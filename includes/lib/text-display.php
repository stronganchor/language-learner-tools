<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_protect_maqqef_for_display')) {
    /**
     * Wrap Hebrew maqqef with WORD JOINER to prevent line breaks at that character.
     *
     * Keeps the visible glyph unchanged and is idempotent if called multiple times.
     */
    function ll_tools_protect_maqqef_for_display($text): string {
        $value = (string) $text;
        if ($value === '') {
            return '';
        }

        $maqqef = "\u{05BE}";
        if (strpos($value, $maqqef) === false) {
            return $value;
        }

        $word_joiner = "\u{2060}";
        $normalized = preg_replace(
            '/\x{2060}*\x{05BE}\x{2060}*/u',
            $word_joiner . $maqqef . $word_joiner,
            $value
        );

        return is_string($normalized) ? $normalized : $value;
    }
}

if (!function_exists('ll_tools_strip_display_word_joiners')) {
    /**
     * Remove display-only WORD JOINER characters if pasted back into editable inputs.
     */
    function ll_tools_strip_display_word_joiners($text): string {
        $value = (string) $text;
        if ($value === '') {
            return '';
        }

        return str_replace("\u{2060}", '', $value);
    }
}

if (!function_exists('ll_tools_decode_display_entities')) {
    /**
     * Decode text that was stored with HTML entities before rendering it as plain text.
     */
    function ll_tools_decode_display_entities($text): string {
        $value = (string) $text;
        if ($value === '') {
            return '';
        }

        $flags = ENT_QUOTES;
        if (defined('ENT_HTML5')) {
            $flags |= ENT_HTML5;
        }
        if (defined('ENT_SUBSTITUTE')) {
            $flags |= ENT_SUBSTITUTE;
        }

        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($value, $flags, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return $value;
    }
}

if (!function_exists('ll_tools_normalize_text_for_search')) {
    /**
     * Normalize user-facing text for forgiving search comparisons.
     *
     * This is not a display transform. It intentionally folds accents and the
     * Turkish dotted/dotless I variants so plain lowercase queries can match
     * labels like "\u{0130}nsanlar" without depending on the PHP or database locale.
     */
    function ll_tools_normalize_text_for_search($text): string {
        $value = (string) $text;
        if ($value === '') {
            return '';
        }

        $flags = ENT_QUOTES;
        if (defined('ENT_HTML5')) {
            $flags |= ENT_HTML5;
        }
        if (defined('ENT_SUBSTITUTE')) {
            $flags |= ENT_SUBSTITUTE;
        }

        $value = html_entity_decode($value, $flags, 'UTF-8');
        $value = wp_strip_all_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }

        if (class_exists('Normalizer')) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $value = $decomposed;
            }
        }

        $without_marks = preg_replace('/\p{Mn}+/u', '', $value);
        if (is_string($without_marks)) {
            $value = $without_marks;
        }

        $value = strtr($value, [
            "\u{0130}" => 'i',
            "\u{0131}" => 'i',
            "\u{0142}" => 'l',
            "\u{0111}" => 'd',
            "\u{00F0}" => 'd',
            "\u{00FE}" => 'th',
            "\u{00E6}" => 'ae',
            "\u{0153}" => 'oe',
            "\u{00DF}" => 'ss',
        ]);

        $value = preg_replace('/\s+/u', ' ', $value);

        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('ll_tools_text_matches_search')) {
    function ll_tools_text_matches_search($haystack, string $query): bool {
        $needle = ll_tools_normalize_text_for_search($query);
        if ($needle === '') {
            return true;
        }

        $candidate = ll_tools_normalize_text_for_search($haystack);
        return $candidate !== '' && strpos($candidate, $needle) !== false;
    }
}

if (!function_exists('ll_tools_any_text_matches_search')) {
    function ll_tools_any_text_matches_search(array $haystacks, string $query): bool {
        $needle = ll_tools_normalize_text_for_search($query);
        if ($needle === '') {
            return true;
        }

        foreach ($haystacks as $haystack) {
            $candidate = ll_tools_normalize_text_for_search($haystack);
            if ($candidate !== '' && strpos($candidate, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ll_tools_esc_html_display')) {
    function ll_tools_esc_html_display($text): string {
        return esc_html(ll_tools_protect_maqqef_for_display(ll_tools_decode_display_entities($text)));
    }
}
