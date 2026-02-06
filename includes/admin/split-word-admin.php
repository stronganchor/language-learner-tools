<?php
/**
 * Split a word into two words and reassign selected audio children.
 *
 * - Adds a "Split Audio" row action on the Words list table.
 * - Renders a hidden admin page for selecting which recordings move to the new word.
 * - Handles secure form submission via admin-post.
 */

if (!defined('WPINC')) { die; }

add_action('admin_menu', 'll_tools_register_split_word_admin_page');
function ll_tools_register_split_word_admin_page() {
    $parent_slug = 'edit.php?post_type=words';

    add_submenu_page(
        $parent_slug,
        __('Split Word Audio', 'll-tools-text-domain'),
        __('Split Word Audio', 'll-tools-text-domain'),
        'view_ll_tools',
        'll-tools-split-word',
        'll_tools_render_split_word_admin_page'
    );
}

// Keep the page routable via edit.php?post_type=words&page=ll-tools-split-word without showing a submenu item.
add_action('admin_menu', 'll_tools_hide_split_word_submenu', 999);
function ll_tools_hide_split_word_submenu() {
    remove_submenu_page('edit.php?post_type=words', 'll-tools-split-word');
}

/**
 * Base admin URL for split-word page routing.
 *
 * @return string
 */
function ll_tools_get_split_word_base_url() {
    return admin_url('edit.php?post_type=words');
}

add_filter('post_row_actions', 'll_tools_add_split_word_row_action', 10, 2);
function ll_tools_add_split_word_row_action($actions, $post) {
    if (!is_admin() || !($post instanceof WP_Post) || $post->post_type !== 'words') {
        return $actions;
    }

    if (!current_user_can('view_ll_tools') || !current_user_can('edit_post', $post->ID)) {
        return $actions;
    }

    $url = add_query_arg(
        [
            'page'    => 'll-tools-split-word',
            'word_id' => (int) $post->ID,
        ],
        ll_tools_get_split_word_base_url()
    );

    $actions['ll_tools_split_word'] = '<a href="' . esc_url($url) . '">' . esc_html__('Split Audio', 'll-tools-text-domain') . '</a>';

    return $actions;
}

/**
 * Fetch audio children for a word, including publish and working statuses.
 *
 * @param int $word_id Word post ID.
 * @return WP_Post[] Array of word_audio posts.
 */
function ll_tools_get_split_word_audio_children($word_id) {
    return get_posts([
        'post_type'        => 'word_audio',
        'post_parent'      => (int) $word_id,
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'posts_per_page'   => -1,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'suppress_filters' => true,
    ]);
}

/**
 * Build the hidden admin page URL for split workflow.
 *
 * @param int   $word_id Word post ID.
 * @param array $args    Optional extra query args.
 * @return string
 */
function ll_tools_get_split_word_page_url($word_id, $args = []) {
    $url = add_query_arg(
        [
            'page'    => 'll-tools-split-word',
            'word_id' => (int) $word_id,
        ],
        ll_tools_get_split_word_base_url()
    );

    if (!empty($args)) {
        $url = add_query_arg($args, $url);
    }

    return $url;
}

/**
 * Human-readable message for split workflow error codes.
 *
 * @param string $code Error code.
 * @return string
 */
function ll_tools_get_split_word_error_message($code) {
    $messages = [
        'invalid_word'      => __('The selected word is invalid or unavailable.', 'll-tools-text-domain'),
        'no_audio'          => __('This word has no audio recordings to split.', 'll-tools-text-domain'),
        'no_selection'      => __('Select at least one audio recording to move to the new word.', 'll-tools-text-domain'),
        'permission'        => __('You do not have permission to split this word.', 'll-tools-text-domain'),
        'create_failed'     => __('Could not create the new word post. Please try again.', 'll-tools-text-domain'),
        'nonce'             => __('Security check failed. Please try again.', 'll-tools-text-domain'),
        'partial_move_fail' => __('Some recordings could not be moved. Review both word posts.', 'll-tools-text-domain'),
    ];

    return isset($messages[$code]) ? $messages[$code] : '';
}

/**
 * Render split form page.
 *
 * @return void
 */
function ll_tools_render_split_word_admin_page() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'll-tools-text-domain'), 403);
    }

    $word_id = isset($_GET['word_id']) ? absint($_GET['word_id']) : 0;
    $word_post = $word_id ? get_post($word_id) : null;

    if (!$word_post || $word_post->post_type !== 'words' || !current_user_can('edit_post', $word_id)) {
        wp_die(esc_html__('Invalid word post.', 'll-tools-text-domain'), 404);
    }

    $audio_posts = ll_tools_get_split_word_audio_children($word_id);
    $error_code = isset($_GET['ll_split_error']) ? sanitize_key((string) $_GET['ll_split_error']) : '';
    $error_message = ll_tools_get_split_word_error_message($error_code);
    $default_new_title = (string) $word_post->post_title;
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Split Word Audio', 'll-tools-text-domain'); ?></h1>
        <p>
            <?php
            printf(
                /* translators: %s: source word title */
                esc_html__('Split "%s" into two word posts by choosing which recordings move to the new word.', 'll-tools-text-domain'),
                esc_html($word_post->post_title)
            );
            ?>
        </p>

        <?php if ($error_message !== '') : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error_message); ?></p></div>
        <?php endif; ?>

        <?php if (empty($audio_posts)) : ?>
            <div class="notice notice-warning">
                <p><?php echo esc_html__('No audio recordings were found for this word.', 'll-tools-text-domain'); ?></p>
            </div>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=words')); ?>">
                    <?php echo esc_html__('Back to Words', 'll-tools-text-domain'); ?>
                </a>
            </p>
            <?php
            return;
        endif;
        ?>

        <form id="ll-tools-split-word-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ll_tools_split_word_save">
            <input type="hidden" name="ll_source_word_id" value="<?php echo esc_attr($word_id); ?>">
            <?php wp_nonce_field('ll_tools_split_word_save_' . $word_id, 'll_tools_split_word_nonce'); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="ll_original_word_title"><?php echo esc_html__('Original title (optional)', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            class="regular-text"
                            id="ll_original_word_title"
                            name="ll_original_word_title"
                            value=""
                            placeholder="<?php echo esc_attr($word_post->post_title); ?>"
                        >
                        <p class="description">
                            <?php echo esc_html__('Leave blank to keep the current original-word title.', 'll-tools-text-domain'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ll_new_word_title"><?php echo esc_html__('New word title (optional)', 'll-tools-text-domain'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            class="regular-text"
                            id="ll_new_word_title"
                            name="ll_new_word_title"
                            value=""
                            placeholder="<?php echo esc_attr($default_new_title); ?>"
                        >
                        <p class="description">
                            <?php echo esc_html__('Leave blank to copy the current title to the new word.', 'll-tools-text-domain'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html__('Audio Assignment', 'll-tools-text-domain'); ?></h2>
            <p><?php echo esc_html__('Check recordings to move to the new word. Unchecked recordings stay on the original word.', 'll-tools-text-domain'); ?></p>
            <p>
                <button type="button" class="button" id="ll-tools-move-all-audio"><?php echo esc_html__('Move All to New Word', 'll-tools-text-domain'); ?></button>
                <button type="button" class="button" id="ll-tools-keep-all-audio"><?php echo esc_html__('Keep All on Original', 'll-tools-text-domain'); ?></button>
            </p>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width: 90px;"><?php echo esc_html__('Move', 'll-tools-text-domain'); ?></th>
                        <th><?php echo esc_html__('Recording', 'll-tools-text-domain'); ?></th>
                        <th style="width: 180px;"><?php echo esc_html__('Type', 'll-tools-text-domain'); ?></th>
                        <th style="width: 120px;"><?php echo esc_html__('Status', 'll-tools-text-domain'); ?></th>
                        <th style="width: 260px;"><?php echo esc_html__('Preview', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audio_posts as $audio_post) : ?>
                        <?php
                        $audio_id = (int) $audio_post->ID;
                        $audio_title = trim((string) get_the_title($audio_id));
                        if ($audio_title === '') {
                            $audio_title = __('Recording', 'll-tools-text-domain') . ' #' . $audio_id;
                        }
                        $audio_edit_link = get_edit_post_link($audio_id);
                        $status = get_post_status($audio_id);
                        $type_names = wp_get_post_terms($audio_id, 'recording_type', ['fields' => 'names']);
                        $type_label = (!is_wp_error($type_names) && !empty($type_names))
                            ? implode(', ', $type_names)
                            : __('Recording', 'll-tools-text-domain');

                        $audio_src = '';
                        $audio_path = (string) get_post_meta($audio_id, 'audio_file_path', true);
                        if ($audio_path !== '') {
                            $audio_src = (strpos($audio_path, 'http') === 0) ? $audio_path : site_url($audio_path);
                        }
                        ?>
                        <tr>
                            <td>
                                <label>
                                    <input type="checkbox" name="ll_move_audio_ids[]" value="<?php echo esc_attr($audio_id); ?>">
                                    <?php echo esc_html__('Move', 'll-tools-text-domain'); ?>
                                </label>
                            </td>
                            <td>
                                <?php if ($audio_edit_link) : ?>
                                    <a href="<?php echo esc_url($audio_edit_link); ?>"><?php echo esc_html($audio_title); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($audio_title); ?>
                                <?php endif; ?>
                                <div style="opacity: .7;">#<?php echo esc_html($audio_id); ?></div>
                            </td>
                            <td><?php echo esc_html($type_label); ?></td>
                            <td><?php echo esc_html((string) $status); ?></td>
                            <td>
                                <?php if ($audio_src !== '') : ?>
                                    <audio controls preload="none" style="width: 240px; max-width: 100%; height: 32px;" src="<?php echo esc_url($audio_src); ?>"></audio>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html__('Split Word', 'll-tools-text-domain'); ?></button>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=words')); ?>">
                    <?php echo esc_html__('Cancel', 'll-tools-text-domain'); ?>
                </a>
            </p>
        </form>
    </div>
    <script>
    (function () {
        var form = document.getElementById('ll-tools-split-word-form');
        if (!form) return;

        var moveAllButton = document.getElementById('ll-tools-move-all-audio');
        var keepAllButton = document.getElementById('ll-tools-keep-all-audio');

        function setAllCheckboxes(checked) {
            var boxes = form.querySelectorAll('input[name="ll_move_audio_ids[]"]');
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].checked = checked;
            }
        }

        if (moveAllButton) {
            moveAllButton.addEventListener('click', function () {
                setAllCheckboxes(true);
            });
        }

        if (keepAllButton) {
            keepAllButton.addEventListener('click', function () {
                setAllCheckboxes(false);
            });
        }
    })();
    </script>
    <?php
}

add_action('admin_post_ll_tools_split_word_save', 'll_tools_handle_split_word_save');
function ll_tools_handle_split_word_save() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'll-tools-text-domain'), 403);
    }

    $source_word_id = isset($_POST['ll_source_word_id']) ? absint($_POST['ll_source_word_id']) : 0;
    if (!$source_word_id) {
        $redirect = add_query_arg(
            [
                'post_type'      => 'words',
                'll_split_error' => 'invalid_word',
            ],
            admin_url('edit.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    if (!isset($_POST['ll_tools_split_word_nonce']) || !wp_verify_nonce($_POST['ll_tools_split_word_nonce'], 'll_tools_split_word_save_' . $source_word_id)) {
        $redirect = ll_tools_get_split_word_page_url($source_word_id, ['ll_split_error' => 'nonce']);
        wp_safe_redirect($redirect);
        exit;
    }

    $source_word = get_post($source_word_id);
    if (!$source_word || $source_word->post_type !== 'words' || !current_user_can('edit_post', $source_word_id)) {
        $redirect = add_query_arg(
            [
                'post_type'      => 'words',
                'll_split_error' => 'permission',
            ],
            admin_url('edit.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    $audio_posts = ll_tools_get_split_word_audio_children($source_word_id);
    if (empty($audio_posts)) {
        $redirect = ll_tools_get_split_word_page_url($source_word_id, ['ll_split_error' => 'no_audio']);
        wp_safe_redirect($redirect);
        exit;
    }

    $source_audio_ids = [];
    foreach ($audio_posts as $audio_post) {
        $source_audio_ids[] = (int) $audio_post->ID;
    }

    $raw_move_ids = isset($_POST['ll_move_audio_ids']) ? (array) $_POST['ll_move_audio_ids'] : [];
    $move_ids = array_values(array_unique(array_filter(array_map('absint', $raw_move_ids))));
    $move_ids = array_values(array_intersect($move_ids, $source_audio_ids));

    if (empty($move_ids)) {
        $redirect = ll_tools_get_split_word_page_url($source_word_id, ['ll_split_error' => 'no_selection']);
        wp_safe_redirect($redirect);
        exit;
    }

    $original_title_input = isset($_POST['ll_original_word_title']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_original_word_title'])) : '';
    $new_title_input = isset($_POST['ll_new_word_title']) ? sanitize_text_field(wp_unslash((string) $_POST['ll_new_word_title'])) : '';
    $new_word_title = trim($new_title_input) !== '' ? trim($new_title_input) : (string) $source_word->post_title;

    $new_word_id = ll_tools_create_split_word_clone($source_word, $new_word_title);
    if (is_wp_error($new_word_id) || !$new_word_id) {
        $redirect = ll_tools_get_split_word_page_url($source_word_id, ['ll_split_error' => 'create_failed']);
        wp_safe_redirect($redirect);
        exit;
    }

    $renamed_original = 0;
    $original_title_input = trim($original_title_input);
    if ($original_title_input !== '' && $original_title_input !== $source_word->post_title) {
        wp_update_post([
            'ID'         => $source_word_id,
            'post_title' => $original_title_input,
        ]);
        $renamed_original = 1;
    }

    $moved_count = 0;
    $failed_count = 0;
    foreach ($move_ids as $audio_id) {
        if (!current_user_can('edit_post', $audio_id)) {
            $failed_count++;
            continue;
        }

        $updated = wp_update_post([
            'ID'          => (int) $audio_id,
            'post_parent' => (int) $new_word_id,
        ], true);

        if (is_wp_error($updated)) {
            $failed_count++;
            continue;
        }

        $moved_count++;
    }

    $desired_new_status = ll_tools_normalize_split_word_status((string) $source_word->post_status);
    if (
        $desired_new_status === 'publish' &&
        function_exists('ll_word_requires_audio_to_publish') &&
        ll_word_requires_audio_to_publish((int) $new_word_id) &&
        !ll_tools_split_word_has_published_audio((int) $new_word_id)
    ) {
        $desired_new_status = 'draft';
    }

    if ($desired_new_status !== 'draft') {
        wp_update_post([
            'ID'          => (int) $new_word_id,
            'post_status' => $desired_new_status,
        ]);
    }

    if (function_exists('ll_tools_sync_parent_word_status_by_children')) {
        ll_tools_sync_parent_word_status_by_children($source_word_id);
        ll_tools_sync_parent_word_status_by_children((int) $new_word_id);
    }

    $redirect_args = [
        'post_type'                 => 'words',
        'll_split_word'             => '1',
        'll_split_source'           => (int) $source_word_id,
        'll_split_new'              => (int) $new_word_id,
        'll_split_moved'            => (int) $moved_count,
        'll_split_failed'           => (int) $failed_count,
        'll_split_renamed_original' => (int) $renamed_original,
    ];

    if ($failed_count > 0) {
        $redirect_args['ll_split_error'] = 'partial_move_fail';
    }

    $redirect = add_query_arg($redirect_args, admin_url('edit.php'));
    wp_safe_redirect($redirect);
    exit;
}

/**
 * Clone source word data needed for split.
 *
 * @param WP_Post $source_word Source word post object.
 * @param string  $new_title   New post title.
 * @return int|WP_Error
 */
function ll_tools_create_split_word_clone($source_word, $new_title) {
    $new_word_id = wp_insert_post([
        'post_type'      => 'words',
        'post_title'     => $new_title,
        'post_content'   => (string) $source_word->post_content,
        'post_excerpt'   => (string) $source_word->post_excerpt,
        'post_status'    => 'draft',
        'post_author'    => (int) $source_word->post_author,
        'comment_status' => (string) $source_word->comment_status,
        'ping_status'    => (string) $source_word->ping_status,
        'menu_order'     => (int) $source_word->menu_order,
        'meta_input'     => [
            '_ll_skip_audio_requirement_once' => '1',
        ],
    ], true);

    if (is_wp_error($new_word_id) || !$new_word_id) {
        return is_wp_error($new_word_id)
            ? $new_word_id
            : new WP_Error('ll_split_create_failed', __('Failed to create split word.', 'll-tools-text-domain'));
    }

    ll_tools_copy_split_word_taxonomies($source_word->ID, (int) $new_word_id);
    ll_tools_copy_split_word_meta($source_word->ID, (int) $new_word_id);

    $thumbnail_id = get_post_thumbnail_id($source_word->ID);
    if ($thumbnail_id) {
        set_post_thumbnail((int) $new_word_id, (int) $thumbnail_id);
    }

    return (int) $new_word_id;
}

/**
 * Copy all terms from source words post to target words post.
 *
 * @param int $source_word_id Source word ID.
 * @param int $target_word_id Target word ID.
 * @return void
 */
function ll_tools_copy_split_word_taxonomies($source_word_id, $target_word_id) {
    $taxonomies = get_object_taxonomies('words', 'names');
    if (empty($taxonomies) || is_wp_error($taxonomies)) {
        return;
    }

    foreach ($taxonomies as $taxonomy) {
        $term_ids = wp_get_object_terms((int) $source_word_id, $taxonomy, ['fields' => 'ids']);
        if (is_wp_error($term_ids)) {
            continue;
        }

        wp_set_object_terms((int) $target_word_id, array_map('intval', (array) $term_ids), $taxonomy, false);
    }
}

/**
 * Copy non-system word meta from source to target.
 *
 * @param int $source_word_id Source word ID.
 * @param int $target_word_id Target word ID.
 * @return void
 */
function ll_tools_copy_split_word_meta($source_word_id, $target_word_id) {
    $all_meta = get_post_meta((int) $source_word_id);
    if (empty($all_meta) || !is_array($all_meta)) {
        return;
    }

    $skip_keys = [
        '_edit_lock',
        '_edit_last',
        '_ll_skip_audio_requirement_once',
        'word_audio_file',
        '_ll_picked_count',
        '_ll_picked_last',
        '_ll_autopicked_image_id',
        '_thumbnail_id',
    ];
    $allow_protected = [
        '_ll_similar_word_id',
    ];

    foreach ($all_meta as $meta_key => $values) {
        if (in_array($meta_key, $skip_keys, true)) {
            continue;
        }

        $is_protected = is_protected_meta((string) $meta_key, 'post');
        if ($is_protected && !in_array($meta_key, $allow_protected, true)) {
            continue;
        }

        foreach ((array) $values as $value) {
            add_post_meta((int) $target_word_id, (string) $meta_key, maybe_unserialize($value));
        }
    }
}

/**
 * Keep new post status aligned with source, bounded to safe statuses.
 *
 * @param string $status Raw status.
 * @return string
 */
function ll_tools_normalize_split_word_status($status) {
    $allowed = ['publish', 'draft', 'pending', 'private'];
    return in_array($status, $allowed, true) ? $status : 'draft';
}

/**
 * Whether a word currently has at least one published audio child.
 *
 * @param int $word_id Word post ID.
 * @return bool
 */
function ll_tools_split_word_has_published_audio($word_id) {
    $published_audio = get_posts([
        'post_type'      => 'word_audio',
        'post_parent'    => (int) $word_id,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    return !empty($published_audio);
}

add_action('admin_notices', 'll_tools_split_word_admin_notices');
function ll_tools_split_word_admin_notices() {
    if (!is_admin()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return;
    }

    $post_type = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : '';
    if ($post_type !== 'words') {
        return;
    }

    $error_code = isset($_GET['ll_split_error']) ? sanitize_key((string) $_GET['ll_split_error']) : '';
    $error_message = ll_tools_get_split_word_error_message($error_code);
    if ($error_message !== '') {
        printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html($error_message));
    }

    if (!isset($_GET['ll_split_word']) || $_GET['ll_split_word'] !== '1') {
        return;
    }

    $source_id = isset($_GET['ll_split_source']) ? absint($_GET['ll_split_source']) : 0;
    $new_id = isset($_GET['ll_split_new']) ? absint($_GET['ll_split_new']) : 0;
    $moved_count = isset($_GET['ll_split_moved']) ? absint($_GET['ll_split_moved']) : 0;
    $failed_count = isset($_GET['ll_split_failed']) ? absint($_GET['ll_split_failed']) : 0;
    $renamed_original = isset($_GET['ll_split_renamed_original']) ? absint($_GET['ll_split_renamed_original']) : 0;

    if (!$source_id || !$new_id) {
        return;
    }

    $source_title = get_the_title($source_id);
    $new_title = get_the_title($new_id);
    $source_edit = get_edit_post_link($source_id);
    $new_edit = get_edit_post_link($new_id);

    $parts = [];
    $parts[] = sprintf(
        /* translators: %d: count of moved recordings */
        _n('%d recording moved.', '%d recordings moved.', $moved_count, 'll-tools-text-domain'),
        $moved_count
    );

    if ($failed_count > 0) {
        $parts[] = sprintf(
            /* translators: %d: count of failed recording moves */
            _n('%d recording could not be moved.', '%d recordings could not be moved.', $failed_count, 'll-tools-text-domain'),
            $failed_count
        );
    }

    if ($renamed_original > 0) {
        $parts[] = __('Original title updated.', 'll-tools-text-domain');
    }

    $message = implode(' ', $parts);
    $context = sprintf(
        /* translators: 1: original word title, 2: new word title */
        __('Split complete: "%1$s" and "%2$s".', 'll-tools-text-domain'),
        $source_title !== '' ? $source_title : ('#' . $source_id),
        $new_title !== '' ? $new_title : ('#' . $new_id)
    );

    echo '<div class="notice notice-success is-dismissible"><p>';
    echo esc_html($context) . ' ' . esc_html($message) . ' ';
    if ($source_edit) {
        echo '<a href="' . esc_url($source_edit) . '">' . esc_html__('Edit Original', 'll-tools-text-domain') . '</a> ';
    }
    if ($new_edit) {
        echo '<a href="' . esc_url($new_edit) . '">' . esc_html__('Edit New', 'll-tools-text-domain') . '</a>';
    }
    echo '</p></div>';
}
