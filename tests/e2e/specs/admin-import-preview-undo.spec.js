const { test, expect } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

function psQuote(value) {
  return `'${String(value).replace(/'/g, "''")}'`;
}

function pluginRootPath() {
  return path.resolve(__dirname, '..', '..', '..');
}

function wpContentPath() {
  return path.resolve(pluginRootPath(), '..', '..');
}

function serverImportDirPath() {
  return path.join(wpContentPath(), 'uploads', 'll-tools-imports');
}

function createMinimalImportBundleZip() {
  const serverImportDir = serverImportDirPath();
  fs.mkdirSync(serverImportDir, { recursive: true });

  const nonce = `${Date.now()}-${Math.floor(Math.random() * 1000000)}`;
  const categorySlug = `e2e-admin-import-${nonce}`.toLowerCase();
  const categoryName = `E2E Admin Import ${nonce}`;
  const zipName = `e2e-admin-import-${nonce}.zip`;
  const zipPath = path.join(serverImportDir, zipName);

  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-admin-import-'));
  const dataJsonPath = path.join(tempDir, 'data.json');

  const payload = {
    bundle_type: 'images',
    categories: [
      {
        slug: categorySlug,
        name: categoryName,
        description: 'Playwright admin preview/import/undo test bundle',
        parent_slug: '',
        meta: {
          display_color: ['blue']
        }
      }
    ],
    word_images: [],
    wordsets: [],
    words: [],
    media_estimate: {
      attachment_count: 0,
      attachment_bytes: 0
    }
  };

  fs.writeFileSync(dataJsonPath, JSON.stringify(payload, null, 2), 'utf8');
  if (fs.existsSync(zipPath)) {
    fs.unlinkSync(zipPath);
  }

  const psCommand = [
    '$ErrorActionPreference = "Stop";',
    `Compress-Archive -Path ${psQuote(dataJsonPath)} -DestinationPath ${psQuote(zipPath)} -Force`
  ].join(' ');

  execFileSync('powershell.exe', ['-NoProfile', '-Command', psCommand], {
    stdio: 'pipe'
  });

  if (!fs.existsSync(zipPath)) {
    throw new Error(`Failed to create zip fixture: ${zipPath}`);
  }

  return {
    zipPath,
    zipName,
    categorySlug,
    categoryName,
    cleanup() {
      try {
        if (fs.existsSync(zipPath)) {
          fs.unlinkSync(zipPath);
        }
      } catch (_) {}
      try {
        if (fs.existsSync(dataJsonPath)) {
          fs.unlinkSync(dataJsonPath);
        }
      } catch (_) {}
      try {
        if (fs.existsSync(tempDir)) {
          fs.rmdirSync(tempDir);
        }
      } catch (_) {}
    }
  };
}

async function ensureLoggedIntoImportPage(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/tools.php?page=ll-import');
  await expect(page).toHaveURL(/\/wp-admin\/tools\.php\?page=ll-import/);
  await discardPausedImportJobIfPresent(page);
  await expect(previewImportForm(page).locator('#ll_import_existing')).toBeVisible({ timeout: 60000 });
}

async function discardPausedImportJobIfPresent(page) {
  const processingScreen = page.locator('#ll-tools-import-processing-screen');
  const isProcessingVisible = await processingScreen.isVisible({ timeout: 1000 }).catch(() => false);
  if (!isProcessingVisible) {
    return;
  }

  const discardButton = page.locator('.ll-tools-import-processing-discard').first();
  await discardButton.waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
  const canDiscard = await discardButton.isVisible().catch(() => false);
  if (!canDiscard) {
    return;
  }

  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 120000 }).catch(() => {}),
    discardButton.click({ timeout: 10000, noWaitAfter: true })
  ]);
  await page.waitForLoadState('domcontentloaded', { timeout: 60000 }).catch(() => {});
  await page.waitForFunction(() => (
    !document.documentElement.classList.contains('ll-tools-import-processing')
  ), null, { timeout: 60000 }).catch(() => {});
}

function previewImportForm(page) {
  return page.locator('form').filter({
    has: page.locator('input[name="action"][value="ll_tools_preview_import_bundle"]')
  }).first();
}

function confirmImportForm(page) {
  return page.locator('form').filter({
    has: page.locator('input[name="action"][value="ll_tools_import_bundle"]')
  }).first();
}

async function clickAndWaitForUrlState(page, button, urlPredicate, timeout = 120000) {
  await button.click({ timeout, noWaitAfter: true });
  await expect.poll(() => {
    try {
      return urlPredicate(new URL(page.url()));
    } catch (_) {
      return false;
    }
  }, {
    timeout,
    message: 'URL did not reach the expected admin import state'
  }).toBe(true);
}

async function clickAndWaitForAdminPost(page, button, action, timeout = 120000) {
  await Promise.all([
    page.waitForResponse((response) => {
      const request = response.request();
      let responseUrl;
      try {
        responseUrl = new URL(response.url());
      } catch (_) {
        return false;
      }

      return request.method() === 'POST'
        && /\/wp-admin\/admin-post\.php$/.test(responseUrl.pathname)
        && (request.postData() || '').includes(`action=${encodeURIComponent(action)}`);
    }, { timeout }),
    button.click({ timeout, noWaitAfter: true })
  ]);
}

test('admin import page previews, imports, and undoes a minimal server zip bundle', async ({ page }) => {
  test.setTimeout(360000);
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for admin E2E tests.');

  const fixture = createMinimalImportBundleZip();

  try {
    await ensureLoggedIntoImportPage(page);

    const previewForm = previewImportForm(page);
    await expect(previewForm.locator('#ll_import_existing')).toBeVisible({ timeout: 30000 });

    await previewForm.locator('#ll_import_existing').selectOption({ value: fixture.zipName });
    await clickAndWaitForUrlState(
      page,
      previewForm.locator('button[type="submit"]'),
      (url) => url.searchParams.has('ll_import_preview')
    );

    await expect(page.locator('#ll-tools-import-preview')).toBeVisible({ timeout: 60000 });
    await expect(page.locator('.ll-tools-import-category-list')).toContainText(fixture.categoryName);
    await expect(confirmImportForm(page)).toBeVisible();

    const importForm = confirmImportForm(page);
    await clickAndWaitForUrlState(
      page,
      importForm.locator('button[type="submit"]'),
      (url) => (
        /\/wp-admin\/tools\.php$/.test(url.pathname)
        && url.searchParams.get('page') === 'll-import'
        && !url.searchParams.has('ll_import_preview')
      )
    );

    const importRow = page.locator('.ll-tools-recent-imports-table tbody tr').filter({ hasText: fixture.zipName }).first();
    await expect(importRow).toBeVisible({ timeout: 120000 });
    await expect(importRow.locator('.ll-tools-undo-import-button')).toBeVisible({ timeout: 60000 });
    const categoriesDetails = importRow.locator('.ll-tools-recent-imports-categories');
    await expect(categoriesDetails).toBeVisible({ timeout: 60000 });
    await categoriesDetails.locator('summary').click();
    await expect(categoriesDetails).toContainText(fixture.categoryName);

    page.once('dialog', (dialog) => dialog.accept());
    await clickAndWaitForAdminPost(page, importRow.locator('.ll-tools-undo-import-button'), 'll_tools_undo_import');

    const undoneRow = page.locator('.ll-tools-recent-imports-table tbody tr').filter({ hasText: fixture.zipName }).first();
    await expect(undoneRow).toBeVisible({ timeout: 60000 });
    await expect(undoneRow.locator('.ll-tools-undo-import-button')).toHaveCount(0);
    await expect(undoneRow.locator('.ll-tools-import-undone-label')).toBeVisible({ timeout: 60000 });
  } finally {
    fixture.cleanup();
  }
});
