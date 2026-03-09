<?php
declare(strict_types=1);

final class VocabLessonTitleEditTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        delete_option('ll_enable_category_translation');
        delete_option('ll_translation_language');

        parent::tearDown();
    }

    public function test_editor_can_update_lesson_category_name_without_changing_slug(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor_id);

        $lesson = $this->createLessonFixture('Original Category', 'original-category');
        $lesson_id = $lesson['lesson_id'];
        $category_id = $lesson['category_id'];

        $this->assertTrue(ll_tools_user_can_edit_vocab_lesson_title($category_id));

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_title_' . $lesson_id),
            'title' => 'Updated Category Title',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_update_vocab_lesson_category_title_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $updated_category = get_term($category_id, 'word-category');

        $this->assertInstanceOf(WP_Term::class, $updated_category);
        $this->assertSame('Updated Category Title', (string) $updated_category->name);
        $this->assertSame('original-category', (string) $updated_category->slug);
        $this->assertSame('name', (string) ($data['field'] ?? ''));
        $this->assertSame('Updated Category Title', (string) ($data['display_name'] ?? ''));
        $this->assertSame('Updated Category Title', (string) ($data['category_name'] ?? ''));
    }

    public function test_translation_display_updates_term_translation_instead_of_raw_name(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor_id);

        $target_language = strtolower(substr((string) get_locale(), 0, 2));
        if ($target_language === '') {
            $target_language = 'en';
        }
        update_option('ll_enable_category_translation', 1, false);
        update_option('ll_translation_language', $target_language, false);

        $lesson = $this->createLessonFixture('Raw Category Name', 'raw-category-name');
        $lesson_id = $lesson['lesson_id'];
        $category_id = $lesson['category_id'];
        update_term_meta($category_id, 'term_translation', 'Visible Category Name');

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_title_' . $lesson_id),
            'title' => 'Translated Category Title',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_update_vocab_lesson_category_title_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue($response['success']);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $updated_category = get_term($category_id, 'word-category');

        $this->assertInstanceOf(WP_Term::class, $updated_category);
        $this->assertSame('Raw Category Name', (string) $updated_category->name);
        $this->assertSame('Translated Category Title', (string) get_term_meta($category_id, 'term_translation', true));
        $this->assertSame('term_translation', (string) ($data['field'] ?? ''));
        $this->assertSame('Translated Category Title', (string) ($data['display_name'] ?? ''));
        $this->assertSame('Raw Category Name', (string) ($data['category_name'] ?? ''));
    }

    public function test_author_cannot_update_lesson_category_title(): void
    {
        $author_id = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author_id);

        $lesson = $this->createLessonFixture('Restricted Category', 'restricted-category');
        $lesson_id = $lesson['lesson_id'];
        $category_id = $lesson['category_id'];

        $this->assertFalse(ll_tools_user_can_edit_vocab_lesson_title($category_id));

        $_POST = [
            'lesson_id' => $lesson_id,
            'nonce' => wp_create_nonce('ll_vocab_lesson_title_' . $lesson_id),
            'title' => 'Should Not Save',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_update_vocab_lesson_category_title_handler();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertFalse($response['success']);
        $this->assertSame('Restricted Category', (string) get_term($category_id, 'word-category')->name);
    }

    /**
     * @return array{wordset_id:int, category_id:int, lesson_id:int}
     */
    private function createLessonFixture(string $category_name, string $category_slug): array
    {
        $wordset = wp_insert_term('Lesson Title Wordset ' . wp_generate_password(4, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term($category_name, 'word-category', ['slug' => $category_slug]);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lesson Title Fixture',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'lesson_id' => $lesson_id,
        ];
    }

    /**
     * @return array<string, mixed>
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
