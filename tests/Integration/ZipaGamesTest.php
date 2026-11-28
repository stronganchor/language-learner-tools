<?php
declare(strict_types=1);

final class ZipaGamesTest extends LL_Tools_TestCase
{
    public function test_configured_joined_answer_credits_only_active_cards(): void
    {
        $cards = [
            11 => $this->card(11, 'Mula', 'mula'),
            12 => $this->card(12, 'Sina', 'sina'),
            13 => $this->card(13, 'Tela', 'tela'),
        ];
        $profile = [
            'roundedVowels' => ['u', 'wi'],
            'roundedVowelOnsets' => 'm',
            'displayRules' => ['phones' => ['m' => 'm', 'ʷ' => 'w', 'i' => 'i', 'l' => 'l',
                'a' => 'a', 's' => 's', 'n' => 'n']],
        ];

        $answer = ll_tools_zipa_score(10, 'mʷilasina', $cards, [11, 12, 13], 0, $profile);
        $this->assertSame([11, 12], array_column($answer['matches'], 'word_id'));
        $this->assertSame(['right', 'right'], array_column($answer['matches'], 'bucket'));
        $this->assertSame('Mula Sina', $answer['display_transcript_text']);

        $answer = ll_tools_zipa_score(10, 'mʷilasina', $cards, [12, 13], 0, $profile);
        $this->assertSame([12], array_column($answer['matches'], 'word_id'));

        $profile['roundedVowels'] = [];
        $answer = ll_tools_zipa_score(10, 'mʷilasina', $cards, [11, 12, 13], 0, $profile);
        $this->assertFalse($answer['matched']);
    }

    public function test_an_exact_answer_to_a_different_card_blocks_a_close_guess(): void
    {
        $cards = [
            21 => $this->card(21, 'Tola', 'tola'),
            22 => $this->card(22, 'Dola', 'dola'),
        ];
        $profile = [
            'closeForms' => [21 => ['target' => 'tola', 'heard' => ['dola']]],
        ];

        $exact = ll_tools_zipa_score(10, 'dola', $cards, [21], 0, $profile);
        $this->assertFalse($exact['matched']);
        $this->assertSame('inactive_card', $exact['reason']);

        unset($cards[22]);
        $close = ll_tools_zipa_score(10, 'dola', $cards, [21], 0, $profile);
        $this->assertSame(21, $close['word_id']);
        $this->assertSame('close', $close['bucket']);
    }

    public function test_word_stack_credits_one_configured_doubling_in_joined_speech(): void
    {
        $cards = [
            21 => $this->card(21, 'Wela', 'wela'),
            22 => $this->card(22, 'Mina', 'mina'),
            23 => $this->card(23, 'Tao', 'tao'),
        ];
        $profile = [
            'repairableConsonants' => 'w',
            'repairableVowels' => 'e',
            'displayRules' => ['phones' => ['w' => 'w', 'ʷ' => 'w', 'e' => 'e', 'l' => 'l',
                'a' => 'a', 'm' => 'm', 'i' => 'i', 'n' => 'n', 't' => 't', 'o' => 'o']],
        ];
        foreach (['wʷelaminatao', 'wʷela mina tao', 'weelaminatao'] as $transcript) {
            $result = ll_tools_zipa_score(10, $transcript, $cards, [21, 22, 23], 0, $profile);
            $this->assertSame([21, 22, 23], array_column($result['matches'], 'word_id'), $transcript);
            $this->assertSame(['right', 'right', 'right'], array_column($result['matches'], 'bucket'), $transcript);
            $this->assertSame('Wela Mina Tao', $result['display_transcript_text'], $transcript);
        }

        $inactive = ll_tools_zipa_score(10, 'wʷelaminatao', $cards, [22, 23], 0, $profile);
        $this->assertSame([22, 23], array_column($inactive['matches'], 'word_id'));

        $cards[24] = $this->card(24, 'Wwela', 'wwela');
        $exact = ll_tools_zipa_score(10, 'wʷelaminatao', $cards, [21, 22, 23, 24], 0, $profile);
        $this->assertSame([24, 22, 23], array_column($exact['matches'], 'word_id'));

        $cards[25] = $this->card(25, 'Weela', 'weela');
        $ambiguous = ll_tools_zipa_score(10, 'wʷeelaminatao', $cards, [21, 22, 23, 24, 25], 0, $profile);
        $this->assertFalse($ambiguous['matched']);
    }

    public function test_equivalent_repair_rules_count_once_in_joined_speech(): void
    {
        $matcher = new LL_Tools_Zipa_Matcher([
            'repairableConsonants' => 'w',
            'roundedVowels' => ['u', 'wu'],
            'roundedVowelOnsets' => 'w',
        ]);
        $this->assertSame([
            ['word_id' => 21, 'repaired' => false],
            ['word_id' => 22, 'repaired' => false],
        ], $matcher->split_spelling_burst('wwulamina', [21 => 'Wula', 22 => 'Mina']));
    }

    public function test_word_stack_keeps_exact_words_around_an_unrenderable_middle(): void
    {
        $cards = [
            31 => $this->card(31, 'Mula', 'mula'),
            32 => $this->card(32, 'Sina', 'sina'),
            33 => $this->card(33, 'Tela', 'tela'),
        ];
        $profile = ['displayRules' => ['phones' => ['m' => 'm', 'u' => 'u', 'l' => 'l',
            'a' => 'a', 't' => 't', 'e' => 'e']]];
        foreach (['mulaɬɔŋtela', 'mula ɬɔŋ tela'] as $transcript) {
            $result = ll_tools_zipa_score(10, $transcript, $cards, [31, 32, 33], 0, $profile);
            $this->assertSame([31, 33], array_column($result['matches'], 'word_id'));
            $this->assertSame(['right', 'right'], array_column($result['matches'], 'bucket'));
            $this->assertSame('Mula Tela', $result['display_transcript_text']);
        }

        $result = ll_tools_zipa_score(10, 'mulaɬɔŋtela', $cards, [32, 33], 0, $profile);
        $this->assertSame([33], array_column($result['matches'], 'word_id'));
        $this->assertSame('Mula Tela', $result['display_transcript_text']);
        $this->assertFalse(ll_tools_zipa_score(10, 'mulaɬɔŋtela', $cards, [31], 31, $profile)['matched']);
    }

    public function test_word_stack_can_read_confirmed_spellings_on_either_side_of_an_unknown_middle(): void
    {
        $cards = [
            31 => $this->card(31, 'Mula', 'mula'),
            32 => $this->card(32, 'Sina', 'sina'),
            33 => $this->card(33, 'Tela', 'tela'),
        ];
        $profile = ['displayRules' => ['phones' => ['m' => 'm', 'ʊ' => 'u', 'l' => 'l', 'a' => 'a',
            'n' => 'n', 'o' => 'o', 'k' => 'k', 't' => 't', 'ɛ' => 'e', 's' => 's', 'i' => 'i']]];
        $result = ll_tools_zipa_score(10, 'mʊlanokatɛla', $cards, [31, 32, 33], 0, $profile);
        $this->assertSame([31, 33], array_column($result['matches'], 'word_id'));
        $this->assertSame('Mula noka Tela', $result['display_transcript_text']);

        $result = ll_tools_zipa_score(10, 'mʊlasinatɛla', $cards, [31, 32, 33], 0, $profile);
        $this->assertSame([31, 32, 33], array_column($result['matches'], 'word_id'));
    }

    public function test_word_stack_rejects_middle_recovery_with_unreliable_anchors(): void
    {
        $left = $this->card(31, 'Mula', 'mula');
        $left['ipaVariants'] = ['mila'];
        $right = $this->card(33, 'Tela', 'tela');
        $cases = [
            ['mulaɬɔŋmila', [31 => $left, 33 => $right]],
            ['mulaɬɔŋtela', [31 => $left, 33 => $right, 34 => $this->card(34, 'Moola', 'mula')]],
            ['mulastela', [31 => $this->card(31, 'Mulas', 'mulas'), 33 => $this->card(33, 'Astela', 'astela')]],
            ['mulanoʊtela', [31 => $left, 33 => $right, 35 => $this->card(35, 'Mula No', 'mula noʊ')]],
        ];
        foreach ($cases as [$transcript, $cards]) {
            $this->assertFalse(ll_tools_zipa_score(10, $transcript, $cards, array_keys($cards), 0, [])['matched'], $transcript);
        }

        $phrase = ll_tools_zipa_score(10, 'mula noʊ tela', $cases[3][1], [31, 33, 35], 0, []);
        $this->assertSame([35, 33], array_column($phrase['matches'], 'word_id'));
    }

    public function test_access_is_scoped_to_the_configured_wordset_and_hosted_requires_a_manager(): void
    {
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $other = self::factory()->term->create(['taxonomy' => 'wordset']);
        $this->assertTrue(ll_tools_zipa_save_settings($wordset, [
            'enabled' => true, 'hostedEnabled' => true, 'audience' => 'everyone',
        ]));

        $configured = ll_tools_zipa_get_settings($wordset);
        $service = static fn(array $config): array => array_replace($config, ['endpoint' => 'https://example.org/stt']);
        add_filter('ll_tools_zipa_hosted_service', $service);
        try {
            wp_set_current_user(0);
            $this->assertTrue(ll_tools_zipa_can_play($wordset, $configured));
            $this->assertFalse(ll_tools_zipa_can_play($other, ll_tools_zipa_get_settings($other)));
            $this->assertFalse(ll_tools_zipa_can_use_hosted($wordset, $configured));

            wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
            $this->assertTrue(ll_tools_zipa_can_play($wordset, $configured));
            $this->assertFalse(ll_tools_zipa_can_use_hosted($wordset, $configured));

            wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
            $this->assertTrue(ll_tools_zipa_can_use_hosted($wordset, $configured));

            wp_set_current_user(0);
            update_term_meta($wordset, LL_TOOLS_WORDSET_VISIBILITY_META_KEY, 'private');
            $this->assertFalse(ll_tools_zipa_can_play($wordset, $configured));
        } finally {
            remove_filter('ll_tools_zipa_hosted_service', $service);
            wp_set_current_user(0);
        }
    }

    private function card(int $id, string $title, string $ipa): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'recording_ipa' => $ipa,
            'ipaVariants' => [],
            'speaking_target_text' => $ipa,
            'speaking_display_texts' => ['title' => $title],
        ];
    }
}
