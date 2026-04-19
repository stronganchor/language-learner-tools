<?php
// Create the "Missing Audio" admin page
function ll_create_missing_audio_admin_page() {
    add_submenu_page(
        'tools.php',
        'Language Learner Tools - Missing Audio',
        'LL Tools Missing Audio',
        'view_ll_tools',
        'language-learner-tools-missing-audio',
        'll_render_missing_audio_admin_page'
    );
}
add_action('admin_menu', 'll_create_missing_audio_admin_page');
// Render the "Missing Audio" admin page
function ll_render_missing_audio_admin_page() {
    $missing_audio_instances = get_option('ll_missing_audio_instances', array());
    $missing_audio_instances = ll_missing_audio_sanitize_cache_keys($missing_audio_instances);
    $regex_patterns = ll_missing_audio_get_regex_patterns();
    $regex_preview = array();
    $apply_summary = array();
    $last_regex_pattern = '';
    $last_saved_pattern_id = '';
    $table_preview = array();
    $table_apply_summary = array();
    $available_table_headers = array();
    $last_selected_headers = array();
    $scroll_target = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['ll_missing_audio_nonce']) || !wp_verify_nonce($_POST['ll_missing_audio_nonce'], 'll_missing_audio_actions')) {
            wp_die(__('Security check failed.', 'll-tools-text-domain'));
        }

        $action = '';
        if (isset($_POST['clear_cache'])) {
            $action = 'clear_cache';
        } elseif (isset($_POST['scan_missing_audio'])) {
            $action = 'scan_missing_audio';
        } elseif (isset($_POST['save_regex_pattern'])) {
            $action = 'save_regex_pattern';
        } elseif (isset($_POST['delete_regex_pattern'])) {
            $action = 'delete_regex_pattern';
        } elseif (isset($_POST['preview_regex_matches'])) {
            $action = 'preview_regex_matches';
        } elseif (isset($_POST['apply_regex_insertions'])) {
            $action = 'apply_regex_insertions';
        } elseif (isset($_POST['preview_table_matches'])) {
            $action = 'preview_table_matches';
        } elseif (isset($_POST['apply_table_insertions'])) {
            $action = 'apply_table_insertions';
        } elseif (isset($_POST['remove_missing_audio'])) {
            $action = 'remove_missing_audio';
        }

        switch ($action) {
            case 'clear_cache':
                update_option('ll_missing_audio_instances', array());
                $missing_audio_instances = array();
                echo '<div class="notice notice-success"><p>' . esc_html__('Missing audio instances cache has been cleared.', 'll-tools-text-domain') . '</p></div>';
                break;

            case 'scan_missing_audio':
                $scan_result = ll_run_missing_audio_scan();
                $missing_audio_instances = $scan_result['missing_audio_instances'];

                $missing_count = intval($scan_result['missing_count']);
                $posts_scanned = intval($scan_result['posts_scanned']);
                printf(
                    '<div class="notice notice-success"><p>' . esc_html(
                        sprintf(
                            _n(
                                'Scan complete. Found %1$d missing audio entry across %2$d post.',
                                'Scan complete. Found %1$d missing audio entries across %2$d posts.',
                                $missing_count,
                                'll-tools-text-domain'
                            ),
                            $missing_count,
                            $posts_scanned
                        )
                    ) . '</p></div>'
                );
                break;

            case 'save_regex_pattern':
                $label = isset($_POST['regex_label']) ? sanitize_text_field(wp_unslash($_POST['regex_label'])) : '';
                $pattern = isset($_POST['regex_pattern']) ? trim(wp_unslash($_POST['regex_pattern'])) : '';
                $pattern_id = isset($_POST['pattern_id']) ? sanitize_text_field(wp_unslash($_POST['pattern_id'])) : '';

                if ($label === '' || $pattern === '') {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Please provide both a label and a regex pattern.', 'll-tools-text-domain') . '</p></div>';
                } else {
                    if (!ll_missing_audio_is_valid_regex($pattern)) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('The regex pattern is invalid. Please fix it and try again.', 'll-tools-text-domain') . '</p></div>';
                    } else {
                        $regex_patterns = ll_missing_audio_upsert_regex_pattern($label, $pattern, $pattern_id);
                        echo '<div class="notice notice-success"><p>' . esc_html__('Regex pattern saved.', 'll-tools-text-domain') . '</p></div>';
                    }
                }
                break;

            case 'delete_regex_pattern':
                $pattern_id = isset($_POST['pattern_id']) ? sanitize_text_field(wp_unslash($_POST['pattern_id'])) : '';
                if ($pattern_id !== '') {
                    $regex_patterns = ll_missing_audio_delete_regex_pattern($pattern_id);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Regex pattern removed.', 'll-tools-text-domain') . '</p></div>';
                }
                break;

            case 'preview_regex_matches':
            case 'apply_regex_insertions':
                $resolved = ll_missing_audio_resolve_pattern_from_request($regex_patterns, $_POST);
                $pattern_to_use = $resolved['pattern'];
                $last_regex_pattern = $pattern_to_use;
                $last_saved_pattern_id = $resolved['pattern_id'];

                if ($pattern_to_use === '') {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Please choose a saved regex or enter one to run.', 'll-tools-text-domain') . '</p></div>';
                    break;
                }

                if (!ll_missing_audio_is_valid_regex($pattern_to_use)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('The regex pattern is invalid. Please fix it and try again.', 'll-tools-text-domain') . '</p></div>';
                    break;
                }

                if ($action === 'preview_regex_matches') {
                    $regex_preview = ll_find_word_audio_regex_matches($pattern_to_use);
                    if (!empty($regex_preview['errors'])) {
                        foreach ($regex_preview['errors'] as $err) {
                            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                        }
                    } else {
                        $scroll_target = '#ll-regex-preview-results';
                        $match_total = isset($regex_preview['match_total']) ? intval($regex_preview['match_total']) : 0;
                        printf(
                            '<div class="notice notice-info"><p>%s</p></div>',
                            esc_html(
                                sprintf(
                                    _n(
                                        'Preview only. Found %1$d match across %2$d post.',
                                        'Preview only. Found %1$d matches across %2$d posts.',
                                        $match_total,
                                        'll-tools-text-domain'
                                    ),
                                    $match_total,
                                    intval($regex_preview['posts_with_matches'])
                                )
                            )
                        );
                    }
                } else {
                    $exclusions = ll_parse_regex_exclusions($_POST);
                    $apply_summary = ll_apply_word_audio_insertions($pattern_to_use, $exclusions);
                    $missing_audio_instances = get_option('ll_missing_audio_instances', array());

                    if (!empty($apply_summary['errors'])) {
                        foreach ($apply_summary['errors'] as $err) {
                            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                        }
                    }

                    $inserted = isset($apply_summary['shortcodes_inserted']) ? intval($apply_summary['shortcodes_inserted']) : 0;
                    $updated_posts = isset($apply_summary['posts_updated']) ? intval($apply_summary['posts_updated']) : 0;
                    $scroll_target = '#ll-regex-summary';
                    printf(
                        '<div class="notice notice-success"><p>%s</p></div>',
                        esc_html(
                            sprintf(
                                _n(
                                    'Inserted %1$d shortcode across %2$d post.',
                                    'Inserted %1$d shortcodes across %2$d posts.',
                                    $inserted,
                                    'll-tools-text-domain'
                                ),
                                $inserted,
                                $updated_posts
                            )
                        )
                    );
                }
                break;

            case 'preview_table_matches':
            case 'apply_table_insertions':
                $selected_table_headers = ll_missing_audio_parse_selected_headers($_POST);
                $last_selected_headers = $selected_table_headers;

                if (empty($selected_table_headers)) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Please choose at least one header to target.', 'll-tools-text-domain') . '</p></div>';
                    break;
                }

                if ($action === 'preview_table_matches') {
                    $table_preview = ll_find_table_column_word_audio_matches($selected_table_headers);
                    if (!empty($table_preview['errors'])) {
                        foreach ($table_preview['errors'] as $err) {
                            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                        }
                    } else {
                        $scroll_target = '#ll-table-preview-results';
                        $match_total = isset($table_preview['cell_count']) ? intval($table_preview['cell_count']) : 0;
                        printf(
                            '<div class="notice notice-info"><p>%s</p></div>',
                            esc_html(
                                sprintf(
                                    _n(
                                        'Preview only. Found %1$d cell across %2$d post.',
                                        'Preview only. Found %1$d cells across %2$d posts.',
                                        $match_total,
                                        'll-tools-text-domain'
                                    ),
                                    $match_total,
                                    intval($table_preview['posts_with_matches'])
                                )
                            )
                        );
                    }
                } else {
                    $cell_exclusions = ll_parse_table_exclusions($_POST);
                    $table_apply_summary = ll_apply_table_column_word_audio_insertions($selected_table_headers, $cell_exclusions);
                    $missing_audio_instances = get_option('ll_missing_audio_instances', array());

                    if (!empty($table_apply_summary['errors'])) {
                        foreach ($table_apply_summary['errors'] as $err) {
                            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                        }
                    }

                    $inserted = isset($table_apply_summary['shortcodes_inserted']) ? intval($table_apply_summary['shortcodes_inserted']) : 0;
                    $updated_posts = isset($table_apply_summary['posts_updated']) ? intval($table_apply_summary['posts_updated']) : 0;
                    $scroll_target = '#ll-table-summary';
                    printf(
                        '<div class="notice notice-success"><p>%s</p></div>',
                        esc_html(
                            sprintf(
                                _n(
                                    'Inserted %1$d shortcode across %2$d post.',
                                    'Inserted %1$d shortcodes across %2$d posts.',
                                    $inserted,
                                    'll-tools-text-domain'
                                ),
                                $inserted,
                                $updated_posts
                            )
                        )
                    );
                }
                break;

            case 'remove_missing_audio':
                $raw_word  = isset($_POST['remove_word']) ? wp_unslash($_POST['remove_word']) : '';
                $post_id   = isset($_POST['remove_post_id']) ? intval($_POST['remove_post_id']) : 0;
                $clean_key = ll_missing_audio_sanitize_word_text($raw_word);

                if ($clean_key === '') {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Invalid word provided.', 'll-tools-text-domain') . '</p></div>';
                    break;
                }

                $missing_audio_instances = get_option('ll_missing_audio_instances', array());
                $missing_audio_instances = ll_missing_audio_sanitize_cache_keys($missing_audio_instances);

                $removed_from_cache = false;
                if (isset($missing_audio_instances[$clean_key])) {
                    unset($missing_audio_instances[$clean_key]);
                    update_option('ll_missing_audio_instances', $missing_audio_instances);
                    $removed_from_cache = true;
                }

                $removed_shortcode = false;
                $updated_post = false;
                if ($post_id && current_user_can('edit_post', $post_id)) {
                    $content = get_post_field('post_content', $post_id);
                    if ($content !== null) {
                        $new_content = ll_missing_audio_remove_word_audio_shortcode($content, $clean_key);
                        if ($new_content !== $content) {
                            wp_update_post(array(
                                'ID'           => $post_id,
                                'post_content' => $new_content,
                            ));
                            $removed_shortcode = true;
                            $updated_post = true;
                        }
                    }
                }

                if ($removed_from_cache || $removed_shortcode) {
                    $msg = __('Removed word from missing audio list', 'll-tools-text-domain');
                    if ($removed_shortcode) {
                        $msg .= __(' and stripped [word_audio] shortcode', 'll-tools-text-domain');
                    }
                    echo '<div class="notice notice-success"><p>' . esc_html($msg) . '.</p></div>';
                } else {
                    echo '<div class="notice notice-info"><p>' . esc_html__('No changes made. The word may have already been removed.', 'll-tools-text-domain') . '</p></div>';
                }

                // Refresh the local copy
                $missing_audio_instances = get_option('ll_missing_audio_instances', array());
                $missing_audio_instances = ll_missing_audio_sanitize_cache_keys($missing_audio_instances);
                break;
        }
    }

    // Build header list for selection (post-processing to reflect any content updates)
    $available_table_headers = ll_missing_audio_collect_table_headers();
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Language Learner Tools - Missing Audio', 'll-tools-text-domain'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <p>
                <input type="submit" name="scan_missing_audio" class="button button-primary" value="<?php echo esc_attr__('Scan All Posts for Missing Audio', 'll-tools-text-domain'); ?>">
                <input type="submit" name="clear_cache" class="button button-secondary" value="<?php echo esc_attr__('Clear Cache', 'll-tools-text-domain'); ?>">
            </p>
        </form>
        <hr>
        <h2><?php echo esc_html__('Regex → Insert [word_audio]', 'll-tools-text-domain'); ?></h2>
        <p><?php echo wp_kses_post(sprintf(esc_html__('Find text via regex and wrap it with the %s shortcode. Patterns are stored for re-use and tagged with a comment label.', 'll-tools-text-domain'), '<code>[word_audio]</code>')); ?></p>

        <h3><?php echo esc_html__('Saved Regex Patterns', 'll-tools-text-domain'); ?></h3>
        <form method="post" style="margin-bottom:16px;">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <input type="hidden" name="pattern_id" value="">
            <p>
                <label for="regex_label"><strong><?php echo esc_html__('Label / comment', 'll-tools-text-domain'); ?></strong></label><br>
                <input type="text" id="regex_label" name="regex_label" class="regular-text" placeholder="<?php echo esc_attr__('e.g., Words in bold tags', 'll-tools-text-domain'); ?>" />
            </p>
            <p>
                <label for="regex_pattern"><strong><?php echo esc_html__('Regex (PHP delimiters required)', 'll-tools-text-domain'); ?></strong></label><br>
                <input type="text" id="regex_pattern" name="regex_pattern" class="large-text code" placeholder="<?php echo esc_attr__('#<strong>([^<]+)</strong>#i', 'll-tools-text-domain'); ?>" />
            </p>
            <p>
                <input type="submit" name="save_regex_pattern" class="button button-secondary" value="<?php echo esc_attr__('Save Pattern', 'll-tools-text-domain'); ?>">
            </p>
        </form>

        <?php if (!empty($regex_patterns)) : ?>
            <table class="wp-list-table widefat striped" style="margin-bottom:20px;">
                <thead>
                    <tr>
                        <th style="width:20%;"><?php echo esc_html__('Label', 'll-tools-text-domain'); ?></th>
                        <th><?php echo esc_html__('Regex', 'll-tools-text-domain'); ?></th>
                        <th style="width:20%;"><?php echo esc_html__('Actions', 'll-tools-text-domain'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($regex_patterns as $pattern_row) : ?>
                    <tr>
                        <td><?php echo esc_html($pattern_row['label']); ?></td>
                        <td><code><?php echo esc_html($pattern_row['pattern']); ?></code></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                                <input type="hidden" name="pattern_id" value="<?php echo esc_attr($pattern_row['id']); ?>">
                                <input type="text" name="regex_label" value="<?php echo esc_attr($pattern_row['label']); ?>" class="regular-text" style="max-width:140px;">
                                <input type="text" name="regex_pattern" value="<?php echo esc_attr($pattern_row['pattern']); ?>" class="regular-text code" style="max-width:220px;">
                                <input type="submit" name="save_regex_pattern" class="button button-small" value="<?php echo esc_attr__('Save', 'll-tools-text-domain'); ?>">
                            </form>
                            <form method="post" style="display:inline;margin-left:6px;">
                                <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                                <input type="hidden" name="pattern_id" value="<?php echo esc_attr($pattern_row['id']); ?>">
                                <input type="submit" name="delete_regex_pattern" class="button button-small button-link-delete" value="<?php echo esc_attr__('Delete', 'll-tools-text-domain'); ?>">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php echo esc_html__('No saved patterns yet.', 'll-tools-text-domain'); ?></p>
        <?php endif; ?>

        <h3><?php echo esc_html__('Find Matches & Insert Shortcodes', 'll-tools-text-domain'); ?></h3>
        <form method="post">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <p>
                <label for="saved_pattern_id"><strong><?php echo esc_html__('Saved pattern', 'll-tools-text-domain'); ?></strong></label><br>
                <select id="saved_pattern_id" name="saved_pattern_id">
                    <option value=""><?php echo esc_html__('— Select a saved pattern —', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($regex_patterns as $pattern_row) : ?>
                        <option value="<?php echo esc_attr($pattern_row['id']); ?>" <?php selected($last_saved_pattern_id, $pattern_row['id']); ?>>
                            <?php echo esc_html($pattern_row['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="run_regex_pattern"><strong><?php echo esc_html__('Or paste regex to run', 'll-tools-text-domain'); ?></strong></label><br>
                <input type="text" id="run_regex_pattern" name="regex_pattern" class="large-text code" value="<?php echo esc_attr($last_regex_pattern); ?>" placeholder="<?php echo esc_attr__('#\\b[A-Za-z]+\\b#u', 'll-tools-text-domain'); ?>">
            </p>
            <p class="description"><?php echo wp_kses_post(sprintf(esc_html__('Uses PHP regex with delimiters. Matches are wrapped as %s.', 'll-tools-text-domain'), '<code>[word_audio]match[/word_audio]</code>')); ?></p>
            <p>
                <input type="submit" name="preview_regex_matches" class="button button-primary" value="<?php echo esc_attr__('Preview Matches', 'll-tools-text-domain'); ?>">
                <input type="submit" name="apply_regex_insertions" class="button button-secondary" value="<?php echo esc_attr__('Insert Shortcodes Now', 'll-tools-text-domain'); ?>">
            </p>
        </form>

        <?php if (!empty($regex_preview) && empty($apply_summary)) : ?>
            <div id="ll-regex-preview-results"></div>
            <?php if (!empty($regex_preview['matches'])) : ?>
                <h3><?php echo esc_html__('Preview Results', 'll-tools-text-domain'); ?></h3>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            _n(
                                'Found %1$d match across %2$d post. No content has been changed yet. Uncheck items you want to exclude before applying.',
                                'Found %1$d matches across %2$d posts. No content has been changed yet. Uncheck items you want to exclude before applying.',
                                intval($regex_preview['match_total']),
                                'll-tools-text-domain'
                            ),
                            intval($regex_preview['match_total']),
                            intval($regex_preview['posts_with_matches'])
                        )
                    );
                    ?>
                </p>
                <form method="post">
                    <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                    <input type="hidden" name="regex_pattern" value="<?php echo esc_attr($last_regex_pattern); ?>">
                    <input type="hidden" name="saved_pattern_id" value="<?php echo esc_attr($last_saved_pattern_id); ?>">
                    <?php foreach ($regex_preview['matches'] as $post_id => $post_data) : ?>
                        <div style="margin-bottom:14px;">
                            <strong>
                                <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank">
                                    <?php echo esc_html($post_data['title']); ?>
                                </a>
                            </strong>
                            <ul style="margin:6px 0 0 18px; list-style:disc;">
                                <?php foreach ($post_data['matches'] as $match) : ?>
                                    <li>
                                        <label style="display:inline-block;">
                                            <input type="checkbox" name="exclude_regex_matches[<?php echo esc_attr($post_id); ?>][]" value="<?php echo esc_attr($match['id']); ?>">
                                            <?php echo esc_html__('Exclude', 'll-tools-text-domain'); ?>
                                        </label>
                                        <?php echo $match['context_html']; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <p>
                        <input type="submit" name="apply_regex_insertions" class="button button-secondary" value="Insert Shortcodes Now (respect exclusions)">
                    </p>
                </form>
            <?php else : ?>
                <p><em><?php echo esc_html__('No matches found for that regex.', 'll-tools-text-domain'); ?></em></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($apply_summary)) : ?>
            <div id="ll-regex-summary"></div>
            <h3><?php echo esc_html__('Insertion Summary', 'll-tools-text-domain'); ?></h3>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        _n(
                            'Updated %1$d post, inserted %2$d shortcode.',
                            'Updated %1$d posts, inserted %2$d shortcodes.',
                            intval($apply_summary['posts_updated']),
                            'll-tools-text-domain'
                        ),
                        intval($apply_summary['posts_updated']),
                        intval($apply_summary['shortcodes_inserted'])
                    )
                );
                ?>
            </p>
            <?php if (!empty($apply_summary['log'])) : ?>
                <?php foreach ($apply_summary['log'] as $log_row) : ?>
                    <div style="margin-bottom:10px;">
                        <strong>
                            <a href="<?php echo esc_url(get_edit_post_link($log_row['post_id'])); ?>" target="_blank">
                                <?php echo esc_html($log_row['title']); ?>
                            </a>
                        </strong>
                        <ul style="margin:6px 0 0 18px; list-style:disc;">
                            <?php foreach ($log_row['matches'] as $match) : ?>
                                <li><?php echo $match['context_html']; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

        <hr>
        <h2><?php echo esc_html__('Table Column → Insert [word_audio]', 'll-tools-text-domain'); ?></h2>
        <p><?php echo wp_kses_post(sprintf(esc_html__('Find HTML tables, choose header(s), and wrap every cell in those columns with %s. Existing shortcodes are skipped.', 'll-tools-text-domain'), '<code>[word_audio]</code>')); ?></p>
        <form method="post">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <div>
                <label for="table_headers"><strong><?php echo esc_html__('Select column headers to target', 'll-tools-text-domain'); ?></strong></label><br>
                <?php if (!empty($available_table_headers)) : ?>
                    <?php $header_select_size = min(15, max(6, count($available_table_headers))); ?>
                    <select id="table_headers" name="table_headers[]" multiple size="<?php echo esc_attr($header_select_size); ?>" class="widefat" style="max-height:320px;">
                        <?php foreach ($available_table_headers as $header) : ?>
                            <?php
                            $label = $header['label'];
                            $count = intval($header['count']);
                            $is_selected = in_array($label, $last_selected_headers, true) ? 'selected' : '';
                            ?>
                            <option value="<?php echo esc_attr($label); ?>" <?php echo $is_selected; ?>>
                                <?php echo esc_html($label . ($count > 1 ? " ({$count})" : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin-top:6px;">
                        <?php
                        echo esc_html(
                            sprintf(
                                _n(
                                    '%1$d unique header found. Use Ctrl/Cmd-click or Shift-click to select multiple.',
                                    '%1$d unique headers found. Use Ctrl/Cmd-click or Shift-click to select multiple.',
                                    intval(count($available_table_headers)),
                                    'll-tools-text-domain'
                                ),
                                intval(count($available_table_headers))
                            )
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p class="description" style="margin-top:6px;"><em><?php echo esc_html__('No table headers found in the scanned post types.', 'll-tools-text-domain'); ?></em></p>
                <?php endif; ?>
            </div>
            <p>
                <input type="submit" name="preview_table_matches" class="button button-primary" value="<?php echo esc_attr__('Preview Table Matches', 'll-tools-text-domain'); ?>">
                <input type="submit" name="apply_table_insertions" class="button button-secondary" value="<?php echo esc_attr__('Insert Shortcodes in Column Now', 'll-tools-text-domain'); ?>">
            </p>
        </form>

        <?php if (!empty($table_preview) && empty($table_apply_summary)) : ?>
            <div id="ll-table-preview-results"></div>
            <?php if (!empty($table_preview['matches'])) : ?>
                <h3><?php echo esc_html__('Table Preview Results', 'll-tools-text-domain'); ?></h3>
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            _n(
                                'Found %1$d cell across %2$d post. No content has been changed yet. Uncheck items you want to exclude before applying.',
                                'Found %1$d cells across %2$d posts. No content has been changed yet. Uncheck items you want to exclude before applying.',
                                intval($table_preview['cell_count']),
                                'll-tools-text-domain'
                            ),
                            intval($table_preview['cell_count']),
                            intval($table_preview['posts_with_matches'])
                        )
                    );
                    ?>
                </p>
                <form method="post">
                    <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                    <?php if (!empty($last_selected_headers)) : ?>
                        <?php foreach ($last_selected_headers as $sel_header) : ?>
                            <input type="hidden" name="table_headers[]" value="<?php echo esc_attr($sel_header); ?>">
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php foreach ($table_preview['matches'] as $post_id => $post_data) : ?>
                        <div style="margin-bottom:14px;">
                            <strong>
                                <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank">
                                    <?php echo esc_html($post_data['title']); ?>
                                </a>
                            </strong>
                            <ul style="margin:6px 0 0 18px; list-style:disc;">
                                <?php foreach ($post_data['cells'] as $cell) : ?>
                                    <li>
                                        <label style="display:inline-block;">
                                            <input type="checkbox" name="exclude_table_cells[<?php echo esc_attr($post_id); ?>][]" value="<?php echo esc_attr($cell['id']); ?>">
                                            <?php echo esc_html__('Exclude', 'll-tools-text-domain'); ?>
                                        </label>
                                        <?php echo $cell['context_html']; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                    <p>
                        <input type="submit" name="apply_table_insertions" class="button button-secondary" value="Insert Shortcodes in Column Now (respect exclusions)">
                    </p>
                </form>
            <?php else : ?>
                <p><em><?php echo esc_html__('No matching tables or cells found for the selected headers.', 'll-tools-text-domain'); ?></em></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($table_apply_summary)) : ?>
            <div id="ll-table-summary"></div>
            <h3><?php echo esc_html__('Table Insertion Summary', 'll-tools-text-domain'); ?></h3>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        _n(
                            'Updated %1$d post, inserted %2$d shortcode.',
                            'Updated %1$d posts, inserted %2$d shortcodes.',
                            intval($table_apply_summary['posts_updated']),
                            'll-tools-text-domain'
                        ),
                        intval($table_apply_summary['posts_updated']),
                        intval($table_apply_summary['shortcodes_inserted'])
                    )
                );
                ?>
            </p>
            <?php if (!empty($table_apply_summary['log'])) : ?>
                <?php foreach ($table_apply_summary['log'] as $log_row) : ?>
                    <div style="margin-bottom:10px;">
                        <strong>
                            <a href="<?php echo esc_url(get_edit_post_link($log_row['post_id'])); ?>" target="_blank">
                                <?php echo esc_html($log_row['title']); ?>
                            </a>
                        </strong>
                        <ul style="margin:6px 0 0 18px; list-style:disc;">
                            <?php foreach ($log_row['cells'] as $cell) : ?>
                                <li><?php echo $cell['context_html']; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

        <hr>
        <h2 id="ll-missing-audio-list"><?php echo esc_html__('Current Missing Audio', 'll-tools-text-domain'); ?></h2>
        <?php if (!empty($missing_audio_instances)) : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Word', 'll-tools-text-domain'); ?></th>
                        <th><?php echo esc_html__('Post', 'll-tools-text-domain'); ?></th>
                        <th><?php echo esc_html__('Actions', 'll-tools-text-domain'); ?></th>
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
                                    echo esc_html__('N/A', 'll-tools-text-domain');
                                }
                                ?>
                            </td>
                            <td>
                                <form method="post" style="margin:0;">
                                    <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                                    <input type="hidden" name="remove_word" value="<?php echo esc_attr($word); ?>">
                                    <input type="hidden" name="remove_post_id" value="<?php echo esc_attr(intval($post_id)); ?>">
                                    <button type="submit" name="remove_missing_audio" class="button button-small button-link-delete" style="padding-left:0;">
                                        <?php echo esc_html__('Remove & strip shortcode', 'll-tools-text-domain'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php echo esc_html__('No missing audio instances found.', 'll-tools-text-domain'); ?></p>
        <?php endif; ?>
    </div>
    <?php if (!empty($scroll_target)) : ?>
        <script>
            (function() {
                var target = document.querySelector('<?php echo esc_js($scroll_target); ?>');
                if (target && target.scrollIntoView) {
                    target.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            })();
        </script>
    <?php endif; ?>
    <?php
}

/**
 * Determine which post types should be scanned for missing audio shortcodes.
 *
 * @return array
 */
function ll_missing_audio_get_scan_post_types() {
    $post_types = get_post_types(array('public' => true, 'show_ui' => true), 'names');
    $excluded_types = array('revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'attachment', 'words', 'word_images', 'word_audio');
    $post_types = array_diff($post_types, $excluded_types);
    $post_types = apply_filters('ll_missing_audio_scan_post_types', $post_types);
    return $post_types;
}

/**
 * Scan all posts for [word_audio] shortcodes that lack matching audio and rebuild the cache.
 *
 * @return array {
 *     @type array $missing_audio_instances Updated missing audio map.
 *     @type int   $missing_count           Count of missing audio entries.
 *     @type int   $posts_scanned           Number of posts scanned.
 * }
 */
function ll_run_missing_audio_scan() {
    $missing_audio_instances = array();

    $post_types = ll_missing_audio_get_scan_post_types();

    if (empty($post_types)) {
        return array(
            'missing_audio_instances' => array(),
            'missing_count' => 0,
            'posts_scanned' => 0,
        );
    }

    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => array('publish'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    $posts_scanned = is_array($query->posts) ? count($query->posts) : 0;

    if (!empty($query->posts)) {
        foreach ($query->posts as $post_id) {
            $content = get_post_field('post_content', $post_id);
            if (empty($content) || stripos($content, '[word_audio') === false) {
                continue;
            }

        $found_missing = ll_extract_missing_audio_from_content($content, $post_id);
        if (!empty($found_missing)) {
            foreach ($found_missing as $word => $source_post_id) {
                $missing_audio_instances[$word] = $source_post_id;
            }
        }
        }
    }

    wp_reset_postdata();

    update_option('ll_missing_audio_instances', $missing_audio_instances);

    return array(
        'missing_audio_instances' => $missing_audio_instances,
        'missing_count' => count($missing_audio_instances),
        'posts_scanned' => $posts_scanned,
    );
}

/**
 * Parse a post's content for [word_audio] shortcodes that do not have a matching word/audio file.
 *
 * @param string $content   The post content.
 * @param int    $post_id   The post ID hosting the shortcode.
 * @return array            Map of missing words => post ID.
 */
function ll_extract_missing_audio_from_content($content, $post_id) {
    $missing = array();
    $shortcode_regex = get_shortcode_regex();

    if (!preg_match_all('/' . $shortcode_regex . '/s', $content, $matches, PREG_SET_ORDER)) {
        return $missing;
    }

    foreach ($matches as $shortcode) {
        if ($shortcode[2] !== 'word_audio') {
            continue;
        }

        $atts = shortcode_parse_atts($shortcode[3]);
        $shortcode_content = isset($shortcode[5]) ? $shortcode[5] : '';

        $context = ll_word_audio_extract_context(is_array($atts) ? $atts : array(), $shortcode_content);
        $is_missing_audio = empty($context['word_post']) || empty($context['audio_file']);
        $sanitized = ll_missing_audio_sanitize_word_text($context['normalized_content']);

        if ($is_missing_audio && $sanitized !== '') {
            $missing[$sanitized] = intval($post_id);
        }
    }

    return $missing;
}

/**
 * Retrieve saved regex patterns used for automated shortcode insertion.
 *
 * @return array
 */
function ll_missing_audio_get_regex_patterns() {
    $patterns = get_option('ll_word_audio_regex_patterns', array());
    if (!is_array($patterns)) {
        return array();
    }

    $normalized = array();
    foreach ($patterns as $row) {
        if (!isset($row['id'], $row['label'], $row['pattern'])) {
            continue;
        }
        $normalized[] = array(
            'id' => sanitize_text_field($row['id']),
            'label' => sanitize_text_field($row['label']),
            'pattern' => $row['pattern'],
        );
    }

    return $normalized;
}

/**
 * Persist the regex pattern list.
 *
 * @param array $patterns
 * @return void
 */
function ll_missing_audio_save_regex_patterns($patterns) {
    update_option('ll_word_audio_regex_patterns', array_values($patterns));
}

/**
 * Add or update a regex pattern (by id if provided).
 *
 * @param string $label
 * @param string $pattern
 * @param string $pattern_id
 * @return array Updated pattern list.
 */
function ll_missing_audio_upsert_regex_pattern($label, $pattern, $pattern_id = '') {
    $patterns = ll_missing_audio_get_regex_patterns();
    $updated = false;

    if ($pattern_id !== '') {
        foreach ($patterns as &$row) {
            if ($row['id'] === $pattern_id) {
                $row['label'] = $label;
                $row['pattern'] = $pattern;
                $updated = true;
                break;
            }
        }
        unset($row);
    }

    if (!$updated) {
        $patterns[] = array(
            'id' => $pattern_id !== '' ? $pattern_id : uniqid('regex_', true),
            'label' => $label,
            'pattern' => $pattern,
        );
    }

    ll_missing_audio_save_regex_patterns($patterns);
    return $patterns;
}

/**
 * Delete a saved regex pattern.
 *
 * @param string $pattern_id
 * @return array Updated pattern list.
 */
function ll_missing_audio_delete_regex_pattern($pattern_id) {
    $patterns = ll_missing_audio_get_regex_patterns();
    $remaining = array();
    foreach ($patterns as $row) {
        if ($row['id'] === $pattern_id) {
            continue;
        }
        $remaining[] = $row;
    }
    ll_missing_audio_save_regex_patterns($remaining);
    return $remaining;
}

/**
 * Resolve which regex to run based on request data and saved patterns.
 *
 * @param array $patterns
 * @param array $request
 * @return array { pattern, pattern_id }
 */
function ll_missing_audio_resolve_pattern_from_request($patterns, $request) {
    $selected_id = isset($request['saved_pattern_id']) ? sanitize_text_field(wp_unslash($request['saved_pattern_id'])) : '';
    $pattern = '';

    if ($selected_id !== '') {
        foreach ($patterns as $row) {
            if ($row['id'] === $selected_id) {
                $pattern = $row['pattern'];
                break;
            }
        }
    }

    if (!empty($request['regex_pattern'])) {
        $pattern = trim(wp_unslash($request['regex_pattern']));
        $selected_id = '';
    }

    return array(
        'pattern' => $pattern,
        'pattern_id' => $selected_id,
    );
}

/**
 * Light validation to ensure a regex compiles.
 *
 * @param string $pattern
 * @return bool
 */
function ll_missing_audio_is_valid_regex($pattern) {
    if ($pattern === '') {
        return false;
    }
    set_error_handler(function () { /* suppress */ });
    $is_valid = @preg_match($pattern, '') !== false;
    restore_error_handler();
    return $is_valid;
}

/**
 * Locate spans of existing [word_audio] shortcodes in content.
 *
 * @param string $content
 * @return array Array of [start, end] offsets.
 */
function ll_get_word_audio_shortcode_spans($content) {
    $spans = array();
    $shortcode_regex = get_shortcode_regex();

    if (preg_match_all('/' . $shortcode_regex . '/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $idx => $full_match) {
            if (!isset($matches[2][$idx][0]) || $matches[2][$idx][0] !== 'word_audio') {
                continue;
            }
            $start = $full_match[1];
            $length = strlen($full_match[0]);
            $spans[] = array($start, $start + $length);
        }
    }

    return $spans;
}

/**
 * Determine if an offset sits inside any existing shortcode span.
 *
 * @param int $offset
 * @param array $spans
 * @return bool
 */
function ll_is_offset_inside_spans($offset, $spans) {
    foreach ($spans as $span) {
        if ($offset >= $span[0] && $offset < $span[1]) {
            return true;
        }
    }
    return false;
}

/**
 * Build an HTML-safe context snippet highlighting the match.
 *
 * @param string $content
 * @param int    $offset
 * @param int    $length
 * @return string
 */
function ll_build_match_context_html($content, $offset, $length) {
    $radius = 45;
    $before_start = max(0, $offset - $radius);
    $before = ll_mb_strcut_safe($content, $before_start, $offset - $before_start);
    $match = ll_mb_strcut_safe($content, $offset, $length);
    $after = ll_mb_strcut_safe($content, $offset + $length, $radius);

    $prefix = $before_start > 0 ? '...' : '';
    $suffix = ($offset + $length + $radius) < ll_mb_strlen_safe($content) ? '...' : '';

    $before = wp_kses_decode_entities($before);
    $match = wp_kses_decode_entities($match);
    $after = wp_kses_decode_entities($after);

    return $prefix . esc_html($before) . '<strong>' . esc_html($match) . '</strong>' . esc_html($after) . $suffix;
}

/**
 * Find regex matches in eligible posts (preview only).
 *
 * @param string $pattern
 * @return array
 */
function ll_find_word_audio_regex_matches($pattern) {
    $post_types = ll_missing_audio_get_scan_post_types();
    $results = array(
        'matches' => array(),
        'match_total' => 0,
        'posts_with_matches' => 0,
        'errors' => array(),
    );

    if (empty($post_types)) {
        return $results;
    }

    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => array('publish'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (!empty($query->posts)) {
        foreach ($query->posts as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $content = get_post_field('post_content', $post_id);
            if ($content === '') {
                continue;
            }

            $spans = ll_get_word_audio_shortcode_spans($content);

            set_error_handler(function () { /* suppress */ });
            $match_count = @preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE);
            restore_error_handler();

            if ($match_count === false) {
                $results['errors'][] = sprintf(__('Failed to run regex on post ID %d', 'll-tools-text-domain'), $post_id);
                continue;
            }

            if ($match_count === 0) {
                continue;
            }

            $post_matches = array();
            foreach ($found[0] as $match) {
                $text = $match[0];
                $offset = intval($match[1]);
                if ($text === '') {
                    continue;
                }
                if (ll_is_offset_inside_spans($offset, $spans)) {
                    continue;
                }

                $sanitized_text = ll_missing_audio_sanitize_word_text($text);
                if ($sanitized_text === '') {
                    continue;
                }

                $post_matches[] = array(
                    'id' => $offset . ':' . strlen($text),
                    'text' => $text,
                    'sanitized_text' => $sanitized_text,
                    'offset' => $offset,
                    'length' => strlen($text),
                    'context_html' => '<strong>' . esc_html($sanitized_text) . '</strong>',
                );
            }

            if (!empty($post_matches)) {
                $results['matches'][$post_id] = array(
                    'title' => get_the_title($post_id),
                    'matches' => $post_matches,
                );
                $results['match_total'] += count($post_matches);
                $results['posts_with_matches']++;
            }
        }
    }

    wp_reset_postdata();

    return $results;
}

/**
 * Apply regex matches by wrapping them in [word_audio] shortcodes and update the missing audio cache.
 *
 * @param string $pattern
 * @return array Summary data.
 */
function ll_apply_word_audio_insertions($pattern, $exclusions = array()) {
    $post_types = ll_missing_audio_get_scan_post_types();
    $summary = array(
        'posts_updated' => 0,
        'shortcodes_inserted' => 0,
        'log' => array(),
        'errors' => array(),
    );

    if (empty($post_types)) {
        return $summary;
    }

    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => array('publish'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (!empty($query->posts)) {
        foreach ($query->posts as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $content = get_post_field('post_content', $post_id);
            if ($content === '') {
                continue;
            }

            $spans = ll_get_word_audio_shortcode_spans($content);

            set_error_handler(function () { /* suppress */ });
            $match_count = @preg_match_all($pattern, $content, $found, PREG_OFFSET_CAPTURE);
            restore_error_handler();

            if ($match_count === false) {
                $summary['errors'][] = sprintf(__('Failed to run regex on post ID %d', 'll-tools-text-domain'), $post_id);
                continue;
            }

            if ($match_count === 0) {
                continue;
            }

            $matches_for_replacement = array();
            foreach ($found[0] as $match) {
                $text = $match[0];
                $offset = intval($match[1]);
                if ($text === '') {
                    continue;
                }
                if (ll_is_offset_inside_spans($offset, $spans)) {
                    continue;
                }

                $match_id = $offset . ':' . strlen($text);
                $is_excluded = isset($exclusions[$post_id]) && in_array($match_id, $exclusions[$post_id], true);
                if ($is_excluded) {
                    continue;
                }

                $sanitized_text = ll_missing_audio_sanitize_word_text($text);
                if ($sanitized_text === '') {
                    continue;
                }

                $matches_for_replacement[] = array(
                    'id' => $match_id,
                    'text' => $text,
                    'sanitized_text' => $sanitized_text,
                    'offset' => $offset,
                    'length' => strlen($text),
                    'context_html' => '<strong>' . esc_html($sanitized_text) . '</strong>',
                );
            }

            if (empty($matches_for_replacement)) {
                continue;
            }

            usort($matches_for_replacement, function ($a, $b) {
                return $a['offset'] - $b['offset'];
            });

            $updated_content = ll_wrap_matches_with_word_audio_shortcode($content, $matches_for_replacement);

            if ($updated_content !== $content) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $updated_content,
                ));

                $summary['posts_updated']++;
                $summary['shortcodes_inserted'] += count($matches_for_replacement);
                $summary['log'][] = array(
                    'post_id' => $post_id,
                    'title' => get_the_title($post_id),
                    'matches' => $matches_for_replacement,
                );

                foreach ($matches_for_replacement as $match_row) {
                    $sanitized = ll_missing_audio_sanitize_word_text($match_row['sanitized_text']);
                    if ($sanitized !== '') {
                        $normalized = ll_normalize_case($sanitized);
                        ll_cache_missing_audio_instance($normalized, $post_id);
                    }
                }
            }
        }
    }

    wp_reset_postdata();

    return $summary;
}

/**
 * Wrap matches in [word_audio] shortcodes using position-based replacement.
 *
 * @param string $content
 * @param array  $matches
 * @return string
 */
function ll_wrap_matches_with_word_audio_shortcode($content, $matches) {
    $offset_shift = 0;
    $last_end = -1;

    foreach ($matches as $match) {
        $offset = $match['offset'] + $offset_shift;
        $length = $match['length'];
        if ($offset < $last_end) {
            // Overlapping; skip to avoid double-wrapping.
            continue;
        }

        $replacement = '[word_audio]' . $match['text'] . '[/word_audio]';
        $content = substr_replace($content, $replacement, $offset, $length);

        $offset_shift += strlen($replacement) - $length;
        $last_end = $offset + strlen($replacement);
    }

    return $content;
}

/**
 * Get inner HTML for a DOMNode.
 *
 * @param DOMNode $node
 * @return string
 */
function ll_dom_inner_html(DOMNode $node) {
    $innerHTML = '';
    foreach ($node->childNodes as $child) {
        $innerHTML .= $node->ownerDocument->saveHTML($child);
    }
    return $innerHTML;
}

/**
 * Return the header row cells for a table (thead > tr preferred, otherwise first tr).
 *
 * @param DOMElement $table
 * @return array
 */
function ll_get_table_header_cells($table) {
    $header_row = null;

    $thead = $table->getElementsByTagName('thead');
    if ($thead->length > 0) {
        $trs = $thead->item(0)->getElementsByTagName('tr');
        if ($trs->length > 0) {
            $header_row = $trs->item(0);
        }
    }

    if (!$header_row) {
        $trs = $table->getElementsByTagName('tr');
        if ($trs->length > 0) {
            $header_row = $trs->item(0);
        }
    }

    if (!$header_row) {
        return array();
    }

    $cells = array();
    foreach ($header_row->childNodes as $cell) {
        if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), array('th', 'td'), true)) {
            $cells[] = $cell;
        }
    }

    return $cells;
}

/**
 * Get all data rows (tr) excluding the header row.
 *
 * @param DOMElement $table
 * @param DOMElement $header_row
 * @return array
 */
function ll_get_table_data_rows($table, $header_row) {
    $rows = array();
    $tbody = $table->getElementsByTagName('tbody');
    if ($tbody->length > 0) {
        foreach ($tbody->item(0)->getElementsByTagName('tr') as $tr) {
            if ($tr === $header_row) {
                continue;
            }
            $rows[] = $tr;
        }
    } else {
        foreach ($table->getElementsByTagName('tr') as $tr) {
            if ($tr === $header_row) {
                continue;
            }
            $rows[] = $tr;
        }
    }
    return $rows;
}

/**
 * Normalize header text for matching (case-insensitive, trimmed).
 *
 * @param string $text
 * @return string
 */
function ll_missing_audio_normalize_header_text($text) {
    $text = wp_strip_all_tags((string) $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    return $text === '' ? '' : (function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
}

/**
 * Collect a unique, sorted list of table headers across scanned post types.
 *
 * Excludes headers whose corresponding columns already have [word_audio]
 * in every data cell across all occurrences.
 *
 * @return array Each entry: ['label' => string, 'count' => int]
 */
function ll_missing_audio_collect_table_headers() {
    $post_types = ll_missing_audio_get_scan_post_types();
    $headers = array();

    if (empty($post_types)) {
        return $headers;
    }

    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => array('publish'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (!empty($query->posts)) {
        foreach ($query->posts as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $content = get_post_field('post_content', $post_id);
            if ($content === '') {
                continue;
            }

            $dom = ll_dom_load_html_utf8($content);
            if (!$dom) {
                continue;
            }

            $tables = $dom->getElementsByTagName('table');
            if ($tables->length === 0) {
                continue;
            }

            foreach ($tables as $table) {
                $header_cells = ll_get_table_header_cells($table);
                if (empty($header_cells)) {
                    continue;
                }
                foreach ($header_cells as $idx => $cell) {
                    $header_text = trim(wp_strip_all_tags(ll_dom_inner_html($cell)));
                    $normalized = ll_missing_audio_normalize_header_text($header_text);
                    if ($normalized === '') {
                        continue;
                    }
                    $rows = ll_get_table_data_rows($table, $header_cells[0]->parentNode);
                    $had_rows = false;
                    $column_all_has_shortcode = true;
                    foreach ($rows as $tr) {
                        $cells = array();
                        foreach ($tr->childNodes as $cell_node) {
                            if ($cell_node->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell_node->nodeName), array('td', 'th'), true)) {
                                $cells[] = $cell_node;
                            }
                        }
                        if (!isset($cells[$idx])) {
                            continue;
                        }
                        $had_rows = true;
                        $cell_html = ll_dom_inner_html($cells[$idx]);
                        if (stripos($cell_html, '[word_audio') === false) {
                            $column_all_has_shortcode = false;
                            break;
                        }
                    }

                    $column_complete = $had_rows && $column_all_has_shortcode;

                    if (!isset($headers[$normalized])) {
                        $headers[$normalized] = array(
                            'label' => $header_text,
                            'count' => 1,
                            'all_complete' => $column_complete,
                        );
                    } else {
                        $headers[$normalized]['count']++;
                        $headers[$normalized]['all_complete'] = $headers[$normalized]['all_complete'] && $column_complete;
                    }
                }
            }
        }
    }

    wp_reset_postdata();

    // Drop headers that are fully covered by existing shortcodes everywhere
    $filtered = array_filter($headers, function ($meta) {
        return empty($meta['all_complete']);
    });

    if (!empty($filtered)) {
        uasort($filtered, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });
    }

    return array_values($filtered);
}

/**
 * Parse selected table headers from a request payload.
 *
 * @param array $request
 * @return array
 */
function ll_missing_audio_parse_selected_headers($request) {
    $selected = array();
    if (empty($request['table_headers']) || !is_array($request['table_headers'])) {
        return $selected;
    }
    foreach ($request['table_headers'] as $hdr) {
        $clean = sanitize_text_field(wp_unslash($hdr));
        if ($clean === '') {
            continue;
        }
        $selected[$clean] = true;
    }
    return array_keys($selected);
}

/**
 * Find table cells under selected headers for preview.
 *
 * @param array $header_targets
 * @return array
 */
function ll_find_table_column_word_audio_matches($header_targets) {
    $post_types = ll_missing_audio_get_scan_post_types();
    $results = array(
        'matches' => array(),
        'cell_count' => 0,
        'posts_with_matches' => 0,
        'errors' => array(),
    );

    $target_map = array();
    $targets = is_array($header_targets) ? $header_targets : array($header_targets);
    foreach ($targets as $hdr) {
        $normalized = ll_missing_audio_normalize_header_text($hdr);
        if ($normalized !== '') {
            $target_map[$normalized] = true;
        }
    }

    if (empty($target_map)) {
        $results['errors'][] = __('No headers selected for matching.', 'll-tools-text-domain');
        return $results;
    }

    if (empty($post_types)) {
        return $results;
    }

    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => array('publish'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (!empty($query->posts)) {
        foreach ($query->posts as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $content = get_post_field('post_content', $post_id);
            if ($content === '') {
                continue;
            }

            $dom = ll_dom_load_html_utf8($content);
            if (!$dom) {
                continue;
            }

            $tables = $dom->getElementsByTagName('table');
            if ($tables->length === 0) {
                continue;
            }

            $post_cells = array();

            foreach ($tables as $table_index => $table) {
                $header_cells = ll_get_table_header_cells($table);
                if (empty($header_cells)) {
                    continue;
                }

                $matching_indexes = array();
                foreach ($header_cells as $idx => $cell) {
                    $header_text = trim(wp_strip_all_tags(ll_dom_inner_html($cell)));
                    $normalized = ll_missing_audio_normalize_header_text($header_text);
                    if ($normalized !== '' && isset($target_map[$normalized])) {
                        $matching_indexes[] = $idx;
                    }
                }

                if (empty($matching_indexes)) {
                    continue;
                }

                $rows = ll_get_table_data_rows($table, $header_cells[0]->parentNode);
                foreach ($rows as $row_idx => $tr) {
                    $cells = array();
                    foreach ($tr->childNodes as $cell) {
                        if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), array('td', 'th'), true)) {
                            $cells[] = $cell;
                        }
                    }
                    foreach ($matching_indexes as $target_idx) {
                        if (!isset($cells[$target_idx])) {
                            continue;
                        }
                        $cell_html = ll_dom_inner_html($cells[$target_idx]);
                        if (stripos($cell_html, '[word_audio') !== false) {
                            continue;
                        }
                        $normalized_parts = ll_missing_audio_extract_normalized_segments($cell_html);
                        if (empty($normalized_parts)) {
                            continue;
                        }

                        $base_id = $table_index . ':' . $row_idx . ':' . $target_idx;
                        foreach ($normalized_parts as $part) {
                            $post_cells[] = array(
                                'id' => $base_id,
                                'context_html' => '<strong>' . esc_html($part) . '</strong>',
                            );
                        }
                    }
                }
            }

            if (!empty($post_cells)) {
                $results['matches'][$post_id] = array(
                    'title' => get_the_title($post_id),
                    'cells' => $post_cells,
                );
                $results['cell_count'] += count($post_cells);
                $results['posts_with_matches']++;
            }
        }
    }

    wp_reset_postdata();

    return $results;
}

/**
 * Apply insertion inside table column cells matched by selected headers.
 *
 * @param array $header_targets
 * @param array $exclusions
 * @return array
 */
function ll_apply_table_column_word_audio_insertions($header_targets, $exclusions = array()) {
    $post_types = ll_missing_audio_get_scan_post_types();
    $summary = array(
        'posts_updated' => 0,
        'shortcodes_inserted' => 0,
        'log' => array(),
        'errors' => array(),
    );

    $target_map = array();
    $targets = is_array($header_targets) ? $header_targets : array($header_targets);
    foreach ($targets as $hdr) {
        $normalized = ll_missing_audio_normalize_header_text($hdr);
        if ($normalized !== '') {
            $target_map[$normalized] = true;
        }
    }

    if (empty($target_map)) {
        $summary['errors'][] = __('No headers selected for matching.', 'll-tools-text-domain');
        return $summary;
    }

    if (empty($post_types)) {
        return $summary;
    }

    $query = new WP_Query(array(
        'post_type'      => $post_types,
        'post_status'    => array('publish'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (!empty($query->posts)) {
        foreach ($query->posts as $post_id) {
            if (!current_user_can('edit_post', $post_id)) {
                continue;
            }

            $content = get_post_field('post_content', $post_id);
            if ($content === '') {
                continue;
            }

            $dom = ll_dom_load_html_utf8($content);
            if (!$dom) {
                continue;
            }

            $tables = $dom->getElementsByTagName('table');
            if ($tables->length === 0) {
                continue;
            }

            $post_log_cells = array();
            $inserted_here = 0;

            foreach ($tables as $table_index => $table) {
                $header_cells = ll_get_table_header_cells($table);
                if (empty($header_cells)) {
                    continue;
                }

                $matching_indexes = array();
                foreach ($header_cells as $idx => $cell) {
                    $header_text = trim(wp_strip_all_tags(ll_dom_inner_html($cell)));
                    $normalized = ll_missing_audio_normalize_header_text($header_text);
                    if ($normalized !== '' && isset($target_map[$normalized])) {
                        $matching_indexes[] = $idx;
                    }
                }

                if (empty($matching_indexes)) {
                    continue;
                }

                $rows = ll_get_table_data_rows($table, $header_cells[0]->parentNode);
                foreach ($rows as $row_idx => $tr) {
                    $cells = array();
                    foreach ($tr->childNodes as $cell) {
                        if ($cell->nodeType === XML_ELEMENT_NODE && in_array(strtolower($cell->nodeName), array('td', 'th'), true)) {
                            $cells[] = $cell;
                        }
                    }
                    foreach ($matching_indexes as $target_idx) {
                        if (!isset($cells[$target_idx])) {
                            continue;
                        }
                        $cell_html = ll_dom_inner_html($cells[$target_idx]);
                        if (stripos($cell_html, '[word_audio') !== false) {
                            continue;
                        }
                        $text_value = ll_missing_audio_sanitize_word_text($cell_html);
                        if ($text_value === '') {
                            continue;
                        }

                        $cell_id = $table_index . ':' . $row_idx . ':' . $target_idx;
                        $is_excluded = isset($exclusions[$post_id]) && in_array($cell_id, $exclusions[$post_id], true);
                        if ($is_excluded) {
                            continue;
                        }

                        ll_missing_audio_wrap_cell_with_split_support($cells[$target_idx]);
                        $preview_parts = ll_missing_audio_extract_normalized_segments($cell_html);
                        $display_string = !empty($preview_parts) ? implode(' / ', $preview_parts) : $text_value;
                        $post_log_cells[] = array(
                            'context_html' => '<strong>' . esc_html($display_string) . '</strong>',
                            'id' => $cell_id,
                        );
                        $inserted_here++;

                        $normalized_parts = !empty($preview_parts) ? $preview_parts : array($text_value);
                        foreach ($normalized_parts as $part) {
                            $normalized = function_exists('ll_normalize_case') ? ll_normalize_case($part) : $part;
                            ll_cache_missing_audio_instance($normalized, $post_id);
                        }
                    }
                }
            }

            if ($inserted_here > 0) {
                $container = $dom->getElementById('ll-root');
                if ($container) {
                    $new_content = ll_dom_inner_html($container);
                } else {
                    $new_content = $dom->saveHTML();
                }

                if ($new_content !== $content) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $new_content,
                    ));
                    $summary['posts_updated']++;
                    $summary['shortcodes_inserted'] += $inserted_here;
                    $summary['log'][] = array(
                        'post_id' => $post_id,
                        'title' => get_the_title($post_id),
                        'cells' => $post_log_cells,
                    );
                }
            }
        }
    }

    wp_reset_postdata();

    return $summary;
}

/**
 * Wrap the contents of a table cell in [word_audio] shortcodes.
 *
 * @param DOMElement $cell
 * @return void
 */
function ll_wrap_table_cell_with_shortcode($cell) {
    $doc = $cell->ownerDocument;
    $clones = array();
    foreach ($cell->childNodes as $child) {
        $clones[] = $child->cloneNode(true);
    }
    while ($cell->firstChild) {
        $cell->removeChild($cell->firstChild);
    }

    $cell->appendChild($doc->createTextNode('[word_audio]'));
    foreach ($clones as $clone) {
        $cell->appendChild($clone);
    }
    $cell->appendChild($doc->createTextNode('[/word_audio]'));
}

/**
 * Replace a cell's inner HTML with parsed HTML content.
 *
 * @param DOMElement $cell
 * @param string     $html
 * @return bool True on success
 */
function ll_missing_audio_replace_cell_html($cell, $html) {
    $doc = $cell->ownerDocument;
    $parsed = ll_dom_load_html_utf8($html);
    if (!$parsed) {
        return false;
    }
    $wrapper = $parsed->getElementById('ll-root');
    if (!$wrapper) {
        return false;
    }

    while ($cell->firstChild) {
        $cell->removeChild($cell->firstChild);
    }

    foreach ($wrapper->childNodes as $child) {
        $imported = $doc->importNode($child, true);
        $cell->appendChild($imported);
    }

    return true;
}

/**
 * Wraps cell content with multiple [word_audio] tags when separated by "/" or ",".
 * Falls back to wrapping the entire cell if splitting fails.
 *
 * @param DOMElement $cell
 * @return void
 */
function ll_missing_audio_wrap_cell_with_split_support($cell) {
    $cell_html = ll_dom_inner_html($cell);
    if (strpos($cell_html, '/') === false && strpos($cell_html, ',') === false) {
        ll_wrap_table_cell_with_shortcode($cell);
        return;
    }

    $segments = ll_missing_audio_split_outside_markup($cell_html);
    $has_delim = false;
    foreach ($segments as $seg) {
        if ($seg['type'] === 'delim') {
            $has_delim = true;
            break;
        }
    }
    if (!$has_delim) {
        ll_wrap_table_cell_with_shortcode($cell);
        return;
    }

    $rebuilt = array();
    foreach ($segments as $seg) {
        if ($seg['type'] === 'delim') {
            $rebuilt[] = $seg['value'];
            continue;
        }

        $piece = $seg['value'];
        $leading = '';
        $trailing = '';
        $core = $piece;
        if (preg_match('/^(\s*)(.*?)(\s*)$/us', $piece, $m)) {
            $leading = $m[1];
            $core = $m[2];
            $trailing = $m[3];
        }

        $core_normalized = ll_missing_audio_sanitize_word_text($core);
        if ($core_normalized === '') {
            $rebuilt[] = $piece;
            continue;
        }

        $rebuilt[] = $leading . '[word_audio]' . $core . '[/word_audio]' . $trailing;
    }

    $new_html = implode('', $rebuilt);
    if (!ll_missing_audio_replace_cell_html($cell, $new_html)) {
        ll_wrap_table_cell_with_shortcode($cell);
    }
}

/**
 * Split text into segments on "/" or "," only when not inside shortcode/HTML tags.
 *
 * @param string $html
 * @return array[] ['type' => 'text'|'delim', 'value' => string]
 */
function ll_missing_audio_split_outside_markup($html) {
    $segments = array();
    $buffer = '';
    $bracket_depth = 0;
    $tag_depth = 0;
    $paren_depth = 0;
    $len = ll_mb_strlen_safe($html);

    for ($i = 0; $i < $len; $i++) {
        $ch = ll_mb_substr_safe($html, $i, 1);

        if ($ch === '[') {
            $bracket_depth++;
            $buffer .= $ch;
            continue;
        }
        if ($ch === ']') {
            if ($bracket_depth > 0) { $bracket_depth--; }
            $buffer .= $ch;
            continue;
        }
        if ($ch === '<') {
            $tag_depth++;
            $buffer .= $ch;
            continue;
        }
        if ($ch === '>') {
            if ($tag_depth > 0) { $tag_depth--; }
            $buffer .= $ch;
            continue;
        }

        if (($ch === '/' || $ch === ',') && $bracket_depth === 0 && $tag_depth === 0 && $paren_depth === 0) {
            if ($buffer !== '') {
                $segments[] = array('type' => 'text', 'value' => $buffer);
                $buffer = '';
            }
            $segments[] = array('type' => 'delim', 'value' => $ch);
            continue;
        }

        if ($ch === '(' && $bracket_depth === 0 && $tag_depth === 0) {
            $paren_depth++;
        } elseif ($ch === ')' && $bracket_depth === 0 && $tag_depth === 0 && $paren_depth > 0) {
            $paren_depth--;
        }

        if (($ch === '/' || $ch === ',') && $paren_depth > 0) {
            $buffer .= $ch;
            continue;
        }

        $buffer .= $ch;
    }

    if ($buffer !== '') {
        $segments[] = array('type' => 'text', 'value' => $buffer);
    }

    return $segments;
}

/**
 * Build a preview string showing how split wrapping will look.
 *
 * @param string $html
 * @return string
 */
function ll_missing_audio_build_split_preview_string($html) {
    $segments = ll_missing_audio_split_outside_markup($html);
    $rebuilt = array();

    foreach ($segments as $seg) {
        if ($seg['type'] === 'delim') {
            $rebuilt[] = $seg['value'];
            continue;
        }
        $piece = $seg['value'];

        $leading = '';
        $trailing = '';
        $core = $piece;
        if (preg_match('/^(\s*)(.*?)(\s*)$/us', $piece, $m)) {
            $leading = $m[1];
            $core = $m[2];
            $trailing = $m[3];
        }

        $core_normalized = ll_missing_audio_sanitize_word_text($core);
        if ($core_normalized === '') {
            $rebuilt[] = $piece;
            continue;
        }

        $rebuilt[] = $leading . '[word_audio]' . $core . '[/word_audio]' . $trailing;
    }

    return implode('', $rebuilt);
}

/**
 * Extract normalized word parts split on "/" or "," outside markup.
 *
 * @param string $html
 * @return array
 */
function ll_missing_audio_extract_normalized_segments($html) {
    $segments = ll_missing_audio_split_outside_markup($html);
    $words = array();
    foreach ($segments as $seg) {
        if ($seg['type'] !== 'text') {
            continue;
        }
        $normalized = ll_missing_audio_sanitize_word_text($seg['value']);
        if ($normalized === '') {
            continue;
        }
        $words[] = $normalized;
    }
    return array_values(array_unique($words));
}

/*
 * Load HTML into a DOMDocument with UTF-8 enforced.
 *
 * @param string $body_html
 * @return DOMDocument|null
 */
function ll_dom_load_html_utf8($body_html) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $wrapped = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="ll-root">' . $body_html . '</div></body></html>';
    $loaded = @$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    return $loaded ? $dom : null;
}

/**
 * Sanitize a word string for missing-audio purposes.
 *
 * @param string $text
 * @return string
 */
function ll_missing_audio_sanitize_word_text($text) {
    if (function_exists('ll_sanitize_word_title_text')) {
        return ll_sanitize_word_title_text($text);
    }
    $text = (string) $text;
    // Normalize curly/Unicode apostrophes to a straight quote so cache keys stay consistent
    $text = str_replace(
        array("\u{2019}", "\u{2018}", "\u{201B}", "\u{02BC}", "\u{FF07}"),
        "'",
        $text
    );
    if (function_exists('ll_strip_shortcodes_preserve_content')) {
        $text = ll_strip_shortcodes_preserve_content($text);
    } elseif (function_exists('strip_shortcodes')) {
        $text = strip_shortcodes($text);
    }
    $text = preg_replace('/\[[^\]]+\]/u', '', $text);
    $text = wp_strip_all_tags($text);
    $text = preg_replace('/\s*\([^)]*\)/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

/**
 * Remove [word_audio] shortcodes whose inner text matches the target word.
 *
 * @param string $content
 * @param string $target_word
 * @return string
 */
function ll_missing_audio_remove_word_audio_shortcode($content, $target_word) {
    if ($content === '' || $target_word === '') {
        return $content;
    }

    $normalized_target = ll_missing_audio_sanitize_word_text($target_word);
    if ($normalized_target === '') {
        return $content;
    }

    $pattern = '/\[word_audio([^\]]*)\](.*?)\[\/word_audio\]/is';
    $replaced = preg_replace_callback($pattern, function ($m) use ($normalized_target) {
        $inner = isset($m[2]) ? $m[2] : '';
        $normalized_inner = ll_missing_audio_sanitize_word_text($inner);
        if ($normalized_inner === $normalized_target) {
            // Strip shortcode, keep inner content
            return $inner;
        }
        return $m[0];
    }, $content);

    return $replaced === null ? $content : $replaced;
}

/**
 * Sanitize and deduplicate missing audio cache keys.
 *
 * @param array $instances
 * @return array
 */
function ll_missing_audio_sanitize_cache_keys($instances) {
    if (!is_array($instances)) {
        return array();
    }
    $sanitized = array();
    foreach ($instances as $word => $post_id) {
        $clean = ll_missing_audio_sanitize_word_text($word);
        if ($clean === '') {
            continue;
        }
        $sanitized[$clean] = intval($post_id);
    }
    if ($sanitized !== $instances) {
        update_option('ll_missing_audio_instances', $sanitized);
    }
    return $sanitized;
}

/**
 * Parse regex exclusions from request.
 *
 * @param array $request
 * @return array
 */
function ll_parse_regex_exclusions($request) {
    $exclusions = array();
    if (empty($request['exclude_regex_matches']) || !is_array($request['exclude_regex_matches'])) {
        return $exclusions;
    }
    foreach ($request['exclude_regex_matches'] as $post_id => $ids) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            continue;
        }
        if (!is_array($ids)) {
            continue;
        }
        $clean_ids = array();
        foreach ($ids as $id) {
            $clean_ids[] = sanitize_text_field(wp_unslash($id));
        }
        if (!empty($clean_ids)) {
            $exclusions[$post_id] = $clean_ids;
        }
    }
    return $exclusions;
}

/**
 * Parse table cell exclusions from request.
 *
 * @param array $request
 * @return array
 */
function ll_parse_table_exclusions($request) {
    $exclusions = array();
    if (empty($request['exclude_table_cells']) || !is_array($request['exclude_table_cells'])) {
        return $exclusions;
    }
    foreach ($request['exclude_table_cells'] as $post_id => $ids) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            continue;
        }
        if (!is_array($ids)) {
            continue;
        }
        $clean_ids = array();
        foreach ($ids as $id) {
            $clean_ids[] = sanitize_text_field(wp_unslash($id));
        }
        if (!empty($clean_ids)) {
            $exclusions[$post_id] = $clean_ids;
        }
    }
    return $exclusions;
}

/**
 * Multibyte-safe strlen fallback.
 *
 * @param string $text
 * @return int
 */
function ll_mb_strlen_safe($text) {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

/**
 * Multibyte-safe substr fallback.
 *
 * @param string   $text
 * @param int      $start
 * @param int|null $length
 * @return string
 */
function ll_mb_substr_safe($text, $start, $length = null) {
    if (function_exists('mb_substr')) {
        return $length === null ? mb_substr($text, $start, null, 'UTF-8') : mb_substr($text, $start, $length, 'UTF-8');
    }
    return $length === null ? substr($text, $start) : substr($text, $start, $length);
}

/**
 * Multibyte-safe strcut fallback (byte-based, avoids breaking characters).
 *
 * @param string $text
 * @param int    $start
 * @param int    $length
 * @return string
 */
function ll_mb_strcut_safe($text, $start, $length) {
    if (function_exists('mb_strcut')) {
        return mb_strcut($text, $start, $length, 'UTF-8');
    }
    return substr($text, $start, $length);
}
