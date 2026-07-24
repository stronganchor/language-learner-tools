<?php
declare(strict_types=1);

final class WordsetPageCategorySearchIndexTest extends LL_Tools_TestCase
{
    public function test_category_search_index_uses_flat_exact_allowed_categories_without_promoting_children(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Search Index Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $wordset_exists = term_exists($wordset_id, 'wordset');
        $this->assertIsArray($wordset_exists);
        $this->assertSame($wordset_id, (int) $wordset_exists['term_id']);

        $allowed = wp_insert_term('Search Index Allowed ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($allowed));
        $this->assertIsArray($allowed);
        $allowed_id = (int) $allowed['term_id'];

        $child = wp_insert_term('Search Index Child ' . wp_generate_password(4, false), 'word-category', [
            'parent' => $allowed_id,
        ]);
        $this->assertFalse(is_wp_error($child));
        $this->assertIsArray($child);
        $child_id = (int) $child['term_id'];
        $owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id';
        update_term_meta($allowed_id, $owner_meta_key, (string) $wordset_id);
        update_term_meta($child_id, $owner_meta_key, (string) $wordset_id);

        $only_allowed = $this->createSearchWord('Allowed Search Token', 'Allowed Translation Token', $wordset_id, [$allowed_id]);
        $allowed_with_stale_child = $this->createSearchWord('Allowed With Stale Child Token', 'Allowed Stale Child Translation Token', $wordset_id, [$allowed_id, $child_id]);
        $child_only = $this->createSearchWord('Child Only Search Token', 'Child Only Translation Token', $wordset_id, [$child_id]);

        $this->assertWordHasTerm($only_allowed, 'wordset', $wordset_id);
        $this->assertWordHasTerm($only_allowed, 'word-category', $allowed_id);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            if (
                strpos($query, 'll_wordset_category_search') === false
                && strpos($query, 'wordset_taxonomy') === false
            ) {
                return $query;
            }
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$allowed_id]);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertArrayHasKey($allowed_id, $index);
        $search_text = (string) ($index[$allowed_id]['search_text'] ?? '');
        $this->assertStringContainsString('Allowed Search Token', $search_text);
        $this->assertStringContainsString('Allowed Translation Token', $search_text);
        $this->assertStringContainsString('Allowed With Stale Child Token', $search_text);
        $this->assertStringContainsString('Allowed Stale Child Translation Token', $search_text);
        $this->assertStringNotContainsString('Child Only Search Token', $search_text);
        $this->assertStringNotContainsString('Child Only Translation Token', $search_text);

        $queries_sql = implode("\n", $captured_queries);
        $this->assertStringContainsString(ll_tools_wordset_category_search_table_name(), $queries_sql);
        $this->assertStringContainsString('posts.ID >', $queries_sql);
        $this->assertStringNotContainsString('allowed_category_relationships', $queries_sql);
        $this->assertStringNotContainsString(' OFFSET ', strtoupper($queries_sql));

        $this->assertGreaterThan(0, $only_allowed);
        $this->assertGreaterThan(0, $allowed_with_stale_child);
        $this->assertGreaterThan(0, $child_only);
        $this->assertNotEmpty($wpdb->last_query);
    }

    public function test_category_search_index_returns_empty_fallback_when_rebuild_lock_is_held(): void
    {
        global $wpdb;

        $wordset_id = 987654;
        $allowed_category_id = 987655;
        $state_option = ll_tools_wordset_category_search_state_option($wordset_id);
        $lock_option = ll_tools_wordset_category_search_lock_option($wordset_id);

        delete_option($state_option);
        delete_option($lock_option);
        $lock = ll_tools_acquire_wordset_category_search_lock($wordset_id, 30);
        $this->assertTrue((bool) ($lock['acquired'] ?? false));

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        $complete = true;
        try {
            $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$allowed_category_id], $complete);
        } finally {
            remove_filter('query', $capture);
            ll_tools_release_wordset_category_search_lock($lock);
            delete_option($state_option);
            delete_option($lock_option);
        }

        $this->assertSame([], $index);
        $this->assertFalse($complete);
        $queries_sql = implode("\n", $queries);
        $this->assertStringNotContainsString('wordset_taxonomy', $queries_sql);
        $this->assertStringNotContainsString('title_value', $queries_sql);
        $this->assertNotEmpty($wpdb->last_query);
    }

    public function test_category_search_materializer_advances_in_bounded_keyset_batches(): void
    {
        $wordset = wp_insert_term('Bounded Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Bounded Search Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);

        for ($index = 1; $index <= 12; $index++) {
            $this->createSearchWord(
                'Bounded Search Word ' . $index,
                'Bounded Translation ' . $index,
                $wordset_id,
                [$category_id]
            );
        }

        delete_option(ll_tools_wordset_category_search_state_option($wordset_id));
        $batch_size = static function (): int {
            return 10;
        };
        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (strpos($query, 'wordset_taxonomy') !== false) {
                $queries[] = $query;
            }
            return $query;
        };

        add_filter('ll_tools_wordset_category_search_rebuild_batch_size', $batch_size);
        add_filter('query', $capture);
        try {
            $complete = true;
            $first = ll_tools_get_wordset_page_category_search_index(
                $wordset_id,
                [$category_id],
                $complete
            );
            $this->assertSame([], $first);
            $this->assertFalse($complete);

            $state = ll_tools_get_wordset_category_search_state($wordset_id);
            $this->assertSame('running', $state['status']);
            $this->assertSame(10, $state['processed']);
            $this->assertGreaterThan(0, $state['last_id']);

            ll_tools_wordset_category_search_process_rebuild_batch($wordset_id);
            $complete = false;
            $index = ll_tools_get_wordset_page_category_search_index(
                $wordset_id,
                [$category_id],
                $complete
            );
        } finally {
            remove_filter('query', $capture);
            remove_filter('ll_tools_wordset_category_search_rebuild_batch_size', $batch_size);
        }

        $this->assertTrue($complete);
        $this->assertArrayHasKey($category_id, $index);
        $this->assertCount(12, (array) ($index[$category_id]['words'] ?? []));
        $queries_sql = implode("\n", $queries);
        $this->assertStringContainsString('posts.ID >', $queries_sql);
        $this->assertStringContainsString('LIMIT 11', $queries_sql);
        $this->assertStringNotContainsString(' OFFSET ', strtoupper($queries_sql));
    }

    public function test_category_search_schema_version_is_not_published_when_verification_fails(): void
    {
        global $wpdb;

        $force_missing = static function (): bool {
            return false;
        };

        delete_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION);
        delete_transient('ll_tools_wordset_category_search_schema_retry');
        add_filter('ll_tools_wordset_category_search_schema_exists_after_install', $force_missing);
        try {
            $this->assertFalse(ll_tools_install_wordset_category_search_schema());
            $this->assertSame('', (string) get_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION, ''));
            $this->assertSame('0', (string) get_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION, ''));
            $this->assertNotFalse(get_transient('ll_tools_wordset_category_search_schema_retry'));
        } finally {
            remove_filter('ll_tools_wordset_category_search_schema_exists_after_install', $force_missing);
            delete_transient('ll_tools_wordset_category_search_schema_retry');
            $this->assertTrue(ll_tools_install_wordset_category_search_schema());
        }

        $table = ll_tools_wordset_category_search_table_name();
        $this->assertSame(
            'generation',
            (string) $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'generation'")
        );
        $primary_rows = $wpdb->get_results(
            "SHOW INDEX FROM {$table} WHERE Key_name = 'PRIMARY'",
            ARRAY_A
        );
        $primary_columns = [];
        foreach ((array) $primary_rows as $primary_row) {
            $primary_columns[(int) $primary_row['Seq_in_index']] = (string) $primary_row['Column_name'];
        }
        ksort($primary_columns);
        $this->assertSame(
            ['wordset_id', 'generation', 'category_id', 'word_id'],
            array_values($primary_columns)
        );
    }

    public function test_category_search_rebuild_lock_rejects_a_stale_owner(): void
    {
        $wordset_id = 987660;
        $lock = ll_tools_acquire_wordset_category_search_lock($wordset_id, 30);
        $this->assertTrue((bool) ($lock['acquired'] ?? false));
        $option_name = ll_tools_wordset_category_search_lock_option($wordset_id);
        $replacement = (time() + 60) . '|replacement-owner';
        update_option($option_name, $replacement, false);

        $this->assertFalse(ll_tools_renew_wordset_category_search_lock($lock, 30));
        ll_tools_release_wordset_category_search_lock($lock);
        $this->assertSame($replacement, (string) get_option($option_name, ''));

        delete_option($option_name);
    }

    public function test_expired_lease_rotates_generation_and_stale_rows_cannot_clobber_published_rows(): void
    {
        global $wpdb;

        $wordset_id = 987665;
        $category_id = 987666;
        $word_id = 987667;
        $table = ll_tools_wordset_category_search_table_name();
        $state_option = ll_tools_wordset_category_search_state_option($wordset_id);
        $lock_option = ll_tools_wordset_category_search_lock_option($wordset_id);
        $signature = ll_tools_wordset_category_search_dependency_signature($wordset_id);
        $stale_generation = ll_tools_wordset_category_search_generate_generation($wordset_id);

        $wpdb->delete($table, ['wordset_id' => $wordset_id], ['%d']);
        delete_option($state_option);
        delete_option($lock_option);
        ll_tools_update_wordset_category_search_state($wordset_id, [
            'status' => 'running',
            'signature' => $signature,
            'generation' => $stale_generation,
            'published_generation' => '',
            'last_id' => 123,
            'processed' => 1,
            'started_at' => current_time('mysql', true),
        ]);

        $stale_lock = ll_tools_acquire_wordset_category_search_lock($wordset_id, 30);
        $this->assertTrue((bool) ($stale_lock['acquired'] ?? false));
        $separator = strpos((string) $stale_lock['value'], '|');
        $this->assertNotFalse($separator);
        update_option(
            $lock_option,
            (time() - 1) . substr((string) $stale_lock['value'], (int) $separator),
            false
        );

        $replacement_lock = ll_tools_acquire_wordset_category_search_lock($wordset_id, 30);
        $this->assertTrue((bool) ($replacement_lock['acquired'] ?? false));
        $this->assertTrue((bool) ($replacement_lock['replaced'] ?? false));

        try {
            $replacement_state = ll_tools_wordset_category_search_begin_generation(
                $wordset_id,
                $signature,
                $replacement_lock
            );
            $replacement_generation = (string) $replacement_state['generation'];
            $this->assertNotSame('', $replacement_generation);
            $this->assertNotSame($stale_generation, $replacement_generation);

            $base_row = [
                'wordset_id' => $wordset_id,
                'category_id' => $category_id,
                'word_id' => $word_id,
                'translation_value' => 'Replacement translation',
                'translation_normalized' => 'replacement translation',
                'translation_tokens' => ' replacement translation ',
                'updated_at' => current_time('mysql', true),
            ];
            $replacement_row = array_merge($base_row, [
                'generation' => $replacement_generation,
                'title_value' => 'Replacement generation',
                'title_normalized' => 'replacement generation',
                'title_tokens' => ' replacement generation ',
            ]);
            $this->assertTrue(ll_tools_wordset_category_search_insert_row_chunk([
                $replacement_row,
            ]));

            $replacement_state['status'] = 'completed';
            $replacement_state['published_generation'] = $replacement_generation;
            $replacement_state['completed_at'] = current_time('mysql', true);
            $did_publish = false;
            $replacement_state = ll_tools_wordset_category_search_update_owned_state(
                $wordset_id,
                $replacement_state,
                $replacement_generation,
                $replacement_lock,
                $did_publish
            );
            $this->assertTrue($did_publish);

            // This simulates an insert that was already in flight when the old
            // 90-second lease expired. Its generation remains isolated.
            $stale_row = array_merge($base_row, [
                'generation' => $stale_generation,
                'title_value' => 'Stale generation',
                'title_normalized' => 'stale generation',
                'title_tokens' => ' stale generation ',
            ]);
            $this->assertTrue(ll_tools_wordset_category_search_insert_row_chunk([
                $stale_row,
            ]));

            $stale_publish = $replacement_state;
            $stale_publish['generation'] = $stale_generation;
            $stale_publish['published_generation'] = $stale_generation;
            $stale_updated = true;
            ll_tools_update_wordset_category_search_state(
                $wordset_id,
                $stale_publish,
                $stale_generation,
                $stale_updated
            );
            $this->assertFalse($stale_updated);
        } finally {
            ll_tools_release_wordset_category_search_lock($replacement_lock);
            ll_tools_release_wordset_category_search_lock($stale_lock);
        }

        $complete = false;
        $rows = ll_tools_wordset_category_search_get_index_rows(
            $wordset_id,
            [$category_id],
            $complete
        );
        $this->assertTrue($complete);
        $this->assertCount(1, $rows);
        $this->assertSame('Replacement generation', (string) $rows[0]['title_value']);
        $this->assertSame(
            2,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1)
                 FROM {$table}
                 WHERE wordset_id = %d
                   AND category_id = %d
                   AND word_id = %d",
                $wordset_id,
                $category_id,
                $word_id
            ))
        );

        $wpdb->delete($table, ['wordset_id' => $wordset_id], ['%d']);
        delete_option($state_option);
        delete_option($lock_option);
    }

    public function test_category_search_insert_statements_obey_row_and_byte_chunk_limits(): void
    {
        global $wpdb;

        $wordset_id = 987670;
        $large_value = str_repeat('Ã¼', 1000);
        $rows = [];
        for ($index = 1; $index <= 12; $index++) {
            $rows[] = [
                'wordset_id' => $wordset_id,
                'category_id' => 990000 + $index,
                'word_id' => 995000 + $index,
                'title_value' => $large_value,
                'translation_value' => $large_value,
                'title_normalized' => $large_value,
                'translation_normalized' => $large_value,
                'title_tokens' => ' ' . $large_value . ' ',
                'translation_tokens' => ' ' . $large_value . ' ',
                'updated_at' => current_time('mysql', true),
            ];
        }

        $row_limit = static function (): int {
            return 5;
        };
        $byte_limit = static function (): int {
            return 64 * 1024;
        };
        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (strpos($query, 'INSERT INTO ' . ll_tools_wordset_category_search_table_name()) !== false) {
                $queries[] = $query;
            }
            return $query;
        };

        add_filter('ll_tools_wordset_category_search_insert_chunk_rows', $row_limit);
        add_filter('ll_tools_wordset_category_search_insert_chunk_bytes', $byte_limit);
        add_filter('query', $capture);
        try {
            $this->assertTrue(ll_tools_wordset_category_search_insert_rows($rows));
        } finally {
            remove_filter('query', $capture);
            remove_filter('ll_tools_wordset_category_search_insert_chunk_bytes', $byte_limit);
            remove_filter('ll_tools_wordset_category_search_insert_chunk_rows', $row_limit);
            $wpdb->delete(
                ll_tools_wordset_category_search_table_name(),
                ['wordset_id' => $wordset_id],
                ['%d']
            );
        }

        $this->assertGreaterThan(1, count($queries));
        foreach ($queries as $query) {
            $this->assertLessThan(64 * 1024, strlen($query));
        }
    }

    public function test_category_search_normalization_is_stable_across_request_locales(): void
    {
        $original_locale = determine_locale();
        switch_to_locale('de_DE');
        $german_request = ll_tools_wordset_page_normalize_category_search_match_text('Ã„pfel fÃ¼r ÄŸÄ±da');
        switch_to_locale('tr_TR');
        $turkish_request = ll_tools_wordset_page_normalize_category_search_match_text('Ã„pfel fÃ¼r ÄŸÄ±da');
        restore_previous_locale();
        restore_previous_locale();

        $this->assertSame($german_request, $turkish_request);
        $this->assertSame(
            $german_request,
            ll_tools_wordset_page_normalize_category_search_match_text('Ã„pfel fÃ¼r ÄŸÄ±da')
        );
        $this->assertNotSame('', $original_locale);
    }

    public function test_deterministic_category_fanout_failure_does_not_hot_loop_cron(): void
    {
        $wordset = wp_insert_term('Fanout Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) $wordset['term_id'];
        $category_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $category = wp_insert_term(
                'Fanout Search Category ' . $index . ' ' . wp_generate_password(4, false),
                'word-category'
            );
            $this->assertFalse(is_wp_error($category));
            $category_id = (int) $category['term_id'];
            $this->setCategoryOwner($category_id, $wordset_id);
            $category_ids[] = $category_id;
        }
        $this->createSearchWord('Fanout Search Word', 'Fanout Translation', $wordset_id, $category_ids);

        $limit = static function (): int {
            return 4;
        };
        delete_option(ll_tools_wordset_category_search_state_option($wordset_id));
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$wordset_id]);
        add_filter('ll_tools_wordset_category_search_categories_per_word_limit', $limit);
        try {
            $complete = true;
            $this->assertSame([], ll_tools_get_wordset_page_category_search_index(
                $wordset_id,
                [$category_ids[0]],
                $complete
            ));
            $this->assertFalse($complete);
            $state = ll_tools_get_wordset_category_search_state($wordset_id);
            $this->assertSame('pending', $state['status']);
            $this->assertSame('category_relationship_limit', $state['last_error']);
            $this->assertSame(1, $state['terminal']);
            $this->assertFalse(wp_next_scheduled(
                LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK,
                [$wordset_id]
            ));

            ll_tools_wordset_category_search_run_scheduled_rebuild($wordset_id);
            $this->assertFalse(wp_next_scheduled(
                LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK,
                [$wordset_id]
            ));
        } finally {
            remove_filter('ll_tools_wordset_category_search_categories_per_word_limit', $limit);
            wp_clear_scheduled_hook(LL_TOOLS_WORDSET_CATEGORY_SEARCH_REBUILD_HOOK, [$wordset_id]);
        }
    }

    public function test_wordset_page_initial_config_defers_category_search_text_to_ajax(): void
    {
        $wordset = wp_insert_term('Deferred Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Search Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);

        for ($index = 1; $index <= 5; $index++) {
            $this->createSearchWordWithAudio(
                'Deferred Search Apple ' . $index,
                'Deferred Search Elma ' . $index,
                $wordset_id,
                [$category_id],
                'deferred-search-' . $index . '.mp3'
            );
        }
        $this->createVocabLesson($wordset_id, $category_id);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (strpos($query, 'allowed_category_relationships') !== false) {
                $queries[] = $query;
            }
            return $query;
        };

        add_filter('query', $capture);
        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            remove_filter('query', $capture);
        }

        $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
        $this->assertNotSame('', $localized);
        $config = $this->extractLocalizedConfig($localized);
        $this->assertIsArray($config['categorySearch'] ?? null);
        $this->assertTrue((bool) ($config['categorySearch']['enabled'] ?? false));
        $this->assertNotSame('', (string) ($config['categorySearch']['token'] ?? ''));
        $this->assertIsArray($config['categories'] ?? null);

        foreach ((array) ($config['categories'] ?? []) as $category_config) {
            $this->assertIsArray($category_config);
            $this->assertArrayNotHasKey('search_text', $category_config);
        }

        $this->assertSame([], $queries);
    }

    public function test_category_search_ajax_returns_diacritic_insensitive_word_matches(): void
    {
        $wordset = wp_insert_term('Ajax Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Ajax Search Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);

        $word_id = $this->createSearchWord('Ajax Search Travel', 'cirûn otel', $wordset_id, [$category_id]);
        $this->assertWordHasTerm($word_id, 'wordset', $wordset_id);
        $this->assertWordHasTerm($word_id, 'word-category', $category_id);
        if (function_exists('ll_tools_bump_category_cache_epoch')) {
            ll_tools_bump_category_cache_epoch([$wordset_id]);
        }
        if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
            ll_tools_bump_wordset_cache_epoch([$wordset_id]);
        }

        $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$category_id]);
        $this->assertArrayHasKey($category_id, $index);
        $this->assertStringContainsString('otel', (string) ($index[$category_id]['search_text'] ?? ''));
        $this->assertIsArray($index[$category_id]['words'] ?? null);
        $this->assertNotEmpty($index[$category_id]['words']);

        $token = ll_tools_wordset_page_store_category_search_payload([
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_id],
            'user_id' => 0,
            'access_signature' => ll_tools_wordset_page_payload_access_signature($wordset_id, 0),
        ]);
        $this->assertNotSame('', $token);

        $response = $this->postCategorySearchAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
            'token' => $token,
            'wordset_id' => $wordset_id,
            'query' => 'cirun',
        ]);

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data'] ?? null);
        $data = (array) $response['data'];
        $this->assertSame('cirun', (string) ($data['query'] ?? ''));
        $this->assertSame([$category_id], array_values(array_map('intval', (array) ($data['categoryIds'] ?? []))));
        $this->assertIsArray($data['wordMatches'] ?? null);
        $this->assertArrayHasKey((string) $category_id, $data['wordMatches']);
        $matches = (array) $data['wordMatches'][(string) $category_id];
        $this->assertNotEmpty($matches);
        $first_match = (array) $matches[0];
        $this->assertSame($word_id, (int) ($first_match['id'] ?? 0));
        $this->assertSame('translation', (string) ($first_match['match_field'] ?? ''));
        $this->assertGreaterThan(0, (int) ($first_match['match_rank'] ?? 0));
    }

    public function test_anonymous_category_search_cache_miss_reservation_is_atomic(): void
    {
        global $wpdb;

        wp_set_current_user(0);
        $original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.31';
        $token = 'search_' . str_repeat('a', 32);
        $now = 1700000100;
        $window = 5 * MINUTE_IN_SECONDS;
        $token_option = ll_tools_wordset_page_category_search_cache_miss_reservation_option('token', $token, $window, $now);
        $ip_option = ll_tools_wordset_page_category_search_cache_miss_reservation_option('ip', '203.0.113.31', $window, $now);
        $token_timeout_option = ll_tools_wordset_page_category_search_cache_miss_reservation_timeout_option($token_option);
        $ip_timeout_option = ll_tools_wordset_page_category_search_cache_miss_reservation_timeout_option($ip_option);
        delete_option($token_option);
        delete_option($ip_option);
        delete_option($token_timeout_option);
        delete_option($ip_timeout_option);

        $fixed_now = static function () use ($now): int {
            return $now;
        };
        $token_limit = static function (): int {
            return 1;
        };
        $ip_limit = static function (): int {
            return 10;
        };
        $reservation_updates = [];
        $capture = static function (string $query) use (&$reservation_updates): string {
            if (strpos($query, 'CAST(option_value AS UNSIGNED) < 1') !== false) {
                $reservation_updates[] = $query;
            }
            return $query;
        };

        add_filter('ll_tools_wordset_page_category_search_cache_miss_now', $fixed_now);
        add_filter('ll_tools_wordset_page_category_search_cache_miss_token_limit', $token_limit);
        add_filter('ll_tools_wordset_page_category_search_cache_miss_ip_limit', $ip_limit);
        add_filter('query', $capture);
        try {
            $this->assertTrue(ll_tools_wordset_page_reserve_category_search_cache_miss($token));
            $this->assertFalse(ll_tools_wordset_page_reserve_category_search_cache_miss($token));
            $this->assertTrue(ll_tools_wordset_page_category_search_cache_miss_limited($token));
            $this->assertSame(1, (int) get_option($token_option, 0));
            $this->assertSame(1, (int) get_option($ip_option, 0));
            $this->assertSame($now + $window, (int) get_option($token_timeout_option, 0));
            $this->assertSame($now + $window, (int) get_option($ip_timeout_option, 0));
            $this->assertNotEmpty($reservation_updates, 'The second request must use one conditional SQL increment instead of a transient get/set pair.');
            $this->assertStringContainsString($wpdb->options, $reservation_updates[0]);
        } finally {
            remove_filter('query', $capture);
            remove_filter('ll_tools_wordset_page_category_search_cache_miss_ip_limit', $ip_limit);
            remove_filter('ll_tools_wordset_page_category_search_cache_miss_token_limit', $token_limit);
            remove_filter('ll_tools_wordset_page_category_search_cache_miss_now', $fixed_now);
            delete_option($token_option);
            delete_option($ip_option);
            delete_option($token_timeout_option);
            delete_option($ip_timeout_option);
            if ($original_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $original_remote_addr;
            }
        }
    }

    public function test_anonymous_category_search_build_lock_prevents_duplicate_scan_without_consuming_miss_budget(): void
    {
        wp_set_current_user(0);
        $original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.32';

        $wordset = wp_insert_term('Locked Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = wp_insert_term('Locked Search Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);
        $this->createSearchWord('Needle', 'Target', $wordset_id, [$category_id]);

        $payload = [
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_id],
            'user_id' => 0,
            'access_signature' => ll_tools_wordset_page_payload_access_signature($wordset_id, 0),
        ];
        $token = ll_tools_wordset_page_build_category_search_token($payload);
        $this->assertTrue(ll_tools_wordset_page_is_shared_category_search_token($token));
        $this->assertSame($token, ll_tools_wordset_page_store_category_search_payload($payload, 0, $token));
        $cache_args = [
            'token' => $token,
            'wordset_id' => $wordset_id,
            'query' => 'n',
        ];
        $cache_key = ll_tools_wordset_page_category_search_ajax_cache_key($cache_args);
        $lock_option = ll_tools_wordset_page_category_search_ajax_cache_lock_option($cache_args);
        ll_tools_wordset_page_delete_durable_cached_payload($cache_key);
        delete_option($lock_option);

        $now = 1700000400;
        $window = 5 * MINUTE_IN_SECONDS;
        $token_option = ll_tools_wordset_page_category_search_cache_miss_reservation_option('token', $token, $window, $now);
        $ip_option = ll_tools_wordset_page_category_search_cache_miss_reservation_option('ip', '203.0.113.32', $window, $now);
        $token_timeout_option = ll_tools_wordset_page_category_search_cache_miss_reservation_timeout_option($token_option);
        $ip_timeout_option = ll_tools_wordset_page_category_search_cache_miss_reservation_timeout_option($ip_option);
        delete_option($token_option);
        delete_option($ip_option);
        delete_option($token_timeout_option);
        delete_option($ip_timeout_option);

        $fixed_now = static function () use ($now): int {
            return $now;
        };
        $token_limit = static function (): int {
            return 1;
        };
        $ip_limit = static function (): int {
            return 10;
        };
        $no_wait = static function (): int {
            return 0;
        };
        $index_queries = [];
        $capture = static function (string $query) use (&$index_queries): string {
            if (strpos($query, 'wordset_taxonomy') !== false && strpos($query, 'posts.ID >') !== false) {
                $index_queries[] = $query;
            }
            return $query;
        };

        add_filter('ll_tools_wordset_page_category_search_cache_miss_now', $fixed_now);
        add_filter('ll_tools_wordset_page_category_search_cache_miss_token_limit', $token_limit);
        add_filter('ll_tools_wordset_page_category_search_cache_miss_ip_limit', $ip_limit);
        add_filter('ll_tools_wordset_page_category_search_ajax_cache_build_wait_ms', $no_wait);
        add_filter('query', $capture);
        try {
            $this->assertTrue(ll_tools_wordset_page_acquire_category_search_ajax_cache_lock($cache_args, 30));
            $locked_response = $this->postCategorySearchAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'query' => 'n',
            ]);
            $this->assertFalse((bool) ($locked_response['success'] ?? true));
            $this->assertTrue((bool) (($locked_response['data'] ?? [])['retry'] ?? false));
            $this->assertSame([], $index_queries);
            $this->assertFalse(get_option($token_option, false), 'A lock loser must not consume the expensive-work budget.');
            $this->assertNotFalse(get_option($lock_option, false), 'A lock loser must not release another request\'s lock.');

            ll_tools_wordset_page_release_category_search_ajax_cache_lock($cache_args);
            $miss_response = $this->postCategorySearchAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'query' => 'n',
            ]);
            $this->assertTrue((bool) ($miss_response['success'] ?? false));
            $this->assertSame('n', (string) (($miss_response['data'] ?? [])['query'] ?? ''), 'One-character category search remains supported.');
            $this->assertSame([$category_id], array_values(array_map('intval', (array) (($miss_response['data'] ?? [])['categoryIds'] ?? []))));
            $this->assertCount(1, $index_queries);
            $this->assertSame(1, (int) get_option($token_option, 0));
            $this->assertFalse(get_option($lock_option, false));

            $hit_response = $this->postCategorySearchAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'query' => 'n',
            ]);
            $this->assertTrue((bool) ($hit_response['success'] ?? false));
            $this->assertSame(1, (int) get_option($token_option, 0), 'A cache hit must not reserve additional miss work.');
            $this->assertCount(1, $index_queries);
        } finally {
            remove_filter('query', $capture);
            remove_filter('ll_tools_wordset_page_category_search_ajax_cache_build_wait_ms', $no_wait);
            remove_filter('ll_tools_wordset_page_category_search_cache_miss_ip_limit', $ip_limit);
            remove_filter('ll_tools_wordset_page_category_search_cache_miss_token_limit', $token_limit);
            remove_filter('ll_tools_wordset_page_category_search_cache_miss_now', $fixed_now);
            ll_tools_wordset_page_release_category_search_ajax_cache_lock($cache_args);
            ll_tools_wordset_page_delete_durable_cached_payload($cache_key);
            delete_option($token_option);
            delete_option($ip_option);
            delete_option($token_timeout_option);
            delete_option($ip_timeout_option);
            if ($original_remote_addr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $original_remote_addr;
            }
        }
    }

    public function test_staff_category_search_includes_only_pending_recording_text_transcriptions(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('Pending Transcription Search ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = wp_insert_term('Pending Transcription Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);

        $word_id = $this->createSearchWord('Unrelated Visible Word', 'Unrelated translation', $wordset_id, [$category_id]);
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Pending transcription recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'remembered pending needle');
        update_post_meta($recording_id, 'll_auto_transcription_review_fields', ['recording_text' => true]);

        $token = ll_tools_wordset_page_store_category_search_payload([
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_id],
            'user_id' => $admin_id,
            'access_signature' => ll_tools_wordset_page_payload_access_signature($wordset_id, $admin_id),
        ]);
        $transcription_queries = [];
        $capture_query = static function (string $sql) use (&$transcription_queries): string {
            if (strpos($sql, 'recording_text.meta_value') !== false) {
                $transcription_queries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $capture_query);
        try {
            $response = $this->postCategorySearchAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'query' => 'pending needle',
            ]);
        } finally {
            remove_filter('query', $capture_query);
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $word_matches = (array) (($response['data'] ?? [])['wordMatches'] ?? []);
        $matches = (array) ($word_matches[$category_id] ?? $word_matches[(string) $category_id] ?? []);
        $this->assertNotEmpty($matches);
        $this->assertSame($word_id, (int) ($matches[0]['id'] ?? 0));
        $this->assertSame('recording_text', (string) ($matches[0]['match_field'] ?? ''));
        $this->assertArrayNotHasKey('matched_transcription', (array) $matches[0]);
        $this->assertNotEmpty($transcription_queries);
        $this->assertStringContainsString('SELECT DISTINCT', $transcription_queries[0]);
        $this->assertStringContainsString('FROM ' . $GLOBALS['wpdb']->posts . ' AS audio', $transcription_queries[0]);
        $this->assertStringContainsString('WHERE audio.ID IN (', $transcription_queries[0]);
        $this->assertStringNotContainsString('STRAIGHT_JOIN', $transcription_queries[0]);

        $short_query_sql = [];
        $capture_short_query = static function (string $sql) use (&$short_query_sql): string {
            if (strpos($sql, 'recording_text.meta_value') !== false) {
                $short_query_sql[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $capture_short_query);
        try {
            $short_response = $this->postCategorySearchAjax([
                'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
                'token' => $token,
                'wordset_id' => $wordset_id,
                'query' => 'xy',
            ]);
        } finally {
            remove_filter('query', $capture_short_query);
        }
        $this->assertSame([], $short_query_sql);
        $this->assertSame([], (array) (($short_response['data'] ?? [])['categoryIds'] ?? []));

        update_post_meta($recording_id, 'll_auto_transcription_review_fields', ['recording_ipa' => true]);
        $reviewed_response = $this->postCategorySearchAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
            'token' => $token,
            'wordset_id' => $wordset_id,
            'query' => 'pending needle',
        ]);
        $this->assertSame([], (array) (($reviewed_response['data'] ?? [])['categoryIds'] ?? []));

        update_post_meta($recording_id, 'll_auto_transcription_review_fields', ['recording_text' => true]);
        wp_set_current_user(0);
        $public_token = ll_tools_wordset_page_store_category_search_payload([
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_id],
            'user_id' => 0,
            'access_signature' => ll_tools_wordset_page_payload_access_signature($wordset_id, 0),
        ]);
        $public_response = $this->postCategorySearchAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
            'token' => $public_token,
            'wordset_id' => $wordset_id,
            'query' => 'pending needle',
        ]);
        $this->assertSame([], (array) (($public_response['data'] ?? [])['categoryIds'] ?? []));
    }

    public function test_uncategorized_virtual_category_uses_bounded_preview_and_ajax_search(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('Uncategorized Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $needle_word_id = 0;
        for ($index = 1; $index <= 6; $index++) {
            $title = sprintf('Bounded Orphan %02d', $index);
            $translation = sprintf('Bounded Translation %02d', $index);
            if ($index === 6) {
                $title .= ' Needle';
                $translation .= ' Hidden Needle';
            }
            $word_id = $this->createSearchWord($title, $translation, $wordset_id, []);
            if ($index === 6) {
                $needle_word_id = $word_id;
            }
        }
        $this->assertGreaterThan(0, $needle_word_id);

        $virtual_category_id = ll_tools_wordset_page_uncategorized_virtual_category_id();
        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $virtual_category = null;
        foreach ($categories as $category) {
            if (is_array($category) && (int) ($category['id'] ?? 0) === $virtual_category_id) {
                $virtual_category = $category;
                break;
            }
        }

        $this->assertIsArray($virtual_category);
        $this->assertSame(6, (int) ($virtual_category['count'] ?? 0));
        $this->assertSame(6, (int) ($virtual_category['content_count'] ?? 0));
        $this->assertIsArray($virtual_category['preview'] ?? null);
        $this->assertCount(4, (array) ($virtual_category['preview'] ?? []));
        $search_text = (string) ($virtual_category['search_text'] ?? '');
        $this->assertStringContainsString('Uncategorized', $search_text);
        $this->assertStringNotContainsString('Needle', $search_text);

        $token = ll_tools_wordset_page_store_category_search_payload([
            'wordset_id' => $wordset_id,
            'category_ids' => [$virtual_category_id],
            'user_id' => $admin_id,
            'access_signature' => ll_tools_wordset_page_payload_access_signature($wordset_id, $admin_id),
        ]);
        $this->assertNotSame('', $token);

        $response = $this->postCategorySearchAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
            'token' => $token,
            'wordset_id' => $wordset_id,
            'query' => 'needle',
        ]);

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data'] ?? null);
        $data = (array) $response['data'];
        $this->assertContains($virtual_category_id, array_values(array_map('intval', (array) ($data['categoryIds'] ?? []))));
        $word_matches = (array) ($data['wordMatches'] ?? []);
        $virtual_matches = $word_matches[$virtual_category_id] ?? $word_matches[(string) $virtual_category_id] ?? [];
        $this->assertNotEmpty($virtual_matches);
        $first_match = (array) $virtual_matches[0];
        $this->assertSame($needle_word_id, (int) ($first_match['id'] ?? 0));
    }

    /**
     * @param int[] $category_ids
     */
    private function createSearchWord(string $title, string $translation, int $wordset_id, array $category_ids): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        $wordset_result = wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        $category_result = wp_set_object_terms($word_id, array_values(array_map('intval', $category_ids)), 'word-category', false);
        $this->assertFalse(is_wp_error($wordset_result));
        $this->assertFalse(is_wp_error($category_result));
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    private function setCategoryOwner(int $category_id, int $wordset_id): void
    {
        $owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id';
        update_term_meta($category_id, $owner_meta_key, (string) $wordset_id);
    }

    /**
     * @param int[] $category_ids
     */
    private function createSearchWordWithAudio(string $title, string $translation, int $wordset_id, array $category_ids, string $audio_file_name): int
    {
        $word_id = $this->createSearchWord($title, $translation, $wordset_id, $category_ids);
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return $word_id;
    }

    private function createVocabLesson(int $wordset_id, int $category_id): int
    {
        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : $category_id;

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Search Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);

        return (int) $lesson_id;
    }

    private function assertWordHasTerm(int $word_id, string $taxonomy, int $term_id): void
    {
        global $wpdb;

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
             FROM {$wpdb->term_relationships} AS relationships
             INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
                ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
                AND taxonomy.taxonomy = %s
                AND taxonomy.term_id = %d
             WHERE relationships.object_id = %d",
            $taxonomy,
            $term_id,
            $word_id
        ));

        $this->assertSame(1, $count);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized): array
    {
        preg_match('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function postCategorySearchAjax(array $post): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = $post;
        $_REQUEST = $_POST;

        try {
            return $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_category_search_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }
    }

    /**
     * @return array<string,mixed>
     */
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
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
