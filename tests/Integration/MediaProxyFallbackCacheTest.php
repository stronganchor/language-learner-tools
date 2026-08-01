<?php
declare(strict_types=1);

final class MediaProxyFallbackCacheTest extends LL_Tools_TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /**
     * @template T
     * @param callable(string):T $callback
     * @return T
     */
    private function withIsolatedCacheRoot(callable $callback)
    {
        $uploads = wp_get_upload_dir();
        $root = trailingslashit((string) $uploads['basedir'])
            . 'll-tools-media-proxy-test-'
            . wp_generate_password(12, false, false);
        $filter = static function ($default, string $uploadsRoot) use ($root): string {
            return $root;
        };
        add_filter('ll_tools_media_proxy_fallback_cache_root', $filter, 10, 2);

        try {
            $this->assertTrue(wp_mkdir_p($root));
            return $callback(wp_normalize_path($root));
        } finally {
            remove_filter('ll_tools_media_proxy_fallback_cache_root', $filter, 10);
            $this->deleteTestDirectory($root);
        }
    }

    private function deleteTestDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                $this->deleteTestDirectory($entry->getPathname());
            } elseif ($entry->isFile()) {
                wp_delete_file($entry->getPathname());
            }
        }
        @rmdir($directory);
    }

    private function writePng(string $path, ?int $mtime = null): void
    {
        $this->assertSame(
            68,
            file_put_contents($path, base64_decode(self::PNG_1X1, true))
        );
        if ($mtime !== null) {
            $this->assertTrue(touch($path, $mtime));
        }
        clearstatcache(true, $path);
    }

    public function test_cache_context_is_scoped_and_rotates_with_remote_url(): void
    {
        $first = ll_tools_media_proxy_fallback_cache_context(123, 'thumbnail', 'https://example.com/a.png');
        $second = ll_tools_media_proxy_fallback_cache_context(123, 'thumbnail', 'https://example.com/b.png');

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $uploads = wp_get_upload_dir();
        $this->assertStringStartsWith(
            wp_normalize_path((string) $uploads['basedir']) . '/',
            wp_normalize_path((string) $first['path'])
        );
        $this->assertSame((string) $first['directory'], (string) $second['directory']);
        $this->assertNotSame((string) $first['path'], (string) $second['path']);
    }

    public function test_cache_root_filter_cannot_escape_the_uploads_directory(): void
    {
        $uploads = wp_get_upload_dir();
        $outsideRoot = trailingslashit(dirname((string) $uploads['basedir']))
            . 'll-tools-media-proxy-outside';
        $filter = static function () use ($outsideRoot): string {
            return $outsideRoot;
        };
        add_filter('ll_tools_media_proxy_fallback_cache_root', $filter, 10, 0);

        try {
            $root = ll_tools_media_proxy_fallback_cache_root();
            $this->assertWPError($root);
            $this->assertSame('media_proxy_cache_root', $root->get_error_code());
        } finally {
            remove_filter('ll_tools_media_proxy_fallback_cache_root', $filter, 10);
        }
    }

    public function test_cached_file_validation_requires_a_bounded_raster_image(): void
    {
        $temporary = wp_tempnam('ll-tools-media-proxy-test');
        $this->assertIsString($temporary);
        $this->assertNotSame('', $temporary);

        try {
            file_put_contents($temporary, 'not an image');
            $mime = '';
            $this->assertFalse(ll_tools_media_proxy_validate_cached_fallback_file($temporary, 1024, $mime));

            file_put_contents($temporary, base64_decode(self::PNG_1X1, true));
            $this->assertTrue(ll_tools_media_proxy_validate_cached_fallback_file($temporary, 1024, $mime));
            $this->assertSame('image/png', $mime);
            $this->assertFalse(ll_tools_media_proxy_validate_cached_fallback_file($temporary, 1, $mime));
        } finally {
            wp_delete_file($temporary);
        }
    }

    public function test_cache_lock_is_single_owner_and_release_is_token_safe(): void
    {
        $cache_key = 'media-proxy-test-' . wp_generate_password(12, false, false);
        $lease = ll_tools_media_proxy_acquire_fallback_cache_lock($cache_key, 20);
        $this->assertIsArray($lease);

        try {
            $this->assertNull(ll_tools_media_proxy_acquire_fallback_cache_lock($cache_key, 20));
            ll_tools_media_proxy_release_fallback_cache_lock([
                'key' => (string) $lease['key'],
                'value' => 'not-the-owner',
            ]);
            $this->assertNull(ll_tools_media_proxy_acquire_fallback_cache_lock($cache_key, 20));
        } finally {
            ll_tools_media_proxy_release_fallback_cache_lock($lease);
        }

        $replacement = ll_tools_media_proxy_acquire_fallback_cache_lock($cache_key, 20);
        $this->assertIsArray($replacement);
        ll_tools_media_proxy_release_fallback_cache_lock($replacement);
    }

    public function test_remote_download_streams_to_a_valid_bounded_cache_file(): void
    {
        $this->withIsolatedCacheRoot(function (): void {
            $url = 'https://example.com/remote-fallback.png';
            $context = ll_tools_media_proxy_fallback_cache_context(
                987654,
                'thumbnail',
                $url . '?' . wp_generate_password(8, false, false)
            );
            $this->assertIsArray($context);

            $preempt = static function ($preempt, array $args, string $request_url) use ($url) {
                if (strpos($request_url, $url) !== 0) {
                    return $preempt;
                }
                file_put_contents((string) ($args['filename'] ?? ''), base64_decode(self::PNG_1X1, true));
                return [
                    'headers' => [
                        'content-type' => 'image/png',
                        'content-length' => '68',
                    ],
                    'body' => '',
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => (string) ($args['filename'] ?? ''),
                ];
            };
            add_filter('pre_http_request', $preempt, 10, 3);

            try {
                $downloaded = ll_tools_media_proxy_download_fallback_to_cache(
                    $url,
                    $context,
                    1024
                );
                $this->assertIsArray($downloaded);
                $this->assertSame('image/png', (string) ($downloaded['mime'] ?? ''));
                $this->assertFileExists((string) ($downloaded['path'] ?? ''));
                $mime = '';
                $this->assertTrue(ll_tools_media_proxy_validate_cached_fallback_file(
                    (string) $downloaded['path'],
                    1024,
                    $mime
                ));
            } finally {
                remove_filter('pre_http_request', $preempt, 10);
            }
        });
    }

    public function test_cold_cache_contention_wait_serves_the_winners_file(): void
    {
        $this->withIsolatedCacheRoot(function (): void {
            $context = ll_tools_media_proxy_fallback_cache_context(
                765401,
                'thumbnail',
                'https://example.com/contention.png'
            );
            $this->assertIsArray($context);
            $this->assertTrue(wp_mkdir_p((string) $context['directory']));

            $poll = function (string $path, int $attempt): void {
                if ($attempt === 1) {
                    $this->writePng($path);
                }
            };
            add_action('ll_tools_media_proxy_fallback_cache_wait_poll', $poll, 10, 2);

            try {
                $started = microtime(true);
                $waited = ll_tools_media_proxy_wait_for_fallback_cache_file(
                    (string) $context['path'],
                    1024,
                    150,
                    10
                );
                $this->assertLessThan(0.5, microtime(true) - $started);
                $this->assertIsArray($waited);
                $this->assertSame('image/png', (string) ($waited['mime'] ?? ''));
                $this->assertSame((string) $context['path'], (string) ($waited['path'] ?? ''));
            } finally {
                remove_action('ll_tools_media_proxy_fallback_cache_wait_poll', $poll, 10);
            }
        });
    }

    public function test_dead_origin_is_negatively_cached_before_a_second_refresh(): void
    {
        $this->withIsolatedCacheRoot(function (): void {
            $url = 'https://example.com/dead-origin.png';
            $context = ll_tools_media_proxy_fallback_cache_context(765402, 'medium', $url);
            $this->assertIsArray($context);
            $requestCount = 0;
            $preempt = static function ($preempt, array $args, string $requestUrl) use (&$requestCount, $url) {
                if ($requestUrl !== $url) {
                    return $preempt;
                }
                $requestCount++;
                return new WP_Error('test_dead_origin', 'Origin unavailable.');
            };
            add_filter('pre_http_request', $preempt, 10, 3);

            try {
                $first = ll_tools_media_proxy_refresh_fallback_cache($url, $context, 1024);
                $second = ll_tools_media_proxy_refresh_fallback_cache($url, $context, 1024);

                $this->assertWPError($first);
                $this->assertSame('media_proxy_remote_failed', $first->get_error_code());
                $this->assertWPError($second);
                $this->assertSame('media_proxy_refresh_backoff', $second->get_error_code());
                $this->assertSame(1, $requestCount);
                $this->assertGreaterThan(0, ll_tools_media_proxy_fallback_backoff_remaining($context));
                $this->assertFileExists(ll_tools_media_proxy_fallback_backoff_path($context));
            } finally {
                remove_filter('pre_http_request', $preempt, 10);
                ll_tools_media_proxy_clear_fallback_backoff($context);
            }
        });
    }

    public function test_stale_file_has_an_absolute_serving_limit_and_is_pruned(): void
    {
        $this->withIsolatedCacheRoot(function (): void {
            $now = time();
            $context = ll_tools_media_proxy_fallback_cache_context(
                765403,
                'large',
                'https://example.com/stale.png'
            );
            $this->assertIsArray($context);
            $this->assertTrue(wp_mkdir_p((string) $context['directory']));
            $this->writePng((string) $context['path'], $now - 200);

            $state = ll_tools_media_proxy_cached_fallback_state(
                (string) $context['path'],
                1024,
                60,
                120,
                $now
            );
            $this->assertTrue($state['valid']);
            $this->assertFalse($state['fresh']);
            $this->assertFalse($state['servable']);

            $pruned = ll_tools_media_proxy_prune_fallback_cache_bucket(
                (string) $context['directory'],
                [
                    'now' => $now,
                    'max_stale_age' => 120,
                    'max_files' => 4,
                    'max_bytes' => 1024,
                    'scan_limit' => 10,
                    'delete_limit' => 10,
                ]
            );
            $this->assertSame(1, $pruned['deleted_file_count']);
            $this->assertFileDoesNotExist((string) $context['path']);
        });
    }

    public function test_client_max_age_decays_with_server_side_freshness(): void
    {
        $this->assertSame(60, ll_tools_media_proxy_fallback_client_max_age(60, 0));
        $this->assertSame(1, ll_tools_media_proxy_fallback_client_max_age(60, 59));
        $this->assertSame(0, ll_tools_media_proxy_fallback_client_max_age(60, 60));
        $this->assertSame(0, ll_tools_media_proxy_fallback_client_max_age(60, 61));
        $this->assertSame(60, ll_tools_media_proxy_fallback_client_max_age(60, -1));
    }

    public function test_bucket_pruning_enforces_file_and_byte_storage_limits(): void
    {
        $this->withIsolatedCacheRoot(function (): void {
            $now = time();
            $context = ll_tools_media_proxy_fallback_cache_context(
                765404,
                'thumbnail',
                'https://example.com/current.png'
            );
            $this->assertIsArray($context);
            $directory = (string) $context['directory'];
            $this->assertTrue(wp_mkdir_p($directory));

            $paths = [];
            for ($index = 0; $index < 5; $index++) {
                $path = trailingslashit($directory) . hash('sha256', 'sibling-' . $index) . '.img';
                $this->writePng($path, $now - (50 - $index));
                $paths[] = $path;
            }

            $pruned = ll_tools_media_proxy_prune_fallback_cache_bucket(
                $directory,
                [
                    'now' => $now,
                    'keep_path' => $paths[4],
                    'max_stale_age' => 1000,
                    'max_files' => 2,
                    'max_bytes' => 136,
                    'scan_limit' => 10,
                    'delete_limit' => 10,
                ]
            );

            $remaining = glob(trailingslashit($directory) . '*.img');
            $this->assertCount(2, is_array($remaining) ? $remaining : []);
            $this->assertFileExists($paths[4]);
            $this->assertSame(3, $pruned['deleted_file_count']);
            $this->assertSame(2, $pruned['remaining_image_count']);
            $this->assertSame(136, $pruned['remaining_image_bytes']);
        });
    }

    public function test_delete_attachment_and_global_maintenance_remove_guarded_buckets(): void
    {
        $this->withIsolatedCacheRoot(function (string $root): void {
            $attachmentId = self::factory()->post->create([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image/png',
            ]);
            $deleteContext = ll_tools_media_proxy_fallback_cache_context(
                $attachmentId,
                'thumbnail',
                'https://example.com/delete.png'
            );
            $this->assertIsArray($deleteContext);
            $this->assertTrue(wp_mkdir_p((string) $deleteContext['directory']));
            $this->writePng((string) $deleteContext['path']);

            $deleted = ll_tools_media_proxy_delete_attachment_cache($attachmentId);
            $this->assertSame(1, $deleted['matched_bucket_count']);
            $this->assertSame(1, $deleted['deleted_file_count']);
            $this->assertDirectoryDoesNotExist((string) $deleteContext['directory']);
            $this->assertNotFalse(has_action('delete_attachment', 'll_tools_media_proxy_delete_attachment_cache'));

            $orphanContext = ll_tools_media_proxy_fallback_cache_context(
                999999991,
                'medium',
                'https://example.com/orphan.png'
            );
            $liveContext = ll_tools_media_proxy_fallback_cache_context(
                $attachmentId,
                'medium',
                'https://example.com/live-stale.png'
            );
            $this->assertIsArray($orphanContext);
            $this->assertIsArray($liveContext);
            $this->assertTrue(wp_mkdir_p((string) $orphanContext['directory']));
            $this->assertTrue(wp_mkdir_p((string) $liveContext['directory']));
            $this->writePng((string) $orphanContext['path']);
            $this->writePng(
                (string) $liveContext['path'],
                time() - ll_tools_media_proxy_fallback_max_stale_age() - 60
            );

            $maintenance = [
                'processed_bucket_count' => 0,
                'orphan_bucket_count' => 0,
                'deleted_file_count' => 0,
            ];
            $shardIndexes = array_unique([
                (int) $orphanContext['shard_index'],
                (int) $liveContext['shard_index'],
            ]);
            foreach ($shardIndexes as $shardIndex) {
                $batch = ll_tools_media_proxy_run_cache_maintenance_batch(
                    $root,
                    ll_tools_media_proxy_cache_maintenance_cursor($shardIndex),
                    [
                        'scan_limit' => 20,
                        'bucket_limit' => 10,
                        'shard_probe_limit' => 1,
                        'runtime' => 1.0,
                    ]
                );
                $maintenance['processed_bucket_count'] += $batch['processed_bucket_count'];
                $maintenance['orphan_bucket_count'] += $batch['orphan_bucket_count'];
                $maintenance['deleted_file_count'] += $batch['deleted_file_count'];
            }
            $this->assertSame(2, $maintenance['processed_bucket_count']);
            $this->assertSame(1, $maintenance['orphan_bucket_count']);
            $this->assertSame(2, $maintenance['deleted_file_count']);
            $this->assertDirectoryDoesNotExist((string) $orphanContext['directory']);
            $this->assertDirectoryDoesNotExist((string) $liveContext['directory']);
        });
    }

    public function test_global_maintenance_honors_bucket_limit_and_advances_after_deletion(): void
    {
        $this->withIsolatedCacheRoot(function (string $root): void {
            $idsByShard = [];
            $attachmentIds = [];
            for ($attachmentId = 999990000; $attachmentId < 1000040000; $attachmentId++) {
                $shard = substr(hash('sha256', (string) $attachmentId), 0, 3);
                $idsByShard[$shard][] = $attachmentId;
                if (count($idsByShard[$shard]) === 3) {
                    $attachmentIds = $idsByShard[$shard];
                    break;
                }
            }
            $this->assertNotEmpty($attachmentIds);

            $directories = [];
            $shardIndex = 0;
            foreach ($attachmentIds as $attachmentId) {
                $context = ll_tools_media_proxy_fallback_cache_context(
                    $attachmentId,
                    'thumbnail',
                    'https://example.com/orphan-' . $attachmentId . '.png'
                );
                $this->assertIsArray($context);
                $this->assertTrue(wp_mkdir_p((string) $context['directory']));
                $this->writePng((string) $context['path']);
                $directories[] = (string) $context['directory'];
                $shardIndex = (int) $context['shard_index'];
            }

            $offset = ll_tools_media_proxy_cache_maintenance_cursor($shardIndex);
            $processed = 0;
            for ($iteration = 0; $iteration < 8; $iteration++) {
                $batch = ll_tools_media_proxy_run_cache_maintenance_batch(
                    $root,
                    $offset,
                    [
                        'scan_limit' => 10,
                        'bucket_limit' => 1,
                        'shard_probe_limit' => 1,
                        'runtime' => 1.0,
                    ]
                );
                $this->assertLessThanOrEqual(1, $batch['processed_bucket_count']);
                $processed += $batch['orphan_bucket_count'];
                $offset = $batch['next_offset'];

                $remaining = array_filter($directories, 'is_dir');
                if ($remaining === []) {
                    break;
                }
            }

            $this->assertSame(3, $processed);
            foreach ($directories as $directory) {
                $this->assertDirectoryDoesNotExist($directory);
            }
        });
    }

    public function test_global_maintenance_stops_after_the_final_shard_without_wrapping(): void
    {
        $this->withIsolatedCacheRoot(function (string $root): void {
            $maintenance = ll_tools_media_proxy_run_cache_maintenance_batch(
                $root,
                ll_tools_media_proxy_cache_maintenance_cursor(0xfffe),
                [
                    'scan_limit' => 10,
                    'bucket_limit' => 10,
                    'shard_probe_limit' => 4,
                    'runtime' => 1.0,
                ]
            );

            $this->assertSame(2, $maintenance['shard_probe_count']);
            $this->assertSame(0, $maintenance['next_offset']);
        });
    }

    public function test_media_cache_schedule_is_single_and_deactivation_cleanup_clears_state(): void
    {
        wp_clear_scheduled_hook(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK);
        wp_clear_scheduled_hook(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK);
        delete_option(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION);

        try {
            ll_tools_schedule_media_proxy_cache_maintenance();
            $firstTimestamp = wp_next_scheduled(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK);
            $this->assertNotFalse($firstTimestamp);
            ll_tools_schedule_media_proxy_cache_maintenance();
            $this->assertSame(
                $firstTimestamp,
                wp_next_scheduled(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK)
            );
            $this->assertNotFalse(has_action(
                LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK,
                'll_tools_run_media_proxy_cache_maintenance'
            ));

            $this->withIsolatedCacheRoot(function (): void {
                $maintenance = ll_tools_run_media_proxy_cache_maintenance();
                $this->assertGreaterThan(0, $maintenance['next_offset']);
                $this->assertNotFalse(wp_next_scheduled(
                    LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK
                ));
            });
            ll_tools_clear_media_proxy_cache_maintenance_schedule();
            $this->assertFalse(wp_next_scheduled(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK));
            $this->assertFalse(wp_next_scheduled(
                LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK
            ));
            $this->assertFalse(get_option(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION, false));
        } finally {
            wp_clear_scheduled_hook(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK);
            wp_clear_scheduled_hook(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK);
            delete_option(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION);
        }
    }
}
