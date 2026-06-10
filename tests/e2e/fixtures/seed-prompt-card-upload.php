<?php
/**
 * WP-CLI eval-file script for the prompt-card real upload E2E fixture.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

if (!defined('LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY', 'll-tools-e2e-prompt-card-upload');
}
if (!defined('LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY', '_ll_tools_e2e_fixture');
}
if (!defined('LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_VERSION_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_VERSION_META_KEY', '_ll_tools_e2e_fixture_version');
}

function ll_tools_prompt_card_upload_fixture_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_prompt_card_upload_fixture_post_marker_matches($post_id): bool {
    return (string) get_post_meta((int) $post_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY, true) === LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY;
}

function ll_tools_prompt_card_upload_fixture_term_marker_matches($term_id): bool {
    return (string) get_term_meta((int) $term_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY, true) === LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY;
}

function ll_tools_prompt_card_upload_fixture_assert_post_slug_available(string $slug, string $post_type): void {
    $existing = get_page_by_path($slug, OBJECT, $post_type);
    if ($existing instanceof WP_Post && !ll_tools_prompt_card_upload_fixture_post_marker_matches((int) $existing->ID)) {
        ll_tools_prompt_card_upload_fixture_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s post with slug %s.',
            $post_type,
            $slug
        ));
    }
}

function ll_tools_prompt_card_upload_fixture_assert_term_slug_available(string $slug, string $taxonomy): void {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term && !is_wp_error($existing) && !ll_tools_prompt_card_upload_fixture_term_marker_matches((int) $existing->term_id)) {
        ll_tools_prompt_card_upload_fixture_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s term with slug %s.',
            $taxonomy,
            $slug
        ));
    }
}

function ll_tools_prompt_card_upload_fixture_assert_user_available(string $login): void {
    $existing = get_user_by('login', $login);
    if ($existing instanceof WP_User) {
        $marker = (string) get_user_meta((int) $existing->ID, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY, true);
        if ($marker !== '' && $marker !== LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY) {
            ll_tools_prompt_card_upload_fixture_fail(sprintf(
                'Refusing to overwrite existing user %s with a different fixture marker.',
                $login
            ));
        }
    }
}

function ll_tools_prompt_card_upload_fixture_tag_post(int $post_id, string $fixture_version): void {
    update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY);
    update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_prompt_card_upload_fixture_tag_term(int $term_id, string $fixture_version): void {
    update_term_meta($term_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY);
    update_term_meta($term_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_prompt_card_upload_fixture_delete_posts(): int {
    $deleted = 0;
    $prompt_card_ids = get_posts([
        'post_type' => ['ll_prompt_card'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY,
            'value' => LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY,
        ]],
    ]);

    foreach ((array) $prompt_card_ids as $prompt_card_id) {
        $attachments = get_children([
            'post_parent' => (int) $prompt_card_id,
            'post_type' => 'attachment',
            'post_status' => 'any',
            'fields' => 'ids',
        ]);
        foreach ((array) $attachments as $attachment_id) {
            if ((int) $attachment_id > 0 && wp_delete_attachment((int) $attachment_id, true)) {
                $deleted++;
            }
        }
    }

    $post_ids = get_posts([
        'post_type' => ['page', 'll_prompt_card'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY,
            'value' => LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY,
        ]],
    ]);

    foreach ((array) $post_ids as $post_id) {
        $post_id = (int) $post_id;
        if ($post_id > 0 && wp_delete_post($post_id, true)) {
            $deleted++;
        }
    }

    return $deleted;
}

function ll_tools_prompt_card_upload_fixture_delete_terms(): int {
    $deleted = 0;
    foreach (['word-category', 'wordset'] as $taxonomy) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY,
                'value' => LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY,
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

function ll_tools_prompt_card_upload_fixture_insert_term(string $taxonomy, string $name, string $slug, string $fixture_version): int {
    $insert = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($insert)) {
        ll_tools_prompt_card_upload_fixture_fail(sprintf('Unable to create %s term %s: %s', $taxonomy, $slug, $insert->get_error_message()));
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id <= 0) {
        ll_tools_prompt_card_upload_fixture_fail(sprintf('Unable to create %s term %s.', $taxonomy, $slug));
    }

    ll_tools_prompt_card_upload_fixture_tag_term($term_id, $fixture_version);
    return $term_id;
}

function ll_tools_prompt_card_upload_fixture_insert_post(array $args, string $fixture_version): int {
    $post_id = wp_insert_post($args, true);
    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown error';
        ll_tools_prompt_card_upload_fixture_fail(sprintf(
            'Unable to create fixture %s post %s: %s',
            (string) ($args['post_type'] ?? 'post'),
            (string) ($args['post_name'] ?? ''),
            $message
        ));
    }

    $post_id = (int) $post_id;
    ll_tools_prompt_card_upload_fixture_tag_post($post_id, $fixture_version);
    return $post_id;
}

function ll_tools_prompt_card_upload_fixture_assign_terms(int $post_id, int $wordset_id, int $category_id): void {
    foreach ([
        'wordset' => [$wordset_id],
        'word-category' => [$category_id],
    ] as $taxonomy => $term_ids) {
        $result = wp_set_object_terms($post_id, $term_ids, $taxonomy);
        if (is_wp_error($result)) {
            ll_tools_prompt_card_upload_fixture_fail(sprintf(
                'Unable to assign %s terms to prompt card %d: %s',
                $taxonomy,
                $post_id,
                $result->get_error_message()
            ));
        }
    }
}

function ll_tools_prompt_card_upload_fixture_relative_url(int $post_id): string {
    $url = get_permalink($post_id);
    if (!is_string($url) || $url === '') {
        return '';
    }

    return function_exists('wp_make_link_relative') ? wp_make_link_relative($url) : $url;
}

function ll_tools_prompt_card_upload_fixture_ensure_prompt_recording_type(): void {
    if (!taxonomy_exists('recording_type')) {
        return;
    }
    if (get_term_by('slug', 'prompt', 'recording_type') instanceof WP_Term) {
        return;
    }

    wp_insert_term('Prompt', 'recording_type', ['slug' => 'prompt']);
}

function ll_tools_prompt_card_upload_fixture_ensure_recorder_user(string $login, string $password, string $fixture_version): int {
    if (function_exists('ll_tools_register_or_refresh_audio_recorder_role')) {
        ll_tools_register_or_refresh_audio_recorder_role();
    }

    $user = get_user_by('login', $login);
    if ($user instanceof WP_User) {
        $user_id = (int) $user->ID;
        wp_set_password($password, $user_id);
        $user = new WP_User($user_id);
        $user->set_role('audio_recorder');
    } else {
        $user_id = wp_insert_user([
            'user_login' => $login,
            'user_pass' => $password,
            'user_email' => $login . '@example.test',
            'display_name' => 'E2E Prompt Upload Recorder',
            'role' => 'audio_recorder',
        ]);
        if (is_wp_error($user_id) || (int) $user_id <= 0) {
            $message = is_wp_error($user_id) ? $user_id->get_error_message() : 'unknown error';
            ll_tools_prompt_card_upload_fixture_fail(sprintf('Unable to create recorder user %s: %s', $login, $message));
        }
        $user_id = (int) $user_id;
    }

    update_user_meta($user_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_META_KEY, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_KEY);
    update_user_meta($user_id, LL_TOOLS_PROMPT_CARD_UPLOAD_FIXTURE_VERSION_META_KEY, $fixture_version);

    return $user_id;
}

function ll_tools_prompt_card_upload_fixture_inspect_prompt_card(int $prompt_card_id): array {
    $attachment_id = defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY')
        ? (int) get_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, true)
        : 0;
    $attachment = $attachment_id > 0 ? get_post($attachment_id) : null;

    return [
        'ok' => true,
        'promptCardId' => $prompt_card_id,
        'attachmentId' => $attachment_id,
        'attachmentMime' => $attachment instanceof WP_Post ? (string) $attachment->post_mime_type : '',
        'attachmentParent' => $attachment instanceof WP_Post ? (int) $attachment->post_parent : 0,
        'attachmentAuthor' => $attachment instanceof WP_Post ? (int) $attachment->post_author : 0,
        'recordedBy' => defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY')
            ? (int) get_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY, true)
            : 0,
        'recordedAt' => defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_AT_META_KEY')
            ? (string) get_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_AT_META_KEY, true)
            : '',
        'uploadSha1' => defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_UPLOAD_SHA1_META_KEY')
            ? (string) get_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_UPLOAD_SHA1_META_KEY, true)
            : '',
        'promptAudioUrl' => function_exists('ll_tools_get_prompt_card_prompt_audio_url')
            ? ll_tools_get_prompt_card_prompt_audio_url($prompt_card_id)
            : '',
        'needsPromptAudio' => function_exists('ll_tools_prompt_card_needs_prompt_audio')
            ? ll_tools_prompt_card_needs_prompt_audio($prompt_card_id)
            : null,
    ];
}

function ll_tools_prompt_card_upload_fixture_run(): array {
    $fixture_version = '2026-06-10.1';
    $recorder_login = 'll_e2e_prompt_upload_recorder';
    $recorder_password = 'll-e2e-prompt-upload-pass-20260610!';
    $wordset_name = 'E2E Prompt Upload Set';
    $wordset_slug = 'll-e2e-prompt-upload-set';
    $category_name = 'E2E Prompt Upload Category';
    $category_slug = 'll-e2e-prompt-upload-category';
    $other_wordset_name = 'E2E Prompt Upload Other Set';
    $other_wordset_slug = 'll-e2e-prompt-upload-other-set';
    $other_category_name = 'E2E Prompt Upload Other Category';
    $other_category_slug = 'll-e2e-prompt-upload-other-category';
    $prompt_title = 'E2E Prompt Upload Card';
    $prompt_slug = 'll-e2e-prompt-upload-card';
    $prompt_text = 'Read the E2E upload prompt exactly.';
    $other_prompt_title = 'E2E Prompt Upload Other Card';
    $other_prompt_slug = 'll-e2e-prompt-upload-other-card';
    $other_prompt_text = 'This inaccessible prompt should not accept this recorder upload.';
    $recorder_page_title = 'E2E Prompt Upload Recorder';
    $recorder_page_slug = 'll-e2e-prompt-upload-recorder';

    foreach ([
        [$prompt_slug, 'll_prompt_card'],
        [$other_prompt_slug, 'll_prompt_card'],
        [$recorder_page_slug, 'page'],
    ] as $post_slug) {
        ll_tools_prompt_card_upload_fixture_assert_post_slug_available($post_slug[0], $post_slug[1]);
    }
    foreach ([
        [$wordset_slug, 'wordset'],
        [$category_slug, 'word-category'],
        [$other_wordset_slug, 'wordset'],
        [$other_category_slug, 'word-category'],
    ] as $term_slug) {
        ll_tools_prompt_card_upload_fixture_assert_term_slug_available($term_slug[0], $term_slug[1]);
    }
    ll_tools_prompt_card_upload_fixture_assert_user_available($recorder_login);

    $deleted_posts = ll_tools_prompt_card_upload_fixture_delete_posts();
    $deleted_terms = ll_tools_prompt_card_upload_fixture_delete_terms();
    ll_tools_prompt_card_upload_fixture_ensure_prompt_recording_type();

    $wordset_id = ll_tools_prompt_card_upload_fixture_insert_term('wordset', $wordset_name, $wordset_slug, $fixture_version);
    update_term_meta($wordset_id, 'll_language', 'English');
    if (defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY')) {
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'target');
    } else {
        update_term_meta($wordset_id, 'll_wordset_word_title_language_role', 'target');
    }

    $category_id = ll_tools_prompt_card_upload_fixture_insert_term('word-category', $category_name, $category_slug, $fixture_version);
    update_term_meta($category_id, 'll_category_visibility', 'public');
    if (function_exists('ll_tools_set_category_wordset_owner')) {
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
    }

    $other_wordset_id = ll_tools_prompt_card_upload_fixture_insert_term('wordset', $other_wordset_name, $other_wordset_slug, $fixture_version);
    update_term_meta($other_wordset_id, 'll_language', 'English');
    $other_category_id = ll_tools_prompt_card_upload_fixture_insert_term('word-category', $other_category_name, $other_category_slug, $fixture_version);
    update_term_meta($other_category_id, 'll_category_visibility', 'public');
    if (function_exists('ll_tools_set_category_wordset_owner')) {
        ll_tools_set_category_wordset_owner($other_category_id, $other_wordset_id, $other_category_id);
    }

    $recorder_user_id = ll_tools_prompt_card_upload_fixture_ensure_recorder_user($recorder_login, $recorder_password, $fixture_version);
    update_user_meta($recorder_user_id, 'll_recording_config', [
        'wordset' => $wordset_slug,
        'include_recording_types' => 'prompt',
        'exclude_recording_types' => '',
        'allow_new_words' => '0',
        'auto_process_recordings' => '0',
    ]);

    $prompt_card_id = ll_tools_prompt_card_upload_fixture_insert_post([
        'post_type' => 'll_prompt_card',
        'post_status' => 'publish',
        'post_title' => $prompt_title,
        'post_name' => $prompt_slug,
        'menu_order' => 10,
    ], $fixture_version);
    update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, $prompt_text);
    ll_tools_prompt_card_upload_fixture_assign_terms($prompt_card_id, $wordset_id, $category_id);

    $other_prompt_card_id = ll_tools_prompt_card_upload_fixture_insert_post([
        'post_type' => 'll_prompt_card',
        'post_status' => 'publish',
        'post_title' => $other_prompt_title,
        'post_name' => $other_prompt_slug,
        'menu_order' => 20,
    ], $fixture_version);
    update_post_meta($other_prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, $other_prompt_text);
    ll_tools_prompt_card_upload_fixture_assign_terms($other_prompt_card_id, $other_wordset_id, $other_category_id);

    $recorder_page_id = ll_tools_prompt_card_upload_fixture_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => $recorder_page_title,
        'post_name' => $recorder_page_slug,
        'post_content' => sprintf(
            '[audio_recording_interface wordset="%s" category="%s" include_recording_types="prompt"]',
            esc_attr($wordset_slug),
            esc_attr($category_slug)
        ),
    ], $fixture_version);
    update_user_meta($recorder_user_id, 'll_recording_page_id', $recorder_page_id);

    clean_term_cache([$wordset_id, $other_wordset_id], 'wordset');
    clean_term_cache([$category_id, $other_category_id], 'word-category');
    clean_post_cache($prompt_card_id);
    clean_post_cache($other_prompt_card_id);
    clean_post_cache($recorder_page_id);
    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id, $other_category_id]);
    }
    flush_rewrite_rules(false);

    return [
        'ok' => true,
        'fixtureVersion' => $fixture_version,
        'deletedPosts' => $deleted_posts,
        'deletedTerms' => $deleted_terms,
        'recorderUserId' => $recorder_user_id,
        'recorderUsername' => $recorder_login,
        'recorderPassword' => $recorder_password,
        'recorderPageId' => $recorder_page_id,
        'recorderPagePath' => ll_tools_prompt_card_upload_fixture_relative_url($recorder_page_id),
        'wordsetId' => $wordset_id,
        'wordsetSlug' => $wordset_slug,
        'categoryId' => $category_id,
        'categorySlug' => $category_slug,
        'categoryName' => $category_name,
        'promptCardId' => $prompt_card_id,
        'promptTitle' => $prompt_title,
        'promptText' => $prompt_text,
        'otherWordsetId' => $other_wordset_id,
        'otherCategoryId' => $other_category_id,
        'otherCategorySlug' => $other_category_slug,
        'otherPromptCardId' => $other_prompt_card_id,
        'otherPromptText' => $other_prompt_text,
    ];
}

$fixture_args = isset($args) && is_array($args) ? $args : [];
$fixture_action = isset($fixture_args[0]) ? (string) $fixture_args[0] : 'seed';

if ($fixture_action === 'inspect') {
    $prompt_card_id = isset($fixture_args[1]) ? absint($fixture_args[1]) : 0;
    if ($prompt_card_id <= 0) {
        ll_tools_prompt_card_upload_fixture_fail('A prompt card ID is required for inspect.');
    }

    echo wp_json_encode(ll_tools_prompt_card_upload_fixture_inspect_prompt_card($prompt_card_id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    return;
}

$summary = ll_tools_prompt_card_upload_fixture_run();
echo wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
