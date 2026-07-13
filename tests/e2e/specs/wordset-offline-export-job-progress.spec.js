const path = require('path');
const { test, expect } = require('@playwright/test');

const scriptPath = path.resolve(__dirname, '../../../js/wordset-offline-export.js');

function fixtureHtml() {
  return `
    <section data-ll-wordset-offline-export>
      <form data-ll-wordset-offline-export-form>
        <input name="ll_offline_wordset_id" value="17">
        <input name="ll_offline_category_scope" value="custom">
        <input name="ll_offline_category_ids[]" value="31" checked type="checkbox">
        <button type="submit" data-ll-wordset-offline-export-submit>Export Offline App</button>
      </form>
      <div data-ll-wordset-offline-export-job hidden>
        <h3 data-ll-wordset-offline-export-phase></h3>
        <p data-ll-wordset-offline-export-status></p>
        <progress data-ll-wordset-offline-export-progress max="100" value="0"></progress>
        <p data-ll-wordset-offline-export-error hidden></p>
        <button type="button" data-ll-wordset-offline-export-resume hidden>Resume export</button>
        <a data-ll-wordset-offline-export-download hidden>Download Offline App Bundle</a>
      </div>
    </section>
  `;
}

test('wordset manager export advances the resumable job to download', async ({ page }) => {
  await page.setContent(fixtureHtml());
  await page.evaluate(() => {
    window.llWordsetOfflineExportData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      startAction: 'll_tools_offline_app_export_start',
      stepAction: 'll_tools_offline_app_export_step',
      nonce: 'job-nonce',
      currentJob: null,
      strings: { requestFailed: 'Request failed.', paused: 'Export paused' }
    };
    window.offlineExportCalls = [];
    let stepCalls = 0;
    window.fetch = async (_url, options) => {
      const action = String(options.body.get('action') || '');
      window.offlineExportCalls.push(action);
      if (action === 'll_tools_offline_app_export_start') {
        return new Response(JSON.stringify({
          success: true,
          data: {
            token: 'manager-job-token',
            status: 'queued',
            phaseLabel: 'Discovering categories',
            statusText: 'Export queued.',
            progress: 2
          }
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      }

      stepCalls += 1;
      const data = stepCalls === 1
        ? {
            token: 'manager-job-token',
            status: 'processing',
            phaseLabel: 'Preparing words and media manifest',
            statusText: '3 words checked.',
            progress: 40
          }
        : {
            token: 'manager-job-token',
            status: 'completed',
            phaseLabel: 'Offline app bundle ready',
            statusText: 'The bundle is ready.',
            progress: 100,
            downloadUrl: '/download/offline-job.zip'
          };
      return new Response(JSON.stringify({ success: true, data }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' }
      });
    };
  });
  await page.addScriptTag({ path: scriptPath });

  await page.getByRole('button', { name: 'Export Offline App' }).click();
  await expect(page.locator('[data-ll-wordset-offline-export-phase]')).toHaveText('Offline app bundle ready');
  await expect(page.locator('[data-ll-wordset-offline-export-progress]')).toHaveAttribute('value', '100');
  await expect(page.getByRole('link', { name: 'Download Offline App Bundle' })).toHaveAttribute('href', '/download/offline-job.zip');
  await expect(page.getByRole('button', { name: 'Export Offline App' })).toBeEnabled();
  expect(await page.evaluate(() => window.offlineExportCalls)).toEqual([
    'll_tools_offline_app_export_start',
    'll_tools_offline_app_export_step',
    'll_tools_offline_app_export_step'
  ]);
});

test('wordset manager export exposes retry after a resumable request failure', async ({ page }) => {
  await page.setContent(fixtureHtml());
  await page.evaluate(() => {
    window.llWordsetOfflineExportData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      startAction: 'll_tools_offline_app_export_start',
      stepAction: 'll_tools_offline_app_export_step',
      nonce: 'job-nonce',
      currentJob: {
        token: 'resume-job-token',
        status: 'processing',
        phaseLabel: 'Copying bundle assets',
        statusText: '12 assets copied.',
        progress: 70
      },
      strings: { requestFailed: 'Resume from the last batch.', paused: 'Offline app export paused' }
    };
    window.offlineExportStepCalls = 0;
    window.fetch = async () => {
      window.offlineExportStepCalls += 1;
      if (window.offlineExportStepCalls === 1) {
        throw new Error('Temporary network failure.');
      }
      return new Response(JSON.stringify({
        success: true,
        data: {
          token: 'resume-job-token',
          status: 'completed',
          phaseLabel: 'Offline app bundle ready',
          statusText: 'The bundle is ready.',
          progress: 100,
          downloadUrl: '/download/resumed-job.zip'
        }
      }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };
  });
  await page.addScriptTag({ path: scriptPath });

  await expect(page.locator('[data-ll-wordset-offline-export-phase]')).toHaveText('Offline app export paused');
  await expect(page.locator('[data-ll-wordset-offline-export-error]')).toHaveText('Temporary network failure.');
  await expect(page.getByRole('button', { name: 'Resume export' })).toBeVisible();

  await page.getByRole('button', { name: 'Resume export' }).click();
  await expect(page.locator('[data-ll-wordset-offline-export-phase]')).toHaveText('Offline app bundle ready');
  await expect(page.getByRole('link', { name: 'Download Offline App Bundle' })).toHaveAttribute('href', '/download/resumed-job.zip');
  expect(await page.evaluate(() => window.offlineExportStepCalls)).toBe(2);
});
