<?php
declare(strict_types=1);

final class WordGridCategoryEditTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption;

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);

        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_lesson_edit_popup_lists_only_current_wordset_categories_including_empty_owned_categories(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        $lesson_category = get_term((int) $fixture['category_a_id'], 'word-category');
        $wordset = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $lesson_category);
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;

        try {
            $output = do_shortcode(sprintf(
                '[word_grid category="%s" wordset="%s"]',
                esc_attr((string) $lesson_category->slug),
                esc_attr((string) $wordset->slug)
            ));
        } finally {
            remove_filter('wp_doing_ajax', $ajax_filter);
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        }

        $this->assertStringContainsString('data-ll-word-categories-field', $output);
        $this->assertMatchesRegularExpression(
            '/data-ll-word-category-input[^>]+value="' . preg_quote((string) $fixture['category_a_id'], '/') . '"[^>]+checked=/',
            $output
        );
        $this->assertMatchesRegularExpression(
            '/data-ll-word-category-input[^>]+value="' . preg_quote((string) $fixture['category_b_id'], '/') . '"/',
            $output,
            'Expected the empty wordset-owned category to be available.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-ll-word-category-input[^>]+value="' . preg_quote((string) $fixture['foreign_category_id'], '/') . '"/',
            $output,
            'Foreign wordset categories must not be offered in this lesson editor.'
        );
    }

    public function test_ajax_word_update_replaces_only_current_wordset_category_assignments(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        wp_set_post_terms(
            (int) $fixture['word_id'],
            [(int) $fixture['wordset_id'], (int) $fixture['foreign_wordset_id']],
            'wordset',
            false
        );
        wp_set_post_terms(
            (int) $fixture['word_id'],
            [(int) $fixture['category_a_id'], (int) $fixture['foreign_category_id']],
            'word-category',
            false
        );

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'word_id' => (string) $fixture['word_id'],
            'word_text' => 'Category Edit Word',
            'word_translation' => 'Category edit translation',
            'wordset_id' => (string) $fixture['wordset_id'],
            'lesson_category_id' => (string) $fixture['category_a_id'],
            'category_ids_submitted' => '1',
            'category_ids' => [(string) $fixture['category_b_id']],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_update_word_handler();
            });
        } finally {
            $_POST = $this->postBackup;
            $_REQUEST = $this->requestBackup;
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame([(int) $fixture['category_b_id']], array_map('intval', (array) ($response['data']['categories']['ids'] ?? [])));
        $this->assertFalse((bool) ($response['data']['lesson_visible'] ?? true));

        $assigned_ids = wp_get_post_terms((int) $fixture['word_id'], 'word-category', ['fields' => 'ids']);
        $assigned_ids = array_values(array_map('intval', is_wp_error($assigned_ids) ? [] : (array) $assigned_ids));

        $this->assertContains((int) $fixture['category_b_id'], $assigned_ids);
        $this->assertContains((int) $fixture['foreign_category_id'], $assigned_ids, 'Out-of-scope categories from another wordset should be preserved.');
        $this->assertNotContains((int) $fixture['category_a_id'], $assigned_ids);
    }

    public function test_ajax_word_update_rejects_category_outside_current_wordset_scope(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'word_id' => (string) $fixture['word_id'],
            'word_text' => 'Category Edit Word',
            'word_translation' => 'Category edit translation',
            'wordset_id' => (string) $fixture['wordset_id'],
            'category_ids_submitted' => '1',
            'category_ids' => [(string) $fixture['foreign_category_id']],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_update_word_handler();
            });
        } finally {
            $_POST = $this->postBackup;
            $_REQUEST = $this->requestBackup;
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $assigned_ids = wp_get_post_terms((int) $fixture['word_id'], 'word-category', ['fields' => 'ids']);
        $assigned_ids = array_values(array_map('intval', is_wp_error($assigned_ids) ? [] : (array) $assigned_ids));

        $this->assertContains((int) $fixture['category_a_id'], $assigned_ids);
        $this->assertNotContains((int) $fixture['foreign_category_id'], $assigned_ids);
    }

    /**
     * @return array<string,int>
     */
    private function createCategoryEditFixture(): array
    {
        $wordset_id = $this->ensureTerm('wordset', 'Category Edit Wordset', 'category-edit-wordset');
        $foreign_wordset_id = $this->ensureTerm('wordset', 'Category Edit Foreign Wordset', 'category-edit-foreign-wordset');
        $category_a_id = $this->createWordsetCategory('Category Edit A', 'category-edit-a', $wordset_id);
        $category_b_id = $this->createWordsetCategory('Category Edit Empty B', 'category-edit-empty-b', $wordset_id);
        $foreign_category_id = $this->createWordsetCategory('Category Edit Foreign', 'category-edit-foreign', $foreign_wordset_id);

        foreach ([$category_a_id, $category_b_id, $foreign_category_id] as $category_id) {
            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        }

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Category Edit Word',
        ]);
        wp_set_post_terms($word_id, [$category_a_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Category edit translation');

        return [
            'wordset_id' => $wordset_id,
            'foreign_wordset_id' => $foreign_wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'foreign_category_id' => $foreign_category_id,
            'word_id' => $word_id,
        ];
    }

    private function createWordsetCategory(string $name, string $slug, int $wordset_id): int
    {
        $created = function_exists('ll_tools_create_or_get_wordset_category')
            ? ll_tools_create_or_get_wordset_category($name, $wordset_id, ['slug' => $slug])
            : wp_insert_term($name, 'word-category', ['slug' => $slug]);
        if (is_array($created)) {
            $created = (int) ($created['term_id'] ?? 0);
        }
        $this->assertFalse(is_wp_error($created));
        $this->assertGreaterThan(0, (int) $created);

        return (int) $created;
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }

    private function loginEditor(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'administrator']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);
        wp_set_current_user($editor_id);
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
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
