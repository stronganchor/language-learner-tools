<?php
/**
 * Admin page for managing recording transcription symbols by word set.
 */

if (!defined('WPINC')) { die; }

function ll_register_ipa_keyboard_admin_page() {
    add_submenu_page(
        'tools.php',
        __('Language Learner Tools - Transcription Manager', 'll-tools-text-domain'),
        __('Transcription Manager', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-ipa-keyboard',
        'll_render_ipa_keyboard_admin_page'
    );
}
add_action('admin_menu', 'll_register_ipa_keyboard_admin_page');

function ll_tools_ipa_keyboard_last_wordset_meta_key(): string {
    return 'll_tools_transcription_manager_last_wordset_id';
}

function ll_tools_ipa_keyboard_get_default_wordset_id(): int {
    if (function_exists('ll_tools_get_active_wordset_id')) {
        $active_wordset_id = (int) ll_tools_get_active_wordset_id();
        if ($active_wordset_id > 0) {
            return $active_wordset_id;
        }
    }

    if (function_exists('ll_get_default_wordset_term_id')) {
        $default_wordset_id = (int) ll_get_default_wordset_term_id();
        if ($default_wordset_id > 0) {
            return $default_wordset_id;
        }
    }

    return 0;
}

function ll_tools_ipa_keyboard_get_available_wordsets(): array {
    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (is_wp_error($wordsets) || empty($wordsets)) {
        return [];
    }

    if (current_user_can('manage_options')) {
        return array_values(array_filter($wordsets, static function ($wordset): bool {
            return $wordset instanceof WP_Term;
        }));
    }

    return array_values(array_filter($wordsets, static function ($wordset): bool {
        return ($wordset instanceof WP_Term)
            && (!function_exists('ll_tools_user_can_view_wordset') || ll_tools_user_can_view_wordset((int) $wordset->term_id));
    }));
}

function ll_tools_ipa_keyboard_wordset_is_available(int $wordset_id, array $wordsets): bool {
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        return false;
    }

    foreach ($wordsets as $wordset) {
        if ($wordset instanceof WP_Term && (int) $wordset->term_id === $wordset_id) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_get_last_wordset_id(): int {
    if (!is_user_logged_in()) {
        return 0;
    }

    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
        return 0;
    }

    $wordset_id = (int) get_user_meta($user_id, ll_tools_ipa_keyboard_last_wordset_meta_key(), true);
    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        if ($wordset_id > 0) {
            delete_user_meta($user_id, ll_tools_ipa_keyboard_last_wordset_meta_key());
        }
        return 0;
    }

    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_id, $user_id)) {
        delete_user_meta($user_id, ll_tools_ipa_keyboard_last_wordset_meta_key());
        return 0;
    }

    return $wordset_id;
}

function ll_tools_ipa_keyboard_remember_wordset(int $wordset_id): void {
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
        return;
    }

    if ($wordset_id <= 0 || !term_exists($wordset_id, 'wordset')) {
        delete_user_meta($user_id, ll_tools_ipa_keyboard_last_wordset_meta_key());
        return;
    }

    if (function_exists('ll_tools_user_can_view_wordset') && !ll_tools_user_can_view_wordset($wordset_id, $user_id)) {
        return;
    }

    update_user_meta($user_id, ll_tools_ipa_keyboard_last_wordset_meta_key(), $wordset_id);
}

function ll_tools_ipa_keyboard_resolve_wordset_id(array $wordsets, int $requested_wordset_id = 0): int {
    $candidate_ids = [];
    if ($requested_wordset_id > 0) {
        $candidate_ids[] = $requested_wordset_id;
    }

    $last_wordset_id = ll_tools_ipa_keyboard_get_last_wordset_id();
    if ($last_wordset_id > 0) {
        $candidate_ids[] = $last_wordset_id;
    }

    $default_wordset_id = ll_tools_ipa_keyboard_get_default_wordset_id();
    if ($default_wordset_id > 0) {
        $candidate_ids[] = $default_wordset_id;
    }

    foreach ($wordsets as $wordset) {
        if ($wordset instanceof WP_Term) {
            $candidate_ids[] = (int) $wordset->term_id;
            break;
        }
    }

    $candidate_ids = array_values(array_unique(array_map('intval', $candidate_ids)));
    foreach ($candidate_ids as $candidate_id) {
        if (ll_tools_ipa_keyboard_wordset_is_available($candidate_id, $wordsets)) {
            return $candidate_id;
        }
    }

    return 0;
}

function ll_tools_ipa_keyboard_get_requested_tab(): string {
    $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : 'map';
    return in_array($tab, ['map', 'symbols', 'search'], true) ? $tab : 'map';
}

function ll_tools_ipa_keyboard_get_requested_search_state(): array {
    $query = isset($_GET['search']) ? sanitize_text_field(wp_unslash((string) $_GET['search'])) : '';
    $scope = isset($_GET['scope']) ? sanitize_key((string) wp_unslash($_GET['scope'])) : 'both';
    if (!in_array($scope, ['written', 'transcription', 'both'], true)) {
        $scope = 'both';
    }

    $issues_only = isset($_GET['issues'])
        ? (sanitize_text_field(wp_unslash((string) $_GET['issues'])) === '1')
        : ($query === '');

    return [
        'query' => $query,
        'scope' => $scope,
        'issues_only' => $issues_only,
    ];
}

function ll_tools_ipa_keyboard_current_user_can_view_wordset(int $wordset_id): bool {
    if (!current_user_can('view_ll_tools') || $wordset_id <= 0) {
        return false;
    }

    return !function_exists('ll_tools_user_can_view_wordset') || ll_tools_user_can_view_wordset($wordset_id);
}

function ll_tools_ipa_keyboard_current_user_can_edit_wordset(int $wordset_id): bool {
    if ($wordset_id <= 0 || !current_user_can('view_ll_tools')) {
        return false;
    }

    if (function_exists('ll_tools_user_can_edit_vocab_words')) {
        return ll_tools_user_can_edit_vocab_words($wordset_id);
    }

    return current_user_can('manage_options');
}

function ll_enqueue_ipa_keyboard_admin_assets($hook) {
    if ($hook !== 'tools_page_ll-ipa-keyboard') {
        return;
    }

    $wordsets = ll_tools_ipa_keyboard_get_available_wordsets();
    $selected_wordset_id = ll_tools_ipa_keyboard_resolve_wordset_id(
        $wordsets,
        isset($_GET['wordset_id']) ? (int) $_GET['wordset_id'] : 0
    );
    $initial_search = ll_tools_ipa_keyboard_get_requested_search_state();

    ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-ipa-fonts');
    ll_enqueue_asset_by_timestamp('/css/ipa-keyboard-admin.css', 'll-ipa-keyboard-admin-css', ['ll-ipa-fonts']);
    ll_enqueue_asset_by_timestamp('/js/ipa-keyboard-admin.js', 'll-ipa-keyboard-admin-js', ['jquery'], true);

    wp_localize_script('ll-ipa-keyboard-admin-js', 'llIpaKeyboardAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
        'selectedWordsetId' => $selected_wordset_id,
        'initialTab' => ll_tools_ipa_keyboard_get_requested_tab(),
        'initialSearch' => $initial_search,
        'i18n' => [
            'loading' => __('Loading transcription data...', 'll-tools-text-domain'),
            'empty' => __('No special characters found for this word set.', 'll-tools-text-domain'),
            'saving' => __('Saving...', 'll-tools-text-domain'),
            'saved' => __('Saved.', 'll-tools-text-domain'),
            'error' => __('Something went wrong. Please try again.', 'll-tools-text-domain'),
            'addSuccess' => __('Characters added.', 'll-tools-text-domain'),
            'selectWordset' => __('Select a word set first.', 'll-tools-text-domain'),
            'enterSymbols' => __('Enter one or more characters to add.', 'll-tools-text-domain'),
            'noRecordings' => __('No recordings use this character yet.', 'll-tools-text-domain'),
            'noWordsets' => __('No word sets are available for this page.', 'll-tools-text-domain'),
            'save' => __('Save', 'll-tools-text-domain'),
            'mapEmpty' => __('No letter mappings found for this word set.', 'll-tools-text-domain'),
            'mapAutoEmpty' => __('No mappings yet.', 'll-tools-text-domain'),
            'mapLetterLabel' => __('Letter(s)', 'll-tools-text-domain'),
            'mapAutoLabel' => __('Auto map', 'll-tools-text-domain'),
            'mapManualLabel' => __('Manual override', 'll-tools-text-domain'),
            'mapPlaceholder' => __('e.g. r', 'll-tools-text-domain'),
            'mapClear' => __('Clear', 'll-tools-text-domain'),
            'mapSamplesLabel' => __('Examples', 'll-tools-text-domain'),
            'mapSampleTextLabel' => __('Text:', 'll-tools-text-domain'),
            'pronunciationLabel' => __('Pronunciation', 'll-tools-text-domain'),
            'wordColumnLabel' => __('Word', 'll-tools-text-domain'),
            'recordingColumnLabel' => __('Recording', 'll-tools-text-domain'),
            'textColumnLabel' => __('Text', 'll-tools-text-domain'),
            'recordingCountSingular' => __('%1$d recording', 'll-tools-text-domain'),
            'recordingCountPlural' => __('%1$d recordings', 'll-tools-text-domain'),
            'occurrenceCountSingular' => __('%1$d occurrence', 'll-tools-text-domain'),
            'occurrenceCountPlural' => __('%1$d occurrences', 'll-tools-text-domain'),
            'untitled' => __('(Untitled)', 'll-tools-text-domain'),
            'mapBlockLabel' => __('Block mapping', 'll-tools-text-domain'),
            'mapBlockedTitle' => __('Blocked mappings', 'll-tools-text-domain'),
            'mapUnblockLabel' => __('Undo', 'll-tools-text-domain'),
            'mapAddLabel' => __('Add manual mapping', 'll-tools-text-domain'),
            'mapAddLettersLabel' => __('Letters', 'll-tools-text-domain'),
            'mapAddLettersPlaceholder' => __('Letters (e.g. ll)', 'll-tools-text-domain'),
            'mapAddHint' => __('Use multiple letters to map digraphs like ll.', 'll-tools-text-domain'),
            'mapAdd' => __('Add mapping', 'll-tools-text-domain'),
            'mapAddMissing' => __('Enter letters and characters to add.', 'll-tools-text-domain'),
            'playRecording' => __('Play recording', 'll-tools-text-domain'),
            'tabMap' => __('Letter to IPA Map', 'll-tools-text-domain'),
            'tabSymbols' => __('IPA Special Characters', 'll-tools-text-domain'),
            'tabSearch' => __('Search', 'll-tools-text-domain'),
            'searchLoading' => __('Searching recordings...', 'll-tools-text-domain'),
            'searchResultsEmpty' => __('No recordings matched this search.', 'll-tools-text-domain'),
            'searchSummary' => __('%1$d result', 'll-tools-text-domain'),
            'searchSummaryPlural' => __('%1$d results', 'll-tools-text-domain'),
            'searchFilteredSummary' => __('Showing %1$d flagged recording', 'll-tools-text-domain'),
            'searchFilteredSummaryPlural' => __('Showing %1$d flagged recordings', 'll-tools-text-domain'),
            'searchTooMany' => __('Showing the first %1$d results. Narrow the search to see more.', 'll-tools-text-domain'),
            'searchWordLabel' => __('Word', 'll-tools-text-domain'),
            'searchImageLabel' => __('Image', 'll-tools-text-domain'),
            'searchCategoriesLabel' => __('Categories', 'll-tools-text-domain'),
            'searchIssuesLabel' => __('Checks', 'll-tools-text-domain'),
            'searchNoImage' => __('No image', 'll-tools-text-domain'),
            'searchNoCategories' => __('No categories', 'll-tools-text-domain'),
            'searchNoIssues' => __('No warnings', 'll-tools-text-domain'),
            'searchIgnoredLabel' => __('Ignored', 'll-tools-text-domain'),
            'searchReviewIssues' => __('Review warnings', 'll-tools-text-domain'),
            'searchPatternHint' => __('Use * as a wildcard.', 'll-tools-text-domain'),
            'searchRulesTitle' => __('Wordset-specific IPA checks', 'll-tools-text-domain'),
            'searchRulesDescription' => __('Add sounds that should never appear in this word set, or ban sounds in specific immediate environments.', 'll-tools-text-domain'),
            'searchBuiltinsTitle' => __('Standard IPA checks', 'll-tools-text-domain'),
            'searchRulesEmpty' => __('No custom IPA checks for this word set yet.', 'll-tools-text-domain'),
            'searchRulesUnavailable' => __('Custom IPA checks are only available when this word set uses IPA transcription mode.', 'll-tools-text-domain'),
            'searchRuleLabel' => __('Label', 'll-tools-text-domain'),
            'searchRuleTarget' => __('Sound', 'll-tools-text-domain'),
            'searchRulePrevious' => __('Previous', 'll-tools-text-domain'),
            'searchRuleNext' => __('Next', 'll-tools-text-domain'),
            'searchRuleLabelPlaceholder' => __('Optional note', 'll-tools-text-domain'),
            'searchRuleTargetPlaceholder' => __('e.g. t', 'll-tools-text-domain'),
            'searchRulePreviousPlaceholder' => __('Previous sound(s)', 'll-tools-text-domain'),
            'searchRuleNextPlaceholder' => __('Next sound(s)', 'll-tools-text-domain'),
            'searchRuleHint' => __('Leave Previous and Next empty to ban a sound everywhere. Separate multiple sounds with spaces.', 'll-tools-text-domain'),
            'searchRuleAdd' => __('Add check', 'll-tools-text-domain'),
            'searchRuleRemove' => __('Remove', 'll-tools-text-domain'),
            'searchRuleMissingTarget' => __('Enter a sound to check.', 'll-tools-text-domain'),
            'searchRuleSave' => __('Save checks', 'll-tools-text-domain'),
            'searchRuleSaved' => __('Checks saved and rescanned.', 'll-tools-text-domain'),
            'searchRuleRescanning' => __('Saving checks and rescanning this word set...', 'll-tools-text-domain'),
            'searchOpenWord' => __('Open word', 'll-tools-text-domain'),
            'searchUnknownCategory' => __('Unknown category', 'll-tools-text-domain'),
            'searchOpenCategory' => __('Open category', 'll-tools-text-domain'),
            'searchExceptionIgnore' => __('Ignore for this transcription', 'll-tools-text-domain'),
            'searchExceptionRestore' => __('Undo exception', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_ipa_keyboard_admin_assets');

function ll_render_ipa_keyboard_admin_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'll-tools-text-domain'));
    }

    $wordsets = ll_tools_ipa_keyboard_get_available_wordsets();
    $selected_wordset_id = ll_tools_ipa_keyboard_resolve_wordset_id(
        $wordsets,
        isset($_GET['wordset_id']) ? (int) $_GET['wordset_id'] : 0
    );
    $initial_tab = ll_tools_ipa_keyboard_get_requested_tab();
    $initial_search = ll_tools_ipa_keyboard_get_requested_search_state();

    echo '<div class="wrap ll-ipa-admin" data-ll-secondary-text-mode="ipa" data-ll-initial-tab="' . esc_attr($initial_tab) . '">';
    echo '<h1 id="ll-ipa-admin-title">' . esc_html__('Transcription Manager', 'll-tools-text-domain') . '</h1>';
    echo '<p class="description">' . esc_html__('Manage letter maps, IPA helper characters, searches, and typo checks for each word set.', 'll-tools-text-domain') . '</p>';

    echo '<div class="ll-ipa-admin-toolbar">';
    echo '<div class="ll-ipa-admin-controls">';
    echo '<label for="ll-ipa-wordset">' . esc_html__('Word set', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-ipa-wordset" class="ll-ipa-wordset-select">';
    if (empty($wordsets)) {
        echo '<option value="">' . esc_html__('No available word sets', 'll-tools-text-domain') . '</option>';
    } else {
        foreach ($wordsets as $wordset) {
            if (!$wordset instanceof WP_Term) {
                continue;
            }
            echo '<option value="' . esc_attr($wordset->term_id) . '"' . selected($selected_wordset_id, (int) $wordset->term_id, false) . '>' . esc_html($wordset->name) . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="ll-ipa-tabs" role="tablist" aria-label="' . esc_attr__('Transcription Manager sections', 'll-tools-text-domain') . '">';
    echo '<button type="button" class="ll-ipa-tab-button" id="ll-ipa-tab-map" data-ll-tab-trigger="map" role="tab" aria-controls="ll-ipa-panel-map" aria-selected="' . esc_attr($initial_tab === 'map' ? 'true' : 'false') . '">' . esc_html__('Letter to IPA Map', 'll-tools-text-domain') . '</button>';
    echo '<button type="button" class="ll-ipa-tab-button" id="ll-ipa-tab-symbols" data-ll-tab-trigger="symbols" role="tab" aria-controls="ll-ipa-panel-symbols" aria-selected="' . esc_attr($initial_tab === 'symbols' ? 'true' : 'false') . '">' . esc_html__('IPA Special Characters', 'll-tools-text-domain') . '</button>';
    echo '<button type="button" class="ll-ipa-tab-button" id="ll-ipa-tab-search" data-ll-tab-trigger="search" role="tab" aria-controls="ll-ipa-panel-search" aria-selected="' . esc_attr($initial_tab === 'search' ? 'true' : 'false') . '">' . esc_html__('Search', 'll-tools-text-domain') . '</button>';
    echo '</div>';
    echo '</div>';

    echo '<div id="ll-ipa-admin-status" class="ll-ipa-admin-status" role="status" aria-live="polite"></div>';
    echo '<div class="ll-ipa-admin-panels">';

    echo '<section class="ll-ipa-panel" id="ll-ipa-panel-map" data-ll-tab-panel="map" role="tabpanel" aria-labelledby="ll-ipa-tab-map"' . ($initial_tab === 'map' ? '' : ' hidden') . '>';
    echo '<h2 id="ll-ipa-letter-map-heading">' . esc_html__('Letter to IPA Map', 'll-tools-text-domain') . '</h2>';
    echo '<p id="ll-ipa-letter-map-description" class="description">' . esc_html__('Mappings inferred from this word set. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-letter-map" class="ll-ipa-letter-map"></div>';
    echo '</section>';

    echo '<section class="ll-ipa-panel" id="ll-ipa-panel-symbols" data-ll-tab-panel="symbols" role="tabpanel" aria-labelledby="ll-ipa-tab-symbols"' . ($initial_tab === 'symbols' ? '' : ' hidden') . '>';
    echo '<h2 id="ll-ipa-symbols-heading">' . esc_html__('IPA Special Characters', 'll-tools-text-domain') . '</h2>';
    echo '<p id="ll-ipa-symbols-description" class="description">' . esc_html__('Characters used in this word set. Update recordings or add new characters to the keyboard.', 'll-tools-text-domain') . '</p>';
    echo '<div class="ll-ipa-admin-add">';
    echo '<label id="ll-ipa-add-label" for="ll-ipa-add-input">' . esc_html__('Add characters', 'll-tools-text-domain') . '</label>';
    echo '<input type="text" id="ll-ipa-add-input" class="ll-ipa-add-input" placeholder="' . esc_attr__('e.g. IPA symbols', 'll-tools-text-domain') . '" />';
    echo '<button type="button" class="button button-secondary" id="ll-ipa-add-btn">' . esc_html__('Add', 'll-tools-text-domain') . '</button>';
    echo '</div>';
    echo '<div id="ll-ipa-symbols" class="ll-ipa-symbols"></div>';
    echo '</section>';

    echo '<section class="ll-ipa-panel" id="ll-ipa-panel-search" data-ll-tab-panel="search" role="tabpanel" aria-labelledby="ll-ipa-tab-search"' . ($initial_tab === 'search' ? '' : ' hidden') . '>';
    echo '<h2>' . esc_html__('Search and Checks', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Search written text or IPA, edit recordings inline, and review likely typo warnings.', 'll-tools-text-domain') . '</p>';
    echo '<div class="ll-ipa-search-controls">';
    echo '<label class="screen-reader-text" for="ll-ipa-search-query">' . esc_html__('Search recordings', 'll-tools-text-domain') . '</label>';
    echo '<input type="search" id="ll-ipa-search-query" class="ll-ipa-search-input" value="' . esc_attr($initial_search['query']) . '" placeholder="' . esc_attr__('Search written text or IPA', 'll-tools-text-domain') . '" />';
    echo '<label class="screen-reader-text" for="ll-ipa-search-scope">' . esc_html__('Search field', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-ipa-search-scope" class="ll-ipa-search-scope">';
    echo '<option value="both"' . selected($initial_search['scope'], 'both', false) . '>' . esc_html__('Written or transcription', 'll-tools-text-domain') . '</option>';
    echo '<option value="written"' . selected($initial_search['scope'], 'written', false) . '>' . esc_html__('Written text only', 'll-tools-text-domain') . '</option>';
    echo '<option value="transcription"' . selected($initial_search['scope'], 'transcription', false) . '>' . esc_html__('Transcription only', 'll-tools-text-domain') . '</option>';
    echo '</select>';
    echo '<label class="ll-ipa-search-toggle">';
    echo '<input type="checkbox" id="ll-ipa-search-issues-only"' . checked(!empty($initial_search['issues_only']), true, false) . ' />';
    echo '<span>' . esc_html__('Only flagged typos', 'll-tools-text-domain') . '</span>';
    echo '</label>';
    echo '<button type="button" class="button button-primary" id="ll-ipa-search-btn">' . esc_html__('Search', 'll-tools-text-domain') . '</button>';
    echo '</div>';
    echo '<p class="ll-ipa-search-hint description">' . esc_html__('Use * as a wildcard when needed. Leave the query empty to review flagged recordings in this word set.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-search-summary" class="ll-ipa-search-summary" aria-live="polite"></div>';
    echo '<div id="ll-ipa-search-rules" class="ll-ipa-search-rules"></div>';
    echo '<div id="ll-ipa-search-results" class="ll-ipa-search-results"></div>';
    echo '</section>';

    echo '</div>';
    echo '</div>';
}

function ll_tools_ipa_keyboard_get_transcription_config(int $wordset_id = 0): array {
    if (function_exists('ll_tools_get_wordset_recording_transcription_config')) {
        return ll_tools_get_wordset_recording_transcription_config($wordset_id > 0 ? [$wordset_id] : [], true);
    }

    return [
        'mode' => 'ipa',
        'label' => __('IPA', 'll-tools-text-domain'),
        'display_format' => 'brackets',
        'uses_ipa_font' => true,
        'supports_superscript' => true,
        'common_chars' => ['t͡ʃ', 'd͡ʒ', 'ʃ', 'ˈ'],
        'common_chars_label' => __('Common IPA symbols', 'll-tools-text-domain'),
        'wordset_chars_label' => __('Wordset IPA symbols', 'll-tools-text-domain'),
        'special_chars_heading' => __('IPA Special Characters', 'll-tools-text-domain'),
        'special_chars_empty' => __('No IPA symbols found for this word set.', 'll-tools-text-domain'),
        'special_chars_add_label' => __('Add symbols', 'll-tools-text-domain'),
        'special_chars_add_placeholder' => __('e.g. IPA symbols', 'll-tools-text-domain'),
        'special_chars_description' => __('Symbols used in this word set. Update recordings or add new symbols to the keyboard.', 'll-tools-text-domain'),
        'symbols_column_label' => __('IPA', 'll-tools-text-domain'),
        'map_heading' => __('Letter to IPA Map', 'll-tools-text-domain'),
        'map_description' => __('Mappings inferred from transcriptions. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain'),
        'map_sample_value_label' => __('IPA:', 'll-tools-text-domain'),
        'map_add_symbols_label' => __('IPA symbols', 'll-tools-text-domain'),
        'map_add_symbols_placeholder' => __('IPA symbols (e.g. r)', 'll-tools-text-domain'),
        'map_add_missing' => __('Enter letters and IPA symbols to add.', 'll-tools-text-domain'),
    ];
}

function ll_tools_ipa_keyboard_get_word_ids_for_wordset(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $word_ids = get_posts([
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => 'wordset',
                'field' => 'term_id',
                'terms' => $wordset_id,
            ],
        ],
    ]);

    return array_values(array_unique(array_map('intval', $word_ids)));
}

function ll_tools_ipa_keyboard_get_default_symbols(int $wordset_id = 0): array {
    $config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    return array_values(array_filter(array_map('strval', (array) ($config['common_chars'] ?? []))));
}

function ll_tools_ipa_keyboard_get_word_display_map(array $word_ids): array {
    $map = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_resolve_display_text')) {
            $display = ll_tools_word_grid_resolve_display_text($word_id);
            $map[$word_id] = [
                'word_text' => (string) ($display['word_text'] ?? ''),
                'translation' => (string) ($display['translation_text'] ?? ''),
            ];
        } else {
            $map[$word_id] = [
                'word_text' => get_the_title($word_id),
                'translation' => (string) get_post_meta($word_id, 'word_translation', true),
            ];
        }
    }
    return $map;
}

function ll_tools_ipa_keyboard_get_word_image_payload(int $word_id): array {
    $fallback = [
        'url' => '',
        'alt' => '',
    ];

    if ($word_id <= 0) {
        return $fallback;
    }

    if (function_exists('ll_tools_word_grid_get_image_data_for_word')) {
        $image = (array) ll_tools_word_grid_get_image_data_for_word($word_id);
        return [
            'url' => (string) ($image['url'] ?? ''),
            'alt' => (string) ($image['alt'] ?? ''),
        ];
    }

    $attachment_id = (int) get_post_thumbnail_id($word_id);
    if ($attachment_id <= 0) {
        return $fallback;
    }

    return [
        'url' => (string) (wp_get_attachment_image_url($attachment_id, 'thumbnail') ?: ''),
        'alt' => (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
    ];
}

function ll_tools_ipa_keyboard_get_word_category_payload(int $word_id): array {
    if ($word_id <= 0) {
        return [];
    }

    $terms = wp_get_post_terms($word_id, 'word-category');
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $categories = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }

        $categories[] = [
            'id' => (int) $term->term_id,
            'name' => function_exists('ll_tools_get_category_display_name')
                ? (string) ll_tools_get_category_display_name($term)
                : (string) $term->name,
            'edit_url' => (string) get_edit_term_link($term, 'word-category', 'words'),
        ];
    }

    return $categories;
}

function ll_tools_ipa_keyboard_count_special_symbols(string $recording_ipa, string $transcription_mode = 'ipa'): array {
    if (function_exists('ll_tools_word_grid_normalize_ipa_output')) {
        $recording_ipa = ll_tools_word_grid_normalize_ipa_output($recording_ipa, $transcription_mode);
    } else {
        $recording_ipa = trim($recording_ipa);
    }

    if ($recording_ipa === '') {
        return [];
    }

    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($recording_ipa, $transcription_mode)
        : preg_split('//u', $recording_ipa, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($tokens)) {
        return [];
    }

    $counts = [];
    foreach ($tokens as $token) {
        $token = (string) $token;
        if ($token === '') {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_special_ipa_token')
            && !ll_tools_word_grid_is_special_ipa_token($token, $transcription_mode)) {
            continue;
        }
        $counts[$token] = (int) ($counts[$token] ?? 0) + 1;
    }

    return $counts;
}

function ll_tools_ipa_keyboard_build_recording_payload(int $recording_id, int $word_id, array $word_info, string $recording_ipa = ''): array {
    $recording_type_terms = wp_get_post_terms($recording_id, 'recording_type');
    $recording_type = '';
    $recording_type_slug = '';
    if (!is_wp_error($recording_type_terms) && !empty($recording_type_terms) && $recording_type_terms[0] instanceof WP_Term) {
        $recording_type = (string) $recording_type_terms[0]->name;
        $recording_type_slug = sanitize_key((string) $recording_type_terms[0]->slug);
    }

    $recording_icon_type = 'isolation';
    if (in_array($recording_type_slug, ['question', 'isolation', 'introduction'], true)) {
        $recording_icon_type = $recording_type_slug;
    } elseif (in_array($recording_type_slug, ['sentence', 'in-sentence', 'in_sentence', 'insentence'], true)) {
        $recording_icon_type = 'sentence';
    }

    $audio_url = '';
    if (function_exists('ll_tools_word_grid_get_recording_audio_url')) {
        $audio_url = (string) ll_tools_word_grid_get_recording_audio_url($recording_id);
    } else {
        $audio_path = trim((string) get_post_meta($recording_id, 'audio_file_path', true));
        if ($audio_path !== '') {
            $audio_url = function_exists('ll_tools_resolve_audio_file_url')
                ? (string) ll_tools_resolve_audio_file_url($audio_path)
                : ((0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path));
        }
    }

    $recording_label = $recording_type !== '' ? $recording_type : __('Recording', 'll-tools-text-domain');
    $audio_label = sprintf(
        /* translators: %s: recording type label */
        __('Play %s recording', 'll-tools-text-domain'),
        $recording_label
    );

    return [
        'recording_id' => $recording_id,
        'word_id' => (int) $word_id,
        'word_text' => (string) ($word_info['word_text'] ?? ''),
        'word_translation' => (string) ($word_info['translation'] ?? ''),
        'recording_type' => $recording_type,
        'recording_type_slug' => $recording_type_slug,
        'recording_icon_type' => $recording_icon_type,
        'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
        'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
        'recording_ipa' => (string) $recording_ipa,
        'audio_url' => $audio_url,
        'audio_label' => $audio_label,
        'word_edit_link' => get_edit_post_link($word_id, 'raw'),
        'image' => ll_tools_ipa_keyboard_get_word_image_payload($word_id),
        'categories' => ll_tools_ipa_keyboard_get_word_category_payload($word_id),
    ];
}

function ll_tools_ipa_keyboard_build_symbol_data(int $wordset_id): array {
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [];
    }
    $transcription_config = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    $transcription_mode = (string) ($transcription_config['mode'] ?? 'ipa');

    $word_display = ll_tools_ipa_keyboard_get_word_display_map($word_ids);

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
    ]);

    $counts = [];
    $recordings_by_symbol = [];

    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $word_id = wp_get_post_parent_id($recording_id);
        if (!$word_id) {
            continue;
        }
        $recording_ipa_raw = (string) get_post_meta($recording_id, 'recording_ipa', true);
        $recording_ipa = ll_tools_word_grid_normalize_ipa_output($recording_ipa_raw, $transcription_mode);
        $special_counts = ll_tools_ipa_keyboard_count_special_symbols($recording_ipa, $transcription_mode);
        if (empty($special_counts)) {
            continue;
        }

        $word_info = $word_display[$word_id] ?? ['word_text' => '', 'translation' => ''];
        $recording_payload = ll_tools_ipa_keyboard_build_recording_payload($recording_id, $word_id, $word_info, $recording_ipa);

        foreach ($special_counts as $token => $token_count) {
            $counts[$token] = (int) ($counts[$token] ?? 0) + max(1, (int) $token_count);
            if (!isset($recordings_by_symbol[$token])) {
                $recordings_by_symbol[$token] = [];
            }
            $recordings_by_symbol[$token][] = $recording_payload;
        }
    }

    $manual_symbols = function_exists('ll_tools_word_grid_get_wordset_ipa_manual_symbols')
        ? ll_tools_word_grid_get_wordset_ipa_manual_symbols($wordset_id)
        : [];

    $default_symbols = ll_tools_ipa_keyboard_get_default_symbols($wordset_id);
    $symbols = array_values(array_unique(array_merge($default_symbols, array_keys($counts), $manual_symbols)));
    $entries = [];
    foreach ($symbols as $symbol) {
        $symbol = (string) $symbol;
        if ($symbol === '') {
            continue;
        }
        $recordings = $recordings_by_symbol[$symbol] ?? [];
        $entries[] = [
            'symbol' => $symbol,
            'count' => (int) ($counts[$symbol] ?? 0),
            'recording_count' => count($recordings),
            'recordings' => $recordings,
        ];
    }

    usort($entries, function ($a, $b) use ($transcription_mode) {
        $symbol_a = (string) ($a['symbol'] ?? '');
        $symbol_b = (string) ($b['symbol'] ?? '');
        if (function_exists('ll_tools_compare_secondary_text_symbols')) {
            $sorted = ll_tools_compare_secondary_text_symbols($symbol_a, $symbol_b, $transcription_mode);
            if ($sorted !== 0) {
                return $sorted;
            }
        }
        return ll_tools_locale_compare_strings($symbol_a, $symbol_b);
    });

    return $entries;
}

function ll_tools_ipa_keyboard_build_letter_map_samples(int $wordset_id, int $limit = 5): array {
    $wordset_id = (int) $wordset_id;
    $limit = max(0, (int) $limit);
    if ($wordset_id <= 0 || $limit <= 0) {
        return [];
    }
    if (!function_exists('ll_tools_word_grid_prepare_text_letters')
        || !function_exists('ll_tools_word_grid_tokenize_ipa')
        || !function_exists('ll_tools_word_grid_align_text_to_ipa')
        || !function_exists('ll_tools_word_grid_is_ipa_stress_marker')
        || !function_exists('ll_tools_word_grid_normalize_ipa_output')) {
        return [];
    }

    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [];
    }

    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');

    $word_display = ll_tools_ipa_keyboard_get_word_display_map($word_ids);

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
    ]);

    if (empty($recording_ids)) {
        return [];
    }

    $samples = [];
    $seen = [];

    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $word_id = wp_get_post_parent_id($recording_id);
        if (!$word_id) {
            continue;
        }

        $recording_text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        $recording_ipa = ll_tools_word_grid_normalize_ipa_output(
            (string) get_post_meta($recording_id, 'recording_ipa', true),
            $transcription_mode
        );
        if ($recording_text === '' || $recording_ipa === '') {
            continue;
        }

        $letters = ll_tools_word_grid_prepare_text_letters($recording_text, $wordset_language);
        $tokens = ll_tools_word_grid_tokenize_ipa($recording_ipa, $transcription_mode);
        if (!empty($tokens)) {
            $tokens = array_values(array_filter($tokens, function ($token) use ($transcription_mode) {
                return !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $transcription_mode);
            }));
        }
        if (empty($letters) || empty($tokens)) {
            continue;
        }

        $alignment = ll_tools_word_grid_align_text_to_ipa($letters, $tokens, $transcription_mode);
        if (empty($alignment['matches'])) {
            continue;
        }

        $letter_coverage = $alignment['matched_letters'] / max(1, count($letters));
        $token_coverage = $alignment['matched_tokens'] / max(1, count($tokens));
        if ($alignment['avg_score'] < 0.55 || $letter_coverage < 0.55 || $token_coverage < 0.45) {
            continue;
        }

        $word_info = $word_display[$word_id] ?? ['word_text' => '', 'translation' => ''];
        $recording_payload = ll_tools_ipa_keyboard_build_recording_payload($recording_id, $word_id, $word_info, $recording_ipa);

        foreach ($alignment['matches'] as $match) {
            $text_key = ll_tools_ipa_keyboard_normalize_letter_key((string) ($match['text'] ?? ''), $wordset_language);
            $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) ($match['ipa'] ?? ''), $transcription_mode);
            if ($text_key === '' || $ipa_key === '') {
                continue;
            }
            $seen_key = $text_key . '|' . $ipa_key;
            if (!isset($seen[$seen_key])) {
                $seen[$seen_key] = [];
            }
            if (isset($seen[$seen_key][$recording_id])) {
                continue;
            }
            if (!isset($samples[$text_key][$ipa_key])) {
                $samples[$text_key][$ipa_key] = [];
            }
            if (count($samples[$text_key][$ipa_key]) >= $limit) {
                continue;
            }
            $samples[$text_key][$ipa_key][] = $recording_payload;
            $seen[$seen_key][$recording_id] = true;
        }
    }

    return $samples;
}

function ll_tools_ipa_keyboard_normalize_letter_key(string $letter, string $language = ''): string {
    $letter = function_exists('ll_tools_word_grid_lowercase')
        ? ll_tools_word_grid_lowercase($letter, $language)
        : strtolower($letter);
    $letter = preg_replace('/[^\p{L}]+/u', '', $letter);
    return (string) $letter;
}

function ll_tools_ipa_keyboard_normalize_ipa_token(string $token, string $mode = 'ipa'): string {
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    if (function_exists('ll_tools_word_grid_normalize_ipa_output')) {
        $token = ll_tools_word_grid_normalize_ipa_output($token, $mode);
    }
    if (function_exists('ll_tools_word_grid_strip_ipa_stress_markers')) {
        $token = ll_tools_word_grid_strip_ipa_stress_markers($token, $mode);
    }
    $token = trim((string) $token);
    if (function_exists('ll_tools_word_grid_is_ipa_stress_marker')
        && ll_tools_word_grid_is_ipa_stress_marker($token, $mode)) {
        return '';
    }
    return $token;
}

function ll_tools_ipa_keyboard_build_recording_letter_map_matches(
    string $recording_text,
    string $recording_ipa,
    string $wordset_language = '',
    string $transcription_mode = 'ipa'
): array {
    $recording_text = trim($recording_text);
    $recording_ipa = function_exists('ll_tools_word_grid_normalize_ipa_output')
        ? ll_tools_word_grid_normalize_ipa_output($recording_ipa, $transcription_mode)
        : trim($recording_ipa);

    if ($recording_text === '' || $recording_ipa === ''
        || !function_exists('ll_tools_word_grid_prepare_text_letters')
        || !function_exists('ll_tools_word_grid_tokenize_ipa')
        || !function_exists('ll_tools_word_grid_align_text_to_ipa')
        || !function_exists('ll_tools_word_grid_is_ipa_stress_marker')) {
        return [];
    }

    $letters = ll_tools_word_grid_prepare_text_letters($recording_text, $wordset_language);
    $tokens = ll_tools_word_grid_tokenize_ipa($recording_ipa, $transcription_mode);
    if (!empty($tokens)) {
        $tokens = array_values(array_filter($tokens, function ($token) use ($transcription_mode) {
            return !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $transcription_mode);
        }));
    }
    if (empty($letters) || empty($tokens)) {
        return [];
    }

    $alignment = ll_tools_word_grid_align_text_to_ipa($letters, $tokens, $transcription_mode);
    if (empty($alignment['matches'])) {
        return [];
    }

    $letter_coverage = $alignment['matched_letters'] / max(1, count($letters));
    $token_coverage = $alignment['matched_tokens'] / max(1, count($tokens));
    if ($alignment['avg_score'] < 0.55 || $letter_coverage < 0.55 || $token_coverage < 0.45) {
        return [];
    }

    $map = [];
    foreach ($alignment['matches'] as $match) {
        $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) ($match['text'] ?? ''), $wordset_language);
        $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) ($match['ipa'] ?? ''), $transcription_mode);
        if ($letter_key === '' || $ipa_key === '') {
            continue;
        }
        if (!isset($map[$letter_key])) {
            $map[$letter_key] = [];
        }
        $map[$letter_key][$ipa_key] = (int) ($map[$letter_key][$ipa_key] ?? 0) + 1;
    }

    return $map;
}

function ll_tools_ipa_keyboard_update_cached_letter_map(
    int $wordset_id,
    array $previous_matches,
    array $next_matches,
    string $wordset_language = '',
    string $transcription_mode = 'ipa'
): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $map = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_map')
        ? ll_tools_word_grid_get_wordset_ipa_letter_map($wordset_id)
        : get_term_meta($wordset_id, 'll_wordset_ipa_letter_map', true);
    if (!is_array($map)) {
        $map = [];
    }

    $apply_delta = function (array $source, int $direction) use (&$map, $wordset_language, $transcription_mode) {
        foreach ($source as $letter => $ipa_counts) {
            if (!is_array($ipa_counts)) {
                continue;
            }
            $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) $letter, $wordset_language);
            if ($letter_key === '') {
                continue;
            }
            if (!isset($map[$letter_key]) || !is_array($map[$letter_key])) {
                $map[$letter_key] = [];
            }

            foreach ($ipa_counts as $ipa => $count) {
                $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) $ipa, $transcription_mode);
                $delta = max(1, (int) $count) * $direction;
                if ($ipa_key === '' || $delta === 0) {
                    continue;
                }
                $map[$letter_key][$ipa_key] = (int) ($map[$letter_key][$ipa_key] ?? 0) + $delta;
                if ($map[$letter_key][$ipa_key] <= 0) {
                    unset($map[$letter_key][$ipa_key]);
                }
            }

            if (empty($map[$letter_key])) {
                unset($map[$letter_key]);
            }
        }
    };

    $apply_delta($previous_matches, -1);
    $apply_delta($next_matches, 1);

    if (function_exists('ll_tools_word_grid_clean_ipa_letter_map')) {
        $map = ll_tools_word_grid_clean_ipa_letter_map($map, $wordset_language, $transcription_mode);
    }

    update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', $map);
    if (defined('LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION')) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map_case_version', LL_TOOLS_WORD_GRID_IPA_LETTER_MAP_CASE_VERSION);
    }

    return $map;
}

function ll_tools_ipa_keyboard_wordset_has_symbol(
    int $wordset_id,
    string $symbol,
    int $exclude_recording_id = 0,
    string $transcription_mode = 'ipa'
): bool {
    $wordset_id = (int) $wordset_id;
    $symbol = ll_tools_ipa_keyboard_normalize_ipa_token($symbol, $transcription_mode);
    if ($wordset_id <= 0 || $symbol === '') {
        return false;
    }

    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return false;
    }

    $query_args = [
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
        'meta_query' => [
            [
                'key' => 'recording_ipa',
                'value' => $symbol,
                'compare' => 'LIKE',
            ],
        ],
        'no_found_rows' => true,
        'suppress_filters' => true,
    ];
    if ($exclude_recording_id > 0) {
        $query_args['post__not_in'] = [$exclude_recording_id];
    }

    $candidate_ids = get_posts($query_args);
    foreach ((array) $candidate_ids as $candidate_id) {
        $candidate_counts = ll_tools_ipa_keyboard_count_special_symbols(
            (string) get_post_meta((int) $candidate_id, 'recording_ipa', true),
            $transcription_mode
        );
        if (!empty($candidate_counts[$symbol])) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_update_cached_special_symbols(
    int $wordset_id,
    array $previous_symbol_counts,
    array $next_symbol_counts,
    int $exclude_recording_id = 0,
    string $transcription_mode = 'ipa'
): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $existing_raw = get_term_meta($wordset_id, 'll_wordset_ipa_special_chars', true);
    if (!is_array($existing_raw) && function_exists('ll_tools_word_grid_rebuild_wordset_ipa_special_chars')) {
        $existing = ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
    } else {
        $existing = function_exists('ll_tools_word_grid_get_wordset_ipa_auto_symbols')
            ? ll_tools_word_grid_get_wordset_ipa_auto_symbols($wordset_id)
            : $existing_raw;
    }
    if (!is_array($existing)) {
        $existing = [];
    }

    $symbols = [];
    foreach ((array) $existing as $symbol) {
        $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $transcription_mode);
        if ($token !== '') {
            $symbols[$token] = true;
        }
    }
    foreach ($next_symbol_counts as $symbol => $count) {
        $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $transcription_mode);
        if ($token !== '' && (int) $count > 0) {
            $symbols[$token] = true;
        }
    }
    foreach ($previous_symbol_counts as $symbol => $count) {
        $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $transcription_mode);
        if ($token === '' || (int) $count <= 0 || !empty($next_symbol_counts[$token])) {
            continue;
        }
        if (!ll_tools_ipa_keyboard_wordset_has_symbol($wordset_id, $token, $exclude_recording_id, $transcription_mode)) {
            unset($symbols[$token]);
        }
    }

    $list = array_keys($symbols);
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $list = ll_tools_sort_secondary_text_symbols($list, $transcription_mode);
    }

    update_term_meta($wordset_id, 'll_wordset_ipa_special_chars', $list);
    return $list;
}

function ll_tools_ipa_keyboard_build_letter_map_data(int $wordset_id, bool $rebuild_auto = true): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');

    if ($rebuild_auto && function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
        $auto_map = ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    } elseif (function_exists('ll_tools_word_grid_get_wordset_ipa_letter_map')) {
        $auto_map = ll_tools_word_grid_get_wordset_ipa_letter_map($wordset_id);
    } else {
        $auto_map = [];
    }
    $auto_map_clean = function_exists('ll_tools_word_grid_clean_ipa_letter_map')
        ? ll_tools_word_grid_clean_ipa_letter_map((array) $auto_map, $wordset_language, $transcription_mode)
        : (array) $auto_map;
    $manual_map = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_manual_map')
        ? ll_tools_word_grid_get_wordset_ipa_letter_manual_map($wordset_id)
        : [];
    $blocklist = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_blocklist')
        ? ll_tools_word_grid_get_wordset_ipa_letter_blocklist($wordset_id)
        : [];
    $auto_map = function_exists('ll_tools_word_grid_filter_ipa_letter_map_with_blocklist')
        ? ll_tools_word_grid_filter_ipa_letter_map_with_blocklist($auto_map_clean, $blocklist, $transcription_mode)
        : $auto_map_clean;

    if (empty($auto_map) && empty($manual_map) && empty($blocklist)) {
        return [];
    }

    $samples = ll_tools_ipa_keyboard_build_letter_map_samples($wordset_id, 5);
    $entries = [];
    $blocked_by_letter = [];

    $merge_auto = function ($letter, $ipa_counts) use (&$entries, $wordset_language, $transcription_mode) {
        if (!is_array($ipa_counts)) {
            return;
        }
        $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) $letter, $wordset_language);
        if ($letter_key === '') {
            return;
        }
        if (!isset($entries[$letter_key])) {
            $entries[$letter_key] = [
                'letter' => $letter_key,
                'auto_counts' => [],
                'manual' => [],
            ];
        }
        foreach ($ipa_counts as $ipa => $count) {
            $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) $ipa, $transcription_mode);
            if ($ipa_key === '') {
                continue;
            }
            $entries[$letter_key]['auto_counts'][$ipa_key] = (int) ($entries[$letter_key]['auto_counts'][$ipa_key] ?? 0)
                + max(1, (int) $count);
        }
    };

    $merge_manual = function ($letter, $symbols) use (&$entries, $wordset_language, $transcription_mode) {
        if (!is_array($symbols)) {
            return;
        }
        $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) $letter, $wordset_language);
        if ($letter_key === '') {
            return;
        }
        if (!isset($entries[$letter_key])) {
            $entries[$letter_key] = [
                'letter' => $letter_key,
                'auto_counts' => [],
                'manual' => [],
            ];
        }
        foreach ($symbols as $symbol) {
            $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $transcription_mode);
            if ($ipa_key === '' || in_array($ipa_key, $entries[$letter_key]['manual'], true)) {
                continue;
            }
            $entries[$letter_key]['manual'][] = $ipa_key;
        }
    };

    foreach ($auto_map as $letter => $ipa_counts) {
        $merge_auto($letter, $ipa_counts);
    }
    foreach ($manual_map as $letter => $symbols) {
        $merge_manual($letter, $symbols);
    }

    if (!empty($blocklist)) {
        foreach ($blocklist as $letter => $symbols) {
            if (!is_array($symbols)) {
                continue;
            }
            $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) $letter, $wordset_language);
            if ($letter_key === '') {
                continue;
            }
            if (!isset($entries[$letter_key])) {
                $entries[$letter_key] = [
                    'letter' => $letter_key,
                    'auto_counts' => [],
                    'manual' => [],
                ];
            }
            foreach ($symbols as $symbol) {
                $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $transcription_mode);
                if ($ipa_key === '') {
                    continue;
                }
                if (!isset($blocked_by_letter[$letter_key])) {
                    $blocked_by_letter[$letter_key] = [];
                }
                $count = (int) ($auto_map_clean[$letter_key][$ipa_key] ?? 0);
                $blocked_by_letter[$letter_key][] = [
                    'symbol' => $ipa_key,
                    'count' => $count,
                    'samples' => array_values((array) ($samples[$letter_key][$ipa_key] ?? [])),
                ];
            }
        }
    }

    $list = [];
    foreach ($entries as $entry) {
        $auto_entries = [];
        foreach ((array) ($entry['auto_counts'] ?? []) as $symbol => $count) {
            $auto_entries[] = [
                'symbol' => $symbol,
                'count' => (int) $count,
                'samples' => array_values((array) ($samples[(string) ($entry['letter'] ?? '')][$symbol] ?? [])),
            ];
        }
        usort($auto_entries, function ($a, $b) {
            $count_a = (int) ($a['count'] ?? 0);
            $count_b = (int) ($b['count'] ?? 0);
            if ($count_a === $count_b) {
                return strnatcasecmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
            }
            return ($count_b <=> $count_a);
        });

        $list[] = [
            'letter' => (string) ($entry['letter'] ?? ''),
            'auto' => $auto_entries,
            'manual' => array_values((array) ($entry['manual'] ?? [])),
            'blocked' => array_values((array) ($blocked_by_letter[(string) ($entry['letter'] ?? '')] ?? [])),
        ];
    }

    usort($list, function ($a, $b) {
        $letter_a = (string) ($a['letter'] ?? '');
        $letter_b = (string) ($b['letter'] ?? '');
        $len_a = function_exists('mb_strlen') ? mb_strlen($letter_a, 'UTF-8') : strlen($letter_a);
        $len_b = function_exists('mb_strlen') ? mb_strlen($letter_b, 'UTF-8') : strlen($letter_b);
        if ($len_a === $len_b) {
            return strnatcasecmp($letter_a, $letter_b);
        }
        return ($len_a <=> $len_b);
    });

    return $list;
}

function ll_tools_ipa_keyboard_validation_config_meta_key(): string {
    return 'll_wordset_transcription_validation_config';
}

function ll_tools_ipa_keyboard_validation_state_meta_key(): string {
    return 'll_transcription_validation_by_wordset';
}

function ll_tools_ipa_keyboard_validation_issue_count_meta_key(): string {
    return 'll_transcription_validation_issue_count';
}

function ll_tools_ipa_keyboard_validation_exceptions_meta_key(): string {
    return 'll_transcription_validation_exceptions';
}

function ll_tools_ipa_keyboard_validation_scan_option_key(): string {
    return 'll_tools_transcription_validation_scan_version';
}

function ll_tools_ipa_keyboard_get_validation_schema_version(): int {
    return 2;
}

function ll_tools_ipa_keyboard_get_builtin_validation_rules(): array {
    return [
        'modifier_without_base' => [
            'label' => __('Modifier without base sound', 'll-tools-text-domain'),
            'description' => __('Flags tokens made only of modifiers or diacritics, without a main sound.', 'll-tools-text-domain'),
        ],
        'tie_bar_without_pair' => [
            'label' => __('Tie bar without a pair', 'll-tools-text-domain'),
            'description' => __('Flags tie bars that do not join two sounds into a single affricate-style token.', 'll-tools-text-domain'),
        ],
        'duplicate_modifier' => [
            'label' => __('Repeated IPA modifier', 'll-tools-text-domain'),
            'description' => __('Flags the same modifier appearing more than once on one token.', 'll-tools-text-domain'),
        ],
        'stress_placement' => [
            'label' => __('Stress marker placement', 'll-tools-text-domain'),
            'description' => __('Flags stress markers left at the end or doubled together.', 'll-tools-text-domain'),
        ],
        'aspiration_context' => [
            'label' => __('Aspiration placement', 'll-tools-text-domain'),
            'description' => __('Flags ʰ unless it follows a voiceless stop or affricate.', 'll-tools-text-domain'),
        ],
    ];
}

function ll_tools_ipa_keyboard_generate_validation_rule_id(): string {
    return 'rule_' . substr(md5(uniqid((string) wp_rand(), true)), 0, 12);
}

function ll_tools_ipa_keyboard_normalize_validation_rule_id(string $rule_id): string {
    $rule_id = strtolower($rule_id);
    $rule_id = preg_replace('/[^a-z0-9_-]/', '', $rule_id);
    if ($rule_id === '') {
        $rule_id = ll_tools_ipa_keyboard_generate_validation_rule_id();
    }
    return (string) $rule_id;
}

function ll_tools_ipa_keyboard_sanitize_validation_rule($raw_rule, string $mode = 'ipa'): ?array {
    if (!is_array($raw_rule)) {
        return null;
    }

    $id = ll_tools_ipa_keyboard_normalize_validation_rule_id((string) ($raw_rule['id'] ?? ''));
    $label = sanitize_text_field((string) ($raw_rule['label'] ?? ''));

    $target_raw = function_exists('ll_tools_word_grid_sanitize_ipa')
        ? ll_tools_word_grid_sanitize_ipa((string) ($raw_rule['target'] ?? ''), $mode)
        : sanitize_text_field((string) ($raw_rule['target'] ?? ''));
    $target_tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($target_raw, $mode)
        : preg_split('//u', $target_raw, -1, PREG_SPLIT_NO_EMPTY);
    $target = ll_tools_ipa_keyboard_normalize_ipa_token((string) ($target_tokens[0] ?? ''), $mode);
    if ($target === '') {
        return null;
    }

    $sanitize_context_tokens = static function ($value) use ($mode): array {
        $raw = function_exists('ll_tools_word_grid_sanitize_ipa')
            ? ll_tools_word_grid_sanitize_ipa((string) $value, $mode)
            : sanitize_text_field((string) $value);
        $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
            ? ll_tools_word_grid_tokenize_ipa($raw, $mode)
            : preg_split('//u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $clean = [];
        foreach ((array) $tokens as $token) {
            $normalized = ll_tools_ipa_keyboard_normalize_ipa_token((string) $token, $mode);
            if ($normalized === '' || in_array($normalized, $clean, true)) {
                continue;
            }
            $clean[] = $normalized;
        }
        return $clean;
    };

    return [
        'id' => $id,
        'label' => $label,
        'target' => $target,
        'previous' => $sanitize_context_tokens($raw_rule['previous'] ?? []),
        'next' => $sanitize_context_tokens($raw_rule['next'] ?? []),
    ];
}

function ll_tools_ipa_keyboard_sanitize_validation_config($raw_config, int $wordset_id = 0): array {
    $mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $builtin_rules = ll_tools_ipa_keyboard_get_builtin_validation_rules();
    $config = [
        'disabled_builtin_rules' => [],
        'custom_rules' => [],
    ];

    if (!is_array($raw_config)) {
        return $config;
    }

    foreach ((array) ($raw_config['disabled_builtin_rules'] ?? []) as $rule_code) {
        $rule_code = sanitize_key((string) $rule_code);
        if ($rule_code !== '' && isset($builtin_rules[$rule_code])) {
            $config['disabled_builtin_rules'][] = $rule_code;
        }
    }
    $config['disabled_builtin_rules'] = array_values(array_unique($config['disabled_builtin_rules']));

    foreach ((array) ($raw_config['custom_rules'] ?? []) as $raw_rule) {
        $rule = ll_tools_ipa_keyboard_sanitize_validation_rule($raw_rule, $mode);
        if ($rule !== null) {
            $config['custom_rules'][] = $rule;
        }
    }

    return $config;
}

function ll_tools_ipa_keyboard_get_wordset_validation_config(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return ll_tools_ipa_keyboard_sanitize_validation_config([]);
    }

    $raw = get_term_meta($wordset_id, ll_tools_ipa_keyboard_validation_config_meta_key(), true);
    $config = ll_tools_ipa_keyboard_sanitize_validation_config($raw, $wordset_id);
    if ($config !== $raw) {
        update_term_meta($wordset_id, ll_tools_ipa_keyboard_validation_config_meta_key(), $config);
    }

    return $config;
}

function ll_tools_ipa_keyboard_update_wordset_validation_config(int $wordset_id, array $config): array {
    $config = ll_tools_ipa_keyboard_sanitize_validation_config($config, $wordset_id);
    if (empty($config['disabled_builtin_rules']) && empty($config['custom_rules'])) {
        delete_term_meta($wordset_id, ll_tools_ipa_keyboard_validation_config_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_keyboard_validation_config_meta_key(), $config);
    }

    return $config;
}

function ll_tools_ipa_keyboard_build_validation_rule_summary(array $rule): string {
    $target = (string) ($rule['target'] ?? '');
    $previous = array_values(array_filter(array_map('strval', (array) ($rule['previous'] ?? []))));
    $next = array_values(array_filter(array_map('strval', (array) ($rule['next'] ?? []))));

    if ($target === '') {
        return '';
    }

    if (!empty($previous) && !empty($next)) {
        return sprintf(
            /* translators: 1: target sound, 2: previous sounds, 3: next sounds */
            __('Do not allow %1$s after %2$s and before %3$s.', 'll-tools-text-domain'),
            $target,
            implode(', ', $previous),
            implode(', ', $next)
        );
    }

    if (!empty($previous)) {
        return sprintf(
            /* translators: 1: target sound, 2: previous sounds */
            __('Do not allow %1$s after %2$s.', 'll-tools-text-domain'),
            $target,
            implode(', ', $previous)
        );
    }

    if (!empty($next)) {
        return sprintf(
            /* translators: 1: target sound, 2: next sounds */
            __('Do not allow %1$s before %2$s.', 'll-tools-text-domain'),
            $target,
            implode(', ', $next)
        );
    }

    return sprintf(
        /* translators: %s: target sound */
        __('Do not allow %s anywhere in this word set.', 'll-tools-text-domain'),
        $target
    );
}

function ll_tools_ipa_keyboard_build_validation_config_payload(int $wordset_id): array {
    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    $mode = (string) ($transcription['mode'] ?? 'ipa');
    $config = ll_tools_ipa_keyboard_get_wordset_validation_config($wordset_id);
    $builtin_rules = ll_tools_ipa_keyboard_get_builtin_validation_rules();

    $builtin_payload = [];
    foreach ($builtin_rules as $rule_code => $rule) {
        $builtin_payload[] = [
            'code' => $rule_code,
            'label' => (string) ($rule['label'] ?? $rule_code),
            'description' => (string) ($rule['description'] ?? ''),
            'enabled' => !in_array($rule_code, (array) ($config['disabled_builtin_rules'] ?? []), true),
        ];
    }

    $custom_payload = [];
    foreach ((array) ($config['custom_rules'] ?? []) as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $custom_payload[] = [
            'id' => (string) ($rule['id'] ?? ''),
            'label' => (string) ($rule['label'] ?? ''),
            'target' => (string) ($rule['target'] ?? ''),
            'previous' => implode(' ', array_values((array) ($rule['previous'] ?? []))),
            'next' => implode(' ', array_values((array) ($rule['next'] ?? []))),
            'summary' => ll_tools_ipa_keyboard_build_validation_rule_summary($rule),
        ];
    }

    return [
        'supports_rules' => ($mode === 'ipa'),
        'builtin_rules' => $builtin_payload,
        'custom_rules' => $custom_payload,
    ];
}

function ll_tools_ipa_keyboard_sanitize_validation_exceptions($raw): array {
    $clean = [];
    if (!is_array($raw)) {
        return $clean;
    }

    foreach ($raw as $wordset_id => $rule_keys) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0 || !is_array($rule_keys)) {
            continue;
        }

        $keys = [];
        foreach ($rule_keys as $rule_key) {
            $rule_key = strtolower((string) $rule_key);
            $rule_key = preg_replace('/[^a-z0-9:_-]/', '', $rule_key);
            if ($rule_key !== '' && !in_array($rule_key, $keys, true)) {
                $keys[] = $rule_key;
            }
        }

        if (!empty($keys)) {
            $clean[$wordset_id] = $keys;
        }
    }

    return $clean;
}

function ll_tools_ipa_keyboard_get_recording_validation_exceptions(int $recording_id): array {
    if ($recording_id <= 0) {
        return [];
    }

    $raw = get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_exceptions_meta_key(), true);
    $clean = ll_tools_ipa_keyboard_sanitize_validation_exceptions($raw);
    if ($clean !== $raw) {
        if (empty($clean)) {
            delete_post_meta($recording_id, ll_tools_ipa_keyboard_validation_exceptions_meta_key());
        } else {
            update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_exceptions_meta_key(), $clean);
        }
    }

    return $clean;
}

function ll_tools_ipa_keyboard_get_recording_validation_exception_keys(int $recording_id, int $wordset_id): array {
    $exceptions = ll_tools_ipa_keyboard_get_recording_validation_exceptions($recording_id);
    return array_values((array) ($exceptions[$wordset_id] ?? []));
}

function ll_tools_ipa_keyboard_update_recording_validation_exception(
    int $recording_id,
    int $wordset_id,
    string $rule_key,
    bool $enabled
): array {
    $exceptions = ll_tools_ipa_keyboard_get_recording_validation_exceptions($recording_id);
    $rule_key = strtolower($rule_key);
    $rule_key = preg_replace('/[^a-z0-9:_-]/', '', $rule_key);
    if ($recording_id <= 0 || $wordset_id <= 0 || $rule_key === '') {
        return $exceptions;
    }

    $existing = array_values((array) ($exceptions[$wordset_id] ?? []));
    if ($enabled) {
        if (!in_array($rule_key, $existing, true)) {
            $existing[] = $rule_key;
        }
    } else {
        $existing = array_values(array_filter($existing, static function ($entry) use ($rule_key): bool {
            return (string) $entry !== $rule_key;
        }));
    }

    if (empty($existing)) {
        unset($exceptions[$wordset_id]);
    } else {
        $exceptions[$wordset_id] = $existing;
    }

    if (empty($exceptions)) {
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_validation_exceptions_meta_key());
    } else {
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_exceptions_meta_key(), $exceptions);
    }

    return $exceptions;
}

function ll_tools_ipa_keyboard_get_recording_wordset_ids(int $recording_id): array {
    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return [];
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return [];
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || empty($wordset_ids)) {
        return [];
    }

    return array_values(array_filter(array_map('intval', (array) $wordset_ids), static function ($wordset_id): bool {
        return $wordset_id > 0;
    }));
}

function ll_tools_ipa_keyboard_split_token_characters(string $token): array {
    $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
    return $chars ? array_values($chars) : [];
}

function ll_tools_ipa_keyboard_token_has_base_symbol(string $token, string $mode = 'ipa'): bool {
    return ll_tools_ipa_keyboard_extract_token_base($token, $mode) !== '';
}

function ll_tools_ipa_keyboard_extract_token_base(string $token, string $mode = 'ipa'): string {
    if ($token === '') {
        return '';
    }

    $chars = ll_tools_ipa_keyboard_split_token_characters($token);
    if (empty($chars)) {
        return '';
    }

    $base = '';
    foreach ($chars as $char) {
        if (function_exists('ll_tools_word_grid_is_ipa_stress_marker') && ll_tools_word_grid_is_ipa_stress_marker($char, $mode)) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_separator') && ll_tools_word_grid_is_ipa_separator($char, $mode)) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_tie_bar') && ll_tools_word_grid_is_ipa_tie_bar($char, $mode)) {
            $base .= $char;
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_combining_mark') && ll_tools_word_grid_is_ipa_combining_mark($char)) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_post_modifier') && ll_tools_word_grid_is_ipa_post_modifier($char, $mode)) {
            continue;
        }
        $base .= $char;
    }

    return trim($base);
}

function ll_tools_ipa_keyboard_token_base_segment_count(string $token, string $mode = 'ipa'): int {
    $base = ll_tools_ipa_keyboard_extract_token_base($token, $mode);
    if ($base === '') {
        return 0;
    }

    $segments = preg_split('/[\x{035C}\x{0361}]/u', $base, -1, PREG_SPLIT_NO_EMPTY);
    if (!$segments) {
        return 1;
    }

    return count($segments);
}

function ll_tools_ipa_keyboard_count_modifier_occurrences(string $token, string $modifier): int {
    if ($token === '' || $modifier === '') {
        return 0;
    }

    return preg_match_all('/' . preg_quote($modifier, '/') . '/u', $token) ?: 0;
}

function ll_tools_ipa_keyboard_token_is_voiceless_stop_or_affricate(string $token, string $mode = 'ipa'): bool {
    $base = ll_tools_ipa_keyboard_extract_token_base($token, $mode);
    if ($base === '') {
        return false;
    }

    $segments = preg_split('/[\x{035C}\x{0361}]/u', $base, -1, PREG_SPLIT_NO_EMPTY);
    if (!$segments || empty($segments[0])) {
        return false;
    }

    $first = (string) $segments[0];
    return in_array($first, ['p', 't', 'ʈ', 'c', 'k', 'q', 'ʔ'], true);
}

function ll_tools_ipa_keyboard_sanitize_validation_state($raw): array {
    if (!is_array($raw)) {
        return [];
    }

    $sanitize_issue = static function ($issue): ?array {
        if (!is_array($issue)) {
            return null;
        }

        $rule_key = strtolower((string) ($issue['rule_key'] ?? ''));
        $rule_key = preg_replace('/[^a-z0-9:_-]/', '', $rule_key);
        if ($rule_key === '') {
            return null;
        }

        $samples = [];
        foreach ((array) ($issue['samples'] ?? []) as $sample) {
            $sample = trim((string) $sample);
            if ($sample !== '' && !in_array($sample, $samples, true)) {
                $samples[] = $sample;
            }
        }

        return [
            'rule_key' => $rule_key,
            'code' => sanitize_key((string) ($issue['code'] ?? '')),
            'type' => in_array((string) ($issue['type'] ?? ''), ['builtin', 'custom'], true) ? (string) $issue['type'] : 'builtin',
            'label' => sanitize_text_field((string) ($issue['label'] ?? '')),
            'message' => sanitize_text_field((string) ($issue['message'] ?? '')),
            'count' => max(1, (int) ($issue['count'] ?? 1)),
            'samples' => $samples,
        ];
    };

    $state = [];
    foreach ($raw as $wordset_id => $entry) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0 || !is_array($entry)) {
            continue;
        }

        $active = [];
        foreach ((array) ($entry['active'] ?? []) as $issue) {
            $clean_issue = $sanitize_issue($issue);
            if ($clean_issue !== null) {
                $active[] = $clean_issue;
            }
        }

        $ignored = [];
        foreach ((array) ($entry['ignored'] ?? []) as $issue) {
            $clean_issue = $sanitize_issue($issue);
            if ($clean_issue !== null) {
                $ignored[] = $clean_issue;
            }
        }

        if (!empty($active) || !empty($ignored)) {
            $state[$wordset_id] = [
                'active' => $active,
                'ignored' => $ignored,
            ];
        }
    }

    return $state;
}

function ll_tools_ipa_keyboard_get_recording_validation_state(int $recording_id): array {
    if ($recording_id <= 0) {
        return [];
    }

    $raw = get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), true);
    $clean = ll_tools_ipa_keyboard_sanitize_validation_state($raw);
    if ($clean !== $raw) {
        if (empty($clean)) {
            delete_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key());
        } else {
            update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), $clean);
        }
    }

    return $clean;
}

function ll_tools_ipa_keyboard_get_recording_wordset_validation_result(int $recording_id, int $wordset_id): array {
    $state = ll_tools_ipa_keyboard_get_recording_validation_state($recording_id);
    $entry = (array) ($state[$wordset_id] ?? []);

    return [
        'active' => array_values((array) ($entry['active'] ?? [])),
        'ignored' => array_values((array) ($entry['ignored'] ?? [])),
    ];
}

function ll_tools_ipa_keyboard_validate_recording_for_wordset(
    int $recording_id,
    int $wordset_id,
    array $exception_rule_keys = []
): array {
    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    $mode = (string) ($transcription['mode'] ?? 'ipa');
    if ($recording_id <= 0 || $wordset_id <= 0 || $mode !== 'ipa') {
        return ['active' => [], 'ignored' => []];
    }

    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $mode);
    if ($recording_ipa === '') {
        return ['active' => [], 'ignored' => []];
    }

    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($recording_ipa, $mode)
        : preg_split('//u', $recording_ipa, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) {
        return ['active' => [], 'ignored' => []];
    }

    $segment_tokens = array_values(array_filter($tokens, static function ($token) use ($mode): bool {
        return !function_exists('ll_tools_word_grid_is_ipa_stress_marker')
            || !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $mode);
    }));

    $builtin_rules = ll_tools_ipa_keyboard_get_builtin_validation_rules();
    $config = ll_tools_ipa_keyboard_get_wordset_validation_config($wordset_id);
    $disabled_builtin_rules = array_values((array) ($config['disabled_builtin_rules'] ?? []));
    $issue_map = [];

    $add_issue = static function (
        array &$issues,
        string $rule_key,
        string $code,
        string $type,
        string $label,
        string $message,
        string $sample = ''
    ): void {
        if (!isset($issues[$rule_key])) {
            $issues[$rule_key] = [
                'rule_key' => $rule_key,
                'code' => $code,
                'type' => $type,
                'label' => $label,
                'message' => $message,
                'count' => 0,
                'samples' => [],
            ];
        }

        $issues[$rule_key]['count'] += 1;
        if ($sample !== '' && !in_array($sample, $issues[$rule_key]['samples'], true) && count($issues[$rule_key]['samples']) < 3) {
            $issues[$rule_key]['samples'][] = $sample;
        }
    };

    if (!in_array('modifier_without_base', $disabled_builtin_rules, true)) {
        foreach ($segment_tokens as $token) {
            if (!ll_tools_ipa_keyboard_token_has_base_symbol((string) $token, $mode)) {
                $add_issue(
                    $issue_map,
                    'builtin:modifier_without_base',
                    'modifier_without_base',
                    'builtin',
                    (string) ($builtin_rules['modifier_without_base']['label'] ?? ''),
                    __('A modifier or diacritic appears without a main sound.', 'll-tools-text-domain'),
                    (string) $token
                );
            }
        }
    }

    if (!in_array('tie_bar_without_pair', $disabled_builtin_rules, true)) {
        foreach ($segment_tokens as $token) {
            if (!preg_match('/[\x{035C}\x{0361}]/u', (string) $token)) {
                continue;
            }
            if (ll_tools_ipa_keyboard_token_base_segment_count((string) $token, $mode) < 2) {
                $add_issue(
                    $issue_map,
                    'builtin:tie_bar_without_pair',
                    'tie_bar_without_pair',
                    'builtin',
                    (string) ($builtin_rules['tie_bar_without_pair']['label'] ?? ''),
                    __('A tie bar appears without two joined sounds.', 'll-tools-text-domain'),
                    (string) $token
                );
            }
        }
    }

    if (!in_array('duplicate_modifier', $disabled_builtin_rules, true)) {
        $repeatable_modifiers = ['ʰ', 'ʱ', 'ʲ', 'ʷ', 'ː', 'ˑ', 'ˀ'];
        foreach ($segment_tokens as $token) {
            foreach ($repeatable_modifiers as $modifier) {
                if (ll_tools_ipa_keyboard_count_modifier_occurrences((string) $token, $modifier) > 1) {
                    $add_issue(
                        $issue_map,
                        'builtin:duplicate_modifier',
                        'duplicate_modifier',
                        'builtin',
                        (string) ($builtin_rules['duplicate_modifier']['label'] ?? ''),
                        __('The same IPA modifier is repeated on one sound.', 'll-tools-text-domain'),
                        (string) $token
                    );
                    break;
                }
            }
        }
    }

    if (!in_array('stress_placement', $disabled_builtin_rules, true)) {
        foreach ($tokens as $index => $token) {
            if (!function_exists('ll_tools_word_grid_is_ipa_stress_marker')
                || !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $mode)) {
                continue;
            }

            $next_token = (string) ($tokens[$index + 1] ?? '');
            if (($index === count($tokens) - 1)
                || (function_exists('ll_tools_word_grid_is_ipa_stress_marker')
                    && $next_token !== ''
                    && ll_tools_word_grid_is_ipa_stress_marker($next_token, $mode))) {
                $add_issue(
                    $issue_map,
                    'builtin:stress_placement',
                    'stress_placement',
                    'builtin',
                    (string) ($builtin_rules['stress_placement']['label'] ?? ''),
                    __('Stress markers should appear before a sound, not at the end or doubled together.', 'll-tools-text-domain'),
                    (string) $token
                );
            }
        }
    }

    if (!in_array('aspiration_context', $disabled_builtin_rules, true)) {
        foreach ($segment_tokens as $token) {
            $token = (string) $token;
            if (!preg_match('/ʰ/u', $token) || !ll_tools_ipa_keyboard_token_has_base_symbol($token, $mode)) {
                continue;
            }
            if (!ll_tools_ipa_keyboard_token_is_voiceless_stop_or_affricate($token, $mode)) {
                $add_issue(
                    $issue_map,
                    'builtin:aspiration_context',
                    'aspiration_context',
                    'builtin',
                    (string) ($builtin_rules['aspiration_context']['label'] ?? ''),
                    __('Aspiration ʰ should usually appear only after a voiceless stop or affricate.', 'll-tools-text-domain'),
                    $token
                );
            }
        }
    }

    foreach ((array) ($config['custom_rules'] ?? []) as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $target = (string) ($rule['target'] ?? '');
        if ($target === '') {
            continue;
        }

        $rule_key = 'custom:' . ll_tools_ipa_keyboard_normalize_validation_rule_id((string) ($rule['id'] ?? ''));
        $rule_label = trim((string) ($rule['label'] ?? ''));
        $rule_message = ll_tools_ipa_keyboard_build_validation_rule_summary($rule);
        if ($rule_label === '') {
            $rule_label = $rule_message;
        }

        $previous_allowed = array_values((array) ($rule['previous'] ?? []));
        $next_allowed = array_values((array) ($rule['next'] ?? []));

        foreach ($segment_tokens as $index => $token) {
            $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $token, $mode);
            if ($token !== $target) {
                continue;
            }

            $previous_token = ll_tools_ipa_keyboard_normalize_ipa_token((string) ($segment_tokens[$index - 1] ?? ''), $mode);
            $next_token = ll_tools_ipa_keyboard_normalize_ipa_token((string) ($segment_tokens[$index + 1] ?? ''), $mode);

            if (!empty($previous_allowed) && !in_array($previous_token, $previous_allowed, true)) {
                continue;
            }
            if (!empty($next_allowed) && !in_array($next_token, $next_allowed, true)) {
                continue;
            }

            $add_issue(
                $issue_map,
                $rule_key,
                sanitize_key((string) ($rule['id'] ?? 'custom_rule')),
                'custom',
                $rule_label,
                $rule_message,
                $token
            );
        }
    }

    $active = [];
    $ignored = [];
    foreach ($issue_map as $issue) {
        if (in_array((string) ($issue['rule_key'] ?? ''), $exception_rule_keys, true)) {
            $ignored[] = $issue;
        } else {
            $active[] = $issue;
        }
    }

    return [
        'active' => array_values($active),
        'ignored' => array_values($ignored),
    ];
}

function ll_tools_ipa_keyboard_update_recording_validation(int $recording_id): array {
    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return [];
    }

    $wordset_ids = ll_tools_ipa_keyboard_get_recording_wordset_ids($recording_id);
    $state = [];
    $active_issue_count = 0;

    foreach ($wordset_ids as $wordset_id) {
        $exceptions = ll_tools_ipa_keyboard_get_recording_validation_exception_keys($recording_id, $wordset_id);
        $validation = ll_tools_ipa_keyboard_validate_recording_for_wordset($recording_id, $wordset_id, $exceptions);
        if (empty($validation['active']) && empty($validation['ignored'])) {
            continue;
        }

        $state[$wordset_id] = [
            'active' => array_values((array) ($validation['active'] ?? [])),
            'ignored' => array_values((array) ($validation['ignored'] ?? [])),
        ];
        $active_issue_count += count((array) ($validation['active'] ?? []));
    }

    if (empty($state)) {
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key());
    } else {
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), $state);
    }

    if ($active_issue_count > 0) {
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), $active_issue_count);
    } else {
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key());
    }

    return $state;
}

function ll_tools_ipa_keyboard_rescan_wordset_validations(int $wordset_id): int {
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return 0;
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
        'no_found_rows' => true,
    ]);

    $count = 0;
    foreach ((array) $recording_ids as $recording_id) {
        ll_tools_ipa_keyboard_update_recording_validation((int) $recording_id);
        $count++;
    }

    return $count;
}

function ll_tools_ipa_keyboard_rescan_all_validations(): int {
    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);

    $count = 0;
    foreach ((array) $recording_ids as $recording_id) {
        ll_tools_ipa_keyboard_update_recording_validation((int) $recording_id);
        $count++;
    }

    return $count;
}

function ll_tools_ipa_keyboard_get_flagged_validation_recording_count(): int {
    $query = new WP_Query([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => false,
        'meta_query' => [
            [
                'key' => ll_tools_ipa_keyboard_validation_issue_count_meta_key(),
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
        ],
    ]);

    return (int) $query->found_posts;
}

function ll_tools_ipa_keyboard_maybe_rescan_all_validations(): void {
    if (!is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || !current_user_can('view_ll_tools')) {
        return;
    }

    $stored_version = (int) get_option(ll_tools_ipa_keyboard_validation_scan_option_key(), 0);
    $target_version = ll_tools_ipa_keyboard_get_validation_schema_version();
    if ($stored_version >= $target_version) {
        return;
    }

    ll_tools_ipa_keyboard_rescan_all_validations();
    update_option(ll_tools_ipa_keyboard_validation_scan_option_key(), $target_version, false);
}
add_action('admin_init', 'll_tools_ipa_keyboard_maybe_rescan_all_validations');

function ll_tools_ipa_keyboard_sync_validation_on_word_audio_save($post_id, $post, $update): void {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }

    if (!($post instanceof WP_Post) || $post->post_type !== 'word_audio') {
        return;
    }

    ll_tools_ipa_keyboard_update_recording_validation((int) $post_id);
}
add_action('save_post_word_audio', 'll_tools_ipa_keyboard_sync_validation_on_word_audio_save', 25, 3);

function ll_tools_ipa_keyboard_sync_validation_on_recording_meta_change($meta_ids, $object_id, $meta_key, $meta_value = null): void {
    if ((string) $meta_key !== 'recording_ipa') {
        return;
    }

    $post = get_post((int) $object_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'word_audio') {
        return;
    }

    ll_tools_ipa_keyboard_update_recording_validation((int) $object_id);
}
add_action('added_post_meta', 'll_tools_ipa_keyboard_sync_validation_on_recording_meta_change', 10, 4);
add_action('updated_post_meta', 'll_tools_ipa_keyboard_sync_validation_on_recording_meta_change', 10, 4);
add_action('deleted_post_meta', 'll_tools_ipa_keyboard_sync_validation_on_recording_meta_change', 10, 4);

function ll_tools_ipa_keyboard_sync_validation_on_wordset_term_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids = []): void {
    if ($taxonomy !== 'wordset') {
        return;
    }

    $post = get_post((int) $object_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
        return;
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent' => (int) $object_id,
        'no_found_rows' => true,
    ]);

    foreach ((array) $recording_ids as $recording_id) {
        ll_tools_ipa_keyboard_update_recording_validation((int) $recording_id);
    }
}
add_action('set_object_terms', 'll_tools_ipa_keyboard_sync_validation_on_wordset_term_change', 10, 6);

function ll_tools_ipa_keyboard_text_matches_pattern(string $value, string $query): bool {
    $value = trim($value);
    $query = trim($query);
    if ($query === '') {
        return true;
    }

    if (strpos($query, '*') !== false || strpos($query, '?') !== false) {
        $pattern = preg_quote($query, '/');
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], $pattern);
        return preg_match('/' . $pattern . '/iu', $value) === 1;
    }

    if (function_exists('mb_stripos')) {
        return mb_stripos($value, $query, 0, 'UTF-8') !== false;
    }

    return stripos($value, $query) !== false;
}

function ll_tools_ipa_keyboard_recording_matches_search(array $payload, string $query, string $scope = 'both'): bool {
    $query = trim($query);
    if ($query === '') {
        return true;
    }

    $written_values = [
        (string) ($payload['word_text'] ?? ''),
        (string) ($payload['recording_text'] ?? ''),
    ];
    $transcription_values = [
        (string) ($payload['recording_ipa'] ?? ''),
    ];

    if ($scope === 'written') {
        foreach ($written_values as $value) {
            if (ll_tools_ipa_keyboard_text_matches_pattern($value, $query)) {
                return true;
            }
        }
        return false;
    }

    if ($scope === 'transcription') {
        foreach ($transcription_values as $value) {
            if (ll_tools_ipa_keyboard_text_matches_pattern($value, $query)) {
                return true;
            }
        }
        return false;
    }

    foreach (array_merge($written_values, $transcription_values) as $value) {
        if (ll_tools_ipa_keyboard_text_matches_pattern($value, $query)) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_build_search_row_payload(int $recording_id, int $wordset_id, array $word_info): array {
    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa'));
    $payload = ll_tools_ipa_keyboard_build_recording_payload($recording_id, (int) wp_get_post_parent_id($recording_id), $word_info, $recording_ipa);
    $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);

    $payload['issues'] = array_values((array) ($validation['active'] ?? []));
    $payload['ignored_issues'] = array_values((array) ($validation['ignored'] ?? []));
    $payload['issue_count'] = count($payload['issues']);
    $payload['ignored_issue_count'] = count($payload['ignored_issues']);

    return $payload;
}

function ll_tools_ipa_keyboard_search_recordings(
    int $wordset_id,
    string $query = '',
    string $scope = 'both',
    bool $issues_only = false,
    int $limit = 200
): array {
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [
            'results' => [],
            'total_matches' => 0,
            'shown_count' => 0,
            'has_more' => false,
        ];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
        'no_found_rows' => true,
    ]);
    if (empty($recording_ids)) {
        return [
            'results' => [],
            'total_matches' => 0,
            'shown_count' => 0,
            'has_more' => false,
        ];
    }

    $word_display = ll_tools_ipa_keyboard_get_word_display_map($word_ids);
    $results = [];
    $total_matches = 0;

    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        $word_id = (int) wp_get_post_parent_id($recording_id);
        $payload = ll_tools_ipa_keyboard_build_search_row_payload(
            $recording_id,
            $wordset_id,
            (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => ''])
        );

        if ($issues_only && (int) ($payload['issue_count'] ?? 0) <= 0) {
            continue;
        }

        if (!ll_tools_ipa_keyboard_recording_matches_search($payload, $query, $scope)) {
            continue;
        }

        $total_matches++;
        if (count($results) < $limit) {
            $results[] = $payload;
        }
    }

    usort($results, static function (array $left, array $right): int {
        $word_compare = ll_tools_locale_compare_strings((string) ($left['word_text'] ?? ''), (string) ($right['word_text'] ?? ''));
        if ($word_compare !== 0) {
            return $word_compare;
        }

        return ll_tools_locale_compare_strings((string) ($left['recording_text'] ?? ''), (string) ($right['recording_text'] ?? ''));
    });

    return [
        'results' => $results,
        'total_matches' => $total_matches,
        'shown_count' => count($results),
        'has_more' => ($total_matches > count($results)),
    ];
}

function ll_tools_ipa_keyboard_prepare_add_symbols(string $input, string $mode = 'ipa'): array {
    $input = trim($input);
    if ($input === '') {
        return [];
    }

    if (function_exists('ll_tools_word_grid_sanitize_ipa')) {
        $input = ll_tools_word_grid_sanitize_ipa($input, $mode);
    }

    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($input, $mode)
        : preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($tokens)) {
        return [];
    }

    $clean = [];
    foreach ($tokens as $token) {
        if (function_exists('ll_tools_word_grid_is_special_ipa_token')
            && !ll_tools_word_grid_is_special_ipa_token($token, $mode)) {
            continue;
        }
        $clean[$token] = true;
    }

    return array_keys($clean);
}

function ll_tools_ipa_keyboard_get_wordset_term(int $wordset_id): ?WP_Term {
    if ($wordset_id <= 0) {
        return null;
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset) || !($wordset instanceof WP_Term)) {
        return null;
    }

    return $wordset;
}

function ll_tools_ipa_keyboard_update_recording_fields(
    int $recording_id,
    int $wordset_id,
    array $fields
): array {
    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return [];
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return [];
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || !in_array($wordset_id, array_map('intval', $wordset_ids), true)) {
        return [];
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';

    $previous_text_raw = (string) get_post_meta($recording_id, 'recording_text', true);
    $previous_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text($previous_text_raw)
        : sanitize_text_field($previous_text_raw);
    $previous_ipa_raw = (string) get_post_meta($recording_id, 'recording_ipa', true);
    $previous_ipa = ll_tools_word_grid_normalize_ipa_output($previous_ipa_raw, $transcription_mode);
    $previous_symbol_counts = ll_tools_ipa_keyboard_count_special_symbols($previous_ipa, $transcription_mode);
    $previous_letter_map_matches = ll_tools_ipa_keyboard_build_recording_letter_map_matches(
        $previous_text,
        $previous_ipa,
        $wordset_language,
        $transcription_mode
    );

    $recording_text = $previous_text;
    if (array_key_exists('recording_text', $fields)) {
        $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
            ? ll_tools_word_grid_sanitize_non_ipa_text((string) $fields['recording_text'])
            : sanitize_text_field((string) $fields['recording_text']);
        if ($recording_text !== $previous_text_raw) {
            if ($recording_text !== '') {
                update_post_meta($recording_id, 'recording_text', $recording_text);
            } else {
                delete_post_meta($recording_id, 'recording_text');
            }
        }
    }

    $clean_ipa = $previous_ipa_raw;
    if (array_key_exists('recording_ipa', $fields)) {
        $clean_ipa = function_exists('ll_tools_word_grid_sanitize_ipa')
            ? ll_tools_word_grid_sanitize_ipa((string) $fields['recording_ipa'], $transcription_mode)
            : sanitize_text_field((string) $fields['recording_ipa']);
        if ($clean_ipa !== $previous_ipa_raw) {
            if ($clean_ipa !== '') {
                update_post_meta($recording_id, 'recording_ipa', $clean_ipa);
            } else {
                delete_post_meta($recording_id, 'recording_ipa');
            }
        }
    }

    $recording_ipa = ll_tools_word_grid_normalize_ipa_output($clean_ipa, $transcription_mode);
    $symbol_counts = ll_tools_ipa_keyboard_count_special_symbols($recording_ipa, $transcription_mode);
    $next_letter_map_matches = ll_tools_ipa_keyboard_build_recording_letter_map_matches(
        $recording_text,
        $recording_ipa,
        $wordset_language,
        $transcription_mode
    );
    $text_changed = ($recording_text !== $previous_text);
    $ipa_changed = ($recording_ipa !== $previous_ipa);
    $letter_map_refresh_required = ($text_changed || $ipa_changed);

    if ($ipa_changed) {
        ll_tools_ipa_keyboard_update_cached_special_symbols(
            $wordset_id,
            $previous_symbol_counts,
            $symbol_counts,
            $recording_id,
            $transcription_mode
        );
    }

    if ($letter_map_refresh_required) {
        ll_tools_ipa_keyboard_update_cached_letter_map(
            $wordset_id,
            $previous_letter_map_matches,
            $next_letter_map_matches,
            $wordset_language,
            $transcription_mode
        );
    }

    ll_tools_ipa_keyboard_update_recording_validation($recording_id);
    $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
    $word_display = ll_tools_ipa_keyboard_get_word_display_map([$word_id]);
    $recording_payload = ll_tools_ipa_keyboard_build_recording_payload(
        $recording_id,
        $word_id,
        $word_display[$word_id] ?? ['word_text' => '', 'translation' => ''],
        $recording_ipa
    );
    $recording_payload['issues'] = array_values((array) ($validation['active'] ?? []));
    $recording_payload['ignored_issues'] = array_values((array) ($validation['ignored'] ?? []));
    $recording_payload['issue_count'] = count($recording_payload['issues']);
    $recording_payload['ignored_issue_count'] = count($recording_payload['ignored_issues']);

    return [
        'recording_id' => $recording_id,
        'recording_text' => $recording_text,
        'recording_ipa' => $recording_ipa,
        'recording' => $recording_payload,
        'previous_symbols' => array_keys($previous_symbol_counts),
        'previous_symbol_counts' => $previous_symbol_counts,
        'symbols' => array_keys($symbol_counts),
        'symbol_counts' => $symbol_counts,
        'letter_map_refresh_required' => $letter_map_refresh_required,
        'validation' => $validation,
    ];
}

add_action('wp_ajax_ll_tools_get_ipa_keyboard_data', 'll_tools_get_ipa_keyboard_data_handler');
function ll_tools_get_ipa_keyboard_data_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    $symbols = ll_tools_ipa_keyboard_build_symbol_data($wordset_id);
    $letter_map = ll_tools_ipa_keyboard_build_letter_map_data($wordset_id);

    wp_send_json_success([
        'wordset' => [
            'id' => (int) $wordset_id,
            'name' => (string) $wordset->name,
        ],
        'transcription' => $transcription,
        'symbols' => $symbols,
        'letter_map' => $letter_map,
        'can_edit' => ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id),
        'validation_config' => ll_tools_ipa_keyboard_build_validation_config_payload($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_get_ipa_keyboard_letter_map', 'll_tools_get_ipa_keyboard_letter_map_handler');
function ll_tools_get_ipa_keyboard_letter_map_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success([
        'wordset' => [
            'id' => (int) $wordset_id,
            'name' => (string) $wordset->name,
        ],
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
        'letter_map' => ll_tools_ipa_keyboard_build_letter_map_data($wordset_id, false),
        'can_edit' => ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_get_ipa_keyboard_symbols', 'll_tools_get_ipa_keyboard_symbols_handler');
function ll_tools_get_ipa_keyboard_symbols_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success([
        'wordset' => [
            'id' => (int) $wordset_id,
            'name' => (string) $wordset->name,
        ],
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
        'symbols' => ll_tools_ipa_keyboard_build_symbol_data($wordset_id),
        'can_edit' => ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_update_recording_ipa', 'll_tools_update_recording_ipa_handler');
function ll_tools_update_recording_ipa_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($recording_id <= 0 || $wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Missing data', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    $payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [
        'recording_ipa' => (string) ($_POST['recording_ipa'] ?? ''),
    ]);
    if (empty($payload)) {
        wp_send_json_error('Invalid recording', 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success($payload);
}

add_action('wp_ajax_ll_tools_search_ipa_keyboard_recordings', 'll_tools_search_ipa_keyboard_recordings_handler');
function ll_tools_search_ipa_keyboard_recordings_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    $query = sanitize_text_field((string) ($_POST['query'] ?? ''));
    $scope = sanitize_key((string) ($_POST['scope'] ?? 'both'));
    if (!in_array($scope, ['written', 'transcription', 'both'], true)) {
        $scope = 'both';
    }
    $issues_only = !empty($_POST['issues_only']);
    $results = ll_tools_ipa_keyboard_search_recordings($wordset_id, $query, $scope, $issues_only, 200);
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'wordset' => [
            'id' => (int) $wordset_id,
            'name' => (string) $wordset->name,
        ],
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
        'results' => array_values((array) ($results['results'] ?? [])),
        'total_matches' => (int) ($results['total_matches'] ?? 0),
        'shown_count' => (int) ($results['shown_count'] ?? 0),
        'has_more' => !empty($results['has_more']),
        'issues_only' => $issues_only,
        'can_edit' => ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id),
        'validation_config' => ll_tools_ipa_keyboard_build_validation_config_payload($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_update_ipa_keyboard_recording', 'll_tools_update_ipa_keyboard_recording_handler');
function ll_tools_update_ipa_keyboard_recording_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($recording_id <= 0 || $wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Missing data', 400);
    }

    $payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [
        'recording_text' => (string) ($_POST['recording_text'] ?? ''),
        'recording_ipa' => (string) ($_POST['recording_ipa'] ?? ''),
    ]);
    if (empty($payload)) {
        wp_send_json_error('Invalid recording', 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success($payload);
}

add_action('wp_ajax_ll_tools_save_ipa_keyboard_validation_config', 'll_tools_save_ipa_keyboard_validation_config_handler');
function ll_tools_save_ipa_keyboard_validation_config_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    if ((string) ($transcription['mode'] ?? 'ipa') !== 'ipa') {
        wp_send_json_error('Rules unavailable', 400);
    }

    $disabled_builtin_rules = isset($_POST['disabled_builtin_rules']) && is_array($_POST['disabled_builtin_rules'])
        ? array_map('sanitize_key', wp_unslash((array) $_POST['disabled_builtin_rules']))
        : [];
    $custom_rules = isset($_POST['custom_rules']) && is_array($_POST['custom_rules'])
        ? wp_unslash((array) $_POST['custom_rules'])
        : [];

    ll_tools_ipa_keyboard_update_wordset_validation_config($wordset_id, [
        'disabled_builtin_rules' => $disabled_builtin_rules,
        'custom_rules' => $custom_rules,
    ]);
    ll_tools_ipa_keyboard_rescan_wordset_validations($wordset_id);
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'validation_config' => ll_tools_ipa_keyboard_build_validation_config_payload($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_toggle_ipa_keyboard_validation_exception', 'll_tools_toggle_ipa_keyboard_validation_exception_handler');
function ll_tools_toggle_ipa_keyboard_validation_exception_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $rule_key = sanitize_text_field((string) ($_POST['rule_key'] ?? ''));
    $enabled = !empty($_POST['enabled']);
    if ($recording_id <= 0 || $wordset_id <= 0 || $rule_key === '' || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Missing data', 400);
    }

    $payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, []);
    if (empty($payload)) {
        wp_send_json_error('Invalid recording', 400);
    }

    ll_tools_ipa_keyboard_update_recording_validation_exception($recording_id, $wordset_id, $rule_key, $enabled);
    ll_tools_ipa_keyboard_update_recording_validation($recording_id);
    $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'recording_id' => $recording_id,
        'validation' => $validation,
    ]);
}

add_action('wp_ajax_ll_tools_add_wordset_ipa_symbols', 'll_tools_add_wordset_ipa_symbols_handler');
function ll_tools_add_wordset_ipa_symbols_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $symbols = ll_tools_ipa_keyboard_prepare_add_symbols((string) ($_POST['symbols'] ?? ''), $transcription_mode);
    if (empty($symbols)) {
        wp_send_json_error('No symbols found', 400);
    }

    $existing = function_exists('ll_tools_word_grid_get_wordset_ipa_manual_symbols')
        ? ll_tools_word_grid_get_wordset_ipa_manual_symbols($wordset_id)
        : [];

    $merged = array_values(array_unique(array_merge($existing, $symbols)));
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $merged = ll_tools_sort_secondary_text_symbols($merged, $transcription_mode);
    }
    update_term_meta($wordset_id, 'll_wordset_ipa_manual_symbols', $merged);
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'symbols' => $merged,
    ]);
}

add_action('wp_ajax_ll_tools_update_wordset_ipa_letter_map', 'll_tools_update_wordset_ipa_letter_map_handler');
function ll_tools_update_wordset_ipa_letter_map_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $letter_raw = (string) ($_POST['letter'] ?? '');
    $letter = ll_tools_ipa_keyboard_normalize_letter_key($letter_raw, $wordset_language);
    if ($letter === '') {
        wp_send_json_error('Invalid letter', 400);
    }

    $manual_map = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_manual_map')
        ? ll_tools_word_grid_get_wordset_ipa_letter_manual_map($wordset_id)
        : get_term_meta($wordset_id, 'll_wordset_ipa_letter_manual_map', true);
    if (!is_array($manual_map)) {
        $manual_map = [];
    }

    $clear = !empty($_POST['clear']);
    $symbols_raw = trim((string) ($_POST['symbols'] ?? ''));

    if ($clear || $symbols_raw === '') {
        unset($manual_map[$letter]);
    } else {
        if (function_exists('ll_tools_word_grid_sanitize_ipa')) {
            $symbols_raw = ll_tools_word_grid_sanitize_ipa($symbols_raw, $transcription_mode);
        }
        $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
            ? ll_tools_word_grid_tokenize_ipa($symbols_raw, $transcription_mode)
            : preg_split('//u', $symbols_raw, -1, PREG_SPLIT_NO_EMPTY);

        $clean_tokens = [];
        $seen = [];
        foreach ((array) $tokens as $token) {
            $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $token, $transcription_mode);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $clean_tokens[] = $token;
        }

        if (empty($clean_tokens)) {
            unset($manual_map[$letter]);
        } else {
            $manual_map[$letter] = $clean_tokens;
        }
    }

    if (empty($manual_map)) {
        delete_term_meta($wordset_id, 'll_wordset_ipa_letter_manual_map');
    } else {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_manual_map', $manual_map);
    }
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'letter' => $letter,
        'manual' => array_values((array) ($manual_map[$letter] ?? [])),
    ]);
}

add_action('wp_ajax_ll_tools_block_wordset_ipa_letter_mapping', 'll_tools_block_wordset_ipa_letter_mapping_handler');
function ll_tools_block_wordset_ipa_letter_mapping_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $letter_raw = (string) ($_POST['letter'] ?? '');
    $symbol_raw = (string) ($_POST['symbol'] ?? '');
    $letter = ll_tools_ipa_keyboard_normalize_letter_key($letter_raw, $wordset_language);
    $symbol = ll_tools_ipa_keyboard_normalize_ipa_token($symbol_raw, $transcription_mode);

    if ($letter === '' || $symbol === '') {
        wp_send_json_error('Invalid mapping', 400);
    }

    $blocklist = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_blocklist')
        ? ll_tools_word_grid_get_wordset_ipa_letter_blocklist($wordset_id)
        : get_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', true);
    if (!is_array($blocklist)) {
        $blocklist = [];
    }

    if (!isset($blocklist[$letter])) {
        $blocklist[$letter] = [];
    }
    if (!in_array($symbol, $blocklist[$letter], true)) {
        $blocklist[$letter][] = $symbol;
    }

    if (empty($blocklist)) {
        delete_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist');
    } else {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', $blocklist);
    }

    if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
        ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    }
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'letter' => $letter,
        'symbol' => $symbol,
    ]);
}

add_action('wp_ajax_ll_tools_unblock_wordset_ipa_letter_mapping', 'll_tools_unblock_wordset_ipa_letter_mapping_handler');
function ll_tools_unblock_wordset_ipa_letter_mapping_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error('Invalid word set', 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $letter_raw = (string) ($_POST['letter'] ?? '');
    $symbol_raw = (string) ($_POST['symbol'] ?? '');
    $letter = ll_tools_ipa_keyboard_normalize_letter_key($letter_raw, $wordset_language);
    $symbol = ll_tools_ipa_keyboard_normalize_ipa_token($symbol_raw, $transcription_mode);

    if ($letter === '' || $symbol === '') {
        wp_send_json_error('Invalid mapping', 400);
    }

    $blocklist = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_blocklist')
        ? ll_tools_word_grid_get_wordset_ipa_letter_blocklist($wordset_id)
        : get_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', true);
    if (!is_array($blocklist)) {
        $blocklist = [];
    }

    if (!empty($blocklist[$letter])) {
        $blocklist[$letter] = array_values(array_filter(
            (array) $blocklist[$letter],
            function ($entry) use ($symbol) {
                return (string) $entry !== (string) $symbol;
            }
        ));
        if (empty($blocklist[$letter])) {
            unset($blocklist[$letter]);
        }
    }

    if (empty($blocklist)) {
        delete_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist');
    } else {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', $blocklist);
    }

    if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
        ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    }
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'letter' => $letter,
        'symbol' => $symbol,
    ]);
}
