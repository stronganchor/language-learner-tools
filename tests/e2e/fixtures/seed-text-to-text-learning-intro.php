<?php
/**
 * WP-CLI eval-file script for the text-to-text Learning introduction E2E fixture.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

const LL_TOOLS_T2T_INTRO_FIXTURE_KEY = 'll-tools-e2e-text-to-text-learning-intro';
const LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY = '_ll_tools_e2e_fixture';
const LL_TOOLS_T2T_INTRO_FIXTURE_VERSION_META_KEY = '_ll_tools_e2e_fixture_version';

function ll_tools_t2t_intro_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_t2t_intro_manifest_path(): string {
    $env_path = getenv('LL_TOOLS_TEXT_TO_TEXT_INTRO_FIXTURE_MANIFEST');
    if (is_string($env_path) && trim($env_path) !== '') {
        return trim($env_path);
    }

    return __DIR__ . '/text-to-text-learning-intro.json';
}

function ll_tools_t2t_intro_manifest(): array {
    $path = ll_tools_t2t_intro_manifest_path();
    if (!is_readable($path)) {
        ll_tools_t2t_intro_fail('Text-to-text intro fixture manifest is not readable: ' . $path);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        ll_tools_t2t_intro_fail('Text-to-text intro fixture manifest is not valid JSON: ' . $path);
    }

    if (trim((string) ($decoded['fixtureVersion'] ?? '')) === '') {
        ll_tools_t2t_intro_fail('Text-to-text intro fixture manifest is missing fixtureVersion.');
    }

    foreach (['page', 'wordset', 'category', 'words'] as $key) {
        if (!array_key_exists($key, $decoded)) {
            ll_tools_t2t_intro_fail('Text-to-text intro fixture manifest is missing ' . $key . '.');
        }
    }

    if (!is_array($decoded['words']) || count($decoded['words']) < 5) {
        ll_tools_t2t_intro_fail('Text-to-text intro fixture must define at least five words.');
    }

    return $decoded;
}

function ll_tools_t2t_intro_marker_matches($object_id, string $kind): bool {
    if ($kind === 'term') {
        return (string) get_term_meta((int) $object_id, LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY, true) === LL_TOOLS_T2T_INTRO_FIXTURE_KEY;
    }

    return (string) get_post_meta((int) $object_id, LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY, true) === LL_TOOLS_T2T_INTRO_FIXTURE_KEY;
}

function ll_tools_t2t_intro_assert_post_slug_available(string $slug, string $post_type): void {
    $existing = get_page_by_path($slug, OBJECT, $post_type);
    if ($existing instanceof WP_Post && !ll_tools_t2t_intro_marker_matches((int) $existing->ID, 'post')) {
        ll_tools_t2t_intro_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s post with slug %s.',
            $post_type,
            $slug
        ));
    }
}

function ll_tools_t2t_intro_assert_term_slug_available(string $slug, string $taxonomy): void {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term && !is_wp_error($existing) && !ll_tools_t2t_intro_marker_matches((int) $existing->term_id, 'term')) {
        ll_tools_t2t_intro_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s term with slug %s.',
            $taxonomy,
            $slug
        ));
    }
}

function ll_tools_t2t_intro_delete_fixture_posts(): int {
    $deleted = 0;
    $ids = get_posts([
        'post_type' => ['page', 'words', 'll_quiz_page'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY,
            'value' => LL_TOOLS_T2T_INTRO_FIXTURE_KEY,
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

function ll_tools_t2t_intro_delete_fixture_terms(): int {
    $deleted = 0;
    foreach (['word-category', 'wordset'] as $taxonomy) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY,
                'value' => LL_TOOLS_T2T_INTRO_FIXTURE_KEY,
            ]],
        ]);

        if (is_wp_error($terms)) {
            continue;
        }

        foreach ((array) $terms as $term_id) {
            $term_id = (int) $term_id;
            if ($term_id > 0 && !is_wp_error(wp_delete_term($term_id, $taxonomy))) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

function ll_tools_t2t_intro_tag_post(int $post_id, string $fixture_version): void {
    update_post_meta($post_id, LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY, LL_TOOLS_T2T_INTRO_FIXTURE_KEY);
    update_post_meta($post_id, LL_TOOLS_T2T_INTRO_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_t2t_intro_tag_term(int $term_id, string $fixture_version): void {
    update_term_meta($term_id, LL_TOOLS_T2T_INTRO_FIXTURE_META_KEY, LL_TOOLS_T2T_INTRO_FIXTURE_KEY);
    update_term_meta($term_id, LL_TOOLS_T2T_INTRO_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_t2t_intro_insert_term(string $taxonomy, string $name, string $slug, string $fixture_version): int {
    $insert = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($insert)) {
        ll_tools_t2t_intro_fail(sprintf('Unable to create %s term %s: %s', $taxonomy, $slug, $insert->get_error_message()));
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id <= 0) {
        ll_tools_t2t_intro_fail(sprintf('Unable to create %s term %s.', $taxonomy, $slug));
    }

    ll_tools_t2t_intro_tag_term($term_id, $fixture_version);
    return $term_id;
}

function ll_tools_t2t_intro_create_word(array $word, int $wordset_id, int $category_id, string $fixture_version): int {
    $title = trim((string) ($word['title'] ?? ''));
    $slug = sanitize_title((string) ($word['slug'] ?? ''));
    $translation = trim((string) ($word['translation'] ?? ''));

    if ($title === '' || $slug === '' || $translation === '') {
        ll_tools_t2t_intro_fail('Fixture words must include title, slug, and translation.');
    }

    $word_id = wp_insert_post([
        'post_type' => 'words',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '',
    ], true);

    if (is_wp_error($word_id) || (int) $word_id <= 0) {
        $message = is_wp_error($word_id) ? $word_id->get_error_message() : 'unknown error';
        ll_tools_t2t_intro_fail(sprintf('Unable to create fixture word %s: %s', $slug, $message));
    }

    $word_id = (int) $word_id;
    wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
    wp_set_object_terms($word_id, [$category_id], 'word-category', false);
    update_post_meta($word_id, 'word_translation', sanitize_text_field($translation));
    update_post_meta($word_id, 'word_note', 'Text-to-text Learning intro Playwright fixture.');
    ll_tools_t2t_intro_tag_post($word_id, $fixture_version);

    return $word_id;
}

function ll_tools_t2t_intro_run(): array {
    $manifest = ll_tools_t2t_intro_manifest();
    $fixture_version = (string) $manifest['fixtureVersion'];
    $page = (array) $manifest['page'];
    $wordset = (array) $manifest['wordset'];
    $category = (array) $manifest['category'];
    $words = (array) $manifest['words'];

    $page_slug = sanitize_title((string) ($page['slug'] ?? ''));
    $wordset_slug = sanitize_title((string) ($wordset['slug'] ?? ''));
    $category_slug = sanitize_title((string) ($category['slug'] ?? ''));
    if ($page_slug === '' || $wordset_slug === '' || $category_slug === '') {
        ll_tools_t2t_intro_fail('Fixture page, wordset, and category require slugs.');
    }

    ll_tools_t2t_intro_assert_post_slug_available($page_slug, 'page');
    foreach ($words as $word) {
        ll_tools_t2t_intro_assert_post_slug_available(sanitize_title((string) ($word['slug'] ?? '')), 'words');
    }
    ll_tools_t2t_intro_assert_term_slug_available($wordset_slug, 'wordset');
    ll_tools_t2t_intro_assert_term_slug_available($category_slug, 'word-category');

    $deleted_posts = ll_tools_t2t_intro_delete_fixture_posts();
    $deleted_terms = ll_tools_t2t_intro_delete_fixture_terms();

    $wordset_id = ll_tools_t2t_intro_insert_term(
        'wordset',
        sanitize_text_field((string) ($wordset['name'] ?? $wordset_slug)),
        $wordset_slug,
        $fixture_version
    );
    if (defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY')) {
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'target');
    } else {
        update_term_meta($wordset_id, 'll_wordset_word_title_language_role', 'target');
    }
    update_term_meta($wordset_id, 'll_language', 'Biblical Hebrew');

    $category_id = ll_tools_t2t_intro_insert_term(
        'word-category',
        sanitize_text_field((string) ($category['name'] ?? $category_slug)),
        $category_slug,
        $fixture_version
    );
    update_term_meta($category_id, 'll_quiz_prompt_type', sanitize_key((string) ($category['promptType'] ?? 'text_translation')));
    update_term_meta($category_id, 'll_quiz_option_type', sanitize_key((string) ($category['optionType'] ?? 'text_title')));
    update_term_meta($category_id, 'll_category_visibility', 'public');
    update_term_meta($category_id, 'll_category_enabled_games', []);
    if (function_exists('ll_tools_set_category_wordset_owner')) {
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
    }

    $word_ids = [];
    foreach ($words as $word) {
        $word_ids[] = ll_tools_t2t_intro_create_word((array) $word, $wordset_id, $category_id, $fixture_version);
    }

    $quiz_page_id = 0;
    if (function_exists('ll_tools_get_or_create_quiz_page_for_category')) {
        $quiz_page_id = (int) ll_tools_get_or_create_quiz_page_for_category($category_id);
        if ($quiz_page_id > 0) {
            ll_tools_t2t_intro_tag_post($quiz_page_id, $fixture_version);
        }
    }

    $mode = sanitize_key((string) ($page['shortcodeMode'] ?? 'learning'));
    if (!in_array($mode, ['practice', 'learning', 'self-check'], true)) {
        $mode = 'learning';
    }
    $page_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => sanitize_text_field((string) ($page['title'] ?? 'LL E2E Text-to-Text Intro')),
        'post_name' => $page_slug,
        'post_content' => sprintf('[quiz_pages_grid wordset="%s" popup="yes" mode="%s"]', esc_attr($wordset_slug), esc_attr($mode)),
    ], true);

    if (is_wp_error($page_id) || (int) $page_id <= 0) {
        $message = is_wp_error($page_id) ? $page_id->get_error_message() : 'unknown error';
        ll_tools_t2t_intro_fail('Unable to create fixture page: ' . $message);
    }
    $page_id = (int) $page_id;
    ll_tools_t2t_intro_tag_post($page_id, $fixture_version);

    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id]);
    }
    clean_term_cache([$wordset_id], 'wordset');
    clean_term_cache([$category_id], 'word-category');
    foreach ($word_ids as $word_id) {
        clean_post_cache((int) $word_id);
    }
    flush_rewrite_rules(false);

    return [
        'ok' => true,
        'fixtureVersion' => $fixture_version,
        'deletedPosts' => $deleted_posts,
        'deletedTerms' => $deleted_terms,
        'pageId' => $page_id,
        'pagePath' => '/' . trim($page_slug, '/') . '/',
        'wordsetId' => $wordset_id,
        'wordsetSlug' => $wordset_slug,
        'categoryId' => $category_id,
        'categoryName' => (string) ($category['name'] ?? $category_slug),
        'categorySlug' => $category_slug,
        'quizPageId' => $quiz_page_id,
        'wordIds' => array_values(array_map('intval', $word_ids)),
        'words' => array_values(array_map(static function ($word): array {
            return [
                'title' => (string) ($word['title'] ?? ''),
                'translation' => (string) ($word['translation'] ?? ''),
            ];
        }, $words)),
    ];
}

$summary = ll_tools_t2t_intro_run();
echo wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
