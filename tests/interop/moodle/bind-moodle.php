<?php
define('CLI_SCRIPT', true);
require '/var/www/moodle/config.php';
$moodle = json_decode(file_get_contents('/evidence/moodle-state.json'), true);
$wordpress = json_decode(file_get_contents('/evidence/wordpress-state.json'), true);
foreach (['practice', 'assignment'] as $kind) {
    $DB->set_field('lti', 'instructorcustomparameters', 'll_activity=' . $wordpress['resources'][$kind], ['id' => $moodle['activities'][$kind]['instance_id']]);
}
echo "Two manual activities bound; two guided activities retain empty custom parameters.\n";
