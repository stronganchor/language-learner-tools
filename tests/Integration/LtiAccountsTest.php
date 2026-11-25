<?php
declare(strict_types=1);

final class LtiAccountsTest extends LL_Tools_TestCase {
    private array $context;
    private array $resource;
    private int $learner;
    private int $teacher;
    private int $class;
    private int $wordset;
    private int $category;
    private string $binding;

    protected function setUp(): void {
        parent::setUp();
        add_filter('ll_tools_lms_credential_master_key', [$this, 'credentialKey']);
        ll_tools_register_or_refresh_teacher_role();
        ll_tools_register_or_refresh_learner_role();
        $this->assertTrue(ll_tools_install_lms_assignment_schema());
        $this->assertTrue(ll_tools_install_grade_delivery_schema());
        // Custom tables can retain rows across test transactions; isolate the foundation as its existing suites do.
        global $wpdb;
        foreach (ll_tools_grade_delivery_table_names() as $table) { $wpdb->query("DELETE FROM {$table}"); }
        ll_tools_lti_register_grade_adapter();
        delete_option(LL_TOOLS_LTI_RESOURCES_OPTION);
        delete_option(LL_TOOLS_LTI_PLATFORMS_OPTION);
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $registered = ll_tools_lti_register_platform([
            'id' => 'moodle', 'name' => 'Test Moodle', 'issuer' => 'https://moodle.example.org',
            'client_id' => 'client-1', 'deployment_id' => 'deployment-1',
            'authorization_url' => 'https://moodle.example.org/mod/lti/auth.php',
            'jwks_url' => 'https://moodle.example.org/mod/lti/certs.php',
            'token_url' => 'https://moodle.example.org/mod/lti/token.php', 'enabled' => true,
        ]);
        $this->assertIsArray($registered);
        $this->teacher = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $this->learner = self::factory()->user->create(['role' => 'll_tools_learner']);
        $this->wordset = (int) self::factory()->term->create(['taxonomy' => 'wordset']);
        $this->category = (int) self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($this->category, $this->wordset);
        update_term_meta($this->category, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($this->category, 'll_quiz_option_type', 'text_translation');
        for ($i = 1; $i <= 5; $i++) {
            $word = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'LTI word ' . $i]);
            wp_set_object_terms($word, [$this->wordset], 'wordset');
            wp_set_object_terms($word, [$this->category], 'word-category');
            update_post_meta($word, 'word_translation', 'LTI meaning ' . $i);
        }
        $class = ll_tools_teacher_class_create($this->teacher, 'LTI Course', $this->wordset);
        $this->assertIsInt($class);
        $this->class = $class;
        wp_set_current_user($this->teacher);
        $resource = ll_tools_lti_register_resource([
            'platform_id' => 'moodle', 'context_id' => 'course-11', 'class_id' => $this->class,
            'category_id' => $this->category, 'kind' => 'practice', 'name' => 'Vocabulary practice',
        ]);
        $this->assertIsArray($resource);
        $this->resource = $resource;
        $this->context = [
            'platform_id' => 'moodle', 'issuer' => $registered['issuer'], 'client_id' => 'client-1',
            'deployment_id' => 'deployment-1', 'subject' => 'student-22',
            'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
            'context_id' => 'course-11', 'resource_link_id' => 'resource-31',
            'custom' => ['ll_activity' => $resource['id']], 'ags' => [],
            'target_uri' => ll_tools_lti_tool_urls()['resource'], 'return_url' => '',
        ];
        $this->binding = str_repeat('a', 64);
        wp_set_current_user($this->learner);
    }

    public function credentialKey(): string { return str_repeat('x', 32); }

    public function test_first_resource_config_creator_wins_without_an_option_upsert(): void {
        global $wpdb;
        delete_option(LL_TOOLS_LTI_RESOURCES_OPTION);
        $this->assertSame([], ll_tools_lti_get_resources());
        $candidate = [$this->resource['id'] => $this->resource];
        $winner = $candidate; $winner[$this->resource['id']]['context_id'] = 'Course-11';
        $raced = false;
        $collision = static function (string $sql) use ($wpdb, $winner, &$raced): string {
            if (!$raced && stripos($sql, 'INSERT INTO') === 0 && strpos($sql, LL_TOOLS_LTI_RESOURCES_OPTION) !== false) {
                $raced = true;
                $wpdb->insert($wpdb->options, ['option_name' => LL_TOOLS_LTI_RESOURCES_OPTION, 'option_value' => maybe_serialize($winner), 'autoload' => 'no']);
            }
            return $sql;
        };
        $previous = $wpdb->suppress_errors(true);
        add_filter('query', $collision);
        try { $saved = ll_tools_lti_write_resources([], $candidate); }
        finally { remove_filter('query', $collision); $wpdb->suppress_errors($previous); }
        $this->assertTrue($raced);
        $this->assertFalse($saved);
        $this->assertSame($winner, ll_tools_lti_get_resources());
        $this->assertSame('no', $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", LL_TOOLS_LTI_RESOURCES_OPTION)));
    }

    public function test_resource_config_compare_and_swap_preserves_a_case_only_concurrent_change(): void {
        global $wpdb;
        $before = ll_tools_lti_get_resources();
        $after = $before; $after[$this->resource['id']]['enabled'] = false;
        $winner = $before; $winner[$this->resource['id']]['context_id'] = 'Course-11';
        $raced = false;
        $collision = static function (string $sql) use ($wpdb, $winner, &$raced): string {
            if (!$raced && str_starts_with($sql, "UPDATE {$wpdb->options} SET option_value = ") && strpos($sql, LL_TOOLS_LTI_RESOURCES_OPTION) !== false) {
                $raced = true;
                $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($winner)], ['option_name' => LL_TOOLS_LTI_RESOURCES_OPTION]);
            }
            return $sql;
        };
        add_filter('query', $collision);
        try { $saved = ll_tools_lti_write_resources($before, $after); }
        finally { remove_filter('query', $collision); }
        $this->assertTrue($raced);
        $this->assertFalse($saved);
        $this->assertSame($winner, ll_tools_lti_get_resources());
        $this->assertFalse(ll_tools_lti_write_resources($before, $after), 'A fresh read also rejects an already stale caller snapshot.');
    }

    public function test_stale_cached_pinned_activity_cannot_admit_after_it_is_disabled(): void {
        global $wpdb;
        $this->linkLearner();
        $cached = ll_tools_lti_get_resources();
        $this->assertSame($this->context['resource_link_id'], $cached[$this->resource['id']]['resource_link_id']);
        $disabled = $cached; $disabled[$this->resource['id']]['enabled'] = false;
        $this->assertSame(1, $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($disabled)], ['option_name' => LL_TOOLS_LTI_RESOURCES_OPTION]));
        $this->assertSame($cached, ll_tools_lti_get_resources(), 'Preserve the stale request-local option cache for this admission.');
        $result = ll_tools_lti_admit_account($this->context, $this->learner);
        $this->assertWPError($result);
        $this->assertSame('lti_resource_write_conflict', $result->get_error_code());
        $this->assertFalse(ll_tools_lti_get_resources()[$this->resource['id']]['enabled']);
        $this->assertTrue(ll_tools_teacher_class_user_is_student($this->class, $this->learner), 'Blocking the activity does not erase existing class membership.');
    }

    public function test_first_resource_link_pin_wins_despite_a_stale_option_cache(): void {
        global $wpdb;
        $cached = ll_tools_lti_get_resources();
        $winner = $cached; $winner[$this->resource['id']]['resource_link_id'] = 'first-moodle-activity';
        $this->assertSame(1, $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($winner)], ['option_name' => LL_TOOLS_LTI_RESOURCES_OPTION]));
        $this->assertSame($cached, ll_tools_lti_get_resources());
        $this->assertWPError(ll_tools_lti_bind_resource_link($this->resource, $this->context));
        $same = $this->context; $same['resource_link_id'] = 'first-moodle-activity';
        $this->assertTrue(ll_tools_lti_bind_resource_link($this->resource, $same));
        $actual = maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", LL_TOOLS_LTI_RESOURCES_OPTION)));
        $this->assertSame($winner, $actual);
    }

    public function test_instructor_setup_uses_exact_signed_activity_and_student_keeps_account(): void {
        $instructor = $this->context;
        $instructor['roles'] = ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'];
        $instructor['custom'] = [];
        $this->assertWPError(ll_tools_lti_teacher_setup_ticket($instructor));
        wp_set_current_user($this->teacher);
        $this->assertWPError(ll_tools_lti_teacher_setup_ticket($this->context));
        $ticket = ll_tools_lti_teacher_setup_ticket($instructor);
        $this->assertIsString($ticket);
        $scope = ll_tools_lti_teacher_setup_scope($ticket);
        $this->assertSame('resource-31', $scope['resource_link_id']);
        $this->assertFalse($scope['can_grade']);
        wp_set_current_user(self::factory()->user->create(['role' => 'll_tools_teacher']));
        $this->assertWPError(ll_tools_lti_teacher_setup_scope($ticket));
        wp_set_current_user($this->teacher);
        $input = $scope + ['setup_ticket' => $ticket, 'class_id' => $this->class,
            'category_id' => $this->category, 'name' => 'Guided practice', 'kind' => 'practice'];
        $student = $this->context;
        $student['custom'] = [];
        $this->assertWPError(ll_tools_lti_resolve_resource($student));
        $this->assertWPError(ll_tools_lti_register_resource(array_merge($input, ['context_id' => 'wrong-course'])));
        $this->assertSame('lti_grade_service_unavailable', ll_tools_lti_resource_preflight(array_merge($input, ['kind' => 'assignment']), $this->teacher)->get_error_code());
        $graded = $instructor;
        $graded['ags'] = ['lineitem' => 'https://moodle.example.org/grade/1', 'scope' => [LL_TOOLS_LTI_SCORE_SCOPE]];
        $this->assertTrue(ll_tools_lti_teacher_setup_scope(ll_tools_lti_teacher_setup_ticket($graded))['can_grade']);
        $row = ll_tools_lti_register_resource($input);
        $this->assertIsArray($row);
        $this->assertTrue($row['guided']);
        $this->assertWPError(ll_tools_lti_teacher_setup_scope($ticket));
        $input['setup_ticket'] = ll_tools_lti_teacher_setup_ticket($instructor);
        $this->assertWPError(ll_tools_lti_register_resource($input));
        wp_set_current_user($this->learner);
        $this->assertSame($row, ll_tools_lti_resolve_resource($student));
        foreach (['resource_link_id', 'context_id', 'deployment_id', 'issuer', 'client_id'] as $field) {
            $this->assertWPError(ll_tools_lti_resolve_resource(array_merge($student, [$field => 'other'])));
        }
        foreach (['', [], 'invalid', str_repeat('b', 32)] as $invalid) {
            $this->assertWPError(ll_tools_lti_resolve_resource(array_merge($student, ['custom' => ['ll_activity' => $invalid]])));
        }
        $confirmation = ll_tools_lti_prepare_account($student, $this->binding);
        $this->assertIsString($confirmation);
        $this->assertIsString(ll_tools_lti_confirm_account($confirmation, $this->binding, $this->learner));
        $this->assertSame($this->learner, (int) ll_tools_lti_find_account_identity($student)['learner_user_id']);
        $this->assertTrue(ll_tools_lti_disable_resource($row['id'], $this->teacher));
        $this->assertWPError(ll_tools_lti_resolve_resource($student));
    }

    protected function tearDown(): void {
        global $wpdb;
        foreach (ll_tools_grade_delivery_table_names() as $table) { $wpdb->query("DELETE FROM {$table}"); }
        if (isset($this->learner)) { ll_tools_lti_erase_user_data($this->learner); }
        remove_filter('ll_tools_lms_credential_master_key', [$this, 'credentialKey']);
        delete_option(LL_TOOLS_LTI_RESOURCES_OPTION);
        delete_option(LL_TOOLS_LTI_PLATFORMS_OPTION);
        parent::tearDown();
    }

    public function test_explicit_link_reuses_account_and_repeat_launch_preserves_identity_and_enrollment(): void {
        $ticket = ll_tools_lti_prepare_account($this->context, $this->binding);
        $this->assertIsString($ticket);
        $this->assertNull(ll_tools_lti_find_account_identity($this->context));
        $url = ll_tools_lti_confirm_account($ticket, $this->binding, $this->learner);
        $this->assertIsString($url);
        $this->assertStringContainsString('/embed/', $url);
        $identity = ll_tools_lti_find_account_identity($this->context);
        $this->assertSame($this->learner, (int) $identity['learner_user_id']);
        $this->assertSame('active', $identity['status']);
        $this->assertTrue(ll_tools_teacher_class_user_is_student($this->class, $this->learner));
        $this->assertSame([$this->class], get_user_meta($this->learner, '_ll_tools_lti_class_admissions', true));
        wp_set_current_user(0);
        $repeat = ll_tools_lti_admit_account($this->context, $this->learner);
        $this->assertSame($url, $repeat);
        $this->assertSame($identity['id'], ll_tools_lti_find_account_identity($this->context)['id']);
        $this->assertSame('resource-31', ll_tools_lti_get_resources()[$this->resource['id']]['resource_link_id']);
    }

    public function test_confirmation_requires_browser_binding_and_is_one_use(): void {
        $ticket = ll_tools_lti_prepare_account($this->context, $this->binding);
        $wrong = ll_tools_lti_confirm_account($ticket, str_repeat('b', 64), $this->learner);
        $this->assertWPError($wrong);
        $this->assertNull(ll_tools_lti_find_account_identity($this->context));
        $this->assertIsString(ll_tools_lti_confirm_account($ticket, $this->binding, $this->learner));
        $this->assertWPError(ll_tools_lti_confirm_account($ticket, $this->binding, $this->learner));
    }

    public function test_subject_collision_never_silently_switches_or_merges_accounts(): void {
        $this->linkLearner();
        $other = self::factory()->user->create(['role' => 'll_tools_learner']);
        wp_set_current_user($other);
        $result = ll_tools_lti_admit_account($this->context, $this->learner);
        $this->assertWPError($result);
        $this->assertSame('lti_account_session_conflict', $result->get_error_code());
        $this->assertSame($other, get_current_user_id());
        $ticket = ll_tools_lti_prepare_account($this->context, $this->binding);
        $this->assertWPError(ll_tools_lti_confirm_account($ticket, $this->binding, $other));
        $this->assertSame($this->learner, (int) ll_tools_lti_find_account_identity($this->context)['learner_user_id']);
    }

    public function test_instructor_claim_or_privileged_local_account_cannot_become_learner(): void {
        $instructor = $this->context;
        $instructor['roles'] = ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'];
        $this->assertWPError(ll_tools_lti_prepare_account($instructor, $this->binding));
        $user = get_userdata($this->learner);
        $user->add_role('administrator');
        $this->assertFalse(ll_tools_lti_account_is_learner($this->learner));
        $this->assertWPError(ll_tools_lti_admit_account($this->context, $this->learner, true));
        $this->assertTrue(user_can($this->learner, 'manage_options'));
    }

    public function test_wrong_course_deployment_and_reused_resource_link_are_rejected(): void {
        foreach (['context_id', 'deployment_id', 'platform_id'] as $field) {
            $bad = $this->context;
            $bad[$field] = 'other';
            $this->assertWPError(ll_tools_lti_resolve_resource($bad));
        }
        $this->linkLearner();
        $bad = $this->context;
        $bad['resource_link_id'] = 'different-resource';
        $this->assertWPError(ll_tools_lti_admit_account($bad, $this->learner));
        $other_teacher = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $this->assertWPError(ll_tools_lti_register_resource([
            'platform_id' => 'moodle', 'context_id' => 'course-22', 'class_id' => $this->class,
            'category_id' => $this->category, 'kind' => 'practice',
        ], $other_teacher));
    }

    public function test_teacher_removal_blocks_reenrollment_without_deleting_personal_account(): void {
        $this->linkLearner();
        $removed = ll_tools_teacher_class_remove_student($this->class, $this->learner);
        $this->assertIsArray($removed);
        $result = ll_tools_lti_admit_account($this->context, $this->learner);
        $this->assertWPError($result);
        $this->assertSame('lti_account_enrollment_suspended', $result->get_error_code());
        $this->assertInstanceOf(WP_User::class, get_userdata($this->learner));
        $this->assertFalse(ll_tools_teacher_class_user_is_student($this->class, $this->learner));
    }

    public function test_unlink_revokes_launch_without_erasing_personal_history(): void {
        $this->linkLearner();
        update_user_meta($this->learner, LL_TOOLS_USER_STARRED_META, [765]);
        $identity = ll_tools_lti_find_account_identity($this->context);
        $this->assertTrue(ll_tools_lti_unlink_account((int) $identity['id'], $this->learner));
        $this->assertSame('revoked', ll_tools_lti_find_account_identity($this->context)['status']);
        $this->assertWPError(ll_tools_lti_admit_account($this->context, $this->learner));
        $this->assertSame([765], get_user_meta($this->learner, LL_TOOLS_USER_STARRED_META, true));
    }

    public function test_manual_erasure_invalidates_old_confirmation_and_clears_admission(): void {
        $this->linkLearner();
        $pending = ll_tools_lti_prepare_account($this->context, $this->binding);
        $this->assertIsString($pending);
        $done = false;
        for ($page = 1; $page <= 5 && !$done; $page++) {
            $erased = ll_tools_privacy_erase_personal_data(get_userdata($this->learner)->user_email, $page);
            $this->assertNotWPError($erased);
            $done = !empty($erased['done']);
        }
        $this->assertTrue($done);
        $this->assertSame('', get_user_meta($this->learner, '_ll_tools_lti_class_admissions', true));
        $this->assertWPError(ll_tools_lti_confirm_account($pending, $this->binding, $this->learner));
        $this->assertNull(ll_tools_lti_find_account_identity($this->context));
    }

    public function test_deleted_account_cannot_confirm_old_pending_launch(): void {
        $ticket = ll_tools_lti_prepare_account($this->context, $this->binding);
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($this->learner);
        $this->assertWPError(ll_tools_lti_confirm_account($ticket, $this->binding, $this->learner));
        $this->assertFalse(ll_tools_lti_account_is_learner($this->learner));
    }

    public function test_removal_immediately_before_admission_lock_is_not_undone(): void {
        $this->linkLearner();
        $fired = false;
        $removed = null;
        $race = function (string $sql) use (&$fired, &$removed): string {
            if (!$fired && strpos($sql, 'GET_LOCK(') !== false) {
                $fired = true;
                $removed = ll_tools_teacher_class_remove_student($this->class, $this->learner);
            }
            return $sql;
        };
        add_filter('query', $race);
        try { $result = ll_tools_lti_admit_account($this->context, $this->learner); }
        finally { remove_filter('query', $race); }
        $this->assertTrue($fired);
        $this->assertIsArray($removed);
        $this->assertWPError($result);
        $this->assertSame('lti_account_enrollment_suspended', $result->get_error_code());
        $this->assertFalse(ll_tools_teacher_class_user_is_student($this->class, $this->learner));
    }

    public function test_unlink_immediately_before_admission_lock_cannot_open_practice(): void {
        $this->linkLearner();
        $identity_id = (int) ll_tools_lti_find_account_identity($this->context)['id'];
        $fired = false;
        $unlinked = null;
        $race = function (string $sql) use (&$fired, &$unlinked, $identity_id): string {
            if (!$fired && strpos($sql, 'GET_LOCK(') !== false) {
                $fired = true;
                $unlinked = ll_tools_lti_unlink_account($identity_id, $this->learner);
            }
            return $sql;
        };
        add_filter('query', $race);
        try { $result = ll_tools_lti_admit_account($this->context, $this->learner); }
        finally { remove_filter('query', $race); }
        $this->assertTrue($fired);
        $this->assertTrue($unlinked);
        $this->assertWPError($result);
        $this->assertSame('revoked', ll_tools_lti_find_account_identity($this->context)['status']);
    }

    public function test_admission_write_failure_rolls_back_identity_roster_and_resource_pin(): void {
        global $wpdb;
        $ticket = ll_tools_lti_prepare_account($this->context, $this->binding);
        $fault = static function (string $sql) use ($wpdb): string {
            return strpos($sql, $wpdb->usermeta) !== false && strpos($sql, '_ll_tools_lti_class_admissions') !== false
                && stripos($sql, 'INSERT INTO') === 0 ? 'INSERT INTO ll_lti_missing_admission_table (missing) VALUES (1)' : $sql;
        };
        $old = $wpdb->suppress_errors(true);
        add_filter('query', $fault);
        try { $result = ll_tools_lti_confirm_account($ticket, $this->binding, $this->learner); }
        finally { remove_filter('query', $fault); $wpdb->suppress_errors($old); }
        $this->assertWPError($result);
        $this->assertNull(ll_tools_lti_find_account_identity($this->context));
        $this->assertFalse(ll_tools_teacher_class_user_is_student($this->class, $this->learner));
        $this->assertSame([], ll_tools_teacher_class_get_ids_for_student($this->learner));
        $this->assertSame('', get_user_meta($this->learner, '_ll_tools_lti_class_admissions', true));
        $this->assertSame('', ll_tools_lti_get_resources()[$this->resource['id']]['resource_link_id']);
    }

    public function test_myisam_core_storage_blocks_account_link_before_writes(): void {
        global $wpdb;
        $expected = $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($wpdb->posts));
        $myisam = $wpdb->prepare('SELECT %s AS Name, %s AS Engine', $wpdb->posts, 'MyISAM');
        $interceptions = 0;
        $fault = static function (string $sql) use ($expected, $myisam, &$interceptions): string {
            if ($sql !== $expected) { return $sql; }
            $interceptions++;
            return $myisam;
        };
        add_filter('query', $fault);
        try { $result = ll_tools_lti_prepare_account($this->context, $this->binding); }
        finally { remove_filter('query', $fault); }
        $this->assertSame(1, $interceptions, 'The actual prepared posts-table engine query must be intercepted.');
        $this->assertWPError($result);
        $this->assertSame('lti_transactional_storage_required', $result->get_error_code());
        $this->assertNull(ll_tools_lti_find_account_identity($this->context));
        $this->assertFalse(ll_tools_teacher_class_user_is_student($this->class, $this->learner));
    }

    public function test_limited_role_gate_allows_exact_learner_handlers_and_teacher_setup(): void {
        $old_page = $GLOBALS['pagenow'] ?? null;
        $old_request = $_REQUEST;
        $old_get = $_GET;
        try {
            $GLOBALS['pagenow'] = 'admin-post.php';
            foreach (['ll_tools_lti_confirm_account', 'll_tools_lti_unlink_account'] as $action) {
                $_REQUEST['action'] = $action;
                $this->assertTrue(ll_tools_limited_role_admin_post_action_is_allowed(get_userdata($this->learner)));
            }
            $_REQUEST['action'] = 'll_tools_lti_register_resource';
            $this->assertFalse(ll_tools_limited_role_admin_post_action_is_allowed(get_userdata($this->learner)));
            $this->assertTrue(ll_tools_limited_role_admin_post_action_is_allowed(get_userdata($this->teacher)));
            $GLOBALS['pagenow'] = 'admin.php'; $_REQUEST = []; $_GET['page'] = 'll-tools-lti';
            $this->assertSame('', ll_tools_get_limited_role_admin_redirect_target(get_userdata($this->teacher), true, false));
        } finally {
            $GLOBALS['pagenow'] = $old_page; $_REQUEST = $old_request; $_GET = $old_get;
        }
    }

    private function linkLearner(): void {
        $ticket = ll_tools_lti_prepare_account($this->context, $this->binding);
        $this->assertIsString($ticket);
        $this->assertIsString(ll_tools_lti_confirm_account($ticket, $this->binding, $this->learner));
    }
}
