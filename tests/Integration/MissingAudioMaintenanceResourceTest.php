<?php
declare(strict_types=1);

final class MissingAudioMaintenanceResourceTest extends LL_Tools_TestCase
{
    /** @var callable|null */
    private $postTypesFilter = null;

    /** @var callable|null */
    private $batchSizeFilter = null;

    /** @var string|null */
    private $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();

        $administratorId = self::factory()->user->create(['role' => 'administrator']);
        $administratorRole = get_role('administrator');
        if ($administratorRole && !$administratorRole->has_cap('view_ll_tools')) {
            $administratorRole->add_cap('view_ll_tools');
        }
        wp_set_current_user($administratorId);

        $this->postTypesFilter = static function (): array {
            return ['post'];
        };
        $this->batchSizeFilter = static function (): int {
            return 2;
        };
        add_filter('ll_missing_audio_scan_post_types', $this->postTypesFilter);
        add_filter('ll_missing_audio_maintenance_batch_size', $this->batchSizeFilter);

        $this->originalRequestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? (string) $_SERVER['REQUEST_METHOD']
            : null;
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        delete_option(ll_missing_audio_maintenance_job_option_name());
        delete_option('ll_missing_audio_instances');
        delete_option('ll_missing_audio_table_headers');
        delete_option('ll_missing_audio_table_headers_updated_at');
    }

    protected function tearDown(): void
    {
        if ($this->postTypesFilter !== null) {
            remove_filter('ll_missing_audio_scan_post_types', $this->postTypesFilter);
        }
        if ($this->batchSizeFilter !== null) {
            remove_filter('ll_missing_audio_maintenance_batch_size', $this->batchSizeFilter);
        }
        delete_option(ll_missing_audio_maintenance_job_option_name());
        delete_option('ll_missing_audio_instances');
        delete_option('ll_missing_audio_table_headers');
        delete_option('ll_missing_audio_table_headers_updated_at');
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        if ($this->originalRequestMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
        }

        parent::tearDown();
    }

    public function test_scan_regex_preview_and_apply_resume_in_bounded_batches(): void
    {
        $postIds = [];
        for ($index = 1; $index <= 5; $index++) {
            $postIds[] = $this->createPost(sprintf(
                'Target %1$03d [word_audio]Missing Scan %1$03d[/word_audio]',
                $index
            ));
        }

        $batchQueries = [];
        $queryObserver = static function (string $query) use (&$batchQueries): string {
            if (stripos($query, 'SELECT ID FROM') !== false
                && stripos($query, "post_status = 'publish'") !== false
                && stripos($query, 'ORDER BY ID ASC') !== false) {
                $batchQueries[] = $query;
            }
            return $query;
        };
        add_filter('query', $queryObserver);

        try {
            $scanJob = ll_missing_audio_create_maintenance_job('scan');
            $this->assertIsArray($scanJob);
            $scanJob = ll_missing_audio_process_maintenance_job($scanJob);
            $this->assertSame('running', $scanJob['status']);
            $this->assertSame(2, (int) $scanJob['processed']);
            $this->assertSame(2, (int) $scanJob['result']['missing_count']);
            $this->assertSame([], get_option('ll_missing_audio_instances', []), 'The public cache should change only after the complete scan.');

            $continueHtml = $this->renderAdminPage([
                'continue_maintenance_job' => '1',
                'maintenance_job_id' => (string) $scanJob['id'],
            ]);
            $scanJob = ll_missing_audio_get_maintenance_job();
            $this->assertSame('running', $scanJob['status']);
            $this->assertSame(4, (int) $scanJob['processed']);
            $this->assertStringContainsString('Processed 4 posts so far', $continueHtml);

            $scanJob = $this->finishJob($scanJob);
            $this->assertSame('completed', $scanJob['status']);
            $this->assertSame(5, (int) $scanJob['processed']);
            $this->assertSame(5, (int) $scanJob['result']['missing_count']);
            $this->assertCount(5, get_option('ll_missing_audio_instances', []));

            $previewJob = ll_missing_audio_create_maintenance_job('regex_preview', [
                'pattern' => '#Target [0-9]{3}#',
            ]);
            $this->assertIsArray($previewJob);
            $previewJob = ll_missing_audio_process_maintenance_job($previewJob);
            $this->assertSame('running', $previewJob['status']);
            $this->assertSame(2, (int) $previewJob['processed']);
            $this->assertSame(2, (int) $previewJob['result']['match_total']);
            $previewJob = $this->finishJob($previewJob);
            $this->assertSame('completed', $previewJob['status']);
            $this->assertSame(5, (int) $previewJob['result']['match_total']);
            $this->assertSame(5, (int) $previewJob['result']['posts_with_matches']);

            $applySettings = ll_missing_audio_get_preview_apply_settings('regex_apply', $previewJob);
            $this->assertIsArray($applySettings);
            $applyJob = ll_missing_audio_create_maintenance_job('regex_apply', $applySettings);
            $this->assertIsArray($applyJob);
            $applyJob = ll_missing_audio_process_maintenance_job($applyJob);
            $this->assertSame('running', $applyJob['status']);
            $this->assertSame(2, (int) $applyJob['processed']);
            $this->assertSame(2, (int) $applyJob['result']['posts_updated']);
            $applyJob = $this->finishJob($applyJob);
            $this->assertSame('completed', $applyJob['status']);
            $this->assertSame(5, (int) $applyJob['processed']);
            $this->assertSame(5, (int) $applyJob['result']['posts_updated']);
            $this->assertSame(5, (int) $applyJob['result']['shortcodes_inserted']);

            foreach ($postIds as $index => $postId) {
                $this->assertStringContainsString(
                    sprintf('[word_audio]Target %03d[/word_audio]', $index + 1),
                    (string) get_post_field('post_content', $postId)
                );
            }
        } finally {
            remove_filter('query', $queryObserver);
        }

        $this->assertNotEmpty($batchQueries);
        foreach ($batchQueries as $query) {
            $this->assertMatchesRegularExpression('/LIMIT\s+3\s*$/i', trim($query));
        }
    }

    public function test_table_headers_preview_and_apply_are_batched_and_page_render_uses_cache(): void
    {
        $postIds = [];
        for ($index = 1; $index <= 5; $index++) {
            $postIds[] = $this->createPost(sprintf(
                '<table><thead><tr><th>Term</th></tr></thead><tbody><tr><td>Table %03d</td></tr></tbody></table>',
                $index
            ));
        }

        $headerJob = ll_missing_audio_create_maintenance_job('headers');
        $this->assertIsArray($headerJob);
        $headerJob = ll_missing_audio_process_maintenance_job($headerJob);
        $this->assertSame('running', $headerJob['status']);
        $this->assertSame(2, (int) $headerJob['processed']);
        $headerJob = $this->finishJob($headerJob);
        $this->assertSame('completed', $headerJob['status']);
        $this->assertSame(5, (int) $headerJob['processed']);

        $cachedHeaders = ll_missing_audio_get_cached_table_headers();
        $this->assertCount(1, $cachedHeaders);
        $this->assertSame('Term', $cachedHeaders[0]['label']);
        $this->assertSame(5, $cachedHeaders[0]['count']);

        $renderBatchQueries = [];
        $queryObserver = static function (string $query) use (&$renderBatchQueries): string {
            if (stripos($query, 'SELECT ID FROM') !== false && stripos($query, 'ORDER BY ID ASC') !== false) {
                $renderBatchQueries[] = $query;
            }
            return $query;
        };
        add_filter('query', $queryObserver);
        try {
            $html = $this->renderAdminPage();
        } finally {
            remove_filter('query', $queryObserver);
        }
        $this->assertSame([], $renderBatchQueries, 'A normal page render must not start post discovery.');
        $this->assertStringContainsString('Term (5)', $html);
        $this->assertStringContainsString('Refresh Table Headers', $html);

        $previewJob = ll_missing_audio_create_maintenance_job('table_preview', ['headers' => ['Term']]);
        $this->assertIsArray($previewJob);
        $previewJob = ll_missing_audio_process_maintenance_job($previewJob);
        $this->assertSame('running', $previewJob['status']);
        $this->assertSame(2, (int) $previewJob['processed']);
        $this->assertSame(2, (int) $previewJob['result']['cell_count']);
        $previewJob = $this->finishJob($previewJob);
        $this->assertSame('completed', $previewJob['status']);
        $this->assertSame(5, (int) $previewJob['result']['cell_count']);

        $applySettings = ll_missing_audio_get_preview_apply_settings('table_apply', $previewJob);
        $this->assertIsArray($applySettings);
        $applyJob = ll_missing_audio_create_maintenance_job('table_apply', $applySettings);
        $this->assertIsArray($applyJob);
        $applyJob = ll_missing_audio_process_maintenance_job($applyJob);
        $this->assertSame('running', $applyJob['status']);
        $this->assertSame(2, (int) $applyJob['processed']);
        $this->assertSame(2, (int) $applyJob['result']['posts_updated']);
        $applyJob = $this->finishJob($applyJob);
        $this->assertSame('completed', $applyJob['status']);
        $this->assertSame(5, (int) $applyJob['result']['posts_updated']);
        $this->assertSame(5, (int) $applyJob['result']['shortcodes_inserted']);

        foreach ($postIds as $index => $postId) {
            $this->assertStringContainsString(
                sprintf('[word_audio]Table %03d[/word_audio]', $index + 1),
                (string) get_post_field('post_content', $postId)
            );
        }
    }

    public function test_apply_skips_a_post_changed_after_preview(): void
    {
        $postIds = [];
        for ($index = 1; $index <= 3; $index++) {
            $postIds[] = $this->createPost(sprintf('Protected Target %03d', $index));
        }

        $previewJob = ll_missing_audio_create_maintenance_job('regex_preview', [
            'pattern' => '#Protected Target [0-9]{3}#',
        ]);
        $this->assertIsArray($previewJob);
        $previewJob = $this->finishJob($previewJob);
        $this->assertSame('completed', $previewJob['status']);

        wp_update_post([
            'ID' => $postIds[1],
            'post_content' => 'Externally changed Protected Target 002',
        ]);

        $applySettings = ll_missing_audio_get_preview_apply_settings('regex_apply', $previewJob);
        $this->assertIsArray($applySettings);
        $applyJob = ll_missing_audio_create_maintenance_job('regex_apply', $applySettings);
        $this->assertIsArray($applyJob);
        $applyJob = $this->finishJob($applyJob);

        $this->assertSame('completed', $applyJob['status']);
        $this->assertSame(2, (int) $applyJob['result']['posts_updated']);
        $this->assertSame(1, (int) $applyJob['result']['changed_posts_skipped']);
        $this->assertNotEmpty($applyJob['result']['errors']);
        $this->assertStringNotContainsString('[word_audio]', (string) get_post_field('post_content', $postIds[1]));
        $this->assertStringContainsString('[word_audio]Protected Target 001[/word_audio]', (string) get_post_field('post_content', $postIds[0]));
        $this->assertStringContainsString('[word_audio]Protected Target 003[/word_audio]', (string) get_post_field('post_content', $postIds[2]));
    }

    public function test_unsafe_regexes_are_rejected_before_any_post_batch(): void
    {
        $this->assertFalse(ll_missing_audio_is_valid_regex('#' . str_repeat('a', 511) . '#'));
        $this->assertFalse(ll_missing_audio_is_valid_regex('#(?R)#'));
        $this->assertFalse(ll_missing_audio_is_valid_regex('#(?&loop)(?<loop>a)#'));
        $this->assertFalse(ll_missing_audio_is_valid_regex('#[unterminated#'));
        $this->assertTrue(ll_missing_audio_is_valid_regex('#Safe [0-9]+#'));

        $job = ll_missing_audio_create_maintenance_job('regex_preview', ['pattern' => '#(?R)#']);
        $this->assertIsArray($job);
        $job = ll_missing_audio_process_maintenance_job($job);
        $this->assertSame('failed', $job['status']);
        $this->assertSame(0, (int) $job['processed']);
        $this->assertNotEmpty($job['result']['errors']);
    }

    private function createPost(string $content): int
    {
        return (int) self::factory()->post->create([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Missing Audio Resource ' . wp_generate_password(8, false, false),
            'post_content' => $content,
        ]);
    }

    private function finishJob(array $job): array
    {
        for ($iteration = 0; $iteration < 20 && ($job['status'] ?? '') === 'running'; $iteration++) {
            $job = ll_missing_audio_process_maintenance_job($job);
        }
        return $job;
    }

    private function renderAdminPage(array $post = []): string
    {
        $previousPost = $_POST;
        $previousRequest = $_REQUEST;
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        try {
            $_POST = $post;
            if (!empty($post)) {
                $_POST['ll_missing_audio_nonce'] = wp_create_nonce('ll_missing_audio_actions');
            }
            $_REQUEST = $_POST;
            $_SERVER['REQUEST_METHOD'] = empty($post) ? 'GET' : 'POST';
            ob_start();
            ll_render_missing_audio_admin_page();
            return (string) ob_get_clean();
        } finally {
            $_POST = $previousPost;
            $_REQUEST = $previousRequest;
            if ($previousMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }
    }
}
