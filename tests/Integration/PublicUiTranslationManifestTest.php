<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/check-public-i18n.php';
require_once dirname(__DIR__, 2) . '/scripts/translate-public-i18n-deepl.php';

final class PublicUiTranslationManifestTest extends LL_Tools_TestCase
{
    public function test_manifest_matches_current_public_pot_selection(): void
    {
        $root = $this->pluginRoot();
        $config = ll_tools_public_i18n_load_config($root);
        $pot_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['pot_file']);
        $manifest_path = $root . DIRECTORY_SEPARATOR . ll_tools_public_i18n_normalize_path((string) $config['manifest_file']);

        $selected_entries = ll_tools_public_i18n_select_public_entries($pot_path, $config, $root);
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
        $this->assertSame('active', $config['tier2_locales']['ru_RU']['status']);
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
            $mo_path = $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.mo';
            $l10n_path = $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-' . $locale . '.l10n.php';
            $coverage = ll_tools_public_i18n_check_locale_coverage((string) $locale, $manifest_entries, $po_path);

            $this->assertFileExists($po_path, sprintf('%s is marked %s but the PO file is missing.', (string) $locale, $status));
            $this->assertFileExists($mo_path, sprintf('%s is marked %s but the MO file is missing.', (string) $locale, $status));
            $this->assertFileExists($l10n_path, sprintf('%s is marked %s but the PHP translation file is missing.', (string) $locale, $status));
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
        $this->assertContains('Loading next recommendation...', $msgids);

        $this->assertNotContains('Create manager account', $msgids);
        $this->assertNotContains('Loading queue categories...', $msgids);
        $this->assertNotContains('Recording text', $msgids);
        $this->assertNotContains('Internal review note', $msgids);
        $this->assertNotContains('Open in admin', $msgids);
        $this->assertNotContains('Add a media URL in the lesson editor to play this lesson here.', $msgids);
    }

    public function test_source_policy_does_not_use_numeric_line_ranges(): void
    {
        $config = ll_tools_public_i18n_load_config($this->pluginRoot());
        foreach ((array) ($config['include_sources'] ?? []) as $rule) {
            $this->assertIsArray($rule);
            $this->assertArrayNotHasKey(
                'ranges',
                $rule,
                sprintf('Replace numeric ranges for %s with symbols or semantic regions.', (string) ($rule['path'] ?? 'unknown'))
            );
        }
    }

    public function test_tier2_po_generator_preserves_manifest_fields_and_plural_slots(): void
    {
        $entry = [
            'key' => ll_tools_public_i18n_entry_key([
                'context' => 'button',
                'msgid' => '%d apple',
                'msgid_plural' => '%d apples',
            ]),
            'context' => 'button',
            'msgid' => '%d apple',
            'msgid_plural' => '%d apples',
            'public_references' => ['includes/example.php:12'],
        ];
        $config = [
            'tier2_locales' => [
                'ru_RU' => [
                    'plural_forms' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
                ],
            ],
        ];

        $po = ll_tools_public_i18n_build_po_for_locale('ru_RU', [$entry], $config);

        $this->assertStringContainsString('"Language: ru_RU\n"', $po);
        $this->assertStringContainsString('Plural-Forms: nplurals=3;', $po);
        $this->assertStringContainsString('#. ll-tools-public-key: ' . $entry['key'], $po);
        $this->assertStringContainsString('#: includes/example.php:12', $po);
        $this->assertStringContainsString('msgctxt "button"', $po);
        $this->assertStringContainsString('msgid_plural "%d apples"', $po);
        $this->assertStringContainsString('msgstr[2] ""', $po);
    }

    public function test_locale_coverage_rejects_translations_that_drop_markup_or_placeholders(): void
    {
        $msgid = "Open <strong>%s</strong> at https://example.com [word_audio]\nNow";
        $entry = [
            'key' => ll_tools_public_i18n_entry_key([
                'context' => null,
                'msgid' => $msgid,
                'msgid_plural' => null,
            ]),
            'context' => '',
            'msgid' => $msgid,
            'msgid_plural' => null,
            'public_references' => ['includes/example.php:12'],
        ];

        $po_path = tempnam(sys_get_temp_dir(), 'll-public-i18n-');
        $this->assertIsString($po_path);
        file_put_contents(
            $po_path,
            implode("\n", [
                'msgid ""',
                'msgstr ""',
                '"Language: xx_XX\n"',
                '"Plural-Forms: nplurals=2; plural=(n != 1);\n"',
                '',
                ll_tools_public_i18n_po_line('msgid', $msgid),
                'msgstr "Open"',
                '',
            ])
        );

        try {
            $coverage = ll_tools_public_i18n_check_locale_coverage('xx_XX', [$entry], $po_path);
        } finally {
            @unlink($po_path);
        }

        $this->assertFalse($coverage['complete']);
        $this->assertSame(0, $coverage['covered']);
        $this->assertSame(1, $coverage['untranslated']);
        $types = array_values(array_unique(array_map(
            static fn (array $error): string => (string) $error['type'],
            (array) $coverage['validation_errors']
        )));

        $this->assertContains('printf_placeholders', $types);
        $this->assertContains('urls', $types);
        $this->assertContains('shortcodes', $types);
        $this->assertContains('html_tags', $types);
        $this->assertContains('newline_count', $types);
    }

    public function test_semantic_source_selectors_survive_inserted_lines_and_exclude_staff_regions(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'll-public-i18n-' . bin2hex(random_bytes(6));
        $source_path = $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'sample.php';
        $pot_path = $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'sample.pot';
        mkdir(dirname($source_path), 0777, true);
        mkdir(dirname($pot_path), 0777, true);

        $source = <<<'PHP'
<?php
function staff_queue(): void {
    __('Recorder queue', 'll-tools-text-domain');
}
function render_screen(): void {
    $staff_before = __('Manager before', 'll-tools-text-domain');
    $public_start = __('Public start', 'll-tools-text-domain');
    $public_end = __('Public end', 'll-tools-text-domain');
    $staff_after = __('Manager after', 'll-tools-text-domain');
}
function public_helper(): void {
    __('Learner helper', 'll-tools-text-domain');
}
PHP;
        $msgids = ['Recorder queue', 'Manager before', 'Public start', 'Public end', 'Manager after', 'Learner helper'];
        $write_fixture = static function (string $fixture_source) use ($source_path, $pot_path, $msgids): void {
            file_put_contents($source_path, $fixture_source);
            $source_lines = preg_split('/\R/', $fixture_source) ?: [];
            $chunks = ["msgid \"\"\nmsgstr \"\""];
            foreach ($msgids as $msgid) {
                $matching_lines = [];
                foreach ($source_lines as $index => $source_line) {
                    if (strpos($source_line, "'{$msgid}'") !== false) {
                        $matching_lines[] = $index + 1;
                    }
                }
                if (count($matching_lines) !== 1) {
                    throw new RuntimeException("Invalid semantic selector fixture for {$msgid}");
                }
                $chunks[] = '#: includes/sample.php:' . $matching_lines[0]
                    . "\n" . ll_tools_public_i18n_po_line('msgid', $msgid)
                    . "\nmsgstr \"\"";
            }
            file_put_contents($pot_path, implode("\n\n", $chunks) . "\n");
        };
        $config = [
            'include_sources' => [[
                'path' => 'includes/sample.php',
                'symbols' => ['public_helper'],
                'regions' => [[
                    'name' => 'learner-copy',
                    'symbol' => 'render_screen',
                    'start' => '$public_start =',
                    'end' => '$public_end =',
                ]],
            ]],
        ];

        try {
            $write_fixture($source);
            $before = ll_tools_public_i18n_select_public_entries($pot_path, $config, $root);

            $shifted_source = str_replace(
                "<?php\n",
                "<?php\n// New source lines above every selected function.\n\n\n",
                $source
            );
            $write_fixture($shifted_source);
            $after = ll_tools_public_i18n_select_public_entries($pot_path, $config, $root);
        } finally {
            @unlink($pot_path);
            @unlink($source_path);
            @rmdir(dirname($pot_path));
            @rmdir(dirname($source_path));
            @rmdir($root);
        }

        $selected_before = array_column($before, 'msgid');
        $selected_after = array_column($after, 'msgid');
        $this->assertEqualsCanonicalizing(['Learner helper', 'Public start', 'Public end'], $selected_before);
        $this->assertEqualsCanonicalizing($selected_before, $selected_after);
        $this->assertNotContains('Recorder queue', $selected_after);
        $this->assertNotContains('Manager before', $selected_after);
        $this->assertNotContains('Manager after', $selected_after);
    }

    public function test_deepl_catalog_append_preserves_existing_content(): void
    {
        $po_path = tempnam(sys_get_temp_dir(), 'll-public-i18n-deepl-');
        $this->assertIsString($po_path);

        $new_public_entry = [
            'key' => ll_tools_public_i18n_entry_key([
                'context' => null,
                'msgid' => 'New public label',
                'msgid_plural' => null,
            ]),
            'context' => '',
            'msgid' => 'New public label',
            'msgid_plural' => null,
            'public_references' => ['includes/public.php:30'],
        ];
        $new_public_translations = [
            $new_public_entry['key'] => ['New public translation'],
        ];
        file_put_contents(
            $po_path,
            implode("\n", [
                'msgid ""',
                'msgstr ""',
                '"Language: xx_XX\\n"',
                '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
                '',
                '# Existing translator comment must remain byte-for-byte.',
                '#: includes/public.php:10',
                'msgid "Public label"',
                'msgstr "Public translation"',
                '',
                '#: includes/admin.php:20',
                '#, php-format',
                'msgid "Supplemental %s"',
                'msgstr "Supplemental translation %s"',
                '',
            ])
        );

        try {
            ll_tools_public_i18n_deepl_append_missing_entries(
                $po_path,
                'xx_XX',
                [$new_public_entry],
                ['tier2_locales' => ['xx_XX' => []]],
                $new_public_translations
            );
            $written = file_get_contents($po_path);
            $written_entries = ll_tools_public_i18n_parse_po_file($po_path);
        } finally {
            @unlink($po_path);
        }

        $written_by_msgid = [];
        foreach ($written_entries as $entry) {
            $written_by_msgid[(string) ($entry['msgid'] ?? '')] = $entry;
        }
        $this->assertIsString($written);
        $this->assertStringContainsString('# Existing translator comment must remain byte-for-byte.', $written);
        $this->assertSame('Public translation', $written_by_msgid['Public label']['msgstr'][0]);
        $this->assertSame('Supplemental translation %s', $written_by_msgid['Supplemental %s']['msgstr'][0]);
        $this->assertContains('php-format', $written_by_msgid['Supplemental %s']['flags']);
        $this->assertSame('New public translation', $written_by_msgid['New public label']['msgstr'][0]);
    }

    private function pluginRoot(): string
    {
        return rtrim(defined('LL_TOOLS_BASE_PATH') ? (string) LL_TOOLS_BASE_PATH : dirname(__DIR__, 2), "\\/");
    }
}
