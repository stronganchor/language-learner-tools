const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

test.describe.configure({ timeout: 180000 });

test('WebP optimizer admin page hydrates the queue shell', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for admin E2E tests.');

  await ensureLoggedIntoAdmin(page, '/wp-admin/tools.php?page=ll-image-webp-optimizer');

  const root = page.locator('[data-ll-webp-optimizer-root]');
  await expect(root).toBeVisible({ timeout: 60000 });
  await expect(root.locator('[data-ll-webp-filter-category]')).toBeVisible();
  await expect(root.locator('[data-ll-webp-filter-search]')).toBeVisible();
  await expect(root.locator('[data-ll-webp-refresh]')).toBeVisible();
  await expect(root.locator('[data-ll-webp-convert-all]')).toBeVisible();

  await expect(root.locator('[data-ll-webp-summary] .ll-webp-stat-card:not(.is-placeholder)')).toHaveCount(4, {
    timeout: 60000
  });
  await expect(root.locator('[data-ll-webp-cards] .ll-webp-card, [data-ll-webp-cards] .ll-webp-empty').first()).toBeVisible({
    timeout: 60000
  });
});

test('orphaned media admin page exposes review filters and scan controls', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for admin E2E tests.');

  await ensureLoggedIntoAdmin(page, '/wp-admin/tools.php?page=ll-orphan-media');

  const root = page.locator('.ll-orphan-media-admin');
  await expect(root).toBeVisible({ timeout: 60000 });
  await expect(root.locator('#ll-orphan-media-kind')).toBeVisible();
  await expect(root.locator('#ll-orphan-media-search')).toBeVisible();
  await expect(root.getByRole('button', { name: /Refresh Scan/i })).toBeVisible();
  await expect(root.locator('.ll-orphan-media-admin__summary-card')).toHaveCount(4);
  await expect(root.locator('#ll-orphan-media-bulk-form, .notice.inline').first()).toBeVisible({
    timeout: 60000
  });
});
