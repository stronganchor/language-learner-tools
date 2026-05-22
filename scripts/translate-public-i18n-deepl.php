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
        '  --locale=LOCALE        Tier-2 locale to translate, such as ru_RU.',
        '  --target=LANG          DeepL target language, default inferred from locale.',
        '  --source=LANG          DeepL source language, default EN.',
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
    $pattern = '~https?://[^\s"\'<>)\]]+|(?<!%)%(?:\d+\$)?[+\-0# ]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]|\[(?:/)?[A-Za-z][A-Za-z0-9_-]*(?:\s+[^\]]*)?\]|<\s*/?\s*[A-Za-z][A-Za-z0-9:-]*\b[^>]*>|\n~';

    $protected = preg_replace_callback($pattern, static function (array $matches) use (&$tokens): string {
        $tokens[] = (string) $matches[0];
        return '<llph id="' . (count($tokens) - 1) . '"/>';
    }, $text);

    return [is_string($protected) ? $protected : $text, $tokens];
}

/**
 * @param string[] $tokens
 */
function ll_tools_public_i18n_deepl_restore_text(string $text, array $tokens): string
{
    return preg_replace_callback('#<llph\s+id="(\d+)"\s*/>|<llph\s+id="(\d+)"></llph>#', static function (array $matches) use ($tokens): string {
        $index = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : (int) ($matches[2] ?? -1);
        return array_key_exists($index, $tokens) ? $tokens[$index] : (string) $matches[0];
    }, $text) ?? $text;
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
                $lines[] = ll_tools_public_i18n_po_line('msgstr[' . $index . ']', (string) ($translations[$key][$index] ?? ''));
            }
        } else {
            $lines[] = ll_tools_public_i18n_po_line('msgstr', (string) ($translations[$key][0] ?? ''));
        }

        $chunks[] = implode("\n", $lines);
    }

    if (file_put_contents($po_path, implode("\n\n", $chunks) . "\n") === false) {
        throw new RuntimeException("Unable to write PO file: {$po_path}");
    }
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
    if ($api_key === '') {
        throw new RuntimeException('DEEPL_API_KEY is not set.');
    }

    $config = ll_tools_public_i18n_load_config($root_dir);
    if (!isset($config['tier2_locales'][$locale])) {
        throw new InvalidArgumentException("Locale is not configured as a tier-2 locale: {$locale}");
    }

    $target = (string) ($args['target'] ?: ll_tools_public_i18n_deepl_target_from_locale($locale));
    $source = (string) $args['source'];
    $manifest_path = $root_dir . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);
    $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
    $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
    $po_path = $root_dir . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.po';

    if (!is_file($po_path)) {
        if (empty($args['force'])) {
            throw new RuntimeException("PO file does not exist: {$po_path}. Run --force to create it first.");
        }
        ll_tools_public_i18n_write_po_for_locale($po_path, $locale, $manifest_entries, $config, false);
    }

    $translations = ll_tools_public_i18n_deepl_existing_translations($po_path);
    $plural_count = ll_tools_public_i18n_plural_count_for_locale($locale, $config);
    $jobs = [];
    $cache = [];
    foreach ($manifest_entries as $entry) {
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
            if ((int) $args['limit'] > 0 && count($jobs) >= (int) $args['limit']) {
                break 2;
            }
        }
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

    ll_tools_public_i18n_deepl_write_po($po_path, $locale, $manifest_entries, $config, $translations);
    echo "Wrote {$po_path}\n";

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
