<?php
require '/var/www/wordboat/wp-load.php';
$result = ll_tools_grade_delivery_run_worker(10);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
