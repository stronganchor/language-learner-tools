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
        $empty_card = $this->extractCategoryCardMarkupByText($staff_html, 'Empty Owned Staff Category');
        $image_card = $this->extractCategoryCardMarkupByText($staff_html, 'Image Only Staff Category');

        $this->assertStringContainsString('Inactive Staff Only Category', $staff_html);
        $this->assertStringContainsString('Empty Owned Staff Category', $staff_html);
        $this->assertStringContainsString('Image Only Staff Category', $staff_html);
        $this->assertStringContainsString('ll-wordset-card--inactive', $inactive_card);
        $this->assertStringContainsString('data-ll-wordset-public="0"', $inactive_card);
        $this->assertStringContainsString('Not public', $inactive_card);
        $this->assertStringContainsString('Needs more quiz-ready words.', $inactive_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="preview"', $inactive_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="hide"', $inactive_card);
        $this->assertStringContainsString('ll-wordset-card__staff-action--delete', $inactive_card);
        $this->assertStringContainsString('disabled', $inactive_card);
        $this->assertStringNotContainsString('data-ll-wordset-select', $inactive_card);
        $this->assertStringNotContainsString('data-ll-wordset-category-mode', $inactive_card);
        $this->assertStringNotContainsString('href="', $inactive_card);

        $this->assertStringContainsString('No words yet.', $empty_card);
        $this->assertStringNotContainsString('ll_wordset_inactive_category_action" value="preview"', $empty_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="delete"', $empty_card);
        $this->assertStringNotContainsString('ll-wordset-card__staff-action--delete" disabled', $empty_card);

        $this->assertStringContainsString('Needs word records.', $image_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="preview"', $image_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="hide"', $image_card);
        $this->assertStringContainsString('ll-wordset-card__staff-action--delete', $image_card);
        $this->assertStringContainsString('disabled', $image_card);
    }

    public function test_preview_prepares_draft_words_from_word_images(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['image_only_category_id'];
        $image_id = (int) $fixture['image_id'];

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $prepared = ll_tools_wordset_page_prepare_word_images_for_lesson_preview($category_id, $wordset_id);

        $this->assertIsArray($prepared);
        $this->assertSame(1, (int) $prepared['image_count']);
        $this->assertSame(1, count($prepared['word_ids']));

        $word_id = (int) $prepared['word_ids'][0];
        $this->assertSame('draft', get_post_status($word_id));
        $this->assertSame($image_id, (int) get_post_meta($word_id, '_ll_autopicked_image_id', true));
        $this->assertContains($wordset_id, array_map('intval', wp_get_object_terms($word_id, 'wordset', ['fields' => 'ids'])));
        $this->assertContains($category_id, array_map('intval', wp_get_object_terms($word_id, 'word-category', ['fields' => 'ids'])));

        $lesson = ll_tools_get_or_create_vocab_lesson_preview_page($category_id, $wordset_id);
        $this->assertIsArray($lesson);
        $lesson_id = (int) $lesson['post_id'];
        $this->assertGreaterThan(0, $lesson_id);
        $this->assertSame('publish', get_post_status($lesson_id));
        $this->assertTrue(ll_tools_vocab_lesson_is_preview_only($lesson_id));
    }

    /**
     * @return array{wordset_id:int,image_only_category_id:int,image_id:int}
     */
    private function createWordsetFixture(): array
    {
        $wordset = wp_insert_term('Inactive Card Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $public_category = wp_insert_term('Public Staff Visible Category ' . wp_generate_password(4, false), 'word-category');
        $inactive_category = wp_insert_term('Inactive Staff Only Category ' . wp_generate_password(4, false), 'word-category');
        $empty_owned_category = wp_insert_term('Empty Owned Staff Category ' . wp_generate_password(4, false), 'word-category');
        $image_only_category = wp_insert_term('Image Only Staff Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($public_category));
        $this->assertFalse(is_wp_error($inactive_category));
        $this->assertFalse(is_wp_error($empty_owned_category));
        $this->assertFalse(is_wp_error($image_only_category));
        $this->assertIsArray($public_category);
        $this->assertIsArray($inactive_category);
        $this->assertIsArray($empty_owned_category);
        $this->assertIsArray($image_only_category);

        $public_category_id = (int) $public_category['term_id'];
        $inactive_category_id = (int) $inactive_category['term_id'];
        $empty_owned_category_id = (int) $empty_owned_category['term_id'];
        $image_only_category_id = (int) $image_only_category['term_id'];
        $owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id';
        foreach ([$public_category_id, $inactive_category_id, $empty_owned_category_id, $image_only_category_id] as $category_id) {
            update_term_meta($category_id, $owner_meta_key, (string) $wordset_id);
        }

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

        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Image Only Staff Category Image ' . wp_generate_password(4, false),
        ]);
        $attachment_id = $this->createImageAttachment();
        set_post_thumbnail($image_id, $attachment_id);
        if (function_exists('ll_tools_set_word_image_wordset_owner')) {
            ll_tools_set_word_image_wordset_owner((int) $image_id, $wordset_id);
        }
        wp_set_post_terms($image_id, [$image_only_category_id], 'word-category', false);
        wp_set_post_terms($image_id, [$wordset_id], 'wordset', false);

        return [
            'wordset_id' => $wordset_id,
            'image_only_category_id' => $image_only_category_id,
            'image_id' => (int) $image_id,
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

    private function createImageAttachment(): int
    {
        $attachment_id = wp_insert_attachment([
            'post_title' => 'Inactive Category Test Attachment ' . wp_generate_password(4, false),
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
        ]);
        $this->assertIsInt($attachment_id);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/04/inactive-category-test.jpg');

        return (int) $attachment_id;
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
