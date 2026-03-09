<?php
declare(strict_types=1);

final class WordOptionImageSimilarityOverrideTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    public function test_admin_page_distinguishes_same_image_from_similar_image_pairs(): void
    {
        $fixture = $this->createImagePairFixture();

        $image_pairs = ll_tools_word_option_rules_get_image_pair_map([
            $fixture['same_word_a'],
            $fixture['same_word_b'],
            $fixture['similar_word_a'],
            $fixture['similar_word_b'],
        ]);

        $this->assertSame('same_image', (string) ($image_pairs[$fixture['same_key']]['match_type'] ?? ''));
        $this->assertSame('similar_image', (string) ($image_pairs[$fixture['similar_key']]['match_type'] ?? ''));

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->grantViewLlToolsCapability();
        wp_set_current_user($admin_id);

        $html = $this->renderWordOptionRulesAdminPage($fixture['wordset_id'], $fixture['category_id']);

        $this->assertStringContainsString($fixture['same_pair_label'], $html);
        $this->assertStringContainsString('ll-tools-word-options-reason--same_image', $html);
        $this->assertStringContainsString('Locked', $html);

        $this->assertStringContainsString($fixture['similar_pair_label'], $html);
        $this->assertStringContainsString('ll-tools-word-options-reason--similar_image', $html);
        $this->assertStringContainsString('value="' . $fixture['similar_key'] . '"', $html);
    }

    public function test_similar_image_override_removes_auto_blocking_without_unlocking_same_image_pairs(): void
    {
        $fixture = $this->createImagePairFixture();

        $rows_before = $this->indexRowsById(ll_get_words_by_category(
            $fixture['category_name'],
            'image',
            [$fixture['wordset_id']],
            [
                'prompt_type' => 'image',
                'option_type' => 'image',
            ]
        ));

        $this->assertContains(
            $fixture['similar_word_b'],
            array_map('intval', (array) ($rows_before[$fixture['similar_word_a']]['option_blocked_ids'] ?? []))
        );

        ll_tools_update_word_option_rules(
            $fixture['wordset_id'],
            $fixture['category_id'],
            [],
            [],
            [[$fixture['similar_word_a'], $fixture['similar_word_b']]]
        );

        $rows_after = $this->indexRowsById(ll_get_words_by_category(
            $fixture['category_name'],
            'image',
            [$fixture['wordset_id']],
            [
                'prompt_type' => 'image',
                'option_type' => 'image',
            ]
        ));

        $this->assertNotContains(
            $fixture['similar_word_b'],
            array_map('intval', (array) ($rows_after[$fixture['similar_word_a']]['option_blocked_ids'] ?? []))
        );

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->grantViewLlToolsCapability();
        wp_set_current_user($admin_id);

        $html = $this->renderWordOptionRulesAdminPage($fixture['wordset_id'], $fixture['category_id']);

        $this->assertStringContainsString($fixture['same_pair_label'], $html);
        $this->assertStringContainsString('ll-tools-word-options-reason--same_image', $html);
        $this->assertStringNotContainsString($fixture['similar_pair_label'], $html);
        $this->assertStringNotContainsString('value="' . $fixture['similar_key'] . '"', $html);
    }

    private function createImagePairFixture(): array
    {
        $wordset_name = 'Word Option Image Wordset ' . wp_generate_password(6, false);
        $category_name = 'Word Option Image Category ' . wp_generate_password(6, false);

        $wordset = wp_insert_term($wordset_name, 'wordset');
        $this->assertIsArray($wordset);
        $category = wp_insert_term($category_name, 'word-category');
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];

        $shared_attachment = $this->createImageAttachmentWithHash('word-option-shared.png', '0000000000000000');
        $similar_attachment_a = $this->createImageAttachmentWithHash('word-option-similar-a.png', 'f0f0f0f0f0f0f0f0');
        $similar_attachment_b = $this->createImageAttachmentWithHash('word-option-similar-b.png', 'f0f0f0f0f0f0f0f0');

        $same_word_a = $this->createWordWithThumbnail($category_id, $wordset_id, $shared_attachment, 'Same Attachment A');
        $same_word_b = $this->createWordWithThumbnail($category_id, $wordset_id, $shared_attachment, 'Same Attachment B');
        $similar_word_a = $this->createWordWithThumbnail($category_id, $wordset_id, $similar_attachment_a, 'Similar Attachment A');
        $similar_word_b = $this->createWordWithThumbnail($category_id, $wordset_id, $similar_attachment_b, 'Similar Attachment B');

        [$same_a, $same_b] = $this->normalizePair($same_word_a, $same_word_b);
        [$similar_a, $similar_b] = $this->normalizePair($similar_word_a, $similar_word_b);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'category_name' => $category_name,
            'same_word_a' => $same_word_a,
            'same_word_b' => $same_word_b,
            'similar_word_a' => $similar_word_a,
            'similar_word_b' => $similar_word_b,
            'same_key' => $same_a . '|' . $same_b,
            'similar_key' => $similar_a . '|' . $similar_b,
            'same_pair_label' => 'Same Attachment A / Same Attachment B',
            'similar_pair_label' => 'Similar Attachment A / Similar Attachment B',
        ];
    }

    private function createWordWithThumbnail(int $category_id, int $wordset_id, int $attachment_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
    }

    private function createImageAttachmentWithHash(string $filename, string $hash): int
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

        $meta_key = function_exists('ll_tools_get_image_hash_meta_key')
            ? ll_tools_get_image_hash_meta_key()
            : '_ll_tools_image_hash';
        update_post_meta($attachment_id, $meta_key, [
            'hash' => strtolower(trim($hash)),
            'mtime' => (int) filemtime($file_path),
            'algo' => 'dhash',
        ]);

        return (int) $attachment_id;
    }

    private function renderWordOptionRulesAdminPage(int $wordset_id, int $category_id): string
    {
        $previous_get = $_GET;

        try {
            $_GET['wordset_id'] = $wordset_id;
            $_GET['category_id'] = $category_id;

            ob_start();
            ll_render_word_option_rules_admin_page();
            return (string) ob_get_clean();
        } finally {
            $_GET = $previous_get;
        }
    }

    private function indexRowsById(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $word_id = (int) ($row['id'] ?? 0);
            if ($word_id > 0) {
                $indexed[$word_id] = $row;
            }
        }

        return $indexed;
    }

    private function normalizePair(int $a, int $b): array
    {
        if ($a > $b) {
            return [$b, $a];
        }

        return [$a, $b];
    }

    private function grantViewLlToolsCapability(): void
    {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
    }
}
