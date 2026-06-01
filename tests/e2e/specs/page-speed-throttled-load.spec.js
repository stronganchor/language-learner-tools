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
const WARMUP_TIMEOUT_MS = readEnvNumber('LL_E2E_PAGE_SPEED_WARMUP_TIMEOUT_MS', 180000);
const MEASURE_ATTEMPTS = Math.max(1, Math.round(readEnvNumber('LL_E2E_PAGE_SPEED_MEASURE_ATTEMPTS', 2)));

function metricsFitBudget(metrics) {
  return metrics.actionableCount > 0
    && metrics.domContentLoadedMs <= MAX_DOMCONTENTLOADED_MS
    && metrics.firstActionableMs <= MAX_ACTIONABLE_MS
    && metrics.loadEventMs <= MAX_LOAD_MS;
}

function budgetRatio(metrics) {
  return Math.max(
    metrics.domContentLoadedMs / MAX_DOMCONTENTLOADED_MS,
    metrics.firstActionableMs / MAX_ACTIONABLE_MS,
    metrics.loadEventMs / MAX_LOAD_MS
  );
}

async function measurePageSpeed(page, navigationTimeoutMs) {
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
    return { metrics, profile: session.profile };
  } finally {
    await session.dispose();
  }
}

test('learn page stays within the throttled load budget', async ({ page, request }, testInfo) => {
  test.slow();

  const navigationTimeoutMs = Math.max(MAX_DOMCONTENTLOADED_MS, MAX_ACTIONABLE_MS, MAX_LOAD_MS) + 10000;
  await warmPageSpeedRoute(request, PAGE_PATH, {
    attempts: WARMUP_ATTEMPTS,
    retryDelayMs: WARMUP_RETRY_DELAY_MS,
    timeoutMs: Math.max(navigationTimeoutMs, WARMUP_TIMEOUT_MS)
  });

  const attempts = [];
  let selected = null;

  for (let attempt = 1; attempt <= MEASURE_ATTEMPTS; attempt += 1) {
    const result = await measurePageSpeed(page, navigationTimeoutMs);
    attempts.push(Object.assign({ attempt }, result));

    if (metricsFitBudget(result.metrics)) {
      selected = attempts[attempts.length - 1];
      break;
    }

    if (attempt < MEASURE_ATTEMPTS) {
      await page.goto('about:blank');
    }
  }

  if (!selected) {
    selected = attempts.reduce((best, current) => (
      !best || budgetRatio(current.metrics) < budgetRatio(best.metrics) ? current : best
    ), null);
  }

  await testInfo.attach('page-speed-metrics', {
    body: JSON.stringify({
      path: PAGE_PATH,
      budgetsMs: {
        domContentLoaded: MAX_DOMCONTENTLOADED_MS,
        actionable: MAX_ACTIONABLE_MS,
        load: MAX_LOAD_MS
      },
      selectedAttempt: selected ? selected.attempt : null,
      attempts: attempts.map((attempt) => ({
        attempt: attempt.attempt,
        throttleProfile: attempt.profile,
        metrics: attempt.metrics
      }))
    }, null, 2),
    contentType: 'application/json'
  });

  const metrics = selected.metrics;
  expect(metrics.actionableCount).toBeGreaterThan(0);
  expect(metrics.domContentLoadedMs).toBeLessThanOrEqual(MAX_DOMCONTENTLOADED_MS);
  expect(metrics.firstActionableMs).toBeLessThanOrEqual(MAX_ACTIONABLE_MS);
  expect(metrics.loadEventMs).toBeLessThanOrEqual(MAX_LOAD_MS);
});
