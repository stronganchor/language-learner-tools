const { test, expect } = require('@playwright/test');
const path = require('path');
const script = path.resolve(__dirname, '../../../js/word-copy-dialog.js');
const style = path.resolve(__dirname, '../../../css/word-copy-dialog.css');

async function mount(page, scenario = 'success') {
  await page.route('https://ll-copy.test/**', route => route.fulfill({ contentType: 'text/html', body: '<main><div data-ll-wordset-editor-row><button data-ll-word-copy data-word-id="11">Copy / Split</button><div data-recording-id="31">Recording 31</div><div data-recording-id="32">Recording 32</div></div></main>' }));
  await page.goto('https://ll-copy.test/editor');
  await page.evaluate(({ scenario }) => {
    window.llWordCopy = {
      ajaxUrl: '/ajax', wordsetId: 7, userId: 5, nonce: 'nonce', timeoutMs: scenario === 'slow' ? 5000 : 80,
      heading: 'Copy / Split Word', title: 'New word title', recordings: 'Move selected recordings to the copy',
      hint: 'Recordings stay on the original unless selected below.', empty: 'No recordings to move.',
      loading: 'Loading…', saving: 'Copying…', submit: 'Create copy', cancel: 'Cancel', close: 'Close',
      more: 'More recordings', open: 'View copy', retry: 'Retry', check: 'Check result', failed: 'Request failed.',
      uncertain: 'Check the result before starting another copy.', limit: 'Choose at most 50 recordings.',
      storageUnavailable: 'Your browser could not save this copy request.'
    };
    window.calls = []; window.nextPreviewFailure = scenario === 'preview_failure';
    window.fetch = async (url, options) => {
      const params = Object.fromEntries(options.body.entries());
      params.moveIds = options.body.getAll('move_ids[]');
      window.calls.push(params);
      const response = data => new Response(JSON.stringify({ success: true, data }), { status: 200 });
      const completed = {
        state: 'completed', new_word_id: 12, title: params.title || 'Same title', status: 'draft', status_label: 'Draft',
        source_status: 'draft', source_status_label: 'Draft', source_audio_count: 0, source_audio_label: '0 recordings',
        moved_ids: params.moveIds.map(Number), image: {}, url: '/editor?copy=12', message: 'Word copied.'
      };
      if (params.action === 'll_tools_word_copy_preview') {
        if (window.nextPreviewFailure) { window.nextPreviewFailure = false; throw new Error('Preview failed.'); }
        return response({ title: 'Same title', recordings: scenario === 'empty' ? [] : [
          { id: 31, title: 'First recording', status: 'Draft', url: '' },
          { id: 32, title: 'Second recording', status: 'Draft', url: '' }
        ], after_id: 32, has_more: false });
      }
      if (params.action === 'll_tools_word_copy_status') return response(completed);
      if (scenario === 'lost_response') {
        return new Promise((resolve, reject) => options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError'))));
      }
      if (scenario === 'pending') return response({ ...completed, state: 'pending', message: 'Review the saved copy.' });
      if (scenario === 'orphan') return response({ ...completed, state: 'pending', new_word_id: 0, retained_word_id: 12,
        title: '', url: '', image: {}, message: 'Draft word #12 was retained. Ask an administrator to review it.' });
      if (scenario === 'slow') await new Promise(resolve => { window.finishCopy = resolve; });
      return response(completed);
    };
  }, { scenario });
  await page.addStyleTag({ path: style });
  await page.addScriptTag({ path: script });
  await page.getByRole('button', { name: 'Copy / Split', exact: true }).click();
  await expect(page.getByRole('textbox', { name: 'New word title' })).toHaveValue('Same title');
}

test('copy keeps the same title and retains every recording by default without navigating', async ({ page }) => {
  await mount(page);
  await expect(page.getByRole('checkbox').first()).not.toBeChecked();
  await expect(page.getByRole('checkbox').last()).not.toBeChecked();
  const before = page.url();
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Word copied.');
  expect(page.url()).toBe(before);
  const writes = await page.evaluate(() => window.calls.filter(call => call.action === 'll_tools_word_copy_apply'));
  expect(writes).toHaveLength(1); expect(writes[0].title).toBe('Same title'); expect(writes[0].moveIds).toEqual([]);
  await expect(page.locator('[data-recording-id]')).toHaveCount(2);
  await page.locator('dialog').getByRole('button', { name: 'Close', exact: true }).last().click();
  await expect(page.getByRole('button', { name: 'Copy / Split', exact: true })).toBeFocused();
});

test('split sends only explicitly selected recording IDs and updates the source row', async ({ page }) => {
  await mount(page);
  await page.getByRole('checkbox', { name: 'Second recording · Draft' }).check();
  await page.getByRole('textbox', { name: 'New word title' }).fill('Another meaning');
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Word copied.');
  expect(await page.evaluate(() => window.calls.find(call => call.action === 'll_tools_word_copy_apply').moveIds)).toEqual(['32']);
  await expect(page.locator('[data-recording-id="31"]')).toHaveCount(1);
  await expect(page.locator('[data-recording-id="32"]')).toHaveCount(0);
});

test('audio-less source can be copied', async ({ page }) => {
  await mount(page, 'empty');
  await expect(page.getByRole('status')).toHaveText('No recordings to move.');
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Word copied.');
});

test('lost response offers readback and never automatically repeats creation', async ({ page }) => {
  await mount(page, 'lost_response');
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Check result', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'Copy / Split', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Word copied.');
  expect(await page.evaluate(() => window.calls.filter(call => call.action === 'll_tools_word_copy_apply').length)).toBe(1);
  const requests = await page.evaluate(() => window.calls.filter(call => ['ll_tools_word_copy_apply', 'll_tools_word_copy_status'].includes(call.action)));
  expect(requests[0].request_id).toBe(requests[1].request_id);
});

test('uncertain stored result retains readback controls and disables a second mutation', async ({ page }) => {
  await mount(page, 'pending');
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Review the saved copy.');
  await expect(page.getByRole('button', { name: 'Create copy', exact: true })).toHaveCount(0);
  await expect(page.getByRole('textbox')).toBeDisabled();
  await expect(page.locator('dialog').getByRole('link', { name: /View copy/ })).toBeVisible();
});

test('inflight mutation disables duplicate submission and dismissal', async ({ page }) => {
  await mount(page, 'slow');
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.locator('dialog')).toHaveAttribute('aria-busy', 'true');
  await expect(page.getByRole('button', { name: 'Cancel', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Create copy', exact: true })).toBeDisabled();
  await page.evaluate(() => window.finishCopy());
  await expect(page.getByRole('status')).toHaveText('Word copied.');
});

test('session storage write failure blocks mutation and reopening cannot create a duplicate', async ({ page }) => {
  await mount(page);
  await page.evaluate(() => { Storage.prototype.setItem = () => { throw new DOMException('Quota exceeded', 'QuotaExceededError'); }; });
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Your browser could not save this copy request.');
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'Copy / Split', exact: true }).click();
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Your browser could not save this copy request.');
  expect(await page.evaluate(() => window.calls.filter(call => call.action === 'll_tools_word_copy_apply'))).toEqual([]);
});

test('earliest retained draft failure shows ID-only recovery and keeps mutation disabled', async ({ page }) => {
  await mount(page, 'orphan');
  await page.getByRole('button', { name: 'Create copy', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Draft word #12 was retained. Ask an administrator to review it.');
  await expect(page.locator('dialog a')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Check result', exact: true })).toBeEnabled();
  await expect(page.getByRole('textbox')).toBeDisabled();
  expect(await page.evaluate(() => window.calls.filter(call => call.action === 'll_tools_word_copy_apply').length)).toBe(1);
});

test('unreadable session recovery state blocks creation after reopening', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.evaluate(() => { Storage.prototype.getItem = () => { throw new DOMException('Storage denied', 'SecurityError'); }; });
  await page.getByRole('button', { name: 'Copy / Split', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Your browser could not save this copy request.');
  await expect(page.getByRole('button', { name: 'Create copy', exact: true })).toBeDisabled();
  expect(await page.evaluate(() => window.calls.filter(call => call.action === 'll_tools_word_copy_apply'))).toEqual([]);
});
