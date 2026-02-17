<?php
declare(strict_types=1);

final class RoleRedirectAccessTest extends LL_Tools_TestCase
{
    public function test_login_redirect_routes_audio_recorder_to_recording_page_by_default(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $recording_page_id = $this->create_page_with_shortcode('Recorder Page', '[audio_recording_interface]');
        update_option('ll_default_recording_page_id', $recording_page_id);

        $user_id = self::factory()->user->create(['role' => 'audio_recorder']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);

        $redirect = apply_filters('login_redirect', admin_url(), '', $user);

        $this->assertSame(get_permalink($recording_page_id), $redirect);
    }

    public function test_login_redirect_routes_learner_to_study_dashboard_by_default(): void
    {
        ll_tools_register_or_refresh_learner_role();

        $dashboard_page_id = $this->create_page_with_shortcode('Study Dashboard', '[ll_user_study_dashboard]');
        $expected_url = get_permalink($dashboard_page_id);
        $this->assertSame($expected_url, ll_tools_get_study_dashboard_redirect_url());

        $user_id = self::factory()->user->create(['role' => 'll_tools_learner']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);

        $redirect = apply_filters('login_redirect', admin_url(), '', $user);

        $this->assertSame($expected_url, $redirect);
    }

    public function test_login_redirect_routes_ll_tools_editor_to_editor_hub_by_default(): void
    {
        ll_create_ll_tools_editor_role();

        $editor_hub_page_id = $this->create_page_with_shortcode('Editor Hub', '[editor_hub]');
        update_option('ll_default_editor_hub_page_id', $editor_hub_page_id);

        $user_id = self::factory()->user->create(['role' => 'll_tools_editor']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);

        $redirect = apply_filters('login_redirect', admin_url(), '', $user);

        $this->assertSame(get_permalink($editor_hub_page_id), $redirect);
    }

    public function test_login_redirect_honors_requested_internal_redirect_for_limited_roles(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        ll_tools_register_or_refresh_learner_role();
        ll_create_ll_tools_editor_role();

        $requested = home_url('/requested-destination/');
        $users = [
            $this->create_user_with_role('audio_recorder'),
            $this->create_user_with_role('ll_tools_learner'),
            $this->create_user_with_role('ll_tools_editor'),
        ];

        foreach ($users as $user) {
            $redirect = apply_filters('login_redirect', admin_url(), $requested, $user);
            $this->assertSame($requested, $redirect);
        }
    }

    public function test_login_redirect_ignores_external_request_and_uses_role_default_target(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        ll_tools_register_or_refresh_learner_role();
        ll_create_ll_tools_editor_role();

        $recording_page_id = $this->create_page_with_shortcode('Recorder Page', '[audio_recording_interface]');
        $dashboard_page_id = $this->create_page_with_shortcode('Study Dashboard', '[ll_user_study_dashboard]');
        $editor_hub_page_id = $this->create_page_with_shortcode('Editor Hub', '[editor_hub]');

        update_option('ll_default_recording_page_id', $recording_page_id);
        update_option('ll_default_editor_hub_page_id', $editor_hub_page_id);

        $external_request = 'https://evil.example/phish';

        $recorder = $this->create_user_with_role('audio_recorder');
        $learner = $this->create_user_with_role('ll_tools_learner');
        $editor = $this->create_user_with_role('ll_tools_editor');

        $this->assertSame(
            get_permalink($recording_page_id),
            apply_filters('login_redirect', admin_url(), $external_request, $recorder)
        );
        $this->assertSame(
            get_permalink($dashboard_page_id),
            apply_filters('login_redirect', admin_url(), $external_request, $learner)
        );
        $this->assertSame(
            get_permalink($editor_hub_page_id),
            apply_filters('login_redirect', admin_url(), $external_request, $editor)
        );
    }

    public function test_limited_roles_get_wp_admin_redirect_target_in_admin_non_ajax_context(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();
        ll_tools_register_or_refresh_learner_role();

        $recording_page_id = $this->create_page_with_shortcode('Recorder Page', '[audio_recording_interface]');
        $dashboard_page_id = $this->create_page_with_shortcode('Study Dashboard', '[ll_user_study_dashboard]');
        update_option('ll_default_recording_page_id', $recording_page_id);

        $recorder = $this->create_user_with_role('audio_recorder');
        $learner = $this->create_user_with_role('ll_tools_learner');

        $this->assertSame(
            get_permalink($recording_page_id),
            ll_tools_get_limited_role_admin_redirect_target($recorder, true, false)
        );
        $this->assertSame(
            get_permalink($dashboard_page_id),
            ll_tools_get_limited_role_admin_redirect_target($learner, true, false)
        );
    }

    public function test_limited_role_admin_redirect_target_is_empty_when_access_should_remain_allowed(): void
    {
        ll_tools_register_or_refresh_learner_role();
        ll_create_ll_tools_editor_role();

        $learner = $this->create_user_with_role('ll_tools_learner');
        $editor = $this->create_user_with_role('ll_tools_editor');
        $admin = $this->create_user_with_role('administrator');

        $this->assertSame('', ll_tools_get_limited_role_admin_redirect_target($learner, false, false));
        $this->assertSame('', ll_tools_get_limited_role_admin_redirect_target($learner, true, true));
        $this->assertSame('', ll_tools_get_limited_role_admin_redirect_target($editor, true, false));
        $this->assertSame('', ll_tools_get_limited_role_admin_redirect_target($admin, true, false));
    }

    public function test_learner_role_refresh_removes_restricted_caps(): void
    {
        ll_tools_register_or_refresh_learner_role();

        $role = get_role('ll_tools_learner');
        $this->assertNotNull($role);

        $role->add_cap('upload_files');
        $role->add_cap('view_ll_tools');
        $role->add_cap('edit_posts');

        ll_tools_register_or_refresh_learner_role();

        $role = get_role('ll_tools_learner');
        $this->assertNotNull($role);
        $this->assertFalse($role->has_cap('upload_files'));
        $this->assertFalse($role->has_cap('view_ll_tools'));
        $this->assertFalse($role->has_cap('edit_posts'));
        $this->assertTrue($role->has_cap('read'));
    }

    private function create_page_with_shortcode(string $title, string $shortcode): int
    {
        return self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $shortcode,
        ]);
    }

    private function create_user_with_role(string $role): WP_User
    {
        $user_id = self::factory()->user->create(['role' => $role]);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->set_role($role);
        return $user;
    }
}
