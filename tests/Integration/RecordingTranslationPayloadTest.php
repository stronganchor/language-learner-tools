<?php
declare(strict_types=1);

final class RecordingTranslationPayloadTest extends LL_Tools_TestCase
{
    public function test_audio_text_rows_include_recording_specific_translations(): void
    {
        $category = wp_insert_term('Recording Translation Payload Category', 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'draft',
            'post_title'  => 'Caminar',
        ]);
        update_post_meta($word_id, 'word_translation', 'Walk');
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        $isolation_type_id = $this->ensureRecordingType('isolation', 'Isolation');
        $question_type_id = $this->ensureRecordingType('question', 'Question');

        $this->createAudioPost($word_id, $isolation_type_id, 'Caminar isolation', 'caminar', 'Walk now', 'https://example.com/audio/caminar-isolation.mp3');
        $this->createAudioPost($word_id, $question_type_id, 'Caminar question', 'caminar?', 'To walk?', 'https://example.com/audio/caminar-question.mp3');

        wp_update_post([
            'ID' => $word_id,
            'post_status' => 'publish',
        ]);

        $rows = ll_get_words_by_category(
            'Recording Translation Payload Category',
            'text_translation',
            null,
            [
                'prompt_type' => 'audio',
                'option_type' => 'text_translation',
            ]
        );

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame($word_id, (int) ($row['id'] ?? 0));
        $recording_translations_by_type = (array) ($row['recording_translations_by_type'] ?? []);
        $this->assertSame('Walk now', (string) ($recording_translations_by_type['isolation'] ?? ''));
        $this->assertSame('To walk?', (string) ($recording_translations_by_type['question'] ?? ''));

        $audio_translation_by_type = [];
        foreach ((array) ($row['audio_files'] ?? []) as $audio_file) {
            $recording_type = (string) ($audio_file['recording_type'] ?? '');
            if ($recording_type === '') {
                continue;
            }
            $audio_translation_by_type[$recording_type] = (string) ($audio_file['recording_translation'] ?? '');
        }

        $this->assertSame('Walk now', (string) ($audio_translation_by_type['isolation'] ?? ''));
        $this->assertSame('To walk?', (string) ($audio_translation_by_type['question'] ?? ''));
    }

    public function test_category_rows_use_active_category_title_storage_for_translation_answers(): void
    {
        $broad_category = wp_insert_term('Broad Number Category', 'word-category');
        $this->assertFalse(is_wp_error($broad_category));
        $this->assertIsArray($broad_category);
        $broad_category_id = (int) $broad_category['term_id'];
        update_term_meta($broad_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($broad_category_id, 'll_quiz_option_type', 'text_translation');

        $active_category = wp_insert_term('Active Number Category', 'word-category');
        $this->assertFalse(is_wp_error($active_category));
        $this->assertIsArray($active_category);
        $active_category_id = (int) $active_category['term_id'];
        update_term_meta($active_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($active_category_id, 'll_quiz_option_type', 'text_translation');
        update_term_meta($active_category_id, 'use_word_titles_for_audio', '1');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Pûnces',
        ]);
        update_post_meta($word_id, 'word_translation', '15');
        wp_set_post_terms($word_id, [$broad_category_id, $active_category_id], 'word-category', false);

        $isolation_type_id = $this->ensureRecordingType('isolation', 'Isolation');
        $this->createAudioPost($word_id, $isolation_type_id, 'Pûnces isolation', 'Pûnces', '', 'https://example.com/audio/punces.mp3');

        $rows = ll_get_words_by_category(
            'Active Number Category',
            'text_translation',
            null,
            [
                'prompt_type' => 'audio',
                'option_type' => 'text_translation',
                'use_titles' => true,
            ]
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Pûnces', (string) ($rows[0]['title'] ?? ''));
        $this->assertSame('15', (string) ($rows[0]['translation'] ?? ''));
        $this->assertSame('15', (string) ($rows[0]['label'] ?? ''));
        $this->assertSame('Pûnces', (string) ($rows[0]['prompt_label'] ?? ''));
    }

    private function ensureRecordingType(string $slug, string $label): int
    {
        $term = wp_insert_term($label, 'recording_type', ['slug' => $slug]);
        if (!is_wp_error($term)) {
            $this->assertIsArray($term);
            return (int) $term['term_id'];
        }

        $existing = get_term_by('slug', $slug, 'recording_type');
        $this->assertInstanceOf(WP_Term::class, $existing);
        return (int) $existing->term_id;
    }

    private function createAudioPost(
        int $word_id,
        int $recording_type_id,
        string $title,
        string $recording_text,
        string $recording_translation,
        string $audio_file_path
    ): int {
        $audio_id = self::factory()->post->create([
            'post_type'   => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title'  => $title,
        ]);

        update_post_meta($audio_id, 'audio_file_path', $audio_file_path);
        update_post_meta($audio_id, 'recording_text', $recording_text);
        update_post_meta($audio_id, 'recording_translation', $recording_translation);
        wp_set_post_terms($audio_id, [$recording_type_id], 'recording_type', false);

        return $audio_id;
    }
}
