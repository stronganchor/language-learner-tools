<?php
declare(strict_types=1);

final class FlashcardPayloadMaterializerTest extends LL_Tools_TestCase
{
    /** @var string[] */
    private array $scopeHashes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(ll_tools_install_flashcard_payload_schema());
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach (array_unique($this->scopeHashes) as $scopeHash) {
            wp_clear_scheduled_hook(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$scopeHash]);
            delete_option(ll_tools_flashcard_payload_state_option($scopeHash));
            delete_option(ll_tools_flashcard_payload_lock_option($scopeHash));
            $wpdb->delete(
                ll_tools_flashcard_payload_table_name(),
                ['scope_hash' => $scopeHash],
                ['%s']
            );
        }
        delete_option(ll_tools_flashcard_payload_lock_option('global'));
        delete_option(LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_CURSOR_OPTION);
        delete_option(LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_FLASHCARD_PAYLOAD_CLEANUP_HOOK);
        $_POST = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    public function test_materializer_advances_one_bounded_keyset_batch_before_publishing(): void
    {
        global $wpdb;

        $fixture = $this->createTextFixture('Bounded Materializer', 25);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $batchSize = static function (): int {
            return 20;
        };
        $sourceQueries = [];
        $captureQuery = static function (string $sql) use (&$sourceQueries): string {
            if (
                strpos($sql, 'SELECT DISTINCT p.ID') !== false
                && strpos($sql, "p.post_type = 'words'") !== false
                && strpos($sql, 'category_rel') !== false
            ) {
                $sourceQueries[] = $sql;
            }
            return $sql;
        };

        add_filter('ll_tools_flashcard_payload_primary_batch_size', $batchSize);
        add_filter('query', $captureQuery);
        try {
            $firstRead = ll_tools_flashcard_payload_read_page($scope, '', 10);
        } finally {
            remove_filter('query', $captureQuery);
            remove_filter('ll_tools_flashcard_payload_primary_batch_size', $batchSize);
        }

        $this->assertWPError($firstRead);
        $this->assertSame('flashcard_payload_warming', $firstRead->get_error_code());

        $state = ll_tools_get_flashcard_payload_state($scopeHash);
        $this->assertSame('running', (string) ($state['status'] ?? ''));
        $this->assertSame('primary', (string) ($state['phase'] ?? ''));
        $this->assertSame(20, (int) ($state['processed'] ?? 0));
        $this->assertGreaterThan(0, (int) ($state['primary_cursor'] ?? 0));
        $this->assertSame(
            20,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_flashcard_payload_table_name()
                    . ' WHERE scope_hash = %s AND generation = %s',
                $scopeHash,
                (string) $state['generation']
            ))
        );

        $this->assertNotEmpty($sourceQueries);
        $this->assertMatchesRegularExpression(
            '/p\.ID\s*>\s*0.*LIMIT\s+20/is',
            implode("\n", $sourceQueries)
        );
        $this->assertStringNotContainsString(' OFFSET ', strtoupper(implode("\n", $sourceQueries)));

        $published = $this->warmScope($scope);
        $this->assertSame('completed', (string) ($published['status'] ?? ''));
        $this->assertSame(25, (int) ($published['row_count'] ?? 0));

        $allRows = [];
        $cursor = '';
        do {
            $page = ll_tools_flashcard_payload_read_page($scope, $cursor, 10);
            $this->assertIsArray($page);
            $this->assertLessThanOrEqual(10, (int) ($page['page_rows'] ?? PHP_INT_MAX));
            $allRows = array_merge($allRows, (array) ($page['rows'] ?? []));
            $cursor = (string) ($page['next_cursor'] ?? '');
        } while ($cursor !== '');

        $this->assertCount(25, $allRows);
        $this->assertSame($fixture['word_ids'], $this->rowIds($allRows));
    }

    public function test_materializer_recovers_from_a_legacy_empty_owner_map_without_waiting_for_cron(): void
    {
        $fixture = $this->createTextFixture('Legacy Empty Owner Map', 1);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];

        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, [], false);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION);
        wp_clear_scheduled_hook('ll_tools_retry_specific_wrong_answer_owner_map_rebuild');

        $this->assertFalse(ll_tools_flashcard_payload_ensure_ready($scope));

        $state = ll_tools_get_flashcard_payload_state($scopeHash);
        $this->assertSame('running', (string) ($state['status'] ?? ''));
        $this->assertSame('prompt', (string) ($state['phase'] ?? ''));
        $this->assertSame(1, (int) ($state['processed'] ?? 0));
        $this->assertSame(0, (int) ($state['retry_count'] ?? 0));
        $this->assertSame('', (string) ($state['last_error'] ?? ''));

        $ownerPayload = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null);
        $this->assertIsArray($ownerPayload);
        $this->assertSame([], ll_tools_specific_wrong_answer_owner_map_normalize($ownerPayload));
        $this->assertSame(2, (int) ($ownerPayload['__ll_tools_schema'] ?? 0));
        $this->assertNotSame(
            '',
            ll_tools_specific_wrong_answer_owner_map_payload_generation($ownerPayload)
        );
        $this->assertTrue(ll_tools_specific_wrong_answer_owner_map_is_complete($ownerPayload, true));
        $this->assertFalse(
            wp_next_scheduled('ll_tools_retry_specific_wrong_answer_owner_map_rebuild')
        );

        $published = $this->warmScope($scope);
        $this->assertSame('completed', (string) ($published['status'] ?? ''));
        $this->assertSame(1, (int) ($published['row_count'] ?? 0));
        $this->assertSame(0, (int) ($published['retry_count'] ?? 0));
        $this->assertSame('', (string) ($published['last_error'] ?? ''));

        $page = ll_tools_flashcard_payload_read_page($scope, '', 10);
        $this->assertIsArray($page);
        $this->assertCount(1, (array) ($page['rows'] ?? []));
        $this->assertSame($fixture['word_ids'], $this->rowIds((array) ($page['rows'] ?? [])));
    }

    public function test_page_cursor_rejects_tampering_and_an_old_generation_after_signature_drift(): void
    {
        $fixture = $this->createTextFixture('Cursor Materializer', 12);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $published = $this->warmScope($scope);
        $oldGeneration = (string) ($published['published_generation'] ?? '');
        $this->assertNotSame('', $oldGeneration);

        $firstPage = ll_tools_flashcard_payload_read_page($scope, '', 10);
        $this->assertIsArray($firstPage);
        $oldCursor = (string) ($firstPage['next_cursor'] ?? '');
        $this->assertNotSame('', $oldCursor);

        $lastCharacter = substr($oldCursor, -1);
        $tamperedCursor = substr($oldCursor, 0, -1) . ($lastCharacter === 'a' ? 'b' : 'a');
        $tamperedRead = ll_tools_flashcard_payload_read_page($scope, $tamperedCursor, 10);
        $this->assertWPError($tamperedRead);
        $this->assertSame('invalid_flashcard_payload_cursor', $tamperedRead->get_error_code());

        $oldSignature = ll_tools_flashcard_payload_dependency_signature($scope);
        ll_tools_bump_category_cache_epoch();
        $this->assertNotSame(
            $oldSignature,
            ll_tools_flashcard_payload_dependency_signature($scope)
        );

        $republished = $this->warmScope($scope);
        $newGeneration = (string) ($republished['published_generation'] ?? '');
        $this->assertNotSame('', $newGeneration);
        $this->assertNotSame($oldGeneration, $newGeneration);
        $this->assertSame(
            $newGeneration,
            (string) (ll_tools_get_flashcard_payload_state($scopeHash)['generation'] ?? '')
        );

        $staleRead = ll_tools_flashcard_payload_read_page($scope, $oldCursor, 10);
        $this->assertWPError($staleRead);
        $this->assertSame('stale_flashcard_payload_cursor', $staleRead->get_error_code());
    }

    public function test_public_materialized_page_ajax_redacts_speaker_identifiers(): void
    {
        global $wpdb;

        $speakerId = self::factory()->user->create(['role' => 'subscriber']);
        $fixture = $this->createAudioFixture('Speaker Materializer', 2, $speakerId);
        $published = $this->warmScope($fixture['scope']);
        $storedPayloads = $wpdb->get_col($wpdb->prepare(
            'SELECT payload FROM ' . ll_tools_flashcard_payload_table_name()
                . ' WHERE scope_hash = %s AND generation = %s',
            (string) $fixture['scope']['scope_hash'],
            (string) $published['published_generation']
        ));
        $this->assertCount(2, $storedPayloads);
        foreach ($storedPayloads as $storedPayload) {
            $storedRow = json_decode((string) $storedPayload, true);
            $this->assertIsArray($storedRow);
            $this->assertSame(0, (int) ($storedRow['preferred_speaker_user_id'] ?? -1));
            foreach ((array) ($storedRow['audio_files'] ?? []) as $audioFile) {
                $this->assertSame(0, (int) ($audioFile['speaker_user_id'] ?? -1));
            }
        }
        wp_set_current_user(0);

        $_POST = [
            'category_id' => (string) $fixture['term']->term_id,
            'category_slug' => (string) $fixture['term']->slug,
            'wordset_fallback' => '0',
            'page_size' => '10',
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_get_flashcard_payload_page_ajax();
            });
        } finally {
            $_POST = [];
            $_REQUEST = [];
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $rows = is_array($response['data']['rows'] ?? null)
            ? $response['data']['rows']
            : [];
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(0, (int) ($row['preferred_speaker_user_id'] ?? -1));
            $audioFiles = is_array($row['audio_files'] ?? null) ? $row['audio_files'] : [];
            $this->assertNotEmpty($audioFiles);
            foreach ($audioFiles as $audioFile) {
                $this->assertSame(0, (int) ($audioFile['speaker_user_id'] ?? -1));
            }
        }
    }

    public function test_expired_scope_lease_rotates_generation_before_more_writes(): void
    {
        $fixture = $this->createTextFixture('Lease Fencing', 25);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];

        $first = ll_tools_flashcard_payload_ensure_ready($scope);
        $this->assertFalse($first);
        $before = ll_tools_get_flashcard_payload_state($scopeHash);
        $oldGeneration = (string) ($before['generation'] ?? '');
        $this->assertNotSame('', $oldGeneration);

        update_option(
            ll_tools_flashcard_payload_lock_option($scopeHash),
            (time() - 5) . '|stale-owner',
            false
        );
        $after = ll_tools_flashcard_payload_process_rebuild_batch($scopeHash);

        $this->assertNotSame(
            $oldGeneration,
            (string) ($after['generation'] ?? ''),
            'An expired lease takeover must fence the former writer with a new generation.'
        );
        $this->assertSame('running', (string) ($after['status'] ?? ''));
    }

    public function test_cleanup_removes_stale_scope_rows_state_and_rebuild_event(): void
    {
        global $wpdb;

        $fixture = $this->createTextFixture('Stale Cleanup', 55);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $published = $this->warmScope($scope);
        $optionName = ll_tools_flashcard_payload_state_option($scopeHash);
        $oldTime = gmdate('Y-m-d H:i:s', time() - (40 * DAY_IN_SECONDS));
        foreach (['started_at', 'updated_at', 'last_access_at', 'completed_at'] as $field) {
            $published[$field] = $oldTime;
        }
        update_option($optionName, ll_tools_flashcard_payload_sanitize_state($published), false);
        wp_cache_delete($optionName, 'options');
        ll_tools_flashcard_payload_schedule_rebuild($scopeHash, 60);

        $this->assertGreaterThan(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_flashcard_payload_table_name()
                    . ' WHERE scope_hash = %s',
                $scopeHash
            ))
        );
        $this->assertNotFalse(
            wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$scopeHash])
        );

        $cleanupLimit = static function (): int {
            return 50;
        };
        $rowDeleteQueries = [];
        $captureDelete = static function (string $sql) use (&$rowDeleteQueries): string {
            if (
                stripos($sql, 'DELETE FROM ' . ll_tools_flashcard_payload_table_name()) !== false
            ) {
                $rowDeleteQueries[] = $sql;
            }
            return $sql;
        };
        add_filter('ll_tools_flashcard_payload_cleanup_row_limit', $cleanupLimit);
        add_filter('query', $captureDelete);
        try {
            ll_tools_flashcard_payload_cleanup_stale_scopes();

            $retiring = ll_tools_get_flashcard_payload_state($scopeHash);
            $this->assertSame('retiring', (string) ($retiring['status'] ?? ''));
            $this->assertSame('', (string) ($retiring['published_generation'] ?? ''));
            $this->assertSame(
                5,
                (int) $wpdb->get_var($wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . ll_tools_flashcard_payload_table_name()
                        . ' WHERE scope_hash = %s',
                    $scopeHash
                )),
                'A partial cleanup must keep the scope unpublished between bounded passes.'
            );
            $this->assertFalse(
                wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$scopeHash]),
                'Tombstoning must clear queued rebuilds before partial row cleanup.'
            );

            ll_tools_flashcard_payload_cleanup_stale_scopes();
        } finally {
            remove_filter('query', $captureDelete);
            remove_filter('ll_tools_flashcard_payload_cleanup_row_limit', $cleanupLimit);
        }

        $this->assertNotEmpty($rowDeleteQueries);
        foreach ($rowDeleteQueries as $deleteQuery) {
            $this->assertStringContainsString('AND generation =', $deleteQuery);
            $this->assertStringNotContainsString('generation <>', $deleteQuery);
        }
        $this->assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_flashcard_payload_table_name()
                    . ' WHERE scope_hash = %s',
                $scopeHash
            ))
        );
        $this->assertNull(get_option($optionName, null));
        $this->assertFalse(
            wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$scopeHash])
        );
    }

    public function test_scheduled_rebuild_does_not_recreate_missing_or_retiring_state(): void
    {
        $fixture = $this->createTextFixture('Scheduled Retirement', 1);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $optionName = ll_tools_flashcard_payload_state_option($scopeHash);

        delete_option($optionName);
        ll_tools_flashcard_payload_schedule_rebuild($scopeHash, 60);
        ll_tools_flashcard_payload_run_scheduled_rebuild($scopeHash);
        $this->assertNull(get_option($optionName, null));
        $this->assertFalse(
            wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$scopeHash])
        );

        $published = $this->warmScope($scope);
        $generation = (string) ($published['generation'] ?? '');
        $this->assertNotSame('', $generation);
        $published['status'] = 'retiring';
        $published['published_generation'] = '';
        update_option(
            $optionName,
            ll_tools_flashcard_payload_sanitize_state($published),
            false
        );
        wp_cache_delete($optionName, 'options');
        wp_clear_scheduled_hook(
            LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK,
            [$scopeHash]
        );
        ll_tools_flashcard_payload_schedule_rebuild($scopeHash, 60);

        ll_tools_flashcard_payload_run_scheduled_rebuild($scopeHash);

        $retiring = ll_tools_get_flashcard_payload_state($scopeHash);
        $this->assertSame('retiring', (string) ($retiring['status'] ?? ''));
        $this->assertSame($generation, (string) ($retiring['generation'] ?? ''));
        $this->assertFalse(
            wp_next_scheduled(LL_TOOLS_FLASHCARD_PAYLOAD_REBUILD_HOOK, [$scopeHash])
        );
    }

    public function test_cleanup_sweeps_an_old_generation_after_its_state_disappears(): void
    {
        global $wpdb;

        $fixture = $this->createTextFixture('Orphan Sweep', 2);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $published = $this->warmScope($scope);
        $generation = (string) ($published['generation'] ?? '');
        $this->assertNotSame('', $generation);

        $this->assertTrue(
            delete_option(ll_tools_flashcard_payload_state_option($scopeHash))
        );
        $updatedRows = $wpdb->query($wpdb->prepare(
            'UPDATE ' . ll_tools_flashcard_payload_table_name()
                . ' SET updated_at = %s'
                . ' WHERE scope_hash = %s AND generation = %s',
            gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
            $scopeHash,
            $generation
        ));
        $this->assertSame(2, $updatedRows);
        $this->assertNull($wpdb->get_var($wpdb->prepare(
            "SELECT option_id
             FROM {$wpdb->options}
             WHERE option_name = %s
             LIMIT 1",
            ll_tools_flashcard_payload_state_option($scopeHash)
        )));
        $this->assertSame(
            2,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_flashcard_payload_table_name()
                    . ' WHERE scope_hash = %s AND generation = %s',
                $scopeHash,
                $generation
            ))
        );
        update_option(
            LL_TOOLS_FLASHCARD_PAYLOAD_ORPHAN_CURSOR_OPTION,
            [
                'scope_hash' => $scopeHash,
                'generation' => str_repeat('0', 64),
                'row_kind' => 'prompt',
                'row_id' => 1,
            ],
            false
        );

        $scanQueries = [];
        $captureScan = static function (string $sql) use (&$scanQueries): string {
            if (
                stripos($sql, 'SELECT scope_hash, generation, row_kind, row_id, updated_at') !== false
                && stripos($sql, 'FROM ' . ll_tools_flashcard_payload_table_name()) !== false
            ) {
                $scanQueries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $captureScan);
        try {
            ll_tools_flashcard_payload_cleanup_stale_scopes();
        } finally {
            remove_filter('query', $captureScan);
        }

        $this->assertCount(1, $scanQueries);
        $this->assertStringNotContainsString(' JOIN ', strtoupper($scanQueries[0]));
        $this->assertStringContainsString(
            'ORDER BY scope_hash ASC, generation ASC, row_kind ASC, row_id ASC',
            $scanQueries[0]
        );
        $this->assertMatchesRegularExpression('/LIMIT\s+100\b/i', $scanQueries[0]);
        $this->assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . ll_tools_flashcard_payload_table_name()
                    . ' WHERE scope_hash = %s AND generation = %s',
                $scopeHash,
                $generation
            ))
        );
        $this->assertNull(
            get_option(ll_tools_flashcard_payload_state_option($scopeHash), null)
        );
    }

    public function test_page_rows_are_read_while_the_scope_lease_is_held(): void
    {
        $fixture = $this->createTextFixture('Reader Lease', 2);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $this->warmScope($scope);
        $observedRead = false;
        $observedLease = false;
        $captureRead = static function (string $sql) use (
            $scopeHash,
            &$observedRead,
            &$observedLease
        ): string {
            if (
                stripos($sql, 'OCTET_LENGTH(payload) AS actual_payload_bytes') !== false
                && stripos($sql, 'FROM ' . ll_tools_flashcard_payload_table_name()) !== false
            ) {
                $observedRead = true;
                $observedLease = ll_tools_flashcard_payload_lock_is_active($scopeHash);
            }
            return $sql;
        };

        add_filter('query', $captureRead);
        try {
            $page = ll_tools_flashcard_payload_read_page($scope, '', 10);
        } finally {
            remove_filter('query', $captureRead);
        }

        $this->assertIsArray($page);
        $this->assertTrue($observedRead);
        $this->assertTrue($observedLease);
        $this->assertFalse(ll_tools_flashcard_payload_lock_is_active($scopeHash));
    }

    public function test_option_row_reader_finds_an_eligible_word_after_more_than_two_hundred_raw_misses(): void
    {
        $term = $this->createCategory(
            'Late Materialized Option',
            'audio',
            'text_translation'
        );
        $targetId = $this->createWord($term, 'Early eligible target', 'Early translation');
        $this->addAudioToWord($targetId, 'Early eligible audio');

        for ($index = 1; $index <= 205; $index++) {
            $this->createWord(
                $term,
                sprintf('Raw ineligible word %03d', $index),
                sprintf('Raw ineligible translation %03d', $index)
            );
        }

        $distractorId = $this->createWord($term, 'Late eligible distractor', 'Late translation');
        $this->addAudioToWord($distractorId, 'Late eligible audio');
        ll_tools_rebuild_specific_wrong_answer_owner_map();
        $scope = $this->buildScope($term);

        $cold = ll_tools_flashcard_payload_read_option_rows($scope, [$targetId], 12);
        $this->assertWPError($cold);
        $this->assertSame('flashcard_payload_warming', $cold->get_error_code());

        $published = $this->warmScope($scope);
        $this->assertSame(2, (int) ($published['row_count'] ?? 0));

        $rows = ll_tools_flashcard_payload_read_option_rows($scope, [$targetId], 12);
        $this->assertIsArray($rows);
        $this->assertSame([$distractorId], $this->rowIds($rows));
    }

    public function test_option_row_reader_caps_output_and_excludes_every_canonical_alias_under_lease(): void
    {
        global $wpdb;

        $fixture = $this->createTextFixture('Bounded Option Reader', 18);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $published = $this->warmScope($scope);
        $generation = (string) ($published['published_generation'] ?? '');
        $targetId = (int) $fixture['word_ids'][0];
        $answerAliasId = (int) $fixture['word_ids'][1];
        $progressAliasId = (int) $fixture['word_ids'][2];

        foreach ([
            $answerAliasId => ['answer_word_id' => $targetId],
            $progressAliasId => ['progress_word_id' => $targetId],
        ] as $rowId => $changes) {
            $storedPayload = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT payload FROM ' . ll_tools_flashcard_payload_table_name()
                    . ' WHERE scope_hash = %s AND generation = %s'
                    . " AND row_kind = 'word' AND row_id = %d",
                $scopeHash,
                $generation,
                $rowId
            ));
            $decoded = json_decode($storedPayload, true);
            $this->assertIsArray($decoded);
            $decoded = array_merge($decoded, $changes);
            $updatedPayload = (string) wp_json_encode($decoded);
            $this->assertSame(
                1,
                $wpdb->update(
                    ll_tools_flashcard_payload_table_name(),
                    [
                        'payload' => $updatedPayload,
                        'payload_bytes' => strlen($updatedPayload),
                    ],
                    [
                        'scope_hash' => $scopeHash,
                        'generation' => $generation,
                        'row_kind' => 'word',
                        'row_id' => $rowId,
                    ],
                    ['%s', '%d'],
                    ['%s', '%s', '%s', '%d']
                )
            );
        }

        $metadataQueries = [];
        $observedLease = false;
        $captureRead = static function (string $sql) use (
            $scopeHash,
            &$metadataQueries,
            &$observedLease
        ): string {
            if (
                stripos($sql, 'OCTET_LENGTH(payload) AS actual_payload_bytes') !== false
                && stripos($sql, "row_kind IN ('word', 'prompt')") !== false
                && stripos($sql, 'ORDER BY sort_group ASC, row_id ASC') !== false
            ) {
                $metadataQueries[] = $sql;
                $observedLease = ll_tools_flashcard_payload_lock_is_active($scopeHash);
            }
            return $sql;
        };

        $normalizedScope = $scope;
        $normalizedScope['scope_hash'] = str_repeat('f', 64);
        add_filter('query', $captureRead);
        try {
            $rows = ll_tools_flashcard_payload_read_option_rows(
                $normalizedScope,
                [$targetId],
                99
            );
        } finally {
            remove_filter('query', $captureRead);
        }

        $this->assertIsArray($rows);
        $this->assertCount(12, $rows);
        $this->assertSame(
            array_slice($fixture['word_ids'], 3, 12),
            $this->rowIds($rows)
        );
        $this->assertCount(1, $metadataQueries);
        $this->assertMatchesRegularExpression('/LIMIT\s+48\b/i', $metadataQueries[0]);
        $this->assertStringNotContainsString(' OFFSET ', strtoupper($metadataQueries[0]));
        $this->assertTrue($observedLease);
        $this->assertFalse(ll_tools_flashcard_payload_lock_is_active($scopeHash));
    }

    public function test_option_row_reader_preserves_prompt_and_support_rows_without_duplicate_answer_identity(): void
    {
        global $wpdb;

        $term = $this->createCategory(
            'Prompt Support Options',
            'audio',
            'text_translation'
        );
        $answerId = $this->createWord($term, 'Prompt answer', 'Answer translation');
        $wrongId = $this->createWord($term, 'Prompt wrong option', 'Wrong translation');
        $this->addAudioToWord($wrongId, 'Prompt wrong option audio');
        $promptCardId = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Materialized prompt card',
        ]);
        wp_set_object_terms($promptCardId, [(int) $term->term_id], 'word-category');
        update_post_meta(
            $promptCardId,
            LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY,
            'https://example.com/materialized-prompt.mp3'
        );
        update_post_meta(
            $promptCardId,
            LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY,
            $answerId
        );
        update_post_meta(
            $promptCardId,
            LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY,
            [$wrongId]
        );
        update_post_meta(
            $promptCardId,
            LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY,
            1
        );
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $scope = $this->buildScope($term);
        $published = $this->warmScope($scope);
        $storedKinds = $wpdb->get_col($wpdb->prepare(
            'SELECT row_kind FROM ' . ll_tools_flashcard_payload_table_name()
                . ' WHERE scope_hash = %s AND generation = %s ORDER BY row_kind ASC',
            (string) $scope['scope_hash'],
            (string) ($published['published_generation'] ?? '')
        ));
        $this->assertContains('prompt', $storedKinds);
        $this->assertContains('word', $storedKinds);

        $unscopedOptions = ll_tools_flashcard_payload_read_option_rows($scope, [], 12);
        $this->assertIsArray($unscopedOptions);
        $expectedUnscopedIds = [$wrongId, $promptCardId];
        sort($expectedUnscopedIds, SORT_NUMERIC);
        $this->assertSame($expectedUnscopedIds, $this->rowIds($unscopedOptions));
        $promptRows = array_values(array_filter(
            $unscopedOptions,
            static function (array $row) use ($promptCardId): bool {
                return !empty($row['is_prompt_card'])
                    && (int) ($row['id'] ?? 0) === $promptCardId;
            }
        ));
        $this->assertCount(1, $promptRows);
        $this->assertSame($answerId, (int) ($promptRows[0]['answer_word_id'] ?? 0));
        $this->assertSame($answerId, (int) ($promptRows[0]['progress_word_id'] ?? 0));
        $this->assertContains(
            $wrongId,
            array_map('intval', (array) ($promptRows[0]['specific_wrong_answer_ids'] ?? []))
        );

        $targetScopedOptions = ll_tools_flashcard_payload_read_option_rows(
            $scope,
            [$promptCardId, $answerId],
            12
        );
        $this->assertIsArray($targetScopedOptions);
        $this->assertSame([$wrongId], $this->rowIds($targetScopedOptions));
        $wrongRow = $targetScopedOptions[0];
        $storedWrongPayload = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT payload FROM ' . ll_tools_flashcard_payload_table_name()
                . ' WHERE scope_hash = %s AND generation = %s'
                . " AND row_kind = 'word' AND row_id = %d",
            (string) $scope['scope_hash'],
            (string) ($published['published_generation'] ?? ''),
            $wrongId
        ));
        $storedWrongRow = json_decode($storedWrongPayload, true);
        $this->assertIsArray($storedWrongRow);
        $this->assertSame($storedWrongRow, $wrongRow);
    }

    public function test_option_row_reader_rejects_signature_drift_during_its_fenced_read(): void
    {
        $fixture = $this->createTextFixture('Option Reader Drift', 3);
        $scope = $fixture['scope'];
        $scopeHash = (string) $scope['scope_hash'];
        $this->warmScope($scope);
        $observedRead = false;
        $observedLease = false;
        $drifted = false;
        $captureRead = static function (string $sql) use (
            $scopeHash,
            &$observedRead,
            &$observedLease,
            &$drifted
        ): string {
            if (
                !$drifted
                && stripos($sql, 'OCTET_LENGTH(payload) AS actual_payload_bytes') !== false
                && stripos($sql, "row_kind IN ('word', 'prompt')") !== false
            ) {
                $drifted = true;
                $observedRead = true;
                $observedLease = ll_tools_flashcard_payload_lock_is_active($scopeHash);
                ll_tools_bump_category_cache_epoch();
            }
            return $sql;
        };

        add_filter('query', $captureRead);
        try {
            $result = ll_tools_flashcard_payload_read_option_rows($scope, [], 12);
        } finally {
            remove_filter('query', $captureRead);
        }

        $this->assertWPError($result);
        $this->assertSame('stale_flashcard_payload_cursor', $result->get_error_code());
        $this->assertTrue($observedRead);
        $this->assertTrue($observedLease);
        $this->assertFalse(ll_tools_flashcard_payload_lock_is_active($scopeHash));
    }

    public function test_locale_is_part_of_scope_identity_and_request_locale_is_allowlisted(): void
    {
        $fixture = $this->createTextFixture('Locale Scope', 1);
        $english = $fixture['scope'];
        $turkish = $fixture['scope'];
        $english['locale'] = 'en_US';
        $turkish['locale'] = 'tr_TR';

        $this->assertNotSame(
            ll_tools_flashcard_payload_scope_hash($english),
            ll_tools_flashcard_payload_scope_hash($turkish)
        );
        $invalid = ll_tools_flashcards_payload_page_request_locale([
            'locale' => 'zz_NotInstalled',
        ]);
        $this->assertWPError($invalid);
        $this->assertSame('invalid_flashcard_payload_locale', $invalid->get_error_code());
    }

    public function test_private_category_materializes_in_an_exact_viewer_scope(): void
    {
        $viewerId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($viewerId);
        $term = $this->createCategory(
            'Private Materializer',
            'text_title',
            'text_translation'
        );
        update_term_meta(
            (int) $term->term_id,
            LL_TOOLS_CATEGORY_VISIBILITY_META_KEY,
            'private'
        );
        update_term_meta(
            (int) $term->term_id,
            LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY,
            [$viewerId]
        );
        $this->createWord($term, 'Private materialized word', 'Private translation');
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        $scope = $this->buildScope($term);
        $this->assertSame($viewerId, (int) ($scope['viewer_user_id'] ?? 0));
        $published = $this->warmScope($scope);
        $this->assertSame('completed', (string) ($published['status'] ?? ''));
        $this->assertSame(1, (int) ($published['row_count'] ?? 0));
    }

    public function test_hidden_fallback_and_unscoped_private_wordset_fail_closed(): void
    {
        $term = $this->createCategory(
            'Private Wordset Boundary',
            'text_title',
            'text_translation'
        );
        $wordset = wp_insert_term(
            'Private Boundary Wordset ' . strtolower(wp_generate_password(6, false)),
            'wordset'
        );
        $this->assertIsArray($wordset);
        $wordsetId = (int) $wordset['term_id'];
        update_term_meta(
            $wordsetId,
            LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
            'private'
        );
        $wordId = $this->createWord(
            $term,
            'Private-only boundary word',
            'Hidden translation'
        );
        wp_set_object_terms($wordId, [$wordsetId], 'wordset');
        ll_tools_rebuild_specific_wrong_answer_owner_map();
        wp_set_current_user(0);
        $this->assertSame(
            [$wordsetId],
            array_map('intval', wp_get_object_terms($wordId, 'wordset', ['fields' => 'ids']))
        );
        $this->assertTrue(ll_tools_is_wordset_private($wordsetId));
        $unscopedComplete = true;
        $this->assertFalse(
            ll_tools_flashcard_payload_unscoped_category_is_public_safe(
                (int) $term->term_id,
                $unscopedComplete
            )
        );
        $this->assertTrue($unscopedComplete);

        $complete = true;
        $config = ll_tools_get_category_quiz_config($term, $complete);
        $this->assertTrue($complete);
        $unscoped = ll_tools_flashcard_payload_build_scope(
            $term,
            [],
            ll_tools_flashcard_payload_normalize_config($config)
        );
        $this->assertWPError($unscoped);
        $this->assertSame(
            'flashcard_payload_wordset_required',
            $unscoped->get_error_code()
        );

        $supportComplete = true;
        $this->assertFalse(
            ll_tools_flashcard_payload_support_words_are_accessible(
                [$wordId],
                0,
                $supportComplete
            )
        );
        $this->assertTrue($supportComplete);

        $privateSupportCategory = $this->createCategory(
            'Private Support Boundary',
            'text_title',
            'text_translation'
        );
        update_term_meta(
            (int) $privateSupportCategory->term_id,
            LL_TOOLS_CATEGORY_VISIBILITY_META_KEY,
            'private'
        );
        $privateSupportWordId = $this->createWord(
            $privateSupportCategory,
            'Private category support word',
            'Private category translation'
        );
        $privateCategoryComplete = true;
        $this->assertFalse(
            ll_tools_flashcard_payload_support_words_are_accessible(
                [$privateSupportWordId],
                0,
                $privateCategoryComplete
            )
        );
        $this->assertTrue($privateCategoryComplete);

        $previousDefault = get_option('ll_default_wordset_id', null);
        update_option('ll_default_wordset_id', $wordsetId, false);
        try {
            $resolutionComplete = true;
            $requestedIds = [];
            $visibleIds = ll_flashcards_resolve_wordset_ids(
                '',
                true,
                $resolutionComplete,
                $requestedIds
            );
            $this->assertTrue($resolutionComplete);
            $this->assertSame([$wordsetId], $requestedIds);
            $this->assertSame([], $visibleIds);

            $resolved = ll_tools_flashcards_resolve_payload_page_scope([
                'category_id' => (string) $term->term_id,
                'wordset_fallback' => '1',
            ]);
            $this->assertWPError($resolved);
            $this->assertSame('invalid_wordset', $resolved->get_error_code());
        } finally {
            if ($previousDefault === null) {
                delete_option('ll_default_wordset_id');
            } else {
                update_option('ll_default_wordset_id', $previousDefault, false);
            }
        }
    }

    public function test_request_mode_overrides_are_canonical_scope_inputs(): void
    {
        $fixture = $this->createTextFixture('Mode Override', 1);
        $resolved = ll_tools_flashcards_resolve_payload_page_scope([
            'category_id' => (string) $fixture['term']->term_id,
            'wordset_fallback' => '0',
            'prompt_type' => 'text_translation',
            'option_type' => 'text_title',
            'display_mode' => 'image',
        ]);

        $this->assertIsArray($resolved);
        $this->assertSame(
            'text_translation',
            (string) ($resolved['config']['prompt_type'] ?? '')
        );
        $this->assertSame(
            'text_title',
            (string) ($resolved['config']['option_type'] ?? '')
        );
        $this->assertSame(
            'text_translation',
            (string) ($resolved['scope']['config']['prompt_type'] ?? '')
        );
        $this->scopeHashes[] = (string) $resolved['scope']['scope_hash'];
    }

    public function test_persisted_rows_hide_cross_scope_taxonomy_metadata(): void
    {
        $term = $this->createCategory(
            'Public Scope Metadata',
            'text_title',
            'text_translation'
        );
        $privateCategory = $this->createCategory(
            'Private Cross Link',
            'text_title',
            'text_translation'
        );
        update_term_meta(
            (int) $privateCategory->term_id,
            LL_TOOLS_CATEGORY_VISIBILITY_META_KEY,
            'private'
        );
        $publicWordset = wp_insert_term(
            'Public Scope Wordset ' . strtolower(wp_generate_password(6, false)),
            'wordset'
        );
        $privateWordset = wp_insert_term(
            'Private Cross Wordset ' . strtolower(wp_generate_password(6, false)),
            'wordset'
        );
        $this->assertIsArray($publicWordset);
        $this->assertIsArray($privateWordset);
        $publicWordsetId = (int) $publicWordset['term_id'];
        $privateWordsetId = (int) $privateWordset['term_id'];
        update_term_meta(
            $privateWordsetId,
            LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
            'private'
        );
        $wordId = $this->createWord($term, 'Cross-linked word', 'Public translation');
        wp_set_object_terms(
            $wordId,
            [(int) $term->term_id, (int) $privateCategory->term_id],
            'word-category'
        );
        wp_set_object_terms(
            $wordId,
            [$publicWordsetId, $privateWordsetId],
            'wordset'
        );
        ll_tools_rebuild_specific_wrong_answer_owner_map();
        wp_set_current_user(0);
        $this->assertEqualsCanonicalizing(
            [$publicWordsetId, $privateWordsetId],
            array_map('intval', wp_get_object_terms($wordId, 'wordset', ['fields' => 'ids']))
        );

        $scope = $this->buildScope($term, [$publicWordsetId]);
        $this->warmScope($scope);
        $page = ll_tools_flashcard_payload_read_page($scope, '', 10);
        $this->assertIsArray($page);
        $this->assertCount(1, (array) ($page['rows'] ?? []));
        $row = $page['rows'][0];
        $this->assertSame([(string) $term->name], (array) ($row['all_categories'] ?? []));
        $this->assertSame([$publicWordsetId], (array) ($row['wordset_ids'] ?? []));
    }

    public function test_page_rejects_payload_byte_metadata_drift(): void
    {
        global $wpdb;

        $fixture = $this->createTextFixture('Byte Drift', 1);
        $published = $this->warmScope($fixture['scope']);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . ll_tools_flashcard_payload_table_name()
                . ' SET payload_bytes = payload_bytes + 1'
                . ' WHERE scope_hash = %s AND generation = %s LIMIT 1',
            (string) $fixture['scope']['scope_hash'],
            (string) $published['published_generation']
        ));

        $page = ll_tools_flashcard_payload_read_page($fixture['scope'], '', 10);
        $this->assertWPError($page);
        $this->assertSame('flashcard_payload_corrupt_row', $page->get_error_code());
    }

    /**
     * @return array{term:WP_Term,scope:array<string,mixed>,word_ids:int[]}
     */
    private function createTextFixture(string $label, int $wordCount): array
    {
        $term = $this->createCategory($label, 'text_title', 'text_translation');
        $wordIds = [];
        for ($index = 1; $index <= $wordCount; $index++) {
            $wordIds[] = $this->createWord(
                $term,
                sprintf('%s Word %02d', $label, $index),
                sprintf('%s Translation %02d', $label, $index)
            );
        }
        sort($wordIds, SORT_NUMERIC);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        return [
            'term' => $term,
            'scope' => $this->buildScope($term),
            'word_ids' => $wordIds,
        ];
    }

    /**
     * @return array{term:WP_Term,scope:array<string,mixed>,word_ids:int[]}
     */
    private function createAudioFixture(
        string $label,
        int $wordCount,
        int $speakerId
    ): array {
        $term = $this->createCategory($label, 'audio', 'text_translation');
        $recordingType = term_exists('isolation', 'recording_type');
        if (!$recordingType) {
            $recordingType = wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
        }
        $this->assertFalse(is_wp_error($recordingType));

        $wordIds = [];
        for ($index = 1; $index <= $wordCount; $index++) {
            $wordId = $this->createWord(
                $term,
                sprintf('%s Word %02d', $label, $index),
                sprintf('%s Translation %02d', $label, $index)
            );
            $audioId = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_title' => sprintf('%s Audio %02d', $label, $index),
                'post_parent' => $wordId,
            ]);
            update_post_meta(
                $audioId,
                'audio_file_path',
                sprintf('/wp-content/uploads/materializer-audio-%d.mp3', $audioId)
            );
            update_post_meta($audioId, 'speaker_user_id', $speakerId);
            wp_set_object_terms($audioId, ['isolation'], 'recording_type');
            $wordIds[] = $wordId;
        }
        sort($wordIds, SORT_NUMERIC);
        ll_tools_rebuild_specific_wrong_answer_owner_map();

        return [
            'term' => $term,
            'scope' => $this->buildScope($term),
            'word_ids' => $wordIds,
        ];
    }

    private function createCategory(
        string $label,
        string $promptType,
        string $optionType
    ): WP_Term {
        $suffix = strtolower(wp_generate_password(8, false));
        $created = wp_insert_term(
            $label . ' ' . $suffix,
            'word-category',
            ['slug' => sanitize_title($label . '-' . $suffix)]
        );
        $this->assertIsArray($created);
        $termId = (int) $created['term_id'];
        update_term_meta($termId, 'll_quiz_prompt_type', $promptType);
        update_term_meta($termId, 'll_quiz_option_type', $optionType);
        $term = get_term($termId, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        return $term;
    }

    private function createWord(WP_Term $term, string $title, string $translation): int
    {
        $wordId = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_object_terms($wordId, [(int) $term->term_id], 'word-category');
        update_post_meta($wordId, 'word_translation', $translation);

        return (int) $wordId;
    }

    private function addAudioToWord(int $wordId, string $title): int
    {
        $audioId = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_parent' => $wordId,
        ]);
        update_post_meta(
            $audioId,
            'audio_file_path',
            sprintf('/wp-content/uploads/materializer-option-%d.mp3', $audioId)
        );

        return (int) $audioId;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildScope(WP_Term $term, array $wordsetIds = []): array
    {
        $complete = false;
        $config = ll_tools_get_category_quiz_config($term, $complete);
        $this->assertTrue($complete);
        $scope = ll_tools_flashcard_payload_build_scope(
            $term,
            $wordsetIds,
            ll_tools_flashcard_payload_normalize_config($config)
        );
        $this->assertIsArray($scope);
        $this->scopeHashes[] = (string) $scope['scope_hash'];

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function warmScope(array $scope): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $ready = ll_tools_flashcard_payload_ensure_ready($scope);
            if (is_wp_error($ready)) {
                $this->fail(
                    'Materializer failed: ' . $ready->get_error_code()
                        . ' ' . $ready->get_error_message()
                );
            }
            if ($ready === true) {
                return ll_tools_get_flashcard_payload_state(
                    (string) $scope['scope_hash']
                );
            }
        }

        $this->fail('Materializer did not publish within 20 bounded batches.');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return int[]
     */
    private function rowIds(array $rows): array
    {
        $ids = array_values(array_filter(array_map(
            static function (array $row): int {
                return (int) ($row['id'] ?? 0);
            },
            $rows
        )));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $dieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $error) {
            $this->assertSame('wp_die', $error->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $dieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
