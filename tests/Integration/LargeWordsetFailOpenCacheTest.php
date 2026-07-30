<?php
declare(strict_types=1);

final class LargeWordsetFailOpenCacheTest extends LL_Tools_TestCase
{
    public function test_sign_mode_read_reports_its_term_meta_failure_and_recovers(): void
    {
        global $wpdb;

        $wordset_id = $this->createWordset('Sign Mode Completeness');
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $failed = $this->withFailedTermMetaQuery($wordset_id, static function () use ($wordset_id): array {
            $complete = true;
            $uses_sign_mode = ll_tools_wordset_uses_sign_language_mode([$wordset_id], $complete);
            return [$uses_sign_mode, $complete];
        });

        $this->assertFalse($failed[0]);
        $this->assertFalse($failed[1]);

        wp_cache_delete($wordset_id, 'term_meta');
        $wpdb->last_error = 'stale error from an unrelated query';
        $retry_complete = false;
        $this->assertTrue(ll_tools_wordset_uses_sign_language_mode([$wordset_id], $retry_complete));
        $this->assertTrue($retry_complete);
        $this->assertSame('', $wpdb->last_error, 'The helper must reset stale query errors before its own meta read.');
    }

    public function test_sign_mode_failure_does_not_publish_quiz_aggregate_caches(): void
    {
        $sign_wordset_id = $this->createWordset('Flashcard Sign Scope');
        $second_wordset_id = $this->createWordset('Flashcard Secondary Scope');
        update_term_meta($sign_wordset_id, LL_TOOLS_WORDSET_SIGN_LANGUAGE_MODE_META_KEY, '1');

        $category_result = wp_insert_term(
            'Fail Open Sign Category ' . strtolower(wp_generate_password(8, false)),
            'word-category'
        );
        $this->assertIsArray($category_result);
        $category_id = (int) $category_result['term_id'];
        if (defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
            update_term_meta($category_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, $sign_wordset_id);
        }
        update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
        update_term_meta($category_id, 'll_quiz_option_type', 'audio');
        $category = get_term($category_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $word_id = (int) self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Sign presentation image word',
        ]);
        update_post_meta($word_id, 'word_translation', 'Sign presentation translation');
        $attachment_id = (int) self::factory()->post->create([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => 'Sign presentation image',
            'post_mime_type' => 'image/jpeg',
        ]);
        update_post_meta($attachment_id, '_wp_attached_file', '2026/07/sign-presentation-image.jpg');
        set_post_thumbnail($word_id, $attachment_id);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$sign_wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, ['noun'], 'part_of_speech', false);
        update_post_meta($word_id, 'll_grammatical_gender', 'masculine');

        $owner_snapshot = $this->installCompleteOwnerMap();
        $wordset_ids = [$sign_wordset_id, $second_wordset_id];
        $failure_config = ll_tools_get_category_quiz_config($category);
        $failure_keys = $this->quizAggregateCacheKeys($category, $failure_config, $wordset_ids, false);
        $this->deleteQuizAggregateCaches($failure_keys);
        try {
            $failed_renderable = $this->withFailedTermMetaQuery(
                $sign_wordset_id,
                static function () use ($category, $wordset_ids): array {
                    $complete = true;
                    $ids = ll_tools_get_renderable_category_item_ids(
                        $category,
                        'audio',
                        $wordset_ids,
                        [],
                        $complete
                    );
                    return [$ids, $complete];
                }
            );
            $this->assertSame([], $failed_renderable[0]);
            $this->assertFalse($failed_renderable[1]);

            $failed_count = $this->withFailedTermMetaQuery(
                $sign_wordset_id,
                static function () use ($category, $wordset_ids): array {
                    $complete = true;
                    $count = ll_get_words_by_category_count(
                        $category,
                        'audio',
                        $wordset_ids,
                        [],
                        $complete,
                        1
                    );
                    return [$count, $complete];
                }
            );
            $this->assertSame(0, $failed_count[0]);
            $this->assertFalse($failed_count[1]);

            $failed_gender = $this->withFailedTermMetaQuery(
                $sign_wordset_id,
                static function () use ($category, $wordset_ids): array {
                    $complete = true;
                    $count = ll_tools_count_gender_eligible_words_for_category(
                        $category,
                        $wordset_ids,
                        [],
                        ['masculine'],
                        $complete
                    );
                    return [$count, $complete];
                }
            );
            $this->assertSame(0, $failed_gender[0]);
            $this->assertFalse($failed_gender[1]);

            $failed_rows = $this->withFailedTermMetaQuery(
                $sign_wordset_id,
                static function () use ($category, $wordset_ids): array {
                    $complete = true;
                    $rows = ll_get_words_by_category($category, 'audio', $wordset_ids, [], $complete);
                    return [$rows, $complete];
                }
            );
            $this->assertSame([], $failed_rows[0]);
            $this->assertFalse($failed_rows[1]);

            $failed_can_generate = $this->withFailedTermMetaQuery(
                $sign_wordset_id,
                static function () use ($category, $wordset_ids): array {
                    $complete = true;
                    $can_generate = ll_can_category_generate_quiz($category, 1, $wordset_ids, $complete);
                    return [$can_generate, $complete];
                }
            );
            $this->assertFalse($failed_can_generate[0]);
            $this->assertFalse($failed_can_generate[1]);

            $this->assertFalse(wp_cache_get($failure_keys['rows'], 'll_tools_words'));
            $this->assertFalse(get_transient($failure_keys['rows']));
            $this->assertFalse(wp_cache_get($failure_keys['ids'], 'll_tools_words_ids'));
            $this->assertFalse(get_transient($failure_keys['ids']));
            $this->assertFalse(wp_cache_get($failure_keys['count'], 'll_tools_words_count'));
            $this->assertFalse(get_transient($failure_keys['count']));

            $failed = $this->withFailedTermMetaQuery(
                $sign_wordset_id,
                static function () use ($category, $wordset_ids): array {
                    $complete = true;
                    $categories = ll_flashcards_get_processed_categories_cached(
                        [$category],
                        false,
                        1,
                        $wordset_ids,
                        $complete
                    );
                    return [$categories, $complete];
                }
            );

            $this->assertSame([], $failed[0]);
            $this->assertFalse($failed[1]);

            wp_cache_delete($sign_wordset_id, 'term_meta');
            wp_cache_delete($second_wordset_id, 'term_meta');
            $image_complete = false;
            $this->assertSame(
                [$word_id => true],
                ll_tools_get_word_effective_image_presence_map([$word_id], $image_complete)
            );
            $this->assertTrue($image_complete);
            $renderable_complete = false;
            $this->assertSame(
                [$word_id],
                ll_tools_get_renderable_category_item_ids(
                    $category,
                    'image',
                    $wordset_ids,
                    [],
                    $renderable_complete
                )
            );
            $this->assertTrue($renderable_complete);
            $count_complete = false;
            $this->assertSame(1, ll_get_words_by_category_count($category, 'image', $wordset_ids, [], $count_complete, 1));
            $this->assertTrue($count_complete);
            $gender_complete = false;
            $this->assertSame(
                1,
                ll_tools_count_gender_eligible_words_for_category(
                    $category,
                    $wordset_ids,
                    [],
                    ['masculine'],
                    $gender_complete
                )
            );
            $this->assertTrue($gender_complete);
            $rows_complete = false;
            $rows = ll_get_words_by_category($category, 'image', $wordset_ids, [], $rows_complete);
            $this->assertTrue($rows_complete);
            $this->assertSame([$word_id], array_values(array_map('intval', wp_list_pluck($rows, 'id'))));
            $can_generate_complete = false;
            $this->assertTrue(ll_can_category_generate_quiz($category, 1, $wordset_ids, $can_generate_complete));
            $this->assertTrue($can_generate_complete);
            $effective_complete = false;
            $effective_config = ll_tools_resolve_effective_category_quiz_config(
                $category,
                1,
                $wordset_ids,
                $effective_complete
            );
            $this->assertTrue($effective_complete);
            $this->assertSame('image', (string) ($effective_config['option_type'] ?? ''));
            $this->assertSame(1, (int) ($effective_config['word_count'] ?? 0));
            $retry_complete = false;
            $retry = ll_flashcards_get_processed_categories_cached(
                [$category],
                false,
                1,
                $wordset_ids,
                $retry_complete
            );

            $this->assertTrue($retry_complete);
            $this->assertCount(1, $retry, 'The same cache key must rebuild after the sign-mode source recovers.');
            $this->assertSame('image', (string) ($retry[0]['mode'] ?? ''));
            $this->assertSame(1, (int) ($retry[0]['word_count'] ?? 0));
        } finally {
            $this->deleteQuizAggregateCaches($failure_keys);
            $this->restoreOwnerMap($owner_snapshot);
        }
    }

    public function test_renderable_and_count_caches_retry_same_key_after_incomplete_sources(): void
    {
        global $wpdb;

        $renderable_fixture = $this->createTextCategoryFixture('Renderable SQL');
        $count_fixture = $this->createTextCategoryFixture('Count Source');
        $config = $this->textQuizConfig();
        $renderable_keys = $this->quizAggregateCacheKeys(
            $renderable_fixture['category'],
            $config
        );
        $count_keys = $this->quizAggregateCacheKeys(
            $count_fixture['category'],
            $config
        );
        $owner_snapshot = $this->installCompleteOwnerMap();
        try {
            $this->deleteQuizAggregateCaches($renderable_keys);
            $this->deleteQuizAggregateCaches($count_keys);

            $injected = false;
            $term_taxonomy_id = (int) $renderable_fixture['category']->term_taxonomy_id;
            $break_primary_word_query = static function (string $sql) use (
                $wpdb,
                $term_taxonomy_id,
                &$injected
            ): string {
                if (
                    !$injected
                    && strpos($sql, "{$wpdb->posts}.post_type = 'words'") !== false
                    && preg_match(
                        '/term_taxonomy_id\s+IN\s*\([^)]*\b' . preg_quote((string) $term_taxonomy_id, '/') . '\b[^)]*\)/i',
                        $sql
                    ) === 1
                ) {
                    $injected = true;
                    return "SELECT ID FROM {$wpdb->posts}_ll_tools_fail_open_missing";
                }

                return $sql;
            };

            $previous_suppress = $wpdb->suppress_errors(true);
            add_filter('query', $break_primary_word_query);
            try {
                $renderable_complete = true;
                $failed_ids = ll_tools_get_renderable_category_item_ids(
                    $renderable_fixture['category'],
                    'text',
                    null,
                    $config,
                    $renderable_complete
                );
            } finally {
                remove_filter('query', $break_primary_word_query);
                $wpdb->suppress_errors($previous_suppress);
            }

            $this->assertTrue($injected, 'The fixture must interrupt the primary category word query.');
            $this->assertFalse($renderable_complete);
            $this->assertSame([], $failed_ids);
            $this->assertFalse(wp_cache_get($renderable_keys['ids'], 'll_tools_words_ids'));
            $this->assertFalse(get_transient($renderable_keys['ids']));
            $this->assertSame(
                $renderable_keys,
                $this->quizAggregateCacheKeys($renderable_fixture['category'], $config),
                'The recovery assertion must exercise the identical aggregate generation.'
            );

            $retry_renderable_complete = false;
            $retry_ids = ll_tools_get_renderable_category_item_ids(
                $renderable_fixture['category'],
                'text',
                null,
                $config,
                $retry_renderable_complete
            );
            $this->assertTrue(
                $retry_renderable_complete,
                'A same-key retry must execute the repaired source instead of reusing the incomplete request result.'
            );
            $this->assertSame([(int) $renderable_fixture['word_id']], array_values($retry_ids));
            $this->assertIsArray(get_transient($renderable_keys['ids']));

            update_option(
                LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
                'stale:fail-open-regression',
                false
            );
            $count_complete = true;
            $failed_source_count = ll_get_words_by_category_count(
                $count_fixture['category'],
                'text',
                null,
                $config,
                $count_complete
            );

            $this->assertSame(1, $failed_source_count);
            $this->assertFalse($count_complete);
            $this->assertFalse(wp_cache_get($count_keys['count'], 'll_tools_words_count'));
            $this->assertFalse(get_transient($count_keys['count']));

            update_option(
                LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
                (string) $owner_snapshot['installed_integrity'],
                false
            );
            $this->assertSame(
                $count_keys,
                $this->quizAggregateCacheKeys($count_fixture['category'], $config),
                'Changing only source completeness must not sidestep the request-cache regression with a new key.'
            );
            $retry_count_complete = false;
            $retry_count = ll_get_words_by_category_count(
                $count_fixture['category'],
                'text',
                null,
                $config,
                $retry_count_complete
            );
            $this->assertSame(1, $retry_count);
            $this->assertTrue(
                $retry_count_complete,
                'Restoring the source marker must make a same-key retry complete; an incomplete request cache would mask it.'
            );
            $this->assertIsArray(get_transient($count_keys['count']));
        } finally {
            $this->deleteQuizAggregateCaches($renderable_keys);
            $this->deleteQuizAggregateCaches($count_keys);
            $this->restoreOwnerMap($owner_snapshot);
        }
    }

    public function test_outer_category_payload_is_not_cached_when_uncategorized_source_is_incomplete(): void
    {
        global $wpdb;

        $previous_user_id = get_current_user_id();
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);
        $owner_snapshot = null;
        $outer_cache_key = '';
        try {
            $suffix = strtolower(wp_generate_password(8, false));
            $wordset_result = wp_insert_term(
                'Fail Open Outer Wordset ' . $suffix,
                'wordset',
                ['slug' => 'fail-open-outer-wordset-' . $suffix]
            );
            $this->assertIsArray($wordset_result);
            $wordset_id = (int) $wordset_result['term_id'];

            $owned_category = ll_tools_create_or_get_wordset_category(
                'Fail Open Owned Category ' . $suffix,
                $wordset_id,
                ['slug' => 'fail-open-owned-category-' . $suffix]
            );
            $this->assertNotWPError($owned_category);
            $this->assertGreaterThan(0, (int) $owned_category);

            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => 'Fail Open Uncategorized Word ' . $suffix,
            ]);
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

            $owner_snapshot = $this->installCompleteOwnerMap();
            $outer_cache_key = $this->outerCategoryCacheKey($wordset_id, 2, false);
            wp_cache_delete($outer_cache_key, 'll_tools');
            delete_transient($outer_cache_key);

            $wordset = get_term($wordset_id, 'wordset');
            $this->assertInstanceOf(WP_Term::class, $wordset);
            $wordset_tt_id = (int) $wordset->term_taxonomy_id;
            $injected = false;
            $break_uncategorized_count = static function (string $sql) use (
                $wpdb,
                $wordset_tt_id,
                &$injected
            ): string {
                if (
                    !$injected
                    && strpos($sql, 'SELECT COUNT(DISTINCT posts.ID)') !== false
                    && strpos($sql, 'selected_category_relationships') !== false
                    && preg_match(
                        '/wordset_relationships\.term_taxonomy_id\s*=\s*' . preg_quote((string) $wordset_tt_id, '/') . '\b/i',
                        $sql
                    ) === 1
                ) {
                    $injected = true;
                    return "SELECT ID FROM {$wpdb->posts}_ll_tools_fail_open_missing";
                }

                return $sql;
            };

            $previous_suppress = $wpdb->suppress_errors(true);
            add_filter('query', $break_uncategorized_count);
            try {
                $failed_items = ll_tools_get_wordset_page_categories($wordset_id, 2);
            } finally {
                remove_filter('query', $break_uncategorized_count);
                $wpdb->suppress_errors($previous_suppress);
            }

            $this->assertTrue($injected, 'The fixture must interrupt the nested uncategorized count source.');
            $this->assertFalse($this->containsUncategorizedCategory($failed_items));
            $this->assertFalse(wp_cache_get($outer_cache_key, 'll_tools'));
            $this->assertFalse(get_transient($outer_cache_key));
            $this->assertSame(
                $outer_cache_key,
                $this->outerCategoryCacheKey($wordset_id, 2, false),
                'The nested-source retry must remain on the same outer payload generation.'
            );

            $retry_items = ll_tools_get_wordset_page_categories($wordset_id, 2);
            $this->assertTrue(
                $this->containsUncategorizedCategory($retry_items),
                'The same outer cache key must retry and expose the uncategorized card after the nested source recovers.'
            );
            $this->assertNotFalse(get_transient($outer_cache_key));
        } finally {
            if ($outer_cache_key !== '') {
                wp_cache_delete($outer_cache_key, 'll_tools');
                delete_transient($outer_cache_key);
            }
            if (is_array($owner_snapshot)) {
                $this->restoreOwnerMap($owner_snapshot);
            }
            wp_set_current_user($previous_user_id);
        }
    }

    public function test_scoped_quiz_epoch_rotates_lazy_payload_access_signature_and_token(): void
    {
        $previous_user_id = get_current_user_id();
        wp_set_current_user(0);

        try {
            $wordset_a = $this->createWordset('Lazy Scope A');
            $wordset_b = $this->createWordset('Lazy Scope B');

            $signature_a_before = ll_tools_wordset_page_payload_access_signature($wordset_a, 0);
            $signature_b_before = ll_tools_wordset_page_payload_access_signature($wordset_b, 0);
            $token_a_before = $this->lazyTokenForCurrentEpoch($wordset_a);
            $token_b_before = $this->lazyTokenForCurrentEpoch($wordset_b);

            $this->assertNotSame('', $signature_a_before);
            $this->assertNotSame('', $signature_b_before);
            $this->assertMatchesRegularExpression('/^shared_[a-f0-9]{32}$/', $token_a_before);
            $this->assertMatchesRegularExpression('/^shared_[a-f0-9]{32}$/', $token_b_before);

            ll_tools_bump_quiz_content_cache_epoch([$wordset_a], true);

            $signature_a_after = ll_tools_wordset_page_payload_access_signature($wordset_a, 0);
            $signature_b_after = ll_tools_wordset_page_payload_access_signature($wordset_b, 0);
            $token_a_after = $this->lazyTokenForCurrentEpoch($wordset_a);
            $token_b_after = $this->lazyTokenForCurrentEpoch($wordset_b);

            $this->assertNotSame($signature_a_before, $signature_a_after);
            $this->assertNotSame($token_a_before, $token_a_after);
            $this->assertSame($signature_b_before, $signature_b_after);
            $this->assertSame($token_b_before, $token_b_after);
            $this->assertFalse(
                ll_tools_wordset_page_payload_scope_is_current($wordset_a, $signature_a_before, 0),
                'A stored lazy payload from the old scoped epoch must fail its O(1) access revalidation.'
            );
            $this->assertTrue(
                ll_tools_wordset_page_payload_scope_is_current($wordset_a, $signature_a_after, 0)
            );
        } finally {
            wp_set_current_user($previous_user_id);
        }
    }

    /** @return array{category:WP_Term,word_id:int} */
    private function createTextCategoryFixture(string $label): array
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $created = wp_insert_term(
            'Fail Open ' . $label . ' ' . $suffix,
            'word-category',
            ['slug' => 'fail-open-' . sanitize_title($label) . '-' . $suffix]
        );
        $this->assertIsArray($created);
        $category = get_term((int) $created['term_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $category);

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Fail Open ' . $label . ' Word ' . $suffix,
        ]);
        wp_set_post_terms($word_id, [(int) $category->term_id], 'word-category', false);

        return [
            'category' => $category,
            'word_id' => (int) $word_id,
        ];
    }

    /** @return array<string,mixed> */
    private function textQuizConfig(): array
    {
        return [
            'prompt_type' => 'text_title',
            'option_type' => 'text_title',
            '__skip_quiz_config_merge' => true,
        ];
    }

    /** @return array{rows:string,ids:string,count:string} */
    private function quizAggregateCacheKeys(
        WP_Term $category,
        array $quiz_config,
        array $wordset_ids = [],
        bool $apply_presentation_overrides = true
    ): array
    {
        $category_context = ll_tools_get_word_category_query_context($category);
        $config = ($apply_presentation_overrides && function_exists('ll_tools_apply_wordset_quiz_presentation_overrides'))
            ? ll_tools_apply_wordset_quiz_presentation_overrides($quiz_config, $wordset_ids)
            : $quiz_config;
        $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
        $option_type = (string) ($config['option_type'] ?? 'text_title');
        $use_titles = !empty($config['use_titles']);
        $require_audio = ll_tools_quiz_requires_audio(
            ['prompt_type' => $prompt_type, 'option_type' => $option_type],
            $option_type
        );
        $flags = [
            'require_audio' => $require_audio,
            'require_prompt_image' => ll_tools_quiz_prompt_type_has_image($prompt_type),
            'require_option_image' => ll_tools_quiz_option_type_has_image($option_type),
            'use_titles' => $use_titles,
            'term_slug' => (string) ($category_context['slug'] ?? ''),
            'text_label_schema' => 8,
            'prompt_card_schema' => 4,
            'wordset_sign_language_mode' => !empty($config['sign_language_mode']),
            'image_animation_meta' => true,
            'masked_image_url' => function_exists('ll_tools_should_use_masked_image_proxy')
                ? ll_tools_should_use_masked_image_proxy()
                : true,
            'include_pos' => true,
            'include_gender' => true,
            'include_plurality' => true,
            'source_complete_schema' => 2,
        ];
        $rows_key = ll_tools_get_words_cache_key(
            (int) ($category_context['term_id'] ?? 0),
            $wordset_ids,
            $prompt_type,
            $option_type,
            $flags
        );

        return [
            'rows' => $rows_key,
            'ids' => 'll_wc_item_ids_' . md5($rows_key . '|v1'),
            'count' => 'll_wc_words_count_' . md5($rows_key . '|v3'),
        ];
    }

    /** @param array{rows:string,ids:string,count:string} $keys */
    private function deleteQuizAggregateCaches(array $keys): void
    {
        wp_cache_delete($keys['rows'], 'll_tools_words');
        delete_transient($keys['rows']);
        wp_cache_delete($keys['ids'], 'll_tools_words_ids');
        delete_transient($keys['ids']);
        wp_cache_delete($keys['count'], 'll_tools_words_count');
        delete_transient($keys['count']);
    }

    /**
     * @return array{map_exists:bool,map:mixed,integrity_exists:bool,integrity:mixed,installed_integrity:string}
     */
    private function installCompleteOwnerMap(): array
    {
        $sentinel = '__ll_tools_fail_open_missing_option__';
        $previous_map = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $sentinel);
        $previous_integrity = get_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, $sentinel);
        $generation = 'failopen' . substr(md5(wp_generate_uuid4()), 0, 20);
        $normalized = is_array($previous_map)
            ? ll_tools_specific_wrong_answer_owner_map_normalize($previous_map)
            : [];
        $integrity = 'v2:' . $generation;

        update_option(
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION,
            ll_tools_specific_wrong_answer_owner_map_pack($normalized, $generation),
            false
        );
        update_option(
            LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION,
            $integrity,
            false
        );

        return [
            'map_exists' => $previous_map !== $sentinel,
            'map' => $previous_map,
            'integrity_exists' => $previous_integrity !== $sentinel,
            'integrity' => $previous_integrity,
            'installed_integrity' => $integrity,
        ];
    }

    /** @param array{map_exists:bool,map:mixed,integrity_exists:bool,integrity:mixed} $snapshot */
    private function restoreOwnerMap(array $snapshot): void
    {
        if ($snapshot['map_exists']) {
            update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION, $snapshot['map'], false);
        } else {
            delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_OPTION);
        }
        if ($snapshot['integrity_exists']) {
            update_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION, $snapshot['integrity'], false);
        } else {
            delete_option(LL_TOOLS_SPECIFIC_WRONG_ANSWERS_OWNER_INTEGRITY_OPTION);
        }
    }

    private function outerCategoryCacheKey(int $wordset_id, int $preview_limit, bool $defer_previews): string
    {
        $translation_enabled = function_exists('ll_tools_is_category_translation_enabled')
            ? (ll_tools_is_category_translation_enabled([$wordset_id]) ? '1' : '0')
            : ((bool) get_option('ll_enable_category_translation', 0) ? '1' : '0');
        $translation_target = function_exists('ll_tools_get_wordset_translation_language')
            ? sanitize_key((string) ll_tools_get_wordset_translation_language([$wordset_id]))
            : sanitize_key((string) get_option('ll_translation_language', ''));
        $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1;
        $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1;
        $quiz_content_epoch = function_exists('ll_tools_get_quiz_content_cache_epoch')
            ? ll_tools_get_quiz_content_cache_epoch([$wordset_id])
            : (string) $category_epoch;

        return ll_tools_wordset_page_build_cache_key('categories_user', [
            'schema' => 9,
            'wordset_id' => $wordset_id,
            'preview_limit' => max(1, $preview_limit),
            'preview_mode' => $defer_previews ? 'deferred' : 'eager',
            'locale' => sanitize_key((string) get_locale()),
            'translation_enabled' => $translation_enabled,
            'translation_target' => $translation_target,
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
            'quiz_content_epoch' => $quiz_content_epoch,
            'user_id' => max(0, (int) get_current_user_id()),
            'include_inactive' => 1,
        ]);
    }

    private function containsUncategorizedCategory(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && ll_tools_wordset_page_is_uncategorized_virtual_category($item)) {
                return true;
            }
        }

        return false;
    }

    private function createWordset(string $label): int
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $created = wp_insert_term(
            'Fail Open ' . $label . ' ' . $suffix,
            'wordset',
            ['slug' => 'fail-open-' . sanitize_title($label) . '-' . $suffix]
        );
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }

    private function lazyTokenForCurrentEpoch(int $wordset_id): string
    {
        return ll_tools_wordset_page_build_lazy_cards_token([
            'schema' => 3,
            'wordset_id' => $wordset_id,
            'quiz_content_epoch' => ll_tools_get_quiz_content_cache_epoch([$wordset_id]),
        ]);
    }

    /** @return mixed */
    private function withFailedTermMetaQuery(int $term_id, callable $callback)
    {
        global $wpdb;

        wp_cache_delete($term_id, 'term_meta');
        $injected = false;
        $query_filter = static function (string $sql) use ($wpdb, $term_id, &$injected): string {
            if (
                !$injected
                && strpos($sql, "FROM {$wpdb->termmeta}") !== false
                && preg_match(
                    '/term_id\s+IN\s*\([^)]*\b' . preg_quote((string) $term_id, '/') . '\b[^)]*\)/i',
                    $sql
                ) === 1
            ) {
                $injected = true;
                return "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}_ll_tools_sign_mode_missing";
            }

            return $sql;
        };

        $previous_suppress = $wpdb->suppress_errors(true);
        add_filter('query', $query_filter);
        try {
            $result = $callback();
        } finally {
            remove_filter('query', $query_filter);
            $wpdb->suppress_errors($previous_suppress);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected, 'The fixture must interrupt the intended wordset term-meta query.');
        return $result;
    }
}
