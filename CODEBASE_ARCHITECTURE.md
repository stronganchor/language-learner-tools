---
title: Language Learner Tools - Codebase Architecture (AI Guide)
entry_points:
  - language-learner-tools.php
  - includes/bootstrap.php
read_first:
  - language-learner-tools.php
  - includes/bootstrap.php
  - includes/template-loader.php
  - includes/assets.php
  - includes/pages/quiz-pages.php
  - includes/pages/embed-page.php
  - includes/pages/recording-page.php
  - includes/shortcodes/flashcard-widget.php
  - includes/shortcodes/audio-recording-shortcode.php
  - includes/user-study.php
  - includes/shortcodes/user-study-dashboard.php
  - includes/taxonomies/word-category-taxonomy.php
  - includes/taxonomies/wordset-taxonomy.php
  - includes/post-types/words-post-type.php
  - includes/post-types/word-audio-post-type.php
  - includes/admin/settings.php
  - includes/admin/audio-processor-admin.php
  - js/flashcard-widget/main.js
  - js/flashcard-widget/selection.js
  - js/flashcard-widget/modes/listening.js
---

# Overview (30-second tour)
- WordPress plugin for vocabulary-driven language learning.
- Custom post types for words, word images, and word audio recordings.
- Taxonomies for word categories, word sets, language, part of speech, and recording types.
- Flashcard quizzes with three modes (practice, learning, listening) and per-category prompt/option config.
- Auto quiz pages under `/quiz/<category>` plus embeddable pages under `/embed/<category>`.
- Audio workflow: recording interface, bulk uploader, processing/review, recording type management.
- Admin tools for bulk translation, bulk word import, export/import, and legacy cleanups.
- Template override system and GitHub update checker (main/dev branch).

# Entry points and runtime flow
- `language-learner-tools.php`
  - Defines `LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`, `LL_TOOLS_MAIN_FILE`, `LL_TOOLS_MIN_WORDS_PER_QUIZ`.
  - Registers GitHub update checker (branch from `ll_update_branch` option).
  - Activation adds `view_ll_tools`, seeds default wordset and recording page via transients.
  - Registers `/embed/<category>` rewrite + query var + template_include hook.
- `includes/bootstrap.php`
  - Loads all CPTs, taxonomies, admin tools, shortcodes, utilities, and vendor update checker.
- `includes/assets.php`
  - `ll_enqueue_asset_by_timestamp()` enqueues local JS/CSS with `filemtime` versioning.
  - Public enqueue pulls jQuery UI CSS (code.jquery.com) and canvas-confetti (cdn.jsdelivr).
  - Non-admin style lives in `css/non-admin-style.css`.
- `includes/pages/quiz-pages.php`
  - Creates `/quiz` parent + child pages per `word-category` (meta `_ll_tools_word_category_id`).
  - Syncs on category/content changes; daily and on file mtime change; manual cleanup in admin.
  - Uses `templates/quiz-page-template.php` and `js/quiz-pages.js`.
- `includes/pages/embed-page.php`
  - Minimal page for iframes; noindex; uses `[flashcard_widget]`.
  - Accepts `?wordset=<slug>` and `?mode=practice|learning|listening`.
  - Posts `ll-embed-ready` to parent when initialized.
- `includes/pages/recording-page.php`
  - Ensures a default recording page with `[audio_recording_interface]`.
  - Redirects `audio_recorder` users on login to recording page (or user override).
- `includes/lib/media-proxy.php`
  - Signed image proxy (`lltools-img`, `lltools-size`, `lltools-sig`) to hide filenames.

# Directory map (top level)
```
language-learner-tools.php    # Bootstrap, constants, updates, /embed rewrite
includes/
  assets.php                  # Versioned enqueue helper + public assets
  bootstrap.php               # Central includes
  template-loader.php         # Theme override resolver
  lib/
    ll-matching.php           # Audio <-> image matching heuristics
    media-proxy.php           # Signed image proxy for quizzes
  pages/
    quiz-pages.php            # Auto /quiz pages + sync + assets
    embed-page.php            # /embed/<category> template
    recording-page.php        # Recording page creation + login redirect
  post-types/
    words-post-type.php
    word-image-post-type.php
    word-audio-post-type.php
  taxonomies/
    word-category-taxonomy.php
    wordset-taxonomy.php
    language-taxonomy.php
    part-of-speech-taxonomy.php
    recording-type-taxonomy.php
  shortcodes/
    flashcard-widget.php
    quiz-pages-shortcodes.php
    word-grid-shortcode.php
    word-audio-shortcode.php
    audio-recording-shortcode.php
    image-copyright-grid-shortcode.php
    language-switcher-shortcode.php
    user-study-dashboard.php
  admin/
    settings.php
    audio-processor-admin.php
    audio-image-matcher.php
    missing-audio-admin-page.php
    recording-types-admin.php
    bulk-translation-admin.php
    bulk-word-import-admin.php
    export-import.php
    word-images-fixer.php
    manage-wordsets.php
    metabox-word-audio-parent.php
    uploads/
      audio-upload-form.php
      image-upload-form.php
    api/deepl-api.php
  user-study.php
  i18n/language-switcher.php
  user-roles/
    wordset-manager.php
    ll-tools-editor.php
    audio-recorder-role.php
js/
  flashcard-widget/           # Modular quiz system
  audio-processor.js
  audio-image-matcher.js
  audio-recorder.js
  quiz-pages.js
  user-study-dashboard.js
  word-audio.js
  manage-wordsets.js
  words-bulk-edit.js
  word-images-bulk-edit.js
css/
  language-learner-tools.css
  quiz-pages.css
  quiz-pages-style.css
  recording-interface.css
  audio-processor.css
  audio-image-matcher.css
  user-study-dashboard.css
  flashcard/
    base.css
    mode-practice.css
    mode-learning.css
    mode-listening.css
templates/
  flashcard-widget-template.php
  quiz-page-template.php
  audio-image-matcher-template.php
media/
  right-answer.mp3
  wrong-answer.mp3
  play-symbol.svg
  stop-symbol.svg
data/
  iso-languages/              # ISO 639 language tables
vendor/
  getid3/                     # audio metadata
  plugin-update-checker/      # GitHub update checker
```
Note: `includes/admin/migrate-word-audio.php` exists as a legacy migration tool but is not loaded by default.

# Core data model (canonical)
## Custom post types
- `words` (public, REST)
  - Key meta: `word_translation`, legacy `word_english_meaning`, legacy `word_audio_file`.
  - Other meta: `word_example_sentence`, `word_example_sentence_translation`, `similar_word_id`.
  - Publish guard: requires at least one published `word_audio` when category config needs audio.
    - Bypass with `_ll_skip_audio_requirement_once` or filter `ll_tools_skip_audio_requirement`.
- `word_images` (public, REST)
  - Featured image is the media asset.
  - Meta: `copyright_info`, plus translation fields used by grids.
- `word_audio` (admin-only UI, REST)
  - Child of `words` via `post_parent`.
  - Meta: `audio_file_path`, `recording_date`, `speaker_user_id` or `speaker_name`, `_ll_needs_audio_processing`.
  - Terms: `recording_type` (isolation, sentence, question, introduction, etc).

## Taxonomies
- `word-category` (hierarchical; attached to `words` and `word_images`)
  - Translation meta: `term_translation` when translation is enabled.
  - Quiz config meta: `ll_quiz_prompt_type` (audio|image), `ll_quiz_option_type` (image|text_translation|text_title|audio|text_audio).
  - Desired recording types: `ll_desired_recording_types` (list of slugs; sentinel `__none__` disables recording for the category).
  - Helpers: `ll_tools_get_category_display_name()`, `ll_tools_get_category_quiz_config()`, `ll_can_category_generate_quiz()`.
- `wordset` (flat; attached to `words`)
  - Meta: `ll_language`, `manager_user_id`.
  - Capabilities: `edit_wordsets` etc; non-admins see only managed wordsets.
  - Active wordset resolution: `ll_tools_get_active_wordset_id()`; default seeded on activation.
- `language` (attached to `words`, populated from `data/iso-languages` on first run).
- `part_of_speech` (attached to `words`).
- `recording_type` (attached to `word_audio`).

## Common meta and flags
- `_ll_tools_word_category_id` on auto-generated quiz pages.
- `_ll_picked_count`, `_ll_picked_last`, `_ll_autopicked_image_id` for image matching usage tracking.
- `_ll_needs_audio_processing` for unprocessed audio queue.

## User meta and per-user state
- User study state (from `includes/user-study.php`):
  - `ll_user_study_wordset`, `ll_user_study_categories`, `ll_user_study_starred`, `ll_user_star_mode`, `ll_user_fast_transitions`.
- Audio recorder config (from `includes/user-roles/audio-recorder-role.php`):
  - `ll_recording_config` (wordset, category, recording type filters, allow_new_words, auto_process_recordings).
  - `ll_recording_page_url` (custom redirect on login).

# Settings and options
Core settings live in `includes/admin/settings.php`:
- `ll_target_language`, `ll_translation_language`.
- `ll_enable_category_translation`, `ll_category_translation_source`.
- `ll_word_title_language_role` (target vs translation).
- `ll_max_options_override` (max multiple-choice options).
- `ll_flashcard_image_size` (small/medium/large).
- `ll_hide_recording_titles` (recording UI).
- `ll_quiz_font` and `ll_quiz_font_url` (font selection; fonts must already be enqueued by theme/plugin).
- `ll_update_branch` (main/dev) for GitHub update checker.

# Public UI surfaces and routes
## Shortcodes (user-facing)
- `[flashcard_widget]` (controller: `includes/shortcodes/flashcard-widget.php`)
  - Attributes: `category`, `mode`, `embed`, `quiz_mode` (practice|learning|listening), `wordset`, `wordset_fallback`.
- `[quiz_pages_grid]` and `[quiz_pages_dropdown]` (`includes/shortcodes/quiz-pages-shortcodes.php`).
- `[word_grid]` (`includes/shortcodes/word-grid-shortcode.php`).
- `[word_audio]` (`includes/shortcodes/word-audio-shortcode.php`, JS: `js/word-audio.js`).
- `[audio_recording_interface]` (`includes/shortcodes/audio-recording-shortcode.php`).
- `[audio_upload_form]` and `[image_upload_form]` (bulk upload helpers in `includes/admin/uploads/`).
- `[image_copyright_grid]` (`includes/shortcodes/image-copyright-grid-shortcode.php`).
- `[language_switcher]` (`includes/shortcodes/language-switcher-shortcode.php`).
- `[ll_user_study_dashboard]` (`includes/shortcodes/user-study-dashboard.php`).

## Routes
- `/quiz/<category>` auto pages (created/synced by `includes/pages/quiz-pages.php`).
  - Optional params: `?mode=practice|learning|listening`.
- `/embed/<category>` embed page (handled by `includes/pages/embed-page.php`).
  - Optional params: `?wordset=<slug>` and `?mode=practice|learning|listening`.

# Flashcard widget architecture
## PHP controller
- `includes/shortcodes/flashcard-widget.php` builds categories, initial words, and localizes JS data into `llToolsFlashcardsData`.
- Data includes category config, wordset scope, user study preferences, and mode UI labels/icons.

## JS module map (`js/flashcard-widget/`)
- `main.js` - orchestrates quiz lifecycle, mode switching, settings UI, and session guards.
- `state.js` - shared state container and constants.
- `selection.js` - category/word selection, prompt rendering, and star-weighted selection.
- `modes/practice.js`, `modes/learning.js`, `modes/listening.js` - mode-specific flows.
- `audio.js` - playback + `selectBestAudio()` priority logic.
- `loader.js` - preloading and cache management (wordset aware).
- `options.js` - option count calculation and layout constraints.
- `cards.js` - card rendering and font sizing.
- `dom.js` - DOM helpers and progress UI.
- `effects.js` - confetti and visual feedback.
- `results.js` - end-of-quiz UI.
- `audio-visualizer.js` - animated loading/listening visualizer.
- `mode-config.js` - merges default mode UI labels/icons with `llToolsFlashcardsData.modeUi`.
- `category-selection.js`, `util.js` - supporting UI/utility helpers.

## Mode behavior (high level)
- Practice mode: standard multiple-choice quiz with adaptive option count.
- Learning mode: guided introduction + mastery tracking.
  - Implementation: `js/flashcard-widget/modes/learning.js` + `selection.js`.
  - State highlights: `introducedWordIDs`, `wordIntroductionProgress`, `wordCorrectCounts`, `wrongAnswerQueue` (with `dueTurn`), `learningChoiceCount`, `learningCorrectStreak`.
  - Defaults: `MIN_CHOICE_COUNT` = 2, `MAX_CHOICE_COUNT` = 6, `MIN_CORRECT_COUNT` = 3.
  - Progress UI updated via `Dom.updateLearningProgress()`.
- Listening mode: audio-first playback with simplified UI and visualizer.
  - Implementation: `js/flashcard-widget/modes/listening.js` + `audio-visualizer.js`.
  - Uses study prefs (`llToolsStudyPrefs`) to honor star mode and fast transitions.

# Admin tools and workflows
## Core Tools menu pages (files)
- Audio Processor: `includes/admin/audio-processor-admin.php` + `js/audio-processor.js`.
- Audio Image Matcher: `includes/admin/audio-image-matcher.php`, `templates/audio-image-matcher-template.php`, `js/audio-image-matcher.js`.
- Missing Audio report: `includes/admin/missing-audio-admin-page.php`.
- Recording Types admin: `includes/admin/recording-types-admin.php`.
- Bulk Translations: `includes/admin/bulk-translation-admin.php` (DeepL + dictionary fallback).
- Bulk Word Import: `includes/admin/bulk-word-import-admin.php` (Turkish casing support).
- Export/Import: `includes/admin/export-import.php` (zip of categories + word_images + attachments).
- Fix Word Images (legacy): `includes/admin/word-images-fixer.php`.
- Languages admin: `includes/taxonomies/language-taxonomy.php`.
- Manage Word Sets page: `includes/admin/manage-wordsets.php` (front-end page with admin iframe).
- Word Audio Parent metabox: `includes/admin/metabox-word-audio-parent.php`.

## Audio workflow (end to end)
- Recording UI: `[audio_recording_interface]` uses MediaRecorder and category recording type targets.
- Bulk upload: `[audio_upload_form]` and `[image_upload_form]` allow admin uploads.
- Processing: Audio Processor runs in browser, uses `lamejs` from CDN for MP3 encoding.
- Storage: `word_audio` posts store `audio_file_path` and `recording_type` terms; parent word published only when audio exists.

# Template override system
- Resolver: `includes/template-loader.php`.
- Search order:
  1. Child theme: `wp-content/themes/<child>/ll-tools/<template>`
  2. Parent theme: `wp-content/themes/<parent>/ll-tools/<template>`
  3. Plugin fallback: `templates/<template>`

# External dependencies and assets
- CDNs: jQuery UI CSS (code.jquery.com), canvas-confetti (cdn.jsdelivr), lamejs (cdn.jsdelivr).
- DeepL API integration: `includes/admin/api/deepl-api.php`.
- getID3: used for audio validation in `includes/admin/uploads/audio-upload-form.php`.
- Media proxy may fetch remote image URLs via `wp_remote_get()` fallback.

# Contracts and invariants (do not break)
- Capabilities gate admin tools: use `view_ll_tools` or stricter.
- AJAX and POST handlers must verify nonces and capabilities.
- Slugs are public contracts: `words`, `word_images`, `word_audio`, `word-category`, `wordset`, `recording_type`.
- Auto quiz pages rely on `_ll_tools_word_category_id` meta and the `/quiz` parent page.
- Learning state is client-side only; do not persist it server-side.
- Word publish guard depends on `ll_tools_get_category_quiz_config()` and `ll_tools_quiz_requires_audio()`.
- Use `ll_enqueue_asset_by_timestamp()` and `LL_TOOLS_BASE_*` constants for paths/URLs.
- Template overrides must follow the resolver order in `includes/template-loader.php`.
- Flashcard options in practice/learning must never include a conflicting pair (same `option_blocked_ids` pair, same image identity, or linked `similar_word_id`).
- Learning-mode bootstrap should introduce a non-conflicting initial pair when possible so the first round remains distinguishable.
- Keep `ll_get_words_by_category()` payload fields stable (`image`, `similar_word_id`, `option_groups`, `option_blocked_ids`); option safety depends on them.
- Learning mode options are built from all introduced categories, so conflict filtering must be evaluated against all currently chosen options (not just the target).
- If conflict filtering leaves fewer cards than the desired option count, keep conflicts blocked (do not force-add conflicting cards).

# Common tasks (file pointers)
- Register/adjust CPTs or taxonomies: `includes/post-types/*.php`, `includes/taxonomies/*.php`.
- Update quiz mode logic: `js/flashcard-widget/modes/*.js`, `js/flashcard-widget/selection.js`.
- Modify quiz UI: `templates/flashcard-widget-template.php`, `css/flashcard/*.css`.
- Adjust auto quiz pages: `includes/pages/quiz-pages.php`, `templates/quiz-page-template.php`.
- Edit embed behavior: `includes/pages/embed-page.php`.
- Tune audio/image matching: `includes/lib/ll-matching.php`, `includes/admin/audio-image-matcher.php`.
- Adjust recording interface: `includes/shortcodes/audio-recording-shortcode.php`, `js/audio-recorder.js`, `css/recording-interface.css`.
- Change audio processing: `includes/admin/audio-processor-admin.php`, `js/audio-processor.js`, `css/audio-processor.css`.
- Modify user study dashboard: `includes/user-study.php`, `includes/shortcodes/user-study-dashboard.php`, `js/user-study-dashboard.js`.
- Update settings/options: `includes/admin/settings.php`.

# Search hints (ripgrep)
- `register_post_type( 'words'` / `register_post_type( 'word_audio'`.
- `register_taxonomy('word-category'` / `register_taxonomy('wordset'` / `register_taxonomy('recording_type'`.
- `_ll_tools_word_category_id` for auto quiz pages.
- `ll_tools_get_category_quiz_config` and `ll_can_category_generate_quiz` for quiz eligibility.
- `ll_tools_get_active_wordset_id` for default wordset logic.
- `ll_get_words_by_category` for quiz data payloads.
- `llToolsFlashcardsData` for front-end localization data.
- `audio_recording_interface` for recording UI.
- `ll_user_study_` for study preferences.
- `ll_tools_get_masked_image_url` for signed image proxy.
