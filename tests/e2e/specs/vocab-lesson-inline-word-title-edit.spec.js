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

function buildMarkup(options = {}) {
  const wordText = options.wordText || 'shalom';
  const translationText = options.translationText || '';

  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="17">
      <div class="word-item" data-word-id="101">
        <div class="ll-word-title-row">
          <h3 class="word-title">
            ${renderInlineEditor('word', wordText, 'Word', 'Add word')}
            ${renderInlineEditor('translation', translationText, 'Translation', 'Add translation')}
          </h3>
        </div>
        <div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div>
      </div>
    </div>
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
      jQuery.ajax = function (options) {
        const payload = Object.assign({}, (options && options.data) || {});
        window.__wordGridRequests.push(payload);
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
              verb_mood: {}
            }
          });
        }, 0);
        return deferred.promise();
      };
    `
  });
  await page.addScriptTag({ content: wordGridJsSource });
}

test('clicking a lesson word title opens the inline editor and saves that field', async ({ page }) => {
  await mountInlineEditor(page, { wordText: 'shalom', translationText: 'peace' });

  const wordEditor = page.locator('[data-ll-inline-word-editor="word"]');
  const wordTrigger = wordEditor.locator('[data-ll-inline-word-trigger="word"]');
  const wordInput = wordEditor.locator('[data-ll-inline-word-input="word"]');

  await wordTrigger.click();
  await expect(wordInput).toBeVisible();

  await wordInput.fill('shalom updated');
  await wordEditor.locator('[data-ll-inline-word-save]').click();

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
  await translationEditor.locator('[data-ll-inline-word-save]').click();

  await expect(page.locator('[data-ll-word-translation]')).toHaveText('peace');
  await expect(translationPlaceholder).toBeHidden();

  const requests = await page.evaluate(() => window.__wordGridRequests);
  expect(requests).toHaveLength(1);
  expect(requests[0].word_text).toBe('shalom');
  expect(requests[0].word_translation).toBe('peace');
});
