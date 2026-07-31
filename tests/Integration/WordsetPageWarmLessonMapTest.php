<?php
declare(strict_types=1);

final class WordsetPageWarmLessonMapTest extends LL_Tools_TestCase
{
    public function test_published_lesson_map_rotates_after_status_and_restore_lifecycle(): void
    {
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();

        $wordset = wp_insert_term(
            'Lesson lifecycle wordset ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $category = wp_insert_term(
            'Lesson lifecycle category ' . wp_generate_password(10, false, false),
            'word-category'
        );
        $this->assertNotWPError($wordset);
        $this->assertNotWPError($category);
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);

        $lesson_id = wp_insert_post([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lesson lifecycle fixture',
            'meta_input' => [
                LL_TOOLS_VOCAB_LESSON_WORDSET_META => (string) $wordset_id,
                LL_TOOLS_VOCAB_LESSON_CATEGORY_META => (string) $category_id,
            ],
        ], true);
        $this->assertIsInt($lesson_id);
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();

        $published = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $this->assertSame($lesson_id, (int) ($published['map'][$category_id] ?? 0));

        wp_update_post(['ID' => $lesson_id, 'post_status' => 'draft']);
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        $draft = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $this->assertArrayNotHasKey($category_id, (array) ($draft['map'] ?? []));

        wp_update_post(['ID' => $lesson_id, 'post_status' => 'publish']);
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        $republished = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $this->assertSame($lesson_id, (int) ($republished['map'][$category_id] ?? 0));

        $this->assertInstanceOf(WP_Post::class, wp_trash_post($lesson_id));
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        $trashed = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $this->assertArrayNotHasKey($category_id, (array) ($trashed['map'] ?? []));

        $before_untrash_epoch = ll_tools_get_wordset_cache_epoch();
        $this->assertInstanceOf(WP_Post::class, wp_untrash_post($lesson_id));
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        $this->assertGreaterThan($before_untrash_epoch, ll_tools_get_wordset_cache_epoch());
        if (get_post_status($lesson_id) !== 'publish') {
            wp_update_post(['ID' => $lesson_id, 'post_status' => 'publish']);
            ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        }
        $restored = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $this->assertSame($lesson_id, (int) ($restored['map'][$category_id] ?? 0));
    }

    public function test_lesson_invalidation_finalizer_rotates_again_and_resets_both_purge_guards(): void
    {
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        ll_tools_public_static_cache_reset_purge_once_state();
        ll_tools_cloudflare_static_cache_reset_purge_once_state();

        $before = ll_tools_get_wordset_cache_epoch();
        ll_tools_invalidate_wordset_page_lesson_cache();
        $after_immediate = ll_tools_get_wordset_cache_epoch();
        $this->assertGreaterThan($before, $after_immediate);

        $GLOBALS['ll_tools_public_static_cache_purged_keys'] = ['all' => 'early'];
        $GLOBALS['ll_tools_cloudflare_static_cache_purge_results'] = [
            'public:urls' => ['sentinel' => true],
        ];
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();

        $this->assertGreaterThan($after_immediate, ll_tools_get_wordset_cache_epoch());
        $this->assertSame(['all' => true], $GLOBALS['ll_tools_public_static_cache_purged_keys'] ?? []);
        $this->assertNotSame(
            ['public:urls' => ['sentinel' => true]],
            $GLOBALS['ll_tools_cloudflare_static_cache_purge_results'] ?? []
        );
    }

    public function test_published_lesson_map_serves_last_known_good_during_same_key_rebuild(): void
    {
        $wordset = wp_insert_term(
            'Lesson map stale wordset ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $category = wp_insert_term(
            'Lesson map stale category ' . wp_generate_password(10, false, false),
            'word-category'
        );
        $this->assertNotWPError($wordset);
        $this->assertNotWPError($category);
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);

        $lesson_id = wp_insert_post([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lesson map stale fixture',
            'meta_input' => [
                LL_TOOLS_VOCAB_LESSON_WORDSET_META => (string) $wordset_id,
                LL_TOOLS_VOCAB_LESSON_CATEGORY_META => (string) $category_id,
            ],
        ], true);
        $this->assertIsInt($lesson_id);
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();

        $initial = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $this->assertSame($lesson_id, (int) ($initial['map'][$category_id] ?? 0));

        ll_tools_invalidate_wordset_page_lesson_cache();
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();
        $cache_key = $this->currentPublishedLessonMapCacheKey($wordset_id);
        $this->assertTrue(ll_tools_wordset_page_acquire_cache_rebuild_lock($cache_key, 30));
        $waitFilterCalled = false;
        $waitFilter = static function (int $waitMs) use (&$waitFilterCalled): int {
            $waitFilterCalled = true;
            return $waitMs;
        };
        add_filter('ll_tools_wordset_page_published_lesson_map_cache_build_wait_ms', $waitFilter);

        try {
            $stale = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        } finally {
            remove_filter('ll_tools_wordset_page_published_lesson_map_cache_build_wait_ms', $waitFilter);
            ll_tools_wordset_page_release_cache_rebuild_lock($cache_key);
        }

        $this->assertFalse($waitFilterCalled, 'A last-known-good map should return without entering the contention wait.');
        $this->assertTrue((bool) ($stale['complete'] ?? false));
        $this->assertTrue((bool) ($stale['stale'] ?? false));
        $this->assertSame($lesson_id, (int) ($stale['map'][$category_id] ?? 0));
    }

    public function test_published_lesson_map_fails_closed_when_cold_build_is_already_running(): void
    {
        $wordset = wp_insert_term(
            'Lesson map cold contention ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $this->assertNotWPError($wordset);
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $cache_key = $this->currentPublishedLessonMapCacheKey($wordset_id);
        $this->assertTrue(ll_tools_wordset_page_acquire_cache_rebuild_lock($cache_key, 30));
        $noWait = static fn (): int => 0;
        add_filter('ll_tools_wordset_page_published_lesson_map_cache_build_wait_ms', $noWait);

        try {
            $result = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        } finally {
            remove_filter('ll_tools_wordset_page_published_lesson_map_cache_build_wait_ms', $noWait);
            ll_tools_wordset_page_release_cache_rebuild_lock($cache_key);
        }

        $this->assertFalse((bool) ($result['complete'] ?? true));
        $this->assertSame('building', (string) ($result['signature'] ?? ''));
        $this->assertSame([], (array) ($result['map'] ?? []));
    }

    public function test_old_generation_cannot_overwrite_last_known_good_after_dependency_drift(): void
    {
        $wordset = wp_insert_term(
            'Lesson map generation fence ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $category = wp_insert_term(
            'Lesson map generation category ' . wp_generate_password(10, false, false),
            'word-category'
        );
        $this->assertNotWPError($wordset);
        $this->assertNotWPError($category);
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $category_id = (int) ($category['term_id'] ?? 0);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);

        $lesson_id = wp_insert_post([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Lesson map generation fixture',
            'meta_input' => [
                LL_TOOLS_VOCAB_LESSON_WORDSET_META => (string) $wordset_id,
                LL_TOOLS_VOCAB_LESSON_CATEGORY_META => (string) $category_id,
            ],
        ], true);
        $this->assertIsInt($lesson_id);
        ll_tools_finalize_wordset_page_lesson_cache_invalidation();

        $last_known_key = ll_tools_wordset_page_build_cache_key('published_lesson_map_lkg', [
            'schema' => 1,
            'wordset_id' => $wordset_id,
        ]);
        $sentinel = [
            'map' => [$category_id => 987654],
            'signature' => 'newer-generation',
            'complete' => true,
        ];
        $request_cache = [];
        ll_tools_wordset_page_store_cached_payload(
            $last_known_key,
            $sentinel,
            HOUR_IN_SECONDS,
            $request_cache
        );

        if (get_option('ll_tools_wordset_cache_epoch', false) === false) {
            add_option('ll_tools_wordset_cache_epoch', 1, '', false);
        }
        ll_tools_read_option_epoch('ll_tools_wordset_cache_epoch', true);

        $drifted = false;
        $driftEpoch = static function (WP_Query $query) use (&$drifted): void {
            if ($drifted || $query->get('post_type') !== 'll_vocab_lesson') {
                return;
            }
            $drifted = true;
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options}
                 SET option_value = CAST(option_value AS UNSIGNED) + 1
                 WHERE option_name = %s",
                'll_tools_wordset_cache_epoch'
            ));
        };
        add_action('pre_get_posts', $driftEpoch);
        try {
            $result = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        } finally {
            remove_action('pre_get_posts', $driftEpoch);
        }

        $this->assertTrue($drifted);
        $this->assertTrue((bool) ($result['stale'] ?? false));
        $this->assertSame('newer-generation', (string) ($result['signature'] ?? ''));
        $readback_cache = [];
        $readback = ll_tools_wordset_page_get_cached_payload($last_known_key, $readback_cache);
        $this->assertSame($sentinel, $readback);
        ll_tools_wordset_page_delete_durable_cached_payload($last_known_key);
    }

    public function test_cache_rebuild_lock_release_cannot_delete_a_replacement_owner(): void
    {
        $cache_key = 'replacement-owner-' . wp_generate_password(12, false, false);
        $option_name = ll_tools_wordset_page_cache_rebuild_lock_option($cache_key);
        $this->assertTrue(ll_tools_wordset_page_acquire_cache_rebuild_lock($cache_key, 30));

        $replacement = 'replacement:' . (time() + 60);
        update_option($option_name, $replacement, false);
        $this->assertFalse(ll_tools_wordset_page_renew_cache_rebuild_lock($cache_key, 30));
        ll_tools_wordset_page_release_cache_rebuild_lock($cache_key);

        try {
            $this->assertSame($replacement, get_option($option_name));
        } finally {
            delete_option($option_name);
        }
    }

    public function test_published_lesson_map_rotates_with_category_identity_generation(): void
    {
        global $wpdb;

        $created = wp_insert_term(
            'Lesson identity map ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $this->assertNotWPError($created);
        $wordset_id = (int) ($created['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);

        $other_wordset = wp_insert_term(
            'Lesson identity destination ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $category = wp_insert_term(
            'Lesson identity category ' . wp_generate_password(10, false, false),
            'word-category'
        );
        $this->assertNotWPError($other_wordset);
        $this->assertNotWPError($category);
        $category_id = (int) ($category['term_id'] ?? 0);
        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);

        ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $prefix = $wpdb->esc_like('_transient_ll_wsp_published_lesson_map_') . '%';
        $before = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $prefix
        ));

        update_term_meta(
            $category_id,
            LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
            (int) ($other_wordset['term_id'] ?? 0)
        );
        ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
        $after = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
            $prefix
        ));

        $this->assertGreaterThan($before, $after);
    }

    public function test_warm_category_rows_return_before_enumerating_published_lessons(): void
    {
        global $wpdb;

        $created = wp_insert_term(
            'Warm lesson map ' . wp_generate_password(10, false, false),
            'wordset'
        );
        $this->assertNotWPError($created);
        $wordset_id = (int) ($created['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);

        $preview_limit = 2;
        $wordset_min = max(1, (int) apply_filters('ll_tools_wordset_page_min_words', 1, $wordset_id));
        $lesson_min = function_exists('ll_tools_get_vocab_lesson_min_word_count')
            ? ll_tools_get_vocab_lesson_min_word_count(null, $wordset_id)
            : $wordset_min;
        $ordering_sig = function_exists('ll_tools_wordset_get_category_ordering_cache_signature')
            ? ll_tools_wordset_get_category_ordering_cache_signature($wordset_id)
            : 'none';
        $translation_enabled = function_exists('ll_tools_is_category_translation_enabled')
            ? (ll_tools_is_category_translation_enabled([$wordset_id]) ? '1' : '0')
            : ((bool) get_option('ll_enable_category_translation', 0) ? '1' : '0');
        $translation_target = function_exists('ll_tools_get_wordset_translation_language')
            ? sanitize_key((string) ll_tools_get_wordset_translation_language([$wordset_id]))
            : sanitize_key((string) get_option('ll_translation_language', ''));
        $cache_context_sig = substr(md5(
            sanitize_key((string) get_locale()) . '|' . $translation_enabled . '|' . $translation_target
        ), 0, 8);
        $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1;
        $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1;
        $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
            ? ll_tools_get_quiz_content_cache_epoch([$wordset_id])
            : (string) $category_epoch;
        $cache_key = ll_tools_wordset_page_build_cache_key('category_rows', [
            'wordset_id' => $wordset_id,
            'min_words' => max($wordset_min, $lesson_min),
            'preview_limit' => $preview_limit,
            'ordering_sig' => $ordering_sig,
            'cache_context' => $cache_context_sig,
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
            'quiz_content_epoch' => $quiz_content_epoch,
            'lesson_sig' => $category_epoch . ':' . $wordset_epoch,
            'preview_schema' => 5,
            'inactive' => 0,
            'inactive_user_id' => 0,
        ]);
        $expected = [[
            'term_id' => 987654,
            'wordset_id' => $wordset_id,
            'word_count' => 1,
            'is_public' => true,
            'public_note' => '',
        ]];
        set_transient(
            $cache_key,
            ll_tools_wordset_page_encode_durable_cache_payload($expected),
            HOUR_IN_SECONDS
        );
        wp_cache_delete($cache_key, 'll_tools');

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            $captured_queries[] = $query;
            return $query;
        };
        add_filter('query', $capture);
        try {
            $this->assertSame(
                $expected,
                ll_tools_get_wordset_page_category_rows($wordset_id, $preview_limit, false)
            );
        } finally {
            remove_filter('query', $capture);
            ll_tools_wordset_page_delete_durable_cached_payload($cache_key);
        }

        $lesson_queries = array_values(array_filter(
            $captured_queries,
            static function (string $query) use ($wpdb): bool {
                return strpos($query, $wpdb->posts) !== false
                    && (
                        strpos($query, "post_type = 'll_vocab_lesson'") !== false
                        || strpos($query, LL_TOOLS_VOCAB_LESSON_WORDSET_META) !== false
                    );
            }
        ));
        $this->assertSame([], $lesson_queries, 'A warm row payload must be checked before the all-lesson map is queried.');
    }

    private function currentPublishedLessonMapCacheKey(int $wordset_id): string
    {
        $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1;
        $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1;
        $content_fallback_epoch = function_exists('ll_tools_get_quiz_content_fallback_epoch')
            ? ll_tools_get_quiz_content_fallback_epoch()
            : 'qcf-unavailable';

        return ll_tools_wordset_page_build_cache_key('published_lesson_map', [
            'schema' => 3,
            'wordset_id' => $wordset_id,
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
            'content_fallback_epoch' => $content_fallback_epoch,
        ]);
    }
}
