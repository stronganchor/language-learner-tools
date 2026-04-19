<?php
declare(strict_types=1);

final class AdminImportAjaxJobFlowTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $serverBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_ajax_import_job_runs_in_multiple_batches_and_completes(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        update_option(ll_tools_import_history_option_name(), [], false);
        delete_transient('ll_tools_import_result');
        delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
        delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);

        $bundle = $this->createMinimalServerImportBundleZip();
        $zipPath = (string) ($bundle['zip_path'] ?? '');
        $zipFilename = (string) ($bundle['zip_filename'] ?? '');
        $categorySlug = (string) ($bundle['category_slug'] ?? '');
        $this->assertNotSame('', $zipPath);
        $this->assertNotSame('', $zipFilename);
        $this->assertNotSame('', $categorySlug);

        $extractBatchFilter = static function () {
            return 1;
        };
        $categoryBatchFilter = static function () {
            return 1;
        };
        add_filter('ll_tools_import_job_extract_batch_size', $extractBatchFilter);
        add_filter('ll_tools_import_job_category_chunk_size', $categoryBatchFilter);

        try {
            $previewRedirect = $this->captureRedirect(function () use ($zipFilename): void {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_GET = [];
                $_POST = [
                    '_wpnonce' => wp_create_nonce('ll_tools_preview_import_bundle'),
                    'action' => 'll_tools_preview_import_bundle',
                    'll_import_existing' => $zipFilename,
                ];
                $_REQUEST = $_POST;
                ll_tools_handle_preview_import_bundle();
            });

            $previewQuery = $this->parseRedirectQuery($previewRedirect);
            $previewToken = (string) ($previewQuery['ll_import_preview'] ?? '');
            $this->assertNotSame('', $previewToken);

            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                'action' => 'll_tools_import_start_job',
                'll_import_preview_token' => $previewToken,
            ];
            $_REQUEST = $_POST;
            $startResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_start_job();
            });

            $this->assertTrue($startResponse['success']);
            $job = is_array($startResponse['data']['job'] ?? null) ? $startResponse['data']['job'] : [];
            $jobId = (string) ($job['id'] ?? '');
            $this->assertNotSame('', $jobId);
            $this->assertSame('running', (string) ($job['status'] ?? ''));
            $this->assertSame($jobId, ll_tools_import_job_get_active_id());

            $completedJob = [];
            $sawIntermediateRunningState = false;
            for ($attempt = 0; $attempt < 12; $attempt++) {
                $_POST = [
                    'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                    'action' => 'll_tools_import_process_job',
                    'job_id' => $jobId,
                ];
                $_REQUEST = $_POST;

                $processResponse = $this->run_json_endpoint(static function (): void {
                    ll_tools_ajax_import_process_job();
                });

                $this->assertTrue($processResponse['success']);
                $completedJob = is_array($processResponse['data']['job'] ?? null) ? $processResponse['data']['job'] : [];
                $status = (string) ($completedJob['status'] ?? '');
                if ($status === 'completed') {
                    break;
                }

                $this->assertSame('running', $status);
                $sawIntermediateRunningState = true;
            }

            $this->assertTrue($sawIntermediateRunningState, 'Expected at least one intermediate running batch.');
            $this->assertSame('completed', (string) ($completedJob['status'] ?? ''));
            $this->assertSame('', ll_tools_import_job_get_active_id());

            $importResult = get_transient('ll_tools_import_result');
            $this->assertIsArray($importResult);
            $this->assertTrue((bool) ($importResult['ok'] ?? false), implode(' | ', (array) ($importResult['errors'] ?? [])));

            $importedCategory = get_term_by('slug', $categorySlug, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $importedCategory);

            $history = ll_tools_import_read_history();
            $this->assertNotEmpty($history);
            $entry = is_array($history[0] ?? null) ? $history[0] : [];
            $this->assertSame($zipFilename, (string) ($entry['source_zip'] ?? ''));
            $this->assertTrue((bool) ($entry['ok'] ?? false));
        } finally {
            remove_filter('ll_tools_import_job_extract_batch_size', $extractBatchFilter);
            remove_filter('ll_tools_import_job_category_chunk_size', $categoryBatchFilter);
            @unlink($zipPath);
            delete_transient('ll_tools_import_result');
            delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
            delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);
        }
    }

    public function test_ajax_import_job_can_discard_paused_partial_import(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        update_option(ll_tools_import_history_option_name(), [], false);
        delete_transient('ll_tools_import_result');
        delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
        delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);

        $bundle = $this->createMinimalServerImportBundleZip();
        $zipPath = (string) ($bundle['zip_path'] ?? '');
        $zipFilename = (string) ($bundle['zip_filename'] ?? '');
        $categorySlug = (string) ($bundle['category_slug'] ?? '');
        $this->assertNotSame('', $zipPath);
        $this->assertNotSame('', $zipFilename);
        $this->assertNotSame('', $categorySlug);

        $extractBatchFilter = static function () {
            return 999;
        };
        $categoryBatchFilter = static function () {
            return 1;
        };
        add_filter('ll_tools_import_job_extract_batch_size', $extractBatchFilter);
        add_filter('ll_tools_import_job_category_chunk_size', $categoryBatchFilter);

        try {
            $previewRedirect = $this->captureRedirect(function () use ($zipFilename): void {
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_GET = [];
                $_POST = [
                    '_wpnonce' => wp_create_nonce('ll_tools_preview_import_bundle'),
                    'action' => 'll_tools_preview_import_bundle',
                    'll_import_existing' => $zipFilename,
                ];
                $_REQUEST = $_POST;
                ll_tools_handle_preview_import_bundle();
            });

            $previewQuery = $this->parseRedirectQuery($previewRedirect);
            $previewToken = (string) ($previewQuery['ll_import_preview'] ?? '');
            $this->assertNotSame('', $previewToken);

            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                'action' => 'll_tools_import_start_job',
                'll_import_preview_token' => $previewToken,
            ];
            $_REQUEST = $_POST;
            $startResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_start_job();
            });

            $this->assertTrue($startResponse['success']);
            $job = is_array($startResponse['data']['job'] ?? null) ? $startResponse['data']['job'] : [];
            $jobId = (string) ($job['id'] ?? '');
            $this->assertNotSame('', $jobId);

            $categoryCreated = false;
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $_POST = [
                    'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                    'action' => 'll_tools_import_process_job',
                    'job_id' => $jobId,
                ];
                $_REQUEST = $_POST;

                $processResponse = $this->run_json_endpoint(static function (): void {
                    ll_tools_ajax_import_process_job();
                });

                $this->assertTrue($processResponse['success']);
                $createdCategory = get_term_by('slug', $categorySlug, 'word-category');
                if ($createdCategory instanceof WP_Term) {
                    $categoryCreated = true;
                    break;
                }
            }

            $this->assertTrue($categoryCreated, 'Expected the partial import to create its category before discard.');

            $pausedJob = ll_tools_import_job_get($jobId);
            $this->assertIsArray($pausedJob);
            $pausedJob['status'] = 'paused';
            $pausedJob['error_message'] = 'Simulated pause for discard test.';
            ll_tools_import_job_save($jobId, $pausedJob);

            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                'action' => 'll_tools_import_discard_job',
                'job_id' => $jobId,
            ];
            $_REQUEST = $_POST;
            $discardResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_discard_job();
            });

            $this->assertTrue($discardResponse['success']);
            $cleanupResult = is_array($discardResponse['data']['cleanupResult'] ?? null)
                ? $discardResponse['data']['cleanupResult']
                : [];
            $this->assertSame(__('Partial import discarded.', 'll-tools-text-domain'), (string) ($cleanupResult['message'] ?? ''));
            $this->assertNull(ll_tools_import_job_get($jobId));
            $this->assertSame('', ll_tools_import_job_get_active_id());
            $this->assertSame('', ll_tools_import_job_get_last_id($adminId));
            $this->assertFalse((bool) get_term_by('slug', $categorySlug, 'word-category'));

            $importResult = get_transient('ll_tools_import_result');
            $this->assertIsArray($importResult);
            $this->assertSame(__('Partial import discarded.', 'll-tools-text-domain'), (string) ($importResult['message'] ?? ''));
        } finally {
            remove_filter('ll_tools_import_job_extract_batch_size', $extractBatchFilter);
            remove_filter('ll_tools_import_job_category_chunk_size', $categoryBatchFilter);
            @unlink($zipPath);
            delete_transient('ll_tools_import_result');
            delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
            delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);
        }
    }

    /**
     * @return array{zip_path:string,zip_filename:string,category_slug:string}
     */
    private function createMinimalServerImportBundleZip(): array
    {
        $importDir = ll_tools_get_import_dir();
        $this->assertTrue(ll_tools_ensure_import_dir($importDir));

        $suffix = wp_generate_password(8, false, false);
        $categorySlug = 'import-ajax-job-cat-' . strtolower($suffix);
        $payload = [
            'bundle_type' => 'images',
            'categories' => [
                [
                    'slug' => $categorySlug,
                    'name' => 'Import AJAX Job Category ' . $suffix,
                    'description' => 'AJAX job flow test bundle',
                    'parent_slug' => '',
                    'meta' => [
                        'display_color' => ['green'],
                    ],
                ],
            ],
            'word_images' => [],
            'wordsets' => [],
            'words' => [],
            'media_estimate' => [
                'attachment_count' => 0,
                'attachment_bytes' => 0,
            ],
        ];

        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertIsString($json);

        $zipFilename = 'll-tools-admin-import-ajax-job-' . strtolower($suffix) . '.zip';
        $zipPath = trailingslashit($importDir) . $zipFilename;
        @unlink($zipPath);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assertTrue($opened === true, 'Failed to create test import zip.');
        $this->assertTrue($zip->addFromString('data.json', $json));
        $this->assertTrue($zip->close());
        $this->assertFileExists($zipPath);

        return [
            'zip_path' => wp_normalize_path($zipPath),
            'zip_filename' => $zipFilename,
            'category_slug' => $categorySlug,
        ];
    }

    private function captureRedirect(callable $callback): string
    {
        $redirectUrl = '';
        $redirectFilter = static function ($location) use (&$redirectUrl) {
            $redirectUrl = (string) $location;
            throw new RuntimeException('redirect_intercepted');
        };
        add_filter('wp_redirect', $redirectFilter, 10, 1);

        try {
            $callback();
            $this->fail('Expected redirect.');
        } catch (RuntimeException $e) {
            $this->assertSame('redirect_intercepted', $e->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirectFilter, 10);
        }

        $this->assertNotSame('', $redirectUrl);
        return $redirectUrl;
    }

    /**
     * @return array<string,string>
     */
    private function parseRedirectQuery(string $url): array
    {
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        $decoded = [];
        parse_str($query, $decoded);
        return array_map('strval', $decoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function run_json_endpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
