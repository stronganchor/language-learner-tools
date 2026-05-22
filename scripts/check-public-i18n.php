<?php
declare(strict_types=1);

/**
 * Build and check the tier-2 public UI translation manifest.
 */

function ll_tools_public_i18n_root_dir(): string
{
    return dirname(__DIR__);
}

function ll_tools_public_i18n_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function ll_tools_public_i18n_decode_po_string(string $value): string
{
    return stripcslashes($value);
}

/**
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_parse_po_file(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("PO/POT file not found: {$path}");
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException("Unable to read PO/POT file: {$path}");
    }

    $entries = [];
    $entry = ll_tools_public_i18n_empty_entry();
    $field = null;
    $msgstr_index = 0;

    $flush = static function () use (&$entries, &$entry, &$field, &$msgstr_index): void {
        if ($entry['msgid'] !== null) {
            $entry['references'] = array_values(array_unique($entry['references']));
            sort($entry['references'], SORT_STRING);
            $entries[] = $entry;
        }
        $entry = ll_tools_public_i18n_empty_entry();
        $field = null;
        $msgstr_index = 0;
    };

    foreach ($lines as $line) {
        if (trim($line) === '') {
            $flush();
            continue;
        }

        if (preg_match('/^#:\s+(.+)$/', $line, $matches)) {
            foreach (preg_split('/\s+/', trim($matches[1])) ?: [] as $reference) {
                $reference = ll_tools_public_i18n_normalize_path((string) $reference);
                if ($reference !== '') {
                    $entry['references'][] = $reference;
                }
            }
            continue;
        }

        if (preg_match('/^#,\s+(.+)$/', $line, $matches)) {
            foreach (preg_split('/,\s*/', trim($matches[1])) ?: [] as $flag) {
                $flag = trim((string) $flag);
                if ($flag !== '') {
                    $entry['flags'][] = $flag;
                }
            }
            continue;
        }

        if (preg_match('/^msgctxt\s+"(.*)"$/', $line, $matches)) {
            $entry['context'] = ll_tools_public_i18n_decode_po_string($matches[1]);
            $field = 'context';
            continue;
        }

        if (preg_match('/^msgid\s+"(.*)"$/', $line, $matches)) {
            $entry['msgid'] = ll_tools_public_i18n_decode_po_string($matches[1]);
            $field = 'msgid';
            continue;
        }

        if (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $matches)) {
            $entry['msgid_plural'] = ll_tools_public_i18n_decode_po_string($matches[1]);
            $field = 'msgid_plural';
            continue;
        }

        if (preg_match('/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $matches)) {
            $msgstr_index = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $entry['msgstr'][$msgstr_index] = ll_tools_public_i18n_decode_po_string($matches[2]);
            $field = 'msgstr';
            continue;
        }

        if (preg_match('/^"(.*)"$/', $line, $matches)) {
            $value = ll_tools_public_i18n_decode_po_string($matches[1]);
            if ($field === 'context') {
                $entry['context'] = (string) $entry['context'] . $value;
            } elseif ($field === 'msgid') {
                $entry['msgid'] = (string) $entry['msgid'] . $value;
            } elseif ($field === 'msgid_plural') {
                $entry['msgid_plural'] = (string) $entry['msgid_plural'] . $value;
            } elseif ($field === 'msgstr') {
                $entry['msgstr'][$msgstr_index] = (string) ($entry['msgstr'][$msgstr_index] ?? '') . $value;
            }
        }
    }

    $flush();

    return $entries;
}

/**
 * @return array{context:?string,msgid:?string,msgid_plural:?string,msgstr:array<int,string>,references:array<int,string>,flags:array<int,string>}
 */
function ll_tools_public_i18n_empty_entry(): array
{
    return [
        'context' => null,
        'msgid' => null,
        'msgid_plural' => null,
        'msgstr' => [],
        'references' => [],
        'flags' => [],
    ];
}

function ll_tools_public_i18n_entry_key(array $entry): string
{
    return sha1(
        (string) ($entry['context'] ?? '') .
        "\x04" .
        (string) ($entry['msgid'] ?? '') .
        "\x04" .
        (string) ($entry['msgid_plural'] ?? '')
    );
}

function ll_tools_public_i18n_glob_match(string $glob, string $path): bool
{
    $glob = ll_tools_public_i18n_normalize_path($glob);
    $path = ll_tools_public_i18n_normalize_path($path);
    $quoted = preg_quote($glob, '#');
    $quoted = str_replace('\\*\\*', '.*', $quoted);
    $quoted = str_replace('\\*', '[^/]*', $quoted);

    return (bool) preg_match('#^' . $quoted . '$#', $path);
}

/**
 * @return array{path:string,line:int}|null
 */
function ll_tools_public_i18n_parse_reference(string $reference): ?array
{
    $reference = ll_tools_public_i18n_normalize_path($reference);
    if (!preg_match('/^(.+):(\d+)$/', $reference, $matches)) {
        return null;
    }

    return [
        'path' => $matches[1],
        'line' => (int) $matches[2],
    ];
}

function ll_tools_public_i18n_reference_matches_rule(string $reference, array $rule): bool
{
    $parsed = ll_tools_public_i18n_parse_reference($reference);
    if ($parsed === null) {
        return false;
    }

    $path = (string) ($rule['path'] ?? '');
    if ($path === '' || !ll_tools_public_i18n_glob_match($path, $parsed['path'])) {
        return false;
    }

    $ranges = $rule['ranges'] ?? null;
    if (!is_array($ranges) || $ranges === []) {
        return true;
    }

    foreach ($ranges as $range) {
        if (!is_array($range) || count($range) < 2) {
            continue;
        }
        $start = (int) $range[0];
        $end = (int) $range[1];
        if ($start <= $parsed['line'] && $parsed['line'] <= $end) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_load_config(string $root_dir = ''): array
{
    $root_dir = $root_dir !== '' ? rtrim($root_dir, "\\/") : ll_tools_public_i18n_root_dir();
    $path = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'tier2-public-ui-sources.php';
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException("Invalid public i18n source config: {$path}");
    }

    return $config;
}

/**
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_select_public_entries(string $pot_path, array $config): array
{
    $rules = $config['include_sources'] ?? [];
    if (!is_array($rules) || $rules === []) {
        throw new RuntimeException('No public i18n include sources configured.');
    }

    $entries = ll_tools_public_i18n_parse_po_file($pot_path);
    $selected = [];

    foreach ($entries as $entry) {
        $msgid = (string) ($entry['msgid'] ?? '');
        if ($msgid === '') {
            continue;
        }

        $public_refs = [];
        foreach ((array) ($entry['references'] ?? []) as $reference) {
            foreach ($rules as $rule) {
                if (is_array($rule) && ll_tools_public_i18n_reference_matches_rule((string) $reference, $rule)) {
                    $public_refs[] = (string) $reference;
                    break;
                }
            }
        }

        if ($public_refs === []) {
            continue;
        }

        $public_refs = array_values(array_unique($public_refs));
        sort($public_refs, SORT_STRING);

        $all_refs = array_values(array_unique(array_map('strval', (array) ($entry['references'] ?? []))));
        sort($all_refs, SORT_STRING);

        $key = ll_tools_public_i18n_entry_key($entry);
        $selected_entry = [
            'key' => $key,
            'context' => $entry['context'] === null ? '' : (string) $entry['context'],
            'msgid' => $msgid,
            'msgid_plural' => $entry['msgid_plural'] === null ? null : (string) $entry['msgid_plural'],
            'references' => $all_refs,
            'public_references' => $public_refs,
        ];

        if (isset($selected[$key])) {
            $selected[$key]['references'] = array_values(array_unique(array_merge(
                (array) ($selected[$key]['references'] ?? []),
                $selected_entry['references']
            )));
            sort($selected[$key]['references'], SORT_STRING);

            $selected[$key]['public_references'] = array_values(array_unique(array_merge(
                (array) ($selected[$key]['public_references'] ?? []),
                $selected_entry['public_references']
            )));
            sort($selected[$key]['public_references'], SORT_STRING);
            continue;
        }

        $selected[$key] = $selected_entry;
    }

    $selected = array_values($selected);

    usort($selected, static function (array $left, array $right): int {
        $left_ref = (string) ($left['public_references'][0] ?? '');
        $right_ref = (string) ($right['public_references'][0] ?? '');
        return [$left_ref, (string) $left['msgid'], (string) $left['context']]
            <=> [$right_ref, (string) $right['msgid'], (string) $right['context']];
    });

    return $selected;
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_build_manifest(array $entries, array $config): array
{
    return [
        'schema_version' => (int) ($config['schema_version'] ?? 1),
        'text_domain' => (string) ($config['text_domain'] ?? 'll-tools-text-domain'),
        'source_policy' => (string) ($config['policy'] ?? ''),
        'source_config' => 'languages/tier2-public-ui-sources.php',
        'source_pot' => (string) ($config['pot_file'] ?? 'languages/ll-tools-text-domain.pot'),
        'entry_count' => count($entries),
        'entries' => $entries,
    ];
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_load_manifest(string $manifest_path): array
{
    if (!is_file($manifest_path)) {
        throw new RuntimeException("Public i18n manifest not found: {$manifest_path}");
    }

    $json = file_get_contents($manifest_path);
    if ($json === false) {
        throw new RuntimeException("Unable to read public i18n manifest: {$manifest_path}");
    }

    $manifest = json_decode($json, true);
    if (!is_array($manifest)) {
        throw new RuntimeException("Invalid public i18n manifest JSON: {$manifest_path}");
    }

    return $manifest;
}

function ll_tools_public_i18n_write_manifest(string $manifest_path, array $manifest): void
{
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode public i18n manifest.');
    }

    if (file_put_contents($manifest_path, $json . "\n") === false) {
        throw new RuntimeException("Unable to write public i18n manifest: {$manifest_path}");
    }
}

/**
 * @return array{ok:bool,missing_from_manifest:array<int,string>,stale_in_manifest:array<int,string>,changed_entries:array<int,string>}
 */
function ll_tools_public_i18n_compare_manifest_entries(array $manifest_entries, array $selected_entries): array
{
    $manifest_by_key = [];
    foreach ($manifest_entries as $entry) {
        if (is_array($entry) && isset($entry['key'])) {
            $manifest_by_key[(string) $entry['key']] = $entry;
        }
    }

    $selected_by_key = [];
    foreach ($selected_entries as $entry) {
        if (isset($entry['key'])) {
            $selected_by_key[(string) $entry['key']] = $entry;
        }
    }

    $missing = array_values(array_diff(array_keys($selected_by_key), array_keys($manifest_by_key)));
    $stale = array_values(array_diff(array_keys($manifest_by_key), array_keys($selected_by_key)));
    $changed = [];

    foreach (array_intersect(array_keys($selected_by_key), array_keys($manifest_by_key)) as $key) {
        if ($selected_by_key[$key] != $manifest_by_key[$key]) {
            $changed[] = $key;
        }
    }

    sort($missing, SORT_STRING);
    sort($stale, SORT_STRING);
    sort($changed, SORT_STRING);

    return [
        'ok' => $missing === [] && $stale === [] && $changed === [],
        'missing_from_manifest' => $missing,
        'stale_in_manifest' => $stale,
        'changed_entries' => $changed,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function ll_tools_public_i18n_entries_by_key(array $entries): array
{
    $by_key = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = isset($entry['key']) ? (string) $entry['key'] : ll_tools_public_i18n_entry_key($entry);
        $by_key[$key] = $entry;
    }

    return $by_key;
}

function ll_tools_public_i18n_plural_count_from_entries(array $entries): int
{
    foreach ($entries as $entry) {
        if (($entry['msgid'] ?? null) !== '') {
            continue;
        }

        $header = (string) (($entry['msgstr'][0] ?? ''));
        if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)/i', $header, $matches)) {
            return max(1, (int) $matches[1]);
        }
    }

    return 0;
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_check_locale_coverage(string $locale, array $manifest_entries, string $po_path): array
{
    if (!is_file($po_path)) {
        return [
            'locale' => $locale,
            'po_file' => $po_path,
            'exists' => false,
            'covered' => 0,
            'missing' => count($manifest_entries),
            'untranslated' => 0,
            'total' => count($manifest_entries),
            'complete' => false,
            'missing_keys' => array_values(array_map(static fn (array $entry): string => (string) $entry['key'], $manifest_entries)),
            'untranslated_keys' => [],
        ];
    }

    $po_entries = ll_tools_public_i18n_parse_po_file($po_path);
    $plural_count = ll_tools_public_i18n_plural_count_from_entries($po_entries);
    $po_by_key = [];
    foreach ($po_entries as $entry) {
        $msgid = (string) ($entry['msgid'] ?? '');
        if ($msgid === '') {
            continue;
        }
        $po_by_key[ll_tools_public_i18n_entry_key($entry)] = $entry;
    }

    $missing = [];
    $untranslated = [];
    $covered = 0;

    foreach ($manifest_entries as $manifest_entry) {
        $key = (string) ($manifest_entry['key'] ?? '');
        if ($key === '' || !isset($po_by_key[$key])) {
            $missing[] = $key;
            continue;
        }

        $po_entry = $po_by_key[$key];
        if (in_array('fuzzy', (array) ($po_entry['flags'] ?? []), true)) {
            $untranslated[] = $key;
            continue;
        }

        $msgstr = (array) ($po_entry['msgstr'] ?? []);
        $has_plural = isset($manifest_entry['msgid_plural']) && $manifest_entry['msgid_plural'] !== null;
        $translated = false;

        if ($has_plural) {
            $required_plural_count = $plural_count > 0 ? $plural_count : count($msgstr);
            $translated = $required_plural_count > 0;
            for ($index = 0; $index < $required_plural_count; $index++) {
                $translation = $msgstr[$index] ?? '';
                if (trim((string) $translation) === '') {
                    $translated = false;
                    break;
                }
            }
        } else {
            $translated = isset($msgstr[0]) && trim((string) $msgstr[0]) !== '';
        }

        if ($translated) {
            $covered++;
        } else {
            $untranslated[] = $key;
        }
    }

    return [
        'locale' => $locale,
        'po_file' => $po_path,
        'exists' => true,
        'covered' => $covered,
        'missing' => count($missing),
        'untranslated' => count($untranslated),
        'total' => count($manifest_entries),
        'complete' => $missing === [] && $untranslated === [],
        'missing_keys' => $missing,
        'untranslated_keys' => $untranslated,
    ];
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_parse_cli_args(array $argv): array
{
    $args = [
        'update_manifest' => false,
        'all_tier2' => false,
        'fail_on_missing' => false,
        'details' => false,
        'format' => 'text',
        'locales' => [],
        'pot' => '',
        'manifest' => '',
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        if ($arg === '--update-manifest') {
            $args['update_manifest'] = true;
        } elseif ($arg === '--all-tier2') {
            $args['all_tier2'] = true;
        } elseif ($arg === '--fail-on-missing') {
            $args['fail_on_missing'] = true;
        } elseif ($arg === '--details') {
            $args['details'] = true;
        } elseif ($arg === '--json' || $arg === '--format=json') {
            $args['format'] = 'json';
        } elseif (str_starts_with($arg, '--locale=')) {
            $args['locales'][] = substr($arg, strlen('--locale='));
        } elseif ($arg === '--locale' && isset($argv[$i + 1])) {
            $args['locales'][] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--pot=')) {
            $args['pot'] = substr($arg, strlen('--pot='));
        } elseif (str_starts_with($arg, '--manifest=')) {
            $args['manifest'] = substr($arg, strlen('--manifest='));
        } elseif ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
        } else {
            throw new InvalidArgumentException("Unknown argument: {$arg}");
        }
    }

    $args['locales'] = array_values(array_unique(array_filter(array_map('strval', $args['locales']))));

    return $args;
}

function ll_tools_public_i18n_usage(): string
{
    return implode("\n", [
        'Usage: php scripts/check-public-i18n.php [options]',
        '',
        'Options:',
        '  --update-manifest      Regenerate languages/tier2-public-ui-strings.json from the current POT.',
        '  --locale=LOCALE        Check public-string coverage for one locale PO file.',
        '  --all-tier2            Check every planned tier-2 locale from the source config.',
        '  --fail-on-missing      Exit non-zero when requested locale coverage is incomplete.',
        '  --json                 Emit a machine-readable JSON report.',
        '  --details              Include per-string missing/untranslated keys in JSON output.',
        '  --pot=PATH             Override the POT path.',
        '  --manifest=PATH        Override the manifest path.',
    ]) . "\n";
}

function ll_tools_public_i18n_display_path(string $root_dir, string $path): string
{
    $root = rtrim(ll_tools_public_i18n_normalize_path($root_dir), '/') . '/';
    $path = ll_tools_public_i18n_normalize_path($path);
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}

function ll_tools_public_i18n_run(array $argv, string $root_dir = ''): int
{
    $root_dir = $root_dir !== '' ? rtrim($root_dir, "\\/") : ll_tools_public_i18n_root_dir();
    $args = ll_tools_public_i18n_parse_cli_args($argv);
    if (!empty($args['help'])) {
        echo ll_tools_public_i18n_usage();
        return 0;
    }

    $config = ll_tools_public_i18n_load_config($root_dir);
    $pot_path = (string) ($args['pot'] ?: ($root_dir . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['pot_file'])));
    $manifest_path = (string) ($args['manifest'] ?: ($root_dir . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file'])));
    $selected_entries = ll_tools_public_i18n_select_public_entries($pot_path, $config);

    if (!empty($args['update_manifest'])) {
        ll_tools_public_i18n_write_manifest(
            $manifest_path,
            ll_tools_public_i18n_build_manifest($selected_entries, $config)
        );
    }

    $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
    $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
    $comparison = ll_tools_public_i18n_compare_manifest_entries($manifest_entries, $selected_entries);

    $locales = (array) $args['locales'];
    if (!empty($args['all_tier2'])) {
        $locales = array_values(array_unique(array_merge(
            $locales,
            array_keys(is_array($config['tier2_locales'] ?? null) ? $config['tier2_locales'] : [])
        )));
    }

    $coverage = [];
    foreach ($locales as $locale) {
        $locale = preg_replace('/[^A-Za-z0-9_]/', '', (string) $locale);
        if ($locale === '') {
            continue;
        }
        $po_path = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.po';
        $coverage[$locale] = ll_tools_public_i18n_check_locale_coverage($locale, $manifest_entries, $po_path);
    }

    if (empty($args['details'])) {
        foreach ($coverage as &$coverage_row) {
            unset($coverage_row['missing_keys'], $coverage_row['untranslated_keys']);
        }
        unset($coverage_row);
    }

    $result = [
        'manifest' => [
            'path' => ll_tools_public_i18n_display_path($root_dir, $manifest_path),
            'entry_count' => count($manifest_entries),
            'current_selection_count' => count($selected_entries),
            'matches_current_pot' => (bool) $comparison['ok'],
            'missing_from_manifest' => count($comparison['missing_from_manifest']),
            'stale_in_manifest' => count($comparison['stale_in_manifest']),
            'changed_entries' => count($comparison['changed_entries']),
        ],
        'coverage' => $coverage,
    ];

    $coverage_incomplete = false;
    foreach ($coverage as $row) {
        if (empty($row['complete'])) {
            $coverage_incomplete = true;
            break;
        }
    }

    if ($args['format'] === 'json') {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo 'Public UI manifest: ' . count($manifest_entries) . ' entries';
        echo ' (' . ($comparison['ok'] ? 'matches current POT selection' : 'stale') . ")\n";
        if (!$comparison['ok']) {
            echo '  Missing from manifest: ' . count($comparison['missing_from_manifest']) . "\n";
            echo '  Stale in manifest: ' . count($comparison['stale_in_manifest']) . "\n";
            echo '  Changed entries: ' . count($comparison['changed_entries']) . "\n";
            echo "  Refresh with: php scripts/check-public-i18n.php --update-manifest\n";
        }
        foreach ($coverage as $locale => $row) {
            $label = (string) ($config['tier2_locales'][$locale]['label'] ?? $locale);
            $status = (string) ($config['tier2_locales'][$locale]['status'] ?? 'checked');
            if (empty($row['exists'])) {
                echo "- {$locale} ({$label}, {$status}): PO file missing; 0/" . (int) $row['total'] . " covered\n";
                continue;
            }
            echo "- {$locale} ({$label}, {$status}): " . (int) $row['covered'] . '/' . (int) $row['total'] . ' covered';
            if (!empty($row['complete'])) {
                echo " complete\n";
            } else {
                echo '; missing ' . (int) $row['missing'] . ', untranslated ' . (int) $row['untranslated'] . "\n";
            }
        }
    }

    if (!$comparison['ok']) {
        return 1;
    }

    if (!empty($args['fail_on_missing']) && $coverage_incomplete) {
        return 1;
    }

    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        exit(ll_tools_public_i18n_run($argv));
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage() . "\n");
        exit(1);
    }
}
