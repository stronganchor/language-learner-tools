<?php
declare(strict_types=1);

final class AudioProcessorQueuePaginationTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_queue_loader_returns_bounded_pages_without_hydrating_later_rows(): void
    {
        $editor_id = $this->createAudioProcessorEditor();
        wp_set_current_user($editor_id);

        $word_ids = [];
        $audio_ids = [];
        for ($index = 1; $index <= 27; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'draft',
                'post_title' => 'Paged Queue Word ' . $index,
                'post_author' => $editor_id,
            ]);
            $word_ids[] = $word_id;
            $audio_ids[] = $this->createQueuedAudio($word_id, $editor_id, 'Paged Queue Audio ' . $index);
        }
        foreach (array_merge($word_ids, $audio_ids) as $post_id) {
            clean_post_cache($post_id);
        }

        $queries = [];
        $capture_query = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        add_filter('query', $capture_query);
        try {
            $first_page = ll_audio_processor_get_queue_page('queue', 1, 25);
        } finally {
            remove_filter('query', $capture_query);
        }

        $this->assertLessThanOrEqual(
            30,
            count($queries),
            'Current-page cache priming must prevent one post/meta/term query burst per visible queue row.'
        );
        $this->assertNotFalse(wp_cache_get($audio_ids[26], 'posts'));
        $this->assertNotFalse(wp_cache_get($word_ids[26], 'posts'));
        $this->assertFalse(wp_cache_get($audio_ids[0], 'posts'), 'Rows beyond the requested page must remain unhydrated.');
        $this->assertFalse(wp_cache_get($word_ids[0], 'posts'), 'Parent words beyond the requested page must remain unhydrated.');

        $second_page = ll_audio_processor_get_queue_page('queue', 2, 25);

        $this->assertSame(25, (int) ($first_page['perPage'] ?? 0));
        $this->assertCount(25, (array) ($first_page['recordings'] ?? []));
        $this->assertTrue((bool) ($first_page['hasMore'] ?? false));
        $this->assertSame(25, (int) ($first_page['knownCount'] ?? 0));

        $this->assertCount(2, (array) ($second_page['recordings'] ?? []));
        $this->assertFalse((bool) ($second_page['hasMore'] ?? true));
        $this->assertSame(27, (int) ($second_page['knownCount'] ?? 0));

        $first_ids = array_map('intval', array_column((array) $first_page['recordings'], 'id'));
        $second_ids = array_map('intval', array_column((array) $second_page['recordings'], 'id'));
        $this->assertSame([], array_values(array_intersect($first_ids, $second_ids)));
    }

    public function test_queue_and_duplicate_tabs_preserve_legacy_grouping_reason(): void
    {
        $editor_id = $this->createAudioProcessorEditor();
        wp_set_current_user($editor_id);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Duplicate Queue Word',
            'post_author' => $editor_id,
        ]);

        $older_id = $this->createQueuedAudio($word_id, $editor_id, 'Older Queued Audio', '2026-07-16 08:00:00');
        $newer_id = $this->createQueuedAudio($word_id, $editor_id, 'Newer Queued Audio', '2026-07-17 08:00:00');

        $queue_page = ll_audio_processor_get_queue_page('queue', 1, 25);
        $duplicate_page = ll_audio_processor_get_queue_page('duplicates', 1, 25);

        $this->assertSame([$newer_id], array_map('intval', array_column((array) $queue_page['recordings'], 'id')));
        $this->assertSame([$older_id], array_map('intval', array_column((array) $duplicate_page['recordings'], 'id')));
        $this->assertSame('queued', (string) ($duplicate_page['recordings'][0]['duplicateReason'] ?? ''));
    }

    public function test_published_sibling_keeps_pending_recording_on_duplicates_tab(): void
    {
        $editor_id = $this->createAudioProcessorEditor();
        wp_set_current_user($editor_id);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Published Duplicate Word',
            'post_author' => $editor_id,
        ]);
        $queued_id = $this->createQueuedAudio($word_id, $editor_id, 'Pending Published Duplicate');
        $published_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Existing Published Audio',
            'post_author' => $editor_id,
        ]);
        update_post_meta($published_id, 'audio_file_path', '/wp-content/uploads/existing-published-audio.mp3');

        $queue_page = ll_audio_processor_get_queue_page('queue', 1, 25);
        $duplicate_page = ll_audio_processor_get_queue_page('duplicates', 1, 25);
        $queue_ids = array_map('intval', array_column((array) $queue_page['recordings'], 'id'));
        $duplicate_ids = array_map('intval', array_column((array) $duplicate_page['recordings'], 'id'));

        $this->assertNotContains($queued_id, $queue_ids);
        $this->assertContains($queued_id, $duplicate_ids);
        $matched_index = array_search($queued_id, $duplicate_ids, true);
        $this->assertNotFalse($matched_index);
        $this->assertSame(
            'published',
            (string) ($duplicate_page['recordings'][(int) $matched_index]['duplicateReason'] ?? '')
        );
    }

    public function test_admin_page_renders_lazy_queue_shells_without_recording_cards(): void
    {
        $editor_id = $this->createAudioProcessorEditor();
        wp_set_current_user($editor_id);

        ob_start();
        ll_render_audio_processor_page();
        $html = (string) ob_get_clean();

        $this->assertSame(3, substr_count($html, 'class="ll-queue-items"'));
        $this->assertStringContainsString('data-loaded="false"', $html);
        $this->assertStringContainsString('Loading recordings...', $html);
        $this->assertStringNotContainsString('class="ll-recording-item"', $html);
    }

    public function test_admin_page_restores_the_requested_active_queue_page(): void
    {
        $editor_id = $this->createAudioProcessorEditor();
        wp_set_current_user($editor_id);

        $get_backup = $_GET;
        $_GET = [
            'll_ap_tab' => 'duplicates',
            'll_ap_page' => '3',
        ];
        try {
            ob_start();
            ll_render_audio_processor_page();
            $html = (string) ob_get_clean();
        } finally {
            $_GET = $get_backup;
        }

        $this->assertMatchesRegularExpression(
            '/id="ll-recordings-duplicates".*?data-page="3"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="ll-recordings-queue".*?data-page="1"/s',
            $html
        );
    }

    public function test_queue_ajax_requires_plugin_capability_and_valid_nonce(): void
    {
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_audio_processor'),
            'tab' => 'queue',
            'page' => '1',
        ];
        $_REQUEST = $_POST;
        $forbidden = $this->runJsonEndpoint(static function (): void {
            ll_audio_processor_load_queue_page_handler();
        });
        $this->assertFalse((bool) ($forbidden['success'] ?? true));

        $editor_id = $this->createAudioProcessorEditor();
        wp_set_current_user($editor_id);
        $_POST['nonce'] = 'invalid';
        $_REQUEST = $_POST;
        $invalid_nonce = $this->runJsonEndpoint(static function (): void {
            ll_audio_processor_load_queue_page_handler();
        });
        $this->assertFalse((bool) ($invalid_nonce['success'] ?? true));

        $_POST['nonce'] = wp_create_nonce('ll_audio_processor');
        $_REQUEST = $_POST;
        $allowed = $this->runJsonEndpoint(static function (): void {
            ll_audio_processor_load_queue_page_handler();
        });
        $this->assertTrue((bool) ($allowed['success'] ?? false));
        $this->assertSame('queue', (string) ($allowed['data']['tab'] ?? ''));
        $this->assertSame(1, (int) ($allowed['data']['page'] ?? 0));
    }

    private function createAudioProcessorEditor(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function createQueuedAudio(
        int $parent_word_id,
        int $author_id,
        string $title,
        string $post_date = '2026-07-17 09:00:00'
    ): int {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $parent_word_id,
            'post_title' => $title,
            'post_author' => $author_id,
            'post_date' => $post_date,
        ]);
        update_post_meta($audio_id, '_ll_needs_audio_processing', '1');
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '.mp3');
        update_post_meta($audio_id, 'recording_date', $post_date);

        return $audio_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $error) {
            $this->assertSame('wp_die', $error->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
