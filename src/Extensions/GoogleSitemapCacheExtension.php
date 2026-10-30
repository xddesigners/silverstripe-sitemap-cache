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
     * Seconds a cached file stays valid. Upstream uses this as the queued-job re-queue
     * interval; this backport ships no job, so it is applied as a read-side max-age: a
     * request for an older file regenerates it inline. 0 = serve regardless of age (rely
     * solely on the cron task). Either way, run GenerateGoogleSitemapTask from cron.
     *
     * @config
     * @var int
     */
    private static $regenerate_time = 3600;
}
