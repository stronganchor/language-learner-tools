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

    public function test_sampled_image_options_carry_exact_image_keys_for_an_absent_blocked_word(): void
    {
        $fixture = $this->createFixture();
        $wordset_id = $fixture['wordset_id'];
        $category_id = $fixture['category_id'];
        $target = $fixture['word_a'];
        $blocked = $fixture['word_b'];
        $uploads = self::factory()->attachment;
        $image_dir = ABSPATH . WPINC . '/images/';
        $target_image = $uploads->create_upload_object($image_dir . 'w-logo-blue.png');
        $blocked_image = $uploads->create_upload_object($image_dir . 'rss.png');
        $copy_image = $uploads->create_upload_object($image_dir . 'rss.png');
        $other_image = $uploads->create_upload_object($image_dir . 'arrow-pointer-blue.png');

        $this->assertNotSame($blocked_image, $copy_image);
        set_post_thumbnail($target, $target_image);
        set_post_thumbnail($blocked, $blocked_image);
        $candidates = [];
        foreach (['Shared' => $blocked_image, 'Copy' => $copy_image, 'Other' => $other_image] as $title => $image_id) {
            $word = $this->createWord($wordset_id, $category_id, $title);
            $this->createAudio($word, 'isolation', $title, 'https://audio.test/' . $title . '.mp3');
            $this->publishWord($word);
            set_post_thumbnail($word, $image_id);
            $candidates[$title] = $word;
        }

        ll_tools_update_word_option_rules($wordset_id, $category_id, [], [[
            'word_ids' => [$target, $blocked],
            'unblocked_recording_types' => ['question', 'introduction'],
        ]]);

        $config = [
            'prompt_type' => 'audio',
            '__candidate_word_ids' => array_merge([$target], array_values($candidates)),
            '__skip_image_similarity_pairs' => true,
        ];
        $rows = $this->indexRowsById(ll_get_words_by_category(
            (string) $category_id, 'image', [$wordset_id], $config + ['option_type' => 'image']
        ));
        $this->assertArrayNotHasKey($blocked, $rows);
        $this->assertArrayHasKey($target, $rows);
        $target_row = $rows[$target];
        $this->assertContains($blocked, $target_row['option_blocked_ids_by_recording_type']['isolation']);
        $this->assertNotContains($blocked, $target_row['option_blocked_ids_by_recording_type']['question'] ?? []);
        $this->assertNotContains($blocked, $target_row['option_blocked_ids']);
        $this->assertEmpty($target_row['option_blocked_image_keys'][''] ?? []);
        $this->assertEmpty($target_row['option_blocked_image_keys']['question'] ?? []);
        $this->assertEmpty($target_row['option_blocked_image_keys']['introduction'] ?? []);
        $blocked_keys = $target_row['option_blocked_image_keys']['isolation'];
        $digest = 'sha256:' . hash_file('sha256', $image_dir . 'rss.png');
        $this->assertEqualsCanonicalizing(['attachment:' . $blocked_image, $digest], $blocked_keys);
        $this->assertEqualsCanonicalizing($blocked_keys, $rows[$candidates['Shared']]['option_image_keys']);
        $this->assertEqualsCanonicalizing(
            ['attachment:' . $copy_image, $digest],
            $rows[$candidates['Copy']]['option_image_keys']
        );
        $this->assertSame([], array_intersect($blocked_keys, $rows[$candidates['Other']]['option_image_keys']));

        $text_rows = $this->indexRowsById(ll_get_words_by_category(
            (string) $category_id, 'text_title', [$wordset_id], $config + ['option_type' => 'text_title']
        ));
        $this->assertArrayHasKey($target, $text_rows);
        foreach ($text_rows as $row) {
            $this->assertArrayNotHasKey('option_image_keys', $row);
            $this->assertArrayNotHasKey('option_blocked_image_keys', $row);
        }
    }

    public function test_recording_option_blocks_follow_only_the_configured_audio(): void
    {
        $fixture = $this->createFixture();
        $target = $fixture['word_a'];
        $blocked = $fixture['word_b'];
        $recording = $this->createAudio($target, 'isolation', 'ambiguous', 'https://audio.test/ambiguous.mp3');
        $this->createAudio($target, 'isolation', 'explicit', 'https://audio.test/explicit.mp3');
        $image_file = ABSPATH . WPINC . '/images/rss.png';
        $blocked_image = self::factory()->attachment->create_upload_object($image_file);
        set_post_thumbnail($blocked, $blocked_image);
        set_post_thumbnail($target, self::factory()->attachment->create_upload_object(ABSPATH . WPINC . '/images/w-logo-blue.png'));

        $config = [
            'prompt_type' => 'audio',
            'option_type' => 'image',
            '__candidate_word_ids' => [$target],
            '__skip_image_similarity_pairs' => true,
        ];
        $get_rows = function () use ($fixture, $config): array {
            return $this->indexRowsById(ll_get_words_by_category(
                (string) $fixture['category_id'], 'image', [$fixture['wordset_id']], $config
            ));
        };

        $before = array_column($get_rows()[$target]['audio_files'], null, 'url');
        $this->assertArrayNotHasKey('option_blocked_ids', $before['https://audio.test/ambiguous.mp3']);
        $this->completeLlToolsSimulatedRequest();

        update_post_meta($recording, '_ll_quiz_blocked_option_word_ids', [$target, $blocked, $blocked, 0]);
        $this->completeLlToolsSimulatedRequest();
        $rows = $get_rows();
        $this->assertArrayNotHasKey($blocked, $rows);
        $this->assertSame([], $rows[$target]['option_blocked_ids']);
        $audio = array_column($rows[$target]['audio_files'], null, 'url');
        $this->assertSame([$blocked], $audio['https://audio.test/ambiguous.mp3']['option_blocked_ids']);
        $this->assertEqualsCanonicalizing(
            ['attachment:' . $blocked_image, 'sha256:' . hash_file('sha256', $image_file)],
            $audio['https://audio.test/ambiguous.mp3']['option_blocked_image_keys']
        );
        $this->assertArrayNotHasKey('option_blocked_ids', $audio['https://audio.test/explicit.mp3']);
        $this->assertArrayNotHasKey('option_blocked_image_keys', $audio['https://audio.test/explicit.mp3']);

        delete_post_meta($recording, '_ll_quiz_blocked_option_word_ids');
        $this->completeLlToolsSimulatedRequest();
        $after = array_column($get_rows()[$target]['audio_files'], null, 'url');
        $this->assertArrayNotHasKey('option_blocked_ids', $after['https://audio.test/ambiguous.mp3']);
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
