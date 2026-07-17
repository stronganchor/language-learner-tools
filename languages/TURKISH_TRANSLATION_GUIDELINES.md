# Turkish Translation Review Guide

## Scope

- Edit only `languages/ll-tools-text-domain-tr_TR.po`.
- Regenerate `languages/ll-tools-text-domain-tr_TR.mo` and `languages/ll-tools-text-domain-tr_TR.l10n.php` after every change.
- Ignore `languages/*backup*.po~`; those are backups, not canonical sources.

## Tone

- Use second-person informal Turkish consistently.
- Prefer `sen`-style grammar in both commands and non-command sentences.
- Good: `giriş yap`, `yeniden dene`, `hesabın var mı?`, `şifreni mi unuttun?`, `iznin yok`, `giriş yapmış olman gerekir`.
- Avoid formal/plural address unless the English source explicitly addresses multiple people.
- Avoid: `giriş yapın`, `hesabınız var mı?`, `şifrenizi mi unuttunuz?`, `izniniz yok`.

## Canonical Glossary

| English | Turkish standard | Notes |
| --- | --- | --- |
| word | `kelime` | Prefer `kelime`, not `sözcük`, for plugin entities and core UI labels. |
| word set | `kelime seti` | Do not drift to `sözcük kümesi`. |
| word sets | `kelime setleri` | Keep singular/plural aligned with the source. |
| category | `kategori` | Use in generic UI/admin copy. |
| word category | `kelime kategorisi` | Use when the source explicitly says `word category`. |
| word image | `kelime görseli` | Do not use `kelime görüntüsü` for the CPT/entity name. |
| word images | `kelime görselleri` | Same rule as above. |
| word audio | `kelime ses kaydı` | Distinct from generic `audio recording`. |
| audio recording | `ses kaydı` | Use for actual recorded audio actions/items. |
| part of speech | `konuşma parçası` | Use this for the taxonomy/filter label; avoid drifting to `sözcük türü`. |
| recording type | `kayıt türü` | Keep consistent across taxonomy/admin labels. |
| dictionary entry | `sözlük girişi` | |
| slug | `kısa ad` | Use this for URL/import identifiers unless the string is explicitly about the raw technical field name. |
| quiz | `quiz` | Prefer `quiz` over `sınav` or `test`; it better conveys a lightweight knowledge check rather than a serious graded exam. |
| flashcard | `bilgi kartı` | Use only when the source really means `flashcard`, not `quiz`. |
| hard (difficult) | `zor` | Use for learning difficulty filters/status. Avoid `sert` unless the source means physically hard or strict. |
| learner | `öğrenci` | |
| recorder / audio recorder | `ses kaydedici` | Prefer this over shorter variants in role labels. |
| manager | `yönetici` | Avoid `müdür` for software/admin roles. |
| translation | `çeviri` | |
| vocab lesson | `kelime dersi` | |
| word progress | `kelime ilerlemesi` | |
| Editor Hub | `Editör Merkezi` | Treat as a user-facing feature name. |

## Review Checklist

- Keep placeholders exactly intact: `%s`, `%1$d`, `%2$s`, etc.
- Keep HTML, shortcodes, and path-like strings intact: `<strong>`, `[word_grid]`, `/quiz/<category>`.
- Keep external product names unchanged: `WordPress`, `Quizlet`, `DeepL`, `AssemblyAI`.
- Match source casing when it matters:
  - Title/label case: `Word Set` -> `Kelime Seti`
  - Sentence case: `word set` -> `kelime seti`
- Prefer natural Turkish over literal English calques, especially in admin help text.
- If a string mentions a canonical glossary term inside a longer sentence, still use the canonical term there.

## Quick Checks

Run these searches before finishing a translation pass:

```bash
rg -n 'hesabınız|şifreniz|izniniz|yapın|misiniz|musunuz|unuz|ünüz' languages/ll-tools-text-domain-tr_TR.po
rg -n 'sözcük kümes|[Ss]özcük [Tt]ür|kelime görünt|\bSınav\b|\bsınav\b|msgstr "Word Audio"|Flashcard Görüntü|Müdür|sümüklü|İmzalandı' languages/ll-tools-text-domain-tr_TR.po
```

Manually review matches. Some hits may be false positives, but these searches catch most tone/glossary regressions quickly.

## Rebuild Locale Files

From PowerShell in the plugin root, compile only reviewed translations. Keeping
blank entries in the PO is useful for review, but compiling those entries would
turn their English fallback text into empty UI copy.

```powershell
$CompileScriptPath = Join-Path $env:TEMP ('ll-tools-compile-tr-' + [guid]::NewGuid().ToString('N') + '.php')
$RuntimePoPath = Join-Path $env:TEMP ('ll-tools-runtime-tr-' + [guid]::NewGuid().ToString('N') + '.po')
$CompileScript = @'
<?php
$root = dirname(dirname(dirname(getcwd())));
$poPath = getenv('LL_TOOLS_RUNTIME_PO');
$moPath = getcwd() . '/languages/ll-tools-text-domain-tr_TR.mo';
$phpPath = getcwd() . '/languages/ll-tools-text-domain-tr_TR.l10n.php';

if (!is_string($poPath) || $poPath === '') {
    fwrite(STDERR, "Runtime PO path is missing\n");
    exit(1);
}

require $root . '/wp-includes/pomo/translations.php';
require $root . '/wp-includes/pomo/streams.php';
require $root . '/wp-includes/pomo/entry.php';
require $root . '/wp-includes/pomo/po.php';
require $root . '/wp-includes/pomo/mo.php';
require $root . '/wp-includes/pomo/plural-forms.php';
require $root . '/wp-includes/l10n/class-wp-translation-file.php';
require $root . '/wp-includes/l10n/class-wp-translation-file-mo.php';
require $root . '/wp-includes/l10n/class-wp-translation-file-php.php';

$po = new PO();
if (!$po->import_from_file($poPath)) {
    fwrite(STDERR, "PO import failed\n");
    exit(1);
}

$mo = new MO();
$mo->set_headers($po->headers);
foreach ($po->entries as $entry) {
    $translations = is_array($entry->translations) ? $entry->translations : [];
    if ($translations === [] || in_array('', $translations, true)) {
        continue;
    }
    $mo->add_entry($entry);
}

if (!$mo->export_to_file($moPath)) {
    fwrite(STDERR, "MO export failed\n");
    exit(1);
}

$php = WP_Translation_File::transform($moPath, 'php');
if ($php === false || file_put_contents($phpPath, $php) === false) {
    fwrite(STDERR, "PHP export failed\n");
    exit(1);
}
'@
[System.IO.File]::WriteAllText($CompileScriptPath, $CompileScript, [System.Text.UTF8Encoding]::new($false))
try {
    php scripts/filter-po-runtime-translations.php languages/ll-tools-text-domain-tr_TR.po $RuntimePoPath
    if ($LASTEXITCODE -ne 0) { throw 'Turkish runtime PO filter failed.' }
    $env:LL_TOOLS_RUNTIME_PO = $RuntimePoPath
    php $CompileScriptPath
    if ($LASTEXITCODE -ne 0) { throw 'Turkish locale rebuild failed.' }
} finally {
    Remove-Item Env:LL_TOOLS_RUNTIME_PO -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $RuntimePoPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $CompileScriptPath -Force -ErrorAction SilentlyContinue
}
```

For a full catalog refresh, run `scripts/update-i18n.sh` from Git Bash. It
regenerates the POT, merges the refreshed catalog, and compiles a temporary
runtime PO containing only complete, non-fuzzy translations. Review the catalog
diff before accepting broad WP-CLI reordering. Leave newly merged `msgstr`
values empty until a reviewer supplies confident Turkish; the runtime artifacts
will omit those entries so WordPress falls back to English.

## Optional Verification

After rebuilding, spot-check a few glossary-sensitive strings from the generated PHP locale:

```powershell
php -r "print_r(array_intersect_key((require getcwd() . '/languages/ll-tools-text-domain-tr_TR.l10n.php')['messages'], array_flip(['Word Set', 'Word Image', 'Word Audio', 'Quiz', 'Already have an account?'])));"
```
