# Language Learner Tools

A WordPress toolkit for building vocabulary-driven language learning sites. It provides custom post types and taxonomies, flashcard quizzes (with image + audio), auto-generated quiz pages, embeddable quiz pages, vocabulary grids, audio players, bulk uploaders, DeepL-assisted translations, a template override system, and helper roles.

## Key Features

- **Post types & taxonomies**
  - `words` and `word_images` post types.
  - Taxonomies: `word-category`, `wordset`, `language`, `part-of-speech`.

- **Flashcard quiz shortcode**
  - `[flashcard_widget]` renders an interactive quiz. Attributes:
    - `category` (slug or CSV), `mode` (`random` | `image` | `text`), `embed` (`true|false`).

- **Auto quiz pages & embed pages**
  - Auto-generated quiz pages under `/quiz/<category>`.
  - Embeddable pages under `/embed/<category>` render a minimal page that includes `[flashcard_widget embed="true"]`.

- **Other shortcodes**
  - `[word_grid]` – grid of words with audio/text toggles.
  - `[word_audio]` – simple audio + (optional) text/translation for a single word.
  - `[image_copyright_grid posts_per_page="12"]` – paginated grid of `word_images`.
  - Quiz listings: `[quiz_pages_grid]` and `[quiz_pages_dropdown]`.

- **Admin UX**
  - Bulk **audio** and **image** uploaders that can create/update posts and try to match media to posts by name.
  - “Manage Word Sets” admin surface.
  - Settings page (translations, DeepL API key, image size, font, option caps, etc.).

- **DeepL integration**
  - Optional: store a DeepL API key and enable category term translations in the UI.

- **Templating**
  - Child/parent theme template overrides via `templates/ll-tools/...` with a safe loader.

- **Roles**
  - `wordset_manager` and `ll_tools_editor` roles with scoped capabilities and a trimmed admin menu.

- **Update checker**
  - Uses Yahnis Elsts’ Plugin Update Checker, tracking the GitHub repo.

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
   [flashcard_widget category="animals" mode="random"]
   ```
   - To embed a minimal page for a category, link to `/embed/animals`.

4. Make a grid of words:
   ```text
   [word_grid category="animals" columns="4" limit="24" show_audio="true" show_text="translation"]
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
   [quiz_pages_grid show_counts="true"]
   [quiz_pages_dropdown]
   ```

---

## Shortcodes (details)

### `[flashcard_widget]`
- **Attributes**
  - `category`: target `word-category` term **slug** (CSV accepted).
  - `mode`: `random` (default), `image`, or `text`.
  - `embed`: `true|false` (use `true` for compact UI on `/embed/<slug>` pages).

### `[word_grid]`
- **Common attributes**:
  `limit`, `columns`, `category`, `language`, `sort_by`, `transliterate`, `show_audio`, `show_text` (values like `target|translation|both|none`).

### `[word_audio]`
- **Attributes**:
  - `id`: the Word post ID.
  - `translate`: `yes|no` (show the translation next to the audio button).

### `[image_copyright_grid]`
- **Attributes**:
  - `posts_per_page`: default `12`.

### `[quiz_pages_grid]` / `[quiz_pages_dropdown]`
- **Attributes (grid)**: `show_counts`, `exclude`, `parent`, `order`, `orderby`.
- **Attributes (dropdown)**: minimal, provides a simple category selector.

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

Find the settings via the plugin’s **Settings** link on the Plugins page or under **Settings → Language Learner Tools**.

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

The admin menu is trimmed for these roles to focus on LL Tools–related items.

---

## Update Checker

The plugin includes the **Plugin Update Checker** library and points at this GitHub repository’s `main` branch.

---

## Notes

- After activation, the plugin creates/maintains pages for each `word-category` under `/quiz/...`.
- Embedded quiz pages are handled purely by rewrite + template, not by requiring the PHP directly in bootstrap (clean separation).

---

## License

GPL-compatible. See source headers.
