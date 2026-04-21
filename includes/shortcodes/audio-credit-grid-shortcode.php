<?php
/**
 * Provides an [audio_credit_grid] shortcode to display public attribution
 * details for sourced Word Audio recordings.
 */

if (!defined('WPINC')) { die; }

function ll_tools_get_audio_credit_grid_meta_keys(): array {
    if (function_exists('ll_tools_get_audio_attribution_fields')) {
        return array_keys(ll_tools_get_audio_attribution_fields());
    }

    return ['speaker_name'];
}

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
    $meta_query = [
        'relation' => 'AND',
        [
            'key' => 'audio_file_path',
            'value' => '',
            'compare' => '!=',
        ],
        [
            'relation' => 'OR',
        ],
    ];

    foreach (ll_tools_get_audio_credit_grid_meta_keys() as $meta_key) {
        $meta_query[1][] = [
            'key' => $meta_key,
            'value' => '',
            'compare' => '!=',
        ];
    }

    $query = new WP_Query([
        'post_type' => 'word_audio',
        'post_status' => 'publish',
        'posts_per_page' => max(1, (int) $atts['posts_per_page']),
        'paged' => $paged,
        'orderby' => 'title',
        'order' => 'ASC',
        'meta_query' => $meta_query,
    ]);

    ob_start();

    if ($query->have_posts()) {
        echo '<div class="ll-audio-credit-grid">';

        while ($query->have_posts()) {
            $query->the_post();

            $audio_post_id = (int) get_the_ID();
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

            $parent_id = (int) get_post_field('post_parent', $audio_post_id);
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

        $pagination_base = add_query_arg('ll_audio_credits_page', '%#%');
        echo '<div class="ll-grid-pagination">';
        echo paginate_links([
            'base' => $pagination_base,
            'format' => '',
            'current' => $paged,
            'total' => (int) $query->max_num_pages,
            'prev_text' => __('« Prev', 'll-tools-text-domain'),
            'next_text' => __('Next »', 'll-tools-text-domain'),
            'add_args' => false,
        ]);
        echo '</div>';
    } else {
        echo '<p>' . esc_html__('No audio recordings found with public credit information.', 'll-tools-text-domain') . '</p>';
    }

    wp_reset_postdata();

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
