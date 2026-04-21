<?php
declare(strict_types=1);

final class UserStudyStateLegacyStarModeTest extends LL_Tools_TestCase
{
    public function test_get_user_study_state_ignores_and_clears_legacy_star_mode_meta(): void
    {
        $user_id = self::factory()->user->create();
        $this->assertIsInt($user_id);

        update_user_meta($user_id, LL_TOOLS_USER_STARRED_META, [101, 102]);
        update_user_meta($user_id, 'll_user_star_mode', 'only');

        $state = ll_tools_get_user_study_state($user_id);

        $this->assertSame([101, 102], array_values(array_map('intval', (array) ($state['starred_word_ids'] ?? []))));
        $this->assertSame('normal', (string) ($state['star_mode'] ?? ''));
        $this->assertSame('', (string) get_user_meta($user_id, 'll_user_star_mode', true));
    }

    public function test_save_user_study_state_never_persists_star_mode(): void
    {
        $user_id = self::factory()->user->create();
        $this->assertIsInt($user_id);

        update_user_meta($user_id, 'll_user_star_mode', 'weighted');

        $saved = ll_tools_save_user_study_state([
            'wordset_id' => 7,
            'category_ids' => [11, 12],
            'starred_word_ids' => [21, 22],
            'star_mode' => 'only',
            'fast_transitions' => true,
        ], $user_id);

        $this->assertSame('normal', (string) ($saved['star_mode'] ?? ''));
        $this->assertSame('', (string) get_user_meta($user_id, 'll_user_star_mode', true));

        $stored = ll_tools_get_user_study_state($user_id);
        $this->assertSame('normal', (string) ($stored['star_mode'] ?? ''));
        $this->assertSame([21, 22], array_values(array_map('intval', (array) ($stored['starred_word_ids'] ?? []))));
        $this->assertTrue((bool) ($stored['fast_transitions'] ?? false));
    }
}
