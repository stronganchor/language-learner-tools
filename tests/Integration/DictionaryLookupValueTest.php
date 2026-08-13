<?php
declare(strict_types=1);

final class DictionaryLookupValueTest extends LL_Tools_TestCase
{
    private string $originalDatabaseCharset = '';

    private function utf8CharacterCount(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);
        $this->assertNotFalse($count);

        return (int) $count;
    }

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->originalDatabaseCharset = (string) $wpdb->charset;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->charset = $this->originalDatabaseCharset;

        parent::tearDown();
    }

    public function test_latin1_connection_caps_raw_utf8_bytes_without_splitting_characters(): void
    {
        global $wpdb;
        $wpdb->charset = 'latin1';
        $two_byte_character = "\xC3\xAA";
        $four_byte_character = "\xF0\x9F\x99\x82";

        // Mirrors the live failure shape: 189 Unicode characters occupy 212
        // raw UTF-8 bytes, which a latin1 connection counts against varchar(191).
        $value = str_repeat('a', 166) . str_repeat($two_byte_character, 23);
        $this->assertSame(189, $this->utf8CharacterCount($value));
        $this->assertSame(212, strlen($value));

        $prepared = ll_tools_dictionary_prepare_lookup_value($value);
        $this->assertSame(str_repeat('a', 166) . str_repeat($two_byte_character, 12), $prepared);
        $this->assertSame(190, strlen($prepared));
        $this->assertLessThanOrEqual(191, strlen($prepared));
        $this->assertSame(1, preg_match('//u', $prepared));

        $boundary = str_repeat('b', 187) . $four_byte_character . 'x';
        $prepared_boundary = ll_tools_dictionary_prepare_lookup_value($boundary);
        $this->assertSame(str_repeat('b', 187) . $four_byte_character, $prepared_boundary);
        $this->assertSame(191, strlen($prepared_boundary));
    }

    public function test_utf8_and_unclassified_connections_preserve_the_191_character_limit(): void
    {
        global $wpdb;
        $two_byte_character = "\xC3\xAA";
        $value = str_repeat($two_byte_character, 191) . 'x';

        foreach (['utf8', 'utf8mb3', 'utf8mb4', 'UTF-8', 'sjis', 'big5', 'gbk', ''] as $charset) {
            $wpdb->charset = $charset;
            $prepared = ll_tools_dictionary_prepare_lookup_value($value);

            $this->assertSame(str_repeat($two_byte_character, 191), $prepared, $charset);
            $this->assertSame(191, $this->utf8CharacterCount($prepared), $charset);
            $this->assertSame(382, strlen($prepared), $charset);
        }
    }

    public function test_lookup_row_builder_applies_the_latin1_connection_byte_cap(): void
    {
        global $wpdb;
        $value = str_repeat('a', 166) . str_repeat("\xC3\xAA", 23);
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => $value,
            'post_content' => 'lookup byte cap regression',
        ]);

        $wpdb->charset = 'latin1';
        $rows = ll_tools_dictionary_build_lookup_rows_for_entry($entry_id);
        $headword_rows = array_values(array_filter($rows, static function (array $row): bool {
            return (string) ($row['lookup_kind'] ?? '') === 'headword';
        }));

        $this->assertNotEmpty($headword_rows);
        $long_row_found = false;
        foreach ($headword_rows as $row) {
            $lookup_value = (string) ($row['lookup_value'] ?? '');
            $this->assertLessThanOrEqual(191, strlen($lookup_value));
            $this->assertSame(1, preg_match('//u', $lookup_value));
            if (str_starts_with($lookup_value, str_repeat('a', 166))) {
                $this->assertSame(189, (int) ($row['value_length'] ?? 0));
                $long_row_found = true;
            }
        }
        $this->assertTrue($long_row_found);
    }
}
