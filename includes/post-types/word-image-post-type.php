<?php

// Register the "word_images" custom post type
function ll_tools_register_word_images_post_type() {
    $labels = [
        "name" => esc_html__("Word Images", "ll-tools-text-domain"),
        "singular_name" => esc_html__("Word Image", "ll-tools-text-domain"),
    ];

    $args = [
        "label" => esc_html__("Word Images", "ll-tools-text-domain"),
        "labels" => $labels,
        "description" => "",
        "public" => true,
        "publicly_queryable" => true,
        "show_ui" => true,
        "show_in_rest" => true,
        "rest_base" => "",
        "rest_controller_class" => "WP_REST_Posts_Controller",
        "rest_namespace" => "wp/v2",
        "has_archive" => false,
        "show_in_menu" => true,
        "show_in_nav_menus" => true,
        "delete_with_user" => false,
        "exclude_from_search" => false,
        "capability_type" => "post",
        "map_meta_cap" => true,
        "hierarchical" => false,
        "can_export" => false,
        "rewrite" => ["slug" => "word-images", "with_front" => true],
        "query_var" => true,
        "supports" => ["title", "thumbnail"],
        "show_in_graphql" => false,
    ];

    register_post_type("word_images", $args);
}
add_action('init', 'll_tools_register_word_images_post_type');

/**
 * Attachment metadata helpers.
 */
function ll_tools_get_external_attachment_source_url($attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }

    $url = get_post_meta($attachment_id, '_ll_tools_external_source_url', true);
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (strpos($url, '//') === 0) {
        $url = (is_ssl() ? 'https:' : 'http:') . $url;
    }

    $url = esc_url_raw($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }

    $validated = wp_http_validate_url($url);
    return is_string($validated) ? $validated : '';
}

function ll_tools_filter_external_attachment_url($url, $attachment_id) {
    $external_url = ll_tools_get_external_attachment_source_url((int) $attachment_id);
    return $external_url !== '' ? $external_url : $url;
}
add_filter('wp_get_attachment_url', 'll_tools_filter_external_attachment_url', 5, 2);

function ll_tools_image_downsize_external_attachment($downsize, $id, $size) {
    if ($downsize) {
        return $downsize;
    }

    $attachment_id = (int) $id;
    if ($attachment_id <= 0) {
        return false;
    }

    $external_url = ll_tools_get_external_attachment_source_url($attachment_id);
    if ($external_url === '') {
        return false;
    }

    $attachment = get_post($attachment_id);
    if (!$attachment || $attachment->post_type !== 'attachment') {
        return false;
    }

    $mime_type = (string) $attachment->post_mime_type;
    if ($mime_type !== '' && strpos($mime_type, 'image/') !== 0) {
        return false;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    $width = is_array($metadata) ? (int) ($metadata['width'] ?? 0) : 0;
    $height = is_array($metadata) ? (int) ($metadata['height'] ?? 0) : 0;

    if ($width > 0 || $height > 0) {
        list($width, $height) = image_constrain_size_for_editor($width, $height, $size);
    }

    return [$external_url, max(0, $width), max(0, $height), false];
}
add_filter('image_downsize', 'll_tools_image_downsize_external_attachment', 10, 3);

function ll_tools_attachment_metadata_needs_refresh($metadata) {
    if (!is_array($metadata)) {
        return true;
    }

    $width = isset($metadata['width']) ? (int) $metadata['width'] : 0;
    $height = isset($metadata['height']) ? (int) $metadata['height'] : 0;

    return ($width <= 1 || $height <= 1);
}

function ll_tools_maybe_regenerate_attachment_metadata($attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
        return false;
    }

    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!ll_tools_attachment_metadata_needs_refresh($metadata)) {
        return false;
    }

    $file_path = get_attached_file($attachment_id, true);
    if (!$file_path || !file_exists($file_path) || !is_readable($file_path)) {
        return false;
    }

    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $generated = wp_generate_attachment_metadata($attachment_id, $file_path);
    if (empty($generated) || !is_array($generated)) {
        return false;
    }

    $generated_width = isset($generated['width']) ? (int) $generated['width'] : 0;
    $generated_height = isset($generated['height']) ? (int) $generated['height'] : 0;
    if ($generated_width <= 0 || $generated_height <= 0) {
        return false;
    }

    wp_update_attachment_metadata($attachment_id, $generated);
    clean_post_cache($attachment_id);
    return true;
}

function ll_tools_repair_post_thumbnail_attachment($post_id) {
    $thumb_id = (int) get_post_thumbnail_id((int) $post_id);
    if ($thumb_id <= 0) {
        return false;
    }
    return ll_tools_maybe_regenerate_attachment_metadata($thumb_id);
}

function ll_tools_get_post_thumbnail_html_with_repair($post_id, $size = 'post-thumbnail', $attr = []) {
    ll_tools_repair_post_thumbnail_attachment((int) $post_id);
    return get_the_post_thumbnail((int) $post_id, $size, $attr);
}

/**
 * Ensure the Featured Image metabox preview doesn't render with stale/bad dimensions.
 */
function ll_tools_fix_featured_image_box_preview($content, $post_id, $thumbnail_id) {
    $post = get_post((int) $post_id);
    if (!$post || !in_array($post->post_type, ['words', 'word_images'], true)) {
        return $content;
    }
    if ((int) $thumbnail_id <= 0) {
        return $content;
    }
    if (!ll_tools_maybe_regenerate_attachment_metadata((int) $thumbnail_id)) {
        return $content;
    }
    if (!function_exists('_wp_post_thumbnail_html')) {
        return $content;
    }

    static $is_refreshing = false;
    if ($is_refreshing) {
        return $content;
    }

    $is_refreshing = true;
    $refreshed = _wp_post_thumbnail_html((int) $thumbnail_id, (int) $post_id);
    $is_refreshing = false;

    return is_string($refreshed) && $refreshed !== '' ? $refreshed : $content;
}
add_filter('admin_post_thumbnail_html', 'll_tools_fix_featured_image_box_preview', 10, 3);

/**
 * Keep word thumbnails in sync when a word_images featured image is replaced.
 */
function ll_tools_word_image_thumbnail_change_track_update($check, $object_id, $meta_key, $meta_value, $prev_value) {
    if ($meta_key !== '_thumbnail_id') {
        return $check;
    }

    $post = get_post((int) $object_id);
    if (!$post || $post->post_type !== 'word_images') {
        return $check;
    }

    $old_attachment_id = (int) get_post_meta((int) $object_id, '_thumbnail_id', true);
    $new_attachment_id = (int) $meta_value;
    if ($old_attachment_id === $new_attachment_id) {
        return $check;
    }

    if (!isset($GLOBALS['ll_tools_word_image_thumb_changes']) || !is_array($GLOBALS['ll_tools_word_image_thumb_changes'])) {
        $GLOBALS['ll_tools_word_image_thumb_changes'] = [];
    }

    $GLOBALS['ll_tools_word_image_thumb_changes'][(int) $object_id] = [
        'old' => $old_attachment_id,
        'new' => $new_attachment_id,
    ];

    return $check;
}
add_filter('update_post_metadata', 'll_tools_word_image_thumbnail_change_track_update', 10, 5);

function ll_tools_get_connected_word_ids_for_word_image_sync($word_image_id, $old_attachment_id = 0) {
    $word_image_id = (int) $word_image_id;
    $old_attachment_id = (int) $old_attachment_id;
    if ($word_image_id <= 0) {
        return [];
    }

    $owner_wordset_id = function_exists('ll_tools_get_word_image_wordset_owner_id')
        ? (int) ll_tools_get_word_image_wordset_owner_id($word_image_id)
        : 0;

    $connected_query = [
        'post_type'         => 'words',
        'post_status'       => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'    => -1,
        'fields'            => 'ids',
        'no_found_rows'     => true,
        'suppress_filters'  => true,
        'meta_query'        => [
            [
                'key'   => '_ll_autopicked_image_id',
                'value' => $word_image_id,
            ],
        ],
    ];
    if ($owner_wordset_id > 0) {
        $connected_query['tax_query'] = [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$owner_wordset_id],
            ],
        ];
    }

    $connected = get_posts($connected_query);

    $ids = array_map('intval', (array) $connected);

    if ($old_attachment_id > 0) {
        $thumbnail_query = [
            'post_type'         => 'words',
            'post_status'       => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page'    => -1,
            'fields'            => 'ids',
            'no_found_rows'     => true,
            'suppress_filters'  => true,
            'meta_query'        => [
                [
                    'key'   => '_thumbnail_id',
                    'value' => $old_attachment_id,
                ],
            ],
        ];

        if ($owner_wordset_id > 0) {
            $thumbnail_query['tax_query'] = [
                'relation' => 'AND',
                [
                    'taxonomy' => 'wordset',
                    'field'    => 'term_id',
                    'terms'    => [$owner_wordset_id],
                ],
            ];
        }

        $image_category_ids = wp_get_post_terms($word_image_id, 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($image_category_ids) && !empty($image_category_ids)) {
            $category_tax_query = [
                [
                    'taxonomy' => 'word-category',
                    'field'    => 'term_id',
                    'terms'    => array_map('intval', $image_category_ids),
                ],
            ];
            if (!empty($thumbnail_query['tax_query'])) {
                $thumbnail_query['tax_query'][] = $category_tax_query[0];
            } else {
                $thumbnail_query['tax_query'] = $category_tax_query;
            }
        }

        $by_thumbnail = get_posts($thumbnail_query);

        foreach ((array) $by_thumbnail as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id <= 0) {
                continue;
            }

            $linked_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
            if ($linked_image_id > 0 && $linked_image_id !== $word_image_id) {
                continue;
            }

            $ids[] = $word_id;
        }
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
        return $id > 0;
    })));

    return $ids;
}

function ll_tools_sync_words_for_word_image_thumbnail_change($word_image_id, $old_attachment_id, $new_attachment_id) {
    $word_image_id = (int) $word_image_id;
    $old_attachment_id = (int) $old_attachment_id;
    $new_attachment_id = (int) $new_attachment_id;

    if ($word_image_id <= 0 || $old_attachment_id === $new_attachment_id) {
        return 0;
    }

    $word_ids = ll_tools_get_connected_word_ids_for_word_image_sync($word_image_id, $old_attachment_id);
    if (empty($word_ids)) {
        return 0;
    }

    $updated = 0;
    foreach ($word_ids as $word_id) {
        if ($new_attachment_id > 0) {
            set_post_thumbnail((int) $word_id, $new_attachment_id);
        } else {
            delete_post_thumbnail((int) $word_id);
        }

        update_post_meta((int) $word_id, '_ll_autopicked_image_id', $word_image_id);
        $updated++;
    }

    return $updated;
}

/**
 * Resolve the best linked word_images post for a word.
 */
function ll_tools_get_linked_word_image_post_id_for_word($word_id) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return 0;
    }

    $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
        ? ll_tools_get_post_wordset_ids($word_id)
        : [];
    $primary_wordset_id = function_exists('ll_tools_get_primary_wordset_id_for_post')
        ? (int) ll_tools_get_primary_wordset_id_for_post($word_id)
        : (int) ($wordset_ids[0] ?? 0);

    $linked_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
    if ($linked_image_id > 0) {
        $linked_post = get_post($linked_image_id);
        if ($linked_post && $linked_post->post_type === 'word_images') {
            $owner_wordset_id = function_exists('ll_tools_get_word_image_wordset_owner_id')
                ? (int) ll_tools_get_word_image_wordset_owner_id($linked_image_id)
                : 0;
            if ($owner_wordset_id <= 0 || empty($wordset_ids) || in_array($owner_wordset_id, $wordset_ids, true)) {
                return $linked_image_id;
            }

            if ($primary_wordset_id > 0 && function_exists('ll_tools_get_existing_isolated_word_image_copy_id') && function_exists('ll_tools_get_word_image_isolation_source_id')) {
                $existing_copy_id = ll_tools_get_existing_isolated_word_image_copy_id(
                    ll_tools_get_word_image_isolation_source_id($linked_image_id),
                    $primary_wordset_id
                );
                if ($existing_copy_id > 0) {
                    return $existing_copy_id;
                }
            }
        }
    }

    $thumbnail_id = (int) get_post_thumbnail_id($word_id);
    if ($thumbnail_id <= 0) {
        return 0;
    }

    $query_args = [
        'post_type'         => 'word_images',
        'post_status'       => ['publish', 'draft', 'pending', 'future', 'private'],
        'posts_per_page'    => 1,
        'fields'            => 'ids',
        'no_found_rows'     => true,
        'suppress_filters'  => true,
        'orderby'           => 'date',
        'order'             => 'ASC',
        'meta_query'        => [
            [
                'key'   => '_thumbnail_id',
                'value' => $thumbnail_id,
            ],
        ],
    ];

    $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($category_ids) && !empty($category_ids)) {
        $query_args['tax_query'] = [
            [
                'taxonomy' => 'word-category',
                'field'    => 'term_id',
                'terms'    => array_map('intval', $category_ids),
            ],
        ];
    }

    $matches = [];
    if ($primary_wordset_id > 0 && function_exists('ll_tools_is_wordset_isolation_enabled') && ll_tools_is_wordset_isolation_enabled()) {
        $owner_only_meta_query = $query_args['meta_query'];
        $owner_only_meta_query['relation'] = 'AND';
        $owner_only_meta_query[] = [
            [
                'key'   => LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY,
                'value' => $primary_wordset_id,
            ],
        ];

        $owner_only_query_args = $query_args;
        $owner_only_query_args['meta_query'] = $owner_only_meta_query;
        $matches = get_posts($owner_only_query_args);
    }

    if (empty($matches) && $primary_wordset_id > 0 && function_exists('ll_tools_get_word_image_owner_meta_query')) {
        $scoped_meta_query = $query_args['meta_query'];
        $scoped_meta_query['relation'] = 'AND';
        $scoped_meta_query[] = ll_tools_get_word_image_owner_meta_query([$primary_wordset_id], true);
        $scoped_query_args = $query_args;
        $scoped_query_args['meta_query'] = $scoped_meta_query;
        $matches = get_posts($scoped_query_args);
    }

    if (empty($matches)) {
        $matches = get_posts($query_args);
    }

    $resolved_id = !empty($matches) ? (int) $matches[0] : 0;
    if ($resolved_id > 0) {
        update_post_meta($word_id, '_ll_autopicked_image_id', $resolved_id);
    }

    return $resolved_id;
}

/**
 * Ensure a word has a linked word_images post so image replacement can update the shared image record.
 *
 * @param int $word_id
 * @return int|WP_Error
 */
function ll_tools_ensure_word_image_post_for_word($word_id) {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return new WP_Error('ll_word_image_invalid_word', __('Invalid word.', 'll-tools-text-domain'));
    }

    $primary_wordset_id = function_exists('ll_tools_get_primary_wordset_id_for_post')
        ? (int) ll_tools_get_primary_wordset_id_for_post($word_id)
        : 0;
    $existing_id = ll_tools_get_linked_word_image_post_id_for_word($word_id);
    if ($existing_id > 0) {
        if ($primary_wordset_id > 0 && function_exists('ll_tools_get_effective_word_image_id_for_wordset')) {
            $effective_existing_id = (int) ll_tools_get_effective_word_image_id_for_wordset($existing_id, $primary_wordset_id);
            if ($effective_existing_id > 0) {
                return $effective_existing_id;
            }
        }
        return $existing_id;
    }

    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        return new WP_Error('ll_word_image_invalid_word', __('Invalid word.', 'll-tools-text-domain'));
    }

    $display_title = '';
    if (function_exists('ll_tools_word_grid_resolve_display_text')) {
        $display_values = ll_tools_word_grid_resolve_display_text($word_id);
        $display_title = trim((string) ($display_values['word_text'] ?? ''));
    }
    if ($display_title === '') {
        $display_title = trim((string) get_the_title($word_id));
    }
    if ($display_title === '') {
        $display_title = __('Untitled image', 'll-tools-text-domain');
    }

    $insert_args = [
        'post_type'   => 'word_images',
        'post_status' => 'publish',
        'post_title'  => $display_title,
    ];
    if ($primary_wordset_id > 0 && function_exists('ll_tools_is_wordset_isolation_enabled') && ll_tools_is_wordset_isolation_enabled()) {
        $insert_args['post_name'] = ll_tools_build_isolated_word_image_slug(sanitize_title($display_title), $primary_wordset_id);
    }

    $word_image_id = wp_insert_post($insert_args, true);
    if (is_wp_error($word_image_id) || !$word_image_id) {
        return is_wp_error($word_image_id)
            ? $word_image_id
            : new WP_Error('ll_word_image_create_failed', __('Could not create the linked word image.', 'll-tools-text-domain'));
    }

    $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
    if (!is_wp_error($category_ids) && !empty($category_ids)) {
        if ($primary_wordset_id > 0 && function_exists('ll_tools_get_isolated_category_ids_for_wordsets')) {
            $category_ids = ll_tools_get_isolated_category_ids_for_wordsets(array_map('intval', (array) $category_ids), [$primary_wordset_id]);
        }
        wp_set_post_terms((int) $word_image_id, array_map('intval', $category_ids), 'word-category', false);
    }

    if ($primary_wordset_id > 0 && function_exists('ll_tools_set_word_image_wordset_owner')) {
        ll_tools_set_word_image_wordset_owner((int) $word_image_id, $primary_wordset_id, (int) $word_image_id);
    }

    update_post_meta($word_id, '_ll_autopicked_image_id', (int) $word_image_id);

    $thumbnail_id = (int) get_post_thumbnail_id($word_id);
    if ($thumbnail_id > 0) {
        set_post_thumbnail((int) $word_image_id, $thumbnail_id);
    }

    return (int) $word_image_id;
}

function ll_tools_word_image_thumbnail_change_on_added($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_thumbnail_id') {
        return;
    }

    $post = get_post((int) $object_id);
    if (!$post || $post->post_type !== 'word_images') {
        return;
    }

    $new_attachment_id = (int) $meta_value;
    ll_tools_sync_words_for_word_image_thumbnail_change((int) $object_id, 0, $new_attachment_id);
}
add_action('added_post_meta', 'll_tools_word_image_thumbnail_change_on_added', 10, 4);

function ll_tools_word_image_thumbnail_change_on_updated($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_thumbnail_id') {
        return;
    }

    $post = get_post((int) $object_id);
    if (!$post || $post->post_type !== 'word_images') {
        return;
    }

    $old_attachment_id = 0;
    if (isset($GLOBALS['ll_tools_word_image_thumb_changes'][(int) $object_id]['old'])) {
        $old_attachment_id = (int) $GLOBALS['ll_tools_word_image_thumb_changes'][(int) $object_id]['old'];
    }
    unset($GLOBALS['ll_tools_word_image_thumb_changes'][(int) $object_id]);

    $new_attachment_id = (int) $meta_value;
    ll_tools_sync_words_for_word_image_thumbnail_change((int) $object_id, $old_attachment_id, $new_attachment_id);
}
add_action('updated_post_meta', 'll_tools_word_image_thumbnail_change_on_updated', 10, 4);

function ll_tools_word_image_thumbnail_change_on_deleted($meta_ids, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_thumbnail_id') {
        return;
    }

    $post = get_post((int) $object_id);
    if (!$post || $post->post_type !== 'word_images') {
        return;
    }

    ll_tools_sync_words_for_word_image_thumbnail_change((int) $object_id, (int) $meta_value, 0);
}
add_action('deleted_post_meta', 'll_tools_word_image_thumbnail_change_on_deleted', 10, 4);

/**
 * Repair attached thumbnail metadata when words or word_images posts are saved.
 */
function ll_tools_repair_featured_image_metadata_on_save($post_id, $post) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!$post || !in_array($post->post_type, ['words', 'word_images'], true)) {
        return;
    }

    ll_tools_repair_post_thumbnail_attachment((int) $post_id);
}
add_action('save_post_words', 'll_tools_repair_featured_image_metadata_on_save', 20, 2);
add_action('save_post_word_images', 'll_tools_repair_featured_image_metadata_on_save', 20, 2);


/**
 * 1) ADD A META BOX FOR WORD IMAGE METADATA
 *    (So we can see & edit "copyright_info" in the Edit Word Image screen)
 */

// Hook: Add our meta box to the Edit screen for Word Images
add_action('add_meta_boxes_word_images', 'll_add_word_image_meta_box');
function ll_add_word_image_meta_box() {
    add_meta_box(
        'll_word_image_metadata_box',   // Unique ID for the meta box
        'Word Image Metadata',          // Box title
        'll_word_image_metadata_cb',    // Callback to render the box’s HTML
        'word_images',                  // Post type
        'side',                         // Context (side, normal, advanced)
        'default'                       // Priority
    );
}

/**
 * Callback to display the custom fields (e.g., "copyright_info") in the meta box.
 *
 * @param WP_Post $post Current post object.
 */
function ll_word_image_metadata_cb($post) {
    // Security: Add a nonce for form validation later
    wp_nonce_field('ll_word_image_metadata_save', 'll_word_image_metadata_nonce');

    // Retrieve the current value of copyright_info
    $copyright_info = get_post_meta($post->ID, 'copyright_info', true);

    echo '<p><label for="ll_copyright_info"><strong>Copyright Info:</strong></label></p>';
    echo '<textarea id="ll_copyright_info" name="ll_copyright_info" rows="3" style="width:100%;">';
    echo esc_textarea($copyright_info);
    echo '</textarea>';
    echo '<p class="howto">Edit or add any copyright or source info for this image.</p>';
    echo '<p class="howto">' . esc_html__('Replacing this featured image also updates connected word posts automatically.', 'll-tools-text-domain') . '</p>';
}

/**
 * Hook: Save the field when the post is saved/updated.
 */
add_action('save_post_word_images', 'll_save_word_image_metadata');
function ll_save_word_image_metadata($post_id) {
    // Security check: Was our nonce present and valid?
    if (!isset($_POST['ll_word_image_metadata_nonce']) ||
        !wp_verify_nonce($_POST['ll_word_image_metadata_nonce'], 'll_word_image_metadata_save')) {
        return;
    }

    // Stop if it's an autosave or if the user lacks permissions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Finally, save the posted "copyright_info" field
    if (isset($_POST['ll_copyright_info'])) {
        $clean_value = sanitize_textarea_field($_POST['ll_copyright_info']);
        update_post_meta($post_id, 'copyright_info', $clean_value);
    }
}


/**
 * 2) SHOW METADATA IN THE WORD IMAGES TABLE (edit.php?post_type=word_images)
 */

// Hook: Register our modifications to the Word Images admin page columns
add_action('admin_init', 'll_modify_word_images_admin_page');
function ll_modify_word_images_admin_page() {
    // Filter: Modify the columns shown in the Word Images table
    add_filter('manage_word_images_posts_columns', 'll_modify_word_images_columns');

    // Action: Render the custom column content
    add_action('manage_word_images_posts_custom_column', 'll_render_word_images_columns', 10, 2);

    // Add filter dropdown for categories
    add_action('restrict_manage_posts', 'll_add_word_images_category_filter');

    // Apply the category filter to the query
    add_filter('parse_query', 'll_filter_word_images_by_category');
}

/**
 * Add a category filter dropdown to the Word Images admin page
 */
function ll_add_word_images_category_filter() {
    global $typenow, $wpdb;

    if ($typenow === 'word_images') {
        $selected_category = isset($_GET['word_category_filter']) ? $_GET['word_category_filter'] : '';

        // Get categories with accurate counts for word_images only
        $categories = get_terms(array(
            'taxonomy' => 'word-category',
            'hide_empty' => false,
            'hierarchical' => true,
        ));

        echo '<select name="word_category_filter" id="word_category_filter">';
        echo '<option value="">' . __('All Categories', 'll-tools-text-domain') . '</option>';

        foreach ($categories as $category) {
            // Count word_images posts in this category
            $count_query = new WP_Query(array(
                'post_type' => 'word_images',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => array(
                    array(
                        'taxonomy' => 'word-category',
                        'field' => 'term_id',
                        'terms' => $category->term_id,
                    )
                ),
            ));
            $count = $count_query->found_posts;

            $indent = str_repeat('&nbsp;&nbsp;', ll_get_category_depth($category->term_id));
            printf(
                '<option value="%d" %s>%s%s (%d)</option>',
                $category->term_id,
                selected($selected_category, $category->term_id, false),
                $indent,
                esc_html($category->name),
                $count
            );
        }

        echo '</select>';
    }
}

/**
 * Filter Word Images by selected category
 */
function ll_filter_word_images_by_category($query) {
    global $pagenow, $typenow;

    if ($pagenow === 'edit.php' && $typenow === 'word_images' && is_admin() && $query->is_main_query()) {
        // Only apply filter if a specific category is selected (not empty string or 0)
        if (isset($_GET['word_category_filter']) && $_GET['word_category_filter'] != '' && $_GET['word_category_filter'] != '0') {
            $category_id = intval($_GET['word_category_filter']);

            $query->set('tax_query', array(
                array(
                    'taxonomy' => 'word-category',
                    'field' => 'term_id',
                    'terms' => $category_id,
                )
            ));
        }
    }
}

/**
 * Adjust the columns we show in the Word Images table.
 *
 * @param array $columns Default columns
 * @return array Modified columns
 */
function ll_modify_word_images_columns($columns) {
    // Insert our new columns after the 'title' column
    $new_columns = [];
    foreach ($columns as $col_key => $col_label) {
        $new_columns[$col_key] = $col_label;
        if ('title' === $col_key) {
            $new_columns['word_categories'] = __('Categories', 'll-tools-text-domain');
            $new_columns['copyright_info'] = __('Copyright Info', 'll-tools-text-domain');
            $new_columns['attached_image'] = __('Featured Image', 'll-tools-text-domain');
        }
    }

    return $new_columns;
}

/**
 * Make the category column sortable
 */
add_filter('manage_edit-word_images_sortable_columns', 'll_word_images_sortable_columns');
function ll_word_images_sortable_columns($columns) {
    $columns['word_categories'] = 'word_categories';
    return $columns;
}

/**
 * Handle sorting by category
 */
add_action('pre_get_posts', 'll_word_images_sort_by_category');
function ll_word_images_sort_by_category($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    // Check if we're on the word_images post type list
    if ($query->get('post_type') !== 'word_images') {
        return;
    }

    // Check if sorting by categories (WordPress passes this from the URL)
    $orderby = $query->get('orderby');
    if ($orderby === 'word_categories') {
        // Don't set orderby here, let the clauses filter handle it
        add_filter('posts_clauses', 'll_sort_word_images_by_category_clauses', 10, 2);
    }
}

function ll_sort_word_images_by_category_clauses($clauses, $query) {
    global $wpdb;

    // Only apply if we're sorting by categories
    if (!is_admin() || $query->get('orderby') !== 'word_categories') {
        return $clauses;
    }

    // Join with term tables
    $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id";
    $clauses['join'] .= " LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'word-category'";
    $clauses['join'] .= " LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id";

    // Group by post ID to avoid duplicates
    $clauses['groupby'] = "{$wpdb->posts}.ID";

    // Order by the minimum (alphabetically first) category name
    $order = ($query->get('order') === 'DESC' || strtoupper($query->get('order')) === 'DESC') ? 'DESC' : 'ASC';
    $clauses['orderby'] = "MIN(t.name) " . $order;

    // Remove this filter after it runs once
    remove_filter('posts_clauses', 'll_sort_word_images_by_category_clauses', 10);

    return $clauses;
}

/**
 * Render the content for our custom columns in the Word Images table.
 *
 * @param string $column  Current column slug
 * @param int    $post_id Current post ID
 */
function ll_render_word_images_columns($column, $post_id) {
    switch ($column) {
        case 'word_categories':
            // Show assigned 'word-category' terms for this Word Image
            $terms = get_the_terms($post_id, 'word-category');
            if ($terms && !is_wp_error($terms)) {
                $links = [];
                foreach ($terms as $t) {
                    // Link to filter Word Images by this category
                    $url = add_query_arg(
                        ['post_type' => 'word_images', 'word-category' => $t->slug],
                        admin_url('edit.php')
                    );
                    $links[] = '<a href="' . esc_url($url) . '">' . esc_html($t->name) . '</a>';
                }
                echo implode(', ', $links);
            } else {
                echo '—';
            }
            break;
        case 'copyright_info':
            // Show the "copyright_info" meta field
            $val = get_post_meta($post_id, 'copyright_info', true);
            echo $val ? esc_html($val) : '—';
            break;

        case 'attached_image':
            $thumbnail = ll_tools_get_post_thumbnail_html_with_repair($post_id, 'full', array('style' => 'width:50px;height:auto;'));
            if ($thumbnail) {
                echo $thumbnail;
            } else {
                echo '-';
            }
            break;
    }
}

/**
 * BULK EDIT CATEGORY FUNCTIONALITY FOR WORD IMAGES
 */

/**
 * Enqueue admin script for bulk edit category handling
 */
function ll_word_images_enqueue_bulk_edit_script($hook) {
    ll_enqueue_bulk_category_edit_script('word_images', 'll-word-images-bulk-edit', 'js/bulk-category-edit.js', 'll_word_images_get_common_categories');
}
add_action('admin_enqueue_scripts', 'll_word_images_enqueue_bulk_edit_script');

/**
 * AJAX handler to get common categories for selected word images
 */
function ll_word_images_get_common_categories() {
    ll_get_common_categories_for_post_type('word_images');
}
add_action('wp_ajax_ll_word_images_get_common_categories', 'll_word_images_get_common_categories');

/**
 * Handle bulk edit category removal for word images
 */
function ll_word_images_handle_bulk_edit_categories($post_id) {
    ll_handle_bulk_category_edit($post_id, 'word_images');
}
add_action('edit_post', 'll_word_images_handle_bulk_edit_categories', 999, 1);
