<?php
declare(strict_types=1);

final class WordsetPageCategorySearchIndexTest extends LL_Tools_TestCase
{
    public function test_category_search_index_uses_flat_exact_allowed_categories_without_promoting_children(): void
    {
        global $wpdb;

        $wordset = wp_insert_term('Search Index Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        $wordset_exists = term_exists($wordset_id, 'wordset');
        $this->assertIsArray($wordset_exists);
        $this->assertSame($wordset_id, (int) $wordset_exists['term_id']);

        $allowed = wp_insert_term('Search Index Allowed ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($allowed));
        $this->assertIsArray($allowed);
        $allowed_id = (int) $allowed['term_id'];

        $child = wp_insert_term('Search Index Child ' . wp_generate_password(4, false), 'word-category', [
            'parent' => $allowed_id,
        ]);
        $this->assertFalse(is_wp_error($child));
        $this->assertIsArray($child);
        $child_id = (int) $child['term_id'];
        $owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id';
        update_term_meta($allowed_id, $owner_meta_key, (string) $wordset_id);
        update_term_meta($child_id, $owner_meta_key, (string) $wordset_id);

        $only_allowed = $this->createSearchWord('Allowed Search Token', 'Allowed Translation Token', $wordset_id, [$allowed_id]);
        $allowed_with_stale_child = $this->createSearchWord('Allowed With Stale Child Token', 'Allowed Stale Child Translation Token', $wordset_id, [$allowed_id, $child_id]);
        $child_only = $this->createSearchWord('Child Only Search Token', 'Child Only Translation Token', $wordset_id, [$child_id]);

        $this->assertWordHasTerm($only_allowed, 'wordset', $wordset_id);
        $this->assertWordHasTerm($only_allowed, 'word-category', $allowed_id);

        $captured_queries = [];
        $capture = static function (string $query) use (&$captured_queries): string {
            if (strpos($query, 'category_search') === false && strpos($query, 'allowed_category_relationships') === false) {
                return $query;
            }
            $captured_queries[] = $query;
            return $query;
        };

        add_filter('query', $capture);
        try {
            $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$allowed_id]);
        } finally {
            remove_filter('query', $capture);
        }

        $this->assertArrayHasKey($allowed_id, $index);
        $search_text = (string) ($index[$allowed_id]['search_text'] ?? '');
        $this->assertStringContainsString('Allowed Search Token', $search_text);
        $this->assertStringContainsString('Allowed Translation Token', $search_text);
        $this->assertStringContainsString('Allowed With Stale Child Token', $search_text);
        $this->assertStringContainsString('Allowed Stale Child Translation Token', $search_text);
        $this->assertStringNotContainsString('Child Only Search Token', $search_text);
        $this->assertStringNotContainsString('Child Only Translation Token', $search_text);

        $queries_sql = implode("\n", $captured_queries);
        $this->assertStringContainsString('allowed_category_relationships', $queries_sql);
        $this->assertStringContainsString('allowed_category_taxonomy.term_id IN', $queries_sql);

        $this->assertGreaterThan(0, $only_allowed);
        $this->assertGreaterThan(0, $allowed_with_stale_child);
        $this->assertGreaterThan(0, $child_only);
        $this->assertNotEmpty($wpdb->last_query);
    }

    public function test_category_search_index_returns_empty_fallback_when_rebuild_lock_is_held(): void
    {
        global $wpdb;

        $wordset_id = 987654;
        $allowed_category_id = 987655;
        $category_epoch = function_exists('ll_tools_get_category_cache_epoch')
            ? max(1, (int) ll_tools_get_category_cache_epoch())
            : 1;
        $wordset_epoch = function_exists('ll_tools_get_wordset_cache_epoch')
            ? max(1, (int) ll_tools_get_wordset_cache_epoch())
            : 1;
        $cache_key = ll_tools_wordset_page_build_cache_key('category_search', [
            'wordset_id' => $wordset_id,
            'allowed_category_ids' => [$allowed_category_id],
            'category_epoch' => $category_epoch,
            'wordset_epoch' => $wordset_epoch,
        ]);
        $lock_option = ll_tools_wordset_page_cache_rebuild_lock_option($cache_key);

        delete_transient($cache_key);
        wp_cache_delete($cache_key, 'll_tools');
        delete_option($lock_option);
        add_option($lock_option, (string) (time() + 30), '', false);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            $queries[] = $query;
            return $query;
        };
        $no_wait = static function (): int {
            return 0;
        };

        add_filter('query', $capture);
        add_filter('ll_tools_wordset_page_category_search_index_rebuild_wait_ms', $no_wait);
        try {
            $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$allowed_category_id]);
        } finally {
            remove_filter('ll_tools_wordset_page_category_search_index_rebuild_wait_ms', $no_wait);
            remove_filter('query', $capture);
            delete_option($lock_option);
        }

        $this->assertSame([], $index);
        $queries_sql = implode("\n", $queries);
        $this->assertStringNotContainsString('category_taxonomy.term_id AS category_id', $queries_sql);
        $this->assertStringNotContainsString($wpdb->posts . ' AS posts', $queries_sql);
    }

    public function test_wordset_page_initial_config_defers_category_search_text_to_ajax(): void
    {
        $wordset = wp_insert_term('Deferred Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Deferred Search Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);

        for ($index = 1; $index <= 5; $index++) {
            $this->createSearchWordWithAudio(
                'Deferred Search Apple ' . $index,
                'Deferred Search Elma ' . $index,
                $wordset_id,
                [$category_id],
                'deferred-search-' . $index . '.mp3'
            );
        }
        $this->createVocabLesson($wordset_id, $category_id);

        $queries = [];
        $capture = static function (string $query) use (&$queries): string {
            if (strpos($query, 'allowed_category_relationships') !== false) {
                $queries[] = $query;
            }
            return $query;
        };

        add_filter('query', $capture);
        try {
            ll_tools_render_wordset_page_content($wordset_id, [
                'show_title' => false,
                'wrapper_tag' => 'div',
            ]);
        } finally {
            remove_filter('query', $capture);
        }

        $localized = (string) wp_scripts()->get_data('ll-wordset-pages-js', 'data');
        $this->assertNotSame('', $localized);
        $config = $this->extractLocalizedConfig($localized);
        $this->assertIsArray($config['categorySearch'] ?? null);
        $this->assertTrue((bool) ($config['categorySearch']['enabled'] ?? false));
        $this->assertNotSame('', (string) ($config['categorySearch']['token'] ?? ''));
        $this->assertIsArray($config['categories'] ?? null);

        foreach ((array) ($config['categories'] ?? []) as $category_config) {
            $this->assertIsArray($category_config);
            $this->assertArrayNotHasKey('search_text', $category_config);
        }

        $this->assertSame([], $queries);
    }

    public function test_category_search_ajax_returns_diacritic_insensitive_word_matches(): void
    {
        $wordset = wp_insert_term('Ajax Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $category = wp_insert_term('Ajax Search Category ' . wp_generate_password(4, false), 'word-category');
        $this->assertFalse(is_wp_error($category));
        $this->assertIsArray($category);
        $category_id = (int) $category['term_id'];
        $this->setCategoryOwner($category_id, $wordset_id);

        $word_id = $this->createSearchWord('Ajax Search Travel', 'cirûn otel', $wordset_id, [$category_id]);
        $this->assertWordHasTerm($word_id, 'wordset', $wordset_id);
        $this->assertWordHasTerm($word_id, 'word-category', $category_id);
        if (function_exists('ll_tools_bump_category_cache_epoch')) {
            ll_tools_bump_category_cache_epoch([$wordset_id]);
        }
        if (function_exists('ll_tools_bump_wordset_cache_epoch')) {
            ll_tools_bump_wordset_cache_epoch([$wordset_id]);
        }

        $index = ll_tools_get_wordset_page_category_search_index($wordset_id, [$category_id]);
        $this->assertArrayHasKey($category_id, $index);
        $this->assertStringContainsString('otel', (string) ($index[$category_id]['search_text'] ?? ''));
        $this->assertIsArray($index[$category_id]['words'] ?? null);
        $this->assertNotEmpty($index[$category_id]['words']);

        $token = ll_tools_wordset_page_store_category_search_payload([
            'wordset_id' => $wordset_id,
            'category_ids' => [$category_id],
            'user_id' => 0,
        ]);
        $this->assertNotSame('', $token);

        $response = $this->postCategorySearchAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
            'token' => $token,
            'wordset_id' => $wordset_id,
            'query' => 'cirun',
        ]);

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data'] ?? null);
        $data = (array) $response['data'];
        $this->assertSame('cirun', (string) ($data['query'] ?? ''));
        $this->assertSame([$category_id], array_values(array_map('intval', (array) ($data['categoryIds'] ?? []))));
        $this->assertIsArray($data['wordMatches'] ?? null);
        $this->assertArrayHasKey((string) $category_id, $data['wordMatches']);
        $matches = (array) $data['wordMatches'][(string) $category_id];
        $this->assertNotEmpty($matches);
        $first_match = (array) $matches[0];
        $this->assertSame($word_id, (int) ($first_match['id'] ?? 0));
        $this->assertSame('translation', (string) ($first_match['match_field'] ?? ''));
        $this->assertGreaterThan(0, (int) ($first_match['match_rank'] ?? 0));
    }

    public function test_uncategorized_virtual_category_uses_bounded_preview_and_ajax_search(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $wordset = wp_insert_term('Uncategorized Search Wordset ' . wp_generate_password(6, false), 'wordset');
        $this->assertFalse(is_wp_error($wordset));
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $needle_word_id = 0;
        for ($index = 1; $index <= 6; $index++) {
            $title = sprintf('Bounded Orphan %02d', $index);
            $translation = sprintf('Bounded Translation %02d', $index);
            if ($index === 6) {
                $title .= ' Needle';
                $translation .= ' Hidden Needle';
            }
            $word_id = $this->createSearchWord($title, $translation, $wordset_id, []);
            if ($index === 6) {
                $needle_word_id = $word_id;
            }
        }
        $this->assertGreaterThan(0, $needle_word_id);

        $virtual_category_id = ll_tools_wordset_page_uncategorized_virtual_category_id();
        $categories = ll_tools_get_wordset_page_categories($wordset_id, 2);
        $virtual_category = null;
        foreach ($categories as $category) {
            if (is_array($category) && (int) ($category['id'] ?? 0) === $virtual_category_id) {
                $virtual_category = $category;
                break;
            }
        }

        $this->assertIsArray($virtual_category);
        $this->assertSame(6, (int) ($virtual_category['count'] ?? 0));
        $this->assertSame(6, (int) ($virtual_category['content_count'] ?? 0));
        $this->assertIsArray($virtual_category['preview'] ?? null);
        $this->assertCount(4, (array) ($virtual_category['preview'] ?? []));
        $search_text = (string) ($virtual_category['search_text'] ?? '');
        $this->assertStringContainsString('Uncategorized', $search_text);
        $this->assertStringNotContainsString('Needle', $search_text);

        $token = ll_tools_wordset_page_store_category_search_payload([
            'wordset_id' => $wordset_id,
            'category_ids' => [$virtual_category_id],
            'user_id' => $admin_id,
        ]);
        $this->assertNotSame('', $token);

        $response = $this->postCategorySearchAjax([
            'nonce' => wp_create_nonce('ll_tools_wordset_page_category_search'),
            'token' => $token,
            'wordset_id' => $wordset_id,
            'query' => 'needle',
        ]);

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertIsArray($response['data'] ?? null);
        $data = (array) $response['data'];
        $this->assertContains($virtual_category_id, array_values(array_map('intval', (array) ($data['categoryIds'] ?? []))));
        $word_matches = (array) ($data['wordMatches'] ?? []);
        $virtual_matches = $word_matches[$virtual_category_id] ?? $word_matches[(string) $virtual_category_id] ?? [];
        $this->assertNotEmpty($virtual_matches);
        $first_match = (array) $virtual_matches[0];
        $this->assertSame($needle_word_id, (int) ($first_match['id'] ?? 0));
    }

    /**
     * @param int[] $category_ids
     */
    private function createSearchWord(string $title, string $translation, int $wordset_id, array $category_ids): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $title . ' ' . wp_generate_password(4, false),
        ]);
        $wordset_result = wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        $category_result = wp_set_object_terms($word_id, array_values(array_map('intval', $category_ids)), 'word-category', false);
        $this->assertFalse(is_wp_error($wordset_result));
        $this->assertFalse(is_wp_error($category_result));
        update_post_meta($word_id, 'word_translation', $translation);

        return (int) $word_id;
    }

    private function setCategoryOwner(int $category_id, int $wordset_id): void
    {
        $owner_meta_key = defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY') ? LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY : 'll_wordset_owner_id';
        update_term_meta($category_id, $owner_meta_key, (string) $wordset_id);
    }

    /**
     * @param int[] $category_ids
     */
    private function createSearchWordWithAudio(string $title, string $translation, int $wordset_id, array $category_ids, string $audio_file_name): int
    {
        $word_id = $this->createSearchWord($title, $translation, $wordset_id, $category_ids);
        $audio_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Audio ' . $title,
        ]);
        update_post_meta($audio_id, 'audio_file_path', '/wp-content/uploads/' . $audio_file_name);

        return $word_id;
    }

    private function createVocabLesson(int $wordset_id, int $category_id): int
    {
        $effective_category_id = function_exists('ll_tools_get_effective_category_id_for_wordset')
            ? (int) ll_tools_get_effective_category_id_for_wordset($category_id, $wordset_id, true)
            : $category_id;

        $lesson_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Deferred Search Lesson ' . wp_generate_password(4, false),
        ]);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $wordset_id);
        update_post_meta($lesson_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $effective_category_id);

        return (int) $lesson_id;
    }

    private function assertWordHasTerm(int $word_id, string $taxonomy, int $term_id): void
    {
        global $wpdb;

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(1)
             FROM {$wpdb->term_relationships} AS relationships
             INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
                ON taxonomy.term_taxonomy_id = relationships.term_taxonomy_id
                AND taxonomy.taxonomy = %s
                AND taxonomy.term_id = %d
             WHERE relationships.object_id = %d",
            $taxonomy,
            $term_id,
            $word_id
        ));

        $this->assertSame(1, $count);
    }

    /**
     * @return array<string,mixed>
     */
    private function extractLocalizedConfig(string $localized): array
    {
        preg_match('/var llWordsetPageData = (\{.*?\});/s', $localized, $matches);
        $this->assertArrayHasKey(1, $matches);

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function postCategorySearchAjax(array $post): array
    {
        $original_post = $_POST;
        $original_request = $_REQUEST;
        $_POST = $post;
        $_REQUEST = $_POST;

        try {
            return $this->runJsonEndpoint(static function (): void {
                ll_tools_wordset_page_handle_category_search_ajax();
            });
        } finally {
            $_POST = $original_post;
            $_REQUEST = $original_request;
        }
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
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
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
