<?php

// Create the "Word Set Manager" user role
function ll_create_word_set_manager_role() {
    add_role(
        'word_set_manager',
        'Word Set Manager',
        array(
            'read' => true,
            'upload_files' => true,
            'edit_posts' => true,
            'edit_published_posts' => true,
            'delete_posts' => true,
            'delete_published_posts' => true,
            'edit_word_sets' => true,
            'manage_word_sets' => true,
        )
    );
}
add_action('init', 'll_create_word_set_manager_role');