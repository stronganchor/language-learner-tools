<?php
declare(strict_types=1);

final class LL_Tools_Example_Sentence_Migration_Wakeup_Probe
{
    public static int $wakeups = 0;

    public function __wakeup(): void
    {
        self::$wakeups++;
    }
}

final class ExampleSentenceMigrationBatchTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        LL_Tools_Example_Sentence_Migration_Wakeup_Probe::$wakeups = 0;
        $this->resetMigrationState();
    }

    protected function tearDown(): void
    {
        $this->resetMigrationState();
        parent::tearDown();
    }

    public function test_recordings_are_keyset_processed_within_the_shared_work_budget(): void
    {
        $fixture = $this->createMigrationFixture('Audio batches', 4);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (stripos($query, 'example_sentence') !== false || stripos($query, "terms.slug = 'introduction'") !== false) {
                $queries[] = $query;
            }
            return $query;
        };
        add_filter('query', $capture);
        try {
            $first = ll_tools_run_example_sentence_migration_batch();
            $this->assertSame('queued', $first['status']);
            $this->assertSame($fixture['word_id'], $first['active_word_id']);
            $this->assertSame($fixture['audio_ids'][0], $first['audio_cursor']);
            $this->assertSame(0, $first['word_cursor']);
            $this->assertSame(0, $first['processed']);
            $this->assertSame('Audio batches example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
            $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][1], 'recording_text', true));
            $this->assertSame('Audio batches example sentence', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));

            $second = ll_tools_run_example_sentence_migration_batch();
            $this->assertSame('queued', $second['status']);
            $this->assertSame($fixture['audio_ids'][2], $second['audio_cursor']);
            $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][3], 'recording_text', true));

            $third = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame('completed', $third['status']);
        $this->assertSame($fixture['word_id'], $third['word_cursor']);
        $this->assertSame(0, $third['active_word_id']);
        $this->assertSame(1, $third['processed']);
        $this->assertSame(1, $third['migrated']);
        $this->assertSame('1', (string) get_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION, ''));
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        foreach ($fixture['audio_ids'] as $audio_id) {
            $this->assertSame('Audio batches example sentence', (string) get_post_meta($audio_id, 'recording_text', true));
            $this->assertSame('Audio batches example translation', (string) get_post_meta($audio_id, 'recording_translation', true));
        }
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));

        $queryText = implode("\n", $queries);
        $this->assertMatchesRegularExpression('/posts\.ID > 0[\s\S]+LIMIT 3/', $queryText);
        $this->assertMatchesRegularExpression('/posts\.post_parent = ' . $fixture['word_id'] . '[\s\S]+posts\.ID > 0[\s\S]+LIMIT 2/', $queryText);
        $this->assertStringNotContainsString('posts_per_page', $queryText);
    }

    public function test_word_cursor_advances_only_after_each_word_is_complete(): void
    {
        $firstFixture = $this->createMigrationFixture('First word', 1);
        $secondFixture = $this->createMigrationFixture('Second word', 1);
        $thirdFixture = $this->createMigrationFixture('Third word', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);

        $first = ll_tools_run_example_sentence_migration_batch();
        $this->assertSame('queued', $first['status']);
        $this->assertSame($firstFixture['word_id'], $first['word_cursor']);
        $this->assertSame(1, $first['processed']);
        $this->assertSame('', (string) get_post_meta($firstFixture['word_id'], 'word_example_sentence', true));
        $this->assertNotSame('', (string) get_post_meta($secondFixture['word_id'], 'word_example_sentence', true));

        $second = ll_tools_run_example_sentence_migration_batch();
        $this->assertSame('queued', $second['status']);
        $this->assertSame($secondFixture['word_id'], $second['word_cursor']);
        $this->assertSame(2, $second['processed']);
        $this->assertNotSame('', (string) get_post_meta($thirdFixture['word_id'], 'word_example_sentence', true));

        $third = ll_tools_run_example_sentence_migration_batch();
        $this->assertSame('completed', $third['status']);
        $this->assertSame($thirdFixture['word_id'], $third['word_cursor']);
        $this->assertSame(3, $third['processed']);
        $this->assertSame(3, $third['migrated']);
    }

    public function test_source_query_failure_keeps_the_cursor_and_retry_resumes(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Retry source', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $postsTable = $wpdb->posts;
        $postmetaTable = $wpdb->postmeta;
        $failSource = static function (string $query) use ($postsTable, $postmetaTable): string {
            if (
                stripos($query, "FROM {$postsTable} AS posts") !== false
                && stripos($query, "INNER JOIN {$postmetaTable} AS postmeta") !== false
                && stripos($query, 'example_sentence') !== false
            ) {
                return 'SELECT ID FROM ll_tools_missing_example_sentence_source';
            }
            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failSource);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $failSource);
            $wpdb->suppress_errors($previousSuppressErrors);
        }

        $this->assertSame('queued', $failed['status']);
        $this->assertSame('word_source_unavailable', $failed['last_error']);
        $this->assertSame(0, $failed['word_cursor']);
        $this->assertSame(0, $failed['active_word_id']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame('Retry source example sentence', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));

        $retried = ll_tools_run_example_sentence_migration_batch();
        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame(1, $retried['migrated']);
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('Retry source example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
    }

    public function test_word_meta_read_failure_retries_the_exact_word_without_advancing(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Retry word meta', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $failedOnce = false;
        $failWordMetaRead = static function (string $query) use (&$failedOnce, $fixture): string {
            if (
                !$failedOnce
                && stripos($query, 'SELECT meta_id, meta_value') !== false
                && stripos($query, 'post_id = ' . $fixture['word_id']) !== false
                && stripos($query, "meta_key = 'word_example_sentence'") !== false
            ) {
                $failedOnce = true;
                return 'SELECT meta_value FROM ll_tools_missing_example_sentence_word_meta';
            }
            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failWordMetaRead);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $failWordMetaRead);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($failedOnce, 'The fixture must fail the first source metadata read.');
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('word_source_unavailable', $failed['last_error']);
        $this->assertSame(0, $failed['word_cursor']);
        $this->assertSame(0, $failed['active_word_id']);
        $this->assertSame(0, $failed['audio_cursor']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame('Retry word meta example sentence', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame(1, $retried['migrated']);
        $this->assertSame('Retry word meta example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
    }

    public function test_audio_meta_read_failure_keeps_the_exact_active_audio_for_retry(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Retry audio read', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $failedOnce = false;
        $failAudioMetaRead = static function (string $query) use (&$failedOnce, $fixture): string {
            if (
                !$failedOnce
                && stripos($query, 'SELECT meta_id, meta_value') !== false
                && stripos($query, 'post_id = ' . $fixture['audio_ids'][0]) !== false
                && stripos($query, "meta_key = 'recording_text'") !== false
            ) {
                $failedOnce = true;
                return 'SELECT meta_value FROM ll_tools_missing_example_sentence_audio_meta';
            }
            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failAudioMetaRead);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $failAudioMetaRead);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($failedOnce, 'The fixture must fail the first recording metadata read.');
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('recording_data_unavailable', $failed['last_error']);
        $this->assertSame(0, $failed['word_cursor']);
        $this->assertSame($fixture['word_id'], $failed['active_word_id']);
        $this->assertSame(0, $failed['audio_cursor']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame('Retry audio read example sentence', $failed['source_example']);
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['migrated']);
        $this->assertSame('Retry audio read example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
    }

    public function test_audio_write_failure_does_not_advance_the_audio_cursor(): void
    {
        $fixture = $this->createMigrationFixture('Retry audio write', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $blockedOnce = false;
        $blockRecordingTextWrite = static function ($check, $objectId, $metaKey) use (&$blockedOnce, $fixture) {
            if (
                !$blockedOnce
                && (int) $objectId === $fixture['audio_ids'][0]
                && (string) $metaKey === 'recording_text'
            ) {
                $blockedOnce = true;
                return false;
            }
            return $check;
        };

        add_filter('update_post_metadata', $blockRecordingTextWrite, 10, 3);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('update_post_metadata', $blockRecordingTextWrite, 10);
        }

        $this->assertTrue($blockedOnce, 'The fixture must suppress the recording text write.');
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('recording_data_unavailable', $failed['last_error']);
        $this->assertSame($fixture['word_id'], $failed['active_word_id']);
        $this->assertSame(0, $failed['audio_cursor']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertSame('Retry audio write example sentence', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['migrated']);
        $this->assertSame('Retry audio write example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
    }

    public function test_audio_write_readback_failure_retries_without_rewriting_completed_text(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Retry audio readback', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $recordingTextReads = 0;
        $failedOnce = false;
        $failWriteReadback = static function (string $query) use (&$recordingTextReads, &$failedOnce, $fixture): string {
            if (
                stripos($query, 'SELECT meta_id, meta_value') !== false
                && stripos($query, 'post_id = ' . $fixture['audio_ids'][0]) !== false
                && stripos($query, "meta_key = 'recording_text'") !== false
            ) {
                $recordingTextReads++;
                if ($recordingTextReads === 2) {
                    $failedOnce = true;
                    return 'SELECT meta_value FROM ll_tools_missing_example_sentence_audio_readback';
                }
            }
            return $query;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $failWriteReadback);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $failWriteReadback);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($failedOnce, 'The fixture must fail the post-write readback.');
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('recording_data_unavailable', $failed['last_error']);
        $this->assertSame($fixture['word_id'], $failed['active_word_id']);
        $this->assertSame(0, $failed['audio_cursor']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame('Retry audio readback example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_translation', true));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame(1, $retried['migrated']);
        $this->assertSame('Retry audio readback example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertSame('Retry audio readback example translation', (string) get_post_meta($fixture['audio_ids'][0], 'recording_translation', true));
    }

    public function test_partial_source_delete_failure_keeps_the_word_active_until_cleanup_retries(): void
    {
        $fixture = $this->createMigrationFixture('Retry source cleanup', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $blockedOnce = false;
        $blockTranslationDelete = static function ($check, $objectId, $metaKey) use (&$blockedOnce, $fixture) {
            if (
                !$blockedOnce
                && (int) $objectId === $fixture['word_id']
                && (string) $metaKey === 'word_example_sentence_translation'
            ) {
                $blockedOnce = true;
                return false;
            }
            return $check;
        };

        add_filter('delete_post_metadata', $blockTranslationDelete, 10, 3);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('delete_post_metadata', $blockTranslationDelete, 10);
        }

        $this->assertTrue($blockedOnce, 'The fixture must suppress the second source-key delete.');
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('word_cleanup_unavailable', $failed['last_error']);
        $this->assertSame(0, $failed['word_cursor']);
        $this->assertSame($fixture['word_id'], $failed['active_word_id']);
        $this->assertSame($fixture['audio_ids'][0], $failed['audio_cursor']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame(0, $failed['migrated']);
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('Retry source cleanup example translation', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
        $this->assertSame('Retry source cleanup example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(0, $retried['active_word_id']);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame(1, $retried['migrated']);
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
    }

    public function test_concurrent_source_change_between_read_and_delete_is_preserved(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Concurrent source edit', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        // A case-only edit compares equal under the default case-insensitive
        // postmeta collation, so this also proves the CAS uses byte equality.
        $replacement = 'CONCURRENT SOURCE EDIT EXAMPLE SENTENCE';
        $swappedOnce = false;
        $swapBeforeCompareDelete = static function (string $query) use (&$swappedOnce, $fixture, $replacement, $wpdb): string {
            if (
                !$swappedOnce
                && stripos($query, "DELETE FROM {$wpdb->postmeta}") !== false
                && stripos($query, 'post_id = ' . $fixture['word_id']) !== false
                && stripos($query, "meta_key = BINARY 'word_example_sentence'") !== false
            ) {
                $swappedOnce = true;
                update_post_meta($fixture['word_id'], 'word_example_sentence', $replacement);
            }
            return $query;
        };

        add_filter('query', $swapBeforeCompareDelete);
        try {
            $failed = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $swapBeforeCompareDelete);
        }

        $this->assertTrue($swappedOnce, 'The fixture must replace the source after comparison and before delete.');
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('word_cleanup_unavailable', $failed['last_error']);
        $this->assertSame(0, $failed['word_cursor']);
        $this->assertSame($fixture['word_id'], $failed['active_word_id']);
        $this->assertSame($fixture['audio_ids'][0], $failed['audio_cursor']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame($replacement, (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('Concurrent source edit example translation', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
        $this->assertSame('Concurrent source edit example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame(0, $retried['migrated']);
        $this->assertSame(1, $retried['skipped']);
        $this->assertSame($replacement, (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('Concurrent source edit example translation', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
    }

    public function test_activation_snapshot_preserves_raw_change_that_sanitizes_to_the_same_text(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Snapshot fence', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $replacement = 'Snapshot fence <b>example</b> sentence';
        $this->assertSame(
            'Snapshot fence example sentence',
            sanitize_text_field($replacement),
            'The replacement must normalize to the original durable migration text.'
        );

        $sourceReads = 0;
        $swappedOnce = false;
        $swapBeforeFinalRead = static function (string $query) use (&$sourceReads, &$swappedOnce, $fixture, $replacement, $wpdb): string {
            if (
                stripos($query, 'SELECT meta_id, meta_value') !== false
                && stripos($query, "FROM {$wpdb->postmeta}") !== false
                && stripos($query, 'post_id = ' . $fixture['word_id']) !== false
                && stripos($query, "meta_key = 'word_example_sentence'") !== false
            ) {
                $sourceReads++;
                if ($sourceReads === 2) {
                    // Guard before the nested write so its queries cannot
                    // recursively re-enter this fixture mutation.
                    $swappedOnce = true;
                    update_post_meta($fixture['word_id'], 'word_example_sentence', $replacement);
                }
            }
            return $query;
        };

        add_filter('query', $swapBeforeFinalRead);
        try {
            $result = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $swapBeforeFinalRead);
        }

        $this->assertTrue($swappedOnce, 'The fixture must replace the raw source after activation and before finalization.');
        $this->assertSame(2, $sourceReads);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($fixture['word_id'], $result['word_cursor']);
        $this->assertSame(0, $result['active_word_id']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(0, $result['migrated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame($replacement, (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('Snapshot fence example translation', (string) get_post_meta($fixture['word_id'], 'word_example_sentence_translation', true));
        $this->assertSame('Snapshot fence example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
    }

    public function test_lock_contention_leaves_the_batch_unchanged_and_retryable(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Lock contention', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $lockName = ll_tools_example_sentence_migration_lock_name();
        $databaseName = defined('DB_NAME') ? (string) DB_NAME : '';
        $this->assertSame(
            'll_tools_example_migration_' . substr(hash('sha256', $databaseName . '|' . (string) $wpdb->options), 0, 32),
            $lockName
        );
        $this->assertLessThanOrEqual(64, strlen($lockName));

        $deniedOnce = false;
        $denyLock = static function (string $query) use (&$deniedOnce, $lockName): string {
            if (
                stripos($query, 'SELECT GET_LOCK(') !== false
                && stripos($query, $lockName) !== false
            ) {
                $deniedOnce = true;
                return 'SELECT 0';
            }
            return $query;
        };

        add_filter('query', $denyLock);
        try {
            $blocked = ll_tools_run_example_sentence_migration_batch();
        } finally {
            remove_filter('query', $denyLock);
        }

        $this->assertTrue($deniedOnce, 'The fixture must simulate a competing advisory-lock owner.');
        $this->assertSame('queued', $blocked['status']);
        $this->assertSame(0, $blocked['word_cursor']);
        $this->assertSame(0, $blocked['active_word_id']);
        $this->assertSame(0, $blocked['processed']);
        $this->assertSame('Lock contention example sentence', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame('Lock contention example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $this->assertSame('', (string) get_post_meta($fixture['word_id'], 'word_example_sentence', true));
    }

    public function test_serialized_object_source_is_rejected_without_wakeup_and_retries_the_same_word(): void
    {
        global $wpdb;

        $fixture = $this->createMigrationFixture('Serialized object', 1);
        add_filter('ll_tools_example_sentence_migration_batch_limit', static fn (): int => 2);
        $payload = serialize(new LL_Tools_Example_Sentence_Migration_Wakeup_Probe());
        $updated = $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $payload],
            [
                'post_id' => $fixture['word_id'],
                'meta_key' => 'word_example_sentence',
            ],
            ['%s'],
            ['%d', '%s']
        );
        $this->assertSame(1, $updated);
        wp_cache_delete($fixture['word_id'], 'post_meta');

        $decodeComplete = false;
        $this->assertFalse(ll_tools_decode_example_sentence_migration_meta_value('b:0;', $decodeComplete));
        $this->assertTrue($decodeComplete, 'A valid serialized false value must remain distinguishable from decode failure.');

        $failed = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame(0, LL_Tools_Example_Sentence_Migration_Wakeup_Probe::$wakeups);
        $this->assertSame('queued', $failed['status']);
        $this->assertSame('word_source_unavailable', $failed['last_error']);
        $this->assertSame(0, $failed['word_cursor']);
        $this->assertSame(0, $failed['active_word_id']);
        $this->assertSame(0, $failed['processed']);
        $this->assertSame('', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
        $storedPayload = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $fixture['word_id'],
            'word_example_sentence'
        ));
        $this->assertSame($payload, (string) $storedPayload);

        $repaired = $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => 'Serialized object example sentence'],
            [
                'post_id' => $fixture['word_id'],
                'meta_key' => 'word_example_sentence',
            ],
            ['%s'],
            ['%d', '%s']
        );
        $this->assertSame(1, $repaired);
        wp_cache_delete($fixture['word_id'], 'post_meta');

        $retried = ll_tools_run_example_sentence_migration_batch();

        $this->assertSame(0, LL_Tools_Example_Sentence_Migration_Wakeup_Probe::$wakeups);
        $this->assertSame('completed', $retried['status']);
        $this->assertSame($fixture['word_id'], $retried['word_cursor']);
        $this->assertSame(1, $retried['processed']);
        $this->assertSame('Serialized object example sentence', (string) get_post_meta($fixture['audio_ids'][0], 'recording_text', true));
    }

    public function test_schedule_cleanup_preserves_restart_state(): void
    {
        $state = ll_tools_example_sentence_migration_default_state();
        $state['word_cursor'] = 123;
        ll_tools_save_example_sentence_migration_state($state);
        ll_tools_schedule_example_sentence_migration();
        set_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT, 'test-lock', MINUTE_IN_SECONDS);

        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));
        $this->assertSame('test-lock', get_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT));

        ll_tools_clear_example_sentence_migration_schedule();

        $this->assertFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));
        $this->assertFalse(get_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT));
        $this->assertSame(123, ll_tools_get_example_sentence_migration_state()['word_cursor']);
    }

    public function test_plugin_deactivation_clears_migration_and_retention_continuations(): void
    {
        $deactivationHook = 'deactivate_' . plugin_basename(LL_TOOLS_MAIN_FILE);
        $this->assertNotFalse(has_action($deactivationHook), 'The plugin deactivation callback must be registered.');

        $state = ll_tools_example_sentence_migration_default_state();
        $state['word_cursor'] = 456;
        ll_tools_save_example_sentence_migration_state($state);
        set_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT, 'deactivation-marker', MINUTE_IN_SECONDS);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK);

        update_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, 12, false);
        update_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, 34, false);
        wp_schedule_single_event(time() + MINUTE_IN_SECONDS, LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK);

        $administrator = get_role('administrator');
        $administratorHadViewCapability = $administrator instanceof WP_Role
            && $administrator->has_cap('view_ll_tools');

        try {
            do_action($deactivationHook);

            $this->assertFalse(wp_next_scheduled(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK));
            $this->assertFalse(get_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT));
            $this->assertSame(
                456,
                ll_tools_get_example_sentence_migration_state()['word_cursor'],
                'Deactivation must preserve the durable migration cursor for a later reactivation.'
            );
            $this->assertFalse(wp_next_scheduled(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK));
            $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION, false));
        } finally {
            wp_clear_scheduled_hook(LL_TOOLS_USER_PROGRESS_RETENTION_CONTINUATION_HOOK);
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CURSOR_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_RETENTION_CEILING_OPTION);
            ll_tools_schedule_user_progress_retention_cleanup();
            if ($administrator instanceof WP_Role && $administratorHadViewCapability) {
                $administrator->add_cap('view_ll_tools');
            }
        }
    }

    /** @return array{word_id:int,audio_ids:array<int,int>} */
    private function createMigrationFixture(string $label, int $audioCount): array
    {
        $term = term_exists('introduction', 'recording_type');
        if (!$term) {
            $term = wp_insert_term('Introduction', 'recording_type', ['slug' => 'introduction']);
        }
        $this->assertFalse(is_wp_error($term));

        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $label . ' migration word',
        ]);
        update_post_meta($wordId, 'word_example_sentence', $label . ' example sentence');
        update_post_meta($wordId, 'word_example_sentence_translation', $label . ' example translation');

        $audioIds = [];
        for ($index = 1; $index <= $audioCount; $index++) {
            $audioId = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'post_title' => $label . ' introduction recording ' . $index,
            ]);
            $assigned = wp_set_object_terms($audioId, ['introduction'], 'recording_type', false);
            $this->assertFalse(is_wp_error($assigned));
            $audioIds[] = (int) $audioId;
        }
        sort($audioIds, SORT_NUMERIC);

        return [
            'word_id' => (int) $wordId,
            'audio_ids' => $audioIds,
        ];
    }

    private function resetMigrationState(): void
    {
        delete_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_DONE_OPTION);
        delete_option(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_STATE_OPTION);
        delete_transient(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_LOCK_TRANSIENT);
        wp_clear_scheduled_hook(LL_TOOLS_EXAMPLE_SENTENCE_MIGRATION_HOOK);
        remove_all_filters('ll_tools_example_sentence_migration_batch_limit');
    }
}
