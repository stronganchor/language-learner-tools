<?php
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
 * Fetches all published quiz pages and returns display data.
 *
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
function ll_get_all_quiz_pages_data() {
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'meta_key'       => '_ll_tools_word_category_id',
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));
    if (empty($pages)) return array();

    $enable_translation = get_option('ll_enable_category_translation', 0);
    $items = array();

    foreach ($pages as $post_id) {
        $term_id = (int) get_post_meta($post_id, '_ll_tools_word_category_id', true);
        if (!$term_id) continue;

        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) continue;

        $name = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');

        $translation = '';
        if ($enable_translation) {
            $t = get_term_meta($term_id, 'term_translation', true);
            if (!empty($t)) $translation = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }

        $items[] = array(
            'post_id'      => $post_id,
            'permalink'    => get_permalink($post_id),
            'slug'         => $term->slug,
            'term_id'      => $term_id,
            'name'         => $name,
            'translation'  => $translation,
            'display_name' => ($translation !== '' ? $translation : $name),
        );
    }

    usort($items, function ($a, $b) {
        return strnatcasecmp($a['display_name'], $b['display_name']);
    });

    return $items;
}

/**
 * Ensures the flashcard overlay shell (the same one used by [flashcard_widget]) exists once on the page,
 * and enqueues the flashcard assets + localization the scripts expect.
 *
 * Called only when [quiz_pages_grid popup="yes"] is used.
 */
function ll_qpg_bootstrap_flashcards_for_grid() {
    // Build the categories array exactly like the widget does (kept from your version)
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $target_language    = strtolower(get_option('ll_translation_language', 'en'));
    $site_language      = strtolower(get_locale());
    $use_translations   = $enable_translation && strpos($site_language, $target_language) === 0;

    $all_terms = get_terms(array('taxonomy' => 'word-category', 'hide_empty' => false));
    if (is_wp_error($all_terms)) $all_terms = array();

    if (!function_exists('ll_process_categories')) {
        $categories = array_map(function($t){
            return array(
                'id'          => $t->term_id,
                'slug'        => $t->slug,
                'name'        => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'translation' => html_entity_decode($t->name, ENT_QUOTES, 'UTF-8'),
                'mode'        => 'image',
            );
        }, $all_terms);
    } else {
        $categories = ll_process_categories($all_terms, $use_translations);
    }

    $atts = array('mode' => 'random');
    ll_flashcards_enqueue_and_localize($atts, $categories, false, array(), '');

    // Ensure the overlay DOM exists once per page (unchanged).
    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once');
}

/**
 * Prints the flashcard overlay DOM (same structure/IDs the widget expects) once.
 */
function ll_qpg_print_flashcard_shell_once() {
    static $printed = false;
    if ($printed) { return; }
    $printed = true;

    ?>
    <div id="ll-tools-flashcard-container" style="display:none;">
        <div id="ll-tools-flashcard-popup" style="display:none;">
            <div id="ll-tools-flashcard-quiz-popup" style="display:none;">

                <div id="ll-tools-flashcard-header" style="display:none;">
                    <div id="ll-tools-category-stack" class="ll-tools-category-stack">
                        <span id="ll-tools-category-display" class="ll-tools-category-display"></span>
                        <button id="ll-tools-repeat-flashcard" class="play-mode">
                            <span class="icon-container">
                                <img
                                    src="<?php echo esc_url( LL_TOOLS_BASE_URL . 'media/play-symbol.svg' ); ?>"
                                    alt="<?php esc_attr_e('Play', 'll-tools-text-domain'); ?>"
                                >
                            </span>
                        </button>
                    </div>

                    <div id="ll-tools-loading-animation" class="ll-tools-loading-animation"></div>
                    <button id="ll-tools-close-flashcard"
                            aria-label="<?php esc_attr_e('Close', 'll-tools-text-domain'); ?>">&times;</button>
                </div>

                <div id="ll-tools-flashcard-content">
                    <div id="ll-tools-flashcard"></div>
                    <audio controls class="hidden"></audio>
                </div>

                <div id="quiz-results" style="display:none;">
                    <h2 id="quiz-results-title"><?php esc_html_e('Quiz Results', 'll-tools-text-domain'); ?></h2>
                    <p id="quiz-results-message" style="display:none;"></p>
                    <p>
                        <strong><?php esc_html_e('Correct:', 'll-tools-text-domain'); ?></strong>
                        <span id="correct-count">0</span> / <span id="total-questions">0</span>
                    </p>
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
 * ------------------------------------------------------------------ */
function ll_quiz_pages_grid_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'columns'   => '',        // optional fixed column count
            'popup'     => 'no',      // "yes" => open flashcard overlay instead of navigating
            'order'     => 'title',   // ignored (kept for backward compat)
            'order_dir' => 'ASC',     // ignored (kept for backward compat)
        ),
        $atts,
        'quiz_pages_grid'
    );

    // Build list using the helper so translated display names are respected.
    $items = ll_get_all_quiz_pages_data();
    if ( empty( $items ) ) {
        return '<p>' . esc_html__( 'No quizzes found.', 'll-tools-text-domain' ) . '</p>';
    }

    $use_popup = ( strtolower( $atts['popup'] ) === 'yes' );
    $grid_id   = 'll-quiz-pages-grid-' . wp_generate_uuid4();

    // When using popup mode, make sure the flashcard overlay + assets exist.
    if ( $use_popup ) {
        ll_qpg_bootstrap_flashcards_for_grid();
    }

    // Optional fixed columns override.
    $style = '';
    if ( $atts['columns'] !== '' && is_numeric( $atts['columns'] ) && (int)$atts['columns'] > 0 ) {
        $cols  = (int) $atts['columns'];
        $style = ' style="grid-template-columns: repeat(' . $cols . ', minmax(220px, 1fr));"';
    }

    ob_start();

    echo '<div id="' . esc_attr( $grid_id ) . '" class="ll-quiz-pages-grid"' . $style . '>';

    foreach ( $items as $it ) {
        $title     = $it['display_name']; // what the user sees (translated when enabled)
        $permalink = $it['permalink'];
        $raw_name  = $it['name'];         // untranslated category name (used by flashcards)

        if ( $use_popup ) {
            // Popup mode: the CARD itself is a single <a> acting as a button.
            echo '<a class="ll-quiz-page-card ll-quiz-page-trigger"'
            . ' href="#"'
            . ' role="button"'
            . ' aria-label="Start ' . esc_attr( $title ) . '"'
            . ' data-category="' . esc_attr( $raw_name ) . '"'
            . ' onclick="llOpenFlashcardForCategory(' . wp_json_encode( $raw_name ) . '); return false;">';
            echo   '<span class="ll-quiz-page-name">' . esc_html( $title ) . '</span>';
            echo '</a>';
        } else {
            // Link mode: the CARD itself is a single <a>.
            echo '<a class="ll-quiz-page-card ll-quiz-page-link"'
            . ' href="' . esc_url( $permalink ) . '"'
            . ' aria-label="' . esc_attr( $title ) . '">';
            echo   '<span class="ll-quiz-page-name">' . esc_html( $title ) . '</span>';
            echo '</a>';
        }
    }

    echo '</div>';

    // Lightweight delegation for popup clicks (no duplicates, no nested links).
    if ( $use_popup ) {
        echo '<script>
        (function(){
            var grid = document.getElementById(' . json_encode( $grid_id ) . ');
            if (!grid) return;
            grid.addEventListener("click", function(e){
                var btn = e.target.closest(".ll-quiz-page-trigger");
                if (!btn || !grid.contains(btn)) return;
                e.preventDefault();
                var cat = btn.getAttribute("data-category");
                if (cat && typeof window.llOpenFlashcardForCategory === "function") {
                    window.llOpenFlashcardForCategory(cat);
                }
            });
        })();
        </script>';
    }

    return ob_get_clean();
}
add_shortcode( 'quiz_pages_grid', 'll_quiz_pages_grid_shortcode' );

/** ------------------------------------------------------------------
 * Shortcode: [quiz_pages_dropdown]
 * (unchanged – still navigates to the page)
 * ------------------------------------------------------------------ */
function ll_quiz_pages_dropdown_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'placeholder' => __('Select a quiz…', 'll-tools-text-domain'),
            'button'      => 'no',
        ),
        $atts,
        'quiz_pages_dropdown'
    );

    $items = ll_get_all_quiz_pages_data();

    ob_start();

    if (empty($items)) {
        echo '<p>' . esc_html__('No quiz pages are available yet.', 'll-tools-text-domain') . '</p>';
        return ob_get_clean();
    }

    $select_id   = 'll-quiz-pages-select-' . wp_generate_uuid4();
    $has_button  = strtolower($atts['button']) === 'yes';

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
add_shortcode('quiz_pages_dropdown', 'll_quiz_pages_dropdown_shortcode');

/**
 * Conditionally enqueue styles used by these shortcodes (grid/dropdown only).
 * The flashcard overlay uses its own stylesheet that we enqueue only in popup mode.
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
