<?php
declare(strict_types=1);

final class ContentLessonCueParsingTest extends LL_Tools_TestCase
{
    public function test_content_lesson_parses_supported_timing_formats(): void
    {
        $vtt = <<<VTT
WEBVTT

00:00:01.000 --> 00:00:03.500
First line

00:00:04.000 --> 00:00:06.000
Second line
VTT;

        $vtt_cues = ll_tools_content_lesson_parse_source($vtt, 'vtt');
        $this->assertIsArray($vtt_cues);
        $this->assertCount(2, $vtt_cues);
        $this->assertSame('First line', $vtt_cues[0]['text']);
        $this->assertSame(1000, $vtt_cues[0]['start_ms']);
        $this->assertSame(3500, $vtt_cues[0]['end_ms']);

        $tsv = <<<TSV
id\ttext_full\tstart_sec\tend_sec
1\tHello world\t1.168\t3.888
2\tSecond phrase\t4.000\t5.500
TSV;

        $tsv_cues = ll_tools_content_lesson_parse_source($tsv, 'tsv');
        $this->assertIsArray($tsv_cues);
        $this->assertCount(2, $tsv_cues);
        $this->assertSame('Hello world', $tsv_cues[0]['text']);
        $this->assertSame(1168, $tsv_cues[0]['start_ms']);

        $json = wp_json_encode([
            'lines' => [
                [
                    'start_sec' => 1.5,
                    'end_sec' => 2.75,
                    'text_projected' => 'Projected line',
                ],
            ],
        ]);

        $json_cues = ll_tools_content_lesson_parse_source((string) $json, 'json');
        $this->assertIsArray($json_cues);
        $this->assertCount(1, $json_cues);
        $this->assertSame('Projected line', $json_cues[0]['text']);
        $this->assertSame(1500, $json_cues[0]['start_ms']);
        $this->assertSame(2750, $json_cues[0]['end_ms']);
    }

    public function test_content_lesson_relationship_helpers_link_wordset_and_vocab_pages(): void
    {
        $wordset = wp_insert_term('Story Wordset', 'wordset', ['slug' => 'story-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Story Category', 'word-category', ['slug' => 'story-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);

        $vocab_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Story Category Lesson',
        ]);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Story Lesson',
            'post_excerpt' => 'Main story lesson.',
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$category_id]);

        $wordset_lessons = ll_tools_get_content_lessons_for_wordset($wordset_id);
        $this->assertCount(1, $wordset_lessons);
        $this->assertSame($content_lesson_id, (int) $wordset_lessons[0]['id']);
        $this->assertSame(1, (int) $wordset_lessons[0]['category_count']);

        $related_to_vocab = ll_tools_get_content_lessons_for_vocab_lesson($wordset_id, $category_id);
        $this->assertCount(1, $related_to_vocab);
        $this->assertSame($content_lesson_id, (int) $related_to_vocab[0]['id']);

        $related_vocab_items = ll_tools_get_content_lesson_related_vocab_items($content_lesson_id);
        $this->assertCount(1, $related_vocab_items);
        $this->assertSame($category_id, (int) $related_vocab_items[0]['id']);
        $this->assertNotSame('', (string) $related_vocab_items[0]['url']);
    }
}
