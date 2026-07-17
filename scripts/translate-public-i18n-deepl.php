<?php
declare(strict_types=1);

/**
 * Translate a tier-2 public UI PO scaffold with DeepL.
 */

require_once __DIR__ . '/check-public-i18n.php';

function ll_tools_public_i18n_deepl_usage(): string
{
    return implode("\n", [
        'Usage: php scripts/translate-public-i18n-deepl.php --locale=LOCALE [options]',
        '',
        'Options:',
        '  --locale=LOCALE        Locale to translate, such as tr_TR or ru_RU.',
        '  --target=LANG          DeepL target language, default inferred from locale.',
        '  --source=LANG          DeepL source language, default EN.',
        '  --scope=public|catalog Translate the public manifest (default) or every active POT entry.',
        '  --batch-size=N         Texts per request, default 40.',
        '  --limit=N              Translate at most N currently empty strings for a test run.',
        '  --force                Create the PO scaffold first if it does not exist.',
        '',
        'Requires DEEPL_API_KEY in the environment. Set DEEPL_CAINFO if PHP cURL has no CA bundle.',
    ]) . "\n";
}

/**
 * @return array<string, mixed>
 */
function ll_tools_public_i18n_deepl_parse_args(array $argv): array
{
    $args = [
        'locale' => '',
        'target' => '',
        'source' => 'EN',
        'scope' => 'public',
        'batch_size' => 40,
        'limit' => 0,
        'force' => false,
        'help' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
        } elseif ($arg === '--force') {
            $args['force'] = true;
        } elseif ($arg === '--catalog') {
            $args['scope'] = 'catalog';
        } elseif (str_starts_with($arg, '--locale=')) {
            $args['locale'] = substr($arg, strlen('--locale='));
        } elseif ($arg === '--locale' && isset($argv[$i + 1])) {
            $args['locale'] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--target=')) {
            $args['target'] = substr($arg, strlen('--target='));
        } elseif ($arg === '--target' && isset($argv[$i + 1])) {
            $args['target'] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--source=')) {
            $args['source'] = substr($arg, strlen('--source='));
        } elseif ($arg === '--source' && isset($argv[$i + 1])) {
            $args['source'] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--scope=')) {
            $args['scope'] = substr($arg, strlen('--scope='));
        } elseif ($arg === '--scope' && isset($argv[$i + 1])) {
            $args['scope'] = (string) $argv[++$i];
        } elseif (str_starts_with($arg, '--batch-size=')) {
            $args['batch_size'] = (int) substr($arg, strlen('--batch-size='));
        } elseif ($arg === '--batch-size' && isset($argv[$i + 1])) {
            $args['batch_size'] = (int) $argv[++$i];
        } elseif (str_starts_with($arg, '--limit=')) {
            $args['limit'] = (int) substr($arg, strlen('--limit='));
        } elseif ($arg === '--limit' && isset($argv[$i + 1])) {
            $args['limit'] = (int) $argv[++$i];
        } else {
            throw new InvalidArgumentException("Unknown argument: {$arg}");
        }
    }

    $args['locale'] = preg_replace('/[^A-Za-z0-9_]/', '', (string) $args['locale']);
    $args['target'] = strtoupper(preg_replace('/[^A-Za-z_-]/', '', (string) $args['target']));
    $args['source'] = strtoupper(preg_replace('/[^A-Za-z_-]/', '', (string) $args['source']));
    $args['scope'] = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $args['scope']));
    if (!in_array($args['scope'], ['public', 'catalog'], true)) {
        throw new InvalidArgumentException('Scope must be public or catalog.');
    }
    $args['batch_size'] = max(1, min(50, (int) $args['batch_size']));
    $args['limit'] = max(0, (int) $args['limit']);

    return $args;
}

function ll_tools_public_i18n_deepl_target_from_locale(string $locale): string
{
    $map = [
        'ru_RU' => 'RU',
        'zh_CN' => 'ZH',
        'hi_IN' => 'HI',
        'es_ES' => 'ES',
        'ar' => 'AR',
        'fr_FR' => 'FR',
        'bn_BD' => 'BN',
        'pt_BR' => 'PT-BR',
        'id_ID' => 'ID',
    ];

    return $map[$locale] ?? strtoupper(strtok($locale, '_') ?: $locale);
}

/**
 * @return array<string, array<int, string>>
 */
function ll_tools_public_i18n_deepl_existing_translations(string $po_path): array
{
    if (!is_file($po_path)) {
        return [];
    }

    $translations = [];
    foreach (ll_tools_public_i18n_parse_po_file($po_path) as $entry) {
        $msgid = (string) ($entry['msgid'] ?? '');
        if ($msgid === '') {
            continue;
        }

        $key = ll_tools_public_i18n_entry_key($entry);
        $msgstr = (array) ($entry['msgstr'] ?? []);
        foreach ($msgstr as $index => $translation) {
            if (trim((string) $translation) !== '') {
                $translations[$key][(int) $index] = (string) $translation;
            }
        }
    }

    return $translations;
}

/**
 * @return array{0:string,1:array<int,string>}
 */
function ll_tools_public_i18n_deepl_protect_text(string $text): array
{
    $tokens = [];
    $pattern = '~https?://[^\s"\'<>)\]]+|(?<!%)%(?:\d+\$)?[+\-0# ]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]|\{[A-Za-z_][A-Za-z0-9_.-]*\}|(?<![A-Za-z0-9_-])--[A-Za-z0-9][A-Za-z0-9_-]*|\[(?:/)?[A-Za-z][A-Za-z0-9_-]*(?:\s+[^\]]*)?\]|<\s*/?\s*[A-Za-z][A-Za-z0-9:-]*\b[^>]*>|\b(?:Language Learner Tools|LL Tools|WordPress|DeepL|AssemblyAI|Quizlet)\b|\n~';

    $protected = preg_replace_callback($pattern, static function (array $matches) use (&$tokens): string {
        $tokens[] = (string) $matches[0];
        $index = count($tokens) - 1;
        return '__LLPH' . $index . '__';
    }, $text);

    return [is_string($protected) ? $protected : $text, $tokens];
}

/**
 * @param string[] $tokens
 */
function ll_tools_public_i18n_deepl_restore_text(string $text, array $tokens): string
{
    foreach ($tokens as $index => $token) {
        $marker = '__LLPH' . $index . '__';
        if (substr_count($text, $marker) !== 1) {
            throw new RuntimeException('DeepL response did not preserve protected token ' . $index . '.');
        }
        $text = str_replace($marker, (string) $token, $text);
    }
    if (preg_match('/__LLPH\d+__/', $text)) {
        throw new RuntimeException('DeepL response contains an unknown protected token.');
    }

    return $text;
}

function ll_tools_public_i18n_deepl_cainfo_path(): string
{
    $candidates = [
        getenv('DEEPL_CAINFO') ?: '',
        getenv('CURL_CA_BUNDLE') ?: '',
        getenv('SSL_CERT_FILE') ?: '',
        (string) ini_get('curl.cainfo'),
        (string) ini_get('openssl.cafile'),
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * @param string[] $texts
 * @return string[]
 */
function ll_tools_public_i18n_deepl_translate_batch(array $texts, string $target, string $source, string $api_key): array
{
    if ($texts === []) {
        return [];
    }

    $host = str_ends_with($api_key, ':fx')
        ? 'https://api-free.deepl.com/v2/translate'
        : 'https://api.deepl.com/v2/translate';

    $fields = http_build_query([
        'source_lang' => $source,
        'target_lang' => $target,
        'tag_handling' => 'xml',
        'ignore_tags' => 'llph',
        'preserve_formatting' => '1',
        'split_sentences' => 'nonewlines',
    ]);
    foreach ($texts as $text) {
        $fields .= '&text=' . rawurlencode($text);
    }

    $ch = curl_init($host);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Authorization: DeepL-Auth-Key ' . $api_key,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $cainfo = ll_tools_public_i18n_deepl_cainfo_path();
    if ($cainfo !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
    } elseif ((string) getenv('DEEPL_INSECURE_SSL') === '1') {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('DeepL request failed: ' . $error);
    }
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('DeepL request failed with HTTP ' . $status . ': ' . (string) $response);
    }

    $translations = $decoded['translations'] ?? null;
    if (!is_array($translations) || count($translations) !== count($texts)) {
        throw new RuntimeException('DeepL response did not include the expected translation count.');
    }

    return array_map(static fn (array $row): string => (string) ($row['text'] ?? ''), $translations);
}

function ll_tools_public_i18n_deepl_translation_complete(array $entry, array $translations, int $plural_count): bool
{
    $key = (string) ($entry['key'] ?? '');
    if ($key === '') {
        return false;
    }

    $has_plural = array_key_exists('msgid_plural', $entry) && $entry['msgid_plural'] !== null;
    $required = $has_plural ? $plural_count : 1;
    for ($index = 0; $index < $required; $index++) {
        if (trim((string) ($translations[$key][$index] ?? '')) === '') {
            return false;
        }
    }

    return true;
}

/**
 * Keep source-backed entries that are intentionally broader than the public
 * manifest when refreshing an existing locale catalog.
 *
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_deepl_supplemental_entries(string $po_path, array $manifest_entries): array
{
    if (!is_file($po_path)) {
        return [];
    }

    $manifest_keys = [];
    foreach ($manifest_entries as $entry) {
        if (is_array($entry)) {
            $manifest_keys[ll_tools_public_i18n_entry_key($entry)] = true;
        }
    }

    $supplemental = [];
    foreach (ll_tools_public_i18n_parse_po_file($po_path) as $entry) {
        $msgid = (string) ($entry['msgid'] ?? '');
        if ($msgid === '') {
            continue;
        }

        $key = ll_tools_public_i18n_entry_key($entry);
        if (isset($manifest_keys[$key])) {
            continue;
        }

        $supplemental[] = [
            'key' => $key,
            'context' => $entry['context'] === null ? '' : (string) $entry['context'],
            'msgid' => $msgid,
            'msgid_plural' => $entry['msgid_plural'] === null ? null : (string) $entry['msgid_plural'],
            'references' => array_values(array_unique(array_map('strval', (array) ($entry['references'] ?? [])))),
            'flags' => array_values(array_unique(array_map('strval', (array) ($entry['flags'] ?? [])))),
        ];
    }

    return $supplemental;
}

/**
 * @param array<string, array<int, string>> $translations
 */
function ll_tools_public_i18n_deepl_build_po_entry(
    array $entry,
    array $translations,
    int $plural_count
): string {
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

    $flags = array_values(array_unique(array_filter(array_map('strval', (array) ($entry['flags'] ?? [])))));
    sort($flags, SORT_STRING);
    if ($flags !== []) {
        $lines[] = '#, ' . implode(', ', $flags);
    }

    $context = (string) ($entry['context'] ?? '');
    if ($context !== '') {
        $lines[] = ll_tools_public_i18n_po_line('msgctxt', $context);
    }

    $lines[] = ll_tools_public_i18n_po_line('msgid', (string) ($entry['msgid'] ?? ''));
    if (array_key_exists('msgid_plural', $entry) && $entry['msgid_plural'] !== null) {
        $lines[] = ll_tools_public_i18n_po_line('msgid_plural', (string) $entry['msgid_plural']);
        for ($index = 0; $index < $plural_count; $index++) {
            $lines[] = ll_tools_public_i18n_po_line('msgstr[' . $index . ']', (string) ($translations[$key][$index] ?? ''));
        }
    } else {
        $lines[] = ll_tools_public_i18n_po_line('msgstr', (string) ($translations[$key][0] ?? ''));
    }

    return implode("\n", $lines);
}

/**
 * Append translated manifest entries that do not exist in the PO without
 * rewriting existing source-backed entries or translator comments.
 *
 * @param array<string, array<int, string>> $translations
 */
function ll_tools_public_i18n_deepl_append_missing_entries(
    string $po_path,
    string $locale,
    array $manifest_entries,
    array $config,
    array $translations
): void {
    $existing_keys = [];
    foreach (ll_tools_public_i18n_parse_po_file($po_path) as $entry) {
        if ((string) ($entry['msgid'] ?? '') !== '') {
            $existing_keys[ll_tools_public_i18n_entry_key($entry)] = true;
        }
    }

    $plural_count = ll_tools_public_i18n_plural_count_for_locale($locale, $config);
    $chunks = [];
    foreach ($manifest_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = (string) ($entry['key'] ?? '');
        if ($key === '' || isset($existing_keys[$key])) {
            continue;
        }
        if (!ll_tools_public_i18n_deepl_translation_complete($entry, $translations, $plural_count)) {
            continue;
        }
        $chunks[] = ll_tools_public_i18n_deepl_build_po_entry($entry, $translations, $plural_count);
    }

    if ($chunks === []) {
        return;
    }

    $existing = file_get_contents($po_path);
    if ($existing === false) {
        throw new RuntimeException("Unable to read PO file: {$po_path}");
    }
    $separator = $existing === '' || str_ends_with($existing, "\n\n") ? '' : (str_ends_with($existing, "\n") ? "\n" : "\n\n");
    if (file_put_contents($po_path, $separator . implode("\n\n", $chunks) . "\n", FILE_APPEND) === false) {
        throw new RuntimeException("Unable to append translated PO entries: {$po_path}");
    }
}

/**
 * @param array<string, array<int, string>> $translations
 */
function ll_tools_public_i18n_deepl_write_po(string $po_path, string $locale, array $manifest_entries, array $config, array $translations): void
{
    $chunks = [ll_tools_public_i18n_build_po_header($locale, $config)];
    $plural_count = ll_tools_public_i18n_plural_count_for_locale($locale, $config);

    foreach ($manifest_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $chunks[] = ll_tools_public_i18n_deepl_build_po_entry($entry, $translations, $plural_count);
    }

    if (file_put_contents($po_path, implode("\n\n", $chunks) . "\n") === false) {
        throw new RuntimeException("Unable to write PO file: {$po_path}");
    }
}

/**
 * Parse only the identity fields needed to match one raw PO block. Keeping the
 * raw block lets catalog translation replace msgstr values without dropping
 * translator/extracted comments, flags, references, or entry order.
 *
 * @return array<string, mixed>|null
 */
function ll_tools_public_i18n_deepl_parse_po_block_identity(string $block): ?array
{
    $entry = [
        'context' => null,
        'msgid' => null,
        'msgid_plural' => null,
    ];
    $field = null;

    foreach (preg_split('/\n/', $block) ?: [] as $line) {
        if (str_starts_with($line, '#~')) {
            return null;
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
        if (preg_match('/^msgstr(?:\[\d+\])?\s+"/', $line)) {
            $field = 'msgstr';
            continue;
        }
        if (preg_match('/^"(.*)"$/', $line, $matches) && in_array($field, ['context', 'msgid', 'msgid_plural'], true)) {
            $entry[$field] = (string) ($entry[$field] ?? '') . ll_tools_public_i18n_decode_po_string($matches[1]);
        }
    }

    if ($entry['msgid'] === null || $entry['msgid'] === '') {
        return null;
    }

    return $entry;
}

/**
 * Fill selected existing entries in place while preserving every non-msgstr
 * line. Catalog mode deliberately refuses a partial/missing PO merge: run the
 * normal POT/PO refresh first so source comments and references stay canonical.
 *
 * @param array<string, array<int, string>> $translations
 * @param array<string, bool> $updated_keys
 */
function ll_tools_public_i18n_deepl_replace_existing_translations(
    string $po_path,
    array $translations,
    array $updated_keys,
    int $plural_count
): int {
    if ($updated_keys === []) {
        return 0;
    }

    $contents = file_get_contents($po_path);
    if (!is_string($contents)) {
        throw new RuntimeException("Unable to read PO file: {$po_path}");
    }

    $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
    $normalized = str_replace(["\r\n", "\r"], "\n", $contents);
    $blocks = preg_split('/\n{2,}/', rtrim($normalized, "\n"));
    if (!is_array($blocks)) {
        throw new RuntimeException("Unable to split PO entries: {$po_path}");
    }

    $replaced_keys = [];
    foreach ($blocks as &$block) {
        $identity = ll_tools_public_i18n_deepl_parse_po_block_identity($block);
        if ($identity === null) {
            continue;
        }

        $key = ll_tools_public_i18n_entry_key($identity);
        if (!isset($updated_keys[$key])) {
            continue;
        }

        $lines = preg_split('/\n/', $block) ?: [];
        $first_msgstr = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^msgstr(?:\[\d+\])?\s+"/', $line)) {
                $first_msgstr = $index;
                break;
            }
        }
        if ($first_msgstr === null) {
            throw new RuntimeException('Selected PO entry has no msgstr field: ' . (string) $identity['msgid']);
        }

        $replacement = array_slice($lines, 0, $first_msgstr);
        if ($identity['msgid_plural'] !== null) {
            for ($index = 0; $index < $plural_count; $index++) {
                $translation = (string) ($translations[$key][$index] ?? '');
                if (trim($translation) === '') {
                    throw new RuntimeException('Translated plural slot is empty: ' . (string) $identity['msgid']);
                }
                $replacement[] = ll_tools_public_i18n_po_line('msgstr[' . $index . ']', $translation);
            }
        } else {
            $translation = (string) ($translations[$key][0] ?? '');
            if (trim($translation) === '') {
                throw new RuntimeException('Translated singular value is empty: ' . (string) $identity['msgid']);
            }
            $replacement[] = ll_tools_public_i18n_po_line('msgstr', $translation);
        }

        $block = implode("\n", $replacement);
        $replaced_keys[$key] = true;
    }
    unset($block);

    $missing_keys = array_diff_key($updated_keys, $replaced_keys);
    if ($missing_keys !== []) {
        throw new RuntimeException('Catalog PO is missing ' . count($missing_keys) . ' selected entries. Refresh it from the POT before translating.');
    }

    $written = implode("\n\n", $blocks) . "\n";
    if ($newline !== "\n") {
        $written = str_replace("\n", $newline, $written);
    }

    $temp_path = tempnam(dirname($po_path), '.ll-tools-po-');
    if (!is_string($temp_path) || file_put_contents($temp_path, $written, LOCK_EX) === false) {
        if (is_string($temp_path)) {
            @unlink($temp_path);
        }
        throw new RuntimeException("Unable to write temporary PO file for {$po_path}");
    }

    $backup_path = $po_path . '.ll-tools-backup-' . bin2hex(random_bytes(6));
    if (!rename($po_path, $backup_path)) {
        @unlink($temp_path);
        throw new RuntimeException("Unable to prepare atomic PO replacement: {$po_path}");
    }
    if (!rename($temp_path, $po_path)) {
        @rename($backup_path, $po_path);
        @unlink($temp_path);
        throw new RuntimeException("Unable to install translated PO file: {$po_path}");
    }
    @unlink($backup_path);

    return count($replaced_keys);
}

/**
 * Validate translated values before any catalog write so placeholder, URL,
 * shortcode, HTML, and newline corruption cannot partially replace the PO.
 *
 * @param array<int, array<string, mixed>> $entries
 * @param array<string, array<int, string>> $translations
 * @param array<string, bool> $selected_keys
 * @return array<int, array<string, mixed>>
 */
function ll_tools_public_i18n_deepl_validate_translation_map(
    array $entries,
    array $translations,
    array $selected_keys,
    int $plural_count
): array {
    $errors = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = (string) ($entry['key'] ?? '');
        if ($key === '' || !isset($selected_keys[$key])) {
            continue;
        }

        $required = ($entry['msgid_plural'] ?? null) !== null ? $plural_count : 1;
        for ($index = 0; $index < $required; $index++) {
            $translation = (string) ($translations[$key][$index] ?? '');
            if (trim($translation) === '') {
                $errors[] = [
                    'key' => $key,
                    'type' => 'blank_translation',
                    'plural_index' => $index,
                ];
                continue;
            }
            $entry_errors = ll_tools_public_i18n_validate_translation_string($entry, $translation, $index);
            foreach ($entry_errors as &$entry_error) {
                $entry_error['msgid'] = (string) ($entry['msgid'] ?? '');
                $entry_error['translation'] = $translation;
            }
            unset($entry_error);
            $errors = array_merge($errors, $entry_errors);
        }
    }

    return $errors;
}

function ll_tools_public_i18n_deepl_run(array $argv, string $root_dir = ''): int
{
    $root_dir = $root_dir !== '' ? rtrim($root_dir, "\\/") : ll_tools_public_i18n_root_dir();
    $args = ll_tools_public_i18n_deepl_parse_args($argv);
    if (!empty($args['help'])) {
        echo ll_tools_public_i18n_deepl_usage();
        return 0;
    }

    $locale = (string) $args['locale'];
    if ($locale === '') {
        throw new InvalidArgumentException('Missing --locale.');
    }

    $api_key = (string) getenv('DEEPL_API_KEY');

    $config = ll_tools_public_i18n_load_config($root_dir);
    $scope = (string) $args['scope'];
    if ($scope === 'catalog' && !isset($config['core_full_locales'][$locale])) {
        throw new InvalidArgumentException("Locale is not configured as a full-catalog locale: {$locale}");
    }
    if ($scope === 'public' && !isset($config['tier2_locales'][$locale])) {
        throw new InvalidArgumentException("Locale is not configured as a tier-2 locale: {$locale}");
    }

    $target = (string) ($args['target'] ?: ll_tools_public_i18n_deepl_target_from_locale($locale));
    $source = (string) $args['source'];
    $pot_path = $root_dir . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['pot_file']);
    $manifest_path = $root_dir . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);
    $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
    $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
    $translation_entries = $scope === 'catalog'
        ? ll_tools_public_i18n_catalog_entries_from_pot($pot_path)
        : $manifest_entries;
    $po_path = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.po';

    if (!is_file($po_path)) {
        if ($scope === 'catalog') {
            throw new RuntimeException("Full-catalog PO file does not exist: {$po_path}. Merge the POT before translating it.");
        }
        if (empty($args['force'])) {
            throw new RuntimeException("PO file does not exist: {$po_path}. Run --force to create it first.");
        }
        ll_tools_public_i18n_write_po_for_locale($po_path, $locale, $manifest_entries, $config, false);
    }

    $existing_entries = ll_tools_public_i18n_parse_po_file($po_path);
    $translations = ll_tools_public_i18n_deepl_existing_translations($po_path);
    $supplemental_entries = ll_tools_public_i18n_deepl_supplemental_entries($po_path, $manifest_entries);
    $existing_keys = [];
    foreach ($existing_entries as $existing_entry) {
        if ((string) ($existing_entry['msgid'] ?? '') !== '') {
            $existing_keys[ll_tools_public_i18n_entry_key($existing_entry)] = true;
        }
    }
    if ($scope === 'catalog') {
        $catalog_keys = [];
        foreach ($translation_entries as $entry) {
            if (is_array($entry)) {
                $catalog_keys[(string) ($entry['key'] ?? '')] = true;
            }
        }
        $missing_catalog_keys = array_diff_key($catalog_keys, $existing_keys);
        if ($missing_catalog_keys !== []) {
            throw new RuntimeException('Catalog PO is missing ' . count($missing_catalog_keys) . ' current POT entries. Merge the POT before translating.');
        }
    }
    $plural_count = ll_tools_public_i18n_plural_count_from_entries($existing_entries);
    if ($plural_count < 1) {
        $plural_count = ll_tools_public_i18n_plural_count_for_locale($locale, $config);
    }
    $has_incomplete_existing_entry = false;
    foreach ($translation_entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $key = (string) ($entry['key'] ?? '');
        if (isset($existing_keys[$key]) && !ll_tools_public_i18n_deepl_translation_complete($entry, $translations, $plural_count)) {
            $has_incomplete_existing_entry = true;
            break;
        }
    }
    $jobs = [];
    $updated_keys = [];
    $cache = [];
    foreach ($translation_entries as $entry) {
        if (!is_array($entry) || ll_tools_public_i18n_deepl_translation_complete($entry, $translations, $plural_count)) {
            continue;
        }

        $key = (string) ($entry['key'] ?? '');
        $has_plural = array_key_exists('msgid_plural', $entry) && $entry['msgid_plural'] !== null;
        $required = $has_plural ? $plural_count : 1;
        for ($index = 0; $index < $required; $index++) {
            if (trim((string) ($translations[$key][$index] ?? '')) !== '') {
                continue;
            }

            $source_text = $has_plural && $index > 0
                ? (string) $entry['msgid_plural']
                : (string) $entry['msgid'];
            $jobs[] = [
                'key' => $key,
                'index' => $index,
                'source' => $source_text,
            ];
            $updated_keys[$key] = true;
            if ((int) $args['limit'] > 0 && count($jobs) >= (int) $args['limit']) {
                break 2;
            }
        }
    }

    if ($jobs !== [] && $api_key === '') {
        throw new RuntimeException('DEEPL_API_KEY is not set.');
    }

    $translated = 0;
    $batch_size = (int) $args['batch_size'];
    for ($offset = 0; $offset < count($jobs); $offset += $batch_size) {
        $batch = array_slice($jobs, $offset, $batch_size);
        $protected_texts = [];
        $protected_tokens = [];
        $batch_positions = [];

        foreach ($batch as $position => $job) {
            $source_text = (string) $job['source'];
            if (isset($cache[$source_text])) {
                $translations[(string) $job['key']][(int) $job['index']] = $cache[$source_text];
                $translated++;
                continue;
            }

            [$protected, $tokens] = ll_tools_public_i18n_deepl_protect_text($source_text);
            $protected_texts[] = $protected;
            $protected_tokens[] = $tokens;
            $batch_positions[] = $position;
        }

        if ($protected_texts !== []) {
            $translated_texts = ll_tools_public_i18n_deepl_translate_batch($protected_texts, $target, $source, $api_key);
            foreach ($translated_texts as $translation_index => $translated_text) {
                $job = $batch[$batch_positions[$translation_index]];
                $source_text = (string) $job['source'];
                $restored = ll_tools_public_i18n_deepl_restore_text($translated_text, $protected_tokens[$translation_index]);
                $translations[(string) $job['key']][(int) $job['index']] = $restored;
                $cache[$source_text] = $restored;
                $translated++;
            }
        }

        echo 'Translated ' . min($translated, count($jobs)) . '/' . count($jobs) . " strings\n";
    }

    if ($scope === 'catalog') {
        $validation_keys = (int) $args['limit'] === 0
            ? array_fill_keys(array_map(static fn (array $entry): string => (string) ($entry['key'] ?? ''), $translation_entries), true)
            : $updated_keys;
        unset($validation_keys['']);
        $validation_errors = ll_tools_public_i18n_deepl_validate_translation_map(
            $translation_entries,
            $translations,
            $validation_keys,
            $plural_count
        );
        if ($validation_errors !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to write catalog with %d structural translation errors: %s',
                count($validation_errors),
                json_encode(array_slice($validation_errors, 0, 10), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ));
        }

        $replaced = ll_tools_public_i18n_deepl_replace_existing_translations(
            $po_path,
            $translations,
            $updated_keys,
            $plural_count
        );
        echo "Updated {$replaced} catalog entries\n";

        if ((int) $args['limit'] === 0) {
            $coverage = ll_tools_public_i18n_check_locale_coverage($locale, $translation_entries, $po_path);
            if (empty($coverage['complete'])) {
                throw new RuntimeException(sprintf(
                    'Full catalog remains incomplete (missing: %d, untranslated: %d, validation errors: %d).',
                    (int) $coverage['missing'],
                    (int) $coverage['untranslated'],
                    (int) $coverage['validation_error_count']
                ));
            }
        }
    } elseif ($has_incomplete_existing_entry) {
        ll_tools_public_i18n_deepl_write_po(
            $po_path,
            $locale,
            array_merge($manifest_entries, $supplemental_entries),
            $config,
            $translations
        );
    } else {
        ll_tools_public_i18n_deepl_append_missing_entries(
            $po_path,
            $locale,
            $manifest_entries,
            $config,
            $translations
        );
    }
    if ($scope === 'catalog' && $updated_keys === []) {
        echo "Catalog already complete: {$po_path}\n";
    } else {
        echo "Wrote {$po_path}\n";
    }

    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        exit(ll_tools_public_i18n_deepl_run($argv));
    } catch (Throwable $throwable) {
        fwrite(STDERR, $throwable->getMessage() . "\n");
        exit(1);
    }
}
