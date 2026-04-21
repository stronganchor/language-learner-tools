<?php
/**
 * Provides an [audio_credit_grid] shortcode to display public attribution
 * details for sourced Word Audio recordings.
 */

if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP')) {
    define('LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP', 'll_tools_audio_credit_grid');
}

function ll_tools_get_audio_credit_grid_meta_keys(): array {
    if (function_exists('ll_tools_get_audio_attribution_fields')) {
        return array_keys(ll_tools_get_audio_attribution_fields());
    }

    return ['speaker_name'];
}

function ll_tools_get_audio_credit_grid_relevant_meta_keys(): array {
    $keys = array_merge(['audio_file_path'], ll_tools_get_audio_credit_grid_meta_keys());
    $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static function (string $meta_key): bool {
        return $meta_key !== '';
    })));

    sort($keys, SORT_STRING);
    return $keys;
}

function ll_tools_get_audio_credit_grid_cache_version(): int {
    $version = (int) get_option('ll_tools_audio_credit_grid_cache_version', 1);
    return $version > 0 ? $version : 1;
}

function ll_tools_get_audio_credit_grid_cache_key(int $version = 0): string {
    if ($version <= 0) {
        $version = ll_tools_get_audio_credit_grid_cache_version();
    }

    return 'recording_ids_v' . $version;
}

function ll_tools_bump_audio_credit_grid_cache_version(): int {
    $current_version = ll_tools_get_audio_credit_grid_cache_version();
    $next_version = $current_version + 1;

    update_option('ll_tools_audio_credit_grid_cache_version', $next_version, false);
    wp_cache_delete(ll_tools_get_audio_credit_grid_cache_key($current_version), LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP);
    delete_transient(ll_tools_get_audio_credit_grid_cache_key($current_version));

    return $next_version;
}

function ll_tools_audio_credit_grid_is_relevant_meta_key(string $meta_key): bool {
    return in_array($meta_key, ll_tools_get_audio_credit_grid_relevant_meta_keys(), true);
}

function ll_tools_audio_credit_grid_has_public_credit(int $audio_post_id): bool {
    $audio_post_id = (int) $audio_post_id;
    if ($audio_post_id <= 0) {
        return false;
    }

    $audio_path = trim((string) get_post_meta($audio_post_id, 'audio_file_path', true));
    if ($audio_path === '') {
        return false;
    }

    foreach (ll_tools_get_audio_credit_grid_meta_keys() as $meta_key) {
        if (trim((string) get_post_meta($audio_post_id, $meta_key, true)) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Build the ordered list of publishable audio posts that have both a real file
 * and at least one public attribution field. This intentionally avoids a wide
 * postmeta join so public credit pages cannot trigger expensive SQL_CALC_FOUND_ROWS
 * meta queries under load.
 *
 * @return int[]
 */
function ll_tools_get_audio_credit_grid_recording_ids(): array {
    $cache_key = ll_tools_get_audio_credit_grid_cache_key();

    $cached = wp_cache_get($cache_key, LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP);
    if (is_array($cached)) {
        return array_values(array_map('intval', $cached));
    }

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $cached_ids = array_values(array_map('intval', $cached));
        wp_cache_set($cache_key, $cached_ids, LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP, HOUR_IN_SECONDS);
        return $cached_ids;
    }

    $candidate_ids = array_values(array_filter(array_map('intval', (array) get_posts([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'cache_results' => true,
    ])), static function (int $post_id): bool {
        return $post_id > 0;
    }));

    if (empty($candidate_ids)) {
        wp_cache_set($cache_key, [], LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP, HOUR_IN_SECONDS);
        set_transient($cache_key, [], HOUR_IN_SECONDS);
        return [];
    }

    update_meta_cache('post', $candidate_ids);

    $matching_ids = [];
    foreach ($candidate_ids as $audio_post_id) {
        if (ll_tools_audio_credit_grid_has_public_credit($audio_post_id)) {
            $matching_ids[] = $audio_post_id;
        }
    }

    wp_cache_set($cache_key, $matching_ids, LL_TOOLS_AUDIO_CREDIT_GRID_CACHE_GROUP, HOUR_IN_SECONDS);
    set_transient($cache_key, $matching_ids, HOUR_IN_SECONDS);

    return $matching_ids;
}

function ll_tools_invalidate_audio_credit_grid_cache_for_post(int $post_id): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0 || get_post_type($post_id) !== 'word_audio') {
        return;
    }

    ll_tools_bump_audio_credit_grid_cache_version();
}

function ll_tools_invalidate_audio_credit_grid_cache_on_save(int $post_id, $post = null, bool $update = false): void {
    unset($update);

    if (wp_is_post_revision($post_id)) {
        return;
    }

    if ($post instanceof WP_Post && $post->post_type !== 'word_audio') {
        return;
    }

    ll_tools_invalidate_audio_credit_grid_cache_for_post($post_id);
}
add_action('save_post_word_audio', 'll_tools_invalidate_audio_credit_grid_cache_on_save', 10, 3);

function ll_tools_invalidate_audio_credit_grid_cache_on_meta(int $meta_id, int $post_id, string $meta_key, $meta_value): void {
    unset($meta_id, $meta_value);

    if (!ll_tools_audio_credit_grid_is_relevant_meta_key($meta_key)) {
        return;
    }

    ll_tools_invalidate_audio_credit_grid_cache_for_post($post_id);
}
add_action('added_post_meta', 'll_tools_invalidate_audio_credit_grid_cache_on_meta', 10, 4);
add_action('updated_post_meta', 'll_tools_invalidate_audio_credit_grid_cache_on_meta', 10, 4);

function ll_tools_invalidate_audio_credit_grid_cache_on_deleted_meta($meta_ids, int $post_id, string $meta_key, $meta_value): void {
    unset($meta_ids, $meta_value);

    if (!ll_tools_audio_credit_grid_is_relevant_meta_key($meta_key)) {
        return;
    }

    ll_tools_invalidate_audio_credit_grid_cache_for_post($post_id);
}
add_action('deleted_post_meta', 'll_tools_invalidate_audio_credit_grid_cache_on_deleted_meta', 10, 4);

function ll_tools_invalidate_audio_credit_grid_cache_on_post_lifecycle(int $post_id): void {
    ll_tools_invalidate_audio_credit_grid_cache_for_post($post_id);
}
add_action('trashed_post', 'll_tools_invalidate_audio_credit_grid_cache_on_post_lifecycle');
add_action('untrashed_post', 'll_tools_invalidate_audio_credit_grid_cache_on_post_lifecycle');
add_action('before_delete_post', 'll_tools_invalidate_audio_credit_grid_cache_on_post_lifecycle');

function ll_tools_get_audio_credit_grid_page_number(): int {
    $raw_page = isset($_GET['ll_audio_credits_page']) ? wp_unslash($_GET['ll_audio_credits_page']) : 1;
    $page = (int) $raw_page;
    return max(1, $page);
}

function ll_tools_render_audio_credit_grid_meta_row(string $label, string $value): string {
    if ($value === '') {
        return '';
    }

    return '<div class="ll-audio-credit-grid__meta-row"><dt>' . esc_html($label) . '</dt><dd>' . nl2br(esc_html($value)) . '</dd></div>';
}

function ll_tools_audio_credit_grid_shortcode($atts) {
    $atts = shortcode_atts(
        [
            'posts_per_page' => 12,
        ],
        $atts,
        'audio_credit_grid'
    );

    $paged = ll_tools_get_audio_credit_grid_page_number();
    $posts_per_page = max(1, (int) $atts['posts_per_page']);
    $recording_ids = ll_tools_get_audio_credit_grid_recording_ids();
    $total_posts = count($recording_ids);
    $total_pages = $total_posts > 0 ? (int) ceil($total_posts / $posts_per_page) : 0;
    $page_ids = $total_posts > 0
        ? array_slice($recording_ids, max(0, ($paged - 1) * $posts_per_page), $posts_per_page)
        : [];
    $audio_posts = !empty($page_ids)
        ? get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post__in' => $page_ids,
            'orderby' => 'post__in',
            'posts_per_page' => count($page_ids),
        ])
        : [];

    ob_start();

    if (!empty($audio_posts)) {
        echo '<div class="ll-audio-credit-grid">';

        foreach ($audio_posts as $audio_post) {
            if (!($audio_post instanceof WP_Post)) {
                continue;
            }

            $audio_post_id = (int) $audio_post->ID;
            $audio_attribution = function_exists('ll_tools_get_audio_attribution_meta')
                ? ll_tools_get_audio_attribution_meta($audio_post_id)
                : [];
            $audio_path = trim((string) get_post_meta($audio_post_id, 'audio_file_path', true));
            $audio_url = '';
            if ($audio_path !== '') {
                if (function_exists('ll_tools_resolve_audio_file_url')) {
                    $audio_url = (string) ll_tools_resolve_audio_file_url($audio_path);
                } else {
                    $audio_url = (0 === strpos($audio_path, 'http')) ? $audio_path : site_url($audio_path);
                }
            }

            $parent_id = (int) $audio_post->post_parent;
            $word_title = $parent_id > 0 ? get_the_title($parent_id) : get_the_title($audio_post_id);
            $recording_types = wp_get_post_terms($audio_post_id, 'recording_type', ['fields' => 'names']);
            if (is_wp_error($recording_types)) {
                $recording_types = [];
            }

            $source_name = trim((string) ($audio_attribution['audio_source_name'] ?? ''));
            $source_url = trim((string) ($audio_attribution['audio_source_url'] ?? ''));
            $license_name = trim((string) ($audio_attribution['audio_license'] ?? ''));
            $license_url = trim((string) ($audio_attribution['audio_license_url'] ?? ''));

            echo '<article class="ll-audio-credit-grid__item">';
            echo '<header class="ll-audio-credit-grid__header">';
            echo '<h3 class="ll-audio-credit-grid__title">' . esc_html($word_title) . '</h3>';
            if (!empty($recording_types)) {
                echo '<div class="ll-audio-credit-grid__badges">';
                foreach ($recording_types as $recording_type_name) {
                    echo '<span class="ll-audio-credit-grid__badge">' . esc_html((string) $recording_type_name) . '</span>';
                }
                echo '</div>';
            }
            echo '</header>';

            if ($audio_url !== '') {
                echo '<audio class="ll-audio-credit-grid__player" controls preload="none" src="' . esc_url($audio_url) . '"></audio>';
            }

            echo '<dl class="ll-audio-credit-grid__meta">';
            echo ll_tools_render_audio_credit_grid_meta_row(__('Speaker', 'll-tools-text-domain'), trim((string) ($audio_attribution['speaker_name'] ?? '')));
            echo ll_tools_render_audio_credit_grid_meta_row(__('Credit', 'll-tools-text-domain'), trim((string) ($audio_attribution['audio_credit'] ?? '')));

            if ($source_name !== '' || $source_url !== '') {
                $source_markup = $source_name !== '' ? esc_html($source_name) : esc_html($source_url);
                if ($source_url !== '') {
                    $source_markup = '<a href="' . esc_url($source_url) . '" target="_blank" rel="noopener noreferrer">' . $source_markup . '</a>';
                }
                echo '<div class="ll-audio-credit-grid__meta-row"><dt>' . esc_html__('Source', 'll-tools-text-domain') . '</dt><dd>' . $source_markup . '</dd></div>';
            }

            if ($license_name !== '' || $license_url !== '') {
                $license_markup = $license_name !== '' ? esc_html($license_name) : esc_html($license_url);
                if ($license_url !== '') {
                    $license_markup = '<a href="' . esc_url($license_url) . '" target="_blank" rel="license noopener noreferrer">' . $license_markup . '</a>';
                }
                echo '<div class="ll-audio-credit-grid__meta-row"><dt>' . esc_html__('License', 'll-tools-text-domain') . '</dt><dd>' . $license_markup . '</dd></div>';
            }

            echo ll_tools_render_audio_credit_grid_meta_row(__('Changes made', 'll-tools-text-domain'), trim((string) ($audio_attribution['audio_change_note'] ?? '')));
            echo '</dl>';
            echo '</article>';
        }

        echo '</div>';

        if ($total_pages > 1) {
            $pagination_base = add_query_arg('ll_audio_credits_page', '%#%');
            echo '<div class="ll-grid-pagination">';
            echo paginate_links([
                'base' => $pagination_base,
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
                'prev_text' => __('« Prev', 'll-tools-text-domain'),
                'next_text' => __('Next »', 'll-tools-text-domain'),
                'add_args' => false,
            ]);
            echo '</div>';
        }
    } else {
        echo '<p>' . esc_html__('No audio recordings found with public credit information.', 'll-tools-text-domain') . '</p>';
    }

    return ob_get_clean();
}
add_shortcode('audio_credit_grid', 'll_tools_audio_credit_grid_shortcode');

function ll_maybe_enqueue_audio_credit_grid_styles() {
    if (!is_singular()) {
        return;
    }

    $post = get_post();
    if ($post && has_shortcode($post->post_content, 'audio_credit_grid')) {
        ll_enqueue_asset_by_timestamp('/css/audio-credit-grid.css', 'll-audio-credit-grid-style');
    }
}
add_action('wp_enqueue_scripts', 'll_maybe_enqueue_audio_credit_grid_styles');
