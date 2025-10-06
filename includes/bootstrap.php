<?php // /includes/bootstrap.php
if (!defined('WPINC')) { die; }

// Include asset management
require_once(__DIR__ . '/assets.php');

// Include template loader
require_once __DIR__ . '/template-loader.php';

// Include custom post types
require_once(__DIR__ . '/post-types/words-post-type.php');
require_once(__DIR__ . '/post-types/word-image-post-type.php');
require_once(__DIR__ . '/post-types/word-audio-post-type.php');

// Include taxonomies
require_once(__DIR__ . '/taxonomies/word-category-taxonomy.php');
require_once(__DIR__ . '/taxonomies/wordset-taxonomy.php');
require_once(__DIR__ . '/taxonomies/language-taxonomy.php');
require_once(__DIR__ . '/taxonomies/part-of-speech-taxonomy.php');
require_once(__DIR__ . '/taxonomies/recording-type-taxonomy.php');

// Include user roles
require_once(__DIR__ . '/user-roles/wordset-manager.php');
require_once(__DIR__ . '/user-roles/ll-tools-editor.php');

// Include admin functionality
require_once(__DIR__ . '/admin/uploads/audio-upload-form.php');
require_once(__DIR__ . '/admin/uploads/image-upload-form.php');
require_once(__DIR__ . '/admin/manage-wordsets.php');
require_once(__DIR__ . '/admin/missing-audio-admin-page.php');
require_once(__DIR__ . '/admin/audio-image-matcher.php'); 
require_once(__DIR__ . '/admin/settings.php');
require_once(__DIR__ . '/admin/audio-processor-admin.php');
require_once(__DIR__ . '/admin/audio-review-page.php');
require_once(__DIR__ . '/admin/recording-types-admin.php');

// Include API integrations
require_once(__DIR__ . '/admin/api/deepl-api.php');

// Include pages
require_once(__DIR__ . '/pages/quiz-pages.php');
if (function_exists('ll_tools_register_autopage_activation') && defined('LL_TOOLS_MAIN_FILE')) {
    ll_tools_register_autopage_activation(LL_TOOLS_MAIN_FILE);
}
// Note: embed-page.php is loaded via template_include filter, not require

// Include shortcodes
require_once(__DIR__ . '/shortcodes/flashcard-widget.php');
require_once(__DIR__ . '/shortcodes/word-audio-shortcode.php');
require_once(__DIR__ . '/shortcodes/word-grid-shortcode.php');
require_once(__DIR__ . '/shortcodes/image-copyright-grid-shortcode.php');
require_once(__DIR__ . '/shortcodes/quiz-pages-shortcodes.php');
require_once(__DIR__ . '/shortcodes/audio-recording-shortcode.php');
require_once(__DIR__ . '/shortcodes/language-switcher-shortcode.php');

// Include the plugin update checker
require_once LL_TOOLS_BASE_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

// Include other utility files
require_once(__DIR__ . '/i18n/language-switcher.php');
