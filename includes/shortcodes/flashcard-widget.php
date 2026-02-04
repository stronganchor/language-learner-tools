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
 * Resolve the effective wordset term IDs for the widget.
 * - If an explicit spec is provided, use only that (no silent fallback).
 * - If no spec is provided, fall back to the active/default wordset when available.
 *
 * @param string $wordset_spec Wordset slug|name|id
 * @param bool   $fallback_to_active When true, fall back to the active/default wordset if no spec is provided.
 * @return int[] Term IDs (unique, >0)
 */
function ll_flashcards_resolve_wordset_ids(string $wordset_spec = '', bool $fallback_to_active = true): array {
    $wordset_spec = trim($wordset_spec);
    $ids = [];

    if ($wordset_spec !== '' && function_exists('ll_raw_resolve_wordset_term_ids')) {
        $ids = ll_raw_resolve_wordset_term_ids($wordset_spec);
    } elseif ($fallback_to_active && $wordset_spec === '' && function_exists('ll_tools_get_active_wordset_id')) {
        $active = (int) ll_tools_get_active_wordset_id();
        if ($active > 0) {
            $ids = [$active];
        }
    }

    $ids = array_map('intval', (array) $ids);
    $ids = array_filter($ids, function ($id) { return $id > 0; });
    $ids = array_values(array_unique($ids));

    return $ids;
}

/**
 * Build the categories list used by the widget.
 * Returns [array $categories, bool $categoriesPreselected]
 */
function ll_flashcards_build_categories(?string $raw, bool $use_translations, array $wordset_ids = []): array {
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $all_terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
    ]);
    if (is_wp_error($all_terms)) $all_terms = [];

    if (!empty($wordset_ids) && function_exists('ll_collect_wc_ids_for_wordset_term_ids')) {
        $allowed_term_ids = ll_collect_wc_ids_for_wordset_term_ids($wordset_ids);
        $allowed_term_ids = array_map('intval', (array) $allowed_term_ids);
        $allowed_term_ids = array_filter($allowed_term_ids, function ($id) { return $id > 0; });
        if (!empty($allowed_term_ids)) {
            $allowed_lookup = array_flip($allowed_term_ids);
            $all_terms = array_values(array_filter($all_terms, function ($term) use ($allowed_lookup) {
                return isset($allowed_lookup[(int) $term->term_id]);
            }));
        } else {
            $all_terms = [];
        }
    }

    // Your existing helper builds the final structure: id, slug, name, translation, mode
    $all_processed = ll_process_categories($all_terms, $use_translations, $min_word_count, $wordset_ids);

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
function ll_flashcards_pick_initial_batch(array $categories, array $wordset_ids = [], bool $wordset_explicit = false): array {
    $words_data = [];
    $firstCategoryName = '';
    $selected_category_data = null;

    $wordset_ids = array_map('intval', $wordset_ids);
    $wordset_ids = array_filter($wordset_ids, function ($id) { return $id > 0; });
    $wordset_ids = array_values(array_unique($wordset_ids));
    if ($wordset_explicit && empty($wordset_ids)) {
        return [$selected_category_data, $firstCategoryName, $words_data];
    }

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
        $mode = $selected_category_data ? ($selected_category_data['option_type'] ?? $selected_category_data['mode']) : 'image';

        $words_data = ll_get_words_by_category($random, $mode, $wordset_ids, (array) $selected_category_data);
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

/** Return UI metadata (icon + labels) for each quiz mode */
function ll_flashcards_get_mode_ui_config(): array {
    $gender_svg = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">'
        . '<circle cx="7.5" cy="9" r="3.5" stroke="currentColor" stroke-width="2" />'
        . '<circle cx="16.5" cy="9" r="3.5" stroke="currentColor" stroke-width="2" />'
        . '<path d="M7.5 12.5v6.5M5 16.5h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />'
        . '<path d="M18.5 7.5l4-4M20.5 3.5h2M22.5 3.5v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />'
        . '</svg>';

    return [
        'practice' => [
            'icon'              => '❓',
            'className'         => 'practice-mode',
            'switchLabel'       => __('Switch to Practice Mode', 'll-tools-text-domain'),
            'resultsButtonText' => __('Practice Mode', 'll-tools-text-domain'),
        ],
        'learning' => [
            'icon'              => '🎓',
            'className'         => 'learning-mode',
            'switchLabel'       => __('Switch to Learning Mode', 'll-tools-text-domain'),
            'resultsButtonText' => __('Learning Mode', 'll-tools-text-domain'),
        ],
        'listening' => [
            'icon'              => '🎧',
            'className'         => 'listening-mode',
            'switchLabel'       => __('Switch to Listening Mode', 'll-tools-text-domain'),
            'resultsButtonText' => __('Replay Listening', 'll-tools-text-domain'),
        ],
        'gender' => [
            'icon'              => '',
            'svg'               => $gender_svg,
            'className'         => 'gender-mode',
            'switchLabel'       => __('Switch to Gender Mode', 'll-tools-text-domain'),
            'resultsButtonText' => __('Gender Mode', 'll-tools-text-domain'),
        ],
    ];
}

/** Enqueue styles/scripts and localize data. Returns the localized data array for per-instance scoping. */
function ll_flashcards_enqueue_and_localize(array $atts, array $categories, bool $preselected, array $initial_words, string $firstCategoryName): array {
    wp_enqueue_script('jquery');

    // ---- Robust defaults to avoid "Undefined array key" notices ----
    $mode      = isset($atts['mode']) ? sanitize_text_field((string) $atts['mode']) : 'random';
    $quiz_mode = isset($atts['quiz_mode']) ? sanitize_text_field((string) $atts['quiz_mode']) : 'practice';
    $wordset   = isset($atts['wordset']) ? sanitize_text_field((string) $atts['wordset']) : '';
    $wordset_fallback = isset($atts['wordset_fallback'])
        ? (bool) $atts['wordset_fallback']
        : ($wordset === '');

    ll_enqueue_asset_by_timestamp('/css/flashcard/base.css', 'll-tools-flashcard-style');
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-practice.css',
        'll-tools-flashcard-mode-practice',
        ['ll-tools-flashcard-style']
    );
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-learning.css',
        'll-tools-flashcard-mode-learning',
        ['ll-tools-flashcard-style']
    );
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-listening.css',
        'll-tools-flashcard-mode-listening',
        ['ll-tools-flashcard-style']
    );
    ll_enqueue_asset_by_timestamp(
        '/css/flashcard/mode-gender.css',
        'll-tools-flashcard-mode-gender',
        ['ll-tools-flashcard-style']
    );

    $shortcode_folder = '/js/flashcard-widget/';

    // Core & modules - enqueue in dependency order
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'audio.js',    'll-tools-flashcard-audio',   ['jquery'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'loader.js',   'll-tools-flashcard-loader',  ['jquery'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'options.js',  'll-tools-flashcard-options', ['jquery'], true);

    ll_enqueue_asset_by_timestamp($shortcode_folder . 'util.js',       'll-flc-util',        ['jquery'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'mode-config.js','ll-flc-mode-config', ['ll-flc-util'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'state.js',      'll-flc-state',       ['ll-flc-mode-config'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'dom.js',       'll-flc-dom',       ['ll-flc-state'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'audio-visualizer.js', 'll-flc-audio-visualizer', ['ll-flc-dom'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'effects.js',   'll-flc-effects',   ['ll-flc-dom'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'cards.js',     'll-flc-cards',     ['ll-flc-dom', 'll-flc-state', 'll-flc-effects'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'selection.js', 'll-flc-selection', ['ll-flc-cards'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'results.js',   'll-flc-results',   ['ll-flc-state', 'll-flc-dom', 'll-flc-effects'], true);

    // New mode-specific modules (loaded after selection.js)
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/learning.js',  'll-flc-mode-learning',  ['ll-flc-selection'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/practice.js',  'll-flc-mode-practice',  ['ll-flc-selection'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/listening.js', 'll-flc-mode-listening', ['ll-flc-selection', 'll-flc-audio-visualizer'], true);
    ll_enqueue_asset_by_timestamp($shortcode_folder . 'modes/gender.js',    'll-flc-mode-gender',    ['ll-flc-selection'], true);

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
            'll-flc-mode-listening',
            'll-flc-mode-gender'
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
    $wordset_ids = [];
    if (isset($atts['wordset_ids_for_popup'])) {
        $wordset_ids = array_map('intval', (array) $atts['wordset_ids_for_popup']);
    } else {
        $wordset_ids = ll_flashcards_resolve_wordset_ids($atts['wordset'], $wordset_fallback);
    }
    $wordset_ids = array_map('intval', (array) $wordset_ids);
    $wordset_ids = array_values(array_filter(array_unique($wordset_ids), function ($id) { return $id > 0; }));
    $gender_wordset_id = (count($wordset_ids) === 1) ? (int) $wordset_ids[0] : 0;
    $gender_enabled = ($gender_wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($gender_wordset_id)
        : false;
    $gender_options = ($gender_enabled && function_exists('ll_tools_wordset_get_gender_options'))
        ? ll_tools_wordset_get_gender_options($gender_wordset_id)
        : [];

    // Pull saved study prefs (stars/fast transitions) for logged-in users so the widget can reflect them outside the dashboard.
    $user_study_state = [
        'wordset_id'       => 0,
        'category_ids'     => [],
        'starred_word_ids' => [],
        'star_mode'        => 'normal',
        'fast_transitions' => false,
    ];
    if (is_user_logged_in() && function_exists('ll_tools_get_user_study_state')) {
        $user_study_state = ll_tools_get_user_study_state();
    }

    $is_embed = isset($atts['embed']) && strtolower((string) $atts['embed']) === 'true';
    $localized_data = [
        'mode'                  => $mode,
        'quiz_mode'             => $quiz_mode,
        'isEmbed'               => $is_embed,
        'debug'                 => (bool) apply_filters('ll_tools_flashcards_debug', false),
        'plugin_dir'            => LL_TOOLS_BASE_URL,
        'ajaxurl'               => admin_url('admin-ajax.php'),
        'ajaxNonce'             => is_user_logged_in() ? wp_create_nonce('ll_get_words_by_category') : '',
        'categories'            => $categories,
        'isUserLoggedIn'        => is_user_logged_in(),
        'categoriesPreselected' => $preselected,
        'firstCategoryData'     => $initial_words,
        'firstCategoryName'     => $firstCategoryName,
        'imageSize'             => get_option('ll_flashcard_image_size', 'small'),
        'maxOptionsOverride'    => get_option('ll_max_options_override', 9),
        'wordset'               => $wordset,
        'wordsetFallback'       => $wordset_fallback,
        'wordsetIds'            => $wordset_ids,
        'modeUi'                => ll_flashcards_get_mode_ui_config(),
        'starredWordIds'       => $user_study_state['starred_word_ids'],
        'starred_word_ids'     => $user_study_state['starred_word_ids'],
        'starMode'             => $user_study_state['star_mode'] ?? 'normal',
        'star_mode'            => $user_study_state['star_mode'] ?? 'normal',
        'fastTransitions'      => !empty($user_study_state['fast_transitions']),
        'fast_transitions'     => !empty($user_study_state['fast_transitions']),
        'userStudyState'       => $user_study_state,
        'userStudyNonce'       => is_user_logged_in() ? wp_create_nonce('ll_user_study') : '',
        'genderEnabled'        => $gender_enabled,
        'genderWordsetId'      => $gender_wordset_id,
        'genderOptions'        => $gender_options,
        'genderMinCount'       => (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ),
        'resultsCategoryPreviewLimit' => (int) apply_filters('ll_tools_results_category_preview_limit', 3),
    ];

    wp_localize_script('ll-tools-flashcard-options',         'llToolsFlashcardsData', $localized_data);
    wp_localize_script('ll-flc-mode-config',                 'llToolsFlashcardsData', $localized_data);
    wp_localize_script('ll-flc-main',                        'llToolsFlashcardsData', $localized_data);
    wp_localize_script('ll-tools-category-selection-script', 'llToolsFlashcardsData', $localized_data);

    // Localize translatable messages (must happen after scripts are enqueued)
    $messages = ll_flashcards_get_messages();
    wp_localize_script('ll-flc-results', 'llToolsFlashcardsMessages', $messages);
    wp_localize_script('ll-flc-main',    'llToolsFlashcardsMessages', $messages);

    return $localized_data;
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
        'category'  => '',
        'mode'      => 'random',
        'embed'     => 'false',
        'wordset'   => '',
        'wordset_fallback' => true,
        'quiz_mode' => 'practice',
    ], $atts);

    $atts['wordset'] = isset($atts['wordset']) ? sanitize_text_field((string) $atts['wordset']) : '';
    // If a wordset is explicitly provided, do NOT fall back to other wordsets.
    $atts['wordset_fallback'] = ($atts['wordset'] !== '')
        ? false
        : filter_var($atts['wordset_fallback'], FILTER_VALIDATE_BOOLEAN);
    $wordset_ids = ll_flashcards_resolve_wordset_ids($atts['wordset'], $atts['wordset_fallback']);
    $wordset_ids = array_map('intval', (array) $wordset_ids);
    $wordset_ids = array_values(array_filter(array_unique($wordset_ids), function ($id) { return $id > 0; }));

    $embed     = strtolower((string)$atts['embed']) === 'true';
    $quiz_font = (string) get_option('ll_quiz_font');

    // 1) translations on/off
    $use_translations = ll_flashcards_should_use_translations();

    // 2) categories list (+ whether they were preselected)
    [$categories, $preselected] = ll_flashcards_build_categories($atts['category'], $use_translations, $wordset_ids);

    // 3) initial words batch so the UI isn't empty - NOW WORDSET AWARE
    [$selected_category_data, $firstCategoryName, $words_data] = ll_flashcards_pick_initial_batch($categories, $wordset_ids, $atts['wordset'] !== '');

    // 4) label shown above play button
    $category_label_text = ll_flashcards_category_label($selected_category_data, $firstCategoryName, $embed);

    // 5) assets + localized data for JS - NOW INCLUDES WORDSET
    $localized_data = ll_flashcards_enqueue_and_localize($atts, $categories, $preselected, $words_data, $firstCategoryName);

    // 6) render the template (single source of truth for markup)
    if (!function_exists('ll_tools_render_template')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/template-loader.php';
    }

    ob_start();
    ll_tools_render_template('flashcard-widget-template.php', [
        'embed'               => $embed,
        'category_label_text' => $category_label_text,
        'quiz_font'           => $quiz_font,
        'mode_ui'             => ll_flashcards_get_mode_ui_config(),
        'wordset'             => $atts['wordset'],
        'wordset_fallback'    => $atts['wordset_fallback'],
        'll_config'           => $localized_data,
    ]);
    echo "<script>document.addEventListener('DOMContentLoaded',function(){var c=document.getElementById('ll-tools-flashcard-container');if(c){c.removeAttribute('style');}});</script>";
    return (string) ob_get_clean();
}

/**
 * Determines display mode by counts.
 */
function ll_determine_display_mode($categoryName, $min_word_count = LL_TOOLS_MIN_WORDS_PER_QUIZ, $wordset_ids = []) {
    $term = get_term_by('name', $categoryName, 'word-category');
    $config = $term && !is_wp_error($term) ? ll_tools_get_category_quiz_config($term) : ['prompt_type' => 'audio', 'option_type' => 'image'];

    $option_type = $config['option_type'] ?? 'image';
    $words_in_mode = ll_get_words_by_category($categoryName, $option_type, $wordset_ids, $config);
    if (count($words_in_mode) >= $min_word_count) {
        return $option_type;
    }

    // Try a text fallback for audio-based options
    if (in_array($option_type, ['audio', 'text_audio'], true)) {
        $fallback_config = $config;
        $fallback_config['option_type'] = 'text_translation';
        $text_words = ll_get_words_by_category($categoryName, 'text', $wordset_ids, $fallback_config);
        if (count($text_words) >= $min_word_count) {
            return 'text_translation';
        }
    }

    // Last resort: compare image/text availability to pick the better one
    $image_count = count(ll_get_words_by_category($categoryName, 'image', $wordset_ids, $config));
    $text_count  = count(ll_get_words_by_category($categoryName, 'text', $wordset_ids, array_merge($config, ['option_type' => 'text_translation'])));

    if ($image_count < $min_word_count && $text_count < $min_word_count) return null;
    if ($image_count < $min_word_count) return 'text';
    if ($text_count  < $min_word_count) return 'image';
    return ($image_count >= $text_count) ? 'image' : 'text';
}

/**
 * Processes categories (unchanged from your version).
 */
function ll_process_categories($categories, $use_translations, $min_word_count = LL_TOOLS_MIN_WORDS_PER_QUIZ, $wordset_ids = []) {
    $processed = [];
    $gender_wordset_id = (count((array) $wordset_ids) === 1) ? (int) $wordset_ids[0] : 0;
    $gender_enabled = ($gender_wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($gender_wordset_id)
        : false;
    $gender_options = ($gender_enabled && function_exists('ll_tools_wordset_get_gender_options'))
        ? ll_tools_wordset_get_gender_options($gender_wordset_id)
        : [];
    $gender_lookup = [];
    foreach ($gender_options as $option) {
        $key = strtolower(trim((string) $option));
        if (function_exists('ll_tools_wordset_strip_variation_selectors')) {
            $key = ll_tools_wordset_strip_variation_selectors($key);
        }
        if ($key !== '') {
            $gender_lookup[$key] = true;
        }
    }

    foreach ($categories as $category) {
        if (!ll_can_category_generate_quiz($category, $min_word_count, $wordset_ids)) continue;

        $config = ll_tools_get_category_quiz_config($category);
        $learning_supported = $config['learning_supported'];

        // Resolve the effective option type (fall back to text if the preferred audio-based mode has too few words)
        $option_type = $config['option_type'];
        $words_in_mode = ll_get_words_by_category($category->name, $option_type, $wordset_ids, $config);
        if (count($words_in_mode) < $min_word_count && in_array($option_type, ['audio', 'text_audio'], true)) {
            $fallback_config = $config;
            $fallback_config['option_type'] = 'text_translation';
            $fallback_words = ll_get_words_by_category($category->name, 'text', $wordset_ids, $fallback_config);
            if (count($fallback_words) >= $min_word_count) {
                $option_type = 'text_translation';
                $learning_supported = ($config['prompt_type'] === 'image') ? false : $learning_supported;
                $words_in_mode = $fallback_words;
            }
        }
        $config['option_type'] = $option_type;

        // Keep legacy name "mode" for frontend compatibility (now represents the option type)
        $mode = $option_type;

        $translation = $use_translations
            ? ( get_term_meta($category->term_id, 'term_translation', true) ?: $category->name )
            : $category->name;

        $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
        $requires_audio = function_exists('ll_tools_quiz_requires_audio')
            ? ll_tools_quiz_requires_audio(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
            : ($prompt_type === 'audio' || in_array($option_type, ['audio', 'text_audio'], true));
        $requires_image = ($prompt_type === 'image') || ($option_type === 'image');

        $gender_word_count = 0;
        if ($gender_enabled && !empty($words_in_mode)) {
            foreach ($words_in_mode as $word) {
                if (!is_array($word)) {
                    continue;
                }
                $pos = $word['part_of_speech'] ?? [];
                $pos = is_array($pos) ? $pos : [$pos];
                $pos = array_map('strtolower', array_map('strval', $pos));
                if (!in_array('noun', $pos, true)) {
                    continue;
                }
                $gender_raw = (string) ($word['grammatical_gender'] ?? '');
                $gender_label = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                    ? ll_tools_wordset_normalize_gender_value_for_options($gender_raw, $gender_options)
                    : trim($gender_raw);
                $gender = strtolower(trim($gender_label));
                if ($gender === '' || (empty($gender_lookup) || !isset($gender_lookup[$gender]))) {
                    continue;
                }
                if (($requires_image && empty($word['has_image'])) || ($requires_audio && empty($word['has_audio']))) {
                    continue;
                }
                $gender_word_count++;
            }
        }

        $processed[] = [
            'id'          => $category->term_id,
            'slug'        => $category->slug,
            'name'        => html_entity_decode($category->name, ENT_QUOTES, 'UTF-8'),
            'translation' => html_entity_decode($translation,     ENT_QUOTES, 'UTF-8'),
            'mode'        => $mode,
            'option_type' => $option_type,
            'prompt_type' => $config['prompt_type'],
            'learning_supported' => $learning_supported,
            'use_titles'  => $config['use_titles'],
            'word_count'  => count($words_in_mode),
            'gender_word_count' => $gender_word_count,
            'gender_supported' => ($gender_enabled && $gender_word_count >= $min_word_count),
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
    $wordset_fallback = isset($_POST['wordset_fallback']) ? (bool) $_POST['wordset_fallback'] : true;
    $prompt_type  = isset($_POST['prompt_type'])  ? sanitize_text_field($_POST['prompt_type'])  : '';
    $option_type  = isset($_POST['option_type'])  ? sanitize_text_field($_POST['option_type'])  : '';

    if (!$category) { wp_send_json_error('Invalid category.'); }

    $wordset_ids = ll_flashcards_resolve_wordset_ids($wordset_spec, $wordset_fallback);
    if ($wordset_spec !== '' && empty($wordset_ids)) {
        wp_send_json_success([]);
    }

    $term = get_term_by('name', $category, 'word-category');
    $meta_config = ($term && !is_wp_error($term)) ? ll_tools_get_category_quiz_config($term) : [];
    $base_config = [
        'prompt_type' => ll_tools_normalize_quiz_prompt_type($prompt_type ?: ($meta_config['prompt_type'] ?? 'audio')),
        'option_type' => ll_tools_normalize_quiz_option_type($option_type ?: $display_mode, !empty($meta_config['use_titles'])),
    ];
    if (!empty($meta_config)) {
        $base_config = array_merge($meta_config, $base_config);
    }

    wp_send_json_success(ll_get_words_by_category($category, $base_config['option_type'], $wordset_ids, $base_config));
}

function ll_tools_register_flashcard_widget_shortcode() {
    add_shortcode('flashcard_widget', 'll_tools_flashcard_widget');
}
add_action('init', 'll_tools_register_flashcard_widget_shortcode');
