<?php
declare(strict_types=1);

final class WordsWithoutQuizzableCategoryAdminTaskTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_REQUEST = [];

        remove_filter('ll_tools_quiz_min_words', [$this, 'forceQuizMinWordsToThree']);

        parent::tearDown();
    }

    public function test_words_without_quizzable_category_helpers_only_include_words_without_any_quizzable_category(): void
    {
        add_filter('ll_tools_quiz_min_words', [$this, 'forceQuizMinWordsToThree']);

        $quizzable_category_id = $this->createCategory('Quizzable Category ' . (string) wp_rand(1000, 9999));
        $non_quizzable_category_id = $this->createCategory('Non Quizzable Category ' . (string) wp_rand(1000, 9999));

        for ($index = 1; $index <= 3; $index++) {
            $this->createWord('Quizzable Word ' . $index, [$quizzable_category_id]);
        }

        $non_quizzable_word_id = $this->createWord('Needs Category Processing', [$non_quizzable_category_id]);
        $uncategorized_word_id = $this->createWord('Still Uncategorized');
        $this->createWord('Mixed Assignment', [$quizzable_category_id, $non_quizzable_category_id]);

        $count = ll_tools_get_words_without_quizzable_categories_count();
        $query = new WP_Query(ll_tools_get_words_without_quizzable_categories_query_args([
            'posts_per_page' => -1,
        ]));

        $word_ids = array_map('intval', (array) $query->posts);
        sort($word_ids, SORT_NUMERIC);

        $this->assertSame(2, $count);
        $this->assertSame([$non_quizzable_word_id, $uncategorized_word_id], $word_ids);
    }

    public function test_admin_maintenance_tasks_include_words_without_quizzable_category_task(): void
    {
        add_filter('ll_tools_quiz_min_words', [$this, 'forceQuizMinWordsToThree']);

        $quizzable_category_id = $this->createCategory('Task Quizzable Category ' . (string) wp_rand(1000, 9999));

        for ($index = 1; $index <= 3; $index++) {
            $this->createWord('Task Quizzable Word ' . $index, [$quizzable_category_id]);
        }

        $this->createWord('Task Missing Quizzable Category');
        $missing_count = ll_tools_get_words_without_quizzable_categories_count();

        $tasks = ll_tools_get_admin_maintenance_tasks();
        $task = $this->findTaskByKey($tasks, 'words_without_quizzable_category');

        $this->assertIsArray($task);
        $this->assertSame('edit-words', (string) ($task['screen_id'] ?? ''));
        $this->assertSame(
            ll_tools_get_words_no_quizzable_category_filter_value(),
            (string) (($task['screen_query_args']['ll_quiz_category_status'] ?? ''))
        );

        $decoded_url = html_entity_decode((string) ($task['url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $this->assertStringContainsString('post_type=words', $decoded_url);
        $this->assertStringContainsString(
            'll_quiz_category_status=' . ll_tools_get_words_no_quizzable_category_filter_value(),
            $decoded_url
        );
        $this->assertStringContainsString(
            sprintf(
                _n(
                    '%d word is not in any quizzable category',
                    '%d words are not in any quizzable category',
                    $missing_count,
                    'll-tools-text-domain'
                ),
                $missing_count
            ),
            (string) ($task['message'] ?? '')
        );
    }

    public function test_current_admin_task_screen_requires_matching_query_args_for_filtered_words_task(): void
    {
        $task = [
            'screen_id' => 'edit-words',
            'screen_query_args' => [
                'll_quiz_category_status' => ll_tools_get_words_no_quizzable_category_filter_value(),
            ],
        ];
        $screen = WP_Screen::get('edit-words');

        $_GET = ['post_type' => 'words'];
        $this->assertFalse(ll_tools_is_current_admin_task_screen($task, $screen));

        $_GET['ll_quiz_category_status'] = ll_tools_get_words_no_quizzable_category_filter_value();
        $this->assertTrue(ll_tools_is_current_admin_task_screen($task, $screen));
    }

    public function forceQuizMinWordsToThree($min): int
    {
        return 3;
    }

    private function createCategory(string $name): int
    {
        $term = wp_insert_term($name, 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $term_id = (int) $term['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($term_id, 'll_quiz_option_type', 'text_translation');

        return $term_id;
    }

    /**
     * @param int[] $category_ids
     */
    private function createWord(string $title, array $category_ids = []): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        if (!empty($category_ids)) {
            wp_set_post_terms($word_id, array_map('intval', $category_ids), 'word-category', false);
        }

        update_post_meta($word_id, 'word_translation', $title . ' Translation');

        return (int) $word_id;
    }

    /**
     * @param array<int,array<string,mixed>> $tasks
     * @return array<string,mixed>|null
     */
    private function findTaskByKey(array $tasks, string $key): ?array
    {
        foreach ($tasks as $task) {
            if (($task['key'] ?? '') === $key) {
                return $task;
            }
        }

        return null;
    }
}
