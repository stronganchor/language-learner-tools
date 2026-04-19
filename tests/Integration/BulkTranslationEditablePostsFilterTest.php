<?php
declare(strict_types=1);

final class BulkTranslationEditablePostsFilterTest extends LL_Tools_TestCase
{
    public function test_filter_editable_post_ids_excludes_non_editable_and_non_target_posts(): void
    {
        $author_id = self::factory()->user->create(['role' => 'author']);
        $other_author_id = self::factory()->user->create(['role' => 'author']);

        $own_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $author_id,
            'post_title' => 'Own Word',
        ]);
        $other_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_author' => $other_author_id,
            'post_title' => 'Other Word',
        ]);
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_author' => $author_id,
            'post_title' => 'Page Post',
        ]);

        wp_set_current_user($author_id);

        $filtered = ll_bulk_translations_filter_editable_post_ids([
            $own_word_id,
            $other_word_id,
            $page_id,
            0,
            -3,
            'abc',
            $own_word_id,
        ]);

        $this->assertSame([$own_word_id], $filtered);
    }
}

