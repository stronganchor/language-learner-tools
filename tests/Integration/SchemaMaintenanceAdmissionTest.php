<?php
declare(strict_types=1);

final class SchemaMaintenanceAdmissionTest extends LL_Tools_TestCase
{
    /** @var string[] */
    private array $schemaKeys = [
        'offline_app_sessions',
        'user_progress',
        'dictionary_lookup',
        'wordset_category_search',
        'image_match_index',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        delete_transient(LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_RETRY_TRANSIENT);
        delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
        delete_transient(LL_TOOLS_DICTIONARY_LOOKUP_SCHEMA_RETRY_TRANSIENT);
        delete_transient('ll_tools_wordset_category_search_schema_retry');
        delete_transient('ll_tools_image_match_index_schema_retry');

        $this->assertTrue(ll_tools_install_offline_app_session_schema());
        $this->assertTrue(ll_tools_install_user_progress_schema());
        $this->assertTrue(ll_tools_install_dictionary_lookup_schema());
        $this->assertTrue(ll_tools_install_wordset_category_search_schema());
        $this->assertTrue(ll_tools_install_image_match_index_schema());

        foreach ($this->schemaKeys as $schemaKey) {
            wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, [$schemaKey]);
            delete_option(ll_tools_schema_maintenance_lock_option($schemaKey));
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->schemaKeys as $schemaKey) {
            wp_clear_scheduled_hook(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, [$schemaKey]);
            delete_option(ll_tools_schema_maintenance_lock_option($schemaKey));
        }

        // The test changes only durable markers, never the installed tables.
        // Restore their verified values so later integration tests inherit a
        // healthy runtime contract without repeating schema DDL here.
        update_option(
            LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION,
            LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION,
            false
        );
        update_option(
            LL_TOOLS_USER_PROGRESS_VERSION_OPTION,
            LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION,
            false
        );
        update_option(
            LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION,
            LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION,
            false
        );
        update_option(
            LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION,
            LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION,
            false
        );
        update_option(
            LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION,
            LL_TOOLS_DICTIONARY_LOOKUP_TABLE_VERSION,
            false
        );
        update_option(LL_TOOLS_DICTIONARY_LOOKUP_EXISTS_OPTION, '1', false);
        update_option(
            LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION,
            LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION,
            false
        );
        update_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_EXISTS_OPTION, '1', false);
        update_option(
            LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION,
            LL_TOOLS_IMAGE_MATCH_INDEX_VERSION,
            false
        );
        update_option(LL_TOOLS_IMAGE_MATCH_INDEX_EXISTS_OPTION, '1', false);
        ll_tools_offline_app_session_schema_ready(true);

        parent::tearDown();
    }

    public function test_public_schema_upgrade_checks_schedule_repairs_without_schema_queries(): void
    {
        delete_option(LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION);
        delete_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION);
        delete_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_LOOKUP_VERSION_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_LOOKUP_VERIFIED_VERSION_OPTION);
        delete_option(LL_TOOLS_WORDSET_CATEGORY_SEARCH_VERSION_OPTION);
        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION);

        $denyUpgrade = static function (): bool {
            return false;
        };
        $schemaQueries = [];
        $captureSchemaQueries = static function (string $query) use (&$schemaQueries): string {
            if (preg_match(
                '/\b(?:SHOW\s+(?:TABLES|TABLE\s+STATUS|COLUMNS|INDEX)|CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE)\b/i',
                $query
            ) === 1) {
                $schemaQueries[] = $query;
            }
            return $query;
        };
        add_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $denyUpgrade);
        add_filter('query', $captureSchemaQueries);

        try {
            $this->assertFalse(ll_tools_maybe_install_offline_app_session_schema());
            $this->assertFalse(ll_tools_maybe_upgrade_user_progress_schema());
            $this->assertFalse(ll_tools_maybe_upgrade_dictionary_lookup_schema());
            $this->assertFalse(ll_tools_maybe_upgrade_wordset_category_search_schema());
            $this->assertFalse(ll_tools_image_match_index_maybe_upgrade());
        } finally {
            remove_filter('query', $captureSchemaQueries);
            remove_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $denyUpgrade);
        }

        $this->assertSame([], $schemaQueries);
        foreach ($this->schemaKeys as $schemaKey) {
            $this->assertNotFalse(
                wp_next_scheduled(LL_TOOLS_SCHEMA_MAINTENANCE_HOOK, [$schemaKey]),
                "Expected a coalesced schema repair for {$schemaKey}."
            );
        }
    }

    public function test_schema_lease_takeover_and_release_are_exact_owner_fenced(): void
    {
        $schemaKey = 'user_progress';
        $optionName = ll_tools_schema_maintenance_lock_option($schemaKey);
        delete_option($optionName);

        $first = ll_tools_acquire_schema_maintenance_lease($schemaKey, MINUTE_IN_SECONDS);
        $this->assertTrue((bool) ($first['acquired'] ?? false));

        $replacement = (time() + 5 * MINUTE_IN_SECONDS) . '|replacement-owner';
        update_option($optionName, $replacement, false);
        ll_tools_release_schema_maintenance_lease($first);
        $this->assertSame($replacement, (string) get_option($optionName, ''));

        update_option($optionName, (time() - 1) . '|expired-owner', false);
        $takeover = ll_tools_acquire_schema_maintenance_lease($schemaKey, MINUTE_IN_SECONDS);
        $this->assertTrue((bool) ($takeover['acquired'] ?? false));
        $this->assertTrue((bool) ($takeover['replaced'] ?? false));
        $this->assertNotSame($replacement, (string) ($takeover['value'] ?? ''));

        ll_tools_release_schema_maintenance_lease($takeover);
        $this->assertFalse(get_option($optionName, false));
    }

    public function test_public_offline_session_fallback_schedules_repair_without_installing_inline(): void
    {
        global $wpdb;

        $hideTable = static function (string $query): string {
            if (stripos($query, 'SHOW TABLES LIKE') !== false) {
                return "SHOW TABLES LIKE 'll_tools_missing_offline_sessions'";
            }
            return $query;
        };
        add_filter('query', $hideTable);
        try {
            $this->assertFalse(ll_tools_offline_app_session_schema_ready(true));
        } finally {
            remove_filter('query', $hideTable);
        }

        $denyUpgrade = static function (): bool {
            return false;
        };
        $ddlQueries = [];
        $captureDdl = static function (string $query) use (&$ddlQueries): string {
            if (preg_match('/\b(?:CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i', $query) === 1) {
                $ddlQueries[] = $query;
            }
            return $query;
        };
        add_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $denyUpgrade);
        add_filter('query', $captureDdl);
        try {
            $userId = self::factory()->user->create();
            $this->assertSame([], ll_tools_offline_app_create_session($userId));
        } finally {
            remove_filter('query', $captureDdl);
            remove_filter('ll_tools_schema_maintenance_upgrade_is_allowed', $denyUpgrade);
        }

        $this->assertSame([], $ddlQueries);
        $this->assertNotFalse(wp_next_scheduled(
            LL_TOOLS_SCHEMA_MAINTENANCE_HOOK,
            ['offline_app_sessions']
        ));
        $this->assertSame('', (string) get_option(LL_TOOLS_OFFLINE_APP_SESSION_SCHEMA_VERSION_OPTION, ''));
        $this->assertSame('', (string) $wpdb->last_error);
    }

    public function test_unknown_schema_keys_cannot_be_scheduled_or_locked(): void
    {
        $this->assertFalse(ll_tools_schedule_schema_maintenance('not-a-schema'));
        $lease = ll_tools_acquire_schema_maintenance_lease('not-a-schema');
        $this->assertFalse((bool) ($lease['acquired'] ?? true));
        $this->assertSame('', (string) ($lease['option_name'] ?? 'unexpected'));
    }
}
