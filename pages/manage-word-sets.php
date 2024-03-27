<?php
function ll_manage_word_sets_page_template() {
    $page_slug = 'manage-word-sets';
    $existing_page = get_page_by_path($page_slug, OBJECT, 'page');
	
    // Correct the path for the file whose version we're checking.
    $latest_page_version = filemtime(__FILE__); 

    if (!$existing_page) {
        // Page doesn't exist, so create it.
        $page_id = wp_insert_post(
            array(
                'post_title'    => 'Manage Word Sets',
                'post_name'     => $page_slug,
                'post_content'  => ll_manage_word_sets_page_content(), 
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => get_current_user_id(),
                'ping_status'   => 'closed'
            )
        );
        // Set the initial version of the page.
        if ($page_id != 0) {
            update_post_meta($page_id, '_page_version', $latest_page_version);
            flush_rewrite_rules();
        }
    } else {
        // Page exists, check if the version needs updating.
        $existing_page_version = get_post_meta($existing_page->ID, '_page_version', true);
        if (intval($existing_page_version) < $latest_page_version) {
            // Update the content and the version meta.
            wp_update_post(
                array(
                    'ID'           => $existing_page->ID,
                    'post_content' => ll_manage_word_sets_page_content(),
                )
            );
            update_post_meta($existing_page->ID, '_page_version', $latest_page_version);
        }
    }
}
add_action('init', 'll_manage_word_sets_page_template');

// Function to return the content for the Manage Word Sets page
function ll_manage_word_sets_page_content() {
    ob_start();
    ?>
    <h2>Create a New Word Set</h2>
    <form id="create-word-set-form" method="post">
        <div>
            <label for="word-set-name">Word Set Name:</label>
            <input type="text" id="word-set-name" name="word_set_name" required>
            <div id="word-set-name-error" class="error-message" style="display:none; color: red; margin-top: 5px;"></div>
        </div>
        <div>
            <label for="word-set-language">Language:</label>
            <input type="text" id="word-set-language" name="word_set_language" required>
            <input type="hidden" id="word-set-language-id" name="word_set_language_id">
            <div id="word-set-language-error" class="error-message" style="display:none; color: red; margin-top: 5px;"></div>
        </div>
        <div style="margin-top: 20px;">
            <button type="submit">Create Word Set</button>
        </div>
    </form>

    <h2>Your Word Sets</h2>
    <?php
    $content = ob_get_clean();
    $content .= ll_get_user_word_sets(get_current_user_id());
    return $content;
}

// Enqueue the script for the Manage Word Sets page
function ll_enqueue_manage_word_sets_script() {
    // Assuming this function is in a file located in /pages/ directory of your plugin
    // Go up one level to the plugin root, then into the /js/ directory
    $js_path = plugin_dir_path(dirname(__FILE__)) . 'js/manage-word-sets.js';
    $version = filemtime($js_path); // File modification time as version
    $userId = get_current_user_id();

    wp_enqueue_script('manage-word-sets-script', plugin_dir_url(dirname(__FILE__)) . 'js/manage-word-sets.js', array('jquery', 'jquery-ui-autocomplete'), $version, true);

    $languages = get_terms([
        'taxonomy' => 'language',
        'hide_empty' => false,
    ]);
    $language_data = array_map(function($language) {
        return array('label' => esc_html($language->name), 'value' => esc_attr($language->term_id));
    }, $languages);

    wp_localize_script('manage-word-sets-script', 'manageWordSetData', array(
        'availableLanguages' => $language_data,
		'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('create_word_set_nonce'),
        'userWordSets' => ll_get_user_word_sets($userId),
    ));
}
add_action('wp_enqueue_scripts', 'll_enqueue_manage_word_sets_script');

// AJAX handler for creating a new word set
add_action('wp_ajax_create_word_set', 'll_handle_create_word_set');
function ll_handle_create_word_set() {
    // Make sure user has the required permissions
    if (!current_user_can('edit_word_sets')) {
        wp_die();
    }
    
    // Verify nonce
    check_ajax_referer('create_word_set_nonce', 'security');

    // Process form data
    $wordSetName = isset($_POST['word_set_name']) ? sanitize_text_field($_POST['word_set_name']) : '';
    $languageId = isset($_POST['word_set_language_id']) ? sanitize_text_field($_POST['word_set_language_id']) : '';
    $userId = get_current_user_id();

    // Create the word set (you might need additional arguments based on your setup)
    $term = ll_create_new_word_set($wordSetName, $languageId, $userId);
    if (is_wp_error($term)) {
        wp_send_json_error(['message' => $term->get_error_message()]);
        return;
    } else {
        wp_send_json_success(['message' => 'Word set created successfully!']);
    }
}

// Don't apply wpautop filter to the content of the Manage Word Sets page
function ll_manage_word_sets_content_filter($content) {
    global $post;
    // Ensure global $post is available to check against the current page's slug
    if (isset($post) && $post->post_name == 'manage-word-sets') {
        remove_filter('the_content', 'wpautop');
    }
    return $content;
}
add_filter('the_content', 'll_manage_word_sets_content_filter', 0);


