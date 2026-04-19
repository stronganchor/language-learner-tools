<?php
declare(strict_types=1);

final class WordsetProgressGenderSectionTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
        if (function_exists('ll_register_part_of_speech_taxonomy')) {
            ll_register_part_of_speech_taxonomy();
        }
    }

    public function test_progress_view_renders_gender_section_when_marked_words_exist(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset_id = $this->createWordsetFixture(true);
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'progress');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('data-ll-wordset-progress-gender', $html);
        $this->assertStringContainsString('data-ll-wordset-progress-gender-toggle', $html);
        $this->assertStringContainsString('role="button"', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('data-ll-wordset-progress-gender-cards', $html);
        $this->assertStringContainsString('Only words with marked gender are counted.', $html);
        $this->assertStringNotContainsString('data-ll-wordset-progress-gender-categories', $html);
    }

    public function test_progress_view_omits_gender_section_when_no_marked_words_exist(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset_id = $this->createWordsetFixture(false);
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'progress');

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('data-ll-wordset-progress-gender', $html);
    }

    private function createWordsetFixture(bool $with_marked_gender_words): int
    {
        $wordset = wp_insert_term('Progress Gender Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
        update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);

        $category_term = wp_insert_term('Progress Gender Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');

        for ($index = 1; $index <= 5; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Progress Gender Word ' . $index . ' ' . wp_generate_password(4, false),
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$noun_term_id], 'part_of_speech', false);
            update_post_meta($word_id, 'word_translation', 'Progress Gender Translation ' . $index);
            if ($with_marked_gender_words) {
                update_post_meta($word_id, 'll_grammatical_gender', ($index % 2 === 0) ? 'Feminine' : 'Masculine');
            }

            $audio_post_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title' => 'Progress Gender Audio ' . $index,
            ]);
            update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/progress-gender-' . $index . '.mp3');
        }

        return $wordset_id;
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): int
    {
        $existing = term_exists($slug, 'part_of_speech');
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $created = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        if (is_wp_error($created)) {
            $term = get_term_by('slug', $slug, 'part_of_speech');
            $this->assertInstanceOf(WP_Term::class, $term);
            return (int) $term->term_id;
        }

        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }
}
