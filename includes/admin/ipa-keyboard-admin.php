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
    return in_array($tab, ['map', 'symbols', 'search', 'orthography'], true) ? $tab : 'map';
}

function ll_tools_ipa_keyboard_sanitize_search_query($raw): string {
    $query = is_scalar($raw) ? (string) wp_unslash((string) $raw) : '';
    $query = function_exists('wp_check_invalid_utf8') ? wp_check_invalid_utf8($query) : $query;
    $query = html_entity_decode($query, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $query = preg_replace('/[\r\n\t]+/u', ' ', $query);
    return trim((string) $query);
}

function ll_tools_ipa_keyboard_sanitize_search_page($raw): int {
    $page = is_scalar($raw) ? (int) $raw : 1;
    return max(1, $page);
}

function ll_tools_ipa_keyboard_sanitize_search_per_page($raw, int $fallback = 0): int {
    $per_page = is_scalar($raw) ? (int) $raw : $fallback;
    if ($per_page <= 0) {
        return max(0, $fallback);
    }

    return max(1, min(500, $per_page));
}

function ll_tools_ipa_keyboard_get_initial_search_results_per_page(): int {
    $per_page = (int) apply_filters('ll_tools_ipa_keyboard_initial_search_results_per_page', 20);
    return ll_tools_ipa_keyboard_sanitize_search_per_page($per_page, 20);
}

function ll_tools_ipa_keyboard_get_transcription_mode_for_wordset(int $wordset_id): string {
    static $runtime_cache = [];

    $cache_key = (int) $wordset_id;
    if (isset($runtime_cache[$cache_key])) {
        return $runtime_cache[$cache_key];
    }

    $mode = function_exists('ll_tools_get_wordset_recording_transcription_mode')
        ? sanitize_key((string) ll_tools_get_wordset_recording_transcription_mode($wordset_id > 0 ? [$wordset_id] : [], true))
        : 'ipa';
    $runtime_cache[$cache_key] = in_array($mode, ['ipa', 'transliteration', 'transcription'], true) ? $mode : 'ipa';
    return $runtime_cache[$cache_key];
}

function ll_tools_ipa_keyboard_get_requested_search_state(): array {
    $query = isset($_GET['search']) ? ll_tools_ipa_keyboard_sanitize_search_query($_GET['search']) : '';
    $scope = isset($_GET['scope']) ? sanitize_key((string) wp_unslash($_GET['scope'])) : 'both';
    if (!in_array($scope, ['written', 'transcription', 'both'], true)) {
        $scope = 'both';
    }

    $issues_only = isset($_GET['issues'])
        ? (sanitize_text_field(wp_unslash((string) $_GET['issues'])) === '1')
        : false;
    $review_only = isset($_GET['review'])
        ? (sanitize_text_field(wp_unslash((string) $_GET['review'])) === '1')
        : false;
    $exact_transcription = isset($_GET['exact'])
        ? (sanitize_text_field(wp_unslash((string) $_GET['exact'])) === '1')
        : false;
    $search_page = isset($_GET['search_page'])
        ? ll_tools_ipa_keyboard_sanitize_search_page(wp_unslash($_GET['search_page']))
        : 1;

    return [
        'query' => $query,
        'scope' => $scope,
        'issues_only' => $issues_only,
        'review_only' => $review_only,
        'exact_transcription' => $exact_transcription,
        'page' => $search_page,
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
    $admin_script_deps = ['jquery'];
    if (function_exists('ll_tools_word_edit_modal_enqueue_assets')) {
        ll_tools_word_edit_modal_enqueue_assets($selected_wordset_id);
        $admin_script_deps[] = 'll-tools-word-edit-modal';
    }
    ll_enqueue_asset_by_timestamp('/js/ipa-keyboard-admin.js', 'll-ipa-keyboard-admin-js', $admin_script_deps, true);

    wp_localize_script('ll-ipa-keyboard-admin-js', 'llIpaKeyboardAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
        'selectedWordsetId' => $selected_wordset_id,
        'initialTab' => ll_tools_ipa_keyboard_get_requested_tab(),
        'initialSearch' => $initial_search,
        'searchInitialPerPage' => ll_tools_ipa_keyboard_get_initial_search_results_per_page(),
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
            'downloadRecording' => __('Download recording', 'll-tools-text-domain'),
            'tabMap' => __('Letter to IPA Map', 'll-tools-text-domain'),
            'tabSymbols' => __('IPA Special Characters', 'll-tools-text-domain'),
            'tabSearch' => __('Search', 'll-tools-text-domain'),
            'tabOrthography' => __('IPA to Orthography', 'll-tools-text-domain'),
            'searchLoading' => __('Searching recordings...', 'll-tools-text-domain'),
            'searchLoadingHint' => __('This can take a moment for larger word sets.', 'll-tools-text-domain'),
            'searchResultsEmpty' => __('No recordings matched this search.', 'll-tools-text-domain'),
            'searchSummary' => __('%1$d result', 'll-tools-text-domain'),
            'searchSummaryPlural' => __('%1$d results', 'll-tools-text-domain'),
            'searchFilteredSummary' => __('Showing %1$d flagged recording', 'll-tools-text-domain'),
            'searchFilteredSummaryPlural' => __('Showing %1$d flagged recordings', 'll-tools-text-domain'),
            'searchReviewSummary' => __('Showing %1$d transcription needing review', 'll-tools-text-domain'),
            'searchReviewSummaryPlural' => __('Showing %1$d transcriptions needing review', 'll-tools-text-domain'),
            'searchSummaryRange' => __('Showing %1$d-%2$d of %3$d results', 'll-tools-text-domain'),
            'searchFilteredSummaryRange' => __('Showing %1$d-%2$d of %3$d flagged recordings', 'll-tools-text-domain'),
            'searchReviewSummaryRange' => __('Showing %1$d-%2$d of %3$d transcriptions needing review', 'll-tools-text-domain'),
            'searchTooMany' => __('Showing the first %1$d results. Narrow the search to see more.', 'll-tools-text-domain'),
            'searchPaginationLabel' => __('Search result pages', 'll-tools-text-domain'),
            'searchPaginationPage' => __('Page %1$d of %2$d', 'll-tools-text-domain'),
            'searchPaginationGoToPage' => __('Go to page %1$d', 'll-tools-text-domain'),
            'searchPaginationCurrentPage' => __('Current page %1$d', 'll-tools-text-domain'),
            'searchPaginationPrevious' => __('Previous', 'll-tools-text-domain'),
            'searchPaginationNext' => __('Next', 'll-tools-text-domain'),
            'searchLoadMore' => __('Load more', 'll-tools-text-domain'),
            'searchLoadingMore' => __('Loading more...', 'll-tools-text-domain'),
            'searchWordLabel' => __('Word', 'll-tools-text-domain'),
            'searchImageLabel' => __('Image', 'll-tools-text-domain'),
            'searchTranscriptionsLabel' => __('Transcriptions', 'll-tools-text-domain'),
            'searchCategoriesLabel' => __('Categories', 'll-tools-text-domain'),
            'searchIssuesLabel' => __('Checks', 'll-tools-text-domain'),
            'searchNoImage' => __('No image', 'll-tools-text-domain'),
            'searchNoCategories' => __('No categories', 'll-tools-text-domain'),
            'searchNoIssues' => __('No warnings', 'll-tools-text-domain'),
            'searchIgnoredLabel' => __('Ignored', 'll-tools-text-domain'),
            'searchReviewIssues' => __('Review warnings', 'll-tools-text-domain'),
            'searchPatternHint' => __('Use * as a wildcard.', 'll-tools-text-domain'),
            'searchRulesSummary' => __('IPA checks and typo rules', 'll-tools-text-domain'),
            'searchRulesSummaryHint' => __('Expand to review built-in checks and wordset-specific IPA rules.', 'll-tools-text-domain'),
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
            'searchReviewOnlyLabel' => __('Only needs review', 'll-tools-text-domain'),
            'searchReviewPendingTitle' => __('Needs review', 'll-tools-text-domain'),
            'searchReviewPendingMessage' => __('This transcription is marked for follow-up review.', 'll-tools-text-domain'),
            'searchReviewConfirm' => __('Mark reviewed', 'll-tools-text-domain'),
            'searchReviewFlag' => __('Mark for review', 'll-tools-text-domain'),
            'searchReviewTextLabel' => __('Orthography', 'll-tools-text-domain'),
            'searchReviewIpaLabel' => __('Pronunciation', 'll-tools-text-domain'),
            'searchReviewNeedsReviewTag' => __('Needs review', 'll-tools-text-domain'),
            'searchReviewReviewedTag' => __('Reviewed', 'll-tools-text-domain'),
            'searchReviewMarkAsReviewed' => __('Mark as reviewed', 'll-tools-text-domain'),
            'searchReviewMarkAsNeedsReview' => __('Mark as needing review', 'll-tools-text-domain'),
            'searchReviewMarkFieldAsReviewed' => __('%s: mark as reviewed', 'll-tools-text-domain'),
            'searchReviewMarkFieldAsNeedsReview' => __('%s: mark as needing review', 'll-tools-text-domain'),
            'searchReviewConfirmText' => __('Mark text reviewed', 'll-tools-text-domain'),
            'searchReviewConfirmIpa' => __('Mark pronunciation reviewed', 'll-tools-text-domain'),
            'searchReviewFlagText' => __('Mark text for review', 'll-tools-text-domain'),
            'searchReviewFlagIpa' => __('Mark pronunciation for review', 'll-tools-text-domain'),
            'searchMarkedForReview' => __('Marked for review.', 'll-tools-text-domain'),
            'searchReviewed' => __('Reviewed.', 'll-tools-text-domain'),
            'searchEditWord' => __('Edit word', 'll-tools-text-domain'),
            'searchOpeningWordEditor' => __('Opening word editor...', 'll-tools-text-domain'),
            'searchWordEditorOpened' => __('Word editor opened.', 'll-tools-text-domain'),
            'searchWordEditorError' => __('Unable to open the word editor.', 'll-tools-text-domain'),
            'searchEditSavingBeforeOpen' => __('Saving changes before opening the word editor...', 'll-tools-text-domain'),
            'searchRowsSynced' => __('Transcription rows updated.', 'll-tools-text-domain'),
            'searchExceptionIgnore' => __('Ignore for this transcription', 'll-tools-text-domain'),
            'searchExceptionRestore' => __('Undo exception', 'll-tools-text-domain'),
            'searchApproveSymbolMapping' => __('Approve %1$s symbol and map it to %2$s in orthography', 'll-tools-text-domain'),
            'searchApprovedSymbolMapping' => __('Approved symbol mapping.', 'll-tools-text-domain'),
            'keyboardFlagIllegal' => __('Flag as illegal symbol', 'll-tools-text-domain'),
            'keyboardFlagIllegalConfirm' => __('Mark %s as illegal for this word set?', 'll-tools-text-domain'),
            'keyboardFlagIllegalSaving' => __('Flagging symbol and rescanning this word set...', 'll-tools-text-domain'),
            'keyboardFlagIllegalSaved' => __('Symbol marked illegal and checks rescanned.', 'll-tools-text-domain'),
            'keyboardOptionalGroupsShow' => __('Show %s', 'll-tools-text-domain'),
            'keyboardOptionalGroupsHide' => __('Hide %s', 'll-tools-text-domain'),
            'searchSaveFailed' => __('Save failed', 'll-tools-text-domain'),
            'orthographyLoading' => __('Loading orthography conversion data...', 'll-tools-text-domain'),
            'orthographyUnsupported' => __('IPA-to-orthography conversion is only available when this word set uses IPA transcription mode.', 'll-tools-text-domain'),
            'orthographyRulesTitle' => __('Detected conversion rules', 'll-tools-text-domain'),
            'orthographyRulesDescription' => __('Review the inferred IPA-to-orthography rules, block bad guesses, and add manual overrides.', 'll-tools-text-domain'),
            'orthographyRulesEmpty' => __('No usable IPA/text pairings were found yet for this word set.', 'll-tools-text-domain'),
            'orthographyRuleSegment' => __('IPA segment', 'll-tools-text-domain'),
            'orthographyRuleAuto' => __('Auto rules', 'll-tools-text-domain'),
            'orthographyRuleManual' => __('Manual overrides', 'll-tools-text-domain'),
            'orthographyRuleExamples' => __('Examples', 'll-tools-text-domain'),
            'orthographyRuleAddTitle' => __('Add manual rule', 'll-tools-text-domain'),
            'orthographyRuleAddSegment' => __('IPA segment', 'll-tools-text-domain'),
            'orthographyRuleAddSegmentPlaceholder' => __('e.g. ʃ or t͡ʃ', 'll-tools-text-domain'),
            'orthographyRuleAddContext' => __('Position', 'll-tools-text-domain'),
            'orthographyRuleAddOutput' => __('Orthography', 'll-tools-text-domain'),
            'orthographyRuleAddOutputPlaceholder' => __('e.g. sh', 'll-tools-text-domain'),
            'orthographyRuleAddButton' => __('Add rule', 'll-tools-text-domain'),
            'orthographyRuleAddMissing' => __('Enter both an IPA segment and an orthography output.', 'll-tools-text-domain'),
            'orthographyRuleSave' => __('Save rules', 'll-tools-text-domain'),
            'orthographyRuleClear' => __('Clear', 'll-tools-text-domain'),
            'orthographyRuleBlock' => __('Hide auto rule', 'll-tools-text-domain'),
            'orthographyRuleUnblock' => __('Restore', 'll-tools-text-domain'),
            'orthographyRuleAny' => __('Anywhere', 'll-tools-text-domain'),
            'orthographyRuleFinal' => __('Word-final', 'll-tools-text-domain'),
            'orthographyRuleNonfinal' => __('Elsewhere', 'll-tools-text-domain'),
            'orthographyRuleBlockedTitle' => __('Hidden auto rules', 'll-tools-text-domain'),
            'orthographyIssuesTitle' => __('Contradicting words', 'll-tools-text-domain'),
            'orthographyIssuesDescription' => __('These saved IPA/text pairings do not match the current rules. You can approve a word as an exception or keep adjusting the rules.', 'll-tools-text-domain'),
            'orthographyIssuesEmpty' => __('No contradictions found with the current rules.', 'll-tools-text-domain'),
            'orthographyIssuesSummary' => __('%1$d contradicting word', 'll-tools-text-domain'),
            'orthographyIssuesSummaryPlural' => __('%1$d contradicting words', 'll-tools-text-domain'),
            'orthographyIssueActual' => __('Saved text', 'll-tools-text-domain'),
            'orthographyIssuePredicted' => __('Predicted text', 'll-tools-text-domain'),
            'orthographyIssueApprove' => __('Approve exception', 'll-tools-text-domain'),
            'orthographyIssueRestore' => __('Undo exception', 'll-tools-text-domain'),
            'orthographyIssueApproved' => __('Approved exception', 'll-tools-text-domain'),
            'orthographyIssueApplySuggestion' => __('Use suggested orthography', 'll-tools-text-domain'),
            'orthographyIssueApplyIpaSuggestion' => __('Use IPA: %1$s', 'll-tools-text-domain'),
            'orthographyIssueInlineChangeTo' => __('Change to: %s', 'll-tools-text-domain'),
            'orthographyIssueSuggestionApplied' => __('Suggestion saved.', 'll-tools-text-domain'),
            'orthographyIssueIpaSuggestionApplied' => __('IPA suggestion saved.', 'll-tools-text-domain'),
            'orthographyIssueSaveFirst' => __('Saved local edits. Click the suggestion again to apply it.', 'll-tools-text-domain'),
            'orthographyConvertTitle' => __('Words missing written text', 'll-tools-text-domain'),
            'orthographyConvertDescription' => __('Apply the current rules to words that have IPA saved but still need written text.', 'll-tools-text-domain'),
            'orthographyConvertEmpty' => __('No words are waiting for IPA-to-orthography conversion.', 'll-tools-text-domain'),
            'orthographyConvertSummary' => __('%1$d word ready to convert', 'll-tools-text-domain'),
            'orthographyConvertSummaryPlural' => __('%1$d words ready to convert', 'll-tools-text-domain'),
            'orthographyConvertSelectAll' => __('Select all', 'll-tools-text-domain'),
            'orthographyConvertClearSelection' => __('Clear selection', 'll-tools-text-domain'),
            'orthographyConvertSelected' => __('Convert selected', 'll-tools-text-domain'),
            'orthographyConvertOne' => __('Convert', 'll-tools-text-domain'),
            'orthographyConvertPreview' => __('Predicted text', 'll-tools-text-domain'),
            'orthographyConvertReason' => __('Status', 'll-tools-text-domain'),
            'orthographyConvertCannot' => __('Needs more rules', 'll-tools-text-domain'),
            'orthographyConvertCompleted' => __('Converted.', 'll-tools-text-domain'),
            'orthographyConvertNoSelection' => __('Select at least one word to convert.', 'll-tools-text-domain'),
            'orthographyConvertSaved' => __('Conversion saved.', 'll-tools-text-domain'),
            'orthographySummaryRules' => __('Rules', 'll-tools-text-domain'),
            'orthographySummaryIssues' => __('Contradictions', 'll-tools-text-domain'),
            'orthographySummaryQueue' => __('Missing text', 'll-tools-text-domain'),
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
    echo '<button type="button" class="ll-ipa-tab-button" id="ll-ipa-tab-orthography" data-ll-tab-trigger="orthography" role="tab" aria-controls="ll-ipa-panel-orthography" aria-selected="' . esc_attr($initial_tab === 'orthography' ? 'true' : 'false') . '">' . esc_html__('IPA to Orthography', 'll-tools-text-domain') . '</button>';
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
    echo '<h2>' . esc_html__('Search', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Search written text or IPA and edit recordings inline. Typo checks are available below as an advanced section.', 'll-tools-text-domain') . '</p>';
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
    echo '<label class="ll-ipa-search-toggle">';
    echo '<input type="checkbox" id="ll-ipa-search-review-only"' . checked(!empty($initial_search['review_only']), true, false) . ' />';
    echo '<span>' . esc_html__('Only needs review', 'll-tools-text-domain') . '</span>';
    echo '</label>';
    echo '<label class="ll-ipa-search-toggle">';
    echo '<input type="checkbox" id="ll-ipa-search-exact-transcription"' . checked(!empty($initial_search['exact_transcription']), true, false) . ' />';
    echo '<span>' . esc_html__('Exact letters + diacritics', 'll-tools-text-domain') . '</span>';
    echo '</label>';
    echo '<button type="button" class="button button-primary" id="ll-ipa-search-btn">' . esc_html__('Search', 'll-tools-text-domain') . '</button>';
    echo '</div>';
    echo '<details class="ll-ipa-search-help">';
    echo '<summary>' . esc_html__('Pattern help', 'll-tools-text-domain') . '</summary>';
    echo '<div class="ll-ipa-search-help-body">';
    echo '<p class="description">' . esc_html__('Use plain text for simple searches. For IPA context searches, switch to Transcription only and use one of these patterns:', 'll-tools-text-domain') . '</p>';
    echo '<ul class="ll-ipa-search-help-list">';
    echo '<li><code>k !> ʰ</code> ' . esc_html__('Find k not immediately followed by ʰ.', 'll-tools-text-domain') . '</li>';
    echo '<li><code>k > [i e]</code> ' . esc_html__('Find k immediately followed by i or e.', 'll-tools-text-domain') . '</li>';
    echo '<li><code>k < [a o u]</code> ' . esc_html__('Find k immediately preceded by a, o, or u.', 'll-tools-text-domain') . '</li>';
    echo '<li><code>rx:k(?!ʰ)</code> ' . esc_html__('Run a regex search on the current field.', 'll-tools-text-domain') . '</li>';
    echo '<li><code>*</code> / <code>?</code> ' . esc_html__('Use wildcard matches for simpler text searches.', 'll-tools-text-domain') . '</li>';
    echo '</ul>';
    echo '</div>';
    echo '</details>';
    echo '<div id="ll-ipa-search-summary" class="ll-ipa-search-summary" aria-live="polite"></div>';
    echo '<div id="ll-ipa-search-results" class="ll-ipa-search-results"></div>';
    echo '<div id="ll-ipa-search-rules" class="ll-ipa-search-rules"></div>';
    echo '</section>';

    echo '<section class="ll-ipa-panel" id="ll-ipa-panel-orthography" data-ll-tab-panel="orthography" role="tabpanel" aria-labelledby="ll-ipa-tab-orthography"' . ($initial_tab === 'orthography' ? '' : ' hidden') . '>';
    echo '<h2>' . esc_html__('IPA to Orthography', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Infer written-word spellings from IPA, inspect contradictions, and bulk-fill missing text once the rules look right.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-orthography-summary" class="ll-ipa-orthography-summary" aria-live="polite"></div>';
    echo '<div id="ll-ipa-orthography-rules" class="ll-ipa-orthography-rules"></div>';
    echo '<div id="ll-ipa-orthography-issues" class="ll-ipa-orthography-issues"></div>';
    echo '<div id="ll-ipa-orthography-convert" class="ll-ipa-orthography-convert"></div>';
    echo '</section>';

    echo '</div>';
    if (function_exists('ll_tools_word_edit_modal_host_html')) {
        echo ll_tools_word_edit_modal_host_html($selected_wordset_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    echo '</div>';
}

function ll_tools_ipa_keyboard_get_transcription_config(int $wordset_id = 0): array {
    $config = function_exists('ll_tools_get_wordset_recording_transcription_config')
        ? ll_tools_get_wordset_recording_transcription_config($wordset_id > 0 ? [$wordset_id] : [], true)
        : [
            'mode' => 'ipa',
            'label' => __('IPA', 'll-tools-text-domain'),
            'display_format' => 'brackets',
            'uses_ipa_font' => true,
            'supports_superscript' => true,
            'common_chars' => [],
            'common_chars_label' => '',
            'modifier_chars' => function_exists('ll_tools_get_secondary_text_keyboard_modifier_symbols') ? ll_tools_get_secondary_text_keyboard_modifier_symbols('ipa') : ['ʰ', 'ʲ', 'ʷ', 'ː'],
            'modifier_chars_label' => __('Diacritics and signs', 'll-tools-text-domain'),
            'wordset_chars_label' => __('Wordset symbols', 'll-tools-text-domain'),
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
            'keyboard_aria_label' => __('IPA symbols', 'll-tools-text-domain'),
        ];

    $mode = sanitize_key((string) ($config['mode'] ?? 'ipa'));
    if (!in_array($mode, ['ipa', 'transliteration', 'transcription'], true)) {
        $mode = 'ipa';
    }
    $config['mode'] = $mode;
    $config['supports_superscript'] = !empty($config['supports_superscript']) && $mode === 'ipa';

    $common_chars = [];
    foreach ((array) ($config['common_chars'] ?? []) as $symbol) {
        $normalized = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $mode);
        if ($normalized === '' || in_array($normalized, $common_chars, true)) {
            continue;
        }
        $common_chars[] = $normalized;
    }
    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $common_chars = ll_tools_sort_secondary_text_symbols($common_chars, $mode);
    }
    if ($mode === 'ipa') {
        $common_chars = [];
    }
    $config['common_chars'] = $common_chars;

    $modifier_chars = [];
    foreach ((array) ($config['modifier_chars'] ?? []) as $symbol) {
        $normalized = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $mode);
        if ($normalized === '' || in_array($normalized, $modifier_chars, true)) {
            continue;
        }
        $modifier_chars[] = $normalized;
    }
    if ($mode === 'ipa' && empty($modifier_chars) && function_exists('ll_tools_get_secondary_text_keyboard_modifier_symbols')) {
        $modifier_chars = ll_tools_get_secondary_text_keyboard_modifier_symbols($mode);
    }
    $config['modifier_chars'] = $mode === 'ipa' ? $modifier_chars : [];
    if (empty($config['modifier_chars_label'])) {
        $config['modifier_chars_label'] = __('Diacritics and signs', 'll-tools-text-domain');
    }

    if (empty($config['keyboard_aria_label'])) {
        $config['keyboard_aria_label'] = __('IPA symbols', 'll-tools-text-domain');
    }

    $keyboard_inventory = ll_tools_ipa_keyboard_get_keyboard_inventory($wordset_id, $mode);
    $config['keyboard_symbols'] = function_exists('ll_tools_compact_secondary_text_keyboard_symbols')
        ? ll_tools_compact_secondary_text_keyboard_symbols((array) ($keyboard_inventory['symbols'] ?? []), $mode)
        : array_values((array) ($keyboard_inventory['symbols'] ?? []));
    $config['keyboard_groups'] = function_exists('ll_tools_build_secondary_text_keyboard_groups')
        ? ll_tools_build_secondary_text_keyboard_groups(
            (array) ($keyboard_inventory['symbols'] ?? []),
            $mode,
            (array) ($keyboard_inventory['recording_counts'] ?? []),
            ['illegal_symbols' => (array) ($keyboard_inventory['illegal_symbols'] ?? [])]
        )
        : [];
    $detail_symbols = function_exists('ll_tools_flatten_secondary_text_keyboard_groups')
        ? ll_tools_flatten_secondary_text_keyboard_groups($config['keyboard_groups'])
        : array_values(array_unique(array_merge($config['modifier_chars'], $config['keyboard_symbols'])));
    $config['symbol_details'] = function_exists('ll_tools_get_secondary_text_keyboard_symbol_details')
        ? ll_tools_get_secondary_text_keyboard_symbol_details($detail_symbols, $mode)
        : [];
    $config['illegal_symbols'] = array_values((array) ($keyboard_inventory['illegal_symbols'] ?? []));

    return $config;
}

function ll_tools_ipa_keyboard_get_keyboard_inventory(int $wordset_id = 0, string $transcription_mode = ''): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [
            'symbols' => [],
            'recording_counts' => [],
            'illegal_symbols' => [],
            'mode' => $transcription_mode !== '' ? sanitize_key($transcription_mode) : 'ipa',
        ];
    }

    $mode = $transcription_mode !== ''
        ? sanitize_key($transcription_mode)
        : ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    if (!in_array($mode, ['ipa', 'transliteration', 'transcription'], true)) {
        $mode = 'ipa';
    }

    if (function_exists('ll_tools_word_grid_get_wordset_reviewed_ipa_symbol_inventory')) {
        $inventory = ll_tools_word_grid_get_wordset_reviewed_ipa_symbol_inventory($wordset_id);
    } else {
        $inventory = [
            'symbols' => function_exists('ll_tools_word_grid_get_wordset_ipa_special_chars')
                ? ll_tools_word_grid_get_wordset_ipa_special_chars($wordset_id)
                : [],
            'recording_counts' => [],
        ];
    }

    $illegal_symbols = function_exists('ll_tools_get_wordset_secondary_text_illegal_symbols')
        ? ll_tools_get_wordset_secondary_text_illegal_symbols($wordset_id, $mode)
        : [];
    $raw_counts = (array) ($inventory['recording_counts'] ?? []);
    $recording_counts = [];
    foreach ($raw_counts as $symbol => $count) {
        $normalized = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $mode);
        if ($normalized !== '') {
            $recording_counts[$normalized] = max((int) ($recording_counts[$normalized] ?? 0), (int) $count);
        }
    }

    $symbols = [];
    foreach ((array) ($inventory['symbols'] ?? []) as $symbol) {
        $normalized = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol, $mode);
        if ($normalized === '' || in_array($normalized, $symbols, true)) {
            continue;
        }
        if (function_exists('ll_tools_secondary_text_token_has_illegal_symbol')
            && ll_tools_secondary_text_token_has_illegal_symbol($normalized, $illegal_symbols) !== '') {
            continue;
        }
        $symbols[] = $normalized;
    }

    if (function_exists('ll_tools_sort_secondary_text_symbols')) {
        $symbols = ll_tools_sort_secondary_text_symbols($symbols, $mode);
    }

    return [
        'symbols' => $symbols,
        'recording_counts' => $recording_counts,
        'illegal_symbols' => array_values((array) $illegal_symbols),
        'mode' => $mode,
    ];
}

function ll_tools_ipa_keyboard_get_keyboard_symbols(int $wordset_id = 0, string $transcription_mode = ''): array {
    $inventory = ll_tools_ipa_keyboard_get_keyboard_inventory($wordset_id, $transcription_mode);
    $mode = (string) ($inventory['mode'] ?? ($transcription_mode !== '' ? sanitize_key($transcription_mode) : 'ipa'));
    $symbols = array_values((array) ($inventory['symbols'] ?? []));

    if (function_exists('ll_tools_compact_secondary_text_keyboard_symbols')) {
        $symbols = ll_tools_compact_secondary_text_keyboard_symbols($symbols, $mode);
    }

    return $symbols;
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

function ll_tools_ipa_keyboard_get_wordset_lesson_url_map(int $wordset_id): array {
    static $maps = [];

    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    if (isset($maps[$wordset_id])) {
        return $maps[$wordset_id];
    }

    $lesson_ids = get_posts([
        'post_type' => 'll_vocab_lesson',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query' => [
            [
                'key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
                'value' => (string) $wordset_id,
            ],
        ],
    ]);

    $map = [];
    foreach ((array) $lesson_ids as $lesson_id) {
        $lesson_id = (int) $lesson_id;
        if ($lesson_id <= 0) {
            continue;
        }

        $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        if ($category_id <= 0 || isset($map[$category_id])) {
            continue;
        }

        $permalink = (string) get_permalink($lesson_id);
        if ($permalink !== '') {
            $map[$category_id] = $permalink;
        }
    }

    $maps[$wordset_id] = $map;
    return $maps[$wordset_id];
}

function ll_tools_ipa_keyboard_get_word_category_payload(int $word_id, int $wordset_id = 0): array {
    if ($word_id <= 0) {
        return [];
    }

    $terms = wp_get_post_terms($word_id, 'word-category');
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $lesson_url_map = ll_tools_ipa_keyboard_get_wordset_lesson_url_map($wordset_id);
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
            'url' => (string) ($lesson_url_map[(int) $term->term_id] ?? ''),
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

function ll_tools_ipa_keyboard_build_recording_payload(
    int $recording_id,
    int $word_id,
    array $word_info,
    string $recording_ipa = '',
    int $wordset_id = 0
): array {
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
    $review_fields = ll_tools_ipa_keyboard_get_recording_review_fields($recording_id);

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
        'categories' => ll_tools_ipa_keyboard_get_word_category_payload($word_id, $wordset_id),
        'needs_review' => !empty(array_filter($review_fields)),
        'review_fields' => $review_fields,
        'review_note' => ll_tools_ipa_keyboard_get_recording_review_note($recording_id),
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
        if (function_exists('ll_tools_word_grid_recording_ipa_is_reviewed')
            && !ll_tools_word_grid_recording_ipa_is_reviewed($recording_id)) {
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
        $recording_payload = ll_tools_ipa_keyboard_build_recording_payload($recording_id, $word_id, $word_info, $recording_ipa, $wordset_id);

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
        $recording_payload = ll_tools_ipa_keyboard_build_recording_payload($recording_id, $word_id, $word_info, $recording_ipa, $wordset_id);

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
    if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_special_chars')) {
        return ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
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

function ll_tools_ipa_keyboard_approved_symbols_meta_key(): string {
    return 'll_wordset_approved_ipa_symbols';
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

if (!defined('LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK')) {
    define('LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK', 'll_tools_ipa_keyboard_update_recording_validation_async');
}

function ll_tools_ipa_keyboard_auto_review_meta_key(): string {
    return 'll_auto_transcription_needs_review';
}

function ll_tools_ipa_keyboard_review_fields_meta_key(): string {
    return 'll_auto_transcription_review_fields';
}

function ll_tools_ipa_keyboard_review_note_meta_key(): string {
    return 'll_auto_transcription_review_note';
}

function ll_tools_ipa_keyboard_supported_review_fields(): array {
    return [
        'recording_text' => __('Orthography', 'll-tools-text-domain'),
        'recording_ipa' => __('Pronunciation', 'll-tools-text-domain'),
    ];
}

function ll_tools_ipa_keyboard_normalize_review_field(string $field): string {
    $field = sanitize_key($field);
    if (in_array($field, ['recording_text', 'recordingtext', 'text', 'orthography', 'ortho'], true)) {
        return 'recording_text';
    }
    if (in_array($field, ['recording_ipa', 'recordingipa', 'ipa', 'transcription', 'pronunciation', 'phonetic'], true)) {
        return 'recording_ipa';
    }

    return '';
}

function ll_tools_ipa_keyboard_normalize_review_fields($fields): array {
    if (is_string($fields)) {
        $fields = preg_split('/[,;|]/', $fields);
    }

    $normalized = [];
    foreach ((array) $fields as $key => $field) {
        if (is_string($key)) {
            if (!$field) {
                continue;
            }
            $field_key = ll_tools_ipa_keyboard_normalize_review_field($key);
            if ($field_key !== '') {
                $normalized[$field_key] = true;
            }
            continue;
        }

        if (is_array($field)) {
            foreach ($field as $nested_field => $enabled) {
                if (!$enabled) {
                    continue;
                }
                $field_key = is_string($nested_field)
                    ? ll_tools_ipa_keyboard_normalize_review_field($nested_field)
                    : ll_tools_ipa_keyboard_normalize_review_field((string) $enabled);
                if ($field_key !== '') {
                    $normalized[$field_key] = true;
                }
            }
            continue;
        }

        $field_key = ll_tools_ipa_keyboard_normalize_review_field((string) $field);
        if ($field_key !== '') {
            $normalized[$field_key] = true;
        }
    }

    return array_values(array_keys($normalized));
}

function ll_tools_ipa_keyboard_get_recording_review_fields(int $recording_id): array {
    if ($recording_id <= 0) {
        return [
            'recording_text' => false,
            'recording_ipa' => false,
        ];
    }

    $fields = [
        'recording_text' => false,
        'recording_ipa' => false,
    ];
    $raw = get_post_meta($recording_id, ll_tools_ipa_keyboard_review_fields_meta_key(), true);
    if (is_array($raw)) {
        foreach ($raw as $field => $enabled) {
            $field_key = is_string($field)
                ? ll_tools_ipa_keyboard_normalize_review_field($field)
                : ll_tools_ipa_keyboard_normalize_review_field((string) $enabled);
            if ($field_key !== '') {
                $fields[$field_key] = is_string($field) ? !empty($enabled) : true;
            }
        }
    } elseif (is_string($raw) && trim($raw) !== '') {
        foreach (ll_tools_ipa_keyboard_normalize_review_fields($raw) as $field_key) {
            $fields[$field_key] = true;
        }
    }

    $has_field_meta = is_array($raw) ? !empty($raw) : (is_string($raw) && trim($raw) !== '');
    if (!$has_field_meta && (string) get_post_meta($recording_id, ll_tools_ipa_keyboard_auto_review_meta_key(), true) === '1') {
        $fields['recording_ipa'] = true;
    }

    return $fields;
}

function ll_tools_ipa_keyboard_get_recording_review_field_list(int $recording_id): array {
    $fields = ll_tools_ipa_keyboard_get_recording_review_fields($recording_id);
    return array_values(array_keys(array_filter($fields)));
}

function ll_tools_ipa_keyboard_recording_field_needs_review(int $recording_id, string $field): bool {
    $field_key = ll_tools_ipa_keyboard_normalize_review_field($field);
    if ($recording_id <= 0 || $field_key === '') {
        return false;
    }

    $fields = ll_tools_ipa_keyboard_get_recording_review_fields($recording_id);
    return !empty($fields[$field_key]);
}

function ll_tools_ipa_keyboard_get_recording_review_note(int $recording_id): string {
    if ($recording_id <= 0) {
        return '';
    }

    return trim((string) get_post_meta($recording_id, ll_tools_ipa_keyboard_review_note_meta_key(), true));
}

function ll_tools_ipa_keyboard_set_recording_review_note(int $recording_id, string $review_note): void {
    if ($recording_id <= 0) {
        return;
    }

    $review_note = sanitize_textarea_field($review_note);
    if ($review_note === '') {
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_review_note_meta_key());
        return;
    }

    update_post_meta($recording_id, ll_tools_ipa_keyboard_review_note_meta_key(), $review_note);
}

function ll_tools_ipa_keyboard_set_recording_review_state(
    int $recording_id,
    bool $needs_review,
    string $review_field = 'recording_ipa',
    string $review_note = ''
): void {
    if ($recording_id <= 0) {
        return;
    }

    $field_key = ll_tools_ipa_keyboard_normalize_review_field($review_field);
    if ($field_key === '') {
        return;
    }

    $fields = ll_tools_ipa_keyboard_get_recording_review_fields($recording_id);
    $fields[$field_key] = $needs_review;
    $enabled_fields = array_values(array_keys(array_filter($fields)));

    if ($needs_review) {
        update_post_meta($recording_id, ll_tools_ipa_keyboard_auto_review_meta_key(), '1');
        update_post_meta($recording_id, ll_tools_ipa_keyboard_review_fields_meta_key(), array_fill_keys($enabled_fields, true));
        if (trim($review_note) !== '') {
            ll_tools_ipa_keyboard_set_recording_review_note($recording_id, $review_note);
        }
        return;
    }

    if (!empty($enabled_fields)) {
        update_post_meta($recording_id, ll_tools_ipa_keyboard_auto_review_meta_key(), '1');
        update_post_meta($recording_id, ll_tools_ipa_keyboard_review_fields_meta_key(), array_fill_keys($enabled_fields, true));
        return;
    }

    delete_post_meta($recording_id, ll_tools_ipa_keyboard_auto_review_meta_key());
    delete_post_meta($recording_id, ll_tools_ipa_keyboard_review_fields_meta_key());
    delete_post_meta($recording_id, ll_tools_ipa_keyboard_review_note_meta_key());
}

function ll_tools_ipa_keyboard_recording_needs_auto_review(int $recording_id): bool {
    if ($recording_id <= 0) {
        return false;
    }

    return !empty(ll_tools_ipa_keyboard_get_recording_review_field_list($recording_id));
}

function ll_tools_ipa_keyboard_mark_recording_needs_auto_review(
    int $recording_id,
    string $review_field = 'recording_ipa',
    string $review_note = ''
): void {
    ll_tools_ipa_keyboard_set_recording_review_state($recording_id, true, $review_field, $review_note);
}

function ll_tools_ipa_keyboard_clear_recording_auto_review(int $recording_id, string $review_field = ''): void {
    if ($recording_id <= 0) {
        return;
    }

    if ($review_field === '') {
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_auto_review_meta_key());
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_review_fields_meta_key());
        delete_post_meta($recording_id, ll_tools_ipa_keyboard_review_note_meta_key());
        return;
    }

    ll_tools_ipa_keyboard_set_recording_review_state($recording_id, false, $review_field);
}

function ll_tools_ipa_keyboard_get_auto_review_recording_counts_by_wordset(): array {
    if (!current_user_can('view_ll_tools')) {
        return [];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [
            [
                'key' => ll_tools_ipa_keyboard_auto_review_meta_key(),
                'value' => '1',
            ],
        ],
    ]);

    if (empty($recording_ids)) {
        return [];
    }

    $counts = [];
    foreach ((array) $recording_ids as $recording_id) {
        $wordset_ids = ll_tools_ipa_keyboard_get_recording_wordset_ids((int) $recording_id);
        foreach ($wordset_ids as $wordset_id) {
            $wordset_id = (int) $wordset_id;
            if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
                continue;
            }

            if (!isset($counts[$wordset_id])) {
                $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
                if (!$wordset) {
                    continue;
                }

                $counts[$wordset_id] = [
                    'wordset_id' => $wordset_id,
                    'wordset_name' => (string) $wordset->name,
                    'count' => 0,
                ];
            }

            $counts[$wordset_id]['count']++;
        }
    }

    uasort($counts, static function (array $left, array $right): int {
        return ll_tools_locale_compare_strings(
            (string) ($left['wordset_name'] ?? ''),
            (string) ($right['wordset_name'] ?? '')
        );
    });

    return array_values($counts);
}

function ll_tools_ipa_keyboard_get_validation_schema_version(): int {
    return 18;
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
        'dental_diacritic_context' => [
            'label' => __('Dental diacritic placement', 'll-tools-text-domain'),
            'description' => __('Flags dental marks that are not attached to t or d.', 'll-tools-text-domain'),
        ],
        'illegal_ipa_symbol' => [
            'label' => __('Illegal IPA symbol', 'll-tools-text-domain'),
            'description' => __('Flags symbols that have been marked illegal for this word set.', 'll-tools-text-domain'),
        ],
        'unapproved_ipa_symbol' => [
            'label' => __('Unapproved IPA symbol', 'll-tools-text-domain'),
            'description' => __('Flags symbols outside the approved IPA inventory for this workflow.', 'll-tools-text-domain'),
        ],
        'orthography_mismatch' => [
            'label' => __('Orthography mismatch', 'll-tools-text-domain'),
            'description' => __('Flags saved IPA and orthography text that do not agree with the active conversion profile.', 'll-tools-text-domain'),
        ],
    ];
}

function ll_tools_ipa_keyboard_get_unapproved_ipa_symbols(): array {
    return ['ā', 'ê', 'ı', 'ø', 'ġ', 'ʉ', 'ʐ'];
}

function ll_tools_ipa_keyboard_sanitize_approved_ipa_symbols($raw, string $mode = 'ipa'): array {
    $approved = [];
    $known_unapproved = ll_tools_ipa_keyboard_get_unapproved_ipa_symbols();
    $values = is_string($raw) ? preg_split('/[\s,;|]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) : (array) $raw;

    foreach ($values as $value) {
        $symbol = ll_tools_ipa_keyboard_normalize_ipa_token((string) $value, $mode);
        if ($symbol === '' || !in_array($symbol, $known_unapproved, true) || in_array($symbol, $approved, true)) {
            continue;
        }
        $approved[] = $symbol;
    }

    usort($approved, 'll_tools_locale_compare_strings');
    return $approved;
}

function ll_tools_ipa_keyboard_get_wordset_approved_ipa_symbols(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $raw = get_term_meta($wordset_id, ll_tools_ipa_keyboard_approved_symbols_meta_key(), true);
    $approved = ll_tools_ipa_keyboard_sanitize_approved_ipa_symbols($raw, $mode);
    if ($approved !== $raw) {
        if (empty($approved)) {
            delete_term_meta($wordset_id, ll_tools_ipa_keyboard_approved_symbols_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_ipa_keyboard_approved_symbols_meta_key(), $approved);
        }
    }

    return $approved;
}

function ll_tools_ipa_keyboard_update_wordset_approved_ipa_symbols(int $wordset_id, array $symbols): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $approved = ll_tools_ipa_keyboard_sanitize_approved_ipa_symbols($symbols, $mode);
    if (empty($approved)) {
        delete_term_meta($wordset_id, ll_tools_ipa_keyboard_approved_symbols_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_keyboard_approved_symbols_meta_key(), $approved);
    }

    return $approved;
}

function ll_tools_ipa_keyboard_add_wordset_approved_ipa_symbol(int $wordset_id, string $symbol): array {
    $approved = ll_tools_ipa_keyboard_get_wordset_approved_ipa_symbols($wordset_id);
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $symbol = ll_tools_ipa_keyboard_normalize_ipa_token($symbol, $mode);
    if ($symbol !== '' && !in_array($symbol, $approved, true)) {
        $approved[] = $symbol;
    }

    return ll_tools_ipa_keyboard_update_wordset_approved_ipa_symbols($wordset_id, $approved);
}

function ll_tools_ipa_keyboard_token_has_unapproved_ipa_symbol(string $token, array $approved_symbols = []): string {
    if ($token === '') {
        return '';
    }

    foreach (ll_tools_ipa_keyboard_split_token_characters($token) as $char) {
        if (in_array($char, ll_tools_ipa_keyboard_get_unapproved_ipa_symbols(), true)
            && !in_array($char, $approved_symbols, true)) {
            return $char;
        }
    }

    return '';
}

function ll_tools_ipa_keyboard_token_has_invalid_dental_diacritic(string $token): bool {
    if ($token === '' || !preg_match('/\x{032A}/u', $token)) {
        return false;
    }

    $normalized_token = (function_exists('normalizer_normalize') && class_exists('Normalizer'))
        ? (normalizer_normalize($token, Normalizer::FORM_D) ?: $token)
        : $token;
    $chars = ll_tools_ipa_keyboard_split_token_characters($normalized_token);
    foreach ($chars as $index => $char) {
        if ($char !== "\u{032A}") {
            continue;
        }

        $cursor = $index - 1;
        while ($cursor >= 0) {
            $previous = (string) ($chars[$cursor] ?? '');
            if ($previous === "\u{0361}" || (function_exists('ll_tools_word_grid_is_ipa_combining_mark') && ll_tools_word_grid_is_ipa_combining_mark($previous))) {
                $cursor--;
                continue;
            }
            break;
        }

        $base = $cursor >= 0 ? (string) ($chars[$cursor] ?? '') : '';
        if (!in_array($base, ['t', 'd'], true)) {
            return true;
        }
    }

    return false;
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
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
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
        'approved_ipa_symbols' => ll_tools_ipa_keyboard_get_wordset_approved_ipa_symbols($wordset_id),
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

function ll_tools_ipa_orthography_manual_rules_meta_key(): string {
    return 'll_wordset_ipa_orthography_manual_rules';
}

function ll_tools_ipa_orthography_settings_meta_key(): string {
    return 'll_wordset_ipa_orthography_settings';
}

function ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key(): string {
    return 'll_wordset_ipa_orthography_exception_dictionary_entry_ids';
}

function ll_tools_ipa_orthography_blocklist_meta_key(): string {
    return 'll_wordset_ipa_orthography_rule_blocklist';
}

function ll_tools_ipa_orthography_engine_rules_cache_generation_meta_key(): string {
    return 'll_wordset_ipa_orthography_engine_rules_generation';
}

function ll_tools_ipa_orthography_get_engine_rules_cache_generation(int $wordset_id): int {
    if ($wordset_id <= 0) {
        return 0;
    }

    return max(0, (int) get_term_meta($wordset_id, ll_tools_ipa_orthography_engine_rules_cache_generation_meta_key(), true));
}

function ll_tools_ipa_orthography_engine_rules_cache_key(int $wordset_id, int $generation = -1): string {
    $wordset_id = max(0, $wordset_id);
    if ($generation < 0) {
        $generation = ll_tools_ipa_orthography_get_engine_rules_cache_generation($wordset_id);
    }

    return sprintf(
        'll_ipa_ortho_engine_%d_v%d_g%d',
        $wordset_id,
        ll_tools_ipa_keyboard_get_validation_schema_version(),
        max(0, $generation)
    );
}

function ll_tools_ipa_orthography_engine_rules_index_cache_key(int $wordset_id, int $generation = -1): string {
    $wordset_id = max(0, $wordset_id);
    if ($generation < 0) {
        $generation = ll_tools_ipa_orthography_get_engine_rules_cache_generation($wordset_id);
    }

    return sprintf(
        'll_ipa_ortho_engine_idx_%d_v%d_g%d',
        $wordset_id,
        ll_tools_ipa_keyboard_get_validation_schema_version(),
        max(0, $generation)
    );
}

function ll_tools_ipa_orthography_get_engine_rules_runtime_cache(): array {
    if (!isset($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'])) {
        $GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'] = [];
    }

    return $GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'];
}

function ll_tools_ipa_orthography_set_engine_rules_runtime_cache(string $cache_key, array $engine_rules): void {
    if ($cache_key === '') {
        return;
    }

    if (!isset($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'])) {
        $GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'] = [];
    }

    $GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'][$cache_key] = $engine_rules;
}

function ll_tools_ipa_orthography_get_engine_rules_index_runtime_cache(): array {
    if (!isset($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'])) {
        $GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'] = [];
    }

    return $GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'];
}

function ll_tools_ipa_orthography_set_engine_rules_index_runtime_cache(string $cache_key, array $engine_rules_index): void {
    if ($cache_key === '') {
        return;
    }

    if (!isset($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'])) {
        $GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'] = [];
    }

    $GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'][$cache_key] = $engine_rules_index;
}

function ll_tools_ipa_orthography_get_settings_runtime_cache(): array {
    if (!isset($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'])) {
        $GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'] = [];
    }

    return $GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'];
}

function ll_tools_ipa_orthography_set_settings_runtime_cache(int $wordset_id, array $settings): void {
    if ($wordset_id <= 0) {
        return;
    }

    if (!isset($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'])) {
        $GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'] = [];
    }

    $GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'][$wordset_id] = $settings;
}

function ll_tools_ipa_orthography_clear_settings_runtime_cache(int $wordset_id = 0): void {
    if (!isset($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'])
        || !is_array($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'])) {
        return;
    }

    if ($wordset_id > 0) {
        unset($GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'][$wordset_id]);
        return;
    }

    $GLOBALS['ll_tools_ipa_orthography_settings_runtime_cache'] = [];
}

function ll_tools_ipa_orthography_clear_engine_rules_runtime_cache(int $wordset_id): void {
    if ($wordset_id <= 0) {
        return;
    }

    $prefix = 'll_ipa_ortho_engine_' . $wordset_id . '_';
    if (!empty($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'])
        && is_array($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'])) {
        foreach (array_keys($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache']) as $cache_key) {
            if (strpos((string) $cache_key, $prefix) === 0) {
                unset($GLOBALS['ll_tools_ipa_orthography_engine_rules_runtime_cache'][$cache_key]);
            }
        }
    }

    $index_prefix = 'll_ipa_ortho_engine_idx_' . $wordset_id . '_';
    if (!empty($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'])
        && is_array($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'])) {
        foreach (array_keys($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache']) as $cache_key) {
            if (strpos((string) $cache_key, $index_prefix) === 0) {
                unset($GLOBALS['ll_tools_ipa_orthography_engine_rules_index_runtime_cache'][$cache_key]);
            }
        }
    }
}

function ll_tools_ipa_orthography_engine_rules_cache_ttl(): int {
    return max(60, (int) apply_filters('ll_tools_ipa_orthography_engine_rules_cache_ttl', 6 * HOUR_IN_SECONDS));
}

function ll_tools_ipa_orthography_invalidate_engine_rules_cache(int $wordset_id): void {
    if ($wordset_id <= 0) {
        return;
    }

    $current_generation = ll_tools_ipa_orthography_get_engine_rules_cache_generation($wordset_id);
    delete_transient(ll_tools_ipa_orthography_engine_rules_cache_key($wordset_id, $current_generation));
    delete_transient(ll_tools_ipa_orthography_engine_rules_index_cache_key($wordset_id, $current_generation));
    update_term_meta($wordset_id, ll_tools_ipa_orthography_engine_rules_cache_generation_meta_key(), $current_generation + 1);
    ll_tools_ipa_orthography_clear_engine_rules_runtime_cache($wordset_id);
}

function ll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_term_meta($meta_id, $term_id, $meta_key): void {
    $meta_key = (string) $meta_key;
    if ($meta_key !== ll_tools_ipa_orthography_manual_rules_meta_key()
        && $meta_key !== ll_tools_ipa_orthography_blocklist_meta_key()
        && $meta_key !== ll_tools_ipa_orthography_settings_meta_key()) {
        return;
    }

    if ($meta_key === ll_tools_ipa_orthography_settings_meta_key()) {
        ll_tools_ipa_orthography_clear_settings_runtime_cache((int) $term_id);
    }
    ll_tools_ipa_orthography_invalidate_engine_rules_cache((int) $term_id);
}

add_action('added_term_meta', 'll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_term_meta', 10, 3);
add_action('updated_term_meta', 'll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_term_meta', 10, 3);
add_action('deleted_term_meta', 'll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_term_meta', 10, 3);

function ll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_recording_meta($meta_id, $post_id, $meta_key): void {
    $meta_key = (string) $meta_key;
    if ($meta_key !== 'recording_text' && $meta_key !== 'recording_ipa') {
        return;
    }

    $post = get_post((int) $post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'word_audio') {
        return;
    }

    foreach (ll_tools_ipa_keyboard_get_recording_wordset_ids((int) $post_id) as $wordset_id) {
        ll_tools_ipa_orthography_invalidate_engine_rules_cache((int) $wordset_id);
    }
}

add_action('added_post_meta', 'll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_recording_meta', 10, 3);
add_action('updated_post_meta', 'll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_recording_meta', 10, 3);
add_action('deleted_post_meta', 'll_tools_ipa_orthography_maybe_invalidate_engine_rules_for_recording_meta', 10, 3);

function ll_tools_ipa_orthography_exception_word_ids_meta_key(): string {
    return 'll_wordset_ipa_orthography_exception_word_ids';
}

function ll_tools_ipa_orthography_normalize_context(string $context): string {
    $context = sanitize_key($context);
    return in_array($context, ['any', 'final', 'nonfinal'], true) ? $context : 'any';
}

function ll_tools_ipa_orthography_get_context_label(string $context): string {
    $context = ll_tools_ipa_orthography_normalize_context($context);
    if ($context === 'final') {
        return __('Word-final', 'll-tools-text-domain');
    }
    if ($context === 'nonfinal') {
        return __('Elsewhere', 'll-tools-text-domain');
    }
    return __('Anywhere', 'll-tools-text-domain');
}

function ll_tools_ipa_orthography_get_wordset_language(int $wordset_id): string {
    return function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? (string) ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
}

function ll_tools_ipa_orthography_normalize_word_text(string $text, string $language = ''): string {
    return ll_tools_ipa_keyboard_normalize_letter_key(
        function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
            ? ll_tools_word_grid_sanitize_non_ipa_text($text)
            : sanitize_text_field($text),
        $language
    );
}

function ll_tools_ipa_orthography_sanitize_rule_output_text($value, string $language = ''): string {
    if (!is_scalar($value)) {
        return '';
    }

    $text = (string) $value;
    $text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text($text)
        : sanitize_text_field($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = ll_tools_ipa_orthography_mb_lower($text, $language);
    $text = preg_replace('/\s+/u', '', (string) $text);
    return ll_tools_ipa_orthography_unicode_normalize(trim((string) $text), defined('Normalizer::FORM_C') ? Normalizer::FORM_C : 16);
}

function ll_tools_ipa_orthography_profile_compare_key(string $text, string $language = ''): string {
    $text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text($text)
        : sanitize_text_field($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = ll_tools_ipa_orthography_mb_lower($text, $language);
    $text = preg_replace('/[?.!,:;()\[\]{}]+/u', ' ', (string) $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);
    return ll_tools_ipa_orthography_unicode_normalize(trim((string) $text), defined('Normalizer::FORM_C') ? Normalizer::FORM_C : 16);
}

function ll_tools_ipa_orthography_settings_defaults(): array {
    return [
        'word_overrides' => [],
        'word_override_word_ids' => [],
        'word_override_entry_ids' => [],
        'phrase_overrides' => [],
        'optional_matches' => [],
        'recording_type_punctuation' => [],
        'sentence_case' => false,
    ];
}

function ll_tools_ipa_orthography_merge_settings(array $base, array $override, array $override_raw = []): array {
    $settings = ll_tools_ipa_orthography_settings_defaults();
    $settings['word_overrides'] = array_merge(
        (array) ($base['word_overrides'] ?? []),
        (array) ($override['word_overrides'] ?? [])
    );
    $settings['word_override_entry_ids'] = array_merge(
        (array) ($base['word_override_entry_ids'] ?? []),
        (array) ($override['word_override_entry_ids'] ?? [])
    );
    $settings['word_override_word_ids'] = array_merge(
        (array) ($base['word_override_word_ids'] ?? []),
        (array) ($override['word_override_word_ids'] ?? [])
    );
    foreach ($settings['word_override_word_ids'] as $from_key => $word_id) {
        $from_key = (string) $from_key;
        $word_id = (int) $word_id;
        if ($from_key === '' || $word_id <= 0 || !isset($settings['word_overrides'][$from_key])) {
            unset($settings['word_override_word_ids'][$from_key]);
        } else {
            $settings['word_override_word_ids'][$from_key] = $word_id;
        }
    }
    foreach ($settings['word_override_entry_ids'] as $from_key => $entry_id) {
        $from_key = (string) $from_key;
        $entry_id = (int) $entry_id;
        if ($from_key === ''
            || $entry_id <= 0
            || !isset($settings['word_overrides'][$from_key])
            || !empty($settings['word_override_word_ids'][$from_key])) {
            unset($settings['word_override_entry_ids'][$from_key]);
        } else {
            $settings['word_override_entry_ids'][$from_key] = $entry_id;
        }
    }
    $settings['phrase_overrides'] = array_values(array_merge(
        (array) ($base['phrase_overrides'] ?? []),
        (array) ($override['phrase_overrides'] ?? [])
    ));
    usort($settings['phrase_overrides'], static function (array $left, array $right): int {
        return count((array) ($right['from_key'] ?? [])) <=> count((array) ($left['from_key'] ?? []));
    });

    $optional_matches = [];
    $seen_optional = [];
    foreach (array_merge((array) ($base['optional_matches'] ?? []), (array) ($override['optional_matches'] ?? [])) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = (string) ($entry['ipa'] ?? '') . '|' . (string) ($entry['orthography_key'] ?? '');
        if ($key === '|' || isset($seen_optional[$key])) {
            continue;
        }
        $seen_optional[$key] = true;
        $optional_matches[] = $entry;
    }
    $settings['optional_matches'] = $optional_matches;
    $settings['recording_type_punctuation'] = array_merge(
        (array) ($base['recording_type_punctuation'] ?? []),
        (array) ($override['recording_type_punctuation'] ?? [])
    );
    $settings['sentence_case'] = array_key_exists('sentence_case', $override_raw)
        ? !empty($override['sentence_case'])
        : !empty($base['sentence_case']);

    return $settings;
}

function ll_tools_ipa_orthography_merge_manual_rules(array $base, array $override): array {
    $merged = $base;
    foreach ($override as $segment => $contexts) {
        $segment = (string) $segment;
        if ($segment === '' || !is_array($contexts)) {
            continue;
        }
        if (!isset($merged[$segment]) || !is_array($merged[$segment])) {
            $merged[$segment] = [];
        }
        foreach ($contexts as $context => $output) {
            $context = ll_tools_ipa_orthography_normalize_context((string) $context);
            $output = (string) $output;
            if ($output !== '') {
                $merged[$segment][$context] = $output;
            }
        }
    }
    ksort($merged);
    return $merged;
}

function ll_tools_ipa_orthography_get_profile_locked_manual_rules(int $wordset_id): array {
    if (ll_tools_ipa_orthography_get_profile_key($wordset_id) !== 'zazaki_genc_palu') {
        return [];
    }

    return ll_tools_ipa_orthography_sanitize_manual_rules([
        'æ' => ['any' => 'â'],
        'i' => ['any' => 'i'],
        'ɨ' => ['nonfinal' => 'ı'],
        'ɪ' => ['nonfinal' => 'ı'],
        'ɢ' => ['any' => "'g"],
        'ɢʷ' => ['any' => "'gw"],
        'ħ' => ['any' => "'h"],
        'ɭ' => ['any' => "'l"],
        'χ' => ['any' => 'x'],
        'x' => ['any' => 'x'],
        'ŋg' => ['any' => 'ng'],
        'ŋk' => ['any' => 'nk'],
        'ŋqʰ' => ['any' => 'nq'],
        'ŋq' => ['any' => 'nq'],
        't̪͡ʙ̥ɨ' => ['any' => 'twe'],
        't̪͡ʙɨ' => ['any' => 'twe'],
        'sɨ' => ['any' => 'se'],
        'sʷ' => ['any' => 'sw'],
        'ʷ' => ['any' => 'w'],
    ], $wordset_id);
}

function ll_tools_ipa_orthography_apply_profile_locked_manual_rules(array $rules, int $wordset_id): array {
    foreach (ll_tools_ipa_orthography_get_profile_locked_manual_rules($wordset_id) as $segment => $contexts) {
        if (!isset($rules[$segment]) || !is_array($rules[$segment])) {
            $rules[$segment] = [];
        }
        foreach ((array) $contexts as $context => $output) {
            $context = ll_tools_ipa_orthography_normalize_context((string) $context);
            $output = (string) $output;
            if ($output !== '') {
                $rules[$segment][$context] = $output;
            }
        }
    }

    ksort($rules);
    return $rules;
}

function ll_tools_ipa_orthography_get_profile_disallowed_ipa_segments(int $wordset_id): array {
    if (ll_tools_ipa_orthography_get_profile_key($wordset_id) !== 'zazaki_genc_palu') {
        return [];
    }

    return ['ə', 'ᵊ'];
}

function ll_tools_ipa_orthography_profile_allows_ipa_segment(string $segment, int $wordset_id): bool {
    if ($wordset_id <= 0) {
        return true;
    }

    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $segment_key = ll_tools_ipa_orthography_normalize_segment_key($segment, $mode);
    if ($segment_key === '') {
        return true;
    }

    return !in_array($segment_key, ll_tools_ipa_orthography_get_profile_disallowed_ipa_segments($wordset_id), true);
}

function ll_tools_ipa_orthography_filter_profile_allowed_optional_matches(array $optional_matches, int $wordset_id): array {
    if ($wordset_id <= 0 || empty($optional_matches)) {
        return $optional_matches;
    }

    return array_values(array_filter($optional_matches, static function ($entry) use ($wordset_id): bool {
        return is_array($entry)
            && ll_tools_ipa_orthography_profile_allows_ipa_segment((string) ($entry['ipa'] ?? ''), $wordset_id);
    }));
}

function ll_tools_ipa_orthography_sanitize_setting_text($value): string {
    if (!is_scalar($value)) {
        return '';
    }

    $text = (string) $value;
    $text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text($text)
        : sanitize_text_field($text);
    return trim($text);
}

function ll_tools_ipa_orthography_sanitize_setting_ipa($value, int $wordset_id): string {
    if (!is_scalar($value)) {
        return '';
    }

    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $text = (string) $value;
    $text = function_exists('ll_tools_word_grid_normalize_ipa_output')
        ? ll_tools_word_grid_normalize_ipa_output($text, $mode)
        : sanitize_text_field($text);
    return ll_tools_ipa_orthography_unicode_normalize(trim($text), defined('Normalizer::FORM_D') ? Normalizer::FORM_D : 4);
}

function ll_tools_ipa_orthography_settings_word_tokens($value): array {
    if (is_array($value)) {
        $tokens = [];
        foreach ($value as $entry) {
            $token = ll_tools_ipa_orthography_sanitize_setting_text($entry);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    $text = ll_tools_ipa_orthography_sanitize_setting_text($value);
    if ($text === '') {
        return [];
    }

    $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? array_values(array_map('strval', $parts)) : [];
}

function ll_tools_ipa_orthography_sanitize_dictionary_entry_id($raw): int {
    $entry_id = is_scalar($raw) ? absint($raw) : 0;
    if ($entry_id <= 0) {
        return 0;
    }

    if (function_exists('ll_tools_is_dictionary_entry_id')) {
        return ll_tools_is_dictionary_entry_id($entry_id) ? $entry_id : 0;
    }

    return get_post_type($entry_id) === 'll_dictionary_entry' ? $entry_id : 0;
}

function ll_tools_ipa_orthography_sanitize_word_override_word_id($raw): int {
    $word_id = is_scalar($raw) ? absint($raw) : 0;
    if ($word_id <= 0) {
        return 0;
    }

    return get_post_type($word_id) === 'words' ? $word_id : 0;
}

function ll_tools_ipa_orthography_get_word_dictionary_entry_id(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }

    if (function_exists('ll_tools_get_word_dictionary_entry_id')) {
        return (int) ll_tools_get_word_dictionary_entry_id($word_id);
    }

    return 0;
}

function ll_tools_ipa_orthography_sanitize_settings($raw, int $wordset_id): array {
    $settings = ll_tools_ipa_orthography_settings_defaults();
    if (!is_array($raw)) {
        return $settings;
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);

    foreach ((array) ($raw['word_overrides'] ?? []) as $from => $to) {
        $word_id = 0;
        $dictionary_entry_id = 0;
        if (is_array($to)) {
            $word_id = ll_tools_ipa_orthography_sanitize_word_override_word_id($to['word_id'] ?? 0);
            $dictionary_entry_id = ll_tools_ipa_orthography_sanitize_dictionary_entry_id(
                $to['dictionary_entry_id'] ?? ($to['entry_id'] ?? 0)
            );
            if ($dictionary_entry_id <= 0 && $word_id <= 0) {
                $dictionary_entry_id = ll_tools_ipa_orthography_get_word_dictionary_entry_id(
                    absint($to['word_id'] ?? 0)
                );
            }
            $from = $to['from'] ?? $from;
            $to = $to['to'] ?? ($to['replacement'] ?? '');
        }

        $from_key = ll_tools_ipa_orthography_profile_compare_key(
            ll_tools_ipa_orthography_sanitize_setting_text($from),
            $language
        );
        $replacement = ll_tools_ipa_orthography_sanitize_setting_text($to);
        if ($from_key !== '' && $replacement !== '') {
            $settings['word_overrides'][$from_key] = $replacement;
            if ($word_id > 0) {
                $settings['word_override_word_ids'][$from_key] = $word_id;
            } elseif ($dictionary_entry_id > 0) {
                $settings['word_override_entry_ids'][$from_key] = $dictionary_entry_id;
            }
        }
    }

    foreach ((array) ($raw['word_override_word_ids'] ?? []) as $from => $word_id) {
        $from_key = ll_tools_ipa_orthography_profile_compare_key(
            ll_tools_ipa_orthography_sanitize_setting_text($from),
            $language
        );
        $word_id = ll_tools_ipa_orthography_sanitize_word_override_word_id($word_id);
        if ($from_key !== '' && $word_id > 0 && isset($settings['word_overrides'][$from_key])) {
            $settings['word_override_word_ids'][$from_key] = $word_id;
        }
    }

    foreach ((array) ($raw['word_override_entry_ids'] ?? []) as $from => $entry_id) {
        $from_key = ll_tools_ipa_orthography_profile_compare_key(
            ll_tools_ipa_orthography_sanitize_setting_text($from),
            $language
        );
        $entry_id = ll_tools_ipa_orthography_sanitize_dictionary_entry_id($entry_id);
        if ($from_key !== ''
            && $entry_id > 0
            && isset($settings['word_overrides'][$from_key])
            && empty($settings['word_override_word_ids'][$from_key])) {
            $settings['word_override_entry_ids'][$from_key] = $entry_id;
        }
    }

    foreach ((array) ($raw['phrase_overrides'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $from_tokens = ll_tools_ipa_orthography_settings_word_tokens($entry['from'] ?? ($entry['source'] ?? []));
        $to_tokens = ll_tools_ipa_orthography_settings_word_tokens($entry['to'] ?? ($entry['replacement'] ?? []));
        if (empty($from_tokens) || empty($to_tokens)) {
            continue;
        }

        $from_key = array_values(array_filter(array_map(static function (string $token) use ($language): string {
            return ll_tools_ipa_orthography_profile_compare_key($token, $language);
        }, $from_tokens)));
        if (count($from_key) !== count($from_tokens)) {
            continue;
        }

        $settings['phrase_overrides'][] = [
            'from' => $from_tokens,
            'from_key' => $from_key,
            'to' => $to_tokens,
        ];
    }
    usort($settings['phrase_overrides'], static function (array $left, array $right): int {
        return count((array) ($right['from_key'] ?? [])) <=> count((array) ($left['from_key'] ?? []));
    });

    foreach ((array) ($raw['optional_matches'] ?? []) as $ipa => $entry) {
        if (is_array($entry)) {
            $ipa = $entry['ipa'] ?? $ipa;
            $orthography = $entry['orthography'] ?? ($entry['text'] ?? '');
        } else {
            $orthography = $entry;
        }

        $ipa_pattern = ll_tools_ipa_orthography_sanitize_setting_ipa($ipa, $wordset_id);
        $orthography_text = ll_tools_ipa_orthography_sanitize_setting_text($orthography);
        $orthography_key = ll_tools_ipa_orthography_profile_compare_key($orthography_text, $language);
        if ($ipa_pattern !== '' && $orthography_key !== '' && ll_tools_ipa_orthography_profile_allows_ipa_segment($ipa_pattern, $wordset_id)) {
            $settings['optional_matches'][] = [
                'ipa' => $ipa_pattern,
                'orthography' => $orthography_text,
                'orthography_key' => $orthography_key,
            ];
        }
    }

    foreach ((array) ($raw['recording_type_punctuation'] ?? []) as $slug => $punctuation) {
        $slug = sanitize_key((string) $slug);
        $punctuation = trim((string) $punctuation);
        if ($slug !== '' && in_array($punctuation, ['.', '?', '!'], true)) {
            $settings['recording_type_punctuation'][$slug] = $punctuation;
        }
    }

    $settings['sentence_case'] = !empty($raw['sentence_case']) && rest_sanitize_boolean($raw['sentence_case']);

    return $settings;
}

function ll_tools_ipa_orthography_get_settings(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return ll_tools_ipa_orthography_settings_defaults();
    }

    $runtime_cache = ll_tools_ipa_orthography_get_settings_runtime_cache();
    if (isset($runtime_cache[$wordset_id]) && is_array($runtime_cache[$wordset_id])) {
        return $runtime_cache[$wordset_id];
    }

    $profile_settings = ll_tools_ipa_orthography_get_profile_default_settings($wordset_id);
    $raw = get_term_meta($wordset_id, ll_tools_ipa_orthography_settings_meta_key(), true);
    if (!is_array($raw)) {
        ll_tools_ipa_orthography_set_settings_runtime_cache($wordset_id, $profile_settings);
        return $profile_settings;
    }

    $clean = ll_tools_ipa_orthography_sanitize_settings($raw, $wordset_id);
    if ($clean !== $raw) {
        if ($clean === ll_tools_ipa_orthography_settings_defaults()) {
            delete_term_meta($wordset_id, ll_tools_ipa_orthography_settings_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_ipa_orthography_settings_meta_key(), $clean);
        }
    }

    $settings = ll_tools_ipa_orthography_merge_settings($profile_settings, $clean, $raw);
    $settings['optional_matches'] = ll_tools_ipa_orthography_filter_profile_allowed_optional_matches(
        (array) ($settings['optional_matches'] ?? []),
        $wordset_id
    );
    ll_tools_ipa_orthography_set_settings_runtime_cache($wordset_id, $settings);
    return $settings;
}

function ll_tools_ipa_orthography_count_text_occurrences(string $text, string $needle): int {
    return $needle === '' ? 0 : substr_count($text, $needle);
}

function ll_tools_ipa_orthography_count_ipa_pattern(string $ipa_word, string $ipa_pattern): int {
    $ipa_pattern = ll_tools_ipa_orthography_unicode_normalize($ipa_pattern, defined('Normalizer::FORM_D') ? Normalizer::FORM_D : 4);
    if ($ipa_pattern === '') {
        return 0;
    }

    $ipa_word = ll_tools_ipa_orthography_unicode_normalize($ipa_word, defined('Normalizer::FORM_D') ? Normalizer::FORM_D : 4);
    $count = preg_match_all('/' . preg_quote($ipa_pattern, '/') . '/u', $ipa_word);
    return is_int($count) ? $count : 0;
}

function ll_tools_ipa_orthography_words_match_optional_settings(
    string $actual_key,
    string $suggested_key,
    string $ipa_word,
    int $wordset_id
): bool {
    if ($wordset_id <= 0 || $actual_key === $suggested_key) {
        return $actual_key === $suggested_key;
    }

    $actual_reduced = $actual_key;
    $suggested_reduced = $suggested_key;
    $used_optional_rule = false;
    foreach ((array) (ll_tools_ipa_orthography_get_settings($wordset_id)['optional_matches'] ?? []) as $entry) {
        $ipa_pattern = (string) ($entry['ipa'] ?? '');
        $orthography_key = (string) ($entry['orthography_key'] ?? '');
        if ($ipa_pattern === '' || $orthography_key === '') {
            continue;
        }

        $ipa_count = ll_tools_ipa_orthography_count_ipa_pattern($ipa_word, $ipa_pattern);
        if ($ipa_count <= 0) {
            continue;
        }

        $actual_count = ll_tools_ipa_orthography_count_text_occurrences($actual_reduced, $orthography_key);
        $suggested_count = ll_tools_ipa_orthography_count_text_occurrences($suggested_reduced, $orthography_key);
        if (abs($actual_count - $suggested_count) > $ipa_count) {
            return false;
        }

        $actual_reduced = str_replace($orthography_key, '', $actual_reduced);
        $suggested_reduced = str_replace($orthography_key, '', $suggested_reduced);
        $used_optional_rule = true;
    }

    return $used_optional_rule && $actual_reduced === $suggested_reduced;
}

function ll_tools_ipa_orthography_strlen(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function ll_tools_ipa_orthography_substr(string $text, int $start, ?int $length = null): string {
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($text, $start, null, 'UTF-8') : mb_substr($text, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($text, $start) : substr($text, $start, $length);
}

function ll_tools_ipa_orthography_split_nonspace_spans(string $text): array {
    if ($text === '' || !preg_match_all('/\S+/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $parts = [];
    foreach ((array) ($matches[0] ?? []) as $match) {
        $token = (string) ($match[0] ?? '');
        $byte_offset = max(0, (int) ($match[1] ?? 0));
        $parts[] = [
            'text' => $token,
            'start' => ll_tools_ipa_orthography_strlen(substr($text, 0, $byte_offset)),
            'length' => ll_tools_ipa_orthography_strlen($token),
        ];
    }
    return $parts;
}

function ll_tools_ipa_orthography_split_nonspace_tokens(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    if (strpbrk($text, "\t\n\r\0\x0B") === false) {
        return array_values(array_filter(explode(' ', $text), static function (string $token): bool {
            return $token !== '';
        }));
    }

    $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($tokens) ? array_values(array_map('strval', $tokens)) : [];
}

function ll_tools_ipa_orthography_is_single_space_token_text(string $text): bool {
    return $text !== ''
        && $text === trim($text)
        && strpos($text, '  ') === false
        && strpbrk($text, "\t\n\r\0\x0B") === false;
}

function ll_tools_ipa_orthography_replace_char_span(string $text, int $start, int $length, string $replacement): string {
    return ll_tools_ipa_orthography_substr($text, 0, $start)
        . $replacement
        . ll_tools_ipa_orthography_substr($text, $start + $length);
}

function ll_tools_ipa_orthography_replace_first(string $text, string $search, string $replace): string {
    if ($search === '') {
        return $text;
    }
    $pos = function_exists('mb_strpos') ? mb_strpos($text, $search, 0, 'UTF-8') : strpos($text, $search);
    if ($pos === false) {
        return $text;
    }
    return ll_tools_ipa_orthography_replace_char_span($text, (int) $pos, ll_tools_ipa_orthography_strlen($search), $replace);
}

function ll_tools_ipa_orthography_chars(string $text): array {
    if ($text === '') {
        return [];
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? array_values($chars) : str_split($text);
}

function ll_tools_ipa_orthography_ends_with_any(string $text, array $suffixes): bool {
    foreach ($suffixes as $suffix) {
        $suffix = (string) $suffix;
        if ($suffix === '') {
            continue;
        }
        $length = ll_tools_ipa_orthography_strlen($suffix);
        if (ll_tools_ipa_orthography_strlen($text) >= $length
            && ll_tools_ipa_orthography_substr($text, -$length) === $suffix) {
            return true;
        }
    }
    return false;
}

function ll_tools_ipa_orthography_profile_words_equivalent(
    string $actual_word,
    string $suggested_word,
    string $ipa_word,
    string $language = '',
    int $wordset_id = 0,
    int $word_id = 0
): bool {
    $actual_key = ll_tools_ipa_orthography_profile_compare_key($actual_word, $language);
    $suggested_key = ll_tools_ipa_orthography_profile_compare_key($suggested_word, $language);
    if ($actual_key === $suggested_key) {
        return true;
    }

    if (ll_tools_ipa_orthography_profile_word_matches_configured_override($actual_key, $suggested_key, $wordset_id, false, $word_id)) {
        return true;
    }

    return ll_tools_ipa_orthography_words_match_optional_settings($actual_key, $suggested_key, $ipa_word, $wordset_id);
}

function ll_tools_ipa_orthography_profile_word_matches_configured_override(
    string $actual_key,
    string $suggested_key,
    int $wordset_id,
    bool $include_entry_bound_overrides = false,
    int $word_id = 0
): bool {
    if ($wordset_id <= 0 || $actual_key === '' || $suggested_key === '') {
        return false;
    }

    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $word_overrides = (array) ($settings['word_overrides'] ?? []);
    if (!array_key_exists($suggested_key, $word_overrides)) {
        return false;
    }
    $word_ids = (array) ($settings['word_override_word_ids'] ?? []);
    $required_word_id = (int) ($word_ids[$suggested_key] ?? 0);
    if ($required_word_id > 0 && ($word_id <= 0 || $word_id !== $required_word_id)) {
        return false;
    }
    $entry_ids = (array) ($settings['word_override_entry_ids'] ?? []);
    if (!$include_entry_bound_overrides && (empty($required_word_id) && !empty($entry_ids[$suggested_key]))) {
        return false;
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $replacement_key = ll_tools_ipa_orthography_profile_compare_key((string) $word_overrides[$suggested_key], $language);
    return $replacement_key !== '' && $replacement_key === $actual_key;
}

function ll_tools_ipa_orthography_profile_word_matches_entry_bound_override(
    string $actual_word,
    string $suggested_word,
    string $language,
    int $wordset_id
): bool {
    if ($wordset_id <= 0) {
        return false;
    }

    $actual_key = ll_tools_ipa_orthography_profile_compare_key($actual_word, $language);
    $suggested_key = ll_tools_ipa_orthography_profile_compare_key($suggested_word, $language);
    if ($actual_key === '' || $suggested_key === '') {
        return false;
    }
    if ($actual_key === $suggested_key) {
        return false;
    }

    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $entry_ids = (array) ($settings['word_override_entry_ids'] ?? []);
    if (empty($entry_ids[$suggested_key])) {
        return false;
    }

    return ll_tools_ipa_orthography_profile_word_matches_configured_override($actual_key, $suggested_key, $wordset_id, true);
}

function ll_tools_ipa_orthography_profile_entry_bound_override_context_allows(
    string $suggested_word,
    string $language,
    int $wordset_id,
    array $prediction
): bool {
    $suggested_key = ll_tools_ipa_orthography_profile_compare_key($suggested_word, $language);
    if ($wordset_id <= 0 || $suggested_key === '') {
        return false;
    }

    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $entry_ids = (array) ($settings['word_override_entry_ids'] ?? []);
    $required_entry_id = (int) ($entry_ids[$suggested_key] ?? 0);
    if ($required_entry_id <= 0) {
        return false;
    }

    $word_id = (int) ($prediction['word_id'] ?? 0);
    if ($word_id <= 0) {
        return true;
    }

    $word_entry_id = ll_tools_ipa_orthography_get_word_dictionary_entry_id($word_id);
    return $word_entry_id <= 0 || $word_entry_id === $required_entry_id;
}

function ll_tools_ipa_orthography_diff_span_pair(
    string $actual,
    string $suggested,
    int $actual_start,
    int $suggested_start,
    string $language = ''
): array {
    $actual_chars = ll_tools_ipa_orthography_chars($actual);
    $suggested_chars = ll_tools_ipa_orthography_chars($suggested);
    $actual_compare = ll_tools_ipa_orthography_chars(ll_tools_ipa_orthography_mb_lower($actual, $language));
    $suggested_compare = ll_tools_ipa_orthography_chars(ll_tools_ipa_orthography_mb_lower($suggested, $language));

    $prefix = 0;
    $max_prefix = min(count($actual_compare), count($suggested_compare));
    while ($prefix < $max_prefix && $actual_compare[$prefix] === $suggested_compare[$prefix]) {
        $prefix++;
    }

    $suffix = 0;
    while (
        $suffix < (count($actual_compare) - $prefix)
        && $suffix < (count($suggested_compare) - $prefix)
        && $actual_compare[count($actual_compare) - 1 - $suffix] === $suggested_compare[count($suggested_compare) - 1 - $suffix]
    ) {
        $suffix++;
    }

    $actual_length = max(0, count($actual_chars) - $prefix - $suffix);
    $suggested_length = max(0, count($suggested_chars) - $prefix - $suffix);
    $actual_span_offset = $prefix;
    $suggested_span_offset = $prefix;

    if ($actual_length > 0 && $suggested_length === 0) {
        $deleted_text = implode('', array_slice($actual_chars, $prefix, $actual_length));
        $actual_anchor = $actual_compare[$prefix + $actual_length] ?? null;
        $suggested_anchor = $suggested_compare[$prefix] ?? null;
        if ($actual_anchor !== null
            && $suggested_anchor !== null
            && $actual_anchor === $suggested_anchor
            && preg_match('/^\p{P}+$/u', $deleted_text) === 1) {
            $actual_length++;
            $suggested_length = 1;
        }
        if ($suggested_length === 0 && !empty($suggested_chars)) {
            $context_before = $prefix > 0 ? $prefix - 1 : null;
            $context_after = $prefix < count($suggested_chars) ? $prefix : null;
            if ($context_before !== null || $context_after !== null) {
                $context_start = $context_before !== null ? $context_before : (int) $context_after;
                $context_end = $context_after !== null ? (int) $context_after + 1 : (int) $context_before + 1;
                $suggested_span_offset = $context_start;
                $suggested_length = max(1, $context_end - $context_start);
            }
        }
    } elseif ($suggested_length > 0 && $actual_length === 0) {
        $inserted_text = implode('', array_slice($suggested_chars, $prefix, $suggested_length));
        $actual_anchor = $actual_compare[$prefix] ?? null;
        $suggested_anchor = $suggested_compare[$prefix + $suggested_length] ?? null;
        if ($actual_anchor !== null
            && $suggested_anchor !== null
            && $actual_anchor === $suggested_anchor
            && preg_match('/^\p{P}+$/u', $inserted_text) === 1) {
            $actual_length = 1;
            $suggested_length++;
        }
    }

    return [
        'actual' => $actual_length > 0 ? [
            'start' => $actual_start + $actual_span_offset,
            'length' => $actual_length,
        ] : null,
        'suggested' => $suggested_length > 0 ? [
            'start' => $suggested_start + $suggested_span_offset,
            'length' => $suggested_length,
        ] : null,
        'actual_diff' => $actual_length > 0 ? implode('', array_slice($actual_chars, $actual_span_offset, $actual_length)) : '',
        'suggested_diff' => $suggested_length > 0 ? implode('', array_slice($suggested_chars, $suggested_span_offset, $suggested_length)) : '',
    ];
}

function ll_tools_ipa_orthography_find_ipa_symbol_span(string $ipa_word, string $symbol, int $word_start): ?array {
    if ($symbol === '') {
        return null;
    }
    $pos = function_exists('mb_strpos') ? mb_strpos($ipa_word, $symbol, 0, 'UTF-8') : strpos($ipa_word, $symbol);
    if ($pos === false) {
        return null;
    }
    return [
        'start' => $word_start + (int) $pos,
        'length' => ll_tools_ipa_orthography_strlen($symbol),
    ];
}

function ll_tools_ipa_orthography_rule_output_has_letter(string $output): bool {
    return preg_match('/\p{L}/u', $output) === 1;
}

function ll_tools_ipa_orthography_rule_output_key(string $output, string $language = ''): string {
    return ll_tools_ipa_orthography_sanitize_rule_output_text($output, $language);
}

function ll_tools_ipa_orthography_rule_span_for_suggested_diff(
    string $ipa_word,
    string $suggested_diff,
    int $word_start,
    int $wordset_id,
    string $language = ''
): ?array {
    $diff_key = ll_tools_ipa_orthography_rule_output_key($suggested_diff, $language);
    if ($ipa_word === '' || $diff_key === '' || $wordset_id <= 0) {
        return null;
    }

    $candidates = [];
    foreach (ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id) as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $output = (string) ($rule['output'] ?? '');
        $output_key = ll_tools_ipa_orthography_rule_output_key($output, $language);
        $segment = (string) ($rule['segment'] ?? '');
        if ($output_key === '' || $segment === '' || !ll_tools_ipa_orthography_text_contains($diff_key, $output_key)) {
            continue;
        }

        $span = ll_tools_ipa_orthography_find_ipa_symbol_span($ipa_word, $segment, $word_start);
        if (!is_array($span)) {
            continue;
        }

        $candidates[] = [
            'span' => $span,
            'score' => (!empty($rule['manual']) ? 1000 : 0)
                + (ll_tools_ipa_orthography_rule_output_has_letter($output_key) ? 100 : 0)
                + min(20, ll_tools_ipa_orthography_strlen($output_key))
                + min(10, (int) ($span['length'] ?? 0)),
        ];
    }

    if (empty($candidates)) {
        return null;
    }

    usort($candidates, static function (array $left, array $right): int {
        $score_compare = (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
        if ($score_compare !== 0) {
            return $score_compare;
        }
        $start_compare = (int) ($left['span']['start'] ?? 0) <=> (int) ($right['span']['start'] ?? 0);
        if ($start_compare !== 0) {
            return $start_compare;
        }
        return (int) ($right['span']['length'] ?? 0) <=> (int) ($left['span']['length'] ?? 0);
    });

    return is_array($candidates[0]['span'] ?? null) ? (array) $candidates[0]['span'] : null;
}

function ll_tools_ipa_orthography_ipa_span_for_mismatch(
    string $ipa_word,
    string $actual_diff,
    string $suggested_diff,
    int $word_start,
    int $word_length,
    string $language = '',
    int $wordset_id = 0
): array {
    if ($wordset_id > 0 && $suggested_diff !== '') {
        $span = ll_tools_ipa_orthography_rule_span_for_suggested_diff(
            $ipa_word,
            $suggested_diff,
            $word_start,
            $wordset_id,
            $language
        );
        if (is_array($span)) {
            return $span;
        }
    }

    return [
        'start' => $word_start,
        'length' => $word_length,
    ];
}

function ll_tools_ipa_orthography_final_high_vowel_span(string $ipa_word, int $word_start): ?array {
    $chars = ll_tools_ipa_orthography_chars($ipa_word);
    for ($index = count($chars) - 1; $index >= 0; $index--) {
        $char = (string) $chars[$index];
        if (function_exists('ll_tools_word_grid_is_ipa_combining_mark') && ll_tools_word_grid_is_ipa_combining_mark($char)) {
            continue;
        }
        if ($char === 'ɨ' || $char === 'ɪ') {
            return [
                'start' => $word_start + $index,
                'length' => 1,
            ];
        }
        return null;
    }

    return null;
}

function ll_tools_ipa_orthography_profile_meta_key(): string {
    return 'll_wordset_ipa_orthography_profile';
}

function ll_tools_ipa_orthography_unicode_normalize(string $text, int $form = 16): string {
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, $form);
        if (is_string($normalized)) {
            return $normalized;
        }
    }

    return $text;
}

function ll_tools_ipa_orthography_mb_lower(string $text, string $language = ''): string {
    if (function_exists('ll_tools_word_grid_lowercase')) {
        return (string) ll_tools_word_grid_lowercase($text, $language);
    }
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function ll_tools_ipa_orthography_mb_upper(string $text, string $language = ''): string {
    if (function_exists('ll_tools_language_uses_turkish_casing') && ll_tools_language_uses_turkish_casing($language)) {
        if ($text === 'i') {
            return 'İ';
        }
        if ($text === 'ı') {
            return 'I';
        }
    }
    return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
}

function ll_tools_ipa_orthography_get_profile_key(int $wordset_id): string {
    if ($wordset_id <= 0) {
        return '';
    }

    $stored = sanitize_key((string) get_term_meta($wordset_id, ll_tools_ipa_orthography_profile_meta_key(), true));
    $profiles = ll_tools_ipa_orthography_get_available_conversion_profiles();
    if ($stored !== '' && isset($profiles[$stored])) {
        return $stored;
    }

    $language = strtolower(ll_tools_ipa_orthography_get_wordset_language($wordset_id));
    if (in_array($language, ['zza', 'diq', 'kiu', 'zazaki', 'zaza'], true)) {
        return 'zazaki_genc_palu';
    }

    return '';
}

function ll_tools_ipa_orthography_get_conversion_profile(int $wordset_id): array {
    $profile_key = ll_tools_ipa_orthography_get_profile_key($wordset_id);
    $profiles = ll_tools_ipa_orthography_get_available_conversion_profiles();
    return isset($profiles[$profile_key]) ? $profiles[$profile_key] : [];
}

function ll_tools_ipa_orthography_get_available_conversion_profiles(): array {
    return [
        'zazaki_genc_palu' => [
            'key' => 'zazaki_genc_palu',
            'label' => __('Genç-Palu Zazaki', 'll-tools-text-domain'),
            'short_label' => __('Genç-Palu', 'll-tools-text-domain'),
            'status' => 'draft',
            'direction' => 'ipa_to_orthography',
            'description' => __('Uses the Genç-Palu IPA-to-orthography conversion profile, including lexical and phrase exceptions.', 'll-tools-text-domain'),
        ],
    ];
}

function ll_tools_ipa_orthography_get_profile_default_manual_rules(int $wordset_id): array {
    if (ll_tools_ipa_orthography_get_profile_key($wordset_id) !== 'zazaki_genc_palu') {
        return [];
    }

    return ll_tools_ipa_orthography_sanitize_manual_rules([
        'a' => ['any' => 'a'],
        'æ' => ['any' => 'â'],
        'e' => ['any' => 'ê'],
        'ɛ' => ['any' => 'e'],
        'ɨ' => ['final' => 'e', 'nonfinal' => 'ı'],
        'ɪ' => ['final' => 'e', 'nonfinal' => 'ı'],
        'i' => ['any' => 'i'],
        'o' => ['any' => 'o'],
        'ø' => ['any' => 'ö'],
        'ʊ' => ['any' => 'u'],
        'u' => ['any' => 'û'],
        'ɔ' => ['any' => 'o'],
        'ʕ' => ['any' => "'"],
        'ʔ' => ['any' => "'"],
        'ʡ' => ['any' => "'"],
        'ɢ' => ['any' => "'g"],
        'ɟ' => ['any' => 'g'],
        'ħ' => ['any' => "'h"],
        'ʜ' => ['any' => "'h"],
        'ɭ' => ['any' => "'l"],
        'ŋg' => ['any' => 'ng'],
        'ŋk' => ['any' => 'nk'],
        'ŋqʰ' => ['any' => 'nq'],
        'ŋq' => ['any' => 'nq'],
        'ŋ' => ['any' => 'ng'],
        'ɲ' => ['any' => 'ny'],
        'nʲ' => ['any' => 'ny'],
        'nj' => ['any' => 'ny'],
        'ʃ' => ['any' => 'ş'],
        'ʒ' => ['any' => 'j'],
        'χ' => ['any' => 'x'],
        'x' => ['any' => 'x'],
        'ʁ' => ['any' => 'ğ'],
        'qʷʰ' => ['any' => 'qw'],
        'qʷ' => ['any' => 'qw'],
        'ɢʷ' => ['any' => "'gw"],
        'ɟʷ' => ['any' => 'gw'],
        'gʷ' => ['any' => 'gw'],
        'kʷʰ' => ['any' => 'kw'],
        'kʷ' => ['any' => 'kw'],
        'cʷʰ' => ['any' => 'kw'],
        'cʷ' => ['any' => 'kw'],
        'c͡çʷ' => ['any' => 'kw'],
        't̪͡ʃ' => ['any' => 'ç'],
        't͡ʃ' => ['any' => 'ç'],
        'd̪͡ʒ' => ['any' => 'c'],
        'd͡ʒ' => ['any' => 'c'],
        'c͡ç' => ['any' => 'k'],
        't̪͡ʙ̥ɨ' => ['any' => 'twe'],
        't̪͡ʙɨ' => ['any' => 'twe'],
        't̪͡ʙ̥' => ['any' => 'tw'],
        't̪͡ʙ' => ['any' => 'tw'],
        't̪͡p' => ['any' => 'tw'],
        'd̪͡b' => ['any' => 'dw'],
        'sʷ' => ['any' => 'sw'],
        'sɨ' => ['any' => 'se'],
        'jɨ' => ['any' => 'yı'],
        'jɪ' => ['any' => 'yı'],
        "ɨ\u{032F}" => ['any' => 'ı'],
        "ɨ\u{0306}" => ['any' => 'ı'],
        "ɨ\u{0306}\u{032F}" => ['any' => 'ı'],
        "ɨ\u{032F}\u{0306}" => ['any' => 'ı'],
        "ɪ\u{032F}" => ['any' => 'ı'],
        "ɪ\u{0306}" => ['any' => 'ı'],
        "ɪ\u{0306}\u{032F}" => ['any' => 'ı'],
        "ɪ\u{032F}\u{0306}" => ['any' => 'ı'],
        'q' => ['any' => 'q'],
        'qʰ' => ['any' => 'q'],
        'c' => ['any' => 'k'],
        'cʰ' => ['any' => 'k'],
        'k' => ['any' => 'k'],
        'kʰ' => ['any' => 'k'],
        'g' => ['any' => 'g'],
        'd̪' => ['any' => 'd'],
        'd' => ['any' => 'd'],
        't̪' => ['any' => 't'],
        't' => ['any' => 't'],
        'tʰ' => ['any' => 't'],
        'p' => ['any' => 'p'],
        'pʰ' => ['any' => 'p'],
        'b' => ['any' => 'b'],
        'f' => ['any' => 'f'],
        'v' => ['any' => 'v'],
        's' => ['any' => 's'],
        'z' => ['any' => 'z'],
        'h' => ['any' => 'h'],
        'm' => ['any' => 'm'],
        'n' => ['any' => 'n'],
        'l' => ['any' => 'l'],
        'ɫ' => ['any' => 'l'],
        'ʎ' => ['any' => 'l'],
        'ɾ' => ['any' => 'r'],
        'ɹ' => ['any' => 'r'],
        'r' => ['any' => 'r'],
        'w' => ['any' => 'w'],
        'ʷ' => ['any' => 'w'],
        'ʲ' => ['any' => 'y'],
        'j' => ['any' => 'y'],
    ], $wordset_id);
}

function ll_tools_ipa_orthography_get_effective_manual_rules(int $wordset_id): array {
    return ll_tools_ipa_orthography_apply_profile_locked_manual_rules(
        ll_tools_ipa_orthography_merge_manual_rules(
            ll_tools_ipa_orthography_get_profile_default_manual_rules($wordset_id),
            ll_tools_ipa_orthography_get_manual_rules($wordset_id)
        ),
        $wordset_id
    );
}

function ll_tools_ipa_orthography_get_profile_default_settings(int $wordset_id): array {
    $settings = ll_tools_ipa_orthography_settings_defaults();
    if (ll_tools_ipa_orthography_get_profile_key($wordset_id) !== 'zazaki_genc_palu') {
        return $settings;
    }

    $settings['word_overrides'] = [
        'be' => 'bı',
        'ce' => 'cı',
        'cinê' => 'cini',
        'ciniüê' => 'cinyê',
        'cinyyê' => 'cinyê',
        'fıstıqû' => 'fistıqû',
        'fıstıqûna' => 'fistıqûna',
        'fıstûn' => 'fistûn',
        'fıstûna' => 'fistûna',
        'fıstûne' => 'fistûne',
        'fıstûnê' => 'fistûnê',
        'fıstıx' => 'fistix',
        'hindistên' => "h'indistên",
        'hindistûn' => "h'indistûn",
        'hindistûna' => "h'indistûna",
        'hndistn' => "'hndistn",
        'hndistna' => "'hndistna",
        'hındistên' => "'hındistên",
        'hındistûn' => "'hındistûn",
        'hındistûna' => "'hındistûna",
        'in' => 'ın',
        'ina' => 'ına',
        'kwele' => 'kwelı',
        'kye' => 'kiye',
        'maze' => 'mazı',
        'me' => 'mı',
        'mwerik' => 'mwêrik',
        'nyûne' => 'nyûnı',
        'otobûs' => 'otobüs',
        'otobûsa' => 'otobüsa',
        'owe' => 'owı',
        'qıncele' => 'qıncelı',
        'rezıl' => 'rezil',
        'rezıla' => 'rezila',
        'te' => 'tı',
        'tişort' => 'tişört',
        'mers' => 'merz',
        'miçık' => 'mirçık',
        'miçk' => 'mirçık',
        'miçıkû' => 'mirçıkû',
        'miçkû' => 'mirçıkû',
        'çân' => 'çând',
        'kera' => 'kerra',
        'kerawa' => 'kerrawa',
        'kerawo' => 'kerrawo',
        'kere' => 'kerre',
        'mere' => 'merre',
        'xwı' => 'xwe',
        'kerê' => 'kerrê',
        'kerêy' => 'kerrêy',
        'kerayın' => 'kerrayin',
        'kero' => 'kerro',
        'kerû' => 'kerrû',
        'perena' => 'perrena',
        'zere' => 'zerre',
        'zerê' => 'zerrê',
        'çartele' => 'çartelı',
        'sı' => 'se',
        'şe' => 'şı',
        'twı' => 'twe',
        'pwıre' => 'pwırı',
        'ye' => 'yı',
        'çen' => 'çend',
        "'ez" => 'ez',
    ];
    $settings['phrase_overrides'] = [
        [
            'from' => ['des', 'erzen'],
            'from_key' => ['des', 'erzen'],
            'to' => ['dest', 'erzen'],
        ],
    ];
    $settings['optional_matches'] = [
        [
            'ipa' => "ɨ\u{0306}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɨ\u{032F}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɨ\u{0306}\u{032F}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɨ\u{032F}\u{0306}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɪ\u{0306}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɪ\u{032F}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɪ\u{0306}\u{032F}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => "ɪ\u{032F}\u{0306}",
            'orthography' => 'ı',
            'orthography_key' => 'ı',
        ],
        [
            'ipa' => 'd̪ɨ',
            'orthography' => 'dı',
            'orthography_key' => 'dı',
        ],
        [
            'ipa' => 'd̪ɨ',
            'orthography' => 'de',
            'orthography_key' => 'de',
        ],
        [
            'ipa' => 'd̪ɪ',
            'orthography' => 'dı',
            'orthography_key' => 'dı',
        ],
        [
            'ipa' => 'd̪ɪ',
            'orthography' => 'de',
            'orthography_key' => 'de',
        ],
    ];
    $settings['recording_type_punctuation'] = [
        'introduction' => '.',
        'question' => '?',
    ];
    $settings['sentence_case'] = true;

    return $settings;
}

function ll_tools_ipa_orthography_profile_replacements(string $text, array $replacements): string {
    foreach ($replacements as $from => $to) {
        $text = str_replace((string) $from, (string) $to, $text);
    }
    return $text;
}

function ll_tools_ipa_orthography_apply_profile_output_replacements(string $text, int $wordset_id): string {
    if ($text === '' || ll_tools_ipa_orthography_get_profile_key($wordset_id) !== 'zazaki_genc_palu') {
        return $text;
    }

    return ll_tools_ipa_orthography_profile_replacements($text, [
        'ngg' => 'ng',
        'ngk' => 'nk',
        'ngq' => 'nq',
    ]);
}

function ll_tools_ipa_orthography_profile_strip_terminal_punctuation(string $text): string {
    return trim((string) preg_replace('/[.!?]+$/u', '', trim($text)));
}

function ll_tools_ipa_orthography_profile_sentence_case(string $text, string $language = ''): string {
    return (string) preg_replace_callback('/^(\P{L}*)(\p{L})(.*)$/us', static function (array $matches) use ($language): string {
        return (string) $matches[1] . ll_tools_ipa_orthography_mb_upper((string) $matches[2], $language) . (string) $matches[3];
    }, $text, 1);
}

function ll_tools_ipa_orthography_profile_add_seconds(&$profile, string $key, float $seconds): void {
    if (!is_array($profile) || $key === '') {
        return;
    }

    $profile[$key] = round((float) ($profile[$key] ?? 0) + max(0.0, $seconds), 4);
}

function ll_tools_ipa_orthography_profile_convert_ipa_to_text(string $ipa_text, int $wordset_id, string $recording_type = ''): array {
    return [
        'text' => '',
        'complete' => false,
        'matched_tokens' => 0,
        'token_count' => 0,
        'profile' => [],
    ];
}

function ll_tools_ipa_orthography_profile_replace_suggested_tokens(string $suggested_text, array $suggested_parts, array $replacement_tokens): string {
    $result = $suggested_text;
    for ($index = count($suggested_parts) - 1; $index >= 0; $index--) {
        if (!array_key_exists($index, $replacement_tokens)) {
            continue;
        }
        $part = (array) $suggested_parts[$index];
        $result = ll_tools_ipa_orthography_replace_char_span(
            $result,
            (int) ($part['start'] ?? 0),
            (int) ($part['length'] ?? 0),
            (string) $replacement_tokens[$index]
        );
    }
    return $result;
}

function ll_tools_ipa_orthography_build_ipa_text_from_parts(string $ipa_text, array $ipa_parts, array $replacement_words): string {
    $result = $ipa_text;
    for ($index = count($ipa_parts) - 1; $index >= 0; $index--) {
        if (!array_key_exists($index, $replacement_words)) {
            continue;
        }
        $part = (array) $ipa_parts[$index];
        $result = ll_tools_ipa_orthography_replace_char_span(
            $result,
            (int) ($part['start'] ?? 0),
            (int) ($part['length'] ?? 0),
            (string) $replacement_words[$index]
        );
    }
    return $result;
}

function ll_tools_ipa_orthography_find_char_position(string $text, string $needle): ?int {
    if ($text === '' || $needle === '') {
        return null;
    }

    $pos = function_exists('mb_strpos') ? mb_strpos($text, $needle, 0, 'UTF-8') : strpos($text, $needle);
    return $pos === false ? null : (int) $pos;
}

function ll_tools_ipa_orthography_text_contains(string $text, string $needle): bool {
    return ll_tools_ipa_orthography_find_char_position($text, $needle) !== null;
}

function ll_tools_ipa_orthography_corresponding_actual_output_key(
    string $actual_key,
    string $suggested_key,
    string $suggested_output_key
): string {
    $pos = ll_tools_ipa_orthography_find_char_position($suggested_key, $suggested_output_key);
    if ($pos === null) {
        return $actual_key;
    }

    $length = ll_tools_ipa_orthography_strlen($suggested_output_key);
    if ($length <= 0 || $pos >= ll_tools_ipa_orthography_strlen($actual_key)) {
        return $actual_key;
    }

    $slice = ll_tools_ipa_orthography_substr($actual_key, $pos, min($length, ll_tools_ipa_orthography_strlen($actual_key) - $pos));
    return $slice !== '' ? $slice : $actual_key;
}

function ll_tools_ipa_orthography_replacement_rule_options(array $rules, string $target_output_key, string $language = ''): array {
    if ($target_output_key === '') {
        return [];
    }

    $options = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        if (empty($rule['manual'])) {
            continue;
        }

        $segment = (string) ($rule['segment'] ?? '');
        $output_key = ll_tools_ipa_orthography_rule_output_key((string) ($rule['output'] ?? ''), $language);
        if ($segment === '' || $output_key === '') {
            continue;
        }

        if ($output_key !== $target_output_key && !ll_tools_ipa_orthography_text_contains($target_output_key, $output_key)) {
            continue;
        }

        $options[] = [
            'segment' => $segment,
            'output_key' => $output_key,
            'context' => ll_tools_ipa_orthography_normalize_context((string) ($rule['context'] ?? 'any')),
            'score' => ($output_key === $target_output_key ? 100 : 0)
                + max(0, 20 - ll_tools_ipa_orthography_strlen($segment)),
        ];
    }

    usort($options, static function (array $left, array $right): int {
        $score_compare = (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
        if ($score_compare !== 0) {
            return $score_compare;
        }
        return strcmp((string) ($left['segment'] ?? ''), (string) ($right['segment'] ?? ''));
    });

    $seen = [];
    $out = [];
    foreach ($options as $option) {
        $segment = (string) ($option['segment'] ?? '');
        if ($segment === '' || isset($seen[$segment])) {
            continue;
        }
        $seen[$segment] = true;
        $out[] = $option;
    }

    return $out;
}

function ll_tools_ipa_orthography_replace_variant_segment(array $variant, string $current_segment, string $replacement_segment): ?array {
    $ipa = (string) ($variant['ipa'] ?? '');
    if ($ipa === '' || $current_segment === '' || $replacement_segment === '' || $current_segment === $replacement_segment) {
        return null;
    }

    $pos = ll_tools_ipa_orthography_find_char_position($ipa, $current_segment);
    if ($pos === null) {
        return null;
    }

    $spans = array_values((array) ($variant['spans'] ?? []));
    $spans[] = [
        'start' => $pos,
        'length' => ll_tools_ipa_orthography_strlen($replacement_segment),
    ];

    return [
        'ipa' => ll_tools_ipa_orthography_replace_char_span(
            $ipa,
            $pos,
            ll_tools_ipa_orthography_strlen($current_segment),
            $replacement_segment
        ),
        'spans' => $spans,
    ];
}

function ll_tools_ipa_orthography_profile_word_ipa_variants(array $mismatch, int $wordset_id, string $language = ''): array {
    $ipa_word = (string) ($mismatch['ipa_word'] ?? '');
    if ($ipa_word === '' || $wordset_id <= 0) {
        return [];
    }

    $actual_key = ll_tools_ipa_orthography_rule_output_key((string) ($mismatch['actual_diff'] ?? ''), $language);
    $suggested_key = ll_tools_ipa_orthography_rule_output_key((string) ($mismatch['suggested_diff'] ?? ''), $language);
    if ($actual_key === '' || $suggested_key === '') {
        return [];
    }

    $rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);
    if (empty($rules)) {
        return [];
    }

    $current_segments = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $segment = (string) ($rule['segment'] ?? '');
        $output_key = ll_tools_ipa_orthography_rule_output_key((string) ($rule['output'] ?? ''), $language);
        if ($segment === '' || $output_key === '' || !ll_tools_ipa_orthography_text_contains($suggested_key, $output_key)) {
            continue;
        }
        if (ll_tools_ipa_orthography_find_char_position($ipa_word, $segment) === null) {
            continue;
        }

        $target_key = ll_tools_ipa_orthography_corresponding_actual_output_key($actual_key, $suggested_key, $output_key);
        $replacement_options = ll_tools_ipa_orthography_replacement_rule_options($rules, $target_key, $language);
        if (empty($replacement_options)) {
            continue;
        }

        $current_segments[] = [
            'segment' => $segment,
            'output_key' => $output_key,
            'replacements' => $replacement_options,
            'score' => (ll_tools_ipa_orthography_rule_output_has_letter($output_key) ? 100 : 0)
                + min(20, ll_tools_ipa_orthography_strlen($output_key)),
        ];
    }

    if (empty($current_segments)) {
        return [];
    }

    usort($current_segments, static function (array $left, array $right): int {
        $score_compare = (int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0);
        if ($score_compare !== 0) {
            return $score_compare;
        }
        return strcmp((string) ($left['segment'] ?? ''), (string) ($right['segment'] ?? ''));
    });

    $variants = [
        [
            'ipa' => $ipa_word,
            'spans' => [],
        ],
    ];

    foreach (array_slice($current_segments, 0, 4) as $current) {
        $next = $variants;
        foreach ($variants as $variant) {
            foreach (array_slice((array) ($current['replacements'] ?? []), 0, 4) as $replacement) {
                $entry = ll_tools_ipa_orthography_replace_variant_segment(
                    $variant,
                    (string) ($current['segment'] ?? ''),
                    (string) ($replacement['segment'] ?? '')
                );
                if (is_array($entry)) {
                    $next[] = $entry;
                }
                if (count($next) >= 24) {
                    break 2;
                }
            }
        }
        $variants = $next;
        if (count($variants) >= 24) {
            break;
        }
    }

    $seen = [];
    $out = [];
    foreach ($variants as $entry) {
        $ipa = (string) ($entry['ipa'] ?? '');
        if ($ipa === '' || $ipa === $ipa_word || isset($seen[$ipa])) {
            continue;
        }
        $seen[$ipa] = true;
        $out[] = [
            'ipa' => $ipa,
            'spans' => array_values((array) ($entry['spans'] ?? [])),
        ];
    }

    usort($out, static function (array $left, array $right): int {
        $span_compare = count((array) ($right['spans'] ?? [])) <=> count((array) ($left['spans'] ?? []));
        if ($span_compare !== 0) {
            return $span_compare;
        }
        return ll_tools_ipa_orthography_strlen((string) ($left['ipa'] ?? ''))
            <=> ll_tools_ipa_orthography_strlen((string) ($right['ipa'] ?? ''));
    });

    return $out;
}

function ll_tools_ipa_orthography_profile_ipa_suggestions(
    string $actual_text,
    string $ipa_text,
    int $wordset_id,
    string $recording_type,
    array $detail,
    int $limit = 4
): array {
    $mismatches = array_values((array) ($detail['word_mismatches'] ?? []));
    $ipa_parts = ll_tools_ipa_orthography_split_nonspace_spans($ipa_text);
    if (empty($mismatches) || empty($ipa_parts)) {
        return [];
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $options_by_index = [];
    foreach ($mismatches as $mismatch) {
        if (!is_array($mismatch)) {
            continue;
        }
        $word_index = (int) ($mismatch['word_index'] ?? -1);
        if ($word_index < 0 || !isset($ipa_parts[$word_index])) {
            continue;
        }
        $variants = array_values(array_filter(
            ll_tools_ipa_orthography_profile_word_ipa_variants($mismatch, $wordset_id, $language),
            'is_array'
        ));
        if (!empty($variants)) {
            if (!isset($options_by_index[$word_index])) {
                $options_by_index[$word_index] = [];
            }
            $seen_variants = [];
            foreach ((array) $options_by_index[$word_index] as $existing_variant) {
                if (is_array($existing_variant)) {
                    $seen_variants[(string) ($existing_variant['ipa'] ?? '')] = true;
                }
            }
            foreach ($variants as $variant) {
                $variant_ipa = (string) ($variant['ipa'] ?? '');
                if ($variant_ipa === '' || isset($seen_variants[$variant_ipa])) {
                    continue;
                }
                $seen_variants[$variant_ipa] = true;
                $options_by_index[$word_index][] = $variant;
            }
        }
    }

    if (empty($options_by_index)) {
        return [];
    }

    $replacement_sets = [[]];
    foreach ($options_by_index as $word_index => $variants) {
        $next_sets = [];
        foreach ($replacement_sets as $set) {
            foreach (array_slice($variants, 0, max($limit, min(16, $limit * 4))) as $variant) {
                if (!is_array($variant) || (string) ($variant['ipa'] ?? '') === '') {
                    continue;
                }
                $next = $set;
                $next[(int) $word_index] = $variant;
                $next_sets[] = $next;
                if (count($next_sets) >= $limit * 2) {
                    break 2;
                }
            }
        }
        $replacement_sets = $next_sets;
    }

    $suggestions = [];
    foreach ($replacement_sets as $replacement_variants) {
        $replacement_words = [];
        $suggestion_spans = [];
        foreach ($replacement_variants as $word_index => $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $replacement_words[(int) $word_index] = (string) ($variant['ipa'] ?? '');
            $part = (array) ($ipa_parts[(int) $word_index] ?? []);
            $part_start = (int) ($part['start'] ?? 0);
            foreach ((array) ($variant['spans'] ?? []) as $span) {
                if (!is_array($span)) {
                    continue;
                }
                $length = max(0, (int) ($span['length'] ?? 0));
                if ($length <= 0) {
                    continue;
                }
                $suggestion_spans[] = [
                    'start' => $part_start + max(0, (int) ($span['start'] ?? 0)),
                    'length' => $length,
                ];
            }
        }

        $candidate_ipa = ll_tools_ipa_orthography_build_ipa_text_from_parts($ipa_text, $ipa_parts, $replacement_words);
        if ($candidate_ipa === '' || $candidate_ipa === $ipa_text) {
            continue;
        }
        $candidate_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            $actual_text,
            $candidate_ipa,
            $wordset_id,
            $recording_type,
            null,
            false
        );
        if (empty($candidate_detail['matches'])) {
            continue;
        }
        $suggestions[] = [
            'ipa' => $candidate_ipa,
            'label' => $candidate_ipa,
            'spans' => array_values($suggestion_spans),
        ];
        if (count($suggestions) >= $limit) {
            break;
        }
    }

    $seen = [];
    return array_values(array_filter($suggestions, static function (array $suggestion) use (&$seen): bool {
        $ipa = (string) ($suggestion['ipa'] ?? '');
        if ($ipa === '' || isset($seen[$ipa])) {
            return false;
        }
        $seen[$ipa] = true;
        return true;
    }));
}

function ll_tools_ipa_orthography_profile_mismatch_detail(
    string $actual_text,
    string $ipa_text,
    int $wordset_id,
    string $recording_type = '',
    ?array $prediction = null,
    bool $include_ipa_suggestions = true
): array {
    if ($prediction === null) {
        $rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);
        $prediction = ll_tools_ipa_orthography_convert_ipa_to_text(
            $ipa_text,
            $rules,
            $wordset_id,
            $recording_type,
            0,
            ll_tools_ipa_orthography_get_engine_rules_index_for_wordset($wordset_id, $rules)
        );
        $prediction['source'] = 'rules';
        $prediction['settings'] = ll_tools_ipa_orthography_get_settings($wordset_id);
        $prediction['profile'] = [];
    }
    $suggested_text = (string) ($prediction['text'] ?? '');
    $requires_lexical_decision = !empty($prediction['requires_lexical_decision']);
    if (empty($prediction['complete']) || $suggested_text === '') {
        return [
            'actual_text' => $actual_text,
            'suggested_text' => $suggested_text,
            'canonical_suggested_text' => $suggested_text,
            'ipa_text' => $ipa_text,
            'matches' => false,
            'requires_lexical_decision' => $requires_lexical_decision,
            'actual_spans' => [],
            'suggested_spans' => [],
            'ipa_spans' => [],
            'word_mismatches' => [],
            'ipa_suggestions' => [],
        ];
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $actual_parts = ll_tools_ipa_orthography_split_nonspace_spans($actual_text);
    $suggested_parts = ll_tools_ipa_orthography_split_nonspace_spans($suggested_text);
    $ipa_parts = ll_tools_ipa_orthography_split_nonspace_spans($ipa_text);
    $actual_spans = [];
    $suggested_spans = [];
    $ipa_spans = [];
    $word_mismatches = [];
    $replacement_tokens = [];
    $matches = true;
    $entry_bound_override_match_count = 0;

    if (count($actual_parts) !== count($suggested_parts) || count($suggested_parts) !== count($ipa_parts)) {
        $matches = ll_tools_ipa_orthography_profile_compare_key($actual_text, $language)
            === ll_tools_ipa_orthography_profile_compare_key($suggested_text, $language);
        if (!$matches) {
            foreach ($actual_parts as $part) {
                $actual_spans[] = [
                    'start' => (int) ($part['start'] ?? 0),
                    'length' => (int) ($part['length'] ?? 0),
                ];
            }
            foreach ($suggested_parts as $part) {
                $suggested_spans[] = [
                    'start' => (int) ($part['start'] ?? 0),
                    'length' => (int) ($part['length'] ?? 0),
                ];
            }
            foreach ($ipa_parts as $part) {
                $ipa_spans[] = [
                    'start' => (int) ($part['start'] ?? 0),
                    'length' => (int) ($part['length'] ?? 0),
                ];
            }
        }
    } else {
        foreach ($suggested_parts as $index => $suggested_part) {
            $actual_part = (array) ($actual_parts[$index] ?? []);
            $ipa_part = (array) ($ipa_parts[$index] ?? []);
            $actual_word = (string) ($actual_part['text'] ?? '');
            $suggested_word = (string) ($suggested_part['text'] ?? '');
            $ipa_word = (string) ($ipa_part['text'] ?? '');
            $entry_bound_override_matches_word = ll_tools_ipa_orthography_profile_word_matches_entry_bound_override($actual_word, $suggested_word, $language, $wordset_id)
                && ll_tools_ipa_orthography_profile_entry_bound_override_context_allows($suggested_word, $language, $wordset_id, $prediction);
            if ($entry_bound_override_matches_word) {
                $entry_bound_override_match_count++;
            }

            if ($entry_bound_override_matches_word
                || ll_tools_ipa_orthography_profile_words_equivalent(
                    $actual_word,
                    $suggested_word,
                    $ipa_word,
                    $language,
                    $wordset_id,
                    (int) ($prediction['word_id'] ?? 0)
                )) {
                if (ll_tools_ipa_orthography_profile_compare_key($actual_word, $language)
                    !== ll_tools_ipa_orthography_profile_compare_key($suggested_word, $language)) {
                    $replacement_tokens[$index] = $actual_word;
                }
                continue;
            }

            $matches = false;
            $diff = ll_tools_ipa_orthography_diff_span_pair(
                $actual_word,
                $suggested_word,
                (int) ($actual_part['start'] ?? 0),
                (int) ($suggested_part['start'] ?? 0),
                $language
            );
            if (is_array($diff['actual'] ?? null)) {
                $actual_spans[] = $diff['actual'];
            }
            if (is_array($diff['suggested'] ?? null)) {
                $suggested_spans[] = $diff['suggested'];
            }
            $ipa_span = ll_tools_ipa_orthography_ipa_span_for_mismatch(
                $ipa_word,
                (string) ($diff['actual_diff'] ?? ''),
                (string) ($diff['suggested_diff'] ?? ''),
                (int) ($ipa_part['start'] ?? 0),
                (int) ($ipa_part['length'] ?? 0),
                $language,
                $wordset_id
            );
            $ipa_spans[] = $ipa_span;
            $word_mismatches[] = [
                'word_index' => (int) $index,
                'actual_word' => $actual_word,
                'suggested_word' => $suggested_word,
                'ipa_word' => $ipa_word,
                'actual_diff' => (string) ($diff['actual_diff'] ?? ''),
                'suggested_diff' => (string) ($diff['suggested_diff'] ?? ''),
            ];
        }
    }

    $adjusted_suggested_text = empty($replacement_tokens)
        ? $suggested_text
        : ll_tools_ipa_orthography_profile_replace_suggested_tokens($suggested_text, $suggested_parts, $replacement_tokens);
    $final_high_vowel_candidate_count = max(0, (int) ($prediction['final_high_vowel_candidate_count'] ?? 0));
    if ($requires_lexical_decision
        && $final_high_vowel_candidate_count > 0
        && $entry_bound_override_match_count >= $final_high_vowel_candidate_count
        && empty($word_mismatches)) {
        $requires_lexical_decision = false;
    }
    if ($requires_lexical_decision) {
        $matches = false;
        if (empty($word_mismatches) && count($actual_parts) === count($ipa_parts)) {
            foreach ($ipa_parts as $index => $ipa_part) {
                $ipa_word = (string) ($ipa_part['text'] ?? '');
                $final_vowel_span = ll_tools_ipa_orthography_final_high_vowel_span(
                    $ipa_word,
                    (int) ($ipa_part['start'] ?? 0)
                );
                if (!is_array($final_vowel_span)) {
                    continue;
                }

                $actual_part = (array) ($actual_parts[$index] ?? []);
                if (!empty($actual_part)) {
                    $actual_spans[] = [
                        'start' => (int) ($actual_part['start'] ?? 0),
                        'length' => (int) ($actual_part['length'] ?? 0),
                    ];
                }

                $suggested_part = (array) ($suggested_parts[$index] ?? []);
                if (!empty($suggested_part)) {
                    $suggested_spans[] = [
                        'start' => (int) ($suggested_part['start'] ?? 0),
                        'length' => (int) ($suggested_part['length'] ?? 0),
                    ];
                }

                $ipa_spans[] = $final_vowel_span;
            }
        }
    }

    $detail = [
        'actual_text' => $actual_text,
        'suggested_text' => $adjusted_suggested_text,
        'canonical_suggested_text' => $suggested_text,
        'ipa_text' => $ipa_text,
        'matches' => $matches,
        'requires_lexical_decision' => $requires_lexical_decision,
        'actual_spans' => array_values($actual_spans),
        'suggested_spans' => array_values($suggested_spans),
        'ipa_spans' => array_values($ipa_spans),
        'word_mismatches' => array_values($word_mismatches),
        'ipa_suggestions' => [],
    ];

    if ($include_ipa_suggestions && !$matches) {
        $detail['ipa_suggestions'] = ll_tools_ipa_orthography_profile_ipa_suggestions(
            $actual_text,
            $ipa_text,
            $wordset_id,
            $recording_type,
            $detail
        );
    }

    return $detail;
}

function ll_tools_ipa_orthography_apply_surface_trill_policy(string $segment, string $output): string {
    return trim($output);
}

function ll_tools_ipa_orthography_tokenize_segment(string $segment, string $mode = 'ipa'): array {
    $segment = function_exists('ll_tools_word_grid_sanitize_ipa')
        ? ll_tools_word_grid_sanitize_ipa($segment, $mode)
        : sanitize_text_field($segment);
    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($segment, $mode)
        : preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) {
        return [];
    }

    $clean = [];
    foreach ((array) $tokens as $token) {
        $normalized = ll_tools_ipa_keyboard_normalize_ipa_token((string) $token, $mode);
        if ($normalized === '') {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_separator')
            && ll_tools_word_grid_is_ipa_separator($normalized, $mode)) {
            continue;
        }
        $clean[] = $normalized;
    }

    return $clean;
}

function ll_tools_ipa_orthography_filter_profile_tokens(array $tokens, int $wordset_id): array {
    if (ll_tools_ipa_orthography_get_profile_key($wordset_id) !== 'zazaki_genc_palu') {
        return $tokens;
    }

    $filtered = [];
    foreach (array_values($tokens) as $index => $token) {
        $token = (string) $token;
        if ($index === 0 && $token === 'ʔ') {
            continue;
        }
        $filtered[] = $token;
    }

    return $filtered;
}

function ll_tools_ipa_orthography_normalize_segment_key(string $segment, string $mode = 'ipa'): string {
    $tokens = ll_tools_ipa_orthography_tokenize_segment($segment, $mode);
    return implode('', $tokens);
}

function ll_tools_ipa_orthography_sanitize_manual_rules($raw, int $wordset_id): array {
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $clean = [];
    if (!is_array($raw)) {
        return $clean;
    }

    foreach ($raw as $segment => $contexts) {
        $segment_key = ll_tools_ipa_orthography_normalize_segment_key((string) $segment, $mode);
        if ($segment_key === '' || !is_array($contexts)) {
            continue;
        }
        if (!ll_tools_ipa_orthography_profile_allows_ipa_segment($segment_key, $wordset_id)) {
            continue;
        }

        foreach ($contexts as $context => $output) {
            $context_key = ll_tools_ipa_orthography_normalize_context((string) $context);
            $output_key = ll_tools_ipa_orthography_sanitize_rule_output_text($output, $language);
            $output_key = ll_tools_ipa_orthography_apply_surface_trill_policy($segment_key, $output_key);
            if ($output_key === '') {
                continue;
            }

            if (!isset($clean[$segment_key])) {
                $clean[$segment_key] = [];
            }
            $clean[$segment_key][$context_key] = $output_key;
        }
    }

    ksort($clean);
    return $clean;
}

function ll_tools_ipa_orthography_sanitize_blocklist($raw, int $wordset_id): array {
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $clean = [];
    if (!is_array($raw)) {
        return $clean;
    }

    foreach ($raw as $segment => $contexts) {
        $segment_key = ll_tools_ipa_orthography_normalize_segment_key((string) $segment, $mode);
        if ($segment_key === '' || !is_array($contexts)) {
            continue;
        }

        foreach ($contexts as $context => $outputs) {
            $context_key = ll_tools_ipa_orthography_normalize_context((string) $context);
            $list = [];
            foreach ((array) $outputs as $output) {
                $output_key = ll_tools_ipa_orthography_sanitize_rule_output_text($output, $language);
                if ($output_key !== '' && !in_array($output_key, $list, true)) {
                    $list[] = $output_key;
                }
            }

            if (empty($list)) {
                continue;
            }

            if (!isset($clean[$segment_key])) {
                $clean[$segment_key] = [];
            }
            $clean[$segment_key][$context_key] = $list;
        }
    }

    ksort($clean);
    return $clean;
}

function ll_tools_ipa_orthography_sanitize_exception_word_ids($raw): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $raw), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    sort($ids);
    return $ids;
}

function ll_tools_ipa_orthography_sanitize_exception_dictionary_entry_ids($raw, array $exception_word_ids = []): array {
    if (!is_array($raw)) {
        return [];
    }

    $allowed_word_ids = array_fill_keys(array_map('intval', $exception_word_ids), true);
    $clean = [];
    foreach ($raw as $word_id => $entry_id) {
        $word_id = absint($word_id);
        if ($word_id <= 0 || (!empty($allowed_word_ids) && !isset($allowed_word_ids[$word_id]))) {
            continue;
        }

        $entry_id = ll_tools_ipa_orthography_sanitize_dictionary_entry_id($entry_id);
        if ($entry_id > 0) {
            $clean[$word_id] = $entry_id;
        }
    }
    ksort($clean, SORT_NUMERIC);
    return $clean;
}

function ll_tools_ipa_orthography_get_manual_rules(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), true);
    $clean = ll_tools_ipa_orthography_sanitize_manual_rules($raw, $wordset_id);
    if ($clean !== $raw) {
        if (empty($clean)) {
            delete_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), $clean);
        }
    }

    return $clean;
}

function ll_tools_ipa_keyboard_ipa_segment_contains_symbol(string $segment, string $symbol, string $mode = 'ipa'): bool {
    $symbol = ll_tools_ipa_keyboard_normalize_ipa_token($symbol, $mode);
    if ($segment === '' || $symbol === '') {
        return false;
    }

    foreach (ll_tools_ipa_orthography_tokenize_segment($segment, $mode) as $token) {
        foreach (ll_tools_ipa_keyboard_split_token_characters((string) $token) as $char) {
            if ($char === $symbol) {
                return true;
            }
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_get_manual_orthography_output_for_symbol(int $wordset_id, string $symbol): string {
    $mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $symbol = ll_tools_ipa_orthography_normalize_segment_key($symbol, $mode);
    if ($wordset_id <= 0 || $symbol === '') {
        return '';
    }

    $manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
    $contexts = (array) ($manual_rules[$symbol] ?? []);
    if (!empty($contexts['any'])) {
        return (string) $contexts['any'];
    }

    $outputs = array_values(array_unique(array_filter(array_map('strval', $contexts))));
    return count($outputs) === 1 ? (string) $outputs[0] : '';
}

function ll_tools_ipa_keyboard_infer_orthography_output_for_ipa_symbol(
    int $wordset_id,
    string $recording_text,
    string $recording_ipa,
    string $symbol
): string {
    $mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $symbol_key = ll_tools_ipa_orthography_normalize_segment_key($symbol, $mode);
    $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text($recording_text)
        : sanitize_text_field($recording_text);
    $recording_ipa = function_exists('ll_tools_word_grid_normalize_ipa_output')
        ? ll_tools_word_grid_normalize_ipa_output($recording_ipa, $mode)
        : trim($recording_ipa);

    if ($wordset_id <= 0 || $symbol_key === '') {
        return '';
    }

    if ($recording_text !== '' && $recording_ipa !== ''
        && function_exists('ll_tools_word_grid_prepare_text_letters')
        && function_exists('ll_tools_word_grid_tokenize_ipa')
        && function_exists('ll_tools_word_grid_align_text_to_ipa')) {
        $letters = ll_tools_word_grid_prepare_text_letters($recording_text, $wordset_language);
        $tokens = ll_tools_word_grid_tokenize_ipa($recording_ipa, $mode);
        if (!empty($tokens)) {
            $tokens = array_values(array_filter($tokens, static function ($token) use ($mode): bool {
                return !function_exists('ll_tools_word_grid_is_ipa_stress_marker')
                    || !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $mode);
            }));
        }

        if (!empty($letters) && !empty($tokens)) {
            $alignment = ll_tools_word_grid_align_text_to_ipa($letters, $tokens, $mode);
            $letter_coverage = (float) ($alignment['matched_letters'] ?? 0) / max(1, count($letters));
            $token_coverage = (float) ($alignment['matched_tokens'] ?? 0) / max(1, count($tokens));
            $strong_enough = (float) ($alignment['avg_score'] ?? 0) >= 0.55
                && $letter_coverage >= 0.55
                && $token_coverage >= 0.45;
            if ($strong_enough) {
                foreach ((array) ($alignment['matches'] ?? []) as $match) {
                    if (!is_array($match)) {
                        continue;
                    }
                    $segment = ll_tools_ipa_orthography_normalize_segment_key((string) ($match['ipa'] ?? ''), $mode);
                    if (!ll_tools_ipa_keyboard_ipa_segment_contains_symbol($segment, $symbol_key, $mode)) {
                        continue;
                    }
                    $output = ll_tools_ipa_orthography_normalize_word_text((string) ($match['text'] ?? ''), $wordset_language);
                    $output = ll_tools_ipa_orthography_apply_surface_trill_policy($symbol_key, $output);
                    if ($output !== '') {
                        return $output;
                    }
                }
            }
        }
    }

    return ll_tools_ipa_keyboard_get_manual_orthography_output_for_symbol($wordset_id, $symbol_key);
}

function ll_tools_ipa_keyboard_approve_ipa_symbol_mapping(int $wordset_id, string $symbol, string $output): array {
    $mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $symbol_key = ll_tools_ipa_orthography_normalize_segment_key($symbol, $mode);
    $output_key = ll_tools_ipa_orthography_sanitize_rule_output_text($output, $wordset_language);
    $output_key = ll_tools_ipa_orthography_apply_surface_trill_policy($symbol_key, $output_key);
    if ($wordset_id <= 0 || $symbol_key === '' || $output_key === ''
        || !in_array($symbol_key, ll_tools_ipa_keyboard_get_unapproved_ipa_symbols(), true)) {
        return [
            'symbol' => '',
            'output' => '',
            'approved_symbols' => ll_tools_ipa_keyboard_get_wordset_approved_ipa_symbols($wordset_id),
        ];
    }

    $manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
    if (!isset($manual_rules[$symbol_key]) || !is_array($manual_rules[$symbol_key])) {
        $manual_rules[$symbol_key] = [];
    }
    $manual_rules[$symbol_key]['any'] = $output_key;
    $manual_rules = ll_tools_ipa_orthography_sanitize_manual_rules($manual_rules, $wordset_id);
    if (empty($manual_rules)) {
        delete_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), $manual_rules);
    }

    return [
        'symbol' => $symbol_key,
        'output' => $output_key,
        'approved_symbols' => ll_tools_ipa_keyboard_add_wordset_approved_ipa_symbol($wordset_id, $symbol_key),
    ];
}

function ll_tools_ipa_orthography_get_blocklist(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key(), true);
    $clean = ll_tools_ipa_orthography_sanitize_blocklist($raw, $wordset_id);
    if ($clean !== $raw) {
        if (empty($clean)) {
            delete_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key(), $clean);
        }
    }

    return $clean;
}

function ll_tools_ipa_orthography_get_exception_word_ids(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key(), true);
    $clean = ll_tools_ipa_orthography_sanitize_exception_word_ids($raw);
    if ($clean !== $raw) {
        if (empty($clean)) {
            delete_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key(), $clean);
        }
    }

    return $clean;
}

function ll_tools_ipa_orthography_get_exception_dictionary_entry_ids(int $wordset_id, ?array $exception_word_ids = null): array {
    if ($wordset_id <= 0) {
        return [];
    }

    if ($exception_word_ids === null) {
        $exception_word_ids = ll_tools_ipa_orthography_get_exception_word_ids($wordset_id);
    }

    $raw = get_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key(), true);
    $clean = ll_tools_ipa_orthography_sanitize_exception_dictionary_entry_ids($raw, $exception_word_ids);
    if ($clean !== $raw) {
        if (empty($clean)) {
            delete_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key(), $clean);
        }
    }

    return $clean;
}

function ll_tools_ipa_orthography_infer_exception_dictionary_entry_ids(array $exception_word_ids): array {
    $entry_ids = [];
    foreach (ll_tools_ipa_orthography_sanitize_exception_word_ids($exception_word_ids) as $word_id) {
        $entry_id = ll_tools_ipa_orthography_get_word_dictionary_entry_id($word_id);
        if ($entry_id > 0) {
            $entry_ids[$word_id] = $entry_id;
        }
    }
    ksort($entry_ids, SORT_NUMERIC);
    return $entry_ids;
}

function ll_tools_ipa_orthography_exception_applies_to_word(
    int $wordset_id,
    int $word_id,
    array $exception_word_ids,
    ?array $exception_dictionary_entry_ids = null
): bool {
    if ($wordset_id <= 0 || $word_id <= 0 || !in_array($word_id, $exception_word_ids, true)) {
        return false;
    }

    if ($exception_dictionary_entry_ids === null) {
        $exception_dictionary_entry_ids = ll_tools_ipa_orthography_get_exception_dictionary_entry_ids($wordset_id, $exception_word_ids);
    }

    $required_entry_id = (int) ($exception_dictionary_entry_ids[$word_id] ?? 0);
    if ($required_entry_id <= 0) {
        return true;
    }

    $current_entry_id = ll_tools_ipa_orthography_get_word_dictionary_entry_id($word_id);
    return $current_entry_id > 0 && $current_entry_id === $required_entry_id;
}

function ll_tools_ipa_orthography_update_exception_word_id(int $wordset_id, int $word_id, bool $enabled): array {
    $wordset_id = (int) $wordset_id;
    $word_id = (int) $word_id;
    $existing = ll_tools_ipa_orthography_get_exception_word_ids($wordset_id);
    $entry_ids = ll_tools_ipa_orthography_get_exception_dictionary_entry_ids($wordset_id, $existing);
    if ($wordset_id <= 0 || $word_id <= 0) {
        return $existing;
    }

    if ($enabled) {
        if (!in_array($word_id, $existing, true)) {
            $existing[] = $word_id;
        }
        $entry_id = ll_tools_ipa_orthography_get_word_dictionary_entry_id($word_id);
        if ($entry_id > 0) {
            $entry_ids[$word_id] = $entry_id;
        }
    } else {
        $existing = array_values(array_filter($existing, static function (int $entry) use ($word_id): bool {
            return $entry !== $word_id;
        }));
        unset($entry_ids[$word_id]);
    }

    $existing = ll_tools_ipa_orthography_sanitize_exception_word_ids($existing);
    $entry_ids = ll_tools_ipa_orthography_sanitize_exception_dictionary_entry_ids($entry_ids, $existing);
    if (empty($existing)) {
        delete_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_exception_word_ids_meta_key(), $existing);
    }
    if (empty($entry_ids)) {
        delete_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_exception_dictionary_entry_ids_meta_key(), $entry_ids);
    }

    return $existing;
}

function ll_tools_ipa_orthography_build_rule_sample(array $row): array {
    return [
        'word_id' => (int) ($row['word_id'] ?? 0),
        'recording_id' => (int) ($row['recording_id'] ?? 0),
        'word_text' => (string) (($row['word_info']['word_text'] ?? '') ?: get_the_title((int) ($row['word_id'] ?? 0))),
        'word_translation' => (string) ($row['word_info']['translation'] ?? ''),
        'recording_text' => (string) ($row['recording_text'] ?? ''),
        'recording_ipa' => (string) ($row['recording_ipa'] ?? ''),
        'word_edit_link' => get_edit_post_link((int) ($row['word_id'] ?? 0), 'raw'),
    ];
}

function ll_tools_ipa_orthography_record_rule_observation(
    array &$stats,
    string $segment,
    string $context_key,
    string $output,
    array $sample
): void {
    if ($segment === '' || $output === '') {
        return;
    }

    if (!isset($stats[$segment])) {
        $stats[$segment] = [
            'any' => ['counts' => [], 'samples' => []],
            'final' => ['counts' => [], 'samples' => []],
            'nonfinal' => ['counts' => [], 'samples' => []],
        ];
    }

    $context_key = ll_tools_ipa_orthography_normalize_context($context_key);
    if (!isset($stats[$segment][$context_key])) {
        $stats[$segment][$context_key] = ['counts' => [], 'samples' => []];
    }

    $stats[$segment][$context_key]['counts'][$output] = (int) ($stats[$segment][$context_key]['counts'][$output] ?? 0) + 1;
    if (!isset($stats[$segment][$context_key]['samples'][$output])) {
        $stats[$segment][$context_key]['samples'][$output] = [];
    }
    if (count($stats[$segment][$context_key]['samples'][$output]) < 5) {
        $stats[$segment][$context_key]['samples'][$output][] = $sample;
    }
}

function ll_tools_ipa_orthography_collect_training_rows(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    $mode = (string) ($transcription['mode'] ?? 'ipa');
    if ($wordset_id <= 0 || $mode !== 'ipa') {
        return [];
    }

    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [];
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
        return [];
    }

    $wordset_language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $word_display = ll_tools_ipa_keyboard_get_word_display_map($word_ids);
    $rows = [];

    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
            ? ll_tools_word_grid_sanitize_non_ipa_text((string) get_post_meta($recording_id, 'recording_text', true))
            : sanitize_text_field((string) get_post_meta($recording_id, 'recording_text', true));
        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $mode);
        if ($recording_text === '' || $recording_ipa === '' || preg_match('/\s/u', $recording_text)) {
            continue;
        }

        $letters = function_exists('ll_tools_word_grid_prepare_text_letters')
            ? ll_tools_word_grid_prepare_text_letters($recording_text, $wordset_language)
            : preg_split('//u', $recording_text, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
            ? ll_tools_word_grid_tokenize_ipa($recording_ipa, $mode)
            : preg_split('//u', $recording_ipa, -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($tokens)) {
            $tokens = array_values(array_filter($tokens, static function ($token) use ($mode): bool {
                return !function_exists('ll_tools_word_grid_is_ipa_stress_marker')
                    || !ll_tools_word_grid_is_ipa_stress_marker((string) $token, $mode);
            }));
        }
        if (empty($letters) || empty($tokens)
            || !function_exists('ll_tools_word_grid_align_text_to_ipa')) {
            continue;
        }

        $alignment = ll_tools_word_grid_align_text_to_ipa($letters, $tokens, $mode);
        if (empty($alignment['matches'])) {
            continue;
        }
        $letter_coverage = (float) ($alignment['matched_letters'] ?? 0) / max(1, count($letters));
        $token_coverage = (float) ($alignment['matched_tokens'] ?? 0) / max(1, count($tokens));
        $strong_alignment = (float) ($alignment['avg_score'] ?? 0) >= 0.65
            && $letter_coverage >= 0.8
            && $token_coverage >= 0.75;
        if (!$strong_alignment) {
            $last_letter = (string) end($letters);
            $last_token = (string) end($tokens);
            $final_similarity = ($last_letter !== '' && $last_token !== '' && function_exists('ll_tools_word_grid_similarity_score'))
                ? (float) ll_tools_word_grid_similarity_score($last_letter, $last_token, $mode)
                : 0.0;
            $near_complete_alignment = (int) ($alignment['matched_letters'] ?? 0) >= max(1, count($letters) - 1)
                && (int) ($alignment['matched_tokens'] ?? 0) >= max(1, count($tokens) - 1);
            if (!$near_complete_alignment || $final_similarity < 0.5) {
                continue;
            }
        }

        $word_id = (int) wp_get_post_parent_id($recording_id);
        if ($word_id <= 0) {
            continue;
        }

        $rows[] = [
            'recording_id' => $recording_id,
            'word_id' => $word_id,
            'recording_text' => $recording_text,
            'recording_ipa' => $recording_ipa,
            'letters' => $letters,
            'tokens' => $tokens,
            'alignment' => $alignment,
            'word_info' => (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => '']),
        ];
    }

    return $rows;
}

function ll_tools_ipa_orthography_collect_rule_stats(int $wordset_id, array $training_rows): array {
    $wordset_language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $stats = [];

    foreach ($training_rows as $row) {
        $letters = array_values((array) ($row['letters'] ?? []));
        $tokens = array_values((array) ($row['tokens'] ?? []));
        $matches = array_values((array) ($row['alignment']['matches'] ?? []));
        if (empty($letters) || empty($tokens) || empty($matches)) {
            continue;
        }

        $full_coverage = ((int) ($row['alignment']['matched_letters'] ?? 0) === count($letters))
            && ((int) ($row['alignment']['matched_tokens'] ?? 0) === count($tokens));
        $letter_offset = 0;
        $token_offset = 0;

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $segment = ll_tools_ipa_orthography_normalize_segment_key((string) ($match['ipa'] ?? ''), 'ipa');
            $output = ll_tools_ipa_orthography_normalize_word_text((string) ($match['text'] ?? ''), $wordset_language);
            $text_len = max(1, (int) ($match['text_len'] ?? 1));
            $token_len = max(1, (int) ($match['token_len'] ?? 1));
            if ($segment === '' || $output === '') {
                $letter_offset += $text_len;
                $token_offset += $token_len;
                continue;
            }

            $sample = ll_tools_ipa_orthography_build_rule_sample($row);
            ll_tools_ipa_orthography_record_rule_observation($stats, $segment, 'any', $output, $sample);

            if ($full_coverage) {
                $context_key = (($token_offset + $token_len) === count($tokens) && ($letter_offset + $text_len) === count($letters))
                    ? 'final'
                    : 'nonfinal';
                ll_tools_ipa_orthography_record_rule_observation($stats, $segment, $context_key, $output, $sample);
            }

            $letter_offset += $text_len;
            $token_offset += $token_len;
        }

        $sample = ll_tools_ipa_orthography_build_rule_sample($row);
        $last_token = (string) end($tokens);
        $last_letter = (string) end($letters);
        $final_segment = ll_tools_ipa_orthography_normalize_segment_key($last_token, 'ipa');
        $final_output = ll_tools_ipa_orthography_normalize_word_text($last_letter, $wordset_language);
        if ($final_segment !== '' && $final_output !== '') {
            $final_score = function_exists('ll_tools_word_grid_similarity_score')
                ? ll_tools_word_grid_similarity_score($last_letter, $last_token, 'ipa')
                : 0.0;
            if ($final_score >= 0.5) {
                ll_tools_ipa_orthography_record_rule_observation($stats, $final_segment, 'final', $final_output, $sample);
            }
        }

        $best_final = [
            'segment' => '',
            'output' => '',
            'score' => 0.0,
            'token_length' => 99,
            'text_length' => 99,
        ];

        for ($token_length = 1; $token_length <= min(2, count($tokens)); $token_length++) {
            $token_segment = implode('', array_slice($tokens, -$token_length));
            for ($text_length = 1; $text_length <= min(2, count($letters)); $text_length++) {
                $text_segment = implode('', array_slice($letters, -$text_length));
                $score = function_exists('ll_tools_word_grid_similarity_score')
                    ? ll_tools_word_grid_similarity_score($text_segment, $token_segment, 'ipa')
                    : 0.0;
                if ($score < 0.5) {
                    continue;
                }

                $candidate_span = $token_length + $text_length;
                $best_span = (int) $best_final['token_length'] + (int) $best_final['text_length'];
                if ($candidate_span < $best_span
                    || ($candidate_span === $best_span && $score > (float) $best_final['score'])) {
                    $best_final = [
                        'segment' => ll_tools_ipa_orthography_normalize_segment_key($token_segment, 'ipa'),
                        'output' => ll_tools_ipa_orthography_normalize_word_text($text_segment, $wordset_language),
                        'score' => $score,
                        'token_length' => $token_length,
                        'text_length' => $text_length,
                    ];
                }
            }
        }

        if ((string) ($best_final['segment'] ?? '') !== '' && (string) ($best_final['output'] ?? '') !== '') {
            ll_tools_ipa_orthography_record_rule_observation(
                $stats,
                (string) $best_final['segment'],
                'final',
                (string) $best_final['output'],
                $sample
            );
        }
    }

    return $stats;
}

function ll_tools_ipa_orthography_pick_best_output(array $counts): array {
    $best_output = '';
    $best_count = 0;
    $total = 0;

    foreach ($counts as $output => $count) {
        $count = max(0, (int) $count);
        $total += $count;
        if ($count > $best_count || ($count === $best_count && $best_output !== '' && strcmp((string) $output, $best_output) < 0)) {
            $best_output = (string) $output;
            $best_count = $count;
        } elseif ($best_output === '') {
            $best_output = (string) $output;
            $best_count = $count;
        }
    }

    return [
        'output' => $best_output,
        'count' => $best_count,
        'total' => $total,
    ];
}

function ll_tools_ipa_orthography_build_auto_rules_from_stats(array $stats): array {
    $rules = [];

    foreach ($stats as $segment => $contexts) {
        if (!is_array($contexts)) {
            continue;
        }

        $any = ll_tools_ipa_orthography_pick_best_output((array) (($contexts['any']['counts'] ?? [])));
        if (($any['output'] ?? '') === '') {
            continue;
        }

        $final = ll_tools_ipa_orthography_pick_best_output((array) (($contexts['final']['counts'] ?? [])));
        $nonfinal = ll_tools_ipa_orthography_pick_best_output((array) (($contexts['nonfinal']['counts'] ?? [])));
        $use_split = (($final['output'] ?? '') !== '')
            && (($nonfinal['output'] ?? '') !== '')
            && ($final['output'] !== $nonfinal['output']);

        $rules[$segment] = [];
        if ($use_split) {
            foreach (['final' => $final, 'nonfinal' => $nonfinal] as $context_key => $best) {
                $output = (string) ($best['output'] ?? '');
                $output = ll_tools_ipa_orthography_apply_surface_trill_policy((string) $segment, $output);
                if ($output === '') {
                    continue;
                }
                $rules[$segment][] = [
                    'segment' => (string) $segment,
                    'context' => $context_key,
                    'output' => $output,
                    'count' => (int) ($best['count'] ?? 0),
                    'samples' => array_values((array) ($contexts[$context_key]['samples'][$output] ?? [])),
                ];
            }
        } else {
            $output = (string) ($any['output'] ?? '');
            $output = ll_tools_ipa_orthography_apply_surface_trill_policy((string) $segment, $output);
            $rules[$segment][] = [
                'segment' => (string) $segment,
                'context' => 'any',
                'output' => $output,
                'count' => (int) ($any['count'] ?? 0),
                'samples' => array_values((array) ($contexts['any']['samples'][$output] ?? [])),
            ];
        }

        usort($rules[$segment], static function (array $left, array $right): int {
            $context_order = ['any' => 0, 'final' => 1, 'nonfinal' => 2];
            $left_context = (string) ($left['context'] ?? 'any');
            $right_context = (string) ($right['context'] ?? 'any');
            $left_rank = $context_order[$left_context] ?? 99;
            $right_rank = $context_order[$right_context] ?? 99;
            if ($left_rank !== $right_rank) {
                return $left_rank <=> $right_rank;
            }
            return strcmp((string) ($left['output'] ?? ''), (string) ($right['output'] ?? ''));
        });
    }

    uasort($rules, static function (array $left, array $right): int {
        $left_segment = (string) (($left[0]['segment'] ?? ''));
        $right_segment = (string) (($right[0]['segment'] ?? ''));
        return ll_tools_locale_compare_strings($left_segment, $right_segment);
    });

    return $rules;
}

function ll_tools_ipa_orthography_filter_auto_rules(array $auto_rules, array $blocklist, int $wordset_id = 0): array {
    $filtered = [];
    foreach ($auto_rules as $segment => $entries) {
        if (!is_array($entries)) {
            continue;
        }

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $context = ll_tools_ipa_orthography_normalize_context((string) ($entry['context'] ?? 'any'));
            $output = (string) ($entry['output'] ?? '');
            if ($output === '') {
                continue;
            }

            if (ll_tools_ipa_orthography_get_profile_key($wordset_id) === 'zazaki_genc_palu'
                && in_array((string) $segment, ['ɨ', 'ɪ'], true)
                && in_array($context, ['any', 'final'], true)) {
                continue;
            }

            $blocked_outputs = array_values((array) ($blocklist[$segment][$context] ?? []));
            if (in_array($output, $blocked_outputs, true)) {
                continue;
            }

            if (!isset($filtered[$segment])) {
                $filtered[$segment] = [];
            }
            $filtered[$segment][] = $entry;
        }
    }

    return $filtered;
}

function ll_tools_ipa_orthography_build_rule_payload(array $raw_auto_rules, array $visible_auto_rules, array $manual_rules, array $blocklist): array {
    $segments = array_values(array_unique(array_merge(
        array_map('strval', array_keys($raw_auto_rules)),
        array_map('strval', array_keys($manual_rules)),
        array_map('strval', array_keys($blocklist))
    )));
    usort($segments, 'll_tools_locale_compare_strings');

    $rows = [];
    foreach ($segments as $segment) {
        $blocked = [];
        foreach ((array) ($raw_auto_rules[$segment] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $context = ll_tools_ipa_orthography_normalize_context((string) ($entry['context'] ?? 'any'));
            $output = (string) ($entry['output'] ?? '');
            if ($output === '' || !in_array($output, (array) ($blocklist[$segment][$context] ?? []), true)) {
                continue;
            }
            $blocked[] = $entry;
        }

        $rows[] = [
            'segment' => $segment,
            'auto' => array_values((array) ($visible_auto_rules[$segment] ?? [])),
            'manual' => [
                'any' => (string) (($manual_rules[$segment]['any'] ?? '')),
                'final' => (string) (($manual_rules[$segment]['final'] ?? '')),
                'nonfinal' => (string) (($manual_rules[$segment]['nonfinal'] ?? '')),
            ],
            'blocked' => array_values($blocked),
        ];
    }

    return $rows;
}

function ll_tools_ipa_orthography_context_matches(string $context, int $start_index, int $end_index, int $token_count): bool {
    $context = ll_tools_ipa_orthography_normalize_context($context);
    if ($context === 'final') {
        return $end_index === $token_count;
    }
    if ($context === 'nonfinal') {
        return $end_index < $token_count;
    }
    return true;
}

function ll_tools_ipa_orthography_prepare_engine_rules(array $auto_rules, array $manual_rules, int $wordset_id): array {
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $rules = [];
    $append_rule = static function (string $segment, string $context, string $output, bool $manual, int $count = 1) use (&$rules, $mode): void {
        $tokens = ll_tools_ipa_orthography_tokenize_segment($segment, $mode);
        $output = ll_tools_ipa_orthography_apply_surface_trill_policy($segment, $output);
        if (empty($tokens) || $output === '') {
            return;
        }

        $priority = $manual ? 30000 : 10000;
        if ($context === 'final' || $context === 'nonfinal') {
            $priority += $manual ? 10000 : 10000;
        }
        $priority += min(999, max(1, $count));
        $priority += count($tokens) * 100;

        $rules[] = [
            'segment' => $segment,
            'tokens' => $tokens,
            'token_length' => count($tokens),
            'context' => ll_tools_ipa_orthography_normalize_context($context),
            'output' => $output,
            'manual' => $manual,
            'priority' => $priority,
        ];
    };

    foreach ($auto_rules as $segment => $entries) {
        foreach ((array) $entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $append_rule(
                (string) $segment,
                (string) ($entry['context'] ?? 'any'),
                (string) ($entry['output'] ?? ''),
                false,
                (int) ($entry['count'] ?? 1)
            );
        }
    }
    foreach ($manual_rules as $segment => $contexts) {
        foreach ((array) $contexts as $context => $output) {
            $append_rule((string) $segment, (string) $context, (string) $output, true, 1);
        }
    }

    usort($rules, static function (array $left, array $right): int {
        $priority_compare = (int) ($right['priority'] ?? 0) <=> (int) ($left['priority'] ?? 0);
        if ($priority_compare !== 0) {
            return $priority_compare;
        }
        return strcmp((string) ($left['segment'] ?? ''), (string) ($right['segment'] ?? ''));
    });

    return $rules;
}

function ll_tools_ipa_orthography_word_override_applies_to_word(string $from_key, array $settings, int $word_id): bool {
    $word_ids = (array) ($settings['word_override_word_ids'] ?? []);
    $required_word_id = (int) ($word_ids[$from_key] ?? 0);
    if ($required_word_id > 0) {
        return $word_id > 0 && $word_id === $required_word_id;
    }

    $entry_ids = (array) ($settings['word_override_entry_ids'] ?? []);
    $required_entry_id = (int) ($entry_ids[$from_key] ?? 0);
    if ($required_entry_id <= 0) {
        return true;
    }

    $word_entry_id = ll_tools_ipa_orthography_get_word_dictionary_entry_id($word_id);
    return $word_entry_id > 0 && $word_entry_id === $required_entry_id;
}

function ll_tools_ipa_orthography_apply_word_overrides_to_text(string $text, int $wordset_id, int $word_id = 0): string {
    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $word_overrides = (array) ($settings['word_overrides'] ?? []);
    if ($text === '' || empty($word_overrides)) {
        return $text;
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    if (ll_tools_ipa_orthography_is_single_space_token_text($text)) {
        $tokens = ll_tools_ipa_orthography_split_nonspace_tokens($text);
        foreach ($tokens as $index => $token) {
            $key = ll_tools_ipa_orthography_profile_compare_key((string) $token, $language);
            if ($key === '' || !isset($word_overrides[$key])) {
                continue;
            }
            if (!ll_tools_ipa_orthography_word_override_applies_to_word($key, $settings, $word_id)) {
                continue;
            }
            $tokens[$index] = (string) $word_overrides[$key];
        }
        return implode(' ', $tokens);
    }

    $parts = ll_tools_ipa_orthography_split_nonspace_spans($text);
    for ($index = count($parts) - 1; $index >= 0; $index--) {
        $part = (array) $parts[$index];
        $key = ll_tools_ipa_orthography_profile_compare_key((string) ($part['text'] ?? ''), $language);
        if ($key === '' || !isset($word_overrides[$key])) {
            continue;
        }
        if (!ll_tools_ipa_orthography_word_override_applies_to_word($key, $settings, $word_id)) {
            continue;
        }

        $text = ll_tools_ipa_orthography_replace_char_span(
            $text,
            (int) ($part['start'] ?? 0),
            (int) ($part['length'] ?? 0),
            (string) $word_overrides[$key]
        );
    }

    return $text;
}

function ll_tools_ipa_orthography_apply_entry_bound_word_overrides_to_text(string $text, int $wordset_id, int $word_id = 0): array {
    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $word_overrides = (array) ($settings['word_overrides'] ?? []);
    $entry_ids = (array) ($settings['word_override_entry_ids'] ?? []);
    if ($text === '' || empty($word_overrides) || empty($entry_ids)) {
        return [
            'text' => $text,
            'applied_count' => 0,
        ];
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    if (ll_tools_ipa_orthography_is_single_space_token_text($text)) {
        $tokens = ll_tools_ipa_orthography_split_nonspace_tokens($text);
        $applied_count = 0;
        foreach ($tokens as $index => $token) {
            $key = ll_tools_ipa_orthography_profile_compare_key((string) $token, $language);
            if ($key === '' || !isset($word_overrides[$key]) || empty($entry_ids[$key])) {
                continue;
            }
            if (!ll_tools_ipa_orthography_word_override_applies_to_word($key, $settings, $word_id)) {
                continue;
            }
            $tokens[$index] = (string) $word_overrides[$key];
            $applied_count++;
        }

        return [
            'text' => implode(' ', $tokens),
            'applied_count' => $applied_count,
        ];
    }

    $parts = ll_tools_ipa_orthography_split_nonspace_spans($text);
    $applied_count = 0;
    for ($index = count($parts) - 1; $index >= 0; $index--) {
        $part = (array) $parts[$index];
        $key = ll_tools_ipa_orthography_profile_compare_key((string) ($part['text'] ?? ''), $language);
        if ($key === '' || !isset($word_overrides[$key]) || empty($entry_ids[$key])) {
            continue;
        }
        if (!ll_tools_ipa_orthography_word_override_applies_to_word($key, $settings, $word_id)) {
            continue;
        }

        $text = ll_tools_ipa_orthography_replace_char_span(
            $text,
            (int) ($part['start'] ?? 0),
            (int) ($part['length'] ?? 0),
            (string) $word_overrides[$key]
        );
        $applied_count++;
    }

    return [
        'text' => $text,
        'applied_count' => $applied_count,
    ];
}

function ll_tools_ipa_orthography_apply_phrase_overrides_to_text(string $text, int $wordset_id): string {
    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $phrase_overrides = (array) ($settings['phrase_overrides'] ?? []);
    if ($text === '' || empty($phrase_overrides)) {
        return $text;
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $tokens = ll_tools_ipa_orthography_split_nonspace_tokens($text);
    if (empty($tokens)) {
        return $text;
    }

    $keys = array_map(static function (string $token) use ($language): string {
        return ll_tools_ipa_orthography_profile_compare_key($token, $language);
    }, $tokens);

    $out = [];
    $count = count($tokens);
    for ($index = 0; $index < $count;) {
        $matched = false;
        foreach ($phrase_overrides as $override) {
            $from_key = array_values((array) ($override['from_key'] ?? []));
            $length = count($from_key);
            if ($length <= 0 || $index + $length > $count) {
                continue;
            }
            if (array_values(array_slice($keys, $index, $length)) !== $from_key) {
                continue;
            }

            foreach ((array) ($override['to'] ?? []) as $replacement_token) {
                $replacement_token = (string) $replacement_token;
                if ($replacement_token !== '') {
                    $out[] = $replacement_token;
                }
            }
            $index += $length;
            $matched = true;
            break;
        }

        if ($matched) {
            continue;
        }

        $out[] = (string) ($tokens[$index] ?? '');
        $index++;
    }

    return implode(' ', $out);
}

function ll_tools_ipa_orthography_apply_non_word_settings_to_text(string $text, int $wordset_id, string $recording_type = ''): string {
    if ($text === '') {
        return '';
    }

    $settings = ll_tools_ipa_orthography_get_settings($wordset_id);
    $text = ll_tools_ipa_orthography_apply_phrase_overrides_to_text($text, $wordset_id);

    if (!empty($settings['sentence_case'])) {
        $text = ll_tools_ipa_orthography_profile_sentence_case(
            $text,
            ll_tools_ipa_orthography_get_wordset_language($wordset_id)
        );
    }

    $punctuation_map = (array) ($settings['recording_type_punctuation'] ?? []);
    $punctuation = $recording_type !== '' ? (string) ($punctuation_map[$recording_type] ?? '') : '';
    if ($recording_type !== '' && $punctuation !== '') {
        $text = ll_tools_ipa_orthography_profile_strip_terminal_punctuation($text) . $punctuation;
    }

    return $text;
}

function ll_tools_ipa_orthography_apply_settings_to_text(string $text, int $wordset_id, string $recording_type = '', int $word_id = 0): string {
    if ($text === '') {
        return '';
    }

    $text = ll_tools_ipa_orthography_apply_profile_output_replacements($text, $wordset_id);
    $text = ll_tools_ipa_orthography_apply_word_overrides_to_text($text, $wordset_id, $word_id);
    return ll_tools_ipa_orthography_apply_non_word_settings_to_text($text, $wordset_id, $recording_type);
}

function ll_tools_ipa_orthography_index_engine_rules_by_first_token(array $rules, array $index = [], int $rule_offset = 0): array {
    foreach (array_values($rules) as $local_rule_index => $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $tokens = array_values(array_map('strval', (array) ($rule['tokens'] ?? [])));
        $first_token = (string) ($tokens[0] ?? '');
        $token_length = max(0, (int) ($rule['token_length'] ?? count($tokens)));
        if ($first_token === '') {
            continue;
        }
        if (!isset($index[$first_token])) {
            $index[$first_token] = [];
        }
        if (!isset($index[$first_token][$token_length])) {
            $index[$first_token][$token_length] = [];
        }
        $token_key = implode("\u{0000}", $tokens);
        if ($token_key === '') {
            continue;
        }
        if (!isset($index[$first_token][$token_length][$token_key])) {
            $index[$first_token][$token_length][$token_key] = [];
        }
        $index[$first_token][$token_length][$token_key][] = $rule_offset + (int) $local_rule_index;
    }

    return $index;
}

function ll_tools_ipa_orthography_get_engine_rules_index_for_wordset(int $wordset_id, array $rules): array {
    if ($wordset_id <= 0) {
        return ll_tools_ipa_orthography_index_engine_rules_by_first_token($rules);
    }

    $cache_key = ll_tools_ipa_orthography_engine_rules_index_cache_key($wordset_id);
    $runtime_cache = ll_tools_ipa_orthography_get_engine_rules_index_runtime_cache();
    if (isset($runtime_cache[$cache_key]) && is_array($runtime_cache[$cache_key])) {
        return $runtime_cache[$cache_key];
    }

    $persisted = get_transient($cache_key);
    if (is_array($persisted)) {
        ll_tools_ipa_orthography_set_engine_rules_index_runtime_cache($cache_key, $persisted);
        return $persisted;
    }

    $index = ll_tools_ipa_orthography_index_engine_rules_by_first_token($rules);
    ll_tools_ipa_orthography_set_engine_rules_index_runtime_cache($cache_key, $index);
    set_transient($cache_key, $index, ll_tools_ipa_orthography_engine_rules_cache_ttl());
    return $index;
}

function ll_tools_ipa_orthography_convert_ipa_tokens_to_text(array $tokens, array $rules, ?array $rules_by_first_token = null): array {
    if (empty($tokens)) {
        return [
            'text' => '',
            'complete' => false,
            'matched_tokens' => 0,
            'token_count' => 0,
        ];
    }

    $token_count = count($tokens);
    $dp = array_fill(0, $token_count + 1, null);
    $dp[0] = [
        'score' => 0,
        'text' => '',
    ];
    $furthest = 0;
    $rules_by_first_token = is_array($rules_by_first_token)
        ? $rules_by_first_token
        : ll_tools_ipa_orthography_index_engine_rules_by_first_token($rules);

    for ($index = 0; $index < $token_count; $index++) {
        if ($dp[$index] === null) {
            continue;
        }
        if ($index > $furthest) {
            $furthest = $index;
        }

        $first_token = (string) ($tokens[$index] ?? '');
        $rules_by_length = (array) ($rules_by_first_token[$first_token] ?? []);
        foreach ($rules_by_length as $length => $rules_by_token_key) {
            $length = (int) $length;
            $end = $index + $length;
            if ($length <= 0 || $end > $token_count || !is_array($rules_by_token_key)) {
                continue;
            }
            $token_key = implode("\u{0000}", array_slice($tokens, $index, $length));
            $matching_rule_indexes = (array) ($rules_by_token_key[$token_key] ?? []);
            if (empty($matching_rule_indexes)) {
                continue;
            }
            foreach ($matching_rule_indexes as $rule_index) {
                $rule = (array) ($rules[(int) $rule_index] ?? []);
                if (empty($rule)) {
                    continue;
                }
                if (!ll_tools_ipa_orthography_context_matches((string) ($rule['context'] ?? 'any'), $index, $end, $token_count)) {
                    continue;
                }

                $candidate_score = (int) ($dp[$index]['score'] ?? 0) + (int) ($rule['priority'] ?? 0);
                $candidate_text = (string) ($dp[$index]['text'] ?? '') . (string) ($rule['output'] ?? '');
                if ($dp[$end] === null || $candidate_score > (int) ($dp[$end]['score'] ?? 0)) {
                    $dp[$end] = [
                        'score' => $candidate_score,
                        'text' => $candidate_text,
                    ];
                }
            }
        }
    }

    if ($dp[$token_count] !== null) {
        return [
            'text' => (string) ($dp[$token_count]['text'] ?? ''),
            'complete' => true,
            'matched_tokens' => $token_count,
            'token_count' => $token_count,
            'score' => (int) ($dp[$token_count]['score'] ?? 0),
        ];
    }

    return [
        'text' => '',
        'complete' => false,
        'matched_tokens' => $furthest,
        'token_count' => $token_count,
        'score' => 0,
    ];
}

function ll_tools_ipa_orthography_expand_single_post_modifier_token(string $token, string $mode = 'ipa'): array {
    if ($mode !== 'ipa' || !function_exists('ll_tools_word_grid_is_ipa_post_modifier')) {
        return [$token];
    }

    $chars = ll_tools_ipa_orthography_chars($token);
    $char_count = count($chars);
    if ($char_count < 2) {
        return [$token];
    }

    $split_at = $char_count;
    while ($split_at > 0 && ll_tools_word_grid_is_ipa_post_modifier((string) $chars[$split_at - 1], $mode)) {
        $split_at--;
    }

    if ($split_at <= 0 || $split_at === $char_count) {
        return [$token];
    }

    $expanded = [];
    $base = implode('', array_slice($chars, 0, $split_at));
    if ($base !== '') {
        $expanded[] = $base;
    }
    foreach (array_slice($chars, $split_at) as $modifier) {
        $expanded[] = (string) $modifier;
    }

    return $expanded;
}

function ll_tools_ipa_orthography_expand_post_modifier_tokens(array $tokens, string $mode = 'ipa'): array {
    $expanded = [];
    foreach ($tokens as $token) {
        foreach (ll_tools_ipa_orthography_expand_single_post_modifier_token((string) $token, $mode) as $expanded_token) {
            $expanded[] = $expanded_token;
        }
    }

    return $expanded;
}

function ll_tools_ipa_orthography_post_modifier_token_variants(array $tokens, string $mode = 'ipa'): array {
    $tokens = array_values(array_map('strval', $tokens));
    if ($mode !== 'ipa' || empty($tokens)) {
        return [$tokens];
    }

    $variants = [[]];
    $max_variants = 32;
    foreach ($tokens as $token) {
        $choices = [[$token]];
        $expanded = ll_tools_ipa_orthography_expand_single_post_modifier_token($token, $mode);
        if (array_values($expanded) !== [$token]) {
            $choices[] = $expanded;
        }

        $next = [];
        foreach ($variants as $variant) {
            foreach ($choices as $choice) {
                $next[] = array_merge($variant, $choice);
                if (count($next) >= $max_variants) {
                    break 2;
                }
            }
        }
        $variants = $next;
    }

    $unique = [];
    $seen = [];
    foreach ($variants as $variant) {
        $key = implode("\u{0000}", $variant);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $variant;
    }

    return $unique;
}

function ll_tools_ipa_orthography_with_final_high_vowel_candidate_rules(array $rules): array {
    $fallback_rules = $rules;
    foreach (['ɨ', 'ɪ'] as $segment) {
        $fallback_rules[] = [
            'segment' => $segment,
            'tokens' => [$segment],
            'token_length' => 1,
            'context' => 'final',
            'output' => 'ı',
            'manual' => true,
            'priority' => 60000,
        ];
    }
    return $fallback_rules;
}

function ll_tools_ipa_orthography_convert_ipa_to_text(
    string $ipa_text,
    array $rules,
    int $wordset_id,
    string $recording_type = '',
    int $word_id = 0,
    ?array $rules_by_first_token = null,
    &$profile = null
): array {
    $convert_started = microtime(true);
    $step_started = $convert_started;
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_mode_seconds', microtime(true) - $step_started);
    $step_started = microtime(true);
    $ipa_parts = ll_tools_ipa_orthography_split_nonspace_tokens($ipa_text);
    ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_split_seconds', microtime(true) - $step_started);
    if (empty($ipa_parts)) {
        ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_total_seconds', microtime(true) - $convert_started);
        return [
            'text' => '',
            'complete' => false,
            'matched_tokens' => 0,
            'token_count' => 0,
        ];
    }

    $words = [];
    $matched_tokens = 0;
    $token_count = 0;
    $final_high_vowel_candidate_count = 0;
    $fallback_rules = null;
    $step_started = microtime(true);
    $rules_by_first_token = is_array($rules_by_first_token)
        ? $rules_by_first_token
        : ll_tools_ipa_orthography_index_engine_rules_by_first_token($rules);
    ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_index_seconds', microtime(true) - $step_started);
    $fallback_rules_by_first_token = null;
    $loop_started = microtime(true);
    foreach ($ipa_parts as $part) {
        $step_started = microtime(true);
        $tokens = ll_tools_ipa_orthography_tokenize_segment((string) $part, $mode);
        $tokens = ll_tools_ipa_orthography_filter_profile_tokens($tokens, $wordset_id);
        ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_tokenize_filter_seconds', microtime(true) - $step_started);
        if (empty($tokens)) {
            continue;
        }

        $conversion_tokens = $tokens;
        $step_started = microtime(true);
        $prediction = ll_tools_ipa_orthography_convert_ipa_tokens_to_text($conversion_tokens, $rules, $rules_by_first_token);
        ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_dp_seconds', microtime(true) - $step_started);
        $step_started = microtime(true);
        foreach (ll_tools_ipa_orthography_post_modifier_token_variants($tokens, $mode) as $expanded_tokens) {
            if (array_values($expanded_tokens) === array_values($tokens)) {
                continue;
            }

            $expanded_prediction = ll_tools_ipa_orthography_convert_ipa_tokens_to_text($expanded_tokens, $rules, $rules_by_first_token);
            if (!empty($expanded_prediction['complete']) && (string) ($expanded_prediction['text'] ?? '') !== '') {
                if (empty($prediction['complete'])
                    || (int) ($expanded_prediction['score'] ?? 0) > (int) ($prediction['score'] ?? 0)) {
                    $conversion_tokens = $expanded_tokens;
                    $prediction = $expanded_prediction;
                }
                continue;
            }

            if (empty($prediction['complete'])
                && (int) ($expanded_prediction['matched_tokens'] ?? 0) > (int) ($prediction['matched_tokens'] ?? 0)) {
                $conversion_tokens = $expanded_tokens;
                $prediction = $expanded_prediction;
            }
        }
        ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_post_modifier_seconds', microtime(true) - $step_started);
        if (empty($prediction['complete']) || (string) ($prediction['text'] ?? '') === '') {
            $step_started = microtime(true);
            if ($fallback_rules === null) {
                $fallback_rules = ll_tools_ipa_orthography_with_final_high_vowel_candidate_rules($rules);
                $fallback_candidate_rules = array_slice($fallback_rules, count($rules));
                $fallback_rules_by_first_token = ll_tools_ipa_orthography_index_engine_rules_by_first_token(
                    $fallback_candidate_rules,
                    $rules_by_first_token,
                    count($rules)
                );
            }
            $fallback_prediction = ll_tools_ipa_orthography_convert_ipa_tokens_to_text($conversion_tokens, $fallback_rules, $fallback_rules_by_first_token);
            ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_fallback_seconds', microtime(true) - $step_started);
            if (empty($fallback_prediction['complete']) || (string) ($fallback_prediction['text'] ?? '') === '') {
                $matched_tokens += (int) ($prediction['matched_tokens'] ?? 0);
                $token_count += (int) ($prediction['token_count'] ?? 0);
                ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_loop_seconds', microtime(true) - $loop_started);
                ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_total_seconds', microtime(true) - $convert_started);
                return [
                    'text' => '',
                    'complete' => false,
                    'matched_tokens' => $matched_tokens,
                    'token_count' => $token_count,
                ];
            }
            $prediction = $fallback_prediction;
            $final_high_vowel_candidate_count++;
        }

        $matched_tokens += (int) ($prediction['matched_tokens'] ?? 0);
        $token_count += (int) ($prediction['token_count'] ?? 0);
        $words[] = (string) ($prediction['text'] ?? '');
    }
    ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_loop_seconds', microtime(true) - $loop_started);

    $step_started = microtime(true);
    $raw_text = ll_tools_ipa_orthography_apply_profile_output_replacements(implode(' ', $words), $wordset_id);
    ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_output_replacements_seconds', microtime(true) - $step_started);
    $requires_lexical_decision = $final_high_vowel_candidate_count > 0;
    if ($requires_lexical_decision) {
        $step_started = microtime(true);
        $entry_bound = ll_tools_ipa_orthography_apply_entry_bound_word_overrides_to_text($raw_text, $wordset_id, $word_id);
        $word_override_text = ll_tools_ipa_orthography_apply_word_overrides_to_text(
            (string) ($entry_bound['text'] ?? $raw_text),
            $wordset_id,
            $word_id
        );
        $text = ll_tools_ipa_orthography_apply_non_word_settings_to_text(
            $word_override_text,
            $wordset_id,
            $recording_type
        );
        ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_settings_seconds', microtime(true) - $step_started);
        $requires_lexical_decision = (int) ($entry_bound['applied_count'] ?? 0) < $final_high_vowel_candidate_count;
    } else {
        $step_started = microtime(true);
        $text = ll_tools_ipa_orthography_apply_settings_to_text($raw_text, $wordset_id, $recording_type, $word_id);
        ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_settings_seconds', microtime(true) - $step_started);
    }
    ll_tools_ipa_orthography_profile_add_seconds($profile, 'convert_total_seconds', microtime(true) - $convert_started);

    return [
        'text' => $text,
        'raw_text' => $raw_text,
        'complete' => $text !== '' && $token_count > 0,
        'matched_tokens' => $matched_tokens,
        'token_count' => $token_count,
        'requires_lexical_decision' => $requires_lexical_decision,
        'final_high_vowel_candidate_count' => $final_high_vowel_candidate_count,
        'word_id' => $word_id,
    ];
}

function ll_tools_ipa_orthography_get_recording_type_slug(int $recording_id): string {
    if ($recording_id <= 0) {
        return '';
    }
    if (function_exists('ll_tools_word_grid_get_primary_recording_type_slug')) {
        return (string) ll_tools_word_grid_get_primary_recording_type_slug($recording_id);
    }

    $terms = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
        return '';
    }

    return (string) $terms[0];
}

function ll_tools_ipa_orthography_convert_ipa_to_best_text(
    string $ipa_text,
    array $rules,
    int $wordset_id,
    int $recording_id = 0,
    &$profile = null
): array {
    $recording_type = ll_tools_ipa_orthography_get_recording_type_slug($recording_id);
    $word_id = $recording_id > 0 ? (int) wp_get_post_parent_id($recording_id) : 0;
    $prediction = ll_tools_ipa_orthography_convert_ipa_to_text(
        $ipa_text,
        $rules,
        $wordset_id,
        $recording_type,
        $word_id,
        ll_tools_ipa_orthography_get_engine_rules_index_for_wordset($wordset_id, $rules),
        $profile
    );
    $prediction['source'] = 'rules';
    $prediction['settings'] = ll_tools_ipa_orthography_get_settings($wordset_id);
    $prediction['profile'] = [];
    $prediction['recording_id'] = $recording_id;
    $prediction['word_id'] = $word_id;
    return $prediction;
}

function ll_tools_ipa_orthography_build_recording_payload_for_wordset(int $recording_id, int $wordset_id, array $word_info): array {
    return ll_tools_ipa_keyboard_build_recording_payload(
        $recording_id,
        (int) wp_get_post_parent_id($recording_id),
        $word_info,
        ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), 'ipa'),
        $wordset_id
    );
}

function ll_tools_ipa_orthography_build_profile_contradiction_rows(int $wordset_id, array $exception_word_ids): array {
    return [];
}

function ll_tools_ipa_orthography_build_recording_contradiction_rows(
    int $wordset_id,
    array $engine_rules,
    array $exception_word_ids,
    array $exception_dictionary_entry_ids = []
): array {
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [];
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
        return [];
    }

    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $word_display = ll_tools_ipa_keyboard_get_word_display_map($word_ids);
    $rows = [];

    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        $word_id = (int) wp_get_post_parent_id($recording_id);
        if ($word_id <= 0) {
            continue;
        }

        $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
            ? ll_tools_word_grid_sanitize_non_ipa_text((string) get_post_meta($recording_id, 'recording_text', true))
            : sanitize_text_field((string) get_post_meta($recording_id, 'recording_text', true));
        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), 'ipa');
        if ($recording_text === '' || $recording_ipa === '') {
            continue;
        }

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text($recording_ipa, $engine_rules, $wordset_id, $recording_id);
        $predicted_text = (string) ($prediction['text'] ?? '');
        if (empty($prediction['complete']) || $predicted_text === '') {
            continue;
        }

        $actual_key = ll_tools_ipa_orthography_profile_compare_key($recording_text, $language);
        $predicted_key = ll_tools_ipa_orthography_profile_compare_key($predicted_text, $language);
        if (empty($prediction['requires_lexical_decision']) && $predicted_key !== '' && $predicted_key === $actual_key) {
            continue;
        }

        $mismatch_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            $recording_text,
            $recording_ipa,
            $wordset_id,
            ll_tools_ipa_orthography_get_recording_type_slug($recording_id),
            $prediction
        );
        if (!empty($mismatch_detail['matches'])) {
            continue;
        }

        if (!isset($rows[$word_id])) {
            $payload = ll_tools_ipa_orthography_build_recording_payload_for_wordset(
                $recording_id,
                $wordset_id,
                (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => ''])
            );
            $payload['predicted_text'] = (string) (($mismatch_detail['suggested_text'] ?? '') ?: $predicted_text);
            $payload['canonical_predicted_text'] = $predicted_text;
            $payload['orthography_mismatch'] = $mismatch_detail;
            $payload['prediction_source'] = (string) ($prediction['source'] ?? 'rules');
            $payload['prediction_source_label'] = __('Current rules', 'll-tools-text-domain');
            $payload['approved_exception'] = ll_tools_ipa_orthography_exception_applies_to_word(
                $wordset_id,
                $word_id,
                $exception_word_ids,
                $exception_dictionary_entry_ids
            );
            $payload['approved_exception_dictionary_entry_id'] = (int) ($exception_dictionary_entry_ids[$word_id] ?? 0);
            $payload['conflict_count'] = 1;
            $payload['can_convert'] = true;
            $payload['can_apply_suggestion'] = true;
            $rows[$word_id] = $payload;
            continue;
        }

        $rows[$word_id]['conflict_count'] = (int) ($rows[$word_id]['conflict_count'] ?? 1) + 1;
    }

    return array_values($rows);
}

function ll_tools_ipa_orthography_build_contradiction_rows(
    int $wordset_id,
    array $training_rows,
    array $engine_rules,
    array $exception_word_ids,
    array $exception_dictionary_entry_ids = []
): array {
    $language = ll_tools_ipa_orthography_get_wordset_language($wordset_id);
    $rows = [];

    foreach ($training_rows as $row) {
        $word_id = (int) ($row['word_id'] ?? 0);
        $recording_id = (int) ($row['recording_id'] ?? 0);
        if ($word_id <= 0 || $recording_id <= 0) {
            continue;
        }

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            (string) ($row['recording_ipa'] ?? ''),
            $engine_rules,
            $wordset_id,
            $recording_id
        );
        $predicted_text = (string) ($prediction['text'] ?? '');
        $actual_key = ll_tools_ipa_orthography_profile_compare_key((string) ($row['recording_text'] ?? ''), $language);
        $predicted_key = ll_tools_ipa_orthography_profile_compare_key($predicted_text, $language);

        if (empty($prediction['requires_lexical_decision']) && $predicted_key !== '' && $predicted_key === $actual_key) {
            continue;
        }

        $mismatch_detail = [];
        if (!empty($prediction['complete']) && $predicted_text !== '') {
            $mismatch_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
                (string) ($row['recording_text'] ?? ''),
                (string) ($row['recording_ipa'] ?? ''),
                $wordset_id,
                ll_tools_ipa_orthography_get_recording_type_slug($recording_id),
                $prediction
            );
            if (!empty($mismatch_detail['matches'])) {
                continue;
            }
        }

        if (!isset($rows[$word_id])) {
            $payload = ll_tools_ipa_orthography_build_recording_payload_for_wordset(
                $recording_id,
                $wordset_id,
                (array) ($row['word_info'] ?? ['word_text' => '', 'translation' => ''])
            );
            $payload['predicted_text'] = (string) (($mismatch_detail['suggested_text'] ?? '') ?: $predicted_text);
            if (!empty($mismatch_detail)) {
                $payload['orthography_mismatch'] = $mismatch_detail;
            }
            $payload['prediction_source'] = (string) ($prediction['source'] ?? 'rules');
            $payload['prediction_source_label'] = !empty($prediction['profile']['label'])
                ? (string) $prediction['profile']['label']
                : __('Current rules', 'll-tools-text-domain');
            $payload['approved_exception'] = ll_tools_ipa_orthography_exception_applies_to_word(
                $wordset_id,
                $word_id,
                $exception_word_ids,
                $exception_dictionary_entry_ids
            );
            $payload['approved_exception_dictionary_entry_id'] = (int) ($exception_dictionary_entry_ids[$word_id] ?? 0);
            $payload['conflict_count'] = 1;
            $payload['can_convert'] = !empty($prediction['complete']) && $predicted_text !== '';
            $payload['can_apply_suggestion'] = $payload['can_convert'];
            $rows[$word_id] = $payload;
            continue;
        }

        $rows[$word_id]['conflict_count'] = (int) ($rows[$word_id]['conflict_count'] ?? 1) + 1;
    }

    return array_values($rows);
}

function ll_tools_ipa_orthography_get_conversion_source_recording_id(int $word_id): int {
    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent' => $word_id,
        'no_found_rows' => true,
    ]);
    if (empty($recording_ids)) {
        return 0;
    }

    $best_id = 0;
    $best_rank = PHP_INT_MAX;
    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), 'ipa');
        if ($recording_ipa === '') {
            continue;
        }

        $slug = function_exists('ll_tools_word_grid_get_primary_recording_type_slug')
            ? ll_tools_word_grid_get_primary_recording_type_slug($recording_id)
            : '';
        $rank = ($slug === 'isolation') ? 0 : 10;
        if ($rank < $best_rank) {
            $best_rank = $rank;
            $best_id = $recording_id;
        }
    }

    return $best_id;
}

function ll_tools_ipa_orthography_build_conversion_candidates(int $wordset_id, array $engine_rules): array {
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [];
    }

    $word_display = ll_tools_ipa_keyboard_get_word_display_map($word_ids);
    $candidates = [];

    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }

        $display = ll_tools_word_grid_resolve_display_text($word_id);
        if (trim((string) ($display['word_text'] ?? '')) !== '') {
            continue;
        }

        $recording_id = ll_tools_ipa_orthography_get_conversion_source_recording_id($word_id);
        if ($recording_id <= 0) {
            continue;
        }

        $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
            ? ll_tools_word_grid_sanitize_non_ipa_text((string) get_post_meta($recording_id, 'recording_text', true))
            : sanitize_text_field((string) get_post_meta($recording_id, 'recording_text', true));
        if ($recording_text !== '') {
            continue;
        }

        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), 'ipa');
        if ($recording_ipa === '') {
            continue;
        }

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text($recording_ipa, $engine_rules, $wordset_id, $recording_id);
        $payload = ll_tools_ipa_orthography_build_recording_payload_for_wordset(
            $recording_id,
            $wordset_id,
            (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => ''])
        );
        $payload['predicted_text'] = (string) ($prediction['text'] ?? '');
        $payload['prediction_source'] = (string) ($prediction['source'] ?? 'rules');
        $payload['prediction_source_label'] = !empty($prediction['profile']['label'])
            ? (string) $prediction['profile']['label']
            : __('Current rules', 'll-tools-text-domain');
        $payload['can_convert'] = !empty($prediction['complete']) && $payload['predicted_text'] !== '';
        $payload['status'] = $payload['can_convert'] ? 'ready' : 'needs_rules';
        $candidates[] = $payload;
    }

    return $candidates;
}

function ll_tools_ipa_orthography_apply_conversion_to_word(int $wordset_id, int $word_id, array $engine_rules) {
    $word_id = (int) $word_id;
    $wordset_id = (int) $wordset_id;
    if ($word_id <= 0 || $wordset_id <= 0 || !has_term($wordset_id, 'wordset', $word_id)) {
        return new WP_Error('invalid_word', __('Invalid word for this word set.', 'll-tools-text-domain'));
    }

    $display = ll_tools_word_grid_resolve_display_text($word_id);
    if (trim((string) ($display['word_text'] ?? '')) !== '') {
        return new WP_Error('word_has_text', __('This word already has written text.', 'll-tools-text-domain'));
    }

    $recording_id = ll_tools_ipa_orthography_get_conversion_source_recording_id($word_id);
    if ($recording_id <= 0) {
        return new WP_Error('missing_recording', __('No IPA recording is available for this word.', 'll-tools-text-domain'));
    }

    $existing_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text((string) get_post_meta($recording_id, 'recording_text', true))
        : sanitize_text_field((string) get_post_meta($recording_id, 'recording_text', true));
    if ($existing_text !== '') {
        return new WP_Error('recording_has_text', __('This source recording already has text.', 'll-tools-text-domain'));
    }

    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), 'ipa');
    $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text($recording_ipa, $engine_rules, $wordset_id, $recording_id);
    $predicted_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text((string) ($prediction['text'] ?? ''))
        : sanitize_text_field((string) ($prediction['text'] ?? ''));
    if (empty($prediction['complete']) || $predicted_text === '') {
        return new WP_Error('conversion_failed', __('Current rules cannot fully convert this IPA transcription yet.', 'll-tools-text-domain'));
    }

    $recording_payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [
        'recording_text' => $predicted_text,
    ]);
    if (empty($recording_payload)) {
        return new WP_Error('recording_update_failed', __('Failed to save the converted recording text.', 'll-tools-text-domain'));
    }

    $word_update = ll_tools_fill_missing_word_fields_from_recording($word_id, $predicted_text, '');
    $updated_display = ll_tools_word_grid_resolve_display_text($word_id);

    return [
        'word_id' => $word_id,
        'recording_id' => $recording_id,
        'predicted_text' => $predicted_text,
        'recording' => (array) ($recording_payload['recording'] ?? []),
        'word' => [
            'id' => $word_id,
            'word_text' => (string) ($updated_display['word_text'] ?? ''),
            'word_translation' => (string) ($updated_display['translation_text'] ?? ''),
            'updated' => !empty($word_update['updated']),
        ],
    ];
}

function ll_tools_ipa_orthography_apply_suggestion_to_recording(int $wordset_id, int $recording_id, array $engine_rules) {
    $recording_id = (int) $recording_id;
    $wordset_id = (int) $wordset_id;
    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return new WP_Error('invalid_recording', __('Invalid recording.', 'll-tools-text-domain'));
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0 || !has_term($wordset_id, 'wordset', $word_id)) {
        return new WP_Error('invalid_wordset', __('Recording does not belong to this word set.', 'll-tools-text-domain'));
    }

    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), 'ipa');
    if ($recording_ipa === '') {
        return new WP_Error('missing_ipa', __('No IPA transcription is available for this recording.', 'll-tools-text-domain'));
    }

    $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text($recording_ipa, $engine_rules, $wordset_id, $recording_id);
    $predicted_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text((string) ($prediction['text'] ?? ''))
        : sanitize_text_field((string) ($prediction['text'] ?? ''));
    if (empty($prediction['complete']) || $predicted_text === '') {
        return new WP_Error('conversion_failed', __('Current rules cannot suggest a written transcription for this recording yet.', 'll-tools-text-domain'));
    }

    $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text((string) get_post_meta($recording_id, 'recording_text', true))
        : sanitize_text_field((string) get_post_meta($recording_id, 'recording_text', true));
    if ($recording_text !== '') {
        $mismatch_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            $recording_text,
            $recording_ipa,
            $wordset_id,
            ll_tools_ipa_orthography_get_recording_type_slug($recording_id),
            $prediction
        );
        $adjusted_text = (string) ($mismatch_detail['suggested_text'] ?? '');
        if ($adjusted_text !== '') {
            $predicted_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
                ? ll_tools_word_grid_sanitize_non_ipa_text($adjusted_text)
                : sanitize_text_field($adjusted_text);
        }
    }

    $recording_payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [
        'recording_text' => $predicted_text,
    ]);
    if (empty($recording_payload)) {
        return new WP_Error('recording_update_failed', __('Failed to save the suggested recording text.', 'll-tools-text-domain'));
    }

    return [
        'word_id' => $word_id,
        'recording_id' => $recording_id,
        'predicted_text' => $predicted_text,
        'prediction_source' => (string) ($prediction['source'] ?? 'rules'),
        'recording' => (array) ($recording_payload['recording'] ?? []),
    ];
}

function ll_tools_ipa_orthography_build_engine_rules_for_wordset(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $cache_key = ll_tools_ipa_orthography_engine_rules_cache_key($wordset_id);
    $runtime_cache = ll_tools_ipa_orthography_get_engine_rules_runtime_cache();
    if (isset($runtime_cache[$cache_key]) && is_array($runtime_cache[$cache_key])) {
        return $runtime_cache[$cache_key];
    }

    $persisted = get_transient($cache_key);
    if (is_array($persisted)) {
        ll_tools_ipa_orthography_set_engine_rules_runtime_cache($cache_key, $persisted);
        return $persisted;
    }

    $training_rows = ll_tools_ipa_orthography_collect_training_rows($wordset_id);
    $stats = ll_tools_ipa_orthography_collect_rule_stats($wordset_id, $training_rows);
    $auto_rules = ll_tools_ipa_orthography_build_auto_rules_from_stats($stats);
    $auto_rules = ll_tools_ipa_orthography_filter_auto_rules($auto_rules, ll_tools_ipa_orthography_get_blocklist($wordset_id), $wordset_id);
    $engine_rules = ll_tools_ipa_orthography_prepare_engine_rules($auto_rules, ll_tools_ipa_orthography_get_effective_manual_rules($wordset_id), $wordset_id);
    ll_tools_ipa_orthography_set_engine_rules_runtime_cache($cache_key, $engine_rules);
    set_transient($cache_key, $engine_rules, ll_tools_ipa_orthography_engine_rules_cache_ttl());

    return $engine_rules;
}

function ll_tools_ipa_keyboard_build_orthography_data(int $wordset_id): array {
    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    if ((string) ($transcription['mode'] ?? 'ipa') !== 'ipa') {
        return [
            'supported' => false,
            'rules' => [],
            'contradictions' => [],
            'conversion_candidates' => [],
            'stats' => [
                'rule_count' => 0,
                'training_pair_count' => 0,
                'active_contradiction_count' => 0,
                'approved_contradiction_count' => 0,
                'candidate_count' => 0,
            ],
        ];
    }

    $training_rows = ll_tools_ipa_orthography_collect_training_rows($wordset_id);
    $stats = ll_tools_ipa_orthography_collect_rule_stats($wordset_id, $training_rows);
    $raw_auto_rules = ll_tools_ipa_orthography_build_auto_rules_from_stats($stats);
    $manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
    $effective_manual_rules = ll_tools_ipa_orthography_get_effective_manual_rules($wordset_id);
    $blocklist = ll_tools_ipa_orthography_get_blocklist($wordset_id);
    $visible_auto_rules = ll_tools_ipa_orthography_filter_auto_rules($raw_auto_rules, $blocklist, $wordset_id);
    $engine_rules = ll_tools_ipa_orthography_prepare_engine_rules($visible_auto_rules, $effective_manual_rules, $wordset_id);
    $exception_word_ids = ll_tools_ipa_orthography_get_exception_word_ids($wordset_id);
    $exception_dictionary_entry_ids = ll_tools_ipa_orthography_get_exception_dictionary_entry_ids($wordset_id, $exception_word_ids);
    $contradictions = ll_tools_ipa_orthography_build_recording_contradiction_rows(
        $wordset_id,
        $engine_rules,
        $exception_word_ids,
        $exception_dictionary_entry_ids
    );
    $conversion_candidates = ll_tools_ipa_orthography_build_conversion_candidates($wordset_id, $engine_rules);
    $active_contradiction_count = 0;
    $approved_contradiction_count = 0;
    foreach ($contradictions as $row) {
        if (!empty($row['approved_exception'])) {
            $approved_contradiction_count++;
        } else {
            $active_contradiction_count++;
        }
    }

    return [
        'supported' => true,
        'rules' => ll_tools_ipa_orthography_build_rule_payload($raw_auto_rules, $visible_auto_rules, $manual_rules, $blocklist),
        'effective_rules' => ll_tools_ipa_orthography_build_rule_payload($raw_auto_rules, $visible_auto_rules, $effective_manual_rules, $blocklist),
        'contradictions' => $contradictions,
        'conversion_candidates' => $conversion_candidates,
        'conversion_profile' => ll_tools_ipa_orthography_get_conversion_profile($wordset_id),
        'stats' => [
            'rule_count' => count($raw_auto_rules),
            'training_pair_count' => count($training_rows),
            'active_contradiction_count' => $active_contradiction_count,
            'approved_contradiction_count' => $approved_contradiction_count,
            'candidate_count' => count($conversion_candidates),
        ],
    ];
}

function ll_tools_ipa_keyboard_build_orthography_response(int $wordset_id): array {
    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    return [
        'wordset' => [
            'id' => (int) $wordset_id,
            'name' => $wordset instanceof WP_Term ? (string) $wordset->name : '',
        ],
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
        'can_edit' => ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id),
        'orthography' => ll_tools_ipa_keyboard_build_orthography_data($wordset_id),
    ];
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
    $last_base_char_was_tie_bar = false;
    foreach ($chars as $char) {
        if (function_exists('ll_tools_word_grid_is_ipa_stress_marker') && ll_tools_word_grid_is_ipa_stress_marker($char, $mode)) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_separator') && ll_tools_word_grid_is_ipa_separator($char, $mode)) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_tie_bar') && ll_tools_word_grid_is_ipa_tie_bar($char, $mode)) {
            $base .= $char;
            $last_base_char_was_tie_bar = true;
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_combining_mark') && ll_tools_word_grid_is_ipa_combining_mark($char)) {
            continue;
        }
        if (function_exists('ll_tools_word_grid_is_ipa_post_modifier')
            && ll_tools_word_grid_is_ipa_post_modifier($char, $mode)
            && !($last_base_char_was_tie_bar && $char === "\u{10784}")) {
            continue;
        }
        $base .= $char;
        $last_base_char_was_tie_bar = false;
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

function ll_tools_ipa_keyboard_token_has_malformed_tie_bar(string $token, string $mode = 'ipa'): bool {
    if (function_exists('ll_tools_secondary_text_keyboard_symbol_has_malformed_tie_bar')) {
        return ll_tools_secondary_text_keyboard_symbol_has_malformed_tie_bar($token, $mode);
    }

    return $mode === 'ipa' && preg_match('/[\x{035C}\x{0361}][\x{02B0}-\x{02B8}\x{02D0}\x{02D1}\x{02E0}-\x{02E4}\x{1D2C}-\x{1D6A}\x{1D9B}-\x{1DBF}\x{2070}-\x{209F}\x{10784}\x{0300}-\x{036F}]/u', $token) === 1;
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

function ll_tools_ipa_keyboard_sanitize_orthography_mismatch_detail($raw): array {
    if (!is_array($raw)) {
        return [];
    }

    $sanitize_spans = static function ($spans): array {
        $clean = [];
        foreach ((array) $spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            $start = max(0, (int) ($span['start'] ?? 0));
            $length = max(0, (int) ($span['length'] ?? 0));
            if ($length <= 0) {
                continue;
            }
            $clean[] = [
                'start' => $start,
                'length' => $length,
            ];
        }
        return $clean;
    };

    $suggestions = [];
    foreach ((array) ($raw['ipa_suggestions'] ?? []) as $suggestion) {
        if (!is_array($suggestion)) {
            continue;
        }
        $ipa = sanitize_text_field((string) ($suggestion['ipa'] ?? ''));
        if ($ipa === '') {
            continue;
        }
        $suggestions[] = [
            'ipa' => $ipa,
            'label' => sanitize_text_field((string) (($suggestion['label'] ?? '') ?: $ipa)),
            'spans' => $sanitize_spans($suggestion['spans'] ?? []),
        ];
    }

    return [
        'actual_text' => sanitize_text_field((string) ($raw['actual_text'] ?? '')),
        'suggested_text' => sanitize_text_field((string) ($raw['suggested_text'] ?? '')),
        'canonical_suggested_text' => sanitize_text_field((string) ($raw['canonical_suggested_text'] ?? '')),
        'ipa_text' => sanitize_text_field((string) ($raw['ipa_text'] ?? '')),
        'matches' => !empty($raw['matches']),
        'requires_lexical_decision' => !empty($raw['requires_lexical_decision']),
        'actual_spans' => $sanitize_spans($raw['actual_spans'] ?? []),
        'suggested_spans' => $sanitize_spans($raw['suggested_spans'] ?? []),
        'ipa_spans' => $sanitize_spans($raw['ipa_spans'] ?? []),
        'ipa_suggestions' => array_slice($suggestions, 0, 4),
    ];
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

        $clean_issue = [
            'rule_key' => $rule_key,
            'code' => sanitize_key((string) ($issue['code'] ?? '')),
            'type' => in_array((string) ($issue['type'] ?? ''), ['builtin', 'custom'], true) ? (string) $issue['type'] : 'builtin',
            'label' => sanitize_text_field((string) ($issue['label'] ?? '')),
            'message' => sanitize_text_field((string) ($issue['message'] ?? '')),
            'count' => max(1, (int) ($issue['count'] ?? 1)),
            'samples' => $samples,
            'approval_options' => array_values(array_filter(array_map(static function ($option): ?array {
                if (!is_array($option)) {
                    return null;
                }
                $symbol = sanitize_text_field((string) ($option['symbol'] ?? ''));
                $output = sanitize_text_field((string) ($option['output'] ?? ''));
                if ($symbol === '' || $output === '') {
                    return null;
                }
                return [
                    'symbol' => $symbol,
                    'output' => $output,
                ];
            }, (array) ($issue['approval_options'] ?? [])))),
        ];

        $mismatch_detail = ll_tools_ipa_keyboard_sanitize_orthography_mismatch_detail($issue['orthography_mismatch'] ?? []);
        if (!empty($mismatch_detail['actual_text']) || !empty($mismatch_detail['suggested_text']) || !empty($mismatch_detail['ipa_text'])) {
            $clean_issue['orthography_mismatch'] = $mismatch_detail;
        }

        return $clean_issue;
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
                'schema_version' => max(0, (int) ($entry['schema_version'] ?? 0)),
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
    $entry = ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id);

    return [
        'active' => array_values((array) ($entry['active'] ?? [])),
        'ignored' => array_values((array) ($entry['ignored'] ?? [])),
    ];
}

function ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry(int $recording_id, int $wordset_id): array {
    $state = ll_tools_ipa_keyboard_get_recording_validation_state($recording_id);
    $has_state = isset($state[$wordset_id]) && is_array($state[$wordset_id]);
    $entry = (array) ($state[$wordset_id] ?? []);

    return [
        'has_state' => $has_state,
        'schema_version' => max(0, (int) ($entry['schema_version'] ?? 0)),
        'active' => array_values((array) ($entry['active'] ?? [])),
        'ignored' => array_values((array) ($entry['ignored'] ?? [])),
    ];
}

function ll_tools_ipa_keyboard_validation_result_is_stale(array $validation): bool {
    if (empty($validation['has_state'])) {
        return false;
    }

    return (int) ($validation['schema_version'] ?? 0) < ll_tools_ipa_keyboard_get_validation_schema_version();
}

function ll_tools_ipa_keyboard_validate_recording_for_wordset(
    int $recording_id,
    int $wordset_id,
    array $exception_rule_keys = [],
    &$profile = null
): array {
    $validate_started = microtime(true);
    $step_started = $validate_started;
    $mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    if (is_array($profile)) {
        $profile['validate_mode_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }
    if ($recording_id <= 0 || $wordset_id <= 0 || $mode !== 'ipa') {
        return ['active' => [], 'ignored' => []];
    }

    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $mode);
    if (is_array($profile)) {
        $profile['validate_load_ipa_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }
    if ($recording_ipa === '') {
        return ['active' => [], 'ignored' => []];
    }

    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($recording_ipa, $mode)
        : preg_split('//u', $recording_ipa, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($profile)) {
        $profile['validate_tokenize_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }
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
    $approved_ipa_symbols = ll_tools_ipa_keyboard_get_wordset_approved_ipa_symbols($wordset_id);
    $issue_map = [];
    if (is_array($profile)) {
        $profile['validate_config_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }

    $add_issue = static function (
        array &$issues,
        string $rule_key,
        string $code,
        string $type,
        string $label,
        string $message,
        string $sample = '',
        array $extra = []
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

        foreach ($extra as $key => $value) {
            if (is_string($key) && $key !== '') {
                $issues[$rule_key][$key] = $value;
            }
        }

        $issues[$rule_key]['count'] += 1;
        if ($sample !== '' && !in_array($sample, $issues[$rule_key]['samples'], true) && count($issues[$rule_key]['samples']) < 3) {
            $issues[$rule_key]['samples'][] = $sample;
        }
    };

    $add_approval_option = static function (array &$issue, string $symbol, string $output): void {
        $symbol = trim($symbol);
        $output = trim($output);
        if ($symbol === '' || $output === '') {
            return;
        }
        if (!isset($issue['approval_options']) || !is_array($issue['approval_options'])) {
            $issue['approval_options'] = [];
        }
        foreach ($issue['approval_options'] as $option) {
            if ((string) ($option['symbol'] ?? '') === $symbol && (string) ($option['output'] ?? '') === $output) {
                return;
            }
        }
        $issue['approval_options'][] = [
            'symbol' => $symbol,
            'output' => $output,
        ];
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
            if (ll_tools_ipa_keyboard_token_base_segment_count((string) $token, $mode) < 2
                || ll_tools_ipa_keyboard_token_has_malformed_tie_bar((string) $token, $mode)) {
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

    if (!in_array('dental_diacritic_context', $disabled_builtin_rules, true)) {
        foreach ($segment_tokens as $token) {
            $token = (string) $token;
            if (!ll_tools_ipa_keyboard_token_has_invalid_dental_diacritic($token)) {
                continue;
            }
            $add_issue(
                $issue_map,
                'builtin:dental_diacritic_context',
                'dental_diacritic_context',
                'builtin',
                (string) ($builtin_rules['dental_diacritic_context']['label'] ?? ''),
                __('The dental diacritic should be attached to t or d in this IPA inventory.', 'll-tools-text-domain'),
                $token
            );
        }
    }

    if (!in_array('illegal_ipa_symbol', $disabled_builtin_rules, true)
        && function_exists('ll_tools_get_wordset_secondary_text_illegal_symbols')
        && function_exists('ll_tools_secondary_text_token_has_illegal_symbol')) {
        $illegal_symbols = ll_tools_get_wordset_secondary_text_illegal_symbols($wordset_id, $mode);
        if (!empty($illegal_symbols)) {
            foreach ($segment_tokens as $token) {
                $token = (string) $token;
                $illegal_symbol = ll_tools_secondary_text_token_has_illegal_symbol($token, $illegal_symbols);
                if ($illegal_symbol === '') {
                    continue;
                }
                $add_issue(
                    $issue_map,
                    'builtin:illegal_ipa_symbol',
                    'illegal_ipa_symbol',
                    'builtin',
                    (string) ($builtin_rules['illegal_ipa_symbol']['label'] ?? ''),
                    sprintf(
                        /* translators: %s is an IPA symbol. */
                        __('This transcription contains %s, which is marked illegal for this word set.', 'll-tools-text-domain'),
                        $illegal_symbol
                    ),
                    $token
                );
            }
        }
    }
    if (is_array($profile)) {
        $profile['validate_builtin_symbol_rules_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }

    $recording_text = function_exists('ll_tools_word_grid_sanitize_non_ipa_text')
        ? ll_tools_word_grid_sanitize_non_ipa_text((string) get_post_meta($recording_id, 'recording_text', true))
        : sanitize_text_field((string) get_post_meta($recording_id, 'recording_text', true));
    if (is_array($profile)) {
        $profile['validate_load_text_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }

    if (!in_array('orthography_mismatch', $disabled_builtin_rules, true) && $recording_text !== '') {
        $orthography_started = microtime(true);
        $engine_started = $orthography_started;
        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);
        if (is_array($profile)) {
            $profile['orthography_engine_rules_seconds'] = round(microtime(true) - $engine_started, 4);
            $engine_started = microtime(true);
        }
        $orthography_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            $recording_ipa,
            $engine_rules,
            $wordset_id,
            $recording_id,
            $profile
        );
        if (is_array($profile)) {
            $profile['orthography_predict_seconds'] = round(microtime(true) - $engine_started, 4);
            $engine_started = microtime(true);
        }
        $orthography_text = (string) ($orthography_prediction['text'] ?? '');
        if (!empty($orthography_prediction['complete']) && $orthography_text !== '') {
            $mismatch_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
                $recording_text,
                $recording_ipa,
                $wordset_id,
                ll_tools_ipa_orthography_get_recording_type_slug($recording_id),
                $orthography_prediction
            );
            if (is_array($profile)) {
                $profile['orthography_mismatch_detail_seconds'] = round(microtime(true) - $engine_started, 4);
                $engine_started = microtime(true);
            }
            $recording_word_id = (int) wp_get_post_parent_id($recording_id);
            $orthography_exception_word_ids = ll_tools_ipa_orthography_get_exception_word_ids($wordset_id);
            $orthography_exception_dictionary_entry_ids = ll_tools_ipa_orthography_get_exception_dictionary_entry_ids(
                $wordset_id,
                $orthography_exception_word_ids
            );
            $is_approved_orthography_exception = ll_tools_ipa_orthography_exception_applies_to_word(
                $wordset_id,
                $recording_word_id,
                $orthography_exception_word_ids,
                $orthography_exception_dictionary_entry_ids
            );
            if (is_array($profile)) {
                $profile['orthography_exception_seconds'] = round(microtime(true) - $engine_started, 4);
            }
            if (empty($mismatch_detail['matches']) && !$is_approved_orthography_exception) {
                $add_issue(
                    $issue_map,
                    'builtin:orthography_mismatch',
                    'orthography_mismatch',
                    'builtin',
                    (string) ($builtin_rules['orthography_mismatch']['label'] ?? ''),
                    __('Saved text does not match the current orthography rules.', 'll-tools-text-domain'),
                    '',
                    [
                        'orthography_mismatch' => $mismatch_detail,
                    ]
                );
            }
        }
        if (is_array($profile)) {
            $profile['validate_orthography_seconds'] = round(microtime(true) - $orthography_started, 4);
            $step_started = microtime(true);
        }
    }

    if (!in_array('unapproved_ipa_symbol', $disabled_builtin_rules, true)) {
        foreach ($segment_tokens as $token) {
            $token = (string) $token;
            $unapproved_symbol = ll_tools_ipa_keyboard_token_has_unapproved_ipa_symbol($token, $approved_ipa_symbols);
            if ($unapproved_symbol === '') {
                continue;
            }
            $rule_key = 'builtin:unapproved_ipa_symbol';
            $add_issue(
                $issue_map,
                $rule_key,
                'unapproved_ipa_symbol',
                'builtin',
                (string) ($builtin_rules['unapproved_ipa_symbol']['label'] ?? ''),
                __('This IPA token contains a symbol outside the approved inventory.', 'll-tools-text-domain'),
                $token
            );
            $orthography_output = ll_tools_ipa_keyboard_infer_orthography_output_for_ipa_symbol(
                $wordset_id,
                $recording_text,
                $recording_ipa,
                $unapproved_symbol
            );
            if ($orthography_output !== '' && isset($issue_map[$rule_key])) {
                $add_approval_option($issue_map[$rule_key], $unapproved_symbol, $orthography_output);
            }
        }
    }
    if (is_array($profile)) {
        $profile['validate_unapproved_symbols_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
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
    if (is_array($profile)) {
        $profile['validate_custom_rules_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
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

    ll_tools_ipa_keyboard_clear_scheduled_recording_validation($recording_id);

    $wordset_ids = ll_tools_ipa_keyboard_get_recording_wordset_ids($recording_id);
    $state = [];

    foreach ($wordset_ids as $wordset_id) {
        $exceptions = ll_tools_ipa_keyboard_get_recording_validation_exception_keys($recording_id, $wordset_id);
        $validation = ll_tools_ipa_keyboard_validate_recording_for_wordset($recording_id, $wordset_id, $exceptions);
        if (empty($validation['active']) && empty($validation['ignored'])) {
            continue;
        }

        $state[$wordset_id] = [
            'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version(),
            'active' => array_values((array) ($validation['active'] ?? [])),
            'ignored' => array_values((array) ($validation['ignored'] ?? [])),
        ];
    }

    return ll_tools_ipa_keyboard_save_recording_validation_state($recording_id, $state);
}

function ll_tools_ipa_keyboard_recording_belongs_to_wordset_id(int $recording_id, int $wordset_id, ?WP_Post $recording = null): bool {
    if ($recording_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    if (!($recording instanceof WP_Post)) {
        $recording = get_post($recording_id);
    }
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return false;
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return false;
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    return !is_wp_error($wordset_ids) && in_array($wordset_id, array_map('intval', (array) $wordset_ids), true);
}

function ll_tools_ipa_keyboard_recording_is_in_wordset_search_scope(int $recording_id, int $wordset_id, ?WP_Post $recording = null): bool {
    static $word_id_sets_by_wordset = [];

    if ($recording_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    if (!($recording instanceof WP_Post)) {
        $recording = get_post($recording_id);
    }
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return false;
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return false;
    }

    if (!array_key_exists($wordset_id, $word_id_sets_by_wordset)) {
        $word_id_sets_by_wordset[$wordset_id] = array_fill_keys(
            ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id),
            true
        );
    }

    return isset($word_id_sets_by_wordset[$wordset_id][$word_id]);
}

function ll_tools_ipa_keyboard_count_active_validation_issues(array $state): int {
    $active_issue_count = 0;
    foreach ($state as $entry) {
        $active_issue_count += count((array) ($entry['active'] ?? []));
    }

    return max(0, $active_issue_count);
}

function ll_tools_ipa_keyboard_save_recording_validation_state(int $recording_id, array $state): array {
    $state = ll_tools_ipa_keyboard_sanitize_validation_state($state);
    $state_meta_key = ll_tools_ipa_keyboard_validation_state_meta_key();
    $existing_state = ll_tools_ipa_keyboard_get_recording_validation_state($recording_id);
    if ($state !== $existing_state) {
        if (empty($state)) {
            delete_post_meta($recording_id, $state_meta_key);
        } else {
            update_post_meta($recording_id, $state_meta_key, $state);
        }
    }

    $active_issue_count = ll_tools_ipa_keyboard_count_active_validation_issues($state);
    $issue_count_meta_key = ll_tools_ipa_keyboard_validation_issue_count_meta_key();
    $existing_issue_count = (int) get_post_meta($recording_id, $issue_count_meta_key, true);
    if ($active_issue_count > 0) {
        if ($active_issue_count !== $existing_issue_count) {
            update_post_meta($recording_id, $issue_count_meta_key, $active_issue_count);
        }
    } elseif ($existing_issue_count > 0 || metadata_exists('post', $recording_id, $issue_count_meta_key)) {
        delete_post_meta($recording_id, $issue_count_meta_key);
    }

    return $state;
}

function ll_tools_ipa_keyboard_update_recording_validation_for_wordset(
    int $recording_id,
    int $wordset_id,
    bool $verify_membership = true,
    &$profile = null
): array {
    $total_started = microtime(true);
    $step_started = $total_started;
    $recording = get_post($recording_id);
    if (is_array($profile)) {
        $profile['get_recording_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio' || $wordset_id <= 0) {
        return [];
    }

    if ($verify_membership && !ll_tools_ipa_keyboard_recording_belongs_to_wordset_id($recording_id, $wordset_id, $recording)) {
        return [];
    }
    if (is_array($profile)) {
        $profile['verify_membership_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }

    ll_tools_ipa_keyboard_clear_scheduled_recording_validation($recording_id);
    if (is_array($profile)) {
        $profile['clear_schedule_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }

    $state = ll_tools_ipa_keyboard_get_recording_validation_state($recording_id);
    if (is_array($profile)) {
        $profile['load_state_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }
    $exceptions = ll_tools_ipa_keyboard_get_recording_validation_exception_keys($recording_id, $wordset_id);
    if (is_array($profile)) {
        $profile['load_exceptions_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }
    $validation = ll_tools_ipa_keyboard_validate_recording_for_wordset($recording_id, $wordset_id, $exceptions, $profile);
    if (is_array($profile)) {
        $profile['validate_seconds'] = round(microtime(true) - $step_started, 4);
        $step_started = microtime(true);
    }

    if (empty($validation['active']) && empty($validation['ignored'])) {
        unset($state[$wordset_id]);
    } else {
        $state[$wordset_id] = [
            'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version(),
            'active' => array_values((array) ($validation['active'] ?? [])),
            'ignored' => array_values((array) ($validation['ignored'] ?? [])),
        ];
    }

    $saved_state = ll_tools_ipa_keyboard_save_recording_validation_state($recording_id, $state);
    if (is_array($profile)) {
        $profile['save_state_seconds'] = round(microtime(true) - $step_started, 4);
        $profile['total_seconds'] = round(microtime(true) - $total_started, 4);
    }

    return $saved_state;
}

function ll_tools_ipa_keyboard_clear_scheduled_recording_validation(int $recording_id): void {
    $recording_id = (int) $recording_id;
    if ($recording_id <= 0 || !function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
        return;
    }

    $args = [$recording_id];
    while ($timestamp = wp_next_scheduled(LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, $args)) {
        $unscheduled = wp_unschedule_event((int) $timestamp, LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, $args);
        if ($unscheduled === false) {
            break;
        }
    }
}

function ll_tools_ipa_keyboard_schedule_recording_validation(int $recording_id, int $delay_seconds = 8): void {
    $recording_id = (int) $recording_id;
    if ($recording_id <= 0) {
        return;
    }

    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        return;
    }

    $delay_seconds = (int) apply_filters('ll_tools_ipa_keyboard_validation_delay_seconds', $delay_seconds, $recording_id);
    $delay_seconds = max(1, $delay_seconds);
    $args = [$recording_id];
    if (wp_next_scheduled(LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, $args)) {
        return;
    }

    $scheduled = wp_schedule_single_event(time() + $delay_seconds, LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, $args);
    if ($scheduled === false && !(function_exists('wp_doing_ajax') && wp_doing_ajax())) {
        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
    }
}

add_action(LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, 'll_tools_ipa_keyboard_run_scheduled_recording_validation', 10, 1);
function ll_tools_ipa_keyboard_run_scheduled_recording_validation($recording_id): void {
    ll_tools_ipa_keyboard_update_recording_validation((int) $recording_id);
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

function ll_tools_ipa_keyboard_get_active_validation_wordset_ids_for_recording(
    int $recording_id,
    ?WP_Post $recording = null
): array {
    if ($recording_id <= 0) {
        return [];
    }

    $state = ll_tools_ipa_keyboard_get_recording_validation_state($recording_id);
    if (empty($state)) {
        return [];
    }

    $wordset_ids = [];
    foreach ($state as $wordset_id => $entry) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0 || empty($entry['active'])) {
            continue;
        }

        if (!ll_tools_ipa_keyboard_recording_is_in_wordset_search_scope($recording_id, $wordset_id, $recording)) {
            continue;
        }

        $wordset_ids[] = $wordset_id;
    }

    return array_values(array_unique($wordset_ids));
}

function ll_tools_ipa_keyboard_get_flagged_validation_recording_count(): int {
    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [
            [
                'key' => ll_tools_ipa_keyboard_validation_issue_count_meta_key(),
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
        ],
    ]);

    $count = 0;
    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        if (!empty(ll_tools_ipa_keyboard_get_active_validation_wordset_ids_for_recording($recording_id))) {
            $count++;
        }
    }

    return $count;
}

function ll_tools_ipa_keyboard_get_flagged_validation_recording_counts_by_wordset(): array {
    if (!current_user_can('view_ll_tools')) {
        return [];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [
            [
                'key' => ll_tools_ipa_keyboard_validation_issue_count_meta_key(),
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
        ],
    ]);

    if (empty($recording_ids)) {
        return [];
    }

    $counts = [];
    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        $recording = get_post($recording_id);
        foreach (ll_tools_ipa_keyboard_get_active_validation_wordset_ids_for_recording($recording_id, $recording) as $wordset_id) {
            if (!ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
                continue;
            }

            if (!isset($counts[$wordset_id])) {
                $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
                if (!$wordset) {
                    continue;
                }

                $counts[$wordset_id] = [
                    'wordset_id' => $wordset_id,
                    'wordset_name' => (string) $wordset->name,
                    'count' => 0,
                ];
            }

            $counts[$wordset_id]['count']++;
        }
    }

    uasort($counts, static function (array $left, array $right): int {
        return ll_tools_locale_compare_strings(
            (string) ($left['wordset_name'] ?? ''),
            (string) ($right['wordset_name'] ?? '')
        );
    });

    return array_values($counts);
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

    // Avoid blocking wp-admin on large sites. Stale rows refresh lazily in issue search and on save.
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

    ll_tools_ipa_keyboard_schedule_recording_validation((int) $post_id);
}
add_action('save_post_word_audio', 'll_tools_ipa_keyboard_sync_validation_on_word_audio_save', 25, 3);

function ll_tools_ipa_keyboard_sync_validation_on_recording_meta_change($meta_ids, $object_id, $meta_key, $meta_value = null): void {
    if (!in_array((string) $meta_key, ['recording_text', 'recording_ipa'], true)) {
        return;
    }

    $post = get_post((int) $object_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'word_audio') {
        return;
    }

    ll_tools_ipa_keyboard_schedule_recording_validation((int) $object_id);
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
        ll_tools_ipa_keyboard_schedule_recording_validation((int) $recording_id);
    }
}
add_action('set_object_terms', 'll_tools_ipa_keyboard_sync_validation_on_wordset_term_change', 10, 6);

function ll_tools_ipa_keyboard_text_matches_pattern(string $value, string $query): bool {
    $value = trim($value);
    $query = html_entity_decode(trim($query), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($query === '') {
        return true;
    }

    if (stripos($query, 'rx:') === 0) {
        $pattern = trim(substr($query, 3));
        if ($pattern === '') {
            return false;
        }

        if ($pattern[0] !== '/') {
            $pattern = '/' . str_replace('/', '\/', $pattern) . '/u';
        }

        $result = @preg_match($pattern, $value);
        return ($result === 1);
    }

    if (strpos($query, '*') !== false || strpos($query, '?') !== false) {
        if (function_exists('ll_tools_normalize_text_for_search')) {
            $value = ll_tools_normalize_text_for_search($value);
            $query = ll_tools_normalize_text_for_search($query);
        }
        $pattern = preg_quote($query, '/');
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], $pattern);
        return preg_match('/' . $pattern . '/iu', $value) === 1;
    }

    if (function_exists('ll_tools_text_matches_search')) {
        return ll_tools_text_matches_search($value, $query);
    }

    if (function_exists('mb_stripos')) {
        return mb_stripos($value, $query, 0, 'UTF-8') !== false;
    }

    return stripos($value, $query) !== false;
}

function ll_tools_ipa_keyboard_transcription_matches_simple_query(
    string $value,
    string $query,
    string $mode = 'ipa',
    bool $exact = false
): bool {
    $query = html_entity_decode(trim($query), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($query === '') {
        return true;
    }

    if (stripos($query, 'rx:') === 0 || strpos($query, '*') !== false || strpos($query, '?') !== false) {
        return ll_tools_ipa_keyboard_text_matches_pattern($value, $query);
    }

    $value_tokens = ll_tools_ipa_keyboard_tokenize_search_transcription($value, $mode);
    $query_tokens = ll_tools_ipa_keyboard_tokenize_search_transcription($query, $mode);
    if (empty($value_tokens) || empty($query_tokens)) {
        return false;
    }

    $sequence_length = count($query_tokens);
    $token_count = count($value_tokens);
    if ($sequence_length > $token_count) {
        return false;
    }

    for ($offset = 0; $offset <= ($token_count - $sequence_length); $offset++) {
        if (ll_tools_ipa_keyboard_search_sequence_matches_at($value_tokens, $offset, $query_tokens, $mode, $exact)) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_tokenize_search_transcription(string $value, string $mode = 'ipa'): array {
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $value = function_exists('ll_tools_word_grid_sanitize_ipa')
        ? ll_tools_word_grid_sanitize_ipa($value, $mode)
        : sanitize_text_field($value);
    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($value, $mode)
        : preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($tokens)) {
        return [];
    }

    $normalized = [];
    foreach ((array) $tokens as $token) {
        $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $token, $mode);
        if ($token === '') {
            continue;
        }

        if ($mode !== 'ipa') {
            $normalized[] = $token;
            continue;
        }

        $chars = ll_tools_ipa_keyboard_split_token_characters($token);
        if (empty($chars)) {
            continue;
        }

        $base = '';
        foreach ($chars as $char) {
            if (function_exists('ll_tools_word_grid_is_ipa_stress_marker')
                && ll_tools_word_grid_is_ipa_stress_marker($char, $mode)) {
                continue;
            }
            if (function_exists('ll_tools_word_grid_is_ipa_separator')
                && ll_tools_word_grid_is_ipa_separator($char, $mode)) {
                continue;
            }

            if (function_exists('ll_tools_word_grid_is_ipa_post_modifier')
                && ll_tools_word_grid_is_ipa_post_modifier($char, $mode)) {
                if ($base !== '') {
                    $normalized[] = $base;
                    $base = '';
                }

                $modifier = ll_tools_ipa_keyboard_normalize_ipa_token($char, $mode);
                if ($modifier !== '') {
                    $normalized[] = $modifier;
                }
                continue;
            }

            if ($base === '') {
                $base = $char;
                continue;
            }

            $base_chars = ll_tools_ipa_keyboard_split_token_characters($base);
            $last_base_char = empty($base_chars) ? '' : (string) end($base_chars);
            if ((function_exists('ll_tools_word_grid_is_ipa_combining_mark')
                    && ll_tools_word_grid_is_ipa_combining_mark($char))
                || (function_exists('ll_tools_word_grid_is_ipa_tie_bar')
                    && ll_tools_word_grid_is_ipa_tie_bar($char, $mode))
                || ((function_exists('ll_tools_word_grid_is_ipa_tie_bar')
                        && ll_tools_word_grid_is_ipa_tie_bar($last_base_char, $mode)))) {
                $base .= $char;
                continue;
            }

            $base .= $char;
        }

        if ($base !== '') {
            $normalized[] = $base;
        }
    }

    return $normalized;
}

function ll_tools_ipa_keyboard_parse_search_sequence_alternatives(string $value, string $mode = 'ipa'): array {
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    if (preg_match('/^\[(.*)\]$/u', $value, $matches)) {
        $parts = preg_split('/\s*(?:\||,)\s*|\s+/u', trim((string) $matches[1]), -1, PREG_SPLIT_NO_EMPTY);
        $alternatives = [];
        foreach ((array) $parts as $part) {
            $tokens = ll_tools_ipa_keyboard_tokenize_search_transcription((string) $part, $mode);
            if (!empty($tokens)) {
                $alternatives[] = $tokens;
            }
        }
        return $alternatives;
    }

    $tokens = ll_tools_ipa_keyboard_tokenize_search_transcription($value, $mode);
    return empty($tokens) ? [] : [$tokens];
}

function ll_tools_ipa_keyboard_search_token_matches(
    string $actual_token,
    string $expected_token,
    string $mode = 'ipa',
    bool $exact = false
): bool {
    $actual_token = ll_tools_ipa_keyboard_normalize_ipa_token($actual_token, $mode);
    $expected_token = ll_tools_ipa_keyboard_normalize_ipa_token($expected_token, $mode);
    if ($actual_token === '' || $expected_token === '') {
        return false;
    }

    if ($actual_token === $expected_token) {
        return true;
    }

    if ($exact) {
        return false;
    }

    $expected_base = ll_tools_ipa_keyboard_extract_token_base($expected_token, $mode);
    if ($expected_base === '' || $expected_token !== $expected_base) {
        return false;
    }

    return ll_tools_ipa_keyboard_extract_token_base($actual_token, $mode) === $expected_base;
}

function ll_tools_ipa_keyboard_search_sequence_matches_at(
    array $tokens,
    int $offset,
    array $sequence,
    string $mode = 'ipa',
    bool $exact = false
): bool {
    $sequence = array_values(array_filter(array_map('strval', $sequence), static function (string $token): bool {
        return $token !== '';
    }));
    if ($offset < 0 || empty($sequence) || ($offset + count($sequence)) > count($tokens)) {
        return false;
    }

    foreach ($sequence as $index => $token) {
        if (!ll_tools_ipa_keyboard_search_token_matches((string) ($tokens[$offset + $index] ?? ''), $token, $mode, $exact)) {
            return false;
        }
    }

    return true;
}

function ll_tools_ipa_keyboard_search_any_sequence_matches_at(
    array $tokens,
    int $offset,
    array $alternatives,
    string $mode = 'ipa',
    bool $exact = false
): bool {
    foreach ($alternatives as $sequence) {
        if (ll_tools_ipa_keyboard_search_sequence_matches_at($tokens, $offset, (array) $sequence, $mode, $exact)) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_search_any_sequence_ends_at(
    array $tokens,
    int $end_offset,
    array $alternatives,
    string $mode = 'ipa',
    bool $exact = false
): bool {
    foreach ($alternatives as $sequence) {
        $sequence = array_values((array) $sequence);
        $start_offset = $end_offset - count($sequence);
        if ($start_offset < 0) {
            continue;
        }
        if (ll_tools_ipa_keyboard_search_sequence_matches_at($tokens, $start_offset, $sequence, $mode, $exact)) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_transcription_matches_advanced_pattern(
    string $value,
    string $query,
    string $mode = 'ipa',
    bool $exact = false
): ?bool {
    $query = html_entity_decode(trim($query), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($query === '' || stripos($query, 'rx:') === 0) {
        return null;
    }

    if (!preg_match('/^(.+?)\s*(!?>|!?<|>|<)\s*(.+)$/u', $query, $matches)) {
        return null;
    }

    $left_alternatives = ll_tools_ipa_keyboard_parse_search_sequence_alternatives((string) $matches[1], $mode);
    $right_alternatives = ll_tools_ipa_keyboard_parse_search_sequence_alternatives((string) $matches[3], $mode);
    $tokens = ll_tools_ipa_keyboard_tokenize_search_transcription($value, $mode);
    if (empty($left_alternatives) || empty($right_alternatives) || empty($tokens)) {
        return false;
    }

    $operator = (string) $matches[2];
    foreach ($tokens as $offset => $token) {
        unset($token);
        foreach ($left_alternatives as $sequence) {
            $sequence = array_values((array) $sequence);
            if (empty($sequence) || !ll_tools_ipa_keyboard_search_sequence_matches_at($tokens, (int) $offset, $sequence, $mode, $exact)) {
                continue;
            }

            $next_offset = (int) $offset + count($sequence);
            $has_following_match = ll_tools_ipa_keyboard_search_any_sequence_matches_at($tokens, $next_offset, $right_alternatives, $mode, $exact);
            $has_previous_match = ll_tools_ipa_keyboard_search_any_sequence_ends_at($tokens, (int) $offset, $right_alternatives, $mode, $exact);

            if ($operator === '>' && $has_following_match) {
                return true;
            }
            if ($operator === '!>' && !$has_following_match) {
                return true;
            }
            if ($operator === '<' && $has_previous_match) {
                return true;
            }
            if ($operator === '!<' && !$has_previous_match) {
                return true;
            }
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_recording_matches_search(
    array $payload,
    string $query,
    string $scope = 'both',
    string $transcription_mode = 'ipa',
    bool $exact_transcription = false
): bool {
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
            $advanced_match = ll_tools_ipa_keyboard_transcription_matches_advanced_pattern($value, $query, $transcription_mode, $exact_transcription);
            if ($advanced_match !== null) {
                if ($advanced_match) {
                    return true;
                }
                continue;
            }
            if (ll_tools_ipa_keyboard_transcription_matches_simple_query($value, $query, $transcription_mode, $exact_transcription)) {
                return true;
            }
        }
        return false;
    }

    foreach ($written_values as $value) {
        if (ll_tools_ipa_keyboard_text_matches_pattern($value, $query)) {
            return true;
        }
    }

    foreach ($transcription_values as $value) {
        $advanced_match = ll_tools_ipa_keyboard_transcription_matches_advanced_pattern($value, $query, $transcription_mode, $exact_transcription);
        if ($advanced_match !== null) {
            if ($advanced_match) {
                return true;
            }
            continue;
        }
        if (ll_tools_ipa_keyboard_transcription_matches_simple_query($value, $query, $transcription_mode, $exact_transcription)) {
            return true;
        }
    }

    return false;
}

function ll_tools_ipa_keyboard_build_search_row_payload(
    int $recording_id,
    int $wordset_id,
    array $word_info,
    string $transcription_mode = '',
    bool $refresh_validation = false
): array {
    if ($transcription_mode === '') {
        $transcription_mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    }
    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode);
    $payload = ll_tools_ipa_keyboard_build_recording_payload($recording_id, (int) wp_get_post_parent_id($recording_id), $word_info, $recording_ipa, $wordset_id);
    $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id);
    if ($refresh_validation
        && (!empty($validation['active']) || !empty($validation['ignored']) || ll_tools_ipa_keyboard_validation_result_is_stale($validation))) {
        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id);
    }

    $payload['issues'] = array_values((array) ($validation['active'] ?? []));
    $payload['ignored_issues'] = array_values((array) ($validation['ignored'] ?? []));
    $payload['issue_count'] = count($payload['issues']);
    $payload['ignored_issue_count'] = count($payload['ignored_issues']);
    $payload['needs_review'] = ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id);

    return $payload;
}

function ll_tools_ipa_keyboard_get_search_results_per_page(): int {
    $per_page = (int) apply_filters('ll_tools_ipa_keyboard_search_results_per_page', 100);
    return max(1, min(500, $per_page));
}

function ll_tools_ipa_keyboard_get_issue_search_stale_refresh_limit(): int {
    $limit = (int) apply_filters('ll_tools_ipa_keyboard_issue_search_stale_refresh_limit', 0);
    return max(0, min(100, $limit));
}

function ll_tools_ipa_keyboard_get_search_recording_ids(array $word_ids, bool $issues_only = false): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    }));
    if (empty($word_ids)) {
        return [];
    }

    $args = [
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
        'no_found_rows' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
    ];

    if ($issues_only) {
        $args['meta_query'] = [
            [
                'key' => ll_tools_ipa_keyboard_validation_issue_count_meta_key(),
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ],
        ];
    }

    return array_values(array_map('intval', get_posts($args)));
}

function ll_tools_ipa_keyboard_search_recordings(
    int $wordset_id,
    string $query = '',
    string $scope = 'both',
    bool $issues_only = false,
    bool $review_only = false,
    bool $exact_transcription = false,
    int $page = 1,
    int $per_page = 0
): array {
    $transcription_mode = ll_tools_ipa_keyboard_get_transcription_mode_for_wordset($wordset_id);
    $page = max(1, $page);
    $per_page = $per_page > 0 ? $per_page : ll_tools_ipa_keyboard_get_search_results_per_page();
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [
            'results' => [],
            'total_matches' => 0,
            'shown_count' => 0,
            'has_more' => false,
            'current_page' => 1,
            'total_pages' => 1,
            'per_page' => $per_page,
            'page_start' => 0,
            'page_end' => 0,
        ];
    }

    $recording_ids = ll_tools_ipa_keyboard_get_search_recording_ids($word_ids, $issues_only);
    if (empty($recording_ids)) {
        return [
            'results' => [],
            'total_matches' => 0,
            'shown_count' => 0,
            'has_more' => false,
            'current_page' => 1,
            'total_pages' => 1,
            'per_page' => $per_page,
            'page_start' => 0,
            'page_end' => 0,
        ];
    }

    $recording_parent_ids = [];
    $display_word_ids = $word_ids;
    if ($issues_only) {
        $display_word_ids = [];
        foreach ((array) $recording_ids as $recording_id) {
            $recording_id = (int) $recording_id;
            if ($recording_id <= 0) {
                continue;
            }
            $parent_id = (int) wp_get_post_parent_id($recording_id);
            if ($parent_id <= 0) {
                continue;
            }
            $recording_parent_ids[$recording_id] = $parent_id;
            $display_word_ids[$parent_id] = $parent_id;
        }
        $display_word_ids = array_values($display_word_ids);
    }

    $word_display = ll_tools_ipa_keyboard_get_word_display_map($display_word_ids);
    $matches = [];
    $has_query = ($query !== '');
    $stale_refreshes_remaining = $issues_only ? ll_tools_ipa_keyboard_get_issue_search_stale_refresh_limit() : 0;
    $stale_refresh_count = 0;
    $stale_refresh_deferred_count = 0;

    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }

        $word_id = (int) ($recording_parent_ids[$recording_id] ?? wp_get_post_parent_id($recording_id));
        $word_info = (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => '']);
        if (!$has_query) {
            if ($issues_only) {
                $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id);
                if (!empty($validation['active']) && ll_tools_ipa_keyboard_validation_result_is_stale($validation)) {
                    if ($stale_refreshes_remaining > 0) {
                        $stale_refreshes_remaining--;
                        $stale_refresh_count++;
                        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
                        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id);
                    } else {
                        $stale_refresh_deferred_count++;
                    }
                }
                if (empty($validation['active'])) {
                    continue;
                }
            }

            if ($review_only && !ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id)) {
                continue;
            }

            $matches[] = [
                'recording_id' => $recording_id,
                'word_id' => $word_id,
                'word_info' => $word_info,
                'word_text' => (string) ($word_info['word_text'] ?? ''),
                'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
                'payload' => null,
            ];
            continue;
        }

        $payload = ll_tools_ipa_keyboard_build_search_row_payload(
            $recording_id,
            $wordset_id,
            $word_info,
            $transcription_mode,
            false
        );

        if ($issues_only) {
            $validation = [
                'has_state' => true,
                'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version(),
                'active' => (array) ($payload['issues'] ?? []),
                'ignored' => (array) ($payload['ignored_issues'] ?? []),
            ];
            $state_entry = ll_tools_ipa_keyboard_get_recording_wordset_validation_state_entry($recording_id, $wordset_id);
            if (!empty($state_entry['active']) && ll_tools_ipa_keyboard_validation_result_is_stale($state_entry)) {
                if ($stale_refreshes_remaining > 0) {
                    $stale_refreshes_remaining--;
                    $stale_refresh_count++;
                    ll_tools_ipa_keyboard_update_recording_validation($recording_id);
                    $payload = ll_tools_ipa_keyboard_build_search_row_payload(
                        $recording_id,
                        $wordset_id,
                        $word_info,
                        $transcription_mode,
                        false
                    );
                    $validation['active'] = (array) ($payload['issues'] ?? []);
                } else {
                    $stale_refresh_deferred_count++;
                }
            }
            if (empty($validation['active'])) {
                continue;
            }
        }

        if ($review_only && empty($payload['needs_review'])) {
            continue;
        }

        if (!ll_tools_ipa_keyboard_recording_matches_search($payload, $query, $scope, $transcription_mode, $exact_transcription)) {
            continue;
        }

        $matches[] = [
            'recording_id' => $recording_id,
            'word_id' => $word_id,
            'word_info' => $word_info,
            'word_text' => (string) ($payload['word_text'] ?? ''),
            'recording_text' => (string) ($payload['recording_text'] ?? ''),
            'payload' => $payload,
        ];
    }

    usort($matches, static function (array $left, array $right): int {
        $word_compare = ll_tools_locale_compare_strings((string) ($left['word_text'] ?? ''), (string) ($right['word_text'] ?? ''));
        if ($word_compare !== 0) {
            return $word_compare;
        }

        return ll_tools_locale_compare_strings((string) ($left['recording_text'] ?? ''), (string) ($right['recording_text'] ?? ''));
    });

    $total_matches = count($matches);
    $total_pages = max(1, (int) ceil($total_matches / $per_page));
    $current_page = min($page, $total_pages);
    $offset = max(0, ($current_page - 1) * $per_page);
    $page_matches = array_slice($matches, $offset, $per_page);
    $results = array_map(static function (array $match) use ($wordset_id, $transcription_mode): array {
        if (is_array($match['payload'] ?? null)) {
            return (array) $match['payload'];
        }

        return ll_tools_ipa_keyboard_build_search_row_payload(
            (int) ($match['recording_id'] ?? 0),
            $wordset_id,
            (array) ($match['word_info'] ?? ['word_text' => '', 'translation' => '']),
            $transcription_mode,
            false
        );
    }, $page_matches);
    $page_start = $total_matches > 0 ? ($offset + 1) : 0;
    $page_end = $total_matches > 0 ? ($offset + count($results)) : 0;

    return [
        'results' => $results,
        'total_matches' => $total_matches,
        'shown_count' => count($results),
        'has_more' => ($current_page < $total_pages),
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'per_page' => $per_page,
        'page_start' => $page_start,
        'page_end' => $page_end,
        'stale_refresh_count' => $stale_refresh_count,
        'stale_refresh_deferred_count' => $stale_refresh_deferred_count,
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
        $recording_ipa,
        $wordset_id
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
        'keyboard_symbols' => ll_tools_ipa_keyboard_get_keyboard_symbols($wordset_id, $transcription_mode),
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
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

add_action('wp_ajax_ll_tools_get_ipa_keyboard_orthography', 'll_tools_get_ipa_keyboard_orthography_handler');
function ll_tools_get_ipa_keyboard_orthography_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success(ll_tools_ipa_keyboard_build_orthography_response($wordset_id));
}

add_action('wp_ajax_ll_tools_update_ipa_keyboard_orthography_rule', 'll_tools_update_ipa_keyboard_orthography_rule_handler');
function ll_tools_update_ipa_keyboard_orthography_rule_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $segment = ll_tools_ipa_orthography_normalize_segment_key((string) ($_POST['segment'] ?? ''), 'ipa');
    $context = ll_tools_ipa_orthography_normalize_context((string) ($_POST['context'] ?? 'any'));
    if ($segment === '') {
        wp_send_json_error(__('Invalid segment', 'll-tools-text-domain'), 400);
    }

    $manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
    $clear = !empty($_POST['clear']);
    $output = ll_tools_ipa_orthography_sanitize_rule_output_text(
        (string) ($_POST['output'] ?? ''),
        ll_tools_ipa_orthography_get_wordset_language($wordset_id)
    );

    if ($clear || $output === '') {
        if (isset($manual_rules[$segment][$context])) {
            unset($manual_rules[$segment][$context]);
        }
        if (empty($manual_rules[$segment])) {
            unset($manual_rules[$segment]);
        }
    } else {
        if (!isset($manual_rules[$segment])) {
            $manual_rules[$segment] = [];
        }
        $manual_rules[$segment][$context] = $output;
    }

    $manual_rules = ll_tools_ipa_orthography_sanitize_manual_rules($manual_rules, $wordset_id);
    if (empty($manual_rules)) {
        delete_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), $manual_rules);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success(ll_tools_ipa_keyboard_build_orthography_response($wordset_id));
}

add_action('wp_ajax_ll_tools_block_ipa_keyboard_orthography_rule', 'll_tools_block_ipa_keyboard_orthography_rule_handler');
function ll_tools_block_ipa_keyboard_orthography_rule_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $segment = ll_tools_ipa_orthography_normalize_segment_key((string) ($_POST['segment'] ?? ''), 'ipa');
    $context = ll_tools_ipa_orthography_normalize_context((string) ($_POST['context'] ?? 'any'));
    $output = ll_tools_ipa_orthography_sanitize_rule_output_text(
        (string) ($_POST['output'] ?? ''),
        ll_tools_ipa_orthography_get_wordset_language($wordset_id)
    );
    if ($segment === '' || $output === '') {
        wp_send_json_error(__('Invalid rule', 'll-tools-text-domain'), 400);
    }

    $blocklist = ll_tools_ipa_orthography_get_blocklist($wordset_id);
    if (!isset($blocklist[$segment])) {
        $blocklist[$segment] = [];
    }
    if (!isset($blocklist[$segment][$context])) {
        $blocklist[$segment][$context] = [];
    }
    if (!in_array($output, $blocklist[$segment][$context], true)) {
        $blocklist[$segment][$context][] = $output;
    }
    $blocklist = ll_tools_ipa_orthography_sanitize_blocklist($blocklist, $wordset_id);

    if (empty($blocklist)) {
        delete_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key(), $blocklist);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success(ll_tools_ipa_keyboard_build_orthography_response($wordset_id));
}

add_action('wp_ajax_ll_tools_unblock_ipa_keyboard_orthography_rule', 'll_tools_unblock_ipa_keyboard_orthography_rule_handler');
function ll_tools_unblock_ipa_keyboard_orthography_rule_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $segment = ll_tools_ipa_orthography_normalize_segment_key((string) ($_POST['segment'] ?? ''), 'ipa');
    $context = ll_tools_ipa_orthography_normalize_context((string) ($_POST['context'] ?? 'any'));
    $output = ll_tools_ipa_orthography_sanitize_rule_output_text(
        (string) ($_POST['output'] ?? ''),
        ll_tools_ipa_orthography_get_wordset_language($wordset_id)
    );
    if ($segment === '' || $output === '') {
        wp_send_json_error(__('Invalid rule', 'll-tools-text-domain'), 400);
    }

    $blocklist = ll_tools_ipa_orthography_get_blocklist($wordset_id);
    if (!empty($blocklist[$segment][$context])) {
        $blocklist[$segment][$context] = array_values(array_filter(
            (array) $blocklist[$segment][$context],
            static function ($entry) use ($output): bool {
                return (string) $entry !== $output;
            }
        ));
        if (empty($blocklist[$segment][$context])) {
            unset($blocklist[$segment][$context]);
        }
        if (empty($blocklist[$segment])) {
            unset($blocklist[$segment]);
        }
    }

    $blocklist = ll_tools_ipa_orthography_sanitize_blocklist($blocklist, $wordset_id);
    if (empty($blocklist)) {
        delete_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key());
    } else {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_blocklist_meta_key(), $blocklist);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success(ll_tools_ipa_keyboard_build_orthography_response($wordset_id));
}

add_action('wp_ajax_ll_tools_toggle_ipa_keyboard_orthography_exception', 'll_tools_toggle_ipa_keyboard_orthography_exception_handler');
function ll_tools_toggle_ipa_keyboard_orthography_exception_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $word_id = (int) ($_POST['word_id'] ?? 0);
    $enabled = !empty($_POST['enabled']);
    if ($wordset_id <= 0 || $word_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    $word = get_post($word_id);
    if (!($word instanceof WP_Post) || $word->post_type !== 'words' || !has_term($wordset_id, 'wordset', $word_id)) {
        wp_send_json_error(__('Invalid word', 'll-tools-text-domain'), 400);
    }

    ll_tools_ipa_orthography_update_exception_word_id($wordset_id, $word_id, $enabled);
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success(ll_tools_ipa_keyboard_build_orthography_response($wordset_id));
}

add_action('wp_ajax_ll_tools_apply_ipa_keyboard_orthography_suggestion', 'll_tools_apply_ipa_keyboard_orthography_suggestion_handler');
function ll_tools_apply_ipa_keyboard_orthography_suggestion_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    if ($wordset_id <= 0 || $recording_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $result = ll_tools_ipa_orthography_apply_suggestion_to_recording(
        $wordset_id,
        $recording_id,
        ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id)
    );
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message(), 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    $response = ll_tools_ipa_keyboard_build_orthography_response($wordset_id);
    $response['applied_suggestion'] = $result;
    wp_send_json_success($response);
}

add_action('wp_ajax_ll_tools_convert_ipa_keyboard_orthography_words', 'll_tools_convert_ipa_keyboard_orthography_words_handler');
function ll_tools_convert_ipa_keyboard_orthography_words_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $raw_word_ids = isset($_POST['word_ids']) ? wp_unslash($_POST['word_ids']) : [];
    if (!is_array($raw_word_ids)) {
        $raw_word_ids = [];
    }
    $word_ids = array_values(array_unique(array_filter(array_map('intval', $raw_word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if (empty($word_ids)) {
        wp_send_json_error(__('No words selected', 'll-tools-text-domain'), 400);
    }

    $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);

    $converted = [];
    $errors = [];
    foreach ($word_ids as $word_id) {
        $result = ll_tools_ipa_orthography_apply_conversion_to_word($wordset_id, $word_id, $engine_rules);
        if (is_wp_error($result)) {
            $errors[] = [
                'word_id' => $word_id,
                'message' => $result->get_error_message(),
            ];
            continue;
        }
        $converted[] = $result;
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    $response = ll_tools_ipa_keyboard_build_orthography_response($wordset_id);
    $response['converted'] = $converted;
    $response['errors'] = $errors;
    $response['converted_count'] = count($converted);
    $response['error_count'] = count($errors);
    wp_send_json_success($response);
}

add_action('wp_ajax_ll_tools_update_recording_ipa', 'll_tools_update_recording_ipa_handler');
function ll_tools_update_recording_ipa_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($recording_id <= 0 || $wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [
        'recording_ipa' => (string) ($_POST['recording_ipa'] ?? ''),
    ]);
    if (empty($payload)) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success($payload);
}

add_action('wp_ajax_ll_tools_search_ipa_keyboard_recordings', 'll_tools_search_ipa_keyboard_recordings_handler');
function ll_tools_search_ipa_keyboard_recordings_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_view_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $query = ll_tools_ipa_keyboard_sanitize_search_query($_POST['query'] ?? '');
    $scope = sanitize_key((string) ($_POST['scope'] ?? 'both'));
    if (!in_array($scope, ['written', 'transcription', 'both'], true)) {
        $scope = 'both';
    }
    $issues_only = !empty($_POST['issues_only']);
    $review_only = !empty($_POST['review_only']);
    $exact_transcription = !empty($_POST['exact_transcription']);
    $search_page = ll_tools_ipa_keyboard_sanitize_search_page($_POST['search_page'] ?? 1);
    $per_page = isset($_POST['per_page'])
        ? ll_tools_ipa_keyboard_sanitize_search_per_page(wp_unslash($_POST['per_page']))
        : 0;
    $results = ll_tools_ipa_keyboard_search_recordings($wordset_id, $query, $scope, $issues_only, $review_only, $exact_transcription, $search_page, $per_page);
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
        'current_page' => (int) ($results['current_page'] ?? 1),
        'total_pages' => (int) ($results['total_pages'] ?? 1),
        'per_page' => (int) ($results['per_page'] ?? ll_tools_ipa_keyboard_get_search_results_per_page()),
        'page_start' => (int) ($results['page_start'] ?? 0),
        'page_end' => (int) ($results['page_end'] ?? 0),
        'issues_only' => $issues_only,
        'review_only' => $review_only,
        'exact_transcription' => $exact_transcription,
        'can_edit' => ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id),
        'validation_config' => ll_tools_ipa_keyboard_build_validation_config_payload($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_update_ipa_keyboard_recording', 'll_tools_update_ipa_keyboard_recording_handler');
function ll_tools_update_ipa_keyboard_recording_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($recording_id <= 0 || $wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    $payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, [
        'recording_text' => (string) ($_POST['recording_text'] ?? ''),
        'recording_ipa' => (string) ($_POST['recording_ipa'] ?? ''),
    ]);
    if (empty($payload)) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    ll_tools_ipa_keyboard_remember_wordset($wordset_id);
    wp_send_json_success($payload);
}

add_action('wp_ajax_ll_tools_save_ipa_keyboard_validation_config', 'll_tools_save_ipa_keyboard_validation_config_handler');
function ll_tools_save_ipa_keyboard_validation_config_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    if ((string) ($transcription['mode'] ?? 'ipa') !== 'ipa') {
        wp_send_json_error(__('Rules unavailable', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $rule_key = sanitize_text_field((string) ($_POST['rule_key'] ?? ''));
    $enabled = !empty($_POST['enabled']);
    if ($recording_id <= 0 || $wordset_id <= 0 || $rule_key === '' || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    $payload = ll_tools_ipa_keyboard_update_recording_fields($recording_id, $wordset_id, []);
    if (empty($payload)) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
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

add_action('wp_ajax_ll_tools_approve_ipa_keyboard_symbol_mapping', 'll_tools_approve_ipa_keyboard_symbol_mapping_handler');
function ll_tools_approve_ipa_keyboard_symbol_mapping_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $symbol = isset($_POST['symbol'])
        ? sanitize_text_field((string) wp_unslash($_POST['symbol']))
        : '';
    $submitted_output = isset($_POST['output'])
        ? sanitize_text_field((string) wp_unslash($_POST['output']))
        : '';

    if ($recording_id <= 0 || $wordset_id <= 0 || $symbol === '' || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }

    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    if ((string) ($transcription['mode'] ?? 'ipa') !== 'ipa') {
        wp_send_json_error(__('Rules unavailable', 'll-tools-text-domain'), 400);
    }

    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || !in_array($wordset_id, array_map('intval', (array) $wordset_ids), true)) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    $recording_text = (string) get_post_meta($recording_id, 'recording_text', true);
    $recording_ipa = (string) get_post_meta($recording_id, 'recording_ipa', true);
    $inferred_output = ll_tools_ipa_keyboard_infer_orthography_output_for_ipa_symbol(
        $wordset_id,
        $recording_text,
        $recording_ipa,
        $symbol
    );
    $output = $inferred_output !== '' ? $inferred_output : $submitted_output;
    if ($output === '') {
        wp_send_json_error(__('Could not infer an orthography mapping for this symbol.', 'll-tools-text-domain'), 400);
    }

    $approval = ll_tools_ipa_keyboard_approve_ipa_symbol_mapping($wordset_id, $symbol, $output);
    if ((string) ($approval['symbol'] ?? '') === '' || (string) ($approval['output'] ?? '') === '') {
        wp_send_json_error(__('Could not approve this symbol mapping.', 'll-tools-text-domain'), 400);
    }

    $rescanned_count = ll_tools_ipa_keyboard_rescan_wordset_validations($wordset_id);
    $word_display = ll_tools_ipa_keyboard_get_word_display_map([$word_id]);
    $payload = ll_tools_ipa_keyboard_build_search_row_payload(
        $recording_id,
        $wordset_id,
        (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => ''])
    );
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'recording' => $payload,
        'approved_symbol' => (string) ($approval['symbol'] ?? ''),
        'orthography_output' => (string) ($approval['output'] ?? ''),
        'approved_ipa_symbols' => array_values((array) ($approval['approved_symbols'] ?? [])),
        'rescanned_count' => $rescanned_count,
        'validation_config' => ll_tools_ipa_keyboard_build_validation_config_payload($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_flag_ipa_keyboard_illegal_symbol', 'll_tools_flag_ipa_keyboard_illegal_symbol_handler');
function ll_tools_flag_ipa_keyboard_illegal_symbol_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $transcription = ll_tools_ipa_keyboard_get_transcription_config($wordset_id);
    $mode = (string) ($transcription['mode'] ?? 'ipa');
    if ($mode !== 'ipa') {
        wp_send_json_error(__('Rules unavailable', 'll-tools-text-domain'), 400);
    }

    $raw_symbol = isset($_POST['symbol']) ? (string) wp_unslash($_POST['symbol']) : '';
    $symbol = function_exists('ll_tools_sanitize_secondary_text_keyboard_symbol')
        ? ll_tools_sanitize_secondary_text_keyboard_symbol($raw_symbol, $mode)
        : ll_tools_ipa_keyboard_normalize_ipa_token($raw_symbol, $mode);
    if ($symbol === '') {
        wp_send_json_error(__('Invalid symbol', 'll-tools-text-domain'), 400);
    }

    $illegal_symbols = function_exists('ll_tools_add_wordset_secondary_text_illegal_symbol')
        ? ll_tools_add_wordset_secondary_text_illegal_symbol($wordset_id, $symbol, $mode)
        : [];
    if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_special_chars')) {
        ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
    }
    $rescanned_count = ll_tools_ipa_keyboard_rescan_wordset_validations($wordset_id);
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'symbol' => $symbol,
        'illegal_symbols' => array_values((array) $illegal_symbols),
        'rescanned_count' => $rescanned_count,
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
        'validation_config' => ll_tools_ipa_keyboard_build_validation_config_payload($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_confirm_ipa_keyboard_transcription_review', 'll_tools_set_ipa_keyboard_transcription_review_state_handler');
add_action('wp_ajax_ll_tools_set_ipa_keyboard_transcription_review_state', 'll_tools_set_ipa_keyboard_transcription_review_state_handler');
function ll_tools_set_ipa_keyboard_transcription_review_state_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $needs_review = !empty($_POST['needs_review']);
    $review_field = isset($_POST['review_field'])
        ? ll_tools_ipa_keyboard_normalize_review_field((string) wp_unslash($_POST['review_field']))
        : 'recording_ipa';
    $review_note = isset($_POST['review_note'])
        ? sanitize_textarea_field((string) wp_unslash($_POST['review_note']))
        : '';
    if ($recording_id <= 0 || $wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Missing data', 'll-tools-text-domain'), 400);
    }
    if ($review_field === '') {
        wp_send_json_error(__('Invalid review field', 'll-tools-text-domain'), 400);
    }

    $recording = get_post($recording_id);
    if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio') {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || !in_array($wordset_id, array_map('intval', (array) $wordset_ids), true)) {
        wp_send_json_error(__('Invalid recording', 'll-tools-text-domain'), 400);
    }

    ll_tools_ipa_keyboard_set_recording_review_state($recording_id, $needs_review, $review_field, $review_note);
    if ($review_field === 'recording_ipa' && function_exists('ll_tools_word_grid_rebuild_wordset_ipa_special_chars')) {
        ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
    }
    $word_display = ll_tools_ipa_keyboard_get_word_display_map([$word_id]);
    $payload = ll_tools_ipa_keyboard_build_search_row_payload(
        $recording_id,
        $wordset_id,
        (array) ($word_display[$word_id] ?? ['word_text' => '', 'translation' => ''])
    );
    ll_tools_ipa_keyboard_remember_wordset($wordset_id);

    wp_send_json_success([
        'recording' => $payload,
        'transcription' => ll_tools_ipa_keyboard_get_transcription_config($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_add_wordset_ipa_symbols', 'll_tools_add_wordset_ipa_symbols_handler');
function ll_tools_add_wordset_ipa_symbols_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    if (!ll_tools_ipa_keyboard_get_wordset_term($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $symbols = ll_tools_ipa_keyboard_prepare_add_symbols((string) ($_POST['symbols'] ?? ''), $transcription_mode);
    if (empty($symbols)) {
        wp_send_json_error(__('No symbols found', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $letter_raw = (string) ($_POST['letter'] ?? '');
    $letter = ll_tools_ipa_keyboard_normalize_letter_key($letter_raw, $wordset_language);
    if ($letter === '') {
        wp_send_json_error(__('Invalid letter', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Invalid mapping', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0 || !ll_tools_ipa_keyboard_current_user_can_edit_wordset($wordset_id)) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
    }

    $wordset = ll_tools_ipa_keyboard_get_wordset_term($wordset_id);
    if (!$wordset) {
        wp_send_json_error(__('Invalid word set', 'll-tools-text-domain'), 400);
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
        wp_send_json_error(__('Invalid mapping', 'll-tools-text-domain'), 400);
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
