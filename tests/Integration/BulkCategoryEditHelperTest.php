<?php
declare(strict_types=1);

final class BulkCategoryEditHelperTest extends LL_Tools_TestCase
{
    public function test_common_category_helper_intersects_only_eligible_posts_for_type(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $cat_a = $this->createCategory('Bulk Cat A');
        $cat_b = $this->createCategory('Bulk Cat B');
        $cat_c = $this->createCategory('Bulk Cat C');

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Word One',
        ]);
        wp_set_post_terms($word_one, [$cat_a, $cat_b], 'word-category', false);

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Word Two',
        ]);
        wp_set_post_terms($word_two, [$cat_a, $cat_c], 'word-category', false);

        $image_post = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Image One',
        ]);
        wp_set_post_terms($image_post, [$cat_b], 'word-category', false);

        $result = ll_tools_get_common_word_category_ids_for_posts(
            [$word_one, $word_two, $image_post, 999999],
            'words'
        );

        $this->assertSame([$cat_a], array_values(array_map('intval', $result)));
    }

    public function test_common_category_helper_returns_empty_when_no_eligible_posts(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $image_post = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Image Two',
        ]);

        $result = ll_tools_get_common_word_category_ids_for_posts([$image_post], 'words');
        $this->assertSame([], $result);
    }

    public function test_common_category_ajax_rejects_oversized_raw_post_ids_before_hydration(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $limitFilter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_bulk_common_category_post_ids_limit', $limitFilter);

        $_POST = [
            'nonce' => wp_create_nonce('ll_bulk_category_edit_words'),
            'post_ids' => [101, 102, 103],
        ];
        $_REQUEST = $_POST;
        $queries = [];
        $queryWatcher = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $queryWatcher);

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_get_common_categories_for_post_type('words');
            });
        } finally {
            remove_filter('query', $queryWatcher);
            remove_filter('ll_tools_bulk_common_category_post_ids_limit', $limitFilter);
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame(
            'll_tools_bulk_common_categories_too_large',
            (string) ($response['data']['code'] ?? '')
        );
        $querySql = implode("\n", $queries);
        $this->assertStringNotContainsString("FROM {$wpdb->posts}", $querySql);
    }

    public function test_common_category_request_guard_rejects_noncanonical_post_ids(): void
    {
        foreach ([true, false, 1.5, '123junk', '01', '0', '-1', '+1', ' 1', str_repeat('9', 20)] as $invalidId) {
            $error = ll_tools_bulk_common_category_post_ids_request_error([$invalidId]);
            $this->assertWPError($error);
            $this->assertSame('ll_tools_bulk_common_categories_input_invalid', $error->get_error_code());
        }

        $this->assertNull(ll_tools_bulk_common_category_post_ids_request_error([1, '2']));
    }

    public function test_common_category_helper_fails_closed_when_one_term_read_fails(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $categoryId = $this->createCategory('Bulk Source Failure');
        $firstWordId = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        $secondWordId = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        wp_set_post_terms($firstWordId, [$categoryId], 'word-category', false);
        wp_set_post_terms($secondWordId, [$categoryId], 'word-category', false);

        $failed = false;
        $termFailure = static function ($terms, $objectIds, $taxonomies) use ($secondWordId, &$failed) {
            if (!$failed && str_contains((string) $taxonomies, 'word-category') && str_contains((string) $objectIds, (string) $secondWordId)) {
                $failed = true;
                return new WP_Error('forced_term_read_failure', 'forced');
            }
            return $terms;
        };
        add_filter('wp_get_object_terms', $termFailure, 10, 3);
        try {
            $result = ll_tools_get_common_word_category_ids_for_posts([$firstWordId, $secondWordId], 'words');
        } finally {
            remove_filter('wp_get_object_terms', $termFailure, 10);
        }

        $this->assertTrue($failed);
        $this->assertWPError($result);
        $this->assertSame('ll_tools_bulk_common_categories_source_unavailable', $result->get_error_code());
        $this->assertSame(503, (int) (($result->get_error_data()['status'] ?? 0)));
    }

    public function test_common_category_helper_fails_closed_on_low_level_term_query_error(): void
    {
        global $wpdb;

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);
        $categoryId = $this->createCategory('Bulk Database Failure');
        $wordId = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);

        $termFailure = static function ($terms, $objectIds, $taxonomies) use ($wordId, $wpdb) {
            if (str_contains((string) $taxonomies, 'word-category') && str_contains((string) $objectIds, (string) $wordId)) {
                $wpdb->last_error = 'forced low-level term query failure';
                return [];
            }
            return $terms;
        };
        add_filter('wp_get_object_terms', $termFailure, 10, 3);
        try {
            $result = ll_tools_get_common_word_category_ids_for_posts([$wordId], 'words');
        } finally {
            remove_filter('wp_get_object_terms', $termFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertWPError($result);
        $this->assertSame('ll_tools_bulk_common_categories_source_unavailable', $result->get_error_code());
        $this->assertSame(503, (int) (($result->get_error_data()['status'] ?? 0)));
    }

    private function createCategory(string $label): int
    {
        $term = wp_insert_term($label . ' ' . wp_generate_password(5, false), 'word-category');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static fn (): bool => true;

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
