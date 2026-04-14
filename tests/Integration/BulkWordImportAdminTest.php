<?php
declare(strict_types=1);

final class BulkWordImportAdminTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    public function test_bulk_word_import_capability_defaults_to_manage_options_but_can_be_filtered(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $recorder_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($recorder_id);
        $this->assertSame('manage_options', ll_tools_get_bulk_word_import_capability());
        $this->assertFalse(ll_tools_current_user_can_bulk_word_import());

        wp_set_current_user($admin_id);
        $this->assertTrue(ll_tools_current_user_can_bulk_word_import());

        $filter = static function (): string {
            return 'view_ll_tools';
        };
        add_filter('ll_tools_bulk_word_import_capability', $filter);

        try {
            wp_set_current_user($recorder_id);
            $this->assertSame('view_ll_tools', ll_tools_get_bulk_word_import_capability());
            $this->assertTrue(ll_tools_current_user_can_bulk_word_import());
        } finally {
            remove_filter('ll_tools_bulk_word_import_capability', $filter);
        }
    }

    public function test_bulk_word_import_submission_is_ignored_without_hardened_capability(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create(['role' => 'author']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);

        $title = 'Locked Bulk Import ' . wp_generate_password(8, false);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_bulk_word_import_nonce' => wp_create_nonce('ll_bulk_word_import'),
            'll_word_list' => $title,
            'll_existing_wordset' => '0',
            'll_existing_category' => '0',
            'll_new_category' => '',
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_tools_render_bulk_word_import_page();
        $output = (string) ob_get_clean();

        $created_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
            'words',
            $title
        ));

        $this->assertSame('', $output);
        $this->assertSame(0, $created_id);
    }

    public function test_bulk_word_import_submission_creates_draft_words_for_administrator(): void
    {
        global $wpdb;

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $title = 'Allowed Bulk Import ' . wp_generate_password(8, false);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_bulk_word_import_nonce' => wp_create_nonce('ll_bulk_word_import'),
            'll_word_list' => strtolower($title),
            'll_existing_wordset' => '0',
            'll_existing_category' => '0',
            'll_new_category' => '',
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_tools_render_bulk_word_import_page();
        ob_end_clean();

        $created_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
            'words',
            $title
        ));

        $this->assertGreaterThan(0, $created_id);
        $this->assertSame('draft', get_post_status($created_id));
    }

    public function test_bulk_word_import_submission_can_store_translations_from_tab_and_csv_rows(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_bulk_word_import_nonce' => wp_create_nonce('ll_bulk_word_import'),
            'll_word_list' => "merhaba\thello\nsalut,\"hello, hi\"",
            'll_existing_wordset' => '0',
            'll_existing_category' => '0',
            'll_new_category' => '',
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_tools_render_bulk_word_import_page();
        ob_end_clean();

        $tab_word_id = $this->findWordIdByTitle('Merhaba');
        $csv_word_id = $this->findWordIdByTitle('Salut');

        $this->assertGreaterThan(0, $tab_word_id);
        $this->assertGreaterThan(0, $csv_word_id);
        $this->assertSame('hello', (string) get_post_meta($tab_word_id, 'word_translation', true));
        $this->assertSame('hello, hi', (string) get_post_meta($csv_word_id, 'word_translation', true));
    }

    public function test_bulk_word_import_page_explains_supported_two_column_formats(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        ob_start();
        ll_tools_render_bulk_word_import_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Word + translation with a tab', $html);
        $this->assertStringContainsString('bonjour[TAB]hello', $html);
        $this->assertStringContainsString('Word + translation with a comma', $html);
        $this->assertStringContainsString('spreadsheet columns', $html);
    }

    public function test_bulk_word_import_category_dropdown_is_scoped_to_selected_wordset(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset_one = wp_insert_term('Bulk Import Scope One', 'wordset', ['slug' => 'bulk-import-scope-one']);
        $wordset_two = wp_insert_term('Bulk Import Scope Two', 'wordset', ['slug' => 'bulk-import-scope-two']);
        $shared_category = wp_insert_term('Bulk Import Shared Category', 'word-category', ['slug' => 'bulk-import-shared-category']);

        $this->assertIsArray($wordset_one);
        $this->assertIsArray($wordset_two);
        $this->assertIsArray($shared_category);

        $wordset_one_id = (int) $wordset_one['term_id'];
        $wordset_two_id = (int) $wordset_two['term_id'];
        $shared_category_id = (int) $shared_category['term_id'];

        $this->createWordInScope('Bulk Import Scope Word One', $wordset_one_id, $shared_category_id);
        $this->createWordInScope('Bulk Import Scope Word Two', $wordset_two_id, $shared_category_id);

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
        ll_tools_run_wordset_isolation_migration();

        $isolated_one = (int) ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_one_id);
        $isolated_two = (int) ll_tools_get_existing_isolated_category_copy_id($shared_category_id, $wordset_two_id);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'll_existing_wordset' => (string) $wordset_one_id,
            'll_existing_category' => (string) $shared_category_id,
        ];
        $_REQUEST = $_POST;

        ob_start();
        ll_tools_render_bulk_word_import_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="' . $isolated_one . '"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . preg_quote((string) $isolated_one, '/') . '".*selected/', $html);
        $this->assertStringNotContainsString('value="' . $shared_category_id . '"', $html);
        $this->assertStringNotContainsString('value="' . $isolated_two . '"', $html);
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

    private function findWordIdByTitle(string $title): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
            'words',
            $title
        ));
    }
}
