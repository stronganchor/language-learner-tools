<?php
declare(strict_types=1);

final class ContentLessonIndexShortcodeTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private array $original_get = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_get = $_GET;
        $_GET = [];
        ll_tools_register_content_lesson_index_shortcodes();
    }

    protected function tearDown(): void
    {
        $_GET = $this->original_get;
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_canonical_index_filters_exact_categories_and_paginates_independently(): void
    {
        $wordset_id = $this->createWordset('Lesson index');
        $other_wordset_id = $this->createWordset('Other lesson index');
        $grammar_category_id = self::factory()->category->create([
            'name' => 'Grammar index source',
        ]);
        $culture_category_id = self::factory()->category->create([
            'name' => 'Culture index source',
        ]);

        $first_id = $this->createLesson(
            $wordset_id,
            'Grammar One',
            1,
            [$grammar_category_id]
        );
        $second_id = $this->createLesson(
            $wordset_id,
            'Grammar Two',
            1,
            [$grammar_category_id]
        );
        $third_id = $this->createLesson(
            $wordset_id,
            'Grammar Three',
            2,
            [$grammar_category_id]
        );
        $culture_id = $this->createLesson(
            $wordset_id,
            'Culture Only',
            1,
            [$culture_category_id]
        );
        $foreign_id = $this->createLesson(
            $other_wordset_id,
            'Foreign Grammar',
            1,
            [$grammar_category_id]
        );

        $user_id = self::factory()->user->create();
        wp_set_current_user($user_id);
        $this->assertTrue(
            ll_tools_set_content_lesson_completion($user_id, $first_id, true)
        );
        update_post_meta(
            $first_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$third_id]
        );
        update_post_meta(
            $second_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$third_id]
        );

        $shortcode = sprintf(
            '[ll_content_lesson_index wordset="%d" categories="%d" per_page="2" list_id="grammar"]',
            $wordset_id,
            $grammar_category_id
        );
        global $wpdb;
        $content_lesson_selects = 0;
        $content_lesson_queries = [];
        $query_counter = static function (string $sql) use (
            &$content_lesson_selects,
            &$content_lesson_queries,
            $wpdb
        ): string {
            if (stripos($sql, 'SELECT') !== false
                && stripos($sql, (string) $wpdb->posts) !== false
                && stripos($sql, 'll_content_lesson') !== false
            ) {
                $content_lesson_selects++;
                $content_lesson_queries[] = $sql;
            }
            return $sql;
        };
        add_filter('query', $query_counter);
        try {
            $first_page = do_shortcode($shortcode);
        } finally {
            remove_filter('query', $query_counter);
        }

        $this->assertSame(2, substr_count($first_page, 'data-lesson-id="'));
        $this->assertLessThanOrEqual(
            1,
            $content_lesson_selects,
            implode("\n---\n", $content_lesson_queries)
        );
        $this->assertStringContainsString('data-lesson-id="' . $first_id . '"', $first_page);
        $this->assertStringContainsString('data-lesson-id="' . $second_id . '"', $first_page);
        $this->assertStringContainsString('class="ll-content-lesson-index__item is-completed"', $first_page);
        $this->assertStringContainsString(
            'data-lesson-id="' . $first_id . '" data-lesson-state="completed"',
            $first_page
        );
        $this->assertStringContainsString(
            'class="ll-content-lesson-index__item has-unmet-prerequisites"',
            $first_page
        );
        $this->assertStringContainsString(
            'data-lesson-id="' . $second_id . '" data-lesson-state="prerequisites-incomplete"',
            $first_page
        );
        $this->assertStringContainsString('0 / 1 prerequisite', $first_page);
        $this->assertStringContainsString('ll_lesson_page_grammar=2', html_entity_decode($first_page));
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $culture_id . '"',
            $first_page
        );
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $foreign_id . '"',
            $first_page
        );
        $this->assertTrue(wp_style_is('ll-tools-content-lesson-index', 'enqueued'));

        $this->assertTrue(
            ll_tools_set_content_lesson_completion($user_id, $third_id, true)
        );
        $unlocked_first_page = do_shortcode($shortcode);
        $this->assertStringContainsString(
            'class="ll-content-lesson-index__item is-ready" data-lesson-id="'
                . $second_id . '" data-lesson-state="ready"',
            $unlocked_first_page
        );
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $second_id
                . '" data-lesson-state="prerequisites-incomplete"',
            $unlocked_first_page
        );
        $this->assertStringNotContainsString('0 / 1 prerequisite', $unlocked_first_page);
        $this->assertTrue(
            ll_tools_set_content_lesson_completion($user_id, $third_id, false)
        );

        $_GET['ll_lesson_page_grammar'] = '2';
        $second_page = do_shortcode($shortcode);
        $this->assertSame(1, substr_count($second_page, 'data-lesson-id="'));
        $this->assertStringContainsString('data-lesson-id="' . $third_id . '"', $second_page);
        $this->assertStringContainsString(
            'data-lesson-id="' . $third_id . '" data-lesson-state="ready"',
            $second_page
        );
        $this->assertStringContainsString(
            'class="ll-content-lesson-index__item is-ready"',
            $second_page
        );
        $this->assertStringContainsString('>Ready</span>', $second_page);
        $this->assertStringContainsString('>Previous</a>', $second_page);
        $this->assertStringNotContainsString('>Next</a>', $second_page);

        $_GET['ll_lesson_page_grammar'] = '3';
        $out_of_range_page = do_shortcode($shortcode);
        $this->assertStringContainsString(
            'No lessons were found on this page.',
            $out_of_range_page
        );
        $this->assertStringContainsString('>Previous</a>', $out_of_range_page);
        $this->assertStringContainsString('ll_lesson_page_grammar=2', html_entity_decode($out_of_range_page));
    }

    public function test_index_helper_hard_caps_page_size_page_number_and_category_filters(): void
    {
        $wordset_id = $this->createWordset('Lesson index bounds');
        $result = ll_tools_get_content_lesson_index_page(
            $wordset_id,
            range(1, 100),
            999,
            999
        );

        $this->assertIsArray($result);
        $this->assertSame(LL_TOOLS_CONTENT_LESSON_INDEX_PAGE_MAX, $result['page']);
        $this->assertSame(LL_TOOLS_CONTENT_LESSON_INDEX_PER_PAGE_MAX, $result['per_page']);
        $this->assertCount(LL_TOOLS_CONTENT_LESSON_INDEX_CATEGORY_MAX, $result['category_ids']);
        $this->assertFalse($result['has_more']);
    }

    public function test_retained_source_bridge_redirects_and_stays_out_of_public_catalogs(): void
    {
        $wordset_id = $this->createWordset('Retained source catalogs');
        $legacy_category_id = self::factory()->category->create([
            'name' => 'Retained source legacy category',
        ]);
        $word_category = wp_insert_term(
            'Retained source word category ' . wp_generate_password(5, false),
            'word-category'
        );
        $this->assertIsArray($word_category);
        $word_category_id = (int) $word_category['term_id'];

        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Retained Search Surface',
            'post_name' => 'retained-search-surface',
            'post_content' => '[custom_header][custom_footer]',
        ]);
        wp_set_post_categories($source_id, [$legacy_category_id]);
        $migration = ll_tools_migrate_legacy_content_lessons_batch([
            'phase' => 'lessons',
            'wordset_id' => $wordset_id,
            'source_ids' => [$source_id],
            'limit' => 1,
            'status' => 'publish',
            'show_in_mix' => false,
            'retained_source' => true,
            'apply' => true,
        ]);
        $this->assertIsArray($migration);
        $this->assertSame([], $migration['errors']);
        $target_id = ll_tools_find_content_lesson_by_legacy_source($source_id);
        $this->assertGreaterThan(0, $target_id);
        update_post_meta(
            $target_id,
            LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
            [$word_category_id]
        );
        update_post_meta(
            $target_id,
            LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META,
            '1'
        );

        $ordinary_id = $this->createLesson(
            $wordset_id,
            'Ordinary Search Surface',
            1,
            [$legacy_category_id]
        );
        update_post_meta(
            $ordinary_id,
            LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META,
            [$word_category_id]
        );
        $dependent_id = $this->createLesson(
            $wordset_id,
            'Retained Bridge Dependent',
            2,
            [$legacy_category_id]
        );
        update_post_meta(
            $dependent_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$target_id]
        );

        $index = do_shortcode(sprintf(
            '[ll_content_lesson_index wordset="%d" categories="%d" per_page="20"]',
            $wordset_id,
            $legacy_category_id
        ));
        $this->assertStringContainsString(
            'data-lesson-id="' . $ordinary_id . '"',
            $index
        );
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $target_id . '"',
            $index
        );
        $enclosed_public_index = do_shortcode(sprintf(
            '[ll_content_lesson_index wordset="%d" categories="%d" per_page="20"]'
            . 'Compatibility content'
            . '[/ll_content_lesson_index]',
            $wordset_id,
            $legacy_category_id
        ));
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $target_id . '"',
            $enclosed_public_index
        );
        $malformed_bridge_id = $this->createLesson(
            $wordset_id,
            'A Malformed Retained Bridge 00',
            0,
            [$legacy_category_id]
        );
        add_post_meta(
            $malformed_bridge_id,
            LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
            '1',
            false
        );
        add_post_meta(
            $malformed_bridge_id,
            LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
            '1',
            false
        );
        $malformed_bridge_ids = [$malformed_bridge_id];
        for ($index_number = 1; $index_number <= 24; $index_number++) {
            $extra_malformed_id = $this->createLesson(
                $wordset_id,
                sprintf(
                    'A Malformed Retained Bridge %02d',
                    $index_number
                ),
                0,
                [$legacy_category_id]
            );
            add_post_meta(
                $extra_malformed_id,
                LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
                '1',
                false
            );
            add_post_meta(
                $extra_malformed_id,
                LL_TOOLS_LEGACY_LESSON_RETAINED_SOURCE_META,
                '1',
                false
            );
            $malformed_bridge_ids[] = $extra_malformed_id;
        }
        $legacy_index = ll_tools_legacy_display_prereq_tree_shortcode([
            'categories' => (string) $legacy_category_id,
            'per_page' => 20,
        ]);
        $source_url = (string) get_permalink($source_id);
        $this->assertStringContainsString(
            'data-lesson-id="' . $target_id . '"',
            $legacy_index
        );
        $this->assertStringContainsString(
            'href="' . esc_url($source_url) . '"',
            $legacy_index
        );
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $malformed_bridge_id . '"',
            $legacy_index
        );
        $compatibility_first_page = ll_tools_get_content_lesson_index_page(
            $wordset_id,
            [$legacy_category_id],
            1,
            2,
            true
        );
        $compatibility_second_page = ll_tools_get_content_lesson_index_page(
            $wordset_id,
            [$legacy_category_id],
            2,
            2,
            true
        );
        $this->assertIsArray($compatibility_first_page);
        $this->assertIsArray($compatibility_second_page);
        $first_page_ids = array_map(
            static fn(WP_Post $post): int => (int) $post->ID,
            $compatibility_first_page['posts']
        );
        $second_page_ids = array_map(
            static fn(WP_Post $post): int => (int) $post->ID,
            $compatibility_second_page['posts']
        );
        $this->assertSame([$target_id, $ordinary_id], $first_page_ids);
        $this->assertSame([$dependent_id], $second_page_ids);
        $this->assertTrue($compatibility_first_page['has_more']);
        $this->assertFalse($compatibility_second_page['has_more']);
        $this->assertSame(
            [],
            array_values(array_intersect(
                $malformed_bridge_ids,
                array_merge($first_page_ids, $second_page_ids)
            ))
        );
        $canonical_after_malformed = do_shortcode(sprintf(
            '[ll_content_lesson_index wordset="%d" categories="%d" per_page="20"]',
            $wordset_id,
            $legacy_category_id
        ));
        $this->assertStringNotContainsString(
            'data-lesson-id="' . $malformed_bridge_id . '"',
            $canonical_after_malformed
        );
        $this->assertFalse(ll_tools_get_content_lesson_show_in_mix($target_id));

        $wordset_lessons = ll_tools_get_content_lessons_for_wordset($wordset_id);
        $this->assertContains(
            $ordinary_id,
            array_map('intval', array_column($wordset_lessons, 'id'))
        );
        $this->assertNotContains(
            $target_id,
            array_map('intval', array_column($wordset_lessons, 'id'))
        );
        $this->assertNotContains(
            $malformed_bridge_id,
            array_map('intval', array_column($wordset_lessons, 'id'))
        );
        $related_lessons = ll_tools_get_content_lessons_for_vocab_lesson(
            $wordset_id,
            $word_category_id
        );
        $this->assertContains(
            $ordinary_id,
            array_map('intval', array_column($related_lessons, 'id'))
        );
        $this->assertNotContains(
            $target_id,
            array_map('intval', array_column($related_lessons, 'id'))
        );
        $this->assertNotContains(
            $malformed_bridge_id,
            array_map('intval', array_column($related_lessons, 'id'))
        );
        $option_page = ll_tools_content_lesson_option_page(
            'prereq_lessons',
            $wordset_id,
            ['limit' => 20]
        );
        $option_ids = array_map(
            'intval',
            array_column((array) $option_page['rows'], 'id')
        );
        $this->assertContains($ordinary_id, $option_ids);
        $this->assertNotContains($target_id, $option_ids);
        $this->assertNotContains($malformed_bridge_id, $option_ids);
        $crawler_ids = array_map(
            static fn(WP_Post $post): int => (int) $post->ID,
            ll_tools_ai_crawler_get_public_content_lessons(100)
        );
        $this->assertNotContains($target_id, $crawler_ids);
        $this->assertNotContains($malformed_bridge_id, $crawler_ids);

        $search = new WP_Query([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            's' => 'Search Surface',
            'fields' => 'ids',
        ]);
        $this->assertContains($ordinary_id, array_map('intval', $search->posts));
        $this->assertNotContains($target_id, array_map('intval', $search->posts));
        $malformed_search = new WP_Query([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            's' => 'Malformed Retained Bridge',
            'fields' => 'ids',
        ]);
        $this->assertNotContains(
            $malformed_bridge_id,
            array_map('intval', $malformed_search->posts)
        );

        $core_sitemap_args = apply_filters(
            'wp_sitemaps_posts_query_args',
            [
                'post_type' => 'll_content_lesson',
                'post_status' => 'publish',
                'posts_per_page' => 20,
                'fields' => 'ids',
            ],
            'll_content_lesson'
        );
        $core_sitemap_ids = get_posts($core_sitemap_args);
        $this->assertContains($ordinary_id, array_map('intval', $core_sitemap_ids));
        $this->assertNotContains($target_id, array_map('intval', $core_sitemap_ids));
        $this->assertNotContains(
            $malformed_bridge_id,
            array_map('intval', $core_sitemap_ids)
        );
        $this->assertFalse(apply_filters(
            'wpseo_sitemap_entry',
            ['loc' => (string) get_permalink($target_id)],
            'post',
            get_post($target_id)
        ));
        $this->assertIsArray(apply_filters(
            'wpseo_sitemap_entry',
            ['loc' => (string) get_permalink($ordinary_id)],
            'post',
            get_post($ordinary_id)
        ));

        $rest_args = apply_filters(
            'rest_ll_content_lesson_query',
            [
                'post_type' => 'll_content_lesson',
                'post_status' => 'publish',
                'posts_per_page' => 20,
                'fields' => 'ids',
            ],
            null
        );
        $rest_ids = get_posts($rest_args);
        $this->assertContains($ordinary_id, array_map('intval', $rest_ids));
        $this->assertNotContains($target_id, array_map('intval', $rest_ids));
        $this->assertNotContains(
            $malformed_bridge_id,
            array_map('intval', $rest_ids)
        );
        $post_type = get_post_type_object('ll_content_lesson');
        $this->assertInstanceOf(WP_Post_Type::class, $post_type);
        $this->assertTrue((bool) $post_type->exclude_from_search);
        $this->assertFalse((bool) $post_type->has_archive);
        $this->assertFalse((bool) $post_type->show_in_rest);

        $this->assertSame($source_url, (string) get_permalink($target_id));
        $GLOBALS['post'] = get_post($source_id);
        setup_postdata($GLOBALS['post']);
        try {
            wp_set_current_user(0);
            $header = do_shortcode('[custom_header]');
            $footer = do_shortcode('[custom_footer]');
        } finally {
            wp_reset_postdata();
        }
        $this->assertStringContainsString(
            'Log in to save lesson progress',
            $header
        );
        $this->assertStringContainsString(urlencode($source_url), $header);
        $this->assertStringContainsString('Retained Bridge Dependent', $footer);

        $this->go_to(
            add_query_arg(
                ['post_type' => 'll_content_lesson', 'p' => $target_id],
                home_url('/')
            )
        );
        $this->assertTrue(is_singular('ll_content_lesson'));
        $this->assertSame(
            $source_url,
            apply_filters('wpseo_canonical', (string) get_permalink($ordinary_id))
        );
        $this->assertSame(
            'noindex, follow',
            apply_filters('wpseo_robots', 'index, follow')
        );
        $this->assertSame(
            'noindex',
            (string) (
                apply_filters('wpseo_robots_array', ['index' => 'index'])['index']
                ?? ''
            )
        );
        $this->assertArrayHasKey('noindex', apply_filters('wp_robots', []));

        $captured_location = '';
        $captured_status = 0;
        $redirect_capture = static function (
            string $location,
            int $status
        ) use (&$captured_location, &$captured_status): string {
            $captured_location = $location;
            $captured_status = $status;
            throw new RuntimeException('retained-source-redirect-captured');
        };
        add_filter('wp_redirect', $redirect_capture, 10, 2);
        try {
            ll_tools_content_lesson_maybe_redirect_retained_source();
            $this->fail('Expected the retained-source redirect to be captured.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'retained-source-redirect-captured',
                $exception->getMessage()
            );
        } finally {
            remove_filter('wp_redirect', $redirect_capture, 10);
        }
        $this->assertSame($source_url, $captured_location);
        $this->assertSame(301, $captured_status);

        update_post_meta(
            $target_id,
            LL_TOOLS_CONTENT_LESSON_KIND_META,
            'corpus_text'
        );
        $this->assertSame(
            [],
            ll_tools_get_corpus_text_grid_lessons(['ids' => (string) $target_id])
        );
    }

    public function test_migration_round_trips_compatibility_metadata_and_skips_unchanged_post_writes(): void
    {
        $wordset_id = $this->createWordset('Legacy contract');
        $category_id = self::factory()->category->create([
            'name' => 'Legacy contract source',
        ]);
        $source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Legacy Contract Lesson',
            'post_content' => '<p>Contract content</p>',
            'menu_order' => 9,
        ]);
        wp_set_post_categories($source_id, [$category_id]);
        update_post_meta($source_id, '_lesson_level', '3');

        $first = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true, 'status' => 'publish']
        );
        $this->assertIsArray($first);
        $target_id = (int) $first['target_id'];
        $this->assertGreaterThan(0, $target_id);
        $this->assertSame(3, (int) get_post_field('menu_order', $target_id));
        $this->assertSame([$category_id], ll_tools_get_legacy_lesson_category_ids($target_id));
        $this->assertSame($wordset_id, ll_tools_get_legacy_lesson_default_wordset_id());
        $this->assertSame(
            (string) get_permalink($source_id),
            (string) get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META, true)
        );
        $this->assertSame(
            '1',
            (string) get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_MIGRATION_META, true)
        );

        wp_update_post([
            'ID' => $target_id,
            'post_status' => 'future',
            'post_date' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS),
        ]);
        $this->assertSame('future', get_post_status($target_id));
        delete_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_CATEGORY_ID_META);
        delete_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META);
        delete_option(LL_TOOLS_LEGACY_LESSON_DEFAULT_WORDSET_OPTION);
        $backfill = ll_tools_migrate_legacy_lesson_post(
            $source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($backfill);
        $this->assertTrue((bool) $backfill['changed']);
        $this->assertSame([$category_id], ll_tools_get_legacy_lesson_category_ids($target_id));
        $this->assertSame($wordset_id, ll_tools_get_legacy_lesson_default_wordset_id());
        $this->assertSame(
            (string) get_permalink($source_id),
            (string) get_post_meta($target_id, LL_TOOLS_LEGACY_LESSON_SOURCE_URL_META, true)
        );
        $this->assertSame('future', get_post_status($target_id));

        $content_lesson_writes = 0;
        $write_counter = static function (array $data) use (&$content_lesson_writes): array {
            if (($data['post_type'] ?? '') === 'll_content_lesson') {
                $content_lesson_writes++;
            }
            return $data;
        };
        add_filter('wp_insert_post_data', $write_counter);
        try {
            $unchanged = ll_tools_migrate_legacy_lesson_post(
                $source_id,
                $wordset_id,
                ['apply' => true]
            );
        } finally {
            remove_filter('wp_insert_post_data', $write_counter);
        }

        $this->assertIsArray($unchanged);
        $this->assertFalse((bool) $unchanged['changed']);
        $this->assertSame(0, $content_lesson_writes);
    }

    public function test_legacy_shims_use_migrated_targets_and_do_not_replace_an_existing_shortcode(): void
    {
        $wordset_id = $this->createWordset('Legacy compatibility');
        $category_id = self::factory()->category->create([
            'name' => 'Legacy compatibility source',
        ]);
        $first_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Compatibility First',
            'post_content' => '[custom_header][regex_linker]fallback[/regex_linker][custom_footer]',
        ]);
        $second_source_id = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Compatibility Second',
            'post_content' => '[regex_linker]raw second text[/regex_linker]',
        ]);
        wp_set_post_categories($first_source_id, [$category_id]);
        wp_set_post_categories($second_source_id, [$category_id]);
        update_post_meta(
            $first_source_id,
            '_processed_text_with_links',
            '<a href="https://example.test/safe">Cached link</a><script>bad()</script>'
        );
        update_post_meta(
            $second_source_id,
            '_processed_text_with_links',
            '<a href="' . esc_url((string) get_permalink($first_source_id)) . '">First lesson</a>'
        );

        foreach ([$first_source_id, $second_source_id] as $source_id) {
            $result = ll_tools_migrate_legacy_lesson_post(
                $source_id,
                $wordset_id,
                ['apply' => true, 'status' => 'publish']
            );
            $this->assertIsArray($result);
        }
        $first_target_id = ll_tools_find_content_lesson_by_legacy_source($first_source_id);
        $second_target_id = ll_tools_find_content_lesson_by_legacy_source($second_source_id);
        $relation_result = ll_tools_migrate_legacy_lesson_relations(
            $second_source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($relation_result);
        $this->assertSame(1, (int) $relation_result['rewritten_links']);
        $idempotent_lesson_result = ll_tools_migrate_legacy_lesson_post(
            $second_source_id,
            $wordset_id,
            ['apply' => true]
        );
        $this->assertIsArray($idempotent_lesson_result);
        $this->assertFalse((bool) $idempotent_lesson_result['changed']);
        update_post_meta(
            $second_target_id,
            LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
            [$first_target_id]
        );

        $GLOBALS['post'] = get_post($first_source_id);
        setup_postdata($GLOBALS['post']);
        try {
            wp_set_current_user(0);
            $tree = do_shortcode(
                '[display_prereq_tree categories="' . $category_id . '" per_page="10"]'
            );
            $header = do_shortcode('[custom_header]');
            $footer = do_shortcode('[custom_footer]');
            $linked = do_shortcode('[regex_linker]fallback[/regex_linker]');
            update_option('users_can_register', 1);
            update_option('ll_allow_learner_self_registration', 1);
            $signup = do_shortcode('[signup_link]');
        } finally {
            wp_reset_postdata();
        }

        $this->assertStringContainsString('Compatibility First', $tree);
        $this->assertStringContainsString('Compatibility Second', $tree);
        $this->assertTrue(wp_style_is('ll-tools-content-lesson-index', 'enqueued'));
        $this->assertStringContainsString('Log in to save lesson progress', $header);
        $this->assertStringContainsString(
            urlencode((string) get_permalink($first_target_id)),
            $header
        );
        $this->assertStringContainsString('Compatibility Second', $footer);
        $this->assertStringContainsString('Cached link', $linked);
        $this->assertStringNotContainsString('<script', $linked);
        $this->assertStringContainsString('Log in', $signup);
        $this->assertStringContainsString('register', $signup);
        $this->assertStringNotContainsString('<font', $signup);

        global $shortcode_tags;
        $original = $shortcode_tags['custom_header'] ?? null;
        remove_shortcode('custom_header');
        add_shortcode('custom_header', static function (): string {
            return 'legacy-owner';
        });
        ll_tools_register_content_lesson_index_shortcodes();
        $this->assertSame('legacy-owner', do_shortcode('[custom_header]'));
        remove_shortcode('custom_header');
        if ($original !== null) {
            $shortcode_tags['custom_header'] = $original;
        }
    }

    private function createWordset(string $label): int
    {
        $term = wp_insert_term(
            $label . ' ' . wp_generate_password(5, false),
            'wordset'
        );
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    /**
     * @param int[] $legacy_category_ids
     */
    private function createLesson(
        int $wordset_id,
        string $title,
        int $level,
        array $legacy_category_ids
    ): int {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_excerpt' => $title . ' excerpt',
            'menu_order' => $level,
        ]);
        update_post_meta(
            $lesson_id,
            LL_TOOLS_CONTENT_LESSON_WORDSET_META,
            (string) $wordset_id
        );
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'article');
        $this->assertTrue(
            ll_tools_set_legacy_lesson_category_ids($lesson_id, $legacy_category_ids)
        );
        return $lesson_id;
    }
}
