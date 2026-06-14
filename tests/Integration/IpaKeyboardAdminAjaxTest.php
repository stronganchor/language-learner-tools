<?php
declare(strict_types=1);

final class IpaKeyboardAdminAjaxTest extends LL_Tools_TestCase
{
    /** @var array<string,mixed> */
    private $postBackup = [];

    /** @var array<string,mixed> */
    private $requestBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->postBackup = $_POST;
        $this->requestBackup = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        $_REQUEST = $this->requestBackup;
        parent::tearDown();
    }

    public function test_update_recording_ipa_returns_symbol_diff_and_updated_recording_payload(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('IPA Admin Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ship',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);
        update_post_meta($word_id, 'word_translation', 'Gem');

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Ship Recording',
        ]);
        wp_set_object_terms($recording_id, ['Isolation'], 'recording_type', false);
        update_post_meta($recording_id, 'audio_file_path', 'wp-content/uploads/test-audio/ship.mp3');
        update_post_meta($recording_id, 'recording_text', 'ship');
        update_post_meta($recording_id, 'recording_translation', 'gem');
        update_post_meta($recording_id, 'recording_ipa', 'ʃʃ');
        ll_tools_ipa_keyboard_mark_recording_needs_auto_review($recording_id);

        wp_set_current_user($user_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $recording_id,
            'recording_ipa' => 'ʒ',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_update_recording_ipa_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $this->assertSame('ʒ', (string) get_post_meta($recording_id, 'recording_ipa', true));

        $data = (array) ($response['data'] ?? []);
        $this->assertSame($recording_id, (int) ($data['recording_id'] ?? 0));
        $this->assertSame('ʒ', (string) ($data['recording_ipa'] ?? ''));
        $this->assertSame([], array_values((array) ($data['keyboard_symbols'] ?? [])));
        $this->assertSame(['ʃ'], array_values((array) ($data['previous_symbols'] ?? [])));
        $this->assertSame(['ʒ'], array_values((array) ($data['symbols'] ?? [])));
        $this->assertSame(2, (int) (($data['previous_symbol_counts']['ʃ'] ?? 0)));
        $this->assertSame(1, (int) (($data['symbol_counts']['ʒ'] ?? 0)));
        $this->assertTrue((bool) ($data['letter_map_refresh_required'] ?? false));
        $this->assertArrayNotHasKey('letter_map', $data);

        $recording = (array) ($data['recording'] ?? []);
        $this->assertSame($recording_id, (int) ($recording['recording_id'] ?? 0));
        $this->assertSame($word_id, (int) ($recording['word_id'] ?? 0));
        $this->assertSame('Ship', (string) ($recording['word_text'] ?? ''));
        $this->assertSame('Gem', (string) ($recording['word_translation'] ?? ''));
        $this->assertSame('Isolation', (string) ($recording['recording_type'] ?? ''));
        $this->assertSame('isolation', (string) ($recording['recording_type_slug'] ?? ''));
        $this->assertSame('isolation', (string) ($recording['recording_icon_type'] ?? ''));
        $this->assertSame('ship', (string) ($recording['recording_text'] ?? ''));
        $this->assertSame('gem', (string) ($recording['recording_translation'] ?? ''));
        $this->assertSame('ʒ', (string) ($recording['recording_ipa'] ?? ''));
        $this->assertTrue((bool) ($recording['needs_review'] ?? false));
        $this->assertTrue((bool) (($recording['review_fields'] ?? [])['recording_ipa'] ?? false));
        $this->assertSame(site_url('wp-content/uploads/test-audio/ship.mp3'), (string) ($recording['audio_url'] ?? ''));
        $this->assertSame('Play Isolation recording', (string) ($recording['audio_label'] ?? ''));
        $this->assertNotSame('', (string) ($recording['word_edit_link'] ?? ''));
        $this->assertTrue(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));

        $this->assertSame([], ll_tools_word_grid_get_wordset_ipa_special_chars($wordset_id));

        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
        ];
        $_REQUEST = $_POST;

        $letterMapResponse = $this->runJsonEndpoint(static function (): void {
            ll_tools_get_ipa_keyboard_letter_map_handler();
        });

        $this->assertTrue((bool) ($letterMapResponse['success'] ?? false));
        $this->assertIsArray($letterMapResponse['data']['letter_map'] ?? null);
    }

    public function test_search_recordings_handler_returns_paginated_review_results(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('Search Pagination Wordset');
        update_term_meta($wordset_id, 'll_wordset_ipa_special_chars', ['ɬ']);

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $title) {
            $word_id = self::factory()->post->create([
                'post_type' => 'words',
                'post_status' => 'publish',
                'post_title' => $title,
            ]);
            wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

            $recording_id = self::factory()->post->create([
                'post_type' => 'word_audio',
                'post_status' => 'publish',
                'post_parent' => $word_id,
                'post_title' => $title . ' Recording',
            ]);
            update_post_meta($recording_id, 'recording_text', strtolower($title));
            update_post_meta($recording_id, 'recording_ipa', 'a');
            ll_tools_ipa_keyboard_mark_recording_needs_auto_review($recording_id);
        }

        $pageSizeFilter = static function (): int {
            return 2;
        };
        $wordAudioQueryCount = 0;
        $wordAudioQueryCounter = static function (WP_Query $query) use (&$wordAudioQueryCount): void {
            if ($query->get('post_type') === 'word_audio' && $query->get('fields') === 'ids') {
                $wordAudioQueryCount++;
            }
        };
        add_filter('ll_tools_ipa_keyboard_search_results_per_page', $pageSizeFilter);
        add_action('pre_get_posts', $wordAudioQueryCounter);

        try {
            wp_set_current_user($user_id);
            $_POST = [
                'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
                'wordset_id' => $wordset_id,
                'review_only' => 1,
                'search_page' => 2,
            ];
            $_REQUEST = $_POST;

            $response = $this->runJsonEndpoint(static function (): void {
                ll_tools_search_ipa_keyboard_recordings_handler();
            });

            $this->assertTrue((bool) ($response['success'] ?? false));
            $this->assertLessThanOrEqual(3, $wordAudioQueryCount);
            $data = (array) ($response['data'] ?? []);
            $this->assertSame(5, (int) ($data['total_matches'] ?? 0));
            $this->assertSame(2, (int) ($data['shown_count'] ?? 0));
            $this->assertSame(2, (int) ($data['current_page'] ?? 0));
            $this->assertSame(3, (int) ($data['total_pages'] ?? 0));
            $this->assertSame(2, (int) ($data['per_page'] ?? 0));
            $this->assertSame(3, (int) ($data['page_start'] ?? 0));
            $this->assertSame(4, (int) ($data['page_end'] ?? 0));
            $this->assertTrue((bool) ($data['has_more'] ?? false));
            $this->assertSame([], array_values((array) (($data['transcription'] ?? [])['keyboard_symbols'] ?? [])));

            $results = array_values((array) ($data['results'] ?? []));
            $this->assertCount(2, $results);
            $this->assertSame('Charlie', (string) ($results[0]['word_text'] ?? ''));
            $this->assertSame('Delta', (string) ($results[1]['word_text'] ?? ''));

            $wordAudioQueryCount = 0;
            $_POST['search_page'] = 1;
            $_POST['per_page'] = 3;
            $_REQUEST = $_POST;

            $customPageSizeResponse = $this->runJsonEndpoint(static function (): void {
                ll_tools_search_ipa_keyboard_recordings_handler();
            });

            $this->assertTrue((bool) ($customPageSizeResponse['success'] ?? false));
            $this->assertLessThanOrEqual(3, $wordAudioQueryCount);
            $customPageSizeData = (array) ($customPageSizeResponse['data'] ?? []);
            $this->assertSame(5, (int) ($customPageSizeData['total_matches'] ?? 0));
            $this->assertSame(3, (int) ($customPageSizeData['shown_count'] ?? 0));
            $this->assertSame(1, (int) ($customPageSizeData['current_page'] ?? 0));
            $this->assertSame(2, (int) ($customPageSizeData['total_pages'] ?? 0));
            $this->assertSame(3, (int) ($customPageSizeData['per_page'] ?? 0));
            $this->assertSame(1, (int) ($customPageSizeData['page_start'] ?? 0));
            $this->assertSame(3, (int) ($customPageSizeData['page_end'] ?? 0));
            $this->assertTrue((bool) ($customPageSizeData['has_more'] ?? false));

            $customPageSizeResults = array_values((array) ($customPageSizeData['results'] ?? []));
            $this->assertCount(3, $customPageSizeResults);
            $this->assertSame('Alpha', (string) ($customPageSizeResults[0]['word_text'] ?? ''));
            $this->assertSame('Bravo', (string) ($customPageSizeResults[1]['word_text'] ?? ''));
            $this->assertSame('Charlie', (string) ($customPageSizeResults[2]['word_text'] ?? ''));

            $wordAudioQueryCount = 0;
            unset($_POST['per_page']);
            $_POST['search_page'] = 99;
            $_REQUEST = $_POST;

            $lastPageResponse = $this->runJsonEndpoint(static function (): void {
                ll_tools_search_ipa_keyboard_recordings_handler();
            });

            $this->assertTrue((bool) ($lastPageResponse['success'] ?? false));
            $this->assertLessThanOrEqual(3, $wordAudioQueryCount);
            $lastPageData = (array) ($lastPageResponse['data'] ?? []);
            $this->assertSame(3, (int) ($lastPageData['current_page'] ?? 0));
            $this->assertSame(5, (int) ($lastPageData['page_start'] ?? 0));
            $this->assertSame(5, (int) ($lastPageData['page_end'] ?? 0));
            $this->assertSame(1, (int) ($lastPageData['shown_count'] ?? 0));
            $this->assertFalse((bool) ($lastPageData['has_more'] ?? true));

            $lastPageResults = array_values((array) ($lastPageData['results'] ?? []));
            $this->assertCount(1, $lastPageResults);
            $this->assertSame('Echo', (string) ($lastPageResults[0]['word_text'] ?? ''));
        } finally {
            remove_filter('ll_tools_ipa_keyboard_search_results_per_page', $pageSizeFilter);
            remove_action('pre_get_posts', $wordAudioQueryCounter);
        }
    }

    public function test_set_review_state_handler_can_mark_and_clear_recording_review(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('Review Toggle Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Later',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Later Recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'later');
        update_post_meta($recording_id, 'recording_ipa', 'la');

        wp_set_current_user($user_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $recording_id,
            'review_field' => 'recording_text',
            'review_note' => 'Unsure whether this is a short or long vowel.',
            'needs_review' => 1,
        ];
        $_REQUEST = $_POST;

        $markResponse = $this->runJsonEndpoint(static function (): void {
            ll_tools_set_ipa_keyboard_transcription_review_state_handler();
        });

        $this->assertTrue((bool) ($markResponse['success'] ?? false));
        $this->assertTrue(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));
        $this->assertTrue(ll_tools_ipa_keyboard_recording_field_needs_review($recording_id, 'recording_text'));
        $this->assertSame('Unsure whether this is a short or long vowel.', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
        $markedRecording = (array) (($markResponse['data'] ?? [])['recording'] ?? []);
        $this->assertTrue((bool) ($markedRecording['needs_review'] ?? false));
        $this->assertTrue((bool) (($markedRecording['review_fields'] ?? [])['recording_text'] ?? false));
        $this->assertSame('Unsure whether this is a short or long vowel.', (string) ($markedRecording['review_note'] ?? ''));

        $_POST['needs_review'] = 0;
        $_POST['review_note'] = '';
        $_REQUEST = $_POST;

        $clearResponse = $this->runJsonEndpoint(static function (): void {
            ll_tools_set_ipa_keyboard_transcription_review_state_handler();
        });

        $this->assertTrue((bool) ($clearResponse['success'] ?? false));
        $this->assertFalse(ll_tools_ipa_keyboard_recording_needs_auto_review($recording_id));
        $this->assertSame('', ll_tools_ipa_keyboard_get_recording_review_note($recording_id));
        $clearedRecording = (array) (($clearResponse['data'] ?? [])['recording'] ?? []);
        $this->assertFalse((bool) ($clearedRecording['needs_review'] ?? true));
    }

    public function test_validation_flags_bad_dental_marks_and_unapproved_symbols(): void
    {
        $wordset_id = $this->create_wordset('IPA Validation Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Invalid IPA',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Invalid IPA Recording',
        ]);
        update_post_meta($recording_id, 'recording_ipa', 'q̪ʰ aġʒɨz');

        $validation = ll_tools_ipa_keyboard_validate_recording_for_wordset($recording_id, $wordset_id);
        $codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($validation['active'] ?? []));

        $this->assertContains('dental_diacritic_context', $codes);
        $this->assertContains('unapproved_ipa_symbol', $codes);
    }

    public function test_flag_illegal_symbol_adds_validation_issue_and_removes_keyboard_symbol(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('Illegal IPA Symbol Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Illegal IPA',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Illegal IPA Recording',
        ]);
        update_post_meta($recording_id, 'recording_ipa', 'ʃa');

        $this->assertContains('ʃ', ll_tools_ipa_keyboard_get_keyboard_symbols($wordset_id, 'ipa'));

        wp_set_current_user($user_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'symbol' => 'ʃ',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_flag_ipa_keyboard_illegal_symbol_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $this->assertContains('ʃ', (array) ($data['illegal_symbols'] ?? []));
        $this->assertNotContains('ʃ', (array) (($data['transcription'] ?? [])['keyboard_symbols'] ?? []));

        $state = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        $codes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($state['active'] ?? []));

        $this->assertContains('illegal_ipa_symbol', $codes);
    }

    public function test_approve_symbol_mapping_adds_wordset_approval_and_orthography_rule(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('IPA Symbol Approval Wordset');

        $first_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Blind',
        ]);
        wp_set_object_terms($first_word_id, [$wordset_id], 'wordset', false);
        $first_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $first_word_id,
            'post_title' => 'Blind Recording',
        ]);
        update_post_meta($first_recording_id, 'recording_text', 'kör');
        update_post_meta($first_recording_id, 'recording_ipa', 'kør');

        $second_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Word',
        ]);
        wp_set_object_terms($second_word_id, [$wordset_id], 'wordset', false);
        $second_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $second_word_id,
            'post_title' => 'Word Recording',
        ]);
        update_post_meta($second_recording_id, 'recording_text', 'söz');
        update_post_meta($second_recording_id, 'recording_ipa', 'søz');

        $before = ll_tools_ipa_keyboard_validate_recording_for_wordset($first_recording_id, $wordset_id);
        $beforeCodes = array_map(static function (array $issue): string {
            return (string) ($issue['code'] ?? '');
        }, (array) ($before['active'] ?? []));
        $this->assertContains('unapproved_ipa_symbol', $beforeCodes);
        $approvalOptions = (array) (((array) ($before['active'][0] ?? []))['approval_options'] ?? []);
        $this->assertSame('ø', (string) (($approvalOptions[0] ?? [])['symbol'] ?? ''));
        $this->assertSame('ö', (string) (($approvalOptions[0] ?? [])['output'] ?? ''));

        wp_set_current_user($user_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $first_recording_id,
            'symbol' => 'ø',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_approve_ipa_keyboard_symbol_mapping_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $this->assertSame('ø', (string) ($data['approved_symbol'] ?? ''));
        $this->assertSame('ö', (string) ($data['orthography_output'] ?? ''));
        $this->assertContains('ø', (array) ($data['approved_ipa_symbols'] ?? []));
        $this->assertContains('ø', ll_tools_ipa_keyboard_get_wordset_approved_ipa_symbols($wordset_id));

        $manualRules = ll_tools_ipa_orthography_get_manual_rules($wordset_id);
        $this->assertSame('ö', (string) (($manualRules['ø'] ?? [])['any'] ?? ''));

        $firstAfter = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($first_recording_id, $wordset_id);
        $secondAfter = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($second_recording_id, $wordset_id);
        $this->assertSame([], (array) ($firstAfter['active'] ?? []));
        $this->assertSame([], (array) ($secondAfter['active'] ?? []));

        $recording = (array) ($data['recording'] ?? []);
        $this->assertSame($first_recording_id, (int) ($recording['recording_id'] ?? 0));
        $this->assertSame(0, (int) ($recording['issue_count'] ?? -1));
    }

    public function test_search_row_payload_uses_cached_validation_unless_refresh_requested(): void
    {
        $wordset_id = $this->create_wordset('Stale Validation Search Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Fresh IPA',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Fresh IPA Recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Biregê ispanax');
        update_post_meta($recording_id, 'recording_ipa', 'biʁege ispʰanaχ');
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
            $wordset_id => [
                'active' => [
                    [
                        'rule_key' => 'builtin:unapproved_ipa_symbol',
                        'code' => 'unapproved_ipa_symbol',
                        'type' => 'builtin',
                        'label' => 'Unapproved IPA symbol',
                        'message' => 'This IPA token contains a symbol outside the approved inventory.',
                        'count' => 1,
                        'samples' => ['ø'],
                    ],
                ],
                'ignored' => [],
            ],
        ]);
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);

        $payload = ll_tools_ipa_keyboard_build_search_row_payload($recording_id, $wordset_id, [
            'word_text' => 'Fresh IPA',
            'translation' => '',
        ]);

        $this->assertSame('unapproved_ipa_symbol', (string) (($payload['issues'][0]['code'] ?? '')));
        $this->assertSame(1, (int) ($payload['issue_count'] ?? -1));
        $this->assertSame('1', (string) get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), true));

        $payload = ll_tools_ipa_keyboard_build_search_row_payload($recording_id, $wordset_id, [
            'word_text' => 'Fresh IPA',
            'translation' => '',
        ], '', true);

        $this->assertSame([], (array) ($payload['issues'] ?? []));
        $this->assertSame(0, (int) ($payload['issue_count'] ?? -1));
        $this->assertSame('', (string) get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), true));
    }

    public function test_approve_symbol_mapping_clears_stale_unapproved_symbol_warning(): void
    {
        $user_id = $this->create_viewer_user();
        $wordset_id = $this->create_wordset('Stale IPA Symbol Approval Wordset');
        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Ispanakli Borek',
        ]);
        wp_set_object_terms($word_id, [$wordset_id], 'wordset', false);

        $recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $word_id,
            'post_title' => 'Ispanakli Borek Recording',
        ]);
        update_post_meta($recording_id, 'recording_text', 'Biregê ispanax');
        update_post_meta($recording_id, 'recording_ipa', 'biʁege ispʰanaχ');
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
            $wordset_id => [
                'active' => [
                    [
                        'rule_key' => 'builtin:unapproved_ipa_symbol',
                        'code' => 'unapproved_ipa_symbol',
                        'type' => 'builtin',
                        'label' => 'Unapproved IPA symbol',
                        'message' => 'This IPA token contains a symbol outside the approved inventory.',
                        'count' => 1,
                        'samples' => ['ø'],
                        'approval_options' => [
                            [
                                'symbol' => 'ø',
                                'output' => 'ö',
                            ],
                        ],
                    ],
                ],
                'ignored' => [],
            ],
        ]);
        update_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);

        wp_set_current_user($user_id);
        $_POST = [
            'nonce' => wp_create_nonce('ll_ipa_keyboard_admin'),
            'wordset_id' => $wordset_id,
            'recording_id' => $recording_id,
            'symbol' => 'ø',
            'output' => 'ö',
        ];
        $_REQUEST = $_POST;

        $response = $this->runJsonEndpoint(static function (): void {
            ll_tools_approve_ipa_keyboard_symbol_mapping_handler();
        });

        $this->assertTrue((bool) ($response['success'] ?? false));
        $data = (array) ($response['data'] ?? []);
        $this->assertContains('ø', (array) ($data['approved_ipa_symbols'] ?? []));

        $recording = (array) ($data['recording'] ?? []);
        $this->assertSame($recording_id, (int) ($recording['recording_id'] ?? 0));
        $this->assertSame([], (array) ($recording['issues'] ?? []));
        $this->assertSame(0, (int) ($recording['issue_count'] ?? -1));
        $this->assertSame('', (string) get_post_meta($recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), true));

        $state = ll_tools_ipa_keyboard_get_recording_wordset_validation_result($recording_id, $wordset_id);
        $this->assertSame([], (array) ($state['active'] ?? []));
    }

    public function test_flagged_validation_notice_counts_ignore_orphaned_wordset_state(): void
    {
        $user_id = $this->create_viewer_user();
        wp_set_current_user($user_id);

        $stale_wordset_id = $this->create_wordset('Stale Notice Wordset');
        $valid_wordset_id = $this->create_wordset('Valid Notice Wordset');
        $issue = [
            'rule_key' => 'builtin:orthography_mismatch',
            'code' => 'orthography_mismatch',
            'type' => 'builtin',
            'label' => 'Orthography mismatch',
            'message' => 'Saved text does not match the current orthography rules.',
            'count' => 1,
        ];

        $orphaned_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Moved Word',
        ]);
        wp_set_object_terms($orphaned_word_id, [$valid_wordset_id], 'wordset', false);

        $orphaned_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $orphaned_word_id,
            'post_title' => 'Moved Word Recording',
        ]);
        update_post_meta($orphaned_recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
            $stale_wordset_id => [
                'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version(),
                'active' => [$issue],
                'ignored' => [],
            ],
        ]);
        update_post_meta($orphaned_recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);

        $hidden_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'draft',
            'post_title' => 'Hidden Word',
        ]);
        wp_set_object_terms($hidden_word_id, [$stale_wordset_id], 'wordset', false);

        $hidden_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $hidden_word_id,
            'post_title' => 'Hidden Word Recording',
        ]);
        update_post_meta($hidden_recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
            $stale_wordset_id => [
                'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version(),
                'active' => [$issue],
                'ignored' => [],
            ],
        ]);
        update_post_meta($hidden_recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);

        $valid_word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Still Flagged',
        ]);
        wp_set_object_terms($valid_word_id, [$valid_wordset_id], 'wordset', false);

        $valid_recording_id = self::factory()->post->create([
            'post_type' => 'word_audio',
            'post_status' => 'publish',
            'post_parent' => $valid_word_id,
            'post_title' => 'Still Flagged Recording',
        ]);
        update_post_meta($valid_recording_id, ll_tools_ipa_keyboard_validation_state_meta_key(), [
            $valid_wordset_id => [
                'schema_version' => ll_tools_ipa_keyboard_get_validation_schema_version(),
                'active' => [$issue],
                'ignored' => [],
            ],
        ]);
        update_post_meta($valid_recording_id, ll_tools_ipa_keyboard_validation_issue_count_meta_key(), 1);

        $stale_search = ll_tools_ipa_keyboard_search_recordings($stale_wordset_id, '', 'both', true);
        $this->assertSame(0, (int) ($stale_search['total_matches'] ?? -1));

        $counts_by_wordset = [];
        foreach (ll_tools_ipa_keyboard_get_flagged_validation_recording_counts_by_wordset() as $entry) {
            $counts_by_wordset[(int) ($entry['wordset_id'] ?? 0)] = (int) ($entry['count'] ?? 0);
        }

        $this->assertArrayNotHasKey($stale_wordset_id, $counts_by_wordset);
        $this->assertSame(1, (int) ($counts_by_wordset[$valid_wordset_id] ?? 0));
        $this->assertSame(1, ll_tools_ipa_keyboard_get_flagged_validation_recording_count());
    }

    private function create_viewer_user(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        $user = get_user_by('id', $user_id);
        $this->assertInstanceOf(WP_User::class, $user);
        $user->add_cap('view_ll_tools');
        clean_user_cache($user_id);

        return $user_id;
    }

    private function create_wordset(string $name): int
    {
        $wordset = wp_insert_term($name, 'wordset');
        $this->assertIsArray($wordset);

        return (int) ($wordset['term_id'] ?? 0);
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
