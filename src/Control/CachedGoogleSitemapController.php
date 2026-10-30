<?php

namespace XD\SitemapCache\Control;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use Wilr\GoogleSitemaps\Control\GoogleSitemapController;

/**
 * Serves silverstripe/googlesitemaps output from a static file cache so that search
 * engine bots don't trigger the (slow) live ORM generation on every request. On a
 * cache miss the rendered XML is written to disk; the cache is refreshed out-of-band
 * by {@see \XD\SitemapCache\Tasks\GenerateSitemapCacheTask} (cron).
 *
 * This backports the static cache that the upstream module only ships in its CMS 6-only
 * 4.1+ releases, for the SilverStripe 4/5 (googlesitemaps 2.x) line.
 *
 * It extends the module controller so it inherits $allowed_actions and the sub-sitemap
 * action routing (sitemap.xml/sitemap/$ID/$OtherID); only index()/sitemap() are wrapped.
 */
class CachedGoogleSitemapController extends GoogleSitemapController
{
    /**
     * Serve and write the static cache at all.
     *
     * @config
     * @var bool
     */
    private static $cache_enabled = true;

    /**
     * Seconds a cached file is considered fresh for live requests. 0 = never expire on
     * read (only the cron task regenerates); > 0 = a live request for an older file
     * regenerates it inline (self-healing).
     *
     * @config
     * @var int
     */
    private static $cache_lifetime = 0;

    /**
     * Absolute cache directory. Empty defaults to <TEMP_PATH>/sitemap-cache.
     *
     * @config
     * @var string
     */
    private static $cache_dir = '';

    public function index($url)
    {
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
     * render through the matching template), or an HTTPResponse (e.g. 404) which is passed
     * through uncached.
     *
     * @return HTTPResponse
     */
    protected function respondCached($action, $class, $page, callable $generate)
    {
        $enabled = (bool) $this->config()->get('cache_enabled');
        $file = $this->cacheFile($action, $class, $page);

        if ($enabled && $file && !$this->forceRegenerate() && $this->isFresh($file)) {
            return $this->xmlResponse(file_get_contents($file));
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

        return $this->xmlResponse($body);
    }

    /**
     * ?regenerate=1 forces a fresh render + cache write, but only from CLI (the cron
     * task runs via Director::test in a sake process) or for an admin, so a bot can't
     * hammer the expensive path.
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

        $lifetime = (int) $this->config()->get('cache_lifetime');
        if ($lifetime <= 0) {
            return true;
        }

        return (filemtime($file) + $lifetime) > time();
    }

    /**
     * @return HTTPResponse
     */
    protected function xmlResponse($body)
    {
        $response = HTTPResponse::create($body);
        $response->addHeader('Content-Type', 'application/xml; charset="utf-8"');
        $response->addHeader('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * @return string
     */
    public function cacheDir()
    {
        $dir = (string) $this->config()->get('cache_dir');

        if (!$dir) {
            $base = defined('TEMP_PATH') ? TEMP_PATH : sys_get_temp_dir();
            $dir = $base . DIRECTORY_SEPARATOR . 'sitemap-cache';
        }

        return rtrim($dir, '/\\');
    }

    /**
     * Cache file for the index, or a sub-sitemap keyed on its URL-sanitised class + page.
     *
     * @return string|null
     */
    public function cacheFile($action, $class = null, $page = null)
    {
        if ($action === 'index') {
            $name = 'index';
        } else {
            if (!$class || !$page) {
                return null;
            }
            // $class arrives URL-sanitised (backslashes already dashes); keep it FS-safe.
            $name = 'sitemap-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $class) . '-' . (int) $page;
        }

        return $this->cacheDir() . DIRECTORY_SEPARATOR . $name . '.xml';
    }

    protected function writeCache($file, $body)
    {
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }

        // Write to a temp file then rename so readers never see a half-written file.
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $body) !== false) {
            @rename($tmp, $file);
        }
    }
}
