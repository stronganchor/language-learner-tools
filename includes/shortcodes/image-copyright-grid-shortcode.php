<?php
/**
 * Provides a [image_copyright_grid] shortcode to display a grid of Word Images
 * that have non-empty "copyright_info" meta. Uses pagination.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM')) {
    define('LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM', 'll_img_q');
}
if (!defined('LL_IMAGE_COPYRIGHT_GRID_WORDSET_PARAM')) {
    define('LL_IMAGE_COPYRIGHT_GRID_WORDSET_PARAM', 'll_img_wordset');
}
if (!defined('LL_IMAGE_COPYRIGHT_GRID_CATEGORY_PARAM')) {
    define('LL_IMAGE_COPYRIGHT_GRID_CATEGORY_PARAM', 'll_img_category');
}

function ll_image_copyright_grid_get_page_number(): int {
    $paged = (int) get_query_var('paged');
    if ($paged <= 0) {
        $paged = (int) get_query_var('page');
    }

    return max(1, $paged);
}

function ll_image_copyright_grid_get_selected_term_id(string $param_name, string $taxonomy): int {
    $raw_value = isset($_GET[$param_name]) ? wp_unslash($_GET[$param_name]) : 0;
    $term_id = absint($raw_value);
    if ($term_id <= 0) {
        return 0;
    }

    $term = get_term($term_id, $taxonomy);
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return 0;
    }

    return (int) $term->term_id;
}

function ll_image_copyright_grid_get_filters(): array {
    $search = isset($_GET[LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM])
        ? sanitize_text_field(wp_unslash($_GET[LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM]))
        : '';

    return [
        'search' => trim($search),
        'wordset_id' => ll_image_copyright_grid_get_selected_term_id(LL_IMAGE_COPYRIGHT_GRID_WORDSET_PARAM, 'wordset'),
        'category_id' => ll_image_copyright_grid_get_selected_term_id(LL_IMAGE_COPYRIGHT_GRID_CATEGORY_PARAM, 'word-category'),
    ];
}

function ll_image_copyright_grid_get_filter_query_args(array $filters): array {
    $args = [];

    if ((string) ($filters['search'] ?? '') !== '') {
        $args[LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM] = (string) $filters['search'];
    }
    if ((int) ($filters['wordset_id'] ?? 0) > 0) {
        $args[LL_IMAGE_COPYRIGHT_GRID_WORDSET_PARAM] = (int) $filters['wordset_id'];
    }
    if ((int) ($filters['category_id'] ?? 0) > 0) {
        $args[LL_IMAGE_COPYRIGHT_GRID_CATEGORY_PARAM] = (int) $filters['category_id'];
    }

    return $args;
}

function ll_image_copyright_grid_get_form_action(): string {
    $queried_id = (int) get_queried_object_id();
    if ($queried_id > 0) {
        $permalink = get_permalink($queried_id);
        if (is_string($permalink) && $permalink !== '') {
            return remove_query_arg([
                LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM,
                LL_IMAGE_COPYRIGHT_GRID_WORDSET_PARAM,
                LL_IMAGE_COPYRIGHT_GRID_CATEGORY_PARAM,
            ], $permalink);
        }
    }

    return home_url('/');
}

function ll_image_copyright_grid_get_wordset_terms(): array {
    $terms = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }

    return array_values(array_filter($terms, static function ($term): bool {
        return $term instanceof WP_Term;
    }));
}

function ll_image_copyright_grid_get_category_terms(int $wordset_id): array {
    $terms = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }

    $terms = array_values(array_filter($terms, static function ($term) use ($wordset_id): bool {
        if (!($term instanceof WP_Term)) {
            return false;
        }

        if ($wordset_id <= 0 || !function_exists('ll_tools_get_category_wordset_owner_id')) {
            return true;
        }

        return (int) ll_tools_get_category_wordset_owner_id($term) === $wordset_id;
    }));

    usort($terms, static function (WP_Term $a, WP_Term $b): int {
        return strcasecmp($a->name, $b->name);
    });

    return $terms;
}

function ll_image_copyright_grid_get_category_label(WP_Term $term, int $wordset_id): string {
    if (function_exists('ll_tools_get_category_display_name')) {
        $label = (string) ll_tools_get_category_display_name($term, [
            'wordset_ids' => $wordset_id > 0 ? [$wordset_id] : [],
        ]);
        if ($label !== '') {
            return $label;
        }
    }

    return (string) $term->name;
}

function ll_image_copyright_grid_render_controls(array $filters): string {
    $selected_search = (string) ($filters['search'] ?? '');
    $selected_wordset_id = (int) ($filters['wordset_id'] ?? 0);
    $selected_category_id = (int) ($filters['category_id'] ?? 0);
    $wordsets = ll_image_copyright_grid_get_wordset_terms();
    $categories = ll_image_copyright_grid_get_category_terms($selected_wordset_id);
    $has_active_filters = $selected_search !== '' || $selected_wordset_id > 0 || $selected_category_id > 0;
    $clear_url = ll_image_copyright_grid_get_form_action();

    ob_start();
    ?>
    <form class="ll-image-copyright-grid__filters" method="get" action="<?php echo esc_url(ll_image_copyright_grid_get_form_action()); ?>">
        <div class="ll-image-copyright-grid__field ll-image-copyright-grid__field--search">
            <label for="ll-image-copyright-grid-search"><?php esc_html_e('Search', 'll-tools-text-domain'); ?></label>
            <input
                id="ll-image-copyright-grid-search"
                class="ll-image-copyright-grid__input"
                type="search"
                name="<?php echo esc_attr(LL_IMAGE_COPYRIGHT_GRID_SEARCH_PARAM); ?>"
                value="<?php echo esc_attr($selected_search); ?>"
                placeholder="<?php echo esc_attr__('Image, credit, source', 'll-tools-text-domain'); ?>"
            />
        </div>
        <div class="ll-image-copyright-grid__field">
            <label for="ll-image-copyright-grid-wordset"><?php esc_html_e('Word set', 'll-tools-text-domain'); ?></label>
            <select
                id="ll-image-copyright-grid-wordset"
                class="ll-image-copyright-grid__select"
                name="<?php echo esc_attr(LL_IMAGE_COPYRIGHT_GRID_WORDSET_PARAM); ?>"
            >
                <option value="0"><?php esc_html_e('All word sets', 'll-tools-text-domain'); ?></option>
                <?php foreach ($wordsets as $wordset) : ?>
                    <option value="<?php echo esc_attr((string) $wordset->term_id); ?>" <?php selected($selected_wordset_id, (int) $wordset->term_id); ?>>
                        <?php echo esc_html((string) $wordset->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-image-copyright-grid__field">
            <label for="ll-image-copyright-grid-category"><?php esc_html_e('Category', 'll-tools-text-domain'); ?></label>
            <select
                id="ll-image-copyright-grid-category"
                class="ll-image-copyright-grid__select"
                name="<?php echo esc_attr(LL_IMAGE_COPYRIGHT_GRID_CATEGORY_PARAM); ?>"
            >
                <option value="0"><?php esc_html_e('All categories', 'll-tools-text-domain'); ?></option>
                <?php foreach ($categories as $category) : ?>
                    <option value="<?php echo esc_attr((string) $category->term_id); ?>" <?php selected($selected_category_id, (int) $category->term_id); ?>>
                        <?php echo esc_html(ll_image_copyright_grid_get_category_label($category, $selected_wordset_id)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ll-image-copyright-grid__actions">
            <button type="submit" class="ll-image-copyright-grid__button"><?php esc_html_e('Filter', 'll-tools-text-domain'); ?></button>
            <?php if ($has_active_filters) : ?>
                <a class="ll-image-copyright-grid__clear" href="<?php echo esc_url($clear_url); ?>"><?php esc_html_e('Clear', 'll-tools-text-domain'); ?></a>
            <?php endif; ?>
        </div>
    </form>
    <?php

    return trim((string) ob_get_clean());
}

function ll_image_copyright_grid_render_source_label(int $index, int $total): string {
    if ($total > 1) {
        return sprintf(
            /* translators: %d is the source link number. */
            __('Source %d', 'll-tools-text-domain'),
            $index
        );
    }

    return __('Source', 'll-tools-text-domain');
}

function ll_image_copyright_grid_render_copyright_info(string $copyright): string {
    $copyright = trim($copyright);
    if ($copyright === '') {
        return '';
    }

    $matches = [];
    if (!preg_match_all('~https?://[^\s<>"\']+~i', $copyright, $matches, PREG_OFFSET_CAPTURE)) {
        return nl2br(esc_html($copyright));
    }

    $total_urls = count($matches[0]);
    $html = '';
    $last_offset = 0;
    $source_index = 1;

    foreach ($matches[0] as $match) {
        $url = (string) $match[0];
        $offset = (int) $match[1];
        $prefix = substr($copyright, $last_offset, $offset - $last_offset);

        $html .= nl2br(esc_html((string) $prefix));

        $safe_url = esc_url($url);
        if ($safe_url !== '') {
            $html .= '<a class="ll-image-copyright-source-link" href="' . $safe_url . '" target="_blank" rel="noopener noreferrer">';
            $html .= esc_html(ll_image_copyright_grid_render_source_label($source_index, $total_urls));
            $html .= '</a>';
            $source_index++;
        } else {
            $html .= esc_html($url);
        }

        $last_offset = $offset + strlen($url);
    }

    $html .= nl2br(esc_html((string) substr($copyright, $last_offset)));

    return $html;
}

function ll_image_copyright_grid_search_posts_join(string $join, WP_Query $query): string {
    if ((string) $query->get('ll_image_copyright_grid_search') === '') {
        return $join;
    }

    global $wpdb;

    if (strpos($join, 'll_image_copyright_grid_search_meta') !== false) {
        return $join;
    }

    return $join . " LEFT JOIN {$wpdb->postmeta} AS ll_image_copyright_grid_search_meta ON ({$wpdb->posts}.ID = ll_image_copyright_grid_search_meta.post_id AND ll_image_copyright_grid_search_meta.meta_key = 'copyright_info') ";
}
add_filter('posts_join', 'll_image_copyright_grid_search_posts_join', 10, 2);

function ll_image_copyright_grid_search_posts_where(string $where, WP_Query $query): string {
    $search = trim((string) $query->get('ll_image_copyright_grid_search'));
    if ($search === '') {
        return $where;
    }

    global $wpdb;

    $like = '%' . $wpdb->esc_like($search) . '%';

    return $where . $wpdb->prepare(
        " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR ll_image_copyright_grid_search_meta.meta_value LIKE %s)",
        $like,
        $like,
        $like,
        $like
    );
}
add_filter('posts_where', 'll_image_copyright_grid_search_posts_where', 10, 2);

function ll_image_copyright_grid_search_posts_groupby(string $groupby, WP_Query $query): string {
    if ((string) $query->get('ll_image_copyright_grid_search') === '') {
        return $groupby;
    }

    global $wpdb;

    $post_id_group = "{$wpdb->posts}.ID";
    if ($groupby === '') {
        return $post_id_group;
    }

    if (strpos($groupby, $post_id_group) === false) {
        return $groupby . ', ' . $post_id_group;
    }

    return $groupby;
}
add_filter('posts_groupby', 'll_image_copyright_grid_search_posts_groupby', 10, 2);

/**
 * Registers the [image_copyright_grid] shortcode.
 *
 * Usage example (shortcode attributes):
 *    [image_copyright_grid posts_per_page="12"]
 */
function ll_image_copyright_grid_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'posts_per_page' => 12, // How many images per page
        ),
        $atts,
        'image_copyright_grid'
    );

    $paged = ll_image_copyright_grid_get_page_number();
    $posts_per_page = max(1, (int) $atts['posts_per_page']);
    $filters = ll_image_copyright_grid_get_filters();

    // Query only Word Images that have a non-empty copyright_info
    $args = array(
        'post_type'      => 'word_images',
        'post_status'    => 'publish',
        'paged'          => $paged,
        'posts_per_page' => $posts_per_page,
        'meta_query'     => array(
            array(
                'key'     => 'copyright_info',
                'value'   => '',
                'compare' => '!=', // means key != ''
            ),
        ),
    );

    if ((string) $filters['search'] !== '') {
        $args['ll_image_copyright_grid_search'] = (string) $filters['search'];
    }

    if ((int) $filters['wordset_id'] > 0) {
        $args['meta_query'][] = [
            'key' => defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY') ? LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id',
            'value' => (int) $filters['wordset_id'],
            'compare' => '=',
            'type' => 'NUMERIC',
        ];
    }

    if ((int) $filters['category_id'] > 0) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [(int) $filters['category_id']],
                'include_children' => true,
            ],
        ];
    }

    $query = new WP_Query($args);

    // Start output buffering
    ob_start();

    echo ll_image_copyright_grid_render_controls($filters);

    if ($query->have_posts()) {
        echo '<div class="ll-image-copyright-grid">';
        while ($query->have_posts()) {
            $query->the_post();

            // Retrieve the copyright info
            $copyright = get_post_meta(get_the_ID(), 'copyright_info', true);

            echo '<div class="ll-image-copyright-grid-item">';
                // Show the featured image at a small size
                if (has_post_thumbnail()) {
                    echo '<div class="ll-image-wrapper">';
                        the_post_thumbnail(array(150, 150)); // or 'thumbnail'
                    echo '</div>';
                } else {
                    echo '<div class="ll-image-wrapper no-image">' . esc_html__('No Image', 'll-tools-text-domain') . '</div>';
                }

                // Optional title
                echo '<h4 class="ll-word-image-title">' . esc_html(get_the_title()) . '</h4>';

                // Copyright info
                if (!empty($copyright)) {
                    echo '<div class="ll-copyright-info">'
                        . ll_image_copyright_grid_render_copyright_info((string) $copyright)
                        . '</div>';
                }
            echo '</div>'; // .ll-image-copyright-grid-item
        }
        echo '</div>'; // .ll-image-copyright-grid

        // Pagination
        $big = 999999999; // need an unlikely integer
        $pagination = paginate_links(array(
            'base'      => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
            'format'    => '?paged=%#%',
            'current'   => $paged,
            'total'     => $query->max_num_pages,
            'prev_text' => '&laquo; ' . __('Previous', 'll-tools-text-domain'),
            'next_text' => __('Next', 'll-tools-text-domain') . ' &raquo;',
            'add_args'  => ll_image_copyright_grid_get_filter_query_args($filters),
        ));

        if ($pagination) {
            echo '<div class="ll-grid-pagination">';
            echo wp_kses_post($pagination);
            echo '</div>';
        }
    } else {
        echo '<p class="ll-image-copyright-grid__empty">' . esc_html__('No word images found with matching copyright info.', 'll-tools-text-domain') . '</p>';
    }

    // Reset query
    wp_reset_postdata();

    // Return buffered content
    return ob_get_clean();
}
add_shortcode('image_copyright_grid', 'll_image_copyright_grid_shortcode');


/**
 * Enqueues the CSS for the image copyright grid if the [image_copyright_grid] shortcode is used.
 */
function ll_maybe_enqueue_image_copyright_grid_styles() {
    // Only enqueue if we're on a singular page/post with the shortcode present
    if (is_singular()) {
        $post = get_post();
        if ($post && has_shortcode($post->post_content, 'image_copyright_grid')) {
            // Use your custom function that sets the version by filemtime
            ll_enqueue_asset_by_timestamp('/css/image-copyright-style.css', 'll-image-copyright-style');
        }
    }
}
add_action('wp_enqueue_scripts', 'll_maybe_enqueue_image_copyright_grid_styles');
