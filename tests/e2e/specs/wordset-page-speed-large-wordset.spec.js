const { test, expect } = require('@playwright/test');
const {
  collectPageSpeedMetrics,
  createPageSpeedSession,
  readEnvNumber,
  warmPageSpeedRoute,
  waitForVisibleActionable
} = require('../helpers/page-speed');

const PAGE_PATH = process.env.LL_E2E_WORDSET_PAGE_SPEED_PATH || '/genc-palu/';
const ACTIONABLE_SELECTOR = process.env.LL_E2E_WORDSET_PAGE_SPEED_SELECTOR || '.ll-wordset-card[data-cat-id]:not(.ll-wordset-card--lazy-placeholder):not([data-ll-wordset-inline-placeholder])';
const PLACEHOLDER_SELECTOR = '.ll-wordset-card--lazy-placeholder, [data-ll-wordset-inline-placeholder]';
const PAGE_ROOT_SELECTOR = process.env.LL_E2E_WORDSET_PAGE_SPEED_ROOT_SELECTOR || '[data-ll-wordset-page], .ll-wordset-page';
const MIN_CARD_COUNT = readEnvNumber('LL_E2E_WORDSET_PAGE_SPEED_MIN_CARDS', 18);

const MAX_DOMCONTENTLOADED_MS = readEnvNumber('LL_E2E_WORDSET_PAGE_SPEED_MAX_DOMCONTENTLOADED_MS', 12000);
const MAX_ACTIONABLE_MS = readEnvNumber('LL_E2E_WORDSET_PAGE_SPEED_MAX_ACTIONABLE_MS', 12000);
const MAX_LOAD_MS = readEnvNumber('LL_E2E_WORDSET_PAGE_SPEED_MAX_LOAD_MS', 20000);
const WARMUP_ATTEMPTS = readEnvNumber('LL_E2E_WORDSET_PAGE_SPEED_WARMUP_ATTEMPTS', 2);
const WARMUP_RETRY_DELAY_MS = readEnvNumber('LL_E2E_WORDSET_PAGE_SPEED_WARMUP_RETRY_DELAY_MS', 1000);

test('large wordset page stays within the throttled load budget', async ({ page, request }, testInfo) => {
  test.slow();

  const navigationTimeoutMs = Math.max(MAX_DOMCONTENTLOADED_MS, MAX_ACTIONABLE_MS, MAX_LOAD_MS) + 10000;
  await warmPageSpeedRoute(request, PAGE_PATH, {
    attempts: WARMUP_ATTEMPTS,
    retryDelayMs: WARMUP_RETRY_DELAY_MS,
    timeoutMs: navigationTimeoutMs
  });

  const session = await createPageSpeedSession(page);

  try {
    await page.goto(PAGE_PATH, {
      waitUntil: 'domcontentloaded',
      timeout: navigationTimeoutMs
    });

    const firstActionableMs = await waitForVisibleActionable(page, ACTIONABLE_SELECTOR, MAX_ACTIONABLE_MS);
    await expect(page.locator(PAGE_ROOT_SELECTOR).first()).toBeVisible();
    await expect(page.locator(ACTIONABLE_SELECTOR).first()).toBeVisible();
    await page.waitForLoadState('load', { timeout: MAX_LOAD_MS });

    const metrics = await collectPageSpeedMetrics(page, ACTIONABLE_SELECTOR, firstActionableMs);
    const placeholderCount = await page.locator(PLACEHOLDER_SELECTOR).count();

    await testInfo.attach('wordset-page-speed-metrics', {
      body: JSON.stringify({
        path: PAGE_PATH,
        selectors: {
          actionable: ACTIONABLE_SELECTOR,
          placeholders: PLACEHOLDER_SELECTOR
        },
        budgetsMs: {
          domContentLoaded: MAX_DOMCONTENTLOADED_MS,
          actionable: MAX_ACTIONABLE_MS,
          load: MAX_LOAD_MS
        },
        minCardCount: MIN_CARD_COUNT,
        placeholderCount,
        throttleProfile: session.profile,
        metrics
      }, null, 2),
      contentType: 'application/json'
    });

    expect(metrics.actionableCount).toBeGreaterThanOrEqual(MIN_CARD_COUNT);
    expect(metrics.domContentLoadedMs).toBeLessThanOrEqual(MAX_DOMCONTENTLOADED_MS);
    expect(metrics.firstActionableMs).toBeLessThanOrEqual(MAX_ACTIONABLE_MS);
    expect(metrics.loadEventMs).toBeLessThanOrEqual(MAX_LOAD_MS);
  } finally {
    await session.dispose();
  }
});
