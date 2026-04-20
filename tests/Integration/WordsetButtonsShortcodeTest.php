<?php
declare(strict_types=1);

final class WordsetButtonsShortcodeTest extends LL_Tools_TestCase
{
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
}
