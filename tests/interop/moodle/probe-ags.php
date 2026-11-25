<?php
define('CLI_SCRIPT', true);
require '/var/www/moodle/config.php';
require_once $CFG->dirroot . '/mod/lti/locallib.php';
$state = json_decode(file_get_contents('/evidence/moodle-state.json'), true);
\core\session\manager::set_user(get_admin());
$result = [];
foreach (['teacher', 'student'] as $role) {
    $user = $DB->get_record('user', ['id' => $state[$role . '_id']], '*', MUST_EXIST);
    $service = new \ltiservice_gradebookservices\local\service\gradebookservices();
    $params = $service->get_launch_parameters('basic-lti-launch-request', $state['course_id'], $user, (int) $state['deployment_id'], $state['activities']['assignment']['instance_id']);
    $result[$role] = ['has_lineitem' => !empty($params['lineitem_url']), 'has_score_scope' => in_array('https://purl.imsglobal.org/spec/lti-ags/scope/score', explode(',', $params['gradebookservices_scope'] ?? ''), true)];
}
file_put_contents('/evidence/moodle-ags-probe.json', json_encode($result, JSON_PRETTY_PRINT));
echo json_encode($result) . PHP_EOL;
