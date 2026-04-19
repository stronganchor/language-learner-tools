<?php
declare(strict_types=1);

final class AudioProcessorWordEditTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var mixed */
    private $wordTitleRoleOption = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->wordTitleRoleOption = get_option('ll_word_title_language_role', null);
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;

        if ($this->wordTitleRoleOption === null) {
            delete_option('ll_word_title_language_role');
        } else {
            update_option('ll_word_title_language_role', $this->wordTitleRoleOption);
        }

        parent::tearDown();
    }

    public function test_audio_processor_update_word_text_saves_word_and_translation_in_default_mode(): void
    {
        $editor_id = $this->create_audio_processor_editor();
        $wordset_id = $this->create_wordset_with_title_role('target');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Old Word',
            'post_author' => $editor_id,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Old Translation');
        update_post_meta($word_id, 'word_english_meaning', 'Old Translation');

        wp_set_current_user($editor_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_audio_processor'),
            'word_id' => $word_id,
            'word_text' => 'New Word',
            'translation_text' => 'New Translation',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_audio_processor_update_word_text_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('New Word', (string) get_post_field('post_title', $word_id));
        $this->assertSame('New Translation', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertSame('New Translation', (string) get_post_meta($word_id, 'word_english_meaning', true));

        $data = (array) ($response['data'] ?? []);
        $this->assertSame('New Word', (string) ($data['wordText'] ?? ''));
        $this->assertSame('New Translation', (string) ($data['translationText'] ?? ''));
        $this->assertTrue((bool) ($data['storeInTitle'] ?? false));
    }

    public function test_audio_processor_update_word_text_respects_translation_title_mode(): void
    {
        $editor_id = $this->create_audio_processor_editor();
        $wordset_id = $this->create_wordset_with_title_role('translation');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Old Translation',
            'post_author' => $editor_id,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Old Target');
        update_post_meta($word_id, 'word_english_meaning', 'Old Translation');

        wp_set_current_user($editor_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_audio_processor'),
            'word_id' => $word_id,
            'word_text' => 'Yeni Kelime',
            'translation_text' => 'Updated Translation',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_audio_processor_update_word_text_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('Updated Translation', (string) get_post_field('post_title', $word_id));
        $this->assertSame('Yeni Kelime', (string) get_post_meta($word_id, 'word_translation', true));
        $this->assertSame('Updated Translation', (string) get_post_meta($word_id, 'word_english_meaning', true));

        $data = (array) ($response['data'] ?? []);
        $this->assertSame('Yeni Kelime', (string) ($data['wordText'] ?? ''));
        $this->assertSame('Updated Translation', (string) ($data['translationText'] ?? ''));
        $this->assertFalse((bool) ($data['storeInTitle'] ?? true));
    }

    private function create_audio_processor_editor(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function create_wordset_with_title_role(string $title_role): int
    {
        $wordset = wp_insert_term('Audio Processor Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);

        update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, $title_role);

        return $wordset_id;
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
