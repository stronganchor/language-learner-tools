<?php
declare(strict_types=1);

final class ImageAspectNormalizationEligibilityTest extends LL_Tools_TestCase
{
    private function createCategory(string $name, string $prompt_type, string $option_type): int
    {
        $term = wp_insert_term($name, 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($term_id, 'll_quiz_option_type', $option_type);

        return $term_id;
    }

    private function createWordWithImage(int $category_id, string $title, int $width, int $height, string $suffix): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $suffix,
            'post_mime_type' => 'image/jpeg',
        ]);

        $relative_path = '2026/03/image-aspect-' . sanitize_title($suffix) . '.jpg';
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);
        wp_update_attachment_metadata($attachment_id, [
            'width' => $width,
            'height' => $height,
            'file' => $relative_path,
        ]);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
    }

    public function test_prompt_only_image_categories_do_not_need_aspect_normalization(): void
    {
        $category_id = $this->createCategory(
            'Prompt Only Aspect Category ' . (string) wp_rand(1000, 9999),
            'image',
            'text_translation'
        );

        $this->createWordWithImage($category_id, 'Prompt Only Square', 100, 100, 'prompt-only-square');
        $this->createWordWithImage($category_id, 'Prompt Only Wide', 200, 100, 'prompt-only-wide');

        $stats = ll_tools_get_category_image_aspect_stats($category_id);

        $this->assertCount(2, (array) ($stats['ratios'] ?? []));
        $this->assertFalse(ll_tools_category_requires_image_answer_aspect_normalization($category_id));
        $this->assertFalse(ll_tools_category_needs_aspect_normalization($category_id));
        $this->assertSame('no-image', ll_tools_get_category_aspect_bucket_key($category_id));
        $this->assertSame([], ll_tools_get_categories_needing_aspect_normalization([$category_id]));
    }

    public function test_image_answer_categories_still_need_aspect_normalization_when_ratios_mix(): void
    {
        $category_id = $this->createCategory(
            'Image Answer Aspect Category ' . (string) wp_rand(1000, 9999),
            'audio',
            'image'
        );

        $this->createWordWithImage($category_id, 'Image Answer Square', 100, 100, 'image-answer-square');
        $this->createWordWithImage($category_id, 'Image Answer Wide', 200, 100, 'image-answer-wide');

        $stats = ll_tools_get_category_image_aspect_stats($category_id);

        $this->assertCount(2, (array) ($stats['ratios'] ?? []));
        $this->assertTrue(ll_tools_category_requires_image_answer_aspect_normalization($category_id));
        $this->assertTrue(ll_tools_category_needs_aspect_normalization($category_id));
        $this->assertStringStartsWith('ratio:', ll_tools_get_category_aspect_bucket_key($category_id));
        $this->assertSame([$category_id], ll_tools_get_categories_needing_aspect_normalization([$category_id]));
    }
}
