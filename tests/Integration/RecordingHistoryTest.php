<?php
declare(strict_types=1);

final class RecordingHistoryTest extends LL_Tools_TestCase
{
    private int $recorder;
    private int $staff;
    private int $wordset;

    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_register_or_refresh_audio_recorder_role();
        $this->recorder = self::factory()->user->create(['role' => 'audio_recorder']);
        $this->staff = self::factory()->user->create(['role' => 'administrator']);
        $this->wordset = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'History wordset']);
        $this->assign($this->wordset);
        wp_set_current_user($this->recorder);
    }

    private function assign(int $wordset): void
    {
        update_user_meta($this->recorder, 'll_recording_config', [
            'wordset' => get_term($wordset, 'wordset')->slug,
        ]);
    }

    private function word(string $status = 'publish', int $wordset = 0): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'words', 'post_status' => $status, 'post_author' => $this->staff,
            'post_title' => 'History word ' . wp_generate_uuid4(),
        ]);
        wp_set_object_terms($id, [$wordset ?: $this->wordset], 'wordset');
        return $id;
    }

    private function recording(int $word, ?int $speaker = null, ?int $author = null, string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type' => 'word_audio', 'post_status' => $status,
            'post_parent' => $word, 'post_author' => $author ?? $this->recorder,
        ]);
        if ($speaker !== null) { update_post_meta($id, 'speaker_user_id', $speaker); }
        update_post_meta($id, 'audio_file_path', '/wp-content/uploads/history-' . $id . '.mp3');
        update_post_meta($id, 'recording_date', '2026-09-06 10:00:00');
        return $id;
    }

    private function ids(array $page): array
    {
        return array_map('intval', wp_list_pluck($page['items'], 'id'));
    }

    public function test_effective_speaker_overrides_author_and_legacy_author_fallback_is_exact(): void
    {
        $word = $this->word();
        $spoken = $this->recording($word, $this->recorder, $this->staff);
        $legacy = $this->recording($word);
        $zero = $this->recording($word, 0);
        $other = $this->recording($word, $this->staff, $this->recorder);
        $other_legacy = $this->recording($word, null, $this->staff);
        // Malformed duplicate metadata must use the same first-row attribution
        // in both SQL admission and object-level authorization.
        add_post_meta($other, 'speaker_user_id', $this->recorder);
        add_post_meta($spoken, 'speaker_user_id', $this->staff);
        $page = ll_tools_recording_history_page($this->wordset);
        $this->assertSame([$zero, $legacy, $spoken], $this->ids($page));
        $this->assertNotContains($other_legacy, $this->ids($page));
        $this->assertSame([], array_intersect(['delete_url', 'rerecord_url', 'edit_url', 'speaker_user_id'], array_keys($page['items'][0])));
    }

    public function test_own_processing_upload_on_staff_draft_is_visible_without_edit_rights(): void
    {
        update_term_meta($this->wordset, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        $word = $this->word('draft');
        $audio = $this->recording($word, $this->recorder, null, 'draft');
        update_post_meta($audio, '_ll_needs_audio_processing', '1');
        $type = self::factory()->term->create(['taxonomy' => 'recording_type', 'name' => 'Custom recording', 'slug' => 'history-custom']);
        wp_set_object_terms($audio, [$type], 'recording_type');
        $this->assertFalse(current_user_can('edit_posts'));
        $this->assertFalse(current_user_can('read_post', $word));
        $page = ll_tools_recording_history_page($this->wordset);
        $this->assertSame([$audio], $this->ids($page));
        $item = $page['items'][0];
        $this->assertSame('processing', $item['status']);
        $this->assertSame('Awaiting processing', $item['status_label']);
        $this->assertSame('Custom recording', $item['recording_type']);
        $this->assertNotEmpty($item['date']);
        $this->assertNotEmpty($item['audio_url']);
        $this->assertSame('', $item['word_url']);
    }

    public function test_private_password_hidden_and_foreign_scope_objects_do_not_expose_labels_or_media(): void
    {
        $visible_word = $this->word();
        $visible = $this->recording($visible_word);
        $private = $this->recording($this->word('private'));
        $password_word = $this->word();
        wp_update_post(['ID' => $password_word, 'post_password' => 'hidden-password']);
        $this->recording($password_word);
        $password_recording = $this->recording($visible_word);
        wp_update_post(['ID' => $password_recording, 'post_password' => 'hidden-recording-password']);
        $this->assertNull(ll_tools_recording_history_item($password_recording, $this->wordset));
        $other_wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $this->recording($this->word('publish', $other_wordset));
        $hidden_word = $this->word();
        $category = self::factory()->term->create(['taxonomy' => 'word-category', 'name' => 'Secret history label']);
        update_term_meta($category, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $this->wordset);
        update_term_meta($category, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        wp_set_object_terms($hidden_word, [$category], 'word-category');
        $this->recording($hidden_word);
        $page = ll_tools_recording_history_page($this->wordset);
        $this->assertSame([$visible], $this->ids($page));
        $this->assertNotContains($private, $this->ids($page));
        $this->assertStringNotContainsString('Secret history label', wp_json_encode($page));
        $this->assertNotEmpty($page['items'][0]['word_url']);
        update_term_meta($category, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$this->recorder]);
        $page = ll_tools_recording_history_page($this->wordset);
        $this->assertCount(2, $page['items']);
        $this->assertSame('Secret history label', $page['items'][0]['categories'][0]['name']);
    }

    public function test_assignment_removal_revokes_existing_cursor_and_unassigned_public_scope_is_denied(): void
    {
        $word = $this->word();
        $audio = $this->recording($word);
        $cursor = ll_tools_recording_history_cursor($this->wordset, $audio + 1);
        // created_wordset deliberately seeds the current user as its manager.
        wp_set_current_user(0);
        $other = self::factory()->term->create(['taxonomy' => 'wordset']);
        wp_set_current_user($this->recorder);
        $this->assertWPError(ll_tools_recording_history_page($other));
        $this->assign($other);
        $result = ll_tools_recording_history_page($this->wordset, $cursor);
        $this->assertWPError($result);
        $this->assertSame('ll_recording_history_forbidden', $result->get_error_code());
        delete_user_meta($this->recorder, 'll_recording_config');
        $this->assertWPError(ll_tools_recording_history_page($other));
    }

    public function test_administrator_history_still_only_returns_own_effective_attribution(): void
    {
        $word = $this->word();
        $this->recording($word, $this->recorder, $this->staff);
        $staff_audio = $this->recording($word, $this->staff, $this->recorder);
        wp_set_current_user($this->staff);
        $this->assertSame([$staff_audio], $this->ids(ll_tools_recording_history_page($this->wordset)));
    }

    public function test_prompt_history_uses_current_canonical_attachment_and_recorded_by(): void
    {
        $prompt = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE, 'post_status' => 'publish',
            'post_title' => 'History prompt', 'post_author' => $this->staff,
        ]);
        wp_set_object_terms($prompt, [$this->wordset], 'wordset');
        $old = self::factory()->post->create(['post_type' => 'attachment', 'post_status' => 'inherit',
            'post_parent' => $prompt, 'post_author' => $this->recorder, 'post_mime_type' => 'audio/mpeg']);
        $active = self::factory()->post->create(['post_type' => 'attachment', 'post_status' => 'inherit',
            'post_parent' => $prompt, 'post_author' => $this->staff, 'post_mime_type' => 'audio/mpeg',
            'guid' => 'https://example.org/history-prompt.mp3']);
        update_post_meta($prompt, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, $active);
        update_post_meta($prompt, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY, $this->recorder);
        $page = ll_tools_recording_history_page($this->wordset);
        $this->assertSame([$active], $this->ids($page));
        $this->assertNotContains($old, $this->ids($page));
        $this->assertSame('Prompt audio', $page['items'][0]['recording_type']);
        $this->assertSame('', $page['items'][0]['word_url']);
        update_post_meta($prompt, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY, $this->staff);
        $this->assertSame([], $this->ids(ll_tools_recording_history_page($this->wordset)));
    }

    public function test_keyset_pages_are_bounded_and_stable_when_new_recording_arrives(): void
    {
        $word = $this->word();
        $expected = [];
        for ($i = 0; $i < 43; $i++) { $expected[] = $this->recording($word); }
        rsort($expected, SORT_NUMERIC);
        $first = ll_tools_recording_history_page($this->wordset);
        $this->assertCount(20, $first['items']);
        $this->recording($word);
        $second = ll_tools_recording_history_page($this->wordset, $first['next_cursor']);
        $third = ll_tools_recording_history_page($this->wordset, $second['next_cursor']);
        $this->assertCount(20, $second['items']);
        $this->assertCount(3, $third['items']);
        $this->assertFalse($third['has_more']);
        $this->assertSame('', $third['next_cursor']);
        $this->assertSame($expected, array_merge($this->ids($first), $this->ids($second), $this->ids($third)));
    }

    public function test_filtered_rows_have_a_fixed_scan_budget_and_continuation(): void
    {
        global $wpdb;
        $word = $this->word('private');
        for ($i = 0; $i < 101; $i++) { $this->recording($word); }
        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (strpos($query, 'SELECT recording.ID, recording.post_parent') !== false) { $queries[] = $query; }
            return $query;
        };
        add_filter('query', $capture);
        try { $first = ll_tools_recording_history_page($this->wordset); }
        finally { remove_filter('query', $capture); }
        $this->assertSame([], $first['items']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(4, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('LIMIT 26', $query);
            $this->assertStringNotContainsString('OFFSET', $query);
        }
        $next = ll_tools_recording_history_page($this->wordset, $first['next_cursor']);
        $this->assertSame([], $next['items']);
        $this->assertFalse($next['has_more']);
    }

    public function test_cursor_is_bound_to_user_wordset_and_signature(): void
    {
        $cursor = ll_tools_recording_history_cursor($this->wordset, 999);
        $this->assertSame(999, ll_tools_recording_history_parse_cursor($cursor, $this->wordset));
        $this->assertWPError(ll_tools_recording_history_parse_cursor($cursor . 'a', $this->wordset));
        $this->assertWPError(ll_tools_recording_history_parse_cursor($cursor, $this->wordset + 1));
        $this->assertWPError(ll_tools_recording_history_parse_cursor(str_repeat('x', 513), $this->wordset));
        wp_set_current_user($this->staff);
        $this->assertWPError(ll_tools_recording_history_parse_cursor($cursor, $this->wordset));
    }

    public function test_database_failure_returns_error_instead_of_empty_success(): void
    {
        global $wpdb;
        $failure = static function (string $query): string {
            return strpos($query, 'SELECT recording.ID, recording.post_parent') !== false
                ? 'SELECT ID FROM ll_tools_missing_history_table' : $query;
        };
        $suppress = $wpdb->suppress_errors(true);
        add_filter('query', $failure);
        try { $result = ll_tools_recording_history_page($this->wordset); }
        finally { remove_filter('query', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertWPError($result);
        $this->assertSame('ll_recording_history_unavailable', $result->get_error_code());
    }

    public function test_assignment_revoked_during_query_discards_the_built_page(): void
    {
        $this->recording($this->word());
        $other = self::factory()->term->create(['taxonomy' => 'wordset']);
        $changed = false;
        $revoke = function (string $query) use ($other, &$changed): string {
            if (!$changed && strpos($query, 'SELECT recording.ID, recording.post_parent') !== false) {
                $changed = true;
                $this->assign($other);
            }
            return $query;
        };
        add_filter('query', $revoke);
        try { $result = ll_tools_recording_history_page($this->wordset); }
        finally { remove_filter('query', $revoke); }
        $this->assertTrue($changed);
        $this->assertWPError($result);
    }

    public function test_renderer_is_lazy_and_unauthorized_scope_has_no_history_markup(): void
    {
        $this->recording($this->word());
        $html = ll_tools_recording_history_render($this->wordset);
        $this->assertStringContainsString('data-ll-recording-history', $html);
        $this->assertStringContainsString('My recordings', $html);
        $this->assertStringNotContainsString('<audio', $html);
        $this->assertStringNotContainsString('.mp3', $html);
        wp_set_current_user(0);
        $this->assertSame('', ll_tools_recording_history_render($this->wordset));
        $this->assertFalse(has_action('wp_ajax_nopriv_ll_tools_recording_history'));
    }

    public function test_ajax_requires_nonce_and_current_user_scope_and_rejects_array_inputs(): void
    {
        $word = $this->word();
        $own = $this->recording($word);
        $this->recording($word, $this->staff, $this->staff);
        $base = ['nonce' => wp_create_nonce('ll_tools_recording_history'), 'wordset_id' => (string) $this->wordset];
        $response = $this->ajax($base + ['user_id' => $this->staff]);
        $this->assertTrue($response['success']);
        $this->assertSame([$own], $this->ids($response['data']));
        $this->assertFalse($this->ajax(array_merge($base, ['nonce' => 'wrong']))['success']);
        $this->assertFalse($this->ajax(array_merge($base, ['wordset_id' => [$this->wordset]]))['success']);
        $this->assertFalse($this->ajax(array_merge($base, ['cursor' => ['invalid']]))['success']);
        wp_set_current_user(0);
        $this->assertFalse($this->ajax($base)['success']);
    }

    private function ajax(array $post): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = $post;
        $_REQUEST = $post;
        $die_filter = static function () {
            return static function (): void { throw new RuntimeException('history_wp_die'); };
        };
        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', '__return_true');
        ob_start();
        try {
            ll_tools_recording_history_ajax();
        } catch (RuntimeException $error) {
            $this->assertSame('history_wp_die', $error->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', '__return_true');
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }
        $response = json_decode($output, true);
        $this->assertIsArray($response);
        return $response;
    }
}
