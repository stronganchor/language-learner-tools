<?php
declare(strict_types=1);

final class BulkCategoryEditSecurityTest extends LL_Tools_TestCase
{
    public function test_bulk_category_removal_requires_valid_bulk_edit_nonce(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        [$keep_term_id, $remove_term_id] = $this->createBulkEditTerms();
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Edit Protected Word',
        ]);
        wp_set_post_terms($post_id, [$keep_term_id, $remove_term_id], 'word-category', false);

        $_REQUEST = [
            'bulk_edit' => 'Update',
            'll_bulk_categories_to_remove' => [(string) $remove_term_id],
        ];

        try {
            ll_handle_bulk_category_edit($post_id, 'words');
        } finally {
            $_REQUEST = [];
        }

        $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        sort($term_ids, SORT_NUMERIC);
        $this->assertSame([$keep_term_id, $remove_term_id], array_map('intval', $term_ids));
    }

    public function test_bulk_category_removal_applies_with_valid_bulk_edit_nonce(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        [$keep_term_id, $remove_term_id] = $this->createBulkEditTerms();
        $post_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Edit Authorized Word',
        ]);
        wp_set_post_terms($post_id, [$keep_term_id, $remove_term_id], 'word-category', false);

        $_REQUEST = [
            'bulk_edit' => 'Update',
            '_wpnonce' => wp_create_nonce('bulk-posts'),
            'll_bulk_categories_to_remove' => [(string) $remove_term_id],
        ];

        try {
            ll_handle_bulk_category_edit($post_id, 'words');
        } finally {
            $_REQUEST = [];
        }

        $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
        $this->assertSame([$keep_term_id], array_map('intval', $term_ids));
    }

    /**
     * @return array{0:int,1:int}
     */
    private function createBulkEditTerms(): array
    {
        $keep = wp_insert_term('Bulk Keep ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($keep));
        $this->assertIsArray($keep);

        $remove = wp_insert_term('Bulk Remove ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($remove));
        $this->assertIsArray($remove);

        return [(int) $keep['term_id'], (int) $remove['term_id']];
    }
}
