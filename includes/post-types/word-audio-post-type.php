<?php
/**
 * Register the "word_audio" custom post type
 * Each post represents one audio recording for a word
 */

if (!defined('WPINC')) { die; }

function ll_tools_register_word_audio_post_type() {
    $labels = [
        'name' => __('Word Audio', 'll-tools-text-domain'),
        'singular_name' => __('Audio Recording', 'll-tools-text-domain'),
        'add_new' => __('Add New Recording', 'll-tools-text-domain'),
        'add_new_item' => __('Add New Audio Recording', 'll-tools-text-domain'),
        'edit_item' => __('Edit Audio Recording', 'll-tools-text-domain'),
        'new_item' => __('New Audio Recording', 'll-tools-text-domain'),
        'view_item' => __('View Audio Recording', 'll-tools-text-domain'),
        'search_items' => __('Search Audio Recordings', 'll-tools-text-domain'),
        'not_found' => __('No audio recordings found', 'll-tools-text-domain'),
        'not_found_in_trash' => __('No audio recordings found in trash', 'll-tools-text-domain'),
        'parent_item_colon' => __('Parent Word:', 'll-tools-text-domain'),
    ];

    $args = [
        'labels' => $labels,
        'public' => false,
        'publicly_queryable' => false,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=words',
        'show_in_nav_menus' => false,
        'show_in_rest' => true,
        'hierarchical' => true, // enables post_parent
        'supports' => ['title'],
        'capability_type' => 'post',
        'map_meta_cap' => true,
        'has_archive' => false,
        'rewrite' => false,
        'query_var' => 'word_audio',
    ];

    register_post_type('word_audio', $args);
}
add_action('init', 'll_tools_register_word_audio_post_type');

/**
 * Customize admin columns
 */
add_filter('manage_word_audio_posts_columns', 'll_word_audio_columns');
function ll_word_audio_columns($columns) {
    $new_columns = [];
    $new_columns['cb'] = $columns['cb'];
    $new_columns['title'] = __('Word', 'll-tools-text-domain');
    $new_columns['speaker'] = __('Speaker', 'll-tools-text-domain');
    $new_columns['recording_type'] = __('Type', 'll-tools-text-domain');
    $new_columns['audio_file'] = __('Audio File', 'll-tools-text-domain');
    $new_columns['date'] = __('Date', 'll-tools-text-domain');
    return $new_columns;
}

add_action('manage_word_audio_posts_custom_column', 'll_word_audio_column_content', 10, 2);
function ll_word_audio_column_content($column, $post_id) {
    switch ($column) {
        case 'speaker':
            $user_id = get_post_meta($post_id, 'speaker_user_id', true);
            if ($user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    echo esc_html($user->display_name);
                } else {
                    echo '—';
                }
            } else {
                $speaker_name = get_post_meta($post_id, 'speaker_name', true);
                echo $speaker_name ? esc_html($speaker_name) : '—';
            }
            break;

        case 'recording_type':
            $types = wp_get_post_terms($post_id, 'recording_type', ['fields' => 'names']);
            echo !is_wp_error($types) && !empty($types) ? esc_html(implode(', ', $types)) : '—';
            break;

        case 'audio_file':
            $audio = get_post_meta($post_id, 'audio_file_path', true);
            if ($audio) {
                $audio_url = (0 === strpos($audio, 'http')) ? $audio : site_url($audio);
                echo '<audio controls preload="none" style="height:30px;max-width:200px;" src="' . esc_url($audio_url) . '"></audio>';
            } else {
                echo '—';
            }
            break;
    }
}

/**
 * Add meta box for audio details
 */
add_action('add_meta_boxes_word_audio', 'll_word_audio_meta_box');
function ll_word_audio_meta_box() {
    add_meta_box(
        'll_word_audio_details',
        __('Audio Recording Details', 'll-tools-text-domain'),
        'll_word_audio_meta_box_callback',
        'word_audio',
        'normal',
        'high'
    );
}

function ll_word_audio_meta_box_callback($post) {
    wp_nonce_field('ll_word_audio_meta', 'll_word_audio_meta_nonce');

    $audio_path = get_post_meta($post->ID, 'audio_file_path', true);
    $user_id = get_post_meta($post->ID, 'speaker_user_id', true);
    $recording_date = get_post_meta($post->ID, 'recording_date', true);
    $parent_id = $post->post_parent;

    echo '<table class="form-table">';

    // Parent word
    echo '<tr>';
    echo '<th><label>' . __('Associated Word', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    if ($parent_id) {
        $parent = get_post($parent_id);
        echo esc_html($parent->post_title);
        echo ' <a href="' . get_edit_post_link($parent_id) . '" target="_blank">(' . __('Edit', 'll-tools-text-domain') . ')</a>';
    } else {
        echo '—';
    }
    echo '</td>';
    echo '</tr>';

    // Audio file
    echo '<tr>';
    echo '<th><label>' . __('Audio File Path', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<input type="text" value="' . esc_attr($audio_path) . '" class="widefat" readonly>';
    if ($audio_path) {
        $audio_url = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
        echo '<br><audio controls src="' . esc_url($audio_url) . '" style="margin-top:10px;"></audio>';
    }
    echo '</td>';
    echo '</tr>';

    // Speaker
    echo '<tr>';
    echo '<th><label>' . __('Recorded By', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    if ($user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            echo esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')';
            echo '<br><a href="' . get_edit_user_link($user_id) . '" target="_blank">' . __('View User Profile', 'll-tools-text-domain') . '</a>';
        } else {
            echo __('User not found', 'll-tools-text-domain');
        }
    } else {
        // Fallback for legacy data
        $speaker_name = get_post_meta($post->ID, 'speaker_name', true);
        echo $speaker_name ? esc_html($speaker_name) . ' ' . __('(legacy)', 'll-tools-text-domain') : '—';
    }
    echo '</td>';
    echo '</tr>';

    // Recording date
    echo '<tr>';
    echo '<th><label>' . __('Recording Date', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<input type="text" value="' . esc_attr($recording_date) . '" class="widefat" readonly>';
    echo '</td>';
    echo '</tr>';

    echo '</table>';
}

/**
 * Save meta box data
 */
add_action('save_post_word_audio', 'll_save_word_audio_meta');
function ll_save_word_audio_meta($post_id) {
    if (!isset($_POST['ll_word_audio_meta_nonce']) ||
        !wp_verify_nonce($_POST['ll_word_audio_meta_nonce'], 'll_word_audio_meta')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    if (isset($_POST['speaker_name'])) {
        update_post_meta($post_id, 'speaker_name', sanitize_text_field($_POST['speaker_name']));
    }
}

/**
 * Keep parent word status in sync with availability of published audio children.
 * - If a word loses its last published audio (trash/delete/unpublish), set the word to draft and remove legacy meta.
 * - We purposefully do not auto-publish words here; publishing is handled by processing/review flows.
 */
function ll_tools_sync_parent_word_status_by_children($parent_word_id) {
    $parent_word_id = intval($parent_word_id);
    if (!$parent_word_id) return;

    // Check if there are any remaining published audio children
    $remaining = get_posts([
        'post_type'      => 'word_audio',
        'post_parent'    => $parent_word_id,
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if (empty($remaining)) {
        $parent = get_post($parent_word_id);
        if ($parent && $parent->post_type === 'words') {
            if ($parent->post_status !== 'draft') {
                wp_update_post([
                    'ID'          => $parent_word_id,
                    'post_status' => 'draft',
                ]);
            }
            // Remove legacy meta so it doesn't appear as if audio exists
            delete_post_meta($parent_word_id, 'word_audio_file');
        }
    }
}

// When an audio post is trashed
add_action('trashed_post', function ($post_id) {
    $post = get_post($post_id);
    if ($post && $post->post_type === 'word_audio') {
        ll_tools_sync_parent_word_status_by_children($post->post_parent);
    }
});

// When an audio post is permanently deleted
add_action('before_delete_post', function ($post_id) {
    $post = get_post($post_id);
    if ($post && $post->post_type === 'word_audio') {
        ll_tools_sync_parent_word_status_by_children($post->post_parent);
    }
});

// When an audio post changes status (publish -> draft/private/pending etc.)
add_action('transition_post_status', function ($new_status, $old_status, $post) {
    if ($post && $post->post_type === 'word_audio' && $new_status !== $old_status) {
        // Only enforce downgrade when leaving publish
        if ($old_status === 'publish' && $new_status !== 'publish') {
            ll_tools_sync_parent_word_status_by_children($post->post_parent);
        }
    }
}, 10, 3);
