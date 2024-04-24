<?php

// Create the "Missing Audio" admin page
function ll_create_missing_audio_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Missing Audio',
        'LL Tools Missing Audio',
        'manage_options',
        'language-learner-tools-missing-audio',
        'll_render_missing_audio_admin_page'
    );
}
add_action('admin_menu', 'll_create_missing_audio_admin_page');

// Render the "Missing Audio" admin page
function ll_render_missing_audio_admin_page() {
    // Get all posts and pages
    $args = array(
        'post_type' => array('post', 'page'),
        'posts_per_page' => -1,
    );
    $query = new WP_Query($args);

    // Initialize an array to store the missing audio instances
    $missing_audio_instances = array();

    // Loop through each post/page
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_content = get_the_content();

            // Find all [word_audio] shortcodes in the post content
            if (preg_match_all('/\[word_audio[^\]]*\]([^\[]+)\[\/word_audio\]/i', $post_content, $matches)) {
                foreach ($matches[1] as $word) {
                    $normalized_word = ll_normalize_case(sanitize_text_field($word));
                    $word_post = ll_find_post_by_exact_title($normalized_word, 'words');

                    // Check if the word post exists and has an audio file
                    if (!$word_post || !get_post_meta($word_post->ID, 'word_audio_file', true)) {
                        $missing_audio_instances[] = array(
                            'post_id' => get_the_ID(),
                            'post_title' => get_the_title(),
                            'word' => $word,
                        );
                    }
                }
            }
        }
        wp_reset_postdata();
    }
    ?>

    <div class="wrap">
        <h1>Language Learner Tools - Missing Audio</h1>
        <?php if (!empty($missing_audio_instances)) : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Post/Page</th>
                        <th>Word</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missing_audio_instances as $instance) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(get_edit_post_link($instance['post_id'])); ?>" target="_blank"><?php echo esc_html($instance['post_title']); ?></a></td>
                            <td><?php echo esc_html($instance['word']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No missing audio instances found.</p>
        <?php endif; ?>
    </div>
    <?php
}