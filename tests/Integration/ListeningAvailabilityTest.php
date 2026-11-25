<?php
declare(strict_types=1);

final class ListeningAvailabilityTest extends LL_Tools_TestCase
{
    private function scope(string $suffix): array
    {
        $category = wp_insert_term('Audio availability ' . $suffix, 'word-category');
        $wordset = wp_insert_term('Audio scope ' . $suffix, 'wordset');
        $this->assertIsArray($category);
        $this->assertIsArray($wordset);
        $category_id = (int) $category['term_id'];
        update_term_meta($category_id, 'll_wordset_owner_id', (int) $wordset['term_id']);
        update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');
        return [$category_id, (int) $wordset['term_id']];
    }

    private function word(int $category_id, int $wordset_id): int
    {
        $id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Prompt']);
        wp_set_post_terms($id, [$category_id], 'word-category');
        if ($wordset_id > 0) { wp_set_post_terms($id, [$wordset_id], 'wordset'); }
        update_post_meta($id, 'word_translation', 'Answer');
        return $id;
    }

    public function test_listen_requires_a_published_nonblank_recording_and_refreshes_after_mutations(): void
    {
        wp_set_current_user(0);
        [$category, $wordset] = $this->scope('recordings');
        $word = $this->word($category, $wordset);
        $audio = self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'draft', 'post_parent' => $word]);
        update_post_meta($audio, 'audio_file_path', '/recording.mp3');
        $complete = false;
        $this->assertFalse(ll_tools_category_has_playable_audio($category, [$wordset], $complete));
        $this->assertTrue($complete);
        wp_update_post(['ID' => $audio, 'post_status' => 'publish']);
        $this->assertTrue(ll_tools_category_has_playable_audio($category, [$wordset]));
        update_post_meta($audio, 'audio_file_path', " \t\n ");
        $this->assertFalse(ll_tools_category_has_playable_audio($category, [$wordset]));
        update_post_meta($audio, 'audio_file_path', '/recording.mp3');
        wp_update_post(['ID' => $word, 'post_password' => 'secret']);
        $this->assertFalse(ll_tools_category_has_playable_audio($category, [$wordset]));
        wp_update_post(['ID' => $word, 'post_password' => '']);
        $this->assertTrue(ll_tools_category_has_playable_audio($category, [$wordset]));
        wp_delete_post($audio, true);
        $this->assertFalse(ll_tools_category_has_playable_audio($category, [$wordset]));
        $config = ll_tools_resolve_effective_category_quiz_config($category, 1, [$wordset]);
        $this->assertFalse($config['listening_supported']);
        $this->assertSame(1, $config['word_count'], 'Text-only Practice remains available.');
    }

    public function test_audio_availability_is_scoped_and_does_not_reveal_private_wordsets(): void
    {
        wp_set_current_user(0);
        [$category, $wordset] = $this->scope('private');
        [, $other_wordset] = $this->scope('other');
        $word = $this->word($category, $wordset);
        $audio = self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $word]);
        update_post_meta($audio, 'audio_file_path', '/recording.mp3');
        $this->assertTrue(ll_tools_category_has_playable_audio($category, [$wordset]));
        $this->assertFalse(ll_tools_category_has_playable_audio($category, [$other_wordset]));
        $this->assertFalse(ll_tools_category_has_playable_audio($category, []));
        update_term_meta($wordset, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        $this->assertFalse(ll_tools_category_has_playable_audio($category, [$wordset]));
    }

    public function test_wordset_category_cache_rebuilds_pre_listen_payloads_and_invalidates_after_audio_changes(): void
    {
        wp_set_current_user(0);
        [$category, $wordset] = $this->scope('warm-cache');
        $words = [];
        for ($index = 0; $index < 5; $index++) {
            $words[] = $this->word($category, $wordset);
        }
        $audio = self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $words[0]]);
        update_post_meta($audio, 'audio_file_path', '/recording.mp3');
        $old_key = ll_tools_wordset_page_build_cache_key('categories', [
            'schema' => 9,
            'wordset_id' => $wordset,
            'preview_limit' => 2,
            'preview_mode' => 'deferred',
            'locale' => sanitize_key((string) get_locale()),
            'translation_enabled' => ll_tools_is_category_translation_enabled([$wordset]) ? '1' : '0',
            'translation_target' => sanitize_key((string) ll_tools_get_wordset_translation_language([$wordset])),
            'category_epoch' => max(1, (int) ll_tools_get_category_cache_epoch()),
            'wordset_epoch' => max(1, (int) ll_tools_get_wordset_cache_epoch()),
            'quiz_content_epoch' => ll_tools_get_quiz_content_cache_epoch([$wordset]),
        ]);
        // This is the pre-upgrade payload shape: no listening_supported field.
        wp_cache_set($old_key, [['id' => $category, 'name' => 'Old cached category']], 'll_tools', 30 * MINUTE_IN_SECONDS);
        $read_category = static function () use ($category, $wordset): array {
            $rows = ll_tools_get_wordset_page_categories($wordset, 2, ['defer_previews' => true]);
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $category) { return $row; }
            }
            return [];
        };
        $rebuilt = $read_category();
        $this->assertNotEmpty($rebuilt);
        $this->assertNotSame('Old cached category', $rebuilt['name']);
        $this->assertTrue($rebuilt['listening_supported']);
        update_post_meta($audio, 'audio_file_path', '');
        $this->assertFalse($read_category()['listening_supported']);
        update_post_meta($audio, 'audio_file_path', '/new-recording.mp3');
        $this->assertTrue($read_category()['listening_supported']);
        wp_delete_post($audio, true);
        $this->assertFalse($read_category()['listening_supported']);
    }

    public function test_prompt_card_audio_counts_without_hydrating_words(): void
    {
        wp_set_current_user(0);
        [$category, $wordset] = $this->scope('prompt-card');
        $card = self::factory()->post->create(['post_type' => 'll_prompt_card', 'post_status' => 'publish']);
        wp_set_post_terms($card, [$category], 'word-category');
        wp_set_post_terms($card, [$wordset], 'wordset');
        update_post_meta($card, '_ll_prompt_card_prompt_audio_url', 'https://example.test/question.mp3');
        global $wpdb;
        $queries = [];
        $capture = static function (string $query) use (&$queries): string { $queries[] = $query; return $query; };
        add_filter('query', $capture);
        try {
            $this->assertTrue(ll_tools_category_has_playable_audio($category, [$wordset]));
        } finally {
            remove_filter('query', $capture);
        }
        $existence = array_values(array_filter($queries, static function (string $query): bool { return strpos($query, 'audio_file_path') !== false; }));
        $this->assertCount(1, $existence);
        $this->assertSame([], array_values(array_filter($queries, static function (string $query): bool { return (bool) preg_match('/^\s*(INSERT|UPDATE|DELETE)\b/i', $query); })), 'Availability reads must not create isolated copies or mutate content.');
        $this->assertStringContainsString('SELECT 1 FROM', $existence[0]);
        $this->assertStringContainsString('LIMIT 1', $existence[0]);
    }
}
