<?php
declare(strict_types=1);

final class WordsetManualOrderingResourceTest extends LL_Tools_TestCase
{
    private function lesson(int $wordset, int $category, string $date, string $gmt = ''): int
    {
        global $wpdb;
        $id = self::factory()->post->create(['post_type' => 'll_vocab_lesson', 'post_status' => 'draft']);
        update_post_meta($id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset);
        update_post_meta($id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category);
        $wpdb->update($wpdb->posts, ['post_status' => 'publish', 'post_date' => $date, 'post_date_gmt' => $gmt ?: $date], ['ID' => $id]);
        clean_post_cache($id);
        return $id;
    }

    public function test_category_aggregate_preserves_first_local_date_utc_value_and_exact_scope(): void
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $first = $this->lesson($wordset, 401, '2020-01-02 04:00:00', '2020-01-02 01:00:00');
        $this->lesson($wordset, 401, '2020-01-03 04:00:00', '2020-01-01 01:00:00');
        $this->lesson($wordset, 402, '2019-01-01 00:00:00');
        $this->lesson($wordset + 10000, 401, '2018-01-01 00:00:00');
        add_post_meta($first, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, '403');
        add_post_meta($first, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset);

        $map = ll_tools_wordset_get_vocab_lesson_category_created_timestamps($wordset, [401, 402, 403]);
        $this->assertSame((int) get_post_time('U', true, $first), $map[401]);
        $this->assertCount(2, $map);
        $this->assertArrayNotHasKey(403, $map, 'Only the first category meta value has get_post_meta(..., true) semantics.');
    }

    public function test_complete_stored_order_needs_no_lesson_query_and_missing_rows_append_in_baseline_order(): void
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $categories = [];
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $category = self::factory()->term->create(['taxonomy' => 'word-category', 'name' => $name]);
            ll_tools_set_category_wordset_owner($category, $wordset);
            $categories[] = $category;
        }
        [$a, $b, $c, $d] = $categories;
        $this->lesson($wordset, $a, '2022-01-01 00:00:00');
        $this->lesson($wordset, $b, '2020-01-01 00:00:00');
        update_term_meta($wordset, 'll_wordset_category_manual_order', [$c, $a, $b]);
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string {
            if (str_contains($sql, "p.post_type = 'll_vocab_lesson'")) {
                $queries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $this->assertSame([$c, $a, $b], ll_tools_wordset_get_category_manual_order($wordset, [$a, $b, $c]));
            $this->assertSame([], $queries);
            update_term_meta($wordset, 'll_wordset_category_manual_order', [$c]);
            $this->assertSame([$c, $b, $a, $d], ll_tools_wordset_get_category_manual_order($wordset, $categories, [
                'category_name_map' => [$a => 'a', $b => 'b', $c => 'c', $d => 'd'],
            ]));
            $this->assertCount(1, $queries);
            $this->assertStringContainsString('IN (' . implode(',', [$a, $b, $d]) . ')', $queries[0]);
        } finally {
            remove_filter('query', $capture);
        }
    }

    public function test_many_lessons_return_only_category_aggregates_without_priming_posts_or_metadata(): void
    {
        global $wpdb;
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $ids = [];
        for ($index = 0; $index < 40; $index++) {
            $ids[] = $this->lesson($wordset, 601 + ($index % 2), '2021-01-01 00:00:00');
        }
        foreach ($ids as $id) {
            wp_cache_delete($id, 'posts');
            wp_cache_delete($id, 'post_meta');
        }
        $before = $wpdb->num_queries;
        $map = ll_tools_wordset_get_vocab_lesson_category_created_timestamps($wordset, [601, 602]);
        $this->assertCount(2, $map);
        $this->assertSame(1, $wpdb->num_queries - $before);
        foreach ($ids as $id) {
            $this->assertFalse(wp_cache_get($id, 'posts'));
            $this->assertFalse(wp_cache_get($id, 'post_meta'));
        }
    }

    public function test_failed_read_is_not_cached_and_new_dates_are_visible_in_the_same_request(): void
    {
        global $wpdb;
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $id = $this->lesson($wordset, 701, '2021-01-01 00:00:00');
        $break_query = static function (string $sql): string {
            return str_contains($sql, "p.post_type = 'll_vocab_lesson'") ? 'SELECT * FROM ll_missing_manual_order_fixture' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $break_query);
        try {
            $this->assertSame([], ll_tools_wordset_get_vocab_lesson_category_created_timestamps($wordset, [701]));
        } finally {
            remove_filter('query', $break_query);
            $wpdb->suppress_errors($previous);
        }
        $first = ll_tools_wordset_get_vocab_lesson_category_created_timestamps($wordset, [701]);
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '2022-01-01 00:00:00'], ['ID' => $id]);
        $next = ll_tools_wordset_get_vocab_lesson_category_created_timestamps($wordset, [701]);
        $this->assertGreaterThan($first[701], $next[701]);
    }

    public function test_zero_gmt_dates_are_skipped_and_candidate_queries_are_chunked(): void
    {
        global $wpdb;
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $zero = $this->lesson($wordset, 801, '2018-01-01 00:00:00');
        $wpdb->update($wpdb->posts, ['post_date_gmt' => '0000-00-00 00:00:00'], ['ID' => $zero]);
        $first = $this->lesson($wordset, 801, '2020-01-01 00:00:00', '2020-01-01 01:00:00');
        $this->lesson($wordset, 801, '2020-01-01 00:00:00', '2020-01-01 02:00:00');
        $expected = (int) get_post_time('U', true, $first);
        $before = $wpdb->num_queries;
        $map = ll_tools_wordset_get_vocab_lesson_category_created_timestamps($wordset, range(801, 1001));
        $this->assertSame([801 => $expected], $map);
        $this->assertSame(2, $wpdb->num_queries - $before);
    }
}
