<?php
declare(strict_types=1);

final class PromptCardCacheBoundaryTest extends LL_Tools_TestCase
{
    public function test_category_context_id_query_bypasses_wp_query_result_cache(): void
    {
        $fixture = $this->createPromptCardFixture('ID query');
        $capturedCacheResults = null;
        $capture = static function (WP_Query $query) use (&$capturedCacheResults): void {
            if ((string) $query->get('post_type') !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
                return;
            }

            $capturedCacheResults = $query->get('cache_results');
        };

        add_action('pre_get_posts', $capture);
        try {
            $complete = false;
            $ids = ll_tools_get_prompt_card_ids_for_category_context(
                [
                    'query_field' => 'term_id',
                    'query_terms' => [$fixture['category_id']],
                ],
                [$fixture['wordset_id']],
                -1,
                $complete
            );
        } finally {
            remove_action('pre_get_posts', $capture);
        }

        $this->assertTrue($complete);
        $this->assertSame([$fixture['prompt_card_id']], array_values(array_map('intval', $ids)));
        $this->assertFalse($capturedCacheResults, 'Prompt-card ID discovery must not reuse a failed WP_Query result cache.');
    }

    public function test_reference_hydration_reports_attachment_post_and_meta_failures(): void
    {
        global $wpdb;

        $fixture = $this->createPromptCardFixture('Audio hydration');
        $attachmentId = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Prompt audio fixture',
            'post_mime_type' => 'audio/mpeg',
            'guid' => 'https://example.com/prompt-audio-fixture.mp3',
        ]);
        update_post_meta($attachmentId, '_wp_attached_file', '2026/07/prompt-audio-fixture.mp3');
        update_post_meta(
            $fixture['prompt_card_id'],
            LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY,
            $attachmentId
        );

        clean_post_cache($attachmentId);
        $postReadFailed = false;
        $breakAttachmentPostRead = static function (string $sql) use ($wpdb, $attachmentId, &$postReadFailed): string {
            if (
                !$postReadFailed
                && preg_match(
                    '/FROM\s+`?' . preg_quote($wpdb->posts, '/') . '`?\s+WHERE\s+ID\s*=\s*' . $attachmentId . '\s+LIMIT\s+1/i',
                    $sql
                ) === 1
            ) {
                $postReadFailed = true;
                return "SELECT * FROM {$wpdb->posts}_ll_tools_prompt_card_missing WHERE ID = {$attachmentId}";
            }

            return $sql;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $breakAttachmentPostRead);
        try {
            $postComplete = true;
            $postFailureRows = ll_tools_get_prompt_card_reference_data_for_ids(
                [$fixture['prompt_card_id']],
                true,
                $postComplete
            );
        } finally {
            remove_filter('query', $breakAttachmentPostRead);
            $wpdb->suppress_errors($previousSuppress);
        }

        $this->assertTrue($postReadFailed, 'The fixture must interrupt attachment post hydration.');
        $this->assertFalse($postComplete);
        $this->assertCount(1, $postFailureRows);
        $this->assertSame('', (string) ($postFailureRows[0]['prompt_audio_url'] ?? ''));

        clean_post_cache($attachmentId);
        $this->assertInstanceOf(WP_Post::class, get_post($attachmentId));
        wp_cache_delete($attachmentId, 'post_meta');
        $metaReadFailed = false;
        $breakAttachmentMetaRead = static function (string $sql) use ($wpdb, $attachmentId, &$metaReadFailed): string {
            if (
                !$metaReadFailed
                && stripos($sql, 'FROM ' . $wpdb->postmeta) !== false
                && preg_match('/post_id\s+IN\s*\(\s*' . $attachmentId . '\s*\)/i', $sql) === 1
            ) {
                $metaReadFailed = true;
                return "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}_ll_tools_prompt_card_missing";
            }

            return $sql;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $breakAttachmentMetaRead);
        try {
            $metaComplete = true;
            $metaFailureRows = ll_tools_get_prompt_card_reference_data_for_ids(
                [$fixture['prompt_card_id']],
                true,
                $metaComplete
            );
        } finally {
            remove_filter('query', $breakAttachmentMetaRead);
            $wpdb->suppress_errors($previousSuppress);
        }

        $this->assertTrue($metaReadFailed, 'The fixture must interrupt attachment URL metadata hydration.');
        $this->assertFalse($metaComplete);
        $this->assertCount(1, $metaFailureRows);

        wp_cache_delete($attachmentId, 'post_meta');
        $retryComplete = false;
        $retryRows = ll_tools_get_prompt_card_reference_data_for_ids(
            [$fixture['prompt_card_id']],
            true,
            $retryComplete
        );
        $this->assertTrue($retryComplete, 'The same reference request must become complete once attachment reads recover.');
        $this->assertCount(1, $retryRows);
        $this->assertNotSame('', (string) ($retryRows[0]['prompt_audio_url'] ?? ''));
    }

    public function test_term_scope_reads_fail_incomplete_and_retry_without_narrowing_invalidation(): void
    {
        $fixture = $this->createPromptCardFixture('Term scope');

        [$categoryFailure, $categoryInjected] = $this->runWithBrokenTermRead(
            'word-category',
            $fixture['prompt_card_id'],
            static function () use ($fixture): array {
                return ll_tools_prompt_card_get_word_category_scope($fixture['prompt_card_id']);
            }
        );
        $this->assertTrue($categoryInjected);
        $this->assertFalse((bool) ($categoryFailure['complete'] ?? true));
        $this->assertSame([], (array) ($categoryFailure['category_ids'] ?? []));

        [$wordsetFailure, $wordsetInjected] = $this->runWithBrokenTermRead(
            'wordset',
            $fixture['prompt_card_id'],
            static function () use ($fixture): array {
                return ll_tools_prompt_card_get_direct_wordset_scope($fixture['prompt_card_id']);
            }
        );
        $this->assertTrue($wordsetInjected);
        $this->assertFalse((bool) ($wordsetFailure['complete'] ?? true));
        $this->assertSame([], (array) ($wordsetFailure['wordset_ids'] ?? []));

        [$referenceFailure, $referenceInjected] = $this->runWithBrokenTermRead(
            'word-category',
            $fixture['prompt_card_id'],
            static function () use ($fixture): array {
                return ll_tools_prompt_card_get_scope_for_word_references([$fixture['word_id']]);
            }
        );
        $this->assertTrue($referenceInjected);
        $this->assertFalse((bool) ($referenceFailure['complete'] ?? true));
        $this->assertContains($fixture['prompt_card_id'], (array) ($referenceFailure['prompt_card_ids'] ?? []));
        $this->assertContains($fixture['wordset_id'], (array) ($referenceFailure['wordset_ids'] ?? []));

        $categoryRetry = ll_tools_prompt_card_get_word_category_scope($fixture['prompt_card_id']);
        $wordsetRetry = ll_tools_prompt_card_get_direct_wordset_scope($fixture['prompt_card_id']);
        $referenceRetry = ll_tools_prompt_card_get_scope_for_word_references([$fixture['word_id']]);
        $this->assertTrue((bool) ($categoryRetry['complete'] ?? false));
        $this->assertSame([$fixture['category_id']], (array) ($categoryRetry['category_ids'] ?? []));
        $this->assertTrue((bool) ($wordsetRetry['complete'] ?? false));
        $this->assertSame([$fixture['wordset_id']], (array) ($wordsetRetry['wordset_ids'] ?? []));
        $this->assertTrue((bool) ($referenceRetry['complete'] ?? false));
        $this->assertContains($fixture['category_id'], (array) ($referenceRetry['category_ids'] ?? []));
        $this->assertContains($fixture['wordset_id'], (array) ($referenceRetry['wordset_ids'] ?? []));
    }

    public function test_recorder_prompt_page_does_not_publish_a_failed_post_query_as_complete(): void
    {
        global $wpdb;

        $fixture = $this->createPromptCardFixture('Recorder query');
        update_post_meta(
            $fixture['prompt_card_id'],
            LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY,
            'Record this bounded prompt.'
        );
        $category = get_term($fixture['category_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
        $categorySlug = (string) $category->slug;

        $queryFailed = false;
        $breakPromptCardQuery = static function (string $sql) use ($wpdb, &$queryFailed): string {
            if (
                !$queryFailed
                && stripos($sql, 'FROM ' . $wpdb->posts) !== false
                && strpos($sql, "'" . LL_TOOLS_PROMPT_CARD_POST_TYPE . "'") !== false
            ) {
                $queryFailed = true;
                return "SELECT ID FROM {$wpdb->posts}_ll_tools_recorder_prompt_missing";
            }

            return $sql;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $breakPromptCardQuery);
        try {
            $failedPage = ll_tools_get_prompt_cards_needing_audio_page(
                $categorySlug,
                [$fixture['wordset_id']],
                '',
                '',
                false,
                0,
                ['limit' => 1]
            );
        } finally {
            remove_filter('query', $breakPromptCardQuery);
            $wpdb->suppress_errors($previousSuppress);
        }

        $this->assertTrue($queryFailed, 'The fixture must interrupt the bounded prompt-card post query.');
        $this->assertFalse((bool) ($failedPage['complete'] ?? true));
        $this->assertTrue((bool) ($failedPage['truncated'] ?? false));
        $this->assertSame([], (array) ($failedPage['items'] ?? []));
        $this->assertSame(0, (int) ($failedPage['cursor']['raw_offset'] ?? -1));
        $this->assertSame(0, (int) ($failedPage['cursor']['eligible_seen'] ?? -1));

        $retryPage = ll_tools_get_prompt_cards_needing_audio_page(
            $categorySlug,
            [$fixture['wordset_id']],
            '',
            '',
            false,
            0,
            ['limit' => 1]
        );
        $this->assertTrue((bool) ($retryPage['complete'] ?? false));
        $this->assertSame(
            [$fixture['prompt_card_id']],
            array_values(array_map(static function (array $item): int {
                return (int) ($item['prompt_card_id'] ?? 0);
            }, (array) ($retryPage['items'] ?? [])))
        );
    }

    public function test_recorder_prompt_page_does_not_advance_past_failed_term_priming(): void
    {
        $fixture = $this->createPromptCardFixture('Recorder term cache');
        $category = get_term($fixture['category_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
        clean_object_term_cache($fixture['prompt_card_id'], LL_TOOLS_PROMPT_CARD_POST_TYPE);

        [$failedPage, $termReadFailed] = $this->runWithBrokenTermRead(
            'word-category',
            $fixture['prompt_card_id'],
            static function () use ($fixture, $category): array {
                return ll_tools_get_prompt_cards_needing_audio_page(
                    (string) $category->slug,
                    [$fixture['wordset_id']],
                    '',
                    '',
                    false,
                    0,
                    ['limit' => 1]
                );
            }
        );

        $this->assertTrue($termReadFailed, 'The fixture must interrupt prompt-card term-cache priming.');
        $this->assertFalse((bool) ($failedPage['complete'] ?? true));
        $this->assertTrue((bool) ($failedPage['truncated'] ?? false));
        $this->assertSame([], (array) ($failedPage['items'] ?? []));
        $this->assertSame(0, (int) ($failedPage['cursor']['raw_offset'] ?? -1));
        $this->assertSame(0, (int) ($failedPage['cursor']['eligible_seen'] ?? -1));

        clean_object_term_cache($fixture['prompt_card_id'], LL_TOOLS_PROMPT_CARD_POST_TYPE);
        $retryPage = ll_tools_get_prompt_cards_needing_audio_page(
            (string) $category->slug,
            [$fixture['wordset_id']],
            '',
            '',
            false,
            0,
            ['limit' => 1]
        );
        $this->assertTrue((bool) ($retryPage['complete'] ?? false));
        $this->assertSame($fixture['prompt_card_id'], (int) ($retryPage['items'][0]['prompt_card_id'] ?? 0));
    }

    /** @return array{wordset_id:int,category_id:int,word_id:int,prompt_card_id:int} */
    private function createPromptCardFixture(string $label): array
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $wordset = wp_insert_term(
            'Prompt Boundary Wordset ' . $label . ' ' . $suffix,
            'wordset',
            ['slug' => 'prompt-boundary-wordset-' . $suffix]
        );
        $this->assertIsArray($wordset);
        $category = wp_insert_term(
            'Prompt Boundary Category ' . $label . ' ' . $suffix,
            'word-category',
            ['slug' => 'prompt-boundary-category-' . $suffix]
        );
        $this->assertIsArray($category);

        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Prompt boundary answer ' . $suffix,
        ]);
        $promptCardId = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Prompt boundary card ' . $suffix,
        ]);
        update_post_meta(
            $promptCardId,
            LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY,
            $wordId
        );
        wp_set_object_terms($promptCardId, [(int) $category['term_id']], 'word-category', false);
        wp_set_object_terms($promptCardId, [(int) $wordset['term_id']], 'wordset', false);

        return [
            'wordset_id' => (int) $wordset['term_id'],
            'category_id' => (int) $category['term_id'],
            'word_id' => $wordId,
            'prompt_card_id' => $promptCardId,
        ];
    }

    /** @return array{0:array,1:bool} */
    private function runWithBrokenTermRead(string $taxonomy, int $objectId, callable $callback): array
    {
        global $wpdb;

        $injected = false;
        $breakTermRead = static function (string $sql) use ($wpdb, $taxonomy, $objectId, &$injected): string {
            if (
                !$injected
                && stripos($sql, $wpdb->term_relationships) !== false
                && strpos($sql, "'{$taxonomy}'") !== false
                && preg_match('/object_id\s+IN\s*\(\s*' . $objectId . '\s*\)/i', $sql) === 1
            ) {
                $injected = true;
                return "SELECT term_id FROM {$wpdb->terms}_ll_tools_prompt_card_missing";
            }

            return $sql;
        };

        $previousSuppress = $wpdb->suppress_errors(true);
        add_filter('query', $breakTermRead);
        try {
            $result = $callback();
        } finally {
            remove_filter('query', $breakTermRead);
            $wpdb->suppress_errors($previousSuppress);
        }

        return [(array) $result, $injected];
    }
}
