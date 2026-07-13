<?php
/**
 * Canonical performance-manifest helpers shared by the fixture seeder and
 * lightweight verification scripts.
 */

const LL_TOOLS_PERF_MANIFEST_CHECKSUM_FORMAT = 'canonical-json-v1';

function ll_tools_perf_manifest_array_is_list(array $value): bool {
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }

    $expected_key = 0;
    foreach ($value as $key => $_item) {
        if ($key !== $expected_key) {
            return false;
        }
        $expected_key++;
    }
    return true;
}

/**
 * Recursively sort JSON object keys while preserving list order.
 *
 * Performance manifests use JSON objects for associative data and JSON arrays
 * for lists. File reads preserve object identity so empty objects stay distinct
 * from empty lists and produce the same checksum as JSON.stringify().
 *
 * @param mixed $value
 * @return mixed
 */
function ll_tools_perf_manifest_canonicalize_value($value) {
    if (is_int($value)) {
        if (abs($value) > 9007199254740991) {
            throw new RuntimeException('Performance fixture manifests may contain only JSON integers within JavaScript\'s safe integer range.');
        }
        return $value;
    }

    if (is_float($value)) {
        if (!is_finite($value) || floor($value) !== $value || abs($value) > 9007199254740991) {
            throw new RuntimeException('Performance fixture manifests may contain only JSON integers within JavaScript\'s safe integer range.');
        }
        return (int) $value;
    }

    if (is_object($value)) {
        $properties = get_object_vars($value);
        ksort($properties, SORT_STRING);
        $canonical = new stdClass();
        foreach ($properties as $key => $item) {
            $canonical->{$key} = ll_tools_perf_manifest_canonicalize_value($item);
        }
        return $canonical;
    }

    if (!is_array($value)) {
        return $value;
    }

    if (!ll_tools_perf_manifest_array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    foreach ($value as $key => $item) {
        $value[$key] = ll_tools_perf_manifest_canonicalize_value($item);
    }

    return $value;
}

/**
 * @throws RuntimeException When the value cannot be encoded.
 */
function ll_tools_perf_manifest_canonical_json($manifest): string {
    $canonical = ll_tools_perf_manifest_canonicalize_value($manifest);
    $json = json_encode(
        $canonical,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode the performance fixture manifest as canonical JSON.');
    }

    return $json;
}

function ll_tools_perf_manifest_checksum_from_array(array $manifest): string {
    return hash('sha256', ll_tools_perf_manifest_canonical_json($manifest));
}

/**
 * Return whether a stored fixture manifest proves it was seeded from the
 * current canonical manifest representation.
 *
 * Legacy/raw checksums cannot safely certify fixture reuse: the manifest may
 * have changed semantically without changing its version or aggregate counts.
 */
function ll_tools_perf_manifest_stored_checksum_is_current(array $stored, string $expected_checksum): bool {
    if ($expected_checksum === '') {
        return false;
    }

    return (string) ($stored['manifest_checksum_format'] ?? '') === LL_TOOLS_PERF_MANIFEST_CHECKSUM_FORMAT
        && (string) ($stored['manifest_sha256'] ?? '') === $expected_checksum;
}

/**
 * @throws RuntimeException When the file is unreadable or invalid.
 */
function ll_tools_perf_manifest_checksum_file(string $path): string {
    if (!is_readable($path)) {
        throw new RuntimeException('Performance fixture manifest is not readable: ' . $path);
    }

    $decoded = json_decode((string) file_get_contents($path));
    if (!is_object($decoded) && !is_array($decoded)) {
        throw new RuntimeException('Performance fixture manifest is not valid JSON: ' . $path);
    }

    return hash('sha256', ll_tools_perf_manifest_canonical_json($decoded));
}
