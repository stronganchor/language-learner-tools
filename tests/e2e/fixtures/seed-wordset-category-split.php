<?php
/** Disposable fixture for real category-split form submissions and readback. */
if (!defined('ABSPATH')) { exit(1); }
const LL_CATEGORY_SPLIT_FIXTURE_META = '_ll_tools_e2e_category_split';

function ll_category_split_fixture_key(string $run_id): string {
    if (!preg_match('/^[a-f0-9-]{36}$/D', $run_id)) { WP_CLI::error('A valid fixture run ID is required.'); }
    return 'll_tools_e2e_category_split_' . str_replace('-', '', $run_id);
}

function ll_category_split_fixture_state(string $run_id): array {
    $state = get_option(ll_category_split_fixture_key($run_id), []);
    if (!is_array($state) || ($state && ($state['runId'] ?? '') !== $run_id)) {
        WP_CLI::error('Fixture state does not match this run; refusing to guess ownership.');
    }
    return $state;
}

function ll_category_split_fixture_save(array $state): void {
    update_option(ll_category_split_fixture_key($state['runId']), $state, false);
    if (ll_category_split_fixture_state($state['runId']) !== $state) { WP_CLI::error('Fixture state was not saved.'); }
}

function ll_category_split_fixture_term(array &$state, string $taxonomy, string $name): int {
    $term = wp_insert_term($name . ' ' . $state['marker'], $taxonomy, ['slug' => 'll-split-' . sanitize_title($name) . '-' . $state['marker']]);
    if (is_wp_error($term)) { WP_CLI::error($term->get_error_message()); }
    $id = (int) $term['term_id'];
    update_term_meta($id, LL_CATEGORY_SPLIT_FIXTURE_META, $state['marker']);
    $state['terms'][] = ['id' => $id, 'taxonomy' => $taxonomy];
    ll_category_split_fixture_save($state);
    return $id;
}

function ll_category_split_fixture_word(array &$state, string $title, int $category_id, string $status, bool $with_image): int {
    $id = wp_insert_post(['post_type' => 'words', 'post_status' => 'draft', 'post_title' => $title,
        'meta_input' => [LL_CATEGORY_SPLIT_FIXTURE_META => $state['marker'], 'word_translation' => 'Meaning of ' . $title]], true);
    if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
    $id = (int) $id;
    $state['posts'][] = $id;
    ll_category_split_fixture_save($state);
    wp_set_object_terms($id, [$state['wordsetId']], 'wordset');
    wp_set_object_terms($id, [$category_id], 'word-category');
    if ($with_image) {
        $image_id = wp_insert_post(['post_type' => 'word_images', 'post_status' => 'publish', 'post_title' => $title . ' image',
            'meta_input' => [LL_CATEGORY_SPLIT_FIXTURE_META => $state['marker']]], true);
        if (is_wp_error($image_id)) { WP_CLI::error($image_id->get_error_message()); }
        $state['posts'][] = (int) $image_id;
        $state['imageIds'][(string) $id] = (int) $image_id;
        ll_category_split_fixture_save($state);
        wp_set_object_terms($image_id, [$category_id], 'word-category');
        ll_tools_set_word_image_wordset_owner((int) $image_id, $state['wordsetId'], (int) $image_id);
        update_post_meta($id, '_ll_autopicked_image_id', (int) $image_id);
    }
    if ($status === 'publish') { wp_update_post(['ID' => $id, 'post_status' => 'publish']); }
    if (get_post_status($id) !== $status) { WP_CLI::error('Fixture word status was not preserved.'); }
    return $id;
}

function ll_category_split_fixture_seed(string $run_id): array {
    if (ll_category_split_fixture_state($run_id)) { WP_CLI::error('This fixture run already exists.'); }
    wp_set_current_user(0);
    $marker = str_replace('-', '', $run_id);
    $state = ['runId' => $run_id, 'marker' => $marker, 'terms' => [], 'posts' => [], 'imageIds' => [],
        'selectedWordIds' => [], 'retainedWordIds' => [], 'existingWordIds' => [],
        'newCategoryName' => 'Split destination ' . $marker];
    ll_category_split_fixture_save($state);
    if (get_term_by('name', $state['newCategoryName'], 'word-category')) { WP_CLI::error('Destination name is already occupied.'); }
    $login = 'll-split-' . substr($marker, 0, 20);
    $password = wp_generate_password(28, false, false);
    $user_id = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'role' => 'wordset_manager',
        'user_email' => $login . '@example.invalid', 'display_name' => 'Category split fixture manager']);
    if (is_wp_error($user_id)) { WP_CLI::error($user_id->get_error_message()); }
    update_user_meta($user_id, LL_CATEGORY_SPLIT_FIXTURE_META, $marker);
    update_user_meta($user_id, 'locale', 'en_US');
    get_userdata($user_id)->add_cap('view_ll_tools');
    $state['manager'] = ['id' => (int) $user_id, 'login' => $login, 'password' => $password];
    ll_category_split_fixture_save($state);
    $state['wordsetId'] = ll_category_split_fixture_term($state, 'wordset', 'Split wordset');
    ll_category_split_fixture_save($state);
    ll_tools_set_wordset_manager_user_ids($state['wordsetId'], [(int) $user_id], (int) $user_id);
    update_term_meta($state['wordsetId'], 'll_language', 'English');
    foreach (['sourceId' => 'Split source', 'existingTargetId' => 'Existing destination', 'relatedId' => 'Related category'] as $key => $name) {
        $state[$key] = ll_category_split_fixture_term($state, 'word-category', $name);
        ll_category_split_fixture_save($state);
        update_term_meta($state[$key], LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $state['wordsetId']);
        update_term_meta($state[$key], 'll_quiz_prompt_type', 'text_translation');
        update_term_meta($state[$key], 'll_quiz_option_type', 'text_title');
    }
    for ($number = 1; $number <= 5; $number++) {
        $state['selectedWordIds'][] = ll_category_split_fixture_word($state, sprintf('Move word %02d %s', $number, $marker), $state['sourceId'], 'publish', true);
        ll_category_split_fixture_save($state);
    }
    $state['draftWordId'] = ll_category_split_fixture_word($state, 'Move draft ' . $marker, $state['sourceId'], 'draft', true);
    $state['selectedWordIds'][] = $state['draftWordId'];
    ll_category_split_fixture_save($state);
    for ($number = 1; $number <= 7; $number++) {
        $state['retainedWordIds'][] = ll_category_split_fixture_word($state, sprintf('Keep word %02d %s', $number, $marker), $state['sourceId'], 'publish', true);
        ll_category_split_fixture_save($state);
    }
    for ($number = 1; $number <= 5; $number++) {
        $state['existingWordIds'][] = ll_category_split_fixture_word($state, sprintf('Existing word %02d %s', $number, $marker), $state['existingTargetId'], 'publish', false);
        ll_category_split_fixture_save($state);
    }
    wp_set_object_terms($state['selectedWordIds'][0], [$state['sourceId'], $state['relatedId']], 'word-category');
    wp_set_object_terms($state['imageIds'][(string) $state['selectedWordIds'][0]], [$state['sourceId'], $state['relatedId']], 'word-category');
    ll_tools_ensure_vocab_lessons_enabled_for_wordset($state['wordsetId'], false);
    ll_tools_sync_vocab_lesson_pages([$state['wordsetId']]);
    $wordset = get_term($state['wordsetId'], 'wordset');
    $state['wordsetPath'] = wp_make_link_relative(ll_tools_get_wordset_page_view_url($wordset));
    $state['editorPath'] = wp_make_link_relative(ll_tools_get_wordset_settings_tool_url($wordset, 'editor'));
    $state['splitPath'] = wp_make_link_relative(add_query_arg(['ll_editor_category' => $state['sourceId'], 'll_editor_split_category' => '1'], ll_tools_get_wordset_settings_tool_url($wordset, 'editor')));
    ll_category_split_fixture_save($state);
    return $state;
}

function ll_category_split_fixture_inspect(string $run_id): array {
    $state = ll_category_split_fixture_state($run_id);
    if (!$state) { WP_CLI::error('This fixture run does not exist.'); }
    $target = get_term_by('name', $state['newCategoryName'], 'word-category');
    $target_id = $target instanceof WP_Term ? (int) $target->term_id : 0;
    if ($target_id > 0 && (int) get_term_meta($target_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true) !== $state['wordsetId']) {
        WP_CLI::error('Destination category is outside this fixture wordset.');
    }
    $words = [];
    foreach (array_merge($state['selectedWordIds'], $state['retainedWordIds'], $state['existingWordIds']) as $id) {
        $image_id = (int) ($state['imageIds'][(string) $id] ?? 0);
        $words[(string) $id] = ['status' => get_post_status($id),
            'categories' => array_map('intval', wp_get_post_terms($id, 'word-category', ['fields' => 'ids'])),
            'imageCategories' => $image_id ? array_map('intval', wp_get_post_terms($image_id, 'word-category', ['fields' => 'ids'])) : [],
            'wordsets' => array_map('intval', wp_get_post_terms($id, 'wordset', ['fields' => 'ids']))];
    }
    $lessons = get_posts(['post_type' => 'll_vocab_lesson', 'post_status' => 'publish', 'posts_per_page' => 20, 'no_found_rows' => true,
        'meta_key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META, 'meta_value' => (string) $state['wordsetId']]);
    $lesson_rows = array_map(static fn($post) => ['id' => (int) $post->ID, 'categoryId' => (int) get_post_meta($post->ID, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true),
        'path' => wp_make_link_relative(get_permalink($post->ID))], $lessons);
    return ['targetId' => $target_id, 'words' => $words, 'lessons' => $lesson_rows,
        'targetConfig' => $target_id ? ['prompt' => get_term_meta($target_id, 'll_quiz_prompt_type', true), 'option' => get_term_meta($target_id, 'll_quiz_option_type', true)] : null];
}

function ll_category_split_fixture_cleanup(string $run_id): array {
    global $wpdb;
    $state = ll_category_split_fixture_state($run_id);
    if (!$state) { return ['ok' => true]; }
    $wordset_id = (int) ($state['wordsetId'] ?? 0);
    $target = get_term_by('name', $state['newCategoryName'], 'word-category');
    if ($target instanceof WP_Term) {
        if ($wordset_id <= 0 || (int) get_term_meta($target->term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true) !== $wordset_id) {
            WP_CLI::error('Created destination no longer belongs to the fixture; cleanup stopped.');
        }
        $state['terms'][] = ['id' => (int) $target->term_id, 'taxonomy' => 'word-category', 'createdDestination' => true];
        ll_category_split_fixture_save($state);
    }
    $category_ids = array_map(static fn($term) => (int) $term['id'], array_filter($state['terms'], static fn($term) => $term['taxonomy'] === 'word-category'));
    if ($category_ids !== []) {
        // Normal public catalog rendering may create standalone quiz pages.
        // Their category metadata proves scope even after a prior cleanup
        // trashed them and deleted the fixture terms.
        $quiz_pages = get_posts(['post_type' => 'll_quiz_page', 'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'posts_per_page' => 21, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => LL_TOOLS_QUIZ_PAGE_CATEGORY_META, 'value' => $category_ids, 'compare' => 'IN']]]);
        if (count($quiz_pages) > 20) { WP_CLI::error('Fixture quiz-page discovery exceeded its cleanup bound.'); }
        foreach ($quiz_pages as $id) {
            if (!in_array((int) get_post_meta($id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true), $category_ids, true)) { WP_CLI::error('Fixture quiz page category changed.'); }
            wp_delete_post((int) $id, true);
            if (get_post($id)) { WP_CLI::error('Fixture quiz page could not be removed.'); }
        }
    }
    if ($wordset_id > 0) {
        $lessons = get_posts(['post_type' => 'll_vocab_lesson', 'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'posts_per_page' => 21, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META, 'meta_value' => (string) $wordset_id]);
        if (count($lessons) > 20) { WP_CLI::error('Fixture lesson discovery exceeded its cleanup bound.'); }
        foreach ($lessons as $id) {
            wp_delete_post((int) $id, true);
            if (get_post($id)) { WP_CLI::error('Fixture lesson could not be removed.'); }
        }
    }
    foreach ((array) $state['posts'] as $id) {
        if (!get_post($id)) { continue; }
        if (get_post_meta($id, LL_CATEGORY_SPLIT_FIXTURE_META, true) !== $state['marker']) { WP_CLI::error('Tracked post lost its fixture marker.'); }
        wp_delete_post((int) $id, true);
        if (get_post($id)) { WP_CLI::error('Fixture post could not be removed.'); }
    }
    foreach (array_reverse($state['terms']) as $term) {
        if (!get_term($term['id'], $term['taxonomy'])) { continue; }
        if (empty($term['createdDestination']) && get_term_meta($term['id'], LL_CATEGORY_SPLIT_FIXTURE_META, true) !== $state['marker']) {
            WP_CLI::error('Tracked term lost its fixture marker.');
        }
        $deleted = wp_delete_term((int) $term['id'], $term['taxonomy']);
        if (is_wp_error($deleted) || !$deleted) { WP_CLI::error('Fixture term could not be removed.'); }
    }
    if (!empty($state['manager']['id']) && get_userdata($state['manager']['id'])) {
        $user_id = (int) $state['manager']['id'];
        if (get_user_meta($user_id, LL_CATEGORY_SPLIT_FIXTURE_META, true) !== $state['marker']) { WP_CLI::error('Fixture user lost its marker.'); }
        $wpdb->last_error = '';
        $authored_post = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author = %d LIMIT 1", $user_id));
        if ($wpdb->last_error !== '' || $authored_post !== null) { WP_CLI::error('Fixture user unexpectedly owns posts; cleanup stopped.'); }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);
        if (get_userdata($user_id)) { WP_CLI::error('Fixture user could not be removed.'); }
    }
    // Remove only this fixture ID, retaining unrelated stored configuration.
    $wordsets = get_option('ll_vocab_lesson_wordsets', []);
    if (is_array($wordsets)) {
        $wordsets = array_values(array_filter($wordsets, static fn($id) => (int) $id !== $wordset_id));
    } elseif (is_string($wordsets)) {
        $wordsets = implode(',', array_filter(explode(',', $wordsets), static fn($id) => (int) trim($id) !== $wordset_id));
    } else { WP_CLI::error('Lesson wordset configuration changed shape; cleanup state retained.'); }
    update_option('ll_vocab_lesson_wordsets', $wordsets, false);
    $history = ll_tools_wordset_editor_get_history();
    ll_tools_wordset_editor_save_history(array_values(array_filter($history, static fn($entry) => (int) ($entry['wordset_id'] ?? 0) !== $wordset_id)));
    delete_option(ll_category_split_fixture_key($run_id));
    if (ll_category_split_fixture_state($run_id)) { WP_CLI::error('Fixture state cleanup could not be verified.'); }
    return ['ok' => true];
}

$arguments = isset($args) && is_array($args) ? $args : [];
$command = (string) ($arguments[0] ?? '');
$run_id = (string) ($arguments[1] ?? '');
if ($command === 'seed') { $result = ll_category_split_fixture_seed($run_id); }
elseif ($command === 'inspect') { $result = ll_category_split_fixture_inspect($run_id); }
elseif ($command === 'cleanup') { $result = ll_category_split_fixture_cleanup($run_id); }
else { WP_CLI::error('Unknown fixture command.'); }
echo wp_json_encode($result);
