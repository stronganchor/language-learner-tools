<?php
// /includes/shortcodes/quiz-pages-shortcodes.php
/**
 * Shortcodes to list auto-generated quiz pages:
 *  - [quiz_pages_grid]
 *  - [quiz_pages_dropdown]
 *
 * A "quiz page" is any WP Page created by the plugin for a word-category
 * and marked with meta key _ll_tools_word_category_id.
 */

if (!defined('WPINC')) { die; }

/** ------------------------------------------------------------------
 * Shared helpers
 * ------------------------------------------------------------------ */

/**
 * Resolve a term identifier (id | slug | name) to a numeric term_id.
 *
 * @param string       $taxonomy  e.g. 'wordset'
 * @param string|int   $value     id, slug, or name
 * @return int|null
 */
function ll_tools_resolve_term_id_by_slug_name_or_id($taxonomy, $value) {
    if (is_numeric($value)) {
        $t = get_term((int)$value, $taxonomy);
        if ($t && !is_wp_error($t)) return (int)$t->term_id;
    }
    if (is_string($value) && $value !== '') {
        // try slug
        $t = get_term_by('slug', sanitize_title($value), $taxonomy);
        if ($t && !is_wp_error($t)) return (int)$t->term_id;
        // try name
        $t = get_term_by('name', $value, $taxonomy);
        if ($t && !is_wp_error($t)) return (int)$t->term_id;
    }
    return null;
}

/**
 * Resolve a wordset spec (slug/name/id) to one or more raw term_ids
 * directly from the DB, ignoring language filters.
 */
function ll_raw_resolve_wordset_term_ids($spec) {
    global $wpdb;

    if (is_numeric($spec)) {
        $tid = (int) $spec;
        if ($tid > 0) return [$tid];
        return [];
    }

    $spec = trim((string) $spec);
    if ($spec === '') return [];

    // 1) Exact slug match(es)
    $sql_slug = $wpdb->prepare("
        SELECT tt.term_id
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
        WHERE tt.taxonomy = %s AND t.slug = %s
    ", 'wordset', $spec);
    $ids = array_map('intval', (array) $wpdb->get_col($sql_slug));

    // 2) If nothing found by slug, try exact name match
    if (empty($ids)) {
        $sql_name = $wpdb->prepare("
            SELECT tt.term_id
            FROM {$wpdb->term_taxonomy} tt
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s AND t.name = %s
        ", 'wordset', $spec);
        $ids = array_map('intval', (array) $wpdb->get_col($sql_name));
    }

    return array_values(array_unique(array_filter($ids, function($v){ return $v > 0; })));
}

/**
 * Collect all word-category IDs used by at least MINIMUM published "words" posts
 * that belong to ANY of the provided wordset term IDs. Uses direct SQL.
 * Includes ancestor categories so parents appear.
 */
function ll_collect_wc_ids_for_wordset_term_ids(array $wordset_term_ids) {
    global $wpdb;

    $wordset_term_ids = array_values(array_unique(array_map('intval', $wordset_term_ids)));
    $wordset_term_ids = array_filter($wordset_term_ids, function($v){ return $v > 0; });
    if (empty($wordset_term_ids)) return [];

    // Simple in-request cache
    $cache_key = 'll_wcids_ws_' . md5(implode(',', $wordset_term_ids));
    $cached = wp_cache_get($cache_key, 'll_tools');
    if ($cached !== false) return $cached;

    $placeholders = implode(',', array_fill(0, count($wordset_term_ids), '%d'));

    // Get minimum word count (default 5)
    $min_words = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);

    // First, get word counts per category for this wordset
    $sql = $wpdb->prepare("
        SELECT tt_cat.term_id, COUNT(DISTINCT p.ID) as word_count
        FROM {$wpdb->posts}                p
        INNER JOIN {$wpdb->term_relationships} tr_ws  ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy}      tt_ws  ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy}      tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type   = %s
          AND p.post_status = %s
          AND tt_ws.taxonomy  = %s
          AND tt_ws.term_id  IN ($placeholders)
          AND tt_cat.taxonomy = %s
        GROUP BY tt_cat.term_id
        HAVING word_count >= %d
    ", array_merge(['words','publish','wordset'], $wordset_term_ids, ['word-category', $min_words]));

    $cat_ids = array_map('intval', (array) $wpdb->get_col($sql));
    if (empty($cat_ids)) {
        wp_cache_set($cache_key, [], 'll_tools', HOUR_IN_SECONDS);
        return [];
    }

    // Include ancestors so parents appear
    $with_anc = [];
    foreach ($cat_ids as $cid) {
        $with_anc[$cid] = true;
        foreach (get_ancestors($cid, 'word-category', 'taxonomy') as $aid) {
            $with_anc[(int) $aid] = true;
        }
    }
    $result = array_values(array_map('intval', array_keys($with_anc)));
    wp_cache_set($cache_key, $result, 'll_tools', HOUR_IN_SECONDS);
    return $result;
}

/**
 * Fetch all published quiz pages and return display data.
 * Optional filter: $opts['wordset'] accepts slug/name/id of a WORDSET term.
 * This version is DB-driven for the filter path so guest/admin see identical results.
 */
function ll_get_all_quiz_pages_data($opts = []) {
    // Load all quiz pages (public pages with a word-category meta)
    $pages = get_posts([
        'post_type'        => 'page',
        'post_status'      => 'publish',
        'has_password'     => false,
        'no_found_rows'    => true,
        'suppress_filters' => true,
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'meta_key'         => '_ll_tools_word_category_id',
        'orderby'          => 'ID',  // Ensure consistent ordering for deduplication
        'order'            => 'ASC',
    ]);
    if (empty($pages)) return [];

    $allowed_term_ids = null;

    $ws_ids = [];
    $filtered_wordset_id = 0;
    if (!empty($opts['wordset'])) {
        $ws_ids = ll_raw_resolve_wordset_term_ids($opts['wordset']);
        if (empty($ws_ids)) return []; // nothing by that slug/name/id
        $allowed_term_ids = ll_collect_wc_ids_for_wordset_term_ids($ws_ids);
        if (empty($allowed_term_ids)) return []; // no categories used by that wordset
        $filtered_wordset_id = (int) ($ws_ids[0] ?? 0);
    }

    $enable_translation = (int) get_option('ll_enable_category_translation', 0);
    $items = [];
    $gender_config_cache = [];

    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);

    // Build the allowed categories list using the same helper as the widget for consistency
    $allowed_category_ids = [];
    $category_meta_map = [];
    $use_translations = $enable_translation && (strpos(strtolower(get_locale()), strtolower(get_option('ll_translation_language', 'en'))) === 0);
    if (function_exists('ll_flashcards_build_categories')) {
        [$processed] = ll_flashcards_build_categories('', $use_translations, $ws_ids);
        foreach ($processed as $cat) {
            $cid = isset($cat['id']) ? (int) $cat['id'] : 0;
            if ($cid > 0) {
                $allowed_category_ids[$cid] = true;
                $category_meta_map[$cid] = $cat;
            }
        }
    }

    foreach ($pages as $post_id) {
        $term_id = (int) get_post_meta($post_id, '_ll_tools_word_category_id', true);
        if ($term_id <= 0) continue;

        if (is_array($allowed_term_ids) && !in_array($term_id, $allowed_term_ids, true)) {
            continue;
        }

        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) continue;

        if (!empty($allowed_category_ids) && !isset($allowed_category_ids[$term_id])) {
            continue;
        }
        // Eligibility should match flashcard widget: use provided wordset scope; otherwise consider all wordsets
        $category_wordset_ids = !empty($ws_ids) ? $ws_ids : [];
        if (!ll_can_category_generate_quiz($term, $min_word_count, $category_wordset_ids)) {
            continue;
        }
        $config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($term)
            : ['prompt_type' => 'audio', 'option_type' => 'image', 'learning_supported' => true, 'use_titles' => false];
        $option_type = $config['option_type'] ?? 'image';
        $prompt_type = $config['prompt_type'] ?? 'audio';

        $name        = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
        $translation = '';
        if ($enable_translation) {
            $t = get_term_meta($term_id, 'term_translation', true);
            if (!empty($t)) $translation = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }

        // Determine wordset slug / id for this specific item (do NOT leak values across items)
        $wordset_slug = '';
        $wordset_id_for_item = 0;
        if (!empty($opts['wordset'])) {
            // If filtered by wordset, use that slug
            $wordset_slug = sanitize_text_field($opts['wordset']);
            $wordset_id_for_item = $filtered_wordset_id;
        } else {
            // No filter: select default wordset for this category
            $default_ws_id = ll_get_default_wordset_id_for_category($name, $min_word_count);
            // Ensure the chosen default wordset can actually generate a playable quiz for this category.
            // If not, fall back to "no wordset filter" (use words across all wordsets).
            if ($default_ws_id > 0 && function_exists('ll_can_category_generate_quiz')) {
                if (!ll_can_category_generate_quiz($term, $min_word_count, [$default_ws_id])) {
                    $default_ws_id = 0;
                }
            }
            if ($default_ws_id > 0) {
                $default_term = get_term($default_ws_id, 'wordset');
                if ($default_term && !is_wp_error($default_term)) {
                    $wordset_slug = $default_term->slug;
                    $wordset_id_for_item = (int) $default_ws_id;
                    $category_wordset_ids = [$default_ws_id];
                }
            }
        }

        $gender_enabled = false;
        $gender_options = [];
        $gender_visual_config = [];
        $gender_supported = false;
        if ($wordset_id_for_item > 0 && function_exists('ll_tools_wordset_has_grammatical_gender')) {
            if (!isset($gender_config_cache[$wordset_id_for_item])) {
                $enabled = ll_tools_wordset_has_grammatical_gender($wordset_id_for_item);
                $options = ($enabled && function_exists('ll_tools_wordset_get_gender_options'))
                    ? ll_tools_wordset_get_gender_options($wordset_id_for_item)
                    : [];
                $visual_config = ($enabled && function_exists('ll_tools_wordset_get_gender_visual_config'))
                    ? ll_tools_wordset_get_gender_visual_config($wordset_id_for_item)
                    : [];
                $options = array_values(array_filter(array_map('strval', (array) $options), function ($val) {
                    return $val !== '';
                }));
                $support_map = [];
                if ($enabled && function_exists('ll_flashcards_build_categories')) {
                    [$ws_categories] = ll_flashcards_build_categories('', $use_translations, [$wordset_id_for_item]);
                    foreach ($ws_categories as $cat_meta) {
                        $cid = isset($cat_meta['id']) ? (int) $cat_meta['id'] : 0;
                        if ($cid > 0) {
                            $support_map[$cid] = !empty($cat_meta['gender_supported']);
                        }
                    }
                }
                $gender_config_cache[$wordset_id_for_item] = [
                    'enabled' => $enabled,
                    'options' => $options,
                    'visual_config' => $visual_config,
                    'support_map' => $support_map,
                ];
            }
            $cached = $gender_config_cache[$wordset_id_for_item];
            $gender_enabled = !empty($cached['enabled']);
            $gender_options = $cached['options'];
            $gender_visual_config = is_array($cached['visual_config'] ?? null) ? $cached['visual_config'] : [];
            $gender_supported = !empty($cached['support_map'][$term_id]);
        }

        $items[] = [
            'post_id'      => $post_id,
            'permalink'    => get_permalink($post_id),
            'slug'         => $term->slug,
            'term_id'      => $term_id,
            'name'         => $name,
            'translation'  => $translation,
            'display_name' => ($translation !== '' ? $translation : $name),
            'wordset_slug' => $wordset_slug,  // Added key
            'wordset_id'   => $wordset_id_for_item,
            'display_mode' => $option_type,
            'option_type'  => $option_type,
            'prompt_type'  => $prompt_type,
            'learning_supported' => $config['learning_supported'] ?? true,
            'gender_enabled' => $gender_enabled,
            'gender_options' => $gender_options,
            'gender_visual_config' => $gender_visual_config,
            'gender_supported' => $gender_supported,
        ];
    }

    usort($items, function ($a, $b) {
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
        }
        return strnatcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
    });

    return $items;
}

/**
 * Finds the wordset ID with the earliest creation date (lowest term_id) that has enough published words for a category.
 * @param string $category_name
 * @param int $min_word_count Minimum words required
 * @return int Wordset ID or 0 if none found
 */
function ll_get_default_wordset_id_for_category(string $category_name, int $min_word_count = 5): int {
    global $wpdb;
    $cat_term = get_term_by('name', $category_name, 'word-category');
    if (!$cat_term) return 0;
    $cat_id = (int)$cat_term->term_id;

    // Get all wordset IDs ordered by term_id (assuming lower IDs are older)
    $wordsets = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'term_id',
        'order' => 'ASC',
        'fields' => 'ids',
    ]);
    if (empty($wordsets) || is_wp_error($wordsets)) return 0;

    foreach ($wordsets as $ws_id) {
        $count = (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
            INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
            WHERE p.post_type = 'words'
              AND p.post_status = 'publish'
              AND tt_cat.term_id = %d
              AND tt_ws.term_id = %d
              AND tt_cat.taxonomy = 'word-category'
              AND tt_ws.taxonomy = 'wordset'
        ", $cat_id, $ws_id));
        if ($count >= $min_word_count) return (int)$ws_id;
    }
    return 0;
}

/**
 * Ensures the flashcard overlay shell exists and assets are enqueued.
 * Called only when [quiz_pages_grid popup="yes"] is used.
 *
 * @param string $wordset_spec Optional wordset filter (slug|name|id) to align popup categories/words.
 */
function ll_qpg_bootstrap_flashcards_for_grid($wordset_spec = '') {
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $target_language    = strtolower(get_option('ll_translation_language', 'en'));
    $site_language      = strtolower(get_locale());
    $use_translations   = $enable_translation && strpos($site_language, $target_language) === 0;

    $wordset_spec = sanitize_text_field((string) $wordset_spec);
    $wordset_ids  = function_exists('ll_flashcards_resolve_wordset_ids')
        ? ll_flashcards_resolve_wordset_ids($wordset_spec, false)
        : [];
    $wordset_ids = array_map('intval', (array) $wordset_ids);
    $wordset_ids = array_values(array_filter(array_unique($wordset_ids), function ($id) { return $id > 0; }));

    if (function_exists('ll_flashcards_build_categories')) {
        [$categories] = ll_flashcards_build_categories('', $use_translations, $wordset_ids);
    } else {
        $all_terms = get_terms(['taxonomy' => 'word-category', 'hide_empty' => false]);
        if (is_wp_error($all_terms)) $all_terms = [];
        $categories = array_map(function($t){
            return [
                'id'          => $t->term_id,
                'slug'        => $t->slug,
                'name'        => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'translation' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'mode'        => 'image',
                'option_type' => 'image',
                'prompt_type' => 'audio',
            ];
        }, $all_terms);
    }

    $atts = ['mode' => 'random', 'wordset' => $wordset_spec, 'wordset_fallback' => false];
    $localized_wordset_ids = $wordset_ids;
    ll_flashcards_enqueue_and_localize(array_merge($atts, ['wordset_ids_for_popup' => $localized_wordset_ids]), $categories, false, [], '');

    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once');

    echo '<style id="ll-qpg-popup-zfix">
      body.ll-qpg-popup-active #ll-tools-flashcard-container,
      body.ll-qpg-popup-active #ll-tools-flashcard-popup,
      body.ll-qpg-popup-active #ll-tools-flashcard-quiz-popup{position:fixed;inset:0;z-index:999999}
      body.ll-qpg-popup-active #ll-tools-flashcard-content{flex:1 1 auto;min-height:0;height:auto}
    </style>';

    ?>
    <script>
    (function(){
      if (window.__LL_QPG_DELEGATED_BOUND) { return; }
      window.__LL_QPG_DELEGATED_BOUND = true;
      function openFromAnchor(a){
            var cat = a.getAttribute('data-category') || '';
            var wordsetId = a.getAttribute('data-wordset-id') || '';
            var wordsetSlug = a.getAttribute('data-wordset') || '';
            var mode = a.getAttribute('data-mode') || 'practice';
            var displayModeHint = a.getAttribute('data-display-mode') || '';
            var promptTypeHint = a.getAttribute('data-prompt-type') || '';
            var optionTypeHint = a.getAttribute('data-option-type') || '';
            var genderEnabledAttr = a.getAttribute('data-gender-enabled');
            var genderSupportedAttr = a.getAttribute('data-gender-supported');
            var genderOptionsAttr = a.getAttribute('data-gender-options') || '';
            var genderVisualConfigAttr = a.getAttribute('data-gender-visual-config') || '';
            if (!cat) return;

            var genderEnabled = (genderEnabledAttr === '1' || genderEnabledAttr === 'true');
            var genderSupported = (genderSupportedAttr === '1' || genderSupportedAttr === 'true');
            var genderOptions = [];
            var genderVisualConfig = null;
            if (genderOptionsAttr) {
                try {
                    var parsed = JSON.parse(genderOptionsAttr);
                    if (Array.isArray(parsed)) {
                        genderOptions = parsed;
                    }
                } catch (_) {}
            }
            if (genderVisualConfigAttr) {
                try {
                    var parsedVisual = JSON.parse(genderVisualConfigAttr);
                    if (parsedVisual && typeof parsedVisual === 'object') {
                        genderVisualConfig = parsedVisual;
                    }
                } catch (_) {}
            }

            try {
                if (window.llToolsFlashcardsData) {
                    var found = null;
                    if (window.llToolsFlashcardsData.categories && window.llToolsFlashcardsData.categories.length) {
                        for (var i=0;i<window.llToolsFlashcardsData.categories.length;i++){
                            var c = window.llToolsFlashcardsData.categories[i];
                            if (c && c.name === cat) { found = c; break; }
                        }
                    }
                    if (!found) {
                        (window.llToolsFlashcardsData.categories || (window.llToolsFlashcardsData.categories = [])).push({
                            id: 0,
                            slug: '',
                            name: cat,
                            translation: cat,
                            mode: displayModeHint || 'image',
                            option_type: optionTypeHint || displayModeHint || 'image',
                            prompt_type: promptTypeHint || 'audio',
                            gender_supported: genderSupported
                        });
                    } else {
                        if (displayModeHint) { found.mode = displayModeHint; }
                        if (optionTypeHint) { found.option_type = optionTypeHint; }
                        if (promptTypeHint) { found.prompt_type = promptTypeHint; }
                        found.gender_supported = genderSupported;
                    }
                }
            } catch (e) {}

            if (typeof window.llOpenFlashcardForCategory === 'function') {
                var opts = {
                    mode: mode,
                    genderEnabled: genderEnabled,
                    genderSupported: genderSupported,
                    genderOptions: genderOptions,
                    genderVisualConfig: genderVisualConfig,
                    triggerEl: a
                };
                if (wordsetId) {
                    opts.wordsetId = wordsetId;
                } else if (wordsetSlug) {
                    opts.wordset = wordsetSlug;
                }
                window.llOpenFlashcardForCategory(cat, opts);
            } else {
                console.error('llOpenFlashcardForCategory not found');
            }
      }

      function vanillaBind(){
        document.removeEventListener('click', vanillaHandler, true);
        document.addEventListener('click', vanillaHandler, true);
      }
      function vanillaHandler(e){
        var a = e.target.closest && e.target.closest('.ll-quiz-page-trigger');
        if (!a) return;
        e.preventDefault();
        if (typeof e.stopImmediatePropagation === 'function') {
          e.stopImmediatePropagation();
        } else {
          e.stopPropagation();
        }
        openFromAnchor(a);
      }

      function jqueryBind($){
        $(document).off('click.llqpg', '.ll-quiz-page-trigger')
                   .on('click.llqpg', '.ll-quiz-page-trigger', function(ev){
                      ev.preventDefault(); ev.stopPropagation();
                      openFromAnchor(this);
                   });
        $(document).off('keydown.llqpg', '.ll-quiz-page-trigger')
                   .on('keydown.llqpg', '.ll-quiz-page-trigger', function(ev){
                      if (ev.key === ' ' || ev.key === 'Enter') { ev.preventDefault(); $(this).trigger('click'); }
                   });
      }

      function init(){
        vanillaBind();
        if (window.jQuery) { jqueryBind(window.jQuery); }
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
      } else {
        init();
      }
    })();
    </script>
    <?php
}

/** Prints the flashcard overlay DOM (same IDs the widget expects) once. */
function ll_qpg_print_flashcard_shell_once() {
    static $printed = false;
    if ($printed) { return; }
    $printed = true;
    $mode_ui = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $practice_mode_ui = $mode_ui['practice'] ?? [];
    $learning_mode_ui = $mode_ui['learning'] ?? [];
    $self_check_mode_ui = $mode_ui['self-check'] ?? [];
    $listening_mode_ui = $mode_ui['listening'] ?? [];
    $gender_mode_ui = $mode_ui['gender'] ?? [];
    $render_mode_icon = function (array $cfg, string $fallback, string $class = 'mode-icon'): void {
        if (!empty($cfg['svg'])) {
            echo '<span class="' . esc_attr($class) . '" aria-hidden="true">' . $cfg['svg'] . '</span>';
            return;
        }
        $icon = !empty($cfg['icon']) ? $cfg['icon'] : $fallback;
        echo '<span class="' . esc_attr($class) . '" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
    };
    ?>
    <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container" style="display:none;">
      <div id="ll-tools-flashcard-popup" style="display:none;">
        <div id="ll-tools-flashcard-quiz-popup" style="display:none;">

          <div id="ll-tools-flashcard-header" style="display:none;">
            <button id="ll-tools-close-flashcard" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>

            <div id="ll-tools-learning-progress" style="display:none;"></div>

            <div id="ll-tools-category-stack" class="ll-tools-category-stack">
              <span id="ll-tools-category-display" class="ll-tools-category-display"></span>
              <button id="ll-tools-repeat-flashcard" class="play-mode" aria-label="<?php echo esc_attr__('Play', 'll-tools-text-domain'); ?>">
              </button>
            </div>

            <div id="ll-tools-loading-animation" class="ll-tools-loading-animation" aria-hidden="true"></div>
          </div>

          <div id="ll-tools-flashcard-content">
            <div id="ll-tools-prompt" class="ll-tools-prompt" style="display:none;"></div>
            <div id="ll-tools-flashcard"></div>
            <audio controls class="hidden"></audio>
          </div>

          <!-- Mode switcher: single toggle that expands to fixed-order options -->
          <?php
            $practice_label = $practice_mode_ui['switchLabel'] ?? __('Switch to Practice Mode', 'll-tools-text-domain');
            $learning_label = $learning_mode_ui['switchLabel'] ?? __('Switch to Learning Mode', 'll-tools-text-domain');
            $self_check_label = $self_check_mode_ui['switchLabel'] ?? __('Open Self Check', 'll-tools-text-domain');
            $listening_label = $listening_mode_ui['switchLabel'] ?? __('Switch to Listening Mode', 'll-tools-text-domain');
            $gender_label = $gender_mode_ui['switchLabel'] ?? __('Switch to Gender', 'll-tools-text-domain');
            $settings_label = __('Study Settings', 'll-tools-text-domain');
          ?>
          <div id="ll-tools-mode-switcher-wrap" class="ll-tools-mode-switcher-wrap" style="display:none;" aria-expanded="false">
            <?php if (is_user_logged_in()): ?>
              <div id="ll-tools-settings-control" class="ll-tools-settings-control">
                <button id="ll-tools-settings-button" class="ll-tools-settings-button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr($settings_label); ?>">
                  <span class="mode-icon" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                      <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                      <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                  </span>
                </button>
                <div id="ll-tools-settings-panel" class="ll-tools-settings-panel" role="dialog" aria-label="<?php echo esc_attr($settings_label); ?>" aria-hidden="true">
                  <div class="ll-tools-settings-section">
                    <div class="ll-tools-settings-heading"><?php echo esc_html__('Word inclusion', 'll-tools-text-domain'); ?></div>
                    <div class="ll-tools-settings-options" role="group" aria-label="<?php echo esc_attr__('Word inclusion', 'll-tools-text-domain'); ?>">
                      <button type="button" class="ll-tools-settings-option" data-star-mode="normal"><?php echo esc_html__('☆★ All words once', 'll-tools-text-domain'); ?></button>
                      <button type="button" class="ll-tools-settings-option" data-star-mode="weighted"><?php echo esc_html__('★☆★ Starred twice', 'll-tools-text-domain'); ?></button>
                      <button type="button" class="ll-tools-settings-option" data-star-mode="only"><?php echo esc_html__('★ Starred only', 'll-tools-text-domain'); ?></button>
                    </div>
                  </div>
                  <div class="ll-tools-settings-section">
                    <div class="ll-tools-settings-heading"><?php echo esc_html__('Transition speed', 'll-tools-text-domain'); ?></div>
                    <div class="ll-tools-settings-options" role="group" aria-label="<?php echo esc_attr__('Transition speed', 'll-tools-text-domain'); ?>">
                      <button type="button" class="ll-tools-settings-option" data-speed="normal"><?php echo esc_html__('Standard pace', 'll-tools-text-domain'); ?></button>
                      <button type="button" class="ll-tools-settings-option" data-speed="fast"><?php echo esc_html__('Faster transitions', 'll-tools-text-domain'); ?></button>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>
            <div id="ll-tools-mode-menu" class="ll-tools-mode-menu" role="menu" aria-hidden="true">
              <!-- Fixed order: learning, practice, listening, gender, self-check -->
              <button class="ll-tools-mode-option learning" role="menuitemradio" aria-label="<?php echo esc_attr($learning_label); ?>" data-mode="learning">
                <?php $render_mode_icon($learning_mode_ui, '🎓', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option practice" role="menuitemradio" aria-label="<?php echo esc_attr($practice_label); ?>" data-mode="practice">
                <?php $render_mode_icon($practice_mode_ui, '❓', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option listening" role="menuitemradio" aria-label="<?php echo esc_attr($listening_label); ?>" data-mode="listening">
                <?php $render_mode_icon($listening_mode_ui, '🎧', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option gender hidden" role="menuitemradio" aria-label="<?php echo esc_attr($gender_label); ?>" data-mode="gender" aria-hidden="true">
                <?php $render_mode_icon($gender_mode_ui, '⚥', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option self-check" role="menuitemradio" aria-label="<?php echo esc_attr($self_check_label); ?>" data-mode="self-check">
                <?php $render_mode_icon($self_check_mode_ui, '✔✖', 'mode-icon'); ?>
              </button>
            </div>
            <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>">
              <span class="mode-icon" aria-hidden="true">⇄</span>
            </button>
          </div>

          <div id="quiz-results" style="display:none;">
            <h2 id="quiz-results-title"><?php echo esc_html__('Quiz Results', 'll-tools-text-domain'); ?></h2>
            <p id="quiz-results-message" style="display:none;"></p>
            <p><strong><?php echo esc_html__('Correct:', 'll-tools-text-domain'); ?></strong>
              <span id="correct-count">0</span> / <span id="total-questions">0</span>
            </p>
            <p id="quiz-results-categories" style="margin-top:10px; display:none;"></p>
            <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
              <?php
                $practice_label = $practice_mode_ui['resultsButtonText'] ?? __('Practice Mode', 'll-tools-text-domain');
                $learning_label = $learning_mode_ui['resultsButtonText'] ?? __('Learning Mode', 'll-tools-text-domain');
                $self_check_results_label = $self_check_mode_ui['resultsButtonText'] ?? __('Self Check', 'll-tools-text-domain');
                $listening_label = $listening_mode_ui['resultsButtonText'] ?? __('Listen', 'll-tools-text-domain');
                $gender_results_label = $gender_mode_ui['resultsButtonText'] ?? __('Gender', 'll-tools-text-domain');
              ?>
              <button id="restart-practice-mode" class="quiz-button quiz-mode-button">
                <?php $render_mode_icon($practice_mode_ui, '❓', 'button-icon'); ?>
                <?php echo esc_html($practice_label); ?>
              </button>
              <button id="restart-learning-mode" class="quiz-button quiz-mode-button">
                <?php $render_mode_icon($learning_mode_ui, '🎓', 'button-icon'); ?>
                <span class="ll-learning-results-label"><?php echo esc_html($learning_label); ?></span>
              </button>
              <button id="restart-self-check-mode" class="quiz-button quiz-mode-button">
                <?php $render_mode_icon($self_check_mode_ui, '✔✖', 'button-icon'); ?>
                <?php echo esc_html($self_check_results_label); ?>
              </button>
              <button id="restart-gender-mode" class="quiz-button quiz-mode-button" style="display:none;">
                <?php $render_mode_icon($gender_mode_ui, '⚥', 'button-icon'); ?>
                <span class="ll-gender-results-label"><?php echo esc_html($gender_results_label); ?></span>
              </button>
              <button id="restart-listening-mode" class="quiz-button quiz-mode-button" style="display:none;">
                <?php $render_mode_icon($listening_mode_ui, '🎧', 'button-icon'); ?>
                <?php echo esc_html($listening_label); ?>
              </button>
            </div>
            <div id="ll-gender-results-actions" style="display:none; margin-top: 12px;">
              <button id="ll-gender-next-activity" class="quiz-button quiz-mode-button" style="display:none;">
                <?php echo esc_html__('Next Gender Activity', 'll-tools-text-domain'); ?>
              </button>
              <button id="ll-gender-next-chunk" class="quiz-button quiz-mode-button" style="display:none;">
                <?php echo esc_html__('Next Recommended Set', 'll-tools-text-domain'); ?>
              </button>
            </div>
            <div id="ll-study-results-actions" style="display:none; margin-top: 12px;">
              <p id="ll-study-results-suggestion" style="display:none; margin: 0 0 8px 0;"></p>
              <button id="ll-study-results-same-chunk" class="quiz-button quiz-mode-button" style="display:none;">
                <?php echo esc_html__('Repeat', 'll-tools-text-domain'); ?>
              </button>
              <button id="ll-study-results-different-chunk" class="quiz-button quiz-mode-button" style="display:none;">
                <?php echo esc_html__('Categories', 'll-tools-text-domain'); ?>
              </button>
              <button id="ll-study-results-next-chunk" class="quiz-button quiz-mode-button" style="display:none;">
                <?php echo esc_html__('Recommended', 'll-tools-text-domain'); ?>
              </button>
            </div>
            <button id="restart-quiz" class="quiz-button" style="display:none;"><?php echo esc_html__('Restart Quiz', 'll-tools-text-domain'); ?></button>
          </div>

        </div>
      </div>
    </div>

    <script>
    (function($){
        function initPlayIcon() {
            if (window.LLFlashcards && window.LLFlashcards.Dom && typeof window.LLFlashcards.Dom.setRepeatButton === 'function') {
                window.LLFlashcards.Dom.setRepeatButton('play');
            } else {
            setTimeout(initPlayIcon, 50);
            }
        }
        initPlayIcon();

        window.llOpenFlashcardForCategory = function(catName, wordset, mode){
            if (!catName) return;

            var opts = null;
            if (wordset && typeof wordset === 'object') {
                opts = wordset;
                wordset = '';
                mode = (opts && (opts.mode || opts.quiz_mode)) || mode || 'practice';
                if (opts) {
                    wordset = opts.wordsetId || opts.wordset_id || opts.wordset || '';
                    try {
                        if (!wordset && opts.triggerEl && opts.triggerEl.getAttribute) {
                            wordset = opts.triggerEl.getAttribute('data-wordset-id') ||
                                opts.triggerEl.getAttribute('data-wordset') || '';
                        }
                        if ((!mode || mode === 'practice') && opts.triggerEl && opts.triggerEl.getAttribute) {
                            mode = opts.triggerEl.getAttribute('data-mode') || mode;
                        }
                    } catch (_) {}
                }
            }

            mode = mode || 'practice';

            if (wordset && typeof wordset !== 'string' && typeof wordset !== 'number') {
                wordset = '';
            }
            wordset = String(wordset || '');

            var parsedWordsetIds = [];
            var wordsetIsNumeric = wordset !== '' && !isNaN(parseInt(wordset, 10));
            if (wordsetIsNumeric) {
                var wid = parseInt(wordset, 10);
                if (wid > 0) { parsedWordsetIds.push(wid); }
            }

            var previousWordset = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.wordset !== undefined)
                ? String(window.llToolsFlashcardsData.wordset || '')
                : '';
            var currentWordset = wordset;
            var wordsetChanged = (previousWordset !== currentWordset);
            var launchContext = (opts && typeof opts.launchContext === 'string')
                ? String(opts.launchContext || '').toLowerCase()
                : '';
            if (!launchContext && opts && opts.triggerEl) {
                try {
                    var triggerEl = opts.triggerEl;
                    var isVocabLessonTrigger = !!(
                        (triggerEl.classList && triggerEl.classList.contains('ll-vocab-lesson-mode-button')) ||
                        (triggerEl.closest && triggerEl.closest('[data-ll-vocab-lesson], .ll-vocab-lesson-page'))
                    );
                    launchContext = isVocabLessonTrigger ? 'vocab_lesson' : 'quiz_pages';
                } catch (_) {}
            }

            if (window.llToolsFlashcardsData) {
                window.llToolsFlashcardsData.wordset = currentWordset;
                window.llToolsFlashcardsData.wordsetFallback = false;
                window.llToolsFlashcardsData.quiz_mode = mode;
                window.llToolsFlashcardsData.wordsetIds = parsedWordsetIds.length ? parsedWordsetIds : [];
                window.llToolsFlashcardsData.launchContext = launchContext;
                window.llToolsFlashcardsData.launch_context = launchContext;
                if (mode === 'gender') {
                    delete window.llToolsFlashcardsData.genderSessionPlan;
                    delete window.llToolsFlashcardsData.genderSessionPlanArmed;
                    delete window.llToolsFlashcardsData.gender_session_plan_armed;
                    window.llToolsFlashcardsData.genderLaunchSource = 'direct';
                }
            }

            var genderEnabled = (opts && typeof opts.genderEnabled !== 'undefined') ? !!opts.genderEnabled : null;
            var genderSupported = (opts && typeof opts.genderSupported !== 'undefined') ? !!opts.genderSupported : null;
            var genderOptions = (opts && Array.isArray(opts.genderOptions)) ? opts.genderOptions : null;
            var genderVisualConfig = (opts && opts.genderVisualConfig && typeof opts.genderVisualConfig === 'object')
                ? opts.genderVisualConfig
                : null;
            if (opts && opts.triggerEl && opts.triggerEl.getAttribute) {
                if (genderEnabled === null) {
                    var geAttr = opts.triggerEl.getAttribute('data-gender-enabled');
                    if (geAttr !== null) {
                        genderEnabled = (geAttr === '1' || geAttr === 'true');
                    }
                }
                if (genderSupported === null) {
                    var gsAttr = opts.triggerEl.getAttribute('data-gender-supported');
                    if (gsAttr !== null) {
                        genderSupported = (gsAttr === '1' || gsAttr === 'true');
                    }
                }
                if (genderOptions === null) {
                    var goAttr = opts.triggerEl.getAttribute('data-gender-options') || '';
                    if (goAttr) {
                        try {
                            var parsedOpts = JSON.parse(goAttr);
                            if (Array.isArray(parsedOpts)) {
                                genderOptions = parsedOpts;
                            }
                        } catch (_) {}
                    }
                }
                if (genderVisualConfig === null) {
                    var gvAttr = opts.triggerEl.getAttribute('data-gender-visual-config') || '';
                    if (gvAttr) {
                        try {
                            var parsedVisualCfg = JSON.parse(gvAttr);
                            if (parsedVisualCfg && typeof parsedVisualCfg === 'object') {
                                genderVisualConfig = parsedVisualCfg;
                            }
                        } catch (_) {}
                    }
                }
            }
            if (genderEnabled === false && !Array.isArray(genderOptions)) {
                genderOptions = [];
            }

            if (window.llToolsFlashcardsData) {
                if (genderEnabled !== null) {
                    window.llToolsFlashcardsData.genderEnabled = genderEnabled;
                    window.llToolsFlashcardsData.genderWordsetId = parsedWordsetIds.length ? parsedWordsetIds[0] : 0;
                }
                if (Array.isArray(genderOptions)) {
                    window.llToolsFlashcardsData.genderOptions = genderOptions;
                }
                if (genderVisualConfig !== null) {
                    window.llToolsFlashcardsData.genderVisualConfig = genderVisualConfig;
                }
                if (genderSupported !== null && window.llToolsFlashcardsData.categories) {
                    for (var i = 0; i < window.llToolsFlashcardsData.categories.length; i++) {
                        var cat = window.llToolsFlashcardsData.categories[i];
                        if (cat && cat.name === catName) {
                            cat.gender_supported = genderSupported;
                            break;
                        }
                    }
                }
            }

            if (wordsetChanged && window.FlashcardLoader) {
                if (typeof window.FlashcardLoader.resetCacheForNewWordset === 'function') {
                    window.FlashcardLoader.resetCacheForNewWordset();
                } else if (Array.isArray(window.FlashcardLoader.loadedCategories)) {
                    window.FlashcardLoader.loadedCategories.length = 0;
                }
            }

            // Prevent multiple rapid opens triggering multiple sessions
            if (window.__LL_QPG_OPEN_IN_PROGRESS) {
                return;
            }
            window.__LL_QPG_OPEN_IN_PROGRESS = true;

            try { document.body.classList.add('ll-qpg-popup-active'); } catch (_) {}
            try { $('body').addClass('ll-qpg-popup-active'); } catch (_) {}
            $('body').addClass('ll-tools-flashcard-open');
            $('#ll-tools-flashcard-container').show();
            $('#ll-tools-flashcard-popup').show();
            $('#ll-tools-flashcard-quiz-popup').css('display', 'flex');
            try {
                var p = initFlashcardWidget([catName], mode);
                if (p && typeof p.finally === 'function') {
                    p.finally(function(){ window.__LL_QPG_OPEN_IN_PROGRESS = false; });
                } else {
                    setTimeout(function(){ window.__LL_QPG_OPEN_IN_PROGRESS = false; }, 0);
                }
            } catch (e) {
                console.error('initFlashcardWidget failed', e);
                try { document.body.classList.remove('ll-qpg-popup-active'); } catch (_) {}
                try { $('body').removeClass('ll-qpg-popup-active'); } catch (_) {}
                window.__LL_QPG_OPEN_IN_PROGRESS = false;
            }
        };
    })(jQuery);
    </script>
    <?php
}

/** ------------------------------------------------------------------
 * Shortcode: [quiz_pages_grid]
 * Attributes:
 *   - wordset  (id|slug|name)
 *   - columns
 *   - popup    ("yes" to open flashcard overlay inline)
 *   - order / order_dir (kept for backward compat; ignored)
 * ------------------------------------------------------------------ */
function ll_quiz_pages_grid_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'wordset'   => '',
            'columns'   => '',
            'popup'     => 'no',
            'mode'      => 'practice',
            'order'     => 'title',
            'order_dir' => 'ASC',
        ],
        $atts,
        'quiz_pages_grid'
    );

    $filter = [];
    if (trim($atts['wordset']) !== '') {
        $filter['wordset'] = $atts['wordset'];
    }

    $items = ll_get_all_quiz_pages_data($filter);
    if (empty($items)) {
        return '<p>' . esc_html__('No quizzes found.', 'll-tools-text-domain') . '</p>';
    }

    $use_popup = (strtolower($atts['popup']) === 'yes');
    $grid_id   = 'll-quiz-pages-grid-' . wp_generate_uuid4();
    $quiz_mode = in_array($atts['mode'], ['practice', 'learning', 'self-check'], true) ? $atts['mode'] : 'practice';

    if ($use_popup) {
        ll_qpg_bootstrap_flashcards_for_grid($atts['wordset']);
    }

    $style = '';
    if ($atts['columns'] !== '' && is_numeric($atts['columns']) && (int)$atts['columns'] > 0) {
        $cols  = (int) $atts['columns'];
        $style = ' style="grid-template-columns: repeat(' . $cols . ', minmax(220px, 1fr));"';
    }

    ob_start();

    echo '<div id="' . esc_attr($grid_id) . '" class="ll-quiz-pages-grid"' . $style . '>';

    foreach ($items as $it) {
        $title     = $it['display_name'];
        $permalink = $it['permalink'];
        $raw_name  = $it['name'];

        if (!$use_popup) {
            $qs = ($quiz_mode !== 'practice') ? '?mode=' . esc_attr($quiz_mode) : '';
            echo '<a class="ll-quiz-page-card ll-quiz-page-link"'
            . ' href="' . esc_url($permalink . $qs) . '"'
            . ' aria-label="' . esc_attr($title) . '">';
            echo '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        } else {
            // For popup, add wordset and mode data attributes if set
            $ws_attr = (!empty($it['wordset_slug'])) ? ' data-wordset="' . esc_attr($it['wordset_slug']) . '"' : '';
            $ws_id_attr = (!empty($it['wordset_id'])) ? ' data-wordset-id="' . (int) $it['wordset_id'] . '"' : '';
            $mode_hint = (!empty($it['display_mode'])) ? ' data-display-mode="' . esc_attr($it['display_mode']) . '"' : '';
            $mode_attr = ' data-mode="' . esc_attr($quiz_mode) . '"';
            $prompt_attr = (!empty($it['prompt_type'])) ? ' data-prompt-type="' . esc_attr($it['prompt_type']) . '"' : '';
            $option_attr = (!empty($it['option_type'])) ? ' data-option-type="' . esc_attr($it['option_type']) . '"' : '';
            $gender_enabled_attr = ' data-gender-enabled="' . (!empty($it['gender_enabled']) ? '1' : '0') . '"';
            $gender_supported_attr = ' data-gender-supported="' . (!empty($it['gender_supported']) ? '1' : '0') . '"';
            $gender_options_attr = ' data-gender-options="' . esc_attr(wp_json_encode($it['gender_options'] ?? [])) . '"';
            $gender_visual_attr = ' data-gender-visual-config="' . esc_attr(wp_json_encode($it['gender_visual_config'] ?? [])) . '"';
            echo '<a class="ll-quiz-page-card ll-quiz-page-trigger"'
            . ' href="#" role="button"'
            . ' aria-label="Start ' . esc_attr($title) . '"'
            . ' data-category="' . esc_attr($raw_name) . '"'
            . ' data-url="' . esc_url($permalink) . '"'
            . $ws_attr
            . $ws_id_attr
            . $mode_hint
            . $mode_attr
            . $prompt_attr
            . $option_attr
            . $gender_enabled_attr
            . $gender_supported_attr
            . $gender_options_attr
            . $gender_visual_attr
            . '>';
            echo   '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        }
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_grid', 'll_quiz_pages_grid_shortcode');

/** ------------------------------------------------------------------
 * Shortcode: [quiz_pages_dropdown]
 * Attributes:
 *   - wordset (id|slug|name)   ← NEW
 *   - placeholder
 *   - button ("yes" to show a Go button; default is navigate on change)
 * ------------------------------------------------------------------ */
function ll_quiz_pages_dropdown_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'wordset'     => '', // NEW
            'placeholder' => __('Select a quiz…', 'll-tools-text-domain'),
            'button'      => 'no',
        ],
        $atts,
        'quiz_pages_dropdown'
    );

    $filter = [];
    if (trim($atts['wordset']) !== '') {
        $filter['wordset'] = $atts['wordset'];
    }

    $items = ll_get_all_quiz_pages_data($filter);

    ob_start();

    if (empty($items)) {
        echo '<p>' . esc_html__('No quiz pages are available yet.', 'll-tools-text-domain') . '</p>';
        return ob_get_clean();
    }

    $select_id  = 'll-quiz-pages-select-' . wp_generate_uuid4();
    $has_button = strtolower($atts['button']) === 'yes';

    echo '<div class="ll-quiz-pages-dropdown">';
    echo '<label class="screen-reader-text" for="' . esc_attr($select_id) . '">'
        . esc_html__('Quiz selection', 'll-tools-text-domain') . '</label>';

    echo '<select id="' . esc_attr($select_id) . '" class="ll-quiz-pages-select" '
        . ($has_button ? '' : 'onchange="if(this.value){window.location.href=this.value;}"') . '>';

    echo '<option value="">' . esc_html($atts['placeholder']) . '</option>';

    foreach ($items as $it) {
        echo '<option value="' . esc_url($it['permalink']) . '">' . esc_html($it['display_name']) . '</option>';
    }

    echo '</select>';

    if ($has_button) {
        $btn_id = 'll-quiz-pages-go-' . wp_generate_uuid4();
        echo '<button id="' . esc_attr($btn_id) . '" class="ll-quiz-pages-go">' . esc_html__('Go', 'll-tools-text-domain') . '</button>';
        echo '<script>
            (function(){ var s=document.getElementById(' . json_encode($select_id) . '), b=document.getElementById(' . json_encode($btn_id) . ');
                if(s&&b){ b.addEventListener("click", function(){ if(s.value){ window.location.href=s.value; } }); }
            })();
        </script>';
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_dropdown', 'll_quiz_pages_dropdown_shortcode'); // existed previously. :contentReference[oaicite:4]{index=4}

/**
 * Conditionally enqueue styles used by these shortcodes (grid/dropdown only).
 * The flashcard overlay uses its own stylesheet in popup mode.
 */
function ll_maybe_enqueue_quiz_pages_styles() {
    if (!is_singular()) return;
    $post = get_post(); if (!$post) return;

    if ( has_shortcode($post->post_content, 'quiz_pages_grid') ||
         has_shortcode($post->post_content, 'quiz_pages_dropdown') ) {
        ll_enqueue_asset_by_timestamp('/css/quiz-pages-style.css', 'll-quiz-pages-style');
    }
}
add_action('wp_enqueue_scripts', 'll_maybe_enqueue_quiz_pages_styles');
