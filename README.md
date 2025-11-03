# Language Learner Tools

A WordPress toolkit for building vocabulary-driven language learning sites. It provides custom post types and taxonomies, flashcard quizzes (with image + audio), auto-generated quiz pages, embeddable quiz pages, vocabulary grids, audio players, bulk uploaders, DeepL-assisted translations, a template override system, and helper roles.

## Key Features

- **Post types & taxonomies**
  - `words`, `word_images`, and `word_audio` post types.
  - Taxonomies: `word-category`, `wordset`, `language`, `part-of-speech`, `recording_type`.

- **Flashcard quiz modes**
  - **Practice Mode**: Traditional quiz with adaptive difficulty based on performance.
  - **Learning Mode**: Guided learning with word introduction, audio repetition, and progress tracking. Words are introduced gradually, and the system ensures mastery before moving forward.

- **Flashcard quiz shortcode**
  - `[flashcard_widget]` renders an interactive quiz. Attributes:
    - `category` (slug or CSV), `mode` (`random` | `image` | `text`), `embed` (`true|false`), `quiz_mode` (`practice` | `learning`), `wordset` (filter by wordset).

- **Auto quiz pages & embed pages**
  - Auto-generated quiz pages under `/quiz/<category>`.
  - Embeddable pages under `/embed/<category>` render a minimal page that includes `[flashcard_widget embed="true"]`.
  - Both support wordset filtering and mode selection via URL parameters.

- **Audio recording & processing**
  - **Audio Recorder Role**: Dedicated user role for contributors to record audio.
  - **Recording Interface**: `[audio_recording]` shortcode provides a browser-based recording interface.
  - **Audio Processor**: Admin tool to batch-process uploaded audio files and attach them to words.
  - **Audio Review**: Admin page to review and manage audio quality.
  - **Recording Types**: Categorize audio as introduction, isolation, question, or sentence for contextual playback.

- **Other shortcodes**
  - `[word_grid]` – grid of words with audio/text toggles, supports wordset filtering.
  - `[word_audio]` – simple audio + (optional) text/translation for a single word.
  - `[image_copyright_grid posts_per_page="12"]` – paginated grid of `word_images`.
  - Quiz listings: `[quiz_pages_grid]` and `[quiz_pages_dropdown]`, both support wordset filtering and popup mode.
  - `[audio_recording]` – browser-based audio recording interface.

- **Admin UX**
  - Bulk **audio** and **image** uploaders that can create/update posts and try to match media to posts by name.
  - **Audio Processor**: Batch-process uploaded audio files and attach them to words.
  - **Audio Review**: Review and manage audio quality and associations.
  - **Audio-Image Matcher**: Match audio files to images with scoring and usage tracking.
  - "Manage Word Sets" admin surface.
  - Settings page (translations, DeepL API key, image size, font, option caps, etc.).

- **DeepL integration**
  - Optional: store a DeepL API key and enable category term translations in the UI.

- **Templating**
  - Child/parent theme template overrides via `templates/ll-tools/...` with a safe loader.

- **Roles**
  - `wordset_manager` – Can manage wordsets and basic content.
  - `ll_tools_editor` – Can upload files, edit categories/wordsets, and access LL Tools pages.
  - `audio_recorder` – Dedicated role for audio recording contributors with limited admin access.

- **Update checker**
  - Uses Yahnis Elsts' Plugin Update Checker, tracking the GitHub repo.

---

## Installation

1. Upload or clone the plugin into `wp-content/plugins/language-learner-tools`.
2. Activate it in **Plugins**.
3. (If `/quiz` or `/embed` initially 404) Go to **Settings → Permalinks** and click **Save** to refresh rewrites.
4. Open **Settings → Language Learner Tools** (or click the **Settings** link under the plugin on the Plugins screen) to review options.

## Quick Start

1. Create terms in **Word Category**, **Word Set**, **Language** and **Part of Speech**.
2. Add **Words**: set the title, featured image (optional), attach audio (optional), and assign **Word Category** terms.
3. Drop a flashcard quiz anywhere:
   ```text
   [flashcard_widget category="animals" quiz_mode="learning"]
   ```
   - `quiz_mode="standard"` for traditional adaptive quizzing (default)
   - `quiz_mode="learning"` for guided learning with word introduction
   - To embed a minimal page for a category, link to `/embed/animals` or `/embed/animals?mode=learning`.

4. Make a grid of words:
   ```text
   [word_grid category="animals" columns="4" limit="24" show_audio="true" show_text="translation" wordset="beginner"]
   ```

5. Single-word audio on lesson pages:
   ```text
   [word_audio id="123" translate="yes"]
   ```

6. Show which images need copyright info:
   ```text
   [image_copyright_grid posts_per_page="12"]
   ```

7. List quiz categories:
   ```text
   [quiz_pages_grid wordset="beginner" popup="yes" mode="learning"]
   [quiz_pages_dropdown wordset="advanced"]
   ```

8. Audio recording interface for contributors:
   ```text
   [audio_recording]
   ```

---

## Shortcodes (details)

### `[flashcard_widget]`
- **Attributes**
  - `category`: target `word-category` term **slug** (CSV accepted).
  - `mode`: `random` (default), `image`, or `text`.
  - `embed`: `true|false` (use `true` for compact UI on `/embed/<slug>` pages).
  - `quiz_mode`: `standard` (default) or `learning`.
  - `wordset`: filter words by wordset slug/name/id.

### `[word_grid]`
- **Common attributes**:
  `limit`, `columns`, `category`, `language`, `sort_by`, `transliterate`, `show_audio`, `show_text` (values like `target|translation|both|none`), `wordset`.

### `[word_audio]`
- **Attributes**:
  - `id`: the Word post ID.
  - `translate`: `yes|no` (show the translation next to the audio button).

### `[image_copyright_grid]`
- **Attributes**:
  - `posts_per_page`: default `12`.

### `[quiz_pages_grid]` / `[quiz_pages_dropdown]`
- **Attributes (grid)**: `show_counts`, `exclude`, `parent`, `order`, `orderby`, `wordset`, `popup` (`yes` to open flashcard overlay inline), `mode` (`standard` or `learning`).
- **Attributes (dropdown)**: `wordset`, `placeholder`, `button` (`yes` to show a Go button).

### `[audio_recording]`
- Provides a browser-based interface for recording audio.
- Best used with the Audio Recorder role for contributors.

---

## Learning Mode

**Learning Mode** is an adaptive learning system that guides users through vocabulary acquisition:

- **Word Introduction**: New words are introduced in small batches (1-2 at a time) with audio repetition.
- **Progressive Difficulty**: The number of answer choices adapts based on performance (2-5 options).
- **Mastery Tracking**: Each word must be answered correctly 3 times before it's considered learned.
- **Smart Sequencing**: Words are reintroduced if answered incorrectly, with spacing to avoid frustration.
- **Progress Visualization**: A dual-progress bar shows both introduction progress and mastery progress.
- **Audio Context**: Uses different recording types (introduction vs. question) for varied learning contexts.

To use Learning Mode:
```text
[flashcard_widget category="animals" quiz_mode="learning"]
```

Or link to: `/quiz/animals?mode=learning`

---

## Audio Recording & Processing Workflow

### For Contributors (Audio Recorder Role)

1. **Recording Interface**: Use the `[audio_recording]` shortcode on a page.
2. **Browser Recording**: Record directly in the browser with playback preview.
3. **Upload**: Submit recordings for processing.

### For Administrators

1. **Audio Processor** (`Tools → Audio Processor`):
   - Batch-process uploaded audio files.
   - Automatically match audio to words by filename.
   - Assign recording types (introduction, isolation, question, sentence).
   - Create `word_audio` posts and attach to words.

2. **Audio Review** (`Tools → Audio Review`):
   - Review audio quality and associations.
   - Listen to audio and verify word matches.
   - Edit or remove incorrect associations.

3. **Audio-Image Matcher** (`Tools → Audio-Image Matcher`):
   - Match audio files to images.
   - Uses fuzzy matching and scoring.
   - Tracks image usage to promote variety.

4. **Recording Types Admin** (`Tools → Recording Types`):
   - Manage the recording type taxonomy.
   - Categorize audio for contextual use.

---

## Recording Types

Audio files can be categorized by recording type:

- **Introduction**: Full introduction audio (e.g., "Merhaba. That means hello in Turkish.")
- **Isolation**: Word spoken in isolation
- **Question**: Word used in a question
- **Sentence**: Word used in a sentence

The flashcard widget intelligently selects the appropriate recording type based on context (learning mode uses introduction audio, standard quiz uses question audio).

---

## Settings

- **Translations**
  - Enable category term translations (optional).
  - If enabled, you can store a **DeepL API** key and have UI strings/terms translated automatically.

- **Flashcards**
  - **Image size** used in quizzes.
  - **Max options** override for multiple-choice layout.
  - **Font** selection for the quiz.

- **Admin**
  - Show/hide audio & image upload tools.
  - Lightweight capability **`view_ll_tools`** gates certain admin pages.

Find the settings via the plugin's **Settings** link on the Plugins page or under **Settings → Language Learner Tools**.

---

## Templating

You can override plugin templates by placing files under:

```
/wp-content/themes/<child-or-parent>/ll-tools/<relative-path>
```

For example, copy:
```
wp-content/plugins/language-learner-tools/templates/flashcard-widget-template.php
```
to:
```
wp-content/themes/your-child-theme/ll-tools/flashcard-widget-template.php
```

---

## Roles

- **Word Set Manager** (`wordset_manager`): basic content + word set management.
- **LL Tools Editor** (`ll_tools_editor`): can upload files, edit categories/word sets, and access LL Tools pages (via `view_ll_tools`).
- **Audio Recorder** (`audio_recorder`): dedicated role for audio recording contributors with limited admin access.

The admin menu is trimmed for these roles to focus on LL Tools–related items.

---

## Update Checker

The plugin includes the **Plugin Update Checker** library and points at this GitHub repository's `main` branch.

---

## Notes

- After activation, the plugin creates/maintains pages for each `word-category` under `/quiz/...`.
- Embedded quiz pages are handled purely by rewrite + template, not by requiring the PHP directly in bootstrap (clean separation).
- Learning mode introduces words gradually and tracks mastery (3 correct answers per word).
- Audio files can have multiple recordings per word (introduction, isolation, question, sentence) for contextual use.

---

## License

GPL-compatible. See source headers.