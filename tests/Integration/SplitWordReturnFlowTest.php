<?php
declare(strict_types=1);

final class SplitWordReturnFlowTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function test_split_word_save_redirects_back_to_audio_processor_when_return_url_is_valid(): void
    {
        $editor_id = $this->createSplitWordEditor();
        [$source_word_id, $audio_to_move] = $this->createSplitWordFixture($editor_id);

        wp_set_current_user($editor_id);

        $return_to = admin_url(
            'tools.php?page=ll-audio-processor&ll_ap_tab=duplicates&ll_ap_focus_recording=' . $audio_to_move
        );

        $redirect_url = $this->runSplitSaveRequest([
            'll_source_word_id' => $source_word_id,
            'll_tools_split_word_nonce' => wp_create_nonce('ll_tools_split_word_save_' . $source_word_id),
            'll_move_audio_ids' => [(string) $audio_to_move],
            'll_return_to' => $return_to,
        ]);

        $query = $this->parseRedirectQuery($redirect_url);

        $this->assertSame('ll-audio-processor', (string) ($query['page'] ?? ''));
        $this->assertSame('duplicates', (string) ($query['ll_ap_tab'] ?? ''));
        $this->assertSame((string) $audio_to_move, (string) ($query['ll_ap_focus_recording'] ?? ''));
        $this->assertSame('1', (string) ($query['ll_split_word'] ?? ''));
        $this->assertSame((string) $source_word_id, (string) ($query['ll_split_source'] ?? ''));
        $this->assertSame('1', (string) ($query['ll_split_moved'] ?? ''));

        $new_word_id = (int) ($query['ll_split_new'] ?? 0);
        $this->assertGreaterThan(0, $new_word_id);
        $this->assertSame($new_word_id, (int) wp_get_post_parent_id($audio_to_move));
    }

    public function test_split_word_save_rejects_external_return_url_and_falls_back_to_words_list(): void
    {
        $editor_id = $this->createSplitWordEditor();
        [$source_word_id, $audio_to_move] = $this->createSplitWordFixture($editor_id);

        wp_set_current_user($editor_id);

        $redirect_url = $this->runSplitSaveRequest([
            'll_source_word_id' => $source_word_id,
            'll_tools_split_word_nonce' => wp_create_nonce('ll_tools_split_word_save_' . $source_word_id),
            'll_move_audio_ids' => [(string) $audio_to_move],
            'll_return_to' => 'https://example.com/wp-admin/tools.php?page=ll-audio-processor',
        ]);

        $query = $this->parseRedirectQuery($redirect_url);

        $this->assertSame('words', (string) ($query['post_type'] ?? ''));
        $this->assertArrayNotHasKey('page', $query);
        $this->assertSame('1', (string) ($query['ll_split_word'] ?? ''));
    }

    private function createSplitWordEditor(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function createSplitWordFixture(int $author_id): array
    {
        $source_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Source Word',
            'post_author' => $author_id,
        ]);

        $audio_to_move = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $source_word_id,
            'post_title' => 'Recording One',
            'post_author' => $author_id,
        ]);

        self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'draft',
            'post_parent' => $source_word_id,
            'post_title' => 'Recording Two',
            'post_author' => $author_id,
        ]);

        return [$source_word_id, $audio_to_move];
    }

    private function runSplitSaveRequest(array $post): string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post;
        $_REQUEST = $post;

        $redirect_url = '';
        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            ll_tools_handle_split_word_save();
            $this->fail('Expected split word save handler to redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
        }

        $this->assertNotSame('', $redirect_url);
        return $redirect_url;
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $decoded = [];
        parse_str($query, $decoded);

        return array_map('strval', $decoded);
    }
}
