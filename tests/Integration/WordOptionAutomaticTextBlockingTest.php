<?php
declare(strict_types=1);

final class WordOptionAutomaticTextBlockingTest extends LL_Tools_TestCase
{
    public function test_admin_page_locks_same_translation_and_prompt_recording_text_pairs(): void
    {
        $fixture = $this->createFixture('audio');

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->grantViewLlToolsCapability();
        wp_set_current_user($admin_id);

        $html = $this->renderWordOptionRulesAdminPage($fixture['wordset_id'], $fixture['category_id']);

        $this->assertStringContainsString('ll-tools-word-options-table-wrap ll-tools-word-options-table-wrap--groups', $html);
        $this->assertStringContainsString('ll-tools-word-options-table-wrap ll-tools-word-options-table-wrap--pairs', $html);
        $this->assertStringContainsString('Dog - animal / Hound - animal', $html);
        $this->assertStringContainsString('ll-tools-word-options-reason--same_translation', $html);
        $this->assertStringContainsString('Car / Truck', $html);
        $this->assertStringContainsString('ll-tools-word-options-reason--recording_text', $html);
        $this->assertStringContainsString('Same Question text', $html);
        $this->assertStringContainsString('Locked', $html);
    }

    public function test_admin_page_skips_recording_text_pairs_when_category_prompt_is_not_audio(): void
    {
        $fixture = $this->createFixture('text_title');

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->grantViewLlToolsCapability();
        wp_set_current_user($admin_id);

        $html = $this->renderWordOptionRulesAdminPage($fixture['wordset_id'], $fixture['category_id']);

        $this->assertStringContainsString('Dog - animal / Hound - animal', $html);
        $this->assertStringContainsString('ll-tools-word-options-reason--same_translation', $html);
        $this->assertStringNotContainsString('Car / Truck', $html);
        $this->assertStringNotContainsString('Same Question text', $html);
    }

    private function createFixture(string $prompt_type): array
    {
        $wordset_name = 'Word Option Text Wordset ' . wp_generate_password(6, false);
        $category_name = 'Word Option Text Category ' . wp_generate_password(6, false);

        $wordset = wp_insert_term($wordset_name, 'wordset');
        $this->assertIsArray($wordset);
        $category = wp_insert_term($category_name, 'word-category');
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($category_id, 'll_quiz_option_type', 'image');

        $same_translation_a = $this->createWord($wordset_id, $category_id, 'Dog', 'animal');
        $same_translation_b = $this->createWord($wordset_id, $category_id, 'Hound', 'animal');
        $same_question_a = $this->createWord($wordset_id, $category_id, 'Car');
        $same_question_b = $this->createWord($wordset_id, $category_id, 'Truck');

        $this->createAudio($same_question_a, 'question', 'where is it', 'https://audio.test/car-question.mp3');
        $this->createAudio($same_question_b, 'question', 'where is it', 'https://audio.test/truck-question.mp3');

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
        ];
    }

    private function createWord(int $wordset_id, int $category_id, string $title, string $translation = ''): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        if ($translation !== '') {
            update_post_meta($word_id, 'word_translation', $translation);
        }

        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }

    private function createAudio(int $word_id, string $recording_type, string $recording_text, string $audio_url): int
    {
        if (!term_exists($recording_type, 'recording_type')) {
            wp_insert_term(ucwords(str_replace('-', ' ', $recording_type)), 'recording_type', [
                'slug' => $recording_type,
            ]);
        }

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $recording_type,
        ]);

        update_post_meta($audio_id, 'audio_file_path', $audio_url);
        update_post_meta($audio_id, 'recording_text', $recording_text);
        wp_set_post_terms($audio_id, [$recording_type], 'recording_type', false);

        return (int) $audio_id;
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

    private function grantViewLlToolsCapability(): void
    {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
    }
}
