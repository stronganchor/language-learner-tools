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
        $this->assertCategoryAppearsBefore($staff_html, 'Public Staff Visible Category', 'Inactive Staff Only Category');
        $this->assertCategoryAppearsBefore($staff_html, 'Public Staff Visible Category', 'Empty Owned Staff Category');
        $this->assertCategoryAppearsBefore($staff_html, 'Public Staff Visible Category', 'Image Only Staff Category');
        $this->assertStringContainsString('ll-wordset-card--inactive', $inactive_card);
        $this->assertStringContainsString('data-ll-wordset-public="0"', $inactive_card);
        $this->assertStringContainsString('Not public', $inactive_card);
        $this->assertStringContainsString('Needs more quiz-ready words.', $inactive_card);
        $this->assertStringContainsString('data-ll-wordset-inactive-preview-card="true"', $inactive_card);
        $this->assertStringContainsString('data-ll-wordset-inactive-preview-trigger', $inactive_card);
        $this->assertStringContainsString('ll-wordset-card__inactive-preview-form', $inactive_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="preview"', $inactive_card);
        $this->assertStringNotContainsString('ll-wordset-card__staff-actions', $inactive_card);
        $this->assertStringNotContainsString('ll-wordset-card__staff-action--preview', $inactive_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="hide"', $inactive_card);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--hide', $inactive_card);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--delete', $inactive_card);
        $this->assertStringContainsString('ll-wordset-trash-icon', $inactive_card);
        $this->assertStringContainsString('disabled', $inactive_card);
        $this->assertStringNotContainsString('data-ll-wordset-select', $inactive_card);
        $this->assertStringNotContainsString('data-ll-wordset-category-mode', $inactive_card);
        $this->assertStringNotContainsString('href="', $inactive_card);

        $this->assertStringContainsString('No words yet.', $empty_card);
        $this->assertStringNotContainsString('data-ll-wordset-inactive-preview-card="true"', $empty_card);
        $this->assertStringNotContainsString('data-ll-wordset-inactive-preview-trigger', $empty_card);
        $this->assertStringNotContainsString('ll-wordset-card__inactive-preview-form', $empty_card);
        $this->assertStringNotContainsString('ll_wordset_inactive_category_action" value="preview"', $empty_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="delete"', $empty_card);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--delete', $empty_card);
        $this->assertStringNotContainsString('ll-wordset-card__inactive-action--delete" disabled', $empty_card);

        $this->assertStringContainsString('Needs word records.', $image_card);
        $this->assertStringContainsString('data-ll-wordset-inactive-preview-card="true"', $image_card);
        $this->assertStringContainsString('data-ll-wordset-inactive-preview-trigger', $image_card);
        $this->assertStringContainsString('ll-wordset-card__inactive-preview-form', $image_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="preview"', $image_card);
        $this->assertStringNotContainsString('ll-wordset-card__staff-actions', $image_card);
        $this->assertStringNotContainsString('ll-wordset-card__staff-action--preview', $image_card);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="hide"', $image_card);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--delete', $image_card);
        $this->assertStringContainsString('ll-wordset-trash-icon', $image_card);
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

    public function test_inactive_card_backfills_action_icons_when_action_state_is_missing(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $inactive_category_id = (int) $fixture['inactive_category_id'];

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $inactive_category = null;
        foreach ($categories as $category) {
            if ((int) ($category['id'] ?? 0) === $inactive_category_id) {
                $inactive_category = $category;
                break;
            }
        }
        $this->assertIsArray($inactive_category);

        unset(
            $inactive_category['can_manage_inactive'],
            $inactive_category['can_hide'],
            $inactive_category['can_preview'],
            $inactive_category['can_delete'],
            $inactive_category['delete_reason'],
            $inactive_category['inactive_action_nonce'],
            $inactive_category['inactive_action_url']
        );

        $html = ll_tools_wordset_page_render_category_card($inactive_category, ['is_study_user' => true]);

        $this->assertStringContainsString('ll-wordset-card__inactive-actions', $html);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--hide', $html);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--delete', $html);
        $this->assertStringContainsString('ll-wordset-trash-icon', $html);
        $this->assertStringContainsString('ll_wordset_inactive_category_nonce', $html);
    }

    public function test_logged_in_viewer_gets_hide_icon_for_visible_inactive_card(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $inactive_category_id = (int) $fixture['inactive_category_id'];

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $html = ll_tools_wordset_page_render_category_card([
            'id' => $inactive_category_id,
            'wordset_id' => $wordset_id,
            'name' => 'Visible Draft Category',
            'count' => 1,
            'preview' => [],
            'has_images' => false,
            'is_public' => false,
            'public_note' => 'Needs more quiz-ready words.',
        ], ['is_study_user' => true]);

        $this->assertStringContainsString('ll-wordset-card__inactive-actions', $html);
        $this->assertStringContainsString('ll_wordset_inactive_category_action" value="hide"', $html);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--hide', $html);
        $this->assertStringContainsString('ll-wordset-trash-icon', $html);
        $this->assertStringContainsString('ll-wordset-card__inactive-action--delete" disabled', $html);
        $this->assertStringNotContainsString('data-ll-wordset-inactive-preview-trigger', $html);
        $this->assertStringNotContainsString('ll-wordset-card__inactive-preview-form', $html);
    }

    public function test_inactive_category_action_process_hides_category_for_logged_in_viewer(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $inactive_category_id = (int) $fixture['inactive_category_id'];

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);

        $result = ll_tools_wordset_page_process_inactive_category_action(
            'hide',
            $wordset_id,
            $wordset_id,
            $inactive_category_id,
            wp_create_nonce('ll_wordset_inactive_category_' . $wordset_id . '_' . $inactive_category_id)
        );

        $this->assertIsArray($result);
        $this->assertSame('hidden', $result['result']);
        $goals = ll_tools_get_user_study_goals($subscriber_id);
        $this->assertContains($inactive_category_id, array_map('intval', (array) ($goals['ignored_category_ids'] ?? [])));
    }

    public function test_inactive_category_action_process_deletes_empty_owned_category(): void
    {
        $fixture = $this->createWordsetFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['empty_owned_category_id'];

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $result = ll_tools_wordset_page_process_inactive_category_action(
            'delete',
            $wordset_id,
            $wordset_id,
            $category_id,
            wp_create_nonce('ll_wordset_inactive_category_' . $wordset_id . '_' . $category_id)
        );

        $this->assertIsArray($result);
        $this->assertSame('deleted', $result['result']);
        $deleted_term = get_term($category_id, 'word-category');
        $this->assertTrue($deleted_term === null || is_wp_error($deleted_term));
    }

    /**
     * @return array{wordset_id:int,inactive_category_id:int,empty_owned_category_id:int,image_only_category_id:int,image_id:int}
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
            'inactive_category_id' => $inactive_category_id,
            'empty_owned_category_id' => $empty_owned_category_id,
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
        $pattern = '/<article\b[^>]*>.*?<\/article>/s';
        $matches = [];
        $found = preg_match_all($pattern, $html, $matches);
        $this->assertGreaterThan(0, $found, 'Expected wordset card markup.');
        foreach ((array) ($matches[0] ?? []) as $match) {
            if (strpos((string) $match, $needle) !== false) {
                return (string) $match;
            }
        }

        $this->fail('Expected wordset card markup containing ' . $needle . '.');
    }

    private function assertCategoryAppearsBefore(string $html, string $first, string $second): void
    {
        $first_position = strpos($html, $first);
        $second_position = strpos($html, $second);
        $this->assertNotFalse($first_position, 'Expected markup containing ' . $first . '.');
        $this->assertNotFalse($second_position, 'Expected markup containing ' . $second . '.');
        $this->assertLessThan($second_position, $first_position, $first . ' should render before ' . $second . '.');
    }
}
