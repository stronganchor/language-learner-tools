<?php
/** Local WP-CLI fixture only; never used by public LTI routes. */
if (!defined('WP_CLI') || !WP_CLI) { exit; }
$site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
if (!in_array($site_host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) && !str_ends_with($site_host, '.local')) {
    WP_CLI::error('LTI browser fixtures may run only against a local WordPress sandbox.');
}
$mode = $args[0] ?? '';
$suffix = sanitize_key($args[1] ?? '');
if (!preg_match('/^[a-z0-9-]{5,40}$/D', $suffix)) { WP_CLI::error('Invalid fixture identifier.'); }
$option = 'll_tools_e2e_lti_' . $suffix;
$fixture = get_option($option, []);
if ($mode === 'seed') {
    if ($fixture !== []) { WP_CLI::error('Fixture already exists; clean it before reseeding.'); }
    $password = 'LocalLti!' . wp_generate_password(18, false, false);
    $fixture['words'] = [];
    $create_user = static function (string $key, string $role) use ($suffix, $password, $option, &$fixture) {
        $id = wp_insert_user(['user_login' => 'lti_' . $key . '_' . $suffix, 'user_email' => 'lti_' . $key . '_' . $suffix . '@example.test', 'user_pass' => $password, 'role' => $role]);
        if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
        $fixture[$key] = (int) $id; update_option($option, $fixture, false);
        return (int) $id;
    };
    $admin = $create_user('admin', 'administrator');
    $teacher = $create_user('teacher', 'll_tools_teacher');
    $learner = $create_user('learner', 'll_tools_learner');
    $other = $create_user('other', 'll_tools_learner');
    wp_set_current_user($admin);
    $platform_id = 'e2e-' . $suffix;
    $platform = ll_tools_lti_register_platform([
        'id' => $platform_id, 'name' => 'E2E Moodle', 'issuer' => 'https://moodle-' . $suffix . '.example.org',
        'client_id' => 'e2e-client', 'deployment_id' => 'e2e-deployment',
        'authorization_url' => 'https://moodle-' . $suffix . '.example.org/auth',
        'jwks_url' => 'https://moodle-' . $suffix . '.example.org/certs',
        'token_url' => 'https://moodle-' . $suffix . '.example.org/token', 'enabled' => true,
    ]);
    if (is_wp_error($platform)) { WP_CLI::error($platform->get_error_message()); }
    $fixture['platform_id'] = $platform_id; update_option($option, $fixture, false);
    $ws = wp_insert_term('LTI E2E ' . $suffix, 'wordset', ['slug' => 'lti-e2e-' . $suffix]);
    if (is_wp_error($ws)) { WP_CLI::error('Could not create fixture wordset.'); }
    $fixture['wordset'] = (int) $ws['term_id']; update_option($option, $fixture, false);
    $cat = wp_insert_term('LTI E2E category ' . $suffix, 'word-category', ['slug' => 'lti-e2e-category-' . $suffix]);
    if (is_wp_error($cat)) { WP_CLI::error('Could not create fixture category.'); }
    $fixture['category'] = (int) $cat['term_id']; update_option($option, $fixture, false);
    $wordset = (int) $ws['term_id']; $category = (int) $cat['term_id'];
    ll_tools_set_category_wordset_owner($category, $wordset);
    update_term_meta($category, 'll_quiz_prompt_type', 'text_title');
    update_term_meta($category, 'll_quiz_option_type', 'text_translation');
    $words = [];
    add_filter('ll_tools_skip_audio_requirement', '__return_true');
    for ($i = 1; $i <= 5; $i++) {
        $id = wp_insert_post(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Fixture word ' . $i], true);
        if (is_wp_error($id)) { WP_CLI::error($id->get_error_message()); }
        $fixture['words'][] = (int) $id; update_option($option, $fixture, false);
        wp_set_post_terms($id, [$wordset], 'wordset'); wp_set_post_terms($id, [$category], 'word-category');
        update_post_meta($id, 'translation', 'Fixture meaning ' . $i); update_post_meta($id, 'word_translation', 'Fixture meaning ' . $i);
        $words[] = $id;
    }
    remove_filter('ll_tools_skip_audio_requirement', '__return_true');
    $class = ll_tools_teacher_class_create($teacher, 'LTI E2E class ' . $suffix, $wordset);
    if (is_wp_error($class)) { WP_CLI::error($class->get_error_message()); }
    $fixture['class'] = (int) $class; update_option($option, $fixture, false);
    wp_set_current_user($teacher);
    $resource = ll_tools_lti_register_resource(['platform_id' => $platform_id, 'context_id' => 'e2e-course', 'class_id' => $class, 'category_id' => $category, 'kind' => 'practice', 'name' => 'E2E Practice']);
    if (is_wp_error($resource)) { WP_CLI::error($resource->get_error_code()); }
    $fixture['resource'] = $resource; update_option($option, $fixture, false);
    $context = ['platform_id' => $platform_id, 'issuer' => $platform['issuer'], 'client_id' => $platform['client_id'], 'deployment_id' => $platform['deployment_id'], 'subject' => 'e2e-student', 'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'], 'context_id' => 'e2e-course', 'resource_link_id' => 'e2e-resource', 'custom' => ['ll_activity' => $resource['id']], 'ags' => [], 'target_uri' => ll_tools_lti_tool_urls()['resource'], 'return_url' => ''];
    $fixture = compact('admin', 'teacher', 'learner', 'other', 'wordset', 'category', 'class', 'words', 'platform_id', 'context', 'resource');
    update_option($option, $fixture, false);
    WP_CLI::line(wp_json_encode(['username' => get_userdata($learner)->user_login, 'otherUsername' => get_userdata($other)->user_login, 'adminUsername' => get_userdata($admin)->user_login, 'password' => $password, 'learnerId' => $learner, 'suffix' => $suffix]));
} elseif ($mode === 'ticket') {
    wp_set_current_user(0);
    $binding = $args[2] ?? '';
    $type = $args[3] ?? 'account';
    $context = $fixture['context'];
    if ($type === 'launch') {
        $context['_browser_binding'] = ll_tools_lti_browser_hash($binding);
        $ticket = ll_tools_lti_ticket_put('launch', $context);
        $url = add_query_arg('ll_lti_launch', $ticket, home_url('/'));
    } else {
        $ticket = ll_tools_lti_prepare_account($context, $binding);
        $url = is_string($ticket) ? add_query_arg('ll_lti_continue', $ticket, home_url('/')) : '';
    }
    if (is_wp_error($ticket)) { WP_CLI::error($ticket->get_error_code()); }
    WP_CLI::line(wp_json_encode(['url' => $url]));
} elseif ($mode === 'state') {
    $identity = ll_tools_lti_find_account_identity($fixture['context']);
    WP_CLI::line(wp_json_encode(['learner_id' => is_array($identity) ? (int) $identity['learner_user_id'] : 0, 'linked' => is_array($identity) && $identity['status'] === 'active', 'member' => ll_tools_teacher_class_user_is_student($fixture['class'], $fixture['learner'])]));
} elseif ($mode === 'cleanup') {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($fixture['words'] ?? [] as $id) { wp_delete_post((int) $id, true); }
    foreach (['admin', 'teacher', 'learner', 'other'] as $key) { if (!empty($fixture[$key])) { wp_delete_user((int) $fixture[$key]); } }
    if (!empty($fixture['class'])) { wp_delete_post((int) $fixture['class'], true); }
    if (!empty($fixture['category'])) { wp_delete_term((int) $fixture['category'], 'word-category'); }
    if (!empty($fixture['wordset'])) { wp_delete_term((int) $fixture['wordset'], 'wordset'); }
    $platforms = get_option(LL_TOOLS_LTI_PLATFORMS_OPTION, []); if (!empty($fixture['platform_id'])) { unset($platforms[$fixture['platform_id']]); } update_option(LL_TOOLS_LTI_PLATFORMS_OPTION, $platforms, false);
    $resources = ll_tools_lti_get_resources(); if (!empty($fixture['resource']['id'])) { unset($resources[$fixture['resource']['id']]); } update_option(LL_TOOLS_LTI_RESOURCES_OPTION, $resources, false);
    delete_option($option);
    WP_CLI::line(wp_json_encode(['cleaned' => true]));
} else { WP_CLI::error('Unknown fixture mode.'); }
