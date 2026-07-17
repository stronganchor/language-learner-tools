<?php
declare(strict_types=1);

final class AudioUploadSpeakerAssignmentTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_non_privileged_uploader_cannot_assign_another_user_as_speaker(): void
    {
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $other_recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($recorder_id);

        $result = ll_create_new_word_post(
            'Recorder Ownership Regression Word',
            '/wp-content/uploads/2026/04/recorder-ownership-regression-word.mp3',
            [
                'll_speaker_assignment' => (string) $other_recorder_id,
                'll_recording_type' => 'isolation',
            ],
            [],
            wp_upload_dir()
        );

        $this->assertIsInt($result);
        $word_id = (int) $result;
        $this->assertGreaterThan(0, $word_id);

        $audio_children = get_children([
            'post_type' => 'word_audio',
            'post_parent' => $word_id,
            'post_status' => 'any',
            'numberposts' => 5,
            'fields' => 'ids',
        ]);

        $audio_ids = array_values(array_filter(array_map('intval', (array) $audio_children), static function (int $post_id): bool {
            return $post_id > 0;
        }));
        $this->assertCount(1, $audio_ids);

        $audio_id = (int) $audio_ids[0];
        $this->assertSame($recorder_id, (int) get_post_meta($audio_id, 'speaker_user_id', true));
    }

    public function test_assignable_speaker_predicate_matches_server_capability_policy(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);

        $this->assertTrue(ll_audio_upload_user_is_assignable_speaker(get_userdata($admin_id)));
        $this->assertTrue(ll_audio_upload_user_is_assignable_speaker(get_userdata($recorder_id)));
        $this->assertFalse(ll_audio_upload_user_is_assignable_speaker(get_userdata($subscriber_id)));
        $this->assertFalse(ll_audio_upload_user_is_assignable_speaker(false));
    }

    public function test_numeric_speaker_resolution_fetches_only_the_submitted_user(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($admin_id);

        $user_query_count = 0;
        $query_counter = static function () use (&$user_query_count): void {
            ++$user_query_count;
        };
        add_action('pre_get_users', $query_counter);
        try {
            $this->assertSame($recorder_id, ll_audio_upload_resolve_speaker_user_id((string) $recorder_id));
            $this->assertSame($admin_id, ll_audio_upload_resolve_speaker_user_id((string) $subscriber_id));
            $this->assertSame($admin_id, ll_audio_upload_resolve_speaker_user_id('not-a-user'));
        } finally {
            remove_action('pre_get_users', $query_counter);
        }

        $this->assertSame(0, $user_query_count, 'Numeric validation must not scan the user table.');
    }

    public function test_speaker_search_is_bounded_filtered_and_deterministic(): void
    {
        $admin_id = self::factory()->user->create([
            'role' => 'administrator',
            'display_name' => 'Search Administrator',
        ]);
        wp_set_current_user($admin_id);

        for ($index = 0; $index < 25; ++$index) {
            self::factory()->user->create([
                'role' => 'audio_recorder',
                'user_login' => sprintf('speaker-search-%02d', $index),
                'display_name' => sprintf('Speaker %02d', $index),
            ]);
        }
        $subscriber_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_login' => 'speaker-search-hidden',
            'display_name' => 'Speaker 00 Hidden',
        ]);

        $query_limits = [];
        $query_counter = static function (WP_User_Query $query) use (&$query_limits): void {
            if (str_contains((string) $query->get('search'), 'Speaker')) {
                $query_limits[] = (int) $query->get('number');
            }
        };
        add_action('pre_get_users', $query_counter);
        try {
            $search = ll_audio_upload_search_assignable_speaker_users('Speaker', 20);
        } finally {
            remove_action('pre_get_users', $query_counter);
        }

        $results = array_values((array) ($search['results'] ?? []));
        $this->assertCount(20, $results);
        $this->assertTrue((bool) ($search['has_more'] ?? false));
        $this->assertSame([21], $query_limits, 'Speaker search must inspect at most limit + 1 users.');
        $this->assertNotContains($subscriber_id, array_map('intval', array_column($results, 'id')));

        $labels = array_map('strval', array_column($results, 'label'));
        $sorted_labels = $labels;
        sort($sorted_labels, SORT_STRING);
        $this->assertSame($sorted_labels, $labels);
    }

    public function test_short_speaker_search_does_not_query_users(): void
    {
        $query_count = 0;
        $query_counter = static function () use (&$query_count): void {
            ++$query_count;
        };
        add_action('pre_get_users', $query_counter);
        try {
            $search = ll_audio_upload_search_assignable_speaker_users('a', 20);
        } finally {
            remove_action('pre_get_users', $query_counter);
        }

        $this->assertSame([], $search['results']);
        $this->assertFalse($search['has_more']);
        $this->assertSame(0, $query_count);
    }

    public function test_speaker_search_ajax_requires_capability_and_nonce(): void
    {
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        wp_set_current_user($recorder_id);
        $_GET = [
            'nonce' => wp_create_nonce('ll_audio_upload_search_speakers'),
            'search' => 'Speaker',
        ];
        $_REQUEST = $_GET;

        $forbidden = $this->runJsonEndpoint(static function (): void {
            ll_audio_upload_search_speakers_ajax();
        });
        $this->assertFalse((bool) ($forbidden['success'] ?? true));

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $_GET = [
            'nonce' => 'invalid',
            'search' => 'Speaker',
        ];
        $_REQUEST = $_GET;

        $invalid_nonce = $this->runJsonEndpoint(static function (): void {
            ll_audio_upload_search_speakers_ajax();
        });
        $this->assertFalse((bool) ($invalid_nonce['success'] ?? true));

        $speaker_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Speaker Search Result',
        ]);
        $_GET = [
            'nonce' => wp_create_nonce('ll_audio_upload_search_speakers'),
            'search' => 'Search Result',
        ];
        $_REQUEST = $_GET;

        $allowed = $this->runJsonEndpoint(static function (): void {
            ll_audio_upload_search_speakers_ajax();
        });
        $this->assertTrue((bool) ($allowed['success'] ?? false));
        $this->assertContains($speaker_id, array_map(
            'intval',
            array_column((array) (($allowed['data']['results'] ?? [])), 'id')
        ));
    }

    public function test_audio_upload_form_does_not_preload_other_users(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $speaker_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Never Preloaded Speaker',
        ]);
        wp_set_current_user($admin_id);

        $html = ll_audio_upload_form_shortcode();

        $this->assertStringContainsString('data-ll-speaker-search', $html);
        $this->assertStringContainsString('value="current"', $html);
        $this->assertStringContainsString('value="unassigned"', $html);
        $this->assertStringNotContainsString('Never Preloaded Speaker', $html);
        $this->assertStringNotContainsString('value="' . $speaker_id . '"', $html);
        $this->assertStringNotContainsString('<optgroup', $html);
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
        $ajax_die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $ajax_die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $error) {
            $this->assertSame('wp_die', $error->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $ajax_die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
