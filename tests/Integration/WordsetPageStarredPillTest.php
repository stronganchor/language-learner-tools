<?php
declare(strict_types=1);

final class WordsetPageStarredPillTest extends LL_Tools_TestCase
{
    public function test_main_view_renders_category_starred_pills_when_analytics_words_are_deferred(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_a_id = (int) $fixture['category_a_id'];
        $category_b_id = (int) $fixture['category_b_id'];
        $category_a_word_ids = array_values(array_map('intval', (array) ($fixture['category_a_word_ids'] ?? [])));
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $wordset_categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $wordset_category_ids = array_values(array_map('intval', wp_list_pluck((array) $wordset_categories, 'id')));
        $this->assertContains($category_a_id, $wordset_category_ids);
        $this->assertContains($category_b_id, $wordset_category_ids);

        ll_tools_save_user_study_state([
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_a_id, $category_b_id],
            'starred_word_ids' => [$category_a_word_ids[0], $category_a_word_ids[1]],
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id) use ($wordset_id): bool {
            if ((int) $filter_wordset_id === $wordset_id && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
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
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }

        $this->assertNotSame('', $html);

        $category_a_card = $this->extractCategoryCardMarkup($html, $category_a_id);
        $this->assertStringContainsString('ll-wordset-card__starred-pill', $category_a_card);
        $this->assertStringContainsString('ll-wordset-progress-star-glyph-icon', $category_a_card);
        $this->assertStringContainsString('ll-wordset-card__starred-value">2<', $category_a_card);

        $category_b_card = $this->extractCategoryCardMarkup($html, $category_b_id);
        $this->assertStringNotContainsString('ll-wordset-card__starred-pill', $category_b_card);
    }

    /**
     * @return array{
     *   wordset_id:int,
     *   category_a_id:int,
     *   category_b_id:int,
     *   category_a_word_ids:int[],
     *   category_b_word_ids:int[]
     * }
     */
    private function createWordsetFixture(): array
    {
        $wordset = wp_insert_term('Wordset Page Stars ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_a_term = wp_insert_term('Wordset Star Category A ' . wp_generate_password(6, false), 'word-category');
        $category_b_term = wp_insert_term('Wordset Star Category B ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_a_term));
        $this->assertFalse(is_wp_error($category_b_term));
        $this->assertIsArray($category_a_term);
        $this->assertIsArray($category_b_term);
        $category_a_id = (int) $category_a_term['term_id'];
        $category_b_id = (int) $category_b_term['term_id'];

        update_term_meta($category_a_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_a_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($category_b_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_b_id, 'll_quiz_option_type', 'text_title');

        $category_a_word_ids = [];
        $category_b_word_ids = [];

        for ($index = 1; $index <= 5; $index++) {
            $category_a_word_ids[] = $this->createWordWithAudio(
                'Wordset Stars A Word ' . $index,
                'Wordset Stars A Translation ' . $index,
                $category_a_id,
                $wordset_id,
                'wordset-stars-a-' . $index . '.mp3'
            );

            $category_b_word_ids[] = $this->createWordWithAudio(
                'Wordset Stars B Word ' . $index,
                'Wordset Stars B Translation ' . $index,
                $category_b_id,
                $wordset_id,
                'wordset-stars-b-' . $index . '.mp3'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'category_a_word_ids' => $category_a_word_ids,
            'category_b_word_ids' => $category_b_word_ids,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    private function extractCategoryCardMarkup(string $html, int $category_id): string
    {
        $pattern = '/<article\b[^>]*data-cat-id="' . preg_quote((string) $category_id, '/') . '"[^>]*>.*?<\/article>/s';
        $matches = [];
        $found = preg_match($pattern, $html, $matches);
        $this->assertSame(1, $found, 'Expected wordset card markup for category ' . $category_id . '.');
        return isset($matches[0]) ? (string) $matches[0] : '';
    }
}
