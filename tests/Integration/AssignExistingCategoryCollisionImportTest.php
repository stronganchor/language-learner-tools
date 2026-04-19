<?php
declare(strict_types=1);

final class AssignExistingCategoryCollisionImportTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W8fcAAAAASUVORK5CYII=';

    public function test_assign_existing_import_creates_clean_owned_category_when_foreign_slug_collision_exists(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $target_wordset = wp_insert_term('Target Genc Palu ' . wp_generate_password(6, false, false), 'wordset', [
            'slug' => 'target-genc-palu-' . strtolower(wp_generate_password(4, false, false)),
        ]);
        $this->assertFalse(is_wp_error($target_wordset));
        $this->assertIsArray($target_wordset);
        $target_wordset_id = (int) $target_wordset['term_id'];

        $foreign_wordset = wp_insert_term('Foreign Genc Palu ' . wp_generate_password(6, false, false), 'wordset', [
            'slug' => 'foreign-genc-palu-' . strtolower(wp_generate_password(4, false, false)),
        ]);
        $this->assertFalse(is_wp_error($foreign_wordset));
        $this->assertIsArray($foreign_wordset);
        $foreign_wordset_id = (int) $foreign_wordset['term_id'];

        $dirty_source = wp_insert_term('Quiz 2.2', 'word-category', ['slug' => 'quiz-2-2-collision-test']);
        $this->assertFalse(is_wp_error($dirty_source));
        $this->assertIsArray($dirty_source);
        $dirty_source_id = (int) $dirty_source['term_id'];

        $foreign_slug = 'takvim-1-genc-palu';
        $foreign_category = wp_insert_term('Takvim 1 Dirty', 'word-category', ['slug' => $foreign_slug]);
        $this->assertFalse(is_wp_error($foreign_category));
        $this->assertIsArray($foreign_category);
        $foreign_category_id = (int) $foreign_category['term_id'];
        update_term_meta($foreign_category_id, 'll_wordset_owner_id', $foreign_wordset_id);
        update_term_meta($foreign_category_id, 'll_category_isolation_source_id', $dirty_source_id);

        $zip_path = $this->createMinimalFullBundleZip($foreign_slug);

        try {
            $result = ll_tools_process_import_zip($zip_path, [
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $target_wordset_id,
            ]);

            $this->assertIsArray($result);
            $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
            $this->assertEmpty((array) ($result['errors'] ?? []));

            $owned_slug = function_exists('ll_tools_build_isolated_category_slug')
                ? ll_tools_build_isolated_category_slug($foreign_slug, $target_wordset_id)
                : $foreign_slug;
            $owned_term = get_term_by('slug', $owned_slug, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $owned_term);
            $this->assertSame('Takvim 1', (string) $owned_term->name);
            $this->assertSame($target_wordset_id, (int) get_term_meta((int) $owned_term->term_id, 'll_wordset_owner_id', true));
            $this->assertSame((int) $owned_term->term_id, (int) get_term_meta((int) $owned_term->term_id, 'll_category_isolation_source_id', true));

            $this->assertSame('Takvim 1 Dirty', (string) get_term($foreign_category_id, 'word-category')->name);
            $this->assertSame($foreign_wordset_id, (int) get_term_meta($foreign_category_id, 'll_wordset_owner_id', true));
            $this->assertSame($dirty_source_id, (int) get_term_meta($foreign_category_id, 'll_category_isolation_source_id', true));

            $word = get_page_by_path('calendar-day', OBJECT, 'words');
            $this->assertInstanceOf(WP_Post::class, $word);
            $word_categories = wp_get_post_terms((int) $word->ID, 'word-category', ['fields' => 'ids']);
            $this->assertSame([(int) $owned_term->term_id], array_values(array_map('intval', (array) $word_categories)));

            $owned_image_slug = function_exists('ll_tools_build_isolated_word_image_slug')
                ? ll_tools_build_isolated_word_image_slug('calendar-card', $target_wordset_id)
                : 'calendar-card';
            $word_image = get_page_by_path($owned_image_slug, OBJECT, 'word_images');
            $this->assertInstanceOf(WP_Post::class, $word_image);
            $this->assertSame($target_wordset_id, (int) get_post_meta((int) $word_image->ID, 'll_wordset_owner_id', true));
            $this->assertSame((int) $word_image->ID, (int) get_post_meta((int) $word_image->ID, 'll_word_image_isolation_source_id', true));
            $image_categories = wp_get_post_terms((int) $word_image->ID, 'word-category', ['fields' => 'ids']);
            $this->assertSame([(int) $owned_term->term_id], array_values(array_map('intval', (array) $image_categories)));
            $this->assertGreaterThan(0, (int) get_post_thumbnail_id((int) $word_image->ID));
        } finally {
            @unlink($zip_path);
        }
    }

    private function createMinimalFullBundleZip(string $category_slug): string
    {
        $payload = [
            'version' => 2,
            'bundle_type' => 'category_full',
            'categories' => [
                [
                    'slug' => $category_slug,
                    'name' => 'Takvim 1',
                    'description' => 'Calendar words',
                    'parent_slug' => '',
                    'meta' => [
                        'll_quiz_prompt_type' => ['image'],
                        'll_quiz_option_type' => ['text_title'],
                        'll_wordset_owner_id' => ['999999'],
                        'll_category_isolation_source_id' => ['888888'],
                    ],
                ],
            ],
            'word_images' => [
                [
                    'slug' => 'calendar-card',
                    'title' => 'Calendar Card',
                    'status' => 'publish',
                    'meta' => [
                        'll_wordset_owner_id' => ['999999'],
                        'll_word_image_isolation_source_id' => ['777777'],
                    ],
                    'categories' => [$category_slug],
                    'featured_image' => [
                        'file' => 'media/calendar.png',
                        'mime_type' => 'image/png',
                        'title' => 'Calendar Card',
                    ],
                ],
            ],
            'wordsets' => [
                [
                    'slug' => 'source-wordset',
                    'name' => 'Source Wordset',
                    'description' => '',
                    'meta' => [],
                ],
            ],
            'words' => [
                [
                    'origin_id' => 321,
                    'slug' => 'calendar-day',
                    'title' => 'Yarin',
                    'content' => '',
                    'excerpt' => '',
                    'status' => 'publish',
                    'meta' => [],
                    'categories' => [$category_slug],
                    'wordsets' => ['source-wordset'],
                    'linked_word_image_slug' => 'calendar-card',
                    'languages' => [],
                    'parts_of_speech' => [],
                    'featured_image' => [],
                    'audio_entries' => [],
                ],
            ],
            'media_estimate' => [
                'attachment_count' => 1,
                'attachment_bytes' => 68,
            ],
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $zip_path = trailingslashit(sys_get_temp_dir()) . 'll-tools-collision-import-' . strtolower(wp_generate_password(8, false, false)) . '.zip';
        @unlink($zip_path);

        $png = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($png);

        $zip = new ZipArchive();
        $opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Failed to create collision test zip.');
        $this->assertTrue($zip->addFromString('data.json', $json));
        $this->assertTrue($zip->addFromString('media/calendar.png', $png));
        $this->assertTrue($zip->close());
        $this->assertFileExists($zip_path);

        return wp_normalize_path($zip_path);
    }
}
