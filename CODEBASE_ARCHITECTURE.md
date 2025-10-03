---
title: Language Learner Tools — Codebase Architecture (AI Guide)
last_updated: 2025-10-03
entry_points:
  - language-learner-tools.php
read_first:
  - includes/bootstrap.php
  - includes/assets.php
  - includes/template-loader.php
  - includes/pages/quiz-pages.php
  - includes/pages/embed-page.php
  - includes/admin/audio-image-matcher.php
---

# What this plugin does (30-second tour)
Vocabulary platform for WordPress:
- **CPTs** for words, images (and audio).
- **Taxonomies** for word categories, word sets, language, parts of speech, and recording type.
- **Quizzes** (flashcards) with **auto-generated pages** (`/quiz/<category>`) and **embeddable pages** (`/embed/<category>`).
- **Admin tools** to record/process audio, match audio↔images, review content, manage word sets.
- **Roles/caps** to gate lightweight editors and word-set managers.

# Directory map (high level)
language-learner-tools.php # Plugin bootstrap + constants + rewrite for /embed/<slug>
includes/
assets.php # Versioned enqueues (filemtime)
bootstrap.php # Central includes/wiring
template-loader.php # Theme override resolution for plugin templates
admin/ # Admin pages (matcher, processor, review, missing audio, settings)
uploads/ # Audio/Image upload forms
api/deepl-api.php # DeepL integration
i18n/language-switcher.php # Language UI helpers
lib/ll-matching.php # Audio↔image matching heuristics/bookkeeping
pages/ # Frontend pages (embed, quiz pages)
post-types/ # words, word_images, word_audio CPTs
shortcodes/ # All public shortcodes
taxonomies/ # wordset, word-category, language, part_of_speech, recording_type
user-roles/ # wordset_manager, ll_tools_editor + menu trimming
js/ # Admin + frontend JS (flashcard widget in modules)
css/ # Styles for quiz pages, recording, matcher, review, etc.
templates/ # Rendered by template-loader (quiz page, matcher, flashcards)
vendor/ # Third-party code (getid3, plugin-update-checker)
data/ # Files containing data (e.g. language codes)

# Core data model (canonical)
- **Custom Post Types**
  - `words` — main vocabulary entries (supports title, editor, thumbnail, custom fields).
  - `word_images` — image library for vocab (featured image, copyright meta).
  - `word_audio` — individual recordings; categorized by recording type.
- **Taxonomies**
  - `wordset` (flat) — groups of words (also used for permissions & UI scoping).
  - `word-category` — semantic categories (“people”, animals, etc.).
  - `language` — language tagging.
  - `part_of_speech` — noun/verb/adj… (pre-seeded).
  - `recording_type` — e.g., isolation, sentence, question, introduction.
- **Common meta**
  - Image usage bookkeeping (e.g., `_ll_picked_count`, `_ll_picked_last`).
  - Word ↔ auto-picked image reference (e.g., `_ll_autopicked_image_id`).
  - Per-image `copyright_info` for the copyright grid.

# Contracts & invariants (don’t break)
- **Capabilities gate admin tools.** Use `view_ll_tools` and/or taxonomy caps like `edit_wordsets`.
- **AJAX/POST must verify nonces and caps.** Return JSON responses.
- **Slugs are public contracts.** Changing CPT or taxonomy slugs is a migration (rewrite flushing + data updates).
- **Template resolution order** (see `includes/template-loader.php`):
  1) Child theme: `wp-content/themes/<child>/ll-tools/<template>`
  2) Parent theme: `wp-content/themes/<parent>/ll-tools/<template>`
  3) Plugin fallback: `plugins/language-learner-tools/templates/<template>`

# Runtime entry points & routing
- **Plugin bootstrap:** `language-learner-tools.php`
  - Defines `LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`, `LL_TOOLS_MAIN_FILE`.
  - Registers rewrite for **`/embed/<category-slug>`** → `includes/pages/embed-page.php`.
- **Template rendering:** via `ll_tools_render_template()` / `ll_tools_capture_template()` with the search order above.
- **Quiz page UI:** `templates/quiz-page-template.php` (spinner until embed signals ready).
- **Asset versioning:** `ll_enqueue_asset_by_timestamp($path, $handle)` enqueues by `filemtime`.

# Admin tools (open these files first)
- **Audio ↔ Image Matcher:** `includes/admin/audio-image-matcher.php`
  - UI template in `templates/audio-image-matcher-template.php`
  - Frontend logic in `js/audio-image-matcher.js`
  - Matching helpers in `includes/lib/ll-matching.php` (scoring/normalization and “picked” bookkeeping).
- **Audio processing & review:** `includes/admin/audio-processor-admin.php`, `includes/admin/audio-review-page.php` (+ `css/audio-processor.css`, `css/audio-review.css`).
- **Missing Audio report:** `includes/admin/missing-audio-admin-page.php` (clearable cache table).
- **Word Set management:** `includes/admin/manage-wordsets.php` (auto-creates an iframe page into Word Set term admin).

# Roles & permissions
- **`wordset_manager`** and **`ll_tools_editor`** roles live in `includes/user-roles/`.
  - Both may gain `view_ll_tools` and taxonomy caps (`edit_wordsets`, `manage_wordsets`, `assign_wordsets`, etc.).
  - Admin menu trimmed for these roles to reduce clutter.
- **Administrator** is granted `view_ll_tools` on activation and stripped on deactivation.

# Shortcodes (user-facing API)
Located in `includes/shortcodes/`:
- `flashcard-widget.php` — `[flashcard_widget]` (start popup, category selection, quiz UI; JS in `js/flashcard-widget/`).
- `quiz-pages-shortcodes.php` — UX to list/launch quiz pages.
- `word-audio-shortcode.php` — inline audio player markup; paired with `js/word-audio.js`.
- `word-grid-shortcode.php` — grid of word cards.
- `audio-recording-shortcode.php` — recording interface for contributors.
- `image-copyright-grid-shortcode.php` — `[image_copyright_grid posts_per_page="12"]` (styles in `css/image-copyright-style.css`).

# Flashcard widget (module map)
`js/flashcard-widget/` is split into small modules:
- `main.js` (bootstrap), `loader.js` (data/init), `dom.js` (UI helpers), `cards.js` (card logic),
- `audio.js`, `effects.js`, `state.js`, `options.js`, `results.js`, `selection.js`, `category-selection.js`, `util.js`.

# Quizzes & embeds
- **Embeds**: rewrite rule maps `/embed/<category>` → `includes/pages/embed-page.php`, which renders the in-iframe quiz view.
- **Standalone quiz pages**: rendered with `templates/quiz-page-template.php` and orchestrated by `includes/pages/quiz-pages.php` (CSS: `css/quiz-pages.css` + `css/quiz-pages-style.css`).

# Matching & media notes
- **Matching heuristics** are centralized in `includes/lib/ll-matching.php` (string normalization, candidate ranking, and “picked” counters to shade used images in the UI).
- **Recording types** taxonomy (`recording_type`) classifies `word_audio` items (e.g., isolation / sentence / question / introduction).

# i18n and external services
- **DeepL** integration is isolated in `includes/admin/api/deepl-api.php`.
- **Text domain**: `ll-tools-text-domain`; language files under `/languages`.

# Styling & UX
- Public styles in `css/language-learner-tools.css` (word grids, audio snippets).
- **Quiz** layout/styles in `css/quiz-pages.css` and `css/quiz-pages-style.css` (spinner, responsive wrappers).
- **Admin matcher** in `css/audio-image-matcher.css`.
- **Recording UI** in `css/recording-interface.css`.
- **Review UI** in `css/audio-review.css`.

# Common tasks (with file pointers)
- **Register/adjust CPTs or taxonomies** → `includes/post-types/*.php`, `includes/taxonomies/*.php`
- **Add/trim capabilities for roles** → `includes/user-roles/*.php`
- **Change enqueue/versioning behavior** → `includes/assets.php`
- **Override a template in a theme** → copy from `/templates/*` to `wp-content/themes/<child>/ll-tools/*`
- **Tweak quiz page UX** → `templates/quiz-page-template.php`, `css/quiz-pages*.css`, `js/quiz-pages.js`
- **Tune audio/image matching** → `includes/lib/ll-matching.php`, `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`

# Guardrails for AI edits
- **Always** check capability before rendering admin pages or processing actions (`current_user_can('view_ll_tools')` or stricter).
- **Always** verify nonces on AJAX/forms.
- **Never** hardcode URLs—use `plugins_url()`, `admin_url()`, constants (`LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`).
- **Keep slugs stable** (`words`, `wordset`, `word-category`, etc.); if you must change, coordinate rewrite flush and data migration.

# Search hints (ripgrep/LSP)
- `"register_post_type( 'words'"` for the main CPT.
- `"register_taxonomy('wordset'"` for Word Set caps/REST settings.
- `"add_submenu_page('tools.php'"` to find LL Tools admin entries.
- `"ll_tools_render_template("` to trace views.
- `"flashcard_widget"` / `"image_copyright_grid"` for shortcode entry points.
