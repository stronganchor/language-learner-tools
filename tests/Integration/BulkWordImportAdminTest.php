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
}
