const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const source = fs.readFileSync(path.resolve(__dirname, '../../../js/content-lesson-cloze.js'), 'utf8');
const css = fs.readFileSync(path.resolve(__dirname, '../../../css/content-lesson-cloze.css'), 'utf8');
const adminSource = fs.readFileSync(path.resolve(__dirname, '../../../js/content-lesson-cloze-admin.js'), 'utf8');
const i18n = {
  instructions: 'Drag a word into each blank, or select a word and then a blank.', bank: 'Word bank', blank: 'Blank %d',
  filled: 'Blank %1$d: %2$s. Select to return this word.', selected: 'Word selected. Choose a blank.',
  placed: 'Word placed.', returned: 'Word returned to the bank.', check: 'Check', checking: 'Checking...', reset: 'Start again',
  incomplete: 'Fill every blank first.', retry: 'Try the marked blanks again.', correct: 'All correct!', saved: 'Progress saved.',
  saveError: 'Your answers are correct, but progress could not be saved. Check again to retry.', error: 'Answers could not be checked. Please try again.',
  correctBlank: 'Correct', incorrectBlank: 'Try again'
};
const hebrew = {
  lessonId: 57, version: 'version', salt: 'salt', signature: 'signature',
  segments: [
    { text: 'וַיָּבֹא אֵלַי עֶגְלוֹן׃ לָמָּה ' }, { blank: 0 }, { text: ' אֵלַי? כִּי רָאָה עֶגְלוֹן כִּי ' },
    { blank: 1 }, { text: ' בְיָדִי׃ עַל־כֵּן בָּא ' }, { blank: 2 }, { text: '׃ וַיָּבֹא אֵלַי עֶגְלוֹן ' },
    { blank: 3 }, { text: ' מִן־הֶעָלֶה׃' }
  ],
  bank: [{ id: 'c', text: 'אֵלַי' }, { id: 'a', text: 'בָּא' }, { id: 'd', text: 'וַיֹּאכַל' }, { id: 'b', text: 'עָלֶה' }]
};
async function mount(page, { payload = hebrew, handler, timeout = 1000 } = {}) {
  const requests = [];
  await page.route('https://example.test/**', async (route) => {
    if (!route.request().url().includes('admin-ajax')) {
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html>' }); return;
    }
    const params = new URLSearchParams(route.request().postData());
    const attempt = JSON.parse(params.get('attempt'));
    requests.push(attempt);
    if (handler) { await handler(route, attempt, requests.length); return; }
    const correct = attempt.choices.map((id, i) => id === ['a', 'b', 'c', 'd'][i]);
    await route.fulfill({ json: { success: true, data: { correct, allCorrect: correct.every(Boolean), saved: correct.every(Boolean), saveError: false } } });
  });
  await page.goto('https://example.test/');
  await page.setContent(`<style>${css}</style><main style="max-width:860px;margin:auto"><section class="ll-cloze" data-ll-cloze><script type="application/json" data-ll-cloze-payload>${JSON.stringify(payload).replace(/</g, '\\u003c')}</script></section></main>`);
  await page.evaluate(({ strings, requestTimeoutMs }) => { window.llToolsCloze = { ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php', nonce: 'nonce', i18n: strings, requestTimeoutMs }; }, { strings: i18n, requestTimeoutMs: timeout });
  await page.addScriptTag({ content: source });
  return requests;
}
const token = (page, id) => page.locator(`[data-ll-cloze-token="${id}"]`);
const slot = (page, index) => page.locator(`[data-ll-cloze-slot="${index}"]`);
async function place(page, id, index) { await token(page, id).click(); await slot(page, index).click(); }
async function solve(page) { for (const [index, id] of ['a', 'b', 'c', 'd'].entries()) { await place(page, id, index); } }

test('Hebrew lesson opens directly with blank passage and keyboard usable bank, then checks completion once', async ({ page }) => {
  const requests = await mount(page);
  await page.addScriptTag({ content: source });
  await expect(page.locator('[data-ll-cloze-slot]')).toHaveCount(4);
  await expect(page.locator('.ll-cloze__word:visible')).toHaveCount(4);
  await expect(page.locator('.ll-cloze__passage')).not.toContainText('וַיֹּאכַל');
  await expect(page.locator('.ll-cloze__passage')).toHaveCSS('direction', 'rtl');
  await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText(i18n.incomplete);
  expect(requests).toHaveLength(0);
  await token(page, 'a').focus(); await page.keyboard.press('Enter');
  await slot(page, 0).focus(); await page.keyboard.press('Space');
  await expect(slot(page, 0)).toContainText('בָּא');
  await place(page, 'b', 1); await place(page, 'c', 2); await place(page, 'd', 3);
  await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('All correct! Progress saved.');
  await expect(page.locator('.is-correct')).toHaveCount(4);
  expect(requests).toHaveLength(1);
  expect(requests[0].choices).toEqual(['a', 'b', 'c', 'd']);
  await page.getByRole('button', { name: 'Start again' }).click();
  await expect(page.locator('.ll-cloze__word:visible')).toHaveCount(4);
  await expect(page.locator('.is-correct')).toHaveCount(0);
  await expect(page.locator('.ll-cloze')).not.toHaveClass(/is-complete/);
});

test('drag/drop permits incorrect attempts, moving a filled word and returning answers for retry', async ({ page }) => {
  await mount(page);
  await token(page, 'b').dragTo(slot(page, 0));
  await token(page, 'a').dragTo(slot(page, 1));
  await place(page, 'c', 2); await place(page, 'd', 3);
  await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText(i18n.retry);
  await expect(page.locator('.is-incorrect')).toHaveCount(2);
  await slot(page, 0).dragTo(slot(page, 1));
  await expect(token(page, 'a')).toBeVisible();
  await expect(slot(page, 1)).toContainText('עָלֶה');
  await place(page, 'a', 0);
  await slot(page, 3).focus(); await page.keyboard.press('Delete');
  await expect(token(page, 'd')).toBeVisible();
  await place(page, 'd', 3);
  await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toContainText(i18n.correct);
});

test('failed save does not claim saved progress and offers a safe explicit recheck', async ({ page }) => {
  const requests = await mount(page, { handler: async (route, attempt, number) => {
    await route.fulfill({ json: { success: true, data: { correct: [true, true, true, true], allCorrect: true, saved: number > 1, saveError: number === 1 } } });
  } });
  await solve(page); await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toContainText(i18n.saveError);
  await expect(page.getByRole('button', { name: 'Check', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('All correct! Progress saved.');
  expect(requests).toHaveLength(2);
});

test('check timeout preserves placed answers, unlocks controls and never reports completion', async ({ page }) => {
  await mount(page, { timeout: 40, handler: async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 150));
    await route.fulfill({ json: { success: true, data: { correct: [true, true, true, true], allCorrect: true, saved: true } } }).catch(() => {});
  } });
  await solve(page); await page.getByRole('button', { name: 'Check', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText(i18n.error);
  await expect(page.getByRole('button', { name: 'Check', exact: true })).toBeEnabled();
  await expect(slot(page, 0)).toContainText('בָּא');
  await expect(page.locator('.is-correct')).toHaveCount(0);
});

test('duplicate bank tokens remain independent and literal text is not interpreted as HTML', async ({ page }) => {
  const payload = { ...hebrew, segments: [{ blank: 0 }, { text: ' ' }, { blank: 1 }, { text: ' ' }, { blank: 2 }], bank: [{ id: 'one', text: 'same' }, { id: 'two', text: 'same' }, { id: 'three', text: '<img src=x onerror=alert(1)>' }] };
  await mount(page, { payload });
  await expect(page.getByRole('button', { name: 'same', exact: true })).toHaveCount(2);
  await place(page, 'two', 0); await place(page, 'one', 1); await place(page, 'three', 2);
  await expect(page.locator('img')).toHaveCount(0);
  await expect(slot(page, 2)).toContainText('<img src=x onerror=alert(1)>');
});

test('mobile touch drag and long allowed answers stay inside a 320px page', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 850 });
  const payload = { ...hebrew, segments: [{ text: 'לָמָּה ' }, { blank: 0 }, { text: '?' }, { blank: 1 }], bank: [{ id: 'a', text: 'בָּא' }, { id: 'b', text: 'x'.repeat(300) }] };
  await mount(page, { payload });
  const from = await token(page, 'a').boundingBox(); const to = await slot(page, 0).boundingBox();
  await token(page, 'a').dispatchEvent('pointerdown', { pointerId: 1, pointerType: 'touch', clientX: from.x + 8, clientY: from.y + 8 });
  await token(page, 'a').dispatchEvent('pointermove', { pointerId: 1, pointerType: 'touch', clientX: to.x + 8, clientY: to.y + 8 });
  await token(page, 'a').dispatchEvent('pointerup', { pointerId: 1, pointerType: 'touch', clientX: to.x + 8, clientY: to.y + 8 });
  await expect(slot(page, 0)).toContainText('בָּא');
  await place(page, 'b', 1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await expect(slot(page, 0)).toHaveCSS('direction', 'rtl');
});

test('author autosave serializes changes and does not show a stale Saved acknowledgement', async ({ page }) => {
  const calls = [];
  await page.route('https://example.test/**', async (route) => {
    if (!route.request().url().includes('admin-ajax')) { await route.fulfill({ body: '<!doctype html>' }); return; }
    const params = new URLSearchParams(route.request().postData()); calls.push(params);
    await new Promise((resolve) => setTimeout(resolve, calls.length === 1 ? 800 : 20));
    await route.fulfill({ json: { success: true, data: { hash: `hash${calls.length}`, count: 1 } } });
  });
  await page.goto('https://example.test/');
  await page.setContent('<form><select id="ll-content-lesson-kind"><option value="cloze">Cloze</option></select><select id="ll-content-lesson-wordset"><option value="7">Set</option></select><input name="ll_content_lesson_cloze_hash" value="hash0"><textarea id="ll-content-lesson-cloze-source" data-saved-kind="cloze" data-source-hash="hash0" data-lesson-id="57">A [[cat]].</textarea><p data-ll-cloze-save-status></p><button type="button" data-ll-cloze-save-retry hidden>Retry</button></form>');
  await page.evaluate(() => { window.llToolsClozeAdmin = { ajaxUrl: 'https://example.test/admin-ajax.php', nonce: 'nonce', i18n: { saving: 'Saving...', saved: 'Saved.', valid: '%d blanks ready.', invalid: 'Invalid.', pending: 'Save settings.', error: 'Error.' } }; });
  await page.addScriptTag({ content: adminSource });
  const input = page.locator('textarea'); const status = page.locator('[data-ll-cloze-save-status]');
  await input.fill('A [[dog]].'); await expect.poll(() => calls.length).toBe(1);
  await input.fill('A [[bird]].'); await page.waitForTimeout(850);
  await expect(status).not.toContainText('Saved.');
  await expect.poll(() => calls.length).toBe(2);
  await expect(status).toHaveText('Saved. 1 blanks ready.');
  expect(calls[1].get('previous_hash')).toBe('hash1');
  expect(calls[1].get('source')).toBe('A [[bird]].');
  await expect(page.locator('[name="ll_content_lesson_cloze_hash"]')).toHaveValue('hash2');
});

async function mountAdmin(page, handler) {
  await page.route('https://example.test/**', async (route) => {
    if (!route.request().url().includes('admin-ajax')) { await route.fulfill({ body: '<!doctype html>' }); return; }
    await handler(route, new URLSearchParams(route.request().postData()));
  });
  await page.goto('https://example.test/');
  await page.setContent('<form><select id="ll-content-lesson-kind"><option value="cloze">Cloze</option></select><select id="ll-content-lesson-wordset"><option value="7">Set</option></select><input name="ll_content_lesson_cloze_hash" value="hash0"><textarea id="ll-content-lesson-cloze-source" data-saved-kind="cloze" data-source-hash="hash0" data-lesson-id="57">A [[cat]].</textarea><p data-ll-cloze-save-status></p><button type="button" data-ll-cloze-save-retry hidden>Retry</button><button type="submit">Update</button></form>');
  await page.evaluate(() => { window.llToolsClozeAdmin = { ajaxUrl: 'https://example.test/admin-ajax.php', nonce: 'nonce', i18n: { saving: 'Saving...', saved: 'Saved.', valid: '%d blanks ready.', invalid: 'Invalid.', pending: 'Save settings.', error: 'Error.' } }; });
  await page.addScriptTag({ content: adminSource });
}

test('Update waits for an in-flight save and the latest draft before normal form submission', async ({ page }) => {
  const calls = [];
  let releaseFirst;
  const firstReady = new Promise((resolve) => { releaseFirst = resolve; });
  await mountAdmin(page, async (route, params) => {
    calls.push(params);
    const number = calls.length;
    if (number === 1) { await firstReady; }
    await route.fulfill({ json: { success: true, data: { hash: `hash${number}`, count: 1 } } });
  });
  await page.evaluate(() => {
    window.normalSubmissions = [];
    document.querySelector('form').addEventListener('submit', (event) => {
      if (!event.defaultPrevented) {
        window.normalSubmissions.push({ source: document.querySelector('textarea').value, hash: document.querySelector('[name="ll_content_lesson_cloze_hash"]').value });
        event.preventDefault();
      }
    });
  });
  await page.locator('textarea').fill('A [[dog]].');
  await expect.poll(() => calls.length).toBe(1);
  await page.locator('textarea').fill('A [[bird]].');
  await page.getByRole('button', { name: 'Update', exact: true }).click();
  expect(await page.evaluate(() => window.normalSubmissions)).toEqual([]);
  releaseFirst();
  await expect.poll(() => calls.length).toBe(2);
  await expect.poll(() => page.evaluate(() => window.normalSubmissions.length)).toBe(1);
  expect(calls[1].get('source')).toBe('A [[bird]].');
  expect(calls[1].get('previous_hash')).toBe('hash1');
  expect(await page.evaluate(() => window.normalSubmissions)).toEqual([{ source: 'A [[bird]].', hash: 'hash2' }]);
});

test('lost response remains dirty after reverting text, and Retry recovers the original attempt before saving the reversion', async ({ page }) => {
  const calls = [];
  await mountAdmin(page, async (route, params) => {
    calls.push(params);
    if (calls.length === 1) {
      await new Promise((resolve) => setTimeout(resolve, 150));
      await route.abort('failed'); return;
    }
    await route.fulfill({ json: { success: true, data: { hash: `hash${calls.length}`, count: 1 } } });
  });
  await page.locator('textarea').fill('A [[dog]].');
  await expect.poll(() => calls.length).toBe(1);
  await page.locator('textarea').fill('A [[cat]].');
  await expect(page.locator('[data-ll-cloze-save-status]')).toHaveText('Error.');
  expect(await page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented;
  })).toBe(true);
  await page.getByRole('button', { name: 'Retry' }).click();
  await expect.poll(() => calls.length).toBe(3);
  await expect(page.locator('[data-ll-cloze-save-status]')).toHaveText('Saved. 1 blanks ready.');
  expect(calls[1].get('source')).toBe('A [[dog]].');
  expect(calls[1].get('previous_hash')).toBe('hash0');
  expect(calls[2].get('source')).toBe('A [[cat]].');
  expect(calls[2].get('previous_hash')).toBe('hash2');
});
