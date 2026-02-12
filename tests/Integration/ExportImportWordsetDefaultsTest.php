<?php
declare(strict_types=1);

final class ExportImportWordsetDefaultsTest extends LL_Tools_TestCase
{
    public function test_preview_defaults_assign_existing_wordset_on_exact_slug_match(): void
    {
        $slug = sanitize_title('preview-match-' . wp_generate_password(8, false, false));
        $created = wp_insert_term('Local Matching Wordset', 'wordset', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        $existing_term_id = (int) $created['term_id'];
        $this->assertGreaterThan(0, $existing_term_id);

        $payload = [
            'bundle_type' => 'category_full',
            'wordsets' => [[
                'slug' => $slug,
                'name' => 'Exported Wordset Name',
                'description' => 'Different description is allowed for slug-based auto-match.',
                'meta' => [
                    'll_language' => ['xx'],
                ],
            ]],
            'words' => [],
        ];

        $defaults = ll_tools_build_import_preview_default_options($payload);

        $this->assertSame('assign_existing', (string) ($defaults['wordset_mode'] ?? ''));
        $this->assertSame($existing_term_id, (int) ($defaults['target_wordset_id'] ?? 0));
    }

    public function test_preview_defaults_do_not_assign_when_slug_does_not_match(): void
    {
        $payload = [
            'bundle_type' => 'category_full',
            'wordsets' => [[
                'slug' => 'missing-wordset-' . wp_generate_password(6, false, false),
                'name' => 'Missing Local Wordset',
            ]],
            'words' => [],
        ];

        $defaults = ll_tools_build_import_preview_default_options($payload);

        $this->assertSame('create_from_export', (string) ($defaults['wordset_mode'] ?? ''));
        $this->assertSame(0, (int) ($defaults['target_wordset_id'] ?? 0));
    }
}

