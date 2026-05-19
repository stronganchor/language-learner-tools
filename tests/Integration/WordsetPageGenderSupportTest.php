<?php
declare(strict_types=1);

final class WordsetPageGenderSupportTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (function_exists('ll_register_part_of_speech_taxonomy')) {
            ll_register_part_of_speech_taxonomy();
        }
    }

    public function test_wordset_page_categories_mark_gender_supported_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Wordset Page Gender ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
        update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);

        $category = wp_insert_term('Wordset Page Gender Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : $category_id;
        if ($effective_category_id <= 0) {
            $effective_category_id = $category_id;
        }

        foreach (array_values(array_unique([$category_id, $effective_category_id])) as $term_id) {
            update_term_meta($term_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($term_id, 'll_quiz_option_type', 'text_title');
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Wordset Page Gender Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        for ($index = 1; $index <= 5; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Wordset Page Gender Word ' . $index . ' ' . wp_generate_password(4, false),
            ]);
            wp_set_post_terms($word_id, [$effective_category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$noun_term_id], 'part_of_speech', false);
            update_post_meta($word_id, 'word_translation', 'Gender Translation ' . $index);
            update_post_meta($word_id, 'll_grammatical_gender', ($index % 2 === 0) ? 'Feminine' : 'Masculine');
        }

        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $target_category = null;
        foreach ($categories as $row) {
            if ((int) ($row['id'] ?? 0) === $effective_category_id) {
                $target_category = $row;
                break;
            }
        }

        $this->assertIsArray($target_category);
        $this->assertTrue((bool) ($target_category['gender_supported'] ?? false));
    }

    public function test_wordset_page_batched_gender_support_respects_media_requirements(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Wordset Page Gender Media ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
        update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);

        $noun_term_id = $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $image_category_id = $this->createCategoryWithLesson(
            $wordset_id,
            'Wordset Page Gender Image Category',
            'text_title',
            'image'
        );
        $audio_category_id = $this->createCategoryWithLesson(
            $wordset_id,
            'Wordset Page Gender Audio Category',
            'audio',
            'text_title'
        );

        for ($index = 1; $index <= 5; $index++) {
            $image_word_id = $this->createGenderWord(
                $wordset_id,
                $image_category_id,
                $noun_term_id,
                'Image Gender Word ' . $index,
                ($index % 2 === 0) ? 'Feminine' : 'Masculine'
            );
            $this->assignStubThumbnail($image_word_id, 'Image Gender Attachment ' . $index);

            $audio_word_id = $this->createGenderWord(
                $wordset_id,
                $audio_category_id,
                $noun_term_id,
                'Audio Gender Word ' . $index,
                ($index % 2 === 0) ? 'Feminine' : 'Masculine'
            );
            if ($index <= 4) {
                $this->createAudioRecording($audio_word_id, 'audio-gender-word-' . $index . '.mp3');
            }
        }

        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $category_map = [];
        foreach ($categories as $row) {
            $category_map[(int) ($row['id'] ?? 0)] = $row;
        }

        $this->assertArrayHasKey($image_category_id, $category_map);
        $this->assertArrayHasKey($audio_category_id, $category_map);
        $this->assertTrue((bool) ($category_map[$image_category_id]['gender_supported'] ?? false));
        $this->assertFalse((bool) ($category_map[$audio_category_id]['gender_supported'] ?? true));
    }

    private function createCategoryWithLesson(int $wordset_id, string $base_name, string $prompt_type, string $option_type): int
    {
        $category = wp_insert_term($base_name . ' ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : $category_id;
        if ($effective_category_id <= 0) {
            $effective_category_id = $category_id;
        }

        foreach (array_values(array_unique([$category_id, $effective_category_id])) as $term_id) {
            update_term_meta($term_id, 'll_quiz_prompt_type', $prompt_type);
            update_term_meta($term_id, 'll_quiz_option_type', $option_type);
        }

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $base_name . ' Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);

        return $effective_category_id;
    }

    private function createGenderWord(int $wordset_id, int $category_id, int $noun_term_id, string $title, string $gender): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$noun_term_id], 'part_of_speech', false);
        update_post_meta($word_id, 'word_translation', $title . ' Translation');
        update_post_meta($word_id, 'll_grammatical_gender', $gender);

        return (int) $word_id;
    }

    private function assignStubThumbnail(int $word_id, string $title): void
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => $title,
        ]);
        update_post_meta($word_id, '_thumbnail_id', (int) $attachment_id);
    }

    private function createAudioRecording(int $word_id, string $audio_file_name): int
    {
        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $word_id,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return (int) $audio_post_id;
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): int
    {
        $existing = term_exists($slug, 'part_of_speech');
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $created = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        if (is_wp_error($created)) {
            $term = get_term_by('slug', $slug, 'part_of_speech');
            $this->assertInstanceOf(WP_Term::class, $term);
            return (int) $term->term_id;
        }

        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }
}
