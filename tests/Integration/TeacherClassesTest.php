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

    public function test_manual_assignment_adds_learner_to_class_memberships(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher-manual@example.org',
        ]);
        $learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'learner-manual@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Manual Assignment Class');
        $this->assertIsInt($class_id);

        $result = ll_tools_teacher_class_assign_student((int) $class_id, $learner_id);

        $this->assertIsArray($result);
        $this->assertFalse($result['already_member']);
        $this->assertTrue(ll_tools_teacher_class_user_is_student((int) $class_id, $learner_id));
        $this->assertSame(
            [(int) $class_id],
            ll_tools_teacher_class_get_ids_for_student($learner_id)
        );
    }

    public function test_removing_student_updates_class_memberships(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher-remove@example.org',
        ]);
        $learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'learner-remove@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Removal Class');
        $this->assertIsInt($class_id);
        $this->assertTrue(ll_tools_teacher_class_add_student((int) $class_id, $learner_id));

        $result = ll_tools_teacher_class_remove_student((int) $class_id, $learner_id);

        $this->assertIsArray($result);
        $this->assertFalse($result['already_removed']);
        $this->assertFalse(ll_tools_teacher_class_user_is_student((int) $class_id, $learner_id));
        $this->assertSame([], ll_tools_teacher_class_get_ids_for_student($learner_id));
    }

    public function test_deleting_class_removes_student_memberships(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher-delete@example.org',
        ]);
        $learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'learner-delete@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Delete Class');
        $this->assertIsInt($class_id);
        $this->assertTrue(ll_tools_teacher_class_add_student((int) $class_id, $learner_id));

        $result = ll_tools_teacher_class_delete((int) $class_id);

        $this->assertIsArray($result);
        $this->assertFalse(ll_tools_teacher_class_exists((int) $class_id));
        $this->assertSame([], ll_tools_teacher_class_get_ids_for_student($learner_id));
    }

    public function test_class_creation_assigns_teacher_role_to_selected_user(): void
    {
        ll_tools_register_or_refresh_teacher_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'new-teacher@example.org',
        ]);

        $this->assertFalse(ll_tools_user_has_teacher_role($teacher_id));

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Assigned Teacher Class');

        $this->assertIsInt($class_id);
        $this->assertTrue(ll_tools_user_has_teacher_role($teacher_id));
        $this->assertSame($teacher_id, ll_tools_teacher_class_get_owner_id((int) $class_id));
    }

    public function test_assigning_class_teacher_updates_owner_and_promotes_user(): void
    {
        ll_tools_register_or_refresh_teacher_role();

        $current_teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'current-teacher@example.org',
        ]);
        $next_teacher_id = self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'next-teacher@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($current_teacher_id, 'Teacher Reassignment Class');
        $this->assertIsInt($class_id);
        $this->assertFalse(ll_tools_user_has_teacher_role($next_teacher_id));

        $result = ll_tools_teacher_class_assign_teacher((int) $class_id, $next_teacher_id);

        $this->assertIsArray($result);
        $this->assertTrue($result['ownership_changed']);
        $this->assertTrue($result['teacher_role_added']);
        $this->assertTrue(ll_tools_user_has_teacher_role($next_teacher_id));
        $this->assertSame($next_teacher_id, ll_tools_teacher_class_get_owner_id((int) $class_id));
    }

    public function test_assignable_students_excludes_existing_members_and_non_learners(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher-list@example.org',
        ]);
        $assigned_learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'already-in-class@example.org',
        ]);
        $available_learner_id = self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'available@example.org',
        ]);
        self::factory()->user->create([
            'role' => 'subscriber',
            'user_email' => 'subscriber@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Assignable Learners Class');
        $this->assertIsInt($class_id);
        $this->assertTrue(ll_tools_teacher_class_add_student((int) $class_id, $assigned_learner_id));

        $users = ll_tools_teacher_class_get_assignable_students((int) $class_id);
        $user_ids = array_map(static function ($user): int {
            return $user instanceof WP_User ? (int) $user->ID : 0;
        }, $users);
        sort($user_ids, SORT_NUMERIC);

        $this->assertSame([$available_learner_id], array_values(array_filter($user_ids)));
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

    public function test_teacher_role_redirects_wp_admin_to_frontend_classes_page(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_audio_recorder_role();

        $wordset = wp_insert_term('Teacher Classes Redirect ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);

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
        $this->assertSame(
            ll_tools_get_teacher_classes_frontend_url(),
            ll_tools_get_limited_role_admin_redirect_target($user, true, false)
        );
    }

    public function test_admin_classes_page_renders_manual_assignment_controls(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $admin_user = self::factory()->user->create_and_get([
            'role' => 'administrator',
            'user_email' => 'manual-assignment-admin@example.org',
        ]);
        $this->assertInstanceOf(WP_User::class, $admin_user);
        $admin_user->add_cap('view_ll_tools');
        $admin_id = (int) $admin_user->ID;
        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher-ui@example.org',
        ]);
        self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'learner-ui@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Rendered Manual Assignment Class');
        $this->assertIsInt($class_id);

        $original_get = $_GET;
        $_GET['class_id'] = (string) $class_id;
        wp_set_current_user($admin_id);

        ob_start();
        try {
            ll_tools_render_teacher_classes_page();
        } finally {
            $html = (string) ob_get_clean();
            $_GET = $original_get;
        }

        $this->assertStringContainsString('Assign a teacher', $html);
        $this->assertStringContainsString('ll_tools_teacher_assign_class_teacher', $html);
        $this->assertStringContainsString('ll_tools_teacher_class_teacher_user_id', $html);
        $this->assertStringContainsString('Assign an existing learner now', $html);
        $this->assertStringContainsString('ll_tools_teacher_assign_class_student', $html);
        $this->assertStringContainsString('Select a learner account', $html);
    }

    public function test_teacher_classes_page_hides_manual_assignment_controls_for_non_admin_teachers(): void
    {
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();

        $teacher_id = self::factory()->user->create([
            'role' => 'll_tools_teacher',
            'user_email' => 'teacher-only@example.org',
        ]);
        self::factory()->user->create([
            'role' => 'll_tools_learner',
            'user_email' => 'learner-hidden@example.org',
        ]);

        $class_id = ll_tools_teacher_class_create($teacher_id, 'Teacher View Class');
        $this->assertIsInt($class_id);

        $original_get = $_GET;
        $_GET['class_id'] = (string) $class_id;
        wp_set_current_user($teacher_id);

        ob_start();
        try {
            ll_tools_render_teacher_classes_page();
        } finally {
            $html = (string) ob_get_clean();
            $_GET = $original_get;
        }

        $this->assertStringNotContainsString('Assign a teacher', $html);
        $this->assertStringNotContainsString('ll_tools_teacher_assign_class_teacher', $html);
        $this->assertStringNotContainsString('ll_tools_teacher_class_teacher_user_id', $html);
        $this->assertStringNotContainsString('Assign an existing learner now', $html);
        $this->assertStringNotContainsString('ll_tools_teacher_assign_class_student', $html);
    }
}
