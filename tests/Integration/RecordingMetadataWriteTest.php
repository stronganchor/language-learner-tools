<?php
declare(strict_types=1);

final class RecordingMetadataWriteTest extends LL_Tools_TestCase
{
    private function recording(): int
    {
        require_once LL_TOOLS_BASE_PATH . 'includes/lib/recording-metadata.php';
        $id = self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'publish']);
        update_post_meta($id, 'recording_text', 'Original');
        return $id;
    }

    public function test_nested_writers_keep_the_outer_lock_and_release_it_once(): void
    {
        $id = $this->recording();
        $outer = ll_tools_recording_write_acquire($id);
        $this->assertIsArray($outer);
        try {
            $inner = ll_tools_recording_write_acquire($id);
            $this->assertIsArray($inner);
            ll_tools_recording_write_release($inner);
            $this->assertTrue(ll_tools_mutation_job_owns($outer['lease']));
            $this->assertNotFalse(ll_tools_recording_update_post_meta($id, 'recording_text', 'Nested'));
            $this->assertTrue(ll_tools_mutation_job_owns($outer['lease']));
        } finally { ll_tools_recording_write_release($outer); }
        $this->assertFalse(ll_tools_mutation_job_owns($outer['lease']));
        $this->assertSame('Nested', get_post_meta($id, 'recording_text', true));
    }

    public function test_legacy_review_helper_obeys_the_same_busy_recording_fence(): void
    {
        $id = $this->recording();
        ll_tools_wordset_transcription_review_load_runtime();
        $lease = ll_tools_mutation_job_acquire('recording_metadata', (string) $id);
        $this->assertIsArray($lease);
        try {
            ll_tools_ipa_keyboard_set_recording_review_state($id, true, 'recording_text', 'Blocked note');
            $this->assertWPError(ll_tools_recording_write_error($id));
            $this->assertSame('', get_post_meta($id, 'll_auto_transcription_review_note', true));
            $this->assertSame('', get_post_meta($id, 'll_auto_transcription_needs_review', true));
        } finally { ll_tools_mutation_job_release($lease); }
        ll_tools_ipa_keyboard_set_recording_review_state($id, true, 'recording_text', 'Admitted note');
        $this->assertNull(ll_tools_recording_write_error($id));
        $this->assertSame('Admitted note', get_post_meta($id, 'll_auto_transcription_review_note', true));
    }

    public function test_one_failed_field_stops_later_fields_in_the_same_logical_write(): void
    {
        $id = $this->recording();
        $block = static fn($check, $object_id, $key) => (int) $object_id === $id && $key === 'recording_text' ? false : $check;
        add_filter('update_post_metadata', $block, 10, 3);
        try {
            $result = ll_tools_recording_write_run($id, static function () use ($id): void {
                ll_tools_recording_update_post_meta($id, 'recording_text', 'Blocked');
                ll_tools_recording_update_post_meta($id, 'recording_ipa', 'Must not follow');
            });
        } finally { remove_filter('update_post_metadata', $block, 10); }
        $this->assertWPError($result);
        $this->assertSame('Original', get_post_meta($id, 'recording_text', true));
        $this->assertSame('', get_post_meta($id, 'recording_ipa', true));
    }

    public function test_wordpress_sanitization_and_falsey_previous_value_semantics_are_retained(): void
    {
        $id = $this->recording();
        $sanitize = static fn($value) => strtoupper((string) $value);
        add_filter('sanitize_post_meta_recording_text', $sanitize);
        try { $saved = ll_tools_recording_update_post_meta($id, 'recording_text', 'normalized', 0); }
        finally { remove_filter('sanitize_post_meta_recording_text', $sanitize); }
        $this->assertNotFalse($saved);
        $this->assertNull(ll_tools_recording_write_error($id));
        $this->assertSame('NORMALIZED', get_post_meta($id, 'recording_text', true));
    }

    public function test_outer_snapshot_rejects_an_unwrapped_writer_before_child_admission(): void
    {
        global $wpdb;
        $id = $this->recording();
        $scope = ll_tools_recording_write_acquire($id);
        $this->assertIsArray($scope);
        try {
            $wpdb->update($wpdb->postmeta, ['meta_value' => 'External'], ['post_id' => $id, 'meta_key' => 'recording_text']);
            $this->assertFalse(ll_tools_recording_update_post_meta($id, 'recording_text', 'Stale'));
            $this->assertWPError(ll_tools_recording_write_error($id));
        } finally { ll_tools_recording_write_release($scope); }
        wp_cache_delete($id, 'post_meta');
        $this->assertSame('External', get_post_meta($id, 'recording_text', true));
    }

    public function test_actual_update_and_delete_sql_preserve_replacement_rows_and_lost_lease(): void
    {
        global $wpdb;
        $id = $this->recording();
        $scope = ll_tools_recording_write_acquire($id);
        $this->assertIsArray($scope);
        $before = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='recording_text'", $id), ARRAY_A);
        try {
            delete_post_meta($id, 'recording_text');
            $replacement_id = add_post_meta($id, 'recording_text', 'Replacement');
            $delete = "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN( {$replacement_id} )";
            $this->assertSame(0, $wpdb->query(ll_tools_recording_write_fence_query($delete, $scope['lease'], $id, 'recording_text', $before)));
            $update = $wpdb->prepare("UPDATE `{$wpdb->postmeta}` SET `meta_value` = %s WHERE `post_id` = %d AND `meta_key` = %s", 'Stale', $id, 'recording_text');
            $this->assertSame(0, $wpdb->query(ll_tools_recording_write_fence_query($update, $scope['lease'], $id, 'recording_text', $before)));
            $current = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='recording_text'", $id), ARRAY_A);
            ll_tools_mutation_job_release($scope['lease']);
            $this->assertSame(0, $wpdb->query(ll_tools_recording_write_fence_query($update, $scope['lease'], $id, 'recording_text', $current)));
        } finally { ll_tools_recording_write_release($scope); }
        wp_cache_delete($id, 'post_meta');
        $this->assertSame('Replacement', get_post_meta($id, 'recording_text', true));
    }

    public function test_duplicate_nonunique_add_fails_without_a_second_row(): void
    {
        $id = $this->recording();
        $this->assertFalse(ll_tools_recording_add_post_meta($id, 'recording_text', 'Original'));
        $this->assertWPError(ll_tools_recording_write_error($id));
        $this->assertSame(['Original'], get_post_meta($id, 'recording_text', false));
        $this->assertFalse(ll_tools_recording_add_post_meta($id, 'recording_text', 'Original', true));
        $this->assertNull(ll_tools_recording_write_error($id));
    }

    public function test_update_fence_does_not_match_another_recording_with_the_same_id_prefix(): void
    {
        global $wpdb;
        $id = $this->recording();
        $scope = ll_tools_recording_write_acquire($id);
        $this->assertIsArray($scope);
        try {
            $sql = $wpdb->prepare("UPDATE `{$wpdb->postmeta}` SET `meta_value` = %s WHERE `post_id` = %d AND `meta_key` = %s", 'Another hook', $id * 10 + 3, 'recording_text');
            $this->assertSame($sql, ll_tools_recording_write_fence_query($sql, $scope['lease'], $id, 'recording_text', []));
        } finally { ll_tools_recording_write_release($scope); }
    }
}
