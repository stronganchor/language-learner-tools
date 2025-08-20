<?php
/**
 * Shortcodes to list auto-generated quiz pages:
 *  - [quiz_pages_grid]
 *  - [quiz_pages_dropdown]
 *
 * A "quiz page" is any WP Page created by the plugin for a word-category
 * and marked with meta key _ll_tools_word_category_id.
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Fetches all published quiz pages and returns display data.
 *
 * @return array[] Each item: [
 *   'post_id'      => int,
 *   'permalink'    => string,
 *   'term_id'      => int,
 *   'name'         => string,   // original term name
 *   'translation'  => string,   // translated term name (may be '')
 *   'display_name' => string,   // translation if available (and enabled), else name
 * ]
 */
function ll_get_all_quiz_pages_data() {
    // Find pages that were auto-created for categories
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'meta_key'       => '_ll_tools_word_category_id',
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));

    if (empty($pages)) {
        return array();
    }

    $enable_translation = get_option('ll_enable_category_translation', 0);

    $items = array();

    foreach ($pages as $post_id) {
        $term_id = (int) get_post_meta($post_id, '_ll_tools_word_category_id', true);
        if (!$term_id) {
            continue;
        }

        $term = get_term($term_id, 'word-category');
        if (!$term || is_wp_error($term)) {
            continue;
        }

        $name = html_entity_decode($term->name, ENT_QUOTES, 'UTF-8');

        $translation = '';
        if ($enable_translation) {
            $translation_meta = get_term_meta($term_id, 'term_translation', true);
            if (!empty($translation_meta)) {
                $translation = html_entity_decode($translation_meta, ENT_QUOTES, 'UTF-8');
            }
        }

        $display = $translation !== '' ? $translation : $name;

        $items[] = array(
            'post_id'      => $post_id,
            'permalink'    => get_permalink($post_id),
            'term_id'      => $term_id,
            'name'         => $name,
            'translation'  => $translation,
            'display_name' => $display,
        );
    }

    // Sort by display name (case-insensitive, natural)
    usort($items, function ($a, $b) {
        return strnatcasecmp($a['display_name'], $b['display_name']);
    });

    return $items;
}

/**
 * [quiz_pages_grid] — Displays cards linking to each quiz page.
 *
 * @param array $atts {
 *   @type int|string $columns Optional. Fixed column count (>=2). If omitted or invalid, uses responsive auto-fill.
 * }
 * @return string
 */
function ll_quiz_pages_grid_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'columns' => '',
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

    // Optional fixed columns (>=2). Otherwise CSS handles auto-fill.
    $columns = is_numeric($atts['columns']) ? max(2, (int) $atts['columns']) : 0;
    $style   = $columns > 0
        ? 'grid-template-columns: repeat(' . $columns . ', minmax(180px, 1fr));'
        : '';

    echo '<div class="ll-quiz-pages-grid" style="' . esc_attr($style) . '">';

    foreach ($items as $it) {
        $label = $it['display_name'];
        $url   = $it['permalink'];

        echo '<div class="ll-quiz-page-card">';
        echo '  <a class="ll-quiz-page-link" href="' . esc_url($url) . '">';
        echo '      <h3 class="ll-quiz-page-name">' . esc_html($label) . '</h3>';
        echo '  </a>';
        echo '</div>';
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_grid', 'll_quiz_pages_grid_shortcode');

/**
 * [quiz_pages_dropdown] — Displays a dropdown that navigates to a quiz page on change.
 *
 * @param array $atts {
 *   @type string $placeholder Optional. Placeholder label (default "Select a quiz…").
 *   @type string $button      Optional. If "yes", shows a Go button instead of auto-navigating on change.
 * }
 * @return string
 */
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
        $label = $it['display_name'];
        $url   = $it['permalink'];
        echo '<option value="' . esc_url($url) . '">' . esc_html($label) . '</option>';
    }

    echo '</select>';

    if ($has_button) {
        $btn_id = 'll-quiz-pages-go-' . wp_generate_uuid4();
        echo '<button id="' . esc_attr($btn_id) . '" class="ll-quiz-pages-go">'
           . esc_html__('Go', 'll-tools-text-domain') . '</button>';

        // Lightweight inline script for button-based navigation
        echo '<script>
            (function(){
                var sel = document.getElementById(' . json_encode($select_id) . ');
                var btn = document.getElementById(' . json_encode($btn_id) . ');
                if (sel && btn) {
                    btn.addEventListener("click", function(){
                        if (sel.value) { window.location.href = sel.value; }
                    });
                }
            })();
        </script>';
    }

    echo '</div>';

    return ob_get_clean();
}
add_shortcode('quiz_pages_dropdown', 'll_quiz_pages_dropdown_shortcode');

/**
 * Conditionally enqueue styles for these shortcodes.
 */
function ll_maybe_enqueue_quiz_pages_styles() {
    if (!is_singular()) {
        return;
    }
    $post = get_post();
    if (!$post) {
        return;
    }

    if (
        has_shortcode($post->post_content, 'quiz_pages_grid') ||
        has_shortcode($post->post_content, 'quiz_pages_dropdown')
    ) {
        // Uses the helper defined in the main plugin file
        ll_enqueue_asset_by_timestamp('/css/quiz-pages-style.css', 'll-quiz-pages-style');
    }
}
add_action('wp_enqueue_scripts', 'll_maybe_enqueue_quiz_pages_styles');
