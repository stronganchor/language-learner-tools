const { test, expect } = require('@playwright/test');
const path = require('path');

const scriptPath = path.resolve(__dirname, '../../../js/export-import-admin.js');

for (const recoveryOnLoad of [true, false]) {
  test(`import recovery ${recoveryOnLoad ? 'on load' : 'after process failure'} offers readback without replay`, async ({ page }) => {
    await page.goto('about:blank');
    await page.setContent('<main></main>');
    await page.evaluate((onLoad) => {
      const recovery = {
        id: 'recovery-job', status: 'running', progressRatio: 0.5,
        statusText: 'Importing words', errorMessage: 'Review the saved checkpoint before recovery.',
        recoveryRequired: true, canResume: false, canDiscard: false
      };
      window.__importRequests = [];
      window.llToolsImportUi = {
        ajaxUrl: '/unused-ajax', importStartAction: 'start', importProcessAction: 'process',
        importDiscardAction: 'discard', importJobNonce: 'nonce',
        processingReload: 'Reload', processingResume: 'Resume import',
        processingDiscard: 'Discard partial import',
        activeImportJob: onLoad ? recovery : { ...recovery, recoveryRequired: false, canResume: true }
      };
      window.fetch = async (url, options) => {
        window.__importRequests.push(options.body.get('action'));
        return new Response(JSON.stringify({
          success: false, data: { message: recovery.errorMessage, job: recovery }
        }), { status: 503, headers: { 'Content-Type': 'application/json' } });
      };
    }, recoveryOnLoad);
    await page.addScriptTag({ path: scriptPath });
    await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
    await expect(page.locator('.ll-tools-import-processing-error')).toHaveText('Review the saved checkpoint before recovery.');
    await expect(page.getByRole('button', { name: 'Reload', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Resume import', exact: true })).toHaveCount(0);
    await expect(page.locator('.ll-tools-import-processing-discard')).toBeHidden();
    expect(await page.locator('.ll-tools-import-processing-reload').evaluate((button) => typeof button._llAction)).not.toBe('function');
    expect(await page.evaluate(() => window.__importRequests)).toEqual(recoveryOnLoad ? [] : ['process']);
  });
}
