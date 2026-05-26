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
        'TEXT' => 'TEXT',
        'LINE' => 'TEXT',
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

        if ($label === 'TEXT') {
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

function ll_tools_interlinear_shortcode($atts = [], $content = '', $tag = 'll_interlinear'): string {
    $atts = shortcode_atts([
        'text' => '',
        'show_text' => '1',
        'class' => '',
    ], (array) $atts, $tag);

    $line = ll_tools_interlinear_shortcode_parse_line((string) $content, (string) ($atts['text'] ?? ''));
    if (empty($line)) {
        return '';
    }

    if (function_exists('ll_tools_mark_public_assets_needed')) {
        ll_tools_mark_public_assets_needed();
    }

    $lines_html = ll_tools_render_interlinear_lines([$line], ll_tools_interlinear_shortcode_is_truthy($atts['show_text'] ?? '1'));
    if ($lines_html === '') {
        return '';
    }

    $classes = ll_tools_interlinear_shortcode_class_list((string) ($atts['class'] ?? ''));

    return '<div class="' . esc_attr(implode(' ', $classes)) . '" data-ll-interlinear-shortcode aria-label="' . esc_attr__('Interlinear analysis', 'll-tools-text-domain') . '">' . $lines_html . '</div>';
}
add_shortcode('ll_interlinear', 'll_tools_interlinear_shortcode');
