# AI Context Agent Workflow

Use this workflow when the repo feels too large to inspect directly.

## First Pass

1. Match the task to a pack in `docs/ai-context/task-router.md`.
2. Generate a manifest first:

   ```bash
   php scripts/build-ai-context-pack.php --pack <pack> --manifest-only
   ```

3. Read the hot/warm files from the change-frequency section before quiet files,
   unless the route, shortcode, test, or invariant points elsewhere.
4. Apply `docs/ai-context/AI_IGNORE.md` so generated, vendor, or builder-only
   paths do not crowd out the owning source files.
5. Generate full excerpts only after the owner is clear:

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
  the affected surface.

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
```

When docs or pack definitions change, also run the maintenance contract test if
the local Playwright environment is available:

```bash
cd tests/e2e
npx playwright test maintenance-doc-contracts.spec.js --grep "AI context"
```

The generated packs are local artifacts under `test-results/ai-context/`; do not
commit them unless there is a specific review reason.
