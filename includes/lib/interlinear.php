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

function ll_tools_interlinear_content_lesson_is_corpus_text(int $lesson_id): bool {
    if ($lesson_id <= 0) {
        return false;
    }

    if (function_exists('ll_tools_content_lesson_is_corpus_text')) {
        return ll_tools_content_lesson_is_corpus_text($lesson_id);
    }

    return defined('LL_TOOLS_CONTENT_LESSON_KIND_META')
        && get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, true) === 'corpus_text';
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

    $schema = isset($payload['schema']) && is_scalar($payload['schema']) ? (string) $payload['schema'] : '';
    $has_text_document_keys = false;
    foreach (['source_lines', 'reading_units', 'witnesses', 'translations'] as $text_key) {
        if (array_key_exists($text_key, $payload)) {
            $has_text_document_keys = true;
            break;
        }
    }
    if (isset($payload['kind']) && is_scalar($payload['kind'])) {
        $payload['kind'] = sanitize_key((string) $payload['kind']);
    }
    $is_text_document = $has_text_document_keys
        || (isset($payload['kind']) && in_array((string) $payload['kind'], ['corpus_text', 'text_document', 'historical_text'], true))
        || ($schema !== '' && preg_match('/(?:corpus|text_document|historical_text)/i', $schema));

    if ($is_text_document) {
        foreach (['source_lines', 'reading_units', 'witnesses'] as $list_key) {
            if (!isset($payload[$list_key]) || !is_array($payload[$list_key])) {
                $payload[$list_key] = [];
            }
            $payload[$list_key] = array_values(array_filter($payload[$list_key], 'is_array'));
        }

        if (!isset($payload['translations']) || !is_array($payload['translations'])) {
            $payload['translations'] = [];
        }
    }

    if ($is_text_document && (!isset($payload['kind']) || (string) $payload['kind'] === '')) {
        $payload['kind'] = 'corpus_text';
    }

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
    foreach (['lines', 'source_lines', 'reading_units'] as $line_key) {
        if (!empty($payload[$line_key]) && is_array($payload[$line_key])) {
            return true;
        }
    }

    return false;
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
    $post = get_post($lesson_id);
    if ($post instanceof WP_Post
        && $post->post_type === 'll_content_lesson'
        && ll_tools_interlinear_payload_is_text_document($payload)
        && defined('LL_TOOLS_CONTENT_LESSON_KIND_META')) {
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');
    }
    if ($post instanceof WP_Post
        && $post->post_type === 'll_content_lesson'
        && ll_tools_interlinear_payload_is_text_document($payload)) {
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        $collection = ll_tools_interlinear_scalar($metadata, ['collection', 'corpus_collection', 'series']);
        $collection_label = ll_tools_interlinear_scalar($metadata, ['collection_label', 'corpus_collection_label', 'series_label']);
        $source_author = ll_tools_interlinear_scalar($metadata, ['source_author', 'author', 'collector']);

        $collection_meta = defined('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META')
            ? LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META
            : '_ll_tools_corpus_text_collection';
        $collection_label_meta = defined('LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META')
            ? LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_LABEL_META
            : '_ll_tools_corpus_text_collection_label';
        $source_author_meta = defined('LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META')
            ? LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META
            : '_ll_tools_corpus_text_source_author';

        if ($collection !== '') {
            update_post_meta($lesson_id, $collection_meta, sanitize_title($collection));
        } else {
            delete_post_meta($lesson_id, $collection_meta);
        }
        if ($collection_label !== '') {
            update_post_meta($lesson_id, $collection_label_meta, sanitize_text_field($collection_label));
        } else {
            delete_post_meta($lesson_id, $collection_label_meta);
        }
        if ($source_author !== '') {
            update_post_meta($lesson_id, $source_author_meta, sanitize_text_field($source_author));
        } else {
            delete_post_meta($lesson_id, $source_author_meta);
        }
    }
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
    $source_lines = isset($payload['source_lines']) && is_array($payload['source_lines']) ? $payload['source_lines'] : [];
    $reading_units = isset($payload['reading_units']) && is_array($payload['reading_units']) ? $payload['reading_units'] : [];

    return [
        'lines' => max(0, (int) ($summary['lines'] ?? count(!empty($source_lines) ? $source_lines : $lines))),
        'source_lines' => max(0, (int) ($summary['source_lines'] ?? count($source_lines))),
        'reading_units' => max(0, (int) ($summary['reading_units'] ?? count($reading_units))),
        'tokens' => max(0, (int) ($summary['tokens'] ?? array_sum(array_map(static function ($line): int {
            return is_array($line) && isset($line['tokens']) && is_array($line['tokens']) ? count($line['tokens']) : 0;
        }, !empty($source_lines) ? $source_lines : $lines)))),
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

function ll_tools_interlinear_normalize_comparison_value(string $value): string {
    $value = remove_accents($value);
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function ll_tools_interlinear_hidden_rows(array $line): array {
    $hidden = $line['hidden_rows'] ?? ($line['hide_rows'] ?? []);
    if (is_string($hidden)) {
        $hidden = preg_split('/[\s,]+/', $hidden, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($hidden)) {
        return [];
    }

    return array_map(static function ($row): string {
        return strtoupper(trim((string) $row));
    }, $hidden);
}

function ll_tools_interlinear_row_is_hidden(array $line, string $label): bool {
    return in_array(strtoupper($label), ll_tools_interlinear_hidden_rows($line), true);
}

function ll_tools_interlinear_has_spanning_row_value(array $tokens, callable $value_fn): bool {
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        $value = trim((string) call_user_func($value_fn, $token));
        if ($value !== '' && $value !== '?') {
            return true;
        }
    }

    return false;
}

function ll_tools_interlinear_has_morpheme_row_value(array $tokens, string $field): bool {
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        foreach (ll_tools_interlinear_token_morphemes($token) as $morpheme) {
            $value = $field === 'gloss' ? (string) ($morpheme['gloss'] ?? '') : (string) ($morpheme['form'] ?? '');
            $value = trim($value);
            if ($value !== '' && $value !== '?') {
                return true;
            }
        }
    }

    return false;
}

function ll_tools_interlinear_lemma_row_is_informative(array $tokens): bool {
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        $lemma = trim(ll_tools_interlinear_display_lemma($token));
        if ($lemma === '' || $lemma === '?') {
            continue;
        }

        $word = trim(ll_tools_interlinear_display_form($token));
        $morph_forms = [];
        foreach (ll_tools_interlinear_token_morphemes($token) as $morpheme) {
            $form = trim((string) ($morpheme['form'] ?? ''));
            if ($form !== '' && $form !== '?') {
                $morph_forms[] = $form;
            }
        }
        $morph = implode('', $morph_forms);
        $lemma_key = ll_tools_interlinear_normalize_comparison_value($lemma);
        $word_key = ll_tools_interlinear_normalize_comparison_value($word);
        $morph_key = ll_tools_interlinear_normalize_comparison_value($morph);
        if ($lemma_key !== '' && $lemma_key !== $word_key && $lemma_key !== $morph_key) {
            return true;
        }
    }

    return false;
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
                        <?php if (!ll_tools_interlinear_row_is_hidden($line, 'WORD') && ll_tools_interlinear_has_spanning_row_value($tokens, 'll_tools_interlinear_word_display_form')) : ?>
                            <?php echo ll_tools_interlinear_render_spanning_row('WORD', $line, $tokens, 'll_tools_interlinear_word_display_form'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                        <?php if (!ll_tools_interlinear_row_is_hidden($line, 'MORPH') && ll_tools_interlinear_has_morpheme_row_value($tokens, 'form')) : ?>
                            <?php echo ll_tools_interlinear_render_morpheme_row('MORPH', $line, $tokens, 'form'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                        <?php if (!ll_tools_interlinear_row_is_hidden($line, 'LEMMA') && ll_tools_interlinear_lemma_row_is_informative($tokens)) : ?>
                            <?php echo ll_tools_interlinear_render_spanning_row('LEMMA', $line, $tokens, 'll_tools_interlinear_display_lemma'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                        <?php if (!ll_tools_interlinear_row_is_hidden($line, 'GLOSS') && ll_tools_interlinear_has_morpheme_row_value($tokens, 'gloss')) : ?>
                            <?php echo ll_tools_interlinear_render_morpheme_row('GLOSS', $line, $tokens, 'gloss'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                        <?php if (!ll_tools_interlinear_row_is_hidden($line, 'POS') && ll_tools_interlinear_has_spanning_row_value($tokens, 'll_tools_interlinear_display_pos')) : ?>
                            <?php echo ll_tools_interlinear_render_spanning_row('POS', $line, $tokens, 'll_tools_interlinear_display_pos'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
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

function ll_tools_interlinear_payload_is_text_document(array $payload): bool {
    $kind = isset($payload['kind']) && is_scalar($payload['kind']) ? sanitize_key((string) $payload['kind']) : '';
    if (in_array($kind, ['corpus_text', 'text_document', 'historical_text'], true)) {
        return true;
    }

    $schema = isset($payload['schema']) && is_scalar($payload['schema']) ? (string) $payload['schema'] : '';
    if ($schema !== '' && preg_match('/(?:corpus|text_document|historical_text)/i', $schema)) {
        return true;
    }

    return !empty($payload['source_lines']) || !empty($payload['reading_units']);
}

function ll_tools_current_user_can_view_text_document(int $lesson_id): bool {
    if ($lesson_id <= 0) {
        return false;
    }

    $post = get_post($lesson_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_content_lesson') {
        return ll_tools_current_user_can_view_interlinear($lesson_id);
    }

    if (ll_tools_interlinear_content_lesson_is_corpus_text($lesson_id)) {
        return $post->post_status === 'publish' || current_user_can('read_post', $lesson_id);
    }

    $wordset_id = ll_tools_interlinear_get_wordset_id_for_lesson($lesson_id);
    if ($wordset_id <= 0) {
        return current_user_can('manage_options');
    }

    return !function_exists('ll_tools_user_can_view_wordset')
        || ll_tools_user_can_view_wordset($wordset_id, (int) get_current_user_id());
}

function ll_tools_text_document_user_can_view_linguist(int $lesson_id): bool {
    $post = get_post($lesson_id);
    if ($post instanceof WP_Post && $post->post_type === 'll_content_lesson') {
        $is_corpus_text = function_exists('ll_tools_content_lesson_is_corpus_text')
            ? ll_tools_content_lesson_is_corpus_text($lesson_id)
            : (
                defined('LL_TOOLS_CONTENT_LESSON_KIND_META')
                && get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, true) === 'corpus_text'
            );
        if ($is_corpus_text) {
            return ll_tools_current_user_can_view_text_document($lesson_id);
        }
    }

    return ll_tools_current_user_can_view_interlinear($lesson_id);
}

if (!defined('LL_TOOLS_TEXT_DOCUMENT_REVIEW_NOTES_META')) {
    define('LL_TOOLS_TEXT_DOCUMENT_REVIEW_NOTES_META', '_ll_tools_text_document_review_notes');
}

function ll_tools_text_document_review_notes_meta_key(): string {
    return LL_TOOLS_TEXT_DOCUMENT_REVIEW_NOTES_META;
}

function ll_tools_text_document_normalize_review_note_key(string $key): string {
    $key = trim(wp_check_invalid_utf8($key));
    $key = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', (string) $key);
    $key = trim((string) $key, '_.:-');

    return $key !== '' ? substr($key, 0, 120) : 'document';
}

function ll_tools_current_user_can_manage_text_document_review_notes(int $lesson_id): bool {
    if ($lesson_id <= 0 || !is_user_logged_in()) {
        return false;
    }
    if (current_user_can('manage_options')) {
        return true;
    }
    if (!current_user_can('view_ll_tools')) {
        return false;
    }
    if (current_user_can('edit_post', $lesson_id)) {
        return true;
    }

    $wordset_id = ll_tools_interlinear_get_wordset_id_for_lesson($lesson_id);
    return $wordset_id > 0
        && function_exists('ll_tools_user_can_manage_wordset_content')
        && ll_tools_user_can_manage_wordset_content($wordset_id, (int) get_current_user_id());
}

function ll_tools_get_text_document_review_notes(int $lesson_id): array {
    if ($lesson_id <= 0) {
        return [];
    }

    $notes = get_post_meta($lesson_id, ll_tools_text_document_review_notes_meta_key(), true);
    return is_array($notes) ? $notes : [];
}

function ll_tools_get_text_document_review_note(int $lesson_id, string $note_key): string {
    $notes = ll_tools_get_text_document_review_notes($lesson_id);
    $note_key = ll_tools_text_document_normalize_review_note_key($note_key);
    $note = $notes[$note_key] ?? '';

    return is_scalar($note) ? trim((string) $note) : '';
}

function ll_tools_set_text_document_review_note(int $lesson_id, string $note_key, string $note): string {
    if ($lesson_id <= 0) {
        return '';
    }

    $note_key = ll_tools_text_document_normalize_review_note_key($note_key);
    $note = function_exists('ll_tools_sanitize_internal_review_note')
        ? ll_tools_sanitize_internal_review_note($note)
        : trim(sanitize_textarea_field($note));

    $notes = ll_tools_get_text_document_review_notes($lesson_id);
    if ($note === '') {
        unset($notes[$note_key]);
    } else {
        $notes[$note_key] = $note;
    }

    if (empty($notes)) {
        delete_post_meta($lesson_id, ll_tools_text_document_review_notes_meta_key());
    } else {
        ksort($notes, SORT_NATURAL);
        update_post_meta($lesson_id, ll_tools_text_document_review_notes_meta_key(), $notes);
    }

    return $note;
}

function ll_tools_text_document_render_review_note_field(int $lesson_id, string $note_key, string $label = ''): string {
    if (!ll_tools_current_user_can_manage_text_document_review_notes($lesson_id)) {
        return '';
    }

    $note_key = ll_tools_text_document_normalize_review_note_key($note_key);
    $note = ll_tools_get_text_document_review_note($lesson_id, $note_key);
    $has_note = $note !== '';
    $field_id = 'll-text-document-review-note-' . $lesson_id . '-' . md5($note_key);
    $status_id = $field_id . '-status';
    if ($label === '') {
        $label = __('Internal review note', 'll-tools-text-domain');
    }

    ob_start();
    ?>
    <details
        class="ll-text-document-review-note"
        data-ll-text-document-review-note
        data-lesson-id="<?php echo esc_attr((string) $lesson_id); ?>"
        data-note-key="<?php echo esc_attr($note_key); ?>"
        <?php if ($has_note) : ?>open<?php endif; ?>
    >
        <summary class="ll-text-document-review-note__summary">
            <span><?php echo esc_html($has_note ? $label : __('Add internal review note', 'll-tools-text-domain')); ?></span>
            <span class="ll-text-document-review-note__status" id="<?php echo esc_attr($status_id); ?>" data-ll-text-document-review-note-status aria-live="polite"></span>
        </summary>
        <label class="ll-text-document-review-note__label" for="<?php echo esc_attr($field_id); ?>">
            <?php echo esc_html($label); ?>
        </label>
        <textarea
            class="ll-text-document-review-note__input"
            id="<?php echo esc_attr($field_id); ?>"
            rows="3"
            data-ll-text-document-review-note-input
            aria-describedby="<?php echo esc_attr($status_id); ?>"
            placeholder="<?php echo esc_attr__('Staff-only note. Mention a line, word, or source issue to revisit.', 'll-tools-text-domain'); ?>"
        ><?php echo esc_textarea($note); ?></textarea>
    </details>
    <?php

    return trim((string) ob_get_clean());
}

function ll_tools_save_text_document_review_note_ajax_handler(): void {
    check_ajax_referer('ll_text_document_review_note', 'nonce');

    $lesson_id = isset($_POST['lesson_id']) ? absint(wp_unslash((string) $_POST['lesson_id'])) : 0;
    $note_key = isset($_POST['note_key']) ? ll_tools_text_document_normalize_review_note_key(wp_unslash((string) $_POST['note_key'])) : 'document';
    $note = isset($_POST['note']) ? (string) wp_unslash((string) $_POST['note']) : '';

    if ($lesson_id <= 0 || !ll_tools_current_user_can_manage_text_document_review_notes($lesson_id)) {
        wp_send_json_error([
            'message' => __('You do not have permission to save this review note.', 'll-tools-text-domain'),
        ], 403);
    }

    $saved_note = ll_tools_set_text_document_review_note($lesson_id, $note_key, $note);
    wp_send_json_success([
        'lesson_id' => $lesson_id,
        'note_key' => $note_key,
        'note' => $saved_note,
    ]);
}
add_action('wp_ajax_ll_tools_save_text_document_review_note', 'll_tools_save_text_document_review_note_ajax_handler');

function ll_tools_text_document_request_arg(string $key): string {
    if (!isset($_GET[$key])) {
        return '';
    }

    $value = sanitize_key(wp_unslash((string) $_GET[$key]));
    return $value === 'linguist' ? 'interlinear' : $value;
}

function ll_tools_text_document_translation_label(array $payload, string $key): string {
    $translations = isset($payload['translations']) && is_array($payload['translations']) ? $payload['translations'] : [];
    if (isset($translations[$key]) && is_array($translations[$key])) {
        $label = ll_tools_interlinear_scalar($translations[$key], ['label', 'name', 'title']);
        if ($label !== '') {
            return $label;
        }
    }

    $labels = [
        'en' => __('English', 'll-tools-text-domain'),
        'tr' => __('Turkish', 'll-tools-text-domain'),
        'de' => __('German', 'll-tools-text-domain'),
        'ru' => __('Russian', 'll-tools-text-domain'),
    ];

    return $labels[$key] ?? strtoupper($key);
}

function ll_tools_text_document_available_translation_keys(array $payload): array {
    $keys = [];
    $translations = isset($payload['translations']) && is_array($payload['translations']) ? $payload['translations'] : [];
    foreach ($translations as $key => $value) {
        if (!is_string($key) && !is_int($key)) {
            continue;
        }
        $key = sanitize_key((string) $key);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    $units = isset($payload['reading_units']) && is_array($payload['reading_units']) ? $payload['reading_units'] : [];
    foreach ($units as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        if (isset($unit['translations']) && is_array($unit['translations'])) {
            foreach ($unit['translations'] as $key => $value) {
                $key = sanitize_key((string) $key);
                if ($key !== '' && is_scalar($value) && trim((string) $value) !== '') {
                    $keys[$key] = true;
                }
            }
        }
        foreach ($unit as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^translation_([a-z0-9_-]+)$/i', $key, $matches) && is_scalar($value) && trim((string) $value) !== '') {
                $keys[sanitize_key((string) $matches[1])] = true;
            }
        }
    }

    $keys = array_keys($keys);
    usort($keys, static function (string $left, string $right): int {
        $priority = ['tr' => 0, 'en' => 1, 'de' => 2, 'ru' => 3];
        return (($priority[$left] ?? 50) <=> ($priority[$right] ?? 50)) ?: strcmp($left, $right);
    });

    return $keys;
}

function ll_tools_text_document_selected_translation_key(array $payload): string {
    $keys = ll_tools_text_document_available_translation_keys($payload);
    if (empty($keys)) {
        return '';
    }

    $requested = ll_tools_text_document_request_arg('ll_translation');
    if ($requested !== '' && in_array($requested, $keys, true)) {
        return $requested;
    }

    foreach (['tr', 'en', 'de'] as $preferred) {
        if (in_array($preferred, $keys, true)) {
            return $preferred;
        }
    }

    return (string) $keys[0];
}

function ll_tools_text_document_unit_source_text(array $unit): string {
    return ll_tools_interlinear_scalar($unit, [
        'zazaki',
        'source',
        'source_text',
        'text',
        'orthography',
        'zazaki_orthography',
        'normalized_text',
    ]);
}

function ll_tools_text_document_unit_translation(array $unit, string $key): string {
    if ($key !== '' && isset($unit['translations']) && is_array($unit['translations'])) {
        $value = $unit['translations'][$key] ?? '';
        if (is_scalar($value) && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    $candidates = $key !== ''
        ? ['translation_' . $key, $key . '_translation', $key, 'translation']
        : ['translation'];

    return ll_tools_interlinear_scalar($unit, $candidates);
}

function ll_tools_text_document_reader_units(array $payload): array {
    $units = isset($payload['reading_units']) && is_array($payload['reading_units']) ? $payload['reading_units'] : [];
    if (!empty($units)) {
        return array_values(array_filter($units, 'is_array'));
    }

    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    $source_lines = isset($payload['source_lines']) && is_array($payload['source_lines']) ? $payload['source_lines'] : [];
    $units = !empty($source_lines) ? $source_lines : $lines;

    return array_values(array_filter($units, 'is_array'));
}

function ll_tools_text_document_view_url(string $view, string $translation_key = ''): string {
    $args = ['ll_text_view' => $view];
    if ($translation_key !== '') {
        $args['ll_translation'] = $translation_key;
    }

    return add_query_arg($args, remove_query_arg(['ll_text_view', 'll_translation']));
}

function ll_tools_text_document_render_tabs(string $current_view, string $translation_key, bool $can_view_linguist, array $payload): string {
    $tabs = [
        'reader' => __('Text', 'll-tools-text-domain'),
    ];

    $source_lines = isset($payload['source_lines']) && is_array($payload['source_lines']) ? $payload['source_lines'] : [];
    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    if ($can_view_linguist && (!empty($source_lines) || !empty($lines))) {
        $tabs['interlinear'] = __('Interlinear', 'll-tools-text-domain');
    }
    if (!empty($payload['witnesses']) && is_array($payload['witnesses'])) {
        $tabs['sources'] = __('Sources', 'll-tools-text-domain');
    }

    if (count($tabs) < 2) {
        return '';
    }

    $html = '<nav class="ll-text-document__tabs" aria-label="' . esc_attr__('Text view', 'll-tools-text-domain') . '">';
    foreach ($tabs as $view => $label) {
        $class = 'll-text-document__tab';
        if ($view === $current_view) {
            $class .= ' is-active';
        }
        $html .= '<a class="' . esc_attr($class) . '" href="' . esc_url(ll_tools_text_document_view_url($view, $translation_key)) . '"' . ($view === $current_view ? ' aria-current="page"' : '') . '>';
        $html .= esc_html($label);
        $html .= '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function ll_tools_text_document_render_translation_switcher(array $payload, string $selected_key, string $current_view): string {
    $keys = ll_tools_text_document_available_translation_keys($payload);
    if (count($keys) < 2) {
        return '';
    }

    $html = '<nav class="ll-text-document__translations" aria-label="' . esc_attr__('Translation language', 'll-tools-text-domain') . '">';
    foreach ($keys as $key) {
        $class = 'll-text-document__translation';
        if ($key === $selected_key) {
            $class .= ' is-active';
        }
        $html .= '<a class="' . esc_attr($class) . '" href="' . esc_url(ll_tools_text_document_view_url($current_view, $key)) . '"' . ($key === $selected_key ? ' aria-current="page"' : '') . '>';
        $html .= esc_html(ll_tools_text_document_translation_label($payload, $key));
        $html .= '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function ll_tools_text_document_render_reader(array $payload, string $translation_key): string {
    $units = ll_tools_text_document_reader_units($payload);
    if (empty($units)) {
        return '';
    }

    $translation_label = $translation_key !== ''
        ? ll_tools_text_document_translation_label($payload, $translation_key)
        : __('Translation', 'll-tools-text-domain');
    $source_label = ll_tools_interlinear_scalar($payload, ['source_label', 'text_label']);
    if ($source_label === '') {
        $source_label = __('Text', 'll-tools-text-domain');
    }
    $translation_heading = ll_tools_text_document_render_translation_switcher($payload, $translation_key, 'reader');
    if ($translation_heading === '') {
        $translation_heading = esc_html($translation_label);
    }

    $rows = '';
    foreach ($units as $unit) {
        if (!is_array($unit)) {
            continue;
        }
        $source_text = ll_tools_text_document_unit_source_text($unit);
        $translation = ll_tools_text_document_unit_translation($unit, $translation_key);
        if ($source_text === '' && $translation === '') {
            continue;
        }

        $rows .= '<div class="ll-text-reader__row">';
        $rows .= '<div class="ll-text-reader__cell ll-text-reader__cell--source" dir="auto">' . nl2br(esc_html($source_text)) . '</div>';
        $rows .= '<div class="ll-text-reader__cell ll-text-reader__cell--translation" dir="auto">' . nl2br(esc_html($translation)) . '</div>';
        $rows .= '</div>';
    }

    if ($rows === '') {
        return '';
    }

    return '<section class="ll-text-reader" aria-label="' . esc_attr__('Reader text', 'll-tools-text-domain') . '">'
        . '<div class="ll-text-reader__head"><span>' . esc_html($source_label) . '</span><span class="ll-text-reader__translation-head">' . $translation_heading . '</span></div>'
        . $rows
        . '</section>';
}

function ll_tools_text_document_render_print_sources(array $payload): string {
    $sources = ll_tools_text_document_render_sources($payload);
    if ($sources === '') {
        return '';
    }

    return '<section class="ll-text-document__print-sources" aria-label="' . esc_attr__('Print sources', 'll-tools-text-domain') . '">'
        . '<h2>' . esc_html__('Sources', 'll-tools-text-domain') . '</h2>'
        . $sources
        . '</section>';
}

function ll_tools_text_document_source_lines(array $payload): array {
    $source_lines = isset($payload['source_lines']) && is_array($payload['source_lines']) ? $payload['source_lines'] : [];
    if (!empty($source_lines)) {
        return array_values(array_filter($source_lines, 'is_array'));
    }

    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    return array_values(array_filter($lines, 'is_array'));
}

function ll_tools_text_document_label_from_key(string $key): string {
    $labels = [
        'lerch' => 'LERCH',
        'transcription' => 'LERCH',
        'ipa' => 'IPA',
        'zazaki' => 'ZAZAKI',
        'orthography' => 'ZAZAKI',
        'zazaki_orthography' => 'ZAZAKI',
        'gloss_en' => 'ENGLISH',
        'translation_en' => 'ENGLISH',
        'translation_tr' => 'TURKISH',
        'translation_de' => 'GERMAN',
    ];

    $key = sanitize_key($key);
    return $labels[$key] ?? strtoupper(str_replace('_', ' ', $key));
}

function ll_tools_text_document_line_rows(array $line): array {
    $raw_rows = [];
    if (isset($line['display_rows']) && is_array($line['display_rows'])) {
        $raw_rows = $line['display_rows'];
    } elseif (isset($line['rows']) && is_array($line['rows'])) {
        $raw_rows = $line['rows'];
    } elseif (isset($line['layers']) && is_array($line['layers'])) {
        $raw_rows = $line['layers'];
    }

    $rows = [];
    if (!empty($raw_rows)) {
        $keys = array_keys($raw_rows);
        $is_list = $keys === range(0, count($raw_rows) - 1);
        if ($is_list) {
            foreach ($raw_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $label = ll_tools_interlinear_scalar($row, ['label', 'name']);
                $value = ll_tools_interlinear_scalar($row, ['value', 'text', 'content']);
                if ($label !== '' && $value !== '') {
                    $rows[] = ['label' => $label, 'value' => $value];
                }
            }
        } else {
            foreach ($raw_rows as $label => $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $rows[] = ['label' => ll_tools_text_document_label_from_key((string) $label), 'value' => trim((string) $value)];
                }
            }
        }
    }

    foreach (['lerch', 'transcription', 'ipa', 'zazaki', 'orthography', 'zazaki_orthography', 'translation_en', 'translation_tr', 'translation_de'] as $key) {
        $value = ll_tools_interlinear_scalar($line, [$key]);
        if ($value !== '') {
            $rows[] = ['label' => ll_tools_text_document_label_from_key($key), 'value' => $value];
        }
    }

    $deduped = [];
    $seen = [];
    foreach ($rows as $row) {
        $label = (string) ($row['label'] ?? '');
        $value = (string) ($row['value'] ?? '');
        $key = $label . "\0" . $value;
        if ($label === '' || $value === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = ['label' => $label, 'value' => $value];
    }

    return $deduped;
}

function ll_tools_text_document_image_url($image): string {
    if (is_array($image)) {
        $attachment_id = isset($image['attachment_id']) ? (int) $image['attachment_id'] : 0;
        if ($attachment_id > 0) {
            $attachment_url = wp_get_attachment_image_url($attachment_id, 'full');
            if (is_string($attachment_url) && $attachment_url !== '') {
                return $attachment_url;
            }
        }
        $image = $image['image_url'] ?? ($image['url'] ?? ($image['src'] ?? ''));
    }

    if (!is_scalar($image)) {
        return '';
    }

    $url = trim((string) $image);
    if ($url === '') {
        return '';
    }

    if (ctype_digit($url)) {
        $attachment_url = wp_get_attachment_image_url((int) $url, 'full');
        return is_string($attachment_url) ? $attachment_url : '';
    }

    return $url;
}

function ll_tools_text_document_line_witnesses(array $line): array {
    $witnesses = [];
    foreach (['witnesses', 'source_images', 'images'] as $key) {
        if (isset($line[$key]) && is_array($line[$key])) {
            foreach ($line[$key] as $witness) {
                if (is_array($witness)) {
                    $witnesses[] = $witness;
                } elseif (is_scalar($witness)) {
                    $witnesses[] = ['image_url' => (string) $witness];
                }
            }
        }
    }

    foreach (['source_image', 'scan', 'scan_url', 'image_url'] as $key) {
        if (isset($line[$key]) && is_scalar($line[$key])) {
            $witnesses[] = [
                'label' => ll_tools_text_document_label_from_key($key),
                'image_url' => (string) $line[$key],
            ];
        }
    }

    return $witnesses;
}

function ll_tools_text_document_render_source_images(array $line): string {
    $witnesses = ll_tools_text_document_line_witnesses($line);
    if (empty($witnesses)) {
        return '';
    }

    $html = '<div class="ll-text-source-line__witnesses">';
    foreach ($witnesses as $witness) {
        $image_url = ll_tools_text_document_image_url($witness);
        if ($image_url === '') {
            continue;
        }
        $label = is_array($witness) ? ll_tools_interlinear_scalar($witness, ['label', 'name', 'source']) : '';
        $caption = is_array($witness) ? ll_tools_interlinear_scalar($witness, ['caption', 'description']) : '';
        $alt = $label !== '' ? $label : __('Source scan', 'll-tools-text-domain');
        $html .= '<figure class="ll-text-source-line__witness">';
        if ($label !== '') {
            $html .= '<figcaption>' . esc_html($label) . '</figcaption>';
        }
        $html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" />';
        if ($caption !== '') {
            $html .= '<figcaption class="ll-text-source-line__caption">' . esc_html($caption) . '</figcaption>';
        }
        $html .= '</figure>';
    }
    $html .= '</div>';

    return $html;
}

function ll_tools_text_document_render_linguist(array $payload, int $lesson_id = 0): string {
    $source_lines = ll_tools_text_document_source_lines($payload);
    if (empty($source_lines)) {
        return '';
    }

    $html = '<section class="ll-text-linguist" aria-label="' . esc_attr__('Linguistic edition', 'll-tools-text-domain') . '">';
    foreach ($source_lines as $index => $line) {
        if (!is_array($line)) {
            continue;
        }
        $line_id = ll_tools_interlinear_scalar($line, ['id', 'line_id']);
        $title = ll_tools_interlinear_scalar($line, ['title', 'label']);
        if ($title === '') {
            $title = $line_id !== '' ? $line_id : sprintf(__('Line %d', 'll-tools-text-domain'), $index + 1);
        }
        $rows = ll_tools_text_document_line_rows($line);
        $interlinear_html = ll_tools_render_interlinear_line($line, empty($rows));
        $image_html = ll_tools_text_document_render_source_images($line);
        if ($image_html === '' && empty($rows) && $interlinear_html === '') {
            continue;
        }
        $note_key = 'line:' . ($line_id !== '' ? $line_id : (string) ($index + 1));
        $note_html = $lesson_id > 0
            ? ll_tools_text_document_render_review_note_field($lesson_id, $note_key, __('Line review note', 'll-tools-text-domain'))
            : '';

        $html .= '<article class="ll-text-source-line">';
        $html .= '<h3 class="ll-text-source-line__title">' . esc_html($title) . '</h3>';
        $html .= $note_html;
        $html .= $image_html;
        if (!empty($rows)) {
            $html .= '<dl class="ll-text-source-line__rows">';
            foreach ($rows as $row) {
                $html .= '<div class="ll-text-source-line__row">';
                $html .= '<dt>' . esc_html((string) $row['label']) . '</dt>';
                $html .= '<dd dir="auto">' . nl2br(esc_html((string) $row['value'])) . '</dd>';
                $html .= '</div>';
            }
            $html .= '</dl>';
        }
        $html .= $interlinear_html;
        $html .= '</article>';
    }
    $html .= '</section>';

    return $html;
}

function ll_tools_text_document_render_sources(array $payload): string {
    $witnesses = isset($payload['witnesses']) && is_array($payload['witnesses']) ? $payload['witnesses'] : [];
    if (empty($witnesses)) {
        return '';
    }

    $html = '<section class="ll-text-sources" aria-label="' . esc_attr__('Source witnesses', 'll-tools-text-domain') . '">';
    foreach ($witnesses as $witness) {
        if (!is_array($witness)) {
            continue;
        }
        $label = ll_tools_interlinear_scalar($witness, ['label', 'title', 'name']);
        $source = ll_tools_interlinear_scalar($witness, ['citation', 'mla', 'source', 'description']);
        $note = ll_tools_interlinear_scalar($witness, ['note', 'role', 'usage']);
        $pages = ll_tools_interlinear_scalar($witness, ['pages', 'page_range']);
        $rights = ll_tools_interlinear_scalar($witness, ['rights', 'license']);
        $url = ll_tools_interlinear_scalar($witness, ['url', 'source_url', 'access_url']);
        $image_url = ll_tools_text_document_image_url($witness);
        $links = [];
        if (isset($witness['urls']) && is_array($witness['urls'])) {
            foreach ($witness['urls'] as $link) {
                if (is_array($link)) {
                    $link_url = ll_tools_interlinear_scalar($link, ['url', 'href']);
                    $link_label = ll_tools_interlinear_scalar($link, ['label', 'title', 'name']);
                    if ($link_url !== '') {
                        $links[] = ['url' => $link_url, 'label' => $link_label !== '' ? $link_label : $link_url];
                    }
                } elseif (is_scalar($link) && trim((string) $link) !== '') {
                    $link_url = trim((string) $link);
                    $links[] = ['url' => $link_url, 'label' => $link_url];
                }
            }
        }
        if ($url !== '') {
            array_unshift($links, ['url' => $url, 'label' => $url]);
        }
        if ($label === '' && $source === '' && $note === '' && $pages === '' && $rights === '' && empty($links) && $image_url === '') {
            continue;
        }

        $html .= '<article class="ll-text-sources__item">';
        if ($label !== '') {
            $html .= '<h3>' . esc_html($label) . '</h3>';
        }
        if ($source !== '') {
            $html .= '<p class="ll-text-sources__citation">' . esc_html($source) . '</p>';
        }
        if ($pages !== '') {
            $html .= '<p><strong>' . esc_html__('Pages:', 'll-tools-text-domain') . '</strong> ' . esc_html($pages) . '</p>';
        }
        if ($note !== '') {
            $html .= '<p>' . esc_html($note) . '</p>';
        }
        if ($rights !== '') {
            $html .= '<p><strong>' . esc_html__('Rights:', 'll-tools-text-domain') . '</strong> ' . esc_html($rights) . '</p>';
        }
        if (!empty($links)) {
            $html .= '<ul class="ll-text-sources__links">';
            foreach ($links as $link) {
                $html .= '<li><a href="' . esc_url($link['url']) . '">' . esc_html($link['label']) . '</a></li>';
            }
            $html .= '</ul>';
        }
        if ($image_url !== '') {
            $html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($label !== '' ? $label : __('Source witness', 'll-tools-text-domain')) . '" loading="lazy" decoding="async" />';
        }
        $html .= '</article>';
    }
    $html .= '</section>';

    return $html;
}

function ll_tools_render_text_document_block(int $lesson_id, array $payload): string {
    if (!ll_tools_current_user_can_view_text_document($lesson_id)) {
        return '';
    }

    $can_view_linguist = ll_tools_text_document_user_can_view_linguist($lesson_id);
    $translation_key = ll_tools_text_document_selected_translation_key($payload);
    $requested_view = ll_tools_text_document_request_arg('ll_text_view');
    $has_sources = !empty($payload['witnesses']) && is_array($payload['witnesses']);
    $allowed_views = ['reader'];
    if ($can_view_linguist) {
        $allowed_views[] = 'interlinear';
    }
    if ($has_sources) {
        $allowed_views[] = 'sources';
    }
    $view = in_array($requested_view, $allowed_views, true) ? $requested_view : 'reader';
    if (!$can_view_linguist && $view === 'interlinear') {
        $view = 'reader';
    }

    if ($view === 'interlinear') {
        $body = ll_tools_text_document_render_linguist($payload, $lesson_id);
    } elseif ($view === 'sources') {
        $body = ll_tools_text_document_render_sources($payload);
    } else {
        $view = 'reader';
        $body = ll_tools_text_document_render_reader($payload, $translation_key);
        $body .= ll_tools_text_document_render_print_sources($payload);
    }

    if ($body === '' && $view !== 'reader') {
        $view = 'reader';
        $body = ll_tools_text_document_render_reader($payload, $translation_key);
    }

    if ($body === '') {
        return '';
    }

    $title = ll_tools_interlinear_scalar($payload, ['title', 'document_title']);
    $tabs = ll_tools_text_document_render_tabs($view, $translation_key, $can_view_linguist, $payload);
    $review_note_html = ll_tools_text_document_render_review_note_field($lesson_id, 'document', __('Document review note', 'll-tools-text-domain'));
    $print_button = $view === 'reader'
        ? '<button type="button" class="ll-text-document__print-button" data-ll-text-document-print>' . esc_html__('Print', 'll-tools-text-domain') . '</button>'
        : '';

    ob_start();
    ?>
    <section class="ll-text-document<?php echo $can_view_linguist ? ' ll-text-document--has-interlinear' : ''; ?>" data-ll-text-document data-view="<?php echo esc_attr($view); ?>">
        <?php if ($title !== '' || $tabs !== '') : ?>
            <div class="ll-text-document__head">
                <?php if ($title !== '') : ?>
                    <h2 class="ll-text-document__title"><?php echo esc_html($title); ?></h2>
                <?php endif; ?>
                <div class="ll-text-document__head-actions">
                    <?php echo $tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php echo $print_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        <?php endif; ?>
        <?php echo $review_note_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </section>
    <?php

    return trim((string) ob_get_clean());
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
    if (!ll_tools_interlinear_has_payload($lesson_id)) {
        return '';
    }

    $payload = ll_tools_interlinear_get_payload($lesson_id);
    if (ll_tools_interlinear_payload_is_text_document($payload)) {
        return ll_tools_render_text_document_block($lesson_id, $payload);
    }

    if (!ll_tools_current_user_can_view_interlinear($lesson_id)) {
        return '';
    }

    $post = get_post($lesson_id);
    $is_vocab_lesson = $post instanceof WP_Post && $post->post_type === 'll_vocab_lesson';
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
    $show_label = $is_vocab_lesson
        ? __('Interlinear', 'll-tools-text-domain')
        : __('Show interlinear', 'll-tools-text-domain');
    $hide_label = $is_vocab_lesson
        ? __('Interlinear', 'll-tools-text-domain')
        : __('Hide interlinear', 'll-tools-text-domain');

    ob_start();
    ?>
    <details class="<?php echo esc_attr($classes); ?>" data-ll-interlinear>
        <summary class="ll-interlinear__summary"<?php echo $summary_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
            <span class="ll-interlinear__summary-icon ll-interlinear__summary-icon--table" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                    <rect x="3" y="3.5" width="14" height="13" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M3 7.8h14M3 12.1h14M7.7 3.5v13M12.3 3.5v13" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round"/>
                </svg>
            </span>
            <span class="ll-interlinear__summary-label ll-interlinear__summary-label--show"><?php echo esc_html($show_label); ?></span>
            <span class="ll-interlinear__summary-label ll-interlinear__summary-label--hide"><?php echo esc_html($hide_label); ?></span>
            <?php if (!$is_vocab_lesson) : ?>
                <span class="ll-interlinear__staff-label"><?php echo esc_html__('Staff', 'll-tools-text-domain'); ?></span>
            <?php endif; ?>
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
