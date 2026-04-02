<?php
declare(strict_types=1);

final class IpaKeyboardAdminAjaxTest extends LL_Tools_TestCase
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

    public function test_update_recording_ipa_returns_symbol_diff_and_updated_recording_payload(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('IPA Admin Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ship',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Gem');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Ship Recording',
        ]);
        wp_set_object_terms($recording_id, ['Isolation'], 'recording_type', false);
        update_post_meta($recording_id, 'audio_file_path', 'wp-content/uploads/test-audio/ship.mp3');
        update_post_meta($recording_id, 'recording_text', 'ship');
        update_post_meta($recording_id, 'recording_translation', 'gem');
        update_post_meta($recording_id, 'recording_ipa', 'ʃʃ');

        wp_set_current_user($user_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $recording_id,
            'recording_ipa' => 'ʒ',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_update_recording_ipa_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('ʒ', (string) get_post_meta($recording_id, 'recording_ipa', true));

        $data = (array) ($response['data'] ?? []);
        $this->assertSame($recording_id, (int) ($data['recording_id'] ?? 0));
        $this->assertSame('ʒ', (string) ($data['recording_ipa'] ?? ''));
        $this->assertSame(['ʃ'], array_values((array) ($data['previous_symbols'] ?? [])));
        $this->assertSame(['ʒ'], array_values((array) ($data['symbols'] ?? [])));
        $this->assertSame(2, (int) (($data['previous_symbol_counts']['ʃ'] ?? 0)));
        $this->assertSame(1, (int) (($data['symbol_counts']['ʒ'] ?? 0)));
        $this->assertIsArray($data['letter_map'] ?? null);

        $recording = (array) ($data['recording'] ?? []);
        $this->assertSame($recording_id, (int) ($recording['recording_id'] ?? 0));
        $this->assertSame($word_id, (int) ($recording['word_id'] ?? 0));
        $this->assertSame('Ship', (string) ($recording['word_text'] ?? ''));
        $this->assertSame('Gem', (string) ($recording['word_translation'] ?? ''));
        $this->assertSame('Isolation', (string) ($recording['recording_type'] ?? ''));
        $this->assertSame('isolation', (string) ($recording['recording_type_slug'] ?? ''));
        $this->assertSame('isolation', (string) ($recording['recording_icon_type'] ?? ''));
        $this->assertSame('ship', (string) ($recording['recording_text'] ?? ''));
        $this->assertSame('gem', (string) ($recording['recording_translation'] ?? ''));
        $this->assertSame('ʒ', (string) ($recording['recording_ipa'] ?? ''));
        $this->assertSame(site_url('wp-content/uploads/test-audio/ship.mp3'), (string) ($recording['audio_url'] ?? ''));
        $this->assertSame('Play Isolation recording', (string) ($recording['audio_label'] ?? ''));
        $this->assertNotSame('', (string) ($recording['word_edit_link'] ?? ''));
    }

    private function create_viewer_user(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function create_wordset(string $name): int
    {
        $wordset = wp_insert_term($name, 'wordset');
        $this->assertIsArray($wordset);

        return (int) ($wordset['term_id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
