<?php
declare(strict_types=1);

final class ScopedBundleImportTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once LL_TOOLS_BASE_PATH . 'includes/api/scoped-bundle-import.php';
        require_once LL_TOOLS_BASE_PATH . 'includes/admin/export-import.php';
    }

    public function test_scoped_zip_rejects_executable_paths_links_and_conflicting_formats(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is required.');
        }

        $fixtures = [
            [['data.json', 'audio/recording.mp3', 'images/sample.webp'], '', true],
            [['quiz.csv', 'images/sample.webp'], '', true],
            [['quiz.csv', 'images/sample.php'], '', false],
            [['data.json', 'sample.php.webp'], '', false],
            [['data.json', 'images/.htaccess'], '', false],
            [['data.json', 'images/../sample.webp'], '', false],
            [['data.json', 'images/SAMPLE.webp', 'images/sample.webp'], '', false],
            [['data.json', 'quiz.csv'], '', false],
            [['data.json', 'images/sample.webp'], 'images/sample.webp', false],
        ];
        foreach ($fixtures as [$entries, $symlink, $valid]) {
            $path = tempnam(sys_get_temp_dir(), 'll-tools-scoped-zip-');
            $this->assertIsString($path);
            try {
                $zip = new ZipArchive();
                $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
                foreach ($entries as $name) {
                    $this->assertTrue($zip->addFromString($name, 'fixture'));
                }
                if ($symlink !== '') {
                    $this->assertTrue($zip->setExternalAttributesName($symlink, ZipArchive::OPSYS_UNIX, 0120777 << 16));
                }
                $this->assertTrue($zip->close());
                $actual = ll_tools_scoped_import_validate_zip($path);
                if ($valid) {
                    $this->assertTrue($actual);
                } else {
                    $this->assertWPError($actual);
                    $this->assertSame('ll_tools_scoped_import_invalid_zip', $actual->get_error_code());
                }
            } finally {
                unlink($path);
            }
        }
    }

    public function test_scoped_payload_preserves_quiz_content_and_drops_privileged_fields(): void
    {
        $payload = [
            'bundle_type' => 'category_full',
            'wordsets' => [['slug' => 'other', 'meta' => ['manager_user_id' => [999]]]],
            'categories' => [[
                'slug' => 'lesson-56',
                'name' => 'Lesson 56',
                'meta' => [
                    'll_quiz_prompt_type' => ['image'],
                    'll_quiz_option_type' => ['text_audio'],
                    'll_wordset_owner_id' => [999],
                    'll_category_lineup_word_order' => [[999]],
                ],
            ]],
            'word_images' => [[
                'slug' => 'boy',
                'categories' => ['lesson-56'],
                'featured_image' => ['file' => 'images/boy.webp'],
            ]],
            'words' => [[
                'slug' => 'lesson-56-boy',
                'title' => 'יֶלֶד',
                'content' => '<script>alert(1)</script>[caption]photo[/caption]',
                'origin_id' => 999,
                'wordsets' => ['other'],
                'categories' => ['lesson-56'],
                'linked_word_image_slug' => 'boy',
                'specific_wrong_answer_texts' => ['גֶּבֶר'],
                'preferred_wrong_image_files' => ['images/man.webp', 'https://other.example/man.webp'],
                'meta' => [
                    'word_translation' => ['boy'],
                    '_ll_autopicked_image_id' => [999],
                    '_ll_specific_wrong_answer_ids' => [[999]],
                    'll_word_usage_note' => [serialize(serialize([999]))],
                ],
                'audio_entries' => [[
                    'slug' => 'boy-audio',
                    'audio_file' => ['file' => 'audio/boy.mp3'],
                    'meta' => [
                        'recording_ipa' => ['yeled'],
                        'audio_source_url' => ['https://example.com/credits'],
                        'audio_file_path' => ['/other/file.mp3'],
                    ],
                ]],
            ]],
        ];

        $actual = ll_tools_scoped_import_sanitize_payload($payload, 42);
        $this->assertIsArray($actual);
        $this->assertSame([], $actual['wordsets']);
        $this->assertSame(['ll_quiz_prompt_type' => ['image'], 'll_quiz_option_type' => ['text_audio']], $actual['categories'][0]['meta']);
        $word = $actual['words'][0];
        $this->assertSame('יֶלֶד', $word['title']);
        $this->assertSame('boy', $word['linked_word_image_slug']);
        $this->assertSame(['גֶּבֶר'], $word['specific_wrong_answer_texts']);
        $this->assertSame(['images/man.webp'], $word['preferred_wrong_image_files']);
        $this->assertSame(['word_translation' => ['boy']], $word['meta']);
        $this->assertArrayNotHasKey('origin_id', $word);
        $this->assertArrayNotHasKey('wordsets', $word);
        $this->assertStringNotContainsString('<script>', $word['content']);
        $this->assertStringNotContainsString('[caption]', $word['content']);
        $this->assertSame('audio/boy.mp3', $word['audio_entries'][0]['audio_file']['file']);
        $this->assertSame(['recording_ipa' => ['yeled'], 'audio_source_url' => ['https://example.com/credits']], $word['audio_entries'][0]['meta']);

        $payload['words'][0]['featured_image'] = ['source_url' => 'https://other.example/image.webp'];
        $this->assertWPError(ll_tools_scoped_import_sanitize_payload($payload, 42));
        $payload['bundle_type'] = 'wordset_template';
        $this->assertWPError(ll_tools_scoped_import_sanitize_payload($payload, 42));
    }

    public function test_scoped_media_must_exist_and_match_its_declared_type(): void
    {
        $root = tempnam(sys_get_temp_dir(), 'll-tools-scoped-media-');
        $this->assertIsString($root);
        unlink($root);
        $this->assertTrue(mkdir($root));
        $verified = [];

        try {
            $this->assertWPError(ll_tools_scoped_import_media(['file' => 'missing.png'], 'image', $root, $verified));
            $this->assertNotFalse(file_put_contents($root . '/fake.png', '<?php echo 1;'));
            $this->assertWPError(ll_tools_scoped_import_media(['file' => 'fake.png'], 'image', $root, $verified));

            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6WQAAAAASUVORK5CYII=', true);
            $this->assertNotFalse(file_put_contents($root . '/real.png', $png));
            $this->assertSame('real.png', ll_tools_scoped_import_media(['file' => 'real.png'], 'image', $root, $verified)['file']);
        } finally {
            ll_tools_rrmdir($root);
        }
    }

    public function test_scoped_image_group_write_appends_without_replacing_existing_rules(): void
    {
        $wordset_id = self::factory()->term->create(['taxonomy' => 'wordset']);
        $category_id = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        $words = [];
        for ($index = 0; $index < 4; $index++) {
            $words[] = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft']);
            $word_id = $words[$index];
            wp_set_object_terms($word_id, [$wordset_id], 'wordset');
            wp_set_object_terms($word_id, [$category_id], 'word-category');
        }
        $unrelated_rules = ['groups' => [['label' => 'Unrelated', 'word_ids' => [1, 2]]]];
        $manual_group = ['label' => 'CSV image import 1', 'word_ids' => [$words[2], $words[3]]];
        $old = [
            $wordset_id + 1 => [$category_id + 1 => $unrelated_rules],
            $wordset_id => [$category_id => ['groups' => [$manual_group]]],
        ];
        update_option('ll_tools_word_option_rules', $old, false);

        $components = [[$words[0], $words[1]]];
        $this->assertTrue(ll_tools_scoped_import_append_image_groups($wordset_id, $category_id, $components));
        $this->assertTrue(ll_tools_scoped_import_append_image_groups($wordset_id, $category_id, $components));
        $current = get_option('ll_tools_word_option_rules');
        $this->assertSame($unrelated_rules, $current[$wordset_id + 1][$category_id + 1]);
        $this->assertSame([
            $manual_group,
            ['label' => 'CSV image import 2', 'word_ids' => $components[0]],
        ], $current[$wordset_id][$category_id]['groups']);
    }
}
