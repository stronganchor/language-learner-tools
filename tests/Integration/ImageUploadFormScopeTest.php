<?php
declare(strict_types=1);

final class ImageUploadFormScopeTest extends LL_Tools_TestCase
{
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

    public function test_image_upload_form_dedupes_isolated_categories_into_single_logical_option(): void
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
        $this->assertSame(2, preg_match_all('/<option[^>]*>\s*Shared Trees\s*<\/option>/', $html, $matches));
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
