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

    /** @var array<string,mixed> */
    private $filesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->filesBackup = $_FILES;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        $_FILES = $this->filesBackup;
        parent::tearDown();
    }

    public function test_ajax_chunked_preview_upload_creates_preview_from_staged_zip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $bundle = $this->createMinimalServerImportBundleZip();
        $zipPath = (string) ($bundle['zip_path'] ?? '');
        $zipFilename = (string) ($bundle['zip_filename'] ?? '');
        $categorySlug = (string) ($bundle['category_slug'] ?? '');
        $this->assertNotSame('', $zipPath);
        $this->assertNotSame('', $zipFilename);
        $this->assertNotSame('', $categorySlug);

        $zipBytes = file_get_contents($zipPath);
        $this->assertIsString($zipBytes);
        $this->assertNotSame('', $zipBytes);

        $totalSize = strlen($zipBytes);
        $chunkSize = max(1, (int) ceil($totalSize / 2));
        $chunks = str_split($zipBytes, $chunkSize);
        $this->assertGreaterThanOrEqual(2, count($chunks));

        $previewToken = '';
        $stagedZipPath = '';
        $uploadId = '';

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_FILES = [];
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                'action' => 'll_tools_import_preview_upload_start',
                'filename' => $zipFilename,
                'total_size' => (string) $totalSize,
                'total_chunks' => (string) count($chunks),
            ];
            $_REQUEST = $_POST;
            $startResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_preview_upload_start();
            });

            $this->assertTrue($startResponse['success']);
            $uploadId = (string) ($startResponse['data']['uploadId'] ?? '');
            $this->assertNotSame('', $uploadId);

            foreach ($chunks as $index => $chunkBytes) {
                $chunkTmpPath = wp_tempnam('ll-tools-import-upload-chunk-' . $index . '.part');
                $this->assertIsString($chunkTmpPath);
                $this->assertNotSame('', $chunkTmpPath);
                file_put_contents($chunkTmpPath, $chunkBytes);

                $_FILES = [
                    'll_import_chunk' => [
                        'name' => 'chunk-' . $index . '.part',
                        'type' => 'application/octet-stream',
                        'tmp_name' => $chunkTmpPath,
                        'error' => UPLOAD_ERR_OK,
                        'size' => strlen($chunkBytes),
                    ],
                ];
                $_POST = [
                    'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                    'action' => 'll_tools_import_preview_upload_chunk',
                    'upload_id' => $uploadId,
                    'chunk_index' => (string) $index,
                    'total_chunks' => (string) count($chunks),
                ];
                $_REQUEST = $_POST;

                $chunkResponse = $this->run_json_endpoint(static function (): void {
                    ll_tools_ajax_import_preview_upload_chunk();
                });

                $this->assertTrue($chunkResponse['success']);
                $this->assertSame($index, (int) ($chunkResponse['data']['chunkIndex'] ?? -1));
            }

            $_FILES = [];
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                'action' => 'll_tools_import_preview_upload_finish',
                'upload_id' => $uploadId,
            ];
            $_REQUEST = $_POST;
            $finishResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_preview_upload_finish();
            });

            $this->assertTrue($finishResponse['success']);
            $previewToken = (string) ($finishResponse['data']['previewToken'] ?? '');
            $this->assertNotSame('', $previewToken);
            $this->assertStringContainsString('ll_import_preview=' . rawurlencode($previewToken), (string) ($finishResponse['data']['redirectUrl'] ?? ''));

            $previewTransient = get_transient(ll_tools_import_preview_transient_key($previewToken));
            $this->assertIsArray($previewTransient);
            $this->assertSame('uploaded', (string) ($previewTransient['source_type'] ?? ''));
            $this->assertSame($zipFilename, (string) ($previewTransient['zip_name'] ?? ''));
            $this->assertTrue((bool) ($previewTransient['cleanup_zip'] ?? false));
            $this->assertSame(1, (int) (($previewTransient['summary'] ?? [])['categories'] ?? 0));
            $this->assertStringContainsString('Import AJAX Job Category', (string) implode(' ', (array) ($previewTransient['category_names'] ?? [])));

            $stagedZipPath = (string) ($previewTransient['zip_path'] ?? '');
            $this->assertNotSame('', $stagedZipPath);
            $this->assertFileExists($stagedZipPath);
            $this->assertFalse(file_exists(ll_tools_import_chunk_upload_session_dir($uploadId)));
        } finally {
            if ($previewToken !== '') {
                ll_tools_delete_import_preview_data($previewToken);
            }
            if ($stagedZipPath !== '' && file_exists($stagedZipPath)) {
                @unlink($stagedZipPath);
            }
            if ($uploadId !== '' && file_exists(ll_tools_import_chunk_upload_session_dir($uploadId))) {
                ll_tools_import_job_delete_path(ll_tools_import_chunk_upload_session_dir($uploadId));
            }
            @unlink($zipPath);
            $_FILES = [];
        }
    }

    public function test_ajax_chunked_preview_upload_start_rejects_zip_over_staging_limit(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $max_size_filter = static function (): int {
            return 100;
        };
        $startResponse = [];
        add_filter('ll_tools_import_chunk_upload_max_total_bytes', $max_size_filter);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_FILES = [];
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                'action' => 'll_tools_import_preview_upload_start',
                'filename' => 'oversize-import.zip',
                'total_size' => '101',
                'total_chunks' => '1',
            ];
            $_REQUEST = $_POST;

            $startResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_preview_upload_start();
            });
        } finally {
            remove_filter('ll_tools_import_chunk_upload_max_total_bytes', $max_size_filter);
        }

        $this->assertFalse($startResponse['success']);
        $this->assertStringContainsString('upload staging limit', (string) ($startResponse['data']['message'] ?? ''));
    }

    public function test_ajax_chunked_preview_upload_start_rejects_too_many_chunks(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $max_chunks_filter = static function (): int {
            return 2;
        };
        $startResponse = [];
        add_filter('ll_tools_import_chunk_upload_max_chunks', $max_chunks_filter);

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_FILES = [];
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                'action' => 'll_tools_import_preview_upload_start',
                'filename' => 'too-many-chunks.zip',
                'total_size' => '12',
                'total_chunks' => '3',
            ];
            $_REQUEST = $_POST;

            $startResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_preview_upload_start();
            });
        } finally {
            remove_filter('ll_tools_import_chunk_upload_max_chunks', $max_chunks_filter);
        }

        $this->assertFalse($startResponse['success']);
        $this->assertStringContainsString('too many upload chunks', (string) ($startResponse['data']['message'] ?? ''));
    }

    public function test_ajax_chunked_preview_upload_chunk_rejects_chunk_over_staging_limit(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $uploadId = '';
        $chunkTmpPath = '';
        $chunkResponse = [];
        $max_chunk_filter = static function (): int {
            return 4;
        };

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_FILES = [];
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                'action' => 'll_tools_import_preview_upload_start',
                'filename' => 'chunk-limit-import.zip',
                'total_size' => '16',
                'total_chunks' => '2',
            ];
            $_REQUEST = $_POST;

            $startResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_preview_upload_start();
            });

            $this->assertTrue($startResponse['success']);
            $uploadId = (string) ($startResponse['data']['uploadId'] ?? '');
            $this->assertNotSame('', $uploadId);

            $chunkTmpPath = wp_tempnam('ll-tools-import-upload-chunk-too-large.part');
            $this->assertIsString($chunkTmpPath);
            file_put_contents($chunkTmpPath, '12345');

            add_filter('ll_tools_import_chunk_upload_max_chunk_bytes', $max_chunk_filter);
            $_FILES = [
                'll_import_chunk' => [
                    'name' => 'chunk-0.part',
                    'type' => 'application/octet-stream',
                    'tmp_name' => $chunkTmpPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => 5,
                ],
            ];
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_chunk_upload_ajax'),
                'action' => 'll_tools_import_preview_upload_chunk',
                'upload_id' => $uploadId,
                'chunk_index' => '0',
            ];
            $_REQUEST = $_POST;

            $chunkResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_preview_upload_chunk();
            });
        } finally {
            remove_filter('ll_tools_import_chunk_upload_max_chunk_bytes', $max_chunk_filter);
            if ($uploadId !== '') {
                $chunkPath = ll_tools_import_chunk_upload_chunk_path($uploadId, 0);
                $this->assertFalse(file_exists($chunkPath));
                if (file_exists(ll_tools_import_chunk_upload_session_dir($uploadId))) {
                    ll_tools_import_job_delete_path(ll_tools_import_chunk_upload_session_dir($uploadId));
                }
            }
            if ($chunkTmpPath !== '' && file_exists($chunkTmpPath)) {
                @unlink($chunkTmpPath);
            }
            $_FILES = [];
        }

        $this->assertFalse($chunkResponse['success']);
        $this->assertStringContainsString('one upload chunk', (string) ($chunkResponse['data']['message'] ?? ''));
    }

    public function test_ajax_import_start_accepts_preview_token_from_url_style_field(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
        delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);
        delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_PREVIEW_META_KEY);

        $bundle = $this->createMinimalServerImportBundleZip();
        $zipPath = (string) ($bundle['zip_path'] ?? '');
        $zipFilename = (string) ($bundle['zip_filename'] ?? '');
        $this->assertNotSame('', $zipPath);
        $this->assertNotSame('', $zipFilename);

        $jobId = '';
        $previewToken = '';

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
            $this->assertIsArray(ll_tools_get_import_preview_data($previewToken));

            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                'action' => 'll_tools_import_start_job',
                'll_import_preview' => $previewToken,
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

            $savedJob = ll_tools_import_job_get($jobId);
            $this->assertIsArray($savedJob);
            $this->assertSame($previewToken, (string) ($savedJob['preview_token'] ?? ''));
        } finally {
            if ($jobId !== '') {
                $job = ll_tools_import_job_get($jobId);
                if (is_array($job)) {
                    ll_tools_import_job_discard($job);
                }
            }
            if ($previewToken !== '') {
                ll_tools_delete_import_preview_data($previewToken);
            }
            @unlink($zipPath);
        }
    }

    public function test_ajax_import_start_restores_preview_when_transient_is_gone(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive is not available in this test environment.');
        }

        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        delete_option(LL_TOOLS_IMPORT_ACTIVE_JOB_OPTION);
        delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_JOB_META_KEY);
        delete_user_meta($adminId, LL_TOOLS_IMPORT_LAST_PREVIEW_META_KEY);

        $bundle = $this->createMinimalServerImportBundleZip();
        $zipPath = (string) ($bundle['zip_path'] ?? '');
        $zipFilename = (string) ($bundle['zip_filename'] ?? '');
        $this->assertNotSame('', $zipPath);
        $this->assertNotSame('', $zipFilename);

        $jobId = '';
        $previewToken = '';

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
            $this->assertIsArray(ll_tools_get_import_preview_data($previewToken));

            delete_transient(ll_tools_import_preview_transient_key($previewToken));
            $this->assertFalse(get_transient(ll_tools_import_preview_transient_key($previewToken)));

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
            $this->assertIsArray(get_transient(ll_tools_import_preview_transient_key($previewToken)));
        } finally {
            if ($jobId !== '') {
                $job = ll_tools_import_job_get($jobId);
                if (is_array($job)) {
                    ll_tools_import_job_discard($job);
                }
            }
            if ($previewToken !== '') {
                ll_tools_delete_import_preview_data($previewToken);
            }
            @unlink($zipPath);
        }
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

    public function test_ajax_import_process_locked_job_returns_retry_without_pausing(): void
    {
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($adminId);

        $jobId = 'ajax-import-lock-' . strtolower(wp_generate_password(8, false, false));
        $job = [
            'id' => $jobId,
            'status' => 'running',
            'phase' => 'extract',
            'created_at' => time(),
            'updated_at' => time(),
            'extract_index' => 0,
            'extract_total' => 1,
            'payload_counts' => [],
            'error_message' => '',
            'result' => ll_tools_import_job_default_result(),
        ];
        ll_tools_import_job_save($jobId, $job);
        ll_tools_import_job_set_active_id($jobId);

        $lock = ll_tools_import_job_process_lock_acquire($jobId, $job);
        $this->assertNotWPError($lock);
        $this->assertIsArray($lock);
        $lockOwner = (string) ($lock['owner'] ?? '');

        try {
            $_POST = [
                'nonce' => wp_create_nonce('ll_tools_import_job_ajax'),
                'action' => 'll_tools_import_process_job',
                'job_id' => $jobId,
            ];
            $_REQUEST = $_POST;

            $processResponse = $this->run_json_endpoint(static function (): void {
                ll_tools_ajax_import_process_job();
            });

            $this->assertFalse((bool) ($processResponse['success'] ?? true));
            $responseData = is_array($processResponse['data'] ?? null) ? $processResponse['data'] : [];
            $this->assertTrue((bool) ($responseData['locked'] ?? false));
            $this->assertGreaterThan(0, (float) ($responseData['retry_after_seconds'] ?? 0));
            $responseJob = is_array($responseData['job'] ?? null) ? $responseData['job'] : [];
            $this->assertSame('running', (string) ($responseJob['status'] ?? ''));

            $savedJob = ll_tools_import_job_get($jobId);
            $this->assertIsArray($savedJob);
            $this->assertSame('running', (string) ($savedJob['status'] ?? ''));
            $this->assertSame('', (string) ($savedJob['error_message'] ?? ''));
        } finally {
            ll_tools_import_job_process_lock_release($jobId, $lockOwner);
            ll_tools_import_job_delete($jobId, $adminId);
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
