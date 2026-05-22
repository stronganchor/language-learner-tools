<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/check-public-i18n.php';

final class PublicUiTranslationManifestTest extends LL_Tools_TestCase
{
    public function test_manifest_matches_current_public_pot_selection(): void
    {
        $root = $this->pluginRoot();
        $config = ll_tools_public_i18n_load_config($root);
        $pot_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['pot_file']);
        $manifest_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);

        $selected_entries = ll_tools_public_i18n_select_public_entries($pot_path, $config);
        $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
        $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        $comparison = ll_tools_public_i18n_compare_manifest_entries($manifest_entries, $selected_entries);

        $this->assertTrue(
            $comparison['ok'],
            sprintf(
                'Refresh the public UI manifest with `php scripts/check-public-i18n.php --update-manifest` (missing: %d, stale: %d, changed: %d).',
                count($comparison['missing_from_manifest']),
                count($comparison['stale_in_manifest']),
                count($comparison['changed_entries'])
            )
        );
        $this->assertSame(count($selected_entries), (int) ($manifest['entry_count'] ?? 0));
        $this->assertSame(count($selected_entries), count($manifest_entries));
        $this->assertGreaterThan(500, count($manifest_entries));
        $this->assertArrayHasKey('ru_RU', $config['tier2_locales']);
        $this->assertSame('planned', $config['tier2_locales']['ru_RU']['status']);
    }

    public function test_turkish_covers_every_public_manifest_entry(): void
    {
        $root = $this->pluginRoot();
        $config = ll_tools_public_i18n_load_config($root);
        $manifest_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);
        $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
        $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];

        $coverage = ll_tools_public_i18n_check_locale_coverage(
            'tr_TR',
            $manifest_entries,
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.po'
        );

        $this->assertTrue(
            $coverage['complete'],
            sprintf(
                'Turkish public UI coverage is incomplete (missing: %d, untranslated: %d).',
                (int) $coverage['missing'],
                (int) $coverage['untranslated']
            )
        );
    }

    public function test_active_tier2_locales_must_cover_every_public_manifest_entry(): void
    {
        $root = $this->pluginRoot();
        $config = ll_tools_public_i18n_load_config($root);
        $manifest_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);
        $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
        $manifest_entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];

        foreach ((array) ($config['tier2_locales'] ?? []) as $locale => $locale_config) {
            $status = is_array($locale_config) ? (string) ($locale_config['status'] ?? '') : '';
            if (!in_array($status, ['active', 'complete'], true)) {
                continue;
            }

            $po_path = $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.po';
            $coverage = ll_tools_public_i18n_check_locale_coverage((string) $locale, $manifest_entries, $po_path);

            $this->assertTrue(
                $coverage['complete'],
                sprintf(
                    '%s is marked %s but public UI coverage is incomplete (missing: %d, untranslated: %d).',
                    (string) $locale,
                    $status,
                    (int) $coverage['missing'],
                    (int) $coverage['untranslated']
                )
            );
        }

        $this->addToAssertionCount(1);
    }

    public function test_manifest_keeps_known_public_strings_and_excludes_manager_only_strings(): void
    {
        $root = $this->pluginRoot();
        $config = ll_tools_public_i18n_load_config($root);
        $manifest_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);
        $manifest = ll_tools_public_i18n_load_manifest($manifest_path);
        $msgids = array_map(
            static fn (array $entry): string => (string) ($entry['msgid'] ?? ''),
            is_array($manifest['entries'] ?? null) ? $manifest['entries'] : []
        );

        $this->assertContains('Sign in', $msgids);
        $this->assertContains('Search dictionary', $msgids);
        $this->assertContains('Star word', $msgids);
        $this->assertContains('Start recording', $msgids);

        $this->assertNotContains('Create manager account', $msgids);
        $this->assertNotContains('Internal review note', $msgids);
        $this->assertNotContains('Open in admin', $msgids);
        $this->assertNotContains('Add a media URL in the lesson editor to play this lesson here.', $msgids);
    }

    private function pluginRoot(): string
    {
        return rtrim(defined('LL_TOOLS_BASE_PATH') ? (string) LL_TOOLS_BASE_PATH : dirname(__DIR__, 2), "\\/");
    }
}
