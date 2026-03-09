<?php
declare(strict_types=1);

final class AdminDashboardMenuTitleRegressionTest extends LL_Tools_TestCase
{
    public function test_hidden_tool_page_primes_a_string_title_before_admin_header(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $user = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');

        global $title;
        $original_title = $title;
        $original_get = $_GET;
        $original_request = $_REQUEST;

        try {
            $title = null;
            $_GET['page'] = 'll-audio-processor';
            $_REQUEST['page'] = 'll-audio-processor';

            $screen = WP_Screen::get('tools_page_ll-audio-processor');
            ll_tools_prime_admin_title_for_dashboard_pages($screen);

            $this->assertSame('Audio Processor', $title);
        } finally {
            $title = $original_title;
            $_GET = $original_get;
            $_REQUEST = $original_request;
        }
    }

    public function test_words_list_screen_primes_a_string_title_before_admin_header(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $user = get_user_by('id', $admin_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');

        global $title;
        $original_title = $title;
        $original_get = $_GET;
        $original_request = $_REQUEST;

        try {
            $title = null;
            $_GET['post_type'] = 'words';
            $_REQUEST['post_type'] = 'words';

            $screen = WP_Screen::get('edit-words');
            ll_tools_prime_admin_title_for_dashboard_pages($screen);

            $this->assertSame('Words', $title);
        } finally {
            $title = $original_title;
            $_GET = $original_get;
            $_REQUEST = $original_request;
        }
    }
}
