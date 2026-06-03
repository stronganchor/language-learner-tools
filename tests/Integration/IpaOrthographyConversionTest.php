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
