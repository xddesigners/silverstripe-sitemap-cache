<?php

namespace XD\SitemapCache\Extensions;

use SilverStripe\Core\Extension;

/**
 * Declares the static-cache config on Wilr\GoogleSitemaps\GoogleSitemap so the YAML block
 * is identical to the upstream (CMS 6-only) 4.1+ feature:
 *
 *   Wilr\GoogleSitemaps\GoogleSitemap:
 *     enable_static_cache: true
 *     enable_gzip: true
 *     static_cache_path: 'sitemaps'
 *     regenerate_time: 3600
 *
 * On CMS 6 these same keys are read by the native module, so a site that upgrades can keep
 * its config, drop this module, and the behaviour carries over.
 *
 * @see \XD\SitemapCache\Control\CachedGoogleSitemapController
 */
class GoogleSitemapCacheExtension extends Extension
{
    /**
     * Serve/write the static cache. Opt-in, like upstream (this module's config enables it).
     *
     * @config
     * @var bool
     */
    private static $enable_static_cache = false;

    /**
     * Also write .gz copies and serve gzip (Content-Encoding + /sitemap.xml.gz).
     *
     * @config
     * @var bool
     */
    private static $enable_gzip = true;

    /**
     * Cache directory. A relative path resolves under the public web root; an absolute path
     * is used as-is. Default 'sitemaps' => <PUBLIC_PATH>/sitemaps (matches upstream).
     *
     * @config
     * @var string
     */
    private static $static_cache_path = 'sitemaps';

    /**
     * Read-side max-age in seconds.
     *
     * 0 (default, recommended) = a cached file is always served while it exists; ONLY the
     * cron task regenerates it. Web requests then never run the expensive generation, so a
     * slow, timed-out or partial render can never be cached from a web hit (and a flush can't
     * trigger one either). Requires the cron to keep the cache fresh.
     *
     * > 0 = also regenerate inline when a cached file is older than N seconds (self-heals
     * without a cron, at the cost of an occasional slow — and potentially partial — request
     * being cached). Upstream uses this value as its queued-job interval; this backport ships
     * no job. Either way, run GenerateGoogleSitemapTask from cron.
     *
     * @config
     * @var int
     */
    private static $regenerate_time = 0;
}
