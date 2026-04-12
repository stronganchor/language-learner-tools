<?php
declare(strict_types=1);

final class WordsetIsolationMigrationTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    protected function tearDown(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION);
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT);

        parent::tearDown();
    }

    public function test_find_existing_word_post_by_title_in_wordsets_stays_inside_requested_scope(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one = $this->ensure_term('wordset', 'Isolation Scope One', 'isolation-scope-one');
        $wordset_two = $this->ensure_term('wordset', 'Isolation Scope Two', 'isolation-scope-two');

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Shared Scope Title',
        ]);
        wp_set_object_terms($word_one, [$wordset_one], 'wordset', false);

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Shared Scope Title',
        ]);
        wp_set_object_terms($word_two, [$wordset_two], 'wordset', false);

        $resolved_one = ll_tools_find_existing_word_post_by_title_in_wordsets('Shared Scope Title', [$wordset_one]);
        $resolved_two = ll_tools_find_existing_word_post_by_title_in_wordsets('Shared Scope Title', [$wordset_two]);
        $resolved_none = ll_tools_find_existing_word_post_by_title_in_wordsets('Shared Scope Title', [999999]);

        $this->assertInstanceOf(WP_Post::class, $resolved_one);
        $this->assertSame((int) $word_one, (int) $resolved_one->ID);
        $this->assertInstanceOf(WP_Post::class, $resolved_two);
        $this->assertSame((int) $word_two, (int) $resolved_two->ID);
        $this->assertNull($resolved_none);
    }

    public function test_wordset_isolation_migration_duplicates_shared_categories_and_images_per_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_one = $this->ensure_term('wordset', 'Isolation Migration One', 'isolation-migration-one');
        $wordset_two = $this->ensure_term('wordset', 'Isolation Migration Two', 'isolation-migration-two');
        $shared_category = $this->ensure_term('word-category', 'Shared Migration Category', 'shared-migration-category');

        $attachment_id = $this->create_image_attachment('wordset-isolation-migration.png');

        $legacy_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Shared Migration Image',
        ]);
        set_post_thumbnail($legacy_image_id, $attachment_id);
        wp_set_object_terms($legacy_image_id, [$shared_category], 'word-category', false);

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Migration Word One',
        ]);
        set_post_thumbnail($word_one, $attachment_id);
        wp_set_object_terms($word_one, [$shared_category], 'word-category', false);
        wp_set_object_terms($word_one, [$wordset_one], 'wordset', false);

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Migration Word Two',
        ]);
        set_post_thumbnail($word_two, $attachment_id);
        wp_set_object_terms($word_two, [$shared_category], 'word-category', false);
        wp_set_object_terms($word_two, [$wordset_two], 'wordset', false);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $result = ll_tools_run_wordset_isolation_migration();

        $category_one = ll_tools_get_existing_isolated_category_copy_id($shared_category, $wordset_one);
        $category_two = ll_tools_get_existing_isolated_category_copy_id($shared_category, $wordset_two);
        $image_one = ll_tools_get_existing_isolated_word_image_copy_id($legacy_image_id, $wordset_one);
        $image_two = ll_tools_get_existing_isolated_word_image_copy_id($legacy_image_id, $wordset_two);

        $this->assertGreaterThan(0, $category_one);
        $this->assertGreaterThan(0, $category_two);
        $this->assertNotSame($category_one, $category_two);
        $this->assertGreaterThan(0, $image_one);
        $this->assertGreaterThan(0, $image_two);
        $this->assertNotSame($image_one, $image_two);

        $this->assertSame($wordset_one, ll_tools_get_category_wordset_owner_id($category_one));
        $this->assertSame($wordset_two, ll_tools_get_category_wordset_owner_id($category_two));
        $this->assertSame($wordset_one, ll_tools_get_word_image_wordset_owner_id($image_one));
        $this->assertSame($wordset_two, ll_tools_get_word_image_wordset_owner_id($image_two));

        $image_one_categories = wp_get_post_terms($image_one, 'word-category', ['fields' => 'ids']);
        $image_two_categories = wp_get_post_terms($image_two, 'word-category', ['fields' => 'ids']);
        $this->assertContains($category_one, array_map('intval', (array) $image_one_categories));
        $this->assertContains($category_two, array_map('intval', (array) $image_two_categories));

        $word_one_categories = wp_get_post_terms($word_one, 'word-category', ['fields' => 'ids']);
        $word_two_categories = wp_get_post_terms($word_two, 'word-category', ['fields' => 'ids']);
        $this->assertContains($category_one, array_map('intval', (array) $word_one_categories));
        $this->assertContains($category_two, array_map('intval', (array) $word_two_categories));
        $this->assertSame($image_one, (int) get_post_meta($word_one, '_ll_autopicked_image_id', true));
        $this->assertSame($image_two, (int) get_post_meta($word_two, '_ll_autopicked_image_id', true));

        $this->assertGreaterThanOrEqual(2, (int) ($result['categories_created'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($result['images_created'] ?? 0));
        $this->assertSame(2, (int) ($result['images_relinked'] ?? 0));
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    private function create_image_attachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }
}
