<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/scripts/check-public-i18n.php';
require_once dirname(__DIR__, 2) . '/scripts/filter-po-runtime-translations.php';
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

    public function test_turkish_catalog_fully_translates_every_current_pot_entry(): void
    {
        $root = $this->pluginRoot();
        $pot_entries = ll_tools_public_i18n_parse_po_file(
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain.pot'
        );
        $po_entries = ll_tools_public_i18n_parse_po_file(
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.po'
        );
        $pot_by_key = ll_tools_public_i18n_entries_by_key($pot_entries);
        $po_by_key = ll_tools_public_i18n_entries_by_key($po_entries);
        $catalog_entries = ll_tools_public_i18n_catalog_entries_from_pot(
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain.pot'
        );
        $coverage = ll_tools_public_i18n_check_full_catalog_coverage(
            'tr_TR',
            $catalog_entries,
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.po'
        );
        $seen_keys = [];
        $duplicate_msgids = [];
        foreach ($po_entries as $entry) {
            if (($entry['msgid'] ?? '') === '') {
                continue;
            }

            $entry_key = ll_tools_public_i18n_entry_key($entry);
            if (isset($seen_keys[$entry_key])) {
                $duplicate_msgids[] = (string) ($entry['msgid'] ?? '');
                continue;
            }

            $seen_keys[$entry_key] = true;
        }
        $missing = array_values(array_map(
            static fn (array $entry): string => (string) ($entry['msgid'] ?? ''),
            array_diff_key($pot_by_key, $po_by_key)
        ));
        $stale = array_values(array_map(
            static fn (array $entry): string => (string) ($entry['msgid'] ?? ''),
            array_diff_key($po_by_key, $pot_by_key)
        ));
        $fuzzy = array_values(array_filter(
            $po_entries,
            static fn (array $entry): bool => in_array('fuzzy', (array) ($entry['flags'] ?? []), true)
        ));

        $this->assertSame(
            [],
            $missing,
            'Merge and translate every current POT entry in the Turkish PO.'
        );
        $this->assertSame([], $stale, 'Remove stale active Turkish entries after merging the current POT.');
        $this->assertSame(
            [],
            $duplicate_msgids,
            'Keep only one active Turkish catalog entry per context, msgid, and plural combination.'
        );
        $this->assertSame([], $fuzzy, 'Resolve or clear fuzzy Turkish catalog entries before rebuilding locale artifacts.');
        $this->assertTrue(
            $coverage['complete'],
            sprintf(
                'Turkish full-catalog coverage is incomplete (missing: %d, untranslated/invalid: %d, validation errors: %d).',
                (int) $coverage['missing'],
                (int) $coverage['untranslated'],
                (int) $coverage['validation_error_count']
            )
        );
    }

    public function test_translation_template_keeps_genc_palu_source_text_in_utf8(): void
    {
        $pot = file_get_contents(
            $this->pluginRoot() . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain.pot'
        );
        $this->assertIsString($pot);
        $this->assertStringContainsString('Genç-Palu', $pot);
        $this->assertStringNotContainsString('GenÃ§-Palu', $pot);
    }

    public function test_turkish_catalog_keeps_reviewed_word_set_translations(): void
    {
        $root = $this->pluginRoot();
        $entries = ll_tools_public_i18n_parse_po_file(
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.po'
        );
        $translations = [];
        foreach ($entries as $entry) {
            $msgid = (string) ($entry['msgid'] ?? '');
            if ($msgid !== '') {
                $translations[$msgid] = (string) (($entry['msgstr'][0] ?? ''));
            }
        }

        $expected = [
            'No word sets' => 'Kelime seti yok',
            'No word sets found yet.' => 'Henüz kelime seti bulunamadı.',
            'Defaults to the word set name.' => 'Varsayılan olarak kelime seti adını kullanır.',
        ];
        foreach ($expected as $msgid => $translation) {
            $this->assertSame($translation, $translations[$msgid] ?? null, $msgid);
        }

        $compiled = require $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.l10n.php';
        $compiled_messages = is_array($compiled['messages'] ?? null) ? $compiled['messages'] : [];
        foreach ($expected as $msgid => $translation) {
            $this->assertSame($translation, $compiled_messages[$msgid] ?? null, 'Compiled: ' . $msgid);
        }
    }

    public function test_turkish_catalog_avoids_known_machine_translation_glossary_regressions(): void
    {
        $entries = ll_tools_public_i18n_parse_po_file(
            $this->pluginRoot() . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.po'
        );

        foreach ($entries as $entry) {
            $msgid = (string) ($entry['msgid'] ?? '');
            if ($msgid === '') {
                continue;
            }
            $translation = implode("\n", array_map('strval', (array) ($entry['msgstr'] ?? [])));

            if (preg_match('/\bword ?sets?\b/i', $msgid)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(?:kelime|sözcük) kümes/iu',
                    $translation,
                    'Translate the word set entity as kelime seti: ' . $msgid
                );
            }
            if (preg_match('/\bpart of speech\b/i', $msgid)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bsözcük tür/iu',
                    $translation,
                    'Use konuşma parçası for the part-of-speech label: ' . $msgid
                );
            }
            if (preg_match('/\bquiz(?:zes)?\b/i', $msgid)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(?:sınav|test)(?:ler|lar|in|ın|un|ün|i|ı|u|ü)?\b/iu',
                    $translation,
                    'Keep the lightweight quiz feature named quiz in Turkish: ' . $msgid
                );
            }
            if (preg_match('/\bspeakers?\b/i', $msgid)) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bhoparlör/iu',
                    $translation,
                    'Speaker means konuşmacı here, not a loudspeaker: ' . $msgid
                );
            }
            if (stripos($msgid, 'resume export') !== false) {
                $this->assertStringNotContainsString(
                    'özgeçmiş',
                    mb_strtolower($translation, 'UTF-8'),
                    'Resume export means continue the export, not export a résumé.'
                );
            }
            if (stripos($msgid, 'you do not have permission') !== false) {
                $this->assertStringNotContainsString(
                    'izniniz',
                    mb_strtolower($translation, 'UTF-8'),
                    'Use informal second-person Turkish for permission errors: ' . $msgid
                );
            }
        }
    }

    public function test_compiled_turkish_catalog_matches_every_active_translation(): void
    {
        $root = $this->pluginRoot();
        $entries = ll_tools_public_i18n_parse_po_file(
            $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.po'
        );
        $compiled = require $root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.l10n.php';
        $compiled_messages = is_array($compiled['messages'] ?? null) ? $compiled['messages'] : [];
        $mo = new MO();
        $this->assertTrue(
            $mo->import_from_file($root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'll-tools-text-domain-tr_TR.mo'),
            'The compiled Turkish MO catalog must be readable.'
        );
        $expected_count = 0;
        foreach ($entries as $entry) {
            if (($entry['msgid'] ?? '') === '') {
                continue;
            }
            $expected_count++;
            $runtime_key = (string) ($entry['msgid'] ?? '');
            if (($entry['context'] ?? '') !== '') {
                $runtime_key = (string) $entry['context'] . "\x04" . $runtime_key;
            }
            $translations = array_values(array_map('strval', (array) ($entry['msgstr'] ?? [])));
            $this->assertSame(
                implode("\0", $translations),
                $compiled_messages[$runtime_key] ?? null,
                'The compiled Turkish PHP value must match its PO translation: ' . $runtime_key
            );
            $this->assertSame(
                $translations,
                isset($mo->entries[$runtime_key]) ? array_values($mo->entries[$runtime_key]->translations) : null,
                'The compiled Turkish MO value must match its PO translation: ' . $runtime_key
            );
        }
        $this->assertCount($expected_count, $compiled_messages, 'The Turkish PHP catalog must not contain stale messages.');
        $this->assertCount($expected_count, $mo->entries, 'The Turkish MO catalog must not contain stale messages.');
    }

    public function test_runtime_po_filter_keeps_only_complete_reviewed_translations(): void
    {
        $source_path = tempnam(sys_get_temp_dir(), 'll-runtime-po-source-');
        $destination_path = tempnam(sys_get_temp_dir(), 'll-runtime-po-output-');
        $this->assertIsString($source_path);
        $this->assertIsString($destination_path);

        $source = implode("\n", [
            'msgid ""',
            'msgstr ""',
            '"Language: tr_TR\\n"',
            '"Content-Type: text/plain; charset=UTF-8\\n"',
            '"Plural-Forms: nplurals=2; plural=n != 1;\\n"',
            '',
            '# Existing translator comment must remain.',
            '#: includes/public.php:10',
            'msgid "Translated"',
            'msgstr "Çevrildi"',
            '',
            'msgid "Multiline"',
            'msgstr ""',
            '"Çok "',
            '"satırlı"',
            '',
            'msgid "Zero"',
            'msgstr "0"',
            '',
            'msgid "Blank"',
            'msgstr ""',
            '',
            '#, php-format, fuzzy',
            'msgid "Fuzzy %s"',
            'msgstr "Belirsiz %s"',
            '',
            'msgid "Complete singular"',
            'msgid_plural "Complete plural"',
            'msgstr[0] "Tekil"',
            'msgstr[1] "Çoğul"',
            '',
            'msgid "Partial singular"',
            'msgid_plural "Partial plural"',
            'msgstr[0] "Tekil"',
            'msgstr[1] ""',
            '',
            'msgid "Missing slot singular"',
            'msgid_plural "Missing slot plural"',
            'msgstr[0] "Tekil"',
            '',
            '#~ msgid "Obsolete"',
            '#~ msgstr "Eski"',
            '',
        ]);
        file_put_contents($source_path, $source);

        try {
            ll_tools_filter_po_for_runtime($source_path, $destination_path);
            $written = file_get_contents($destination_path);
            $entries = ll_tools_public_i18n_parse_po_file($destination_path);
        } finally {
            @unlink($source_path);
            @unlink($destination_path);
        }

        $this->assertIsString($written);
        $this->assertStringContainsString('# Existing translator comment must remain.', $written);
        $by_msgid = [];
        foreach ($entries as $entry) {
            $by_msgid[(string) ($entry['msgid'] ?? '')] = $entry;
        }

        $this->assertSame('Çevrildi', $by_msgid['Translated']['msgstr'][0] ?? null);
        $this->assertSame('Çok satırlı', $by_msgid['Multiline']['msgstr'][0] ?? null);
        $this->assertSame('0', $by_msgid['Zero']['msgstr'][0] ?? null);
        $this->assertSame('Tekil', $by_msgid['Complete singular']['msgstr'][0] ?? null);
        $this->assertSame('Çoğul', $by_msgid['Complete singular']['msgstr'][1] ?? null);
        $this->assertArrayNotHasKey('Blank', $by_msgid);
        $this->assertArrayNotHasKey('Fuzzy %s', $by_msgid);
        $this->assertArrayNotHasKey('Partial singular', $by_msgid);
        $this->assertArrayNotHasKey('Missing slot singular', $by_msgid);
        $this->assertArrayNotHasKey('Obsolete', $by_msgid);
    }

    public function test_update_i18n_compiles_the_filtered_runtime_po(): void
    {
        $script = file_get_contents($this->pluginRoot() . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'update-i18n.sh');
        $this->assertIsString($script);
        $this->assertStringContainsString(
            'scripts/filter-po-runtime-translations.php "$TR_PO_FILE" "$RUNTIME_PO"',
            $script
        );
        $this->assertStringContainsString('i18n make-mo "$RUNTIME_PO"', $script);
        $this->assertStringContainsString('i18n make-php "$RUNTIME_PO"', $script);
        $this->assertStringNotContainsString('i18n make-mo "$TR_PO_FILE"', $script);
        $this->assertStringNotContainsString('i18n make-php "$TR_PO_FILE"', $script);
        $this->assertStringContainsString(
            'POT_ROOT_WINDOWS="$(wslpath -w "$ROOT_DIR"',
            $script
        );
        $this->assertStringContainsString(
            'POT_ROOT_WINDOWS="$(cygpath -w "$ROOT_DIR"',
            $script
        );
        $this->assertStringContainsString(
            'POT_ROOT_DIR="$ROOT_DIR" POT_ROOT_WINDOWS="$POT_ROOT_WINDOWS"',
            $script
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

    public function test_compiled_asset_status_rejects_php_messages_that_do_not_match_the_po(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'll-i18n-compiled-' . uniqid('', true);
        $languages = $root . DIRECTORY_SEPARATOR . 'languages';
        $this->assertTrue(mkdir($languages, 0777, true));
        $po_path = $languages . DIRECTORY_SEPARATOR . 'test-domain-xx_XX.po';
        $mo_path = $languages . DIRECTORY_SEPARATOR . 'test-domain-xx_XX.mo';
        $php_path = $languages . DIRECTORY_SEPARATOR . 'test-domain-xx_XX.l10n.php';

        file_put_contents($po_path, implode("\n", [
            'msgid ""',
            'msgstr ""',
            '"Language: xx_XX\\n"',
            '"Plural-Forms: nplurals=2; plural=n != 1;\\n"',
            '',
            'msgid "Current"',
            'msgstr "Güncel"',
            '',
        ]));
        file_put_contents($mo_path, 'present');
        file_put_contents($php_path, "<?php return ['messages' => ['Current' => 'Eski']];\n");

        try {
            $status = ll_tools_public_i18n_compiled_asset_status(
                $root,
                'xx_XX',
                ['text_domain' => 'test-domain'],
                $po_path
            );
        } finally {
            @unlink($php_path);
            @unlink($mo_path);
            @unlink($po_path);
            @rmdir($languages);
            @rmdir($root);
        }

        $this->assertFalse($status['complete']);
        $this->assertFalse($status['php_catalog_current']);
        $this->assertSame(1, $status['php_expected_messages']);
        $this->assertSame(1, $status['php_mismatched_messages']);
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

    public function test_deepl_full_catalog_fill_preserves_raw_entry_metadata_and_completed_values(): void
    {
        $po_path = tempnam(sys_get_temp_dir(), 'll-full-i18n-deepl-');
        $this->assertIsString($po_path);

        $source = implode("\n", [
            'msgid ""',
            'msgstr ""',
            '"Language: tr_TR\\n"',
            '"Plural-Forms: nplurals=2; plural=n != 1;\\n"',
            '',
            '# Existing translator comment remains byte-for-byte.',
            '#: includes/admin.php:10',
            'msgid "Already translated"',
            'msgstr "Zaten çevrildi"',
            '',
            '#. Extracted source guidance must remain.',
            '#: includes/admin.php:20',
            '#, php-format',
            'msgid "Blank %s"',
            'msgstr ""',
            '',
            '#: includes/admin.php:30',
            'msgid "%d singular"',
            'msgid_plural "%d plural"',
            'msgstr[0] "Mevcut %d"',
            'msgstr[1] ""',
            '',
        ]);
        file_put_contents($po_path, $source);

        $entries = ll_tools_public_i18n_parse_po_file($po_path);
        $by_msgid = [];
        foreach ($entries as $entry) {
            if ((string) ($entry['msgid'] ?? '') !== '') {
                $by_msgid[(string) $entry['msgid']] = $entry;
            }
        }
        $blank_key = ll_tools_public_i18n_entry_key($by_msgid['Blank %s']);
        $plural_key = ll_tools_public_i18n_entry_key($by_msgid['%d singular']);

        try {
            $replaced = ll_tools_public_i18n_deepl_replace_existing_translations(
                $po_path,
                [
                    $blank_key => ['Boş %s'],
                    $plural_key => ['Mevcut %d', 'Çoğul %d'],
                ],
                [$blank_key => true, $plural_key => true],
                2
            );
            $written = file_get_contents($po_path);
            $written_entries = ll_tools_public_i18n_parse_po_file($po_path);
            $hash_before_idempotent_fill = hash_file('sha256', $po_path);
            $second_replaced = ll_tools_public_i18n_deepl_replace_existing_translations(
                $po_path,
                [],
                [],
                2
            );
            $hash_after_idempotent_fill = hash_file('sha256', $po_path);
        } finally {
            @unlink($po_path);
        }

        $this->assertSame(2, $replaced);
        $this->assertIsString($written);
        $this->assertStringContainsString('# Existing translator comment remains byte-for-byte.', $written);
        $this->assertStringContainsString('#. Extracted source guidance must remain.', $written);
        $this->assertStringContainsString('#, php-format', $written);
        $written_by_msgid = [];
        foreach ($written_entries as $entry) {
            $written_by_msgid[(string) ($entry['msgid'] ?? '')] = $entry;
        }
        $this->assertSame('Zaten çevrildi', $written_by_msgid['Already translated']['msgstr'][0] ?? null);
        $this->assertSame('Boş %s', $written_by_msgid['Blank %s']['msgstr'][0] ?? null);
        $this->assertSame('Mevcut %d', $written_by_msgid['%d singular']['msgstr'][0] ?? null);
        $this->assertSame('Çoğul %d', $written_by_msgid['%d singular']['msgstr'][1] ?? null);
        $this->assertSame(0, $second_replaced);
        $this->assertSame($hash_before_idempotent_fill, $hash_after_idempotent_fill);
    }

    public function test_deepl_full_catalog_validation_rejects_structural_token_damage(): void
    {
        $entry = [
            'key' => ll_tools_public_i18n_entry_key([
                'context' => null,
                'msgid' => 'Open <strong>%s</strong> at https://example.com/file.zip on {page} with --resume-job',
                'msgid_plural' => null,
            ]),
            'context' => null,
            'msgid' => 'Open <strong>%s</strong> at https://example.com/file.zip on {page} with --resume-job',
            'msgid_plural' => null,
        ];

        $errors = ll_tools_public_i18n_deepl_validate_translation_map(
            [$entry],
            [$entry['key'] => ['Dosyayı aç']],
            [$entry['key'] => true],
            2
        );
        $types = array_values(array_unique(array_map(
            static fn (array $error): string => (string) ($error['type'] ?? ''),
            $errors
        )));

        $this->assertContains('printf_placeholders', $types);
        $this->assertContains('brace_placeholders', $types);
        $this->assertContains('cli_options', $types);
        $this->assertContains('urls', $types);
        $this->assertContains('html_tags', $types);
    }

    public function test_full_catalog_cli_argument_is_parser_backed(): void
    {
        $args = ll_tools_public_i18n_parse_cli_args([
            'check-public-i18n.php',
            '--full-catalog=tr_TR',
            '--fail-on-missing',
            '--json',
        ]);

        $this->assertSame(['tr_TR'], $args['catalog_locales']);
        $this->assertTrue($args['fail_on_missing']);
        $this->assertSame('json', $args['format']);
    }

    public function test_deepl_protected_markers_preserve_reordered_placeholders_and_product_names(): void
    {
        [$protected, $tokens] = ll_tools_public_i18n_deepl_protect_text(
            'Backed up %1$d of %2$d WordPress entries with LL Tools.'
        );

        $this->assertStringContainsString('__LLPH0__', $protected);
        $this->assertStringContainsString('__LLPH1__', $protected);
        $this->assertStringContainsString('__LLPH2__', $protected);
        $this->assertStringContainsString('__LLPH3__', $protected);

        $restored = ll_tools_public_i18n_deepl_restore_text(
            '__LLPH1__ içindeki __LLPH0__ girdi __LLPH2__ ile __LLPH3__ kullanılarak yedeklendi.',
            $tokens
        );
        $this->assertStringContainsString('%1$d', $restored);
        $this->assertStringContainsString('%2$d', $restored);
        $this->assertStringContainsString('WordPress', $restored);
        $this->assertStringContainsString('LL Tools', $restored);
    }

    public function test_deepl_protected_markers_preserve_brace_placeholders_and_cli_options(): void
    {
        [$protected, $tokens] = ll_tools_public_i18n_deepl_protect_text(
            'Page {page} of {pages}; rerun with --allow-large-option-rules.'
        );

        $this->assertCount(3, $tokens);
        $this->assertSame(
            'Sayfa {page} / {pages}; --allow-large-option-rules ile yeniden çalıştır.',
            ll_tools_public_i18n_deepl_restore_text(
                'Sayfa __LLPH0__ / __LLPH1__; __LLPH2__ ile yeniden çalıştır.',
                $tokens
            )
        );
    }

    public function test_full_catalog_coverage_rejects_stale_duplicate_and_fuzzy_entries(): void
    {
        $po_path = tempnam(sys_get_temp_dir(), 'll-full-i18n-check-');
        $this->assertIsString($po_path);
        $catalog_entry = [
            'key' => ll_tools_public_i18n_entry_key([
                'context' => null,
                'msgid' => 'Current',
                'msgid_plural' => null,
            ]),
            'context' => null,
            'msgid' => 'Current',
            'msgid_plural' => null,
        ];
        file_put_contents($po_path, implode("\n", [
            'msgid ""',
            'msgstr ""',
            '"Language: tr_TR\\n"',
            '"Plural-Forms: nplurals=2; plural=n != 1;\\n"',
            '',
            '#, fuzzy',
            'msgid "Current"',
            'msgstr "Güncel"',
            '',
            'msgid "Current"',
            'msgstr "Güncel"',
            '',
            'msgid "Stale"',
            'msgstr "Eski"',
            '',
        ]));

        try {
            $coverage = ll_tools_public_i18n_check_full_catalog_coverage('tr_TR', [$catalog_entry], $po_path);
        } finally {
            @unlink($po_path);
        }

        $this->assertFalse($coverage['complete']);
        $this->assertSame(1, $coverage['stale']);
        $this->assertSame(1, $coverage['duplicates']);
        $this->assertSame(1, $coverage['fuzzy']);
    }

    private function pluginRoot(): string
    {
        return rtrim(defined('LL_TOOLS_BASE_PATH') ? (string) LL_TOOLS_BASE_PATH : dirname(__DIR__, 2), "\\/");
    }
}
