<?php
declare(strict_types=1);

final class WordOptionManualRecordingTypeBlockingTest extends LL_Tools_TestCase
{
    public function test_manual_pair_recording_type_exclusions_only_block_selected_prompt_recordings(): void
    {
        $fixture = $this->createFixture();

        ll_tools_update_word_option_rules(
            $fixture['wordset_id'],
            $fixture['category_id'],
            [],
            [[
                'word_ids' => [$fixture['word_a'], $fixture['word_b']],
                'unblocked_recording_types' => ['isolation'],
            ]]
        );

        $maps = ll_tools_get_word_option_maps($fixture['wordset_id'], $fixture['category_id']);
        $this->assertSame([], array_map('intval', (array) ($maps['blocked_map'][$fixture['word_a']] ?? [])));
        $this->assertContains(
            $fixture['word_b'],
            array_map('intval', (array) (($maps['blocked_map_by_recording_type'][$fixture['word_a']]['question'] ?? [])))
        );
        $this->assertNotContains(
            $fixture['word_b'],
            array_map('intval', (array) (($maps['blocked_map_by_recording_type'][$fixture['word_a']]['isolation'] ?? [])))
        );

        $rows = $this->indexRowsById(ll_get_words_by_category(
            (string) $fixture['category_id'],
            'text_title',
            [$fixture['wordset_id']],
            [
                'prompt_type' => 'audio',
                'option_type' => 'text_title',
            ]
        ));

        $this->assertSame([], array_map('intval', (array) ($rows[$fixture['word_a']]['option_blocked_ids'] ?? [])));
        $this->assertContains(
            $fixture['word_b'],
            array_map('intval', (array) (($rows[$fixture['word_a']]['option_blocked_ids_by_recording_type']['question'] ?? [])))
        );
        $this->assertNotContains(
            $fixture['word_b'],
            array_map('intval', (array) (($rows[$fixture['word_a']]['option_blocked_ids_by_recording_type']['isolation'] ?? [])))
        );

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $this->grantViewLlToolsCapability();
        wp_set_current_user($admin_id);

        $html = $this->renderWordOptionRulesAdminPage($fixture['wordset_id'], $fixture['category_id']);

        $question_checkbox = '/id="' . preg_quote(
            'll-word-option-pair-type-' . $fixture['word_a'] . '-' . $fixture['word_b'] . '-question',
            '/'
        ) . '"[^>]*checked=\'checked\'/';
        $isolation_checkbox = '/id="' . preg_quote(
            'll-word-option-pair-type-' . $fixture['word_a'] . '-' . $fixture['word_b'] . '-isolation',
            '/'
        ) . '"[^>]*checked=\'checked\'/';

        $this->assertStringContainsString('Blocked prompt recordings', $html);
        $this->assertStringContainsString('name="pair_recording_types[' . $fixture['pair_key'] . '][]"', $html);
        $this->assertMatchesRegularExpression($question_checkbox, $html);
        $this->assertDoesNotMatchRegularExpression($isolation_checkbox, $html);
    }

    private function createFixture(): array
    {
        $wordset_name = 'Word Option Manual Pair Wordset ' . wp_generate_password(6, false);
        $category_name = 'Word Option Manual Pair Category ' . wp_generate_password(6, false);

        $wordset = wp_insert_term($wordset_name, 'wordset');
        $this->assertIsArray($wordset);
        $category = wp_insert_term($category_name, 'word-category');
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];

        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $word_a = $this->createWord($wordset_id, $category_id, 'Clear One');
        $word_b = $this->createWord($wordset_id, $category_id, 'Clear Two');

        $this->createAudio($word_a, 'question', 'alpha question', 'https://audio.test/clear-one-question.mp3');
        $this->createAudio($word_a, 'isolation', 'alpha isolation', 'https://audio.test/clear-one-isolation.mp3');
        $this->createAudio($word_b, 'question', 'bravo question', 'https://audio.test/clear-two-question.mp3');
        $this->createAudio($word_b, 'isolation', 'bravo isolation', 'https://audio.test/clear-two-isolation.mp3');
        $this->publishWord($word_a);
        $this->publishWord($word_b);
        $category_id = $this->getAssignedCategoryId($word_a, $category_id);

        [$pair_a, $pair_b] = $this->normalizePair($word_a, $word_b);

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'category_name' => $category_name,
            'word_a' => $pair_a,
            'word_b' => $pair_b,
            'pair_key' => $pair_a . '|' . $pair_b,
        ];
    }

    private function createWord(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => $title,
        ]);

        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }

    private function createAudio(int $word_id, string $recording_type, string $recording_text, string $audio_url): int
    {
        if (!term_exists($recording_type, 'recording_type')) {
            wp_insert_term(ucwords(str_replace('-', ' ', $recording_type)), 'recording_type', [
                'slug' => $recording_type,
            ]);
        }

        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $recording_type,
        ]);

        update_post_meta($audio_id, 'audio_file_path', $audio_url);
        update_post_meta($audio_id, 'recording_text', $recording_text);
        wp_set_post_terms($audio_id, [$recording_type], 'recording_type', false);

        return (int) $audio_id;
    }

    private function publishWord(int $word_id): void
    {
        wp_update_post([
            'ID' => $word_id,
            'post_status' => 'publish',
        ]);
    }

    private function getAssignedCategoryId(int $word_id, int $fallback_category_id): int
    {
        $category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($category_ids) || empty($category_ids)) {
            return $fallback_category_id;
        }

        return (int) $category_ids[0];
    }

    private function renderWordOptionRulesAdminPage(int $wordset_id, int $category_id): string
    {
        $previous_get = $_GET;

        try {
            $_GET['wordset_id'] = $wordset_id;
            $_GET['category_id'] = $category_id;

            ob_start();
            ll_render_word_option_rules_admin_page();
            return (string) ob_get_clean();
        } finally {
            $_GET = $previous_get;
        }
    }

    private function indexRowsById(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $word_id = (int) ($row['id'] ?? 0);
            if ($word_id > 0) {
                $indexed[$word_id] = $row;
            }
        }

        return $indexed;
    }

    private function normalizePair(int $a, int $b): array
    {
        if ($a > $b) {
            return [$b, $a];
        }

        return [$a, $b];
    }

    private function grantViewLlToolsCapability(): void
    {
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
    }
}
