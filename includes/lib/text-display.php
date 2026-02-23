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

if (!function_exists('ll_tools_esc_html_display')) {
    function ll_tools_esc_html_display($text): string {
        return esc_html(ll_tools_protect_maqqef_for_display($text));
    }
}
