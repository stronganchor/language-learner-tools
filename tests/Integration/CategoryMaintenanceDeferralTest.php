<?php
declare(strict_types=1);

final class CategoryMaintenanceDeferralTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        delete_option('ll_vocab_lesson_wordsets');
        delete_option('ll_tools_quiz_page_sync_last');
        delete_option('ll_tools_vocab_lesson_sync_last');
        delete_transient('ll_tools_skip_sync_until_seeded');
        delete_transient('ll_tools_seed_default_wordset');
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        parent::tearDown();
    }

    public function test_deferred_category_maintenance_defers_quiz_and_vocab_generation_until_flush(): void
    {
        $fixture = $this->createQuizzableCategoryFixture();

        $this->assertSame(0, $this->findQuizPageId($fixture['category_id']));
        $this->assertSame(0, $this->findVocabLessonId($fixture['category_id'], $fixture['wordset_id']));

        ll_tools_begin_deferred_category_maintenance('category-maintenance-test');
        ll_tools_handle_category_sync($fixture['category_id']);
        ll_tools_handle_category_sync($fixture['category_id']);
        ll_tools_sync_vocab_lessons_for_category($fixture['category_id']);
        ll_tools_sync_vocab_lessons_for_category($fixture['category_id']);

        $this->assertSame(0, $this->findQuizPageId($fixture['category_id']));
        $this->assertSame(0, $this->findVocabLessonId($fixture['category_id'], $fixture['wordset_id']));

        ll_tools_end_deferred_category_maintenance(true);

        $quiz_page_id = $this->findQuizPageId($fixture['category_id']);
        $lesson_id = $this->findVocabLessonId($fixture['category_id'], $fixture['wordset_id']);

        $this->assertGreaterThan(0, $quiz_page_id);
        $this->assertGreaterThan(0, $lesson_id);
        $this->assertSame('publish', get_post_status($quiz_page_id));
        $this->assertSame('publish', get_post_status($lesson_id));
    }

    public function test_schedule_helpers_queue_background_sync_without_running_inline_generation(): void
    {
        $fixture = $this->createQuizzableCategoryFixture();

        delete_option('ll_tools_quiz_page_sync_last');
        delete_option('ll_tools_vocab_lesson_sync_last');
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        ll_tools_schedule_quiz_page_full_sync(0);
        ll_tools_schedule_vocab_lesson_full_sync(0);

        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $this->assertSame(0, $this->findQuizPageId($fixture['category_id']));
        $this->assertSame(0, $this->findVocabLessonId($fixture['category_id'], $fixture['wordset_id']));

        do_action(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        do_action(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        $this->assertGreaterThan(0, $this->findQuizPageId($fixture['category_id']));
        $this->assertNotFalse(has_action(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT, 'll_tools_sync_vocab_lesson_pages'));
    }

    /**
     * @return array{wordset_id:int, category_id:int}
     */
    private function createQuizzableCategoryFixture(): array
    {
        $suffix = strtolower(wp_generate_password(6, false));
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $min_words_filter = static function ($min_words = 0): int {
            unset($min_words);
            return 999;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset = wp_insert_term('Deferred Wordset ' . $suffix, 'wordset', [
                'slug' => 'deferred-wordset-' . $suffix,
            ]);
            $this->assertIsArray($wordset);
            $wordset_id = (int) $wordset['term_id'];

            if (function_exists('ll_tools_create_or_get_wordset_category')) {
                $category_id = (int) ll_tools_create_or_get_wordset_category('Deferred Category ' . $suffix, $wordset_id, [
                    'slug' => 'deferred-category-' . $suffix,
                ]);
            } else {
                $category = wp_insert_term('Deferred Category ' . $suffix, 'word-category', [
                    'slug' => 'deferred-category-' . $suffix,
                ]);
                $this->assertIsArray($category);
                $category_id = (int) $category['term_id'];
            }
            $this->assertGreaterThan(0, $category_id);

            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

            for ($index = 1; $index <= LL_TOOLS_MIN_WORDS_PER_QUIZ; $index++) {
                $word_id = self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => 'Deferred Word ' . $suffix . ' ' . $index,
                    'post_name' => 'deferred-word-' . $suffix . '-' . $index,
                ]);
                update_post_meta($word_id, 'word_translation', 'Deferred Translation ' . $index);
                update_post_meta($word_id, 'word_english_meaning', 'Deferred Translation ' . $index);
                wp_set_post_terms($word_id, [$category_id], 'word-category', false);
                wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            }
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }

        $this->deleteGeneratedPagesForFixture($category_id, $wordset_id);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
        ];
    }

    private function deleteGeneratedPagesForFixture(int $category_id, int $wordset_id): void
    {
        $quiz_page_id = $this->findQuizPageId($category_id);
        if ($quiz_page_id > 0) {
            wp_delete_post($quiz_page_id, true);
        }

        $lesson_id = $this->findVocabLessonId($category_id, $wordset_id);
        if ($lesson_id > 0) {
            wp_delete_post($lesson_id, true);
        }
    }

    private function findQuizPageId(int $category_id): int
    {
        $ids = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'meta_key' => '_ll_tools_word_category_id',
            'meta_value' => (string) $category_id,
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        return (int) ($ids[0] ?? 0);
    }

    private function findVocabLessonId(int $category_id, int $wordset_id): int
    {
        $ids = get_posts([
            'post_type' => 'll_vocab_lesson',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
                    'value' => $category_id,
                    'compare' => '=',
                ],
                [
                    'key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
                    'value' => $wordset_id,
                    'compare' => '=',
                ],
            ],
        ]);

        return (int) ($ids[0] ?? 0);
    }
}
