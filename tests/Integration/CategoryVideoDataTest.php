<?php
declare(strict_types=1);

final class CategoryVideoDataTest extends LL_Tools_TestCase
{
    private int $wordset;
    private int $category;
    private int $speaker;
    private int $isolation;
    private int $attachment;

    protected function setUp(): void
    {
        parent::setUp();
        require_once LL_TOOLS_BASE_PATH . 'includes/lib/category-video-data.php';
        $admin = self::factory()->user->create(['role' => 'administrator']);
        get_userdata($admin)->add_cap('view_ll_tools');
        wp_set_current_user($admin);
        $this->wordset = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Video language']);
        $this->category = self::factory()->term->create(['taxonomy' => 'word-category', 'name' => 'Video fruit']);
        ll_tools_set_category_wordset_owner($this->category, $this->wordset);
        $this->speaker = self::factory()->user->create(['role' => 'subscriber', 'display_name' => 'Video speaker']);
        $term = get_term_by('slug', 'isolation', 'recording_type');
        $this->isolation = $term instanceof WP_Term ? $term->term_id : self::factory()->term->create(['taxonomy' => 'recording_type', 'slug' => 'isolation', 'name' => 'Isolation']);
        $this->attachment = self::factory()->post->create(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/webp', 'guid' => 'https://example.org/wp-content/uploads/video-fruit.webp']);
        update_post_meta($this->attachment, '_wp_attached_file', 'video-fruit.webp');
        update_post_meta($this->attachment, '_wp_attachment_metadata', ['width' => 640, 'height' => 640, 'file' => 'video-fruit.webp', 'sizes' => []]);
    }

    private function word(int $category = 0, int $wordset = 0, string $status = 'publish', bool $image = true): int
    {
        $id = self::factory()->post->create(['post_type' => 'words', 'post_status' => $status, 'post_title' => 'Translation title']);
        wp_set_object_terms($id, [$wordset ?: $this->wordset], 'wordset');
        wp_set_object_terms($id, [$category ?: $this->category], 'word-category');
        update_post_meta($id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY, 'Canonical twe');
        if ($image) { update_post_meta($id, '_thumbnail_id', $this->attachment); }
        return $id;
    }

    private function audio(int $word, int $speaker = 0, string $text = 'twe', string $status = 'publish'): int
    {
        $id = self::factory()->post->create(['post_type' => 'word_audio', 'post_parent' => $word, 'post_status' => $status, 'post_author' => get_current_user_id()]);
        wp_set_object_terms($id, [$this->isolation], 'recording_type');
        update_post_meta($id, 'speaker_user_id', $speaker ?: $this->speaker);
        update_post_meta($id, 'recording_text', $text);
        update_post_meta($id, 'audio_file_path', 'wp-content/uploads/video-test/' . $id . '.mp3');
        return $id;
    }

    public function test_preserves_exact_recording_spelling_and_selects_matching_speaker(): void
    {
        $word = $this->word();
        $matching = $this->audio($word, 0, 'twe / twı');
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $this->audio($word, $other, 'Different speaker');
        $page = ll_tools_category_video_get_items($this->wordset, $this->category, $this->speaker);
        $this->assertIsArray($page);
        $this->assertCount(1, $page['items']);
        $this->assertSame('twe / twı', $page['items'][0]['text']);
        $this->assertSame($matching, $page['items'][0]['recording_id']);
        $this->assertSame($this->speaker, $page['items'][0]['speaker_id']);
        $this->assertSame('recording', $page['items'][0]['text_source']);
        $this->assertStringContainsString($matching . '.mp3', $page['items'][0]['audio_url']);
        $this->assertArrayNotHasKey('review_note', $page['items'][0]);
    }

    public function test_text_review_is_skipped_but_ipa_review_preserves_usable_audio(): void
    {
        $word = $this->word();
        $reviewed = $this->audio($word, 0, 'Reviewed spelling');
        update_post_meta($reviewed, ll_tools_ipa_keyboard_review_fields_meta_key(), ['recording_ipa' => true]);
        $unreviewed = $this->audio($word, 0, 'Uncertain spelling');
        update_post_meta($unreviewed, ll_tools_ipa_keyboard_review_fields_meta_key(), ['recording_text' => true]);
        $page = ll_tools_category_video_get_items($this->wordset, $this->category);
        $this->assertSame($reviewed, $page['items'][0]['recording_id']);
        $this->assertSame('Reviewed spelling', $page['items'][0]['text']);
        wp_update_post(['ID' => $reviewed, 'post_status' => 'draft']);
        $page = ll_tools_category_video_get_items($this->wordset, $this->category);
        $this->assertSame([], $page['items']);
        $this->assertSame('text_needs_review', $page['skipped'][0]['reason']);
    }

    public function test_empty_recording_text_uses_canonical_target_not_translation_title(): void
    {
        $word = $this->word();
        $this->audio($word, 0, '');
        $page = ll_tools_category_video_get_items($this->wordset, $this->category);
        $this->assertSame('Canonical twe', $page['items'][0]['text']);
        $this->assertSame('word', $page['items'][0]['text_source']);
    }

    public function test_exact_category_and_wordset_exclude_descendants_foreign_and_unpublished_words(): void
    {
        $valid = $this->word();
        $this->audio($valid);
        $child = self::factory()->term->create(['taxonomy' => 'word-category', 'name' => 'Child fruit', 'parent' => $this->category]);
        ll_tools_set_category_wordset_owner($child, $this->wordset);
        $this->audio($this->word($child));
        $this->audio($this->word(0, 0, 'draft'));
        $foreign_set = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Other video language']);
        $this->audio($this->word(0, $foreign_set));
        $page = ll_tools_category_video_get_items($this->wordset, $this->category);
        $this->assertSame([$valid], array_column($page['items'], 'id'));
    }

    public function test_missing_image_audio_and_wrong_type_are_explicit_skips(): void
    {
        $missing_image = $this->word(0, 0, 'publish', false);
        $this->audio($missing_image);
        $missing_audio = $this->word();
        $wrong_type = $this->word();
        $audio = $this->audio($wrong_type);
        wp_set_object_terms($audio, [], 'recording_type');
        $page = ll_tools_category_video_get_items($this->wordset, $this->category);
        $this->assertSame([], $page['items']);
        $this->assertSame(['missing_image', 'missing_audio', 'missing_audio'], array_column($page['skipped'], 'reason'));
        $this->assertSame([$missing_image, $missing_audio, $wrong_type], array_column($page['skipped'], 'id'));
    }

    public function test_paging_caps_candidates_and_advances_over_skips_without_duplicates(): void
    {
        $words = [];
        for ($i = 0; $i < 28; $i++) { $words[] = $this->word(0, 0, 'publish', false); }
        $page = ll_tools_category_video_get_items($this->wordset, $this->category, 0, 0, 1000);
        $this->assertCount(25, $page['skipped']);
        $this->assertTrue($page['has_more']);
        $this->assertSame($words[24], $page['next_cursor']);
        $next = ll_tools_category_video_get_items($this->wordset, $this->category, 0, $page['next_cursor']);
        $this->assertCount(3, $next['skipped']);
        $this->assertFalse($next['has_more']);
        $this->assertSame($words, array_merge(array_column($page['skipped'], 'id'), array_column($next['skipped'], 'id')));
    }

    public function test_sources_use_exact_scope_and_effective_speaker_not_post_author(): void
    {
        $word = $this->word();
        $this->audio($word);
        $this->assertContains($this->wordset, array_column(ll_tools_category_video_get_sources()['wordsets'], 'id'));
        $this->assertSame([$this->category], array_column(ll_tools_category_video_get_sources($this->wordset)['categories'], 'id'));
        $speakers = ll_tools_category_video_get_sources($this->wordset, $this->category);
        $this->assertSame([$this->speaker], array_column($speakers['speakers'], 'id'));
        $this->assertSame('Video speaker', $speakers['speakers'][0]['name']);
        $this->assertFalse($speakers['has_more']);
    }

    public function test_requires_both_administration_and_ll_tools_access(): void
    {
        wp_set_current_user($this->speaker);
        get_userdata($this->speaker)->add_cap('view_ll_tools');
        $this->assertWPError(ll_tools_category_video_get_sources());
        $this->assertWPError(ll_tools_category_video_get_items($this->wordset, $this->category));
        get_userdata($this->speaker)->remove_cap('view_ll_tools');
        get_userdata($this->speaker)->add_cap('manage_options');
        $this->assertFalse(ll_tools_category_video_can_access());
        wp_set_current_user(0);
        $this->assertWPError(ll_tools_category_video_get_sources());
    }

    public function test_source_query_failure_is_retryable_instead_of_empty_success(): void
    {
        global $wpdb;
        $filter = static function (string $sql): string {
            return strpos($sql, "SELECT w.ID FROM") !== false && strpos($sql, "ORDER BY w.ID ASC LIMIT") !== false
                ? 'SELECT ll_video_deliberately_missing_column' : $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try { $page = ll_tools_category_video_get_items($this->wordset, $this->category); }
        finally { remove_filter('query', $filter); $wpdb->suppress_errors($previous); }
        $this->assertWPError($page);
        $this->assertSame(503, $page->get_error_data()['status']);
    }

    public function test_duplicate_and_legacy_speaker_rows_use_the_same_attribution_in_both_reads(): void
    {
        $author = get_current_user_id();
        $word = $this->word();
        $explicit = $this->audio($word);
        delete_post_meta($explicit, 'speaker_user_id');
        foreach (['-1', '0', (string) $this->speaker, (string) $author] as $value) {
            add_post_meta($explicit, 'speaker_user_id', $value);
        }

        $legacy_word = $this->word();
        $legacy = $this->audio($legacy_word);
        update_post_meta($legacy, 'speaker_user_id', 'invalid');

        $sources = ll_tools_category_video_get_sources($this->wordset, $this->category);
        $this->assertSame([$author, $this->speaker], array_column($sources['speakers'], 'id'));
        $selected = ll_tools_category_video_get_items($this->wordset, $this->category, $this->speaker);
        $this->assertSame([$explicit], array_column($selected['items'], 'recording_id'));
        $this->assertSame([$this->speaker], array_column($selected['items'], 'speaker_id'));
        $fallback = ll_tools_category_video_get_items($this->wordset, $this->category, $author);
        $this->assertSame([$legacy], array_column($fallback['items'], 'recording_id'));
        $this->assertSame([$author], array_column($fallback['items'], 'speaker_id'));
    }

    public function test_nested_linked_image_read_failure_clears_partial_metadata_and_allows_same_cursor_retry(): void
    {
        global $wpdb;
        $word = $this->word(0, 0, 'publish', false);
        $this->audio($word);
        $image = self::factory()->post->create(['post_type' => 'word_images', 'post_status' => 'publish']);
        update_post_meta($image, '_thumbnail_id', $this->attachment);
        update_post_meta($word, '_ll_autopicked_image_id', $image);
        clean_post_cache($image);
        $failed = false;
        $filter = static function (string $sql) use ($wpdb, $image, &$failed): string {
            $from = "FROM {$wpdb->postmeta} WHERE post_id IN ({$image})";
            if (!$failed && strpos($sql, $from) !== false) {
                $failed = true;
                return 'SELECT ll_video_deliberately_missing_image_meta';
            }
            return $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $filter);
        try {
            $result = ll_tools_category_video_get_items($this->wordset, $this->category, 0, $word - 1);
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($previous);
        }
        $this->assertTrue($failed);
        $this->assertWPError($result);
        $this->assertSame(503, $result->get_error_data()['status']);
        $this->assertFalse(wp_cache_get($image, 'post_meta'));

        $retry = ll_tools_category_video_get_items($this->wordset, $this->category, 0, $word - 1);
        $this->assertSame([$word], array_column($retry['items'], 'id'));
        $this->assertSame([], $retry['skipped']);
        $this->assertSame($word, $retry['next_cursor']);
    }

    public function test_registered_ajax_requires_capabilities_nonce_and_exact_category_scope(): void
    {
        $word = $this->word();
        $this->audio($word);
        $scope = ['wordset_id' => (string) $this->wordset, 'category_id' => (string) $this->category];
        $valid = $scope + ['nonce' => wp_create_nonce('ll_tools_category_video')];

        $sources = json_decode($this->dispatch_ajax('sources', $valid), true);
        $this->assertTrue($sources['success']);
        $this->assertSame([$this->speaker], array_column($sources['data']['speakers'], 'id'));
        $items = json_decode($this->dispatch_ajax('items', $valid), true);
        $this->assertTrue($items['success']);
        $this->assertSame([$word], array_column($items['data']['items'], 'id'));
        $this->assertSame('-1', $this->dispatch_ajax('items', $scope + ['nonce' => 'invalid']));

        $other = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Other video AJAX scope']);
        $foreign = json_decode($this->dispatch_ajax('items', array_replace($valid, ['wordset_id' => (string) $other])), true);
        $this->assertFalse($foreign['success']);
        $this->assertSame('forbidden', $foreign['data']['code']);

        wp_set_current_user($this->speaker);
        $forbidden = json_decode($this->dispatch_ajax('items', $scope + ['nonce' => wp_create_nonce('ll_tools_category_video')]), true);
        $this->assertFalse($forbidden['success']);
    }

    private function dispatch_ajax(string $action, array $fields): string
    {
        $previous_post = $_POST;
        $previous_request = $_REQUEST;
        $_POST = $_REQUEST = $fields;
        $die = static function () {
            return static function ($message) {
                echo (string) $message;
                throw new RuntimeException('category_video_ajax_exit');
            };
        };
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', $die);
        ob_start();
        try {
            do_action('wp_ajax_ll_tools_category_video_' . $action);
            $this->fail('The registered Category Video AJAX handler did not exit.');
        } catch (RuntimeException $error) {
            $this->assertSame('category_video_ajax_exit', $error->getMessage());
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            remove_filter('wp_die_ajax_handler', $die);
            remove_filter('wp_doing_ajax', '__return_true');
            $_POST = $previous_post;
            $_REQUEST = $previous_request;
        }
    }

    public function test_image_resolution_does_not_create_cross_wordset_copies_or_change_links(): void
    {
        $word = $this->word();
        $other = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Image owner']);
        $image = self::factory()->post->create(['post_type' => 'word_images', 'post_status' => 'publish']);
        update_post_meta($image, '_thumbnail_id', $this->attachment);
        ll_tools_set_word_image_wordset_owner($image, $other);
        update_post_meta($word, '_ll_autopicked_image_id', $image);
        $before = wp_count_posts('word_images')->publish;
        $this->assertSame('', ll_tools_category_video_image_url($word, $this->wordset));
        $this->assertSame($image, (int) get_post_meta($word, '_ll_autopicked_image_id', true));
        $this->assertSame($before, wp_count_posts('word_images')->publish);
    }
}
