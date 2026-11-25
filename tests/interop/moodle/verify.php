<?php
if (($argv[1] ?? '') === 'moodle') {
    define('CLI_SCRIPT', true);
    require '/var/www/moodle/config.php';
    $state = json_decode(file_get_contents('/evidence/moodle-state.json'), true);
    $grade = $DB->get_record_sql('SELECT gi.id, gi.grademax, gg.rawgrade, gg.finalgrade FROM {grade_items} gi LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ? WHERE gi.itemmodule = ? AND gi.iteminstance = ?', [$state['student_id'], 'lti', $state['activities']['guided_assignment']['instance_id']]);
    $result = ['moodle_version' => $CFG->release, 'gradebook' => $grade ?: null];
    file_put_contents('/evidence/moodle-gradebook.json', json_encode($result, JSON_PRETTY_PRINT));
    echo json_encode($result) . PHP_EOL;
    if (!$grade || (float) $grade->grademax !== 100.0 || (float) $grade->rawgrade !== 100.0 || (float) $grade->finalgrade !== 100.0) { fwrite(STDERR, "Native Moodle gradebook score assertion failed.\n"); exit(1); }
    exit;
}
require '/var/www/wordboat/wp-load.php';
$state = json_decode(file_get_contents('/evidence/wordpress-state.json'), true);
$tables = ll_tools_grade_delivery_table_names();
$assignments = ll_tools_lms_assignment_table_names();
$progress = ll_tools_user_progress_table_names();
$user = (int) $state['learner_id'];
if (($argv[1] ?? '') === 'source') {
    $ids = implode(',', array_map('intval', $state['word_ids']));
    echo json_encode($wpdb->get_results("SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE ID IN ($ids)", ARRAY_A)) . PHP_EOL;
    exit;
}
$identity_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['identities']} WHERE learner_user_id = %d AND adapter = 'lti' AND status = 'active'", $user));
$deliveries = $wpdb->get_results($wpdb->prepare("SELECT id, adapter, status, grade_revision, last_http_status, last_error_code, attempt_count FROM {$tables['deliveries']} WHERE learner_user_id = %d ORDER BY id", $user), ARRAY_A);
$result = ['wordpress_version' => get_bloginfo('version'), 'll_tools_version' => LL_TOOLS_VERSION, 'active_moodle_identity_count' => $identity_count, 'class_member' => ll_tools_teacher_class_user_is_student($state['class_id'], $user), 'progress_event_count' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$progress['events']} WHERE user_id = %d", $user)), 'word_progress_count' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$progress['words']} WHERE user_id = %d", $user)), 'selected_grades' => $wpdb->get_results($wpdb->prepare("SELECT score_given, score_maximum, points_given, points_maximum, grade_revision FROM {$assignments['grades']} WHERE user_id = %d", $user), ARRAY_A), 'deliveries' => $deliveries];
file_put_contents('/evidence/wordpress-verification.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result) . PHP_EOL;
$selected = count($result['selected_grades']) === 1 ? $result['selected_grades'][0] : null;
$successful = array_filter($deliveries, static fn($row) => $row['status'] === 'succeeded' && (int) $row['last_http_status'] >= 200 && (int) $row['last_http_status'] < 300 && $row['last_error_code'] === '');
if ($identity_count !== 1 || !$result['class_member'] || $result['progress_event_count'] < 10 || $result['word_progress_count'] !== 5 || !$selected
    || (int) $selected['score_given'] !== 5 || (int) $selected['score_maximum'] !== 5 || (float) $selected['points_given'] !== 100.0 || (float) $selected['points_maximum'] !== 100.0 || !$successful) {
    fwrite(STDERR, "Wordboat account, selected grade, progress or successful delivery assertion failed.\n"); exit(1);
}
$hashes = [];
foreach (['language-learner-tools.php','includes/lms/lti.php','includes/lms/lti-accounts.php','includes/lms/assignment-experience.php','includes/admin/lti-integration.php'] as $file) { $hashes[$file] = hash_file('sha256', WP_PLUGIN_DIR . '/language-learner-tools/' . $file); }
file_put_contents('/evidence/verified-runtime-sha256.json', json_encode($hashes, JSON_PRETTY_PRINT));
