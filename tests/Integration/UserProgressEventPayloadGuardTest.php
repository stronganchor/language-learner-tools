<?php
declare(strict_types=1);

final class UserProgressEventPayloadGuardTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_install_user_progress_schema();
    }

    public function test_progress_event_drops_unknown_payload_values_without_rejecting_the_event(): void
    {
        $event = ll_tools_sanitize_progress_event([
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => 17,
            'wordset_id' => 23,
            'payload' => [
                'units' => 2,
                'unrecognized_blob' => 'obsolete but bounded',
                'nested' => [['value' => 'drop this too']],
                'device_id' => 'payload-device',
                'profile_id' => 'payload-profile',
            ],
        ]);

        $this->assertIsArray($event);
        $this->assertSame(['units' => 2], $event['payload'] ?? null);
    }

    public function test_client_progress_event_requires_a_stable_uuid_for_retry_idempotency(): void
    {
        $raw = [
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => 17,
            'wordset_id' => 23,
            'payload' => ['units' => 1],
        ];

        $this->assertNull(ll_tools_sanitize_progress_event($raw));
        $this->assertNull(ll_tools_sanitize_progress_event(['event_uuid' => ''] + $raw));
        $this->assertNull(ll_tools_sanitize_progress_event(['uuid' => ''] + $raw));

        $failure = ll_tools_user_progress_core_engine_failure_stats([$raw], [
            'failure_code' => 'progress_schema_unavailable',
        ]);
        $this->assertSame(1, (int) ($failure['received'] ?? 0));
        $this->assertSame(1, (int) ($failure['invalid'] ?? 0));
        $this->assertSame(0, (int) ($failure['failed'] ?? -1));
        $this->assertSame([], $failure['failed_event_uuids'] ?? null);
    }

    public function test_progress_event_rejects_oversized_or_overly_nested_raw_payloads(): void
    {
        $byte_limit = static function (): int {
            return 1024;
        };
        add_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);

        try {
            $oversized = ll_tools_sanitize_progress_event([
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'category_study',
                'mode' => 'practice',
                'category_id' => 17,
                'wordset_id' => 23,
                'payload' => [
                    'units' => 1,
                    'removed_extension' => str_repeat('x', 2048),
                ],
            ]);
            $this->assertNull($oversized);

            $too_deep = ll_tools_sanitize_progress_event([
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'category_study',
                'mode' => 'practice',
                'category_id' => 17,
                'wordset_id' => 23,
                'payload' => [
                    'units' => 1,
                    'removed_extension' => [[[[[[['value' => 'too deep']]]]]]],
                ],
            ]);
            $this->assertNull($too_deep);

            $nonfinite = ll_tools_sanitize_progress_event([
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'category_study',
                'mode' => 'practice',
                'category_id' => 17,
                'wordset_id' => 23,
                'payload' => [
                    'units' => 1,
                    'removed_extension' => INF,
                ],
            ]);
            $this->assertNull($nonfinite);
        } finally {
            remove_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);
        }
    }

    public function test_payload_builder_preserves_each_first_party_contract_and_drops_cross_event_fields(): void
    {
        $cases = [
            'word_exposure' => [
                'mode' => 'practice',
                'payload' => [
                    'prompt_card_id' => 41,
                    'recording_type' => 'Question',
                    'available_recording_types' => ['question', 'sentence'],
                    'game_slug' => 'lineup',
                    'event_source' => 'lineup',
                    'speaking_target_field' => 'recording_text',
                    'sequence_direction' => 'rtl',
                    'sequence_length' => 9,
                    'prompt_type' => 'text_title',
                    'tile_count' => 12,
                    'self_check_bucket' => 'right',
                    'device_id' => 'payload-device',
                ],
                'keys' => [
                    'available_recording_types',
                    'event_source',
                    'game_slug',
                    'prompt_card_id',
                    'prompt_type',
                    'recording_type',
                    'sequence_direction',
                    'sequence_length',
                    'speaking_target_field',
                    'tile_count',
                ],
            ],
            'word_outcome' => [
                'mode' => 'gender',
                'payload' => [
                    'prompt_card_id' => 42,
                    'recording_type' => 'isolation',
                    'available_recording_types' => ['isolation', 'question'],
                    'audio_progress_ratio' => 0.625,
                    'answered_before_audio_end' => true,
                    'gender' => [
                        'level' => 2,
                        'confidence' => 5,
                        'intro_seen' => true,
                        'quick_correct_streak' => 2,
                        'level1_passes' => 3,
                        'level1_failures' => 1,
                        'level2_correct' => 4,
                        'level2_wrong' => 1,
                        'level3_correct' => 0,
                        'level3_wrong' => 0,
                        'dont_know_count' => 1,
                        'seen_total' => 9,
                        'category_name' => 'Canonical category',
                        'last_seen_at' => '2026-08-23T08:00:00Z',
                        'updated_at_ms' => 1787472000000,
                        'unknown_nested' => 'drop me',
                    ],
                    'gender_answer_timing' => 'before_intro',
                    'gender_dont_know' => false,
                    'self_check_confidence' => 'know',
                    'self_check_result' => 'right',
                    'self_check_bucket' => 'right',
                    'self_check_group_key' => 'round:42',
                    'self_check_group_size' => 4,
                    'forced_prompt' => 'image',
                    'game_slug' => 'speaking-practice',
                    'event_source' => 'speaking_practice',
                    'wrong_hit' => false,
                    'timeout' => false,
                    'stack_end_reason' => 'win',
                    'sequence_category_id' => 17,
                    'sequence_category_name' => 'Sequence category',
                    'sequence_direction' => 'ltr',
                    'sequence_length' => 8,
                    'sequence_attempts' => 2,
                    'prompt_type' => 'image',
                    'tile_count' => 10,
                    'moves' => 11,
                    'needed_retry' => 1,
                    'speaking_game_bucket' => 'close',
                    'speaking_score' => 87.5,
                    'stt_provider' => 'assemblyai',
                    'speaking_target_field' => 'recording_text',
                    'units' => 999,
                    'profile_id' => 'payload-profile',
                ],
                'keys' => [
                    'answered_before_audio_end',
                    'audio_progress_ratio',
                    'available_recording_types',
                    'event_source',
                    'forced_prompt',
                    'game_slug',
                    'gender',
                    'gender_answer_timing',
                    'gender_dont_know',
                    'moves',
                    'needed_retry',
                    'prompt_card_id',
                    'prompt_type',
                    'recording_type',
                    'self_check_bucket',
                    'self_check_confidence',
                    'self_check_group_key',
                    'self_check_group_size',
                    'self_check_result',
                    'sequence_attempts',
                    'sequence_category_id',
                    'sequence_category_name',
                    'sequence_direction',
                    'sequence_length',
                    'speaking_game_bucket',
                    'speaking_score',
                    'speaking_target_field',
                    'stack_end_reason',
                    'stt_provider',
                    'tile_count',
                    'timeout',
                    'wrong_hit',
                ],
            ],
            'category_study' => [
                'mode' => 'listening',
                'payload' => [
                    'units' => 3,
                    'game_slug' => 'space-shooter',
                ],
                'keys' => ['units'],
            ],
            'mode_session_complete' => [
                'mode' => 'practice',
                'payload' => [
                    'category_ids' => [17, '18', 17, -1, 'bad'],
                    'result' => [
                        'schema' => 1,
                        'kind' => 'practice_first_try',
                        'score_given' => 7,
                        'score_maximum' => 10,
                        'score_basis' => 'first_try_distinct_words',
                        'percentage' => 999,
                    ],
                    'gender' => ['level' => 3],
                ],
                'keys' => ['category_ids', 'result'],
            ],
            'stt_api_call' => [
                'mode' => 'practice',
                'payload' => [
                    'source' => 'wordset_speaking_game',
                    'provider' => 'assemblyai',
                    'target_field' => 'recording_text',
                    'transcript' => 'must not be stored',
                ],
                'keys' => ['provider', 'source', 'target_field'],
            ],
        ];

        foreach ($cases as $event_type => $case) {
            $payload = ll_tools_build_progress_event_payload(
                $event_type,
                (string) $case['mode'],
                (array) $case['payload']
            );
            $actual_keys = array_keys($payload);
            sort($actual_keys);
            $expected_keys = (array) $case['keys'];
            sort($expected_keys);
            $this->assertSame($expected_keys, $actual_keys, $event_type);
        }

        $outcome = ll_tools_build_progress_event_payload(
            'word_outcome',
            'gender',
            (array) $cases['word_outcome']['payload']
        );
        $gender = (array) ($outcome['gender'] ?? []);
        $this->assertSame(1787472000000, (int) ($gender['updated_at'] ?? 0));
        $this->assertArrayNotHasKey('updated_at_ms', $gender);
        $this->assertArrayNotHasKey('unknown_nested', $gender);

        $completion = ll_tools_build_progress_event_payload(
            'mode_session_complete',
            'practice',
            (array) $cases['mode_session_complete']['payload']
        );
        $this->assertSame([17, 18], $completion['category_ids'] ?? null);
        $this->assertSame([
            'schema' => 1,
            'kind' => 'practice_first_try',
            'score_given' => 7,
            'score_maximum' => 10,
            'score_basis' => 'first_try_distinct_words',
        ], $completion['result'] ?? null);
    }

    public function test_payload_builder_bounds_known_scalar_and_nested_values(): void
    {
        $payload = ll_tools_build_progress_event_payload('word_outcome', 'gender', [
            'recording_type' => str_repeat('Long Recording Type ', 20),
            'available_recording_types' => [str_repeat('Long Type ', 30)],
            'event_source' => str_repeat('source', 30),
            'sequence_length' => 999999999,
            'audio_progress_ratio' => 4,
            'speaking_score' => 900,
            'gender' => [
                'level' => 99,
                'confidence' => -999,
                'intro_seen' => true,
                'quick_correct_streak' => 0,
                'level1_passes' => 0,
                'level1_failures' => 0,
                'level2_correct' => 0,
                'level2_wrong' => 0,
                'level3_correct' => 0,
                'level3_wrong' => 0,
                'dont_know_count' => 0,
                'seen_total' => 999999999,
                'category_name' => str_repeat('Category ', 100),
                'updated_at' => 9999999999999,
            ],
        ]);

        $this->assertSame(64, strlen((string) ($payload['recording_type'] ?? '')));
        $this->assertSame(64, strlen((string) (($payload['available_recording_types'] ?? [])[0] ?? '')));
        $this->assertSame(64, strlen((string) ($payload['event_source'] ?? '')));
        $this->assertSame(1000000, (int) ($payload['sequence_length'] ?? 0));
        $this->assertSame(1.0, (float) ($payload['audio_progress_ratio'] ?? 0));
        $this->assertSame(100.0, (float) ($payload['speaking_score'] ?? 0));
        $this->assertSame(3, (int) (($payload['gender'] ?? [])['level'] ?? 0));
        $this->assertSame(-8, (int) (($payload['gender'] ?? [])['confidence'] ?? 0));
        $this->assertSame(1000000, (int) (($payload['gender'] ?? [])['seen_total'] ?? 0));
        $this->assertSame(191, strlen((string) (($payload['gender'] ?? [])['category_name'] ?? '')));
        $this->assertSame(4102444800000, (int) (($payload['gender'] ?? [])['updated_at'] ?? 0));

        $partial_gender = ll_tools_build_progress_event_payload('word_outcome', 'gender', [
            'gender' => [
                'updated_at' => 1787472000000,
                'seen_total' => 99,
            ],
            'gender_dont_know' => true,
        ]);
        $this->assertSame([
            'seen_total' => 99,
            'updated_at' => 1787472000000,
        ], $partial_gender['gender'] ?? null);
        $this->assertTrue((bool) ($partial_gender['gender_dont_know'] ?? false));
    }

    public function test_progress_event_removes_invalid_prompt_card_id_and_canonicalizes_the_ingress_alias(): void
    {
        foreach ([0, -10, 'not-an-id', []] as $invalid_prompt_card_id) {
            $event = ll_tools_sanitize_progress_event([
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => 123,
                'wordset_id' => 456,
                'payload' => ['prompt_card_id' => $invalid_prompt_card_id],
            ]);
            $this->assertIsArray($event);
            $this->assertArrayNotHasKey('prompt_card_id', (array) ($event['payload'] ?? []));
        }

        $aliased = ll_tools_sanitize_progress_event([
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_exposure',
            'mode' => 'practice',
            'word_id' => 123,
            'wordset_id' => 456,
            'payload' => ['promptCardId' => '789'],
        ]);
        $this->assertIsArray($aliased);
        $this->assertSame(789, (int) (($aliased['payload'] ?? [])['prompt_card_id'] ?? 0));
        $this->assertArrayNotHasKey('promptCardId', (array) ($aliased['payload'] ?? []));
    }

    public function test_payload_identity_is_removed_and_opt_in_identity_comes_only_from_top_level_fields(): void
    {
        $raw = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => 17,
            'wordset_id' => 23,
            'device_id' => 'DEVICE Top !',
            'profile_id' => 'Profile Top !',
            'payload' => [
                'units' => 1,
                'device_id' => 'payload-device',
                'profile_id' => 'payload-profile',
            ],
        ];

        $default_event = ll_tools_sanitize_progress_event($raw);
        $this->assertIsArray($default_event);
        $this->assertSame('', (string) ($default_event['device_id'] ?? 'not-empty'));
        $this->assertSame('', (string) ($default_event['profile_id'] ?? 'not-empty'));
        $this->assertArrayNotHasKey('device_id', (array) ($default_event['payload'] ?? []));
        $this->assertArrayNotHasKey('profile_id', (array) ($default_event['payload'] ?? []));

        $store_identity = static function (): bool {
            return true;
        };
        add_filter('ll_tools_store_progress_event_client_identity', $store_identity);
        try {
            $opt_in_event = ll_tools_sanitize_progress_event($raw);
            $this->assertIsArray($opt_in_event);
            $this->assertSame('devicetop', (string) ($opt_in_event['device_id'] ?? ''));
            $this->assertSame('profiletop', (string) ($opt_in_event['profile_id'] ?? ''));
            $this->assertSame('devicetop', (string) (($opt_in_event['payload'] ?? [])['device_id'] ?? ''));
            $this->assertSame('profiletop', (string) (($opt_in_event['payload'] ?? [])['profile_id'] ?? ''));

            $payload_only = $raw;
            unset($payload_only['device_id'], $payload_only['profile_id']);
            $payload_only_event = ll_tools_sanitize_progress_event($payload_only);
            $this->assertIsArray($payload_only_event);
            $this->assertArrayNotHasKey('device_id', (array) ($payload_only_event['payload'] ?? []));
            $this->assertArrayNotHasKey('profile_id', (array) ($payload_only_event['payload'] ?? []));
        } finally {
            remove_filter('ll_tools_store_progress_event_client_identity', $store_identity);
        }
    }

    public function test_progress_event_rechecks_the_budget_after_canonicalization(): void
    {
        $byte_limit = static function (): int {
            return 1024;
        };
        add_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);
        try {
            $event = ll_tools_sanitize_progress_event([
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'mode_session_complete',
                'mode' => 'practice',
                'payload' => ['category_ids' => range(1, 1000)],
            ]);
            $this->assertNull($event);
        } finally {
            remove_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);
        }
    }

    public function test_progress_event_keeps_full_supported_category_list_and_practice_result(): void
    {
        $event = ll_tools_sanitize_progress_event([
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'mode_session_complete',
            'mode' => 'practice',
            'wordset_id' => 23,
            'payload' => [
                'category_ids' => range(1, 1000),
                'result' => [
                    'schema' => 1,
                    'kind' => 'practice_first_try',
                    'score_given' => 750,
                    'score_maximum' => 1000,
                    'score_basis' => 'first_try_distinct_words',
                ],
            ],
        ]);

        $this->assertIsArray($event);
        $this->assertSame(range(1, 1000), $event['payload']['category_ids'] ?? []);
        $this->assertSame(750, (int) ($event['payload']['result']['score_given'] ?? -1));
        $this->assertSame(1000, (int) ($event['payload']['result']['score_maximum'] ?? -1));
    }

    public function test_privacy_export_contains_only_the_canonical_payload(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $user = get_userdata($user_id);
        $this->assertInstanceOf(WP_User::class, $user);

        $category = wp_insert_term('Payload privacy ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($category);
        $stats = ll_tools_process_progress_events_batch($user_id, [[
            'event_uuid' => 'payload-privacy-' . wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => (int) $category['term_id'],
            'payload' => [
                'units' => 2,
                'device_id' => 'private-device',
                'profile_id' => 'private-profile',
                'legacy_secret' => 'private-extension-data',
            ],
        ]]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $export = ll_tools_privacy_export_study_event_rows((string) $user->user_email, 1);
        $exported_payload = null;
        foreach ((array) ($export['data'] ?? []) as $item) {
            foreach ((array) ($item['data'] ?? []) as $pair) {
                if (($pair['name'] ?? '') !== __('Payload', 'll-tools-text-domain')) {
                    continue;
                }
                $candidate = json_decode((string) ($pair['value'] ?? ''), true);
                if (is_array($candidate) && ($candidate['units'] ?? null) === 2) {
                    $exported_payload = $candidate;
                    break 2;
                }
            }
        }
        $this->assertSame(['units' => 2], $exported_payload);
    }

    public function test_server_stt_event_stores_only_the_bounded_contract(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $event_uuid = 'stt-contract-' . wp_generate_uuid4();
        $this->assertTrue(ll_tools_record_server_progress_event($user_id, [
            'event_uuid' => $event_uuid,
            'event_type' => 'stt_api_call',
            'mode' => 'practice',
            'payload' => [
                'source' => 'wordset_speaking_game',
                'provider' => 'assemblyai',
                'target_field' => 'recording_text',
                'transcript' => str_repeat('private transcript ', 1000),
                'device_id' => 'payload-device',
                'nested' => [['unbounded' => true]],
            ],
        ]));

        $payload_json = $wpdb->get_var($wpdb->prepare(
            'SELECT payload_json FROM ' . ll_tools_user_progress_table_names()['events'] . ' WHERE event_uuid = %s',
            $event_uuid
        ));
        $this->assertSame([
            'source' => 'wordset_speaking_game',
            'provider' => 'assemblyai',
            'target_field' => 'recording_text',
        ], json_decode((string) $payload_json, true));
    }
}
