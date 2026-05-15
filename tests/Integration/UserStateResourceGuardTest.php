<?php
declare(strict_types=1);

final class UserStateResourceGuardTest extends LL_Tools_TestCase
{
    public function test_offline_sync_word_ids_are_sanitized_deduped_and_capped(): void
    {
        $limit_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_offline_app_sync_word_ids_limit', $limit_filter);

        try {
            $word_ids = ll_tools_offline_app_sanitize_word_ids([
                '10',
                11,
                0,
                -1,
                'bad',
                10,
                12,
                13,
            ]);

            $this->assertSame([10, 11, 12], $word_ids);
        } finally {
            remove_filter('ll_tools_offline_app_sync_word_ids_limit', $limit_filter);
        }
    }

    public function test_user_study_state_arrays_are_sanitized_deduped_and_capped(): void
    {
        $category_limit_filter = static function (): int {
            return 2;
        };
        $starred_limit_filter = static function (): int {
            return 3;
        };
        add_filter('ll_tools_user_study_category_ids_limit', $category_limit_filter);
        add_filter('ll_tools_user_study_starred_word_ids_limit', $starred_limit_filter);

        $user_id = self::factory()->user->create();
        $this->assertIsInt($user_id);

        try {
            $saved = ll_tools_save_user_study_state([
                'wordset_id' => 7,
                'category_ids' => ['21', 0, 22, 21, -5, 'bad', 23],
                'starred_word_ids' => [31, '32', 0, 31, -9, 'bad', 33, 34],
                'fast_transitions' => true,
            ], $user_id);

            $this->assertSame([21, 22], array_values(array_map('intval', (array) ($saved['category_ids'] ?? []))));
            $this->assertSame([31, 32, 33], array_values(array_map('intval', (array) ($saved['starred_word_ids'] ?? []))));

            $stored = ll_tools_get_user_study_state($user_id);
            $this->assertSame([21, 22], array_values(array_map('intval', (array) ($stored['category_ids'] ?? []))));
            $this->assertSame([31, 32, 33], array_values(array_map('intval', (array) ($stored['starred_word_ids'] ?? []))));
        } finally {
            remove_filter('ll_tools_user_study_category_ids_limit', $category_limit_filter);
            remove_filter('ll_tools_user_study_starred_word_ids_limit', $starred_limit_filter);
        }
    }

    public function test_user_study_goal_arrays_are_sanitized_deduped_and_capped(): void
    {
        $ignored_limit_filter = static function (): int {
            return 2;
        };
        $preferred_wordsets_limit_filter = static function (): int {
            return 1;
        };
        $placement_limit_filter = static function (): int {
            return 3;
        };

        add_filter('ll_tools_user_study_goal_ignored_category_ids_limit', $ignored_limit_filter);
        add_filter('ll_tools_user_study_goal_preferred_wordset_ids_limit', $preferred_wordsets_limit_filter);
        add_filter('ll_tools_user_study_goal_placement_known_category_ids_limit', $placement_limit_filter);

        $user_id = self::factory()->user->create();
        $this->assertIsInt($user_id);

        try {
            $goals = ll_tools_save_user_study_goals([
                'enabled_modes' => ['learning', 'practice'],
                'ignored_category_ids' => [101, '102', 0, 101, -4, 'bad', 103],
                'preferred_wordset_ids' => ['201', 202, 201],
                'placement_known_category_ids' => [301, 0, '302', 301, -9, 303, 304],
                'daily_new_word_target' => 4,
            ], $user_id);

            $this->assertSame([101, 102], array_values(array_map('intval', (array) ($goals['ignored_category_ids'] ?? []))));
            $this->assertSame([201], array_values(array_map('intval', (array) ($goals['preferred_wordset_ids'] ?? []))));
            $this->assertSame([301, 302, 303], array_values(array_map('intval', (array) ($goals['placement_known_category_ids'] ?? []))));

            $stored = ll_tools_get_user_study_goals($user_id);
            $this->assertSame([101, 102], array_values(array_map('intval', (array) ($stored['ignored_category_ids'] ?? []))));
            $this->assertSame([201], array_values(array_map('intval', (array) ($stored['preferred_wordset_ids'] ?? []))));
            $this->assertSame([301, 302, 303], array_values(array_map('intval', (array) ($stored['placement_known_category_ids'] ?? []))));
        } finally {
            remove_filter('ll_tools_user_study_goal_ignored_category_ids_limit', $ignored_limit_filter);
            remove_filter('ll_tools_user_study_goal_preferred_wordset_ids_limit', $preferred_wordsets_limit_filter);
            remove_filter('ll_tools_user_study_goal_placement_known_category_ids_limit', $placement_limit_filter);
        }
    }
}
