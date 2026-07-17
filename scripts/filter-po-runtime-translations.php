<?php
declare(strict_types=1);

/**
 * Build a runtime PO that omits incomplete and fuzzy translations.
 *
 * The review PO remains untouched so untranslated entries stay visible to
 * translators. The filtered copy is safe to pass to WP-CLI's make-mo and
 * make-php commands, which otherwise compile blank msgstr values.
 */

/**
 * @return array<int, string>
 */
function ll_tools_runtime_po_translation_fields(string $block): array
{
    $fields = [];
    $current_index = null;
    $lines = preg_split('/\r?\n/', $block) ?: [];

    foreach ($lines as $line) {
        if (preg_match('/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $matches)) {
            $current_index = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
            $fields[$current_index] = (string) $matches[2];
            continue;
        }

        if ($current_index !== null && preg_match('/^"(.*)"$/', $line, $matches)) {
            $fields[$current_index] .= (string) $matches[1];
            continue;
        }

        $current_index = null;
    }

    ksort($fields, SORT_NUMERIC);

    return $fields;
}

function ll_tools_runtime_po_block_is_header(string $block): bool
{
    if (!preg_match('/^msgid\s+""\r?$/m', $block)) {
        return false;
    }

    $header_fields = ll_tools_runtime_po_translation_fields($block);
    $header = (string) ($header_fields[0] ?? '');

    return str_contains($header, 'Content-Type:') || str_contains($header, 'Project-Id-Version:');
}

function ll_tools_runtime_po_block_is_fuzzy(string $block): bool
{
    return (bool) preg_match('/^#,\s*(?:[^,\r\n]+,\s*)*fuzzy(?:\s*,|\s*$)/m', $block);
}

function ll_tools_runtime_po_plural_count(string $header_block): int
{
    $header_fields = ll_tools_runtime_po_translation_fields($header_block);
    $header = (string) ($header_fields[0] ?? '');
    if (preg_match('/Plural-Forms:\s*nplurals\s*=\s*(\d+)/i', $header, $matches)) {
        return max(1, (int) $matches[1]);
    }

    return 2;
}

function ll_tools_runtime_po_block_is_complete(string $block, int $plural_count = 2): bool
{
    if (ll_tools_runtime_po_block_is_header($block)) {
        return true;
    }

    if (!preg_match('/^msgid\s+/m', $block) || ll_tools_runtime_po_block_is_fuzzy($block)) {
        return false;
    }

    $translations = ll_tools_runtime_po_translation_fields($block);
    if ($translations === [] || in_array('', $translations, true)) {
        return false;
    }

    if (preg_match('/^msgid_plural\s+/m', $block)) {
        $expected_index = 0;
        foreach (array_keys($translations) as $index) {
            if ($index !== $expected_index) {
                return false;
            }
            ++$expected_index;
        }

        return $expected_index === $plural_count;
    }

    return count($translations) === 1 && array_key_exists(0, $translations);
}

function ll_tools_filter_po_for_runtime(string $source_path, string $destination_path): void
{
    $contents = file_get_contents($source_path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read PO file: {$source_path}");
    }

    $line_ending = str_contains($contents, "\r\n") ? "\r\n" : "\n";
    $normalized = str_replace("\r\n", "\n", $contents);
    $blocks = preg_split('/\n{2,}/', trim($normalized));
    if ($blocks === false) {
        throw new RuntimeException("Unable to split PO file: {$source_path}");
    }

    $plural_count = ll_tools_runtime_po_plural_count((string) ($blocks[0] ?? ''));

    $runtime_blocks = array_values(array_filter(
        $blocks,
        static fn (string $block): bool => ll_tools_runtime_po_block_is_complete($block, $plural_count)
    ));
    if ($runtime_blocks === [] || !ll_tools_runtime_po_block_is_header($runtime_blocks[0])) {
        throw new RuntimeException("PO header is missing or invalid: {$source_path}");
    }

    $output = implode("\n\n", $runtime_blocks) . "\n";
    if ($line_ending === "\r\n") {
        $output = str_replace("\n", "\r\n", $output);
    }

    $destination_directory = dirname($destination_path);
    if (!is_dir($destination_directory)) {
        throw new RuntimeException("Destination directory does not exist: {$destination_directory}");
    }
    if (file_put_contents($destination_path, $output) === false) {
        throw new RuntimeException("Unable to write runtime PO file: {$destination_path}");
    }
}

function ll_tools_runtime_po_cli(array $arguments): int
{
    if (count($arguments) !== 3) {
        fwrite(STDERR, "Usage: php scripts/filter-po-runtime-translations.php <source.po> <destination.po>\n");

        return 1;
    }

    try {
        ll_tools_filter_po_for_runtime((string) $arguments[1], (string) $arguments[2]);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);

        return 1;
    }

    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(ll_tools_runtime_po_cli($argv));
}
