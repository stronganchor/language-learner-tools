<?php
declare(strict_types=1);

final class QuizSamplingTest extends LL_Tools_TestCase
{
    private array $scopes = [];

    protected function setUp(): void {
        parent::setUp();
        $this->assertTrue(ll_tools_install_flashcard_payload_schema());
    }

    protected function tearDown(): void {
        global $wpdb;
        foreach ($this->scopes as $scope) {
            $hash = $scope['scope_hash'];
            wp_clear_scheduled_hook(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$hash]);
            delete_option(ll_tools_flashcard_payload_state_option($hash));
            delete_option(ll_tools_flashcard_payload_lock_option($hash));
            $wpdb->delete(ll_tools_flashcard_payload_table_name(), ['scope_hash' => $hash]);
        }
        parent::tearDown();
    }

    private function fixture(int $count): array {
        $termId = self::factory()->term->create(['taxonomy' => 'word-category']);
        update_term_meta($termId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($termId, 'll_quiz_option_type', 'text_translation');
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Qatal ' . $i]);
            wp_set_object_terms($id, [$termId], 'word-category');
            update_post_meta($id, 'word_translation', 'Vayyiqtol ' . $i);
            $ids[] = $id;
        }
        ll_tools_rebuild_specific_wrong_answer_owner_map();
        $term = get_term($termId, 'word-category');
        $scope = ll_tools_flashcard_payload_build_scope($term, [], ll_tools_flashcard_payload_normalize_config(ll_tools_get_category_quiz_config($term)));
        $this->assertIsArray($scope);
        $this->scopes[] = $scope;
        for ($i = 0; $i < 20; $i++) {
            $ready = ll_tools_flashcard_payload_ensure_ready($scope);
            $this->assertNotWPError($ready);
            if ($ready) { return [$termId, $scope, $ids]; }
        }
        $this->fail('Sample fixture did not materialize.');
    }

    public function test_settings_validate_default_minimum_and_preserve_older_clients(): void {
        $this->assertNull(ll_tools_quiz_question_limit_from_request([]));
        $this->assertSame(15, ll_tools_quiz_question_limit_from_request(['quiz_sample_enabled' => '1']));
        $this->assertSame(0, ll_tools_quiz_question_limit_from_request(['quiz_sample_enabled' => '0', 'quiz_question_limit' => 'bad']));
        foreach (['0', '4', '-5', '5.5', '5e1', '', [], '9999999999'] as $invalid) {
            $this->assertWPError(ll_tools_quiz_question_limit_from_request(['quiz_sample_enabled' => '1', 'quiz_question_limit' => $invalid]));
        }
        foreach ([5, 15, 73, 150] as $valid) {
            $this->assertSame($valid, ll_tools_quiz_question_limit_from_request(['quiz_sample_enabled' => '1', 'quiz_question_limit' => (string) $valid]));
        }
        [$termId] = $this->fixture(5);
        $this->assertSame(0, ll_tools_get_category_quiz_config($termId)['question_limit']);
        update_term_meta($termId, 'll_quiz_question_limit', 15);
        $this->assertSame(15, ll_tools_get_category_quiz_config($termId)['question_limit']);
    }

    public function test_73_bank_samples_15_stable_on_retry_and_changes_on_new_attempt(): void {
        [, $scope, $bank] = $this->fixture(73);
        $first = ll_tools_quiz_sample_read_page($scope, 15, str_repeat('a', 32));
        $this->assertIsArray($first);
        $this->assertSame(15, $first['sample_total']);
        $this->assertCount(15, array_unique($first['sample_target_ids']));
        $this->assertSame([], array_diff($first['sample_target_ids'], $bank));
        $this->assertTrue($first['complete']);
        $this->assertSame('', $first['next_cursor']);
        $this->assertSame($first['sample_target_ids'], ll_tools_quiz_sample_read_page($scope, 15, str_repeat('a', 32))['sample_target_ids']);
        $this->assertNotSame($first['sample_target_ids'], ll_tools_quiz_sample_read_page($scope, 15, str_repeat('b', 32))['sample_target_ids']);
        $this->assertNotEmpty(array_diff(array_column($first['rows'], 'id'), $first['sample_target_ids']));
        $this->assertLessThanOrEqual(27, count($first['rows']));
        $this->assertCount(5, ll_tools_quiz_sample_read_page($scope, 5, str_repeat('a', 32))['sample_target_ids']);
    }

    public function test_large_custom_count_is_paged_without_duplicates_and_cursor_cannot_change_seed(): void {
        [, $scope, $bank] = $this->fixture(73);
        $first = ll_tools_quiz_sample_read_page($scope, 100, str_repeat('c', 32));
        $this->assertIsArray($first);
        $this->assertCount(30, $first['sample_target_ids']);
        $this->assertFalse($first['complete']);
        $this->assertWPError(ll_tools_quiz_sample_read_page($scope, 100, str_repeat('d', 32), $first['next_cursor']));
        $this->assertWPError(ll_tools_quiz_sample_read_page($scope, 50, str_repeat('c', 32), $first['next_cursor']));
        $ids = $first['sample_target_ids'];
        $cursor = $first['next_cursor'];
        while ($cursor) {
            $page = ll_tools_quiz_sample_read_page($scope, 100, str_repeat('c', 32), $cursor);
            $this->assertIsArray($page);
            $this->assertSame(73, $page['sample_total']);
            $this->assertLessThanOrEqual(30, count($page['sample_target_ids']));
            $ids = array_merge($ids, $page['sample_target_ids']);
            $cursor = $page['next_cursor'];
        }
        sort($bank); sort($ids);
        $this->assertSame($bank, $ids);
    }

    public function test_sample_rejects_invalid_seed_and_corrupt_payload_before_hydration(): void {
        global $wpdb;
        [, $scope] = $this->fixture(5);
        $this->assertWPError(ll_tools_quiz_sample_read_page($scope, 4, str_repeat('a', 32)));
        $this->assertWPError(ll_tools_quiz_sample_read_page($scope, 15, 'invalid'));
        $wpdb->query($wpdb->prepare('UPDATE ' . ll_tools_flashcard_payload_table_name() . ' SET payload_bytes = 1 WHERE scope_hash = %s', $scope['scope_hash']));
        $result = ll_tools_quiz_sample_read_page($scope, 15, str_repeat('a', 32));
        $this->assertWPError($result);
        $this->assertSame('flashcard_payload_corrupt_row', $result->get_error_code());
    }

    public function test_support_rows_never_become_sample_targets(): void {
        global $wpdb;
        [, $scope, $bank] = $this->fixture(6);
        $table = ll_tools_flashcard_payload_table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE scope_hash = %s AND row_id = %d", $scope['scope_hash'], $bank[0]), ARRAY_A);
        $payload = json_decode($row['payload'], true);
        $payload['is_specific_wrong_answer_only'] = true;
        $encoded = wp_json_encode($payload);
        $wpdb->update($table, ['sort_group' => 2, 'payload' => $encoded, 'payload_bytes' => strlen($encoded)], ['scope_hash' => $scope['scope_hash'], 'row_id' => $bank[0]]);
        $page = ll_tools_quiz_sample_read_page($scope, 15, str_repeat('a', 32));
        $this->assertIsArray($page);
        $this->assertSame(5, $page['sample_total']);
        $this->assertNotContains($bank[0], $page['sample_target_ids']);
    }
}
