<?php
declare(strict_types=1);

final class WordsetAnswerOptionPreviewCacheTest extends LL_Tools_TestCase
{
    public function test_cache_key_tracks_only_the_relevant_content_scope_and_viewer(): void
    {
        [$wordset_a, $category_a] = $this->createPreviewFixture('Preview cache A', false);
        [$wordset_b, $category_b] = $this->createPreviewFixture('Preview cache B', false);

        $admin_a = self::factory()->user->create(['role' => 'administrator']);
        $admin_b = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_a);

        $key_a_before = ll_tools_wordset_answer_option_preview_cache_key($wordset_a);
        $key_b_before = ll_tools_wordset_answer_option_preview_cache_key($wordset_b);

        ll_tools_bump_category_cache_version([$category_b], [$wordset_b], true);

        $this->assertSame($key_a_before, ll_tools_wordset_answer_option_preview_cache_key($wordset_a));
        $this->assertNotSame($key_b_before, ll_tools_wordset_answer_option_preview_cache_key($wordset_b));

        wp_set_current_user($admin_b);
        $this->assertNotSame($key_a_before, ll_tools_wordset_answer_option_preview_cache_key($wordset_a));

        wp_set_current_user($admin_a);
        ll_tools_bump_category_cache_version([$category_a], [$wordset_a], true);
        $this->assertNotSame($key_a_before, ll_tools_wordset_answer_option_preview_cache_key($wordset_a));
    }

    public function test_incomplete_category_discovery_is_not_cached_and_same_request_retry_can_recover(): void
    {
        global $wpdb;

        [$wordset_id] = $this->createPreviewFixture('Preview discovery failure', true);
        $preview_count_before = $this->previewTransientCount($wpdb);
        $previous_suppress = $wpdb->suppress_errors(true);
        $break_category_discovery = static function (string $sql) use ($wpdb): string {
            if (stripos($sql, 'HAVING word_count') !== false && stripos($sql, 'tt_cat.term_id') !== false) {
                return "SELECT missing_column FROM {$wpdb->prefix}ll_tools_missing_preview_source";
            }
            return $sql;
        };

        add_filter('query', $break_category_discovery);
        try {
            $this->assertSame([], ll_tools_wordset_get_answer_option_preview_samples($wordset_id));
        } finally {
            remove_filter('query', $break_category_discovery);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertSame($preview_count_before, $this->previewTransientCount($wpdb));
        $this->assertNotEmpty(
            ll_tools_wordset_get_answer_option_preview_samples($wordset_id),
            'A failed source query must not leave a request-local empty preview behind.'
        );
        $this->assertSame($preview_count_before + 1, $this->previewTransientCount($wpdb));
    }

    public function test_incomplete_bounded_word_query_does_not_persist_a_partial_preview(): void
    {
        global $wpdb;

        [$wordset_id, $category_id] = $this->createPreviewFixture('Preview word failure', true);
        $category_resolution_complete = false;
        $this->assertNotEmpty(ll_collect_wc_ids_for_wordset_term_ids([$wordset_id], $category_resolution_complete));
        $this->assertTrue($category_resolution_complete);

        $category = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
        $term_taxonomy_id = (int) $category->term_taxonomy_id;
        $this->assertGreaterThan(0, $term_taxonomy_id);

        $preview_count_before = $this->previewTransientCount($wpdb);
        $previous_suppress = $wpdb->suppress_errors(true);
        $injected = false;
        $break_bounded_word_query = static function (string $sql) use ($wpdb, $term_taxonomy_id, &$injected): string {
            $is_bounded_word_query = !$injected
                && strpos($sql, "{$wpdb->posts}.post_type = 'words'") !== false
                && strpos($sql, "{$wpdb->posts}.ID") !== false
                && stripos($sql, (string) $wpdb->term_relationships) !== false
                && preg_match(
                    '/term_taxonomy_id\s+IN\s*\([^)]*\b' . preg_quote((string) $term_taxonomy_id, '/') . '\b[^)]*\)/i',
                    $sql
                ) === 1;
            if ($is_bounded_word_query) {
                $injected = true;
                return "SELECT missing_column FROM {$wpdb->prefix}ll_tools_missing_preview_words";
            }
            return $sql;
        };

        add_filter('query', $break_bounded_word_query);
        try {
            $this->assertSame([], ll_tools_wordset_get_answer_option_preview_samples($wordset_id));
        } finally {
            remove_filter('query', $break_bounded_word_query);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertTrue($injected, 'The regression must inject a failure into the bounded word query.');
        $this->assertSame($preview_count_before, $this->previewTransientCount($wpdb));
        $this->assertNotEmpty(ll_tools_wordset_get_answer_option_preview_samples($wordset_id));
        $this->assertSame($preview_count_before + 1, $this->previewTransientCount($wpdb));
    }

    /** @return array{0:int,1:int} */
    private function createPreviewFixture(string $label, bool $with_words): array
    {
        $wordset = wp_insert_term($label . ' wordset ' . wp_generate_uuid4(), 'wordset');
        $category = wp_insert_term($label . ' category ' . wp_generate_uuid4(), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);
        $this->assertGreaterThan(0, $category_id);

        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        if ($with_words) {
            for ($index = 1; $index <= 5; $index++) {
                $word_id = self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => $label . ' word ' . $index,
                ]);
                $this->assertGreaterThan(0, $word_id);
                $this->assertFalse(is_wp_error(wp_set_object_terms($word_id, [$wordset_id], 'wordset', false)));
                $this->assertFalse(is_wp_error(wp_set_object_terms($word_id, [$category_id], 'word-category', false)));
            }
        }

        return [$wordset_id, $category_id];
    }

    private function previewTransientCount(wpdb $wpdb): int
    {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->options}
             WHERE option_name LIKE %s",
            $wpdb->esc_like('_transient_ll_ws_ans_prev_samples_v2_s3_') . '%'
        ));
    }
}
