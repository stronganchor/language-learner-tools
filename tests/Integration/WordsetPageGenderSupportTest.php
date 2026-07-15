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

    public function test_gender_support_lookup_uses_bounded_grouped_aggregates_without_word_hydration(): void
    {
        $source = $this->getFunctionSource('ll_tools_wordset_page_collect_gender_supported_lookup');

        $this->assertStringContainsString('COUNT(DISTINCT posts.ID) AS eligible_count', $source);
        $this->assertStringContainsString(
            'GROUP BY category_taxonomy.term_id, gender_meta.meta_value',
            $source
        );
        $this->assertStringContainsString(
            'foreach ($category_ids_by_requirements as $requirements_key => $group_category_ids)',
            $source
        );
        $this->assertSame(
            1,
            substr_count($source, '$wpdb->get_results('),
            'Each requirement group should execute one aggregate query.'
        );

        $requirements_key_start = strpos($source, '$requirements_key =');
        $this->assertIsInt($requirements_key_start);
        $requirements_key_end = strpos($source, ';', $requirements_key_start);
        $this->assertIsInt($requirements_key_end);
        $requirements_key_source = substr(
            $source,
            $requirements_key_start,
            $requirements_key_end - $requirements_key_start + 1
        );
        $this->assertSame(1, substr_count($requirements_key_source, "requires_audio"));
        $this->assertSame(1, substr_count($requirements_key_source, "requires_image"));
        $this->assertSame(
            2,
            substr_count($requirements_key_source, "? '1' : '0'"),
            'Two binary requirement flags must cap the grouped aggregate path at four queries.'
        );
        $this->assertStringContainsString(
            ".':'.",
            (string) preg_replace('/\s+/', '', $requirements_key_source)
        );

        foreach ([
            'll_tools_wordset_page_get_category_word_ids(',
            'll_tools_user_study_renderable_word_ids_by_category(',
            '$wpdb->get_col(',
            'SELECT posts.ID',
            'new WP_Query(',
            'get_posts(',
            '_prime_post_caches(',
            'update_meta_cache(',
            'get_post_meta(',
        ] as $unbounded_hydration_source) {
            $this->assertStringNotContainsString(
                $unbounded_hydration_source,
                $source,
                'Gender support must stay aggregate-only on the interactive wordset path.'
            );
        }
    }

    public function test_gender_support_lookup_failures_are_incomplete_and_skip_durable_cache_writes(): void
    {
        $source = $this->getFunctionSource('ll_tools_wordset_page_collect_gender_supported_lookup');

        $prepare_guard = "if (!is_string(\$prepared) || \$prepared === '') {";
        $prepare_guard_offset = strpos($source, $prepare_guard);
        $this->assertIsInt($prepare_guard_offset);
        $query_offset = strpos($source, "\$wpdb->last_error = '';", $prepare_guard_offset);
        $this->assertIsInt($query_offset);
        $prepare_failure_branch = substr(
            $source,
            $prepare_guard_offset,
            $query_offset - $prepare_guard_offset
        );
        $this->assertStringContainsString('$sources_complete = false;', $prepare_failure_branch);
        $this->assertStringContainsString('continue;', $prepare_failure_branch);

        $query_guard = "if (\$wpdb->last_error !== '' || !is_array(\$rows)) {";
        $query_guard_offset = strpos($source, $query_guard, $query_offset);
        $this->assertIsInt($query_guard_offset);
        $row_loop_offset = strpos($source, 'foreach ($rows as $row)', $query_guard_offset);
        $this->assertIsInt($row_loop_offset);
        $query_failure_branch = substr(
            $source,
            $query_guard_offset,
            $row_loop_offset - $query_guard_offset
        );
        $this->assertStringContainsString('$sources_complete = false;', $query_failure_branch);
        $this->assertStringContainsString('continue;', $query_failure_branch);

        $incomplete_guard_offset = strrpos($source, 'if (!$sources_complete) {');
        $this->assertIsInt($incomplete_guard_offset);
        $cache_store_offset = strpos(
            $source,
            'll_tools_wordset_page_store_cached_payload(',
            $incomplete_guard_offset
        );
        $this->assertIsInt($cache_store_offset);
        $this->assertLessThan(
            $cache_store_offset,
            $incomplete_guard_offset,
            'The incomplete-source guard must run before the only durable cache write.'
        );

        $incomplete_branch = substr(
            $source,
            $incomplete_guard_offset,
            $cache_store_offset - $incomplete_guard_offset
        );
        $this->assertStringContainsString('$complete = false;', $incomplete_branch);
        $this->assertStringContainsString('return $supported_lookup;', $incomplete_branch);
        $this->assertStringNotContainsString(
            'll_tools_wordset_page_store_cached_payload(',
            $incomplete_branch
        );
        $this->assertSame(
            1,
            substr_count($source, 'll_tools_wordset_page_store_cached_payload('),
            'Incomplete aggregate results must have no alternate request or durable cache write path.'
        );
    }

    public function test_wordset_category_cache_requires_complete_gender_and_sign_meta_reads(): void
    {
        $source = $this->getFunctionSource('ll_tools_get_wordset_page_categories');

        foreach ([
            '$gender_enabled_complete = true;',
            'll_tools_wordset_has_grammatical_gender($wordset_id, $gender_enabled_complete)',
            '$sources_complete = $sources_complete && $gender_enabled_complete;',
            '$gender_options_complete = true;',
            'll_tools_wordset_get_gender_options($wordset_id, $gender_options_complete)',
            '$sources_complete = $sources_complete && $gender_options_complete;',
            '$presentation_complete = true;',
            '$sources_complete = $sources_complete && $presentation_complete;',
        ] as $required_contract) {
            $this->assertStringContainsString($required_contract, $source);
        }

        $presentation_offset = strpos($source, '$presentation_complete = true;');
        $cache_store_offset = strpos($source, "if (\$category_cache_key !== '' && \$sources_complete) {");
        $this->assertIsInt($presentation_offset);
        $this->assertIsInt($cache_store_offset);
        $this->assertLessThan($cache_store_offset, $presentation_offset);
    }

    private function getFunctionSource(string $function_name): string
    {
        $reflection = new ReflectionFunction($function_name);
        $file_name = $reflection->getFileName();
        $this->assertIsString($file_name);
        $lines = file($file_name);
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
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
