const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 360000 });

const fixtureScript = path.resolve(
  __dirname,
  '..',
  'fixtures',
  'seed-private-wordset-access.php'
);

function runFixture(command, ...args) {
  return runWpCliJson(['eval-file', fixtureScript, command, ...args], { timeoutMs: 240000 });
}

async function loginAs(page, user) {
  await page.context().clearCookies();
  await page.goto('/wp-login.php?reauth=1', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#loginform')).toBeVisible({ timeout: 30000 });
  await page.locator('#user_login').fill(user.login);
  await page.locator('#user_pass').fill(user.password);
  await Promise.all([
    page.waitForURL((url) => !/\/wp-login\.php(?:$|[?\/])/.test(url.toString()), {
      timeout: 60000
    }),
    page.locator('#wp-submit').click()
  ]);
}

function isPrivateLazyCardsResponse(response, wordsetId) {
  if (!response.url().includes('/wp-admin/admin-ajax.php')) {
    return false;
  }
  const postData = response.request().postData() || '';
  return postData.includes('action=ll_tools_wordset_page_lazy_cards')
    && postData.includes(`wordset_id=${wordsetId}`);
}

test('private wordset route and lazy cards admit only the assigned manager', async ({ page }) => {
  let fixture;
  let cleanupRequired = false;

  try {
    try {
      cleanupRequired = true;
      fixture = runFixture('seed');
    } catch (error) {
      if (error && error.isWpCliUnavailable) {
        cleanupRequired = false;
        test.skip(true, `Unable to seed the WordPress private-wordset fixture: ${error.message}`);
        return;
      }
      throw error;
    }

    expect(fixture.categoryCount).toBeGreaterThan(18);
    await loginAs(page, fixture.manager);

    const lazyResponsePromise = page.waitForResponse(
      (response) => isPrivateLazyCardsResponse(response, fixture.wordsetId),
      { timeout: 90000 }
    );
    const managerResponse = await page.goto(fixture.pagePath, { waitUntil: 'domcontentloaded' });
    expect(managerResponse && managerResponse.status()).toBe(200);
    await expect(page.locator('.ll-wordset-page:not(.ll-wordset-page--missing)')).toBeVisible({ timeout: 60000 });
    await expect(page.getByRole('heading', { name: fixture.wordsetName, exact: true })).toBeVisible();
    await expect(page.locator('.ll-wordset-card[data-cat-id]').first()).toBeVisible({ timeout: 60000 });
    await expect(page.locator('.ll-wordset-card__title').filter({ hasText: fixture.firstCategoryName })).toBeVisible();

    const sentinel = page.locator('[data-ll-wordset-load-more-sentinel]');
    await expect(sentinel).toHaveCount(1);
    await sentinel.scrollIntoViewIfNeeded();
    const lazyResponse = await lazyResponsePromise;
    const lazyResponseText = await lazyResponse.text();
    let lazyFailureDiagnostics = null;
    if (lazyResponse.status() !== 200) {
      const requestParams = new URLSearchParams(lazyResponse.request().postData() || '');
      lazyFailureDiagnostics = runFixture('inspect-lazy', requestParams.get('token') || '');
    }
    expect(
      lazyResponse.status(),
      `Assigned-manager lazy-card request failed: ${lazyResponseText.slice(0, 1000)}; `
        + `diagnostics=${JSON.stringify(lazyFailureDiagnostics)}`
    ).toBe(200);
    const lazyPayload = JSON.parse(lazyResponseText);
    expect(lazyPayload.success).toBe(true);
    expect(String(lazyPayload.data?.html || '')).toContain(fixture.lastCategoryName);

    await expect(page.locator('.ll-wordset-card[data-cat-id]')).toHaveCount(
      fixture.categoryCount,
      { timeout: 90000 }
    );
    await expect(page.locator('.ll-wordset-card__title').filter({ hasText: fixture.lastCategoryName })).toBeVisible();
    await expect(page.locator('.ll-wordset-page--missing')).toHaveCount(0);

    await loginAs(page, fixture.outsider);
    let outsiderLazyRequests = 0;
    const outsiderRequestListener = (request) => {
      const postData = request.postData() || '';
      if (request.url().includes('/wp-admin/admin-ajax.php')
        && postData.includes('action=ll_tools_wordset_page_lazy_cards')) {
        outsiderLazyRequests += 1;
      }
    };
    page.on('request', outsiderRequestListener);
    const outsiderResponse = await page.goto(fixture.pagePath, { waitUntil: 'domcontentloaded' });
    expect(outsiderResponse && outsiderResponse.status()).toBe(404);
    await expect(page.locator('.ll-wordset-page--missing')).toBeVisible({ timeout: 60000 });
    await expect(page.locator('.ll-wordset-empty')).toHaveText('Word set not found.');
    await expect(page.locator('.ll-wordset-card[data-cat-id]')).toHaveCount(0);
    await page.waitForTimeout(1000);
    expect(outsiderLazyRequests).toBe(0);
    page.off('request', outsiderRequestListener);

    await page.context().clearCookies();
    let anonymousLazyRequests = 0;
    const anonymousRequestListener = (request) => {
      const postData = request.postData() || '';
      if (request.url().includes('/wp-admin/admin-ajax.php')
        && postData.includes('action=ll_tools_wordset_page_lazy_cards')) {
        anonymousLazyRequests += 1;
      }
    };
    page.on('request', anonymousRequestListener);
    const anonymousResponse = await page.goto(fixture.pagePath, { waitUntil: 'domcontentloaded' });
    expect(anonymousResponse && anonymousResponse.status()).toBe(404);
    await expect(page.locator('.ll-wordset-page--missing')).toBeVisible({ timeout: 60000 });
    await expect(page.locator('.ll-wordset-empty')).toHaveText('Word set not found.');
    await expect(page.locator('.ll-wordset-card[data-cat-id]')).toHaveCount(0);
    await page.waitForTimeout(1000);
    expect(anonymousLazyRequests).toBe(0);
    page.off('request', anonymousRequestListener);
  } finally {
    if (cleanupRequired) {
      runFixture('cleanup');
    }
  }
});
