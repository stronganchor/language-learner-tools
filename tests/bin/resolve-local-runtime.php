<?php

declare(strict_types=1);

/**
 * Resolve Local test settings without depending on a system Python install.
 *
 * This helper intentionally prints shell export statements because the Bash
 * wrappers consume its output with eval. Never include these exports in test
 * logs: the database password is one of the values required by PHPUnit.
 */

function ll_tools_local_env_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/**
 * @return array<string, list<string>>
 */
function ll_tools_local_env_options(array $arguments): array
{
    $options = [];

    foreach (array_slice($arguments, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            ll_tools_local_env_fail('Invalid local runtime resolver argument.');
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        if ($name === '') {
            ll_tools_local_env_fail('Invalid local runtime resolver option.');
        }
        $options[$name][] = $value;
    }

    return $options;
}

/**
 * @param array<string, list<string>> $options
 */
function ll_tools_local_env_option(array $options, string $name, string $default = ''): string
{
    if (!isset($options[$name][0])) {
        return $default;
    }

    return $options[$name][0];
}

function ll_tools_local_env_path_identity(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $realPath = realpath($path);
    if ($realPath !== false) {
        $path = str_replace('\\', '/', $realPath);
    }

    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = rtrim($path, '/');

    if (preg_match('#^([A-Za-z]):/(.*)$#', $path, $matches) === 1) {
        return 'windows:' . strtolower($matches[1] . '/' . $matches[2]);
    }
    if (preg_match('#^/mnt/([A-Za-z])/(.*)$#', $path, $matches) === 1) {
        return 'windows:' . strtolower($matches[1] . '/' . $matches[2]);
    }
    if (preg_match('#^/([A-Za-z])/(.*)$#', $path, $matches) === 1) {
        return 'windows:' . strtolower($matches[1] . '/' . $matches[2]);
    }

    return (PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path);
}

function ll_tools_local_env_shell_path(string $path, string $style): string
{
    $normalized = str_replace('\\', '/', $path);
    if (preg_match('#^([A-Za-z]):/(.*)$#', $normalized, $matches) !== 1) {
        return $normalized;
    }

    $drive = strtolower($matches[1]);
    if ($style === 'msys') {
        return '/' . $drive . '/' . $matches[2];
    }
    if ($style === 'wsl') {
        return '/mnt/' . $drive . '/' . $matches[2];
    }

    return $normalized;
}

function ll_tools_local_env_shell_quote(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function ll_tools_local_env_export(string $name, string $value): void
{
    // This output is evaluated by Bash even when the resolver itself runs
    // under Windows PHP. Keep the shell protocol LF-only so a CR from PHP_EOL
    // cannot become part of the exported host, password, or path value.
    fwrite(STDOUT, 'export ' . $name . '=' . ll_tools_local_env_shell_quote($value) . "\n");
}

/**
 * @return list<string>
 */
function ll_tools_local_env_default_runtime_roots(): array
{
    $roots = [];
    $appData = getenv('APPDATA');
    $userProfile = getenv('USERPROFILE');
    $home = getenv('HOME');

    if (is_string($appData) && $appData !== '') {
        $roots[] = $appData . '/Local/run';
    }
    if (is_string($userProfile) && $userProfile !== '') {
        $roots[] = $userProfile . '/AppData/Roaming/Local/run';
    }
    if (is_string($home) && $home !== '') {
        $roots[] = $home . '/AppData/Roaming/Local/run';
    }

    foreach (glob('/mnt/c/Users/*/AppData/Roaming/Local/run', GLOB_ONLYDIR) ?: [] as $root) {
        $roots[] = $root;
    }

    return array_values(array_unique($roots));
}

/**
 * @return list<string>
 */
function ll_tools_local_env_default_service_roots(): array
{
    $roots = [];
    $appData = getenv('APPDATA');
    $userProfile = getenv('USERPROFILE');
    $home = getenv('HOME');

    if (is_string($appData) && $appData !== '') {
        $roots[] = $appData . '/Local/lightning-services';
    }
    if (is_string($userProfile) && $userProfile !== '') {
        $roots[] = $userProfile . '/AppData/Roaming/Local/lightning-services';
    }
    if (is_string($home) && $home !== '') {
        $roots[] = $home . '/AppData/Roaming/Local/lightning-services';
    }

    foreach (glob('/mnt/c/Users/*/AppData/Roaming/Local/lightning-services', GLOB_ONLYDIR) ?: [] as $root) {
        $roots[] = $root;
    }

    return array_values(array_unique($roots));
}

/**
 * @param list<string> $runtimeRoots
 * @return array{nginx_conf:string,mysql_conf:string,mysql_port:string,http_port:string}|null
 */
function ll_tools_local_env_runtime(array $runtimeRoots, string $siteRoot): ?array
{
    $siteIdentity = ll_tools_local_env_path_identity($siteRoot);
    $siteConfigs = [];

    foreach ($runtimeRoots as $runtimeRoot) {
        $pattern = rtrim(str_replace('\\', '/', $runtimeRoot), '/') . '/*/conf/nginx/site.conf';
        foreach (glob($pattern) ?: [] as $siteConfig) {
            $siteConfigs[] = $siteConfig;
        }
    }

    usort(
        $siteConfigs,
        static function (string $left, string $right): int {
            $mtimeOrder = ((int) @filemtime($right)) <=> ((int) @filemtime($left));
            return $mtimeOrder !== 0 ? $mtimeOrder : strcmp($right, $left);
        }
    );

    foreach ($siteConfigs as $siteConfig) {
        $nginxText = @file_get_contents($siteConfig);
        if (!is_string($nginxText)) {
            continue;
        }

        if (preg_match('/^\s*root\s+["\']?([^;"\']+)["\']?\s*;/mi', $nginxText, $rootMatch) !== 1) {
            continue;
        }
        if (ll_tools_local_env_path_identity(trim($rootMatch[1])) !== $siteIdentity) {
            continue;
        }

        $httpPort = '';
        if (preg_match('/^\s*listen\s+(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)\s*;/mi', $nginxText, $portMatch) === 1) {
            $httpPort = $portMatch[1];
        }

        $runDirectory = dirname(dirname(dirname($siteConfig)));
        $mysqlConfig = $runDirectory . '/conf/mysql/my.cnf';
        $mysqlPort = '';
        $mysqlText = @file_get_contents($mysqlConfig);
        if (is_string($mysqlText) && preg_match('/^\s*port\s*=\s*(\d+)\s*$/mi', $mysqlText, $mysqlMatch) === 1) {
            $mysqlPort = $mysqlMatch[1];
        }

        return [
            'nginx_conf' => $siteConfig,
            'mysql_conf' => is_file($mysqlConfig) ? $mysqlConfig : '',
            'mysql_port' => $mysqlPort,
            'http_port' => $httpPort,
        ];
    }

    return null;
}

/**
 * Resolve the loopback database port recorded by Local in wp-config.php.
 *
 * Sandboxed test runners may be able to read the site checkout while Local's
 * AppData runtime directory is intentionally hidden. In that case the site's
 * literal DB_HOST is a safer fallback than local-site.json, whose service port
 * can lag behind a restarted Local runtime.
 */
function ll_tools_local_env_wp_config_db_port(string $siteRoot): string
{
    $configPath = rtrim($siteRoot, '/\\') . '/wp-config.php';
    $configText = @file_get_contents($configPath);
    if (!is_string($configText)) {
        return '';
    }

    if (preg_match(
        '/define\s*\(\s*[\'\"]DB_HOST[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/i',
        $configText,
        $hostMatch
    ) !== 1) {
        return '';
    }

    $host = trim((string) ($hostMatch[1] ?? ''));
    if (preg_match('/^(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)$/i', $host, $portMatch) !== 1) {
        return '';
    }

    $port = (int) ($portMatch[1] ?? 0);
    return $port >= 1 && $port <= 65535 ? (string) $port : '';
}

/**
 * @param list<string> $serviceRoots
 */
function ll_tools_local_env_find_binary(array $serviceRoots, string $fileName): string
{
    $matches = [];
    foreach ($serviceRoots as $serviceRoot) {
        if (!is_dir($serviceRoot)) {
            continue;
        }

        $root = rtrim(str_replace('\\', '/', $serviceRoot), '/');
        $patterns = [
            $root . '/*/bin/*/bin/' . $fileName,
            $root . '/*/bin/*/' . $fileName,
            $root . '/*/bin/' . $fileName,
        ];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $match) {
                if (is_file($match)) {
                    $matches[] = $match;
                }
            }
        }
    }

    $matches = array_values(array_unique($matches));
    natsort($matches);
    $matches = array_values($matches);
    return $matches === [] ? '' : (string) end($matches);
}

$options = ll_tools_local_env_options($argv);
$mode = ll_tools_local_env_option($options, 'mode');
$siteRoot = ll_tools_local_env_option($options, 'site-root');
$shellPathStyle = ll_tools_local_env_option($options, 'shell-path-style', 'posix');

if (!in_array($mode, ['db', 'http'], true)) {
    ll_tools_local_env_fail('Local runtime resolver mode must be db or http.');
}
if ($siteRoot === '') {
    ll_tools_local_env_fail('Local runtime resolver requires a site root.');
}

$runtimeRoots = $options['runtime-root'] ?? ll_tools_local_env_default_runtime_roots();
$runtime = ll_tools_local_env_runtime($runtimeRoots, $siteRoot);
$localSiteJson = ll_tools_local_env_option($options, 'local-site-json');
if ($localSiteJson === '' || !is_file($localSiteJson)) {
    ll_tools_local_env_fail('local-site.json was not found. Set LOCAL_SITE_JSON explicitly.');
}

$jsonText = @file_get_contents($localSiteJson);
$siteData = is_string($jsonText) ? json_decode($jsonText, true) : null;
if (!is_array($siteData)) {
    ll_tools_local_env_fail('local-site.json could not be parsed.');
}

if ($mode === 'http') {
    if ($runtime === null || $runtime['http_port'] === '') {
        ll_tools_local_env_fail("Could not detect a Local nginx site.conf matching this plugin's site root.");
    }

    $domain = is_scalar($siteData['domain'] ?? null) ? trim((string) $siteData['domain']) : '';
    if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
        ll_tools_local_env_fail('local-site.json does not contain a valid canonical domain.');
    }

    ll_tools_local_env_export('LL_E2E_BASE_URL', 'https://' . strtolower($domain));
    ll_tools_local_env_export('LL_E2E_LEARN_PATH', '/learn/');
    ll_tools_local_env_export(
        'LL_E2E_NGINX_CONF',
        ll_tools_local_env_shell_path($runtime['nginx_conf'], $shellPathStyle)
    );
    exit(0);
}

$dbData = is_array($siteData['mysql'] ?? null) ? $siteData['mysql'] : [];
$services = is_array($siteData['services'] ?? null) ? $siteData['services'] : [];
$mysqlService = is_array($services['mysql'] ?? null) ? $services['mysql'] : [];
$ports = is_array($mysqlService['ports'] ?? null) ? $mysqlService['ports'] : [];
$jsonPorts = is_array($ports['MYSQL'] ?? null) ? $ports['MYSQL'] : [];

$siteDbName = is_scalar($dbData['database'] ?? null) ? (string) $dbData['database'] : 'local';
$dbUser = is_scalar($dbData['user'] ?? null) ? (string) $dbData['user'] : 'root';
$dbPass = is_scalar($dbData['password'] ?? null) ? (string) $dbData['password'] : 'root';
$dbPort = isset($jsonPorts[0]) && is_scalar($jsonPorts[0]) ? (string) $jsonPorts[0] : '3306';
$dbPortSource = 'local_site_json';

if ($runtime !== null && preg_match('/^\d+$/', $runtime['mysql_port']) === 1) {
    $dbPort = $runtime['mysql_port'];
    $dbPortSource = 'local_runtime';
} else {
    $wpConfigPort = ll_tools_local_env_wp_config_db_port($siteRoot);
    if ($wpConfigPort !== '') {
        $dbPort = $wpConfigPort;
        $dbPortSource = 'wp_config';
    }
}

$defaultTestDbName = $siteDbName === 'local' ? 'local_test' : $siteDbName . '_test';
if (preg_match('/_(?:test|tests|testing|suite)$/', $siteDbName) === 1) {
    $defaultTestDbName = $siteDbName . '_suite';
}

$dbName = getenv('WP_TEST_DB_NAME');
if (!is_string($dbName) || $dbName === '') {
    $dbName = $defaultTestDbName;
}

$testsDirectory = getenv('WP_TESTS_DIR');
$coreDirectory = getenv('WP_CORE_DIR');
$testsDirectory = is_string($testsDirectory) && $testsDirectory !== '' ? $testsDirectory : '/tmp/wordpress-tests-lib';
$coreDirectory = is_string($coreDirectory) && $coreDirectory !== '' ? $coreDirectory : '/tmp/wordpress';

$preferWindowsTemp = getenv('LL_TOOLS_USE_WINDOWS_TEMP_WP_BOOTSTRAP');
$preferWindowsTemp = !is_string($preferWindowsTemp) || $preferWindowsTemp === '' || $preferWindowsTemp === '1';
if ($preferWindowsTemp && PHP_OS_FAMILY === 'Windows') {
    $tempDirectory = ll_tools_local_env_option($options, 'temp-dir', sys_get_temp_dir());
    if (getenv('WP_TESTS_DIR') === false || getenv('WP_TESTS_DIR') === '') {
        $testsDirectory = rtrim($tempDirectory, '/\\') . '/wordpress-tests-lib';
    }
    if (getenv('WP_CORE_DIR') === false || getenv('WP_CORE_DIR') === '') {
        $coreDirectory = rtrim($tempDirectory, '/\\') . '/wordpress';
    }
}

$serviceRoots = $options['service-root'] ?? ll_tools_local_env_default_service_roots();
$mysqlBinary = ll_tools_local_env_find_binary($serviceRoots, 'mysql.exe');
$phpBinary = PHP_BINARY;

ll_tools_local_env_export('WP_TEST_DB_NAME', $dbName);
ll_tools_local_env_export('WP_TEST_DB_USER', $dbUser);
ll_tools_local_env_export('WP_TEST_DB_PASS', $dbPass);
ll_tools_local_env_export('WP_TEST_DB_HOST', '127.0.0.1:' . $dbPort);
ll_tools_local_env_export('WP_TESTS_DIR', ll_tools_local_env_shell_path($testsDirectory, $shellPathStyle));
ll_tools_local_env_export('WP_CORE_DIR', ll_tools_local_env_shell_path($coreDirectory, $shellPathStyle));

if ($phpBinary !== '') {
    ll_tools_local_env_export('PHP_BIN', ll_tools_local_env_shell_path($phpBinary, $shellPathStyle));
}
if ($mysqlBinary !== '') {
    ll_tools_local_env_export('MYSQL_BIN', ll_tools_local_env_shell_path($mysqlBinary, $shellPathStyle));
}

ll_tools_local_env_export('LOCAL_DB_PORT_SOURCE', $dbPortSource);
ll_tools_local_env_export('LOCAL_LIVE_DB_NAME', $siteDbName);
ll_tools_local_env_export('LOCAL_LIVE_DB_HOST', '127.0.0.1:' . $dbPort);

if ($runtime !== null && $runtime['mysql_conf'] !== '') {
    ll_tools_local_env_export(
        'LOCAL_ACTIVE_MYSQL_CONF',
        ll_tools_local_env_shell_path($runtime['mysql_conf'], $shellPathStyle)
    );
}
if ($runtime !== null) {
    ll_tools_local_env_export(
        'LOCAL_ACTIVE_NGINX_CONF',
        ll_tools_local_env_shell_path($runtime['nginx_conf'], $shellPathStyle)
    );
}

ll_tools_local_env_export(
    'LOCAL_SITE_JSON',
    ll_tools_local_env_shell_path($localSiteJson, $shellPathStyle)
);
