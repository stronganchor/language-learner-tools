<?php
declare(strict_types=1);

final class QuizPagesShortcodeTranslationTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
    }

    protected function tearDown(): void
    {
        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_quiz_pages_data_uses_translated_category_display_name_without_warning(): void
    {
        $fixture = $this->createQuizPageFixture();

        $locale_prefix = strtolower(substr((string) get_locale(), 0, 2));
        if ($locale_prefix === '') {
            $locale_prefix = 'en';
        }

        update_term_meta($fixture['wordset_id'], LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, '1');
        update_term_meta($fixture['wordset_id'], LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, $locale_prefix);
        update_term_meta($fixture['category_id'], 'term_translation', 'Translated Quiz Category');

        $wordset = get_term($fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use (&$warnings): bool {
            if (in_array($severity, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE], true)) {
                $warnings[] = $message . ' @ ' . $file . ':' . $line;
                return true;
            }

            return false;
        });

        try {
            $items = ll_get_all_quiz_pages_data([
                'wordset' => $wordset->slug,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $items);
        $item = $items[0];

        $this->assertSame('Translated Quiz Category', (string) ($item['translation'] ?? ''));
        $this->assertSame('Translated Quiz Category', (string) ($item['display_name'] ?? ''));
        $this->assertSame($fixture['page_id'], (int) ($item['post_id'] ?? 0));

        $warning_text = implode("\n", $warnings);
        $this->assertStringNotContainsString('enable_translation', $warning_text);
    }

    /**
     * @return array{wordset_id:int,category_id:int,page_id:int}
     */
    private function createQuizPageFixture(): array
    {
        $wordset = wp_insert_term('Quiz Page Wordset ' . wp_generate_password(6, false), 'wordset');
        $category = wp_insert_term('Raw Quiz Category ' . wp_generate_password(6, false), 'word-category');

        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $this->assertFalse(is_wp_error($wordset));
        $this->assertFalse(is_wp_error($category));

        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Quiz Page Fixture',
        ]);
        update_post_meta($page_id, '_ll_tools_word_category_id', $category_id);

        $recording_type = term_exists('isolation', 'recording_type');
        if (is_array($recording_type) && !empty($recording_type['term_id'])) {
            $recording_type_id = (int) $recording_type['term_id'];
        } elseif (is_int($recording_type) && $recording_type > 0) {
            $recording_type_id = $recording_type;
        } else {
            $created_recording_type = wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
            $this->assertFalse(is_wp_error($created_recording_type));
            $this->assertIsArray($created_recording_type);
            $recording_type_id = (int) ($created_recording_type['term_id'] ?? 0);
        }

        for ($index = 1; $index <= 5; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Quiz Word ' . $index,
            ]);

            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            update_post_meta($word_id, 'word_translation', 'Quiz Translation ' . $index);

            $audio_post_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title' => 'Quiz Audio ' . $index,
            ]);
            update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/quiz-audio-' . $index . '.mp3');
            wp_set_post_terms($audio_post_id, [$recording_type_id], 'recording_type', false);
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'page_id' => (int) $page_id,
        ];
    }
}
