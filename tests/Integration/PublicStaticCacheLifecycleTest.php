<?php
declare(strict_types=1);

final class PublicStaticCacheLifecycleTest extends LL_Tools_TestCase
{
    private array $limits = [];
    private array $ownedFiles = [];
    private array $ownedDirectories = [];
    private $limitsFilter;

    protected function setUp(): void
    {
        parent::setUp();
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user(0);
        ll_tools_purge_public_static_cache();
        update_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA, false);
        $this->clearInventory();
        delete_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE);
        $this->limitsFilter = function (array $limits): array {
            return array_merge($limits, ['seconds' => 3.0], $this->limits);
        };
        add_filter('ll_tools_public_static_cache_gc_limits', $this->limitsFilter);
    }

    protected function tearDown(): void
    {
        remove_filter('ll_tools_public_static_cache_gc_limits', $this->limitsFilter);
        unset($GLOBALS['ll_tools_public_static_cache_request']);
        ll_tools_public_static_cache_delete_rebuild_locks();
        foreach ($this->ownedFiles as $file) {
            if (is_file($file) || is_link($file)) { @unlink($file); }
        }
        foreach ($this->ownedDirectories as $directory) { @rmdir($directory); }
        ll_tools_purge_public_static_cache();
        $this->clearInventory();
        delete_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE);
        wp_clear_scheduled_hook(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK);
        parent::tearDown();
    }

    public function test_schema_maintenance_retires_legacy_upload_html_and_sidecars_without_adopting_them(): void
    {
        $base = trailingslashit(sys_get_temp_dir()) . 'll-tools-legacy-' . wp_generate_uuid4();
        $legacy = trailingslashit($base) . LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME;
        $shard = trailingslashit($legacy) . 'files/ab';
        $this->assertTrue(wp_mkdir_p($shard));
        $this->ownedDirectories = [$shard, dirname($shard), $legacy, $base];
        $key = 'ab' . str_repeat('c', 30);
        $files = [
            trailingslashit($legacy) . 'public-' . $key . '.html',
            trailingslashit($shard) . 'public-' . $key . '.html',
            trailingslashit($shard) . 'public-' . $key . '.meta.json',
            trailingslashit($shard) . 'public-' . $key . '.html.tmp-probe',
        ];
        $unrelated = trailingslashit($shard) . 'preserve.txt';
        $this->ownedFiles = array_merge($files, [$unrelated]);
        foreach ($this->ownedFiles as $file) {
            $this->assertNotFalse(file_put_contents($file, 'legacy fixture'));
        }
        $uploads = static function (array $paths) use ($base): array {
            $paths['basedir'] = $base;
            return $paths;
        };
        add_filter('upload_dir', $uploads);
        update_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA - 1, false);
        delete_option(LL_TOOLS_PUBLIC_STATIC_CACHE_UPLOADS_RETIRED_OPTION);
        delete_option('ll_tools_public_static_cache_retry_after');
        try {
            $this->assertNull(ll_tools_public_static_cache_read_prepared_file($files[0], $this->identity(1)));
            ll_tools_public_static_cache_maybe_purge_legacy_schema();
            foreach ($files as $file) {
                $this->assertFileDoesNotExist($file);
            }
            $this->assertFileExists($unrelated);
            $this->assertFileDoesNotExist(ll_tools_public_static_cache_file_path($key));
            $this->assertSame(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA, (int) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION));
            $this->assertTrue((bool) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_UPLOADS_RETIRED_OPTION, false));
        } finally {
            remove_filter('upload_dir', $uploads);
        }
    }

    public function test_unsafe_legacy_uploads_remain_pending_while_private_cache_can_publish(): void
    {
        $base = trailingslashit(sys_get_temp_dir()) . 'll-tools-unsafe-legacy-' . wp_generate_uuid4();
        $legacy = trailingslashit($base) . LL_TOOLS_PUBLIC_STATIC_CACHE_DIRNAME;
        $this->assertTrue(wp_mkdir_p($base));
        $this->ownedDirectories[] = $base;
        $this->ownedFiles[] = $legacy;
        $this->assertNotFalse(file_put_contents($legacy, 'not a cache directory'));
        $uploads = static function (array $paths) use ($base): array {
            $paths['basedir'] = $base;
            return $paths;
        };
        add_filter('upload_dir', $uploads);
        update_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION, LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA - 1, false);
        delete_option(LL_TOOLS_PUBLIC_STATIC_CACHE_UPLOADS_RETIRED_OPTION);
        delete_option('ll_tools_public_static_cache_retry_after');
        wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['public_static_cache']);
        try {
            ll_tools_public_static_cache_maybe_purge_legacy_schema();
            $this->assertSame(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA, (int) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_SCHEMA_OPTION));
            $this->assertFalse((bool) get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_UPLOADS_RETIRED_OPTION, false));
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, ['public_static_cache']));
            $this->assertSame('not a cache directory', file_get_contents($legacy));

            $identity = $this->identity(1);
            $key = ll_tools_public_static_cache_key($identity, [], 'en_US');
            $file = $this->publish($identity, $key);
            $this->assertFileExists($file);
            $this->assertIsString(ll_tools_public_static_cache_read_prepared_file($file, $identity));
        } finally {
            remove_filter('upload_dir', $uploads);
        }
    }

    public function test_production_md5_key_publication_is_inventoried_and_uses_sharded_storage(): void
    {
        $identity = $this->identity(1);
        $key = ll_tools_public_static_cache_key($identity, [], 'en_US');
        $this->assertSame(32, strlen($key));
        $file = $this->publish($identity, $key);
        $this->assertFileExists($file);
        $this->assertStringContainsString('/files/' . substr($key, 0, 2) . '/', wp_normalize_path($file));
        $entry = get_option(ll_tools_public_static_cache_gc_entry_option($key));
        $this->assertIsArray($entry);
        $this->assertSame($key, $entry['key']);
        $this->assertSame(filesize($file), $entry['bytes']);
        $this->assertSame(hash_file('sha256', $file), $entry['signature']);
        $this->assertSame([], $this->temporaryOptions($key));
        $this->assertStringContainsString('Lifecycle captured body', (string) ll_tools_public_static_cache_read_prepared_file($file, $identity));
    }

    public function test_failed_final_inventory_write_keeps_recovery_journal_until_gc_adopts_published_html(): void
    {
        $identity = $this->identity(2);
        $key = ll_tools_public_static_cache_key($identity, [], 'en_US');
        $option = ll_tools_public_static_cache_gc_entry_option($key);
        $reject = static fn($candidate, $before) => $before;
        add_filter('pre_update_option_' . $option, $reject, 10, 2);
        try { $file = $this->publish($identity, $key); }
        finally { remove_filter('pre_update_option_' . $option, $reject, 10); }
        $this->assertFileExists($file);
        $this->assertFalse(get_option($option, false));
        $journals = $this->temporaryOptions($key);
        $this->assertNotEmpty($journals, 'The missing final inventory must retain a crash-recovery receipt.');
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertFalse($result['retry']);
        $this->assertIsArray(get_option($option));
        foreach ($journals as $journal) { $this->assertFalse(get_option($journal, false)); }
        $this->assertStringContainsString('Lifecycle captured body', (string) ll_tools_public_static_cache_read_prepared_file($file, $identity));
    }

    public function test_quota_pressure_preserves_current_fresh_and_stale_fallback_but_reclaims_obsolete_output(): void
    {
        $obsolete = $this->createCache(3);
        ll_tools_public_static_cache_rotate_generation();
        $fresh = $this->createCache(4);
        $stale = $this->createCache(5, time() - ll_tools_public_static_cache_ttl() - 100);
        $this->limits = ['bytes' => 1, 'entries' => 1];
        update_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE, ['last_bytes' => 1000, 'last_entries' => 3], false);
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertSame(1, $result['deleted']);
        $this->assertFileDoesNotExist($obsolete['file']);
        $this->assertFileDoesNotExist(ll_tools_public_static_cache_meta_file_path($obsolete['file']));
        $this->assertFileExists($fresh['file']);
        $this->assertFileExists($stale['file']);
        $this->assertIsString(ll_tools_public_static_cache_read_prepared_file($stale['file'], $stale['identity']));
        $state = get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE);
        $this->assertTrue($state['over_budget'], 'A soft target must remain truthful when usable fallback is retained.');
    }

    public function test_obsolete_age_reclaims_old_output_without_removing_recent_obsolete_output(): void
    {
        $old = $this->createCache(6, time() - 1200);
        $recent = $this->createCache(7);
        ll_tools_public_static_cache_rotate_generation();
        $this->limits = ['obsolete_age' => 600];
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertSame(1, $result['deleted']);
        $this->assertFileDoesNotExist($old['file']);
        $this->assertFileExists($recent['file']);
    }

    public function test_authoritative_busy_first_key_does_not_starve_later_cleanup_or_trust_stale_object_cache(): void
    {
        global $wpdb;
        $first = $this->createCache(8, time() - 1200, str_repeat('0', 31) . '1');
        $later = $this->createCache(9, time() - 1200, str_repeat('0', 31) . '2');
        ll_tools_public_static_cache_rotate_generation();
        $this->limits = ['obsolete_age' => 600];
        $this->assertTrue(ll_tools_public_static_cache_acquire_rebuild_lock($first['key']));
        $option = ll_tools_public_static_cache_rebuild_lock_option($first['key']);
        $lease = ll_tools_public_static_cache_get_rebuild_lease_value($first['key']);
        wp_cache_set($option, (time() - 10) . '|stale-object-cache', 'options');
        try {
            $result = ll_tools_public_static_cache_gc_run();
            $this->assertGreaterThanOrEqual(1, $result['deferred']);
            $this->assertFileExists($first['file']);
            $this->assertFileDoesNotExist($later['file']);
            $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
            $this->assertSame($lease, $stored);
        } finally { ll_tools_public_static_cache_release_rebuild_lock($first['key']); }
    }

    public function test_busy_publication_stripe_defers_only_its_key(): void
    {
        $first = $this->createCache(10, time() - 1200, str_repeat('0', 31) . '3');
        $later = $this->createCache(11, time() - 1200, str_repeat('0', 31) . '4');
        ll_tools_public_static_cache_rotate_generation();
        $this->limits = ['obsolete_age' => 600];
        $lock = ll_tools_public_static_cache_open_publication_lock($first['file']);
        $this->assertIsResource($lock);
        try {
            $result = ll_tools_public_static_cache_gc_run();
            $this->assertGreaterThanOrEqual(1, $result['deferred']);
            $this->assertFileExists($first['file']);
            $this->assertFileDoesNotExist($later['file']);
        } finally { ll_tools_public_static_cache_close_publication_lock($lock); }
    }

    public function test_html_metadata_and_temporary_files_share_the_same_publication_stripe(): void
    {
        $key = ll_tools_public_static_cache_key($this->identity(22), [], 'en_US');
        $file = ll_tools_public_static_cache_file_path($key);
        $this->assertTrue(wp_mkdir_p(dirname($file)));
        $htmlLock = ll_tools_public_static_cache_open_publication_lock($file);
        $this->assertIsResource($htmlLock);
        $metadataLock = null;
        $htmlTempLock = null;
        $metadataTempLock = null;
        try {
            $metadataLock = ll_tools_public_static_cache_open_publication_lock(ll_tools_public_static_cache_meta_file_path($file));
            $htmlTempLock = ll_tools_public_static_cache_open_publication_lock($file . '.tmp-StripeFixture');
            $metadataTempLock = ll_tools_public_static_cache_open_publication_lock(ll_tools_public_static_cache_meta_file_path($file) . '.tmp-StripeFixture');
            $this->assertNull($metadataLock);
            $this->assertNull($htmlTempLock);
            $this->assertNull($metadataTempLock);
        } finally {
            foreach ([$metadataLock, $htmlTempLock, $metadataTempLock, $htmlLock] as $lock) {
                ll_tools_public_static_cache_close_publication_lock($lock);
            }
        }
    }

    public function test_legacy_root_html_is_physically_adopted_without_losing_usable_output(): void
    {
        $cache = $this->createCache(12, null, null, true, false);
        $target = ll_tools_public_static_cache_file_path($cache['key']);
        $this->assertSame($cache['file'], ll_tools_public_static_cache_existing_file_path($cache['key']));
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertSame(1, $result['adopted']);
        $this->assertFileDoesNotExist($cache['file']);
        $this->assertFileExists($target);
        $this->assertFileExists(ll_tools_public_static_cache_meta_file_path($target));
        $this->assertIsString(ll_tools_public_static_cache_read_prepared_file($target, $cache['identity']));
        $this->assertIsArray(get_option(ll_tools_public_static_cache_gc_entry_option($cache['key'])));
    }

    public function test_explicit_purge_covers_root_and_shards_while_preserving_unrelated_files(): void
    {
        $legacy = $this->createCache(13, null, null, true, false);
        $sharded = $this->createCache(14);
        $unrelated = $this->createCache(15);
        ll_tools_purge_public_static_cache(['wordset_ids' => [13, 14]]);
        $this->assertFileDoesNotExist($legacy['file']);
        $this->assertFileDoesNotExist($sharded['file']);
        $this->assertFileExists($unrelated['file']);
        $this->assertIsString(ll_tools_public_static_cache_read_prepared_file($unrelated['file'], $unrelated['identity']));
    }

    public function test_failed_inventory_after_legacy_move_is_recovered_when_journal_retry_is_due(): void
    {
        $cache = $this->createCache(19, null, null, true, false);
        $option = ll_tools_public_static_cache_gc_entry_option($cache['key']);
        $target = ll_tools_public_static_cache_file_path($cache['key']);
        $reject = static fn($candidate, $before) => $before;
        add_filter('pre_update_option_' . $option, $reject, 10, 2);
        try { $failed = ll_tools_public_static_cache_gc_run(); }
        finally { remove_filter('pre_update_option_' . $option, $reject, 10); }
        $this->assertTrue($failed['retry']);
        $this->assertFileDoesNotExist($cache['file']);
        $this->assertFileExists($target);
        $this->assertFalse(get_option($option, false));
        $receipts = $this->temporaryOptions($cache['key']);
        $this->assertCount(1, $receipts, 'The move must retain its exact publication receipt.');
        $receipt = get_option($receipts[0]);
        $this->assertIsArray($receipt);
        $this->assertSame($cache['key'], $receipt['key']);
        $this->assertSame($cache['identity'], $receipt['identity']);
        $this->assertGreaterThan(time(), $receipt['retry_at']);

        $this->assertTrue(ll_tools_public_static_cache_gc_run()['complete']);
        $this->assertFalse(get_option($option, false), 'The backoff must prevent immediate recovery attempts.');
        $receipt['retry_at'] = time() - 1;
        update_option($receipts[0], $receipt, false);
        $retry = ll_tools_public_static_cache_gc_run();
        $this->assertFalse($retry['retry']);
        $this->assertIsArray(get_option($option), 'A due publication receipt must restore the shard inventory.');
        $this->assertFalse(get_option($receipts[0], false), 'The worker may retire the receipt after restoring inventory.');
        $this->assertIsString(ll_tools_public_static_cache_read_prepared_file($target, $cache['identity']));
    }

    public function test_expired_html_and_metadata_temp_journals_are_reaped_but_recent_temp_is_retained(): void
    {
        $this->limits = ['temporary_age' => 600];
        $identity = $this->identity(16);
        $key = ll_tools_public_static_cache_key($identity, [], 'en_US');
        $file = ll_tools_public_static_cache_file_path($key);
        $this->assertTrue(wp_mkdir_p(dirname($file)));
        $oldHtml = $file . '.tmp-OldHtml';
        $oldMeta = ll_tools_public_static_cache_meta_file_path($file) . '.tmp-OldMeta';
        $recent = $file . '.tmp-Recent';
        foreach ([$oldHtml, $oldMeta, $recent] as $temporary) {
            $this->ownedFiles[] = $temporary;
            $journal = ll_tools_public_static_cache_gc_record_temporary($temporary, $key, $identity);
            $this->assertNotSame('', $journal);
            file_put_contents($temporary, 'Owned partial output');
            if ($temporary !== $recent) { touch($temporary, time() - 1200); }
        }
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertSame(2, $result['deleted']);
        $this->assertFileDoesNotExist($oldHtml);
        $this->assertFileDoesNotExist($oldMeta);
        $this->assertFileExists($recent);
        $this->assertCount(1, $this->temporaryOptions($key));
    }

    public function test_missing_html_keeps_owned_metadata_cleanup_recoverable(): void
    {
        $cache = $this->createCache(17);
        $meta = ll_tools_public_static_cache_meta_file_path($cache['file']);
        $this->assertTrue(unlink($cache['file']));
        $this->assertFileExists($meta);
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertFalse($result['retry']);
        $this->assertFileDoesNotExist($meta);
        $this->assertFalse(get_option(ll_tools_public_static_cache_gc_entry_option($cache['key']), false));
    }

    public function test_large_inventory_uses_bounded_keyset_pages_even_with_retained_root_prefix(): void
    {
        global $wpdb;
        $this->limits = ['batch' => 7, 'visits' => 8];
        $dir = ll_tools_public_static_cache_dir();
        $this->assertTrue(wp_mkdir_p($dir));
        for ($index = 0; $index < 20; $index++) {
            $foreign = trailingslashit($dir) . 'lifecycle-foreign-' . $index . '.txt';
            $this->ownedFiles[] = $foreign;
            file_put_contents($foreign, 'Foreign files are never adopted or deleted.');
        }
        $values = [];
        $arguments = [];
        for ($index = 1; $index <= 211; $index++) {
            $key = str_pad(dechex($index), 32, '0', STR_PAD_LEFT);
            $values[] = '(%s,%s,%s)';
            array_push($arguments, ll_tools_public_static_cache_gc_entry_option($key), maybe_serialize(['key' => $key]), 'off');
        }
        $this->assertSame(211, $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES " . implode(',', $values),
            $arguments
        )));
        wp_cache_delete('notoptions', 'options');
        $first = ll_tools_public_static_cache_gc_run();
        $this->assertSame(7, $first['selected']);
        $this->assertLessThanOrEqual(8, $first['visited']);
        $state = get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE);
        $cursor = $state['cursor'];
        $this->assertNotSame('', $cursor);
        $second = ll_tools_public_static_cache_gc_run();
        $this->assertSame(7, $second['selected']);
        $this->assertGreaterThan($cursor, get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE)['cursor']);
        foreach ($this->ownedFiles as $file) { $this->assertFileExists($file); }
    }

    public function test_failed_inventory_delete_backs_off_and_does_not_starve_later_rows(): void
    {
        $this->limits = ['obsolete_age' => 600, 'batch' => 1];
        $first = $this->createCache(23, time() - 1200, str_repeat('1', 32));
        $next = $this->createCache(24, time() - 1200, str_repeat('2', 32));
        $option = ll_tools_public_static_cache_gc_entry_option($first['key']);
        ll_tools_public_static_cache_rotate_generation();

        $attempts = 0;
        $reject = static function (string $sql) use (&$attempts, $option): string {
            if (str_starts_with(ltrim($sql), 'DELETE ') && str_contains($sql, $option)) {
                $attempts++;
                return 'SELECT 1 WHERE 1=0';
            }
            return $sql;
        };
        add_filter('query', $reject);
        try {
            $failed_row = ll_tools_public_static_cache_gc_run();
            $this->assertFalse($failed_row['retry'], 'A row failure should not stop the sweep.');
            $this->assertSame(1, $failed_row['row_retries']);
            $this->assertSame(1, $attempts);
            $this->assertSame($option, get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE)['cursor']);
            $entry = get_option($option);
            $this->assertSame(1, $entry['retry_attempt']);
            $this->assertGreaterThan(time(), $entry['retry_at']);

            $next_row = ll_tools_public_static_cache_gc_run();
            $this->assertSame(1, $next_row['deleted']);
            $this->assertFileDoesNotExist($next['file']);
            $this->assertTrue(ll_tools_public_static_cache_gc_run()['complete']);
            $state = get_option(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE);
            $this->assertSame(1, $state['pending_retries']);
            $this->assertSame($entry['retry_at'], $state['next_retry_at']);
            $this->assertSame(1, ll_tools_public_static_cache_gc_run()['row_retries']);
            $this->assertSame(1, $attempts, 'The next sweep must respect the row deadline.');
            $this->assertTrue(ll_tools_public_static_cache_gc_run()['complete']);
        } finally {
            remove_filter('query', $reject);
        }

        $entry['retry_at'] = time() - 1;
        update_option($option, $entry, false);
        $this->assertSame(0, ll_tools_public_static_cache_gc_run()['row_retries']);
        $this->assertFalse(get_option($option, false));
    }

    public function test_malformed_temporary_journal_cannot_starve_later_inventory(): void
    {
        $bad_key = str_repeat('1', 32);
        $bad_option = ll_tools_public_static_cache_gc_entry_option($bad_key) . '_tmp_bad';
        $journal = ['key' => $bad_key, 'temporary' => '../../foreign.html'];
        add_option($bad_option, $journal, '', false);

        $unindexed = $this->createCache(26, null, str_repeat('2', 32), false, false);
        $unindexed_option = ll_tools_public_static_cache_gc_entry_option($unindexed['key']) . '_tmp_bad';
        $invalid_identity = [
            'key' => $unindexed['key'], 'temporary' => basename($unindexed['file']) . '.tmp-BadIdentity',
            'identity' => 'invalid saved identity',
        ];
        add_option($unindexed_option, $invalid_identity, '', false);
        $next = $this->createCache(25, time() - 1200, str_repeat('3', 32));
        ll_tools_public_static_cache_rotate_generation();
        $this->limits = ['obsolete_age' => 600];

        $result = ll_tools_public_static_cache_gc_run();
        $this->assertTrue($result['complete']);
        $this->assertSame(2, $result['row_retries']);
        $this->assertSame($journal, get_option($bad_option));
        $this->assertSame($invalid_identity, get_option($unindexed_option));
        $this->assertFileExists($unindexed['file']);
        $this->assertFileDoesNotExist($next['file']);
    }

    public function test_unreadable_gc_state_still_releases_lease_and_schedules_retry(): void
    {
        global $wpdb;
        $reject = static function (string $sql): string {
            if (str_starts_with(ltrim($sql), 'SELECT ') && str_contains($sql, LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE)) {
                return 'SELECT ll_tools_unavailable_cache_checkpoint';
            }
            return $sql;
        };
        $old_suppression = $wpdb->suppress_errors(true);
        wp_clear_scheduled_hook(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK);
        add_filter('query', $reject);
        try {
            $result = ll_tools_public_static_cache_gc_run();
            $this->assertTrue($result['retry']);
            $this->assertSame(0, $result['selected']);
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_HOOK));
        } finally {
            remove_filter('query', $reject);
            $wpdb->suppress_errors($old_suppression);
        }
        $this->assertTrue(ll_tools_public_static_cache_gc_run()['complete']);
    }

    public function test_failed_gc_checkpoint_cannot_delete_a_later_current_replacement_on_retry(): void
    {
        $cache = $this->createCache(18, time() - 1200);
        ll_tools_public_static_cache_rotate_generation();
        $this->limits = ['obsolete_age' => 600];
        $reject = static fn($candidate, $before) => $before;
        add_filter('pre_update_option_' . LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE, $reject, 10, 2);
        try { $failed = ll_tools_public_static_cache_gc_run(); }
        finally { remove_filter('pre_update_option_' . LL_TOOLS_PUBLIC_STATIC_CACHE_GC_STATE, $reject, 10); }
        $this->assertTrue($failed['retry']);
        $this->assertFileDoesNotExist($cache['file']);
        $replacement = $this->createCache(18, null, $cache['key']);
        $retry = ll_tools_public_static_cache_gc_run();
        $this->assertFalse($retry['retry']);
        $this->assertFileExists($replacement['file']);
        $this->assertIsString(ll_tools_public_static_cache_read_prepared_file($replacement['file'], $replacement['identity']));
    }

    public function test_gc_does_not_clear_security_quarantine_or_delete_its_fallback(): void
    {
        $cache = $this->createCache(20, time() - 1200);
        ll_tools_public_static_cache_rotate_generation();
        $this->limits = ['obsolete_age' => 600];
        $this->assertTrue(ll_tools_public_static_cache_quarantine('lifecycle-security-owner'));
        $marker = ll_tools_public_static_cache_quarantine_file();
        $before = file_get_contents($marker);
        $result = ll_tools_public_static_cache_gc_run();
        $this->assertTrue($result['retry']);
        $this->assertSame(0, $result['deleted']);
        $this->assertFileExists($cache['file']);
        $this->assertTrue(ll_tools_public_static_cache_is_quarantined());
        $this->assertSame($before, file_get_contents($marker));
    }

    public function test_external_shard_junction_refuses_publication_inventory_and_gc_without_touching_target(): void
    {
        if (!function_exists('proc_open')) { $this->markTestSkipped('Node junction fixture requires proc_open.'); }
        $identity = $this->identity(21);
        $key = ll_tools_public_static_cache_key($identity, [], 'en_US');
        $file = ll_tools_public_static_cache_file_path($key);
        $root = ll_tools_public_static_cache_dir();
        $layout = dirname(dirname($file));
        $this->assertTrue(wp_mkdir_p($layout));
        $shard = dirname($file);
        $this->assertStringStartsWith(trailingslashit(wp_normalize_path($root)) . 'files/', wp_normalize_path($shard));
        $external = dirname($root) . '/ll-static-lifecycle-external-' . wp_generate_password(12, false, false);
        $this->assertTrue(wp_mkdir_p($external));
        $this->ownedDirectories[] = $external;
        $externalFile = trailingslashit($external) . basename($file);
        $bytes = 'External owned fixture must survive publication and reclamation unchanged.';
        $externalMeta = ll_tools_public_static_cache_meta_file_path($externalFile);
        $externalPayloads = [
            $externalFile => $bytes,
            $externalMeta => 'External metadata must survive unchanged.',
            $externalFile . '.tmp-ExternalHtml' => 'External HTML temporary output must survive unchanged.',
            $externalMeta . '.tmp-ExternalMeta' => 'External metadata temporary output must survive unchanged.',
        ];
        foreach ($externalPayloads as $externalPath => $externalBytes) {
            $this->ownedFiles[] = $externalPath;
            file_put_contents($externalPath, $externalBytes);
        }
        $backup = $shard . '-lifecycle-backup-' . wp_generate_password(12, false, false);
        $hadShard = is_dir($shard);
        if ($hadShard) { $this->assertTrue(rename($shard, $backup)); }
        $created = false;
        try {
            $created = $this->runNode("require('node:fs').symlinkSync(process.argv[1],process.argv[2],process.platform==='win32'?'junction':'dir');", [$external, $shard]);
            if (!$created) { $this->markTestSkipped('A real directory junction or symlink could not be created.'); }
            clearstatcache(true, $file);
            $this->assertFalse(ll_tools_public_static_cache_gc_path_is_safe($file));
            $this->assertFalse(ll_tools_public_static_cache_gc_prepare_directory($file));
            $this->assertFalse(ll_tools_public_static_cache_gc_record($file, $key, $identity));
            $this->assertSame('', ll_tools_public_static_cache_file_signature($file));
            $this->assertNull(ll_tools_public_static_cache_read_meta($file));
            $this->assertSame('', ll_tools_public_static_cache_gc_record_temporary($file . '.tmp-RefusedJournal', $key, $identity));
            ll_tools_public_static_cache_write_meta($file, $key, $identity);
            foreach ($externalPayloads as $externalPath => $externalBytes) {
                $this->assertSame($externalBytes, file_get_contents($externalPath));
            }
            update_option(ll_tools_public_static_cache_gc_entry_option($key), ['key' => $key, 'identity' => $identity], false);
            $this->publish($identity, $key);
            $result = ll_tools_public_static_cache_gc_run();
            $this->assertSame(0, $result['deleted']);
            $this->assertSame(0, ll_tools_purge_public_static_cache());
            foreach ($externalPayloads as $externalPath => $externalBytes) {
                $this->assertSame($externalBytes, file_get_contents($externalPath));
            }
            $this->assertTrue(ll_tools_public_static_cache_is_quarantined(), 'An unsafe shard is not a successfully completed security purge.');
        } finally {
            if ($created) {
                $this->assertTrue($this->runNode("const fs=require('node:fs');if(!fs.lstatSync(process.argv[1]).isSymbolicLink())throw Error('Refusing non-link cleanup');fs.unlinkSync(process.argv[1]);", [$shard]));
            }
            if ($hadShard) { $this->assertTrue(rename($backup, $shard)); }
        }
    }

    private function identity(int $id): array
    {
        return ['type' => 'wordset_main', 'id' => $id, 'path' => '/lifecycle-' . $id, 'wordset_id' => $id];
    }

    private function createCache(int $id, ?int $mtime = null, ?string $key = null, bool $legacy = false, bool $inventory = true): array
    {
        $identity = $this->identity($id);
        $key = $key ?? ll_tools_public_static_cache_key($identity, [], 'en_US');
        $file = $legacy ? ll_tools_public_static_cache_legacy_file_path($key) : ll_tools_public_static_cache_file_path($key);
        $this->assertTrue(wp_mkdir_p(dirname($file)));
        $this->ownedFiles[] = $file;
        $this->ownedFiles[] = ll_tools_public_static_cache_meta_file_path($file);
        file_put_contents($file, ll_tools_public_static_cache_prepare_html_for_storage(
            '<!doctype html><html><body>' . str_repeat('Lifecycle retained body ', 40) . '</body></html>',
            $identity,
            ll_tools_public_static_cache_generation($identity)
        ));
        if ($mtime !== null) { touch($file, $mtime); }
        ll_tools_public_static_cache_write_meta($file, $key, $identity);
        if ($inventory) { $this->assertTrue(ll_tools_public_static_cache_gc_record($file, $key, $identity)); }
        return compact('key', 'file', 'identity');
    }

    private function publish(array $identity, string $key): string
    {
        $file = ll_tools_public_static_cache_file_path($key);
        $this->ownedFiles[] = $file;
        $this->ownedFiles[] = ll_tools_public_static_cache_meta_file_path($file);
        $this->assertTrue(ll_tools_public_static_cache_acquire_rebuild_lock($key));
        $bufferLevel = ob_get_level();
        $GLOBALS['ll_tools_public_static_cache_request'] = [
            'active' => true, 'key' => $key, 'file' => $file, 'identity' => $identity,
            'lock_acquired' => true, 'lease_value' => ll_tools_public_static_cache_get_rebuild_lease_value($key),
            'generation' => ll_tools_public_static_cache_generation($identity),
            'original_file_signature' => ll_tools_public_static_cache_file_signature($file), 'buffer_level' => $bufferLevel,
        ];
        ob_start();
        try {
            echo '<!doctype html><html><body>' . str_repeat('Lifecycle captured body ', 40) . '</body></html>';
            ll_tools_store_public_static_cache();
        } finally {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            unset($GLOBALS['ll_tools_public_static_cache_request']);
        }
        return $file;
    }

    private function temporaryOptions(string $key): array
    {
        global $wpdb;
        return (array) $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
            $wpdb->esc_like(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_ENTRY_PREFIX . $key . '_tmp_') . '%'
        ));
    }

    private function clearInventory(): void
    {
        global $wpdb;
        $options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(LL_TOOLS_PUBLIC_STATIC_CACHE_GC_ENTRY_PREFIX) . '%'
        ));
        foreach ((array) $options as $option) { delete_option($option); }
    }

    private function runNode(string $script, array $arguments): bool
    {
        $pipes = [];
        $process = @proc_open(array_merge(['node', '-e', $script], $arguments), [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) { return false; }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        return proc_close($process) === 0;
    }
}
