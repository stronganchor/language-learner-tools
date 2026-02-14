<?php
declare(strict_types=1);

final class EditorHubTest extends LL_Tools_TestCase
{
    public function test_editor_hub_page_is_created_with_shortcode(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        delete_option('ll_default_editor_hub_page_id');

        ll_tools_ensure_editor_hub_page();

        $page_id = (int) get_option('ll_default_editor_hub_page_id');
        $this->assertGreaterThan(0, $page_id);
        $this->assertSame('publish', (string) get_post_status($page_id));

        $content = (string) get_post_field('post_content', $page_id);
        $this->assertStringContainsString('[editor_hub]', $content);
    }

    public function test_ll_tools_editor_login_redirects_to_editor_hub_page(): void
    {
        if (function_exists('ll_create_ll_tools_editor_role')) {
            ll_create_ll_tools_editor_role();
        }

        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Editor Hub',
            'post_content' => '[editor_hub]',
        ]);
        update_option('ll_default_editor_hub_page_id', $page_id);

        $editor_id = self::factory()->user->create(['role' => 'll_tools_editor']);
        $editor = get_user_by('id', $editor_id);
        $this->assertInstanceOf(WP_User::class, $editor);
        $editor->set_role('ll_tools_editor');

        $redirect = ll_tools_editor_hub_login_redirect(
            admin_url(),
            '',
            $editor
        );

        $this->assertSame(get_permalink($page_id), $redirect);
    }

    public function test_dataset_includes_word_with_missing_editable_fields(): void
    {
        $wordset_term = wp_insert_term('Editor Hub Wordset', 'wordset', ['slug' => 'editor-hub-wordset']);
        $this->assertFalse(is_wp_error($wordset_term));
        $this->assertIsArray($wordset_term);
        $wordset_id = (int) $wordset_term['term_id'];

        $category_term = wp_insert_term('Editor Hub Category', 'word-category', ['slug' => 'editor-hub-category']);
        $this->assertFalse(is_wp_error($category_term));
        $this->assertIsArray($category_term);
        $category_id = (int) $category_term['term_id'];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Alpha',
        ]);

        wp_set_post_terms($word_id, [$wordset_id], 'wordset', false);
        wp_set_post_terms($word_id, [$category_id], 'word-category', false);

        delete_post_meta($word_id, 'word_translation');
        delete_post_meta($word_id, 'll_word_usage_note');

        $dataset = ll_tools_editor_hub_get_dataset($wordset_id, 'editor-hub-category');

        $this->assertSame($wordset_id, (int) ($dataset['wordset_id'] ?? 0));
        $this->assertNotEmpty($dataset['categories']);
        $this->assertSame('editor-hub-category', (string) ($dataset['selected_category'] ?? ''));

        $items = is_array($dataset['items'] ?? null) ? $dataset['items'] : [];
        $this->assertCount(1, $items);

        $item = $items[0];
        $this->assertSame($word_id, (int) ($item['word_id'] ?? 0));
        $this->assertSame('editor-hub-category', (string) ($item['category']['slug'] ?? ''));
        $this->assertIsArray($item['image'] ?? null);
        $this->assertSame('', (string) ($item['image']['url'] ?? ''));

        $flags = is_array($item['missing_flags'] ?? null) ? $item['missing_flags'] : [];
        $this->assertTrue((bool) ($flags['word_translation'] ?? false));
        $this->assertTrue((bool) ($flags['word_note'] ?? false));
        $this->assertTrue((bool) ($flags['dictionary_entry'] ?? false));
        $this->assertTrue((bool) ($flags['part_of_speech'] ?? false));
        $this->assertFalse((bool) ($flags['grammatical_gender'] ?? true));

        $this->assertSame([], (array) ($item['recordings'] ?? []));
        $this->assertTrue((bool) ($item['has_missing'] ?? false));
    }

    public function test_admin_like_user_with_manage_options_can_access_editor_hub(): void
    {
        add_role('ll_temp_editor_hub_admin', 'LL Temp Editor Hub Admin', [
            'read' => true,
            'manage_options' => true,
        ]);

        $user_id = self::factory()->user->create(['role' => 'll_temp_editor_hub_admin']);
        wp_set_current_user($user_id);

        $this->assertTrue(ll_tools_editor_hub_user_can_access());
    }

    public function test_wordset_resolver_falls_back_to_non_empty_wordset(): void
    {
        delete_option('ll_default_wordset_id');

        $empty_term = wp_insert_term('Editor Hub Empty Set', 'wordset', ['slug' => 'editor-hub-empty']);
        $this->assertFalse(is_wp_error($empty_term));
        $this->assertIsArray($empty_term);
        $empty_wordset_id = (int) $empty_term['term_id'];

        $filled_term = wp_insert_term('Editor Hub Filled Set', 'wordset', ['slug' => 'editor-hub-filled']);
        $this->assertFalse(is_wp_error($filled_term));
        $this->assertIsArray($filled_term);
        $filled_wordset_id = (int) $filled_term['term_id'];

        $word_id = self::factory()->post->create([
            'post_type' => 'words',
            'post_status' => 'publish',
            'post_title' => 'Resolver Word',
        ]);
        wp_set_post_terms($word_id, [$filled_wordset_id], 'wordset', false);

        $resolved = ll_tools_editor_hub_resolve_wordset_id('');

        $this->assertGreaterThan(0, $resolved);
        $this->assertNotSame($empty_wordset_id, $resolved);
        $this->assertContains($word_id, ll_tools_editor_hub_get_word_ids_for_wordset($resolved));
    }
}
