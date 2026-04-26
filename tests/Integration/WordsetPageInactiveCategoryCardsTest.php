<?php
declare(strict_types=1);

final class WordsetPageInactiveCategoryCardsTest extends LL_Tools_TestCase
{
    public function test_staff_can_see_inactive_unquizzable_category_cards(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];

        wp_set_current_user(0);
        $guest_html = $this->renderWordsetMainView($wordset_id);
        $this->assertStringContainsString('Public Staff Visible Category', $guest_html);
        $this->assertStringNotContainsString('Inactive Staff Only Category', $guest_html);

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $subscriber_html = $this->renderWordsetMainView($wordset_id);
        $this->assertStringNotContainsString('Inactive Staff Only Category', $subscriber_html);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $staff_html = $this->renderWordsetMainView($wordset_id);
        $inactive_card = $this->extractCategoryCardMarkupByText($staff_html, 'Inactive Staff Only Category');

        $this->assertStringContainsString('Inactive Staff Only Category', $staff_html);
        $this->assertStringContainsString('ll-wordset-card--inactive', $inactive_card);
        $this->assertStringContainsString('data-ll-wordset-public="0"', $inactive_card);
        $this->assertStringContainsString('Not public', $inactive_card);
        $this->assertStringContainsString('Needs more quiz-ready words.', $inactive_card);
        $this->assertStringNotContainsString('data-ll-wordset-select', $inactive_card);
        $this->assertStringNotContainsString('data-ll-wordset-category-mode', $inactive_card);
        $this->assertStringNotContainsString('href="', $inactive_card);
    }

    /**
     * @return array{wordset_id:int}
     */
    private function createWordsetFixture(): array
    {
        $wordset = wp_insert_term('Inactive Card Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $public_category = wp_insert_term('Public Staff Visible Category ' . wp_generate_password(4, false), 'word-category');
        $inactive_category = wp_insert_term('Inactive Staff Only Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($public_category));
        $this->assertFalse(is_wp_error($inactive_category));
        $this->assertIsArray($public_category);
        $this->assertIsArray($inactive_category);

        $public_category_id = (int) $public_category['term_id'];
        $inactive_category_id = (int) $inactive_category['term_id'];

        update_term_meta($public_category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($public_category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($inactive_category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($inactive_category_id, 'll_quiz_option_type', 'text_title');

        for ($index = 1; $index <= 5; $index++) {
            $this->createWord(
                'Public Staff Visible Word ' . $index,
                'Public Staff Visible Translation ' . $index,
                $public_category_id,
                $wordset_id
            );
        }

        $this->createWord(
            'Inactive Staff Only Word',
            'Inactive Staff Only Translation',
            $inactive_category_id,
            $wordset_id
        );

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Public Staff Visible Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $public_category_id);

        return [
            'wordset_id' => $wordset_id,
        ];
    }

    private function createWord(string $title, string $translation, int $category_id, int $wordset_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    private function renderWordsetMainView(int $wordset_id): string
    {
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            return ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }
    }

    private function extractCategoryCardMarkupByText(string $html, string $needle): string
    {
        $pattern = '/<article\b[^>]*>.*?' . preg_quote($needle, '/') . '.*?<\/article>/s';
        $matches = [];
        $found = preg_match($pattern, $html, $matches);
        $this->assertSame(1, $found, 'Expected wordset card markup containing ' . $needle . '.');
        return isset($matches[0]) ? (string) $matches[0] : '';
    }
}
