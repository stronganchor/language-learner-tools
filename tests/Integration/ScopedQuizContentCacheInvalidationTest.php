<?php
declare(strict_types=1);

final class ScopedQuizContentCacheInvalidationTest extends LL_Tools_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_rebuild_specific_wrong_answer_owner_map')) {
            ll_tools_rebuild_specific_wrong_answer_owner_map();
        }
        if (function_exists('ll_tools_public_static_cache_reset_purge_once_state')) {
            ll_tools_public_static_cache_reset_purge_once_state();
        }
    }

    protected function tearDown(): void
    {
        if (function_exists('ll_tools_public_static_cache_reset_purge_once_state')) {
            ll_tools_public_static_cache_reset_purge_once_state();
        }
        parent::tearDown();
    }

    public function test_category_payload_bump_advances_only_its_wordset_content_epoch(): void
    {
        [$wordset_a, $category_a] = $this->createOwnedCategoryFixture('Scoped A');
        [$wordset_b, $category_b] = $this->createOwnedCategoryFixture('Scoped B');

        $broad_before = ll_tools_get_category_cache_epoch();
        $global_before = ll_tools_get_quiz_content_global_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_before = ll_tools_get_wordset_quiz_content_epoch($wordset_b);
        $category_a_before = ll_tools_get_category_cache_version($category_a);
        $category_b_before = ll_tools_get_category_cache_version($category_b);
        $unscoped_signature_before = ll_tools_get_quiz_content_cache_epoch();
        $wordset_b_signature_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);

        ll_tools_public_static_cache_reset_purge_once_state();
        ll_tools_bump_category_cache_version([$category_a], [$wordset_a], true);

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($global_before + 1, ll_tools_get_quiz_content_global_epoch());
        $this->assertSame($unknown_before, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame($wordset_a_before + 1, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertSame($wordset_b_before, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertSame($category_a_before + 1, ll_tools_get_category_cache_version($category_a));
        $this->assertSame($category_b_before, ll_tools_get_category_cache_version($category_b));
        $this->assertNotSame($unscoped_signature_before, ll_tools_get_quiz_content_cache_epoch());
        $this->assertSame($wordset_b_signature_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
        $this->assertPublicPurgeTargets([$wordset_a]);
    }

    public function test_cold_epoch_reads_do_not_persist_options_or_term_meta(): void
    {
        $wordset_id = $this->createTerm('Read Only Epoch', 'wordset');
        $category_id = $this->createTerm('Read Only Category Version', 'word-category');
        delete_option('ll_tools_quiz_content_cache_epoch');
        delete_option('ll_tools_quiz_content_unknown_epoch');
        delete_option('ll_tools_quiz_content_failsafe_epoch');
        delete_term_meta($wordset_id, '_ll_quiz_content_epoch');
        delete_term_meta($category_id, '_ll_wc_cache_version');

        $this->assertFalse(get_option('ll_tools_quiz_content_cache_epoch', false));
        $this->assertFalse(get_option('ll_tools_quiz_content_unknown_epoch', false));
        $this->assertFalse(get_option('ll_tools_quiz_content_failsafe_epoch', false));
        $this->assertFalse(metadata_exists('term', $wordset_id, '_ll_quiz_content_epoch'));
        $this->assertFalse(metadata_exists('term', $category_id, '_ll_wc_cache_version'));

        $this->assertSame(1, ll_tools_get_quiz_content_global_epoch());
        $this->assertSame(1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame(1, ll_tools_get_wordset_quiz_content_epoch($wordset_id));
        $this->assertSame('qce2:f1;a1', ll_tools_get_quiz_content_cache_epoch());
        $this->assertSame('qce2:f1;u1;w' . $wordset_id . ':1', ll_tools_get_quiz_content_cache_epoch([$wordset_id]));
        $this->assertNotSame('', ll_tools_flashcards_public_ajax_cache_key(
            $this->flashcardAjaxCacheArgs($category_id, $wordset_id)
        ));

        $this->assertFalse(get_option('ll_tools_quiz_content_cache_epoch', false));
        $this->assertFalse(get_option('ll_tools_quiz_content_unknown_epoch', false));
        $this->assertFalse(get_option('ll_tools_quiz_content_failsafe_epoch', false));
        $this->assertFalse(metadata_exists('term', $wordset_id, '_ll_quiz_content_epoch'));
        $this->assertFalse(metadata_exists('term', $category_id, '_ll_wc_cache_version'));
    }

    public function test_prompt_card_wordset_move_invalidates_old_owner_and_new_direct_scope(): void
    {
        [$wordset_a, $category_a] = $this->createOwnedCategoryFixture('Prompt Move A');
        $wordset_b = $this->createTerm('Prompt Move B', 'wordset');
        $wordset_c = $this->createTerm('Prompt Move C', 'wordset');
        $prompt_card_id = self::factory()->post->create([
            'post_type' => LL_TOOLS_PROMPT_CARD_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Scoped prompt move',
        ]);
        wp_set_object_terms($prompt_card_id, [$category_a], 'word-category', false);
        wp_set_object_terms($prompt_card_id, [$wordset_a], 'wordset', false);

        $epoch_a = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $epoch_b = ll_tools_get_wordset_quiz_content_epoch($wordset_b);
        $epoch_c = ll_tools_get_wordset_quiz_content_epoch($wordset_c);
        $unknown = ll_tools_get_quiz_content_unknown_epoch();

        wp_set_object_terms($prompt_card_id, [$wordset_b], 'wordset', false);

        $this->assertGreaterThan($epoch_a, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertGreaterThan($epoch_b, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertSame($epoch_c, ll_tools_get_wordset_quiz_content_epoch($wordset_c));
        $this->assertSame($unknown, ll_tools_get_quiz_content_unknown_epoch());
    }

    public function test_atomic_epoch_bumps_advance_past_stale_request_cached_values(): void
    {
        global $wpdb;

        [$wordset_id, $category_id] = $this->createOwnedCategoryFixture('Atomic Epoch');
        update_option('ll_tools_quiz_content_cache_epoch', 10, false);
        update_option('ll_tools_quiz_content_unknown_epoch', 30, false);
        update_option('ll_tools_wordset_cache_epoch', 20, false);
        update_term_meta($wordset_id, '_ll_quiz_content_epoch', 10);
        update_term_meta($category_id, '_ll_wc_cache_version', 10);

        $this->assertSame(10, ll_tools_get_quiz_content_global_epoch());
        $this->assertSame(30, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame(20, ll_tools_get_wordset_cache_epoch());
        $this->assertSame(10, ll_tools_get_wordset_quiz_content_epoch($wordset_id));
        $this->assertSame(10, ll_tools_get_category_cache_version($category_id));

        $wpdb->update($wpdb->options, ['option_value' => '11'], ['option_name' => 'll_tools_quiz_content_cache_epoch']);
        $wpdb->update($wpdb->options, ['option_value' => '31'], ['option_name' => 'll_tools_quiz_content_unknown_epoch']);
        $wpdb->update($wpdb->options, ['option_value' => '21'], ['option_name' => 'll_tools_wordset_cache_epoch']);
        $wpdb->update($wpdb->termmeta, ['meta_value' => '11'], [
            'term_id' => $wordset_id,
            'meta_key' => '_ll_quiz_content_epoch',
        ]);
        $wpdb->update($wpdb->termmeta, ['meta_value' => '11'], [
            'term_id' => $category_id,
            'meta_key' => '_ll_wc_cache_version',
        ]);

        ll_tools_bump_category_cache_version([$category_id], [$wordset_id], true);
        ll_tools_bump_quiz_content_cache_epoch([], false);
        ll_tools_bump_wordset_cache_epoch();

        $this->assertSame(13, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'll_tools_quiz_content_cache_epoch'
        )));
        $this->assertSame(32, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'll_tools_quiz_content_unknown_epoch'
        )));
        $this->assertSame(22, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'll_tools_wordset_cache_epoch'
        )));
        $this->assertSame(12, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s",
            $wordset_id,
            '_ll_quiz_content_epoch'
        )));
        $this->assertSame(12, (int) $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s",
            $category_id,
            '_ll_wc_cache_version'
        )));
    }

    public function test_word_image_owner_move_uses_unknown_scope_fail_safe_for_old_owner(): void
    {
        $wordset_a = $this->createTerm('Image Owner A', 'wordset');
        [$wordset_b, $category_b] = $this->createOwnedCategoryFixture('Image Owner B');
        $wordset_c = $this->createTerm('Image Owner C', 'wordset');
        $image_id = self::factory()->post->create([
            'post_type' => 'word_images',
            'post_status' => 'publish',
            'post_title' => 'Scoped owner image',
        ]);
        wp_set_object_terms($image_id, [$category_b], 'word-category', false);
        update_post_meta($image_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_a);

        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $signature_a_before = ll_tools_get_quiz_content_cache_epoch([$wordset_a]);
        $signature_b_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);
        $signature_c_before = ll_tools_get_quiz_content_cache_epoch([$wordset_c]);
        $category_before = ll_tools_get_category_cache_version($category_b);

        update_post_meta($image_id, LL_TOOLS_WORD_IMAGE_WORDSET_OWNER_META_KEY, $wordset_b);

        $this->assertSame($unknown_before + 1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertNotSame($signature_a_before, ll_tools_get_quiz_content_cache_epoch([$wordset_a]));
        $this->assertNotSame($signature_b_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
        $this->assertNotSame($signature_c_before, ll_tools_get_quiz_content_cache_epoch([$wordset_c]));
        $this->assertSame($category_before + 1, ll_tools_get_category_cache_version($category_b));
    }

    public function test_unrelated_wordset_does_not_churn_flashcard_eligibility_or_ajax_keys(): void
    {
        [$wordset_a, $category_a] = $this->createOwnedCategoryFixture('Catalog A');
        [$wordset_b, $category_b] = $this->createOwnedCategoryFixture('Catalog B');
        $term_a = get_term($category_a, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term_a);

        $persisted_keys = [];
        $capture_key = static function ($persist, $cache_key) use (&$persisted_keys): bool {
            $persisted_keys[] = (string) $cache_key;
            return false;
        };
        add_filter('ll_tools_flashcard_categories_persist_transient', $capture_key, 10, 2);

        try {
            ll_flashcards_get_processed_categories_cached([$term_a], false, 5, [$wordset_a]);
            $this->assertCount(1, $persisted_keys);
            $initial_processed_key = $persisted_keys[0];

            $ajax_args_a = $this->flashcardAjaxCacheArgs($category_a, $wordset_a);
            $ajax_args_b = $this->flashcardAjaxCacheArgs($category_b, $wordset_b);
            $ajax_key_a_before = ll_tools_flashcards_public_ajax_cache_key($ajax_args_a);
            $ajax_key_b_before = ll_tools_flashcards_public_ajax_cache_key($ajax_args_b);

            ll_tools_bump_category_cache_version([$category_b], [$wordset_b], true);
            ll_flashcards_get_processed_categories_cached([$term_a], false, 5, [$wordset_a]);
            $this->assertCount(1, $persisted_keys, 'An unrelated wordset must retain the eligibility catalog request cache.');
            $this->assertSame($ajax_key_a_before, ll_tools_flashcards_public_ajax_cache_key($ajax_args_a));
            $this->assertNotSame($ajax_key_b_before, ll_tools_flashcards_public_ajax_cache_key($ajax_args_b));

            ll_tools_bump_category_cache_version([$category_a], [$wordset_a], true);
            ll_flashcards_get_processed_categories_cached([$term_a], false, 5, [$wordset_a]);
            $this->assertCount(2, $persisted_keys);
            $this->assertNotSame($initial_processed_key, $persisted_keys[1]);
            $this->assertNotSame($ajax_key_a_before, ll_tools_flashcards_public_ajax_cache_key($ajax_args_a));
        } finally {
            remove_filter('ll_tools_flashcard_categories_persist_transient', $capture_key, 10);
        }
    }

    public function test_unowned_category_uses_unknown_scope_fail_safe_without_broad_epoch(): void
    {
        $wordset_a = $this->createTerm('Unknown Guard A', 'wordset');
        $wordset_b = $this->createTerm('Unknown Guard B', 'wordset');
        $category = $this->createTerm('Legacy Unowned', 'word-category');

        $broad_before = ll_tools_get_category_cache_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_before = ll_tools_get_wordset_quiz_content_epoch($wordset_b);
        $signature_a_before = ll_tools_get_quiz_content_cache_epoch([$wordset_a]);
        $signature_b_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);

        ll_tools_public_static_cache_reset_purge_once_state();
        ll_tools_bump_category_cache_version([$category]);

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($unknown_before + 1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame($wordset_a_before, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertSame($wordset_b_before, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertNotSame($signature_a_before, ll_tools_get_quiz_content_cache_epoch([$wordset_a]));
        $this->assertNotSame($signature_b_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
        $this->assertPublicPurgeIsGlobal();
    }

    public function test_direct_term_query_failures_make_word_scope_incomplete(): void
    {
        global $wpdb;

        [$wordset_id, $category_id] = $this->createOwnedCategoryFixture('Direct scope failure');
        $word_id = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Direct scope failure word',
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        foreach (['word-category', 'wordset'] as $failed_taxonomy) {
            $injected = false;
            $clauses_filter = static function (array $clauses, array $taxonomies, array $args) use ($failed_taxonomy, &$injected): array {
                if (in_array($failed_taxonomy, $taxonomies, true) && !empty($args['object_ids'])) {
                    $clauses['where'] .= ' AND ll_tools_missing_scope_table.term_id = 1';
                    $injected = true;
                }
                return $clauses;
            };
            $previous_suppress_errors = $wpdb->suppress_errors(true);
            add_filter('terms_clauses', $clauses_filter, 10, 3);
            try {
                $scope = ll_tools_collect_word_quiz_cache_scope([$word_id]);
            } finally {
                remove_filter('terms_clauses', $clauses_filter, 10);
                $wpdb->suppress_errors($previous_suppress_errors);
                $wpdb->last_error = '';
            }

            $this->assertTrue($injected, 'The fixture must interrupt the intended direct taxonomy read.');
            $this->assertFalse((bool) ($scope['complete'] ?? true));
        }
    }

    public function test_category_only_bump_keeps_unknown_guard_for_cross_wordset_drift(): void
    {
        [$wordset_a, $category_id] = $this->createOwnedCategoryFixture('Drift Owner');
        $wordset_b = $this->createTerm('Drift Direct Wordset', 'wordset');
        $word_id = $this->createWord($wordset_b, $category_id, 'Cross-wordset drift');
        $this->assertGreaterThan(0, $word_id);

        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_before = ll_tools_get_wordset_quiz_content_epoch($wordset_b);
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $signature_b_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);

        ll_tools_bump_category_cache_version([$category_id]);

        $this->assertSame($wordset_a_before + 1, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertSame($wordset_b_before, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertSame($unknown_before + 1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertNotSame($signature_b_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
    }

    public function test_mixed_owned_and_unknown_categories_bump_known_scope_and_fail_safe(): void
    {
        [$wordset_a, $category_a] = $this->createOwnedCategoryFixture('Mixed Owned');
        $wordset_b = $this->createTerm('Mixed Other', 'wordset');
        $unknown_category = $this->createTerm('Mixed Unknown', 'word-category');

        $broad_before = ll_tools_get_category_cache_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_before = ll_tools_get_wordset_quiz_content_epoch($wordset_b);
        $wordset_b_signature_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);

        ll_tools_public_static_cache_reset_purge_once_state();
        ll_tools_bump_category_cache_version([$category_a, $unknown_category]);

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($unknown_before + 1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame($wordset_a_before + 1, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertSame($wordset_b_before, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertNotSame($wordset_b_signature_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
        $this->assertPublicPurgeIsGlobal();
    }

    public function test_structural_category_hook_keeps_broad_epoch_and_skips_content_epochs(): void
    {
        [$wordset_id, $category_id] = $this->createOwnedCategoryFixture('Structural');

        $broad_before = ll_tools_get_category_cache_epoch();
        $global_before = ll_tools_get_quiz_content_global_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $wordset_before = ll_tools_get_wordset_quiz_content_epoch($wordset_id);
        $category_before = ll_tools_get_category_cache_version($category_id);

        ll_tools_public_static_cache_reset_purge_once_state();
        ll_tools_bump_single_category_cache_version($category_id);

        $this->assertSame($broad_before + 1, ll_tools_get_category_cache_epoch());
        $this->assertSame($global_before, ll_tools_get_quiz_content_global_epoch());
        $this->assertSame($unknown_before, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame($wordset_before, ll_tools_get_wordset_quiz_content_epoch($wordset_id));
        $this->assertSame($category_before + 1, ll_tools_get_category_cache_version($category_id));
        $this->assertSame(20, has_action('created_word-category', 'll_tools_bump_single_category_cache_version'));
        $this->assertSame(20, has_action('edited_word-category', 'll_tools_bump_single_category_cache_version'));
        $this->assertSame(20, has_action('delete_word-category', 'll_tools_bump_single_category_cache_version'));
        $this->assertPublicPurgeIsGlobal();
    }

    public function test_uncategorized_word_with_wordset_invalidates_only_that_scope(): void
    {
        $wordset_a = $this->createTerm('Uncategorized A', 'wordset');
        $wordset_b = $this->createTerm('Uncategorized B', 'wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Uncategorized scoped word',
        ]);
        wp_set_object_terms($word_id, [$wordset_a], 'wordset', false);

        $broad_before = ll_tools_get_category_cache_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_before = ll_tools_get_wordset_quiz_content_epoch($wordset_b);

        ll_tools_public_static_cache_reset_purge_once_state();
        $this->assertSame([], ll_tools_bump_content_post_quiz_cache($word_id));

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($unknown_before, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame($wordset_a_before + 1, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertSame($wordset_b_before, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertPublicPurgeTargets([$wordset_a]);
    }

    public function test_fully_unscoped_content_invalidates_every_scoped_signature_fail_safe(): void
    {
        $wordset_a = $this->createTerm('No Scope A', 'wordset');
        $wordset_b = $this->createTerm('No Scope B', 'wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Fully unscoped word',
        ]);

        $broad_before = ll_tools_get_category_cache_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $signature_a_before = ll_tools_get_quiz_content_cache_epoch([$wordset_a]);
        $signature_b_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);

        ll_tools_public_static_cache_reset_purge_once_state();
        $this->assertSame([], ll_tools_bump_content_post_quiz_cache($word_id));

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($unknown_before + 1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertNotSame($signature_a_before, ll_tools_get_quiz_content_cache_epoch([$wordset_a]));
        $this->assertNotSame($signature_b_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
        $this->assertPublicPurgeIsGlobal();
    }

    public function test_unowned_category_stays_fail_safe_even_when_post_has_a_wordset(): void
    {
        $wordset_a = $this->createTerm('Legacy Scoped A', 'wordset');
        $wordset_b = $this->createTerm('Legacy Scoped B', 'wordset');
        $category_id = $this->createTerm('Legacy Scoped Category', 'word-category');
        $word_id = $this->createWord($wordset_a, $category_id, 'Legacy scoped category word');
        $assigned_category_ids = wp_get_post_terms($word_id, 'word-category', ['fields' => 'ids']);
        $this->assertIsArray($assigned_category_ids);
        $category_id = (int) ($assigned_category_ids[0] ?? 0);
        $this->assertGreaterThan(0, $category_id);
        delete_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY);

        $broad_before = ll_tools_get_category_cache_epoch();
        $unknown_before = ll_tools_get_quiz_content_unknown_epoch();
        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_signature_before = ll_tools_get_quiz_content_cache_epoch([$wordset_b]);

        ll_tools_public_static_cache_reset_purge_once_state();
        $this->assertSame([$category_id], ll_tools_bump_content_post_quiz_cache($word_id));

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($unknown_before + 1, ll_tools_get_quiz_content_unknown_epoch());
        $this->assertSame($wordset_a_before + 1, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertNotSame($wordset_b_signature_before, ll_tools_get_quiz_content_cache_epoch([$wordset_b]));
        $this->assertPublicPurgeIsGlobal();
    }

    public function test_audio_parent_change_uses_parent_wordset_scope(): void
    {
        [$wordset_a, $category_a] = $this->createOwnedCategoryFixture('Audio A');
        [$wordset_b] = $this->createOwnedCategoryFixture('Audio B');
        $word_id = $this->createWord($wordset_a, $category_a, 'Scoped audio parent');
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Scoped audio',
        ]);

        $broad_before = ll_tools_get_category_cache_epoch();
        $category_before = ll_tools_get_category_cache_version($category_a);
        $wordset_a_before = ll_tools_get_wordset_quiz_content_epoch($wordset_a);
        $wordset_b_before = ll_tools_get_wordset_quiz_content_epoch($wordset_b);

        ll_tools_public_static_cache_reset_purge_once_state();
        $this->assertSame([$category_a], ll_tools_bump_word_audio_parent_category_cache($audio_id));

        $this->assertSame($broad_before, ll_tools_get_category_cache_epoch());
        $this->assertSame($category_before + 1, ll_tools_get_category_cache_version($category_a));
        $this->assertSame($wordset_a_before + 1, ll_tools_get_wordset_quiz_content_epoch($wordset_a));
        $this->assertSame($wordset_b_before, ll_tools_get_wordset_quiz_content_epoch($wordset_b));
        $this->assertPublicPurgeTargets([$wordset_a]);
    }

    /** @return array{0:int,1:int} */
    private function createOwnedCategoryFixture(string $label): array
    {
        $wordset_id = $this->createTerm($label . ' Wordset', 'wordset');
        $category_id = $this->createTerm($label . ' Category', 'word-category');
        update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $wordset_id);
        return [$wordset_id, $category_id];
    }

    private function createTerm(string $label, string $taxonomy): int
    {
        $created = wp_insert_term($label . ' ' . wp_generate_uuid4(), $taxonomy);
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }

    private function createWord(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$category_id], 'word-category', false);
        return $word_id;
    }

    /** @return array<string,mixed> */
    private function flashcardAjaxCacheArgs(int $category_id, int $wordset_id): array
    {
        $term = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);
        return [
            'wordset_ids' => [$wordset_id],
            'wordset_fallback' => false,
            'prompt_type' => 'audio',
            'option_type' => 'text_translation',
            'use_titles' => false,
            'term_id' => $category_id,
            'term_slug' => (string) $term->slug,
            'quiz_config' => [
                'prompt_type' => 'audio',
                'option_type' => 'text_translation',
            ],
        ];
    }

    /** @param int[] $wordset_ids */
    private function assertPublicPurgeTargets(array $wordset_ids): void
    {
        $criteria = ll_tools_public_static_cache_normalize_purge_criteria(['wordset_ids' => $wordset_ids]);
        $key = md5((string) wp_json_encode($criteria));
        $purged = $GLOBALS['ll_tools_public_static_cache_purged_keys'] ?? [];
        $this->assertIsArray($purged);
        $this->assertArrayHasKey($key, $purged);
        $this->assertArrayNotHasKey('all', $purged);
    }

    private function assertPublicPurgeIsGlobal(): void
    {
        $purged = $GLOBALS['ll_tools_public_static_cache_purged_keys'] ?? [];
        $this->assertIsArray($purged);
        $this->assertArrayHasKey('all', $purged);
    }
}
