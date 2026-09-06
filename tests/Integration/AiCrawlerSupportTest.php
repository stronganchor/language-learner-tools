<?php
declare(strict_types=1);

final class AiCrawlerSupportTest extends LL_Tools_TestCase
{
    /** @var array<int,string> */
    private array $created_terms = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        foreach (get_posts([
            'post_type' => ['ll_dictionary_entry', 'll_vocab_lesson', 'll_content_lesson'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => false,
        ]) as $post_id) {
            wp_delete_post((int) $post_id, true);
        }

        foreach ($this->created_terms as $term_id => $taxonomy) {
            wp_delete_term((int) $term_id, $taxonomy);
        }
        $this->created_terms = [];

        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        parent::tearDown();
    }

    public function test_llms_txt_lists_public_markdown_exports(): void
    {
        $content = ll_tools_ai_crawler_build_llms_txt();

        $this->assertStringStartsWith('# ', $content);
        $this->assertStringContainsString('/ll-tools/index.md', $content);
        $this->assertStringContainsString('/ll-tools/index.jsonld', $content);
        $this->assertStringContainsString('/ll-tools/dictionary.md', $content);
        $this->assertStringContainsString('/ll-tools/wordsets.md', $content);
        $this->assertStringContainsString('/ll-tools/content-lessons.md', $content);
        $this->assertStringContainsString('Admin screens', $content);
    }

    public function test_discovery_links_advertise_llms_and_ai_index(): void
    {
        $links = ll_tools_ai_crawler_discovery_links();
        $this->assertCount(3, $links);
        $this->assertSame(home_url('/llms.txt'), $links[0]['href']);
        $this->assertSame('text/plain', $links[0]['type']);
        $this->assertSame(home_url('/ll-tools/index.md'), $links[1]['href']);
        $this->assertSame('text/markdown', $links[1]['type']);
        $this->assertSame(home_url('/ll-tools/index.jsonld'), $links[2]['href']);
        $this->assertSame('application/ld+json', $links[2]['type']);

        ob_start();
        ll_tools_ai_crawler_render_head_links();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('href="' . esc_url(home_url('/llms.txt')) . '"', $html);
        $this->assertStringContainsString('href="' . esc_url(home_url('/ll-tools/index.md')) . '"', $html);
        $this->assertStringContainsString('href="' . esc_url(home_url('/ll-tools/index.jsonld')) . '"', $html);
    }

    public function test_export_response_caches_get_body_and_refreshes_when_dictionary_version_changes(): void
    {
        $public_wordset_id = $this->createWordset('AI Cached Export Wordset', 'ai-cached-export-wordset');
        $this->createDictionaryEntry('Cached Export Entry', $public_wordset_id, 'cached definition');
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        $export = [
            'key' => 'dictionary',
            'content_type' => 'text/markdown; charset=utf-8',
        ];
        $first_cache_key = ll_tools_ai_crawler_export_cache_key($export);
        ll_tools_ai_crawler_delete_cached_export($export);

        try {
            $first = ll_tools_ai_crawler_prepare_export_response('GET', $export);
            $this->assertTrue($first['ok']);
            $this->assertTrue($first['send_body']);
            $this->assertSame('MISS', $first['cache_status']);
            $this->assertStringContainsString('Cached Export Entry', $first['body']);
            $this->assertStringContainsString('cached definition', $first['body']);

            $second = ll_tools_ai_crawler_prepare_export_response('GET', $export);
            $this->assertTrue($second['ok']);
            $this->assertSame('HIT', $second['cache_status']);
            $this->assertSame($first['body'], $second['body']);

            $this->createDictionaryEntry('Refreshed Export Entry', $public_wordset_id, 'fresh definition');
            if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
                ll_tools_bump_dictionary_browser_cache_version();
            }

            $third = ll_tools_ai_crawler_prepare_export_response('GET', $export);
            $this->assertTrue($third['ok']);
            $this->assertSame('MISS', $third['cache_status']);
            $this->assertStringContainsString('Refreshed Export Entry', $third['body']);
            $this->assertStringContainsString('fresh definition', $third['body']);
        } finally {
            wp_cache_delete($first_cache_key, ll_tools_ai_crawler_cache_group());
            delete_transient($first_cache_key);
            ll_tools_ai_crawler_delete_cached_export($export);
        }
    }

    public function test_ai_export_downstream_cache_policy_is_locale_safe(): void
    {
        update_option('WPLANG', 'en_US');
        switch_to_locale('en_US');
        try {
            $this->assertStringStartsWith('public, max-age=', ll_tools_ai_crawler_response_cache_control_value());
        } finally {
            restore_previous_locale();
        }

        $turkish_locale = static function (): string {
            return 'tr_TR';
        };
        add_filter('locale', $turkish_locale);
        try {
            $this->assertSame('private, no-store, max-age=0', ll_tools_ai_crawler_response_cache_control_value());
        } finally {
            remove_filter('locale', $turkish_locale);
        }
    }

    public function test_head_export_response_does_not_build_uncached_body(): void
    {
        $export = [
            'key' => 'dictionary',
            'content_type' => 'text/markdown; charset=utf-8',
        ];
        ll_tools_ai_crawler_delete_cached_export($export);

        $build_count = 0;
        $count_filter = static function ($limit) use (&$build_count) {
            $build_count++;
            return $limit;
        };
        add_filter('ll_tools_ai_crawler_dictionary_limit', $count_filter);

        try {
            $head = ll_tools_ai_crawler_prepare_export_response('HEAD', $export);
            $this->assertTrue($head['ok']);
            $this->assertFalse($head['send_body']);
            $this->assertSame('', $head['body']);
            $this->assertSame('HEAD', $head['cache_status']);
            $this->assertSame(0, $build_count);

            $get = ll_tools_ai_crawler_prepare_export_response('GET', $export);
            $this->assertTrue($get['ok']);
            $this->assertSame('MISS', $get['cache_status']);
            $this->assertGreaterThan(0, $build_count);

            $build_count = 0;
            $cached_head = ll_tools_ai_crawler_prepare_export_response('HEAD', $export);
            $this->assertTrue($cached_head['ok']);
            $this->assertFalse($cached_head['send_body']);
            $this->assertSame('', $cached_head['body']);
            $this->assertSame('HIT', $cached_head['cache_status']);
            $this->assertGreaterThan(0, $cached_head['content_length']);
            $this->assertSame(0, $build_count);
        } finally {
            remove_filter('ll_tools_ai_crawler_dictionary_limit', $count_filter);
            ll_tools_ai_crawler_delete_cached_export($export);
        }
    }

    public function test_dictionary_markdown_excludes_private_wordset_entries_for_anonymous_agents(): void
    {
        $public_wordset_id = $this->createWordset('AI Public Dictionary Wordset', 'ai-public-dictionary-wordset');
        $private_wordset_id = $this->createWordset('AI Private Dictionary Wordset', 'ai-private-dictionary-wordset', true);

        $public_entry_id = wp_insert_post([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'zewq',
            'post_content' => 'taste',
        ], true);
        $this->assertIsInt($public_entry_id);
        update_post_meta((int) $public_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (string) $public_wordset_id);
        update_post_meta((int) $public_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => 'taste; flavor',
                'translations' => ['en' => 'taste; flavor'],
                'entry_type' => 'noun',
                'source_dictionary' => 'Test Dictionary',
                'dialects' => ['Palu'],
            ],
        ]);

        $private_entry_id = wp_insert_post([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => 'secret-headword',
            'post_content' => 'hidden definition',
        ], true);
        $this->assertIsInt($private_entry_id);
        update_post_meta((int) $private_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (string) $private_wordset_id);
        update_post_meta((int) $private_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => 'hidden definition',
                'translations' => ['en' => 'hidden definition'],
                'entry_type' => 'noun',
            ],
        ]);

        wp_set_current_user(0);
        $content = ll_tools_ai_crawler_build_dictionary_markdown(['limit' => 20, 'sense_limit' => 4]);

        $this->assertStringContainsString('zewq', $content);
        $this->assertStringContainsString('taste; flavor', $content);
        $this->assertStringContainsString('Test Dictionary', $content);
        $this->assertStringContainsString('Palu', $content);
        $this->assertStringContainsString('ll_dictionary_entry=' . (string) $public_entry_id, $content);
        $this->assertStringNotContainsString('secret-headword', $content);
        $this->assertStringNotContainsString('hidden definition', $content);
    }

    public function test_dictionary_letter_markdown_uses_public_letter_scope(): void
    {
        $public_wordset_id = $this->createWordset('AI Letter Public Wordset', 'ai-letter-public-wordset');
        $private_wordset_id = $this->createWordset('AI Letter Private Wordset', 'ai-letter-private-wordset', true);

        $this->createDictionaryEntry('Ava', $public_wordset_id, 'water');
        $this->createDictionaryEntry('Bero', $public_wordset_id, 'come');
        $this->createDictionaryEntry('A-secret', $private_wordset_id, 'hidden A definition');
        $this->createDictionaryEntry('Z-secret', $private_wordset_id, 'hidden Z definition');
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        wp_set_current_user(0);
        $content = ll_tools_ai_crawler_build_dictionary_letter_markdown('a', ['limit' => 20, 'sense_limit' => 4]);
        $letters = ll_tools_ai_crawler_get_dictionary_letters(20);

        $this->assertStringContainsString('# Public Dictionary: A', $content);
        $this->assertStringContainsString('Ava', $content);
        $this->assertStringContainsString('water', $content);
        $this->assertStringContainsString('/ll-tools/dictionary.md', $content);
        $this->assertStringNotContainsString('Bero', $content);
        $this->assertStringNotContainsString('A-secret', $content);
        $this->assertStringNotContainsString('hidden A definition', $content);
        $this->assertContains('A', $letters);
        $this->assertContains('B', $letters);
        $this->assertNotContains('Z', $letters);
    }

    public function test_jsonld_index_exposes_schema_graph_and_dictionary_chunks(): void
    {
        $public_wordset_id = $this->createWordset('AI JSON Public Wordset', 'ai-json-public-wordset');
        $private_wordset_id = $this->createWordset('AI JSON Private Wordset', 'ai-json-private-wordset', true);

        $this->createDictionaryEntry('Ava', $public_wordset_id, 'water');
        $this->createDictionaryEntry('Z-secret', $private_wordset_id, 'hidden Z definition');
        if (function_exists('ll_tools_bump_dictionary_browser_cache_version')) {
            ll_tools_bump_dictionary_browser_cache_version();
        }

        wp_set_current_user(0);
        $json = ll_tools_ai_crawler_build_index_jsonld([
            'dictionary_entry_limit' => 5,
            'dictionary_letter_limit' => 10,
        ]);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertSame('https://schema.org', $data['@context']);
        $graph = $data['@graph'] ?? [];
        $this->assertIsArray($graph);
        $types = array_map(static fn($node): string => is_array($node) ? (string) ($node['@type'] ?? '') : '', $graph);
        $this->assertContains('WebSite', $types);
        $this->assertContains('Dataset', $types);
        $this->assertContains('DefinedTermSet', $types);
        $this->assertContains('ItemList', $types);
        $this->assertStringContainsString('/ll-tools/dictionary/A.md', $json);
        $this->assertStringContainsString('DefinedTerm', $json);
        $this->assertStringContainsString('Ava', $json);
        $this->assertStringContainsString('water', $json);
        $this->assertStringNotContainsString('Z-secret', $json);
        $this->assertStringNotContainsString('hidden Z definition', $json);
    }

    public function test_wordset_and_content_exports_filter_private_surfaces(): void
    {
        $public_wordset_id = $this->createWordset('AI Public Wordset', 'ai-public-wordset');
        $private_wordset_id = $this->createWordset('AI Private Wordset', 'ai-private-wordset', true);
        $public_category_id = $this->createCategory('AI Public Category', 'ai-public-category');
        $private_category_id = $this->createCategory('AI Private Category', 'ai-private-category', true);

        $public_vocab_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Public AI Vocab Lesson',
        ]);
        update_post_meta((int) $public_vocab_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $public_wordset_id);
        update_post_meta((int) $public_vocab_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $public_category_id);

        $private_vocab_id = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson',
            'post_status' => 'publish',
            'post_title' => 'Private AI Vocab Lesson',
        ]);
        update_post_meta((int) $private_vocab_id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, (string) $public_wordset_id);
        update_post_meta((int) $private_vocab_id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, (string) $private_category_id);

        $public_content_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Public AI Content Lesson',
            'post_excerpt' => 'A public listening lesson.',
        ]);
        update_post_meta((int) $public_content_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $public_wordset_id);
        update_post_meta((int) $public_content_id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [(string) $public_category_id, (string) $private_category_id]);
        update_post_meta((int) $public_content_id, LL_TOOLS_CONTENT_LESSON_CUES_META, [
            ['start_ms' => 1000, 'end_ms' => 2500, 'text' => 'First public cue.'],
            ['start_ms' => 3000, 'end_ms' => 4500, 'text' => 'Second public cue.'],
        ]);

        $private_content_id = self::factory()->post->create([
            'post_type' => 'll_content_lesson',
            'post_status' => 'publish',
            'post_title' => 'Private AI Content Lesson',
        ]);
        update_post_meta((int) $private_content_id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, (string) $private_wordset_id);

        wp_set_current_user(0);
        $wordsets = ll_tools_ai_crawler_build_wordsets_markdown(['wordset_limit' => 20, 'lesson_limit' => 20]);
        $content = ll_tools_ai_crawler_build_content_lessons_markdown(['lesson_limit' => 20, 'cue_limit' => 2]);

        $this->assertStringContainsString('AI Public Wordset', $wordsets);
        $this->assertStringNotContainsString('AI Private Wordset', $wordsets);
        $this->assertStringContainsString('Public AI Vocab Lesson', $wordsets);
        $this->assertStringNotContainsString('Private AI Vocab Lesson', $wordsets);

        $this->assertStringContainsString('Public AI Content Lesson', $content);
        $this->assertStringContainsString('A public listening lesson.', $content);
        $this->assertStringContainsString('[0:01] First public cue.', $content);
        $this->assertStringContainsString('AI Public Category', $content);
        $this->assertStringNotContainsString('Private AI Content Lesson', $content);
        $this->assertStringNotContainsString('AI Private Category', $content);
    }

    public function test_privileged_password_visitor_cannot_seed_private_shared_exports(): void
    {
        $cookies = $_COOKIE;
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $public = $this->createWordset('Crawler Visible Scope', 'crawler-visible-scope');
        $private = $this->createWordset('Crawler Hidden Scope', 'crawler-hidden-scope', true);
        $category = $this->createCategory('Crawler Visible Category', 'crawler-visible-category');
        $hidden_category = $this->createCategory('Crawler Hidden Category', 'crawler-hidden-category', true);
        $this->createDictionaryEntry('Crawler Visible Dictionary', $public, 'visible definition');
        $protected_entry = $this->createDictionaryEntry('Crawler Protected Dictionary', $public, 'protected definition');
        $this->createDictionaryEntry('Crawler Private Dictionary', $private, 'private definition');
        wp_update_post(['ID' => $protected_entry, 'post_password' => 'crawler-test-password']);
        foreach (['ll_vocab_lesson', 'll_content_lesson'] as $type) {
            foreach (['Visible', 'Protected', 'Private'] as $visibility) {
                $id = self::factory()->post->create([
                    'post_type' => $type,
                    'post_status' => 'publish',
                    'post_title' => 'Crawler ' . $visibility . ' ' . $type,
                    'post_excerpt' => strtolower($visibility) . ' excerpt',
                    'post_password' => $visibility === 'Protected' ? 'crawler-test-password' : '',
                ]);
                $scope = $visibility === 'Private' ? $private : $public;
                if ($type === 'll_vocab_lesson') {
                    update_post_meta($id, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $scope);
                    update_post_meta($id, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $category);
                } else {
                    update_post_meta($id, LL_TOOLS_CONTENT_LESSON_WORDSET_META, $scope);
                    update_post_meta($id, LL_TOOLS_CONTENT_LESSON_CATEGORY_IDS_META, [$category, $hidden_category]);
                }
            }
        }
        $private_category_lesson = self::factory()->post->create([
            'post_type' => 'll_vocab_lesson', 'post_status' => 'publish', 'post_title' => 'Crawler Private Category Lesson',
        ]);
        update_post_meta($private_category_lesson, LL_TOOLS_VOCAB_LESSON_WORDSET_META, $public);
        update_post_meta($private_category_lesson, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, $hidden_category);
        ll_tools_bump_dictionary_browser_cache_version();
        require_once ABSPATH . WPINC . '/class-phpass.php';
        $hasher = new PasswordHash(8, true);
        $cookie = $hasher->HashPassword('crawler-test-password');
        $exports = [
            ['key' => 'dictionary'], ['key' => 'dictionary-letter', 'letter' => 'C'],
            ['key' => 'index-jsonld'], ['key' => 'wordsets'], ['key' => 'content-lessons'],
        ];
        try {
            foreach ($exports as $export) {
                wp_set_current_user($admin);
                $_COOKIE['wp-postpass_' . COOKIEHASH] = $cookie;
                $this->assertFalse(post_password_required($protected_entry), 'The visitor really has a valid password cookie.');
                ll_tools_ai_crawler_delete_cached_export($export);
                $legacy_args = ll_tools_ai_crawler_export_cache_args($export);
                $legacy_args['schema'] = 2;
                $legacy_key = 'll_ai_export_' . md5((string) wp_json_encode($legacy_args));
                set_transient($legacy_key, 'Crawler Protected Legacy Cache', MINUTE_IN_SECONDS);
                wp_cache_set($legacy_key, 'Crawler Protected Legacy Cache', ll_tools_ai_crawler_cache_group(), MINUTE_IN_SECONDS);
                try {
                    $first = ll_tools_ai_crawler_prepare_export_response('GET', $export);
                    $this->assertTrue($first['ok']);
                    $this->assertSame('MISS', $first['cache_status']);
                    $this->assertStringContainsString('Crawler Visible', $first['body']);
                    foreach (['Crawler Protected', 'Crawler Private', 'Crawler Hidden', 'protected definition', 'private definition', 'protected excerpt', 'private excerpt'] as $hidden) {
                        $this->assertStringNotContainsString($hidden, $first['body'], $export['key']);
                    }
                    unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
                    wp_set_current_user(0);
                    $second = ll_tools_ai_crawler_prepare_export_response('GET', $export);
                    $this->assertSame('HIT', $second['cache_status']);
                    $this->assertSame($first['body'], $second['body']);
                    $head = ll_tools_ai_crawler_prepare_export_response('HEAD', $export);
                    $this->assertSame('HIT', $head['cache_status']);
                    $this->assertSame('', $head['body']);
                    $this->assertFalse($head['send_body']);
                } finally {
                    delete_transient($legacy_key);
                    wp_cache_delete($legacy_key, ll_tools_ai_crawler_cache_group());
                    ll_tools_ai_crawler_delete_cached_export($export);
                }
            }
        } finally {
            $_COOKIE = $cookies;
        }
    }

    public function test_letter_fallback_preserves_empty_private_and_password_only_scopes(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame([], ll_tools_ai_crawler_get_dictionary_letters());
        $private = $this->createWordset('Letter Private Only', 'letter-private-only', true);
        $this->createDictionaryEntry('Z-hidden-only', $private, 'private');
        ll_tools_bump_dictionary_browser_cache_version();
        $this->assertSame([], ll_tools_ai_crawler_get_dictionary_letters());
        $public = $this->createWordset('Letter Password Only', 'letter-password-only');
        $protected = $this->createDictionaryEntry('Q-protected-only', $public, 'protected');
        wp_update_post(['ID' => $protected, 'post_password' => 'letter-password']);
        ll_tools_bump_dictionary_browser_cache_version();
        $this->assertSame([], ll_tools_ai_crawler_get_dictionary_letters());
        $this->createDictionaryEntry('A-public', $public, 'visible');
        ll_tools_bump_dictionary_browser_cache_version();
        $this->assertSame(['A'], ll_tools_ai_crawler_get_dictionary_letters());
        update_term_meta($public, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        ll_tools_bump_wordset_cache_epoch([$public]);
        $this->assertSame([], ll_tools_ai_crawler_get_dictionary_letters(), 'Request-local letter caches must follow visibility epochs.');
    }

    public function test_public_dictionary_entry_never_exports_private_linked_wordset_names(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $public = $this->createWordset('Visible Linked Scope', 'visible-linked-scope');
        $private = $this->createWordset('Hidden Linked Scope', 'hidden-linked-scope', true);
        $entry = $this->createDictionaryEntry('Shared Entry', $public, 'shared definition');
        update_post_meta($entry, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_SCOPE_INDEX_META_KEY, '|' . $public . '|' . $private . '|');
        $item = ll_tools_ai_crawler_dictionary_entry_item($entry, 4);
        $this->assertNotNull($item);
        $this->assertSame(['Visible Linked Scope'], $item['wordset_names']);
    }

    public function test_visibility_change_during_export_build_does_not_publish_a_new_generation(): void
    {
        $public = $this->createWordset('Changing Export Scope', 'changing-export-scope');
        $this->createDictionaryEntry('Changing Entry', $public, 'visible');
        $export = ['key' => 'dictionary'];
        ll_tools_ai_crawler_delete_cached_export($export);
        $change_visibility = static function ($limit) use ($public) {
            update_term_meta($public, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
            ll_tools_bump_wordset_cache_epoch([$public]);
            return $limit;
        };
        add_filter('ll_tools_ai_crawler_dictionary_limit', $change_visibility);
        try {
            $response = ll_tools_ai_crawler_prepare_export_response('GET', $export);
        } finally {
            remove_filter('ll_tools_ai_crawler_dictionary_limit', $change_visibility);
        }
        $this->assertFalse($response['ok']);
        $this->assertSame('', $response['body']);
        $this->assertNull(ll_tools_ai_crawler_get_cached_export($export));
        $next = ll_tools_ai_crawler_prepare_export_response('GET', $export);
        $this->assertTrue($next['ok']);
        $this->assertStringNotContainsString('Changing Entry', $next['body']);
        ll_tools_ai_crawler_delete_cached_export($export);
    }

    private function createWordset(string $name, string $slug, bool $private = false): int
    {
        $result = wp_insert_term($name, 'wordset', ['slug' => $slug]);
        $this->assertIsArray($result);
        $term_id = (int) ($result['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);
        if ($private) {
            update_term_meta($term_id, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
        }
        $this->created_terms[$term_id] = 'wordset';

        return $term_id;
    }

    private function createCategory(string $name, string $slug, bool $private = false): int
    {
        $result = wp_insert_term($name, 'word-category', ['slug' => $slug]);
        $this->assertIsArray($result);
        $term_id = (int) ($result['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);
        if ($private) {
            update_term_meta($term_id, LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, 'private');
        }
        $this->created_terms[$term_id] = 'word-category';

        return $term_id;
    }

    private function createDictionaryEntry(string $title, int $wordset_id, string $definition): int
    {
        $entry_id = wp_insert_post([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_content' => $definition,
        ], true);
        $this->assertIsInt($entry_id);

        update_post_meta((int) $entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (string) $wordset_id);
        update_post_meta((int) $entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [
            [
                'definition' => $definition,
                'translations' => ['en' => $definition],
                'entry_type' => 'noun',
                'source_dictionary' => 'Test Dictionary',
            ],
        ]);

        return (int) $entry_id;
    }
}
