<?php
declare(strict_types=1);

final class VocabLessonPasswordAccessTest extends LL_Tools_TestCase
{
    private array $savedCookies = [];
    private array $savedGet = [];
    private array $savedPost = [];
    private array $savedRequest = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedCookies = $_COOKIE;
        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
        $this->savedRequest = $_REQUEST;
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->savedCookies;
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
        $_REQUEST = $this->savedRequest;
        parent::tearDown();
    }

    public function test_guest_nonce_and_warm_grid_do_not_replace_current_password_access(): void
    {
        [$lessonId, $wordsetId, $categoryId] = $this->createProtectedLesson();
        $this->completeLlToolsSimulatedRequest();
        $nonce = wp_create_nonce('ll_vocab_lesson_grid_' . $lessonId);
        $this->unlock('lesson-password');
        $this->assertFalse(post_password_required($lessonId));
        $unlocked = $this->requestGrid($lessonId, $nonce);
        $this->assertTrue($unlocked['success']);
        $this->assertStringContainsString('Protected vocabulary marker', $unlocked['data']['html']);
        $this->assertIsString(ll_tools_vocab_lesson_grid_public_cache_get($lessonId, $wordsetId, $categoryId));

        $this->completeLlToolsSimulatedRequest();
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
        $this->assertNotFalse(wp_verify_nonce($nonce, 'll_vocab_lesson_grid_' . $lessonId));
        $this->assertTrue(post_password_required($lessonId));
        $locked = $this->requestGrid($lessonId, $nonce);
        $this->assertFalse($locked['success']);
        $this->assertArrayNotHasKey('html', $locked['data']);

        $this->unlock('lesson-password');
        wp_update_post(['ID' => $lessonId, 'post_password' => 'replacement-password']);
        $this->completeLlToolsSimulatedRequest();
        $this->assertTrue(post_password_required($lessonId));
        $this->assertFalse($this->requestGrid($lessonId, $nonce)['success']);
    }

    public function test_password_refusal_precedes_paged_preparation(): void
    {
        [$lessonId] = $this->createProtectedLesson();
        $this->completeLlToolsSimulatedRequest();
        $called = false;
        $forcePaged = static function () use (&$called): bool {
            $called = true;
            return true;
        };
        add_filter('ll_tools_vocab_lesson_grid_should_use_paged_loading', $forcePaged);
        try {
            $response = $this->requestGrid($lessonId, wp_create_nonce('ll_vocab_lesson_grid_' . $lessonId));
        } finally {
            remove_filter('ll_tools_vocab_lesson_grid_should_use_paged_loading', $forcePaged);
        }
        $this->assertFalse($response['success']);
        $this->assertFalse($called, 'Password denial must happen before count/order/page preparation.');
    }

    public function test_bundled_template_protects_deferred_fallback_warm_and_print_views(): void
    {
        [$lessonId] = $this->createProtectedLesson();
        $this->completeLlToolsSimulatedRequest();
        $nonce = wp_create_nonce('ll_vocab_lesson_grid_' . $lessonId);
        foreach ([true, false] as $deferred) {
            $deferFilter = static fn(): bool => $deferred;
            add_filter('ll_tools_vocab_lesson_defer_grid', $deferFilter);
            try {
                $html = $this->renderLesson($lessonId);
            } finally {
                remove_filter('ll_tools_vocab_lesson_defer_grid', $deferFilter);
            }
            $this->assertLockedTemplate($html);
        }

        $this->unlock('lesson-password');
        $this->assertTrue($this->requestGrid($lessonId, $nonce)['success']);
        $this->completeLlToolsSimulatedRequest();
        unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
        $this->assertLockedTemplate($this->renderLesson($lessonId));
        $this->assertLockedTemplate($this->renderLesson($lessonId, '&ll_print=1&ll_print_text=1'));
    }

    public function test_valid_password_cookie_preserves_bundled_deferred_lesson_access(): void
    {
        [$lessonId] = $this->createProtectedLesson();
        $this->completeLlToolsSimulatedRequest();
        $this->unlock('lesson-password');
        $html = $this->renderLesson($lessonId);
        $this->assertStringNotContainsString('post-password-form', $html);
        $this->assertStringContainsString('data-ll-vocab-lesson-grid-shell', $html);
        $this->assertStringContainsString('data-nonce="' . wp_create_nonce('ll_vocab_lesson_grid_' . $lessonId) . '"', $html);
    }

    private function assertLockedTemplate(string $html): void
    {
        $this->assertStringContainsString('post-password-form', $html);
        $this->assertStringNotContainsString('data-ll-vocab-lesson-grid-shell', $html);
        $this->assertStringNotContainsString('data-ll-word-grid', $html);
        $this->assertStringNotContainsString('Protected vocabulary marker', $html);
    }

    private function renderLesson(int $lessonId, string $query = ''): string
    {
        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $lessonId . $query);
        $this->assertTrue(is_singular('ll_vocab_lesson'));
        ob_start();
        try {
            include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    private function unlock(string $password): void
    {
        require_once ABSPATH . WPINC . '/class-phpass.php';
        $hasher = new PasswordHash(8, true);
        $_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword($password);
    }

    private function requestGrid(int $lessonId, string $nonce): array
    {
        $_POST = ['lesson_id' => $lessonId, 'nonce' => $nonce];
        $_REQUEST = $_POST;
        $dieHandler = static function (): void { throw new RuntimeException('password-test-wp-die'); };
        $dieFilter = static fn() => $dieHandler;
        $ajaxFilter = static fn(): bool => true;
        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $ajaxFilter);
        ob_start();
        try {
            ll_tools_get_vocab_lesson_grid_handler();
        } catch (RuntimeException $error) {
            $this->assertSame('password-test-wp-die', $error->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $ajaxFilter);
        }
        $response = json_decode($output, true);
        $this->assertIsArray($response);
        return $response;
    }

    private function createProtectedLesson(): array
    {
        $suffix = strtolower(wp_generate_password(8, false, false));
        $wordset = wp_insert_term('Password wordset ' . $suffix, 'wordset', ['slug' => 'password-wordset-' . $suffix]);
        $category = wp_insert_term('Password category ' . $suffix, 'word-category', ['slug' => 'password-category-' . $suffix]);
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_translation');
        $wordId = (int) self::factory()->post->create([
            'post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Protected vocabulary marker',
        ]);
        wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
        update_post_meta($wordId, 'word_translation', 'Protected translation marker');
        $audioId = (int) self::factory()->post->create([
            'post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $wordId,
        ]);
        update_post_meta($audioId, 'audio_file_path', '/wp-content/uploads/password-vocabulary-marker.mp3');
        wp_set_post_terms($audioId, ['isolation'], 'recording_type', false);
        $lessonId = (int) self::factory()->post->create([
            'post_type' => 'll_vocab_lesson', 'post_status' => 'publish',
            'post_title' => 'Password lesson ' . $suffix, 'post_password' => 'lesson-password',
            'post_content' => '[word_grid category="' . $category['term_id'] . '" wordset="' . $wordsetId . '"]',
        ]);
        update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordsetId);
        update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $categoryId);
        return [$lessonId, $wordsetId, $categoryId];
    }
}
