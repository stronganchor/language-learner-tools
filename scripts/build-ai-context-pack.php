<?php
/**
 * Builds local, bounded workflow context packs for LL Tools analysis.
 *
 * Examples:
 *   php scripts/build-ai-context-pack.php --list
 *   php scripts/build-ai-context-pack.php --pack wordset-vocab-manager
 *   php scripts/build-ai-context-pack.php --pack performance-benchmark --output -
 *   php scripts/build-ai-context-pack.php --all --format both
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = getopt('', [
    'help',
    'list',
    'pack:',
    'all',
    'output:',
    'format:',
    'json',
    'max-chars:',
    'max-file-chars:',
    'excerpt-lines:',
    'history-months:',
    'max-change-files:',
    'manifest-only',
    'changed-only',
    'include-untracked',
    'check',
]);

$packs = ll_tools_context_pack_definitions();
$aliases = [
    'wordset-page' => 'wordset-vocab-manager',
    'wordset-editor' => 'wordset-vocab-manager',
    'word-grid' => 'wordset-vocab-manager',
    'transcription-manager' => 'recording-media-transcription',
    'dictionary' => 'dictionary-i18n-cache',
    'imports-sync' => 'automation-import-sync',
];

if (isset($options['help'])) {
    ll_tools_context_pack_print_usage();
    exit(0);
}

if (isset($options['list'])) {
    foreach ($packs as $name => $pack) {
        echo $name . ' - ' . $pack['description'] . PHP_EOL;
    }
    if ($aliases) {
        echo PHP_EOL . 'Aliases:' . PHP_EOL;
        foreach ($aliases as $alias => $target) {
            echo $alias . ' -> ' . $target . PHP_EOL;
        }
    }
    exit(0);
}

$format = isset($options['json']) ? 'json' : strtolower((string) ($options['format'] ?? 'markdown'));
if (!in_array($format, ['markdown', 'json', 'both'], true)) {
    fwrite(STDERR, "--format must be markdown, json, or both.\n");
    exit(1);
}

$settings = [
    'max_chars' => max(0, (int) ($options['max-chars'] ?? 120000)),
    'max_file_chars' => max(500, (int) ($options['max-file-chars'] ?? 12000)),
    'excerpt_lines' => max(20, (int) ($options['excerpt-lines'] ?? 80)),
    'history_months' => max(0, (int) ($options['history-months'] ?? 12)),
    'max_change_files' => max(0, (int) ($options['max-change-files'] ?? 12)),
    'manifest_only' => isset($options['manifest-only']),
    'changed_only' => isset($options['changed-only']),
    'include_untracked' => isset($options['include-untracked']),
    'check' => isset($options['check']),
];

$packNames = [];
if (isset($options['all'])) {
    $packNames = array_keys($packs);
} else {
    $packName = (string) ($options['pack'] ?? '');
    $packName = $aliases[$packName] ?? $packName;
    if ($packName === '' || !isset($packs[$packName])) {
        fwrite(STDERR, "Missing or unknown --pack value.\n\n");
        ll_tools_context_pack_print_usage();
        exit(1);
    }
    $packNames = [$packName];
}

$output = (string) ($options['output'] ?? '');
if (count($packNames) > 1 && $output === '-') {
    fwrite(STDERR, "--output - is only supported for a single pack.\n");
    exit(1);
}

$exitCode = 0;
foreach ($packNames as $packName) {
    $packResult = ll_tools_context_pack_build($root, $packName, $packs[$packName], $settings);
    if ($settings['check'] && $packResult['missing']) {
        $exitCode = 1;
    }

    if ($output === '-') {
        echo ll_tools_context_pack_render_for_format($packResult, $format, true);
        continue;
    }

    $target = ll_tools_context_pack_output_target($root, $output, $packName, $format);
    ll_tools_context_pack_write_result($packResult, $target, $format);
}

exit($exitCode);

function ll_tools_context_pack_definitions(): array
{
    return [
        'core-runtime-data-model' => [
            'description' => 'Bootstrap, assets, templates, post types, taxonomies, roles, and wordset isolation.',
            'load_when' => 'A change touches plugin loading, core taxonomies, CPT registration, roles, templates, or source/docs contracts.',
            'invariants' => [
                'Bootstrap include order must match CODEBASE_ARCHITECTURE.md.',
                'Template overrides must respect includes/template-loader.php order.',
                'Asset enqueues should use ll_enqueue_asset_by_timestamp().',
                'Wordset ownership and isolation rules must stay consistent across CPTs and taxonomies.',
            ],
            'sources' => [
                'language-learner-tools.php',
                'includes/bootstrap.php',
                'includes/assets.php',
                'includes/template-loader.php',
                'includes/post-types/*.php',
                'includes/taxonomies/*.php',
                'includes/user-roles/*.php',
                'includes/wordset-isolation.php',
                'includes/wordset-templates.php',
            ],
            'tests' => [
                'tests/Integration/AssetEnqueueTest.php',
                'tests/Integration/TemplateLoaderTest.php',
                'tests/Integration/WordPublishAudioRequirementTest.php',
                'tests/Integration/WordsetIsolationMigrationTest.php',
                'tests/e2e/specs/maintenance-doc-contracts.spec.js',
            ],
        ],
        'public-quiz-flashcards' => [
            'description' => 'Public quiz pages, flashcard payloads, shell rendering, and practice/listening flows.',
            'load_when' => 'A change touches quiz pages, flashcard AJAX payloads, option constraints, listening mode, or public quiz assets.',
            'invariants' => [
                'Do not hydrate all words when a count or bounded candidate pool is enough.',
                'Keep ll_get_words_by_category() payload fields stable for option safety.',
                'Anonymous public surfaces should remain cache-aware and nonce-safe.',
            ],
            'sources' => [
                'includes/shortcodes/flashcard-widget.php',
                'includes/flashcard-shell.php',
                'includes/pages/quiz-pages.php',
                'includes/pages/embed-page.php',
                'includes/shortcodes/quiz-pages-shortcodes.php',
                'includes/taxonomies/word-category-taxonomy.php',
                'templates/flashcard-widget-template.php',
                'templates/quiz-page-template.php',
                'js/flashcard-widget/*.js',
                'js/quiz-pages*.js',
                'css/flashcard/*.css',
                'css/quiz-pages*.css',
            ],
            'tests' => [
                'tests/Integration/Flashcard*Test.php',
                'tests/Integration/QuizPagePostTypeTest.php',
                'tests/Integration/QuizPagesShortcode*Test.php',
                'tests/Integration/PromptCardQuizPayloadTest.php',
                'tests/e2e/specs/quiz-*.spec.js',
                'tests/e2e/specs/flashcard-*.spec.js',
                'tests/e2e/specs/practice-option-constraints.spec.js',
                'tests/e2e/specs/listening-*.spec.js',
            ],
        ],
        'wordset-vocab-manager' => [
            'description' => 'Wordset pages, lazy cards, search, editor/settings UI, vocab lessons, and word grid.',
            'load_when' => 'A change touches public wordset pages, category shells, search, recommendations, editor rows, or vocab lesson cards.',
            'invariants' => [
                'Large wordsets are production data; first paint must stay bounded.',
                'Use shell cards, paged editor rows, ID queries, and lazy hydration before full word/media hydration.',
                'Search and progress summaries should use bounded or materialized data paths.',
            ],
            'sources' => [
                'includes/pages/wordset-pages.php',
                'includes/pages/wordset-editor.php',
                'includes/pages/vocab-lesson-pages.php',
                'includes/shortcodes/wordset-*.php',
                'includes/shortcodes/word-grid-shortcode.php',
                'templates/wordset-page-template.php',
                'templates/vocab-lesson*.php',
                'js/wordset-*.js',
                'js/word-grid.js',
                'js/word-edit-modal.js',
                'js/vocab-lesson*.js',
                'css/wordset-pages.css',
                'css/vocab-lesson*.css',
            ],
            'tests' => [
                'tests/Integration/Wordset*Test.php',
                'tests/Integration/VocabLesson*Test.php',
                'tests/Integration/WordGrid*Test.php',
                'tests/Integration/WordsetPageLazyCardsAjaxTest.php',
                'tests/e2e/specs/wordset-*.spec.js',
                'tests/e2e/specs/vocab-lesson-*.spec.js',
            ],
        ],
        'recording-media-transcription' => [
            'description' => 'Audio recording, media admin/imports, IPA/transcription manager, matching, and media helpers.',
            'load_when' => 'A change touches recordings, audio uploads, transcription rows, IPA matching, or generated media assignment.',
            'invariants' => [
                'Initial admin loads should be paged/lazy; validation can be deeper but must be explicit.',
                'Publishing words may be blocked without published word_audio depending on category config.',
                'Autosave/editing flows should avoid page refreshes after successful saves when practical.',
            ],
            'sources' => [
                'includes/shortcodes/audio-recording-shortcode.php',
                'includes/admin/uploads/*.php',
                'includes/admin/audio-*.php',
                'includes/admin/recording-types-admin.php',
                'includes/admin/prompt-audio-import-admin.php',
                'includes/admin/ipa-keyboard-admin.php',
                'includes/lib/ll-matching.php',
                'includes/lib/audio-originals.php',
                'includes/lib/image-*.php',
                'js/audio-*.js',
                'js/ipa-keyboard-admin.js',
                'css/recording-interface.css',
                'css/audio-*.css',
                'css/ipa-keyboard-admin.css',
            ],
            'tests' => [
                'tests/Integration/Audio*Test.php',
                'tests/Integration/Recording*Test.php',
                'tests/Integration/Ipa*Test.php',
                'tests/Integration/PromptAudioImportAdminTest.php',
                'tests/e2e/specs/audio-*.spec.js',
                'tests/e2e/specs/transcription-manager-*.spec.js',
            ],
        ],
        'automation-import-sync' => [
            'description' => 'Automation REST control plane, imports/exports, CLI helpers, site sync, and bulk jobs.',
            'load_when' => 'A change touches REST automation, import previews, site sync snapshots, remote apply flows, or server-side jobs.',
            'invariants' => [
                'REST should control, enqueue, and report status for heavy work rather than doing unbounded work inline.',
                'Site-sync snapshots must be paged for large wordsets; use ensure_sync_ids=0 and include_media=0 for lightweight read-only inspection.',
                'Admin and REST mutation paths require capability and nonce/auth checks.',
            ],
            'sources' => [
                'includes/api/automation-rest.php',
                'includes/api/word-metadata-plan-rest.php',
                'includes/lib/site-sync.php',
                'includes/admin/export-import.php',
                'includes/admin/site-sync-admin.php',
                'includes/cli/*.php',
                'bin/*.sh',
                'docs/REST_AUTOMATION.md',
                'docs/CLI_AUTOMATION.md',
                'docs/AI_DATA_CLEANUP.md',
                'js/export-import-admin.js',
                'js/site-sync-admin.js',
            ],
            'tests' => [
                'tests/Integration/AutomationRest*Test.php',
                'tests/Integration/SiteSyncTest.php',
                'tests/Integration/AdminImport*Test.php',
                'tests/Integration/Import*Test.php',
                'tests/e2e/specs/admin-import-preview-undo.spec.js',
            ],
        ],
        'dictionary-i18n-cache' => [
            'description' => 'Dictionary search/browser, public i18n, language switcher, and static cache behavior.',
            'load_when' => 'A change touches dictionary search, dictionary cache, locale negotiation, public strings, or language switching.',
            'invariants' => [
                'Public dictionary search should avoid broad postmeta contains scans.',
                'Static cache keys must be deterministic and locale-safe.',
                'User-facing strings must be translation-ready and discoverable by Loco Translate.',
            ],
            'sources' => [
                'includes/post-types/dictionary-entry-post-type.php',
                'includes/lib/dictionary-*.php',
                'includes/lib/public-static-cache.php',
                'includes/lib/entity-translations.php',
                'includes/lib/word-translations.php',
                'includes/pages/dictionary-page.php',
                'includes/shortcodes/dictionary-shortcode.php',
                'includes/i18n/language-switcher.php',
                'includes/shortcodes/language-switcher-shortcode.php',
                'languages/tier2-public-ui-sources.php',
                'scripts/check-public-i18n.php',
                'scripts/update-i18n.sh',
                'js/dictionary-*.js',
                'js/language-switcher.js',
                'css/dictionary-*.css',
                'css/language-switcher.css',
            ],
            'tests' => [
                'tests/Integration/Dictionary*Test.php',
                'tests/Integration/LocalePreferenceTest.php',
                'tests/Integration/PublicStaticCacheTest.php',
                'tests/Integration/PublicUiTranslationManifestTest.php',
                'tests/e2e/specs/dictionary-*.spec.js',
                'tests/e2e/specs/maintenance-doc-contracts.spec.js',
            ],
        ],
        'offline-games-content-progress' => [
            'description' => 'Offline app export/sync, wordset games, user progress, content lessons, interlinear content, and classes.',
            'load_when' => 'A change touches offline bundles, wordset games, progress/study rows, content lessons, or teacher classes.',
            'invariants' => [
                'Game launch and study candidate pools should be capped before hydration.',
                'Offline export can do batch work, but it should be explicit and resumable where possible.',
                'Progress views should prefer aggregate rows and bounded category lookups.',
            ],
            'sources' => [
                'includes/offline-app-sync.php',
                'includes/admin/offline-app-export.php',
                'offline-app/offline-app.js',
                'templates/offline-app-shell-template.php',
                'includes/pages/wordset-games.php',
                'js/wordset-games.js',
                'css/wordset-games.css',
                'includes/user-progress*.php',
                'includes/user-study.php',
                'includes/privacy.php',
                'includes/pages/content-lesson-pages.php',
                'includes/lib/interlinear.php',
                'includes/teacher-classes.php',
                'includes/admin/teacher-classes-page.php',
            ],
            'tests' => [
                'tests/Integration/OfflineApp*Test.php',
                'tests/Integration/WordsetGames*Test.php',
                'tests/Integration/UserProgress*Test.php',
                'tests/Integration/ContentLesson*Test.php',
                'tests/Integration/TeacherClassesTest.php',
                'tests/e2e/specs/offline-*.spec.js',
                'tests/e2e/specs/wordset-games-space-shooter.spec.js',
                'tests/e2e/specs/content-lesson-route-media.spec.js',
                'tests/e2e/specs/teacher-classes-frontend.spec.js',
            ],
        ],
        'performance-benchmark' => [
            'description' => 'Performance fixture manifests, seeding, Playwright scenarios, budgets, and history comparison.',
            'load_when' => 'A change touches performance test data, benchmark scenarios, page-speed budgets, or release-to-release performance evidence.',
            'invariants' => [
                'Default benchmark runs must stay affordable.',
                'Use LL_PERF_PROFILE=xl for thousands-of-words coverage.',
                'History comparisons must only compare compatible fixture shapes and throttle profiles.',
            ],
            'sources' => [
                'docs/PERFORMANCE_ARCHITECTURE.md',
                'tests/performance/README.md',
                'tests/performance/fixtures/performance-wordsets*.json',
                'tests/performance/seed-performance-fixtures.php',
                'tests/bin/run-performance-benchmark.sh',
                'scripts/summarize-performance-history.js',
                'tests/e2e/specs/performance-benchmark.spec.js',
                'tests/e2e/helpers/performance-benchmark.js',
                'tests/e2e/specs/page-speed-throttled-load.spec.js',
                'tests/e2e/specs/wordset-page-speed-large-wordset.spec.js',
            ],
            'tests' => [
                'tests/e2e/specs/performance-benchmark.spec.js',
                'tests/e2e/specs/page-speed-throttled-load.spec.js',
                'tests/e2e/specs/wordset-page-speed-large-wordset.spec.js',
            ],
        ],
    ];
}

function ll_tools_context_pack_print_usage(): void
{
    echo "Usage:\n";
    echo "  php scripts/build-ai-context-pack.php --list\n";
    echo "  php scripts/build-ai-context-pack.php --pack <name> [--output <path|->] [--format markdown|json|both]\n";
    echo "  php scripts/build-ai-context-pack.php --all [--output <directory>] [--format markdown|json|both] [--check]\n";
    echo "Options:\n";
    echo "  --max-chars <n>       Total markdown character budget, default 120000, 0 for uncapped.\n";
    echo "  --max-file-chars <n>  Per-file excerpt budget, default 12000.\n";
    echo "  --excerpt-lines <n>   Max lines per file excerpt window, default 80.\n";
    echo "  --history-months <n>  Git history window for change-frequency hints, default 12, 0 to disable.\n";
    echo "  --max-change-files <n> Max hot/quiet file rows in the frequency summary, default 12.\n";
    echo "  --manifest-only       Write indexes and metadata without source excerpts.\n";
    echo "  --changed-only        Include only tracked files changed from HEAD.\n";
    echo "  --include-untracked   Include untracked files with --changed-only.\n";
    echo "  --check               Exit non-zero when configured source patterns are missing.\n";
}

function ll_tools_context_pack_build(string $root, string $packName, array $pack, array $settings): array
{
    $sourcePatterns = array_merge([
        'AGENTS.md',
        'CODEBASE_ARCHITECTURE.md',
        'docs/PERFORMANCE_ARCHITECTURE.md',
        'docs/ai-context/*.md',
        'tests/AI_TESTING_PLAYBOOK.md',
        'tests/README.md',
    ], $pack['sources']);
    $testPatterns = $pack['tests'];
    $expandedSources = ll_tools_context_pack_expand_patterns($root, $sourcePatterns);
    $expandedTests = ll_tools_context_pack_expand_patterns($root, $testPatterns);
    $files = array_values(array_unique(array_merge($expandedSources['files'], $expandedTests['files'])));
    $changedFiles = [];
    if ($settings['changed_only']) {
        $changedFiles = ll_tools_context_pack_changed_files($root, $settings['include_untracked']);
        $changedLookup = array_fill_keys($changedFiles, true);
        $files = array_values(array_filter($files, static function (string $file) use ($changedLookup): bool {
            return isset($changedLookup[$file]);
        }));
    }

    $changeStats = ll_tools_context_pack_change_frequency($root, $settings['history_months']);
    $sourceRows = [];
    foreach ($files as $file) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $contents = is_file($absolute) ? (string) file_get_contents($absolute) : '';
        $recentChanges = (int) ($changeStats['counts'][$file] ?? 0);
        $sourceRows[] = [
            'path' => $file,
            'bytes' => strlen($contents),
            'lines' => $contents === '' ? 0 : substr_count($contents, "\n") + 1,
            'sha256' => is_file($absolute) ? hash_file('sha256', $absolute) : '',
            'recent_changes' => $recentChanges,
            'change_rank' => $changeStats['ranks'][$file] ?? null,
            'change_band' => $changeStats['bands'][$file] ?? ($recentChanges > 0 ? 'cool' : 'quiet'),
            'anchors' => ll_tools_context_pack_extract_anchors($file, $contents),
        ];
    }

    $missing = array_values(array_unique(array_merge($expandedSources['missing'], $expandedTests['missing'])));
    $metadata = [
        'schema' => 'll-tools-ai-context-pack/v1',
        'pack_id' => $packName,
        'generated_at_gmt' => gmdate('c'),
        'git_head' => ll_tools_context_pack_git($root, ['rev-parse', '--short', 'HEAD']),
        'worktree_status' => ll_tools_context_pack_git($root, ['status', '--short']) === '' ? 'clean' : 'dirty',
        'max_chars' => $settings['max_chars'],
        'max_file_chars' => $settings['max_file_chars'],
        'history_months' => $settings['history_months'],
        'change_frequency_counted_files' => count($changeStats['counts']),
        'changed_only' => $settings['changed_only'] ? 'true' : 'false',
        'include_untracked' => $settings['include_untracked'] ? 'true' : 'false',
        'changed_source_count' => count($changedFiles),
        'source_count' => count($sourceRows),
        'missing_patterns' => $missing,
    ];

    $markdown = ll_tools_context_pack_render_markdown($root, $packName, $pack, $metadata, $sourceRows, $missing, $settings);

    return [
        'metadata' => $metadata,
        'pack' => [
            'description' => $pack['description'],
            'load_when' => $pack['load_when'],
            'invariants' => $pack['invariants'],
        ],
        'sources' => $sourceRows,
        'missing' => $missing,
        'markdown' => $markdown,
    ];
}

function ll_tools_context_pack_expand_patterns(string $root, array $patterns): array
{
    $allFiles = null;
    $files = [];
    $missing = [];

    foreach ($patterns as $pattern) {
        $pattern = str_replace('\\', '/', (string) $pattern);
        if (!ll_tools_context_pack_has_glob($pattern)) {
            if (is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pattern))) {
                $files[] = $pattern;
            } else {
                $missing[] = $pattern;
            }
            continue;
        }

        if ($allFiles === null) {
            $allFiles = ll_tools_context_pack_all_files($root);
        }

        $regex = ll_tools_context_pack_pattern_regex($pattern);
        $matches = array_values(array_filter($allFiles, static function (string $file) use ($regex): bool {
            return preg_match($regex, $file) === 1;
        }));

        if ($matches) {
            array_push($files, ...$matches);
        } else {
            $missing[] = $pattern;
        }
    }

    sort($files);
    sort($missing);

    return [
        'files' => array_values(array_unique($files)),
        'missing' => array_values(array_unique($missing)),
    ];
}

function ll_tools_context_pack_changed_files(string $root, bool $includeUntracked): array
{
    $outputs = [
        ll_tools_context_pack_git($root, ['diff', '--name-only', '--diff-filter=ACMRTUXB', 'HEAD', '--']),
        ll_tools_context_pack_git($root, ['diff', '--cached', '--name-only', '--diff-filter=ACMRTUXB', '--']),
    ];
    if ($includeUntracked) {
        $outputs[] = ll_tools_context_pack_git($root, ['ls-files', '--others', '--exclude-standard']);
    }

    $files = [];
    foreach ($outputs as $output) {
        foreach (preg_split('/\r\n|\r|\n/', trim((string) $output)) as $file) {
            $file = str_replace('\\', '/', trim($file));
            if ($file === '' || ll_tools_context_pack_is_excluded($file)) {
                continue;
            }
            $files[] = $file;
        }
    }

    $files = array_values(array_unique($files));
    sort($files);
    return $files;
}

function ll_tools_context_pack_change_frequency(string $root, int $historyMonths): array
{
    static $cache = [];

    if ($historyMonths <= 0) {
        return [
            'counts' => [],
            'ranks' => [],
            'bands' => [],
        ];
    }

    $cacheKey = $root . '|' . $historyMonths;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $output = ll_tools_context_pack_git($root, [
        'log',
        '--since=' . $historyMonths . ' months ago',
        '--name-only',
        '--pretty=format:',
        '--',
        '.',
    ]);

    $counts = [];
    foreach (preg_split('/\r\n|\r|\n/', trim((string) $output)) as $file) {
        $file = str_replace('\\', '/', trim($file));
        if ($file === '' || ll_tools_context_pack_is_excluded($file)) {
            continue;
        }
        $counts[$file] = ($counts[$file] ?? 0) + 1;
    }

    arsort($counts);
    $rankedFiles = array_keys($counts);
    $rankCount = count($rankedFiles);
    $hotLimit = $rankCount > 0 ? max(20, (int) ceil($rankCount * 0.05)) : 0;
    $warmLimit = $rankCount > 0 ? max(80, (int) ceil($rankCount * 0.20)) : 0;

    $ranks = [];
    $bands = [];
    foreach ($rankedFiles as $index => $file) {
        $rank = $index + 1;
        $ranks[$file] = $rank;
        if ($rank <= $hotLimit) {
            $bands[$file] = 'hot';
        } elseif ($rank <= $warmLimit) {
            $bands[$file] = 'warm';
        } else {
            $bands[$file] = 'cool';
        }
    }

    $cache[$cacheKey] = [
        'counts' => $counts,
        'ranks' => $ranks,
        'bands' => $bands,
    ];

    return $cache[$cacheKey];
}

function ll_tools_context_pack_has_glob(string $pattern): bool
{
    return strpbrk($pattern, '*?[') !== false;
}

function ll_tools_context_pack_all_files(string $root): array
{
    $files = [];
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $current) use ($root): bool {
            if (!$current->isDir()) {
                return true;
            }

            $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($root) + 1));
            return !ll_tools_context_pack_is_excluded_directory($relative);
        }
    );
    $iterator = new RecursiveIteratorIterator($filter);
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($fileInfo->getPathname(), strlen($root) + 1));
        if (ll_tools_context_pack_is_excluded($relative)) {
            continue;
        }
        $files[] = $relative;
    }
    sort($files);
    return $files;
}

function ll_tools_context_pack_is_excluded_directory(string $relative): bool
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '') {
        return false;
    }

    foreach (ll_tools_context_pack_excluded_prefixes() as $prefix) {
        $prefix = trim($prefix, '/');
        if ($relative === $prefix || strpos($relative . '/', $prefix . '/') === 0) {
            return true;
        }
    }

    return false;
}

function ll_tools_context_pack_excluded_prefixes(): array
{
    return [
        '.git/',
        'vendor/',
        'node_modules/',
        'tests/e2e/node_modules/',
        'offline-app-builder/',
        'dist/',
        'test-results/',
        'playwright-report/',
        'blob-report/',
    ];
}

function ll_tools_context_pack_is_excluded(string $relative): bool
{
    foreach (ll_tools_context_pack_excluded_prefixes() as $prefix) {
        if (strpos($relative, $prefix) === 0) {
            return true;
        }
    }

    return preg_match('/\.(mo|l10n\.php|png|jpe?g|gif|webp|mp3|wav|zip|pdf|sqlite|db)$/i', $relative) === 1;
}

function ll_tools_context_pack_pattern_regex(string $pattern): string
{
    $quoted = preg_quote($pattern, '#');
    $quoted = str_replace('\*\*', '.*', $quoted);
    $quoted = str_replace('\*', '[^/]*', $quoted);
    $quoted = str_replace('\?', '[^/]', $quoted);
    return '#^' . $quoted . '$#';
}

function ll_tools_context_pack_extract_anchors(string $path, string $contents): array
{
    if ($contents === '') {
        return [];
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['php', 'js'], true)) {
        return [];
    }

    $patterns = [
        '/\bfunction\s+([A-Za-z0-9_]+)/',
        '/\bclass\s+([A-Za-z0-9_]+)/',
        '/\badd_shortcode\(\s*[\'"]([^\'"]+)/',
        '/\bregister_rest_route\(\s*[\'"]([^\'"]+)/',
        '/wp_ajax_([A-Za-z0-9_]+)/',
        '/\bwp_localize_script\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([^\'"]+)/',
        '/\b(?:test|it)\(\s*[\'"]([^\'"]+)/',
        '/public\s+function\s+(test[A-Za-z0-9_]+)/',
    ];

    $anchors = [];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $contents, $matches)) {
            foreach ($matches[1] as $match) {
                $anchor = trim((string) $match);
                if ($anchor !== '') {
                    $anchors[] = $anchor;
                }
            }
        }
    }

    $anchors = array_values(array_unique($anchors));
    return array_slice($anchors, 0, 18);
}

function ll_tools_context_pack_render_markdown(
    string $root,
    string $packName,
    array $pack,
    array $metadata,
    array $sourceRows,
    array $missing,
    array $settings
): string {
    $markdown = "---\n";
    foreach ($metadata as $key => $value) {
        if (is_array($value)) {
            continue;
        }
        $markdown .= $key . ': ' . ll_tools_context_pack_yaml_scalar((string) $value) . "\n";
    }
    $markdown .= "sources:\n";
    foreach ($sourceRows as $row) {
        $markdown .= '  - path: ' . ll_tools_context_pack_yaml_scalar($row['path']) . "\n";
        $markdown .= '    lines: ' . (int) $row['lines'] . "\n";
        $markdown .= '    bytes: ' . (int) $row['bytes'] . "\n";
        $markdown .= '    sha256: ' . ll_tools_context_pack_yaml_scalar($row['sha256']) . "\n";
        $markdown .= '    recent_changes: ' . (int) $row['recent_changes'] . "\n";
        $markdown .= '    change_rank: ' . ($row['change_rank'] === null ? 'null' : (int) $row['change_rank']) . "\n";
        $markdown .= '    change_band: ' . ll_tools_context_pack_yaml_scalar($row['change_band']) . "\n";
        $markdown .= '    anchors: [' . implode(', ', array_map('ll_tools_context_pack_yaml_scalar', $row['anchors'])) . "]\n";
    }
    $markdown .= "---\n\n";

    $markdown .= '# LL Tools Context Pack: ' . $packName . "\n\n";
    $markdown .= "## Purpose\n\n" . $pack['description'] . "\n\n";
    $markdown .= "## Load When\n\n" . $pack['load_when'] . "\n\n";
    $markdown .= "## Hard Invariants\n\n";
    foreach ($pack['invariants'] as $invariant) {
        $markdown .= '- ' . $invariant . "\n";
    }
    $markdown .= "\n## Source Index\n\n";
    $markdown .= "| Path | Lines | Bytes | Change Signal | Anchors |\n";
    $markdown .= "| --- | ---: | ---: | --- | --- |\n";
    foreach ($sourceRows as $row) {
        $markdown .= '| `' . $row['path'] . '` | ' . (int) $row['lines'] . ' | ' . (int) $row['bytes'] . ' | '
            . ll_tools_context_pack_table_cell(ll_tools_context_pack_change_signal($row)) . ' | '
            . ll_tools_context_pack_table_cell(implode(', ', $row['anchors'])) . " |\n";
    }

    $markdown .= ll_tools_context_pack_render_change_frequency($sourceRows, $settings);

    $markdown .= "\n## Hooks/Routes/Shortcodes/Globals\n\n";
    foreach ($sourceRows as $row) {
        $important = array_values(array_filter($row['anchors'], static function (string $anchor): bool {
            return preg_match('/^(ll_|LL_|wp_ajax_|[a-z0-9_-]+\/v[0-9]|[A-Za-z0-9_-]+$)/', $anchor) === 1;
        }));
        if (!$important) {
            continue;
        }
        $markdown .= '- `' . $row['path'] . '`: ' . implode(', ', array_slice($important, 0, 10)) . "\n";
    }

    $testRows = array_values(array_filter($sourceRows, static function (array $row): bool {
        return preg_match('#^tests/(Integration/.+Test\.php|e2e/specs/.+\.spec\.js)$#', $row['path']) === 1;
    }));
    $markdown .= "\n## Focused Tests\n\n";
    if ($testRows) {
        foreach ($testRows as $row) {
            $markdown .= '- `' . $row['path'] . "`\n";
        }
    } else {
        $markdown .= "_No focused tests matched the configured patterns._\n";
    }

    if ($missing) {
        $markdown .= "\n## Excluded Or Missing Sources\n\n";
        foreach ($missing as $pattern) {
            $markdown .= '- `' . $pattern . "` did not match this checkout\n";
        }
    }

    if (!$settings['manifest_only']) {
        $markdown .= "\n## Bounded Excerpts\n";
        foreach ($sourceRows as $row) {
            if ($settings['max_chars'] > 0 && strlen($markdown) >= $settings['max_chars']) {
                $markdown .= "\n_Context pack character budget reached; remaining excerpts omitted._\n";
                break;
            }
            $markdown .= ll_tools_context_pack_file_excerpt($root, $row['path'], $settings, strlen($markdown));
        }
    }

    if ($settings['max_chars'] > 0 && strlen($markdown) > $settings['max_chars']) {
        $markdown = substr($markdown, 0, $settings['max_chars']) . "\n\n[truncated by context pack character budget]\n";
    }

    return $markdown;
}

function ll_tools_context_pack_file_excerpt(string $root, string $path, array $settings, int $currentChars): string
{
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($absolute)) {
        return '';
    }

    $contents = (string) file_get_contents($absolute);
    if ($contents === '') {
        return '';
    }

    $remaining = $settings['max_chars'] > 0 ? $settings['max_chars'] - $currentChars : PHP_INT_MAX;
    if ($remaining < 600) {
        return '';
    }

    $maxFileChars = min((int) $settings['max_file_chars'], $remaining - 200);
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    $lineLimit = min(count($lines), (int) $settings['excerpt_lines']);
    $excerpt = implode("\n", array_slice($lines, 0, $lineLimit));
    if (strlen($excerpt) > $maxFileChars) {
        $excerpt = substr($excerpt, 0, $maxFileChars);
    }

    $language = ll_tools_context_pack_language_for_path($path);
    $suffix = (count($lines) > $lineLimit || strlen($contents) > strlen($excerpt)) ? "\n\n[excerpt truncated]" : '';

    return "\n### {$path}\n\n```{$language}\n" . $excerpt . $suffix . "\n```\n";
}

function ll_tools_context_pack_render_change_frequency(array $sourceRows, array $settings): string
{
    $historyMonths = (int) ($settings['history_months'] ?? 0);
    if ($historyMonths <= 0) {
        return "\n## Change Frequency Signals\n\n_Disabled for this pack run._\n";
    }

    $maxRows = max(0, (int) ($settings['max_change_files'] ?? 12));
    if ($maxRows === 0) {
        return '';
    }

    $changedRows = array_values(array_filter($sourceRows, static function (array $row): bool {
        return (int) $row['recent_changes'] > 0;
    }));
    usort($changedRows, static function (array $a, array $b): int {
        $changeCompare = (int) $b['recent_changes'] <=> (int) $a['recent_changes'];
        if ($changeCompare !== 0) {
            return $changeCompare;
        }
        return strcmp($a['path'], $b['path']);
    });

    $quietRows = array_values(array_filter($sourceRows, static function (array $row): bool {
        return (int) $row['recent_changes'] === 0;
    }));
    usort($quietRows, static function (array $a, array $b): int {
        return strcmp($a['path'], $b['path']);
    });

    $markdown = "\n## Change Frequency Signals\n\n";
    $markdown .= "Counts are based on git commits that touched each file in the last {$historyMonths} months. ";
    $markdown .= "Use them as a triage clue, not as proof that a file is the right or wrong place to edit.\n\n";

    $markdown .= "### Most Changed Files In This Pack\n\n";
    if ($changedRows) {
        foreach (array_slice($changedRows, 0, $maxRows) as $row) {
            $markdown .= '- `' . $row['path'] . '`: ' . ll_tools_context_pack_change_signal($row) . "\n";
        }
    } else {
        $markdown .= "_No files in this pack were touched in the selected history window._\n";
    }

    $markdown .= "\n### Quiet Files In This Pack\n\n";
    if ($quietRows) {
        foreach (array_slice($quietRows, 0, $maxRows) as $row) {
            $markdown .= '- `' . $row['path'] . "`: quiet 0\n";
        }
    } else {
        $markdown .= "_Every file in this pack was touched in the selected history window._\n";
    }

    return $markdown;
}

function ll_tools_context_pack_change_signal(array $row): string
{
    $band = (string) ($row['change_band'] ?? 'quiet');
    $changes = (int) ($row['recent_changes'] ?? 0);
    if ($changes === 0) {
        return 'quiet 0';
    }

    $rank = $row['change_rank'] ?? null;
    $rankText = is_int($rank) ? ', rank #' . $rank : '';
    return $band . ' ' . $changes . $rankText;
}

function ll_tools_context_pack_language_for_path(string $path): string
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'php' => 'php',
        'js' => 'javascript',
        'css' => 'css',
        'json' => 'json',
        'sh' => 'bash',
        'md' => 'markdown',
        'html' => 'html',
    ];
    return $map[$extension] ?? '';
}

function ll_tools_context_pack_render_for_format(array $result, string $format, bool $stdout = false): string
{
    if ($format === 'json') {
        return json_encode(ll_tools_context_pack_json_payload($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
    if ($format === 'both' && $stdout) {
        return $result['markdown'] . "\n\n<!-- JSON sidecar omitted on stdout; use --format json for machine output. -->\n";
    }
    return $result['markdown'];
}

function ll_tools_context_pack_json_payload(array $result): array
{
    return [
        'metadata' => $result['metadata'],
        'pack' => $result['pack'],
        'sources' => $result['sources'],
        'missing' => $result['missing'],
    ];
}

function ll_tools_context_pack_output_target(string $root, string $output, string $packName, string $format): string
{
    if ($output !== '' && $output !== '-') {
        $resolved = ll_tools_context_pack_resolve_output_path($root, $output);
        if (count(pathinfo($resolved)) > 1 && pathinfo($resolved, PATHINFO_EXTENSION) !== '') {
            return $resolved;
        }
        return rtrim($resolved, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $packName . '-context.' . ($format === 'json' ? 'json' : 'md');
    }

    return $root . DIRECTORY_SEPARATOR . 'test-results' . DIRECTORY_SEPARATOR . 'ai-context' . DIRECTORY_SEPARATOR
        . $packName . '-context.' . ($format === 'json' ? 'json' : 'md');
}

function ll_tools_context_pack_write_result(array $result, string $target, string $format): void
{
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create output directory: {$dir}\n");
        exit(1);
    }

    if ($format === 'json') {
        file_put_contents($target, ll_tools_context_pack_render_for_format($result, 'json'));
        echo "Wrote {$target}\n";
        return;
    }

    file_put_contents($target, $result['markdown']);
    echo "Wrote {$target}\n";

    if ($format === 'both') {
        $jsonTarget = preg_replace('/\.[^.]+$/', '.json', $target);
        if (!is_string($jsonTarget) || $jsonTarget === '') {
            $jsonTarget = $target . '.json';
        }
        file_put_contents($jsonTarget, ll_tools_context_pack_render_for_format($result, 'json'));
        echo "Wrote {$jsonTarget}\n";
    }
}

function ll_tools_context_pack_resolve_output_path(string $root, string $output): string
{
    if (preg_match('/^[A-Za-z]:[\/\\\\]/', $output) === 1 || strpos($output, DIRECTORY_SEPARATOR) === 0) {
        return $output;
    }

    return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $output);
}

function ll_tools_context_pack_git(string $root, array $args): string
{
    $command = 'git';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $root);
    if (!is_resource($process)) {
        return '';
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    return $status === 0 ? trim((string) $stdout) : '';
}

function ll_tools_context_pack_yaml_scalar(string $value): string
{
    if ($value === '') {
        return '""';
    }
    return '"' . str_replace('"', '\"', $value) . '"';
}

function ll_tools_context_pack_table_cell(string $value): string
{
    $value = str_replace('|', '\\|', $value);
    return $value === '' ? '_none_' : $value;
}
