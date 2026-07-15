<?php
declare(strict_types=1);

final class DefaultQuizWordsetResolutionTest extends LL_Tools_TestCase
{
    /**
     * @return array{wordset_id:int,source_category_id:int,isolated_category_id:int}
     */
    private function createIsolatedQuizCategoryFixture(): array
    {
        $wordset = wp_insert_term('Biblical Hebrew', 'wordset', [
            'slug' => 'biblical-hebrew',
        ]);
        $category = wp_insert_term('Quiz 23.1', 'word-category', [
            'slug' => 'quiz-23-1',
        ]);

        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $source_category_id = (int) $category['term_id'];

        update_term_meta($source_category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($source_category_id, 'll_quiz_option_type', 'text_title');

        $isolated_category_id = ll_tools_get_or_create_isolated_category_copy($source_category_id, $wordset_id);
        $this->assertGreaterThan(0, $isolated_category_id);
        $this->assertNotSame($source_category_id, $isolated_category_id);

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Hebrew Word',
        ]);
        wp_set_post_terms($word_id, [$isolated_category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return [
            'wordset_id' => $wordset_id,
            'source_category_id' => $source_category_id,
            'isolated_category_id' => $isolated_category_id,
        ];
    }

    /**
     * @return array{wordset_id:int,owned_category_id:int}
     */
    private function createOwnedLegacyEmbedCategoryFixture(
        bool $include_source_category = false,
        string $owned_category_slug = 'quiz-7-1-biblical-hebrew'
    ): array
    {
        $wordset = wp_insert_term('Biblical Hebrew', 'wordset', [
            'slug' => 'biblical-hebrew',
        ]);
        if ($include_source_category) {
            $source_category = wp_insert_term('Quiz 7.1', 'word-category', [
                'slug' => 'quiz-7-1',
            ]);
            $this->assertIsArray($source_category);
            update_term_meta((int) $source_category['term_id'], 'll_quiz_prompt_type', 'text_title');
            update_term_meta((int) $source_category['term_id'], 'll_quiz_option_type', 'text_title');
        }

        $category = wp_insert_term('Quiz 7.1 Biblical Hebrew', 'word-category', [
            'slug' => $owned_category_slug,
        ]);

        $this->assertIsArray($wordset);
        $this->assertIsArray($category);

        $wordset_id = (int) $wordset['term_id'];
        $owned_category_id = (int) $category['term_id'];

        ll_tools_set_category_wordset_owner($owned_category_id, $wordset_id, $owned_category_id);
        update_term_meta($owned_category_id, 'll_quiz_prompt_type', 'text_title');
        update_term_meta($owned_category_id, 'll_quiz_option_type', 'text_title');

        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => 'Hebrew Legacy Word',
        ]);
        wp_set_post_terms($word_id, [$owned_category_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);

        return [
            'wordset_id' => $wordset_id,
            'owned_category_id' => $owned_category_id,
        ];
    }

    public function test_source_category_uses_isolated_wordset_when_resolving_default_embed_context(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createIsolatedQuizCategoryFixture();
            $wordset_id = $fixture['wordset_id'];
            $source_category_id = $fixture['source_category_id'];

            $this->assertTrue(
                ll_can_category_generate_quiz($source_category_id, 1, [$wordset_id]),
                'The source category should be quizzable once its isolated copy has words in the target wordset.'
            );

            $this->assertSame($wordset_id, ll_get_default_wordset_id_for_category($source_category_id, 1));
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_default_wordset_resolution_does_not_request_cache_a_failed_zero_result(): void
    {
        global $wpdb;

        $fixture = $this->createIsolatedQuizCategoryFixture();
        $source_category_id = (int) $fixture['source_category_id'];
        $injected = false;
        $break_wordset_query = static function (array $clauses, array $taxonomies, array $args) use (&$injected): array {
            if (
                !$injected
                && in_array('wordset', $taxonomies, true)
                && (string) ($args['fields'] ?? '') === 'ids'
            ) {
                $clauses['where'] .= ' AND ll_tools_missing_default_wordset.term_id = 1';
                $injected = true;
            }
            return $clauses;
        };

        $previous_suppress_errors = $wpdb->suppress_errors(true);
        add_filter('terms_clauses', $break_wordset_query, 10, 3);
        try {
            $failed_complete = true;
            $this->assertSame(
                0,
                ll_get_default_wordset_id_for_category($source_category_id, 1, $failed_complete)
            );
        } finally {
            remove_filter('terms_clauses', $break_wordset_query, 10);
            $wpdb->suppress_errors($previous_suppress_errors);
            $wpdb->last_error = '';
        }

        $this->assertTrue($injected, 'The fixture must interrupt the intended wordset catalog read.');
        $this->assertFalse($failed_complete);

        // A transient failure is not a real "no owner" result. Retrying the
        // exact same key in this request must perform the source reads again.
        $retry_complete = false;
        $this->assertSame(
            (int) $fixture['wordset_id'],
            ll_get_default_wordset_id_for_category($source_category_id, 1, $retry_complete)
        );
        $this->assertTrue($retry_complete);
    }

    public function test_embed_context_re_resolves_legacy_source_slug_after_default_wordset_lookup(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createIsolatedQuizCategoryFixture();
            $wordset_id = $fixture['wordset_id'];
            $isolated_category_id = $fixture['isolated_category_id'];

            $context = ll_tools_resolve_embed_quiz_context('quiz-23-1', '');

            $this->assertIsArray($context);
            $this->assertInstanceOf(WP_Term::class, $context['term']);
            $this->assertInstanceOf(WP_Term::class, $context['wordset_term']);
            $this->assertSame('biblical-hebrew', $context['wordset']);
            $this->assertSame($wordset_id, (int) $context['wordset_term']->term_id);
            $this->assertSame($isolated_category_id, (int) $context['term']->term_id);
            $this->assertSame('quiz-23-1-biblical-hebrew', (string) $context['term']->slug);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_legacy_biblical_hebrew_embed_slug_resolves_owned_category_without_source_slug(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createOwnedLegacyEmbedCategoryFixture();
            $wordset_id = $fixture['wordset_id'];
            $owned_category_id = $fixture['owned_category_id'];

            $this->assertFalse(get_term_by('slug', 'quiz-7-1', 'word-category'));

            $context = ll_tools_resolve_embed_quiz_context('quiz-7-1', '');

            $this->assertIsArray($context);
            $this->assertInstanceOf(WP_Term::class, $context['term']);
            $this->assertInstanceOf(WP_Term::class, $context['wordset_term']);
            $this->assertSame('biblical-hebrew', $context['wordset']);
            $this->assertSame($wordset_id, (int) $context['wordset_term']->term_id);
            $this->assertSame($owned_category_id, (int) $context['term']->term_id);
            $this->assertSame('quiz-7-1-biblical-hebrew', (string) $context['term']->slug);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_legacy_biblical_hebrew_embed_slug_resolves_owned_category_when_source_slug_still_exists(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createOwnedLegacyEmbedCategoryFixture(true, 'quiz-7-1-owned-biblical-hebrew');

            $source_term = get_term_by('slug', 'quiz-7-1', 'word-category');
            $this->assertInstanceOf(WP_Term::class, $source_term);
            $this->assertSame(0, ll_get_default_wordset_id_for_category($source_term, 1));

            $context = ll_tools_resolve_embed_quiz_context('quiz-7-1', '');

            $this->assertInstanceOf(WP_Term::class, $context['term']);
            $this->assertInstanceOf(WP_Term::class, $context['wordset_term']);
            $this->assertSame($fixture['owned_category_id'], (int) $context['term']->term_id);
            $this->assertSame($fixture['wordset_id'], (int) $context['wordset_term']->term_id);
            $this->assertSame('biblical-hebrew', $context['wordset']);
            $this->assertSame('quiz-7-1-owned-biblical-hebrew', (string) $context['term']->slug);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_legacy_biblical_hebrew_embed_slug_respects_explicit_wordset_query(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $fixture = $this->createOwnedLegacyEmbedCategoryFixture();

            $context = ll_tools_resolve_embed_quiz_context('quiz-7-1', 'biblical-hebrew');

            $this->assertInstanceOf(WP_Term::class, $context['term']);
            $this->assertInstanceOf(WP_Term::class, $context['wordset_term']);
            $this->assertSame($fixture['owned_category_id'], (int) $context['term']->term_id);
            $this->assertSame($fixture['wordset_id'], (int) $context['wordset_term']->term_id);
            $this->assertSame('biblical-hebrew', $context['wordset']);
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_invalid_legacy_embed_lookup_is_bounded_and_negative_result_is_cached(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Large Legacy Catalog', 'wordset', [
            'slug' => 'large-legacy-catalog',
        ]);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        for ($index = 1; $index <= 200; $index++) {
            $category = wp_insert_term('Unrelated Legacy Category ' . $index, 'word-category', [
                'slug' => 'unrelated-legacy-' . $index . '--large-legacy-catalog',
            ]);
            $this->assertIsArray($category);
            ll_tools_set_category_wordset_owner((int) $category['term_id'], $wordset_id, (int) $category['term_id']);
        }

        $queries = [];
        $capture_query = static function (string $query) use (&$queries, $wpdb): string {
            if (
                stripos($query, (string) $wpdb->terms) !== false
                && stripos($query, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY) !== false
                && stripos($query, 'terms.slug LIKE') !== false
            ) {
                $queries[] = $query;
            }
            return $query;
        };
        $candidate_limit = static function (): int {
            return 7;
        };

        add_filter('query', $capture_query);
        add_filter('ll_tools_legacy_embed_candidate_limit', $candidate_limit);

        try {
            $this->assertNull(ll_tools_resolve_legacy_embed_isolated_category('missing-legacy', null, 1));
            $this->assertNull(ll_tools_resolve_legacy_embed_isolated_category('missing-legacy', null, 1));
        } finally {
            remove_filter('ll_tools_legacy_embed_candidate_limit', $candidate_limit);
            remove_filter('query', $capture_query);
        }

        $this->assertCount(1, $queries, 'The repeated miss should use the cached empty candidate list.');
        $this->assertStringContainsString('LIMIT 7', $queries[0]);
        $this->assertStringContainsString("missing-legacy-%", $queries[0]);
        $this->assertStringNotContainsString('get_terms', strtolower($queries[0]));
    }
}
