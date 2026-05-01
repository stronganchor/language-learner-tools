const { test, expect } = require('@playwright/test');
const { createWpPage, deleteWpPage, hasAdminCredentials } = require('../helpers/admin');

test.describe.configure({ timeout: 180000 });

const SAVE_SECTIONS = [
  'study-defaults',
  'learner-accounts',
  'recording-defaults',
  'privacy-retention',
  'plugin-updates',
  'api-providers'
];

const MAINTENANCE_ACTIONS = [
  'flush-quiz-caches',
  'purge-legacy-audio',
  'refresh-languages'
];

async function expectNoHorizontalOverflow(page) {
  const overflow = await page.evaluate(() => {
    const root = document.querySelector('[data-ll-site-tools]');
    const viewportWidth = window.innerWidth;
    const rootRect = root ? root.getBoundingClientRect() : null;
    const overflowingElements = Array.from(document.querySelectorAll('[data-ll-site-tools] *'))
      .filter((element) => {
        const rect = element.getBoundingClientRect();
        return rect.width > 0 && (rect.left < -2 || rect.right > viewportWidth + 2);
      })
      .slice(0, 5)
      .map((element) => {
        const rect = element.getBoundingClientRect();
        return {
          tag: element.tagName.toLowerCase(),
          className: typeof element.className === 'string' ? element.className : '',
          left: Math.round(rect.left),
          right: Math.round(rect.right),
          width: Math.round(rect.width)
        };
      });

    return {
      documentScrollWidth: document.documentElement.scrollWidth,
      viewportWidth,
      rootLeft: rootRect ? Math.round(rootRect.left) : null,
      rootRight: rootRect ? Math.round(rootRect.right) : null,
      overflowingElements
    };
  });

  expect(overflow.documentScrollWidth, JSON.stringify(overflow, null, 2)).toBeLessThanOrEqual(overflow.viewportWidth + 2);
  expect(overflow.rootLeft, JSON.stringify(overflow, null, 2)).toBeGreaterThanOrEqual(-2);
  expect(overflow.rootRight, JSON.stringify(overflow, null, 2)).toBeLessThanOrEqual(overflow.viewportWidth + 2);
  expect(overflow.overflowingElements, JSON.stringify(overflow, null, 2)).toEqual([]);
}

test('Site Tools frontend exposes admin forms and maintenance actions', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for Site Tools E2E tests.');

  const createdPage = await createWpPage(page, {
    title: `E2E Site Tools ${Date.now()}`,
    content: '[ll_site_tools]'
  });

  try {
    await page.goto(createdPage.link, { waitUntil: 'domcontentloaded' });

    const root = page.locator('[data-ll-site-tools]');
    await expect(root).toBeVisible({ timeout: 60000 });
    await expect(root.getByRole('heading', { name: 'Site Tools', level: 1 })).toBeVisible();

    for (const cardTitle of [
      'Workspace Pages',
      'Study Defaults',
      'Learner Accounts',
      'Recording Defaults',
      'Recording Types',
      'Privacy & Retention',
      'Plugin Updates',
      'API Providers',
      'Maintenance',
      'Managed Pages'
    ]) {
      await expect(root.getByRole('heading', { name: cardTitle, exact: true })).toBeVisible();
    }

    for (const section of SAVE_SECTIONS) {
      const form = root.locator(`form:has(input[name="ll_site_tools_section"][value="${section}"])`);
      await expect(form, `${section} save form`).toHaveCount(1);
      await expect(form.locator('input[name="action"][value="ll_tools_save_site_tools"]')).toHaveCount(1);
      await expect(form.locator('input[name="ll_site_tools_nonce"]')).toHaveCount(1);
    }

    const recordingTypesCard = root.locator('.ll-site-tools-card--recording-types');
    await expect(recordingTypesCard).toBeVisible();
    await expect(recordingTypesCard.locator('form.ll-site-tools-recording-add input[name="action"][value="ll_tools_site_tools_recording_type"]')).toHaveCount(1);
    await expect(recordingTypesCard.locator('form.ll-site-tools-recording-add input[name="ll_site_tools_recording_type_action"][value="add"]')).toHaveCount(1);
    await expect(recordingTypesCard.locator('form.ll-site-tools-recording-defaults input[name="ll_site_tools_recording_type_action"][value="defaults"]')).toHaveCount(1);

    const maintenanceCard = root.locator('.ll-site-tools-card--maintenance');
    await expect(maintenanceCard).toBeVisible();
    for (const action of MAINTENANCE_ACTIONS) {
      const form = maintenanceCard.locator(`form.ll-site-tools-maintenance-item:has(input[name="ll_site_tools_maintenance_action"][value="${action}"])`);
      await expect(form, `${action} maintenance form`).toHaveCount(1);
      await expect(form.locator('input[name="action"][value="ll_tools_run_site_tools_maintenance"]')).toHaveCount(1);
      await expect(form.locator('input[name="ll_site_tools_maintenance_nonce"]')).toHaveCount(1);
      await expect(form.getByRole('button', { name: 'Run' })).toBeVisible();
    }

    const flushForm = maintenanceCard.locator('form.ll-site-tools-maintenance-item:has(input[name="ll_site_tools_maintenance_action"][value="flush-quiz-caches"])');
    await Promise.all([
      page.waitForURL((url) => url.searchParams.get('ll_site_tools_notice') === 'cache_flushed', { timeout: 60000 }),
      flushForm.getByRole('button', { name: 'Run' }).click()
    ]);
    await expect(root.locator('.ll-site-tools-notice--success')).toContainText('Flushed quiz caches');

    const managedPagesCard = root.locator('.ll-site-tools-card--managed-pages');
    await expect(managedPagesCard).toBeVisible();
    const managedPagesEmpty = managedPagesCard.locator('.ll-site-tools-card__empty');
    if ((await managedPagesEmpty.count()) > 0) {
      await expect(managedPagesEmpty.first()).toBeVisible();
    } else {
      await expect(managedPagesCard.locator('form.ll-site-tools-page-item__form input[name="action"][value="ll_tools_manage_site_tools_page"]').first()).toBeAttached();
      await expect(managedPagesCard.locator('form.ll-site-tools-page-item__form button').first()).toBeVisible();
    }

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(root).toBeVisible({ timeout: 60000 });
    await expect(root.getByRole('button', { name: 'Save Study Defaults' })).toBeVisible();
    await expect(root.getByRole('button', { name: 'Run' }).first()).toBeVisible();
    await expectNoHorizontalOverflow(page);
  } finally {
    await deleteWpPage(page, createdPage.id);
  }
});
