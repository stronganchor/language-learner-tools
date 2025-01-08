<?php
/**
 * Provides a [image_copyright_grid] shortcode to display a grid of Word Images
 * that have non-empty "copyright_info" meta. Uses pagination.
 */

/**
 * Registers the [image_copyright_grid] shortcode.
 *
 * Usage example (shortcode attributes):
 *    [image_copyright_grid posts_per_page="12"]
 */
function ll_image_copyright_grid_shortcode($atts) {
    // Parse shortcode attributes; default posts_per_page = 12
    $atts = shortcode_atts(array(
        'posts_per_page' => 12, // How many images per page
    ), $atts, 'image_copyright_grid');

    // Figure out the current page for pagination
    // (Using 'paged' query var is the most common approach in WP)
    $paged = get_query_var('paged') ? (int)get_query_var('paged') : 1;

    // Query only Word Images that have a non-empty copyright_info
    $args = array(
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'paged'          => $paged,
        'posts_per_page' => (int)$atts['posts_per_page'],
        'meta_query'     => array(
            array(
                'key'     => 'copyright_info',
                'value'   => '',      // Compare with empty string
                'compare' => '!=',    // "!=" means we only want rows where key != ''
            ),
        ),
    );
    $query = new WP_Query($args);

    // Begin output buffering so we can return the final HTML
    ob_start();

    if ($query->have_posts()) {
        echo '<div class="ll-image-copyright-grid">';
        while ($query->have_posts()) {
            $query->the_post();

            // Retrieve the copyright info
            $copyright = get_post_meta(get_the_ID(), 'copyright_info', true);

            echo '<div class="ll-image-copyright-grid-item">';
                // Show the featured image at a small size
                // e.g., "thumbnail", or use array(150, 150) for exact dimensions
                if (has_post_thumbnail()) {
                    echo '<div class="ll-image-wrapper">';
                        the_post_thumbnail(array(150, 150)); // or 'thumbnail'
                    echo '</div>';
                } else {
                    echo '<div class="ll-image-wrapper no-image">No Image</div>';
                }

                // Title (optional)
                echo '<h4 class="ll-word-image-title">' . get_the_title() . '</h4>';

                // Copyright info
                if (!empty($copyright)) {
                    echo '<div class="ll-copyright-info">'
                        . esc_html($copyright)
                        . '</div>';
                }
            echo '</div>'; // .ll-image-copyright-grid-item
        }
        echo '</div>'; // .ll-image-copyright-grid

        // Pagination
        $big = 999999999; // need an unlikely integer
        echo '<div class="ll-grid-pagination">';
        echo paginate_links(array(
            'base'      => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
            'format'    => '?paged=%#%',
            'current'   => $paged,
            'total'     => $query->max_num_pages,
            'prev_text' => __('« Prev'),
            'next_text' => __('Next »'),
        ));
        echo '</div>';
    } else {
        echo '<p>No Word Images found with copyright info.</p>';
    }

    // Reset query so other loops/pages aren’t affected
    wp_reset_postdata();

    // Return our buffered content
    return ob_get_clean();
}
add_shortcode('image_copyright_grid', 'll_image_copyright_grid_shortcode');


