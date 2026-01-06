<?php
if (!defined('WPINC')) { die; }

function ll_tools_get_words_with_example_sentence_meta(int $limit = -1): array {
    $args = [
        'post_type'      => 'words',
        'post_status'    => 'any',
        'posts_per_page' => ($limit > 0) ? $limit : -1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => 'word_example_sentence',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'word_example_sentence_translation',
                'compare' => 'EXISTS',
            ],
        ],
    ];

    return get_posts($args);
}

function ll_tools_migrate_example_sentence_meta_to_intro_recordings(): array {
    $word_ids = ll_tools_get_words_with_example_sentence_meta();
    $migrated = 0;
    $skipped = 0;

    foreach ($word_ids as $word_id) {
        $example = sanitize_text_field(get_post_meta($word_id, 'word_example_sentence', true));
        $translation = sanitize_text_field(get_post_meta($word_id, 'word_example_sentence_translation', true));

        if ($example === '' && $translation === '') {
            continue;
        }

        $intro_audio_ids = get_posts([
            'post_type'      => 'word_audio',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_parent'    => (int) $word_id,
            'tax_query'      => [
                [
                    'taxonomy' => 'recording_type',
                    'field'    => 'slug',
                    'terms'    => ['introduction'],
                ],
            ],
        ]);

        if (empty($intro_audio_ids)) {
            $skipped++;
            continue;
        }

        $has_intro_text = false;
        foreach ($intro_audio_ids as $audio_id) {
            $existing_text = (string) get_post_meta($audio_id, 'recording_text', true);
            $existing_translation = (string) get_post_meta($audio_id, 'recording_translation', true);

            if ($existing_text !== '' || $existing_translation !== '') {
                $has_intro_text = true;
            }

            if ($example !== '' && $existing_text === '') {
                update_post_meta($audio_id, 'recording_text', $example);
                $has_intro_text = true;
            }

            if ($translation !== '' && $existing_translation === '') {
                update_post_meta($audio_id, 'recording_translation', $translation);
                $has_intro_text = true;
            }
        }

        if ($has_intro_text) {
            delete_post_meta($word_id, 'word_example_sentence');
            delete_post_meta($word_id, 'word_example_sentence_translation');
            $migrated++;
        } else {
            $skipped++;
        }
    }

    return [
        'migrated' => $migrated,
        'skipped' => $skipped,
        'total' => count($word_ids),
    ];
}

function ll_tools_maybe_run_example_sentence_migration() {
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return;
    }

    if (get_option('ll_tools_example_sentence_migration_done')) {
        return;
    }

    $candidate_ids = ll_tools_get_words_with_example_sentence_meta(1);
    if (empty($candidate_ids)) {
        update_option('ll_tools_example_sentence_migration_done', '1');
        return;
    }

    ll_tools_migrate_example_sentence_meta_to_intro_recordings();
    update_option('ll_tools_example_sentence_migration_done', '1');
}
add_action('admin_init', 'll_tools_maybe_run_example_sentence_migration');

