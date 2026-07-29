<?php
declare(strict_types=1);

final class LegacyContentLessonMigrationTest extends LL_Tools_TestCase
{
    public function test_simple_favorites_extractor_handles_indexed_site_and_group_shapes(): void
    {
        $raw = [
            0 => [
                'site_id' => 1,
                'posts' => [2 => 150, 3 => 40],
                'groups' => [
                    [
                        'group_id' => 9,
                        'site_id' => 1,
                        'posts' => [8 => 40, 9 => 225],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            [40, 150, 225],
            ll_tools_extract_legacy_favorite_post_ids($raw)
        );
    }

    public function test_retained_source_bridge_is_empty_idempotent_and_maps_relations_and_completions(): void
    {
        $wordset_id = $this->createWordset('Retained source bridge');
        $prerequisite_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Retained bridge prerequisite',
            'post_content' => '<p>Small canonical lesson.</p>',
        ]);
        $prerequisite_migration = ll_tools_migrate_legacy_lesson_post(
            $prerequisite_source_id,
            $wordset_id,
            ['apply' => true, 'status' => 'publish']
        );
        $this->assertIsArray($prerequisite_migration);
        $prerequisite_target_id = (int) $prerequisite_migration['target_id'];

        $large_source_body = str_repeat(
            '<p>Retained editorial body [word_audio] with table data.</p>',
            2000
        );
        $this->assertGreaterThan(98000, strlen($large_source_body));
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Most Common Words',
            'post_name' => 'most-common-words',
            'post_excerpt' => 'A deliberately retained source lesson.',
            'post_content' => $large_source_body,
        ]);
        add_post_meta(
            $source_id,
            'post_dependency',
            (string) get_permalink($prerequisite_source_id)
        );
        $args = [
            'phase' => 'lessons',
            'wordset_id' => $wordset_id,
            'source_ids' => [$source_id],
            'limit' => 1,
            'status' => 'publish',
            'show_in_mix' => false,
            'retained_source' => true,
        ];

        $dry_run = ll_tools_migrate_legacy_content_lessons_batch($args);
        $this->assertIsArray($dry_run);
        $this->assertTrue((bool) $dry_run['retained_source']);
        $this->assertSame(1, (int) $dry_run['created']);
        $this->assertSame(0, ll_tools_find_content_lesson_by_legacy_source($source_id));

        $applied = ll_tools_migrate_legacy_content_lessons_batch(
            array_merge($args, ['apply' => true])
        );
        $this->assertIsArray($applied);
        $this->assertSame([], $applied['errors']);
        $this->assertSame(1, (int) $applied['created']);

        $target_id = ll_tools_find_content_lesson_by_legacy_source($source_id);
        $this->assertGreaterThan(0, $target_id);
        $target = get_post($target_id);
        $this->assertInstanceOf(WP_Post::class, $target);
        $this->assertSame('publish', $target->post_status);
        $this->assertSame('', $target->post_content);
        $this->assertSame(
            'A deliberately retained source lesson.',
            $target->post_excerpt
        );
        $this->assertSame(
            $large_source_body,
            (string) get_post_field('post_content', $source_id)
        );
        $this->assertSame(
            ['1'],
            get_post_meta(
                $target_id,
                LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
                false
            )
        );
        $this->assertSame(
            (string) get_permalink($source_id),
            (string) get_permalink($target_id)
        );
        $this->assertSame(
            (string) get_permalink($source_id),
            ll_tools_get_legacy_lesson_retained_source_url($target_id)
        );

        $relations = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'relations',
            'wordset_id' => $wordset_id,
            'source_ids' => [$source_id],
            'limit' => 1,
            'apply' => true,
        ]);
        $this->assertIsArray($relations);
        $this->assertSame([], $relations['errors']);
        $this->assertSame(
            [$prerequisite_target_id],
            ll_tools_get_content_lesson_prereq_lesson_ids($target_id)
        );
        $this->assertSame('', (string) get_post_field('post_content', $target_id));

        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'simplefavorites', [
            [
                'site_id' => 1,
                'posts' => [$source_id],
            ],
        ]);
        delete_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION);
        $completions = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'completions',
            'wordset_id' => $wordset_id,
            'limit' => 1,
            'apply' => true,
        ]);
        $this->assertIsArray($completions);
        $this->assertSame(1, (int) $completions['mapped_associations']);
        $this->assertSame(
            [$target_id],
            ll_tools_get_completed_content_lesson_ids($user_id)
        );

        update_post_meta(
            $target_id,
            LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META,
            '1'
        );
        $this->assertFalse(ll_tools_get_content_lesson_show_in_mix($target_id));
        $idempotent = ll_tools_migrate_legacy_content_lessons_batch(
            array_merge($args, ['apply' => true])
        );
        $this->assertIsArray($idempotent);
        $this->assertSame(1, (int) $idempotent['unchanged']);
        $this->assertSame('', (string) get_post_field('post_content', $target_id));

        $mode_change = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true, 'status' => 'publish']
        );
        $this->assertWPError($mode_change);
        $this->assertSame(
            'legacy_lesson_retained_source_mode_mismatch',
            $mode_change->get_error_code()
        );
        $this->assertSame('', (string) get_post_field('post_content', $target_id));
    }

    public function test_retained_source_batch_contract_fails_closed(): void
    {
        $wordset_id = $this->createWordset('Retained source contract');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Retained source contract',
        ]);
        $base = [
            'phase' => 'lessons',
            'wordset_id' => $wordset_id,
            'source_ids' => [$source_id],
            'limit' => 1,
            'limit_was_explicit' => true,
            'limit_raw' => '1',
            'status' => 'publish',
            'show_in_mix' => false,
            'show_in_mix_was_explicit' => true,
            'show_in_mix_raw' => '0',
            'retained_source' => true,
            'apply' => true,
        ];
        $invalid_cases = [
            'wrong phase' => array_merge($base, ['phase' => 'relations']),
            'missing source IDs' => array_diff_key($base, ['source_ids' => true]),
            'too many source IDs' => array_merge(
                $base,
                ['source_ids' => range(1, 21)]
            ),
            'category scope' => array_merge($base, ['category_ids' => [1]]),
            'missing status' => array_diff_key($base, ['status' => true]),
            'draft status' => array_merge($base, ['status' => 'draft']),
            'missing mix flag' => array_diff_key($base, ['show_in_mix' => true]),
            'enabled mix flag' => array_merge($base, ['show_in_mix' => true]),
            'implicit mix flag' => array_merge(
                $base,
                ['show_in_mix_was_explicit' => false]
            ),
            'invalid raw mix flag' => array_merge(
                $base,
                ['show_in_mix_raw' => 'false']
            ),
            'oversized limit' => array_merge($base, ['limit' => 21]),
            'implicit limit' => array_merge(
                $base,
                ['limit_was_explicit' => false]
            ),
            'invalid raw limit' => array_merge($base, ['limit_raw' => '-1']),
        ];

        foreach ($invalid_cases as $label => $invalid_args) {
            $result = ll_tools_migrate_legacy_content_lessons_batch($invalid_args);
            $this->assertWPError($result, $label);
            $this->assertSame(
                'legacy_lesson_retained_source_contract_invalid',
                $result->get_error_code(),
                $label
            );
        }

        $this->assertSame(0, ll_tools_find_content_lesson_by_legacy_source($source_id));
    }

    public function test_lesson_and_relation_batches_are_bounded_and_idempotent(): void
    {
        $wordset_id = $this->createWordset('Legacy lesson migration');
        $category_id = self::factory()->category->create([
            'name' => 'Legacy Lessons',
            'slug' => 'legacy-lessons',
        ]);
        $first_source_id = self::factory()->post->create([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Legacy Basics',
            'post_name' => 'legacy-basics',
            'post_content' => '[custom_header]<p>[color1]Merhaba $1[/color1]</p>[custom_footer]',
        ]);
        $second_source_id = self::factory()->post->create([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Legacy Follow Up',
            'post_name' => 'legacy-follow-up',
            'post_content' => '[regex_linker]raw text[/regex_linker]',
        ]);
        wp_set_post_categories($first_source_id, [$category_id]);
        wp_set_post_categories($second_source_id, [$category_id]);
        add_post_meta($first_source_id, 'verb_ending', '-iyor');
        add_post_meta($first_source_id, 'other_concept', '<script>bad()</script>Present tense');
        add_post_meta($first_source_id, 'other_concept', 'Progressive aspect');
        add_post_meta($first_source_id, 'other_concept', 'Vowel harmony');
        add_post_meta($first_source_id, 'other_concept', 'Present time');
        add_post_meta($first_source_id, 'other_concept', 'Ongoing action');
        update_post_meta(
            $second_source_id,
            '_processed_text_with_links',
            '<p><a href="' . esc_url(get_permalink($first_source_id)) . '">linked $1 text</a></p>'
        );
        add_post_meta(
            $second_source_id,
            'post_dependency',
            get_permalink($first_source_id)
        );
        add_post_meta($second_source_id, 'post_dependency', '');
        add_post_meta($second_source_id, 'post_dependency', 'missing-prerequisite');

        $dry_run = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'lessons',
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_id],
            'limit' => 1,
        ]);
        $this->assertIsArray($dry_run);
        $this->assertSame(1, (int) $dry_run['processed']);
        $this->assertSame(1, (int) $dry_run['created']);
        $this->assertTrue((bool) $dry_run['has_more']);
        $this->assertSame(0, ll_tools_find_content_lesson_by_legacy_source($first_source_id));

        $lesson_result = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'lessons',
            'wordset_id' => $wordset_id,
            'source_ids' => [$first_source_id, $second_source_id],
            'limit' => 10,
            'apply' => true,
        ]);
        $this->assertIsArray($lesson_result);
        $this->assertSame(2, (int) $lesson_result['created']);
        $this->assertSame([], $lesson_result['errors']);

        $first_target_id = ll_tools_find_content_lesson_by_legacy_source($first_source_id);
        $second_target_id = ll_tools_find_content_lesson_by_legacy_source($second_source_id);
        $this->assertGreaterThan(0, $first_target_id);
        $this->assertGreaterThan(0, $second_target_id);
        $this->assertSame('article', ll_tools_get_content_lesson_kind($first_target_id));
        $this->assertSame($wordset_id, ll_tools_get_content_lesson_wordset_id($first_target_id));
        $first_target = get_post($first_target_id);
        $second_target = get_post($second_target_id);
        $this->assertInstanceOf(WP_Post::class, $first_target);
        $this->assertInstanceOf(WP_Post::class, $second_target);
        $this->assertStringNotContainsString('[custom_header]', (string) $first_target->post_content);
        $this->assertStringContainsString('[color1]Merhaba $1[/color1]', (string) $first_target->post_content);
        $this->assertStringContainsString('linked $1 text', (string) $second_target->post_content);
        $this->assertStringNotContainsString('[regex_linker]', (string) $second_target->post_content);
        $this->assertStringNotContainsString('<script', (string) $first_target->post_excerpt);
        $stored_concepts = (array) get_post_meta(
            $first_target_id,
            LL_TOOLS_LEGACY_LESSON_CONCEPTS_META,
            true
        );
        $this->assertCount(1, (array) ($stored_concepts['verb_ending'] ?? []));
        $this->assertCount(5, (array) ($stored_concepts['other_concept'] ?? []));
        $stored_categories = (array) get_post_meta(
            $first_target_id,
            LL_TOOLS_LEGACY_LESSON_CATEGORIES_META,
            true
        );
        $this->assertSame($category_id, (int) ($stored_categories[0]['id'] ?? 0));

        $relation_result = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'relations',
            'wordset_id' => $wordset_id,
            'source_ids' => [$first_source_id, $second_source_id],
            'limit' => 10,
            'apply' => true,
        ]);
        $this->assertIsArray($relation_result);
        $this->assertSame(1, (int) $relation_result['resolved_dependencies']);
        $this->assertSame(1, (int) $relation_result['unresolved_dependencies']);
        $this->assertSame(1, (int) $relation_result['rewritten_links']);
        $this->assertSame(
            [$first_target_id],
            ll_tools_get_content_lesson_prereq_lesson_ids($second_target_id)
        );
        $this->assertSame(
            ['missing-prerequisite'],
            (array) get_post_meta(
                $second_target_id,
                LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META,
                true
            )
        );
        $rewritten_target = get_post($second_target_id);
        $this->assertInstanceOf(WP_Post::class, $rewritten_target);
        $decoded_rewritten_content = html_entity_decode(
            (string) $rewritten_target->post_content,
            ENT_QUOTES | ENT_HTML5
        );
        $this->assertStringContainsString(
            (string) get_permalink($first_target_id),
            $decoded_rewritten_content
        );
        $this->assertStringNotContainsString(
            (string) get_permalink($first_source_id),
            $decoded_rewritten_content
        );

        $second_run = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'lessons',
            'wordset_id' => $wordset_id,
            'source_ids' => [$first_source_id, $second_source_id],
            'limit' => 10,
            'apply' => true,
        ]);
        $this->assertIsArray($second_run);
        $this->assertSame(0, (int) $second_run['created']);
        $this->assertSame(2, (int) $second_run['unchanged']);
    }

    public function test_completion_batch_merges_both_legacy_stores_into_new_lesson_ids(): void
    {
        $wordset_id = $this->createWordset('Legacy completion migration');
        $first_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Completion source one',
        ]);
        $second_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Completion source two',
        ]);
        foreach ([$first_source_id, $second_source_id] as $source_id) {
            $result = ll_tools_migrate_legacy_lesson_post(
                $source_id,
                $wordset_id,
                ['apply' => true]
            );
            $this->assertIsArray($result);
        }
        $first_target_id = ll_tools_find_content_lesson_by_legacy_source($first_source_id);
        $second_target_id = ll_tools_find_content_lesson_by_legacy_source($second_source_id);

        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'simplefavorites', [
            0 => [
                'site_id' => 1,
                'posts' => [2 => $first_source_id],
                'groups' => [
                    [
                        'group_id' => 1,
                        'posts' => [3 => $first_source_id],
                    ],
                ],
            ],
        ]);
        update_user_meta($user_id, 'tt_completed_lessons', [$second_source_id, 999999]);
        delete_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION);

        $summary = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'completions',
            'wordset_id' => $wordset_id,
            'limit' => 1,
            'apply' => true,
        ]);
        $this->assertIsArray($summary);
        $this->assertSame(1, (int) $summary['processed']);
        $this->assertSame(1, (int) $summary['changed_users']);
        $this->assertSame(3, (int) $summary['source_associations']);
        $this->assertSame(2, (int) $summary['mapped_associations']);
        $this->assertSame(1, (int) $summary['unmapped_associations']);
        $this->assertSame(
            [$first_target_id, $second_target_id],
            ll_tools_get_completed_content_lesson_ids($user_id)
        );
        $audit = get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, []);
        $this->assertIsArray($audit);
        $this->assertSame($wordset_id, (int) ($audit['wordset_id'] ?? 0));
        $this->assertSame(1, (int) ($audit['processed'] ?? 0));
        $this->assertSame(1, (int) ($audit['unmapped_associations'] ?? 0));
        $this->assertSame([999999], (array) ($audit['unmapped_source_ids'] ?? []));
        $this->assertTrue((bool) ($audit['completed'] ?? false));
    }

    public function test_source_page_rejects_oversized_explicit_and_exclusion_scopes(): void
    {
        $limit = static function (): int {
            return 100;
        };
        add_filter('ll_tools_completed_content_lesson_ids_limit', $limit);
        try {
            $oversized_ids = range(1, 101);
            $explicit_result = ll_tools_legacy_lesson_source_page([
                'source_ids' => $oversized_ids,
                'limit' => 10,
            ]);
            $exclusion_result = ll_tools_legacy_lesson_source_page([
                'category_ids' => [1],
                'exclude_source_ids' => $oversized_ids,
                'limit' => 10,
            ]);
        } finally {
            remove_filter('ll_tools_completed_content_lesson_ids_limit', $limit);
        }

        $this->assertWPError($explicit_result);
        $this->assertSame(
            'legacy_lesson_scope_too_large',
            $explicit_result->get_error_code()
        );
        $this->assertWPError($exclusion_result);
        $this->assertSame(
            'legacy_lesson_scope_too_large',
            $exclusion_result->get_error_code()
        );
    }

    public function test_completion_planning_reports_cap_full_canonical_store_with_new_mapping(): void
    {
        $wordset_id = $this->createWordset('Cap-full completion planning');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Cap-full completion source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = ll_tools_find_content_lesson_by_legacy_source($source_id);
        $this->assertGreaterThan(0, $target_id);

        $user_id = self::factory()->user->create();
        $canonical_ids = range($target_id + 1000, $target_id + 1099);
        update_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            $canonical_ids
        );
        update_user_meta($user_id, 'tt_completed_lessons', [$source_id]);

        $limit = static function (): int {
            return 100;
        };
        add_filter('ll_tools_completed_content_lesson_ids_limit', $limit);
        try {
            $summary = ll_tools_migrate_legacy_lesson_completions_batch(
                $wordset_id,
                [
                    'after_id' => 0,
                    'limit' => 1,
                    'apply' => true,
                ]
            );
        } finally {
            remove_filter('ll_tools_completed_content_lesson_ids_limit', $limit);
        }

        $this->assertIsArray($summary);
        $this->assertSame(1, (int) $summary['processed']);
        $this->assertSame(1, (int) $summary['mapped_associations']);
        $this->assertSame(0, (int) $summary['changed_users']);
        $this->assertCount(1, (array) $summary['errors']);
        $this->assertStringContainsString(
            (string) $user_id,
            (string) $summary['errors'][0]
        );
        $this->assertStringContainsString(
            'configured safe limit',
            (string) $summary['errors'][0]
        );
        $this->assertSame(
            $canonical_ids,
            ll_tools_get_completed_content_lesson_ids($user_id)
        );
        $this->assertNotContains($target_id, $canonical_ids, '', true);
        $audit = get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, []);
        $this->assertIsArray($audit);
        $this->assertFalse((bool) ($audit['completed'] ?? true));
        $this->assertCount(1, (array) ($audit['errors'] ?? []));
    }

    public function test_favorites_extractor_fails_closed_when_associations_exceed_limit(): void
    {
        $limit = static function (): int {
            return 2;
        };
        add_filter('ll_tools_legacy_favorites_association_limit', $limit);
        try {
            $result = ll_tools_extract_legacy_favorite_post_ids([
                ['posts' => [11, 12, 13]],
            ]);
        } finally {
            remove_filter('ll_tools_legacy_favorites_association_limit', $limit);
        }

        $this->assertWPError($result);
        $this->assertSame(
            'legacy_favorites_associations_too_large',
            $result->get_error_code()
        );
    }

    public function test_completion_audit_replay_is_idempotent_and_requires_run_id(): void
    {
        $wordset_id = $this->createWordset('Replay-safe completion audit');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Replay completion source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);

        $first_user_id = self::factory()->user->create();
        $second_user_id = self::factory()->user->create();
        foreach ([$first_user_id, $second_user_id] as $user_id) {
            update_user_meta($user_id, 'tt_completed_lessons', [$source_id]);
        }
        delete_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION);

        $first_page = ll_tools_migrate_legacy_lesson_completions_batch(
            $wordset_id,
            [
                'after_id' => 0,
                'limit' => 1,
                'apply' => true,
            ]
        );
        $this->assertIsArray($first_page);
        $this->assertTrue((bool) $first_page['has_more']);
        $run_id = (string) $first_page['audit_run_id'];
        $this->assertNotSame('', $run_id);

        $missing_run = ll_tools_migrate_legacy_lesson_completions_batch(
            $wordset_id,
            [
                'after_id' => (int) $first_page['next_cursor'],
                'limit' => 1,
                'apply' => true,
            ]
        );
        $this->assertWPError($missing_run);
        $this->assertSame(
            'legacy_completion_audit_sequence_invalid',
            $missing_run->get_error_code()
        );

        $tail_args = [
            'after_id' => (int) $first_page['next_cursor'],
            'limit' => 1,
            'apply' => true,
            'run_id' => $run_id,
        ];
        $tail = ll_tools_migrate_legacy_lesson_completions_batch(
            $wordset_id,
            $tail_args
        );
        $this->assertIsArray($tail);
        $this->assertFalse((bool) $tail['has_more']);
        $audit = get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, []);
        $this->assertSame(2, (int) ($audit['processed'] ?? 0));
        $this->assertTrue((bool) ($audit['completed'] ?? false));

        $replayed_tail = ll_tools_migrate_legacy_lesson_completions_batch(
            $wordset_id,
            $tail_args
        );
        $this->assertIsArray($replayed_tail);
        $replayed_audit = get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, []);
        $this->assertSame(2, (int) ($replayed_audit['processed'] ?? 0));
        $this->assertCount(2, (array) ($replayed_audit['pages'] ?? []));
        $this->assertTrue((bool) ($replayed_audit['completed'] ?? false));
    }

    public function test_category_source_scope_defaults_to_published_posts(): void
    {
        $category_id = self::factory()->category->create([
            'name' => 'Published legacy lessons',
        ]);
        $published_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Published source',
        ]);
        $draft_id = self::factory()->post->create([
            'post_status' => 'draft',
            'post_title' => 'Draft source',
        ]);
        wp_set_post_categories($published_id, [$category_id]);
        wp_set_post_categories($draft_id, [$category_id]);

        $page = ll_tools_legacy_lesson_source_page([
            'category_ids' => [$category_id],
            'limit' => 10,
        ]);

        $this->assertIsArray($page);
        $this->assertSame([$published_id], array_map('intval', $page['ids']));
    }

    public function test_relation_batch_reports_source_ids_that_must_be_retried(): void
    {
        $wordset_id = $this->createWordset('Failed legacy relation');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Unmigrated relation source',
        ]);

        $result = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'relations',
            'wordset_id' => $wordset_id,
            'source_ids' => [$source_id],
            'limit' => 10,
            'apply' => true,
        ]);

        $this->assertIsArray($result);
        $this->assertSame([$source_id], (array) $result['failed_source_ids']);
        $this->assertCount(1, (array) $result['errors']);
    }

    public function test_duplicate_source_mapping_fails_closed_before_writing(): void
    {
        $wordset_id = $this->createWordset('Duplicate source mapping');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Duplicate source',
        ]);
        foreach (['Duplicate target one', 'Duplicate target two'] as $title) {
            $target_id = self::factory()->post->create([
                'post_type' => 'll_content_lesson',
                'post_status' => 'draft',
                'post_title' => $title,
            ]);
            update_post_meta(
                $target_id,
                LL_TOOLS_CONTENT_LESSON_WORDSET_META,
                $wordset_id
            );
            update_post_meta(
                $target_id,
                LL_TOOLS_LEGACY_LESSON_SOURCE_POST_META,
                $source_id
            );
        }

        $result = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );

        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_duplicate_target_mapping',
            $result->get_error_code()
        );
        $this->assertSame(0, ll_tools_find_content_lesson_by_legacy_source($source_id));
    }

    public function test_source_snapshot_query_failures_do_not_create_or_change_lessons(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Fail-closed source snapshot');
        $category_id = self::factory()->category->create([
            'name' => 'Fail-closed source category',
        ]);
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Fail-closed source',
            'post_content' => '[regex_linker]legacy[/regex_linker]',
        ]);
        wp_set_post_categories($source_id, [$category_id]);
        update_post_meta($source_id, '_processed_text_with_links', '<p>Processed</p>');
        update_post_meta($source_id, '_lesson_level', 4);
        add_post_meta($source_id, 'verb_ending', '-iyor');
        $default_wordset_before = ll_tools_get_legacy_lesson_default_wordset_id();

        [$meta_result, $meta_failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $source_id): bool {
                return stripos($query, "FROM {$wpdb->postmeta}") !== false
                    && stripos($query, '_processed_text_with_links') !== false
                    && stripos($query, '_lesson_level') !== false
                    && preg_match(
                        '/post_id\s*=\s*' . preg_quote((string) $source_id, '/') . '\b/i',
                        $query
                    ) === 1;
            },
            static function () use ($source_id, $wordset_id) {
                return ll_tools_migrate_legacy_lesson_post(
                    $source_id,
                    $wordset_id,
                    ['apply' => true]
                );
            }
        );
        $this->assertTrue($meta_failure_injected);
        $this->assertWPError($meta_result);
        $this->assertSame(
            'legacy_lesson_source_meta_query_incomplete',
            $meta_result->get_error_code()
        );
        $this->assertSame(0, ll_tools_find_content_lesson_by_legacy_source($source_id));
        $this->assertSame(
            $default_wordset_before,
            ll_tools_get_legacy_lesson_default_wordset_id()
        );

        [$category_result, $category_failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $source_id): bool {
                return stripos($query, "FROM {$wpdb->term_relationships} tr") !== false
                    && stripos($query, "tt.taxonomy = 'category'") !== false
                    && preg_match(
                        '/tr\.object_id\s*=\s*' . preg_quote((string) $source_id, '/') . '\b/i',
                        $query
                    ) === 1;
            },
            static function () use ($source_id, $wordset_id) {
                return ll_tools_migrate_legacy_lesson_post(
                    $source_id,
                    $wordset_id,
                    ['apply' => true]
                );
            }
        );
        $this->assertTrue($category_failure_injected);
        $this->assertWPError($category_result);
        $this->assertSame(
            'legacy_lesson_source_category_query_incomplete',
            $category_result->get_error_code()
        );
        $this->assertSame(0, ll_tools_find_content_lesson_by_legacy_source($source_id));
        $this->assertSame(
            $default_wordset_before,
            ll_tools_get_legacy_lesson_default_wordset_id()
        );
    }

    public function test_dependency_query_failure_preserves_existing_relations_and_content(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Fail-closed dependencies');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Dependency source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        add_post_meta($source_id, 'post_dependency', 'https://example.test/missing/');
        update_post_meta(
            $target_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [987654]
        );
        update_post_meta(
            $target_id,
            LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META,
            ['keep-existing']
        );
        wp_update_post([
            'ID' => $target_id,
            'post_content' => '<p>Keep existing content.</p>',
        ]);

        [$result, $failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $source_id): bool {
                return stripos($query, "FROM {$wpdb->postmeta}") !== false
                    && stripos($query, 'post_dependency') !== false
                    && preg_match(
                        '/post_id\s*=\s*' . preg_quote((string) $source_id, '/') . '\b/i',
                        $query
                    ) === 1;
            },
            static function () use ($source_id, $wordset_id) {
                return ll_tools_migrate_legacy_lesson_relations(
                    $source_id,
                    $wordset_id,
                    ['apply' => true]
                );
            }
        );

        $this->assertTrue($failure_injected);
        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_dependency_query_incomplete',
            $result->get_error_code()
        );
        $this->assertSame(
            [987654],
            get_post_meta(
                $target_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                true
            )
        );
        $this->assertSame(
            ['keep-existing'],
            get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META, true)
        );
        $this->assertSame(
            '<p>Keep existing content.</p>',
            (string) get_post_field('post_content', $target_id)
        );
    }

    public function test_completion_snapshot_query_failure_does_not_write_or_advance_audit(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Fail-closed completions');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Completion snapshot source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        $user_id = self::factory()->user->create();
        $canonical_before = [$target_id + 1000];
        update_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            $canonical_before
        );
        update_user_meta($user_id, 'tt_completed_lessons', [$source_id]);
        delete_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION);

        [$result, $failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $user_id): bool {
                return stripos($query, "FROM {$wpdb->usermeta}") !== false
                    && stripos($query, LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META) !== false
                    && stripos($query, 'simplefavorites') !== false
                    && stripos($query, 'tt_completed_lessons') !== false
                    && preg_match(
                        '/user_id\s+IN\s*\(\s*' . preg_quote((string) $user_id, '/') . '\s*\)/i',
                        $query
                    ) === 1;
            },
            static function () use ($wordset_id) {
                return ll_tools_migrate_legacy_lesson_completions_batch(
                    $wordset_id,
                    ['limit' => 1, 'apply' => true]
                );
            }
        );

        $this->assertTrue($failure_injected);
        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_completion_meta_query_incomplete',
            $result->get_error_code()
        );
        $this->assertSame(
            $canonical_before,
            get_user_meta(
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                true
            )
        );
        $this->assertFalse(get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, false));
    }

    public function test_duplicate_completion_rows_fail_closed_before_audit_or_merge(): void
    {
        $wordset_id = $this->createWordset('Ambiguous completions');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Ambiguous completion source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'tt_completed_lessons', [$source_id]);
        add_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            [$target_id + 1000],
            false
        );
        add_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            [$target_id + 2000],
            false
        );
        delete_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION);

        $result = ll_tools_migrate_legacy_lesson_completions_batch(
            $wordset_id,
            ['limit' => 1, 'apply' => true]
        );

        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_completion_meta_ambiguous',
            $result->get_error_code()
        );
        $this->assertCount(
            2,
            get_user_meta(
                $user_id,
                LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                false
            )
        );
        $this->assertFalse(get_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION, false));
    }

    public function test_invalid_explicit_status_is_rejected_before_batch_writes(): void
    {
        $wordset_id = $this->createWordset('Invalid status');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Invalid status source',
        ]);
        $default_wordset_before = ll_tools_get_legacy_lesson_default_wordset_id();

        foreach (['trash', '!!!', ''] as $invalid_status) {
            $result = ll_tools_migrate_legacy_content_lessons_batch([
                'phase' => 'lessons',
                'wordset_id' => $wordset_id,
                'source_ids' => [$source_id],
                'limit' => 1,
                'status' => $invalid_status,
                'apply' => true,
            ]);

            $this->assertWPError($result);
            $this->assertSame(
                'legacy_lesson_status_invalid',
                $result->get_error_code()
            );
            $this->assertSame(
                0,
                ll_tools_find_content_lesson_by_legacy_source($source_id)
            );
            $this->assertSame(
                $default_wordset_before,
                ll_tools_get_legacy_lesson_default_wordset_id()
            );
        }
    }

    public function test_existing_target_with_unsupported_status_is_not_recreated_as_draft(): void
    {
        $wordset_id = $this->createWordset('Unsupported target status');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Unsupported target source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        $status_update = wp_update_post([
            'ID' => $target_id,
            'post_status' => 'trash',
        ], true);
        $this->assertSame($target_id, $status_update);

        $result = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );

        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_target_status_invalid',
            $result->get_error_code()
        );
        $this->assertSame('trash', get_post_status($target_id));
        $this->assertSame(
            $target_id,
            ll_tools_resolve_content_lesson_by_legacy_source($source_id)
        );
    }

    public function test_wordset_mismatch_preserves_published_and_private_targets(): void
    {
        $current_wordset_id = $this->createWordset('Protected current wordset');
        $requested_wordset_id = $this->createWordset('Rejected requested wordset');

        foreach (['publish', 'private'] as $status) {
            $source_id = self::factory()->post->create([
                'post_status' => 'publish',
                'post_title' => ucfirst($status) . ' protected source',
            ]);
            $migration = ll_tools_migrate_legacy_lesson_post(
                $source_id,
                $current_wordset_id,
                ['apply' => true, 'status' => $status]
            );
            $this->assertIsArray($migration);
            $target_id = (int) $migration['target_id'];
            $protected_content = '<p>' . $status . ' target content must remain.</p>';
            wp_update_post([
                'ID' => $target_id,
                'post_title' => ucfirst($status) . ' protected target',
                'post_content' => $protected_content,
            ]);
            update_post_meta(
                $target_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                [987650]
            );
            update_post_meta(
                $target_id,
                LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META,
                ['keep-' . $status]
            );
            add_post_meta($source_id, 'post_dependency', 'missing-' . $status);
            $default_wordset_before = ll_tools_get_legacy_lesson_default_wordset_id();

            $lesson_result = ll_tools_migrate_legacy_lesson_post(
                $source_id,
                $requested_wordset_id,
                [
                    'apply' => true,
                    'status' => 'draft',
                    'show_in_mix' => true,
                ]
            );
            $this->assertWPError($lesson_result);
            $this->assertSame(
                'legacy_lesson_target_wordset_mismatch',
                $lesson_result->get_error_code()
            );
            $this->assertSame($status, get_post_status($target_id));
            $this->assertSame(
                ucfirst($status) . ' protected target',
                (string) get_post_field('post_title', $target_id)
            );
            $this->assertSame(
                $protected_content,
                (string) get_post_field('post_content', $target_id)
            );
            $this->assertSame(
                $current_wordset_id,
                ll_tools_get_content_lesson_wordset_id($target_id)
            );
            $this->assertSame(
                $default_wordset_before,
                ll_tools_get_legacy_lesson_default_wordset_id()
            );

            $relation_result = ll_tools_migrate_legacy_lesson_relations(
                $source_id,
                $requested_wordset_id,
                ['apply' => true]
            );
            $this->assertWPError($relation_result);
            $this->assertSame(
                'legacy_lesson_target_wordset_mismatch',
                $relation_result->get_error_code()
            );
            $this->assertSame($status, get_post_status($target_id));
            $this->assertSame(
                [987650],
                get_post_meta(
                    $target_id,
                    LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                    true
                )
            );
            $this->assertSame(
                ['keep-' . $status],
                get_post_meta(
                    $target_id,
                    LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META,
                    true
                )
            );
            $this->assertSame(
                $protected_content,
                (string) get_post_field('post_content', $target_id)
            );
        }
    }

    public function test_explicit_source_scope_query_failure_is_retryable(): void
    {
        global $wpdb;

        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Authoritative explicit source',
        ]);
        [$result, $failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $source_id): bool {
                return stripos($query, "FROM {$wpdb->posts}") !== false
                    && stripos($query, "post_type = 'post'") !== false
                    && stripos($query, 'ID IN') !== false
                    && preg_match(
                        '/\b' . preg_quote((string) $source_id, '/') . '\b/',
                        $query
                    ) === 1;
            },
            static function () use ($source_id) {
                return ll_tools_legacy_lesson_source_page([
                    'source_ids' => [$source_id],
                    'limit' => 1,
                ]);
            }
        );

        $this->assertTrue($failure_injected);
        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_source_query_incomplete',
            $result->get_error_code()
        );
        $retry = ll_tools_legacy_lesson_source_page([
            'source_ids' => [$source_id],
            'limit' => 1,
        ]);
        $this->assertIsArray($retry);
        $this->assertSame([$source_id], array_map('intval', $retry['ids']));
    }

    public function test_link_snapshot_query_failure_preserves_target_and_marks_source_retryable(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Fail-closed link snapshot');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Link snapshot source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        $protected_content = '<p>Keep link snapshot content.</p>';
        wp_update_post([
            'ID' => $target_id,
            'post_content' => $protected_content,
        ]);

        [$result, $failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $source_id, $target_id): bool {
                return stripos($query, "SELECT *\n         FROM {$wpdb->posts}") !== false
                    && stripos($query, 'WHERE ID IN') !== false
                    && preg_match(
                        '/\b' . preg_quote((string) $source_id, '/') . '\b/',
                        $query
                    ) === 1
                    && preg_match(
                        '/\b' . preg_quote((string) $target_id, '/') . '\b/',
                        $query
                    ) === 1;
            },
            static function () use ($source_id, $wordset_id) {
                return ll_tools_migrate_legacy_content_lessons_batch([
                    'phase' => 'relations',
                    'wordset_id' => $wordset_id,
                    'source_ids' => [$source_id],
                    'limit' => 1,
                    'apply' => true,
                ]);
            }
        );

        $this->assertTrue($failure_injected);
        $this->assertIsArray($result);
        $this->assertSame([$source_id], (array) $result['failed_source_ids']);
        $this->assertSame(
            $protected_content,
            (string) get_post_field('post_content', $target_id)
        );
        $retry = ll_tools_migrate_legacy_lesson_relations(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($retry);
        $this->assertSame(
            $protected_content,
            (string) get_post_field('post_content', $target_id)
        );
    }

    public function test_empty_target_permalink_does_not_cache_or_mutate_link_map(): void
    {
        $wordset_id = $this->createWordset('Missing target permalink');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Missing permalink source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        $protected_content = '<p>Keep missing-permalink content.</p>';
        wp_update_post([
            'ID' => $target_id,
            'post_content' => $protected_content,
        ]);

        $empty_target_permalink = static function (
            string $url,
            WP_Post $post
        ) use ($target_id): string {
            return (int) $post->ID === $target_id ? '' : $url;
        };
        add_filter('post_type_link', $empty_target_permalink, 10, 2);
        try {
            $result = ll_tools_migrate_legacy_lesson_relations(
                $source_id,
                $wordset_id,
                ['apply' => true]
            );
        } finally {
            remove_filter('post_type_link', $empty_target_permalink, 10);
        }

        $this->assertWPError($result);
        $this->assertSame(
            'legacy_lesson_link_permalink_incomplete',
            $result->get_error_code()
        );
        $this->assertSame(
            $protected_content,
            (string) get_post_field('post_content', $target_id)
        );
        $retry = ll_tools_migrate_legacy_lesson_relations(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($retry);
    }

    public function test_dependency_lookup_and_normalizer_failures_preserve_target_relations(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Authoritative relation reads');
        $prerequisite_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Authoritative prerequisite source',
        ]);
        $dependent_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Authoritative dependent source',
        ]);
        foreach ([$prerequisite_source_id, $dependent_source_id] as $source_id) {
            $migration = ll_tools_migrate_legacy_lesson_post(
                $source_id,
                $wordset_id,
                ['apply' => true]
            );
            $this->assertIsArray($migration);
        }
        $prerequisite_target_id = ll_tools_find_content_lesson_by_legacy_source(
            $prerequisite_source_id
        );
        $dependent_target_id = ll_tools_find_content_lesson_by_legacy_source(
            $dependent_source_id
        );
        add_post_meta(
            $dependent_source_id,
            'post_dependency',
            (string) $prerequisite_source_id
        );
        update_post_meta(
            $dependent_target_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [987640]
        );
        update_post_meta(
            $dependent_target_id,
            LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META,
            ['keep-authoritative']
        );

        [$lookup_result, $lookup_failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $prerequisite_source_id): bool {
                return stripos($query, 'SELECT ID, post_name') !== false
                    && stripos($query, "FROM {$wpdb->posts}") !== false
                    && stripos($query, "post_type = 'post'") !== false
                    && preg_match(
                        '/\b' . preg_quote((string) $prerequisite_source_id, '/') . '\b/',
                        $query
                    ) === 1;
            },
            static function () use ($dependent_source_id, $wordset_id) {
                return ll_tools_migrate_legacy_lesson_relations(
                    $dependent_source_id,
                    $wordset_id,
                    ['apply' => true]
                );
            }
        );
        $this->assertTrue($lookup_failure_injected);
        $this->assertWPError($lookup_result);
        $this->assertSame(
            'legacy_lesson_dependency_source_query_incomplete',
            $lookup_result->get_error_code()
        );
        $this->assertSame(
            [987640],
            get_post_meta(
                $dependent_target_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                true
            )
        );

        [$normalizer_result, $normalizer_failure_injected] = $this->runWithInjectedQueryFailure(
            static function (string $query) use ($wpdb, $prerequisite_target_id): bool {
                return stripos($query, "FROM {$wpdb->posts}") !== false
                    && stripos($query, "post_type = 'll_content_lesson'") !== false
                    && stripos($query, 'ID IN') !== false
                    && preg_match(
                        '/\b' . preg_quote((string) $prerequisite_target_id, '/') . '\b/',
                        $query
                    ) === 1;
            },
            static function () use ($dependent_source_id, $wordset_id) {
                return ll_tools_migrate_legacy_lesson_relations(
                    $dependent_source_id,
                    $wordset_id,
                    ['apply' => true]
                );
            }
        );
        $this->assertTrue($normalizer_failure_injected);
        $this->assertWPError($normalizer_result);
        $this->assertSame(
            'content_lesson_relation_query_incomplete',
            $normalizer_result->get_error_code()
        );
        $this->assertSame(
            [987640],
            get_post_meta(
                $dependent_target_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                true
            )
        );
        $this->assertSame(
            ['keep-authoritative'],
            get_post_meta(
                $dependent_target_id,
                LL_TOOLS_LEGACY_LESSON_UNRESOLVED_META,
                true
            )
        );
    }

    public function test_completion_apply_revalidates_noop_plan_before_certifying_merge(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Completion no-op race');
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Completion no-op race source',
        ]);
        $migration = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($migration);
        $target_id = (int) $migration['target_id'];
        $concurrent_id = $target_id + 1000;
        $user_id = self::factory()->user->create();
        update_user_meta($user_id, 'tt_completed_lessons', [$source_id]);
        update_user_meta(
            $user_id,
            LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
            [$target_id]
        );
        delete_option(LL_TOOLS_LEGACY_COMPLETION_AUDIT_OPTION);

        $snapshot_query_count = 0;
        $concurrent_change_injected = false;
        $query_filter = null;
        $query_filter = static function (string $query) use (
            &$query_filter,
            &$snapshot_query_count,
            &$concurrent_change_injected,
            $wpdb,
            $user_id,
            $concurrent_id
        ): string {
            if (stripos($query, "FROM {$wpdb->usermeta}") === false
                || stripos($query, LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META) === false
                || stripos($query, 'simplefavorites') === false
                || stripos($query, 'tt_completed_lessons') === false
                || preg_match(
                    '/user_id\s+IN\s*\(\s*' . preg_quote((string) $user_id, '/') . '\s*\)/i',
                    $query
                ) !== 1
            ) {
                return $query;
            }

            $snapshot_query_count++;
            if ($snapshot_query_count === 2) {
                remove_filter('query', $query_filter);
                update_user_meta(
                    $user_id,
                    LL_TOOLS_USER_CONTENT_LESSON_COMPLETION_META,
                    [$concurrent_id]
                );
                $concurrent_change_injected = true;
            }
            return $query;
        };
        add_filter('query', $query_filter);
        try {
            $result = ll_tools_migrate_legacy_lesson_completions_batch(
                $wordset_id,
                ['limit' => 1, 'apply' => true]
            );
        } finally {
            remove_filter('query', $query_filter);
        }

        $this->assertTrue($concurrent_change_injected);
        $this->assertIsArray($result);
        $this->assertSame(
            [$target_id, $concurrent_id],
            ll_tools_get_completed_content_lesson_ids($user_id)
        );
        $this->assertSame([], (array) $result['errors']);
    }

    public function test_plain_permalink_link_keys_keep_source_ids_distinct(): void
    {
        $first_key = ll_tools_legacy_lesson_link_match_key(
            'https://example.test/?p=101&utm_source=legacy'
        );
        $second_key = ll_tools_legacy_lesson_link_match_key(
            'http://example.test/?p=202'
        );

        $this->assertSame('example.test|/?p=101', $first_key);
        $this->assertSame('example.test|/?p=202', $second_key);
        $this->assertNotSame($first_key, $second_key);

        $result = ll_tools_rewrite_legacy_lesson_links(
            '<a href="https://example.test/?p=101&amp;utm_source=legacy#part">Legacy</a>',
            [$first_key => 'https://example.test/lesson/target/']
        );
        $this->assertIsArray($result);
        $this->assertSame(1, (int) $result['rewritten']);
        $this->assertStringContainsString(
            'https://example.test/lesson/target/?utm_source=legacy#part',
            html_entity_decode((string) $result['content'], ENT_QUOTES | ENT_HTML5)
        );
        $this->assertStringNotContainsString(
            'p=101',
            (string) $result['content']
        );
    }

    /**
     * @return array{0:mixed,1:bool}
     */
    private function runWithInjectedQueryFailure(
        callable $matcher,
        callable $operation
    ): array {
        global $wpdb;

        $injected = false;
        $query_filter = static function (string $query) use (
            $matcher,
            &$injected,
            $wpdb
        ): string {
            if (!$injected && $matcher($query)) {
                $injected = true;
                return "SELECT * FROM {$wpdb->prefix}ll_tools_missing_migration_table";
            }
            return $query;
        };
        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $query_filter);
        try {
            $result = $operation();
        } finally {
            remove_filter('query', $query_filter);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        return [$result, $injected];
    }

    private function createWordset(string $label): int
    {
        $term = wp_insert_term(
            $label . ' ' . wp_generate_password(5, false),
            'wordset'
        );
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }
}
