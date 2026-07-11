<?php
declare(strict_types=1);

final class WordsetManagerCoreAccessTest extends LL_Tools_TestCase
{
    public function test_native_term_meta_cap_checks_enforce_wordset_ownership(): void
    {
        ll_create_wordset_manager_role();
        ll_ensure_wordset_manager_has_view_ll_tools_cap();

        $manager_one_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $manager_two_id = self::factory()->user->create(['role' => 'wordset_manager']);
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        $wordset_one = wp_insert_term('Core Access One ' . wp_generate_password(5, false), 'wordset');
        $wordset_two = wp_insert_term('Core Access Two ' . wp_generate_password(5, false), 'wordset');
        $this->assertIsArray($wordset_one);
        $this->assertIsArray($wordset_two);
        $wordset_one_id = (int) ($wordset_one['term_id'] ?? 0);
        $wordset_two_id = (int) ($wordset_two['term_id'] ?? 0);

        ll_tools_set_wordset_manager_user_ids($wordset_one_id, [$manager_one_id], $manager_one_id);
        ll_tools_set_wordset_manager_user_ids($wordset_two_id, [$manager_two_id], $manager_two_id);

        wp_set_current_user($manager_one_id);
        $this->assertTrue(current_user_can('edit_wordsets'));
        $this->assertTrue(current_user_can('edit_term', $wordset_one_id));
        $this->assertTrue(current_user_can('delete_term', $wordset_one_id));
        $this->assertFalse(current_user_can('edit_term', $wordset_two_id));
        $this->assertFalse(current_user_can('delete_term', $wordset_two_id));

        wp_set_current_user($admin_id);
        $this->assertTrue(current_user_can('edit_term', $wordset_one_id));
        $this->assertTrue(current_user_can('edit_term', $wordset_two_id));
        $this->assertTrue(current_user_can('delete_term', $wordset_two_id));
    }
}
