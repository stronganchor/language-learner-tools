<?php
/**
 * WP-CLI eval-file fixture for real private-wordset route access coverage.
 *
 * Usage:
 *   wp eval-file seed-private-wordset-access.php seed
 *   wp eval-file seed-private-wordset-access.php cleanup
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

const LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY = 'll-tools-e2e-private-wordset-access';
const LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY = '_ll_tools_e2e_fixture';
const LL_TOOLS_PRIVATE_WORDSET_E2E_VERSION_META_KEY = '_ll_tools_e2e_fixture_version';

function ll_tools_private_wordset_e2e_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_private_wordset_e2e_marker_matches(int $object_id, string $kind): bool {
    if ($kind === 'user') {
        return (string) get_user_meta($object_id, LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY, true)
            === LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY;
    }
    if ($kind === 'term') {
        return (string) get_term_meta($object_id, LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY, true)
            === LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY;
    }

    return (string) get_post_meta($object_id, LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY, true)
        === LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY;
}

function ll_tools_private_wordset_e2e_tag_user(int $user_id, string $version): void {
    update_user_meta($user_id, LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY, LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY);
    update_user_meta($user_id, LL_TOOLS_PRIVATE_WORDSET_E2E_VERSION_META_KEY, $version);
}

function ll_tools_private_wordset_e2e_tag_term(int $term_id, string $version): void {
    update_term_meta($term_id, LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY, LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY);
    update_term_meta($term_id, LL_TOOLS_PRIVATE_WORDSET_E2E_VERSION_META_KEY, $version);
}

function ll_tools_private_wordset_e2e_tag_post(int $post_id, string $version): void {
    update_post_meta($post_id, LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY, LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY);
    update_post_meta($post_id, LL_TOOLS_PRIVATE_WORDSET_E2E_VERSION_META_KEY, $version);
}

function ll_tools_private_wordset_e2e_begin_bulk_mode(): array {
    $state = [
        'cache_invalidation' => null,
        'term_counting' => false,
        'category_maintenance' => false,
    ];
    if (function_exists('wp_suspend_cache_invalidation')) {
        $state['cache_invalidation'] = wp_suspend_cache_invalidation(true);
    }
    if (function_exists('wp_defer_term_counting')) {
        wp_defer_term_counting(true);
        $state['term_counting'] = true;
    }
    if (function_exists('ll_tools_begin_deferred_category_maintenance')) {
        ll_tools_begin_deferred_category_maintenance('private-wordset-e2e-fixture');
        $state['category_maintenance'] = true;
    }
    return $state;
}

function ll_tools_private_wordset_e2e_end_bulk_mode(array $state): void {
    if (!empty($state['category_maintenance'])
        && function_exists('ll_tools_end_deferred_category_maintenance')) {
        ll_tools_end_deferred_category_maintenance(false);
    }
    if (!empty($state['term_counting']) && function_exists('wp_defer_term_counting')) {
        wp_defer_term_counting(false);
    }
    if (array_key_exists('cache_invalidation', $state)
        && $state['cache_invalidation'] !== null
        && function_exists('wp_suspend_cache_invalidation')) {
        wp_suspend_cache_invalidation((bool) $state['cache_invalidation']);
    }
}

function ll_tools_private_wordset_e2e_assert_user_available(string $login): void {
    $existing = get_user_by('login', $login);
    if ($existing instanceof WP_User && !ll_tools_private_wordset_e2e_marker_matches((int) $existing->ID, 'user')) {
        ll_tools_private_wordset_e2e_fail(sprintf(
            'Refusing to replace the existing non-fixture user %s.',
            $login
        ));
    }
}

function ll_tools_private_wordset_e2e_assert_term_available(string $slug, string $taxonomy): void {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term && !is_wp_error($existing)
        && !ll_tools_private_wordset_e2e_marker_matches((int) $existing->term_id, 'term')) {
        ll_tools_private_wordset_e2e_fail(sprintf(
            'Refusing to replace the existing non-fixture %s term %s.',
            $taxonomy,
            $slug
        ));
    }
}

function ll_tools_private_wordset_e2e_assert_post_available(string $slug, string $post_type = 'words'): void {
    $existing = get_page_by_path($slug, OBJECT, $post_type);
    if ($existing instanceof WP_Post && !ll_tools_private_wordset_e2e_marker_matches((int) $existing->ID, 'post')) {
        ll_tools_private_wordset_e2e_fail(sprintf(
            'Refusing to replace the existing non-fixture %s post %s.',
            $post_type,
            $slug
        ));
    }
}

/**
 * Delete only objects carrying this fixture's exact marker.
 *
 * @return array{posts:int,terms:int,users:int}
 */
function ll_tools_private_wordset_e2e_cleanup(): array {
    $deleted = ['posts' => 0, 'terms' => 0, 'users' => 0];
    $bulk_state = ll_tools_private_wordset_e2e_begin_bulk_mode();

    $post_ids = get_posts([
        'post_type' => ['words', 'll_vocab_lesson', 'page'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY,
            'value' => LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY,
        ]],
    ]);
    foreach ((array) $post_ids as $post_id) {
        $post_id = (int) $post_id;
        if ($post_id > 0 && ll_tools_private_wordset_e2e_marker_matches($post_id, 'post')
            && wp_delete_post($post_id, true)) {
            $deleted['posts']++;
        }
    }

    $fixture_wordset_ids = [];
    foreach (['word-category', 'wordset'] as $taxonomy) {
        $term_ids = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY,
                'value' => LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY,
            ]],
        ]);
        if (is_wp_error($term_ids)) {
            continue;
        }
        foreach ((array) $term_ids as $term_id) {
            $term_id = (int) $term_id;
            if ($term_id <= 0 || !ll_tools_private_wordset_e2e_marker_matches($term_id, 'term')) {
                continue;
            }
            if ($taxonomy === 'wordset') {
                $fixture_wordset_ids[$term_id] = true;
            }
            $result = wp_delete_term($term_id, $taxonomy);
            if (!is_wp_error($result) && $result !== false) {
                $deleted['terms']++;
            }
        }
    }

    if ($fixture_wordset_ids !== []) {
        $enabled = array_values(array_filter(
            array_map('intval', (array) get_option('ll_vocab_lesson_wordsets', [])),
            static fn(int $wordset_id): bool => $wordset_id > 0 && !isset($fixture_wordset_ids[$wordset_id])
        ));
        update_option('ll_vocab_lesson_wordsets', array_values(array_unique($enabled)), false);
    }

    $user_ids = get_users([
        'fields' => 'ids',
        'meta_key' => LL_TOOLS_PRIVATE_WORDSET_E2E_META_KEY,
        'meta_value' => LL_TOOLS_PRIVATE_WORDSET_E2E_FIXTURE_KEY,
    ]);
    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }
    foreach ((array) $user_ids as $user_id) {
        $user_id = (int) $user_id;
        if ($user_id > 0 && ll_tools_private_wordset_e2e_marker_matches($user_id, 'user')
            && wp_delete_user($user_id)) {
            $deleted['users']++;
        }
    }

    ll_tools_private_wordset_e2e_end_bulk_mode($bulk_state);
    if (function_exists('ll_tools_purge_wordset_buttons_shortcode_cache')) {
        ll_tools_purge_wordset_buttons_shortcode_cache();
    }
    if (function_exists('ll_tools_reset_wordset_buttons_navigation_manifests')) {
        ll_tools_reset_wordset_buttons_navigation_manifests();
    }
    flush_rewrite_rules(false);
    return $deleted;
}

/** @return array{id:int,login:string,password:string,displayName:string} */
function ll_tools_private_wordset_e2e_create_user(
    string $login,
    string $password,
    string $display_name,
    string $version
): array {
    $user_id = wp_create_user($login, $password, $login . '@example.invalid');
    if (is_wp_error($user_id) || (int) $user_id <= 0) {
        $message = is_wp_error($user_id) ? $user_id->get_error_message() : 'unknown error';
        ll_tools_private_wordset_e2e_fail('Unable to create private-wordset fixture user: ' . $message);
    }
    $user_id = (int) $user_id;
    wp_update_user([
        'ID' => $user_id,
        'display_name' => $display_name,
        'nickname' => $display_name,
        'role' => 'wordset_manager',
    ]);
    $user = get_userdata($user_id);
    if (!($user instanceof WP_User)) {
        ll_tools_private_wordset_e2e_fail('Unable to reload private-wordset fixture user.');
    }
    $user->add_cap('view_ll_tools');
    ll_tools_private_wordset_e2e_tag_user($user_id, $version);

    return [
        'id' => $user_id,
        'login' => $login,
        'password' => $password,
        'displayName' => $display_name,
    ];
}

function ll_tools_private_wordset_e2e_insert_term(
    string $taxonomy,
    string $name,
    string $slug,
    string $version
): int {
    $result = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($result)) {
        ll_tools_private_wordset_e2e_fail(sprintf(
            'Unable to create %s fixture term %s: %s',
            $taxonomy,
            $slug,
            $result->get_error_message()
        ));
    }
    $term_id = (int) ($result['term_id'] ?? 0);
    if ($term_id <= 0) {
        ll_tools_private_wordset_e2e_fail('Fixture term creation returned no term ID.');
    }
    ll_tools_private_wordset_e2e_tag_term($term_id, $version);
    return $term_id;
}

function ll_tools_private_wordset_e2e_insert_word(
    string $title,
    string $slug,
    string $translation,
    int $wordset_id,
    array $category_ids,
    string $version
): int {
    $post_id = wp_insert_post([
        'post_type' => 'words',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '',
    ], true);
    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown error';
        ll_tools_private_wordset_e2e_fail('Unable to create private-wordset fixture word: ' . $message);
    }
    $post_id = (int) $post_id;
    $wordsets = wp_set_object_terms($post_id, [$wordset_id], 'wordset', false);
    $categories = wp_set_object_terms($post_id, array_map('intval', $category_ids), 'word-category', false);
    if (is_wp_error($wordsets) || is_wp_error($categories)) {
        $error = is_wp_error($wordsets) ? $wordsets : $categories;
        ll_tools_private_wordset_e2e_fail('Unable to assign fixture word terms: ' . $error->get_error_message());
    }
    update_post_meta($post_id, 'word_translation', $translation);
    ll_tools_private_wordset_e2e_tag_post($post_id, $version);
    return $post_id;
}

function ll_tools_private_wordset_e2e_insert_vocab_lesson(
    string $title,
    string $slug,
    int $wordset_id,
    int $category_id,
    string $version
): int {
    $post_id = wp_insert_post([
        'post_type' => 'll_vocab_lesson',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '[word_grid]',
    ], true);
    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown error';
        ll_tools_private_wordset_e2e_fail('Unable to create private-wordset fixture lesson: ' . $message);
    }
    $post_id = (int) $post_id;
    update_post_meta(
        $post_id,
        defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
            ? LL_TOOLS_VOCAB_LESSON_WORDSET_META
            : '_ll_tools_vocab_wordset_id',
        (string) $wordset_id
    );
    update_post_meta(
        $post_id,
        defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')
            ? LL_TOOLS_VOCAB_LESSON_CATEGORY_META
            : '_ll_tools_vocab_category_id',
        (string) $category_id
    );
    ll_tools_private_wordset_e2e_tag_post($post_id, $version);
    return $post_id;
}

function ll_tools_private_wordset_e2e_seed(): array {
    $version = '2026-09-01.1';
    $wordset_slug = 'll-e2e-private-wordset-access';
    $hub_slug = 'll-e2e-private-wordset-hub';
    $manager_login = 'll-e2e-private-wordset-manager';
    $outsider_login = 'll-e2e-private-wordset-outsider';
    $password = 'LL-E2E-private-wordset-2026!';
    $category_count = 19;
    $words_per_category = 5;

    ll_tools_private_wordset_e2e_assert_user_available($manager_login);
    ll_tools_private_wordset_e2e_assert_user_available($outsider_login);
    ll_tools_private_wordset_e2e_assert_term_available($wordset_slug, 'wordset');
    ll_tools_private_wordset_e2e_assert_post_available($hub_slug, 'page');
    for ($category_index = 1; $category_index <= $category_count; $category_index++) {
        $category_slug = sprintf('ll-e2e-private-category-%02d', $category_index);
        ll_tools_private_wordset_e2e_assert_term_available($category_slug, 'word-category');
        $lesson_slug = sprintf('ll-e2e-private-lesson-%02d', $category_index);
        $existing_lesson = get_page_by_path($lesson_slug, OBJECT, 'll_vocab_lesson');
        if ($existing_lesson instanceof WP_Post
            && !ll_tools_private_wordset_e2e_marker_matches((int) $existing_lesson->ID, 'post')) {
            ll_tools_private_wordset_e2e_fail(sprintf(
                'Refusing to replace the existing non-fixture vocab lesson %s.',
                $lesson_slug
            ));
        }
    }
    for ($word_index = 1; $word_index <= $words_per_category; $word_index++) {
        ll_tools_private_wordset_e2e_assert_post_available(sprintf(
            'll-e2e-private-shared-word-%02d',
            $word_index
        ));
    }

    $deleted = ll_tools_private_wordset_e2e_cleanup();
    $bulk_state = ll_tools_private_wordset_e2e_begin_bulk_mode();
    $manager = ll_tools_private_wordset_e2e_create_user(
        $manager_login,
        $password,
        'Private Wordset Manager',
        $version
    );
    $outsider = ll_tools_private_wordset_e2e_create_user(
        $outsider_login,
        $password,
        'Unassigned Wordset Manager',
        $version
    );

    $wordset_id = ll_tools_private_wordset_e2e_insert_term(
        'wordset',
        'E2E Private Wordset',
        $wordset_slug,
        $version
    );
    update_term_meta(
        $wordset_id,
        defined('LL_TOOLS_WORDSET_VISIBILITY_META_KEY')
            ? LL_TOOLS_WORDSET_VISIBILITY_META_KEY
            : 'll_wordset_visibility',
        'private'
    );
    update_term_meta($wordset_id, 'll_language', 'English');
    $assigned = function_exists('ll_tools_set_wordset_manager_user_ids')
        ? ll_tools_set_wordset_manager_user_ids($wordset_id, [(int) $manager['id']], (int) $manager['id'])
        : new WP_Error('missing_manager_helper', 'Wordset manager assignment helper is unavailable.');
    if (is_wp_error($assigned) || $assigned !== true) {
        $message = is_wp_error($assigned) ? $assigned->get_error_message() : 'unknown error';
        ll_tools_private_wordset_e2e_fail('Unable to assign the fixture wordset manager: ' . $message);
    }

    $category_ids = [];
    $post_ids = [];
    for ($category_index = 1; $category_index <= $category_count; $category_index++) {
        $category_name = sprintf('E2E Private Category %02d', $category_index);
        $category_slug = sprintf('ll-e2e-private-category-%02d', $category_index);
        $category_id = ll_tools_private_wordset_e2e_insert_term(
            'word-category',
            $category_name,
            $category_slug,
            $version
        );
        $category_ids[] = $category_id;
        update_term_meta($category_id, 'll_category_visibility', 'public');
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        update_term_meta($category_id, 'll_category_enabled_games', []);
        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        }
        $post_ids[] = ll_tools_private_wordset_e2e_insert_vocab_lesson(
            $category_name . ' Lesson',
            sprintf('ll-e2e-private-lesson-%02d', $category_index),
            $wordset_id,
            $category_id,
            $version
        );
    }

    // Five shared words keep every category above the normal quiz threshold
    // without creating 95 posts or weakening production minimums.
    for ($word_index = 1; $word_index <= $words_per_category; $word_index++) {
        $post_ids[] = ll_tools_private_wordset_e2e_insert_word(
            sprintf('Private Shared %02d', $word_index),
            sprintf('ll-e2e-private-shared-word-%02d', $word_index),
            sprintf('Shared Translation %02d', $word_index),
            $wordset_id,
            $category_ids,
            $version
        );
    }

    $hub_page_id = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'E2E Private Wordset Hub',
        'post_name' => $hub_slug,
        'post_content' => '[ll_wordset_buttons class="ll-e2e-private-wordset-hub" hide_empty="1"]',
    ], true);
    if (is_wp_error($hub_page_id) || (int) $hub_page_id <= 0) {
        $message = is_wp_error($hub_page_id) ? $hub_page_id->get_error_message() : 'unknown error';
        ll_tools_private_wordset_e2e_fail('Unable to create private-wordset fixture hub page: ' . $message);
    }
    $hub_page_id = (int) $hub_page_id;
    ll_tools_private_wordset_e2e_tag_post($hub_page_id, $version);
    $post_ids[] = $hub_page_id;

    if (function_exists('ll_tools_ensure_vocab_lessons_enabled_for_wordset')) {
        if (!ll_tools_ensure_vocab_lessons_enabled_for_wordset($wordset_id, false)) {
            ll_tools_private_wordset_e2e_fail('Unable to enable the fixture wordset route.');
        }
    } else {
        $enabled = array_values(array_unique(array_merge(
            array_map('intval', (array) get_option('ll_vocab_lesson_wordsets', [])),
            [$wordset_id]
        )));
        update_option('ll_vocab_lesson_wordsets', $enabled, false);
    }

    ll_tools_private_wordset_e2e_end_bulk_mode($bulk_state);
    clean_term_cache([$wordset_id], 'wordset');
    clean_term_cache($category_ids, 'word-category');
    foreach ($post_ids as $post_id) {
        clean_post_cache((int) $post_id);
    }
    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($category_ids);
    }
    if (function_exists('ll_tools_purge_wordset_buttons_shortcode_cache')) {
        ll_tools_purge_wordset_buttons_shortcode_cache();
    }
    if (function_exists('ll_tools_reset_wordset_buttons_navigation_manifests')) {
        ll_tools_reset_wordset_buttons_navigation_manifests();
    }
    flush_rewrite_rules(false);

    return [
        'ok' => true,
        'fixtureVersion' => $version,
        'deleted' => $deleted,
        'wordsetId' => $wordset_id,
        'wordsetName' => 'E2E Private Wordset',
        'wordsetSlug' => $wordset_slug,
        'pagePath' => wp_make_link_relative(home_url('/' . $wordset_slug . '/')),
        'hubPagePath' => wp_make_link_relative(home_url('/' . $hub_slug . '/')),
        'categoryCount' => $category_count,
        'firstCategoryName' => 'E2E Private Category 01',
        'lastCategoryName' => sprintf('E2E Private Category %02d', $category_count),
        'manager' => $manager,
        'outsider' => $outsider,
    ];
}

/**
 * Inspect a rejected lazy-card token before fixture cleanup. This command is
 * read-only and intentionally returns identifiers/signatures rather than card
 * payloads or credentials so a failure can distinguish invalidated access
 * state from a missing/mismatched token.
 */
function ll_tools_private_wordset_e2e_inspect_lazy_payload(string $token): array {
    $token = sanitize_key($token);
    $payload = $token !== '' && function_exists('ll_tools_wordset_page_get_lazy_cards_payload')
        ? ll_tools_wordset_page_get_lazy_cards_payload($token)
        : null;
    if (!is_array($payload)) {
        return [
            'tokenPresent' => $token !== '',
            'payloadPresent' => false,
        ];
    }

    $wordset_id = max(0, (int) ($payload['wordset_id'] ?? 0));
    $payload_user_id = max(0, (int) ($payload['user_id'] ?? 0));
    wp_set_current_user($payload_user_id);
    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    $stored_signature = (string) ($payload['access_signature'] ?? '');
    $current_signature = function_exists('ll_tools_wordset_page_payload_access_signature')
        ? ll_tools_wordset_page_payload_access_signature($wordset_id, $payload_user_id)
        : '';

    return [
        'tokenPresent' => true,
        'payloadPresent' => true,
        'payloadWordsetId' => $wordset_id,
        'payloadUserId' => $payload_user_id,
        'currentUserId' => get_current_user_id(),
        'wordsetExists' => $wordset instanceof WP_Term && !is_wp_error($wordset),
        'userCanView' => $wordset instanceof WP_Term
            && function_exists('ll_tools_user_can_view_wordset')
            && ll_tools_user_can_view_wordset($wordset, $payload_user_id),
        'storedSignature' => $stored_signature,
        'currentSignature' => $current_signature,
        'signatureMatches' => $stored_signature !== ''
            && $current_signature !== ''
            && hash_equals($stored_signature, $current_signature),
        'wordsetEpoch' => function_exists('ll_tools_get_wordset_cache_epoch')
            ? ll_tools_get_wordset_cache_epoch()
            : null,
        'categoryEpoch' => function_exists('ll_tools_get_category_cache_epoch')
            ? ll_tools_get_category_cache_epoch()
            : null,
        'quizContentEpoch' => function_exists('ll_tools_get_quiz_content_cache_epoch')
            ? ll_tools_get_quiz_content_cache_epoch([$wordset_id])
            : null,
    ];
}

$fixture_args = isset($args) && is_array($args) ? array_values($args) : [];
$command = sanitize_key((string) ($fixture_args[0] ?? 'seed'));
if ($command === 'cleanup') {
    $result = ['ok' => true, 'cleanup' => ll_tools_private_wordset_e2e_cleanup()];
} elseif ($command === 'seed') {
    $result = ll_tools_private_wordset_e2e_seed();
} elseif ($command === 'inspect-lazy') {
    $result = ll_tools_private_wordset_e2e_inspect_lazy_payload((string) ($fixture_args[1] ?? ''));
} else {
    ll_tools_private_wordset_e2e_fail('Unknown private-wordset fixture command.');
}

echo wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
