# AI Context Ignore And Downrank Policy

Use this with `task-router.md` and generated context packs. The goal is not to
hide code from agents; it is to keep first-pass context focused on files likely
to own the requested behavior.

## Usually Skip On First Pass

These paths are excluded from generated context packs unless a task explicitly
points at them:

- `vendor/`: third-party dependencies. Inspect only for dependency behavior or
  update work.
- `offline-app-builder/`: native/offline APK build tooling. Inspect only for
  offline builder or packaged app work.
- `node_modules/`, `tests/e2e/node_modules/`: installed dependencies.
- `test-results/`, `playwright-report/`, `blob-report/`: generated outputs.
- Binary or generated artifacts such as `.mo`, `.l10n.php`, images, audio,
  zip files, PDFs, SQLite files, and database files.

## Downrank Unless Relevant

These files can be useful, but they should not be the first place to look for a
normal feature or bug fix:

- Locale outputs: prefer `.po`, `languages/tier2-public-ui-sources.php`, and
  i18n scripts over generated translation outputs.
- Broad docs: use `CODEBASE_ARCHITECTURE.md` as a map, then move quickly to the
  owning source files and focused tests.
- Large fixtures and benchmark history: inspect only when the task is about
  performance evidence, fixture shape, or regression history.
- Historical migration or cleanup tools: inspect when a task touches their
  specific data path, not for current public behavior by default.

## Override Rules

Always override the skip/downrank list when the user asks about that surface or
when evidence points there. Examples:

- Offline export or APK packaging can require `offline-app-builder/`.
- Release packaging can require checking what excludes `vendor/`, tests, or
  builder-only files from the zip.
- Locale build failures can require generated locale outputs as verification
  artifacts after source `.po` files are updated.

Quiet files from the generated change-frequency signal are not off-limits. Treat
them as lower-probability candidates until a route, helper, test, or invariant
shows they own the behavior.
