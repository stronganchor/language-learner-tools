<?php
declare(strict_types=1);

final class DictionaryFeatureTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        global $wpdb;

        if (function_exists('ll_tools_dictionary_import_get_job_option_key')) {
            $like = $wpdb->esc_like(LL_TOOLS_DICTIONARY_IMPORT_JOB_OPTION_PREFIX) . '%';
            $option_names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $like
            ));
            foreach ((array) $option_names as $option_name) {
                $job = get_option((string) $option_name);
                if (is_array($job) && function_exists('ll_tools_dictionary_import_delete_path')) {
                    ll_tools_dictionary_import_delete_path((string) ($job['job_dir'] ?? ''));
                }
                delete_option((string) $option_name);
            }
        }
        if (function_exists('ll_tools_dictionary_import_clear_active_job_id')) {
            ll_tools_dictionary_import_clear_active_job_id();
        }
        if (function_exists('ll_tools_dictionary_import_read_history')) {
            foreach (ll_tools_dictionary_import_read_history() as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $backup_path = (string) ($entry['backup_snapshot_path'] ?? '');
                if ($backup_path !== '' && file_exists($backup_path)) {
                    @unlink($backup_path);
                }
            }
        }
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        unset($_COOKIE[LL_TOOLS_I18N_COOKIE]);
        delete_option(LL_TOOLS_DICTIONARY_SOURCES_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_IMPORT_HISTORY_OPTION);
        delete_option(LL_TOOLS_DICTIONARY_BROWSER_CACHE_VERSION_OPTION);
        unset($GLOBALS['ll_tools_dictionary_source_registry_cache']);
        unset($GLOBALS['ll_tools_dictionary_browser_cache_version']);
        parent::tearDown();
    }

    public function test_dictionary_shortcode_defaults_to_unscoped_page_configuration(): void
    {
        $this->assertSame(0, ll_tools_dictionary_shortcode_resolve_wordset_id(''));
        $this->assertSame('[ll_dictionary wordset="0"]', ll_tools_get_dictionary_page_config()['post_content']);
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
                'entry' => 'Bari',
                'definition' => 'shore',
                'entry_type' => 'noun',
                'page_number' => '17',
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

        $this->assertSame(4, (int) ($summary['rows_grouped'] ?? 0));
        $this->assertSame(4, (int) ($summary['entries_created'] ?? 0));

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
            'll_dictionary_letter' => 'B',
            'll_dictionary_page' => '2',
        ];

        $paged_html = do_shortcode(sprintf('[ll_dictionary wordset="%d" per_page="1"]', $wordset_id));
        $this->assertStringContainsString('Showing 2-2 of 2', $paged_html);
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

    public function test_dictionary_import_job_processes_rows_in_batches_with_resume_snapshot(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $job = ll_tools_dictionary_import_create_tsv_job_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Roce',
                'definition' => 'day',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
            'skip_review_rows' => true,
        ], 'harun.tsv');

        $this->assertIsArray($job);
        $this->assertSame('running', $job['status']);
        $this->assertNotSame('', ll_tools_dictionary_import_get_active_job_id());

        $runningSnapshot = ll_tools_dictionary_import_get_job_snapshot($job);
        $this->assertSame('running', $runningSnapshot['status']);
        $this->assertStringContainsString('Keep', (string) $runningSnapshot['advice_title']);

        $processedJob = ll_tools_dictionary_import_process_job($job);
        $this->assertIsArray($processedJob);
        $this->assertSame('completed', $processedJob['status']);
        $this->assertSame('', ll_tools_dictionary_import_get_active_job_id());
        $this->assertSame(3, (int) ($processedJob['summary']['entries_created'] ?? 0));

        $completedSnapshot = ll_tools_dictionary_import_get_job_snapshot($processedJob);
        $this->assertSame('completed', $completedSnapshot['status']);
        $this->assertStringContainsString('Safe', (string) $completedSnapshot['advice_title']);
        $this->assertStringContainsString('Processed 3 rows into 3 dictionary headwords', (string) $completedSnapshot['summary_html']);

        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Dar', 0));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Roce', 0));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Ava', 0));
    }

    public function test_header_tsv_import_supports_multilingual_gloss_columns(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('DEZD Wordset', 'wordset', ['slug' => 'dezd-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];

        $temp_file = tempnam(sys_get_temp_dir(), 'lltd_');
        $this->assertNotFalse($temp_file);

        $tsv = implode("\n", [
            "entry\tdefinition\tgender_number\tentry_type\tparent\tneeds_review\tpage_number\tsource_dictionary\tsource_row_idx\traw_headword\ttitle_keys\tdefinition_full_tr\tdefinition_full_de\tdefinition_full_en",
            "Ava\tsu | Wasser | water\t\tnoun\t\t0\t6\tDEZD\t42\tava\tava|aw\tsu\tWasser\twater",
            "Ava rê\tsu phrase\t\tnoun\t\t0\t7\tDEZD\t43\tava rê\tava-re\tsulu ifade\tWasserphrase\twater phrase",
        ]);
        $this->assertNotFalse(file_put_contents($temp_file, $tsv));

        try {
            $rows = ll_tools_dictionary_parse_tsv_file($temp_file);
            $this->assertIsArray($rows);
            $this->assertSame('Wasser', $rows[0]['definition_full_de'] ?? '');

            $summary = ll_tools_dictionary_import_rows($rows, [
                'wordset_id' => $wordset_id,
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
                'skip_review_rows' => true,
            ]);
        } finally {
            @unlink($temp_file);
        }

        $this->assertSame(2, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Ava', $wordset_id);
        $this->assertGreaterThan(0, $entry_id);

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(1, $senses);
        $this->assertSame('water', $senses[0]['definition']);
        $this->assertSame([
            'tr' => 'su',
            'de' => 'Wasser',
            'en' => 'water',
        ], $senses[0]['translations']);
        $this->assertSame('water', ll_tools_get_dictionary_entry_translation($entry_id));

        $this->assertSame('Wasser', ll_tools_dictionary_lookup_best('Ava', 'Zazaki', 'German', false));
        $this->assertSame('Ava', ll_tools_dictionary_lookup_best('water', 'English', 'Zazaki', true));
        $this->assertSame('Ava', ll_tools_dictionary_lookup_best('Wasser', 'German', 'Zazaki', true));

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ava',
        ]);
        update_post_meta($word_id, 'word_translation', 'water');
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        $link_result = ll_tools_assign_dictionary_entry_to_word($word_id, $entry_id, '');
        $this->assertIsArray($link_result);
        $this->assertSame($entry_id, ll_tools_get_word_dictionary_entry_id($word_id));
        $this->assertContains($word_id, ll_tools_get_dictionary_entry_word_ids($entry_id, -1));

        $_COOKIE[LL_TOOLS_I18N_COOKIE] = 'de_DE';

        $_GET = [
            'll_dictionary_q' => 'Ava',
        ];

        $html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Wasser', $html);
        $this->assertStringContainsString('water', $html);
        $this->assertStringContainsString('su', $html);
        $this->assertStringContainsString('ll_dictionary_entry=' . $entry_id, $html);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Back to dictionary', $detail_html);
        $this->assertStringContainsString('Translations', $detail_html);
        $this->assertStringContainsString('Related Entries', $detail_html);
        $this->assertStringContainsString('Ava rê', $detail_html);
    }

    public function test_dictionary_sources_apply_defaults_and_render_attribution_filters(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Used with permission from the DEZD glossary.',
                'attribution_url' => 'https://example.com/dezd-license',
                'default_dialects' => [],
            ],
            [
                'id' => 'harun-turgut',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Used with permission from Harun Turgut.',
                'attribution_url' => 'https://example.com/harun-license',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'source_dictionary' => 'Harun Turgut',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Dar',
                'definition' => 'tree trunk',
                'entry_type' => 'noun',
                'source_dictionary' => 'DEZD',
                'dialects' => 'Siverek',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
            'skip_review_rows' => true,
        ]);

        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));

        $entry_id = ll_tools_dictionary_find_entry_by_title('Dar', 0);
        $this->assertGreaterThan(0, $entry_id);

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $this->assertCount(2, $senses);
        $source_ids = array_values(array_filter(array_map(static function (array $sense): string {
            return (string) ($sense['source_id'] ?? '');
        }, $senses)));
        $this->assertContains('dezd', $source_ids);
        $this->assertContains('harun-turgut', $source_ids);

        $dialects = ll_tools_dictionary_collect_dialects($senses);
        $this->assertContains('Palu - Bingöl', $dialects);
        $this->assertContains('Siverek', $dialects);

        $_GET = [
            'll_dictionary_source' => 'harun-turgut',
        ];

        $filtered_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('name="ll_dictionary_source"', $filtered_html);
        $this->assertStringContainsString('name="ll_dictionary_dialect"', $filtered_html);
        $this->assertStringContainsString('Dar', $filtered_html);
        $this->assertStringContainsString('Harun Turgut', $filtered_html);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('DEZD', $detail_html);
        $this->assertStringContainsString('Harun Turgut', $detail_html);
        $this->assertStringContainsString('Palu - Bingöl', $detail_html);
        $this->assertStringContainsString('Siverek', $detail_html);
        $this->assertStringContainsString('License and attribution details', $detail_html);
        $this->assertStringContainsString('https://example.com/dezd-license', $detail_html);
        $this->assertStringContainsString('https://example.com/harun-license', $detail_html);
    }

    public function test_dictionary_scope_filter_index_refreshes_after_source_and_entry_updates(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('Dictionary Cache Wordset', 'wordset', ['slug' => 'dictionary-cache-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_language', 'zza');

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Original attribution.',
                'attribution_url' => 'https://example.com/harun',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'source_id' => 'harun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
            'skip_review_rows' => true,
        ]);

        $this->assertSame(1, (int) ($summary['entries_created'] ?? 0));

        $initial_index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
        $this->assertContains('A', (array) ($initial_index['letters'] ?? []));
        $this->assertContains('Palu - Bingöl', (array) ($initial_index['dialect_options'] ?? []));
        $this->assertSame('noun', (string) ($initial_index['pos_options'][0]['slug'] ?? ''));
        $this->assertSame('Harun Turgut', (string) ($initial_index['source_options'][0]['label'] ?? ''));

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'harun',
                'label' => 'Harun Updated',
                'attribution_text' => 'Updated attribution.',
                'attribution_url' => 'https://example.com/harun-updated',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $updated_index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
        $this->assertSame('Harun Updated', (string) ($updated_index['source_options'][0]['label'] ?? ''));

        $follow_up_summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Êvar',
                'definition' => 'evening',
                'entry_type' => 'noun',
                'source_id' => 'harun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
            'skip_review_rows' => true,
        ]);

        $this->assertSame(1, (int) ($follow_up_summary['entries_created'] ?? 0));
        $this->assertContains('Ê', ll_tools_dictionary_get_available_letters($wordset_id));
    }

    public function test_dictionary_query_cache_refreshes_after_linked_word_updates(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $entry_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($entry_result);
        $entry_id = (int) ($entry_result['entry_id'] ?? 0);
        $this->assertGreaterThan(0, $entry_id);

        $palu_wordset = wp_insert_term('Palu Cache Wordset', 'wordset', ['slug' => 'palu-cache-wordset']);
        $bingol_wordset = wp_insert_term('Bingol Cache Wordset', 'wordset', ['slug' => 'bingol-cache-wordset']);
        $this->assertIsArray($palu_wordset);
        $this->assertIsArray($bingol_wordset);
        $palu_wordset_id = (int) $palu_wordset['term_id'];
        $bingol_wordset_id = (int) $bingol_wordset['term_id'];

        $palu_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($palu_word_id, 'word_translation', 'tree');
        wp_set_object_terms($palu_word_id, [$palu_wordset_id], 'wordset', false);
        $this->assertIsArray(ll_tools_assign_dictionary_entry_to_word($palu_word_id, $entry_id, ''));

        $first_query = ll_tools_dictionary_query_entries([
            'search' => 'Dar',
            'page' => 1,
            'per_page' => 5,
            'sense_limit' => 1,
            'linked_word_limit' => 4,
            'post_status' => ['publish'],
        ]);

        $this->assertSame(1, (int) ($first_query['total'] ?? 0));
        $first_item = (array) (($first_query['items'][0] ?? []));
        $this->assertSame(1, (int) ($first_item['linked_word_count'] ?? 0));
        $this->assertSame(['Palu Cache Wordset'], array_values(array_map('strval', (array) ($first_item['wordset_names'] ?? []))));

        $bingol_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($bingol_word_id, 'word_translation', 'tree');
        wp_set_object_terms($bingol_word_id, [$bingol_wordset_id], 'wordset', false);
        $this->assertIsArray(ll_tools_assign_dictionary_entry_to_word($bingol_word_id, $entry_id, ''));

        $second_query = ll_tools_dictionary_query_entries([
            'search' => 'Dar',
            'page' => 1,
            'per_page' => 5,
            'sense_limit' => 1,
            'linked_word_limit' => 4,
            'post_status' => ['publish'],
        ]);

        $this->assertSame(1, (int) ($second_query['total'] ?? 0));
        $second_item = (array) (($second_query['items'][0] ?? []));
        $wordset_names = array_values(array_map('strval', (array) ($second_item['wordset_names'] ?? [])));
        sort($wordset_names);
        $this->assertSame(['Bingol Cache Wordset', 'Palu Cache Wordset'], $wordset_names);
        $this->assertSame(2, (int) ($second_item['linked_word_count'] ?? 0));
    }

    public function test_dictionary_entry_detail_spans_multiple_wordsets_when_words_share_one_entry(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $entry_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);

        $this->assertIsArray($entry_result);
        $entry_id = (int) ($entry_result['entry_id'] ?? 0);
        $this->assertGreaterThan(0, $entry_id);

        $palu_wordset = wp_insert_term('Palu Wordset', 'wordset', ['slug' => 'palu-wordset']);
        $bingol_wordset = wp_insert_term('Bingol Wordset', 'wordset', ['slug' => 'bingol-wordset']);
        $this->assertIsArray($palu_wordset);
        $this->assertIsArray($bingol_wordset);
        $palu_wordset_id = (int) $palu_wordset['term_id'];
        $bingol_wordset_id = (int) $bingol_wordset['term_id'];

        $palu_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($palu_word_id, 'word_translation', 'tree');
        wp_set_object_terms($palu_word_id, [$palu_wordset_id], 'wordset', false);

        $bingol_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        update_post_meta($bingol_word_id, 'word_translation', 'tree');
        wp_set_object_terms($bingol_word_id, [$bingol_wordset_id], 'wordset', false);

        $first_link_result = ll_tools_assign_dictionary_entry_to_word($palu_word_id, $entry_id, '');
        $second_link_result = ll_tools_assign_dictionary_entry_to_word($bingol_word_id, $entry_id, '');
        $this->assertIsArray($first_link_result);
        $this->assertIsArray($second_link_result);

        $scope_wordset_ids = ll_tools_get_dictionary_entry_scope_wordset_ids($entry_id);
        sort($scope_wordset_ids);
        $expected_scope_ids = [$palu_wordset_id, $bingol_wordset_id];
        sort($expected_scope_ids);
        $this->assertSame($expected_scope_ids, $scope_wordset_ids);
        $this->assertSame(0, ll_tools_get_dictionary_entry_wordset_id($entry_id));

        $_GET = [
            'll_dictionary_q' => 'Dar',
        ];

        $search_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Palu Wordset', $search_html);
        $this->assertStringContainsString('Bingol Wordset', $search_html);

        $_GET = [
            'll_dictionary_entry' => (string) $entry_id,
        ];

        $detail_html = do_shortcode('[ll_dictionary]');
        $this->assertStringContainsString('Palu Wordset', $detail_html);
        $this->assertStringContainsString('Bingol Wordset', $detail_html);
    }

    public function test_dictionary_snapshot_override_and_undo_preserve_linked_words(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        ll_tools_update_dictionary_source_registry([
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Original DEZD attribution.',
                'attribution_url' => 'https://example.com/dezd',
                'default_dialects' => [],
            ],
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Original Harun attribution.',
                'attribution_url' => 'https://example.com/harun',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ]);

        $dar_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Dar',
                'definition' => 'tree',
                'entry_type' => 'noun',
                'source_dictionary' => 'Harun Turgut',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $this->assertIsArray($dar_result);

        $roc_result = ll_tools_dictionary_upsert_entry_from_rows([
            [
                'entry' => 'Roc',
                'definition' => 'day',
                'entry_type' => 'noun',
                'source_dictionary' => 'DEZD',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
        ]);
        $this->assertIsArray($roc_result);

        $dar_entry_id = (int) ($dar_result['entry_id'] ?? 0);
        $roc_entry_id = (int) ($roc_result['entry_id'] ?? 0);
        $this->assertGreaterThan(0, $dar_entry_id);
        $this->assertGreaterThan(0, $roc_entry_id);

        $dar_import_key = ll_tools_get_dictionary_entry_import_key($dar_entry_id, true);
        $this->assertNotSame('', $dar_import_key);

        $palu_wordset = wp_insert_term('Palu Snapshot Wordset', 'wordset', ['slug' => 'palu-snapshot-wordset']);
        $bingol_wordset = wp_insert_term('Bingol Snapshot Wordset', 'wordset', ['slug' => 'bingol-snapshot-wordset']);
        $this->assertIsArray($palu_wordset);
        $this->assertIsArray($bingol_wordset);
        $palu_wordset_id = (int) $palu_wordset['term_id'];
        $bingol_wordset_id = (int) $bingol_wordset['term_id'];

        $palu_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        wp_set_object_terms($palu_word_id, [$palu_wordset_id], 'wordset', false);
        update_post_meta($palu_word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $dar_entry_id);

        $bingol_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Dar',
        ]);
        wp_set_object_terms($bingol_word_id, [$bingol_wordset_id], 'wordset', false);
        update_post_meta($bingol_word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $dar_entry_id);

        $snapshot = ll_tools_dictionary_build_snapshot();
        $this->assertSame(2, (int) ($snapshot['entry_count'] ?? 0));
        $this->assertSame('ll-tools-dictionary-snapshot', $snapshot['format'] ?? '');

        $snapshot['sources'] = [
            [
                'id' => 'dezd',
                'label' => 'DEZD',
                'attribution_text' => 'Updated DEZD attribution.',
                'attribution_url' => 'https://example.com/dezd-updated',
                'default_dialects' => ['Siverek'],
            ],
            [
                'id' => 'harun',
                'label' => 'Harun Turgut',
                'attribution_text' => 'Updated Harun attribution.',
                'attribution_url' => 'https://example.com/harun-updated',
                'default_dialects' => ['Palu - Bingöl'],
            ],
        ];

        $snapshot['entries'] = array_values(array_filter(array_map(static function (array $entry): ?array {
            if (($entry['title'] ?? '') === 'Roc') {
                return null;
            }
            if (($entry['title'] ?? '') === 'Dar') {
                $entry['translation'] = 'tree updated';
                $entry['senses'][0]['definition'] = 'tree updated';
                $entry['senses'][0]['source_id'] = 'harun';
                $entry['senses'][0]['source_dictionary'] = 'Harun Turgut';
                return $entry;
            }

            return $entry;
        }, (array) ($snapshot['entries'] ?? []))));

        $snapshot['entries'][] = [
            'import_key' => ll_tools_dictionary_generate_import_key(),
            'title' => 'Ava',
            'status' => 'publish',
            'translation' => 'water',
            'wordset' => null,
            'senses' => [
                [
                    'definition' => 'water',
                    'entry_type' => 'noun',
                    'entry_lang' => 'Zazaki',
                    'def_lang' => 'English',
                    'source_id' => 'dezd',
                    'source_dictionary' => 'DEZD',
                    'dialects' => ['Siverek'],
                    'translations' => [
                        'en' => 'water',
                    ],
                ],
            ],
        ];
        $snapshot['entry_count'] = count($snapshot['entries']);

        $job = ll_tools_dictionary_import_create_snapshot_job_from_snapshot($snapshot, [
            'snapshot_mode' => 'override',
        ], 'dictionary-site.json');
        $this->assertIsArray($job);
        $this->assertSame('running', $job['status']);
        $this->assertNotSame('', (string) ($job['backup_snapshot_path'] ?? ''));
        $this->assertFileExists((string) $job['backup_snapshot_path']);

        while (is_array($job) && (string) ($job['status'] ?? '') === 'running') {
            $job = ll_tools_dictionary_import_process_job($job);
        }

        $this->assertIsArray($job);
        $this->assertSame('completed', $job['status']);
        $this->assertSame(1, (int) ($job['summary']['entries_created'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($job['summary']['entries_updated'] ?? 0));
        $this->assertSame(1, (int) ($job['summary']['entries_deleted'] ?? 0));

        $dar_entry_after_id = ll_tools_dictionary_find_entry_by_import_key($dar_import_key);
        $this->assertSame($dar_entry_id, $dar_entry_after_id);
        $this->assertContains($palu_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_after_id, -1));
        $this->assertContains($bingol_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_after_id, -1));
        $this->assertSame('tree updated', ll_tools_get_dictionary_entry_translation($dar_entry_after_id));
        $this->assertSame(0, ll_tools_dictionary_find_entry_by_title('Roc', 0));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Ava', 0));

        $sources_after_override = ll_tools_get_dictionary_source_registry();
        $this->assertSame('Updated DEZD attribution.', $sources_after_override['dezd']['attribution_text'] ?? '');
        $this->assertSame('https://example.com/harun-updated', $sources_after_override['harun']['attribution_url'] ?? '');

        $history = ll_tools_dictionary_import_read_history();
        $this->assertNotEmpty($history);
        $latest_history = $history[0];
        $this->assertSame('snapshot', $latest_history['type'] ?? '');
        $this->assertSame('dictionary-site.json', $latest_history['source_label'] ?? '');

        $undo_job = ll_tools_dictionary_import_create_undo_job((string) ($latest_history['id'] ?? ''));
        $this->assertIsArray($undo_job);

        while (is_array($undo_job) && (string) ($undo_job['status'] ?? '') === 'running') {
            $undo_job = ll_tools_dictionary_import_process_job($undo_job);
        }

        $this->assertIsArray($undo_job);
        $this->assertSame('completed', $undo_job['status']);
        $this->assertSame($dar_entry_id, ll_tools_dictionary_find_entry_by_import_key($dar_import_key));
        $this->assertSame('tree', ll_tools_get_dictionary_entry_translation($dar_entry_id));
        $this->assertGreaterThan(0, ll_tools_dictionary_find_entry_by_title('Roc', 0));
        $this->assertSame(0, ll_tools_dictionary_find_entry_by_title('Ava', 0));
        $this->assertContains($palu_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_id, -1));
        $this->assertContains($bingol_word_id, ll_tools_get_dictionary_entry_word_ids($dar_entry_id, -1));

        $history_after_undo = ll_tools_dictionary_import_read_history();
        $this->assertNotEmpty($history_after_undo);
        $this->assertSame('undo', $history_after_undo[0]['history_mode'] ?? '');
        $this->assertNotEmpty($history_after_undo[1]['undone_at'] ?? 0);

        $sources_after_undo = ll_tools_get_dictionary_source_registry();
        $this->assertSame('Original DEZD attribution.', $sources_after_undo['dezd']['attribution_text'] ?? '');
        $this->assertSame('https://example.com/harun', $sources_after_undo['harun']['attribution_url'] ?? '');
    }

    public function test_shortcode_starts_compact_and_exposes_language_specific_letter_browse(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->ensurePartOfSpeechTerm('noun', 'Noun');

        $wordset = wp_insert_term('Zazaki Browse Wordset', 'wordset', ['slug' => 'zazaki-browse-wordset']);
        $this->assertIsArray($wordset);
        $wordset_id = (int) $wordset['term_id'];
        update_term_meta($wordset_id, 'll_language', 'zza');

        $summary = ll_tools_dictionary_import_rows([
            [
                'entry' => 'Ava',
                'definition' => 'water',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Êvar',
                'definition' => 'evening',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'İnce',
                'definition' => 'thin',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
            [
                'entry' => 'Ûsiv',
                'definition' => 'something',
                'entry_type' => 'noun',
                'entry_lang' => 'Zazaki',
                'def_lang' => 'English',
            ],
        ], [
            'wordset_id' => $wordset_id,
            'entry_lang' => 'Zazaki',
            'def_lang' => 'English',
            'skip_review_rows' => true,
        ]);

        $this->assertSame(4, (int) ($summary['entries_created'] ?? 0));

        $_GET = [];
        $idle_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('ll-dictionary__toolbar is-collapsed', $idle_html);
        $this->assertStringContainsString('name="ll_dictionary_q"', $idle_html);
        $this->assertStringNotContainsString('ll-dictionary__results', $idle_html);
        $this->assertStringNotContainsString('Showing 1-20', $idle_html);
        $this->assertStringContainsString('ll_dictionary_letter=Ê', $idle_html);
        $this->assertStringContainsString('ll_dictionary_letter=İ', $idle_html);
        $this->assertStringContainsString('ll_dictionary_letter=Û', $idle_html);

        $_GET = [
            'll_dictionary_letter' => 'Ê',
        ];
        $letter_html = do_shortcode(sprintf('[ll_dictionary wordset="%d"]', $wordset_id));
        $this->assertStringContainsString('Êvar', $letter_html);
        $this->assertStringNotContainsString('Ava', $letter_html);
        $this->assertStringContainsString('Showing 1-1 of 1', $letter_html);
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
