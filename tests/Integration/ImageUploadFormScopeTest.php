<?php
declare(strict_types=1);

final class ImageUploadFormScopeTest extends LL_Tools_TestCase
{
    public function test_failed_image_upload_cleanup_removes_file_and_attachment(): void
    {
        $path = wp_tempnam('ll-tools-failed-image.png');
        $this->assertIsString($path);
        $this->assertNotSame('', $path);
        file_put_contents($path, 'staged image');
        $attachment_id = wp_insert_attachment([
            'post_title' => 'Failed image attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image/png',
        ], $path);
        $this->assertIsInt($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);

        ll_image_upload_cleanup_failed_file($attachment_id, $path);

        $this->assertNull(get_post($attachment_id));
        $this->assertFileDoesNotExist($path);

        $unattached_path = wp_tempnam('ll-tools-failed-image-unattached.png');
        $this->assertIsString($unattached_path);
        file_put_contents($unattached_path, 'staged image');
        ll_image_upload_cleanup_failed_file(0, $unattached_path);
        $this->assertFileDoesNotExist($unattached_path);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_image_upload_form_locks_to_only_accessible_wordset_for_manager(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_id = $this->ensureTerm('wordset', 'Manager Upload Scope', 'manager-upload-scope');
        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        update_term_meta($wordset_id, 'manager_user_id', $user_id);
        wp_set_current_user($user_id);

        $html = ll_image_upload_form_shortcode();

        $this->assertStringContainsString('name="ll_single_wordset_id" value="' . $wordset_id . '"', $html);
        $this->assertStringContainsString('Only accessible word set', $html);
        $this->assertStringNotContainsString('name="ll_multi_wordset_ids[]"', $html);
    }

    public function test_image_upload_access_denies_audio_recorder_without_managed_wordset(): void
    {
        if (function_exists('ll_tools_register_or_refresh_audio_recorder_role')) {
            ll_tools_register_or_refresh_audio_recorder_role();
        }

        wp_set_current_user(0);
        $denied_wordset_id = $this->ensureTerm('wordset', 'Recorder Denied Upload Scope', 'recorder-denied-upload-scope');
        delete_term_meta($denied_wordset_id, 'manager_user_id');
        if (defined('LL_TOOLS_WORDSET_MANAGER_USER_IDS_META_KEY')) {
            delete_term_meta($denied_wordset_id, LL_TOOLS_WORDSET_MANAGER_USER_IDS_META_KEY);
        }

        $user_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('upload_files');
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $this->assertTrue(current_user_can('upload_files'));
        $this->assertTrue(current_user_can('view_ll_tools'));
        $this->assertFalse(current_user_can('manage_options'));
        $this->assertFalse(current_user_can('manage_categories'));
        $this->assertFalse(ll_image_upload_current_user_can_manage_upload_scope());
        $this->assertFalse(ll_image_upload_user_can_access_admin_tool());

        $html = ll_image_upload_form_shortcode();
        $this->assertStringContainsString('You do not have permission to upload files.', $html);
        $this->assertStringNotContainsString('<form', $html);

        $_POST = [
            'll_image_upload_nonce' => wp_create_nonce('ll_process_image_files'),
            'll_wordset_scope_mode' => 'single',
            'll_single_wordset_id' => (string) $denied_wordset_id,
        ];
        $_REQUEST = $_POST;

        $this->assertSame([], ll_image_upload_get_requested_wordset_ids_from_request());
    }

    public function test_image_upload_form_dedupes_isolated_categories_into_one_logical_option(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensureTerm('wordset', 'Image Upload Scope One', 'image-upload-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Image Upload Scope Two', 'image-upload-scope-two');
        $shared_category_id = $this->ensureTerm('word-category', 'Shared Trees', 'shared-trees');

        $this->createWordInScope('Image Upload Tree One', $wordset_one_id, $shared_category_id);
        $this->createWordInScope('Image Upload Tree Two', $wordset_two_id, $shared_category_id);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        $isolated_one_id = (int) ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_two_id);

        $html = ll_image_upload_form_shortcode();

        $this->assertMatchesRegularExpression(
            '/<option[^>]+value="' . preg_quote((string) $shared_category_id, '/') . '"[^>]+data-ll-category-wordsets="[^"]*' . preg_quote((string) $wordset_one_id, '/') . '[^"]*' . preg_quote((string) $wordset_two_id, '/') . '[^"]*"[^>]*>\s*Shared Trees\s*<\/option>/',
            $html
        );
        $this->assertSame(1, preg_match_all('/<option[^>]*>\s*Shared Trees\s*<\/option>/', $html, $matches));
        $this->assertStringNotContainsString('value="' . $isolated_one_id . '"', $html);
        $this->assertStringNotContainsString('value="' . $isolated_two_id . '"', $html);
    }

    public function test_create_category_from_request_uses_single_scope_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_id = $this->ensureTerm('wordset', 'Single Scope Upload Category', 'single-scope-upload-category');

        $_POST = [
            'll_category_mode' => 'new',
            'll_new_category_title' => 'Single Scope Plants',
            'll_wordset_scope_mode' => 'single',
            'll_single_wordset_id' => (string) $wordset_id,
        ];

        $created_category_id = ll_image_upload_create_category_from_request();

        $this->assertIsInt($created_category_id);
        $this->assertSame($wordset_id, ll_tools_get_category_wordset_owner_id((int) $created_category_id));
    }

    public function test_manager_can_render_new_category_controls_and_create_wordset_scoped_category(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_id = $this->ensureTerm('wordset', 'Manager Category Scope', 'manager-category-scope');
        $manager_id = self::factory()->user->create(['role' => 'author']);
        $manager = get_user_by('id', $manager_id);
        $this->assertInstanceOf(WP_User::class, $manager);
        $manager->add_cap('view_ll_tools');
        clean_user_cache($manager_id);
        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $html = ll_image_upload_form_shortcode();
        $this->assertStringContainsString('Create new category', $html);
        $this->assertStringContainsString('name="ll_new_category_title"', $html);

        $_POST = [
            'll_category_mode' => 'new',
            'll_new_category_title' => 'Manager Scoped Category',
            'll_wordset_scope_mode' => 'single',
            'll_single_wordset_id' => (string) $wordset_id,
        ];

        $created_category_id = ll_image_upload_create_category_from_request();

        $this->assertIsInt($created_category_id);
        $this->assertSame($wordset_id, ll_tools_get_category_wordset_owner_id((int) $created_category_id));
    }

    public function test_category_scope_validator_rejects_category_owned_by_other_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_id = $this->ensureTerm('wordset', 'Image Upload Category Scope Allowed', 'image-upload-category-scope-allowed');
        $other_wordset_id = $this->ensureTerm('wordset', 'Image Upload Category Scope Other', 'image-upload-category-scope-other');
        $category_id = $this->ensureTerm('word-category', 'Allowed Upload Category Scope', 'allowed-upload-category-scope');
        $other_category_id = $this->ensureTerm('word-category', 'Other Upload Category Scope', 'other-upload-category-scope');

        if (function_exists('ll_tools_set_category_wordset_owner')) {
            ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
            ll_tools_set_category_wordset_owner($other_category_id, $other_wordset_id, $other_category_id);
        }

        $this->assertTrue(ll_image_upload_category_ids_are_available_for_wordsets([$category_id], [$wordset_id]));
        $this->assertFalse(ll_image_upload_category_ids_are_available_for_wordsets([$other_category_id], [$wordset_id]));
    }

    public function test_create_category_from_request_uses_shared_logical_root_for_multiple_scope(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_one_id = $this->ensureTerm('wordset', 'Multi Scope Upload One', 'multi-scope-upload-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Multi Scope Upload Two', 'multi-scope-upload-two');

        $_POST = [
            'll_category_mode' => 'new',
            'll_new_category_title' => 'Shared Scope Plants',
            'll_wordset_scope_mode' => 'multiple',
            'll_multi_wordset_ids' => [(string) $wordset_one_id, (string) $wordset_two_id],
        ];

        $created_category_id = ll_image_upload_create_category_from_request();

        $this->assertIsInt($created_category_id);
        $this->assertSame(0, ll_tools_get_category_wordset_owner_id((int) $created_category_id));

        $effective_one_id = (int) ll_tools_get_effective_category_id_for_wordset((int) $created_category_id, $wordset_one_id, true);
        $effective_two_id = (int) ll_tools_get_effective_category_id_for_wordset((int) $created_category_id, $wordset_two_id, true);

        $this->assertGreaterThan(0, $effective_one_id);
        $this->assertGreaterThan(0, $effective_two_id);
        $this->assertNotSame($effective_one_id, $effective_two_id);
        $this->assertSame($wordset_one_id, ll_tools_get_category_wordset_owner_id($effective_one_id));
        $this->assertSame($wordset_two_id, ll_tools_get_category_wordset_owner_id($effective_two_id));
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }

        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);

        return (int) $inserted['term_id'];
    }

    private function createWordInScope(string $title, int $wordset_id, int $category_id): int
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
}
