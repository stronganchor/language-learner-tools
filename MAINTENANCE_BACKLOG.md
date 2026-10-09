# Maintenance Backlog

Updated October 9, 2026 during the authorized maintenance follow-up.
The original September 18 evidence and line numbers below refer to
`ed4405812767784e1d4e3eb688fc9ebbf632a41b` (6.7.39); current status notes identify
the implemented follow-up without erasing that history. This maintenance pass
does not deploy the changes or authorize provider/live-site testing.

This file is for worthwhile work that should be planned deliberately instead of
being folded into a small opportunistic fix.

## October 9 maintenance follow-up (6.7.46)

- **Implemented — AUDIT-2026-10-09-07:** the bundled lesson template and grid
  AJAX handler enforce WordPress password access before exposing lesson HTML,
  nonces, or cached grids. A valid unlock cookie follows core behavior; protected
  lessons remain excluded from shared anonymous static HTML.
- **Implemented — AUDIT-2026-10-09-01:** the offline builder uses Capacitor
  8.4.3 and sharp 0.35.5. Icon inputs are bounded, validated byte snapshots;
  SVG/renamed unsupported formats are rejected before native parsing. These
  changes address GHSA-rvm3-566m-v7fv and GHSA-wq5f-xc86-pv6w.
- **Implemented — AUDIT-2026-10-09-02:** vocabulary shortcodes apply the same
  canonical scope policy as the main vocabulary surfaces. Bulk checks preserve
  mixed public/private membership semantics and fail closed on incomplete
  relationship reads without retaining an erroneous denial across a retry.
- **Partially implemented — AUDIT-2026-10-09-03:** static cache schema 3 uses
  finite renderer-equivalent view keys, ignores ineffective selectors, and
  guards cold work across keys with atomic per-IP/site budgets. Locale switch
  URLs retain the existing clean redirect; unsupported section/custom variants
  bypass static publication. Completed, stale, and waited cache hits remain
  available without spending the cold-work budget. Disk quotas/reaping and a
  finite valid book-section manifest remain follow-up below.
- **Implemented — AUDIT-2026-10-09-04:** cold paged-grid preparation spends a
  shared budget, completed page hits precede order scans, and failed durable
  checkpoints cannot report progress. The browser bounds total/no-progress
  preparation, including response-body stalls, and presents a recoverable error.
- **Implemented — AUDIT-2026-10-09-05/06:** focused recorder reads retain their
  deadline through JSON parsing; failed study preference saves retain the draft
  and expose Saving/Saved/error/Retry states with revision and mutation fences.
- **Implemented — September 30 copy workflow follow-up:** an explicit Retry
  after repeated invalid cursors starts a fresh replacement request, preserving
  copied-card focus and avoiding duplicate append. Unrelated retries keep their
  cursor. **AUDIT-2026-09-18-06 extension:** native recorder failures use localized
  fallback messages while retaining structured server messages and prompt data.

### Deliberate follow-up

| Priority / owner | Evidence and remaining work | Risk / strategy | Verification |
| --- | --- | --- | --- |
| P2 / human design review: static cache disk lifecycle | `includes/lib/public-static-cache.php` limits variants and cold builders, but has no rolling byte/file quota or resumable generation garbage collection. Old generations can accumulate between invalidations. | A request-time directory scan would add anonymous work; an eager reaper could race active publication or remove useful stale fallback. Design a durable bounded maintenance cursor/journal, byte accounting, active-generation/lease fences, and a bounded valid book-section ID manifest before enabling caching for sections. | Test visit/time/byte caps, crash/resume, concurrent publication/purge, unwritable files, stale availability, and large directories; measure cleanup outside public requests. |
| P2 / autonomous measurement, human protocol review: AUDIT-2026-09-18-08 | The October 9 matrix reached a cold private-wordset readiness timeout; its unchanged-budget focused rerun passed. Actual authenticated recorder HTTP phase attribution and cold serial-batch completion remain unmeasured. | Keep the current protocol and timing budget. Add a bounded opt-in HTTP harness separating TTFB, body download, decode, batch scheduling, and rendering before choosing a shared-cache/protocol redesign. Existing PHP helper timings do not establish popup/page latency. | Repeat cold/warm runs with realistic large wordsets, varied access scopes and cache states; attribute database/provider/network/browser phases, confirm cancellation and fixture cleanup, then test any redesign against access and concurrency guards. |
| P3 / human product/access review: per-word password policy | The current canonical vocabulary policy governs category/wordset privacy; it does not define whether a protected individual `words` post should hide independently embedded media. The dictionary policy is already separate. | Decide intended staff/unlock/embed semantics before extending the policy; silently excluding every protected word could alter existing teaching workflows. | Verify shortcode, lesson, quiz, direct media/embed and authorized editor behavior for the agreed policy. |
| P3 / human release/language review | Builder tests, three Android ABIs and JVM PCM checks cover build behavior; no installed-device release or production HTTP test is implied. Native Turkish tone review and broader Audio Processor production queue/whole-page profiling remain open. | Test an installed build and hardware recording/playback before distribution. Review uncertain translations with a native speaker; keep larger/scope-varied queue profiling separate from implementation. | Device microphone/permissions, valid icons, offline lessons/playback, signed release/update path; targeted native language review and representative queue/TTFB measurements. |

Operators who reduce paged scan/page sizes or raise the materialized lesson limit
must review `ll_tools_vocab_lesson_grid_public_cache_miss_ip_limit` alongside
those filters. The fixed shared default budget covers the built-in 5,000-word
scan/page cycle; extreme custom settings must not automatically raise anonymous
capacity for every request. Live deployment, provider tests, and installed APK
distribution require their own scoped follow-through.

The three planned public locales `zh_CN`, `ar`, and `bn_BD` still have no
catalogs. Completing that rollout requires reviewed translations for 802 public
messages per locale. The eight available public locales and full Turkish/German
catalogs remain the verification scope for this maintenance release.

### Follow-up verification

- Full PHPUnit: 2,757 tests / 66,895 assertions / 10 expected skips passed in
  15 minutes 26 seconds, with no reported warnings or failures.
- Focused browser/source contracts: 84 unique cases passed (24 recorder, 13
  deferred-grid, 13 study-save recovery, 34 maintenance/documentation contracts).
  Git Bash fixture launch initially hit the Windows sandbox restriction; the
  same documentation cases passed with the required tool permission.
- The real Local private-wordset route/lazy-card case passed on its exact rerun
  in 2.7 minutes, bringing focused browser coverage to 85 unique cases. Its first
  attempt loaded the assigned manager's private cards, then hit a login 502:
  Nginx recorded `WSARecv` at 12:43:28 and the replacement PHP worker started at
  12:43:29. The rerun retained strict status/access assertions and the unchanged
  loading budget. Fixture cleanup completed; this is not a full browser matrix.
- Offline builder: 21/21 Node tests, zero reported npm advisories, successful
  debug APK compilation for three native ABIs, and 3/3 JVM PCM tests. No installed
  device, signed release, or APK distribution was tested.
- Source/POT freshness passed in the full suite. Turkish and German each retain
  complete 6,398-key catalogs with compiled MO/PHP parity; the eight available
  public locales cover all 802 manifest keys with compiled parity. All eight
  context manifests and changed PHP/JavaScript syntax checks passed.
- Local services remain running. These checks do not establish deployed state,
  production HTTP latency, provider acceptance, or native language approval.

## October 2 maintenance follow-up (6.7.45)

- **Implemented:** overlapping public-cache purges track independent active
  owners and retained failures; targeted success cannot clear an unrelated
  failure, and successful full recovery cannot clear a newer failure. Abandoned
  owners have scheduled recovery. Filesystem admission failure still rotates
  the database generation to fence prior captures and cached keys, and still
  removes old generated HTML even when the marker itself is unwritable.
- **Implemented:** legacy static-cache cleanup is admitted through the shared
  maintenance lease, runs outside anonymous requests, and retains a durable
  five-minute retry cooldown. Failed file enumeration leaves schemas pending;
  stale schemas cannot read or publish static HTML. Source/POT and public
  manifest references were refreshed without changing translation keys.
- **Implemented:** paged lesson copying/invalid-cursor recovery (details below)
  and the offline builder's dev-only `brace-expansion` update to 5.0.12. Builder
  tests pass 19/19 and its npm advisory check is clean.
- **Audio Processor investigation:** the read-only Local profile found 15,142
  published recordings but no pending queue, so its 1–36 ms samples are not
  large-queue evidence. An isolated transactional profile then checked 200
  pending rows (3.164–4.219 ms) and 2,000 pending rows over 400 words
  (52.039–58.560 ms), with exact 400 queue / 1,600 duplicate totals. These are
  warm-data PHP helper samples on Local, not admin-page or production timings.
  Keep the exact counts; these results do not justify a cache/materialization
  redesign. `AudioProcessorCountPerformanceTest` and the read-only CLI profile
  are repeatable diagnostics. Larger queues, varied recording types/access
  scopes, production database plans, and whole-page TTFB remain follow-up.
- **Still open:** `AUDIT-2026-09-18-08`; no recorder algorithm or timing budget
  changed in this pass. Actual authenticated HTTP phase attribution is still
  needed before changing the serial batch protocol. Native Turkish tone review
  also remains a human review queue; no uncertain translations were rewritten.
- **Local blocker resolved:** restarted an isolated Local background process
  with no children, site services, or listeners, then used the documented
  matching-site startup helper. The initial full PHP run executed 2,732 tests
  and exposed only four stale POT reference sets; final verification is recorded
  separately rather than treating that run as green.

### Follow-up verification

- Full PHPUnit: 2,736 tests / 66,434 assertions / 10 expected skips passed.
  After that run, the final marker-write error-path correction passed the
  focused public-cache suite (56 tests / 336 assertions), and refreshed public
  translation references passed 32 tests / 13,994 assertions. The full suite
  was not rerun after that narrowly covered correction.
- Focused browser suites: 61 distinct tests passed (documentation contracts,
  deferred lesson grid, and copy popup); final deferred-grid and documentation
  reruns also passed 10/10 and 34/34. This is not a full browser-matrix run.
- Offline builder: 19/19 tests and zero reported npm advisories. The opt-in
  Audio Processor profile passed 1 test / 6,018 assertions. All eight context
  manifests and source/POT freshness passed; Turkish/German catalogs retain
  6,396 keys each with compiled-catalog parity, and the public manifest retains
  800 keys. No translation identities changed.
- No named live site, deployment, edge-provider acceptance, or whole-page
  production timing was tested. Historical live-runbook notes are not current
  live verification. Local services remain running for follow-up work.

## September 30 copy workflow follow-up

- **October 2 status: implemented.** A completed scoped copy restarts the bounded
  loader with replacement semantics, preserves the copied card and focus,
  and ignores old in-flight responses. Invalid cursors also get one bounded
  restart; unrelated transport retries retain their cursor. Browser guards
  verify continuation without duplicate cards and copy focus preservation.
- **Original evidence — bounded lesson continuation after a content mutation:** Copying a word rotates
  the wordset/quiz epochs used by the lesson paging cursor. An already loaded
  partial category keeps its old cursor, and `js/vocab-lesson-page.js` currently
  reports `invalid_cursor` without replacing that cursor on Retry. Add a bounded
  first-page restart with replacement semantics and preserve the newly revealed
  copied card and focus. Do not clear the cursor and append page one, or hydrate
  the full category. Guard a copy from page one of a multi-page category, successful
  continuation without duplicate cards, and an independent transport failure.
  This is source-traced; the small-category copy fix does not change paging.

## September 18 audit issues

IDs are stable so later audits and implementation passes can update the same
issue instead of duplicating it. Each issue has its own current status.
"Autonomous" means
appropriate for an authorized implementation pass; it does not override a
review-only request. Evidence distinguishes isolated current-source probes
from source-traced risks and unmeasured production behavior.

### AUDIT-2026-09-18-01 — P1: Enforce dictionary entry password protection

- **September 25 status:** Implemented in 6.7.43. Public dictionary surfaces
  exclude password-protected entries even after a visitor unlocks one; an
  explicitly authorized staff editor retains editing access. Shared payload
  schemas advance and legacy generated HTML is purged. Tests cover detail,
  search/browse visibility, unlock cookies, and staff access. Byte-exact SQL
  guards also exclude spaces-only passwords from aggregates, crawler letters,
  and quiz-page duplicate selection. The legacy quiz query applies the same
  byte-exact SQL guard without per-entry hydration; catalog data/scope schemas
  and the builder token fence older materialized output. No deployed exposure
  or remediation is claimed.
- **Evidence and impact:** An isolated probe using the current plugin resolver
  and WordPress password function returned entry ID 77 while
  `post_password_required()` was true, with no password cookie. The public
  detail AJAX/render path was source-traced, not executed against deployed
  entries. Published protected entries in otherwise visible wordsets can pass
  the access guard and expose raw entry content, translations, and senses.
- **Owners:** `includes/lib/dictionary-browser.php:2380`
  (`ll_tools_dictionary_current_user_can_view_entry`);
  `includes/shortcodes/dictionary-shortcode.php:909`
  (`ll_tools_dictionary_resolve_entry_id`), detail handler at 4083 and anonymous
  registration at 4247. The crawler already excludes nonempty post passwords
  in `includes/lib/ai-crawler-support.php:647`.
- **Strategy:** Centralize password-aware public visibility across detail,
  browse/search, related-entry hydration, and shared caches. Audit existing
  cached output and invalidate affected entries as part of the eventual fix.
- **Verification:** Add real WordPress coverage for protected detail/browse/
  live-search results, warmed caches, password changes, and a valid-password
  visitor followed by an anonymous visitor in `DictionaryFeatureTest.php`.
- **Disposition/risk:** Autonomous; moderate compatibility risk. Supporting
  password-unlocked dictionary views needs an explicit cache/access policy.
  Whether any deployed entry is affected remains unknown.

### AUDIT-2026-09-18-02 — P2: Retain failed word-option autosave scopes

- **September 25 status:** Implemented in 6.7.43. Failed scopes remain dirty
  until explicit Retry succeeds, queued sibling edits still save, global Saved
  requires every scope to be acknowledged, and navigation warns about unsaved
  work. Browser coverage includes edits while the failed request is in flight.
- **Evidence and impact:** Current-source execution reproduced a failed group
  save followed by a successful pair save. Requests sent scopes `groups`, then
  `pairs`; the UI reported "Word options saved" while the group edit remained
  unsaved. Dirty scopes are discarded before acknowledgement, and the server
  intentionally excludes groups from a pair-only mutation.
- **Owners:** `js/word-option-rules-admin.js:120,135` (failure handling and
  `performSave`); `includes/admin/word-option-rules-admin.php:2704` (scope gate).
- **Strategy:** Keep dirty scopes until acknowledged, merge later edits without
  dropping failed work, expose explicit Retry, and never claim global Saved
  while a scope remains dirty. Preserve unsaved work during navigation.
- **Verification:** Browser cases for failed groups then successful pairs, the
  reverse order, edits during an in-flight failure, retry, and independent
  persisted readback. Successful-autosave coverage alone misses this transition.
- **Disposition/risk:** Autonomous; moderate concurrency/change risk. Do not
  automatically replay an uncertain non-idempotent operation.

### AUDIT-2026-09-18-03 — P2: Fence public-cache rebuild ownership and publication

- **September 25 status:** Implemented in 6.7.43. Atomic exact-owner leases and
  scoped generation markers fence publication and reads; protected lessons
  never enter shared files, including for password-unlocked visitors. Legacy
  generated files are purged before the migration marker advances. A stale
  owner cannot release a successor or delete its output. Optional bounded cold
  waiting defaults off; cold losers still render without publishing, so no
  production stampede/resource-saving claim is made.
- **Evidence and impact:** An in-memory probe of the actual lock helpers let A
  acquire, expire, and be replaced by B; A's later release deleted B's lock,
  allowing C to acquire while B remained active. Shutdown publication does not
  verify ownership or a captured mutation generation. Stale publication after
  purge is source-traced, not an end-to-end reproduction.
- **Owners:** `includes/lib/public-static-cache.php:558` (acquire/release),
  963-985 (cold lock loser), 1111-1135 (publication), and 1198 (purge locks).
- **Strategy:** Use exact-owner tokens, conditional takeover/release, and a
  generation check before publication. Measure concurrent cold requests: when
  no stale file exists, lock losers still dynamically render, so the current
  writer lock does not prevent a cold rendering stampede. Preserve cheap cache
  hits and crawlable links when choosing bounded waiting/retry behavior.
- **Verification:** Extend `PublicStaticCacheTest.php` beyond sequential lock
  checks to overlapping workers, expiry/takeover, and purge during rendering;
  measure cold concurrency before claiming resource savings.
- **Related privacy check:** Protected lessons with a valid anonymous password
  cookie qualify for public-file storage at lines 428/453. Exclude them from
  shared storage alongside issue 01. Ordinary no-cookie route requests remain
  gated and headers vary by Cookie; direct uploads-file exposure depends on
  server rules and was not tested. Do not claim a normal route cache-hit leak.
- **Disposition/risk:** Autonomous; moderate concurrency risk. Production load
  impact and cache-file access rules remain unmeasured.

### AUDIT-2026-09-18-04 — P2: Recover dictionary reads after terminal failure

- **September 25 status:** Implemented in 6.7.43. Search and toolbar failures
  replace shimmers with localized Retry; read deadlines cover response bodies
  as well as headers. Retry keeps the current query and stale responses remain
  fenced. Tests cover network/JSON/body stalls, warming exhaustion, and recovery.
- **Evidence and impact:** A rejected live-search fetch left two skeletons and
  "Loading dictionary results..." with no Retry, although `aria-busy` was
  cleared. Normal typed searches use the silent failure path. Toolbar, search,
  and detail reads also lack bounded request deadlines.
- **Owners:** `js/dictionary-shortcode.js:1519` (`requestResults` failure),
  1546 (typed-search caller), and read requests at 862, 1328, and 1449.
- **Strategy:** Replace loading markup with a translated error and Retry for
  the unchanged query; bound read deadlines and preserve stale-response fences
  plus ordinary form/link fallbacks.
- **Verification:** Extend `dictionary-shortcode-deferred-toolbar.spec.js` for
  network rejection, invalid JSON, exhausted warming retries, stalled reads,
  retry success, and query changes during recovery.
- **Disposition/risk:** Autonomous; low-to-moderate UI/change risk. A separate
  cached-A / pending-B / cached-A stale-response hypothesis was disproved:
  `showLoadingState()` aborts and generation-fences B. Do not reopen it without
  new evidence.

### AUDIT-2026-09-18-05 — P2: Keep independent Line-Up read failures visible

- **September 25 status:** Implemented in 6.7.43. Sequence and candidate reads
  have independent error/Retry state and bounded deadlines. A successful sibling
  cannot erase a failure. Relative mutation retries remain deliberately unchanged.
- **Evidence and impact:** Rejecting the sequence read showed an error; a
  later successful candidate read hid it, leaving the sequence unloaded with
  no explanation. A stalled read can also retain the shared disabled state.
- **Owners:** `js/category-lineup-manager.js:374` (`loadView` shared status),
  51 (`managerRequest`, no deadline); `css/category-lineup-manager.css:224`.
- **Strategy:** Track sequence/candidate read states independently, retain each
  failure until that view succeeds, and add bounded read deadlines and Retry.
- **Verification:** Both failure/success completion orders, one stalled view,
  independent retry, and preservation of the successful sibling's state.
- **Disposition/risk:** Autonomous for reads/UI; moderate change risk. Retrying
  relative move mutations after an uncertain response needs protocol review.

### AUDIT-2026-09-18-06 — P3: Localize native failures and correct Turkish drift

- **September 25 status:** Implemented in 6.7.43. Native transport/parser errors
  use localized fallbacks in lesson progress, dictionary inline edits, offline
  export, and Site Sync; validated server messages remain visible. Corrected
  the font-help wording and a CSV quiz-type label exposed by the expanded
  inflection guard. Rebuilt core and active public locale artifacts. Broad
  native fluency and uncertain mutation-replay protocols remain separate work.
- **Evidence and impact:** Executing `js/content-lesson-progress.js:130` with
  Turkish configuration displayed native `Failed to fetch` and JSON parser
  errors verbatim. Retry remained Turkish. Full catalog coverage does not catch
  this runtime bypass. At `languages/ll-tools-text-domain-tr_TR.po:5748`, font
  help translates "quizzes" as `testlerde` and ends with formal `getirin`;
  the glossary guard in `PublicUiTranslationManifestTest.php:303` misses that
  inflection.
- **Strategy:** Separate validated server messages from native transport/parser
  exceptions using the existing Copy/Split localized-error pattern. Correct
  the exact glossary/tone entry and regenerate both compiled Turkish catalogs.
- **Verification:** Turkish network/parser/timeout/server-error browser cases
  with retained state and Retry; an exact source-aware glossary regression;
  source/POT, full-catalog, and compiled parity checks.
- **Disposition/risk:** Exact fixes autonomous and low risk. Broader Turkish/
  German fluency remains human review. Similar source-traced native-error paths
  in dictionary inline edits, offline export, and Site Sync need targeted
  reproduction before expanding the implementation scope.

### AUDIT-2026-09-18-07 — P3: Remove confirmed unused private JavaScript helpers

- **September 25 status:** Implemented in 6.7.43. Removed the four named
  declaration-only wordset helpers plus three exclusively used dependencies,
  the IPA pagination pair, six obsolete localized keys, and unused pagination
  CSS. Preserved current selection planners and load-more behavior. No measured
  speedup is claimed for this cleanup.
- **Evidence:** Declaration-only private functions in `js/wordset-pages.js`:
  `collectWordIdsForCategories` (14832), `filterWordIdsByCategories` (14904),
  `pickBestCategoryIdByCounts` (14991), and `buildSelectionChunkLaunchPlan`
  (15229). `js/ipa-keyboard-admin.js:1630` has an unused
  `buildSearchPagination`, its sole helper `getSearchPaginationItems` at 1598,
  and six obsolete pagination labels in `includes/admin/ipa-keyboard-admin.php:466`.
  These are closure-private functions, not externally callable PHP aliases.
- **Strategy:** Reconfirm references, then narrowly remove unreachable helpers
  and their unused localized payload keys. Do not fold in a broad refactor.
- **Verification:** Selection/chunk/progress and transcription load-more
  browser coverage, JavaScript syntax, source/POT and catalog checks.
- **Disposition/risk:** Autonomous and low risk. No speedup has been measured.

### AUDIT-2026-09-18-08 — P2 investigation: Separate recorder server and media cost

- **September 25 status:** Partially addressed by 6.7.40–6.7.42 native recorder
  links, cached manifests/overviews, and prewarming; 6.7.43 adds bounded
  no-progress recovery, durable upgrade scheduling, and explicit summary-request
  versus media-transfer diagnostics. The guarded profiler now isolates server
  rebuild cost and resets only the verified fixture's exact summary keys.
  The Genç seeder also fixes a measurement defect: metadata previously called
  full-size source images medium previews. Version `2026-09-25-genc.2` creates
  truthful, reusable previews from at most 24 source images, with image-editor
  preflight before deleting the old tagged fixture. This changes the baseline,
  not the production image pipeline or the 120-second budget.
- **Evidence/status:** Keep the cold Genç recorder-summary investigation in
  the short list below. September 11 reached 132/209 categories within the
  120-second budget while previews transferred about 23.8 MB. The September 18
  audit did not rerun that seeded benchmark; this is historical Local evidence,
  not proof of a new or deployed regression.
- **September 25 server evidence (old Genç fixture):** All 209 categories
  completed in 35 bounded batches. Cold rebuild took 26,191.566 ms / 6,295 SQL
  queries after 211 exact-key deletions; a separate warm process took 296.970 ms
  / three queries. Fixture verification and catalog setup are outside this
  timed interval. The old normal browser arm stopped at 171/209 after 120 seconds
  with 11.1 MB of media transfer. Its media-blocked arm began with 177 categories
  already loaded rather than nine, so those arms are not comparable and do not
  establish a media-isolation speedup.
- **Corrected fixture evidence:** The `2026-09-25-genc.2` normal-media arm
  started cold (zero loaded, 209 placeholders) after a guarded 211-key reset,
  but still reached only 171/209 categories within 120 seconds. It made 30
  serial summary requests (one still in flight), with a 3,845 ms median full
  request duration, and transferred 2,280,301 media bytes. Correct preview sizing
  is verified; it does not close the completion-budget failure. Preserved
  failure trace/video live under the ignored
  `tests/e2e/test-results-maintenance-genc2-normal/` directory.
  The matched media-blocked arm used the same fixture/recorder/generation,
  another 211-key reset, and the same zero/209 initial state; it also failed,
  reaching 99/209 with 18 serial requests, a 5,879 ms request median, and zero
  media bytes. One local pair, including interception and runtime variation,
  cannot quantify a causal media contribution. Both failures retain the
  unchanged budget and remain failures; neither is appended to history.
- **Owners:** `includes/pages/wordset-pages.php` recorder-summary handlers,
  `includes/shortcodes/audio-recording-shortcode.php` candidate/category reads,
  `js/audio-recorder.js` shortcode viewport hydration, `js/wordset-pages.js`
  manager recorder-summary loading, and the Genç scenarios in
  `tests/e2e/specs/performance-benchmark.spec.js`.
- **6.7.44 implementation:** Prepared category eligibility once per bounded
  scan, bulk-remapped the already-known category IDs, primed category versions
  in one read, and avoided attachment reverse joins when direct image links
  already resolve the candidate. Exact counts, isolation ownership, fallback
  precedence, incomplete-source retry, batch size, and serial requests remain
  unchanged. The shortcode now observes all unresolved visible shells so a
  jump down the grid loads nearby categories; response-body timeouts, payload
  identity checks, bounded retries, and keyboard focus are retained or hardened.
- **Fresh-process evidence:** On the same corrected fixture and 211-key reset,
  the first cold six-category batch changed from 4,376.865 ms / 621 queries to
  2,051.474 ms / 198 queries; a later cold batch changed from 3,681.908 ms / 612
  queries to 1,632.880 ms / 194 queries. Warm batches used 12 queries (44.711 ms
  before, 25.072 ms after). Every case resolved the same six cards with matching
  response sizes. These are individual local diagnostic samples, not production
  page-load guarantees; bootstrap, authentication setup, and SQL phases are
  recorded separately by `profile-recorder-summary-fresh-process.ps1`.
  Measurements kept version metadata at 6.7.43 to avoid upgrade warmup changing
  the experiment; the final 6.7.44 commit contains the same recorder algorithms.
- **Final cold browser evidence:** Still **open**. The unchanged 120-second
  Genç manager benchmark again reached 171/209 from zero loaded / 209 initial
  placeholders, with 30 serial requests (one unfinished), a 3,908 ms request
  median, and maximum concurrency one. The intermediate pre-bulk run reached
  only 87/209; retain both failures rather than cherry-picking a speedup. Local
  PHP/HTTP runtime and database variability prevent a causal whole-page claim.
  Exact failure artifacts are retained under ignored
  `test-results/recorder-performance-20260925/browser-after-bulk/` and
  `browser-after-first-optimization/`. History remains unchanged.
- **HTTP diagnostic limitation:** Two bounded Local-only diagnostic passes
  each returned three successful summary responses, but the temporary MU
  profiler produced no server phase reports. Its exact installed file was
  removed and absence verified after each pass. The actual Local HTTP runtime
  is PHP 8.2 CGI, whereas the fresh-process probe uses PHP 8.4 CLI. Do not treat
  the browser response timings as SQL/phase attribution or assume the two
  runtimes' bootstrap costs match. No production profiler was installed.
- **Scoped live preview:** Only the three recorder production files were
  applied to Zazacaogren's 6.7.42 baseline with rollback copies and cache purges;
  this is not deployment of the separate 6.7.43 maintenance release. The
  unrelated correct/incorrect-sound files were hash-verified unchanged.
  Authenticated no-recording/no-upload UI checks confirmed that jumping to
  the bottom resolves nearby summaries while earlier offscreen shells remain
  deferred, native links and keyboard focus survive hydration, and Enter opens
  the selected category's one-item queue. No browser console errors were seen.
  This closes the observed viewport starvation bug, not the full cold budget.
- **Local verification:** Full PHPUnit passed 2,702 tests / 65,840 assertions
  with nine expected skips; the final recorder/recording-history/real-WordPress
  fixture/documentation browser set passed 94/94. Source/POT references,
  Turkish/German full catalogs (6,383 each), all eight active public locales
  (800 each), context packs, PHP/JS/PowerShell syntax, and diff hygiene passed.
- **Strategy/verification:** Profile summary PHP/SQL separately from preview
  sizing/network transfer; compare cold and warm runs on the same fixture and
  throttle settings. Retain serial loading, visible progress, and current
  limits until evidence identifies the growth dimension to change.
  Continue isolating actual HTTP request overhead from the now-measured fresh
  process batch costs: bootstrap/auth, full-catalog generation, category
  signatures/scan, and HTML/JSON rendering, with queries per phase.
  The CLI helper reuses one bootstrapped process and request-local caches across
  35 batches; it cannot apportion the browser's repeated request overhead.
  Optimize measured repeated work through durable manifest/prewarm consumption
  or a deliberately bounded continuation design before enlarging batches or
  adding concurrency. Acceptance remains 209/209 within 120 seconds, serial
  requests, bounded queries, and preserved foreground recovery.
- **Disposition/risk:** Local profiling autonomous and low risk. Production
  measurements require the live safety context; schema/protocol changes and
  budget changes need deliberate review. Do not infer deployed improvement
  from corrected synthetic media or nonmatching cache states.

## September 25 follow-up findings

- **AUDIT-2026-09-25-01 — Recorder warmup retry pressure:** 6.7.43 adds stable
  bounded scan-progress witnesses, increasing delays for unchanged incomplete
  work, and a longer pause/rearm. Foreground recovery remains independent; a
  permanently failing source must not produce a five-second cron loop.
- **AUDIT-2026-09-25-02 — Upgrade warmup durability:** 6.7.43 replaces a
  positional user offset and optimistic completion marker with a durable owned
  checkpoint and ascending user-ID cursor. Failed child scheduling or incomplete
  scopes retain the current user; deleting earlier users cannot skip later IDs.
  Completion must follow an acknowledged durable checkpoint, not an attempted
  schedule. Failed continuation schedules retain a non-autoloaded intent;
  capability-bearing requests inspect at most 100 intents and rearm at most 10
  due items, with backoff rather than a public or hot-cron repair scan.
- **AUDIT-2026-09-25-03 — Recorder link focus:** 6.7.43 retains focus on the same
  native category link while its summary hydrates; source/spec coverage includes
  clicking a category before previews finish. Manager neutral-shell behavior
  remains distinct from named shortcode navigation.
- **AUDIT-2026-09-25-04 — Stale public locale selection:** 6.7.43 checks normalized
  source references as well as gettext keys before public-manifest selection.
  Refreshed the POT, public manifest, and active catalogs, including translated
  dictionary recovery. A source-line-only move is now a failing freshness check.
  Updated architecture, router, and recorder/test documentation in the same pass.

## Current Short List

In addition to the actionable audit issues above, these follow-ups need product,
compatibility, schema/backfill, measurements, credentials, or human-language
judgment. The recorder investigation is tracked as AUDIT-2026-09-18-08:

- Continue evidence-led recorder profiling under AUDIT-2026-09-18-08 before
  changing its batch size or performance limit. Server-only cold/warm evidence
  and a corrected media fixture are now available; production latency and a
  matching-state browser/media comparison remain distinct from those results.
- Native review of machine-assisted German and residual Turkish admin/formal
  copy. Automated completeness and curated high-confidence regressions do not
  establish native fluency across the full catalogs.
- Real Google OAuth/provider sandbox acceptance. The local browser fixture now
  covers safe unconfigured and mocked connected states, but no local test can
  authorize Marketplace, CourseWork, or grade-passback claims.
- A normalized teacher-class membership table only if measured class size,
  deserialization cost, or assignment latency justifies a dual-write/backfill
  migration.
- A durable lesson-map materializer only if production measurements justify
  replacing the winning cold full scan.
- A normalized Line-Up order store with a physically bounded runtime projection
  and a bounded migration for legacy `ll_category_lineup_word_order` termmeta.
  Learner code now caps inspected IDs, candidate queries, and hydrated words,
  but WordPress can still deserialize the complete legacy order when another
  key primes that category's termmeta cache. Moving the authoring order must
  preserve taxonomy/editor, export/import, isolation-fallback, and rollback
  compatibility; a local prefix parser alone does not remove that peak.
- A dependency revision/materialization protocol for isolated categories that
  still inherit Line-Up order or direction from their source category. Current
  category locks and revisions serialize direct writes to the target term, but
  a later source-only change can alter the target's effective inherited value
  without advancing the target revision. Closing that cross-term dependency
  requires deterministic multi-term coordination plus dependency revisions,
  materializing independent target values, or deliberately ending inheritance;
  it should not be hidden inside the single-category autosave protocol.
- A paged offline progress-snapshot protocol for bundles above the existing
  5,000-word read scope. The compatibility sanitizer still caps that read list
  so an oversized bundle can sync its state/events; rejecting the whole request
  or changing client/server chunk semantics needs an explicit protocol rollout.
- Idempotency/recovery identifiers before applying automatic client deadlines
  or retries to offline-export job creation and Site Sync remote-write batches.
  Offline-export continuation requests now time out into an explicit Resume
  state, but a lost job-start or remote-write response remains ambiguous.
- Early render-surface declaration before removing the login/language-switcher
  base assets from unrelated public pages. Those shortcodes can render after
  `wp_head`, when self-enqueuing their stylesheet would be too late.
- An automated sweeper or dedicated admin view for rare Audio Processor
  `cleanup_failed` journals only if operational evidence shows retained
  non-autoloaded failure records accumulating after the bounded automatic
  retries. The journal intentionally preserves the uploads-root-safe orphan
  path for an authorized manual retry instead of discarding recovery evidence.

Keep performance work evidence-led and scoped to a measured growth dimension.
The local Google Classroom and authorized-private-wordset browser gaps are now
closed with controlled fixtures; live provider/site assertions remain outside
the normal regression suite.

### Historical verification inventory (September 25 implementation)

- The full PHP run executed 2,695 tests / 65,689 assertions / nine expected
  skips and exposed three dictionary regressions. Their SQL/password fixes
  passed the final affected-class runs: 319 tests / 20,721 assertions across
  dictionary, crawler, quiz catalog/query/translation/epochs/deduplication,
  public static cache, recorder resource coordination, and public translations.
  A new whitespace-password quiz test also found and now covers the legacy
  query path. This is full-run evidence plus targeted post-fix verification,
  not a claim that a single final unsharded run passed. Discard the separate
  interrupted run; its broken child processes and mixed source are not results.
- The affected browser set passed 142/142, and the final documentation,
  recovery-localization, and performance contracts passed 52/52. Discovery
  lists 888 tests in 125 files; the full browser inventory was not rerun.
- Source/POT keys and normalized references are fresh at 6,383. Turkish and
  German each pass 6,383/6,383 with exact compiled parity; all eight active
  public locales pass 800/800. Planned unshipped locales remain excluded from
  active-locale acceptance. All eight context packs validate; changed PHP and
  JavaScript syntax and diff checks pass.
- The earlier same-day review-only audit passed 19 offline-builder tests and
  reported zero Composer/E2E npm/builder npm advisories. Dependencies have not
  changed in this implementation pass. No production/provider or native-language
  acceptance is claimed, and no maintenance deployment or push was performed.
  The separate correct/incorrect-sound task owns its own committed changes;
  this pass does not modify its widget, media, or feedback-test files.
- Regenerated only tagged local performance data: the seeder replaced 16,512
  fixture posts, 209 quiz pages, 209 categories, one wordset, one synthetic
  recorder account, and 48 fixture media files. These are reproducible from
  the committed manifest/seeder; production content was not touched. The new
  fixture has 2,717 words, 8,151 audio rows, and 24 reusable medium previews.

### Previous verification inventory (September 18)

- Review-only audit at `ed440581`: PHPUnit passed 2,662 tests / 65,413
  assertions / nine expected skips. Maintenance/startup contracts passed 47/47;
  the selected browser set passed 149/149, including Local recording-tools
  persistence and both throttled page-load checks. Discovery found 852 tests
  in 122 files; this audit did not execute the full browser inventory.
- Builder tests passed 13/13; Composer and both npm audits reported zero
  advisories. Changed-source PHP lint passed 23/23 and JS syntax 14/14.
- Source/POT was fresh at 6,385 keys; Turkish/German each passed 6,385/6,385
  with compiled parity; eight active public locales passed 799/799. All eight
  context packs and 50 checked canonical-document links passed.
- The audit left source/docs/catalogs unchanged and ran no live-site/provider
  checks. Seeded Genç/stress benchmarks, deployed exposure, and native-language
  fluency were not revalidated. The open issues above concern cases missing
  from the current regression coverage; passing suites do not close them.

### Previous verification inventory (September 11)

- The complete PHPUnit suite passed **2,657 tests, 65,239 assertions, and nine
  expected skips**. A focused run of the cache, Games, lazy-card, and Copy/Split
  regressions passed 151 tests with 2,742 assertions. Failure diagnostics traced
  the earlier suite-only failures to request-local recording-type and taxonomy
  identity caches surviving fixture ID reuse. Simulated request boundaries now
  reset those exact caches; persisted-cache assertions remain unchanged.
- Final Playwright discovery lists **849 tests in 122 files**. Eight initial
  serial shards plus focused post-fix runs account for all 848 regular cases;
  this is coverage across runs, not one final unsharded run. The remaining
  opt-in performance case was exercised separately. Focused checks passed all
  59 Transcription Manager/Copy/Review cases, 47 startup/maintenance contracts,
  and 14 performance-runner contracts. The real WordPress recording-tools
  scenario also passed, including independent persistence readback.
- Local readiness was verified against the running site. Thirteen simulated
  startup cases cover stopped, starting, running, and failure states without
  stopping the user's app. Fourteen additional warmup contracts cover transient
  gateway/connection recovery, permanent failures, and the shared deadline.
  No real stopped-to-started Local transition was performed in this pass.
- Source/POT is fresh at 6,384 canonical keys. Turkish and German are complete
  at 6,384/6,384 with exact compiled MO/PHP parity; all eight active public
  locales pass 800/800. All eight AI context packs validate. Builder tests pass
  13/13 and its npm audit reports no vulnerabilities after adm-zip 0.6.1.
- Genç completed nine of ten performance scenarios, then exceeded the unchanged
  120-second cold recorder-completion budget. The measured follow-up is at the
  top of this backlog. A failed run does not replace the last successful
  aggregate report, so historical report files are not current-run evidence.
- Stress-2x passed all eight scenarios with the existing budgets against 100
  categories, 5,000 words, 15,000 audio records, and 5,100 images/attachments.
  The run reused the verified fixture after a transient Local PHP-CGI recycle
  had interrupted the first attempt's warmup. It measured one run per scenario
  without writing history; no comparable previous record was available, so this
  establishes current budget compliance rather than a performance improvement.

### Previous verification inventory (September 5)

- `PublicUiTranslationManifestTest` now includes a database-free canonical
  source/POT key comparison backed by a temporary WP-CLI extraction. The
  standalone command is `php scripts/check-i18n-source-pot.php`; it must be
  green after the catalog refresh and before catalog-count or locale-coverage
  checks are accepted. The source-frozen POT and complete Turkish/German core
  catalogs contain 6,313 canonical keys each; both compiled MO/PHP catalogs
  match exactly. The active public manifest and all eight active tier-2 locales
  pass 796/796.
- The maintenance browser contract owns both automation REST documentation and
  all eight routes registered by `includes/api/lms-rest.php`.
- The WordPress-backed teacher Classes invite scenario covers latest Practice
  score/date, 30-day attempt counts, dynamic column indexes, descending order,
  `aria-sort`, and focus retention. It requires the serial Local Playwright
  environment and admin credentials.
- Final Playwright discovery lists **726 tests in 114 files**. Eight serial
  shards plus the corrected-catalog focused rerun account for every case: 725
  passed and the opt-in seeded performance benchmark skipped once as expected,
  with zero product failures. One invalid shard was restarted after the
  manually started Local FastCGI worker ended and returned 502; the unchanged
  shard then passed 62/62.
- Both release-scale performance profiles passed without writing history. The
  force-seeded Genç fixture (209 categories, 2,717 words) passed 10/10
  scenarios, including cold recorder-queue completion in 98,043 ms; the
  stress-2x fixture (100 categories, 5,000 words, 15,000 audio records, and
  5,100 images/attachments) passed 8/8 scenarios.
- Final PHPUnit result: **2,506 tests, 61,790 assertions, 9 expected skips**.
  The standard complete suite exited successfully.

## Recently Closed

- September 11 follow-up: regression wrappers identify a registered Local site
  by its exact document root and target settings, probe only its required
  services, and start that site through Local when stopped. Existing running
  services are preserved. Transcription Manager drains pending transcription,
  review, and internal-note saves before replacing rows, retains failed drafts,
  and fences stale searches and view callbacks. Copy/Split and Transcription
  Review use translated defaults for transport/parser failures while retaining
  structured server errors and the existing no-replay recovery behavior.
- September 11 crawler follow-up: non-ASCII letter reads use exact indexed
  initial buckets populated by a bounded background backfill; incomplete source
  reads, changing epochs, and lost build leases produce retryable uncached
  failures. Anonymous cold builds use atomic client budgets and leases while
  cached reads and HEAD requests remain inexpensive. The Copy/Split cross-wordset
  cache concern is now a targeted regression for the existing invalidation
  protocol, not a speculative production change. The offline builder uses
  adm-zip 0.6.1; Turkish product names and immediate-adjacency regex help have
  explicit translation guards. Broad native-language review remains above.
- September 4 runtime/resource follow-up: an incomplete targeted vocab-lesson
  count now returns a retryable 503 before the legacy full-category fallback;
  offline sync rejects event batches above 200 before authentication or
  mutation; offline logout has atomic token/IP pre-auth admission; weighted
  fixed-window counters use a non-overwriting atomic first create and recover
  once from a concurrent zero-row cleanup without admitting on SQL errors; dictionary,
  common-category, and Word Audio bulk-state inputs are raw-shape/byte/count
  bounded before hydration; and learner Line-Up catalog/launch logic inspects
  only a capped sequence prefix and issues bounded candidate queries instead of
  sorting and hydrating every category word. Physical legacy-order storage is
  tracked separately above because WordPress termmeta caching can still load it.
- September 4 frontend/accessibility follow-up: vocab category autosave now
  keeps the latest queued edit across failure, serializes category writes, and
  fences stale or cross-tab revisions; title saves recover for explicit retry;
  offline-export continuation requests abort/fence on timeout and expose
  Resume; progress bars have programmatic labels; recorder animation/hover
  motion respects reduced-motion; and the Turkish transliteration example
  preserves its exact symbols.
- September 4 category-state atomicity follow-up: lesson autosave, taxonomy
  forms, Line-Up/default/audio/image settings, duplicate/copy, import, and
  deletion now share one category lock. Settings writes require transactional
  term metadata, verify their mutations, and commit with one revision advance;
  imports validate canonical per-key shapes and bounds, exclude the private
  revision, preserve omitted local Line-Up state, and verify failed-created-term
  rollback instead of treating a best-effort delete as proof. WordPress 6.1 is
  now the declared minimum so category creation can prove the exact raw term
  mapping from scoped hook arguments; concurrent duplicate winners are never
  adopted or Undo-tracked, template categories retain isolated ownership even
  when the global option is off, and uncertain cleanup remains recoverable
  without being counted as success. Regular imported term metadata now verifies
  deletion, every add, and exact serialized readback, with failures propagated
  through template and ordinary wordset imports. Failed deferred maintenance
  also retains a durable, independently scheduled full-pass intent behind any
  active quiz or vocab worker; follow-ups restart from cursor zero without
  clobbering active state, losing forced orphan cleanup, recursively creating
  passes, or being stranded by a failed worker-event write.
- September 4 focused coverage follow-up: Word Audio bulk recording-type edits,
  Site Sync orchestration, WebP mutation/retry behavior, autosave/title/export
  deadlines, reduced motion, admission guards, stale bulk-selection responses,
  and Line-Up query bounds now have direct PHP or browser regressions. The
  offline builder lockfile also resolves `@xmldom/xmldom` to the patched 0.8.15
  release; its npm audit is clean.

- September 1 maintenance/performance audit follow-up: teacher-class deletion
  now publishes an exact-owner deletion lease before its roster barrier, and
  membership writers recheck that barrier under the established user-to-class
  lock order. Privacy erasure preserves the externally owned `simplefavorites`
  value while an LL-owned marker prevents its legacy completion associations
  from replaying. Privacy decisions use error-detecting current usermeta reads.
  Direct LMS identity/recipient writers now join the learner transaction,
  require the verified assignment-schema marker, current-read both privacy
  fences, and re-read authoritative mappings before mutation.
- September 1 reporting, resource, and recovery follow-up: progress word stats
  aggregate in 100-user SQL chunks. The parity fallback is independently
  keyset-paged per learner, cap-bounded, aware of current recording
  requirements, and fail-closed without a partial summary; all report query
  failures remain typed through teacher rows/summaries and render `Unavailable`
  with true empty sort values. Client progress events without a stable UUID are
  rejected rather than assigned retry-breaking IDs. IPA Keyboard lesson links
  now query only requested category IDs under a 100-default/250-hard ceiling.
  Audio deletion uses durable non-autoloaded `pending`/`post_deleted`/
  `cleanup_failed`/`deleted` phases, bounded scheduled retries, validated upload
  paths, authoritative cleanup readback, and terminal verification.
- September 1 recorder-summary performance follow-up: every six-category batch
  reuses one complete-catalog manifest scope and generation. The optional
  aggregate retains only exact card counts and two-item previews while the
  richer per-category durable caches remain authoritative; the force-seeded
  Genç cold completion improved from 128,993 ms to 98,043 ms without increasing
  batch size or concurrency.
- September 1 UI, localization, and coverage follow-up: reduced-motion users no
  longer wait for decorative modal/delete transitions; upload prompt/option
  controllers share tested fallback behavior; the transient matcher save test
  uses deterministic request release; Google Classroom Disconnect and
  authorized/private wordset behavior have WordPress-backed browser coverage.
  The Turkish literal-token guard preserves API/import identifiers and the
  reviewed entries follow the informal learner tone.

- August 23 progress-event schema inventory: a 143,173-row fixed-high-water
  scan covered all six controlled sites. The 36,867 rows on WordBoat,
  NepaliBasics, YerelArapca, StarterEnglish, and TurkishTextbook matched the
  first-party payload schema exactly. Zazaca's 106,306 rows contained only 23
  early self-check events with the obsolete diagnostic key `placement`; there
  is no current producer or external integration for it.
  Existing rows remain untouched because derived state ignores the key. New
  browser and offline events are rebuilt from exact per-event key unions, so
  unknown or cross-event fields are dropped while stale journals are still
  acknowledged. Nested payload identity can no longer bypass the explicit
  identity-storage policy.

- August 23 controlled-fleet compatibility cleanup: exact source and database
  scans across WordBoat, Zazaca, NepaliBasics, YerelArapca, StarterEnglish, and
  TurkishTextbook found no external consumers of the audited global helpers or
  recorder category-page query/filter contract. The unused wrappers, unbounded
  category media helpers, synchronous quiz/category-delete shims, and
  first-party-unreachable pre-stream recorder branches were removed while the
  active stream, focused, hidden, and recorder-paging paths remain covered.
  Module splitting is closed as unnecessary without a measured route-cost or
  ownership failure; existing route-specific asset gating is the current answer.

- August 21 documentation, localization-contract, and teacher-report coverage:
  source gettext keys are compared with the checked-in POT without regenerating
  repository catalogs; the REST documentation contract now includes the eight
  native LMS assignment/attempt routes and their cookie-authenticated ownership
  boundary. The teacher Classes browser fixture now records canonical
  `mode_session_complete` Practice results and verifies the two new report
  columns. Its sort assertions resolve each header's live `cellIndex`, closing
  the stale hard-coded index failure introduced when the Practice columns were
  inserted, and also cover sort state plus retained button focus.

- August 21 public admission and auth resource protection: shared counters use
  atomic fixed-window reservations and exact-unit refunds; public login,
  registration, username suggestions, offline login/sync, lazy cards, and vocab
  grids bound raw inputs before expensive parsing, hashing, or queries. Generic
  rejected-request feedback reuses a bounded stable token while ordinary
  feedback remains one-shot.

- August 21 progress/report protection: event payloads enforce exact encoded
  byte, dynamic node, and depth budgets before and after enrichment while still
  supporting the 1,000-category session contract. Teacher Practice reporting
  replaces per-learner query round trips with adaptive bounded UNION batches,
  excludes payloads above 16 KiB, keeps the aggregate query near 24 MiB, and
  shows translated unavailable cells when a query fails instead of false zeroes.

- August 21 asynchronous recovery and autosave integrity: content completion,
  quiz-catalog warming, the Word Options modal, and other unavoidable waits now
  settle into translated Retry/error states with generation fences and focus
  recovery. Text-document review notes use one-flight/latest-queued browser
  saves and server compare-and-set retries so concurrent edits cannot silently
  overwrite each other.

- August 21 Audio Processor deletion: processing and deleting share one mutex;
  bulk work uses bounded concurrency and deadlines; the server uses user-scoped
  pending/deleted receipts plus exact-owner leases, deletes the post before
  unlinking files, preserves a vetoed live recording, and makes a retry
  idempotent.

- August 21 browser gap closure: local WordPress-backed fixtures now cover the
  Google Classroom unconfigured/mocked-connected admin states and private
  wordset access through assigned-manager lazy hydration, unassigned-user 404,
  and anonymous 404. They intentionally make no live-provider claim.

- August 12 schema, migration, and study-request protection:
  offline-session, learner-progress, dictionary, wordset-category-search, and
  image-match schema repair now runs only at explicit maintenance boundaries,
  with coalesced cron scheduling and exact-owner leases. The one-time dotless-i
  `recording_ipa` correction is a durable leased keyset cron migration rather
  than a public-init full scan/rebuild. Progress, snapshot, goals,
  recommendation, and analytics request collections now pass byte, count, and
  shape admission before JSON decoding, mapping, or downstream query work.
- August 12 accessibility and shared contracts:
  detached word-editor and recorder dialogs now contain focus, isolate the
  background, restore their opener, and follow a conservative Escape policy
  that cannot discard active recorder work. Recorder/manager invitation tokens
  share one canonical byte-compatible codec and fail-closed parser with
  tamper, cross-role, expiry, deleted-wordset, malformed-payload, and wrong-user
  coverage. Learner-registration transitions have one always-loaded owner plus
  focused multisite configuration and tests that preserve `none/user/blog/all`
  semantics and network maintenance context.
- August 12 copy, localization, compatibility, and test inventory:
  flat category settings no longer promise re-parenting, Turkish catalog
  source text contains no prohibited zero-width characters, and compatibility
  wrappers for DeepL language maps, bounded word-option pages, and bounded
  image-fixer scans have direct parity tests. Current no-install discovery lists
  629 Playwright tests in 100 files; the refreshed POT contains 6,000 messages,
  full Turkish/German catalogs pass at 6,000/6,000, and the tier-2 public
  manifest remains 793/793.

- July 31 security and resource protection:
  raw Basic compatibility auth now reserves both a coarse direct-peer allowance
  and a peer/login allowance before password hashing, refunds both on success,
  and cannot be bypassed by rotating usernames. Every public dictionary query
  argument is shape/byte/cardinality bounded before normalization or recursive
  cache-key traversal. The masked-media fallback adds bounded contention wait,
  safe-origin redirect, a 14-day stale ceiling, five-minute failure backoff,
  four-file/32-MB bucket caps, attachment-shard deletion cleanup, and daily
  bounded cursor maintenance with one-minute continuations. Browser/CDN cache
  lifetimes now decay with the file's remaining server freshness, and the
  65,536-shard sweep stops at its final shard instead of wrapping into another
  continuation cycle.
- July 31 quiz and matcher recovery:
  shared and standalone quiz dialogs now provide dialog semantics, focus
  containment/restoration, background isolation, translated loading, timeout,
  error, retry, and direct-open recovery, plus reduced-motion behavior. The
  existing mobile quiz/game zoom suppression remains intentional. Audio/Image
  Matcher requests and mutations are serialized; image/audio reads expose
  retry and abort on timeout, while assignments remain visibly saving until
  their authoritative response prevents ambiguous duplicate writes. Image
  choices are accessible native keyboard buttons, and rematches refresh
  deduplicated global used counts for both the old and new image. Candidate
  images remain paged at 48 by default with a 96-item hard maximum.
- July 31 preview, lesson-map, and browser coverage:
  inactive previews advance 10 items by default and at most 25 per continuation,
  retry a failed item without advancing its cursor, renew exact-owner leases,
  and expose serial progress. Lesson-map contenders immediately receive a
  complete last-known-good map instead of duplicating or waiting behind the
  full rebuild. Real WordPress browser coverage now exercises content-index
  pagination/accessibility, ranked numeric ordering/translations/pagination,
  and retained-source permanent redirects.
- July 31 localization and test inventory:
  German and Turkish are enforced as configured full-catalog locales, Turkish
  learner terminology and high-confidence informal public copy have regression
  guards, and a no-install discovery lists 479 Playwright tests in 97 spec
  files. The refreshed POT contains 5,994 messages, the public manifest
  contains 792 current strings, Turkish and German pass the complete-catalog
  gate at 5,994/5,994, and all eight active tier-2 locales pass the public
  manifest gate at 792/792 with current compiled catalogs.
- July 24 durable flashcard payload materialization:
  no-candidate category loads now advance a generation-fenced SQL materializer
  in bounded primary-word, image, and prompt-card batches, then read immutable
  signed keyset pages under row/byte and anonymous admission limits. Private
  category/wordset/support metadata is filtered or viewer-scoped, user progress
  remains a response-only overlay, unavoidable cold work exposes a retryable
  loading state, and activation/privileged maintenance owns schema DDL.
  Exact-generation retirement, read-side leases, scheduled-worker guards,
  keyset-bounded orphan cleanup, and 14 focused integration tests cover
  takeover and cleanup safety.
- July 24 maintenance and resource-protection pass:
  anonymous dictionary and flashcard cold builds now use atomic budgets,
  same-query cache coordination, bounded candidate input, and exact-owner
  leases; the wordset category search uses a durable, restartable, lease-fenced
  materializer with bounded keyset batches and explicit loading/error/Retry UI.
  Audio Processor normal pagination uses signed user/tab keyset cursors while
  cursorless legacy links are capped and rebase beyond their compatibility
  window. Hosted STT file reads and provider responses, review-note and
  interlinear REST list offsets, report-summary aggregation, compressed offline
  archive input, and wordset category-ordering rollback paths now have explicit
  resource or transaction bounds with focused regressions. Review-note ties now
  use deterministic title-plus-ID ordering.
- July 24 translation catalog integrity:
  the POT contains 5,810 current messages and the tier-2 public manifest
  contains 730 strings. Turkish and German track the complete POT without
  missing, blank, stale, fuzzy, duplicate, partial, or structurally invalid
  entries; all active tier-2 locales cover the public manifest, and compiled MO
  and PHP catalogs are current. German completion is machine-assisted and
  should be treated as best-effort rather than human-reviewed copy.
- July 17 maintenance, performance, and localization pass:
  Audio Processor queue/duplicate/reprocess tabs now load bounded 40-row AJAX
  pages with loading/error/retry/return-state UI; audio upload uses bounded
  speaker search; Site Sync transcription snapshots select recording IDs
  directly; IPA maintenance counts, progress analytics/reset work, and public
  category-search cold misses are bounded or stampede-protected. Corpus
  collection pages use a materialized lookup instead of scanning every Page.
  Shared upload-scope and advanced-grammar persistence helpers replace proven
  duplication, and four unused admin helpers were removed.
- July 17 translation catalog integrity:
  the Turkish PO tracked all 5,802 then-current POT messages with zero missing,
  untranslated, stale, fuzzy, duplicate, partial, or structurally invalid
  active entries. The full-catalog DeepL path fills raw `msgstr` fields without
  dropping comments/order, and the parser-backed full-catalog gate prevents a
  scheduled upkeep run from accepting blank admin copy or stale compiled
  runtime messages. All 729 then-current public strings remained complete across every active
  tier-2 locale.
- July 17 maintenance documentation drift:
  `npx --no-install playwright test --list` discovered 436 tests in 95 spec
  files at that time. Architecture routing names the word-metadata plan REST helper and
  durable word-grid bulk-operation module, the large-module estimates below
  match current source size, and the paged quiz-card launch invariant points to
  its canonical browser specs. The former WordPress-backed offline sync error
  fixture gap was already closed by `offline-app-sync-error-wp.spec.js`.
- July 13 legacy Classes admin paging and account search:
  class queries now use 20-row plus-one pages, administrator account options use
  shared 25-row plus-one search pages, and selected-class progress hydrates only
  a globally ordered 25-learner page. Native GET controls preserve state across
  mutations, reset empty class pages, clamp emptied final learner pages, and
  distinguish no matches from no classes. Deterministic ID tie-breakers keep
  duplicate titles/display names stable across offset pages; query-observer and
  browser regressions prove the bounded shapes, continuation UI, reverse-ID
  ordering, and filtered delete state.
- July 10 offline export category lazy loading:
  opening the export tool now loads no per-wordset category maps. A guarded AJAX
  endpoint fetches categories only after a wordset is selected, and loading or
  failure keeps export disabled. Full offline export coverage remains green.
- July 10 Recent Imports rendering caps:
  each history entry now renders at most 10 imported categories and five lesson
  links per category. Wordset filtering is part of the bounded lesson query,
  full category counts remain visible, and resource coverage verifies capped
  query count and shape.
- July 10 legacy translation migration cursor:
  the explicit maintenance action now selects at most 101 eligible IDs, writes
  at most 100 translations, and returns a keyset cursor with batch/cumulative
  counts for nonce-backed continuation. Tests cover a forced 2/2/1 sequence and
  the existing per-post capability boundary.
- July 10 pasted admin import limits:
  bulk word and prompt-audio submissions now reject more than 200 actionable
  rows or 256 KB before creating categories or posts. Prompt duplicate checks
  fetch at most one matching ID, and focused tests prove over-limit submissions
  are mutation-free while normal imports still work.
- July 10 direct import fallback job routing:
  direct admin-post import confirmation now creates the same durable import job
  used by the AJAX progress UI and redirects back for automatic resume. It no
  longer invokes the full-zip importer synchronously; handler and AJAX job tests
  cover shared conflict gating, bounded completion, cleanup, history, and undo.
- July 10 offline STT PCM resource ceilings:
  the offline shell rejects oversized encoded blobs and audio over 15 seconds;
  Android validates PCM parameters and byte budgets before decoding, then the
  model session and JNI layer independently cap 16 kHz samples before native
  inference. Browser, JVM, Java compile, and three-ABI native builds pass.
- July 10 vocab-lesson prompt-card shell guard:
  initial public lesson rendering now detects prompt-card mode through an
  ID-only query capped at three, without hydrating every prompt card or priming
  its metadata and terms. Full card rendering stays deferred, and the focused
  shell regression covers a category containing more cards than the cap.
- July 10 dictionary result linked-word count batching:
  visible dictionary result entries now receive linked-word counts from one
  bounded aggregate query instead of one `WP_Query` per card. Direct detail and
  admin callers keep the existing single-entry helper, and focused resource
  coverage verifies one aggregate query preserves zero and nonzero counts.
- July 10 recorder new-word preflight scope guard:
  category recording-type preflights now send the selected numeric and legacy
  wordset context, cache results per wordset/category, and reject an omitted or
  inaccessible explicit scope instead of consulting the default wordset.
  Focused PHP and Playwright coverage guards both sides of the request contract.
- July 10 REST word-row scan and report paging:
  `missing-meta` and broad `bulk-update` now query bounded candidate ID windows
  before hydrating editor rows. Sparse reads continue with candidate offsets;
  writes persist `scan_after_id` in resume state and rewind safely on failures.
  The REST full report now returns page-scoped counts with bounded ID paging,
  while WP-CLI retains the intentional full report. `AutomationRestApiTest`
  covers sparse scans, resumable writes, default cap compatibility, and report
  pages without unbounded word queries.
- July 10 REST category-scope query guard:
  `word-category-updates` and `word-category-terms` now resolve available
  category IDs through aggregate ownership and term-relationship queries
  instead of the Wordset Editor helper that could hydrate every word and owned
  image. The shared taxonomy helper also replaces the duplicate aggregate SQL
  in Bulk Word Import. `AutomationRestApiTest` covers legacy unowned word and
  image categories, cross-wordset exclusion, and the absence of unbounded post
  queries; the existing Bulk Word Import scope test covers shared-helper parity.
- July 10 transcription-validation candidate query cap:
  transcription validation refreshes and validation-job creation now select
  candidate `word_audio` IDs with a capped SQL query joined through the parent
  word's `wordset` term instead of first materializing every word ID in the
  wordset. Existing per-recording membership and stale-state checks remain as
  a second safety layer. `AutomationRestApiTest` covers cap behavior, wordset
  scoping, and issue-only versus all-recording candidate scopes.
- July 10 dictionary toolbar AJAX cold-miss guard:
  anonymous `ll_tools_dictionary_toolbar_bootstrap` cache misses now use a
  short option-backed build lock keyed to the same normalized AJAX cache key.
  The build owner renders and stores the toolbar payload while concurrent cold
  misses receive a retryable `cache_warming` response and keep the existing
  loading shell. `DictionaryFeatureTest` covers lock expiry/release and the
  locked toolbar response.
- July 10 Turkish i18n and testing-doc drift cleanup:
  the interlinear phrase-row header now renders through the existing translated
  `phrase` string, the tier-2 public source range covers that render path,
  Turkish `slug`/`Signed in`/formal-tone regressions were corrected and guarded,
  and the testing docs now record the measured local Playwright inventory as
  368 tests in 81 files. The local-only live-site runbook still needs a fresh
  read-only smoke result when a deploy makes that useful.
- July 3 audio credit grid cold-cache guard:
  `[audio_credit_grid]` now builds its public recording-ID cache in bounded
  batches, uses an option-backed rebuild lock to avoid concurrent cold-cache
  stampedes, falls back to the last good stale ID list while another request is
  rebuilding, and rechecks public-credit metadata before rendering stale IDs.
  `AudioCreditGridShortcodeTest` covers bounded query shape, stale fallback,
  invalidation, pagination, and the existing no-`SQL_CALC_FOUND_ROWS` invariant.
- July 3 vocab lesson grid AJAX cold-cache guard:
  anonymous `ll_tools_get_vocab_lesson_grid` cache misses now use a per-grid
  build lock, a short wait-for-cache path for concurrent requests, per-lesson
  and per-IP miss throttles, and cache empty HTML as a legitimate result.
  `VocabLessonDeferredGridTest` covers successful lock release/cache writes,
  locked cold misses avoiding heavy render work, and cache hits bypassing the
  miss throttle.
- July 3 image copyright grid public-surface guard:
  `[image_copyright_grid]` now ignores inaccessible selected wordset/category
  terms, filters dropdown terms to the current user's visible vocab scope,
  applies direct visible-term tax constraints plus word-image owner-meta
  constraints to the public query, and caps shortcode page size.
  `ImageCopyrightGridShortcodeTest` covers private wordset/category exclusion,
  filtered controls, search behavior, and the page-size cap.
- June 26 Playwright flake-tracking follow-up:
  the two shard-1 audit flakes were rerun directly. A cold paired run reproduced
  one admin navigation timeout before `/wp-admin/tools.php?page=ll-import`
  committed and one flashcard start-flow timeout, while direct `curl` checks
  showed the Local site responding. Immediate isolated reruns then passed for
  `admin-import-preview-undo.spec.js` and `flashcard-widget-start-flow.spec.js`,
  the paired run passed, and a paired `--repeat-each=3` run passed 6/6. Treat
  any future recurrence as Local browser/admin startup pressure unless a focused
  rerun produces an app-level assertion failure.
- June 26 maintenance/performance docs refresh:
  the testing docs recorded the measured local Playwright inventory as 363
  tests in 81 files, the backlog reflects the June 26 closed resource guards,
  and the performance/architecture docs include the AI crawler export cache and
  cheap HEAD invariant.
- June 26 AI crawler export resource-guard follow-up:
  generated `/llms.txt` and `/ll-tools/*` GET export bodies are cached in the
  object cache and transients by route, locale, dictionary browser version, and
  wordset/category cache epochs. HEAD requests remain header-only and do not
  build cold export bodies. `AiCrawlerSupportTest` covers cache hit/miss,
  dictionary-version invalidation, and no-build HEAD behavior.
- June 26 dictionary detail linked-word resource-guard follow-up:
  public dictionary entry-detail rendering now uses a publish-only capped helper
  query for linked-word preview rows, with a default preview limit of 24,
  limit+1 truncation detection, and cache schema isolation. `DictionaryFeatureTest`
  covers the capped helper and detail rendering before `[word_grid]`.
- June 26 flat-category regression cleanup:
  upload-form and wordset search regression tests now match the flat exact
  category model, and the unreachable image-upload parent-category renderer was
  removed after the aligned tests passed.
- June 19 WebP optimizer queue resource-guard follow-up:
  the admin WebP optimizer queue no longer requests all `word_images` IDs in a
  single unbounded query on every page load. Queue indexing now scans in bounded
  chunks, stores only compact sortable rows, reuses a short-lived invalidated
  index for follow-up pages and bulk ID fetches, and hydrates only the visible
  page rows for card rendering. `ImageWebpOptimizerAdminTest` covers the
  bounded query shape and compact-index reuse.
- June 19 public flashcard AJAX cache-stampede follow-up:
  anonymous cold misses for `ll_get_words_by_category` now acquire a short
  build lock keyed to the normalized public cache arguments. Duplicate misses
  get a retryable `cache_warming`/429 response using existing localized retry
  copy while the first request warms the cache. `SecurityHardeningRegressionTest`
  covers the server guard, and `flashcard-loader-wordset-isolation.spec.js`
  covers the client retry branch.
- June 10 offline sync error-UX follow-up:
  `tests/e2e/specs/offline-app-shell-launcher.spec.js` now covers offline sync
  login failure and server sync failure responses in the real offline shell
  wiring. Failed logins keep the sign-in sheet open and re-enable submit
  without starting sync; failed server sync keeps the user connected, leaves
  pending local progress visible, and exposes the failure feedback so progress
  is not silently discarded.
- June 10 offline remote snapshot follow-up:
  `offline-app/offline-app.js` now mirrors remote `userStudyState` starred
  words, star mode, and fast-transition preferences into the top-level
  flashcard fields consumed by offline launches. `tests/e2e/specs/offline-app-shell-launcher.spec.js`
  now covers a remote sync snapshot updating selected categories, progress sort
  metrics, next recommendation UI, session-word launch plans, and synced study
  preferences.
- June 10 Speaking Practice hosted API failure follow-up:
  `tests/e2e/specs/wordset-games-space-shooter.spec.js` now covers hosted
  transcribe and score failures in the real Speaking Practice popup flow. The
  runtime returns to retry state, keeps the result panel hidden, avoids progress
  outcome writes, does not call score after transcribe failure, and resolves raw
  upstream/provider messages to the localized retry message.
- June 10 Speaking Practice microphone-denial follow-up:
  `tests/e2e/specs/wordset-games-space-shooter.spec.js` now covers a hosted
  Speaking Practice launch where `getUserMedia()` rejects with a browser
  permission error. The runtime shows the localized microphone failure message,
  returns the record control to retry state, avoids transcription/scoring POSTs,
  and no longer surfaces raw browser permission text.
- June 10 teacher-class coverage verification follow-up:
  `tests/e2e/specs/teacher-classes-frontend.spec.js` passed locally and already
  covers the frontend teacher-role create/delete path, signup invite
  registration, admin assignment of an existing learner, class progress-table
  sorting, and learner removal. This closes the stale backlog wording that
  listed assignment, invite, and progress-table flows as still uncovered.
- June 10 prompt-card recorder real-upload E2E follow-up:
  `tests/e2e/specs/audio-recorder-prompt-card-upload.spec.js` now seeds a
  marked local WordPress fixture, logs in as the limited `audio_recorder` role,
  verifies inaccessible prompt cards are rejected by the real AJAX handler, and
  posts a valid WAV multipart upload that becomes the prompt card's prompt-audio
  attachment. This closes the main permission plus real-media-upload browser
  gap for prompt-card recorder queues while leaving real browser microphone
  permission permutations as future coverage.
- June 10 content lesson route/media E2E follow-up:
  `tests/e2e/specs/content-lesson-route-media.spec.js` now uses a marked
  WP-CLI fixture to seed a real `ll_content_lesson` route with audio media,
  parsed transcript cues, post notes, and a related vocab lesson link. This
  now also uploads and plays a real WAV with range support, finite duration,
  and cue seeking, and covers corpus reader/collection/source routes with one
  accessible page title. This closes the previously listed media and corpus
  route gaps.
- June 10 flashcard shell duplication follow-up: added
  `includes/flashcard-shell.php` as the shared renderer for the flashcard
  overlay popup, mode switcher, results controls, and guarded repeat-button
  initializer. The public flashcard template, offline app shell, and
  quiz-page/vocab-lesson popup bootstrap now call that renderer instead of
  carrying separate copies of the same ID-sensitive shell.
- June 10 E2E admin REST helper follow-up: `tests/e2e/helpers/admin.js` now
  wraps in-page WordPress REST fixture calls with a hard Promise timeout, so
  temporary admin fixture setup can fail fast and fall back instead of consuming
  the full Playwright test timeout.
- June 10 Speaking Practice browser coverage follow-up:
  `tests/e2e/specs/wordset-games-space-shooter.spec.js` now includes a
  self-contained mocked microphone/MediaRecorder/AudioContext path that launches
  Speaking Practice, records an attempt, posts the transcribe and score AJAX
  actions, renders the scored result, and queues the expected progress outcome.
- June 10 architecture documentation drift follow-up: `CODEBASE_ARCHITECTURE.md`
  now has a direct bootstrap include index that mirrors plugin-owned
  `includes/bootstrap.php` `require_once` paths in load order, and the
  maintenance source-contract spec now fails if that index drifts from the
  loaded module list.

- June 10 Turkish localization quality follow-up: normalized the Turkish
  `part of speech` glossary cluster from `Sözcük Türü`/`sözcük türü` to
  `Konuşma Parçası`/`konuşma parçası`, rebuilt the compiled Turkish locale
  files, added the glossary term to the Turkish review guide, and added a
  maintenance source-contract check for high-risk Turkish tone/glossary drift.
- June 10 public JS localization fallback follow-up: `js/wordset-games.js`
  now reads game UI labels, status text, alerts, and ARIA copy through localized
  `llWordsetPageData.i18n` helpers instead of duplicating English fallbacks in
  the public runtime. The wordset-games Playwright fixture now supplies the full
  game i18n payload, and the maintenance source-contract spec guards against
  reintroducing English `ctx.i18n`/`cfg.i18n` fallback strings in the games JS.
- June 10 Word Images admin category-count follow-up: the
  `word_images` list-table category filter now computes all visible image
  counts with one aggregate taxonomy/post query instead of running one
  `WP_Query` per category. Focused PHPUnit coverage verifies visible admin
  status semantics, empty categories, selected-option rendering, and the
  single-query shape.
- June 10 full local E2E timeout investigation: `bash tests/bin/run-e2e.sh --list`
  found 314 Playwright tests, and all four local shards completed successfully
  with 313 passed and 1 skipped. Later follow-ups expanded the suite; the June
  26 documentation refresh listed 363 tests in 81 files, and the July 10 weekly
  audit listed 368 tests in 81 files. The July 17 discovery listed 436 tests in
  95 files, a July 24 discovery listed 453 tests in 95 files, and a July 31,
  2026 no-install discovery lists 479 tests in 97 spec files. These are dated
  discovery snapshots, not a
  fixed suite contract. No hung spec was isolated. Treat the unsharded local
  E2E command as a long serial suite in automation; use `--list`, shards, and a
  timeout of at least 35 minutes before declaring a runner hang.
- June 10 wordset category search indexing follow-up: the main wordset render
  path localizes a tokenized `categorySearch` config instead of the full
  per-category word-search text. As of July 31, the first word/translation
  search request reads a durable bounded materialization rather than building
  the whole search index on a public request; preparation remains retryable and
  preserves hidden-selection cleanup, empty-state behavior, clear-button
  behavior, lazy-card hydration, and diacritic-insensitive matching.

1. Add browser/source-contract coverage for major feature areas that still have mostly PHP or manual coverage.
   - Content lessons in the mixed lesson grid now have PHP ordering coverage plus focused browser coverage for rendered order, content-card search, category-only selection behavior, a WordPress-backed real route with uploaded WAV playback/cue seeking/related-vocab behavior, corpus collection/reader/source variants, public content-index pagination/accessibility, ranked numeric ordering/translations/pagination, and retained-shadow permanent redirects. Extend this coverage when the payload or media contract changes rather than retaining the former media/corpus gap.
   - Prompt-card recorder queue flows. Focused browser fixtures now cover prompt-card prompt-audio upload/advance behavior, a local WordPress-backed prompt-card queue item, and a limited-recorder real multipart prompt-audio upload with an inaccessible-card rejection check. Remaining prompt-card recorder gaps are real browser microphone permission permutations and future data-contract changes. Prompt-card quiz payload and lesson-grid shells also have focused browser coverage; keep extending those specs when the data contract changes.
   - Teacher class flows now have frontend Playwright coverage for a
     teacher-role user creating/deleting a class, signup invite registration,
     admin assignment of an existing learner, progress-table sorting, learner
     removal, and the limited-role `admin-post.php` selected-class redirect.
   - Offline app shell launcher and sync-panel wiring now have self-contained
     browser coverage for launcher selection/sort/launch, sync-panel sign-in,
     login failure, manual sync/disconnect, failed server sync feedback, and
     remote snapshot application to selected categories, progress sorting,
     recommendations, and study preferences. The WordPress-backed
     `offline-app-sync-error-wp.spec.js` fixture also covers a real login, forced
     sync conflict, retained pending progress, and manual retry. The remaining
     offline browser gap is service-worker/install behavior if that runtime is
     ever added.
   - Less-covered games: Line Up now has browser startup, retry, reorder, progress-event, and completion coverage; Unscramble now has keyboard tile-reorder, progress-event, and completion coverage; Speaking Stack has focused browser coverage for stack placement and pre-attempt fall speed; Speaking Practice now has mocked browser coverage for record -> transcribe -> score UI and progress behavior, microphone-denied retry state, and hosted transcribe/score failure retry states. Remaining live game gaps are real browser permission-prompt variations and live hosted API behavior under real credentials/latency.
   - The Site Tools frontend now has Playwright coverage for admin form wiring,
     recording-type controls, managed-page controls, maintenance action wiring,
     a safe cache-flush submit path, and mobile overflow.
   - A lightweight Playwright source-contract spec now checks that registered
     public shortcodes stay listed in `README.md`, high-confidence user-facing
     PHP/JS string contexts use translation-ready wrappers, wordset game
     UI copy does not duplicate English `i18n` fallbacks in public JS, and the
     Turkish PO avoids high-risk glossary/tone regressions. It also guards the
     architecture guide's direct bootstrap include index against load-map drift.
     Keep extending this kind of static coverage where it catches real
     maintenance drift with low flake risk.

2. Keep Site Sync reads bounded while preserving complete admin comparisons.
   - `GET /wordsets/{wordset}/site-sync/snapshot` now defaults omitted, zero, and negative `per_page` values to 100 rows, caps positive pages at 250, and always returns continuation metadata; `include_media` still defaults to true.
   - Transcription pages select `word_audio` IDs directly through the wordset relationship, metadata pages hydrate only their requested words, and the admin assembles explicit complete comparisons by iterating bounded local and remote pages.
   - Full pull/push plan construction can still be proportional to both compared catalogs in PHP. If production scale requires a hard comparison-memory bound, move the diff into a durable cursor-backed job rather than restoring an unpaged query.
   - Verify future Site Sync contract changes against `docs/REST_AUTOMATION.md`, local REST tests, and at least one controlled staging sync workflow before deployment.

3. Keep the audited helper decisions explicit.
   - `ll_tools_dictionary_get_scope_filter_index()` is currently an internal/cache-validation helper covered by tests; keep it until dictionary filters render from a precomputed index or remove it together with the cache-validation test.
   - The August 23 controlled-fleet source/database scan found no consumers of
     `get_deepl_language_codes()`, `ll_tools_word_option_rules_get_word_posts()`,
     `ll_find_words_missing_word_images()`,
     `ll_tools_wordset_page_delete_category_for_wordset()`, or
     `ll_tools_cleanup_invalid_quiz_pages()`. Those wrappers are removed; use
     their bounded canonical APIs rather than recreating compatibility aliases.

4. Keep architecture and operator docs current after large feature work.
   - `CODEBASE_ARCHITECTURE.md` now includes the newer cache, automation, offline, prompt-audio, teacher-class, and dictionary-source modules, plus a source-contract-guarded direct bootstrap include index. Keep refreshing narrative flow docs whenever another large workflow lands.
   - `README.md` shortcode coverage is now guarded by a Playwright source-contract regression; update the README and the test together when intentionally adding or removing public shortcodes.

## Follow-Up Notes

- Audio Processor normal continuation now uses signed keyset cursors. Direct
  cursorless legacy URLs intentionally retain SQL `OFFSET` compatibility only
  through a filterable 5,000-row ceiling (50,000 hard maximum), then rebase to
  page one. Removing the bounded fallback entirely would change the externally
  addressable page contract and needs explicit compatibility review.
- The durable flashcard materializer intentionally makes the client drain all
  immutable pages before quiz start. Starting from a partial page could improve
  first-interaction time on very large categories, but it needs a product
  decision about randomization, option-pool completeness, progress totals, and
  whether later-page failures may interrupt an already-started session.
- Flashcard rebuilds currently return a loading state instead of serving a
  last-known-good generation after dependency drift. A future stale-serving
  policy must define which schema, privacy, viewer, locale, and wordset changes
  remain compatible; do not reuse an old generation across those boundaries
  merely to hide warming.
- Flashcard builds use one global lease plus a scope lease, which deliberately
  serializes expensive materialization across scopes. Revisit scheduling only
  after measuring real queue delay or starvation across many simultaneously
  cold categories; any concurrency increase must preserve the per-scope
  generation/CAS and anonymous resource guards.
- One prompt card may include at most 300 bounded support-word IDs in the
  materializer. Supporting larger cards needs a nested durable support cursor
  or an explicit content-validation policy, not a higher synchronous cap.
- The pre-stream manager recorder overview and its category-page query/filter
  contract were removed after the controlled-fleet scan found no consumers.
  Normal visible manager queues use the bounded stream; focused and hidden
  modes plus hidden recorder paging remain active and covered.
- Turkish full-catalog completeness is now a hard source/test/automation
  contract. The runtime blank filter remains defense in depth for interrupted
  local merges, but a scheduled upkeep run cannot close or advance its HEAD
  guard until every current Turkish POT entry is translated and structurally
  valid.
- Site Sync snapshot queries are bounded to 100 rows by default and 250 maximum,
  including omitted/zero values. Full pull/push comparison can still be
  proportional to both catalogs after the admin assembles those pages; a hard
  memory bound would require a durable server-side diff design and staging
  review.
- Corpus collection lookup has a bounded 20-candidate compatibility fallback.
  Saving a legacy collection Page materializes its index; a pre-index page
  outside that candidate window waits for a save/reindex instead of triggering
  an unbounded request scan.
- Advanced grammar settings now share normalization/persistence. Category-order
  persistence remains separate because the manager aborts on `WP_Error` while
  taxonomy admin queues a notice and continues; unify it only after choosing an
  explicit mutation/error policy. Smaller game/font settings duplication is a
  low-risk future cleanup.
- Earlier audit item 3 meant the Teacher Practice report-query scaling risk.
  The report no longer issues one event-history query per
  learner or accepts large event payloads into each batch. It now queries only
  the already-paged roster with adaptive UNION batches, a 500+1 row sentinel,
  a 16 KiB payload filter, and an approximately 24 MiB aggregate-query budget.
  That is the bounded solution for the current Classes page, so no product or
  schema decision is needed now. The remaining rows-examined risk is that the
  existing `(user_id, wordset_id, created_at)` index cannot seek directly on
  the later `event_type`, `mode`, and payload-size filters. If production
  latency becomes material, capture `EXPLAIN`, row cardinality, and request
  timing first; use that evidence to choose an online composite index or a
  durable Practice summary. Either choice adds schema, backfill, invalidation,
  and rollback work and should not be made from local file size alone.
- Teacher-class membership remains duplicated in serialized class/user meta.
  Large classes still deserialize the complete member-ID array for counts and
  pass it to assignment exclusion before bounded progress hydration. A future
  normalized membership-table migration should provide indexed counts and
  keyset progress/assignment queries while preserving a tested dual-write,
  backfill, and rollback window; do not replace the bounded admin path with a
  full progress scan to recover class-wide page metrics.
- Offline app service-worker/install behavior is still a future coverage item
  only if a browser PWA/service-worker runtime is added; the current offline app
  path is a local-first web/APK shell and does not register a service worker.
- Live hosted API checks for Speaking Practice should remain behind explicit
  approval and credentials; local mocked coverage now covers success, mic
  denial, transcribe failure, and score failure UI states.
- Real browser permission-prompt permutations are best handled as targeted
  manual/staged checks because Chromium automation can reliably fake device
  errors but not every browser prompt/state combination users see in Chrome and
  OS-level privacy settings.
- Wordset editor IPA keyboard metadata needs a larger cached-data design if
  editors expect off-page IPA symbols and letter aids while browsing a paged
  large wordset. The current modal path keeps `word_grid` rendering bounded by
  visible `specific_word_ids`, so its IPA symbol inventory can omit symbols
  that only appear on other pages. Do not fix this by restoring a synchronous
  full-wordset `word_audio` scan in the editor modal. Prefer a materialized
  wordset-level IPA inventory/letter-map cache, maintained by bounded
  recording-save hooks or an explicit admin rebuild job, or lazy-load keyboard
  metadata asynchronously when the editor opens. Existing wordset term meta
  such as `ll_wordset_ipa_special_chars` and `ll_wordset_ipa_letter_map` can
  be part of the read path, but only after invalidation/rebuild behavior is
  clear enough for large wordsets.

## Deferred Larger Projects

1. Plan scalable wordset and vocab-lesson routing as its own migration project.
   - Current root-level pretty routes are registered per enabled wordset in
     `includes/pages/wordset-pages.php` and
     `includes/pages/vocab-lesson-pages.php`.
   - This is acceptable at the current scale, but it should be replaced or
     supplemented before a large live site has many dozens of wordsets.
   - Treat this as a deliberate compatibility migration, not an opportunistic
     cleanup. Existing saved URLs and bookmarks must keep working or redirect
     cleanly.
   - The embed route is a hard compatibility constraint:
     `/embed/<category>?wordset=<slug>&mode=practice|learning|listening|gender|self-check`
     must keep working for Word Boat embedded quizzes.
   - Add regression tests for root wordset URLs, vocab lesson URLs, canonical
     query URLs, and embedded Biblical Hebrew quiz URLs before changing the
     route shape.
   - A likely target shape is a small fixed set of prefixed routes such as
     `/learn/<wordset>/...`, with old root pretty URLs preserved through narrow
     redirects.

2. Continue the tier-2 public UI translation rollout deliberately.
   - Run a QA pass over `languages/tier2-public-ui-strings.json` to reduce the
     public string set by removing unnecessary copy, replacing text with icons
     where appropriate, and reusing existing strings where the context allows.
   - Audit whether a tier-2 learner locale can be used on public surfaces while
     elevated manager/admin UI falls back to a core full locale such as English.
   - Active tier-2 public UI PO files now cover Russian (`ru_RU`), Hindi
     (`hi_IN`), Spanish (`es_ES`), French (`fr_FR`), Portuguese Brazil
     (`pt_BR`), Indonesian (`id_ID`), Korean (`ko_KR`), and Italian (`it_IT`).
     Keep using the manifest coverage checker and targeted browser smoke checks
     when changing public locale coverage.
   - For tier-2 public locales, release readiness is based on the public UI
     manifest, not raw full-plugin PO absence counts. A locale can have complete
     learner/visitor coverage while still omitting admin-only or manager-only
     strings outside `tier2-public-ui-sources.php`.
   - Planned tier-2 locales without PO files remain Chinese Simplified
     (`zh_CN`), Arabic (`ar`), and Bengali (`bn_BD`). Add them deliberately from
     the manifest workflow rather than treating their missing files as ordinary
     active-locale regressions.
   - Expand `[ll_language_switcher]` display modes for larger tier-2 language
     sets, including compact icon/button triggers and a dropdown or modal list.

4. Formalize a generic bulk-operation job framework.
   - Existing REST automation works well as a control plane for reports, dry
     runs, bounded writes, and import job polling, while WP-CLI works well for
     trusted server-side maintenance.
   - The next step is a reusable job pattern for heavy live operations where
     Codex may only have WordPress credentials but the work should still run in
     durable server-owned chunks.
   - A generic framework should record operation name, scope, caller/lease
     context, input manifest hash or idempotency key, cursor, progress counts,
     recent errors, per-row results, and final readback hints.
   - Use it for future workflows that touch hundreds of rows and perform
     expensive validation, media handling, taxonomy repair, cache rebuilding, or
     cross-post recomputation instead of adding one long synchronous REST
     request per operation.

## Lower Priority

No additional low-priority code item is carried forward from this audit. Add
new entries only when measured behavior or a concrete compatibility decision
justifies them.
