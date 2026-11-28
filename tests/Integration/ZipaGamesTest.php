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
