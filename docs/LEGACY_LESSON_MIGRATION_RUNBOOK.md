# Legacy Lesson Migration Runbook

Use this runbook for the bounded `wp ll-tools legacy-lessons-migrate`
migration. The command is dry-run by default and must be run in this order:

1. `lessons`
2. `relations`
3. `completions`

Do the complete rehearsal on a current production clone before changing the
live site. Keep a database/files backup, the legacy plugin, the original posts,
and the rollback path until every deactivation gate below passes.

## 1. Freeze the exact scope

Confirm the target wordset before using it:

```bash
wp term get wordset turkish --by=slug --fields=term_id,slug,name
```

The TurkishTextbook audit found lesson posts in the union of WordPress category
IDs `2,3,4,10,30,32`. Reconfirm those IDs on the site before applying:

```bash
wp term list category --include=2,3,4,10,30,32 \
  --fields=term_id,slug,name,count --format=table
```

`--categories` is an OR scope over exact category IDs. Prefer IDs over names or
slugs. After confirming the exception below, archive the exact ordered
source-post IDs before the first write:

```bash
PREFIX="$(wp db prefix)"
wp db query "
  SELECT DISTINCT p.ID
  FROM ${PREFIX}posts p
  INNER JOIN ${PREFIX}term_relationships tr ON tr.object_id = p.ID
  INNER JOIN ${PREFIX}term_taxonomy tt
    ON tt.term_taxonomy_id = tr.term_taxonomy_id
  WHERE p.post_type = 'post'
    AND p.post_status = 'publish'
    AND tt.taxonomy = 'category'
    AND tt.term_id IN (2,3,4,10,30,32)
    AND p.ID NOT IN (3797)
  ORDER BY p.ID ASC
" --skip-column-names > legacy-lesson-source-ids.txt
```

Review and retain that file with the migration logs. For the most reproducible
clone and live applies, replace the category/exclusion arguments in the
examples below with:

```bash
--source-ids="$(paste -sd, legacy-lesson-source-ids.txt)"
```

A nonempty explicit source list takes precedence over `--categories`; the two
scopes are not intersected.

Keep intentionally editorial posts out of the lesson conversion with
`--exclude-source-ids`. TurkishTextbook post `3797`, **Most Common Words**, is
the current explicit exclusion:

```text
wordset: turkish
source categories: 2,3,4,10,30,32
excluded source IDs: 3797
initial target status: draft
initial show-in-mix: 0
```

Reconfirm post `3797` and the category inventory at runtime; do not treat this
checked-in snapshot as proof that live IDs have not changed.

## 2. Rehearse and migrate lesson records

Run every page first without `--apply`. Keep the scope, status, mix flag, and
limit identical, replacing `--after` with each response's `next_cursor` until
`has_more` is false:

```bash
wp ll-tools legacy-lessons-migrate turkish \
  --phase=lessons \
  --categories=2,3,4,10,30,32 \
  --exclude-source-ids=3797 \
  --status=draft \
  --show-in-mix=0 \
  --after=0 \
  --limit=100 \
  --format=json
```

Review `processed`, `created`, `updated`, `unchanged`, `failed_source_ids`,
`errors`, `next_cursor`, and `has_more` on every dry-run page. Then repeat the
same cursor sequence with `--apply`.

An applied page with errors exits nonzero after printing its summary. Do not
advance past it. Fix the cause and retry the reported IDs explicitly:

```bash
wp ll-tools legacy-lessons-migrate turkish \
  --phase=lessons \
  --source-ids=12,34 \
  --status=draft \
  --show-in-mix=0 \
  --apply \
  --format=json
```

Archive each summary. The lessons phase must finish across the complete frozen
scope before relations begin.

The lessons and relations phases are source-authoritative replays: they overwrite
the migrated title, excerpt, content, mapped metadata, and prerequisites. Freeze
editorial changes on canonical target lessons until the final replay and
promotion are complete. Once canonical lessons become the editing source, do not
rerun either phase without an explicit reconciliation plan for target-side edits.

## 3. Rehearse and migrate relations

Use the same frozen source scope and cursor loop:

```bash
wp ll-tools legacy-lessons-migrate turkish \
  --phase=relations \
  --categories=2,3,4,10,30,32 \
  --exclude-source-ids=3797 \
  --after=0 \
  --limit=100 \
  --format=json
```

Inspect `resolved_dependencies`, `unresolved_dependencies`, `rewritten_links`,
`failed_source_ids`, and `errors`, then repeat with `--apply`. Retry failed
relation rows with `--phase=relations --source-ids=<reported-ids> --apply`
before advancing.

Do not accept missing lesson mappings, graph validation failures, or silently
discarded prerequisites. Every unresolved dependency must either be repaired
and replayed or recorded as an intentional exception.

## 4. Rehearse and migrate completions

Completions merge both legacy Simple Favorites data and
`tt_completed_lessons` into canonical LL Tools completion state; they do not
replace already-canonical completion IDs.

Dry-run user-ID pages from `--after=0`, following only the returned
`next_cursor`. Review `source_associations`, `mapped_associations`,
`unmapped_associations`, `unmapped_source_ids`, `changed_users`, and `errors`.

The first applied page must use `--after=0` and no caller-created run ID:

```bash
wp ll-tools legacy-lessons-migrate turkish \
  --phase=completions \
  --after=0 \
  --limit=100 \
  --apply \
  --format=json
```

Save its `audit_run_id`. Every continuation or replay must pass that exact ID
and the exact cursor being continued:

```bash
wp ll-tools legacy-lessons-migrate turkish \
  --phase=completions \
  --after=<next_cursor> \
  --run-id=<audit_run_id> \
  --limit=100 \
  --apply \
  --format=json
```

An absent or mismatched run ID on a continuation fails closed. Do not skip a
cursor. If a page reports an error, fix it and replay the same `--after` value
with the same run ID; replay replaces that page's audit contribution instead
of double-counting it.

Read back the durable aggregate after the tail page:

```bash
wp option get ll_tools_legacy_lesson_completion_audit --format=json
```

The audit must have the expected `wordset_id` and `audit_run_id`,
`completed=true`, no errors, and no unexplained unmapped source IDs.

## 5. Readback and promotion gates

Do not publish migrated lessons until all of these are true:

- The exact source inventory minus explicit exclusions has one and only one
  `_ll_tools_legacy_lesson_source_post_id` mapping in the target wordset.
- A full lessons dry-run now reports only unchanged rows.
- Titles, content, excerpts, authors, levels/menu order, source URLs,
  source-category IDs, concepts, article kind, wordset, status, and mix flags
  read back as intended.
- A full relations dry-run reports no further changes. Unresolved dependencies
  are zero or match a signed-off exception list, and the graph is cycle-free.
- Internal lesson links point to migrated targets; links to intentionally
  excluded editorial pages still point to their retained canonical URLs.
- The completion audit passes, and representative users retain both old and
  newly mapped completion records.
- The Grammar, Comprehension, Culture, Language + Culture, and Vocabulary index
  pages render in the clone for logged-out and logged-in users.
- Signup/login links, completion controls, prerequisite/dependent displays,
  `[color1]`/`[color2]`/`[color3]`, and canonical `[ll_mark]` output render
  without raw shortcodes.

Promotion is an explicit editorial choice. Re-run the lessons phase over the
same frozen IDs with `--status=publish`. Keep `--show-in-mix=0` when the
category/index pages remain the primary navigation, or use
`--show-in-mix=1` only when the LL Tools mixed wordset lesson grid should also
show these articles. Re-run the lessons and relations audit gates afterward.

## 6. Routes, shims, and cache cutover

The migration does not create source-URL redirects. Choose and test one of
these strategies before unpublishing an original post:

- **Compatibility bridge:** leave original lesson posts and index pages
  published. After the old plugin is disabled, LL Tools temporarily owns
  `[display_prereq_tree]`, `[custom_header]`, `[custom_footer]`,
  `[regex_linker]`, and `[signup_link]` when no other plugin registered them.
- **Canonical cutover:** publish the migrated `/lesson/...` targets, replace
  index content with `[ll_content_lesson_index]`, install an exact old-to-new
  301 map, test every source route, and only then retire the original posts.

The bridge is the safer first production cutover; the exact 301 strategy is the
cleaner final state. Do not run both for one source URL.

After changing plugins, shortcodes, redirects, or publication state:

```bash
wp rewrite flush --hard
wp cache flush
```

Also purge the site's page cache and CDN/edge cache for every lesson index,
old source URL, new target URL, and login/signup route. Verify as an anonymous
request after the purge, not only in an authenticated browser.

## 7. Old-plugin deactivation gate

Keep the old lessons/prerequisites plugin installed and active if any prior
gate is incomplete. Deactivate it on the clone first, then require:

- All three migration phases and the completion audit pass.
- Every required legacy shortcode is either removed from content or visibly
  handled by an LL Tools compatibility shim.
- No old-plugin-only debug shortcode, global style dequeue, route, or data read
  remains required.
- Indexes, lesson pages, prerequisite paths, completion toggles, auth links,
  colors, internal links, redirects, and anonymous responses pass smoke tests.
- Page/object/CDN caches have been purged and the same checks pass cold.
- Reactivating the old plugin and reverting redirects/index content remains a
  tested rollback until the observation window closes.

Only then repeat the guarded cutover on production.

## 8. Most Common Words decision

Keep post `3797` as the editorial/SEO/download page for now. Its large table,
PDF/XLSX downloads, and existing URL are useful, but its current order is
alphabetical rather than an authoritative frequency rank. Do not write that
order into `_ll_tools_word_rank` or present it as a ranked LL Tools list.

Repair it separately:

1. Normalize Turkish/Unicode spellings before exact matching.
2. Resolve duplicate word groups and the table/category membership differences.
3. Choose and document the authority for conflicting glosses.
4. Repair unresolved word/audio rows and regenerate the downloads from the
   reviewed source.
5. Only after acquiring a defensible rank source, import bounded rank rows and
   consider replacing the table body with
   `[ll_ranked_word_list category="most-common-words" wordset="turkish" per_page="50"]`.

The retained page must pass its own audio, download, mobile-table, and link
checks, but it should not block deactivation of the old prerequisite plugin
once it no longer depends on that plugin.
