<?php
declare(strict_types=1);

final class CategoryQuizPresentationMismatchNoticeTest extends LL_Tools_TestCase
{
    public function test_mismatch_data_recommends_text_when_more_audio_words_have_text_than_images(): void
    {
        $this->setCurrentUserToAdministrator();

        $fixture = $this->createAudioPromptCategoryFixture('image');
        $notice = ll_tools_get_category_quiz_presentation_mismatch_data($fixture['term']->term_id);

        $this->assertIsArray($notice);
        $this->assertSame('image', (string) ($notice['current_config']['option_type'] ?? ''));
        $this->assertSame('text_translation', (string) ($notice['recommended_config']['option_type'] ?? ''));
        $this->assertSame(10, (int) ($notice['current_count'] ?? -1));
        $this->assertSame(12, (int) ($notice['recommended_count'] ?? -1));
        $this->assertSame(12, (int) ($notice['published_count'] ?? -1));
        $this->assertSame(2, (int) ($notice['mismatch_count'] ?? -1));
        $this->assertStringContainsString(
            'tag_ID=' . (string) $fixture['term']->term_id,
            html_entity_decode((string) ($notice['settings_url'] ?? ''))
        );
        $this->assertStringContainsString(
            'word_category=' . (string) $fixture['term']->term_id,
            html_entity_decode((string) ($notice['words_url'] ?? ''))
        );
    }

    public function test_mismatch_data_scopes_edit_words_link_to_explicit_wordset_context(): void
    {
        $this->setCurrentUserToAdministrator();

        $fixture = $this->createAudioPromptCategoryFixture('image');
        $notice = ll_tools_get_category_quiz_presentation_mismatch_data($fixture['term']->term_id, [
            'wordset' => $fixture['wordset']->slug,
        ]);

        $this->assertIsArray($notice);
        $this->assertSame((int) $fixture['wordset']->term_id, (int) ($notice['wordset_id'] ?? 0));
        $this->assertSame((string) $fixture['wordset']->slug, (string) ($notice['wordset_slug'] ?? ''));
        $this->assertSame((string) $fixture['wordset']->name, (string) ($notice['wordset_name'] ?? ''));
        $this->assertStringContainsString(
            'word_category=' . (string) $fixture['term']->term_id,
            html_entity_decode((string) ($notice['words_url'] ?? ''))
        );
        $this->assertStringContainsString(
            'wordset=' . (string) $fixture['wordset']->slug,
            html_entity_decode((string) ($notice['words_url'] ?? ''))
        );
    }

    public function test_admin_notice_renders_on_word_edit_screen_for_mismatched_category(): void
    {
        $this->setCurrentUserToAdministrator();

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
        $this->assertStringContainsString('published words currently appear', $decoded_output);
        $this->assertStringContainsString('will not appear on lesson or quiz pages', $decoded_output);
        $this->assertStringContainsString('change this category\'s quiz presentation', $decoded_output);
        $this->assertStringContainsString('edit the words in this category', $decoded_output);
        $this->assertStringContainsString('word_category=' . (string) $fixture['term']->term_id, $decoded_output);
        $this->assertStringContainsString('wordset=' . (string) $fixture['wordset']->slug, $decoded_output);
    }

    public function test_notice_term_ids_include_word_list_category_filter_using_term_id_query_var(): void
    {
        $this->setCurrentUserToAdministrator();

        $fixture = $this->createAudioPromptCategoryFixture('image');

        global $pagenow;
        $original_get = $_GET;
        $original_post = $_POST;
        $original_pagenow = $pagenow ?? null;

        try {
            $_GET = [
                'post_type' => 'words',
                'word_category' => (string) $fixture['term']->term_id,
                'wordset' => (string) $fixture['wordset']->slug,
            ];
            $_POST = [];
            $pagenow = 'edit.php';

            $term_ids = ll_tools_get_category_quiz_presentation_notice_term_ids_for_admin_screen();

            ob_start();
            ll_tools_render_category_quiz_presentation_mismatch_notice();
            $output = (string) ob_get_clean();
        } finally {
            $_GET = $original_get;
            $_POST = $original_post;
            $pagenow = $original_pagenow;
        }

        $this->assertSame([(int) $fixture['term']->term_id], $term_ids);
        $decoded_output = html_entity_decode($output, ENT_QUOTES, 'UTF-8');

        $this->assertStringContainsString('notice notice-warning', $decoded_output);
        $this->assertStringContainsString('word_category=' . (string) $fixture['term']->term_id, $decoded_output);
        $this->assertStringContainsString('wordset=' . (string) $fixture['wordset']->slug, $decoded_output);
    }

    public function test_mismatch_data_returns_null_when_current_option_is_already_best(): void
    {
        $this->setCurrentUserToAdministrator();

        $fixture = $this->createAudioPromptCategoryFixture('text_translation');
        $notice = ll_tools_get_category_quiz_presentation_mismatch_data($fixture['term']->term_id);

        $this->assertNull($notice);
    }

    public function test_mismatch_data_can_recommend_text_title_when_translation_text_is_missing(): void
    {
        $this->setCurrentUserToAdministrator();

        $fixture = $this->createAudioPromptCategoryFixture('image', 12, 10, false);
        $notice = ll_tools_get_category_quiz_presentation_mismatch_data($fixture['term']->term_id);

        $this->assertIsArray($notice);
        $this->assertSame('image', (string) ($notice['current_config']['option_type'] ?? ''));
        $this->assertSame('text_title', (string) ($notice['recommended_config']['option_type'] ?? ''));
        $this->assertSame(10, (int) ($notice['current_count'] ?? -1));
        $this->assertSame(12, (int) ($notice['recommended_count'] ?? -1));
        $this->assertSame(2, (int) ($notice['mismatch_count'] ?? -1));
        $this->assertTrue((bool) ($notice['recommended_changes_presentation'] ?? false));
    }

    /**
     * @return array{term:WP_Term,word_id:int,wordset:WP_Term}
     */
    private function createAudioPromptCategoryFixture(
        string $option_type,
        int $word_count = 12,
        int $image_count = 10,
        bool $with_translations = true
    ): array
    {
        $category_name = 'Quiz Presentation Notice ' . (string) wp_rand(1000, 9999) . ' ' . $option_type;
        $term_insert = wp_insert_term($category_name, 'word-category');

        $this->assertFalse(is_wp_error($term_insert));
        $this->assertIsArray($term_insert);

        $term_id = (int) $term_insert['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($term_id, 'll_quiz_option_type', $option_type);

        $wordset_insert = wp_insert_term(
            'Quiz Presentation Wordset ' . (string) wp_rand(1000, 9999),
            'wordset',
            [
                'slug' => 'quiz-presentation-wordset-' . (string) wp_rand(1000, 9999),
            ]
        );

        $this->assertFalse(is_wp_error($wordset_insert));
        $this->assertIsArray($wordset_insert);

        $wordset = get_term((int) $wordset_insert['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $last_word_id = 0;
        for ($index = 1; $index <= $word_count; $index++) {
            $last_word_id = $this->createWordWithAudioAndMaybeImage(
                $term_id,
                (int) $wordset->term_id,
                'Prompt Notice Word ' . $index,
                $with_translations ? ('Prompt Notice Translation ' . $index) : '',
                $index <= $image_count
            );
        }

        $term = get_term($term_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        return [
            'term' => $term,
            'word_id' => $last_word_id,
            'wordset' => $wordset,
        ];
    }

    private function createWordWithAudioAndMaybeImage(int $category_id, int $wordset_id, string $title, string $translation, bool $with_image): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        update_post_meta($word_id, 'word_translation', $translation);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

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

    private function setCurrentUserToAdministrator(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
    }
}
