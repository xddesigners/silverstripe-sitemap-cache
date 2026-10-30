# Silverstripe Sitemap Cache

Static file cache for [`silverstripe/googlesitemaps`](https://github.com/wilr/silverstripe-googlesitemaps)
(the `Wilr\GoogleSitemaps` module) on **SilverStripe 4 / 5**.

On large sites the sub-sitemaps are generated from the ORM on every request, which can
take several seconds each — so every crawler hit is an expensive PHP request. This module
serves the sitemap from a static file cache instead, and refreshes that cache out-of-band
from a cron task. Live visitors never trigger generation.

It backports the static cache that the upstream module only ships in its **CMS 6-only**
`4.1+` releases, for the `googlesitemaps` 2.x (SilverStripe 4/5) line.

## How it works

- `CachedGoogleSitemapController` replaces the module's `sitemap.xml` route. It extends the
  module controller, so sub-sitemap routing (`sitemap.xml/sitemap/$Class/$Page`) is inherited.
  On a cache **hit** it returns the stored XML; on a **miss** it renders live (via the parent),
  writes the result, and returns it.
- `GenerateSitemapCacheTask` (cron) requests the index and every sub-sitemap with
  `?regenerate=1`, forcing a fresh render + write. `?regenerate=1` is honoured only from CLI
  or for an admin, so it can't be abused from the web.
- Cache files live in `<TEMP_PATH>/sitemap-cache` by default (outside the web root).

## Install

```
composer require xddesigners/silverstripe-sitemap-cache
```

The module auto-enables (its config sets `cache_enabled: true` and re-points the route).
Requires `GoogleSitemap.enabled: true` (the default).

## Configuration

```yml
XD\SitemapCache\Control\CachedGoogleSitemapController:
  cache_enabled: true     # serve/write the cache; false = always render live
  cache_lifetime: 0       # 0 = only the cron task refreshes; >0 = live requests older than N seconds self-heal
  cache_dir: ''           # empty = <TEMP_PATH>/sitemap-cache
```

## Cron

Run the task regularly (hourly is usually fine):

```
0 * * * * /path/to/site/vendor/bin/sake dev/tasks/GenerateSitemapCacheTask
```

Or once by hand:

```
vendor/bin/sake dev/tasks/GenerateSitemapCacheTask
```

**Requirements for the cron run**

- `SS_BASE_URL` (or `SilverStripe\Control\Director.alternate_base_url`) must be set, so the
  `<loc>` URLs get the correct scheme + host when rendered from CLI.
- The cron user and the web user must share the same `TEMP_PATH` (the default on a standard
  single-user Plesk subscription). If they differ, set an explicit `cache_dir` both can write.

## Notes

- First request after a deploy (empty cache) renders that one sub-sitemap live and caches it;
  the cron run pre-warms everything so this rarely happens.
- `cache_lifetime: 0` means the cache only changes when the cron runs — regenerate after a big
  content change by running the task, or lower the cron interval.
- No TTL/expiry is written into the files; freshness is entirely the cron schedule's job.
