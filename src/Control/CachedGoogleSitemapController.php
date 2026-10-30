<?php

namespace XD\SitemapCache\Control;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Permission;
use Wilr\GoogleSitemaps\Control\GoogleSitemapController;
use Wilr\GoogleSitemaps\GoogleSitemap;

/**
 * Serves silverstripe/googlesitemaps output from a static file cache so search engine bots
 * don't trigger the (slow) live ORM generation on every request. On a cache miss the rendered
 * XML is written to disk; the cache is refreshed out-of-band by
 * {@see \XD\SitemapCache\Tasks\GenerateSitemapCacheTask} (cron).
 *
 * This backports the "static cache and gzipped sitemaps" feature that upstream only ships in
 * its CMS 6-only 4.1+ releases, for the googlesitemaps 2.x (SilverStripe 4/5) line. It reads
 * the same config keys as upstream (declared via {@see \XD\SitemapCache\Extensions\GoogleSitemapCacheExtension})
 * so a site that later moves to CMS 6 can keep its YAML, drop this module, and the behaviour
 * carries over.
 */
class CachedGoogleSitemapController extends GoogleSitemapController
{
    /**
     * index() and sitemap() are overridden here, so this subclass becomes their defining
     * class and must list them (and the inherited stylesheet actions) explicitly.
     *
     * @var array
     */
    private static $allowed_actions = [
        'index',
        'sitemap',
        'styleSheetIndex',
        'styleSheet',
    ];

    public function index($url)
    {
        // /sitemap.xml.gz -> serve the gzipped index as a downloadable file (upstream parity).
        if ($this->isGzipUrl()) {
            return $this->serveGzipIndex();
        }

        return $this->respondCached('index', null, null, function () use ($url) {
            return parent::index($url);
        });
    }

    public function sitemap()
    {
        $class = $this->request->param('ID');
        $page = $this->request->param('OtherID');

        return $this->respondCached('sitemap', $class, $page, function () {
            return parent::sitemap();
        });
    }

    /**
     * Read-through / write cache around a parent action.
     *
     * $generate returns a stringable rendered body, an array (array-returning action to
     * render through the matching template), or an HTTPResponse (e.g. 404) passed through
     * uncached.
     *
     * @return HTTPResponse
     */
    protected function respondCached($action, $class, $page, callable $generate)
    {
        $enabled = $this->cacheEnabled();
        $file = $this->cacheFile($action, $class, $page);

        if ($enabled && $file && !$this->forceRegenerate() && $this->isFresh($file)) {
            return $this->xmlResponse(file_get_contents($file), $file . '.gz');
        }

        $result = $generate();

        // 404 / disabled: hand back untouched, never cache.
        if ($result instanceof HTTPResponse) {
            return $result;
        }

        // Array-returning actions (sitemap) must be rendered with their template.
        if (is_array($result)) {
            $result = $this->getViewer($action)->process($this->customise($result));
        }

        $body = (string) $result;

        if ($enabled && $file) {
            $this->writeCache($file, $body);
        }

        return $this->xmlResponse($body, $enabled && $file ? $file . '.gz' : null);
    }

    /**
     * Build the XML response, transparently gzip-encoding it when the client accepts gzip
     * and gzip is enabled. Prefers a pre-written .gz file, otherwise encodes on the fly.
     *
     * @return HTTPResponse
     */
    protected function xmlResponse($body, $gzFile = null)
    {
        if ($this->gzipEnabled() && $this->clientAcceptsGzip()) {
            $gz = ($gzFile && is_file($gzFile)) ? file_get_contents($gzFile) : gzencode($body, 9);

            if ($gz !== false) {
                $response = HTTPResponse::create($gz);
                $response->addHeader('Content-Type', 'application/xml; charset="utf-8"');
                $response->addHeader('Content-Encoding', 'gzip');
                $response->addHeader('Vary', 'Accept-Encoding');
                $response->addHeader('X-Robots-Tag', 'noindex');
                return $response;
            }
        }

        $response = HTTPResponse::create($body);
        $response->addHeader('Content-Type', 'application/xml; charset="utf-8"');
        if ($this->gzipEnabled()) {
            $response->addHeader('Vary', 'Accept-Encoding');
        }
        $response->addHeader('X-Robots-Tag', 'noindex');
        return $response;
    }

    /**
     * Serve <cache>/sitemap.xml.gz as a literal gzip download (mirrors upstream's
     * sitemap.xml.gz). Generates the index first if the gz file is missing.
     *
     * @return HTTPResponse
     */
    protected function serveGzipIndex()
    {
        if (!GoogleSitemap::enabled()) {
            return new HTTPResponse('Page not found', 404);
        }

        $gzFile = $this->cacheFile('index', null, null) . '.gz';

        if (!is_file($gzFile)) {
            // Prime the cache (writes sitemap.xml + .gz) via the normal path, then re-read.
            $this->respondCached('index', null, null, function () {
                return parent::index(null);
            });
        }

        $gz = is_file($gzFile) ? file_get_contents($gzFile) : false;
        if ($gz === false) {
            return new HTTPResponse('Page not found', 404);
        }

        $response = HTTPResponse::create($gz);
        $response->addHeader('Content-Type', 'application/x-gzip');
        $response->addHeader('X-Robots-Tag', 'noindex');
        return $response;
    }

    /**
     * ?regenerate=1 forces a fresh render + cache write, but only from CLI (the cron task
     * runs via Director::test in a sake process) or for an admin, so a bot can't hammer the
     * expensive path.
     *
     * @return bool
     */
    protected function forceRegenerate()
    {
        if (!$this->request->getVar('regenerate')) {
            return false;
        }

        return Director::is_cli() || Permission::check('ADMIN');
    }

    /**
     * @return bool
     */
    protected function isFresh($file)
    {
        if (!is_file($file)) {
            return false;
        }

        $ttl = (int) Config::inst()->get(GoogleSitemap::class, 'regenerate_time');
        if ($ttl <= 0) {
            return true;
        }

        return (filemtime($file) + $ttl) > time();
    }

    /**
     * @return bool
     */
    protected function cacheEnabled()
    {
        return (bool) Config::inst()->get(GoogleSitemap::class, 'enable_static_cache');
    }

    /**
     * @return bool
     */
    protected function gzipEnabled()
    {
        return (bool) Config::inst()->get(GoogleSitemap::class, 'enable_gzip')
            && function_exists('gzencode');
    }

    /**
     * @return bool
     */
    protected function clientAcceptsGzip()
    {
        return stripos((string) $this->request->getHeader('Accept-Encoding'), 'gzip') !== false;
    }

    /**
     * @return bool
     */
    protected function isGzipUrl()
    {
        return substr((string) $this->request->getURL(), -3) === '.gz';
    }

    /**
     * @return string
     */
    public function cacheDir()
    {
        $path = (string) Config::inst()->get(GoogleSitemap::class, 'static_cache_path');
        if ($path === '') {
            $path = 'sitemaps';
        }

        // Absolute path (unix or windows) is used as-is; relative resolves under the web root.
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $dir = $path;
        } else {
            $base = defined('PUBLIC_PATH') ? PUBLIC_PATH : (defined('BASE_PATH') ? BASE_PATH : getcwd());
            $dir = $base . DIRECTORY_SEPARATOR . $path;
        }

        return rtrim($dir, '/\\');
    }

    /**
     * Cache file for the index, or a sub-sitemap keyed on its URL-sanitised class + page.
     * Mirrors upstream file names: sitemap.xml and sitemap-<Class>-<Page>.xml.
     *
     * @return string|null
     */
    public function cacheFile($action, $class = null, $page = null)
    {
        if ($action === 'index') {
            $name = 'sitemap';
        } else {
            if (!$class || !$page) {
                return null;
            }
            // $class arrives URL-sanitised (backslashes already dashes); keep it FS-safe.
            $name = 'sitemap-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $class) . '-' . (int) $page;
        }

        return $this->cacheDir() . DIRECTORY_SEPARATOR . $name . '.xml';
    }

    /**
     * Write the XML cache file (and a .gz copy when gzip is enabled) atomically.
     */
    protected function writeCache($file, $body)
    {
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        $this->atomicWrite($file, $body);

        if ($this->gzipEnabled()) {
            $gz = gzencode($body, 9);
            if ($gz !== false) {
                $this->atomicWrite($file . '.gz', $gz);
            }
        }
    }

    /**
     * Write to a temp file then rename, so readers never see a half-written file.
     */
    protected function atomicWrite($file, $contents)
    {
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $contents) !== false) {
            @rename($tmp, $file);
        }
    }
}
