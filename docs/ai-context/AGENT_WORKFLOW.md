# AI Context Agent Workflow

Use this workflow when the repo feels too large to inspect directly.

## Whole-repository reviews

1. Record the branch, reviewed HEAD and existing working-tree changes. Preserve
   the current request's edit boundary: documentation-only work can correct
   docs without implementing discovered code defects.
2. Use [codebase-map.md](codebase-map.md) as the subsystem checklist and
   `git ls-files` as the tracked inventory. Review each runtime area, its
   focused tests, release/test tooling and first-party native builder. Override
   normal pack exclusions for explicitly requested areas.
3. Distinguish an inventory/source-contract scan from deeper behavior tracing
   and a reproduced issue. Record remaining coverage limits, including upstream
   code, generated catalogs, binaries, provider integration and live sites.
4. Trace each candidate through callers, capability/scope guards, cache keys,
   failure handling and current tests. A `posts_per_page => -1` match alone is
   not a finding when the candidate set is bounded or the operation is an
   intentional maintenance batch. A batch lock must also cover checkpoint
   persistence, not just row mutation, to serialize job advancement.
5. If findings must stay local, maintain
   `docs/CODEBASE_REVIEW_FOLLOWUPS.local.md` (ignored by the root `.gitignore`).
   Use stable IDs, priority, status, reviewed HEAD/date, evidence, trigger,
   impact, confidence, proposed work and a regression/acceptance check. Verify
   with `git check-ignore -v docs/CODEBASE_REVIEW_FOLLOWUPS.local.md` before
   staging documentation. Reproducers/logs belong under ignored
   `test-results/codebase-review/`.
6. Rank verified exposure/data-loss defects before correctness, boundedness,
   UX and maintenance work. Separate current bugs, hypotheses needing a
   reproduction, known design limits, and completed documentation corrections.
   Recheck old backlog entries instead of copying their status into a new list.

Updating documentation or recording a proposed fix is not permission to apply
that fix. Live checks and provider calls are separate from local review.

## First Pass

1. Ask the local router for a starting pack:

   ```bash
   php scripts/build-ai-context-pack.php --suggest-pack "short task description"
   ```

2. If the owner is still unclear, read `docs/ai-context/task-router.md` and
   generate the repo-wide activity report:

   ```bash
   php scripts/build-ai-context-pack.php --activity-report --output -
   ```

3. Generate a manifest for the selected pack:

   ```bash
   php scripts/build-ai-context-pack.php --pack <pack> --manifest-only
   ```

4. Read the hot/warm files from the change-frequency section before quiet files,
   unless the route, shortcode, test, or invariant points elsewhere.
5. Apply `docs/ai-context/AI_IGNORE.md` so generated, vendor, or builder-only
   paths do not crowd out the owning source files.
6. Generate full excerpts only after the owner is clear:

   ```bash
   php scripts/build-ai-context-pack.php --pack <pack> --output -
   ```

## Feedback Loop

Update the context-pack system in the same change when any of these are true:

- A feature adds, removes, or renames a public route, shortcode, AJAX action,
  REST route, template, localized JS global, or major admin surface.
- A bug fix reveals that multiple surfaces had copied the same rule and the fix
  moves that rule into a shared helper.
- A focused test becomes the canonical guard for a surface but the pack does not
  include it.
- A performance fix identifies a new hot path, unbounded load pattern, cache
  contract, or benchmark scenario.
- A task was hard to route because the router lacked the user's vocabulary for
  the affected surface or `--suggest-pack` ranked the wrong pack first.

Prefer small updates:

- Add or adjust one task-router row.
- Add a source or test glob to one pack definition.
- Add one invariant that would have prevented the mistake.
- Add one ignore/downrank note for generated or low-signal files.

## Verification

For context-pack changes, run the narrowest relevant checks:

```bash
php scripts/build-ai-context-pack.php --pack <pack> --manifest-only --check
php scripts/build-ai-context-pack.php --pack <pack> --format json --manifest-only --output -
php scripts/build-ai-context-pack.php --suggest-pack "dictionary cache locale bug"
php scripts/build-ai-context-pack.php --activity-report --format json --output -
```

When docs or pack definitions change, also run the maintenance contract test if
the local Playwright environment is available:

```bash
cd tests/e2e
node node_modules/@playwright/test/cli.js test specs/maintenance-doc-contracts.spec.js --reporter=line
```

This spec reads local files and exercises CLI fixtures; it does not request a
browser/page fixture or contact the WordPress site. Calling the installed CLI
directly avoids the normal E2E wrapper's HTTP readiness request for this
filesystem-only check. In PowerShell, `npm.cmd`/`npx.cmd` are alternatives when
execution policy blocks their `.ps1` shims. For actual WordPress/browser flows,
keep using the runtime-aware wrappers in `tests/README.md`.

The generated packs are local artifacts under `test-results/ai-context/`; do not
commit them unless there is a specific review reason.
