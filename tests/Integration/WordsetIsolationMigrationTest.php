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
        delete_transient(LL_TOOLS_WORDSET_ISOLATION_MIGRATION_NOTICE_TRANSIENT);
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
        $this->assertSame(2, (int) ($result['images_relinked'] ?? 0));
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
