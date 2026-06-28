# Cache Follow-Ups

## Current Direction

- Keep LL Tools dynamic public surfaces out of generic WordPress page caches with `DONOTCACHEPAGE`.
- Let LL Tools' own static caches handle known-safe anonymous pages where the plugin can refresh nonces and purge after content changes.
- Keep browser/downstream cache lifetimes shorter than LL Tools' internal file-cache lifetimes unless a surface is proven safe for longer public caching.

## Implemented Now

- Dictionary static-cache files now default to a 7-day internal TTL because dictionary content changes relatively rarely and existing dictionary edit paths purge the cache.
- Dictionary static-cache hits send a 5-minute browser/downstream `Cache-Control` max-age by default, so browsers and intermediary caches stay much shorter-lived than the 7-day internal file cache.
- Static-cache purge helpers can optionally purge Cloudflare edge HTML when `LL_TOOLS_CLOUDFLARE_ZONE_ID` and `LL_TOOLS_CLOUDFLARE_API_TOKEN` are configured, or when equivalent filters provide those values. The default edge purge discovers the configured public dictionary page and purges that exact URL; sites can add more URLs with `ll_tools_cloudflare_static_cache_purge_urls`.
- Locale-switch links remain signed GET bootstrap URLs for accessibility, but the rendered links now use `rel="nofollow"` and unsigned or expired public `ll_locale` GET/HEAD requests redirect to the clean URL without saving a locale. This reduces stale crawler work on nonce-bearing URLs while preserving normal signed language switching.
- Dictionary canonicalization now strips `ll_wordset_back` along with auth/nonce/tracking noise, and the redirect pass runs on dictionary front pages even when those front pages are intentionally excluded from static HTML caching. This keeps crawler-discovered dictionary detail URLs from multiplying through internal wordset return-state parameters.
- Both values remain configurable through constants and filters:
  - `LL_TOOLS_DICTIONARY_STATIC_CACHE_TTL`
  - `ll_tools_dictionary_static_cache_ttl`
  - `LL_TOOLS_DICTIONARY_STATIC_CACHE_BROWSER_MAX_AGE`
  - `ll_tools_dictionary_static_cache_browser_max_age`
  - `LL_TOOLS_CLOUDFLARE_ZONE_ID`
  - `LL_TOOLS_CLOUDFLARE_API_TOKEN`
  - `ll_tools_cloudflare_static_cache_zone_id`
  - `ll_tools_cloudflare_static_cache_api_token`
  - `ll_tools_cloudflare_static_cache_purge_urls`

## Future Work

- Add bounded prewarming after explicit cache purges or dictionary content changes. Start with canonical dictionary landing and letter pages, not arbitrary search/result combinations.
- Add stale-while-revalidate behavior for anonymous dictionary/public static-cache hits, guarded by a per-key lock so one request refreshes an expired file while other anonymous requests can temporarily receive the stale copy.
- Add LL-owned static caching for public blog/article pages that contain LL shortcodes. These pages are intentionally excluded from generic page caches today, but a plugin-owned cache could safely refresh LL nonce placeholders while avoiding full PHP renders for mostly static article content.
- Add an admin cache diagnostic panel that shows current LL cache status, page-cache bypass reason, cache directory size, and last purge/prewarm time.
- Continue making public language-switcher links more cache-safe on generic pages by avoiding nonce-bearing URLs in cacheable markup entirely, or by resolving the switch action dynamically from a small uncached endpoint.
- Re-run a small live sitemap/header audit after major cache or template changes to confirm expected pages are cached and LL-specific pages remain excluded.

## Cloudflare Edge Cache Follow-Ups

Operational note from the June 11, 2026 Zazacaogren Cloudflare migration:

- The initial safe Cloudflare rule should stay narrow: anonymous `GET`/`HEAD`, host `zazacaogren.com`, path `/sozluk/`, empty query string, and no cookies. A 1-hour Edge TTL with a 30-minute Browser TTL was enough to produce `CF-Cache-Status: HIT` while keeping stale browser HTML bounded.
- Do not broadly edge-cache LL Tools HTML while public dictionary pages can vary by `Accept-Language`. Free Cloudflare cache rules use a URL-oriented cache key unless a custom cache-key feature is available, so a Turkish-language visitor can receive a cached default-language `/sozluk/` response if the cache key does not account for language.
- For the current Zazacaogren rule, continue serving negotiated Turkish/non-default dictionary UI, but bypass edge HTML caching for those variants. The Cloudflare expression can keep caching the deterministic default-locale anonymous URL while excluding Turkish browser preferences:
  `http.host eq "zazacaogren.com" and http.request.method in {"GET" "HEAD"} and http.request.uri.path eq "/sozluk/" and http.request.uri.query eq "" and http.cookie eq "" and not any(lower(http.request.headers["accept-language"][*])[*] contains "tr")`
- Implement a deterministic locale strategy for cacheable public dictionary pages. Either make the locale explicit in the URL/path or serve a canonical anonymous dictionary shell that does not change by `Accept-Language`.
- Move request-fresh public nonces out of edge-cacheable HTML where practical. A small uncached bootstrap endpoint for live-search and locale-switch nonces would allow longer CDN TTLs without relying on placeholder refresh during PHP execution.
- Extend optional Cloudflare purge integration beyond the discovered dictionary page if a site starts edge-caching public wordset, lesson, or article HTML. Add those exact URLs through `ll_tools_cloudflare_static_cache_purge_urls`; do not purge the full Cloudflare zone for routine LL content edits.
- Consider `Cloudflare-CDN-Cache-Control` headers only after locale and nonce behavior are proven edge-safe. Until then, dashboard rules should remain explicit and site-specific rather than emitted by the plugin for all installs.
- Use the optional live-smoke `cloudflareCache` checks to verify repeated anonymous `/sozluk/` requests return `Server: cloudflare` and `CF-Cache-Status: HIT`, while language-specific variants such as Turkish `Accept-Language` bypass edge HTML caching as intended.
