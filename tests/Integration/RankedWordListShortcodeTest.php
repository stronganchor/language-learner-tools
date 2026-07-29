<?php
declare(strict_types=1);

final class RankedWordListShortcodeTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private array $originalGet = [];
    private WP_Term $wordset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalGet = $_GET;
        unset($GLOBALS['ll_tools_public_assets_needed']);
        $term = wp_insert_term(
            'Ranked list wordset ' . wp_generate_password(5, false),
            'wordset'
        );
        $this->assertIsArray($term);
        $wordset = get_term((int) $term['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);
        $this->wordset = $wordset;
    }

    protected function tearDown(): void
    {
        $_GET = $this->originalGet;
        unset($GLOBALS['ll_tools_public_assets_needed']);
        parent::tearDown();
    }

    public function test_shortcode_orders_one_bounded_page_by_numeric_rank_with_stable_ties(): void
    {
        $category = $this->createCategory('Ranked list ordering');
        $this->createRankedWord($category, 'Rank Thirty', 30, 'thirty');
        $twentyA = $this->createRankedWord($category, 'Rank Twenty A', 20, 'twenty a');
        $ten = $this->createRankedWord($category, 'Rank Ten', 10, 'ten');
        $this->createRankedWord($category, 'Rank Twenty B', 20, 'twenty b');
        $this->createRankedWord($category, 'Rank Forty', 40, 'forty');
        $unranked = $this->createRankedWord($category, 'Rank Missing', null, 'missing');

        $outside = $this->createCategory('Ranked list outside');
        $this->createRankedWord($outside, 'Outside Rank One', 1, 'outside');
        $this->addAudio($ten, '/wp-content/uploads/rank-ten.mp3');
        $this->addAudio($twentyA, '/wp-content/uploads/rank-twenty-untyped.mp3', false);

        $rank_queries = [];
        $audio_queries = [];
        $capture = static function (WP_Query $query) use (&$rank_queries): void {
            if ((int) $query->get('ll_tools_ranked_word_list_query') === 1) {
                $rank_queries[] = $query->query_vars;
            }
        };
        $capture_audio = static function (string $sql) use (&$audio_queries): string {
            if (str_contains($sql, 'll_tools_ranked_word_list_audio')) {
                $audio_queries[] = $sql;
            }
            return $sql;
        };
        add_action('pre_get_posts', $capture);
        add_filter('query', $capture_audio);
        try {
            $html = do_shortcode(
                '[ll_ranked_word_list category="' . (int) $category->term_id
                . '" wordset="' . (int) $this->wordset->term_id
                . '" per_page="2" list_id="ordering"]'
            );
        } finally {
            remove_action('pre_get_posts', $capture);
            remove_filter('query', $capture_audio);
        }

        $this->assertCount(1, $rank_queries);
        $this->assertSame(2, (int) ($rank_queries[0]['posts_per_page'] ?? 0));
        $this->assertSame(1, (int) ($rank_queries[0]['paged'] ?? 0));
        $this->assertFalse((bool) ($rank_queries[0]['no_found_rows'] ?? true));
        $this->assertSame(
            ['rank_clause' => 'ASC', 'ID' => 'ASC'],
            $rank_queries[0]['orderby'] ?? null
        );
        $this->assertSame(
            LL_TOOLS_RANKED_WORD_META_KEY,
            $rank_queries[0]['meta_query']['rank_clause']['key'] ?? null
        );
        $this->assertSame(
            [(int) $category->term_id],
            array_map('intval', (array) ($rank_queries[0]['tax_query'][0]['terms'] ?? []))
        );
        $this->assertSame('AND', $rank_queries[0]['tax_query']['relation'] ?? null);
        $this->assertSame(
            [(int) $this->wordset->term_id],
            array_map('intval', (array) ($rank_queries[0]['tax_query'][1]['terms'] ?? []))
        );

        $this->assertCount(1, $audio_queries, 'Audio for a page should be collected in one parent-scoped query.');
        $this->assertSame(2, substr_count($audio_queries[0], 'post_parent ='));
        $this->assertSame(2, substr_count($audio_queries[0], 'LIMIT 9'));
        $this->assertStringNotContainsString('LIMIT -1', $audio_queries[0]);
        $this->assertStringContainsString("meta_key = 'audio_file_path'", $audio_queries[0]);

        $tenPosition = strpos($html, 'Rank Ten');
        $twentyAPosition = strpos($html, 'Rank Twenty A');
        $this->assertIsInt($tenPosition);
        $this->assertIsInt($twentyAPosition);
        $this->assertTrue($tenPosition < $twentyAPosition);
        $this->assertStringNotContainsString('Rank Twenty B', $html);
        $this->assertStringNotContainsString('Rank Thirty', $html);
        $this->assertStringNotContainsString('Rank Missing', $html);
        $this->assertStringNotContainsString('Outside Rank One', $html);
        $this->assertStringContainsString('/wp-content/uploads/rank-ten.mp3', $html);
        $this->assertStringContainsString('/wp-content/uploads/rank-twenty-untyped.mp3', $html);
        $this->assertStringContainsString('ll-word-audio__button', $html);
        $this->assertStringContainsString('ll-ranked-word-list__pagination', $html);
        $this->assertStringContainsString('data-word-id="' . $ten . '"', $html);
        $this->assertStringNotContainsString('data-word-id="' . $unranked . '"', $html);

    }

    public function test_scoped_page_argument_renders_the_next_stable_page_without_colliding(): void
    {
        $firstCategory = $this->createCategory('Ranked paging first');
        $secondCategory = $this->createCategory('Ranked paging second');

        $this->createRankedWord($firstCategory, 'Paging Rank One', 1, 'one');
        $this->createRankedWord($firstCategory, 'Paging Rank Two', 2, 'two');
        $this->createRankedWord($firstCategory, 'Paging Rank Three', 3, 'three');
        $this->createRankedWord($secondCategory, 'Second List Rank One', 1, 'second');

        $firstArg = ll_tools_ranked_word_list_page_arg(
            (int) $firstCategory->term_id,
            1,
            'first-list',
            (int) $this->wordset->term_id
        );
        $secondArg = ll_tools_ranked_word_list_page_arg(
            (int) $secondCategory->term_id,
            1,
            'second-list',
            (int) $this->wordset->term_id
        );
        $duplicateArg = ll_tools_ranked_word_list_page_arg(
            (int) $firstCategory->term_id,
            1,
            'duplicate-list',
            (int) $this->wordset->term_id
        );

        $this->assertNotSame($firstArg, $secondArg);
        $this->assertNotSame($firstArg, $duplicateArg);
        $this->assertStringStartsWith('ll_ranked_page_', $firstArg);

        $_GET[$firstArg] = '2';
        $_GET[$secondArg] = '1';

        $html = do_shortcode(
            '[ll_ranked_word_list category="' . (int) $firstCategory->term_id
            . '" wordset="' . (int) $this->wordset->term_id
            . '" per_page="1" list_id="first-list"]'
        );

        $this->assertStringContainsString('Paging Rank Two', $html);
        $this->assertStringNotContainsString('Paging Rank One', $html);
        $this->assertStringNotContainsString('Paging Rank Three', $html);
        $this->assertStringContainsString($firstArg, html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString('#ll-ranked-word-list-first-list-', html_entity_decode($html, ENT_QUOTES));
    }

    public function test_shortcode_hard_caps_page_size_and_participates_in_asset_detection(): void
    {
        $category = $this->createCategory('Ranked cap and assets');
        $this->createRankedWord($category, 'Ranked Asset Word', 1, 'asset');

        $args = ll_tools_ranked_word_list_query_args(
            $category,
            $this->wordset,
            9999,
            1
        );
        $this->assertSame(LL_TOOLS_RANKED_WORD_LIST_MAX_PER_PAGE, $args['posts_per_page']);
        $pageArg = ll_tools_ranked_word_list_page_arg(
            (int) $category->term_id,
            100,
            'cap',
            (int) $this->wordset->term_id
        );
        $_GET[$pageArg] = '999999';
        $this->assertSame(
            LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE,
            ll_tools_ranked_word_list_current_page($pageArg)
        );
        $this->assertContains('ll_ranked_word_list', ll_tools_get_public_assets_shortcode_tags());
        $this->assertTrue(shortcode_exists('ll_ranked_word_list'));

        $pageId = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Ranked shortcode asset page',
            'post_content' => '[ll_ranked_word_list category="' . (int) $category->term_id
                . '" wordset="' . (int) $this->wordset->term_id . '"]',
        ]);
        $this->go_to(get_permalink($pageId));

        $this->assertTrue(
            ll_tools_content_has_public_assets_shortcodes(
                '[ll_ranked_word_list category="' . (int) $category->term_id
                    . '" wordset="' . (int) $this->wordset->term_id . '"]'
            )
        );
        $this->assertTrue(ll_tools_request_needs_public_assets());

        do_shortcode(
            '[ll_ranked_word_list category="' . (int) $category->term_id
                . '" wordset="' . (int) $this->wordset->term_id . '"]'
        );
        $this->assertTrue(wp_style_is('ll-tools-style', 'enqueued'));
        $this->assertTrue(wp_style_is('ll-tools-ranked-word-list', 'enqueued'));
        $this->assertTrue(wp_script_is('ll-word-audio', 'enqueued'));
    }

    public function test_shortcode_caps_unreachable_pagination_and_discloses_truncation(): void
    {
        $category = $this->createCategory('Ranked pagination cap');
        $this->createRankedWord($category, 'Ranked pagination cap word', 1, 'one');
        $inflate_found_posts = static function ($found_posts, WP_Query $query): int {
            if ((int) $query->get('ll_tools_ranked_word_list_query') === 1) {
                return LL_TOOLS_RANKED_WORD_LIST_MAX_PAGE + 1;
            }
            return (int) $found_posts;
        };
        add_filter('found_posts', $inflate_found_posts, 10, 2);
        try {
            $html = do_shortcode(
                '[ll_ranked_word_list category="' . (int) $category->term_id
                    . '" wordset="' . (int) $this->wordset->term_id
                    . '" per_page="1" list_id="page-cap"]'
            );
        } finally {
            remove_filter('found_posts', $inflate_found_posts, 10);
        }

        $pageArg = ll_tools_ranked_word_list_page_arg(
            (int) $category->term_id,
            1,
            'page-cap',
            (int) $this->wordset->term_id
        );
        $decoded = html_entity_decode($html, ENT_QUOTES);
        $this->assertStringContainsString(
            'This ranked list is limited to the first 100 pages.',
            $html
        );
        $this->assertStringContainsString($pageArg . '=100', $decoded);
        $this->assertStringNotContainsString($pageArg . '=101', $decoded);
    }

    public function test_repeated_lists_receive_unique_dom_targets_and_focusable_table_regions(): void
    {
        $category = $this->createCategory('Ranked duplicate instances');
        $this->createRankedWord($category, 'Duplicate list first', 1, 'first');
        $this->createRankedWord($category, 'Duplicate list second', 2, 'second');
        $shortcode = '[ll_ranked_word_list category="' . (int) $category->term_id
            . '" wordset="' . (int) $this->wordset->term_id
            . '" per_page="1"]';

        $html = do_shortcode($shortcode . $shortcode);
        preg_match_all('/<section id="([^"]+)"/', $html, $matches);

        $this->assertCount(2, $matches[1]);
        $this->assertCount(2, array_unique($matches[1]));
        $this->assertSame(2, substr_count($html, 'tabindex="0"'));
        $this->assertSame(2, substr_count($html, 'role="region"'));
        foreach ($matches[1] as $container_id) {
            $this->assertStringContainsString('#' . $container_id, html_entity_decode($html, ENT_QUOTES));
        }
    }

    public function test_shortcode_requires_and_authorizes_an_exact_wordset_scope(): void
    {
        $category = $this->createCategory('Ranked wordset privacy');
        $publicWordId = $this->createRankedWord(
            $category,
            'Public scoped ranked word',
            1,
            'public'
        );
        $privateTerm = wp_insert_term(
            'Private ranked wordset ' . wp_generate_password(5, false),
            'wordset'
        );
        $this->assertIsArray($privateTerm);
        $privateWordset = get_term((int) $privateTerm['term_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $privateWordset);
        update_term_meta(
            (int) $privateWordset->term_id,
            LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
            'private'
        );
        $isolation_option = defined('LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION')
            ? LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION
            : 'll_tools_wordset_isolation_enabled';
        $previous_isolation = get_option($isolation_option, '1');
        update_option($isolation_option, '0', false);
        try {
            $privateWordId = $this->createRankedWord(
                $category,
                'Private scoped ranked word',
                2,
                'private',
                $privateWordset
            );
        } finally {
            update_option($isolation_option, $previous_isolation, false);
        }
        wp_set_current_user(0);

        $missingScope = do_shortcode(
            '[ll_ranked_word_list category="' . (int) $category->term_id . '"]'
        );
        $publicScope = do_shortcode(
            '[ll_ranked_word_list category="' . (int) $category->term_id
                . '" wordset="' . (int) $this->wordset->term_id . '"]'
        );
        $privateScope = do_shortcode(
            '[ll_ranked_word_list category="' . (int) $category->term_id
                . '" wordset="' . (int) $privateWordset->term_id . '"]'
        );

        $this->assertSame('', $missingScope);
        $this->assertStringContainsString(
            'data-word-id="' . $publicWordId . '"',
            $publicScope
        );
        $this->assertStringNotContainsString(
            'data-word-id="' . $privateWordId . '"',
            $publicScope
        );
        $this->assertSame('', $privateScope);
    }

    public function test_audio_query_failure_is_reported_instead_of_claiming_audio_is_absent(): void
    {
        global $wpdb;

        $category = $this->createCategory('Ranked audio failure');
        $wordId = $this->createRankedWord($category, 'Audio failure word', 1, 'failure');
        $this->addAudio($wordId, '/wp-content/uploads/audio-failure.mp3');

        $break_audio_query = static function (string $sql): string {
            if (!str_contains($sql, 'll_tools_ranked_word_list_audio')) {
                return $sql;
            }
            return 'SELECT missing_column FROM missing_ranked_audio_table'
                . ' /* ll_tools_ranked_word_list_audio */';
        };
        add_filter('query', $break_audio_query);
        $previous_suppression = $wpdb->suppress_errors(true);
        try {
            $html = do_shortcode(
                '[ll_ranked_word_list category="' . (int) $category->term_id
                    . '" wordset="' . (int) $this->wordset->term_id . '"]'
            );
        } finally {
            remove_filter('query', $break_audio_query);
            $wpdb->suppress_errors($previous_suppression);
            $wpdb->last_error = '';
        }

        $this->assertStringContainsString('Audio failure word', $html);
        $this->assertStringContainsString(
            'Ranked word audio could not be loaded completely.',
            $html
        );
        $this->assertStringNotContainsString(
            'aria-label="No audio available"',
            $html
        );
    }

    public function test_audio_meta_failure_is_reported_instead_of_using_partial_details(): void
    {
        global $wpdb;

        $category = $this->createCategory('Ranked audio metadata failure');
        $wordId = $this->createRankedWord($category, 'Audio metadata failure word', 1, 'failure');
        $audioId = $this->addAudio($wordId, '/wp-content/uploads/audio-meta-failure.mp3');
        wp_cache_delete($audioId, 'post_meta');

        $injected = false;
        $break_audio_meta_query = static function (string $sql) use ($audioId, &$injected): string {
            if (
                !$injected
                && stripos($sql, 'postmeta') !== false
                && preg_match(
                    '/post_id\s+IN\s*\(\s*' . preg_quote((string) $audioId, '/') . '\s*\)/i',
                    $sql
                ) === 1
            ) {
                $injected = true;
                return 'SELECT post_id, meta_key, meta_value FROM missing_ranked_audio_meta_table';
            }

            return $sql;
        };
        add_filter('query', $break_audio_meta_query);
        $previous_suppression = $wpdb->suppress_errors(true);
        try {
            $urls = ll_tools_ranked_word_list_audio_url_map([$wordId]);
        } finally {
            remove_filter('query', $break_audio_meta_query);
            $wpdb->suppress_errors($previous_suppression);
            $wpdb->last_error = '';
            wp_cache_delete($audioId, 'post_meta');
        }

        $this->assertTrue($injected, 'Expected the ranked audio metadata query failure to be injected.');
        $this->assertWPError($urls);
        $this->assertSame('ll_ranked_word_audio_meta_failed', $urls->get_error_code());
        $this->assertSame(
            'Ranked word audio details could not be loaded completely.',
            $urls->get_error_message()
        );
    }

    public function test_audio_candidates_ignore_newer_rows_without_a_file_path(): void
    {
        $category = $this->createCategory('Ranked usable audio candidates');
        $wordId = $this->createRankedWord($category, 'Usable candidate word', 1, 'usable');
        $usableAudioId = $this->addAudio(
            $wordId,
            '/wp-content/uploads/usable-ranked-audio.mp3'
        );
        for ($index = 0; $index < 4; $index++) {
            $audioId = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $wordId,
                'post_title' => 'Missing path candidate ' . $index,
            ]);
            $this->assertGreaterThan($usableAudioId, $audioId);
        }

        $candidate_limit = static function (): int {
            return 2;
        };
        add_filter('ll_tools_ranked_word_audio_candidates_per_word', $candidate_limit);
        try {
            $urls = ll_tools_ranked_word_list_audio_url_map([$wordId]);
        } finally {
            remove_filter('ll_tools_ranked_word_audio_candidates_per_word', $candidate_limit);
        }

        $this->assertIsArray($urls);
        $this->assertStringContainsString(
            '/wp-content/uploads/usable-ranked-audio.mp3',
            (string) ($urls[$wordId] ?? '')
        );
    }

    public function test_importer_assigns_id_and_title_rows_idempotently_with_bounded_title_lookup(): void
    {
        $category = $this->createCategory('Ranked importer');
        $firstWord = $this->createRankedWord($category, 'Importer By ID', null, 'id translation');
        $secondWord = $this->createRankedWord($category, 'Importer By Title', null, 'title translation');

        $lookupLimits = [];
        $capture = static function (WP_Query $query) use (&$lookupLimits): void {
            if ((int) $query->get('ll_tools_ranked_word_import_lookup') === 1) {
                $lookupLimits[] = (int) $query->get('posts_per_page');
            }
        };
        add_action('pre_get_posts', $capture);
        try {
            $rows = [
                ['word_id' => $firstWord, 'rank' => '2'],
                ['title' => 'Importer By Title', 'rank' => '1'],
            ];
            $first = ll_tools_import_ranked_word_rows($rows, [
                'category' => (int) $category->term_id,
            ]);
            $second = ll_tools_import_ranked_word_rows($rows, [
                'category' => (int) $category->term_id,
            ]);
        } finally {
            remove_action('pre_get_posts', $capture);
        }

        $this->assertIsArray($first);
        $this->assertSame(2, $first['processed']);
        $this->assertSame(2, $first['updated']);
        $this->assertSame(0, $first['failed']);
        $this->assertSame(2, (int) get_post_meta($firstWord, LL_TOOLS_RANKED_WORD_META_KEY, true));
        $this->assertSame(1, (int) get_post_meta($secondWord, LL_TOOLS_RANKED_WORD_META_KEY, true));

        $this->assertIsArray($second);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame([2, 2], $lookupLimits, 'Title matching must stay a bounded exact lookup on every run.');
    }

    public function test_importer_rejects_oversized_batches_invalid_ranks_and_out_of_scope_words(): void
    {
        $category = $this->createCategory('Ranked import scope');
        $outsideCategory = $this->createCategory('Ranked import other scope');
        $outsideWord = $this->createRankedWord($outsideCategory, 'Ranked Outside Import Scope', null, 'outside');

        $oversized = array_fill(
            0,
            LL_TOOLS_RANKED_WORD_IMPORT_MAX_ROWS + 1,
            ['word_id' => $outsideWord, 'rank' => 1]
        );
        $oversizedResult = ll_tools_import_ranked_word_rows($oversized);
        $this->assertWPError($oversizedResult);
        $this->assertSame('ll_ranked_word_import_too_large', $oversizedResult->get_error_code());

        $result = ll_tools_import_ranked_word_rows([
            ['word_id' => $outsideWord, 'rank' => 1],
            ['title' => 'missing identity rank', 'rank' => '1.5'],
        ], [
            'category' => (int) $category->term_id,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(
            ['ll_ranked_word_outside_category', 'll_ranked_word_invalid_rank'],
            array_column($result['errors'], 'code')
        );
        $this->assertSame('', get_post_meta($outsideWord, LL_TOOLS_RANKED_WORD_META_KEY, true));
    }

    private function createCategory(string $name): WP_Term
    {
        $inserted = wp_insert_term($name, 'word-category');
        $this->assertIsArray($inserted);
        $this->assertFalse(is_wp_error($inserted));

        $term = get_term((int) $inserted['term_id'], 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);
        ll_tools_set_category_wordset_owner(
            (int) $term->term_id,
            (int) $this->wordset->term_id,
            (int) $term->term_id
        );

        return $term;
    }

    private function createRankedWord(
        WP_Term $category,
        string $title,
        ?int $rank,
        string $translation,
        ?WP_Term $wordset = null
    ): int {
        $wordId = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);
        $this->assertGreaterThan(0, $wordId);

        $wordset = $wordset instanceof WP_Term ? $wordset : $this->wordset;
        wp_set_object_terms($wordId, [(int) $wordset->term_id], 'wordset', false);
        wp_set_object_terms($wordId, [(int) $category->term_id], 'word-category', false);
        update_post_meta($wordId, 'word_translation', $translation);
        if ($rank !== null) {
            update_post_meta($wordId, LL_TOOLS_RANKED_WORD_META_KEY, $rank);
        }

        return (int) $wordId;
    }

    private function addAudio(int $wordId, string $path, bool $assignRecordingType = true): int
    {
        $audioId = self::factory()->post->create([
            'post_type'   => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $wordId,
            'post_title'  => 'Ranked list test audio',
        ]);
        $this->assertGreaterThan(0, $audioId);
        update_post_meta($audioId, 'audio_file_path', $path);

        if ($assignRecordingType) {
            $term = term_exists('isolation', 'recording_type');
            if (!$term) {
                $term = wp_insert_term('Isolation', 'recording_type', ['slug' => 'isolation']);
            }
            $this->assertFalse(is_wp_error($term));
            $recordingTypeId = is_array($term) ? (int) $term['term_id'] : (int) $term;
            wp_set_object_terms($audioId, [$recordingTypeId], 'recording_type', false);
        }

        return (int) $audioId;
    }
}
