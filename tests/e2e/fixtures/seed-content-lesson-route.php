<?php
/**
 * WP-CLI eval-file script for the content lesson route/media E2E fixture.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

if (!defined('LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY')) {
    define('LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY', 'll-tools-e2e-content-lesson-route-media');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY')) {
    define('LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY', '_ll_tools_e2e_fixture');
}
if (!defined('LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_VERSION_META_KEY')) {
    define('LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_VERSION_META_KEY', '_ll_tools_e2e_fixture_version');
}

function ll_tools_content_lesson_route_fixture_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_content_lesson_route_fixture_marker_matches($object_id, string $kind): bool {
    if ($kind === 'term') {
        return (string) get_term_meta((int) $object_id, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY, true) === LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY;
    }

    return (string) get_post_meta((int) $object_id, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY, true) === LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY;
}

function ll_tools_content_lesson_route_fixture_assert_post_slug_available(string $slug, string $post_type): void {
    $existing = get_page_by_path($slug, OBJECT, $post_type);
    if ($existing instanceof WP_Post && !ll_tools_content_lesson_route_fixture_marker_matches((int) $existing->ID, 'post')) {
        ll_tools_content_lesson_route_fixture_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s post with slug %s.',
            $post_type,
            $slug
        ));
    }
}

function ll_tools_content_lesson_route_fixture_assert_term_slug_available(string $slug, string $taxonomy): void {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term && !is_wp_error($existing) && !ll_tools_content_lesson_route_fixture_marker_matches((int) $existing->term_id, 'term')) {
        ll_tools_content_lesson_route_fixture_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s term with slug %s.',
            $taxonomy,
            $slug
        ));
    }
}

function ll_tools_content_lesson_route_fixture_tag_post(int $post_id, string $fixture_version): void {
    update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY);
    update_post_meta($post_id, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_content_lesson_route_fixture_tag_term(int $term_id, string $fixture_version): void {
    update_term_meta($term_id, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY);
    update_term_meta($term_id, LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_content_lesson_route_fixture_delete_posts(): int {
    $deleted = 0;
    $ids = get_posts([
        'post_type' => ['ll_content_lesson', 'll_vocab_lesson'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY,
            'value' => LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY,
        ]],
    ]);

    foreach ((array) $ids as $post_id) {
        $post_id = (int) $post_id;
        if ($post_id > 0 && wp_delete_post($post_id, true)) {
            $deleted++;
        }
    }

    return $deleted;
}

function ll_tools_content_lesson_route_fixture_delete_terms(): int {
    $deleted = 0;
    foreach (['word-category', 'wordset'] as $taxonomy) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_META_KEY,
                'value' => LL_TOOLS_CONTENT_LESSON_ROUTE_FIXTURE_KEY,
            ]],
        ]);

        if (is_wp_error($terms)) {
            continue;
        }

        foreach ((array) $terms as $term_id) {
            $term_id = (int) $term_id;
            ll_tools_content_lesson_route_fixture_remove_wordset_option_id($term_id);
            if ($term_id > 0 && !is_wp_error(wp_delete_term($term_id, $taxonomy))) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

function ll_tools_content_lesson_route_fixture_remove_wordset_option_id(int $wordset_id): void {
    if ($wordset_id <= 0) {
        return;
    }

    $raw = get_option('ll_vocab_lesson_wordsets', []);
    $ids = is_array($raw) ? array_values(array_unique(array_map('intval', $raw))) : [];
    $ids = array_values(array_filter($ids, static function (int $id) use ($wordset_id): bool {
        return $id > 0 && $id !== $wordset_id;
    }));
    update_option('ll_vocab_lesson_wordsets', $ids, false);
}

function ll_tools_content_lesson_route_fixture_add_wordset_option_id(int $wordset_id): void {
    if ($wordset_id <= 0) {
        return;
    }

    $raw = get_option('ll_vocab_lesson_wordsets', []);
    $ids = is_array($raw) ? array_values(array_unique(array_map('intval', $raw))) : [];
    $ids[] = $wordset_id;
    $ids = array_values(array_unique(array_filter($ids, static function (int $id): bool {
        return $id > 0;
    })));
    update_option('ll_vocab_lesson_wordsets', $ids, false);
}

function ll_tools_content_lesson_route_fixture_insert_term(string $taxonomy, string $name, string $slug, string $fixture_version): int {
    $insert = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($insert)) {
        ll_tools_content_lesson_route_fixture_fail(sprintf('Unable to create %s term %s: %s', $taxonomy, $slug, $insert->get_error_message()));
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id <= 0) {
        ll_tools_content_lesson_route_fixture_fail(sprintf('Unable to create %s term %s.', $taxonomy, $slug));
    }

    ll_tools_content_lesson_route_fixture_tag_term($term_id, $fixture_version);
    return $term_id;
}

function ll_tools_content_lesson_route_fixture_insert_post(array $args, string $fixture_version): int {
    $post_id = wp_insert_post($args, true);
    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown error';
        ll_tools_content_lesson_route_fixture_fail(sprintf(
            'Unable to create fixture %s post %s: %s',
            (string) ($args['post_type'] ?? 'post'),
            (string) ($args['post_name'] ?? ''),
            $message
        ));
    }

    $post_id = (int) $post_id;
    ll_tools_content_lesson_route_fixture_tag_post($post_id, $fixture_version);
    return $post_id;
}

function ll_tools_content_lesson_route_fixture_relative_url(int $post_id): string {
    $url = get_permalink($post_id);
    if (!is_string($url) || $url === '') {
        return '';
    }

    return function_exists('wp_make_link_relative') ? wp_make_link_relative($url) : $url;
}

function ll_tools_content_lesson_route_fixture_run(): array {
    $fixture_version = '2026-06-10.1';
    $wordset_name = 'E2E Content Lesson Wordset';
    $wordset_slug = 'll-e2e-content-lesson';
    $category_name = 'E2E Dialogue Practice';
    $category_slug = 'll-e2e-content-lesson-dialogue';
    $content_lesson_title = 'E2E Audio Story Route';
    $content_lesson_slug = 'll-e2e-content-lesson-route';
    $vocab_lesson_title = 'E2E Dialogue Practice Vocab';
    $vocab_lesson_slug = 'll-e2e-content-lesson-vocab';
    $media_url = 'https://ll-content-lesson-fixture.test/audio/story.mp3';
    $notes = 'These notes confirm the content lesson template renders post content after the transcript.';
    $excerpt = 'A short fixture story for the real content lesson route.';
    $transcript_source = "WEBVTT\n\n00:00:01.000 --> 00:00:03.500\nFirst fixture cue.\n\n00:00:04.000 --> 00:00:06.250\nSecond fixture cue.";

    foreach ([
        [$content_lesson_slug, 'll_content_lesson'],
        [$vocab_lesson_slug, 'll_vocab_lesson'],
    ] as $post_slug) {
        ll_tools_content_lesson_route_fixture_assert_post_slug_available($post_slug[0], $post_slug[1]);
    }
    ll_tools_content_lesson_route_fixture_assert_term_slug_available($wordset_slug, 'wordset');
    ll_tools_content_lesson_route_fixture_assert_term_slug_available($category_slug, 'word-category');

    $deleted_posts = ll_tools_content_lesson_route_fixture_delete_posts();
    $deleted_terms = ll_tools_content_lesson_route_fixture_delete_terms();

    $wordset_id = ll_tools_content_lesson_route_fixture_insert_term('wordset', $wordset_name, $wordset_slug, $fixture_version);
    update_term_meta($wordset_id, 'll_language', 'English');
    if (defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY')) {
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'target');
    } else {
        update_term_meta($wordset_id, 'll_wordset_word_title_language_role', 'target');
    }
    ll_tools_content_lesson_route_fixture_add_wordset_option_id($wordset_id);

    $category_id = ll_tools_content_lesson_route_fixture_insert_term('word-category', $category_name, $category_slug, $fixture_version);
    update_term_meta($category_id, 'll_category_visibility', 'public');
    update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
    update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
    update_term_meta($category_id, 'll_category_enabled_games', []);
    if (function_exists('ll_tools_set_category_wordset_owner')) {
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
    }

    $vocab_lesson_id = ll_tools_content_lesson_route_fixture_insert_post([
        'post_type' => 'll_vocab_lesson',
        'post_status' => 'publish',
        'post_title' => $vocab_lesson_title,
        'post_name' => $vocab_lesson_slug,
        'post_content' => '[word_grid]',
    ], $fixture_version);
    update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
    update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);

    $cues = function_exists('ll_tools_content_lesson_parse_source')
        ? ll_tools_content_lesson_parse_source($transcript_source, 'vtt')
        : [];
    if (is_wp_error($cues) || !is_array($cues) || count($cues) !== 2) {
        $message = is_wp_error($cues) ? $cues->get_error_message() : 'unexpected cue count';
        ll_tools_content_lesson_route_fixture_fail('Unable to parse content lesson fixture cues: ' . $message);
    }

    $content_lesson_id = ll_tools_content_lesson_route_fixture_insert_post([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'post_title' => $content_lesson_title,
        'post_name' => $content_lesson_slug,
        'post_excerpt' => $excerpt,
        'post_content' => $notes,
        'menu_order' => 10,
    ], $fixture_version);
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $wordset_id);
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, $media_url);
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_FORMAT_META, 'vtt');
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_TRANSCRIPT_SOURCE_META, $transcript_source);
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CUES_META, $cues);
    update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$category_id]);

    clean_term_cache([$wordset_id], 'wordset');
    clean_term_cache([$category_id], 'word-category');
    clean_post_cache($content_lesson_id);
    clean_post_cache($vocab_lesson_id);
    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id]);
    }
    flush_rewrite_rules(false);

    return [
        'ok' => true,
        'fixtureVersion' => $fixture_version,
        'deletedPosts' => $deleted_posts,
        'deletedTerms' => $deleted_terms,
        'wordsetId' => $wordset_id,
        'wordsetName' => $wordset_name,
        'categoryId' => $category_id,
        'categoryName' => $category_name,
        'lessonId' => $content_lesson_id,
        'lessonTitle' => $content_lesson_title,
        'lessonExcerpt' => $excerpt,
        'lessonPath' => ll_tools_content_lesson_route_fixture_relative_url($content_lesson_id),
        'mediaUrl' => $media_url,
        'notes' => $notes,
        'vocabLessonId' => $vocab_lesson_id,
        'vocabLessonPath' => ll_tools_content_lesson_route_fixture_relative_url($vocab_lesson_id),
        'cues' => array_values(array_map(static function (array $cue): array {
            return [
                'id' => (int) ($cue['id'] ?? 0),
                'start_ms' => (int) ($cue['start_ms'] ?? 0),
                'end_ms' => (int) ($cue['end_ms'] ?? 0),
                'text' => (string) ($cue['text'] ?? ''),
            ];
        }, $cues)),
    ];
}

$summary = ll_tools_content_lesson_route_fixture_run();
echo wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
