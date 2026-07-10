<?php
declare(strict_types=1);

final class CategoryLineupManagerTest extends LL_Tools_TestCase
{
    public function test_settings_renders_read_stored_config_without_hydrating_lineup_words(): void
    {
        $category_id = $this->createCategory();
        $word_ids = [
            $this->createWord($category_id, 'Render Alpha'),
            $this->createWord($category_id, 'Render Beta'),
            $this->createWord($category_id, 'Render Gamma'),
        ];
        update_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, array_reverse($word_ids));
        update_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, 'rtl');

        $word_queries = [];
        $capture = $this->captureWordQueries($word_queries);
        try {
            $panel_data = ll_tools_get_vocab_lesson_category_settings_panel_data($category_id);

            ob_start();
            ll_tools_edit_category_lineup_field(get_term($category_id, 'word-category'));
            $taxonomy_html = (string) ob_get_clean();
        } finally {
            remove_action('pre_get_posts', $capture, 999);
        }

        $lineup_queries = array_values(array_filter($word_queries, static function (array $query_vars): bool {
            return ($query_vars['post_status'] ?? null) === 'any';
        }));
        $this->assertSame([], $lineup_queries);
        $this->assertSame('rtl', (string) ($panel_data['lineup']['direction'] ?? ''));
        $this->assertSame(array_reverse($word_ids), array_map('intval', (array) ($panel_data['lineup']['word_ids'] ?? [])));
        $this->assertSame([], $panel_data['lineup_items']);
        $this->assertStringContainsString('data-ll-category-lineup-manager', $taxonomy_html);
        $this->assertStringNotContainsString('Render Alpha', $taxonomy_html);
        $this->assertStringNotContainsString('name="ll_category_lineup_word_ids"', $taxonomy_html);
    }

    public function test_candidate_and_sequence_pages_are_capped_searchable_and_ordered(): void
    {
        $category_id = $this->createCategory();
        $word_ids = [];
        for ($index = 1; $index <= 8; $index++) {
            $word_ids[] = $this->createWord($category_id, sprintf('Paged Word %02d', $index));
        }
        $needle_id = $this->createWord($category_id, 'Paged Needle');
        $word_ids[] = $needle_id;
        $stored_order = array_reverse($word_ids);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, $stored_order);

        $page_size_filter = static function (): int {
            return 5;
        };
        add_filter('ll_tools_category_lineup_page_size', $page_size_filter);

        $word_queries = [];
        $capture = $this->captureWordQueries($word_queries);
        try {
            $sequence_page = ll_tools_get_category_lineup_candidate_page($category_id, [
                'view' => 'sequence',
                'page' => 2,
                'per_page' => 99,
            ]);
            $search_page = ll_tools_get_category_lineup_candidate_page($category_id, [
                'view' => 'candidates',
                'page' => 1,
                'per_page' => 99,
                'search' => 'Needle',
            ]);
        } finally {
            remove_action('pre_get_posts', $capture, 999);
            remove_filter('ll_tools_category_lineup_page_size', $page_size_filter);
        }

        $this->assertIsArray($sequence_page);
        $this->assertSame(5, (int) $sequence_page['per_page']);
        $this->assertSame(2, (int) $sequence_page['page']);
        $this->assertSame(
            array_slice($stored_order, 5, 5),
            array_map(static function (array $item): int {
                return (int) $item['id'];
            }, (array) $sequence_page['items'])
        );
        $this->assertSame([6, 7, 8, 9], array_map(static function (array $item): int {
            return (int) $item['position'];
        }, (array) $sequence_page['items']));

        $this->assertIsArray($search_page);
        $this->assertSame(1, (int) $search_page['total']);
        $this->assertSame($needle_id, (int) ($search_page['items'][0]['id'] ?? 0));
        $this->assertNotEmpty($word_queries);
        foreach ($word_queries as $query_vars) {
            $this->assertGreaterThan(0, (int) ($query_vars['posts_per_page'] ?? 0));
            $this->assertLessThanOrEqual(5, (int) ($query_vars['posts_per_page'] ?? 0));
        }
    }

    public function test_submitted_id_validation_queries_only_the_bounded_candidates(): void
    {
        $category_id = $this->createCategory();
        $word_a_id = $this->createWord($category_id, 'Validate Alpha');
        $word_b_id = $this->createWord($category_id, 'Validate Beta');
        $outside_category_id = $this->createCategory();
        $outside_word_id = $this->createWord($outside_category_id, 'Validate Outside');

        $word_queries = [];
        $capture = $this->captureWordQueries($word_queries);
        try {
            $valid = ll_tools_validate_category_lineup_candidate_ids(
                $category_id,
                [$word_b_id, $word_a_id],
                2
            );
            $invalid = ll_tools_validate_category_lineup_candidate_ids(
                $category_id,
                [$word_a_id, $outside_word_id],
                2
            );
            $query_count_before_overflow = count($word_queries);
            $overflow = ll_tools_validate_category_lineup_candidate_ids(
                $category_id,
                [$word_a_id, $word_b_id, $outside_word_id],
                2
            );
        } finally {
            remove_action('pre_get_posts', $capture, 999);
        }

        $this->assertSame([$word_b_id, $word_a_id], $valid);
        $this->assertWPError($invalid);
        $this->assertSame('lineup_invalid_word', $invalid->get_error_code());
        $this->assertWPError($overflow);
        $this->assertSame('lineup_too_many_ids', $overflow->get_error_code());
        $this->assertSame($query_count_before_overflow, count($word_queries));
        $this->assertCount(2, $word_queries);
        foreach ($word_queries as $query_vars) {
            $this->assertSame(2, (int) ($query_vars['posts_per_page'] ?? 0));
            $this->assertSame(
                2,
                count(array_values(array_filter(array_map('intval', (array) ($query_vars['post__in'] ?? [])))))
            );
        }
    }

    public function test_sequence_mutations_preserve_stored_order_and_direction_semantics(): void
    {
        $category_id = $this->createCategory();
        $word_a_id = $this->createWord($category_id, 'Mutate Alpha');
        $word_b_id = $this->createWord($category_id, 'Mutate Beta');
        $word_c_id = $this->createWord($category_id, 'Mutate Gamma');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, [$word_a_id, $word_b_id, $word_c_id]);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, 'rtl');

        $move = ll_tools_apply_category_lineup_sequence_mutation($category_id, [
            'mutation' => 'move_down',
            'word_id' => $word_a_id,
        ]);
        $this->assertIsArray($move);
        $this->assertSame([$word_b_id, $word_a_id, $word_c_id], $this->storedOrder($category_id));

        $reset = ll_tools_apply_category_lineup_sequence_mutation($category_id, [
            'mutation' => 'reset',
            'word_id' => $word_a_id,
        ]);
        $this->assertIsArray($reset);
        $this->assertSame([$word_b_id, $word_c_id], $this->storedOrder($category_id));
        $resolved_after_reset = ll_tools_get_category_lineup_config($category_id);
        $this->assertSame(
            [$word_b_id, $word_c_id, $word_a_id],
            array_map('intval', (array) ($resolved_after_reset['word_ids'] ?? [])),
            'Reset words must return to the automatic suffix instead of disappearing from Line-Up.'
        );

        $add = ll_tools_apply_category_lineup_sequence_mutation($category_id, [
            'mutation' => 'add',
            'word_id' => $word_a_id,
        ]);
        $this->assertIsArray($add);
        $this->assertSame([$word_b_id, $word_c_id, $word_a_id], $this->storedOrder($category_id));
        $this->assertSame('rtl', (string) get_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_DIRECTION_META_KEY, true));
    }

    private function createCategory(): int
    {
        $term = wp_insert_term(
            'Line-Up Manager ' . wp_generate_password(8, false),
            'word-category'
        );
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
        return $word_id;
    }

    private function captureWordQueries(array &$captured): callable
    {
        $callback = static function (WP_Query $query) use (&$captured): void {
            if ($query->get('post_type') === 'words') {
                $captured[] = $query->query_vars;
            }
        };
        add_action('pre_get_posts', $callback, 999);
        return $callback;
    }

    /** @return int[] */
    private function storedOrder(int $category_id): array
    {
        return array_values(array_map(
            'intval',
            (array) get_term_meta($category_id, LL_TOOLS_CATEGORY_LINEUP_WORD_ORDER_META_KEY, true)
        ));
    }
}
