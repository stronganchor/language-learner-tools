<?php
define('CLI_SCRIPT', true);
require '/var/www/moodle/config.php';
$CFG->debug = E_ALL;
$CFG->debugdisplay = true;
require_once $CFG->dirroot . '/user/lib.php';
require_once $CFG->dirroot . '/course/lib.php';
require_once $CFG->dirroot . '/course/modlib.php';
require_once $CFG->dirroot . '/mod/lti/locallib.php';
require_once $CFG->dirroot . '/enrol/manual/lib.php';
require_once $CFG->libdir . '/adminlib.php';
$credentials = json_decode(file_get_contents('/private/credentials.json'), true);
\core\session\manager::set_user(get_admin());
admin_apply_default_settings(null, true);
if (is_file('/evidence/moodle-state.json')) {
    $prior = json_decode(file_get_contents('/evidence/moodle-state.json'), true);
    if ($DB->record_exists('course', ['id' => $prior['course_id'] ?? 0, 'shortname' => 'LLINTEROP'])
        && $DB->record_exists('lti_types', ['id' => $prior['deployment_id'] ?? 0, 'clientid' => $prior['client_id'] ?? ''])) { echo "Moodle fixture already seeded.\n"; exit; }
    unlink('/evidence/moodle-state.json');
}
$users = [];
foreach (['student', 'teacher'] as $kind) {
    $record = (object) ['username' => 'interop_' . $kind, 'password' => $credentials['moodle_' . $kind], 'auth' => 'manual', 'confirmed' => 1, 'firstname' => 'Interop', 'lastname' => ucfirst($kind), 'email' => $kind . '@example.test', 'mnethostid' => $CFG->mnet_localhost_id, 'calendartype' => 'gregorian', 'maildisplay' => 2, 'mailformat' => 1, 'maildigest' => 0, 'autosubscribe' => 1, 'trackforums' => 0, 'lang' => 'en', 'city' => '', 'country' => ''];
    $users[$kind] = user_create_user($record);
}
$course = create_course((object) ['category' => $DB->get_field('course_categories', 'id', [], IGNORE_MULTIPLE), 'fullname' => 'Wordboat Hebrew interoperability', 'shortname' => 'LLINTEROP', 'format' => 'topics', 'visible' => 1]);
$manual = enrol_get_plugin('manual');
$enrols = enrol_get_instances($course->id, false);
$instance = null;
foreach ($enrols as $enrol) { if ($enrol->enrol === 'manual') { $instance = $enrol; break; } }
if (!$instance) { $id = $manual->add_instance($course); $instance = $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST); }
foreach (['student' => 'student', 'teacher' => 'editingteacher'] as $kind => $role) { $manual->enrol_user($instance, $users[$kind], $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST)); }
$tool = 'https://wordboat.lti-interop.example.net';
$config = (object) ['lti_typename' => 'Wordboat real LTI', 'lti_toolurl' => $tool . '/?ll_lti_resource=1', 'lti_ltiversion' => LTI_VERSION_1P3, 'lti_coursevisible' => 1, 'lti_keytype' => 'JWK_KEYSET', 'lti_publickeyset' => $tool . '/wp-json/ll-tools/v1/lti/jwks', 'lti_initiatelogin' => $tool . '/wp-json/ll-tools/v1/lti/login', 'lti_redirectionuris' => $tool . '/wp-json/ll-tools/v1/lti/launch', 'lti_sendname' => LTI_SETTING_NEVER, 'lti_sendemailaddr' => LTI_SETTING_NEVER, 'lti_acceptgrades' => LTI_SETTING_ALWAYS, 'lti_forcessl' => 1, 'lti_launchcontainer' => LTI_LAUNCH_CONTAINER_WINDOW, 'ltiservice_gradesynchronization' => 2];
$typeid = lti_add_type((object) ['state' => LTI_TOOL_STATE_CONFIGURED, 'course' => 1], $config);
$type = $DB->get_record('lti_types', ['id' => $typeid], '*', MUST_EXIST);
$native = serialise_tool_type($type);
$platformdetails = ['platformid' => $native['platformid'], 'clientid' => $native['clientid'], 'deploymentid' => $native['deploymentid'], 'urls' => array_intersect_key($native['urls'], array_flip(['publickeyset','accesstoken','authrequest']))];
file_put_contents('/evidence/moodle-platform-details.json', json_encode($platformdetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$state = ['moodle_version' => $CFG->release, 'course_id' => (int) $course->id, 'student_id' => $users['student'], 'teacher_id' => $users['teacher'], 'client_id' => $type->clientid, 'deployment_id' => (string) $typeid, 'issuer' => $CFG->wwwroot, 'activities' => []];
foreach (['practice' => 0, 'assignment' => 100, 'guided_practice' => 0, 'guided_assignment' => 100] as $kind => $grade) {
    $module = (object) ['course' => $course->id, 'name' => 'Wordboat ' . $kind, 'modulename' => 'lti', 'module' => $DB->get_field('modules', 'id', ['name' => 'lti']), 'section' => 1, 'visible' => 1, 'visibleoncoursepage' => 1, 'groupmode' => 0, 'groupingid' => 0, 'idnumber' => '', 'intro' => '', 'introformat' => FORMAT_HTML, 'typeid' => $typeid, 'toolurl' => '', 'securetoolurl' => '', 'grade' => $grade, 'launchcontainer' => LTI_LAUNCH_CONTAINER_WINDOW, 'instructorchoiceacceptgrades' => $grade > 0 ? LTI_SETTING_ALWAYS : LTI_SETTING_NEVER, 'instructorchoicesendname' => LTI_SETTING_NEVER, 'instructorchoicesendemailaddr' => LTI_SETTING_NEVER, 'instructorcustomparameters' => '', 'showtitlelaunch' => 0, 'showdescriptionlaunch' => 0, 'completion' => 0];
    $created = add_moduleinfo($module, $course);
    $state['activities'][$kind] = ['cm_id' => (int) $created->coursemodule, 'instance_id' => (int) $created->instance];
}
file_put_contents('/evidence/moodle-state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Real Moodle course, teacher, student and four LTI activities seeded.\n";
