<?php
declare(strict_types=1);

final class WordsetAnswerOptionFontWeightTest extends LL_Tools_TestCase
{
    public function test_normalize_accepts_numeric_string_weights(): void
    {
        $this->assertSame('400', ll_tools_wordset_normalize_answer_option_font_weight('400'));
        $this->assertSame('500', ll_tools_wordset_normalize_answer_option_font_weight('500'));
        $this->assertSame('700', ll_tools_wordset_normalize_answer_option_font_weight('not-a-weight'));
    }

    public function test_save_persists_non_default_font_weight(): void
    {
        $term = wp_insert_term('Weight Test ' . wp_generate_password(8, false, false), 'wordset');
        $this->assertIsArray($term);
        $this->assertFalse(is_wp_error($term));
        $term_id = (int) ($term['term_id'] ?? 0);
        $this->assertGreaterThan(0, $term_id);

        $admin_role = get_role('administrator');
        $this->assertNotNull($admin_role);
        $admin_role->add_cap('edit_wordsets');

        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $original_post = $_POST;
        $_POST = [
            'll_wordset_answer_option_text_font_weight' => '400',
            'll_wordset_meta_nonce' => wp_create_nonce('ll_wordset_meta'),
        ];
        ll_save_wordset_language($term_id);
        $_POST = $original_post;

        $this->assertSame('400', (string) get_term_meta($term_id, 'll_wordset_answer_option_text_font_weight_v2', true));
        $config = ll_tools_wordset_get_answer_option_text_style_config($term_id);
        $this->assertSame('400', (string) ($config['fontWeight'] ?? ''));
    }
}
