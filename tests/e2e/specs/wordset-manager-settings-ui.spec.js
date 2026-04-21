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
    <main class="ll-wordset-page" style="padding: 20px;">
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
    <main class="ll-wordset-page" style="padding: 20px;">
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

async function mountSettingsTool(page, markup, viewport) {
  await page.setViewportSize(viewport);
  await page.goto('about:blank');
  await page.setContent(markup);
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: wordsetPagesCssSource });
  await page.addStyleTag({ content: hostileThemeCss });
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
