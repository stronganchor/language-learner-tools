<?php
declare(strict_types=1);

final class ContentLessonCueParsingTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->getBackup = $_GET;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_GET = $this->getBackup;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        parent::tearDown();
    }

    public function test_content_lesson_parses_supported_timing_formats(): void
    {
        $vtt = <<<VTT
WEBVTT

00:00:01.000 --> 00:00:03.500
First line

00:00:04.000 --> 00:00:06.000
Second line
VTT;

        $vtt_cues = ll_tools_content_lesson_parse_source($vtt, 'vtt');
        $this->assertIsArray($vtt_cues);
        $this->assertCount(2, $vtt_cues);
        $this->assertSame('First line', $vtt_cues[0]['text']);
        $this->assertSame(1000, $vtt_cues[0]['start_ms']);
        $this->assertSame(3500, $vtt_cues[0]['end_ms']);

        $tsv = <<<TSV
id\ttext_full\tstart_sec\tend_sec
1\tHello world\t1.168\t3.888
2\tSecond phrase\t4.000\t5.500
TSV;

        $tsv_cues = ll_tools_content_lesson_parse_source($tsv, 'tsv');
        $this->assertIsArray($tsv_cues);
        $this->assertCount(2, $tsv_cues);
        $this->assertSame('Hello world', $tsv_cues[0]['text']);
        $this->assertSame(1168, $tsv_cues[0]['start_ms']);

        $json = wp_json_encode([
            'lines' => [
                [
                    'start_sec' => 1.5,
                    'end_sec' => 2.75,
                    'text_projected' => 'Projected line',
                ],
            ],
        ]);

        $json_cues = ll_tools_content_lesson_parse_source((string) $json, 'json');
        $this->assertIsArray($json_cues);
        $this->assertCount(1, $json_cues);
        $this->assertSame('Projected line', $json_cues[0]['text']);
        $this->assertSame(1500, $json_cues[0]['start_ms']);
        $this->assertSame(2750, $json_cues[0]['end_ms']);
    }

    public function test_content_lesson_relationship_helpers_link_wordset_and_vocab_pages(): void
    {
        $wordset = wp_insert_term('Story Wordset', 'wordset', ['slug' => 'story-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Story Category', 'word-category', ['slug' => 'story-category']);
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];

        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);

        $vocab_lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Story Category Lesson',
        ]);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($vocab_lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category_id);

        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Story Lesson',
            'post_excerpt' => 'Main story lesson.',
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$category_id]);

        $wordset_lessons = ll_tools_get_content_lessons_for_wordset($wordset_id);
        $this->assertCount(1, $wordset_lessons);
        $this->assertSame($content_lesson_id, (int) $wordset_lessons[0]['id']);
        $this->assertSame(1, (int) $wordset_lessons[0]['category_count']);

        $related_to_vocab = ll_tools_get_content_lessons_for_vocab_lesson($wordset_id, $category_id);
        $this->assertCount(1, $related_to_vocab);
        $this->assertSame($content_lesson_id, (int) $related_to_vocab[0]['id']);

        $related_vocab_items = ll_tools_get_content_lesson_related_vocab_items($content_lesson_id);
        $this->assertCount(1, $related_vocab_items);
        $this->assertSame($category_id, (int) $related_vocab_items[0]['id']);
        $this->assertNotSame('', (string) $related_vocab_items[0]['url']);
    }

    public function test_vocab_lesson_related_content_query_is_category_targeted_and_bounded(): void
    {
        $wordset = wp_insert_term('Targeted Lesson Wordset', 'wordset', ['slug' => 'targeted-lesson-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $target_category = wp_insert_term('Targeted Lesson Category', 'word-category', ['slug' => 'targeted-lesson-category']);
        $other_category = wp_insert_term('Unrelated Lesson Category', 'word-category', ['slug' => 'unrelated-lesson-category']);
        $this->assertIsArray($target_category);
        $this->assertIsArray($other_category);
        $target_category_id = (int) $target_category['term_id'];
        $other_category_id = (int) $other_category['term_id'];

        for ($index = 1; $index <= 15; $index++) {
            $lesson_id = self::factory()->post->create([
                'post_type' => 'll_content_lesson',
                'post_status' => 'publish',
                'post_title' => 'Unrelated Content Lesson ' . $index,
            ]);
            update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
            update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$other_category_id]);
        }

        $matching_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Matching Content Lesson',
        ]);
        update_post_meta($matching_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($matching_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$target_category_id]);

        $query_limits = [];
        $query_meta = [];
        $query_watcher = static function (WP_Query $query) use (&$query_limits, &$query_meta): void {
            if ($query->get('post_type') !== 'll_content_lesson') {
                return;
            }

            $meta_query = $query->get('meta_query');
            $query_limits[] = (int) $query->get('posts_per_page');
            if (strpos((string) wp_json_encode($meta_query), LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META) !== false) {
                $query_meta[] = $meta_query;
            }
        };
        add_action('pre_get_posts', $query_watcher);
        try {
            $related_lessons = ll_tools_get_content_lessons_for_vocab_lesson($wordset_id, $target_category_id);
        } finally {
            remove_action('pre_get_posts', $query_watcher);
        }

        $this->assertCount(1, $related_lessons);
        $this->assertSame($matching_lesson_id, (int) $related_lessons[0]['id']);
        $this->assertSame([6], $query_limits);
        $this->assertNotContains(-1, $query_limits);
        $this->assertStringContainsString(LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, wp_json_encode($query_meta));
        $this->assertStringContainsString('i:' . $target_category_id . ';', wp_json_encode($query_meta));
    }

    public function test_orphan_published_content_lesson_is_404_on_frontend(): void
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Orphan Content Lesson',
        ]);

        $this->go_to('/?post_type=ll_content_lesson&p=' . (int) $lesson_id);
        $this->assertTrue(is_singular('ll_content_lesson'));

        $status = 0;
        $status_header_filter = static function ($status_header, $code) use (&$status) {
            $status = (int) $code;
            return $status_header;
        };

        add_filter('status_header', $status_header_filter, 10, 2);
        try {
            ll_tools_content_lesson_enforce_frontend_access();
        } finally {
            remove_filter('status_header', $status_header_filter, 10);
        }

        global $wp_query;
        $this->assertInstanceOf(WP_Query::class, $wp_query);
        $this->assertTrue((bool) $wp_query->is_404);
        $this->assertSame(404, $status);
    }

    public function test_orphan_corpus_text_is_public_and_excluded_from_wordset_lesson_grid(): void
    {
        $wordset = wp_insert_term('Corpus Wordset', 'wordset', ['slug' => 'corpus-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $standard_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Standard Lesson',
        ]);
        update_post_meta($standard_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($standard_lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'standard');

        $corpus_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Corpus Text',
            'post_name' => 'corpus-text',
            'post_excerpt' => 'Historical text excerpt.',
        ]);
        update_post_meta($corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');
        update_post_meta($corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META, 'lerch');
        update_post_meta($corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META, 'Peter Lerch');

        $second_corpus_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Second Corpus Text',
            'post_name' => 'second-corpus-text',
            'post_excerpt' => 'Second historical text excerpt.',
        ]);
        update_post_meta($second_corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');
        update_post_meta($second_corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($second_corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META, 'lerch');
        update_post_meta($second_corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META, 'Peter Lerch');

        $wordset_lessons = ll_tools_get_content_lessons_for_wordset($wordset_id);
        $this->assertCount(1, $wordset_lessons);
        $this->assertSame($standard_lesson_id, (int) $wordset_lessons[0]['id']);

        delete_post_meta($corpus_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META);
        $this->assertTrue(ll_tools_current_user_can_view_text_document($corpus_lesson_id));

        $this->go_to('/?post_type=ll_content_lesson&p=' . (int) $corpus_lesson_id);
        $this->assertTrue(is_singular('ll_content_lesson'));

        ll_tools_content_lesson_enforce_frontend_access();
        global $wp_query;
        $this->assertInstanceOf(WP_Query::class, $wp_query);
        $this->assertFalse((bool) $wp_query->is_404);

        $grid_html = do_shortcode('[ll_corpus_text_grid collection="lerch" title=""]');
        $this->assertStringContainsString('Corpus Text', $grid_html);
        $this->assertStringContainsString('Second Corpus Text', $grid_html);
        $this->assertStringContainsString('Historical text excerpt.', $grid_html);
        $this->assertStringContainsString('Open text', $grid_html);

        $filtered_grid_html = do_shortcode('[ll_corpus_text_grid collection="lerch" slugs="corpus-text" title=""]');
        $this->assertStringContainsString('Corpus Text', $filtered_grid_html);
        $this->assertStringNotContainsString('Second Corpus Text', $filtered_grid_html);
    }

    public function test_corpus_text_grid_defaults_to_bounded_page_and_supports_paged_results(): void
    {
        $collection = 'bounded-grid-' . strtolower(wp_generate_password(6, false));
        $limit_filter = static function (): int {
            return 2;
        };

        add_filter('ll_tools_corpus_text_grid_default_limit', $limit_filter);
        try {
            for ($index = 1; $index <= 5; $index++) {
                $this->createPublishedCorpusText(
                    'Corpus Grid Text ' . $index,
                    $collection,
                    $index
                );
            }

            $grid_html = do_shortcode('[ll_corpus_text_grid collection="' . esc_attr($collection) . '" title=""]');
            $this->assertSame(2, substr_count($grid_html, '<article class="ll-content-lesson-card'));
            $this->assertStringContainsString('Corpus Grid Text 1', $grid_html);
            $this->assertStringContainsString('Corpus Grid Text 2', $grid_html);
            $this->assertStringNotContainsString('Corpus Grid Text 3', $grid_html);
            $this->assertStringContainsString('ll-corpus-text-grid__pagination', $grid_html);

            $second_page = ll_tools_get_corpus_text_grid_query_result([
                'collection' => $collection,
                'page' => 2,
            ]);
            $this->assertSame(5, (int) ($second_page['total'] ?? 0));
            $this->assertSame(3, (int) ($second_page['total_pages'] ?? 0));
            $this->assertTrue((bool) ($second_page['has_previous_page'] ?? false));
            $this->assertTrue((bool) ($second_page['has_next_page'] ?? false));
            $this->assertSame(
                ['Corpus Grid Text 3', 'Corpus Grid Text 4'],
                array_column((array) ($second_page['lessons'] ?? []), 'title')
            );

            $all_results = ll_tools_get_corpus_text_grid_query_result([
                'collection' => $collection,
                'limit' => '-1',
            ]);
            $this->assertCount(5, (array) ($all_results['lessons'] ?? []));
        } finally {
            remove_filter('ll_tools_corpus_text_grid_default_limit', $limit_filter);
        }
    }

    public function test_corpus_collection_page_lookup_is_bounded_cached_and_invalidates_on_save_and_delete(): void
    {
        $suffix = strtolower(wp_generate_password(8, false));
        $first_collection = 'indexed-corpus-' . $suffix;
        $second_collection = 'moved-corpus-' . $suffix;
        $first_lesson_id = $this->createPublishedCorpusText('Indexed Corpus Lesson', $first_collection, 1);
        $second_lesson_id = $this->createPublishedCorpusText('Moved Corpus Lesson', $second_collection, 2);
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Indexed Corpus Page ' . $suffix,
            'post_content' => "[ll_text_document_grid title=\"Fixture\" collection = '" . $first_collection . "']",
        ]);

        $this->assertSame(
            [$first_collection],
            ll_tools_get_corpus_text_collection_page_index($page_id)
        );

        $page_queries = [];
        $query_watcher = static function (WP_Query $query) use (&$page_queries): void {
            if ($query->get('post_type') !== 'page'
                || $query->get('meta_key') !== ll_tools_corpus_text_collection_page_index_meta_key()) {
                return;
            }

            $page_queries[] = [
                'posts_per_page' => (int) $query->get('posts_per_page'),
                'fields' => (string) $query->get('fields'),
                'no_found_rows' => (bool) $query->get('no_found_rows'),
            ];
        };
        add_action('pre_get_posts', $query_watcher);
        try {
            $missing_link = ll_tools_get_corpus_text_collection_link($second_lesson_id);
            $first_link = ll_tools_get_corpus_text_collection_link($first_lesson_id);
        } finally {
            remove_action('pre_get_posts', $query_watcher);
        }

        $this->assertSame('', (string) ($missing_link['url'] ?? ''));
        $this->assertSame((string) get_permalink($page_id), (string) ($first_link['url'] ?? ''));
        $this->assertCount(2, $page_queries);
        foreach ($page_queries as $query_args) {
            $this->assertSame(1, $query_args['posts_per_page']);
            $this->assertSame('ids', $query_args['fields']);
            $this->assertTrue($query_args['no_found_rows']);
        }

        $cached_query_count = 0;
        $cached_query_watcher = static function (WP_Query $query) use (&$cached_query_count): void {
            if ($query->get('post_type') === 'page'
                && $query->get('meta_key') === ll_tools_corpus_text_collection_page_index_meta_key()) {
                $cached_query_count++;
            }
        };
        add_action('pre_get_posts', $cached_query_watcher);
        try {
            $cached_link = ll_tools_get_corpus_text_collection_link($first_lesson_id);
            $cached_missing_link = ll_tools_get_corpus_text_collection_link($second_lesson_id);
        } finally {
            remove_action('pre_get_posts', $cached_query_watcher);
        }
        $this->assertSame((string) get_permalink($page_id), (string) ($cached_link['url'] ?? ''));
        $this->assertSame('', (string) ($cached_missing_link['url'] ?? ''));
        $this->assertSame(0, $cached_query_count);

        $updated = wp_update_post([
            'ID' => $page_id,
            'post_content' => '[ll_corpus_text_grid collection="' . $second_collection . '"]',
        ], true);
        $this->assertNotWPError($updated);
        $this->assertFalse(get_transient(ll_tools_corpus_text_collection_page_cache_key($first_collection)));
        $this->assertFalse(get_transient(ll_tools_corpus_text_collection_page_cache_key($second_collection)));
        $this->assertSame([$second_collection], ll_tools_get_corpus_text_collection_page_index($page_id));

        $this->assertSame('', (string) (ll_tools_get_corpus_text_collection_link($first_lesson_id)['url'] ?? ''));
        $this->assertSame(
            (string) get_permalink($page_id),
            (string) (ll_tools_get_corpus_text_collection_link($second_lesson_id)['url'] ?? '')
        );

        $this->assertNotFalse(wp_delete_post($page_id, true));
        $this->assertFalse(get_transient(ll_tools_corpus_text_collection_page_cache_key($second_collection)));
        $this->assertSame('', (string) (ll_tools_get_corpus_text_collection_link($second_lesson_id)['url'] ?? ''));

        delete_transient(ll_tools_corpus_text_collection_page_cache_key($first_collection));
        delete_transient(ll_tools_corpus_text_collection_page_cache_key($second_collection));
    }

    public function test_corpus_collection_page_lookup_lazily_backfills_pre_index_pages_with_a_bounded_query(): void
    {
        $collection = 'legacy-corpus-' . strtolower(wp_generate_password(8, false));
        $lesson_id = $this->createPublishedCorpusText('Legacy Corpus Lesson', $collection, 1);
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Legacy Corpus Collection',
            'post_content' => "[ll_corpus_text_grid collection  =  '" . $collection . "']",
        ]);
        delete_post_meta($page_id, ll_tools_corpus_text_collection_page_index_meta_key());
        delete_transient(ll_tools_corpus_text_collection_page_cache_key($collection));

        $page_limits = [];
        $legacy_fallback_queries = [];
        $query_watcher = static function (WP_Query $query) use (&$page_limits): void {
            if ($query->get('post_type') === 'page') {
                $page_limits[] = (int) $query->get('posts_per_page');
            }
        };
        $sql_watcher = static function (string $sql) use (&$legacy_fallback_queries): string {
            if (strpos($sql, 'post_content REGEXP') !== false
                && strpos($sql, "post_type = 'page'") !== false) {
                $legacy_fallback_queries[] = $sql;
            }
            return $sql;
        };
        add_action('pre_get_posts', $query_watcher);
        add_filter('query', $sql_watcher);
        try {
            $link = ll_tools_get_corpus_text_collection_link($lesson_id);
        } finally {
            remove_action('pre_get_posts', $query_watcher);
            remove_filter('query', $sql_watcher);
        }

        $this->assertSame((string) get_permalink($page_id), (string) ($link['url'] ?? ''));
        $this->assertSame([1], $page_limits);
        $this->assertNotContains(-1, $page_limits);
        $this->assertCount(1, $legacy_fallback_queries);
        $this->assertStringContainsString('LIMIT 20', $legacy_fallback_queries[0]);
        $this->assertSame([$collection], ll_tools_get_corpus_text_collection_page_index($page_id));

        wp_delete_post($page_id, true);
        delete_transient(ll_tools_corpus_text_collection_page_cache_key($collection));
    }

    public function test_content_lesson_category_rows_scope_to_selected_wordset(): void
    {
        $fixture = $this->createScopedCategoryFixture();

        $rows = ll_tools_get_content_lesson_selectable_category_rows(
            (int) $fixture['wordset_one_id'],
            [(int) $fixture['isolated_two_id']]
        );

        $row_ids = array_values(array_map(static function (array $row): int {
            return (int) ($row['id'] ?? 0);
        }, $rows));

        $this->assertContains((int) $fixture['isolated_one_id'], $row_ids);
        $this->assertNotContains((int) $fixture['isolated_two_id'], $row_ids);
    }

    public function test_content_lesson_admin_option_pages_are_bounded_and_keep_selected_rows(): void
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $wordset_id = $this->ensureTerm(
            'wordset',
            'Paged Lesson Options ' . wp_generate_password(5, false),
            'paged-lesson-options-' . wp_generate_password(5, false)
        );
        $category_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $category_id = $this->ensureTerm(
                'word-category',
                sprintf('Paged Lesson Category %02d', $index),
                'paged-lesson-category-' . $index . '-' . wp_generate_password(4, false)
            );
            $this->createWordInScope('Paged Lesson Word ' . $index, $wordset_id, $category_id);
            $category_ids[] = $category_id;
        }

        $first_category_page = ll_tools_content_lesson_option_page('categories', $wordset_id, ['limit' => 2]);
        $this->assertCount(2, (array) $first_category_page['rows']);
        $this->assertTrue((bool) $first_category_page['has_more']);
        $this->assertSame(2, (int) $first_category_page['next_offset']);

        $second_category_page = ll_tools_content_lesson_option_page('categories', $wordset_id, [
            'limit' => 2,
            'offset' => 2,
        ]);
        $this->assertCount(2, (array) $second_category_page['rows']);
        $this->assertNotSame(
            wp_list_pluck((array) $first_category_page['rows'], 'id'),
            wp_list_pluck((array) $second_category_page['rows'], 'id')
        );

        $selected_category_page = ll_tools_content_lesson_option_page('categories', $wordset_id, [
            'limit' => 2,
            'selected_ids' => [$category_ids[4]],
        ]);
        $this->assertContains($category_ids[4], array_map('intval', wp_list_pluck((array) $selected_category_page['rows'], 'id')));
        $this->assertLessThanOrEqual(3, count((array) $selected_category_page['rows']));

        $lesson_ids = [];
        for ($index = 1; $index <= 5; $index++) {
            $lesson_ids[] = $this->createPublishedContentLesson(
                $wordset_id,
                sprintf('Paged Prerequisite Lesson %02d', $index),
                []
            );
        }

        $query_limits = [];
        $watch_queries = static function (WP_Query $query) use (&$query_limits): void {
            if ($query->get('post_type') === 'll_content_lesson') {
                $query_limits[] = (int) $query->get('posts_per_page');
            }
        };
        add_action('pre_get_posts', $watch_queries);
        try {
            $lesson_page = ll_tools_content_lesson_option_page('prereq_lessons', $wordset_id, [
                'limit' => 2,
                'selected_ids' => [$lesson_ids[4]],
                'exclude_lesson_id' => $lesson_ids[0],
            ]);
        } finally {
            remove_action('pre_get_posts', $watch_queries);
        }

        $this->assertTrue((bool) $lesson_page['has_more']);
        $this->assertContains($lesson_ids[4], array_map('intval', wp_list_pluck((array) $lesson_page['rows'], 'id')));
        $this->assertNotContains(-1, $query_limits);
        $this->assertLessThanOrEqual(3, max($query_limits));
    }

    public function test_content_lesson_admin_localizes_only_the_active_wordset_page(): void
    {
        global $typenow;

        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);
        $this->setCurrentUserWithViewCapability();
        $active_wordset_id = $this->ensureTerm(
            'wordset',
            'Active Admin Lesson ' . wp_generate_password(5, false),
            'active-admin-lesson-' . wp_generate_password(5, false)
        );
        $other_wordset_id = $this->ensureTerm(
            'wordset',
            'Other Admin Lesson ' . wp_generate_password(5, false),
            'other-admin-lesson-' . wp_generate_password(5, false)
        );
        $active_category_id = $this->ensureTerm(
            'word-category',
            'Active Admin Category ' . wp_generate_password(5, false),
            'active-admin-category-' . wp_generate_password(5, false)
        );
        $other_category_id = $this->ensureTerm(
            'word-category',
            'Other Admin Category ' . wp_generate_password(5, false),
            'other-admin-category-' . wp_generate_password(5, false)
        );
        $this->createWordInScope('Active Admin Word', $active_wordset_id, $active_category_id);
        $this->createWordInScope('Other Admin Word', $other_wordset_id, $other_category_id);

        $lesson_id = $this->createPublishedContentLesson(
            $active_wordset_id,
            'Active Admin Content Lesson',
            [$active_category_id]
        );
        $previous_typenow = $typenow;
        $typenow = 'll_content_lesson';
        $_GET['post'] = (string) $lesson_id;
        wp_dequeue_script('ll-tools-content-lesson-admin');
        wp_deregister_script('ll-tools-content-lesson-admin');

        try {
            ll_tools_enqueue_content_lesson_admin_assets('post.php');
            $localized = (string) wp_scripts()->get_data('ll-tools-content-lesson-admin', 'data');
        } finally {
            $typenow = $previous_typenow;
        }

        $this->assertMatchesRegularExpression('/var llContentLessonAdminData = \{.*\};/s', $localized);
        preg_match('/var llContentLessonAdminData = (\{.*\});/s', $localized, $matches);
        $payload = json_decode((string) ($matches[1] ?? ''), true);
        $this->assertIsArray($payload);
        $this->assertSame([$active_wordset_id], array_keys((array) ($payload['rowsByWordset'] ?? [])));
        $this->assertSame([$active_wordset_id], array_keys((array) ($payload['prereqRowsByWordset'] ?? [])));
        $this->assertSame([$active_wordset_id], array_keys((array) ($payload['prereqLessonRowsByWordset'] ?? [])));
        $this->assertArrayNotHasKey((string) $other_wordset_id, (array) ($payload['rowsByWordset'] ?? []));
        $this->assertLessThanOrEqual(40, count((array) ($payload['rowsByWordset'][(string) $active_wordset_id] ?? [])));
    }

    public function test_content_lesson_save_remaps_selected_categories_into_current_wordset_sandbox(): void
    {
        $fixture = $this->createScopedCategoryFixture();
        $this->setCurrentUserWithViewCapability();

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_title' => 'Scoped Content Lesson',
        ]);
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $fixture['wordset_one_id'],
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/story.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $fixture['isolated_two_id']],
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $saved_category_ids = get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, true);
        $saved_category_ids = is_array($saved_category_ids) ? array_values(array_map('intval', $saved_category_ids)) : [];

        $this->assertSame([(int) $fixture['isolated_one_id']], $saved_category_ids);
    }

    public function test_content_lesson_save_cannot_move_lesson_to_unmanaged_wordset(): void
    {
        ll_create_wordset_manager_role();
        ll_ensure_wordset_manager_has_view_ll_tools_cap();
        $manager_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $managed = wp_insert_term('Managed Content Lessons ' . wp_generate_password(5, false), 'wordset');
        $unmanaged = wp_insert_term('Unmanaged Content Lessons ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($managed);
        $this->assertIsArray($unmanaged);
        $managed_id = (int) ($managed['term_id'] ?? 0);
        $unmanaged_id = (int) ($unmanaged['term_id'] ?? 0);
        ll_tools_set_wordset_manager_user_ids($managed_id, [$manager_id], $manager_id);
        wp_set_current_user($manager_id);

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_author' => $manager_id,
            'post_title' => 'Manager Scoped Content Lesson',
        ]);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $managed_id);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, 'https://example.com/original.mp3');
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);
        $this->assertTrue(current_user_can('edit_post', $lesson_id));

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $unmanaged_id,
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/unauthorized.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertSame($managed_id, ll_tools_get_content_lesson_wordset_id($lesson_id));
        $this->assertSame('https://example.com/original.mp3', (string) get_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_URL_META, true));
    }

    public function test_content_lesson_save_filters_mixed_grid_prerequisites_to_quizzable_wordset_lessons(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $this->setCurrentUserWithViewCapability();

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_title' => 'Mixed Grid Story',
        ]);
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $fixture['wordset_id'],
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/mixed-grid-story.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $fixture['category_c_id']],
            'll_content_lesson_show_in_mix' => '1',
            'll_content_lesson_prereq_category_ids' => [
                (string) $fixture['category_a_id'],
                (string) $fixture['non_quizzable_category_id'],
            ],
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertTrue(ll_tools_get_content_lesson_show_in_mix($lesson_id));
        $this->assertSame(
            [(int) $fixture['category_a_id']],
            ll_tools_get_content_lesson_prereq_category_ids($lesson_id)
        );
    }

    public function test_content_lesson_save_filters_content_lesson_prerequisites_to_same_wordset_lessons(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $this->setCurrentUserWithViewCapability();

        $valid_prereq_lesson_id = $this->createPublishedContentLesson(
            (int) $fixture['wordset_id'],
            'Earlier Story',
            [(int) $fixture['category_b_id']],
            [
                'show_in_mix' => true,
                'prereq_category_ids' => [(int) $fixture['category_a_id']],
            ]
        );

        $other_wordset = wp_insert_term('Other Content Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($other_wordset);
        $other_wordset_id = (int) ($other_wordset['term_id'] ?? 0);
        $invalid_prereq_lesson_id = $this->createPublishedContentLesson(
            $other_wordset_id,
            'Wrong Wordset Story',
            []
        );

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'draft',
            'post_title' => 'Follow-Up Story',
        ]);
        $lesson = get_post($lesson_id);
        $this->assertInstanceOf(WP_Post::class, $lesson);

        $_POST = [
            'll_tools_content_lesson_nonce' => wp_create_nonce('ll_tools_content_lesson_save'),
            'll_content_lesson_wordset_id' => (string) $fixture['wordset_id'],
            'll_content_lesson_media_type' => 'audio',
            'll_content_lesson_media_url' => 'https://example.com/follow-up-story.mp3',
            'll_content_lesson_transcript_format' => 'auto',
            'll_content_lesson_transcript_source' => '',
            'll_content_lesson_category_ids' => [(string) $fixture['category_c_id']],
            'll_content_lesson_show_in_mix' => '1',
            'll_content_lesson_prereq_lesson_ids' => [
                (string) $valid_prereq_lesson_id,
                (string) $invalid_prereq_lesson_id,
                (string) $lesson_id,
            ],
        ];

        ll_tools_save_content_lesson_metabox($lesson_id, $lesson);

        $this->assertSame(
            [$valid_prereq_lesson_id],
            ll_tools_get_content_lesson_prereq_lesson_ids($lesson_id)
        );
    }

    public function test_wordset_page_renders_mixed_content_lessons_between_vocab_cards(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $content_lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Bravo Story Bridge',
            'post_excerpt' => 'Review the story before the final vocab drill.',
            'menu_order' => 5,
        ]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, 'audio');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$fixture['category_c_id']]);
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
        update_post_meta($content_lesson_id, LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META, [
            $fixture['category_a_id'],
            $fixture['category_b_id'],
        ]);

        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id > 0 && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            try {
                $html = ll_tools_render_wordset_page_content($wordset_id, [
                    'show_title' => false,
                    'wrapper_tag' => 'div',
                ]);
            } finally {
                $_GET = $original_get;
                set_query_var('ll_wordset_page', $original_wordset_page);
                set_query_var('ll_wordset_view', $original_wordset_view);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }

        $alpha_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_a_id'] . '"');
        $bravo_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_b_id'] . '"');
        $content_pos = strpos($html, 'data-lesson-id="' . (int) $content_lesson_id . '"');
        $charlie_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_c_id'] . '"');

        $this->assertNotFalse($alpha_pos);
        $this->assertNotFalse($bravo_pos);
        $this->assertNotFalse($content_pos);
        $this->assertNotFalse($charlie_pos);
        $this->assertTrue($alpha_pos < $bravo_pos);
        $this->assertTrue($bravo_pos < $content_pos);
        $this->assertTrue($content_pos < $charlie_pos);
        $this->assertStringContainsString('ll-wordset-card ll-wordset-card--content', $html);
    }

    public function test_wordset_can_hide_content_lessons_from_home_without_hiding_bounded_index(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $featured_lesson_id = $this->createPublishedContentLesson(
            $wordset_id,
            'Hidden Featured Content Lesson',
            [(int) $fixture['category_a_id']],
            ['menu_order' => 1]
        );
        $mixed_lesson_id = $this->createPublishedContentLesson(
            $wordset_id,
            'Hidden Mixed Content Lesson',
            [(int) $fixture['category_b_id']],
            ['menu_order' => 2, 'show_in_mix' => true]
        );

        $this->assertTrue(ll_tools_wordset_should_show_content_lessons($wordset_id));
        update_term_meta(
            $wordset_id,
            LL_TOOLS_WORDSET_SHOW_CONTENT_LESSONS_META_KEY,
            '0'
        );
        $this->assertFalse(ll_tools_wordset_should_show_content_lessons($wordset_id));

        $content_lesson_queries = 0;
        $capture_queries = static function (WP_Query $query) use (&$content_lesson_queries): void {
            $post_type = $query->get('post_type');
            $post_types = is_array($post_type) ? array_map('strval', $post_type) : [(string) $post_type];
            if (in_array('ll_content_lesson', $post_types, true)) {
                $content_lesson_queries++;
            }
        };
        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');
        add_action('pre_get_posts', $capture_queries);
        try {
            $html = ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
            $fallback_payload = ll_tools_wordset_page_build_lazy_cards_fallback_payload(
                $wordset_id,
                2
            );
        } finally {
            remove_action('pre_get_posts', $capture_queries);
            $_GET = $original_get;
            set_query_var('ll_wordset_page', $original_wordset_page);
            set_query_var('ll_wordset_view', $original_wordset_view);
        }

        $this->assertSame(0, $content_lesson_queries);
        $this->assertStringContainsString(
            'data-cat-id="' . (int) $fixture['category_a_id'] . '"',
            $html
        );
        $this->assertStringNotContainsString('Main Lessons', $html);
        $this->assertStringNotContainsString('Hidden Featured Content Lesson', $html);
        $this->assertStringNotContainsString('Hidden Mixed Content Lesson', $html);
        $fallback_category_ids = array_values(array_filter(array_map(
            static function (array $card): int {
                return (string) ($card['type'] ?? '') === 'category'
                    ? (int) ($card['data']['id'] ?? 0)
                    : 0;
            },
            (array) ($fallback_payload['cards'] ?? [])
        )));
        $this->assertContains((int) $fixture['category_a_id'], $fallback_category_ids);
        $this->assertContains((int) $fixture['category_b_id'], $fallback_category_ids);
        $this->assertNotContains(
            'content',
            array_map(
                static function (array $card): string {
                    return (string) ($card['type'] ?? '');
                },
                (array) ($fallback_payload['cards'] ?? [])
            )
        );

        $index_page = ll_tools_get_content_lesson_index_page(
            $wordset_id,
            [],
            1,
            10
        );
        $this->assertIsArray($index_page);
        $indexed_ids = array_map(
            static function (WP_Post $post): int {
                return (int) $post->ID;
            },
            (array) ($index_page['posts'] ?? [])
        );
        $this->assertContains($featured_lesson_id, $indexed_ids);
        $this->assertContains($mixed_lesson_id, $indexed_ids);

        update_term_meta(
            $wordset_id,
            LL_TOOLS_WORDSET_SHOW_CONTENT_LESSONS_META_KEY,
            '1'
        );
        $this->assertTrue(ll_tools_wordset_should_show_content_lessons($wordset_id));
    }

    public function test_non_main_wordset_view_does_not_query_content_lesson_cards(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $wordsetId = (int) $fixture['wordset_id'];
        $wordsetTerm = get_term($wordsetId, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordsetTerm);

        for ($index = 1; $index <= 20; $index++) {
            $this->createPublishedContentLesson(
                $wordsetId,
                'Non Main Lesson ' . $index,
                [(int) $fixture['category_c_id']],
                ['show_in_mix' => ($index % 2) === 0]
            );
        }

        $contentLessonQueries = 0;
        $captureQueries = static function (WP_Query $query) use (&$contentLessonQueries): void {
            $postType = $query->get('post_type');
            $postTypes = is_array($postType) ? array_map('strval', $postType) : [(string) $postType];
            if (in_array('ll_content_lesson', $postTypes, true)) {
                $contentLessonQueries++;
            }
        };
        $originalGet = $_GET;
        $originalWordsetPage = get_query_var('ll_wordset_page');
        $originalWordsetView = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordsetTerm->slug);
        set_query_var('ll_wordset_view', 'hidden-categories');
        add_action('pre_get_posts', $captureQueries);
        try {
            $html = ll_tools_render_wordset_page_content($wordsetId, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            remove_action('pre_get_posts', $captureQueries);
            $_GET = $originalGet;
            set_query_var('ll_wordset_page', $originalWordsetPage);
            set_query_var('ll_wordset_view', $originalWordsetView);
        }

        $this->assertSame(0, $contentLessonQueries);
        $this->assertStringNotContainsString('Non Main Lesson', $html);
        $this->assertStringContainsString('ll-wordset-page--hidden-categories', $html);
    }

    public function test_wordset_page_renders_content_lesson_prerequisites_in_sequence(): void
    {
        $fixture = $this->createMixedLessonFixture();
        $wordset_id = (int) $fixture['wordset_id'];
        $wordset_term = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset_term);

        $bridge_lesson_id = $this->createPublishedContentLesson(
            $wordset_id,
            'Bridge Story',
            [(int) $fixture['category_c_id']],
            [
                'show_in_mix' => true,
                'prereq_category_ids' => [(int) $fixture['category_b_id']],
                'menu_order' => 5,
                'excerpt' => 'Story first.',
            ]
        );
        $practice_lesson_id = $this->createPublishedContentLesson(
            $wordset_id,
            'Bridge Practice',
            [(int) $fixture['category_c_id']],
            [
                'show_in_mix' => true,
                'prereq_lesson_ids' => [$bridge_lesson_id],
                'menu_order' => 10,
                'excerpt' => 'Practice second.',
            ]
        );

        $bootstrap_filter = static function ($should_bootstrap, $view, $filter_wordset_id): bool {
            if ((int) $filter_wordset_id > 0 && (string) $view === 'main') {
                return false;
            }
            return (bool) $should_bootstrap;
        };
        add_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10, 4);

        $original_get = $_GET;
        $original_wordset_page = get_query_var('ll_wordset_page');
        $original_wordset_view = get_query_var('ll_wordset_view');
        $_GET = [];
        set_query_var('ll_wordset_page', (string) $wordset_term->slug);
        set_query_var('ll_wordset_view', '');

        try {
            try {
                $html = ll_tools_render_wordset_page_content($wordset_id, [
                    'show_title' => false,
                    'wrapper_tag' => 'div',
                ]);
            } finally {
                $_GET = $original_get;
                set_query_var('ll_wordset_page', $original_wordset_page);
                set_query_var('ll_wordset_view', $original_wordset_view);
            }
        } finally {
            remove_filter('ll_tools_wordset_page_bootstrap_analytics', $bootstrap_filter, 10);
        }

        $bravo_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_b_id'] . '"');
        $bridge_pos = strpos($html, 'data-lesson-id="' . $bridge_lesson_id . '"');
        $practice_pos = strpos($html, 'data-lesson-id="' . $practice_lesson_id . '"');
        $charlie_pos = strpos($html, 'data-cat-id="' . (int) $fixture['category_c_id'] . '"');

        $this->assertNotFalse($bravo_pos);
        $this->assertNotFalse($bridge_pos);
        $this->assertNotFalse($practice_pos);
        $this->assertNotFalse($charlie_pos);
        $this->assertTrue($bravo_pos < $bridge_pos);
        $this->assertTrue($bridge_pos < $practice_pos);
        $this->assertTrue($practice_pos < $charlie_pos);
    }

    public function test_content_lesson_metabox_renders_translated_media_url_placeholder(): void
    {
        $messages = require LL_TOOLS_BASE_PATH . 'languages/ll-tools-text-domain-tr_TR.l10n.php';
        $this->assertIsArray($messages);
        $this->assertArrayHasKey('messages', $messages);
        $this->assertSame('Doğrudan medya URL\'sini buraya yapıştır.', $messages['messages']['Paste the direct media URL here.'] ?? null);
    }

    /**
     * @return array<string,int>
     */
    private function createScopedCategoryFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);

        $wordset_one_id = $this->ensureTerm('wordset', 'Lesson Scope One', 'lesson-scope-one');
        $wordset_two_id = $this->ensureTerm('wordset', 'Lesson Scope Two', 'lesson-scope-two');
        $source_category_id = $this->ensureTerm('word-category', 'Lesson Shared Trees', 'lesson-shared-trees');

        $isolated_one_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_one_id);
        $isolated_two_id = (int) ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_two_id);

        $this->createWordInScope('Lesson Scope One Tree', $wordset_one_id, $isolated_one_id);
        $this->createWordInScope('Lesson Scope Two Tree', $wordset_two_id, $isolated_two_id);

        return [
            'wordset_one_id' => $wordset_one_id,
            'wordset_two_id' => $wordset_two_id,
            'isolated_one_id' => $isolated_one_id,
            'isolated_two_id' => $isolated_two_id,
        ];
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

    private function createWordInScope(string $title, int $wordset_id, int $category_id): int
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

    /**
     * @return array{wordset_id:int,category_a_id:int,category_b_id:int,category_c_id:int,non_quizzable_category_id:int}
     */
    private function createMixedLessonFixture(): array
    {
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '0', false);

        $wordset = wp_insert_term('Mixed Lesson Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $this->assertFalse(is_wp_error($wordset));
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        update_option('ll_vocab_lesson_wordsets', [$wordset_id], false);

        $category_a_id = $this->ensureTerm('word-category', 'Alpha Lesson ' . wp_generate_password(4, false), 'alpha-lesson-' . wp_generate_password(4, false));
        $category_b_id = $this->ensureTerm('word-category', 'Bravo Lesson ' . wp_generate_password(4, false), 'bravo-lesson-' . wp_generate_password(4, false));
        $category_c_id = $this->ensureTerm('word-category', 'Charlie Lesson ' . wp_generate_password(4, false), 'charlie-lesson-' . wp_generate_password(4, false));
        $non_quizzable_category_id = $this->ensureTerm('word-category', 'Sparse Lesson ' . wp_generate_password(4, false), 'sparse-lesson-' . wp_generate_password(4, false));

        foreach ([$category_a_id, $category_b_id, $category_c_id, $non_quizzable_category_id] as $category_id) {
            update_term_meta($category_id, 'll_quiz_prompt_type', 'audio');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        }

        $this->createVocabLessonFixturePosts($wordset_id, $category_a_id, 'Alpha');
        $this->createVocabLessonFixturePosts($wordset_id, $category_b_id, 'Bravo');
        $this->createVocabLessonFixturePosts($wordset_id, $category_c_id, 'Charlie');

        for ($index = 1; $index <= 2; $index++) {
            $this->createWordWithAudio(
                'Sparse Word ' . $index,
                'Sparse Translation ' . $index,
                $non_quizzable_category_id,
                $wordset_id,
                'sparse-word-' . $index . '.mp3'
            );
        }

        return [
            'wordset_id' => $wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'category_c_id' => $category_c_id,
            'non_quizzable_category_id' => $non_quizzable_category_id,
        ];
    }

    private function createVocabLessonFixturePosts(int $wordset_id, int $category_id, string $prefix): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->createWordWithAudio(
                $prefix . ' Word ' . $index,
                $prefix . ' Translation ' . $index,
                $category_id,
                $wordset_id,
                strtolower($prefix) . '-word-' . $index . '.mp3'
            );
        }

        $result = ll_tools_get_or_create_vocab_lesson_page($category_id, $wordset_id);
        $this->assertIsArray($result);
        $this->assertNotEmpty((int) ($result['post_id'] ?? 0));
    }

    private function createPublishedContentLesson(int $wordset_id, string $title, array $category_ids, array $args = []): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_excerpt' => (string) ($args['excerpt'] ?? ''),
            'menu_order' => isset($args['menu_order']) ? (int) $args['menu_order'] : 0,
        ]);

        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_MEDIA_TYPE_META, (string) ($args['media_type'] ?? 'audio'));
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, array_values(array_map('intval', $category_ids)));

        if (!empty($args['show_in_mix'])) {
            update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_SHOW_IN_MIX_META, '1');
        }

        if (!empty($args['prereq_category_ids'])) {
            update_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_CATEGORY_IDS_META,
                array_values(array_map('intval', (array) $args['prereq_category_ids']))
            );
        }

        if (!empty($args['prereq_lesson_ids'])) {
            update_post_meta(
                $lesson_id,
                LL_TOOLS_CONTENT_LESSON_PREREQ_LESSON_IDS_META,
                array_values(array_map('intval', (array) $args['prereq_lesson_ids']))
            );
        }

        return $lesson_id;
    }

    private function createPublishedCorpusText(string $title, string $collection, int $menu_order): int
    {
        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_excerpt' => $title . ' excerpt.',
            'menu_order' => $menu_order,
        ]);

        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_KIND_META, 'corpus_text');
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CORPUS_COLLECTION_META, $collection);
        update_post_meta($lesson_id, LL_TOOLS_CONTENT_LESSON_CORPUS_SOURCE_AUTHOR_META, 'Regression Author');

        return $lesson_id;
    }

    private function createWordWithAudio(string $title, string $translation, int $category_id, int $wordset_id, string $audio_file_name): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', $translation);

        $audio_post_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_post_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return $word_id;
    }

    private function setCurrentUserWithViewCapability(): void
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);
        wp_set_current_user($user_id);
    }
}
