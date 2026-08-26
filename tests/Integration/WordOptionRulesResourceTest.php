<?php
declare(strict_types=1);

final class WordOptionRulesResourceTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        delete_option('ll_tools_word_option_rules');

        parent::tearDown();
    }

    public function test_large_category_initial_render_hydrates_only_one_candidate_page(): void
    {
        $fixture = $this->createLargeFixture(80, true);
        $this->setCurrentAdministrator();

        $word_query_limits = [];
        $audio_parent_counts = [];
        $query_observer = $this->createQueryObserver($word_query_limits, $audio_parent_counts);
        $page_size_filter = static function (): int {
            return 10;
        };

        add_action('pre_get_posts', $query_observer);
        add_filter('ll_tools_word_option_rules_editor_page_size', $page_size_filter);
        try {
            $html = $this->renderAdminPage($fixture['wordset_id'], $fixture['category_id']);
        } finally {
            remove_filter('ll_tools_word_option_rules_editor_page_size', $page_size_filter);
            remove_action('pre_get_posts', $query_observer);
        }

        $this->assertSame(10, substr_count($html, '<tr data-word-id="'));
        $this->assertStringContainsString('Showing 1-10 of 80 words', $html);
        $this->assertStringContainsString('name="ll_word_search"', $html);
        $this->assertStringContainsString('ll_word_page=2', html_entity_decode($html));
        $this->assertStringContainsString('ll-tools-word-options-reason--same_translation', $html);
        $this->assertNotEmpty($word_query_limits);
        $this->assertLessThanOrEqual(10, max($word_query_limits));
        $this->assertNotContains(-1, $word_query_limits, 'Normal editor rendering must not issue an unbounded words query.');
        $this->assertNotEmpty($audio_parent_counts);
        $this->assertLessThanOrEqual(10, max($audio_parent_counts));
    }

    public function test_large_category_group_autosave_merges_only_visible_candidates(): void
    {
        $fixture = $this->createLargeFixture(80, false);
        $this->setCurrentAdministrator();
        $outside_pair = [$fixture['word_ids'][70], $fixture['word_ids'][71]];

        ll_tools_update_word_option_rules(
            $fixture['wordset_id'],
            $fixture['category_id'],
            [
                [
                    'label' => 'Remove Me',
                    'word_ids' => $fixture['word_ids'],
                ],
                [
                    'label' => 'Core',
                    'word_ids' => $fixture['word_ids'],
                ],
            ],
            [[
                'word_ids' => $outside_pair,
                'unblocked_recording_types' => [],
            ]]
        );

        $visible_word_ids = array_slice($fixture['word_ids'], 0, 10);
        $kept_visible_word_ids = array_slice($visible_word_ids, 1);
        $word_query_limits = [];
        $audio_parent_counts = [];
        $query_observer = $this->createQueryObserver($word_query_limits, $audio_parent_counts);
        $page_size_filter = static function (): int {
            return 10;
        };

        add_action('pre_get_posts', $query_observer);
        add_filter('ll_tools_word_option_rules_editor_page_size', $page_size_filter);
        try {
            $result = ll_tools_save_word_option_rules_from_request([
                'wordset_id' => $fixture['wordset_id'],
                'category_id' => $fixture['category_id'],
                'll_mutation_scope' => 'groups',
                'editor_word_ids' => $visible_word_ids,
                'group_names_present' => 1,
                'group_names' => [
                    'g1' => 'Core',
                ],
                'group_original_labels' => [
                    'g1' => 'Core',
                ],
                'group_members' => [
                    'g1' => $kept_visible_word_ids,
                ],
            ]);
            $second_result = ll_tools_save_word_option_rules_from_request([
                'wordset_id' => $fixture['wordset_id'],
                'category_id' => $fixture['category_id'],
                'll_mutation_scope' => 'groups',
                'editor_word_ids' => $visible_word_ids,
                'group_names_present' => 1,
                'group_names' => [
                    'g1' => 'Core',
                ],
                'group_original_labels' => [
                    'g1' => 'Core',
                ],
                'group_members' => [
                    'g1' => $kept_visible_word_ids,
                ],
            ]);
        } finally {
            remove_filter('ll_tools_word_option_rules_editor_page_size', $page_size_filter);
            remove_action('pre_get_posts', $query_observer);
        }

        $this->assertIsArray($result);
        $this->assertIsArray($second_result);
        $saved = ll_tools_get_word_option_rules($fixture['wordset_id'], $fixture['category_id']);
        $this->assertCount(1, $saved['groups']);
        $saved_group_ids = array_map('intval', (array) ($saved['groups'][0]['word_ids'] ?? []));
        $this->assertNotContains($visible_word_ids[0], $saved_group_ids);
        $this->assertContains($fixture['word_ids'][79], $saved_group_ids, 'Off-page group membership must survive a page delta.');
        $this->assertContains($outside_pair, array_map(static function (array $pair): array {
            return array_map('intval', (array) ($pair['word_ids'] ?? []));
        }, $saved['pairs']));
        $this->assertNotEmpty($word_query_limits);
        $this->assertLessThanOrEqual(10, max($word_query_limits));
        $this->assertSame([], $audio_parent_counts, 'Group-only writes must not collect category audio.');
    }

    public function test_large_category_pair_removal_checks_only_the_named_pair_and_keeps_locked_conflict(): void
    {
        $fixture = $this->createLargeFixture(80, false);
        $this->setCurrentAdministrator();
        $pair = [$fixture['word_ids'][0], $fixture['word_ids'][1]];
        update_post_meta($pair[0], 'word_translation', 'shared lock');
        update_post_meta($pair[1], 'word_translation', 'shared lock');

        ll_tools_update_word_option_rules(
            $fixture['wordset_id'],
            $fixture['category_id'],
            [],
            [[
                'word_ids' => $pair,
                'unblocked_recording_types' => [],
            ]]
        );

        $visible_word_ids = array_slice($fixture['word_ids'], 0, 10);
        $pair_key = min($pair) . '|' . max($pair);
        $word_query_limits = [];
        $audio_parent_counts = [];
        $query_observer = $this->createQueryObserver($word_query_limits, $audio_parent_counts);
        $page_size_filter = static function (): int {
            return 10;
        };

        add_action('pre_get_posts', $query_observer);
        add_filter('ll_tools_word_option_rules_editor_page_size', $page_size_filter);
        try {
            $result = ll_tools_save_word_option_rules_from_request([
                'wordset_id' => $fixture['wordset_id'],
                'category_id' => $fixture['category_id'],
                'll_mutation_scope' => 'pairs',
                'editor_word_ids' => $visible_word_ids,
                'remove_pair' => $pair_key,
            ]);
        } finally {
            remove_filter('ll_tools_word_option_rules_editor_page_size', $page_size_filter);
            remove_action('pre_get_posts', $query_observer);
        }

        $this->assertIsArray($result);
        $saved_pair_keys = array_map(static function (array $saved_pair): string {
            $word_ids = array_map('intval', (array) ($saved_pair['word_ids'] ?? []));
            sort($word_ids, SORT_NUMERIC);
            return implode('|', $word_ids);
        }, ll_tools_get_word_option_rules($fixture['wordset_id'], $fixture['category_id'])['pairs']);
        $this->assertContains($pair_key, $saved_pair_keys, 'Automatic text conflicts must remain locked.');
        $this->assertNotEmpty($word_query_limits);
        $this->assertLessThanOrEqual(2, max($word_query_limits));
        $this->assertNotEmpty($audio_parent_counts);
        $this->assertLessThanOrEqual(2, max($audio_parent_counts), 'Pair mutation checks must not collect audio beyond the named pair.');
    }

    private function createLargeFixture(int $word_count, bool $duplicate_translations): array
    {
        $wordset = wp_insert_term('Resource Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $category = wp_insert_term('Resource Category ' . wp_generate_password(6, false), 'word-category');
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'text_title');

        $word_ids = [];
        for ($index = 1; $index <= $word_count; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'draft',
                'post_title' => sprintf('Resource Word %03d', $index),
            ]);
            update_post_meta(
                $word_id,
                'word_translation',
                $duplicate_translations ? 'shared meaning' : sprintf('Meaning %03d', $index)
            );
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            $word_ids[] = (int) $word_id;
        }

        foreach ($word_ids as $word_id) {
            wp_update_post([
                'ID' => $word_id,
                'post_status' => 'publish',
            ]);
        }
        $assigned_category_ids = wp_get_post_terms($word_ids[0], 'word-category', ['fields' => 'ids']);
        if (!is_wp_error($assigned_category_ids) && !empty($assigned_category_ids)) {
            $category_id = (int) $assigned_category_ids[0];
        }

        return [
            'wordset_id' => $wordset_id,
            'category_id' => $category_id,
            'word_ids' => $word_ids,
        ];
    }

    private function setCurrentAdministrator(): void
    {
        $administrator_id = self::factory()->user->create(['role' => 'administrator']);
        $role = get_role('administrator');
        if ($role && !$role->has_cap('view_ll_tools')) {
            $role->add_cap('view_ll_tools');
        }
        wp_set_current_user($administrator_id);
    }

    private function renderAdminPage(int $wordset_id, int $category_id): string
    {
        $previous_get = $_GET;
        try {
            $_GET = [
                'wordset_id' => $wordset_id,
                'category_id' => $category_id,
            ];
            ob_start();
            ll_render_word_option_rules_admin_page();
            return (string) ob_get_clean();
        } finally {
            $_GET = $previous_get;
        }
    }

    private function createQueryObserver(array &$word_query_limits, array &$audio_parent_counts): callable
    {
        return static function ($query) use (&$word_query_limits, &$audio_parent_counts): void {
            if (!($query instanceof WP_Query)) {
                return;
            }

            $post_type = $query->get('post_type');
            if ($post_type === 'words') {
                $word_query_limits[] = (int) $query->get('posts_per_page');
                return;
            }
            if ($post_type !== 'word_audio') {
                return;
            }

            $parent_ids = $query->get('post_parent__in');
            if (is_array($parent_ids) && !empty($parent_ids)) {
                $audio_parent_counts[] = count(array_unique(array_map('intval', $parent_ids)));
            }
        };
    }
}
