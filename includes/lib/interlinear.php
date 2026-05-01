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

function ll_tools_render_interlinear_block(int $lesson_id): string {
    if (!ll_tools_current_user_can_view_interlinear($lesson_id) || !ll_tools_interlinear_has_payload($lesson_id)) {
        return '';
    }

    $payload = ll_tools_interlinear_get_payload($lesson_id);
    $summary = ll_tools_interlinear_summary($payload);
    $lines = isset($payload['lines']) && is_array($payload['lines']) ? $payload['lines'] : [];
    $panel_id = 'll-interlinear-panel-' . $lesson_id;
    $schema = isset($payload['schema']) && is_scalar($payload['schema']) ? (string) $payload['schema'] : '';
    $source = (string) get_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_SOURCE_META, true);
    $updated_at = (string) get_post_meta($lesson_id, LL_TOOLS_INTERLINEAR_UPDATED_AT_META, true);

    ob_start();
    ?>
    <details class="ll-interlinear" data-ll-interlinear>
        <summary class="ll-interlinear__summary" aria-controls="<?php echo esc_attr($panel_id); ?>">
            <span class="ll-interlinear__summary-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">
                    <path d="M3 5.5h14M3 10h14M3 14.5h14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    <path d="M6 3v14M14 3v14" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" opacity=".65"/>
                </svg>
            </span>
            <span class="ll-interlinear__summary-label ll-interlinear__summary-label--show"><?php echo esc_html__('Show interlinear', 'll-tools-text-domain'); ?></span>
            <span class="ll-interlinear__summary-label ll-interlinear__summary-label--hide"><?php echo esc_html__('Hide interlinear', 'll-tools-text-domain'); ?></span>
            <span class="ll-interlinear__staff-label"><?php echo esc_html__('Staff', 'll-tools-text-domain'); ?></span>
        </summary>
        <div class="ll-interlinear__panel" id="<?php echo esc_attr($panel_id); ?>">
            <div class="ll-interlinear__meta" aria-label="<?php echo esc_attr__('Interlinear summary', 'll-tools-text-domain'); ?>">
                <span><?php echo esc_html(sprintf(_n('%d line', '%d lines', $summary['lines'], 'll-tools-text-domain'), $summary['lines'])); ?></span>
                <span><?php echo esc_html(sprintf(_n('%d token', '%d tokens', $summary['tokens'], 'll-tools-text-domain'), $summary['tokens'])); ?></span>
                <?php if ($summary['matched_tokens'] > 0 || $summary['matched_pct'] !== '') : ?>
                    <span><?php echo esc_html(sprintf(__('Matched: %1$d %2$s', 'll-tools-text-domain'), $summary['matched_tokens'], $summary['matched_pct'])); ?></span>
                <?php endif; ?>
                <?php if ($schema !== '') : ?>
                    <span><?php echo esc_html($schema); ?></span>
                <?php endif; ?>
                <?php if ($source !== '') : ?>
                    <span><?php echo esc_html($source); ?></span>
                <?php endif; ?>
                <?php if ($updated_at !== '') : ?>
                    <span><?php echo esc_html($updated_at); ?></span>
                <?php endif; ?>
            </div>
            <div class="ll-interlinear__lines">
                <?php foreach ($lines as $line_index => $line) : ?>
                    <?php
                    if (!is_array($line)) {
                        continue;
                    }
                    $line_id = ll_tools_interlinear_scalar($line, ['id', 'line_id']);
                    $line_text = ll_tools_interlinear_scalar($line, ['text', 'text_projected', 'sentence']);
                    $start = ll_tools_interlinear_scalar($line, ['start_sec', 'start']);
                    $end = ll_tools_interlinear_scalar($line, ['end_sec', 'end']);
                    $tokens = isset($line['tokens']) && is_array($line['tokens']) ? array_values(array_filter($line['tokens'], 'is_array')) : [];
                    ?>
                    <article class="ll-interlinear-line">
                        <div class="ll-interlinear-line__header">
                            <span class="ll-interlinear-line__label">
                                <?php echo esc_html($line_id !== '' ? sprintf(__('Line %s', 'll-tools-text-domain'), $line_id) : sprintf(__('Line %d', 'll-tools-text-domain'), $line_index + 1)); ?>
                            </span>
                            <?php if ($start !== '' || $end !== '') : ?>
                                <span class="ll-interlinear-line__time"><?php echo esc_html(trim($start . ' - ' . $end, " \t\n\r\0\x0B-")); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($line_text !== '') : ?>
                            <div class="ll-interlinear-line__text" dir="auto"><?php echo esc_html($line_text); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tokens)) : ?>
                            <div class="ll-interlinear-token-grid" role="list">
                                <?php foreach ($tokens as $token) : ?>
                                    <?php
                                    $word = trim(ll_tools_interlinear_scalar($token, ['prefix_punct']) . ll_tools_interlinear_scalar($token, ['corrected_form', 'form']) . ll_tools_interlinear_scalar($token, ['suffix_punct']));
                                    $morph = ll_tools_interlinear_token_morpheme_line($token, 'morph');
                                    $gloss = ll_tools_interlinear_token_morpheme_line($token, 'gloss');
                                    $lemma = ll_tools_interlinear_scalar($token, ['corrected_lemma', 'lemma']);
                                    $pos = ll_tools_interlinear_scalar($token, ['corrected_pos', 'pos']);
                                    $confidence = ll_tools_interlinear_format_confidence($token['corrected_confidence'] ?? ($token['confidence'] ?? null));
                                    ?>
                                    <div class="ll-interlinear-token" role="listitem">
                                        <span class="ll-interlinear-token__cell ll-interlinear-token__cell--word" data-label="<?php echo esc_attr__('Word', 'll-tools-text-domain'); ?>" dir="auto"><?php echo esc_html($word !== '' ? $word : '...'); ?></span>
                                        <span class="ll-interlinear-token__cell" data-label="<?php echo esc_attr__('Morph', 'll-tools-text-domain'); ?>" dir="auto"><?php echo esc_html($morph !== '' ? $morph : '...'); ?></span>
                                        <span class="ll-interlinear-token__cell" data-label="<?php echo esc_attr__('Gloss', 'll-tools-text-domain'); ?>" dir="auto"><?php echo esc_html($gloss !== '' ? $gloss : '...'); ?></span>
                                        <span class="ll-interlinear-token__cell ll-interlinear-token__cell--meta" data-label="<?php echo esc_attr__('Lemma', 'll-tools-text-domain'); ?>" dir="auto"><?php echo esc_html($lemma !== '' ? $lemma : '...'); ?></span>
                                        <span class="ll-interlinear-token__cell ll-interlinear-token__cell--meta" data-label="<?php echo esc_attr__('POS', 'll-tools-text-domain'); ?>"><?php echo esc_html($pos !== '' ? $pos : '...'); ?></span>
                                        <?php if ($confidence !== '') : ?>
                                            <span class="ll-interlinear-token__cell ll-interlinear-token__cell--confidence" data-label="<?php echo esc_attr__('Confidence', 'll-tools-text-domain'); ?>"><?php echo esc_html($confidence); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </details>
    <?php

    return trim((string) ob_get_clean());
}
