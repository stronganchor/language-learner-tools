<?php
declare(strict_types=1);

final class ImageWebpOptimizerAdminTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private array $postBackup = [];

    /** @var array<string,mixed> */
    private array $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        ll_tools_webp_optimizer_bump_queue_index_cache_version();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        wp_set_current_user(0);
        ll_tools_webp_optimizer_bump_queue_index_cache_version();
        parent::tearDown();
    }

    public function test_parse_post_id_list_accepts_unique_positive_integer_tokens_only(): void
    {
        $this->assertSame(
            [12, 4, 9],
            ll_tools_webp_optimizer_parse_post_id_list("12, 004\n9 12 0 -5 7abc +8 3.5")
        );

        $this->assertSame(
            [5, 6],
            ll_tools_webp_optimizer_parse_post_id_list([5, '6', '06', 0, -1, '8x', ['9']])
        );

        $this->assertSame([], ll_tools_webp_optimizer_parse_post_id_list(false));
    }

    public function test_webp_optimizer_ajax_requires_view_permission_before_queue_access(): void
    {
        wp_set_current_user(0);

        $_POST = [
            'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
            'page' => '1',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_webp_optimizer_queue_ajax();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('You do not have permission.', (string) ($response['data']['message'] ?? ''));
    }

    public function test_webp_optimizer_convert_ajax_rejects_empty_sanitized_id_selection(): void
    {
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
            'word_image_ids' => '0 nope -4 8x',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_webp_optimizer_convert_ajax();
        });

        $this->assertFalse((bool) ($response['success'] ?? true));
        $this->assertSame('No word images were selected for optimization.', (string) ($response['data']['message'] ?? ''));
    }

    public function test_webp_optimizer_queue_scans_word_images_in_bounded_batches(): void
    {
        $this->createWordImagePosts(5, 'webp-bounded-scan');

        $capturedQueries = [];
        $captureQuery = static function (WP_Query $query) use (&$capturedQueries): void {
            if ($query->get('post_type') === 'word_images' && $query->get('fields') === 'ids') {
                $capturedQueries[] = $query->query_vars;
            }
        };
        $batchFilter = static function (int $size, int $per_page): int {
            return 2;
        };
        $disableCache = static function (int $ttl): int {
            return 0;
        };

        add_action('pre_get_posts', $captureQuery);
        add_filter('ll_tools_webp_optimizer_queue_scan_batch_size', $batchFilter, 10, 2);
        add_filter('ll_tools_webp_optimizer_queue_index_cache_ttl', $disableCache, 10, 1);
        try {
            $queue = ll_tools_webp_optimizer_get_queue([
                'page' => 1,
                'per_page' => 2,
                'include_non_flagged' => true,
            ]);
        } finally {
            remove_action('pre_get_posts', $captureQuery);
            remove_filter('ll_tools_webp_optimizer_queue_scan_batch_size', $batchFilter, 10);
            remove_filter('ll_tools_webp_optimizer_queue_index_cache_ttl', $disableCache, 10);
        }

        $this->assertNotEmpty($capturedQueries);
        foreach ($capturedQueries as $queryVars) {
            $this->assertSame(2, (int) ($queryVars['posts_per_page'] ?? 0));
            $this->assertNotSame(-1, (int) ($queryVars['posts_per_page'] ?? 0));
            $this->assertTrue((bool) ($queryVars['no_found_rows'] ?? false));
        }
        $this->assertSame(5, (int) ($queue['total_items'] ?? 0));
        $this->assertSame(3, (int) ($queue['total_pages'] ?? 0));
        $this->assertCount(2, (array) ($queue['items'] ?? []));
    }

    public function test_webp_optimizer_queue_reuses_compact_index_for_followup_pages(): void
    {
        $this->createWordImagePosts(5, 'webp-index-cache');

        $capturedQueries = [];
        $captureQuery = static function (WP_Query $query) use (&$capturedQueries): void {
            if ($query->get('post_type') === 'word_images' && $query->get('fields') === 'ids') {
                $capturedQueries[] = $query->query_vars;
            }
        };
        $batchFilter = static function (int $size, int $per_page): int {
            return 2;
        };
        $cacheTtl = static function (int $ttl): int {
            return 60;
        };

        add_action('pre_get_posts', $captureQuery);
        add_filter('ll_tools_webp_optimizer_queue_scan_batch_size', $batchFilter, 10, 2);
        add_filter('ll_tools_webp_optimizer_queue_index_cache_ttl', $cacheTtl, 10, 1);
        try {
            $firstPage = ll_tools_webp_optimizer_get_queue([
                'page' => 1,
                'per_page' => 2,
                'include_non_flagged' => true,
            ]);
            $firstPageQueries = $capturedQueries;
            $capturedQueries = [];

            $secondPage = ll_tools_webp_optimizer_get_queue([
                'page' => 2,
                'per_page' => 2,
                'include_non_flagged' => true,
            ]);
        } finally {
            remove_action('pre_get_posts', $captureQuery);
            remove_filter('ll_tools_webp_optimizer_queue_scan_batch_size', $batchFilter, 10);
            remove_filter('ll_tools_webp_optimizer_queue_index_cache_ttl', $cacheTtl, 10);
        }

        $this->assertNotEmpty($firstPageQueries);
        $this->assertSame([], $capturedQueries, 'Follow-up pages should hydrate page rows from the compact queue index without rescanning word_images.');
        $this->assertCount(2, (array) ($firstPage['items'] ?? []));
        $this->assertSame(2, (int) ($secondPage['page'] ?? 0));
        $this->assertCount(2, (array) ($secondPage['items'] ?? []));
    }

    public function test_queue_and_focus_enforce_viewer_scope_status_and_media_permissions(): void
    {
        $admin = get_current_user_id();
        $viewer_id = self::factory()->user->create(['role' => 'subscriber']);
        $viewer = get_user_by('id', $viewer_id);
        $viewer->add_cap('view_ll_tools');
        $allowed_scope = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Optimizer Allowed Private Scope']);
        $denied_scope = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Optimizer Denied Private Scope']);
        foreach ([$allowed_scope, $denied_scope] as $scope) {
            update_term_meta($scope, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        }
        ll_tools_set_wordset_manager_user_ids($allowed_scope, [$viewer_id]);
        $images = $this->createWordImagePosts(7, 'optimizer-privacy');
        [$public_image, $allowed_image, $denied_image, $draft_image, $private_image, $allowed_media_image, $denied_media_image] = $images;
        ll_tools_set_word_image_wordset_owner($allowed_image, $allowed_scope);
        ll_tools_set_word_image_wordset_owner($denied_image, $denied_scope);
        wp_update_post(['ID' => $draft_image, 'post_status' => 'draft', 'post_author' => $admin]);
        wp_update_post(['ID' => $private_image, 'post_status' => 'private', 'post_author' => $admin]);
        $allowed_media = $this->createImageAttachment($admin, 'inherit');
        $denied_media = $this->createImageAttachment($admin, 'private');
        set_post_thumbnail($allowed_media_image, $allowed_media);
        set_post_thumbnail($denied_media_image, $denied_media);

        $admin_queue = ll_tools_webp_optimizer_get_queue(['include_non_flagged' => true, 'ids_only' => true]);
        $this->assertContains($denied_image, $admin_queue['ids']);
        $admin_cache_key = ll_tools_webp_optimizer_queue_index_cache_key(['include_non_flagged' => true], ll_tools_webp_optimizer_queue_scan_batch_size());
        wp_set_current_user($viewer_id);
        $viewer_cache_key = ll_tools_webp_optimizer_queue_index_cache_key(['include_non_flagged' => true], ll_tools_webp_optimizer_queue_scan_batch_size());
        $this->assertNotSame($admin_cache_key, $viewer_cache_key);
        $this->assertTrue(current_user_can('read_post', $denied_image), 'The scope check must add protection beyond WordPress published-post access.');
        $expected = [$public_image, $allowed_image, $allowed_media_image];
        foreach ([false, true] as $ids_only) {
            $queue = ll_tools_webp_optimizer_get_queue(['include_non_flagged' => true, 'ids_only' => $ids_only]);
            $visible = $ids_only ? $queue['ids'] : array_column($queue['items'], 'word_image_id');
            $this->assertEqualsCanonicalizing($expected, $visible);
            $this->assertSame(count($expected), $queue['total_items']);
        }
        $allowed_item = ll_tools_webp_optimizer_build_item($allowed_media_image);
        $this->assertSame($allowed_media, $allowed_item['attachment_id']);
        foreach ([$denied_image, $draft_image, $private_image, $denied_media_image] as $denied) {
            $_POST = [
                'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
                'focus_word_image_id' => $denied,
                'include_non_flagged' => '1',
            ];
            $_REQUEST = $_POST;
            $response = $this->runJsonEndpoint(static function (): void { ll_tools_webp_optimizer_queue_ajax(); });
            $this->assertTrue($response['success']);
            $this->assertSame([], $response['data']['focus_item']);
            $this->assertNotContains($denied, array_column($response['data']['items'], 'word_image_id'));
        }
        $_POST = [
            'nonce' => wp_create_nonce(LL_TOOLS_WEBP_OPTIMIZER_NONCE_ACTION),
            'word_image_ids' => (string) $public_image,
        ];
        $_REQUEST = $_POST;
        $conversion = $this->runJsonEndpoint(static function (): void { ll_tools_webp_optimizer_convert_ajax(); });
        $this->assertFalse(current_user_can('edit_post', $public_image));
        $this->assertStringContainsString('You do not have permission to edit this word image.', (string) wp_json_encode($conversion));
    }

    public function test_cached_queue_follows_role_and_private_scope_changes(): void
    {
        $viewer_id = get_current_user_id();
        $private_scope = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Optimizer Revoked Scope']);
        update_term_meta($private_scope, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        // Creating a wordset assigns its creator as a manager. This fixture must
        // isolate the administrator grant from that independent scope grant.
        $this->assertTrue(ll_tools_set_wordset_manager_user_ids($private_scope, []));
        [$image] = $this->createWordImagePosts(1, 'optimizer-role-change');
        ll_tools_set_word_image_wordset_owner($image, $private_scope);
        $args = ['include_non_flagged' => true, 'ids_only' => true];
        $this->assertContains($image, ll_tools_webp_optimizer_get_queue($args)['ids']);
        $user = get_user_by('id', $viewer_id);
        $user->set_role('subscriber');
        $user->add_cap('view_ll_tools');
        wp_set_current_user(0);
        wp_set_current_user($viewer_id);
        $this->assertFalse(current_user_can('manage_options'));
        $this->assertFalse(ll_tools_user_can_view_wordset($private_scope, $viewer_id));
        $this->assertSame([], ll_tools_webp_optimizer_get_queue($args)['ids']);

        update_user_meta($viewer_id, 'managed_wordsets', [$private_scope]);
        $this->assertContains($image, ll_tools_webp_optimizer_get_queue($args)['ids']);
        delete_user_meta($viewer_id, 'managed_wordsets');
        $this->assertSame([], ll_tools_webp_optimizer_get_queue($args)['ids']);
        update_term_meta($private_scope, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'public');
        ll_tools_bump_wordset_cache_epoch([$private_scope]);
        $this->assertContains($image, ll_tools_webp_optimizer_get_queue($args)['ids']);
        update_term_meta($private_scope, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        ll_tools_bump_wordset_cache_epoch([$private_scope]);
        $this->assertSame([], ll_tools_webp_optimizer_get_queue($args)['ids']);
    }

    public function test_category_labels_and_admin_selector_exclude_inaccessible_categories(): void
    {
        $viewer = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $viewer)->add_cap('view_ll_tools');
        $public_category = self::factory()->term->create(['taxonomy' => 'word-category', 'name' => 'Optimizer Public Label']);
        $private_category = self::factory()->term->create(['taxonomy' => 'word-category', 'name' => 'Optimizer Secret Label']);
        update_term_meta($private_category, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        [$shared, $private_only] = $this->createWordImagePosts(2, 'optimizer-category-scope');
        wp_set_object_terms($shared, [$public_category, $private_category], 'word-category');
        wp_set_object_terms($private_only, [$private_category], 'word-category');
        wp_set_current_user($viewer);
        $item = ll_tools_webp_optimizer_build_item($shared);
        $this->assertSame(['Optimizer Public Label'], $item['categories']);
        $this->assertSame([], ll_tools_webp_optimizer_build_item($private_only));
        ob_start();
        ll_tools_webp_optimizer_render_admin_page();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Optimizer Public Label', $html);
        $this->assertStringNotContainsString('Optimizer Secret Label', $html);
    }

    public function test_visibility_change_during_index_build_discards_ids_and_summary(): void
    {
        $scope = self::factory()->term->create(['taxonomy' => 'wordset', 'name' => 'Optimizer Changing Scope']);
        [$image] = $this->createWordImagePosts(1, 'optimizer-changing-scope');
        ll_tools_set_word_image_wordset_owner($image, $scope);
        $viewer = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $viewer)->add_cap('view_ll_tools');
        wp_set_current_user($viewer);
        $changed = false;
        $change_scope = static function ($value, $post_id, $key) use ($image, $scope, &$changed) {
            if (!$changed && (int) $post_id === $image && $key === '_thumbnail_id') {
                $changed = true;
                update_term_meta($scope, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
                ll_tools_bump_wordset_cache_epoch([$scope]);
            }
            return $value;
        };
        add_filter('get_post_metadata', $change_scope, 10, 3);
        try {
            $queue = ll_tools_webp_optimizer_get_queue(['include_non_flagged' => true, 'ids_only' => true]);
        } finally {
            remove_filter('get_post_metadata', $change_scope, 10);
        }
        $this->assertTrue($changed);
        $this->assertSame([], $queue['ids']);
        $this->assertSame(0, $queue['total_items']);
        $this->assertSame(0, $queue['summary']['queued_count']);
    }

    private function createImageAttachment(int $author, string $status): int
    {
        $attachment = self::factory()->post->create([
            'post_type' => 'attachment', 'post_status' => $status, 'post_author' => $author,
            'post_mime_type' => 'image/png', 'post_title' => 'Optimizer Synthetic Media',
        ]);
        update_post_meta($attachment, '_wp_attached_file', 'optimizer-fixture.png');
        wp_update_attachment_metadata($attachment, ['file' => 'optimizer-fixture.png', 'width' => 32, 'height' => 32, 'filesize' => 1000]);
        return $attachment;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }

    /**
     * @return array<int,int>
     */
    private function createWordImagePosts(int $count, string $prefix): array
    {
        $postIds = [];
        for ($index = 1; $index <= $count; $index++) {
            $postId = self::factory()->post->create([
                'post_type' => 'word_images',
                'post_status' => 'publish',
                'post_title' => sprintf('%s %02d', $prefix, $index),
            ]);
            $this->assertIsInt($postId);
            $this->assertGreaterThan(0, $postId);
            $postIds[] = (int) $postId;
        }

        return $postIds;
    }
}
