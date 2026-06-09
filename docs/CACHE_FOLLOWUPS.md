# Cache Follow-Ups

## Current Direction

- Keep LL Tools dynamic public surfaces out of generic WordPress page caches with `DONOTCACHEPAGE`.
- Let LL Tools' own static caches handle known-safe anonymous pages where the plugin can refresh nonces and purge after content changes.
- Keep browser/downstream cache lifetimes shorter than LL Tools' internal file-cache lifetimes unless a surface is proven safe for longer public caching.

## Implemented Now

- Dictionary static-cache files now default to a 7-day internal TTL because dictionary content changes relatively rarely and existing dictionary edit paths purge the cache.
- Dictionary static-cache hits still send a 1-day browser/downstream `Cache-Control` max-age by default, so browsers and intermediary caches are not asked to keep dictionary HTML for a full week.
- Both values remain configurable through constants and filters:
  - `LL_TOOLS_DICTIONARY_STATIC_CACHE_TTL`
  - `ll_tools_dictionary_static_cache_ttl`
  - `LL_TOOLS_DICTIONARY_STATIC_CACHE_BROWSER_MAX_AGE`
  - `ll_tools_dictionary_static_cache_browser_max_age`

## Future Work

- Add bounded prewarming after explicit cache purges or dictionary content changes. Start with canonical dictionary landing and letter pages, not arbitrary search/result combinations.
- Add stale-while-revalidate behavior for anonymous dictionary/public static-cache hits, guarded by a per-key lock so one request refreshes an expired file while other anonymous requests can temporarily receive the stale copy.
- Add LL-owned static caching for public blog/article pages that contain LL shortcodes. These pages are intentionally excluded from generic page caches today, but a plugin-owned cache could safely refresh LL nonce placeholders while avoiding full PHP renders for mostly static article content.
- Add an admin cache diagnostic panel that shows current LL cache status, page-cache bypass reason, cache directory size, and last purge/prewarm time.
- Make public language-switcher links more cache-safe on generic pages, either by avoiding nonce-bearing URLs in cacheable markup or by resolving the switch action dynamically.
- Re-run a small live sitemap/header audit after major cache or template changes to confirm expected pages are cached and LL-specific pages remain excluded.
