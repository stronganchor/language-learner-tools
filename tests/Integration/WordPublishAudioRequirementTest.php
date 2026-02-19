<?php
declare(strict_types=1);

final class WordPublishAudioRequirementTest extends LL_Tools_TestCase
{
    public function test_publish_is_blocked_without_audio_when_category_requires_it(): void
    {
        $this->set_current_user_to_administrator();

        $category = wp_insert_term('Audio Required Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Needs Audio Before Publish',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $this->assertTrue(ll_word_requires_audio_to_publish($word_id));

        wp_update_post([
            'ID'          => $word_id,
            'post_status' => 'publish',
        ]);

        $this->assertSame('draft', get_post_status($word_id));

        $blocked = (int) get_transient('ll_word_publish_blocked_' . get_current_user_id());
        $this->assertSame($word_id, $blocked);
    }

    public function test_publish_is_allowed_without_audio_for_non_audio_quiz_category(): void
    {
        $this->set_current_user_to_administrator();

        $category = wp_insert_term('No Audio Required Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Publish Without Audio',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $this->assertFalse(ll_word_requires_audio_to_publish($word_id));

        wp_update_post([
            'ID'          => $word_id,
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', get_post_status($word_id));
    }

    public function test_publish_is_allowed_without_audio_for_text_prompt_image_option_category(): void
    {
        $this->set_current_user_to_administrator();

        $category = wp_insert_term('Text Prompt Image Option Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_translation');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Publish Without Audio For Text Prompt',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $this->assertFalse(ll_word_requires_audio_to_publish($word_id));

        wp_update_post([
            'ID'          => $word_id,
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', get_post_status($word_id));
    }

    private function set_current_user_to_administrator(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
    }
}
