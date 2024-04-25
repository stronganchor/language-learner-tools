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
    $missing_audio_instances = get_option('ll_missing_audio_instances', array());

    // Check if the clear cache button was clicked
    if (isset($_POST['clear_cache'])) {
        // Clear the missing audio instances cache
        update_option('ll_missing_audio_instances', array());
        $missing_audio_instances = array();
        echo '<div class="notice notice-success"><p>Missing audio instances cache has been cleared.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Language Learner Tools - Missing Audio</h1>
        <form method="post">
            <?php wp_nonce_field('clear_missing_audio_cache', 'clear_cache_nonce'); ?>
            <p>
                <input type="submit" name="clear_cache" class="button button-secondary" value="Clear Cache">
            </p>
        </form>
        <?php if (!empty($missing_audio_instances)) : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Word</th>
                        <th>Post</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($missing_audio_instances as $word => $post_id) : ?>
                        <tr>
                            <td><?php echo esc_html($word); ?></td>
                            <td>
                                <?php
                                $post = get_post($post_id);
                                if ($post) {
                                    echo '<a href="' . esc_url(get_edit_post_link($post->ID)) . '" target="_blank">' . esc_html($post->post_title) . '</a>';
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
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