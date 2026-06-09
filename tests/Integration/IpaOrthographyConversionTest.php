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
        if (function_exists('ll_tools_register_dictionary_entry_post_type')) {
            ll_tools_register_dictionary_entry_post_type();
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

    public function test_surface_trill_rule_is_not_changed_by_language_specific_policy(): void
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
        $this->assertSame('arra', (string) ($prediction['text'] ?? ''));
    }

    public function test_wordset_phrase_override_drives_conversion_and_flags_mismatch(): void
    {
        $wordset_id = $this->createWordset('Phrase Override');
        $this->configureDesErzenFixture($wordset_id);
        $word_id = $this->createWord($wordset_id, 'Ten eight', 'dest erzen');
        $recording_id = $this->createRecording($word_id, 'des erzen', 'dɛs ɛɾzɛn');

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            'dɛs ɛɾzɛn',
            ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id),
            $wordset_id,
            $recording_id
        );
        $this->assertTrue((bool) ($prediction['complete'] ?? false));
        $this->assertSame('dest erzen', (string) ($prediction['text'] ?? ''));
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
        $this->assertSame('dest erzen', (string) ($rows[0]['predicted_text'] ?? ''));
        $this->assertSame('rules', (string) ($rows[0]['prediction_source'] ?? ''));
        $this->assertTrue((bool) ($rows[0]['can_apply_suggestion'] ?? false));
    }

    public function test_recording_meta_change_queues_validation_until_scheduled_hook_runs(): void
    {
        $wordset_id = $this->createWordset('Async Validation');
        $this->configureDesErzenFixture($wordset_id);
        $word_id = $this->createWord($wordset_id, 'Ten eight', 'dest erzen');
        $recording_id = $this->createRecording(
            $word_id,
            'des erzen',
            "d\u{025B}s \u{025B}\u{027E}z\u{025B}n"
        );

        $this->assertNotFalse(wp_next_scheduled(LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, [$recording_id]));
        $this->assertSame('', (string) get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), true));
        $this->assertSame(
            ['active' => [], 'ignored' => []],
            ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id)
        );

        do_action(LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, $recording_id);

        $this->assertFalse(wp_next_scheduled(LL_TOOLS_IPA_KEYBOARD_VALIDATION_HOOK, [$recording_id]));
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        $codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($validation['active'] ?? []));
        $this->assertContains('orthography_mismatch', $codes);
        $this->assertGreaterThan(0, (int) get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), true));
    }

    public function test_validation_flags_modifier_between_tie_bar_and_second_sound(): void
    {
        $wordset_id = $this->createWordset('Malformed Tie Bar Validation');
        $word_id = $this->createWord($wordset_id, 'Bad Tie Bar', '');
        $recording_id = $this->createRecording($word_id, '', "c\u{0361}\u{02B0}\u{025B}");

        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        $issues = (array) ($validation['active'] ?? []);
        $codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, $issues);

        $this->assertContains('tie_bar_without_pair', $codes);
        $tie_bar_issue = null;
        foreach ($issues as $issue) {
            if ((string) ($issue['code'] ?? '') === 'tie_bar_without_pair') {
                $tie_bar_issue = $issue;
                break;
            }
        }
        $this->assertIsArray($tie_bar_issue);
        $this->assertContains("c\u{0361}\u{02B0}\u{025B}", array_values((array) ($tie_bar_issue['samples'] ?? [])));
    }

    public function test_word_overrides_and_optional_matches_are_wordset_settings(): void
    {
        $wordset_id = $this->createWordset('Configurable Orthography Settings');
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            's' => ['any' => 's'],
            't' => ['any' => 'tw'],
            'ɨ' => ['any' => 'ı'],
        ]);
        $this->setOrthographySettings($wordset_id, [
            'word_overrides' => [
                'sı' => 'se',
                'twı' => 'twe',
            ],
            'optional_matches' => [
                [
                    'ipa' => "ɨ\u{0306}",
                    'orthography' => 'ı',
                ],
            ],
        ]);

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            "s\u{0268} t\u{0268}",
            ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id),
            $wordset_id
        );
        $this->assertTrue((bool) ($prediction['complete'] ?? false));
        $this->assertSame('se twe', (string) ($prediction['text'] ?? ''));

        $brief_ipa = "b\u{02B7}\u{0268}\u{0306}\u{027E}e";
        $manual_prediction = [
            'text' => 'Bwırê',
            'complete' => true,
            'matched_tokens' => 1,
            'token_count' => 1,
        ];
        $with_dotless_i = ll_tools_ipa_orthography_profile_mismatch_detail('Bwırê', $brief_ipa, $wordset_id, '', $manual_prediction);
        $this->assertTrue((bool) ($with_dotless_i['matches'] ?? false));
        $this->assertSame('Bwırê', (string) ($with_dotless_i['suggested_text'] ?? ''));

        $without_dotless_i = ll_tools_ipa_orthography_profile_mismatch_detail('Bwrê', $brief_ipa, $wordset_id, '', $manual_prediction);
        $this->assertTrue((bool) ($without_dotless_i['matches'] ?? false));
        $this->assertSame('Bwrê', (string) ($without_dotless_i['suggested_text'] ?? ''));
    }

    public function test_dictionary_bound_word_override_only_applies_to_matching_entry(): void
    {
        $wordset_id = $this->createWordset('Dictionary Bound Word Override');
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            'm' => ['any' => 'm'],
            'a' => ['any' => 'a'],
        ]);

        $matching_entry_id = $this->createDictionaryEntry('Lexeme A');
        $other_entry_id = $this->createDictionaryEntry('Lexeme B');
        $this->setOrthographySettings($wordset_id, [
            'word_overrides' => [
                [
                    'from' => 'ma',
                    'to' => 'lexeme-a',
                    'dictionary_entry_id' => $matching_entry_id,
                ],
            ],
        ]);

        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);
        $unscoped_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('ma', $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($unscoped_prediction['complete'] ?? false));
        $this->assertSame('ma', (string) ($unscoped_prediction['text'] ?? ''));

        $matching_word_id = $this->createWord($wordset_id, 'Matching lexical item', 'lexeme-a');
        $this->linkWordToDictionaryEntry($matching_word_id, $matching_entry_id);
        $matching_recording_id = $this->createRecording($matching_word_id, 'lexeme-a', 'ma');
        $matching_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            'ma',
            $engine_rules,
            $wordset_id,
            $matching_recording_id
        );
        $this->assertSame('lexeme-a', (string) ($matching_prediction['text'] ?? ''));
        ll_tools_ipa_keyboard_update_recording_validation($matching_recording_id);
        $this->assertNotContains(
            'orthography_mismatch',
            $this->validationCodes($matching_recording_id, $wordset_id)
        );

        $other_word_id = $this->createWord($wordset_id, 'Other lexical item', 'ma');
        $this->linkWordToDictionaryEntry($other_word_id, $other_entry_id);
        $other_recording_id = $this->createRecording($other_word_id, 'ma', 'ma');
        $other_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            'ma',
            $engine_rules,
            $wordset_id,
            $other_recording_id
        );
        $this->assertSame('ma', (string) ($other_prediction['text'] ?? ''));
        ll_tools_ipa_keyboard_update_recording_validation($other_recording_id);
        $this->assertNotContains(
            'orthography_mismatch',
            $this->validationCodes($other_recording_id, $wordset_id)
        );

        $wrong_word_id = $this->createWord($wordset_id, 'Wrong lexical item', 'lexeme-a');
        $this->linkWordToDictionaryEntry($wrong_word_id, $other_entry_id);
        $wrong_recording_id = $this->createRecording($wrong_word_id, 'lexeme-a', 'ma');
        ll_tools_ipa_keyboard_update_recording_validation($wrong_recording_id);
        $this->assertContains(
            'orthography_mismatch',
            $this->validationCodes($wrong_recording_id, $wordset_id)
        );
    }

    public function test_zazaki_profile_maps_i_vowels_to_dotless_i_and_flags_dotted_i(): void
    {
        $wordset_id = $this->createWordset('Zazaki Genç-Palu Profile');
        update_term_meta($wordset_id, 'll_language', 'zza');

        $this->assertSame('zazaki_genc_palu', ll_tools_ipa_orthography_get_profile_key($wordset_id));

        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);
        $initial_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('ɨna', $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($initial_prediction['complete'] ?? false));
        $this->assertSame('Ina', (string) ($initial_prediction['text'] ?? ''));

        $near_i_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('mɪna', $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($near_i_prediction['complete'] ?? false));
        $this->assertSame('Mına', (string) ($near_i_prediction['text'] ?? ''));

        $default_final_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('vɪ', $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($default_final_prediction['complete'] ?? false));
        $this->assertSame('Ve', (string) ($default_final_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($default_final_prediction['requires_lexical_decision'] ?? true));

        $final_e_entry_id = $this->createDictionaryEntry('Final e Lexeme');
        $final_dotless_entry_id = $this->createDictionaryEntry('Final dotless Lexeme');
        $this->setOrthographySettings($wordset_id, [
            'word_overrides' => [
                [
                    'from' => 'sı',
                    'to' => 'se',
                    'dictionary_entry_id' => $final_e_entry_id,
                ],
                [
                    'from' => 've',
                    'to' => 'vı',
                    'dictionary_entry_id' => $final_dotless_entry_id,
                ],
            ],
            'sentence_case' => true,
        ]);
        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);

        $final_e_word_id = $this->createWord($wordset_id, 'Final e lexical item', 'Se');
        $this->linkWordToDictionaryEntry($final_e_word_id, $final_e_entry_id);
        $final_e_recording_id = $this->createRecording($final_e_word_id, 'Se', 'sɨ');
        $final_e_exception = ll_tools_ipa_orthography_convert_ipa_to_best_text('sɨ', $engine_rules, $wordset_id, $final_e_recording_id);
        $this->assertTrue((bool) ($final_e_exception['complete'] ?? false));
        $this->assertSame('Se', (string) ($final_e_exception['text'] ?? ''));
        $this->assertFalse((bool) ($final_e_exception['requires_lexical_decision'] ?? false));
        ll_tools_ipa_keyboard_update_recording_validation($final_e_recording_id);
        $this->assertNotContains(
            'orthography_mismatch',
            $this->validationCodes($final_e_recording_id, $wordset_id)
        );

        $final_dotless_word_id = $this->createWord($wordset_id, 'Final dotless lexical item', 'Vı');
        $this->linkWordToDictionaryEntry($final_dotless_word_id, $final_dotless_entry_id);
        $final_dotless_recording_id = $this->createRecording($final_dotless_word_id, 'Vı', 'vɪ');
        $final_dotless_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('vɪ', $engine_rules, $wordset_id, $final_dotless_recording_id);
        $this->assertTrue((bool) ($final_dotless_prediction['complete'] ?? false));
        $this->assertSame('Vı', (string) ($final_dotless_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($final_dotless_prediction['requires_lexical_decision'] ?? false));
        ll_tools_ipa_keyboard_update_recording_validation($final_dotless_recording_id);
        $this->assertNotContains(
            'orthography_mismatch',
            $this->validationCodes($final_dotless_recording_id, $wordset_id)
        );

        $other_final_entry_id = $this->createDictionaryEntry('Other final vowel lexeme');
        $unbound_final_word_id = $this->createWord($wordset_id, 'Unresolved final high vowel', 'Vı');
        $this->linkWordToDictionaryEntry($unbound_final_word_id, $other_final_entry_id);
        $unbound_final_recording_id = $this->createRecording($unbound_final_word_id, 'Vı', 'vɪ');
        $unbound_recording_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('vɪ', $engine_rules, $wordset_id, $unbound_final_recording_id);
        $this->assertSame('Ve', (string) ($unbound_recording_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($unbound_recording_prediction['requires_lexical_decision'] ?? true));
        $unbound_mismatch_detail = ll_tools_ipa_orthography_profile_mismatch_detail('Vı', 'vɪ', $wordset_id, 'isolation', $unbound_recording_prediction);
        $this->assertFalse((bool) ($unbound_mismatch_detail['matches'] ?? true));
        $this->assertSame('Ve', (string) ($unbound_mismatch_detail['suggested_text'] ?? ''));
        $raw_unbound_validation = ll_tools_ipa_keyboard_validate_recording_for_wordset($unbound_final_recording_id, $wordset_id, []);
        $this->assertContains(
            'orthography_mismatch',
            array_map(static function (array $issue): string {
                return (string) ($issue['code'] ?? '');
            }, (array) ($raw_unbound_validation['active'] ?? []))
        );
        ll_tools_ipa_keyboard_update_recording_validation($unbound_final_recording_id);
        $this->assertContains(
            'orthography_mismatch',
            $this->validationCodes($unbound_final_recording_id, $wordset_id)
        );

        $word_id = $this->createWord($wordset_id, 'Dotted i mismatch', 'İna');
        $recording_id = $this->createRecording($word_id, 'İna', 'ɨna');
        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);

        $mismatch_issue = null;
        foreach ((array) ($validation['active'] ?? []) as $issue) {
            if ((string) ($issue['code'] ?? '') === 'orthography_mismatch') {
                $mismatch_issue = $issue;
                break;
            }
        }

        $this->assertIsArray($mismatch_issue);
        $this->assertSame('Ina', (string) ($mismatch_issue['orthography_mismatch']['suggested_text'] ?? ''));
    }

    public function test_zazaki_word_id_bound_override_limits_final_dotless_i_exception_to_one_word(): void
    {
        $wordset_id = $this->createWordset('Zazaki Word Bound Final Vowel Override');
        update_term_meta($wordset_id, 'll_language', 'zza');

        $target_word_id = $this->createWord($wordset_id, 'Exact final dotless item', 'Vı');
        $other_word_id = $this->createWord($wordset_id, 'Other final dotless item', 'Vı');
        $this->setOrthographySettings($wordset_id, [
            'word_overrides' => [
                [
                    'from' => 've',
                    'to' => 'vı',
                    'word_id' => $target_word_id,
                ],
            ],
            'sentence_case' => true,
        ]);

        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);

        $target_recording_id = $this->createRecording($target_word_id, 'Vı', 'vɪ');
        $target_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('vɪ', $engine_rules, $wordset_id, $target_recording_id);
        $this->assertSame('Vı', (string) ($target_prediction['text'] ?? ''));
        ll_tools_ipa_keyboard_update_recording_validation($target_recording_id);
        $this->assertNotContains('orthography_mismatch', $this->validationCodes($target_recording_id, $wordset_id));

        $other_recording_id = $this->createRecording($other_word_id, 'Vı', 'vɪ');
        $other_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('vɪ', $engine_rules, $wordset_id, $other_recording_id);
        $this->assertSame('Ve', (string) ($other_prediction['text'] ?? ''));
        ll_tools_ipa_keyboard_update_recording_validation($other_recording_id);
        $this->assertContains('orthography_mismatch', $this->validationCodes($other_recording_id, $wordset_id));
    }

    public function test_zazaki_profile_locks_strict_vowel_consonant_and_dotted_i_mappings(): void
    {
        $wordset_id = $this->createWordset('Zazaki Strict Profile Mappings');
        update_term_meta($wordset_id, 'll_language', 'zza');
        update_term_meta(
            $wordset_id,
            ll_tools_ipa_orthography_manual_rules_meta_key(),
            ll_tools_ipa_orthography_sanitize_manual_rules([
                'æ' => ['any' => 'e'],
                'i' => ['any' => 'ı'],
                'ɭ' => ['any' => 'l'],
                'χ' => ['any' => 'q'],
            ], $wordset_id)
        );

        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);

        $front_vowel_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('ræhme', $engine_rules, $wordset_id);
        $this->assertSame('Râhmê', (string) ($front_vowel_prediction['text'] ?? ''));

        $front_vowel_word_id = $this->createWord($wordset_id, 'Front vowel mismatch', 'Rehmê');
        $front_vowel_recording_id = $this->createRecording($front_vowel_word_id, 'Rehmê', 'ræhme');
        ll_tools_ipa_keyboard_update_recording_validation($front_vowel_recording_id);
        $front_vowel_validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($front_vowel_recording_id, $wordset_id);
        $front_vowel_issue = $this->findOrthographyMismatchIssue($front_vowel_validation);
        $this->assertIsArray($front_vowel_issue);
        $this->assertSame('Râhmê', (string) ($front_vowel_issue['orthography_mismatch']['suggested_text'] ?? ''));

        $fricative_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('χa', $engine_rules, $wordset_id);
        $this->assertSame('Xa', (string) ($fricative_prediction['text'] ?? ''));

        $retroflex_lateral_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('ɭa', $engine_rules, $wordset_id);
        $this->assertSame("'La", (string) ($retroflex_lateral_prediction['text'] ?? ''));

        $dotted_i_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('i', $engine_rules, $wordset_id);
        $this->assertSame('İ', (string) ($dotted_i_prediction['text'] ?? ''));

        $dotted_i_word_id = $this->createWord($wordset_id, 'Dotted I mismatch', 'I');
        $dotted_i_recording_id = $this->createRecording($dotted_i_word_id, 'I', 'i');
        ll_tools_ipa_keyboard_update_recording_validation($dotted_i_recording_id);
        $dotted_i_validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($dotted_i_recording_id, $wordset_id);
        $dotted_i_issue = $this->findOrthographyMismatchIssue($dotted_i_validation);
        $this->assertIsArray($dotted_i_issue);
        $this->assertSame('İ', (string) ($dotted_i_issue['orthography_mismatch']['suggested_text'] ?? ''));
    }

    public function test_zazaki_profile_handles_local_phonetic_and_lexical_exceptions(): void
    {
        $wordset_id = $this->createWordset('Zazaki Local Phonetic Exceptions');
        update_term_meta($wordset_id, 'll_language', 'zza');
        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);

        $hbar = "\u{0127}";
        $this->assertSame(
            "'Hilal",
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text($hbar . 'ilal', $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Âg',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("ʔæg", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            "'Gaz",
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("ɢaz", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            "'Gwa",
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("ɢʷa", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Zwa',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("zʷa", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Ang ank',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("aŋg aŋk", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Twe',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("t̪͡ʙ̥ɨ", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Se',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("sɨ", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Yı',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("jɨ", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Yı',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("ji", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Çend',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("t̪͡ʃɛn", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $this->assertSame(
            'Mirçıkû',
            (string) (ll_tools_ipa_orthography_convert_ipa_to_best_text("mit̪͡ʃkʰu", $engine_rules, $wordset_id)['text'] ?? '')
        );

        $nonfinal_high_vowel_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text("mʷɛɾɪkʰ", $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($nonfinal_high_vowel_prediction['complete'] ?? false));
        $this->assertSame('Mwerık', (string) ($nonfinal_high_vowel_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($nonfinal_high_vowel_prediction['requires_lexical_decision'] ?? true));
        $nonfinal_high_vowel_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            'Mwerık',
            "mʷɛɾɪkʰ",
            $wordset_id,
            'isolation',
            $nonfinal_high_vowel_prediction
        );
        $this->assertTrue((bool) ($nonfinal_high_vowel_detail['matches'] ?? false));

        $plural_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text("mʷɛɾik", $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($plural_prediction['complete'] ?? false));
        $this->assertSame('Mwêrik', (string) ($plural_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($plural_prediction['requires_lexical_decision'] ?? true));
        $plural_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            'Mwêrik',
            "mʷɛɾik",
            $wordset_id,
            'isolation',
            $plural_prediction
        );
        $this->assertTrue((bool) ($plural_detail['matches'] ?? false));

        $proximal_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text("ina", $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($proximal_prediction['complete'] ?? false));
        $this->assertSame('Ina', (string) ($proximal_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($proximal_prediction['requires_lexical_decision'] ?? true));

        $release_ipa = "bɨd̪ɨ\u{032F}";
        $release_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text($release_ipa, $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($release_prediction['complete'] ?? false));
        $this->assertSame('Bıdı', (string) ($release_prediction['text'] ?? ''));

        $without_release_vowel = ll_tools_ipa_orthography_profile_mismatch_detail('Bıd', $release_ipa, $wordset_id, 'isolation', $release_prediction);
        $this->assertTrue((bool) ($without_release_vowel['matches'] ?? false));
        $this->assertSame('Bıd', (string) ($without_release_vowel['suggested_text'] ?? ''));

        $with_release_vowel = ll_tools_ipa_orthography_profile_mismatch_detail('Bıdı', $release_ipa, $wordset_id, 'isolation', $release_prediction);
        $this->assertTrue((bool) ($with_release_vowel['matches'] ?? false));

        $wrong_release_vowel = ll_tools_ipa_orthography_profile_mismatch_detail('Bıde', $release_ipa, $wordset_id, 'isolation', $release_prediction);
        $this->assertFalse((bool) ($wrong_release_vowel['matches'] ?? true));

        $de_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text("d̪ɨ", $engine_rules, $wordset_id);
        $this->assertTrue((bool) ($de_prediction['complete'] ?? false));
        $this->assertSame('De', (string) ($de_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($de_prediction['requires_lexical_decision'] ?? true));
        $this->assertTrue((bool) (ll_tools_ipa_orthography_profile_mismatch_detail('De', "d̪ɨ", $wordset_id, 'isolation', $de_prediction)['matches'] ?? false));
        $this->assertTrue((bool) (ll_tools_ipa_orthography_profile_mismatch_detail('Dı', "d̪ɨ", $wordset_id, 'isolation', $de_prediction)['matches'] ?? false));
        $di_front_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text("d̪ɪ", $engine_rules, $wordset_id);
        $this->assertTrue((bool) (ll_tools_ipa_orthography_profile_mismatch_detail('Dı', "d̪ɪ", $wordset_id, 'isolation', $di_front_prediction)['matches'] ?? false));

        $unresolved_final_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text("vɨ", $engine_rules, $wordset_id);
        $this->assertSame('Ve', (string) ($unresolved_final_prediction['text'] ?? ''));
        $this->assertFalse((bool) ($unresolved_final_prediction['requires_lexical_decision'] ?? true));
        $unresolved_final_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            'Vı',
            "vɨ",
            $wordset_id,
            'isolation',
            $unresolved_final_prediction
        );
        $this->assertFalse((bool) ($unresolved_final_detail['matches'] ?? true));
        $this->assertSame('Ve', (string) ($unresolved_final_detail['suggested_text'] ?? ''));
        $this->assertNotEmpty((array) ($unresolved_final_detail['actual_spans'] ?? []));
        $this->assertNotEmpty((array) ($unresolved_final_detail['ipa_spans'] ?? []));
    }

    public function test_dropped_final_n_for_bread_is_dictionary_bound(): void
    {
        $wordset_id = $this->createWordset('Bread Final N Exception');
        update_term_meta($wordset_id, 'll_language', 'zza');
        $bread_entry_id = $this->createDictionaryEntry('nûn bread');
        $other_entry_id = $this->createDictionaryEntry('nû other');
        $this->setOrthographySettings($wordset_id, [
            'word_overrides' => [
                [
                    'from' => 'nû',
                    'to' => 'nûn',
                    'dictionary_entry_id' => $bread_entry_id,
                ],
            ],
            'sentence_case' => true,
        ]);

        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);

        $bread_word_id = $this->createWord($wordset_id, 'Bread', 'Nûn');
        $this->linkWordToDictionaryEntry($bread_word_id, $bread_entry_id);
        $bread_recording_id = $this->createRecording($bread_word_id, 'Nûn', 'nu');
        $bread_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('nu', $engine_rules, $wordset_id, $bread_recording_id);
        $this->assertSame('Nûn', (string) ($bread_prediction['text'] ?? ''));
        ll_tools_ipa_keyboard_update_recording_validation($bread_recording_id);
        $this->assertNotContains('orthography_mismatch', $this->validationCodes($bread_recording_id, $wordset_id));

        $other_word_id = $this->createWord($wordset_id, 'Other nû', 'Nû');
        $this->linkWordToDictionaryEntry($other_word_id, $other_entry_id);
        $other_recording_id = $this->createRecording($other_word_id, 'Nû', 'nu');
        $other_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text('nu', $engine_rules, $wordset_id, $other_recording_id);
        $this->assertSame('Nû', (string) ($other_prediction['text'] ?? ''));
    }

    public function test_dictionary_bound_word_override_can_resolve_sentence_token_decision(): void
    {
        $wordset_id = $this->createWordset('Zazaki Genç-Palu Bread Sentence Exception');
        update_term_meta($wordset_id, 'll_language', 'zza');
        $bread_entry_id = $this->createDictionaryEntry('nûn bread');
        $this->setOrthographySettings($wordset_id, [
            'word_overrides' => [
                [
                    'from' => 'nû',
                    'to' => 'nûn',
                    'dictionary_entry_id' => $bread_entry_id,
                ],
                [
                    'from' => 'miçkû',
                    'to' => 'mirçıkû',
                ],
            ],
            'sentence_case' => true,
        ]);

        $engine_rules = ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id);
        $sentence_word_id = $this->createWord($wordset_id, 'Bread sentence', 'Nûn mirçıkû');
        $sentence_ipa = 'nu mit̪͡ʃkʰu';
        $sentence_recording_id = $this->createRecording($sentence_word_id, 'Nûn mirçıkû', $sentence_ipa);
        $sentence_prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text($sentence_ipa, $engine_rules, $wordset_id, $sentence_recording_id);

        $this->assertSame('Nû mirçıkû', (string) ($sentence_prediction['text'] ?? ''));

        $sentence_detail = ll_tools_ipa_orthography_profile_mismatch_detail(
            'Nûn mirçıkû',
            $sentence_ipa,
            $wordset_id,
            'isolation',
            $sentence_prediction
        );
        $this->assertTrue((bool) ($sentence_detail['matches'] ?? false));
        $this->assertFalse((bool) ($sentence_detail['requires_lexical_decision'] ?? true));
        $this->assertSame('Nûn mirçıkû', (string) ($sentence_detail['suggested_text'] ?? ''));

        ll_tools_ipa_keyboard_update_recording_validation($sentence_recording_id);
        $this->assertNotContains('orthography_mismatch', $this->validationCodes($sentence_recording_id, $wordset_id));
    }

    public function test_issue_search_lists_cached_candidates_and_rest_batch_refreshes_stale_validation(): void
    {
        $wordset_id = $this->createWordset('Stale Validation Search');
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            'm' => ['any' => 'm'],
            'a' => ['any' => 'a'],
        ]);
        $word_id = $this->createWord($wordset_id, 'Valid item', 'ma');
        $recording_id = $this->createRecording($word_id, 'ma', 'ma');
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
            $wordset_id => [
                'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version() - 1,
                'active' => [
                    [
                        'rule_key' => 'builtin:orthography_mismatch',
                        'code' => 'orthography_mismatch',
                        'type' => 'builtin',
                        'label' => 'Orthography mismatch',
                        'message' => 'Stale issue',
                        'count' => 1,
                    ],
                ],
                'ignored' => [],
            ],
        ]);
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);

        $this->assertContains('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id));

        $search = ll_tools_ipa_keyboard_search_recordings($wordset_id, '', 'both', true, false, false, 1);
        $this->assertSame(1, (int) ($search['total_matches'] ?? -1));
        $this->assertContains('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id));

        $request = new WP_REST_Request('POST', '/ll-tools/v1/wordsets/' . $wordset_id . '/transcription-validations');
        $request->set_param('wordset', (string) $wordset_id);
        $request->set_param('limit', 1);
        $response = ll_tools_rest_automation_refresh_transcription_validations($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = (array) $response->get_data();
        $batch = is_array($data['batch'] ?? null) ? $data['batch'] : [];
        $limit = is_array($batch['limit'] ?? null) ? $batch['limit'] : [];
        $this->assertSame(1, (int) ($data['updated_count'] ?? -1));
        $this->assertSame(1, (int) ($data['limit'] ?? -1));
        $this->assertSame(1, (int) ($limit['max'] ?? -1));
        $this->assertNotContains('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id));

        $search_after_refresh = ll_tools_ipa_keyboard_search_recordings($wordset_id, '', 'both', true, false, false, 1);
        $this->assertSame(0, (int) ($search_after_refresh['total_matches'] ?? -1));
    }

    public function test_rest_validation_refresh_clamps_write_batches_to_one_recording(): void
    {
        $wordset_id = $this->createWordset('Clamped Validation Refresh');
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            'm' => ['any' => 'm'],
            'a' => ['any' => 'a'],
        ]);

        $recording_ids = [];
        for ($i = 1; $i <= 2; $i++) {
            $word_id = $this->createWord($wordset_id, 'Valid item ' . $i, 'ma');
            $recording_id = $this->createRecording($word_id, 'ma', 'ma');
            update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
                $wordset_id => [
                    'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version() - 1,
                    'active' => [
                        [
                            'rule_key' => 'builtin:orthography_mismatch',
                            'code' => 'orthography_mismatch',
                            'type' => 'builtin',
                            'label' => 'Orthography mismatch',
                            'message' => 'Stale issue',
                            'count' => 1,
                        ],
                    ],
                    'ignored' => [],
                ],
            ]);
            update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);
            $recording_ids[] = $recording_id;
        }

        $request = new WP_REST_Request('POST', '/ll-tools/v1/wordsets/' . $wordset_id . '/transcription-validations');
        $request->set_param('wordset', (string) $wordset_id);
        $request->set_param('limit', 10);
        $request->set_param('scan_limit', 200);
        $response = ll_tools_rest_automation_refresh_transcription_validations($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $data = (array) $response->get_data();
        $batch = is_array($data['batch'] ?? null) ? $data['batch'] : [];
        $limit = is_array($batch['limit'] ?? null) ? $batch['limit'] : [];
        $scan_limit = is_array($batch['scan_limit'] ?? null) ? $batch['scan_limit'] : [];

        $this->assertSame(1, (int) ($data['updated_count'] ?? -1));
        $this->assertSame(1, (int) ($data['limit'] ?? -1));
        $this->assertSame(25, (int) ($data['scan_limit'] ?? -1));
        $this->assertTrue((bool) ($limit['clamped'] ?? false));
        $this->assertTrue((bool) ($scan_limit['clamped'] ?? false));
        $this->assertTrue((bool) ($batch['server_side_recommended'] ?? false));
        $this->assertCount(1, (array) ($data['updated_recording_ids'] ?? []));

        $remaining_issue_count = 0;
        foreach ($recording_ids as $recording_id) {
            if (in_array('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id), true)) {
                $remaining_issue_count++;
            }
        }
        $this->assertSame(1, $remaining_issue_count);
    }

    public function test_manual_rule_outputs_preserve_apostrophes_for_conversion_and_mismatch_detection(): void
    {
        $wordset_id = $this->createWordset('Apostrophe Manual Orthography Rule');
        $hbar = "\u{0127}";
        update_term_meta(
            $wordset_id,
            ll_tools_ipa_orthography_manual_rules_meta_key(),
            ll_tools_ipa_orthography_sanitize_manual_rules([
                $hbar => ['any' => "'h"],
                'a' => ['any' => 'a'],
            ], $wordset_id)
        );

        $manual_rules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
        $this->assertSame("'h", (string) ($manual_rules[$hbar]['any'] ?? ''));

        $prediction = ll_tools_ipa_orthography_convert_ipa_to_best_text(
            $hbar . 'a',
            ll_tools_ipa_orthography_build_engine_rules_for_wordset($wordset_id),
            $wordset_id
        );
        $this->assertTrue((bool) ($prediction['complete'] ?? false));
        $this->assertSame("'ha", (string) ($prediction['text'] ?? ''));

        $matching_word_id = $this->createWord($wordset_id, 'Matching apostrophe h', "'ha");
        $matching_recording_id = $this->createRecording($matching_word_id, "'ha", $hbar . 'a');
        ll_tools_ipa_keyboard_update_recording_validation($matching_recording_id);
        $matching_validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($matching_recording_id, $wordset_id);
        $matching_codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($matching_validation['active'] ?? []));
        $this->assertNotContains('orthography_mismatch', $matching_codes);

        $mismatching_word_id = $this->createWord($wordset_id, 'Missing apostrophe h', 'ha');
        $mismatching_recording_id = $this->createRecording($mismatching_word_id, 'ha', $hbar . 'a');
        ll_tools_ipa_keyboard_update_recording_validation($mismatching_recording_id);
        $mismatching_validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($mismatching_recording_id, $wordset_id);
        $mismatching_codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($mismatching_validation['active'] ?? []));
        $this->assertContains('orthography_mismatch', $mismatching_codes);

        $data = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $rows = (array) ($data['contradictions'] ?? []);
        $this->assertCount(1, $rows);
        $this->assertSame($mismatching_recording_id, (int) ($rows[0]['recording_id'] ?? 0));
        $this->assertSame("'ha", (string) ($rows[0]['predicted_text'] ?? ''));
    }

    public function test_apply_orthography_suggestion_handler_updates_recording_text(): void
    {
        $wordset_id = $this->createWordset('Phrase Override Suggestion');
        $this->configureDesErzenFixture($wordset_id);
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
        $this->assertSame('dest erzen', (string) get_post_meta($recording_id, 'recording_text', true));
        $this->assertSame(0, (int) (($response['data']['orthography']['stats']['active_contradiction_count'] ?? 0)));
    }

    public function test_apply_orthography_suggestion_preserves_configured_optional_match_choice(): void
    {
        $wordset_id = $this->createWordset('Optional Match Suggestion');
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            'a' => ['any' => 'a'],
            'x' => ['any' => 'q'],
            'b' => ['any' => 'b'],
        ]);
        $this->setOrthographySettings($wordset_id, [
            'optional_matches' => [
                [
                    'ipa' => 'x',
                    'orthography' => 'q',
                ],
            ],
        ]);
        $word_id = $this->createWord($wordset_id, 'Optional middle segment', 'ab');
        $recording_id = $this->createRecording(
            $word_id,
            'ab',
            'axb'
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
        $this->assertSame('ab', (string) get_post_meta($recording_id, 'recording_text', true));
    }

    public function test_word_exception_marks_contradiction_as_approved(): void
    {
        $wordset_id = $this->createWordset('Orthography Exceptions');
        $this->configureDesErzenFixture($wordset_id);

        $contradicting_word_id = $this->createWord($wordset_id, 'Ten eight', 'dest erzen');
        $contradicting_recording_id = $this->createRecording($contradicting_word_id, 'des erzen', 'dɛs ɛɾzɛn');

        $before = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $this->assertSame(1, (int) (($before['stats']['active_contradiction_count'] ?? 0)));
        ll_tools_ipa_keyboard_update_recording_validation($contradicting_recording_id);
        $before_validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($contradicting_recording_id, $wordset_id);
        $before_codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($before_validation['active'] ?? []));
        $this->assertContains('orthography_mismatch', $before_codes);

        ll_tools_ipa_orthography_update_exception_word_id($wordset_id, $contradicting_word_id, true);

        $after = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $this->assertSame(0, (int) (($after['stats']['active_contradiction_count'] ?? 0)));
        $this->assertSame(1, (int) (($after['stats']['approved_contradiction_count'] ?? 0)));

        $rows = (array) ($after['contradictions'] ?? []);
        $this->assertCount(1, $rows);
        $this->assertTrue((bool) ($rows[0]['approved_exception'] ?? false));

        ll_tools_ipa_keyboard_update_recording_validation($contradicting_recording_id);
        $after_validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($contradicting_recording_id, $wordset_id);
        $after_codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($after_validation['active'] ?? []));
        $this->assertNotContains('orthography_mismatch', $after_codes);
    }

    public function test_word_exception_stops_applying_after_dictionary_entry_relink(): void
    {
        $wordset_id = $this->createWordset('Orthography Exception Entry Binding');
        $this->configureDesErzenFixture($wordset_id);
        $original_entry_id = $this->createDictionaryEntry('Original Lexeme');
        $other_entry_id = $this->createDictionaryEntry('Other Lexeme');

        $word_id = $this->createWord($wordset_id, 'Ten eight', 'dest erzen');
        $this->linkWordToDictionaryEntry($word_id, $original_entry_id);
        $recording_id = $this->createRecording($word_id, 'des erzen', 'dɛs ɛɾzɛn');

        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $this->assertContains('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id));

        ll_tools_ipa_orthography_update_exception_word_id($wordset_id, $word_id, true);
        $entry_bindings = ll_tools_ipa_orthography_get_exception_dictionary_entry_ids($wordset_id);
        $this->assertSame($original_entry_id, (int) ($entry_bindings[$word_id] ?? 0));

        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $this->assertNotContains('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id));

        $this->linkWordToDictionaryEntry($word_id, $other_entry_id);
        ll_tools_ipa_keyboard_update_recording_validation($recording_id);
        $this->assertContains('orthography_mismatch', $this->validationCodes($recording_id, $wordset_id));

        $data = ll_tools_ipa_keyboard_build_orthography_data($wordset_id);
        $this->assertSame(1, (int) (($data['stats']['active_contradiction_count'] ?? 0)));
        $this->assertSame(0, (int) (($data['stats']['approved_contradiction_count'] ?? 0)));
    }

    /**
     * @param array<string,mixed> $validation
     * @return array<string,mixed>|null
     */
    private function findOrthographyMismatchIssue(array $validation): ?array
    {
        foreach ((array) ($validation['active'] ?? []) as $issue) {
            if ((string) ($issue['code'] ?? '') === 'orthography_mismatch') {
                return (array) $issue;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function setOrthographySettings(int $wordset_id, array $settings): void
    {
        update_term_meta(
            $wordset_id,
            ll_tools_ipa_orthography_settings_meta_key(),
            ll_tools_ipa_orthography_sanitize_settings($settings, $wordset_id)
        );
    }

    private function configureDesErzenFixture(int $wordset_id): void
    {
        update_term_meta($wordset_id, ll_tools_ipa_orthography_manual_rules_meta_key(), [
            'd' => ['any' => 'd'],
            'ɛ' => ['any' => 'e'],
            's' => ['any' => 's'],
            'ɾ' => ['any' => 'r'],
            'z' => ['any' => 'z'],
            'n' => ['any' => 'n'],
        ]);
        $this->setOrthographySettings($wordset_id, [
            'phrase_overrides' => [
                [
                    'from' => ['des', 'erzen'],
                    'to' => ['dest', 'erzen'],
                ],
            ],
        ]);
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

    private function createDictionaryEntry(string $title): int
    {
        $entry_id = self::factory()->post->create([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'post_title' => $title,
        ]);
        $this->assertGreaterThan(0, $entry_id);
        return (int) $entry_id;
    }

    private function linkWordToDictionaryEntry(int $word_id, int $entry_id): void
    {
        update_post_meta($word_id, LL_TOOLS_WORD_DICTIONARY_ENTRY_META_KEY, $entry_id);
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

    /**
     * @return string[]
     */
    private function validationCodes(int $recording_id, int $wordset_id): array
    {
        $validation = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        return array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($validation['active'] ?? []));
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
