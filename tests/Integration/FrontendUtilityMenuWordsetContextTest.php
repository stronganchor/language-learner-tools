<?php
declare(strict_types=1);

final class FrontendUtilityMenuWordsetContextTest extends LL_Tools_TestCase
{
    public function test_wordset_pages_hide_redundant_managed_wordset_buttons(): void
    {
        if (function_exists('ll_create_wordset_manager_role')) {
            ll_create_wordset_manager_role();
        }
        if (function_exists('ll_ensure_wordset_manager_has_view_ll_tools_cap')) {
            ll_ensure_wordset_manager_has_view_ll_tools_cap();
        }

        $manager_id = self::factory()->user->create([
            'role' => 'wordset_manager',
        ]);
        $wordset_id = $this->ensureWordset('Menu Context Wordset', 'menu-context-wordset');

        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $wordset = get_term($wordset_id, 'wordset');
        $this->assertInstanceOf(WP_Term::class, $wordset);

        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'wordset_settings',
            'wordset' => $wordset,
        ]);

        $this->assertStringContainsString('ll-wordset-utility-bar--context-wordset_settings', $markup);
        $this->assertStringNotContainsString('Manage Menu Context Wordset', $markup);
        $this->assertStringNotContainsString('>Menu Context Wordset<', $markup);
    }

    public function test_non_wordset_pages_still_show_managed_wordset_shortcuts(): void
    {
        if (function_exists('ll_create_wordset_manager_role')) {
            ll_create_wordset_manager_role();
        }
        if (function_exists('ll_ensure_wordset_manager_has_view_ll_tools_cap')) {
            ll_ensure_wordset_manager_has_view_ll_tools_cap();
        }

        $manager_id = self::factory()->user->create([
            'role' => 'wordset_manager',
        ]);
        $wordset_id = $this->ensureWordset('Editor Hub Shortcut Wordset', 'editor-hub-shortcut-wordset');

        update_term_meta($wordset_id, 'manager_user_id', $manager_id);
        wp_set_current_user($manager_id);

        $markup = ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'editor_hub',
        ]);

        $this->assertStringContainsString('Editor Hub Shortcut Wordset', $markup);
        $this->assertStringContainsString('Manage Editor Hub Shortcut Wordset', $markup);
    }

    private function ensureWordset(string $name, string $slug): int
    {
        $existing = get_term_by('slug', $slug, 'wordset');
        if ($existing instanceof WP_Term) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, 'wordset', ['slug' => $slug]);
        $this->assertFalse(is_wp_error($created));
        $this->assertIsArray($created);

        return (int) $created['term_id'];
    }
}
