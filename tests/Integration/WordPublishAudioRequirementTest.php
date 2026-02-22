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

    public function test_publish_is_allowed_without_audio_when_any_assigned_category_is_non_audio(): void
    {
        $this->set_current_user_to_administrator();

        $audio_category = wp_insert_term('Mixed Audio Category', 'word-category');
        $this->assertFalse(is_wp_error($audio_category));
        $this->assertIsArray($audio_category);
        $audio_category_id = (int) $audio_category['term_id'];

        update_term_meta($audio_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($audio_category_id, 'll_quiz_option_type', 'image');

        $text_category = wp_insert_term('Mixed Non Audio Category', 'word-category');
        $this->assertFalse(is_wp_error($text_category));
        $this->assertIsArray($text_category);
        $text_category_id = (int) $text_category['term_id'];

        update_term_meta($text_category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($text_category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Publish Without Audio In Mixed Categories',
        ]);
        wp_set_post_terms($word_id, [$audio_category_id, $text_category_id], 'word-category', false);

        $this->assertFalse(ll_word_requires_audio_to_publish($word_id));

        wp_update_post([
            'ID'          => $word_id,
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', get_post_status($word_id));
    }

    public function test_rest_publish_with_non_audio_category_assigned_in_same_request_is_allowed(): void
    {
        $this->set_current_user_to_administrator();

        $category = wp_insert_term('REST Non Audio Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'REST Publish Without Audio',
        ]);

        $response = $this->dispatch_words_rest_update($word_id, [
            'status'        => 'publish',
            'word-category' => [$category_id],
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('publish', isset($data['status']) ? (string) $data['status'] : '');
        $this->assertSame('publish', get_post_status($word_id));
    }

    public function test_rest_publish_with_audio_required_category_assigned_in_same_request_is_blocked(): void
    {
        $this->set_current_user_to_administrator();

        $category = wp_insert_term('REST Audio Required Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'REST Publish Should Be Blocked',
        ]);

        $response = $this->dispatch_words_rest_update($word_id, [
            'status'        => 'publish',
            'word-category' => [$category_id],
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('draft', isset($data['status']) ? (string) $data['status'] : '');
        $this->assertSame('draft', get_post_status($word_id));
        $blocked = (int) get_transient('ll_word_publish_blocked_' . get_current_user_id());
        $this->assertSame($word_id, $blocked);
    }

    private function dispatch_words_rest_update($word_id, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/wp/v2/words/' . (int) $word_id);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_get_server()->dispatch($request);
        $this->assertNotWPError($response);

        return rest_ensure_response($response);
    }

    private function set_current_user_to_administrator(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
    }
}
