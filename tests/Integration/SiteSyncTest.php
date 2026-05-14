<?php
declare(strict_types=1);

final class SiteSyncTest extends LL_Tools_TestCase
{
    public function test_snapshot_endpoint_exports_transcription_records_with_sync_ids(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'Sync Wordset', 'sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Sync Category', 'sync-category');
        $recording_type_id = $this->ensure_term('recording_type', 'Isolation', 'isolation');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Sync Word', 'Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Sync Recording', ['recording_ipa' => 'old.ipa']);
        wp_set_object_terms($recording_id, [$recording_type_id], 'recording_type');

        wp_set_current_user($admin_id);

        $response = $this->dispatch_rest_request('GET', '/ll-tools/v1/wordsets/sync-wordset/site-sync/snapshot');

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame('transcriptions', (string) ($data['surface'] ?? ''));
        $this->assertSame(1, (int) ($data['record_count'] ?? 0));

        $record = (array) (($data['records'] ?? [])[0] ?? []);
        $this->assertNotSame('', (string) ($record['sync_id'] ?? ''));
        $this->assertSame('old.ipa', (string) (($record['values'] ?? [])['recording_ipa'] ?? ''));
        $this->assertSame(['isolation'], array_values((array) (($record['recording'] ?? [])['types'] ?? [])));
        $this->assertNotSame('', (string) get_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), true));
    }

    public function test_snapshot_endpoint_pages_records_and_can_skip_media(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'Paged Sync Wordset', 'paged-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Paged Sync Category', 'paged-sync-category');
        $first_word_id = $this->create_word($wordset_id, [$category_id], 'Paged Sync Word A', 'A');
        $second_word_id = $this->create_word($wordset_id, [$category_id], 'Paged Sync Word B', 'B');
        $this->create_recording($first_word_id, 'Paged Sync Recording A', ['recording_ipa' => 'a.ipa']);
        $this->create_recording($second_word_id, 'Paged Sync Recording B', ['recording_ipa' => 'b.ipa']);

        wp_set_current_user($admin_id);

        $response = $this->dispatch_rest_request('GET', '/ll-tools/v1/wordsets/paged-sync-wordset/site-sync/snapshot', [
            'per_page' => 1,
            'offset' => 1,
            'include_media' => 0,
        ]);

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertSame(2, (int) ($data['record_count'] ?? 0));
        $this->assertSame(1, (int) ($data['records_returned'] ?? 0));
        $this->assertFalse((bool) ($data['include_media'] ?? true));
        $this->assertFalse((bool) (($data['pagination'] ?? [])['has_more'] ?? true));

        $record = (array) (($data['records'] ?? [])[0] ?? []);
        $this->assertArrayNotHasKey('media', $record);
    }

    public function test_push_plan_separates_clean_updates_from_conflicts(): void
    {
        $base = $this->snapshot([
            $this->record('shared-clean', 101, 'Word A', 'Recording A', ['recording_ipa' => 'base.clean']),
            $this->record('shared-conflict', 102, 'Word B', 'Recording B', ['recording_ipa' => 'base.conflict']),
        ]);
        $local = $this->snapshot([
            $this->record('shared-clean', 201, 'Word A', 'Recording A', ['recording_ipa' => 'local.clean']),
            $this->record('shared-conflict', 202, 'Word B', 'Recording B', ['recording_ipa' => 'local.conflict']),
        ]);
        $remote = $this->snapshot([
            $this->record('shared-clean', 301, 'Word A', 'Recording A', ['recording_ipa' => 'base.clean']),
            $this->record('shared-conflict', 302, 'Word B', 'Recording B', ['recording_ipa' => 'remote.conflict']),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        $this->assertSame(1, count((array) $plan['remote_updates']));
        $this->assertSame(301, (int) $plan['remote_updates'][0]['recording_id']);
        $this->assertSame('local.clean', (string) $plan['remote_updates'][0]['recording_ipa']);
        $this->assertSame(1, count((array) $plan['conflicts']));
        $this->assertSame('recording_ipa', (string) $plan['conflicts'][0]['field']);
        $this->assertSame(1, count((array) $plan['conflict_review_updates']));
        $this->assertStringContainsString('local.conflict', (string) $plan['conflict_review_updates'][0]['review_note']);
        $this->assertStringContainsString('remote.conflict', (string) $plan['conflict_review_updates'][0]['review_note']);
    }

    public function test_push_plan_does_not_requeue_already_flagged_conflict_review(): void
    {
        $base = $this->snapshot([
            $this->record('shared-flagged-conflict', 102, 'Word B', 'Recording B', ['recording_ipa' => 'base.conflict']),
        ]);
        $local = $this->snapshot([
            $this->record('shared-flagged-conflict', 202, 'Word B', 'Recording B', ['recording_ipa' => 'local.conflict']),
        ]);
        $remote = $this->snapshot([
            $this->record('shared-flagged-conflict', 302, 'Word B', 'Recording B', ['recording_ipa' => 'remote.conflict']),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);
        $this->assertSame(1, count((array) $plan['conflict_review_updates']));

        $remote_record = (array) $remote['records'][0];
        $review_update = (array) $plan['conflict_review_updates'][0];
        foreach (['needs_review', 'review_fields', 'review_note'] as $field) {
            $remote_record['values'][$field] = $review_update[$field];
        }
        $remote_record['values'] = ll_tools_site_sync_normalize_record_values((array) $remote_record['values']);
        $remote_record['value_hash'] = ll_tools_site_sync_value_hash((array) $remote_record['values']);
        $remote = $this->snapshot([$remote_record]);

        $flagged_plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        $this->assertSame(1, count((array) $flagged_plan['conflicts']));
        $this->assertSame(0, count((array) $flagged_plan['conflict_review_updates']));
    }

    public function test_recording_text_is_sanitized_for_site_sync_comparison(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Sanitized Sync Wordset', 'sanitized-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Sanitized Sync Category', 'sanitized-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Sanitized Word', 'Sanitized Translation');
        $recording_id = $this->create_recording($word_id, 'Sanitized Recording', [
            'recording_text' => 'Yo ho ça da?',
        ]);

        $values = ll_tools_site_sync_record_values($recording_id, $wordset_id);

        $this->assertSame('Yo ho ça da', (string) ($values['recording_text'] ?? ''));
    }

    public function test_push_local_conflict_updates_include_staging_conflict_values(): void
    {
        $base = $this->snapshot([
            $this->record('shared-conflict-local-wins', 102, 'Word B', 'Recording B', ['recording_ipa' => 'base.conflict']),
        ]);
        $local = $this->snapshot([
            $this->record('shared-conflict-local-wins', 202, 'Word B', 'Recording B', ['recording_ipa' => 'local.conflict']),
        ]);
        $remote = $this->snapshot([
            $this->record('shared-conflict-local-wins', 302, 'Word B', 'Recording B', ['recording_ipa' => 'remote.conflict']),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);
        $updates = ll_tools_site_sync_push_local_conflict_updates($plan);

        $this->assertSame(1, count($updates));
        $this->assertSame(302, (int) ($updates[0]['recording_id'] ?? 0));
        $this->assertSame('local.conflict', (string) ($updates[0]['recording_ipa'] ?? ''));
    }

    public function test_remote_transcription_updates_are_sent_in_small_batches(): void
    {
        $requests = [];
        $http_filter = static function ($preempt, array $args, string $url) use (&$requests) {
            unset($preempt, $url);
            $body = json_decode((string) ($args['body'] ?? ''), true);
            $updates = array_values((array) ($body['updates'] ?? []));
            $requests[] = $updates;

            return [
                'headers' => [],
                'body' => wp_json_encode([
                    'matched_count' => count($updates),
                    'updated_count' => count($updates),
                    'updated' => array_map(static function (array $update): array {
                        return [
                            'recording_id' => (int) ($update['recording_id'] ?? 0),
                            'changed' => true,
                        ];
                    }, $updates),
                    'errors' => [],
                ]),
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'cookies' => [],
                'filename' => null,
            ];
        };
        $batch_filter = static function (): int {
            return 2;
        };

        add_filter('pre_http_request', $http_filter, 10, 3);
        add_filter('ll_tools_site_sync_remote_update_batch_size', $batch_filter);

        try {
            $result = ll_tools_site_sync_send_remote_transcription_updates([
                'local_wordset_id' => 1,
                'remote_url' => 'https://example.com',
                'remote_wordset' => 'remote-wordset',
                'remote_username' => 'remote-user',
                'surface' => 'transcriptions',
            ], 'remote-password', [
                ['recording_id' => 1, 'recording_ipa' => 'one'],
                ['recording_id' => 2, 'recording_ipa' => 'two'],
                ['recording_id' => 3, 'recording_ipa' => 'three'],
                ['recording_id' => 4, 'recording_ipa' => 'four'],
                ['recording_id' => 5, 'recording_ipa' => 'five'],
            ]);
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            remove_filter('ll_tools_site_sync_remote_update_batch_size', $batch_filter);
        }

        $this->assertIsArray($result);
        $this->assertSame(5, (int) ($result['updated_count'] ?? 0));
        $this->assertSame(3, (int) (($result['batch'] ?? [])['request_count'] ?? 0));
        $this->assertSame([2, 2, 1], array_map('count', $requests));
    }

    public function test_apply_push_batch_processes_update_and_reports_done(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Apply Batch Wordset', 'apply-batch-wordset');
        $category_id = $this->ensure_term('word-category', 'Apply Batch Category', 'apply-batch-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Apply Batch Word', 'Apply Batch Translation');
        $recording_id = $this->create_recording($word_id, 'Apply Batch Recording', [
            'recording_ipa' => 'baseline.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'apply-batch-recording');

        $connection = [
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ];
        update_option(ll_tools_site_sync_connection_option_name(), $connection, false);
        $base = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true, [
            'include_media' => false,
        ]);
        $this->assertIsArray($base);
        ll_tools_site_sync_save_base_snapshot($connection, $base);

        $remote_record = (array) (($base['records'] ?? [])[0] ?? []);
        $remote_record['recording']['id'] = 9001;
        update_post_meta($recording_id, 'recording_ipa', 'local.ipa');

        $http_filter = static function ($preempt, array $args, string $url) use (&$remote_record) {
            unset($preempt);
            if (str_contains($url, '/site-sync/snapshot')) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'schema_version' => LL_TOOLS_SITE_SYNC_SCHEMA_VERSION,
                        'surface' => 'transcriptions',
                        'generated_at_gmt' => gmdate('c'),
                        'wordset' => ['id' => 1, 'slug' => 'remote-wordset', 'name' => 'Remote Wordset'],
                        'record_count' => 1,
                        'records_returned' => 1,
                        'include_media' => false,
                        'records' => [$remote_record],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if (str_contains($url, '/transcriptions')) {
                $body = json_decode((string) ($args['body'] ?? ''), true);
                $updates = array_values((array) ($body['updates'] ?? []));
                foreach ($updates as $update) {
                    foreach (['recording_text', 'recording_ipa', 'needs_review', 'review_fields', 'review_note'] as $field) {
                        if (array_key_exists($field, $update)) {
                            $remote_record['values'][$field] = $update[$field];
                        }
                    }
                }
                $remote_record['values'] = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
                $remote_record['value_hash'] = ll_tools_site_sync_value_hash((array) ($remote_record['values'] ?? []));

                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'matched_count' => count($updates),
                        'updated_count' => count($updates),
                        'updated' => [],
                        'errors' => [],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            return false;
        };

        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            $result = ll_tools_site_sync_apply_push_batch($connection, 'remote-password', 'skip');
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
        }

        $this->assertSame([], (array) ($result['errors'] ?? []));
        $this->assertSame(1, (int) (($result['progress'] ?? [])['sent_remote_updates'] ?? 0));
        $this->assertTrue((bool) (($result['progress'] ?? [])['done'] ?? false));
        $this->assertSame(0, (int) (($result['progress'] ?? [])['next_remote_updates'] ?? -1));
    }

    public function test_apply_push_batch_keeps_remote_only_changes_out_of_next_push_across_conflict_modes(): void
    {
        foreach (['skip', 'flag', 'push_local', 'accept_live'] as $conflict_mode) {
            $result = $this->run_push_base_guard_batch($conflict_mode);
            $progress = (array) ($result['result']['progress'] ?? []);

            $this->assertSame([], (array) ($result['result']['errors'] ?? []), $conflict_mode);
            $this->assertSame(1, (int) ($progress['sent_remote_updates'] ?? 0), $conflict_mode);
            $this->assertSame(0, (int) ($progress['next_remote_updates'] ?? -1), $conflict_mode);
            $this->assertSame(
                'pulled.remote',
                $this->base_recording_ipa_for_sync_id($result['base_snapshot'], 'push-base-guard-remote-' . $conflict_mode),
                $conflict_mode
            );

            if ($conflict_mode === 'skip' || $conflict_mode === 'flag') {
                $this->assertSame(1, (int) ($progress['next_conflicts'] ?? 0), $conflict_mode);
                $this->assertSame(
                    'base.conflict',
                    $this->base_recording_ipa_for_sync_id($result['base_snapshot'], 'push-base-guard-conflict-' . $conflict_mode),
                    $conflict_mode
                );
            } elseif ($conflict_mode === 'push_local') {
                $this->assertSame(0, (int) ($progress['next_conflicts'] ?? -1), $conflict_mode);
                $this->assertSame(
                    'local.conflict',
                    $this->base_recording_ipa_for_sync_id($result['base_snapshot'], 'push-base-guard-conflict-' . $conflict_mode),
                    $conflict_mode
                );
            } else {
                $this->assertSame(0, (int) ($progress['next_conflicts'] ?? -1), $conflict_mode);
                $this->assertSame(
                    'live.conflict',
                    $this->base_recording_ipa_for_sync_id($result['base_snapshot'], 'push-base-guard-conflict-' . $conflict_mode),
                    $conflict_mode
                );
            }

            $this->assertSame(
                'local.clean',
                $this->base_recording_text_for_sync_id($result['base_snapshot'], 'push-base-guard-conflict-' . $conflict_mode),
                $conflict_mode
            );
        }
    }

    /**
     * @return array{result:array<string,mixed>,base_snapshot:array<string,mixed>}
     */
    private function run_push_base_guard_batch(string $conflict_mode): array
    {
        $slug = sanitize_title('push-base-guard-' . $conflict_mode);
        $wordset_id = $this->ensure_term('wordset', 'Push Base Guard Wordset ' . $conflict_mode, $slug . '-wordset');
        $category_id = $this->ensure_term('word-category', 'Push Base Guard Category ' . $conflict_mode, $slug . '-category');
        $conflict_word_id = $this->create_word($wordset_id, [$category_id], 'Push Base Guard Conflict ' . $conflict_mode, 'Conflict');
        $remote_word_id = $this->create_word($wordset_id, [$category_id], 'Push Base Guard Remote ' . $conflict_mode, 'Remote');
        $conflict_recording_id = $this->create_recording($conflict_word_id, 'Push Base Guard Conflict Recording ' . $conflict_mode, [
            'recording_text' => 'base.clean',
            'recording_ipa' => 'base.conflict',
        ]);
        $remote_recording_id = $this->create_recording($remote_word_id, 'Push Base Guard Remote Recording ' . $conflict_mode, [
            'recording_ipa' => 'pulled.remote',
        ]);
        update_post_meta($conflict_recording_id, ll_tools_site_sync_uuid_meta_key(), 'push-base-guard-conflict-' . $conflict_mode);
        update_post_meta($remote_recording_id, ll_tools_site_sync_uuid_meta_key(), 'push-base-guard-remote-' . $conflict_mode);

        $connection = [
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ];
        update_option(ll_tools_site_sync_connection_option_name(), $connection, false);

        $base = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true, [
            'include_media' => false,
        ]);
        $this->assertIsArray($base);
        ll_tools_site_sync_save_base_snapshot($connection, $base);

        $remote_records = [];
        foreach ((array) ($base['records'] ?? []) as $record) {
            $record = (array) $record;
            if ((string) ($record['sync_id'] ?? '') === 'push-base-guard-conflict-' . $conflict_mode) {
                $record['recording']['id'] = 9101;
                $record['values']['recording_ipa'] = 'live.conflict';
                $record['values'] = ll_tools_site_sync_normalize_record_values((array) $record['values']);
                $record['value_hash'] = ll_tools_site_sync_value_hash((array) $record['values']);
            } elseif ((string) ($record['sync_id'] ?? '') === 'push-base-guard-remote-' . $conflict_mode) {
                $record['recording']['id'] = 9102;
                $record['values']['recording_ipa'] = 'live.remote';
                $record['values'] = ll_tools_site_sync_normalize_record_values((array) $record['values']);
                $record['value_hash'] = ll_tools_site_sync_value_hash((array) $record['values']);
            }
            $remote_records[] = $record;
        }
        update_post_meta($conflict_recording_id, 'recording_text', 'local.clean');
        update_post_meta($conflict_recording_id, 'recording_ipa', 'local.conflict');

        $http_filter = static function ($preempt, array $args, string $url) use (&$remote_records) {
            unset($preempt);
            if (str_contains($url, '/site-sync/snapshot')) {
                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'schema_version' => LL_TOOLS_SITE_SYNC_SCHEMA_VERSION,
                        'surface' => 'transcriptions',
                        'generated_at_gmt' => gmdate('c'),
                        'wordset' => ['id' => 1, 'slug' => 'remote-wordset', 'name' => 'Remote Wordset'],
                        'record_count' => count($remote_records),
                        'records_returned' => count($remote_records),
                        'include_media' => false,
                        'records' => $remote_records,
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            if (str_contains($url, '/transcriptions')) {
                $body = json_decode((string) ($args['body'] ?? ''), true);
                $updates = array_values((array) ($body['updates'] ?? []));
                foreach ($updates as $update) {
                    $recording_id = (int) ($update['recording_id'] ?? 0);
                    foreach ($remote_records as &$remote_record) {
                        if ((int) (($remote_record['recording'] ?? [])['id'] ?? 0) !== $recording_id) {
                            continue;
                        }
                        foreach (['recording_text', 'recording_ipa', 'needs_review', 'review_fields', 'review_note'] as $field) {
                            if (array_key_exists($field, $update)) {
                                $remote_record['values'][$field] = $update[$field];
                            }
                        }
                        $remote_record['values'] = ll_tools_site_sync_normalize_record_values((array) ($remote_record['values'] ?? []));
                        $remote_record['value_hash'] = ll_tools_site_sync_value_hash((array) ($remote_record['values'] ?? []));
                    }
                    unset($remote_record);
                }

                return [
                    'headers' => [],
                    'body' => wp_json_encode([
                        'matched_count' => count($updates),
                        'updated_count' => count($updates),
                        'updated' => [],
                        'errors' => [],
                    ]),
                    'response' => ['code' => 200, 'message' => 'OK'],
                    'cookies' => [],
                    'filename' => null,
                ];
            }

            return false;
        };

        add_filter('pre_http_request', $http_filter, 10, 3);
        try {
            $result = ll_tools_site_sync_apply_push_batch($connection, 'remote-password', $conflict_mode);
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
        }

        return [
            'result' => is_array($result) ? $result : [],
            'base_snapshot' => ll_tools_site_sync_get_base_snapshot($connection),
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function base_recording_text_for_sync_id(array $snapshot, string $sync_id): string
    {
        return $this->base_value_for_sync_id($snapshot, $sync_id, 'recording_text');
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function base_recording_ipa_for_sync_id(array $snapshot, string $sync_id): string
    {
        return $this->base_value_for_sync_id($snapshot, $sync_id, 'recording_ipa');
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function base_value_for_sync_id(array $snapshot, string $sync_id, string $field): string
    {
        foreach ((array) ($snapshot['records'] ?? []) as $record) {
            if (!is_array($record) || (string) ($record['sync_id'] ?? '') !== $sync_id) {
                continue;
            }

            $values = ll_tools_site_sync_normalize_record_values((array) ($record['values'] ?? []));
            return is_scalar($values[$field] ?? '') ? (string) $values[$field] : '';
        }

        return '';
    }

    public function test_remote_snapshot_fetch_combines_paged_responses(): void
    {
        $offsets = [];
        $include_media_values = [];
        $http_filter = static function ($preempt, array $args, string $url) use (&$offsets, &$include_media_values) {
            unset($preempt, $args);
            $query = [];
            parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
            $offset = max(0, (int) ($query['offset'] ?? 0));
            $offsets[] = $offset;
            $include_media_values[] = (string) ($query['include_media'] ?? '');

            $record = [
                'record_type' => 'word_audio_transcription',
                'sync_id' => 'remote-' . $offset,
                'natural_key' => 'remote-' . $offset,
                'word' => [
                    'slug' => 'remote-word-' . $offset,
                ],
                'recording' => [
                    'id' => 100 + $offset,
                    'slug' => 'remote-recording-' . $offset,
                    'types' => [],
                ],
                'values' => ll_tools_site_sync_normalize_record_values([
                    'recording_ipa' => 'remote.' . $offset,
                ]),
            ];

            return [
                'headers' => [],
                'body' => wp_json_encode([
                    'record_count' => 2,
                    'records_returned' => 1,
                    'records' => [$record],
                    'pagination' => [
                        'limit' => 1,
                        'offset' => $offset,
                        'returned_count' => 1,
                        'total_count' => 2,
                        'has_more' => $offset === 0,
                        'next_offset' => $offset === 0 ? 1 : null,
                    ],
                ]),
                'response' => [
                    'code' => 200,
                    'message' => 'OK',
                ],
                'cookies' => [],
                'filename' => null,
            ];
        };
        $per_page_filter = static function (): int {
            return 1;
        };

        add_filter('pre_http_request', $http_filter, 10, 3);
        add_filter('ll_tools_site_sync_remote_snapshot_per_page', $per_page_filter);

        try {
            $snapshot = ll_tools_site_sync_fetch_remote_snapshot([
                'local_wordset_id' => 1,
                'remote_url' => 'https://example.com',
                'remote_wordset' => 'remote-wordset',
                'remote_username' => 'remote-user',
                'surface' => 'transcriptions',
            ], 'remote-password', false);
        } finally {
            remove_filter('pre_http_request', $http_filter, 10);
            remove_filter('ll_tools_site_sync_remote_snapshot_per_page', $per_page_filter);
        }

        $this->assertIsArray($snapshot);
        $this->assertSame(2, (int) ($snapshot['record_count'] ?? 0));
        $this->assertSame(2, (int) ($snapshot['records_returned'] ?? 0));
        $this->assertSame(['remote-0', 'remote-1'], array_map(static function (array $record): string {
            return (string) ($record['sync_id'] ?? '');
        }, (array) ($snapshot['records'] ?? [])));
        $this->assertSame([0, 1], $offsets);
        $this->assertSame(['0', '0'], $include_media_values);
    }

    public function test_pull_plan_applies_remote_changes_to_local_recordings(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Pull Sync Wordset', 'pull-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Pull Sync Category', 'pull-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Pull Sync Word', 'Pull Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Pull Sync Recording', [
            'recording_text' => 'local text',
            'recording_ipa' => 'local.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'pull-shared-recording');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $remote_record = $this->record('pull-shared-recording', 999, 'Pull Sync Word', 'Pull Sync Recording', [
            'recording_text' => 'remote text?',
            'recording_ipa' => 'remote.ipa',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) $summary['records_updated']);
        $this->assertSame('remote text', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('remote.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
    }

    public function test_pull_links_remote_sync_ids_when_values_already_match(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Link Sync Wordset', 'link-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Link Sync Category', 'link-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Link Sync Word', 'Link Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Link Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $remote_record = $this->record('remote-link-recording', 999, 'Link Sync Word', 'Link Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote_record['word']['sync_id'] = 'remote-link-word';
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(0, (int) $summary['records_updated']);
        $this->assertSame(2, (int) $summary['sync_ids_linked']);
        $this->assertSame('remote-link-recording', (string) get_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), true));
        $this->assertSame('remote-link-word', (string) get_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), true));
    }

    public function test_pull_creates_missing_local_recording_for_existing_word_with_remote_audio_url(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Create Recording Wordset', 'create-recording-wordset');
        $category_id = $this->ensure_term('word-category', 'Create Recording Category', 'create-recording-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Create Recording Word', 'Create Recording Translation');
        wp_update_post([
            'ID' => $word_id,
            'post_name' => '',
        ]);

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $this->assertSame(0, count((array) ($local['records'] ?? [])));

        $audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/create-recording.mp3';
        $remote_record = $this->record('create-remote-recording', 999, 'Create Recording Word', 'Remote Create Recording', [
            'recording_text' => 'remote text',
            'recording_ipa' => 'remote.ipa',
        ], [
            'audio' => [
                'url' => $audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
        ]);
        $remote_record['word']['sync_id'] = 'create-remote-word';
        $remote_record['word']['slug'] = 'remote-only-create-recording-word';
        $remote_record['recording']['slug'] = 'remote-create-recording';
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);

        $this->assertSame(1, (int) ($plan['stats']['records_to_create'] ?? 0));
        $this->assertSame(0, (int) ($plan['stats']['skipped'] ?? 0));
        $this->assertSame('create_local_recording', (string) ($plan['actions'][0]['type'] ?? ''));
        $this->assertContains('audio_file_path', (array) ($plan['actions'][0]['fields'] ?? []));

        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) ($summary['records_created'] ?? 0));
        $this->assertSame(0, (int) ($summary['records_updated'] ?? 0));
        $this->assertSame(1, (int) ($summary['media_refs_updated'] ?? 0));
        $this->assertSame('create-remote-word', (string) get_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), true));

        $recordings = get_posts([
            'post_type' => 'word_audio',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'post_parent' => $word_id,
            'posts_per_page' => -1,
        ]);
        $this->assertCount(1, $recordings);

        $recording_id = (int) $recordings[0]->ID;
        $this->assertSame('create-remote-recording', (string) get_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), true));
        $this->assertSame('remote text', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('remote.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
        $this->assertSame($audio_url, (string) get_post_meta($recording_id, 'audio_file_path', true));

        $recording_types = wp_get_post_terms($recording_id, 'recording_type', ['fields' => 'slugs']);
        $this->assertSame(['isolation'], array_values((array) $recording_types));

        $after_local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($after_local);
        $after_plan = ll_tools_site_sync_build_pull_plan($after_local, $remote, $remote);
        $this->assertSame(0, count((array) ($after_plan['actions'] ?? [])));
    }

    public function test_pull_creates_missing_local_word_once_for_multiple_remote_recordings(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Create Word Wordset', 'create-word-wordset');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $this->assertSame(0, count((array) ($local['records'] ?? [])));

        $remote_word = [
            'sync_id' => 'remote-created-word',
            'slug' => 'remote-created-word',
            'title' => 'Remote Created Word',
            'status' => 'publish',
            'word_translation' => 'Remote translation',
            'word_english_meaning' => 'Remote English meaning',
            'categories' => [
                [
                    'sync_id' => 'remote-created-category',
                    'slug' => 'remote-created-category',
                    'name' => 'Remote Created Category',
                ],
            ],
        ];
        $first_audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/remote-created-isolation.mp3';
        $second_audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/remote-created-question.mp3';
        $first_remote_record = $this->record('remote-created-recording-isolation', 999, 'Remote Created Word', 'Remote Created Isolation', [
            'recording_text' => 'remote isolation',
            'recording_ipa' => 'remote.iso',
        ], [
            'audio' => [
                'url' => $first_audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
        ]);
        $first_remote_record['word'] = $remote_word;
        $first_remote_record['recording']['slug'] = 'remote-created-isolation';

        $second_remote_record = $this->record('remote-created-recording-question', 1000, 'Remote Created Word', 'Remote Created Question', [
            'recording_text' => 'remote question',
            'recording_ipa' => 'remote.question',
        ], [
            'audio' => [
                'url' => $second_audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
        ]);
        $second_remote_record['word'] = $remote_word;
        $second_remote_record['recording']['slug'] = 'remote-created-question';
        $second_remote_record['recording']['types'] = ['question'];

        $remote = $this->snapshot([$first_remote_record, $second_remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);

        $this->assertSame(1, (int) ($plan['stats']['words_to_create'] ?? 0));
        $this->assertSame(2, (int) ($plan['stats']['records_to_create'] ?? 0));
        $this->assertSame(0, (int) ($plan['stats']['skipped'] ?? 0));

        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) ($summary['words_created'] ?? 0));
        $this->assertSame(2, (int) ($summary['records_created'] ?? 0));
        $this->assertSame(2, (int) ($summary['media_refs_updated'] ?? 0));

        $words = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'title' => 'Remote Created Word',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'wordset',
                    'field' => 'term_id',
                    'terms' => [$wordset_id],
                ],
            ],
        ]);
        $this->assertCount(1, $words);

        $word_id = (int) $words[0];
        $this->assertSame('remote-created-word', (string) get_post_meta($word_id, ll_tools_site_sync_uuid_meta_key(), true));
        $this->assertSame('Remote translation', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertSame('Remote English meaning', (string) get_post_meta($word_id, 'word_english_meaning', true));

        $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        $this->assertCount(1, (array) $category_ids);
        $this->assertSame('remote-created-category', (string) get_term_meta((int) $category_ids[0], ll_tools_site_sync_uuid_meta_key(), true));

        $recordings = get_posts([
            'post_type' => 'word_audio',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'post_parent' => $word_id,
            'posts_per_page' => -1,
            'orderby' => 'post_name',
            'order' => 'ASC',
        ]);
        $this->assertCount(2, $recordings);
        $this->assertSame($first_audio_url, (string) get_post_meta((int) $recordings[0]->ID, 'audio_file_path', true));
        $this->assertSame($second_audio_url, (string) get_post_meta((int) $recordings[1]->ID, 'audio_file_path', true));

        $after_local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($after_local);
        $after_plan = ll_tools_site_sync_build_pull_plan($after_local, $remote, $remote);
        $this->assertSame(0, count((array) ($after_plan['actions'] ?? [])));
    }

    public function test_pull_reparents_recording_when_remote_word_sync_is_different(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Reparent Wordset', 'reparent-wordset');
        $category_id = $this->ensure_term('word-category', 'Reparent Category', 'reparent-category');
        $old_word_id = $this->create_word($wordset_id, [$category_id], 'Shared Title Word', 'Old Translation');
        update_post_meta($old_word_id, ll_tools_site_sync_uuid_meta_key(), 'old-remote-word');
        $recording_id = $this->create_recording($old_word_id, 'Shared Title Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'shared-title-recording');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);

        $remote_record = $this->record('shared-title-recording', 999, 'Shared Title Word', 'Shared Title Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote_record['word']['sync_id'] = 'new-remote-word';
        $remote_record['word']['slug'] = 'shared-title-word-live-copy';
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, $remote);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) ($summary['words_created'] ?? 0));
        $this->assertSame(1, (int) ($summary['recordings_reparented'] ?? 0));
        $this->assertSame('old-remote-word', (string) get_post_meta($old_word_id, ll_tools_site_sync_uuid_meta_key(), true));

        $new_words = get_posts([
            'post_type' => 'words',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'meta_key' => ll_tools_site_sync_uuid_meta_key(),
            'meta_value' => 'new-remote-word',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        $this->assertCount(1, $new_words);
        $this->assertSame((int) $new_words[0], (int) get_post($recording_id)->post_parent);

        $after_local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($after_local);
        $after_plan = ll_tools_site_sync_build_pull_plan($after_local, $remote, $remote);
        $this->assertSame(0, count((array) ($after_plan['actions'] ?? [])));
    }

    public function test_pull_uses_remote_media_urls_when_local_media_is_missing(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Media Sync Wordset', 'media-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Media Sync Category', 'media-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Media Sync Word', 'Media Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Media Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'media-shared-recording');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);

        $audio_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/media-sync.mp3';
        $image_url = 'https://zazacaogren.com/wp-content/uploads/2026/05/media-sync.webp';
        $remote_record = $this->record('media-shared-recording', 999, 'Media Sync Word', 'Media Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ], [
            'audio' => [
                'url' => $audio_url,
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
            'word_image' => [
                'sync_id' => 'remote-media-word-image',
                'slug' => 'media-sync-word-image',
                'title' => 'Media Sync Image',
                'status' => 'publish',
                'attachment' => [
                    'url' => $image_url,
                    'source_url' => $image_url,
                    'mime_type' => 'image/webp',
                    'title' => 'Media Sync Image',
                    'alt' => 'Media alt',
                    'width' => 640,
                    'height' => 480,
                    'has_local_file' => true,
                ],
            ],
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote_record['word']['sync_id'] = (string) (($local['records'][0]['word'] ?? [])['sync_id'] ?? '');
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $this->assertSame(2, (int) ($plan['stats']['media_refs_to_apply'] ?? 0));
        $this->assertContains('audio_file_path', (array) ($plan['actions'][0]['fields'] ?? []));
        $this->assertContains('word_image', (array) ($plan['actions'][0]['fields'] ?? []));

        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(0, (int) $summary['records_updated']);
        $this->assertSame(2, (int) $summary['media_refs_updated']);
        $this->assertSame($audio_url, (string) get_post_meta($recording_id, 'audio_file_path', true));
        $this->assertSame($audio_url, ll_tools_resolve_audio_file_url($audio_url));
        $uploads = wp_get_upload_dir();
        $uploads_path = (string) wp_parse_url((string) ($uploads['baseurl'] ?? ''), PHP_URL_PATH);
        if ($uploads_path !== '') {
            $legacy_local_url = 'http://old-site.local' . $uploads_path . '/legacy-sync.mp3';
            $this->assertSame(trailingslashit((string) $uploads['baseurl']) . 'legacy-sync.mp3', ll_tools_resolve_audio_file_url($legacy_local_url));
        }

        $word_image_id = (int) get_post_meta($word_id, '_ll_autopicked_image_id', true);
        $this->assertGreaterThan(0, $word_image_id);
        $this->assertSame('word_images', get_post_type($word_image_id));
        $this->assertSame('remote-media-word-image', (string) get_post_meta($word_image_id, ll_tools_site_sync_uuid_meta_key(), true));

        $attachment_id = (int) get_post_thumbnail_id($word_image_id);
        $this->assertGreaterThan(0, $attachment_id);
        $this->assertSame($attachment_id, (int) get_post_thumbnail_id($word_id));
        $this->assertSame($image_url, (string) wp_get_attachment_url($attachment_id));
        $this->assertSame($image_url, (string) get_post_meta($attachment_id, '_ll_tools_external_source_url', true));
        $this->assertSame('Media alt', (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
        $this->assertFalse(ll_tools_site_sync_attachment_has_local_file($attachment_id));

        $metadata = wp_get_attachment_metadata($attachment_id);
        $this->assertIsArray($metadata);
        $this->assertSame(640, (int) ($metadata['width'] ?? 0));
        $this->assertSame(480, (int) ($metadata['height'] ?? 0));
    }

    public function test_pull_replaces_existing_review_note_with_empty_remote_note(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Review Sync Wordset', 'review-sync-wordset');
        $category_id = $this->ensure_term('word-category', 'Review Sync Category', 'review-sync-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Review Sync Word', 'Review Sync Translation');
        $recording_id = $this->create_recording($word_id, 'Review Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'review-shared-recording');
        ll_tools_ipa_keyboard_set_recording_review_state($recording_id, true, 'recording_ipa', 'old local note');

        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($local);
        $remote_record = $this->record('review-shared-recording', 999, 'Review Sync Word', 'Review Sync Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'same.ipa',
            'needs_review' => true,
            'review_fields' => [],
            'review_note' => '',
        ]);
        $remote_record['natural_key'] = (string) (($local['records'][0] ?? [])['natural_key'] ?? '');
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, []);
        $summary = ll_tools_site_sync_apply_pull_plan($plan, $wordset_id);

        $this->assertSame(1, (int) $summary['records_updated']);
        $this->assertSame('', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
        $this->assertSame(['recording_ipa'], ll_tools_ipa_keyboard_get_recording_review_field_list($recording_id));
    }

    public function test_pull_base_merge_preserves_old_base_for_conflicted_fields(): void
    {
        $base_record = $this->record('merge-shared', 101, 'Merge Word', 'Merge Recording', ['recording_ipa' => 'base.ipa']);
        $base = $this->snapshot([$base_record]);
        $local = $this->snapshot([
            $this->record('merge-shared', 201, 'Merge Word', 'Merge Recording', ['recording_ipa' => 'local.ipa']),
        ]);
        $remote = $this->snapshot([
            $this->record('merge-shared', 301, 'Merge Word', 'Merge Recording', ['recording_ipa' => 'remote.ipa']),
        ]);

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, $base);
        $merged = ll_tools_site_sync_merge_base_snapshot_after_pull($base, $remote, $plan);

        $this->assertSame(1, count((array) $plan['conflicts']));
        $this->assertSame('base.ipa', (string) (($merged['records'][0]['values'] ?? [])['recording_ipa'] ?? ''));
    }

    public function test_compact_base_snapshot_keeps_merge_data_without_media_payload(): void
    {
        $base_record = $this->record('compact-shared', 101, 'Compact Word', 'Compact Recording', [
            'recording_ipa' => 'base.ipa',
        ], [
            'audio' => [
                'url' => 'https://example.com/audio.mp3',
                'mime_type' => 'audio/mpeg',
                'has_local_file' => true,
            ],
            'word_image' => [
                'attachment' => [
                    'url' => 'https://example.com/image.webp',
                    'source_url' => 'https://example.com/image.webp',
                    'mime_type' => 'image/webp',
                    'width' => 1024,
                    'height' => 1024,
                    'has_local_file' => true,
                ],
            ],
        ]);
        $base = $this->snapshot([$base_record]);

        $compacted = ll_tools_site_sync_compact_base_snapshot($base);
        $local = $this->snapshot([
            $this->record('compact-shared', 201, 'Compact Word', 'Compact Recording', ['recording_ipa' => 'local.ipa']),
        ]);
        $remote = $this->snapshot([
            $this->record('compact-shared', 301, 'Compact Word', 'Compact Recording', ['recording_ipa' => 'remote.ipa']),
        ]);

        $this->assertArrayNotHasKey('media', (array) ($compacted['records'][0] ?? []));
        $this->assertSame('base.ipa', (string) (($compacted['records'][0]['values'] ?? [])['recording_ipa'] ?? ''));
        $this->assertLessThan(strlen(serialize($base)), strlen(serialize($compacted)));

        $plan = ll_tools_site_sync_build_pull_plan($local, $remote, $compacted);

        $this->assertSame(1, count((array) $plan['conflicts']));
        $this->assertSame('base.ipa', (string) ($plan['conflicts'][0]['base_value'] ?? ''));
    }

    public function test_local_change_summary_compares_current_site_to_saved_baseline(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Local Summary Wordset', 'local-summary-wordset');
        $category_id = $this->ensure_term('word-category', 'Local Summary Category', 'local-summary-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Local Summary Word', 'Local Summary Translation');
        $recording_id = $this->create_recording($word_id, 'Local Summary Recording', [
            'recording_text' => 'baseline text',
            'recording_ipa' => 'baseline.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'local-summary-recording');

        $base_snapshot = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true);
        $this->assertIsArray($base_snapshot);

        update_post_meta($recording_id, 'recording_ipa', 'changed local ipa');

        $summary = ll_tools_site_sync_build_local_change_summary([
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ], $base_snapshot);

        $this->assertTrue((bool) ($summary['available'] ?? false));
        $this->assertSame(1, (int) (($summary['stats'] ?? [])['changed_records'] ?? 0));
        $this->assertSame(1, (int) (($summary['field_counts'] ?? [])['recording_ipa'] ?? 0));

        $sample = (array) (($summary['samples'] ?? [])[0] ?? []);
        $change = (array) (($sample['changes'] ?? [])[0] ?? []);
        $this->assertSame('Modified locally', (string) ($sample['status_label'] ?? ''));
        $this->assertSame('recording_ipa', (string) ($change['field'] ?? ''));
        $this->assertSame('baseline.ipa', (string) ($change['before'] ?? ''));
        $this->assertSame('changed local ipa', (string) ($change['after'] ?? ''));
    }

    public function test_local_change_summary_paginates_visible_samples(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Paged Summary Wordset', 'paged-summary-wordset');
        $category_id = $this->ensure_term('word-category', 'Paged Summary Category', 'paged-summary-category');
        $recording_ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $word_id = $this->create_word($wordset_id, [$category_id], 'Paged Summary Word ' . $i, 'Translation ' . $i);
            $recording_id = $this->create_recording($word_id, 'Paged Summary Recording ' . $i, [
                'recording_text' => 'baseline text ' . $i,
                'recording_ipa' => 'baseline.' . $i,
            ]);
            update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'paged-summary-recording-' . $i);
            $recording_ids[] = $recording_id;
        }

        $base_snapshot = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true, [
            'include_media' => false,
        ]);
        $this->assertIsArray($base_snapshot);

        foreach ($recording_ids as $index => $recording_id) {
            update_post_meta($recording_id, 'recording_ipa', 'changed.' . ($index + 1));
        }

        $summary = ll_tools_site_sync_build_local_change_summary([
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ], $base_snapshot, 2, 2);

        $this->assertSame(3, (int) ($summary['sample_total'] ?? 0));
        $this->assertSame(2, (int) ($summary['sample_page'] ?? 0));
        $this->assertCount(1, (array) ($summary['samples'] ?? []));
        $this->assertSame('Paged Summary Word 3', (string) (($summary['samples'][0] ?? [])['word_title'] ?? ''));
    }

    public function test_local_change_revert_and_edit_actions_update_visible_record(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Local Action Wordset', 'local-action-wordset');
        $category_id = $this->ensure_term('word-category', 'Local Action Category', 'local-action-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Local Action Word', 'Local Action Translation');
        $recording_id = $this->create_recording($word_id, 'Local Action Recording', [
            'recording_text' => 'baseline text',
            'recording_ipa' => 'changed.ipa',
        ]);

        $connection = [
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ];
        $previous_post = $_POST;

        try {
            $_POST = [
                'll_site_sync_recording_id' => (string) $recording_id,
                'll_site_sync_fields_json' => wp_json_encode(['recording_ipa']),
                'll_site_sync_before_values_json' => wp_json_encode(['recording_ipa' => 'baseline.ipa']),
            ];
            $revert_result = ll_tools_site_sync_process_local_change_request($connection, 'revert_local_change');
            $this->assertIsArray($revert_result);
            $this->assertSame('baseline.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));

            $_POST = [
                'll_site_sync_recording_id' => (string) $recording_id,
                'll_site_sync_fields_json' => wp_json_encode(['recording_text', 'recording_ipa', 'needs_review', 'review_fields', 'review_note']),
                'll_site_sync_after_values' => [
                    'recording_text' => 'edited text',
                    'recording_ipa' => 'edited.ipa',
                    'review_note' => 'edited note',
                ],
                'll_site_sync_needs_review' => '1',
                'll_site_sync_review_fields' => ['recording_ipa'],
            ];
            $edit_result = ll_tools_site_sync_process_local_change_request($connection, 'edit_local_change');
            $this->assertIsArray($edit_result);
        } finally {
            $_POST = $previous_post;
        }

        $this->assertSame('edited text', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame('edited.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
        $this->assertSame('edited note', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
        $this->assertSame(['recording_ipa'], ll_tools_ipa_keyboard_get_recording_review_field_list($recording_id));
    }

    public function test_local_change_overview_placeholder_defers_saved_baseline_comparison(): void
    {
        $connection = [
            'local_wordset_id' => 123,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ];
        $base_snapshot = $this->snapshot([
            $this->record('deferred-local-overview', 101, 'Deferred Word', 'Deferred Recording', [
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);

        ob_start();
        ll_tools_site_sync_render_local_change_overview_placeholder($connection, $base_snapshot);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-ll-site-sync-local-overview', $html);
        $this->assertStringContainsString('Checking local changes in the background', $html);
        $this->assertStringNotContainsString('Deferred Word', $html);
        $this->assertStringNotContainsString('baseline.ipa', $html);
    }

    public function test_ajax_local_change_overview_returns_saved_baseline_comparison(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_id = $this->ensure_term('wordset', 'Ajax Summary Wordset', 'ajax-summary-wordset');
        $category_id = $this->ensure_term('word-category', 'Ajax Summary Category', 'ajax-summary-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Ajax Summary Word', 'Ajax Summary Translation');
        $recording_id = $this->create_recording($word_id, 'Ajax Summary Recording', [
            'recording_text' => 'baseline text',
            'recording_ipa' => 'baseline.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'ajax-summary-recording');

        $connection = [
            'local_wordset_id' => $wordset_id,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ];
        update_option(ll_tools_site_sync_connection_option_name(), $connection, false);
        $base_snapshot = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true, [
            'include_media' => false,
        ]);
        $this->assertIsArray($base_snapshot);
        ll_tools_site_sync_save_base_snapshot($connection, $base_snapshot);

        update_post_meta($recording_id, 'recording_ipa', 'changed ajax ipa');
        wp_set_current_user($admin_id);

        $previous_post = $_POST;
        $previous_request = $_REQUEST;
        $_POST['nonce'] = wp_create_nonce('ll_tools_site_sync_local_overview');
        $_REQUEST['nonce'] = $_POST['nonce'];

        try {
            $payload = $this->run_json_endpoint(static function (): void {
                ll_tools_site_sync_ajax_local_overview();
            });
        } finally {
            $_POST = $previous_post;
            $_REQUEST = $previous_request;
        }

        $this->assertTrue((bool) ($payload['success'] ?? false));
        $html = (string) (($payload['data'] ?? [])['html'] ?? '');
        $this->assertStringContainsString('Ajax Summary Word', $html);
        $this->assertStringContainsString('changed', $html);
        $this->assertStringContainsString('ajax', $html);
        $this->assertStringContainsString('ipa', $html);
    }

    public function test_site_sync_preview_renders_media_and_before_after_values(): void
    {
        $base = $this->snapshot([
            $this->record('rich-preview', 101, 'Preview Word', 'Preview Recording', [
                'recording_text' => 'baseline text',
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);
        $local = $this->snapshot([
            $this->record('rich-preview', 201, 'Preview Word', 'Preview Recording', [
                'recording_text' => 'changed local text',
                'recording_ipa' => 'baseline.ipa',
            ], [
                'audio' => [
                    'url' => 'https://example.com/audio.mp3',
                    'mime_type' => 'audio/mpeg',
                    'has_local_file' => false,
                ],
                'word_image' => [
                    'attachment' => [
                        'source_url' => 'https://example.com/image.webp',
                        'url' => 'https://example.com/image.webp',
                        'mime_type' => 'image/webp',
                    ],
                ],
            ]),
        ]);
        $remote = $this->snapshot([
            $this->record('rich-preview', 301, 'Preview Word', 'Preview Recording', [
                'recording_text' => 'baseline text',
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        ob_start();
        ll_tools_site_sync_render_plan_summary($plan);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Preview Word', $html);
        $this->assertStringContainsString('Live now', $html);
        $this->assertStringContainsString('After push', $html);
        $this->assertStringContainsString('Revert local change', $html);
        $this->assertStringContainsString('Edit after-change state', $html);
        $this->assertStringContainsString('baseline', $html);
        $this->assertStringContainsString('changed', $html);
        $this->assertStringContainsString('local', $html);
        $this->assertStringContainsString('ll-site-sync-diff-added', $html);
        $this->assertStringContainsString('<audio controls', $html);
        $this->assertStringContainsString('https://example.com/image.webp', $html);
    }

    public function test_live_comparison_preview_emphasizes_conflicts(): void
    {
        $base = $this->snapshot([
            $this->record('conflict-preview', 101, 'Conflict Word', 'Conflict Recording', [
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);
        $local = $this->snapshot([
            $this->record('conflict-preview', 201, 'Conflict Word', 'Conflict Recording', [
                'recording_ipa' => 'staging.ipa',
            ]),
        ]);
        $remote = $this->snapshot([
            $this->record('conflict-preview', 301, 'Conflict Word', 'Conflict Recording', [
                'recording_ipa' => 'live.ipa',
            ]),
        ]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);
        $conflict_items = ll_tools_site_sync_conflict_change_items($plan);
        $this->assertSame('Live now', (string) ($conflict_items[0]['before_label'] ?? ''));
        $this->assertSame('After push', (string) ($conflict_items[0]['after_label'] ?? ''));
        $this->assertSame('live.ipa', (string) (($conflict_items[0]['changes'][0] ?? [])['before'] ?? ''));
        $this->assertSame('staging.ipa', (string) (($conflict_items[0]['changes'][0] ?? [])['after'] ?? ''));
        $this->assertTrue((bool) ($conflict_items[0]['allow_local_actions'] ?? false));

        ob_start();
        ll_tools_site_sync_render_plan_summary($plan);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Live Comparison Preview', $html);
        $this->assertStringContainsString('fresh live-site snapshot', $html);
        $this->assertStringContainsString('1 conflict needs review', $html);
        $this->assertStringContainsString('Last pulled', $html);
        $this->assertStringContainsString('baseline.ipa', $html);
        $this->assertStringContainsString('staging.ipa', $html);
        $this->assertStringContainsString('live.ipa', $html);
    }

    public function test_apply_push_panel_renders_automatic_batch_controls(): void
    {
        $base = $this->snapshot([
            $this->record('apply-panel', 101, 'Apply Panel Word', 'Apply Panel Recording', [
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);
        $local = $this->snapshot([
            $this->record('apply-panel', 201, 'Apply Panel Word', 'Apply Panel Recording', [
                'recording_ipa' => 'local.ipa',
            ]),
        ]);
        $remote = $this->snapshot([
            $this->record('apply-panel', 301, 'Apply Panel Word', 'Apply Panel Recording', [
                'recording_ipa' => 'baseline.ipa',
            ]),
        ]);
        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);

        ob_start();
        ll_tools_site_sync_render_apply_push_panel([
            'local_wordset_id' => 123,
            'remote_url' => 'https://example.com',
            'remote_wordset' => 'remote-wordset',
            'remote_username' => 'remote-user',
            'surface' => 'transcriptions',
        ], $plan);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-ll-site-sync-apply-form', $html);
        $this->assertStringContainsString('Apply All Push Batches', $html);
        $this->assertStringContainsString('data-ll-site-sync-apply-progress', $html);
    }

    public function test_accept_live_conflicts_updates_local_conflict_fields(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Accept Live Wordset', 'accept-live-wordset');
        $category_id = $this->ensure_term('word-category', 'Accept Live Category', 'accept-live-category');
        $word_id = $this->create_word($wordset_id, [$category_id], 'Accept Live Word', 'Accept Live Translation');
        $recording_id = $this->create_recording($word_id, 'Accept Live Recording', [
            'recording_text' => 'same text',
            'recording_ipa' => 'baseline.ipa',
        ]);
        update_post_meta($recording_id, ll_tools_site_sync_uuid_meta_key(), 'accept-live-recording');

        $base = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true, [
            'include_media' => false,
        ]);
        $this->assertIsArray($base);

        update_post_meta($recording_id, 'recording_ipa', 'local.ipa');
        $local = ll_tools_site_sync_build_snapshot($wordset_id, 'transcriptions', true, [
            'include_media' => false,
        ]);
        $this->assertIsArray($local);

        $remote_record = (array) (($base['records'] ?? [])[0] ?? []);
        $remote_record['values']['recording_ipa'] = 'live.ipa';
        $remote_record['value_hash'] = ll_tools_site_sync_value_hash((array) $remote_record['values']);
        $remote = $this->snapshot([$remote_record]);

        $plan = ll_tools_site_sync_build_push_plan($local, $remote, $base);
        $this->assertSame(1, count((array) ($plan['conflicts'] ?? [])));

        $result = ll_tools_site_sync_accept_live_conflicts_locally($plan, $wordset_id);

        $this->assertSame(1, (int) ($result['fields_updated'] ?? 0));
        $this->assertSame('live.ipa', (string) get_post_meta($recording_id, 'recording_ipa', true));
    }

    public function test_remote_plan_hides_page_load_local_change_overview(): void
    {
        $this->assertTrue(ll_tools_site_sync_should_render_local_change_overview(['plan' => null]));
        $this->assertTrue(ll_tools_site_sync_should_render_local_change_overview([]));
        $this->assertFalse(ll_tools_site_sync_should_render_local_change_overview([
            'plan' => [
                'direction' => 'push',
            ],
        ]));
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertNotWPError($inserted);
        return (int) $inserted['term_id'];
    }

    /**
     * @param array<int,int> $category_ids
     */
    private function create_word(int $wordset_id, array $category_ids, string $title, string $translation): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset');
        wp_set_object_terms($word_id, $category_ids, 'word-category');
        update_post_meta($word_id, 'word_translation', $translation);
        return (int) $word_id;
    }

    /**
     * @param array<string,string> $meta
     */
    private function create_recording(int $word_id, string $title, array $meta): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => $title,
        ]);
        foreach ($meta as $key => $value) {
            update_post_meta($recording_id, $key, $value);
        }
        return (int) $recording_id;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function snapshot(array $records): array
    {
        return [
            'schema_version' => LL_TOOLS_SITE_SYNC_SCHEMA_VERSION,
            'surface' => 'transcriptions',
            'generated_at_gmt' => gmdate('c'),
            'wordset' => ['id' => 1, 'slug' => 'sync', 'name' => 'Sync'],
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function record(string $sync_id, int $recording_id, string $word_title, string $recording_title, array $values, array $media = []): array
    {
        $values = array_merge([
            'recording_text' => '',
            'recording_ipa' => '',
            'needs_review' => false,
            'review_fields' => [],
            'review_note' => '',
        ], $values);

        return [
            'record_type' => 'word_audio_transcription',
            'sync_id' => $sync_id,
            'natural_key' => 'natural:' . $sync_id,
            'word' => [
                'id' => 10 + $recording_id,
                'sync_id' => 'word-' . $sync_id,
                'slug' => sanitize_title($word_title),
                'title' => $word_title,
            ],
            'recording' => [
                'id' => $recording_id,
                'slug' => sanitize_title($recording_title),
                'title' => $recording_title,
                'types' => ['isolation'],
            ],
            'values' => ll_tools_site_sync_normalize_record_values($values),
            'value_hash' => ll_tools_site_sync_value_hash($values),
            'media' => ll_tools_site_sync_normalize_record_media($media),
        ];
    }

    /**
     * @param array<string,mixed> $params
     */
    private function dispatch_rest_request(string $method, string $route, array $params = []): WP_REST_Response
    {
        $previous_rest_route = $_GET['rest_route'] ?? null;
        $_GET['rest_route'] = $route;
        $request = new WP_REST_Request($method, $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        try {
            $response = rest_get_server()->dispatch($request);
            $this->assertNotWPError($response);
            return rest_ensure_response($response);
        } finally {
            if ($previous_rest_route === null) {
                unset($_GET['rest_route']);
            } else {
                $_GET['rest_route'] = $previous_rest_route;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
