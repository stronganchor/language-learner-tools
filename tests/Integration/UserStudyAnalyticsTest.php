<?php
declare(strict_types=1);

final class UserStudyAnalyticsTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_build_analytics_payload_includes_summary_categories_and_words(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        [$word_a, $word_b, $word_c] = $fixture['word_ids'];
        [$cat_a, $cat_b] = $fixture['category_ids'];

        $this->seedWordProgressRow($user_id, $word_a, $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 6,
            'correct_clean' => 4,
            'correct_after_retry' => 0,
            'incorrect' => 1,
            'lapse_count' => 0,
            'stage' => 6,
        ]);
        $this->seedWordProgressRow($user_id, $word_b, $cat_a, $fixture['wordset_id'], [
            'total_coverage' => 5,
            'correct_clean' => 1,
            'correct_after_retry' => 1,
            'incorrect' => 4,
            'lapse_count' => 3,
            'stage' => 1,
        ]);
        // Leave $word_c without a progress row so it remains "new".

        ll_tools_record_category_exposure($user_id, $cat_a, 'practice', $fixture['wordset_id'], 8);
        ll_tools_record_category_exposure($user_id, $cat_a, 'learning', $fixture['wordset_id'], 2);
        ll_tools_record_category_exposure($user_id, $cat_b, 'learning', $fixture['wordset_id'], 1);

        ll_tools_save_user_study_state([
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'starred_word_ids' => [$word_b],
            'star_mode' => 'normal',
            'fast_transitions' => false,
        ], $user_id);

        $stats = ll_tools_process_progress_events_batch($user_id, [[
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_exposure',
            'mode' => 'practice',
            'word_id' => $word_a,
            'category_id' => $cat_a,
            'wordset_id' => $fixture['wordset_id'],
            'payload' => [],
        ]]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $fixture['wordset_id'],
            $fixture['category_ids'],
            14
        );

        $this->assertSame(10, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertSame(1, (int) ($analytics['summary']['mastered_words'] ?? 0));
        $this->assertSame(2, (int) ($analytics['summary']['studied_words'] ?? 0));
        $this->assertSame(8, (int) ($analytics['summary']['new_words'] ?? 0));
        $this->assertSame(1, (int) ($analytics['summary']['starred_words'] ?? 0));
        $this->assertNotEmpty($analytics['categories']);
        $this->assertCount(2, (array) ($analytics['categories'] ?? []));
        $this->assertCount(10, (array) ($analytics['words'] ?? []));
        $this->assertCount(14, (array) ($analytics['daily_activity']['days'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($analytics['daily_activity']['max_events'] ?? 0));

        $words_by_id = [];
        foreach ((array) ($analytics['words'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $wid = isset($row['id']) ? (int) $row['id'] : 0;
            if ($wid > 0) {
                $words_by_id[$wid] = $row;
            }
        }

        $this->assertSame('mastered', (string) ($words_by_id[$word_a]['status'] ?? ''));
        $this->assertSame('studied', (string) ($words_by_id[$word_b]['status'] ?? ''));
        $this->assertSame('new', (string) ($words_by_id[$word_c]['status'] ?? ''));
    }

    public function test_analytics_filters_out_non_quizzable_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        $analytics = ll_tools_build_user_study_analytics_payload(
            $user_id,
            $wordset_id,
            [$quizzable_category_id, $non_quizzable_category_id],
            14
        );

        $this->assertSame(5, (int) ($analytics['summary']['total_words'] ?? 0));
        $this->assertCount(1, (array) ($analytics['categories'] ?? []));

        $category_ids = array_map(static function ($row): int {
            return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
        }, (array) ($analytics['categories'] ?? []));
        $this->assertContains($quizzable_category_id, $category_ids);
        $this->assertNotContains($non_quizzable_category_id, $category_ids);
    }

    public function test_user_study_words_filters_out_non_quizzable_requested_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        $words_by_category = ll_tools_user_study_words(
            [$quizzable_category_id, $non_quizzable_category_id],
            $wordset_id
        );

        $this->assertArrayHasKey($quizzable_category_id, $words_by_category);
        $this->assertArrayNotHasKey($non_quizzable_category_id, $words_by_category);
        $this->assertCount(5, (array) ($words_by_category[$quizzable_category_id] ?? []));
    }

    public function test_user_study_fetch_words_ajax_filters_out_non_quizzable_requested_categories(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createMixedQuizzableFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $quizzable_category_id = (int) $fixture['quizzable_category_id'];
        $non_quizzable_category_id = (int) $fixture['non_quizzable_category_id'];

        $_POST = [
            'nonce' => wp_create_nonce('ll_user_study'),
            'wordset_id' => $wordset_id,
            'category_ids' => [$quizzable_category_id, $non_quizzable_category_id],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_fetch_words_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $words_by_category = (array) ($response['data']['words_by_category'] ?? []);
        $this->assertArrayHasKey((string) $quizzable_category_id, $words_by_category);
        $this->assertArrayNotHasKey((string) $non_quizzable_category_id, $words_by_category);
    }

    public function test_analytics_ajax_returns_payload(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $fixture = $this->createAnalyticsFixture();
        $nonce = wp_create_nonce('ll_user_study');

        $_POST = [
            'nonce' => $nonce,
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => $fixture['category_ids'],
            'days' => 14,
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_user_study_analytics_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data']['analytics'] ?? null);
        $this->assertArrayHasKey('summary', $response['data']['analytics']);
        $this->assertArrayHasKey('categories', $response['data']['analytics']);
        $this->assertArrayHasKey('words', $response['data']['analytics']);
    }

    /**
     * @return array{
     *   wordset_id:int,
     *   category_ids:int[],
     *   word_ids:int[]
     * }
     */
    private function createAnalyticsFixture(): array
    {
        $wordset = wp_insert_term('Analytics Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $cat_a_term = wp_insert_term('Analytics Category A ' . wp_generate_password(6, false), 'word-category');
        $cat_b_term = wp_insert_term('Analytics Category B ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($cat_a_term));
        $this->assertFalse(is_wp_error($cat_b_term));
        $this->assertIsArray($cat_a_term);
        $this->assertIsArray($cat_b_term);
        $cat_a = (int) $cat_a_term['term_id'];
        $cat_b = (int) $cat_b_term['term_id'];

        // Use text options so image requirements do not block test words.
        update_term_meta($cat_a, 'll_quiz_prompt_type', 'audio');
        update_term_meta($cat_a, 'll_quiz_option_type', 'text_title');
        update_term_meta($cat_b, 'll_quiz_prompt_type', 'audio');
        update_term_meta($cat_b, 'll_quiz_option_type', 'text_title');

        $word_a = $this->createWordWithAudio('Analytics Word A', 'Analytics Translation A', $cat_a, $wordset_id, 'analytics-a.mp3');
        $word_b = $this->createWordWithAudio('Analytics Word B', 'Analytics Translation B', $cat_a, $wordset_id, 'analytics-b.mp3');
        $word_c = $this->createWordWithAudio('Analytics Word C', 'Analytics Translation C', $cat_b, $wordset_id, 'analytics-c.mp3');
        $this->createWordWithAudio('Analytics Word D', 'Analytics Translation D', $cat_a, $wordset_id, 'analytics-d.mp3');
        $this->createWordWithAudio('Analytics Word E', 'Analytics Translation E', $cat_a, $wordset_id, 'analytics-e.mp3');
        $this->createWordWithAudio('Analytics Word F', 'Analytics Translation F', $cat_a, $wordset_id, 'analytics-f.mp3');
        $this->createWordWithAudio('Analytics Word G', 'Analytics Translation G', $cat_b, $wordset_id, 'analytics-g.mp3');
        $this->createWordWithAudio('Analytics Word H', 'Analytics Translation H', $cat_b, $wordset_id, 'analytics-h.mp3');
        $this->createWordWithAudio('Analytics Word I', 'Analytics Translation I', $cat_b, $wordset_id, 'analytics-i.mp3');
        $this->createWordWithAudio('Analytics Word J', 'Analytics Translation J', $cat_b, $wordset_id, 'analytics-j.mp3');

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => [$cat_a, $cat_b],
            'word_ids' => [$word_a, $word_b, $word_c],
        ];
    }

    /**
     * @return array{wordset_id:int,quizzable_category_id:int,non_quizzable_category_id:int}
     */
    private function createMixedQuizzableFixture(): array
    {
        $wordset = wp_insert_term('Analytics Scope Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $quizzable_term = wp_insert_term('Analytics Quizzable Category ' . wp_generate_password(6, false), 'word-category');
        $non_quizzable_term = wp_insert_term('Analytics Nonquizzable Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($quizzable_term));
        $this->assertFalse(is_wp_error($non_quizzable_term));
        $this->assertIsArray($quizzable_term);
        $this->assertIsArray($non_quizzable_term);
        $quizzable_category_id = (int) $quizzable_term['term_id'];
        $non_quizzable_category_id = (int) $non_quizzable_term['term_id'];

        update_term_meta($quizzable_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($quizzable_category_id, 'll_quiz_option_type', 'text_title');
        update_term_meta($non_quizzable_category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($non_quizzable_category_id, 'll_quiz_option_type', 'text_title');

        // Quizzable category: meets default minimum of 5 words.
        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithAudio(
                'Analytics Quizzable Word ' . $index,
                'Analytics Quizzable Translation ' . $index,
                $quizzable_category_id,
                $wordset_id,
                'analytics-quizzable-' . $index . '.mp3'
            );
        }

        // Non-quizzable category: intentionally below minimum threshold.
        for ($index = 1; $index <= 2; $index++) {
            $this->createWordWithAudio(
                'Analytics Nonquizzable Word ' . $index,
                'Analytics Nonquizzable Translation ' . $index,
                $non_quizzable_category_id,
                $wordset_id,
                'analytics-nonquizzable-' . $index . '.mp3'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'quizzable_category_id' => $quizzable_category_id,
            'non_quizzable_category_id' => $non_quizzable_category_id,
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

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
