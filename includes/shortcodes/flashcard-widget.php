<?php
/**
 * [flashcard_widget] shortcode
 *
 * Thin controller + helpers + AJAX. Markup is in /templates/flashcard-widget-template.php
 */

if (!defined('WPINC')) { die; }

/** ---------------------------
 *  Helpers (small + focused)
 *  ---------------------------
 */

/** Normalize and decide if translations should be used */
function ll_flashcards_should_use_translations(): bool {
    $enable_translation = (int) get_option('ll_enable_category_translation', 0);
    $target_language    = strtolower((string) get_option('ll_translation_language', 'en'));
    $site_language      = strtolower(get_locale());
    return $enable_translation && strpos($site_language, $target_language) === 0;
}

/**
 * Build the categories list used by the widget.
 * Returns [array $categories, bool $categoriesPreselected]
 */
function ll_flashcards_build_categories(?string $raw, bool $use_translations): array {
    $all_terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);
    if (is_wp_error($all_terms)) $all_terms = [];

    // Your existing helper builds the final structure: id, slug, name, translation, mode
    $all_processed = ll_process_categories($all_terms, $use_translations);

    // No specific categories provided → offer all; not preselected.
    if (empty($raw)) {
        return [$all_processed, false];
    }

    // Specific categories requested → pick matching ones; preselected = true.
    $wanted = array_map('trim', explode(',', (string) $raw));
    $out = [];
    foreach ($wanted as $w) {
        $w_lc = strtolower($w);
        $found = false;
        foreach ($all_processed as $cat) {
            if (
                strtolower((string) $cat['name']) === $w_lc ||
                (string) $cat['id'] === $w ||
                strtolower((string) $cat['slug']) === $w_lc
            ) {
                $out[] = $cat;
                $found = true;
                break;
            }
        }
        if (!$found) error_log("LL Tools: Category '$w' not found.");
    }
    return [$out, true];
}

/**
 * Pick an initial category and fetch its words (so first render isn’t empty).
 * Returns [$selected_category_data, $firstCategoryName, $words_data]
 */
function ll_flashcards_pick_initial_batch(array $categories, string $wordset_spec = ''): array {
    $words_data = [];
    $firstCategoryName = '';
    $selected_category_data = null;

    // Try random categories until we have at least a few words
    $names = array_column($categories, 'name');
    $tries = $names;
    while (!empty($tries) && (empty($words_data) || count($words_data) < 3)) {
        $random = $tries[array_rand($tries)];
        $tries  = array_diff($tries, [$random]);

        $selected_category_data = null;
        foreach ($categories as $cat) {
            if ($cat['name'] === $random) { $selected_category_data = $cat; break; }
        }
        $mode = $selected_category_data ? $selected_category_data['mode'] : 'image';

        // Determine wordset_id from spec
        $wordset_id = null;
        if (!empty($wordset_spec)) {
            $ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
            $wordset_id = !empty($ids) ? $ids[0] : null;
        }

        $words_data = ll_get_words_by_category($random, $mode, $wordset_id);
        $firstCategoryName = $random;
    }

    return [$selected_category_data, $firstCategoryName, $words_data];
}

/** Compute the category label shown above the play button (non-embed only) */
function ll_flashcards_category_label(?array $selected_category_data, string $firstCategoryName, bool $embed): string {
    if ($embed) return '';
    if (!empty($selected_category_data['id'])) {
        return (string) ll_tools_get_category_display_name($selected_category_data['id']);
    }
    if ($firstCategoryName !== '') {
        return (string) ll_tools_get_category_display_name($firstCategoryName);
    }
    return '';
}

/** Prepare translatable UI messages for JavaScript */
function ll_flashcards_get_messages(): array {
    return [
        // Results page messages
        'learningComplete'        => __('Learning Complete!', 'll-tools-text-domain'),
        'learningCompleteMessage' => __('✓', 'll-tools-text-domain'),
        'listeningComplete'       => __('Listening Complete', 'll-tools-text-domain'),
        'perfect'                 => __('Perfect!', 'll-tools-text-domain'),
        'goodJob'                 => __('Good job!', 'll-tools-text-domain'),
        'keepPracticingTitle'     => __('Keep practicing!', 'll-tools-text-domain'),
        'keepPracticingMessage'   => __("You're on the right track...", 'll-tools-text-domain'),
        'categoriesLabel'         => __('Categories', 'll-tools-text-domain'),

        // Error messages
        'loadingError'            => __('Loading Error', 'll-tools-text-domain'),
        'somethingWentWrong'      => __('Something went wrong', 'll-tools-text-domain'),
        'noWordsFound'            => __('No words could be loaded for this quiz. Please check that:', 'll-tools-text-domain'),
        'checkCategoryExists'     => __('The category exists and has words', 'll-tools-text-domain'),
        'checkWordsAssigned'      => __('Words are properly assigned to the category', 'll-tools-text-domain'),
        'checkWordsetFilter'      => __('If using wordsets, the wordset contains words for this category', 'll-tools-text-domain'),
    ];
}

/** Enqueue styles/scripts and localize data */
function ll_flashcards_enqueue_and_localize(array $atts, array $categories, bool $preselected, array $initial_words, string $firstCategoryName): void {
    wp_enqueue_script('jquery');

    // ---- Robust defaults to avoid "Undefined array key" notices ----
    $mode      = isset($atts['mode']) ? sanitize_text_field((string) $atts['mode']) : 'random';
    $quiz_mode = isset($atts['quiz_mode']) ? sanitize_text_field((string) $atts['quiz_mode']) : 'practice';
    $wordset   = isset($atts['wordset']) ? sanitize_text_field((string) $atts['wordset']) : '';

    ll_enqueue_asset_by_timestamp('/css/flashcard-style.css', 'll-tools-flashcard-style');

    $shortcode_folder = '/js/flashcard-widget/';

    // Core & modules - enqueue in dependency order
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'audio.js',    'll-tools-flashcard-audio',   ['jquery'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'loader.js',   'll-tools-flashcard-loader',  ['jquery'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'options.js',  'll-tools-flashcard-options', ['jquery'], true);

    ll_enqueue_asset_by_timestamp($shortcode_folder . 'util.js',      'll-flc-util',      ['jquery'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'state.js',     'll-flc-state',     ['ll-flc-util'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'dom.js',       'll-flc-dom',       ['ll-flc-state'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'effects.js',   'll-flc-effects',   ['ll-flc-dom'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'cards.js',     'll-flc-cards',     ['ll-flc-dom', 'll-flc-state', 'll-flc-effects'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'selection.js', 'll-flc-selection', ['ll-flc-cards'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'results.js',   'll-flc-results',   ['ll-flc-state', 'll-flc-dom', 'll-flc-effects'], true);

    // New mode-specific modules (loaded after selection.js)
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/learning.js',  'll-flc-mode-learning',  ['ll-flc-selection'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/practice.js',  'll-flc-mode-practice',  ['ll-flc-selection'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/listening.js', 'll-flc-mode-listening', ['ll-flc-selection'], true);

    // Main orchestrator depends on the mode modules as well
    ll_enqueue_asset_by_timestamp(
        $shortcode_folder . 'main.js',
        'll-flc-main',
        [
            'll-flc-selection',
            'll-flc-results',
            'll-tools-flashcard-audio',
            'll-tools-flashcard-loader',
            'll-tools-flashcard-options',
            'll-flc-mode-learning',
            'll-flc-mode-practice',
            'll-flc-mode-listening'
        ],
        true
    );

    // Category selector script remains the same
    ll_enqueue_asset_by_timestamp(
        $shortcode_folder . 'category-selection.js',
        'll-tools-category-selection-script',
        ['jquery', 'll-flc-main'],
        true
    );

    ll_enqueue_asset_by_timestamp($shortcode_folder . 'category-selection.js', 'll-tools-category-selection-script', ['jquery','ll-flc-main'], true);

    // Stop parallel audio playback when the grid is present
    wp_add_inline_script('jquery', <<<JS
      jQuery(function($){
        var audios = $("#word-grid audio");
        audios.on("play", function(){
          var cur = this;
          audios.each(function(){ if (this !== cur) this.pause(); });
        });
      });
    JS);

    // Data: print early on 'options' and again on 'main' (belt & suspenders)
    $localized_data = [
        'mode'                  => $mode,
        'quiz_mode'             => $quiz_mode,
        'plugin_dir'            => LL_TOOLS_BASE_URL,
        'ajaxurl'               => admin_url('admin-ajax.php'),
        'categories'            => $categories,
        'isUserLoggedIn'        => is_user_logged_in(),
        'categoriesPreselected' => $preselected,
        'firstCategoryData'     => $initial_words,
        'firstCategoryName'     => $firstCategoryName,
        'imageSize'             => get_option('ll_flashcard_image_size', 'small'),
        'maxOptionsOverride'    => get_option('ll_max_options_override', 9),
        'wordset'               => $wordset,
    ];

    wp_localize_script('ll-tools-flashcard-options',         'llToolsFlashcardsData', $localized_data);
    wp_localize_script('ll-flc-main',                        'llToolsFlashcardsData', $localized_data);
    wp_localize_script('ll-tools-category-selection-script', 'llToolsFlashcardsData', $localized_data);

    // Localize translatable messages (must happen after scripts are enqueued)
    $messages = ll_flashcards_get_messages();
    wp_localize_script('ll-flc-results', 'llToolsFlashcardsMessages', $messages);
    wp_localize_script('ll-flc-main',    'llToolsFlashcardsMessages', $messages);
}

/** ---------------------------
 *  Shortcode Controller
 *  ---------------------------
 */

/**
 * [flashcard_widget] handler
 * @param array $atts
 * @return string
 */
function ll_tools_flashcard_widget($atts) {
    $atts = shortcode_atts([
        'category' => '',
        'mode'     => 'random',
        'embed'    => 'false',
        'wordset'  => '',
        'quiz_mode' => 'practice',
    ], $atts);

    $embed     = strtolower((string)$atts['embed']) === 'true';
    $quiz_font = (string) get_option('ll_quiz_font');

    // 1) translations on/off
    $use_translations = ll_flashcards_should_use_translations();

    // 2) categories list (+ whether they were preselected)
    [$categories, $preselected] = ll_flashcards_build_categories($atts['category'], $use_translations);

    // 3) initial words batch so the UI isn't empty - NOW WORDSET AWARE
    [$selected_category_data, $firstCategoryName, $words_data] = ll_flashcards_pick_initial_batch($categories, $atts['wordset']);

    // 4) label shown above play button
    $category_label_text = ll_flashcards_category_label($selected_category_data, $firstCategoryName, $embed);

    // 5) assets + localized data for JS - NOW INCLUDES WORDSET
    ll_flashcards_enqueue_and_localize($atts, $categories, $preselected, $words_data, $firstCategoryName);

    // 6) render the template (single source of truth for markup)
    if (!function_exists('ll_tools_render_template')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/template-loader.php';
    }

    ob_start();
    ll_tools_render_template('flashcard-widget-template.php', [
        'embed'               => $embed,
        'category_label_text' => $category_label_text,
        'quiz_font'           => $quiz_font,
    ]);
    echo "<script>document.addEventListener('DOMContentLoaded',function(){var c=document.getElementById('ll-tools-flashcard-container');if(c){c.removeAttribute('style');}});</script>";
    return (string) ob_get_clean();
}

/**
 * Determines display mode by counts.
 */
function ll_determine_display_mode($categoryName, $min_word_count = LL_TOOLS_MIN_WORDS_PER_QUIZ) {
    $image_count = count(ll_get_words_by_category($categoryName, 'image'));
    $text_count  = count(ll_get_words_by_category($categoryName, 'text'));

    if ($image_count < $min_word_count && $text_count < $min_word_count) return null;
    if ($image_count < $min_word_count) return 'text';
    if ($text_count  < $min_word_count) return 'image';
    return ($image_count >= $text_count) ? 'image' : 'text';
}

/**
 * Processes categories (unchanged from your version).
 */
function ll_process_categories($categories, $use_translations, $min_word_count = LL_TOOLS_MIN_WORDS_PER_QUIZ) {
    $processed = [];
    foreach ($categories as $category) {
        if (!ll_can_category_generate_quiz($category, $min_word_count)) continue;

        $use_titles = get_term_meta($category->term_id, 'use_word_titles_for_audio', true) === '1';
        $mode = $use_titles ? 'text' : ll_determine_display_mode($category->name, $min_word_count);

        $translation = $use_translations
            ? ( get_term_meta($category->term_id, 'term_translation', true) ?: $category->name )
            : $category->name;

        $processed[] = [
            'id'          => $category->term_id,
            'slug'        => $category->slug,
            'name'        => html_entity_decode($category->name, ENT_QUOTES, 'UTF-8'),
            'translation' => html_entity_decode($translation,     ENT_QUOTES, 'UTF-8'),
            'mode'        => $mode,
        ];
    }
    return $processed;
}

/** ---------------------------
 *  AJAX + Shortcode registration
 *  ---------------------------
 */

add_action('wp_ajax_ll_get_words_by_category',        'll_get_words_by_category_ajax');
add_action('wp_ajax_nopriv_ll_get_words_by_category', 'll_get_words_by_category_ajax');
function ll_get_words_by_category_ajax() {
    $category     = isset($_POST['category'])     ? sanitize_text_field($_POST['category'])     : '';
    $display_mode = isset($_POST['display_mode']) ? sanitize_text_field($_POST['display_mode']) : 'image';
    $wordset_spec = isset($_POST['wordset'])      ? sanitize_text_field($_POST['wordset'])      : '';

    if (!$category) { wp_send_json_error('Invalid category.'); }

    // Resolve wordset to ID - use explicit ID if provided, otherwise get active wordset
    $wordset_id = null;
    if (!empty($wordset_spec)) {
        $ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
        // IMPORTANT: Use the first ID if found, otherwise fall back to active wordset
        $wordset_id = !empty($ids) ? $ids[0] : ll_tools_get_active_wordset_id();
    } else {
        // No wordset specified - use the active wordset for the site
        $wordset_id = ll_tools_get_active_wordset_id();
    }

    wp_send_json_success(ll_get_words_by_category($category, $display_mode, $wordset_id));
}

function ll_tools_register_flashcard_widget_shortcode() {
    add_shortcode('flashcard_widget', 'll_tools_flashcard_widget');
}
add_action('init', 'll_tools_register_flashcard_widget_shortcode');
