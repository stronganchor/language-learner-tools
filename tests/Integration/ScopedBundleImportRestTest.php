<?php
declare(strict_types=1);

final class ScopedBundleImportRestTest extends LL_Tools_TestCase
{
    private const ROUTE = '/ll-tools/v1/imports/';
    private const GRANT = '_ll_tools_scoped_bundle_import_wordset';

    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_rest_automation_load_import_helpers();
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1');
        add_filter('ll_tools_rest_resource_guard_enabled', '__return_false');
    }

    protected function tearDown(): void
    {
        remove_filter('ll_tools_rest_resource_guard_enabled', '__return_false');
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_import_grant_requires_active_assignment_and_a_private_isolated_wordset(): void
    {
        [$managerId, $wordsetId] = $this->createManager();
        wp_set_current_user($managerId);
        $this->assertImportForbidden($this->dispatch('POST', 'preview'));

        update_user_meta($managerId, self::GRANT, $wordsetId);
        $this->assertSame($wordsetId, ll_tools_scoped_import_wordset_id($managerId));
        $this->assertFalse(ll_tools_current_user_can_export_import());
        $this->assertFalse(current_user_can('manage_options'));

        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'public');
        $this->assertImportForbidden($this->dispatch('POST', 'preview'));

        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0');
        $this->assertImportForbidden($this->dispatch('POST', 'preview'));

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1');
        $this->assertNotWPError(ll_tools_set_wordset_manager_user_ids($wordsetId, []));
        $this->assertImportForbidden($this->dispatch('POST', 'preview'));
    }

    public function test_granted_manager_cannot_read_a_server_zip_or_start_another_users_preview(): void
    {
        [$managerId, $wordsetId] = $this->createGrantedManager();
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $slug = $this->uniqueSlug('foreign-preview');
        $zipPath = $this->createBundle($slug);
        $token = '';

        try {
            wp_set_current_user($adminId);
            $preview = ll_tools_prepare_import_preview_from_zip_info([
                'zip_path' => $zipPath,
                'zip_name' => 'foreign-preview.zip',
                'uploaded_file' => true,
                'cleanup_zip' => true,
            ]);
            $this->assertNotWPError($preview);
            $token = $preview['preview_token'];

            wp_set_current_user($managerId);
            $this->assertSame(403, $this->dispatch('POST', 'preview', ['existing' => 'foreign-preview.zip'])->get_status());
            $start = $this->dispatch('POST', 'start', [
                'preview_token' => $token,
                'wordset_mode' => 'assign_existing',
                'target_wordset_id' => $wordsetId,
            ]);
            $this->assertSame(400, $start->get_status());
            $this->assertSame('ll_tools_import_preview_missing', $start->get_data()['code']);
            $this->assertSame('', ll_tools_import_job_get_active_id());
            $this->assertFileExists($zipPath);
        } finally {
            if ($token !== '') {
                wp_set_current_user($adminId);
                ll_tools_delete_import_preview_data($token);
            }
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    public function test_granted_manager_can_import_a_category_full_bundle_into_the_private_wordset(): void
    {
        [$managerId, $wordsetId] = $this->createGrantedManager();
        [$otherManagerId, $otherWordsetId] = $this->createGrantedManager();
        $slug = $this->uniqueSlug('scoped-bundle');
        $zipPath = $this->createBundle($slug);
        $token = '';
        $jobId = '';

        try {
            wp_set_current_user($managerId);
            $preview = ll_tools_rest_automation_scoped_import_preview($zipPath, 'fixture.zip', $wordsetId);
            $this->assertNotWPError($preview);
            $previewData = ll_tools_rest_automation_prepare_preview_response($preview);
            $token = $previewData['preview_token'];
            $this->assertNotSame('', $token);
            $this->assertSame('assign_existing', $previewData['options']['wordset_mode']);
            $this->assertSame($wordsetId, $previewData['options']['target_wordset_id']);

            $this->assertSame(403, $this->dispatch('POST', 'start', [
                'preview_token' => $token,
                'wordset_mode' => 'create_from_export',
            ])->get_status());
            $this->assertSame(403, $this->dispatch('POST', 'start', [
                'preview_token' => $token,
                'll_import_target_wordset' => (string) $otherWordsetId,
            ])->get_status());
            $this->assertSame('', ll_tools_import_job_get_active_id());

            $start = $this->dispatch('POST', 'start', ['preview_token' => $token]);
            $this->assertSame(200, $start->get_status(), wp_json_encode($start->get_data()));
            $jobId = $start->get_data()['job']['id'];
            $this->assertNotSame('', $jobId);

            wp_set_current_user($otherManagerId);
            foreach (['' => 'GET', '/result' => 'GET', '/process' => 'POST', '/discard' => 'POST'] as $suffix => $method) {
                $this->assertSame(404, $this->dispatch($method, $jobId . $suffix)->get_status());
            }

            wp_set_current_user($managerId);
            delete_user_meta($managerId, self::GRANT);
            $this->assertImportForbidden($this->dispatch('POST', $jobId . '/process'));
            update_user_meta($managerId, self::GRANT, $wordsetId);

            $last = $this->processJob($jobId);
            $this->assertSame(200, $last->get_status(), wp_json_encode($last->get_data()));
            $result = $this->dispatch('GET', $jobId . '/result');
            $this->assertSame(200, $result->get_status());
            $this->assertTrue($result->get_data()['result']['ok']);
            $this->assertFileDoesNotExist($zipPath);

            $word = get_page_by_path($slug . '-word', OBJECT, 'words');
            $this->assertInstanceOf(WP_Post::class, $word);
            $this->assertSame('Scoped bundle word', $word->post_title);
            $this->assertSame([$wordsetId], wp_get_object_terms($word->ID, 'wordset', ['fields' => 'ids']));

            $category = get_term_by('slug', ll_tools_build_isolated_category_slug($slug, $wordsetId), 'word-category');
            $this->assertInstanceOf(WP_Term::class, $category);
            $this->assertSame($wordsetId, (int) get_term_meta($category->term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true));
            $this->assertSame([$category->term_id], wp_get_object_terms($word->ID, 'word-category', ['fields' => 'ids']));
        } finally {
            $this->cleanupImport($managerId, $jobId, $token, $zipPath);
        }
    }

    public function test_bundle_cannot_repurpose_a_foreign_word_or_a_foreign_image_with_an_owned_slug(): void
    {
        [$managerId, $wordsetId] = $this->createGrantedManager();
        [, $foreignWordsetId] = $this->createGrantedManager();

        foreach (['words', 'word_images'] as $postType) {
            $slug = $this->uniqueSlug($postType === 'words' ? 'foreign-word' : 'foreign-image');
            $postSlug = $postType === 'words'
                ? $slug . '-word'
                : ll_tools_import_build_owned_word_image_slug($slug . '-image', $wordsetId);
            $postId = self::factory()->post->create([
                'post_type' => $postType,
                'post_name' => $postSlug,
                'post_title' => 'Preserve this content',
                'post_status' => 'publish',
            ]);
            if ($postType === 'words') {
                $this->assertNotWPError(wp_set_object_terms($postId, [$foreignWordsetId], 'wordset'));
            } else {
                ll_tools_set_word_image_wordset_owner($postId, $foreignWordsetId, $postId);
            }

            $zipPath = $this->createBundle($slug, $postType === 'word_images');
            try {
                wp_set_current_user($managerId);
                $preview = ll_tools_rest_automation_scoped_import_preview($zipPath, 'fixture.zip', $wordsetId);
                $this->assertWPError($preview);
                $this->assertSame('ll_tools_rest_import_target_collision', $preview->get_error_code());
                $this->assertSame('Preserve this content', get_post($postId)->post_title);
                if ($postType === 'words') {
                    $this->assertSame([$foreignWordsetId], wp_get_object_terms($postId, 'wordset', ['fields' => 'ids']));
                } else {
                    $this->assertSame($foreignWordsetId, (int) get_post_meta($postId, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, true));
                }
                $this->assertFalse(get_term_by('slug', ll_tools_build_isolated_category_slug($slug, $wordsetId), 'word-category'));
            } finally {
                unlink($zipPath);
            }
        }
    }

    public function test_admin_can_cancel_a_running_job_after_its_grant_is_revoked(): void
    {
        [$managerId, $wordsetId] = $this->createGrantedManager();
        $adminId = self::factory()->user->create(['role' => 'administrator']);
        $zipPath = $this->createBundle($this->uniqueSlug('cancel-scoped-import'));
        $token = '';
        $jobId = '';

        try {
            wp_set_current_user($managerId);
            $preview = ll_tools_rest_automation_scoped_import_preview($zipPath, 'fixture.zip', $wordsetId);
            $this->assertNotWPError($preview);
            $token = $preview['preview_token'];
            $start = $this->dispatch('POST', 'start', ['preview_token' => $token]);
            $this->assertSame(200, $start->get_status(), wp_json_encode($start->get_data()));
            $jobId = $start->get_data()['job']['id'];
            $job = ll_tools_import_job_get($jobId);
            $this->assertSame('running', $job['status']);
            $this->assertEmpty($job['pending_step'] ?? '');
            $this->assertDirectoryExists($job['job_dir']);

            delete_user_meta($managerId, self::GRANT);
            $this->assertImportForbidden($this->dispatch('POST', $jobId . '/discard'));
            wp_set_current_user($adminId);
            $cancel = $this->dispatch('POST', $jobId . '/discard');
            $this->assertSame(200, $cancel->get_status(), wp_json_encode($cancel->get_data()));
            $this->assertTrue($cancel->get_data()['cleanupResult']['ok']);
            $this->assertNull(ll_tools_import_job_get($jobId));
            $this->assertSame('', ll_tools_import_job_get_active_id());
            $this->assertDirectoryDoesNotExist($job['job_dir']);
            $this->assertFileDoesNotExist($zipPath);
            wp_set_current_user($managerId);
            $this->assertNull(ll_tools_get_import_preview_data($token));
        } finally {
            $this->cleanupImport($managerId, $jobId, $token, $zipPath);
        }
    }

    public function test_bundle_rejects_an_owned_category_used_by_a_foreign_word(): void
    {
        global $wpdb;

        [$managerId, $wordsetId] = $this->createGrantedManager();
        [, $foreignWordsetId] = $this->createGrantedManager();
        $slug = $this->uniqueSlug('shared-category');
        $ownedSlug = ll_tools_build_isolated_category_slug($slug, $wordsetId);
        $category = wp_insert_term('Preserve this category', 'word-category', ['slug' => $ownedSlug]);
        $this->assertNotWPError($category);
        $categoryId = (int) $category['term_id'];
        update_term_meta($categoryId, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordsetId);

        $foreignWordId = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft']);
        $this->assertNotWPError(wp_set_object_terms($foreignWordId, [$foreignWordsetId], 'wordset'));
        // Reproduce a legacy relationship that the normal assignment hook would isolate.
        $this->assertSame(1, $wpdb->insert($wpdb->term_relationships, [
            'object_id' => $foreignWordId,
            'term_taxonomy_id' => (int) $category['term_taxonomy_id'],
        ], ['%d', '%d']));
        $zipPath = $this->createBundle($slug);
        try {
            wp_set_current_user($managerId);
            $preview = ll_tools_rest_automation_scoped_import_preview($zipPath, 'fixture.zip', $wordsetId);
            $this->assertWPError($preview);
            $this->assertSame('ll_tools_rest_import_target_collision', $preview->get_error_code());
            $this->assertSame('Preserve this category', get_term($categoryId, 'word-category')->name);
            $this->assertSame([$foreignWordsetId], wp_get_object_terms($foreignWordId, 'wordset', ['fields' => 'ids']));
        } finally {
            unlink($zipPath);
        }
    }

    private function assertImportForbidden(WP_REST_Response $response): void
    {
        $this->assertSame(403, $response->get_status());
        $this->assertSame('ll_tools_rest_import_forbidden', $response->get_data()['code']);
    }

    private function createManager(): array
    {
        $managerId = self::factory()->user->create(['role' => 'wordset_manager']);
        (new WP_User($managerId))->add_cap('view_ll_tools');
        $slug = $this->uniqueSlug('scoped-import');
        $wordset = wp_insert_term($slug, 'wordset', ['slug' => $slug]);
        $this->assertNotWPError($wordset);
        $wordsetId = (int) $wordset['term_id'];
        update_term_meta($wordsetId, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        $this->assertNotWPError(ll_tools_set_wordset_manager_user_ids($wordsetId, [$managerId]));

        return [$managerId, $wordsetId];
    }

    private function createGrantedManager(): array
    {
        [$managerId, $wordsetId] = $this->createManager();
        update_user_meta($managerId, self::GRANT, $wordsetId);

        return [$managerId, $wordsetId];
    }

    private function uniqueSlug(string $prefix): string
    {
        return $prefix . '-' . strtolower(wp_generate_password(8, false, false));
    }

    private function dispatch(string $method, string $path, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, self::ROUTE . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    private function processJob(string $jobId): WP_REST_Response
    {
        for ($attempt = 0; $attempt < 16; $attempt++) {
            $response = $this->dispatch('POST', $jobId . '/process');
            if ($response->get_status() !== 200 || $response->get_data()['job']['status'] === 'completed') {
                return $response;
            }
        }

        $this->fail('The import job did not complete within 16 steps.');
    }

    private function cleanupImport(int $ownerId, string $jobId, string $token, string $zipPath): void
    {
        wp_set_current_user($ownerId);
        if ($jobId !== '') {
            ll_tools_import_job_delete($jobId, $ownerId);
        }
        if ($token !== '') {
            ll_tools_delete_import_preview_data($token);
        }
        if (is_file($zipPath)) {
            unlink($zipPath);
        }
        delete_transient('ll_tools_import_result');
    }

    private function createBundle(string $slug, bool $includeImage = false): string
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is required for this test.');
        }

        $payload = [
            'version' => 2,
            'bundle_type' => 'category_full',
            'categories' => [[
                'slug' => $slug,
                'name' => 'Scoped import ' . $slug,
                'meta' => ['ll_quiz_prompt_type' => ['text_title'], 'll_quiz_option_type' => ['text_title']],
            ]],
            'wordsets' => [['slug' => 'source', 'name' => 'Source wordset']],
            'words' => [[
                'slug' => $slug . '-word',
                'title' => 'Scoped bundle word',
                'status' => 'draft',
                'categories' => [$slug],
                'wordsets' => ['source'],
            ]],
            'word_images' => $includeImage ? [[
                'slug' => $slug . '-image',
                'title' => 'Scoped bundle image',
                'status' => 'draft',
                'categories' => [$slug],
            ]] : [],
        ];
        $path = trailingslashit(get_temp_dir()) . $slug . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        $this->assertTrue($zip->addFromString('data.json', wp_json_encode($payload)));
        $this->assertTrue($zip->close());

        return $path;
    }
}
