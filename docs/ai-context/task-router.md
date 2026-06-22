# AI Context Task Router

Start here when the task is broad or the owner is unclear. Pick the nearest
pack, generate it, then verify against current source before editing.

```bash
php scripts/build-ai-context-pack.php --suggest-pack "short task description"
php scripts/build-ai-context-pack.php --activity-report --output -
php scripts/build-ai-context-pack.php --pack <pack> --manifest-only
php scripts/build-ai-context-pack.php --pack <pack> --output -
```

The generated pack includes git change-frequency signals for each source file.
Use hot/warm files as a scan-order clue, and use quiet files as a reason to
verify ownership before editing.

If the suggested pack looks wrong, update the `signals` for the relevant pack in
`scripts/build-ai-context-pack.php` or add a clearer row below.

| Task signal | Start with pack | Search next |
| --- | --- | --- |
| Bootstrap, loaded modules, constants, assets, template overrides, CPTs, taxonomies, roles | `core-runtime-data-model` | `includes/bootstrap.php`, `ll_enqueue_asset_by_timestamp`, `register_post_type`, `register_taxonomy`, `template-loader` |
| Quiz pages, flashcards, practice/learning/listening modes, option labels, embed routes | `public-quiz-flashcards` | `flashcard_widget`, `ll_get_words_by_category`, `llToolsFlashcardsData`, `word-option-rules`, `quiz-page-template` |
| Wordset landing pages, category shells, search, word grid, editor rows, vocab lesson cards | `wordset-vocab-manager` | `wordset-pages`, `wordset-editor`, `word-grid`, `vocab-lesson`, `lazy`, `paged` |
| Recording interface, audio upload/processing, media matching, IPA/transcription manager | `recording-media-transcription` | `audio_recording_interface`, `ipa-keyboard`, `recording_type`, `review_note`, `ll-matching` |
| Automation REST, site sync, imports/exports, CLI support, live apply/readback flows | `automation-import-sync` | `automation-rest`, `site-sync`, `export-import`, `ensure_sync_ids`, `snapshot` |
| Dictionary search/browser, public cache, language switcher, public i18n manifests | `dictionary-i18n-cache` | `dictionary-search-index`, `dictionary-browser`, `public-static-cache`, `tier2-public-ui-sources`, `language-switcher` |
| Offline export/sync, wordset games, progress/study metrics, content lessons, teacher classes | `offline-games-content-progress` | `offline-app-sync`, `wordset-games`, `user-progress`, `content-lesson`, `teacher-classes` |
| Performance fixtures, benchmark scenarios, page-speed budgets, large-wordset evidence | `performance-benchmark` | `PERFORMANCE_ARCHITECTURE`, `LL_PERF_PROFILE`, `performance-history`, `page-speed`, `large-wordset` |

## Aliases

The generator supports these shorter names:

- `wordset-page`, `wordset-editor`, `word-grid` -> `wordset-vocab-manager`
- `transcription-manager` -> `recording-media-transcription`
- `dictionary` -> `dictionary-i18n-cache`
- `imports-sync` -> `automation-import-sync`

## When No Row Fits

1. Read `CODEBASE_ARCHITECTURE.md` for entry points and invariants.
2. Use `rg` on route names, shortcodes, AJAX actions, localized JS globals, or
   visible UI strings from the user's report.
3. Generate the nearest context pack once you identify a surface.
4. Update this router if the missing route would help the next agent.
