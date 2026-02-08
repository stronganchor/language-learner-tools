<?php
declare(strict_types=1);

final class AudioRecorderRoleTest extends LL_Tools_TestCase
{
    public function test_audio_recorder_role_has_expected_caps(): void
    {
        ll_tools_register_or_refresh_audio_recorder_role();

        $role = get_role('audio_recorder');
        $this->assertNotNull($role);
        $this->assertTrue($role->has_cap('read'));
        $this->assertTrue($role->has_cap('upload_files'));
        $this->assertTrue($role->has_cap('view_ll_tools'));
    }

    public function test_user_can_record_requires_upload_and_access_capability(): void
    {
        wp_set_current_user(0);
        $this->assertFalse(ll_tools_user_can_record());

        $author_id = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author_id);
        $this->assertFalse(ll_tools_user_can_record());

        $author = get_user_by('id', $author_id);
        $this->assertInstanceOf(WP_User::class, $author);
        $author->add_cap('view_ll_tools');
        clean_user_cache($author_id);
        wp_set_current_user(0);
        wp_set_current_user($author_id);
        $this->assertTrue(ll_tools_user_can_record());

        $subscriber_id = self::factory()->user->create(['role' => 'subscriber']);
        $subscriber = get_user_by('id', $subscriber_id);
        $this->assertInstanceOf(WP_User::class, $subscriber);
        $subscriber->add_cap('view_ll_tools');
        wp_set_current_user($subscriber_id);
        $this->assertFalse(ll_tools_user_can_record());

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $this->assertTrue(ll_tools_user_can_record());
    }
}
