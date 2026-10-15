# Public aggregate maintenance

Some public pages need materialized data whose cold generation takes several
bounded batches: dictionary lookup/browse rows, exact wordset-button lesson
counts, and recorder category summaries. A shared fleet cron runner can leave
these jobs behind unrelated overdue work even when the source/cache builders
themselves are healthy.

Managed sites can install an independent server schedule for:

```sh
wp ll-tools public-aggregate-worker --max-seconds=8 --max-batches=6
```

`includes/lib/public-aggregate-worker.php` directly calls only the three known
builders. It does not invoke arbitrary WP-Cron hooks, send messages, or run
recurring events. Each invocation gives every due materialization family one
turn before another batch from the same family. Progress continuations can run
in the same invocation; dictionary failures and count-source backoff remain
delayed. The defaults are eight seconds and six batches; arguments are clamped
to 1–20 seconds and 1–100 batches.

The time budget is checked between batches. Install an outer process timeout,
use the site's existing cron lock shared with its ordinary runner, and retain
the server's load/pool health gates. This schedule is a per-site operational
choice and is never installed automatically by the plugin update.

The currently executing single event stays scheduled throughout its callback.
Its own attempts to unschedule that event are blocked; its typed continuation
is written atomically with retirement of the consumed event. A process killed
before checkpoint leaves the original work retryable. A failed database read
does not mean completion, deleted scope, or zero results. Compare-and-swap
checkpointing preserves concurrent unrelated events and source changes.

Dictionary batches require a verified persisted rebuild state before retiring
their event. Counts retain their exact wordset list and viewer identity and
respect the existing raw-word/prompt budgets and backoff. Recorder callbacks
return the exact next offset/targeted slug scope; the worker uses the recorder's
stored public locale and bundled translations, then restores the CLI locale.
This matters because translated category labels participate in recorder catalog
generation. Proven deleted recorder scopes are retired; uncertain reads retry.
Recorder continuations also retain their no-progress attempt count and progress
witness. Both ordinary cron and the dedicated worker use the same retry delay;
the worker accepts the original seven-field event and its nine-field backoff
continuation, and checkpoints the latter without dropping its retry state.

`tests/Integration/PublicAggregateWorkerTest.php` is the focused guard for
allowlisting, budgets, family fairness, held dictionary leases, callback
interruption, failed reads/checkpoints, recorder backoff continuations, locale
restoration and racing cron writes.
Keep source-specific completeness tests in `DictionaryBrowseLookupTest`,
`WordsetButtonsShortcodeTest`, and `WordsetRecorderQueueOverviewResourceTest`.

After installing a schedule, verify the command under the site's normal owner
and PHP runtime, observe cursor progression without visiting the public page,
and confirm that completed state is reused by the public response. Preserve
ordinary cron and unrelated site configuration.
