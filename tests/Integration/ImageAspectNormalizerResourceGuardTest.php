<?php
declare(strict_types=1);

final class ImageAspectNormalizerResourceGuardTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];
    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_worklist_refreshes_one_bounded_page_and_row_action_reads_cached_status_only(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        $category_ids = [];
        for ($index = 1; $index <= 7; $index++) {
            $created = wp_insert_term(sprintf('Aspect Resource Category %02d', $index), 'word-category');
            $this->assertIsArray($created);
            $category_id = (int) $created['term_id'];
            update_term_meta($category_id, 'll_quiz_option_type', 'image');
            $category_ids[] = $category_id;
        }

        $page_size_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_aspect_normalizer_worklist_page_size', $page_size_filter);

        $term_queries = [];
        $term_query_filter = static function (array $args, array $taxonomies) use (&$term_queries): array {
            if (in_array('word-category', array_map('strval', $taxonomies), true)) {
                $term_queries[] = $args;
            }
            return $args;
        };
        $aspect_queries = [];
        $post_query_watcher = static function (WP_Query $query) use (&$aspect_queries): void {
            if (in_array($query->get('post_type'), ['words', 'word_images'], true)) {
                $aspect_queries[] = [
                    'post_type' => (string) $query->get('post_type'),
                    'posts_per_page' => (int) $query->get('posts_per_page'),
                ];
            }
        };
        add_filter('get_terms_args', $term_query_filter, 10, 2);
        add_action('pre_get_posts', $post_query_watcher);

        try {
            $_POST = [
                'nonce' => wp_create_nonce(LL_TOOLS_ASPECT_NORMALIZER_NONCE_ACTION),
                'offset' => '0',
            ];
            $_REQUEST = $_POST;
            $response = $this->runJsonEndpoint('ll_tools_aspect_normalizer_worklist_ajax');

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertSame([], (array) ($response['data']['categories'] ?? []));
            $this->assertTrue((bool) ($response['data']['has_more'] ?? false));
            $this->assertSame(3, (int) ($response['data']['next_offset'] ?? 0));
            $this->assertSame(3, (int) ($response['data']['page_size'] ?? 0));
        } finally {
            remove_filter('get_terms_args', $term_query_filter, 10);
            remove_filter('ll_tools_aspect_normalizer_worklist_page_size', $page_size_filter);
        }

        $bounded_term_queries = array_values(array_filter($term_queries, static function (array $args): bool {
            return isset($args['number']) && (int) $args['number'] > 0;
        }));
        $this->assertNotEmpty($bounded_term_queries);
        $this->assertSame(4, (int) $bounded_term_queries[0]['number']);
        $this->assertCount(6, $aspect_queries, 'Three category refreshes should issue only one words and one word_images stats query each.');
        $this->assertIsArray(get_term_meta($category_ids[2], LL_TOOLS_CATEGORY_ASPECT_STATUS_META_KEY, true));
        $this->assertSame('', (string) get_term_meta($category_ids[3], LL_TOOLS_CATEGORY_ASPECT_STATUS_META_KEY, true));

        update_term_meta($category_ids[0], LL_TOOLS_CATEGORY_ASPECT_STATUS_META_KEY, [
            'cache_version' => ll_tools_get_category_aspect_cache_version($category_ids[0]),
            'needs_fix' => 1,
            'offending_count' => 1,
            'total_attachments' => 2,
            'ratio_count' => 2,
        ]);
        set_current_screen('edit-word-category');
        $term = get_term($category_ids[0], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);
        $query_count_before_row = count($aspect_queries);
        $actions = ll_tools_add_word_category_aspect_row_action(['edit' => 'Edit'], $term);
        $this->assertArrayHasKey('ll_tools_aspect_normalize', $actions);
        $this->assertSame($query_count_before_row, count($aspect_queries));

        ll_tools_clear_category_aspect_cache($category_ids[0]);
        $stale_actions = ll_tools_add_word_category_aspect_row_action(['edit' => 'Edit'], $term);
        $this->assertArrayNotHasKey('ll_tools_aspect_normalize', $stale_actions);
        $this->assertSame($query_count_before_row, count($aspect_queries));
        remove_action('pre_get_posts', $post_query_watcher);
    }

    /** @return array<string,mixed> */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);
        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON, got: ' . $output);
        return $decoded;
    }
}
