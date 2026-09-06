<?php
declare(strict_types=1);

final class ImportHistoryUndoTest extends LL_Tools_TestCase
{
    public function test_recent_history_returns_today_and_yesterday_when_available(): void
    {
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $startToday = $now->setTime(0, 0, 0);

        update_option(ll_tools_import_history_option_name(), [
            [
                'id' => 'recent-today',
                'finished_at' => $startToday->getTimestamp() + 3600,
            ],
            [
                'id' => 'recent-yesterday',
                'finished_at' => $startToday->modify('-1 day')->getTimestamp() + 7200,
            ],
            [
                'id' => 'too-old',
                'finished_at' => $startToday->modify('-2 day')->getTimestamp() + 7200,
            ],
        ], false);

        $recent = ll_tools_import_get_recent_history_entries();
        $ids = array_values(array_map(static function (array $entry): string {
            return (string) ($entry['id'] ?? '');
        }, $recent));

        $this->assertContains('recent-today', $ids);
        $this->assertContains('recent-yesterday', $ids);
        $this->assertNotContains('too-old', $ids);
    }

    public function test_recent_history_falls_back_to_latest_entry_when_no_recent_imports_exist(): void
    {
        $tz = wp_timezone();
        $now = new DateTimeImmutable('now', $tz);
        $startToday = $now->setTime(0, 0, 0);

        update_option(ll_tools_import_history_option_name(), [
            [
                'id' => 'latest-older',
                'finished_at' => $startToday->modify('-3 day')->getTimestamp() + 3600,
            ],
            [
                'id' => 'older-still',
                'finished_at' => $startToday->modify('-6 day')->getTimestamp() + 7200,
            ],
        ], false);

        $recent = ll_tools_import_get_recent_history_entries();
        $ids = array_values(array_map(static function (array $entry): string {
            return (string) ($entry['id'] ?? '');
        }, $recent));

        $this->assertSame(['latest-older'], $ids);
    }

    public function test_undo_import_entry_deletes_created_posts_terms_and_audio_file(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $category_insert = wp_insert_term('Undo Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($category_insert));
        $category_id = (int) $category_insert['term_id'];

        $wordset_insert = wp_insert_term('Undo Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset_insert));
        $wordset_id = (int) $wordset_insert['term_id'];

        $word_id = wp_insert_post([
            'post_type' => 'words',
            'post_title' => 'Undo Word',
            'post_status' => 'draft',
        ]);
        $this->assertGreaterThan(0, $word_id);

        $word_image_id = wp_insert_post([
            'post_type' => 'word_images',
            'post_title' => 'Undo Image',
            'post_status' => 'draft',
        ]);
        $this->assertGreaterThan(0, $word_image_id);

        $word_audio_id = wp_insert_post([
            'post_type' => 'word_audio',
            'post_title' => 'Undo Audio',
            'post_status' => 'draft',
            'post_parent' => $word_id,
        ]);
        $this->assertGreaterThan(0, $word_audio_id);

        $upload_dir = wp_upload_dir();
        $audio_file = trailingslashit((string) $upload_dir['path']) . 'undo-audio-' . wp_generate_password(6, false, false) . '.mp3';
        if (!is_dir((string) $upload_dir['path'])) {
            wp_mkdir_p((string) $upload_dir['path']);
        }
        file_put_contents($audio_file, 'undo-audio');
        $this->assertFileExists($audio_file);

        $undo_result = ll_tools_undo_import_entry([
            'undo' => [
                'category_term_ids' => [$category_id],
                'wordset_term_ids' => [$wordset_id],
                'word_image_post_ids' => [$word_image_id],
                'word_post_ids' => [$word_id],
                'word_audio_post_ids' => [$word_audio_id],
                'attachment_ids' => [],
                'audio_paths' => [$audio_file],
            ],
        ]);

        $this->assertTrue((bool) ($undo_result['ok'] ?? false), implode(' | ', (array) ($undo_result['errors'] ?? [])));
        $this->assertNull(get_post($word_id));
        $this->assertNull(get_post($word_image_id));
        $this->assertNull(get_post($word_audio_id));
        $this->assertEmpty(term_exists($category_id, 'word-category'));
        $this->assertEmpty(term_exists($wordset_id, 'wordset'));
        $this->assertFileDoesNotExist($audio_file);

        $stats = isset($undo_result['stats']) && is_array($undo_result['stats']) ? $undo_result['stats'] : [];
        $this->assertSame(1, (int) ($stats['words_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['word_images_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['word_audio_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['categories_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['wordsets_deleted'] ?? 0));
        $this->assertSame(1, (int) ($stats['audio_files_deleted'] ?? 0));
    }

    public function test_wordset_undo_retries_a_silent_partial_term_delete(): void
    {
        global $wpdb;

        $insert = wp_insert_term('Retry Partial Undo Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($insert);
        $wordsetId = (int) $insert['term_id'];
        update_option('ll_vocab_lesson_wordsets', [$wordsetId], false);
        $termsDeleteWasBlocked = false;
        $blockTermsDelete = static function (string $query) use ($wpdb, $wordsetId, &$termsDeleteWasBlocked): string {
            if (
                str_starts_with(ltrim($query), 'DELETE FROM')
                && str_contains($query, (string) $wpdb->terms)
                && preg_match('/`?term_id`?\s*=\s*[\'\"]?' . preg_quote((string) $wordsetId, '/') . '\b/i', $query)
            ) {
                $termsDeleteWasBlocked = true;
                return 'SELECT 1';
            }
            return $query;
        };
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'wordset_term_ids' => [$wordsetId],
        ])];
        add_filter('query', $blockTermsDelete);
        try {
            $first = ll_tools_undo_import_entry($entry);
        } finally {
            remove_filter('query', $blockTermsDelete);
        }

        $this->assertTrue($termsDeleteWasBlocked);
        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertNotEmpty((array) ($first['errors'] ?? []));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
            $wordsetId
        )));

        $second = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($second['ok'] ?? false), implode(' | ', (array) ($second['errors'] ?? [])));
        $this->assertSame(1, (int) ($second['stats']['wordsets_deleted'] ?? 0));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
            $wordsetId
        )));
        $this->assertNotContains($wordsetId, ll_tools_get_vocab_lesson_wordset_ids());
    }

    public function test_post_undo_retries_when_core_reports_success_but_the_post_row_remains(): void
    {
        global $wpdb;

        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Retry Silent Post Undo',
        ]);
        update_post_meta($wordId, 'word_translation', 'Retry me');
        $postDeleteWasBlocked = false;
        $blockPostDelete = static function (string $query) use ($wpdb, $wordId, &$postDeleteWasBlocked): string {
            if (
                str_starts_with(ltrim($query), 'DELETE FROM')
                && str_contains($query, (string) $wpdb->posts)
                && str_contains($query, (string) $wordId)
            ) {
                $postDeleteWasBlocked = true;
                return 'SELECT 1';
            }
            return $query;
        };
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'word_post_ids' => [$wordId],
        ])];
        add_filter('query', $blockPostDelete);
        try {
            $first = ll_tools_undo_import_entry($entry);
        } finally {
            remove_filter('query', $blockPostDelete);
        }

        $this->assertTrue($postDeleteWasBlocked);
        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertSame(0, (int) ($first['stats']['words_deleted'] ?? 0));
        $this->assertInstanceOf(WP_Post::class, get_post($wordId));

        $second = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($second['ok'] ?? false), implode(' | ', (array) ($second['errors'] ?? [])));
        $this->assertSame(1, (int) ($second['stats']['words_deleted'] ?? 0));
        $this->assertNull(get_post($wordId));
    }

    public function test_post_undo_converts_a_throwing_delete_hook_into_a_retryable_error(): void
    {
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Retry Throwing Post Undo',
        ]);
        $deleteHookWasCalled = false;
        $throwDuringDelete = static function (int $postId) use ($wordId, &$deleteHookWasCalled): void {
            if ($postId === $wordId) {
                $deleteHookWasCalled = true;
                throw new RuntimeException('Synthetic Undo post-delete failure.');
            }
        };
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'word_post_ids' => [$wordId],
        ])];
        add_action('before_delete_post', $throwDuringDelete, 10, 1);
        try {
            $first = ll_tools_undo_import_entry($entry);
        } finally {
            remove_action('before_delete_post', $throwDuringDelete, 10);
        }

        $this->assertTrue($deleteHookWasCalled);
        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertSame(0, (int) ($first['stats']['words_deleted'] ?? 0));
        $this->assertInstanceOf(WP_Post::class, get_post($wordId));

        $second = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($second['ok'] ?? false), implode(' | ', (array) ($second['errors'] ?? [])));
        $this->assertNull(get_post($wordId));
    }

    public function test_attachment_undo_rejects_a_caller_owned_transaction_before_mutation(): void
    {
        $upload = wp_upload_bits(
            'undo-attachment-' . wp_generate_password(6, false, false) . '.jpg',
            null,
            'attachment-data'
        );
        $this->assertSame('', (string) ($upload['error'] ?? ''));
        $filePath = (string) $upload['file'];
        $attachmentId = wp_insert_attachment([
            'post_title' => 'Undo attachment file retry',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
        ], $filePath);
        $this->assertGreaterThan(0, $attachmentId);
        update_attached_file($attachmentId, $filePath);
        wp_update_attachment_metadata($attachmentId, [
            'file' => _wp_relative_upload_path($filePath),
        ]);
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'attachment_ids' => [$attachmentId],
        ])];
        $first = ll_tools_undo_import_entry($entry);
        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertSame(0, (int) ($first['stats']['attachments_deleted'] ?? 0));
        $this->assertInstanceOf(WP_Post::class, get_post($attachmentId));
        $this->assertFileExists($filePath);
        $this->assertSame([], ll_tools_import_read_pending_attachment_file_cleanup($attachmentId));
        wp_delete_attachment($attachmentId, true);
    }

    public function test_pending_attachment_file_cleanup_evidence_survives_unlink_failure(): void
    {
        $upload = wp_upload_bits(
            'undo-pending-file-' . wp_generate_password(6, false, false) . '.jpg',
            null,
            'pending-file-data'
        );
        $this->assertSame('', (string) ($upload['error'] ?? ''));
        $filePath = wp_normalize_path((string) $upload['file']);
        $attachmentId = 1000000 + wp_rand(1, 1000000);
        $this->assertTrue(ll_tools_import_write_pending_attachment_file_cleanup($attachmentId, [$filePath]));

        $blockFileDelete = static function (bool $allowed, string $candidate) use ($filePath): bool {
            return wp_normalize_path($candidate) === wp_normalize_path($filePath) ? false : $allowed;
        };
        add_filter('ll_tools_import_undo_file_delete_allowed', $blockFileDelete, 10, 2);
        try {
            $this->assertFalse(ll_tools_import_finish_pending_attachment_file_cleanup(
                $attachmentId,
                [$filePath]
            ));
        } finally {
            remove_filter('ll_tools_import_undo_file_delete_allowed', $blockFileDelete, 10);
        }
        $this->assertFileExists($filePath);
        $this->assertSame(
            [$filePath],
            ll_tools_import_read_pending_attachment_file_cleanup($attachmentId)
        );

        $this->assertTrue(ll_tools_import_finish_pending_attachment_file_cleanup(
            $attachmentId,
            [$filePath]
        ));
        $this->assertFileDoesNotExist($filePath);
        $this->assertSame([], ll_tools_import_read_pending_attachment_file_cleanup($attachmentId));
    }

    public function test_top_level_attachment_undo_commits_a_worker_before_file_cleanup(): void
    {
        global $wpdb;

        // Attachment Undo deliberately rejects the savepoint owned by the test
        // framework. Temporarily finish it so this test exercises the real
        // top-level commit boundary, then restore a transaction for teardown.
        $this->assertNotFalse($wpdb->query('COMMIT'));
        $this->assertNotFalse($wpdb->query('SET autocommit = 1'));
        $attachmentId = 0;
        $filePath = '';
        $attachmentFilePath = '';
        $filterActive = false;
        $scheduleObserverActive = false;
        $deleteObserverActive = false;
        try {
            $upload = wp_upload_bits(
                'undo-crash-durable-' . wp_generate_password(6, false, false) . '.jpg',
                null,
                'crash-durable-attachment-data'
            );
            $this->assertSame('', (string) ($upload['error'] ?? ''));
            $attachmentFilePath = wp_normalize_path((string) ($upload['file'] ?? ''));
            $attachmentId = (int) wp_insert_attachment([
                'post_title' => 'Undo crash durable attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image/jpeg',
            ], $attachmentFilePath);
            $this->assertGreaterThan(0, $attachmentId);
            update_attached_file($attachmentId, $attachmentFilePath);
            wp_update_attachment_metadata($attachmentId, [
                'file' => _wp_relative_upload_path($attachmentFilePath),
            ]);

            // Seed a previously journaled companion so the post-commit worker
            // path remains testable even when core successfully removes the
            // attachment's primary file on this platform.
            $uploadDir = wp_upload_dir();
            $filePath = wp_normalize_path(
                trailingslashit((string) $uploadDir['path'])
                . 'undo-journal-companion-' . wp_generate_password(6, false, false) . '.jpg'
            );
            $this->assertNotFalse(file_put_contents($filePath, 'journal-companion-data'));
            $this->assertTrue(ll_tools_import_write_pending_attachment_file_cleanup(
                $attachmentId,
                [$filePath]
            ));
            $this->assertFalse(ll_tools_database_transaction_is_active());
            $this->assertTrue(ll_tools_import_undo_tables_are_transactional());
            $this->assertContains(
                $attachmentFilePath,
                (array) ll_tools_import_attachment_file_paths_for_undo($attachmentId)
            );

            $blockedPaths = [];
            $blockFileDelete = static function (bool $allowed, string $candidate) use (&$blockedPaths): bool {
                $blockedPaths[] = wp_normalize_path($candidate);
                return false;
            };
            add_filter('ll_tools_import_undo_file_delete_allowed', $blockFileDelete, 10, 2);
            $filterActive = true;
            $scheduleAttempts = 0;
            $observeSchedule = static function ($pre, $event, $wpError) use (&$scheduleAttempts) {
                if (is_object($event) && (string) ($event->hook ?? '') === 'll_tools_import_attachment_file_cleanup') {
                    $scheduleAttempts++;
                }
                return $pre;
            };
            add_filter('pre_schedule_event', $observeSchedule, 10, 3);
            $scheduleObserverActive = true;
            $deleteAttempts = 0;
            $observeDelete = static function (int $deletedId) use ($attachmentId, &$deleteAttempts): void {
                if ($deletedId === $attachmentId) {
                    $deleteAttempts++;
                }
            };
            add_action('delete_attachment', $observeDelete, 10, 1);
            $deleteObserverActive = true;
            $this->assertContains(
                $filePath,
                (array) ll_tools_import_read_pending_attachment_file_cleanup($attachmentId)
            );
            $this->assertSame('failed', ll_tools_import_delete_audio_file_if_safe($filePath));
            $this->assertFileExists($filePath);
            $blockedPaths = [];
            $status = ll_tools_import_delete_tracked_post_for_undo($attachmentId, 'attachment', true);

            $this->assertSame(
                'failed',
                $status,
                'delete attempts: ' . $deleteAttempts . '; cleanup schedule attempts: ' . $scheduleAttempts
            );
            $this->assertGreaterThan(0, $deleteAttempts);
            $this->assertGreaterThan(0, $scheduleAttempts);
            $this->assertNull(get_post($attachmentId));
            $this->assertFileExists($filePath);
            $this->assertNotEmpty($blockedPaths);
            $this->assertContains(
                $filePath,
                (array) ll_tools_import_read_pending_attachment_file_cleanup($attachmentId)
            );
            $this->assertNotFalse(wp_next_scheduled(
                'll_tools_import_attachment_file_cleanup',
                [$attachmentId]
            ));

            remove_filter('ll_tools_import_undo_file_delete_allowed', $blockFileDelete, 10);
            $filterActive = false;
            ll_tools_import_run_pending_attachment_file_cleanup($attachmentId);

            $this->assertFileDoesNotExist($filePath);
            $this->assertSame([], ll_tools_import_read_pending_attachment_file_cleanup($attachmentId));
        } finally {
            if ($filterActive && isset($blockFileDelete)) {
                remove_filter('ll_tools_import_undo_file_delete_allowed', $blockFileDelete, 10);
            }
            if ($scheduleObserverActive && isset($observeSchedule)) {
                remove_filter('pre_schedule_event', $observeSchedule, 10);
            }
            if ($deleteObserverActive && isset($observeDelete)) {
                remove_action('delete_attachment', $observeDelete, 10);
            }
            if ($attachmentId > 0) {
                wp_clear_scheduled_hook('ll_tools_import_attachment_file_cleanup', [$attachmentId], true);
                ll_tools_import_clear_pending_attachment_file_cleanup($attachmentId);
                if (get_post($attachmentId) instanceof WP_Post) {
                    wp_delete_attachment($attachmentId, true);
                }
            }
            if ($filePath !== '' && is_file($filePath)) {
                @unlink($filePath);
            }
            if ($attachmentFilePath !== '' && is_file($attachmentFilePath)) {
                @unlink($attachmentFilePath);
            }
            wp_cache_delete('cron', 'options');
            wp_cache_delete('alloptions', 'options');
            $wpdb->query('SET autocommit = 0');
            $wpdb->query('START TRANSACTION');
        }
    }

    public function test_attachment_file_discovery_fails_closed_on_an_authoritative_metadata_read_error(): void
    {
        $upload = wp_upload_bits(
            'undo-attachment-meta-read-' . wp_generate_password(6, false, false) . '.jpg',
            null,
            'attachment-data'
        );
        $this->assertSame('', (string) ($upload['error'] ?? ''));
        $filePath = (string) $upload['file'];
        $attachmentId = wp_insert_attachment([
            'post_title' => 'Undo attachment metadata read failure',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
        ], $filePath);
        $this->assertGreaterThan(0, $attachmentId);
        update_attached_file($attachmentId, $filePath);
        $metaReadWasBlocked = false;
        $blockMetaRead = static function (string $query) use (&$metaReadWasBlocked): string {
            if (str_contains($query, "meta_key IN ('_wp_attached_file'")) {
                $metaReadWasBlocked = true;
                throw new RuntimeException('Synthetic attachment metadata read failure.');
            }
            return $query;
        };
        add_filter('query', $blockMetaRead);
        try {
            $paths = ll_tools_import_attachment_file_paths_for_undo($attachmentId);
        } finally {
            remove_filter('query', $blockMetaRead);
        }

        $this->assertTrue($metaReadWasBlocked);
        $this->assertNull($paths);
        $this->assertInstanceOf(WP_Post::class, get_post($attachmentId));
        $this->assertFileExists($filePath);
        $this->assertSame([], ll_tools_import_read_pending_attachment_file_cleanup($attachmentId));
        wp_delete_attachment($attachmentId, true);
    }

    public function test_audio_file_undo_counter_is_idempotent_on_retry(): void
    {
        $uploadDir = wp_upload_dir();
        $filePath = trailingslashit((string) $uploadDir['path'])
            . 'undo-audio-counter-' . wp_generate_password(6, false, false) . '.mp3';
        if (!is_dir((string) $uploadDir['path'])) {
            wp_mkdir_p((string) $uploadDir['path']);
        }
        file_put_contents($filePath, 'audio-data');
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'audio_paths' => [$filePath],
        ])];

        $first = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($first['ok'] ?? false));
        $this->assertSame(1, (int) ($first['stats']['audio_files_deleted'] ?? 0));
        $this->assertFileDoesNotExist($filePath);

        $second = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($second['ok'] ?? false));
        $this->assertSame(0, (int) ($second['stats']['audio_files_deleted'] ?? 0));
    }

    public function test_post_undo_repairs_a_silently_blocked_relationship_delete_and_recounts(): void
    {
        global $wpdb;

        $category = wp_insert_term('Undo recount ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertIsArray($category);
        $categoryId = (int) $category['term_id'];
        $ttId = (int) $category['term_taxonomy_id'];
        $deletedWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Undo recount deleted word',
        ]);
        $remainingWordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Undo recount remaining word',
        ]);
        wp_set_object_terms($deletedWordId, [$categoryId], 'word-category');
        wp_set_object_terms($remainingWordId, [$categoryId], 'word-category');
        wp_update_term_count_now([$ttId], 'word-category');
        $this->assertSame(2, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            $ttId
        )));

        $blockedDeletes = 0;
        $blockFirstRelationshipDelete = static function (string $query) use (
            $wpdb,
            $deletedWordId,
            &$blockedDeletes
        ): string {
            if (
                $blockedDeletes === 0
                && str_starts_with(ltrim($query), 'DELETE FROM')
                && str_contains($query, (string) $wpdb->term_relationships)
                && str_contains($query, (string) $deletedWordId)
            ) {
                $blockedDeletes++;
                return 'SELECT 1';
            }
            return $query;
        };
        add_filter('query', $blockFirstRelationshipDelete);
        try {
            $result = ll_tools_undo_import_entry([
                'undo' => array_merge(ll_tools_import_default_undo_payload(), [
                    'word_post_ids' => [$deletedWordId],
                ]),
            ]);
        } finally {
            remove_filter('query', $blockFirstRelationshipDelete);
        }

        $this->assertSame(1, $blockedDeletes);
        $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
        $this->assertNull(get_post($deletedWordId));
        $this->assertInstanceOf(WP_Post::class, get_post($remainingWordId));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d",
            $deletedWordId
        )));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
            $ttId
        )));
    }

    public function test_post_undo_repairs_a_silently_blocked_comment_delete(): void
    {
        global $wpdb;

        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Undo comment cleanup',
        ]);
        $commentId = self::factory()->comment->create(['comment_post_ID' => $wordId]);
        add_comment_meta($commentId, 'undo_comment_meta', 'owned');
        $blockedDeletes = 0;
        $blockFirstCommentDelete = static function (string $query) use ($wpdb, $commentId, &$blockedDeletes): string {
            if (
                $blockedDeletes === 0
                && str_starts_with(ltrim($query), 'DELETE FROM')
                && str_contains($query, (string) $wpdb->comments)
                && str_contains($query, (string) $commentId)
            ) {
                $blockedDeletes++;
                return 'SELECT 1';
            }
            return $query;
        };
        add_filter('query', $blockFirstCommentDelete);
        try {
            $result = ll_tools_undo_import_entry([
                'undo' => array_merge(ll_tools_import_default_undo_payload(), [
                    'word_post_ids' => [$wordId],
                ]),
            ]);
        } finally {
            remove_filter('query', $blockFirstCommentDelete);
        }

        $this->assertSame(1, $blockedDeletes);
        $this->assertTrue((bool) ($result['ok'] ?? false), implode(' | ', (array) ($result['errors'] ?? [])));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_ID = %d",
            $commentId
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE comment_id = %d",
            $commentId
        )));
    }

    public function test_post_undo_rolls_back_when_a_revision_delete_is_vetoed(): void
    {
        global $wpdb;

        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Undo revision parent',
        ]);
        $revisionId = self::factory()->post->create([
            'post_type' => 'revision',
            'post_status' => 'inherit',
            'post_parent' => $wordId,
            'post_title' => 'Undo revision child',
        ]);
        $wpdb->insert($wpdb->postmeta, [
            'post_id' => $revisionId,
            'meta_key' => 'undo_revision_meta',
            'meta_value' => 'owned',
        ], ['%d', '%s', '%s']);
        $vetoRevision = static function ($delete, WP_Post $post) use ($revisionId) {
            return (int) $post->ID === $revisionId ? false : $delete;
        };
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'word_post_ids' => [$wordId],
        ])];
        add_filter('pre_delete_post', $vetoRevision, 10, 2);
        try {
            $first = ll_tools_undo_import_entry($entry);
        } finally {
            remove_filter('pre_delete_post', $vetoRevision, 10);
        }

        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertInstanceOf(WP_Post::class, get_post($wordId));
        $this->assertInstanceOf(WP_Post::class, get_post($revisionId));
        $this->assertSame('owned', (string) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = 'undo_revision_meta'
             LIMIT 1",
            $revisionId
        )));

        $second = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($second['ok'] ?? false), implode(' | ', (array) ($second['errors'] ?? [])));
        $this->assertNull(get_post($wordId));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
            $revisionId
        )));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
            $revisionId
        )));
    }

    public function test_wordset_undo_retries_option_cleanup_after_the_term_is_deleted(): void
    {
        global $wpdb;

        $insert = wp_insert_term('Retry Option Undo Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($insert);
        $wordsetId = (int) $insert['term_id'];
        update_option('ll_vocab_lesson_wordsets', [$wordsetId], false);
        $optionUpdateWasBlocked = false;
        $blockOptionUpdate = static function (string $query) use ($wpdb, &$optionUpdateWasBlocked): string {
            if (
                str_starts_with(ltrim($query), 'UPDATE')
                && str_contains($query, (string) $wpdb->options)
                && str_contains($query, 'll_vocab_lesson_wordsets')
            ) {
                $optionUpdateWasBlocked = true;
                return 'SELECT 1';
            }
            return $query;
        };
        $entry = ['undo' => array_merge(ll_tools_import_default_undo_payload(), [
            'wordset_term_ids' => [$wordsetId],
        ])];
        add_filter('query', $blockOptionUpdate);
        try {
            $first = ll_tools_undo_import_entry($entry);
        } finally {
            remove_filter('query', $blockOptionUpdate);
        }

        $this->assertTrue($optionUpdateWasBlocked);
        $this->assertFalse((bool) ($first['ok'] ?? true));
        $this->assertSame(1, (int) ($first['stats']['wordsets_deleted'] ?? 0));
        $this->assertFalse((bool) term_exists($wordsetId, 'wordset'));
        $storedOptionAfterFirst = maybe_unserialize($wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'll_vocab_lesson_wordsets'
        )));
        $this->assertContains($wordsetId, array_map('intval', (array) $storedOptionAfterFirst));

        $second = ll_tools_undo_import_entry($entry);
        $this->assertTrue((bool) ($second['ok'] ?? false), implode(' | ', (array) ($second['errors'] ?? [])));
        $this->assertSame(0, (int) ($second['stats']['wordsets_deleted'] ?? 0));
        $storedOptionAfterSecond = maybe_unserialize($wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            'll_vocab_lesson_wordsets'
        )));
        $this->assertNotContains($wordsetId, array_map('intval', (array) $storedOptionAfterSecond));
    }

    public function test_undo_converts_a_throwing_residual_read_into_a_per_term_error(): void
    {
        $insert = wp_insert_term('Throwing Residual Undo ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertIsArray($insert);
        $wordsetId = (int) $insert['term_id'];
        $readThrew = false;
        $throwResidualRead = static function (string $query) use (&$readThrew): string {
            if (!$readThrew && str_contains($query, '(SELECT COUNT(*)') && str_contains($query, 'termmeta')) {
                $readThrew = true;
                throw new RuntimeException('Synthetic Undo residual-read failure.');
            }
            return $query;
        };
        add_filter('query', $throwResidualRead);
        try {
            $result = ll_tools_undo_import_entry([
                'undo' => array_merge(ll_tools_import_default_undo_payload(), [
                    'wordset_term_ids' => [$wordsetId],
                ]),
            ]);
        } finally {
            remove_filter('query', $throwResidualRead);
        }

        $this->assertTrue($readThrew);
        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertNotEmpty((array) ($result['errors'] ?? []));
        $this->assertNotFalse(term_exists($wordsetId, 'wordset'));
    }

    public function test_undo_import_entry_restores_metadata_update_snapshots(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Undo Original Word',
            'post_name' => 'undo-original-word',
        ]);
        update_post_meta($word_id, 'word_translation', 'Undo Original Translation');
        update_post_meta($word_id, 'word_english_meaning', 'Undo Original Translation');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Undo Original Recording',
            'post_name' => 'undo-original-recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Undo Original Recording Text');
        update_post_meta($recording_id, 'recording_ipa', 'undo.old.ipa');
        update_post_meta($recording_id, 'speaker_name', 'Undo Speaker');

        $csv = implode("\n", [
            'word_id,recording_id,word_title,word_translation,recording_text,recording_ipa,speaker_name',
            $word_id . ',' . $recording_id . ',Undo Changed Word,Undo Changed Translation,Undo Changed Recording Text,undo.new.ipa,Undo Speaker New',
        ]) . "\n";

        $file_path = wp_normalize_path(trailingslashit(sys_get_temp_dir()) . 'll-tools-undo-metadata-' . wp_generate_password(8, false, false) . '.csv');
        file_put_contents($file_path, $csv);

        try {
            $processed = ll_tools_process_metadata_updates_file($file_path, 'undo-updates.csv');
            $this->assertTrue((bool) ($processed['ok'] ?? false), implode(' | ', (array) ($processed['errors'] ?? [])));
            $this->assertTrue(ll_tools_import_has_undo_targets((array) ($processed['undo'] ?? [])));
            $this->assertTrue(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));

            $undo_result = ll_tools_undo_import_entry([
                'undo' => (array) ($processed['undo'] ?? []),
            ]);

            $this->assertTrue((bool) ($undo_result['ok'] ?? false), implode(' | ', (array) ($undo_result['errors'] ?? [])));
            $this->assertSame('Undo Original Word', (string) get_the_title($word_id));
            $this->assertSame('Undo Original Translation', (string) get_post_meta($word_id, 'word_translation', true));
            $this->assertSame('Undo Original Translation', (string) get_post_meta($word_id, 'word_english_meaning', true));
            $this->assertSame('Undo Original Recording Text', (string) get_post_meta($recording_id, 'recording_text', true));
            $this->assertSame('undo.old.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
            $this->assertSame('Undo Speaker', (string) get_post_meta($recording_id, 'speaker_name', true));
            $this->assertFalse(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));

            $stats = isset($undo_result['stats']) && is_array($undo_result['stats']) ? $undo_result['stats'] : [];
            $this->assertSame(2, (int) ($stats['metadata_posts_restored'] ?? 0));
            $this->assertGreaterThanOrEqual(5, (int) ($stats['metadata_fields_restored'] ?? 0));
        } finally {
            @unlink($file_path);
        }
    }

    public function test_recent_imports_section_lists_categories_and_matching_lesson_links(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $category_insert = wp_insert_term('History Category ' . wp_generate_password(6, false, false), 'word-category');
        $this->assertFalse(is_wp_error($category_insert));
        $this->assertIsArray($category_insert);
        $category_id = (int) $category_insert['term_id'];
        $category = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $primary_wordset_insert = wp_insert_term('History Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($primary_wordset_insert));
        $this->assertIsArray($primary_wordset_insert);
        $primary_wordset_id = (int) $primary_wordset_insert['term_id'];
        $primary_wordset = get_term($primary_wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $primary_wordset);

        $secondary_wordset_insert = wp_insert_term('Other Wordset ' . wp_generate_password(6, false, false), 'wordset');
        $this->assertFalse(is_wp_error($secondary_wordset_insert));
        $this->assertIsArray($secondary_wordset_insert);
        $secondary_wordset_id = (int) $secondary_wordset_insert['term_id'];

        $matching_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Matching History Lesson',
        ]);
        update_post_meta($matching_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);
        update_post_meta($matching_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $primary_wordset_id);

        $non_matching_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Non Matching History Lesson',
        ]);
        update_post_meta($non_matching_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);
        update_post_meta($non_matching_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $secondary_wordset_id);

        ob_start();
        ll_tools_render_recent_imports_section([
            [
                'id' => 'history-entry-with-lessons',
                'finished_at' => time(),
                'ok' => true,
                'stats' => [
                    'categories_created' => 1,
                ],
                'undo' => ll_tools_import_default_undo_payload(),
                'history_context' => [
                    'categories' => [
                        [
                            'term_id' => $category_id,
                            'name' => $category->name,
                            'slug' => $category->slug,
                        ],
                    ],
                    'wordsets' => [
                        [
                            'term_id' => $primary_wordset_id,
                            'name' => $primary_wordset->name,
                            'slug' => $primary_wordset->slug,
                        ],
                    ],
                ],
            ],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Categories (1)', $html);
        $this->assertStringContainsString($category->name, $html);
        $this->assertStringContainsString('Lesson pages:', $html);
        $this->assertStringContainsString($primary_wordset->name, $html);
        $this->assertStringContainsString(get_permalink($matching_lesson_id), $html);
        $this->assertStringNotContainsString(get_permalink($non_matching_lesson_id), $html);
    }

    public function test_recent_imports_section_bounds_category_and_lesson_queries(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_insert = wp_insert_term('Bounded History Wordset', 'wordset');
        $this->assertIsArray($wordset_insert);
        $wordset_id = (int) $wordset_insert['term_id'];
        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $category_context = [];
        $category_names = [];
        foreach (range(1, 4) as $category_index) {
            $category_insert = wp_insert_term('Bounded History Category ' . $category_index, 'word-category');
            $this->assertIsArray($category_insert);
            $category_id = (int) $category_insert['term_id'];
            $category = get_term($category_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $category);
            $category_names[] = (string) $category->name;
            $category_context[] = [
                'term_id' => $category_id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
            ];

            foreach (range(1, 5) as $lesson_index) {
                $lesson_id = self::factory()->post->create([
                    'post_type' => 'll_vocab_lesson',
                    'post_status' => 'publish',
                    'post_title' => sprintf('Bounded Lesson %d-%d', $category_index, $lesson_index),
                ]);
                update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);
                update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
            }
        }

        $category_limit = static function (): int {
            return 2;
        };
        $lesson_limit = static function (): int {
            return 2;
        };
        $captured_queries = [];
        $capture = static function (WP_Query $query) use (&$captured_queries): void {
            if ($query->get('post_type') === 'll_vocab_lesson') {
                $captured_queries[] = $query->query_vars;
            }
        };
        add_filter('ll_tools_import_recent_category_limit', $category_limit);
        add_filter('ll_tools_import_recent_lesson_link_limit', $lesson_limit);
        add_action('pre_get_posts', $capture, 10, 1);
        try {
            ob_start();
            ll_tools_render_recent_imports_section([[
                'id' => 'bounded-history-entry',
                'finished_at' => time(),
                'ok' => true,
                'stats' => ['categories_created' => 4],
                'undo' => ll_tools_import_default_undo_payload(),
                'history_context' => [
                    'categories' => $category_context,
                    'wordsets' => [[
                        'term_id' => $wordset_id,
                        'name' => (string) $wordset->name,
                        'slug' => (string) $wordset->slug,
                    ]],
                ],
            ]]);
            $html = (string) ob_get_clean();
        } finally {
            remove_action('pre_get_posts', $capture, 10);
            remove_filter('ll_tools_import_recent_lesson_link_limit', $lesson_limit);
            remove_filter('ll_tools_import_recent_category_limit', $category_limit);
        }

        $this->assertCount(2, $captured_queries);
        foreach ($captured_queries as $query_vars) {
            $this->assertSame(3, (int) ($query_vars['posts_per_page'] ?? 0));
            $this->assertTrue((bool) ($query_vars['no_found_rows'] ?? false));
        }
        $this->assertStringContainsString('Categories (4)', $html);
        $this->assertStringContainsString('Showing the first 2 of 4 imported categories.', $html);
        $this->assertStringContainsString($category_names[0], $html);
        $this->assertStringContainsString($category_names[1], $html);
        $this->assertStringNotContainsString($category_names[2], $html);
        $this->assertStringNotContainsString($category_names[3], $html);
        $this->assertSame(2, substr_count($html, 'class="ll-tools-recent-imports-category-links"'));
        $this->assertSame(4, substr_count($html, 'target="_blank"'));
    }
}
