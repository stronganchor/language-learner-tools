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
 * Resolve named PHP functions to their inclusive source line ranges.
 *
 * @return array<string, array{0:int,1:int}>
 */
function ll_tools_public_i18n_source_function_ranges(string $source_path): array
{
    $source = file_get_contents($source_path);
    if ($source === false) {
        throw new RuntimeException("Unable to read public i18n source: {$source_path}");
    }

    $tokens = token_get_all($source);
    $ranges = [];
    $token_count = count($tokens);

    for ($index = 0; $index < $token_count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $function_line = (int) $token[2];
        $name = '';
        $open_index = null;
        $expect_name = true;
        for ($cursor = $index + 1; $cursor < $token_count; $cursor++) {
            $candidate = $tokens[$cursor];
            if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $is_ampersand = $candidate === '&'
                || (is_array($candidate) && defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && in_array(
                    $candidate[0],
                    [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG],
                    true
                ));
            if ($expect_name && $is_ampersand) {
                continue;
            }
            if ($expect_name && is_array($candidate) && $candidate[0] === T_STRING) {
                $name = (string) $candidate[1];
                $expect_name = false;
                continue;
            }
            if ($expect_name) {
                break;
            }
            if ($candidate === '{') {
                $open_index = $cursor;
                break;
            }
            if ($candidate === ';') {
                break;
            }
        }

        // Anonymous functions and abstract/interface method declarations do not
        // provide stable source-policy symbols.
        if ($name === '' || $open_index === null) {
            continue;
        }
        if (isset($ranges[$name])) {
            throw new RuntimeException("Duplicate named function in public i18n source {$source_path}: {$name}");
        }

        $depth = 0;
        $line = $function_line;
        $end_line = null;
        for ($cursor = $open_index; $cursor < $token_count; $cursor++) {
            $candidate = $tokens[$cursor];
            $candidate_text = is_array($candidate) ? (string) $candidate[1] : (string) $candidate;
            if (is_array($candidate)) {
                $line = (int) $candidate[2];
            }

            if ($candidate === '{') {
                $depth++;
            } elseif ($candidate === '}') {
                $depth--;
                if ($depth === 0) {
                    $end_line = $line;
                    break;
                }
            }

            $line += substr_count($candidate_text, "\n");
        }

        if ($end_line === null) {
            throw new RuntimeException("Unable to resolve the end of public i18n source function {$name} in {$source_path}");
        }

        $ranges[$name] = [$function_line, $end_line];
    }

    return $ranges;
}

/**
 * @param string[] $lines
 */
function ll_tools_public_i18n_find_unique_anchor_line(
    array $lines,
    int $start_line,
    int $end_line,
    string $anchor,
    string $description
): int {
    if ($anchor === '') {
        throw new RuntimeException("Empty public i18n source anchor: {$description}");
    }

    $matches = [];
    for ($line = $start_line; $line <= $end_line; $line++) {
        if (strpos((string) ($lines[$line - 1] ?? ''), $anchor) !== false) {
            $matches[] = $line;
        }
    }

    if (count($matches) !== 1) {
        throw new RuntimeException(sprintf(
            'Expected exactly one public i18n source anchor for %s; found %d.',
            $description,
            count($matches)
        ));
    }

    return $matches[0];
}

/**
 * Expand named functions and anchor-bounded regions into concrete line ranges.
 *
 * @param array<int, array<string, mixed>> $rules
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_expand_source_rules(array $rules, string $root_dir): array
{
    $root_dir = rtrim($root_dir, "\\/");
    $expanded = [];

    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }

        $symbols = $rule['symbols'] ?? [];
        $regions = $rule['regions'] ?? [];
        $has_semantic_selectors = (is_array($symbols) && $symbols !== [])
            || (is_array($regions) && $regions !== []);
        if (!$has_semantic_selectors) {
            $expanded[] = $rule;
            continue;
        }

        $path = ll_tools_public_i18n_normalize_path((string) ($rule['path'] ?? ''));
        if ($path === '' || strpbrk($path, '*?[') !== false) {
            throw new RuntimeException('Semantic public i18n source selectors require one exact source path.');
        }

        $source_path = $root_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($source_path)) {
            throw new RuntimeException("Public i18n semantic source not found: {$source_path}");
        }

        $function_ranges = ll_tools_public_i18n_source_function_ranges($source_path);
        $ranges = is_array($rule['ranges'] ?? null) ? $rule['ranges'] : [];
        foreach ($symbols as $symbol) {
            $symbol = (string) $symbol;
            if (!isset($function_ranges[$symbol])) {
                throw new RuntimeException("Public i18n source function not found in {$path}: {$symbol}");
            }
            $ranges[] = $function_ranges[$symbol];
        }

        $lines = file($source_path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Unable to read public i18n source lines: {$source_path}");
        }
        foreach ($regions as $region_index => $region) {
            if (!is_array($region)) {
                throw new RuntimeException("Invalid public i18n source region in {$path} at index {$region_index}");
            }

            $symbol = (string) ($region['symbol'] ?? '');
            if ($symbol !== '' && !isset($function_ranges[$symbol])) {
                throw new RuntimeException("Public i18n region function not found in {$path}: {$symbol}");
            }

            [$function_start, $function_end] = $symbol !== ''
                ? $function_ranges[$symbol]
                : [1, count($lines)];
            $name = (string) ($region['name'] ?? "region {$region_index}");
            $scope_description = $symbol !== '' ? $symbol : '(file)';
            $start = ll_tools_public_i18n_find_unique_anchor_line(
                $lines,
                $function_start,
                $function_end,
                (string) ($region['start'] ?? ''),
                "{$path} {$scope_description} {$name} start"
            );
            $end = ll_tools_public_i18n_find_unique_anchor_line(
                $lines,
                $function_start,
                $function_end,
                (string) ($region['end'] ?? ''),
                "{$path} {$scope_description} {$name} end"
            );
            if ($end < $start) {
                throw new RuntimeException("Public i18n source region ends before it starts: {$path} {$scope_description} {$name}");
            }
            $ranges[] = [$start, $end];
        }

        $normalized_ranges = [];
        foreach ($ranges as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }
            $normalized_ranges[(int) $range[0] . ':' . (int) $range[1]] = [(int) $range[0], (int) $range[1]];
        }
        $normalized_ranges = array_values($normalized_ranges);
        usort($normalized_ranges, static fn (array $left, array $right): int => $left <=> $right);

        unset($rule['symbols'], $rule['regions']);
        $rule['ranges'] = $normalized_ranges;
        $expanded[] = $rule;
    }

    return $expanded;
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
function ll_tools_public_i18n_select_public_entries(string $pot_path, array $config, string $root_dir = ''): array
{
    $rules = $config['include_sources'] ?? [];
    if (!is_array($rules) || $rules === []) {
        throw new RuntimeException('No public i18n include sources configured.');
    }
    $root_dir = $root_dir !== '' ? rtrim($root_dir, "\\/") : ll_tools_public_i18n_root_dir();
    $rules = ll_tools_public_i18n_expand_source_rules($rules, $root_dir);

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

function ll_tools_public_i18n_escape_po_string(string $value): string
{
    return str_replace(
        ["\\", "\"", "\t", "\r", "\n"],
        ["\\\\", "\\\"", "\\t", "\\r", "\\n"],
        $value
    );
}

function ll_tools_public_i18n_po_line(string $keyword, string $value): string
{
    return $keyword . ' "' . ll_tools_public_i18n_escape_po_string($value) . '"';
}

/**
 * @return string[]
 */
function ll_tools_public_i18n_po_header_lines(string $locale, array $config): array
{
    $locale_config = is_array($config['tier2_locales'][$locale] ?? null) ? $config['tier2_locales'][$locale] : [];
    $plural_forms = trim((string) ($locale_config['plural_forms'] ?? ''));
    if ($plural_forms === '') {
        $plural_forms = 'nplurals=2; plural=(n != 1);';
    }
    if (!str_ends_with($plural_forms, ';')) {
        $plural_forms .= ';';
    }

    return [
        'Project-Id-Version: Language Learner Tools Public UI',
        'Report-Msgid-Bugs-To: ',
        'POT-Creation-Date: ',
        'PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE',
        'Last-Translator: ',
        'Language-Team: ',
        'Language: ' . $locale,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Plural-Forms: ' . $plural_forms,
        'X-Generator: LL Tools public i18n manifest',
    ];
}

function ll_tools_public_i18n_build_po_header(string $locale, array $config): string
{
    $lines = [
        '# LL Tools tier-2 public UI translations.',
        '# This file is generated from languages/tier2-public-ui-strings.json.',
        'msgid ""',
        'msgstr ""',
    ];

    foreach (ll_tools_public_i18n_po_header_lines($locale, $config) as $header_line) {
        $lines[] = '"' . ll_tools_public_i18n_escape_po_string($header_line . "\n") . '"';
    }

    return implode("\n", $lines);
}

function ll_tools_public_i18n_plural_count_from_plural_forms(string $plural_forms): int
{
    if (preg_match('/nplurals\s*=\s*(\d+)/i', $plural_forms, $matches)) {
        return max(1, (int) $matches[1]);
    }

    return 2;
}

function ll_tools_public_i18n_plural_count_for_locale(string $locale, array $config): int
{
    $locale_config = is_array($config['tier2_locales'][$locale] ?? null) ? $config['tier2_locales'][$locale] : [];
    $plural_forms = trim((string) ($locale_config['plural_forms'] ?? ''));
    if ($plural_forms === '') {
        $plural_forms = 'nplurals=2; plural=(n != 1);';
    }

    return ll_tools_public_i18n_plural_count_from_plural_forms($plural_forms);
}

function ll_tools_public_i18n_build_po_for_locale(string $locale, array $manifest_entries, array $config): string
{
    $chunks = [ll_tools_public_i18n_build_po_header($locale, $config)];
    $plural_count = ll_tools_public_i18n_plural_count_for_locale($locale, $config);

    foreach ($manifest_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $lines = [];
        $key = (string) ($entry['key'] ?? '');
        if ($key !== '') {
            $lines[] = '#. ll-tools-public-key: ' . $key;
        }

        $references = (array) ($entry['public_references'] ?? ($entry['references'] ?? []));
        $references = array_values(array_unique(array_filter(array_map('strval', $references))));
        sort($references, SORT_STRING);
        if ($references !== []) {
            $lines[] = '#: ' . implode(' ', $references);
        }

        $context = (string) ($entry['context'] ?? '');
        if ($context !== '') {
            $lines[] = ll_tools_public_i18n_po_line('msgctxt', $context);
        }

        $lines[] = ll_tools_public_i18n_po_line('msgid', (string) ($entry['msgid'] ?? ''));
        if (array_key_exists('msgid_plural', $entry) && $entry['msgid_plural'] !== null) {
            $lines[] = ll_tools_public_i18n_po_line('msgid_plural', (string) $entry['msgid_plural']);
            for ($index = 0; $index < $plural_count; $index++) {
                $lines[] = 'msgstr[' . $index . '] ""';
            }
        } else {
            $lines[] = 'msgstr ""';
        }

        $chunks[] = implode("\n", $lines);
    }

    return implode("\n\n", $chunks) . "\n";
}

function ll_tools_public_i18n_write_po_for_locale(string $po_path, string $locale, array $manifest_entries, array $config, bool $force = false): void
{
    if (is_file($po_path) && !$force) {
        throw new RuntimeException("PO file already exists: {$po_path}. Use --force to overwrite.");
    }

    $po = ll_tools_public_i18n_build_po_for_locale($locale, $manifest_entries, $config);
    if (file_put_contents($po_path, $po) === false) {
        throw new RuntimeException("Unable to write PO file: {$po_path}");
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

/**
 * Convert every active POT entry into the same keyed shape used by the public
 * manifest checker. Full-locale catalogs such as Turkish use this broader
 * contract for admin/plugin copy as well as learner-facing strings.
 *
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_catalog_entries_from_pot(string $pot_path): array
{
    $catalog_entries = [];
    foreach (ll_tools_public_i18n_parse_po_file($pot_path) as $entry) {
        if ((string) ($entry['msgid'] ?? '') === '') {
            continue;
        }

        $entry['key'] = ll_tools_public_i18n_entry_key($entry);
        $catalog_entries[] = $entry;
    }

    return $catalog_entries;
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
 * @return string[]
 */
function ll_tools_public_i18n_extract_printf_placeholders(string $text): array
{
    preg_match_all('/(?<!%)%(?:\d+\$)?[+\-0# ]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]/', $text, $matches);
    return $matches[0] ?? [];
}

/**
 * @return string[]
 */
function ll_tools_public_i18n_extract_brace_placeholders(string $text): array
{
    preg_match_all('/\{[A-Za-z_][A-Za-z0-9_.-]*\}/', $text, $matches);
    return $matches[0] ?? [];
}

/**
 * @return string[]
 */
function ll_tools_public_i18n_extract_cli_options(string $text): array
{
    preg_match_all('/(?<![A-Za-z0-9_-])--[A-Za-z0-9][A-Za-z0-9_-]*/', $text, $matches);
    return $matches[0] ?? [];
}

/**
 * @return string[]
 */
function ll_tools_public_i18n_extract_urls(string $text): array
{
    preg_match_all('#https?://[^\s"\'<>)\]]+#', $text, $matches);
    return $matches[0] ?? [];
}

/**
 * @return string[]
 */
function ll_tools_public_i18n_extract_shortcode_tokens(string $text): array
{
    preg_match_all('/\[(?:\/)?[A-Za-z][A-Za-z0-9_-]*(?:\s+[^\]]*)?\]/', $text, $matches);
    return $matches[0] ?? [];
}

/**
 * @return string[]
 */
function ll_tools_public_i18n_extract_html_tag_tokens(string $text): array
{
    preg_match_all('/<\s*(\/)?\s*([A-Za-z][A-Za-z0-9:-]*)\b[^>]*(\/)?\s*>/', $text, $matches, PREG_SET_ORDER);
    $tokens = [];
    foreach ($matches as $match) {
        $tag = strtolower((string) ($match[2] ?? ''));
        if ($tag === '') {
            continue;
        }

        $type = !empty($match[1]) ? 'close' : (!empty($match[3]) ? 'self' : 'open');
        $tokens[] = $type . ':' . $tag;
    }

    return $tokens;
}

/**
 * @return array<string, int>
 */
function ll_tools_public_i18n_token_counts(array $tokens): array
{
    $counts = array_count_values(array_map('strval', $tokens));
    ksort($counts, SORT_STRING);
    return $counts;
}

/**
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_validate_translation_string(array $manifest_entry, string $translation, int $plural_index = 0): array
{
    $msgid = (string) ($manifest_entry['msgid'] ?? '');
    $msgid_plural = array_key_exists('msgid_plural', $manifest_entry) && $manifest_entry['msgid_plural'] !== null
        ? (string) $manifest_entry['msgid_plural']
        : null;
    $source = ($msgid_plural !== null && $plural_index > 0) ? $msgid_plural : $msgid;
    $key = (string) ($manifest_entry['key'] ?? ll_tools_public_i18n_entry_key([
        'context' => (string) ($manifest_entry['context'] ?? ''),
        'msgid' => $msgid,
        'msgid_plural' => $msgid_plural,
    ]));

    $checks = [
        'printf_placeholders' => [
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_printf_placeholders($source)),
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_printf_placeholders($translation)),
        ],
        'brace_placeholders' => [
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_brace_placeholders($source)),
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_brace_placeholders($translation)),
        ],
        'cli_options' => [
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_cli_options($source)),
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_cli_options($translation)),
        ],
        'urls' => [
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_urls($source)),
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_urls($translation)),
        ],
        'shortcodes' => [
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_shortcode_tokens($source)),
            ll_tools_public_i18n_token_counts(ll_tools_public_i18n_extract_shortcode_tokens($translation)),
        ],
        'html_tags' => [
            ll_tools_public_i18n_extract_html_tag_tokens($source),
            ll_tools_public_i18n_extract_html_tag_tokens($translation),
        ],
    ];

    $errors = [];
    foreach ($checks as $type => [$expected, $actual]) {
        if ($expected === $actual) {
            continue;
        }

        $errors[] = [
            'key' => $key,
            'type' => $type,
            'plural_index' => $plural_index,
            'expected' => $expected,
            'actual' => $actual,
        ];
    }

    if (substr_count($source, "\n") !== substr_count($translation, "\n")) {
        $errors[] = [
            'key' => $key,
            'type' => 'newline_count',
            'plural_index' => $plural_index,
            'expected' => substr_count($source, "\n"),
            'actual' => substr_count($translation, "\n"),
        ];
    }

    return $errors;
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
            'validation_error_count' => 0,
            'validation_errors' => [],
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
    $validation_errors = [];
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
            $entry_validation_errors = [];
            if ($has_plural) {
                $required_plural_count = $plural_count > 0 ? $plural_count : count($msgstr);
                for ($index = 0; $index < $required_plural_count; $index++) {
                    $entry_validation_errors = array_merge(
                        $entry_validation_errors,
                        ll_tools_public_i18n_validate_translation_string($manifest_entry, (string) ($msgstr[$index] ?? ''), $index)
                    );
                }
            } else {
                $entry_validation_errors = ll_tools_public_i18n_validate_translation_string($manifest_entry, (string) ($msgstr[0] ?? ''), 0);
            }

            if ($entry_validation_errors === []) {
                $covered++;
            } else {
                $validation_errors = array_merge($validation_errors, $entry_validation_errors);
                $untranslated[] = $key;
            }
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
        'complete' => $missing === [] && $untranslated === [] && $validation_errors === [],
        'missing_keys' => $missing,
        'untranslated_keys' => $untranslated,
        'validation_error_count' => count($validation_errors),
        'validation_errors' => $validation_errors,
    ];
}

/**
 * Full locales require an exact active POT/PO key set in addition to complete
 * translations. Public tier-2 catalogs intentionally allow supplemental admin
 * entries, so this stricter contract is kept separate from public coverage.
 *
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_check_full_catalog_coverage(string $locale, array $catalog_entries, string $po_path): array
{
    $coverage = ll_tools_public_i18n_check_locale_coverage($locale, $catalog_entries, $po_path);
    $coverage += [
        'stale' => 0,
        'duplicates' => 0,
        'fuzzy' => 0,
        'stale_keys' => [],
        'duplicate_keys' => [],
        'fuzzy_keys' => [],
    ];
    if (empty($coverage['exists'])) {
        return $coverage;
    }

    $catalog_by_key = ll_tools_public_i18n_entries_by_key($catalog_entries);
    $po_entries = ll_tools_public_i18n_parse_po_file($po_path);
    $po_key_counts = [];
    $fuzzy_keys = [];
    foreach ($po_entries as $entry) {
        if ((string) ($entry['msgid'] ?? '') === '') {
            continue;
        }
        $key = ll_tools_public_i18n_entry_key($entry);
        $po_key_counts[$key] = (int) ($po_key_counts[$key] ?? 0) + 1;
        if (in_array('fuzzy', (array) ($entry['flags'] ?? []), true)) {
            $fuzzy_keys[$key] = true;
        }
    }

    $stale_keys = array_values(array_diff(array_keys($po_key_counts), array_keys($catalog_by_key)));
    $duplicate_keys = array_values(array_keys(array_filter(
        $po_key_counts,
        static fn (int $count): bool => $count > 1
    )));
    $fuzzy_keys = array_values(array_keys($fuzzy_keys));
    sort($stale_keys, SORT_STRING);
    sort($duplicate_keys, SORT_STRING);
    sort($fuzzy_keys, SORT_STRING);

    $coverage['stale'] = count($stale_keys);
    $coverage['duplicates'] = count($duplicate_keys);
    $coverage['fuzzy'] = count($fuzzy_keys);
    $coverage['stale_keys'] = $stale_keys;
    $coverage['duplicate_keys'] = $duplicate_keys;
    $coverage['fuzzy_keys'] = $fuzzy_keys;
    $coverage['complete'] = !empty($coverage['complete'])
        && $stale_keys === []
        && $duplicate_keys === []
        && $fuzzy_keys === [];

    return $coverage;
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
        'catalog_locales' => [],
        'generate_po' => '',
        'force' => false,
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
        } elseif ($arg === '--force') {
            $args['force'] = true;
        } elseif (str_starts_with($arg, '--generate-po=')) {
            $args['generate_po'] = substr($arg, strlen('--generate-po='));
        } elseif ($arg === '--generate-po' && isset($argv[$i + 1])) {
            $args['generate_po'] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--locale=')) {
            $args['locales'][] = substr($arg, strlen('--locale='));
        } elseif ($arg === '--locale' && isset($argv[$i + 1])) {
            $args['locales'][] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--full-catalog=')) {
            $args['catalog_locales'][] = substr($arg, strlen('--full-catalog='));
        } elseif ($arg === '--full-catalog' && isset($argv[$i + 1])) {
            $args['catalog_locales'][] = (string) $argv[++$i];
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
    $args['catalog_locales'] = array_values(array_unique(array_filter(array_map('strval', $args['catalog_locales']))));
    $args['generate_po'] = preg_replace('/[^A-Za-z0-9_]/', '', (string) $args['generate_po']);

    return $args;
}

function ll_tools_public_i18n_usage(): string
{
    return implode("\n", [
        'Usage: php scripts/check-public-i18n.php [options]',
        '',
        'Options:',
        '  --update-manifest      Regenerate languages/tier2-public-ui-strings.json from the current POT.',
        '  --generate-po=LOCALE   Generate an untranslated tier-2 PO scaffold from the public manifest.',
        '  --force                Allow --generate-po to overwrite an existing PO file.',
        '  --locale=LOCALE        Check public-string coverage for one locale PO file.',
        '  --full-catalog=LOCALE  Check every active POT entry for a full-translation locale.',
        '  --all-tier2            Check every planned tier-2 locale from the source config.',
        '  --fail-on-missing      Exit non-zero when requested coverage or compiled assets are incomplete.',
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

/**
 * Read a GNU MO file into the same runtime-key/value shape used by WordPress'
 * generated PHP catalogs. This keeps the database-free guard independent of a
 * loaded WordPress runtime.
 *
 * @return array<string, string>
 */
function ll_tools_public_i18n_read_mo_messages(string $mo_path): array
{
    $data = file_get_contents($mo_path);
    if (!is_string($data) || strlen($data) < 28) {
        throw new RuntimeException('Compiled MO catalog is unreadable or truncated.');
    }

    $little_magic = unpack('Vmagic', substr($data, 0, 4));
    $big_magic = unpack('Nmagic', substr($data, 0, 4));
    if ((int) ($little_magic['magic'] ?? 0) === 0x950412de) {
        $format = 'V';
    } elseif ((int) ($big_magic['magic'] ?? 0) === 0x950412de) {
        $format = 'N';
    } else {
        throw new RuntimeException('Compiled MO catalog has an invalid magic number.');
    }

    $header = unpack(
        $format . 'revision/' . $format . 'count/' . $format . 'originals_offset/' . $format . 'translations_offset',
        substr($data, 4, 16)
    );
    $count = (int) ($header['count'] ?? 0);
    $originals_offset = (int) ($header['originals_offset'] ?? 0);
    $translations_offset = (int) ($header['translations_offset'] ?? 0);
    $data_length = strlen($data);
    if (
        $count < 1
        || $originals_offset < 0
        || $translations_offset < 0
        || $count > intdiv($data_length, 8)
        || $originals_offset + ($count * 8) > $data_length
        || $translations_offset + ($count * 8) > $data_length
    ) {
        throw new RuntimeException('Compiled MO catalog has an invalid table header.');
    }

    $messages = [];
    for ($index = 0; $index < $count; $index++) {
        $original_meta_offset = $originals_offset + ($index * 8);
        $translation_meta_offset = $translations_offset + ($index * 8);
        if ($original_meta_offset + 8 > $data_length || $translation_meta_offset + 8 > $data_length) {
            throw new RuntimeException('Compiled MO catalog has a truncated string table.');
        }

        $original_meta = unpack($format . 'length/' . $format . 'offset', substr($data, $original_meta_offset, 8));
        $translation_meta = unpack($format . 'length/' . $format . 'offset', substr($data, $translation_meta_offset, 8));
        $original_length = (int) ($original_meta['length'] ?? -1);
        $original_offset = (int) ($original_meta['offset'] ?? -1);
        $translation_length = (int) ($translation_meta['length'] ?? -1);
        $translation_offset = (int) ($translation_meta['offset'] ?? -1);
        if (
            $original_length < 0
            || $original_offset < 0
            || $translation_length < 0
            || $translation_offset < 0
            || $original_offset + $original_length > $data_length
            || $translation_offset + $translation_length > $data_length
        ) {
            throw new RuntimeException('Compiled MO catalog contains an invalid string offset.');
        }

        $original = substr($data, $original_offset, $original_length);
        if ($original === '') {
            continue;
        }
        $runtime_key = explode("\0", $original, 2)[0];
        $messages[$runtime_key] = substr($data, $translation_offset, $translation_length);
    }

    return $messages;
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_compiled_asset_status(
    string $root_dir,
    string $locale,
    array $config,
    string $po_path = ''
): array
{
    $domain = (string) ($config['text_domain'] ?? 'll-tools-text-domain');
    $base = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . $domain . '-' . $locale;
    $mo_path = $base . '.mo';
    $php_path = $base . '.l10n.php';

    $status = [
        'mo_file' => ll_tools_public_i18n_display_path($root_dir, $mo_path),
        'php_file' => ll_tools_public_i18n_display_path($root_dir, $php_path),
        'mo_exists' => is_file($mo_path),
        'php_exists' => is_file($php_path),
        'mo_expected_messages' => 0,
        'mo_missing_messages' => 0,
        'mo_mismatched_messages' => 0,
        'mo_stale_messages' => 0,
        'mo_catalog_current' => false,
        'php_expected_messages' => 0,
        'php_missing_messages' => 0,
        'php_mismatched_messages' => 0,
        'php_stale_messages' => 0,
        'php_catalog_current' => false,
        'mo_error' => '',
        'php_error' => '',
    ];

    if (!is_file($mo_path) || !is_file($php_path)) {
        $status['complete'] = false;
        return $status;
    }

    if ($po_path === '' || !is_file($po_path)) {
        $status['complete'] = true;
        return $status;
    }

    $po_entries = ll_tools_public_i18n_parse_po_file($po_path);
    $plural_count = ll_tools_public_i18n_plural_count_from_entries($po_entries);
    $expected_messages = [];
    foreach ($po_entries as $entry) {
        $msgid = (string) ($entry['msgid'] ?? '');
        if ($msgid === '' || in_array('fuzzy', (array) ($entry['flags'] ?? []), true)) {
            continue;
        }

        $msgstr = array_map('strval', (array) ($entry['msgstr'] ?? []));
        $required = ($entry['msgid_plural'] ?? null) !== null ? max(1, $plural_count) : 1;
        $translations = [];
        for ($index = 0; $index < $required; $index++) {
            $translation = (string) ($msgstr[$index] ?? '');
            if (trim($translation) === '') {
                $translations = [];
                break;
            }
            $translations[] = $translation;
        }
        if ($translations === []) {
            continue;
        }

        $runtime_key = $msgid;
        if ((string) ($entry['context'] ?? '') !== '') {
            $runtime_key = (string) $entry['context'] . "\x04" . $runtime_key;
        }
        $expected_messages[$runtime_key] = implode("\0", $translations);
    }

    try {
        $mo_messages = ll_tools_public_i18n_read_mo_messages($mo_path);
        $missing = array_diff_key($expected_messages, $mo_messages);
        $stale = array_diff_key($mo_messages, $expected_messages);
        $mismatched = [];
        foreach (array_intersect_key($expected_messages, $mo_messages) as $key => $expected) {
            if ($mo_messages[$key] !== $expected) {
                $mismatched[$key] = true;
            }
        }

        $status['mo_expected_messages'] = count($expected_messages);
        $status['mo_missing_messages'] = count($missing);
        $status['mo_mismatched_messages'] = count($mismatched);
        $status['mo_stale_messages'] = count($stale);
        $status['mo_catalog_current'] = $missing === [] && $mismatched === [] && $stale === [];
    } catch (Throwable $error) {
        $status['mo_error'] = $error->getMessage();
    }

    try {
        $compiled = (static function (string $path) {
            return require $path;
        })($php_path);
        if (!is_array($compiled) || !is_array($compiled['messages'] ?? null)) {
            throw new RuntimeException('Compiled PHP catalog did not return a messages array.');
        }
        $compiled_messages = array_map('strval', $compiled['messages']);
        $missing = array_diff_key($expected_messages, $compiled_messages);
        $stale = array_diff_key($compiled_messages, $expected_messages);
        $mismatched = [];
        foreach (array_intersect_key($expected_messages, $compiled_messages) as $key => $expected) {
            if ($compiled_messages[$key] !== $expected) {
                $mismatched[$key] = true;
            }
        }

        $status['php_expected_messages'] = count($expected_messages);
        $status['php_missing_messages'] = count($missing);
        $status['php_mismatched_messages'] = count($mismatched);
        $status['php_stale_messages'] = count($stale);
        $status['php_catalog_current'] = $missing === [] && $mismatched === [] && $stale === [];
    } catch (Throwable $error) {
        $status['php_error'] = $error->getMessage();
    }

    $status['complete'] = !empty($status['mo_exists'])
        && !empty($status['php_exists'])
        && !empty($status['mo_catalog_current'])
        && !empty($status['php_catalog_current']);

    return $status;
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
    $selected_entries = ll_tools_public_i18n_select_public_entries($pot_path, $config, $root_dir);

    if (!empty($args['update_manifest'])) {
        ll_tools_public_i18n_write_manifest(
            $manifest_path,
            ll_tools_public_i18n_build_manifest($selected_entries, $config)
        );
    }

    $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
    $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
    $comparison = ll_tools_public_i18n_compare_manifest_entries($manifest_entries, $selected_entries);

    if (!empty($args['generate_po'])) {
        $generated_locale = (string) $args['generate_po'];
        if (!isset($config['tier2_locales'][$generated_locale])) {
            throw new InvalidArgumentException("Locale is not configured as a tier-2 locale: {$generated_locale}");
        }

        $po_path = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $generated_locale . '.po';
        ll_tools_public_i18n_write_po_for_locale($po_path, $generated_locale, $manifest_entries, $config, (bool) $args['force']);
        $args['locales'][] = $generated_locale;
    }

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
        $coverage[$locale]['translations_complete'] = $coverage[$locale]['complete'];
        $coverage[$locale]['compiled_assets'] = ll_tools_public_i18n_compiled_asset_status($root_dir, $locale, $config, $po_path);
        $coverage[$locale]['complete'] = !empty($coverage[$locale]['translations_complete'])
            && !empty($coverage[$locale]['compiled_assets']['complete']);
    }

    $catalog_entries = ll_tools_public_i18n_catalog_entries_from_pot($pot_path);
    $catalog_coverage = [];
    foreach ((array) $args['catalog_locales'] as $locale) {
        $locale = preg_replace('/[^A-Za-z0-9_]/', '', (string) $locale);
        if ($locale === '') {
            continue;
        }
        $po_path = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.po';
        $catalog_coverage[$locale] = ll_tools_public_i18n_check_full_catalog_coverage($locale, $catalog_entries, $po_path);
        $catalog_coverage[$locale]['translations_complete'] = $catalog_coverage[$locale]['complete'];
        $catalog_coverage[$locale]['compiled_assets'] = ll_tools_public_i18n_compiled_asset_status($root_dir, $locale, $config, $po_path);
        $catalog_coverage[$locale]['complete'] = !empty($catalog_coverage[$locale]['translations_complete'])
            && !empty($catalog_coverage[$locale]['compiled_assets']['complete']);
    }

    if (empty($args['details'])) {
        foreach ($coverage as &$coverage_row) {
            unset($coverage_row['missing_keys'], $coverage_row['untranslated_keys']);
            unset($coverage_row['validation_errors']);
        }
        unset($coverage_row);
        foreach ($catalog_coverage as &$coverage_row) {
            unset($coverage_row['missing_keys'], $coverage_row['untranslated_keys']);
            unset($coverage_row['validation_errors']);
            unset($coverage_row['stale_keys'], $coverage_row['duplicate_keys'], $coverage_row['fuzzy_keys']);
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
        'full_catalog_coverage' => $catalog_coverage,
    ];

    $coverage_incomplete = false;
    foreach ($coverage as $row) {
        if (empty($row['complete'])) {
            $coverage_incomplete = true;
            break;
        }
    }
    foreach ($catalog_coverage as $row) {
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
                echo '; missing ' . (int) $row['missing'] . ', untranslated ' . (int) $row['untranslated'];
                if (!empty($row['validation_error_count'])) {
                    echo ', validation errors ' . (int) $row['validation_error_count'];
                }
                if (empty($row['compiled_assets']['complete'])) {
                    echo ', compiled artifacts missing or stale';
                }
                echo "\n";
            }
        }
        foreach ($catalog_coverage as $locale => $row) {
            $label = (string) ($config['core_full_locales'][$locale] ?? $locale);
            if (empty($row['exists'])) {
                echo "- {$locale} ({$label}, full catalog): PO file missing; 0/" . (int) $row['total'] . " covered\n";
                continue;
            }
            echo "- {$locale} ({$label}, full catalog): " . (int) $row['covered'] . '/' . (int) $row['total'] . ' covered';
            if (!empty($row['complete'])) {
                echo " complete\n";
            } else {
                echo '; missing ' . (int) $row['missing'] . ', untranslated ' . (int) $row['untranslated'];
                echo ', stale ' . (int) ($row['stale'] ?? 0) . ', duplicates ' . (int) ($row['duplicates'] ?? 0);
                echo ', fuzzy ' . (int) ($row['fuzzy'] ?? 0);
                if (!empty($row['validation_error_count'])) {
                    echo ', validation errors ' . (int) $row['validation_error_count'];
                }
                if (empty($row['compiled_assets']['complete'])) {
                    echo ', compiled artifacts missing or stale';
                }
                echo "\n";
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
