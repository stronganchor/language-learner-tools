<?php
declare(strict_types=1);

final class AnonymousWordsetLazyCardTokenTest extends LL_Tools_TestCase
{
    public function test_scoped_quiz_changes_rotate_only_the_affected_anonymous_lazy_token(): void
    {
        $quiz_min_filter = static function (): int {
            return 1;
        };
        $lazy_batch_filter = static function (): int {
            return 6;
        };

        add_filter('ll_tools_quiz_min_words', $quiz_min_filter);
        add_filter('ll_tools_wordset_page_lazy_card_batch_size', $lazy_batch_filter);

        try {
            wp_set_current_user(0);

            $fixture_a = $this->createWordsetFixture('A', 7);
            $fixture_b = $this->createWordsetFixture('B', 7);

            $initial_a = $this->renderLazyConfig((int) $fixture_a['wordset_id']);
            $initial_a_token = (string) $initial_a['token'];
            $stale_a_payload = ll_tools_wordset_page_get_lazy_cards_payload($initial_a_token);
            $this->assertIsArray($stale_a_payload);
            $this->assertSame(7, (int) ($stale_a_payload['total'] ?? 0));
            $this->assertCount(1, (array) ($stale_a_payload['cards'] ?? []));

            $initial_b = $this->renderLazyConfig((int) $fixture_b['wordset_id']);
            $initial_b_token = (string) $initial_b['token'];

            ll_tools_bump_category_cache_version(
                [(int) $fixture_b['category_ids'][0]],
                [(int) $fixture_b['wordset_id']],
                true
            );

            $after_unrelated_a = $this->renderLazyConfig((int) $fixture_a['wordset_id']);
            $after_scoped_b = $this->renderLazyConfig((int) $fixture_b['wordset_id']);
            $this->assertSame(
                $initial_a_token,
                (string) $after_unrelated_a['token'],
                'A known change in another wordset must not churn this wordset\'s shared lazy token.'
            );
            $this->assertNotSame(
                $initial_b_token,
                (string) $after_scoped_b['token'],
                'The affected wordset must rotate its shared lazy token even when its category count is unchanged.'
            );
            $this->assertSame(7, (int) $after_unrelated_a['total']);
            $this->assertSame(7, (int) $after_scoped_b['total']);

            $stale_category = $this->firstLazyCategoryData($stale_a_payload);
            $target_category_id = (int) ($stale_category['id'] ?? 0);
            $this->assertGreaterThan(0, $target_category_id);
            $this->assertContains($target_category_id, $fixture_a['category_ids']);
            $this->assertSame('text_title', (string) ($stale_category['prompt_type'] ?? ''));
            $this->assertSame('text_translation', (string) ($stale_category['option_type'] ?? ''));

            update_term_meta($target_category_id, 'll_quiz_prompt_type', 'text_translation');
            update_term_meta($target_category_id, 'll_quiz_option_type', 'text_title');
            ll_tools_bump_category_cache_version(
                [$target_category_id],
                [(int) $fixture_a['wordset_id']],
                true
            );

            $current_a = $this->renderLazyConfig((int) $fixture_a['wordset_id']);
            $current_a_token = (string) $current_a['token'];
            $this->assertNotSame(
                $initial_a_token,
                $current_a_token,
                'A scoped quiz-content change must rotate the anonymous lazy token without relying on a count change.'
            );
            $this->assertSame(7, (int) $initial_a['total']);
            $this->assertSame(7, (int) $current_a['total']);

            $current_a_payload = ll_tools_wordset_page_get_lazy_cards_payload($current_a_token);
            $this->assertIsArray($current_a_payload);
            $this->assertSame(
                $this->lazyPayloadIdentitySignature($stale_a_payload),
                $this->lazyPayloadIdentitySignature($current_a_payload),
                'The regression fixture must retain the same wordset, card IDs, and counts across the content-only change.'
            );
            $current_category = $this->findLazyCategoryData($current_a_payload, $target_category_id);
            $this->assertSame('text_translation', (string) ($current_category['prompt_type'] ?? ''));
            $this->assertSame('text_title', (string) ($current_category['option_type'] ?? ''));

            $stored_token = ll_tools_wordset_page_store_lazy_cards_payload(
                $stale_a_payload,
                0,
                $initial_a_token
            );
            $this->assertSame($initial_a_token, $stored_token);
            $this->assertSame(
                $current_a_payload,
                ll_tools_wordset_page_get_lazy_cards_payload($current_a_token),
                'A delayed stale payload must remain isolated under its old epoch token and cannot overwrite current content.'
            );
            $this->assertSame($stale_a_payload, ll_tools_wordset_page_get_lazy_cards_payload($initial_a_token));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $quiz_min_filter);
            remove_filter('ll_tools_wordset_page_lazy_card_batch_size', $lazy_batch_filter);
        }
    }

    /**
     * @return array{wordset_id:int,category_ids:int[]}
     */
    private function createWordsetFixture(string $label, int $category_count): array
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $created_wordset = wp_insert_term(
            'Anonymous Lazy Wordset ' . $label . ' ' . $suffix,
            'wordset',
            ['slug' => 'anonymous-lazy-wordset-' . strtolower($label) . '-' . $suffix]
        );
        $this->assertIsArray($created_wordset);
        $wordset_id = (int) $created_wordset['term_id'];
        $category_ids = [];

        ll_tools_begin_deferred_category_maintenance('anonymous-lazy-card-token-test');
        try {
            for ($index = 1; $index <= $category_count; $index++) {
                $category_name = sprintf('Anonymous Lazy %s Category %02d', $label, $index);
                $created_category = ll_tools_create_or_get_wordset_category($category_name, $wordset_id, [
                    'slug' => sprintf('anonymous-lazy-%s-%s-%02d', strtolower($label), $suffix, $index),
                ]);
                $this->assertNotWPError($created_category);
                $category_id = (int) $created_category;
                $this->assertGreaterThan(0, $category_id);
                $category_ids[] = $category_id;

                update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
                update_term_meta($category_id, 'll_quiz_option_type', 'text_translation');

                $lesson_id = wp_insert_post([
                    'post_type' => 'll_vocab_lesson',
                    'post_status' => 'publish',
                    'post_title' => $category_name . ' Lesson',
                    'post_name' => sprintf('anonymous-lazy-lesson-%s-%s-%02d', strtolower($label), $suffix, $index),
                    'meta_input' => [
                        LL_TOOLS_VOCAB_LESSON_WORDSET_META => (string) $wordset_id,
                        LL_TOOLS_VOCAB_LESSON_CATEGORY_META => (string) $category_id,
                    ],
                ], true);
                $this->assertIsInt($lesson_id);
                $this->assertGreaterThan(0, $lesson_id);
            }

            $word_id = wp_insert_post([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Anonymous Lazy Shared Word ' . $label . ' ' . $suffix,
                'post_name' => 'anonymous-lazy-shared-word-' . strtolower($label) . '-' . $suffix,
                'meta_input' => [
                    'word_translation' => 'Shared translation',
                ],
            ], true);
            $this->assertIsInt($word_id);
            $this->assertGreaterThan(0, $word_id);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, $category_ids, 'word-category', false);
        } finally {
            ll_tools_end_deferred_category_maintenance(false);
        }

        return [
            'wordset_id' => $wordset_id,
            'category_ids' => $category_ids,
        ];
    }

    /** @return array{token:string,total:int} */
    private function renderLazyConfig(int $wordset_id): array
    {
        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset->slug);
        set_query_var('ll_wordset_view', '');
        if (function_exists('ll_tools_flashcard_widget_reset_render_guard')) {
            ll_tools_flashcard_widget_reset_render_guard();
        }

        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
        preg_match('/var llWordsetPageData = (\{.*\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);
        $decoded = json_decode((string) $matches[1], true);
        $this->assertIsArray($decoded);
        $lazy = isset($decoded['lazyCards']) && is_array($decoded['lazyCards'])
            ? $decoded['lazyCards']
            : [];
        $this->assertTrue((bool) ($lazy['enabled'] ?? false));
        $this->assertMatchesRegularExpression('/^shared_[a-f0-9]{32}$/', (string) ($lazy['token'] ?? ''));
        $this->assertSame(6, (int) ($lazy['initialCount'] ?? 0));
        $this->assertSame(1, (int) ($lazy['remaining'] ?? 0));

        return [
            'token' => (string) $lazy['token'],
            'total' => (int) ($lazy['total'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function firstLazyCategoryData(array $payload): array
    {
        foreach ((array) ($payload['cards'] ?? []) as $card) {
            if (is_array($card) && ($card['type'] ?? '') === 'category' && is_array($card['data'] ?? null)) {
                return $card['data'];
            }
        }

        $this->fail('Expected the lazy payload to contain a category card.');
    }

    /** @return array<string,mixed> */
    private function findLazyCategoryData(array $payload, int $category_id): array
    {
        foreach ((array) ($payload['cards'] ?? []) as $card) {
            if (
                is_array($card)
                && ($card['type'] ?? '') === 'category'
                && is_array($card['data'] ?? null)
                && (int) ($card['data']['id'] ?? 0) === $category_id
            ) {
                return $card['data'];
            }
        }

        $this->fail('Expected the target category to remain in the lazy payload.');
    }

    /** @return array<string,mixed> */
    private function lazyPayloadIdentitySignature(array $payload): array
    {
        $cards = [];
        foreach ((array) ($payload['cards'] ?? []) as $card) {
            if (!is_array($card)) {
                continue;
            }
            $data = is_array($card['data'] ?? null) ? $card['data'] : [];
            $cards[] = [
                'type' => (string) ($card['type'] ?? ''),
                'id' => (int) ($data['id'] ?? 0),
                'count' => (int) ($data['count'] ?? 0),
            ];
        }

        return [
            'total' => (int) ($payload['total'] ?? 0),
            'base_offset' => (int) ($payload['base_offset'] ?? 0),
            'batch_size' => (int) ($payload['batch_size'] ?? 0),
            'cards' => $cards,
        ];
    }
}
