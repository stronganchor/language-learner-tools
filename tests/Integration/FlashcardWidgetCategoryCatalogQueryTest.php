<?php
declare(strict_types=1);

final class FlashcardWidgetCategoryCatalogQueryTest extends LL_Tools_TestCase
{
    public function test_wordset_scoped_catalog_uses_bounded_term_query(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $wordset_a_id = $this->create_term('Scoped Catalog Wordset A', 'wordset');
            $wordset_b_id = $this->create_term('Scoped Catalog Wordset B', 'wordset');
            $category_a_id = $this->create_quizzable_category('Scoped Catalog Category A');
            $category_b_id = $this->create_quizzable_category('Scoped Catalog Category B');
            $category_a = get_term($category_a_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $category_a);

            $this->create_word($wordset_a_id, $category_a_id, 'Scoped Catalog Word A');
            $this->create_word($wordset_b_id, $category_b_id, 'Scoped Catalog Word B');

            $captured = [];
            $capture_filter = $this->captureWordCategoryTermQueries($captured);
            add_filter('get_terms_args', $capture_filter, 10, 2);
            try {
                [$categories, $preselected] = ll_flashcards_build_categories('', false, [$wordset_a_id]);
            } finally {
                remove_filter('get_terms_args', $capture_filter, 10);
            }

            $this->assertFalse($preselected);
            $this->assertSame([$category_a->name], array_column($categories, 'name'));
            $this->assertWordCategoryQueriesAreBounded($captured);
            $this->assertTrue(
                $this->hasBoundedQueryArg($captured, 'include'),
                'Wordset-scoped all-category mode should fetch terms by the eligible category ID list.'
            );
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_explicit_category_catalog_preserves_requested_order_without_all_terms_query(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };

        add_filter('ll_tools_quiz_min_words', $min_words_filter);

        try {
            $alpha_id = $this->create_quizzable_category('Explicit Catalog Alpha');
            $beta_id = $this->create_quizzable_category('Explicit Catalog Beta');
            $unrelated_id = $this->create_quizzable_category('Explicit Catalog Unrelated');

            $this->create_word(0, $alpha_id, 'Explicit Catalog Alpha Word');
            $this->create_word(0, $beta_id, 'Explicit Catalog Beta Word');
            $this->create_word(0, $unrelated_id, 'Explicit Catalog Unrelated Word');

            $alpha = get_term($alpha_id, 'word-category');
            $beta = get_term($beta_id, 'word-category');
            $this->assertInstanceOf(WP_Term::class, $alpha);
            $this->assertInstanceOf(WP_Term::class, $beta);

            $captured = [];
            $capture_filter = $this->captureWordCategoryTermQueries($captured);
            add_filter('get_terms_args', $capture_filter, 10, 2);
            try {
                [$categories, $preselected] = ll_flashcards_build_categories(
                    $beta->slug . ',' . $alpha->slug,
                    false,
                    []
                );
            } finally {
                remove_filter('get_terms_args', $capture_filter, 10);
            }

            $this->assertTrue($preselected);
            $this->assertSame(
                [$beta->name, $alpha->name],
                array_column($categories, 'name')
            );
            $this->assertWordCategoryQueriesAreBounded($captured);
            $this->assertTrue(
                $this->hasBoundedQueryArg($captured, 'slug'),
                'Explicit category mode should fetch only requested slugs.'
            );
        } finally {
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_missing_explicit_category_stays_preselected_without_all_terms_query(): void
    {
        $captured = [];
        $capture_filter = $this->captureWordCategoryTermQueries($captured);
        add_filter('get_terms_args', $capture_filter, 10, 2);
        try {
            [$categories, $preselected] = ll_flashcards_build_categories('missing-flashcard-catalog-category', false, []);
        } finally {
            remove_filter('get_terms_args', $capture_filter, 10);
        }

        $this->assertTrue($preselected);
        $this->assertSame([], $categories);
        $this->assertWordCategoryQueriesAreBounded($captured);
    }

    private function create_quizzable_category(string $name): int
    {
        $term_id = $this->create_term($name, 'word-category');
        $term = get_term($term_id, 'word-category');
        $this->assertInstanceOf(WP_Term::class, $term);

        update_term_meta($term_id, 'll_quiz_prompt_type', 'text');
        update_term_meta($term_id, 'll_quiz_option_type', 'text_translation');

        return $term_id;
    }

    private function create_term(string $name, string $taxonomy): int
    {
        $created = wp_insert_term($name . ' ' . wp_generate_password(6, false), $taxonomy);
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }

    private function create_word(int $wordset_id, int $category_id, string $title): int
    {
        $word_id = self::factory()->post->create([
            'post_type'   => 'words',
            'post_status' => 'publish',
            'post_title'  => $title,
        ]);
        $this->assertIsInt($word_id);

        wp_set_post_terms($word_id, [$category_id], 'word-category', false);
        if ($wordset_id > 0) {
            wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        }
        update_post_meta($word_id, 'word_translation', $title . ' Translation');

        return $word_id;
    }

    private function captureWordCategoryTermQueries(array &$captured): callable
    {
        return static function (array $args, array $taxonomies) use (&$captured): array {
            $query_taxonomies = isset($args['taxonomy']) ? (array) $args['taxonomy'] : [];
            $all_taxonomies = array_map('strval', array_merge((array) $taxonomies, $query_taxonomies));
            if (in_array('word-category', $all_taxonomies, true)) {
                $captured[] = $args;
            }

            return $args;
        };
    }

    private function assertWordCategoryQueriesAreBounded(array $captured): void
    {
        $this->assertNotEmpty($captured, 'Expected to capture at least one word-category term query.');

        foreach ($captured as $args) {
            $this->assertTrue(
                $this->hasAnyBoundedQueryArg($args),
                'Expected word-category term query to be bounded, got: ' . wp_json_encode($args)
            );
        }
    }

    private function hasAnyBoundedQueryArg(array $args): bool
    {
        foreach (['include', 'slug', 'name', 'object_ids'] as $key) {
            if (!empty($args[$key])) {
                return true;
            }
        }

        return false;
    }

    private function hasBoundedQueryArg(array $captured, string $key): bool
    {
        foreach ($captured as $args) {
            if (!empty($args[$key])) {
                return true;
            }
        }

        return false;
    }
}
