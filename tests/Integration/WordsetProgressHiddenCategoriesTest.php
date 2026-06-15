<?php
declare(strict_types=1);

final class WordsetProgressHiddenCategoriesTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_progress_view_excludes_hidden_categories_from_default_analytics(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createWordsetFixture();
        ll_tools_save_user_study_goals([
            'ignored_category_ids' => [(int) $fixture['hidden_category_id']],
        ], $user_id);

        $this->seedWordProgressRow($user_id, (int) $fixture['visible_word_id'], (int) $fixture['visible_category_id'], (int) $fixture['wordset_id'], [
            'total_coverage' => 3,
            'coverage_practice' => 3,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);
        $this->seedWordProgressRow($user_id, (int) $fixture['hidden_word_id'], (int) $fixture['hidden_category_id'], (int) $fixture['wordset_id'], [
            'total_coverage' => 3,
            'coverage_practice' => 3,
            'correct_clean' => 1,
            'incorrect' => 1,
            'stage' => 1,
        ]);

        $config = $this->renderProgressConfig((int) $fixture['wordset_id']);
        $analytics = (array) ($config['analytics'] ?? []);

        $this->assertFalse((bool) ($config['progressIncludeHidden'] ?? true));
        $this->assertSame(5, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertSame(1, (int) ($analytics['summary']['studied_words'] ?? 0));
        $this->assertSame(4, (int) ($analytics['summary']['new_words'] ?? 0));
        $this->assertSame(4, (int) ($config['summaryCounts']['new'] ?? 0));

        $category_ids = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($analytics['categories'] ?? []));
        $this->assertSame([(int) $fixture['visible_category_id']], $category_ids);

        $this->assertTrue((bool) ($analytics['words_omitted'] ?? false));
        $this->assertSame([], (array) ($analytics['words'] ?? []));
        $scope_category_ids = array_map('intval', (array) ($analytics['scope']['category_ids'] ?? []));
        $this->assertContains((int) $fixture['visible_category_id'], $scope_category_ids);
        $this->assertNotContains((int) $fixture['hidden_category_id'], $scope_category_ids);
    }

    /**
     * @return array{
     *   wordset_id:int,
     *   visible_category_id:int,
     *   hidden_category_id:int,
     *   visible_word_id:int,
     *   hidden_word_id:int
     * }
     */
    private function createWordsetFixture(): array
    {
        $wordset = wp_insert_term('Progress Hidden Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $visible_category = wp_insert_term('Progress Visible Category ' . wp_generate_password(6, false), 'word-category');
        $hidden_category = wp_insert_term('Progress Hidden Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($visible_category));
        $this->assertFalse(is_wp_error($hidden_category));
        $this->assertIsArray($visible_category);
        $this->assertIsArray($hidden_category);

        $raw_visible_category_id = (int) $visible_category['term_id'];
        $raw_hidden_category_id = (int) $hidden_category['term_id'];
        $visible_category_id = $this->resolveEffectiveCategoryId($raw_visible_category_id, $wordset_id);
        $hidden_category_id = $this->resolveEffectiveCategoryId($raw_hidden_category_id, $wordset_id);
        foreach (array_values(array_unique([$raw_visible_category_id, $visible_category_id])) as $term_id) {
            update_term_meta($term_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($term_id, 'll_quiz_option_type', 'text_title');
        }
        foreach (array_values(array_unique([$raw_hidden_category_id, $hidden_category_id])) as $term_id) {
            update_term_meta($term_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($term_id, 'll_quiz_option_type', 'text_title');
        }

        $visible_word_id = 0;
        $hidden_word_id = 0;
        for ($index = 1; $index <= 5; $index++) {
            $word_id = $this->createWordWithAudio(
                'Progress Visible Word ' . $index,
                'Progress Visible Translation ' . $index,
                $visible_category_id,
                $wordset_id,
                'progress-visible-' . $index . '.mp3'
            );
            if ($visible_word_id === 0) {
                $visible_word_id = $word_id;
            }

            $word_id = $this->createWordWithAudio(
                'Progress Hidden Word ' . $index,
                'Progress Hidden Translation ' . $index,
                $hidden_category_id,
                $wordset_id,
                'progress-hidden-' . $index . '.mp3'
            );
            if ($hidden_word_id === 0) {
                $hidden_word_id = $word_id;
            }
        }

        $this->createVocabLesson($wordset_id, $visible_category_id);
        $this->createVocabLesson($wordset_id, $hidden_category_id);

        return [
            'wordset_id' => $wordset_id,
            'visible_category_id' => $visible_category_id,
            'hidden_category_id' => $hidden_category_id,
            'visible_word_id' => $visible_word_id,
            'hidden_word_id' => $hidden_word_id,
        ];
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
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

    private function resolveEffectiveCategoryId(int $category_id, int $wordset_id): int
    {
        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : 0;

        return ($effective_category_id > 0) ? $effective_category_id : $category_id;
    }

    private function createVocabLesson(int $wordset_id, int $category_id): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Progress Category Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $category_id);

        return (int) $lesson_id;
    }

    /**
     * @return array<string,mixed>
     */
    private function renderProgressConfig(int $wordset_id): array
    {
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', 'progress');
        $bootstrap_filter = static function ($bootstrap, $view): bool {
            return $view === 'progress' ? true : (bool) $bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 2);

        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $this->assertNotSame('', $html);
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
        $this->assertNotSame('', $localized);
        preg_match('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);

        $decoded = json_decode((string) $matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function seedWordProgressRow(int $user_id, int $word_id, int $category_id, int $wordset_id, array $overrides): void
    {
        global $wpdb;
        $tables = ll_tools_user_progress_table_names();
        $table = $tables['words'];

        $now = gmdate('Y-m-d H:i:s');
        $data = array_merge([
            'user_id' => $user_id,
            'word_id' => $word_id,
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_mode' => 'practice',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'due_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace($table, $data, [
            '%d', '%d', '%d', '%d', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d',
            '%d', '%d', '%d', '%d', '%d', '%s', '%s',
        ]);
        $this->assertNotFalse($inserted);
    }
}
