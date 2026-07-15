<?php
declare(strict_types=1);

final class VocabLessonFailOpenMaintenanceTest extends LL_Tools_TestCase
{
    private int $previousUserId = 0;

    /** @var callable */
    private $minimumWordsFilter;

    /** @var array<string,array{exists:bool,value:mixed}> */
    private array $ownerMapSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousUserId = (int) get_current_user_id();
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->minimumWordsFilter = static function (): int {
            return 1;
        };
        add_filter('ll_tools_quiz_min_words', $this->minimumWordsFilter);
        $this->installCompleteOwnerMap();
        $this->resetMaintenance();
    }

    protected function tearDown(): void
    {
        remove_filter('ll_tools_quiz_min_words', $this->minimumWordsFilter);
        $this->resetMaintenance();
        $this->restoreOwnerMap();
        wp_set_current_user($this->previousUserId);

        parent::tearDown();
    }

    public function test_immediate_cleanup_preserves_lesson_and_remains_retryable_after_incomplete_eligibility(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(false);
        $lessonId = $this->createLesson($wordsetId, $categoryId, 'Immediate fail-open lesson');
        $this->resetMaintenance(false);

        $this->withFailedWordCountQuery($wordsetId, static function () use ($categoryId): void {
            ll_tools_sync_vocab_lessons_for_category_immediate($categoryId, true, true);
        });

        $this->assertSame('publish', get_post_status($lessonId));
        $state = ll_tools_get_vocab_lesson_reconciliation_state();
        $this->assertSame('queued', (string) ($state['status'] ?? ''));
        $this->assertSame('sync', (string) ($state['phase'] ?? ''));
        $this->assertSame([$wordsetId], array_values(array_map('intval', (array) ($state['wordset_ids'] ?? []))));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));

        $runtime = ll_tools_get_category_maintenance_runtime();
        $this->assertArrayNotHasKey(
            $categoryId,
            (array) ($runtime['synced_vocab_category_ids'] ?? []),
            'An incomplete immediate attempt must remain retryable in the same request.'
        );

        ll_tools_sync_vocab_lessons_for_category_immediate($categoryId, false, true);
        $this->assertSame(
            'trash',
            get_post_status($lessonId),
            'The recovered complete result should be allowed to remove the now-proven ineligible lesson.'
        );
    }

    public function test_cleanup_batch_does_not_trash_or_advance_past_incomplete_eligibility(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(false);
        $lessonId = $this->createLesson($wordsetId, $categoryId, 'Cleanup fail-open lesson');
        $this->resetMaintenance(false);

        $state = ll_tools_queue_vocab_lesson_reconciliation([$wordsetId], [
            'manual' => true,
            'cleanup_invalid' => true,
            'cleanup_unavailable_categories' => true,
        ], true);
        $state['cleanup_cursor'] = $lessonId - 1;
        update_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, $state, false);

        $result = $this->withFailedWordCountQuery($wordsetId, static function (): array {
            return ll_tools_run_vocab_lesson_reconciliation_batch();
        });

        $this->assertSame('queued', (string) ($result['status'] ?? ''));
        $this->assertSame($lessonId - 1, (int) ($result['cleanup_cursor'] ?? -1));
        $this->assertSame(0, (int) ($result['cleanup_processed'] ?? -1));
        $this->assertSame(0, (int) ($result['removed'] ?? -1));
        $this->assertStringContainsString('incomplete', (string) ($result['message'] ?? ''));
        $this->assertSame('publish', get_post_status($lessonId));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
    }

    public function test_sync_batch_retries_without_advancing_when_candidate_query_fails(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(true);
        $this->resetMaintenance(false);
        ll_tools_queue_vocab_lesson_reconciliation([$wordsetId], ['manual' => true], true);

        $result = $this->withFailedCandidateQuery(static function (): array {
            return ll_tools_run_vocab_lesson_reconciliation_batch();
        });

        $this->assertSame('queued', (string) ($result['status'] ?? ''));
        $this->assertSame(0, (int) ($result['wordset_index'] ?? -1));
        $this->assertSame(0, (int) ($result['category_cursor'] ?? -1));
        $this->assertSame(0, (int) ($result['categories_processed'] ?? -1));
        $this->assertStringContainsString('candidates or counts were incomplete', (string) ($result['message'] ?? ''));
        $this->assertSame([], $this->activeLessonIds($wordsetId, $categoryId));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
    }

    public function test_sync_batch_retries_without_advancing_when_count_query_fails(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(true);
        $this->resetMaintenance(false);
        ll_tools_queue_vocab_lesson_reconciliation([$wordsetId], ['manual' => true], true);

        $result = $this->withFailedWordCountQuery($wordsetId, static function (): array {
            return ll_tools_run_vocab_lesson_reconciliation_batch();
        });

        $this->assertSame('queued', (string) ($result['status'] ?? ''));
        $this->assertSame(0, (int) ($result['wordset_index'] ?? -1));
        $this->assertSame(0, (int) ($result['category_cursor'] ?? -1));
        $this->assertSame(0, (int) ($result['categories_processed'] ?? -1));
        $this->assertStringContainsString('candidates or counts were incomplete', (string) ($result['message'] ?? ''));
        $this->assertSame([], $this->activeLessonIds($wordsetId, $categoryId));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
    }

    public function test_category_image_requirement_propagates_incomplete_quiz_config(): void
    {
        [, $categoryId] = $this->createFixture(false);
        $category = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $result = $this->withFailedTermMetaQuery($categoryId, static function () use ($category): array {
            $complete = true;
            $requiresImages = ll_tools_vocab_lesson_category_requires_images($category, 0, $complete);
            return [$requiresImages, $complete];
        });

        $this->assertTrue($result[0], 'The conservative fallback may require images while the source is unavailable.');
        $this->assertFalse($result[1], 'Callers must be told that the fallback quiz configuration is incomplete.');

        wp_cache_delete($categoryId, 'term_meta');
        $complete = false;
        $this->assertFalse(ll_tools_vocab_lesson_category_requires_images($category, 0, $complete));
        $this->assertTrue($complete);
    }

    public function test_sign_mode_meta_failure_keeps_targeted_vocab_count_retryable(): void
    {
        [$wordsetId, $categoryId, $wordId] = $this->createFixture(true);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'audio');
        update_post_meta($wordId, '_thumbnail_id', '999999');

        $failed = $this->withFailedTermMetaQuery($wordsetId, static function () use ($categoryId, $wordsetId): array {
            $complete = true;
            $count = ll_tools_get_vocab_lesson_category_word_count_targeted(
                $categoryId,
                $wordsetId,
                $complete
            );
            return [$count, $complete];
        });

        $this->assertSame(0, $failed[0]);
        $this->assertFalse($failed[1]);

        wp_cache_delete($wordsetId, 'term_meta');
        $retry_complete = false;
        $this->assertSame(
            1,
            ll_tools_get_vocab_lesson_category_word_count_targeted(
                $categoryId,
                $wordsetId,
                $retry_complete
            )
        );
        $this->assertTrue($retry_complete, 'The incomplete sign-mode fallback must not enter the request cache.');
    }

    public function test_sign_mode_meta_failure_marks_vocab_grid_presentation_incomplete(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(false);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'audio');
        $category = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $failed = $this->withFailedTermMetaQuery($wordsetId, static function () use ($wordsetId, $category): array {
            $complete = true;
            $config = ll_tools_vocab_lesson_get_effective_quiz_config($wordsetId, $category, $complete);
            return [$config, $complete];
        });

        $this->assertFalse($failed[1]);
        $this->assertSame('audio', (string) ($failed[0]['prompt_type'] ?? ''));
        $this->assertSame('audio', (string) ($failed[0]['option_type'] ?? ''));

        wp_cache_delete($wordsetId, 'term_meta');
        $retry_complete = false;
        $retry_config = ll_tools_vocab_lesson_get_effective_quiz_config(
            $wordsetId,
            $category,
            $retry_complete
        );
        $this->assertTrue($retry_complete);
        $this->assertSame('image', (string) ($retry_config['prompt_type'] ?? ''));
        $this->assertSame('image', (string) ($retry_config['option_type'] ?? ''));
    }

    public function test_sign_mode_meta_failure_keeps_destructive_vocab_eligibility_incomplete(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(false);
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');
        update_term_meta($categoryId, 'll_quiz_prompt_type', 'audio');
        update_term_meta($categoryId, 'll_quiz_option_type', 'audio');
        $category = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
        $proven_empty_counts = [
            'all' => [$categoryId => 0],
            'with_images' => [$categoryId => 0],
            'complete' => true,
        ];

        $failed = $this->withFailedTermMetaQuery(
            $wordsetId,
            static function () use ($category, $wordsetId, $proven_empty_counts): array {
                $complete = true;
                $eligible = ll_tools_can_generate_vocab_lesson(
                    $category,
                    $wordsetId,
                    $proven_empty_counts,
                    $complete
                );
                return [$eligible, $complete];
            }
        );

        $this->assertFalse($failed[0]);
        $this->assertFalse(
            $failed[1],
            'Reconciliation must not treat a sign-mode DB fallback as proof that the lesson is unavailable.'
        );

        wp_cache_delete($wordsetId, 'term_meta');
        $retry_complete = false;
        $this->assertFalse(
            ll_tools_can_generate_vocab_lesson(
                $category,
                $wordsetId,
                $proven_empty_counts,
                $retry_complete
            )
        );
        $this->assertTrue($retry_complete, 'A recovered read may make the destructive decision conclusive.');
    }

    public function test_targeted_count_does_not_request_cache_an_incomplete_category_resolution(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(true);

        $failed = $this->withFailedTermLookup($categoryId, static function () use ($categoryId, $wordsetId): array {
            $complete = true;
            $count = ll_tools_get_vocab_lesson_category_word_count_targeted($categoryId, $wordsetId, $complete);
            return [$count, $complete];
        });
        $this->assertSame(0, $failed[0]);
        $this->assertFalse($failed[1]);

        clean_term_cache($categoryId, 'word-category');
        $complete = false;
        $this->assertSame(1, ll_tools_get_vocab_lesson_category_word_count_targeted($categoryId, $wordsetId, $complete));
        $this->assertTrue($complete, 'The recovered same-request read must not reuse an incomplete zero.');
    }

    public function test_prompt_preview_ids_report_an_incomplete_category_resolution(): void
    {
        [$wordsetId, $categoryId] = $this->createFixture(false);

        $result = $this->withFailedTermLookup($categoryId, static function () use ($categoryId, $wordsetId): array {
            $complete = true;
            $ids = ll_tools_get_vocab_lesson_prompt_card_preview_ids(
                $wordsetId,
                $categoryId,
                2,
                0,
                $complete
            );
            return [$ids, $complete];
        });

        $this->assertSame([], $result[0]);
        $this->assertFalse($result[1]);
    }

    public function test_deep_counts_initialize_a_proven_empty_owner_map_before_caching(): void
    {
        [$wordsetId] = $this->createFixture(false);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION);
        unset($GLOBALS['ll_tools_specific_wrong_answer_owner_map_read_complete']);

        $counts = ll_tools_get_vocab_lesson_deepest_counts_for_wordset($wordsetId, true);

        $this->assertTrue((bool) ($counts['complete'] ?? false));
        $this->assertIsArray(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null));
        $this->assertStringStartsWith(
            'v2:',
            (string) get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, '')
        );
    }

    /** @return array{0:int,1:int,2:int} */
    private function createFixture(bool $withWord): array
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $wordset = wp_insert_term(
            'Vocab fail-open wordset ' . $suffix,
            'wordset',
            ['slug' => 'vocab-fail-open-wordset-' . $suffix]
        );
        $category = wp_insert_term(
            'Vocab fail-open category ' . $suffix,
            'word-category',
            ['slug' => 'vocab-fail-open-category-' . $suffix]
        );
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordsetId = (int) $wordset['term_id'];
        $categoryId = (int) $category['term_id'];

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_translation');

        $wordId = 0;
        if ($withWord) {
            $wordId = (int) self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Vocab fail-open word ' . $suffix,
            ]);
            update_post_meta($wordId, 'word_translation', 'Translation ' . $suffix);
            wp_set_post_terms($wordId, [$categoryId], 'word-category', false);
            wp_set_post_terms($wordId, [$wordsetId], 'wordset', false);
        }

        $GLOBALS['ll_tools_vocab_lesson_skip_auto_sync'] = true;
        update_option('ll_vocab_lesson_wordsets', [$wordsetId], false);
        unset($GLOBALS['ll_tools_vocab_lesson_skip_auto_sync']);

        return [$wordsetId, $categoryId, $wordId];
    }

    private function createLesson(int $wordsetId, int $categoryId, string $title): int
    {
        $lessonId = (int) self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordsetId);
        update_post_meta($lessonId, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $categoryId);
        return $lessonId;
    }

    /** @return mixed */
    private function withFailedWordCountQuery(int $wordsetId, callable $callback)
    {
        global $wpdb;

        $wordset = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $termTaxonomyId = (int) $wordset->term_taxonomy_id;
        $injected = false;
        $queryFilter = static function (string $sql) use ($wpdb, $termTaxonomyId, &$injected): string {
            if (
                !$injected
                && strpos($sql, 'COUNT(DISTINCT posts.ID) AS total') !== false
                && strpos($sql, "posts.post_type = 'words'") !== false
                && preg_match(
                    '/wordset_relationships\.term_taxonomy_id\s*=\s*'
                        . preg_quote((string) $termTaxonomyId, '/')
                        . '\b/i',
                    $sql
                ) === 1
            ) {
                $injected = true;
                return "SELECT category_id, total FROM {$wpdb->posts}_ll_tools_vocab_fail_open_missing";
            }
            return $sql;
        };

        return $this->withFailedQuery($queryFilter, $injected, $callback);
    }

    /** @return mixed */
    private function withFailedCandidateQuery(callable $callback)
    {
        global $wpdb;

        $injected = false;
        $queryFilter = static function (string $sql) use ($wpdb, &$injected): string {
            if (!$injected && strpos($sql, 'SELECT DISTINCT category_taxonomy.term_id') !== false) {
                $injected = true;
                return "SELECT term_id FROM {$wpdb->terms}_ll_tools_vocab_fail_open_missing";
            }
            return $sql;
        };

        return $this->withFailedQuery($queryFilter, $injected, $callback);
    }

    /** @return mixed */
    private function withFailedTermMetaQuery(int $categoryId, callable $callback)
    {
        global $wpdb;

        wp_cache_delete($categoryId, 'term_meta');
        $injected = false;
        $queryFilter = static function (string $sql) use ($wpdb, $categoryId, &$injected): string {
            if (
                !$injected
                && strpos($sql, "FROM {$wpdb->termmeta}") !== false
                && preg_match(
                    '/term_id\s+IN\s*\([^)]*\b' . preg_quote((string) $categoryId, '/') . '\b[^)]*\)/i',
                    $sql
                ) === 1
            ) {
                $injected = true;
                return "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}_ll_tools_vocab_fail_open_missing";
            }
            return $sql;
        };

        return $this->withFailedQuery($queryFilter, $injected, $callback);
    }

    /** @return mixed */
    private function withFailedTermLookup(int $categoryId, callable $callback)
    {
        global $wpdb;

        clean_term_cache($categoryId, 'word-category');
        $injected = false;
        $queryFilter = static function (string $sql) use ($wpdb, $categoryId, &$injected): string {
            if (
                !$injected
                && strpos($sql, "FROM {$wpdb->terms} AS t") !== false
                && strpos($sql, "INNER JOIN {$wpdb->term_taxonomy} AS tt") !== false
                && preg_match('/t\.term_id\s*=\s*' . preg_quote((string) $categoryId, '/') . '\b/i', $sql) === 1
            ) {
                $injected = true;
                return "SELECT t.*, tt.* FROM {$wpdb->terms}_ll_tools_vocab_fail_open_missing AS t";
            }
            return $sql;
        };

        return $this->withFailedQuery($queryFilter, $injected, $callback);
    }

    /** @return mixed */
    private function withFailedQuery(callable $queryFilter, bool &$injected, callable $callback)
    {
        global $wpdb;

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $queryFilter);
        try {
            $result = $callback();
        } finally {
            remove_filter('query', $queryFilter);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected, 'The fixture must interrupt the intended vocab reconciliation query.');
        return $result;
    }

    /** @return int[] */
    private function activeLessonIds(int $wordsetId, int $categoryId): array
    {
        return array_values(array_map('intval', get_posts([
            'post_type' => 'll_vocab_lesson',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'fields' => 'ids',
            'numberposts' => -1,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => LL_TOOLS_VOCAB_LESSON_WORDSET_META,
                    'value' => (string) $wordsetId,
                ],
                [
                    'key' => LL_TOOLS_VOCAB_LESSON_CATEGORY_META,
                    'value' => (string) $categoryId,
                ],
            ],
        ])));
    }

    private function resetMaintenance(bool $resetEnabledWordsets = true): void
    {
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        delete_option('ll_tools_vocab_lesson_sync_last');
        delete_transient(LL_TOOLS_VOCAB_LESSON_SYNC_LOCK);
        delete_transient('ll_tools_vocab_lesson_sync_notice');
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        if ($resetEnabledWordsets) {
            delete_option('ll_vocab_lesson_wordsets');
        }
        if (function_exists('ll_tools_reset_category_maintenance_runtime')) {
            ll_tools_reset_category_maintenance_runtime();
        }
    }

    private function installCompleteOwnerMap(): void
    {
        $sentinel = '__ll_tools_vocab_fail_open_missing__' . wp_generate_uuid4();
        foreach ([
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION,
        ] as $optionName) {
            $value = get_option($optionName, $sentinel);
            $this->ownerMapSnapshot[$optionName] = [
                'exists' => $value !== $sentinel,
                'value' => $value,
            ];
        }

        $sourceEpoch = ll_tools_specific_wrong_answer_owner_map_source_epoch(true);
        if ($sourceEpoch < 0) {
            $sourceEpoch = 0;
            update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, $sourceEpoch, false);
        }
        $generation = 'vocab-sync-' . substr(md5(wp_generate_uuid4()), 0, 20);
        update_option(
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION,
            ll_tools_specific_wrong_answer_owner_map_pack([], $generation, $sourceEpoch),
            false
        );
        update_option(
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
            'v2:' . $generation,
            false
        );
    }

    private function restoreOwnerMap(): void
    {
        foreach ($this->ownerMapSnapshot as $optionName => $snapshot) {
            if ($snapshot['exists']) {
                update_option($optionName, $snapshot['value'], false);
            } else {
                delete_option($optionName);
            }
        }
        $this->ownerMapSnapshot = [];
        unset($GLOBALS['ll_tools_specific_wrong_answer_owner_map_read_complete']);
    }
}
