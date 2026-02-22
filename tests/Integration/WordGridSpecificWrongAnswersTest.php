<?php
declare(strict_types=1);

final class WordGridSpecificWrongAnswersTest extends LL_Tools_TestCase
{
    public function test_word_grid_excludes_specific_wrong_answer_only_words(): void
    {
        $slug = 'word-grid-specific-wrong-' . strtolower(wp_generate_password(8, false, false));
        $category = wp_insert_term('Word Grid Specific Wrong Category', 'word-category', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $owner_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Grid Owner Word',
        ]);
        wp_set_post_terms($owner_id, [$category_id], 'word-category', false);
        update_post_meta($owner_id, 'word_translation', 'Owner Translation');

        $reserved_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Grid Reserved Wrong Word',
        ]);
        wp_set_post_terms($reserved_id, [$category_id], 'word-category', false);
        update_post_meta($reserved_id, 'word_translation', 'Reserved Translation');

        $normal_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Grid Normal Word',
        ]);
        wp_set_post_terms($normal_id, [$category_id], 'word-category', false);
        update_post_meta($normal_id, 'word_translation', 'Normal Translation');

        update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_id]);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $output = do_shortcode('[word_grid category="' . $slug . '"]');

        $this->assertStringContainsString('data-word-id="' . $owner_id . '"', $output);
        $this->assertStringContainsString('data-word-id="' . $normal_id . '"', $output);
        $this->assertStringNotContainsString('data-word-id="' . $reserved_id . '"', $output);
    }
}

