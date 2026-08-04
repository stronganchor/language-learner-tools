<?php
declare(strict_types=1);

final class AudioUploadImageMatchIndexTest extends LL_Tools_TestCase
{
    private $originalState;
    private $originalVersion;
    private $originalIsolation;
    private $inlineBatchFilter;

    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_install_image_match_index_schema();
        $this->originalState = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION, null);
        $this->originalVersion = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, null);
        $this->originalIsolation = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        ll_tools_image_match_index_update_state([
            'status' => 'completed',
            'last_id' => 0,
            'processed' => 0,
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
        ]);
        $this->inlineBatchFilter = static function (): int {
            return 0;
        };
        add_filter('ll_tools_image_match_index_inline_batch_size', $this->inlineBatchFilter);
    }

    protected function tearDown(): void
    {
        remove_filter('ll_tools_image_match_index_inline_batch_size', $this->inlineBatchFilter);
        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION);
        delete_transient('ll_tools_image_match_index_schema_retry');
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);
        $this->restoreOption(LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION, $this->originalState);
        $this->restoreOption(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, $this->originalVersion);
        $this->restoreOption(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolation);
        parent::tearDown();
    }

    public function test_typo_only_match_uses_a_bounded_index_candidate_query(): void
    {
        $category_id = $this->ensureTerm('word-category', 'Indexed Mountains', 'indexed-mountains');
        for ($index = 0; $index < 80; $index++) {
            $this->createImage('Distractor ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT), $category_id);
        }
        $target_id = $this->createImage('Mountain', $category_id);

        $candidateQueries = [];
        $queryFilter = static function (string $sql) use (&$candidateQueries): string {
            if (strpos($sql, 'll_tools_image_match_candidates') !== false) {
                $candidateQueries[] = $sql;
            }
            return $sql;
        };
        $candidateLimitFilter = static function (): int {
            return 7;
        };
        $candidatePostLimits = [];
        $postQueryObserver = static function (WP_Query $query) use (&$candidatePostLimits): void {
            if ($query->get('post_type') === 'word_images' && !empty($query->get('post__in'))) {
                $candidatePostLimits[] = (int) $query->get('posts_per_page');
            }
        };

        add_filter('query', $queryFilter);
        add_filter('ll_tools_image_match_index_candidate_limit', $candidateLimitFilter);
        add_action('pre_get_posts', $postQueryObserver);
        try {
            $match = ll_find_matching_image_conservative('Moutain', [$category_id]);
        } finally {
            remove_filter('query', $queryFilter);
            remove_filter('ll_tools_image_match_index_candidate_limit', $candidateLimitFilter);
            remove_action('pre_get_posts', $postQueryObserver);
        }

        $this->assertInstanceOf(WP_Post::class, $match);
        $this->assertSame($target_id, (int) $match->ID);
        $this->assertCount(1, $candidateQueries);
        $this->assertStringContainsString('LIMIT 7', $candidateQueries[0]);
        $this->assertNotEmpty($candidatePostLimits);
        $this->assertLessThanOrEqual(7, max($candidatePostLimits));
        $this->assertNotContains(-1, $candidatePostLimits);
    }

    public function test_candidate_query_applies_wordset_owner_scope_before_the_limit(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $category_id = $this->ensureTerm('word-category', 'Indexed Owner Scope', 'indexed-owner-scope');
        $wordset_one_id = $this->ensureTerm('wordset', 'Indexed Owner One', 'indexed-owner-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Indexed Owner Two', 'indexed-owner-two');

        $first_id = $this->createImage('Owner Mountain', $category_id);
        $second_id = $this->createImage('Owner Mountain', $category_id);
        update_post_meta($first_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_one_id);
        update_post_meta($second_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_two_id);

        $candidate_ids = ll_tools_image_match_index_candidate_ids('Owner Mountain', [$category_id], [$wordset_two_id]);
        $this->assertSame([$second_id], $candidate_ids);
        $match = ll_find_matching_image_conservative('Owner Mountain', [$category_id], [$wordset_two_id]);

        $this->assertInstanceOf(WP_Post::class, $match);
        $this->assertSame($second_id, (int) $match->ID);
    }

    public function test_candidate_query_does_not_serve_a_partial_rebuild(): void
    {
        $category_id = $this->ensureTerm('word-category', 'Indexed Partial Read', 'indexed-partial-read');
        $this->createImage('Partial Read Candidate', $category_id);
        ll_tools_image_match_index_update_state([
            'status' => 'running',
            'last_id' => 1,
            'processed' => 1,
            'started_at' => gmdate('c'),
            'completed_at' => '',
        ]);

        $candidate_queries = [];
        $observe_candidate_query = static function (string $query) use (&$candidate_queries): string {
            if (strpos($query, 'll_tools_image_match_candidates') !== false) {
                $candidate_queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $observe_candidate_query);
        try {
            $this->assertSame(
                [],
                ll_tools_image_match_index_candidate_ids('Partial Read Candidate', [$category_id])
            );
        } finally {
            remove_filter('query', $observe_candidate_query);
        }

        $this->assertSame([], $candidate_queries);
    }

    public function test_candidate_query_failure_invalidates_readiness_and_queues_a_full_retry(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Candidate Retry', 'indexed-candidate-retry');
        $this->createImage('Candidate Retry Image', $category_id);
        ll_tools_image_match_index_update_state([
            'status' => 'completed',
            'last_id' => 999,
            'processed' => 1,
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
        ]);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);

        $failCandidateQuery = static function (string $sql) use ($wpdb): string {
            if (strpos($sql, 'll_tools_image_match_candidates') !== false) {
                return "/* ll_tools_image_match_candidates */ SELECT ID FROM {$wpdb->prefix}missing_image_match_candidates";
            }
            return $sql;
        };
        add_filter('query', $failCandidateQuery);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $this->assertSame([], ll_tools_image_match_index_candidate_ids('Candidate Retry Image', [$category_id]));
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $failCandidateQuery);
        }

        $state = ll_tools_image_match_index_state();
        $this->assertSame('', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, ''));
        $this->assertSame('pending', (string) $state['status']);
        $this->assertSame(0, (int) $state['last_id']);
        $this->assertSame(0, (int) $state['processed']);
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK));
    }

    public function test_save_failure_marks_image_index_unavailable_and_queues_rebuild(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Image Save Retry', 'image-save-retry');
        $image_id = $this->createImage('Image Save Retry Original', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        ll_tools_image_match_index_update_state([
            'status' => 'completed',
            'last_id' => $image_id,
            'processed' => 1,
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
        ]);
        update_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, LL_TOOLS_IMAGE_MATCH_INDEX_VERSION, false);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);

        $this->assertNotFalse($wpdb->update(
            $wpdb->posts,
            ['post_title' => 'Image Save Retry Changed'],
            ['ID' => $image_id],
            ['%s'],
            ['%d']
        ));
        clean_post_cache($image_id);
        $post = get_post($image_id);
        $this->assertInstanceOf(WP_Post::class, $post);

        $failed_writes = 0;
        $fail_index_insert = static function (string $query) use ($wpdb, &$failed_writes): string {
            if ($failed_writes === 0 && strpos($query, 'll_tools_image_match_index_sync') !== false) {
                $failed_writes++;
                return "INSERT INTO {$wpdb->prefix}missing_image_save_index (image_id) VALUES (1)";
            }
            return $query;
        };

        add_filter('query', $fail_index_insert);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            ll_tools_image_match_index_sync_post_on_save($image_id, $post);
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $fail_index_insert);
        }

        $this->assertSame(1, $failed_writes);
        $this->assertSame('', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, ''));
        $state = ll_tools_image_match_index_state();
        $this->assertSame('pending', $state['status']);
        $this->assertSame(0, (int) $state['last_id']);
        $this->assertSame(0, (int) $state['processed']);
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK));
    }

    public function test_auto_draft_save_cleanup_does_not_mark_image_index_unavailable(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Image Draft Cleanup', 'image-draft-cleanup');
        $image_id = $this->createImage('Image Draft Cleanup', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        ll_tools_image_match_index_update_state([
            'status' => 'completed',
            'last_id' => $image_id,
            'processed' => 1,
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
        ]);
        update_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, LL_TOOLS_IMAGE_MATCH_INDEX_VERSION, false);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);

        $this->assertNotFalse($wpdb->update(
            $wpdb->posts,
            ['post_status' => 'auto-draft'],
            ['ID' => $image_id],
            ['%s'],
            ['%d']
        ));
        clean_post_cache($image_id);
        $post = get_post($image_id);
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame('auto-draft', $post->post_status);

        ll_tools_image_match_index_sync_post_on_save($image_id, $post);

        $this->assertSame(
            LL_TOOLS_IMAGE_MATCH_INDEX_VERSION,
            (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, '')
        );
        $this->assertSame('completed', ll_tools_image_match_index_state()['status']);
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK));
        $this->assertSame(0, $this->indexRowCount($image_id));
        $this->assertSame('', (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true));
    }

    public function test_rebuild_advances_by_a_bounded_id_cursor(): void
    {
        $category_id = $this->ensureTerm('word-category', 'Indexed Rebuild', 'indexed-rebuild');
        $image_ids = [];
        for ($index = 0; $index < 12; $index++) {
            $image_ids[] = $this->createImage('Rebuild Image ' . $index, $category_id);
        }
        sort($image_ids, SORT_NUMERIC);
        foreach ($image_ids as $image_id) {
            ll_tools_image_match_index_delete_post_rows($image_id);
            delete_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY);
        }
        ll_tools_image_match_index_update_state([
            'status' => 'pending',
            'last_id' => $image_ids[0] - 1,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
        ]);

        $rebuildQueries = [];
        $queryFilter = static function (string $sql) use (&$rebuildQueries): string {
            if (strpos($sql, 'll_tools_image_match_rebuild') !== false) {
                $rebuildQueries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $queryFilter);
        try {
            $state = ll_tools_image_match_index_process_rebuild_batch(5);
        } finally {
            remove_filter('query', $queryFilter);
        }

        $this->assertSame(5, (int) $state['batch']);
        $this->assertSame(5, (int) $state['processed']);
        $this->assertSame($image_ids[4], (int) $state['last_id']);
        $this->assertSame('running', (string) $state['status']);
        $this->assertCount(1, $rebuildQueries);
        $this->assertStringContainsString('LIMIT 6', $rebuildQueries[0]);
        $this->assertGreaterThan(0, $this->indexRowCount($image_ids[4]));
        $this->assertSame(0, $this->indexRowCount($image_ids[5]));
    }

    public function test_rebuild_query_failure_keeps_cursor_retryable(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Retry', 'indexed-retry');
        $image_id = $this->createImage('Retry Image', $category_id);
        ll_tools_image_match_index_delete_post_rows($image_id);
        delete_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY);
        $initial_last_id = $image_id - 1;
        ll_tools_image_match_index_update_state([
            'status' => 'pending',
            'last_id' => $initial_last_id,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
        ]);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);

        $failRebuildQuery = static function (string $sql) use ($wpdb): string {
            if (strpos($sql, 'll_tools_image_match_rebuild') !== false) {
                return "/* ll_tools_image_match_rebuild */ SELECT ID FROM {$wpdb->prefix}missing_image_match_posts";
            }
            return $sql;
        };
        add_filter('query', $failRebuildQuery);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $failed_state = ll_tools_image_match_index_process_rebuild_batch(1);
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $failRebuildQuery);
        }

        $this->assertSame(0, (int) $failed_state['batch']);
        $this->assertSame(0, (int) $failed_state['processed']);
        $this->assertSame($initial_last_id, (int) $failed_state['last_id']);
        $this->assertSame('pending', (string) $failed_state['status']);
        $this->assertSame('', (string) $failed_state['completed_at']);
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK));

        $retried_state = ll_tools_image_match_index_process_rebuild_batch(1);
        $this->assertSame(1, (int) $retried_state['batch']);
        $this->assertSame(1, (int) $retried_state['processed']);
        $this->assertSame($image_id, (int) $retried_state['last_id']);
        $this->assertGreaterThan(0, $this->indexRowCount($image_id));
    }

    public function test_sync_uses_strict_complete_inserts_for_binary_hash_keys(): void
    {
        $category_id = $this->ensureTerm('word-category', 'Indexed Accent Hashes', 'indexed-accent-hashes');
        $image_id = $this->createImage('Cafe CAFÉ cafe café', $category_id);
        $insert_queries = [];
        $query_observer = static function (string $query) use (&$insert_queries): string {
            if (strpos($query, 'll_tools_image_match_index_sync') !== false) {
                $insert_queries[] = $query;
            }
            return $query;
        };

        add_filter('query', $query_observer);
        try {
            $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        } finally {
            remove_filter('query', $query_observer);
        }

        $this->assertCount(1, $insert_queries);
        $this->assertStringContainsString('INSERT INTO', $insert_queries[0]);
        $this->assertStringNotContainsString('INSERT IGNORE INTO', $insert_queries[0]);
        $rows = $this->indexRows($image_id);
        $expected_keys = ll_tools_image_match_index_keys('Cafe CAFÉ cafe café', true);
        $expected_row_count = array_sum(array_map('count', $expected_keys));
        $this->assertSame($expected_row_count, count($rows));
        foreach ($rows as $row) {
            // Hashing before storage means accents/case cannot collapse under
            // the table collation, so every prepared row must be inserted.
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string) ($row['lookup_value'] ?? ''));
        }
    }

    public function test_failed_replacement_rolls_back_rows_and_hash(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Atomic', 'indexed-atomic');
        $image_id = $this->createImage('Atomic Original', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $before_rows = $this->indexRows($image_id);
        $before_hash = (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true);
        $this->assertNotEmpty($before_rows);
        $this->assertNotSame('', $before_hash);

        $wpdb->update($wpdb->posts, ['post_title' => 'Atomic Replacement'], ['ID' => $image_id], ['%s'], ['%d']);
        clean_post_cache($image_id);
        $failInsert = static function (string $sql) use ($wpdb): string {
            if (strpos($sql, 'll_tools_image_match_index_sync') !== false) {
                return "/* ll_tools_image_match_index_sync */ INSERT INTO {$wpdb->prefix}missing_image_match_index (image_id) VALUES (0)";
            }
            return $sql;
        };
        add_filter('query', $failInsert);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $this->assertFalse(ll_tools_image_match_index_sync_post($image_id, true));
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $failInsert);
        }

        $this->assertSame($before_rows, $this->indexRows($image_id));
        $this->assertSame(
            $before_hash,
            (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true)
        );

        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $this->assertNotSame($before_rows, $this->indexRows($image_id));
        $this->assertNotSame(
            $before_hash,
            (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true)
        );
    }

    public function test_partial_successful_insert_rolls_back_rows_and_hash(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Partial Atomic', 'indexed-partial-atomic');
        $image_id = $this->createImage('Partial Original', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $before_rows = $this->indexRows($image_id);
        $before_hash = (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true);
        $this->assertNotEmpty($before_rows);
        $this->assertNotSame('', $before_hash);

        $replacement_title = 'Partial Replacement With Several Search Keys';
        $expected_keys = ll_tools_image_match_index_keys($replacement_title, true);
        $this->assertGreaterThan(1, array_sum(array_map('count', $expected_keys)));
        $wpdb->update($wpdb->posts, ['post_title' => $replacement_title], ['ID' => $image_id], ['%s'], ['%d']);
        clean_post_cache($image_id);

        $table = ll_tools_image_match_index_table_name();
        $force_partial_insert = static function (string $sql) use ($wpdb, $table, $image_id): string {
            if (strpos($sql, 'll_tools_image_match_index_sync') !== false) {
                return $wpdb->prepare(
                    "INSERT INTO {$table} /* ll_tools_image_match_index_sync */ (image_id, lookup_kind, lookup_value)
                     VALUES (%d, %s, %s)",
                    $image_id,
                    'exact',
                    sha1('forced partial row')
                );
            }
            return $sql;
        };
        add_filter('query', $force_partial_insert);
        try {
            $this->assertFalse(ll_tools_image_match_index_sync_post($image_id, true));
        } finally {
            remove_filter('query', $force_partial_insert);
        }

        $this->assertSame($before_rows, $this->indexRows($image_id));
        $this->assertSame(
            $before_hash,
            (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true)
        );
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $this->assertNotSame($before_rows, $this->indexRows($image_id));
    }

    public function test_source_post_read_error_preserves_rows_hash_and_rebuild_cursor(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Source Post', 'indexed-source-post');
        $image_id = $this->createImage('Source Post Preserved', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $before_rows = $this->indexRows($image_id);
        $before_hash = (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true);
        $this->assertNotEmpty($before_rows);
        $this->assertNotSame('', $before_hash);

        ll_tools_image_match_index_update_state([
            'status' => 'pending',
            'last_id' => $image_id - 1,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
        ]);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);

        $fault_count = 0;
        $fail_post_read = static function (string $query) use ($wpdb, $image_id, &$fault_count): string {
            if (
                $fault_count === 0
                && stripos($query, 'SELECT *') !== false
                && stripos($query, "FROM {$wpdb->posts}") !== false
                && preg_match('/WHERE\s+ID\s*=\s*' . $image_id . '\s+LIMIT\s+1/i', $query) === 1
            ) {
                $fault_count++;
                return "SELECT * FROM {$wpdb->prefix}missing_image_source_post WHERE ID = {$image_id}";
            }
            return $query;
        };

        clean_post_cache($image_id);
        add_filter('query', $fail_post_read);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $failed_state = ll_tools_image_match_index_process_rebuild_batch(1);
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $fail_post_read);
        }

        $this->assertSame(1, $fault_count);
        $this->assertSame(0, (int) $failed_state['batch']);
        $this->assertSame(0, (int) $failed_state['processed']);
        $this->assertSame($image_id - 1, (int) $failed_state['last_id']);
        $this->assertNotSame('completed', (string) $failed_state['status']);
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK));
        $this->assertSame($before_rows, $this->indexRows($image_id));
        $this->assertSame($before_hash, (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true));
    }

    public function test_source_meta_read_error_preserves_existing_rows_and_hash(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Source Meta', 'indexed-source-meta');
        $image_id = $this->createImage('Source Meta Preserved', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $before_rows = $this->indexRows($image_id);
        $before_hash = (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true);
        $this->assertNotEmpty($before_rows);
        $this->assertNotSame('', $before_hash);

        $fault_count = 0;
        $fail_meta_read = static function (string $query) use ($wpdb, $image_id, &$fault_count): string {
            if (
                $fault_count === 0
                && stripos($query, "FROM {$wpdb->postmeta}") !== false
                && preg_match('/post_id\s+IN\s*\(\s*' . $image_id . '\s*\)/i', $query) === 1
            ) {
                $fault_count++;
                return "SELECT post_id, meta_key, meta_value FROM {$wpdb->prefix}missing_image_source_meta";
            }
            return $query;
        };

        wp_cache_delete($image_id, 'post_meta');
        add_filter('query', $fail_meta_read);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $this->assertFalse(ll_tools_image_match_index_sync_post($image_id, true));
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $fail_meta_read);
        }

        $this->assertSame(1, $fault_count);
        $this->assertSame($before_rows, $this->indexRows($image_id));
        $this->assertSame($before_hash, (string) get_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY, true));
    }

    public function test_sync_preserves_an_enclosing_transaction(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Indexed Outer Transaction', 'indexed-outer-transaction');
        $image_id = $this->createImage('Image Outer Original', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        $original_title = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
            $image_id
        ));
        $first_savepoint_name = ll_tools_image_match_index_unique_savepoint_name('sync', $image_id);
        $second_savepoint_name = ll_tools_image_match_index_unique_savepoint_name('sync', $image_id);
        $this->assertNotSame($first_savepoint_name, $second_savepoint_name);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $first_savepoint_name);

        $transaction_open = $wpdb->query('START TRANSACTION') !== false;
        $this->assertTrue($transaction_open);
        try {
            $wpdb->update(
                $wpdb->posts,
                ['post_title' => 'Image Outer Pending'],
                ['ID' => $image_id],
                ['%s'],
                ['%d']
            );
            clean_post_cache($image_id);

            $legacy_probe = 'll_tools_image_match_transaction_probe';
            $this->assertNotFalse($wpdb->query("SAVEPOINT {$legacy_probe}"));
            $this->assertTrue(ll_tools_image_match_index_connection_in_transaction());
            $this->assertNotFalse($wpdb->query("ROLLBACK TO SAVEPOINT {$legacy_probe}"));
            $this->assertNotFalse($wpdb->query("RELEASE SAVEPOINT {$legacy_probe}"));

            $legacy_sync = 'll_tools_image_match_sync_' . $image_id;
            $this->assertNotFalse($wpdb->query("SAVEPOINT {$legacy_sync}"));
            $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
            $this->assertNotFalse($wpdb->query("ROLLBACK TO SAVEPOINT {$legacy_sync}"));
            $this->assertNotFalse($wpdb->query("RELEASE SAVEPOINT {$legacy_sync}"));

            $wpdb->query('ROLLBACK');
            $transaction_open = false;
        } finally {
            if ($transaction_open) {
                $wpdb->query('ROLLBACK');
            }
            clean_post_cache($image_id);
        }

        $this->assertSame(
            $original_title,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
                $image_id
            ))
        );
    }

    public function test_schema_version_is_not_published_when_verification_fails(): void
    {
        $forceMissing = static function (): bool {
            return false;
        };

        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION);
        delete_transient('ll_tools_image_match_index_schema_retry');
        add_filter('ll_tools_image_match_index_schema_exists_after_install', $forceMissing);
        try {
            $this->assertFalse(ll_tools_install_image_match_index_schema());
            $this->assertSame('', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, ''));
            $this->assertSame('0', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, ''));
            $this->assertNotFalse(get_transient('ll_tools_image_match_index_schema_retry'));
        } finally {
            remove_filter('ll_tools_image_match_index_schema_exists_after_install', $forceMissing);
            delete_transient('ll_tools_image_match_index_schema_retry');
            $this->assertTrue(ll_tools_install_image_match_index_schema());
        }

        $this->assertSame(
            LL_TOOLS_IMAGE_MATCH_INDEX_VERSION,
            (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, '')
        );
        $this->assertSame('1', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, ''));
        $this->assertTrue(ll_tools_image_match_index_schema_ready());
    }

    public function test_failed_table_existence_probe_does_not_publish_a_durable_absence_marker(): void
    {
        global $wpdb;

        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION);
        $fault_count = 0;
        $fail_show_tables = static function (string $query) use ($wpdb, &$fault_count): string {
            if ($fault_count === 0 && stripos($query, 'SHOW TABLES LIKE') !== false) {
                $fault_count++;
                return "SELECT table_name FROM {$wpdb->prefix}missing_image_match_tables";
            }
            return $query;
        };

        add_filter('query', $fail_show_tables);
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            $this->assertFalse(ll_tools_image_match_index_table_exists(true));
        } finally {
            $wpdb->suppress_errors($previous_suppress_errors);
            remove_filter('query', $fail_show_tables);
        }

        $this->assertSame(1, $fault_count);
        $this->assertSame('', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, ''));
        $this->assertTrue(ll_tools_image_match_index_table_exists(true));
        $this->assertSame('1', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, ''));
    }

    public function test_schema_metadata_faults_fail_closed_without_poisoning_the_next_check(): void
    {
        global $wpdb;

        $table = ll_tools_image_match_index_table_name();
        $fault_patterns = [
            'SHOW TABLE STATUS LIKE',
            "SHOW COLUMNS FROM {$table}",
            "SHOW INDEX FROM {$table}",
        ];

        foreach ($fault_patterns as $fault_pattern) {
            $fault_count = 0;
            $fail_metadata = static function (string $query) use ($wpdb, $fault_pattern, &$fault_count): string {
                if ($fault_count === 0 && stripos($query, $fault_pattern) !== false) {
                    $fault_count++;
                    return "SELECT schema_value FROM {$wpdb->prefix}missing_image_match_schema";
                }
                return $query;
            };

            add_filter('query', $fail_metadata);
            $previous_suppress_errors = $wpdb->suppress_errors(true);
            try {
                $this->assertFalse(ll_tools_image_match_index_schema_ready());
            } finally {
                $wpdb->suppress_errors($previous_suppress_errors);
                remove_filter('query', $fail_metadata);
            }

            $this->assertSame(1, $fault_count);
            $this->assertTrue(ll_tools_image_match_index_schema_ready());
        }

        $wpdb->last_error = 'synthetic stale schema error';
        $this->assertTrue(ll_tools_image_match_index_schema_ready());
    }

    public function test_schema_rejects_and_repairs_prefix_indexes(): void
    {
        global $wpdb;

        $this->assertTrue(ll_tools_install_image_match_index_schema());
        $table = ll_tools_image_match_index_table_name();
        $altered = $wpdb->query(
            "ALTER TABLE {$table}
             DROP INDEX uniq_image_lookup,
             DROP INDEX idx_kind_value_image,
             ADD UNIQUE KEY uniq_image_lookup (image_id, lookup_kind, lookup_value(8)),
             ADD KEY idx_kind_value_image (lookup_kind, lookup_value(8), image_id)"
        );
        $this->assertNotFalse($altered);

        try {
            $prefixed_rows = array_values(array_filter(
                (array) $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A),
                static function (array $row): bool {
                    return in_array(
                        (string) ($row['Key_name'] ?? ''),
                        ['uniq_image_lookup', 'idx_kind_value_image'],
                        true
                    ) && isset($row['Sub_part']) && (int) $row['Sub_part'] > 0;
                }
            ));
            $this->assertNotEmpty($prefixed_rows);
            $this->assertFalse(ll_tools_image_match_index_schema_ready());

            delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION);
            delete_transient('ll_tools_image_match_index_schema_retry');
            $this->assertTrue(ll_tools_install_image_match_index_schema());
            $this->assertTrue(ll_tools_image_match_index_schema_ready());

            $remaining_prefixes = array_values(array_filter(
                (array) $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A),
                static function (array $row): bool {
                    return in_array(
                        (string) ($row['Key_name'] ?? ''),
                        ['uniq_image_lookup', 'idx_kind_value_image'],
                        true
                    ) && isset($row['Sub_part']) && (int) $row['Sub_part'] > 0;
                }
            ));
            $this->assertSame([], $remaining_prefixes);
        } finally {
            if (!ll_tools_image_match_index_schema_ready()) {
                if (ll_tools_image_match_index_table_exists(true)) {
                    $previous_suppress_errors = $wpdb->suppress_errors(true);
                    $wpdb->query("ALTER TABLE {$table} DROP INDEX uniq_image_lookup");
                    $wpdb->query("ALTER TABLE {$table} DROP INDEX idx_kind_value_image");
                    $wpdb->suppress_errors($previous_suppress_errors);
                    $wpdb->query(
                        "ALTER TABLE {$table}
                         ADD UNIQUE KEY uniq_image_lookup (image_id, lookup_kind, lookup_value),
                         ADD KEY idx_kind_value_image (lookup_kind, lookup_value, image_id)"
                    );
                }
                delete_transient('ll_tools_image_match_index_schema_retry');
                ll_tools_install_image_match_index_schema();
            }
        }
    }

    public function test_missing_table_candidate_read_queues_no_ddl_bounded_repair(): void
    {
        global $wpdb;

        $category_id = $this->ensureTerm('word-category', 'Missing Index Table', 'missing-index-table');
        $image_id = $this->createImage('Missing Table Candidate', $category_id);
        $this->assertTrue(ll_tools_image_match_index_sync_post($image_id, true));
        ll_tools_image_match_index_update_state([
            'status' => 'completed',
            'last_id' => $image_id,
            'processed' => 1,
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
        ]);
        update_option(
            LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION,
            LL_TOOLS_IMAGE_MATCH_INDEX_VERSION,
            false
        );
        update_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, '1', false);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);

        $schema_queries = [];
        $candidate_queries = [];
        $observe_queries = static function (string $query) use ($wpdb, &$schema_queries, &$candidate_queries): string {
            if (strpos($query, 'll_tools_image_match_candidates') !== false) {
                $candidate_queries[] = $query;
                return "SELECT image_id FROM {$wpdb->prefix}missing_image_match_candidates";
            }
            if (preg_match(
                '/\b(?:SHOW\s+(?:TABLES|TABLE\s+STATUS|COLUMNS|INDEX)|CREATE\s+TABLE|ALTER\s+TABLE)\b/i',
                $query
            ) === 1) {
                $schema_queries[] = $query;
            }
            return $query;
        };

        try {
            add_filter('query', $observe_queries);
            $previous_suppress_errors = $wpdb->suppress_errors(true);
            try {
                $candidate_ids = ll_tools_image_match_index_candidate_ids(
                    'Missing Table Candidate',
                    [$category_id]
                );
            } finally {
                $wpdb->suppress_errors($previous_suppress_errors);
                remove_filter('query', $observe_queries);
            }

            $this->assertSame([], $candidate_ids);
            $this->assertCount(1, $candidate_queries);
            $this->assertSame([], $schema_queries);
            $this->assertSame('', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, ''));
            $this->assertSame('0', (string) get_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, ''));
            $state = ll_tools_image_match_index_state();
            $this->assertSame('pending', $state['status']);
            $this->assertSame(0, (int) $state['last_id']);
            $this->assertSame(0, (int) $state['processed']);
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK));

            ll_tools_image_match_index_maybe_upgrade();
            $repaired_state = ll_tools_image_match_index_process_rebuild_batch(10);
            $this->assertSame('completed', $repaired_state['status']);
            $this->assertTrue(ll_tools_image_match_index_schema_ready());
            $this->assertGreaterThan(0, $this->indexRowCount($image_id));
        } finally {
            remove_filter('query', $observe_queries);
            delete_transient('ll_tools_image_match_index_schema_retry');
            if (!ll_tools_image_match_index_schema_ready()) {
                ll_tools_install_image_match_index_schema();
            }
        }
    }

    private function createImage(string $title, int $category_id): int
    {
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_object_terms($image_id, [$category_id], 'word-category', false);
        return (int) $image_id;
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }
        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);
        return (int) $inserted['term_id'];
    }

    private function restoreOption(string $name, $value): void
    {
        if ($value === null) {
            delete_option($name);
            return;
        }
        update_option($name, $value, false);
    }

    private function indexRowCount(int $image_id): int
    {
        global $wpdb;
        $table = ll_tools_image_match_index_table_name();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE image_id = %d", $image_id));
    }

    private function indexRows(int $image_id): array
    {
        global $wpdb;
        $table = ll_tools_image_match_index_table_name();
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT lookup_kind, lookup_value FROM {$table} WHERE image_id = %d ORDER BY lookup_kind, lookup_value",
                $image_id
            ),
            ARRAY_A
        );
    }
}
