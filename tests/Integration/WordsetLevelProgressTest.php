<?php
declare(strict_types=1);

final class WordsetLevelProgressTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/includes/lib/wordset-level-progress.php';
        ll_tools_install_user_progress_schema();
    }

    public function test_counts_launch_and_historical_relationships_use_the_same_visible_words(): void
    {
        global $wpdb;
        $uid = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($uid);
        $ws = (int) wp_insert_term('Level test ' . wp_generate_uuid4(), 'wordset')['term_id'];
        $cat = (int) wp_insert_term('Family ' . wp_generate_uuid4(), 'word-category')['term_id'];
        update_term_meta($cat, 'll_quiz_prompt_type', 'audio');
        update_term_meta($cat, 'll_quiz_option_type', 'text_title');
        $ids = [];
        $published = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        for ($i = 0; $i < 6; $i++) {
            $id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Family word ' . $i,
                'post_date' => get_date_from_gmt($published), 'post_date_gmt' => $published]);
            wp_set_post_terms($id, [$ws], 'wordset');
            wp_set_post_terms($id, [$cat], 'word-category');
            update_post_meta($id, 'word_translation', 'Family translation ' . $i);
            $audio = self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $id]);
            update_post_meta($audio, 'audio_file_path', '/wp-content/uploads/level-' . $i . '.mp3');
            $ids[] = $id;
        }
        $cat = ll_tools_get_effective_category_id_for_wordset($cat, $ws, true);
        $this->completeLlToolsSimulatedRequest();
        $old = gmdate('Y-m-d H:i:s', time() - 15 * DAY_IN_SECONDS);
        $tables = ll_tools_user_progress_table_names();
        $this->assertNotFalse($wpdb->insert($tables['words'], ['user_id' => $uid, 'word_id' => $ids[0], 'category_id' => $cat,
            'wordset_id' => $ws, 'last_seen_at' => $old, 'first_seen_at' => $old, 'updated_at' => $old, 'total_coverage' => 1]));
        $members = [['id' => $cat, 'learning_supported' => true, 'practice_supported' => true]];
        $dashboard = ll_tools_wordset_level_dashboard($ws, $uid, [1 => $members, 2 => $members], [1 => 7, 2 => 30]);
        $this->assertIsArray($dashboard);
        $this->assertSame(6, $dashboard['levels'][1]['total']);
        $this->assertSame(5, $dashboard['levels'][1]['new']);
        $this->assertSame(1, $dashboard['levels'][1]['working']);
        $this->assertSame(6, $dashboard['levels'][1]['added']);
        $this->assertSame(1, $dashboard['levels'][1]['due']);
        $this->assertSame(0, $dashboard['levels'][2]['due']);
        $this->assertSame(1, $dashboard['overall']['due']);
        $this->assertSame([$ids[0]], $dashboard['overall']['activity']['session_word_ids']);
        $this->assertSame([$cat], $dashboard['overall']['activity']['category_ids']);
        $this->assertSame($dashboard['overall']['activity'], ll_tools_wordset_level_launch($ws, $uid, $members, 7, 'due'));
        $this->assertNull(ll_tools_wordset_level_launch($ws, $uid, $members, 30, 'due'));
    }

    public function test_large_generation_checkpoints_250_ids_without_publishing_partial_counts(): void
    {
        global $wpdb;
        $this->assertTrue(ll_tools_install_flashcard_payload_schema());
        $hash = hash('sha256', wp_generate_uuid4());
        $generation = hash('sha256', wp_generate_uuid4());
        $key = 'll_lvl_test_' . substr($hash, 0, 20);
        update_option(ll_tools_flashcard_payload_state_option($hash), [
            'scope_hash' => $hash, 'generation' => $generation, 'published_generation' => $generation, 'status' => 'completed',
        ], false);
        $id = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        $table = ll_tools_flashcard_payload_table_name();
        $values = [];
        for ($n = 0; $n <= 250; ++$n) {
            $values[] = $wpdb->prepare("(%s,%s,7,'word',%d,0,'{}',%s)", $hash, $generation, $id + $n, gmdate('Y-m-d H:i:s'));
        }
        $this->assertSame(251, $wpdb->query("INSERT INTO {$table}
            (scope_hash,generation,category_id,row_kind,row_id,sort_group,payload,updated_at) VALUES " . implode(',', $values)));
        $first = ll_tools_wordset_level_project_generation($key, $hash, $generation, 2, 7);
        $this->assertWPError($first);
        $this->assertSame('ll_level_progress_preparing', $first->get_error_code());
        $this->assertFalse(get_transient($key));
        $cursor = get_transient($key . '_p_' . substr($generation, 0, 20));
        $this->assertSame($id + 249, $cursor['cursor']);
        $this->assertArrayHasKey($id, $cursor['members']);
        $final = ll_tools_wordset_level_project_generation($key, $hash, $generation, 2, 7);
        $this->assertIsArray($final);
        $this->assertArrayHasKey($id, $final);
        $this->assertSame($final, get_transient($key));
        $this->assertFalse(get_transient($key . '_p_' . substr($generation, 0, 20)));
    }

    public function test_distinct_bins_placement_and_batch_rotation_with_duplicate_memberships(): void
    {
        $now = time();
        $old = gmdate('Y-m-d H:i:s', $now - 40 * DAY_IN_SECONDS);
        $today = gmdate('Y-m-d H:i:s', $now);
        $members = [11 => [], 12 => [], 13 => [], 14 => []];
        $progress = [];
        for ($i = 1; $i <= 16; $i++) {
            $members[11][$i] = $old;
            $members[12][$i] = $old;
            $progress[$i] = ['total_coverage' => 1, 'last_seen_at' => $old];
        }
        $progress[1] += ['mastery_unlocked' => 1, 'stage' => 5];
        $members[13][22] = $today;
        $members[14][23] = $today;
        $levels = [1 => array_fill_keys([11, 12, 13, 14], []), 2 => [12 => []]];
        $history = [11 => $old, 12 => $old];
        $first = ll_tools_wordset_level_summarize(0, 0, $levels, $members, $history, $progress, [1 => 5, 2 => 35], $now, [13 => true]);
        $one = $first['levels'][1];
        $this->assertSame([18, 1, 15, 2], [$one['total'], $one['mastered'], $one['working'], $one['new']]);
        $this->assertSame(2, $one['added']);
        $this->assertSame(14, $one['next_category_id']);
        $this->assertSame(16, $first['overall']['due']);
        $this->assertCount(12, $first['overall']['activity']['session_word_ids']);
        foreach ($first['overall']['activity']['session_word_ids'] as $wid) {
            $progress[$wid]['last_seen_at'] = $today;
        }
        $next = ll_tools_wordset_level_summarize(0, 0, $levels, $members, $history, $progress, [1 => 5, 2 => 35], $now);
        $this->assertSame(4, $next['overall']['due']);
        $this->assertSame([13, 14, 15, 16], $next['overall']['activity']['session_word_ids']);
    }

    public function test_releasing_a_lesson_counts_words_that_were_not_already_on_the_level(): void
    {
        $lesson = self::factory()->post->create(['post_type' => 'll_vocab_lesson', 'post_status' => 'publish']);
        update_post_meta($lesson, LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META, '1');
        $this->assertSame('', get_post_meta($lesson, '_ll_level_lesson_released_at', true));
        delete_post_meta($lesson, LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META);
        $released = get_post_meta($lesson, '_ll_level_lesson_released_at', true);
        $this->assertNotEmpty($released);
        $now = strtotime($released . ' UTC');
        $studied = gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
        $old = gmdate('Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS);
        $members = [11 => [3 => $old], 12 => [3 => $old, 4 => $old]];
        $dashboard = ll_tools_wordset_level_summarize(0, 0, [1 => [11 => [], 12 => []]], $members,
            [11 => $studied], [], [], $now, [], [12 => $released]);
        $this->assertSame(1, $dashboard['levels'][1]['added']);
    }

    public function test_date_order_preserves_each_selected_words_category_across_levels(): void
    {
        $now = time();
        $older = gmdate('Y-m-d H:i:s', $now - 90 * DAY_IN_SECONDS);
        $old = gmdate('Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS);
        $members = [11 => [3 => $older], 12 => [3 => $older, 4 => $older]];
        $rows = [3 => ['total_coverage' => 1, 'last_seen_at' => $old], 4 => ['total_coverage' => 1, 'last_seen_at' => $older]];
        $levels = [1 => [11 => [], 12 => []], 2 => [12 => []]];
        $dashboard = ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $rows, [1 => 7, 2 => 7], $now);
        $this->assertSame([4, 3], $dashboard['overall']['activity']['session_word_ids']);
        $this->assertSame([4 => 12, 3 => 11], $dashboard['overall']['activity']['word_category_ids']);
        $this->assertSame([4 => 12, 3 => 11], $dashboard['levels'][1]['due_activity']['word_category_ids']);
    }

    public function test_graph_changes_date_only_categories_entering_a_different_level(): void
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $categories = [];
        for ($i = 0; $i < 5; ++$i) {
            $category = self::factory()->term->create(['taxonomy' => 'word-category']);
            ll_tools_set_category_wordset_owner($category, $wordset);
            $categories[] = $category;
        }
        [$root, $middle, $leaf, $reference, $new_root] = $categories;
        $initial = [$middle => [$root], $leaf => [$middle]];
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'prerequisite');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', $initial);
        update_term_meta($wordset, LL_TOOLS_CURRICULUM_ADDITIONAL_META, [$reference]);
        ll_tools_wordset_level_record_graph_changes();
        $this->assertSame([], ll_tools_wordset_level_entry_dates($wordset, $categories));

        $changed = $initial + [$root => [$new_root]];
        update_term_meta($wordset, 'll_wordset_category_prerequisites', $changed);
        delete_term_meta($wordset, LL_TOOLS_CURRICULUM_ADDITIONAL_META);
        ll_tools_wordset_level_record_graph_changes();
        $entries = ll_tools_wordset_level_entry_dates($wordset, $categories);
        $this->assertArrayHasKey($reference, $entries[1]);
        $this->assertArrayHasKey($root, $entries[2]);
        $this->assertArrayHasKey($middle, $entries[3]);
        $this->assertArrayHasKey($leaf, $entries[4]);
        $this->assertArrayNotHasKey($new_root, $entries[1]);

        // A multi-setting save that rolls back must preserve the previous date.
        update_term_meta($middle, '_ll_level_entry_' . $wordset, '3|2020-01-01 00:00:00');
        update_term_meta($wordset, 'll_wordset_category_prerequisites', $initial);
        update_term_meta($wordset, 'll_wordset_category_prerequisites', $changed);
        ll_tools_wordset_level_record_graph_changes();
        $this->assertSame('2020-01-01 00:00:00', ll_tools_wordset_level_entry_dates($wordset, $categories)[3][$middle]);
    }

    public function test_clones_and_imports_keep_their_own_availability_dates(): void
    {
        require_once LL_TOOLS_BASE_PATH . 'includes/admin/export-import.php';
        $source = self::factory()->post->create();
        $target = self::factory()->post->create();
        update_post_meta($source, '_ll_level_published_at', '2020-01-01 00:00:00');
        update_post_meta($target, '_ll_level_published_at', '2025-01-01 00:00:00');
        update_post_meta($source, 'word_translation', 'example');
        ll_tools_copy_post_meta($source, $target);
        $this->assertSame('2025-01-01 00:00:00', get_post_meta($target, '_ll_level_published_at', true));
        $this->assertSame('example', get_post_meta($target, 'word_translation', true));
        $this->assertArrayNotHasKey('_ll_level_published_at', ll_tools_prepare_meta_for_export(get_post_meta($source)));
        $this->assertFalse(ll_tools_import_should_replace_post_meta_key('_ll_level_published_at', 'words'));
        $this->assertFalse(ll_tools_import_should_replace_term_meta_key('_ll_level_entry_234', 'word-category'));
    }

    public function test_hundreds_of_warm_memberships_use_batched_reads_and_preserve_private_scope(): void
    {
        global $wpdb;
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $count = 201;
        $categories = [];
        $prefix = 'benchmark-' . wp_generate_uuid4();
        // Only the materialized projections are relevant to a warm read. Seed
        // exact term rows without running authoring/media workflows 201 times.
        for ($i = 0; $i < $count; ++$i) {
            $name = $prefix . '-' . $i;
            $wpdb->insert($wpdb->terms, ['name' => $name, 'slug' => $name]);
            $id = (int) $wpdb->insert_id;
            $wpdb->insert($wpdb->term_taxonomy, ['term_id' => $id, 'taxonomy' => 'word-category', 'description' => '']);
            $wpdb->insert($wpdb->termmeta, ['term_id' => $id, 'meta_key' => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, 'meta_value' => $wordset]);
            $categories[] = $id;
        }
        $private = end($categories);
        update_term_meta($private, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        $this->completeLlToolsSimulatedRequest();
        foreach ($categories as $index => $id) {
            $word = 8000001 + $index * 12;
            $projection = array_fill_keys(range($word, $word + 11), '2020-01-01 00:00:00');
            $key = ll_tools_wordset_level_member_key($wordset, $id);
            if ($id === $private) {
                // Simulate an extant shared cache and a different learner.
                set_transient($key, [], HOUR_IN_SECONDS);
                set_transient($key . '_u' . ($user + 1), [], HOUR_IN_SECONDS);
                $key .= '_u' . $user;
            }
            set_transient($key, $projection, HOUR_IN_SECONDS);
        }
        wp_cache_flush();
        ll_tools_epoch_request_cache_reset();
        $before = $wpdb->num_queries;
        $summary = ll_tools_wordset_level_dashboard($wordset, $user, [1 => $categories]);
        $queries = $wpdb->num_queries - $before;
        $this->assertIsArray($summary);
        $this->assertSame(12 * $count, $summary['levels'][1]['total']);
        // WordPress 6.1–6.3 lack the bulk options API but still batch the
        // authoritative term, visibility, and category version reads.
        $maximum = function_exists('wp_prime_option_caches') ? 70 : 2 * $count + 70;
        $this->assertLessThan($maximum, $queries);
        $old_key = ll_tools_wordset_level_member_key($wordset, $private);
        ll_tools_bump_category_cache_versions_only([$private]);
        $this->assertNotSame($old_key, ll_tools_wordset_level_member_key($wordset, $private));
    }

    public function test_historical_context_success_obeys_each_levels_interval_and_never_grants_direct_mastery(): void
    {
        global $wpdb;
        $uid = self::factory()->user->create(['role' => 'subscriber']);
        $ws = (int) wp_insert_term('Context ' . wp_generate_uuid4(), 'wordset')['term_id'];
        $now = time();
        $old = gmdate('Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS);
        $recent = gmdate('Y-m-d H:i:s', $now - 10 * DAY_IN_SECONDS);
        $source = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        $base = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        update_option('ll_tools_context_review_' . $ws, wp_json_encode([[$source, $base]]), false);
        $rows = [$base => ['total_coverage' => 1, 'mastery_unlocked' => 1, 'stage' => 5, 'last_seen_at' => $old],
            $source => ['total_coverage' => 1, 'last_seen_at' => gmdate('Y-m-d H:i:s', $now)]];
        $this->assertNotFalse($wpdb->insert(ll_tools_user_progress_table_names()['events'], [
            'user_id' => $uid, 'wordset_id' => $ws, 'word_id' => $source, 'event_uuid' => wp_generate_uuid4(),
            'event_type' => 'word_outcome', 'mode' => 'practice', 'is_correct' => 1, 'created_at' => $recent,
        ]));
        $result = ll_tools_wordset_level_summarize($ws, $uid, [1 => [7 => []], 2 => [7 => []]], [7 => [$base => $old, $source => $old]],
            [7 => $old], $rows, [1 => 7, 2 => 30], $now);
        $this->assertSame(1, $result['levels'][1]['due']);
        $this->assertSame(0, $result['levels'][2]['due']);
        $this->assertSame([$base], $result['overall']['activity']['session_word_ids']);
        $this->assertSame(1, $result['levels'][2]['mastered']);
        $this->assertSame(1, $result['levels'][2]['working']);
    }
}
