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

    private function withLatin1Connection(callable $callback): void
    {
        global $wpdb;
        $previous = $wpdb->get_row('SELECT @@character_set_client AS client_charset, @@character_set_connection AS connection_charset, @@character_set_results AS results_charset, @@collation_connection AS connection_collation', ARRAY_A);
        $wpdb->set_charset($wpdb->dbh, 'latin1', 'latin1_swedish_ci');
        try { $callback(); }
        finally {
            $wpdb->set_charset($wpdb->dbh, $previous['client_charset']);
            $wpdb->query($wpdb->prepare('SET character_set_connection = %s, character_set_results = %s, collation_connection = %s', $previous['connection_charset'], $previous['results_charset'], $previous['connection_collation']));
        }
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
        $before = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value, HEX(meta_value) AS meta_value_hex FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='recording_text'", $id), ARRAY_A);
        try {
            delete_post_meta($id, 'recording_text');
            $replacement_id = add_post_meta($id, 'recording_text', 'Replacement');
            $delete = "DELETE FROM {$wpdb->postmeta} WHERE meta_id IN( {$replacement_id} )";
            $this->assertSame(0, $wpdb->query(ll_tools_recording_write_fence_query($delete, $scope['lease'], $id, 'recording_text', $before)));
            $update = $wpdb->prepare("UPDATE `{$wpdb->postmeta}` SET `meta_value` = %s WHERE `post_id` = %d AND `meta_key` = %s", 'Stale', $id, 'recording_text');
            $this->assertSame(0, $wpdb->query(ll_tools_recording_write_fence_query($update, $scope['lease'], $id, 'recording_text', $before)));
            $current = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value, HEX(meta_value) AS meta_value_hex FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='recording_text'", $id), ARRAY_A);
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

    public function test_unicode_transcriptions_with_apostrophes_and_combining_marks_keep_exact_preimages(): void
    {
        $id = $this->recording();
        update_post_meta($id, 'recording_text', "Şâ'hmeran ha ça da");
        update_post_meta($id, 'recording_ipa', 'ʃahmɛran ha t͡ʃa da');
        $result = ll_tools_recording_write_run($id, static function () use ($id): void {
            ll_tools_recording_update_post_meta($id, 'recording_text', "Şâ'hmeran ha çê da");
            ll_tools_recording_update_post_meta($id, 'recording_ipa', 'ʃahmæran ha t͡ʃɛ da');
        });
        $this->assertNotWPError($result);
        $this->assertNull(ll_tools_recording_write_error($id));
        $this->assertSame("Şâ'hmeran ha çê da", get_post_meta($id, 'recording_text', true));
        $this->assertSame('ʃahmæran ha t͡ʃɛ da', get_post_meta($id, 'recording_ipa', true));
    }

    public function test_unicode_field_updates_and_deletes_work_when_connection_and_storage_bytes_differ(): void
    {
        global $wpdb;
        $id = $this->recording();
        $this->withLatin1Connection(function () use ($wpdb, $id): void {
            // Model legacy UTF-8 application text written through a latin1
            // connection to a Unicode column, without changing its encoding.
            $original = "Şâ'hmeran ha ça da";
            $updated = "Şâ'hmeran ha çê da";
            update_post_meta($id, 'recording_text', $original);
            $stored = $wpdb->get_row($wpdb->prepare("SELECT HEX(meta_value) AS stored_hex, BINARY meta_value = BINARY %s AS old_fence_matches, meta_value = %s AS text_matches FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'recording_text'", $original, $original, $id), ARRAY_A);
            $this->assertNotSame(strtoupper(bin2hex($original)), $stored['stored_hex']);
            $this->assertSame('0', (string) $stored['old_fence_matches']);
            $this->assertSame('1', (string) $stored['text_matches']);
            $result = ll_tools_recording_write_run($id, function () use ($id, $updated): void {
                $this->assertNotFalse(ll_tools_recording_update_post_meta($id, 'recording_text', $updated));
                $this->assertSame($updated, get_post_meta($id, 'recording_text', true));
                $this->assertNotFalse(ll_tools_recording_update_post_meta($id, 'recording_ipa', 'ʃahmæran ha t͡ʃɛ da'));
                $this->assertNotFalse(ll_tools_recording_update_post_meta($id, 'recording_ipa', 'ʃahmæran ha t͡ʃa da'));
                $this->assertTrue(ll_tools_recording_delete_post_meta($id, 'recording_text'));
            });
            $this->assertNotWPError($result);
            $this->assertNull(ll_tools_recording_write_error($id));
            $this->assertSame('', get_post_meta($id, 'recording_text', true));
            $this->assertSame('ʃahmæran ha t͡ʃa da', get_post_meta($id, 'recording_ipa', true));
        });
    }

    public function test_connection_transcoding_cannot_hide_physical_changes_from_outer_snapshot(): void
    {
        global $wpdb;
        $id = $this->recording();
        $this->withLatin1Connection(function () use ($wpdb, $id): void {
            $this->assertSame(1, $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = CONVERT(UNHEX('CA83') USING utf8mb4) WHERE post_id = %d AND meta_key = 'recording_text'", $id)));
            $scope = ll_tools_recording_write_acquire($id);
            $this->assertIsArray($scope);
            try {
                $original = get_post_meta($id, 'recording_text', true);
                $this->assertSame(1, $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = CONVERT(UNHEX('C99B') USING utf8mb4) WHERE post_id = %d AND meta_key = 'recording_text'", $id)));
                $current = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'recording_text'", $id));
                $this->assertSame($original, $current, 'The latin1 result charset masks distinct Unicode storage bytes.');
                $this->assertFalse(ll_tools_recording_update_post_meta($id, 'recording_text', 'Stale overwrite'));
                $this->assertWPError(ll_tools_recording_write_error($id));
                $this->assertSame('C99B', $wpdb->get_var($wpdb->prepare("SELECT HEX(meta_value) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'recording_text'", $id)));
            } finally { ll_tools_recording_write_release($scope); }
        });
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
