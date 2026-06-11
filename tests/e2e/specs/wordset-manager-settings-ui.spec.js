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

function buildRecorderQueueToolMarkup() {
  return `
    <main class="ll-wordset-page" data-ll-wordset-page style="padding: 20px;">
      <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <div class="ll-wordset-settings-card">
          <h2 class="ll-wordset-settings-card__title">Recorder Queues</h2>
          <p class="description" style="margin-top:0;">Review each assigned recorder's queue by category, edit prompt wording, and tune their recording settings from one place.</p>
          <div class="ll-wordset-settings-card__meta">
            <span class="ll-wordset-settings-card__pill">1 recorder</span>
            <span class="ll-wordset-settings-card__pill">2 queued words</span>
            <a class="ll-wordset-settings-card__pill ll-wordset-recorder-queue-view-link" href="#hidden">Hidden (1)</a>
          </div>
        </div>

        <div class="ll-wordset-settings-card ll-wordset-recorder-queue-wordset-settings">
          <h2 class="ll-wordset-settings-card__title">Recorder text</h2>
          <form class="ll-wordset-recorder-queue-settings__form ll-wordset-recorder-queue-settings__form--wordset" data-ll-recorder-queue-autosave="settings">
            <input type="hidden" name="ll_wordset_manager_recorder_queue_action" value="save_wordset_settings" />
            <input type="hidden" name="ll_wordset_manager_recorder_queue_wordset_id" value="22" />
            <input type="hidden" name="ll_wordset_manager_recorder_queue_nonce" value="queue-nonce" />
            <label class="ll-wordset-recorder-queue-settings__select-row" for="fixture-recorder-text-visibility">
              <span>Recorder text visibility</span>
              <select id="fixture-recorder-text-visibility" name="ll_wordset_recorder_text_visibility">
                <option value="inherit">Follow lesson/grid text setting</option>
                <option value="show">Show word text in recorder</option>
                <option value="hide" selected>Hide word text in recorder</option>
              </select>
            </label>
            <p class="description ll-wordset-recorder-queue-settings__note ll-wordset-recorder-queue-settings__note--wordset">Current effective recorder state: hidden. Applies to every recorder assigned to this word set.</p>
            <span class="ll-wordset-recorder-queue-autosave-status" data-ll-recorder-queue-save-status role="status" aria-live="polite" hidden></span>
            <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--primary ll-wordset-recorder-queue-settings__save">Save recorder text setting</button>
          </form>
        </div>

        <article class="ll-wordset-settings-card ll-wordset-recorder-queue-card" id="ll-recorder-queue-44">
          <div class="ll-wordset-recorder-queue-card__head">
            <div class="ll-wordset-recorder-queue-card__identity">
              <h3 class="ll-wordset-recorder-queue-card__title">Queue Recorder</h3>
              <p class="ll-wordset-recorder-queue-card__identity-meta">
                <span>@queue-recorder</span>
                <span>queue-recorder@example.com</span>
              </p>
            </div>
            <div class="ll-wordset-settings-card__meta ll-wordset-recorder-queue-card__summary">
              <span class="ll-wordset-settings-card__pill">2 queued words</span>
              <a class="ll-wordset-settings-card__pill ll-wordset-recorder-queue-view-link" href="#hidden">Hidden (1)</a>
            </div>
          </div>

          <div class="ll-wordset-settings-card__meta ll-wordset-recorder-queue-card__notes">
            <span class="ll-wordset-settings-card__pill">Skipping: Sentence</span>
            <span class="ll-wordset-settings-card__pill">Can add new words</span>
            <span class="ll-wordset-settings-card__pill">Recorder processes audio</span>
          </div>

          <details class="ll-wordset-recorder-queue-settings">
            <summary class="ll-wordset-recorder-queue-settings__summary">Change queue settings</summary>
            <form class="ll-wordset-recorder-queue-settings__form" data-ll-recorder-queue-autosave="settings">
              <input type="hidden" name="ll_wordset_manager_recorder_queue_action" value="save_settings" />
              <input type="hidden" name="ll_wordset_manager_recorder_queue_wordset_id" value="22" />
              <input type="hidden" name="ll_wordset_manager_recorder_queue_user_id" value="44" />
              <input type="hidden" name="ll_wordset_manager_recorder_queue_nonce" value="queue-nonce" />
              <div class="ll-wordset-recorder-queue-settings__grid">
                <fieldset class="ll-wordset-recorder-queue-settings__fieldset">
                  <legend>Only include types</legend>
                  <p class="description">Leave all unchecked to include every recording type.</p>
                  <div class="ll-wordset-recorder-queue-settings__checks">
                    <label><input type="checkbox" name="ll_wordset_manager_recorder_queue_include_types[]" value="isolation" /> <span>Isolation</span></label>
                    <label><input type="checkbox" name="ll_wordset_manager_recorder_queue_include_types[]" value="question" /> <span>Question</span></label>
                  </div>
                </fieldset>
                <fieldset class="ll-wordset-recorder-queue-settings__fieldset">
                  <legend>Skipped types</legend>
                  <p class="description">These are hidden from the recorder queue unless the only-include list is set.</p>
                  <div class="ll-wordset-recorder-queue-settings__checks">
                    <label><input type="checkbox" name="ll_wordset_manager_recorder_queue_exclude_types[]" value="sentence" /> <span>Sentence</span></label>
                    <label><input type="checkbox" name="ll_wordset_manager_recorder_queue_exclude_types[]" value="introduction" /> <span>Introduction</span></label>
                  </div>
                </fieldset>
              </div>
              <label class="ll-wordset-recorder-queue-settings__toggle"><input type="checkbox" name="ll_wordset_manager_recorder_queue_allow_new_words" value="1" checked /> <span>Allow this recorder to record new words</span></label>
              <label class="ll-wordset-recorder-queue-settings__toggle"><input type="checkbox" name="ll_wordset_manager_recorder_queue_auto_process_recordings" value="1" checked /> <span>Recorder processes audio before saving</span></label>
              <p class="description ll-wordset-recorder-queue-settings__note">Shows the recorder the audio processing review step for trimming, noise reduction, and loudness before the recording is saved.</p>
              <span class="ll-wordset-recorder-queue-autosave-status" data-ll-recorder-queue-save-status role="status" aria-live="polite" hidden></span>
              <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--primary ll-wordset-recorder-queue-settings__save">Save queue settings</button>
            </form>
          </details>

          <section class="ll-wordset-recorder-queue-column">
            <h4 class="ll-wordset-settings-card__subtitle">Queue by Category</h4>
            <div class="ll-wordset-recorder-queue-category-grid" role="list">
              <a class="ll-wordset-card ll-wordset-recorder-queue-category-card" href="#category-view" data-recorder-queue-category="fruit">
                <span class="ll-wordset-card__top ll-wordset-recorder-queue-category-card__top">
                  <span class="ll-wordset-card__title ll-wordset-recorder-queue-category__name">Fruit and market questions</span>
                  <span class="ll-wordset-settings-card__pill">2 words</span>
                </span>
                <span class="ll-wordset-card__lesson-link ll-wordset-recorder-queue-category-card__preview-link" aria-hidden="true">
                  <span class="ll-wordset-card__preview ll-wordset-recorder-queue-category__preview has-images">
                    <span class="ll-wordset-preview-item ll-wordset-preview-item--image">
                      <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=" alt="loquat" loading="lazy" decoding="async" fetchpriority="low" />
                    </span>
                    <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                      <span class="ll-wordset-preview-text" dir="auto">loquat</span>
                    </span>
                  </span>
                </span>
              </a>
              <a class="ll-wordset-card ll-wordset-recorder-queue-category-card" href="#category-view-2" data-recorder-queue-category="market">
                <span class="ll-wordset-card__top ll-wordset-recorder-queue-category-card__top">
                  <span class="ll-wordset-card__title ll-wordset-recorder-queue-category__name">Market requests</span>
                  <span class="ll-wordset-settings-card__pill">3 words</span>
                </span>
                <span class="ll-wordset-card__lesson-link ll-wordset-recorder-queue-category-card__preview-link" aria-hidden="true">
                  <span class="ll-wordset-card__preview ll-wordset-recorder-queue-category__preview has-text">
                    <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                      <span class="ll-wordset-preview-text" dir="auto">How much is this?</span>
                    </span>
                    <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                      <span class="ll-wordset-preview-text" dir="auto">Which one?</span>
                    </span>
                  </span>
                </span>
              </a>
            </div>
          </section>
        </article>
      </section>
    </main>
  `;
}

function buildRecorderQueueCategoryViewMarkup() {
  return `
    <main class="ll-wordset-page" data-ll-wordset-page style="padding: 20px;">
      <section class="ll-wordset-settings-page ll-wordset-settings-page--tool" data-ll-wordset-settings-page>
        <article class="ll-wordset-settings-card ll-wordset-recorder-queue-card" id="ll-recorder-queue-44">
          <div class="ll-wordset-recorder-queue-card__head">
            <div class="ll-wordset-recorder-queue-card__identity">
              <h3 class="ll-wordset-recorder-queue-card__title">Queue Recorder</h3>
              <p class="ll-wordset-recorder-queue-card__identity-meta">
                <span>@queue-recorder</span>
                <span>queue-recorder@example.com</span>
              </p>
            </div>
            <div class="ll-wordset-settings-card__meta ll-wordset-recorder-queue-card__summary">
              <span class="ll-wordset-settings-card__pill">2 queued words</span>
            </div>
          </div>

          <section class="ll-wordset-recorder-queue-column ll-wordset-recorder-queue-column--category-view" id="category-view">
            <div class="ll-wordset-recorder-queue-category-view__toolbar">
              <a class="ll-wordset-settings-action ll-wordset-settings-action--secondary ll-wordset-recorder-queue-back" href="#categories">Back to categories</a>
            </div>
            <section class="ll-wordset-recorder-queue-category-view">
              <div class="ll-wordset-recorder-queue-category-view__head">
                <h5 class="ll-wordset-recorder-queue-category-view__title">Fruit and market questions</h5>
                <span class="ll-wordset-settings-card__pill">2 words</span>
              </div>
                <ul class="ll-wordset-recorder-queue-list">
                  <li class="ll-wordset-recorder-queue-item">
                    <div class="ll-wordset-recorder-queue-item__media">
                      <span class="ll-wordset-recorder-queue-item__thumb ll-wordset-recorder-queue-item__thumb--text" aria-hidden="true">L</span>
                    </div>
                    <div class="ll-wordset-recorder-queue-item__content">
                      <div class="ll-wordset-recorder-queue-item__title-row">
                        <span class="ll-wordset-recorder-queue-item__title">loquat</span>
                      </div>
                      <p class="ll-wordset-recorder-queue-item__secondary">yenidunya</p>
                      <div class="ll-wordset-recorder-queue-item__types">
                        <span class="ll-wordset-settings-card__pill ll-wordset-recorder-queue-item__type">Question</span>
                      </div>
                      <details class="ll-wordset-recorder-queue-prompts" open>
                        <summary>Recording prompts</summary>
                        <form class="ll-wordset-recorder-queue-prompts__form" data-ll-recorder-queue-autosave="prompts">
                          <input type="hidden" name="ll_wordset_manager_recorder_queue_action" value="save_prompts" />
                          <input type="hidden" name="ll_wordset_manager_recorder_queue_wordset_id" value="22" />
                          <input type="hidden" name="ll_wordset_manager_recorder_queue_user_id" value="44" />
                          <input type="hidden" name="ll_wordset_manager_recorder_queue_nonce" value="queue-nonce" />
                          <input type="hidden" name="ll_wordset_manager_recorder_queue_word_id" value="100" />
                          <input type="hidden" name="ll_wordset_manager_recorder_queue_title" value="loquat" />
                          <div class="ll-wordset-recorder-queue-prompts__grid">
                            <label class="ll-wordset-recorder-queue-prompts__field">
                              <span>Question</span>
                              <textarea name="ll_wordset_manager_recorder_queue_prompts[question]" rows="2">Where are the loquats?</textarea>
                            </label>
                          </div>
                          <span class="ll-wordset-recorder-queue-autosave-status" data-ll-recorder-queue-save-status role="status" aria-live="polite" hidden></span>
                          <button type="submit" class="ll-wordset-settings-action ll-wordset-settings-action--secondary ll-wordset-recorder-queue-prompts__save">Save prompts</button>
                        </form>
                      </details>
                    </div>
                    <button type="button" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-recorder-queue-item__action">Hide</button>
                  </li>
                </ul>
            </section>
          </section>
        </article>
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
              Rename categories, update translated names, move them under a different parent, or delete categories.
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
                  <button type="button" class="ll-wordset-settings-action ll-wordset-settings-action--danger">Delete Category</button>
                </div>
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
            <div class="ll-wordset-editor-field ll-wordset-editor-field--search">
              <label class="ll-wordset-editor-field__label" for="ll-editor-fixture-search">Word or translation</label>
              <input id="ll-editor-fixture-search" type="search" value="long translation" />
              <label class="ll-wordset-editor-toggle ll-wordset-editor-toggle--exact">
                <input type="checkbox" />
                <span>Exact letters + diacritics</span>
              </label>
            </div>
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
                <div class="ll-wordset-editor-word-layout">
                  <span class="ll-wordset-editor-thumb" data-ll-wordset-editor-thumb>
                    <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+tmP8AAAAASUVORK5CYII=" alt="Very Long Multilingual Word Title" loading="lazy" decoding="async" />
                  </span>
                  <span class="ll-wordset-editor-word-main">
                    <strong class="ll-wordset-editor-word-title">Very Long Multilingual Word Title</strong>
                    <span class="ll-wordset-editor-word-translation">A compact but long translation shown in the editor table</span>
                    <button type="button" class="ll-wordset-editor-edit-trigger" data-ll-wordset-editor-open-word-edit data-word-id="101" aria-label="Edit word">
                      <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m5 16.5-.5 3 3-.5L17 9.5 14.5 7 5 16.5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                      <span>Edit</span>
                    </button>
                  </span>
                </div>
              </div>
              <div class="ll-wordset-editor-cell ll-wordset-editor-cell--categories" role="cell" data-label="Categories">
                <div class="ll-wordset-editor-pill-list">
                  <a class="ll-wordset-editor-pill ll-wordset-editor-pill--link" href="/conversation-practice/">Conversation Practice</a>
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
                      <button type="button" class="ll-wordset-editor-recording-play ll-study-recording-btn ll-study-recording-btn--isolation" data-ll-wordset-editor-audio data-audio-url="/wp-content/uploads/isolation.mp3" data-recording-type="isolation" data-recording-id="201" aria-label="Play Isolation recording">
                        <span class="ll-study-recording-icon" aria-hidden="true"></span>
                        <span class="ll-study-recording-visualizer" aria-hidden="true"><span class="bar"></span><span class="bar"></span><span class="bar"></span><span class="bar"></span></span>
                      </button>
                      <span class="ll-wordset-editor-recording__label">Isolation recording</span>
                      <span class="ll-wordset-editor-state ll-wordset-editor-state--publish">Published</span>
                    </div>
                    <div class="ll-wordset-editor-recording__actions">
                      <button type="button" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="Trash recording">
                        <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8h10M10 8V6h4v2M9 10.5v6M15 10.5v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                      </button>
                    </div>
                  </div>
                  <div class="ll-wordset-editor-recording">
                    <div class="ll-wordset-editor-recording__main">
                      <button type="button" class="ll-wordset-editor-recording-play ll-study-recording-btn ll-study-recording-btn--question" data-ll-wordset-editor-audio data-audio-url="/wp-content/uploads/question.mp3" data-recording-type="question" data-recording-id="202" aria-label="Play Question recording">
                        <span class="ll-study-recording-icon" aria-hidden="true"></span>
                        <span class="ll-study-recording-visualizer" aria-hidden="true"><span class="bar"></span><span class="bar"></span><span class="bar"></span><span class="bar"></span></span>
                      </button>
                      <span class="ll-wordset-editor-recording__label">Question recording with long type label</span>
                      <span class="ll-wordset-editor-state ll-wordset-editor-state--publish">Published</span>
                    </div>
                    <div class="ll-wordset-editor-recording__actions">
                      <button type="button" class="ll-wordset-editor-icon-button ll-wordset-editor-icon-button--danger" aria-label="Trash recording">
                        <svg class="ll-wordset-editor-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8h10M10 8V6h4v2M9 10.5v6M15 10.5v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                      </button>
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
    window.llWordsetPageData = {
      view: 'settings',
      ajaxUrl: '/fake-admin-ajax.php',
      i18n: {
        recorderQueueSaving: 'Saving...',
        recorderQueueSaved: 'Saved!',
        recorderQueueSaveError: 'Unable to save right now.'
      }
    };
  });
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: wordsetPagesJsSource });
}

async function stubRecorderQueueAutosave(page) {
  await page.evaluate(() => {
    window.__recorderQueueAutosaveCalls = [];
    window.jQuery.post = function postRecorderQueueAutosave(url, payload) {
      const entries = {};
      (Array.isArray(payload) ? payload : []).forEach((entry) => {
        const name = String(entry && entry.name || '');
        if (!name) {
          return;
        }
        if (!entries[name]) {
          entries[name] = [];
        }
        entries[name].push(String(entry.value || ''));
      });
      window.__recorderQueueAutosaveCalls.push({ url, entries });

      const deferred = window.jQuery.Deferred();
      window.setTimeout(() => {
        deferred.resolve({ success: true, data: { result: 'prompts' } });
      }, 25);
      return deferred.promise();
    };
  });
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

test('manager recorder queue uses compact category cards and focused prompt editing', async ({ page }) => {
  await mountSettingsTool(page, buildRecorderQueueToolMarkup(), { width: 900, height: 844 });

  await expect(page.getByRole('link', { name: 'Hidden (1)' }).first()).toBeVisible();
  await expect(page.getByText('Change queue settings')).toBeVisible();
  await expect(page.getByLabel('Recorder text visibility')).toHaveValue('hide');
  await page.getByText('Change queue settings').click();
  await expect(page.getByText('Skipped types')).toBeVisible();
  await expect(page.getByText('Allow this recorder to record new words')).toBeVisible();
  await expect(page.getByText('Recorder processes audio before saving')).toBeVisible();

  await expect(page.getByText('Fruit and market questions')).toBeVisible();
  await expect(page.locator('.ll-wordset-recorder-queue-category__preview img[alt="loquat"]')).toBeVisible();
  await expect(page.locator('.ll-wordset-recorder-queue-category-card .ll-wordset-card__quiz-btn')).toHaveCount(0);
  await expect(page.locator('.ll-wordset-recorder-queue-item__title', { hasText: 'loquat' })).toHaveCount(0);

  const cardMetrics = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('.ll-wordset-recorder-queue-category-card')).map((card) => {
      const rect = card.getBoundingClientRect();
      return {
        top: Math.round(rect.top),
        width: Math.round(rect.width)
      };
    });
  });
  expect(cardMetrics.length).toBeGreaterThanOrEqual(2);
  expect(cardMetrics[0].top).toBe(cardMetrics[1].top);
  cardMetrics.forEach((metric) => {
    expect(metric.width).toBeLessThanOrEqual(332);
  });

  await assertPageFitsViewport(page);

  await mountSettingsTool(page, buildRecorderQueueCategoryViewMarkup(), { width: 390, height: 844 });

  await expect(page.getByRole('link', { name: 'Back to categories' })).toBeVisible();
  await expect(page.getByText('Fruit and market questions')).toBeVisible();
  await expect(page.locator('.ll-wordset-recorder-queue-item__title', { hasText: 'loquat' })).toBeVisible();
  await expect(page.getByText('Recording prompts')).toBeVisible();
  await expect(page.locator('.ll-wordset-recorder-queue-prompts textarea')).toHaveValue('Where are the loquats?');
  await expect(page.getByRole('button', { name: 'Hide' })).toBeVisible();

  await assertPageFitsViewport(page);
});

test('manager recorder queue autosaves prompt edits without a manual save click', async ({ page }) => {
  await mountSettingsTool(page, buildRecorderQueueCategoryViewMarkup(), { width: 390, height: 844 });
  await enableWordsetPageScript(page);
  await stubRecorderQueueAutosave(page);

  const promptForm = page.locator('.ll-wordset-recorder-queue-prompts__form');
  const status = promptForm.locator('[data-ll-recorder-queue-save-status]');
  await expect(promptForm.locator('.ll-wordset-recorder-queue-prompts__save')).toBeHidden();

  await promptForm.locator('textarea').fill('Where should the recorder say loquat?');
  await expect(status).toHaveText('Saving...');
  await expect(status).toHaveText('Saved!');

  const autosavePayload = await page.evaluate(() => window.__recorderQueueAutosaveCalls[0] || null);
  expect(autosavePayload).not.toBeNull();
  expect(autosavePayload.url).toBe('/fake-admin-ajax.php');
  expect(autosavePayload.entries.action).toContain('ll_tools_wordset_recorder_queue_save');
  expect(autosavePayload.entries.ll_wordset_manager_recorder_queue_action).toContain('save_prompts');
  expect(autosavePayload.entries['ll_wordset_manager_recorder_queue_prompts[question]']).toContain('Where should the recorder say loquat?');
});

test('manager categories tool keeps create and edit actions visible on mobile', async ({ page }) => {
  await mountSettingsTool(page, buildCategoriesToolMarkup(), { width: 390, height: 844 });

  await expect(page.locator('#ll-wordset-category-new-name')).toBeVisible();
  await expect(page.locator('#ll-wordset-category-new-translation')).toBeVisible();
  await expect(page.locator('#ll-wordset-category-new-parent')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Add Category' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Save Category' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Delete Category' })).toBeVisible();

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
  await expect(page.locator('[data-ll-wordset-editor-thumb] img')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-editor-audio]')).toHaveCount(2);

  await assertPageFitsViewport(page);

  const layoutMetrics = await page.evaluate(() => {
    const wordCell = document.querySelector('.ll-wordset-editor-cell--word[role="cell"]');
    const mediaCell = document.querySelector('.ll-wordset-editor-cell--media[role="cell"]');
    const details = document.querySelector('.ll-wordset-editor-row__details');
    const recordings = Array.from(document.querySelectorAll('.ll-wordset-editor-recording'));
    if (!wordCell || !mediaCell || !details || recordings.length === 0) {
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
      moveTargetCount: document.querySelectorAll('[data-ll-wordset-editor-move-target]').length
    };
  });

  expect(layoutMetrics).not.toBeNull();
  expect(layoutMetrics.wordWidth).toBeGreaterThan(layoutMetrics.mediaWidth);
  expect(layoutMetrics.detailsWidth).toBeGreaterThan(layoutMetrics.mediaWidth * 2);
  expect(layoutMetrics.detailsLeft).toBeLessThanOrEqual(layoutMetrics.wordLeft + 2);
  expect(layoutMetrics.recordingMinWidth).toBeGreaterThan(320);
  expect(layoutMetrics.recordingRows).toBe(1);
  expect(layoutMetrics.moveTargetCount).toBe(0);
});

test('manager wordset editor keeps recording moves inside the edit popup', async ({ page }) => {
  await mountSettingsTool(page, buildEditorToolMarkup(), { width: 1180, height: 900 });
  await enableWordsetPageScript(page);

  await expect(page.locator('[data-ll-wordset-editor-move-target]')).toHaveCount(0);
  await expect(page.getByLabel('Move recording to word')).toHaveCount(0);
});

test('manager wordset editor table keeps recording controls usable on mobile', async ({ page }) => {
  await mountSettingsTool(page, buildEditorToolMarkup(), { width: 390, height: 844 });

  await expect(page.locator('.ll-wordset-editor-stat')).toHaveCount(4);
  await expect(page.getByRole('link', { name: 'Show words missing published audio' })).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-saved-view-form')).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-table-card')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Edit word' })).toBeVisible();
  await expect(page.locator('[data-ll-wordset-editor-thumb] img')).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-recording').first()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Play Isolation recording' })).toBeVisible();
  await expect(page.locator('.ll-wordset-editor-history-filter')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Trash recording' }).first()).toBeVisible();
  await expect(page.getByLabel('Move recording to word')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Move recording' })).toHaveCount(0);

  await assertPageFitsViewport(page);

  const controlMetrics = await page.evaluate(() => {
    const editTrigger = document.querySelector('.ll-wordset-editor-edit-trigger');
    const statCard = document.querySelector('.ll-wordset-editor-stat');
    const savedViewForm = document.querySelector('.ll-wordset-editor-saved-view-form');
    const details = document.querySelector('.ll-wordset-editor-row__details');
    const buttons = Array.from(document.querySelectorAll('.ll-wordset-editor-icon-button'));
    if (!editTrigger || !statCard || !savedViewForm || !details || buttons.length < 2) {
      return null;
    }
    const editTriggerRect = editTrigger.getBoundingClientRect();
    const statRect = statCard.getBoundingClientRect();
    const savedViewRect = savedViewForm.getBoundingClientRect();
    const detailsRect = details.getBoundingClientRect();
    return {
      editTriggerRight: Math.round(editTriggerRect.right),
      statRight: Math.round(statRect.right),
      savedViewRight: Math.round(savedViewRect.right),
      detailsRight: Math.round(detailsRect.right),
      buttonSizes: buttons.map((button) => Math.round(button.getBoundingClientRect().width))
    };
  });

  expect(controlMetrics).not.toBeNull();
  expect(controlMetrics.editTriggerRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.statRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.savedViewRight).toBeLessThanOrEqual(392);
  expect(controlMetrics.detailsRight).toBeLessThanOrEqual(392);
  controlMetrics.buttonSizes.forEach((width) => {
    expect(width).toBeGreaterThanOrEqual(32);
  });
});
