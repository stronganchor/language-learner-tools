<?php
/**
 * Central admin menu for LL Tools.
 */

if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_get_admin_menu_slug')) {
    function ll_tools_get_admin_menu_slug() {
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

if (!function_exists('ll_tools_get_settings_related_page_slugs')) {
    function ll_tools_get_settings_related_page_slugs() {
        return [
            'deepl-api-key',
            'assemblyai-api-key',
            'language-learner-tools-languages',
            'll-recording-types',
        ];
    }
}

if (!function_exists('ll_tools_get_tools_hub_related_page_slugs')) {
    function ll_tools_get_tools_hub_related_page_slugs() {
        return [
            'll-audio-processor',
            'll-audio-image-matcher',
            'language-learner-tools-missing-audio',
            'll-bulk-word-import',
            'll-bulk-translations',
            'll-export-import',
            'll-ipa-keyboard',
            'll-word-option-rules',
            'll-fix-word-images',
            'll-audio-migration',
        ];
    }
}

function ll_tools_render_settings_page_menu_wrapper() {
    if (function_exists('ll_render_settings_page')) {
        ll_render_settings_page();
        return;
    }

    echo '<div class="wrap"><h1>' . esc_html__('Language Learning Tools', 'll-tools-text-domain') . '</h1>';
    echo '<p>' . esc_html__('Settings page is unavailable.', 'll-tools-text-domain') . '</p></div>';
}

function ll_tools_register_dashboard_menu() {
    $menu_slug = ll_tools_get_admin_menu_slug();
    global $submenu;

    add_menu_page(
        __('Language Learning Tools', 'll-tools-text-domain'),
        __('Language Learning Tools', 'll-tools-text-domain'),
        'view_ll_tools',
        $menu_slug,
        'll_tools_render_settings_page_menu_wrapper',
        'dashicons-translation',
        58
    );

    // Rename the auto-created first submenu (same slug as top-level) to "Settings"
    // without registering a second callback for the same screen hook.
    if (isset($submenu[$menu_slug][0][0])) {
        $submenu[$menu_slug][0][0] = __('Settings', 'll-tools-text-domain');
    }

    add_submenu_page(
        $menu_slug,
        __('Word Categories', 'll-tools-text-domain'),
        __('Word Categories', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit-tags.php?taxonomy=word-category&post_type=words'
    );

    add_submenu_page(
        $menu_slug,
        __('Word Sets', 'll-tools-text-domain'),
        __('Word Sets', 'll-tools-text-domain'),
        'edit_wordsets',
        'edit-tags.php?taxonomy=wordset&post_type=words'
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
        __('Vocab Lessons', 'll-tools-text-domain'),
        __('Vocab Lessons', 'll-tools-text-domain'),
        'view_ll_tools',
        'edit.php?post_type=ll_vocab_lesson'
    );

    add_submenu_page(
        $menu_slug,
        __('LL Tools Utilities', 'll-tools-text-domain'),
        __('Tools', 'll-tools-text-domain'),
        'view_ll_tools',
        ll_tools_get_tools_hub_page_slug(),
        'll_tools_render_tools_hub_page'
    );
}
add_action('admin_menu', 'll_tools_register_dashboard_menu', 9);

function ll_tools_render_tools_hub_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
    }

    $api_capability = function_exists('ll_tools_api_settings_capability')
        ? ll_tools_api_settings_capability()
        : 'manage_options';
    $export_import_capability = function_exists('ll_tools_get_export_import_capability')
        ? ll_tools_get_export_import_capability()
        : 'manage_options';

    $workflow_links = [
        [
            'label' => __('Audio Processor', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-audio-processor'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Audio/Image Matcher', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-audio-image-matcher'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Missing Audio', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=language-learner-tools-missing-audio'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Bulk Word Import', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-bulk-word-import'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Bulk Translations', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-bulk-translations'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Export / Import', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-export-import'),
            'cap' => $export_import_capability,
        ],
        [
            'label' => __('IPA Keyboard', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-ipa-keyboard'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Word Option Rules', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-word-option-rules'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('Fix Word Images', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-fix-word-images'),
            'cap' => 'manage_options',
        ],
        [
            'label' => __('Audio Migration', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-audio-migration'),
            'cap' => 'manage_options',
        ],
    ];

    $advanced_links = [
        [
            'label' => __('DeepL API Key', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=deepl-api-key'),
            'cap' => $api_capability,
        ],
        [
            'label' => __('AssemblyAI API Key', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=assemblyai-api-key'),
            'cap' => $api_capability,
        ],
        [
            'label' => __('Word Sets', 'll-tools-text-domain'),
            'url' => admin_url('edit-tags.php?taxonomy=wordset&post_type=words'),
            'cap' => 'edit_wordsets',
        ],
        [
            'label' => __('Parts of Speech', 'll-tools-text-domain'),
            'url' => admin_url('edit-tags.php?taxonomy=part_of_speech&post_type=words'),
            'cap' => 'view_ll_tools',
        ],
        [
            'label' => __('LL Tools Languages', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=language-learner-tools-languages'),
            'cap' => 'manage_options',
        ],
        [
            'label' => __('LL Recording Types', 'll-tools-text-domain'),
            'url' => admin_url('tools.php?page=ll-recording-types'),
            'cap' => 'manage_options',
        ],
    ];

    echo '<div class="wrap">';
    echo '<h1>' . esc_html__('LL Tools Utilities', 'll-tools-text-domain') . '</h1>';
    echo '<p class="description">' . esc_html__('Quick access to lower-frequency tools and maintenance workflows.', 'll-tools-text-domain') . '</p>';

    echo '<h2>' . esc_html__('Workflows', 'll-tools-text-domain') . '</h2>';
    echo '<p>';
    foreach ($workflow_links as $link) {
        if (!current_user_can((string) $link['cap'])) {
            continue;
        }
        echo '<a class="button button-secondary" style="margin:0 8px 8px 0;" href="'
            . esc_url((string) $link['url']) . '">'
            . esc_html((string) $link['label']) . '</a>';
    }
    echo '</p>';

    echo '<h2>' . esc_html__('Advanced', 'll-tools-text-domain') . '</h2>';
    echo '<p>';
    foreach ($advanced_links as $link) {
        if (!current_user_can((string) $link['cap'])) {
            continue;
        }
        echo '<a class="button" style="margin:0 8px 8px 0;" href="'
            . esc_url((string) $link['url']) . '">'
            . esc_html((string) $link['label']) . '</a>';
    }
    echo '</p>';

    echo '<p class="description">' . esc_html__('Duplicate Category is intentionally hidden from navigation and available from the Duplicate row action on Word Categories.', 'll-tools-text-domain') . '</p>';
    echo '</div>';
}

function ll_tools_hide_legacy_admin_menu_entries() {
    $settings_slug = ll_tools_get_admin_menu_slug();

    remove_submenu_page('options-general.php', $settings_slug);

    foreach (array_merge(ll_tools_get_settings_related_page_slugs(), ll_tools_get_tools_hub_related_page_slugs()) as $page_slug) {
        remove_submenu_page('tools.php', $page_slug);
    }

    // Hide old top-level post type menus; they are now reachable from LL Tools.
    remove_menu_page('edit.php?post_type=words');
    remove_menu_page('edit.php?post_type=word_images');
    remove_menu_page('edit.php?post_type=ll_vocab_lesson');
}
add_action('admin_menu', 'll_tools_hide_legacy_admin_menu_entries', 999);

function ll_tools_force_dashboard_parent_file($parent_file) {
    if (!is_admin()) {
        return $parent_file;
    }

    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return $parent_file;
    }

    $screen = get_current_screen();
    if (!($screen instanceof WP_Screen)) {
        return $parent_file;
    }

    $menu_slug = ll_tools_get_admin_menu_slug();
    $tracked_post_types = ['words', 'word_images', 'word_audio', 'll_dictionary_entry', 'll_vocab_lesson'];
    if (!empty($screen->post_type) && in_array($screen->post_type, $tracked_post_types, true)) {
        return $menu_slug;
    }

    if (!empty($screen->taxonomy) && $screen->taxonomy === 'word-category') {
        return $menu_slug;
    }
    if (!empty($screen->taxonomy) && $screen->taxonomy === 'wordset') {
        return $menu_slug;
    }

    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    if ($page === '') {
        return $parent_file;
    }

    if (
        $page === $menu_slug
        || $page === ll_tools_get_tools_hub_page_slug()
        || in_array($page, ll_tools_get_settings_related_page_slugs(), true)
        || in_array($page, ll_tools_get_tools_hub_related_page_slugs(), true)
    ) {
        return $menu_slug;
    }

    return $parent_file;
}
add_filter('parent_file', 'll_tools_force_dashboard_parent_file');

function ll_tools_force_dashboard_submenu_file($submenu_file) {
    if (!is_admin()) {
        return $submenu_file;
    }

    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return $submenu_file;
    }

    $screen = get_current_screen();
    if (!($screen instanceof WP_Screen)) {
        return $submenu_file;
    }

    if (!empty($screen->taxonomy) && $screen->taxonomy === 'word-category') {
        return 'edit-tags.php?taxonomy=word-category&post_type=words';
    }
    if (!empty($screen->taxonomy) && $screen->taxonomy === 'wordset') {
        return 'edit-tags.php?taxonomy=wordset&post_type=words';
    }

    if (!empty($screen->post_type)) {
        $map = [
            'words' => 'edit.php?post_type=words',
            'word_images' => 'edit.php?post_type=word_images',
            'word_audio' => 'edit.php?post_type=word_audio',
            'll_dictionary_entry' => 'edit.php?post_type=ll_dictionary_entry',
            'll_vocab_lesson' => 'edit.php?post_type=ll_vocab_lesson',
        ];
        if (isset($map[$screen->post_type])) {
            return $map[$screen->post_type];
        }
    }

    $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
    if ($page === '') {
        return $submenu_file;
    }

    if ($page === ll_tools_get_tools_hub_page_slug() || in_array($page, ll_tools_get_tools_hub_related_page_slugs(), true)) {
        return ll_tools_get_tools_hub_page_slug();
    }

    if ($page === ll_tools_get_admin_menu_slug() || in_array($page, ll_tools_get_settings_related_page_slugs(), true)) {
        return ll_tools_get_admin_menu_slug();
    }

    return $submenu_file;
}
add_filter('submenu_file', 'll_tools_force_dashboard_submenu_file');
