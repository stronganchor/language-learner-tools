<?php
declare(strict_types=1);

final class BulkCategoryEditHelperTest extends LL_Tools_TestCase
{
    public function test_common_category_helper_intersects_only_eligible_posts_for_type(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $cat_a = $this->createCategory('Bulk Cat A');
        $cat_b = $this->createCategory('Bulk Cat B');
        $cat_c = $this->createCategory('Bulk Cat C');

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Word One',
        ]);
        wp_set_post_terms($word_one, [$cat_a, $cat_b], 'word-category', false);

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Word Two',
        ]);
        wp_set_post_terms($word_two, [$cat_a, $cat_c], 'word-category', false);

        $image_post = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Image One',
        ]);
        wp_set_post_terms($image_post, [$cat_b], 'word-category', false);

        $result = ll_tools_get_common_word_category_ids_for_posts(
            [$word_one, $word_two, $image_post, 999999],
            'words'
        );

        $this->assertSame([$cat_a], array_values(array_map('intval', $result)));
    }

    public function test_common_category_helper_returns_empty_when_no_eligible_posts(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $image_post = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Image Two',
        ]);

        $result = ll_tools_get_common_word_category_ids_for_posts([$image_post], 'words');
        $this->assertSame([], $result);
    }

    private function createCategory(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }
}
