<?php
declare(strict_types=1);

final class WordsetRecorderQueueOverviewResourceTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var mixed */
    private $originalIsolationOption;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }
        parent::tearDown();
    }

    public function test_overview_refreshes_only_a_bounded_category_page_and_reuses_cached_summaries(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(6);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Bounded Queue Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);
        $recorder = get_userdata($recorder_id);
        $this->assertInstanceOf(WP_User::class, $recorder);

        $refresh_budget = static function (): int {
            return 2;
        };
        $candidate_queries = 0;
        $query_watcher = static function (WP_Query $query) use (&$candidate_queries): void {
            if (
                $query->get('post_type') === 'words'
                && $query->get('fields') === 'ids'
                && (int) $query->get('posts_per_page') === 120
                && (bool) $query->get('no_found_rows')
            ) {
                $candidate_queries++;
            }
        };
        add_filter('ll_tools_wordset_recorder_queue_overview_refresh_budget', $refresh_budget);
        add_action('pre_get_posts', $query_watcher);

        $base_args = [
            'summary_categories' => $fixture['categories'],
            'category_page' => 1,
            'categories_per_page' => 3,
            'recorder_page' => 1,
            'recorders_per_page' => 2,
        ];

        try {
            $first_rows = ll_tools_wordset_page_get_recorder_queue_rows(
                $wordset_id,
                $wordset_term,
                [$recorder],
                $base_args
            );

            $this->assertCount(1, $first_rows);
            $this->assertSame(2, $candidate_queries);
            $this->assertSame(2, (int) $first_rows[0]['summary_status']['refreshed']);
            $this->assertSame(1, (int) $first_rows[0]['summary_status']['pending']);
            $this->assertCount(2, $first_rows[0]['visible_groups']);
            $this->assertSame(6, (int) $first_rows[0]['summary_pagination']['total']);
            $this->assertTrue((bool) $first_rows[0]['summary_pagination']['has_next']);

            $_GET = ['ll_wordset_tool' => 'recorder-queues'];
            $first_html = ll_tools_wordset_page_render_settings_recorder_queues_tool(
                $wordset_term,
                $wordset_id,
                '',
                $first_rows
            );
            $this->assertStringContainsString('Updating queue summaries: 2 of 3 ready.', $first_html);
            $this->assertStringContainsString('Continue', $first_html);
            $this->assertStringNotContainsString('No words currently need recordings for this recorder.', $first_html);
            $this->assertStringContainsString('ll_recorder_queue_categories_page=2', $first_html);

            $candidate_queries = 0;
            $second_rows = ll_tools_wordset_page_get_recorder_queue_rows(
                $wordset_id,
                $wordset_term,
                [$recorder],
                $base_args
            );
            $this->assertSame(1, $candidate_queries);
            $this->assertSame(1, (int) $second_rows[0]['summary_status']['refreshed']);
            $this->assertSame(0, (int) $second_rows[0]['summary_status']['pending']);
            $this->assertCount(3, $second_rows[0]['visible_groups']);

            $candidate_queries = 0;
            $cached_rows = ll_tools_wordset_page_get_recorder_queue_rows(
                $wordset_id,
                $wordset_term,
                [$recorder],
                $base_args
            );
            $this->assertSame(0, $candidate_queries);
            $this->assertSame(0, (int) $cached_rows[0]['summary_status']['refreshed']);
            $this->assertSame(0, (int) $cached_rows[0]['summary_status']['pending']);
            $this->assertCount(3, $cached_rows[0]['visible_groups']);

            $empty_page_rows = $cached_rows;
            $empty_page_rows[0]['visible_groups'] = [];
            $empty_page_html = ll_tools_wordset_page_render_settings_recorder_queues_tool(
                $wordset_term,
                $wordset_id,
                '',
                $empty_page_rows
            );
            $this->assertStringContainsString('No queued words on this category page.', $empty_page_html);
            $this->assertStringNotContainsString('No words currently need recordings for this recorder.', $empty_page_html);

            $candidate_queries = 0;
            $second_page_rows = ll_tools_wordset_page_get_recorder_queue_rows(
                $wordset_id,
                $wordset_term,
                [$recorder],
                array_merge($base_args, ['category_page' => 2])
            );
            $this->assertSame(2, $candidate_queries);
            $this->assertCount(2, $second_page_rows[0]['visible_groups']);
            $this->assertSame(1, (int) $second_page_rows[0]['summary_status']['pending']);
            $this->assertSame(2, (int) $second_page_rows[0]['summary_pagination']['page']);
            $this->assertFalse((bool) $second_page_rows[0]['summary_pagination']['has_next']);

            $second_page_slugs = array_column($second_page_rows[0]['visible_groups'], 'slug');
            $this->assertNotContains((string) $fixture['categories'][0]['slug'], $second_page_slugs);
            $this->assertContains((string) $fixture['categories'][3]['slug'], $second_page_slugs);
        } finally {
            remove_action('pre_get_posts', $query_watcher);
            remove_filter('ll_tools_wordset_recorder_queue_overview_refresh_budget', $refresh_budget);
        }
    }

    public function test_overview_pages_recorders_before_building_rows(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $wordset = wp_insert_term('Recorder Page Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorders = [];
        for ($index = 1; $index <= 5; $index++) {
            $recorder_id = self::factory()->user->create([
                'role' => 'audio_recorder',
                'display_name' => 'Paged Recorder ' . $index,
            ]);
            update_user_meta($recorder_id, 'll_recording_config', [
                'wordset' => (string) $wordset_term->slug,
            ]);
            $recorder = get_userdata($recorder_id);
            $this->assertInstanceOf(WP_User::class, $recorder);
            $recorders[] = $recorder;
        }

        $rows = ll_tools_wordset_page_get_recorder_queue_rows(
            $wordset_id,
            $wordset_term,
            $recorders,
            [
                'hidden_view' => true,
                'recorder_page' => 2,
                'recorders_per_page' => 2,
            ]
        );

        $this->assertCount(2, $rows);
        $this->assertSame((int) $recorders[2]->ID, (int) $rows[0]['user_id']);
        $this->assertSame((int) $recorders[3]->ID, (int) $rows[1]['user_id']);
        $this->assertSame(5, (int) $rows[0]['recorder_pagination']['total']);
        $this->assertSame(2, (int) $rows[0]['recorder_pagination']['page']);
        $this->assertTrue((bool) $rows[0]['recorder_pagination']['has_prev']);
        $this->assertTrue((bool) $rows[0]['recorder_pagination']['has_next']);
    }

    public function test_overview_category_source_paginates_the_compact_category_list(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createWordsetWithCategories(6);

        $page = ll_tools_wordset_page_get_recorder_queue_summary_category_page(
            (int) $fixture['wordset_id'],
            2,
            2
        );

        $this->assertSame(6, (int) $page['total']);
        $this->assertSame(2, (int) $page['page']);
        $this->assertSame(2, (int) $page['per_page']);
        $this->assertSame(3, (int) $page['total_pages']);
        $this->assertCount(2, $page['categories']);
        $this->assertSame(
            array_column(array_slice($fixture['categories'], 2, 2), 'slug'),
            array_column($page['categories'], 'slug')
        );
    }

    public function test_overview_category_source_preserves_content_categories_order_and_uncategorized_without_empty_shells(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];

        $shared_term = wp_insert_term('Shared Recorder Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($shared_term);
        $shared_category_id = (int) $shared_term['term_id'];
        update_term_meta($shared_category_id, 'll_desired_recording_types', ['isolation']);
        $shared_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Shared Recorder Category Word',
        ]);
        wp_set_post_terms($shared_word_id, [$shared_category_id], 'word-category', false);
        wp_set_post_terms($shared_word_id, [$wordset_id], 'wordset', false);

        $empty_term = wp_insert_term('Empty Owned Recorder Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertIsArray($empty_term);
        $empty_category_id = (int) $empty_term['term_id'];
        ll_tools_set_category_wordset_owner($empty_category_id, $wordset_id, $empty_category_id);

        $uncategorized_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Uncategorized Recorder Word',
        ]);
        wp_set_post_terms($uncategorized_word_id, [$wordset_id], 'wordset', false);

        $manual_order = [
            $shared_category_id,
            (int) $fixture['categories'][1]['id'],
            (int) $fixture['categories'][0]['id'],
            $empty_category_id,
        ];
        update_term_meta($wordset_id, 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta($wordset_id, 'll_wordset_category_manual_order', implode(',', $manual_order));

        $page = ll_tools_wordset_page_get_recorder_queue_summary_category_page($wordset_id, 1, 20);
        $slugs = array_column($page['categories'], 'slug');

        $this->assertSame(4, (int) $page['total']);
        $this->assertSame([
            (string) get_term_field('slug', $shared_category_id, 'word-category'),
            (string) $fixture['categories'][1]['slug'],
            (string) $fixture['categories'][0]['slug'],
            'uncategorized',
        ], $slugs);
        $this->assertNotContains((string) get_term_field('slug', $empty_category_id, 'word-category'), $slugs);
    }

    public function test_overview_image_scan_uses_only_the_current_candidate_batch_for_reverse_lookup(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $category = $fixture['categories'][0];
        $category_id = (int) $category['id'];

        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Bounded summary image attachment',
            'post_mime_type' => 'image/png',
        ]);
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bounded summary candidate image',
        ]);
        update_post_meta($image_id, '_thumbnail_id', $attachment_id);
        wp_set_post_terms($image_id, [$category_id], 'word-category', false);

        $linked_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bounded summary linked word',
        ]);
        update_post_meta($linked_word_id, '_ll_autopicked_image_id', $image_id);
        wp_set_post_terms($linked_word_id, [$wordset_id], 'wordset', false);

        for ($index = 1; $index <= 30; $index++) {
            $unrelated_word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Unrelated summary image word %02d', $index),
            ]);
            update_post_meta($unrelated_word_id, '_ll_autopicked_image_id', 100000 + $index);
            wp_set_post_terms($unrelated_word_id, [$wordset_id], 'wordset', false);
        }

        $legacy_whole_map_queries = [];
        $query_watcher = static function (WP_Query $query) use (&$legacy_whole_map_queries): void {
            if (!in_array('words', (array) $query->get('post_type'), true) || (int) $query->get('posts_per_page') !== -1) {
                return;
            }

            $meta_query_json = wp_json_encode($query->get('meta_query'));
            if (
                is_string($meta_query_json)
                && strpos($meta_query_json, '_thumbnail_id') !== false
                && strpos($meta_query_json, '_ll_autopicked_image_id') !== false
            ) {
                $legacy_whole_map_queries[] = $query->query_vars;
            }
        };

        add_action('pre_get_posts', $query_watcher);
        try {
            $scan = ll_tools_wordset_page_advance_recorder_queue_summary_scan(
                $category,
                $wordset_id,
                get_current_user_id(),
                '',
                '',
                [
                    'phase' => 'images',
                    'word_offset' => 0,
                    'image_offset' => 0,
                    'valid_seen' => 0,
                    'candidates' => [],
                ]
            );
        } finally {
            remove_action('pre_get_posts', $query_watcher);
        }

        $this->assertSame([], $legacy_whole_map_queries, 'Overview image summaries must not build a whole-wordset reverse image map.');
        $this->assertTrue((bool) $scan['complete']);
        $this->assertSame([], $scan['candidates'], 'An image already linked to a word in this wordset must not be queued twice.');
    }

    public function test_focused_category_page_excludes_a_linked_candidate_image_without_a_whole_wordset_map(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $wordset = wp_insert_term('Focused Candidate Wordset ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Focused Candidate Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Focused Candidate Attachment',
            'post_mime_type' => 'image/png',
        ]);
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Focused Candidate Image',
        ]);
        update_post_meta($image_id, '_thumbnail_id', $attachment_id);
        wp_set_post_terms($image_id, [$category_id], 'word-category', false);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Focused Candidate Linked Word',
        ]);
        update_post_meta($word_id, '_ll_autopicked_image_id', $image_id);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $legacy_whole_map_queries = [];
        $query_watcher = static function (WP_Query $query) use (&$legacy_whole_map_queries): void {
            if (!in_array('words', (array) $query->get('post_type'), true) || (int) $query->get('posts_per_page') !== -1) {
                return;
            }
            $meta_query_json = wp_json_encode($query->get('meta_query'));
            if (
                is_string($meta_query_json)
                && strpos($meta_query_json, '_thumbnail_id') !== false
                && strpos($meta_query_json, '_ll_autopicked_image_id') !== false
            ) {
                $legacy_whole_map_queries[] = $query->query_vars;
            }
        };

        add_action('pre_get_posts', $query_watcher);
        try {
            $page = ll_tools_wordset_page_get_recorder_queue_category_candidate_word_page(
                $wordset_id,
                (string) get_term_field('slug', $category_id, 'word-category'),
                1,
                5
            );
        } finally {
            remove_action('pre_get_posts', $query_watcher);
        }

        $this->assertSame([], $legacy_whole_map_queries);
        $this->assertSame([], $page['image_ids']);
        $this->assertSame([], $page['ids']);
    }

    public function test_overview_resumes_a_mostly_hidden_category_without_scanning_it_all_at_once(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $category = $fixture['categories'][0];
        $category_id = (int) $category['id'];

        for ($index = 2; $index <= 100; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Mostly Hidden Word %03d', $index),
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Mostly Hidden Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);
        $recorder = get_userdata($recorder_id);
        $this->assertInstanceOf(WP_User::class, $recorder);

        $word_ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [$category_id],
            ]],
        ]);
        $this->assertCount(100, $word_ids);
        $hidden = [];
        foreach ($word_ids as $word_id) {
            $key = 'word:' . (int) $word_id;
            $hidden[$key] = [
                'key' => $key,
                'word_id' => (int) $word_id,
                'title' => (string) get_the_title($word_id),
                'category_name' => (string) $category['name'],
                'category_slug' => (string) $category['slug'],
                'hidden_at' => gmdate('c'),
            ];
        }
        $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, $hidden));

        $chunk_size = static function (): int {
            return 40;
        };
        $candidate_queries = 0;
        $query_watcher = static function (WP_Query $query) use (&$candidate_queries): void {
            if (
                $query->get('post_type') === 'words'
                && $query->get('fields') === 'ids'
                && (int) $query->get('posts_per_page') === 40
                && (bool) $query->get('no_found_rows')
            ) {
                $candidate_queries++;
            }
        };
        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        add_action('pre_get_posts', $query_watcher);

        $args = [
            'summary_categories' => [$category],
            'refresh_budget' => 1,
        ];
        try {
            $first_rows = ll_tools_wordset_page_get_recorder_queue_rows(
                $wordset_id,
                $wordset_term,
                [$recorder],
                $args
            );
            $this->assertSame(2, $candidate_queries);
            $this->assertSame(1, (int) $first_rows[0]['summary_status']['pending']);
            $this->assertSame([], $first_rows[0]['visible_groups']);

            $candidate_queries = 0;
            $second_rows = ll_tools_wordset_page_get_recorder_queue_rows(
                $wordset_id,
                $wordset_term,
                [$recorder],
                $args
            );
            $this->assertSame(1, $candidate_queries);
            $this->assertSame(0, (int) $second_rows[0]['summary_status']['pending']);
            $this->assertSame([], $second_rows[0]['visible_groups']);
        } finally {
            remove_action('pre_get_posts', $query_watcher);
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        }
    }

    /**
     * @return array{wordset_id:int,categories:array<int,array{id:int,name:string,slug:string}>}
     */
    private function createWordsetWithCategories(int $count): array
    {
        $wordset = wp_insert_term('Bounded Queue Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $categories = [];

        for ($index = 1; $index <= $count; $index++) {
            $category = wp_insert_term(
                sprintf('Bounded Queue Category %02d %s', $index, wp_generate_password(4, false)),
                'word-category'
            );
            $this->assertIsArray($category);
            $category_id = (int) $category['term_id'];
            $category_term = get_term($category_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $category_term);
            update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
            ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);

            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Bounded Queue Word %02d', $index),
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

            $categories[] = [
                'id' => $category_id,
                'name' => (string) $category_term->name,
                'slug' => (string) $category_term->slug,
            ];
        }

        return [
            'wordset_id' => $wordset_id,
            'categories' => $categories,
        ];
    }

    private function ensureRecordingType(string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, 'recording_type');
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, 'recording_type', ['slug' => $slug]);
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }
}
