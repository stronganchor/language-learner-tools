<?php
/** Disposable container installer. Credentials never enter command output. */
$credentials = json_decode(file_get_contents('/private/credentials.json'), true);
if (($argv[1] ?? '') === 'moodle') {
    $db = new mysqli('db', 'root', '', 'moodle_interop');
    $exists = $db->query("SHOW TABLES LIKE 'mdl_config'")->num_rows > 0;
    if ($exists) { echo "Moodle already installed.\n"; exit; }
    $argv = ['/var/www/moodle/admin/cli/install_database.php', '--agree-license', '--fullname=LL Tools real Moodle interop', '--shortname=Interop', '--adminuser=interop_admin', '--adminpass=' . $credentials['moodle_admin'], '--adminemail=admin@example.test', '--lang=en'];
    $argc = count($argv);
    $_SERVER['argv'] = $argv;
    $_SERVER['argc'] = $argc;
    require $argv[0];
    exit;
}
define('WP_INSTALLING', true);
require '/var/www/wordboat/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_blog_installed()) { wp_install('Real Moodle interop Wordboat', 'interop_admin', 'wpadmin@example.test', false, '', $credentials['wordpress_admin']); }
$result = activate_plugin('language-learner-tools/language-learner-tools.php');
if (is_wp_error($result)) { throw new RuntimeException($result->get_error_code()); }
echo "WordPress installed; LL Tools active.\n";
