<?php
declare(strict_types=1);

final class VocabLessonPromptCardCountTest extends LL_Tools_TestCase
{
    public function test_prompt_card_only_category_is_counted_for_vocab_lessons(): void
    {
        $asset_category_id = $this->createCategory('Prompt Card Assets ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $prompt_category_id = $this->createCategory('Prompt Card Lesson ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $wordset_id = $this->createWordset('Prompt Card Lesson Wordset ' . wp_generate_password(5, false));
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $answer_word_id = $this->createWord($asset_category_id, 'Yes');
        $wrong_word_id = $this->createWord($asset_category_id, 'No');

        for ($index = 1; $index <= 5; $index++) {
            $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
                'title' => 'Prompt Card Lesson ' . $index,
                'prompt_text' => 'Prompt Card Lesson Question ' . $index,
                'correct_answer_word_id' => $answer_word_id,
                'wrong_answer_word_ids' => [$wrong_word_id],
            ]);
        }

        $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id, true);
        $this->assertSame(5, (int) ($counts['all'][$effective_prompt_category_id] ?? 0));
        $this->assertSame(0, (int) ($counts['with_images'][$effective_prompt_category_id] ?? 0));
        $this->assertTrue(ll_tools_can_generate_vocab_lesson($effective_prompt_category_id, $wordset_id));
        $this->assertContains($effective_prompt_category_id, ll_tools_get_vocab_lesson_category_ids_for_wordset($wordset_id, true));
    }

    public function test_deepest_counts_use_bounded_relationship_queries_without_depth_union_tables(): void
    {
        global $wpdb;

        $asset_category_id = $this->createCategory('Prompt Card Query Assets ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $prompt_category_id = $this->createCategory('Prompt Card Query Lesson ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $wordset_id = $this->createWordset('Prompt Card Query Wordset ' . wp_generate_password(5, false));
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $word_id = $this->createWord($effective_prompt_category_id, 'Query Word With Image');
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        $this->addImage($word_id, '-query-word');

        $answer_word_id = $this->createWord($asset_category_id, 'Query Answer With Image');
        $this->addImage($answer_word_id, '-query-answer');
        $wrong_word_id = $this->createWord($asset_category_id, 'Query Wrong');
        $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'Prompt Card Query Lesson',
            'prompt_text' => 'Prompt Card Query Question',
            'correct_answer_word_id' => $answer_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id, true);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertSame(2, (int) ($counts['all'][$effective_prompt_category_id] ?? 0));
        $this->assertSame(2, (int) ($counts['with_images'][$effective_prompt_category_id] ?? 0));

        $relationship_queries = array_values(array_filter($captured_queries, static function (string $query): bool {
            return strpos($query, 'posts.ID AS post_id') !== false
                && strpos($query, 'category_taxonomy.term_id AS category_id') !== false
                && strpos($query, 'category_relationships.term_taxonomy_id AS term_taxonomy_id') !== false;
        }));

        $this->assertGreaterThanOrEqual(4, count($relationship_queries), 'Expected deepest counts to use bounded post/category relationship queries.');
        foreach ($relationship_queries as $query) {
            $this->assertStringContainsString('category_taxonomy.taxonomy =', $query);
            $this->assertStringNotContainsString('UNION ALL', $query);
            $this->assertStringNotContainsString('category_depths', $query);
            $this->assertStringNotContainsString('deeper_depths', $query);
            $this->assertStringNotContainsString('COUNT(DISTINCT posts.ID) AS total', $query);
            $this->assertStringNotContainsString('posts.ID AS object_id', $query);
            $this->assertStringNotContainsString('SELECT ' . $wpdb->posts . '.ID', $query);
        }
    }

    public function test_deepest_counts_exclude_specific_wrong_answer_only_words(): void
    {
        $category_id = $this->createCategory('Prompt Card Wrong Only ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $wordset_id = $this->createWordset('Prompt Card Wrong Only Wordset ' . wp_generate_password(5, false));
        $effective_category_id = $this->resolveEffectiveCategoryId($category_id, $wordset_id);

        $owner_word_id = $this->createWord($effective_category_id, 'Wrong Only Owner');
        wp_set_post_terms($owner_word_id, [$wordset_id], 'wordset', false);
        $reserved_word_id = $this->createWord($effective_category_id, 'Wrong Only Reserved');
        wp_set_post_terms($reserved_word_id, [$wordset_id], 'wordset', false);

        update_post_meta($owner_word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$reserved_word_id]);
        update_post_meta($owner_word_id, LL_TOOLS_SPECIFIC_WRONG_ANSWER_TEXTS_META_KEY, ['Typed Wrong']);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id, true);

        $this->assertSame(1, (int) ($counts['all'][$effective_category_id] ?? 0));
        $this->assertSame(0, (int) ($counts['with_images'][$effective_category_id] ?? 0));
    }

    public function test_prompt_card_counts_do_not_pull_child_words_up_to_parent_vocab_lesson_counts(): void
    {
        $asset_category_id = $this->createCategory('Prompt Card Tree Assets ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $parent_category_id = $this->createCategory('Prompt Card Tree Parent ' . wp_generate_password(5, false), 'text_title', 'text_title');
        $child_category_id = $this->createCategory(
            'Prompt Card Tree Child ' . wp_generate_password(5, false),
            'text_title',
            'text_title',
            $parent_category_id
        );
        $wordset_id = $this->createWordset('Prompt Card Tree Wordset ' . wp_generate_password(5, false));

        $effective_parent_category_id = $this->resolveEffectiveCategoryId($parent_category_id, $wordset_id);
        $effective_child_category_id = $this->resolveEffectiveCategoryId($child_category_id, $wordset_id);

        $child_word_id = $this->createWord($effective_child_category_id, 'Child Word');
        wp_set_post_terms($child_word_id, [$wordset_id], 'wordset', false);

        $answer_word_id = $this->createWord($asset_category_id, 'Parent Answer');
        $wrong_word_id = $this->createWord($asset_category_id, 'Parent Wrong');
        $this->createPromptCard($effective_parent_category_id, $wordset_id, [
            'title' => 'Parent Prompt Card',
            'prompt_text' => 'Parent Prompt Question',
            'correct_answer_word_id' => $answer_word_id,
            'wrong_answer_word_ids' => [$wrong_word_id],
        ]);

        $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordset_id, true);

        $this->assertSame(1, (int) ($counts['all'][$effective_parent_category_id] ?? 0));
        $this->assertSame(1, (int) ($counts['all'][$effective_child_category_id] ?? 0));
        $this->assertSame(0, (int) ($counts['all'][$parent_category_id] ?? 0));
        $this->assertSame(0, (int) ($counts['with_images'][$effective_parent_category_id] ?? 0));
        $this->assertSame(0, (int) ($counts['with_images'][$effective_child_category_id] ?? 0));
    }

    private function createCategory(string $name, string $prompt_type, string $option_type, int $parent_id = 0): int
    {
        $term = wp_insert_term($name, 'word-category', [
            'parent' => $parent_id,
        ]);
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        $category_id = (int) $term['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', $prompt_type);
        update_term_meta($category_id, 'll_quiz_option_type', $option_type);

        return $category_id;
    }

    private function createWordset(string $name): int
    {
        $term = wp_insert_term($name, 'wordset');
        $this->assertFalse(is_wp_error($term));
        $this->assertIsArray($term);

        return (int) $term['term_id'];
    }

    private function createWord(int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }

    private function addImage(int $word_id, string $suffix = ''): void
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $suffix,
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/06/vocab-lesson-count-' . $word_id . $suffix . '.jpg');
        set_post_thumbnail($word_id, $attachment_id);
    }

    /**
     * @param array<string,mixed> $args
     */
    private function createPromptCard(int $category_id, int $wordset_id, array $args): int
    {
        $post_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => (string) ($args['title'] ?? 'Prompt Card'),
        ]);

        wp_set_post_terms($post_id, [$category_id], 'word-category', false);
        wp_set_post_terms($post_id, [$wordset_id], 'wordset', false);
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, (string) ($args['prompt_text'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, (int) ($args['prompt_image_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, (int) ($args['correct_answer_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', (array) ($args['wrong_answer_word_ids'] ?? []))));

        return (int) $post_id;
    }

    private function resolveEffectiveCategoryId(int $category_id, int $wordset_id): int
    {
        if (function_exists('ll_tools_get_effective_category_id_for_wordset')) {
            $resolved = (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true);
            if ($resolved > 0) {
                return $resolved;
            }
        }

        return $category_id;
    }
}
