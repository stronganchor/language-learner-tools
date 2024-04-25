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
    ?>
    <div class="wrap">
        <h1>Language Learner Tools - Missing Audio</h1>
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