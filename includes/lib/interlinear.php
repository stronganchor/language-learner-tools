<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_INTERLINEAR_PAYLOAD_META')) {
    define('LL_TOOLS_INTERLINEAR_PAYLOAD_META', '_ll_tools_interlinear_payload');
}
if (!defined('LL_TOOLS_INTERLINEAR_SOURCE_META')) {
    define('LL_TOOLS_INTERLINEAR_SOURCE_META', '_ll_tools_interlinear_source');
}
if (!defined('LL_TOOLS_INTERLINEAR_LESSON_ID_META')) {
    define('LL_TOOLS_INTERLINEAR_LESSON_ID_META', '_ll_tools_interlinear_lesson_id');
}
if (!defined('LL_TOOLS_INTERLINEAR_UPDATED_AT_META')) {
    define('LL_TOOLS_INTERLINEAR_UPDATED_AT_META', '_ll_tools_interlinear_updated_at');
}

function ll_tools_interlinear_supported_post_types(): array {
    return ['ll_content_lesson', 'll_vocab_lesson'];
}

function ll_tools_interlinear_content_wordset_meta_key(): string {
    return defined('LL_TOOLS_CONTENT_LESSON_WORDSET_META')
        ? LL_TOOLS_CONTENT_LESSON_WORDSET_META
        : '_ll_tools_content_lesson_wordset_id';
}

function ll_tools_interlinear_vocab_wordset_meta_key(): string {
    return defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
        ? LL_TOOLS_VOCAB_LESSON_WORDSET_META
        : '_ll_tools_vocab_wordset_id';
}

function ll_tools_interlinear_post_type_supported($post): bool {
    if (is_numeric($post)) {
        $post = get_post((int) $post);
    }

    return $post instanceof WP_Post && in_array((string) $post->post_type, ll_tools_interlinear_supported_post_types(), true);
}

function ll_tools_interlinear_get_wordset_id_for_lesson(int $lesson_id): int {
    $post = get_post($lesson_id);
    if (!ll_tools_interlinear_post_type_supported($post)) {
        return 0;
    }

    if ($post->post_type === 'll_content_lesson' && function_exists('ll_tools_get_content_lesson_wordset_id')) {
        return max(0, (int) ll_tools_get_content_lesson_wordset_id($lesson_id));
    }

    $meta_key = $post->post_type === 'll_content_lesson'
        ? ll_tools_interlinear_content_wordset_meta_key()
        : ll_tools_interlinear_vocab_wordset_meta_key();

    return max(0, (int) get_post_meta($lesson_id, $meta_key, true));
}

function ll_tools_current_user_can_view_interlinear(int $lesson_id): bool {
    if ($lesson_id <= 0 || !is_user_logged_in()) {
        return false;
    }
    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return false;
    }

    $wordset_id = ll_tools_interlinear_get_wordset_id_for_lesson($lesson_id);
    if ($wordset_id <= 0) {
        return current_user_can('manage_options');
    }

    return !function_exists('ll_tools_user_can_view_wordset')
        || ll_tools_user_can_view_wordset($wordset_id, (int) get_current_user_id());
}

function ll_tools_current_user_can_manage_interlinear(int $lesson_id): bool {
    if ($lesson_id <= 0 || !is_user_logged_in()) {
        return false;
    }
    if (current_user_can('manage_options')) {
        return true;
    }

    $wordset_id = ll_tools_interlinear_get_wordset_id_for_lesson($lesson_id);
    return $wordset_id > 0
        && function_exists('ll_tools_user_can_manage_wordset_content')
        && ll_tools_user_can_manage_wordset_content($wordset_id, (int) get_current_user_id());
}

function ll_tools_interlinear_clean_payload_value($value, int $depth = 0) {
    if ($depth > 20) {
        return null;
    }

    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $child_value) {
            $clean_key = is_int($key)
                ? $key
                : preg_replace('/[\x00-\x1F\x7F]/u', '', wp_check_invalid_utf8((string) $key));
            if (!is_int($clean_key)) {
                $clean_key = trim((string) $clean_key);
                if ($clean_key === '') {
                    continue;
                }
            }
            $clean[$clean_key] = ll_tools_interlinear_clean_payload_value($child_value, $depth + 1);
        }
        return $clean;
    }

    if (is_string($value)) {
        return str_replace("\0", '', wp_check_invalid_utf8($value));
    }

    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
        return $value;
    }

    if (is_scalar($value)) {
        return str_replace("\0", '', wp_check_invalid_utf8((string) $value));
    }

    return null;
}

function ll_tools_interlinear_normalize_payload($payload) {
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return new WP_Error(
                'll_tools_interlinear_invalid_json',
                __('Interlinear payload must be a valid JSON object.', 'll-tools-text-domain')
            );
        }
        $payload = $decoded;
    }

    if (!is_array($payload)) {
        return new WP_Error(
            'll_tools_interlinear_invalid_payload',
            __('Interlinear payload must be an object.', 'll-tools-text-domain')
        );
    }

    $payload = ll_tools_interlinear_clean_payload_value($payload);
    if (!is_array($payload)) {
        return new WP_Error(
            'll_tools_interlinear_invalid_payload',
            __('Interlinear payload must be an object.', 'll-tools-text-domain')
        );
    }

    if (!isset($payload['lines']) || !is_array($payload['lines'])) {
        $payload['lines'] = [];
    }
    $payload['lines'] = array_values(array_filter($payload['lines'], 'is_array'));

    if (!isset($payload['schema']) || !is_scalar($payload['schema'])) {
        $payload['schema'] = 'll_tools_interlinear.v1';
    }

    return $payload;
}

function ll_tools_interlinear_get_payload(int $lesson_id): array {
    if ($lesson_id <= 0) {
        return [];
    }

    $payload = get_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_PAYLOAD_META, true);
    return is_array($payload) ? $payload : [];
}

function ll_tools_interlinear_has_payload(int $lesson_id): bool {
    $payload = ll_tools_interlinear_get_payload($lesson_id);
    return !empty($payload) && !empty($payload['lines']) && is_array($payload['lines']);
}

function ll_tools_interlinear_set_payload(int $lesson_id, $payload, string $source = '') {
    if (!ll_tools_interlinear_post_type_supported($lesson_id)) {
        return new WP_Error(
            'll_tools_interlinear_invalid_lesson',
            __('Interlinear payloads can only be attached to LL Tools lesson posts.', 'll-tools-text-domain')
        );
    }

    $payload = ll_tools_interlinear_normalize_payload($payload);
    if (is_wp_error($payload)) {
        return $payload;
    }

    $source = trim(sanitize_text_field($source));
    $updated_at = gmdate('c');
    update_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_PAYLOAD_META, $payload);
    update_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_UPDATED_AT_META, $updated_at);
    if ($source !== '') {
        update_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_SOURCE_META, $source);
    } else {
        delete_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_SOURCE_META);
    }

    $lesson_ref = isset($payload['lesson_id']) && is_scalar($payload['lesson_id'])
        ? trim((string) $payload['lesson_id'])
        : '';
    if ($lesson_ref !== '') {
        update_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_LESSON_ID_META, sanitize_text_field($lesson_ref));
    } else {
        delete_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_LESSON_ID_META);
    }

    return [
        'payload' => $payload,
        'updated_at' => $updated_at,
    ];
}

function ll_tools_interlinear_clear_payload(int $lesson_id): void {
    delete_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_PAYLOAD_META);
    delete_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_SOURCE_META);
    delete_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_LESSON_ID_META);
    delete_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_UPDATED_AT_META);
}

function ll_tools_interlinear_summary(array $payload): array {
    $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : [];
    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];

    return [
        'lines' => max(0, (int) ($summary['lines'] ?? count($lines))),
        'tokens' => max(0, (int) ($summary['tokens'] ?? array_sum(array_map(static function ($line): int {
            return is_array($line) && isset($line['tokens']) && is_array($line['tokens']) ? count($line['tokens']) : 0;
        }, $lines)))),
        'matched_tokens' => max(0, (int) ($summary['matched_tokens'] ?? 0)),
        'matched_pct' => isset($summary['matched_pct']) && is_scalar($summary['matched_pct']) ? (string) $summary['matched_pct'] : '',
        'high_confidence_tokens' => max(0, (int) ($summary['high_confidence_tokens'] ?? 0)),
        'high_confidence_pct' => isset($summary['high_confidence_pct']) && is_scalar($summary['high_confidence_pct']) ? (string) $summary['high_confidence_pct'] : '',
        'mean_confidence' => isset($summary['mean_confidence']) && is_numeric($summary['mean_confidence']) ? (float) $summary['mean_confidence'] : null,
    ];
}

function ll_tools_interlinear_payload_for_rest(int $lesson_id, bool $include_payload = true): array {
    $post = get_post($lesson_id);
    if (!ll_tools_interlinear_post_type_supported($post)) {
        return [];
    }

    $wordset_id = ll_tools_interlinear_get_wordset_id_for_lesson($lesson_id);
    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    $payload = ll_tools_interlinear_get_payload($lesson_id);
    $row = [
        'post_id' => $lesson_id,
        'post_type' => (string) $post->post_type,
        'post_slug' => (string) $post->post_name,
        'post_title' => (string) get_the_title($post),
        'permalink' => (string) get_permalink($post),
        'wordset' => [
            'id' => $wordset_id,
            'slug' => ($wordset instanceof WP_Term && !is_wp_error($wordset)) ? (string) $wordset->slug : '',
            'name' => ($wordset instanceof WP_Term && !is_wp_error($wordset)) ? (string) $wordset->name : '',
        ],
        'interlinear_lesson_id' => (string) get_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_LESSON_ID_META, true),
        'source' => (string) get_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_SOURCE_META, true),
        'updated_at' => (string) get_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_UPDATED_AT_META, true),
        'has_payload' => !empty($payload),
        'summary' => ll_tools_interlinear_summary($payload),
    ];

    if ($include_payload) {
        $row['payload'] = $payload;
    }

    return $row;
}

function ll_tools_interlinear_scalar(array $row, array $keys): string {
    foreach ($keys as $key) {
        if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
            return trim((string) $row[$key]);
        }
    }
    return '';
}

function ll_tools_interlinear_token_morpheme_line(array $token, string $line): string {
    $morphemes = isset($token['corrected_morphemes']) && is_array($token['corrected_morphemes'])
        ? $token['corrected_morphemes']
        : (isset($token['morphemes']) && is_array($token['morphemes']) ? $token['morphemes'] : []);
    if (empty($morphemes)) {
        if ($line === 'morph') {
            return ll_tools_interlinear_scalar($token, ['corrected_form', 'form']);
        }
        if ($line === 'gloss') {
            return ll_tools_interlinear_scalar($token, ['corrected_gloss_en', 'display_gloss', 'gloss_en', 'gloss']);
        }
        return '';
    }

    $parts = [];
    foreach ($morphemes as $morpheme) {
        if (!is_array($morpheme)) {
            continue;
        }
        $part = ($line === 'morph')
            ? ll_tools_interlinear_scalar($morpheme, ['corrected_form', 'form', 'morph'])
            : ll_tools_interlinear_scalar($morpheme, ['corrected_gloss_en', 'display_gloss', 'gloss_en', 'gloss']);
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return implode('-', $parts);
}

function ll_tools_interlinear_format_confidence($value): string {
    if (!is_numeric($value)) {
        return '';
    }

    $confidence = (float) $value;
    if ($confidence <= 1.0) {
        return (string) round($confidence * 100) . '%';
    }

    return (string) round($confidence, 2);
}

function ll_tools_interlinear_display_gloss(array $token): string {
    return ll_tools_interlinear_scalar($token, ['corrected_gloss_en', 'display_gloss', 'gloss_en', 'gloss']);
}

function ll_tools_interlinear_display_lemma(array $token): string {
    return ll_tools_interlinear_scalar($token, ['corrected_lemma', 'lemma']);
}

function ll_tools_interlinear_display_pos(array $token): string {
    return ll_tools_interlinear_scalar($token, ['corrected_pos', 'pos']);
}

function ll_tools_interlinear_display_form(array $token): string {
    return ll_tools_interlinear_scalar($token, ['corrected_form', 'form']);
}

function ll_tools_interlinear_word_display_form(array $token): string {
    return trim(
        ll_tools_interlinear_scalar($token, ['prefix_punct'])
        . ll_tools_interlinear_display_form($token)
        . ll_tools_interlinear_scalar($token, ['suffix_punct'])
    );
}

function ll_tools_interlinear_token_morphemes(array $token): array {
    $raw_morphemes = [];
    if (isset($token['corrected_morphemes']) && is_array($token['corrected_morphemes']) && !empty($token['corrected_morphemes'])) {
        $raw_morphemes = $token['corrected_morphemes'];
    } elseif (isset($token['morphemes']) && is_array($token['morphemes'])) {
        $raw_morphemes = $token['morphemes'];
    }

    $morphemes = [];
    foreach ($raw_morphemes as $morpheme) {
        if (!is_array($morpheme)) {
            continue;
        }
        $form = ll_tools_interlinear_scalar($morpheme, ['corrected_form', 'form', 'morph']);
        $gloss = ll_tools_interlinear_scalar($morpheme, ['corrected_gloss_en', 'display_gloss', 'gloss_en', 'gloss']);
        $pos = ll_tools_interlinear_scalar($morpheme, ['corrected_pos', 'pos']);
        if ($form !== '' || $gloss !== '' || $pos !== '') {
            $morphemes[] = [
                'form' => $form,
                'gloss' => $gloss,
                'pos' => $pos,
            ];
        }
    }

    if (!empty($morphemes)) {
        return $morphemes;
    }

    return [[
        'form' => ll_tools_interlinear_display_form($token),
        'gloss' => ll_tools_interlinear_display_gloss($token),
        'pos' => ll_tools_interlinear_display_pos($token),
    ]];
}

function ll_tools_interlinear_token_column_count(array $token): int {
    return max(1, count(ll_tools_interlinear_token_morphemes($token)));
}

function ll_tools_interlinear_token_column_range_count(array $tokens, int $start_token, int $end_token): int {
    $count = 0;
    for ($token_index = $start_token; $token_index <= $end_token && $token_index <= count($tokens); $token_index++) {
        $token = $tokens[$token_index - 1] ?? [];
        if (is_array($token)) {
            $count += ll_tools_interlinear_token_column_count($token);
        }
    }

    return max(1, $count);
}

function ll_tools_interlinear_certainty_class(array $token, string $value = ''): string {
    $text = trim($value);
    $review_correction = isset($token['review_correction']) && is_array($token['review_correction']) ? $token['review_correction'] : [];
    $approved = (string) ($review_correction['status'] ?? '') === 'approved';
    $confidence = is_numeric($token['corrected_confidence'] ?? null)
        ? (float) $token['corrected_confidence']
        : (is_numeric($token['confidence'] ?? null) ? (float) $token['confidence'] : 0.0);
    $match_type = isset($token['match_type']) && is_scalar($token['match_type']) ? (string) $token['match_type'] : '';
    $unknown = $text === ''
        || $text === '?'
        || ll_tools_interlinear_display_gloss($token) === '?'
        || ll_tools_interlinear_display_lemma($token) === '?';

    if ($unknown || $match_type === 'unknown') {
        return ' unknown-cell';
    }
    if ($approved) {
        return '';
    }
    if ($confidence > 0 && $confidence < 0.84) {
        return ' low-certainty';
    }
    if (in_array($match_type, ['dictionary_folded', 'orthography_variant', 'prefix_morphology', 'suffix_morphology'], true)) {
        return ' low-certainty';
    }

    return '';
}

function ll_tools_interlinear_abbreviation_glossary(): array {
    return [
        '1SG' => __('first-person singular', 'll-tools-text-domain'),
        '1PL' => __('first-person plural', 'll-tools-text-domain'),
        '2SG' => __('second-person singular', 'll-tools-text-domain'),
        '2PL' => __('second-person plural', 'll-tools-text-domain'),
        '3SG' => __('third-person singular', 'll-tools-text-domain'),
        '3PL' => __('third-person plural', 'll-tools-text-domain'),
        'F' => __('feminine', 'll-tools-text-domain'),
        'M' => __('masculine', 'll-tools-text-domain'),
        'F.SG' => __('feminine singular', 'll-tools-text-domain'),
        'M.SG' => __('masculine singular', 'll-tools-text-domain'),
        'PL' => __('plural', 'll-tools-text-domain'),
        'PL.OBL' => __('plural oblique', 'll-tools-text-domain'),
        'DIR' => __('direct case', 'll-tools-text-domain'),
        'OBL' => __('oblique case', 'll-tools-text-domain'),
        'POSS' => __('possessive', 'll-tools-text-domain'),
        'REFL' => __('reflexive', 'll-tools-text-domain'),
        'DAT' => __('dative', 'll-tools-text-domain'),
        'LOC' => __('locative', 'll-tools-text-domain'),
        'NEG' => __('negative', 'll-tools-text-domain'),
        'IPFV' => __('imperfective aspect', 'll-tools-text-domain'),
        'SBJV' => __('subjunctive mood', 'll-tools-text-domain'),
        'OPT' => __('optative mood', 'll-tools-text-domain'),
        'COP' => __('copula', 'll-tools-text-domain'),
        'DEM' => __('demonstrative', 'll-tools-text-domain'),
        'PROX' => __('proximal', 'll-tools-text-domain'),
        'PROG' => __('progressive', 'll-tools-text-domain'),
        'EZ' => __('ezafe', 'll-tools-text-domain'),
        'AGR' => __('agreement', 'll-tools-text-domain'),
        'ASP' => __('aspect', 'll-tools-text-domain'),
        'MOOD' => __('mood', 'll-tools-text-domain'),
        'POL' => __('polarity', 'll-tools-text-domain'),
        'CASE' => __('case', 'll-tools-text-domain'),
        'CL' => __('clitic', 'll-tools-text-domain'),
        'NUM' => __('number', 'll-tools-text-domain'),
        'PHON' => __('phonological process', 'll-tools-text-domain'),
        'BUF' => __('buffer consonant', 'll-tools-text-domain'),
        'DER' => __('derivational morphology', 'll-tools-text-domain'),
        'N' => __('noun', 'll-tools-text-domain'),
        'V' => __('verb', 'll-tools-text-domain'),
        'PRON' => __('pronoun', 'll-tools-text-domain'),
        'DET' => __('determiner', 'll-tools-text-domain'),
        'POST' => __('postposition', 'll-tools-text-domain'),
        'PART' => __('particle', 'll-tools-text-domain'),
        'ADV' => __('adverb', 'll-tools-text-domain'),
        'ADJ' => __('adjective', 'll-tools-text-domain'),
        'INTJ' => __('interjection', 'll-tools-text-domain'),
        'CONJ' => __('conjunction', 'll-tools-text-domain'),
        'PHRASE' => __('phrase', 'll-tools-text-domain'),
        'LEX' => __('lexical item', 'll-tools-text-domain'),
    ];
}

function ll_tools_interlinear_abbreviation_boundary(string $value): bool {
    return $value === '' || preg_match('/[^A-Za-z0-9]/', $value) === 1;
}

function ll_tools_interlinear_render_with_abbreviation_tooltips(string $value): string {
    static $pattern = null;
    $glossary = ll_tools_interlinear_abbreviation_glossary();
    if ($pattern === null) {
        $keys = array_keys($glossary);
        usort($keys, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });
        $pattern = '/(?<![A-Za-z0-9])(' . implode('|', array_map('preg_quote', $keys)) . ')(?![A-Za-z0-9])/u';
    }

    $html = '';
    $offset = 0;
    if (preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $label = (string) ($match[1][0] ?? '');
            $position = (int) ($match[1][1] ?? 0);
            if ($position > $offset) {
                $html .= esc_html(substr($value, $offset, $position - $offset));
            }
            $tooltip = sprintf('%1$s: %2$s', $label, (string) ($glossary[$label] ?? ''));
            $html .= '<span class="ling-abbr" tabindex="0" title="' . esc_attr($tooltip) . '" aria-label="' . esc_attr($tooltip) . '">' . esc_html($label) . '</span>';
            $offset = $position + strlen($label);
        }
    }
    if ($offset < strlen($value)) {
        $html .= esc_html(substr($value, $offset));
    }

    return $html !== '' ? $html : esc_html('?');
}

function ll_tools_interlinear_render_text_cell(string $value, string $label): string {
    $value = $value !== '' ? $value : '?';
    if (in_array($label, ['GLOSS', 'POS', 'PHRASE'], true)) {
        return ll_tools_interlinear_render_with_abbreviation_tooltips($value);
    }

    return esc_html($value);
}

function ll_tools_interlinear_render_spanning_row(string $label, array $line, array $tokens, callable $value_fn): string {
    $line_id = ll_tools_interlinear_scalar($line, ['id', 'line_id']);
    $cells = '';
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        $span = ll_tools_interlinear_token_column_count($token);
        $value = (string) call_user_func($value_fn, $token);
        $classes = 'token-span token-start token-end' . ll_tools_interlinear_certainty_class($token, $value);
        $cells .= '<td class="' . esc_attr($classes) . '" colspan="' . esc_attr((string) $span) . '" data-line-id="' . esc_attr($line_id) . '" data-token-index="' . esc_attr((string) ($index + 1)) . '" data-row-label="' . esc_attr($label) . '">';
        $cells .= ll_tools_interlinear_render_text_cell($value, $label);
        $cells .= '</td>';
    }

    return '<tr><th scope="row">' . esc_html($label) . '</th>' . $cells . '</tr>';
}

function ll_tools_interlinear_render_morpheme_row(string $label, array $line, array $tokens, string $field): string {
    $line_id = ll_tools_interlinear_scalar($line, ['id', 'line_id']);
    $cells = '';
    foreach ($tokens as $token_index => $token) {
        if (!is_array($token)) {
            continue;
        }
        $morphemes = ll_tools_interlinear_token_morphemes($token);
        foreach ($morphemes as $morph_index => $morpheme) {
            $value = $field === 'gloss' ? (string) ($morpheme['gloss'] ?? '') : (string) ($morpheme['form'] ?? '');
            $classes = 'morph-cell';
            if ($field === 'gloss') {
                $classes .= ' gloss-cell';
            }
            if ($morph_index === 0) {
                $classes .= ' token-start';
            }
            if ($morph_index === count($morphemes) - 1) {
                $classes .= ' token-end';
            }
            $classes .= ll_tools_interlinear_certainty_class($token, $value);
            $cells .= '<td class="' . esc_attr($classes) . '" data-line-id="' . esc_attr($line_id) . '" data-token-index="' . esc_attr((string) ($token_index + 1)) . '" data-row-label="' . esc_attr($label) . '" data-morph-index="' . esc_attr((string) $morph_index) . '">';
            $cells .= '<span class="morph-text">' . ll_tools_interlinear_render_text_cell($value, $label) . '</span>';
            $cells .= '</td>';
        }
    }

    return '<tr><th scope="row">' . esc_html($label) . '</th>' . $cells . '</tr>';
}

function ll_tools_interlinear_phrase_label(array $phrase): string {
    $lemma = ll_tools_interlinear_scalar($phrase, ['lemma']);
    $gloss = ll_tools_interlinear_scalar($phrase, ['display_gloss', 'gloss_en', 'gloss']);
    if ($lemma !== '' && $lemma !== '?') {
        return $lemma . ' = ' . ($gloss !== '' ? $gloss : '?');
    }

    return $gloss !== '' ? $gloss : '?';
}

function ll_tools_interlinear_render_empty_phrase_cells(array $tokens, int $start_token, int $end_token): string {
    $cells = '';
    for ($token_index = $start_token; $token_index <= $end_token && $token_index <= count($tokens); $token_index++) {
        $token = $tokens[$token_index - 1] ?? [];
        $span = is_array($token) ? ll_tools_interlinear_token_column_count($token) : 1;
        $cells .= '<td class="phrase-empty" colspan="' . esc_attr((string) $span) . '"></td>';
    }

    return $cells;
}

function ll_tools_interlinear_render_phrase_row(array $line, array $tokens): string {
    $phrases = isset($line['phrase_matches']) && is_array($line['phrase_matches']) ? $line['phrase_matches'] : [];
    if (empty($phrases)) {
        return '';
    }

    $normalized = [];
    foreach ($phrases as $phrase) {
        if (!is_array($phrase)) {
            continue;
        }
        $start = isset($phrase['start_token']) ? (int) $phrase['start_token'] : 0;
        $end = isset($phrase['end_token']) ? (int) $phrase['end_token'] : 0;
        if ($start < 1 || $end < $start || $start > count($tokens)) {
            continue;
        }
        $phrase['start_token'] = $start;
        $phrase['end_token'] = $end;
        $normalized[] = $phrase;
    }
    if (empty($normalized)) {
        return '';
    }

    usort($normalized, static function (array $left, array $right): int {
        return ((int) $left['start_token'] <=> (int) $right['start_token'])
            ?: ((int) $left['end_token'] <=> (int) $right['end_token']);
    });

    $line_id = ll_tools_interlinear_scalar($line, ['id', 'line_id']);
    $cells = '';
    $cursor = 1;
    foreach ($normalized as $phrase) {
        $start = (int) $phrase['start_token'];
        if ($start < $cursor) {
            continue;
        }
        $cells .= ll_tools_interlinear_render_empty_phrase_cells($tokens, $cursor, $start - 1);
        $end = min((int) $phrase['end_token'], count($tokens));
        $span = ll_tools_interlinear_token_column_range_count($tokens, $start, $end);
        $label = ll_tools_interlinear_phrase_label($phrase);
        $forms = isset($phrase['forms']) && is_array($phrase['forms']) ? implode(' ', array_map('strval', $phrase['forms'])) : '';
        $title = trim($forms . ': ' . $label, " \t\n\r\0\x0B:");
        $cells .= '<td class="phrase-cell token-start token-end" colspan="' . esc_attr((string) $span) . '" title="' . esc_attr($title) . '" data-line-id="' . esc_attr($line_id) . '" data-token-index="' . esc_attr((string) $start) . '" data-row-label="PHRASE">';
        $cells .= ll_tools_interlinear_render_text_cell($label, 'PHRASE');
        $cells .= '</td>';
        $cursor = $end + 1;
    }
    $cells .= ll_tools_interlinear_render_empty_phrase_cells($tokens, $cursor, count($tokens));

    return '<tr class="phrase-row"><th scope="row">' . esc_html__('PHRASE', 'll-tools-text-domain') . '</th>' . $cells . '</tr>';
}

function ll_tools_render_interlinear_line(array $line, bool $show_line_text = true): string {
    $line_text = ll_tools_interlinear_scalar($line, ['text', 'text_projected', 'sentence']);
    $display_line_text = $show_line_text ? $line_text : '';
    $tokens = isset($line['tokens']) && is_array($line['tokens']) ? array_values(array_filter($line['tokens'], 'is_array')) : [];
    if ($display_line_text === '' && empty($tokens)) {
        return '';
    }

    ob_start();
    ?>
    <article class="ll-interlinear-line">
        <?php if ($display_line_text !== '') : ?>
            <div class="ll-interlinear-line__text" dir="auto"><?php echo esc_html($display_line_text); ?></div>
        <?php endif; ?>
        <?php if (!empty($tokens)) : ?>
            <div class="ll-interlinear-grid">
                <table class="ll-interlinear-table">
                    <tbody>
                        <?php echo ll_tools_interlinear_render_spanning_row('WORD', $line, $tokens, 'll_tools_interlinear_word_display_form'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo ll_tools_interlinear_render_morpheme_row('MORPH', $line, $tokens, 'form'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo ll_tools_interlinear_render_spanning_row('LEMMA', $line, $tokens, 'll_tools_interlinear_display_lemma'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo ll_tools_interlinear_render_morpheme_row('GLOSS', $line, $tokens, 'gloss'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo ll_tools_interlinear_render_spanning_row('POS', $line, $tokens, 'll_tools_interlinear_display_pos'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php echo ll_tools_interlinear_render_phrase_row($line, $tokens); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function ll_tools_render_interlinear_lines(array $lines, bool $show_line_text = true): string {
    $html = '';
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $html .= ll_tools_render_interlinear_line($line, $show_line_text);
    }

    if ($html === '') {
        return '';
    }

    return '<div class="ll-interlinear__lines">' . $html . '</div>';
}

function ll_tools_interlinear_match_key(string $value): string {
    $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', (string) $value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }

    return strtolower(trim((string) $value));
}

function ll_tools_interlinear_line_matches_word(array $line, int $word_id, array $candidate_texts, array $candidate_recording_ids): bool {
    $line_id = ll_tools_interlinear_scalar($line, ['id', 'line_id']);
    if ($line_id !== '' && in_array($line_id, $candidate_recording_ids, true)) {
        return true;
    }
    if ($line_id !== '' && (string) $word_id === $line_id) {
        return true;
    }

    $line_texts = [
        ll_tools_interlinear_scalar($line, ['text']),
        ll_tools_interlinear_scalar($line, ['text_projected']),
        ll_tools_interlinear_scalar($line, ['sentence']),
    ];
    foreach ($line_texts as $line_text) {
        $key = ll_tools_interlinear_match_key($line_text);
        if ($key !== '' && isset($candidate_texts[$key])) {
            return true;
        }
    }

    $tokens = isset($line['tokens']) && is_array($line['tokens']) ? array_values(array_filter($line['tokens'], 'is_array')) : [];
    if (count($tokens) === 1) {
        $token_key = ll_tools_interlinear_match_key(ll_tools_interlinear_word_display_form($tokens[0]));
        if ($token_key !== '' && isset($candidate_texts[$token_key])) {
            return true;
        }
    }

    return false;
}

function ll_tools_interlinear_lines_for_word(int $lesson_id, int $word_id, string $word_text, array $recording_rows = []): array {
    if ($lesson_id <= 0 || $word_id <= 0 || !ll_tools_interlinear_has_payload($lesson_id)) {
        return [];
    }

    $payload = ll_tools_interlinear_get_payload($lesson_id);
    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    if (empty($lines)) {
        return [];
    }

    $candidate_texts = [];
    $word_key = ll_tools_interlinear_match_key($word_text);
    if ($word_key !== '') {
        $candidate_texts[$word_key] = true;
    }

    $candidate_recording_ids = [];
    foreach ($recording_rows as $recording_row) {
        if (!is_array($recording_row)) {
            continue;
        }
        if (!empty($recording_row['id'])) {
            $candidate_recording_ids[] = (string) ((int) $recording_row['id']);
        }
        foreach (['text', 'ipa'] as $field) {
            $key = isset($recording_row[$field]) && is_scalar($recording_row[$field])
                ? ll_tools_interlinear_match_key((string) $recording_row[$field])
                : '';
            if ($key !== '') {
                $candidate_texts[$key] = true;
            }
        }
    }
    $candidate_recording_ids = array_values(array_unique($candidate_recording_ids));

    $matches = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (ll_tools_interlinear_line_matches_word($line, $word_id, $candidate_texts, $candidate_recording_ids)) {
            $matches[] = $line;
        }
    }

    return $matches;
}

function ll_tools_interlinear_lines_for_recording(int $lesson_id, array $recording_row, string $word_text = ''): array {
    if ($lesson_id <= 0 || !ll_tools_interlinear_has_payload($lesson_id)) {
        return [];
    }

    $payload = ll_tools_interlinear_get_payload($lesson_id);
    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    if (empty($lines)) {
        return [];
    }

    $candidate_texts = [];
    foreach (['text', 'ipa'] as $field) {
        $key = isset($recording_row[$field]) && is_scalar($recording_row[$field])
            ? ll_tools_interlinear_match_key((string) $recording_row[$field])
            : '';
        if ($key !== '') {
            $candidate_texts[$key] = true;
        }
    }
    if (empty($candidate_texts)) {
        $word_key = ll_tools_interlinear_match_key($word_text);
        if ($word_key !== '') {
            $candidate_texts[$word_key] = true;
        }
    }

    $candidate_recording_ids = [];
    if (!empty($recording_row['id'])) {
        $candidate_recording_ids[] = (string) ((int) $recording_row['id']);
    }

    $matches = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (ll_tools_interlinear_line_matches_word($line, 0, $candidate_texts, $candidate_recording_ids)) {
            $matches[] = $line;
        }
    }

    return $matches;
}

function ll_tools_render_word_interlinear_block(int $lesson_id, int $word_id, string $word_text, array $recording_rows = []): string {
    if (!ll_tools_current_user_can_view_interlinear($lesson_id) || !ll_tools_interlinear_has_payload($lesson_id)) {
        return '';
    }

    $lines = ll_tools_interlinear_lines_for_word($lesson_id, $word_id, $word_text, $recording_rows);
    $lines_html = ll_tools_render_interlinear_lines($lines);
    if ($lines_html === '') {
        return '';
    }

    return '<div class="ll-word-interlinear" data-ll-word-interlinear aria-label="' . esc_attr__('Interlinear analysis', 'll-tools-text-domain') . '">' . $lines_html . '</div>';
}

function ll_tools_render_recording_interlinear_block(int $lesson_id, array $recording_row, string $word_text = ''): string {
    if (!ll_tools_current_user_can_view_interlinear($lesson_id) || !ll_tools_interlinear_has_payload($lesson_id)) {
        return '';
    }

    $lines = ll_tools_interlinear_lines_for_recording($lesson_id, $recording_row, $word_text);
    $lines_html = ll_tools_render_interlinear_lines($lines, false);
    if ($lines_html === '') {
        return '';
    }

    $recording_id_attr = !empty($recording_row['id'])
        ? ' data-recording-id="' . esc_attr((int) $recording_row['id']) . '"'
        : '';

    return '<div class="ll-word-interlinear ll-word-recording-interlinear" data-ll-recording-interlinear' . $recording_id_attr . ' aria-label="' . esc_attr__('Interlinear analysis', 'll-tools-text-domain') . '">' . $lines_html . '</div>';
}

function ll_tools_render_interlinear_block(int $lesson_id): string {
    if (!ll_tools_current_user_can_view_interlinear($lesson_id) || !ll_tools_interlinear_has_payload($lesson_id)) {
        return '';
    }

    $post = get_post($lesson_id);
    $is_vocab_lesson = $post instanceof WP_Post && $post->post_type === 'll_vocab_lesson';
    $payload = ll_tools_interlinear_get_payload($lesson_id);
    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    $lines_html = ll_tools_render_interlinear_lines($lines);
    if ($lines_html === '') {
        return '';
    }
    $panel_id = 'll-interlinear-panel-' . $lesson_id;
    $classes = 'll-interlinear';
    if ($is_vocab_lesson) {
        $classes .= ' ll-interlinear--word-grid-toggle';
    }
    $summary_attrs = $is_vocab_lesson ? '' : ' aria-controls="' . esc_attr($panel_id) . '"';

    ob_start();
    ?>
    <details class="<?php echo esc_attr($classes); ?>" data-ll-interlinear>
        <summary class="ll-interlinear__summary"<?php echo $summary_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <span class="ll-interlinear__summary-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                    <path d="M5.2 4.4H15M5.2 10H12M5.2 15.6H15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    <path d="M3.6 7.2 1.8 10l1.8 2.8M16.4 7.2l1.8 2.8-1.8 2.8" fill="none" stroke="currentColor" stroke-width="1.45" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="ll-interlinear__summary-label ll-interlinear__summary-label--show"><?php echo esc_html__('Show interlinear', 'll-tools-text-domain'); ?></span>
            <span class="ll-interlinear__summary-label ll-interlinear__summary-label--hide"><?php echo esc_html__('Hide interlinear', 'll-tools-text-domain'); ?></span>
            <span class="ll-interlinear__staff-label"><?php echo esc_html__('Staff', 'll-tools-text-domain'); ?></span>
        </summary>
        <?php if (!$is_vocab_lesson) : ?>
            <div class="ll-interlinear__panel" id="<?php echo esc_attr($panel_id); ?>">
                <?php echo $lines_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        <?php endif; ?>
    </details>
    <?php

    return trim((string) ob_get_clean());
}
