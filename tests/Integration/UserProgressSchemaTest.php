<?php
declare(strict_types=1);

final class UserProgressSchemaTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
        $this->assertTrue(ll_tools_install_user_progress_schema());
    }

    protected function tearDown(): void
    {
        delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
        ll_tools_install_user_progress_schema();
        parent::tearDown();
    }

    public function test_user_progress_schema_contract_is_ready_after_install(): void
    {
        $this->assertTrue(ll_tools_user_progress_schema_is_ready());
        $this->assertSame(
            LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION,
            (string) get_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, '')
        );
        $this->assertSame(
            LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION,
            (string) get_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION, '')
        );
    }

    public function test_user_progress_schema_version_is_not_published_when_verification_fails(): void
    {
        $force_missing = static function (): bool {
            return false;
        };

        delete_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION);
        delete_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION);
        add_filter('ll_tools_user_progress_schema_exists_after_install', $force_missing);
        try {
            $this->assertFalse(ll_tools_install_user_progress_schema());
            $this->assertSame('', (string) get_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, ''));
            $this->assertSame('', (string) get_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION, ''));
            $this->assertNotFalse(get_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT));
        } finally {
            remove_filter('ll_tools_user_progress_schema_exists_after_install', $force_missing);
            delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
        }

        $this->assertTrue(ll_tools_install_user_progress_schema());
    }

    public function test_user_progress_schema_requires_and_repairs_wordset_user_index(): void
    {
        global $wpdb;

        $words_table = ll_tools_user_progress_table_names()['words'];
        $wpdb->query("ALTER TABLE {$words_table} DROP INDEX idx_wordset_user");

        try {
            $this->assertFalse(ll_tools_user_progress_schema_is_ready());
            delete_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION);

            $this->assertTrue(ll_tools_install_user_progress_schema());
            $this->assertTrue(ll_tools_user_progress_schema_is_ready());
            $this->assertSame(
                LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION,
                (string) get_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION, '')
            );
        } finally {
            if (!ll_tools_user_progress_schema_is_ready()) {
                delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
                ll_tools_install_user_progress_schema();
            }
        }
    }

    public function test_user_progress_schema_requires_and_repairs_critical_event_column_shapes(): void
    {
        global $wpdb;

        $events_table = ll_tools_user_progress_table_names()['events'];
        $this->assertNotFalse($wpdb->query(
            "ALTER TABLE {$events_table} MODIFY id bigint(20) unsigned NOT NULL"
        ));
        $this->assertNotFalse($wpdb->query(
            "ALTER TABLE {$events_table} MODIFY event_uuid varchar(100) NOT NULL"
        ));

        try {
            $this->assertFalse(ll_tools_user_progress_schema_is_ready());
            delete_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION);
            delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);

            $this->assertTrue(ll_tools_install_user_progress_schema());
            $this->assertTrue(ll_tools_user_progress_schema_is_ready());
            $this->assertSame(
                LL_TOOLS_USER_PROGRESS_SCHEMA_VERSION,
                (string) get_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION, '')
            );

            $columns = [];
            foreach ((array) $wpdb->get_results("SHOW COLUMNS FROM {$events_table}", ARRAY_A) as $column) {
                $columns[(string) ($column['Field'] ?? '')] = $column;
            }
            $this->assertMatchesRegularExpression(
                '/^bigint(?:\(\d+\))? unsigned$/',
                strtolower((string) ($columns['id']['Type'] ?? ''))
            );
            $this->assertStringContainsString('auto_increment', strtolower((string) ($columns['id']['Extra'] ?? '')));
            $this->assertSame('varchar(64)', strtolower((string) ($columns['event_uuid']['Type'] ?? '')));
            $this->assertSame('NO', strtoupper((string) ($columns['event_uuid']['Null'] ?? '')));
        } finally {
            if (!ll_tools_user_progress_schema_is_ready()) {
                delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
                ll_tools_install_user_progress_schema();
            }
        }
    }

    public function test_user_progress_schema_rejects_and_repairs_prefixed_event_uuid_index(): void
    {
        global $wpdb;

        $events_table = ll_tools_user_progress_table_names()['events'];
        $this->assertNotFalse($wpdb->query(
            "ALTER TABLE {$events_table} DROP INDEX uniq_event_uuid, "
            . 'ADD UNIQUE KEY uniq_event_uuid (event_uuid(63))'
        ));

        try {
            $prefixed_index = $wpdb->get_row(
                "SHOW INDEX FROM {$events_table} WHERE Key_name = 'uniq_event_uuid'",
                ARRAY_A
            );
            $this->assertIsArray($prefixed_index);
            $this->assertSame(63, (int) ($prefixed_index['Sub_part'] ?? 0));
            $this->assertFalse(ll_tools_user_progress_schema_is_ready());

            delete_option(LL_TOOLS_USER_PROGRESS_VERSION_OPTION);
            delete_option(LL_TOOLS_USER_PROGRESS_VERIFIED_VERSION_OPTION);
            delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);

            $this->assertTrue(ll_tools_install_user_progress_schema());
            $this->assertTrue(ll_tools_user_progress_schema_is_ready());

            $repaired_index = $wpdb->get_row(
                "SHOW INDEX FROM {$events_table} WHERE Key_name = 'uniq_event_uuid'",
                ARRAY_A
            );
            $this->assertIsArray($repaired_index);
            $this->assertNull($repaired_index['Sub_part'] ?? null);
            $this->assertSame(0, (int) ($repaired_index['Non_unique'] ?? 1));
            $this->assertSame('event_uuid', (string) ($repaired_index['Column_name'] ?? ''));
        } finally {
            if (!ll_tools_user_progress_schema_is_ready()) {
                $wpdb->query("ALTER TABLE {$events_table} DROP INDEX uniq_event_uuid");
                $wpdb->query("ALTER TABLE {$events_table} ADD UNIQUE KEY uniq_event_uuid (event_uuid)");
                delete_transient(LL_TOOLS_USER_PROGRESS_SCHEMA_RETRY_TRANSIENT);
                ll_tools_install_user_progress_schema();
            }
        }
    }
}
