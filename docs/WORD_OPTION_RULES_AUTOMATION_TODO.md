# Word Option Rules Automation TODO

Created 2026-04-25 after the Word Boat Biblical Greek import pass.

## Plugin-side fix added 2026-04-25

Live Word Boat bundle import automation completed successfully, and prompt-card
audio was updated, but the follow-up word-option-rule and advanced-settings
automation hung before saving word-option groups.

Observed caller context:

- Workspace script:
  `C:\Users\messy\OneDrive\Websites\wordboat\Biblical Greek\run_live_wordboat_biblical_greek_update.py`
- Import result note:
  `C:\Users\messy\OneDrive\Websites\wordboat\Biblical Greek\generated\awa_live_wordboat_update_2026-04-24\live_wordboat_biblical_greek_update_result_2026-04-24.json`
- The import job completed first; this is not an import failure.
- The stalled follow-up was trying to save staged `word_option_groups` and then
  refresh advanced settings/category ordering.

The word-option-rule part of this blocker now has a first-class automation
route:

- `POST /wp-json/ll-tools/v1/wordsets/{wordset}/word-option-rules`
- The route accepts `category`, `category_id`, or `category_slug`.
- Group payloads can use `word_ids`, `words`, `word_slugs`, `slugs`, or `ids`.
- Omitted `pairs` and `similar_image_overrides` preserve existing rules.
- `dry_run=true` resolves the category, group labels, word IDs, missing words,
  and validation errors without writing.
- Non-dry-run validation failures return structured JSON with `missing_words`
  and `errors` instead of hanging on wp-admin nonce/form replay.

Implemented in:

- `includes/lib/word-option-rules.php`
- `includes/api/automation-rest.php`
- `docs/REST_AUTOMATION.md`

## Remaining operational follow-up

The Word Boat live script still needs to be updated to call the REST route
instead of `ll_tools_save_word_option_rules_async`. The advanced-settings
refresh mentioned in the Word Boat note is a separate follow-up only if the
importer still needs to save category ordering or grammar settings after the
word-option groups are applied.

Minimum acceptance criteria status:

- Done: a temporary admin/manager automation user can update word-option groups
  for a wordset/category without loading wp-admin HTML.
- Done: the route accepts slugs or IDs, validates category/wordset ownership,
  and writes via `ll_tools_update_word_option_rules()`.
- Done: dry-run mode reports the resolved wordset, category, group labels, word
  IDs, and missing slugs.
- Done: failures return structured JSON with actionable errors.
- Ready for caller update: the Word Boat Biblical Greek follow-up groups
  documented in `BIBLICAL_GREEK_CORRECTION_IMPORT_PREP_2026-04-24.md` can be
  applied without browser automation by posting each manifest entry to the new
  REST route.
