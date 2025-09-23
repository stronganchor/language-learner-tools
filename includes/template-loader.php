<?php
// Minimal, safe template loader for LL Tools.
if (!defined('WPINC')) { die; }

/**
 * Absolute path to the plugin's templates directory.
 */
function ll_tools_templates_dir(): string {
    // e.g. /.../wp-content/plugins/language-learner-tools/templates/
    return trailingslashit(LL_TOOLS_BASE_PATH . 'templates');
}

/**
 * Locate a template by relative path.
 *
 * Search order:
 *   1) Child theme:  /wp-content/themes/<child>/ll-tools/<relative>
 *   2) Parent theme: /wp-content/themes/<parent>/ll-tools/<relative>
 *   3) Plugin:       <plugin>/templates/<relative>
 *
 * Filters:
 *   - ll_tools_template_theme_subdir (default: 'll-tools')
 *   - ll_tools_template_search_paths (array of candidates)
 *
 * @param string $relative  e.g. 'flashcard-widget-template.php' or 'quiz-pages.php' or 'flashcards/overlay.php'
 * @return string Absolute file path, or '' if not found.
 */
function ll_tools_locate_template(string $relative): string {
    // Basic hardening: prevent traversal & normalize slashes
    $relative = ltrim(str_replace(['\\', '..'], ['', ''], $relative), '/');

    $theme_subdir = trim(apply_filters('ll_tools_template_theme_subdir', 'll-tools'), '/');

    $candidates = [
        trailingslashit(get_stylesheet_directory()) . $theme_subdir . '/' . $relative, // child theme
        trailingslashit(get_template_directory())   . $theme_subdir . '/' . $relative, // parent theme
        ll_tools_templates_dir() . $relative,                                           // plugin fallback
    ];

    $candidates = apply_filters('ll_tools_template_search_paths', $candidates, $relative);

    foreach ($candidates as $file) {
        if (is_readable($file)) {
            return $file;
        }
    }

    do_action('ll_tools_missing_template', $relative, $candidates);
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[LL Tools] Missing template: ' . $relative);
    }
    return '';
}

/**
 * Render a template (echo). Variables are available as locals inside the template.
 * Uses an isolated scope so variables don’t leak.
 *
 * @param string $relative
 * @param array  $vars
 */
function ll_tools_render_template(string $relative, array $vars = []): void {
    $file = ll_tools_locate_template($relative);
    if ($file === '') return;

    // Isolated scope include
    (static function($__file, $__vars) {
        extract($__vars, EXTR_SKIP);
        include $__file;
    })($file, $vars);
}

/**
 * Capture the rendered template and return it as a string.
 * Handy if you ever need the HTML for a filter/email/API response.
 */
function ll_tools_capture_template(string $relative, array $vars = []): string {
    ob_start();
    ll_tools_render_template($relative, $vars);
    return (string) ob_get_clean();
}

/** Convenience: does this template exist (after override resolution)? */
function ll_tools_template_exists(string $relative): bool {
    return ll_tools_locate_template($relative) !== '';
}
