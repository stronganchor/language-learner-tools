<?php
declare(strict_types=1);

final class RecorderMutationProtocolTest extends LL_Tools_TestCase
{
    private function request(callable $handler, array $data): array
    {
        $_POST = ['nonce' => wp_create_nonce('ll_upload_recording')] + $data;
        $_REQUEST = $_POST;
        $die = static function () { return static function (): void { throw new RuntimeException('wp_die'); }; };
        $ajax = static function (): bool { return true; };
        add_filter('wp_die_handler', $die);
        add_filter('wp_die_ajax_handler', $die);
        add_filter('wp_doing_ajax', $ajax);
        ob_start();
        try {
            $handler();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die);
            remove_filter('wp_die_ajax_handler', $die);
            remove_filter('wp_doing_ajax', $ajax);
            $_POST = $_REQUEST = [];
        }
        $result = json_decode($output, true);
        $this->assertIsArray($result, $output);
        return $result;
    }

    private function prepareRequest(): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (!get_term_by('slug', 'isolation', 'recording_type')) {
            wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
        }
        $wordset = wp_insert_term('Protocol ' . wp_generate_uuid4(), 'wordset');
        $this->assertNotWPError($wordset);
        return [
            'preparation_id' => time() . '-' . bin2hex(random_bytes(16)),
            'word_text_target' => 'Merhaba',
            'word_text_translation' => 'Hello',
            'wordset_ids' => wp_json_encode([(int) $wordset['term_id']]),
            'category' => 'uncategorized',
        ];
    }

    private function receiptKey(array $request): string
    {
        return 'll_recorder_prepare_' . hash('sha256', get_current_user_id() . ':' . $request['preparation_id']);
    }

    public function test_lost_creation_response_replays_exact_receipt_without_duplicate_or_overwriting_newer_text(): void
    {
        $request = $this->prepareRequest();
        $first = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertTrue($first['success']);
        $word = $first['data']['word'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $word['text_revision']);
        $this->assertSame('Merhaba', $word['word_text_target']);
        $before = (int) wp_count_posts('words')->draft;
        ll_tools_update_word_target_text((int) $word['word_id'], 'Changed elsewhere', true);
        wp_cache_flush(); // Losing an object cache must never lose the receipt.
        $retry = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertSame($first, $retry);
        $this->assertSame($before, (int) wp_count_posts('words')->draft);
        $this->assertSame('Changed elsewhere', ll_tools_get_word_target_text((int) $word['word_id']));
        $stale = $this->request('ll_update_new_word_text_handler', [
            'word_id' => $word['word_id'], 'word_text_target' => 'Merhaba',
            'word_text_translation' => 'Hello', 'expected_text_revision' => $word['text_revision'],
        ]);
        $this->assertFalse($stale['success']);
        $this->assertSame('recorder_text_conflict', $stale['data']['code']);
        $this->assertSame('Changed elsewhere', $stale['data']['current']['word_text_target']);
    }

    public function test_reusing_creation_id_with_different_payload_is_rejected(): void
    {
        $request = $this->prepareRequest();
        $first = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertTrue($first['success']);
        $count = (int) wp_count_posts('words')->draft;
        $request['word_text_target'] = 'Other word';
        $retry = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertFalse($retry['success']);
        $this->assertSame('recorder_preparation_mismatch', $retry['data']['code']);
        $this->assertArrayNotHasKey('safe_to_retry', $retry);
        $this->assertSame($count, (int) wp_count_posts('words')->draft);
    }

    public function test_pending_or_expired_creation_never_blindly_reinserts(): void
    {
        $request = $this->prepareRequest();
        $context = ll_tools_recorder_prepare_begin($request);
        $this->assertIsArray($context);
        ll_tools_recorder_mutation_unlock($context['lock']);
        $before = (int) wp_count_posts('words')->draft;
        $pending = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertFalse($pending['success']);
        $this->assertSame('recorder_preparation_incomplete', $pending['data']['code']);
        $request['preparation_id'] = (time() - 8 * DAY_IN_SECONDS) . '-' . bin2hex(random_bytes(16));
        $expired = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertFalse($expired['success']);
        $this->assertSame('recorder_preparation_expired', $expired['data']['code']);
        $this->assertSame($before, (int) wp_count_posts('words')->draft);
    }

    public function test_receipts_are_scoped_to_the_authenticated_user_and_recheck_deleted_words(): void
    {
        $request = $this->prepareRequest();
        $first = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertTrue($first['success']);
        $owner = get_current_user_id();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $second = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertTrue($second['success']);
        $this->assertNotSame($first['data']['word']['word_id'], $second['data']['word']['word_id']);
        wp_set_current_user($owner);
        wp_trash_post((int) $first['data']['word']['word_id']);
        $retry = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertFalse($retry['success']);
        $this->assertSame('recorder_preparation_unavailable', $retry['data']['code']);
    }

    public function test_receipt_claim_storage_failure_prevents_creation(): void
    {
        global $wpdb;
        $request = $this->prepareRequest();
        $key = '_transient_' . $this->receiptKey($request);
        $before = (int) wp_count_posts('words')->draft;
        $fault = static function (string $sql) use ($key): string {
            return str_starts_with($sql, 'INSERT IGNORE') && str_contains($sql, $key)
                ? 'SELECT * FROM ll_recorder_missing_table' : $sql;
        };
        add_filter('query', $fault);
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $response = $this->request('ll_prepare_new_word_recording_handler', $request);
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($suppressed);
        }
        $this->assertFalse($response['success']);
        $this->assertSame('recorder_receipt_unavailable', $response['data']['code']);
        $this->assertSame($before, (int) wp_count_posts('words')->draft);
    }

    public function test_failed_receipt_completion_never_authorizes_duplicate_creation(): void
    {
        global $wpdb;
        $request = $this->prepareRequest();
        $key = '_transient_' . $this->receiptKey($request);
        $before = (int) wp_count_posts('words')->draft;
        $fault = static function (string $sql) use ($key): string {
            return str_starts_with($sql, 'UPDATE ') && str_contains($sql, $key)
                ? 'SELECT * FROM ll_recorder_missing_table' : $sql;
        };
        add_filter('query', $fault);
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $response = $this->request('ll_prepare_new_word_recording_handler', $request);
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($suppressed);
        }
        $this->assertFalse($response['success']);
        $this->assertArrayNotHasKey('safe_to_retry', $response);
        $this->assertSame($before + 1, (int) wp_count_posts('words')->draft);
        $retry = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertFalse($retry['success']);
        $this->assertSame('recorder_preparation_incomplete', $retry['data']['code']);
        $this->assertSame($before + 1, (int) wp_count_posts('words')->draft);
    }

    public function test_matching_existing_pending_receipt_is_not_a_new_claim(): void
    {
        $request = $this->prepareRequest();
        $context = ll_tools_recorder_prepare_begin($request);
        $this->assertIsArray($context);
        try {
            $this->assertFalse(ll_tools_recorder_prepare_receipt_write($context['key'], $context['receipt'], null));
        } finally {
            ll_tools_recorder_mutation_unlock($context['lock']);
        }
    }

    public function test_text_metadata_read_failure_does_not_overwrite_existing_values(): void
    {
        global $wpdb;
        $request = $this->prepareRequest();
        $prepared = $this->request('ll_prepare_new_word_recording_handler', $request);
        $word = $prepared['data']['word'];
        $fault = static function (string $sql) use ($wpdb): string {
            return str_starts_with($sql, 'SELECT ') && str_contains($sql, "FROM {$wpdb->postmeta}")
                ? 'SELECT * FROM ll_recorder_missing_table' : $sql;
        };
        add_filter('query', $fault);
        $suppressed = $wpdb->suppress_errors(true);
        try {
            $response = $this->request('ll_update_new_word_text_handler', [
                'word_id' => $word['word_id'], 'word_text_target' => 'Should not save',
                'word_text_translation' => '', 'expected_text_revision' => $word['text_revision'],
            ]);
        } finally {
            remove_filter('query', $fault);
            $wpdb->suppress_errors($suppressed);
            clean_post_cache((int) $word['word_id']);
        }
        $this->assertFalse($response['success']);
        $this->assertSame('Merhaba', ll_tools_get_word_target_text((int) $word['word_id']));
        $this->assertSame('Hello', ll_tools_word_grid_resolve_display_text((int) $word['word_id'])['translation_text']);
    }

    public function test_unchanged_canonical_save_checks_revision_without_writing_text(): void
    {
        $request = $this->prepareRequest();
        $prepared = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertTrue($prepared['success']);
        $word_id = (int) $prepared['data']['word']['word_id'];
        $before = $this->request('ll_get_new_word_text_handler', ['word_id' => $word_id]);
        $this->assertTrue($before['success']);
        $mutation = [
            'word_id' => $word_id,
            'word_text_target' => $before['data']['word_text_target'],
            'word_text_translation' => $before['data']['word_translation'],
            'expected_text_revision' => $before['data']['text_revision'],
        ];

        $title_writes = 0;
        $meta_writes = 0;
        $capture_title_write = static function (int $post_id) use ($word_id, &$title_writes): void {
            if ($post_id === $word_id) {
                $title_writes++;
            }
        };
        $capture_meta_write = static function ($check, int $post_id, string $key) use ($word_id, &$meta_writes) {
            if ($post_id === $word_id && in_array($key, [
                LL_TOOLS_WORD_TARGET_TEXT_META_KEY, LL_TOOLS_WORD_TRANSLATIONS_META_KEY, 'word_translation',
            ], true)) {
                $meta_writes++;
            }
            return $check;
        };
        add_action('save_post_words', $capture_title_write);
        foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $hook) {
            add_filter($hook, $capture_meta_write, 10, 3);
        }
        try {
            $unchanged = $this->request('ll_update_new_word_text_handler', $mutation);
            $this->assertSame($before, $unchanged);

            $stale = $this->request('ll_update_new_word_text_handler', [
                'expected_text_revision' => str_repeat('0', 64),
            ] + $mutation);
            $this->assertFalse($stale['success']);
            $this->assertSame('recorder_text_conflict', $stale['data']['code']);
            $this->assertSame($before['data'], $stale['data']['current']);
            $this->assertSame(0, $title_writes);
            $this->assertSame(0, $meta_writes);

            $clear = $this->request('ll_update_new_word_text_handler', [
                'word_text_translation' => '',
            ] + $mutation);
            $this->assertTrue($clear['success']);
            $this->assertSame('', $clear['data']['word_translation']);
            $this->assertSame(0, $title_writes);
            $this->assertGreaterThan(0, $meta_writes);

            $meta_writes = 0;
            $clear_again = $this->request('ll_update_new_word_text_handler', [
                'word_text_translation' => '',
                'expected_text_revision' => $clear['data']['text_revision'],
            ] + $mutation);
            $this->assertSame($clear, $clear_again);
            $this->assertSame(0, $title_writes);
            $this->assertSame(0, $meta_writes);
        } finally {
            remove_action('save_post_words', $capture_title_write);
            foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $hook) {
                remove_filter($hook, $capture_meta_write, 10);
            }
        }
    }

    public function test_matching_legacy_display_text_still_updates_canonical_storage(): void
    {
        $request = $this->prepareRequest();
        $prepared = $this->request('ll_prepare_new_word_recording_handler', $request);
        $this->assertTrue($prepared['success']);
        $word_id = (int) $prepared['data']['word']['word_id'];
        delete_post_meta($word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY);
        $this->assertSame('', ll_tools_get_word_target_text($word_id, false));

        $before = $this->request('ll_get_new_word_text_handler', ['word_id' => $word_id]);
        $this->assertTrue($before['success']);
        $this->assertSame('Merhaba', $before['data']['word_text_target']);
        $saved = $this->request('ll_update_new_word_text_handler', [
            'word_id' => $word_id,
            'word_text_target' => $before['data']['word_text_target'],
            'expected_text_revision' => $before['data']['text_revision'],
        ]);
        $this->assertTrue($saved['success']);
        $this->assertSame('Merhaba', ll_tools_get_word_target_text($word_id, false));
        $this->assertSame($before['data']['word_translation'], $saved['data']['word_translation']);
    }

    public function test_two_tabs_and_explicit_conflict_resolution_use_fresh_revisions(): void
    {
        $request = $this->prepareRequest();
        $prepared = $this->request('ll_prepare_new_word_recording_handler', $request);
        $word = $prepared['data']['word'];
        $first = $this->request('ll_update_new_word_text_handler', [
            'word_id' => $word['word_id'], 'word_text_target' => 'First tab',
            'word_text_translation' => '', 'expected_text_revision' => $word['text_revision'],
        ]);
        $this->assertTrue($first['success']);
        $this->assertNotSame($word['text_revision'], $first['data']['text_revision']);
        $staleRequest = [
            'word_id' => $word['word_id'], 'word_text_target' => 'Second tab',
            'word_text_translation' => 'Retained draft', 'expected_text_revision' => $word['text_revision'],
        ];
        $second = $this->request('ll_update_new_word_text_handler', $staleRequest);
        $this->assertFalse($second['success']);
        $this->assertSame($first['data'], $second['data']['current']);
        $read = $this->request('ll_get_new_word_text_handler', ['word_id' => $word['word_id']]);
        $this->assertTrue($read['success']);
        $this->assertSame($first['data'], $read['data']);
        $staleRequest['expected_text_revision'] = $read['data']['text_revision'];
        $resolved = $this->request('ll_update_new_word_text_handler', $staleRequest);
        $this->assertTrue($resolved['success']);
        $this->assertSame('Second tab', $resolved['data']['word_text_target']);
        $this->assertSame('Retained draft', $resolved['data']['word_translation']);
    }

    public function test_read_recovery_does_not_leak_unrelated_words_to_recorder(): void
    {
        $request = $this->prepareRequest();
        $prepared = $this->request('ll_prepare_new_word_recording_handler', $request);
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'audio_recorder']));
        $read = $this->request('ll_get_new_word_text_handler', ['word_id' => $prepared['data']['word']['word_id']]);
        $this->assertFalse($read['success']);
        $this->assertArrayNotHasKey('word_text_target', (array) $read['data']);
    }

    public function test_another_connection_blocks_text_mutation_until_ownership_is_released(): void
    {
        global $wpdb;
        $request = $this->prepareRequest();
        $prepared = $this->request('ll_prepare_new_word_recording_handler', $request);
        $word = $prepared['data']['word'];
        $lock_name = 'll_recorder_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->options . ':word:' . $word['word_id']), 0, 48);
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)));
        $mutation = [
            'word_id' => $word['word_id'], 'word_text_target' => 'After admission',
            'word_text_translation' => 'Hello', 'expected_text_revision' => $word['text_revision'],
        ];
        try {
            $blocked = $this->request('ll_update_new_word_text_handler', $mutation);
            $this->assertFalse($blocked['success']);
            $this->assertSame('recorder_text_busy', $blocked['data']['code']);
            $this->assertSame('Merhaba', ll_tools_get_word_target_text((int) $word['word_id']));
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
            $other->close();
        }
        $retried = $this->request('ll_update_new_word_text_handler', $mutation);
        $this->assertTrue($retried['success']);
        $this->assertSame('After admission', $retried['data']['word_text_target']);
    }
}
