<?php

namespace XD\SitemapCache\Tasks;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;
use Wilr\GoogleSitemaps\GoogleSitemap;

/**
 * Regenerates the static sitemap cache (the index plus every sub-sitemap, each with a .gz
 * copy when gzip is enabled) by requesting each cached URL with ?regenerate=1, so
 * {@see \XD\SitemapCache\Control\CachedGoogleSitemapController} renders fresh XML and writes
 * it to disk. Intended to run from cron:
 *
 *   0 * * * * /path/to/site/vendor/bin/sake dev/tasks/GenerateGoogleSitemapTask
 *
 * The segment is GenerateGoogleSitemapTask (the same as the upstream CMS 6 task), so the cron
 * line is identical before and after a CMS 6 upgrade. Live visitors always hit the cache.
 * Requires SS_BASE_URL (or Director.alternate_base_url) to be set so the <loc> entries get
 * the right host.
 */
class GenerateSitemapCacheTask extends BuildTask
{
    /**
     * Matches the upstream task segment so the same cron line works on SS4/5 and CMS 6.
     *
     * @var string
     */
    private static $segment = 'GenerateGoogleSitemapTask';

    /**
     * @var string
     */
    protected $title = 'Generate Google sitemap static cache';

    /**
     * @var string
     */
    protected $description = 'Pre-renders sitemap.xml and all sub-sitemaps into the static file cache (run from cron).';

    public function run($request)
    {
        if (!GoogleSitemap::enabled()) {
            $this->out('GoogleSitemap is disabled; nothing to do.');
            return;
        }

        $start = microtime(true);

        // 1) Regenerate the index and read back its sub-sitemap URLs.
        $indexBody = $this->fetch('sitemap.xml');
        $this->out('Index regenerated (' . strlen($indexBody) . ' bytes).');

        $paths = $this->subSitemapPaths($indexBody);
        $this->out('Found ' . count($paths) . ' sub-sitemap(s).');

        // 2) Regenerate each sub-sitemap.
        foreach ($paths as $path) {
            $body = $this->fetch($path);
            $this->out('  ' . $path . ' -> ' . strlen($body) . ' bytes');
        }

        $this->out('Done in ' . round(microtime(true) - $start, 1) . 's.');
    }

    /**
     * Internal request that forces the cached controller to regenerate and write.
     *
     * @return string
     */
    protected function fetch($path)
    {
        $sep = (strpos($path, '?') === false) ? '?' : '&';
        $response = Director::test($path . $sep . 'regenerate=1');

        return $response ? (string) $response->getBody() : '';
    }

    /**
     * Pull the sub-sitemap relative paths out of the index XML.
     *
     * @return string[]
     */
    protected function subSitemapPaths($xml)
    {
        $paths = [];

        if (preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $matches)) {
            foreach ($matches[1] as $loc) {
                $path = parse_url(html_entity_decode($loc), PHP_URL_PATH);
                if ($path) {
                    $paths[] = ltrim($path, '/');
                }
            }
        }

        return array_values(array_unique($paths));
    }

    protected function out($message)
    {
        if (Director::is_cli()) {
            echo $message . "\n";
        } else {
            echo '<p>' . htmlspecialchars($message) . "</p>\n";
        }
    }
}
