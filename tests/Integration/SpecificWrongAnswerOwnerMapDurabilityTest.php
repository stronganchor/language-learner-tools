<?php
declare(strict_types=1);

final class SpecificWrongAnswerOwnerMapDurabilityTest extends LL_Tools_TestCase
{
    private const RETRY_HOOK = 'll_tools_retry_specific_wrong_answer_owner_map_rebuild';

    /** @var array<string,array{exists:bool,value:mixed}> */
    private array $original_options = [];

    /** @var int|false */
    private $original_retry_timestamp = false;

    /** @var callable|null */
    private $batch_size_filter = null;

    /** @var callable|null */
    private $query_filter = null;

    /** @var callable|null */
    private $pre_get_posts_action = null;

    protected function setUp(): void
    {
        parent::setUp();

        $sentinel = '__ll_tools_owner_map_option_missing__' . wp_generate_uuid4();
        foreach ($this->ownerMapOptionNames() as $option_name) {
            $value = get_option($option_name, $sentinel);
            $this->original_options[$option_name] = [
                'exists' => $value !== $sentinel,
                'value' => $value,
            ];
        }

        $this->original_retry_timestamp = wp_next_scheduled(self::RETRY_HOOK);
        wp_clear_scheduled_hook(self::RETRY_HOOK);
        $this->deleteOwnerMapOptions();
    }

    protected function tearDown(): void
    {
        if ($this->batch_size_filter !== null) {
            remove_filter('ll_tools_specific_wrong_answer_owner_rebuild_batch_size', $this->batch_size_filter);
            $this->batch_size_filter = null;
        }
        if ($this->query_filter !== null) {
            remove_filter('query', $this->query_filter);
            $this->query_filter = null;
        }
        if ($this->pre_get_posts_action !== null) {
            remove_action('pre_get_posts', $this->pre_get_posts_action);
            $this->pre_get_posts_action = null;
        }

        wp_clear_scheduled_hook(self::RETRY_HOOK);
        $this->deleteOwnerMapOptions();
        foreach ($this->original_options as $option_name => $snapshot) {
            if ($snapshot['exists']) {
                update_option($option_name, $snapshot['value'], false);
            }
        }
        if ($this->original_retry_timestamp !== false) {
            wp_schedule_single_event((int) $this->original_retry_timestamp, self::RETRY_HOOK);
        }

        unset($GLOBALS['ll_tools_specific_wrong_answer_owner_map_read_complete']);
        unset($GLOBALS['ll_tools_specific_wrong_answer_owner_map_rebuild_complete']);
        parent::tearDown();
    }

    public function test_packed_generation_and_integrity_must_match_for_completeness(): void
    {
        $generation = 'matching-generation';
        $payload = ll_tools_specific_wrong_answer_owner_map_pack([
            91 => [42, 17, 42],
        ], $generation);

        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $payload, false);
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, 'v2:' . $generation, false);

        $stored = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null);
        $this->assertIsArray($stored);
        $this->assertSame(2, (int) ($stored['__ll_tools_schema'] ?? 0));
        $this->assertSame($generation, ll_tools_specific_wrong_answer_owner_map_payload_generation($stored));
        $this->assertTrue(ll_tools_specific_wrong_answer_owner_map_is_complete($stored));
        $this->assertSame([91 => [17, 42]], ll_tools_get_specific_wrong_answer_owner_map([91]));
        $this->assertTrue((bool) ($GLOBALS['ll_tools_specific_wrong_answer_owner_map_read_complete'] ?? false));
    }

    public function test_mismatched_payload_generation_fails_closed(): void
    {
        $payload = ll_tools_specific_wrong_answer_owner_map_pack([
            301 => [101, 102],
        ], 'payload-generation');
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $payload, false);
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, 'v2:different-generation', false);

        $this->assertFalse(ll_tools_specific_wrong_answer_owner_map_is_complete($payload));
        $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_map([301]));
        $this->assertFalse((bool) ($GLOBALS['ll_tools_specific_wrong_answer_owner_map_read_complete'] ?? true));
        $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_candidate_word_ids());

        $scope = ll_tools_get_specific_wrong_answer_owner_scope([301]);
        $this->assertSame([], $scope['owner_map']);
        $this->assertFalse($scope['complete']);
    }

    public function test_public_getters_do_not_write_options_or_schedule_repairs_for_missing_or_incomplete_maps(): void
    {
        $option_writes = [];
        $tracked_options = array_fill_keys($this->ownerMapOptionNames(), true);
        $capture_added = static function (string $option_name) use (&$option_writes, $tracked_options): void {
            if (isset($tracked_options[$option_name])) {
                $option_writes[] = 'added:' . $option_name;
            }
        };
        $capture_updated = static function (string $option_name) use (&$option_writes, $tracked_options): void {
            if (isset($tracked_options[$option_name])) {
                $option_writes[] = 'updated:' . $option_name;
            }
        };
        $capture_deleted = static function (string $option_name) use (&$option_writes, $tracked_options): void {
            if (isset($tracked_options[$option_name])) {
                $option_writes[] = 'deleted:' . $option_name;
            }
        };
        add_action('added_option', $capture_added, 10, 1);
        add_action('updated_option', $capture_updated, 10, 1);
        add_action('deleted_option', $capture_deleted, 10, 1);

        try {
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, false));
            $this->assertFalse(wp_next_scheduled(self::RETRY_HOOK));

            $complete = true;
            $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_map([501]));
            $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_candidate_word_ids());
            $this->assertSame([], ll_tools_get_specific_wrong_answer_only_word_lookup([501], $complete));
            $this->assertFalse($complete);
            $this->assertSame(
                ['owner_map' => [], 'complete' => false],
                ll_tools_get_specific_wrong_answer_owner_scope([501])
            );

            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, false));
            $this->assertFalse(wp_next_scheduled(self::RETRY_HOOK));
            $this->assertSame([], $option_writes);

            $legacy_payload = [501 => [201]];
            update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $legacy_payload, false);
            $option_writes = [];
            $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_map([501]));
            $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_candidate_word_ids());
            $this->assertSame(
                ['owner_map' => [], 'complete' => false],
                ll_tools_get_specific_wrong_answer_owner_scope([501])
            );

            $this->assertSame($legacy_payload, get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION, false));
            $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, false));
            $this->assertFalse(wp_next_scheduled(self::RETRY_HOOK));
            $this->assertSame([], $option_writes);
        } finally {
            remove_action('added_option', $capture_added, 10);
            remove_action('updated_option', $capture_updated, 10);
            remove_action('deleted_option', $capture_deleted, 10);
        }
    }

    public function test_public_initializer_schedules_bounded_repair_for_legacy_array_without_overwriting_it(): void
    {
        $legacy_payload = [501 => [201]];
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $legacy_payload, false);
        wp_clear_scheduled_hook(self::RETRY_HOOK);

        $this->assertFalse(ll_tools_specific_wrong_answer_owner_map_initialize_empty_if_unused());
        $this->assertSame($legacy_payload, get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION));
        $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION, false));
        $this->assertNotFalse(wp_next_scheduled(self::RETRY_HOOK));
    }

    public function test_source_meta_mutation_advances_epoch_marks_v2_generation_stale_and_schedules_worker(): void
    {
        $payload = ll_tools_specific_wrong_answer_owner_map_pack([701 => [601]], 'active-generation');
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $payload, false);
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, 'v2:active-generation', false);
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, 12, false);
        wp_clear_scheduled_hook(self::RETRY_HOOK);

        ll_tools_note_specific_wrong_answer_owner_source_mutation(
            1,
            601,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY,
            [701]
        );

        $this->assertSame(13, ll_tools_specific_wrong_answer_owner_map_source_epoch());
        $integrity = (string) get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, '');
        $this->assertMatchesRegularExpression('/^stale:13:[a-z0-9-]+$/', $integrity);
        $this->assertFalse(ll_tools_specific_wrong_answer_owner_map_is_complete(
            get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null)
        ));
        $this->assertNotFalse(wp_next_scheduled(self::RETRY_HOOK));
    }

    public function test_scheduled_retry_uses_bounded_checkpoints_then_publishes_a_complete_v2_generation(): void
    {
        [$candidate_id, $owner_ids] = $this->createOwnerFixture(26);
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, 7, false);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION);
        wp_clear_scheduled_hook(self::RETRY_HOOK);

        $this->batch_size_filter = static function (): int {
            return 25;
        };
        add_filter('ll_tools_specific_wrong_answer_owner_rebuild_batch_size', $this->batch_size_filter);

        $unbounded_rebuild_queries = 0;
        $this->pre_get_posts_action = static function (WP_Query $query) use (&$unbounded_rebuild_queries): void {
            $post_type = $query->get('post_type');
            $post_types = is_array($post_type) ? array_map('strval', $post_type) : [(string) $post_type];
            if (in_array('words', $post_types, true) && (int) $query->get('posts_per_page') === -1) {
                $unbounded_rebuild_queries++;
            }
        };
        add_action('pre_get_posts', $this->pre_get_posts_action);

        $this->assertNotFalse(has_action(self::RETRY_HOOK, 'll_tools_retry_specific_wrong_answer_owner_map_rebuild'));
        do_action(self::RETRY_HOOK);

        $state = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION, null);
        $this->assertIsArray($state);
        $this->assertSame(1, (int) ($state['schema'] ?? 0));
        $this->assertSame(7, (int) ($state['source_epoch'] ?? -1));
        $this->assertSame(max(array_slice($owner_ids, 0, 25)), (int) ($state['cursor'] ?? 0));
        $this->assertSame(array_slice($owner_ids, 0, 25), (array) ($state['map'][$candidate_id] ?? []));
        $this->assertNotContains($owner_ids[25], (array) ($state['map'][$candidate_id] ?? []));
        $this->assertFalse(ll_tools_specific_wrong_answer_owner_map_is_complete(
            get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null)
        ));
        $this->assertNotFalse(wp_next_scheduled(self::RETRY_HOOK));
        $this->assertSame(0, $unbounded_rebuild_queries);

        do_action(self::RETRY_HOOK);

        $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION, false));
        $payload = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, null);
        $this->assertIsArray($payload);
        $this->assertTrue(ll_tools_specific_wrong_answer_owner_map_is_complete($payload));
        $this->assertSame(2, (int) ($payload['__ll_tools_schema'] ?? 0));
        $this->assertMatchesRegularExpression(
            '/^v2:[a-z0-9-]+$/',
            (string) get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, '')
        );
        $this->assertSame($owner_ids, ll_tools_get_specific_wrong_answer_owner_map([$candidate_id])[$candidate_id] ?? []);
        $this->assertSame(0, $unbounded_rebuild_queries);
    }

    public function test_source_epoch_drift_discards_checkpoint_instead_of_publishing_stale_map(): void
    {
        [$candidate_id] = $this->createOwnerFixture(1);
        update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, 20, false);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION);
        delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION);
        wp_clear_scheduled_hook(self::RETRY_HOOK);

        global $wpdb;
        $drifted = false;
        $this->query_filter = static function (string $query) use (&$drifted, $wpdb): string {
            if (!$drifted
                && strpos($query, 'FROM ' . $wpdb->posts) !== false
                && strpos($query, 'ID IN (') !== false
                && strpos($query, "post_type = 'words'") !== false) {
                $drifted = true;
                update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION, 21, false);
            }
            return $query;
        };
        add_filter('query', $this->query_filter);

        ll_tools_run_specific_wrong_answer_owner_map_rebuild_batch();

        $this->assertTrue($drifted, 'The fixture must advance the source epoch after the worker snapshot was taken.');
        $this->assertSame(21, ll_tools_specific_wrong_answer_owner_map_source_epoch());
        $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, false));
        $this->assertFalse(get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, false));
        $this->assertSame([], ll_tools_get_specific_wrong_answer_owner_map([$candidate_id]));
        $this->assertNotFalse(wp_next_scheduled(self::RETRY_HOOK));
    }

    /** @return string[] */
    private function ownerMapOptionNames(): array
    {
        return [
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_REBUILD_STATE_OPTION,
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_SOURCE_EPOCH_OPTION,
        ];
    }

    private function deleteOwnerMapOptions(): void
    {
        foreach ($this->ownerMapOptionNames() as $option_name) {
            delete_option($option_name);
        }
    }

    /**
     * @return array{0:int,1:int[]}
     */
    private function createOwnerFixture(int $owner_count): array
    {
        $candidate_id = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Owner map candidate ' . wp_generate_uuid4(),
        ]);

        $source_hooks = [
            'added_post_meta',
            'updated_post_meta',
            'deleted_post_meta',
        ];
        $source_hook_priorities = [];
        foreach ($source_hooks as $hook) {
            $priority = has_action($hook, 'll_tools_note_specific_wrong_answer_owner_source_mutation');
            $source_hook_priorities[$hook] = $priority;
            if ($priority !== false) {
                remove_action($hook, 'll_tools_note_specific_wrong_answer_owner_source_mutation', (int) $priority);
            }
        }

        $owner_ids = [];
        try {
            for ($index = 0; $index < $owner_count; $index++) {
                $owner_id = (int) self::factory()->post->create([
                    'post_type' => 'words',
                    'post_status' => 'publish',
                    'post_title' => 'Owner map source ' . $index . ' ' . wp_generate_uuid4(),
                ]);
                update_post_meta($owner_id, LL_TOOLS_SPECIFIC_WRONG_ANSWERS_META_KEY, [$candidate_id]);
                $owner_ids[] = $owner_id;
            }
        } finally {
            foreach ($source_hooks as $hook) {
                $priority = $source_hook_priorities[$hook] ?? false;
                if ($priority !== false) {
                    add_action($hook, 'll_tools_note_specific_wrong_answer_owner_source_mutation', (int) $priority, 4);
                }
            }
        }

        sort($owner_ids, SORT_NUMERIC);
        return [$candidate_id, $owner_ids];
    }
}
