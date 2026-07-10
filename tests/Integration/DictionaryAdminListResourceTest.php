<?php
declare(strict_types=1);

final class DictionaryAdminListResourceTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_userdata($user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        wp_set_current_user($user_id);
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        unset($GLOBALS['ll_tools_dictionary_admin_linked_counts']);
        parent::tearDown();
    }

    public function test_words_filter_renders_bounded_search_instead_of_all_entry_options(): void
    {
        global $typenow;

        for ($index = 1; $index <= 30; $index++) {
            $this->createEntry('Filter Entry ' . $index, []);
        }

        $dictionary_queries = [];
        $watch_queries = static function (WP_Query $query) use (&$dictionary_queries): void {
            if ($query->get('post_type') === 'll_dictionary_entry') {
                $dictionary_queries[] = (int) $query->get('posts_per_page');
            }
        };
        add_action('pre_get_posts', $watch_queries);
        $previous_typenow = $typenow;
        $typenow = 'words';
        try {
            ob_start();
            ll_tools_words_add_dictionary_entry_filter();
            $html = (string) ob_get_clean();
        } finally {
            $typenow = $previous_typenow;
            remove_action('pre_get_posts', $watch_queries);
        }

        $this->assertSame([], $dictionary_queries);
        $this->assertStringContainsString('data-ll-dictionary-filter-search', $html);
        $this->assertStringContainsString('Specific Dictionary Entry', $html);
        $this->assertStringNotContainsString('Filter Entry 30', $html);
        $this->assertSame(3, substr_count($html, '<option'));
    }

    public function test_dictionary_admin_filters_use_sql_without_full_entry_hydration(): void
    {
        $wordset = wp_insert_term('Admin Filter Wordset', 'wordset');
        $noun = wp_insert_term('Admin Filter Noun', 'part_of_speech', ['slug' => 'admin-filter-noun']);
        $verb = wp_insert_term('Admin Filter Verb', 'part_of_speech', ['slug' => 'admin-filter-verb']);
        $this->assertIsArray($wordset);
        $this->assertIsArray($noun);
        $this->assertIsArray($verb);
        $wordset_id = (int) $wordset['term_id'];

        $entry_a = $this->createEntry('Admin Entry A', [
            'source_id' => 'source-a',
            'source_dictionary' => 'Source A',
            'dialects' => ['Dialect A'],
            'entry_type' => 'headword',
            'pos' => 'admin-filter-noun',
            'review' => 'needs_review',
            'wordset_id' => $wordset_id,
        ]);
        $entry_b = $this->createEntry('Admin Entry B', [
            'source_id' => 'source-b',
            'source_dictionary' => 'Source B',
            'dialects' => ['Dialect B'],
            'entry_type' => 'phrase',
            'pos' => 'admin-filter-verb',
        ]);
        $entry_c = $this->createEntry('Admin Entry C', [
            'source_id' => 'source-c',
            'source_dictionary' => 'Source C',
            'dialects' => ['Dialect A'],
            'entry_type' => 'headword',
        ]);

        $lookup_rows = ll_tools_dictionary_build_lookup_rows_for_entry($entry_a);
        $lookup_kinds = array_values(array_unique(wp_list_pluck($lookup_rows, 'lookup_kind')));
        $this->assertContains('source', $lookup_kinds);
        $this->assertContains('dialect', $lookup_kinds);

        $this->createLinkedWord($entry_a, $wordset_id, (int) $noun['term_id']);
        $this->createLinkedWord($entry_c, $wordset_id, (int) $noun['term_id']);
        delete_post_meta($entry_c, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_SCOPE_INDEX_META_KEY);

        $this->assertSame([$entry_a, $entry_c], $this->queryEntryIds(['wordset' => (string) $wordset_id]));
        $this->assertSame([$entry_b], $this->queryEntryIds(['wordset' => '__unscoped__']));
        $this->assertSame([$entry_a, $entry_c], $this->queryEntryIds(['pos' => 'admin-filter-noun']));
        $this->assertSame([$entry_a], $this->queryEntryIds(['source' => 'source-a']));
        $this->assertSame([$entry_a, $entry_c], $this->queryEntryIds(['dialect' => 'Dialect A']));
        $this->assertSame([$entry_b], $this->queryEntryIds(['type' => 'phrase']));
        $this->assertSame([$entry_a], $this->queryEntryIds(['review' => 'needs_review']));
        $this->assertSame([$entry_b, $entry_c], $this->queryEntryIds(['review' => 'clean']));
        $this->assertSame([$entry_a, $entry_c], $this->queryEntryIds(['linked' => 'linked']));
        $this->assertSame([$entry_b], $this->queryEntryIds(['linked' => 'unlinked']));
    }

    public function test_dictionary_admin_columns_use_one_batched_link_count_query(): void
    {
        $entry_a = $this->createEntry('Counted Entry A', []);
        $entry_b = $this->createEntry('Counted Entry B', []);
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Counted Linked Word',
        ]);
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_a);

        $query = new WP_Query();
        $query->set('post_type', 'll_dictionary_entry');
        $query->set('ll_tools_dictionary_prime_admin_counts', true);
        $posts = [get_post($entry_a), get_post($entry_b)];
        $posts = array_values(array_filter($posts, static function ($post): bool {
            return $post instanceof WP_Post;
        }));
        ll_tools_dictionary_entry_prime_admin_linked_counts($posts, $query);

        $word_queries = 0;
        $watch_queries = static function (WP_Query $watched_query) use (&$word_queries): void {
            if ($watched_query->get('post_type') === 'words') {
                $word_queries++;
            }
        };
        add_action('pre_get_posts', $watch_queries);
        try {
            ob_start();
            ll_tools_dictionary_entry_column_content('linked_words', $entry_a);
            ll_tools_dictionary_entry_column_content('linked_words', $entry_b);
            $html = (string) ob_get_clean();
        } finally {
            remove_action('pre_get_posts', $watch_queries);
        }

        $this->assertSame(0, $word_queries);
        $this->assertStringContainsString('1 linked word', $html);
        $this->assertStringContainsString('View linked words', $html);
        $this->assertStringNotContainsString('ll-dictionary-entry-linked-list', $html);
    }

    /** @param array<string,string|int> $args */
    private function createEntry(string $title, array $args): int
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        $sense = [
            'definition' => $title . ' definition',
            'source_id' => (string) ($args['source_id'] ?? ''),
            'source_dictionary' => (string) ($args['source_dictionary'] ?? ''),
            'dialects' => (array) ($args['dialects'] ?? []),
            'entry_type' => (string) ($args['entry_type'] ?? ''),
        ];
        update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, [$sense]);
        if (!empty($args['entry_type'])) {
            update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY, (string) $args['entry_type']);
        }
        if (!empty($args['pos'])) {
            update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, (string) $args['pos']);
        }
        if (!empty($args['review'])) {
            update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY, (string) $args['review']);
        }
        if (!empty($args['wordset_id'])) {
            update_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, (int) $args['wordset_id']);
        }
        ll_tools_dictionary_refresh_entry_search_meta($entry_id, get_post($entry_id), true);
        ll_tools_refresh_dictionary_entry_wordset_scope_meta($entry_id);
        ll_tools_dictionary_sync_lookup_rows_for_entry($entry_id, false);
        return $entry_id;
    }

    private function createLinkedWord(int $entry_id, int $wordset_id, int $pos_term_id): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Linked Word ' . $entry_id,
        ]);
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_id);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_object_terms($word_id, [$pos_term_id], 'part_of_speech', false);
        ll_tools_refresh_dictionary_entry_wordset_scope_meta($entry_id);
        return $word_id;
    }

    /** @param array<string,string> $filters @return int[] */
    private function queryEntryIds(array $filters): array
    {
        $defaults = [
            'wordset' => '',
            'pos' => '',
            'source' => '',
            'dialect' => '',
            'type' => '',
            'review' => '',
            'linked' => '',
        ];
        $query = new WP_Query([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'll_tools_dictionary_admin_filters' => array_merge($defaults, $filters),
        ]);
        $ids = array_values(array_map('intval', (array) $query->posts));
        $this->assertSame([], array_values(array_filter(array_map('intval', (array) $query->get('post__in')))));
        return $ids;
    }
}
