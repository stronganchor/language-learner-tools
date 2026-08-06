<?php
declare(strict_types=1);

final class WordsetIsolationMigrationTest extends LL_Tools_TestCase
{
    private const ONE_PIXEL_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_install_user_progress_schema')) {
            ll_tools_install_user_progress_schema();
        }
    }

    protected function tearDown(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION);
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT);
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_VOCAB_LESSON_AUTO_REPAIR_TRANSIENT);
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT);
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_LOCK);
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_LOCK);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_STATE_OPTION);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_STATE_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_VOCAB_REPAIR_HOOK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        delete_transient(LL_TOOLS_QUIZ_PAGE_SYNC_LOCK);
        delete_transient(LL_TOOLS_VOCAB_LESSON_SYNC_LOCK);
        delete_option('ll_vocab_lesson_wordsets');
        delete_option('ll_tools_word_option_rules');
        if (function_exists('ll_tools_reset_category_maintenance_runtime')) {
            ll_tools_reset_category_maintenance_runtime();
        }

        parent::tearDown();
    }

    public function test_find_existing_word_post_by_title_in_wordsets_stays_inside_requested_scope(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one = $this->ensure_term('wordset', 'Isolation Scope One', 'isolation-scope-one');
        $wordset_two = $this->ensure_term('wordset', 'Isolation Scope Two', 'isolation-scope-two');

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Shared Scope Title',
        ]);
        wp_set_object_terms($word_one, [$wordset_one], 'wordset', false);

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Shared Scope Title',
        ]);
        wp_set_object_terms($word_two, [$wordset_two], 'wordset', false);

        $resolved_one = ll_tools_find_existing_word_post_by_title_in_wordsets('Shared Scope Title', [$wordset_one]);
        $resolved_two = ll_tools_find_existing_word_post_by_title_in_wordsets('Shared Scope Title', [$wordset_two]);
        $resolved_none = ll_tools_find_existing_word_post_by_title_in_wordsets('Shared Scope Title', [999999]);

        $this->assertInstanceOf(WP_Post::class, $resolved_one);
        $this->assertSame((int) $word_one, (int) $resolved_one->ID);
        $this->assertInstanceOf(WP_Post::class, $resolved_two);
        $this->assertSame((int) $word_two, (int) $resolved_two->ID);
        $this->assertNull($resolved_none);
    }

    public function test_wordset_isolation_migration_duplicates_shared_categories_and_images_per_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_one = $this->ensure_term('wordset', 'Isolation Migration One', 'isolation-migration-one');
        $wordset_two = $this->ensure_term('wordset', 'Isolation Migration Two', 'isolation-migration-two');
        $shared_category = $this->ensure_term('word-category', 'Shared Migration Category', 'shared-migration-category');

        $attachment_id = $this->create_image_attachment('wordset-isolation-migration.png');

        $legacy_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Shared Migration Image',
        ]);
        set_post_thumbnail($legacy_image_id, $attachment_id);
        wp_set_object_terms($legacy_image_id, [$shared_category], 'word-category', false);

        $word_one = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Migration Word One',
        ]);
        set_post_thumbnail($word_one, $attachment_id);
        wp_set_object_terms($word_one, [$shared_category], 'word-category', false);
        wp_set_object_terms($word_one, [$wordset_one], 'wordset', false);

        $word_two = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Migration Word Two',
        ]);
        set_post_thumbnail($word_two, $attachment_id);
        wp_set_object_terms($word_two, [$shared_category], 'word-category', false);
        wp_set_object_terms($word_two, [$wordset_two], 'wordset', false);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $result = ll_tools_run_wordset_isolation_migration();

        $category_one = ll_tools_get_existing_isolated_category_copy_id($shared_category, $wordset_one);
        $category_two = ll_tools_get_existing_isolated_category_copy_id($shared_category, $wordset_two);
        $image_one = ll_tools_get_existing_isolated_word_image_copy_id($legacy_image_id, $wordset_one);
        $image_two = ll_tools_get_existing_isolated_word_image_copy_id($legacy_image_id, $wordset_two);

        $this->assertGreaterThan(0, $category_one);
        $this->assertGreaterThan(0, $category_two);
        $this->assertNotSame($category_one, $category_two);
        $this->assertGreaterThan(0, $image_one);
        $this->assertGreaterThan(0, $image_two);
        $this->assertNotSame($image_one, $image_two);

        $this->assertSame($wordset_one, ll_tools_get_category_wordset_owner_id($category_one));
        $this->assertSame($wordset_two, ll_tools_get_category_wordset_owner_id($category_two));
        $this->assertSame($wordset_one, ll_tools_get_word_image_wordset_owner_id($image_one));
        $this->assertSame($wordset_two, ll_tools_get_word_image_wordset_owner_id($image_two));

        $image_one_categories = wp_get_post_terms($image_one, 'word-category', ['fields' => 'ids']);
        $image_two_categories = wp_get_post_terms($image_two, 'word-category', ['fields' => 'ids']);
        $this->assertContains($category_one, array_map('intval', (array) $image_one_categories));
        $this->assertContains($category_two, array_map('intval', (array) $image_two_categories));

        $word_one_categories = wp_get_post_terms($word_one, 'word-category', ['fields' => 'ids']);
        $word_two_categories = wp_get_post_terms($word_two, 'word-category', ['fields' => 'ids']);
        $this->assertContains($category_one, array_map('intval', (array) $word_one_categories));
        $this->assertContains($category_two, array_map('intval', (array) $word_two_categories));
        $this->assertSame($image_one, (int) get_post_meta($word_one, '_ll_autopicked_image_id', true));
        $this->assertSame($image_two, (int) get_post_meta($word_two, '_ll_autopicked_image_id', true));

        $this->assertGreaterThanOrEqual(2, (int) ($result['categories_created'] ?? 0));
        $this->assertGreaterThanOrEqual(2, (int) ($result['images_created'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($result['images_relinked'] ?? 0));
    }

    public function test_migration_defers_eager_category_maintenance_and_queues_bounded_reconciliation(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Deferred Migration Wordset', 'deferred-migration-wordset');
        $category_id = $this->ensure_term('word-category', 'Deferred Migration Category', 'deferred-migration-category');
        $this->createWordInWordsetCategory('Deferred Migration Word', $wordset_id, $category_id);

        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        ll_tools_reset_category_maintenance_runtime();

        $created_category_deferred_states = [];
        $observe_created_category = static function () use (&$created_category_deferred_states): void {
            $created_category_deferred_states[] = ll_tools_category_maintenance_is_deferred();
        };
        $unbounded_count_queries = [];
        $observe_queries = static function (string $sql) use (&$unbounded_count_queries): string {
            if (
                strpos($sql, 'COUNT(DISTINCT posts.ID) AS total') !== false
                && strpos($sql, 'category_taxonomy.term_id IN') === false
            ) {
                $unbounded_count_queries[] = $sql;
            }
            return $sql;
        };

        add_action('created_word-category', $observe_created_category, 1);
        add_filter('query', $observe_queries);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        try {
            $result = ll_tools_run_wordset_isolation_migration();
        } finally {
            remove_action('created_word-category', $observe_created_category, 1);
            remove_filter('query', $observe_queries);
        }

        $this->assertSame('completed', $result['status']);
        $this->assertNotEmpty($created_category_deferred_states);
        $this->assertNotContains(false, $created_category_deferred_states);
        $this->assertSame([], $unbounded_count_queries);

        $this->assertFalse(get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, false));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));

        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $quiz_sync_state = get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, []);
        $vocab_sync_state = get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, []);
        $this->assertSame('queued', (string) ($quiz_sync_state['status'] ?? ''));
        $this->assertSame('queued', (string) ($vocab_sync_state['status'] ?? ''));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $reconciliation_state = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $this->assertSame('launched', (string) ($reconciliation_state['status'] ?? ''));
        $this->assertSame(
            (string) ($reconciliation_state['token'] ?? ''),
            (string) ($quiz_sync_state['wordset_isolation_reconciliation_token'] ?? '')
        );
        $this->assertSame(
            (string) ($reconciliation_state['token'] ?? ''),
            (string) ($vocab_sync_state['wordset_isolation_reconciliation_token'] ?? '')
        );
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));

        $quiz_sync_state['status'] = 'completed';
        $quiz_sync_state['completed_at'] = time();
        $vocab_sync_state['status'] = 'completed';
        $vocab_sync_state['completed_at'] = time();
        update_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, $quiz_sync_state, false);
        update_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, $vocab_sync_state, false);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $this->assertFalse(get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));

        $maintenance_runtime = ll_tools_get_category_maintenance_runtime();
        $this->assertSame(0, (int) ($maintenance_runtime['defer_depth'] ?? -1));
        $this->assertSame([], (array) ($maintenance_runtime['queued_category_ids'] ?? []));
    }

    public function test_generated_page_reconciliation_waits_for_existing_workers_then_starts_fresh_passes(): void
    {
        $wordset_id = $this->ensure_term(
            'wordset',
            'Deferred Reconciliation Wordset',
            'deferred-reconciliation-wordset'
        );
        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);

        $existing_quiz_state = [
            'status' => 'running',
            'phase' => 'sync',
            'cursor' => 321,
            'queued_at' => 123,
        ];
        $existing_vocab_state = [
            'status' => 'queued',
            'phase' => 'sync',
            'wordset_ids' => [$wordset_id],
            'wordset_index' => 1,
            'category_cursor' => 654,
            'queued_at' => 456,
        ];
        update_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, $existing_quiz_state, false);
        update_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, $existing_vocab_state, false);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);

        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $this->assertSame($existing_quiz_state, get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION));
        $this->assertSame($existing_vocab_state, get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));

        update_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, ['status' => 'completed'], false);
        update_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, ['status' => 'completed'], false);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);

        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $fresh_quiz_state = get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, []);
        $fresh_vocab_state = get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, []);
        $this->assertSame('queued', (string) ($fresh_quiz_state['status'] ?? ''));
        $this->assertSame(0, (int) ($fresh_quiz_state['cursor'] ?? -1));
        $this->assertSame('queued', (string) ($fresh_vocab_state['status'] ?? ''));
        $this->assertSame(0, (int) ($fresh_vocab_state['wordset_index'] ?? -1));
        $this->assertSame(0, (int) ($fresh_vocab_state['category_cursor'] ?? -1));
        $this->assertContains($wordset_id, array_map('intval', (array) ($fresh_vocab_state['wordset_ids'] ?? [])));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $fresh_request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $this->assertSame('launched', (string) ($fresh_request['status'] ?? ''));
        $this->assertSame(
            (string) ($fresh_request['token'] ?? ''),
            (string) ($fresh_quiz_state['wordset_isolation_reconciliation_token'] ?? '')
        );
        $this->assertSame(
            (string) ($fresh_request['token'] ?? ''),
            (string) ($fresh_vocab_state['wordset_isolation_reconciliation_token'] ?? '')
        );
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));

        $fresh_quiz_state['status'] = 'completed';
        $fresh_vocab_state['status'] = 'completed';
        update_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, $fresh_quiz_state, false);
        update_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, $fresh_vocab_state, false);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $this->assertFalse(get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
    }

    public function test_reconciliation_hook_scheduling_failure_prevents_version_publication(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'queued';
        $state['phase'] = 'finalize';
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);

        $reject_owned_event = static function ($pre, $event) {
            return is_object($event) && (string) ($event->hook ?? '') === LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK
                ? false
                : $pre;
        };
        add_filter('pre_schedule_event', $reject_owned_event, 10, 2);
        try {
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_filter('pre_schedule_event', $reject_owned_event, 10);
        }

        $this->assertSame('failed', (string) ($result['status'] ?? ''));
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
        $this->assertFalse(get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
    }

    public function test_child_event_scheduling_failure_retains_durable_reconciliation_intent(): void
    {
        $wordset_id = $this->ensure_term(
            'wordset',
            'Partial Reconciliation Wordset',
            'partial-reconciliation-wordset'
        );
        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        $reject_vocab_event = static function ($pre, $event) {
            return is_object($event) && (string) ($event->hook ?? '') === LL_TOOLS_VOCAB_LESSON_SYNC_EVENT
                ? false
                : $pre;
        };
        add_filter('pre_schedule_event', $reject_vocab_event, 10, 2);
        try {
            ll_tools_wordset_isolation_run_bounded_category_reconciliation();
        } finally {
            remove_filter('pre_schedule_event', $reject_vocab_event, 10);
        }

        $request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $this->assertSame('waiting', (string) ($request['status'] ?? ''));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $this->assertSame(
            (string) ($request['token'] ?? ''),
            (string) (get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, [])['wordset_isolation_reconciliation_token'] ?? '')
        );
    }

    public function test_reconciliation_request_supersedes_every_prior_generation(): void
    {
        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        $first = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $first['status'] = 'launched';
        $first['quiz_queued_at'] = 123;
        update_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, $first, false);

        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        $second = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);

        $this->assertNotSame((string) ($first['token'] ?? ''), (string) ($second['token'] ?? ''));
        $this->assertSame('waiting', (string) ($second['status'] ?? ''));
        $this->assertSame(0, (int) ($second['quiz_queued_at'] ?? -1));
        $this->assertSame(0, (int) ($second['vocab_queued_at'] ?? -1));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
    }

    public function test_reconciliation_refuses_to_tag_a_scoped_vocab_worker_as_the_full_fresh_pass(): void
    {
        $wordset_one = $this->ensure_term('wordset', 'Full Pass Wordset One', 'full-pass-wordset-one');
        $wordset_two = $this->ensure_term('wordset', 'Full Pass Wordset Two', 'full-pass-wordset-two');
        update_option('ll_vocab_lesson_wordsets', [$wordset_one, $wordset_two], false);
        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        $request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);

        $scoped_state = [
            'status' => 'queued',
            'phase' => 'sync',
            'wordset_ids' => [$wordset_one],
            'wordset_index' => 0,
            'category_cursor' => 0,
            'queued_at' => time(),
        ];
        update_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, $scoped_state, false);
        wp_schedule_single_event(time() + 1, LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);

        $tagged_at = ll_tools_wordset_isolation_reconciliation_tag_fresh_child(
            LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION,
            (string) ($request['token'] ?? ''),
            (int) ($request['requested_at'] ?? 0),
            LL_TOOLS_VOCAB_LESSON_SYNC_EVENT,
            'vocab'
        );

        $this->assertSame(0, $tagged_at);
        $stored = get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, []);
        $this->assertArrayNotHasKey('wordset_isolation_reconciliation_token', $stored);
    }

    public function test_reconciliation_worker_lock_contention_keeps_owned_retry_pending(): void
    {
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        set_transient(LL_TOOLS_QUIZ_PAGE_SYNC_LOCK, 1, MINUTE_IN_SECONDS);
        try {
            ll_tools_wordset_isolation_run_bounded_category_reconciliation();
        } finally {
            delete_transient(LL_TOOLS_QUIZ_PAGE_SYNC_LOCK);
        }

        $request = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $this->assertSame('waiting', (string) ($request['status'] ?? ''));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
        $this->assertFalse(get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, false));
    }

    public function test_launched_reconciliation_repairs_a_stranded_child_event(): void
    {
        delete_option('ll_vocab_lesson_wordsets');
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        $this->assertTrue(ll_tools_wordset_isolation_request_bounded_category_reconciliation());
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $before = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $this->assertSame('launched', (string) ($before['status'] ?? ''));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));

        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        delete_transient(LL_TOOLS_QUIZ_PAGE_SYNC_LOCK);
        ll_tools_wordset_isolation_run_bounded_category_reconciliation();

        $after = get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, []);
        $this->assertSame('launched', (string) ($after['status'] ?? ''));
        $this->assertSame((string) ($before['token'] ?? ''), (string) ($after['token'] ?? ''));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
    }

    public function test_migration_discards_only_its_categories_inside_an_outer_deferral_scope(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Nested Deferral Wordset', 'nested-deferral-wordset');
        $category_id = $this->ensure_term('word-category', 'Nested Deferral Category', 'nested-deferral-category');
        $this->createWordInWordsetCategory('Nested Deferral Word', $wordset_id, $category_id);

        ll_tools_reset_category_maintenance_runtime();
        ll_tools_begin_deferred_category_maintenance('outer-test-scope');
        ll_tools_queue_deferred_category_maintenance([$category_id]);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        try {
            $result = ll_tools_run_wordset_isolation_migration();
            $runtime = ll_tools_get_category_maintenance_runtime();
        } finally {
            ll_tools_end_deferred_category_maintenance(false);
        }

        $this->assertSame('completed', (string) ($result['status'] ?? ''));
        $this->assertSame(1, (int) ($runtime['defer_depth'] ?? -1));
        $this->assertSame([$category_id => true], (array) ($runtime['queued_category_ids'] ?? []));
    }

    public function test_wordset_isolation_migration_resumes_in_bounded_keyset_batches(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Bounded Isolation Wordset', 'bounded-isolation-wordset');
        $category_id = $this->ensure_term('word-category', 'Bounded Isolation Category', 'bounded-isolation-category');
        for ($index = 1; $index <= 5; $index++) {
            $this->createWordInWordsetCategory('Bounded Isolation Word ' . $index, $wordset_id, $category_id);
        }

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $batch_size = static function (): int {
            return 2;
        };
        add_filter('ll_tools_wordset_isolation_migration_batch_size', $batch_size);
        try {
            $queued = ll_tools_queue_wordset_isolation_migration();
            $this->assertSame('queued', $queued['status']);
            $this->assertSame(0, (int) $queued['counters']['words_scanned']);
            $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());

            $first = ll_tools_run_wordset_isolation_migration_batch();
            $this->assertSame('queued', $first['status']);
            $this->assertSame('words', $first['phase']);
            $this->assertSame(2, (int) $first['counters']['words_scanned']);
            $this->assertGreaterThan(0, (int) $first['cursor']);
            $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());

            $first_cursor = (int) $first['cursor'];
            $second = ll_tools_run_wordset_isolation_migration_batch();
            $this->assertSame(4, (int) $second['counters']['words_scanned']);
            $this->assertGreaterThan($first_cursor, (int) $second['cursor']);

            $result = ll_tools_run_wordset_isolation_migration();
        } finally {
            remove_filter('ll_tools_wordset_isolation_migration_batch_size', $batch_size);
        }

        $this->assertSame('completed', $result['status']);
        $this->assertSame(5, (int) $result['words_scanned']);
        $this->assertSame(LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION, ll_tools_get_wordset_isolation_migration_version());
        $this->assertSame('completed', ll_tools_get_wordset_isolation_migration_state()['status']);
    }

    public function test_synchronous_migration_drain_stops_without_progress_when_another_worker_holds_the_lease(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_queue_wordset_isolation_migration();
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, [
            'token' => 'another-worker',
            'expires_at' => time() + 5 * MINUTE_IN_SECONDS,
        ], false);

        $result = ll_tools_run_wordset_isolation_migration();

        $this->assertSame('queued', $result['status']);
        $this->assertSame('words', $result['phase']);
        $this->assertSame(0, (int) $result['words_scanned']);
        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_HOOK));
    }

    public function test_queue_request_does_not_overwrite_an_active_workers_progress(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'images';
        $state['cursor'] = 321;
        $state['counters']['words_scanned'] = 17;
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_LOCK_OPTION, [
            'token' => 'active-worker',
            'expires_at' => time() + 5 * MINUTE_IN_SECONDS,
        ], false);

        $queued = ll_tools_queue_wordset_isolation_migration();
        $stored = ll_tools_get_wordset_isolation_migration_state();

        $this->assertSame('running', $queued['status']);
        $this->assertSame('images', $queued['phase']);
        $this->assertSame(321, (int) $queued['cursor']);
        $this->assertSame(17, (int) $queued['counters']['words_scanned']);
        $this->assertSame('running', $stored['status']);
        $this->assertSame(321, (int) $stored['cursor']);
    }

    public function test_completed_version_marker_recovers_an_interrupted_final_state_save(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'finalize';
        $state['started_at'] = time() - MINUTE_IN_SECONDS;
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);
        update_option(
            LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION,
            LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION,
            false
        );

        $result = ll_tools_run_wordset_isolation_migration_batch();
        $stored = ll_tools_get_wordset_isolation_migration_state();

        $this->assertSame('completed', $result['status']);
        $this->assertSame('finalize', $result['phase']);
        $this->assertGreaterThan(0, (int) $result['completed_at']);
        $this->assertSame('completed', $stored['status']);
        $this->assertGreaterThan(0, (int) $stored['completed_at']);
    }

    public function test_migration_does_not_advance_after_an_isolated_category_write_is_lost(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Isolation Write Failure', 'isolation-write-failure');
        $category_id = $this->ensure_term('word-category', 'Isolation Write Failure Category', 'isolation-write-failure-category');
        $word_id = $this->createWordInWordsetCategory('Isolation Write Failure Word', $wordset_id, $category_id);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $discard_category_write = static function ($object_id, $terms, $tt_ids, $taxonomy) use ($wpdb, $word_id): void {
            if ((int) $object_id !== $word_id || $taxonomy !== 'word-category') {
                return;
            }
            $wpdb->query($wpdb->prepare(
                "DELETE relationships
                 FROM {$wpdb->term_relationships} AS relationships
                 INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
                    ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
                 WHERE relationships.object_id = %d
                   AND taxonomy.taxonomy = 'word-category'",
                $word_id
            ));
            clean_object_term_cache($word_id, 'words');
        };
        add_action('set_object_terms', $discard_category_write, PHP_INT_MAX, 6);
        try {
            ll_tools_queue_wordset_isolation_migration();
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_action('set_object_terms', $discard_category_write, PHP_INT_MAX);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('words', $result['phase']);
        $this->assertSame(0, (int) $result['cursor']);
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
        $this->assertStringContainsString('could not be saved', strtolower((string) $result['last_error']));
    }

    public function test_wordset_isolation_migration_repairs_category_ordering_meta_to_isolated_category_ids(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Isolation Ordering Repair', 'isolation-ordering-repair');
        $root_category_id = $this->ensure_term('word-category', 'Zulu Root', 'zulu-root');
        $advanced_category_id = $this->ensure_term('word-category', 'Alpha Advanced', 'alpha-advanced');

        update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordset_id, 'll_wordset_category_manual_order', [$advanced_category_id, $root_category_id]);
        update_term_meta($wordset_id, 'll_wordset_category_prerequisites', [
            $advanced_category_id => [$root_category_id],
        ]);

        $root_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Isolation Root Word',
        ]);
        wp_set_object_terms($root_word_id, [$root_category_id], 'word-category', false);
        wp_set_object_terms($root_word_id, [$wordset_id], 'wordset', false);

        $advanced_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Isolation Advanced Word',
        ]);
        wp_set_object_terms($advanced_word_id, [$advanced_category_id], 'word-category', false);
        wp_set_object_terms($advanced_word_id, [$wordset_id], 'wordset', false);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $result = ll_tools_run_wordset_isolation_migration();

        $isolated_root_category_id = ll_tools_get_existing_isolated_category_copy_id($root_category_id, $wordset_id);
        $isolated_advanced_category_id = ll_tools_get_existing_isolated_category_copy_id($advanced_category_id, $wordset_id);

        $this->assertGreaterThan(0, $isolated_root_category_id);
        $this->assertGreaterThan(0, $isolated_advanced_category_id);
        $this->assertNotSame($isolated_root_category_id, $root_category_id);
        $this->assertNotSame($isolated_advanced_category_id, $advanced_category_id);

        $this->assertSame(
            [$isolated_advanced_category_id, $isolated_root_category_id],
            get_term_meta($wordset_id, 'll_wordset_category_manual_order', true)
        );
        $this->assertSame(
            [$isolated_advanced_category_id => [$isolated_root_category_id]],
            get_term_meta($wordset_id, 'll_wordset_category_prerequisites', true)
        );

        $ordered_category_ids = ll_tools_wordset_sort_category_ids(
            [$isolated_advanced_category_id, $isolated_root_category_id],
            $wordset_id
        );
        $this->assertSame(
            [$isolated_root_category_id, $isolated_advanced_category_id],
            $ordered_category_ids
        );
        $this->assertGreaterThanOrEqual(1, (int) ($result['wordsets_repaired'] ?? 0));
    }

    public function test_wordset_isolation_migration_repairs_word_option_rule_scopes_and_lesson_category_meta(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Isolation Word Options', 'isolation-word-options');
        $legacy_category_id = $this->ensure_term('word-category', 'Isolation Word Options Category', 'isolation-word-options-category');

        $word_one_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Word Option One',
        ]);
        wp_set_object_terms($word_one_id, [$legacy_category_id], 'word-category', false);
        wp_set_object_terms($word_one_id, [$wordset_id], 'wordset', false);

        $word_two_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Word Option Two',
        ]);
        wp_set_object_terms($word_two_id, [$legacy_category_id], 'word-category', false);
        wp_set_object_terms($word_two_id, [$wordset_id], 'wordset', false);

        update_option('ll_tools_word_option_rules', [
            $wordset_id => [
                $legacy_category_id => [
                    'groups' => [[
                        'label' => 'Manual Pair Group',
                        'word_ids' => [$word_one_id, $word_two_id],
                    ]],
                    'pairs' => [[
                        'word_ids' => [$word_one_id, $word_two_id],
                    ]],
                ],
            ],
        ], false);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Isolation Word Options Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $legacy_category_id);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $result = ll_tools_run_wordset_isolation_migration();

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($legacy_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        $this->assertNotSame($legacy_category_id, $isolated_category_id);
        $stored_lesson_category_id = (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true);
        $resolved_lesson_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($stored_lesson_category_id, $wordset_id, true)
            : $stored_lesson_category_id;
        $this->assertSame($isolated_category_id, $resolved_lesson_category_id);

        $rules = ll_tools_get_word_option_rules($wordset_id, $isolated_category_id);
        $this->assertCount(1, $rules['groups']);
        $this->assertSame('Manual Pair Group', (string) ($rules['groups'][0]['label'] ?? ''));
        $this->assertCount(1, $rules['pairs']);
        $this->assertSame(
            $this->normalizePairWordIds($word_one_id, $word_two_id),
            array_map('intval', (array) ($rules['pairs'][0]['word_ids'] ?? []))
        );

        $store = get_option('ll_tools_word_option_rules', []);
        $this->assertArrayHasKey($wordset_id, $store);
        $this->assertArrayHasKey($isolated_category_id, $store[$wordset_id]);
        $this->assertArrayNotHasKey($legacy_category_id, $store[$wordset_id]);
        $this->assertGreaterThanOrEqual(1, (int) ($result['word_option_rule_scopes_repaired'] ?? 0));

        $lesson_context = ll_tools_word_option_rules_get_lesson_context($lesson_id);
        $this->assertSame($isolated_category_id, (int) ($lesson_context['category_id'] ?? 0));
        $this->assertSame($isolated_category_id, (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true));

        $iframe_url = ll_tools_word_option_rules_build_iframe_url($lesson_id);
        $this->assertNotSame('', $iframe_url);
        $this->assertStringContainsString('category_id=' . $isolated_category_id, $iframe_url);
        $this->assertStringContainsString('wordset_id=' . $wordset_id, $iframe_url);
    }

    public function test_vocab_lesson_creation_normalizes_category_meta_to_owned_copy_after_isolation(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Isolation Lesson Sync', 'isolation-lesson-sync');
        $legacy_category_id = $this->ensure_term('word-category', 'Isolation Lesson Sync Category', 'isolation-lesson-sync-category');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Isolation Lesson Sync Word',
        ]);
        wp_set_object_terms($word_id, [$legacy_category_id], 'word-category', false);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($legacy_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);

        $created = ll_tools_get_or_create_vocab_lesson_page($legacy_category_id, $wordset_id);
        $this->assertIsArray($created);

        $lesson_id = (int) ($created['post_id'] ?? 0);
        $this->assertGreaterThan(0, $lesson_id);
        $this->assertSame($isolated_category_id, (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true));
    }

    public function test_bulk_vocab_lesson_repair_fixes_stale_category_meta_without_rerunning_full_migration(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_id = $this->ensure_term('wordset', 'Isolation Lesson Repair', 'isolation-lesson-repair');
        $legacy_category_id = $this->ensure_term('word-category', 'Isolation Lesson Repair Category', 'isolation-lesson-repair-category');

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Isolation Lesson Repair Word',
        ]);
        wp_set_object_terms($word_id, [$legacy_category_id], 'word-category', false);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION, LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION, false);

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($legacy_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Isolation Lesson Repair Target',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $legacy_category_id);

        $repaired = ll_tools_repair_all_vocab_lesson_category_meta_for_isolation();

        $this->assertSame(1, $repaired);
        $this->assertSame($isolated_category_id, (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true));
    }

    public function test_health_notice_cache_miss_does_not_run_full_collectors(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        set_current_screen('dashboard');
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT);

        $collector_queries = 0;
        $watch_queries = static function (WP_Query $query) use (&$collector_queries): void {
            if (in_array($query->get('post_type'), ['words', 'word_images', 'll_vocab_lesson'], true)) {
                $collector_queries++;
            }
        };
        add_action('pre_get_posts', $watch_queries);
        try {
            ob_start();
            ll_tools_render_wordset_isolation_health_notice();
            $html = (string) ob_get_clean();
        } finally {
            remove_action('pre_get_posts', $watch_queries);
            set_current_screen('front');
        }

        $this->assertSame(0, $collector_queries);
        $this->assertStringContainsString('Check now', $html);
        $this->assertStringContainsString('ll_tools_queue_wordset_isolation_health_refresh', $html);
        $this->assertFalse(get_transient(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REPORT_TRANSIENT));
        $this->assertFalse(has_action('admin_init', 'll_tools_maybe_auto_repair_vocab_lesson_category_meta_for_isolation'));
        $this->assertNotFalse(has_action(LL_TOOLS_WORDSET_ISOLATION_HEALTH_REFRESH_HOOK, 'll_tools_run_wordset_isolation_health_refresh'));
    }

    public function test_vocab_lesson_repair_job_uses_bounded_cursor_batches(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Isolation Repair Job', 'isolation-repair-job');
        $legacy_category_id = $this->ensure_term('word-category', 'Isolation Repair Job Category', 'isolation-repair-job-category');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Isolation Repair Job Word',
        ]);
        wp_set_object_terms($word_id, [$legacy_category_id], 'word-category', false);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();
        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($legacy_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);

        $lesson_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_vocab_lesson',
                'post_status' => 'publish',
                'post_title' => 'Isolation Repair Job Lesson ' . $index,
            ]);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $legacy_category_id);
            $lesson_ids[] = $lesson_id;
        }

        $batch_size = static function (): int {
            return 2;
        };
        add_filter('ll_tools_wordset_isolation_vocab_repair_batch_size', $batch_size);
        try {
            ll_tools_queue_wordset_isolation_vocab_repair();
            $first = ll_tools_run_wordset_isolation_vocab_repair_batch();
            $second = ll_tools_run_wordset_isolation_vocab_repair_batch();
            $third = ll_tools_run_wordset_isolation_vocab_repair_batch();
        } finally {
            remove_filter('ll_tools_wordset_isolation_vocab_repair_batch_size', $batch_size);
        }

        $this->assertSame('queued', (string) $first['status']);
        $this->assertSame(2, (int) $first['processed']);
        $this->assertSame('queued', (string) $second['status']);
        $this->assertSame(4, (int) $second['processed']);
        $this->assertSame('completed', (string) $third['status']);
        $this->assertSame(5, (int) $third['processed']);
        $this->assertSame(5, (int) $third['repaired']);
        foreach ($lesson_ids as $lesson_id) {
            $this->assertSame($isolated_category_id, (int) get_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true));
        }
        $this->assertSame('queued', (string) ll_tools_get_wordset_isolation_health_refresh_state()['status']);
    }

    public function test_wordset_isolation_migration_repairs_user_study_and_recommendation_category_meta(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Isolation Study State', 'isolation-study-state');
        $legacy_category_id = $this->ensure_term('word-category', 'Isolation Study Category', 'isolation-study-category');

        $word_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $word_ids[] = $this->createWordInWordsetCategory('Isolation Study Word ' . $index, $wordset_id, $legacy_category_id);
        }

        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, [$legacy_category_id]);
        update_user_meta($user_id, LL_TOOLS_USER_GOALS_META, [
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [$legacy_category_id],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [$legacy_category_id],
            'daily_new_word_target' => 2,
        ]);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, [
            $legacy_category_id => [
                'category_id' => $legacy_category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => 4,
                'exposure_by_mode' => [
                    'practice' => 4,
                ],
                'last_mode' => 'practice',
                'last_seen_at' => '2026-04-14 10:00:00',
            ],
        ]);

        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$legacy_category_id],
            'session_word_ids' => $word_ids,
            'details' => [],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, [
            (string) $wordset_id => [$activity],
        ]);
        update_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, [
            (string) $wordset_id => $activity,
        ]);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $result = ll_tools_run_wordset_isolation_migration();

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($legacy_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        $this->assertGreaterThanOrEqual(5, (int) ($result['user_data_repaired'] ?? 0));

        $state = ll_tools_get_user_study_state($user_id);
        $this->assertSame([$isolated_category_id], array_map('intval', (array) ($state['category_ids'] ?? [])));

        $goals = ll_tools_get_user_study_goals($user_id);
        $this->assertSame([$isolated_category_id], array_map('intval', (array) ($goals['ignored_category_ids'] ?? [])));
        $this->assertSame([$isolated_category_id], array_map('intval', (array) ($goals['placement_known_category_ids'] ?? [])));

        $progress = ll_tools_get_user_category_progress($user_id);
        $this->assertArrayHasKey($isolated_category_id, $progress);
        $this->assertArrayNotHasKey($legacy_category_id, $progress);
        $this->assertSame(4, (int) ($progress[$isolated_category_id]['exposure_total'] ?? 0));

        $queue = ll_tools_get_user_recommendation_queue($user_id, $wordset_id);
        $this->assertNotEmpty($queue);
        $this->assertSame([$isolated_category_id], array_map('intval', (array) ($queue[0]['category_ids'] ?? [])));
        $this->assertNotSame('', (string) ($queue[0]['queue_id'] ?? ''));

        $last_activity = ll_tools_get_user_last_recommendation_activity($user_id, $wordset_id);
        $this->assertIsArray($last_activity);
        $this->assertSame([$isolated_category_id], array_map('intval', (array) ($last_activity['category_ids'] ?? [])));

        $this->assertSame([$isolated_category_id], array_map('intval', (array) get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true)));
        $this->assertArrayHasKey($isolated_category_id, (array) get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
        $this->assertArrayHasKey((string) $wordset_id, (array) get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
    }

    public function test_deleted_selected_categories_are_dropped_while_live_siblings_are_remapped(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Selected Category Cleanup', 'selected-category-cleanup');
        $live_category_id = $this->ensure_term('word-category', 'Selected Live Category', 'selected-live-category');
        $deleted_category_id = $this->ensure_term('word-category', 'Selected Deleted Category', 'selected-deleted-category');
        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $isolated_category_id = ll_tools_get_or_create_isolated_category_copy($live_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, [$isolated_category_id, $deleted_category_id]);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame([$isolated_category_id], get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true));
        $this->assertSame(1, (int) ($state['counters']['user_data_repaired'] ?? 0));
    }

    public function test_all_deleted_selected_categories_are_cleared(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Deleted Selection Cleanup', 'deleted-selection-cleanup');
        $deleted_category_id = $this->ensure_term('word-category', 'Deleted Selection Category', 'deleted-selection-category');
        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, [$deleted_category_id]);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame([], get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true));
        $this->assertSame(1, (int) ($state['counters']['user_data_repaired'] ?? 0));
    }

    public function test_selected_category_lookup_error_fails_without_pruning_user_meta(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Selection Lookup Failure', 'selection-lookup-failure');
        $category_id = $this->ensure_term('word-category', 'Selection Lookup Failure Category', 'selection-lookup-failure-category');
        $stored_categories = [$category_id];
        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, $stored_categories);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertGreaterThan(0, ll_tools_get_or_create_isolated_category_copy($category_id, $wordset_id));

        $break_lookup = static function (string $query) use ($wpdb): string {
            if (strpos($query, 'll-tools-existing-category-preflight') === false) {
                return $query;
            }
            return str_replace($wpdb->term_taxonomy, $wpdb->term_taxonomy . '_missing', $query);
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 91;
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_lookup);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(91, (int) $state['cursor']);
        $this->assertSame($stored_categories, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true));
    }

    public function test_deleted_category_recommendation_activities_are_dropped_while_valid_siblings_are_remapped(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Stale Recommendation Wordset', 'stale-recommendation-wordset');
        $live_category_id = $this->ensure_term('word-category', 'Live Recommendation Category', 'live-recommendation-category');
        $deleted_category_id = $this->ensure_term('word-category', 'Deleted Recommendation Category', 'deleted-recommendation-category');
        $word_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $word_ids[] = $this->createWordInWordsetCategory(
                'Stale Recommendation Word ' . $index,
                $wordset_id,
                $live_category_id
            );
        }

        $valid_activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$live_category_id],
            'session_word_ids' => $word_ids,
            'details' => [],
        ];
        $deleted_activity = $valid_activity;
        $deleted_activity['category_ids'] = [$deleted_category_id];
        $mixed_activity = $valid_activity;
        $mixed_activity['category_ids'] = [$live_category_id, $deleted_category_id];

        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, [
            (string) $wordset_id => [$valid_activity, $deleted_activity, $mixed_activity],
        ]);
        update_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, [
            (string) $wordset_id => $deleted_activity,
        ]);
        $deleted = wp_delete_term($deleted_category_id, 'word-category');
        $this->assertNotWPError($deleted);
        $this->assertNotFalse($deleted);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $this->assertSame('valid', ll_tools_recommendation_activity_category_reference_state_for_isolation($valid_activity));
        $this->assertSame('missing', ll_tools_recommendation_activity_category_reference_state_for_isolation($deleted_activity));
        $this->assertSame('missing', ll_tools_recommendation_activity_category_reference_state_for_isolation($mixed_activity));
        $this->assertNull(ll_tools_repair_recommendation_activity_for_isolation($deleted_activity, $wordset_id));
        $this->assertNull(ll_tools_repair_recommendation_activity_for_isolation($mixed_activity, $wordset_id));

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('running', $state['status']);

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($live_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        $stored_queues = get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true);
        $this->assertIsArray($stored_queues);
        $this->assertArrayHasKey((string) $wordset_id, $stored_queues);
        $this->assertCount(1, $stored_queues[(string) $wordset_id]);
        $this->assertSame([$isolated_category_id], array_map('intval', (array) ($stored_queues[(string) $wordset_id][0]['category_ids'] ?? [])));
        $this->assertSame($word_ids, array_map('intval', (array) ($stored_queues[(string) $wordset_id][0]['session_word_ids'] ?? [])));
        $this->assertNotSame('', (string) ($stored_queues[(string) $wordset_id][0]['queue_id'] ?? ''));
        $this->assertArrayNotHasKey((string) $wordset_id, (array) get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true));
        $this->assertSame(2, (int) ($state['counters']['user_data_repaired'] ?? 0));

        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, [
            (string) $wordset_id => [$deleted_activity],
        ]);
        update_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, [
            (string) $wordset_id => $deleted_activity,
        ]);
        $this->assertSame([], ll_tools_get_user_recommendation_queue($user_id, $wordset_id));
        $this->assertSame([], (array) (get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true)[(string) $wordset_id] ?? []));
        $this->assertNull(ll_tools_get_user_last_recommendation_activity($user_id, $wordset_id));
        $this->assertSame('', get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true));
    }

    public function test_deleted_category_recommendation_cleanup_preserves_a_concurrent_queue_write(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Stale Recommendation CAS Wordset', 'stale-recommendation-cas-wordset');
        $deleted_category_id = $this->ensure_term('word-category', 'Stale Recommendation CAS Category', 'stale-recommendation-cas-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$deleted_category_id],
            'session_word_ids' => [101, 102, 103, 104, 105],
            'details' => [],
        ];
        $before = [(string) $wordset_id => [$activity]];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $before);
        $deleted = wp_delete_term($deleted_category_id, 'word-category');
        $this->assertNotWPError($deleted);
        $this->assertNotFalse($deleted);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $concurrent_activity = $activity;
        $concurrent_activity['category_ids'] = [];
        $concurrent_activity['reason_code'] = 'concurrent_refresh';
        $concurrent_value = [(string) $wordset_id => [$concurrent_activity]];
        $interleave = null;
        $observed_previous = null;
        $interleave = static function ($check, $object_id, $meta_key, $meta_value, $previous_value) use (&$interleave, &$observed_previous, $user_id, $concurrent_value) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_USER_RECOMMENDATION_QUEUE_META) {
                return $check;
            }
            remove_filter('update_user_metadata', $interleave, 10);
            $observed_previous = $previous_value;
            update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $concurrent_value);
            return $check;
        };

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 77;
        add_filter('update_user_metadata', $interleave, 10, 5);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('update_user_metadata', $interleave, 10);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(77, (int) $state['cursor']);
        $this->assertSame($before, $observed_previous);
        $this->assertSame($concurrent_value, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
    }

    public function test_recommendation_category_lookup_errors_never_trigger_partial_stale_cleanup(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recommendation Lookup Error Wordset', 'recommendation-lookup-error-wordset');
        $first_category_id = $this->ensure_term('word-category', 'Recommendation Lookup First', 'recommendation-lookup-first');
        $error_category_id = $this->ensure_term('word-category', 'Recommendation Lookup Error', 'recommendation-lookup-error');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$first_category_id, $error_category_id],
            'session_word_ids' => [201, 202, 203, 204, 205],
            'details' => [],
        ];
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $force_lookup_error = static function ($term, $taxonomy) use ($error_category_id) {
            if (
                $taxonomy === 'word-category'
                && $term instanceof WP_Term
                && (int) $term->term_id === $error_category_id
            ) {
                return new WP_Error('forced_recommendation_category_lookup_error');
            }
            return $term;
        };

        add_filter('get_term', $force_lookup_error, 10, 2);
        try {
            $this->assertSame('valid', ll_tools_recommendation_activity_category_reference_state_for_isolation($activity));
            $repaired = ll_tools_repair_recommendation_activity_for_isolation($activity, $wordset_id);
            $state = ll_tools_wordset_isolation_migration_new_state();
            $state['status'] = 'running';
            $state['phase'] = 'users';
            $preflight = ll_tools_wordset_isolation_migration_preflight_recommendation_activity(
                $activity,
                $wordset_id,
                $user_id,
                $state
            );
        } finally {
            remove_filter('get_term', $force_lookup_error, 10);
        }

        $this->assertIsArray($repaired);
        $this->assertSame([$first_category_id, $error_category_id], array_map('intval', (array) ($repaired['category_ids'] ?? [])));
        $this->assertFalse($preflight);
        $this->assertSame('failed', $state['status']);
        $this->assertGreaterThan(0, ll_tools_get_existing_isolated_category_copy_id($first_category_id, $wordset_id));
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($error_category_id, $wordset_id));
    }

    public function test_recommendation_category_database_errors_fail_closed_without_partial_cleanup(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recommendation Database Error Wordset', 'recommendation-database-error-wordset');
        $category_id = $this->ensure_term('word-category', 'Recommendation Database Error Category', 'recommendation-database-error-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => [211, 212, 213, 214, 215],
            'details' => [],
        ];
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $failed_queries = 0;
        $break_lookup = static function (string $sql) use ($wpdb, &$failed_queries): string {
            if (strpos($sql, 'll_tools_recommendation_category_reference_state') === false) {
                return $sql;
            }
            $failed_queries++;
            return "SELECT ll_tools_missing_category_column FROM {$wpdb->term_taxonomy} /* ll_tools_recommendation_category_reference_state */";
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_lookup);
        try {
            $reference_state = ll_tools_recommendation_activity_category_reference_state_for_isolation($activity);
            $repaired = ll_tools_repair_recommendation_activity_for_isolation($activity, $wordset_id);
            $preflight = ll_tools_wordset_isolation_migration_preflight_recommendation_activity(
                $activity,
                $wordset_id,
                $user_id,
                $state
            );
        } finally {
            remove_filter('query', $break_lookup);
            $wpdb->get_var('SELECT 1');
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertSame('error', $reference_state);
        $this->assertIsArray($repaired);
        $this->assertSame([$category_id], array_map('intval', (array) ($repaired['category_ids'] ?? [])));
        $this->assertFalse($preflight);
        $this->assertSame('failed', $state['status']);
        $this->assertGreaterThanOrEqual(3, $failed_queries);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($category_id, $wordset_id));
    }

    public function test_recommendation_repair_time_database_error_stops_migration_without_writing(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recommendation Repair Error Wordset', 'recommendation-repair-error-wordset');
        $category_id = $this->ensure_term('word-category', 'Recommendation Repair Error Category', 'recommendation-repair-error-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => [216, 217, 218, 219, 220],
            'details' => [],
        ];
        $before = [(string) $wordset_id => [$activity]];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $before);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $classifier_queries = 0;
        $break_repair_lookup = static function (string $sql) use ($wpdb, &$classifier_queries): string {
            if (strpos($sql, 'll_tools_recommendation_category_reference_state') === false) {
                return $sql;
            }
            $classifier_queries++;
            if ($classifier_queries === 3) {
                return "SELECT ll_tools_missing_category_column FROM {$wpdb->term_taxonomy} /* ll_tools_recommendation_category_reference_state */";
            }
            return $sql;
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_repair_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_repair_lookup);
            $wpdb->get_var('SELECT 1');
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(3, $classifier_queries);
        $this->assertSame($before, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
    }

    public function test_recommendation_partial_mapping_failure_preserves_the_complete_category_list(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recommendation Complete Mapping Wordset', 'recommendation-complete-mapping-wordset');
        $first_category_id = $this->ensure_term('word-category', 'Recommendation Complete Mapping First', 'recommendation-complete-mapping-first');
        $second_category_id = $this->ensure_term('word-category', 'Recommendation Complete Mapping Second', 'recommendation-complete-mapping-second');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$first_category_id, $second_category_id],
            'session_word_ids' => [221, 222, 223, 224, 225],
            'details' => [],
        ];
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $second_slug = ll_tools_build_isolated_category_slug('recommendation-complete-mapping-second', $wordset_id);
        $blocked_writes = 0;
        $drop_second_owner = static function ($check, $object_id, $meta_key, $meta_value) use ($wordset_id, $second_slug, &$blocked_writes) {
            if ($meta_key !== LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY || (int) $meta_value !== $wordset_id) {
                return $check;
            }
            $term = get_term((int) $object_id, 'word-category');
            if ($term instanceof WP_Term && $term->slug === $second_slug) {
                $blocked_writes++;
                return true;
            }
            return $check;
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        add_filter('update_term_metadata', $drop_second_owner, 10, 4);
        try {
            $repaired = ll_tools_repair_recommendation_activity_for_isolation($activity, $wordset_id);
            $preflight = ll_tools_wordset_isolation_migration_preflight_recommendation_activity(
                $activity,
                $wordset_id,
                $user_id,
                $state
            );
        } finally {
            remove_filter('update_term_metadata', $drop_second_owner, 10);
        }

        $this->assertIsArray($repaired);
        $this->assertSame(
            [$first_category_id, $second_category_id],
            array_map('intval', (array) ($repaired['category_ids'] ?? []))
        );
        $this->assertFalse($preflight);
        $this->assertSame('failed', $state['status']);
        $this->assertGreaterThanOrEqual(1, $blocked_writes);
        $this->assertGreaterThan(0, ll_tools_get_existing_isolated_category_copy_id($first_category_id, $wordset_id));
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($second_category_id, $wordset_id));
    }

    public function test_runtime_recommendation_queue_repair_preserves_a_concurrent_refresh(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Runtime Queue CAS Wordset', 'runtime-queue-cas-wordset');
        $deleted_category_id = $this->ensure_term('word-category', 'Runtime Queue CAS Category', 'runtime-queue-cas-category');
        $stale_activity = ll_tools_normalize_recommendation_activity([
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$deleted_category_id],
            'session_word_ids' => [231, 232, 233, 234, 235],
            'details' => [],
        ]);
        $before = [(string) $wordset_id => [$stale_activity]];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $before);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $concurrent_activity = ll_tools_normalize_recommendation_activity([
            'type' => 'review_chunk',
            'reason_code' => 'concurrent_refresh',
            'mode' => 'practice',
            'category_ids' => [],
            'session_word_ids' => [241, 242, 243, 244, 245],
            'details' => [],
        ]);
        $concurrent_value = [(string) $wordset_id => [$concurrent_activity]];
        $observed_previous = null;
        $raw_interleave_writes = null;
        $interleave = null;
        $interleave = static function ($check, $object_id, $meta_key, $meta_value, $previous_value) use ($wpdb, &$interleave, &$observed_previous, &$raw_interleave_writes, $user_id, $concurrent_value) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_USER_RECOMMENDATION_QUEUE_META) {
                return $check;
            }
            remove_filter('update_user_metadata', $interleave, 10);
            $observed_previous = $previous_value;
            // Simulate another PHP request: update the row without invalidating
            // this request's user-meta cache.
            $raw_interleave_writes = $wpdb->update(
                $wpdb->usermeta,
                ['meta_value' => maybe_serialize($concurrent_value)],
                [
                    'user_id' => $user_id,
                    'meta_key' => LL_TOOLS_USER_RECOMMENDATION_QUEUE_META,
                ],
                ['%s'],
                ['%d', '%s']
            );
            return $check;
        };
        add_filter('update_user_metadata', $interleave, 10, 5);
        try {
            $queue = ll_tools_get_user_recommendation_queue($user_id, $wordset_id);
        } finally {
            remove_filter('update_user_metadata', $interleave, 10);
        }

        $this->assertSame($before, $observed_previous);
        $this->assertSame(1, $raw_interleave_writes);
        $this->assertNotEmpty($queue);
        $this->assertSame('concurrent_refresh', (string) ($queue[0]['reason_code'] ?? ''));
        $this->assertSame([241, 242, 243, 244, 245], array_map('intval', (array) ($queue[0]['session_word_ids'] ?? [])));
        $this->assertSame($concurrent_value, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
    }

    public function test_runtime_last_recommendation_cleanup_preserves_a_concurrent_refresh(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Runtime Last CAS Wordset', 'runtime-last-cas-wordset');
        $deleted_category_id = $this->ensure_term('word-category', 'Runtime Last CAS Category', 'runtime-last-cas-category');
        $stale_activity = ll_tools_normalize_recommendation_activity([
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$deleted_category_id],
            'session_word_ids' => [251, 252, 253, 254, 255],
            'details' => [],
        ]);
        $before = [(string) $wordset_id => $stale_activity];
        update_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, $before);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $concurrent_activity = ll_tools_normalize_recommendation_activity([
            'type' => 'review_chunk',
            'reason_code' => 'concurrent_refresh',
            'mode' => 'practice',
            'category_ids' => [],
            'session_word_ids' => [261, 262, 263, 264, 265],
            'details' => [],
        ]);
        $concurrent_value = [(string) $wordset_id => $concurrent_activity];
        $observed_previous = null;
        $raw_interleave_writes = null;
        $interleave = null;
        $interleave = static function ($delete, $object_id, $meta_key, $meta_value) use ($wpdb, &$interleave, &$observed_previous, &$raw_interleave_writes, $user_id, $concurrent_value) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_USER_LAST_RECOMMENDATION_META) {
                return $delete;
            }
            remove_filter('delete_user_metadata', $interleave, 10);
            $observed_previous = $meta_value;
            $raw_interleave_writes = $wpdb->update(
                $wpdb->usermeta,
                ['meta_value' => maybe_serialize($concurrent_value)],
                [
                    'user_id' => $user_id,
                    'meta_key' => LL_TOOLS_USER_LAST_RECOMMENDATION_META,
                ],
                ['%s'],
                ['%d', '%s']
            );
            return $delete;
        };
        add_filter('delete_user_metadata', $interleave, 10, 5);
        try {
            $activity = ll_tools_get_user_last_recommendation_activity($user_id, $wordset_id);
        } finally {
            remove_filter('delete_user_metadata', $interleave, 10);
        }

        $this->assertSame($before, $observed_previous);
        $this->assertSame(1, $raw_interleave_writes);
        $this->assertIsArray($activity);
        $this->assertSame('concurrent_refresh', (string) ($activity['reason_code'] ?? ''));
        $this->assertSame([261, 262, 263, 264, 265], array_map('intval', (array) ($activity['session_word_ids'] ?? [])));
        $this->assertSame($concurrent_value, get_user_meta($user_id, LL_TOOLS_USER_LAST_RECOMMENDATION_META, true));
    }

    public function test_recommendation_category_and_queue_inspection_limits_fail_closed(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recommendation Bounds Wordset', 'recommendation-bounds-wordset');
        $category_id = $this->ensure_term('word-category', 'Recommendation Bounds Category', 'recommendation-bounds-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => array_fill(0, 31, $category_id),
            'session_word_ids' => [271, 272, 273, 274, 275],
            'details' => [],
        ];
        $queue_activity = $activity;
        $queue_activity['category_ids'] = [$category_id];
        $oversized_queue = array_fill(0, 17, $queue_activity);
        $stored = [(string) $wordset_id => $oversized_queue];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $stored);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $reference_queries = 0;
        $observe_queries = static function (string $sql) use (&$reference_queries): string {
            if (strpos($sql, 'll_tools_recommendation_category_reference_state') !== false) {
                $reference_queries++;
            }
            return $sql;
        };
        add_filter('query', $observe_queries);
        try {
            $this->assertSame('error', ll_tools_recommendation_activity_category_reference_state_for_isolation($activity));
            $queue = ll_tools_get_user_recommendation_queue($user_id, $wordset_id);
            $state = ll_tools_wordset_isolation_migration_new_state();
            $state['status'] = 'running';
            $state['phase'] = 'users';
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $observe_queries);
        }

        $this->assertSame([], $queue);
        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(0, $reference_queries);
        $this->assertSame($stored, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
    }

    public function test_exact_recommendation_snapshot_is_repreflighted_before_migration_write(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recommendation Snapshot Wordset', 'recommendation-snapshot-wordset');
        $category_id = $this->ensure_term('word-category', 'Recommendation Snapshot Category', 'recommendation-snapshot-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => [281, 282, 283, 284, 285],
            'details' => [],
        ];
        $before = [(string) $wordset_id => [$activity]];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $before);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $invalid_activity = $activity;
        $invalid_activity['category_ids'] = array_fill(0, 31, $category_id);
        $concurrent_value = [(string) $wordset_id => [$invalid_activity]];
        $queue_reads = 0;
        $interleave = null;
        $interleave = static function ($value, $object_id, $meta_key) use (&$interleave, &$queue_reads, $user_id, $concurrent_value) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_USER_RECOMMENDATION_QUEUE_META) {
                return $value;
            }
            $queue_reads++;
            if ($queue_reads === 2) {
                remove_filter('get_user_metadata', $interleave, 10);
                update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, $concurrent_value);
            }
            return $value;
        };
        add_filter('get_user_metadata', $interleave, 10, 5);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('get_user_metadata', $interleave, 10);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertGreaterThanOrEqual(2, $queue_reads);
        $this->assertSame($concurrent_value, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_QUEUE_META, true));
    }

    public function test_owned_goal_categories_do_not_require_copies_in_unrelated_preferred_wordsets(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $owner_wordset_id = $this->ensure_term('wordset', 'Owned Goal Category Wordset', 'owned-goal-category-wordset');
        $preferred_wordset_id = $this->ensure_term('wordset', 'Unrelated Preferred Wordset', 'unrelated-preferred-wordset');
        $source_category_id = $this->ensure_term('word-category', 'Owned Goal Category', 'owned-goal-category');

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $owned_category_id = ll_tools_get_or_create_isolated_category_copy($source_category_id, $owner_wordset_id);
        $this->assertGreaterThan(0, $owned_category_id);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $preferred_wordset_id));

        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $owner_wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_GOALS_META, [
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [$owned_category_id],
            'preferred_wordset_ids' => [$preferred_wordset_id],
            'placement_known_category_ids' => [$owned_category_id],
            'daily_new_word_target' => 2,
        ]);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('running', $state['status']);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $preferred_wordset_id));

        $goals = get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true);
        $this->assertSame([$owned_category_id], array_map('intval', (array) ($goals['ignored_category_ids'] ?? [])));
        $this->assertSame([$owned_category_id], array_map('intval', (array) ($goals['placement_known_category_ids'] ?? [])));
        $this->assertSame([$preferred_wordset_id], array_map('intval', (array) ($goals['preferred_wordset_ids'] ?? [])));
    }

    public function test_legacy_goal_categories_expand_only_to_existing_family_copies(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $first_wordset_id = $this->ensure_term('wordset', 'Legacy Goal First Wordset', 'legacy-goal-first-wordset');
        $second_wordset_id = $this->ensure_term('wordset', 'Legacy Goal Second Wordset', 'legacy-goal-second-wordset');
        $unrelated_wordset_id = $this->ensure_term('wordset', 'Legacy Goal Unrelated Wordset', 'legacy-goal-unrelated-wordset');
        $source_category_id = $this->ensure_term('word-category', 'Legacy Goal Category', 'legacy-goal-category');

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $first_copy_id = ll_tools_get_or_create_isolated_category_copy($source_category_id, $first_wordset_id);
        $second_copy_id = ll_tools_get_or_create_isolated_category_copy($source_category_id, $second_wordset_id);
        $this->assertGreaterThan(0, $first_copy_id);
        $this->assertGreaterThan(0, $second_copy_id);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $unrelated_wordset_id));

        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $first_wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_GOALS_META, [
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [$source_category_id],
            'preferred_wordset_ids' => [$unrelated_wordset_id],
            'placement_known_category_ids' => [$source_category_id],
            'daily_new_word_target' => 2,
        ]);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('running', $state['status']);
        $this->assertSame(1, (int) ($state['counters']['user_data_repaired'] ?? 0));
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $unrelated_wordset_id));

        $expected_ids = [$first_copy_id, $second_copy_id];
        sort($expected_ids, SORT_NUMERIC);
        $goals = get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true);
        $ignored_ids = array_map('intval', (array) ($goals['ignored_category_ids'] ?? []));
        $placement_ids = array_map('intval', (array) ($goals['placement_known_category_ids'] ?? []));
        sort($ignored_ids, SORT_NUMERIC);
        sort($placement_ids, SORT_NUMERIC);
        $this->assertSame($expected_ids, $ignored_ids);
        $this->assertSame($expected_ids, $placement_ids);
        $this->assertSame([$unrelated_wordset_id], array_map('intval', (array) ($goals['preferred_wordset_ids'] ?? [])));
    }

    public function test_goal_category_preflight_rejects_missing_terms_without_writing(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $missing_category_id = $this->ensure_term('word-category', 'Missing Goal Category', 'missing-goal-category');
        $this->assertNotWPError(wp_delete_term($missing_category_id, 'word-category'));
        $stored_goals = [
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [$missing_category_id],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [$missing_category_id],
            'daily_new_word_target' => 2,
        ];
        update_user_meta($user_id, LL_TOOLS_USER_GOALS_META, $stored_goals);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 77;
        $this->assertFalse(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('failed', $state['status']);
        $this->assertSame(77, (int) $state['cursor']);
        $this->assertSame($stored_goals, get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true));
    }

    public function test_goal_category_preflight_rejects_owned_copies_with_missing_owner_metadata(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Malformed Goal Owner Wordset', 'malformed-goal-owner-wordset');
        $source_category_id = $this->ensure_term('word-category', 'Malformed Goal Owner Category', 'malformed-goal-owner-category');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $copy_id = ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_id);
        $this->assertGreaterThan(0, $copy_id);
        $this->assertSame($source_category_id, ll_tools_get_category_isolation_source_id($copy_id));
        delete_term_meta($copy_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY);
        $this->assertSame(0, ll_tools_get_category_wordset_owner_id($copy_id));

        $stored_goals = [
            'enabled_modes' => ['practice'],
            'ignored_category_ids' => [$copy_id],
            'preferred_wordset_ids' => [],
            'placement_known_category_ids' => [$copy_id],
            'daily_new_word_target' => 2,
        ];
        update_user_meta($user_id, LL_TOOLS_USER_GOALS_META, $stored_goals);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 88;
        $this->assertFalse(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('failed', $state['status']);
        $this->assertSame(88, (int) $state['cursor']);
        $this->assertSame($stored_goals, get_user_meta($user_id, LL_TOOLS_USER_GOALS_META, true));
    }

    public function test_deleted_category_progress_is_preserved_exactly_without_a_user_meta_write(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Historical Progress Wordset', 'historical-progress-wordset');
        $deleted_category_id = $this->ensure_term('word-category', 'Historical Deleted Category', 'historical-deleted-category');
        $entry = [
            'category_id' => $deleted_category_id,
            'wordset_id' => $wordset_id,
            'exposure_total' => 339,
            'exposure_by_mode' => [
                'learning' => 0,
                'practice' => 278,
                'listening' => 46,
                'gender' => 0,
                'self-check' => 15,
            ],
            'last_mode' => 'practice',
            'last_seen_at' => '2026-05-25 19:47:28',
        ];
        $stored_progress = [$deleted_category_id => $entry];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $writes = 0;
        $watch_write = static function ($check, $object_id, $meta_key) use (&$writes, $user_id) {
            if ((int) $object_id === $user_id && $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                $writes++;
            }
            return $check;
        };
        add_filter('update_user_metadata', $watch_write, 10, 3);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('update_user_metadata', $watch_write, 10);
        }

        $this->assertTrue($processed);
        $this->assertSame('running', $state['status']);
        $this->assertSame(0, $writes);
        $this->assertSame(0, (int) ($state['counters']['user_data_repaired'] ?? -1));
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_deleted_category_progress_preserves_a_legacy_entry_shape_without_writing(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Malformed Historical Progress Wordset', 'malformed-historical-progress-wordset');
        $deleted_category_id = $this->ensure_term('word-category', 'Malformed Historical Category', 'malformed-historical-category');
        $stored_progress = [
            $deleted_category_id => [
                'category_id' => $deleted_category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => 4,
                'exposure_by_mode' => ['practice' => 4],
                'last_mode' => 'practice',
                'last_seen_at' => '2026-05-25 19:47:28',
                'legacy_counter' => 'kept-verbatim',
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $writes = 0;
        $watch_write = static function ($check, $object_id, $meta_key) use (&$writes, $user_id) {
            if ((int) $object_id === $user_id && $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                $writes++;
            }
            return $check;
        };
        add_filter('update_user_metadata', $watch_write, 10, 3);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('update_user_metadata', $watch_write, 10);
        }

        $this->assertTrue($processed);
        $this->assertSame('running', $state['status']);
        $this->assertSame(0, $writes);
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_deleted_category_progress_rejects_a_recoverable_family_copy(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Recoverable Historical Progress Wordset', 'recoverable-historical-progress-wordset');
        $source_category_id = $this->ensure_term('word-category', 'Recoverable Historical Category', 'recoverable-historical-category');
        $copy_category_id = $this->ensure_term('word-category', 'Recoverable Historical Copy', 'recoverable-historical-copy');
        ll_tools_set_category_wordset_owner($copy_category_id, $wordset_id, $source_category_id);
        $entry = [
            'category_id' => $source_category_id,
            'wordset_id' => $wordset_id,
            'exposure_total' => 8,
            'exposure_by_mode' => array_fill_keys(ll_tools_progress_modes(), 0),
            'last_mode' => 'practice',
            'last_seen_at' => '2026-05-25 19:47:28',
        ];
        $entry['exposure_by_mode']['practice'] = 8;
        $stored_progress = [$source_category_id => $entry];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        $this->assertNotWPError(wp_delete_term($source_category_id, 'word-category'));
        $this->assertInstanceOf(WP_Term::class, get_term($copy_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertFalse(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('failed', $state['status']);
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_deleted_category_progress_rejects_an_ownerless_family_copy(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Ownerless Historical Progress Wordset', 'ownerless-historical-progress-wordset');
        $source_category_id = $this->ensure_term('word-category', 'Ownerless Historical Category', 'ownerless-historical-category');
        $copy_category_id = $this->ensure_term('word-category', 'Ownerless Historical Copy', 'ownerless-historical-copy');
        ll_tools_set_category_wordset_owner($copy_category_id, $wordset_id, $source_category_id);
        delete_term_meta($copy_category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY);
        $entry = [
            'category_id' => $source_category_id,
            'wordset_id' => $wordset_id,
            'exposure_total' => 8,
            'exposure_by_mode' => array_fill_keys(ll_tools_progress_modes(), 0),
            'last_mode' => 'practice',
            'last_seen_at' => '2026-05-25 19:47:28',
        ];
        $entry['exposure_by_mode']['practice'] = 8;
        $stored_progress = [$source_category_id => $entry];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        $this->assertNotWPError(wp_delete_term($source_category_id, 'word-category'));
        $this->assertSame(0, ll_tools_get_category_wordset_owner_id($copy_category_id));
        $this->assertTrue(ll_tools_wordset_isolation_migration_has_source_category_family_row($source_category_id));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertFalse(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('failed', $state['status']);
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_mixed_category_progress_remaps_live_rows_and_preserves_deleted_history(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Mixed Historical Progress Wordset', 'mixed-historical-progress-wordset');
        $live_category_id = $this->ensure_term('word-category', 'Mixed Live Category', 'mixed-live-category');
        $deleted_category_id = $this->ensure_term('word-category', 'Mixed Deleted Category', 'mixed-deleted-category');
        $make_entry = static function (int $category_id, int $total) use ($wordset_id): array {
            $by_mode = array_fill_keys(ll_tools_progress_modes(), 0);
            $by_mode['practice'] = $total;
            return [
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => $total,
                'exposure_by_mode' => $by_mode,
                'last_mode' => 'practice',
                'last_seen_at' => '2026-05-25 19:47:28',
            ];
        };
        $deleted_entry = $make_entry($deleted_category_id, 19);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, [
            $live_category_id => $make_entry($live_category_id, 7),
            $deleted_category_id => $deleted_entry,
        ]);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($live_category_id, $wordset_id));

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));

        $live_copy_id = ll_tools_get_existing_isolated_category_copy_id($live_category_id, $wordset_id);
        $this->assertGreaterThan(0, $live_copy_id);
        $progress = get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true);
        $this->assertArrayHasKey($live_copy_id, $progress);
        $this->assertArrayNotHasKey($live_category_id, $progress);
        $this->assertSame(7, (int) ($progress[$live_copy_id]['exposure_total'] ?? 0));
        $this->assertArrayHasKey($deleted_category_id, $progress);
        $this->assertSame($deleted_entry, $progress[$deleted_category_id]);
    }

    public function test_category_progress_lookup_error_fails_without_writing_an_empty_store(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Progress Lookup Failure', 'progress-lookup-failure');
        $category_id = $this->ensure_term('word-category', 'Progress Lookup Failure Category', 'progress-lookup-failure-category');
        $stored_progress = [
            $category_id => [
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => 3,
                'exposure_by_mode' => ['practice' => 3],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertGreaterThan(0, ll_tools_get_or_create_isolated_category_copy($category_id, $wordset_id));

        $break_lookup = static function (string $query) use ($wpdb): string {
            if (strpos($query, 'll-tools-existing-category-preflight') === false) {
                return $query;
            }
            return str_replace($wpdb->term_taxonomy, $wpdb->term_taxonomy . '_missing', $query);
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 94;
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_lookup);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(94, (int) $state['cursor']);
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_deleted_category_progress_family_lookup_error_fails_closed(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Progress Family Lookup Failure', 'progress-family-lookup-failure');
        $category_id = $this->ensure_term('word-category', 'Progress Family Lookup Failure Category', 'progress-family-lookup-failure-category');
        $entry = [
            'category_id' => $category_id,
            'wordset_id' => $wordset_id,
            'exposure_total' => 5,
            'exposure_by_mode' => ['practice' => 5],
            'legacy_shape' => true,
        ];
        $stored_progress = [$category_id => $entry];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        $this->assertNotWPError(wp_delete_term($category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $break_family_lookup = static function (string $query) use ($wpdb): string {
            if (strpos($query, 'll-tools-category-family-preflight') === false) {
                return $query;
            }
            return str_replace($wpdb->termmeta, $wpdb->termmeta . '_missing', $query);
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 95;
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_family_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_family_lookup);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(95, (int) $state['cursor']);
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_category_progress_source_error_never_becomes_an_empty_user_meta_write(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Progress Source Failure', 'progress-source-failure');
        $category_id = $this->ensure_term('word-category', 'Progress Source Failure Category', 'progress-source-failure-category');
        $stored_progress = [
            $category_id => [
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => 6,
                'exposure_by_mode' => ['practice' => 6],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, $stored_progress);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertGreaterThan(0, ll_tools_get_or_create_isolated_category_copy($category_id, $wordset_id));

        $writes = 0;
        $watch_write = static function ($check, $object_id, $meta_key) use (&$writes, $user_id) {
            if ((int) $object_id === $user_id && $meta_key === LL_TOOLS_USER_CATEGORY_PROGRESS_META) {
                $writes++;
            }
            return $check;
        };
        $inject_source_error = static function ($terms) {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
                if (($frame['function'] ?? '') === 'll_tools_repair_user_category_progress_store_for_isolation') {
                    ll_tools_user_progress_mark_source_error('migration_category_progress_test');
                    break;
                }
            }
            return $terms;
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 96;
        add_filter('update_user_metadata', $watch_write, 10, 3);
        add_filter('get_terms', $inject_source_error);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('get_terms', $inject_source_error);
            remove_filter('update_user_metadata', $watch_write, 10);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(96, (int) $state['cursor']);
        $this->assertSame(0, $writes);
        $this->assertNull(ll_tools_user_progress_get_source_error());
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_PROGRESS_META, true));
    }

    public function test_prompt_progress_remaps_live_rows_and_preserves_deleted_legacy_entries(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Mixed Prompt Progress Wordset', 'mixed-prompt-progress-wordset');
        $live_category_id = $this->ensure_term('word-category', 'Mixed Prompt Live Category', 'mixed-prompt-live-category');
        $deleted_category_id = $this->ensure_term('word-category', 'Mixed Prompt Deleted Category', 'mixed-prompt-deleted-category');
        $live_entry = [
            'prompt_card_id' => 98761,
            'category_id' => $live_category_id,
            'wordset_id' => $wordset_id,
            'exposure_total' => 2,
            'updated_at' => '2026-07-14 10:00:00',
        ];
        $deleted_entry = [
            'prompt_card_id' => 98762,
            'category_id' => $deleted_category_id,
            'wordset_id' => $wordset_id,
            'exposure_total' => 11,
            'legacy_prompt_shape' => ['kept' => true],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, [
            98761 => $live_entry,
            98762 => $deleted_entry,
        ]);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $live_copy_id = ll_tools_get_or_create_isolated_category_copy($live_category_id, $wordset_id);
        $this->assertGreaterThan(0, $live_copy_id);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));

        $progress = get_user_meta($user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, true);
        $live_entry['category_id'] = $live_copy_id;
        $this->assertSame($live_entry, $progress[98761]);
        $this->assertSame($deleted_entry, $progress[98762]);
    }

    public function test_deleted_prompt_progress_family_lookup_error_fails_closed_without_writing(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Prompt Family Lookup Failure', 'prompt-family-lookup-failure');
        $category_id = $this->ensure_term('word-category', 'Prompt Family Lookup Failure Category', 'prompt-family-lookup-failure-category');
        $stored_progress = [
            98763 => [
                'prompt_card_id' => 98763,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => 4,
                'legacy_prompt_shape' => ['kept' => true],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, $stored_progress);
        $this->assertNotWPError(wp_delete_term($category_id, 'word-category'));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $writes = 0;
        $watch_write = static function ($check, $object_id, $meta_key) use (&$writes, $user_id) {
            if ((int) $object_id === $user_id && $meta_key === LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META) {
                $writes++;
            }
            return $check;
        };
        $break_family_lookup = static function (string $query) use ($wpdb): string {
            if (strpos($query, 'll-tools-category-family-preflight') === false) {
                return $query;
            }
            return str_replace($wpdb->termmeta, $wpdb->termmeta . '_missing', $query);
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 97;
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('update_user_metadata', $watch_write, 10, 3);
        add_filter('query', $break_family_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_family_lookup);
            remove_filter('update_user_metadata', $watch_write, 10);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(97, (int) $state['cursor']);
        $this->assertSame(0, $writes);
        $this->assertSame($stored_progress, get_user_meta($user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, true));
    }

    public function test_historical_progress_events_stay_visible_when_category_ids_were_saved_before_isolation(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Isolation Analytics', 'isolation-analytics');
        $legacy_category_id = $this->ensure_term('word-category', 'Isolation Analytics Category', 'isolation-analytics-category');
        $word_id = $this->createWordInWordsetCategory('Isolation Analytics Word', $wordset_id, $legacy_category_id);

        $stats = ll_tools_process_progress_events_batch($user_id, [
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'word_exposure',
                'mode' => 'practice',
                'word_id' => $word_id,
                'category_id' => $legacy_category_id,
                'wordset_id' => $wordset_id,
                'payload' => [],
            ],
            [
                'event_uuid' => wp_generate_uuid4(),
                'event_type' => 'mode_session_complete',
                'mode' => 'practice',
                'category_id' => $legacy_category_id,
                'wordset_id' => $wordset_id,
                'payload' => [
                    'category_ids' => [$legacy_category_id],
                ],
            ],
        ]);
        $this->assertSame(2, (int) ($stats['processed'] ?? 0));

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($legacy_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);

        $daily = ll_tools_user_study_daily_activity_series($user_id, $wordset_id, [$isolated_category_id], 7);
        $today = gmdate('Y-m-d');
        $today_row = null;
        foreach ((array) ($daily['days'] ?? []) as $row) {
            if (is_array($row) && (($row['date'] ?? '') === $today)) {
                $today_row = $row;
                break;
            }
        }
        $this->assertIsArray($today_row);
        $this->assertSame(1, (int) ($today_row['rounds'] ?? 0));
        $this->assertSame(1, (int) ($today_row['unique_words'] ?? 0));

        $mode_sessions = ll_tools_user_study_category_mode_session_counts($user_id, $wordset_id, [$isolated_category_id]);
        $this->assertArrayHasKey($isolated_category_id, $mode_sessions);
        $this->assertSame(1, (int) ($mode_sessions[$isolated_category_id]['by_mode']['practice'] ?? 0));
    }

    public function test_word_option_rules_admin_category_dropdown_is_scoped_to_selected_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset_one = $this->ensure_term('wordset', 'Isolation Admin One', 'isolation-admin-one');
        $wordset_two = $this->ensure_term('wordset', 'Isolation Admin Two', 'isolation-admin-two');
        $shared_category_id = $this->ensure_term('word-category', 'Isolation Admin Category', 'isolation-admin-category');

        $this->createWordInWordsetCategory('Isolation Admin Word One', $wordset_one, $shared_category_id);
        $this->createWordInWordsetCategory('Isolation Admin Word Two', $wordset_two, $shared_category_id);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        $isolated_one = ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_one);
        $isolated_two = ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_two);
        $this->assertGreaterThan(0, $isolated_one);
        $this->assertGreaterThan(0, $isolated_two);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $admin_user = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $admin_user);
        $admin_user->add_cap('view_ll_tools');
        wp_set_current_user($admin_id);

        $previous_get = $_GET;
        $_GET = [
            'page' => 'll-word-option-rules',
            'wordset_id' => (string) $wordset_one,
            'category_id' => (string) $shared_category_id,
        ];

        ob_start();
        ll_render_word_option_rules_admin_page();
        $html = (string) ob_get_clean();
        $_GET = $previous_get;

        $this->assertStringContainsString('value="' . $isolated_one . '"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . preg_quote((string) $isolated_one, '/') . '".*selected/', $html);
        $this->assertStringNotContainsString('value="' . $shared_category_id . '"', $html);
        $this->assertStringNotContainsString('value="' . $isolated_two . '"', $html);
    }

    public function test_migration_version_five_requeues_sites_that_already_completed_legacy_version_four(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_VERSION_OPTION, 4, false);
        $legacy_state = ll_tools_wordset_isolation_migration_new_state();
        $legacy_state['target_version'] = 4;
        $legacy_state['status'] = 'completed';
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $legacy_state, false);

        $queued = ll_tools_queue_wordset_isolation_migration();

        $this->assertSame(5, LL_TOOLS_WORDSET_ISOLATION_CURRENT_MIGRATION_VERSION);
        $this->assertSame('queued', $queued['status']);
        $this->assertSame(5, (int) $queued['target_version']);
        $this->assertSame(4, ll_tools_get_wordset_isolation_migration_version());
    }

    public function test_user_discovery_repairs_prompt_progress_and_rekeys_recommendation_deferrals(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Isolated Auxiliary User Stores', 'isolated-auxiliary-user-stores');
        $category_id = $this->ensure_term('word-category', 'Auxiliary User Store Category', 'auxiliary-user-store-category');
        $prompt_user_id = self::factory()->user->create(['role' => 'subscriber']);
        $deferral_user_id = self::factory()->user->create(['role' => 'subscriber']);

        $prompt_progress = [
            98765 => [
                'prompt_card_id' => 98765,
                'category_id' => $category_id,
                'wordset_id' => $wordset_id,
                'exposure_total' => 2,
                'updated_at' => '2026-07-14 10:00:00',
            ],
        ];
        update_user_meta($prompt_user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, $prompt_progress);

        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => [321, 654, 987, 111, 222],
        ];
        $old_signature = ll_tools_recommendation_activity_queue_id($activity);
        $legacy_signature = 'legacy-without-session-words';
        update_user_meta($deferral_user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, [
            (string) $wordset_id => [
                $old_signature => [
                    'count' => 2,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => [$category_id],
                    'session_word_ids' => $activity['session_word_ids'],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
                $legacy_signature => [
                    'count' => 1,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => [$category_id],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
            ],
        ]);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $result = ll_tools_run_wordset_isolation_migration();

        $isolated_category_id = ll_tools_get_existing_isolated_category_copy_id($category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        $this->assertSame('completed', (string) ($result['status'] ?? ''));
        $this->assertSame(
            $isolated_category_id,
            (int) get_user_meta($prompt_user_id, LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META, true)[98765]['category_id']
        );

        $activity['category_ids'] = [$isolated_category_id];
        $new_signature = ll_tools_recommendation_activity_queue_id($activity);
        $this->assertNotSame($old_signature, $new_signature);
        $deferrals = ll_tools_get_user_recommendation_deferrals($deferral_user_id, $wordset_id);
        $this->assertArrayHasKey($new_signature, $deferrals);
        $this->assertArrayNotHasKey($old_signature, $deferrals);
        $this->assertArrayNotHasKey($legacy_signature, $deferrals);
        $this->assertSame([$isolated_category_id], array_map('intval', (array) $deferrals[$new_signature]['category_ids']));
        $this->assertSame([321, 654, 987, 111, 222], array_map('intval', (array) $deferrals[$new_signature]['session_word_ids']));
    }

    public function test_deleted_category_deferrals_follow_the_durable_repair_policy(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Deleted Deferral Repair', 'deleted-deferral-repair');
        $live_category_id = $this->ensure_term('word-category', 'Live Deferral Category', 'live-deferral-category');
        $deleted_category_id = $this->ensure_term('word-category', 'Deleted Deferral Category', 'deleted-deferral-category');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);

        $stored_deferrals = [
            (string) $wordset_id => [
                'legacy-mixed-without-session-words' => [
                    'count' => 2,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => [$live_category_id, $deleted_category_id],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
                'all-deleted-with-session-words' => [
                    'count' => 1,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => [$deleted_category_id],
                    'session_word_ids' => [501, 502, 503, 504, 505],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, $stored_deferrals);
        $this->assertNotWPError(wp_delete_term($deleted_category_id, 'word-category'));

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertGreaterThan(0, ll_tools_get_or_create_isolated_category_copy($live_category_id, $wordset_id));
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_user($user_id, $state));

        $this->assertSame([], get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true));
        $this->assertSame(1, (int) ($state['counters']['user_data_repaired'] ?? 0));
    }

    public function test_deferral_category_lookup_error_fails_without_pruning_user_meta(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Deferral Lookup Failure', 'deferral-lookup-failure');
        $category_id = $this->ensure_term('word-category', 'Deferral Lookup Failure Category', 'deferral-lookup-failure-category');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $stored_deferrals = [
            (string) $wordset_id => [
                'deferral-lookup-failure-row' => [
                    'count' => 1,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => [$category_id],
                    'session_word_ids' => [601, 602, 603, 604, 605],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, $stored_deferrals);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertGreaterThan(0, ll_tools_get_or_create_isolated_category_copy($category_id, $wordset_id));

        $break_lookup = static function (string $query) use ($wpdb): string {
            if (strpos($query, 'll-tools-existing-category-preflight') === false) {
                return $query;
            }
            return str_replace($wpdb->term_taxonomy, $wpdb->term_taxonomy . '_missing', $query);
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 93;
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_lookup);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(93, (int) $state['cursor']);
        $this->assertSame($stored_deferrals, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true));
    }

    public function test_deferral_exact_repair_lookup_error_after_preflights_fails_without_a_write(): void
    {
        global $wpdb;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Deferral Exact Repair Failure', 'deferral-exact-repair-failure');
        $category_id = $this->ensure_term('word-category', 'Deferral Exact Repair Category', 'deferral-exact-repair-category');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $stored_deferrals = [
            (string) $wordset_id => [
                'deferral-exact-repair-row' => [
                    'count' => 1,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => [$category_id],
                    'session_word_ids' => [701, 702, 703, 704, 705],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, $stored_deferrals);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $this->assertGreaterThan(0, ll_tools_get_or_create_isolated_category_copy($category_id, $wordset_id));

        $lookup_count = 0;
        $break_exact_lookup = static function (string $query) use ($wpdb, &$lookup_count): string {
            if (strpos($query, 'll-tools-existing-category-preflight') === false) {
                return $query;
            }
            $lookup_count++;
            if ($lookup_count !== 2) {
                return $query;
            }
            return str_replace($wpdb->term_taxonomy, $wpdb->term_taxonomy . '_missing', $query);
        };
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $state['cursor'] = 94;
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_exact_lookup);
        try {
            $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
        } finally {
            remove_filter('query', $break_exact_lookup);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertSame(2, $lookup_count);
        $this->assertFalse($processed);
        $this->assertSame('failed', $state['status']);
        $this->assertSame(94, (int) $state['cursor']);
        $this->assertSame(0, (int) ($state['counters']['user_data_repaired'] ?? 0));
        $this->assertSame($stored_deferrals, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true));
    }

    public function test_oversized_deferral_bucket_fails_closed_without_truncating_user_meta(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Oversized Deferral Bucket', 'oversized-deferral-bucket');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $bucket = [];
        for ($index = 1; $index <= 257; $index++) {
            $bucket['oversized-deferral-' . $index] = [
                'count' => 1,
                'available_after' => '2030-07-14 10:00:00',
                'last_dismissed_at' => '2026-07-14 10:00:00',
                'category_ids' => [],
                'session_word_ids' => [801, 802, 803, 804, 805],
                'mode' => 'practice',
                'type' => 'review_chunk',
            ];
        }
        $stored_deferrals = [(string) $wordset_id => $bucket];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, $stored_deferrals);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertFalse(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('failed', $state['status']);
        $this->assertSame(0, (int) ($state['counters']['user_data_repaired'] ?? 0));
        $this->assertSame($stored_deferrals, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true));
    }

    public function test_oversized_deferral_category_list_fails_closed_without_truncating_user_meta(): void
    {
        $wordset_id = $this->ensure_term('wordset', 'Oversized Deferral Categories', 'oversized-deferral-categories');
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $stored_deferrals = [
            (string) $wordset_id => [
                'oversized-category-list' => [
                    'count' => 1,
                    'available_after' => '2030-07-14 10:00:00',
                    'last_dismissed_at' => '2026-07-14 10:00:00',
                    'category_ids' => range(1, 31),
                    'session_word_ids' => [901, 902, 903, 904, 905],
                    'mode' => 'practice',
                    'type' => 'review_chunk',
                ],
            ],
        ];
        update_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, $stored_deferrals);

        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'running';
        $state['phase'] = 'users';
        $this->assertFalse(ll_tools_wordset_isolation_migration_process_user($user_id, $state));
        $this->assertSame('failed', $state['status']);
        $this->assertSame(0, (int) ($state['counters']['user_data_repaired'] ?? 0));
        $this->assertSame($stored_deferrals, get_user_meta($user_id, LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META, true));
    }

    public function test_migration_skips_legacy_words_without_a_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $category_id = $this->ensure_term('word-category', 'No Wordset Migration Category', 'no-wordset-migration-category');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'No Wordset Migration Word',
        ]);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $result = ll_tools_run_wordset_isolation_migration();

        $this->assertSame('completed', $result['status']);
        $this->assertGreaterThanOrEqual(1, (int) $result['words_scanned']);
        $this->assertSame([$category_id], array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids'])));
    }

    public function test_migration_expands_an_existing_isolated_category_to_a_new_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_one = $this->ensure_term('wordset', 'Expansion Wordset One', 'expansion-wordset-one');
        $wordset_two = $this->ensure_term('wordset', 'Expansion Wordset Two', 'expansion-wordset-two');
        $source_category_id = $this->ensure_term('word-category', 'Expansion Source Category', 'expansion-source-category');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Expansion Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_one], 'wordset', false);
        wp_set_object_terms($word_id, [$source_category_id], 'word-category', false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $first_state = ll_tools_wordset_isolation_migration_new_state();
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_word($word_id, $first_state));
        $first_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $this->assertCount(1, $first_categories);
        $this->assertSame($wordset_one, ll_tools_get_category_wordset_owner_id($first_categories[0]));

        wp_set_object_terms($word_id, [$wordset_one, $wordset_two], 'wordset', false);
        $assignment_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $this->assertCount(2, $assignment_categories, 'Adding a wordset should expand existing category sources into the new scope.');
        $expanded_state = ll_tools_wordset_isolation_migration_new_state();
        $this->assertTrue(ll_tools_wordset_isolation_migration_process_word($word_id, $expanded_state));
        $expanded_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $this->assertCount(2, $expanded_categories);
        $owners = array_map('ll_tools_get_category_wordset_owner_id', $expanded_categories);
        sort($owners, SORT_NUMERIC);
        $this->assertSame([$wordset_one, $wordset_two], $owners);
        foreach ($expanded_categories as $category_id) {
            $this->assertSame($source_category_id, ll_tools_get_category_isolation_source_id($category_id));
        }
    }

    public function test_explicit_category_assignment_stays_independent_until_missing_scope_expansion(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_one = $this->ensure_term('wordset', 'Independent Wordset One', 'independent-wordset-one');
        $wordset_two = $this->ensure_term('wordset', 'Independent Wordset Two', 'independent-wordset-two');
        $wordset_three = $this->ensure_term('wordset', 'Independent Wordset Three', 'independent-wordset-three');
        $source_category_id = $this->ensure_term('word-category', 'Independent Source Category', 'independent-source-category');
        ll_tools_set_category_wordset_owner($source_category_id, $wordset_one, $source_category_id);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Independent Category Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_one, $wordset_two], 'wordset', false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        wp_set_object_terms($word_id, [$source_category_id], 'word-category', false);

        $explicit_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $this->assertSame([$source_category_id], $explicit_categories);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $wordset_two));

        wp_set_object_terms($word_id, [$wordset_one, $wordset_two], 'wordset', false);
        $resaved_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $this->assertSame([$source_category_id], $resaved_categories, 'Re-saving unchanged wordsets must preserve an intentionally empty scope.');
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $wordset_two));

        wp_set_object_terms($word_id, [$wordset_two], 'wordset', true);
        $duplicate_append_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $this->assertSame([$source_category_id], $duplicate_append_categories, 'Appending an existing wordset must not fill intentionally empty scopes.');
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $wordset_two));

        wp_set_object_terms($word_id, [$wordset_three], 'wordset', true);
        $new_append_categories = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        $new_append_owners = array_map('ll_tools_get_category_wordset_owner_id', $new_append_categories);
        sort($new_append_owners, SORT_NUMERIC);
        $this->assertSame([$wordset_one, $wordset_three], $new_append_owners, 'Append expansion must target only the newly related wordset.');
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $wordset_two));
        $this->assertGreaterThan(0, ll_tools_get_existing_isolated_category_copy_id($source_category_id, $wordset_three));

        $expanded_categories = ll_tools_normalize_word_categories_for_isolation($word_id, true);
        $this->assertCount(3, $expanded_categories);
        $owners = array_map('ll_tools_get_category_wordset_owner_id', $expanded_categories);
        sort($owners, SORT_NUMERIC);
        $this->assertSame([$wordset_one, $wordset_two, $wordset_three], $owners);
    }

    public function test_migration_discovery_query_failure_does_not_advance_the_phase(): void
    {
        global $wpdb;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_queue_wordset_isolation_migration();
        $break_discovery = static function (string $sql) use ($wpdb): string {
            if (strpos($sql, "FROM {$wpdb->posts}") !== false && strpos($sql, "post_type = 'words'") !== false && strpos($sql, 'ORDER BY ID ASC') !== false) {
                return "SELECT ID FROM {$wpdb->posts}_missing";
            }
            return $sql;
        };
        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $break_discovery);
        try {
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_filter('query', $break_discovery);
            $wpdb->suppress_errors($previous_suppress);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('words', $result['phase']);
        $this->assertSame(0, (int) $result['cursor']);
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
        $this->assertStringContainsString('could not read posts', strtolower((string) $result['last_error']));
    }

    public function test_partial_category_copy_failure_preserves_all_original_word_categories(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Partial Mapping Wordset', 'partial-mapping-wordset');
        $category_one = $this->ensure_term('word-category', 'Partial Mapping One', 'partial-mapping-one');
        $category_two = $this->ensure_term('word-category', 'Partial Mapping Two', 'partial-mapping-two');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Partial Mapping Word',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_one, $category_two], 'word-category', false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $owner_write_count = 0;
        $drop_second_owner = static function ($check, $object_id, $meta_key) use (&$owner_write_count) {
            if ($meta_key !== LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY) {
                return $check;
            }
            $owner_write_count++;
            return $owner_write_count === 2 ? true : $check;
        };
        add_filter('update_term_metadata', $drop_second_owner, 10, 3);
        $state = ll_tools_wordset_isolation_migration_new_state();
        try {
            $success = ll_tools_wordset_isolation_migration_process_word($word_id, $state);
        } finally {
            remove_filter('update_term_metadata', $drop_second_owner, 10);
        }

        $this->assertFalse($success);
        $this->assertSame('failed', $state['status']);
        $persisted = array_map('intval', (array) wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']));
        sort($persisted, SORT_NUMERIC);
        $expected = [$category_one, $category_two];
        sort($expected, SORT_NUMERIC);
        $this->assertSame($expected, $persisted);
        $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($category_two, $wordset_id));
        $this->assertFalse(get_term_by('slug', ll_tools_build_isolated_category_slug('partial-mapping-two', $wordset_id), 'word-category'));
    }

    public function test_category_copy_does_not_claim_an_unrelated_slug_collision(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $target_wordset_id = $this->ensure_term('wordset', 'Collision Target Wordset', 'collision-target-wordset');
        $other_wordset_id = $this->ensure_term('wordset', 'Collision Other Wordset', 'collision-other-wordset');
        $source_category_id = $this->ensure_term('word-category', 'Collision Source Category', 'collision-source-category');
        $collision = wp_insert_term('Collision Source Category', 'word-category', [
            'slug' => ll_tools_build_isolated_category_slug('collision-source-category', $target_wordset_id),
        ]);
        $this->assertIsArray($collision);
        $collision_id = (int) $collision['term_id'];
        ll_tools_set_category_wordset_owner($collision_id, $other_wordset_id, $collision_id);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $this->assertSame(0, ll_tools_get_or_create_isolated_category_copy($source_category_id, $target_wordset_id));
        $this->assertSame($other_wordset_id, ll_tools_get_category_wordset_owner_id($collision_id));
        $this->assertSame($collision_id, ll_tools_get_category_isolation_source_id($collision_id));
    }

    public function test_failed_image_owner_write_removes_the_incomplete_copy(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'Image Owner Failure Wordset', 'image-owner-failure-wordset');
        $category_id = $this->ensure_term('word-category', 'Image Owner Failure Category', 'image-owner-failure-category');
        $source_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Image Owner Failure Source',
        ]);
        wp_set_object_terms($source_image_id, [$category_id], 'word-category', false);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $created_copy_id = 0;
        $drop_owner = static function ($check, $object_id, $meta_key) use (&$created_copy_id, $source_image_id) {
            if ($meta_key === LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY && (int) $object_id !== $source_image_id) {
                $created_copy_id = (int) $object_id;
                return true;
            }
            return $check;
        };
        add_filter('update_post_metadata', $drop_owner, 10, 3);
        try {
            $copy_id = ll_tools_get_or_create_isolated_word_image_copy($source_image_id, $wordset_id);
        } finally {
            remove_filter('update_post_metadata', $drop_owner, 10);
        }

        $this->assertSame(0, $copy_id);
        $this->assertGreaterThan(0, $created_copy_id);
        $this->assertNull(get_post($created_copy_id));
        $this->assertSame(0, ll_tools_get_existing_isolated_word_image_copy_id($source_image_id, $wordset_id));
    }

    public function test_user_meta_compare_and_swap_preserves_a_concurrent_write_and_stops_cursor(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'Concurrent User Repair Wordset', 'concurrent-user-repair-wordset');
        $category_id = $this->ensure_term('word-category', 'Concurrent User Repair Category', 'concurrent-user-repair-category');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $isolated_category_id = ll_tools_get_or_create_isolated_category_copy($category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, [$category_id]);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'queued';
        $state['phase'] = 'users';
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);

        $concurrent_value = [$category_id, 999999];
        $interleave = null;
        $interleave = static function ($check, $object_id, $meta_key) use (&$interleave, $user_id, $concurrent_value) {
            if ((int) $object_id !== $user_id || $meta_key !== LL_TOOLS_USER_CATEGORY_META) {
                return $check;
            }
            remove_filter('update_user_metadata', $interleave, 10);
            update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, $concurrent_value);
            add_filter('update_user_metadata', $interleave, 10, 3);
            return true;
        };
        add_filter('update_user_metadata', $interleave, 10, 3);
        try {
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_filter('update_user_metadata', $interleave, 10);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('users', $result['phase']);
        $this->assertLessThan($user_id, (int) $result['cursor']);
        $this->assertSame($concurrent_value, get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true));
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
    }

    public function test_user_category_copy_failure_stops_the_cursor_without_legacy_success(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $user_id = self::factory()->user->create(['role' => 'subscriber']);
        $wordset_id = $this->ensure_term('wordset', 'User Mapping Failure Wordset', 'user-mapping-failure-wordset');
        $category_id = $this->ensure_term('word-category', 'User Mapping Failure Category', 'user-mapping-failure-category');
        update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
        update_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, [$category_id]);
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'queued';
        $state['phase'] = 'users';
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);

        $drop_owner = static function ($check, $object_id, $meta_key) {
            return $meta_key === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY ? true : $check;
        };
        add_filter('update_term_metadata', $drop_owner, 10, 3);
        try {
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_filter('update_term_metadata', $drop_owner, 10);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('users', $result['phase']);
        $this->assertLessThan($user_id, (int) $result['cursor']);
        $this->assertSame([$category_id], get_user_meta($user_id, LL_TOOLS_USER_CATEGORY_META, true));
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
    }

    public function test_every_wordset_scoped_user_store_requires_a_complete_isolated_mapping(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'User Store Mapping Wordset', 'user-store-mapping-wordset');
        $category_id = $this->ensure_term('word-category', 'User Store Mapping Category', 'user-store-mapping-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => [123, 124, 125, 126, 127],
            'details' => [],
        ];
        $stores = [
            LL_TOOLS_USER_CATEGORY_PROGRESS_META => [
                $category_id => [
                    'category_id' => $category_id,
                    'wordset_id' => $wordset_id,
                    'exposure_total' => 1,
                ],
            ],
            LL_TOOLS_USER_RECOMMENDATION_QUEUE_META => [
                (string) $wordset_id => [$activity],
            ],
            LL_TOOLS_USER_LAST_RECOMMENDATION_META => [
                (string) $wordset_id => $activity,
            ],
            LL_TOOLS_USER_RECOMMENDATION_DEFERRALS_META => [
                (string) $wordset_id => [
                    ll_tools_recommendation_activity_queue_id($activity) => [
                        'count' => 1,
                        'available_after' => '2030-07-14 10:00:00',
                        'last_dismissed_at' => '2026-07-14 10:00:00',
                        'category_ids' => [$category_id],
                        'session_word_ids' => $activity['session_word_ids'],
                        'mode' => 'practice',
                        'type' => 'review_chunk',
                    ],
                ],
            ],
            LL_TOOLS_USER_PROMPT_CARD_PROGRESS_META => [
                98765 => [
                    'prompt_card_id' => 98765,
                    'category_id' => $category_id,
                    'wordset_id' => $wordset_id,
                    'exposure_total' => 1,
                ],
            ],
        ];
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        foreach ($stores as $meta_key => $stored_value) {
            $user_id = self::factory()->user->create(['role' => 'subscriber']);
            update_user_meta($user_id, LL_TOOLS_USER_WORDSET_META, $wordset_id);
            update_user_meta($user_id, $meta_key, $stored_value);
            $state = ll_tools_wordset_isolation_migration_new_state();
            $state['status'] = 'queued';
            $state['phase'] = 'users';
            $state['cursor'] = 77;

            $drop_owner = static function ($check, $object_id, $checked_meta_key) {
                return $checked_meta_key === LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY ? true : $check;
            };
            add_filter('update_term_metadata', $drop_owner, 10, 3);
            try {
                $processed = ll_tools_wordset_isolation_migration_process_user($user_id, $state);
            } finally {
                remove_filter('update_term_metadata', $drop_owner, 10);
            }

            $this->assertFalse($processed, $meta_key);
            $this->assertSame('failed', $state['status'], $meta_key);
            $this->assertSame(77, (int) $state['cursor'], $meta_key);
            $this->assertSame($stored_value, get_user_meta($user_id, $meta_key, true), $meta_key);
            $this->assertSame(0, ll_tools_get_existing_isolated_category_copy_id($category_id, $wordset_id), $meta_key);
        }
    }

    public function test_state_checkpoint_write_failure_is_reported_without_overwriting_progress(): void
    {
        global $wpdb;
        $before = ll_tools_wordset_isolation_migration_new_state();
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $before, false);
        $next = $before;
        $next['phase'] = 'images';
        $next['cursor'] = 123;
        $drop_state_update = static function (string $sql) use ($wpdb): string {
            if (strpos($sql, "UPDATE {$wpdb->options}") !== false && strpos($sql, LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION) !== false) {
                return "UPDATE {$wpdb->options} SET option_value = option_value WHERE 1 = 0";
            }
            return $sql;
        };
        add_filter('query', $drop_state_update);
        try {
            $this->assertFalse(ll_tools_wordset_isolation_migration_save_state($next));
        } finally {
            remove_filter('query', $drop_state_update);
        }
        $this->assertSame($before, get_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION));
    }

    public function test_final_completed_checkpoint_failure_prevents_version_publication(): void
    {
        global $wpdb;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'queued';
        $state['phase'] = 'finalize';
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);
        delete_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION);
        delete_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT);
        wp_clear_scheduled_hook(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION);
        delete_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION);
        ll_tools_reset_category_maintenance_runtime();

        $drop_completed_state = static function (string $sql) use ($wpdb): string {
            if (
                strpos($sql, "UPDATE {$wpdb->options}") !== false
                && strpos($sql, LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION) !== false
                && strpos($sql, 'completed') !== false
            ) {
                return "UPDATE {$wpdb->options} SET option_value = option_value WHERE 1 = 0";
            }
            return $sql;
        };
        add_filter('query', $drop_completed_state);
        try {
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_filter('query', $drop_completed_state);
        }

        $this->assertNotSame('completed', $result['status']);
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
        $this->assertNotSame(
            'completed',
            (string) (ll_tools_get_wordset_isolation_migration_state()['status'] ?? '')
        );
        $this->assertFalse(get_option(LL_TOOLS_QUIZ_PAGE_SYNC_STATE_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_VOCAB_LESSON_SYNC_STATE_OPTION, false));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_VOCAB_LESSON_SYNC_EVENT));
        $this->assertFalse(wp_next_scheduled(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_HOOK));
        $this->assertFalse(get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_STATE_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_WORDSET_ISOLATION_RECONCILIATION_LOCK_OPTION, false));

        $maintenance_runtime = ll_tools_get_category_maintenance_runtime();
        $this->assertSame(0, (int) ($maintenance_runtime['defer_depth'] ?? -1));
        $this->assertSame([], (array) ($maintenance_runtime['queued_category_ids'] ?? []));
    }

    public function test_background_migration_refuses_an_oversized_word_option_rules_store(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        update_option('ll_tools_word_option_rules', ['oversized' => str_repeat('x', 70 * KB_IN_BYTES)], false);
        $state = ll_tools_wordset_isolation_migration_new_state();
        $state['status'] = 'queued';
        $state['phase'] = 'word_option_rules';
        update_option(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_STATE_OPTION, $state, false);

        $small_store_limit = static fn(): int => 64 * KB_IN_BYTES;
        add_filter('ll_tools_wordset_isolation_migration_word_option_rules_max_bytes', $small_store_limit);
        try {
            $result = ll_tools_run_wordset_isolation_migration_batch();
        } finally {
            remove_filter('ll_tools_wordset_isolation_migration_word_option_rules_max_bytes', $small_store_limit);
        }

        $this->assertSame('failed', $result['status']);
        $this->assertSame('word_option_rules', $result['phase']);
        $this->assertStringContainsString('too large', strtolower((string) $result['last_error']));
        $this->assertSame(0, ll_tools_get_wordset_isolation_migration_version());
    }

    private function ensure_term(string $taxonomy, string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    private function create_image_attachment(string $filename): int
    {
        $bytes = base64_decode(self::ONE_PIXEL_PNG_BASE64, true);
        $this->assertIsString($bytes);

        $upload = wp_upload_bits($filename, null, $bytes);
        $this->assertIsArray($upload);
        $this->assertSame('', (string) ($upload['error'] ?? ''));

        $file_path = (string) ($upload['file'] ?? '');
        $this->assertNotSame('', $file_path);
        $this->assertFileExists($file_path);

        $filetype = wp_check_filetype(basename($file_path), null);
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => (string) ($filetype['type'] ?? 'image/png'),
            'post_title' => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $file_path);

        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        $relative_path = function_exists('_wp_relative_upload_path')
            ? (string) _wp_relative_upload_path($file_path)
            : '';
        if ($relative_path === '') {
            $relative_path = ltrim((string) wp_normalize_path($file_path), '/');
        }
        update_post_meta($attachment_id, '_wp_attached_file', $relative_path);

        return (int) $attachment_id;
    }

    private function createWordInWordsetCategory(string $title, int $wordset_id, int $category_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);

        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);

        return (int) $word_id;
    }

    private function normalizePairWordIds(int $left, int $right): array
    {
        if ($left > $right) {
            return [$right, $left];
        }

        return [$left, $right];
    }
}
