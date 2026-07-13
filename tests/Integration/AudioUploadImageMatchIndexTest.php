<?php
declare(strict_types=1);

final class AudioUploadImageMatchIndexTest extends LL_Tools_TestCase
{
    private $originalState;
    private $originalVersion;
    private $originalIsolation;
    private $inlineBatchFilter;

    protected function setUp(): void
    {
        parent::setUp();
        ll_tools_install_image_match_index_schema();
        $this->originalState = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION, null);
        $this->originalVersion = get_option(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, null);
        $this->originalIsolation = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        $this->inlineBatchFilter = static function (): int {
            return 0;
        };
        add_filter('ll_tools_image_match_index_inline_batch_size', $this->inlineBatchFilter);
    }

    protected function tearDown(): void
    {
        remove_filter('ll_tools_image_match_index_inline_batch_size', $this->inlineBatchFilter);
        delete_option(LL_TOOLS_IMAGE_MATCH_INDEX_LOCK_OPTION);
        wp_clear_scheduled_hook(LL_TOOLS_IMAGE_MATCH_INDEX_HOOK);
        $this->restoreOption(LL_TOOLS_IMAGE_MATCH_INDEX_STATE_OPTION, $this->originalState);
        $this->restoreOption(LL_TOOLS_IMAGE_MATCH_INDEX_VERSION_OPTION, $this->originalVersion);
        $this->restoreOption(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolation);
        parent::tearDown();
    }

    public function test_typo_only_match_uses_a_bounded_index_candidate_query(): void
    {
        $category_id = $this->ensureTerm('word-category', 'Indexed Mountains', 'indexed-mountains');
        for ($index = 0; $index < 80; $index++) {
            $this->createImage('Distractor ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT), $category_id);
        }
        $target_id = $this->createImage('Mountain', $category_id);

        $candidateQueries = [];
        $queryFilter = static function (string $sql) use (&$candidateQueries): string {
            if (strpos($sql, 'll_tools_image_match_candidates') !== false) {
                $candidateQueries[] = $sql;
            }
            return $sql;
        };
        $candidateLimitFilter = static function (): int {
            return 7;
        };
        $candidatePostLimits = [];
        $postQueryObserver = static function (WP_Query $query) use (&$candidatePostLimits): void {
            if ($query->get('post_type') === 'word_images' && !empty($query->get('post__in'))) {
                $candidatePostLimits[] = (int) $query->get('posts_per_page');
            }
        };

        add_filter('query', $queryFilter);
        add_filter('ll_tools_image_match_index_candidate_limit', $candidateLimitFilter);
        add_action('pre_get_posts', $postQueryObserver);
        try {
            $match = ll_find_matching_image_conservative('Moutain', [$category_id]);
        } finally {
            remove_filter('query', $queryFilter);
            remove_filter('ll_tools_image_match_index_candidate_limit', $candidateLimitFilter);
            remove_action('pre_get_posts', $postQueryObserver);
        }

        $this->assertInstanceOf(WP_Post::class, $match);
        $this->assertSame($target_id, (int) $match->ID);
        $this->assertCount(1, $candidateQueries);
        $this->assertStringContainsString('LIMIT 7', $candidateQueries[0]);
        $this->assertNotEmpty($candidatePostLimits);
        $this->assertLessThanOrEqual(7, max($candidatePostLimits));
        $this->assertNotContains(-1, $candidatePostLimits);
    }

    public function test_candidate_query_applies_wordset_owner_scope_before_the_limit(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $category_id = $this->ensureTerm('word-category', 'Indexed Owner Scope', 'indexed-owner-scope');
        $wordset_one_id = $this->ensureTerm('wordset', 'Indexed Owner One', 'indexed-owner-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Indexed Owner Two', 'indexed-owner-two');

        $first_id = $this->createImage('Owner Mountain', $category_id);
        $second_id = $this->createImage('Owner Mountain', $category_id);
        update_post_meta($first_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_one_id);
        update_post_meta($second_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_two_id);

        $candidate_ids = ll_tools_image_match_index_candidate_ids('Owner Mountain', [$category_id], [$wordset_two_id]);
        $this->assertSame([$second_id], $candidate_ids);
        $match = ll_find_matching_image_conservative('Owner Mountain', [$category_id], [$wordset_two_id]);

        $this->assertInstanceOf(WP_Post::class, $match);
        $this->assertSame($second_id, (int) $match->ID);
    }

    public function test_rebuild_advances_by_a_bounded_id_cursor(): void
    {
        $category_id = $this->ensureTerm('word-category', 'Indexed Rebuild', 'indexed-rebuild');
        $image_ids = [];
        for ($index = 0; $index < 12; $index++) {
            $image_ids[] = $this->createImage('Rebuild Image ' . $index, $category_id);
        }
        sort($image_ids, SORT_NUMERIC);
        foreach ($image_ids as $image_id) {
            ll_tools_image_match_index_delete_post_rows($image_id);
            delete_post_meta($image_id, LL_TOOLS_IMAGE_MATCH_INDEX_HASH_META_KEY);
        }
        ll_tools_image_match_index_update_state([
            'status' => 'pending',
            'last_id' => $image_ids[0] - 1,
            'processed' => 0,
            'started_at' => '',
            'completed_at' => '',
        ]);

        $rebuildQueries = [];
        $queryFilter = static function (string $sql) use (&$rebuildQueries): string {
            if (strpos($sql, 'll_tools_image_match_rebuild') !== false) {
                $rebuildQueries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $queryFilter);
        try {
            $state = ll_tools_image_match_index_process_rebuild_batch(5);
        } finally {
            remove_filter('query', $queryFilter);
        }

        $this->assertSame(5, (int) $state['batch']);
        $this->assertSame(5, (int) $state['processed']);
        $this->assertSame($image_ids[4], (int) $state['last_id']);
        $this->assertSame('running', (string) $state['status']);
        $this->assertCount(1, $rebuildQueries);
        $this->assertStringContainsString('LIMIT 6', $rebuildQueries[0]);
        $this->assertGreaterThan(0, $this->indexRowCount($image_ids[4]));
        $this->assertSame(0, $this->indexRowCount($image_ids[5]));
    }

    private function createImage(string $title, int $category_id): int
    {
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_object_terms($image_id, [$category_id], 'word-category', false);
        return (int) $image_id;
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }
        if (is_int($existing) && $existing > 0) {
            return $existing;
        }
        $inserted = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertIsArray($inserted);
        return (int) $inserted['term_id'];
    }

    private function restoreOption(string $name, $value): void
    {
        if ($value === null) {
            delete_option($name);
            return;
        }
        update_option($name, $value, false);
    }

    private function indexRowCount(int $image_id): int
    {
        global $wpdb;
        $table = ll_tools_image_match_index_table_name();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE image_id = %d", $image_id));
    }
}
