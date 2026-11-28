<?php
declare(strict_types=1);

final class ZipaSettingsTest extends LL_Tools_TestCase {
    public function test_settings_and_word_aliases_are_isolated_to_the_selected_wordset(): void {
        $wordset = $this->createWordset();
        $other = $this->createWordset();
        $this->assertFalse(ll_tools_zipa_get_settings($wordset)['enabled']);
        $this->assertSame([], ll_tools_zipa_get_settings($wordset)['matcher']);

        $word = $this->createWord($wordset);
        $settings = [
            'enabled' => true,
            'audience' => 'everyone',
            'cardIds' => [$word],
            'ipaAliases' => [$word => ['kæt', 'kat']],
            'matcher' => [
                'confusableGraphemes' => ['p' => 'b'],
                'closeForms' => [$word => ['target' => 'kæt', 'heard' => ['kət']]],
            ],
        ];
        $this->assertTrue(ll_tools_zipa_save_settings($wordset, $settings));
        $this->assertSame(array_replace(ll_tools_zipa_default_settings(), $settings), ll_tools_zipa_get_settings($wordset));
        $this->assertSame(ll_tools_zipa_default_settings(), ll_tools_zipa_get_settings($other));

        $result = ll_tools_zipa_save_settings($other, $settings);
        $this->assertWPError($result);
        $this->assertSame('ll_zipa_invalid_word', $result->get_error_code());
        $this->assertSame(ll_tools_zipa_default_settings(), ll_tools_zipa_get_settings($other));
    }

    public function test_unrelated_recording_and_offsite_model_do_not_replace_existing_settings(): void {
        $wordset = $this->createWordset();
        $word = $this->createWord($wordset);
        $other_word = $this->createWord($wordset);
        $recording = self::factory()->post->create(['post_type' => 'word_audio', 'post_parent' => $other_word]);
        $valid = ['enabled' => true, 'audience' => 'managers'];
        $this->assertTrue(ll_tools_zipa_save_settings($wordset, $valid));

        $result = ll_tools_zipa_save_settings($wordset, ['cardReferences' => [$word => ['recordingId' => $recording]]]);
        $this->assertWPError($result);
        $this->assertSame('ll_zipa_invalid_recording', $result->get_error_code());

        $result = ll_tools_zipa_save_settings($wordset, ['browserModelManifestUrl' => 'https://external.example/model.json']);
        $this->assertWPError($result);
        $this->assertSame(array_replace(ll_tools_zipa_default_settings(), $valid), ll_tools_zipa_get_settings($wordset));
    }

    public function test_settings_form_round_trips_linguistic_rules_and_model_reference(): void {
        require_once LL_TOOLS_BASE_PATH . 'includes/admin/zipa-settings.php';
        $home_filter = static fn(): string => 'https://zipa.example.org';
        add_filter('home_url', $home_filter);
        try {
            $wordset = $this->createWordset();
            $word = $this->createWord($wordset);
            $recording = self::factory()->post->create(['post_type' => 'word_audio', 'post_parent' => $word]);
            $settings = array_replace(ll_tools_zipa_default_settings(), [
                'enabled' => true,
                'browserModelManifestUrl' => '/uploads/models/tuned.json',
                'cardReferences' => [$word => ['recordingId' => $recording, 'title' => "'Baga", 'ipa' => 'ʕbæɢæ']],
                'ipaAliases' => [$word => ['ʔbaɡa']],
                'matcher' => [
                    'tiedPairs' => [],
                    'inputSymbols' => ['▁' => ' '],
                    'ipaReplacements' => [['from' => '̥', 'to' => '', 'followedBy' => ['', ' ']]],
                    'closeForms' => [$word => ['target' => 'ʕbæɢæ', 'heard' => ['baɢæ']]],
                ],
                'stackCapture' => ['speechStartThreshold' => 0.04, 'silenceThreshold' => 0.02, 'feedbackHoldMs' => 900],
            ]);
            $input = ll_tools_zipa_admin_format_settings($settings);
            $this->assertTrue(ll_tools_zipa_admin_save_input($wordset, $input));
            $stored = ll_tools_zipa_get_settings($wordset);
            $settings['browserModelManifestUrl'] = 'https://zipa.example.org/uploads/models/tuned.json';
            $this->assertSame($settings, $stored);
        } finally {
            remove_filter('home_url', $home_filter);
        }
    }

    private function createWordset(): int {
        $term = wp_insert_term('ZIPA setting test ' . wp_generate_uuid4(), 'wordset');
        $this->assertIsArray($term);
        return (int) $term['term_id'];
    }

    private function createWord(int $wordset_id): int {
        $word = self::factory()->post->create(['post_type' => 'words', 'post_status' => 'draft']);
        $this->assertIsArray(wp_set_object_terms($word, [$wordset_id], 'wordset'));
        return $word;
    }
}
