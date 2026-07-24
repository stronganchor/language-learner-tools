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

    public function test_stream_overview_renders_shimmer_shells_without_numbered_overview_paging_or_continue_link(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(5);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorders = [];
        foreach (['Selected Stream Recorder', 'Alternate Stream Recorder'] as $display_name) {
            $recorder_id = self::factory()->user->create([
                'role' => 'audio_recorder',
                'display_name' => $display_name,
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
                'stream_view' => true,
                'focused_user_id' => (int) $recorders[0]->ID,
                'summary_categories' => array_slice($fixture['categories'], 0, 2),
                'summary_categories_paged' => true,
                'summary_category_total' => count($fixture['categories']),
                'categories_per_page' => 2,
            ]
        );

        $this->assertCount(1, $rows);
        $this->assertSame((int) $recorders[0]->ID, (int) $rows[0]['user_id']);
        $this->assertSame(0, (int) ($rows[0]['summary_status']['refreshed'] ?? -1));

        $_GET = ['ll_wordset_tool' => 'recorder-queues'];
        $html = ll_tools_wordset_page_render_settings_recorder_queues_tool(
            $wordset_term,
            $wordset_id,
            '',
            $rows,
            [
                'stream_view' => true,
                'selected_recorder_user_id' => (int) $recorders[0]->ID,
                'assigned_audio_recorders' => $recorders,
                'stream_categories' => $fixture['categories'],
            ]
        );

        $this->assertStringContainsString('data-ll-recorder-queue-summary-root', $html);
        $this->assertStringContainsString('data-ll-recorder-queue-summary-retry', $html);
        $this->assertStringNotContainsString('data-ll-recorder-queue-summary-load-more', $html);
        $this->assertStringNotContainsString('data-ll-recorder-queue-summary-count', $html);
        $this->assertStringNotContainsString('Queue categories loaded:', $html);
        $this->assertStringNotContainsString('>Load more<', $html);
        $this->assertSame(
            count($fixture['categories']),
            substr_count($html, 'data-ll-recorder-queue-summary-placeholder="true"')
        );
        $this->assertSame(3, substr_count($html, 'll-wordset-recorder-queue-category-card--loading'));
        $this->assertSame(2, substr_count($html, 'll-wordset-recorder-queue-category-marker'));
        $this->assertStringContainsString('ll-wordset-card--lazy-placeholder', $html);
        foreach ($fixture['categories'] as $category) {
            $this->assertStringContainsString(esc_attr((string) $category['name']), $html);
        }
        $this->assertStringNotContainsString('ll_recorder_queue_recorders_page=', $html);
        $this->assertStringNotContainsString('ll_recorder_queue_categories_page=', $html);
        $this->assertStringNotContainsString('Recorder queue recorder pages', $html);
        $this->assertStringNotContainsString('Queue category pages for', $html);
        $this->assertStringNotContainsString('>Continue<', $html);
    }

    public function test_incomplete_stream_catalog_renders_retry_instead_of_authoritative_empty_state(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $injected = false;
        $term_failure = static function ($terms, array $taxonomies) use (&$injected) {
            if (!$injected && in_array('word-category', $taxonomies, true)) {
                $injected = true;
                return new WP_Error('ll_tools_test_incomplete_recorder_catalog');
            }
            return $terms;
        };
        add_filter('get_terms', $term_failure, 10, 2);
        try {
            $page_complete = true;
            $failed_page = ll_tools_wordset_page_get_recorder_queue_summary_category_page(
                $wordset_id,
                1,
                6,
                get_current_user_id(),
                $page_complete
            );
        } finally {
            remove_filter('get_terms', $term_failure, 10);
        }
        $this->assertTrue($injected);
        $this->assertFalse($page_complete);
        $this->assertFalse((bool) ($failed_page['complete'] ?? true));
        $this->assertSame([], $failed_page['categories'] ?? null);

        $recovered_complete = false;
        $recovered_page = ll_tools_wordset_page_get_recorder_queue_summary_category_page(
            $wordset_id,
            1,
            6,
            get_current_user_id(),
            $recovered_complete
        );
        $this->assertTrue($recovered_complete);
        $this->assertTrue((bool) ($recovered_page['complete'] ?? false));
        $this->assertNotEmpty($recovered_page['categories'] ?? []);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Catalog Retry Recorder',
        ]);
        $recorder = get_userdata($recorder_id);
        $this->assertInstanceOf(WP_User::class, $recorder);

        $rows = ll_tools_wordset_page_get_recorder_queue_rows(
            $wordset_id,
            $wordset_term,
            [$recorder],
            [
                'stream_view' => true,
                'focused_user_id' => $recorder_id,
                'summary_categories' => [],
                'summary_categories_complete' => false,
            ]
        );

        $_GET = ['ll_wordset_tool' => 'recorder-queues'];
        $html = ll_tools_wordset_page_render_settings_recorder_queues_tool(
            $wordset_term,
            $wordset_id,
            '',
            $rows,
            [
                'stream_view' => true,
                'selected_recorder_user_id' => $recorder_id,
                'assigned_audio_recorders' => [$recorder],
                'stream_categories' => [],
                'stream_catalog_complete' => false,
            ]
        );

        $this->assertStringContainsString(
            'The category catalog is temporarily unavailable. Please try again.',
            $html
        );
        $this->assertMatchesRegularExpression('/>\s*Retry\s*<\/a>/', $html);
        $this->assertStringNotContainsString('data-ll-recorder-queue-summary-root', $html);
        $this->assertStringNotContainsString('No words currently need recordings for this recorder.', $html);

        $page_source = $this->getFunctionSource('ll_tools_wordset_page_get_recorder_queue_summary_category_page');
        $this->assertStringContainsString('$complete', $page_source);
        $this->assertStringContainsString("'complete' => \$complete", $page_source);

        $controller_source = $this->getFunctionSource('ll_tools_render_wordset_page_content');
        $this->assertStringContainsString("'enabled' => \$recorder_queue_stream_catalog_complete", $controller_source);
        $this->assertStringContainsString("'stream_catalog_complete' => \$recorder_queue_stream_catalog_complete", $controller_source);

        $script_source = file_get_contents(LL_TOOLS_BASE_PATH . 'js/wordset-pages.js');
        $this->assertIsString($script_source);
        $this->assertStringContainsString('const recorderQueueCatalogComplete =', $script_source);
        $this->assertStringContainsString('ll-tools-recorder-catalog-retry', $script_source);
        $this->assertStringContainsString('window.location.reload();', $script_source);
    }

    public function test_summary_batch_slug_normalization_deduplicates_and_caps_the_request(): void
    {
        $batch_size = static function (): int {
            return 3;
        };
        add_filter('ll_tools_wordset_recorder_queue_summary_batch_size', $batch_size);

        try {
            $normalized = ll_tools_wordset_page_normalize_recorder_queue_summary_slugs([
                'Alpha One',
                'alpha-one',
                '',
                ['not-a-scalar'],
                'BETA',
                'Gamma',
                'Delta',
            ]);
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_summary_batch_size', $batch_size);
        }

        $this->assertSame(['alpha-one', 'beta', 'gamma'], $normalized);
    }

    public function test_summary_batch_can_render_recorder_category_buttons_with_counts_and_previews(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $category = $fixture['categories'][0];
        for ($index = 2; $index <= 5; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Exact Recorder Summary Word %02d', $index),
            ]);
            wp_set_post_terms($word_id, [(int) $category['id']], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }

        $batch = ll_tools_wordset_page_build_recorder_queue_summary_batch(
            $wordset_id,
            $wordset_term,
            $admin_id,
            [(string) $category['slug']],
            '',
            ['card_interaction' => 'button']
        );

        $this->assertSame([(string) $category['slug']], $batch['resolvedSlugs']);
        $this->assertSame([], $batch['pendingSlugs']);
        $this->assertCount(1, $batch['cards']);
        $card = $batch['cards'][0];
        $this->assertSame(5, (int) $card['count']);
        $this->assertFalse((bool) $card['countIsLowerBound']);
        $this->assertSame((string) $category['name'] . ' (5)', (string) $card['optionLabel']);
        $this->assertStringContainsString('<button', (string) $card['html']);
        $this->assertStringContainsString('ll-recorder-category-card', (string) $card['html']);
        $this->assertStringContainsString('data-recorder-queue-count="5"', (string) $card['html']);
        $this->assertStringContainsString('data-recorder-queue-count-lower-bound="0"', (string) $card['html']);
        $this->assertStringContainsString('5 words', (string) $card['html']);
        $this->assertStringNotContainsString('5+ words', (string) $card['html']);
        $this->assertStringNotContainsString('href=', (string) $card['html']);
        $this->assertStringNotContainsString('aria-pressed', (string) $card['html']);

        $placeholder = ll_tools_wordset_page_render_recorder_queue_category_placeholder($category);
        $this->assertStringContainsString((string) $category['name'], $placeholder);
        $this->assertStringContainsString('data-recorder-queue-category-name=', $placeholder);
        $this->assertStringNotContainsString('Loading recording category', $placeholder);
        $hidden_placeholder = ll_tools_wordset_page_render_recorder_queue_category_placeholder($category, ['hidden' => true]);
        $this->assertMatchesRegularExpression('/aria-busy="true"\s+hidden/', $hidden_placeholder);
        $neutral_placeholder = ll_tools_wordset_page_render_recorder_queue_category_placeholder($category, ['neutral' => true]);
        $this->assertStringContainsString('Loading recording category', $neutral_placeholder);
        $this->assertStringNotContainsString((string) $category['name'], $neutral_placeholder);
        $this->assertStringNotContainsString('data-recorder-queue-category-name=', $neutral_placeholder);

        $this->ensureRecordingType('Question', 'question');
        $scoped_batch = ll_tools_wordset_page_build_recorder_queue_summary_batch(
            $wordset_id,
            $wordset_term,
            $admin_id,
            [(string) $category['slug']],
            '',
            [
                'card_interaction' => 'button',
                'include_recording_types' => 'question',
                'exclude_recording_types' => '',
            ]
        );
        $this->assertSame([(string) $category['slug']], $scoped_batch['resolvedSlugs']);
        $this->assertSame([], $scoped_batch['cards'], 'Shortcode recording-type overrides must scope overview counts.');
    }

    public function test_manager_recorder_summary_ajax_enforces_access_and_returns_a_bounded_batch(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);
        $category = $fixture['categories'][0];

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Direct Summary AJAX Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $response = $this->postManagerRecorderSummaryAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_recorder_queue_summaries'),
            'wordset_id' => (string) $wordset_id,
            'recorder_user_id' => (string) $recorder_id,
            'category_slugs' => [(string) $category['slug']],
            'back_url' => home_url('/safe-return/'),
        ]);

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $this->assertSame(ll_tools_wordset_page_get_recorder_queue_summary_batch_size(), (int) ($data['batchSize'] ?? 0));
        $this->assertSame(1, (int) ($data['sourceTotal'] ?? 0));
        $this->assertContains(
            (string) $category['slug'],
            array_merge((array) ($data['resolvedSlugs'] ?? []), (array) ($data['pendingSlugs'] ?? []))
        );

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber_id);
        $blocked = $this->postManagerRecorderSummaryAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_recorder_queue_summaries'),
            'wordset_id' => (string) $wordset_id,
            'recorder_user_id' => (string) $recorder_id,
            'category_slugs' => [(string) $category['slug']],
        ]);

        $this->assertFalse((bool) ($blocked['success'] ?? true));
        $this->assertSame('permission', (string) ($blocked['data']['code'] ?? ''));
    }

    public function test_summary_stream_starts_with_three_then_uses_resource_safe_six_category_batches(): void
    {
        $this->assertSame(3, ll_tools_wordset_page_get_recorder_queue_summary_initial_batch_size());
        $this->assertSame(6, ll_tools_wordset_page_get_recorder_queue_summary_batch_size());

        $background_batch_size = static function (): int {
            return 2;
        };
        add_filter('ll_tools_wordset_recorder_queue_summary_batch_size', $background_batch_size);

        try {
            $this->assertSame(2, ll_tools_wordset_page_get_recorder_queue_summary_batch_size());
            $this->assertSame(
                2,
                ll_tools_wordset_page_get_recorder_queue_summary_initial_batch_size(),
                'Lower operator caps must also bound the server-rendered first batch.'
            );
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_summary_batch_size', $background_batch_size);
        }

        $oversized_background_batch = static function (): int {
            return 99;
        };
        add_filter('ll_tools_wordset_recorder_queue_summary_batch_size', $oversized_background_batch);

        try {
            $this->assertSame(20, ll_tools_wordset_page_get_recorder_queue_summary_batch_size());
            $this->assertSame(3, ll_tools_wordset_page_get_recorder_queue_summary_initial_batch_size());
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_summary_batch_size', $oversized_background_batch);
        }

        $initial_batch_size = static function (): int {
            return 5;
        };
        add_filter('ll_tools_wordset_recorder_queue_summary_initial_batch_size', $initial_batch_size);

        try {
            $this->assertSame(5, ll_tools_wordset_page_get_recorder_queue_summary_initial_batch_size());
            $this->assertSame(6, ll_tools_wordset_page_get_recorder_queue_summary_batch_size());
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_summary_initial_batch_size', $initial_batch_size);
        }
    }

    public function test_compact_summary_preview_prefers_a_sized_featured_image_over_the_raw_queue_url(): void
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Recorder preview word',
        ]);
        $word_attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Unrelated word thumbnail',
        ]);
        update_post_meta($word_id, '_thumbnail_id', $word_attachment_id);

        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Recorder preview image',
        ]);
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/jpeg',
            'post_title' => 'Recorder preview thumbnail',
        ]);
        update_post_meta($image_id, '_thumbnail_id', $attachment_id);

        $thumbnail_url = 'https://example.test/recorder-preview-thumbnail.jpg';
        $word_thumbnail_url = 'https://example.test/unrelated-word-thumbnail.jpg';
        $image_downsize = static function ($downsize, int $requested_attachment_id, $size) use (
            $attachment_id,
            $thumbnail_url,
            $word_attachment_id,
            $word_thumbnail_url
        ) {
            if ($requested_attachment_id === $attachment_id && $size === 'thumbnail') {
                return [$thumbnail_url, 150, 150, true];
            }
            if ($requested_attachment_id === $word_attachment_id && $size === 'thumbnail') {
                return [$word_thumbnail_url, 150, 150, true];
            }
            return $downsize;
        };
        add_filter('image_downsize', $image_downsize, 10, 3);

        try {
            $resolved = ll_tools_wordset_page_get_recorder_queue_item_image_url([
                'word_id' => $word_id,
                'image_id' => $image_id,
                'image_url' => 'https://example.test/oversized-recorder-preview.jpg',
            ], 'thumbnail');
        } finally {
            remove_filter('image_downsize', $image_downsize, 10);
        }

        $this->assertSame($thumbnail_url, $resolved);
    }

    public function test_compact_category_source_honors_the_selected_recorders_category_visibility(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $private_category_id = (int) $fixture['categories'][1]['id'];
        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_term_meta($private_category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        update_term_meta($private_category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$admin_id]);

        $admin_slugs = array_column(
            ll_tools_wordset_page_get_recorder_queue_summary_categories($wordset_id, $admin_id),
            'slug'
        );
        $recorder_slugs = array_column(
            ll_tools_wordset_page_get_recorder_queue_summary_categories($wordset_id, $recorder_id),
            'slug'
        );

        $this->assertContains((string) $fixture['categories'][1]['slug'], $admin_slugs);
        $this->assertNotContains((string) $fixture['categories'][1]['slug'], $recorder_slugs);
        $this->assertContains((string) $fixture['categories'][0]['slug'], $recorder_slugs);
    }

    public function test_admin_demotion_invalidates_private_category_catalog_maps_and_completed_summaries(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        $other_admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $private_category = $fixture['categories'][1];
        $private_category_id = (int) $private_category['id'];
        $private_category_slug = (string) $private_category['slug'];
        update_term_meta($private_category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        update_term_meta($private_category_id, LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, [$other_admin_id]);

        $before_catalog_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key(
            $wordset_id,
            $recorder_id
        );
        $before_categories = ll_tools_wordset_page_get_recorder_queue_summary_categories(
            $wordset_id,
            $recorder_id
        );
        $before_category_map = ll_tools_recorder_get_category_id_map_for_wordsets([$wordset_id], $recorder_id);
        $before_source_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
            $recorder_id,
            $wordset_id,
            $private_category
        );
        $before_status = [];
        $before_states = [];
        $before_groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
            [$private_category],
            $wordset_id,
            $recorder_id,
            'isolation',
            '',
            1,
            $before_status,
            $before_states
        );

        $this->assertSame('manage-options', ll_tools_wordset_page_get_recorder_category_access_signature($recorder_id));
        $this->assertContains($private_category_slug, array_column($before_categories, 'slug'));
        $this->assertArrayHasKey($private_category_id, $before_category_map);
        $this->assertCount(1, $before_groups);
        $this->assertSame(1, (int) ($before_groups[0]['count'] ?? 0));
        $this->assertTrue((bool) ($before_states[$private_category_slug]['complete'] ?? false));

        $recorder = get_userdata($recorder_id);
        $this->assertInstanceOf(WP_User::class, $recorder);
        $recorder->set_role('audio_recorder');
        clean_user_cache($recorder_id);
        wp_set_current_user(0);
        wp_set_current_user($recorder_id);

        $after_catalog_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key(
            $wordset_id,
            $recorder_id
        );
        $after_source_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
            $recorder_id,
            $wordset_id,
            $private_category
        );
        $after_categories = ll_tools_wordset_page_get_recorder_queue_summary_categories(
            $wordset_id,
            $recorder_id
        );
        $after_category_map = ll_tools_recorder_get_category_id_map_for_wordsets([$wordset_id], $recorder_id);
        $after_status = [];
        $after_states = [];
        $after_groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
            [$private_category],
            $wordset_id,
            $recorder_id,
            'isolation',
            '',
            1,
            $after_status,
            $after_states
        );

        $this->assertTrue(current_user_can('view_ll_tools'));
        $this->assertFalse(current_user_can('manage_options'));
        $this->assertSame('scoped', ll_tools_wordset_page_get_recorder_category_access_signature($recorder_id));
        $this->assertNotSame($before_catalog_key, $after_catalog_key);
        $this->assertNotSame($before_source_signature, $after_source_signature);
        $this->assertNotContains($private_category_slug, array_column($after_categories, 'slug'));
        $this->assertArrayNotHasKey($private_category_id, $after_category_map);
        $this->assertSame([], $after_groups);
        $this->assertTrue((bool) ($after_states[$private_category_slug]['complete'] ?? false));
        $this->assertFalse((bool) ($after_states[$private_category_slug]['has_group'] ?? true));
    }

    public function test_scoped_summary_source_signature_ignores_core_last_changed_tokens(): void
    {
        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $category = $fixture['categories'][0];
        $original_using_ext_cache = (bool) wp_using_ext_object_cache();
        $original_posts_last_changed = wp_cache_get('last_changed', 'posts');
        $original_terms_last_changed = wp_cache_get('last_changed', 'terms');

        try {
            wp_using_ext_object_cache(false);
            wp_cache_set('last_changed', 'request-local-a', 'posts');
            wp_cache_set('last_changed', 'request-local-a', 'terms');
            $default_cache_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category);

            wp_cache_set('last_changed', 'request-local-b', 'posts');
            wp_cache_set('last_changed', 'request-local-b', 'terms');
            $next_request_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category);
            $this->assertSame($default_cache_signature, $next_request_signature);

            wp_using_ext_object_cache(true);
            $persistent_cache_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category);
            wp_cache_set('last_changed', 'persistent-change', 'posts');
            wp_cache_set('last_changed', 'persistent-change', 'terms');
            $changed_persistent_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category);
            $this->assertSame($persistent_cache_signature, $changed_persistent_signature);
        } finally {
            wp_using_ext_object_cache($original_using_ext_cache);
            if ($original_posts_last_changed === false) {
                wp_cache_delete('last_changed', 'posts');
            } else {
                wp_cache_set('last_changed', $original_posts_last_changed, 'posts');
            }
            if ($original_terms_last_changed === false) {
                wp_cache_delete('last_changed', 'terms');
            } else {
                wp_cache_set('last_changed', $original_terms_last_changed, 'terms');
            }
        }
    }

    public function test_scoped_summary_source_signatures_ignore_broad_content_epochs_but_track_local_and_global_configuration(): void
    {
        $option_names = [
            'll_tools_wordset_recorder_queue_structure_epoch',
            'll_tools_wordset_recorder_queue_content_epoch',
            'll_tools_wordset_editor_image_aggregate_epoch',
            'll_tools_wordset_recorder_queue_recording_type_epoch',
            'll_tools_wc_cache_epoch',
            'll_uncategorized_desired_recording_types',
        ];
        $option_backups = [];
        foreach ($option_names as $option_name) {
            $option_backups[$option_name] = get_option($option_name, null);
        }
        $original_using_ext_cache = (bool) wp_using_ext_object_cache();
        $fixture = $this->createWordsetWithCategories(2);
        $other_fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $other_wordset_id = (int) $other_fixture['wordset_id'];
        $category_a = $fixture['categories'][0];
        $category_b = $fixture['categories'][1];
        $other_category = $other_fixture['categories'][0];

        try {
            wp_using_ext_object_cache(false);
            update_option('ll_tools_wordset_recorder_queue_structure_epoch', 'structure-a', false);
            update_option('ll_tools_wordset_recorder_queue_content_epoch', 'content-a', false);
            update_option('ll_tools_wordset_editor_image_aggregate_epoch', 'image-a', false);
            update_option('ll_tools_wordset_recorder_queue_recording_type_epoch', 'recording-type-a', false);
            update_option('ll_tools_wc_cache_epoch', 101, false);
            update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);
            $signature_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $signature_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $other_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);

            update_option('ll_tools_wordset_editor_image_aggregate_epoch', 'image-b', false);
            update_option('ll_tools_wordset_recorder_queue_structure_epoch', 'structure-b', false);
            update_option('ll_tools_wordset_recorder_queue_content_epoch', 'content-b', false);
            $this->assertSame(
                $signature_a,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a),
                'Broad fallback epochs must not invalidate categorized summaries.'
            );

            update_option('ll_tools_wc_cache_epoch', 102, false);
            $eligibility_signature_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $eligibility_signature_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $eligibility_other_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);
            $this->assertNotSame($signature_a, $eligibility_signature_a);
            $this->assertNotSame($signature_b, $eligibility_signature_b);
            $this->assertNotSame($other_signature, $eligibility_other_signature);
            $signature_a = $eligibility_signature_a;
            $signature_b = $eligibility_signature_b;
            $other_signature = $eligibility_other_signature;

            update_term_meta((int) $category_a['id'], 'll_desired_recording_types', ['question']);
            $configured_signature_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $this->assertNotSame($signature_a, $configured_signature_a);
            $this->assertSame($signature_b, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b));
            $this->assertSame($other_signature, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category));

            update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'translation');
            $wordset_signature_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $wordset_signature_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $wordset_other_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);
            $this->assertNotSame($configured_signature_a, $wordset_signature_a);
            $this->assertNotSame($signature_b, $wordset_signature_b);
            $this->assertSame($other_signature, $wordset_other_signature);

            update_option('ll_tools_wordset_recorder_queue_recording_type_epoch', 'recording-type-b', false);
            $recording_type_signature_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $recording_type_signature_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $recording_type_other_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);
            $this->assertNotSame($wordset_signature_a, $recording_type_signature_a);
            $this->assertNotSame($wordset_signature_b, $recording_type_signature_b);
            $this->assertNotSame($wordset_other_signature, $recording_type_other_signature);

            update_option('ll_uncategorized_desired_recording_types', ['question'], false);
            $this->assertNotSame(
                $recording_type_signature_a,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a)
            );
            $this->assertNotSame(
                $recording_type_signature_b,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
            );
            $this->assertNotSame(
                $recording_type_other_signature,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category)
            );
        } finally {
            wp_using_ext_object_cache($original_using_ext_cache);
            foreach ($option_backups as $option_name => $value) {
                if ($value === null) {
                    delete_option($option_name);
                } else {
                    update_option($option_name, $value, false);
                }
            }
        }
    }

    public function test_summary_exact_count_combines_canonical_legacy_and_prompt_sources(): void
    {
        if (
            !defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')
        ) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Mixed Recorder Summary ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Canonical mixed recorder word',
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Mixed recorder prompt',
        ]);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
        update_post_meta(
            $prompt_card_id,
            LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY,
            'Record this mixed-source prompt.'
        );

        $missing_backup = get_option('ll_missing_audio_instances', null);
        $uncategorized_types_backup = get_option('ll_uncategorized_desired_recording_types', null);
        update_option('ll_missing_audio_instances', [
            'Legacy mixed recorder title without a word post' => 987654,
        ], false);
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);

        try {
            $summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                [
                    'id' => 0,
                    'name' => 'Uncategorized',
                    'slug' => 'uncategorized',
                ],
                $wordset_id,
                $admin_id
            );
        } finally {
            if ($missing_backup === null) {
                delete_option('ll_missing_audio_instances');
            } else {
                update_option('ll_missing_audio_instances', $missing_backup, false);
            }
            if ($uncategorized_types_backup === null) {
                delete_option('ll_uncategorized_desired_recording_types');
            } else {
                update_option('ll_uncategorized_desired_recording_types', $uncategorized_types_backup, false);
            }
        }

        $this->assertTrue((bool) $summary['complete']);
        $this->assertSame(1, (int) $summary['scan_state']['canonical_total']);
        $this->assertSame(1, (int) $summary['scan_state']['legacy_total']);
        $this->assertSame(1, (int) $summary['scan_state']['prompt_total']);
        $this->assertIsArray($summary['group']);
        $this->assertSame(3, (int) $summary['group']['count']);
        $this->assertFalse((bool) $summary['group']['count_is_lower_bound']);
        $this->assertCount(2, $summary['group']['items']);

        $html = ll_tools_wordset_page_render_recorder_queue_category_card(
            $summary['group'],
            home_url('/record/uncategorized/')
        );
        $this->assertStringContainsString('3 words', $html);
        $this->assertStringNotContainsString('3+ words', $html);
    }

    public function test_legacy_title_map_failure_keeps_exact_summary_pending_without_advancing_its_cursor(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Failed Legacy Summary ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $word_title = 'Canonical and legacy title-map failure word';
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $word_title,
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $missing_backup = get_option('ll_missing_audio_instances', null);
        $uncategorized_types_backup = get_option('ll_uncategorized_desired_recording_types', null);
        update_option('ll_missing_audio_instances', [$word_title => 987655], false);
        update_option('ll_uncategorized_desired_recording_types', ['isolation'], false);

        $injected_failures = 0;
        $break_legacy_title_map = static function (string $sql) use ($wpdb, &$injected_failures): string {
            if (
                stripos($sql, 'SELECT DISTINCT p.ID, p.post_title, tt.term_id') !== false
                && stripos($sql, $wpdb->term_relationships) !== false
                && stripos($sql, "tt.taxonomy = 'wordset'") !== false
            ) {
                $injected_failures++;
                return "SELECT p.ID, p.post_title, 0 AS term_id FROM {$wpdb->posts}_ll_tools_missing_legacy_title_map";
            }

            return $sql;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('query', $break_legacy_title_map);
        try {
            $failed_page = ll_tools_get_legacy_missing_audio_instances_page(
                'uncategorized',
                [$wordset_id],
                '',
                '',
                false,
                $admin_id,
                ['limit' => 1]
            );

            $status = [];
            $states = [];
            $groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [[
                    'id' => 0,
                    'name' => 'Uncategorized',
                    'slug' => 'uncategorized',
                ]],
                $wordset_id,
                $admin_id,
                '',
                '',
                1,
                $status,
                $states
            );
        } finally {
            remove_filter('query', $break_legacy_title_map);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        try {
            $this->assertGreaterThanOrEqual(2, $injected_failures, 'Both direct and summary scans must hit the guarded title-map query.');
            $this->assertFalse((bool) ($failed_page['complete'] ?? true));
            $this->assertTrue((bool) ($failed_page['truncated'] ?? false));
            $this->assertSame([], (array) ($failed_page['items'] ?? []));
            $this->assertSame(0, (int) ($failed_page['cursor']['raw_offset'] ?? -1));
            $this->assertSame(0, (int) ($failed_page['cursor']['eligible_seen'] ?? -1));
            $this->assertSame(0, (int) ($failed_page['eligible_seen'] ?? -1));

            $this->assertSame([], $groups);
            $this->assertSame(1, (int) ($status['pending'] ?? 0));
            $this->assertFalse((bool) ($states['uncategorized']['complete'] ?? true));
            $this->assertFalse((bool) ($states['uncategorized']['has_group'] ?? true));

            $cache_key = ll_tools_wordset_page_build_cache_key('recorder_queue_summary', [
                'schema' => 6,
                'wordset_id' => $wordset_id,
                'recorder_user_id' => $admin_id,
                'category_slug' => 'uncategorized',
                'category_name' => 'Uncategorized',
                'include_types' => '',
                'exclude_types' => '',
                'locale' => sanitize_key((string) get_locale()),
            ]);
            $cache_request = [];
            $cached_payload = ll_tools_wordset_page_get_cached_payload($cache_key, $cache_request);
            $this->assertIsArray($cached_payload);
            $this->assertFalse((bool) ($cached_payload['scan_complete'] ?? true));
            $this->assertNull($cached_payload['group'] ?? null);
            $this->assertSame(0, (int) ($cached_payload['scan_state']['legacy_cursor']['raw_offset'] ?? -1));
            $this->assertSame(0, (int) ($cached_payload['scan_state']['legacy_cursor']['eligible_seen'] ?? -1));
        } finally {
            if ($missing_backup === null) {
                delete_option('ll_missing_audio_instances');
            } else {
                update_option('ll_missing_audio_instances', $missing_backup, false);
            }
            if ($uncategorized_types_backup === null) {
                delete_option('ll_uncategorized_desired_recording_types');
            } else {
                update_option('ll_uncategorized_desired_recording_types', $uncategorized_types_backup, false);
            }
        }
    }

    public function test_exact_summary_matches_focused_queue_when_target_category_inherits_word_recording_types(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Inherited Recorder Types ' . wp_generate_password(5, false), 'wordset');
        $target_category = wp_insert_term('Empty Target Recorder Category ' . wp_generate_password(5, false), 'word-category');
        $source_category = wp_insert_term('Inherited Source Recorder Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($target_category);
        $this->assertIsArray($source_category);
        $wordset_id = (int) $wordset['term_id'];
        $target_category_id = (int) $target_category['term_id'];
        $source_category_id = (int) $source_category['term_id'];
        ll_tools_set_category_wordset_owner($target_category_id, $wordset_id, $target_category_id);
        ll_tools_set_category_wordset_owner($source_category_id, $wordset_id, $source_category_id);
        delete_term_meta($target_category_id, 'll_desired_recording_types');
        update_term_meta($source_category_id, 'll_desired_recording_types', ['isolation']);

        $target_term = get_term($target_category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $target_term);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Word inheriting recording types',
        ]);
        wp_set_post_terms($word_id, [$target_category_id, $source_category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $focused_complete = true;
        $focused_items = ll_tools_get_recording_queue_items(
            (string) $target_term->slug,
            [$wordset_id],
            '',
            '',
            false,
            $admin_id,
            [$word_id],
            [
                'candidate_word_ids_limited' => true,
                'include_prompt_cards' => false,
            ],
            $focused_complete
        );
        $this->assertTrue($focused_complete);
        $this->assertCount(1, $focused_items);
        $this->assertSame($word_id, (int) ($focused_items[0]['word_id'] ?? 0));
        $this->assertSame((string) $target_term->slug, (string) ($focused_items[0]['category_slug'] ?? ''));

        $summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
            [
                'id' => $target_category_id,
                'name' => (string) $target_term->name,
                'slug' => (string) $target_term->slug,
            ],
            $wordset_id,
            $admin_id
        );

        $this->assertTrue((bool) ($summary['complete'] ?? false));
        $this->assertSame(1, (int) ($summary['scan_state']['canonical_total'] ?? 0));
        $this->assertIsArray($summary['group'] ?? null);
        $this->assertSame(count($focused_items), (int) ($summary['group']['count'] ?? 0));
        $this->assertFalse((bool) ($summary['group']['count_is_lower_bound'] ?? true));
        $this->assertCount(1, (array) ($summary['group']['items'] ?? []));
        $this->assertSame($word_id, (int) ($summary['group']['items'][0]['word_id'] ?? 0));
    }

    public function test_filtered_target_category_does_not_fall_back_to_a_sibling_word_category(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        $this->ensureRecordingType('Question', 'question');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Filtered Target Recorder Types ' . wp_generate_password(5, false), 'wordset');
        $target_category = wp_insert_term('Question Only Target ' . wp_generate_password(5, false), 'word-category');
        $sibling_category = wp_insert_term('Isolation Sibling ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($target_category);
        $this->assertIsArray($sibling_category);
        $wordset_id = (int) $wordset['term_id'];
        $target_category_id = (int) $target_category['term_id'];
        $sibling_category_id = (int) $sibling_category['term_id'];
        ll_tools_set_category_wordset_owner($target_category_id, $wordset_id, $target_category_id);
        ll_tools_set_category_wordset_owner($sibling_category_id, $wordset_id, $sibling_category_id);
        update_term_meta($target_category_id, 'll_desired_recording_types', ['question']);
        update_term_meta($sibling_category_id, 'll_desired_recording_types', ['isolation']);

        $target_term = get_term($target_category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $target_term);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Filtered target must stay empty',
        ]);
        wp_set_post_terms($word_id, [$target_category_id, $sibling_category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        $focused_complete = true;
        $focused_items = ll_tools_get_recording_queue_items(
            (string) $target_term->slug,
            [$wordset_id],
            'isolation',
            '',
            false,
            $admin_id,
            [$word_id],
            [
                'candidate_word_ids_limited' => true,
                'include_prompt_cards' => false,
            ],
            $focused_complete
        );

        $this->assertTrue($focused_complete);
        $this->assertSame([], $focused_items);

        $summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
            [
                'id' => $target_category_id,
                'name' => (string) $target_term->name,
                'slug' => (string) $target_term->slug,
            ],
            $wordset_id,
            $admin_id,
            'isolation'
        );

        $this->assertTrue((bool) ($summary['complete'] ?? false));
        $this->assertSame(0, (int) ($summary['scan_state']['canonical_total'] ?? -1));
        $this->assertNull($summary['group'] ?? null);
    }

    public function test_wordless_image_uses_the_union_of_its_scoped_categories_in_focused_and_summary_queues(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        $this->ensureRecordingType('Question', 'question');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset = wp_insert_term('Wordless Image Recorder Types ' . wp_generate_password(5, false), 'wordset');
        $target_category = wp_insert_term('Wordless Question Target ' . wp_generate_password(5, false), 'word-category');
        $sibling_category = wp_insert_term('Wordless Isolation Sibling ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($target_category);
        $this->assertIsArray($sibling_category);
        $wordset_id = (int) $wordset['term_id'];
        $target_category_id = (int) $target_category['term_id'];
        $sibling_category_id = (int) $sibling_category['term_id'];
        ll_tools_set_category_wordset_owner($target_category_id, $wordset_id, $target_category_id);
        ll_tools_set_category_wordset_owner($sibling_category_id, $wordset_id, $sibling_category_id);
        update_term_meta($target_category_id, 'll_desired_recording_types', ['question']);
        update_term_meta($sibling_category_id, 'll_desired_recording_types', ['isolation']);

        $target_term = get_term($target_category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $target_term);
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Wordless Multi Category Attachment',
            'post_mime_type' => 'image/png',
        ]);
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Wordless Multi Category Image',
        ]);
        update_post_meta($image_id, '_thumbnail_id', $attachment_id);
        wp_set_post_terms($image_id, [$target_category_id, $sibling_category_id], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);

        $image_src_filter = static function ($image, $candidate_attachment_id) use ($attachment_id) {
            if ((int) $candidate_attachment_id === $attachment_id) {
                return ['https://example.org/wordless-multi-category.png', 640, 480, true];
            }
            return $image;
        };
        add_filter('wp_get_attachment_image_src', $image_src_filter, 10, 2);
        try {
            $focused_complete = true;
            $focused_items = ll_tools_get_recording_queue_items(
                (string) $target_term->slug,
                [$wordset_id],
                'isolation',
                '',
                false,
                $admin_id,
                [],
                [
                    'candidate_word_ids_limited' => true,
                    'candidate_image_ids' => [$image_id],
                    'include_prompt_cards' => false,
                ],
                $focused_complete
            );
            $summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                [
                    'id' => $target_category_id,
                    'name' => (string) $target_term->name,
                    'slug' => (string) $target_term->slug,
                ],
                $wordset_id,
                $admin_id,
                'isolation'
            );
        } finally {
            remove_filter('wp_get_attachment_image_src', $image_src_filter, 10);
        }

        $this->assertTrue($focused_complete);
        $this->assertCount(1, $focused_items);
        $this->assertSame($image_id, (int) ($focused_items[0]['image_id'] ?? $focused_items[0]['id'] ?? 0));
        $this->assertSame(['isolation'], (array) ($focused_items[0]['missing_types'] ?? []));
        $this->assertTrue((bool) ($summary['complete'] ?? false));
        $this->assertSame(1, (int) ($summary['scan_state']['canonical_total'] ?? 0));
        $this->assertIsArray($summary['group'] ?? null);
        $this->assertSame(count($focused_items), (int) ($summary['group']['count'] ?? 0));
        $this->assertCount(1, (array) ($summary['group']['items'] ?? []));
        $this->assertSame($image_id, (int) ($summary['group']['items'][0]['image_id'] ?? $summary['group']['items'][0]['id'] ?? 0));
    }

    public function test_wordless_sibling_visibility_invalidates_and_rebuilds_a_completed_target_summary(): void
    {
        $epoch_option = 'll_tools_wc_cache_epoch';
        $epoch_backup = get_option($epoch_option, null);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $recorder_id = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        $this->ensureRecordingType('Question', 'question');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset = wp_insert_term('Wordless Visibility Wordset ' . wp_generate_password(5, false), 'wordset');
        $target_category = wp_insert_term('Wordless Visibility Target ' . wp_generate_password(5, false), 'word-category');
        $sibling_category = wp_insert_term('Wordless Visibility Sibling ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($target_category);
        $this->assertIsArray($sibling_category);
        $wordset_id = (int) $wordset['term_id'];
        $target_category_id = (int) $target_category['term_id'];
        $sibling_category_id = (int) $sibling_category['term_id'];
        ll_tools_set_category_wordset_owner($target_category_id, $wordset_id, $target_category_id);
        ll_tools_set_category_wordset_owner($sibling_category_id, $wordset_id, $sibling_category_id);
        update_term_meta($target_category_id, 'll_desired_recording_types', ['question']);
        update_term_meta($sibling_category_id, 'll_desired_recording_types', ['isolation']);

        $target_term = get_term($target_category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $target_term);
        $category_source = [
            'id' => $target_category_id,
            'name' => (string) $target_term->name,
            'slug' => (string) $target_term->slug,
        ];
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Wordless Visibility Attachment',
            'post_mime_type' => 'image/png',
        ]);
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Wordless Visibility Image',
        ]);
        update_post_meta($image_id, '_thumbnail_id', $attachment_id);
        wp_set_post_terms($image_id, [$target_category_id, $sibling_category_id], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);

        $image_src_filter = static function ($image, $candidate_attachment_id) use ($attachment_id) {
            if ((int) $candidate_attachment_id === $attachment_id) {
                return ['https://example.org/wordless-visibility.png', 640, 480, true];
            }
            return $image;
        };
        add_filter('wp_get_attachment_image_src', $image_src_filter, 10, 2);
        try {
            update_option($epoch_option, 9000, false);
            wp_set_current_user($recorder_id);

            $before_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
                $recorder_id,
                $wordset_id,
                $category_source
            );
            $before_status = [];
            $before_states = [];
            $before_groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [$category_source],
                $wordset_id,
                $recorder_id,
                'isolation',
                '',
                1,
                $before_status,
                $before_states
            );
            $this->assertCount(1, $before_groups);
            $this->assertSame(1, (int) ($before_groups[0]['count'] ?? 0));
            $this->assertTrue((bool) ($before_states[(string) $target_term->slug]['complete'] ?? false));

            $target_version_before = ll_tools_get_category_cache_version($target_category_id);
            $sibling_version_before = ll_tools_get_category_cache_version($sibling_category_id);
            update_term_meta($sibling_category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
            $after_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
                $recorder_id,
                $wordset_id,
                $category_source
            );
            $this->assertNotSame($before_signature, $after_signature);
            $this->assertSame($target_version_before, ll_tools_get_category_cache_version($target_category_id));
            $this->assertGreaterThan($sibling_version_before, ll_tools_get_category_cache_version($sibling_category_id));

            $focused_complete = true;
            $focused_items = ll_tools_get_recording_queue_items(
                (string) $target_term->slug,
                [$wordset_id],
                'isolation',
                '',
                false,
                $recorder_id,
                [],
                [
                    'candidate_word_ids_limited' => true,
                    'candidate_image_ids' => [$image_id],
                    'include_prompt_cards' => false,
                ],
                $focused_complete
            );
            $after_status = [];
            $after_states = [];
            $after_groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [$category_source],
                $wordset_id,
                $recorder_id,
                'isolation',
                '',
                1,
                $after_status,
                $after_states
            );

            $this->assertTrue($focused_complete);
            $this->assertSame([], $focused_items);
            $this->assertSame([], $after_groups);
            $this->assertTrue((bool) ($after_states[(string) $target_term->slug]['complete'] ?? false));
            $this->assertFalse((bool) ($after_states[(string) $target_term->slug]['has_group'] ?? true));

            $before_delete_epoch = ll_tools_get_category_cache_epoch();
            $before_delete_signature = $after_signature;
            $deleted = wp_delete_term($sibling_category_id, 'word-category');
            $this->assertTrue($deleted === true || is_array($deleted));
            $this->assertNotSame(
                $before_delete_epoch,
                ll_tools_get_category_cache_epoch()
            );
            $this->assertNotSame(
                $before_delete_signature,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
                    $recorder_id,
                    $wordset_id,
                    $category_source
                )
            );
        } finally {
            remove_filter('wp_get_attachment_image_src', $image_src_filter, 10);
            if ($epoch_backup === null) {
                delete_option($epoch_option);
            } else {
                update_option($epoch_option, $epoch_backup, false);
            }
        }
    }

    public function test_word_eligibility_failure_does_not_advance_candidate_or_summary_cursors_past_the_failed_row(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Retryable Word Eligibility ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Retryable Word Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $category_source = [
            'id' => $category_id,
            'name' => (string) $category_term->name,
            'slug' => (string) $category_term->slug,
        ];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Retry this exact recorder word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        // Prime category scope and configuration before fault injection. The
        // query filter then evicts only the configuration cache when the raw
        // candidate query is observed, so the injected failure occurs inside
        // eligibility evaluation after this word has been discovered.
        $category_complete = true;
        $this->assertInstanceOf(
            WP_Term::class,
            ll_tools_wordset_page_get_recorder_queue_category_term(
                $wordset_id,
                (string) $category_term->slug,
                $admin_id,
                $category_complete
            )
        );
        $this->assertTrue($category_complete);
        $configuration_complete = true;
        $this->assertSame(
            ['isolation'],
            ll_tools_wordset_page_get_recorder_queue_category_recording_types(
                $category_id,
                ['isolation'],
                $configuration_complete
            )
        );
        $this->assertTrue($configuration_complete);

        $raw_candidate_seen = false;
        $injected_failures = 0;
        $break_word_eligibility = static function (string $sql) use (
            $wpdb,
            $category_id,
            &$raw_candidate_seen,
            &$injected_failures
        ): string {
            if (
                !$raw_candidate_seen
                && stripos($sql, $wpdb->posts) !== false
                && stripos($sql, $wpdb->term_relationships) !== false
                && preg_match("/post_type\\s*=\\s*'words'/i", $sql)
            ) {
                $raw_candidate_seen = true;
                wp_cache_delete($category_id, 'term_meta');
                return $sql;
            }
            if (
                $raw_candidate_seen
                && stripos($sql, $wpdb->termmeta) !== false
                && preg_match('/term_id\\s+IN\\s*\\(\\s*' . $category_id . '\\s*\\)/i', $sql)
            ) {
                $injected_failures++;
                return "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}_ll_tools_missing_word_eligibility";
            }

            return $sql;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        try {
            add_filter('query', $break_word_eligibility);
            $failed_page = ll_tools_wordset_page_get_recorder_queue_category_candidate_word_page(
                $wordset_id,
                (string) $category_term->slug,
                1,
                1,
                'isolation',
                '',
                $admin_id
            );
            remove_filter('query', $break_word_eligibility);
            $wpdb->last_error = '';
            wp_cache_delete($category_id, 'term_meta');
            clean_object_term_cache($word_id, 'words');

            $retried_page = ll_tools_wordset_page_get_recorder_queue_category_candidate_word_page(
                $wordset_id,
                (string) $category_term->slug,
                1,
                1,
                'isolation',
                '',
                $admin_id,
                (array) ($failed_page['cursor'] ?? [])
            );

            $raw_candidate_seen = false;
            add_filter('query', $break_word_eligibility);
            $failed_summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                $category_source,
                $wordset_id,
                $admin_id,
                'isolation'
            );
            remove_filter('query', $break_word_eligibility);
            $wpdb->last_error = '';
            wp_cache_delete($category_id, 'term_meta');
            clean_object_term_cache($word_id, 'words');

            $retried_summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                $category_source,
                $wordset_id,
                $admin_id,
                'isolation',
                '',
                (array) ($failed_summary['scan_state'] ?? [])
            );
        } finally {
            remove_filter('query', $break_word_eligibility);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($raw_candidate_seen);
        $this->assertGreaterThanOrEqual(2, $injected_failures, 'Focused and summary scans must both exercise the failed eligibility read.');
        $this->assertFalse((bool) ($failed_page['complete'] ?? true));
        $this->assertTrue((bool) ($failed_page['truncated'] ?? false));
        $this->assertSame([], (array) ($failed_page['ids'] ?? []));
        $this->assertSame('words', (string) ($failed_page['cursor']['phase'] ?? ''));
        $this->assertSame(0, (int) ($failed_page['cursor']['word_offset'] ?? -1));
        $this->assertSame(0, (int) ($failed_page['cursor']['eligible_seen'] ?? -1));
        $this->assertTrue((bool) ($retried_page['complete'] ?? false));
        $this->assertSame([$word_id], (array) ($retried_page['word_ids'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($retried_page['cursor']['word_offset'] ?? 0));

        $this->assertFalse((bool) ($failed_summary['complete'] ?? true));
        $this->assertNull($failed_summary['group'] ?? null);
        $this->assertSame('words', (string) ($failed_summary['scan_state']['phase'] ?? ''));
        $this->assertSame(0, (int) ($failed_summary['scan_state']['word_offset'] ?? -1));
        $this->assertSame(0, (int) ($failed_summary['scan_state']['valid_seen'] ?? -1));
        $this->assertTrue((bool) ($retried_summary['complete'] ?? false));
        $this->assertIsArray($retried_summary['group'] ?? null);
        $this->assertSame(1, (int) ($retried_summary['group']['count'] ?? 0));
        $this->assertSame($word_id, (int) ($retried_summary['group']['items'][0]['word_id'] ?? 0));
    }

    public function test_image_type_map_failure_does_not_advance_candidate_or_summary_cursors_past_the_failed_row(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset = wp_insert_term('Retryable Image Type Map ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Retryable Image Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $category_source = [
            'id' => $category_id,
            'name' => (string) $category_term->name,
            'slug' => (string) $category_term->slug,
        ];

        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Retryable Image Type Attachment',
            'post_mime_type' => 'image/png',
        ]);
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Retry this exact recorder image',
        ]);
        update_post_meta($image_id, '_thumbnail_id', $attachment_id);
        wp_set_post_terms($image_id, [$category_id], 'word-category', false);
        ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);

        $category_complete = true;
        $this->assertInstanceOf(
            WP_Term::class,
            ll_tools_wordset_page_get_recorder_queue_category_term(
                $wordset_id,
                (string) $category_term->slug,
                $admin_id,
                $category_complete
            )
        );
        $this->assertTrue($category_complete);

        $raw_candidate_seen = false;
        $injected_failures = 0;
        $break_image_type_map = static function (string $sql) use (
            $wpdb,
            $image_id,
            &$raw_candidate_seen,
            &$injected_failures
        ): string {
            if (
                !$raw_candidate_seen
                && stripos($sql, $wpdb->posts) !== false
                && stripos($sql, $wpdb->postmeta) !== false
                && stripos($sql, '_thumbnail_id') !== false
                && preg_match("/post_type\\s*=\\s*'word_images'/i", $sql)
            ) {
                $raw_candidate_seen = true;
                clean_object_term_cache($image_id, 'word_images');
                return $sql;
            }
            if (
                $raw_candidate_seen
                && stripos($sql, $wpdb->term_relationships) !== false
                && stripos($sql, 'tr.object_id') !== false
                && preg_match('/tr\\.object_id\\s+IN\\s*\\(\\s*' . $image_id . '\\s*\\)/i', $sql)
            ) {
                $injected_failures++;
                return "SELECT t.*, tt.*, tr.object_id FROM {$wpdb->terms}_ll_tools_missing_image_type_map";
            }

            return $sql;
        };
        $image_src_filter = static function ($image, $candidate_attachment_id) use ($attachment_id) {
            if ((int) $candidate_attachment_id === $attachment_id) {
                return ['https://example.org/retryable-image-type-map.png', 640, 480, true];
            }
            return $image;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('wp_get_attachment_image_src', $image_src_filter, 10, 2);
        try {
            add_filter('query', $break_image_type_map);
            $failed_page = ll_tools_wordset_page_get_recorder_queue_category_candidate_word_page(
                $wordset_id,
                (string) $category_term->slug,
                1,
                1,
                'isolation',
                '',
                $admin_id
            );
            remove_filter('query', $break_image_type_map);
            $wpdb->last_error = '';
            clean_object_term_cache($image_id, 'word_images');

            $retried_page = ll_tools_wordset_page_get_recorder_queue_category_candidate_word_page(
                $wordset_id,
                (string) $category_term->slug,
                1,
                1,
                'isolation',
                '',
                $admin_id,
                (array) ($failed_page['cursor'] ?? [])
            );

            $raw_candidate_seen = false;
            add_filter('query', $break_image_type_map);
            $failed_summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                $category_source,
                $wordset_id,
                $admin_id,
                'isolation'
            );
            remove_filter('query', $break_image_type_map);
            $wpdb->last_error = '';
            clean_object_term_cache($image_id, 'word_images');

            $retried_summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                $category_source,
                $wordset_id,
                $admin_id,
                'isolation',
                '',
                (array) ($failed_summary['scan_state'] ?? [])
            );
        } finally {
            remove_filter('query', $break_image_type_map);
            remove_filter('wp_get_attachment_image_src', $image_src_filter, 10);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($raw_candidate_seen);
        $this->assertGreaterThanOrEqual(2, $injected_failures, 'Focused and summary scans must both exercise the failed image type-map read.');
        $this->assertFalse((bool) ($failed_page['complete'] ?? true));
        $this->assertTrue((bool) ($failed_page['truncated'] ?? false));
        $this->assertSame([], (array) ($failed_page['image_ids'] ?? []));
        $this->assertSame('images', (string) ($failed_page['cursor']['phase'] ?? ''));
        $this->assertSame(0, (int) ($failed_page['cursor']['image_offset'] ?? -1));
        $this->assertSame(0, (int) ($failed_page['cursor']['eligible_seen'] ?? -1));
        $this->assertTrue((bool) ($retried_page['complete'] ?? false));
        $this->assertSame([$image_id], (array) ($retried_page['image_ids'] ?? []));
        $this->assertGreaterThanOrEqual(1, (int) ($retried_page['cursor']['image_offset'] ?? 0));

        $this->assertFalse((bool) ($failed_summary['complete'] ?? true));
        $this->assertNull($failed_summary['group'] ?? null);
        $this->assertSame('images', (string) ($failed_summary['scan_state']['phase'] ?? ''));
        $this->assertSame(0, (int) ($failed_summary['scan_state']['image_offset'] ?? -1));
        $this->assertSame(0, (int) ($failed_summary['scan_state']['valid_seen'] ?? -1));
        $this->assertTrue((bool) ($retried_summary['complete'] ?? false));
        $this->assertIsArray($retried_summary['group'] ?? null);
        $this->assertSame(1, (int) ($retried_summary['group']['count'] ?? 0));
        $this->assertSame($image_id, (int) ($retried_summary['group']['items'][0]['image_id'] ?? $retried_summary['group']['items'][0]['id'] ?? 0));
    }

    public function test_visibility_completeness_failure_keeps_the_exact_summary_pending_and_fail_closed(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->ensureRecordingType('Isolation', 'isolation');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Incomplete Visibility Summary ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Incomplete Visibility Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $category_source = [
            'id' => $category_id,
            'name' => (string) $category_term->name,
            'slug' => (string) $category_term->slug,
        ];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Visibility failure must not publish a count',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        // Keep the metadata read cache-resident so this filter can emulate a
        // failed visibility read without a later successful DB query clearing
        // wpdb::last_error. The category catalog must propagate that failure
        // through filter_category_terms_for_user and remain fail closed.
        update_meta_cache('term', [$category_id]);
        get_term_meta($category_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, true);
        $visibility_failures = 0;
        $break_visibility = static function ($value, $object_id, $meta_key) use (
            $wpdb,
            $category_id,
            &$visibility_failures
        ) {
            if (
                (int) $object_id === $category_id
                && (string) $meta_key === LL_TOOLS_CATEGORY_VISIBILITY_META_KEY
            ) {
                $visibility_failures++;
                $wpdb->last_error = 'Injected recorder category visibility completeness failure';
            }
            return $value;
        };

        add_filter('get_term_metadata', $break_visibility, 10, 3);
        try {
            $catalog_complete = true;
            $catalog_terms = ll_tools_recorder_get_category_terms_for_wordsets(
                [$wordset_id],
                $admin_id,
                $catalog_complete
            );

            $status = [];
            $states = [];
            $groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [$category_source],
                $wordset_id,
                $admin_id,
                'isolation',
                '',
                1,
                $status,
                $states
            );
        } finally {
            remove_filter('get_term_metadata', $break_visibility, 10);
            $wpdb->last_error = '';
        }

        $this->assertGreaterThanOrEqual(2, $visibility_failures);
        $this->assertFalse($catalog_complete);
        $this->assertSame([], $catalog_terms, 'An incomplete visibility read must not leak a category into the scope map.');
        $this->assertSame([], $groups);
        $this->assertSame(1, (int) ($status['pending'] ?? 0));
        $this->assertFalse((bool) ($states[(string) $category_term->slug]['complete'] ?? true));
        $this->assertFalse((bool) ($states[(string) $category_term->slug]['has_group'] ?? true));

        $cache_key = ll_tools_wordset_page_build_cache_key('recorder_queue_summary', [
            'schema' => 6,
            'wordset_id' => $wordset_id,
            'recorder_user_id' => $admin_id,
            'category_slug' => (string) $category_term->slug,
            'category_name' => (string) $category_term->name,
            'include_types' => 'isolation',
            'exclude_types' => '',
            'locale' => sanitize_key((string) get_locale()),
        ]);
        $cache_request = [];
        $cached_payload = ll_tools_wordset_page_get_cached_payload($cache_key, $cache_request);
        $this->assertIsArray($cached_payload);
        $this->assertFalse((bool) ($cached_payload['scan_complete'] ?? true));
        $this->assertNull($cached_payload['group'] ?? null);
        $this->assertSame(0, (int) ($cached_payload['scan_state']['word_offset'] ?? -1));
        $this->assertSame(0, (int) ($cached_payload['scan_state']['image_offset'] ?? -1));
        $this->assertSame(0, (int) ($cached_payload['scan_state']['valid_seen'] ?? -1));
    }

    public function test_categorized_summary_signatures_ignore_quiz_unknown_scope_but_track_failed_category_writes(): void
    {
        $unknown_option = 'll_tools_quiz_content_unknown_epoch';
        $failsafe_option = 'll_tools_quiz_content_failsafe_epoch';
        $unknown_backup = get_option($unknown_option, null);
        $failsafe_backup = get_option($failsafe_option, null);
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_a = $fixture['categories'][0];
        $category_b = $fixture['categories'][1];

        try {
            update_option($unknown_option, 41001, false);
            update_option($failsafe_option, 51001, false);
            $baseline_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $baseline_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);

            update_option($unknown_option, 41002, false);
            $this->assertSame(
                $baseline_a,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a),
                'A conservative wordset-scope fallback must not evict an exactly versioned recorder category.'
            );
            $this->assertSame(
                $baseline_b,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
            );

            update_option($failsafe_option, 51002, false);
            $this->assertNotSame(
                $baseline_a,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a),
                'A failed category-version write must still invalidate categorized recorder summaries.'
            );
            $this->assertNotSame(
                $baseline_b,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
            );
        } finally {
            if ($unknown_backup === null) {
                delete_option($unknown_option);
            } else {
                update_option($unknown_option, $unknown_backup, false);
            }
            if ($failsafe_backup === null) {
                delete_option($failsafe_option);
            } else {
                update_option($failsafe_option, $failsafe_backup, false);
            }
        }
    }

    public function test_summary_generation_tracks_compact_catalog_and_scope_not_card_content(): void
    {
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $categories = $fixture['categories'];
        $recorder_user_id = 417;
        $content_epoch_name = 'll_tools_wordset_recorder_queue_content_epoch';
        $recording_type_epoch_name = 'll_tools_wordset_recorder_queue_recording_type_epoch';
        $content_epoch_backup = get_option($content_epoch_name, null);
        $recording_type_epoch_backup = get_option($recording_type_epoch_name, null);

        try {
            $baseline = ll_tools_wordset_page_get_recorder_queue_summary_generation(
                $wordset_id,
                $recorder_user_id,
                $categories,
                'question,isolation',
                ''
            );

            update_term_meta((int) $categories[0]['id'], '_ll_wc_cache_version', 93);
            update_option($content_epoch_name, 'content-changed', false);
            update_option($recording_type_epoch_name, 'recording-types-changed', false);
            $this->assertSame(
                $baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id,
                    $categories,
                    'isolation,question,isolation',
                    ''
                ),
                'Card-content invalidation must not restart the compact catalog stream.'
            );

            $this->assertNotSame(
                $baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id,
                    array_reverse($categories),
                    'question,isolation',
                    ''
                )
            );
            $renamed_categories = $categories;
            $renamed_categories[0]['name'] .= ' renamed';
            $this->assertNotSame(
                $baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id,
                    $renamed_categories,
                    'question,isolation',
                    ''
                )
            );
            $this->assertNotSame(
                $baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id + 1,
                    $recorder_user_id,
                    $categories,
                    'question,isolation',
                    ''
                )
            );
            $this->assertNotSame(
                $baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id + 1,
                    $categories,
                    'question,isolation',
                    ''
                )
            );
            $this->assertNotSame(
                $baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id,
                    $categories,
                    'isolation',
                    ''
                )
            );
            $excluded_baseline = ll_tools_wordset_page_get_recorder_queue_summary_generation(
                $wordset_id,
                $recorder_user_id,
                $categories,
                'question,isolation',
                'beta,alpha'
            );
            $this->assertSame(
                $excluded_baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id,
                    $categories,
                    'question,isolation',
                    'alpha,beta,alpha'
                )
            );
            $this->assertNotSame($baseline, $excluded_baseline);
            $this->assertNotSame(
                $excluded_baseline,
                ll_tools_wordset_page_get_recorder_queue_summary_generation(
                    $wordset_id,
                    $recorder_user_id,
                    $categories,
                    'question,isolation',
                    'alpha'
                )
            );
        } finally {
            if ($content_epoch_backup === null) {
                delete_option($content_epoch_name);
            } else {
                update_option($content_epoch_name, $content_epoch_backup, false);
            }
            if ($recording_type_epoch_backup === null) {
                delete_option($recording_type_epoch_name);
            } else {
                update_option($recording_type_epoch_name, $recording_type_epoch_backup, false);
            }
        }
    }

    public function test_structural_mutations_change_the_compact_recorder_category_cache_key(): void
    {
        $option_name = 'll_tools_wordset_recorder_queue_structure_epoch';
        $option_backup = get_option($option_name, null);
        $had_state = array_key_exists('ll_tools_wordset_page_recorder_queue_epoch_states', $GLOBALS);
        $state_backup = $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? null;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $wordset = wp_insert_term('Recorder Structure Epoch Wordset', 'wordset', [
            'slug' => 'recorder-structure-epoch-wordset',
        ]);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        try {
            update_option($option_name, 'structure-word-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_word_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);

            self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'First uncategorized recorder word',
            ]);
            $after_word_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $this->assertNotSame($before_word_key, $after_word_key);

            $image_id = self::factory()->post->create([
                'post_type' => 'word_images',
                'post_status' => 'publish',
                'post_title' => 'Recorder ownership image',
            ]);
            update_option($option_name, 'structure-owner-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_owner_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $owner_meta_key = defined('LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY')
                ? (string) LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY
                : 'll_wordset_owner_id';

            update_post_meta($image_id, $owner_meta_key, $wordset_id);
            $after_owner_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $this->assertNotSame($before_owner_key, $after_owner_key);

            $category = wp_insert_term('Recorder Structure Epoch Category', 'word-category', [
                'slug' => 'recorder-structure-epoch-category',
            ]);
            $this->assertIsArray($category);
            update_option($option_name, 'structure-category-owner-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_category_owner_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $category_term = get_term((int) $category['term_id'], 'word-category');
            $this->assertInstanceOf(WP_Term::class, $category_term);
            $category_source = [
                'id' => (int) $category_term->term_id,
                'name' => (string) $category_term->name,
                'slug' => (string) $category_term->slug,
            ];
            $before_category_owner_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
                0,
                $wordset_id,
                $category_source
            );
            $category_owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')
                ? (string) LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY
                : 'll_wordset_owner_id';

            update_term_meta((int) $category['term_id'], $category_owner_meta_key, $wordset_id);
            $after_category_owner_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $this->assertNotSame($before_category_owner_key, $after_category_owner_key);
            $this->assertNotSame(
                $before_category_owner_signature,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_source)
            );
        } finally {
            if ($option_backup === null) {
                delete_option($option_name);
            } else {
                update_option($option_name, $option_backup, false);
            }
            if ($had_state) {
                $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = $state_backup;
            } else {
                unset($GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states']);
            }
        }
    }

    public function test_recording_type_relationship_changes_only_parent_categories_while_taxonomy_mutations_are_global(): void
    {
        $option_name = 'll_tools_wordset_recorder_queue_recording_type_epoch';
        $option_backup = get_option($option_name, null);
        $had_state = array_key_exists('ll_tools_wordset_page_recorder_queue_epoch_states', $GLOBALS);
        $state_backup = $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? null;
        $recording_type_id = $this->ensureRecordingType('Isolation', 'isolation');
        $fixture = $this->createWordsetWithCategories(2);
        $other_fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $other_wordset_id = (int) $other_fixture['wordset_id'];
        $category_a = $fixture['categories'][0];
        $category_b = $fixture['categories'][1];
        $other_category = $other_fixture['categories'][0];
        $word_ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [(int) $category_a['id']],
            ]],
        ]);
        $this->assertCount(1, $word_ids);
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => (int) $word_ids[0],
        ]);

        try {
            update_option($option_name, 'recording-relation-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_relationship_epoch = ll_tools_wordset_page_get_recorder_queue_recording_type_epoch();
            $before_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $before_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $before_other = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);

            wp_set_post_terms($audio_id, [$recording_type_id], 'recording_type', false);
            $after_relationship_epoch = ll_tools_wordset_page_get_recorder_queue_recording_type_epoch();
            $this->assertSame($before_relationship_epoch, $after_relationship_epoch);
            $after_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $after_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $after_other = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);
            $this->assertNotSame($before_a, $after_a);
            $this->assertSame($before_b, $after_b);
            $this->assertSame($before_other, $after_other);

            update_option($option_name, 'recording-taxonomy-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_taxonomy_epoch = ll_tools_wordset_page_get_recorder_queue_recording_type_epoch();
            $before_taxonomy_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $before_taxonomy_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $before_taxonomy_other = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);
            $suffix = strtolower(wp_generate_password(6, false));
            $created = wp_insert_term('Recorder Epoch Type ' . $suffix, 'recording_type', [
                'slug' => 'recorder-epoch-type-' . $suffix,
            ]);
            $this->assertIsArray($created);
            $after_taxonomy_epoch = ll_tools_wordset_page_get_recorder_queue_recording_type_epoch();
            $this->assertNotSame($before_taxonomy_epoch, $after_taxonomy_epoch);
            $this->assertNotSame($before_taxonomy_a, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a));
            $this->assertNotSame($before_taxonomy_b, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b));
            $this->assertNotSame($before_taxonomy_other, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category));
        } finally {
            if ($option_backup === null) {
                delete_option($option_name);
            } else {
                update_option($option_name, $option_backup, false);
            }
            if ($had_state) {
                $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = $state_backup;
            } else {
                unset($GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states']);
            }
        }
    }

    public function test_audio_mutations_invalidate_summaries_without_evicting_the_compact_category_catalog(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $recording_type_id = $this->ensureRecordingType('Isolation', 'isolation');
        $fixture = $this->createWordsetWithCategories(2);
        $other_fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $other_wordset_id = (int) $other_fixture['wordset_id'];
        $category_a = $fixture['categories'][0];
        $category_b = $fixture['categories'][1];
        $other_category = $other_fixture['categories'][0];
        $word_ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [(int) $category_a['id']],
            ]],
        ]);
        $this->assertCount(1, $word_ids);

        $before_category_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
        $before_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
        $before_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
        $before_other = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category);
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => (int) $word_ids[0],
        ]);
        wp_set_post_terms($audio_id, [$recording_type_id], 'recording_type', false);

        $after_category_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
        $this->assertSame($before_category_key, $after_category_key);
        $this->assertNotSame($before_a, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a));
        $this->assertSame($before_b, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b));
        $this->assertSame($before_other, ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category));

        $before_speaker_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
        $before_speaker_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
        update_post_meta($audio_id, 'speaker_user_id', self::factory()->user->create(['role' => 'subscriber']));
        $this->assertNotSame(
            $before_speaker_a,
            ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a)
        );
        $this->assertSame(
            $before_speaker_b,
            ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
        );
    }

    public function test_queue_display_meta_mutations_invalidate_only_the_affected_category(): void
    {
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_a = $fixture['categories'][0];
        $category_b = $fixture['categories'][1];
        $word_ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [(int) $category_a['id']],
            ]],
        ]);
        $this->assertCount(1, $word_ids);
        $word_id = (int) $word_ids[0];

        $before_translation_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
        $before_translation_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
        update_post_meta($word_id, 'word_translation', 'Updated recorder queue translation');
        $after_translation_a = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
        $this->assertNotSame($before_translation_a, $after_translation_a);
        $this->assertSame(
            $before_translation_b,
            ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
        );

        $before_prompt_a = $after_translation_a;
        $before_prompt_b = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
        update_post_meta($word_id, '_ll_recording_prompt_hints', ['isolation' => 'Say this naturally.']);
        $this->assertNotSame(
            $before_prompt_a,
            ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a)
        );
        $this->assertSame(
            $before_prompt_b,
            ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
        );
    }

    public function test_prompt_audio_meta_and_attachment_deletion_invalidate_summaries_without_evicting_the_catalog(): void
    {
        $option_name = 'll_tools_wordset_recorder_queue_content_epoch';
        $option_backup = get_option($option_name, null);
        $had_state = array_key_exists('ll_tools_wordset_page_recorder_queue_epoch_states', $GLOBALS);
        $state_backup = $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? null;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $category_a = $fixture['categories'][0];
        $category_b = $fixture['categories'][1];
        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Prompt audio invalidation card',
        ]);
        $attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'audio/mpeg',
            'post_title' => 'Prompt audio invalidation attachment',
            'guid' => 'https://example.org/prompt-audio-invalidation.mp3',
        ]);
        wp_set_post_terms($prompt_card_id, [(int) $category_a['id']], 'word-category', false);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);

        try {
            update_option($option_name, 'prompt-meta-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_category_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $before_meta_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $before_meta_sibling_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $this->assertTrue(ll_tools_prompt_card_needs_prompt_audio($prompt_card_id));

            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, $attachment_id);
            $after_meta_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $this->assertNotSame($before_meta_signature, $after_meta_signature);
            $this->assertSame(
                $before_meta_sibling_signature,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
            );
            $this->assertFalse(ll_tools_prompt_card_needs_prompt_audio($prompt_card_id));
            $this->assertSame(
                $before_category_key,
                ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id)
            );

            update_option($option_name, 'prompt-delete-a', false);
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            $before_delete_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $before_delete_sibling_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b);
            $deleted = wp_delete_attachment($attachment_id, true);
            $this->assertInstanceOf(WP_Post::class, $deleted);
            $after_delete_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_a);
            $this->assertNotSame($before_delete_signature, $after_delete_signature);
            $this->assertSame(
                $before_delete_sibling_signature,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category_b)
            );
            $this->assertTrue(ll_tools_prompt_card_needs_prompt_audio($prompt_card_id));
            $this->assertSame(
                $before_category_key,
                ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id)
            );
        } finally {
            if ($option_backup === null) {
                delete_option($option_name);
            } else {
                update_option($option_name, $option_backup, false);
            }
            if ($had_state) {
                $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = $state_backup;
            } else {
                unset($GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states']);
            }
        }
    }

    public function test_matching_completed_summary_remains_fresh_beyond_the_old_five_minute_window(): void
    {
        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $category = $fixture['categories'][0];
        $recorder_id = self::factory()->user->create(['role' => 'subscriber']);
        $source_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
            $recorder_id,
            $wordset_id,
            $category
        );
        $cache_key = ll_tools_wordset_page_build_cache_key('recorder_queue_summary', [
            'schema' => 6,
            'wordset_id' => $wordset_id,
            'recorder_user_id' => $recorder_id,
            'category_slug' => (string) $category['slug'],
            'category_name' => (string) $category['name'],
            'include_types' => '',
            'exclude_types' => '',
            'locale' => sanitize_key((string) get_locale()),
        ]);
        $cached_group = [
            'name' => 'Retained summary sentinel',
            'slug' => (string) $category['slug'],
            'count' => 1,
            'items' => [],
            'preview_items' => [],
            'is_summary' => true,
        ];
        $request_cache = [];
        ll_tools_wordset_page_store_cached_payload($cache_key, [
            'source_signature' => $source_signature,
            'generated_at' => time() - (6 * HOUR_IN_SECONDS),
            'group' => $cached_group,
            'scan_state' => [],
            'scan_complete' => true,
        ], DAY_IN_SECONDS, $request_cache);

        $legacy_freshness_ttl = static function (): int {
            return 5 * MINUTE_IN_SECONDS;
        };
        add_filter('ll_tools_wordset_recorder_queue_summary_freshness_ttl', $legacy_freshness_ttl);
        try {
            $status = [];
            $states = [];
            $groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [$category],
                $wordset_id,
                $recorder_id,
                '',
                '',
                1,
                $status,
                $states
            );
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_summary_freshness_ttl', $legacy_freshness_ttl);
        }

        $this->assertSame([$cached_group], $groups);
        $this->assertSame(1, (int) $status['fresh']);
        $this->assertSame(0, (int) $status['refreshed']);
        $this->assertTrue((bool) $states[(string) $category['slug']]['fresh']);
    }

    public function test_summary_batch_accounts_for_every_requested_source_and_keeps_selected_recorder_urls(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $fixture = $this->createWordsetWithCategories(2);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Selected Batch Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', [
            'wordset' => (string) $wordset_term->slug,
        ]);

        $pending_category = $fixture['categories'][1];
        for ($index = 2; $index <= 81; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => $index === 2
                    ? 'AAA Partial Batch Word'
                    : sprintf('Pending Batch Word %03d', $index),
            ]);
            wp_set_post_terms($word_id, [(int) $pending_category['id']], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }

        $pending_word_ids = get_posts([
            'post_type' => 'words',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'tax_query' => [[
                'taxonomy' => 'word-category',
                'field' => 'term_id',
                'terms' => [(int) $pending_category['id']],
            ]],
        ]);
        $this->assertCount(81, $pending_word_ids);

        $hidden_entries = [];
        $visible_pending_word_ids = [];
        foreach ($pending_word_ids as $word_id) {
            $word_title = (string) get_the_title($word_id);
            if ($word_title === 'AAA Partial Batch Word' || strpos($word_title, 'Bounded Queue Word') === 0) {
                $visible_pending_word_ids[] = (int) $word_id;
                continue;
            }
            $hide_key = 'word:' . (int) $word_id;
            $hidden_entries[$hide_key] = [
                'key' => $hide_key,
                'word_id' => (int) $word_id,
                'title' => $word_title,
                'category_name' => (string) $pending_category['name'],
                'category_slug' => (string) $pending_category['slug'],
                'hidden_at' => gmdate('c'),
            ];
        }
        $this->assertCount(2, $visible_pending_word_ids);
        $this->assertCount(79, $hidden_entries);
        $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, $hidden_entries));

        $chunk_size = static function (): int {
            return 40;
        };
        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);

        try {
            $requested_slugs = array_column($fixture['categories'], 'slug');
            $back_url = home_url('/manager-return/');
            $payload = ll_tools_wordset_page_build_recorder_queue_summary_batch(
                $wordset_id,
                $wordset_term,
                $recorder_id,
                $requested_slugs,
                ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues', $back_url)
            );
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        }

        $this->assertSame(2, (int) $payload['sourceTotal']);
        $this->assertCount(1, $payload['cards']);
        $this->assertSame((string) $fixture['categories'][0]['slug'], (string) $payload['cards'][0]['slug']);
        $this->assertContains((string) $fixture['categories'][0]['slug'], $payload['resolvedSlugs']);
        $this->assertSame([(string) $pending_category['slug']], $payload['pendingSlugs']);
        $this->assertNotContains(
            (string) $pending_category['slug'],
            array_column((array) $payload['cards'], 'slug'),
            'An incomplete category with partial card data must remain a loading placeholder.'
        );

        $accounted_slugs = array_values(array_unique(array_merge(
            array_map('strval', (array) $payload['resolvedSlugs']),
            array_map('strval', (array) $payload['pendingSlugs'])
        )));
        sort($accounted_slugs);
        sort($requested_slugs);
        $this->assertSame($requested_slugs, $accounted_slugs);

        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        try {
            $resumed_payload = ll_tools_wordset_page_build_recorder_queue_summary_batch(
                $wordset_id,
                $wordset_term,
                $recorder_id,
                $requested_slugs,
                ll_tools_get_wordset_settings_tool_url($wordset_term, 'recorder-queues', $back_url)
            );
        } finally {
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        }
        $resumed_card_lookup = [];
        foreach ((array) $resumed_payload['cards'] as $resumed_card) {
            $resumed_card_lookup[(string) ($resumed_card['slug'] ?? '')] = $resumed_card;
        }
        $this->assertSame([], $resumed_payload['pendingSlugs']);
        $this->assertArrayHasKey((string) $pending_category['slug'], $resumed_card_lookup);
        $this->assertSame(2, (int) $resumed_card_lookup[(string) $pending_category['slug']]['count']);
        $this->assertFalse((bool) $resumed_card_lookup[(string) $pending_category['slug']]['countIsLowerBound']);
        $this->assertStringContainsString(
            'data-recorder-queue-count-lower-bound="0"',
            (string) $resumed_card_lookup[(string) $pending_category['slug']]['html']
        );
        $this->assertStringNotContainsString(
            '2+ words',
            (string) $resumed_card_lookup[(string) $pending_category['slug']]['html']
        );

        $card_html = (string) $payload['cards'][0]['html'];
        $this->assertStringContainsString('ll_recorder_queue_focus=' . $recorder_id, $card_html);
        $this->assertStringContainsString(
            'll_recorder_queue_category=' . rawurlencode((string) $fixture['categories'][0]['slug']),
            $card_html
        );
        $this->assertStringContainsString('ll_wordset_back=' . rawurlencode($back_url), $card_html);
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

    public function test_hidden_entry_wordset_filter_batches_relationship_and_image_lookups(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $other_wordset = wp_insert_term('Other Hidden Queue Wordset ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($other_wordset);
        $other_wordset_id = (int) $other_wordset['term_id'];

        $entries = [];
        $word_ids = [];
        for ($index = 1; $index <= 30; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Bulk Hidden Queue Word %02d', $index),
            ]);
            $word_ids[] = $word_id;
            wp_set_post_terms($word_id, [$index <= 20 ? $wordset_id : $other_wordset_id], 'wordset', false);
            $entries[] = [
                'key' => 'word:' . $word_id,
                'word_id' => $word_id,
                'title' => (string) get_the_title($word_id),
            ];
        }

        $owned_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Hidden Owned Image',
        ]);
        ll_tools_set_word_image_wordset_owner($owned_image_id, $wordset_id, $owned_image_id);
        $entries[] = ['key' => 'image:' . $owned_image_id, 'image_id' => $owned_image_id];

        $other_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Hidden Other Image',
        ]);
        ll_tools_set_word_image_wordset_owner($other_image_id, $other_wordset_id, $other_image_id);
        $entries[] = ['key' => 'image:' . $other_image_id, 'image_id' => $other_image_id];

        $ownerless_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Bulk Hidden Ownerless Direct Image',
        ]);
        $ownerless_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Hidden Ownerless Direct Word',
        ]);
        update_post_meta($ownerless_word_id, '_ll_autopicked_image_id', $ownerless_image_id);
        wp_set_post_terms($ownerless_word_id, [$wordset_id], 'wordset', false);
        $entries[] = ['key' => 'image:' . $ownerless_image_id, 'image_id' => $ownerless_image_id];

        $stale_attachment_id = self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Bulk Hidden Stale Image Attachment',
            'post_mime_type' => 'image/png',
        ]);
        $stale_image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'draft',
            'post_title' => 'Bulk Hidden Draft Image',
        ]);
        set_post_thumbnail($stale_image_id, $stale_attachment_id);
        $stale_image_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Bulk Hidden Draft Image Word',
        ]);
        update_post_meta($stale_image_word_id, '_ll_autopicked_image_id', $stale_image_id);
        wp_set_post_terms($stale_image_word_id, [$wordset_id], 'wordset', false);
        $entries[] = ['key' => 'image:' . $stale_image_id, 'image_id' => $stale_image_id];

        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Bulk Hidden Prompt Card',
        ]);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
        $entries[] = ['key' => 'prompt_card:' . $prompt_card_id, 'prompt_card_id' => $prompt_card_id];
        $entries[] = ['key' => 'title:stale-hidden-title', 'title' => 'Stale Hidden Title'];

        clean_object_term_cache($word_ids, 'words');
        foreach ([$owned_image_id, $other_image_id, $ownerless_image_id, $stale_image_id] as $image_id) {
            clean_post_cache($image_id);
        }
        $queries_before = (int) $GLOBALS['wpdb']->num_queries;
        $filtered = ll_tools_wordset_page_filter_hidden_entries_for_wordset($entries, $wordset_id);
        $query_count = (int) $GLOBALS['wpdb']->num_queries - $queries_before;

        $this->assertCount(24, $filtered);
        $this->assertContains('image:' . $owned_image_id, array_column($filtered, 'key'));
        $this->assertContains('image:' . $ownerless_image_id, array_column($filtered, 'key'));
        $this->assertContains('image:' . $stale_image_id, array_column($filtered, 'key'));
        $this->assertContains('prompt_card:' . $prompt_card_id, array_column($filtered, 'key'));
        $this->assertNotContains('image:' . $other_image_id, array_column($filtered, 'key'));
        $this->assertNotContains('title:stale-hidden-title', array_column($filtered, 'key'));
        $this->assertLessThan(25, $query_count, 'Hidden-entry membership must stay batched instead of querying once per entry.');

        $title_matched = ll_tools_wordset_page_filter_hidden_entries_for_wordset($entries, $wordset_id, [
            'title:stale-hidden-title' => ['hide_key' => 'title:stale-hidden-title'],
        ]);
        $this->assertCount(25, $title_matched);
        $this->assertContains('title:stale-hidden-title', array_column($title_matched, 'key'));
    }

    public function test_hidden_queue_view_paginates_scoped_entries(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorder_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'display_name' => 'Paged Hidden Queue Recorder',
        ]);
        update_user_meta($recorder_id, 'll_recording_config', ['wordset' => (string) $wordset_term->slug]);
        $recorder = get_userdata($recorder_id);
        $this->assertInstanceOf(WP_User::class, $recorder);

        $hidden_entries = [];
        for ($index = 1; $index <= 5; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('Paged Hidden Word %02d', $index),
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $hidden_entries['word:' . $word_id] = [
                'key' => 'word:' . $word_id,
                'word_id' => $word_id,
                'title' => (string) get_the_title($word_id),
                'hidden_at' => gmdate('c', time() + $index),
            ];
        }
        $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, $hidden_entries));

        $rows = ll_tools_wordset_page_get_recorder_queue_rows($wordset_id, $wordset_term, [$recorder], [
            'hidden_view' => true,
            'focused_user_id' => $recorder_id,
            'page' => 2,
            'per_page' => 2,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(5, (int) $rows[0]['hidden_count']);
        $this->assertCount(2, $rows[0]['hidden_items']);
        $this->assertSame(2, (int) $rows[0]['pagination']['page']);
        $this->assertSame(3, (int) $rows[0]['pagination']['total_pages']);
        $this->assertTrue((bool) $rows[0]['pagination']['has_prev']);
        $this->assertTrue((bool) $rows[0]['pagination']['has_next']);

        $_GET = ['ll_wordset_tool' => 'recorder-queues', 'll_recorder_queue_view' => 'hidden'];
        $html = ll_tools_wordset_page_render_settings_recorder_queues_tool($wordset_term, $wordset_id, '', $rows);
        $this->assertStringContainsString('Page 2 of 3', $html);
        $this->assertStringContainsString('ll_recorder_queue_page=3', $html);
        $this->assertStringContainsString('ll_recorder_queue_view=hidden', $html);
    }

    public function test_hidden_queue_pager_focuses_one_recorder_before_advancing(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $recorders = [];
        foreach (['First', 'Second'] as $recorder_label) {
            $recorder_id = self::factory()->user->create([
                'role' => 'audio_recorder',
                'display_name' => $recorder_label . ' Hidden Pager Recorder',
            ]);
            update_user_meta($recorder_id, 'll_recording_config', ['wordset' => (string) $wordset_term->slug]);
            $recorder = get_userdata($recorder_id);
            $this->assertInstanceOf(WP_User::class, $recorder);
            $recorders[] = $recorder;

            $hidden_entries = [];
            for ($index = 1; $index <= 3; $index++) {
                $word_id = self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => sprintf('%s Hidden Pager Word %02d', $recorder_label, $index),
                ]);
                wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
                $hidden_entries['word:' . $word_id] = [
                    'key' => 'word:' . $word_id,
                    'word_id' => $word_id,
                    'title' => (string) get_the_title($word_id),
                    'hidden_at' => gmdate('c', time() + $index),
                ];
            }
            $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, $hidden_entries));
        }

        $overview_rows = ll_tools_wordset_page_get_recorder_queue_rows($wordset_id, $wordset_term, $recorders, [
            'hidden_view' => true,
            'page' => 2,
            'per_page' => 2,
        ]);
        $this->assertCount(2, $overview_rows);
        $this->assertSame(1, (int) $overview_rows[0]['pagination']['page']);
        $this->assertSame(1, (int) $overview_rows[1]['pagination']['page']);

        $_GET = ['ll_wordset_tool' => 'recorder-queues', 'll_recorder_queue_view' => 'hidden'];
        $html = ll_tools_wordset_page_render_settings_recorder_queues_tool($wordset_term, $wordset_id, '', $overview_rows);
        $this->assertStringContainsString('ll_recorder_queue_focus=' . (int) $recorders[0]->ID, $html);
        $this->assertStringContainsString('ll_recorder_queue_focus=' . (int) $recorders[1]->ID, $html);
        $this->assertSame(2, substr_count($html, 'll_recorder_queue_page=2'));

        $focused_rows = ll_tools_wordset_page_get_recorder_queue_rows($wordset_id, $wordset_term, $recorders, [
            'hidden_view' => true,
            'focused_user_id' => (int) $recorders[1]->ID,
            'page' => 2,
            'per_page' => 2,
        ]);
        $this->assertCount(1, $focused_rows);
        $this->assertSame((int) $recorders[1]->ID, (int) $focused_rows[0]['user_id']);
        $this->assertCount(1, $focused_rows[0]['hidden_items']);
        $this->assertSame(2, (int) $focused_rows[0]['pagination']['page']);
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

    public function test_overview_category_source_includes_prompt_only_uncategorized_queue(): void
    {
        if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $wordset = wp_insert_term('Prompt-only Recorder Catalog ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Prompt-only uncategorized recorder item',
        ]);
        wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
        update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Record this uncategorized prompt.');

        $categories = ll_tools_wordset_page_get_recorder_queue_summary_categories($wordset_id);

        $this->assertSame(['uncategorized'], array_column($categories, 'slug'));
    }

    public function test_global_missing_audio_source_stays_out_of_isolated_catalog_even_when_its_title_maps_to_the_wordset(): void
    {
        $option_backup = get_option('ll_missing_audio_instances', null);
        $structure_option = 'll_tools_wordset_recorder_queue_structure_epoch';
        $content_option = 'll_tools_wordset_recorder_queue_content_epoch';
        $structure_backup = get_option($structure_option, null);
        $content_backup = get_option($content_option, null);
        $had_state = array_key_exists('ll_tools_wordset_page_recorder_queue_epoch_states', $GLOBALS);
        $state_backup = $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? null;
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $wordset = wp_insert_term('Missing-only Recorder Catalog ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $category = wp_insert_term('Mapped Legacy Recorder Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $category_slug = (string) get_term_field('slug', $category_id, 'word-category');
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        $mapped_title = 'Mapped isolated global missing word ' . wp_generate_password(5, false);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $mapped_title,
        ]);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        $uncategorized = [
            'id' => ll_tools_wordset_page_uncategorized_virtual_category_id(),
            'name' => 'Uncategorized',
            'slug' => 'uncategorized',
        ];

        try {
            delete_option('ll_missing_audio_instances');
            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            update_option($structure_option, 'missing-source-structure-a', false);
            update_option($content_option, 'missing-source-content-a', false);

            $empty_catalog_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $empty_summary_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $uncategorized);
            $this->assertSame(
                [$category_slug],
                array_column(ll_tools_wordset_page_get_recorder_queue_summary_categories($wordset_id), 'slug')
            );

            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            update_option('ll_missing_audio_instances', [$mapped_title => 123], false);
            $added_catalog_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $added_summary_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $uncategorized);
            $this->assertNotSame($empty_catalog_key, $added_catalog_key);
            $this->assertNotSame($empty_summary_signature, $added_summary_signature);
            $this->assertSame(
                [$category_slug],
                array_column(ll_tools_wordset_page_get_recorder_queue_summary_categories($wordset_id), 'slug')
            );

            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            update_option('ll_missing_audio_instances', [$mapped_title => 456], false);
            $updated_catalog_key = ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id);
            $updated_summary_signature = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $uncategorized);
            $this->assertNotSame($added_catalog_key, $updated_catalog_key);
            $this->assertNotSame($added_summary_signature, $updated_summary_signature);

            $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
            delete_option('ll_missing_audio_instances');
            $this->assertNotSame(
                $updated_catalog_key,
                ll_tools_wordset_page_get_recorder_queue_summary_categories_cache_key($wordset_id)
            );
            $this->assertNotSame(
                $updated_summary_signature,
                ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $uncategorized)
            );
            $this->assertSame(
                [$category_slug],
                array_column(ll_tools_wordset_page_get_recorder_queue_summary_categories($wordset_id), 'slug')
            );
        } finally {
            if ($option_backup === null) {
                delete_option('ll_missing_audio_instances');
            } else {
                update_option('ll_missing_audio_instances', $option_backup, false);
            }
            if ($structure_backup === null) {
                delete_option($structure_option);
            } else {
                update_option($structure_option, $structure_backup, false);
            }
            if ($content_backup === null) {
                delete_option($content_option);
            } else {
                update_option($content_option, $content_backup, false);
            }
            if ($had_state) {
                $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = $state_backup;
            } else {
                unset($GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states']);
            }
        }
    }

    public function test_focused_category_queue_paginates_prompt_only_continuation_and_has_next(): void
    {
        if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        ll_tools_register_or_refresh_audio_recorder_role();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $wordset = wp_insert_term('Focused Prompt Queue ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Focused Prompt Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        $category_term = get_term($category_id, 'word-category');
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $prompt_card_ids = [];
        foreach (['Alpha', 'Bravo', 'Charlie'] as $title) {
            $prompt_card_id = self::factory()->post->create([
                'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => 'Focused Prompt ' . $title,
            ]);
            wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Record focused prompt ' . $title . '.');
            $prompt_card_ids[] = (int) $prompt_card_id;
        }

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        update_user_meta($recorder_id, 'll_recording_config', ['wordset' => (string) $wordset_term->slug]);
        $recorder = get_userdata($recorder_id);
        $this->assertInstanceOf(WP_User::class, $recorder);

        $first_rows = ll_tools_wordset_page_get_recorder_queue_rows($wordset_id, $wordset_term, [$recorder], [
            'focused_user_id' => $recorder_id,
            'focused_category_slug' => (string) $category_term->slug,
            'page' => 1,
            'per_page' => 2,
        ]);
        $second_rows = ll_tools_wordset_page_get_recorder_queue_rows($wordset_id, $wordset_term, [$recorder], [
            'focused_user_id' => $recorder_id,
            'focused_category_slug' => (string) $category_term->slug,
            'page' => 2,
            'per_page' => 2,
        ]);

        $this->assertCount(1, $first_rows);
        $this->assertSame(array_slice($prompt_card_ids, 0, 2), array_map(static function (array $item): int {
            return (int) ($item['prompt_card_id'] ?? 0);
        }, (array) $first_rows[0]['visible_items']));
        $this->assertTrue((bool) $first_rows[0]['pagination']['has_next']);
        $this->assertCount(1, $second_rows);
        $this->assertSame(array_slice($prompt_card_ids, 2), array_map(static function (array $item): int {
            return (int) ($item['prompt_card_id'] ?? 0);
        }, (array) $second_rows[0]['visible_items']));
        $this->assertFalse((bool) $second_rows[0]['pagination']['has_next']);
    }

    public function test_focused_category_pager_carries_only_the_signed_continuation_token_forward(): void
    {
        $html = ll_tools_wordset_page_render_recorder_queue_category_pagination(
            [
                'page' => 1,
                'has_prev' => false,
                'has_next' => true,
                'next_page' => 1,
                'cursor_token' => 'signed_payload.signed_mac',
                'is_continuation' => true,
            ],
            [
                'action_url' => 'https://example.test/wordset/?ll_wordset_tool=recorder-queues&ll_recorder_queue_cursor=stale.token',
                'recorder_user_id' => 77,
            ],
            [
                'slug' => 'cursor-category',
            ]
        );

        $this->assertStringContainsString('Load more', $html);
        $this->assertStringContainsString('ll_recorder_queue_cursor=signed_payload.signed_mac', $html);
        $this->assertStringNotContainsString('stale.token', $html);
        $this->assertStringNotContainsString('ll_recorder_queue_page=2', $html);

        $group_html = ll_tools_wordset_page_render_recorder_queue_category_group(
            [
                'name' => 'Cursor category',
                'slug' => 'cursor-category',
                'items' => [],
            ],
            [
                'action_url' => 'https://example.test/wordset/?ll_wordset_tool=recorder-queues',
                'recorder_user_id' => 77,
                'pagination' => [
                    'page' => 1,
                    'has_prev' => false,
                    'has_next' => true,
                    'next_page' => 1,
                    'cursor_token' => 'signed_payload.signed_mac',
                    'is_continuation' => true,
                ],
            ]
        );
        $this->assertStringContainsString('Cursor category', $group_html);
        $this->assertStringContainsString('Loading...', $group_html);
        $this->assertStringContainsString('Load more', $group_html);
    }

    public function test_summary_keeps_a_truncated_prompt_scan_pending_and_resumes_its_cursor(): void
    {
        if (
            !defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')
            || !defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY')
        ) {
            $this->markTestSkipped('Prompt card support is not loaded.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $wordset = wp_insert_term('Resumable Prompt Summary ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Resumable Prompt Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $category_source = [
            'id' => $category_id,
            'name' => (string) $category_term->name,
            'slug' => (string) $category_term->slug,
        ];

        for ($index = 1; $index <= 25; $index++) {
            $prompt_card_id = self::factory()->post->create([
                'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sprintf('AAA Recorded Prompt %02d', $index),
            ]);
            wp_set_post_terms($prompt_card_id, [$wordset_id], 'wordset', false);
            wp_set_post_terms($prompt_card_id, [$category_id], 'word-category', false);
            update_post_meta($prompt_card_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, 'https://example.org/already-recorded.mp3');
        }
        $eligible_prompt_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'ZZZ Resumed Eligible Prompt',
        ]);
        wp_set_post_terms($eligible_prompt_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($eligible_prompt_id, [$category_id], 'word-category', false);
        update_post_meta($eligible_prompt_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, 'Record after the cursor resumes.');

        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        $batch_size = static function (): int {
            return 20;
        };
        $summary_scan_limit = static function (): int {
            return 20;
        };
        add_filter('ll_tools_recorder_prompt_scan_batch_size', $batch_size);
        add_filter('ll_tools_wordset_recorder_queue_summary_auxiliary_scan_limit', $summary_scan_limit);

        try {
            $first_status = [];
            $first_states = [];
            $first_groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [$category_source],
                $wordset_id,
                $recorder_id,
                '',
                '',
                1,
                $first_status,
                $first_states
            );
            $this->assertSame([], $first_groups);
            $this->assertSame(1, (int) $first_status['pending']);
            $this->assertFalse((bool) $first_states[$category_source['slug']]['complete']);

            $second_status = [];
            $second_states = [];
            $second_groups = ll_tools_wordset_page_build_recorder_queue_summary_groups(
                [$category_source],
                $wordset_id,
                $recorder_id,
                '',
                '',
                1,
                $second_status,
                $second_states
            );
            $this->assertSame(0, (int) $second_status['pending']);
            $this->assertTrue((bool) $second_states[$category_source['slug']]['complete']);
            $this->assertCount(1, $second_groups);
            $this->assertSame($eligible_prompt_id, (int) ($second_groups[0]['items'][0]['prompt_card_id'] ?? 0));
        } finally {
            remove_filter('ll_tools_recorder_prompt_scan_batch_size', $batch_size);
            remove_filter('ll_tools_wordset_recorder_queue_summary_auxiliary_scan_limit', $summary_scan_limit);
        }
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

    public function test_summary_skips_broken_image_candidates_and_fills_from_later_renderable_images(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ensureRecordingType('Isolation', 'isolation');

        $wordset = wp_insert_term('Broken Image Summary ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Broken Image Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);
        $category_term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category_term);
        $category_source = [
            'id' => $category_id,
            'name' => (string) $category_term->name,
            'slug' => (string) $category_term->slug,
        ];

        for ($index = 1; $index <= 3; $index++) {
            $broken_image_id = self::factory()->post->create([
                'post_type' => 'word_images',
                'post_status' => 'publish',
                'post_title' => sprintf('AAA Broken Queue Image %02d', $index),
            ]);
            update_post_meta($broken_image_id, '_thumbnail_id', 900000 + $index);
            wp_set_post_terms($broken_image_id, [$category_id], 'word-category', false);
            ll_tools_set_word_image_wordset_owner($broken_image_id, $wordset_id, $broken_image_id);
        }

        $renderable_image_ids = [];
        $renderable_attachment_lookup = [];
        foreach (['Alpha', 'Bravo'] as $label) {
            $attachment_id = self::factory()->post->create([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_title' => 'Renderable Queue Attachment ' . $label,
                'post_mime_type' => 'image/png',
            ]);
            $image_id = self::factory()->post->create([
                'post_type' => 'word_images',
                'post_status' => 'publish',
                'post_title' => 'ZZZ Renderable Queue Image ' . $label,
            ]);
            update_post_meta($image_id, '_thumbnail_id', $attachment_id);
            wp_set_post_terms($image_id, [$category_id], 'word-category', false);
            ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);
            $renderable_image_ids[] = (int) $image_id;
            $renderable_attachment_lookup[(int) $attachment_id] = true;
        }

        $image_src_filter = static function ($image, $attachment_id) use ($renderable_attachment_lookup) {
            $attachment_id = (int) $attachment_id;
            if (isset($renderable_attachment_lookup[$attachment_id])) {
                return ['https://example.org/recorder-queue-image-' . $attachment_id . '.png', 320, 240, true];
            }
            return $image;
        };
        add_filter('wp_get_attachment_image_src', $image_src_filter, 10, 2);
        try {
            $summary = ll_tools_wordset_page_build_recorder_queue_summary_group(
                $category_source,
                $wordset_id,
                get_current_user_id()
            );
        } finally {
            remove_filter('wp_get_attachment_image_src', $image_src_filter, 10);
        }

        $this->assertTrue((bool) $summary['complete']);
        $this->assertIsArray($summary['group']);
        $this->assertSame($renderable_image_ids, array_map(static function (array $item): int {
            return (int) ($item['id'] ?? 0);
        }, (array) $summary['group']['items']));
        $this->assertSame($renderable_image_ids, array_map(static function (array $candidate): int {
            return (int) ($candidate['id'] ?? 0);
        }, (array) $summary['scan_state']['candidates']));
    }

    public function test_direct_category_recording_configuration_meta_rotates_the_global_type_epoch(): void
    {
        $option_name = 'll_tools_wordset_recorder_queue_recording_type_epoch';
        $option_backup = get_option($option_name, null);
        $had_state = array_key_exists('ll_tools_wordset_page_recorder_queue_epoch_states', $GLOBALS);
        $state_backup = $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] ?? null;
        $fixture = $this->createWordsetWithCategories(1);
        $other_fixture = $this->createWordsetWithCategories(1);
        $wordset_id = (int) $fixture['wordset_id'];
        $other_wordset_id = (int) $other_fixture['wordset_id'];
        $category = $fixture['categories'][0];
        $other_category = $other_fixture['categories'][0];

        try {
            foreach ([
                'll_desired_recording_types' => ['question'],
                'use_word_titles_for_audio' => '1',
            ] as $meta_key => $meta_value) {
                update_option($option_name, 'direct-category-meta-' . sanitize_key($meta_key), false);
                $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = [];
                $before_epoch = ll_tools_wordset_page_get_recorder_queue_recording_type_epoch();
                $before_local = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
                    0,
                    $wordset_id,
                    $category
                );
                $before_other = ll_tools_wordset_page_get_recorder_queue_summary_source_signature(
                    0,
                    $other_wordset_id,
                    $other_category
                );

                update_term_meta((int) $category['id'], $meta_key, $meta_value);

                $this->assertNotSame($before_epoch, ll_tools_wordset_page_get_recorder_queue_recording_type_epoch());
                $this->assertNotSame(
                    $before_local,
                    ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $wordset_id, $category)
                );
                $this->assertNotSame(
                    $before_other,
                    ll_tools_wordset_page_get_recorder_queue_summary_source_signature(0, $other_wordset_id, $other_category),
                    'Wordless images can inherit sibling category types, so direct configuration writes are global.'
                );
            }
        } finally {
            if ($option_backup === null) {
                delete_option($option_name);
            } else {
                update_option($option_name, $option_backup, false);
            }
            if ($had_state) {
                $GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states'] = $state_backup;
            } else {
                unset($GLOBALS['ll_tools_wordset_page_recorder_queue_epoch_states']);
            }
        }
    }

    public function test_candidate_hydrators_propagate_bounded_source_completeness_contract(): void
    {
        foreach (['ll_tools_get_recording_queue_items', 'll_get_images_needing_audio'] as $function_name) {
            $reflection = new ReflectionFunction($function_name);
            $parameters = $reflection->getParameters();
            $complete_parameter = end($parameters);

            $this->assertInstanceOf(ReflectionParameter::class, $complete_parameter);
            $this->assertSame('complete', $complete_parameter->getName());
            $this->assertTrue(
                $complete_parameter->isPassedByReference(),
                $function_name . ' must return source completeness through its final argument.'
            );
        }

        $queue_source = $this->getFunctionSource('ll_tools_get_recording_queue_items');
        $this->assertStringContainsString('$image_complete = true;', $queue_source);
        $this->assertStringContainsString(
            'll_get_images_needing_audio($category_slug, $wordset_term_ids, $include_types_csv, $exclude_types_csv, $include_hidden, $user_id, $candidate_word_ids, $options, $image_complete)',
            $queue_source
        );
        $this->assertStringContainsString('$prompt_complete = true;', $queue_source);
        $this->assertStringContainsString('$complete = $image_complete && $prompt_complete;', $queue_source);

        $image_source = $this->getFunctionSource('ll_get_images_needing_audio');
        $this->assertStringContainsString(
            '$candidate_scope_is_limited = $candidate_word_ids_limited || !empty($candidate_word_ids) || !empty($candidate_image_ids);',
            $image_source
        );
        $this->assertStringContainsString("'cache_results'  => false", $image_source);
        $this->assertStringContainsString('$referenced_images_complete = true;', $image_source);
        $this->assertStringContainsString('$candidate_map_complete = true;', $image_source);
        $this->assertMatchesRegularExpression(
            '/if \(!\$referenced_images_complete\) \{\s*\$complete = false;\s*return \[\];\s*\}/',
            $image_source
        );
        $this->assertMatchesRegularExpression(
            '/if \(!\$candidate_map_complete\) \{\s*\$complete = false;\s*return \[\];\s*\}/',
            $image_source
        );

        $candidate_hydrator = new ReflectionFunction('ll_tools_recorder_get_candidate_queue_items_for_matches');
        $candidate_parameters = $candidate_hydrator->getParameters();
        $candidate_complete_parameter = end($candidate_parameters);
        $this->assertInstanceOf(ReflectionParameter::class, $candidate_complete_parameter);
        $this->assertSame('complete', $candidate_complete_parameter->getName());
        $this->assertTrue($candidate_complete_parameter->isPassedByReference());

        $candidate_source = $this->getFunctionSource('ll_tools_recorder_get_candidate_queue_items_for_matches');
        $this->assertStringContainsString('$hydration_complete = true;', $candidate_source);
        $this->assertMatchesRegularExpression(
            '/ll_tools_get_recording_queue_items\([\s\S]*?\$hydration_complete\s*\)/',
            $candidate_source
        );
        $this->assertMatchesRegularExpression(
            '/if \(!\$hydration_complete\) \{\s*\$complete = false;\s*return \[\];\s*\}/',
            $candidate_source
        );
    }

    public function test_focused_candidate_hydration_failure_retries_without_advancing_the_cursor(): void
    {
        $source = $this->getFunctionSource('ll_tools_get_recording_category_queue_page');
        $failure_offset = strpos($source, 'if (!$candidate_hydration_complete) {');
        $advance_offset = strpos($source, '$candidate_page_delivered = count($candidate_matches);');
        $this->assertIsInt($failure_offset);
        $this->assertIsInt($advance_offset);
        $this->assertLessThan(
            $advance_offset,
            $failure_offset,
            'Incomplete hydration must return before the delivered cursor advances.'
        );

        $failure_branch = substr($source, $failure_offset, $advance_offset - $failure_offset);
        foreach ([
            '$items = $items_before_candidate_hydration;',
            "\$flags['candidate_hydration_incomplete'] = true;",
            "'page_delivered' => \$page_delivered,",
            "'candidate_page_delivered' => \$candidate_page_delivered,",
            "\$candidate_scan_state['cursor']",
            "\$candidate_scan_state['resume_cursor']",
            'return $finalize(',
        ] as $required_retry_state) {
            $this->assertStringContainsString($required_retry_state, $failure_branch);
        }
        $this->assertStringNotContainsString("'candidate_cursor' => \$candidate_cursor", $failure_branch);
    }

    public function test_recorder_switcher_catalog_requires_complete_term_owner_visibility_and_label_reads(): void
    {
        $source = $this->getFunctionSource('ll_tools_wordset_page_get_recorder_queue_summary_categories');
        foreach ([
            "\$wpdb->last_error = '';",
            "is_wp_error(\$terms) || \$wpdb->last_error !== ''",
            '$owner_complete = true;',
            'll_tools_get_category_wordset_owner_id($term, $owner_complete)',
            '$visibility_complete = true;',
            'll_tools_user_can_view_category($term, $user_id, $visibility_complete)',
            '$display_name_complete = true;',
            '$catalog_complete = $catalog_complete && $display_name_complete;',
            'if (!$catalog_complete) {',
        ] as $required_contract) {
            $this->assertStringContainsString($required_contract, $source);
        }

        $incomplete_offset = strrpos($source, 'if (!$catalog_complete) {');
        $store_offset = strrpos($source, 'll_tools_wordset_page_store_cached_payload(');
        $this->assertIsInt($incomplete_offset);
        $this->assertIsInt($store_offset);
        $this->assertLessThan($store_offset, $incomplete_offset);
    }

    public function test_incomplete_summary_hydration_preserves_candidates_and_cannot_cache_as_final(): void
    {
        $summary_source = $this->getFunctionSource('ll_tools_wordset_page_build_recorder_queue_summary_group');
        $this->assertStringContainsString('$hydration_complete = true;', $summary_source);
        $this->assertMatchesRegularExpression(
            '/ll_tools_get_recording_queue_items\([\s\S]*?\$hydration_complete\s*\)/',
            $summary_source
        );

        $incomplete_offset = strpos($summary_source, "if (empty(\$hydrated['complete']))");
        $rejection_offset = strpos($summary_source, '$rejected_count = max(');
        $this->assertIsInt($incomplete_offset);
        $this->assertIsInt($rejection_offset);
        $this->assertLessThan(
            $rejection_offset,
            $incomplete_offset,
            'Hydration completeness must be checked before candidates can be classified as rejected.'
        );

        $incomplete_branch = substr($summary_source, $incomplete_offset, $rejection_offset - $incomplete_offset);
        $this->assertStringContainsString("\$scan_state['complete'] = false;", $incomplete_branch);
        $this->assertStringContainsString("\$scan_state['truncated'] = true;", $incomplete_branch);
        $this->assertStringContainsString('break;', $incomplete_branch);
        $this->assertStringNotContainsString("\$scan_state['candidates'] =", $incomplete_branch);
        $this->assertStringNotContainsString("\$scan_state['valid_seen'] =", $incomplete_branch);
        $this->assertStringContainsString("&& \$legacy_scan_complete", $summary_source);
        $this->assertStringContainsString("&& \$prompt_scan_complete;", $summary_source);
        $this->assertStringContainsString("'collect_limit' => \$legacy_capacity", $summary_source);
        $this->assertStringContainsString("'collect_limit' => \$prompt_capacity", $summary_source);
        $this->assertStringContainsString("'continue_after_limit' => true", $summary_source);
        $this->assertStringContainsString("'raw_scan_limit' => \$auxiliary_scan_limit", $summary_source);
        $this->assertStringContainsString("'group' => (!\$summary_complete || \$exact_count <= 0) ? null", $summary_source);

        $legacy_source = $this->getFunctionSource('ll_tools_get_legacy_missing_audio_instances_page');
        $this->assertStringContainsString("\$wpdb->last_error = '';", $legacy_source);
        $this->assertStringContainsString("is_wp_error(\$all_types) || \$wpdb->last_error !== ''", $legacy_source);
        $this->assertStringContainsString('return $empty_result(false);', $legacy_source);
        $this->assertStringContainsString('$word_map_complete = true;', $legacy_source);
        $this->assertStringContainsString('if (!$word_map_complete) {', $legacy_source);
        $this->assertStringContainsString('$source_read_complete = false;', $legacy_source);

        $groups_source = $this->getFunctionSource('ll_tools_wordset_page_build_recorder_queue_summary_groups');
        $this->assertStringContainsString("\$scan_complete = !empty(\$summary['complete']);", $groups_source);
        $this->assertStringContainsString("'generated_at' => \$scan_complete ? \$now : 0,", $groups_source);
        $this->assertStringContainsString("'scan_complete' => \$scan_complete,", $groups_source);
        $this->assertStringContainsString("&& !empty(\$payload['scan_complete'])", $groups_source);

        $generation_source = $this->getFunctionSource('ll_tools_wordset_page_get_recorder_queue_summary_generation');
        $this->assertStringContainsString("'schema' => 4", $generation_source);
    }

    public function test_focused_candidate_page_resumes_bounded_word_and_image_scans_without_losing_partial_state(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        $recorder_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($recorder_id);
        $this->ensureRecordingType('Isolation', 'isolation');

        $wordset = wp_insert_term('Resumable Focused Candidates ' . wp_generate_password(5, false), 'wordset');
        $category = wp_insert_term('Resumable Focused Category ' . wp_generate_password(5, false), 'word-category');
        $this->assertIsArray($wordset);
        $this->assertIsArray($category);
        $wordset_id = (int) $wordset['term_id'];
        $category_id = (int) $category['term_id'];
        $category_slug = (string) get_term_field('slug', $category_id, 'word-category');
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
        update_term_meta($category_id, 'll_desired_recording_types', ['isolation']);

        $hidden = [];
        for ($index = 1; $index <= 85; $index++) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => sprintf('AAA Hidden Focused Word %03d', $index),
            ]);
            wp_set_post_terms($word_id, [$category_id], 'word-category', false);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
            $key = 'word:' . $word_id;
            $hidden[$key] = [
                'key' => $key,
                'word_id' => $word_id,
                'title' => (string) get_the_title($word_id),
                'category_name' => (string) get_term_field('name', $category_id, 'word-category'),
                'category_slug' => $category_slug,
                'hidden_at' => gmdate('c'),
            ];
        }
        $this->assertTrue(ll_tools_save_hidden_recording_words($recorder_id, $hidden));

        $renderable_image_ids = [];
        $renderable_attachment_lookup = [];
        foreach (['Alpha', 'Bravo', 'Charlie'] as $label) {
            $attachment_id = self::factory()->post->create([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_title' => 'Focused Cursor Attachment ' . $label,
                'post_mime_type' => 'image/png',
            ]);
            $image_id = self::factory()->post->create([
                'post_type' => 'word_images',
                'post_status' => 'publish',
                'post_title' => 'ZZZ Focused Cursor Image ' . $label,
            ]);
            update_post_meta($image_id, '_thumbnail_id', $attachment_id);
            wp_set_post_terms($image_id, [$category_id], 'word-category', false);
            ll_tools_set_word_image_wordset_owner($image_id, $wordset_id, $image_id);
            $renderable_image_ids[] = (int) $image_id;
            $renderable_attachment_lookup[(int) $attachment_id] = true;
        }

        $chunk_size = static function (): int {
            return 40;
        };
        $query_budget = static function (): int {
            return 1;
        };
        $candidate_queries = 0;
        $query_watcher = static function (WP_Query $query) use (&$candidate_queries): void {
            if (
                in_array($query->get('post_type'), ['words', 'word_images'], true)
                && $query->get('fields') === 'ids'
                && (int) $query->get('posts_per_page') === 40
                && (bool) $query->get('no_found_rows')
            ) {
                $candidate_queries++;
            }
        };
        $image_src_filter = static function ($image, $attachment_id) use ($renderable_attachment_lookup) {
            $attachment_id = (int) $attachment_id;
            if (isset($renderable_attachment_lookup[$attachment_id])) {
                return ['https://example.org/focused-cursor-' . $attachment_id . '.png', 320, 240, true];
            }
            return $image;
        };
        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        add_filter('ll_tools_wordset_recorder_queue_candidate_scan_query_budget', $query_budget);
        add_filter('wp_get_attachment_image_src', $image_src_filter, 10, 2);
        add_action('pre_get_posts', $query_watcher);

        try {
            $cursor = [];
            $pages = [];
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $candidate_queries = 0;
                $candidate_page = ll_tools_wordset_page_get_recorder_queue_category_candidate_word_page(
                    $wordset_id,
                    $category_slug,
                    1,
                    2,
                    '',
                    '',
                    $recorder_id,
                    $cursor
                );
                $this->assertSame(1, $candidate_queries, 'Each resume invocation must consume only its one-query scan budget.');
                $pages[] = $candidate_page;
                $cursor = (array) $candidate_page['cursor'];
            }
        } finally {
            remove_action('pre_get_posts', $query_watcher);
            remove_filter('wp_get_attachment_image_src', $image_src_filter, 10);
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_query_budget', $query_budget);
            remove_filter('ll_tools_wordset_recorder_queue_candidate_scan_chunk_size', $chunk_size);
        }

        $this->assertTrue((bool) $pages[0]['truncated']);
        $this->assertTrue((bool) $pages[1]['truncated']);
        $this->assertTrue((bool) $pages[2]['truncated']);
        $this->assertSame(40, (int) $pages[0]['cursor']['word_offset']);
        $this->assertSame(80, (int) $pages[1]['cursor']['word_offset']);
        $this->assertSame(85, (int) $pages[2]['cursor']['word_offset']);
        $this->assertSame('images', (string) $pages[2]['cursor']['phase']);
        $this->assertFalse((bool) $pages[3]['truncated']);
        $this->assertTrue((bool) $pages[3]['complete']);
        $this->assertTrue((bool) $pages[3]['has_more']);
        $this->assertSame([], $pages[3]['word_ids']);
        $this->assertSame(array_slice($renderable_image_ids, 0, 2), $pages[3]['image_ids']);
        $this->assertSame($renderable_image_ids, array_map(static function (array $match): int {
            return (int) ($match['id'] ?? 0);
        }, (array) $pages[3]['cursor']['page_matches']));
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

    private function getFunctionSource(string $function_name): string
    {
        $reflection = new ReflectionFunction($function_name);
        $file_name = $reflection->getFileName();
        $this->assertIsString($file_name);
        $lines = file($file_name);
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function postManagerRecorderSummaryAjax(array $post): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = $post;
        $_REQUEST = $post;

        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            ll_tools_wordset_page_handle_recorder_queue_summary_batch_ajax();
            $this->fail('Expected the AJAX endpoint to terminate through wp_die().');
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected a JSON AJAX response.');
        return $decoded;
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
