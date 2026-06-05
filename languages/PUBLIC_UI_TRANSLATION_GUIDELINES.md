# Public UI Translation Guidelines

Use this guide when adding or polishing tier-2 public UI translations for
`ll-tools-text-domain`. It records the workflow and the English string traps
that repeatedly produced awkward or wrong translations during the Spanish,
French, Portuguese, Indonesian, Hindi, Korean, and Italian passes.

## Scope

Tier-2 public UI translations cover strings selected by
`tier2-public-ui-strings.json` and configured in
`tier2-public-ui-sources.php`. These are learner/visitor-facing strings, not the
full admin plugin surface.

Full core translations such as Turkish and German can have their own
language-specific guides. For tier-2 public UI work, this guide is the shared
baseline.

## Coverage Model

Tier-2 locale readiness is measured against the public UI manifest, not the
entire plugin source tree. A locale can pass with no missing public strings
while still leaving admin-only, manager-only, or development-only strings
untranslated. Use raw full-source missing counts only as discovery data, not as
a release blocker for a learner/visitor public locale.

## Required Workflow

1. Work from the manifest, not ad hoc file searches.
2. Edit the locale PO file under `languages/`.
3. Rebuild both compiled artifacts after every PO change:

```bash
wp i18n make-mo languages/ll-tools-text-domain-LOCALE.po languages
wp i18n make-php languages/ll-tools-text-domain-LOCALE.po languages
```

On the Windows/Local setup where `wp` is not on `PATH`, use the bundled WP-CLI
phar:

```powershell
php "C:\Users\messy\AppData\Local\Programs\Local\resources\extraResources\bin\wp-cli\wp-cli.phar" i18n make-mo languages/ll-tools-text-domain-LOCALE.po languages
php "C:\Users\messy\AppData\Local\Programs\Local\resources\extraResources\bin\wp-cli\wp-cli.phar" i18n make-php languages/ll-tools-text-domain-LOCALE.po languages
```

4. Run parser-backed validation:

```bash
php scripts/check-public-i18n.php --locale=LOCALE --fail-on-missing --details --json
php scripts/check-public-i18n.php --all-tier2 --json
bash tests/bin/run-tests.sh Integration/PublicUiTranslationManifestTest.php
```

Do not rely on raw `msgstr ""` counts. PO entries can be multiline, and the
checker understands the actual catalog structure.

## Technical Rules

- Preserve placeholders exactly: `%s`, `%d`, `%1$d`, `%2$s`, etc.
- Preserve HTML tags, URLs, shortcodes, slugs, and file paths exactly.
- Preserve product names unless the product itself has a local official name:
  `LL Tools`, `Language Learner Tools`, `WordPress`, `DeepL`, `AssemblyAI`.
- Keep UI labels short. Many strings appear inside game controls, cards,
  filters, table headers, and mobile buttons.
- Translate for the visible UI action, not the literal English grammar.
- For plural entries, update every required `msgstr[n]` slot for the locale.
- After machine translation, do a targeted human/UX pass before marking a
  locale active.

## Repeated String Traps

| English source or term | Intended sense | Avoid this failure |
| --- | --- | --- |
| `word` | A vocabulary item in the plugin. | Do not use a term that only means a written token if the locale has a better learner-vocabulary term. |
| `word set` / `wordset` | A collection of vocabulary items. | Keep both English spellings translated consistently. |
| `category` | A study category/taxonomy used to group words. | Do not translate as a grammatical category unless the surrounding string says so. |
| `lesson` | A learner-facing content/vocabulary lesson. | Avoid school-class wording when the string is a UI navigation label. |
| `study` | Learner study/practice. | Do not translate as academic research. This was a common Korean failure. |
| `learned` | A learner progress state: the user has learned/mastered the word. | Do not translate as "scholarly" or "educated". |
| `in progress` | A learner progress bucket. | Do not translate as an unfinished editorial draft. |
| `hard` | A difficult word in the learner's progress. | Do not use the physical/material sense of "hard". |
| `star`, `starred`, `unstar` | Favorite/bookmark a word. | Do not translate as a literal astronomical/decorative star unless that is idiomatic for favorites in the locale. |
| `gender` | Grammatical gender in noun practice. | Do not translate as biological sex/gender. Use grammatical gender where needed. |
| `isolation` | An isolated single-word recording/practice mode. | Do not translate as quarantine, solitude, or social isolation. |
| `speaking practice` | Pronunciation/oral practice against a target word/text. | Do not translate as conversation/dialogue practice. |
| `recorder account` | A person invited to record audio. | Do not translate as an audio recording device. |
| `recording` | An audio file/clip. | Keep distinct from the person who records. |
| `target` | The reference answer/text/audio used for scoring. | Do not translate as a goal/target audience. "Reference" is often better. |
| `close` in scoring | Nearly correct, close match. | Do not translate as shut/nearby/dismiss. |
| `prompt` | A game or speaking cue shown to the learner. | Do not translate as a command-line prompt. In some languages "cue" or "instruction" is more natural. |
| `cue` | A transcript/prompt segment. | Do not translate as a queue, billiards cue, or vague hint if the language has a better transcript segment term. |
| `sense` | A dictionary meaning/lexical sense. | Do not translate as physical senses like sight/touch. |
| `source witness` | A textual/source witness in textual evidence. | Do not translate as a human witness to an event. |
| `source` | Attribution/citation source. | Do not use developer-code "source" wording if the locale has a citation/source term. |
| `speaker` in credit grids | The voice/person heard in audio. | Do not translate as a conference presenter unless the context is clearly a talk. |
| `credit` | Media attribution/credit information. | Do not translate as financial credit. |
| `key` in tables | A legend/key explaining symbols. | Do not translate as a password or keyboard key. |
| `open` in actions | Open/view a page, lesson, progress panel, or settings. | Do not translate as public/open-state. |
| `clear` / `cleared` | Depends on context: clear a filter, clear a game item, or complete progress. | Do not default to delete/remove when the UI means completed. |
| `line up` | A card-ordering game. | Do not translate as a queue of people unless the locale idiom still works for ordering cards. |
| `unscramble` | Put letters back in order. | Do not translate "letters" as mail/post. |
| `stack` | A game pile/stack rising to the top. | Avoid overly formal mountain/top wording. |
| `tile` | A game board tile/card. | Do not use bathroom/construction tile terms if a game piece term is better. |
| `fire`, `blast`, `pop` | Game actions. | Keep playful and clear; avoid overly violent wording if the locale has a softer "hit/shoot/pop" verb. |
| `run` in games | A game attempt/session. | Do not translate as jogging/running. |
| `lives` | Game lives/remaining attempts. | Do not translate as life/living in a philosophical or biological sense. |
| `sign in` / `sign up` | Account authentication and registration. | Keep distinct; do not use the same word for both if the locale distinguishes login vs registration. |
| `password` | The account password field. | In locales where the English loanword is standard, keep it. Do not translate into "command word". |

## Locale Notes From Recent QA

- Spanish: use `referencia` for speaking targets, `palabra aislada` for
  isolation practice, `en curso` for in-progress words, and avoid literal
  `objetivo` where the UI means reference text/audio.
- French: use the configured plural rule `nplurals=2; plural=(n > 1);`. Prefer
  `entraînement oral` for speaking-practice limits/settings.
- Brazilian Portuguese: use the configured plural rule
  `nplurals=2; plural=(n > 1);`. Prefer `prática de pronúncia` and
  `palavras isoladas` for speaking/isolation practice.
- Indonesian: keep labels concise and natural; use reference/transcription
  wording instead of literal "target" when scoring speech.
- Hindi: keep favorite/star wording as `पसंदीदा`-style UI language; avoid
  literal star language where it reads decorative.
- Korean: avoid `연구` for learner study, avoid mail/post wording for letters,
  use `문법 성` for grammatical gender, and treat `prompt` as a learner problem
  or cue rather than a technical prompt.
- Italian: `Password` is usually the best account-field label; use
  `pratica di pronuncia`, `testo di riferimento`, `parole preferite`, and
  `genere grammaticale` for the recurring problem strings.

## QA Searches And Checks

Before finishing a public UI translation pass, scan for protected-token
artifacts and empty parsed translations:

```powershell
$code = @'
require_once 'scripts/check-public-i18n.php';
$config = require 'languages/tier2-public-ui-sources.php';
$locales = array_keys((array) ($config['tier2_locales'] ?? []));
foreach ($locales as $locale) {
    $path = 'languages/ll-tools-text-domain-' . $locale . '.po';
    if (!is_file($path)) {
        continue;
    }
    $entries = ll_tools_public_i18n_parse_po_file($path);
    $bad = [];
    foreach ($entries as $entry) {
        foreach ((array) ($entry['msgstr'] ?? []) as $msgstr) {
            if (preg_match('/&lt;llph|<llph|LLSPLIT|^\s*$/u', (string) $msgstr)) {
                $bad[] = (string) ($entry['msgid'] ?? '');
            }
        }
    }
    echo $locale . ' artifact_or_blank=' . count($bad) . PHP_EOL;
}
'@
php -r "$code"
```

Also scan the target locale manually for the known mistranslated concepts above.
The best QA pass is not broad retranslation; it is targeted cleanup of strings
where English has several senses and the wrong sense is costly in UI.

## When To Add More Notes

If a string is mistranslated in more than one language, add it to the table
above. If the issue is language-specific, add it to that locale's note or create
a locale-specific `*_TRANSLATION_GUIDELINES.md` file.
