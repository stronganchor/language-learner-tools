---
title: Language Learner Tools — Codebase Architecture (AI Guide)
entry_points:
  - language-learner-tools.php
read_first:
  - includes/bootstrap.php
  - includes/assets.php
  - includes/template-loader.php
  - includes/pages/quiz-pages.php
  - includes/pages/embed-page.php
  - includes/admin/audio-image-matcher.php
  - js/flashcard-widget/main.js
  - js/flashcard-widget/selection.js
---

# What this plugin does (30-second tour)
Vocabulary platform for WordPress:
- **CPTs** for words, images, and audio (with multiple recordings per word).
- **Taxonomies** for word categories, word sets, language, parts of speech, and recording types.
- **Quizzes** (flashcards) with **two modes**: Standard (adaptive difficulty) and Learning (guided word introduction with mastery tracking).
- **Auto-generated pages** (`/quiz/<category>`) and **embeddable pages** (`/embed/<category>`) with wordset filtering and mode selection.
- **Audio recording & processing workflow**: Recording interface → Audio Processor → Audio Review → Recording Types.
- **Admin tools** to record/process audio, match audio↔images, review content, manage word sets.
- **Roles/caps** to gate lightweight editors, word-set managers, and audio recorders.

# Directory map (high level)
```
language-learner-tools.php # Plugin bootstrap + constants + rewrite for /embed/<slug>
includes/
  assets.php               # Versioned enqueues (filemtime)
  bootstrap.php            # Central includes/wiring
  template-loader.php      # Theme override resolution for plugin templates
  admin/                   # Admin pages (matcher, processor, review, missing audio, settings, recording types)
    uploads/               # Audio/Image upload forms
    api/deepl-api.php      # DeepL integration
  i18n/language-switcher.php # Language UI helpers
  lib/ll-matching.php      # Audio↔image matching heuristics/bookkeeping
  pages/                   # Frontend pages (embed, quiz pages)
  post-types/              # words, word_images, word_audio CPTs
  shortcodes/              # All public shortcodes (flashcard, recording, grid, etc.)
  taxonomies/              # wordset, word-category, language, part_of_speech, recording_type
  user-roles/              # wordset_manager, ll_tools_editor, audio_recorder + menu trimming
js/                        # Admin + frontend JS
  flashcard-widget/        # Modular flashcard system (main, selection, state, learning mode, etc.)
css/                       # Styles for quiz pages, recording, matcher, review, etc.
templates/                 # Rendered by template-loader (quiz page, matcher, flashcards)
vendor/                    # Third-party code (getid3, plugin-update-checker)
data/                      # Files containing data (e.g. language codes)
```

# Core data model (canonical)
- **Custom Post Types**
  - `words` — main vocabulary entries (supports title, editor, thumbnail, custom fields).
  - `word_images` — image library for vocab (featured image, copyright meta).
  - `word_audio` — individual recordings; categorized by recording type. Multiple audio files can be attached to a single word.
- **Taxonomies**
  - `wordset` (flat) — groups of words (also used for permissions & UI scoping).
  - `word-category` — semantic categories ("people", animals, etc.).
  - `language` — language tagging.
  - `part_of_speech` — noun/verb/adj… (pre-seeded).
  - `recording_type` — e.g., isolation, sentence, question, introduction.
- **Common meta**
  - Image usage bookkeeping (e.g., `_ll_picked_count`, `_ll_picked_last`).
  - Word ↔ auto-picked image reference (e.g., `_ll_autopicked_image_id`).
  - Per-image `copyright_info` for the copyright grid.
  - Quiz page marker: `_ll_tools_word_category_id` on auto-generated pages.

# Contracts & invariants (don't break)
- **Capabilities gate admin tools.** Use `view_ll_tools` and/or taxonomy caps like `edit_wordsets`.
- **AJAX/POST must verify nonces and caps.** Return JSON responses.
- **Slugs are public contracts.** Changing CPT or taxonomy slugs is a migration (rewrite flushing + data updates).
- **Template resolution order** (see `includes/template-loader.php`):
  1) Child theme: `wp-content/themes/<child>/ll-tools/<template>`
  2) Parent theme: `wp-content/themes/<parent>/ll-tools/<template>`
  3) Plugin fallback: `plugins/language-learner-tools/templates/<template>`
- **Learning mode state is client-side only.** Server provides data; JS manages introduction, progress, and mastery tracking.

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
  - Matching helpers in `includes/lib/ll-matching.php` (scoring/normalization and "picked" bookkeeping).
- **Audio Processor:** `includes/admin/audio-processor-admin.php` + `js/audio-processor.js`
  - Batch-process uploaded audio files.
  - Auto-match to words by filename.
  - Assign recording types.
  - Create `word_audio` posts.
- **Audio Review:** `includes/admin/audio-review-page.php` + `js/audio-review.js`
  - Review audio quality and word associations.
  - Edit or remove incorrect matches.
- **Recording Types Admin:** `includes/admin/recording-types-admin.php`
  - Manage the `recording_type` taxonomy terms.
- **Missing Audio report:** `includes/admin/missing-audio-admin-page.php` (clearable cache table).
- **Word Set management:** `includes/admin/manage-wordsets.php` (auto-creates an iframe page into Word Set term admin).

# Roles & permissions
- **`wordset_manager`** (`includes/user-roles/wordset-manager.php`) — Can manage wordsets and basic content.
- **`ll_tools_editor`** (`includes/user-roles/ll-tools-editor.php`) — Can upload files, edit categories/wordsets, access LL Tools pages.
- **`audio_recorder`** (`includes/user-roles/audio-recorder-role.php`) — Dedicated role for audio contributors with limited admin access.
- **Administrator** is granted `view_ll_tools` on activation and stripped on deactivation.
- Admin menu trimmed for these roles to reduce clutter.

# Shortcodes (user-facing API)
Located in `includes/shortcodes/`:
- `flashcard-widget.php` — `[flashcard_widget]` (start popup, category selection, quiz UI; JS in `js/flashcard-widget/`). Supports `quiz_mode` (`standard` | `learning`) and `wordset` filtering.
- `quiz-pages-shortcodes.php` — `[quiz_pages_grid]` and `[quiz_pages_dropdown]` with wordset filtering and popup mode support.
- `word-audio-shortcode.php` — `[word_audio]` inline audio player markup; paired with `js/word-audio.js`.
- `word-grid-shortcode.php` — `[word_grid]` grid of word cards with wordset filtering.
- `audio-recording-shortcode.php` — `[audio_recording]` recording interface for contributors.
- `image-copyright-grid-shortcode.php` — `[image_copyright_grid posts_per_page="12"]` (styles in `css/image-copyright-style.css`).
- `language-switcher-shortcode.php` — `[language_switcher]` UI helpers.

# Flashcard widget (module map)
`js/flashcard-widget/` is split into small modules:
- **`main.js`** — Bootstrap, mode switching, quiz round orchestration, word introduction handling.
- **`selection.js`** — Word selection logic for both standard and learning modes. Contains `selectLearningModeWord()` with adaptive introduction and mastery tracking.
- **`state.js`** — Shared state management (categories, rounds, learning mode state, progress tracking).
- **`audio.js`** — Audio playback, feedback sounds, `selectBestAudio()` for choosing recording type by priority.
- **`loader.js`** — Resource preloading (audio/images) with chunked loading for performance.
- **`options.js`** — Dynamic option count calculation based on screen space and performance.
- **`cards.js`** — Card rendering (image/text) with font sizing logic.
- **`dom.js`** — DOM manipulation helpers, progress bar updates, mode switcher button.
- **`effects.js`** — Confetti and visual effects.
- **`results.js`** — Results screen rendering for both modes.
- **`category-selection.js`** — Category picker UI.
- **`util.js`** — Small utilities (randomSort, measureTextWidth, etc.).

# Learning Mode (detailed)
**Client-side implementation** in `js/flashcard-widget/selection.js` and `main.js`:

## State Management (`state.js`)
- `isLearningMode` — Boolean flag for mode.
- `introducedWordIDs` — Array of word IDs that have been introduced.
- `wordIntroductionProgress` — Object tracking introduction repetitions per word (target: 3).
- `wordCorrectCounts` — Object tracking correct answers per word (mastery target: 3).
- `wordsToIntroduce` — Queue of word IDs not yet introduced.
- `totalWordCount` — Fixed total (set once on init).
- `wrongAnswerQueue` — IDs of words answered incorrectly.
- `wordsAnsweredSinceLastIntro` — Set of IDs answered correctly since last introduction.
- `learningChoiceCount` — Current number of answer choices (2-5, adaptive).
- `learningCorrectStreak` — Consecutive correct answers (grows choice count).
- `MIN_CORRECT_COUNT` — Mastery threshold (3).
- `AUDIO_REPETITIONS` — Introduction audio plays (3).

## Selection Logic (`selectLearningModeWord()`)
1. **Introduction Bootstrap**: Introduce 2 words initially, then 1 at a time.
2. **Practice Prioritization**:
   - Pending wrongs first (with spacing to avoid immediate repeats).
   - Words not yet answered this cycle.
   - Words below mastery threshold (prioritize least-known).
   - Sprinkle in completed words for variety.
3. **Introduction Trigger**: New word introduced when all current words answered correctly this cycle and no pending wrongs.
4. **Finish Condition**: All words introduced AND all meet `MIN_CORRECT_COUNT`.

## Introduction Flow (`handleWordIntroduction()` in `main.js`)
1. Display 1-2 words (first intro shows 2, subsequent show 1).
2. Play audio 3 times per word with alternating recording types (intro/isolation pattern).
3. Disable user interaction during introduction.
4. Track introduction progress in `wordIntroductionProgress`.
5. Mark as introduced in `introducedWordIDs` after all repetitions.
6. Automatically transition to quiz after introduction.

## Adaptive Difficulty (`LearningMode.recordAnswerResult()`)
- **Correct Answer**: Increment `wordCorrectCounts`, add to `wordsAnsweredSinceLastIntro`, remove from wrong queue, increment streak.
  - Streak ≥10 → 5 choices
  - Streak ≥6 → 4 choices
  - Streak ≥3 → 3 choices
  - Default → 2 choices
- **Wrong Answer**: Add to `wrongAnswerQueue`, reset streak, reduce choice count by 1 (min 2).
- **Constraints**: Respect screen space (`canAddMoreCards()`), introduced word count, site-wide max, text mode limits (4 max).

## Progress Visualization
Dual-progress bar in `dom.js`:
- **Introduced Fill**: Tracks introduction progress (introduction repetitions / total × 3).
- **Completed Fill**: Tracks mastery progress (correct answers / total × 3).

# Quizzes & embeds
- **Embeds**: rewrite rule maps `/embed/<category>` → `includes/pages/embed-page.php`, which renders the in-iframe quiz view. Supports `?wordset=<slug>` and `?mode=<standard|learning>` params.
- **Standalone quiz pages**: rendered with `templates/quiz-page-template.php` and orchestrated by `includes/pages/quiz-pages.php` (CSS: `css/quiz-pages.css` + `css/quiz-pages-style.css`). Supports `?mode=<standard|learning>` param.
- **Wordset Filtering**: Both quiz pages and grids support wordset filtering via URL param or shortcode attribute.

# Audio Recording & Processing Workflow

## Recording (`includes/shortcodes/audio-recording-shortcode.php`)
- Browser-based recording interface via `[audio_recording]`.
- Uses MediaRecorder API.
- Preview playback before upload.
- Paired with `js/audio-recorder.js` and `css/recording-interface.css`.

## Processing (`includes/admin/audio-processor-admin.php`)
1. Admin uploads audio files in bulk.
2. Processor attempts to match filenames to word titles (fuzzy matching).
3. Admin assigns recording type (introduction, isolation, question, sentence).
4. Creates `word_audio` posts and attaches to matched words.
5. Styles: `css/audio-processor.css`, Logic: `js/audio-processor.js`.

## Review (`includes/admin/audio-review-page.php`)
1. Lists all audio-word associations.
2. Admin can listen, verify, edit, or remove associations.
3. Styles: `css/audio-review.css`, Logic: `js/audio-review.js`.

## Recording Types (`recording_type` taxonomy)
- **Introduction**: Full introduction audio (e.g., "Merhaba. That means hello in Turkish.")
- **Isolation**: Word spoken in isolation.
- **Question**: Word used in a question.
- **Sentence**: Word used in a sentence.
- Used by `FlashcardAudio.selectBestAudio()` to choose contextually appropriate audio.
- Learning mode prefers introduction audio during introduction phase, question audio during quiz.
- Standard mode prefers question/isolation audio.

# Matching & media notes
- **Matching heuristics** are centralized in `includes/lib/ll-matching.php` (string normalization, candidate ranking, and "picked" counters to shade used images in the UI).
- **Recording types** taxonomy (`recording_type`) classifies `word_audio` items (e.g., isolation / sentence / question / introduction).
- **Audio selection priority** (`FlashcardAudio.selectBestAudio()`): Accepts an array of preferred types (e.g., `['introduction', 'isolation']`) and returns the first match, falling back to any available audio.

# i18n and external services
- **DeepL** integration is isolated in `includes/admin/api/deepl-api.php`.
- **Text domain**: `ll-tools-text-domain`; language files under `/languages`.

# Styling & UX
- Public styles in `css/language-learner-tools.css` (word grids, audio snippets).
- **Quiz** layout/styles in `css/quiz-pages.css` and `css/quiz-pages-style.css` (spinner, responsive wrappers).
- **Admin matcher** in `css/audio-image-matcher.css`.
- **Recording UI** in `css/recording-interface.css`.
- **Review UI** in `css/audio-review.css`.
- **Audio Processor** in `css/audio-processor.css`.
- **Flashcard widget** in `css/flashcard-style.css` (includes mode switcher button, progress bars).

# Common tasks (with file pointers)
- **Register/adjust CPTs or taxonomies** → `includes/post-types/*.php`, `includes/taxonomies/*.php`
- **Add/trim capabilities for roles** → `includes/user-roles/*.php`
- **Change enqueue/versioning behavior** → `includes/assets.php`
- **Override a template in a theme** → copy from `/templates/*` to `wp-content/themes/<child>/ll-tools/*`
- **Tweak quiz page UX** → `templates/quiz-page-template.php`, `css/quiz-pages*.css`, `js/quiz-pages.js`
- **Tune audio/image matching** → `includes/lib/ll-matching.php`, `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`
- **Adjust learning mode logic** → `js/flashcard-widget/selection.js` (`selectLearningModeWord`, `LearningMode.recordAnswerResult`)
- **Modify word introduction flow** → `js/flashcard-widget/main.js` (`handleWordIntroduction`)
- **Add new recording type** → Add term to `recording_type` taxonomy, update `FlashcardAudio.selectBestAudio()` priority lists
- **Customize audio processing** → `includes/admin/audio-processor-admin.php`, `js/audio-processor.js`

# Guardrails for AI edits
- **Always** check capability before rendering admin pages or processing actions (`current_user_can('view_ll_tools')` or stricter).
- **Always** verify nonces on AJAX/forms.
- **Never** hardcode URLs—use `plugins_url()`, `admin_url()`, constants (`LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`).
- **Keep slugs stable** (`words`, `wordset`, `word-category`, etc.); if you must change, coordinate rewrite flush and data migration.
- **Learning mode is client-side** — Server provides data, JS manages state. Don't try to track learning state server-side.
- **Audio selection is contextual** — Use `selectBestAudio()` with appropriate priority arrays for different contexts (intro vs. quiz).

# Search hints (ripgrep/LSP)
- `"register_post_type( 'words'"` for the main CPT.
- `"register_post_type( 'word_audio'"` for the audio CPT.
- `"register_taxonomy('wordset'"` for Word Set caps/REST settings.
- `"register_taxonomy('recording_type'"` for the recording types taxonomy.
- `"add_submenu_page('tools.php'"` to find LL Tools admin entries.
- `"ll_tools_render_template("` to trace views.
- `"flashcard_widget"` / `"image_copyright_grid"` / `"audio_recording"` for shortcode entry points.
- `"selectLearningModeWord"` for learning mode word selection logic.
- `"handleWordIntroduction"` for word introduction flow.
- `"LearningMode.recordAnswerResult"` for adaptive difficulty logic.
- `"selectBestAudio"` for audio selection by recording type.
- `"ll_tools_get_active_wordset_id"` for wordset filtering logic.