<?php
declare(strict_types=1);

final class UserProgressEventPayloadGuardTest extends LL_Tools_TestCase
{
    public function test_progress_event_rejects_oversized_or_overly_nested_payloads_before_persistence_work(): void
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
                    'unrecognized_blob' => str_repeat('x', 2048),
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
                    'nested' => [[[[[[['value' => 'too deep']]]]]]],
                ],
            ]);
            $this->assertNull($too_deep);
        } finally {
            remove_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);
        }
    }

    public function test_progress_event_keeps_bounded_legacy_payload_keys_for_compatibility(): void
    {
        $event = ll_tools_sanitize_progress_event([
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => 17,
            'wordset_id' => 23,
            'payload' => [
                'units' => 2,
                'legacy_extension' => 'preserved while bounded',
            ],
        ]);

        $this->assertIsArray($event);
        $this->assertSame(2, (int) ($event['payload']['units'] ?? 0));
        $this->assertSame(
            'preserved while bounded',
            (string) ($event['payload']['legacy_extension'] ?? '')
        );
    }

    public function test_progress_event_rejects_payload_that_expands_past_json_byte_limit(): void
    {
        $event = ll_tools_sanitize_progress_event([
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => 17,
            'wordset_id' => 23,
            'payload' => [
                'units' => 1,
                'escaped_blob' => str_repeat('\\', 40000),
            ],
        ]);

        $this->assertNull($event);
    }

    public function test_progress_event_rejects_unencodable_nonfinite_payload_value(): void
    {
        $event = ll_tools_sanitize_progress_event([
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'category_study',
            'mode' => 'practice',
            'category_id' => 17,
            'wordset_id' => 23,
            'payload' => [
                'units' => 1,
                'nonfinite_value' => INF,
            ],
        ]);

        $this->assertNull($event);
    }

    public function test_progress_event_rechecks_budget_after_identity_enrichment(): void
    {
        $byte_limit = static function (): int {
            return 1024;
        };
        $store_identity = static function (): bool {
            return true;
        };
        add_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);
        add_filter('ll_tools_store_progress_event_client_identity', $store_identity);

        try {
            $event = ll_tools_sanitize_progress_event([
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'category_study',
                'mode' => 'practice',
                'category_id' => 17,
                'wordset_id' => 23,
                'device_id' => str_repeat('d', 80),
                'payload' => [
                    'units' => 1,
                    'legacy_extension' => str_repeat('x', 920),
                ],
            ]);

            $this->assertNull($event);
        } finally {
            remove_filter('ll_tools_user_progress_event_payload_byte_limit', $byte_limit);
            remove_filter('ll_tools_store_progress_event_client_identity', $store_identity);
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
}
