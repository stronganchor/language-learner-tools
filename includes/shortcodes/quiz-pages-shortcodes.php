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
 * Collect all word-category term IDs that are used by at least one published
 * 'words' post that also has the given wordset term. Include ancestors so
 * parent categories appear too.
 *
 * @param int $wordset_term_id
 * @return int[]  unique term IDs
 */
function ll_collect_word_category_ids_for_wordset($wordset_term_id) {
    $found = [];

    $q = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [[
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => [(int)$wordset_term_id],
        ]],
    ]);

    foreach ((array) $q->posts as $post_id) {
        $cats = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        foreach ($cats as $cid) {
            $found[$cid] = true;
            // Include ancestors so higher-level categories in the chain are available
            $ancestors = get_ancestors($cid, 'word-category', 'taxonomy');
            foreach ($ancestors as $aid) {
                $found[$aid] = true;
            }
        }
    }

    return array_map('intval', array_keys($found));
}

/**
 * Fetch all published quiz pages and return display data.
 * Optionally filter by a Word Set (only categories that actually have words in that set).
 *
 * @param array $opts e.g. ['wordset' => 'kurmanji']  (accepts id|slug|name)
 * @return array[] Each item: [
 *   'post_id'      => int,
 *   'permalink'    => string,
 *   'slug'         => string,
 *   'term_id'      => int,
 *   'name'         => string,   // original term name (UNTRANSLATED)
 *   'translation'  => string,   // translated term name (may be '')
 *   'display_name' => string,   // translation if available (and enabled), else name
 * ]
 */
function ll_get_all_quiz_pages_data($opts = []) {
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'meta_key'       => '_ll_tools_word_category_id',
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);
    if (empty($pages)) return [];

    $filter_term_id   = null;
    $allowed_term_ids = null;

    if (!empty($opts['wordset'])) {
        $filter_term_id = ll_tools_resolve_term_id_by_slug_name_or_id('wordset', $opts['wordset']);
        if ($filter_term_id) {
            // Build a one-shot map of which word-category terms appear in this wordset
            $allowed_term_ids = ll_collect_word_category_ids_for_wordset($filter_term_id);
            // If nothing matches, short-circuit to empty
            if (empty($allowed_term_ids)) return [];
        } else {
            // Invalid wordset spec -> return empty to avoid mixing sets
            return [];
        }
    }

    $enable_translation = get_option('ll_enable_category_translation', 0);
    $items = [];

    foreach ($pages as $post_id) {
        $term_id = (int) get_post_meta($post_id, '_ll_tools_word_category_id', true);
        if (!$term_id) continue;

        // If a wordset filter is active, keep only categories present in that set
        if (is_array($allowed_term_ids) && !in_array($term_id, $allowed_term_ids, true)) {
            continue;
        }

        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) continue;

        $name = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');

        $translation = '';
        if ($enable_translation) {
            $t = get_term_meta($term_id, 'term_translation', true);
            if (!empty($t)) $translation = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }

        $items[] = [
            'post_id'      => $post_id,
            'permalink'    => get_permalink($post_id),
            'slug'         => $term->slug,
            'term_id'      => $term_id,
            'name'         => $name,
            'translation'  => $translation,
            'display_name' => ($translation !== '' ? $translation : $name),
        ];
    }

    // Natural alpha by display name, same as before.
    usort($items, function ($a, $b) {
        return strnatcasecmp($a['display_name'], $b['display_name']);
    }); // based on existing sort approach. :contentReference[oaicite:1]{index=1}

    return $items;
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

    $atts = ['mode' => 'random'];
    ll_flashcards_enqueue_and_localize($atts, $categories, false, [], '');

    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once');
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

                <div id="ll-tools-flashcard-header" class="ll-tools-category-stack">
                    <span id="ll-tools-category-display" class="ll-tools-category-display"></span>
                    <button id="ll-tools-repeat-flashcard" class="play-mode" aria-label="<?php esc_attr_e('Repeat', 'll-tools-text-domain'); ?>">
                        <span class="icon-container"><img alt="" /></span>
                    </button>
                    <div id="ll-tools-loading-animation" class="ll-tools-loading-animation"></div>
                    <button id="ll-tools-close-flashcard" aria-label="<?php esc_attr_e('Close', 'll-tools-text-domain'); ?>">&times;</button>
                </div>

                <div id="ll-tools-flashcard-content">
                    <div id="ll-tools-flashcard"></div>
                    <audio controls class="hidden"></audio>
                </div>

                <div id="quiz-results" style="display:none;">
                    <h2 id="quiz-results-title"><?php esc_html_e('Quiz Results', 'll-tools-text-domain'); ?></h2>
                    <p id="quiz-results-message" style="display:none;"></p>
                    <p><strong><?php esc_html_e('Correct:', 'll-tools-text-domain'); ?></strong>
                        <span id="correct-count">0</span> / <span id="total-questions">0</span></p>
                    <p id="quiz-results-categories" style="margin-top:10px; display:none;"></p>
                    <button id="restart-quiz" class="quiz-button" style="display:none;">
                        <?php esc_html_e('Restart Quiz', 'll-tools-text-domain'); ?>
                    </button>
                </div>

            </div>
        </div>
    </div>
    <script>
    (function($){
        window.llOpenFlashcardForCategory = function(catName){
            if (!catName) return;
            $('#ll-tools-flashcard-container').show();
            $('#ll-tools-flashcard-popup').show();
            $('#ll-tools-flashcard-quiz-popup').show();
            $('body').addClass('ll-tools-flashcard-open');
            try { initFlashcardWidget([catName]); } catch (e) { console.error('initFlashcardWidget failed', e); }
        };
    })(jQuery);
    </script>
    <?php
}

/** ------------------------------------------------------------------
 * Shortcode: [quiz_pages_grid]
 * Attributes:
 *   - wordset  (id|slug|name)  ← NEW
 *   - columns
 *   - popup    ("yes" to open flashcard overlay inline)
 *   - order / order_dir (kept for backward compat; ignored)
 * ------------------------------------------------------------------ */
function ll_quiz_pages_grid_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'wordset'   => '',      // filter by word set (id|slug|name)
            'columns'   => '',
            'popup'     => 'no',
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

    // Build list using the helper so translated display names are respected. :contentReference[oaicite:2]{index=2}
    $items = ll_get_all_quiz_pages_data($filter);
    if (empty($items)) {
        return '<p>' . esc_html__('No quizzes found.', 'll-tools-text-domain') . '</p>';
    }

    $use_popup = (strtolower($atts['popup']) === 'yes');
    $grid_id   = 'll-quiz-pages-grid-' . wp_generate_uuid4();

    // When using popup mode, make sure the flashcard overlay + assets exist.
    if ($use_popup) {
        ll_qpg_bootstrap_flashcards_for_grid();
    }

    // Optional fixed columns override.
    $style = '';
    if ($atts['columns'] !== '' && is_numeric($atts['columns']) && (int)$atts['columns'] > 0) {
        $cols  = (int) $atts['columns'];
        $style = ' style="grid-template-columns: repeat(' . $cols . ', minmax(220px, 1fr));"';
    }

    ob_start();

    echo '<div id="' . esc_attr($grid_id) . '" class="ll-quiz-pages-grid"' . $style . '>';

    foreach ($items as $it) {
        $title     = $it['display_name']; // translated when enabled
        $permalink = $it['permalink'];
        $raw_name  = $it['name'];         // untranslated category name

        if ($use_popup) {
            echo '<a class="ll-quiz-page-card ll-quiz-page-trigger"'
               . ' href="#" role="button"'
               . ' aria-label="Start ' . esc_attr($title) . '"'
               . ' data-category="' . esc_attr($raw_name) . '"'
               . ' onclick="llOpenFlashcardForCategory(' . wp_json_encode($raw_name) . '); return false;">';
            echo   '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        } else {
            echo '<a class="ll-quiz-page-card ll-quiz-page-link"'
               . ' href="' . esc_url($permalink) . '"'
               . ' aria-label="' . esc_attr($title) . '">';
            echo   '<span class="ll-quiz-page-name">' . esc_html($title) . '</span>';
            echo '</a>';
        }
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_grid', 'll_quiz_pages_grid_shortcode'); // existed previously. :contentReference[oaicite:3]{index=3}

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
add_action('wp_enqueue_scripts', 'll_maybe_enqueue_quiz_pages_styles'); // already present. :contentReference[oaicite:5]{index=5}
