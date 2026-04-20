<?php
/**
 * Central admin menu for LL Tools.
 */

if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_admin_menu_slug')) {
    function ll_tools_get_admin_menu_slug() {
        return 'll-tools-dashboard-home';
    }
}

if (!function_exists('ll_tools_get_admin_settings_page_slug')) {
    function ll_tools_get_admin_settings_page_slug() {
        if (defined('LL_TOOLS_SETTINGS_SLUG')) {
            return (string) LL_TOOLS_SETTINGS_SLUG;
        }
        return 'language-learning-tools-settings';
    }
}

if (!function_exists('ll_tools_get_tools_hub_page_slug')) {
    function ll_tools_get_tools_hub_page_slug() {
        return 'll-tools-dashboard-tools';
    }
}

if (!function_exists('ll_tools_get_tools_hub_screen_id')) {
    function ll_tools_get_tools_hub_screen_id(): string {
        return 'toplevel_page_' . ll_tools_get_tools_hub_page_slug();
    }
}

if (!function_exists('ll_tools_get_language_learning_menu_title')) {
    function ll_tools_get_language_learning_menu_title(): string {
        return __('LL Tools', 'll-tools-text-domain');
    }
}

if (!function_exists('ll_tools_get_tools_hub_menu_title')) {
    function ll_tools_get_tools_hub_menu_title(): string {
        return __('LL Tools Utilities', 'll-tools-text-domain');
    }
}

if (!function_exists('ll_tools_get_language_learning_menu_position')) {
    function ll_tools_get_language_learning_menu_position(): float {
        return 58.01;
    }
}

if (!function_exists('ll_tools_get_tools_hub_menu_position')) {
    function ll_tools_get_tools_hub_menu_position(): float {
        return 58.02;
    }
}

if (!function_exists('ll_tools_get_dashboard_page_url')) {
    function ll_tools_get_dashboard_page_url(string $page_slug, array $args = []): string {
        $page_slug = sanitize_key($page_slug);
        $query_args = ['page' => $page_slug];
        if (!empty($args)) {
            $query_args = array_merge($query_args, $args);
        }
        return (string) add_query_arg($query_args, admin_url('admin.php'));
    }
}

if (!function_exists('ll_tools_get_tools_page_url')) {
    function ll_tools_get_tools_page_url(string $page_slug, array $args = []): string {
        $page_slug = sanitize_key($page_slug);
        $query_args = ['page' => $page_slug];
        if (!empty($args)) {
            $query_args = array_merge($query_args, $args);
        }
        return (string) add_query_arg($query_args, admin_url('tools.php'));
    }
}

if (!function_exists('ll_tools_get_settings_related_page_slugs')) {
    function ll_tools_get_settings_related_page_slugs() {
        return [ll_tools_get_admin_settings_page_slug()];
    }
}

if (!function_exists('ll_tools_get_tools_hub_related_page_slugs')) {
    function ll_tools_get_tools_hub_related_page_slugs() {
        return [
            'll-audio-processor',
            'll-audio-image-matcher',
            'language-learner-tools-missing-audio',
            'll-bulk-word-import',
            'll-dictionary-import',
            'll-dictionary-sources',
            'll-bulk-translations',
            'll-export',
            'll-import',
            'll-offline-app-export',
            'll-ipa-keyboard',
            'll-word-option-rules',
            'll-image-aspect-normalizer',
            'll-image-webp-optimizer',
            'll-fix-word-images',
            'll-recording-types',
            'll-tools-user-progress-report',
            'deepl-api-key',
            'assemblyai-api-key',
            'language-learner-tools-languages',
        ];
    }
}

if (!function_exists('ll_tools_get_dashboard_related_page_slugs')) {
    function ll_tools_get_dashboard_related_page_slugs(): array {
        $slugs = array_merge(
            [
                ll_tools_get_admin_menu_slug(),
                ll_tools_get_tools_hub_page_slug(),
            ],
            ll_tools_get_settings_related_page_slugs(),
            ll_tools_get_tools_hub_related_page_slugs()
        );

        if (function_exists('ll_tools_get_teacher_classes_page_slug')) {
            $slugs[] = ll_tools_get_teacher_classes_page_slug();
        }

        $slugs = array_values(array_filter(array_map('sanitize_key', $slugs), static function ($slug): bool {
            return $slug !== '';
        }));

        return array_values(array_unique($slugs));
    }
}

if (!function_exists('ll_tools_is_dashboard_related_page_slug')) {
    function ll_tools_is_dashboard_related_page_slug(string $page_slug): bool {
        $page_slug = sanitize_key($page_slug);
        if ($page_slug === '') {
            return false;
        }
        return in_array($page_slug, ll_tools_get_dashboard_related_page_slugs(), true);
    }
}

if (!function_exists('ll_tools_get_dashboard_related_page_title_map')) {
    function ll_tools_get_dashboard_related_page_title_map(): array {
        $titles = [
            ll_tools_get_admin_menu_slug() => __('Language Learning Tools', 'll-tools-text-domain'),
            ll_tools_get_tools_hub_page_slug() => __('LL Tools Utilities', 'll-tools-text-domain'),
            ll_tools_get_admin_settings_page_slug() => __('Language Learning Tools Settings', 'll-tools-text-domain'),
            'll-audio-processor' => __('Audio Processor', 'll-tools-text-domain'),
            'll-audio-image-matcher' => __('Audio/Image Matcher', 'll-tools-text-domain'),
            'language-learner-tools-missing-audio' => __('Missing Audio', 'll-tools-text-domain'),
            'll-bulk-word-import' => __('Bulk Word Import', 'll-tools-text-domain'),
            'll-dictionary-import' => __('Dictionary Manager', 'll-tools-text-domain'),
            'll-dictionary-sources' => __('Dictionary Sources', 'll-tools-text-domain'),
            'll-bulk-translations' => __('Bulk Translations', 'll-tools-text-domain'),
            'll-export' => __('Export', 'll-tools-text-domain'),
            'll-import' => __('Import', 'll-tools-text-domain'),
            'll-offline-app-export' => __('Offline App Export', 'll-tools-text-domain'),
            'll-ipa-keyboard' => __('Transcription Manager', 'll-tools-text-domain'),
            'll-word-option-rules' => __('Word Option Rules', 'll-tools-text-domain'),
            'll-image-aspect-normalizer' => __('Image Aspect Normalizer', 'll-tools-text-domain'),
            'll-image-webp-optimizer' => __('WebP Image Optimizer', 'll-tools-text-domain'),
            'll-fix-word-images' => __('Fix Word Images', 'll-tools-text-domain'),
            'll-recording-types' => __('LL Recording Types', 'll-tools-text-domain'),
            'll-tools-user-progress-report' => __('Learner Progress', 'll-tools-text-domain'),
            'deepl-api-key' => __('DeepL API Key', 'll-tools-text-domain'),
            'assemblyai-api-key' => __('AssemblyAI API Key', 'll-tools-text-domain'),
            'language-learner-tools-languages' => __('LL Tools Languages', 'll-tools-text-domain'),
        ];

        if (function_exists('ll_tools_get_teacher_classes_page_slug')) {
            $titles[ll_tools_get_teacher_classes_page_slug()] = __('Classes', 'll-tools-text-domain');
        }

        return $titles;
    }
}

if (!function_exists('ll_tools_get_dashboard_related_post_types')) {
    function ll_tools_get_dashboard_related_post_types(): array {
        return ['words', 'word_images', 'word_audio', 'll_dictionary_entry', 'll_vocab_lesson', 'll_content_lesson'];
    }
}

if (!function_exists('ll_tools_get_dashboard_related_taxonomies')) {
    function ll_tools_get_dashboard_related_taxonomies(): array {
        return ['word-category', 'wordset', 'part_of_speech'];
    }
}

if (!function_exists('ll_tools_get_language_learning_related_post_types')) {
    function ll_tools_get_language_learning_related_post_types(): array {
        return ['words', 'word_images', 'word_audio', 'll_dictionary_entry'];
    }
}

if (!function_exists('ll_tools_get_language_learning_related_taxonomies')) {
    function ll_tools_get_language_learning_related_taxonomies(): array {
        return ['word-category', 'wordset'];
    }
}

if (!function_exists('ll_tools_get_tools_hub_related_post_types')) {
    function ll_tools_get_tools_hub_related_post_types(): array {
        return ['ll_vocab_lesson', 'll_content_lesson'];
    }
}

if (!function_exists('ll_tools_get_tools_hub_related_taxonomies')) {
    function ll_tools_get_tools_hub_related_taxonomies(): array {
        return ['part_of_speech'];
    }
}

function ll_tools_get_dashboard_fallback_admin_page_title($screen = null): string {
    $page = ll_tools_get_current_plugin_page_slug();
    if ($page !== '') {
        $page_titles = ll_tools_get_dashboard_related_page_title_map();
        if (isset($page_titles[$page])) {
            return (string) $page_titles[$page];
        }
    }

    if (!$screen instanceof WP_Screen) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    }

    if (!$screen instanceof WP_Screen) {
        return '';
    }

    $post_type = is_string($screen->post_type) ? $screen->post_type : '';
    if ($post_type !== '' && in_array($post_type, ll_tools_get_dashboard_related_post_types(), true)) {
        $post_type_object = get_post_type_object($post_type);
        if ($post_type_object instanceof WP_Post_Type && isset($post_type_object->labels)) {
            if ($screen->base === 'post') {
                if ($screen->action === 'add' && !empty($post_type_object->labels->add_new_item)) {
                    return (string) $post_type_object->labels->add_new_item;
                }

                if (!empty($post_type_object->labels->edit_item)) {
                    return (string) $post_type_object->labels->edit_item;
                }
            }

            if (!empty($post_type_object->labels->name)) {
                return (string) $post_type_object->labels->name;
            }
        }
    }

    $taxonomy = is_string($screen->taxonomy) ? $screen->taxonomy : '';
    if ($taxonomy !== '' && in_array($taxonomy, ll_tools_get_dashboard_related_taxonomies(), true)) {
        $taxonomy_object = get_taxonomy($taxonomy);
        if ($taxonomy_object instanceof WP_Taxonomy && isset($taxonomy_object->labels)) {
            $is_term_edit_screen = ($screen->base === 'term')
                || (isset($_GET['action']) && sanitize_key((string) wp_unslash($_GET['action'])) === 'edit');

            if ($is_term_edit_screen && !empty($taxonomy_object->labels->edit_item)) {
                return (string) $taxonomy_object->labels->edit_item;
            }

            if (!empty($taxonomy_object->labels->menu_name)) {
                return (string) $taxonomy_object->labels->menu_name;
            }

            if (!empty($taxonomy_object->labels->name)) {
                return (string) $taxonomy_object->labels->name;
            }
        }
    }

    return '';
}

function ll_tools_prime_admin_title_for_dashboard_pages($screen): void {
    if (!$screen instanceof WP_Screen) {
        return;
    }

    global $title;

    if (is_string($title) && trim($title) !== '') {
        return;
    }

    $page = ll_tools_get_current_plugin_page_slug();
    $post_type = is_string($screen->post_type) ? $screen->post_type : '';
    $taxonomy = is_string($screen->taxonomy) ? $screen->taxonomy : '';
    $is_dashboard_related = ($page !== '' && ll_tools_is_dashboard_related_page_slug($page))
        || in_array($post_type, ll_tools_get_dashboard_related_post_types(), true)
        || in_array($taxonomy, ll_tools_get_dashboard_related_taxonomies(), true);

    if (!$is_dashboard_related) {
        return;
    }

    $fallback_title = trim(wp_strip_all_tags(ll_tools_get_dashboard_fallback_admin_page_title($screen)));
    if ($fallback_title !== '') {
        $title = $fallback_title;
    }
}
add_action('current_screen', 'll_tools_prime_admin_title_for_dashboard_pages');

function ll_tools_render_settings_page_menu_wrapper() {
    if (function_exists('ll_render_settings_page')) {
        ll_render_settings_page();
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__('Language Learning Tools', 'll-tools-text-domain') . '</h1>';
    echo '<p>' . esc_html__('Settings page is unavailable.', 'll-tools-text-domain') . '</p></div>';
}

function ll_tools_render_home_hub_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
    }

    $overview_links = [
        [
            'label' => __('Word Sets', 'll-tools-text-domain'),
            'description' => __('Manage the main set grouping for words and quiz filters.', 'll-tools-text-domain'),
            'url' => admin_url('edit-tags.php?taxonomy=wordset&post_type=words'),
            'cap' => 'edit_wordsets',
            'icon' => 'dashicons-portfolio',
        ],
        [
            'label' => __('Word Categories', 'll-tools-text-domain'),
            'description' => __('Create and organize categories used to build quizzes and pages.', 'll-tools-text-domain'),
            'url' => admin_url('edit-tags.php?taxonomy=word-category&post_type=words'),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-category',
        ],
        [
            'label' => __('Words', 'll-tools-text-domain'),
            'description' => __('Add and edit vocabulary entries, meanings, and metadata.', 'll-tools-text-domain'),
            'url' => admin_url('edit.php?post_type=words'),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-welcome-write-blog',
        ],
        [
            'label' => __('Word Images', 'll-tools-text-domain'),
            'description' => __('Manage image records linked to vocabulary words.', 'll-tools-text-domain'),
            'url' => admin_url('edit.php?post_type=word_images'),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-format-image',
        ],
        [
            'label' => __('Word Audio', 'll-tools-text-domain'),
            'description' => __('Review and edit published audio posts used across the site.', 'll-tools-text-domain'),
            'url' => admin_url('edit.php?post_type=word_audio'),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-format-audio',
        ],
        [
            'label' => __('Dictionary Entries', 'll-tools-text-domain'),
            'description' => __('Maintain dictionary-style reference entries and supporting details.', 'll-tools-text-domain'),
            'url' => admin_url('edit.php?post_type=ll_dictionary_entry'),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-book-alt',
        ],
    ];

    $admin_links = [
        [
            'label' => __('Classes', 'll-tools-text-domain'),
            'description' => __('Create teacher classes, invite learners, and review student progress.', 'll-tools-text-domain'),
            'url' => function_exists('ll_tools_get_teacher_classes_page_url')
                ? ll_tools_get_teacher_classes_page_url()
                : admin_url(),
            'cap' => function_exists('ll_tools_get_teacher_manage_classes_capability')
                ? ll_tools_get_teacher_manage_classes_capability()
                : 'manage_options',
            'icon' => 'dashicons-groups',
        ],
        [
            'label' => ll_tools_get_tools_hub_menu_title(),
            'description' => __('Open the utilities menu for import/export, processing, and maintenance workflows.', 'll-tools-text-domain'),
            'url' => ll_tools_get_dashboard_page_url(ll_tools_get_tools_hub_page_slug()),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-admin-tools',
        ],
        [
            'label' => __('Settings', 'll-tools-text-domain'),
            'description' => __('Configure global plugin behavior, languages, and operational defaults.', 'll-tools-text-domain'),
            'url' => ll_tools_get_dashboard_page_url(ll_tools_get_admin_settings_page_slug()),
            'cap' => 'view_ll_tools',
            'icon' => 'dashicons-admin-generic',
        ],
    ];

    echo '<div class="wrap ll-tools-hub">';
    echo '<h1>' . esc_html__('Language Learning Tools', 'll-tools-text-domain') . '</h1>';
    echo '<p class="ll-tools-hub-intro">' . esc_html__('Use this dashboard to jump to each main area of the plugin. Tools opens workflow utilities; Settings controls global behavior.', 'll-tools-text-domain') . '</p>';

    echo '<section class="ll-tools-hub-section">';
    echo '<h2 class="ll-tools-hub-section-title">' . esc_html__('Content', 'll-tools-text-domain') . '</h2>';
    echo '<p class="ll-tools-hub-section-description">' . esc_html__('Primary content management pages for vocabulary, media, and dictionary data.', 'll-tools-text-domain') . '</p>';
    ll_tools_render_tools_hub_cards($overview_links);
    echo '</section>';

    echo '<section class="ll-tools-hub-section">';
    echo '<h2 class="ll-tools-hub-section-title">' . esc_html__('Administration', 'll-tools-text-domain') . '</h2>';
    echo '<p class="ll-tools-hub-section-description">' . esc_html__('Utility and configuration pages used less frequently.', 'll-tools-text-domain') . '</p>';
    ll_tools_render_tools_hub_cards($admin_links);
    echo '</section>';

    echo '</div>';
}

function ll_tools_get_tools_hub_card_sections(): array {
    $api_capability = function_exists('ll_tools_api_settings_capability')
        ? ll_tools_api_settings_capability()
        : 'manage_options';
    $bulk_word_import_capability = function_exists('ll_tools_get_bulk_word_import_capability')
        ? ll_tools_get_bulk_word_import_capability()
        : 'manage_options';
    $dictionary_import_capability = function_exists('ll_tools_get_dictionary_import_capability')
        ? ll_tools_get_dictionary_import_capability()
        : 'manage_options';
    $dictionary_sources_capability = function_exists('ll_tools_get_dictionary_sources_capability')
        ? ll_tools_get_dictionary_sources_capability()
        : $dictionary_import_capability;
    $export_import_capability = function_exists('ll_tools_get_export_import_capability')
        ? ll_tools_get_export_import_capability()
        : 'manage_options';
    $offline_app_export_capability = function_exists('ll_tools_get_offline_app_export_capability')
        ? ll_tools_get_offline_app_export_capability()
        : 'manage_options';
    $user_progress_capability = function_exists('ll_tools_get_user_progress_report_capability')
        ? ll_tools_get_user_progress_report_capability()
        : 'manage_options';
    $export_page_slug = function_exists('ll_tools_get_export_page_slug')
        ? ll_tools_get_export_page_slug()
        : 'll-export';
    $import_page_slug = function_exists('ll_tools_get_import_page_slug')
        ? ll_tools_get_import_page_slug()
        : 'll-import';

    return [
        'workflow' => [
            [
                'label' => __('Audio Processor', 'll-tools-text-domain'),
                'description' => __('Review queued recordings, clean audio, and publish approved files.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-audio-processor',
                'url' => ll_tools_get_tools_page_url('ll-audio-processor'),
                'page_slug' => 'll-audio-processor',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-format-audio',
            ],
            [
                'label' => __('Audio/Image Matcher', 'll-tools-text-domain'),
                'description' => __('Match uploaded audio and images to the correct words and categories.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-audio-image-matcher',
                'url' => ll_tools_get_tools_page_url('ll-audio-image-matcher'),
                'page_slug' => 'll-audio-image-matcher',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-images-alt2',
            ],
            [
                'label' => __('Missing Audio', 'll-tools-text-domain'),
                'description' => __('Find words without audio and resolve gaps in recording coverage.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=language-learner-tools-missing-audio',
                'url' => ll_tools_get_tools_page_url('language-learner-tools-missing-audio'),
                'page_slug' => 'language-learner-tools-missing-audio',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-warning',
            ],
            [
                'label' => __('Bulk Word Import', 'll-tools-text-domain'),
                'description' => __('Create many draft word posts at once from pasted text.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-bulk-word-import',
                'url' => ll_tools_get_tools_page_url('ll-bulk-word-import'),
                'page_slug' => 'll-bulk-word-import',
                'cap' => $bulk_word_import_capability,
                'icon' => 'dashicons-database-import',
            ],
            [
                'label' => __('Dictionary Manager', 'll-tools-text-domain'),
                'description' => __('Import TSV dictionaries or export and restore whole-site dictionary snapshots.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-dictionary-import',
                'url' => ll_tools_get_tools_page_url('ll-dictionary-import'),
                'page_slug' => 'll-dictionary-import',
                'cap' => $dictionary_import_capability,
                'icon' => 'dashicons-book-alt',
            ],
            [
                'label' => __('Dictionary Sources', 'll-tools-text-domain'),
                'description' => __('Maintain reusable source labels, attribution text, source-page URLs, and default dialects used by dictionary entries.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-dictionary-sources',
                'url' => ll_tools_get_tools_page_url('ll-dictionary-sources'),
                'page_slug' => 'll-dictionary-sources',
                'cap' => $dictionary_sources_capability,
                'icon' => 'dashicons-admin-links',
            ],
            [
                'label' => __('Bulk Translations', 'll-tools-text-domain'),
                'description' => __('Generate translations in bulk using your configured translation services.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-bulk-translations',
                'url' => ll_tools_get_tools_page_url('ll-bulk-translations'),
                'page_slug' => 'll-bulk-translations',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-translation',
            ],
            [
                'label' => __('Learner Progress', 'll-tools-text-domain'),
                'description' => __('Review learner activity and progress summaries by word set.', 'll-tools-text-domain'),
                'menu_slug' => 'admin.php?page=ll-tools-user-progress-report',
                'url' => ll_tools_get_dashboard_page_url('ll-tools-user-progress-report'),
                'page_slug' => 'll-tools-user-progress-report',
                'cap' => $user_progress_capability,
                'icon' => 'dashicons-chart-bar',
            ],
            [
                'label' => __('Export', 'll-tools-text-domain'),
                'description' => __('Download category bundles and CSV text exports for transfer or backup.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=' . sanitize_key((string) $export_page_slug),
                'url' => ll_tools_get_tools_page_url($export_page_slug),
                'page_slug' => sanitize_key((string) $export_page_slug),
                'cap' => $export_import_capability,
                'icon' => 'dashicons-download',
            ],
            [
                'label' => __('Import', 'll-tools-text-domain'),
                'description' => __('Preview and import zip bundles into local categories, words, and audio.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=' . sanitize_key((string) $import_page_slug),
                'url' => ll_tools_get_tools_page_url($import_page_slug),
                'page_slug' => sanitize_key((string) $import_page_slug),
                'cap' => $export_import_capability,
                'icon' => 'dashicons-upload',
            ],
            [
                'label' => __('Offline App Export', 'll-tools-text-domain'),
                'description' => __('Build a one-wordset offline quiz bundle that can be turned into an Android APK.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-offline-app-export',
                'url' => ll_tools_get_tools_page_url('ll-offline-app-export'),
                'page_slug' => 'll-offline-app-export',
                'cap' => $offline_app_export_capability,
                'icon' => 'dashicons-smartphone',
            ],
            [
                'label' => __('Transcription Manager', 'll-tools-text-domain'),
                'description' => __('Review transcriptions by word set, search recordings, and catch likely IPA issues.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-ipa-keyboard',
                'url' => ll_tools_get_tools_page_url('ll-ipa-keyboard'),
                'page_slug' => 'll-ipa-keyboard',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-editor-spellcheck',
            ],
            [
                'label' => __('Word Option Rules', 'll-tools-text-domain'),
                'description' => __('Control grouped options and blocked pairs for quiz wrong answers.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-word-option-rules',
                'url' => ll_tools_get_tools_page_url('ll-word-option-rules'),
                'page_slug' => 'll-word-option-rules',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-screenoptions',
            ],
            [
                'label' => __('Normalize Image Ratios', 'll-tools-text-domain'),
                'description' => __('Detect category image ratio mismatches and apply guided fixed-ratio crops.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-image-aspect-normalizer',
                'url' => ll_tools_get_tools_page_url('ll-image-aspect-normalizer'),
                'page_slug' => 'll-image-aspect-normalizer',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-format-gallery',
            ],
            [
                'label' => __('Optimize Images (WebP)', 'll-tools-text-domain'),
                'description' => __('Flag oversized or non-WebP word images and batch-optimize them with progress tracking.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-image-webp-optimizer',
                'url' => ll_tools_get_tools_page_url('ll-image-webp-optimizer'),
                'page_slug' => 'll-image-webp-optimizer',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-images-alt',
            ],
            [
                'label' => __('Fix Word Images', 'll-tools-text-domain'),
                'description' => __('Repair legacy image links and recreate missing word image posts.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-fix-word-images',
                'url' => ll_tools_get_tools_page_url('ll-fix-word-images'),
                'page_slug' => 'll-fix-word-images',
                'cap' => 'manage_options',
                'icon' => 'dashicons-hammer',
            ],
        ],
        'content_admin' => [
            [
                'label' => __('Content Lessons', 'll-tools-text-domain'),
                'description' => __('Edit main audio/video lessons and synced transcript content.', 'll-tools-text-domain'),
                'menu_slug' => 'edit.php?post_type=ll_content_lesson',
                'url' => admin_url('edit.php?post_type=ll_content_lesson'),
                'post_type' => 'll_content_lesson',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-format-audio',
            ],
            [
                'label' => __('Vocab Lessons', 'll-tools-text-domain'),
                'description' => __('Edit lesson post content and ordering used for vocab lesson pages.', 'll-tools-text-domain'),
                'menu_slug' => 'edit.php?post_type=ll_vocab_lesson',
                'url' => admin_url('edit.php?post_type=ll_vocab_lesson'),
                'post_type' => 'll_vocab_lesson',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-welcome-learn-more',
            ],
            [
                'label' => __('Parts of Speech', 'll-tools-text-domain'),
                'description' => __('Maintain part-of-speech taxonomy values used on word entries.', 'll-tools-text-domain'),
                'menu_slug' => 'edit-tags.php?taxonomy=part_of_speech&post_type=words',
                'url' => admin_url('edit-tags.php?taxonomy=part_of_speech&post_type=words'),
                'taxonomy' => 'part_of_speech',
                'cap' => 'view_ll_tools',
                'icon' => 'dashicons-editor-paragraph',
            ],
            [
                'label' => __('LL Recording Types', 'll-tools-text-domain'),
                'description' => __('Define recording type labels used by recording and export workflows.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=ll-recording-types',
                'url' => ll_tools_get_tools_page_url('ll-recording-types'),
                'page_slug' => 'll-recording-types',
                'cap' => 'manage_options',
                'icon' => 'dashicons-tag',
            ],
        ],
        'advanced' => [
            [
                'label' => __('DeepL API Key', 'll-tools-text-domain'),
                'description' => __('Set and update DeepL credentials for automated translation tasks.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=deepl-api-key',
                'url' => ll_tools_get_tools_page_url('deepl-api-key'),
                'page_slug' => 'deepl-api-key',
                'cap' => $api_capability,
                'icon' => 'dashicons-admin-network',
            ],
            [
                'label' => __('AssemblyAI API Key', 'll-tools-text-domain'),
                'description' => __('Set and update AssemblyAI credentials for transcription workflows.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=assemblyai-api-key',
                'url' => ll_tools_get_tools_page_url('assemblyai-api-key'),
                'page_slug' => 'assemblyai-api-key',
                'cap' => $api_capability,
                'icon' => 'dashicons-microphone',
            ],
            [
                'label' => __('LL Tools Languages', 'll-tools-text-domain'),
                'description' => __('Manage language taxonomy options and plugin language metadata.', 'll-tools-text-domain'),
                'menu_slug' => 'tools.php?page=language-learner-tools-languages',
                'url' => ll_tools_get_tools_page_url('language-learner-tools-languages'),
                'page_slug' => 'language-learner-tools-languages',
                'cap' => 'manage_options',
                'icon' => 'dashicons-translation',
            ],
        ],
    ];
}

function ll_tools_get_tools_hub_menu_items(): array {
    $sections = ll_tools_get_tools_hub_card_sections();
    $items = [];

    foreach ($sections as $cards) {
        foreach ($cards as $card) {
            $items[] = $card;
        }
    }

    return $items;
}

function ll_tools_get_tools_hub_direct_submenu_page_slugs(): array {
    $export_page_slug = function_exists('ll_tools_get_export_page_slug')
        ? sanitize_key((string) ll_tools_get_export_page_slug())
        : 'll-export';
    $import_page_slug = function_exists('ll_tools_get_import_page_slug')
        ? sanitize_key((string) ll_tools_get_import_page_slug())
        : 'll-import';

    return [
        'll-audio-processor',
        'll-bulk-word-import',
        'll-dictionary-import',
        $export_page_slug,
        $import_page_slug,
        'll-offline-app-export',
    ];
}

function ll_tools_get_tools_hub_direct_submenu_items(): array {
    $allowed_page_slugs = array_fill_keys(ll_tools_get_tools_hub_direct_submenu_page_slugs(), true);

    return array_values(array_filter(ll_tools_get_tools_hub_menu_items(), static function ($item) use ($allowed_page_slugs): bool {
        $page_slug = isset($item['page_slug']) ? sanitize_key((string) $item['page_slug']) : '';
        return $page_slug !== '' && isset($allowed_page_slugs[$page_slug]);
    }));
}

function ll_tools_register_dashboard_menu() {
    $menu_slug = ll_tools_get_admin_menu_slug();
    $tools_menu_slug = ll_tools_get_tools_hub_page_slug();
    $settings_slug = ll_tools_get_admin_settings_page_slug();
    global $submenu;

    add_menu_page(
        __('Language Learning Tools', 'll-tools-text-domain'),
        ll_tools_get_language_learning_menu_title(),
        'view_ll_tools',
        $menu_slug,
        'll_tools_render_home_hub_page',
        'dashicons-translation',
        ll_tools_get_language_learning_menu_position()
    );

    add_menu_page(
        __('LL Tools Utilities', 'll-tools-text-domain'),
        ll_tools_get_tools_hub_menu_title(),
        'view_ll_tools',
        $tools_menu_slug,
        'll_tools_render_tools_hub_page',
        'dashicons-admin-tools',
        ll_tools_get_tools_hub_menu_position()
    );

    // Rename the auto-created first submenu (same slug as top-level) to "Overview"
    // without registering a second callback for the same screen hook.
    if (isset($submenu[$menu_slug][0][0])) {
        $submenu[$menu_slug][0][0] = __('Overview', 'll-tools-text-domain');
    }
    if (isset($submenu[$tools_menu_slug][0][0])) {
        $submenu[$tools_menu_slug][0][0] = __('Overview', 'll-tools-text-domain');
    }

    add_submenu_page(
        $menu_slug,
        __('Word Sets', 'll-tools-text-domain'),
        __('Word Sets', 'll-tools-text-domain'),
        'edit_wordsets',
        'edit-tags.php?taxonomy=wordset&post_type=words'
    );

    add_submenu_page(
        $menu_slug,
        __('Word Categories', 'll-tools-text-domain'),
        __('Word Categories', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit-tags.php?taxonomy=word-category&post_type=words'
    );

    add_submenu_page(
        $menu_slug,
        __('Words', 'll-tools-text-domain'),
        __('Words', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit.php?post_type=words'
    );

    add_submenu_page(
        $menu_slug,
        __('Word Images', 'll-tools-text-domain'),
        __('Word Images', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit.php?post_type=word_images'
    );

    add_submenu_page(
        $menu_slug,
        __('Word Audio', 'll-tools-text-domain'),
        __('Word Audio', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit.php?post_type=word_audio'
    );

    add_submenu_page(
        $menu_slug,
        __('Dictionary Entries', 'll-tools-text-domain'),
        __('Dictionary Entries', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit.php?post_type=ll_dictionary_entry'
    );

    add_submenu_page(
        $menu_slug,
        __('Language Learning Tools Settings', 'll-tools-text-domain'),
        __('Settings', 'll-tools-text-domain'),
        'view_ll_tools',
        $settings_slug,
        'll_tools_render_settings_page_menu_wrapper'
    );
}
add_action('admin_menu', 'll_tools_register_dashboard_menu', 9);

function ll_tools_register_tools_hub_submenus(): void {
    global $submenu;

    $menu_slug = ll_tools_get_tools_hub_page_slug();
    if (!isset($submenu[$menu_slug]) || !is_array($submenu[$menu_slug])) {
        return;
    }

    foreach (ll_tools_get_tools_hub_direct_submenu_items() as $item) {
        $label = isset($item['label']) ? (string) $item['label'] : '';
        $capability = isset($item['cap']) ? (string) $item['cap'] : 'manage_options';
        $submenu_slug = isset($item['menu_slug']) ? (string) $item['menu_slug'] : '';
        if ($label === '' || $submenu_slug === '') {
            continue;
        }

        $submenu[$menu_slug][] = [$label, $capability, $submenu_slug, $label];
    }
}
add_action('admin_menu', 'll_tools_register_tools_hub_submenus', 30);

function ll_tools_enqueue_dashboard_menu_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';

    if ($page !== '' && ll_tools_is_dashboard_related_page_slug($page)) {
        ll_enqueue_asset_by_timestamp('/css/admin-dashboard-menu.css', 'll-tools-admin-dashboard-menu', [], false);
    }

    if ($page !== ll_tools_get_tools_hub_page_slug() && $page !== ll_tools_get_admin_menu_slug()) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/admin-tools-hub.css', 'll-tools-admin-tools-hub', [], false);
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_dashboard_menu_assets');

function ll_tools_render_tools_hub_cards(array $cards) {
    $visible_cards = 0;

    echo '<div class="ll-tools-hub-grid">';
    foreach ($cards as $card) {
        $capability = isset($card['cap']) ? (string) $card['cap'] : 'manage_options';
        if (!current_user_can($capability)) {
            continue;
        }

        $visible_cards++;
        $label = isset($card['label']) ? (string) $card['label'] : '';
        $description = isset($card['description']) ? (string) $card['description'] : '';
        $url = isset($card['url']) ? (string) $card['url'] : admin_url();
        $icon = isset($card['icon']) ? sanitize_html_class((string) $card['icon']) : 'dashicons-admin-tools';
        if (strpos($icon, 'dashicons-') !== 0) {
            $icon = 'dashicons-admin-tools';
        }

        echo '<a class="ll-tools-hub-card" href="' . esc_url($url) . '">';
        echo '<span class="ll-tools-hub-card-icon" aria-hidden="true"><span class="dashicons ' . esc_attr($icon) . '"></span></span>';
        echo '<span class="ll-tools-hub-card-content">';
        echo '<span class="ll-tools-hub-card-title">' . esc_html($label) . '</span>';
        if ($description !== '') {
            echo '<span class="ll-tools-hub-card-description">' . esc_html($description) . '</span>';
        }
        echo '</span>';
        echo '<span class="ll-tools-hub-card-arrow dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';
        echo '</a>';
    }
    echo '</div>';

    if ($visible_cards === 0) {
        echo '<p class="ll-tools-hub-empty">' . esc_html__('No tools are available with your current permissions.', 'll-tools-text-domain') . '</p>';
    }
}

function ll_tools_render_tools_hub_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
    }

    $sections = ll_tools_get_tools_hub_card_sections();
    $workflow_links = (array) ($sections['workflow'] ?? []);
    $content_admin_links = (array) ($sections['content_admin'] ?? []);
    $advanced_links = (array) ($sections['advanced'] ?? []);

    echo '<div class="wrap ll-tools-hub">';
    echo '<h1>' . esc_html__('LL Tools Utilities', 'll-tools-text-domain') . '</h1>';
    echo '<p class="ll-tools-hub-intro">' . esc_html__('Choose a tool below to run a workflow. Each card includes a quick summary to help you find the right utility at a glance.', 'll-tools-text-domain') . '</p>';

    echo '<section class="ll-tools-hub-section">';
    echo '<h2 class="ll-tools-hub-section-title">' . esc_html__('Workflows', 'll-tools-text-domain') . '</h2>';
    echo '<p class="ll-tools-hub-section-description">' . esc_html__('Daily operational tools for processing content, media, and quiz behavior.', 'll-tools-text-domain') . '</p>';
    ll_tools_render_tools_hub_cards($workflow_links);
    echo '</section>';

    echo '<section class="ll-tools-hub-section">';
    echo '<h2 class="ll-tools-hub-section-title">' . esc_html__('Content Admin', 'll-tools-text-domain') . '</h2>';
    echo '<p class="ll-tools-hub-section-description">' . esc_html__('Lower-frequency admin pages for lesson and taxonomy maintenance.', 'll-tools-text-domain') . '</p>';
    ll_tools_render_tools_hub_cards($content_admin_links);
    echo '</section>';

    echo '<section class="ll-tools-hub-section">';
    echo '<h2 class="ll-tools-hub-section-title">' . esc_html__('Advanced', 'll-tools-text-domain') . '</h2>';
    echo '<p class="ll-tools-hub-section-description">' . esc_html__('Configuration and taxonomy pages used for setup and maintenance.', 'll-tools-text-domain') . '</p>';
    ll_tools_render_tools_hub_cards($advanced_links);
    echo '</section>';

    echo '</div>';
}

function ll_tools_hide_legacy_admin_menu_entries() {
    $settings_slug = ll_tools_get_admin_settings_page_slug();

    remove_submenu_page('options-general.php', $settings_slug);

    foreach (array_merge(ll_tools_get_settings_related_page_slugs(), ll_tools_get_tools_hub_related_page_slugs()) as $page_slug) {
        remove_submenu_page('tools.php', $page_slug);
    }

    // Hide old top-level post type menus; they are now reachable from LL Tools.
    remove_menu_page('edit.php?post_type=words');
    remove_menu_page('edit.php?post_type=word_images');
    remove_menu_page('edit.php?post_type=ll_vocab_lesson');
    remove_menu_page('edit.php?post_type=ll_content_lesson');
}
add_action('admin_menu', 'll_tools_hide_legacy_admin_menu_entries', 999);

function ll_tools_get_current_plugin_page_slug(): string {
    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    if ($page !== '') {
        return $page;
    }

    global $plugin_page;
    if (is_string($plugin_page) && $plugin_page !== '') {
        return sanitize_key($plugin_page);
    }

    return '';
}

function ll_tools_get_language_learning_submenu_file(string $page = '', $screen = null): string {
    $page = sanitize_key($page);

    if ($page === ll_tools_get_admin_menu_slug()) {
        return ll_tools_get_admin_menu_slug();
    }

    if ($page === ll_tools_get_admin_settings_page_slug() || in_array($page, ll_tools_get_settings_related_page_slugs(), true)) {
        return ll_tools_get_admin_settings_page_slug();
    }

    if (function_exists('ll_tools_get_teacher_classes_page_slug') && $page === ll_tools_get_teacher_classes_page_slug()) {
        return ll_tools_get_teacher_classes_page_slug();
    }

    if (!$screen instanceof WP_Screen) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    }

    if (!$screen instanceof WP_Screen) {
        return '';
    }

    $taxonomy = is_string($screen->taxonomy) ? $screen->taxonomy : '';
    if ($taxonomy === 'word-category') {
        return 'edit-tags.php?taxonomy=word-category&post_type=words';
    }
    if ($taxonomy === 'wordset') {
        return 'edit-tags.php?taxonomy=wordset&post_type=words';
    }

    $post_type = is_string($screen->post_type) ? $screen->post_type : '';
    $post_type_map = [
        'words' => 'edit.php?post_type=words',
        'word_images' => 'edit.php?post_type=word_images',
        'word_audio' => 'edit.php?post_type=word_audio',
        'll_dictionary_entry' => 'edit.php?post_type=ll_dictionary_entry',
    ];

    return isset($post_type_map[$post_type]) ? $post_type_map[$post_type] : '';
}

function ll_tools_get_tools_hub_submenu_file(string $page = '', $screen = null): string {
    $page = sanitize_key($page);

    if ($page === ll_tools_get_tools_hub_page_slug()) {
        return ll_tools_get_tools_hub_page_slug();
    }

    if (!$screen instanceof WP_Screen) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    }

    $post_type = ($screen instanceof WP_Screen && is_string($screen->post_type)) ? $screen->post_type : '';
    $taxonomy = ($screen instanceof WP_Screen && is_string($screen->taxonomy)) ? $screen->taxonomy : '';

    foreach (ll_tools_get_tools_hub_menu_items() as $item) {
        $page_slug = isset($item['page_slug']) ? sanitize_key((string) $item['page_slug']) : '';
        if ($page_slug !== '' && $page_slug === $page) {
            return (string) ($item['menu_slug'] ?? '');
        }

        $item_post_type = isset($item['post_type']) ? (string) $item['post_type'] : '';
        if ($item_post_type !== '' && $item_post_type === $post_type) {
            return (string) ($item['menu_slug'] ?? '');
        }

        $item_taxonomy = isset($item['taxonomy']) ? (string) $item['taxonomy'] : '';
        if ($item_taxonomy !== '' && $item_taxonomy === $taxonomy) {
            return (string) ($item['menu_slug'] ?? '');
        }
    }

    return '';
}

function ll_tools_force_dashboard_parent_file($parent_file) {
    if (!is_admin()) {
        return $parent_file;
    }

    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return $parent_file;
    }

    $page = ll_tools_get_current_plugin_page_slug();
    $screen = get_current_screen();

    if (ll_tools_get_tools_hub_submenu_file($page, $screen) !== '') {
        return ll_tools_get_tools_hub_page_slug();
    }

    if (ll_tools_get_language_learning_submenu_file($page, $screen) !== '') {
        return ll_tools_get_admin_menu_slug();
    }

    return $parent_file;
}
add_filter('parent_file', 'll_tools_force_dashboard_parent_file', 9999);

function ll_tools_force_dashboard_submenu_file($submenu_file) {
    if (!is_admin()) {
        return $submenu_file;
    }

    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return $submenu_file;
    }

    $page = ll_tools_get_current_plugin_page_slug();
    $screen = get_current_screen();
    $tools_submenu = ll_tools_get_tools_hub_submenu_file($page, $screen);
    if ($tools_submenu !== '') {
        return $tools_submenu;
    }

    $language_submenu = ll_tools_get_language_learning_submenu_file($page, $screen);
    if ($language_submenu !== '') {
        return $language_submenu;
    }

    return $submenu_file;
}
add_filter('submenu_file', 'll_tools_force_dashboard_submenu_file', 9999);

function ll_tools_normalize_admin_title_for_dashboard_pages($admin_title, $title) {
    if (!is_admin()) {
        return $admin_title;
    }

    $page = ll_tools_get_current_plugin_page_slug();
    if ($page === '' || !ll_tools_is_dashboard_related_page_slug($page)) {
        return $admin_title;
    }

    if (is_string($admin_title) && trim($admin_title) !== '') {
        return $admin_title;
    }

    $page_title = is_string($title) ? trim($title) : '';
    if ($page_title === '') {
        $page_title = trim((string) ll_tools_get_dashboard_fallback_admin_page_title());
    }

    if ($page_title === '') {
        return 'WordPress';
    }

    $site_name = (string) get_bloginfo('name');
    if ($site_name === '') {
        return $page_title;
    }

    return sprintf(
        /* translators: 1: Admin page title, 2: Site name */
        __('%1$s ‹ %2$s — WordPress', 'll-tools-text-domain'),
        $page_title,
        $site_name
    );
}
add_filter('admin_title', 'll_tools_normalize_admin_title_for_dashboard_pages', 9999, 2);

function ll_tools_dashboard_menu_highlight_fallback(): void {
    if (!is_admin()) {
        return;
    }

    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return;
    }

    $page = ll_tools_get_current_plugin_page_slug();
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $target_submenu = ll_tools_get_tools_hub_submenu_file($page, $screen);
    if ($target_submenu === '') {
        return;
    }

    $top_level_id = 'toplevel_page_' . ll_tools_get_tools_hub_page_slug();
    $language_top_level_id = 'toplevel_page_' . ll_tools_get_admin_menu_slug();
    ?>
    <script>
    (function () {
        var llTop = document.getElementById('<?php echo esc_js($top_level_id); ?>');
        var llTopLink = llTop ? llTop.querySelector('a.menu-top') : null;
        var languageTop = document.getElementById('<?php echo esc_js($language_top_level_id); ?>');
        var languageTopLink = languageTop ? languageTop.querySelector('a.menu-top') : null;
        if (llTop) {
            llTop.classList.remove('wp-not-current-submenu');
            llTop.classList.add('wp-has-current-submenu', 'wp-menu-open', 'current', 'opensub');
        }
        if (llTopLink) {
            llTopLink.classList.remove('wp-not-current-submenu');
            llTopLink.classList.add('wp-has-current-submenu');
        }
        if (languageTop) {
            languageTop.classList.remove('wp-has-current-submenu', 'wp-menu-open', 'current', 'opensub');
        }
        if (languageTopLink) {
            languageTopLink.classList.remove('wp-has-current-submenu');
        }

        var wpTools = document.getElementById('menu-tools');
        var wpToolsLink = wpTools ? wpTools.querySelector('a.menu-top') : null;
        if (wpTools) {
            wpTools.classList.remove('wp-has-current-submenu', 'wp-menu-open', 'current', 'opensub');
        }
        if (wpToolsLink) {
            wpToolsLink.classList.remove('wp-has-current-submenu');
        }

        if (!llTop) {
            return;
        }

        var submenuItems = llTop.querySelectorAll('.wp-submenu li');
        for (var i = 0; i < submenuItems.length; i++) {
            submenuItems[i].classList.remove('current');
        }

        var submenuLinks = llTop.querySelectorAll('.wp-submenu a');
        for (var j = 0; j < submenuLinks.length; j++) {
            var href = submenuLinks[j].getAttribute('href') || '';
            if (href.indexOf('<?php echo esc_js($target_submenu); ?>') !== -1) {
                var item = submenuLinks[j].closest('li');
                if (item) {
                    item.classList.add('current');
                }
                break;
            }
        }
    })();
    </script>
    <?php
}
add_action('admin_footer', 'll_tools_dashboard_menu_highlight_fallback', 9999);
