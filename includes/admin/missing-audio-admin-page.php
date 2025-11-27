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
    $last_table_pattern = '';
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
        }

        switch ($action) {
            case 'clear_cache':
                update_option('ll_missing_audio_instances', array());
                $missing_audio_instances = array();
                echo '<div class="notice notice-success"><p>Missing audio instances cache has been cleared.</p></div>';
                break;

            case 'scan_missing_audio':
                $scan_result = ll_run_missing_audio_scan();
                $missing_audio_instances = $scan_result['missing_audio_instances'];

                $missing_count = intval($scan_result['missing_count']);
                $posts_scanned = intval($scan_result['posts_scanned']);
                printf(
                    '<div class="notice notice-success"><p>Scan complete. Found %1$d missing audio entr%2$s across %3$d post%4$s.</p></div>',
                    $missing_count,
                    $missing_count === 1 ? 'y' : 'ies',
                    $posts_scanned,
                    $posts_scanned === 1 ? '' : 's'
                );
                break;

            case 'save_regex_pattern':
                $label = isset($_POST['regex_label']) ? sanitize_text_field(wp_unslash($_POST['regex_label'])) : '';
                $pattern = isset($_POST['regex_pattern']) ? trim(wp_unslash($_POST['regex_pattern'])) : '';
                $pattern_id = isset($_POST['pattern_id']) ? sanitize_text_field(wp_unslash($_POST['pattern_id'])) : '';

                if ($label === '' || $pattern === '') {
                    echo '<div class="notice notice-error"><p>Please provide both a label and a regex pattern.</p></div>';
                } else {
                    if (!ll_missing_audio_is_valid_regex($pattern)) {
                        echo '<div class="notice notice-error"><p>The regex pattern is invalid. Please fix it and try again.</p></div>';
                    } else {
                        $regex_patterns = ll_missing_audio_upsert_regex_pattern($label, $pattern, $pattern_id);
                        echo '<div class="notice notice-success"><p>Regex pattern saved.</p></div>';
                    }
                }
                break;

            case 'delete_regex_pattern':
                $pattern_id = isset($_POST['pattern_id']) ? sanitize_text_field(wp_unslash($_POST['pattern_id'])) : '';
                if ($pattern_id !== '') {
                    $regex_patterns = ll_missing_audio_delete_regex_pattern($pattern_id);
                    echo '<div class="notice notice-success"><p>Regex pattern removed.</p></div>';
                }
                break;

            case 'preview_regex_matches':
            case 'apply_regex_insertions':
                $resolved = ll_missing_audio_resolve_pattern_from_request($regex_patterns, $_POST);
                $pattern_to_use = $resolved['pattern'];
                $last_regex_pattern = $pattern_to_use;
                $last_saved_pattern_id = $resolved['pattern_id'];

                if ($pattern_to_use === '') {
                    echo '<div class="notice notice-error"><p>Please choose a saved regex or enter one to run.</p></div>';
                    break;
                }

                if (!ll_missing_audio_is_valid_regex($pattern_to_use)) {
                    echo '<div class="notice notice-error"><p>The regex pattern is invalid. Please fix it and try again.</p></div>';
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
                            '<div class="notice notice-info"><p>Preview only. Found %1$d match%2$s across %3$d post%4$s.</p></div>',
                            $match_total,
                            $match_total === 1 ? '' : 'es',
                            intval($regex_preview['posts_with_matches']),
                            intval($regex_preview['posts_with_matches']) === 1 ? '' : 's'
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
                        '<div class="notice notice-success"><p>Inserted %1$d shortcode%2$s across %3$d post%4$s.</p></div>',
                        $inserted,
                        $inserted === 1 ? '' : 's',
                        $updated_posts,
                        $updated_posts === 1 ? '' : 's'
                    );
                }
                break;

            case 'preview_table_matches':
            case 'apply_table_insertions':
                $table_pattern = isset($_POST['table_header_pattern']) ? trim(wp_unslash($_POST['table_header_pattern'])) : '';
                $last_table_pattern = $table_pattern;

                if ($table_pattern === '') {
                    echo '<div class="notice notice-error"><p>Please enter a regex that matches the target column header.</p></div>';
                    break;
                }

                if (!ll_missing_audio_is_valid_regex($table_pattern)) {
                    echo '<div class="notice notice-error"><p>The header regex pattern is invalid. Please fix it and try again.</p></div>';
                    break;
                }

                if ($action === 'preview_table_matches') {
                    $table_preview = ll_find_table_column_word_audio_matches($table_pattern);
                    if (!empty($table_preview['errors'])) {
                        foreach ($table_preview['errors'] as $err) {
                            echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                        }
                    } else {
                        $scroll_target = '#ll-table-preview-results';
                        $match_total = isset($table_preview['cell_count']) ? intval($table_preview['cell_count']) : 0;
                        printf(
                            '<div class="notice notice-info"><p>Preview only. Found %1$d cell%2$s across %3$d post%4$s.</p></div>',
                            $match_total,
                            $match_total === 1 ? '' : 's',
                            intval($table_preview['posts_with_matches']),
                            intval($table_preview['posts_with_matches']) === 1 ? '' : 's'
                        );
                    }
                } else {
                    $cell_exclusions = ll_parse_table_exclusions($_POST);
                    $table_apply_summary = ll_apply_table_column_word_audio_insertions($table_pattern, $cell_exclusions);
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
                        '<div class="notice notice-success"><p>Inserted %1$d shortcode%2$s across %3$d post%4$s.</p></div>',
                        $inserted,
                        $inserted === 1 ? '' : 's',
                        $updated_posts,
                        $updated_posts === 1 ? '' : 's'
                    );
                }
                break;
        }
    }
    ?>
    <div class="wrap">
        <h1>Language Learner Tools - Missing Audio</h1>
        <form method="post">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <p>
                <input type="submit" name="scan_missing_audio" class="button button-primary" value="Scan All Posts for Missing Audio">
                <input type="submit" name="clear_cache" class="button button-secondary" value="Clear Cache">
            </p>
        </form>
        <hr>
        <h2>Regex → Insert [word_audio]</h2>
        <p>Find text via regex and wrap it with the <code>[word_audio]</code> shortcode. Patterns are stored for re-use and tagged with a comment label.</p>

        <h3>Saved Regex Patterns</h3>
        <form method="post" style="margin-bottom:16px;">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <input type="hidden" name="pattern_id" value="">
            <p>
                <label for="regex_label"><strong>Label / comment</strong></label><br>
                <input type="text" id="regex_label" name="regex_label" class="regular-text" placeholder="e.g., Words in bold tags" />
            </p>
            <p>
                <label for="regex_pattern"><strong>Regex (PHP delimiters required)</strong></label><br>
                <input type="text" id="regex_pattern" name="regex_pattern" class="large-text code" placeholder="#<strong>([^<]+)</strong>#i" />
            </p>
            <p>
                <input type="submit" name="save_regex_pattern" class="button button-secondary" value="Save Pattern">
            </p>
        </form>

        <?php if (!empty($regex_patterns)) : ?>
            <table class="wp-list-table widefat striped" style="margin-bottom:20px;">
                <thead>
                    <tr>
                        <th style="width:20%;">Label</th>
                        <th>Regex</th>
                        <th style="width:20%;">Actions</th>
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
                                <input type="submit" name="save_regex_pattern" class="button button-small" value="Save">
                            </form>
                            <form method="post" style="display:inline;margin-left:6px;">
                                <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                                <input type="hidden" name="pattern_id" value="<?php echo esc_attr($pattern_row['id']); ?>">
                                <input type="submit" name="delete_regex_pattern" class="button button-small button-link-delete" value="Delete">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No saved patterns yet.</p>
        <?php endif; ?>

        <h3>Find Matches & Insert Shortcodes</h3>
        <form method="post">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <p>
                <label for="saved_pattern_id"><strong>Saved pattern</strong></label><br>
                <select id="saved_pattern_id" name="saved_pattern_id">
                    <option value="">— Select a saved pattern —</option>
                    <?php foreach ($regex_patterns as $pattern_row) : ?>
                        <option value="<?php echo esc_attr($pattern_row['id']); ?>" <?php selected($last_saved_pattern_id, $pattern_row['id']); ?>>
                            <?php echo esc_html($pattern_row['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="run_regex_pattern"><strong>Or paste regex to run</strong></label><br>
                <input type="text" id="run_regex_pattern" name="regex_pattern" class="large-text code" value="<?php echo esc_attr($last_regex_pattern); ?>" placeholder="#\\b[A-Za-z]+\\b#u">
            </p>
            <p class="description">Uses PHP regex with delimiters. Matches are wrapped as <code>[word_audio]match[/word_audio]</code>.</p>
            <p>
                <input type="submit" name="preview_regex_matches" class="button button-primary" value="Preview Matches">
                <input type="submit" name="apply_regex_insertions" class="button button-secondary" value="Insert Shortcodes Now">
            </p>
        </form>

        <?php if (!empty($regex_preview) && empty($apply_summary)) : ?>
            <div id="ll-regex-preview-results"></div>
            <?php if (!empty($regex_preview['matches'])) : ?>
                <h3>Preview Results</h3>
                <p>
                    Found <?php echo intval($regex_preview['match_total']); ?> match<?php echo intval($regex_preview['match_total']) === 1 ? '' : 'es'; ?>
                    across <?php echo intval($regex_preview['posts_with_matches']); ?> post<?php echo intval($regex_preview['posts_with_matches']) === 1 ? '' : 's'; ?>.
                    No content has been changed yet. Uncheck items you want to exclude before applying.
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
                                            Exclude
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
                <p><em>No matches found for that regex.</em></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($apply_summary)) : ?>
            <div id="ll-regex-summary"></div>
            <h3>Insertion Summary</h3>
            <p>
                Updated <?php echo intval($apply_summary['posts_updated']); ?> post<?php echo intval($apply_summary['posts_updated']) === 1 ? '' : 's'; ?>,
                inserted <?php echo intval($apply_summary['shortcodes_inserted']); ?> shortcode<?php echo intval($apply_summary['shortcodes_inserted']) === 1 ? '' : 's'; ?>.
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
        <h2>Table Column → Insert [word_audio]</h2>
        <p>Find HTML tables, match a header by regex, and wrap every cell in that column with <code>[word_audio]</code>. Existing shortcodes are skipped.</p>
        <form method="post">
            <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
            <p>
                <label for="table_header_pattern"><strong>Header regex (PHP delimiters required)</strong></label><br>
                <input type="text" id="table_header_pattern" name="table_header_pattern" class="large-text code" value="<?php echo esc_attr($last_table_pattern); ?>" placeholder="#^(Word|Translation)$#i">
            </p>
            <p class="description">Matches against the header cell text (first row or &lt;thead&gt;). Wrapped cell content keeps any inner HTML.</p>
            <p>
                <input type="submit" name="preview_table_matches" class="button button-primary" value="Preview Table Matches">
                <input type="submit" name="apply_table_insertions" class="button button-secondary" value="Insert Shortcodes in Column Now">
            </p>
        </form>

        <?php if (!empty($table_preview) && empty($table_apply_summary)) : ?>
            <div id="ll-table-preview-results"></div>
            <?php if (!empty($table_preview['matches'])) : ?>
                <h3>Table Preview Results</h3>
                <p>
                    Found <?php echo intval($table_preview['cell_count']); ?> cell<?php echo intval($table_preview['cell_count']) === 1 ? '' : 's'; ?>
                    across <?php echo intval($table_preview['posts_with_matches']); ?> post<?php echo intval($table_preview['posts_with_matches']) === 1 ? '' : 's'; ?>.
                    No content has been changed yet. Uncheck items you want to exclude before applying.
                </p>
                <form method="post">
                    <?php wp_nonce_field('ll_missing_audio_actions', 'll_missing_audio_nonce'); ?>
                    <input type="hidden" name="table_header_pattern" value="<?php echo esc_attr($last_table_pattern); ?>">
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
                                            Exclude
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
                <p><em>No matching tables or cells found for that header regex.</em></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($table_apply_summary)) : ?>
            <div id="ll-table-summary"></div>
            <h3>Table Insertion Summary</h3>
            <p>
                Updated <?php echo intval($table_apply_summary['posts_updated']); ?> post<?php echo intval($table_apply_summary['posts_updated']) === 1 ? '' : 's'; ?>,
                inserted <?php echo intval($table_apply_summary['shortcodes_inserted']); ?> shortcode<?php echo intval($table_apply_summary['shortcodes_inserted']) === 1 ? '' : 's'; ?>.
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
        <h2 id="ll-missing-audio-list">Current Missing Audio</h2>
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
 * Build a short, HTML-safe context string for a cell.
 *
 * @param string $cell_html
 * @return string
 */
function ll_find_table_column_word_audio_matches($header_pattern) {
    $post_types = ll_missing_audio_get_scan_post_types();
    $results = array(
        'matches' => array(),
        'cell_count' => 0,
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
                    set_error_handler(function () { /* suppress */ });
                    $is_match = @preg_match($header_pattern, $header_text);
                    restore_error_handler();
                    if ($is_match) {
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

                        $post_cells[] = array(
                            'id' => $table_index . ':' . $row_idx . ':' . $target_idx,
                            'context_html' => '<strong>' . esc_html($text_value) . '</strong>',
                        );
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
 * Apply insertion inside table column cells matched by header regex.
 *
 * @param string $header_pattern
 * @return array
 */
function ll_apply_table_column_word_audio_insertions($header_pattern, $exclusions = array()) {
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
                    set_error_handler(function () { /* suppress */ });
                    $is_match = @preg_match($header_pattern, $header_text);
                    restore_error_handler();
                    if ($is_match) {
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

                        ll_wrap_table_cell_with_shortcode($cells[$target_idx]);
                        $post_log_cells[] = array(
                            'context_html' => '<strong>' . esc_html($text_value) . '</strong>',
                            'id' => $cell_id,
                        );
                        $inserted_here++;

                        $normalized = ll_normalize_case($text_value);
                        ll_cache_missing_audio_instance($normalized, $post_id);
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
