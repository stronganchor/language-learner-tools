const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);
const wordsetPagesCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);
const jquerySource = fs.readFileSync(
  path.resolve(__dirname, '../node_modules/jquery/dist/jquery.min.js'),
  'utf8'
);
const wordsetPagesJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-pages.js'),
  'utf8'
);

const hostileThemeCss = `
  .ll-wordset-page button,
  .ll-wordset-page input,
  .ll-wordset-page select,
  .ll-wordset-page textarea,
  .ll-wordset-page a {
    min-width: 0 !important;
    text-decoration: underline !important;
    border-radius: 0 !important;
    box-shadow: inset 0 0 0 2px rgba(14, 73, 118, 0.2) !important;
    letter-spacing: 0.08em !important;
    text-transform: uppercase !important;
  }
`;

function buildRecorderToolMarkup() {
  return `
    <main class="ll-wordset-page" data-ll-wordset-page style="padding: 20px;">
      <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
          <h2 class="ll-wordset-settings-card__title">Recorder Access</h2>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Assigned Tutors / Recorders</h3>
            <div style="display:grid;gap:10px;">
              <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
                <div>
                  <strong>Current Recorder</strong>
                  <div class="description" style="margin-top:2px;">recorder@example.com</div>
                </div>
                <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button">Unassign</button>
              </div>
            </div>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Assign Existing Recorder</h3>
            <label for="ll-wordset-manager-recorder-user" class="screen-reader-text">Audio recorder user</label>
            <select id="ll-wordset-manager-recorder-user" name="ll_wordset_manager_recorder_user_id" class="ll-tools-settings-select" style="max-width:420px;">
              <option value="">Select a recorder user</option>
              <option value="31">Recorder One (recorder-one@example.com)</option>
              <option value="32">Recorder Two (recorder-two@example.com)</option>
            </select>
            <p class="description" style="margin-top:8px;">
              Assigning a recorder locks their recording interface to this word set and resets any stale category filter.
            </p>
            <div style="margin-top:10px;">
              <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button">Assign Recorder</button>
            </div>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Upgrade Existing User</h3>
            <div class="ll-wordset-settings-card__field">
              <label for="ll-wordset-manager-recorder-identifier">
                <span>Username or email</span>
                <input
                  id="ll-wordset-manager-recorder-identifier"
                  type="text"
                  name="ll_wordset_manager_recorder_identifier"
                  class="regular-text ll-tools-settings-input"
                  style="max-width:420px;"
                  autocomplete="off"
                  value=""
                />
              </label>
              <p class="description">Find an existing user by username or email, add the Audio Recorder role, and assign them to this word set in one step.</p>
            </div>
            <div style="margin-top:10px;">
              <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button">Enable Recorder Access</button>
            </div>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Invite Recorder By Email</h3>
            <div class="ll-wordset-settings-card__field">
              <label for="ll-wordset-manager-recorder-email">
                <span>Recorder email</span>
                <input
                  id="ll-wordset-manager-recorder-email"
                  type="email"
                  name="ll_wordset_manager_recorder_email"
                  class="regular-text ll-tools-settings-input"
                  style="max-width:420px;"
                  autocomplete="email"
                  value=""
                />
              </label>
              <p class="description">This sends a special signup link that creates or connects a recorder account and assigns that person to this word set.</p>
            </div>
            <div style="margin-top:10px;">
              <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button">Send Recorder Invite</button>
            </div>
          </div>
        </div>
      </section>
    </main>
  `;
}

function buildCategoriesToolMarkup() {
  return `
    <main class="ll-wordset-page" data-ll-wordset-page style="padding: 20px;">
      <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
          <h2 class="ll-wordset-settings-card__title">Categories</h2>
          <div class="ll-wordset-settings-card__meta">
            <span class="ll-wordset-settings-card__pill">3 categories</span>
            <span class="ll-wordset-settings-card__pill">2 translations saved</span>
            <span class="ll-wordset-settings-card__pill">1 hidden from study</span>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Create Category</h3>
            <p class="description" style="margin-top:0;">Categories created here stay scoped to this word set.</p>
            <form class="ll-wordset-settings-category-form">
              <div class="ll-wordset-settings-card__field-grid">
                <div class="ll-wordset-settings-card__field">
                  <label for="ll-wordset-category-new-name">
                    <span>Category name</span>
                    <input id="ll-wordset-category-new-name" type="text" name="ll_wordset_category_name" class="ll-tools-settings-input" value="" />
                  </label>
                </div>
                <div class="ll-wordset-settings-card__field">
                  <label for="ll-wordset-category-new-translation">
                    <span>Translated name</span>
                    <input id="ll-wordset-category-new-translation" type="text" name="ll_wordset_category_translation" class="ll-tools-settings-input" value="" />
                  </label>
                  <p class="description">Shown when category translations are enabled for this word set.</p>
                </div>
                <div class="ll-wordset-settings-card__field">
                  <label for="ll-wordset-category-new-parent">
                    <span>Parent category</span>
                    <select id="ll-wordset-category-new-parent" name="ll_wordset_category_parent_id" class="ll-tools-settings-select">
                      <option value="0">Top level</option>
                      <option value="11">Basics</option>
                      <option value="12">Travel</option>
                    </select>
                  </label>
                </div>
              </div>
              <div class="ll-wordset-settings-category-actions">
                <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--primary">Add Category</button>
              </div>
            </form>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Existing Categories</h3>
            <p class="description" style="margin-top:0;">
              Rename categories, update translated names, move them under a different parent, or delete empty categories.
            </p>
            <div class="ll-wordset-settings-category-list">
              <form class="ll-wordset-settings-category-row">
                <div class="ll-wordset-settings-category-row__header">
                  <div class="ll-wordset-settings-category-row__title-wrap">
                    <strong class="ll-wordset-settings-category-row__title">Basics</strong>
                    <div class="ll-wordset-settings-category-row__meta">
                      <span class="ll-wordset-settings-card__pill">7 words</span>
                      <span class="ll-wordset-settings-card__pill">1 lesson</span>
                      <span class="ll-wordset-settings-card__pill">Text to text</span>
                    </div>
                  </div>
                </div>
                <div class="ll-wordset-settings-card__field-grid">
                  <div class="ll-wordset-settings-card__field">
                    <label>
                      <span>Category name</span>
                      <input type="text" class="ll-tools-settings-input" value="Basics" />
                    </label>
                  </div>
                  <div class="ll-wordset-settings-card__field">
                    <label>
                      <span>Translated name</span>
                      <input type="text" class="ll-tools-settings-input" value="Temeller" />
                    </label>
                  </div>
                  <div class="ll-wordset-settings-card__field">
                    <label>
                      <span>Parent category</span>
                      <select class="ll-tools-settings-select">
                        <option value="0" selected>Top level</option>
                        <option value="12">Travel</option>
                      </select>
                    </label>
                  </div>
                </div>
                <div class="ll-wordset-settings-category-actions">
                  <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--primary">Save Category</button>
                  <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--danger" disabled>Delete Empty Category</button>
                </div>
                <p class="description ll-wordset-settings-category-row__delete-note">Remove or move the words in this category first.</p>
              </form>
            </div>
          </div>
        </div>
      </section>
    </main>
  `;
}

function buildAdvancedToolMarkup() {
  return `
    <main class="ll-wordset-page" style="padding: 20px;">
      <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
          <h2 class="ll-wordset-settings-card__title">Advanced Settings</h2>
          <div class="ll-wordset-settings-card__meta">
            <span class="ll-wordset-settings-card__pill">Manual order</span>
            <span class="ll-wordset-settings-card__pill">6 categories</span>
            <span class="ll-wordset-settings-card__pill">3 grammar features</span>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Category Ordering</h3>
            <p class="description" style="margin-top:0;">
              These controls affect lesson order, recommended study flow, and prerequisite gating for this word set only.
            </p>
            <div class="ll-wordset-settings-card__field-grid">
              <div class="ll-wordset-settings-card__field">
                <label>
                  <span>Ordering mode</span>
                  <select class="ll-tools-settings-select">
                    <option>Alphabetical</option>
                    <option selected>Manual order</option>
                    <option>Prerequisites</option>
                  </select>
                </label>
              </div>
              <div class="ll-wordset-settings-card__field">
                <label>
                  <span>Manual order</span>
                  <textarea class="large-text ll-tools-settings-input" rows="4">Basics&#10;Travel&#10;Food</textarea>
                </label>
              </div>
            </div>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Games and Answer Cards</h3>
            <div class="ll-wordset-settings-card__field-grid">
              <div class="ll-wordset-settings-card__field">
                <label for="ll-wordset-games-image-size">
                  <span>Word games image size</span>
                  <select id="ll-wordset-games-image-size" class="ll-tools-settings-select">
                    <option>Default (4 picture cards)</option>
                    <option selected>Large (3 picture cards)</option>
                  </select>
                </label>
              </div>
              <div class="ll-wordset-settings-card__field">
                <label for="ll-wordset-answer-option-font-weight">
                  <span>Answer option text weight</span>
                  <select id="ll-wordset-answer-option-font-weight" class="ll-tools-settings-select">
                    <option>Normal (400)</option>
                    <option selected>Bold (700)</option>
                  </select>
                </label>
              </div>
              <div class="ll-wordset-settings-card__field">
                <label for="ll-wordset-answer-option-font-size">
                  <span>Answer option text size</span>
                  <input id="ll-wordset-answer-option-font-size" type="number" class="small-text ll-tools-settings-input" value="48" />
                </label>
              </div>
            </div>
          </div>

          <div class="ll-wordset-settings-card__group">
            <h3 class="ll-wordset-settings-card__subtitle">Grammar Tags</h3>
            <div class="ll-wordset-settings-card__field-grid">
              <div class="ll-wordset-settings-card__field">
                <label class="ll-wordset-settings-card__checkbox-item" for="ll-wordset-grammatical-gender">
                  <input type="checkbox" id="ll-wordset-grammatical-gender" checked />
                  <span>Enable grammatical gender</span>
                </label>
                <label for="ll-wordset-gender-options">
                  <span>Gender options</span>
                  <textarea id="ll-wordset-gender-options" rows="4" class="large-text ll-tools-settings-input">masculine&#10;feminine</textarea>
                </label>
              </div>
              <div class="ll-wordset-settings-card__field">
                <label class="ll-wordset-settings-card__checkbox-item" for="ll-wordset-plurality">
                  <input type="checkbox" id="ll-wordset-plurality" checked />
                  <span>Enable plurality</span>
                </label>
                <label for="ll-wordset-plurality-options">
                  <span>Plurality options</span>
                  <textarea id="ll-wordset-plurality-options" rows="4" class="large-text ll-tools-settings-input">singular&#10;plural</textarea>
                </label>
              </div>
              <div class="ll-wordset-settings-card__field">
                <label class="ll-wordset-settings-card__checkbox-item" for="ll-wordset-verb-tense">
                  <input type="checkbox" id="ll-wordset-verb-tense" checked />
                  <span>Enable verb tense</span>
                </label>
                <label for="ll-wordset-verb-tense-options">
                  <span>Verb tense options</span>
                  <textarea id="ll-wordset-verb-tense-options" rows="4" class="large-text ll-tools-settings-input">present&#10;past&#10;future</textarea>
                </label>
              </div>
            </div>
          </div>

          <div style="margin-top:10px;">
            <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button">Save Advanced Settings</button>
          </div>
        </div>
      </section>
    </main>
  `;
}

function buildEditorToolMarkup() {
  return `
    <main class="ll-wordset-page" data-ll-wordset-page style="padding: 20px;">
      <section
        class="ll-wordset-settings-page ll-wordset-editor"
        data-ll-wordset-editor
        data-ll-wordset-editor-selected-singular="1 selected"
        data-ll-wordset-editor-selected-plural="%d selected"
        data-ll-wordset-editor-all-filtered="All 8 filtered words selected"
      >
        <div class="ll-wordset-editor-stats">
          <a class="ll-wordset-editor-stat" href="?ll_wordset_tool=editor" aria-label="Show all words">
            <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>
            <span class="ll-wordset-editor-stat__value">42</span>
            <span class="ll-wordset-editor-stat__label">Words</span>
          </a>
          <a class="ll-wordset-editor-stat" href="?ll_wordset_tool=editor&ll_editor_recording=missing" aria-label="Show words missing published audio">
            <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9.5v5M12 5.5v13M18 9.5v5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <span class="ll-wordset-editor-stat__value">7</span>
            <span class="ll-wordset-editor-stat__label">Missing audio</span>
          </a>
          <a class="ll-wordset-editor-stat" href="?ll_wordset_tool=editor&ll_editor_image=missing" aria-label="Show words missing images">
            <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.8"/></svg>
            <span class="ll-wordset-editor-stat__value">13</span>
            <span class="ll-wordset-editor-stat__label">Missing images</span>
          </a>
          <a class="ll-wordset-editor-stat" href="#ll-wordset-editor-history" aria-label="Jump to recent actions">
            <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 9a5 5 0 1 1 1.5 3.6M7 9H4.5M7 9v-2.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="ll-wordset-editor-stat__value">2</span>
            <span class="ll-wordset-editor-stat__label">Recent actions</span>
          </a>
        </div>

        <form class="ll-wordset-settings-card ll-wordset-editor-filters">
          <div class="ll-wordset-editor-filters__grid">
            <label class="ll-wordset-editor-field ll-wordset-editor-field--search">
              <span class="ll-wordset-editor-field__label">Word or translation</span>
              <input type="search" value="long translation" />
            </label>
            <label class="ll-wordset-editor-field">
              <span class="ll-wordset-editor-field__label">Category</span>
              <select><option>Long category name used by editors</option></select>
            </label>
            <label class="ll-wordset-editor-field">
              <span class="ll-wordset-editor-field__label">Recording</span>
              <select><option>Missing audio</option></select>
            </label>
          </div>
        </form>

        <section class="ll-wordset-settings-card ll-wordset-editor-saved-views">
          <div class="ll-wordset-editor-panel-head">
            <h2 class="ll-wordset-settings-card__title">Saved views</h2>
            <form class="ll-wordset-editor-saved-view-form">
              <input type="text" value="Needs media review" aria-label="Saved view name" />
              <button type="button" class="ll-wordset-editor-icon-button" aria-label="Save current view">
                <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 4.5h10v15l-5-3-5 3v-15Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
              </button>
            </form>
          </div>
          <div class="ll-wordset-editor-saved-view-list">
            <div class="ll-wordset-editor-saved-view">
              <a href="#">
                <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 7h14M8 12h8M10 17h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <span>Long saved filter title for missing media review</span>
              </a>
              <button type="button" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="Delete saved view">
                <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8h10M10 8V6h4v2M9 10.5v6M15 10.5v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              </button>
            </div>
          </div>
        </section>

        <form class="ll-wordset-settings-card ll-wordset-editor-bulk" data-ll-wordset-editor-bulk-form>
          <div class="ll-wordset-editor-bulk__bar">
            <span class="ll-wordset-editor-selected-count" data-ll-wordset-editor-selected-count>0 selected</span>
            <label class="ll-wordset-editor-all-filtered">
              <input type="checkbox" data-ll-wordset-editor-all-filtered />
              <span>All 8 filtered words</span>
            </label>
            <label class="ll-wordset-editor-field">
              <span class="ll-wordset-editor-field__label">Action</span>
              <select><option>Move to category</option></select>
            </label>
            <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--primary">Apply</button>
          </div>
        </form>

        <div class="ll-wordset-settings-card ll-wordset-editor-table-card">
          <template id="ll-wordset-editor-move-options-1">
            <option value="101">Very Long Multilingual Word Title - A compact but long translation shown in the editor table</option>
            <option value="102">Move target word - translation</option>
          </template>
          <div class="ll-wordset-editor-table" role="table">
            <div class="ll-wordset-editor-row ll-wordset-editor-row--head" role="row">
              <span class="ll-wordset-editor-cell ll-wordset-editor-cell--check" role="columnheader"><input type="checkbox" /></span>
              <span class="ll-wordset-editor-cell ll-wordset-editor-cell--word" role="columnheader"><a class="ll-wordset-editor-sort-link" href="#"><span>Word</span><span class="ll-wordset-editor-sort-link__icon">&lt;&gt;</span></a></span>
              <span class="ll-wordset-editor-cell" role="columnheader">Categories</span>
              <span class="ll-wordset-editor-cell" role="columnheader">State</span>
              <span class="ll-wordset-editor-cell" role="columnheader">Media</span>
            </div>
            <div class="ll-wordset-editor-row" role="row">
              <label class="ll-wordset-editor-cell ll-wordset-editor-cell--check" role="cell"><input type="checkbox" data-ll-wordset-editor-word /></label>
              <div class="ll-wordset-editor-cell ll-wordset-editor-cell--word" role="cell" data-label="Word">
                <strong class="ll-wordset-editor-word-title">Very Long Multilingual Word Title</strong>
                <span class="ll-wordset-editor-word-translation">A compact but long translation shown in the editor table</span>
                <details class="ll-wordset-editor-inline-edit" open>
                  <summary aria-label="Quick edit word">
                    <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 16.5-.5 3 3-.5L17 9.5 14.5 7 5 16.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    <span>Edit</span>
                  </summary>
                  <form class="ll-wordset-editor-inline-form">
                    <label>
                      <span>Word</span>
                      <input type="text" value="Very Long Multilingual Word Title" />
                    </label>
                    <label>
                      <span>Translation</span>
                      <input type="text" value="A compact but long translation shown in the editor table" />
                    </label>
                    <button type="button" class="ll-wordset-editor-icon-button" aria-label="Save quick edit">
                      <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </button>
                  </form>
                </details>
              </div>
              <div class="ll-wordset-editor-cell ll-wordset-editor-cell--categories" role="cell" data-label="Categories">
                <div class="ll-wordset-editor-pill-list">
                  <span class="ll-wordset-editor-pill">Conversation Practice</span>
                  <span class="ll-wordset-editor-pill">Review Queue</span>
                </div>
              </div>
              <div class="ll-wordset-editor-cell ll-wordset-editor-cell--state" role="cell" data-label="State"><span class="ll-wordset-editor-state ll-wordset-editor-state--publish">Published</span></div>
              <div class="ll-wordset-editor-cell ll-wordset-editor-cell--media" role="cell" data-label="Media">
                <div class="ll-wordset-editor-media">
                  <span class="ll-wordset-editor-media__item is-ready">Audio <span>2</span></span>
                  <span class="ll-wordset-editor-media__item is-missing">Image</span>
                </div>
              </div>
              <div class="ll-wordset-editor-row__details" role="cell" aria-colspan="4" data-label="Recordings">
                <div class="ll-wordset-editor-recordings">
                  <div class="ll-wordset-editor-recording">
                    <div class="ll-wordset-editor-recording__main">
                      <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9.5v5M12 5.5v13M18 9.5v5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                      <span class="ll-wordset-editor-recording__label">Isolation recording</span>
                      <span class="ll-wordset-editor-state ll-wordset-editor-state--publish">Published</span>
                    </div>
                    <div class="ll-wordset-editor-recording__actions">
                      <button type="button" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="Trash recording">
                        <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8h10M10 8V6h4v2M9 10.5v6M15 10.5v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                      </button>
                      <form class="ll-wordset-editor-move-form">
                        <select aria-label="Move recording to word" data-ll-wordset-editor-move-target data-ll-wordset-editor-source-word-id="101" data-ll-wordset-editor-options-template="ll-wordset-editor-move-options-1">
                          <option value="0">Move to...</option>
                        </select>
                        <button type="button" class="ll-wordset-editor-icon-button" aria-label="Move recording">
                          <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </button>
                      </form>
                    </div>
                  </div>
                  <div class="ll-wordset-editor-recording">
                    <div class="ll-wordset-editor-recording__main">
                      <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 9.5v5M12 5.5v13M18 9.5v5" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                      <span class="ll-wordset-editor-recording__label">Question recording with long type label</span>
                      <span class="ll-wordset-editor-state ll-wordset-editor-state--publish">Published</span>
                    </div>
                    <div class="ll-wordset-editor-recording__actions">
                      <button type="button" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="Trash recording">
                        <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8h10M10 8V6h4v2M9 10.5v6M15 10.5v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                      </button>
                      <form class="ll-wordset-editor-move-form">
                        <select aria-label="Move recording to word" data-ll-wordset-editor-move-target data-ll-wordset-editor-source-word-id="101" data-ll-wordset-editor-options-template="ll-wordset-editor-move-options-1">
                          <option value="0">Move to...</option>
                        </select>
                        <button type="button" class="ll-wordset-editor-icon-button" aria-label="Move recording">
                          <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5.5 12.5 10 17l8.5-10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <section id="ll-wordset-editor-history" class="ll-wordset-settings-card ll-wordset-editor-history">
          <div class="ll-wordset-editor-history__head">
            <h2 class="ll-wordset-settings-card__title">Action history</h2>
            <span class="ll-wordset-editor-history__hint">Undo is available for recent actions.</span>
          </div>
          <form class="ll-wordset-editor-history-filter">
            <label class="ll-wordset-editor-field">
              <span class="ll-wordset-editor-field__label">History type</span>
              <select><option>All actions</option></select>
            </label>
            <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--secondary">Show</button>
          </form>
          <div class="ll-wordset-editor-history__list">
            <div class="ll-wordset-editor-history__row">
              <div class="ll-wordset-editor-history__main">
                <div class="ll-wordset-editor-history__meta">
                  <span class="ll-wordset-editor-pill">Quick edits</span>
                  <span class="ll-wordset-editor-history__time">April 28, 2026</span>
                  <span class="ll-wordset-editor-history__time">Editor user with a long name</span>
                </div>
                <span class="ll-wordset-editor-history__summary">Quick edited a long multilingual word title.</span>
                <details class="ll-wordset-editor-history__details" open>
                  <summary>Details</summary>
                  <ul><li>Title: Old title to new title</li></ul>
                </details>
              </div>
              <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--secondary">Undo</button>
            </div>
          </div>
        </section>
      </section>
    </main>
  `;
}

async function mountSettingsTool(page, markup, viewport) {
  await page.setViewportSize(viewport);
  await page.goto('about:blank');
  await page.setContent(markup);
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: wordsetPagesCssSource });
  await page.addStyleTag({ content: hostileThemeCss });
}

async function enableWordsetPageScript(page) {
  await page.evaluate(() => {
    window.llWordsetPageData = { view: 'settings' };
  });
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: wordsetPagesJsSource });
}

async function assertPageFitsViewport(page) {
  const metrics = await page.evaluate(() => ({
    viewportWidth: window.innerWidth,
    bodyWidth: document.documentElement.scrollWidth
  }));

  expect(metrics.bodyWidth).toBeLessThanOrEqual(metrics.viewportWidth + 2);
}

test('manager recorder access tool stays usable on mobile', async ({ page }) => {
  await mountSettingsTool(page, buildRecorderToolMarkup(), { width: 390, height: 844 });

  await expect(page.locator('#ll-wordset-manager-recorder-user')).toBeVisible();
  await expect(page.locator('#ll-wordset-manager-recorder-identifier')).toBeVisible();
  await expect(page.locator('#ll-wordset-manager-recorder-email')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Assign Recorder' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Enable Recorder Access' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Send Recorder Invite' })).toBeVisible();

  await assertPageFitsViewport(page);
});

test('manager categories tool keeps create and edit actions visible on mobile', async ({ page }) => {
  await mountSettingsTool(page, buildCategoriesToolMarkup(), { width: 390, height: 844 });

  await expect(page.locator('#ll-wordset-category-new-name')).toBeVisible();
  await expect(page.locator('#ll-wordset-category-new-translation')).toBeVisible();
  await expect(page.locator('#ll-wordset-category-new-parent')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add Category' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save Category' })).toBeVisible();
  await expect(page.getByText('Remove or move the words in this category first.')).toBeVisible();

  await assertPageFitsViewport(page);

  const actionMetrics = await page.evaluate(() => {
    const actions = Array.from(document.querySelectorAll('.ll-wordset-settings-category-actions .ll-wordset-settings-action'));
    return actions.map((button) => {
      const rect = button.getBoundingClientRect();
      return {
        left: Math.round(rect.left),
        right: Math.round(rect.right),
        width: Math.round(rect.width)
      };
    });
  });

  expect(actionMetrics.length).toBeGreaterThanOrEqual(2);
  actionMetrics.forEach((metric) => {
    expect(metric.left).toBeGreaterThanOrEqual(0);
    expect(metric.right).toBeLessThanOrEqual(392);
    expect(metric.width).toBeGreaterThan(80);
  });
});

test('manager advanced settings tool stacks dense controls without horizontal overflow on mobile', async ({ page }) => {
  await mountSettingsTool(page, buildAdvancedToolMarkup(), { width: 390, height: 844 });

  await expect(page.locator('#ll-wordset-games-image-size')).toBeVisible();
  await expect(page.locator('#ll-wordset-answer-option-font-weight')).toBeVisible();
  await expect(page.locator('#ll-wordset-plurality-options')).toBeVisible();
  await expect(page.locator('#ll-wordset-verb-tense-options')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save Advanced Settings' })).toBeVisible();

  await assertPageFitsViewport(page);

  const fieldGridMetrics = await page.evaluate(() => {
    const firstGrid = document.querySelector('.ll-wordset-settings-card__field-grid');
    if (!firstGrid) {
      return null;
    }
    const rect = firstGrid.getBoundingClientRect();
    const children = Array.from(firstGrid.children).map((child) => child.getBoundingClientRect().top);
    return {
      width: Math.round(rect.width),
      distinctRows: Array.from(new Set(children.map((value) => Math.round(value)))).length
    };
  });

  expect(fieldGridMetrics).not.toBeNull();
  expect(fieldGridMetrics.width).toBeLessThanOrEqual(360);
  expect(fieldGridMetrics.distinctRows).toBeGreaterThan(1);
});

test('manager wordset editor table gives recordings usable desktop width', async ({ page }) => {
  await mountSettingsTool(page, buildEditorToolMarkup(), { width: 1180, height: 900 });

  await expect(page.locator('.ll-wordset-editor-row__details')).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-recording')).toHaveCount(2);

  await assertPageFitsViewport(page);

  const layoutMetrics = await page.evaluate(() => {
    const wordCell = document.querySelector('.ll-wordset-editor-cell--word[role="cell"]');
    const mediaCell = document.querySelector('.ll-wordset-editor-cell--media[role="cell"]');
    const details = document.querySelector('.ll-wordset-editor-row__details');
    const recordings = Array.from(document.querySelectorAll('.ll-wordset-editor-recording'));
    const targetSelects = Array.from(document.querySelectorAll('[data-ll-wordset-editor-move-target]'));
    const targetTemplate = document.querySelector('#ll-wordset-editor-move-options-1');
    if (!wordCell || !mediaCell || !details || recordings.length === 0 || !targetTemplate) {
      return null;
    }
    const wordRect = wordCell.getBoundingClientRect();
    const mediaRect = mediaCell.getBoundingClientRect();
    const detailsRect = details.getBoundingClientRect();
    const recordingRects = recordings.map((recording) => recording.getBoundingClientRect());
    return {
      wordWidth: Math.round(wordRect.width),
      mediaWidth: Math.round(mediaRect.width),
      detailsWidth: Math.round(detailsRect.width),
      detailsLeft: Math.round(detailsRect.left),
      wordLeft: Math.round(wordRect.left),
      recordingMinWidth: Math.round(Math.min(...recordingRects.map((rect) => rect.width))),
      recordingRows: new Set(recordingRects.map((rect) => Math.round(rect.top))).size,
      selectOptionCounts: targetSelects.map((select) => select.options.length),
      templateOptionCount: targetTemplate.content.querySelectorAll('option').length
    };
  });

  expect(layoutMetrics).not.toBeNull();
  expect(layoutMetrics.wordWidth).toBeGreaterThan(layoutMetrics.mediaWidth);
  expect(layoutMetrics.detailsWidth).toBeGreaterThan(layoutMetrics.mediaWidth * 2);
  expect(layoutMetrics.detailsLeft).toBeLessThanOrEqual(layoutMetrics.wordLeft + 2);
  expect(layoutMetrics.recordingMinWidth).toBeGreaterThan(320);
  expect(layoutMetrics.recordingRows).toBe(1);
  expect(layoutMetrics.selectOptionCounts).toEqual([1, 1]);
  expect(layoutMetrics.templateOptionCount).toBe(2);
});

test('manager wordset editor move targets hydrate only when needed', async ({ page }) => {
  await mountSettingsTool(page, buildEditorToolMarkup(), { width: 1180, height: 900 });
  await enableWordsetPageScript(page);

  const firstTarget = page.locator('[data-ll-wordset-editor-move-target]').first();
  await expect(firstTarget).toBeVisible();

  await expect.poll(async () => firstTarget.evaluate((select) => select.options.length)).toBe(1);
  await firstTarget.focus();
  await expect.poll(async () => firstTarget.evaluate((select) => select.options.length)).toBe(2);

  const values = await firstTarget.evaluate((select) => Array.from(select.options).map((option) => option.value));
  expect(values).toEqual(['0', '102']);
});

test('manager wordset editor table keeps recording controls usable on mobile', async ({ page }) => {
  await mountSettingsTool(page, buildEditorToolMarkup(), { width: 390, height: 844 });

  await expect(page.locator('.ll-wordset-editor-stat')).toHaveCount(4);
  await expect(page.getByRole('link', { name: 'Show words missing published audio' })).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-saved-view-form')).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-table-card')).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-inline-form')).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-recording').first()).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-history-filter')).toBeVisible();
  await expect(page.getByLabel('Move recording to word').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Trash recording' }).first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Move recording' }).first()).toBeVisible();

  await assertPageFitsViewport(page);

  const controlMetrics = await page.evaluate(() => {
    const select = document.querySelector('.ll-wordset-editor-move-form select');
    const inlineForm = document.querySelector('.ll-wordset-editor-inline-form');
    const statCard = document.querySelector('.ll-wordset-editor-stat');
    const savedViewForm = document.querySelector('.ll-wordset-editor-saved-view-form');
    const details = document.querySelector('.ll-wordset-editor-row__details');
    const buttons = Array.from(document.querySelectorAll('.ll-wordset-editor-icon-button'));
    if (!select || !inlineForm || !statCard || !savedViewForm || !details || buttons.length < 2) {
      return null;
    }
    const selectRect = select.getBoundingClientRect();
    const inlineRect = inlineForm.getBoundingClientRect();
    const statRect = statCard.getBoundingClientRect();
    const savedViewRect = savedViewForm.getBoundingClientRect();
    const detailsRect = details.getBoundingClientRect();
    return {
      selectWidth: Math.round(selectRect.width),
      selectRight: Math.round(selectRect.right),
      inlineRight: Math.round(inlineRect.right),
      statRight: Math.round(statRect.right),
      savedViewRight: Math.round(savedViewRect.right),
      detailsRight: Math.round(detailsRect.right),
      buttonSizes: buttons.map((button) => Math.round(button.getBoundingClientRect().width))
    };
  });

  expect(controlMetrics).not.toBeNull();
  expect(controlMetrics.selectWidth).toBeGreaterThan(180);
  expect(controlMetrics.selectRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.inlineRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.statRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.savedViewRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.detailsRight).toBeLessThanOrEqual(392);
  controlMetrics.buttonSizes.forEach((width) => {
    expect(width).toBeGreaterThanOrEqual(32);
  });
});
