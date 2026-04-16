<?php
declare(strict_types=1);

final class WordsetTextPreviewLayoutTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    public function test_text_based_categories_keep_multi_slot_previews_on_the_main_wordset_page(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createTextPreviewFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_id'];
        $wordset_term = get_term($wordset_id, 'wordset');

        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $category = null;
        foreach ($categories as $row) {
            if ((int) ($row['id'] ?? 0) === $category_id) {
                $category = $row;
                break;
            }
        }

        $this->assertIsArray($category);
        $this->assertSame(4, (int) ($category['preview_limit'] ?? 0));
        $this->assertCount(4, (array) ($category['preview'] ?? []));
        $this->assertSame('text', (string) (($category['preview'][0]['type'] ?? '')));

        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id > 0 && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            try {
                $html = ll_tools_render_wordset_page_content($wordset_id, [
                    'show_title' => false,
                    'wrapper_tag' => 'div',
                ]);
            } finally {
                $_GET = $original_get;
                set_query_var('ll_wordset_page', $original_wordset_page);
                set_query_var('ll_wordset_view', $original_wordset_view);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }

        $card_markup = $this->extractCategoryCardMarkup($html, $category_id);
        $this->assertStringContainsString('ll-wordset-card__preview has-text', $card_markup);
        $this->assertSame(4, substr_count($card_markup, 'll-wordset-preview-item--text'));
        $this->assertSame(0, substr_count($card_markup, 'll-wordset-preview-item--empty'));
    }

    public function test_category_preview_cache_respects_requested_slot_size_for_image_categories(): void
    {
        $fixture = $this->createImagePreviewFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $category_id = (int) $fixture['category_id'];

        $first = ll_tools_get_wordset_page_categories($wordset_id, 1);
        $first_category = $this->findCategoryRow($first, $category_id);
        $this->assertIsArray($first_category);
        $this->assertSame(1, (int) ($first_category['preview_limit'] ?? 0));
        $this->assertCount(1, (array) ($first_category['preview'] ?? []));

        $second = ll_tools_get_wordset_page_categories($wordset_id, 3);
        $second_category = $this->findCategoryRow($second, $category_id);
        $this->assertIsArray($second_category);
        $this->assertSame(3, (int) ($second_category['preview_limit'] ?? 0));
        $this->assertCount(3, (array) ($second_category['preview'] ?? []));
    }

    /**
     * @return array{wordset_id:int,category_id:int}
     */
    private function createTextPreviewFixture(): array
    {
        $wordset = wp_insert_term('Wordset Text Preview ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_term = wp_insert_term('Wordset Text Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $lesson_post_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Wordset Text Preview Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_post_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);

        $labels = [
            'Hala oglu',
            'Hala kizi',
            'Daha uzun aile ifadesi',
            'Dayi oglu',
            'Dayi kizi',
            'Aile buyugu',
        ];

        foreach ($labels as $index => $label) {
            $this->createWordWithAudio(
                $label . ' ' . wp_generate_password(4, false),
                'Preview translation ' . ($index + 1),
                $category_id,
                $wordset_id,
                'text-preview-' . ($index + 1) . '.mp3'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $word_id;
    }

    /**
     * @return array{wordset_id:int,category_id:int}
     */
    private function createImagePreviewFixture(): array
    {
        $wordset = wp_insert_term('Wordset Image Preview ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category_term = wp_insert_term('Wordset Image Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'image');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $lesson_post_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Wordset Image Preview Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_post_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_post_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);

        for ($index = 1; $index <= 3; $index++) {
            $this->createWordWithImage(
                'Wordset Image Word ' . $index . ' ' . wp_generate_password(4, false),
                $category_id,
                $wordset_id,
                'wordset-image-preview-' . $index . '.png'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
        ];
    }

    private function createWordWithImage(string $title, int $category_id, int $wordset_id, string $filename): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $attachment_id = $this->createImageAttachment($filename);
        set_post_thumbnail($word_id, $attachment_id);

        return (int) $word_id;
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

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<string,mixed>|null
     */
    private function findCategoryRow(array $categories, int $category_id): ?array
    {
        foreach ($categories as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['id'] ?? 0) === $category_id) {
                return $row;
            }
        }

        return null;
    }

    private function extractCategoryCardMarkup(string $html, int $category_id): string
    {
        $pattern = '/<article\b[^>]*data-cat-id="' . preg_quote((string) $category_id, '/') . '"[^>]*>.*?<\/article>/s';
        $matches = [];
        $found = preg_match($pattern, $html, $matches);
        $this->assertSame(1, $found, 'Expected wordset card markup for category ' . $category_id . '.');
        return isset($matches[0]) ? (string) $matches[0] : '';
    }
}
