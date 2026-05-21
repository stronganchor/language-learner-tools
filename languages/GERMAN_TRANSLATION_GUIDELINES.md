# German Translation Review Guide

## Scope

- Edit only `languages/ll-tools-text-domain-de_DE.po`.
- Regenerate `languages/ll-tools-text-domain-de_DE.mo` and `languages/ll-tools-text-domain-de_DE.l10n.php` after every change.
- Use parser-backed checks instead of raw `msgstr ""` counts.

## Tone

- Use concise, natural German UI language.
- Prefer informal `du` when the UI directly addresses a learner.
- Admin labels can stay noun-based or neutral; avoid formal `Sie` unless the source clearly requires formal address.
- Use German sentence-style capitalization, not English title case.

## Canonical Glossary

| English | German standard | Notes |
| --- | --- | --- |
| word | `Vokabel` | Use `Wort` only when the literal language token matters. |
| words | `Vokabeln` | |
| word set / wordset | `Vokabelset` | Keep both English variants aligned. |
| word sets / wordsets | `Vokabelsets` | |
| category | `Kategorie` | Generic UI/admin copy. |
| word category | `Vokabelkategorie` | When the source explicitly means a category of words. |
| word image | `Vokabelbild` | Avoid `Wortbild`; that has a different German meaning. |
| word audio | `Vokabel-Audio` | Distinct from a recorded file/action. |
| audio recording / recording | `Audioaufnahme`, `Aufnahme` | Use for actual recorded audio. |
| recording type | `Aufnahmetyp` | Avoid `Aufzeichnungstyp`. |
| dictionary entry | `Wörterbucheintrag` | |
| quiz | `Quiz` | Avoid `Prüfung`. |
| flashcard | `Lernkarte` | |
| learner | `Lernende` | Avoid masculine-only `Lerner` and school-only `Schüler`. |
| vocab lesson | `Vokabellektion` | |
| word progress | `Vokabelfortschritt` | |
| word option rules | `Regeln für Antwortoptionen` | Avoid literal `Wortoptionsregeln`. |
| Editor Hub | `Editor-Hub` | Feature name. |
| Site Sync | `Site-Sync` | In prose, `Website-Synchronisierung` is acceptable. |
| Word Set Manager | `Vokabelset-Manager` | |

## Review Checklist

- Keep placeholders exactly intact: `%s`, `%1$d`, `%2$s`, etc.
- Keep HTML, shortcodes, slugs, URLs, file paths, and code identifiers intact.
- Keep product names unchanged: `WordPress`, `DeepL`, `AssemblyAI`, `LL Tools`, `Language Learner Tools`.
- Prefer readable hyphen compounds for product/admin terms: `Vokabelset-Manager`, `Vokabel-Audio`, `Site-Sync`.
- Check singular/plural: `Quiz/Quizze`, `Vokabelset/Vokabelsets`, `Wörterbucheintrag/Wörterbucheinträge`.

## Quick Checks

Run these searches before finishing a translation pass:

```bash
rg -n 'Wortset|Wortgruppe|Wortbild|Word Set|Wordset|Word Image|Word Audio|Karteikarte|Schüler|Lerner|Gerettet|Blockflöte|Vokabeln Vokabel|Sparen:|Speichern\.\.' languages/ll-tools-text-domain-de_DE.po
```

Manually review matches. Some hits may be source `msgid` lines, but target `msgstr` hits usually need cleanup.
