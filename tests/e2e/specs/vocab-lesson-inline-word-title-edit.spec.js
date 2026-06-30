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

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function escapeAttr(value) {
  return escapeHtml(value).replace(/"/g, '&quot;');
}

function renderInlineEditor(field, value, label, placeholder) {
  const hasValue = String(value || '').trim() !== '';
  const displayClass = field === 'translation' ? 'll-word-translation' : 'll-word-text';
  const displayAttr = field === 'translation' ? 'data-ll-word-translation' : 'data-ll-word-text';

  return `
    <span class="ll-word-inline-edit ll-word-inline-edit--${field}" data-ll-inline-word-editor="${field}" id="editor-${field}-101">
      <button
        type="button"
        class="ll-word-inline-edit-trigger"
        data-ll-inline-word-trigger="${field}"
        aria-expanded="false"
        aria-controls="editor-${field}-101-controls"
        aria-label="Edit ${escapeAttr(label)}"
        title="Edit ${escapeAttr(label)}">
        <span class="${displayClass}" ${displayAttr} dir="auto"${hasValue ? '' : ' hidden'}>${escapeHtml(value)}</span>
        <span class="ll-word-inline-edit-placeholder" data-ll-inline-word-placeholder${hasValue ? ' hidden' : ''}>${escapeHtml(placeholder)}</span>
        <span class="ll-word-inline-edit-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
            <path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
        </span>
      </button>
      <span class="ll-word-inline-edit-controls" id="editor-${field}-101-controls" data-ll-inline-word-form hidden>
        <input
          type="text"
          class="ll-word-inline-edit-input"
          data-ll-inline-word-input="${field}"
          value="${escapeAttr(value)}"
          placeholder="${escapeAttr(placeholder)}"
          aria-label="${escapeAttr(label)}"
          dir="auto"
          autocomplete="off" />
        <span class="ll-word-inline-edit-actions">
          <button type="button" class="ll-word-inline-edit-action ll-word-inline-edit-action--save" data-ll-inline-word-save aria-label="Save" title="Save">
            <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
              <path d="m3 8 3 3 7-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>
          </button>
          <button type="button" class="ll-word-inline-edit-action ll-word-inline-edit-action--cancel" data-ll-inline-word-cancel aria-label="Cancel editing" title="Cancel editing">
            <svg viewBox="0 0 16 16" focusable="false" aria-hidden="true">
              <path d="M4 4l8 8M12 4 4 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path>
            </svg>
          </button>
        </span>
      </span>
    </span>
  `;
}

function renderRecordingMarkup(recordingText = 'alpha transcript') {
  return `
    <div class="ll-word-recordings ll-word-recordings--with-text" aria-label="Recordings">
      <div class="ll-word-recording-row ll-word-recording-row--editable" data-recording-id="501">
        <button
          type="button"
          class="ll-study-recording-btn ll-word-grid-recording-btn"
          data-recording-id="501"
          data-ll-recording-edit-label="Edit recording"
          data-audio-url="recording.mp3">
          Play
        </button>
        <span class="ll-word-recording-text">
          <span class="ll-word-recording-text-main" dir="auto">${escapeHtml(recordingText)}</span>
        </span>
        <button type="button" class="ll-word-recording-edit-trigger" data-ll-recording-edit-trigger data-recording-id="501" aria-label="Edit recording" title="Edit recording">
          <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
            <path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
          </svg>
        </button>
      </div>
    </div>
    <div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="true">
      <div class="ll-word-edit-body" data-ll-word-edit-body>
        <input type="text" data-ll-word-input="word" value="shalom" />
        <input type="text" data-ll-word-input="translation" value="peace" />
        <textarea data-ll-word-input="note"></textarea>
        <div class="ll-word-edit-recording" data-recording-id="501">
          <input type="text" data-ll-recording-input="text" value="${escapeAttr(recordingText)}" />
          <input type="text" data-ll-recording-input="translation" value="" />
          <input type="text" data-ll-recording-input="ipa" value="" />
        </div>
        <button type="button" data-ll-word-edit-save>Save</button>
        <button type="button" data-ll-word-edit-cancel>Cancel</button>
      </div>
    </div>
    <button type="button" data-ll-word-edit-toggle aria-expanded="false">Edit word</button>
  `;
}

function buildMarkup(options = {}) {
  const wordText = options.wordText || 'shalom';
  const translationText = options.translationText || '';
  const recordingMarkup = options.recordingText ? renderRecordingMarkup(options.recordingText) : '';

  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="17">
      <div class="word-item" data-word-id="101">
        <div class="ll-word-title-row">
          <h3 class="word-title">
            ${renderInlineEditor('word', wordText, 'Word', 'Add word')}
            ${renderInlineEditor('translation', translationText, 'Translation', 'Add translation')}
          </h3>
        </div>
        ${recordingMarkup}
        <div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div>
      </div>
    </div>
    <button type="button" id="outside-button">Outside</button>
  `;
}

async function mountInlineEditor(page, options = {}) {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildMarkup(options));
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
          error: 'Unable to save changes.'
        }
      };
      window.__wordGridRequests = [];
      function payloadFromAjaxData(data) {
        if (data instanceof FormData) {
          const payload = {};
          data.forEach(function (value, key) {
            if (Object.prototype.hasOwnProperty.call(payload, key)) {
              if (!Array.isArray(payload[key])) {
                payload[key] = [payload[key]];
              }
              payload[key].push(value);
            } else {
              payload[key] = value;
            }
          });
          return payload;
        }
        return Object.assign({}, data || {});
      }
      jQuery.ajax = function (options) {
        const payload = payloadFromAjaxData((options && options.data) || {});
        window.__wordGridRequests.push(payload);
        const submittedRecordings = payload.recordings ? JSON.parse(String(payload.recordings)) : [];
        const deferred = jQuery.Deferred();
        setTimeout(function () {
          deferred.resolve({
            success: true,
            data: {
              word_id: parseInt(payload.word_id, 10) || 0,
              word_text: String(payload.word_text || ''),
              word_translation: String(payload.word_translation || ''),
              part_of_speech: {},
              grammatical_gender: {},
              grammatical_plurality: {},
              verb_tense: {},
              verb_mood: {},
              recordings: submittedRecordings.map(function (recording) {
                return {
                  id: parseInt(recording.id, 10) || 0,
                  recording_text: String(recording.text || ''),
                  recording_translation: String(recording.translation || ''),
                  recording_ipa: String(recording.ipa || ''),
                  review_fields: recording.review_fields || {}
                };
              })
            }
          });
        }, 0);
        return deferred.promise();
      };
    `
  });
  await page.addScriptTag({ content: wordGridJsSource });
}

test('clicking away from a lesson word title autosaves that field', async ({ page }) => {
  await mountInlineEditor(page, { wordText: 'shalom', translationText: 'peace' });

  const wordEditor = page.locator('[data-ll-inline-word-editor="word"]');
  const wordTrigger = wordEditor.locator('[data-ll-inline-word-trigger="word"]');
  const wordInput = wordEditor.locator('[data-ll-inline-word-input="word"]');

  await wordTrigger.click();
  await expect(wordInput).toBeVisible();

  await wordInput.fill('shalom updated');
  await expect(wordEditor.locator('[data-ll-inline-word-save]')).toBeHidden();
  await expect(wordEditor.locator('[data-ll-inline-word-cancel]')).toBeHidden();
  await page.locator('#outside-button').click();

  await expect(page.locator('[data-ll-word-text]')).toHaveText('shalom updated');
  await expect(page.locator('[data-ll-word-save-status]')).toHaveText('Saved.');
  await expect(wordInput).toBeHidden();

  const requests = await page.evaluate(() => window.__wordGridRequests);
  expect(requests).toHaveLength(1);
  expect(requests[0].word_text).toBe('shalom updated');
  expect(requests[0].word_translation).toBe('peace');
});

test('an empty lesson translation still exposes a clickable inline placeholder', async ({ page }) => {
  await mountInlineEditor(page, { wordText: 'shalom', translationText: '' });

  const translationEditor = page.locator('[data-ll-inline-word-editor="translation"]');
  const translationPlaceholder = translationEditor.locator('[data-ll-inline-word-placeholder]');
  const translationInput = translationEditor.locator('[data-ll-inline-word-input="translation"]');

  await expect(translationPlaceholder).toHaveText('Add translation');
  await translationEditor.locator('[data-ll-inline-word-trigger="translation"]').click();
  await expect(translationInput).toBeVisible();

  await translationInput.fill('peace');
  await page.locator('#outside-button').click();

  await expect(page.locator('[data-ll-word-translation]')).toHaveText('peace');
  await expect(translationPlaceholder).toBeHidden();

  const requests = await page.evaluate(() => window.__wordGridRequests);
  expect(requests).toHaveLength(1);
  expect(requests[0].word_text).toBe('shalom');
  expect(requests[0].word_translation).toBe('peace');
});

test('recording transcription text can be selected and edited inline without opening the full editor', async ({ page }) => {
  await mountInlineEditor(page, {
    wordText: 'shalom',
    translationText: 'peace',
    recordingText: 'alpha transcript'
  });

  const recordingText = page.locator('.ll-word-recording-text-main').first();
  const recordingRow = page.locator('.ll-word-recording-row[data-recording-id="501"]');
  const panel = page.locator('[data-ll-word-edit-panel]');

  await expect(recordingText).toHaveText('alpha transcript');
  await expect(recordingText).toHaveCSS('user-select', 'text');

  const box = await recordingText.boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box.x + 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width - 2, box.y + box.height / 2, { steps: 8 });
  await page.mouse.up();

  const selectedText = await page.evaluate(() => window.getSelection().toString());
  expect(selectedText).toContain('alpha');
  await expect(page.locator('[data-ll-inline-recording-text-input]')).toHaveCount(0);

  await page.evaluate(() => window.getSelection().removeAllRanges());
  const rowWidthBefore = await recordingRow.evaluate((el) => el.getBoundingClientRect().width);
  await recordingText.click();

  const inlineInput = page.locator('[data-ll-inline-recording-text-input]');
  await expect(inlineInput).toBeVisible();
  const rowWidthEditing = await recordingRow.evaluate((el) => el.getBoundingClientRect().width);
  expect(Math.abs(rowWidthEditing - rowWidthBefore)).toBeLessThan(3);

  await inlineInput.fill('alpha transcript edited');
  await page.locator('#outside-button').click();

  await expect(page.locator('.ll-word-recording-text-main')).toHaveText('alpha transcript edited');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('[data-ll-word-save-status]')).toHaveText('Saved.');

  const requests = await page.evaluate(() => window.__wordGridRequests);
  expect(requests).toHaveLength(1);
  expect(requests[0].action).toBe('ll_tools_word_grid_update_word');
  expect(JSON.parse(requests[0].recordings)[0].text).toBe('alpha transcript edited');
});
