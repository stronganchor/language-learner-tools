<?php
declare(strict_types=1);

final class QuizPageFailOpenMaintenanceTest extends LL_Tools_TestCase
{
    private int $previousUserId = 0;

    /** @var array<string,array{exists:bool,value:mixed}> */
    private array $ownerMapSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousUserId = (int) get_current_user_id();
        wp_set_current_user((int) self::factory()->user->create(['role' => 'administrator']));
        $this->installCompleteOwnerMap();
        $this->resetQuizMaintenance();
    }

    protected function tearDown(): void
    {
        $this->resetQuizMaintenance();
        $this->restoreOwnerMap();
        wp_set_current_user($this->previousUserId);

        parent::tearDown();
    }

    public function test_immediate_sync_preserves_existing_page_and_schedules_retry_when_eligibility_is_incomplete(): void
    {
        $categoryId = $this->createTextCategoryWithOneWord('Immediate');
        $pageId = $this->createGeneratedQuizPage($categoryId, 'Immediate fail-open shell');
        $this->prepareFreshEligibilityGeneration([$categoryId]);

        $this->withFailedPrimaryWordQuery($categoryId, static function () use ($categoryId): void {
            ll_tools_handle_category_sync_immediate($categoryId, true);
        });

        $this->assertSame('publish', get_post_status($pageId));
        $state = ll_tools_get_quiz_page_sync_state();
        $this->assertSame('queued', (string) ($state['status'] ?? ''));
        $this->assertSame('cleanup', (string) ($state['phase'] ?? ''));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));

        $runtime = ll_tools_get_category_maintenance_runtime();
        $this->assertArrayNotHasKey(
            $categoryId,
            (array) ($runtime['synced_quiz_category_ids'] ?? []),
            'An incomplete attempt must remain retryable in the current request.'
        );

        ll_tools_handle_category_sync_immediate($categoryId);
        $this->assertSame(
            'trash',
            get_post_status($pageId),
            'Once the source recovers, the same request must retry and apply the complete ineligible result.'
        );
    }

    public function test_cleanup_batch_does_not_trash_or_advance_past_an_incomplete_category(): void
    {
        $incompleteCategoryId = $this->createTextCategoryWithOneWord('Cleanup incomplete');
        $laterCategoryId = $this->createTextCategoryWithOneWord('Cleanup later');
        $incompletePageId = $this->createGeneratedQuizPage($incompleteCategoryId, 'Cleanup incomplete shell');
        $laterPageId = $this->createGeneratedQuizPage($laterCategoryId, 'Cleanup later shell');
        $this->assertLessThan($laterPageId, $incompletePageId);

        $this->prepareFreshEligibilityGeneration([$incompleteCategoryId, $laterCategoryId]);
        ll_tools_queue_quiz_page_sync(false, true);
        $initialState = ll_tools_get_quiz_page_sync_state();
        $initialState['phase'] = 'cleanup';
        $initialState['cursor'] = $incompletePageId - 1;
        update_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, $initialState, false);

        $batchSize = static function (): int {
            return 2;
        };
        add_filter('ll_tools_quiz_page_sync_batch_size', $batchSize);
        try {
            $state = $this->withFailedPrimaryWordQuery(
                $incompleteCategoryId,
                static function (): array {
                    return ll_tools_run_quiz_page_sync_batch();
                }
            );
        } finally {
            remove_filter('ll_tools_quiz_page_sync_batch_size', $batchSize);
        }

        $this->assertSame('queued', (string) ($state['status'] ?? ''));
        $this->assertSame($incompletePageId - 1, (int) ($state['cursor'] ?? -1));
        $this->assertSame(0, (int) ($state['cleanup_processed'] ?? -1));
        $this->assertSame(0, (int) ($state['removed'] ?? -1));
        $this->assertStringContainsString('eligibility was incomplete', (string) ($state['message'] ?? ''));
        $this->assertSame('publish', get_post_status($incompletePageId));
        $this->assertSame(
            'publish',
            get_post_status($laterPageId),
            'The batch must stop before mutating a later generated page.'
        );
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
    }

    public function test_sync_phase_does_not_advance_past_incomplete_eligibility(): void
    {
        $incompleteCategoryId = $this->createTextCategoryWithOneWord('Sync incomplete');
        $laterCategoryId = $this->createTextCategoryWithOneWord('Sync later');
        $this->assertLessThan($laterCategoryId, $incompleteCategoryId);

        $this->prepareFreshEligibilityGeneration([$incompleteCategoryId, $laterCategoryId]);
        ll_tools_queue_quiz_page_sync(false, true);
        $initialState = ll_tools_get_quiz_page_sync_state();
        $initialState['phase'] = 'sync';
        $initialState['cursor'] = $incompleteCategoryId - 1;
        update_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, $initialState, false);

        $batchSize = static function (): int {
            return 2;
        };
        add_filter('ll_tools_quiz_page_sync_batch_size', $batchSize);
        try {
            $state = $this->withFailedPrimaryWordQuery(
                $incompleteCategoryId,
                static function (): array {
                    return ll_tools_run_quiz_page_sync_batch();
                }
            );
        } finally {
            remove_filter('ll_tools_quiz_page_sync_batch_size', $batchSize);
        }

        $this->assertSame('queued', (string) ($state['status'] ?? ''));
        $this->assertSame($incompleteCategoryId - 1, (int) ($state['cursor'] ?? -1));
        $this->assertSame(0, (int) ($state['categories_processed'] ?? -1));
        $this->assertSame(0, (int) ($state['synced'] ?? -1));
        $this->assertStringContainsString('eligibility was incomplete', (string) ($state['message'] ?? ''));
        $this->assertSame([], $this->activeQuizPageIds($incompleteCategoryId));
        $this->assertSame(
            [],
            $this->activeQuizPageIds($laterCategoryId),
            'The batch must not generate a later category after an incomplete eligibility result.'
        );
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
    }

    private function resetQuizMaintenance(): void
    {
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        delete_option('ll_tools_quiz_page_sync_last');
        delete_transient(LL_TOOLS_QUIZ_PAGE_SYNC_LOCK);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        ll_tools_reset_category_maintenance_runtime();
    }

    private function createTextCategoryWithOneWord(string $label): int
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $created = wp_insert_term(
            'Quiz fail-open ' . $label . ' ' . $suffix,
            'word-category',
            ['slug' => 'quiz-fail-open-' . sanitize_title($label) . '-' . $suffix]
        );
        $this->assertIsArray($created);
        $categoryId = (int) $created['term_id'];

        update_term_meta($categoryId, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($categoryId, 'll_quiz_option_type', 'text_title');

        $wordId = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Quiz fail-open word ' . $suffix,
        ]);
        wp_set_post_terms($wordId, [$categoryId], 'word-category', false);

        return $categoryId;
    }

    private function createGeneratedQuizPage(int $categoryId, string $title): int
    {
        $pageId = (int) self::factory()->post->create([
            'post_type' => LL_TOOLS_QUIZ_PAGE_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        update_post_meta($pageId, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, (string) $categoryId);
        return $pageId;
    }

    /** @param int[] $categoryIds */
    private function prepareFreshEligibilityGeneration(array $categoryIds): void
    {
        $this->resetQuizMaintenance();
        $bumpedIds = ll_tools_bump_category_cache_versions_only($categoryIds);
        sort($categoryIds, SORT_NUMERIC);
        $this->assertSame($categoryIds, $bumpedIds);
    }

    /**
     * @return mixed
     */
    private function withFailedPrimaryWordQuery(int $categoryId, callable $callback)
    {
        global $wpdb;

        $category = get_term($categoryId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);
        $termTaxonomyId = (int) $category->term_taxonomy_id;
        $injected = false;
        $breakPrimaryWordQuery = static function (string $sql) use (
            $wpdb,
            $termTaxonomyId,
            &$injected
        ): string {
            if (
                !$injected
                && strpos($sql, "{$wpdb->posts}.post_type = 'words'") !== false
                && preg_match(
                    '/term_taxonomy_id\s+IN\s*\([^)]*\b'
                        . preg_quote((string) $termTaxonomyId, '/')
                        . '\b[^)]*\)/i',
                    $sql
                ) === 1
            ) {
                $injected = true;
                return "SELECT ID FROM {$wpdb->posts}_ll_tools_quiz_sync_fail_open_missing";
            }

            return $sql;
        };

        $previousSuppressErrors = $wpdb->suppress_errors(true);
        add_filter('query', $breakPrimaryWordQuery);
        try {
            $result = $callback();
        } finally {
            remove_filter('query', $breakPrimaryWordQuery);
            $wpdb->suppress_errors($previousSuppressErrors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected, 'The fixture must interrupt the target category word query.');
        return $result;
    }

    /** @return int[] */
    private function activeQuizPageIds(int $categoryId): array
    {
        return array_values(array_map('intval', ll_tools_get_quiz_page_ids_for_category(
            $categoryId,
            ['publish', 'draft', 'pending', 'private'],
            true
        )));
    }

    private function installCompleteOwnerMap(): void
    {
        $sentinel = '__ll_tools_quiz_fail_open_missing__' . wp_generate_uuid4();
        foreach ([
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
        ] as $optionName) {
            $value = get_option($optionName, $sentinel);
            $this->ownerMapSnapshot[$optionName] = [
                'exists' => $value !== $sentinel,
                'value' => $value,
            ];
        }

        $previousMap = $this->ownerMapSnapshot[LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION]['value'];
        $normalizedMap = is_array($previousMap)
            ? ll_tools_specific_wrong_answer_owner_map_normalize($previousMap)
            : [];
        $generation = 'quiz-sync-' . substr(md5(wp_generate_uuid4()), 0, 20);
        update_option(
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION,
            ll_tools_specific_wrong_answer_owner_map_pack($normalizedMap, $generation),
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
