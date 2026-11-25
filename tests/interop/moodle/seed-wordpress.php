<?php
require '/var/www/wordboat/wp-load.php';
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);
if (is_file('/evidence/wordpress-state.json')) {
    $prior = json_decode(file_get_contents('/evidence/wordpress-state.json'), true);
    if (get_userdata($prior['learner_id'] ?? 0) && term_exists($prior['category_id'] ?? 0, 'word-category')
        && ll_tools_lms_assignment_get($prior['assignment_id'] ?? 0)) { echo "WordPress fixture already seeded.\n"; exit; }
    unlink('/evidence/wordpress-state.json');
}
$moodle = json_decode(file_get_contents('/evidence/moodle-state.json'), true);
$credentials = json_decode(file_get_contents('/private/credentials.json'), true);
$admin = get_user_by('login', 'interop_admin');
wp_set_current_user($admin->ID);
foreach (['teacher' => 'll_tools_teacher', 'student' => 'll_tools_learner'] as $kind => $role) {
    $id = wp_insert_user(['user_login' => 'interop_' . $kind, 'user_pass' => $credentials['wordpress_' . $kind], 'user_email' => 'wp' . $kind . '@example.test', 'role' => $role]);
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_code()); }
    $users[$kind] = $id;
}
ll_tools_install_lms_assignment_schema();
ll_tools_install_grade_delivery_schema();
$parsed = ll_tools_lti_parse_moodle_platform_details(file_get_contents('/evidence/moodle-platform-details.json'), ['id' => 'real-moodle', 'name' => 'Real disposable Moodle', 'enabled' => true]);
if (is_wp_error($parsed)) { throw new RuntimeException($parsed->get_error_code()); }
$platform = ll_tools_lti_register_platform($parsed);
if (is_wp_error($platform)) { throw new RuntimeException($platform->get_error_code()); }
$wordset = wp_insert_term('Interop Biblical Hebrew', 'wordset', ['slug' => 'interop-hebrew']);
$category = wp_insert_term('Interop Hebrew lesson', 'word-category', ['slug' => 'interop-hebrew-lesson']);
if (is_wp_error($wordset) || is_wp_error($category)) { throw new RuntimeException('Could not seed categories'); }
$wordset = (int) $wordset['term_id'];
$category = (int) $category['term_id'];
ll_tools_set_category_wordset_owner($category, $wordset);
update_term_meta($category, 'll_quiz_prompt_type', 'text_title');
update_term_meta($category, 'll_quiz_option_type', 'text_translation');
$words = [];
add_filter('ll_tools_skip_audio_requirement', '__return_true');
for ($i = 1; $i <= 5; $i++) {
    $word = wp_insert_post(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Hebrew prompt ' . $i]);
    wp_set_post_terms($word, [$wordset], 'wordset');
    wp_set_post_terms($word, [$category], 'word-category');
    update_post_meta($word, 'translation', 'Meaning ' . $i);
    update_post_meta($word, 'word_translation', 'Meaning ' . $i);
    $words[] = $word;
}
$class = ll_tools_teacher_class_create($users['teacher'], 'Real Moodle Hebrew class', $wordset);
if (is_wp_error($class)) { throw new RuntimeException($class->get_error_code()); }
wp_set_current_user($users['teacher']);
$assignment = ll_tools_lms_assignment_create_from_category($class, $category, ['title' => 'Real Moodle vocabulary assignment', 'points_maximum' => 100, 'attempt_limit' => 2, 'grade_policy' => 'best']);
if (is_wp_error($assignment)) { throw new RuntimeException($assignment->get_error_code()); }
$published = ll_tools_lms_assignment_publish((int) $assignment['id']);
if (is_wp_error($published)) { throw new RuntimeException($published->get_error_code()); }
$resources = [];
foreach (['practice', 'assignment'] as $kind) {
    $resource = ll_tools_lti_register_resource(['platform_id' => 'real-moodle', 'context_id' => (string) $moodle['course_id'], 'resource_link_id' => (string) $moodle['activities'][$kind]['instance_id'], 'class_id' => $class, 'category_id' => $category, 'kind' => $kind, 'assignment_uuid' => $kind === 'assignment' ? $assignment['assignment_uuid'] : '', 'name' => 'Real Moodle ' . $kind]);
    if (is_wp_error($resource)) { throw new RuntimeException($resource->get_error_code()); }
    $resources[$kind] = $resource['id'];
}
$state = ['teacher_id' => $users['teacher'], 'learner_id' => $users['student'], 'class_id' => $class, 'category_id' => $category, 'wordset_id' => $wordset, 'word_ids' => $words, 'assignment_id' => (int) $assignment['id'], 'assignment_uuid' => $assignment['assignment_uuid'], 'resources' => $resources];
file_put_contents('/evidence/wordpress-state.json', json_encode($state, JSON_PRETTY_PRINT));
echo "WordPress teacher, learner, vocabulary, fixed assignment and manual mappings seeded.\n";
