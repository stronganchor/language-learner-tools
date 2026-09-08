# Progress word selection

The Words tab on a wordset's Progress page has a native **Select** dropdown.
It stays available at portrait phone widths even when the Last column is hidden.

- **All** clears the search and word filters, then selects the current category
  scope. In normal word-progress mode this includes words beyond the loaded page.
- **Filtered** appears only while a search, summary pill, category filter, or
  column filter is active. It keeps those filters and selects every match.
- **Learned**, **In progress**, **New**, **Starred**, **Hard**, and **Older than
  30 days** replace the word filters/search with the named group and select it.
  The existing selection bar launches the group in the chosen study mode.
- Summary pills remain filter toggles; they do not automatically select words.
  The older-words pill uses purple and a clock icon.
- **Older than 30 days** uses the same last-seen rule as the Last column's
  **Older** option: a recorded last-seen time strictly more than 30 days ago.
  Never-seen, recent, and future-dated words are excluded.

## Contracts and owners

- `includes/pages/wordset-pages.php` owns initial controls and localized labels;
  `js/wordset-pages.js` owns options, filter state, selection, and launch fencing.
- `includes/user-progress.php` supplies `summary.older_words` in summary-only,
  paged, and selection-ID payloads. Count during existing aggregate passes; do
  not add another whole-wordset hydration or a per-pill request.
- A requested group selection waits for an authoritative response for that
  exact filter. Another filter change or a failed response cancels it.
- A complete unfiltered page can reuse explicit candidate IDs. A paged All
  selection uses `__all_words__` as its client selection identity and an empty
  server filter, retaining the existing `selection_ids_only` lookup and bounded
  launch plan. Do not implement All by loading every table page.
- Filtered gender-table selections retain the existing locally displayed
  gender-eligible selection behavior; named progress groups switch back to
  ordinary word progress, as summary pills do.

## Focused verification

`UserStudyAnalyticsTest` verifies older counts and equivalent Last/summary
filters without hydrating selection rows. `wordset-page-progress-loading.spec.js`
verifies complete selection scopes, both older-word study modes, empty/failing
filters, pending selection, and bounded launches. `wordset-progress-mobile-layout.spec.js`
checks the native control and table layout across phone widths and themes.
Both browser harnesses supply their own page and API responses and can run with
the installed Playwright CLI while Local HTTP is stopped. PHP integration tests
still require the isolated WordPress test database.
