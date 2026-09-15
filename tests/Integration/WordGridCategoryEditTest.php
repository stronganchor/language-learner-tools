<?php
declare(strict_types=1);

final class WordGridCategoryEditTest extends LL_Tools_TestCase
{
    /** @var mixed */
    private $originalIsolationOption;

    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIsolationOption = get_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, null);
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, '1', false);
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);

        if ($this->originalIsolationOption === null) {
            delete_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION);
        } else {
            update_option(LL_TOOLS_WORDSET_ISOLATION_ENABLED_OPTION, $this->originalIsolationOption, false);
        }

        parent::tearDown();
    }

    public function test_lesson_edit_popup_lists_only_current_wordset_categories_including_empty_owned_categories(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        $output = $this->renderLessonWordGridForFixture($fixture);

        $this->assertStringContainsString('data-ll-word-categories-field', $output);
        $this->assertStringContainsString('data-ll-word-category-search', $output);
        $this->assertStringContainsString('data-ll-word-category-sort', $output);
        $this->assertStringContainsString('data-ll-word-category-empty', $output);
        $this->assertStringContainsString('data-ll-word-category-option', $output);
        $this->assertMatchesRegularExpression(
            '/data-ll-word-category-input[^>]+value="' . preg_quote((string) $fixture['category_a_id'], '/') . '"[^>]+checked=/',
            $output
        );
        $this->assertMatchesRegularExpression(
            '/data-ll-word-category-input[^>]+value="' . preg_quote((string) $fixture['category_b_id'], '/') . '"/',
            $output,
            'Expected the empty wordset-owned category to be available.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-ll-word-category-input[^>]+value="' . preg_quote((string) $fixture['foreign_category_id'], '/') . '"/',
            $output,
            'Foreign wordset categories must not be offered in this lesson editor.'
        );
    }

    public function test_lesson_edit_popup_category_controls_keep_wordset_order_metadata(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        update_term_meta((int) $fixture['wordset_id'], 'll_wordset_category_ordering_mode', 'manual');
        update_term_meta((int) $fixture['wordset_id'], 'll_wordset_category_manual_order', [
            (int) $fixture['category_b_id'],
            (int) $fixture['category_a_id'],
        ]);

        $output = $this->renderLessonWordGridForFixture($fixture);

        $category_b_position = strpos($output, 'value="' . (int) $fixture['category_b_id'] . '"');
        $category_a_position = strpos($output, 'value="' . (int) $fixture['category_a_id'] . '"');

        $this->assertIsInt($category_b_position);
        $this->assertIsInt($category_a_position);
        $this->assertLessThan($category_a_position, $category_b_position);
        $this->assertMatchesRegularExpression(
            '/data-ll-word-category-option[^>]+data-ll-wordset-order="0"[^>]*>\\s*<input[^>]+value="' . preg_quote((string) $fixture['category_b_id'], '/') . '"/',
            $output
        );
        $this->assertMatchesRegularExpression(
            '/data-ll-word-category-option[^>]+data-ll-wordset-order="1"[^>]*>\\s*<input[^>]+value="' . preg_quote((string) $fixture['category_a_id'], '/') . '"/',
            $output
        );
    }

    public function test_published_counts_are_scoped_and_aggregate_without_loading_words(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();
        foreach (['draft', 'pending', 'private', 'trash', 'future'] as $status) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words', 'post_status' => $status,
                'post_title' => 'Unpublished category count ' . $status,
                'post_date' => $status === 'future' ? gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS) : current_time('mysql'),
            ]);
            wp_set_post_terms($word_id, [$fixture['category_a_id']], 'word-category');
            wp_set_post_terms($word_id, [$fixture['wordset_id']], 'wordset');
        }
        $foreign_word_id = self::factory()->post->create([
            'post_type' => 'words', 'post_status' => 'publish', 'post_title' => 'Foreign count word',
        ]);
        wp_set_post_terms($foreign_word_id, [$fixture['category_a_id']], 'word-category');
        wp_set_post_terms($foreign_word_id, [$fixture['foreign_wordset_id']], 'wordset');

        $aggregates = [];
        $hydrations = [];
        $capture_sql = static function (string $sql) use (&$aggregates): string {
            if (strpos($sql, 'GROUP BY editor_categories.category_id') !== false) {
                $aggregates[] = $sql;
            }
            return $sql;
        };
        $capture_posts = static function (WP_Query $query) use (&$hydrations): void {
            if (array_intersect(['words', 'word_audio', 'word_images'], (array) $query->get('post_type'))) {
                $hydrations[] = $query->query_vars;
            }
        };
        add_filter('query', $capture_sql);
        add_action('pre_get_posts', $capture_posts);
        try {
            $counts = ll_tools_word_grid_get_category_editor_published_counts(
                $fixture['wordset_id'], [$fixture['category_a_id'], $fixture['category_b_id']], $complete
            );
        } finally {
            remove_filter('query', $capture_sql);
            remove_action('pre_get_posts', $capture_posts);
        }
        $this->assertTrue($complete);
        $this->assertSame(1, $counts[$fixture['category_a_id']]);
        $this->assertSame(0, $counts[$fixture['category_b_id']]);
        $this->assertCount(1, $aggregates);
        $this->assertSame([], $hydrations);
    }

    public function test_published_counts_deduplicate_ownerless_sources_and_exclude_foreign_source_assignments(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $source_id = $this->ensureTerm('word-category', 'Legacy count source', 'legacy-count-source');
        update_term_meta($fixture['category_b_id'], LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, $source_id);
        ll_tools_replace_post_terms_for_isolation($fixture['word_id'], [$source_id, $fixture['category_b_id']], 'word-category');
        $counts = ll_tools_word_grid_get_category_editor_published_counts($fixture['wordset_id'], [$fixture['category_b_id']]);
        $this->assertSame(1, $counts[$fixture['category_b_id']], 'A source and its copy on one word count once.');

        ll_tools_replace_post_terms_for_isolation($fixture['word_id'], [$source_id], 'word-category');
        $counts = ll_tools_word_grid_get_category_editor_published_counts($fixture['wordset_id'], [$fixture['category_b_id']]);
        $this->assertSame(1, $counts[$fixture['category_b_id']], 'An unmigrated ownerless source counts for its copy.');

        ll_tools_set_category_wordset_owner($source_id, $fixture['foreign_wordset_id'], $source_id);
        $counts = ll_tools_word_grid_get_category_editor_published_counts($fixture['wordset_id'], [$fixture['category_b_id']]);
        $this->assertSame(0, $counts[$fixture['category_b_id']], 'Foreign-owned source assignments never count for a current-wordset copy.');
    }

    public function test_published_counts_bulk_prime_cold_legacy_source_metadata(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $category_ids = [];
        $all_ids = [];
        for ($index = 0; $index < 20; $index++) {
            $source_id = $this->ensureTerm('word-category', 'Cold count source ' . $index, 'cold-count-source-' . $index);
            $category_id = $this->createWordsetCategory('Cold count copy ' . $index, 'cold-count-copy-' . $index, $fixture['wordset_id']);
            update_term_meta($category_id, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, $source_id);
            $category_ids[] = $category_id;
            $all_ids[] = $source_id;
            $all_ids[] = $category_id;
        }
        foreach ($all_ids as $term_id) {
            wp_cache_delete($term_id, 'terms');
            wp_cache_delete($term_id, 'term_meta');
        }
        $queries = [];
        $capture = static function (string $sql) use (&$queries): string {
            $queries[] = $sql;
            return $sql;
        };
        add_filter('query', $capture);
        try {
            $counts = ll_tools_word_grid_get_category_editor_published_counts($fixture['wordset_id'], $category_ids, $complete);
        } finally {
            remove_filter('query', $capture);
        }
        $this->assertTrue($complete);
        $this->assertSame(array_fill_keys($category_ids, 0), $counts);
        $this->assertLessThanOrEqual(7, count($queries), 'Cold source identity and metadata reads must be batched, not grow per category.');
    }

    public function test_duplicate_display_names_keep_distinct_selectable_identities_and_published_counts(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();
        $display = static function (string $label, WP_Term $term) use ($fixture): string {
            return in_array((int) $term->term_id, [$fixture['category_a_id'], $fixture['category_b_id'], $fixture['foreign_category_id']], true)
                ? 'Giyim - Modern' : $label;
        };
        add_filter('ll_tools_category_display_name', $display, 10, 2);
        try {
            $rows = ll_tools_word_grid_get_category_editor_rows($fixture['wordset_id']);
            $output = $this->renderLessonWordGridForFixture($fixture);
        } finally {
            remove_filter('ll_tools_category_display_name', $display, 10);
        }
        $rows = array_column($rows, null, 'id');
        $this->assertArrayHasKey($fixture['category_a_id'], $rows);
        $this->assertArrayHasKey($fixture['category_b_id'], $rows);
        $this->assertArrayNotHasKey($fixture['foreign_category_id'], $rows);
        foreach ([$fixture['category_a_id'], $fixture['category_b_id']] as $category_id) {
            $this->assertSame('Giyim - Modern', $rows[$category_id]['label']);
            $this->assertSame($rows[$category_id]['slug'], $rows[$category_id]['identity_label']);
            $this->assertStringContainsString('<span class="ll-word-edit-category-identity">' . esc_html($rows[$category_id]['slug']) . '</span>', $output);
        }
        $this->assertSame(1, $rows[$fixture['category_a_id']]['published_count']);
        $this->assertSame(0, $rows[$fixture['category_b_id']]['published_count']);
        $this->assertStringContainsString('1 published word', $output);
        $this->assertStringContainsString('0 published words', $output);
    }

    public function test_failed_published_count_query_is_not_rendered_as_zero(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();
        $fail_count = static function (string $sql): string {
            return strpos($sql, 'GROUP BY editor_categories.category_id') !== false
                ? 'SELECT * FROM ll_tools_missing_category_count_table' : $sql;
        };
        global $wpdb;
        $previous_suppression = $wpdb->suppress_errors();
        add_filter('query', $fail_count);
        try {
            $counts = ll_tools_word_grid_get_category_editor_published_counts($fixture['wordset_id'], [$fixture['category_a_id']], $complete);
            $output = $this->renderLessonWordGridForFixture($fixture);
        } finally {
            remove_filter('query', $fail_count);
            $wpdb->suppress_errors($previous_suppression);
            $wpdb->last_error = '';
        }
        $this->assertFalse($complete);
        $this->assertSame([], $counts);
        $this->assertStringContainsString('Word count unavailable', $output);
        $this->assertStringNotContainsString('data-ll-word-category-published-count="0"', $output);
    }

    public function test_ajax_word_update_replaces_only_current_wordset_category_assignments(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        $foreign_current_copy_id = ll_tools_get_or_create_isolated_category_copy(
            (int) $fixture['foreign_category_id'],
            (int) $fixture['wordset_id']
        );
        $this->assertGreaterThan(0, $foreign_current_copy_id);
        $this->assertNotSame((int) $fixture['foreign_category_id'], $foreign_current_copy_id);

        wp_set_post_terms(
            (int) $fixture['word_id'],
            [(int) $fixture['wordset_id'], (int) $fixture['foreign_wordset_id']],
            'wordset',
            false
        );
        wp_set_post_terms(
            (int) $fixture['word_id'],
            [(int) $fixture['category_a_id'], (int) $fixture['foreign_category_id']],
            'word-category',
            false
        );

        $selected_ids = ll_tools_word_grid_get_selected_category_ids_for_editor(
            (int) $fixture['word_id'],
            (int) $fixture['wordset_id'],
            [(int) $fixture['category_a_id'], (int) $fixture['category_b_id'], $foreign_current_copy_id]
        );
        $this->assertContains((int) $fixture['category_a_id'], $selected_ids);
        $this->assertNotContains(
            $foreign_current_copy_id,
            $selected_ids,
            'A foreign-owned assignment must not make its current-wordset alias appear selected.'
        );
        $word_ids_by_category = ll_tools_word_grid_get_category_editor_word_ids_by_category(
            (int) $fixture['wordset_id'],
            [$foreign_current_copy_id]
        );
        $this->assertSame(
            [],
            $word_ids_by_category[$foreign_current_copy_id] ?? [],
            'A foreign-owned source assignment must not count as a word in its current-wordset copy.'
        );

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'word_id' => (string) $fixture['word_id'],
            'word_text' => 'Category Edit Word',
            'word_translation' => 'Category edit translation',
            'wordset_id' => (string) $fixture['wordset_id'],
            'lesson_category_id' => (string) $fixture['category_a_id'],
            'category_ids_submitted' => '1',
            'category_ids' => [(string) $fixture['category_b_id']],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_update_word_handler();
            });
        } finally {
            $_POST = $this->postBackup;
            $_REQUEST = $this->requestBackup;
        }

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame([(int) $fixture['category_b_id']], array_map('intval', (array) ($response['data']['categories']['ids'] ?? [])));
        $this->assertFalse((bool) ($response['data']['lesson_visible'] ?? true));

        $assigned_ids = wp_get_post_terms((int) $fixture['word_id'], 'word-category', ['fields' => 'ids']);
        $assigned_ids = array_values(array_map('intval', is_wp_error($assigned_ids) ? [] : (array) $assigned_ids));
        sort($assigned_ids, SORT_NUMERIC);
        $expected_ids = [(int) $fixture['category_b_id'], (int) $fixture['foreign_category_id']];
        sort($expected_ids, SORT_NUMERIC);

        $this->assertSame(
            $expected_ids,
            $assigned_ids,
            'Only the current wordset assignments should be replaced; foreign-owned categories must remain independent.'
        );
    }

    public function test_ajax_word_update_rejects_category_outside_current_wordset_scope(): void
    {
        $fixture = $this->createCategoryEditFixture();
        $this->loginEditor();

        $_POST = [
            'nonce' => wp_create_nonce('ll_word_grid_edit'),
            'word_id' => (string) $fixture['word_id'],
            'word_text' => 'Category Edit Word',
            'word_translation' => 'Category edit translation',
            'wordset_id' => (string) $fixture['wordset_id'],
            'category_ids_submitted' => '1',
            'category_ids' => [(string) $fixture['foreign_category_id']],
        ];
        $_REQUEST = $_POST;

        try {
            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_word_grid_update_word_handler();
            });
        } finally {
            $_POST = $this->postBackup;
            $_REQUEST = $this->requestBackup;
        }

        $this->assertFalse((bool) ($response['success'] ?? true));
        $assigned_ids = wp_get_post_terms((int) $fixture['word_id'], 'word-category', ['fields' => 'ids']);
        $assigned_ids = array_values(array_map('intval', is_wp_error($assigned_ids) ? [] : (array) $assigned_ids));

        $this->assertContains((int) $fixture['category_a_id'], $assigned_ids);
        $this->assertNotContains((int) $fixture['foreign_category_id'], $assigned_ids);
    }

    /**
     * @return array<string,int>
     */
    private function createCategoryEditFixture(): array
    {
        $wordset_id = $this->ensureTerm('wordset', 'Category Edit Wordset', 'category-edit-wordset');
        $foreign_wordset_id = $this->ensureTerm('wordset', 'Category Edit Foreign Wordset', 'category-edit-foreign-wordset');
        $category_a_id = $this->createWordsetCategory('Category Edit A', 'category-edit-a', $wordset_id);
        $category_b_id = $this->createWordsetCategory('Category Edit Empty B', 'category-edit-empty-b', $wordset_id);
        $foreign_category_id = $this->createWordsetCategory('Category Edit Foreign', 'category-edit-foreign', $foreign_wordset_id);

        foreach ([$category_a_id, $category_b_id, $foreign_category_id] as $category_id) {
            update_term_meta($category_id, 'll_quiz_prompt_type', 'text_title');
            update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
        }

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Category Edit Word',
        ]);
        wp_set_post_terms($word_id, [$category_a_id], 'word-category', false);
        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Category edit translation');

        return [
            'wordset_id' => $wordset_id,
            'foreign_wordset_id' => $foreign_wordset_id,
            'category_a_id' => $category_a_id,
            'category_b_id' => $category_b_id,
            'foreign_category_id' => $foreign_category_id,
            'word_id' => $word_id,
        ];
    }

    private function createWordsetCategory(string $name, string $slug, int $wordset_id): int
    {
        $created = function_exists('ll_tools_create_or_get_wordset_category')
            ? ll_tools_create_or_get_wordset_category($name, $wordset_id, ['slug' => $slug])
            : wp_insert_term($name, 'word-category', ['slug' => $slug]);
        if (is_array($created)) {
            $created = (int) ($created['term_id'] ?? 0);
        }
        $this->assertFalse(is_wp_error($created));
        $this->assertGreaterThan(0, (int) $created);

        return (int) $created;
    }

    /**
     * @param array<string,int> $fixture
     */
    private function renderLessonWordGridForFixture(array $fixture): string
    {
        $lesson_category = get_term((int) $fixture['category_a_id'], 'word-category');
        $wordset = get_term((int) $fixture['wordset_id'], 'wordset');
        $this->assertInstanceOf(WP_Term::class, $lesson_category);
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $ajax_filter = static function (): bool {
            return true;
        };
        add_filter('wp_doing_ajax', $ajax_filter);
        $GLOBALS['ll_tools_word_grid_force_lesson_context'] = true;

        try {
            return do_shortcode(sprintf(
                '[word_grid category="%s" wordset="%s"]',
                esc_attr((string) $lesson_category->slug),
                esc_attr((string) $wordset->slug)
            ));
        } finally {
            remove_filter('wp_doing_ajax', $ajax_filter);
            unset($GLOBALS['ll_tools_word_grid_force_lesson_context']);
        }
    }

    private function ensureTerm(string $taxonomy, string $name, string $slug): int
    {
        $existing = term_exists($slug, $taxonomy);
        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);
        return (int) $created['term_id'];
    }

    private function loginEditor(): void
    {
        $editor_id = self::factory()->user->create(['role' => 'administrator']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->add_cap('view_ll_tools');
        clean_user_cache($editor_id);
        wp_set_current_user($editor_id);
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $die_handler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $die_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $die_ajax_filter = static function () use ($die_handler) {
            return $die_handler;
        };
        $doing_ajax_filter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $die_filter);
        add_filter('wp_die_ajax_handler', $die_ajax_filter);
        add_filter('wp_doing_ajax', $doing_ajax_filter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $die_filter);
            remove_filter('wp_die_ajax_handler', $die_ajax_filter);
            remove_filter('wp_doing_ajax', $doing_ajax_filter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');
        return $decoded;
    }
}
