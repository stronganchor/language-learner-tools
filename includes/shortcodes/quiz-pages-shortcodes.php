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

    if (!empty($opts['wordset'])) {
        $ws_ids = ll_raw_resolve_wordset_term_ids($opts['wordset']);
        if (empty($ws_ids)) return []; // nothing by that slug/name/id
        $allowed_term_ids = ll_collect_wc_ids_for_wordset_term_ids($ws_ids);
        if (empty($allowed_term_ids)) return []; // no categories used by that wordset
    }

    $enable_translation = (int) get_option('ll_enable_category_translation', 0);
    $items = [];

    foreach ($pages as $post_id) {
        $term_id = (int) get_post_meta($post_id, '_ll_tools_word_category_id', true);
        if ($term_id <= 0) continue;

        if (is_array($allowed_term_ids) && !in_array($term_id, $allowed_term_ids, true)) {
            continue;
        }

        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) continue;

        $name        = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');
        $translation = '';
        if ($enable_translation) {
            $t = get_term_meta($term_id, 'term_translation', true);
            if (!empty($t)) $translation = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }

        // Determine wordset slug
        $wordset_slug = '';
        if (!empty($opts['wordset'])) {
            // If filtered by wordset, use that slug
            $wordset_slug = sanitize_text_field($opts['wordset']);
        } else {
            // No filter: select default wordset for this category
            $default_ws_id = ll_get_default_wordset_id_for_category($name);
            if ($default_ws_id > 0) {
                $default_term = get_term($default_ws_id, 'wordset');
                if ($default_term && !is_wp_error($default_term)) {
                    $wordset_slug = $default_term->slug;
                }
            }
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
        ];
    }

    usort($items, function ($a, $b) {
        return strnatcasecmp($a['display_name'], $b['display_name']);
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
 */
function ll_qpg_bootstrap_flashcards_for_grid() {
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $target_language    = strtolower(get_option('ll_translation_language', 'en'));
    $site_language      = strtolower(get_locale());
    $use_translations   = $enable_translation && strpos($site_language, $target_language) === 0;

    $all_terms = get_terms(['taxonomy' => 'word-category', 'hide_empty' => false]);
    if (is_wp_error($all_terms)) $all_terms = [];

    if (!function_exists('ll_process_categories')) {
        $categories = array_map(function($t){
            return [
                'id'          => $t->term_id,
                'slug'        => $t->slug,
                'name'        => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'translation' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'mode'        => 'image',
            ];
        }, $all_terms);
    } else {
        $categories = ll_process_categories($all_terms, $use_translations);
    }

    // Ensure flashcard assets + data are present
    $atts = ['mode' => 'random'];
    ll_flashcards_enqueue_and_localize($atts, $categories, false, [], '');

    // Ensure the popup shell is printed late in the page
    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once');

    // Keep the overlay above anything (incl. WP admin bar)
    echo '<style id="ll-qpg-popup-zfix">
      #ll-tools-flashcard-container,
      #ll-tools-flashcard-popup,
      #ll-tools-flashcard-quiz-popup{position:fixed;inset:0;z-index:999999}
      #ll-tools-flashcard-content{height:100%;overflow:auto}
    </style>';

    // Robust delegated click binding:
    //  - Works with or without jQuery
    //  - Prevents href="#" navigation
    ?>
    <script>
    (function(){
      function openFromAnchor(a){
            var cat = a.getAttribute('data-category') || '';
            var wordset = a.getAttribute('data-wordset') || '';
            var mode = a.getAttribute('data-mode') || 'standard';
            if (!cat) return;
            if (typeof window.llOpenFlashcardForCategory === 'function') {
                window.llOpenFlashcardForCategory(cat, wordset, mode);
            } else {
                console.error('llOpenFlashcardForCategory not found');
            }
      }

      // Vanilla JS delegation
      function vanillaBind(){
        document.removeEventListener('click', vanillaHandler, true);
        document.addEventListener('click', vanillaHandler, true);
      }
      function vanillaHandler(e){
        var a = e.target.closest && e.target.closest('.ll-quiz-page-trigger');
        if (!a) return;
        e.preventDefault(); e.stopPropagation();
        openFromAnchor(a);
      }

      // If jQuery exists, also bind via jQuery (nice to have)
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
    ?>
    <div id="ll-tools-flashcard-container" style="display:none;">
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
            <div id="ll-tools-flashcard"></div>
            <audio controls class="hidden"></audio>
          </div>

          <!-- Mode Switcher Button -->
          <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>" style="display:none;">
            <span class="mode-icon"></span>
          </button>

          <div id="quiz-results" style="display:none;">
            <h2 id="quiz-results-title"><?php echo esc_html__('Quiz Results', 'll-tools-text-domain'); ?></h2>
            <p id="quiz-results-message" style="display:none;"></p>
            <p><strong><?php echo esc_html__('Correct:', 'll-tools-text-domain'); ?></strong>
              <span id="correct-count">0</span> / <span id="total-questions">0</span>
            </p>
            <p id="quiz-results-categories" style="margin-top:10px; display:none;"></p>
            <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
              <button id="restart-standard-mode" class="quiz-button quiz-mode-button">
                <span class="button-icon">❓</span>
                <?php echo esc_html__('Standard Quiz', 'll-tools-text-domain'); ?>
              </button>
              <button id="restart-learning-mode" class="quiz-button quiz-mode-button">
                <span class="button-icon">🎓</span>
                <?php echo esc_html__('Learning Mode', 'll-tools-text-domain'); ?>
              </button>
            </div>
            <button id="restart-quiz" class="quiz-button" style="display:none;"><?php echo esc_html__('Restart Quiz', 'll-tools-text-domain'); ?></button>
          </div>

        </div>
      </div>
    </div>

    <script>
    (function($){
        // Initialize play icon
        function initPlayIcon() {
            if (window.LLFlashcards && window.LLFlashcards.Dom) {
            var btn = document.getElementById('ll-tools-repeat-flashcard');
            if (btn && !btn.querySelector('.icon-container')) {
                btn.innerHTML = window.LLFlashcards.Dom.getPlayIconHTML();
            }
            } else {
            setTimeout(initPlayIcon, 50);
            }
        }
        initPlayIcon();

        // Called by the grid link
        window.llOpenFlashcardForCategory = function(catName, wordset, mode){
            if (!catName) return;

            mode = mode || 'standard';

            var previousWordset = (window.llToolsFlashcardsData && window.llToolsFlashcardsData.wordset) || '';
            var currentWordset = wordset || '';
            var wordsetChanged = (previousWordset !== currentWordset);

            if (window.llToolsFlashcardsData) {
                window.llToolsFlashcardsData.wordset = currentWordset;
                window.llToolsFlashcardsData.quiz_mode = mode;
            }

            if (wordsetChanged && window.FlashcardLoader) {
                if (Array.isArray(window.FlashcardLoader.loadedCategories)) {
                    window.FlashcardLoader.loadedCategories.length = 0;
                }
            }

            $('#ll-tools-flashcard-container').show();
            $('#ll-tools-flashcard-popup').show();
            $('#ll-tools-flashcard-quiz-popup').show();
            $('body').addClass('ll-tools-flashcard-open');
            try { initFlashcardWidget([catName], mode); } catch (e) { console.error('initFlashcardWidget failed', e); }
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
            'mode'      => 'standard',  // NEW: default to standard mode
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
    $quiz_mode = in_array($atts['mode'], ['standard', 'learning']) ? $atts['mode'] : 'standard';

    if ($use_popup) {
        ll_qpg_bootstrap_flashcards_for_grid();
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
            // Non-popup: standard link to quiz page
            $qs = ($quiz_mode !== 'standard') ? '?mode=' . esc_attr($quiz_mode) : '';
            echo '<a class="ll-quiz-page-card ll-quiz-page-link"'
            . ' href="' . esc_url($permalink . $qs) . '"'
            . ' aria-label="' . esc_attr($title) . '">';
            echo '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        } else {
            // For popup, add wordset data attribute if set
            $ws_attr = (!empty($it['wordset_slug'])) ? ' data-wordset="' . esc_attr($it['wordset_slug']) . '"' : '';
            echo '<a class="ll-quiz-page-card ll-quiz-page-trigger"'
            . ' href="#" role="button"'
            . ' aria-label="Start ' . esc_attr($title) . '"'
            . ' data-category="' . esc_attr($raw_name) . '"'
            . ' data-url="' . esc_url($permalink) . '"'
            . $ws_attr  // Added
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
