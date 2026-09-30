const { test, expect } = require('@playwright/test');
const path = require('path');

const root = path.resolve(__dirname, '../../..');
const saveFirst = 'Save changes before copying or splitting this word.';

function wordMarkup(wordId, wordsetId, recordingIds, categoryId = 11) {
  return `<section class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="${wordsetId}" data-ll-category-id="${categoryId}">
    <article class="word-item" data-word-id="${wordId}">
      <span data-ll-word-text>Source ${wordId}</span>
      <button type="button" data-ll-word-edit-toggle aria-expanded="false">Edit ${wordId}</button>
      <div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div>
      <div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="true" hidden></div>
      <div class="ll-word-edit-panel" data-ll-word-edit-panel role="dialog" aria-modal="true" aria-label="Edit word ${wordId}" aria-hidden="true" tabindex="-1">
        <div class="ll-word-edit-body" data-ll-word-edit-body>
          <div class="ll-word-edit-fields">
            <label for="title-${wordId}">Word title</label>
            <input id="title-${wordId}" class="ll-word-edit-input" data-ll-word-input="word" value="Source ${wordId}" />
            <label for="translation-${wordId}">Translation</label>
            <input id="translation-${wordId}" class="ll-word-edit-input" data-ll-word-input="translation" value="Meaning" />
            <label><input type="checkbox" data-ll-word-category-input value="${categoryId}" checked /> Current category</label>
            <input type="file" data-ll-word-image-input accept="image/png" aria-label="Word image" />
          </div>
          <div class="ll-word-edit-recordings" data-ll-word-recordings-panel>
            ${recordingIds.map(id => `<div class="ll-word-edit-recording" data-recording-id="${id}" data-recording-type="isolation">
              <label for="recording-${id}">Recording ${id}</label>
              <input id="recording-${id}" class="ll-word-edit-input" data-ll-recording-input="text" value="Recording ${id}" />
            </div>`).join('')}
          </div>
        </div>
        <div class="ll-word-edit-footer">
          <div class="ll-word-edit-actions">
            <button type="button" class="ll-word-copy-trigger" data-ll-word-copy data-word-id="${wordId}" data-wordset-id="${wordsetId}" data-word-copy-nonce="copy-nonce-${wordsetId}">Copy / Split</button>
            <button type="button" data-ll-word-edit-save>Save</button>
            <button type="button" data-ll-word-edit-cancel>Cancel</button>
          </div>
          <div class="ll-word-edit-status" data-ll-word-edit-status aria-live="polite"></div>
        </div>
      </div>
      <div class="ll-word-recordings">${recordingIds.map(id => `<div class="ll-word-recording-row" data-recording-id="${id}"><button type="button" class="ll-word-grid-recording-btn" data-recording-id="${id}" data-ll-recording-edit-label="Edit recording">Public recording ${id}</button></div>`).join('')}</div>
    </article>
  </section>`;
}

async function mount(page, scenario = 'success', { tallCategory = false, deferredEditor = false } = {}) {
  const spacer = tallCategory ? '<div aria-hidden="true" style="grid-column: 1 / -1; height: 1600px"></div>' : '';
  const sourceGrid = deferredEditor
    ? `<section id="visible-category" class="word-grid ll-word-grid ll-word-grid--reorderable" data-ll-word-grid data-ll-word-edit-deferred-grid="1" data-ll-wordset-id="7" data-ll-category-id="11" data-ll-lesson-id="601" data-ll-word-grid-reorderable="1" data-ll-secondary-text-mode="ipa" data-fixture-page-cursor="after-104">
        <article class="word-item" data-word-id="101"><span data-ll-word-text>Source 101</span><button type="button" data-ll-word-edit-deferred>Edit 101</button><div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div></article>
        <article class="word-item" data-word-id="104"><span data-ll-word-text>Another category word</span></article>${spacer}
      </section>`
    : wordMarkup(101, 7, [31, 32]).replace('</section>', `${spacer}</section>`);
  const modalHost = deferredEditor ? '<div class="ll-word-edit-modal-host" data-ll-word-edit-modal-host><div class="word-grid ll-word-grid" data-ll-word-grid data-ll-word-edit-modal-grid="1" data-ll-wordset-id="7"></div></div>' : '';
  const markup = `<main><button id="outside-before">Outside before</button>${sourceGrid}${wordMarkup(102, 8, [41])}${wordMarkup(103, 7, [51], 12)}<button id="outside-after">Outside after</button></main>${modalHost}`;
  const copyCards = Object.fromEntries([[101, 7, 11], [102, 8, 11], [103, 7, 12]].map(([source, wordset, category]) => [source,
    deferredEditor
      ? `<section class="word-grid ll-word-grid" data-ll-word-grid data-ll-word-edit-deferred-grid="1" data-ll-wordset-id="${wordset}" data-ll-category-id="${category}"><article class="word-item ll-word-item--draft" data-word-id="${source + 100}" data-ll-word-status="draft"><span data-ll-word-text>Source ${source + 100}</span><button type="button" data-ll-word-edit-deferred>Edit ${source + 100}</button><div class="ll-word-save-status" data-ll-word-save-status aria-live="polite"></div></article></section>`
      : wordMarkup(source + 100, wordset, [], category).replace('class="word-item"', 'class="word-item ll-word-item--draft" data-ll-word-status="draft"')
  ]));
  await page.route('https://ll-copy-popup.test/**', route => route.fulfill({ contentType: 'text/html', body: markup }));
  await page.goto('https://ll-copy-popup.test/lesson');
  await page.addStyleTag({ path: path.join(root, 'css/language-learner-tools.css') });
  await page.addStyleTag({ path: path.join(root, 'css/word-copy-dialog.css') });
  await page.addScriptTag({ path: require.resolve('jquery') });
  await page.evaluate(({ scenario, saveFirst, copyCards, editorHtml }) => {
    window.llToolsWordGridData = {
      ajaxUrl: '/ajax', nonce: 'study-nonce', editNonce: 'edit-nonce', canEdit: true, isLoggedIn: true,
      state: { wordset_id: 7, category_ids: [11], starred_word_ids: [] }, i18n: {}, editI18n: { copySaveFirst: saveFirst }
    };
    window.llWordCopy = {
      ajaxUrl: '/ajax', wordsetId: 99, userId: 5, nonce: 'wrong-global-nonce', timeoutMs: 5000,
      heading: 'Copy / Split Word', title: 'New word title', recordings: 'Move selected recordings to the copy',
      hint: 'Recordings stay on the original unless selected below.', empty: 'No recordings to move.',
      loading: 'Loading…', saving: 'Copying…', submit: 'Create copy', cancel: 'Cancel', close: 'Close',
      more: 'More recordings', open: 'View copy', retry: 'Retry', check: 'Check result', failed: 'Request failed.',
      uncertain: 'Check the result before starting another copy.', limit: 'Choose at most 50 recordings.', copySaveFirst: saveFirst
    };
    window.copyCalls = [];
    window.copyCompleted = [];
    window.copySourceUpdates = [];
    window.copyIntents = {};
    window.editorCalls = [];
    window.saveCalls = [];
    window.llToolsWordEditModalData = { ajaxUrl: '/ajax', nonce: 'modal-nonce', i18n: {} };
    document.addEventListener('ll-word-copy-completed', event => window.copyCompleted.push(event.detail));
    document.addEventListener('ll-word-copy-source-updated', event => window.copySourceUpdates.push(event.detail));
    window.fetch = async (_url, options) => {
      const params = Object.fromEntries(options.body.entries());
      params.moveIds = options.body.getAll('move_ids[]');
      window.copyCalls.push(params);
      const source = Number(params.word_id);
      const ids = source === 101 ? [31, 32] : source === 102 ? [41] : [51];
      const response = data => new Response(JSON.stringify({ success: true, data }), { status: 200 });
      if (params.action === 'll_tools_word_copy_preview') {
        return response({ title: `Source ${source}`, recordings: ids.map(id => ({ id, title: `Recording ${id}`, status: 'Published', url: '' })), after_id: ids.at(-1), has_more: false });
      }
      if (params.action === 'll_tools_word_copy_apply') window.copyIntents[source] = params;
      if (params.action === 'll_tools_word_copy_apply' && scenario === 'server_failure') {
        return new Response(JSON.stringify({ success: false, data: { message: 'A selected recording changed. Reopen the copy dialog.' } }), { status: 409 });
      }
      if (params.action === 'll_tools_word_copy_apply' && scenario === 'lost_response') throw new TypeError('Failed to fetch');
      if (scenario === 'slow') await new Promise(resolve => { window.finishCopy = resolve; });
      const intent = window.copyIntents[source] || params;
      const moved = intent.moveIds.map(Number);
      const newId = source + 100;
      return response({ state: scenario === 'pending' ? 'pending' : 'completed', new_word_id: newId, title: intent.title || `Source ${source}`,
        status: 'draft', status_label: 'Draft', source_status: 'publish', source_status_label: 'Published',
        source_audio_count: ids.length - moved.length, source_audio_label: `${ids.length - moved.length} recordings`,
        moved_ids: moved, image: {}, url: `/editor?copy=${newId}`, category_ids: [source === 103 ? 12 : 11],
        card_html: scenario === 'pending' ? '' : copyCards[source].replaceAll(`Source ${newId}`, intent.title || `Source ${source}`),
        message: scenario === 'pending' ? 'Review the saved copy.' : 'Word copied.' });
    };
    window.jQuery.ajax = options => {
      const request = window.jQuery.Deferred();
      const values = options.data instanceof FormData ? Object.fromEntries(options.data.entries()) : options.data;
      if (values.action === 'll_tools_get_word_edit_modal_grid') {
        window.editorCalls.push(values);
        request.resolve({ success: true, data: { html: editorHtml, word_id: 101, wordset_id: 7 } });
        return request.promise();
      }
      window.saveCalls.push(values);
      window.finishSave = () => request.resolve({ success: true, data: {
        word_id: Number(values.word_id), word_text: values.word_text, word_translation: values.word_translation,
        word_note: '', recordings: [], lesson_visible: true
      } });
      return request.promise();
    };
  }, { scenario, saveFirst, copyCards, editorHtml: wordMarkup(101, 7, [31, 32]) });
  await page.addScriptTag({ path: path.join(root, 'js/word-grid.js') });
  if (deferredEditor) await page.addScriptTag({ path: path.join(root, 'js/word-edit-modal.js') });
  await page.addScriptTag({ path: path.join(root, 'js/word-copy-dialog.js') });
}

const panelFor = (page, id = 101) => page.locator(`.word-item[data-word-id="${id}"] [data-ll-word-edit-panel]`);
const triggerFor = (page, id = 101) => panelFor(page, id).locator('[data-ll-word-copy]');
const copyDialog = page => page.locator('dialog.ll-word-copy-dialog');

async function openEditor(page, id = 101) {
  await page.getByRole('button', { name: `Edit ${id}`, exact: true }).click();
  await expect(panelFor(page, id)).toBeVisible();
}

async function openCopy(page, id = 101) {
  await triggerFor(page, id).click();
  await expect(copyDialog(page).getByRole('textbox', { name: 'New word title' })).toHaveValue(`Source ${id}`);
}

async function expectCompleted(page, id = 101) {
  await expect(copyDialog(page)).not.toBeVisible();
  await expect(panelFor(page, id)).not.toBeVisible();
  await expect(copyDialog(page).locator('.ll-word-copy-message')).toHaveText('');
  await expect(page.locator('body')).not.toHaveClass(/ll-word-edit-modal-open/);
  await expect(page.locator('#outside-before')).not.toHaveAttribute('inert', '');
}

test('copy popup keeps keyboard focus and Escape returns to the still-open word editor', async ({ page }) => {
  await mount(page);
  await openEditor(page);
  await openCopy(page);
  await expect(copyDialog(page).getByRole('textbox', { name: 'New word title' })).toBeFocused();
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).focus();
  await page.keyboard.press('Tab');
  // Native dialogs can visit browser chrome at the tab boundary, but the
  // underlying editor must never intercept that focus or the next Tab.
  expect(await panelFor(page).evaluate(panel => panel.contains(document.activeElement))).toBe(false);
  await page.keyboard.press('Tab');
  expect(await copyDialog(page).evaluate(dialog => dialog.contains(document.activeElement))).toBe(true);
  await page.keyboard.press('Shift+Tab');
  expect(await panelFor(page).evaluate(panel => panel.contains(document.activeElement))).toBe(false);
  await copyDialog(page).getByRole('textbox', { name: 'New word title' }).focus();
  await page.keyboard.press('Escape');
  await expect(copyDialog(page)).not.toBeVisible();
  await expect(panelFor(page)).toBeVisible();
  await expect(triggerFor(page)).toBeFocused();
  await expect(page.locator('#outside-before')).toHaveAttribute('inert', '');
  await page.keyboard.press('Escape');
  await expect(panelFor(page)).not.toBeVisible();
  await expect(page.getByRole('button', { name: 'Edit 101', exact: true })).toBeFocused();
  await expect(page.locator('#outside-before')).not.toHaveAttribute('inert', '');
});

test('copy dialog remains accessible when reopened after closing its parent editor', async ({ page }) => {
  await mount(page);
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await panelFor(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await openEditor(page);
  await openCopy(page);
  await expect(copyDialog(page)).not.toHaveAttribute('inert', '');
  await expect(copyDialog(page)).not.toHaveAttribute('aria-hidden', 'true');
  await expect(copyDialog(page).getByRole('textbox', { name: 'New word title' })).toBeFocused();
  await copyDialog(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(triggerFor(page)).toBeFocused();
});

for (const changed of ['word title', 'category', 'recording text', 'image']) {
  test(`copy blocks an unsaved ${changed} without losing the edit`, async ({ page }) => {
    await mount(page);
    await openEditor(page);
    if (changed === 'word title') await page.locator('#title-101').fill('Unsaved meaning');
    if (changed === 'category') await panelFor(page).getByRole('checkbox', { name: 'Current category' }).uncheck();
    if (changed === 'recording text') await page.locator('#recording-31').fill('Unsaved recording');
    if (changed === 'image') await panelFor(page).getByLabel('Word image').setInputFiles({ name: 'new-image.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlGIAAAAASUVORK5CYII=', 'base64') });
    await triggerFor(page).click();
    await expect(panelFor(page).locator('[data-ll-word-edit-status]')).toHaveText(saveFirst);
    await expect(copyDialog(page)).not.toBeVisible();
    expect(await page.evaluate(() => window.copyCalls)).toEqual([]);
    await expect(panelFor(page)).toBeVisible();
    if (changed === 'word title') await expect(page.locator('#title-101')).toHaveValue('Unsaved meaning');
    if (changed === 'category') await expect(panelFor(page).getByRole('checkbox', { name: 'Current category' })).not.toBeChecked();
    if (changed === 'recording text') await expect(page.locator('#recording-31')).toHaveValue('Unsaved recording');
    if (changed === 'image') expect(await panelFor(page).getByLabel('Word image').evaluate(input => input.files[0].name)).toBe('new-image.png');
  });
}

test('copy stays blocked while the word save is in flight', async ({ page }) => {
  await mount(page);
  await openEditor(page);
  await page.locator('#title-101').fill('Updated title');
  await panelFor(page).getByRole('button', { name: 'Save', exact: true }).click();
  await expect(page.locator('.word-item[data-word-id="101"]')).toHaveClass(/ll-word-save-pending/);
  await expect(page.getByRole('button', { name: 'Edit 101', exact: true })).toBeDisabled();
  await expect(panelFor(page)).not.toBeVisible();
  expect(await page.evaluate(() => window.copyCalls)).toEqual([]);
  await page.evaluate(() => window.finishSave());
  await expect(page.locator('.word-item[data-word-id="101"]')).not.toHaveClass(/ll-word-save-pending/);
  await openEditor(page);
  await openCopy(page);
});

test('copy requests follow each popup wordset and nonce instead of the page default', async ({ page }) => {
  await mount(page);
  for (const id of [101, 102]) {
    await openEditor(page, id);
    await openCopy(page, id);
    await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
    await expectCompleted(page, id);
  }
  const calls = await page.evaluate(() => window.copyCalls);
  expect(calls.map(({ word_id, wordset_id, nonce, category_id }) => [word_id, wordset_id, nonce, category_id])).toEqual([
    ['101', '7', 'copy-nonce-7', '11'], ['101', '7', 'copy-nonce-7', '11'],
    ['102', '8', 'copy-nonce-8', '11'], ['102', '8', 'copy-nonce-8', '11']
  ]);
});

test('split removes only selected recordings from the editor and public card without navigation', async ({ page }) => {
  await mount(page);
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('checkbox', { name: 'Recording 32 · Published' }).check();
  const before = page.url();
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expectCompleted(page);
  await expect(page.locator('.word-item[data-word-id="101"] [data-recording-id="32"]')).toHaveCount(0);
  await expect(panelFor(page).locator('.ll-word-edit-recording[data-recording-id="31"]')).toHaveCount(1);
  await expect(page.locator('.word-item[data-word-id="101"] .ll-word-recording-row[data-recording-id="31"]')).toHaveCount(1);
  await expect(panelFor(page, 102).locator('.ll-word-edit-recording[data-recording-id="41"]')).toHaveCount(1);
  await expect(page.locator('.word-item[data-word-id="102"] .ll-word-recording-row[data-recording-id="41"]')).toHaveCount(1);
  expect(page.url()).toBe(before);
  const completed = await page.evaluate(() => window.copyCompleted);
  expect(completed).toHaveLength(1);
  expect(completed[0]).toMatchObject({ wordId: 101, wordsetId: 7, moved_ids: [32] });
  await openEditor(page);
  await panelFor(page).getByRole('button', { name: 'Save', exact: true }).click();
  const savedRecordings = await page.evaluate(() => JSON.parse(window.saveCalls.at(-1).recordings));
  expect(savedRecordings.map(recording => recording.id)).toEqual([31]);
  await page.evaluate(() => window.finishSave());
});

test('plain copy shows the gray copied card in its category and permits source editing afterward', async ({ page }) => {
  await mount(page);
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('textbox', { name: 'New word title' }).fill('Another meaning');
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expectCompleted(page);
  const copied = page.locator('[data-ll-word-grid][data-ll-wordset-id="7"][data-ll-category-id="11"] > .word-item[data-word-id="201"]');
  await expect(copied).toHaveCount(1);
  await expect(copied).toHaveClass(/ll-word-item--draft/);
  await expect(copied.locator('[data-ll-word-text]')).toHaveText('Another meaning');
  await expect(copied).toBeInViewport();
  await expect(copied).toBeFocused();
  await expect(page.locator('[data-ll-word-grid][data-ll-wordset-id="8"] > .word-item[data-word-id="201"]')).toHaveCount(0);
  await expect(page.locator('[data-ll-word-grid][data-ll-category-id="12"] > .word-item[data-word-id="201"]')).toHaveCount(0);
  await expect(panelFor(page).locator('.ll-word-edit-recording[data-recording-id]')).toHaveCount(2);
  await expect(page.locator('.word-item[data-word-id="101"] .ll-word-recording-row[data-recording-id]')).toHaveCount(2);
  await openEditor(page);
  await page.locator('#translation-101').fill('New translation');
  await expect(page.locator('#translation-101')).toHaveValue('New translation');
  expect(await page.evaluate(() => window.copyCalls.find(call => call.action === 'll_tools_word_copy_apply').moveIds)).toEqual([]);
});

test('completed copy scrolls to the appended category card after both popups close', async ({ page }) => {
  await mount(page, 'success', { tallCategory: true });
  await openEditor(page);
  await openCopy(page);
  const before = page.url();
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expectCompleted(page);
  const copied = page.locator('[data-ll-word-grid][data-ll-wordset-id="7"][data-ll-category-id="11"] > .word-item[data-word-id="201"]');
  await expect(copied).toBeInViewport();
  await expect(copied).toBeFocused();
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(500);
  expect(page.url()).toBe(before);
});

test('deferred category copy closes the detached editor and preserves the visible category grid', async ({ page }) => {
  await mount(page, 'success', { tallCategory: true, deferredEditor: true });
  const grid = page.locator('#visible-category');
  const originalAttributes = await grid.evaluate(element => Object.fromEntries(Array.from(element.attributes)
    .filter(attr => attr.name === 'id' || attr.name.startsWith('data-')).map(attr => [attr.name, attr.value])));
  await openEditor(page);
  await expect(page.locator('[data-ll-word-edit-modal-grid] .word-item[data-word-id="101"] [data-ll-word-edit-panel]')).toBeVisible();
  expect(await page.evaluate(() => window.editorCalls.map(call => [call.word_id, call.wordset_id, call.category_id]))).toEqual([[101, 7, 11]]);
  await openCopy(page);
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expectCompleted(page);
  const copied = grid.locator(':scope > .word-item[data-word-id="201"]');
  await expect(copied).toHaveCount(1);
  await expect(copied).toHaveClass(/ll-word-item--draft/);
  await expect(copied.locator('[data-ll-word-edit-deferred]')).toHaveCount(1);
  await expect(copied).toBeInViewport();
  await expect(copied).toBeFocused();
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(500);
  await expect(grid.locator(':scope > .word-item[data-word-id="101"]')).toHaveCount(1);
  await expect(grid.locator(':scope > .word-item[data-word-id="104"]')).toHaveCount(1);
  await expect(page.locator('[data-ll-word-edit-modal-grid] .word-item[data-word-id="201"]')).toHaveCount(0);
  await expect(page.locator('[data-ll-word-edit-modal-grid] > .word-item')).toHaveCount(1);
  await expect(grid).toHaveClass(/ll-word-grid--reorderable/);
  for (const [name, value] of Object.entries(originalAttributes)) await expect(grid).toHaveAttribute(name, value);
  expect(await page.evaluate(() => window.copyCalls.map(call => call.category_id))).toEqual(['11', '11']);
});

test('partial split synchronizes confirmed ownership and keeps recovery separate from completion', async ({ page }) => {
  await mount(page, 'pending');
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('checkbox', { name: 'Recording 32 · Published' }).check();
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(copyDialog(page).locator('.ll-word-copy-message')).toHaveText('Review the saved copy.');
  await expect(copyDialog(page)).toBeVisible();
  await expect(panelFor(page)).toBeVisible();
  await expect(page.locator('.word-item[data-word-id="201"]')).toHaveCount(0);
  await expect(page.locator('.word-item[data-word-id="101"] [data-recording-id="32"]')).toHaveCount(0);
  await expect(panelFor(page).locator('.ll-word-edit-recording[data-recording-id="31"]')).toHaveCount(1);
  await expect(page.locator('.word-item[data-word-id="101"] .ll-word-recording-row[data-recording-id="31"]')).toHaveCount(1);
  const events = await page.evaluate(() => ({ updated: window.copySourceUpdates, completed: window.copyCompleted }));
  expect(events.updated).toHaveLength(1);
  expect(events.updated[0]).toMatchObject({ state: 'pending', wordId: 101, wordsetId: 7, moved_ids: [32] });
  expect(events.completed).toEqual([]);
  await expect(copyDialog(page).getByRole('button', { name: 'Check result', exact: true })).toBeEnabled();
  await expect(copyDialog(page).getByRole('button', { name: 'Create copy', exact: true })).toHaveCount(0);
  await expect(copyDialog(page).getByRole('textbox', { name: 'New word title' })).toBeDisabled();
  await expect(copyDialog(page).getByRole('checkbox').first()).toBeDisabled();
  await copyDialog(page).getByRole('button', { name: 'Close', exact: true }).click();
  await triggerFor(page).click();
  await expect(copyDialog(page).locator('.ll-word-copy-message')).toHaveText('Review the saved copy.');
  await expect(copyDialog(page).getByRole('button', { name: 'Check result', exact: true })).toBeEnabled();
  const calls = await page.evaluate(() => window.copyCalls);
  expect(calls.filter(call => call.action === 'll_tools_word_copy_apply')).toHaveLength(1);
  expect(calls.at(-1).action).toBe('ll_tools_word_copy_status');
  expect(calls.at(-1).request_id).toBe(calls.find(call => call.action === 'll_tools_word_copy_apply').request_id);
});

test('a structured copy rejection remains visible and keeps the editor open for recovery', async ({ page }) => {
  await mount(page, 'server_failure');
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(copyDialog(page).locator('.ll-word-copy-message')).toHaveText('A selected recording changed. Reopen the copy dialog. Check the result before starting another copy.');
  await expect(copyDialog(page)).toBeVisible();
  await expect(panelFor(page)).toBeVisible();
  await expect(copyDialog(page).getByRole('button', { name: 'Check result', exact: true })).toBeEnabled();
  await expect(copyDialog(page).getByRole('textbox', { name: 'New word title' })).toBeDisabled();
  await expect(page.locator('.word-item[data-word-id="201"]')).toHaveCount(0);
  expect(await page.evaluate(() => window.copyCompleted)).toEqual([]);
});

test('a lost response checks the same request and reveals the recovered copy without repeating creation', async ({ page }) => {
  await mount(page, 'lost_response');
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('checkbox', { name: 'Recording 32 · Published' }).check();
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(copyDialog(page).locator('.ll-word-copy-message')).toHaveText('Request failed. Check the result before starting another copy.');
  await expect(copyDialog(page)).toBeVisible();
  await expect(copyDialog(page).getByRole('textbox', { name: 'New word title' })).toBeDisabled();
  await copyDialog(page).getByRole('button', { name: 'Check result', exact: true }).click();
  await expectCompleted(page);
  await expect(page.locator('[data-ll-word-grid][data-ll-wordset-id="7"][data-ll-category-id="11"] > .word-item[data-word-id="201"]')).toHaveCount(1);
  await expect(page.locator('.word-item[data-word-id="101"] [data-recording-id="32"]')).toHaveCount(0);
  const calls = await page.evaluate(() => window.copyCalls.filter(call => ['ll_tools_word_copy_apply', 'll_tools_word_copy_status'].includes(call.action)));
  expect(calls.map(call => call.action)).toEqual(['ll_tools_word_copy_apply', 'll_tools_word_copy_status']);
  expect(calls[1].request_id).toBe(calls[0].request_id);
  expect(await page.evaluate(() => sessionStorage.getItem('ll-word-copy:5:7:101'))).toBeNull();
});

test('Escape cannot dismiss either dialog while a copy mutation is running', async ({ page }) => {
  await mount(page, 'slow');
  await openEditor(page);
  await openCopy(page);
  await copyDialog(page).getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(copyDialog(page)).toHaveAttribute('aria-busy', 'true');
  await page.keyboard.press('Escape');
  await expect(copyDialog(page)).toBeVisible();
  await expect(panelFor(page)).toBeVisible();
  await page.evaluate(() => window.finishCopy());
  await expectCompleted(page);
});

test('mobile word editor exposes Copy Split without footer overlap and the nested dialog fits', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page);
  await openEditor(page);
  const buttons = panelFor(page).locator('.ll-word-edit-actions > button');
  const boxes = await buttons.evaluateAll(nodes => nodes.map(node => {
    const rect = node.getBoundingClientRect();
    return { x: rect.x, y: rect.y, right: rect.right, bottom: rect.bottom, width: rect.width, height: rect.height };
  }));
  await expect(triggerFor(page)).toBeVisible();
  for (const box of boxes) {
    expect(box.width).toBeGreaterThan(0);
    expect(box.height).toBeGreaterThan(0);
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.right).toBeLessThanOrEqual(390);
    expect(box.bottom).toBeLessThanOrEqual(844);
  }
  for (let left = 0; left < boxes.length; left++) {
    for (let right = left + 1; right < boxes.length; right++) {
      const a = boxes[left];
      const b = boxes[right];
      expect(a.right <= b.x || b.right <= a.x || a.bottom <= b.y || b.bottom <= a.y).toBe(true);
    }
  }
  await openCopy(page);
  const dialogBox = await copyDialog(page).boundingBox();
  expect(dialogBox.x).toBeGreaterThanOrEqual(0);
  expect(dialogBox.y).toBeGreaterThanOrEqual(0);
  expect(dialogBox.x + dialogBox.width).toBeLessThanOrEqual(390);
  expect(dialogBox.y + dialogBox.height).toBeLessThanOrEqual(844);
  await expect(copyDialog(page).getByRole('button', { name: 'Cancel', exact: true })).toBeInViewport();
  await expect(copyDialog(page).getByRole('button', { name: 'Create copy', exact: true })).toBeInViewport();
});
