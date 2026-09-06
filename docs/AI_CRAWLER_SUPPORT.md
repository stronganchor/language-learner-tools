# AI Crawler Support

LL Tools exposes generated, read-only discovery files for AI agents and
crawlers:

- `/llms.txt`
- `/ll-tools/llms.txt`
- `/ll-tools/index.md`
- `/ll-tools/index.jsonld`
- `/ll-tools/dictionary.md`
- `/ll-tools/dictionary/<letter>.md`
- `/ll-tools/wordsets.md`
- `/ll-tools/content-lessons.md`
- `/ll-tools/ai-crawler.md`

These files are served by `includes/lib/ai-crawler-support.php` during
`template_redirect` at priority 0. The route resolver works directly from the
request path, including a WordPress home-path prefix; these are not saved
Markdown files or REST routes. Only GET and HEAD are handled.

The module also emits `<link rel="alternate">` tags and HTTP `Link` headers for
`/llms.txt`, `/ll-tools/index.md`, and `/ll-tools/index.jsonld` on normal
front-end requests. These are discovery hints only; they do not define crawl or
training policy.

`/ll-tools/index.jsonld` is a compact Schema.org graph that describes the
generated public exports as a `Dataset`, exposes public dictionary samples as a
`DefinedTermSet`, and lists letter-scoped dictionary Markdown exports as an
`ItemList`. The generated letter routes use the same language-aware browse-letter
normalization as the public dictionary browser.

## Source and test map

| Task | Entry point |
| --- | --- |
| Add or diagnose an export route | `ll_tools_ai_crawler_export_routes()`, `ll_tools_ai_crawler_resolve_export()`, `ll_tools_ai_crawler_maybe_serve_export()` |
| Body caching or HEAD behavior | `ll_tools_ai_crawler_export_cache_args()`, `ll_tools_ai_crawler_prepare_export_response()` |
| HTML and HTTP discovery hints | `ll_tools_ai_crawler_discovery_links()`, `ll_tools_ai_crawler_render_head_links()`, `ll_tools_ai_crawler_send_discovery_link_headers()` |
| Anonymous ownership and visibility | `ll_tools_ai_crawler_can_view_*()`, `ll_tools_ai_crawler_get_public_*()` |
| Dictionary letter discovery | `ll_tools_ai_crawler_get_dictionary_letters()`, `ll_tools_ai_crawler_query_public_dictionary_raw_letters()`, `ll_tools_ai_crawler_get_public_wordset_ids_for_dictionary_letters()` |
| Rendered Markdown and JSON-LD | `ll_tools_ai_crawler_build_*()` and the dictionary/cue formatting helpers in the same file |
| Shared dictionary labels, senses, and letter rules | `includes/lib/dictionary-browser.php`; dictionary entry metadata is owned by `includes/post-types/dictionary-entry-post-type.php` |
| Focused integration coverage | `tests/Integration/AiCrawlerSupportTest.php` |

Use the normal serialized test workflow in `tests/AI_TESTING_PLAYBOOK.md`:

```bash
tests/bin/run-tests.sh Integration/AiCrawlerSupportTest.php
```

The current suite covers route discovery, dictionary cache-version changes,
cold/cached HEAD responses, locale cache policy, mixed public/private wordset
samples, letter filtering, JSON-LD structure, and lesson visibility. When
changing visibility or caching, also exercise empty/all-private source sets,
password-cookie variation, and transitions between visibility generations;
mixed public/private fixtures alone do not cover every branch.

## Bounds and cache identity

Caps apply to compact output samples, not complete downloadable datasets.
The `ll_tools_ai_crawler_*_limit` filters are clamped by
`ll_tools_ai_crawler_limit()`; builder arguments are an internal PHP interface,
not URL query parameters.

| Output | Default | Hard maximum |
| --- | --- | --- |
| Dictionary Markdown entries | 50 | 100 |
| Letter-scoped dictionary entries | 25 | 50 |
| Senses per dictionary entry | 4 | 8 |
| JSON-LD dictionary samples | 20 | 50 |
| Advertised dictionary letters | 64 | 100 |
| Wordsets, vocabulary lessons, content lessons | 50 each | 100 each |
| Transcript cue samples per content lesson | 8 | 20 |

Candidate reads have separate limits: dictionary sampling examines at most
500 entry IDs, wordset and lesson sampling reads at most 200 candidates, and
the public-wordset lookup for letter discovery reads at most 500 wordset IDs.
Filtering can therefore return fewer items than the output cap. SQL grouping
for letter discovery is separate from these returned-row bounds.

The export cache key includes schema, export kind, normalized letter, locale,
blog ID, dictionary browser version, wordset/category epochs, and the quiz
content epoch. Bodies are strings stored in both object cache and transients;
these exports do not use the filesystem HTML caches or durable payload-row
materializers. The default TTL is ten minutes, filterable through
`ll_tools_ai_crawler_response_cache_seconds` within 60 seconds to one day.
Cold GET requests build synchronously; HEAD only reads an existing body.

Responses vary on `Accept-Language` and `Cookie`. The site-default locale uses
public cache headers, while other request locales use `private, no-store`.
The `X-LL-Tools-AI-Crawler-Cache` header distinguishes `MISS`, `HIT`, and an
uncached `HEAD`. Header-only discovery hints do not grant access to content.

## Invariants

These are required contracts for changes and regression coverage.

- Exports must only include anonymous public content.
- Use explicit anonymous visibility checks, not the current logged-in user, when
  deciding whether wordsets, categories, dictionary entries, vocab lessons, or
  content lessons appear.
- Keep exports bounded with filterable caps. Do not dump all words,
  dictionary entries, transcript cues, generated media, or large wordsets.
- GET export bodies use the shared cache identity described above.
- HEAD requests must remain header-only and must not build cold export bodies.
- Letter-scoped dictionary exports must still pass every entry through
  anonymous wordset visibility checks before a letter is advertised or rendered.
- Primary letter discovery SQL uses explicit public wordset ownership. Keep
  any fallback discovery path subject to the same anonymous-public contract.
  Direct letter routes filter rendered entries through the broader anonymous
  visibility checks.
- Canonical HTML URLs remain the source of record. Markdown exports are compact
  discovery/context surfaces.
- Do not expose admin pages, editor workflows, recording tools, nonces, REST
  mutation endpoints, or private training/control policy through these files.
- WebMCP annotations should remain limited to anonymous public read-only forms
  unless the workflow has explicit user confirmation and permission checks.

## Follow-Up Areas

- Site owners may still need explicit `robots.txt`, response header, or
  Cloudflare Content Signals policy for crawl/training preferences.
- Richer WebMCP work can add result payload handling for read-only searches
  after browser support stabilizes.
