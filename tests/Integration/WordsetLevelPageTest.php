<?php
declare(strict_types=1);

final class WordsetLevelPageTest extends LL_Tools_TestCase
{
    public function test_level_interval_writes_are_private_scoped_and_exportable(): void
    {
        $learner = self::factory()->user->create_and_get(['role' => 'subscriber']);
        $other = self::factory()->user->create(['role' => 'subscriber']);
        $wordset = self::factory()->term->create(['taxonomy' => 'wordset']);
        $second = self::factory()->term->create(['taxonomy' => 'wordset']);
        $category = self::factory()->term->create(['taxonomy' => 'word-category']);
        ll_tools_set_category_wordset_owner($category, $wordset);
        wp_set_current_user($learner->ID);
        $levels = [1, 2, 3];
        $this->assertSame([1 => 30, 2 => 30, 3 => 30], ll_tools_wordset_level_intervals($learner->ID, $wordset, $levels));
        $token = ll_tools_wordset_page_store_category_search_payload([
            'user_id' => $learner->ID, 'wordset_id' => $wordset,
            'access_signature' => ll_tools_wordset_page_category_search_payload_access_signature($wordset, $learner->ID),
            'level_policy' => ll_tools_wordset_level_policy_signature($wordset),
            'level_scope' => [1 => [['id' => $category]], 3 => [['id' => $category]]],
        ]);
        $request = ['operation' => 'interval', 'wordset_id' => $wordset, 'token' => $token, 'days' => 21, 'level' => 3];
        $this->assertSame(['level' => 3, 'days' => 21], ll_tools_wordset_level_request($learner->ID, $request));
        $this->assertSame(['level' => 1, 'days' => 90], ll_tools_wordset_level_request($learner->ID, array_merge($request, ['days' => 90, 'level' => 1])));
        $this->assertSame([1 => 90, 2 => 30, 3 => 21], ll_tools_wordset_level_intervals($learner->ID, $wordset, $levels));
        $this->assertSame([3 => 30], ll_tools_wordset_level_intervals($learner->ID, $second, [3]));
        $this->assertSame([3 => 30], ll_tools_wordset_level_intervals($other, $wordset, [3]));
        $this->assertSame('ll_level_unknown', ll_tools_wordset_level_request($learner->ID, array_merge($request, ['level' => 2]))->get_error_code());
        $this->assertSame('ll_level_scope_changed', ll_tools_wordset_level_request($learner->ID, array_merge($request, ['wordset_id' => $second]))->get_error_code());
        foreach ([0, 366, '7.5'] as $invalid) {
            $this->assertSame('ll_level_interval_invalid', ll_tools_wordset_level_request($learner->ID, array_merge($request, ['days' => $invalid]))->get_error_code());
        }
        $this->assertContains(LL_TOOLS_LEVEL_REVIEW_INTERVALS_META, ll_tools_privacy_local_erasure_meta_keys());
        $export = ll_tools_privacy_export_study_settings($learner->user_email);
        $this->assertContains('ll-tools-level-intervals-' . $learner->ID, array_column($export['data'], 'item_id'));
        update_term_meta($wordset, 'll_wordset_category_ordering_mode', 'manual');
        $stale = ll_tools_wordset_level_request($learner->ID, $request);
        $this->assertSame('ll_level_scope_changed', $stale->get_error_code());
        $this->assertTrue($stale->get_error_data()['preserve_progress']);
        wp_set_current_user($other);
        $wrong_learner = ll_tools_wordset_level_request($other, $request);
        $this->assertSame('ll_level_scope_changed', $wrong_learner->get_error_code());
        $this->assertArrayNotHasKey('preserve_progress', $wrong_learner->get_error_data());
        $this->assertSame('ll_level_access', ll_tools_wordset_level_request($learner->ID, $request)->get_error_code());
        wp_set_current_user(0);
        $this->assertSame('ll_level_access', ll_tools_wordset_level_request(0, $request)->get_error_code());
    }

    public function test_guest_completion_invitation_uses_available_account_screen(): void
    {
        wp_set_current_user(0);
        $url = home_url('/intro/');
        update_option('ll_allow_learner_self_registration', 1);
        update_option('users_can_register', 0);
        $this->assertSame(ll_tools_get_frontend_auth_url($url, 'login'), ll_tools_guest_lesson_account_action($url)['url']);
        update_option('users_can_register', 1);
        $this->assertSame(ll_tools_get_frontend_auth_url($url, 'register'), ll_tools_guest_lesson_account_action($url)['url']);
        wp_set_current_user(self::factory()->user->create());
        $this->assertSame([], ll_tools_guest_lesson_account_action($url));
    }
}
