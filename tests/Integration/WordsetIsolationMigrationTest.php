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
        delete_option('ll_tools_word_option_rules');

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

    public function test_every_category_bearing_user_store_requires_a_complete_isolated_mapping(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensure_term('wordset', 'User Store Mapping Wordset', 'user-store-mapping-wordset');
        $category_id = $this->ensure_term('word-category', 'User Store Mapping Category', 'user-store-mapping-category');
        $activity = [
            'type' => 'review_chunk',
            'mode' => 'practice',
            'category_ids' => [$category_id],
            'session_word_ids' => [123],
            'details' => [],
        ];
        $stores = [
            LL_TOOLS_USER_GOALS_META => [
                'ignored_category_ids' => [$category_id],
                'placement_known_category_ids' => [$category_id],
                'preferred_wordset_ids' => [$wordset_id],
            ],
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
