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
        $this->assertSame('', get_post_meta($ids[0], '_ll_level_published_at', true));
        $this->assertSame('', get_post_meta($ids[0], '_ll_level_added_wordset_' . $ws, true));
        $this->assertSame(5, $dashboard['levels'][1]['new']);
        $this->assertSame(1, $dashboard['levels'][1]['working']);
        $this->assertArrayNotHasKey('added', $dashboard['levels'][1]);
        $this->assertSame(1, $dashboard['levels'][1]['due']);
        $this->assertSame(0, $dashboard['levels'][2]['due']);
        $this->assertSame(1, $dashboard['overall']['due']);
        $this->assertSame([$ids[0]], $dashboard['overall']['activity']['session_word_ids']);
        $this->assertSame([$cat], $dashboard['overall']['activity']['category_ids']);
        $this->assertSame($dashboard['overall']['activity'], ll_tools_wordset_level_launch($ws, $uid, $members, 7, 'due'));
        $this->assertNull(ll_tools_wordset_level_launch($ws, $uid, $members, 30, 'due'));

        $token = ll_tools_wordset_page_store_category_search_payload([
            'user_id' => $uid, 'wordset_id' => $ws,
            'access_signature' => ll_tools_wordset_page_category_search_payload_access_signature($ws, $uid),
            'level_policy' => ll_tools_wordset_level_policy_signature($ws), 'level_scope' => [1 => $members],
        ]);
        $request = ['operation' => 'summary', 'wordset_id' => $ws, 'token' => $token];
        $initial = ll_tools_wordset_level_request($uid, $request);
        $this->assertSame(['reason' => 'working', 'count' => 1, 'due' => 0], $initial['review']);
        $this->assertArrayNotHasKey('due_word_ids', $initial['review']);
        $this->assertSame(['mode' => 'practice', 'category_ids' => [$cat], 'word_count' => 1],
            $initial['levels'][1]['practice_activity']);
        $level_practice = ll_tools_wordset_level_request($uid, array_merge($request,
            ['operation' => 'launch', 'level' => 1, 'launch_mode' => 'practice']));
        $this->assertSame([$ids[0]], $level_practice['bounded']['word_ids']);
        $this->assertSame([$ids[0] => $cat], $level_practice['word_category_ids']);
        $review_request = array_merge($request, ['operation' => 'launch', 'level' => 0, 'launch_mode' => 'review']);
        $fallback = ll_tools_wordset_level_request($uid, $review_request);
        $this->assertSame('working', $fallback['reason']);
        $this->assertSame([$ids[0]], $fallback['session_word_ids']);
        $this->assertSame([$ids[0]], $fallback['bounded']['word_ids']);
        $this->assertSame('ll_level_no_activity', ll_tools_wordset_level_request($uid,
            array_merge($review_request, ['launch_mode' => 'due']))->get_error_code());
        ll_tools_wordset_level_save_interval($uid, $ws, 1, 7);
        $this->assertSame(['reason' => 'due', 'count' => 1, 'due' => 1], ll_tools_wordset_level_request($uid, $request)['review']);
        $this->assertSame('due', ll_tools_wordset_level_request($uid, $review_request)['reason']);
    }

    public function test_hard_and_starred_launches_match_the_current_learners_counts_in_each_level(): void
    {
        global $wpdb;
        $user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($user);
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $category_ids = [];
        for ($i = 0; $i < 4; ++$i) {
            $category = self::factory()->term->create(['taxonomy' => 'word-category']);
            ll_tools_set_category_wordset_owner($category, $wordset);
            $category_ids[] = $category;
        }
        [$unsupported, $first, $second, $other_level] = $category_ids;
        $scope = [1 => [['id' => $unsupported, 'practice_supported' => false], ['id' => $first], ['id' => $second]],
            2 => [['id' => $other_level]]];
        $this->completeLlToolsSimulatedRequest();
        // Warm the compact projections exactly as the level page does. A shared word can have several owners.
        $members = [$unsupported => [800008, 800090], $first => range(800001, 800008),
            $second => range(800008, 800016), $other_level => [800091]];
        foreach ($members as $category => $words) {
            set_transient(ll_tools_wordset_level_member_key($wordset, $category), array_fill_keys($words, true), HOUR_IN_SECONDS);
        }
        $now = current_time('mysql', true);
        // One mistake at stage 1 is at the default hard threshold. The same mistake at stage 2 falls below it.
        $progress = [800008 => [$first, 1, 1], 800090 => [$unsupported, 1, 1], 800091 => [$other_level, 1, 1],
            800016 => [$second, 1, 2], 800002 => [$first, 0, 5]];
        foreach ($progress as $word => [$category, $incorrect, $stage]) {
            $this->assertNotFalse($wpdb->insert(ll_tools_user_progress_table_names()['words'], [
                'user_id' => $user, 'word_id' => $word, 'category_id' => $category, 'wordset_id' => $wordset,
                'last_seen_at' => $now, 'first_seen_at' => $now, 'updated_at' => $now, 'total_coverage' => 1,
                'incorrect' => $incorrect, 'stage' => $stage, 'mastery_unlocked' => $stage === 5 ? 1 : 0,
            ]));
        }
        $stars = range(800001, 800015);
        update_user_meta($user, LL_TOOLS_USER_STARRED_META, array_merge($stars, [800090, 800091]));
        $token = ll_tools_wordset_page_store_category_search_payload(['user_id' => $user, 'wordset_id' => $wordset,
            'access_signature' => ll_tools_wordset_page_category_search_payload_access_signature($wordset, $user),
            'level_policy' => ll_tools_wordset_level_policy_signature($wordset), 'level_scope' => $scope]);
        $base = ['wordset_id' => $wordset, 'token' => $token];
        $launch = static function (string $filter, int $level) use ($user, $base) {
            return ll_tools_wordset_level_request($user, $base + ['operation' => 'launch', 'level' => $level, 'launch_mode' => $filter]);
        };

        $summary = ll_tools_wordset_level_request($user, $base + ['operation' => 'summary']);
        $this->assertSame([1, 15], [$summary['levels'][1]['hard'], $summary['levels'][1]['starred']]);
        $this->assertSame([1, 1], [$summary['levels'][2]['hard'], $summary['levels'][2]['starred']]);
        $this->assertArrayNotHasKey('starred_activity', $summary['levels'][1]);
        $this->assertArrayNotHasKey('hard_activity', $summary['levels'][1]);
        $hard = $launch('hard', 1);
        $this->assertSame('practice', $hard['mode']);
        $this->assertSame([800008 => $first], $hard['word_category_ids']);
        $this->assertSame([800008], $hard['bounded']['word_ids']);
        $starred = $launch('starred', 1);
        $this->assertEqualsCanonicalizing($stars, $starred['session_word_ids']);
        $this->assertSame($first, $starred['word_category_ids'][800008]);
        $this->assertSame([12, 3], array_map('count', array_column($starred['bounded']['chunks'], 'word_ids')));
        $this->assertSame($starred['session_word_ids'], array_merge(...array_column($starred['bounded']['chunks'], 'word_ids')));
        foreach (['hard', 'starred'] as $filter) {
            $this->assertSame([800091], $launch($filter, 2)['session_word_ids']);
            $this->assertSame('ll_level_unknown', $launch($filter, 0)->get_error_code());
            $this->assertNull(ll_tools_wordset_level_launch($wordset, 0, $scope[1], 30, $filter));
        }
        update_user_meta($user, LL_TOOLS_USER_STARRED_META, [800091]);
        $this->assertSame('ll_level_no_activity', $launch('starred', 1)->get_error_code());
        $other_user = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($other_user);
        foreach (['hard', 'starred'] as $filter) {
            $this->assertSame('ll_level_access', $launch($filter, 1)->get_error_code());
            $this->assertSame('ll_level_scope_changed', ll_tools_wordset_level_request($other_user,
                $base + ['operation' => 'launch', 'level' => 1, 'launch_mode' => $filter])->get_error_code());
        }
    }

    public function test_menu_quizzes_keep_the_oldest_eligible_words_in_the_requested_level_and_gender_stage(): void
    {
        global $wpdb;
        $learner = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($learner);
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        update_term_meta($wordset, 'll_wordset_has_gender', 1);
        update_term_meta($wordset, 'll_wordset_gender_options', ['Masculine', 'Feminine']);
        $categories = [];
        for ($i = 0; $i < 3; ++$i) {
            $category = self::factory()->term->create(['taxonomy' => 'word-category']);
            ll_tools_set_category_wordset_owner($category, $wordset);
            update_term_meta($category, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category, 'll_quiz_option_type', 'text_title');
            $categories[] = $category;
        }
        $noun = term_exists('noun', 'part_of_speech') ?: wp_insert_term('Noun', 'part_of_speech', ['slug' => 'noun']);
        $table = ll_tools_user_progress_table_names()['words'];
        $words = [];
        for ($i = 0; $i <= 56; ++$i) {
            $word = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Word ' . $i]);
            $words[] = $word;
            $owner = $categories[$i === 56 ? 2 : $i % 2];
            wp_set_post_terms($word, [$wordset], 'wordset');
            // One shared word must consume only one of the available places.
            wp_set_post_terms($word, $i === 53 ? [$categories[0], $categories[1]] : [$owner], 'word-category');
            update_post_meta($word, 'word_translation', 'Meaning ' . $i);
            $audio = self::factory()->post->create(['post_type' => 'word_audio', 'post_status' => 'publish', 'post_parent' => $word]);
            update_post_meta($audio, 'audio_file_path', '/wp-content/uploads/menu-' . $i . '.mp3');
            wp_set_post_terms($word, [(int) $noun['term_id']], 'part_of_speech');
            if ($i >= 55) { continue; } // Unstudied, with no noun gender.
            update_post_meta($word, 'll_grammatical_gender', $i % 2 ? 'Masculine' : 'Feminine');
            // Higher IDs were studied earlier. Alternate Gender stages within the same curriculum level.
            $last_seen = gmdate('Y-m-d H:i:s', strtotime('2026-08-01 UTC') - $i * DAY_IN_SECONDS);
            $this->assertNotFalse($wpdb->insert($table, ['user_id' => $learner, 'wordset_id' => $wordset,
                'category_id' => $owner, 'word_id' => $word, 'total_coverage' => 1, 'first_seen_at' => $last_seen,
                'last_seen_at' => $last_seen, 'updated_at' => $last_seen, 'gender_last_seen_at' => $last_seen,
                'gender_seen_total' => 2, 'gender_level' => $i % 2 ? 3 : 2]));
        }
        $this->completeLlToolsSimulatedRequest();
        $mode_flags = ['learning_supported' => true, 'practice_supported' => true, 'self_check_supported' => true,
            'listening_supported' => true, 'gender_supported' => true];
        $scope = [1 => [['id' => $categories[0]] + $mode_flags, ['id' => $categories[1]] + $mode_flags],
            2 => [['id' => $categories[2]] + $mode_flags]];
        $empty_lesson = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($empty_lesson, $wordset);
        $scope[3] = [
            ['id' => $categories[0], 'listening_supported' => false, 'gender_supported' => false] + $mode_flags,
            ['id' => $categories[1], 'gender_supported' => false] + $mode_flags,
            ['id' => $empty_lesson] + $mode_flags,
        ];
        $token_for = static function (int $viewer) use ($scope, $wordset): string {
            return ll_tools_wordset_page_store_category_search_payload(['wordset_id' => $wordset,
                'user_id' => $viewer, 'access_signature' => ll_tools_wordset_page_category_search_payload_access_signature($wordset, $viewer),
                'level_policy' => ll_tools_wordset_level_policy_signature($wordset), 'level_scope' => $scope]);
        };
        $base = ['operation' => 'quiz', 'level' => 1, 'wordset_id' => $wordset, 'token' => $token_for($learner)];
        $ranked = array_reverse(array_slice($words, 0, 56));
        foreach (['learning' => 10, 'practice' => 20, 'self-check' => 50, 'listening' => 56] as $mode => $limit) {
            $result = ll_tools_wordset_level_request($learner, $base + ['launch_mode' => $mode]);
            $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : $mode);
            $this->assertSame($mode, $result['mode']);
            $this->assertSame(array_slice($ranked, 0, $limit), $result['session_word_ids'], $mode);
            $this->assertSame($result['session_word_ids'], array_merge(...array_column($result['bounded']['chunks'], 'word_ids')));
            $this->assertNotContains($categories[2], array_values($result['word_category_ids']));
        }
        foreach ([2, 3] as $stage) {
            $result = ll_tools_wordset_level_request($learner, $base + ['launch_mode' => 'gender']);
            $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : 'Gender');
            $expected = array_map(static fn(int $i): int => $words[$i], range($stage === 2 ? 54 : 53, $stage === 2 ? 26 : 25, -2));
            $this->assertSame($expected, $result['session_word_ids']);
            $this->assertSame([$stage], array_values(array_unique(array_column(array_column($result['bounded']['chunks'], 'details'), 'gender_level'))));
            if ($stage === 2) {
                $now = current_time('mysql', true);
                $this->assertSame(1, $wpdb->update($table, ['last_seen_at' => $now, 'gender_last_seen_at' => $now],
                    ['user_id' => $learner, 'word_id' => $words[54]]));
                $this->completeLlToolsSimulatedRequest();
            }
        }
        $summary_request = array_merge($base, ['operation' => 'summary']);
        $summary = ll_tools_wordset_level_request($learner, $summary_request);
        $this->assertContains('listening', $summary['levels'][3]['modes']);
        $this->assertNotContains('gender', $summary['levels'][3]['modes'], 'An empty lesson cannot enable a mode.');
        update_user_meta($learner, LL_TOOLS_USER_GOALS_META, ['ignored_category_ids' => [$categories[1]]]);
        $summary = ll_tools_wordset_level_request($learner, $summary_request);
        $this->assertNotContains('listening', $summary['levels'][3]['modes'], 'Only the ignored nonempty lesson supported Listen.');
        $this->assertContains('practice', $summary['levels'][3]['modes']);
        $this->assertSame('ll_level_no_activity', ll_tools_wordset_level_request($learner,
            array_merge($base, ['level' => 3, 'launch_mode' => 'listening']))->get_error_code());
        wp_set_current_user(0);
        $this->assertWPError(ll_tools_wordset_level_request(0, $base + ['launch_mode' => 'listening']));
        $base['token'] = $token_for(0);
        $guest = ll_tools_wordset_level_request(0, $base + ['launch_mode' => 'gender']);
        $this->assertIsArray($guest, is_wp_error($guest) ? $guest->get_error_message() : 'Guest');
        $this->assertCount(10, $guest['session_word_ids']);
        $this->assertSame(1, $guest['bounded']['chunks'][0]['details']['gender_level']);
        $this->assertNotContains($words[55], $guest['session_word_ids']);
        $this->assertWPError(ll_tools_wordset_level_request(0, array_merge($base, ['operation' => 'summary'])));
        $this->assertWPError(ll_tools_wordset_level_request(0, array_merge($base, ['operation' => 'interval', 'days' => 45])));
    }

    public function test_exact_plan_chunks_preserve_rank_for_large_and_category_scattered_sessions(): void
    {
        $targets = range(501, 524);
        $owners = array_combine($targets, array_merge(range(11, 21), array_fill(0, 13, 21)));
        $activity = ll_tools_wordset_level_activity('practice', array_values($owners), $targets);
        $activity['word_category_ids'] = $owners;
        $plan = ll_tools_wordset_level_bounded_plan($activity);
        $this->assertFalse($plan['truncated']);
        $this->assertSame(24, $plan['matched_count']);
        $this->assertSame(24, $plan['planned_count']);
        $this->assertSame(3, $plan['chunk_count']);
        $this->assertSame($plan['chunks'][0]['word_ids'], $plan['word_ids']);
        $this->assertSame($plan['chunks'][0]['category_ids'], $plan['category_ids']);
        $this->assertSame($targets, array_merge(...array_column($plan['chunks'], 'word_ids')));
        foreach ($plan['chunks'] as $chunk) {
            $this->assertLessThanOrEqual(8, count($chunk['category_ids']));
            $this->assertLessThanOrEqual(12, count($chunk['word_ids']));
            $this->assertSame(array_values(array_unique(array_intersect_key($owners, array_flip($chunk['word_ids'])))),
                $chunk['category_ids']);
        }
    }

    public function test_global_review_budget_uses_unique_studied_eligible_words_and_shortest_interval(): void
    {
        $now = time();
        $old = gmdate('Y-m-d H:i:s', $now - 45 * DAY_IN_SECONDS);
        $all = array_fill_keys(range(1, 3000), $old);
        $rows = array_fill_keys(array_keys($all), ['total_coverage' => 1, 'last_seen_at' => $old]);
        $unseen = array_fill_keys(range(3001, 4500), $old);
        $members = [11 => $all, 12 => $all + $unseen, 13 => [5000 => $old]];
        $levels = [1 => [11 => []], 2 => [12 => [], 13 => ['practice_supported' => false]]];
        $rows[5000] = ['total_coverage' => 1, 'last_seen_at' => $old];
        $result = ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $rows, [1 => 90, 2 => 30], $now);
        $overall = $result['overall'];
        $this->assertSame(0, $result['levels'][1]['due']);
        $this->assertSame(3000, $result['levels'][2]['due']);
        $this->assertSame(range(1, 3000), $overall['due_word_ids']);
        $this->assertSame('due', $overall['reason']);
        $this->assertSame(120, $overall['count']);
        $this->assertSame(range(1, 120), $overall['activity']['session_word_ids']);
        $this->assertSame([12], $overall['activity']['category_ids']);
        $this->assertSame(array_fill_keys(range(1, 120), 12), $overall['activity']['word_category_ids']);
        $this->assertCount(12, $result['levels'][2]['due_activity']['session_word_ids']);

        // Only 7 of the 3,000 are still due. Keep the budget from inflating the selection.
        $recent = gmdate('Y-m-d H:i:s', $now);
        foreach (range(8, 3000) as $wid) { $rows[$wid]['last_seen_at'] = $recent; }
        $limited = ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $rows, [1 => 90, 2 => 30], $now)['overall'];
        $this->assertSame(7, $limited['count']);
        $this->assertSame(range(1, 7), $limited['activity']['session_word_ids']);
    }

    public function test_global_no_due_plan_uses_one_named_pool_and_filters_unsupported_stars(): void
    {
        $now = time();
        $today = gmdate('Y-m-d H:i:s', $now);
        $studied = ['total_coverage' => 1, 'last_seen_at' => $today];
        $mastered = $studied + ['mastery_unlocked' => 1, 'stage' => 5];
        $rows = [1 => $studied, 2 => $studied + ['incorrect' => 8], 3 => $mastered, 4 => $mastered];
        $members = [11 => array_fill_keys([1, 2, 3, 4], $today), 12 => [7 => $today]];
        $levels = [1 => [11 => [], 12 => ['practice_supported' => false]]];
        $plan = static function (array $progress, array $stars) use ($levels, $members, $now): array {
            return ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $progress, [1 => 30],
                $now, [], array_fill_keys($stars, true))['overall'];
        };
        $working = $plan($rows, [3, 7]);
        $this->assertSame(['working', 1, 0], [$working['reason'], $working['count'], $working['due']]);
        $this->assertSame([1], $working['activity']['session_word_ids']);
        $rows[1] = $mastered;
        $hard = $plan($rows, [3, 7]);
        $this->assertSame('hard', $hard['reason']);
        $this->assertSame([2], $hard['activity']['session_word_ids']);
        $rows[2] = $mastered;
        $stars = $plan($rows, [3, 7]);
        $this->assertSame('starred', $stars['reason']);
        $this->assertSame([3], $stars['activity']['session_word_ids']);
        $rows[4]['last_seen_at'] = gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
        $least_recent = $plan($rows, [7]);
        $this->assertSame('least_recent', $least_recent['reason']);
        $this->assertSame([4, 1, 2, 3], $least_recent['activity']['session_word_ids']);
        $this->assertNull($plan([], [7])['activity']);
    }

    public function test_advanced_level_practice_targets_unfinished_words_and_reinforces_only_their_lesson(): void
    {
        $now = time();
        $old = gmdate('Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS);
        $today = gmdate('Y-m-d H:i:s', $now);
        $familiar = ['total_coverage' => 1, 'mastery_unlocked' => 1, 'stage' => 5, 'last_seen_at' => $old];
        $rows = array_fill_keys(range(1, 11), $familiar);
        $rows[10] = ['total_coverage' => 1, 'last_seen_at' => $today];
        $members = [11 => array_fill_keys(range(1, 8), $old), 12 => [3 => $old, 9 => $old, 10 => $old, 11 => $old, 12 => $today]];
        $level = ll_tools_wordset_level_summarize(0, 0, [1 => [11 => [], 12 => []]], $members, [12 => $old],
            $rows, [1 => 30], $now)['levels'][1];
        $this->assertArrayNotHasKey('label', $level['practice_activity']);
        $this->assertSame(12, $level['next_category_id']);
        $this->assertSame(12, $level['practice_category_id']);
        $this->assertSame([12, 10, 3], $level['practice_activity']['session_word_ids']);
        $this->assertSame([12 => 12, 10 => 12, 3 => 12], $level['practice_activity']['word_category_ids']);
        $this->assertNotContains(12, $level['due_activity']['session_word_ids']);
        $this->assertNotContains(10, $level['due_activity']['session_word_ids']);
        $this->assertSame(11, $level['due_category_id']);
        // A level with a simple majority mastered still introduces its unseen words through Learn.
        foreach ([4, 5, 6] as $id) { unset($rows[$id]); }
        $early = ll_tools_wordset_level_summarize(0, 0, [1 => [11 => [], 12 => []]], $members, [12 => $old],
            $rows, [1 => 30], $now)['levels'][1];
        $this->assertContains(10, $early['practice_activity']['session_word_ids']);
        $this->assertSame([], array_intersect([4, 5, 6, 12], $early['practice_activity']['session_word_ids']));
        $this->assertNotSame(0, $early['next_category_id']);
    }

    public function test_mixed_lesson_practice_labels_the_focus_of_the_round(): void
    {
        $now = time();
        $last = gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS);
        $studied = ['total_coverage' => 1, 'last_seen_at' => $last];
        $learned = $studied + ['mastery_unlocked' => 1, 'stage' => 5];
        $members = [11 => array_fill_keys(range(1, 8), true), 12 => array_fill_keys(range(9, 20), true)];
        $rows = array_fill_keys(range(1, 20), $learned);
        $levels = [1 => [11 => [], 12 => []]];
        $summary = ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $rows, [1 => 30], $now);
        $practice = $summary['levels'][1]['practice_activity'];
        $this->assertCount(12, $practice['session_word_ids']);
        $this->assertSame('12 learned words', $practice['label']);

        $rows[1] = $studied;
        $rows[9] = $studied + ['incorrect' => 8];
        unset($rows[20]);
        $summary = ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $rows, [1 => 30], $now);
        $practice = $summary['levels'][1]['practice_activity'];
        $this->assertCount(4, $practice['session_word_ids']);
        $this->assertSame('New and in progress', $practice['label']);
    }

    public function test_ten_or_more_eligible_in_progress_words_form_the_whole_level_session(): void
    {
        $now = time();
        $recent = ['total_coverage' => 1, 'last_seen_at' => gmdate('Y-m-d H:i:s', $now)];
        $hard = ['incorrect' => 8, 'last_seen_at' => gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS)] + $recent;
        $learned = ['mastery_unlocked' => 1, 'stage' => 5] + $recent;
        $members = [
            11 => array_fill_keys(array_merge(range(1, 5), range(41, 140)), true),
            12 => array_fill_keys(array_merge([3], range(6, 40)), true),
            13 => [200 => true],
        ];
        $levels = [1 => [11 => [], 12 => [], 13 => ['practice_supported' => false]]];
        foreach ([9, 10, 11, 25] as $count) {
            $rows = array_fill_keys(range(1, 139), $learned); // 140 is new in this nearly complete level.
            foreach (range(1, $count) as $id) { $rows[$id] = $recent; }
            $rows[$count] = $hard;
            $rows[200] = $recent; // Studied, but its only category cannot launch Practice.
            $level = ll_tools_wordset_level_summarize(0, 0, $levels, $members, [], $rows, [], $now)['levels'][1];
            $activity = $level['practice_activity'];
            if ($count === 9) {
                $this->assertContains(140, $activity['session_word_ids']);
                $this->assertCount(12, $activity['session_word_ids']);
                $this->assertNotContains(200, $activity['session_word_ids']);
                continue;
            }
            // Keep the older hard word first and a shared word in its first practice-eligible lesson.
            $expected = array_merge([$count], range(1, $count - 1));
            $this->assertSame($expected, $activity['session_word_ids']);
            $this->assertSame($count . ' words in progress', $activity['label']);
            foreach ($expected as $id) {
                $this->assertSame($id <= 5 ? 11 : 12, $activity['word_category_ids'][$id]);
            }
            $plan = ll_tools_wordset_level_bounded_plan($activity);
            $this->assertSame(array_chunk($expected, 12), array_column($plan['chunks'], 'word_ids'));
        }
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
        $first = ll_tools_wordset_level_project_generation($key, $hash, $generation);
        $this->assertWPError($first);
        $this->assertSame('ll_level_progress_preparing', $first->get_error_code());
        $this->assertFalse(get_transient($key));
        $cursor = get_transient($key . '_p_' . substr($generation, 0, 20));
        $this->assertSame($id + 249, $cursor['cursor']);
        $this->assertArrayHasKey($id, $cursor['members']);
        $final = ll_tools_wordset_level_project_generation($key, $hash, $generation);
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

    public function test_clones_and_imports_leave_legacy_notification_metadata_local(): void
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

    public function test_pending_richer_word_in_another_level_suppresses_the_base_only_on_the_whole_course(): void
    {
        $uid = self::factory()->user->create(['role' => 'subscriber']);
        $ws = self::factory()->term->create(['taxonomy' => 'wordset']);
        $source = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        $base = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish']);
        foreach ([$source, $base] as $wid) { wp_set_post_terms($wid, [$ws], 'wordset'); }
        update_option('ll_tools_context_review_' . $ws, wp_json_encode([[$source, $base]]), false);
        $now = time();
        $old = gmdate('Y-m-d H:i:s', $now - 100 * DAY_IN_SECONDS);
        $recent = gmdate('Y-m-d H:i:s', $now - 20 * DAY_IN_SECONDS);
        $rows = [$base => ['total_coverage' => 1, 'mastery_unlocked' => 1, 'stage' => 5, 'last_seen_at' => $old],
            $source => ['total_coverage' => 1, 'last_seen_at' => $recent]];
        $levels = [1 => [11 => []], 2 => [12 => []]];
        $members = [11 => [$base => $old], 12 => [$source => $old]];
        $first = ll_tools_wordset_level_summarize($ws, $uid, $levels, $members, [], $rows, [1 => 90, 2 => 7], $now);
        $this->assertSame(1, $first['levels'][1]['due']);
        $this->assertSame(1, $first['levels'][2]['due']);
        $this->assertSame([$source], $first['overall']['due_word_ids']);
        $reverse = ll_tools_wordset_level_summarize($ws, $uid, $levels, $members, [], $rows, [1 => 7, 2 => 90], $now);
        $this->assertSame([$base], $reverse['overall']['due_word_ids']);
    }
}
