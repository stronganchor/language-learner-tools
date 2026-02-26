<?php // File: includes/admin/recording-types-admin.php
/**
 * Admin page for managing Recording Types taxonomy
 */

if (!defined('WPINC')) { die; }

function ll_register_recording_types_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Recording Types',
        'LL Recording Types',
        'manage_options',
        'll-recording-types',
        'll_render_recording_types_admin_page'
    );
}
add_action('admin_menu', 'll_register_recording_types_admin_page');

function ll_render_recording_types_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'll-tools-text-domain'));
    }

    // Handle term deletion
    if (isset($_POST['delete_term']) && isset($_POST['term_id'])) {
        check_admin_referer('ll_delete_recording_type');
        $term_id = intval($_POST['term_id']);
        $result = wp_delete_term($term_id, 'recording_type');

        if (!is_wp_error($result)) {
            echo '<div class="notice notice-success"><p>Recording type deleted successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error deleting recording type: ' . esc_html($result->get_error_message()) . '</p></div>';
        }
    }

    // Handle term addition
    if (isset($_POST['add_term']) && !empty($_POST['term_name'])) {
        check_admin_referer('ll_add_recording_type');
        $name = sanitize_text_field($_POST['term_name']);
        $slug = sanitize_title($_POST['term_slug'] ?: $name);

        $result = wp_insert_term($name, 'recording_type', ['slug' => $slug]);

        if (!is_wp_error($result)) {
            echo '<div class="notice notice-success"><p>Recording type added successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Error adding recording type: ' . esc_html($result->get_error_message()) . '</p></div>';
        }
    }

    // Get all recording types
    $terms = get_terms([
        'taxonomy' => 'recording_type',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);

    $available_slugs = [];
    if (!empty($terms) && !is_wp_error($terms)) {
        $available_slugs = array_map(function($term) {
            return $term->slug;
        }, $terms);
    }

    if (isset($_POST['save_uncategorized_defaults'])) {
        check_admin_referer('ll_save_uncategorized_defaults');
        $incoming = isset($_POST['ll_uncategorized_desired_recording_types']) ? (array) $_POST['ll_uncategorized_desired_recording_types'] : [];
        $sanitized = array_values(array_unique(array_map('sanitize_text_field', $incoming)));
        if (!empty($available_slugs)) {
            $sanitized = array_values(array_intersect($sanitized, $available_slugs));
        }

        if (empty($sanitized)) {
            delete_option('ll_uncategorized_desired_recording_types');
            echo '<div class="notice notice-success"><p>' . esc_html__('Uncategorized defaults reset to main recording types.', 'll-tools-text-domain') . '</p></div>';
        } else {
            update_option('ll_uncategorized_desired_recording_types', $sanitized);
            echo '<div class="notice notice-success"><p>' . esc_html__('Uncategorized defaults updated.', 'll-tools-text-domain') . '</p></div>';
        }
    }

    $current_uncategorized = function_exists('ll_tools_get_uncategorized_desired_recording_types')
        ? ll_tools_get_uncategorized_desired_recording_types()
        : [];

    ?>
    <div class="wrap">
        <h1>Recording Types Management</h1>
        <p>Manage the recording type taxonomy used for audio recordings. Each type represents a different recording style (e.g., isolation, sentence, question).</p>

        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2>Add New Recording Type</h2>
            <form method="post" action="">
                <?php wp_nonce_field('ll_add_recording_type'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="term_name">Name</label></th>
                        <td>
                            <input type="text" name="term_name" id="term_name" class="regular-text" required>
                            <p class="description">The display name for this recording type.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="term_slug">Slug</label></th>
                        <td>
                            <input type="text" name="term_slug" id="term_slug" class="regular-text">
                            <p class="description">Optional. Will be auto-generated from name if left blank.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="add_term" class="button button-primary" value="Add Recording Type">
                </p>
            </form>
        </div>

        <h2>Existing Recording Types</h2>
        <?php if (!empty($terms) && !is_wp_error($terms)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 30%;">Name</th>
                        <th style="width: 30%;">Slug</th>
                        <th style="width: 15%;">Count</th>
                        <th style="width: 25%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $term): ?>
                        <tr>
                            <td><strong><?php echo esc_html($term->name); ?></strong></td>
                            <td><code><?php echo esc_html($term->slug); ?></code></td>
                            <td><?php echo esc_html($term->count); ?> recordings</td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('edit-tags.php?action=edit&taxonomy=recording_type&tag_ID=' . $term->term_id . '&post_type=word_audio')); ?>" class="button button-small">Edit</a>

                                <form method="post" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this recording type? This cannot be undone.');">
                                    <?php wp_nonce_field('ll_delete_recording_type'); ?>
                                    <input type="hidden" name="term_id" value="<?php echo esc_attr($term->term_id); ?>">
                                    <input type="submit" name="delete_term" class="button button-small" value="Delete">
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No recording types found.</p>
        <?php endif; ?>

        <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2><?php echo esc_html__('Uncategorized Defaults', 'll-tools-text-domain'); ?></h2>
            <p class="description">
                <?php echo esc_html__('Choose which recording types to prompt for when a word has no category. Leave all unchecked to use the main defaults (Isolation, Question, Introduction).', 'll-tools-text-domain'); ?>
            </p>
            <form method="post" action="">
                <?php wp_nonce_field('ll_save_uncategorized_defaults'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Desired recording types', 'll-tools-text-domain'); ?></th>
                        <td>
                            <?php if (!empty($terms) && !is_wp_error($terms)) : ?>
                                <?php foreach ($terms as $term) : ?>
                                    <?php
                                        $slug = $term->slug;
                                        $checked = in_array($slug, (array) $current_uncategorized, true);
                                    ?>
                                    <label style="display:block; margin: 4px 0;">
                                        <input type="checkbox" name="ll_uncategorized_desired_recording_types[]" value="<?php echo esc_attr($slug); ?>" <?php checked($checked); ?>>
                                        <strong><?php echo esc_html($term->name); ?></strong>
                                        <span style="opacity:.7">(<?php echo esc_html($slug); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <em><?php echo esc_html__('No recording types defined yet.', 'll-tools-text-domain'); ?></em>
                            <?php endif; ?>
                            <p class="description" style="margin-top:8px;">
                                <?php echo esc_html__('Tip: Uncheck all and click Save to reset to main defaults.', 'll-tools-text-domain'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_uncategorized_defaults" class="button button-primary" value="<?php echo esc_attr__('Save', 'll-tools-text-domain'); ?>">
                </p>
            </form>
        </div>

        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #72aee6;">
            <h3>Tips</h3>
            <ul>
                <li>Common recording types: Isolation (word alone), Question (asking about the word), Introduction (using in context), Sentence (word in a sentence)</li>
                <li>Slugs should be lowercase and use hyphens instead of spaces</li>
                <li>Deleting a recording type will not delete associated recordings, but they will lose their type classification</li>
            </ul>
        </div>
    </div>
    <?php
}
