const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const adminSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/export-import-admin.js'),
  'utf8'
);

function formField(request, name) {
  const body = request.postData() || '';
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = body.match(new RegExp(`name="${escapedName}"\\r?\\n\\r?\\n([^\\r\\n]*)`));
  return match ? match[1] : '';
}

test('STT export uses the bounded job endpoints and shows progress before download', async ({ page }) => {
  const actions = [];
  let runCalls = 0;
  let downloaded = false;

  await page.route('https://example.test/**', async (route) => {
    const request = route.request();
    if (!request.url().includes('/wp-admin/admin-ajax.php')) {
      if (request.url().includes('/wp-admin/admin-post.php')) {
        downloaded = true;
      }
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><body>done</body></html>' });
      return;
    }

    const action = formField(request, 'action');
    actions.push(action);
    if (action === 'll_tools_start_stt_training_export') {
      expect(formField(request, 'll_stt_wordset_id')).toBe('7');
      expect(formField(request, 'll_stt_text_field')).toBe('recording_text');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { token: 'stt-token', batchNonce: 'batch-nonce', statusText: 'Checked 0 recordings; added 0 training samples.', progressRatio: 0 } })
      });
      return;
    }

    expect(action).toBe('ll_tools_run_export_bundle_batch');
    expect(formField(request, 'll_export_token')).toBe('stt-token');
    expect(formField(request, '_wpnonce')).toBe('batch-nonce');
    runCalls += 1;
    if (runCalls === 1) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { token: 'stt-token', batchNonce: 'batch-nonce', status: 'processing', statusText: 'Checked 2 recordings; added 2 training samples.', progressRatio: 0 } })
      });
      return;
    }

    await new Promise((resolve) => setTimeout(resolve, 150));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { token: 'stt-token', status: 'completed', statusText: 'Export ready. Starting download...', progressRatio: 1, downloadUrl: 'https://example.test/wp-admin/admin-post.php?action=download' } })
    });
  });

  await page.goto('https://example.test/');
  await page.setContent(`
    <form id="stt-export">
      <input type="hidden" name="action" value="ll_tools_export_stt_training_bundle">
      <input type="hidden" name="_wpnonce" value="start-nonce">
      <input type="hidden" name="ll_stt_wordset_id" value="7">
      <input type="hidden" name="ll_stt_text_field" value="recording_text">
      <input type="hidden" name="ll_stt_only_reviewed" value="0">
      <button type="submit">Download STT Bundle</button>
    </form>
  `);
  await page.evaluate(() => {
    window.llToolsImportUi = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      exportPageUrl: 'https://example.test/wp-admin/tools.php?page=ll-export',
      exportProcessingTitle: 'Export in progress',
      exportProcessingMessageKeepOpen: 'Keep this window open.',
      exportProcessingMessageBackground: 'The download will start automatically.',
      exportProcessingProgressLabel: 'Preparing export bundle...',
      exportProcessingDone: 'Export ready. Starting download...',
      exportProcessingFailed: 'Export failed.',
      exportProcessingReload: 'Back to export page'
    };
  });
  await page.addScriptTag({ content: adminSource });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));

  await page.getByRole('button', { name: 'Download STT Bundle' }).click();
  await expect(page.locator('.ll-tools-import-processing-screen')).toBeVisible();
  await expect(page.locator('.ll-tools-import-processing-status')).toHaveText('Checked 2 recordings; added 2 training samples.');
  await expect.poll(() => downloaded).toBe(true);
  expect(actions).toEqual([
    'll_tools_start_stt_training_export',
    'll_tools_run_export_bundle_batch',
    'll_tools_run_export_bundle_batch'
  ]);
});
