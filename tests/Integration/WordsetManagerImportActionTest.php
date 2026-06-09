<?php
declare(strict_types=1);

final class WordsetManagerImportActionTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);
        set_query_var('ll_wordset_page', '');

        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        parent::tearDown();
    }

    public function test_manager_import_schedules_follow_up_category_sync_instead_of_running_inline(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetCategoryFixture();
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET['ll_wordset_page'] = $fixture['wordset_slug'];
        set_query_var('ll_wordset_page', $fixture['wordset_slug']);
        $_POST = [
            'll_wordset_manager_import_action' => 'import_pairs',
            'll_wordset_manager_import_wordset_id' => (string) $fixture['wordset_id'],
            'll_wordset_manager_import_nonce' => wp_create_nonce('ll_wordset_manager_import_' . $fixture['wordset_id']),
            'll_wordset_manager_import_pairs' => "lion\tdoud\nsheep\tgnemai\ndog\tkalib\ncricket\tjarad\ncat\tbissa\n",
            'll_wordset_manager_import_existing_category' => (string) $fixture['category_id'],
            'll_wordset_view' => 'settings',
            'll_wordset_tool' => 'import',
            'll_wordset_back' => home_url('/'),
        ];
        $_REQUEST = $_POST;

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_wordset_page_handle_manager_import_action();
        });
        $redirect_query = $this->parseRedirectQuery($redirect_url);

        $this->assertSame('ok', (string) ($redirect_query['ll_wordset_manager_import'] ?? ''));
        $this->assertSame('5', (string) ($redirect_query['ll_wordset_manager_import_created'] ?? ''));
        $this->assertCount(5, $this->findWordIdsInScope($fixture['wordset_id'], $fixture['category_id']));

        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $this->assertSame(0, $this->findQuizPageId($fixture['category_id']));

        do_action(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        $quiz_page_id = $this->findQuizPageId($fixture['category_id']);
        $this->assertGreaterThan(0, $quiz_page_id);
        $this->assertSame(LL_TOOLS_QUIZ_PAGE_POST_TYPE, get_post_type($quiz_page_id));
    }

    public function test_manager_import_rejects_oversized_paste_before_creating_category_or_words(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetCategoryFixture();
        $new_category_name = 'Oversized Import Category ' . strtolower(wp_generate_password(6, false));
        $max_bytes_filter = static function (): int {
            return 12;
        };
        add_filter('ll_tools_wordset_manager_import_max_bytes', $max_bytes_filter);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET['ll_wordset_page'] = $fixture['wordset_slug'];
            set_query_var('ll_wordset_page', $fixture['wordset_slug']);
            $_POST = [
                'll_wordset_manager_import_action' => 'import_pairs',
                'll_wordset_manager_import_wordset_id' => (string) $fixture['wordset_id'],
                'll_wordset_manager_import_nonce' => wp_create_nonce('ll_wordset_manager_import_' . $fixture['wordset_id']),
                'll_wordset_manager_import_pairs' => "lion\tdoud\nsheep\tgnemai\n",
                'll_wordset_manager_import_new_category' => $new_category_name,
                'll_wordset_view' => 'settings',
                'll_wordset_tool' => 'import',
                'll_wordset_back' => home_url('/'),
            ];
            $_REQUEST = $_POST;

            $redirect_url = $this->captureRedirect(static function (): void {
                ll_tools_wordset_page_handle_manager_import_action();
            });
        } finally {
            remove_filter('ll_tools_wordset_manager_import_max_bytes', $max_bytes_filter);
        }

        $redirect_query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('error', (string) ($redirect_query['ll_wordset_manager_import'] ?? ''));
        $this->assertSame('too_large', (string) ($redirect_query['ll_wordset_manager_import_error'] ?? ''));
        $this->assertSame('12', (string) ($redirect_query['ll_wordset_manager_import_max_bytes'] ?? ''));
        $this->assertEmpty(term_exists($new_category_name, 'word-category'));
        $this->assertSame([], $this->findWordIdsInWordset($fixture['wordset_id']));

        $_GET = $redirect_query;
        $notice = ll_tools_wordset_page_manager_import_notice();
        $this->assertIsArray($notice);
        $this->assertSame('error', (string) ($notice['type'] ?? ''));
        $this->assertStringContainsString('too large', (string) ($notice['message'] ?? ''));
        $this->assertStringContainsString('split the list', (string) ($notice['message'] ?? ''));
    }

    public function test_manager_import_rejects_too_many_parsed_rows_before_creating_category_or_words(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $fixture = $this->createWordsetCategoryFixture();
        $new_category_name = 'Too Many Rows Import Category ' . strtolower(wp_generate_password(6, false));
        $max_rows_filter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_wordset_manager_import_max_rows', $max_rows_filter);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET['ll_wordset_page'] = $fixture['wordset_slug'];
            set_query_var('ll_wordset_page', $fixture['wordset_slug']);
            $_POST = [
                'll_wordset_manager_import_action' => 'import_pairs',
                'll_wordset_manager_import_wordset_id' => (string) $fixture['wordset_id'],
                'll_wordset_manager_import_nonce' => wp_create_nonce('ll_wordset_manager_import_' . $fixture['wordset_id']),
                'll_wordset_manager_import_pairs' => "lion\tdoud\nsheep\tgnemai\ndog\tkalib\n",
                'll_wordset_manager_import_new_category' => $new_category_name,
                'll_wordset_view' => 'settings',
                'll_wordset_tool' => 'import',
                'll_wordset_back' => home_url('/'),
            ];
            $_REQUEST = $_POST;

            $redirect_url = $this->captureRedirect(static function (): void {
                ll_tools_wordset_page_handle_manager_import_action();
            });
        } finally {
            remove_filter('ll_tools_wordset_manager_import_max_rows', $max_rows_filter);
        }

        $redirect_query = $this->parseRedirectQuery($redirect_url);
        $this->assertSame('error', (string) ($redirect_query['ll_wordset_manager_import'] ?? ''));
        $this->assertSame('too_many_rows', (string) ($redirect_query['ll_wordset_manager_import_error'] ?? ''));
        $this->assertSame('2', (string) ($redirect_query['ll_wordset_manager_import_max_rows'] ?? ''));
        $this->assertEmpty(term_exists($new_category_name, 'word-category'));
        $this->assertSame([], $this->findWordIdsInWordset($fixture['wordset_id']));

        $_GET = $redirect_query;
        $notice = ll_tools_wordset_page_manager_import_notice();
        $this->assertIsArray($notice);
        $this->assertSame('error', (string) ($notice['type'] ?? ''));
        $this->assertStringContainsString('2 rows or fewer', (string) ($notice['message'] ?? ''));
        $this->assertStringContainsString('split the list', (string) ($notice['message'] ?? ''));
    }

    /**
     * @return array{wordset_id:int, wordset_slug:string, category_id:int}
     */
    private function createWordsetCategoryFixture(): array
    {
        $suffix = strtolower(wp_generate_password(6, false));
        $wordset = wp_insert_term('Manager Import Wordset ' . $suffix, 'wordset', [
            'slug' => 'manager-import-wordset-' . $suffix,
        ]);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $wordset_slug = (string) get_term_field('slug', $wordset_id, 'wordset');

        if (function_exists('ll_tools_create_or_get_wordset_category')) {
            $category_id = (int) ll_tools_create_or_get_wordset_category('Manager Import Category ' . $suffix, $wordset_id, [
                'slug' => 'manager-import-category-' . $suffix,
            ]);
        } else {
            $category = wp_insert_term('Manager Import Category ' . $suffix, 'word-category', [
                'slug' => 'manager-import-category-' . $suffix,
            ]);
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];
        }

        $this->assertGreaterThan(0, $category_id);

        return [
            'wordset_id' => $wordset_id,
            'wordset_slug' => $wordset_slug,
            'category_id' => $category_id,
        ];
    }

    /**
     * @return int[]
     */
    private function findWordIdsInScope(int $wordset_id, int $category_id): array
    {
        $ids = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                'relation' => 'AND',
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$wordset_id],
                ],
                [
                    'taxonomy' => 'word-category',
                    'field' => 'term_id',
                    'terms' => [$category_id],
                ],
            ],
        ]);

        return array_values(array_map('intval', (array) $ids));
    }

    /**
     * @return int[]
     */
    private function findWordIdsInWordset(int $wordset_id): array
    {
        $ids = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$wordset_id],
                ],
            ],
        ]);

        return array_values(array_map('intval', (array) $ids));
    }

    private function findQuizPageId(int $category_id): int
    {
        $ids = get_posts([
            'post_type' => ll_tools_get_quiz_page_post_types(true),
            'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
            'meta_key' => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
            'meta_value' => (string) $category_id,
            'numberposts' => 1,
            'fields' => 'ids',
        ]);

        return (int) ($ids[0] ?? 0);
    }

    private function captureRedirect(callable $callback): string
    {
        $redirect_url = '';
        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
        }

        $this->assertNotSame('', $redirect_url);
        return $redirect_url;
    }

    /**
     * @return array<string, string>
     */
    private function parseRedirectQuery(string $redirect_url): array
    {
        $parts = wp_parse_url($redirect_url);
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        return array_map('strval', $query);
    }
}
