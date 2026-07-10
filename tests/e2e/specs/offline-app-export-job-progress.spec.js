const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

function formField(request, name) {
  const body = request.postData() || '';
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const multipartMatch = body.match(new RegExp(`name="${escapedName}"\\r?\\n\\r?\\n([^\\r\\n]*)`));
  if (multipartMatch) {
    return multipartMatch[1];
  }
  return new URLSearchParams(body).get(name) || '';
}

test('offline app export admin shows resumable phase progress and a prepared download', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for admin E2E tests.');

  const actions = [];
  let stepCalls = 0;
  let startCalls = 0;
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const action = formField(route.request(), 'action');
    if (!action.startsWith('ll_tools_offline_app_export_')) {
      await route.continue();
      return;
    }
    actions.push(action);

    if (action === 'll_tools_offline_app_export_categories') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: { categories: [{ id: 71, slug: 'bounded-export', name: 'Bounded Export' }] }
        })
      });
      return;
    }

    if (action === 'll_tools_offline_app_export_start') {
      startCalls += 1;
      expect(formField(route.request(), 'll_offline_wordset_id')).not.toBe('');
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            token: startCalls === 1 ? 'offline-job-token' : 'offline-failed-token',
            status: 'queued',
            phase: 'categories',
            phaseLabel: 'Discovering categories',
            statusText: '0 categories checked, 0 words checked, 0 assets copied.',
            progress: 2
          }
        })
      });
      return;
    }

    expect(action).toBe('ll_tools_offline_app_export_step');
    const token = formField(route.request(), 'token');
    if (token === 'offline-failed-token') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            token,
            status: 'failed',
            phase: 'failed',
            phaseLabel: 'Offline app export failed',
            statusText: 'The export stopped before a bundle was created.',
            error: 'A required media file disappeared.',
            progress: 55
          }
        })
      });
      return;
    }
    expect(token).toBe('offline-job-token');
    stepCalls += 1;
    const data = stepCalls === 1
      ? {
          token: 'offline-job-token',
          status: 'processing',
          phase: 'words',
          phaseLabel: 'Preparing words and media manifest',
          statusText: '1 category checked, 3 words checked, 0 assets copied.',
          progress: 35
        }
      : {
          token: 'offline-job-token',
          status: 'completed',
          phase: 'completed',
          phaseLabel: 'Offline app bundle ready',
          statusText: 'The offline app bundle is ready to download.',
          progress: 100,
          downloadUrl: '/wp-admin/admin-post.php?action=ll_tools_download_offline_app_export&token=offline-job-token'
        };
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data })
    });
  });

  await ensureLoggedIntoAdmin(page, '/wp-admin/tools.php?page=ll-offline-app-export');
  const wordset = page.locator('#ll-offline-wordset-id');
  await expect(wordset).toBeVisible({ timeout: 60000 });
  const options = await wordset.locator('option').count();
  test.skip(options < 2, 'At least one word set is required for the offline export admin browser test.');
  await wordset.selectOption({ index: 1 });
  await expect(page.locator('#ll-offline-category-fieldset')).toBeEnabled();

  await page.getByRole('button', { name: 'Build Offline App Bundle' }).click();
  const panel = page.locator('#ll-offline-export-job');
  await expect(panel).toBeVisible();
  await expect(page.locator('#ll-offline-export-job-phase')).toHaveText('Offline app bundle ready');
  await expect(page.locator('#ll-offline-export-job-progress')).toHaveAttribute('value', '100');
  await expect(page.locator('#ll-offline-export-job-download')).toBeVisible();
  await expect(page.locator('#ll-offline-export-job-download')).toHaveAttribute('href', /ll_tools_download_offline_app_export/);

  await page.getByRole('button', { name: 'Build Offline App Bundle' }).click();
  await expect(page.locator('#ll-offline-export-job-phase')).toHaveText('Offline app export failed');
  await expect(page.locator('#ll-offline-export-job-error')).toHaveText('A required media file disappeared.');
  await expect(page.locator('#ll-offline-export-job-download')).toBeHidden();
  await expect(page.getByRole('button', { name: 'Build Offline App Bundle' })).toBeEnabled();
  expect(actions).toEqual([
    'll_tools_offline_app_export_categories',
    'll_tools_offline_app_export_start',
    'll_tools_offline_app_export_step',
    'll_tools_offline_app_export_step',
    'll_tools_offline_app_export_start',
    'll_tools_offline_app_export_step'
  ]);
});
