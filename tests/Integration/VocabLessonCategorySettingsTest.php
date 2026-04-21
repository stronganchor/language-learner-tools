<?php
declare(strict_types=1);

final class VocabLessonCategorySettingsTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    public function test_managed_lesson_page_renders_category_settings_panel(): void
    {
        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        ob_start();
        include LL_TOOLS_BASE_PATH . '/templates/vocab-lesson-template.php';
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ll-vocab-lesson-category-settings-trigger', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_settings_action" value="save"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_quiz_prompt_type"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_enabled_games[]"', $html);
        $this->assertStringContainsString('name="ll_vocab_lesson_category_lineup_word_ids"', $html);
    }

    public function test_wordset_manager_can_save_category_settings_from_lesson_page(): void
    {
        wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
        wp_insert_term('Question', 'recording_type', ['slug' => 'question']);

        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));
        $this->assertTrue(ll_tools_user_can_manage_vocab_lesson_category_settings((int) $fixture['category_id'], (int) $fixture['wordset_id']));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_quiz_option_type' => 'text_title',
            'll_vocab_lesson_grid_text_visibility' => 'hide',
            'll_vocab_lesson_category_enabled_games' => ['line-up', 'unscramble'],
            'll_vocab_lesson_desired_recording_types' => ['isolation', 'question'],
            'll_vocab_lesson_category_lineup_submitted' => '1',
            'll_vocab_lesson_category_lineup_direction' => 'rtl',
            'll_vocab_lesson_category_lineup_word_ids' => (string) $fixture['word_b_id'] . ',' . (string) $fixture['word_a_id'],
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('text_translation', (string) get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true));
        $this->assertSame('text_title', (string) get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true));
        $this->assertSame('1', (string) get_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', true));
        $this->assertSame('hide', (string) get_term_meta((int) $fixture['category_id'], 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(['line-up', 'unscramble'], ll_tools_get_category_enabled_games((int) $fixture['category_id']));
        $this->assertSame(['isolation', 'question'], ll_tools_get_desired_recording_types_for_category((int) $fixture['category_id']));
        $this->assertSame('rtl', (string) get_term_meta((int) $fixture['category_id'], LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, true));
        $this->assertSame(
            [(int) $fixture['word_b_id'], (int) $fixture['word_a_id']],
            array_map('intval', (array) get_term_meta((int) $fixture['category_id'], LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true))
        );
    }

    public function test_wordset_manager_can_reset_text_visibility_and_disable_recording_types_from_lesson_page(): void
    {
        wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);

        $manager_id = $this->createManagerUser();
        wp_set_current_user($manager_id);

        $fixture = $this->createManagedLessonFixture($manager_id);

        update_term_meta((int) $fixture['category_id'], 'll_lesson_grid_text_visibility_override', 'hide');
        update_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', ['isolation']);
        update_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', '1');

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_quiz_prompt_type' => 'audio',
            'll_vocab_lesson_quiz_option_type' => 'image',
            'll_vocab_lesson_grid_text_visibility' => 'inherit',
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('ok', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('', (string) get_term_meta((int) $fixture['category_id'], 'll_lesson_grid_text_visibility_override', true));
        $this->assertSame(
            [LL_TOOLS_DESIRED_RECORDING_TYPES_DISABLED],
            array_values((array) get_term_meta((int) $fixture['category_id'], 'll_desired_recording_types', true))
        );
        $this->assertSame('', (string) get_term_meta((int) $fixture['category_id'], 'use_word_titles_for_audio', true));
    }

    public function test_unmanaged_user_cannot_save_category_settings_from_lesson_page(): void
    {
        $manager_id = $this->createManagerUser();
        $fixture = $this->createManagedLessonFixture($manager_id);

        $other_user_id = self::factory()->user->create(['role' => 'author']);
        $other_user = get_user_by('id', $other_user_id);
        $this->assertInstanceOf(WP_User::class, $other_user);
        $other_user->add_cap('view_ll_tools');
        clean_user_cache($other_user_id);
        wp_set_current_user($other_user_id);

        $this->go_to('/?post_type=ll_vocab_lesson&p=' . $fixture['lesson_id']);
        $this->assertTrue(is_singular('ll_vocab_lesson'));
        $this->assertFalse(ll_tools_user_can_manage_vocab_lesson_category_settings((int) $fixture['category_id'], (int) $fixture['wordset_id']));

        $original_prompt_type = get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true);
        $original_option_type = get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_vocab_lesson_category_settings_action' => 'save',
            'll_vocab_lesson_category_settings_lesson_id' => (string) $fixture['lesson_id'],
            'll_vocab_lesson_category_settings_wordset_id' => (string) $fixture['wordset_id'],
            'll_vocab_lesson_category_settings_category_id' => (string) $fixture['category_id'],
            'll_vocab_lesson_category_settings_nonce' => wp_create_nonce('ll_vocab_lesson_category_settings_' . $fixture['lesson_id']),
            'll_vocab_lesson_quiz_prompt_type' => 'text_translation',
            'll_vocab_lesson_quiz_option_type' => 'text_title',
        ];

        $redirect_url = $this->captureRedirect(static function (): void {
            ll_tools_handle_vocab_lesson_category_settings_submit();
        });

        $query = [];
        parse_str((string) wp_parse_url($redirect_url, PHP_URL_QUERY), $query);

        $this->assertSame('error', (string) ($query['ll_vocab_lesson_category_settings'] ?? ''));
        $this->assertSame('permission', (string) ($query['ll_vocab_lesson_category_settings_error'] ?? ''));
        $this->assertSame($original_prompt_type, get_term_meta((int) $fixture['category_id'], 'll_quiz_prompt_type', true));
        $this->assertSame($original_option_type, get_term_meta((int) $fixture['category_id'], 'll_quiz_option_type', true));
    }

    private function createManagerUser(): int
    {
        $manager_id = self::factory()->user->create(['role' => 'author']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        clean_user_cache($manager_id);

        return $manager_id;
    }

    /**
     * @return array<string,int>
     */
    private function createManagedLessonFixture(int $manager_id): array
    {
        $wordset = wp_insert_term('Managed Settings Wordset ' . wp_generate_password(4, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);

        $category_slug = 'managed-settings-category-' . strtolower(wp_generate_password(4, false));
        $category = wp_insert_term('Managed Settings Category', 'word-category', ['slug' => $category_slug]);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

        $word_a_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Alpha',
        ]);
        wp_set_post_terms($word_a_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_a_id, [$wordset_id], 'wordset', false);

        $word_b_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Beta',
        ]);
        wp_set_post_terms($word_b_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_b_id, [$wordset_id], 'wordset', false);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Managed Settings Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'lesson_id' => $lesson_id,
            'word_a_id' => $word_a_id,
            'word_b_id' => $word_b_id,
        ];
    }

    private function captureRedirect(callable $callback): string
    {
        $redirect_url = '';
        $redirect_filter = static function ($location) use (&$redirect_url) {
            $redirect_url = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };

        add_filter('wp_redirect', $redirect_filter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect_filter, 10);
        }

        $this->assertNotSame('', $redirect_url);
        return $redirect_url;
    }
}
