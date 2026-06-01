<?php
declare(strict_types=1);

final class IpaOrthographyConversionTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    /** @var mixed */
    private $titleRoleBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
        $this->titleRoleBackup = get_option('ll_word_title_language_role', 'target');
        update_option('ll_word_title_language_role', 'translation');

        if (function_exists('ll_tools_register_words_post_type')) {
            ll_tools_register_words_post_type();
        }
        if (function_exists('ll_tools_register_word_audio_post_type')) {
            ll_tools_register_word_audio_post_type();
        }
        if (function_exists('ll_tools_register_wordset_taxonomy')) {
            ll_tools_register_wordset_taxonomy();
        }
        if (function_exists('ll_tools_register_recording_type_taxonomy')) {
            ll_tools_register_recording_type_taxonomy();
        }

        register_taxonomy_for_object_type('wordset', 'words');
        register_taxonomy_for_object_type('recording_type', 'word_audio');
        $this->ensureRecordingTypeTerm('Isolation', 'isolation');
    }

    protected function tearDown(): void
    {
        update_option('ll_word_title_language_role', $this->titleRoleBackup);
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_orthography_data_detects_word_final_rule_split_and_lists_convertible_word(): void
    {
        $wordset_id = $this->createWordset('Orthography Wordset');

        $this->createWordWithRecording($wordset_id, 'Gloss 1', 'maš', 'maš', 'maʃ');
        $this->createWordWithRecording($wordset_id, 'Gloss 2', 'taš', 'taš', 'taʃ');
        $this->createWordWithRecording($wordset_id, 'Gloss 3', 'sha', 'sha', 'ʃa');
        $this->createWordWithRecording($wordset_id, 'Gloss 4', 'sho', 'sho', 'ʃo');
        $this->createWordWithRecording($wordset_id, 'Gloss 5', 'ba', 'ba', 'ba');

        $candidate_word_id = $this->createWord($wordset_id, 'Candidate Gloss', '');
        $candidate_recording_id = $this->createRecording($candidate_word_id, '', 'baʃ');

        $data = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);

        $this->assertTrue((bool) ($data['supported'] ?? false));
        $this->assertSame(0, (int) (($data['stats']['active_contradiction_count'] ?? 0)));

        $segment_row = $this->findRuleRow((array) ($data['rules'] ?? []), 'ʃ');
        $this->assertNotNull($segment_row);

        $auto_rules = [];
        foreach ((array) ($segment_row['auto'] ?? []) as $entry) {
            $auto_rules[(string) ($entry['context'] ?? '')] = (string) ($entry['output'] ?? '');
        }

        $this->assertSame('š', (string) ($auto_rules['final'] ?? ''));
        $this->assertSame('sh', (string) ($auto_rules['nonfinal'] ?? ''));

        $candidates = (array) ($data['conversion_candidates'] ?? []);
        $this->assertCount(1, $candidates);
        $this->assertSame($candidate_word_id, (int) ($candidates[0]['word_id'] ?? 0));
        $this->assertSame($candidate_recording_id, (int) ($candidates[0]['recording_id'] ?? 0));
        $this->assertSame('baš', (string) ($candidates[0]['predicted_text'] ?? ''));
        $this->assertTrue((bool) ($candidates[0]['can_convert'] ?? false));
    }

    public function test_bulk_convert_handler_fills_missing_word_text_and_recording_text(): void
    {
        $wordset_id = $this->createWordset('Orthography Convert');

        $this->createWordWithRecording($wordset_id, 'Gloss 1', 'maš', 'maš', 'maʃ');
        $this->createWordWithRecording($wordset_id, 'Gloss 2', 'taš', 'taš', 'taʃ');
        $this->createWordWithRecording($wordset_id, 'Gloss 3', 'sha', 'sha', 'ʃa');
        $this->createWordWithRecording($wordset_id, 'Gloss 4', 'sho', 'sho', 'ʃo');
        $this->createWordWithRecording($wordset_id, 'Gloss 5', 'ba', 'ba', 'ba');

        $candidate_word_id = $this->createWord($wordset_id, 'Candidate Gloss', '');
        $candidate_recording_id = $this->createRecording($candidate_word_id, '', 'baʃ');

        $user_id = $this->createViewerUser();
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'word_ids' => [$candidate_word_id],
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_convert_ipa_keyboard_orthography_words_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame(1, (int) (($response['data']['converted_count'] ?? 0)));
        $this->assertSame('baš', (string) get_post_meta($candidate_word_id, 'word_translation', true));
        $this->assertSame('baš', (string) get_post_meta($candidate_recording_id, 'recording_text', true));
        $this->assertSame(0, (int) (($response['data']['orthography']['stats']['candidate_count'] ?? 0)));
    }

    public function test_surface_trill_auto_rule_defaults_to_single_r(): void
    {
        $wordset_id = $this->createWordset('Orthography Surface Trill');
        $engine_rules = ll_tools_ipa_orthography_prepare_engine_rules(
            [
                'a' => [
                    [
                        'segment' => 'a',
                        'context' => 'any',
                        'output' => 'a',
                        'count' => 10,
                    ],
                ],
                'r' => [
                    [
                        'segment' => 'r',
                        'context' => 'any',
                        'output' => 'rr',
                        'count' => 10,
                    ],
                ],
            ],
            [],
            $wordset_id
        );

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_text('ara', $engine_rules, $wordset_id);

        $this->assertTrue((bool) ($prediction['complete'] ?? false));
        $this->assertSame('ara', (string) ($prediction['text'] ?? ''));
    }

    public function test_genc_palu_profile_uses_phrase_exception_and_flags_mismatch(): void
    {
        $wordset_id = $this->createWordset('Genç-Palu Profile');
        $word_id = $this->createWord($wordset_id, 'Ten eight', 'dest erzen');
        $recording_id = $this->createRecording($word_id, 'des erzen', 'dɛs ɛɾzɛn');

        $prediction = ll_tools_ipa_orthography_profile_convert_ipa_to_text('dɛs ɛɾzɛn', $wordset_id);
        $this->assertTrue((bool) ($prediction['complete'] ?? false));
        $this->assertSame('Dest erzen', (string) ($prediction['text'] ?? ''));
        $this->assertNotSame(
            ll_tools_ipa_orthography_profile_compare_key('dest erzen'),
            ll_tools_ipa_orthography_profile_compare_key('desterzen')
        );

        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        $codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($validation['active'] ?? []));
        $this->assertContains('orthography_mismatch', $codes);

        $data = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $rows = (array) ($data['contradictions'] ?? []);
        $this->assertCount(1, $rows);
        $this->assertSame($recording_id, (int) ($rows[0]['recording_id'] ?? 0));
        $this->assertSame('Dest erzen', (string) ($rows[0]['predicted_text'] ?? ''));
        $this->assertSame('profile', (string) ($rows[0]['prediction_source'] ?? ''));
        $this->assertTrue((bool) ($rows[0]['can_apply_suggestion'] ?? false));
    }

    public function test_genc_palu_profile_keeps_palatal_nasal_distinct_from_plain_n(): void
    {
        $wordset_id = $this->createWordset('Genç-Palu Nasal Profile');
        $word_id = $this->createWord($wordset_id, 'Woman', 'cini');
        $recording_id = $this->createRecording($word_id, 'cini', 'd͡ʒiɲi');

        $this->assertSame('cini', ll_tools_ipa_orthography_profile_convert_genc_palu_word('d͡ʒini'));
        $this->assertSame('cinyi', ll_tools_ipa_orthography_profile_convert_genc_palu_word('d͡ʒiɲi'));

        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        $codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($validation['active'] ?? []));

        $this->assertContains('orthography_mismatch', $codes);
    }

    public function test_genc_palu_profile_uses_lexical_final_near_i_overrides_without_broad_auto_accept(): void
    {
        $wordset_id = $this->createWordset('Genç-Palu Near I Profile');
        $ipa = "\u{0283}\u{026A} m\u{02B7}\u{025B}\u{027E}\u{026A}k\u{02B0}";

        $prediction = ll_tools_ipa_orthography_profile_convert_ipa_to_text($ipa, $wordset_id);
        $this->assertTrue((bool) ($prediction['complete'] ?? false));
        $this->assertSame('Şı mwerık', (string) ($prediction['text'] ?? ''));

        $accepted_detail = ll_tools_ipa_orthography_profile_mismatch_detail('Şı mwerık', $ipa, $wordset_id);
        $this->assertTrue((bool) ($accepted_detail['matches'] ?? false));

        $xwe_prediction = ll_tools_ipa_orthography_profile_convert_ipa_to_text("\u{03C7}\u{02B7}\u{0268}", $wordset_id);
        $this->assertTrue((bool) ($xwe_prediction['complete'] ?? false));
        $this->assertSame('Xwe', (string) ($xwe_prediction['text'] ?? ''));
        $xwe_detail = ll_tools_ipa_orthography_profile_mismatch_detail('Xwe', "\u{03C7}\u{02B7}\u{0268}", $wordset_id);
        $this->assertTrue((bool) ($xwe_detail['matches'] ?? false));

        $kweli_prediction = ll_tools_ipa_orthography_profile_convert_ipa_to_text("k\u{02B7}\u{02B0}\u{025B}l\u{026A}", $wordset_id);
        $this->assertTrue((bool) ($kweli_prediction['complete'] ?? false));
        $this->assertSame('Kwelı', (string) ($kweli_prediction['text'] ?? ''));

        $this->assertSame('Mase', (string) (ll_tools_ipa_orthography_profile_convert_ipa_to_text("mas\u{026A}", $wordset_id)['text'] ?? ''));
        $this->assertSame('Merre', (string) (ll_tools_ipa_orthography_profile_convert_ipa_to_text("m\u{025B}r\u{026A}", $wordset_id)['text'] ?? ''));
        $final_e_cases = [
            'Kwe' => "k\u{02B7}\u{02B0}\u{026A}",
            'Dıje' => "d\u{0268}\u{0292}\u{026A}",
            'Vıştıre' => "v\u{0268}\u{0283}t\u{0268}r\u{026A}",
            'Mefte' => "m\u{025B}ft\u{026A}",
            'Perde' => "p\u{025B}rd\u{026A}",
            'Resine' => "r\u{025B}sin\u{026A}",
            'Kılse' => "k\u{0268}ls\u{026A}",
            'Yege' => "j\u{025B}g\u{026A}",
        ];
        foreach ($final_e_cases as $expected_text => $case_ipa) {
            $this->assertSame($expected_text, (string) (ll_tools_ipa_orthography_profile_convert_ipa_to_text($case_ipa, $wordset_id)['text'] ?? ''));
        }

        $lexical_detail = ll_tools_ipa_orthography_profile_mismatch_detail('Maze', "maz\u{026A}", $wordset_id);
        $this->assertFalse((bool) ($lexical_detail['matches'] ?? true));
        $this->assertSame('Mazı', (string) ($lexical_detail['suggested_text'] ?? ''));
        $this->assertSame(['e'], $this->collectSpanText('Maze', (array) ($lexical_detail['actual_spans'] ?? [])));
        $this->assertSame(['ı'], $this->collectSpanText((string) ($lexical_detail['suggested_text'] ?? ''), (array) ($lexical_detail['suggested_spans'] ?? [])));

        $unresolved_pronoun_detail = ll_tools_ipa_orthography_profile_mismatch_detail('Yı', "j\u{026A}", $wordset_id);
        $this->assertFalse((bool) ($unresolved_pronoun_detail['matches'] ?? true));
        $this->assertSame('Ye', (string) ($unresolved_pronoun_detail['suggested_text'] ?? ''));

        $detail = ll_tools_ipa_orthography_profile_mismatch_detail('Şı mwerik', $ipa, $wordset_id);
        $this->assertFalse((bool) ($detail['matches'] ?? true));
        $this->assertSame('Şı mwerık', (string) ($detail['suggested_text'] ?? ''));
        $this->assertSame(['i'], $this->collectSpanText('Şı mwerik', (array) ($detail['actual_spans'] ?? [])));
        $this->assertSame(['ı'], $this->collectSpanText((string) ($detail['suggested_text'] ?? ''), (array) ($detail['suggested_spans'] ?? [])));
        $this->assertSame(["\u{026A}"], $this->collectSpanText($ipa, (array) ($detail['ipa_spans'] ?? [])));

        $ipa_suggestions = array_map(static function (array $suggestion): string {
            return (string) ($suggestion['ipa'] ?? '');
        }, (array) ($detail['ipa_suggestions'] ?? []));
        $this->assertContains("\u{0283}\u{026A} m\u{02B7}\u{025B}\u{027E}ik\u{02B0}", $ipa_suggestions);
    }

    public function test_apply_orthography_suggestion_handler_updates_recording_text(): void
    {
        $wordset_id = $this->createWordset('Genç-Palu Suggestion');
        $word_id = $this->createWord($wordset_id, 'Ten eight', 'dest erzen');
        $recording_id = $this->createRecording($word_id, 'des erzen', 'dɛs ɛɾzɛn');

        $user_id = $this->createViewerUser();
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $recording_id,
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_apply_ipa_keyboard_orthography_suggestion_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('Dest erzen', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame(0, (int) (($response['data']['orthography']['stats']['active_contradiction_count'] ?? 0)));
    }

    public function test_apply_orthography_suggestion_preserves_accepted_final_near_i_choice(): void
    {
        $wordset_id = $this->createWordset('Genç-Palu Near I Suggestion');
        $word_id = $this->createWord($wordset_id, 'Warm clothes', 'Şı mwerik');
        $recording_id = $this->createRecording(
            $word_id,
            'Şı mwerik',
            "\u{0283}\u{026A} m\u{02B7}\u{025B}\u{027E}\u{026A}k\u{02B0}"
        );

        $user_id = $this->createViewerUser();
        wp_set_current_user($user_id);

        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $recording_id,
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_apply_ipa_keyboard_orthography_suggestion_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('Şı mwerık', (string) get_post_meta($recording_id, 'recording_text', true));
    }

    public function test_word_exception_marks_contradiction_as_approved(): void
    {
        $wordset_id = $this->createWordset('Orthography Exceptions');

        $contradicting_word_id = $this->createWordWithRecording($wordset_id, 'Gloss 1', 'maš', 'maš', 'maʃ');
        $this->createWordWithRecording($wordset_id, 'Gloss 2', 'sha', 'sha', 'ʃa');
        $this->createWordWithRecording($wordset_id, 'Gloss 3', 'ma', 'ma', 'ma');

        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            'ʃ' => [
                'any' => 'sh',
            ],
        ]);

        $before = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $this->assertSame(1, (int) (($before['stats']['active_contradiction_count'] ?? 0)));

        ll_tools_ipa_orthography_update_exception_word_id($wordset_id, $contradicting_word_id, true);

        $after = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $this->assertSame(0, (int) (($after['stats']['active_contradiction_count'] ?? 0)));
        $this->assertSame(1, (int) (($after['stats']['approved_contradiction_count'] ?? 0)));

        $rows = (array) ($after['contradictions'] ?? []);
        $this->assertCount(1, $rows);
        $this->assertTrue((bool) ($rows[0]['approved_exception'] ?? false));
    }

    private function createViewerUser(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function createWordset(string $name): int
    {
        $term = wp_insert_term($name, 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));

        return (int) ($term['term_id'] ?? 0);
    }

    private function createWord(int $wordset_id, string $translation_label, string $word_text): int
    {
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => $translation_label,
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        if ($word_text !== '') {
            update_post_meta($word_id, 'word_translation', $word_text);
        }

        return $word_id;
    }

    private function createWordWithRecording(
        int $wordset_id,
        string $translation_label,
        string $word_text,
        string $recording_text,
        string $recording_ipa
    ): int {
        $word_id = $this->createWord($wordset_id, $translation_label, $word_text);
        $this->createRecording($word_id, $recording_text, $recording_ipa);
        return $word_id;
    }

    private function createRecording(int $word_id, string $recording_text, string $recording_ipa): int
    {
        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Recording ' . wp_generate_password(6, false, false),
        ]);
        wp_set_object_terms($recording_id, ['isolation'], 'recording_type', false);
        update_post_meta($recording_id, 'audio_file_path', 'wp-content/uploads/test-audio/test-' . $recording_id . '.mp3');
        if ($recording_text !== '') {
            update_post_meta($recording_id, 'recording_text', $recording_text);
        }
        if ($recording_ipa !== '') {
            update_post_meta($recording_id, 'recording_ipa', $recording_ipa);
        }

        return $recording_id;
    }

    private function ensureRecordingTypeTerm(string $name, string $slug): void
    {
        $existing = get_term_by('slug', $slug, 'recording_type');
        if ($existing instanceof WP_Term) {
            return;
        }

        $term = wp_insert_term($name, 'recording_type', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($term));
    }

    private function findRuleRow(array $rows, string $segment): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['segment'] ?? '') === $segment) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,int>> $spans
     * @return array<int,string>
     */
    private function collectSpanText(string $text, array $spans): array
    {
        return array_values(array_map(static function (array $span) use ($text): string {
            return mb_substr($text, (int) ($span['start'] ?? 0), (int) ($span['length'] ?? 0), 'UTF-8');
        }, $spans));
    }

    /**
     * @return array<string,mixed>
     */
    private function runJsonEndpoint(callable $callback): array
    {
        $dieHandler = static function (): void {
            throw new RuntimeException('wp_die');
        };
        $dieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $ajaxDieFilter = static function () use ($dieHandler) {
            return $dieHandler;
        };
        $doingAjaxFilter = static function (): bool {
            return true;
        };

        add_filter('wp_die_handler', $dieFilter);
        add_filter('wp_die_ajax_handler', $ajaxDieFilter);
        add_filter('wp_doing_ajax', $doingAjaxFilter);

        ob_start();
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die', $e->getMessage());
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_die_handler', $dieFilter);
            remove_filter('wp_die_ajax_handler', $ajaxDieFilter);
            remove_filter('wp_doing_ajax', $doingAjaxFilter);
        }

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'Expected JSON response payload.');

        return $decoded;
    }
}
