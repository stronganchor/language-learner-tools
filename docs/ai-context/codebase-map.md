# Codebase navigation map

Use this as the short source-to-test index. Follow the owning implementation,
then its tests; filenames and symbols below are search anchors, not a promise
that a historical line number or test count is still current. The detailed
[architecture](../../CODEBASE_ARCHITECTURE.md) remains canonical for invariants,
data definitions, template order, and direct bootstrap includes.

## Choose the right reference

| Need | Reference |
| --- | --- |
| User-facing features, installation, shortcode examples | [README](../../README.md) |
| Route a report to a bounded source/test context pack | [Task router](task-router.md), [pack commands](README.md) |
| Runtime lifecycle, CPTs/taxonomies, routes, cache and privacy invariants | [Architecture](../../CODEBASE_ARCHITECTURE.md) |
| Test selection, fixtures, runtime setup and troubleshooting | [Testing playbook](../../tests/AI_TESTING_PLAYBOOK.md), [test setup](../../tests/README.md) |
| Hot paths, growth dimensions, budgets and benchmark profiles | [Performance architecture](../PERFORMANCE_ARCHITECTURE.md), [benchmark runbook](../../tests/performance/README.md) |
| Authenticated HTTP operations, job workflow and response contracts | [REST automation](../REST_AUTOMATION.md) |
| Trusted server-side maintenance, dry runs and resume files | [CLI automation](../CLI_AUTOMATION.md) |
| Dictionary/corpus cleanup and metadata planning | [Data cleanup](../AI_DATA_CLEANUP.md) |
| Legacy lesson/prerequisite conversion | [Migration runbook](../LEGACY_LESSON_MIGRATION_RUNBOOK.md) |
| Public crawler discovery and machine-readable exports | [Crawler support](../AI_CRAWLER_SUPPORT.md) |
| Implemented native LMS vs future provider integration | [LMS plan and contracts](../LMS_INTEGRATION_PLAN.md), [Classroom setup](../GOOGLE_CLASSROOM_SETUP.md) |
| Public/core locale coverage, source policy and wording | [Translation manifest](../../languages/PUBLIC_UI_TRANSLATION_MANIFEST.md), [public guidelines](../../languages/PUBLIC_UI_TRANSLATION_GUIDELINES.md), [Turkish](../../languages/TURKISH_TRANSLATION_GUIDELINES.md), [German](../../languages/GERMAN_TRANSLATION_GUIDELINES.md) |
| Stable ZIP contents and branch/version publication gates | [Releasing](../../RELEASING.md) |
| Native APK builder and upstream ownership | [Builder README](../../offline-app-builder/README.md), [provenance](../../offline-app-builder/UPSTREAM_PROVENANCE.md) |

## Runtime and data ownership

Source paths are relative to the repository root. Test basenames refer to
`tests/Integration/`; browser specs refer to `tests/e2e/specs/`. Use the router
for complete context-pack manifests; this table deliberately lists entry points
and representative guards rather than every file.

| Surface | Owning source and useful symbols | Start with these tests |
| --- | --- | --- |
| Activation, compatibility, update channels | `language-learner-tools.php`; `LL_TOOLS_VERSION`, `ll_tools_should_boot_update_checker` | `PhpCompatibilityTest.php`, `PluginUpdateUiTest.php`, `ReleasePluginScriptTest.php` |
| Module loading, shared assets, theme overrides | `includes/bootstrap.php`, `includes/assets.php`, `includes/template-loader.php`; `ll_tools_should_load_admin_modules`, `ll_enqueue_asset_by_timestamp`, `ll_tools_locate_template` | `AssetEnqueueTest.php`, `TemplateLoaderTest.php`, `maintenance-doc-contracts.spec.js` |
| Deferred schema and transient maintenance | `includes/lib/schema-maintenance.php`, `includes/lib/expired-transient-maintenance.php`; callback allowlist, exact-owner leases, cron continuations | `SchemaMaintenanceAdmissionTest.php`, `ExpiredTransientMaintenanceTest.php`, `MultisiteRegistrationAndMaintenanceTest.php` |
| Login/registration, roles, private wordsets | `includes/login-window.php`, `includes/lib/learner-registration-settings.php`, `includes/lib/public-ajax-resource-guards.php`, `includes/user-roles/`, `includes/taxonomies/wordset-taxonomy.php` | `LoginWindowLoginTest.php`, `LoginWindowRegistrationTest.php`, `CategoryPrivacyAccessTest.php`, `AdminToolCapabilityTest.php`, `private-wordset-access-wp.spec.js` |
| Words/audio/images, categories, option rules | `includes/post-types/`, `includes/taxonomies/`, `includes/lib/word-option-rules.php`; `ll_tools_get_category_quiz_config`, `ll_tools_quiz_requires_audio` | `WordCategoryCountHelperRegressionTest.php`, `SpecificWrongAnswersPayloadTest.php`, `PromptCardQuizPayloadTest.php` |
| Wordset ownership/isolation and generated-page reconciliation | `includes/wordset-isolation.php`, `includes/pages/quiz-pages.php`, `includes/pages/vocab-lesson-pages.php`; migration state, scoped epochs, deferred full sync | `WordsetIsolationMigrationTest.php`, `QuizPagesScopedContentEpochTest.php`, `VocabLessonReconciliationJobTest.php` |
| Quiz/embed routes and shell | `includes/pages/quiz-pages.php`, `includes/pages/embed-page.php`, `includes/flashcard-shell.php`, `includes/shortcodes/flashcard-widget.php`, `templates/quiz-page-template.php` | `FlashcardShellRendererTest.php`, `FlashcardWidgetFlowTest.php`, `quiz-popup-open-close.spec.js` |
| Quiz payloads, selection and modes | `includes/lib/flashcard-payload-materializer.php`, `includes/taxonomies/word-category-taxonomy.php`, `js/flashcard-widget/{main,loader,selection,cards}.js`, `js/flashcard-widget/modes/` | `FlashcardPayloadMaterializerTest.php`, `LargeWordsetQuizHotPathBoundedTest.php`, `quiz-mode-transitions.spec.js`, `flashcard-loader-wordset-isolation.spec.js` |
| Wordset home, settings, search and buttons | `includes/pages/wordset-pages.php`, `js/wordset-pages.js`, `includes/lib/wordset-category-search-index.php`, `includes/shortcodes/wordset-buttons-shortcode.php` | `WordsetPageDeferredStudyCatalogResourceTest.php`, `WordsetPageLazyCardsAjaxTest.php`, `WordsetButtonsShortcodeTest.php`, `WordsetSettingsCustomUiTest.php` |
| Manual category order and first-lesson dates | `includes/taxonomies/wordset-taxonomy.php`; `ll_tools_wordset_get_category_manual_order`, `ll_tools_wordset_get_vocab_lesson_category_created_timestamps` | `WordsetManualOrderingResourceTest.php`, `WordsetCategoryOrderingAtomicSaveTest.php` |
| Vocab lesson/grid, manager edits, bounded rank tables | `includes/pages/vocab-lesson-pages.php`, `includes/shortcodes/word-grid-shortcode.php`, `includes/lib/word-grid-bulk-operations.php`, `includes/shortcodes/ranked-word-list-shortcode.php`, `js/word-grid.js` | `VocabLessonDeferredGridTest.php`, `WordGridBulkEditStateTest.php`, `RankedWordListShortcodeTest.php`, `vocab-lesson-bulk-editor-mobile.spec.js` |
| Recorder, upload, processing, transcription | `includes/shortcodes/audio-recording-shortcode.php`, `includes/admin/uploads/`, `includes/admin/audio-processor-admin.php`, `includes/admin/ipa-keyboard-admin.php`, `js/audio-recorder.js` | `AudioUploadMetadataPreservationTest.php`, `IpaKeyboardAdminAjaxTest.php`, `SecurityHardeningRegressionTest.php`, `audio-processor-delete-all.spec.js` |
| Image matching, optimization and delivery | `includes/lib/{ll-matching,image-match-index,media-proxy}.php`, `includes/admin/{audio-image-matcher,image-webp-optimizer-admin,image-aspect-normalizer-admin}.php` | `AudioUploadImageMatchIndexTest.php`, `MediaProxyFallbackCacheTest.php`, `ImageWebpOptimizerAdminTest.php`, `image-webp-optimizer-mutations.spec.js` |
| REST, metadata plans, imports/undo and site sync | `includes/api/{automation-rest,word-metadata-plan-rest}.php`, `includes/admin/export-import.php`, `includes/lib/site-sync.php`, `includes/cli/`, `bin/` | `AutomationRestApiTest.php`, `MetadataUpdateBatchJobTest.php`, `AdminImportAjaxJobFlowTest.php`, `ImportHistoryUndoTest.php`, `SiteSyncTest.php`, `site-sync-admin-orchestration.spec.js` |
| Mutation ownership, durable checkpoints and recovery | `includes/lib/mutation-job-state.php`, `includes/admin/export-import.php`, `includes/api/word-metadata-plan-rest.php`, `js/export-import-admin.js` | `MutationJobReliabilityTest.php`, `import-job-recovery.spec.js` |
| CLI resume identity and frozen target selection | `includes/cli/cli-support.php`, `includes/cli/class-ll-tools-cli-command.php`; `ll_tools_cli_bind_resume_plan`, `ll_tools_cli_resume_select_rows` | `CliResumePlanTest.php` |
| Dictionary, static HTML cache, crawler exports | `includes/lib/dictionary-*.php`, `includes/lib/public-static-cache.php`, `includes/lib/ai-crawler-support.php`, `includes/shortcodes/dictionary-shortcode.php` | `DictionaryFeatureTest.php`, `PublicStaticCacheTest.php`, `AiCrawlerSupportTest.php`, `dictionary-shortcode-deferred-toolbar.spec.js` |
| Locale selection, strings and generated catalogs | `includes/i18n/language-switcher.php`, `languages/tier2-public-ui-sources.php`, `scripts/check-{i18n-source-pot,public-i18n}.php`, `scripts/update-i18n.sh` | `PublicUiTranslationManifestTest.php`, `language-switcher-post.spec.js`, `maintenance-doc-contracts.spec.js` |
| Learner progress, preferences and reporting | `includes/user-progress.php`, `includes/user-study.php`, `includes/user-progress-report-data.php`, `js/flashcard-widget/progress-tracker.js` | `UserProgressAtomicityTest.php`, `UserProgressEventPayloadGuardTest.php`, `UserProgressPracticeResultTest.php`, `UserProgressReportTest.php` |
| Games, content lessons, corpus review and legacy bridges | `includes/pages/wordset-games.php`, `includes/pages/content-lesson-pages.php`, `includes/content-lesson-progress.php`, `includes/lib/internal-review-notes.php`, `includes/migrations/legacy-content-lessons.php` | `WordsetGamesTest.php`, `ContentLessonProgressTest.php`, `InternalReviewNotesTest.php`, `LegacyContentLessonMigrationTest.php` |
| Teacher classes, assignments, grade delivery, Classroom | `includes/teacher-classes.php`, `includes/lms/`, `includes/api/lms-rest.php`, `includes/admin/google-classroom-integration.php` | `TeacherClassesTest.php`, `LmsAssignmentFoundationTest.php`, `LmsRestApiTest.php`, `LmsGradeDeliveryTest.php`, `GoogleClassroomFoundationTest.php` |
| Privacy lifecycle and offline sync/export | `includes/privacy.php`, `includes/offline-app-sync.php`, `includes/admin/offline-app-export.php`, `offline-app/offline-app.js`, `js/flashcard-widget/progress-tracker.js` | `LmsPrivacyLifecycleTest.php`, `UserProgressRetentionTest.php`, `OfflineAppSyncTest.php`, `OfflineAppExportTest.php`, `offline-app-sync-error-wp.spec.js` |

## Boundaries that filenames can hide

- `includes/admin/` does not mean admin-only. Bootstrap always loads shared
  upload handlers, API integrations, word-option rules, and the example-sentence
  migration worker. Most other admin modules load for admin/WP-CLI/tests.
- `includes/pages/embed-page.php` enters through template routing. The direct
  bootstrap include index is intentionally not a transitive module inventory.
- `includes/api/word-metadata-plan-rest.php` is loaded by `automation-rest.php`.
  Follow delegated callbacks and lower-level writes when reviewing a route.
- `offline-app/offline-app.js` ships in the plugin; `offline-app-builder/` is
  repository-only packaging/native tooling. The offline context pack includes
  the builder README, preparation script, and filesystem hardening tests as
  explicit first-party inputs. Native/vendor code remains outside that pack.
- `vendor/plugin-update-checker/` is a shipped dependency. The large embedded
  Whisper/GGML tree under the builder is upstream code; use its provenance
  record before attributing or changing it. Installed test dependencies and
  generated assets are separate from maintained first-party sources.
- Tests include filesystem-only source contracts, mocked browser tests, local
  WordPress scenarios, and opt-in live/performance suites. Passing one layer
  does not establish another layer's behavior.

## Current references versus review history

[MAINTENANCE_BACKLOG.md](../../MAINTENANCE_BACKLOG.md) contains deliberate
maintenance work and dated verification snapshots. Its dated test counts and
deployment notes are evidence from those runs, not current measurements.

[AUTONOMOUS_CODE_REVIEW_TODO.md](../AUTONOMOUS_CODE_REVIEW_TODO.md) is a historical
overnight review log; June site-specific `STARTERENGLISH_LIVE_*_TODO.md` files
are historical live-check records. Revalidate their unchecked items against
current source before treating them as open defects. Their old cadence and
permission statements do not authorize a new automation, fix or live action.

When a review requests local/private findings, use the gitignored
`docs/CODEBASE_REVIEW_FOLLOWUPS.local.md` and update existing issue IDs on later
passes. See [the review workflow](AGENT_WORKFLOW.md#whole-repository-reviews).
Do not promote its contents into tracked docs unless the task authorizes that.
