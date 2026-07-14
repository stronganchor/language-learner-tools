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
| Quiz pages, flashcards, practice/learning/listening modes, option labels, embed routes, durable catalog warmup | `public-quiz-flashcards` | `flashcard_widget`, `ll_get_words_by_category`, `flashcard bootstrap`, `llToolsFlashcardsData`, `llToolsFlashcardsMessages`, `word-option-rules`, `quiz-page-template`, `quiz catalog`, `catalog warmup`, `ll_quiz_pages_catalog_warmup` |
| Wordset landing pages, sparse category shells, staff pending-transcription search, word grid, editor rows, vocab lesson shells/cards, recorder queue overview/settings | `wordset-vocab-manager` | `wordset-pages`, `wordset-editor`, `word-grid`, `vocab-lesson`, `recording_text`, `data-ll-expected-card-count`, `sparse category registry`, `ID-only lazy shell`, `advanced settings summary`, `saved progress sort`, `saved recent sort`, `summaryCountsDeferred`, `ll_tools_wordset_page_get_server_main_sort`, `recorder queue summary`, `recorder summary generation`, `ll_tools_wordset_page_get_recorder_queue_summary_generation`, `ll_tools_recorder_queue_summaries`, `ll_tools_wordset_recorder_queue_summaries`, `queue_cursor`, `cursor_rebased`, `reset_queue`, `continuation_unavailable`, `page_items`, `is_continuation`, `has_more`, `pendingCategoryQueuePageRequest`, `lazy`, `paged` |
| Recording interface, audio upload/processing, media matching, IPA/transcription manager | `recording-media-transcription` | `audio_recording_interface`, `ipa-keyboard`, `recording_type`, `review_note`, `ll-matching` |
| Automation REST, site sync, imports/exports, CLI support, live apply/readback flows | `automation-import-sync` | `automation-rest`, `site-sync`, `export-import`, `ensure_sync_ids`, `snapshot` |
| Dictionary search/browser, public cache, language switcher, public i18n manifests | `dictionary-i18n-cache` | `dictionary-search-index`, `dictionary-browser`, `public-static-cache`, `tier2-public-ui-sources`, `language-switcher` |
| AI crawler discovery, llms.txt, agent Markdown/JSON-LD exports, dictionary letter chunks, crawler notes, WebMCP annotations | `dictionary-i18n-cache` | `ai-crawler-support`, `llms.txt`, `Schema.org`, `dictionary-browser`, `content-lesson`, `wordset-pages`, `WebMCP` |
| Offline export/sync/session authentication, wordset games, progress/study metrics, content lessons, teacher classes | `offline-games-content-progress` | `offline-app-sync`, `ll_tools_offline_sessions`, `LL_TOOLS_OFFLINE_APP_MAX_SESSIONS`, `secret_hash`, `legacy sessions`, `wordset-games`, `user-progress`, `content-lesson`, `teacher-classes` |
| Wordset-isolation migration, category ownership/remapping, migration retry/status/CLI, durable generated-page reconciliation | `core-runtime-data-model` | `wordset-isolation`, `LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION`, `LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION`, `LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK`, `LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION`, `LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META`, `LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META`, `wordset-isolation-migrate`, `allow-large-option-rules`, `ll_tools_wordset_isolation_continue_migration`, `ll_tools_begin_deferred_category_maintenance`, `ll_tools_schedule_quiz_page_full_sync`, `ll_tools_schedule_vocab_lesson_full_sync` |
| Performance fixtures, benchmark scenarios, page-speed budgets, large-wordset evidence, stored-fixture transport, expired transient or wp_options cache cleanup | `performance-benchmark` | `PERFORMANCE_ARCHITECTURE`, `LL_PERF_PROFILE`, `LL_PERF_SKIP_SEED`, `canonical-json-v1`, `LL_E2E_PERF_CONFIG_LOCKED`, `verify-performance-manifest`, `stored fixture JSON`, `performance-history`, `page-speed`, `large-wordset`, `expired-transient-maintenance` |

## Aliases

The generator supports these shorter names:

- `wordset-page`, `wordset-editor`, `word-grid` -> `wordset-vocab-manager`
- `transcription-manager` -> `recording-media-transcription`
- `dictionary` -> `dictionary-i18n-cache`
- `llms.txt`, `ai-crawler`, `JSON-LD`, `Schema.org`, `WebMCP` -> `dictionary-i18n-cache`
- `imports-sync` -> `automation-import-sync`

## When No Row Fits

1. Read `CODEBASE_ARCHITECTURE.md` for entry points and invariants.
2. Use `rg` on route names, shortcodes, AJAX actions, localized JS globals, or
   visible UI strings from the user's report.
3. Generate the nearest context pack once you identify a surface.
4. Update this router if the missing route would help the next agent.
