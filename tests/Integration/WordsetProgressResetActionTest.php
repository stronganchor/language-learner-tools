<?php
declare(strict_types=1);

final class WordsetProgressResetActionTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $serverBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        set_query_var('ll_wordset_page', null);
        set_query_var('ll_wordset_view', null);

        parent::tearDown();
    }

    public function test_progress_reset_category_scope_redirects_success_and_clears_only_selected_category(): void
    {
        $fixture = $this->createProgressResetFixture(2);
        wp_set_current_user((int) $fixture['user_id']);

        $targetCategoryId = (int) $fixture['category_ids'][0];
        $otherCategoryId = (int) $fixture['category_ids'][1];
        $targetWordId = (int) $fixture['studied_word_ids_by_category'][$targetCategoryId];
        $otherWordId = (int) $fixture['studied_word_ids_by_category'][$otherCategoryId];

        $redirectUrl = $this->runProgressResetRequest((string) $fixture['wordset_slug'], [
            'll_wordset_progress_reset_action' => 'category',
            'll_wordset_progress_reset_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_progress_reset_category_id' => (string) $targetCategoryId,
            'll_wordset_progress_reset_nonce' => wp_create_nonce('ll_wordset_progress_reset_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('ok', (string) ($query['ll_wordset_progress_reset'] ?? ''));
        $this->assertSame('category', (string) ($query['ll_wordset_progress_reset_scope'] ?? ''));
        $this->assertSame((string) $targetCategoryId, (string) ($query['ll_wordset_progress_reset_category'] ?? ''));

        $rows = ll_tools_get_user_word_progress_rows((int) $fixture['user_id'], [$targetWordId, $otherWordId]);
        $this->assertArrayNotHasKey($targetWordId, $rows);
        $this->assertArrayHasKey($otherWordId, $rows);
    }

    public function test_progress_reset_all_scope_redirects_success_and_clears_all_resettable_categories(): void
    {
        $fixture = $this->createProgressResetFixture(2);
        wp_set_current_user((int) $fixture['user_id']);

        $redirectUrl = $this->runProgressResetRequest((string) $fixture['wordset_slug'], [
            'll_wordset_progress_reset_action' => 'all',
            'll_wordset_progress_reset_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_progress_reset_nonce' => wp_create_nonce('ll_wordset_progress_reset_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('ok', (string) ($query['ll_wordset_progress_reset'] ?? ''));
        $this->assertSame('all', (string) ($query['ll_wordset_progress_reset_scope'] ?? ''));

        $rows = ll_tools_get_user_word_progress_rows((int) $fixture['user_id'], array_values($fixture['studied_word_ids_by_category']));
        foreach ((array) $fixture['studied_word_ids_by_category'] as $wordId) {
            $this->assertArrayNotHasKey((int) $wordId, $rows);
        }
    }

    public function test_progress_reset_redirects_permission_error_for_logged_out_user(): void
    {
        $fixture = $this->createProgressResetFixture(1);
        wp_set_current_user(0);

        $redirectUrl = $this->runProgressResetRequest((string) $fixture['wordset_slug'], [
            'll_wordset_progress_reset_action' => 'category',
            'll_wordset_progress_reset_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_progress_reset_category_id' => (string) $fixture['category_ids'][0],
            'll_wordset_progress_reset_nonce' => wp_create_nonce('ll_wordset_progress_reset_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('error', (string) ($query['ll_wordset_progress_reset'] ?? ''));
        $this->assertSame('permission', (string) ($query['ll_wordset_progress_reset_error'] ?? ''));
    }

    public function test_progress_reset_redirects_nonce_error_for_invalid_nonce(): void
    {
        $fixture = $this->createProgressResetFixture(1);
        wp_set_current_user((int) $fixture['user_id']);

        $redirectUrl = $this->runProgressResetRequest((string) $fixture['wordset_slug'], [
            'll_wordset_progress_reset_action' => 'category',
            'll_wordset_progress_reset_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_progress_reset_category_id' => (string) $fixture['category_ids'][0],
            'll_wordset_progress_reset_nonce' => 'invalid',
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('error', (string) ($query['ll_wordset_progress_reset'] ?? ''));
        $this->assertSame('nonce', (string) ($query['ll_wordset_progress_reset_error'] ?? ''));
    }

    public function test_progress_reset_redirects_category_error_for_invalid_category_scope(): void
    {
        $fixture = $this->createProgressResetFixture(2);
        wp_set_current_user((int) $fixture['user_id']);

        $redirectUrl = $this->runProgressResetRequest((string) $fixture['wordset_slug'], [
            'll_wordset_progress_reset_action' => 'category',
            'll_wordset_progress_reset_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_progress_reset_category_id' => (string) 999999,
            'll_wordset_progress_reset_nonce' => wp_create_nonce('ll_wordset_progress_reset_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('error', (string) ($query['ll_wordset_progress_reset'] ?? ''));
        $this->assertSame('category', (string) ($query['ll_wordset_progress_reset_error'] ?? ''));
    }

    public function test_progress_reset_all_scope_requires_two_categories_with_progress(): void
    {
        $fixture = $this->createProgressResetFixture(1);
        wp_set_current_user((int) $fixture['user_id']);

        $redirectUrl = $this->runProgressResetRequest((string) $fixture['wordset_slug'], [
            'll_wordset_progress_reset_action' => 'all',
            'll_wordset_progress_reset_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_progress_reset_nonce' => wp_create_nonce('ll_wordset_progress_reset_' . (int) $fixture['wordset_id']),
        ]);

        $query = $this->parseRedirectQuery($redirectUrl);
        $this->assertSame('error', (string) ($query['ll_wordset_progress_reset'] ?? ''));
        $this->assertSame('scope', (string) ($query['ll_wordset_progress_reset_error'] ?? ''));
    }

    /**
     * @param int $categoryCount
     * @return array{
     *   user_id:int,
     *   wordset_id:int,
     *   wordset_slug:string,
     *   category_ids:int[],
     *   studied_word_ids_by_category:array<int,int>
     * }
     */
    private function createProgressResetFixture(int $categoryCount): array
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);

        $wordset = wp_insert_term('Reset Action Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

        $wordsetId = (int) $wordset['term_id'];
        $wordsetTerm = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordsetTerm);

        $categoryIds = [];
        $studiedByCategory = [];
        for ($categoryIndex = 1; $categoryIndex <= $categoryCount; $categoryIndex++) {
            $term = wp_insert_term('Reset Action Category ' . $categoryIndex . ' ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($term));
            $this->assertIsArray($term);

            $categoryId = (int) $term['term_id'];
            $categoryIds[] = $categoryId;

            update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
            update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');

            $studiedWordId = 0;
            for ($wordIndex = 1; $wordIndex <= 5; $wordIndex++) {
                $wordId = $this->createWordWithAudio(
                    'Reset Action Word ' . $categoryIndex . '-' . $wordIndex,
                    'Reset Action Translation ' . $categoryIndex . '-' . $wordIndex,
                    $categoryId,
                    $wordsetId,
                    'reset-action-' . $categoryIndex . '-' . $wordIndex . '.mp3'
                );
                if ($wordIndex === 1) {
                    $studiedWordId = $wordId;
                }
            }
            $this->assertGreaterThan(0, $studiedWordId);

            $this->seedWordProgressRow($userId, $studiedWordId, $categoryId, $wordsetId, [
                'total_coverage' => 3,
                'coverage_practice' => 3,
                'correct_clean' => 2,
                'incorrect' => 1,
                'lapse_count' => 1,
                'stage' => 1,
            ]);

            $studiedByCategory[$categoryId] = $studiedWordId;
        }

        return [
            'user_id' => $userId,
            'wordset_id' => $wordsetId,
            'wordset_slug' => (string) $wordsetTerm->slug,
            'category_ids' => $categoryIds,
            'studied_word_ids_by_category' => $studiedByCategory,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $categoryId, int $wordsetId, string $audioFileName): int
    {
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        update_post_meta($wordId, 'word_translation', $translation);

        $audioPostId = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $wordId,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audioPostId, 'audio_file_path', '/wp-content/uploads/' . $audioFileName);

        return (int) $wordId;
    }

    private function seedWordProgressRow(int $userId, int $wordId, int $categoryId, int $wordsetId, array $overrides): void
    {
        global $wpdb;
        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];

        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'user_id' => $userId,
            'word_id' => $wordId,
            'category_id' => $categoryId,
            'wordset_id' => $wordsetId,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'due_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace($table, $data, [
            '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d',
            '%d', '%d', '%d', '%d', '%d', '%s', '%s',
        ]);
        $this->assertNotFalse($inserted);
    }

    private function runProgressResetRequest(string $wordsetSlug, array $post): string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [
            'll_wordset_page' => $wordsetSlug,
            'll_wordset_view' => 'progress',
        ];
        $_POST = $post;
        set_query_var('ll_wordset_page', $wordsetSlug);
        set_query_var('ll_wordset_view', 'progress');

        $redirectUrl = '';
        $redirectFilter = static function ($location) use (&$redirectUrl) {
            $redirectUrl = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirectFilter, 10, 1);

        try {
            ll_tools_wordset_page_handle_progress_reset_action();
            $this->fail('Expected progress reset handler to redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirectFilter, 10);
        }

        $this->assertNotSame('', $redirectUrl);
        return $redirectUrl;
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $decoded = [];
        parse_str($query, $decoded);
        return array_map('strval', $decoded);
    }
}
