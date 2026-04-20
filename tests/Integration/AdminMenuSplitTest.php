<?php
declare(strict_types=1);

final class AdminMenuSplitTest extends LL_Tools_TestCase
{
    public function test_tools_pages_highlight_ll_tools_top_level_menu(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $user = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');

        $original_get = $_GET;
        $original_request = $_REQUEST;
        $original_screen = $GLOBALS['current_screen'] ?? null;

        try {
            $_GET['page'] = 'll-audio-processor';
            $_REQUEST['page'] = 'll-audio-processor';
            set_current_screen('tools_page_ll-audio-processor');

            $this->assertSame(
                ll_tools_get_tools_hub_page_slug(),
                ll_tools_force_dashboard_parent_file('tools.php')
            );
            $this->assertSame(
                'tools.php?page=ll-audio-processor',
                ll_tools_force_dashboard_submenu_file('')
            );
        } finally {
            $_GET = $original_get;
            $_REQUEST = $original_request;
            $GLOBALS['current_screen'] = $original_screen;
        }
    }

    public function test_language_learning_pages_keep_language_learning_parent_menu(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $user = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');

        $original_get = $_GET;
        $original_request = $_REQUEST;
        $original_screen = $GLOBALS['current_screen'] ?? null;

        try {
            $_GET['post_type'] = 'words';
            $_REQUEST['post_type'] = 'words';
            set_current_screen('edit-words');

            $this->assertSame(
                ll_tools_get_admin_menu_slug(),
                ll_tools_force_dashboard_parent_file('edit.php')
            );
            $this->assertSame(
                'edit.php?post_type=words',
                ll_tools_force_dashboard_submenu_file('')
            );
        } finally {
            $_GET = $original_get;
            $_REQUEST = $original_request;
            $GLOBALS['current_screen'] = $original_screen;
        }
    }

    public function test_tools_menu_items_keep_legacy_tool_routes_and_include_progress_report(): void
    {
        $items = ll_tools_get_tools_hub_menu_items();
        $menu_slugs = array_values(array_filter(array_map(static function ($item): string {
            return isset($item['menu_slug']) ? (string) $item['menu_slug'] : '';
        }, $items)));

        $this->assertContains('tools.php?page=ll-audio-processor', $menu_slugs);
        $this->assertContains('admin.php?page=ll-tools-user-progress-report', $menu_slugs);
        $this->assertContains('edit.php?post_type=ll_vocab_lesson', $menu_slugs);
    }

    public function test_top_level_menu_titles_use_matching_ll_tools_labels(): void
    {
        $this->assertSame('LL Tools', ll_tools_get_language_learning_menu_title());
        $this->assertSame('LL Tools Utilities', ll_tools_get_tools_hub_menu_title());
        $this->assertLessThan(
            ll_tools_get_tools_hub_menu_position(),
            ll_tools_get_language_learning_menu_position()
        );
    }

    public function test_tools_hub_direct_submenu_items_include_requested_workflows_only(): void
    {
        $items = ll_tools_get_tools_hub_direct_submenu_items();
        $page_slugs = array_values(array_filter(array_map(static function ($item): string {
            return isset($item['page_slug']) ? (string) $item['page_slug'] : '';
        }, $items)));

        $this->assertSame(ll_tools_get_tools_hub_direct_submenu_page_slugs(), $page_slugs);
        $this->assertNotContains('ll-audio-image-matcher', $page_slugs);
        $this->assertNotContains('ll-ipa-keyboard', $page_slugs);
    }
}
