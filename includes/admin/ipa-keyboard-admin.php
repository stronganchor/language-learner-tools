<?php
/**
 * Admin page for managing IPA keyboard symbols by word set.
 */

if (!defined('WPINC')) { die; }

function ll_register_ipa_keyboard_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - IPA Keyboard',
        'LL IPA Keyboard',
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
            'loading' => __('Loading IPA symbols...', 'll-tools-text-domain'),
            'empty' => __('No IPA symbols found for this word set.', 'll-tools-text-domain'),
            'saving' => __('Saving...', 'll-tools-text-domain'),
            'saved' => __('Saved.', 'll-tools-text-domain'),
            'error' => __('Something went wrong. Please try again.', 'll-tools-text-domain'),
            'addSuccess' => __('Symbols added.', 'll-tools-text-domain'),
            'selectWordset' => __('Select a word set first.', 'll-tools-text-domain'),
            'enterSymbols' => __('Enter one or more symbols to add.', 'll-tools-text-domain'),
            'noRecordings' => __('No recordings use this symbol yet.', 'll-tools-text-domain'),
            'save' => __('Save', 'll-tools-text-domain'),
            'mapEmpty' => __('No letter mappings found for this word set.', 'll-tools-text-domain'),
            'mapAutoEmpty' => __('No mappings yet.', 'll-tools-text-domain'),
            'mapLetterLabel' => __('Letter(s)', 'll-tools-text-domain'),
            'mapAutoLabel' => __('Auto map', 'll-tools-text-domain'),
            'mapManualLabel' => __('Manual override', 'll-tools-text-domain'),
            'mapPlaceholder' => __('e.g. r', 'll-tools-text-domain'),
            'mapClear' => __('Clear', 'll-tools-text-domain'),
            'mapAddLabel' => __('Add manual mapping', 'll-tools-text-domain'),
            'mapAddLettersLabel' => __('Letters', 'll-tools-text-domain'),
            'mapAddSymbolsLabel' => __('IPA symbols', 'll-tools-text-domain'),
            'mapAddLettersPlaceholder' => __('Letters (e.g. ll)', 'll-tools-text-domain'),
            'mapAddSymbolsPlaceholder' => __('IPA symbols (e.g. r)', 'll-tools-text-domain'),
            'mapAddHint' => __('Use multiple letters to map digraphs like ll.', 'll-tools-text-domain'),
            'mapAdd' => __('Add mapping', 'll-tools-text-domain'),
            'mapAddMissing' => __('Enter letters and IPA symbols to add.', 'll-tools-text-domain'),
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

    echo '<div class="wrap ll-ipa-admin">';
    echo '<h1>' . esc_html__('IPA Keyboard Manager', 'll-tools-text-domain') . '</h1>';
    echo '<p class="description">' . esc_html__('Review IPA symbols per word set, update recordings, and add new keyboard symbols.', 'll-tools-text-domain') . '</p>';

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
    echo '<label for="ll-ipa-add-input">' . esc_html__('Add symbols', 'll-tools-text-domain') . '</label>';
    echo '<input type="text" id="ll-ipa-add-input" class="ll-ipa-add-input" placeholder="' . esc_attr__('e.g. IPA symbols', 'll-tools-text-domain') . '" />';
    echo '<button type="button" class="button button-secondary" id="ll-ipa-add-btn">' . esc_html__('Add', 'll-tools-text-domain') . '</button>';
    echo '</div>';

    echo '<div id="ll-ipa-admin-status" class="ll-ipa-admin-status" role="status" aria-live="polite"></div>';
    echo '<div class="ll-ipa-admin-sections">';
    echo '<div class="ll-ipa-admin-section ll-ipa-admin-section--symbols">';
    echo '<h2>' . esc_html__('IPA Special Characters', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Symbols used in this word set. Update recordings or add new symbols to the keyboard.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-symbols" class="ll-ipa-symbols"></div>';
    echo '</div>';
    echo '<div class="ll-ipa-admin-section ll-ipa-admin-section--letter-map">';
    echo '<h2>' . esc_html__('Letter to IPA Map', 'll-tools-text-domain') . '</h2>';
    echo '<p class="description">' . esc_html__('Mappings inferred from transcriptions. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain') . '</p>';
    echo '<div id="ll-ipa-letter-map" class="ll-ipa-letter-map"></div>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
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

function ll_tools_ipa_keyboard_get_default_symbols(): array {
    return ['t͡ʃ', 'd͡ʒ', 'ʃ', 'ˈ'];
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

function ll_tools_ipa_keyboard_build_symbol_data(int $wordset_id): array {
    $word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return [];
    }

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
        $recording_ipa = ll_tools_word_grid_normalize_ipa_output($recording_ipa_raw);
        if ($recording_ipa === '') {
            continue;
        }

        $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
            ? ll_tools_word_grid_tokenize_ipa($recording_ipa)
            : preg_split('//u', $recording_ipa, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            continue;
        }

        $special_tokens = [];
        foreach ($tokens as $token) {
            if (function_exists('ll_tools_word_grid_is_special_ipa_token')) {
                if (!ll_tools_word_grid_is_special_ipa_token($token)) {
                    continue;
                }
            }
            $special_tokens[] = $token;
            if (!isset($counts[$token])) {
                $counts[$token] = 0;
            }
            $counts[$token] += 1;
        }

        if (empty($special_tokens)) {
            continue;
        }

        $unique_tokens = array_values(array_unique($special_tokens));
        $recording_type_terms = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'names']);
        $recording_type = (!is_wp_error($recording_type_terms) && !empty($recording_type_terms))
            ? (string) $recording_type_terms[0]
            : '';

        $word_info = $word_display[$word_id] ?? ['word_text' => '', 'translation' => ''];

        $recording_payload = [
            'recording_id' => $recording_id,
            'word_id' => (int) $word_id,
            'word_text' => (string) ($word_info['word_text'] ?? ''),
            'word_translation' => (string) ($word_info['translation'] ?? ''),
            'recording_type' => $recording_type,
            'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
            'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
            'recording_ipa' => $recording_ipa,
            'word_edit_link' => get_edit_post_link($word_id, 'raw'),
        ];

        foreach ($unique_tokens as $token) {
            if (!isset($recordings_by_symbol[$token])) {
                $recordings_by_symbol[$token] = [];
            }
            $recordings_by_symbol[$token][] = $recording_payload;
        }
    }

    $manual_symbols = function_exists('ll_tools_word_grid_get_wordset_ipa_manual_symbols')
        ? ll_tools_word_grid_get_wordset_ipa_manual_symbols($wordset_id)
        : [];

    $default_symbols = ll_tools_ipa_keyboard_get_default_symbols();
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

    $default_index = array_flip($default_symbols);
    usort($entries, function ($a, $b) use ($default_index) {
        $symbol_a = (string) ($a['symbol'] ?? '');
        $symbol_b = (string) ($b['symbol'] ?? '');
        $is_default_a = array_key_exists($symbol_a, $default_index);
        $is_default_b = array_key_exists($symbol_b, $default_index);
        if ($is_default_a || $is_default_b) {
            if ($is_default_a && $is_default_b) {
                return ($default_index[$symbol_a] <=> $default_index[$symbol_b]);
            }
            return $is_default_a ? -1 : 1;
        }
        $count_a = (int) ($a['count'] ?? 0);
        $count_b = (int) ($b['count'] ?? 0);
        if ($count_a === $count_b) {
            return strnatcasecmp($symbol_a, $symbol_b);
        }
        return ($count_b <=> $count_a);
    });

    return $entries;
}

function ll_tools_ipa_keyboard_normalize_letter_key(string $letter): string {
    $letter = function_exists('ll_tools_word_grid_lowercase') ? ll_tools_word_grid_lowercase($letter) : strtolower($letter);
    $letter = preg_replace('/[^\p{L}]+/u', '', $letter);
    return (string) $letter;
}

function ll_tools_ipa_keyboard_normalize_ipa_token(string $token): string {
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    if (function_exists('ll_tools_word_grid_normalize_ipa_output')) {
        $token = ll_tools_word_grid_normalize_ipa_output($token);
    }
    if (function_exists('ll_tools_word_grid_strip_ipa_stress_markers')) {
        $token = ll_tools_word_grid_strip_ipa_stress_markers($token);
    }
    $token = trim((string) $token);
    if (function_exists('ll_tools_word_grid_is_ipa_stress_marker')
        && ll_tools_word_grid_is_ipa_stress_marker($token)) {
        return '';
    }
    return $token;
}

function ll_tools_ipa_keyboard_build_letter_map_data(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $auto_map = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_map')
        ? ll_tools_word_grid_get_wordset_ipa_letter_map($wordset_id)
        : [];
    $manual_map = function_exists('ll_tools_word_grid_get_wordset_ipa_letter_manual_map')
        ? ll_tools_word_grid_get_wordset_ipa_letter_manual_map($wordset_id)
        : [];

    if (empty($auto_map) && empty($manual_map)) {
        return [];
    }

    $entries = [];

    $merge_auto = function ($letter, $ipa_counts) use (&$entries) {
        if (!is_array($ipa_counts)) {
            return;
        }
        $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) $letter);
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
            $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) $ipa);
            if ($ipa_key === '') {
                continue;
            }
            $entries[$letter_key]['auto_counts'][$ipa_key] = (int) ($entries[$letter_key]['auto_counts'][$ipa_key] ?? 0)
                + max(1, (int) $count);
        }
    };

    $merge_manual = function ($letter, $symbols) use (&$entries) {
        if (!is_array($symbols)) {
            return;
        }
        $letter_key = ll_tools_ipa_keyboard_normalize_letter_key((string) $letter);
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
            $ipa_key = ll_tools_ipa_keyboard_normalize_ipa_token((string) $symbol);
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

    $list = [];
    foreach ($entries as $entry) {
        $auto_entries = [];
        foreach ((array) ($entry['auto_counts'] ?? []) as $symbol => $count) {
            $auto_entries[] = [
                'symbol' => $symbol,
                'count' => (int) $count,
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

function ll_tools_ipa_keyboard_prepare_add_symbols(string $input): array {
    $input = trim($input);
    if ($input === '') {
        return [];
    }

    if (function_exists('ll_tools_word_grid_sanitize_ipa')) {
        $input = ll_tools_word_grid_sanitize_ipa($input);
    }

    $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
        ? ll_tools_word_grid_tokenize_ipa($input)
        : preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($tokens)) {
        return [];
    }

    $clean = [];
    foreach ($tokens as $token) {
        if (function_exists('ll_tools_word_grid_is_special_ipa_token')
            && !ll_tools_word_grid_is_special_ipa_token($token)) {
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

    $symbols = ll_tools_ipa_keyboard_build_symbol_data($wordset_id);
    $letter_map = ll_tools_ipa_keyboard_build_letter_map_data($wordset_id);

    wp_send_json_success([
        'wordset' => [
            'id' => (int) $wordset_id,
            'name' => (string) $wordset->name,
        ],
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

    $raw = (string) ($_POST['recording_ipa'] ?? '');
    $clean = function_exists('ll_tools_word_grid_sanitize_ipa')
        ? ll_tools_word_grid_sanitize_ipa($raw)
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

    wp_send_json_success([
        'recording_id' => $recording_id,
        'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true)),
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

    $symbols = ll_tools_ipa_keyboard_prepare_add_symbols((string) ($_POST['symbols'] ?? ''));
    if (empty($symbols)) {
        wp_send_json_error('No symbols found', 400);
    }

    $existing = function_exists('ll_tools_word_grid_get_wordset_ipa_manual_symbols')
        ? ll_tools_word_grid_get_wordset_ipa_manual_symbols($wordset_id)
        : [];

    $merged = array_values(array_unique(array_merge($existing, $symbols)));
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

    $letter_raw = (string) ($_POST['letter'] ?? '');
    $letter = ll_tools_ipa_keyboard_normalize_letter_key($letter_raw);
    if ($letter === '') {
        wp_send_json_error('Invalid letter', 400);
    }

    $manual_map = get_term_meta($wordset_id, 'll_wordset_ipa_letter_manual_map', true);
    if (!is_array($manual_map)) {
        $manual_map = [];
    }

    $clear = !empty($_POST['clear']);
    $symbols_raw = trim((string) ($_POST['symbols'] ?? ''));

    if ($clear || $symbols_raw === '') {
        unset($manual_map[$letter]);
    } else {
        if (function_exists('ll_tools_word_grid_sanitize_ipa')) {
            $symbols_raw = ll_tools_word_grid_sanitize_ipa($symbols_raw);
        }
        $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
            ? ll_tools_word_grid_tokenize_ipa($symbols_raw)
            : preg_split('//u', $symbols_raw, -1, PREG_SPLIT_NO_EMPTY);

        $clean_tokens = [];
        $seen = [];
        foreach ((array) $tokens as $token) {
            $token = ll_tools_ipa_keyboard_normalize_ipa_token((string) $token);
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
