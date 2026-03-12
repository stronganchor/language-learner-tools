<?php
declare(strict_types=1);

final class CategoryQuizPresentationMismatchNoticeTest extends LL_Tools_TestCase
{
    public function test_mismatch_data_recommends_text_when_more_audio_words_have_text_than_images(): void
    {
        $this->setCurrentUserToLlToolsAdmin();

        $fixture = $this->createAudioPromptCategoryFixture('image');
        $notice = ll_tools_get_category_quiz_presentation_mismatch_data($fixture['term']->term_id);

        $this->assertIsArray($notice);
        $this->assertSame('image', (string) ($notice['current_config']['option_type'] ?? ''));
        $this->assertSame('text_translation', (string) ($notice['recommended_config']['option_type'] ?? ''));
        $this->assertSame(10, (int) ($notice['current_count'] ?? -1));
        $this->assertSame(12, (int) ($notice['recommended_count'] ?? -1));
        $this->assertStringContainsString(
            'tag_ID=' . (string) $fixture['term']->term_id,
            html_entity_decode((string) ($notice['settings_url'] ?? ''))
        );
        $this->assertStringContainsString(
            'word-category=' . (string) $fixture['term']->slug,
            html_entity_decode((string) ($notice['words_url'] ?? ''))
        );
    }

    public function test_admin_notice_renders_on_word_edit_screen_for_mismatched_category(): void
    {
        $this->setCurrentUserToLlToolsAdmin();

        $fixture = $this->createAudioPromptCategoryFixture('image');

        global $pagenow;
        $original_get = $_GET;
        $original_post = $_POST;
        $original_pagenow = $pagenow ?? null;

        try {
            $_GET = ['post' => (string) $fixture['word_id']];
            $_POST = [];
            $pagenow = 'post.php';

            ob_start();
            ll_tools_render_category_quiz_presentation_mismatch_notice();
            $output = (string) ob_get_clean();
        } finally {
            $_GET = $original_get;
            $_POST = $original_post;
            $pagenow = $original_pagenow;
        }

        $decoded_output = html_entity_decode($output, ENT_QUOTES, 'UTF-8');

        $this->assertStringContainsString('notice notice-warning', $decoded_output);
        $this->assertStringContainsString((string) $fixture['term']->name, $decoded_output);
        $this->assertStringContainsString('Play audio -> Images', $decoded_output);
        $this->assertStringContainsString('Play audio -> Text (translation)', $decoded_output);
        $this->assertStringContainsString('change this category\'s quiz presentation', $decoded_output);
        $this->assertStringContainsString('edit the words in this category', $decoded_output);
        $this->assertStringContainsString('word-category=' . (string) $fixture['term']->slug, $decoded_output);
    }

    public function test_mismatch_data_returns_null_when_current_option_is_already_best(): void
    {
        $this->setCurrentUserToLlToolsAdmin();

        $fixture = $this->createAudioPromptCategoryFixture('text_translation');
        $notice = ll_tools_get_category_quiz_presentation_mismatch_data($fixture['term']->term_id);

        $this->assertNull($notice);
    }

    /**
     * @return array{term:WP_Term,word_id:int}
     */
    private function createAudioPromptCategoryFixture(string $option_type): array
    {
        $category_name = 'Quiz Presentation Notice ' . (string) wp_rand(1000, 9999) . ' ' . $option_type;
        $term_insert = wp_insert_term($category_name, 'word-category');

        $this->assertFalse(is_wp_error($term_insert));
        $this->assertIsArray($term_insert);

        $term_id = (int) $term_insert['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($term_id, 'll_quiz_option_type', $option_type);

        $last_word_id = 0;
        for ($index = 1; $index <= 12; $index++) {
            $last_word_id = $this->createWordWithAudioAndMaybeImage(
                $term_id,
                'Prompt Notice Word ' . $index,
                'Prompt Notice Translation ' . $index,
                $index <= 10
            );
        }

        $term = get_term($term_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        return [
            'term' => $term,
            'word_id' => $last_word_id,
        ];
    }

    private function createWordWithAudioAndMaybeImage(int $category_id, string $title, string $translation, bool $with_image): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio for ' . $title,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '.mp3');

        if ($with_image) {
            $attachment_id = self::factory()->post->create([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_title' => 'Image for ' . $title,
                'post_mime_type' => 'image/jpeg',
            ]);
            update_post_meta($attachment_id, '_wp_attached_file', '2026/03/' . sanitize_title($title) . '.jpg');
            set_post_thumbnail($word_id, $attachment_id);
        }

        return (int) $word_id;
    }

    private function setCurrentUserToLlToolsAdmin(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin = get_user_by('id', $admin_id);

        $this->assertInstanceOf(WP_User::class, $admin);

        $admin->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);
    }
}
