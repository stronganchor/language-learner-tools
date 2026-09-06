<?php
declare(strict_types=1);

final class WordAudioBulkRecordingTypeEditTest extends LL_Tools_TestCase
{
    private int $adminUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUserId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->adminUserId);
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    public function test_bulk_state_reports_uniform_and_nonuniform_recording_type_sets(): void
    {
        $isolationId = $this->createRecordingType('Bulk Isolation', 'bulk-isolation');
        $questionId = $this->createRecordingType('Bulk Question', 'bulk-question');
        $firstAudioId = $this->createAudio([$isolationId, $questionId]);
        $secondAudioId = $this->createAudio([$questionId, $isolationId]);

        $uniform = ll_word_audio_get_bulk_recording_type_state_for_posts([
            $firstAudioId,
            (string) $secondAudioId,
            $firstAudioId,
            0,
            -1,
        ]);

        $this->assertTrue($uniform['allSame']);
        $this->assertSame([$isolationId, $questionId], $uniform['common']);

        wp_set_object_terms($secondAudioId, [$isolationId], 'recording_type', false);
        $nonuniform = ll_word_audio_get_bulk_recording_type_state_for_posts([$firstAudioId, $secondAudioId]);

        $this->assertFalse($nonuniform['allSame']);
        $this->assertSame([$isolationId], $nonuniform['common']);
    }

    public function test_bulk_state_excludes_posts_the_current_user_cannot_edit(): void
    {
        $recordingTypeId = $this->createRecordingType('Bulk Restricted', 'bulk-restricted');
        $audioId = $this->createAudio([$recordingTypeId]);
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriberId);

        $state = ll_word_audio_get_bulk_recording_type_state_for_posts([$audioId]);

        $this->assertFalse($state['allSame']);
        $this->assertSame([], $state['common']);
    }

    public function test_bulk_state_request_guard_rejects_oversized_and_nested_post_ids(): void
    {
        $limitFilter = static function (): int {
            return 2;
        };
        $valueBytesFilter = static function (): int {
            return 4;
        };
        add_filter('ll_tools_word_audio_bulk_recording_type_post_ids_limit', $limitFilter);
        add_filter('ll_tools_word_audio_bulk_recording_type_post_id_max_raw_bytes', $valueBytesFilter);

        try {
            $tooMany = ll_word_audio_bulk_recording_type_post_ids_request_error([11, 12, 13]);
            $nested = ll_word_audio_bulk_recording_type_post_ids_request_error([['11']]);
            $tooWide = ll_word_audio_bulk_recording_type_post_ids_request_error(['12345']);
            $valid = ll_word_audio_bulk_recording_type_post_ids_request_error(['11', 12]);
        } finally {
            remove_filter('ll_tools_word_audio_bulk_recording_type_post_id_max_raw_bytes', $valueBytesFilter);
            remove_filter('ll_tools_word_audio_bulk_recording_type_post_ids_limit', $limitFilter);
        }

        $rawBytesFilter = static function (): int {
            return 1024;
        };
        $aggregateValueBytesFilter = static function (): int {
            return 64;
        };
        add_filter('ll_tools_word_audio_bulk_recording_type_post_ids_max_raw_bytes', $rawBytesFilter);
        add_filter('ll_tools_word_audio_bulk_recording_type_post_id_max_raw_bytes', $aggregateValueBytesFilter);
        try {
            $tooLargeInAggregate = ll_word_audio_bulk_recording_type_post_ids_request_error(
                array_fill(0, 55, (string) PHP_INT_MAX)
            );
        } finally {
            remove_filter('ll_tools_word_audio_bulk_recording_type_post_id_max_raw_bytes', $aggregateValueBytesFilter);
            remove_filter('ll_tools_word_audio_bulk_recording_type_post_ids_max_raw_bytes', $rawBytesFilter);
        }

        $this->assertWPError($tooMany);
        $this->assertSame('ll_word_audio_bulk_recording_type_too_large', $tooMany->get_error_code());
        $this->assertSame(413, (int) (($tooMany->get_error_data()['status'] ?? 0)));
        $this->assertWPError($nested);
        $this->assertSame('ll_word_audio_bulk_recording_type_input_invalid', $nested->get_error_code());
        $this->assertSame(400, (int) (($nested->get_error_data()['status'] ?? 0)));
        $this->assertWPError($tooWide);
        $this->assertSame('ll_word_audio_bulk_recording_type_too_large', $tooWide->get_error_code());
        $this->assertWPError($tooLargeInAggregate);
        $this->assertSame('ll_word_audio_bulk_recording_type_too_large', $tooLargeInAggregate->get_error_code());
        $this->assertNull($valid);
    }

    public function test_bulk_state_request_guard_rejects_noncanonical_post_ids(): void
    {
        foreach ([true, false, 1.5, '123junk', '01', '0', '-1', '+1', ' 1', str_repeat('9', 20)] as $invalidId) {
            $error = ll_word_audio_bulk_recording_type_post_ids_request_error([$invalidId]);
            $this->assertWPError($error);
            $this->assertSame('ll_word_audio_bulk_recording_type_input_invalid', $error->get_error_code());
        }

        $this->assertNull(ll_word_audio_bulk_recording_type_post_ids_request_error([1, '2']));
    }

    public function test_bulk_state_fails_closed_when_one_recording_type_read_fails(): void
    {
        $recordingTypeId = $this->createRecordingType('Bulk Source Failure', 'bulk-source-failure');
        $firstAudioId = $this->createAudio([$recordingTypeId]);
        $secondAudioId = $this->createAudio([$recordingTypeId]);
        $failed = false;
        $termFailure = static function ($terms, $objectIds, $taxonomies) use ($secondAudioId, &$failed) {
            if (!$failed && str_contains((string) $taxonomies, 'recording_type') && str_contains((string) $objectIds, (string) $secondAudioId)) {
                $failed = true;
                return new WP_Error('forced_term_read_failure', 'forced');
            }
            return $terms;
        };
        add_filter('wp_get_object_terms', $termFailure, 10, 3);
        try {
            $state = ll_word_audio_get_bulk_recording_type_state_for_posts([$firstAudioId, $secondAudioId]);
        } finally {
            remove_filter('wp_get_object_terms', $termFailure, 10);
        }

        $this->assertTrue($failed);
        $this->assertWPError($state);
        $this->assertSame('ll_word_audio_bulk_recording_type_source_unavailable', $state->get_error_code());
        $this->assertSame(503, (int) (($state->get_error_data()['status'] ?? 0)));
    }

    public function test_bulk_state_fails_closed_on_low_level_term_query_error(): void
    {
        global $wpdb;

        $recordingTypeId = $this->createRecordingType('Bulk Database Failure', 'bulk-database-failure');
        $audioId = $this->createAudio([$recordingTypeId]);
        $termFailure = static function ($terms, $objectIds, $taxonomies) use ($audioId, $wpdb) {
            if (str_contains((string) $taxonomies, 'recording_type') && str_contains((string) $objectIds, (string) $audioId)) {
                $wpdb->last_error = 'forced low-level term query failure';
                return [];
            }
            return $terms;
        };
        add_filter('wp_get_object_terms', $termFailure, 10, 3);
        try {
            $state = ll_word_audio_get_bulk_recording_type_state_for_posts([$audioId]);
        } finally {
            remove_filter('wp_get_object_terms', $termFailure, 10);
            $wpdb->last_error = '';
        }

        $this->assertWPError($state);
        $this->assertSame('ll_word_audio_bulk_recording_type_source_unavailable', $state->get_error_code());
        $this->assertSame(503, (int) (($state->get_error_data()['status'] ?? 0)));
    }

    public function test_bulk_state_ajax_rejects_oversized_post_ids_before_hydration(): void
    {
        global $wpdb;

        $limitFilter = static function (): int {
            return 2;
        };
        add_filter('ll_tools_word_audio_bulk_recording_type_post_ids_limit', $limitFilter);
        $_POST = [
            'nonce' => wp_create_nonce('ll_word_audio_bulk_recording_type_edit'),
            'post_ids' => [101, 102, 103],
        ];
        $_REQUEST = $_POST;
        $queries = [];
        $queryWatcher = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $queryWatcher);

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_word_audio_get_bulk_recording_type_state_ajax();
            });
        } finally {
            remove_filter('query', $queryWatcher);
            remove_filter('ll_tools_word_audio_bulk_recording_type_post_ids_limit', $limitFilter);
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame(
            'll_word_audio_bulk_recording_type_too_large',
            (string) ($response['data']['code'] ?? '')
        );
        $querySql = implode("\n", $queries);
        $this->assertStringNotContainsString("FROM {$wpdb->posts}", $querySql);
        $this->assertStringNotContainsString("FROM {$wpdb->term_relationships}", $querySql);
    }

    public function test_bulk_edit_replaces_recording_types_with_sanitized_unique_terms(): void
    {
        $oldTypeId = $this->createRecordingType('Bulk Old', 'bulk-old');
        $newTypeId = $this->createRecordingType('Bulk New', 'bulk-new');
        $audioId = $this->createAudio([$oldTypeId]);
        $_REQUEST = [
            'bulk_edit' => '1',
            'll_bulk_recording_types_replace' => '1',
            'll_bulk_recording_types_selected' => [
                (string) $newTypeId,
                $newTypeId,
                'not-an-id',
                '0',
                '-12',
            ],
        ];

        ll_word_audio_handle_bulk_edit_recording_type_replace($audioId);

        $this->assertSame(
            [$newTypeId],
            array_map('intval', wp_get_post_terms($audioId, 'recording_type', ['fields' => 'ids']))
        );
    }

    public function test_bulk_edit_can_explicitly_clear_recording_types(): void
    {
        $recordingTypeId = $this->createRecordingType('Bulk Clear', 'bulk-clear');
        $audioId = $this->createAudio([$recordingTypeId]);
        $_REQUEST = [
            'bulk_edit' => '1',
            'll_bulk_recording_types_replace' => '1',
            'll_bulk_recording_types_selected' => [],
        ];

        ll_word_audio_handle_bulk_edit_recording_type_replace($audioId);

        $this->assertSame([], wp_get_post_terms($audioId, 'recording_type', ['fields' => 'ids']));
    }

    public function test_bulk_edit_without_replace_flag_or_permission_preserves_terms(): void
    {
        $recordingTypeId = $this->createRecordingType('Bulk Preserve', 'bulk-preserve');
        $audioId = $this->createAudio([$recordingTypeId]);
        $_REQUEST = ['bulk_edit' => '1'];

        ll_word_audio_handle_bulk_edit_recording_type_replace($audioId);
        $this->assertSame(
            [$recordingTypeId],
            array_map('intval', wp_get_post_terms($audioId, 'recording_type', ['fields' => 'ids']))
        );

        $_REQUEST['ll_bulk_recording_types_replace'] = '1';
        $_REQUEST['ll_bulk_recording_types_selected'] = [];
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        ll_word_audio_handle_bulk_edit_recording_type_replace($audioId);

        $this->assertSame(
            [$recordingTypeId],
            array_map('intval', wp_get_post_terms($audioId, 'recording_type', ['fields' => 'ids']))
        );
    }

    private function createRecordingType(string $name, string $slug): int
    {
        $term = wp_insert_term($name, 'recording_type', ['slug' => $slug]);
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    /** @param int[] $recordingTypeIds */
    private function createAudio(array $recordingTypeIds): int
    {
        $audioId = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => 'Bulk recording type fixture',
            'post_author' => $this->adminUserId,
        ]);
        $assigned = wp_set_object_terms($audioId, $recordingTypeIds, 'recording_type', false);
        $this->assertNotWPError($assigned);
        return $audioId;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static fn (): bool => true;

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
