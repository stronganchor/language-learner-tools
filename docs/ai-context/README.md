# AI Context Packs

Context packs are local generated markdown files that collect the source and
test files for one workflow. They are meant to help a human or local agent build
the right mental model without sending the entire plugin to an external API.

Generate them with:

```bash
php scripts/build-ai-context-pack.php --list
php scripts/build-ai-context-pack.php --pack wordset-vocab-manager
php scripts/build-ai-context-pack.php --pack performance-benchmark --output -
php scripts/build-ai-context-pack.php --pack performance-benchmark --changed-only --manifest-only
php scripts/build-ai-context-pack.php --all --format both
```

Default output is `test-results/ai-context/<pack>-context.md`, which is ignored
by git. Use `--output -` to print one pack to stdout, or `--output path/to/file.md`
for a specific local artifact. Use `--max-chars 0` when a full uncapped pack is
needed.

Each markdown pack includes YAML front matter, a source index, detected anchors
such as functions/routes/shortcodes/tests, focused test paths, missing pattern
notes, and bounded excerpts. Use `--format json` for machine-readable metadata
or `--format both` to write a markdown pack plus JSON sidecar.

Useful options:

```bash
--max-chars 120000
--max-file-chars 12000
--excerpt-lines 80
--manifest-only
--changed-only
--include-untracked
--check
```

Use `--changed-only` to narrow a workflow pack to files changed from `HEAD`.
Add `--include-untracked` when new files should be included too.
Use `--check` when tightening a specific pack contract; some broader packs
include optional glob patterns that may not exist in every checkout.

Available packs:

| Pack | Use when investigating |
| --- | --- |
| `core-runtime-data-model` | Bootstrap, assets, templates, post types, taxonomies, roles, and wordset isolation. |
| `public-quiz-flashcards` | Public quiz pages, flashcard payloads, shell rendering, and practice/listening flows. |
| `wordset-vocab-manager` | Wordset pages, lazy cards, search, editor/settings UI, vocab lessons, and word grid. |
| `recording-media-transcription` | Audio recording, media admin/imports, IPA/transcription manager, matching, and media helpers. |
| `automation-import-sync` | Automation REST, imports/exports, CLI helpers, site sync, and server-owned bulk jobs. |
| `dictionary-i18n-cache` | Dictionary search/browser, public i18n, language switcher, and static cache behavior. |
| `offline-games-content-progress` | Offline app export/sync, wordset games, user progress, content lessons, interlinear content, and classes. |
| `performance-benchmark` | Benchmark fixtures, seeding, Playwright scenarios, and benchmark history behavior. |

Convenience aliases are supported for older/narrower names: `wordset-page`,
`wordset-editor`, `word-grid`, `transcription-manager`, `dictionary`, and
`imports-sync`.

The generated output is not a substitute for reading the current source before
editing. Treat it as a map, then verify any proposed change against the live
files and focused tests.
