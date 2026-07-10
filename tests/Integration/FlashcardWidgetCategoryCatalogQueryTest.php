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

    public function test_unscoped_catalog_returns_bounded_pages_instead_of_processing_every_category(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        $page_size_filter = static function (): int {
            return 6;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        add_filter('ll_tools_flashcard_catalog_page_size', $page_size_filter);

        try {
            for ($index = 1; $index <= 14; $index++) {
                $category_id = $this->create_quizzable_category(sprintf('Paged Catalog %02d', $index));
                $this->create_word(0, $category_id, 'Paged Catalog Word ' . $index);
            }

            $captured = [];
            $capture_filter = $this->captureWordCategoryTermQueries($captured);
            add_filter('get_terms_args', $capture_filter, 10, 2);
            try {
                [$first_page, $preselected, $first_catalog] = ll_flashcards_build_categories('', false, [], 0, true);
                [$second_page, , $second_catalog] = ll_flashcards_build_categories('', false, [], 6, true);
            } finally {
                remove_filter('get_terms_args', $capture_filter, 10);
            }

            $this->assertFalse($preselected);
            $this->assertLessThanOrEqual(6, count($first_page));
            $this->assertLessThanOrEqual(6, count($second_page));
            $this->assertTrue((bool) $first_catalog['has_more']);
            $this->assertSame(6, (int) $first_catalog['next_offset']);
            $this->assertSame(6, (int) $first_catalog['page_size']);
            $this->assertSame(12, (int) $second_catalog['next_offset']);
            $this->assertSame([], array_values(array_intersect(
                array_map('intval', array_column($first_page, 'id')),
                array_map('intval', array_column($second_page, 'id'))
            )));
            $this->assertWordCategoryQueriesAreBounded($captured);
            $this->assertTrue($this->hasBoundedQueryArg($captured, 'number'));
        } finally {
            remove_filter('ll_tools_flashcard_catalog_page_size', $page_size_filter);
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
    }

    public function test_catalog_ajax_returns_one_bounded_page_with_continuation_metadata(): void
    {
        $min_words_filter = static function (): int {
            return 1;
        };
        $page_size_filter = static function (): int {
            return 6;
        };
        add_filter('ll_tools_quiz_min_words', $min_words_filter);
        add_filter('ll_tools_flashcard_catalog_page_size', $page_size_filter);

        $original_post = $_POST;
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);
        ll_tools_flashcards_reset_public_ajax_throttle('catalog-test');
        try {
            for ($index = 1; $index <= 8; $index++) {
                $category_id = $this->create_quizzable_category(sprintf('Ajax Catalog %02d', $index));
                $this->create_word(0, $category_id, 'Ajax Catalog Word ' . $index);
            }

            $_POST = [
                'offset' => '0',
                'wordset' => '',
                'wordset_fallback' => '0',
            ];
            $response = $this->runJsonEndpoint('ll_tools_get_flashcard_category_catalog_ajax');

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertIsArray($response['data']['categories'] ?? null);
            $this->assertLessThanOrEqual(6, count($response['data']['categories']));
            $this->assertSame(6, (int) ($response['data']['catalog']['nextOffset'] ?? 0));
            $this->assertSame(6, (int) ($response['data']['catalog']['pageSize'] ?? 0));
        } finally {
            $_POST = $original_post;
            wp_set_current_user(0);
            remove_filter('ll_tools_flashcard_catalog_page_size', $page_size_filter);
            remove_filter('ll_tools_quiz_min_words', $min_words_filter);
        }
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
            if (!empty($args['number'])) {
                $this->assertLessThanOrEqual(49, (int) $args['number']);
            }
        }
    }

    private function hasAnyBoundedQueryArg(array $args): bool
    {
        foreach (['include', 'slug', 'name', 'object_ids', 'number'] as $key) {
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

    /** @return array<string,mixed> */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);
        ob_start();
        try {
            $callback();
            $this->fail('Expected wp_die to be called.');
        } catch (RuntimeException $exception) {
            $this->assertSame('wp_die', $exception->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
