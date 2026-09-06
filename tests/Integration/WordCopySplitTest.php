<?php
declare(strict_types=1);

final class WordCopySplitTest extends LL_Tools_TestCase
{
    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($id);
    }

    protected function tearDown(): void
    {
        foreach ($this->receipts as $key) { delete_option($key); }
        foreach ((array) ($GLOBALS['ll_tools_mutation_job_owners'] ?? []) as $lease) { ll_tools_mutation_job_release($lease); }
        parent::tearDown();
    }

    private function fixture(int $audio_count = 0): array
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $category = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($category, $wordset);
        $word = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft', 'post_title' => 'Same title', 'post_author' => get_current_user_id()]);
        wp_set_object_terms($word, [$wordset], 'wordset');
        wp_set_object_terms($word, [$category], 'word-category');
        update_post_meta($word, 'word_translation', 'Translation');
        update_post_meta($word, 'll_word_usage_note', wp_slash('A \\ path and "quote"'));
        update_post_meta($word, '_ll_tools_sync_uuid', 'source-only-identity');
        $audio_ids = [];
        for ($index = 0; $index < $audio_count; $index++) {
            $id = self::factory()->post->create(['post_type' => 'word_audio', 'post_parent' => $word, 'post_status' => 'draft', 'post_title' => 'Recording ' . $index]);
            update_post_meta($id, 'audio_file_path', '/wp-content/uploads/unchanged-' . $id . '.mp3');
            update_post_meta($id, 'speaker_user_id', get_current_user_id());
            $audio_ids[] = $id;
        }
        return [$wordset, $word, $category, $audio_ids];
    }

    private function apply(int $wordset, int $word, array $ids = [], string $title = '', string $request = '')
    {
        $request = $request ?: wp_generate_uuid4();
        $this->receipts[] = ll_tools_word_copy_receipt_key($wordset, $word, $request);
        return ll_tools_word_copy_apply($wordset, $word, $request, $title, $ids);
    }

    private function manager(int $wordset): int
    {
        $manager = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($wordset, 'manager_user_id', $manager);
        wp_set_current_user($manager);
        return $manager;
    }

    public function test_audio_less_word_copies_title_metadata_and_current_category_without_source_identity(): void
    {
        [$set, $word, $category] = $this->fixture();
        $result = $this->apply($set, $word);
        $this->assertNotWPError($result);
        $this->assertSame('completed', $result['state']);
        $copy = $result['new_word_id'];
        $this->assertNotSame($word, $copy);
        $this->assertSame('Same title', get_the_title($copy));
        $this->assertSame('Translation', get_post_meta($copy, 'word_translation', true));
        $this->assertSame(get_post_meta($word, 'll_word_usage_note', true), get_post_meta($copy, 'll_word_usage_note', true));
        $this->assertSame('', get_post_meta($copy, '_ll_tools_sync_uuid', true));
        $this->assertSame([$set], array_map('intval', wp_get_object_terms($copy, 'wordset', ['fields' => 'ids'])));
        $this->assertSame([$category], array_map('intval', wp_get_object_terms($copy, 'word-category', ['fields' => 'ids'])));
    }

    public function test_copy_shares_image_attachment_and_retains_all_recordings_by_default(): void
    {
        [$set, $word, , $ids] = $this->fixture(2);
        $upload = wp_upload_bits('word-copy.png', null, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII='));
        $this->assertEmpty($upload['error']);
        $attachment = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Shared image', 'post_status' => 'inherit'], $upload['file']);
        update_post_meta($attachment, '_wp_attached_file', _wp_relative_upload_path($upload['file']));
        set_post_thumbnail($word, $attachment);
        $result = $this->apply($set, $word);
        $this->assertSame('completed', $result['state']);
        $this->assertSame($attachment, (int) get_post_thumbnail_id($result['new_word_id']));
        $this->assertSame($attachment, (int) get_post_thumbnail_id($word));
        foreach ($ids as $id) { $this->assertSame($word, (int) wp_get_post_parent_id($id)); }
        $this->assertSame([], $result['moved_ids']);
        $this->assertFileExists($upload['file']);
    }

    public function test_only_explicit_audio_ids_move_and_repeated_request_reuses_the_same_copy(): void
    {
        [$set, $word, , $ids] = $this->fixture(3);
        $request = wp_generate_uuid4();
        $path = get_post_meta($ids[1], 'audio_file_path', true);
        $result = $this->apply($set, $word, [$ids[1]], 'Second meaning', $request);
        $retry = $this->apply($set, $word, [$ids[1]], 'Second meaning', $request);
        $this->assertSame('completed', $result['state']);
        $this->assertSame($result['new_word_id'], $retry['new_word_id']);
        $this->assertSame($result['new_word_id'], (int) wp_get_post_parent_id($ids[1]));
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[2]));
        $this->assertSame($path, get_post_meta($ids[1], 'audio_file_path', true));
        $this->assertSame(get_current_user_id(), (int) get_post_meta($ids[1], 'speaker_user_id', true));
        $this->assertWPError($this->apply($set, $word, [], 'Different copy', $request));
    }

    public function test_assigned_manager_can_copy_another_authors_word_but_not_an_unassigned_wordset(): void
    {
        [$set, $word] = $this->fixture();
        update_post_meta($word, '_ll_skip_audio_requirement_once', '1');
        wp_update_post(['ID' => $word, 'post_status' => 'publish']);
        $this->assertSame('publish', get_post_status($word));
        $manager = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($set, 'manager_user_id', $manager);
        wp_set_current_user($manager);
        $this->assertFalse(current_user_can('edit_others_posts'));
        $result = $this->apply($set, $word);
        $this->assertNotWPError($result);
        $this->assertSame('completed', $result['state']);
        $other_set = self::factory()->term->create(['taxonomy' => 'wordset']);
        $this->assertWPError(ll_tools_word_copy_preview($other_set, $word));
        $this->assertWPError($this->apply($other_set, $word));
    }

    public function test_copy_is_scoped_to_current_wordset_and_foreign_recordings_are_rejected(): void
    {
        [$set, $word, $category, $ids] = $this->fixture(1);
        [$other_set, $other_word, $other_category, $other_ids] = $this->fixture(1);
        wp_set_object_terms($word, [$set, $other_set], 'wordset');
        wp_set_object_terms($word, [$category, $other_category], 'word-category');
        $result = $this->apply($set, $word);
        $this->assertSame([$set], array_map('intval', wp_get_object_terms($result['new_word_id'], 'wordset', ['fields' => 'ids'])));
        $this->assertSame([$category], array_map('intval', wp_get_object_terms($result['new_word_id'], 'word-category', ['fields' => 'ids'])));
        $this->assertWPError($this->apply($set, $word, $other_ids));
        $this->assertSame($other_word, (int) wp_get_post_parent_id($other_ids[0]));
    }

    public function test_manager_cannot_move_recordings_out_of_a_shared_unmanaged_wordset(): void
    {
        [$set, $word, , $ids] = $this->fixture(1);
        $other = self::factory()->term->create(['taxonomy' => 'wordset']);
        wp_set_object_terms($word, [$set, $other], 'wordset');
        $manager = self::factory()->user->create(['role' => 'wordset_manager']);
        update_term_meta($set, 'manager_user_id', $manager);
        wp_set_current_user($manager);
        $this->assertWPError($this->apply($set, $word, $ids));
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
    }

    public function test_recording_preview_is_paged_and_preserves_every_id_once(): void
    {
        [$set, $word, , $ids] = $this->fixture(23);
        $first = ll_tools_word_copy_preview($set, $word);
        $this->assertCount(20, $first['recordings']);
        $this->assertTrue($first['has_more']);
        $last = ll_tools_word_copy_preview($set, $word, $first['after_id']);
        $this->assertCount(3, $last['recordings']);
        $this->assertFalse($last['has_more']);
        $this->assertSame($ids, array_merge(wp_list_pluck($first['recordings'], 'id'), wp_list_pluck($last['recordings'], 'id')));
    }

    public function test_failed_metadata_write_retains_reviewable_copy_and_blocks_replay(): void
    {
        [$set, $word] = $this->fixture();
        $request = wp_generate_uuid4();
        $filter = static fn($check, $id, $key) => (int) $id !== $word && $key === 'll_word_usage_note' ? false : $check;
        add_filter('add_post_metadata', $filter, 10, 3);
        try { $result = $this->apply($set, $word, [], '', $request); }
        finally { remove_filter('add_post_metadata', $filter); }
        $this->assertSame('pending', $result['state']);
        $this->assertGreaterThan(0, $result['new_word_id']);
        $this->assertSame('draft', get_post_status($result['new_word_id']));
        $retry = $this->apply($set, $word, [], '', $request);
        $this->assertSame('pending', $retry['state']);
        $this->assertSame($result['new_word_id'], $retry['new_word_id']);
    }

    public function test_final_checkpoint_failure_does_not_allow_duplicate_or_repeated_audio_move(): void
    {
        [$set, $word, , $ids] = $this->fixture(1);
        $request = wp_generate_uuid4();
        $key = ll_tools_word_copy_receipt_key($set, $word, $request);
        $filter = static fn($value, $old) => ($value['state'] ?? '') === 'completed' ? $old : $value;
        add_filter('pre_update_option_' . $key, $filter, 10, 2);
        try { $result = $this->apply($set, $word, $ids, '', $request); }
        finally { remove_filter('pre_update_option_' . $key, $filter); }
        $this->assertSame('pending', $result['state']);
        $this->assertSame($ids, $result['moved_ids']);
        $retry = $this->apply($set, $word, $ids, '', $request);
        $this->assertSame($result['new_word_id'], $retry['new_word_id']);
        $this->assertSame('pending', $retry['state']);
    }

    public function test_same_source_lock_blocks_overlapping_copy_and_oversized_selection_is_rejected(): void
    {
        [$set, $word] = $this->fixture();
        $lease = ll_tools_mutation_job_acquire('word_copy', (string) $word);
        try { $this->assertWPError($this->apply($set, $word)); }
        finally { ll_tools_mutation_job_release($lease); }
        $this->assertWPError($this->apply($set, $word, range(1, 51)));
    }

    public function test_failed_initial_receipt_creates_no_word(): void
    {
        global $wpdb;
        [$set, $word] = $this->fixture();
        $request = wp_generate_uuid4();
        $key = ll_tools_word_copy_receipt_key($set, $word, $request);
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='words'");
        $filter = static fn($value, $old) => $old;
        add_filter('pre_update_option_' . $key, $filter, 10, 2);
        try { $result = $this->apply($set, $word, [], '', $request); }
        finally { remove_filter('pre_update_option_' . $key, $filter); }
        $this->assertWPError($result);
        $this->assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='words'"));
    }

    public function test_recording_reassigned_after_preflight_is_not_stolen_by_the_copy(): void
    {
        [$set, $word, , $ids] = $this->fixture(1);
        $other = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft']);
        wp_set_object_terms($other, [$set], 'wordset');
        $hook = static function ($id, $post) use ($word, $other, $ids): void {
            if ($post->post_type === 'words' && (int) $id !== $word && (int) $id !== $other) {
                wp_update_post(['ID' => $ids[0], 'post_parent' => $other]);
            }
        };
        add_action('wp_insert_post', $hook, 10, 2);
        try { $result = $this->apply($set, $word, $ids); }
        finally { remove_action('wp_insert_post', $hook); }
        $this->assertSame('pending', $result['state']);
        $this->assertSame([], $result['moved_ids']);
        $this->assertSame($other, (int) wp_get_post_parent_id($ids[0]));
    }

    public function test_ajax_rejects_missing_nonce_before_loading_copy_state(): void
    {
        $backup_post = $_POST; $backup_request = $_REQUEST;
        $_POST = ['action' => 'll_tools_word_copy_preview', 'wordset_id' => 1, 'word_id' => 1]; $_REQUEST = $_POST;
        $handler = static function (): void { throw new RuntimeException('ajax_done'); };
        $filter = static fn() => $handler;
        $ajax = static fn() => true;
        add_filter('wp_die_ajax_handler', $filter); add_filter('wp_doing_ajax', $ajax);
        ob_start();
        try { ll_tools_word_copy_ajax(); }
        catch (RuntimeException $error) { $this->assertSame('ajax_done', $error->getMessage()); }
        finally {
            $output = ob_get_clean();
            remove_filter('wp_die_ajax_handler', $filter); remove_filter('wp_doing_ajax', $ajax);
            $_POST = $backup_post; $_REQUEST = $backup_request;
        }
        $this->assertFalse(json_decode($output, true)['success']);
    }

    public function test_private_source_is_denied_before_any_media_is_resolved(): void
    {
        [$set, $word] = $this->fixture(1);
        wp_update_post(['ID' => $word, 'post_status' => 'private']);
        $this->manager($set);
        $this->assertFalse(current_user_can('read_post', $word));
        $reads = [];
        $spy = static function ($value, $id, $key) use (&$reads) { if (in_array($key, ['audio_file_path', '_thumbnail_id', '_ll_autopicked_image_id'], true)) { $reads[] = $key; } return $value; };
        add_filter('get_post_metadata', $spy, 10, 3);
        try {
            $this->assertWPError(ll_tools_word_copy_preview($set, $word));
            $this->assertWPError($this->apply($set, $word));
        } finally { remove_filter('get_post_metadata', $spy); }
        $this->assertSame([], $reads);
    }

    public function test_private_and_password_recordings_are_not_previewed_or_moved(): void
    {
        [$set, $word, , $ids] = $this->fixture(3);
        $author = get_current_user_id();
        wp_update_post(['ID' => $ids[0], 'post_status' => 'private', 'post_author' => $author]);
        wp_update_post(['ID' => $ids[1], 'post_password' => 'recording-secret']);
        $this->manager($set);
        $reads = [];
        $spy = static function ($value, $id, $key) use (&$reads) { if ($key === 'audio_file_path') { $reads[] = (int) $id; } return $value; };
        add_filter('get_post_metadata', $spy, 10, 3);
        try { $preview = ll_tools_word_copy_preview($set, $word); }
        finally { remove_filter('get_post_metadata', $spy); }
        $this->assertSame([$ids[2]], wp_list_pluck($preview['recordings'], 'id'));
        $this->assertSame([$ids[2]], $reads);
        $this->assertWPError($this->apply($set, $word, [$ids[0]]));
        $this->assertWPError($this->apply($set, $word, [$ids[1]]));
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
        $this->assertSame('completed', $this->apply($set, $word)['state']);
    }

    public function test_private_category_requires_explicit_access_and_is_never_copied_as_hidden_metadata(): void
    {
        [$set, $word, $category] = $this->fixture();
        update_term_meta($category, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        $this->manager($set);
        $this->assertWPError(ll_tools_word_copy_preview($set, $word));
        $this->assertWPError($this->apply($set, $word));
        $visible = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($visible, $set);
        wp_set_object_terms($word, [$category, $visible], 'word-category');
        $result = $this->apply($set, $word);
        $this->assertSame('completed', $result['state']);
        $this->assertSame([$visible], array_map('intval', wp_get_object_terms($result['new_word_id'], 'word-category', ['fields' => 'ids', 'suppress_filter' => true])));
    }

    public function test_private_image_is_rejected_before_attachment_url_resolution_or_copy(): void
    {
        [$set, $word, $category] = $this->fixture();
        $image = self::factory()->post->create(['post_type' => 'word_images', 'post_status' => 'private', 'post_author' => get_current_user_id()]);
        wp_set_object_terms($image, [$set], 'wordset');
        wp_set_object_terms($image, [$category], 'word-category');
        update_post_meta($word, '_ll_autopicked_image_id', $image);
        $this->manager($set);
        $resolved = [];
        $spy = static function ($url, $id) use (&$resolved) { $resolved[] = (int) $id; return $url; };
        add_filter('wp_get_attachment_url', $spy, 10, 2);
        try {
            $this->assertWPError(ll_tools_word_copy_preview($set, $word));
            $this->assertWPError($this->apply($set, $word));
        } finally { remove_filter('wp_get_attachment_url', $spy); }
        $this->assertSame([], $resolved);
    }

    public function test_password_source_is_denied_until_unlocked_and_copy_preserves_its_password(): void
    {
        [$set, $word] = $this->fixture();
        wp_update_post(['ID' => $word, 'post_password' => 'keep-this-password']);
        $this->manager($set);
        $this->assertWPError(ll_tools_word_copy_preview($set, $word));
        $this->assertWPError($this->apply($set, $word));
        $unlocked = static fn() => false;
        add_filter('post_password_required', $unlocked);
        try { $result = $this->apply($set, $word); }
        finally { remove_filter('post_password_required', $unlocked); }
        $this->assertSame('completed', $result['state']);
        $this->assertSame('keep-this-password', get_post($result['new_word_id'])->post_password);
    }

    public function test_private_attachment_and_hidden_image_category_are_checked_before_media_resolution(): void
    {
        [$set, $word, $category] = $this->fixture();
        $author = get_current_user_id();
        $attachment = self::factory()->post->create(['post_type' => 'attachment', 'post_status' => 'private', 'post_author' => $author, 'post_mime_type' => 'image/png']);
        update_post_meta($word, '_thumbnail_id', $attachment);
        $manager = $this->manager($set);
        $this->assertWPError(ll_tools_word_copy_preview($set, $word));
        $this->assertWPError($this->apply($set, $word));
        wp_set_current_user($author);
        delete_post_meta($word, '_thumbnail_id');
        $image = self::factory()->post->create(['post_type' => 'word_images', 'post_status' => 'publish', 'post_author' => $author]);
        $private_category = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($private_category, $set);
        update_term_meta($private_category, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        wp_set_object_terms($image, [$set], 'wordset');
        wp_set_object_terms($image, [$private_category], 'word-category');
        update_post_meta($word, '_ll_autopicked_image_id', $image);
        wp_set_current_user($manager);
        $this->assertWPError(ll_tools_word_copy_preview($set, $word));
        $this->assertWPError($this->apply($set, $word));
    }

    public function test_receipt_readback_rechecks_the_copied_words_current_privacy_and_scope(): void
    {
        [$set, $word] = $this->fixture();
        $admin = get_current_user_id();
        $manager = $this->manager($set);
        $request = wp_generate_uuid4();
        $result = $this->apply($set, $word, [], '', $request);
        $this->assertSame('completed', $result['state']);
        wp_set_current_user($admin);
        wp_update_post(['ID' => $result['new_word_id'], 'post_status' => 'private', 'post_title' => 'Private later title']);
        wp_set_current_user($manager);
        $this->assertWPError($this->apply($set, $word, [], '', $request));
        wp_set_current_user($admin);
        $other_set = self::factory()->term->create(['taxonomy' => 'wordset']);
        wp_update_post(['ID' => $result['new_word_id'], 'post_status' => 'draft']);
        wp_set_object_terms($result['new_word_id'], [$other_set], 'wordset');
        wp_set_current_user($manager);
        $this->assertWPError($this->apply($set, $word, [], '', $request));
    }

    public function test_recording_becoming_private_after_preflight_is_not_moved(): void
    {
        [$set, $word, , $ids] = $this->fixture(1);
        $author = get_current_user_id();
        wp_update_post(['ID' => $ids[0], 'post_author' => $author]);
        $this->manager($set);
        $hook = static function ($id, $post) use ($word, $ids): void {
            if ($post->post_type === 'words' && (int) $id !== $word) { wp_update_post(['ID' => $ids[0], 'post_status' => 'private']); }
        };
        add_action('wp_insert_post', $hook, 10, 2);
        try { $result = $this->apply($set, $word, $ids); }
        finally { remove_action('wp_insert_post', $hook); }
        $this->assertSame('pending', $result['state']);
        $this->assertSame([], $result['moved_ids']);
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
    }

    public function test_first_creation_acknowledgement_failure_retains_only_an_owned_draft_diagnostic(): void
    {
        [$set, $word] = $this->fixture();
        $request = wp_generate_uuid4();
        $key = ll_tools_word_copy_receipt_key($set, $word, $request);
        $filter = static fn($value, $old) => !empty($value['new_word_id']) && empty($old['new_word_id']) ? $old : $value;
        add_filter('pre_update_option_' . $key, $filter, 10, 2);
        try { $result = $this->apply($set, $word, [], '', $request); }
        finally { remove_filter('pre_update_option_' . $key, $filter); }
        $this->assertSame('pending', $result['state']);
        $this->assertGreaterThan(0, $result['retained_word_id']);
        $this->assertSame(0, $result['new_word_id']);
        $this->assertSame('', $result['title']);
        $this->assertSame('', $result['url']);
        $this->assertSame([], $result['image']);
        $this->assertStringContainsString('#' . $result['retained_word_id'], $result['message']);
        $retry = $this->apply($set, $word, [], '', $request);
        $this->assertSame($result['retained_word_id'], $retry['retained_word_id']);
        $other_set = self::factory()->term->create(['taxonomy' => 'wordset']);
        wp_set_object_terms($result['retained_word_id'], [$other_set], 'wordset');
        $this->assertWPError($this->apply($set, $word, [], '', $request));
    }

    public function test_first_wordset_assignment_failure_retains_draft_and_blocks_duplicate_creation(): void
    {
        global $wpdb;
        [$set, $word] = $this->fixture();
        $request = wp_generate_uuid4();
        $new_id = 0;
        $capture = static function ($id, $post) use ($word, &$new_id): void { if ($post->post_type === 'words' && (int) $id !== $word) { $new_id = (int) $id; } };
        $failure = static function (string $query) use (&$new_id, $wpdb): string {
            return $new_id && strpos($query, "INSERT INTO `{$wpdb->term_relationships}`") !== false
                && strpos($query, '(' . $new_id . ',') !== false ? 'SELECT ID FROM ll_tools_missing_copy_scope_table' : $query;
        };
        add_action('wp_insert_post', $capture, 10, 2); add_filter('query', $failure);
        $suppress = $wpdb->suppress_errors(true);
        try { $result = $this->apply($set, $word, [], '', $request); }
        finally { remove_action('wp_insert_post', $capture); remove_filter('query', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertSame('pending', $result['state']);
        $this->assertSame($new_id, $result['retained_word_id']);
        $this->assertSame([], wp_get_object_terms($new_id, 'wordset', ['fields' => 'ids']));
        $this->assertSame('', $result['url']);
        $retry = $this->apply($set, $word, [], '', $request);
        $this->assertSame($new_id, $retry['retained_word_id']);
    }

    public function test_failed_postmeta_creation_marker_retains_committed_draft_via_option_receipt(): void
    {
        global $wpdb;
        [$set, $word, , $ids] = $this->fixture(1);
        $request = wp_generate_uuid4();
        $failed = false;
        $failure = static function (string $query) use ($wpdb, &$failed): string {
            if (strpos($query, "INSERT INTO `{$wpdb->postmeta}`") !== false && strpos($query, "'_ll_word_copy_receipt'") !== false) {
                $failed = true;
                return 'SELECT ID FROM ll_tools_missing_copy_marker_table';
            }
            return $query;
        };
        $suppress = $wpdb->suppress_errors(true); add_filter('query', $failure);
        try { $result = $this->apply($set, $word, $ids, '', $request); }
        finally { remove_filter('query', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertTrue($failed);
        $this->assertSame('pending', $result['state']);
        $this->assertGreaterThan(0, $result['retained_word_id']);
        $this->assertSame('draft', get_post_status($result['retained_word_id']));
        $this->assertSame('', get_post_meta($result['retained_word_id'], '_ll_word_copy_receipt', true));
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
        $retry = $this->apply($set, $word, $ids, '', $request);
        $this->assertSame($result['retained_word_id'], $retry['retained_word_id']);
    }

    public function test_failed_source_taxonomy_read_stops_before_copying_incomplete_content(): void
    {
        global $wpdb;
        [$set, $word] = $this->fixture();
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='words'");
        $failure = static function ($terms, $object_ids, $taxonomies) use ($word, $wpdb) {
            if (in_array($word, array_map('intval', (array) $object_ids), true) && in_array('part_of_speech', (array) $taxonomies, true)) {
                $wpdb->query('SELECT ID FROM ll_tools_missing_copy_taxonomy_table');
                return [];
            }
            return $terms;
        };
        $suppress = $wpdb->suppress_errors(true); add_filter('get_object_terms', $failure, 10, 3);
        try { $result = $this->apply($set, $word); }
        finally { remove_filter('get_object_terms', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertWPError($result);
        $this->assertSame($before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='words'"));
    }

    public function test_failed_receipt_result_count_is_an_error_not_a_false_zero_success(): void
    {
        global $wpdb;
        [$set, $word] = $this->fixture();
        $request = wp_generate_uuid4();
        $created = $this->apply($set, $word, [], '', $request);
        $failure = static fn(string $query): string => strpos($query, "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='word_audio'") !== false
            ? 'SELECT ID FROM ll_tools_missing_copy_count_table' : $query;
        $suppress = $wpdb->suppress_errors(true); add_filter('query', $failure);
        try { $result = $this->apply($set, $word, [], '', $request); }
        finally { remove_filter('query', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertWPError($result);
        $retry = $this->apply($set, $word, [], '', $request);
        $this->assertSame('completed', $retry['state']);
        $this->assertSame($created['new_word_id'], $retry['new_word_id']);
    }

    public function test_failed_creation_discovery_read_cannot_hide_a_retained_draft_or_repeat_creation(): void
    {
        global $wpdb;
        [$set, $word] = $this->fixture();
        $request = wp_generate_uuid4();
        $key = ll_tools_word_copy_receipt_key($set, $word, $request);
        $checkpoint = static fn($value, $old) => !empty($value['new_word_id']) ? $old : $value;
        add_filter('pre_update_option_' . $key, $checkpoint, 10, 2);
        try { $created = $this->apply($set, $word, [], '', $request); }
        finally { remove_filter('pre_update_option_' . $key, $checkpoint); }
        $failure = static fn(string $query): string => strpos($query, "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ll_word_copy_receipt'") !== false
            ? 'SELECT ID FROM ll_tools_missing_copy_discovery_table' : $query;
        $suppress = $wpdb->suppress_errors(true); add_filter('query', $failure);
        try { $result = $this->apply($set, $word, [], '', $request); }
        finally { remove_filter('query', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertWPError($result);
        $retry = $this->apply($set, $word, [], '', $request);
        $this->assertSame($created['retained_word_id'], $retry['retained_word_id']);
    }

    public function test_failed_audio_parent_write_retains_the_original_recording_and_pending_copy(): void
    {
        global $wpdb;
        [$set, $word, , $ids] = $this->fixture(1);
        $request = wp_generate_uuid4();
        $failure = static fn(string $query): string => strpos($query, "UPDATE {$wpdb->posts} SET post_parent =") !== false
            ? 'SELECT ID FROM ll_tools_missing_copy_move_table' : $query;
        $suppress = $wpdb->suppress_errors(true); add_filter('query', $failure);
        try { $result = $this->apply($set, $word, $ids, '', $request); }
        finally { remove_filter('query', $failure); $wpdb->suppress_errors($suppress); }
        $this->assertSame('pending', $result['state']);
        $this->assertSame([], $result['moved_ids']);
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
        $retry = $this->apply($set, $word, $ids, '', $request);
        $this->assertSame($result['new_word_id'], $retry['new_word_id']);
        $this->assertSame($word, (int) wp_get_post_parent_id($ids[0]));
    }
}
