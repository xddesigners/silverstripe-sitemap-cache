# Silverstripe Sitemap Cache

Static file cache (and gzip) for [`silverstripe/googlesitemaps`](https://github.com/wilr/silverstripe-googlesitemaps)
(the `Wilr\GoogleSitemaps` module) on **SilverStripe 4 / 5**.

On large sites the sub-sitemaps are generated from the ORM on every request, which can take
several seconds each — so every crawler hit is an expensive PHP request. This module serves
the sitemap from a static file cache instead, and refreshes that cache out-of-band from a
cron task. Live visitors never trigger generation.

## Why this module exists (and when to remove it)

The upstream module already has this feature — **but only in its `4.1+` releases, which
require SilverStripe CMS 6 and PHP 8.3.** On the CMS 4 / 5 line the newest release is `2.2.1`,
which has no cache. This module backports the feature for that line, using the **same config
keys and the same cron task name** as upstream.

**Once a site is upgraded to CMS 6**, remove this module and switch on the native feature:

```
composer remove xddesigners/silverstripe-sitemap-cache
composer require wilr/silverstripe-googlesitemaps:^4.1
```

The `Wilr\GoogleSitemaps\GoogleSitemap` YAML below and the `GenerateGoogleSitemapTask` cron
line are read by the native module too, so they carry over unchanged.

## How it works

- `CachedGoogleSitemapController` replaces the module's `sitemap.xml` route. It extends the
  module controller, so sub-sitemap routing (`sitemap.xml/sitemap/$Class/$Page`) is inherited.
  On a cache **hit** it returns the stored XML; on a **miss** it renders live (via the parent),
  writes the result, and returns it.
- `GenerateSitemapCacheTask` (segment `GenerateGoogleSitemapTask`, run from cron) requests the
  index and every sub-sitemap with `?regenerate=1`, forcing a fresh render + write.
  `?regenerate=1` is honoured only from CLI or for an admin, so it can't be abused from the web.
- **gzip**: when enabled, a `.gz` copy of each file is written; responses are gzip-encoded
  transparently for clients that send `Accept-Encoding: gzip`, and `/sitemap.xml.gz` serves the
  gzipped index as a download.
- Cache files live in `<PUBLIC_PATH>/sitemaps` by default (same as upstream). That location is
  user-independent, so the web server and the cron task share one cache even when they run as
  different users.

## Install

```
composer require xddesigners/silverstripe-sitemap-cache
```

The module auto-enables (it sets `enable_static_cache: true` and re-points the routes).
Requires `GoogleSitemap.enabled: true` (the default). Run a `dev/build?flush=all` after
install/deploy so the new config and routes take effect.

## Configuration

Identical keys to the upstream CMS 6 feature (declared here via an extension on
`GoogleSitemap`):

```yml
Wilr\GoogleSitemaps\GoogleSitemap:
  enable_static_cache: true   # serve/write the cache; false = always render live
  enable_gzip: true           # write .gz copies + serve gzip
  static_cache_path: 'sitemaps'  # relative => <PUBLIC_PATH>/sitemaps; absolute => as-is
  regenerate_time: 3600       # read-side max-age in seconds; 0 = serve regardless of age (cron-only)
```

Note: upstream uses `regenerate_time` as the interval for its queued job. This backport ships
no job, so it applies the value as a read-side max-age (a request for an older cached file
regenerates it inline). Run the cron task regardless — see below.

## Cron

Run the task regularly (hourly is usually fine):

```
0 * * * * /path/to/site/vendor/bin/sake dev/tasks/GenerateGoogleSitemapTask
```

Or once by hand:

```
vendor/bin/sake dev/tasks/GenerateGoogleSitemapTask
```

**Requirements for the cron run**

- `SS_BASE_URL` (or `SilverStripe\Control\Director.alternate_base_url`) must be set, so the
  `<loc>` URLs get the correct scheme + host when rendered from CLI.
- The cron user and the web user both need write access to the cache dir. The default under
  `<PUBLIC_PATH>/sitemaps` is user-independent, so this works even when they differ; if you
  point `static_cache_path` elsewhere, make sure both users can write there.

## Notes

- First request after a deploy (empty cache) renders that one sub-sitemap live and caches it;
  the cron run pre-warms everything so this rarely happens.
- Regenerate immediately after a big content change by running the task.
- Fluent multi-locale sitemaps are not given per-locale cache keys; use this on single-locale
  sites (or set `enable_static_cache: false` on multi-locale ones).

## Differences from the upstream CMS 6 feature

- No queued-job variant (`GenerateGoogleSitemapJob`) — cron only.
- `regenerate_time` is a read-side max-age here, not a job interval (see above).
- Writes on a cache miss (self-healing cold cache); upstream only writes from the task/job.
