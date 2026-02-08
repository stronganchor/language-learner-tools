<?php
declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    $polyfills_path = __DIR__ . '/vendor/yoast/phpunit-polyfills';
    if (is_dir($polyfills_path)) {
        define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path);
    }
}

$tests_dir = getenv('WP_TESTS_DIR');
if (!$tests_dir) {
    $tests_dir = '/tmp/wordpress-tests-lib';
}

if (!file_exists($tests_dir . '/includes/functions.php')) {
    fwrite(
        STDERR,
        "WordPress test library not found.\n" .
        "Set WP_TESTS_DIR to a valid wordpress-tests-lib path.\n" .
        "Example: export WP_TESTS_DIR=/tmp/wordpress-tests-lib\n"
    );
    exit(1);
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require dirname(__DIR__) . '/language-learner-tools.php';
});

require $tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
