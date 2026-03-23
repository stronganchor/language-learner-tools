<?php
declare(strict_types=1);

final class UserProgressGenderTrackingTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
        if (function_exists('ll_register_part_of_speech_taxonomy')) {
            ll_register_part_of_speech_taxonomy();
        }
    }

    public function test_gender_word_outcome_persists_full_state_snapshot(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        [$word_id, $category_id, $wordset_id] = $this->createGenderWordFixture('Persisted Gender Word', 'Persisted Translation', 'noun', 'Masculine');

        $event = [
            'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome',
            'mode' => 'gender',
            'word_id' => $word_id,
            'category_id' => $category_id,
            'category_name' => (string) get_term_field('name', $category_id, 'word-category'),
            'wordset_id' => $wordset_id,
            'is_correct' => true,
            'had_wrong_before' => false,
            'payload' => [
                'gender' => [
                    'level' => 2,
                    'confidence' => 5,
                    'intro_seen' => true,
                    'quick_correct_streak' => 2,
                    'level1_passes' => 3,
                    'level1_failures' => 1,
                    'level2_correct' => 4,
                    'level2_wrong' => 1,
                    'level3_correct' => 0,
                    'level3_wrong' => 0,
                    'dont_know_count' => 1,
                    'seen_total' => 9,
                    'category_name' => 'Persisted Category',
                    'updated_at' => 1710938400000,
                ],
            ],
        ];

        $stats = ll_tools_process_progress_events_batch($user_id, [$event]);
        $this->assertSame(1, (int) ($stats['processed'] ?? 0));

        $rows = ll_tools_get_user_word_progress_rows($user_id, [$word_id]);
        $this->assertArrayHasKey($word_id, $rows);
        $row = $rows[$word_id];
        $gender_progress = (array) ($row['gender_progress'] ?? []);

        $this->assertSame(2, (int) ($row['gender_level'] ?? 0));
        $this->assertSame(9, (int) ($row['gender_seen_total'] ?? 0));
        $this->assertNotSame('', (string) ($row['gender_last_seen_at'] ?? ''));
        $this->assertNotSame('', (string) ($row['gender_state_json_raw'] ?? ''));

        $this->assertSame(2, (int) ($gender_progress['level'] ?? 0));
        $this->assertSame(5, (int) ($gender_progress['confidence'] ?? 0));
        $this->assertTrue((bool) ($gender_progress['intro_seen'] ?? false));
        $this->assertSame(2, (int) ($gender_progress['quick_correct_streak'] ?? 0));
        $this->assertSame(3, (int) ($gender_progress['level1_passes'] ?? 0));
        $this->assertSame(1, (int) ($gender_progress['level1_failures'] ?? 0));
        $this->assertSame(4, (int) ($gender_progress['level2_correct'] ?? 0));
        $this->assertSame(1, (int) ($gender_progress['level2_wrong'] ?? 0));
        $this->assertSame(1, (int) ($gender_progress['dont_know_count'] ?? 0));
        $this->assertSame(9, (int) ($gender_progress['seen_total'] ?? 0));
        $this->assertSame('Persisted Category', (string) ($gender_progress['category_name'] ?? ''));
        $this->assertNotSame('', (string) ($gender_progress['last_seen_at'] ?? ''));
    }

    public function test_analytics_includes_gender_progress_for_marked_noun_words_only(): void
    {
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $wordset = wp_insert_term('Analytics Gender Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
        update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);

        $category_term = wp_insert_term('Analytics Gender Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $word_a = $this->createGenderWordFixture('Gender Analytics A', 'Translation A', 'noun', 'Masculine', $category_id, $wordset_id)[0];
        $word_b = $this->createGenderWordFixture('Gender Analytics B', 'Translation B', 'noun', 'Feminine', $category_id, $wordset_id)[0];
        $word_c = $this->createGenderWordFixture('Gender Analytics C', 'Translation C', 'noun', 'Masculine', $category_id, $wordset_id)[0];
        $this->createGenderWordFixture('Gender Analytics D', 'Translation D', 'verb', 'Masculine', $category_id, $wordset_id);
        $this->createGenderWordFixture('Gender Analytics E', 'Translation E', 'noun', '', $category_id, $wordset_id);

        $this->seedWordProgressRow($user_id, $word_a, $category_id, $wordset_id, [
            'gender_level' => 2,
            'gender_seen_total' => 6,
            'gender_last_seen_at' => '2026-03-20 10:00:00',
            'gender_state_json' => ll_tools_encode_gender_progress_state([
                'level' => 2,
                'confidence' => 3,
                'intro_seen' => true,
                'level1_passes' => 3,
                'level2_correct' => 2,
                'seen_total' => 6,
                'category_name' => 'Analytics Gender Category',
                'last_seen_at' => '2026-03-20 10:00:00',
                'updated_at' => (int) strtotime('2026-03-20 10:00:00 UTC') * 1000,
            ]),
        ]);
        $this->seedWordProgressRow($user_id, $word_b, $category_id, $wordset_id, [
            'gender_level' => 3,
            'gender_seen_total' => 8,
            'gender_last_seen_at' => '2026-03-21 09:15:00',
            'gender_state_json' => ll_tools_encode_gender_progress_state([
                'level' => 3,
                'confidence' => 7,
                'intro_seen' => true,
                'level1_passes' => 3,
                'level2_correct' => 4,
                'level3_correct' => 2,
                'seen_total' => 8,
                'category_name' => 'Analytics Gender Category',
                'last_seen_at' => '2026-03-21 09:15:00',
                'updated_at' => (int) strtotime('2026-03-21 09:15:00 UTC') * 1000,
            ]),
        ]);

        $analytics = ll_tools_build_user_study_analytics_payload($user_id, $wordset_id, [$category_id], 14);
        $gender_progress = (array) ($analytics['gender_progress'] ?? []);

        $this->assertTrue((bool) ($gender_progress['enabled'] ?? false));
        $this->assertSame(3, (int) ($gender_progress['tracked_word_total'] ?? 0));
        $this->assertSame(1, (int) ($gender_progress['not_started_words'] ?? 0));
        $this->assertSame(0, (int) ($gender_progress['level_1_words'] ?? 0));
        $this->assertSame(1, (int) ($gender_progress['level_2_words'] ?? 0));
        $this->assertSame(1, (int) ($gender_progress['level_3_words'] ?? 0));

        $categories = (array) ($gender_progress['categories'] ?? []);
        $this->assertCount(1, $categories);
        $category = (array) $categories[0];
        $this->assertSame($category_id, (int) ($category['id'] ?? 0));
        $this->assertSame(3, (int) ($category['tracked_word_total'] ?? 0));
        $this->assertSame(1, (int) ($category['not_started_words'] ?? 0));
        $this->assertSame(1, (int) ($category['level_2_words'] ?? 0));
        $this->assertSame(1, (int) ($category['level_3_words'] ?? 0));
        $this->assertSame('2026-03-21 09:15:00', (string) ($category['last_gender_seen_at'] ?? ''));
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function createGenderWordFixture(
        string $title,
        string $translation,
        string $part_of_speech,
        string $grammatical_gender,
        int $category_id = 0,
        int $wordset_id = 0
    ): array {
        if ($wordset_id <= 0) {
            $wordset = wp_insert_term('Gender Fixture Wordset ' . wp_generate_password(6, false), 'wordset');
            $this->assertFalse(is_wp_error($wordset));
            $this->assertIsArray($wordset);
            $wordset_id = (int) $wordset['term_id'];
            update_term_meta($wordset_id, 'll_wordset_has_gender', 1);
            update_term_meta($wordset_id, 'll_wordset_gender_options', ['Masculine', 'Feminine']);
        }

        if ($category_id <= 0) {
            $category = wp_insert_term('Persisted Gender Category ' . wp_generate_password(6, false), 'word-category');
            $this->assertFalse(is_wp_error($category));
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];
            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        }

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);
        if ($grammatical_gender !== '') {
            update_post_meta($word_id, 'll_grammatical_gender', $grammatical_gender);
        }

        $pos_slug = sanitize_title($part_of_speech) ?: 'noun';
        $pos_term_id = $this->ensurePartOfSpeechTerm($pos_slug, ucfirst($part_of_speech));
        wp_set_post_terms($word_id, [$pos_term_id], 'part_of_speech', false);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . sanitize_title($title) . '.mp3');

        return [(int) $word_id, $category_id, $wordset_id];
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
            'last_mode' => 'gender',
            'total_coverage' => 0,
            'coverage_learning' => 0,
            'coverage_practice' => 0,
            'coverage_listening' => 0,
            'coverage_gender' => 0,
            'coverage_self_check' => 0,
            'gender_level' => 0,
            'gender_seen_total' => 0,
            'gender_last_seen_at' => null,
            'gender_state_json' => '',
            'correct_clean' => 0,
            'correct_after_retry' => 0,
            'incorrect' => 0,
            'lapse_count' => 0,
            'stage' => 0,
            'due_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $inserted = $wpdb->replace($table, $data);
        $this->assertNotFalse($inserted);
    }
}
