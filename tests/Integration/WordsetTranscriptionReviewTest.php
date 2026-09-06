<?php
declare(strict_types=1);

final class WordsetTranscriptionReviewTest extends LL_Tools_TestCase
{
    private int $wordset;
    private int $manager;
    private ?wpdb $myisamDdlConnection = null;
    private string $myisamTable = '';

    protected function tearDown(): void
    {
        try { parent::tearDown(); }
        finally {
            // The main test transaction owns a metadata lock until parent teardown.
            // Only then may the auxiliary DDL connection drop this exact fixture.
            if ($this->myisamDdlConnection instanceof wpdb) {
                try {
                    $this->assertTrue(mysqli_query($this->myisamDdlConnection->dbh, "DROP TABLE IF EXISTS {$this->myisamTable}"));
                } finally {
                    $this->myisamDdlConnection->close();
                    $this->myisamDdlConnection = null;
                    $this->myisamTable = '';
                }
            }
        }
    }

    protected function setUp(): void
    {
        if ($this->name() === 'test_saves_and_review_clear_work_on_a_real_myisam_metadata_table') {
            global $wpdb;
            // Create before the main transaction takes its data-dictionary snapshot.
            $this->myisamTable = $wpdb->prefix . 'll_review_myisam_' . substr(md5(wp_generate_uuid4()), 0, 12);
            $this->myisamDdlConnection = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $this->assertTrue(mysqli_query($this->myisamDdlConnection->dbh, 'SET SESSION lock_wait_timeout = 3'));
            // Raw DDL bypasses the WP harness's CREATE-to-TEMPORARY rewrite;
            // the auxiliary connection cannot commit the main test transaction.
            $this->assertTrue(mysqli_query($this->myisamDdlConnection->dbh, "CREATE TABLE {$this->myisamTable} (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL DEFAULT 0, meta_key varchar(255), meta_value longtext, PRIMARY KEY (meta_id), KEY post_id (post_id), KEY meta_key (meta_key(191))) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4"));
            $status = mysqli_fetch_assoc(mysqli_query($this->myisamDdlConnection->dbh, $wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $this->myisamTable)));
            $this->assertSame('MyISAM', $status['Engine']);
        }
        parent::setUp();
        ll_tools_wordset_transcription_review_load_runtime();
        $this->wordset = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Review scope']);
        $this->manager = self::factory()->user->create(['role' => 'wordset_manager']);
        $user = get_userdata($this->manager);
        $user->add_cap('view_ll_tools');
        ll_tools_set_wordset_manager_user_ids($this->wordset, [$this->manager], $this->manager);
        wp_set_current_user($this->manager);
    }

    private function recording(int $wordset = 0, string $status = 'publish'): int
    {
        $word = self::factory()->post->create([
            'post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Review word', 'post_author' => $this->manager,
        ]);
        wp_set_object_terms($word, [$wordset ?: $this->wordset], 'wordset');
        update_post_meta($word, 'word_translation', 'Translation');
        $recording = self::factory()->post->create([
            'post_type' => 'word_audio', 'post_status' => $status, 'post_parent' => $word, 'post_author' => $this->manager,
        ]);
        update_post_meta($recording, 'recording_text', 'Original text');
        update_post_meta($recording, 'recording_ipa', 'aba');
        update_post_meta($recording, 'audio_file_path', 'wp-content/uploads/review/audio.mp3');
        return $recording;
    }

    public function test_scoped_manager_can_list_listen_and_autosave_without_global_edit_others_cap(): void
    {
        $id = $this->recording();
        $other_author = self::factory()->user->create(['role' => 'administrator']);
        wp_update_post(['ID' => $id, 'post_author' => $other_author]);
        wp_update_post(['ID' => wp_get_post_parent_id($id), 'post_author' => $other_author]);
        $this->assertFalse(current_user_can('edit_others_posts'));
        $result = ll_tools_wordset_transcription_review_list($this->wordset);
        $this->assertIsArray($result);
        $row = $result['recordings'][0];
        $this->assertSame($id, $row['recording_id']);
        $this->assertStringContainsString('/review/audio.mp3', $row['audio_url']);
        $saved = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'Updated text', $row['revisions']['recording_text']);
        $this->assertIsArray($saved);
        $this->assertSame('Updated text', get_post_meta($id, 'recording_text', true));
        $this->assertSame('aba', get_post_meta($id, 'recording_ipa', true));
    }

    public function test_scope_is_checked_on_list_single_read_and_write(): void
    {
        wp_set_current_user(0);
        $other = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Other scope']);
        wp_set_current_user($this->manager);
        $id = $this->recording($other);
        $this->assertWPError(ll_tools_wordset_transcription_review_get($this->wordset, $id));
        $this->assertWPError(ll_tools_wordset_transcription_review_list($other));
        $this->assertWPError(ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'Leak', str_repeat('a', 64)));
        $this->assertSame([], ll_tools_wordset_transcription_review_list($this->wordset)['recordings']);
        $viewer = self::factory()->user->create(['role' => 'subscriber']);
        get_userdata($viewer)->add_cap('view_ll_tools');
        wp_set_current_user($viewer);
        $this->assertWPError(ll_tools_wordset_transcription_review_list($this->wordset));
        $this->assertSame('', ll_tools_render_wordset_transcription_review($this->wordset));
        wp_set_current_user(0);
        $this->assertWPError(ll_tools_wordset_transcription_review_list($this->wordset));
    }

    public function test_private_recording_object_requires_read_and_edit_capability_before_media_resolution(): void
    {
        $id = $this->recording(0, 'private');
        $other_author = self::factory()->user->create(['role' => 'administrator']);
        wp_update_post(['ID' => $id, 'post_author' => $other_author]);
        $this->assertWPError(ll_tools_wordset_transcription_review_get($this->wordset, $id));
        $this->assertSame([], ll_tools_wordset_transcription_review_list($this->wordset)['recordings']);
    }

    public function test_incomplete_category_read_is_retryable_instead_of_an_empty_page(): void
    {
        $id = $this->recording();
        $fail = static function ($terms, $objects, $taxonomies) {
            return in_array('word-category', (array) $taxonomies, true) ? new WP_Error('read_failed') : $terms;
        };
        add_filter('get_object_terms', $fail, 10, 3);
        try {
            $page = ll_tools_wordset_transcription_review_list($this->wordset);
            $single = ll_tools_wordset_transcription_review_get($this->wordset, $id);
        } finally { remove_filter('get_object_terms', $fail, 10); }
        $this->assertWPError($page);
        $this->assertSame('read_failed', $page->get_error_code());
        $this->assertSame(503, $page->get_error_data()['status']);
        $this->assertWPError($single);
    }

    public function test_saves_and_review_clear_work_on_a_real_myisam_metadata_table(): void
    {
        global $wpdb;
        $id = $this->recording();
        $original_table = $wpdb->postmeta;
        $temporary_table = $this->myisamTable;
        $word_id = (int) wp_get_post_parent_id($id);
        try {
            $this->assertNotFalse($wpdb->query("INSERT INTO {$temporary_table} SELECT * FROM {$original_table} WHERE post_id IN ({$id}, {$word_id})"));
            $wpdb->postmeta = $temporary_table;
            wp_cache_delete($id, 'post_meta');
            $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
            $saved = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'MyISAM text', $row['revisions']['recording_text']);
            $this->assertIsArray($saved);
            $note = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'review_note', "First note with \ slash", $row['revisions']['review_note']);
            $this->assertIsArray($note);
            $this->assertSame("First note with \ slash", $note['recording']['review_note']);
            ll_tools_ipa_keyboard_mark_recording_needs_auto_review($id, 'recording_text');
            $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
            $reviewed = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'review_fields', 'reviewed', $row['revisions']['review_fields']);
            $this->assertIsArray($reviewed);
            $this->assertFalse($reviewed['recording']['needs_review']);
            $this->assertSame('', $reviewed['recording']['review_note']);
            $this->assertSame('MyISAM text', $reviewed['recording']['recording_text']);
        } finally {
            $wpdb->postmeta = $original_table;
            wp_cache_delete($id, 'post_meta');
        }
    }

    public function test_cursor_pages_do_not_duplicate_or_skip_recordings_and_do_not_count_whole_scope(): void
    {
        $ids = [];
        for ($i = 0; $i < 22; $i++) { $ids[] = $this->recording(); }
        $queries = [];
        $capture = static function ($sql) use (&$queries) { $queries[] = $sql; return $sql; };
        add_filter('query', $capture);
        try {
            $first = ll_tools_wordset_transcription_review_list($this->wordset);
            $second = ll_tools_wordset_transcription_review_list($this->wordset, '', $first['next_cursor']);
        } finally { remove_filter('query', $capture); }
        $this->assertCount(20, $first['recordings']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(2, $second['recordings']);
        $this->assertFalse($second['has_more']);
        $this->assertSame($ids, array_column(array_merge($first['recordings'], $second['recordings']), 'recording_id'));
        $list_queries = array_values(array_filter($queries, static fn($sql) => str_contains($sql, 'SELECT a.ID FROM')));
        $this->assertCount(2, $list_queries);
        foreach ($list_queries as $sql) {
            $this->assertStringContainsString('LIMIT 76', $sql);
            $this->assertStringNotContainsString('COUNT(', $sql);
            $this->assertStringNotContainsString('OFFSET', $sql);
        }
    }

    public function test_search_and_review_filter_use_current_recording_metadata(): void
    {
        $first = $this->recording();
        $second = $this->recording();
        update_post_meta($second, 'recording_text', 'needle');
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($second, 'recording_text', 'Check spelling');
        $matches = ll_tools_wordset_transcription_review_list($this->wordset, 'needle', 0, true);
        $this->assertSame([$second], array_column($matches['recordings'], 'recording_id'));
        $this->assertSame('Check spelling', $matches['recordings'][0]['review_note']);
        $this->assertSame([], ll_tools_wordset_transcription_review_list($this->wordset, 'Original', 0, true)['recordings']);
        $this->assertNotSame($first, $second);
    }

    public function test_search_narrows_before_candidate_limit_and_treats_percent_as_literal(): void
    {
        $first = $this->recording();
        $word = (int) wp_get_post_parent_id($first);
        for ($i = 0; $i < 76; $i++) {
            self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $word]);
        }
        $last = $this->recording();
        update_post_meta($last, 'recording_text', 'needle 100%');
        $page = ll_tools_wordset_transcription_review_list($this->wordset, 'needle');
        $this->assertSame([$last], array_column($page['recordings'], 'recording_id'));
        $this->assertFalse($page['has_more']);
        $this->assertSame([$last], array_column(ll_tools_wordset_transcription_review_list($this->wordset, '%')['recordings'], 'recording_id'));
    }

    public function test_stale_field_is_rejected_while_unrelated_field_can_save(): void
    {
        $id = $this->recording();
        $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
        update_post_meta($id, 'recording_text', 'Another editor');
        $error = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'Stale edit', $row['revisions']['recording_text']);
        $this->assertWPError($error);
        $this->assertSame('edit_conflict', $error->get_error_code());
        $this->assertSame('Another editor', get_post_meta($id, 'recording_text', true));
        $saved = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_ipa', 'ʃa', $row['revisions']['recording_ipa']);
        $this->assertIsArray($saved);
        $this->assertSame('ʃa', get_post_meta($id, 'recording_ipa', true));
    }

    public function test_locked_database_snapshot_overrides_stale_object_cache(): void
    {
        global $wpdb;
        $id = $this->recording();
        $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
        $wpdb->update($wpdb->postmeta, ['meta_value' => 'External writer'], ['post_id' => $id, 'meta_key' => 'recording_text']);
        $this->assertSame('Original text', get_post_meta($id, 'recording_text', true));
        $error = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'Stale browser', $row['revisions']['recording_text']);
        $this->assertWPError($error);
        $this->assertSame('edit_conflict', $error->get_error_code());
        $this->assertSame('External writer', get_post_meta($id, 'recording_text', true));
    }

    public function test_review_confirmation_cannot_clear_a_newer_review_note(): void
    {
        $id = $this->recording();
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($id);
        $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
        ll_tools_ipa_keyboard_set_recording_review_note($id, 'Another reviewer added this.');
        $error = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'review_fields', 'reviewed', $row['revisions']['review_fields']);
        $this->assertWPError($error);
        $this->assertSame('edit_conflict', $error->get_error_code());
        $this->assertTrue(ll_tools_ipa_keyboard_recording_needs_auto_review($id));
        $this->assertSame('Another reviewer added this.', ll_tools_ipa_keyboard_get_recording_review_note($id));
    }

    public function test_review_note_saves_and_mark_reviewed_clears_existing_review_state(): void
    {
        $id = $this->recording();
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($id, 'recording_ipa');
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($id, 'recording_text');
        $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
        $saved = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'review_note', "Check\nthis", $row['revisions']['review_note']);
        $this->assertIsArray($saved);
        $this->assertSame("Check\nthis", $saved['recording']['review_note']);
        $reviewed = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'review_fields', 'reviewed', $saved['recording']['revisions']['review_fields']);
        $this->assertIsArray($reviewed);
        $this->assertFalse($reviewed['recording']['needs_review']);
        $this->assertSame('', $reviewed['recording']['review_note']);
    }

    public function test_failed_write_is_not_reported_saved_and_a_busy_recording_is_not_changed(): void
    {
        $id = $this->recording();
        $row = ll_tools_wordset_transcription_review_get($this->wordset, $id)['recording'];
        $block = static function ($check, $object_id, $key) use ($id) { return $object_id === $id && $key === 'recording_text' ? false : $check; };
        add_filter('update_post_metadata', $block, 10, 3);
        try { $error = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'Cannot save', $row['revisions']['recording_text']); }
        finally { remove_filter('update_post_metadata', $block, 10); }
        $this->assertWPError($error);
        $this->assertSame('save_unverified', $error->get_error_code());
        $lease = ll_tools_mutation_job_acquire('recording_metadata', (string) $id);
        $this->assertIsArray($lease);
        try { $error = ll_tools_wordset_transcription_review_save($this->wordset, $id, 'recording_text', 'Cannot save', $row['revisions']['recording_text']); }
        finally { ll_tools_mutation_job_release($lease); }
        $this->assertWPError($error);
        $this->assertSame('Original text', get_post_meta($id, 'recording_text', true));
    }

    public function test_request_bounds_and_ajax_shape_are_rejected_without_mutation(): void
    {
        $id = $this->recording();
        $this->assertWPError(ll_tools_wordset_transcription_review_list($this->wordset, str_repeat('a', 161)));
        $this->assertWPError(ll_tools_wordset_transcription_review_save($this->wordset, $id, 'post_title', 'No', str_repeat('a', 64)));
        $backup_post = $_POST;
        $backup_request = $_REQUEST;
        $_POST = ['action' => 'll_tools_save_wordset_transcription_review', 'nonce' => wp_create_nonce('ll_wordset_transcription_review'), 'wordset_id' => [$this->wordset]];
        $_REQUEST = $_POST;
        try { $response = $this->runJsonEndpoint(static function (): void { ll_tools_wordset_transcription_review_ajax(); }); }
        finally { $_POST = $backup_post; $_REQUEST = $backup_request; }
        $this->assertFalse($response['success']);
        $this->assertSame('Original text', get_post_meta($id, 'recording_text', true));
    }

    private function runJsonEndpoint(callable $callback): array
    {
        $die = static function (): void { throw new RuntimeException('wp_die'); };
        $filter = static function () use ($die) { return $die; };
        $ajax = static function (): bool { return true; };
        add_filter('wp_die_handler', $filter);
        add_filter('wp_die_ajax_handler', $filter);
        add_filter('wp_doing_ajax', $ajax);
        ob_start();
        try { $callback(); }
        catch (RuntimeException $error) { $this->assertSame('wp_die', $error->getMessage()); }
        finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $filter);
            remove_filter('wp_die_ajax_handler', $filter);
            remove_filter('wp_doing_ajax', $ajax);
        }
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
