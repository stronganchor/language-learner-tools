<?php
declare(strict_types=1);

final class TeacherClassesTest extends LL_Tools_TestCase
{
    public function test_teacher_role_has_expected_caps(): void
    {
        ll_tools_register_or_refresh_teacher_role();

        $role = get_role('ll_tools_teacher');
        $this->assertNotNull($role);
        $this->assertTrue($role->has_cap('read'));
        $this->assertTrue($role->has_cap('view_ll_tools'));
        $this->assertTrue($role->has_cap(ll_tools_get_teacher_manage_classes_capability()));
        $this->assertTrue($role->has_cap(ll_tools_get_teacher_view_progress_capability()));
    }

    public function test_signup_invite_enables_registration_when_public_signup_is_disabled(): void
    {
        ll_tools_register_or_refresh_teacher_role();

        update_option('ll_allow_learner_self_registration', 0);
        update_option('users_can_register', 0);

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher@example.org',
        ]);
        $class_id = ll_tools_teacher_class_create($teacher_id, 'Invite Only Class');
        $this->assertIsInt($class_id);

        $token = ll_tools_teacher_class_build_invite_token((int) $class_id, ['mode' => 'signup']);
        $this->assertNotSame('', $token);

        $original_get = $_GET;
        $original_request = $_REQUEST;

        $_GET[LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG] = $token;
        $_REQUEST[LL_TOOLS_TEACHER_CLASS_INVITE_QUERY_ARG] = $token;
        ll_tools_teacher_class_reset_invite_request_context();

        try {
            $this->assertTrue(ll_tools_is_learner_self_registration_available());
            $this->assertTrue(ll_tools_teacher_class_current_request_allows_signup_registration());
        } finally {
            $_GET = $original_get;
            $_REQUEST = $original_request;
            ll_tools_teacher_class_reset_invite_request_context();
        }
    }

    public function test_accepting_signup_invite_adds_learner_to_class_memberships(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher@example.org',
        ]);
        $learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'learner@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Beginner Class');
        $this->assertIsInt($class_id);

        $token = ll_tools_teacher_class_build_invite_token((int) $class_id, ['mode' => 'signup']);
        $result = ll_tools_teacher_class_accept_invite_for_user($token, $learner_id);

        $this->assertIsArray($result);
        $this->assertFalse($result['already_member']);
        $this->assertTrue(ll_tools_teacher_class_user_is_student((int) $class_id, $learner_id));
        $this->assertSame(
            [(int) $class_id],
            ll_tools_teacher_class_get_ids_for_student($learner_id)
        );
    }

    public function test_existing_learner_invite_rejects_different_logged_in_user(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher@example.org',
        ]);
        $invited_learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'invited@example.org',
        ]);
        $other_learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'other@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Intermediate Class');
        $this->assertIsInt($class_id);

        $invited_user = get_userdata($invited_learner_id);
        $this->assertInstanceOf(WP_User::class, $invited_user);

        $token = ll_tools_teacher_class_build_invite_token((int) $class_id, [
            'mode' => 'existing',
            'email' => (string) $invited_user->user_email,
        ]);

        $result = ll_tools_teacher_class_accept_invite_for_user($token, $other_learner_id);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('wrong_user', $result->get_error_code());
        $this->assertFalse(ll_tools_teacher_class_user_is_student((int) $class_id, $other_learner_id));
    }

    public function test_teacher_role_overrides_limited_recorder_admin_redirect(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_audio_recorder_role();

        $user_id = self::factory()->user->create([
            'role' => 'audio_recorder',
            'user_email' => 'mixed-role@example.org',
        ]);
        $user = get_userdata($user_id);
        $this->assertInstanceOf(WP_User::class, $user);

        $user->add_role('ll_tools_teacher');
        clean_user_cache($user_id);
        $user = get_userdata($user_id);

        $this->assertInstanceOf(WP_User::class, $user);
        $this->assertTrue(ll_tools_user_can_manage_classes($user_id));
        $this->assertSame('', ll_tools_get_limited_role_admin_redirect_target($user, true, false));
    }
}
