<?php
$private = '/private/credentials.json';
if (!is_file($private)) {
    $credentials = [];
    foreach (['moodle_admin', 'moodle_teacher', 'moodle_student', 'wordpress_admin', 'wordpress_teacher', 'wordpress_student'] as $key) { $credentials[$key] = bin2hex(random_bytes(16)) . 'Aa1!'; }
    file_put_contents($private, json_encode($credentials));
    chmod($private, 0600);
}
for ($attempt = 0; $attempt < 60; $attempt++) {
    try { $db = new mysqli('db', 'root', ''); break; } catch (Throwable $e) { sleep(1); }
}
if (!isset($db)) { throw new RuntimeException('Disposable database unavailable'); }
foreach (['moodle_interop', 'wordboat_interop'] as $name) { $db->query("CREATE DATABASE IF NOT EXISTS $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
if (!is_file('/var/www/wordboat/wp-config.php')) {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $settings = ['DB_NAME' => 'wordboat_interop', 'DB_USER' => 'root', 'DB_PASSWORD' => '', 'DB_HOST' => 'db', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_HOME' => 'https://wordboat.lti-interop.example.net', 'WP_SITEURL' => 'https://wordboat.lti-interop.example.net', 'DISABLE_WP_CRON' => true, 'WP_DEBUG' => true, 'WP_DEBUG_LOG' => true, 'WP_DEBUG_DISPLAY' => false, 'LL_TOOLS_LTI_PRIVATE_KEY' => $pem, 'LL_TOOLS_LTI_KEY_ID' => 'real-moodle-interop', 'LL_TOOLS_LMS_CREDENTIAL_KEY' => 'base64:' . base64_encode(random_bytes(32))];
    foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $key) { $settings[$key] = bin2hex(random_bytes(32)); }
    $config = "<?php\n";
    foreach ($settings as $name => $value) { $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n"; }
    $config .= "\$table_prefix = 'wp_';\nrequire __DIR__ . '/wp-settings.php';\n";
    file_put_contents('/var/www/wordboat/wp-config.php', $config);
    chown('/var/www/wordboat/wp-config.php', 'www-data');
    file_put_contents('/var/www/wordboat/.htaccess', "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n");
}
if (!is_file('/var/www/moodle/config.php')) {
    file_put_contents('/var/www/moodle/config.php', <<<'PHP'
<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost = 'db';
$CFG->dbname = 'moodle_interop';
$CFG->dbuser = 'root';
$CFG->dbpass = '';
$CFG->prefix = 'mdl_';
$CFG->dboptions = ['dbcollation' => 'utf8mb4_unicode_ci'];
$CFG->wwwroot = 'https://moodle.lti-interop.example.org';
$CFG->dataroot = '/var/www/moodledata';
$CFG->admin = 'admin';
$CFG->directorypermissions = 02770;
$CFG->pathtocacert = '/etc/ssl/certs/ca-certificates.crt';
$CFG->passwordpolicy = 1;
$CFG->site_is_public = false;
require_once __DIR__ . '/lib/setup.php';
PHP
    );
    chown('/var/www/moodle/config.php', 'www-data');
}
if (!is_file('/var/www/wordboat/.htaccess')) {
    file_put_contents('/var/www/wordboat/.htaccess', "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n");
    chmod('/var/www/wordboat/.htaccess', 0644);
}
chmod('/var/www/wordboat/.htaccess', 0644);
