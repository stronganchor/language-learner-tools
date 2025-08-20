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
    // 1) Enqueue assets used by the flashcard widget
    wp_enqueue_script('jquery');
    ll_enqueue_asset_by_timestamp('/css/flashcard-style.css',       'll-tools-flashcard-style');
    ll_enqueue_asset_by_timestamp('/js/flashcard-audio.js',         'll-tools-flashcard-audio', array('jquery'), true);
    ll_enqueue_asset_by_timestamp('/js/flashcard-loader.js',        'll-tools-flashcard-loader', array('jquery'), true);
    ll_enqueue_asset_by_timestamp('/js/flashcard-options.js',       'll-tools-flashcard-options', array('jquery'), true);
    ll_enqueue_asset_by_timestamp('/js/flashcard-script.js',        'll-tools-flashcard-script', array('jquery','ll-tools-flashcard-audio','ll-tools-flashcard-loader','ll-tools-flashcard-options'), true);

    // 2) Build the categories array exactly like the widget does
    $enable_translation = get_option('ll_enable_category_translation', 0);
    $target_language = strtolower(get_option('ll_translation_language', 'en'));
    $site_language   = strtolower(get_locale());
    $use_translations = $enable_translation && strpos($site_language, $target_language) === 0;

    $all_terms = get_terms(array('taxonomy' => 'word-category', 'hide_empty' => false));
    if (is_wp_error($all_terms)) $all_terms = array();
    if (!function_exists('ll_process_categories')) {
        // Safety: if for some reason that function is not available, fall back to raw terms
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

    // 3) Localize the same object the widget uses
    $localized_data = array(
        'mode'                 => 'random',
        'plugin_dir'           => plugin_dir_url(__FILE__),
        'ajaxurl'              => admin_url('admin-ajax.php'),
        'categories'           => $categories,
        'isUserLoggedIn'       => is_user_logged_in(),
        'categoriesPreselected'=> false,
        'firstCategoryData'    => array(),  // we’ll let AJAX load
        'firstCategoryName'    => '',
        'imageSize'            => get_option('ll_flashcard_image_size', 'small'),
        'maxOptionsOverride'   => get_option('ll_max_options_override', 9),
    );
    wp_localize_script('ll-tools-flashcard-script',  'llToolsFlashcardsData', $localized_data);
    wp_localize_script('ll-tools-flashcard-options', 'llToolsFlashcardsData', $localized_data);

    wp_localize_script('ll-tools-flashcard-script', 'llToolsFlashcardsMessages', array(
        'perfect'               => __('Perfect!', 'll-tools-text-domain'),
        'goodJob'               => __('Good job!', 'll-tools-text-domain'),
        'keepPracticingTitle'   => __('Keep practicing!', 'll-tools-text-domain'),
        'keepPracticingMessage' => __('You\'re on the right track to get a higher score next time!', 'll-tools-text-domain'),
        'somethingWentWrong'    => __('Something went wrong, try again later.', 'll-tools-text-domain'),
    ));

    // 4) Print the overlay HTML shell once at footer, if not printed yet
    add_action('wp_footer', 'll_qpg_print_flashcard_shell_once');
}

/**
 * Prints the flashcard overlay DOM (same structure/IDs the widget expects) once.
 */
function ll_qpg_print_flashcard_shell_once() {
    static $printed = false;
    if ($printed) { return; }
    $printed = true;

    // Build a base URL that points to the plugin ROOT (so /media/*.svg resolves correctly)
    // This file lives in /pages/, so hop up one level to the main plugin file.
    $plugin_main_file = dirname(__FILE__) . '/../language-learner-tools.php';
    if ( file_exists( $plugin_main_file ) ) {
        $lltools_base_url = plugin_dir_url( $plugin_main_file );
    } else {
        // Fallback: derive from two levels up if the main file isn’t where we expect.
        $lltools_base_url = trailingslashit( plugins_url( '', dirname(__FILE__, 2) . '/language-learner-tools.php' ) );
    }
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
                                    src="<?php echo esc_url( $lltools_base_url . 'media/play-symbol.svg' ); ?>"
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
function ll_quiz_pages_grid_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'columns' => '',
            'popup'   => 'no', // when "yes", reuse flashcard popup
        ),
        $atts,
        'quiz_pages_grid'
    );

    $items = ll_get_all_quiz_pages_data();

    ob_start();

    if (empty($items)) {
        echo '<p>' . esc_html__('No quiz pages are available yet.', 'll-tools-text-domain') . '</p>';
        return ob_get_clean();
    }

    $columns = is_numeric($atts['columns']) ? max(2, (int) $atts['columns']) : 0;
    $style   = $columns > 0
        ? 'grid-template-columns: repeat(' . $columns . ', minmax(180px, 1fr));'
        : '';

    $use_popup = in_array(strtolower(trim($atts['popup'])), array('1','true','yes','on'), true);

    // If using popup, make sure flashcard overlay + assets are ready
    if ($use_popup) {
        ll_qpg_bootstrap_flashcards_for_grid();
    }

    $instance_id = wp_generate_uuid4();
    $grid_id     = 'll-quiz-pages-grid-' . $instance_id;

    echo '<div id="' . esc_attr($grid_id) . '" class="ll-quiz-pages-grid" style="' . esc_attr($style) . '">';

    foreach ($items as $it) {
        $label = $it['display_name'];

        if ($use_popup) {
            // One element only – the whole tile is the trigger
            echo '<a href="#" class="ll-quiz-page-card ll-quiz-page-trigger" data-category="' . esc_attr($it['name']) . '">';
            echo '  <h3 class="ll-quiz-page-name">' . esc_html($label) . '</h3>';
            echo '</a>';
        } else {
            echo '<a class="ll-quiz-page-card" href="' . esc_url($it['permalink']) . '">';
            echo '  <h3 class="ll-quiz-page-name">' . esc_html($label) . '</h3>';
            echo '</a>';
        }
    }

    echo '</div>';

    if ($use_popup) {
        // Lightweight instance-scoped JS to wire click → open flashcard overlay
        echo '<script>
        (function(){
        var grid = document.getElementById(' . json_encode($grid_id) . ');
        if (!grid) return;
        grid.addEventListener("click", function(e){
            var btn = e.target.closest(".ll-quiz-page-trigger");
            if (!btn) return;
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
add_shortcode('quiz_pages_grid', 'll_quiz_pages_grid_shortcode');

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
