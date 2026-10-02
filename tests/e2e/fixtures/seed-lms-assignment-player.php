<?php
/** Local-only, exact-owned WordPress fixture for the real assignment player. */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) { exit(1); }
$host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
if (!in_array($host, ['127.0.0.1', 'localhost'], true) && substr($host, -6) !== '.local') {
    WP_CLI::error('This fixture may run only on localhost or a .local WordPress test site.');
}
const LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION = 'll_tools_e2e_assignment_player';

function ll_assignment_player_e2e_cleanup(): array {
    global $wpdb;
    $fixture = get_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, []);
    if (!is_array($fixture) || $fixture === []) { return ['cleaned' => true]; }
    $tables = ll_tools_lms_assignment_table_names();
    $assignment_id = (int) ($fixture['assignment_id'] ?? 0);
    if ($assignment_id > 0) {
        $attempt_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['attempts']} WHERE assignment_id=%d", $assignment_id));
        foreach ((array) $attempt_ids as $id) { $wpdb->delete($tables['answers'], ['attempt_id' => (int) $id], ['%d']); }
        foreach (['grades', 'attempts', 'revisions'] as $key) { $wpdb->delete($tables[$key], ['assignment_id' => $assignment_id], ['%d']); }
        $wpdb->delete($tables['assignments'], ['id' => $assignment_id], ['%d']);
    }
    foreach ((array) ($fixture['users'] ?? []) as $user_id) {
        if (!get_userdata((int) $user_id)) { continue; }
        if (get_user_meta((int) $user_id, '_ll_assignment_player_e2e', true) !== '1') { WP_CLI::error('Refusing to delete an unrelated fixture user.'); }
        $progress = ll_tools_user_progress_table_names();
        $wpdb->delete($progress['events'], ['user_id' => (int) $user_id], ['%d']);
        $wpdb->delete($progress['words'], ['user_id' => (int) $user_id], ['%d']);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $user_id);
    }
    foreach ((array) ($fixture['posts'] ?? []) as $id) {
        if (!get_post((int) $id)) { continue; }
        if (get_post_meta((int) $id, '_ll_assignment_player_e2e', true) !== '1') { WP_CLI::error('Refusing to delete an unrelated fixture post.'); }
        wp_delete_post((int) $id, true);
    }
    foreach ((array) ($fixture['terms'] ?? []) as $entry) {
        if (!get_term((int) $entry['id'], (string) $entry['taxonomy'])) { continue; }
        if (get_term_meta((int) $entry['id'], '_ll_assignment_player_e2e', true) !== '1') { WP_CLI::error('Refusing to delete an unrelated fixture term.'); }
        wp_delete_term((int) $entry['id'], (string) $entry['taxonomy']);
    }
    delete_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION);
    return ['cleaned' => true];
}

function ll_assignment_player_e2e_seed(): array {
    ll_assignment_player_e2e_cleanup();
    ll_tools_register_or_refresh_teacher_role();
    ll_tools_register_or_refresh_learner_role();
    ll_tools_install_lms_assignment_schema();
    ll_tools_install_grade_delivery_schema();
    ll_tools_install_user_progress_schema();
    $suffix = strtolower(wp_generate_password(8, false, false));
    $password = wp_generate_password(32, true, true);
    $teacher = wp_insert_user(['user_login' => 'lms-player-teacher-' . $suffix, 'user_pass' => wp_generate_password(32, true, true), 'user_email' => 'lms-teacher-' . $suffix . '@example.invalid', 'role' => 'll_tools_teacher']);
    $login = 'lms-player-learner-' . $suffix;
    $learner = wp_insert_user(['user_login' => $login, 'user_pass' => $password, 'user_email' => 'lms-learner-' . $suffix . '@example.invalid', 'role' => 'll_tools_learner']);
    if (is_wp_error($teacher) || is_wp_error($learner)) { WP_CLI::error('Could not create assignment test users.'); }
    foreach ([$teacher, $learner] as $id) { update_user_meta((int) $id, '_ll_assignment_player_e2e', '1'); }
    $owned = ['users' => [(int) $teacher, (int) $learner], 'learner' => (int) $learner, 'posts' => [], 'terms' => []];
    update_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, $owned, false);
    $wordset = wp_insert_term('Player Hebrew ' . $suffix, 'wordset');
    $category = wp_insert_term('Player vocabulary ' . $suffix, 'word-category');
    if (is_wp_error($wordset) || is_wp_error($category)) { WP_CLI::error('Could not create assignment test terms.'); }
    foreach ([[$wordset['term_id'], 'wordset'], [$category['term_id'], 'word-category']] as $term) { update_term_meta((int) $term[0], '_ll_assignment_player_e2e', '1'); }
    $owned['terms'] = [['id' => (int) $wordset['term_id'], 'taxonomy' => 'wordset'], ['id' => (int) $category['term_id'], 'taxonomy' => 'word-category']];
    update_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, $owned, false);
    ll_tools_set_category_wordset_owner((int) $category['term_id'], (int) $wordset['term_id']);
    update_term_meta((int) $category['term_id'], 'll_quiz_prompt_type', 'text_title');
    update_term_meta((int) $category['term_id'], 'll_quiz_option_type', 'text_translation');
    $posts = [];
    for ($i = 1; $i <= 5; $i++) {
        $id = wp_insert_post(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Hebrew prompt ' . $i], true);
        if (is_wp_error($id)) { WP_CLI::error('Could not create fixture vocabulary.'); }
        $posts[] = (int) $id;
        update_post_meta((int) $id, '_ll_assignment_player_e2e', '1');
        $owned['posts'] = $posts;
        update_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, $owned, false);
        update_post_meta((int) $id, 'translation', 'Meaning ' . $i);
        update_post_meta((int) $id, 'word_translation', 'Meaning ' . $i);
        wp_set_post_terms((int) $id, [(int) $wordset['term_id']], 'wordset');
        wp_set_post_terms((int) $id, [(int) $category['term_id']], 'word-category');
    }
    $class = ll_tools_teacher_class_create((int) $teacher, 'Player class ' . $suffix, (int) $wordset['term_id']);
    if (is_wp_error($class)) { WP_CLI::error($class->get_error_message()); }
    update_post_meta((int) $class, '_ll_assignment_player_e2e', '1');
    $posts[] = (int) $class;
    $owned['posts'] = $posts;
    update_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, $owned, false);
    ll_tools_teacher_class_add_student((int) $class, (int) $learner);
    wp_set_current_user((int) $teacher);
    $assignment = ll_tools_lms_assignment_create_from_category((int) $class, (int) $category['term_id'], ['title' => 'Player Hebrew assignment', 'attempt_limit' => 2, 'grade_policy' => 'best', 'points_maximum' => 10]);
    if (is_wp_error($assignment) || !is_array($assignment)) { WP_CLI::error(is_wp_error($assignment) ? $assignment->get_error_message() : 'Assignment creation failed.'); }
    $owned['assignment_id'] = (int) $assignment['id'];
    update_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, $owned, false);
    if (ll_tools_lms_assignment_publish((int) $assignment['id'], (int) $teacher) !== true) { WP_CLI::error('Could not publish test assignment.'); }
    update_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, ['assignment_id' => (int) $assignment['id'], 'learner' => (int) $learner, 'users' => [(int) $teacher, (int) $learner], 'posts' => $posts, 'terms' => [['id' => (int) $wordset['term_id'], 'taxonomy' => 'wordset'], ['id' => (int) $category['term_id'], 'taxonomy' => 'word-category']]], false);
    return ['login' => $login, 'password' => $password, 'url' => ll_tools_lms_assignment_player_url($assignment['assignment_uuid']), 'assignment_uuid' => $assignment['assignment_uuid']];
}

function ll_assignment_player_e2e_snapshot(): array {
    global $wpdb;
    $fixture = get_option(LL_TOOLS_ASSIGNMENT_PLAYER_E2E_OPTION, []);
    if (!is_array($fixture) || empty($fixture['assignment_id'])) { WP_CLI::error('Fixture is missing.'); }
    $assignment = ll_tools_lms_assignment_get((int) $fixture['assignment_id']);
    $tables = ll_tools_lms_assignment_table_names();
    $progress = ll_tools_user_progress_table_names();
    $learner = (int) $fixture['learner'];
    return ['attempts' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['attempts']} WHERE assignment_id=%d AND user_id=%d", (int) $assignment['id'], $learner)), 'ledger' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$progress['events']} WHERE user_id=%d AND event_uuid LIKE 'lms-%%'", $learner)), 'coverage' => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(total_coverage) FROM {$progress['words']} WHERE user_id=%d", $learner)), 'grade' => ll_tools_lms_assignment_public_grade(ll_tools_lms_assignment_get_grade((int) $assignment['id'], (int) $assignment['current_revision_id'], $learner))];
}
$command = $args[0] ?? 'seed';
if ($command === 'cleanup') { echo wp_json_encode(ll_assignment_player_e2e_cleanup()); }
elseif ($command === 'snapshot') { echo wp_json_encode(ll_assignment_player_e2e_snapshot()); }
elseif ($command === 'seed') { echo wp_json_encode(ll_assignment_player_e2e_seed()); }
else { WP_CLI::error('Unknown fixture command.'); }
echo "\n";
