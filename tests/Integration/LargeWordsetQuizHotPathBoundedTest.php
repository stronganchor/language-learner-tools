<?php
declare(strict_types=1);

final class LargeWordsetQuizHotPathBoundedTest extends LL_Tools_TestCase
{
    public function test_quiz_eligibility_uses_only_bounded_word_queries(): void
    {
        $category = $this->createTextCategory('Bounded Eligibility');
        for ($index = 1; $index <= 80; $index++) {
            $this->createWord((int) $category->term_id, 'Bounded Word ' . $index);
        }
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $word_queries = [];
        $capture = static function (WP_Query $query) use (&$word_queries): void {
            $post_type = $query->get('post_type');
            if ($post_type !== 'words' || $query->get('fields') !== 'ids') {
                return;
            }
            $word_queries[] = [
                'posts_per_page' => (int) $query->get('posts_per_page'),
                'post__in' => array_values(array_filter(array_map('intval', (array) $query->get('post__in')))),
            ];
        };

        add_action('pre_get_posts', $capture);
        try {
            $complete = false;
            $can_generate = ll_can_category_generate_quiz($category, 5, [], $complete);
        } finally {
            remove_action('pre_get_posts', $capture);
        }

        $this->assertTrue($complete);
        $this->assertTrue($can_generate);
        $this->assertNotEmpty($word_queries);
        foreach ($word_queries as $query) {
            if ((int) $query['posts_per_page'] === -1) {
                $this->assertNotEmpty($query['post__in'], 'An unlimited query must be constrained to the bounded candidate window.');
                $this->assertLessThanOrEqual(50, count($query['post__in']));
                continue;
            }
            $this->assertGreaterThan(0, (int) $query['posts_per_page']);
            $this->assertLessThanOrEqual(25, (int) $query['posts_per_page']);
        }
    }

    public function test_full_rows_do_not_cache_a_failed_primary_query_and_retry_same_key(): void
    {
        global $wpdb;

        $category = $this->createTextCategory('Full Rows Fail Open');
        $word_id = $this->createWord((int) $category->term_id, 'Recoverable Word');
        ll_tools_rebuild_specific_wrong_answer_owner_map();
        $config = $this->textConfig();
        $cache_key = $this->rowsCacheKey($category, $config);
        wp_cache_delete($cache_key, 'll_tools_words');
        delete_transient($cache_key);

        $injected = false;
        $term_taxonomy_id = (int) $category->term_taxonomy_id;
        $break_query = static function (string $sql) use ($wpdb, $term_taxonomy_id, &$injected): string {
            if (
                !$injected
                && strpos($sql, "{$wpdb->posts}.post_type = 'words'") !== false
                && preg_match('/term_taxonomy_id\s+IN\s*\([^)]*\b' . preg_quote((string) $term_taxonomy_id, '/') . '\b[^)]*\)/i', $sql) === 1
            ) {
                $injected = true;
                return "SELECT ID FROM {$wpdb->posts}_ll_tools_missing_full_rows";
            }
            return $sql;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_query);
        try {
            $failed_complete = true;
            $failed_rows = ll_get_words_by_category($category, 'text', null, $config, $failed_complete);
        } finally {
            remove_filter('query', $break_query);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertTrue($injected);
        $this->assertFalse($failed_complete);
        $this->assertSame([], $failed_rows);
        $this->assertFalse(wp_cache_get($cache_key, 'll_tools_words'));
        $this->assertFalse(get_transient($cache_key));

        $retry_complete = false;
        $retry_rows = ll_get_words_by_category($category, 'text', null, $config, $retry_complete);
        $this->assertTrue($retry_complete);
        $this->assertSame([$word_id], $this->rowIds($retry_rows));
        $this->assertIsArray(get_transient($cache_key));
    }

    public function test_ordinary_full_rows_initialize_the_projection_flag_without_warnings(): void
    {
        $category = $this->createTextCategory('Ordinary Full Rows');
        $word_id = $this->createWord((int) $category->term_id, 'Ordinary Full Row Word');
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $projection_warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$projection_warnings): bool {
            if ($severity === E_WARNING && strpos($message, 'eligibility_projection') !== false) {
                $projection_warnings[] = $message;
                return true;
            }
            return false;
        });
        try {
            $complete = false;
            $rows = ll_get_words_by_category($category, 'text', null, $this->textConfig(), $complete);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($complete);
        $this->assertSame([$word_id], $this->rowIds($rows));
        $this->assertSame([], $projection_warnings);
    }

    public function test_quizzable_category_materialization_retries_after_candidate_query_failure(): void
    {
        global $wpdb;

        $category = $this->createTextCategory('Quizzable Retry');
        for ($index = 1; $index <= 5; $index++) {
            $this->createWord((int) $category->term_id, 'Quizzable Retry Word ' . $index);
        }
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $injected = false;
        $break_query = static function (string $sql) use ($wpdb, &$injected): string {
            if (!$injected && strpos($sql, 'SELECT wc_tt.term_id') !== false && strpos($sql, 'HAVING COUNT(DISTINCT CASE') !== false) {
                $injected = true;
                return "SELECT term_id FROM {$wpdb->term_taxonomy}_ll_tools_missing_candidates";
            }
            return $sql;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_query);
        try {
            $failed_complete = true;
            $failed_ids = ll_tools_get_quizzable_category_ids([], 5, $failed_complete);
        } finally {
            remove_filter('query', $break_query);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertTrue($injected);
        $this->assertFalse($failed_complete);
        $this->assertSame([], $failed_ids);

        $retry_complete = false;
        $retry_ids = ll_tools_get_quizzable_category_ids([], 5, $retry_complete);
        $this->assertTrue($retry_complete);
        $this->assertContains((int) $category->term_id, $retry_ids);
    }

    public function test_word_recording_types_report_term_source_failure_and_retry(): void
    {
        global $wpdb;

        $category = $this->createTextCategory('Recorder Types');
        update_term_meta((int) $category->term_id, 'll_desired_recording_types', ['isolation']);
        $word_id = $this->createWord((int) $category->term_id, 'Recorder Type Word');
        clean_object_term_cache($word_id, 'words');
        wp_cache_delete($word_id, 'word-category_relationships');

        $injected = false;
        $break_query = static function (string $sql) use ($wpdb, $word_id, &$injected): string {
            if (
                !$injected
                && strpos($sql, $wpdb->term_relationships) !== false
                && preg_match('/object_id\s+IN\s*\([^)]*\b' . preg_quote((string) $word_id, '/') . '\b[^)]*\)/i', $sql) === 1
            ) {
                $injected = true;
                return "SELECT term_id FROM {$wpdb->term_relationships}_ll_tools_missing_recording_types";
            }
            return $sql;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_query);
        try {
            $failed_complete = true;
            ll_tools_get_desired_recording_types_for_word($word_id, $failed_complete);
        } finally {
            remove_filter('query', $break_query);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertTrue($injected);
        $this->assertFalse($failed_complete);

        clean_object_term_cache($word_id, 'words');
        wp_cache_delete($word_id, 'word-category_relationships');
        $retry_complete = false;
        $retry_types = ll_tools_get_desired_recording_types_for_word($word_id, $retry_complete);
        $this->assertTrue($retry_complete);
        $this->assertSame(['isolation'], $retry_types);
    }

    public function test_display_name_does_not_cache_a_failed_translation_read(): void
    {
        global $wpdb;

        $category = $this->createTextCategory('Display Retry');
        update_term_meta((int) $category->term_id, 'term_translation', 'Recovered Translation');
        wp_cache_delete((int) $category->term_id, 'term_meta');
        $args = [
            'enable_translation' => true,
            'target_language' => 'en',
            'site_language' => 'en_us',
            'meta_key' => 'term_translation',
            'wordset_ids' => [],
        ];
        $cache_key = $this->displayNameCacheKey($category, $args);
        wp_cache_delete($cache_key, 'll_tools_quiz_category');

        $injected = false;
        $break_query = static function (string $sql) use ($wpdb, $category, &$injected): string {
            if (
                !$injected
                && strpos($sql, $wpdb->termmeta) !== false
                && preg_match('/term_id\s+IN\s*\([^)]*\b' . preg_quote((string) $category->term_id, '/') . '\b[^)]*\)/i', $sql) === 1
            ) {
                $injected = true;
                return "SELECT meta_value FROM {$wpdb->termmeta}_ll_tools_missing_translation";
            }
            return $sql;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_query);
        try {
            $failed_complete = true;
            ll_tools_get_category_display_name($category, $args, $failed_complete);
        } finally {
            remove_filter('query', $break_query);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertTrue($injected);
        $this->assertFalse($failed_complete);
        $this->assertFalse(wp_cache_get($cache_key, 'll_tools_quiz_category'));

        wp_cache_delete((int) $category->term_id, 'term_meta');
        $retry_complete = false;
        $retry_name = ll_tools_get_category_display_name($category, $args, $retry_complete);
        $this->assertTrue($retry_complete);
        $this->assertSame('Recovered Translation', $retry_name);
    }

    public function test_bounded_eligibility_initializes_an_empty_owner_map_on_a_fresh_site(): void
    {
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION);

        $category = $this->createTextCategory('Fresh Owner Map');
        for ($index = 1; $index <= 5; $index++) {
            $this->createWord((int) $category->term_id, 'Fresh Owner Map Word ' . $index);
        }

        $complete = false;
        $this->assertTrue(ll_can_category_generate_quiz($category, 5, [], $complete));
        $this->assertTrue($complete);
        $this->assertTrue(ll_tools_specific_wrong_answer_owner_map_is_complete(
            get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null),
            true
        ));
    }

    private function createTextCategory(string $label): WP_Term
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $created = wp_insert_term($label . ' ' . $suffix, 'word-category', ['slug' => sanitize_title($label . '-' . $suffix)]);
        $this->assertIsArray($created);
        $term_id = (int) $created['term_id'];
        update_term_meta($term_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($term_id, 'll_quiz_option_type', 'text_title');
        $term = get_term($term_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);
        return $term;
    }

    private function createWord(int $categoryId, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$categoryId], 'word-category', false);
        return (int) $word_id;
    }

    /** @return array<string,mixed> */
    private function textConfig(): array
    {
        return [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
            '__skip_quiz_config_merge' => true,
        ];
    }

    /** @param array<int,array<string,mixed>> $rows @return int[] */
    private function rowIds(array $rows): array
    {
        $ids = array_values(array_filter(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $rows)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $config */
    private function rowsCacheKey(WP_Term $category, array $config): string
    {
        $prompt_type = (string) $config['prompt_type'];
        $option_type = (string) $config['option_type'];
        return ll_tools_get_words_cache_key((int) $category->term_id, [], $prompt_type, $option_type, [
            'require_audio' => ll_tools_quiz_requires_audio($config, $option_type),
            'require_prompt_image' => ll_tools_quiz_prompt_type_has_image($prompt_type),
            'require_option_image' => ll_tools_quiz_option_type_has_image($option_type),
            'use_titles' => false,
            'term_slug' => (string) $category->slug,
            'text_label_schema' => 8,
            'prompt_card_schema' => 4,
            'wordset_sign_language_mode' => false,
            'image_animation_meta' => true,
            'masked_image_url' => function_exists('ll_tools_should_use_masked_image_proxy') ? ll_tools_should_use_masked_image_proxy() : true,
            'include_pos' => true,
            'include_gender' => true,
            'include_plurality' => true,
            'source_complete_schema' => 2,
        ]);
    }

    /** @param array<string,mixed> $args */
    private function displayNameCacheKey(WP_Term $category, array $args): string
    {
        return 'll_wc_display_name_' . md5(wp_json_encode([
            'term_id' => (int) $category->term_id,
            'version' => max(1, (int) ll_tools_get_category_cache_version((int) $category->term_id)),
            'category_epoch' => max(1, (int) ll_tools_get_category_cache_epoch()),
            'enable_translation' => 1,
            'target_language' => (string) $args['target_language'],
            'site_language' => (string) $args['site_language'],
            'meta_key' => (string) $args['meta_key'],
            'wordset_ids' => [],
            'schema' => 5,
        ]));
    }
}
