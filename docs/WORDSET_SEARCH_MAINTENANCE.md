# Wordset category-search maintenance

The durable category-search table is maintained in
`includes/lib/wordset-category-search-index.php`. Single-word projection and
mutation handling live in `wordset-category-search-incremental.php`; the dedicated
worker is `wordset-category-search-worker.php`.

## Visitor traffic and the managed worker

The default remains compatible with installations that rely on ordinary
WP-Cron: a search may advance one bounded rebuild batch. A managed site can
instead install a server schedule for:

```sh
wp ll-tools wordset-search-worker --max-seconds=10 --max-batches=20
```

Then enable `ll_tools_wordset_category_search_background_only` with `wp option
update ll_tools_wordset_category_search_background_only 1`. Install and verify
the schedule first. The setting is per site and is not enabled automatically by
an update. With it enabled, public search neither builds index rows nor schedules
maintenance, including on a cold index. Content mutation hooks own scheduling.
Search preserves its preparation/retry response while a build is incomplete.
Existing nonce, scope token, allowed-category, cache-build-lock, and atomic
cache-miss rate limits remain in place.

More bot searches cannot increase this scheduled indexing budget. They can still
consume ordinary request capacity; application limits and the site's existing
network protections remain relevant.

The CLI processes only rebuild and scheduling-sweep events, independently of
unrelated overdue cron hooks. It can consume one- or two-second rebuild
continuations in the same invocation, but respects transient failure backoff.
Each invocation is limited to 20 seconds and 100 batches at most; defaults are
10 seconds and 20 batches. The time budget is checked between bounded batches,
so the server must also impose an outer process timeout and use its existing
global/site cron locks. Apply current server-load and target-pool health gates.
Keep events scheduled while executing them and atomically replace the consumed
event with its continuation, so process termination leaves a retryable checkpoint.
The CLI reports bounded error codes and exits nonzero on maintenance failure. Expired leases recover through generation rotation.

Global scheduling sweeps retain their own bounded wordset-ID pages and delayed
continuations. Completed rebuilds retain bounded old-generation cleanup. A
deterministic terminal failure needs investigation; it is not retried in a loop.

## Ordinary edits

A published word title, current or legacy translation, publication status, post
type, or direct category/wordset membership change first advances the existing
source epoch and invalidates search-result caches. When all affected scopes are
known, at most ten wordsets can be updated incrementally in one mutation. A warm,
unlocked, completed index is eligible. The updater:

1. Records the exact old generation and signature and derives exactly one
   successor epoch, so it cannot adopt an unrelated concurrent edit.
2. Takes the existing exact-owner lease and rechecks signature and generation.
3. Reads only the changed word ID and its capped category relationships.
4. Replaces only that word's rows in that wordset and generation.
5. Publishes the new signature only after successful writes and fresh database
   epoch checks. Readers reject held leases and stale signatures.

Full and incremental indexing share normalization, text caps, translation
fallback, and flat category projection. Hard deletion captures its old scope
before deletion and removes rows after core's `deleted_post` boundary. All
other words and the completed generation stay unchanged on successful updates.
`processed` remains the original full-build scan count; `incremental_updates`
and `last_incremental_at` describe later updates, not a live source-word count.

Cold builds, contention, incomplete/oversized scopes, failed row writes, stale
leases and concurrent source changes fall back to the already queued bounded
rebuild. This remains safe for nontransactional storage: partial replacements
cannot publish a matching signature. Category deletion and unknown scopes retain
their existing bounded maintenance paths. Audio/review-only edits do not
invalidate this index.

## Verification and recovery

- `tests/Integration/WordsetCategorySearchMaintenanceTest.php`: one-word edits,
  moves, publication/type/deletion, failed writes, concurrent edits, fresh epoch
  reads, traffic-independent work, worker budgets and retry behavior.
- `tests/Integration/WordsetPageCategorySearchIndexTest.php`: canonical matching,
  source-scope invalidation, schema and generation fencing, bounded SQL, AJAX
  access and atomic miss limits.
- `tests/e2e/specs/wordset-page-category-search.spec.js`: preparation/retry,
  cancellation, matching and error presentation.

Verify a cold index advances with no search requests, then completes with
`status=completed`, matching `generation/published_generation` and a fresh source
signature. Verify a normal edit changes only its own rows and keeps that
generation ready. Keep the server schedule, installed package hashes, target
health, rollback paths and live readback in the site's deployment notes.

To restore foreground fallback, delete the background-only option before
disabling the independent schedule. Do not clear source epochs or publish a
partial generation to silence a preparation message.
