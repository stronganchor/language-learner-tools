<?php
declare(strict_types=1);

final class ImportMetaKeyFilteringTest extends LL_Tools_TestCase
{
    public function test_post_meta_replace_skips_blocked_protected_keys_and_updates_allowed_keys(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Import Meta Word',
        ]);

        $this->assertGreaterThan(0, $post_id);

        update_post_meta($post_id, 'word_translation', 'Old Translation');
        update_post_meta($post_id, '_ll_similar_word_id', 10);
        update_post_meta($post_id, '_wp_old_slug', 'kept-old-slug');

        ll_tools_import_replace_post_meta_values($post_id, [
            'word_translation' => ['New Translation'],
            '_ll_similar_word_id' => [22],
            '_wp_old_slug' => ['should-not-overwrite'],
            '_thumbnail_id' => [999],
        ], 'words');

        $this->assertSame('New Translation', (string) get_post_meta($post_id, 'word_translation', true));
        $this->assertSame('22', (string) get_post_meta($post_id, '_ll_similar_word_id', true));
        $this->assertSame('kept-old-slug', (string) get_post_meta($post_id, '_wp_old_slug', true));
        $this->assertSame('', (string) get_post_meta($post_id, '_thumbnail_id', true));
    }

    public function test_post_meta_replace_can_allow_custom_protected_key_via_filter(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Import Filtered Meta Word',
        ]);

        $this->assertGreaterThan(0, $post_id);

        $allow_key = static function (bool $allowed, string $key, string $post_type): bool {
            if ($key === '_custom_bundle_meta' && $post_type === 'words') {
                return true;
            }

            return $allowed;
        };
        add_filter('ll_tools_import_allow_post_meta_key', $allow_key, 10, 3);

        try {
            ll_tools_import_replace_post_meta_values($post_id, [
                '_custom_bundle_meta' => ['abc123'],
            ], 'words');
        } finally {
            remove_filter('ll_tools_import_allow_post_meta_key', $allow_key, 10);
        }

        $this->assertSame('abc123', (string) get_post_meta($post_id, '_custom_bundle_meta', true));
    }

    public function test_term_meta_replace_skips_blocked_keys_and_allows_filter_override(): void
    {
        $insert = wp_insert_term('Import Meta Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($insert));
        $term_id = (int) $insert['term_id'];

        update_term_meta($term_id, 'display_color', 'red');
        update_term_meta($term_id, '_wp_note', 'keep-me');

        ll_tools_import_replace_term_meta_values($term_id, [
            'display_color' => ['blue'],
            '_wp_note' => ['should-not-overwrite'],
            '_custom_term_bundle_meta' => ['hidden-by-default'],
        ], 'word-category');

        $this->assertSame('blue', (string) get_term_meta($term_id, 'display_color', true));
        $this->assertSame('keep-me', (string) get_term_meta($term_id, '_wp_note', true));
        $this->assertSame('', (string) get_term_meta($term_id, '_custom_term_bundle_meta', true));

        $allow_key = static function (bool $allowed, string $key, string $taxonomy): bool {
            if ($key === '_custom_term_bundle_meta' && $taxonomy === 'word-category') {
                return true;
            }

            return $allowed;
        };
        add_filter('ll_tools_import_allow_term_meta_key', $allow_key, 10, 3);

        try {
            ll_tools_import_replace_term_meta_values($term_id, [
                '_custom_term_bundle_meta' => ['allowed-value'],
            ], 'word-category');
        } finally {
            remove_filter('ll_tools_import_allow_term_meta_key', $allow_key, 10);
        }

        $this->assertSame('allowed-value', (string) get_term_meta($term_id, '_custom_term_bundle_meta', true));
    }
}
