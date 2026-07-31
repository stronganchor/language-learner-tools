# Performance Architecture

This is the working map for performance changes in LL Tools. Read it after
`CODEBASE_ARCHITECTURE.md` when a task touches loading time, query volume,
large wordsets, bulk media, or any page that can grow with `words`,
`word_audio`, `word_images`, prompt cards, or generated media.

## Core invariant

Large wordsets are a normal production case. Interactive requests should be
bounded by a page size, a visible shell count, an explicit user query, or a
cached/materialized aggregate. Full scans and hydration of every word in a
wordset belong only in explicit maintenance jobs, bounded imports/exports, or
admin flows that show progress and can be resumed.

Wordset-page aggregates persisted by
`ll_tools_wordset_page_store_cached_payload()` use an ASCII durable envelope.
Keep request/object-cache values native, but do not bypass this helper with a
raw multilingual transient. Production sites can have a legacy utf8mb3
options table; a payload containing a four-byte icon or other Unicode value is
then rejected after WordPress creates only the timeout row. Token-producing
flows must confirm a durable readback before advertising the token, repair
timeout-only rows, and leave a complete non-AJAX fallback when persistence is
unavailable. Shared lazy-card and category-search tokens must also outlive the
static HTML that references them, while static-cache HIT headers expose only
the lesser of the file's remaining freshness and a short nonce-safe browser
TTL rather than restarting the full internal file lifetime.
Payloads using the durable helper must remain arrays/scalars; convert runtime
objects such as `WP_Post` and `WP_Term` to compact IDs or value arrays first.
Authenticated lazy-card and category-search payload tokens are deterministic
for the same user, wordset, namespace, and payload so repeated manager views
refresh one transient instead of creating UUID-keyed rows. They remain private:
never treat a `private_*` token as eligible for anonymous shared response
caching, and keep the AJAX user/wordset validation in place.

When a fix needs broader context, use `docs/ai-context/task-router.md` and
generate a local context pack with `scripts/build-ai-context-pack.php` instead
of sending the whole plugin to an external model.

## Hot paths

| Surface | Primary files | Watch points |
| --- | --- | --- |
| Flashcard and quiz payloads | `includes/shortcodes/flashcard-widget.php`, `includes/shortcodes/quiz-pages-shortcodes.php`, `includes/taxonomies/word-category-taxonomy.php`, `includes/lib/public-ajax-resource-guards.php` | Treat `ll_get_words_by_category()` as an expensive hydration path. Use `ll_get_words_by_category_count()` or bounded candidate IDs when only counts, eligibility, or a launch subset are needed. Anonymous AJAX cache misses reserve atomic weighted per-client budgets, at most two exact-owner client leases, and one exact cache-key build lease; cache hits bypass admission, duplicate builders receive retryable warming responses, and candidate input is capped before traversal. These guards limit concurrency but do not make an admitted full-category cold build bounded; eliminating that remaining proportional path requires the separately planned durable payload materializer rather than truncating large-category semantics. Durable quiz-catalog workers finish one valid partial generation when no usable latest snapshot exists, but a plugin-versioned builder token resets incompatible pre-deploy chunks and an empty stale snapshot never justifies resetting the only advancing build. Workers must not persist per-category derived count/gender/aspect/default-wordset/eligibility transients. AJAX and signed no-JavaScript continuations each advance bounded work without running unrelated cron callbacks. |
| Public wordset buttons | `includes/shortcodes/wordset-buttons-shortcode.php`, `includes/taxonomies/word-category-taxonomy.php` | A cold render must never synchronously scan every lesson/category pair or every word/prompt card after a content-epoch change. Discover lesson IDs in bounded keyset pages, resume one exact pair through shared prompt-card/raw-word query and row budgets, and publish counts only after the complete generation is fenced to its current epochs, builder schema, user identity, and lock. Preserve only complete, structurally scoped anonymous last-known-good or exact-cache HTML while rebuilding; signed-in scopes may consume that public subset but must never write private output to it. A nonce-protected authenticated loader may advance one bounded batch per serial request and replace the shell only after exact completion; never register that polling route for anonymous traffic. Source failures back off, ordinary budget exhaustion advances one deduplicated cron continuation carrying the initiating user ID, and the worker restores that user context only while advancing the state. A genuine cold miss shows a loading shell rather than blank output; a proven complete-empty scope remains empty. Evicted/expired state options and paired locks must be reclaimed, and default non-opt-in quiz callers retain their existing exact behavior. Direct role-change invalidation is a separate maintenance item. |
| Wordset landing pages, search, lazy cards | `includes/pages/wordset-pages.php`, `includes/lib/wordset-category-search-index.php`, `includes/user-progress.php`, `js/wordset-pages.js` | Keep the first paint and activity startup bounded. Use category summaries, shell cards, and AJAX hydration instead of loading every word/media item. Logged-in filtered or broad non-learning selections resolve through one nonce-protected ID-only launch plan that, on success, preserves every exact match in balanced, category-aware chunks; a sparse layout that cannot form minimum-size rounds within the eight-category hard cap fails closed instead of returning a partial plan. Direct words marked as specific-wrong-answer-only are option-support rows, not plan targets; do not filter the final canonical union because prompt-card answers using those same word IDs remain legitimate targets. Keep category-owned queues contiguous for low request fan-out, but order smaller queues before a dominant broad category inside each balanced batch so a catch-all category cannot monopolize the opening round; retain the filter label across continuation chunks. Each chunk remains capped at 30 words/eight categories (15/three preferred), and candidate-specific requests hydrate only the current chunk before serial continuation. Once exact candidate coverage is verified, hand those rows directly to the flashcard runtime without a second AJAX fetch for the same chunk; never flatten the plan into an all-category hydration drain. A 429 or candidate failure closes the loader instead of retrying in the foreground indefinitely. Word/translation search reads only a complete published generation from the durable category-search table. Builders advance through bounded ID-keyset, relationship, row, and byte batches; exact-owner leases, dependency-signature compare-and-swap publication, generation rotation on expired takeover, and bounded old-generation cleanup prevent stale workers from publishing. Public requests expose loading/error/Retry states while preparation advances and never fall back to rebuilding the complete search index inline. Filter categories already proven ineligible before computing content summaries; bulk-prime the exact term, lesson, and wrong-answer-owner candidates; and do not re-query flat taxonomy membership already established by the category-scoped candidate query. Avoid per-word postmeta loops in request setup. Preview deduplication may use attachment IDs, URLs, stored path identity, and already-stored visual-hash metadata; it must not read/hash attachment files or compute missing visual hashes during interactive rendering. |
| Wordset game launch pools | `includes/pages/wordset-games.php`, `js/wordset-games.js` | Keep game setup capped by the selected round size or candidate pool. Do not hydrate all words just to render the catalog or choose a launch subset. |
| Wordset editor/settings | `includes/pages/wordset-editor.php`, `includes/pages/wordset-pages.php` | Keep editor rows paged. Respect `ll_tools_wordset_editor_can_use_paged_query()` and avoid falling back to all-row builds unless the operation is intentionally small or filtered. Effective-image summary, filter, and sort paths must reuse the cached keyset-batched word-ID membership index; do not put the correlated effective-image predicate back into a whole-wordset count or order query. Render only the visible editor table rows and one empty detached-modal host; fetch a single word's complete edit dialog through the guarded modal AJAX endpoint when its Edit control is opened instead of embedding every visible dialog in the initial HTML. The wordset-wide modal category catalog must trust the recorder helper's set-based result even when it is empty; it must never treat an authoritative empty catalog as permission to hydrate every word or image ID. The settings hub should use stored settings and aggregate term counts only; it must not hydrate full editor rows, media/template catalogs, offline export options, the generic per-category preview catalog, main-view-only game availability, main-view-only category-search tokens, or games-view-only runtime localization before a tool is opened. Plain settings tools and the hub must not enqueue the main wordset-page monolith or locale sorter; keep those assets for `study`, `editor`, and `recorder-queues`, while `advanced` retains only its dedicated manager/media/autocomplete assets plus locale sorting. Confetti remains main/progress-only. The recorder overview selects one assigned recorder at a time and may reuse complete cached summaries during PHP render, but cold summaries advance only through nonce- and capability-protected serial AJAX: three categories in the first request, then six by default (twenty hard maximum). Render full named shimmer cards only for the active bounded batch and preserve later unresolved positions as hidden markers. While the dedicated sentinel remains in or just below the viewport, settle one request before automatically starting the next; moving away pauses continuation, and concurrency must remain one. Do not show a normal Load more control or visible loaded-count copy. Prioritize untouched identities over retry work, and terminate a timed-out, incomplete-catalog, or unclassified response with a reachable error-only Retry state. The recording-shortcode overview remains separate and category-neutral with at most three identity-free shells and an overflow cue; it does not hydrate a focused queue, render progress or the legacy category selector, or open New Word until its button is used. Category cards navigate to dedicated focused pages with explicit back navigation. Do not replace either overview stream with numbered source-category pages, which can collapse a page to one useful queue after empty categories are resolved. Numeric cards publish only after canonical word/image, legacy missing-audio, and prompt-card scans all reach true bounded exhaustion; their count is the exact cumulative three-source total, while pending work stays a loading shell. Wordset-isolated queues ignore the site-wide legacy missing-audio option entirely because it has no collision-safe wordset identity, and they do not hydrate that option or synthesize an Uncategorized identity from it; canonical word/image/prompt sources remain authoritative. Isolation-disabled sites keep the bounded legacy fallback. Image-to-word resolution stays limited to the current candidate batch, hidden-entry wordset membership is resolved in bulk, and focused-category and hidden queue item views remain paged instead of rendering a full queue. Focused queue limits apply after eligibility across canonical word/image, legacy missing-audio, and prompt sources; each raw scan is bounded and resumable, later pages plus same-page continuations reuse prior offsets/keysets, and browser cursors are expiring HMAC tokens bound to the authenticated viewer, requested page, and exact queue scope plus structural/type epochs. Isolated cursor contexts fence the disabled legacy-source mode so pre-change tokens explicitly rebase, while non-isolated tokens remain compatible. Those cursors intentionally survive ordinary recording and hide mutations so active recorders do not lose their raw scan position between lazy batches. Invalid, expired, tampered, or context-mismatched supplied tokens explicitly rebase to page one with `cursor_rebased` and `reset_queue` instead of falling back to numeric offsets. Empty bounded responses with `has_more` keep their continuation with repeat-token protection, while nonincremental server navigation carries cumulative `page_items` across same-page legacy/prompt scans. If a signed token cannot be encoded, return the bounded items with `continuation_unavailable` and no automatic continuation instead of a blank-cursor loop. Scope every in-flight browser request to its selected category generation and discard late responses after a switch. Summary previews prefer the queue image's requested WordPress size before a linked word image or raw URL, reject broken candidates before filling slots, and stay pending when a bounded refill may still find renderable work. Legacy empty-category recording configuration must use the same per-word and ownerless-image fallback semantics as focused hydration. Durable summary caches must key content, compact structure, recording-type changes, and the rarely changing structural category epoch used for wordless sibling eligibility separately; never use request-local core `last_changed` values as cross-request invalidation tokens. |
| Word grid and vocabulary lessons | `includes/shortcodes/word-grid-shortcode.php`, `includes/pages/vocab-lesson-pages.php`, `includes/post-types/word-image-post-type.php`, related JS/CSS | Use renderable ID queries and capped shell ordering. Avoid fetching all media when the page can hydrate visible cards incrementally. Large staff views use a per-user order state and the same bounded page size as public lessons while retaining drafts and published media-mismatch warning cards at the end; candidate-specific word-grid rendering must preserve those staff annotations. Staff lesson cards must not repeat the rich hidden word editor or the full wordset category catalog per card: emit detached-editor triggers and hydrate one editor through `ll_tools_get_word_edit_modal_grid` only after an explicit edit action. Image-qualified vocabulary counts may remain compact `COUNT(DISTINCT ...)` aggregates, but isolation-aware copies must be represented by one uncorrelated, materialized set of eligible source image IDs for the target wordset. Never rescan the owner/source postmeta relationship for every candidate word. |
| Transcription/IPA manager | `includes/admin/ipa-keyboard-admin.php`, `includes/admin/audio-processor-admin.php`, `js/ipa-keyboard-admin.js`, `js/audio-processor.js` | Keep initial admin load to the first visible rows and lazy-load the rest. Validation hooks are allowed to be deeper, but the admin UI should not hydrate every target by default. Audio Processor normal queue continuation uses short-lived user/tab-bound HMAC keyset cursors over its deterministic date/modified-plus-ID order. Direct legacy page URLs retain a small, explicitly capped SQL `OFFSET` compatibility window; cursor navigation is the supported deep continuation path. |
| User progress and study metrics | `includes/user-progress.php`, `includes/pages/wordset-pages.php` | Prefer aggregate rows and bounded category lookups. Be careful with user-specific joins over every word in a large wordset. Multi-category launch planning may read the cached/materialized ID-only analytics scope to apply exact progress criteria. A successful response preserves the complete matching ID set as individually hard-capped, category-aware chunks; an impossible sparse partition returns the typed fail-closed error. Media hydration remains deferred to candidate-specific requests for one current chunk, and verified candidate rows pass directly into the runtime without a duplicate fetch; never hydrate all plan categories up front or in one activity launch. When analytics is deferred, saved progress/recent category sorts stay in canonical server order across both initial and lazy card batches. `js/wordset-pages.js` applies the saved sort only after the `summary_only=1`, `include_words=0` aggregate arrives; do not invoke the full category-metrics collector merely to honor the saved cookie. |
| Dictionary search | `includes/lib/dictionary-search-index.php`, `includes/lib/dictionary-browser.php`, `includes/shortcodes/dictionary-shortcode.php`, `includes/lib/public-ajax-resource-guards.php` | Route public search through indexed/searchable fields. Avoid broad `postmeta LIKE` scans on public requests, and keep entry-detail linked-word previews capped. Anonymous live-search misses use atomic weighted client budgets, bounded exact-owner client leases, and one same-query build lease with a short wait-for-cache path; concurrent callers receive retryable warming rather than starting duplicate work. Cache hits bypass admission. Anonymous unscoped title-language inference may use grouped direct-language SQL only when every published entry has exactly one nonempty direct-language row and none has explicit wordset scope; otherwise it must retain the visibility-aware entry scan. |
| AI crawler exports | `includes/lib/ai-crawler-support.php` | Keep generated exports bounded, anonymous-only, and route-cache backed. HEAD requests must stay header-only and must not build cold export bodies. |
| Public/static cache | `includes/lib/public-static-cache.php`, `includes/lib/dictionary-static-cache.php` | Keep anonymous cache keys deterministic and safe. Respect max-byte guards, nonce placeholder refresh behavior, locale rules, and targeted purge hooks. |
| Expired transient maintenance | `includes/lib/expired-transient-maintenance.php` | Run only from the hourly cron hook and only without an external object cache. Candidate selection is restricted to exact audited LL-owned cache/rate-limit prefixes, at most 200 timeout rows older than a five-minute grace period, a one-second default loop budget, and a two-second hard cap. Each deletion rechecks the selected timeout ID, name, value, and expiry in the same SQL statement that removes its exact value row; timeout pairs renewed before that statement survive, and timeout-only rows can be reclaimed. Never broaden this into global transient cleanup, persistent `ll_tools_*` option/job cleanup, or a hot-path fallback. Telemetry may expose aggregate counts, namespace buckets, and byte totals, never keys or values. |
| Imports, site sync, automation | `includes/api/automation-rest.php`, `includes/lib/internal-review-notes.php`, `includes/lib/site-sync.php`, `includes/admin/export-import.php`, `offline-app-builder/scripts/prepare-bundle.mjs` | Treat heavy work as server-owned jobs. REST endpoints should control, enqueue, and report status instead of doing unbounded work inline. Review-note lists default to 100 rows/max 250, interlinear lists default to 50/max 100, and both reject legacy offsets beyond the configured guarded window instead of issuing arbitrarily deep SQL `OFFSET`. Full report summaries use direct bounded aggregates rather than hydrating every row. Offline bundle preparation rejects oversized compressed archives before `AdmZip` reads them, then enforces entry-count, per-entry, total-uncompressed, traversal, and symlink limits before extraction. |
| Hosted STT | `includes/lib/custom-stt-endpoint.php` | Reject oversized uploads before reading, bound the fallback read to `max + 1`, and pass a small `limit_response_size` to the configured remote endpoint. Treat cap-reaching, content-length-oversized, truncated, or non-JSON responses as errors by default; never materialize an unbounded provider body. The legacy plain-text transcript contract is bounded and opt-in only through `ll_tools_remote_stt_allow_plain_text_response`. |
| Performance benchmark | `tests/performance/`, `tests/e2e/specs/performance-benchmark.spec.js`, `tests/e2e/helpers/performance-benchmark.js` | Keep the default benchmark affordable. Use the Genç profile for the production-shaped 209-category/2,717-word settings and recorder paths, XL for generic 3,000-word coverage, and stress-2x for 5,000-word saturation checks. Named profiles authoritatively select their fixture manifest, history, and report paths. Skip-seed verifies the stored fixture version and canonical checksum without mutation; pass the small stored-fixture JSON as an explicit verifier argument because redirected stdin is unreliable when WSL launches Windows PHP. The E2E child restores all locked parent-selected performance variables after loading `.env` files. |

### Derived-cache integrity

Every normal-page derived payload must distinguish a proven empty result from an incomplete read. Reset and check `$wpdb->last_error` at each source boundary and thread completeness through term, meta, visibility, default-wordset, sign-mode, prompt, media, and owner-map helpers. Never publish a transient, durable chunk, summary, processed-category list, or page cursor from incomplete sources; preserve the previous complete state and retry the same key or cursor.

Keep structural and content generations separate. Structural taxonomy identity/order changes advance the structural epoch. Fully scoped word, audio, image, and prompt mutations advance affected category versions and wordset content epochs. Unknown scope advances the unknown component; any failed narrow generation write advances the failsafe. Scoped keys include the relevant wordsets plus unknown/failsafe components, keeping unrelated wordsets warm without allowing stale content.

Completeness is also cursor state: recorder prompt batches, quiz-catalog row batches, public wordset-button lesson/prompt/word batches, and quiz/vocab maintenance batches must not advance past an incomplete read. Missing or invalid recommendation queues hydrate at most 12 categories, hard-capped at 24, from the materialized wordset category scope; incomplete refreshes do not persist a queue. A wordset-button worker must recheck its structural/content generation before every fenced state write and must not return partial counts as authoritative output.

Recorder overview stream generations key the ordered category `{id,name,slug}`
identities plus recorder, wordset, and include/exclude scope. Per-category content
signatures remain on the summary cards themselves. Ordinary word, audio, image,
or prompt changes therefore refresh affected cards without restarting completed
bounded summary batches; actual category identity, order, or scope changes still
force a structural reload. A completed category card also includes the rarely
changing structural category epoch as an eligibility component, because a
wordless image can inherit its recording-type union from visible, wordset-scoped
sibling categories. Owner/source/privacy/access changes and category deletion
rotate that epoch without tying all cards to ordinary structural post saves.
Catalog, category-map, and summary identities also include the recorder's
effective `manage_options` privacy bypass; the user ID alone is insufficient
because role demotion must stop reuse of cached private names, counts, previews,
and eligibility maps immediately.

### Inline wordset and settings-hub contracts

The browser needs the complete wordset category registry for selection, sorting,
unloaded search placeholders, and launch configuration. Keep that registry sparse
by omitting values supplied by the JavaScript normalizer or top-level wordset
context. Lazy category shells are ordered `{type, id}` references into the
registry; do not serialize a second copy of each category row in the shell list.
Content-lesson shells retain their bounded title, excerpt, and media fields
because unloaded-content search uses them before hydration.

The settings hub's Advanced card reads only the stored values it displays. It
must not call the full Advanced builder, category-ordering catalog, font
discovery, or answer-option preview sampler before that tool is opened. Keep the
focused `WordsetSettingsCustomUiTest` guards and the Genç-scale localized-config
wire-size budget when changing either path.

Flashcard bootstrap globals are single-owner payloads. Localize
`llToolsFlashcardsData` once on `ll-tools-flashcard-audio` and
`llToolsFlashcardsMessages` once on `ll-flc-util`; every startup consumer must
depend on the corresponding owner. Repeating those assignments on main or mode
handles grows every quiz-capable page and can overwrite mutations made by an
earlier responsive-options module.

## Evidence workflow

1. Identify the surface and the growth dimension: categories, words, media,
   user progress rows, prompt cards, or generated assets.
2. Reproduce with the nearest page, PHPUnit test, Playwright spec, or
   performance benchmark scenario. If the current fixture is too small, use the
   nearest opt-in profile rather than extrapolating from a tiny case: Genç for
   the production-shaped settings/recorder workload, XL for generic 3,000-word
   coverage, or stress-2x for 5,000-word saturation.
3. Capture the evidence that explains the slowdown: scenario duration, query
   count, response payload size, DOM node count, network waterfall, or a clear
   code-level unbounded loop.
4. Make the smallest change that changes the asymptotic behavior or removes the
   measured bottleneck. Cosmetic refactors do not count as performance work.
5. Re-run the same evidence path and a focused regression test.

## Benchmark commands

The normal benchmark stays modest enough for routine local use:

```bash
tests/bin/run-performance-benchmark.sh
```

For a quick smoke run:

```bash
LL_E2E_PERF_RUNS=1 tests/bin/run-performance-benchmark.sh
```

For the closest routine reproduction of the Genç wordset, settings hub, and
recorder queue, seed once and then reuse the static fixture:

```bash
LL_PERF_PROFILE=genc LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1 tests/bin/run-performance-benchmark.sh
LL_PERF_PROFILE=genc LL_PERF_SKIP_SEED=1 tests/bin/run-performance-benchmark.sh
```

The Genç profile requires the shared E2E admin credentials and keeps the normal
20-second interaction budget for search/progress/quiz work. Only recorder lazy
completion uses `LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS` (120 seconds by
default).

For XL coverage, use the opt-in XL profile:

```bash
LL_PERF_PROFILE=xl tests/bin/run-performance-benchmark.sh
```

The XL profile uses `tests/performance/fixtures/performance-wordsets-xl.json`,
targets `benchmarkTargetSize: "xl"`, defaults to one run per scenario, and
writes to `tests/performance/history/performance-history-xl.jsonl` plus
`tests/performance/reports/performance-latest-xl.*`.

The benchmark writes history when run through `tests/bin/run-performance-benchmark.sh`.
It also writes latest JSON and Markdown summaries under
`tests/performance/reports/`.
Use a longer command timeout for full runs; a timeout means the runner stopped,
not necessarily that the page under test failed.

To inspect existing history without reseeding or running Playwright:

```bash
node scripts/summarize-performance-history.js
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-xl.jsonl --scenario wordset-xl
```

## Local context packs

Context packs are generated, local-only summaries of related source files. They
are intended for architecture review, bug investigation, and performance
planning when the whole plugin is too large to read at once.

```bash
php scripts/build-ai-context-pack.php --list
php scripts/build-ai-context-pack.php --pack wordset-vocab-manager
php scripts/build-ai-context-pack.php --pack performance-benchmark --changed-only --manifest-only
php scripts/build-ai-context-pack.php --pack performance-benchmark --output -
```

Packs include git change-frequency hints for their source files. For
performance work, treat hot files as likely entry points, but keep following
the measured growth dimension and owner path when quiet files are the real
source of truth.

Generated packs default to `test-results/ai-context/`, which is ignored. Do not
commit generated packs unless there is a specific reason.
