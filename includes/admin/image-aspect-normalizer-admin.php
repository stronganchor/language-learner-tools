<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_ASPECT_NORMALIZER_PAGE_SLUG')) {
    define('LL_TOOLS_ASPECT_NORMALIZER_PAGE_SLUG', 'll-image-aspect-normalizer');
}

if (!defined('LL_TOOLS_ASPECT_NORMALIZER_NONCE_ACTION')) {
    define('LL_TOOLS_ASPECT_NORMALIZER_NONCE_ACTION', 'll_tools_aspect_normalizer');
}

function ll_tools_get_aspect_normalizer_page_slug(): string {
    return (string) LL_TOOLS_ASPECT_NORMALIZER_PAGE_SLUG;
}

function ll_tools_get_aspect_normalizer_admin_url(array $args = []): string {
    $base = add_query_arg(
        ['page' => ll_tools_get_aspect_normalizer_page_slug()],
        admin_url('tools.php')
    );
    if (!empty($args)) {
        $base = add_query_arg($args, $base);
    }
    return (string) $base;
}

function ll_tools_register_image_aspect_normalizer_admin_page(): void {
    add_submenu_page(
        'tools.php',
        __('LL Tools - Image Aspect Normalizer', 'll-tools-text-domain'),
        __('LL Normalize Images', 'll-tools-text-domain'),
        'view_ll_tools',
        ll_tools_get_aspect_normalizer_page_slug(),
        'll_tools_render_image_aspect_normalizer_admin_page'
    );
}
add_action('admin_menu', 'll_tools_register_image_aspect_normalizer_admin_page');

function ll_tools_enqueue_image_aspect_normalizer_admin_assets($hook): void {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $is_word_category_screen = ($screen instanceof WP_Screen)
        && ($screen->base === 'edit-tags')
        && ($screen->taxonomy === 'word-category');
    $is_tool_page = ($hook === ('tools_page_' . ll_tools_get_aspect_normalizer_page_slug()));

    if (!$is_word_category_screen && !$is_tool_page) {
        return;
    }

    ll_enqueue_asset_by_timestamp('/css/image-aspect-normalizer-admin.css', 'll-tools-aspect-normalizer-admin', [], false);

    if (!$is_tool_page) {
        return;
    }

    ll_enqueue_asset_by_timestamp(
        '/js/image-aspect-normalizer-admin.js',
        'll-tools-aspect-normalizer-admin-js',
        ['jquery'],
        true
    );

    $preselected_category_id = isset($_GET['category_id']) ? (int) wp_unslash($_GET['category_id']) : 0;
    wp_localize_script('ll-tools-aspect-normalizer-admin-js', 'llAspectNormalizerData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce(LL_TOOLS_ASPECT_NORMALIZER_NONCE_ACTION),
        'preselectedCategoryId' => max(0, $preselected_category_id),
        'actions' => [
            'worklist' => 'll_tools_aspect_normalizer_worklist',
            'category' => 'll_tools_aspect_normalizer_category',
            'apply' => 'll_tools_aspect_normalizer_apply',
        ],
        'i18n' => [
            'loading' => __('Loading categories...', 'll-tools-text-domain'),
            'emptyWorklist' => __('No categories currently need image aspect normalization.', 'll-tools-text-domain'),
            'worklistOffending' => __('%1$d of %2$d images need fixes', 'll-tools-text-domain'),
            'worklistRatios' => __('%d aspect ratios detected', 'll-tools-text-domain'),
            'loadingCategory' => __('Loading category details...', 'll-tools-text-domain'),
            'chooseCategory' => __('Select a category from the left to preview crops and white-padding updates.', 'll-tools-text-domain'),
            'categorySummary' => __('%1$d images need fixes out of %2$d tracked images.', 'll-tools-text-domain'),
            'ratioLabel' => __('Canonical ratio', 'll-tools-text-domain'),
            'ratioCustom' => __('Custom ratio', 'll-tools-text-domain'),
            'ratioCustomPlaceholder' => __('e.g. 4:3', 'll-tools-text-domain'),
            'ratioPreview' => __('Update Preview', 'll-tools-text-domain'),
            'applyButton' => __('Apply Crops', 'll-tools-text-domain'),
            'applyConfirm' => __('Apply these crops and update affected posts in this category?', 'll-tools-text-domain'),
            'applyWorking' => __('Applying crops...', 'll-tools-text-domain'),
            'applySuccess' => __('Applied %1$d crop(s), updated %2$d post thumbnail(s).', 'll-tools-text-domain'),
            'applyPadButton' => __('Apply White Padding', 'll-tools-text-domain'),
            'applyPadButtonAria' => __('Apply white padding to image %s using the current canonical ratio.', 'll-tools-text-domain'),
            'applyPadConfirm' => __('Apply white padding to this image and update affected posts that use it?', 'll-tools-text-domain'),
            'applyPadWorking' => __('Applying white padding to image...', 'll-tools-text-domain'),
            'applyPadSuccess' => __('Applied white padding to %1$d image(s), updated %2$d post thumbnail(s).', 'll-tools-text-domain'),
            'applyError' => __('Unable to apply image updates right now.', 'll-tools-text-domain'),
            'invalidRatio' => __('Enter a valid ratio like 4:3.', 'll-tools-text-domain'),
            'noOffenders' => __('All category images already match this ratio.', 'll-tools-text-domain'),
            'offendingTitle' => __('Images needing updates', 'll-tools-text-domain'),
            'wordsLabel' => __('Words', 'll-tools-text-domain'),
            'wordImagesLabel' => __('Word Images', 'll-tools-text-domain'),
            'ratioDetectedFrom' => __('Current ratio: %s', 'll-tools-text-domain'),
            'useRatioButton' => __('Use as canonical', 'll-tools-text-domain'),
            'useRatioButtonAria' => __('Use ratio %s as the canonical ratio and refresh preview.', 'll-tools-text-domain'),
            'cropReadout' => __('Crop: x:%1$d y:%2$d w:%3$d h:%4$d', 'll-tools-text-domain'),
            'statusWarnings' => __('Completed with %d warning(s). Check the error list below.', 'll-tools-text-domain'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'll_tools_enqueue_image_aspect_normalizer_admin_assets');

function ll_tools_render_image_aspect_normalizer_admin_page(): void {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission to access this page.', 'll-tools-text-domain'));
    }
    ?>
    <div class="wrap ll-aspect-normalizer-wrap">
        <h1><?php echo esc_html__('Image Aspect Normalizer', 'll-tools-text-domain'); ?></h1>
        <p class="description">
            <?php echo esc_html__('Find mixed-aspect categories, preview fixed-ratio crops, adjust crop boxes, or apply white-padding updates to affected posts.', 'll-tools-text-domain'); ?>
        </p>

        <div class="ll-aspect-normalizer" data-ll-aspect-normalizer-root>
            <aside class="ll-aspect-normalizer__worklist">
                <h2><?php echo esc_html__('Needs Normalization', 'll-tools-text-domain'); ?></h2>
                <div class="ll-aspect-normalizer__worklist-body" data-ll-aspect-worklist>
                    <?php echo esc_html__('Loading categories...', 'll-tools-text-domain'); ?>
                </div>
            </aside>

            <section class="ll-aspect-normalizer__detail">
                <div class="ll-aspect-normalizer__status" data-ll-aspect-status></div>
                <header class="ll-aspect-normalizer__detail-header">
                    <div>
                        <h2 data-ll-aspect-category-title><?php echo esc_html__('Select a category', 'll-tools-text-domain'); ?></h2>
                        <p class="description" data-ll-aspect-category-summary>
                            <?php echo esc_html__('Select a category from the left to preview crops and white-padding updates.', 'll-tools-text-domain'); ?>
                        </p>
                    </div>
                    <div class="ll-aspect-normalizer__controls" data-ll-aspect-controls hidden>
                        <label>
                            <span><?php echo esc_html__('Canonical ratio', 'll-tools-text-domain'); ?></span>
                            <select data-ll-aspect-ratio-select></select>
                        </label>
                        <label class="ll-aspect-normalizer__custom-ratio" data-ll-aspect-ratio-custom-wrap hidden>
                            <span><?php echo esc_html__('Custom ratio', 'll-tools-text-domain'); ?></span>
                            <input type="text" data-ll-aspect-ratio-custom placeholder="<?php echo esc_attr__('e.g. 4:3', 'll-tools-text-domain'); ?>">
                        </label>
                        <button type="button" class="button" data-ll-aspect-preview>
                            <?php echo esc_html__('Update Preview', 'll-tools-text-domain'); ?>
                        </button>
                        <button type="button" class="button button-primary" data-ll-aspect-apply>
                            <?php echo esc_html__('Apply Crops', 'll-tools-text-domain'); ?>
                        </button>
                    </div>
                </header>

                <div class="ll-aspect-normalizer__errors" data-ll-aspect-errors hidden></div>
                <div class="ll-aspect-normalizer__offenders" data-ll-aspect-offenders></div>
            </section>
        </div>
    </div>
    <?php
}

function ll_tools_aspect_normalizer_post_statuses(): array {
    return ['publish', 'draft', 'pending', 'private', 'future'];
}

function ll_tools_aspect_normalizer_category_label($category_id): string {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return '';
    }
    if (function_exists('ll_tools_get_category_display_name')) {
        return (string) ll_tools_get_category_display_name($category_id);
    }
    $term = get_term($category_id, 'word-category');
    if (!$term || is_wp_error($term)) {
        return '';
    }
    return (string) $term->name;
}

function ll_tools_aspect_normalizer_normalize_ratio_key($ratio_key): string {
    $ratio_key = trim((string) $ratio_key);
    if ($ratio_key === '') {
        return '';
    }
    [$width, $height] = ll_tools_aspect_ratio_dimensions_from_key($ratio_key);
    if ($width <= 0 || $height <= 0) {
        return '';
    }
    return ll_tools_aspect_ratio_key_from_dimensions($width, $height);
}

function ll_tools_aspect_normalizer_default_crop_box($width, $height, $ratio_key): array {
    $image_width = max(0, (int) $width);
    $image_height = max(0, (int) $height);
    if ($image_width <= 0 || $image_height <= 0) {
        return ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
    }

    $ratio = ll_tools_aspect_ratio_value_from_key($ratio_key);
    if ($ratio <= 0) {
        return ['x' => 0, 'y' => 0, 'width' => $image_width, 'height' => $image_height];
    }

    $image_ratio = (float) $image_width / (float) $image_height;
    $crop_width = $image_width;
    $crop_height = $image_height;
    $x = 0;
    $y = 0;

    if ($image_ratio > $ratio) {
        $crop_width = (int) round($image_height * $ratio);
        $crop_width = max(1, min($image_width, $crop_width));
        $x = (int) floor(($image_width - $crop_width) / 2);
    } elseif ($image_ratio < $ratio) {
        $crop_height = (int) round($image_width / $ratio);
        $crop_height = max(1, min($image_height, $crop_height));
        $y = (int) floor(($image_height - $crop_height) / 2);
    }

    return [
        'x' => max(0, $x),
        'y' => max(0, $y),
        'width' => max(1, $crop_width),
        'height' => max(1, $crop_height),
    ];
}

function ll_tools_aspect_normalizer_sanitize_crop_box(array $raw, int $image_width, int $image_height, string $ratio_key): array {
    $image_width = max(0, (int) $image_width);
    $image_height = max(0, (int) $image_height);
    if ($image_width <= 0 || $image_height <= 0) {
        return ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
    }

    $ratio = ll_tools_aspect_ratio_value_from_key($ratio_key);
    if ($ratio <= 0) {
        return ['x' => 0, 'y' => 0, 'width' => $image_width, 'height' => $image_height];
    }

    $default = ll_tools_aspect_normalizer_default_crop_box($image_width, $image_height, $ratio_key);
    $x = isset($raw['x']) ? (float) $raw['x'] : (float) $default['x'];
    $y = isset($raw['y']) ? (float) $raw['y'] : (float) $default['y'];
    $width = isset($raw['width']) ? (float) $raw['width'] : (float) $default['width'];
    $height = isset($raw['height']) ? (float) $raw['height'] : (float) $default['height'];

    if (!is_finite($x) || !is_finite($y) || !is_finite($width) || !is_finite($height) || $width <= 0 || $height <= 0) {
        return $default;
    }

    $width = min((float) $image_width, max(1.0, $width));
    $height = min((float) $image_height, max(1.0, $height));

    $height_from_width = $width / $ratio;
    $width_from_height = $height * $ratio;
    if ($height_from_width <= $image_height) {
        $height = $height_from_width;
    } elseif ($width_from_height <= $image_width) {
        $width = $width_from_height;
    } else {
        return $default;
    }

    $min_width = (float) min($image_width, max(20, (int) round($image_width * 0.06)));
    if ($width < $min_width) {
        $width = $min_width;
        $height = $width / $ratio;
    }
    if ($height > $image_height) {
        $height = (float) $image_height;
        $width = $height * $ratio;
    }
    if ($width > $image_width) {
        $width = (float) $image_width;
        $height = $width / $ratio;
    }

    $x = min(max(0.0, $x), max(0.0, $image_width - $width));
    $y = min(max(0.0, $y), max(0.0, $image_height - $height));

    return [
        'x' => (int) round($x),
        'y' => (int) round($y),
        'width' => max(1, (int) round($width)),
        'height' => max(1, (int) round($height)),
    ];
}

function ll_tools_aspect_normalizer_build_category_payload($category_id, $canonical_ratio_key = ''): array {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return [];
    }

    $category = get_term($category_id, 'word-category');
    if (!$category || is_wp_error($category)) {
        return [];
    }

    $canonical_ratio_key = ll_tools_aspect_normalizer_normalize_ratio_key($canonical_ratio_key);
    $stats_args = [
        'post_statuses' => ll_tools_aspect_normalizer_post_statuses(),
        'include_word_images' => true,
    ];
    if ($canonical_ratio_key !== '') {
        $stats_args['canonical_ratio_key'] = $canonical_ratio_key;
    }
    $stats = ll_tools_get_category_image_aspect_stats($category_id, $stats_args);

    $canonical_key = ll_tools_aspect_normalizer_normalize_ratio_key((string) ($stats['canonical']['key'] ?? ''));
    if ($canonical_key === '' && $canonical_ratio_key !== '') {
        $canonical_key = $canonical_ratio_key;
    }
    $canonical_value = ll_tools_aspect_ratio_value_from_key($canonical_key);
    $tolerance = ll_tools_aspect_ratio_tolerance();

    $offending = [];
    $attachments = (array) ($stats['attachments'] ?? []);
    foreach ((array) ($stats['offending_attachment_ids'] ?? []) as $attachment_id_raw) {
        $attachment_id = (int) $attachment_id_raw;
        if ($attachment_id <= 0 || empty($attachments[$attachment_id]) || !is_array($attachments[$attachment_id])) {
            continue;
        }

        $attachment = $attachments[$attachment_id];
        $width = max(0, (int) ($attachment['width'] ?? 0));
        $height = max(0, (int) ($attachment['height'] ?? 0));
        if ($width <= 0 || $height <= 0) {
            continue;
        }

        $ratio_key = ll_tools_aspect_normalizer_normalize_ratio_key((string) ($attachment['ratio_key'] ?? ''));
        if ($ratio_key === '') {
            $ratio_key = ll_tools_aspect_ratio_key_from_dimensions($width, $height);
        }
        $ratio_label = ll_tools_aspect_ratio_label_from_key($ratio_key);
        if ($ratio_label === '') {
            $ratio_label = (string) ($attachment['ratio_label'] ?? '');
        }
        $ratio_value = ll_tools_aspect_ratio_value_from_key($ratio_key);
        if ($ratio_value <= 0.0) {
            $ratio_value = (float) ($attachment['ratio_value'] ?? 0.0);
        }

        if ($canonical_value > 0.0 && $ratio_value > 0.0) {
            $diff = abs($ratio_value - $canonical_value) / $canonical_value;
            if ($diff <= $tolerance) {
                continue;
            }
        }

        $offending[] = [
            'attachment_id' => $attachment_id,
            'title' => (string) ($attachment['title'] ?? ''),
            'url' => (string) ($attachment['url'] ?? ''),
            'width' => $width,
            'height' => $height,
            'ratio_key' => $ratio_key,
            'ratio_label' => $ratio_label,
            'ratio_value' => $ratio_value,
            'word_count' => max(0, (int) ($attachment['word_count'] ?? 0)),
            'word_image_count' => max(0, (int) ($attachment['word_image_count'] ?? 0)),
            'usage_count' => max(0, (int) ($attachment['usage_count'] ?? 0)),
            'word_ids' => array_values(array_filter(array_map('intval', (array) ($attachment['word_ids'] ?? [])), function ($id) {
                return $id > 0;
            })),
            'word_image_ids' => array_values(array_filter(array_map('intval', (array) ($attachment['word_image_ids'] ?? [])), function ($id) {
                return $id > 0;
            })),
            'suggested_crop' => ll_tools_aspect_normalizer_default_crop_box($width, $height, $canonical_key),
        ];
    }

    usort($offending, static function ($left, $right) {
        $left_usage = (int) ($left['usage_count'] ?? 0);
        $right_usage = (int) ($right['usage_count'] ?? 0);
        if ($left_usage !== $right_usage) {
            return $right_usage <=> $left_usage;
        }
        return ((int) ($left['attachment_id'] ?? 0)) <=> ((int) ($right['attachment_id'] ?? 0));
    });

    $ratios_by_key = [];
    foreach ((array) ($stats['ratios'] ?? []) as $ratio_row) {
        if (!is_array($ratio_row)) {
            continue;
        }
        $key = ll_tools_aspect_normalizer_normalize_ratio_key((string) ($ratio_row['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        if (!isset($ratios_by_key[$key])) {
            $ratios_by_key[$key] = [
                'key' => $key,
                'label' => ll_tools_aspect_ratio_label_from_key($key),
                'value' => ll_tools_aspect_ratio_value_from_key($key),
                'attachment_count' => 0,
                'word_count' => 0,
                'word_image_count' => 0,
            ];
        }
        $ratios_by_key[$key]['attachment_count'] += max(0, (int) ($ratio_row['attachment_count'] ?? 0));
        $ratios_by_key[$key]['word_count'] += max(0, (int) ($ratio_row['word_count'] ?? 0));
        $ratios_by_key[$key]['word_image_count'] += max(0, (int) ($ratio_row['word_image_count'] ?? 0));
    }

    $ratios = array_values($ratios_by_key);
    if (!empty($ratios)) {
        usort($ratios, static function ($left, $right) {
            $left_attach = (int) ($left['attachment_count'] ?? 0);
            $right_attach = (int) ($right['attachment_count'] ?? 0);
            if ($left_attach !== $right_attach) {
                return ($right_attach <=> $left_attach);
            }
            $left_words = (int) ($left['word_count'] ?? 0);
            $right_words = (int) ($right['word_count'] ?? 0);
            if ($left_words !== $right_words) {
                return ($right_words <=> $left_words);
            }
            return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
        });
    }

    return [
        'category' => [
            'id' => $category_id,
            'label' => ll_tools_aspect_normalizer_category_label($category_id),
            'raw_name' => html_entity_decode((string) $category->name, ENT_QUOTES, 'UTF-8'),
            'slug' => (string) $category->slug,
        ],
        'canonical' => [
            'key' => $canonical_key,
            'label' => ll_tools_aspect_ratio_label_from_key($canonical_key),
            'value' => $canonical_value,
            'source' => (string) ($stats['canonical']['source'] ?? 'none'),
        ],
        'ratios' => $ratios,
        'offending_attachments' => $offending,
        'offending_count' => count($offending),
        'total_attachments' => max(0, (int) ($stats['total_attachments'] ?? 0)),
        'total_words' => max(0, (int) ($stats['total_words'] ?? 0)),
        'total_word_images' => max(0, (int) ($stats['total_word_images'] ?? 0)),
    ];
}

function ll_tools_aspect_normalizer_normalize_apply_operation($operation): string {
    $operation = sanitize_key((string) $operation);
    if ($operation === 'pad') {
        return 'pad';
    }
    return 'crop';
}

function ll_tools_aspect_normalizer_build_target_path(
    string $source_path,
    int $category_id,
    string $ratio_key,
    string $mode = 'crop',
    string $output_ext = ''
): string {
    $source_dir = dirname($source_path);
    $source_ext = strtolower(trim($output_ext));
    if ($source_ext === '') {
        $source_ext = strtolower((string) pathinfo($source_path, PATHINFO_EXTENSION));
    }
    if ($source_ext === '') {
        $source_ext = 'jpg';
    }
    $source_name = (string) pathinfo($source_path, PATHINFO_FILENAME);
    $ratio_suffix = sanitize_file_name(str_replace(':', 'x', $ratio_key));
    if ($ratio_suffix === '') {
        $ratio_suffix = 'ratio';
    }
    $mode_suffix = ll_tools_aspect_normalizer_normalize_apply_operation($mode);
    $file_stub = ($mode_suffix === 'pad')
        ? ($source_name . '-ll-aspect-pad-' . $category_id . '-' . $ratio_suffix)
        : ($source_name . '-ll-aspect-' . $category_id . '-' . $ratio_suffix);
    $target_name = wp_unique_filename($source_dir, $file_stub . '.' . $source_ext);

    return trailingslashit($source_dir) . $target_name;
}

function ll_tools_aspect_normalizer_finalize_generated_attachment(int $source_attachment_id, string $saved_path, string $saved_mime = '') {
    $saved_path = trim($saved_path);
    if ($saved_path === '') {
        return new WP_Error('save_failed', __('Could not save the generated image.', 'll-tools-text-domain'));
    }

    if ($saved_mime === '') {
        $filetype = wp_check_filetype($saved_path);
        $saved_mime = (string) ($filetype['type'] ?? 'image/jpeg');
    }

    $source_post = get_post($source_attachment_id);
    $title = ($source_post instanceof WP_Post && $source_post->post_title !== '')
        ? (string) $source_post->post_title
        : (string) pathinfo($saved_path, PATHINFO_FILENAME);

    $new_attachment_id = wp_insert_attachment([
        'post_mime_type' => $saved_mime,
        'post_title' => $title,
        'post_content' => '',
        'post_status' => 'inherit',
    ], $saved_path, 0, true);

    if (is_wp_error($new_attachment_id)) {
        return $new_attachment_id;
    }
    $new_attachment_id = (int) $new_attachment_id;

    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $generated = wp_generate_attachment_metadata($new_attachment_id, $saved_path);
    if (!is_wp_error($generated) && is_array($generated)) {
        wp_update_attachment_metadata($new_attachment_id, $generated);
    }

    $alt = (string) get_post_meta($source_attachment_id, '_wp_attachment_image_alt', true);
    if ($alt !== '') {
        update_post_meta($new_attachment_id, '_wp_attachment_image_alt', $alt);
    }

    if ($source_post instanceof WP_Post) {
        wp_update_post([
            'ID' => $new_attachment_id,
            'post_excerpt' => (string) $source_post->post_excerpt,
            'post_content' => (string) $source_post->post_content,
        ]);
    }

    return $new_attachment_id;
}

function ll_tools_aspect_normalizer_compute_padding_box(int $image_width, int $image_height, string $ratio_key): array {
    $image_width = max(1, (int) $image_width);
    $image_height = max(1, (int) $image_height);

    $ratio = ll_tools_aspect_ratio_value_from_key($ratio_key);
    if ($ratio <= 0.0) {
        return [
            'canvas_width' => $image_width,
            'canvas_height' => $image_height,
            'offset_x' => 0,
            'offset_y' => 0,
        ];
    }

    $image_ratio = (float) $image_width / (float) $image_height;
    $canvas_width = $image_width;
    $canvas_height = $image_height;

    if ($image_ratio > $ratio) {
        $canvas_height = max($image_height, (int) ceil((float) $image_width / $ratio));
    } elseif ($image_ratio < $ratio) {
        $canvas_width = max($image_width, (int) ceil((float) $image_height * $ratio));
    }

    return [
        'canvas_width' => $canvas_width,
        'canvas_height' => $canvas_height,
        'offset_x' => (int) floor(($canvas_width - $image_width) / 2),
        'offset_y' => (int) floor(($canvas_height - $image_height) / 2),
    ];
}

function ll_tools_aspect_normalizer_save_gd_image_to_path($image, string $target_path, string $extension): bool {
    $ext = strtolower(trim($extension));
    if ($ext === '') {
        $ext = 'jpg';
    }
    if ($ext === 'jpeg') {
        $ext = 'jpg';
    }

    if ($ext === 'png') {
        if (!function_exists('imagepng')) {
            return false;
        }
        return (bool) @imagepng($image, $target_path, 6);
    }
    if ($ext === 'gif') {
        if (!function_exists('imagegif')) {
            return false;
        }
        return (bool) @imagegif($image, $target_path);
    }
    if ($ext === 'webp') {
        if (!function_exists('imagewebp')) {
            return false;
        }
        return (bool) @imagewebp($image, $target_path, 90);
    }
    if (!function_exists('imagejpeg')) {
        return false;
    }
    return (bool) @imagejpeg($image, $target_path, 90);
}

function ll_tools_aspect_normalizer_create_cropped_attachment($source_attachment_id, array $crop, $category_id, string $ratio_key) {
    $source_attachment_id = (int) $source_attachment_id;
    $category_id = (int) $category_id;
    if ($source_attachment_id <= 0 || $category_id <= 0) {
        return new WP_Error('invalid_input', __('Invalid attachment or category.', 'll-tools-text-domain'));
    }

    $source_path = get_attached_file($source_attachment_id, true);
    if (!is_string($source_path) || $source_path === '' || !file_exists($source_path)) {
        return new WP_Error('missing_source_file', __('Source image file is missing.', 'll-tools-text-domain'));
    }

    $editor = wp_get_image_editor($source_path);
    if (is_wp_error($editor)) {
        return $editor;
    }

    $crop_x = max(0, (int) ($crop['x'] ?? 0));
    $crop_y = max(0, (int) ($crop['y'] ?? 0));
    $crop_width = max(1, (int) ($crop['width'] ?? 0));
    $crop_height = max(1, (int) ($crop['height'] ?? 0));

    $crop_result = $editor->crop($crop_x, $crop_y, $crop_width, $crop_height);
    if (is_wp_error($crop_result)) {
        return $crop_result;
    }

    $target_path = ll_tools_aspect_normalizer_build_target_path($source_path, $category_id, $ratio_key, 'crop');
    $saved = $editor->save($target_path);
    if (is_wp_error($saved)) {
        return $saved;
    }

    $saved_path = isset($saved['path']) ? (string) $saved['path'] : '';
    if ($saved_path === '') {
        return new WP_Error('save_failed', __('Could not save cropped image.', 'll-tools-text-domain'));
    }

    $saved_mime = isset($saved['mime-type']) ? (string) $saved['mime-type'] : '';
    return ll_tools_aspect_normalizer_finalize_generated_attachment($source_attachment_id, $saved_path, $saved_mime);
}

function ll_tools_aspect_normalizer_create_padded_attachment($source_attachment_id, $category_id, string $ratio_key) {
    $source_attachment_id = (int) $source_attachment_id;
    $category_id = (int) $category_id;
    if ($source_attachment_id <= 0 || $category_id <= 0) {
        return new WP_Error('invalid_input', __('Invalid attachment or category.', 'll-tools-text-domain'));
    }

    $source_path = get_attached_file($source_attachment_id, true);
    if (!is_string($source_path) || $source_path === '' || !file_exists($source_path)) {
        return new WP_Error('missing_source_file', __('Source image file is missing.', 'll-tools-text-domain'));
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return new WP_Error('image_lib_missing', __('GD image library is not available for white-padding.', 'll-tools-text-domain'));
    }

    $raw = @file_get_contents($source_path);
    if ($raw === false || $raw === '') {
        return new WP_Error('source_read_failed', __('Could not read source image data.', 'll-tools-text-domain'));
    }

    $source_image = @imagecreatefromstring($raw);
    if (!$source_image) {
        return new WP_Error('source_decode_failed', __('Could not decode source image data.', 'll-tools-text-domain'));
    }

    $source_width = max(0, (int) imagesx($source_image));
    $source_height = max(0, (int) imagesy($source_image));
    if ($source_width <= 0 || $source_height <= 0) {
        imagedestroy($source_image);
        return new WP_Error('source_size_failed', __('Source image dimensions are invalid.', 'll-tools-text-domain'));
    }

    $box = ll_tools_aspect_normalizer_compute_padding_box($source_width, $source_height, $ratio_key);
    $canvas_width = max($source_width, (int) ($box['canvas_width'] ?? 0));
    $canvas_height = max($source_height, (int) ($box['canvas_height'] ?? 0));
    $offset_x = max(0, (int) ($box['offset_x'] ?? 0));
    $offset_y = max(0, (int) ($box['offset_y'] ?? 0));

    $target_image = imagecreatetruecolor($canvas_width, $canvas_height);
    if (!$target_image) {
        imagedestroy($source_image);
        return new WP_Error('canvas_create_failed', __('Could not create a padded image canvas.', 'll-tools-text-domain'));
    }

    $white = imagecolorallocate($target_image, 255, 255, 255);
    imagefill($target_image, 0, 0, $white);
    $copied = imagecopy($target_image, $source_image, $offset_x, $offset_y, 0, 0, $source_width, $source_height);
    if (!$copied) {
        imagedestroy($source_image);
        imagedestroy($target_image);
        return new WP_Error('canvas_copy_failed', __('Could not place the source image on the padded canvas.', 'll-tools-text-domain'));
    }

    $extension = strtolower((string) pathinfo($source_path, PATHINFO_EXTENSION));
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }
    if (!in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
        $extension = 'jpg';
    }

    $target_path = ll_tools_aspect_normalizer_build_target_path($source_path, $category_id, $ratio_key, 'pad', $extension);
    $saved = ll_tools_aspect_normalizer_save_gd_image_to_path($target_image, $target_path, $extension);
    imagedestroy($source_image);
    imagedestroy($target_image);

    if (!$saved || !file_exists($target_path)) {
        return new WP_Error('save_failed', __('Could not save white-padded image.', 'll-tools-text-domain'));
    }

    $filetype = wp_check_filetype($target_path);
    $saved_mime = (string) ($filetype['type'] ?? '');
    if ($saved_mime === '') {
        $saved_mime = 'image/jpeg';
    }

    return ll_tools_aspect_normalizer_finalize_generated_attachment($source_attachment_id, $target_path, $saved_mime);
}

function ll_tools_aspect_normalizer_verify_ajax_request(): void {
    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer(LL_TOOLS_ASPECT_NORMALIZER_NONCE_ACTION, 'nonce');
}

function ll_tools_aspect_normalizer_worklist_ajax(): void {
    ll_tools_aspect_normalizer_verify_ajax_request();

    $rows = [];
    $category_ids = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);
    if (is_wp_error($category_ids)) {
        wp_send_json_success(['categories' => []]);
    }

    foreach ((array) $category_ids as $category_id_raw) {
        $category_id = (int) $category_id_raw;
        if ($category_id <= 0) {
            continue;
        }
        if (!ll_tools_category_needs_aspect_normalization($category_id, [
            'post_statuses' => ll_tools_aspect_normalizer_post_statuses(),
            'include_word_images' => true,
        ])) {
            continue;
        }
        $term = get_term($category_id, 'word-category');
        if (!$term || is_wp_error($term)) {
            continue;
        }

        $stats = ll_tools_get_category_image_aspect_stats($category_id, [
            'post_statuses' => ll_tools_aspect_normalizer_post_statuses(),
            'include_word_images' => true,
        ]);
        $rows[] = [
            'id' => $category_id,
            'label' => ll_tools_aspect_normalizer_category_label($category_id),
            'raw_name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
            'offending_count' => max(0, (int) ($stats['offending_count'] ?? 0)),
            'total_attachments' => max(0, (int) ($stats['total_attachments'] ?? 0)),
            'ratio_count' => count((array) ($stats['ratios'] ?? [])),
            'canonical_ratio_key' => (string) ($stats['canonical']['key'] ?? ''),
            'canonical_ratio_label' => (string) ($stats['canonical']['label'] ?? ''),
        ];
    }

    usort($rows, static function ($left, $right) {
        $left_offending = (int) ($left['offending_count'] ?? 0);
        $right_offending = (int) ($right['offending_count'] ?? 0);
        if ($left_offending !== $right_offending) {
            return $right_offending <=> $left_offending;
        }
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        if (function_exists('ll_tools_locale_compare_strings')) {
            return ll_tools_locale_compare_strings($left_label, $right_label);
        }
        return strnatcasecmp($left_label, $right_label);
    });

    wp_send_json_success(['categories' => $rows]);
}
add_action('wp_ajax_ll_tools_aspect_normalizer_worklist', 'll_tools_aspect_normalizer_worklist_ajax');

function ll_tools_aspect_normalizer_category_ajax(): void {
    ll_tools_aspect_normalizer_verify_ajax_request();

    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $canonical_ratio_key = isset($_POST['canonical_ratio_key']) ? wp_unslash((string) $_POST['canonical_ratio_key']) : '';
    $payload = ll_tools_aspect_normalizer_build_category_payload($category_id, $canonical_ratio_key);
    if (empty($payload)) {
        wp_send_json_error(['message' => __('Category not found.', 'll-tools-text-domain')], 404);
    }

    wp_send_json_success($payload);
}
add_action('wp_ajax_ll_tools_aspect_normalizer_category', 'll_tools_aspect_normalizer_category_ajax');

function ll_tools_aspect_normalizer_parse_crop_map($raw): array {
    if (is_string($raw)) {
        $decoded = json_decode(wp_unslash($raw), true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }

    $map = [];
    foreach ($raw as $attachment_id_raw => $crop) {
        $attachment_id = (int) $attachment_id_raw;
        if ($attachment_id <= 0 || !is_array($crop)) {
            continue;
        }
        $map[$attachment_id] = $crop;
    }
    return $map;
}

function ll_tools_aspect_normalizer_parse_attachment_id_list($raw): array {
    if (is_string($raw)) {
        $decoded = json_decode(wp_unslash($raw), true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw)) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), static function ($id) {
        return $id > 0;
    })));

    return $ids;
}

function ll_tools_aspect_normalizer_apply_ajax(): void {
    ll_tools_aspect_normalizer_verify_ajax_request();

    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $canonical_ratio_key = isset($_POST['canonical_ratio_key']) ? wp_unslash((string) $_POST['canonical_ratio_key']) : '';
    $canonical_ratio_key = ll_tools_aspect_normalizer_normalize_ratio_key($canonical_ratio_key);
    $apply_operation = ll_tools_aspect_normalizer_normalize_apply_operation(
        isset($_POST['operation']) ? wp_unslash((string) $_POST['operation']) : 'crop'
    );
    $crop_map = ll_tools_aspect_normalizer_parse_crop_map($_POST['crops'] ?? []);
    $target_attachment_ids = ll_tools_aspect_normalizer_parse_attachment_id_list($_POST['target_attachment_ids'] ?? []);
    $target_attachment_lookup = [];
    foreach ($target_attachment_ids as $target_attachment_id) {
        $target_attachment_lookup[(int) $target_attachment_id] = true;
    }

    $payload = ll_tools_aspect_normalizer_build_category_payload($category_id, $canonical_ratio_key);
    if (empty($payload)) {
        wp_send_json_error(['message' => __('Category not found.', 'll-tools-text-domain')], 404);
    }

    $category_id = (int) ($payload['category']['id'] ?? 0);
    $canonical_ratio_key = ll_tools_aspect_normalizer_normalize_ratio_key((string) ($payload['canonical']['key'] ?? ''));
    if ($category_id <= 0 || $canonical_ratio_key === '') {
        wp_send_json_error(['message' => __('Unable to resolve a canonical ratio.', 'll-tools-text-domain')], 400);
    }

    $offending_rows = (array) ($payload['offending_attachments'] ?? []);
    if (empty($offending_rows)) {
        update_term_meta($category_id, LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY, $canonical_ratio_key);
        ll_tools_clear_category_aspect_cache($category_id);
        wp_send_json_success([
            'processed_count' => 0,
            'updated_post_count' => 0,
            'warning_messages' => [],
            'canonical_ratio_key' => $canonical_ratio_key,
            'operation' => $apply_operation,
        ]);
    }

    $processed_count = 0;
    $updated_post_count = 0;
    $updated_word_count = 0;
    $updated_word_image_count = 0;
    $warning_messages = [];

    foreach ($offending_rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $attachment_id = (int) ($row['attachment_id'] ?? 0);
        $image_width = (int) ($row['width'] ?? 0);
        $image_height = (int) ($row['height'] ?? 0);
        if ($attachment_id <= 0 || $image_width <= 0 || $image_height <= 0) {
            continue;
        }
        if (!empty($target_attachment_lookup) && empty($target_attachment_lookup[$attachment_id])) {
            continue;
        }

        $new_attachment_id = 0;
        if ($apply_operation === 'pad') {
            $new_attachment_id = ll_tools_aspect_normalizer_create_padded_attachment(
                $attachment_id,
                $category_id,
                $canonical_ratio_key
            );
        } else {
            $raw_crop = isset($crop_map[$attachment_id]) && is_array($crop_map[$attachment_id])
                ? $crop_map[$attachment_id]
                : (array) ($row['suggested_crop'] ?? []);
            $crop = ll_tools_aspect_normalizer_sanitize_crop_box($raw_crop, $image_width, $image_height, $canonical_ratio_key);
            if ((int) ($crop['width'] ?? 0) <= 0 || (int) ($crop['height'] ?? 0) <= 0) {
                $warning_messages[] = sprintf(
                    /* translators: %d attachment ID */
                    __('Skipped attachment %d because crop data was invalid.', 'll-tools-text-domain'),
                    $attachment_id
                );
                continue;
            }
            $new_attachment_id = ll_tools_aspect_normalizer_create_cropped_attachment(
                $attachment_id,
                $crop,
                $category_id,
                $canonical_ratio_key
            );
        }

        if (is_wp_error($new_attachment_id)) {
            $warning_messages[] = sprintf(
                /* translators: 1: attachment ID, 2: error message */
                __('Attachment %1$d failed: %2$s', 'll-tools-text-domain'),
                $attachment_id,
                $new_attachment_id->get_error_message()
            );
            continue;
        }

        $new_attachment_id = (int) $new_attachment_id;
        $processed_count++;

        $word_ids = array_values(array_filter(array_map('intval', (array) ($row['word_ids'] ?? [])), function ($id) {
            return $id > 0;
        }));
        $word_image_ids = array_values(array_filter(array_map('intval', (array) ($row['word_image_ids'] ?? [])), function ($id) {
            return $id > 0;
        }));

        foreach ($word_ids as $word_id) {
            $current_thumbnail_id = (int) get_post_thumbnail_id($word_id);
            if ($current_thumbnail_id === $new_attachment_id) {
                continue;
            }
            if (set_post_thumbnail($word_id, $new_attachment_id)) {
                $updated_post_count++;
                $updated_word_count++;
            }
        }

        foreach ($word_image_ids as $word_image_id) {
            $current_thumbnail_id = (int) get_post_thumbnail_id($word_image_id);
            if ($current_thumbnail_id === $new_attachment_id) {
                continue;
            }
            if (set_post_thumbnail($word_image_id, $new_attachment_id)) {
                $updated_post_count++;
                $updated_word_image_count++;
            }
        }
    }

    update_term_meta($category_id, LL_TOOLS_CATEGORY_CANONICAL_ASPECT_META_KEY, $canonical_ratio_key);
    ll_tools_clear_category_aspect_cache($category_id);
    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id]);
    }

    wp_send_json_success([
        'processed_count' => $processed_count,
        'updated_post_count' => $updated_post_count,
        'updated_word_count' => $updated_word_count,
        'updated_word_image_count' => $updated_word_image_count,
        'warning_messages' => array_values($warning_messages),
        'canonical_ratio_key' => $canonical_ratio_key,
        'operation' => $apply_operation,
    ]);
}
add_action('wp_ajax_ll_tools_aspect_normalizer_apply', 'll_tools_aspect_normalizer_apply_ajax');

function ll_tools_get_aspect_normalization_needs_lookup(): array {
    static $lookup = null;
    if (is_array($lookup)) {
        return $lookup;
    }

    $lookup = [];
    $category_ids = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);
    if (is_wp_error($category_ids)) {
        return $lookup;
    }

    foreach ((array) $category_ids as $category_id_raw) {
        $category_id = (int) $category_id_raw;
        if ($category_id <= 0) {
            continue;
        }
        if (ll_tools_category_needs_aspect_normalization($category_id, [
            'post_statuses' => ll_tools_aspect_normalizer_post_statuses(),
            'include_word_images' => true,
        ])) {
            $lookup[$category_id] = true;
        }
    }

    return $lookup;
}

function ll_tools_add_word_category_aspect_row_action($actions, $term) {
    if (!is_admin() || !current_user_can('view_ll_tools')) {
        return $actions;
    }
    if (!($term instanceof WP_Term) || $term->taxonomy !== 'word-category') {
        return $actions;
    }

    $needs_lookup = ll_tools_get_aspect_normalization_needs_lookup();
    if (empty($needs_lookup[(int) $term->term_id])) {
        return $actions;
    }

    $url = ll_tools_get_aspect_normalizer_admin_url([
        'category_id' => (int) $term->term_id,
    ]);
    $label = __('Normalize Images', 'll-tools-text-domain');
    $attention = __('Needs Fix', 'll-tools-text-domain');
    $link = '<a class="ll-tools-aspect-normalize-link" href="' . esc_url($url) . '">'
        . '<span class="ll-tools-aspect-normalize-link__icon" aria-hidden="true">!</span>'
        . '<span class="ll-tools-aspect-normalize-link__label">' . esc_html($label) . '</span>'
        . '<span class="ll-tools-aspect-normalize-link__attention">' . esc_html($attention) . '</span>'
        . '</a>';

    if (!is_array($actions)) {
        return ['ll_tools_aspect_normalize' => $link];
    }

    $updated = [];
    $inserted = false;
    foreach ($actions as $key => $value) {
        $updated[$key] = $value;
        if ($key === 'edit') {
            $updated['ll_tools_aspect_normalize'] = $link;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $updated['ll_tools_aspect_normalize'] = $link;
    }

    return $updated;
}
add_filter('word-category_row_actions', 'll_tools_add_word_category_aspect_row_action', 15, 2);
