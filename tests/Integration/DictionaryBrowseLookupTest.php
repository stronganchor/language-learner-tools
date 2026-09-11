<?php
declare(strict_types=1);

final class DictionaryBrowseLookupTest extends LL_Tools_TestCase
{
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_dictionary_browser_clear_query_error();
        unset($GLOBALS['ll_tools_ai_crawler_source_error']);
        // TRUNCATE in the production rebuild can commit Core's test transaction;
        // its rollback may resurrect the pre-TRUNCATE transient lock next test.
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY);
        ll_tools_dictionary_lookup_reset_request_schema_cache();
        $this->assertTrue(ll_tools_install_dictionary_lookup_schema());
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        ll_tools_dictionary_browser_clear_query_error();
        unset($GLOBALS['ll_tools_ai_crawler_source_error']);
        parent::tearDown();
        // A production rebuild's TRUNCATE can commit the fixture inserts.
        // Delete after Core's rollback so teardown cannot resurrect those posts.
        foreach ($this->entries as $id) { wp_delete_post($id, true); }
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY);
        ll_tools_dictionary_browser_clear_query_error();
    }

    private function entry(string $title): int
    {
        global $wpdb;
        $now = current_time('mysql');
        $this->assertSame(1, $wpdb->insert($wpdb->posts, [
            'post_author' => 0, 'post_date' => $now, 'post_date_gmt' => $now,
            'post_content' => '', 'post_title' => $title, 'post_excerpt' => '',
            'post_status' => 'publish', 'comment_status' => 'closed', 'ping_status' => 'closed',
            'post_password' => '', 'post_name' => '', 'to_ping' => '', 'pinged' => '',
            'post_modified' => $now, 'post_modified_gmt' => $now, 'post_content_filtered' => '',
            'post_parent' => 0, 'guid' => '', 'menu_order' => 0, 'post_type' => 'll_dictionary_entry',
            'post_mime_type' => '', 'comment_count' => 0,
        ]));
        $id = (int) $wpdb->insert_id;
        $this->entries[] = $id;
        return $id;
    }

    private function rebuild(): void
    {
        global $wpdb;
        ll_tools_schedule_dictionary_lookup_rebuild(true);
        for ($batch = 0; $batch < 20 && !ll_tools_dictionary_browse_lookup_is_ready(); $batch++) {
            ll_tools_dictionary_lookup_process_rebuild_batch();
        }
        $this->assertTrue(ll_tools_dictionary_browse_lookup_is_ready(), (string) wp_json_encode([
            'state' => ll_tools_get_dictionary_lookup_rebuild_state(), 'error' => $wpdb->last_error,
            'lock' => get_transient(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_LOCK_KEY),
        ]));
        ll_tools_bump_dictionary_browser_cache_version();
    }

    private function browse(string $letter, string $language = 'tr', int $limit = 250): array
    {
        return ll_tools_dictionary_query_entry_ids_by_browse_constraints(['publish'], 0, $letter, '', '', '', $limit, $language);
    }

    public function test_rare_and_absent_unicode_buckets_do_not_read_nonmatching_title_batches(): void
    {
        for ($index = 0; $index < 1501; $index++) { $this->entry('Ordinary title ' . $index); }
        $late = $this->entry('Ç Late matching title');
        $this->rebuild();
        $queries = [];
        $observe = static function (string $sql) use (&$queries): string {
            if (str_contains($sql, 'browse_initial') || str_contains($sql, 'p.ID, p.post_title')) { $queries[] = $sql; }
            return $sql;
        };
        add_filter('query', $observe);
        try {
            $this->assertSame([$late], $this->browse('Ç'));
            $this->assertSame([], $this->browse('Ж'));
        } finally { remove_filter('query', $observe); }
        $this->assertCount(2, $queries, 'Each uncached bucket uses one indexed candidate query.');
        foreach ($queries as $sql) {
            $this->assertStringContainsString('browse_initial.lookup_value =', $sql);
            $this->assertStringContainsString('LIMIT 250', $sql);
            $this->assertStringNotContainsString('p.ID, p.post_title', $sql);
        }
        $this->assertNull(ll_tools_dictionary_browser_get_query_error());
    }

    public function test_index_preserves_canonical_unicode_and_turkic_buckets(): void
    {
        $titles = ['Çay', 'çima', 'Şam', 'şima', 'İpek', 'ipek', 'Irmak', 'ırmak',
            'Σigma', 'σigma', 'ςigma', 'ßeta', 'ẞeta', 'Simple', 'Ābel', 'ābel', 'Abel'];
        $ids = [];
        foreach ($titles as $title) { $ids[$this->entry($title)] = $title; }
        $this->rebuild();
        foreach (['tr', 'zza', 'en', 'el'] as $language) {
            foreach (['Ç', 'Ş', 'İ', 'Σ', 'ẞ', 'ß', 'Ā'] as $letter) {
                $normalized = ll_tools_dictionary_normalize_browse_letter($letter, $language);
                $expected = array_keys(array_filter($ids, static fn(string $title): bool =>
                    ll_tools_dictionary_title_matches_browse_letter($title, $normalized, $language)));
                $this->assertSame($expected, $this->browse($letter, $language), $language . ': ' . $letter);
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(array_filter($ids, static fn(string $title): bool => in_array($title, ['Irmak', 'ırmak'], true))), $this->browse('I'));
        $this->assertSame(array_keys(array_filter($ids, static fn(string $title): bool => in_array($title, ['İpek', 'ipek'], true))), $this->browse('İ'));
    }

    public function test_legacy_ready_marker_requires_a_background_backfill_and_never_caches_empty(): void
    {
        $id = $this->entry('Ç Legacy source');
        ll_tools_update_dictionary_lookup_rebuild_state([
            'status' => 'completed', 'last_id' => $id, 'processed' => 1, 'truncate_pending' => 0,
        ]);
        ll_tools_bump_dictionary_browser_cache_version();
        $queries = [];
        $observe = static function (string $sql) use (&$queries): string { $queries[] = $sql; return $sql; };
        add_filter('query', $observe);
        try {
            ll_tools_dictionary_maybe_schedule_browse_lookup_backfill();
            $this->assertSame([], $this->browse('Ç'));
        } finally { remove_filter('query', $observe); }
        $this->assertInstanceOf(WP_Error::class, ll_tools_dictionary_browser_get_query_error());
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('p.ID, p.post_title', $sql);
            $this->assertDoesNotMatchRegularExpression('/\b(?:CREATE|ALTER|TRUNCATE)\s+TABLE\b/i', $sql);
        }
        ll_tools_dictionary_lookup_process_rebuild_batch();
        $this->assertTrue(ll_tools_dictionary_browse_lookup_is_ready());
        $this->assertSame([$id], $this->browse('Ç'));
    }

    public function test_indexed_bucket_query_failure_remains_retryable_under_the_same_cache_key(): void
    {
        global $wpdb;
        $id = $this->entry('Ç Recoverable source');
        $this->rebuild();
        $fail = static fn(string $sql): string => str_contains($sql, 'browse_initial.lookup_value =')
            ? 'SELECT ID FROM ll_tools_missing_browse_initials' : $sql;
        $suppress = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try { $this->assertSame([], $this->browse('Ç')); }
        finally { remove_filter('query', $fail); $wpdb->suppress_errors($suppress); }
        $this->assertInstanceOf(WP_Error::class, ll_tools_dictionary_browser_get_query_error());
        $this->assertSame([$id], $this->browse('Ç'));
    }

    public function test_title_changes_replace_initial_rows_without_another_full_backfill(): void
    {
        $id = $this->entry('Ç Original title');
        $this->rebuild();
        $this->assertSame([$id], $this->browse('Ç'));
        wp_update_post(['ID' => $id, 'post_title' => 'Ş Changed title']);
        $this->assertTrue(ll_tools_dictionary_browse_lookup_is_ready());
        $this->assertSame([], $this->browse('Ç'));
        $this->assertSame([$id], $this->browse('Ş'));
    }

    public function test_failed_completion_write_keeps_background_retry_and_browse_unavailable(): void
    {
        global $wpdb;
        $id = $this->entry('Ç Completion retry');
        ll_tools_schedule_dictionary_lookup_rebuild(true);
        wp_clear_scheduled_hook(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        $failures = 0;
        $fail = static function (string $sql) use (&$failures): string {
            if (str_starts_with($sql, 'UPDATE') && str_contains($sql, LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_STATE_OPTION)
                && str_contains($sql, 'completed_at') && str_contains($sql, 's:9:\\"completed\\"')) {
                $failures++;
                return 'UPDATE ll_tools_missing_lookup_checkpoint SET missing = 1';
            }
            return $sql;
        };
        $suppress = $wpdb->suppress_errors(true);
        add_filter('query', $fail);
        try { ll_tools_dictionary_lookup_process_rebuild_batch(); }
        finally { remove_filter('query', $fail); $wpdb->suppress_errors($suppress); }
        $this->assertSame(1, $failures);
        $this->assertFalse(ll_tools_dictionary_browse_lookup_is_ready());
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK));
        wp_clear_scheduled_hook(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK);
        ll_tools_dictionary_maybe_schedule_browse_lookup_backfill();
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_DICTIONARY_LOOKUP_REBUILD_HOOK), 'An ordinary request repairs a lost continuation too.');
        $this->assertSame([], $this->browse('Ç'));
        ll_tools_dictionary_lookup_process_rebuild_batch();
        $this->assertTrue(ll_tools_dictionary_browse_lookup_is_ready());
        $this->assertSame([$id], $this->browse('Ç'));
    }

    public function test_additive_backfill_keeps_existing_search_ready_until_unicode_buckets_complete(): void
    {
        global $wpdb;
        $first = $this->entry('Existing indexed headword');
        for ($index = 0; $index < 250; $index++) { $this->entry('Ordinary legacy entry ' . $index); }
        $late = $this->entry('Ç Late legacy initial');
        $this->rebuild();
        $wpdb->query('DELETE FROM ' . ll_tools_dictionary_lookup_table_name() . " WHERE lookup_kind IN ('browse_initial', 'browse_initial_tr')");
        ll_tools_update_dictionary_lookup_rebuild_state(['status' => 'completed', 'last_id' => $late, 'processed' => 252]);
        ll_tools_dictionary_lookup_process_rebuild_batch();
        $this->assertSame('running', ll_tools_get_dictionary_lookup_rebuild_state()['status']);
        $this->assertTrue(ll_tools_dictionary_lookup_is_ready(), 'A browse-only backfill must retain the verified search generation.');
        $this->assertFalse(ll_tools_dictionary_browse_lookup_is_ready());
        $queries = [];
        $observe = static function (string $sql) use (&$queries): string { $queries[] = $sql; return $sql; };
        add_filter('query', $observe);
        try {
            $this->assertSame([$first], ll_tools_dictionary_query_entry_ids_from_lookup_table('Existing indexed headword', ['publish'], 'all', 10));
            $public = ll_tools_dictionary_query_entries(['search' => 'Existing indexed headword', 'per_page' => 10]);
            $this->assertSame([$first], array_map('intval', array_column($public['items'], 'id')));
            $this->assertSame([], $this->browse('Ç'));
        } finally { remove_filter('query', $observe); }
        $this->assertStringContainsString(ll_tools_dictionary_lookup_table_name(), implode("\n", $queries));
        $this->assertStringNotContainsString('search_index.meta_value LIKE', implode("\n", $queries));
        $this->assertInstanceOf(WP_Error::class, ll_tools_dictionary_browser_get_query_error());
        ll_tools_dictionary_lookup_process_rebuild_batch();
        $this->assertTrue(ll_tools_dictionary_browse_lookup_is_ready());
        $this->assertSame([$late], $this->browse('Ç'));

        ll_tools_schedule_dictionary_lookup_rebuild(true);
        ll_tools_dictionary_lookup_process_rebuild_batch();
        $this->assertSame(0, ll_tools_get_dictionary_lookup_rebuild_state()['browse_only']);
        $this->assertFalse(ll_tools_dictionary_lookup_is_ready(), 'An initial full rebuild must remain unavailable until complete.');
        $this->assertFalse(ll_tools_dictionary_browse_lookup_is_ready());
    }
}
