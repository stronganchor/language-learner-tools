const { test, expect } = require('@playwright/test');
const {
  collectPageSpeedMetrics,
  createPageSpeedSession,
  readEnvNumber,
  warmPageSpeedRoute,
  waitForVisibleActionable
} = require('../helpers/page-speed');

const PAGE_PATH = process.env.LL_E2E_PAGE_SPEED_PATH || process.env.LL_E2E_LEARN_PATH || '/learn/';
const ACTIONABLE_SELECTOR = process.env.LL_E2E_PAGE_SPEED_SELECTOR || '.ll-quiz-page-trigger';

const MAX_DOMCONTENTLOADED_MS = readEnvNumber('LL_E2E_PAGE_SPEED_MAX_DOMCONTENTLOADED_MS', 7000);
const MAX_ACTIONABLE_MS = readEnvNumber('LL_E2E_PAGE_SPEED_MAX_ACTIONABLE_MS', 10000);
const MAX_LOAD_MS = readEnvNumber('LL_E2E_PAGE_SPEED_MAX_LOAD_MS', 15000);
const WARMUP_ATTEMPTS = readEnvNumber('LL_E2E_PAGE_SPEED_WARMUP_ATTEMPTS', 2);
const WARMUP_RETRY_DELAY_MS = readEnvNumber('LL_E2E_PAGE_SPEED_WARMUP_RETRY_DELAY_MS', 1000);

test('learn page stays within the throttled load budget', async ({ page, request }, testInfo) => {
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
    await expect(page.locator(ACTIONABLE_SELECTOR).first()).toBeVisible();
    await page.waitForLoadState('load', { timeout: MAX_LOAD_MS });

    const metrics = await collectPageSpeedMetrics(page, ACTIONABLE_SELECTOR, firstActionableMs);

    await testInfo.attach('page-speed-metrics', {
      body: JSON.stringify({
        path: PAGE_PATH,
        budgetsMs: {
          domContentLoaded: MAX_DOMCONTENTLOADED_MS,
          actionable: MAX_ACTIONABLE_MS,
          load: MAX_LOAD_MS
        },
        throttleProfile: session.profile,
        metrics
      }, null, 2),
      contentType: 'application/json'
    });

    expect(metrics.actionableCount).toBeGreaterThan(0);
    expect(metrics.domContentLoadedMs).toBeLessThanOrEqual(MAX_DOMCONTENTLOADED_MS);
    expect(metrics.firstActionableMs).toBeLessThanOrEqual(MAX_ACTIONABLE_MS);
    expect(metrics.loadEventMs).toBeLessThanOrEqual(MAX_LOAD_MS);
  } finally {
    await session.dispose();
  }
});
