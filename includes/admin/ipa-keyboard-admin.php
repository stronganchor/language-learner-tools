<?php
/**
 * Admin page for managing recording transcription symbols by word set.
 */

if (!defined('WPINC')) { die; }

function ll_register_ipa_keyboard_admin_page() {
    add_submenu_page(
        'tools.php',
        __('Language Learner Tools - Transcription Keyboard', 'll-tools-text-domain'),
        __('LL Transcription Keyboard', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-ipa-keyboard',
        'll_render_ipa_keyboard_admin_page'
    );
}
add_action('admin_menu', 'll_register_ipa_keyboard_admin_page');

function ll_enqueue_ipa_keyboard_admin_assets($hook) {
    if ($hook !== 'tools_page_ll-ipa-keyboard') {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-ipa-fonts');
    ll_enqueue_asset_by_timestamp('/css/ipa-keyboard-admin.css', 'll-ipa-keyboard-admin-css', ['ll-ipa-fonts']);
    ll_enqueue_asset_by_timestamp('/js/ipa-keyboard-admin.js', 'll-ipa-keyboard-admin-js', ['jquery'], true);

    wp_localize_script('ll-ipa-keyboard-admin-js', 'llIpaKeyboardAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
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
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_enqueue_ipa_keyboard_admin_assets');

function ll_render_ipa_keyboard_admin_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'll-tools-text-domain'));
    }

    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    echo '<div class="wrap ll-ipa-admin" data-ll-secondary-text-mode="ipa">';
    echo '<h1 id="ll-ipa-admin-title">' . esc_html__('Transcription Keyboard Manager', 'll-tools-text-domain') . '</h1>';
    echo '<p class="description">' . esc_html__('Review pronunciation text per word set, update recordings, and add helper characters.', 'll-tools-text-domain') . '</p>';

    echo '<div class="ll-ipa-admin-controls">';
    echo '<label for="ll-ipa-wordset">' . esc_html__('Word set', 'll-tools-text-domain') . '</label>';
    echo '<select id="ll-ipa-wordset" class="ll-ipa-wordset-select">';
    echo '<option value="">' . esc_html__('Select a word set', 'll-tools-text-domain') . '</option>';
    if (!empty($wordsets) && !is_wp_error($wordsets)) {
        foreach ($wordsets as $wordset) {
            echo '<option value="' . esc_attr($wordset->term_id) . '">' . esc_html($wordset->name) . '</option>';
        }
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="ll-ipa-admin-add">';
    echo '<label id="ll-ipa-add-label" for="ll-ipa-add-input">' . esc_html__('Add characters', 'll-tools-text-domain') . '</label>';
    echo '<input type="text" id="ll-ipa-add-input" class="ll-ipa-add-input" placeholder="' . esc_attr__('e.g. special characters', 'll-tools-text-domain') . '" />';
    echo '<button type="button" class="button button-secondary" id="ll-ipa-add-btn">' . esc_html__('Add', 'll-tools-text-domain') . '</button>';
    echo '</div>';

    echo '<div id="ll-ipa-admin-status" class="ll-ipa-admin-status" role="status" aria-live="polite"></div>';
    echo '<div class="ll-ipa-admin-sections">';
    echo '<div class="ll-ipa-admin-section ll-ipa-admin-section--symbols">';
    echo '<h2 id="ll-ipa-symbols-heading">' . esc_html__('Special Characters', 'll-tools-text-domain') . '</h2>';
    echo '<p id="ll-ipa-symbols-description" class="description">' . esc_html__('Characters used in this word set. Update recordings or add new characters to the keyboard.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-symbols" class="ll-ipa-symbols"></div>';
    echo '</div>';
    echo '<div class="ll-ipa-admin-section ll-ipa-admin-section--letter-map">';
    echo '<h2 id="ll-ipa-letter-map-heading">' . esc_html__('Letter Map', 'll-tools-text-domain') . '</h2>';
    echo '<p id="ll-ipa-letter-map-description" class="description">' . esc_html__('Mappings inferred from this word set. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-letter-map" class="ll-ipa-letter-map"></div>';
    echo '</div>';
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

function ll_tools_ipa_keyboard_build_letter_map_data(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $wordset_language = function_exists('ll_tools_word_grid_get_wordset_ipa_language')
        ? ll_tools_word_grid_get_wordset_ipa_language($wordset_id)
        : '';
    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');

    if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
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

add_action('wp_ajax_ll_tools_get_ipa_keyboard_data', 'll_tools_get_ipa_keyboard_data_handler');
function ll_tools_get_ipa_keyboard_data_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset)) {
        wp_send_json_error('Invalid word set', 400);
    }

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
    if ($recording_id <= 0 || $wordset_id <= 0) {
        wp_send_json_error('Missing data', 400);
    }

    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio') {
        wp_send_json_error('Invalid recording', 400);
    }

    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        wp_send_json_error('Invalid word', 400);
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || !in_array($wordset_id, array_map('intval', $wordset_ids), true)) {
        wp_send_json_error('Word set mismatch', 400);
    }

    $transcription_mode = (string) (ll_tools_ipa_keyboard_get_transcription_config($wordset_id)['mode'] ?? 'ipa');
    $previous_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode);
    $previous_symbol_counts = ll_tools_ipa_keyboard_count_special_symbols($previous_ipa, $transcription_mode);
    $raw = (string) ($_POST['recording_ipa'] ?? '');
    $clean = function_exists('ll_tools_word_grid_sanitize_ipa')
        ? ll_tools_word_grid_sanitize_ipa($raw, $transcription_mode)
        : sanitize_text_field($raw);

    if ($clean !== '') {
        update_post_meta($recording_id, 'recording_ipa', $clean);
    } else {
        delete_post_meta($recording_id, 'recording_ipa');
    }

    if (function_exists('ll_tools_word_grid_update_wordset_ipa_special_chars')) {
        ll_tools_word_grid_update_wordset_ipa_special_chars($word_id, $clean);
    }
    if (function_exists('ll_tools_word_grid_rebuild_wordset_ipa_letter_map')) {
        ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    }

    $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true), $transcription_mode);
    $symbol_counts = ll_tools_ipa_keyboard_count_special_symbols($recording_ipa, $transcription_mode);
    $word_display = ll_tools_ipa_keyboard_get_word_display_map([$word_id]);
    $recording_payload = ll_tools_ipa_keyboard_build_recording_payload(
        $recording_id,
        $word_id,
        $word_display[$word_id] ?? ['word_text' => '', 'translation' => ''],
        $recording_ipa
    );

    wp_send_json_success([
        'recording_id' => $recording_id,
        'recording_ipa' => $recording_ipa,
        'recording' => $recording_payload,
        'previous_symbols' => array_keys($previous_symbol_counts),
        'previous_symbol_counts' => $previous_symbol_counts,
        'symbols' => array_keys($symbol_counts),
        'symbol_counts' => $symbol_counts,
        'letter_map' => ll_tools_ipa_keyboard_build_letter_map_data($wordset_id),
    ]);
}

add_action('wp_ajax_ll_tools_add_wordset_ipa_symbols', 'll_tools_add_wordset_ipa_symbols_handler');
function ll_tools_add_wordset_ipa_symbols_handler() {
    check_ajax_referer('ll_ipa_keyboard_admin', 'nonce');

    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0) {
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
    if ($wordset_id <= 0) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset)) {
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
    if ($wordset_id <= 0) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset)) {
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
    if ($wordset_id <= 0) {
        wp_send_json_error('Invalid word set', 400);
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!$wordset || is_wp_error($wordset)) {
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

    wp_send_json_success([
        'letter' => $letter,
        'symbol' => $symbol,
    ]);
}
