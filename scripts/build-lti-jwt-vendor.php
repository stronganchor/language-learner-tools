<?php
/** Rebuild the isolated JWT runtime from the locked Composer package. */
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/_codex_temp/lti-vendor/firebase/php-jwt';
$target = $root . '/includes/vendor/php-jwt';
$lock = json_decode((string) file_get_contents($root . '/dependencies/lti/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
$package = array_values(array_filter($lock['packages'], static fn(array $row): bool => $row['name'] === 'firebase/php-jwt'))[0] ?? null;
if (!$package || !is_dir($source . '/src')) {
    throw new RuntimeException('Run composer install --working-dir=dependencies/lti --no-dev first.');
}
if (!is_dir($target . '/src') && !mkdir($target . '/src', 0775, true)) {
    throw new RuntimeException('Cannot create scoped runtime directory.');
}
$manifest = ['package' => $package['name'], 'version' => $package['version'], 'source_reference' => $package['source']['reference'], 'namespace' => 'LLTools\\Vendor\\Firebase\\JWT', 'files' => []];
$files = glob($source . '/src/*.php');
sort($files, SORT_STRING);
foreach ($files as $file) {
    $original = (string) file_get_contents($file);
    $scoped = str_replace('namespace Firebase\\JWT;', 'namespace LLTools\\Vendor\\Firebase\\JWT;', $original, $count);
    if ($count !== 1) {
        throw new RuntimeException('Unexpected upstream namespace: ' . basename($file));
    }
    // Upstream currently uses relative class references. Fail if that changes.
    $code = '';
    foreach (token_get_all($original) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    if (str_contains($code, 'Firebase\\JWT\\')) {
        throw new RuntimeException('Unexpected absolute upstream class reference.');
    }
    file_put_contents($target . '/src/' . basename($file), $scoped);
    $manifest['files'][basename($file)] = ['upstream_sha256' => hash('sha256', $original), 'scoped_sha256' => hash('sha256', $scoped)];
}
copy($source . '/LICENSE', $target . '/LICENSE');
file_put_contents($target . '/provenance.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Built ' . count($files) . ' scoped JWT classes from ' . $package['version'] . ".\n";
