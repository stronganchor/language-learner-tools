<?php
if (!defined('WPINC')) { die; }

/**
 * Return the supported semantic mark tones.
 *
 * The colors intentionally preserve TurkishTextbook's legacy color1/color2/
 * color3 presentation while the public API uses one reusable shortcode.
 *
 * @return string[]
 */
function ll_tools_semantic_mark_tones(): array {
    return ['orange', 'blue', 'green'];
}

/**
 * Return all public shortcode tags provided by this module.
 *
 * @return string[]
 */
function ll_tools_semantic_mark_shortcode_tags(): array {
    return ['ll_mark', 'color1', 'color2', 'color3'];
}

/**
 * Resolve a canonical tone for the requested shortcode.
 */
function ll_tools_semantic_mark_resolve_tone($raw_tone, string $tag): string {
    $legacy_tones = [
        'color1' => 'orange',
        'color2' => 'blue',
        'color3' => 'green',
    ];

    if (isset($legacy_tones[$tag])) {
        return $legacy_tones[$tag];
    }

    $tone = sanitize_key(strtolower(trim((string) $raw_tone)));
    if (!in_array($tone, ll_tools_semantic_mark_tones(), true)) {
        return 'orange';
    }

    return $tone;
}

function ll_tools_enqueue_semantic_mark_assets(): void {
    if (function_exists('ll_tools_mark_public_assets_needed')) {
        ll_tools_mark_public_assets_needed();
    }
    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }

    // The shortcode can be rendered by a page builder after the normal
    // pre-head asset scan. Re-enqueuing these timestamped handles is safe and
    // ensures the style remains printable by late-rendering integrations.
    if (function_exists('ll_enqueue_asset_by_timestamp')
        && !wp_style_is('ll-tools-style', 'enqueued')
    ) {
        ll_enqueue_asset_by_timestamp('/css/ipa-fonts.css', 'll-ipa-fonts');
        ll_enqueue_asset_by_timestamp(
            '/css/language-learner-tools.css',
            'll-tools-style',
            ['ll-ipa-fonts']
        );
    }
}

/**
 * Keep semantic marks valid inline HTML even when nested shortcodes emit
 * block-level markup.
 *
 * @return array<string,array<string,bool>>
 */
function ll_tools_semantic_mark_inline_html_allowlist(): array {
    return [
        'a' => [
            'class' => true,
            'href' => true,
            'hreflang' => true,
            'rel' => true,
            'target' => true,
            'title' => true,
        ],
        'abbr' => ['title' => true],
        'b' => [],
        'bdi' => ['dir' => true],
        'bdo' => ['dir' => true],
        'br' => [],
        'cite' => [],
        'code' => [],
        'em' => [],
        'i' => [],
        'kbd' => [],
        'q' => ['cite' => true],
        'rp' => [],
        'rt' => [],
        'ruby' => [],
        's' => [],
        'small' => [],
        'span' => [
            'class' => true,
            'dir' => true,
            'lang' => true,
        ],
        'strong' => [],
        'sub' => [],
        'sup' => [],
        'u' => [],
    ];
}

/**
 * Render a compact, class-only semantic mark.
 *
 * Enclosed shortcodes are expanded before the result is restricted to safe
 * inline phrasing markup. Block wrappers are removed while their text remains,
 * so the outer span stays valid and nested output cannot inject unsafe HTML.
 */
function ll_tools_semantic_mark_shortcode($atts = [], $content = '', $tag = 'll_mark'): string {
    if ($content === null || (string) $content === '') {
        return '';
    }

    $tag = sanitize_key((string) $tag);
    $atts = shortcode_atts([
        'tone' => 'orange',
    ], is_array($atts) ? $atts : [], $tag);

    $tone = ll_tools_semantic_mark_resolve_tone($atts['tone'] ?? '', $tag);
    $inner_html = wp_kses(
        do_shortcode((string) $content),
        ll_tools_semantic_mark_inline_html_allowlist()
    );

    if ($inner_html === '') {
        return '';
    }

    ll_tools_enqueue_semantic_mark_assets();

    $classes = 'll-tools-mark ll-tools-mark--' . $tone;
    if ($tag === 'll_mark') {
        $classes .= ' ll-tools-mark--semantic';
    }

    return '<span class="' . esc_attr($classes) . '">' . $inner_html . '</span>';
}

add_shortcode('ll_mark', 'll_tools_semantic_mark_shortcode');
add_shortcode('color1', 'll_tools_semantic_mark_shortcode');
add_shortcode('color2', 'll_tools_semantic_mark_shortcode');
add_shortcode('color3', 'll_tools_semantic_mark_shortcode');
