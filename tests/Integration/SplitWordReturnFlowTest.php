<?php
declare(strict_types=1);

final class SplitWordReturnFlowTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->getBackup = $_GET;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_GET = $this->getBackup;
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
        $this->assertArrayNotHasKey('post_type', $query);

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

    public function test_split_word_save_can_keep_all_audio_on_original_and_copy_its_image(): void
    {
        $editor_id = $this->createSplitWordEditor();
        [$source_word_id, $first_audio_id] = $this->createSplitWordFixture($editor_id);
        $thumbnail_id = $this->createImageAttachment('split-word-keep-all.png');
        $this->assertNotFalse(set_post_thumbnail($source_word_id, $thumbnail_id));

        wp_set_current_user($editor_id);

        $redirect_url = $this->runSplitSaveRequest([
            'll_source_word_id' => $source_word_id,
            'll_tools_split_word_nonce' => wp_create_nonce('ll_tools_split_word_save_' . $source_word_id),
            'll_new_word_title' => 'Alternate Word',
        ]);

        $query = $this->parseRedirectQuery($redirect_url);
        $new_word_id = (int) ($query['ll_split_new'] ?? 0);

        $this->assertGreaterThan(0, $new_word_id);
        $this->assertSame('1', (string) ($query['ll_split_word'] ?? ''));
        $this->assertSame('0', (string) ($query['ll_split_moved'] ?? ''));
        $this->assertSame('0', (string) ($query['ll_split_failed'] ?? ''));
        $this->assertArrayNotHasKey('ll_split_error', $query);
        $this->assertSame('Alternate Word', get_the_title($new_word_id));
        $this->assertSame($thumbnail_id, (int) get_post_thumbnail_id($new_word_id));
        $this->assertSame($source_word_id, (int) wp_get_post_parent_id($first_audio_id));

        $source_audio_ids = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'any',
            'post_parent' => $source_word_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        $new_audio_ids = get_posts([
            'post_type' => 'word_audio',
            'post_status' => 'any',
            'post_parent' => $new_word_id,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $this->assertCount(2, $source_audio_ids);
        $this->assertSame([], $new_audio_ids);
    }

    public function test_split_word_page_from_audio_processor_renders_submit_and_cancel_return_actions(): void
    {
        $editor_id = $this->createSplitWordEditor();
        [$source_word_id] = $this->createSplitWordFixture($editor_id);

        wp_set_current_user($editor_id);
        $_GET = [
            'page' => 'll-tools-split-word',
            'word_id' => (string) $source_word_id,
            'll_return_to' => admin_url('tools.php?page=ll-audio-processor'),
        ];
        $_REQUEST = $_GET;

        ob_start();
        ll_tools_render_split_word_admin_page();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Split Word &amp; Return to Audio Processor', $output);
        $this->assertStringContainsString('Cancel &amp; Return to Audio Processor', $output);
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

    private function createImageAttachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
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
