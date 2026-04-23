<?php
declare(strict_types=1);

final class PromptCardQuizPayloadTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    public function test_prompt_card_rows_include_prompt_audio_support_rows_and_scoped_counts(): void
    {
        $fixture = $this->createPromptCardFixture();
        $term = get_term($fixture['effective_prompt_category_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        $config = ll_tools_get_category_quiz_config($term);
        $rows = ll_get_words_by_category($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);
        $count = ll_get_words_by_category_count($fixture['prompt_category_name'], 'audio', [$fixture['wordset_id']], $config);

        $prompt_rows = [];
        $support_rows = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['is_prompt_card'])) {
                $prompt_rows[(int) ($row['prompt_card_id'] ?? 0)] = $row;
                continue;
            }
            if (!empty($row['is_specific_wrong_answer_only'])) {
                $support_rows[(int) ($row['id'] ?? 0)] = $row;
            }
        }

        $this->assertCount(2, $prompt_rows);
        $this->assertCount(2, $support_rows);
        $this->assertSame(2, $count, 'Count helper should count prompt-card rounds, not support rows.');

        $horse_prompt_row = $prompt_rows[$fixture['horse_or_cow_card_id']] ?? [];
        $this->assertTrue((bool) ($horse_prompt_row['is_prompt_card'] ?? false));
        $this->assertSame($fixture['horse_or_cow_card_id'], (int) ($horse_prompt_row['id'] ?? 0));
        $this->assertSame($fixture['horse_id'], (int) ($horse_prompt_row['answer_word_id'] ?? 0));
        $this->assertSame($fixture['horse_id'], (int) ($horse_prompt_row['progress_word_id'] ?? 0));
        $this->assertTrue((bool) ($horse_prompt_row['track_answer_word_progress'] ?? false));
        $this->assertSame('https://example.com/prompt-horse-or-cow.mp3', (string) ($horse_prompt_row['prompt_audio'] ?? ''));
        $this->assertSame('Is this a horse or a cow?', (string) ($horse_prompt_row['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($horse_prompt_row['image'] ?? ''));

        $yes_no_prompt_row = $prompt_rows[$fixture['is_this_horse_card_id']] ?? [];
        $this->assertTrue((bool) ($yes_no_prompt_row['is_prompt_card'] ?? false));
        $this->assertSame($fixture['is_this_horse_card_id'], (int) ($yes_no_prompt_row['id'] ?? 0));
        $this->assertSame($fixture['yes_id'], (int) ($yes_no_prompt_row['answer_word_id'] ?? 0));
        $this->assertSame(0, (int) ($yes_no_prompt_row['progress_word_id'] ?? -1));
        $this->assertFalse((bool) ($yes_no_prompt_row['track_answer_word_progress'] ?? true));
        $this->assertSame('https://example.com/prompt-is-this-a-horse.mp3', (string) ($yes_no_prompt_row['prompt_audio'] ?? ''));
        $this->assertSame('Is this a horse?', (string) ($yes_no_prompt_row['prompt_label'] ?? ''));
        $this->assertNotEmpty((string) ($yes_no_prompt_row['image'] ?? ''));

        $cow_support_row = $support_rows[$fixture['cow_id']] ?? [];
        $this->assertTrue((bool) ($cow_support_row['is_specific_wrong_answer_only'] ?? false));
        $this->assertSame([$fixture['horse_or_cow_card_id']], $this->normalizeIds((array) ($cow_support_row['specific_wrong_answer_owner_ids'] ?? [])));

        $no_support_row = $support_rows[$fixture['no_id']] ?? [];
        $this->assertTrue((bool) ($no_support_row['is_specific_wrong_answer_only'] ?? false));
        $this->assertSame([$fixture['is_this_horse_card_id']], $this->normalizeIds((array) ($no_support_row['specific_wrong_answer_owner_ids'] ?? [])));
    }

    public function test_prompt_card_progress_batches_support_wordless_cards_and_scoped_reset(): void
    {
        $fixture = $this->createPromptCardFixture();
        $user_id = self::factory()->user->create(['role' => 'subscriber']);

        $stats = ll_tools_process_progress_events_batch($user_id, [
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => 0,
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'payload' => [
                    'prompt_card_id' => $fixture['is_this_horse_card_id'],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => 0,
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [
                    'prompt_card_id' => $fixture['is_this_horse_card_id'],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => $fixture['horse_id'],
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'payload' => [
                    'prompt_card_id' => $fixture['horse_or_cow_card_id'],
                ],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_outcome',
                'mode' => 'practice',
                'word_id' => $fixture['horse_id'],
                'category_id' => $fixture['effective_prompt_category_id'],
                'wordset_id' => $fixture['wordset_id'],
                'is_correct' => true,
                'had_wrong_before' => false,
                'payload' => [
                    'prompt_card_id' => $fixture['horse_or_cow_card_id'],
                ],
            ],
        ]);

        $this->assertSame(4, (int) ($stats['processed'] ?? 0));

        $prompt_progress = ll_tools_get_user_prompt_card_progress($user_id);
        $this->assertArrayHasKey($fixture['is_this_horse_card_id'], $prompt_progress);
        $this->assertArrayHasKey($fixture['horse_or_cow_card_id'], $prompt_progress);
        $this->assertSame(1, (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['exposure_total'] ?? 0));
        $this->assertSame(1, (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['correct_clean'] ?? 0));
        $this->assertSame($fixture['effective_prompt_category_id'], (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['category_id'] ?? 0));
        $this->assertSame($fixture['wordset_id'], (int) ($prompt_progress[$fixture['is_this_horse_card_id']]['wordset_id'] ?? 0));

        $word_rows = ll_tools_get_user_word_progress_rows($user_id, [$fixture['horse_id'], $fixture['yes_id']]);
        $this->assertArrayHasKey($fixture['horse_id'], $word_rows);
        $this->assertArrayNotHasKey($fixture['yes_id'], $word_rows, 'Yes/no prompt cards should not create answer-word progress rows when tracking is disabled.');
        $this->assertSame(1, (int) ($word_rows[$fixture['horse_id']]['correct_clean'] ?? 0));

        $summary = ll_tools_get_prompt_card_progress_summary_by_category($user_id, $fixture['wordset_id'], [$fixture['effective_prompt_category_id']]);
        $this->assertSame(2, (int) ($summary[$fixture['effective_prompt_category_id']]['total'] ?? 0));
        $this->assertSame(2, (int) ($summary[$fixture['effective_prompt_category_id']]['studied'] ?? 0));
        $this->assertSame(0, (int) ($summary[$fixture['effective_prompt_category_id']]['mastered'] ?? 0));

        $reset = ll_tools_reset_user_progress($user_id, [
            'wordset_id' => $fixture['wordset_id'],
            'category_ids' => [$fixture['effective_prompt_category_id']],
        ]);

        $this->assertSame(2, (int) ($reset['cleared_prompt_card_meta_entries'] ?? 0));
        $this->assertSame([], ll_tools_get_user_prompt_card_progress($user_id));
        $this->assertSame([], ll_tools_get_user_word_progress_rows($user_id, [$fixture['horse_id']]));
    }

    /**
     * @return array<string,int|string>
     */
    private function createPromptCardFixture(): array
    {
        $asset_category_id = $this->createCategory('Prompt Card Assets ' . wp_generate_password(5, false), 'image_audio', 'audio');
        $prompt_category_name = 'Prompt Card Questions ' . wp_generate_password(5, false);
        $prompt_category_id = $this->createCategory($prompt_category_name, 'image_audio', 'audio');
        $wordset_id = $this->createWordset('Prompt Card Wordset ' . wp_generate_password(5, false));
        $effective_prompt_category_id = $this->resolveEffectiveCategoryId($prompt_category_id, $wordset_id);

        $horse_id = $this->createWord($asset_category_id, 'Horse');
        $cow_id = $this->createWord($asset_category_id, 'Cow');
        $yes_id = $this->createWord($asset_category_id, 'Yes');
        $no_id = $this->createWord($asset_category_id, 'No');

        $this->addImage($horse_id, '-horse');
        $this->addAudio($horse_id, '-horse');
        $this->addAudio($cow_id, '-cow');
        $this->addAudio($yes_id, '-yes');
        $this->addAudio($no_id, '-no');

        $horse_or_cow_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'Horse Or Cow',
            'prompt_text' => 'Is this a horse or a cow?',
            'prompt_audio_url' => 'https://example.com/prompt-horse-or-cow.mp3',
            'prompt_image_word_id' => $horse_id,
            'correct_answer_word_id' => $horse_id,
            'wrong_answer_word_ids' => [$cow_id],
            'track_answer_word_progress' => true,
        ]);

        $is_this_horse_card_id = $this->createPromptCard($effective_prompt_category_id, $wordset_id, [
            'title' => 'Is This A Horse',
            'prompt_text' => 'Is this a horse?',
            'prompt_audio_url' => 'https://example.com/prompt-is-this-a-horse.mp3',
            'prompt_image_word_id' => $horse_id,
            'correct_answer_word_id' => $yes_id,
            'wrong_answer_word_ids' => [$no_id],
            'track_answer_word_progress' => false,
        ]);

        return [
            'asset_category_id' => $asset_category_id,
            'prompt_category_id' => $prompt_category_id,
            'effective_prompt_category_id' => $effective_prompt_category_id,
            'prompt_category_name' => $prompt_category_name,
            'wordset_id' => $wordset_id,
            'horse_id' => $horse_id,
            'cow_id' => $cow_id,
            'yes_id' => $yes_id,
            'no_id' => $no_id,
            'horse_or_cow_card_id' => $horse_or_cow_card_id,
            'is_this_horse_card_id' => $is_this_horse_card_id,
        ];
    }

    private function createCategory(string $name, string $prompt_type, string $option_type): int
    {
        $term = wp_insert_term($name, 'word-category');
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

    private function addAudio(int $word_id, string $suffix = ''): void
    {
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $suffix,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/prompt-card-' . $word_id . $suffix . '.mp3');
    }

    private function addImage(int $word_id, string $suffix = ''): void
    {
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Image ' . $suffix,
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/04/prompt-card-' . $word_id . $suffix . '.jpg');
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
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, (string) ($args['prompt_audio_url'] ?? ''));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, (int) ($args['prompt_image_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, (int) ($args['correct_answer_word_id'] ?? 0));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values(array_map('intval', (array) ($args['wrong_answer_word_ids'] ?? []))));
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, !empty($args['track_answer_word_progress']) ? 1 : 0);

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

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private function normalizeIds(array $values): array
    {
        $ids = array_values(array_filter(array_map('intval', $values), static function (int $id): bool {
            return $id > 0;
        }));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
