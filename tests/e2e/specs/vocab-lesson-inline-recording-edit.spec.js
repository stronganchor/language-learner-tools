const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);
const wordGridJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);
const jquerySource = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');

const recordingEditIconMarkup = `
  <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
    <path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
    <path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
  </svg>
`;

function buildRecordingRow(id, type, icon) {
  const editLabel = `Edit ${type} recording`;
  return `
    <div class="ll-word-recording-row ll-word-recording-row--editable" data-recording-id="${id}">
      <button
        type="button"
        class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--${type}"
        data-audio-url="/audio/${id}.mp3"
        data-recording-type="${type}"
        data-recording-id="${id}"
        data-ll-recording-label="${type}"
        data-ll-recording-edit-label="${editLabel}"
        aria-label="Play ${type} recording"
        title="Play ${type} recording">
        <span aria-hidden="true">${icon}</span>
      </button>
      <button
        type="button"
        class="ll-word-inline-edit-trigger ll-word-recording-edit-trigger"
        data-ll-recording-edit-trigger
        data-recording-id="${id}"
        aria-label="${editLabel}"
        title="${editLabel}">
        <span class="ll-word-inline-edit-icon" aria-hidden="true">${recordingEditIconMarkup}</span>
      </button>
    </div>
  `;
}

function buildMarkup() {
  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="17">
      <div class="word-item" data-word-id="101">
        <div class="ll-word-title-row">
          <h3 class="word-title">
            <span class="word-text" data-ll-word-text>shalom</span>
          </h3>
        </div>
        <div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div>
        <div class="ll-word-recordings" aria-label="Recordings">
          ${buildRecordingRow(501, 'question', '?')}
          ${buildRecordingRow(502, 'isolation', 'O')}
          ${buildRecordingRow(503, 'introduction', '!')}
        </div>
      </div>
    </div>
  `;
}

async function mountInlineRecordingEditor(page) {
  await page.setViewportSize({ width: 900, height: 900 });
  await page.goto('about:blank');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({
    content: `
      window.jQuery = window.$ = jQuery;
      window.llToolsWordGridData = {
        ajaxUrl: '/fake-ajax',
        isLoggedIn: true,
        canEdit: true,
        editNonce: 'nonce',
        editI18n: {
          saving: 'Saving...',
          saved: 'Saved.',
          error: 'Unable to save changes.',
          save: 'Save',
          cancel: 'Cancel editing',
          recordingText: 'Recording text',
          recordingTranslation: 'Recording translation',
          recordingIpa: 'Recording IPA',
          addRecordingText: 'Add text',
          addRecordingTranslation: 'Add translation',
          addRecordingIpa: 'Add IPA'
        }
      };
      window.__wordGridRequests = [];
      jQuery.ajax = function (options) {
        const payload = Object.assign({}, (options && options.data) || {});
        window.__wordGridRequests.push(payload);
        const deferred = jQuery.Deferred();
        setTimeout(function () {
          deferred.resolve({
            success: true,
            data: {}
          });
        }, 0);
        return deferred.promise();
      };
    `
  });
  await page.addScriptTag({ content: wordGridJsSource });
}

test('hovering between no-text recording rows reveals only one stable edit trigger at a time', async ({ page }) => {
  await mountInlineRecordingEditor(page);

  const rows = page.locator('.ll-word-recording-row--editable');
  await rows.nth(0).hover();

  await expect(rows.nth(0)).toHaveClass(/is-edit-trigger-visible/);
  await expect(rows.nth(1)).not.toHaveClass(/is-edit-trigger-visible/);

  let visibleIds = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('.ll-word-recording-row--editable.is-edit-trigger-visible'))
      .map((row) => row.getAttribute('data-recording-id'));
  });
  expect(visibleIds).toEqual(['501']);

  await rows.nth(1).hover();

  await expect(rows.nth(0)).not.toHaveClass(/is-edit-trigger-visible/);
  await expect(rows.nth(1)).toHaveClass(/is-edit-trigger-visible/);

  visibleIds = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('.ll-word-recording-row--editable.is-edit-trigger-visible'))
      .map((row) => row.getAttribute('data-recording-id'));
  });
  expect(visibleIds).toEqual(['502']);
});

test('clicking a no-text recording edit trigger opens the inline editor and saves caption fields', async ({ page }) => {
  await mountInlineRecordingEditor(page);

  const row = page.locator('.ll-word-recording-row--editable').first();
  await row.hover();
  await row.locator('[data-ll-recording-edit-trigger]').click();

  const textInput = row.locator('[data-ll-inline-recording-input="text"]');
  const translationInput = row.locator('[data-ll-inline-recording-input="translation"]');

  await expect(textInput).toBeVisible();
  await expect(translationInput).toHaveCount(1);

  await textInput.fill('shalom audio');
  await translationInput.evaluate((node, value) => {
    node.value = value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
  }, 'peace audio');
  await row.locator('[data-ll-inline-recording-save]').click();

  await expect(row.locator('.ll-word-recording-text-main')).toHaveText('shalom audio');
  await expect(row.locator('.ll-word-recording-text-translation')).toHaveText('peace audio');
  await expect(page.locator('[data-ll-word-save-status]')).toHaveText('Saved.');
  await expect(row).not.toHaveClass(/is-inline-editing/);

  const requests = await page.evaluate(() => window.__wordGridRequests);
  expect(requests).toHaveLength(1);
  expect(requests[0].recording_id).toBe('501');
  expect(requests[0].recording_text).toBe('shalom audio');
  expect(requests[0].recording_translation).toBe('peace audio');
});
