<?php
/**
 * Lightweight checksum-contract verification; does not bootstrap WordPress.
 */

require_once __DIR__ . '/lib/performance-manifest.php';

/**
 * @return array{version:string,checksum:string}
 */
function ll_tools_perf_manifest_contract_for_file(string $path): array {
    if (!is_readable($path)) {
        throw new RuntimeException('Performance fixture manifest is not readable: ' . $path);
    }

    $manifest = json_decode((string) file_get_contents($path), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Performance fixture manifest is not valid JSON: ' . $path);
    }

    $version = isset($manifest['fixtureVersion']) && is_scalar($manifest['fixtureVersion'])
        ? trim((string) $manifest['fixtureVersion'])
        : '';
    if ($version === '' || preg_match('/[\t\r\n]/', $version)) {
        throw new RuntimeException('Performance fixture manifest has an invalid fixtureVersion.');
    }

    return [
        'version' => $version,
        'checksum' => ll_tools_perf_manifest_checksum_file($path),
    ];
}

$mode = (string) ($argv[1] ?? '');
if ($mode === '--describe' || $mode === '--verify-stored') {
    $manifest_path = (string) ($argv[2] ?? '');
    try {
        $contract = ll_tools_perf_manifest_contract_for_file($manifest_path);
    } catch (RuntimeException $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }

    if ($mode === '--describe') {
        fwrite(STDOUT, implode("\t", [
            $contract['version'],
            $contract['checksum'],
            LL_TOOLS_PERF_MANIFEST_CHECKSUM_FORMAT,
        ]) . "\n");
        exit(0);
    }

    $stored_json = array_key_exists(3, $argv)
        ? (string) $argv[3]
        : (string) stream_get_contents(STDIN);
    $stored = json_decode($stored_json, true);
    if (!is_array($stored)) {
        fwrite(STDERR, sprintf(
            "Stored performance fixture manifest is missing or invalid JSON (%d bytes; %s).\n",
            strlen($stored_json),
            json_last_error_msg()
        ));
        exit(1);
    }
    if ((string) ($stored['fixture_version'] ?? '') !== $contract['version']) {
        fwrite(STDERR, "Stored performance fixture version does not match the selected manifest.\n");
        exit(1);
    }
    if (!ll_tools_perf_manifest_stored_checksum_is_current($stored, $contract['checksum'])) {
        fwrite(STDERR, "Stored performance fixture canonical checksum does not match the selected manifest.\n");
        exit(1);
    }

    fwrite(STDOUT, sprintf(
        "Stored performance fixture verified: version %s, canonical SHA-256 %s.\n",
        $contract['version'],
        $contract['checksum']
    ));
    exit(0);
}
if ($mode !== '') {
    fwrite(STDERR, "Unknown performance manifest verification mode.\n");
    exit(1);
}

$expected = 'bcb6e8fe385c21e8f27036bfbf5e461d92fa018c6db176dc95b8e365882d406e';
$left = [
    'b' => [3, ['z' => 'last', 'a' => 'first']],
    'a' => 1,
];
$right = [
    'a' => 1,
    'b' => [3, ['a' => 'first', 'z' => 'last']],
];

$left_checksum = ll_tools_perf_manifest_checksum_from_array($left);
$right_checksum = ll_tools_perf_manifest_checksum_from_array($right);
if ($left_checksum !== $expected || $right_checksum !== $expected) {
    fwrite(STDERR, "Canonical manifest checksum verification failed.\n");
    exit(1);
}
if (ll_tools_perf_manifest_canonical_json(['number' => 1.0, 'empty' => new stdClass()]) !== '{"empty":{},"number":1}') {
    fwrite(STDERR, "Canonical manifest JSON does not match JavaScript number/object encoding.\n");
    exit(1);
}
try {
    ll_tools_perf_manifest_canonical_json(['fraction' => 0.5]);
    fwrite(STDERR, "Canonical manifest JSON accepted a non-integer number.\n");
    exit(1);
} catch (RuntimeException $error) {
    // Expected: the cross-runtime checksum contract intentionally uses safe integers only.
}

$temp_dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'll-tools-performance-manifest-' . bin2hex(random_bytes(6));
if (!mkdir($temp_dir, 0700, true) && !is_dir($temp_dir)) {
    fwrite(STDERR, "Unable to create checksum verification directory.\n");
    exit(1);
}

$lf_path = $temp_dir . DIRECTORY_SEPARATOR . 'manifest-lf.json';
$crlf_path = $temp_dir . DIRECTORY_SEPARATOR . 'manifest-crlf.json';
file_put_contents($lf_path, "{\n  \"b\": [3, {\"z\": \"last\", \"a\": \"first\"}],\n  \"a\": 1\n}\n");
file_put_contents($crlf_path, "{\r\n  \"a\": 1,\r\n  \"b\": [3, {\"a\": \"first\", \"z\": \"last\"}]\r\n}\r\n");

try {
    $file_contract_passed = ll_tools_perf_manifest_checksum_file($lf_path) === $expected
        && ll_tools_perf_manifest_checksum_file($crlf_path) === $expected;
} finally {
    @unlink($lf_path);
    @unlink($crlf_path);
    @rmdir($temp_dir);
}
if (!$file_contract_passed) {
    fwrite(STDERR, "Line-ending-independent manifest checksum verification failed.\n");
    exit(1);
}

$canonical_stored = [
    'manifest_sha256' => $expected,
    'manifest_checksum_format' => LL_TOOLS_PERF_MANIFEST_CHECKSUM_FORMAT,
];
if (!ll_tools_perf_manifest_stored_checksum_is_current($canonical_stored, $expected)) {
    fwrite(STDERR, "Exact canonical stored checksum was not reusable.\n");
    exit(1);
}

$stale_stored_variants = [
    'legacy checksum format' => [
        'manifest_sha256' => $expected,
    ],
    'missing checksum' => [
        'manifest_checksum_format' => LL_TOOLS_PERF_MANIFEST_CHECKSUM_FORMAT,
    ],
    'different canonical checksum' => [
        'manifest_sha256' => str_repeat('0', 64),
        'manifest_checksum_format' => LL_TOOLS_PERF_MANIFEST_CHECKSUM_FORMAT,
    ],
];
foreach ($stale_stored_variants as $label => $stored) {
    if (ll_tools_perf_manifest_stored_checksum_is_current($stored, $expected)) {
        fwrite(STDERR, sprintf("Stored fixture with %s was incorrectly reusable.\n", $label));
        exit(1);
    }
}
if (ll_tools_perf_manifest_stored_checksum_is_current($canonical_stored, '')) {
    fwrite(STDERR, "Empty current checksum was incorrectly reusable.\n");
    exit(1);
}

fwrite(STDOUT, "Performance manifest checksum contract passed.\n");
