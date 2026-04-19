<?php
declare(strict_types=1);

final class WordsetLanguageSettingsMigrationTest extends LL_Tools_TestCase
{
    protected function tearDown(): void
    {
        delete_option('ll_target_language');
        delete_option('ll_translation_language');
        delete_option('ll_enable_category_translation');
        delete_option('ll_category_translation_source');
        delete_option('ll_word_title_language_role');
        delete_option(LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION);

        parent::tearDown();
    }

    public function test_migration_backfills_missing_wordset_language_settings_without_overwriting_existing_values(): void
    {
        update_option('ll_target_language', 'TR', false);
        update_option('ll_translation_language', 'EN', false);
        update_option('ll_enable_category_translation', 1, false);
        update_option('ll_category_translation_source', 'translation', false);
        update_option('ll_word_title_language_role', 'translation', false);

        $blank_wordset = wp_insert_term('Migration Blank ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($blank_wordset);
        $blank_wordset_id = (int) ($blank_wordset['term_id'] ?? 0);
        $this->assertGreaterThan(0, $blank_wordset_id);

        $custom_wordset = wp_insert_term('Migration Custom ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($custom_wordset);
        $custom_wordset_id = (int) ($custom_wordset['term_id'] ?? 0);
        $this->assertGreaterThan(0, $custom_wordset_id);
        update_term_meta($custom_wordset_id, 'll_language', 'German');
        update_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, 'FR');
        update_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, '0');
        update_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, 'target');
        update_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, 'target');

        $result = ll_tools_migrate_legacy_language_settings_to_wordsets();

        $this->assertSame(2, (int) ($result['wordsets'] ?? 0));
        $this->assertSame('TR', (string) get_term_meta($blank_wordset_id, 'll_language', true));
        $this->assertSame('EN', (string) get_term_meta($blank_wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true));
        $this->assertSame('1', (string) get_term_meta($blank_wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, true));
        $this->assertSame('translation', (string) get_term_meta($blank_wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, true));
        $this->assertSame('translation', (string) get_term_meta($blank_wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, true));

        $this->assertSame('German', (string) get_term_meta($custom_wordset_id, 'll_language', true));
        $this->assertSame('FR', (string) get_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true));
        $this->assertSame('0', (string) get_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, true));
        $this->assertSame('target', (string) get_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, true));
        $this->assertSame('target', (string) get_term_meta($custom_wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, true));
    }

    public function test_translation_language_helper_respects_explicit_empty_wordset_meta(): void
    {
        update_option('ll_translation_language', 'EN', false);

        $wordset = wp_insert_term('Migration Empty ' . wp_generate_password(6, false), 'wordset');
        $this->assertIsArray($wordset);
        $wordset_id = (int) ($wordset['term_id'] ?? 0);
        $this->assertGreaterThan(0, $wordset_id);
        update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, '');

        $this->assertSame('', ll_tools_get_wordset_translation_language([$wordset_id]));
    }
}
