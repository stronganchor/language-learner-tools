<?php
if (!defined('WPINC')) { die; }

function ll_tools_interlinear_shortcode_is_truthy($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return !in_array($normalized, ['0', 'false', 'no', 'off', ''], true);
}

function ll_tools_interlinear_shortcode_decode_cell(string $value): string {
    $charset = function_exists('get_bloginfo') ? (string) get_bloginfo('charset') : 'UTF-8';
    if ($charset === '') {
        $charset = 'UTF-8';
    }

    return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, $charset));
}

function ll_tools_interlinear_shortcode_normalize_row_label(string $label): string {
    $label = strtoupper(trim($label));
    $label = str_replace([' ', '-'], '_', $label);

    $aliases = [
        'FORM' => 'WORD',
        'FORMS' => 'WORD',
        'WORD' => 'WORD',
        'WORDS' => 'WORD',
        'SURFACE' => 'WORD',
        'MORPH' => 'MORPH',
        'MORPHEME' => 'MORPH',
        'MORPHEMES' => 'MORPH',
        'LEMMA' => 'LEMMA',
        'LEMMAS' => 'LEMMA',
        'GLOSS' => 'GLOSS',
        'GLOSSES' => 'GLOSS',
        'TRANSLATION' => 'GLOSS',
        'TRANSLATIONS' => 'GLOSS',
        'POS' => 'POS',
        'PART_OF_SPEECH' => 'POS',
        'SENTENCE' => 'SENTENCE',
        'TEXT' => 'TEXT',
        'LINE' => 'TEXT',
        'IPA' => 'IPA',
        'TRANSCRIPTION' => 'IPA',
        'FREE' => 'FREE_TRANSLATION',
        'FREE_TRANSLATION' => 'FREE_TRANSLATION',
        'FREE_TRANS' => 'FREE_TRANSLATION',
        'FREE_TRANSL' => 'FREE_TRANSLATION',
    ];

    return $aliases[$label] ?? '';
}

function ll_tools_interlinear_shortcode_clean_content(string $content): string {
    if (function_exists('shortcode_unautop')) {
        $content = shortcode_unautop($content);
    }
    $content = preg_replace('/<br\s*\/?>/i', "\n", $content) ?? $content;
    $content = str_ireplace(['</p>', '</div>'], "\n", $content);
    $content = wp_strip_all_tags($content);

    return str_replace(["\r\n", "\r"], "\n", $content);
}

function ll_tools_interlinear_shortcode_parse_line(string $content, string $line_text = ''): array {
    $content = ll_tools_interlinear_shortcode_clean_content($content);
    $rows = [];
    $line_text = ll_tools_interlinear_shortcode_decode_cell($line_text);

    foreach (explode("\n", $content) as $raw_line) {
        $raw_line = trim($raw_line);
        if ($raw_line === '' || str_starts_with($raw_line, '#')) {
            continue;
        }

        $delimiter = strpos($raw_line, "\t") !== false ? "\t" : '|';
        $parts = array_map('ll_tools_interlinear_shortcode_decode_cell', explode($delimiter, $raw_line));
        if (count($parts) < 2) {
            continue;
        }

        $label = ll_tools_interlinear_shortcode_normalize_row_label((string) array_shift($parts));
        if ($label === '') {
            continue;
        }

        if ($label === 'SENTENCE' || $label === 'TEXT') {
            $line_text = trim(implode(' ', array_filter($parts, static function (string $part): bool {
                return $part !== '';
            })));
            continue;
        }

        $rows[$label] = array_values($parts);
    }

    $token_count = 0;
    foreach ($rows as $cells) {
        $token_count = max($token_count, count($cells));
    }
    if ($token_count <= 0) {
        return [];
    }

    $tokens = [];
    for ($index = 0; $index < $token_count; $index++) {
        $form = (string) ($rows['WORD'][$index] ?? ($rows['MORPH'][$index] ?? ''));
        $gloss = (string) ($rows['GLOSS'][$index] ?? '');
        $pos = (string) ($rows['POS'][$index] ?? '');
        $morph_form = (string) ($rows['MORPH'][$index] ?? $form);

        $token = [
            'form' => $form,
            'gloss' => $gloss,
        ];

        if (isset($rows['LEMMA'][$index])) {
            $token['lemma'] = (string) $rows['LEMMA'][$index];
        }
        if ($pos !== '') {
            $token['pos'] = $pos;
        }

        $token['morphemes'] = [[
            'form' => $morph_form,
            'gloss' => $gloss,
            'pos' => $pos,
        ]];

        if ($form !== '' || $gloss !== '' || $pos !== '' || $morph_form !== '') {
            $tokens[] = $token;
        }
    }
    if (empty($tokens)) {
        return [];
    }

    $hidden_rows = [];
    foreach (['WORD', 'MORPH', 'LEMMA', 'GLOSS', 'POS'] as $label) {
        if (!isset($rows[$label])) {
            $hidden_rows[] = $label;
        }
    }

    return [
        'id' => 'shortcode-' . substr(md5($line_text . "\n" . $content), 0, 12),
        'text' => $line_text,
        'tokens' => $tokens,
        'hidden_rows' => $hidden_rows,
    ];
}

function ll_tools_interlinear_shortcode_class_list(string $class_attr): array {
    $classes = ['ll-interlinear', 'll-interlinear--shortcode'];
    $extra = preg_split('/\s+/', trim($class_attr), -1, PREG_SPLIT_NO_EMPTY);
    foreach ((array) $extra as $class) {
        $class = sanitize_html_class((string) $class);
        if ($class !== '') {
            $classes[] = $class;
        }
    }

    return array_values(array_unique($classes));
}

function ll_tools_interlinear_shortcode_parse_table(string $content, array $atts): array {
    $content = ll_tools_interlinear_shortcode_clean_content($content);
    $rows = [];
    $sentence = ll_tools_interlinear_shortcode_decode_cell((string) ($atts['text'] ?? ''));
    $ipa = ll_tools_interlinear_shortcode_decode_cell((string) ($atts['ipa'] ?? ''));
    $free_translation = ll_tools_interlinear_shortcode_decode_cell((string) (
        $atts['free_translation']
        ?? $atts['translation']
        ?? $atts['free']
        ?? ''
    ));

    foreach (explode("\n", $content) as $raw_line) {
        $raw_line = trim($raw_line);
        if ($raw_line === '' || str_starts_with($raw_line, '#')) {
            continue;
        }

        $tab_position = strpos($raw_line, "\t");
        $pipe_position = strpos($raw_line, '|');
        if ($tab_position === false && $pipe_position === false) {
            continue;
        }
        $delimiter = $tab_position !== false && ($pipe_position === false || $tab_position < $pipe_position) ? "\t" : '|';
        $delimiter_position = $delimiter === "\t" ? $tab_position : $pipe_position;

        $raw_label = substr($raw_line, 0, $delimiter_position);
        $raw_value = substr($raw_line, $delimiter_position + strlen($delimiter));
        $label = ll_tools_interlinear_shortcode_normalize_row_label(
            ll_tools_interlinear_shortcode_decode_cell((string) $raw_label)
        );
        if ($label === '') {
            continue;
        }

        if ($label === 'SENTENCE' || $label === 'TEXT') {
            $sentence = ll_tools_interlinear_shortcode_decode_cell((string) $raw_value);
            continue;
        }

        if ($label === 'IPA') {
            $ipa = ll_tools_interlinear_shortcode_decode_cell((string) $raw_value);
            continue;
        }

        if ($label === 'FREE_TRANSLATION') {
            $free_translation = ll_tools_interlinear_shortcode_decode_cell((string) $raw_value);
            continue;
        }

        $parts = array_map('ll_tools_interlinear_shortcode_decode_cell', explode($delimiter, $raw_value));
        $rows[$label] = array_values($parts);
    }

    $column_count = 0;
    foreach ($rows as $cells) {
        $column_count = max($column_count, count($cells));
    }

    return [
        'sentence' => $sentence,
        'ipa' => $ipa,
        'free_translation' => $free_translation,
        'rows' => $rows,
        'column_count' => $column_count,
    ];
}

function ll_tools_interlinear_shortcode_row_order(array $rows): array {
    $order = [];
    foreach (['WORD', 'MORPH', 'LEMMA', 'GLOSS', 'POS'] as $label) {
        if (array_key_exists($label, $rows)) {
            $order[] = $label;
        }
    }

    return $order;
}

function ll_tools_interlinear_shortcode_render_cell(string $value, string $label): string {
    if (function_exists('ll_tools_interlinear_render_text_cell')) {
        return ll_tools_interlinear_render_text_cell($value, $label);
    }

    return esc_html($value !== '' ? $value : '?');
}

function ll_tools_interlinear_shortcode_render_table_row(string $label, array $cells, int $column_count): string {
    $html = '<tr class="ll-interlinear-shortcode__token-row ll-interlinear-shortcode__token-row--' . esc_attr(strtolower($label)) . '"><th scope="row">' . esc_html($label) . '</th>';
    for ($index = 0; $index < $column_count; $index++) {
        $value = (string) ($cells[$index] ?? '');
        $html .= '<td>' . ll_tools_interlinear_shortcode_render_cell($value, $label) . '</td>';
    }
    $html .= '</tr>';

    return $html;
}

function ll_tools_interlinear_shortcode_render_sentence_html(string $sentence): string {
    if ($sentence === '') {
        return '';
    }

    if (function_exists('do_shortcode')) {
        return do_shortcode($sentence);
    }

    return esc_html($sentence);
}

function ll_tools_interlinear_shortcode_render_text_row(string $label, string $content, string $class_name, int $column_count, bool $allow_shortcodes = false): string {
    $html = '<tr class="' . esc_attr($class_name) . '"><th scope="row">' . esc_html($label) . '</th><td colspan="' . esc_attr((string) max(1, $column_count)) . '">';
    $html .= $allow_shortcodes ? ll_tools_interlinear_shortcode_render_sentence_html($content) : esc_html($content);
    $html .= '</td></tr>';

    return $html;
}

function ll_tools_interlinear_shortcode_render_table(array $parsed): string {
    $rows = is_array($parsed['rows'] ?? null) ? $parsed['rows'] : [];
    $row_order = ll_tools_interlinear_shortcode_row_order($rows);
    $column_count = max(1, (int) ($parsed['column_count'] ?? 0));
    if (empty($row_order)) {
        return '';
    }

    $sentence = (string) ($parsed['sentence'] ?? '');
    $ipa = (string) ($parsed['ipa'] ?? '');
    $free_translation = (string) ($parsed['free_translation'] ?? '');

    $html = '<div class="ll-interlinear-grid"><table class="ll-interlinear-table"><tbody>';
    if ($sentence !== '') {
        $html .= ll_tools_interlinear_shortcode_render_text_row(__('TEXT', 'll-tools-text-domain'), $sentence, 'll-interlinear-shortcode__sentence', $column_count, true);
    }
    if ($ipa !== '') {
        $html .= ll_tools_interlinear_shortcode_render_text_row(__('IPA', 'll-tools-text-domain'), $ipa, 'll-interlinear-shortcode__ipa ll-ipa', $column_count);
    }
    foreach ($row_order as $label) {
        $html .= ll_tools_interlinear_shortcode_render_table_row($label, (array) $rows[$label], $column_count);
    }
    if ($free_translation !== '') {
        $html .= ll_tools_interlinear_shortcode_render_text_row(__('FREE', 'll-tools-text-domain'), $free_translation, 'll-interlinear-shortcode__free-translation', $column_count);
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function ll_tools_interlinear_shortcode($atts = [], $content = '', $tag = 'll_interlinear'): string {
    $atts = shortcode_atts([
        'text' => '',
        'ipa' => '',
        'show_text' => '1',
        'translation' => '',
        'free_translation' => '',
        'free' => '',
        'class' => '',
    ], (array) $atts, $tag);

    $parsed = ll_tools_interlinear_shortcode_parse_table((string) $content, $atts);
    if (empty($parsed['rows']) || (int) ($parsed['column_count'] ?? 0) <= 0) {
        return '';
    }

    if (function_exists('ll_tools_mark_public_assets_needed')) {
        ll_tools_mark_public_assets_needed();
    }

    if (!ll_tools_interlinear_shortcode_is_truthy($atts['show_text'] ?? '1')) {
        $parsed['sentence'] = '';
        $parsed['ipa'] = '';
    }

    $table_html = ll_tools_interlinear_shortcode_render_table($parsed);
    $classes = ll_tools_interlinear_shortcode_class_list((string) ($atts['class'] ?? ''));

    return '<div class="' . esc_attr(implode(' ', $classes)) . '" data-ll-interlinear-shortcode aria-label="' . esc_attr__('Interlinear analysis', 'll-tools-text-domain') . '">' . $table_html . '</div>';
}
add_shortcode('ll_interlinear', 'll_tools_interlinear_shortcode');
