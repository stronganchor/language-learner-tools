<?php
declare(strict_types=1);

final class DictionaryFeatureTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function test_import_groups_duplicate_headwords_and_shortcode_paginates_results(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');
        $this->ensurePartOfSpeechTerm('verb', 'Verb');

        $wordset = wp_insert_term('Dictionary Test Wordset', 'wordset', ['slug' => 'dictionary-test-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Roce',
                'definition' => 'day',
                'entry_type' => 'noun',
                'page_number' => '12',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Roce',
                'definition' => 'sun',
                'entry_type' => 'noun',
                'page_number' => '12',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Biya',
                'definition' => 'come',
                'entry_type' => 'verb',
                'page_number' => '18',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'page_number' => '6',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'Turkish',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'Turkish',
            'skip_review_rows' => true,
        ]);

        $this->assertSame(3, (int) ($summary['rows_grouped'] ?? 0));
        $this->assertSame(3, (int) ($summary['entries_created'] ?? 0));

        $roce_entry_id = ll_tools_dictionary_find_entry_by_title('Roce', $wordset_id);
        $this->assertGreaterThan(0, $roce_entry_id);
        $this->assertSame(2, count(ll_tools_get_dictionary_entry_senses($roce_entry_id)));
        $this->assertSame('noun', ll_tools_get_dictionary_entry_primary_pos_slug($roce_entry_id));
        $this->assertSame('day; sun', ll_tools_get_dictionary_entry_translation($roce_entry_id));

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Roce',
        ]);
        update_post_meta($word_id, 'word_translation', 'day');
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $roce_entry_id);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $_GET = [
            'll_dictionary_q' => 'Ro',
            'll_dictionary_page' => '1',
        ];

        $search_html = do_shortcode(sprintf('[ll_dictionary wordset="%d" per_page="2" linked_word_limit="2"]', $wordset_id));
        $this->assertStringContainsString('Roce', $search_html);
        $this->assertStringContainsString('day; sun', $search_html);
        $this->assertStringContainsString('Showing 1-1 of 1', $search_html);
        $this->assertStringContainsString('linked word', strtolower($search_html));

        $_GET = [
            'll_dictionary_page' => '2',
        ];

        $paged_html = do_shortcode(sprintf('[ll_dictionary wordset="%d" per_page="1"]', $wordset_id));
        $this->assertStringContainsString('Showing 2-2 of 3', $paged_html);
        $this->assertStringContainsString('Biya', $paged_html);
    }

    public function test_bulk_translation_lookup_prefers_imported_dictionary_entries(): void
    {
        $entry_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Mij',
                'definition' => 'moon',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($entry_result);
        $this->assertSame('moon', ll_tools_dictionary_lookup_best('Mij', 'Zazaki', 'English', false));
        $this->assertSame('Mij', ll_tools_dictionary_lookup_best('moon', 'English', 'Zazaki', true));
        $this->assertSame('moon', ll_dictionary_lookup_best('Mij', 'Zazaki', 'English', false));
    }

    private function ensurePartOfSpeechTerm(string $slug, string $label): void
    {
        $existing = get_term_by('slug', $slug, 'part_of_speech');
        if ($existing && !is_wp_error($existing)) {
            return;
        }

        $result = wp_insert_term($label, 'part_of_speech', ['slug' => $slug]);
        $this->assertTrue(is_array($result) || is_wp_error($result));
    }
}
