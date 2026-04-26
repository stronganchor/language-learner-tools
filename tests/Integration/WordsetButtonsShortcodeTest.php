<?php
declare(strict_types=1);

final class WordsetButtonsShortcodeTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=';

    protected function tearDown(): void
    {
        if (function_exists('ll_tools_purge_wordset_buttons_shortcode_cache')) {
            ll_tools_purge_wordset_buttons_shortcode_cache();
        }
        parent::tearDown();
    }

    public function test_shortcode_renders_viewable_wordsets_with_published_lesson_counts_only(): void
    {
        $public_term = wp_insert_term('Buttons Public Wordset', 'wordset');
        $private_term = wp_insert_term('Buttons Private Wordset', 'wordset');
        $empty_term = wp_insert_term('Buttons Empty Wordset', 'wordset');

        $this->assertIsArray($public_term);
        $this->assertIsArray($private_term);
        $this->assertIsArray($empty_term);
        $this->assertFalse(is_wp_error($public_term));
        $this->assertFalse(is_wp_error($private_term));
        $this->assertFalse(is_wp_error($empty_term));

        $public_term_id = (int) ($public_term['term_id'] ?? 0);
        $private_term_id = (int) ($private_term['term_id'] ?? 0);
        $empty_term_id = (int) ($empty_term['term_id'] ?? 0);
        update_term_meta($private_term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');

        $this->createPublishedLessonForWordset($public_term_id, 'Public Buttons Lesson A');
        $this->createPublishedLessonForWordset($public_term_id, 'Public Buttons Lesson B');
        $this->createPublishedLessonForWordset($private_term_id, 'Private Buttons Lesson');

        $public_wordset = get_term($public_term_id, 'wordset');
        $private_wordset = get_term($private_term_id, 'wordset');
        $empty_wordset = get_term($empty_term_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $public_wordset);
        $this->assertInstanceOf(WP_Term::class, $private_wordset);
        $this->assertInstanceOf(WP_Term::class, $empty_wordset);

        $html = do_shortcode('[ll_wordset_buttons]');

        $this->assertStringContainsString('ll-wordset-buttons-shortcode', $html);
        $this->assertStringContainsString('ll-study-btn', $html);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode__count', $html);
        $this->assertStringContainsString($public_wordset->name, $html);
        $this->assertStringContainsString('2 lessons', $html);
        $this->assertStringContainsString(
            esc_url(ll_tools_get_wordset_page_view_url($public_wordset)),
            $html
        );
        $this->assertStringNotContainsString($private_wordset->name, $html);
        $this->assertStringNotContainsString($empty_wordset->name, $html);

        $this->assertTrue(wp_style_is('ll-wordset-pages-css', 'enqueued'));
        $this->assertTrue(wp_style_is('ll-tools-style', 'enqueued'));
    }

    public function test_shortcode_orders_wordsets_from_most_lessons_to_fewest(): void
    {
        $small_term = wp_insert_term('Buttons Small Wordset', 'wordset');
        $large_term = wp_insert_term('Buttons Large Wordset', 'wordset');
        $medium_term = wp_insert_term('Buttons Medium Wordset', 'wordset');

        $this->assertIsArray($small_term);
        $this->assertIsArray($large_term);
        $this->assertIsArray($medium_term);
        $this->assertFalse(is_wp_error($small_term));
        $this->assertFalse(is_wp_error($large_term));
        $this->assertFalse(is_wp_error($medium_term));

        $small_term_id = (int) ($small_term['term_id'] ?? 0);
        $large_term_id = (int) ($large_term['term_id'] ?? 0);
        $medium_term_id = (int) ($medium_term['term_id'] ?? 0);

        for ($index = 1; $index <= 4; $index++) {
            $this->createPublishedLessonForWordset($large_term_id, 'Buttons Large Lesson ' . $index);
        }
        for ($index = 1; $index <= 2; $index++) {
            $this->createPublishedLessonForWordset($medium_term_id, 'Buttons Medium Lesson ' . $index);
        }
        $this->createPublishedLessonForWordset($small_term_id, 'Buttons Small Lesson 1');

        $small_wordset = get_term($small_term_id, 'wordset');
        $large_wordset = get_term($large_term_id, 'wordset');
        $medium_wordset = get_term($medium_term_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $small_wordset);
        $this->assertInstanceOf(WP_Term::class, $large_wordset);
        $this->assertInstanceOf(WP_Term::class, $medium_wordset);

        $html = do_shortcode('[ll_wordset_buttons]');

        $large_pos = strpos($html, $large_wordset->name);
        $medium_pos = strpos($html, $medium_wordset->name);
        $small_pos = strpos($html, $small_wordset->name);

        $this->assertIsInt($large_pos);
        $this->assertIsInt($medium_pos);
        $this->assertIsInt($small_pos);
        $this->assertTrue($large_pos < $medium_pos);
        $this->assertTrue($medium_pos < $small_pos);
        $this->assertStringContainsString('4 lessons', $html);
        $this->assertStringContainsString('2 lessons', $html);
        $this->assertStringContainsString('1 lesson', $html);
    }

    public function test_shortcode_renders_configured_wordset_images(): void
    {
        $image_term = wp_insert_term('Buttons Image Wordset', 'wordset');
        $plain_term = wp_insert_term('Buttons Plain Wordset', 'wordset');

        $this->assertIsArray($image_term);
        $this->assertIsArray($plain_term);
        $this->assertFalse(is_wp_error($image_term));
        $this->assertFalse(is_wp_error($plain_term));

        $image_term_id = (int) ($image_term['term_id'] ?? 0);
        $plain_term_id = (int) ($plain_term['term_id'] ?? 0);
        $this->createPublishedLessonForWordset($image_term_id, 'Buttons Image Lesson');
        $this->createPublishedLessonForWordset($plain_term_id, 'Buttons Plain Lesson');

        $attachment_id = $this->createImageAttachment('wordset-button-image.png');
        update_term_meta($image_term_id, LL_TOOLS_WORDSET_BUTTON_IMAGE_ATTACHMENT_ID_META_KEY, $attachment_id);

        $html = do_shortcode('[ll_wordset_buttons]');

        $this->assertStringContainsString('Buttons Image Wordset', $html);
        $this->assertStringContainsString('Buttons Plain Wordset', $html);
        $this->assertStringContainsString('ll-wordset-buttons-shortcode__image', $html);
        $this->assertSame(1, substr_count($html, 'll-wordset-buttons-shortcode__media'));
    }

    public function test_shortcode_returns_anonymous_cached_html_when_available(): void
    {
        wp_set_current_user(0);
        $cache_key = ll_tools_wordset_buttons_shortcode_cache_key([
            'class' => '',
            'hide_empty' => '0',
        ], 'll_wordset_buttons');
        $cached_html = '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Cached wordsets</a></div>';
        ll_tools_wordset_buttons_shortcode_cache_set($cache_key, $cached_html);

        $this->assertSame($cached_html, do_shortcode('[ll_wordset_buttons]'));

        ll_tools_purge_wordset_buttons_shortcode_cache();
        $this->assertFalse(get_transient($cache_key));
    }

    private function createPublishedLessonForWordset(int $wordset_id, string $title): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);

        return (int) $lesson_id;
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

        $metadata = function_exists('wp_generate_attachment_metadata')
            ? wp_generate_attachment_metadata($attachment_id, $file_path)
            : [];
        if (is_array($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }
}
