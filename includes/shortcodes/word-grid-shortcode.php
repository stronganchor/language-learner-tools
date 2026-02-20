<?php

function ll_tools_word_grid_collect_audio_files(array $word_ids, bool $include_meta = false): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_parent__in'=> $word_ids,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'suppress_filters' => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    $audio_by_word = [];
    foreach ($audio_posts as $audio_post) {
        $parent_id = (int) $audio_post->post_parent;
        if (!$parent_id) {
            continue;
        }

        $audio_path = get_post_meta($audio_post->ID, 'audio_file_path', true);
        if (!$audio_path) {
            continue;
        }
        $audio_url = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
        $recording_types = wp_get_post_terms($audio_post->ID, 'recording_type', ['fields' => 'slugs']);
        if (is_wp_error($recording_types) || empty($recording_types)) {
            continue;
        }

        $speaker_uid = (int) get_post_meta($audio_post->ID, 'speaker_user_id', true);
        if (!$speaker_uid) {
            $speaker_uid = (int) $audio_post->post_author;
        }

        $recording_text = '';
        $recording_translation = '';
        $recording_ipa = '';
        if ($include_meta) {
            $recording_text = get_post_meta($audio_post->ID, 'recording_text', true);
            $recording_translation = get_post_meta($audio_post->ID, 'recording_translation', true);
            $recording_ipa = get_post_meta($audio_post->ID, 'recording_ipa', true);
        }

        foreach ($recording_types as $type) {
            $type = sanitize_text_field($type);
            if ($type === '') {
                continue;
            }
            $entry = [
                'id'              => (int) $audio_post->ID,
                'url'             => $audio_url,
                'recording_type'  => $type,
                'speaker_user_id' => $speaker_uid,
            ];
            if ($include_meta) {
                $entry['recording_text'] = $recording_text;
                $entry['recording_translation'] = $recording_translation;
                $entry['recording_ipa'] = $recording_ipa;
            }
            $audio_by_word[$parent_id][] = $entry;
        }
    }

    return $audio_by_word;
}

function ll_tools_word_grid_get_preferred_speaker(array $audio_files, array $main_types): int {
    if (empty($audio_files) || empty($main_types)) {
        return 0;
    }

    $by_speaker = [];
    foreach ($audio_files as $file) {
        $uid = isset($file['speaker_user_id']) ? (int) $file['speaker_user_id'] : 0;
        $type = isset($file['recording_type']) ? (string) $file['recording_type'] : '';
        if (!$uid || $type === '') {
            continue;
        }
        $by_speaker[$uid][$type] = true;
    }

    foreach ($by_speaker as $uid => $types) {
        if (!array_diff($main_types, array_keys($types))) {
            return (int) $uid;
        }
    }

    return 0;
}

function ll_tools_word_grid_select_audio_entry(array $audio_files, string $type, int $preferred_speaker): array {
    $fallback = [];
    foreach ($audio_files as $file) {
        if (empty($file['recording_type']) || $file['recording_type'] !== $type || empty($file['url'])) {
            continue;
        }
        if (!$fallback) {
            $fallback = $file;
        }
        $speaker_uid = isset($file['speaker_user_id']) ? (int) $file['speaker_user_id'] : 0;
        if ($preferred_speaker && $speaker_uid === $preferred_speaker) {
            return $file;
        }
    }
    return $fallback ? (array) $fallback : [];
}

function ll_tools_word_grid_select_audio_url(array $audio_files, string $type, int $preferred_speaker): string {
    $entry = ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
    return isset($entry['url']) ? (string) $entry['url'] : '';
}

function ll_tools_word_grid_supports_ipa_extended(): bool {
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }

    global $wpdb;
    if (!$wpdb || !method_exists($wpdb, 'has_cap') || !$wpdb->has_cap('utf8mb4')) {
        $supports = false;
        return $supports;
    }

    if (!empty($wpdb->charset) && stripos((string) $wpdb->charset, 'utf8mb4') === false) {
        $supports = false;
        return $supports;
    }

    if (method_exists($wpdb, 'get_col_charset')) {
        $charset = $wpdb->get_col_charset($wpdb->postmeta, 'meta_value');
        if ($charset && stripos((string) $charset, 'utf8mb4') === false) {
            $supports = false;
            return $supports;
        }
    }

    $supports = true;
    return $supports;
}

function ll_tools_word_grid_normalize_ipa_text(string $text): string {
    if ($text === '') {
        return '';
    }

    $text = preg_replace_callback('/<\s*sup[^>]*>(.*?)<\/\s*sup>/iu', function ($matches) {
        $content = trim($matches[1] ?? '');
        if ($content === '') {
            return '';
        }
        if (preg_match('/^[Bb\x{0299}\x{1D2E}\x{10784}]$/u', $content)) {
            return "\u{10784}";
        }
        return $content;
    }, $text);

    $text = wp_strip_all_tags($text);
    $text = str_replace("\u{1D2E}", "\u{10784}", $text);

    return $text;
}

function ll_tools_word_grid_normalize_ipa_output(string $text): string {
    $text = ll_tools_word_grid_normalize_ipa_text($text);
    return trim($text);
}

function ll_tools_word_grid_normalize_ipa_input(string $text): string {
    if ($text === '') {
        return '';
    }

    $text = ll_tools_word_grid_normalize_ipa_text($text);

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return $text;
    }

    $out = '';
    foreach ($chars as $char) {
        if ($char === "'" || $char === '’') {
            $out .= "\u{02C8}";
            continue;
        }
        if (strlen($char) === 1) {
            $ord = ord($char);
            if ($ord >= 65 && $ord <= 90) {
                if ($char === 'R') {
                    $out .= "\u{0280}";
                    continue;
                }
                if ($char === 'B') {
                    $out .= "\u{0299}";
                    continue;
                }
                if ($char === 'G') {
                    $out .= "\u{0262}";
                    continue;
                }
                $out .= strtolower($char);
                continue;
            }
        }
        $out .= $char;
    }

    return $out;
}

function ll_tools_word_grid_sanitize_ipa(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $text = ll_tools_word_grid_normalize_ipa_input($text);

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    foreach ($chars as $char) {
        if ($char === "\u{10784}" || $char === '.') {
            $out .= $char;
            continue;
        }
        if (ll_tools_word_grid_is_ipa_combining_mark($char)) {
            $out .= $char;
            continue;
        }
        if (preg_match('/\s/u', $char)) {
            $out .= ' ';
            continue;
        }
        if (preg_match('/[a-z]/u', $char)) {
            $out .= $char;
            continue;
        }
        if (preg_match('/[\x{00C0}-\x{02FF}\x{0370}-\x{03FF}\x{1D00}-\x{1DFF}]/u', $char)) {
            $out .= $char;
        }
    }

    $out = preg_replace('/\s+/u', ' ', $out);
    $out = trim((string) $out);

    if (!ll_tools_word_grid_supports_ipa_extended()) {
        $out = str_replace("\u{10784}", "\u{1D2E}", $out);
    }

    return $out;
}

function ll_tools_word_grid_is_ipa_separator(string $char): bool {
    return $char === '.' || preg_match('/\s/u', $char);
}

function ll_tools_word_grid_is_ipa_combining_mark(string $char): bool {
    return preg_match('/[\x{0300}-\x{036F}]/u', $char) === 1;
}

function ll_tools_word_grid_is_ipa_post_modifier(string $char): bool {
    return preg_match('/[\x{02B0}-\x{02B8}\x{02D0}\x{02D1}\x{02E0}-\x{02E4}\x{1D2C}-\x{1D6A}\x{1D9B}-\x{1DBF}\x{2070}-\x{209F}\x{10784}]/u', $char) === 1;
}

function ll_tools_word_grid_is_ipa_stress_marker(string $char): bool {
    return $char === "\u{02C8}" || $char === "\u{02CC}";
}

function ll_tools_word_grid_strip_ipa_stress_markers(string $token): string {
    if ($token === '') {
        return '';
    }
    return str_replace(["\u{02C8}", "\u{02CC}"], '', $token);
}

function ll_tools_word_grid_clean_ipa_letter_map(array $map): array {
    $cleaned = [];
    foreach ($map as $letter => $ipa_counts) {
        if (!is_array($ipa_counts)) {
            continue;
        }
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        if ($letter_key === '') {
            continue;
        }
        foreach ($ipa_counts as $ipa => $count) {
            $ipa_key = ll_tools_word_grid_normalize_ipa_output((string) $ipa);
            $ipa_key = ll_tools_word_grid_strip_ipa_stress_markers($ipa_key);
            $ipa_key = trim($ipa_key);
            if ($ipa_key === '') {
                continue;
            }
            if (!isset($cleaned[$letter_key])) {
                $cleaned[$letter_key] = [];
            }
            if (!isset($cleaned[$letter_key][$ipa_key])) {
                $cleaned[$letter_key][$ipa_key] = 0;
            }
            $cleaned[$letter_key][$ipa_key] += max(1, (int) $count);
        }
    }
    return $cleaned;
}

function ll_tools_word_grid_clean_ipa_letter_blocklist(array $blocklist): array {
    $cleaned = [];
    foreach ($blocklist as $letter => $symbols) {
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        $letter_key = preg_replace('/[^\p{L}]+/u', '', $letter_key);
        if ($letter_key === '') {
            continue;
        }

        $tokens = [];
        if (is_array($symbols)) {
            $tokens = $symbols;
        } elseif (is_string($symbols) && $symbols !== '') {
            $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
                ? ll_tools_word_grid_tokenize_ipa($symbols)
                : preg_split('//u', $symbols, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (empty($tokens)) {
            continue;
        }

        $seen = [];
        $clean_tokens = [];
        foreach ($tokens as $token) {
            $token = ll_tools_word_grid_normalize_ipa_output((string) $token);
            $token = ll_tools_word_grid_strip_ipa_stress_markers($token);
            $token = trim($token);
            if ($token === '' || isset($seen[$token]) || ll_tools_word_grid_is_ipa_stress_marker($token)) {
                continue;
            }
            $seen[$token] = true;
            $clean_tokens[] = $token;
        }
        if (empty($clean_tokens)) {
            continue;
        }
        $cleaned[$letter_key] = $clean_tokens;
    }
    return $cleaned;
}

function ll_tools_word_grid_filter_ipa_letter_map_with_blocklist(array $auto_map, array $blocklist): array {
    $cleaned = ll_tools_word_grid_clean_ipa_letter_map($auto_map);
    if (empty($blocklist)) {
        return $cleaned;
    }
    $blocked = ll_tools_word_grid_clean_ipa_letter_blocklist($blocklist);
    if (empty($blocked)) {
        return $cleaned;
    }
    foreach ($blocked as $letter => $symbols) {
        if (!isset($cleaned[$letter]) || !is_array($symbols)) {
            continue;
        }
        foreach ($symbols as $symbol) {
            unset($cleaned[$letter][$symbol]);
        }
        if (empty($cleaned[$letter])) {
            unset($cleaned[$letter]);
        }
    }
    return $cleaned;
}

function ll_tools_word_grid_format_ipa_display_html(string $ipa): string {
    $ipa = ll_tools_word_grid_normalize_ipa_output($ipa);
    if ($ipa === '') {
        return '';
    }

    return esc_html($ipa);
}

function ll_tools_word_grid_is_ipa_tie_bar(string $char): bool {
    return preg_match('/[\x{035C}\x{0361}]/u', $char) === 1;
}

function ll_tools_word_grid_tokenize_ipa(string $text): array {
    $text = ll_tools_word_grid_sanitize_ipa($text);
    if ($text === '') {
        return [];
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return [];
    }

    $tokens = [];
    $buffer = '';
    $pending = '';
    $tie_pending = false;

    foreach ($chars as $char) {
        if (ll_tools_word_grid_is_ipa_separator($char)) {
            if ($buffer !== '') {
                $tokens[] = $buffer;
                $buffer = '';
            }
            $pending = '';
            $tie_pending = false;
            continue;
        }

        if (ll_tools_word_grid_is_ipa_combining_mark($char)) {
            if ($buffer === '') {
                $pending .= $char;
            } else {
                $buffer .= $char;
            }
            if (ll_tools_word_grid_is_ipa_tie_bar($char) && $buffer !== '') {
                $tie_pending = true;
            }
            continue;
        }

        if (ll_tools_word_grid_is_ipa_post_modifier($char)) {
            if ($buffer !== '') {
                $buffer .= $char;
                continue;
            }
            if ($pending !== '') {
                $buffer = $pending . $char;
                $pending = '';
                $tie_pending = false;
                continue;
            }
            $buffer = $char;
            $tie_pending = false;
            continue;
        }

        if ($buffer === '') {
            $buffer = $pending . $char;
            $pending = '';
            $tie_pending = false;
            continue;
        }

        if ($tie_pending) {
            $buffer .= $char;
            $tie_pending = false;
            continue;
        }

        $tokens[] = $buffer;
        $buffer = $pending . $char;
        $pending = '';
        $tie_pending = false;
    }

    if ($buffer !== '') {
        $tokens[] = $buffer;
    }

    return $tokens;
}

function ll_tools_word_grid_is_special_ipa_token(string $token): bool {
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    if (ll_tools_word_grid_is_ipa_separator($token)) {
        return false;
    }
    if (preg_match('/[\x{0300}-\x{036F}]/u', $token)) {
        return true;
    }
    if (preg_match('/[^a-z]/u', $token)) {
        return true;
    }
    return false;
}

function ll_tools_word_grid_extract_ipa_special_chars(string $text): array {
    $tokens = ll_tools_word_grid_tokenize_ipa($text);
    if (empty($tokens)) {
        return [];
    }

    $special = [];
    foreach ($tokens as $token) {
        if (!ll_tools_word_grid_is_special_ipa_token($token)) {
            continue;
        }
        $special[$token] = true;
    }

    return array_keys($special);
}

function ll_tools_word_grid_parse_ipa_symbol_meta($raw): array {
    $tokens = [];
    if (is_string($raw)) {
        $tokens = ll_tools_word_grid_tokenize_ipa($raw);
    } elseif (is_array($raw)) {
        foreach ($raw as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            $entry_tokens = ll_tools_word_grid_tokenize_ipa($entry);
            if (!empty($entry_tokens)) {
                $tokens = array_merge($tokens, $entry_tokens);
            }
        }
    } else {
        return [];
    }

    if (empty($tokens)) {
        return [];
    }

    $cleaned = [];
    foreach ($tokens as $token) {
        if (!ll_tools_word_grid_is_special_ipa_token($token)) {
            continue;
        }
        $cleaned[$token] = true;
    }

    return array_keys($cleaned);
}

function ll_tools_word_grid_get_wordset_ipa_auto_symbols(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_special_chars', true);
    return ll_tools_word_grid_parse_ipa_symbol_meta($raw);
}

function ll_tools_word_grid_get_wordset_ipa_manual_symbols(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_manual_symbols', true);
    return ll_tools_word_grid_parse_ipa_symbol_meta($raw);
}

function ll_tools_word_grid_get_wordset_ipa_special_chars(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $auto = ll_tools_word_grid_get_wordset_ipa_auto_symbols($wordset_id);
    $manual = ll_tools_word_grid_get_wordset_ipa_manual_symbols($wordset_id);
    if (empty($auto) && empty($manual)) {
        return [];
    }

    return array_values(array_unique(array_merge($auto, $manual)));
}

function ll_tools_word_grid_rebuild_wordset_ipa_special_chars(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $word_ids = function_exists('ll_tools_ipa_keyboard_get_word_ids_for_wordset')
        ? ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id)
        : get_posts([
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

    if (empty($word_ids)) {
        update_term_meta($wordset_id, 'll_wordset_ipa_special_chars', []);
        return [];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
    ]);

    $symbols = [];
    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $recording_ipa = trim((string) get_post_meta($recording_id, 'recording_ipa', true));
        if ($recording_ipa === '') {
            continue;
        }
        $new_tokens = ll_tools_word_grid_extract_ipa_special_chars($recording_ipa);
        foreach ($new_tokens as $token) {
            $symbols[$token] = true;
        }
    }

    $symbols = array_keys($symbols);
    update_term_meta($wordset_id, 'll_wordset_ipa_special_chars', $symbols);
    return $symbols;
}

function ll_tools_word_grid_update_wordset_ipa_special_chars(int $word_id, string $ipa_text): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return;
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || empty($wordset_ids)) {
        return;
    }

    foreach ($wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }
        ll_tools_word_grid_rebuild_wordset_ipa_special_chars($wordset_id);
    }
}

function ll_tools_word_grid_lowercase(string $value): string {
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function ll_tools_word_grid_prepare_text_letters(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    $text = ll_tools_word_grid_lowercase($text);
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return [];
    }

    $letters = [];
    foreach ($chars as $char) {
        if (!preg_match('/\p{L}/u', $char)) {
            continue;
        }
        $letters[] = $char;
    }

    return $letters;
}

function ll_tools_word_grid_get_ipa_match_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        "\u{027E}" => 'r',
        "\u{0279}" => 'r',
        "\u{027B}" => 'r',
        "\u{0280}" => 'r',
        "\u{0281}" => 'r',
        "\u{027D}" => 'r',
        "\u{029C}" => 'h',
        "\u{0266}" => 'h',
        "\u{0283}" => 'sh',
        "\u{0292}" => 'zh',
        "\u{03B8}" => 'th',
        "\u{00F0}" => 'th',
        "\u{014B}" => 'ng',
        "\u{0272}" => 'ny',
        "\u{0250}" => 'a',
        "\u{0251}" => 'a',
        "\u{0252}" => 'o',
        "\u{00E6}" => 'a',
        "\u{025B}" => 'e',
        "\u{025C}" => 'e',
        "\u{0259}" => 'e',
        "\u{026A}" => 'i',
        "\u{028A}" => 'u',
        "\u{028C}" => 'u',
        "\u{0254}" => 'o',
        "\u{026F}" => 'u',
        "\u{0268}" => 'i',
        "\u{0289}" => 'u',
        "\u{00F8}" => 'o',
        "\u{0153}" => 'oe',
        "\u{0276}" => 'oe',
        "\u{0261}" => 'g',
        "\u{0263}" => 'g',
        "\u{028B}" => 'v',
    ];

    return $map;
}

function ll_tools_word_grid_normalize_text_segment_for_match(string $segment): string {
    $segment = trim($segment);
    if ($segment === '') {
        return '';
    }

    $segment = ll_tools_word_grid_lowercase($segment);
    $segment = preg_replace('/[^\p{L}]+/u', '', $segment);
    if ($segment === '') {
        return '';
    }

    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $segment);
        if (is_string($converted) && $converted !== '') {
            $segment = $converted;
        }
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $segment);
        if (is_string($converted) && $converted !== '') {
            $segment = $converted;
        }
    }

    $segment = preg_replace('/[^a-z]+/', '', $segment);
    return (string) $segment;
}

function ll_tools_word_grid_normalize_ipa_segment_for_match(string $segment): string {
    $segment = ll_tools_word_grid_normalize_ipa_output($segment);
    if ($segment === '') {
        return '';
    }

    $segment = ll_tools_word_grid_lowercase($segment);
    $segment = preg_replace('/[\s\.]+/u', '', $segment);
    $segment = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $segment);

    $map = ll_tools_word_grid_get_ipa_match_map();
    $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    foreach ($chars as $char) {
        if (preg_match('/[a-z]/', $char)) {
            $out .= $char;
            continue;
        }
        if (isset($map[$char])) {
            $out .= $map[$char];
        }
    }

    return $out;
}

function ll_tools_word_grid_normalize_ipa_segment_for_match_with_length(string $segment): string {
    $segment = ll_tools_word_grid_normalize_ipa_output($segment);
    if ($segment === '') {
        return '';
    }

    $segment = ll_tools_word_grid_lowercase($segment);
    $segment = preg_replace('/[\s\.]+/u', '', $segment);
    $segment = preg_replace('/[\x{0300}-\x{036F}]+/u', '', $segment);

    $map = ll_tools_word_grid_get_ipa_match_map();
    $chars = preg_split('//u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    if (!$chars) {
        return '';
    }

    $out = '';
    $last = '';
    foreach ($chars as $char) {
        if ($char === "\u{02D0}" || $char === "\u{02D1}") {
            if ($last !== '') {
                $out .= $last;
            }
            continue;
        }
        if (preg_match('/[a-z]/', $char)) {
            $out .= $char;
            $last = $char;
            continue;
        }
        if (isset($map[$char])) {
            $mapped = $map[$char];
            $out .= $mapped;
            $last = $mapped;
        }
    }

    return $out;
}

function ll_tools_word_grid_similarity_score(string $text_segment, string $ipa_segment): float {
    $text_norm = ll_tools_word_grid_normalize_text_segment_for_match($text_segment);
    if ($text_norm === '') {
        return 0.0;
    }
    $ipa_norm = ll_tools_word_grid_normalize_ipa_segment_for_match($ipa_segment);
    $ipa_norm_expanded = ll_tools_word_grid_normalize_ipa_segment_for_match_with_length($ipa_segment);
    if ($ipa_norm === '' && $ipa_norm_expanded === '') {
        return 0.0;
    }

    $best = 0.0;
    foreach ([$ipa_norm, $ipa_norm_expanded] as $variant) {
        if ($variant === '') {
            continue;
        }
        if ($text_norm === $variant) {
            return 1.0;
        }
        $distance = function_exists('levenshtein') ? levenshtein($text_norm, $variant) : null;
        if ($distance === null) {
            $score = ($text_norm[0] === $variant[0]) ? 0.5 : 0.0;
        } else {
            $max_len = max(strlen($text_norm), strlen($variant));
            if ($max_len === 0) {
                $score = 0.0;
            } else {
                $score = 1 - ($distance / $max_len);
            }
        }
        if ($score < 0) {
            $score = 0.0;
        } elseif ($score > 1) {
            $score = 1.0;
        }
        if ($score > $best) {
            $best = (float) $score;
        }
        if ($best >= 1.0) {
            break;
        }
    }

    return $best;
}

function ll_tools_word_grid_align_text_to_ipa(array $letters, array $tokens): array {
    $letter_count = count($letters);
    $token_count = count($tokens);
    if ($letter_count === 0 || $token_count === 0) {
        return [
            'matches' => [],
            'matched_letters' => 0,
            'matched_tokens' => 0,
            'avg_score' => 0.0,
            'total_score' => 0.0,
        ];
    }

    $skip_penalty = 0.25;
    $match_threshold = 0.55;
    $multi_penalty = 0.05;

    $token_norms = [];
    for ($idx = 0; $idx < $token_count; $idx++) {
        $token_norms[$idx] = ll_tools_word_grid_normalize_ipa_segment_for_match((string) $tokens[$idx]);
    }
    $combo_norms = [];
    if ($token_count > 1) {
        for ($idx = 0; $idx < ($token_count - 1); $idx++) {
            $combo_norms[$idx] = ll_tools_word_grid_normalize_ipa_segment_for_match(
                (string) $tokens[$idx] . (string) $tokens[$idx + 1]
            );
        }
    }

    $dp = array_fill(0, $letter_count + 1, array_fill(0, $token_count + 1, null));
    $dp[0][0] = ['score' => 0, 'prev' => null];

    $update = function (int $i, int $j, float $score, array $prev) use (&$dp) {
        $existing = $dp[$i][$j];
        if ($existing === null || $score > $existing['score']) {
            $dp[$i][$j] = [
                'score' => $score,
                'prev' => $prev,
            ];
        }
    };

    for ($i = 0; $i <= $letter_count; $i++) {
        for ($j = 0; $j <= $token_count; $j++) {
            $cell = $dp[$i][$j];
            if ($cell === null) {
                continue;
            }

            if ($i < $letter_count) {
                $update($i + 1, $j, $cell['score'] - $skip_penalty, [
                    'type' => 'skip_letter',
                    'i' => $i,
                    'j' => $j,
                ]);
            }
            if ($j < $token_count) {
                $update($i, $j + 1, $cell['score'] - $skip_penalty, [
                    'type' => 'skip_token',
                    'i' => $i,
                    'j' => $j,
                ]);
            }

            if ($i < $letter_count && $j < $token_count) {
                $score = ll_tools_word_grid_similarity_score($letters[$i], $tokens[$j]);
                if ($score >= $match_threshold) {
                    $update($i + 1, $j + 1, $cell['score'] + $score, [
                        'type' => 'match',
                        'i' => $i,
                        'j' => $j,
                        'text' => $letters[$i],
                        'ipa' => $tokens[$j],
                        'text_len' => 1,
                        'token_len' => 1,
                        'score' => $score,
                    ]);
                }
            }

            if ($i < $letter_count && ($j + 1) < $token_count) {
                $combo_norm = $combo_norms[$j] ?? '';
                if ($combo_norm !== '') {
                    $norm_a = $token_norms[$j] ?? '';
                    $norm_b = $token_norms[$j + 1] ?? '';
                    if ($combo_norm !== $norm_a && $combo_norm !== $norm_b) {
                        $ipa_segment = $tokens[$j] . $tokens[$j + 1];
                        $score = ll_tools_word_grid_similarity_score($letters[$i], $ipa_segment);
                        if ($score >= $match_threshold) {
                            $update($i + 1, $j + 2, $cell['score'] + $score - $multi_penalty, [
                                'type' => 'match',
                                'i' => $i,
                                'j' => $j,
                                'text' => $letters[$i],
                                'ipa' => $ipa_segment,
                                'text_len' => 1,
                                'token_len' => 2,
                                'score' => $score,
                            ]);
                        }
                    }
                }
            }

            if (($i + 1) < $letter_count && $j < $token_count) {
                $text_segment = $letters[$i] . $letters[$i + 1];
                $score = ll_tools_word_grid_similarity_score($text_segment, $tokens[$j]);
                if ($score >= $match_threshold) {
                    $update($i + 2, $j + 1, $cell['score'] + $score - $multi_penalty, [
                        'type' => 'match',
                        'i' => $i,
                        'j' => $j,
                        'text' => $text_segment,
                        'ipa' => $tokens[$j],
                        'text_len' => 2,
                        'token_len' => 1,
                        'score' => $score,
                    ]);
                }
            }
        }
    }

    $cell = $dp[$letter_count][$token_count];
    if ($cell === null) {
        return [
            'matches' => [],
            'matched_letters' => 0,
            'matched_tokens' => 0,
            'avg_score' => 0.0,
            'total_score' => 0.0,
        ];
    }

    $matches = [];
    $matched_letters = 0;
    $matched_tokens = 0;
    $total_score = 0.0;
    $i = $letter_count;
    $j = $token_count;
    while ($i > 0 || $j > 0) {
        $cell = $dp[$i][$j];
        if (!$cell || !isset($cell['prev'])) {
            break;
        }
        $prev = $cell['prev'];
        if (($prev['type'] ?? '') === 'match') {
            $matches[] = [
                'text' => (string) ($prev['text'] ?? ''),
                'ipa' => (string) ($prev['ipa'] ?? ''),
                'text_len' => (int) ($prev['text_len'] ?? 1),
                'token_len' => (int) ($prev['token_len'] ?? 1),
                'score' => (float) ($prev['score'] ?? 0),
            ];
            $matched_letters += (int) ($prev['text_len'] ?? 1);
            $matched_tokens += (int) ($prev['token_len'] ?? 1);
            $total_score += (float) ($prev['score'] ?? 0);
        }
        $i = (int) ($prev['i'] ?? 0);
        $j = (int) ($prev['j'] ?? 0);
    }

    $matches = array_reverse($matches);
    $avg_score = $matches ? ($total_score / count($matches)) : 0.0;

    return [
        'matches' => $matches,
        'matched_letters' => $matched_letters,
        'matched_tokens' => $matched_tokens,
        'avg_score' => $avg_score,
        'total_score' => $total_score,
    ];
}

function ll_tools_word_grid_rebuild_wordset_ipa_letter_map(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    $word_ids = function_exists('ll_tools_ipa_keyboard_get_word_ids_for_wordset')
        ? ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id)
        : get_posts([
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

    $word_ids = array_values(array_filter(array_map('intval', (array) $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', []);
        return [];
    }

    $recording_ids = get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_parent__in' => $word_ids,
    ]);

    $map = [];
    foreach ((array) $recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $recording_text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        $recording_ipa = trim((string) get_post_meta($recording_id, 'recording_ipa', true));
        if ($recording_text === '' || $recording_ipa === '') {
            continue;
        }

        $letters = ll_tools_word_grid_prepare_text_letters($recording_text);
        $tokens = ll_tools_word_grid_tokenize_ipa($recording_ipa);
        if (!empty($tokens)) {
            $tokens = array_values(array_filter($tokens, function ($token) {
                return !ll_tools_word_grid_is_ipa_stress_marker((string) $token);
            }));
        }
        if (empty($letters) || empty($tokens)) {
            continue;
        }

        $alignment = ll_tools_word_grid_align_text_to_ipa($letters, $tokens);
        if (empty($alignment['matches'])) {
            continue;
        }
        $letter_coverage = $alignment['matched_letters'] / max(1, count($letters));
        $token_coverage = $alignment['matched_tokens'] / max(1, count($tokens));
        if ($alignment['avg_score'] < 0.55 || $letter_coverage < 0.55 || $token_coverage < 0.45) {
            continue;
        }

        foreach ($alignment['matches'] as $match) {
            $text_key = ll_tools_word_grid_lowercase((string) ($match['text'] ?? ''));
            $ipa_key = ll_tools_word_grid_normalize_ipa_output((string) ($match['ipa'] ?? ''));
            if ($text_key === '' || $ipa_key === '') {
                continue;
            }
            if (!isset($map[$text_key])) {
                $map[$text_key] = [];
            }
            if (!isset($map[$text_key][$ipa_key])) {
                $map[$text_key][$ipa_key] = 0;
            }
            $map[$text_key][$ipa_key] += 1;
        }
    }

    update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', $map);
    return $map;
}

function ll_tools_word_grid_get_wordset_ipa_letter_map(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_letter_map', true);
    if (!is_array($raw)) {
        return ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    }
    $cleaned = ll_tools_word_grid_clean_ipa_letter_map($raw);
    if ($cleaned !== $raw) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_map', $cleaned);
    }
    return $cleaned;
}

function ll_tools_word_grid_get_wordset_ipa_letter_manual_map(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_letter_manual_map', true);
    if (!is_array($raw)) {
        return [];
    }

    $cleaned = [];
    foreach ($raw as $letter => $symbols) {
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        $letter_key = preg_replace('/[^\p{L}]+/u', '', $letter_key);
        if ($letter_key === '') {
            continue;
        }

        $tokens = [];
        if (is_array($symbols)) {
            $tokens = $symbols;
        } elseif (is_string($symbols) && $symbols !== '') {
            $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
                ? ll_tools_word_grid_tokenize_ipa($symbols)
                : preg_split('//u', $symbols, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (empty($tokens)) {
            continue;
        }

        $seen = [];
        $clean_tokens = [];
        foreach ($tokens as $token) {
            $token = ll_tools_word_grid_normalize_ipa_output((string) $token);
            $token = ll_tools_word_grid_strip_ipa_stress_markers($token);
            if ($token === '' || isset($seen[$token]) || ll_tools_word_grid_is_ipa_stress_marker($token)) {
                continue;
            }
            $seen[$token] = true;
            $clean_tokens[] = $token;
        }
        if (empty($clean_tokens)) {
            continue;
        }
        $cleaned[$letter_key] = $clean_tokens;
    }

    return $cleaned;
}

function ll_tools_word_grid_get_wordset_ipa_letter_blocklist(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', true);
    if (!is_array($raw)) {
        return [];
    }

    $cleaned = ll_tools_word_grid_clean_ipa_letter_blocklist($raw);
    if ($cleaned !== $raw) {
        update_term_meta($wordset_id, 'll_wordset_ipa_letter_blocklist', $cleaned);
    }

    return $cleaned;
}

function ll_tools_word_grid_get_wordset_ipa_letter_maps(int $wordset_id): array {
    return [
        'auto' => ll_tools_word_grid_get_wordset_ipa_letter_map($wordset_id),
        'manual' => ll_tools_word_grid_get_wordset_ipa_letter_manual_map($wordset_id),
    ];
}

function ll_tools_word_grid_update_wordset_ipa_letter_map(int $word_id): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return;
    }

    $wordset_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($wordset_ids) || empty($wordset_ids)) {
        return;
    }

    foreach ($wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }
        ll_tools_word_grid_rebuild_wordset_ipa_letter_map($wordset_id);
    }
}

function ll_tools_word_grid_prepare_ipa_letter_suggestions(array $auto_map, array $manual_map = [], int $limit = 6, array $blocklist = []): array {
    $limit = max(1, $limit);
    $output = [];

    if (!empty($blocklist)) {
        $auto_map = ll_tools_word_grid_filter_ipa_letter_map_with_blocklist($auto_map, $blocklist);
    }

    foreach ($manual_map as $letter => $symbols) {
        if (!is_array($symbols)) {
            continue;
        }
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        $letter_key = preg_replace('/[^\p{L}]+/u', '', $letter_key);
        if ($letter_key === '') {
            continue;
        }
        $clean = [];
        $seen = [];
        foreach ($symbols as $symbol) {
            $symbol = ll_tools_word_grid_normalize_ipa_output((string) $symbol);
            $symbol = ll_tools_word_grid_strip_ipa_stress_markers($symbol);
            if ($symbol === '' || isset($seen[$symbol]) || ll_tools_word_grid_is_ipa_stress_marker($symbol)) {
                continue;
            }
            $seen[$symbol] = true;
            $clean[] = $symbol;
        }
        if (empty($clean)) {
            continue;
        }
        $output[$letter_key] = array_slice($clean, 0, $limit);
    }

    foreach ($auto_map as $letter => $ipa_counts) {
        if (!is_array($ipa_counts)) {
            continue;
        }
        $letter_key = ll_tools_word_grid_lowercase((string) $letter);
        if ($letter_key === '') {
            continue;
        }
        if (isset($output[$letter_key])) {
            continue;
        }
        $clean_counts = [];
        foreach ($ipa_counts as $ipa => $count) {
            $ipa_key = ll_tools_word_grid_normalize_ipa_output((string) $ipa);
            $ipa_key = ll_tools_word_grid_strip_ipa_stress_markers($ipa_key);
            if ($ipa_key === '' || ll_tools_word_grid_is_ipa_stress_marker($ipa_key)) {
                continue;
            }
            $clean_counts[$ipa_key] = max(1, (int) $count);
        }
        if (empty($clean_counts)) {
            continue;
        }
        arsort($clean_counts);
        $output[$letter_key] = array_slice(array_keys($clean_counts), 0, $limit);
    }

    return $output;
}

function ll_tools_word_grid_format_language_code(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $raw = str_replace('_', '-', $raw);
    $raw = preg_replace('/[^A-Za-z\-]+/', '', $raw);
    $raw = trim($raw, '-');
    if ($raw === '') {
        return '';
    }

    $code = strtoupper($raw);
    if ($code === 'AUTO') {
        return '';
    }
    if (strpos($code, '-') === false && strlen($code) > 6) {
        return '';
    }

    return $code;
}

function ll_tools_word_grid_label_with_code(string $label, string $code): string {
    if ($code === '') {
        return $label;
    }
    return $label . ' (' . $code . ')';
}

function ll_tools_word_grid_resolve_display_text(int $word_id): array {
    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        return [
            'word_text' => '',
            'translation_text' => '',
            'store_in_title' => true,
        ];
    }

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? ll_tools_should_store_word_in_title($word_id)
        : true;
    $word_title = get_the_title($word_id);
    $word_translation = get_post_meta($word_id, 'word_translation', true);
    if ($store_in_title && $word_translation === '') {
        $word_translation = get_post_meta($word_id, 'word_english_meaning', true);
    }

    if ($store_in_title) {
        $word_text = $word_title;
        $translation_text = $word_translation;
    } else {
        $word_text = $word_translation;
        $translation_text = $word_title;
    }

    return [
        'word_text' => (string) $word_text,
        'translation_text' => (string) $translation_text,
        'store_in_title' => (bool) $store_in_title,
    ];
}

function ll_tools_word_grid_collect_part_of_speech_terms(array $word_ids): array {
    $word_ids = array_values(array_filter(array_map('intval', $word_ids), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }

    $terms = wp_get_object_terms($word_ids, 'part_of_speech', [
        'fields' => 'all_with_object_id',
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return [];
    }

    $map = [];
    foreach ($terms as $term) {
        $word_id = isset($term->object_id) ? (int) $term->object_id : 0;
        if ($word_id <= 0) {
            continue;
        }
        if (isset($map[$word_id])) {
            continue;
        }
        $map[$word_id] = [
            'slug' => (string) $term->slug,
            'label' => (string) $term->name,
            'term_id' => (int) $term->term_id,
        ];
    }

    return $map;
}

function ll_tools_word_grid_get_wordset_id_for_word(int $word_id): int {
    if ($word_id <= 0) {
        return 0;
    }
    $term_ids = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) {
        return 0;
    }
    return (int) $term_ids[0];
}

function ll_tools_word_grid_reorder_by_option_groups(array $posts, array $groups): array {
    if (empty($posts) || empty($groups)) {
        return $posts;
    }

    $sorted_groups = $groups;
    usort($sorted_groups, function ($left, $right) {
        $left_label = isset($left['label']) ? trim((string) $left['label']) : '';
        $right_label = isset($right['label']) ? trim((string) $right['label']) : '';
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings($left_label, $right_label);
        }
        return strnatcasecmp($left_label, $right_label);
    });

    $order_map = [];
    $post_map = [];
    foreach ($posts as $idx => $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0) {
            continue;
        }
        $order_map[$post_id] = $idx;
        $post_map[$post_id] = $post;
    }

    if (empty($order_map)) {
        return $posts;
    }

    $used = [];
    $ordered_posts = [];
    foreach ($sorted_groups as $group) {
        if (empty($group['word_ids']) || !is_array($group['word_ids'])) {
            continue;
        }
        $ids = array_values(array_filter(array_map('intval', $group['word_ids']), function ($id) use ($order_map) {
            return $id > 0 && isset($order_map[$id]);
        }));
        if (empty($ids)) {
            continue;
        }
        usort($ids, function ($a, $b) use ($order_map) {
            return ($order_map[$a] ?? 0) <=> ($order_map[$b] ?? 0);
        });
        foreach ($ids as $id) {
            if (isset($used[$id])) {
                continue;
            }
            $ordered_posts[] = $post_map[$id];
            $used[$id] = true;
        }
    }

    foreach ($posts as $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0 || isset($used[$post_id])) {
            continue;
        }
        $ordered_posts[] = $post;
    }

    return $ordered_posts;
}

function ll_tools_word_grid_normalize_name_key(string $value): string {
    $value = wp_strip_all_tags($value);
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $collapsed = preg_replace('/\s+/u', ' ', $value);
    if (is_string($collapsed) && $collapsed !== '') {
        $value = $collapsed;
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function ll_tools_word_grid_group_same_name_or_image(array $posts, array &$display_values_cache = []): array {
    if (empty($posts)) {
        return $posts;
    }

    $post_map = [];
    $index_map = [];
    $name_map = [];
    $image_map = [];
    $links = [];

    foreach ($posts as $index => $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id <= 0) {
            continue;
        }

        $post_map[$post_id] = $post;
        $index_map[$post_id] = (int) $index;
        if (!isset($links[$post_id])) {
            $links[$post_id] = [];
        }

        if (!isset($display_values_cache[$post_id]) || !is_array($display_values_cache[$post_id])) {
            $display_values_cache[$post_id] = ll_tools_word_grid_resolve_display_text($post_id);
        }
        $display_values = $display_values_cache[$post_id];
        $name_key = ll_tools_word_grid_normalize_name_key((string) ($display_values['word_text'] ?? ''));
        if ($name_key !== '') {
            if (!isset($name_map[$name_key])) {
                $name_map[$name_key] = [];
            }
            $name_map[$name_key][$post_id] = true;
        }

        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if ($thumb_id > 0) {
            if (!isset($image_map[$thumb_id])) {
                $image_map[$thumb_id] = [];
            }
            $image_map[$thumb_id][$post_id] = true;
        }
    }

    if (count($post_map) < 2) {
        return $posts;
    }

    $link_ids = static function (array $members) use (&$links): void {
        $ids = array_values(array_unique(array_filter(array_map('intval', $members), function ($id) {
            return $id > 0;
        })));
        if (count($ids) < 2) {
            return;
        }

        $anchor = (int) $ids[0];
        if (!isset($links[$anchor])) {
            return;
        }

        $total = count($ids);
        for ($i = 1; $i < $total; $i++) {
            $member_id = (int) $ids[$i];
            if ($member_id <= 0 || !isset($links[$member_id])) {
                continue;
            }
            $links[$anchor][$member_id] = true;
            $links[$member_id][$anchor] = true;
        }
    };

    foreach ($name_map as $members) {
        $link_ids(array_keys($members));
    }
    foreach ($image_map as $members) {
        $link_ids(array_keys($members));
    }

    $visited = [];
    $clusters = [];
    foreach (array_keys($post_map) as $post_id) {
        if (isset($visited[$post_id])) {
            continue;
        }

        $stack = [$post_id];
        $visited[$post_id] = true;
        $members = [];

        while (!empty($stack)) {
            $current = array_pop($stack);
            $members[] = $current;

            $neighbors = isset($links[$current]) ? array_keys($links[$current]) : [];
            foreach ($neighbors as $neighbor_id) {
                $neighbor_id = (int) $neighbor_id;
                if ($neighbor_id <= 0 || isset($visited[$neighbor_id])) {
                    continue;
                }
                $visited[$neighbor_id] = true;
                $stack[] = $neighbor_id;
            }
        }

        usort($members, function ($left, $right) use ($index_map) {
            return ($index_map[$left] ?? PHP_INT_MAX) <=> ($index_map[$right] ?? PHP_INT_MAX);
        });

        $clusters[] = [
            'first_index' => $index_map[$members[0]] ?? PHP_INT_MAX,
            'members' => $members,
        ];
    }

    if (empty($clusters)) {
        return $posts;
    }

    usort($clusters, function ($left, $right) {
        return ($left['first_index'] ?? PHP_INT_MAX) <=> ($right['first_index'] ?? PHP_INT_MAX);
    });

    $ordered = [];
    $used = [];
    foreach ($clusters as $cluster) {
        $members = isset($cluster['members']) && is_array($cluster['members']) ? $cluster['members'] : [];
        foreach ($members as $member_id) {
            $member_id = (int) $member_id;
            if ($member_id <= 0 || isset($used[$member_id]) || !isset($post_map[$member_id])) {
                continue;
            }
            $ordered[] = $post_map[$member_id];
            $used[$member_id] = true;
        }
    }

    foreach ($posts as $post) {
        $post_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($post_id > 0 && isset($used[$post_id])) {
            continue;
        }
        $ordered[] = $post;
    }

    return $ordered;
}

function ll_tools_user_can_edit_vocab_words(): bool {
    if (!is_user_logged_in() || !current_user_can('view_ll_tools')) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    $user = wp_get_current_user();
    return in_array('ll_tools_editor', (array) $user->roles, true);
}

/**
 * The callback function for the 'word_grid' shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content to display the grid.
 */
function ll_tools_word_grid_shortcode($atts) {
    // Shortcode attributes with defaults
    $atts = shortcode_atts(array(
        'category' => '', // Default category to empty
        'wordset'  => '', // Optional wordset filter
        'deepest_only' => '', // When truthy, restrict to lowest-level categories.
    ), $atts);

    // Sanitize the category attribute
    $sanitized_category = sanitize_text_field($atts['category']);
    $sanitized_wordset = sanitize_text_field($atts['wordset']);
    $deepest_only = false;
    if (!empty($atts['deepest_only'])) {
        $deepest_only = filter_var($atts['deepest_only'], FILTER_VALIDATE_BOOLEAN);
    }

    $category_term = null;
    $is_text_based = false;
    if ($sanitized_category !== '') {
        $category_term = get_term_by('slug', $sanitized_category, 'word-category');
    }
    if ($category_term && !is_wp_error($category_term) && function_exists('ll_tools_get_category_quiz_config')) {
        $quiz_config = ll_tools_get_category_quiz_config($category_term);
        $prompt_type = (string) ($quiz_config['prompt_type'] ?? 'audio');
        $option_type = (string) ($quiz_config['option_type'] ?? '');
        $requires_images = ($prompt_type === 'image') || ($option_type === 'image');
        $is_text_based = !$requires_images && (strpos($option_type, 'text') === 0);
    }

    $wordset_term = null;
    $wordset_id = 0;
    if ($sanitized_wordset !== '') {
        $wordset_field = ctype_digit($sanitized_wordset) ? 'term_id' : 'slug';
        $wordset_value = ctype_digit($sanitized_wordset) ? (int) $sanitized_wordset : $sanitized_wordset;
        $wordset_term = get_term_by($wordset_field, $wordset_value, 'wordset');
        if ($wordset_term && !is_wp_error($wordset_term)) {
            $wordset_id = (int) $wordset_term->term_id;
        } else {
            $wordset_term = null;
        }
    }
    $wordset_has_gender = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender')) {
        $wordset_has_gender = ll_tools_wordset_has_grammatical_gender($wordset_id);
    }
    $wordset_has_plurality = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality')) {
        $wordset_has_plurality = ll_tools_wordset_has_plurality($wordset_id);
    }
    $wordset_has_verb_tense = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense')) {
        $wordset_has_verb_tense = ll_tools_wordset_has_verb_tense($wordset_id);
    }
    $wordset_has_verb_mood = false;
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood')) {
        $wordset_has_verb_mood = ll_tools_wordset_has_verb_mood($wordset_id);
    }
    ll_enqueue_asset_by_timestamp('/js/word-grid.js', 'll-tools-word-grid', ['jquery', 'jquery-ui-autocomplete'], true);

    $can_edit_words = ll_tools_user_can_edit_vocab_words()
        && is_singular('ll_vocab_lesson');

    $user_study_state = [
        'wordset_id'       => 0,
        'category_ids'     => [],
        'starred_word_ids' => [],
        'star_mode'        => 'normal',
        'fast_transitions' => false,
    ];
    if (is_user_logged_in() && function_exists('ll_tools_get_user_study_state')) {
        $user_study_state = ll_tools_get_user_study_state();
    }
    $part_of_speech_terms = [];
    if ($can_edit_words) {
        $part_of_speech_terms = get_terms([
            'taxonomy' => 'part_of_speech',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (is_wp_error($part_of_speech_terms)) {
            $part_of_speech_terms = [];
        }
    }
    $gender_options = [];
    if ($wordset_has_gender && function_exists('ll_tools_wordset_get_gender_options')) {
        $gender_options = ll_tools_wordset_get_gender_options($wordset_id);
    }
    $plurality_options = [];
    if ($wordset_has_plurality && function_exists('ll_tools_wordset_get_plurality_options')) {
        $plurality_options = ll_tools_wordset_get_plurality_options($wordset_id);
    }
    $verb_tense_options = [];
    if ($wordset_has_verb_tense && function_exists('ll_tools_wordset_get_verb_tense_options')) {
        $verb_tense_options = ll_tools_wordset_get_verb_tense_options($wordset_id);
    }
    $verb_mood_options = [];
    if ($wordset_has_verb_mood && function_exists('ll_tools_wordset_get_verb_mood_options')) {
        $verb_mood_options = ll_tools_wordset_get_verb_mood_options($wordset_id);
    }

    // Start output buffering
    ob_start();

    // WP_Query arguments
    $args = array(
        'post_type' => 'words',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'orderby' => 'date', // Order by date
        'order' => 'ASC', // Ascending order
    );
    if (!$is_text_based) {
        $args['meta_query'] = array(
            array(
                'key' => '_thumbnail_id', // Checks if the post has a featured image.
                'compare' => 'EXISTS'
            ),
        );
    }

    $tax_query = [];
    if (!empty($sanitized_category)) {
        $tax_query[] = [
            'taxonomy' => 'word-category',
            'field' => 'slug',
            'terms' => $sanitized_category,
        ];
    }
    if (!empty($sanitized_wordset)) {
        $is_numeric = ctype_digit($sanitized_wordset);
        $tax_query[] = [
            'taxonomy' => 'wordset',
            'field'    => $is_numeric ? 'term_id' : 'slug',
            'terms'    => $is_numeric ? [(int) $sanitized_wordset] : $sanitized_wordset,
        ];
    }
    if (!empty($tax_query)) {
        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }
        $args['tax_query'] = $tax_query;
    }

    // The Query
    $query = new WP_Query($args);
    if ($deepest_only && $category_term && function_exists('ll_get_deepest_categories')) {
        $filtered_posts = [];
        foreach ((array) $query->posts as $post_obj) {
            $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
            if ($post_id <= 0) {
                continue;
            }
            $deepest_terms = ll_get_deepest_categories($post_id);
            $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
            if (in_array((int) $category_term->term_id, $deepest_ids, true)) {
                $filtered_posts[] = $post_obj;
            }
        }
        $query->posts = $filtered_posts;
        $query->post_count = count($filtered_posts);
        $query->current_post = -1;
    }

    // Words reserved as specific wrong answers should not appear in lesson grids.
    if (!empty($query->posts) && function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $visible_word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids(array_map(static function ($post_obj): int {
            return isset($post_obj->ID) ? (int) $post_obj->ID : 0;
        }, (array) $query->posts));
        $visible_lookup = array_fill_keys($visible_word_ids, true);
        if (count($visible_lookup) !== count((array) $query->posts)) {
            $visible_posts = array_values(array_filter((array) $query->posts, static function ($post_obj) use ($visible_lookup): bool {
                $post_id = isset($post_obj->ID) ? (int) $post_obj->ID : 0;
                return $post_id > 0 && isset($visible_lookup[$post_id]);
            }));
            $query->posts = $visible_posts;
            $query->post_count = count($visible_posts);
            $query->current_post = -1;
        }
    }

    if ($category_term && $wordset_id > 0 && function_exists('ll_tools_get_word_option_maps')) {
        $maps = ll_tools_get_word_option_maps($wordset_id, (int) $category_term->term_id);
        $groups = isset($maps['groups']) && is_array($maps['groups']) ? $maps['groups'] : [];
        if (!empty($groups)) {
            $query->posts = ll_tools_word_grid_reorder_by_option_groups($query->posts, $groups);
            $query->post_count = count($query->posts);
            $query->current_post = -1;
        }
    }
    $word_ids = wp_list_pluck($query->posts, 'ID');
    if (!empty($word_ids)) {
        update_meta_cache('post', $word_ids);
    }
    $display_values_cache = [];
    $query->posts = ll_tools_word_grid_group_same_name_or_image($query->posts, $display_values_cache);
    $query->post_count = count($query->posts);
    $query->current_post = -1;
    $word_ids = wp_list_pluck($query->posts, 'ID');
    $part_of_speech_by_word = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
    $audio_by_word = ll_tools_word_grid_collect_audio_files($word_ids, true);
    $main_recording_types = function_exists('ll_tools_get_main_recording_types')
        ? ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $recording_type_order = ['question', 'isolation', 'introduction'];
    $recording_labels = [
        'question'     => __('Question', 'll-tools-text-domain'),
        'isolation'    => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
    ];

    $target_lang_raw = '';
    if ($wordset_term && function_exists('ll_get_wordset_language')) {
        $target_lang_raw = (string) ll_get_wordset_language((int) $wordset_term->term_id);
    }
    if ($target_lang_raw === '') {
        $target_lang_raw = (string) get_option('ll_target_language', '');
    }
    $translation_lang_raw = (string) get_option('ll_translation_language', '');
    $target_lang_code = ll_tools_word_grid_format_language_code($target_lang_raw);
    $translation_lang_code = ll_tools_word_grid_format_language_code($translation_lang_raw);

    $play_label_template = __('Play %s recording', 'll-tools-text-domain');
    $edit_labels = [
        'edit_word'   => __('Edit word', 'll-tools-text-domain'),
        'word'        => ll_tools_word_grid_label_with_code(__('Word', 'll-tools-text-domain'), $target_lang_code),
        'translation' => ll_tools_word_grid_label_with_code(__('Translation', 'll-tools-text-domain'), $translation_lang_code),
        'note'        => __('Note', 'll-tools-text-domain'),
        'recordings'  => __('Recordings', 'll-tools-text-domain'),
        'text'        => ll_tools_word_grid_label_with_code(__('Text', 'll-tools-text-domain'), $target_lang_code),
        'ipa'         => ll_tools_word_grid_label_with_code(__('IPA', 'll-tools-text-domain'), $target_lang_code),
        'ipa_superscript' => __('Superscript selection', 'll-tools-text-domain'),
        'save'        => __('Save', 'll-tools-text-domain'),
        'cancel'      => __('Cancel', 'll-tools-text-domain'),
    ];
    $show_stars = is_user_logged_in();
    $starred_ids = array_values(array_filter(array_map('intval', (array) ($user_study_state['starred_word_ids'] ?? []))));

    $ipa_special_chars = $wordset_id ? ll_tools_word_grid_get_wordset_ipa_special_chars($wordset_id) : [];
    if (!empty($audio_by_word)) {
        foreach ($audio_by_word as $entries) {
            foreach ((array) $entries as $entry) {
                $ipa_value = isset($entry['recording_ipa']) ? (string) $entry['recording_ipa'] : '';
                if ($ipa_value === '') {
                    continue;
                }
                $new_chars = ll_tools_word_grid_extract_ipa_special_chars($ipa_value);
                foreach ($new_chars as $char) {
                    if (!in_array($char, $ipa_special_chars, true)) {
                        $ipa_special_chars[] = $char;
                    }
                }
            }
        }
    }

    $ipa_counts = [];
    $count_audio_by_word = $audio_by_word;
    if ($wordset_id > 0 && function_exists('ll_tools_ipa_keyboard_get_word_ids_for_wordset')) {
        $wordset_word_ids = ll_tools_ipa_keyboard_get_word_ids_for_wordset($wordset_id);
        if (!empty($wordset_word_ids)) {
            $count_audio_by_word = ll_tools_word_grid_collect_audio_files($wordset_word_ids, true);
        }
    }
    if (!empty($count_audio_by_word)) {
        foreach ($count_audio_by_word as $entries) {
            foreach ((array) $entries as $entry) {
                $ipa_value = isset($entry['recording_ipa']) ? (string) $entry['recording_ipa'] : '';
                if ($ipa_value === '') {
                    continue;
                }
                $tokens = function_exists('ll_tools_word_grid_tokenize_ipa')
                    ? ll_tools_word_grid_tokenize_ipa($ipa_value)
                    : preg_split('//u', $ipa_value, -1, PREG_SPLIT_NO_EMPTY);
                foreach ((array) $tokens as $token) {
                    if (function_exists('ll_tools_word_grid_is_special_ipa_token')
                        && !ll_tools_word_grid_is_special_ipa_token($token)) {
                        continue;
                    }
                    if (!isset($ipa_counts[$token])) {
                        $ipa_counts[$token] = 0;
                    }
                    $ipa_counts[$token] += 1;
                }
            }
        }
    }

    if (!empty($ipa_special_chars)) {
        usort($ipa_special_chars, function ($a, $b) use ($ipa_counts) {
            $count_a = (int) ($ipa_counts[$a] ?? 0);
            $count_b = (int) ($ipa_counts[$b] ?? 0);
            if ($count_a === $count_b) {
                return strnatcasecmp((string) $a, (string) $b);
            }
            return ($count_b <=> $count_a);
        });
    }

    if (!empty($ipa_special_chars)) {
        $normalized_chars = [];
        foreach ($ipa_special_chars as $char) {
            $normalized = ll_tools_word_grid_normalize_ipa_output((string) $char);
            if ($normalized === '') {
                continue;
            }
            if (!in_array($normalized, $normalized_chars, true)) {
                $normalized_chars[] = $normalized;
            }
        }
        $ipa_special_chars = $normalized_chars;
    }

    $ipa_letter_map = [];
    if ($can_edit_words && $wordset_id > 0) {
        $letter_maps = ll_tools_word_grid_get_wordset_ipa_letter_maps($wordset_id);
        $blocklist = ll_tools_word_grid_get_wordset_ipa_letter_blocklist($wordset_id);
        if (!empty($letter_maps['auto']) || !empty($letter_maps['manual'])) {
            $ipa_letter_map = ll_tools_word_grid_prepare_ipa_letter_suggestions(
                (array) ($letter_maps['auto'] ?? []),
                (array) ($letter_maps['manual'] ?? []),
                6,
                (array) $blocklist
            );
        }
    }

    wp_localize_script('ll-tools-word-grid', 'llToolsWordGridData', [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => is_user_logged_in() ? wp_create_nonce('ll_user_study') : '',
        'isLoggedIn' => is_user_logged_in(),
        'canEdit'    => $can_edit_words,
        'editNonce'  => $can_edit_words ? wp_create_nonce('ll_word_grid_edit') : '',
        'supportsIpaExtended' => ll_tools_word_grid_supports_ipa_extended(),
        'state'      => $user_study_state,
        'i18n'       => [
            'starLabel'      => __('Star word', 'll-tools-text-domain'),
            'unstarLabel'    => __('Unstar word', 'll-tools-text-domain'),
            'starAllLabel'   => __('Star all', 'll-tools-text-domain'),
            'unstarAllLabel' => __('Unstar all', 'll-tools-text-domain'),
        ],
        'editI18n'   => [
            'saving' => __('Saving...', 'll-tools-text-domain'),
            'saved'  => __('Saved.', 'll-tools-text-domain'),
            'error'  => __('Unable to save changes.', 'll-tools-text-domain'),
            'ipaCommon' => __('Common IPA symbols', 'll-tools-text-domain'),
            'ipaWordset' => __('Wordset IPA symbols', 'll-tools-text-domain'),
        ],
        'bulkI18n' => [
            'saving' => __('Updating...', 'll-tools-text-domain'),
            'posSuccess' => __('Updated %d words.', 'll-tools-text-domain'),
            'genderSuccess' => __('Updated %d nouns.', 'll-tools-text-domain'),
            'pluralitySuccess' => __('Updated %d nouns.', 'll-tools-text-domain'),
            'verbTenseSuccess' => __('Updated %d verbs.', 'll-tools-text-domain'),
            'verbMoodSuccess' => __('Updated %d verbs.', 'll-tools-text-domain'),
            'posMissing' => __('Choose a part of speech.', 'll-tools-text-domain'),
            'genderMissing' => __('Choose a gender.', 'll-tools-text-domain'),
            'pluralityMissing' => __('Choose a plurality option.', 'll-tools-text-domain'),
            'verbTenseMissing' => __('Choose a verb tense option.', 'll-tools-text-domain'),
            'verbMoodMissing' => __('Choose a verb mood option.', 'll-tools-text-domain'),
            'error' => __('Unable to update words.', 'll-tools-text-domain'),
        ],
        'transcribeI18n' => [
            'confirm'        => __('Transcribe missing recordings for this lesson?', 'll-tools-text-domain'),
            'confirmReplace' => __('Replace captions for this lesson?', 'll-tools-text-domain'),
            'confirmClear'   => __('Clear captions for this lesson?', 'll-tools-text-domain'),
            'working'        => __('Transcribing...', 'll-tools-text-domain'),
            'progress'       => __('Transcribing %1$d of %2$d...', 'll-tools-text-domain'),
            'done'           => __('Transcription complete.', 'll-tools-text-domain'),
            'none'           => __('No recordings need text.', 'll-tools-text-domain'),
            'clearing'       => __('Clearing captions...', 'll-tools-text-domain'),
            'cleared'        => __('Captions cleared.', 'll-tools-text-domain'),
            'cancelled'      => __('Transcription cancelled.', 'll-tools-text-domain'),
            'error'          => __('Unable to transcribe recordings.', 'll-tools-text-domain'),
        ],
        'transcribePollAttempts' => (int) apply_filters('ll_tools_word_grid_transcribe_poll_attempts', 20),
        'transcribePollIntervalMs' => (int) apply_filters('ll_tools_word_grid_transcribe_poll_interval_ms', 1200),
        'ipaSpecialChars' => $ipa_special_chars,
        'ipaLetterMap' => $ipa_letter_map,
    ]);

    // The Loop
    if ($query->have_posts()) {
        $grid_classes = 'word-grid ll-word-grid';
        if ($is_text_based) {
            $grid_classes .= ' ll-word-grid--text';
        }
        $grid_attrs = 'data-ll-word-grid';
        if ($wordset_id > 0) {
            $grid_attrs .= ' data-ll-wordset-id="' . esc_attr($wordset_id) . '"';
        }
        if ($category_term && !is_wp_error($category_term)) {
            $grid_attrs .= ' data-ll-category-id="' . esc_attr((int) $category_term->term_id) . '"';
        }
        if ($wordset_has_gender) {
            $grid_attrs .= ' data-ll-gender-enabled="1"';
        }
        if ($wordset_has_plurality) {
            $grid_attrs .= ' data-ll-plurality-enabled="1"';
        }
        if ($wordset_has_verb_tense) {
            $grid_attrs .= ' data-ll-verb-tense-enabled="1"';
        }
        if ($wordset_has_verb_mood) {
            $grid_attrs .= ' data-ll-verb-mood-enabled="1"';
        }
        echo '<div id="word-grid" class="' . esc_attr($grid_classes) . '" ' . $grid_attrs . '>'; // Grid container
        while ($query->have_posts()) {
            $query->the_post();
            $word_id = get_the_ID();
            $display_values = $display_values_cache[$word_id] ?? ll_tools_word_grid_resolve_display_text($word_id);
            $word_text = $display_values['word_text'];
            $translation_text = $display_values['translation_text'];
            $word_note = trim((string) get_post_meta($word_id, 'll_word_usage_note', true));
            $dictionary_entry_id = function_exists('ll_tools_get_word_dictionary_entry_id')
                ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
                : 0;
            $dictionary_entry_title = $dictionary_entry_id > 0
                ? trim((string) get_the_title($dictionary_entry_id))
                : '';
            $pos_entry = $part_of_speech_by_word[$word_id] ?? [];
            $pos_slug = isset($pos_entry['slug']) ? (string) $pos_entry['slug'] : '';
            $pos_label = isset($pos_entry['label']) ? (string) $pos_entry['label'] : '';
            $is_noun = ($pos_slug === 'noun');
            $is_verb = ($pos_slug === 'verb');
            $gender_value = '';
            $gender_label = '';
            $gender_display = [
                'value' => '',
                'label' => '',
                'role' => 'other',
                'style' => '',
                'html' => '',
            ];
            if ($wordset_has_gender && $wordset_id > 0 && $is_noun) {
                $gender_value = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
                if ($gender_value !== '') {
                    if (function_exists('ll_tools_wordset_get_gender_display_data')) {
                        $gender_display = ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value);
                        $gender_label = (string) ($gender_display['label'] ?? '');
                    } elseif (function_exists('ll_tools_wordset_get_gender_label')) {
                        $gender_label = ll_tools_wordset_get_gender_label($wordset_id, $gender_value);
                        $gender_display['label'] = $gender_label;
                    } else {
                        $gender_label = $gender_value;
                        $gender_display['label'] = $gender_label;
                    }
                }
            }
            $plurality_value = '';
            $plurality_label = '';
            if ($wordset_has_plurality && $wordset_id > 0 && $is_noun) {
                $plurality_value = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
                if ($plurality_value !== '' && function_exists('ll_tools_wordset_get_plurality_label')) {
                    $plurality_label = ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value);
                } else {
                    $plurality_label = $plurality_value;
                }
            }
            $verb_tense_value = '';
            $verb_tense_label = '';
            if ($wordset_has_verb_tense && $wordset_id > 0 && $is_verb) {
                $verb_tense_value = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
                if ($verb_tense_value !== '' && function_exists('ll_tools_wordset_get_verb_tense_label')) {
                    $verb_tense_label = ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value);
                } else {
                    $verb_tense_label = $verb_tense_value;
                }
            }
            $verb_mood_value = '';
            $verb_mood_label = '';
            if ($wordset_has_verb_mood && $wordset_id > 0 && $is_verb) {
                $verb_mood_value = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
                if ($verb_mood_value !== '' && function_exists('ll_tools_wordset_get_verb_mood_label')) {
                    $verb_mood_label = ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value);
                } else {
                    $verb_mood_label = $verb_mood_value;
                }
            }

            // Individual item
            echo '<div class="word-item" data-word-id="' . esc_attr($word_id) . '">';
            // Featured image with container
            if (!$is_text_based && has_post_thumbnail()) {
                echo '<div class="word-image-container">'; // Start new container
                echo get_the_post_thumbnail(get_the_ID(), 'full', array('class' => 'word-image'));
                echo '</div>'; // Close container
            }

            $audio_files = $audio_by_word[$word_id] ?? [];
            $preferred_speaker = ll_tools_word_grid_get_preferred_speaker($audio_files, $main_recording_types);
            $has_recordings = false;
            $recordings_html = '';
            $recording_rows = [];
            $has_recording_caption = false;
            $edit_recordings = [];

            foreach ($recording_type_order as $type) {
                $entry = ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
                $audio_url = isset($entry['url']) ? (string) $entry['url'] : '';
                if (!$audio_url) {
                    continue;
                }
                $has_recordings = true;
                $label = $recording_labels[$type] ?? ucfirst($type);
                $play_label = sprintf($play_label_template, $label);
                $recording_text = trim((string) ($entry['recording_text'] ?? ''));
                $recording_translation = trim((string) ($entry['recording_translation'] ?? ''));
                $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? ''));
                if ($recording_text !== '' || $recording_translation !== '' || $recording_ipa !== '') {
                    $has_recording_caption = true;
                }
                $recording_id_attr = '';
                if (!empty($entry['id'])) {
                    $recording_id_attr = ' data-recording-id="' . esc_attr((int) $entry['id']) . '"';
                }
                $recording_button = '<button type="button" class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--' . esc_attr($type) . '" data-audio-url="' . esc_url($audio_url) . '" data-recording-type="' . esc_attr($type) . '"' . $recording_id_attr . ' aria-label="' . esc_attr($play_label) . '" title="' . esc_attr($play_label) . '">';
                $recording_button .= '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
                $recording_button .= '<span class="ll-study-recording-visualizer" aria-hidden="true">';
                for ($i = 0; $i < 4; $i++) {
                    $recording_button .= '<span class="bar"></span>';
                }
                $recording_button .= '</span>';
                $recording_button .= '</button>';
                $recording_rows[] = [
                    'button' => $recording_button,
                    'text' => $recording_text,
                    'translation' => $recording_translation,
                    'ipa' => $recording_ipa,
                    'id' => !empty($entry['id']) ? (int) $entry['id'] : 0,
                ];

                if ($can_edit_words && !empty($entry['id'])) {
                    $edit_recordings[] = [
                        'id' => (int) $entry['id'],
                        'type' => $type,
                        'label' => $label,
                        'text' => (string) ($entry['recording_text'] ?? ''),
                        'translation' => (string) ($entry['recording_translation'] ?? ''),
                        'ipa' => ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? '')),
                        'audio_url' => $audio_url,
                    ];
                }
            }

            $actions_row_html = '';
            if ($show_stars || $can_edit_words) {
                $actions_row_html .= '<div class="ll-word-actions-row">';
                if ($show_stars) {
                    $is_starred = in_array((int) $word_id, $starred_ids, true);
                    $star_label = $is_starred
                        ? __('Unstar word', 'll-tools-text-domain')
                        : __('Star word', 'll-tools-text-domain');
                    $actions_row_html .= '<button type="button" class="ll-word-star ll-word-grid-star' . ($is_starred ? ' active' : '') . '" data-word-id="' . esc_attr($word_id) . '" aria-pressed="' . ($is_starred ? 'true' : 'false') . '" aria-label="' . esc_attr($star_label) . '" title="' . esc_attr($star_label) . '"></button>';
                }
                if ($can_edit_words) {
                    $actions_row_html .= '<button type="button" class="ll-word-edit-toggle" data-ll-word-edit-toggle aria-label="' . esc_attr($edit_labels['edit_word']) . '" title="' . esc_attr($edit_labels['edit_word']) . '" aria-expanded="false">';
                    $actions_row_html .= '<span class="ll-word-edit-icon" aria-hidden="true">';
                    $actions_row_html .= '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    $actions_row_html .= '</span>';
                    $actions_row_html .= '</button>';
                }
                $actions_row_html .= '</div>';
            }

            $title_row_html = '<div class="ll-word-title-row">';
            $title_row_html .= '<h3 class="word-title">';
            $title_row_html .= '<span class="ll-word-text" data-ll-word-text dir="auto">' . esc_html($word_text) . '</span>';
            $title_row_html .= '<span class="ll-word-translation" data-ll-word-translation dir="auto">' . esc_html($translation_text) . '</span>';
            $title_row_html .= '</h3>';
            $title_row_html .= '</div>';

            if ($is_text_based) {
                echo $title_row_html;
                if ($actions_row_html !== '') {
                    echo $actions_row_html;
                }
            } else {
                if ($actions_row_html !== '') {
                    echo $actions_row_html;
                }
                echo $title_row_html;
            }
            $meta_row_class = 'll-word-meta-row';
            if ($pos_label === '' && $gender_label === '' && $plurality_label === '' && $verb_tense_label === '' && $verb_mood_label === '') {
                $meta_row_class .= ' ll-word-meta-row--empty';
            }
            echo '<div class="' . esc_attr($meta_row_class) . '" data-ll-word-meta>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--pos" data-ll-word-pos>' . esc_html($pos_label) . '</span>';
            $gender_tag_class = 'll-word-meta-tag ll-word-meta-tag--gender';
            $gender_role = (string) ($gender_display['role'] ?? '');
            if ($gender_role !== '') {
                $gender_tag_class .= ' ll-word-meta-tag--gender-' . sanitize_html_class($gender_role);
            }
            $gender_style = (string) ($gender_display['style'] ?? '');
            $gender_style_attr = ($gender_style !== '') ? ' style="' . esc_attr($gender_style) . '"' : '';
            $gender_aria_label = (string) ($gender_display['label'] ?? $gender_label);
            echo '<span class="' . esc_attr($gender_tag_class) . '" data-ll-word-gender data-ll-gender-role="' . esc_attr($gender_role) . '"' . $gender_style_attr . ' aria-label="' . esc_attr($gender_aria_label) . '" title="' . esc_attr($gender_aria_label) . '">';
            if (!empty($gender_display['html'])) {
                echo $gender_display['html'];
            } else {
                echo esc_html($gender_label);
            }
            echo '</span>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--plurality" data-ll-word-plurality>' . esc_html($plurality_label) . '</span>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--verb-tense" data-ll-word-verb-tense>' . esc_html($verb_tense_label) . '</span>';
            echo '<span class="ll-word-meta-tag ll-word-meta-tag--verb-mood" data-ll-word-verb-mood>' . esc_html($verb_mood_label) . '</span>';
            echo '</div>';
            $note_class = 'll-word-note';
            if ($word_note === '') {
                $note_class .= ' ll-word-note--empty';
            }
            echo '<div class="' . esc_attr($note_class) . '" data-ll-word-note>' . esc_html($word_note) . '</div>';

            if ($can_edit_words) {
                $word_input_id = 'll-word-edit-word-' . $word_id;
                $translation_input_id = 'll-word-edit-translation-' . $word_id;
                $note_input_id = 'll-word-edit-note-' . $word_id;
                echo '<div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="true">';
                echo '<div class="ll-word-edit-fields">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($word_input_id) . '">' . esc_html($edit_labels['word']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($word_input_id) . '" data-ll-word-input="word" value="' . esc_attr($word_text) . '" />';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($translation_input_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($translation_input_id) . '" data-ll-word-input="translation" value="' . esc_attr($translation_text) . '" />';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($note_input_id) . '">' . esc_html($edit_labels['note']) . '</label>';
                echo '<textarea class="ll-word-edit-input ll-word-edit-textarea" id="' . esc_attr($note_input_id) . '" data-ll-word-input="note" rows="3">' . esc_textarea($word_note) . '</textarea>';
                echo '</div>';
                echo '<div class="ll-word-edit-fields ll-word-edit-fields--meta">';
                $dictionary_entry_input_id = 'll-word-edit-dictionary-entry-' . $word_id;
                echo '<div class="ll-word-edit-field ll-word-edit-dictionary-entry">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($dictionary_entry_input_id) . '">' . esc_html__('Dictionary entry', 'll-tools-text-domain') . '</label>';
                echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($dictionary_entry_input_id) . '" data-ll-word-input="dictionary_entry_lookup" value="' . esc_attr($dictionary_entry_title) . '" placeholder="' . esc_attr__('Type to select or create dictionary entry', 'll-tools-text-domain') . '" autocomplete="off" />';
                echo '<input type="hidden" data-ll-word-input="dictionary_entry_id" value="' . esc_attr($dictionary_entry_id > 0 ? (string) $dictionary_entry_id : '') . '" />';
                echo '</div>';
                $pos_input_id = 'll-word-edit-pos-' . $word_id;
                echo '<div class="ll-word-edit-field">';
                echo '<label class="ll-word-edit-label" for="' . esc_attr($pos_input_id) . '">' . esc_html__('Part of speech', 'll-tools-text-domain') . '</label>';
                echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($pos_input_id) . '" data-ll-word-input="part_of_speech">';
                echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                foreach ($part_of_speech_terms as $term) {
                    $term_slug = (string) ($term->slug ?? '');
                    $term_label = (string) ($term->name ?? '');
                    if ($term_slug === '') {
                        continue;
                    }
                    echo '<option value="' . esc_attr($term_slug) . '"' . selected($term_slug, $pos_slug, false) . '>' . esc_html($term_label) . '</option>';
                }
                echo '</select>';
                echo '</div>';
                if ($wordset_has_gender) {
                    $gender_input_id = 'll-word-edit-gender-' . $word_id;
                    $gender_field_class = 'll-word-edit-field ll-word-edit-gender';
                    if (!$is_noun) {
                        $gender_field_class .= ' ll-word-edit-gender--hidden';
                    }
                    echo '<div class="' . esc_attr($gender_field_class) . '" data-ll-word-gender-field aria-hidden="' . ($is_noun ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($gender_input_id) . '">' . esc_html__('Gender', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($gender_input_id) . '" data-ll-word-input="gender"' . ($is_noun ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $gender_found = false;
                    foreach ($gender_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $gender_value) {
                            $gender_found = true;
                        }
                        $option_label = function_exists('ll_tools_wordset_format_gender_display_label')
                            ? ll_tools_wordset_format_gender_display_label($option_value)
                            : $option_value;
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $gender_value, false) . '>' . esc_html($option_label) . '</option>';
                    }
                    if ($gender_value !== '' && !$gender_found) {
                        $fallback_label = function_exists('ll_tools_wordset_format_gender_display_label')
                            ? ll_tools_wordset_format_gender_display_label($gender_value)
                            : $gender_value;
                        echo '<option value="' . esc_attr($gender_value) . '" selected>' . esc_html($fallback_label) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                if ($wordset_has_plurality) {
                    $plurality_input_id = 'll-word-edit-plurality-' . $word_id;
                    $plurality_field_class = 'll-word-edit-field ll-word-edit-plurality';
                    if (!$is_noun) {
                        $plurality_field_class .= ' ll-word-edit-plurality--hidden';
                    }
                    echo '<div class="' . esc_attr($plurality_field_class) . '" data-ll-word-plurality-field aria-hidden="' . ($is_noun ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($plurality_input_id) . '">' . esc_html__('Plurality', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($plurality_input_id) . '" data-ll-word-input="plurality"' . ($is_noun ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $plurality_found = false;
                    foreach ($plurality_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $plurality_value) {
                            $plurality_found = true;
                        }
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $plurality_value, false) . '>' . esc_html($option_value) . '</option>';
                    }
                    if ($plurality_value !== '' && !$plurality_found) {
                        echo '<option value="' . esc_attr($plurality_value) . '" selected>' . esc_html($plurality_value) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                if ($wordset_has_verb_tense) {
                    $verb_tense_input_id = 'll-word-edit-verb-tense-' . $word_id;
                    $verb_tense_field_class = 'll-word-edit-field ll-word-edit-verb-tense';
                    if (!$is_verb) {
                        $verb_tense_field_class .= ' ll-word-edit-verb-tense--hidden';
                    }
                    echo '<div class="' . esc_attr($verb_tense_field_class) . '" data-ll-word-verb-tense-field aria-hidden="' . ($is_verb ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($verb_tense_input_id) . '">' . esc_html__('Verb tense', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($verb_tense_input_id) . '" data-ll-word-input="verb_tense"' . ($is_verb ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $verb_tense_found = false;
                    foreach ($verb_tense_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $verb_tense_value) {
                            $verb_tense_found = true;
                        }
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $verb_tense_value, false) . '>' . esc_html($option_value) . '</option>';
                    }
                    if ($verb_tense_value !== '' && !$verb_tense_found) {
                        echo '<option value="' . esc_attr($verb_tense_value) . '" selected>' . esc_html($verb_tense_value) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                if ($wordset_has_verb_mood) {
                    $verb_mood_input_id = 'll-word-edit-verb-mood-' . $word_id;
                    $verb_mood_field_class = 'll-word-edit-field ll-word-edit-verb-mood';
                    if (!$is_verb) {
                        $verb_mood_field_class .= ' ll-word-edit-verb-mood--hidden';
                    }
                    echo '<div class="' . esc_attr($verb_mood_field_class) . '" data-ll-word-verb-mood-field aria-hidden="' . ($is_verb ? 'false' : 'true') . '">';
                    echo '<label class="ll-word-edit-label" for="' . esc_attr($verb_mood_input_id) . '">' . esc_html__('Verb mood', 'll-tools-text-domain') . '</label>';
                    echo '<select class="ll-word-edit-input ll-word-edit-select" id="' . esc_attr($verb_mood_input_id) . '" data-ll-word-input="verb_mood"' . ($is_verb ? '' : ' disabled') . '>';
                    echo '<option value="">' . esc_html__('None', 'll-tools-text-domain') . '</option>';
                    $verb_mood_found = false;
                    foreach ($verb_mood_options as $option) {
                        $option_value = (string) $option;
                        if ($option_value === '') {
                            continue;
                        }
                        if ($option_value === $verb_mood_value) {
                            $verb_mood_found = true;
                        }
                        echo '<option value="' . esc_attr($option_value) . '"' . selected($option_value, $verb_mood_value, false) . '>' . esc_html($option_value) . '</option>';
                    }
                    if ($verb_mood_value !== '' && !$verb_mood_found) {
                        echo '<option value="' . esc_attr($verb_mood_value) . '" selected>' . esc_html($verb_mood_value) . '</option>';
                    }
                    echo '</select>';
                    echo '</div>';
                }
                echo '</div>';

                if (!empty($edit_recordings)) {
                    echo '<button type="button" class="ll-word-edit-recordings-toggle" data-ll-word-recordings-toggle aria-expanded="false">';
                    echo '<span class="ll-word-edit-recordings-icon" aria-hidden="true">';
                    echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 10v4M9 6v12M14 8v8M19 11v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
                    echo '</span>';
                    echo '<span class="ll-word-edit-recordings-label">' . esc_html($edit_labels['recordings']) . '</span>';
                    echo '</button>';
                    echo '<div class="ll-word-edit-recordings" data-ll-word-recordings-panel aria-hidden="true">';
                    foreach ($edit_recordings as $recording) {
                        $recording_id = (int) ($recording['id'] ?? 0);
                        if ($recording_id <= 0) {
                            continue;
                        }
                        $recording_type = (string) ($recording['type'] ?? '');
                        $recording_label = (string) ($recording['label'] ?? $recording_type);
                        $recording_text = (string) ($recording['text'] ?? '');
                        $recording_translation = (string) ($recording['translation'] ?? '');
                        $recording_ipa = ll_tools_word_grid_normalize_ipa_output((string) ($recording['ipa'] ?? ''));
                        $recording_audio_url = (string) ($recording['audio_url'] ?? '');
                        $recording_text_id = 'll-word-edit-recording-text-' . $recording_id;
                        $recording_translation_id = 'll-word-edit-recording-translation-' . $recording_id;
                        $recording_ipa_id = 'll-word-edit-recording-ipa-' . $recording_id;
                        echo '<div class="ll-word-edit-recording" data-recording-id="' . esc_attr($recording_id) . '" data-recording-type="' . esc_attr($recording_type) . '">';
                        echo '<div class="ll-word-edit-recording-header">';
                        echo '<div class="ll-word-edit-recording-title">';
                        echo '<span class="ll-word-edit-recording-icon" aria-hidden="true"></span>';
                        echo '<span class="ll-word-edit-recording-name">' . esc_html($recording_label) . '</span>';
                        echo '</div>';
                        if ($recording_audio_url !== '') {
                            $recording_play_label = sprintf($play_label_template, $recording_label);
                            echo '<button type="button" class="ll-study-recording-btn ll-word-grid-recording-btn ll-word-edit-recording-btn ll-study-recording-btn--' . esc_attr($recording_type) . '" data-audio-url="' . esc_url($recording_audio_url) . '" data-recording-type="' . esc_attr($recording_type) . '" data-recording-id="' . esc_attr($recording_id) . '" aria-label="' . esc_attr($recording_play_label) . '" title="' . esc_attr($recording_play_label) . '">';
                            echo '<span class="ll-study-recording-icon" aria-hidden="true"></span>';
                            echo '<span class="ll-study-recording-visualizer" aria-hidden="true">';
                            for ($i = 0; $i < 4; $i++) {
                                echo '<span class="bar"></span>';
                            }
                            echo '</span>';
                            echo '</button>';
                        }
                        echo '</div>';
                        echo '<div class="ll-word-edit-recording-fields">';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_text_id) . '">' . esc_html($edit_labels['text']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_text_id) . '" data-ll-recording-input="text" value="' . esc_attr($recording_text) . '" />';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_translation_id) . '">' . esc_html($edit_labels['translation']) . '</label>';
                        echo '<input type="text" class="ll-word-edit-input" id="' . esc_attr($recording_translation_id) . '" data-ll-recording-input="translation" value="' . esc_attr($recording_translation) . '" />';
                        echo '<label class="ll-word-edit-label" for="' . esc_attr($recording_ipa_id) . '">' . esc_html($edit_labels['ipa']) . '</label>';
                        if ($recording_audio_url !== '') {
                            echo '<div class="ll-word-edit-ipa-audio" data-ll-ipa-audio aria-hidden="true">';
                            echo '<div class="ll-word-edit-ipa-waveform" data-ll-ipa-waveform aria-hidden="true">';
                            echo '<canvas class="ll-word-edit-ipa-waveform-canvas"></canvas>';
                            echo '</div>';
                            echo '<audio class="ll-word-edit-ipa-audio-player" controls preload="none" src="' . esc_url($recording_audio_url) . '"></audio>';
                            echo '</div>';
                        }
                        echo '<div class="ll-word-edit-ipa-target" data-ll-ipa-target aria-hidden="true" aria-label="' . esc_attr__('Transcription guide', 'll-tools-text-domain') . '">';
                        echo '<button type="button" class="ll-word-edit-ipa-shift ll-word-edit-ipa-shift--prev" data-ll-ipa-shift="prev" aria-label="' . esc_attr__('Previous letter', 'll-tools-text-domain') . '" title="' . esc_attr__('Previous letter', 'll-tools-text-domain') . '"><span aria-hidden="true">&lt;</span></button>';
                        echo '<div class="ll-word-edit-ipa-target-text" data-ll-ipa-target-text aria-live="polite"></div>';
                        echo '<button type="button" class="ll-word-edit-ipa-shift ll-word-edit-ipa-shift--next" data-ll-ipa-shift="next" aria-label="' . esc_attr__('Next letter', 'll-tools-text-domain') . '" title="' . esc_attr__('Next letter', 'll-tools-text-domain') . '"><span aria-hidden="true">&gt;</span></button>';
                        echo '</div>';
                        echo '<div class="ll-word-edit-input-wrap ll-word-edit-input-wrap--ipa">';
                        echo '<input type="text" class="ll-word-edit-input ll-word-edit-input--ipa" id="' . esc_attr($recording_ipa_id) . '" data-ll-recording-input="ipa" value="' . esc_attr($recording_ipa) . '" />';
                        echo '</div>';
                        echo '<div class="ll-word-edit-ipa-suggestions" data-ll-ipa-suggestions aria-hidden="true" aria-label="' . esc_attr__('IPA suggestions', 'll-tools-text-domain') . '"></div>';
                        echo '<button type="button" class="ll-word-edit-ipa-superscript" data-ll-ipa-superscript aria-hidden="true" aria-label="' . esc_attr($edit_labels['ipa_superscript']) . '" title="' . esc_attr($edit_labels['ipa_superscript']) . '"><span aria-hidden="true">x&sup2;</span></button>';
                        echo '<div class="ll-word-edit-ipa-keyboard" data-ll-ipa-keyboard aria-hidden="true" aria-label="' . esc_attr__('IPA symbols', 'll-tools-text-domain') . '"></div>';
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }

                echo '<div class="ll-word-edit-actions">';
                echo '<button type="button" class="ll-word-edit-action ll-word-edit-save" data-ll-word-edit-save aria-label="' . esc_attr($edit_labels['save']) . '" title="' . esc_attr($edit_labels['save']) . '">';
                echo '<span aria-hidden="true">';
                echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 13l4 4L19 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                echo '</span>';
                echo '</button>';
                echo '<button type="button" class="ll-word-edit-action ll-word-edit-cancel" data-ll-word-edit-cancel aria-label="' . esc_attr($edit_labels['cancel']) . '" title="' . esc_attr($edit_labels['cancel']) . '">';
                echo '<span aria-hidden="true">';
                echo '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                echo '</span>';
                echo '</button>';
                echo '</div>';
                echo '<div class="ll-word-edit-status" data-ll-word-edit-status aria-live="polite"></div>';
                echo '</div>';
            }

            // Audio buttons
            if ($has_recordings) {
                if ($has_recording_caption) {
                    $recordings_html .= '<div class="ll-word-recordings ll-word-recordings--with-text" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">';
                    foreach ($recording_rows as $row) {
                        $row_id_attr = !empty($row['id']) ? ' data-recording-id="' . esc_attr((int) $row['id']) . '"' : '';
                        $recordings_html .= '<div class="ll-word-recording-row"' . $row_id_attr . '>';
                        $recordings_html .= $row['button'];
                        if (!empty($row['text']) || !empty($row['translation']) || !empty($row['ipa'])) {
                            $recordings_html .= '<span class="ll-word-recording-text">';
                            if (!empty($row['text'])) {
                                $recordings_html .= '<span class="ll-word-recording-text-main" dir="auto">' . esc_html($row['text']) . '</span>';
                            }
                            if (!empty($row['translation'])) {
                                $recordings_html .= '<span class="ll-word-recording-text-translation" dir="auto">' . esc_html($row['translation']) . '</span>';
                            }
                            if (!empty($row['ipa'])) {
                                $recordings_html .= '<span class="ll-word-recording-ipa ll-ipa">' . ll_tools_word_grid_format_ipa_display_html((string) $row['ipa']) . '</span>';
                            }
                            $recordings_html .= '</span>';
                        }
                        $recordings_html .= '</div>';
                    }
                    $recordings_html .= '</div>';
                } else {
                    $recordings_html .= '<div class="ll-word-recordings" aria-label="' . esc_attr__('Recordings', 'll-tools-text-domain') . '">';
                    foreach ($recording_rows as $row) {
                        $recordings_html .= $row['button'];
                    }
                    $recordings_html .= '</div>';
                }
                echo $recordings_html;
            }
            echo '</div>'; // End of word-item
        }
        echo '</div>'; // End of word-grid
    } else {
        // No posts found
        echo '<p>' . esc_html__('No words found in this category.', 'll-tools-text-domain') . '</p>';
    }

    // Restore original Post Data
    wp_reset_postdata();

    // Get the buffer and return it
    return ob_get_clean();
}

function ll_tools_word_grid_parse_recordings_payload($raw): array {
    if (empty($raw)) {
        return [];
    }

    if (is_string($raw)) {
        $decoded = json_decode(stripslashes($raw), true);
    } elseif (is_array($raw)) {
        $decoded = function_exists('wp_unslash') ? wp_unslash($raw) : $raw;
    } else {
        return [];
    }

    if (!is_array($decoded)) {
        return [];
    }

    $parsed = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $recording_id = isset($entry['id']) ? (int) $entry['id'] : 0;
        if ($recording_id <= 0) {
            continue;
        }
        $parsed[] = [
            'id' => $recording_id,
            'text' => sanitize_text_field($entry['text'] ?? ''),
            'translation' => sanitize_text_field($entry['translation'] ?? ''),
            'ipa' => ll_tools_word_grid_sanitize_ipa((string) ($entry['ipa'] ?? '')),
        ];
    }

    return $parsed;
}

function ll_tools_resolve_audio_file_for_transcription(string $audio_path): array {
    $audio_path = trim($audio_path);
    if ($audio_path === '') {
        return [
            'path' => '',
            'is_temp' => false,
        ];
    }

    if (strpos($audio_path, 'http') === 0) {
        $parsed = wp_parse_url($audio_path);
        $home = wp_parse_url(home_url('/'));
        if (!empty($parsed['path']) && (!empty($home['host']) && (empty($parsed['host']) || $parsed['host'] === $home['host']))) {
            $local_path = ABSPATH . ltrim($parsed['path'], '/');
            if (file_exists($local_path) && is_readable($local_path)) {
                return [
                    'path' => $local_path,
                    'is_temp' => false,
                ];
            }
        }

        if (!function_exists('download_url') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (function_exists('download_url')) {
            $temp = download_url($audio_path);
            if (!is_wp_error($temp) && is_string($temp) && $temp !== '') {
                return [
                    'path' => $temp,
                    'is_temp' => true,
                ];
            }
        }

        return [
            'path' => '',
            'is_temp' => false,
        ];
    }

    $local_path = ABSPATH . ltrim($audio_path, '/');
    if (file_exists($local_path) && is_readable($local_path)) {
        return [
            'path' => $local_path,
            'is_temp' => false,
        ];
    }

    if (file_exists($audio_path) && is_readable($audio_path)) {
        return [
            'path' => $audio_path,
            'is_temp' => false,
        ];
    }

    return [
        'path' => '',
        'is_temp' => false,
    ];
}

function ll_tools_get_lesson_word_ids_for_transcription(int $wordset_id, int $category_id): array {
    $wordset_id = (int) $wordset_id;
    $category_id = (int) $category_id;
    if ($wordset_id <= 0 || $category_id <= 0) {
        return [];
    }

    $query = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => [$category_id],
            ],
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ],
    ]);

    $word_ids = array_values(array_filter(array_map('intval', (array) $query->posts), function ($id) { return $id > 0; }));
    if (empty($word_ids)) {
        return [];
    }

    if (function_exists('ll_get_deepest_categories')) {
        $filtered = [];
        foreach ($word_ids as $word_id) {
            $deepest_terms = ll_get_deepest_categories($word_id);
            $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
            if (in_array($category_id, array_map('intval', $deepest_ids), true)) {
                $filtered[] = $word_id;
            }
        }
        $word_ids = $filtered;
    }
    if (function_exists('ll_tools_filter_specific_wrong_answer_only_word_ids')) {
        $word_ids = ll_tools_filter_specific_wrong_answer_only_word_ids($word_ids);
    }

    return $word_ids;
}

function ll_tools_get_vocab_lesson_ids_from_post(int $lesson_id): array {
    if ($lesson_id <= 0) {
        return [0, 0];
    }
    $wordset_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true);
    $category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
    return [$wordset_id, $category_id];
}

function ll_tools_lesson_recording_belongs_to(int $recording_id, int $wordset_id, int $category_id): bool {
    $recording_id = (int) $recording_id;
    if ($recording_id <= 0 || $wordset_id <= 0 || $category_id <= 0) {
        return false;
    }
    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio') {
        return false;
    }
    $word_id = (int) $recording->post_parent;
    if ($word_id <= 0) {
        return false;
    }
    if (!has_term($wordset_id, 'wordset', $word_id) || !has_term($category_id, 'word-category', $word_id)) {
        return false;
    }
    if (function_exists('ll_get_deepest_categories')) {
        $deepest_terms = ll_get_deepest_categories($word_id);
        $deepest_ids = wp_list_pluck((array) $deepest_terms, 'term_id');
        if (!in_array($category_id, array_map('intval', $deepest_ids), true)) {
            return false;
        }
    }
    return true;
}

function ll_tools_can_transcribe_recordings(): bool {
    if (!function_exists('ll_tools_assemblyai_transcribe_audio_file') || !function_exists('ll_get_assemblyai_api_key')) {
        return false;
    }
    return ll_get_assemblyai_api_key() !== '';
}

function ll_tools_recording_is_isolation(int $recording_id): bool {
    if ($recording_id <= 0) {
        return false;
    }
    $terms = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
        return false;
    }
    return in_array('isolation', (array) $terms, true);
}

function ll_tools_trim_isolation_transcript(string $text): string {
    $trimmed = rtrim($text, " \t\n\r\0\x0B.,!?;:");
    return $trimmed !== '' ? $trimmed : trim($text);
}

function ll_tools_get_isolation_recording_data(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [
            'has_isolation' => false,
            'text' => '',
            'translation' => '',
        ];
    }

    $recording_ids = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent'    => $word_id,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'recording_type',
                'field'    => 'slug',
                'terms'    => ['isolation'],
            ],
        ],
    ]);

    $recording_ids = array_values(array_filter(array_map('intval', (array) $recording_ids), function ($id) { return $id > 0; }));
    $data = [
        'has_isolation' => !empty($recording_ids),
        'text' => '',
        'translation' => '',
    ];

    if (empty($recording_ids)) {
        return $data;
    }

    foreach ($recording_ids as $recording_id) {
        $text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        $translation = trim((string) get_post_meta($recording_id, 'recording_translation', true));
        if ($text !== '' || $translation !== '') {
            $data['text'] = $text;
            $data['translation'] = $translation;
            break;
        }
    }

    return $data;
}

function ll_tools_fill_missing_word_fields_from_recording(int $word_id, string $candidate_text, string $candidate_translation): array {
    $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    $store_in_title = isset($display_values['store_in_title']) ? (bool) $display_values['store_in_title'] : true;
    $current_text = trim((string) ($display_values['word_text'] ?? ''));
    $current_translation = trim((string) ($display_values['translation_text'] ?? ''));

    $candidate_text = trim($candidate_text);
    $candidate_translation = trim($candidate_translation);

    $updated = false;
    if ($current_text === '' && $candidate_text !== '') {
        $word_text = function_exists('ll_sanitize_word_title_text')
            ? ll_sanitize_word_title_text($candidate_text)
            : $candidate_text;
        if ($store_in_title) {
            wp_update_post([
                'ID' => $word_id,
                'post_title' => $word_text,
            ]);
        } else {
            update_post_meta($word_id, 'word_translation', $word_text);
        }
        $updated = true;
    }

    if ($current_translation === '' && $candidate_translation !== '') {
        $translation_text = sanitize_text_field($candidate_translation);
        if ($store_in_title) {
            update_post_meta($word_id, 'word_translation', $translation_text);
        } else {
            wp_update_post([
                'ID' => $word_id,
                'post_title' => $translation_text,
            ]);
        }
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
        $updated = true;
    }

    if ($updated) {
        $display_values = ll_tools_word_grid_resolve_display_text($word_id);
    }

    return [
        'updated' => $updated,
        'word_text' => (string) ($display_values['word_text'] ?? ''),
        'word_translation' => (string) ($display_values['translation_text'] ?? ''),
    ];
}

function ll_tools_word_grid_bump_category_cache_for_words(array $word_ids, int $fallback_category_id = 0): void {
    if (!function_exists('ll_tools_bump_category_cache_version')) {
        return;
    }

    $touched = [];
    foreach ($word_ids as $word_id) {
        $word_id = (int) $word_id;
        if ($word_id <= 0) {
            continue;
        }
        $term_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($term_ids)) {
            continue;
        }
        foreach ($term_ids as $term_id) {
            $term_id = (int) $term_id;
            if ($term_id > 0) {
                $touched[$term_id] = true;
            }
        }
    }

    if (empty($touched) && $fallback_category_id > 0) {
        $touched[$fallback_category_id] = true;
    }

    if (!empty($touched)) {
        ll_tools_bump_category_cache_version(array_keys($touched));
    }
}

add_action('wp_ajax_ll_tools_word_grid_update_word', 'll_tools_word_grid_update_word_handler');
function ll_tools_word_grid_update_word_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('You must be logged in', 401);
    }

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }

    $word_id = (int) ($_POST['word_id'] ?? 0);
    if ($word_id <= 0) {
        wp_send_json_error('Missing word ID', 400);
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        wp_send_json_error('Invalid word ID', 404);
    }

    $word_text_raw = sanitize_text_field($_POST['word_text'] ?? '');
    $word_text = function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($word_text_raw)
        : trim($word_text_raw);
    $translation_text = sanitize_text_field($_POST['word_translation'] ?? '');
    $translation_text = trim($translation_text);

    $store_in_title = function_exists('ll_tools_should_store_word_in_title')
        ? ll_tools_should_store_word_in_title($word_id)
        : true;
    $new_title = $word_post->post_title;
    if ($store_in_title) {
        $new_title = $word_text;
    } else {
        $new_title = $translation_text;
    }

    if ($new_title !== $word_post->post_title) {
        wp_update_post([
            'ID' => $word_id,
            'post_title' => $new_title,
        ]);
    }

    if ($translation_text !== '') {
        update_post_meta($word_id, 'word_english_meaning', $translation_text);
    } else {
        delete_post_meta($word_id, 'word_english_meaning');
    }
    if ($store_in_title) {
        if ($translation_text !== '') {
            update_post_meta($word_id, 'word_translation', $translation_text);
        } else {
            delete_post_meta($word_id, 'word_translation');
        }
    } else {
        if ($word_text !== '') {
            update_post_meta($word_id, 'word_translation', $word_text);
        } else {
            delete_post_meta($word_id, 'word_translation');
        }
    }

    $word_note = trim((string) get_post_meta($word_id, 'll_word_usage_note', true));
    if (array_key_exists('word_note', $_POST)) {
        $word_note_raw = isset($_POST['word_note']) ? wp_unslash($_POST['word_note']) : '';
        $sanitized_note = sanitize_textarea_field((string) $word_note_raw);
        $sanitized_note = trim($sanitized_note);
        if ($sanitized_note !== '') {
            update_post_meta($word_id, 'll_word_usage_note', $sanitized_note);
        } else {
            delete_post_meta($word_id, 'll_word_usage_note');
        }
        $word_note = $sanitized_note;
    }

    $dictionary_entry_id = function_exists('ll_tools_get_word_dictionary_entry_id')
        ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
        : 0;
    $dictionary_entry_title = $dictionary_entry_id > 0
        ? trim((string) get_the_title($dictionary_entry_id))
        : '';
    $dictionary_entry_submitted = array_key_exists('dictionary_entry_id', $_POST)
        || array_key_exists('dictionary_entry_title', $_POST);
    if ($dictionary_entry_submitted && function_exists('ll_tools_assign_dictionary_entry_to_word')) {
        $submitted_dictionary_entry_id = isset($_POST['dictionary_entry_id'])
            ? absint($_POST['dictionary_entry_id'])
            : 0;
        $submitted_dictionary_entry_title = isset($_POST['dictionary_entry_title'])
            ? sanitize_text_field(wp_unslash((string) $_POST['dictionary_entry_title']))
            : '';

        $dictionary_result = ll_tools_assign_dictionary_entry_to_word(
            $word_id,
            $submitted_dictionary_entry_id,
            $submitted_dictionary_entry_title
        );
        if (!is_wp_error($dictionary_result)) {
            $dictionary_entry_id = isset($dictionary_result['entry_id']) ? (int) $dictionary_result['entry_id'] : 0;
            $dictionary_entry_title = isset($dictionary_result['entry_title']) ? trim((string) $dictionary_result['entry_title']) : '';
        }
    }
    if (!$dictionary_entry_submitted && function_exists('ll_tools_get_word_dictionary_entry_id')) {
        $dictionary_entry_id = (int) ll_tools_get_word_dictionary_entry_id($word_id);
        $dictionary_entry_title = $dictionary_entry_id > 0
            ? trim((string) get_the_title($dictionary_entry_id))
            : '';
    }

    $pos_updated = array_key_exists('part_of_speech', $_POST);
    $pos_slug = '';
    $pos_label = '';
    if ($pos_updated) {
        $pos_slug = sanitize_text_field($_POST['part_of_speech'] ?? '');
        $pos_slug = sanitize_title($pos_slug);
        if ($pos_slug !== '') {
            $pos_term = get_term_by('slug', $pos_slug, 'part_of_speech');
            if ($pos_term && !is_wp_error($pos_term)) {
                wp_set_object_terms($word_id, [$pos_term->term_id], 'part_of_speech', false);
                $pos_slug = (string) $pos_term->slug;
                $pos_label = (string) $pos_term->name;
            } else {
                $pos_slug = '';
                wp_set_object_terms($word_id, [], 'part_of_speech', false);
            }
        } else {
            wp_set_object_terms($word_id, [], 'part_of_speech', false);
        }
    }
    if (!$pos_updated) {
        $pos_terms = wp_get_post_terms($word_id, 'part_of_speech', ['orderby' => 'name', 'order' => 'ASC']);
        if (!is_wp_error($pos_terms) && !empty($pos_terms)) {
            $pos_slug = (string) $pos_terms[0]->slug;
            $pos_label = (string) $pos_terms[0]->name;
        }
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    if ($wordset_id <= 0) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }
    if ($wordset_id > 0 && !has_term($wordset_id, 'wordset', $word_id)) {
        $wordset_id = ll_tools_word_grid_get_wordset_id_for_word($word_id);
    }
    $gender_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($wordset_id)
        : false;
    $plurality_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality'))
        ? ll_tools_wordset_has_plurality($wordset_id)
        : false;
    $verb_tense_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense'))
        ? ll_tools_wordset_has_verb_tense($wordset_id)
        : false;
    $verb_mood_enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood'))
        ? ll_tools_wordset_has_verb_mood($wordset_id)
        : false;
    $is_noun = ($pos_slug === 'noun');
    $is_verb = ($pos_slug === 'verb');
    $gender_value = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
    $gender_label = '';
    $gender_display = [
        'value' => '',
        'label' => '',
        'role' => 'other',
        'style' => '',
        'html' => '',
    ];
    $gender_submitted = array_key_exists('grammatical_gender', $_POST);
    if ($gender_submitted || $pos_updated) {
        $submitted_gender = sanitize_text_field($_POST['grammatical_gender'] ?? '');
        $submitted_gender = trim($submitted_gender);
        if (!$gender_enabled || !$is_noun) {
            if ($gender_value !== '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
            }
            $gender_value = '';
        } else {
            $allowed = function_exists('ll_tools_wordset_get_gender_options')
                ? ll_tools_wordset_get_gender_options($wordset_id)
                : [];
            if ($submitted_gender === '') {
                delete_post_meta($word_id, 'll_grammatical_gender');
                $gender_value = '';
            } elseif (in_array($submitted_gender, $allowed, true) || $submitted_gender === $gender_value) {
                update_post_meta($word_id, 'll_grammatical_gender', $submitted_gender);
                $gender_value = $submitted_gender;
            } else {
                delete_post_meta($word_id, 'll_grammatical_gender');
                $gender_value = '';
            }
        }
    }
    if (!$gender_enabled || !$is_noun) {
        $gender_value = '';
        $gender_label = '';
    } else {
        if ($gender_value !== '' && function_exists('ll_tools_wordset_get_gender_display_data')) {
            $gender_display = ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value);
            $gender_label = (string) ($gender_display['label'] ?? '');
        } elseif ($gender_value !== '' && function_exists('ll_tools_wordset_get_gender_label')) {
            $gender_label = ll_tools_wordset_get_gender_label($wordset_id, $gender_value);
            $gender_display['label'] = $gender_label;
        } else {
            $gender_label = $gender_value;
            $gender_display['label'] = $gender_label;
        }
    }
    $plurality_value = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
    $plurality_label = '';
    $plurality_submitted = array_key_exists('grammatical_plurality', $_POST);
    if ($plurality_submitted || $pos_updated) {
        $submitted_plurality = sanitize_text_field($_POST['grammatical_plurality'] ?? '');
        $submitted_plurality = trim($submitted_plurality);
        if (!$plurality_enabled || !$is_noun) {
            if ($plurality_value !== '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            }
            $plurality_value = '';
        } else {
            $plurality_allowed = function_exists('ll_tools_wordset_get_plurality_options')
                ? ll_tools_wordset_get_plurality_options($wordset_id)
                : [];
            if ($submitted_plurality === '') {
                delete_post_meta($word_id, 'll_grammatical_plurality');
                $plurality_value = '';
            } elseif (in_array($submitted_plurality, $plurality_allowed, true) || $submitted_plurality === $plurality_value) {
                update_post_meta($word_id, 'll_grammatical_plurality', $submitted_plurality);
                $plurality_value = $submitted_plurality;
            } else {
                delete_post_meta($word_id, 'll_grammatical_plurality');
                $plurality_value = '';
            }
        }
    }
    if (!$plurality_enabled || !$is_noun) {
        $plurality_value = '';
        $plurality_label = '';
    } elseif ($plurality_value !== '' && function_exists('ll_tools_wordset_get_plurality_label')) {
        $plurality_label = ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value);
    } else {
        $plurality_label = $plurality_value;
    }
    $verb_tense_value = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
    $verb_tense_label = '';
    $verb_tense_submitted = array_key_exists('verb_tense', $_POST);
    if ($verb_tense_submitted || $pos_updated) {
        $submitted_verb_tense = sanitize_text_field($_POST['verb_tense'] ?? '');
        $submitted_verb_tense = trim($submitted_verb_tense);
        if (!$verb_tense_enabled || !$is_verb) {
            if ($verb_tense_value !== '') {
                delete_post_meta($word_id, 'll_verb_tense');
            }
            $verb_tense_value = '';
        } else {
            $verb_tense_allowed = function_exists('ll_tools_wordset_get_verb_tense_options')
                ? ll_tools_wordset_get_verb_tense_options($wordset_id)
                : [];
            if ($submitted_verb_tense === '') {
                delete_post_meta($word_id, 'll_verb_tense');
                $verb_tense_value = '';
            } elseif (in_array($submitted_verb_tense, $verb_tense_allowed, true) || $submitted_verb_tense === $verb_tense_value) {
                update_post_meta($word_id, 'll_verb_tense', $submitted_verb_tense);
                $verb_tense_value = $submitted_verb_tense;
            } else {
                delete_post_meta($word_id, 'll_verb_tense');
                $verb_tense_value = '';
            }
        }
    }
    if (!$verb_tense_enabled || !$is_verb) {
        $verb_tense_value = '';
        $verb_tense_label = '';
    } elseif ($verb_tense_value !== '' && function_exists('ll_tools_wordset_get_verb_tense_label')) {
        $verb_tense_label = ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value);
    } else {
        $verb_tense_label = $verb_tense_value;
    }

    $verb_mood_value = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
    $verb_mood_label = '';
    $verb_mood_submitted = array_key_exists('verb_mood', $_POST);
    if ($verb_mood_submitted || $pos_updated) {
        $submitted_verb_mood = sanitize_text_field($_POST['verb_mood'] ?? '');
        $submitted_verb_mood = trim($submitted_verb_mood);
        if (!$verb_mood_enabled || !$is_verb) {
            if ($verb_mood_value !== '') {
                delete_post_meta($word_id, 'll_verb_mood');
            }
            $verb_mood_value = '';
        } else {
            $verb_mood_allowed = function_exists('ll_tools_wordset_get_verb_mood_options')
                ? ll_tools_wordset_get_verb_mood_options($wordset_id)
                : [];
            if ($submitted_verb_mood === '') {
                delete_post_meta($word_id, 'll_verb_mood');
                $verb_mood_value = '';
            } elseif (in_array($submitted_verb_mood, $verb_mood_allowed, true) || $submitted_verb_mood === $verb_mood_value) {
                update_post_meta($word_id, 'll_verb_mood', $submitted_verb_mood);
                $verb_mood_value = $submitted_verb_mood;
            } else {
                delete_post_meta($word_id, 'll_verb_mood');
                $verb_mood_value = '';
            }
        }
    }
    if (!$verb_mood_enabled || !$is_verb) {
        $verb_mood_value = '';
        $verb_mood_label = '';
    } elseif ($verb_mood_value !== '' && function_exists('ll_tools_wordset_get_verb_mood_label')) {
        $verb_mood_label = ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value);
    } else {
        $verb_mood_label = $verb_mood_value;
    }

    $recordings_payload = ll_tools_word_grid_parse_recordings_payload($_POST['recordings'] ?? '');
    $recordings_out = [];
    foreach ($recordings_payload as $recording) {
        $recording_id = (int) ($recording['id'] ?? 0);
        if ($recording_id <= 0) {
            continue;
        }
        $recording_post = get_post($recording_id);
        if (!$recording_post || $recording_post->post_type !== 'word_audio') {
            continue;
        }
        if ((int) $recording_post->post_parent !== $word_id) {
            continue;
        }

        $recording_text = (string) ($recording['text'] ?? '');
        $recording_translation = (string) ($recording['translation'] ?? '');
        $recording_ipa = (string) ($recording['ipa'] ?? '');

        if ($recording_text !== '') {
            update_post_meta($recording_id, 'recording_text', $recording_text);
        } else {
            delete_post_meta($recording_id, 'recording_text');
        }
        if ($recording_translation !== '') {
            update_post_meta($recording_id, 'recording_translation', $recording_translation);
        } else {
            delete_post_meta($recording_id, 'recording_translation');
        }
        if ($recording_ipa !== '') {
            update_post_meta($recording_id, 'recording_ipa', $recording_ipa);
        } else {
            delete_post_meta($recording_id, 'recording_ipa');
        }

        $recordings_out[] = [
            'id' => $recording_id,
            'recording_text' => (string) get_post_meta($recording_id, 'recording_text', true),
            'recording_translation' => (string) get_post_meta($recording_id, 'recording_translation', true),
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true)),
        ];
    }

    ll_tools_word_grid_update_wordset_ipa_special_chars($word_id, '');
    ll_tools_word_grid_update_wordset_ipa_letter_map($word_id);

    ll_tools_word_grid_bump_category_cache_for_words([$word_id]);

    $display_values = ll_tools_word_grid_resolve_display_text($word_id);

    wp_send_json_success([
        'word_id' => $word_id,
        'word_text' => $display_values['word_text'],
        'word_translation' => $display_values['translation_text'],
        'word_note' => $word_note,
        'dictionary_entry' => [
            'id' => $dictionary_entry_id,
            'title' => $dictionary_entry_title,
        ],
        'recordings' => $recordings_out,
        'part_of_speech' => [
            'slug' => $pos_slug,
            'label' => $pos_label,
        ],
        'grammatical_gender' => [
            'value' => $gender_value,
            'label' => $gender_label,
            'role' => (string) ($gender_display['role'] ?? ''),
            'style' => (string) ($gender_display['style'] ?? ''),
            'html' => (string) ($gender_display['html'] ?? ''),
        ],
        'grammatical_plurality' => [
            'value' => $plurality_value,
            'label' => $plurality_label,
        ],
        'verb_tense' => [
            'value' => $verb_tense_value,
            'label' => $verb_tense_label,
        ],
        'verb_mood' => [
            'value' => $verb_mood_value,
            'label' => $verb_mood_label,
        ],
    ]);
}

add_action('wp_ajax_ll_tools_search_dictionary_entries', 'll_tools_search_dictionary_entries_handler');
function ll_tools_search_dictionary_entries_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }

    $query = sanitize_text_field($_POST['q'] ?? '');
    $limit = (int) ($_POST['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }
    $limit = min(50, $limit);

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $word_id = isset($_POST['word_id']) ? (int) $_POST['word_id'] : 0;
    if ($word_id > 0 && get_post_type($word_id) === 'words') {
        $resolved_wordset_id = function_exists('ll_tools_word_grid_get_wordset_id_for_word')
            ? (int) ll_tools_word_grid_get_wordset_id_for_word($word_id)
            : 0;
        if ($resolved_wordset_id > 0) {
            $wordset_id = $resolved_wordset_id;
        }
    }

    $entries = function_exists('ll_tools_search_dictionary_entries')
        ? ll_tools_search_dictionary_entries($query, $limit, $wordset_id)
        : [];

    wp_send_json_success([
        'entries' => array_values((array) $entries),
    ]);
}

add_action('wp_ajax_ll_tools_word_grid_bulk_update', 'll_tools_word_grid_bulk_update_handler');
function ll_tools_word_grid_bulk_update_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }

    $wordset_id = (int) ($_POST['wordset_id'] ?? 0);
    $category_id = (int) ($_POST['category_id'] ?? 0);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing wordset or category', 400);
    }

    $mode = sanitize_text_field($_POST['mode'] ?? '');
    if (!in_array($mode, ['pos', 'gender', 'plurality', 'verb_tense', 'verb_mood'], true)) {
        wp_send_json_error('Invalid mode', 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($word_ids)) {
        wp_send_json_success([
            'word_ids' => [],
            'count' => 0,
        ]);
    }

    if ($mode === 'pos') {
        $pos_slug = sanitize_text_field($_POST['part_of_speech'] ?? '');
        $pos_slug = sanitize_title($pos_slug);
        if ($pos_slug === '') {
            wp_send_json_error('Missing part of speech', 400);
        }
        $term = get_term_by('slug', $pos_slug, 'part_of_speech');
        if (!$term || is_wp_error($term)) {
            wp_send_json_error('Invalid part of speech', 400);
        }

        $clear_gender = ($term->slug !== 'noun');
        $clear_plurality = ($term->slug !== 'noun');
        $clear_verb_tense = ($term->slug !== 'verb');
        $clear_verb_mood = ($term->slug !== 'verb');
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            wp_set_object_terms($word_id, [(int) $term->term_id], 'part_of_speech', false);
            if ($clear_gender) {
                delete_post_meta($word_id, 'll_grammatical_gender');
            }
            if ($clear_plurality) {
                delete_post_meta($word_id, 'll_grammatical_plurality');
            }
            if ($clear_verb_tense) {
                delete_post_meta($word_id, 'll_verb_tense');
            }
            if ($clear_verb_mood) {
                delete_post_meta($word_id, 'll_verb_mood');
            }
        }

        ll_tools_word_grid_bump_category_cache_for_words($word_ids, $category_id);

        wp_send_json_success([
            'word_ids' => $word_ids,
            'count' => count($word_ids),
            'part_of_speech' => [
                'slug' => (string) $term->slug,
                'label' => (string) $term->name,
            ],
            'gender_cleared' => $clear_gender,
            'plurality_cleared' => $clear_plurality,
            'verb_tense_cleared' => $clear_verb_tense,
            'verb_mood_cleared' => $clear_verb_mood,
        ]);
    }

    if ($mode === 'gender') {
        if (!function_exists('ll_tools_wordset_has_grammatical_gender') || !ll_tools_wordset_has_grammatical_gender($wordset_id)) {
            wp_send_json_error('Gender not enabled', 400);
        }

        $gender_value = sanitize_text_field($_POST['grammatical_gender'] ?? '');
        $gender_value = trim($gender_value);
        if ($gender_value === '') {
            wp_send_json_error('Missing gender', 400);
        }

        $allowed = function_exists('ll_tools_wordset_get_gender_options')
            ? ll_tools_wordset_get_gender_options($wordset_id)
            : [];
        if (!in_array($gender_value, $allowed, true)) {
            wp_send_json_error('Invalid gender', 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'noun') {
                continue;
            }
            update_post_meta($word_id, 'll_grammatical_gender', $gender_value);
            $updated[] = $word_id;
        }

        $gender_display = function_exists('ll_tools_wordset_get_gender_display_data')
            ? ll_tools_wordset_get_gender_display_data($wordset_id, $gender_value)
            : [
                'value' => $gender_value,
                'label' => (function_exists('ll_tools_wordset_get_gender_label')
                    ? ll_tools_wordset_get_gender_label($wordset_id, $gender_value)
                    : $gender_value),
                'role' => '',
                'style' => '',
                'html' => '',
            ];
        $gender_label = (string) ($gender_display['label'] ?? $gender_value);

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'grammatical_gender' => [
                'value' => $gender_value,
                'label' => $gender_label,
                'role' => (string) ($gender_display['role'] ?? ''),
                'style' => (string) ($gender_display['style'] ?? ''),
                'html' => (string) ($gender_display['html'] ?? ''),
            ],
        ]);
    }

    if ($mode === 'plurality') {
        if (!function_exists('ll_tools_wordset_has_plurality') || !ll_tools_wordset_has_plurality($wordset_id)) {
            wp_send_json_error('Plurality not enabled', 400);
        }

        $plurality_value = sanitize_text_field($_POST['grammatical_plurality'] ?? '');
        $plurality_value = trim($plurality_value);
        if ($plurality_value === '') {
            wp_send_json_error('Missing plurality', 400);
        }

        $plurality_allowed = function_exists('ll_tools_wordset_get_plurality_options')
            ? ll_tools_wordset_get_plurality_options($wordset_id)
            : [];
        if (!in_array($plurality_value, $plurality_allowed, true)) {
            wp_send_json_error('Invalid plurality', 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'noun') {
                continue;
            }
            update_post_meta($word_id, 'll_grammatical_plurality', $plurality_value);
            $updated[] = $word_id;
        }

        $plurality_label = function_exists('ll_tools_wordset_get_plurality_label')
            ? ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value)
            : $plurality_value;

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'grammatical_plurality' => [
                'value' => $plurality_value,
                'label' => $plurality_label,
            ],
        ]);
    }

    if ($mode === 'verb_tense') {
        if (!function_exists('ll_tools_wordset_has_verb_tense') || !ll_tools_wordset_has_verb_tense($wordset_id)) {
            wp_send_json_error('Verb tense not enabled', 400);
        }

        $verb_tense_value = sanitize_text_field($_POST['verb_tense'] ?? '');
        $verb_tense_value = trim($verb_tense_value);
        if ($verb_tense_value === '') {
            wp_send_json_error('Missing verb tense', 400);
        }

        $verb_tense_allowed = function_exists('ll_tools_wordset_get_verb_tense_options')
            ? ll_tools_wordset_get_verb_tense_options($wordset_id)
            : [];
        if (!in_array($verb_tense_value, $verb_tense_allowed, true)) {
            wp_send_json_error('Invalid verb tense', 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'verb') {
                continue;
            }
            update_post_meta($word_id, 'll_verb_tense', $verb_tense_value);
            $updated[] = $word_id;
        }

        $verb_tense_label = function_exists('ll_tools_wordset_get_verb_tense_label')
            ? ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value)
            : $verb_tense_value;

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'verb_tense' => [
                'value' => $verb_tense_value,
                'label' => $verb_tense_label,
            ],
        ]);
    }

    if ($mode === 'verb_mood') {
        if (!function_exists('ll_tools_wordset_has_verb_mood') || !ll_tools_wordset_has_verb_mood($wordset_id)) {
            wp_send_json_error('Verb mood not enabled', 400);
        }

        $verb_mood_value = sanitize_text_field($_POST['verb_mood'] ?? '');
        $verb_mood_value = trim($verb_mood_value);
        if ($verb_mood_value === '') {
            wp_send_json_error('Missing verb mood', 400);
        }

        $verb_mood_allowed = function_exists('ll_tools_wordset_get_verb_mood_options')
            ? ll_tools_wordset_get_verb_mood_options($wordset_id)
            : [];
        if (!in_array($verb_mood_value, $verb_mood_allowed, true)) {
            wp_send_json_error('Invalid verb mood', 400);
        }

        $pos_map = ll_tools_word_grid_collect_part_of_speech_terms($word_ids);
        $updated = [];
        foreach ($word_ids as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }
            $pos_slug = isset($pos_map[$word_id]['slug']) ? (string) $pos_map[$word_id]['slug'] : '';
            if ($pos_slug !== 'verb') {
                continue;
            }
            update_post_meta($word_id, 'll_verb_mood', $verb_mood_value);
            $updated[] = $word_id;
        }

        $verb_mood_label = function_exists('ll_tools_wordset_get_verb_mood_label')
            ? ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value)
            : $verb_mood_value;

        if (!empty($updated)) {
            ll_tools_word_grid_bump_category_cache_for_words($updated, $category_id);
        }

        wp_send_json_success([
            'word_ids' => $updated,
            'count' => count($updated),
            'skipped' => max(0, count($word_ids) - count($updated)),
            'verb_mood' => [
                'value' => $verb_mood_value,
                'label' => $verb_mood_label,
            ],
        ]);
    }
}

add_action('wp_ajax_ll_tools_get_lesson_transcribe_queue', 'll_tools_get_lesson_transcribe_queue_handler');
function ll_tools_get_lesson_transcribe_queue_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }
    if (!ll_tools_can_transcribe_recordings()) {
        wp_send_json_error('Transcription service not configured', 400);
    }

    $mode = sanitize_text_field($_POST['mode'] ?? 'missing');
    $include_existing = in_array($mode, ['all', 'replace'], true);

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error('Invalid lesson', 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing lesson metadata', 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($word_ids)) {
        wp_send_json_success([
            'queue' => [],
            'total' => 0,
        ]);
    }

    $recording_ids = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent__in'=> $word_ids,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    $queue = [];
    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        $text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        if (!$include_existing && $text !== '') {
            continue;
        }
        if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
            continue;
        }
        $queue[] = [
            'recording_id' => $recording_id,
        ];
    }

    wp_send_json_success([
        'queue' => $queue,
        'total' => count($queue),
    ]);
}

add_action('wp_ajax_ll_tools_clear_lesson_transcriptions', 'll_tools_clear_lesson_transcriptions_handler');
function ll_tools_clear_lesson_transcriptions_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $lesson = $lesson_id ? get_post($lesson_id) : null;
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error('Invalid lesson', 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing lesson metadata', 400);
    }

    $word_ids = ll_tools_get_lesson_word_ids_for_transcription($wordset_id, $category_id);
    if (empty($word_ids)) {
        wp_send_json_success([
            'cleared' => [],
            'total' => 0,
        ]);
    }

    $recording_ids = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'post_parent__in'=> $word_ids,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    $cleared = [];
    foreach ($recording_ids as $recording_id) {
        $recording_id = (int) $recording_id;
        if ($recording_id <= 0) {
            continue;
        }
        if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
            continue;
        }
        delete_post_meta($recording_id, 'recording_text');
        delete_post_meta($recording_id, 'recording_translation');
        $cleared[] = $recording_id;
    }

    wp_send_json_success([
        'cleared' => $cleared,
        'total' => count($cleared),
    ]);
}

/**
 * Finalize a lesson recording transcription and return the payload used by the grid UI.
 *
 * @param int     $recording_id
 * @param WP_Post $recording
 * @param int[]   $wordset_ids
 * @param bool    $force
 * @param string  $raw_transcript
 * @return array|WP_Error
 */
function ll_tools_word_grid_finalize_transcription($recording_id, $recording, $wordset_ids, $force, $raw_transcript) {
    $transcript = trim((string) $raw_transcript);
    if ($transcript !== '' && function_exists('ll_tools_normalize_transcript_case')) {
        $transcript = ll_tools_normalize_transcript_case($transcript, $wordset_ids);
    }
    if ($transcript === '') {
        return new WP_Error('empty_transcript', __('Empty transcript', 'll-tools-text-domain'));
    }

    $recording_text = sanitize_text_field($transcript);
    $recording_is_isolation = ll_tools_recording_is_isolation($recording_id);
    if ($recording_is_isolation) {
        $recording_text = ll_tools_trim_isolation_transcript($recording_text);
    }
    update_post_meta($recording_id, 'recording_text', $recording_text);

    $recording_translation = '';
    $existing_translation = trim((string) get_post_meta($recording_id, 'recording_translation', true));
    $can_translate = ($force || $existing_translation === '')
        && ((string) get_option('ll_deepl_api_key') !== '')
        && function_exists('translate_with_deepl')
        && function_exists('ll_tools_get_deepl_language_codes');
    if ($can_translate) {
        [$source_lang, $target_lang] = ll_tools_get_deepl_language_codes($wordset_ids);
        if ($target_lang !== '') {
            $translated = translate_with_deepl($recording_text, $target_lang, $source_lang);
            if (is_string($translated) && $translated !== '') {
                $recording_translation = sanitize_text_field($translated);
                update_post_meta($recording_id, 'recording_translation', $recording_translation);
            }
        }
    } elseif ($existing_translation !== '') {
        $recording_translation = $existing_translation;
    }
    if ($recording_translation === '' && $existing_translation !== '') {
        $recording_translation = $existing_translation;
    }

    $word_payload = null;
    $word_id = (int) $recording->post_parent;
    if ($word_id > 0) {
        $display_values = ll_tools_word_grid_resolve_display_text($word_id);
        $missing_word_text = trim((string) ($display_values['word_text'] ?? '')) === '';
        $missing_translation = trim((string) ($display_values['translation_text'] ?? '')) === '';
        if ($missing_word_text || $missing_translation) {
            $candidate_text = '';
            $candidate_translation = '';
            $isolation_data = ll_tools_get_isolation_recording_data($word_id);
            $has_isolation = !empty($isolation_data['has_isolation']);

            if ($has_isolation) {
                if ($recording_is_isolation) {
                    $candidate_text = $recording_text !== '' ? $recording_text : (string) ($isolation_data['text'] ?? '');
                    $candidate_translation = $recording_translation !== '' ? $recording_translation : (string) ($isolation_data['translation'] ?? '');
                } elseif (!empty($isolation_data['text']) || !empty($isolation_data['translation'])) {
                    $candidate_text = (string) ($isolation_data['text'] ?? '');
                    $candidate_translation = (string) ($isolation_data['translation'] ?? '');
                }
            } else {
                $candidate_text = $recording_text;
                $candidate_translation = $recording_translation;
            }

            if ($candidate_text !== '' || $candidate_translation !== '') {
                $word_update = ll_tools_fill_missing_word_fields_from_recording(
                    $word_id,
                    $candidate_text,
                    $candidate_translation
                );
                $word_payload = [
                    'id' => $word_id,
                    'word_text' => (string) ($word_update['word_text'] ?? ''),
                    'word_translation' => (string) ($word_update['word_translation'] ?? ''),
                    'updated' => !empty($word_update['updated']),
                ];
            }
        }
    }

    $response = [
        'recording' => [
            'id' => $recording_id,
            'word_id' => $word_id,
            'recording_text' => $recording_text,
            'recording_translation' => $recording_translation,
            'recording_ipa' => ll_tools_word_grid_normalize_ipa_output((string) get_post_meta($recording_id, 'recording_ipa', true)),
        ],
    ];
    if ($word_payload) {
        $response['word'] = $word_payload;
    }

    return $response;
}

add_action('wp_ajax_ll_tools_transcribe_recording_by_id', 'll_tools_transcribe_recording_by_id_handler');
function ll_tools_transcribe_recording_by_id_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_user_can_edit_vocab_words()) {
        wp_send_json_error('Forbidden', 403);
    }
    if (!ll_tools_can_transcribe_recordings()) {
        wp_send_json_error('Transcription service not configured', 400);
    }
    if (!function_exists('ll_tools_assemblyai_start_transcription') || !function_exists('ll_tools_assemblyai_get_transcript')) {
        wp_send_json_error(__('AssemblyAI integration not available', 'll-tools-text-domain'), 400);
    }

    $lesson_id = (int) ($_POST['lesson_id'] ?? 0);
    $recording_id = (int) ($_POST['recording_id'] ?? 0);
    $force = !empty($_POST['force']);
    $posted_transcript_id = sanitize_text_field($_POST['transcript_id'] ?? '');
    if ($lesson_id <= 0 || $recording_id <= 0) {
        wp_send_json_error('Missing data', 400);
    }

    $lesson = get_post($lesson_id);
    if (!$lesson || $lesson->post_type !== 'll_vocab_lesson') {
        wp_send_json_error('Invalid lesson', 400);
    }

    [$wordset_id, $category_id] = ll_tools_get_vocab_lesson_ids_from_post($lesson_id);
    if ($wordset_id <= 0 || $category_id <= 0) {
        wp_send_json_error('Missing lesson metadata', 400);
    }

    if (!ll_tools_lesson_recording_belongs_to($recording_id, $wordset_id, $category_id)) {
        wp_send_json_error('Recording not in lesson', 403);
    }

    $recording = get_post($recording_id);
    if (!$recording || $recording->post_type !== 'word_audio') {
        wp_send_json_error('Invalid recording', 400);
    }

    $wordset_ids = [$wordset_id];
    $language_code = function_exists('ll_tools_get_assemblyai_language_code')
        ? ll_tools_get_assemblyai_language_code($wordset_ids)
        : '';
    $transcript_meta_key = 'll_tools_assemblyai_transcript_id';
    $stored_transcript_id = trim((string) get_post_meta($recording_id, $transcript_meta_key, true));

    if ($posted_transcript_id !== '') {
        if ($stored_transcript_id === '') {
            wp_send_json_error(__('Transcription session expired', 'll-tools-text-domain'), 409);
        }
        if (!hash_equals($stored_transcript_id, $posted_transcript_id)) {
            wp_send_json_error(__('Transcription session mismatch', 'll-tools-text-domain'), 409);
        }
    }

    if ($posted_transcript_id === '') {
        $existing_text = trim((string) get_post_meta($recording_id, 'recording_text', true));
        if (!$force && $existing_text !== '') {
            delete_post_meta($recording_id, $transcript_meta_key);
            wp_send_json_success([
                'skipped' => true,
            ]);
        }
    }

    $transcript_id = '';
    if ($posted_transcript_id !== '') {
        $transcript_id = $posted_transcript_id;
    } elseif ($stored_transcript_id !== '') {
        $transcript_id = $stored_transcript_id;
    } else {
        $audio_path = (string) get_post_meta($recording_id, 'audio_file_path', true);
        $file_info = ll_tools_resolve_audio_file_for_transcription($audio_path);
        $file_path = (string) ($file_info['path'] ?? '');
        $is_temp = !empty($file_info['is_temp']);
        if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
            if ($is_temp && $file_path !== '' && file_exists($file_path)) {
                @unlink($file_path);
            }
            wp_send_json_error('Audio file missing', 400);
        }

        $transcript_id = ll_tools_assemblyai_start_transcription($file_path, $language_code);
        if ($is_temp && $file_path !== '' && file_exists($file_path)) {
            @unlink($file_path);
        }
        if (is_wp_error($transcript_id)) {
            wp_send_json_error($transcript_id->get_error_message(), 500);
        }
        $transcript_id = (string) $transcript_id;
    }

    $status = ll_tools_assemblyai_get_transcript($transcript_id);
    if (is_wp_error($status)) {
        wp_send_json_error($status->get_error_message(), 500);
    }

    $state = isset($status['status']) ? (string) $status['status'] : '';
    if ($state === 'completed') {
        delete_post_meta($recording_id, $transcript_meta_key);
        $response = ll_tools_word_grid_finalize_transcription(
            $recording_id,
            $recording,
            $wordset_ids,
            $force,
            (string) ($status['text'] ?? '')
        );
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message(), 500);
        }
        $response['pending'] = false;
        $response['transcript_id'] = $transcript_id;
        $response['status'] = 'completed';
        wp_send_json_success($response);
    }

    if ($state === 'error') {
        delete_post_meta($recording_id, $transcript_meta_key);
        $message = isset($status['error']) ? (string) $status['error'] : __('AssemblyAI transcription failed.', 'll-tools-text-domain');
        wp_send_json_error($message, 500);
    }

    update_post_meta($recording_id, $transcript_meta_key, $transcript_id);
    wp_send_json_success([
        'pending' => true,
        'transcript_id' => $transcript_id,
        'status' => ($state !== '' ? $state : 'processing'),
        'language_code' => $language_code,
    ]);
}

// Register the 'word_grid' shortcode
function ll_tools_register_word_grid_shortcode() {
    add_shortcode('word_grid', 'll_tools_word_grid_shortcode');
}
add_action('init', 'll_tools_register_word_grid_shortcode');
