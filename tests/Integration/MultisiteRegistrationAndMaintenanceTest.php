<?php
declare(strict_types=1);

final class MultisiteRegistrationAndMaintenanceTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_multisite()) {
            $this->markTestSkipped('Run with tests/phpunit.multisite.xml.dist.');
        }
    }

    public function test_registration_sync_preserves_the_network_site_registration_state(): void
    {
        $original_registration = get_site_option('registration', null);
        $cases = [
            ['current' => 'none', 'enabled' => 1, 'expected' => 'user', 'available' => true],
            ['current' => 'user', 'enabled' => 1, 'expected' => 'user', 'available' => true],
            ['current' => 'blog', 'enabled' => 1, 'expected' => 'all', 'available' => true],
            ['current' => 'all', 'enabled' => 1, 'expected' => 'all', 'available' => true],
            ['current' => 'none', 'enabled' => 0, 'expected' => 'none', 'available' => false],
            ['current' => 'user', 'enabled' => 0, 'expected' => 'none', 'available' => false],
            ['current' => 'blog', 'enabled' => 0, 'expected' => 'blog', 'available' => false],
            ['current' => 'all', 'enabled' => 0, 'expected' => 'blog', 'available' => false],
        ];

        try {
            foreach ($cases as $case) {
                update_site_option('registration', $case['current']);

                ll_tools_sync_wordpress_registration_setting($case['enabled']);

                $this->assertSame($case['expected'], get_site_option('registration'));
                $this->assertSame($case['available'], ll_tools_is_wordpress_user_registration_enabled());
            }
        } finally {
            if ($original_registration === null) {
                delete_site_option('registration');
            } else {
                update_site_option('registration', $original_registration);
            }
        }
    }

    public function test_learner_option_updates_use_the_shared_network_synchronizer(): void
    {
        $original_registration = get_site_option('registration', null);
        $original_learner_setting = get_option('ll_allow_learner_self_registration', null);

        try {
            delete_option('ll_allow_learner_self_registration');
            update_site_option('registration', 'blog');

            update_option('ll_allow_learner_self_registration', '1');
            $this->assertSame(1, get_option('ll_allow_learner_self_registration'));
            $this->assertSame('all', get_site_option('registration'));

            update_option('ll_allow_learner_self_registration', '0');
            $this->assertSame(0, get_option('ll_allow_learner_self_registration'));
            $this->assertSame('blog', get_site_option('registration'));
        } finally {
            if ($original_learner_setting === null) {
                delete_option('ll_allow_learner_self_registration');
            } else {
                update_option('ll_allow_learner_self_registration', $original_learner_setting);
            }

            if ($original_registration === null) {
                delete_site_option('registration');
            } else {
                update_site_option('registration', $original_registration);
            }
        }
    }

    public function test_network_media_cache_schedule_and_cleanup_visit_each_site_and_restore_context(): void
    {
        $original_blog_id = get_current_blog_id();
        $second_blog_id = (int) self::factory()->blog->create();
        $site_ids = [$original_blog_id, $second_blog_id];

        try {
            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                wp_clear_scheduled_hook(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK);
                wp_clear_scheduled_hook(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK);
                delete_option(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION);
                restore_current_blog();
            }

            ll_tools_schedule_media_proxy_cache_maintenance(true);
            $this->assertSame($original_blog_id, get_current_blog_id());

            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK));
                wp_schedule_single_event(
                    time() + MINUTE_IN_SECONDS,
                    LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK
                );
                update_option(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION, ['offset' => 7], false);
                restore_current_blog();
            }

            ll_tools_clear_media_proxy_cache_maintenance_schedule(true);
            $this->assertSame($original_blog_id, get_current_blog_id());

            foreach ($site_ids as $site_id) {
                switch_to_blog($site_id);
                $this->assertFalse(wp_next_scheduled(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_HOOK));
                $this->assertFalse(wp_next_scheduled(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CONTINUATION_HOOK));
                $this->assertFalse(get_option(LL_TOOLS_MEDIA_PROXY_CACHE_MAINTENANCE_CURSOR_OPTION, false));
                restore_current_blog();
            }
        } finally {
            while (ms_is_switched()) {
                restore_current_blog();
            }
            ll_tools_clear_media_proxy_cache_maintenance_schedule(true);
        }
    }

    public function test_network_user_deletion_fences_and_erases_lms_rows_on_every_site(): void
    {
        global $wpdb;

        if (!function_exists('wpmu_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }
        $originalBlogId = get_current_blog_id();
        $secondBlogId = (int) self::factory()->blog->create();
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($originalBlogId, $userId, 'subscriber');
        add_user_to_blog($secondBlogId, $userId, 'subscriber');
        $siteIds = [$originalBlogId, $secondBlogId];
        $allowedLmsTables = [];
        $temporaryLmsTables = [];
        $temporaryStatusShim = static function (string $query) use (&$allowedLmsTables, &$temporaryLmsTables): string {
            $trimmed = trim($query);
            if (
                preg_match('/^CREATE TEMPORARY TABLE\s+`?([A-Za-z0-9_]+)`?/i', $trimmed, $matches) === 1
                && isset($allowedLmsTables[$matches[1]])
                && preg_match('/\bENGINE\s*=\s*InnoDB\b/i', $trimmed) === 1
            ) {
                $temporaryLmsTables[$matches[1]] = true;
                return $query;
            }
            if (preg_match('/^SHOW TABLE STATUS\b/i', $trimmed) === 1) {
                $normalized = str_replace(['\\\\_', '\\_'], '_', $trimmed);
                foreach (array_keys($temporaryLmsTables) as $tableName) {
                    if (str_contains($normalized, "'{$tableName}'")) {
                        return "SELECT '{$tableName}' AS Name, 'InnoDB' AS Engine";
                    }
                }
            }
            return $query;
        };
        // Runs after WP_UnitTestCase's CREATE->CREATE TEMPORARY rewrite. Only
        // exact plugin tables whose real DDL declares InnoDB receive a
        // synthetic status row; real column/index reads still inspect MySQL.
        add_filter('query', $temporaryStatusShim, 20);

        try {
            foreach ($siteIds as $siteId) {
                switch_to_blog($siteId);
                $siteTables = array_merge(
                    array_values(ll_tools_lms_assignment_table_names()),
                    array_values(ll_tools_grade_delivery_table_names()),
                    array_values(ll_tools_google_classroom_table_names())
                );
                $allowedLmsTables = array_fill_keys($siteTables, true);
                $this->assertTrue(
                    ll_tools_install_lms_assignment_schema(),
                    'Temporary LMS tables seen: ' . wp_json_encode(array_keys($temporaryLmsTables))
                        . '; DB error: ' . (string) $wpdb->last_error
                );
                $this->assertTrue(ll_tools_install_grade_delivery_schema());
                $this->assertTrue(ll_tools_install_google_classroom_schema());
                $now = gmdate('Y-m-d H:i:s');
                $assignments = ll_tools_lms_assignment_table_names();
                $deliveries = ll_tools_grade_delivery_table_names();
                $google = ll_tools_google_classroom_table_names();
                $this->assertSame(1, $wpdb->insert($assignments['grades'], [
                    'assignment_id' => 700000 + $siteId,
                    'revision_id' => 710000 + $siteId,
                    'user_id' => $userId,
                    'selected_attempt_id' => 720000 + $siteId,
                    'grade_revision' => 1,
                    'score_given' => 1,
                    'score_maximum' => 1,
                    'points_given' => '1.0000',
                    'points_maximum' => '1.0000',
                    'grade_policy' => 'latest',
                    'updated_at' => $now,
                ]));
                $this->assertSame(1, $wpdb->insert($deliveries['identities'], [
                    'adapter' => 'multisite_test',
                    'connection_key_hash' => hash('sha256', 'connection-' . $siteId),
                    'subject_key_hash' => hash('sha256', 'subject-' . $siteId),
                    'learner_user_id' => $userId,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
                $this->assertSame(1, $wpdb->insert($google['connections'], [
                    'teacher_user_id' => $userId,
                    'google_sub_hash' => hash('sha256', 'sub-' . $siteId),
                    'credential_envelope' => 'multisite-test-envelope',
                    'scopes_json' => '[]',
                    'status' => 'connected',
                    'token_version' => 1,
                    'connected_at' => $now,
                    'updated_at' => $now,
                    'disconnected_at' => null,
                ]));
                restore_current_blog();
            }

            $this->assertTrue(wpmu_delete_user($userId));
            $this->assertFalse(get_userdata($userId));

            foreach ($siteIds as $siteId) {
                switch_to_blog($siteId);
                // The hook may finish a small site synchronously; calling the
                // consumer again is intentionally a no-op once dequeued.
                ll_tools_privacy_cleanup_deleted_user_lms_data($userId);
                $assignments = ll_tools_lms_assignment_table_names();
                $deliveries = ll_tools_grade_delivery_table_names();
                $google = ll_tools_google_classroom_table_names();
                $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$assignments['grades']} WHERE user_id = %d",
                    $userId
                )));
                $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$deliveries['identities']} WHERE learner_user_id = %d",
                    $userId
                )));
                $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$google['connections']} WHERE teacher_user_id = %d",
                    $userId
                )));
                $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
                restore_current_blog();
            }
        } finally {
            while (ms_is_switched()) {
                restore_current_blog();
            }
            foreach ($siteIds as $siteId) {
                switch_to_blog($siteId);
                wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
                ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId);
                restore_current_blog();
            }
            foreach (array_keys($temporaryLmsTables) as $tableName) {
                if (preg_match('/^[A-Za-z0-9_]+$/D', $tableName) === 1) {
                    $wpdb->query("DROP TABLE IF EXISTS `{$tableName}`");
                }
            }
            remove_filter('query', $temporaryStatusShim, 20);
            if (get_userdata($userId)) {
                wpmu_delete_user($userId);
            }
        }
    }

    public function test_direct_site_membership_removal_unlinks_classes_and_continues_bounded_lms_cleanup(): void
    {
        global $wpdb;

        if (!function_exists('wpmu_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }

        $blogId = get_current_blog_id();
        $teacherId = self::factory()->user->create(['role' => 'll_tools_teacher']);
        $userId = self::factory()->user->create(['role' => 'll_tools_learner']);
        $suffix = strtolower(wp_generate_password(8, false, false));
        $wordsetId = 0;
        $classId = 0;
        $tables = ll_tools_lms_assignment_table_names();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->assertNotFalse(has_action(
                'remove_user_from_blog',
                'll_tools_teacher_class_cleanup_removed_user'
            ));
            $this->assertNotFalse(has_action(
                'remove_user_from_blog',
                'll_tools_privacy_prepare_removed_user_lms_cleanup'
            ));
            $this->assertTrue(ll_tools_install_lms_assignment_schema());
            $this->assertTrue(ll_tools_install_grade_delivery_schema());
            $this->assertTrue(ll_tools_install_google_classroom_schema());

            $wordset = wp_insert_term(
                'Multisite Removal Wordset ' . $suffix,
                'wordset',
                ['slug' => 'multisite-removal-' . $suffix]
            );
            $this->assertIsArray($wordset);
            $wordsetId = (int) $wordset['term_id'];
            $class = ll_tools_teacher_class_create(
                $teacherId,
                'Multisite Removal Class ' . $suffix,
                $wordsetId
            );
            $this->assertIsInt($class);
            $classId = (int) $class;
            $this->assertTrue(ll_tools_teacher_class_add_student($classId, $userId));
            $this->assertTrue(ll_tools_teacher_class_user_is_student($classId, $userId));
            $this->assertContains($classId, ll_tools_teacher_class_get_ids_for_student($userId));
            $this->assertTrue(is_user_member_of_blog($userId, $blogId));

            for ($index = 1; $index <= 3; $index++) {
                $this->assertSame(1, $wpdb->insert($tables['grades'], [
                    'assignment_id' => 760000 + $index,
                    'revision_id' => 770000 + $index,
                    'user_id' => $userId,
                    'selected_attempt_id' => 780000 + $index,
                    'grade_revision' => 1,
                    'score_given' => 1,
                    'score_maximum' => 1,
                    'points_given' => '1.0000',
                    'points_maximum' => '1.0000',
                    'grade_policy' => 'latest',
                    'updated_at' => $now,
                ]));
            }

            add_filter('ll_tools_lms_deleted_user_cleanup_passes', static fn(): int => 1);
            add_filter('ll_tools_lms_assignment_erasure_batch_size', static fn(): int => 1);

            $this->assertTrue(remove_user_from_blog($userId, $blogId));

            $this->assertInstanceOf(WP_User::class, get_userdata($userId));
            $this->assertFalse(is_user_member_of_blog($userId, $blogId));
            $this->assertFalse(ll_tools_teacher_class_user_is_student($classId, $userId));
            $this->assertNotContains($classId, ll_tools_teacher_class_get_ids_for_student($userId));
            $this->assertFalse(ll_tools_privacy_deleted_user_post_seen($userId));
            $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $this->assertFalse(ll_tools_lms_assignment_lock_user($userId));
            $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
            $this->assertSame('2', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['grades']} WHERE user_id = %d",
                $userId
            )));

            remove_all_filters('ll_tools_lms_deleted_user_cleanup_passes');
            remove_all_filters('ll_tools_lms_assignment_erasure_batch_size');
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);

            $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['grades']} WHERE user_id = %d",
                $userId
            )));
            $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
        } finally {
            remove_all_filters('ll_tools_lms_deleted_user_cleanup_passes');
            remove_all_filters('ll_tools_lms_assignment_erasure_batch_size');
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId);
            if ($classId > 0) {
                wp_delete_post($classId, true);
            }
            if ($wordsetId > 0) {
                wp_delete_term($wordsetId, 'wordset');
            }
            foreach ([$userId, $teacherId] as $cleanupUserId) {
                if (get_userdata($cleanupUserId)) {
                    wpmu_delete_user($cleanupUserId);
                }
                ll_tools_privacy_cleanup_deleted_user_lms_data($cleanupUserId);
                wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$cleanupUserId]);
                ll_tools_privacy_dequeue_deleted_user_lms_cleanup($cleanupUserId);
            }
        }
    }

    public function test_site_only_user_removal_persists_post_delete_proof_until_bounded_cleanup_finishes(): void
    {
        global $wpdb;

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (!function_exists('wpmu_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }

        $blogId = get_current_blog_id();
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($blogId, $userId, 'subscriber');
        $tables = ll_tools_lms_assignment_table_names();
        $now = gmdate('Y-m-d H:i:s');

        try {
            $this->assertTrue(ll_tools_install_lms_assignment_schema());
            for ($index = 1; $index <= 3; $index++) {
                $this->assertSame(1, $wpdb->insert($tables['grades'], [
                    'assignment_id' => 730000 + $index,
                    'revision_id' => 740000 + $index,
                    'user_id' => $userId,
                    'selected_attempt_id' => 750000 + $index,
                    'grade_revision' => 1,
                    'score_given' => 1,
                    'score_maximum' => 1,
                    'points_given' => '1.0000',
                    'points_maximum' => '1.0000',
                    'grade_policy' => 'latest',
                    'updated_at' => $now,
                ]));
            }

            add_filter('ll_tools_lms_deleted_user_cleanup_passes', static fn(): int => 1);
            add_filter('ll_tools_lms_assignment_erasure_batch_size', static fn(): int => 1);
            $this->assertTrue(wp_delete_user($userId));

            $this->assertInstanceOf(WP_User::class, get_userdata($userId));
            $this->assertFalse(is_user_member_of_blog($userId, $blogId));
            $this->assertTrue(ll_tools_privacy_deleted_user_post_seen($userId));
            $this->assertTrue(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $tombstoneName = ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId);
            wp_cache_delete($tombstoneName, 'options');
            $tombstone = get_option($tombstoneName, false);
            $this->assertIsArray($tombstone);
            $this->assertGreaterThan(0, (int) ($tombstone['post_seen_at'] ?? 0));
            $this->assertSame('1', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['grades']} WHERE user_id = %d",
                $userId
            )));

            remove_all_filters('ll_tools_lms_deleted_user_cleanup_passes');
            remove_all_filters('ll_tools_lms_assignment_erasure_batch_size');
            ll_tools_privacy_cleanup_deleted_user_lms_data($userId);

            $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tables['grades']} WHERE user_id = %d",
                $userId
            )));
            $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $this->assertFalse(ll_tools_privacy_deleted_user_post_seen($userId));
        } finally {
            remove_all_filters('ll_tools_lms_deleted_user_cleanup_passes');
            remove_all_filters('ll_tools_lms_assignment_erasure_batch_size');
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId);
            if (get_userdata($userId)) {
                wpmu_delete_user($userId);
            }
        }
    }

    public function test_site_only_removal_can_finish_when_post_marker_write_transiently_fails(): void
    {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (!function_exists('wpmu_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/ms.php';
        }

        $blogId = get_current_blog_id();
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($blogId, $userId, 'subscriber');
        $optionName = ll_tools_privacy_deleted_user_lms_cleanup_option_name($userId);
        $blockedUpdates = 0;
        $blockPostMarker = static function ($value, $oldValue) use (&$blockedUpdates) {
            $blockedUpdates++;
            return $oldValue;
        };
        add_filter('pre_update_option_' . $optionName, $blockPostMarker, 10, 2);

        try {
            $this->assertTrue(wp_delete_user($userId));
            $this->assertGreaterThan(0, $blockedUpdates);
            $this->assertInstanceOf(WP_User::class, get_userdata($userId));
            $this->assertFalse(is_user_member_of_blog($userId, $blogId));
            $this->assertFalse(ll_tools_privacy_deleted_user_post_seen($userId));
            $this->assertFalse(ll_tools_privacy_user_lms_deletion_is_pending($userId));
            $this->assertFalse(wp_next_scheduled(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]));
        } finally {
            remove_filter('pre_update_option_' . $optionName, $blockPostMarker, 10);
            wp_clear_scheduled_hook(LL_TOOLS_LMS_DELETED_USER_CLEANUP_HOOK, [$userId]);
            ll_tools_privacy_dequeue_deleted_user_lms_cleanup($userId);
            if (get_userdata($userId)) {
                wpmu_delete_user($userId);
            }
        }
    }
}
