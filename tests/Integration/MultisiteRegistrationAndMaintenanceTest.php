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
}
