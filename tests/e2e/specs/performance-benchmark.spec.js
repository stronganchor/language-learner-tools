const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');
const {
  DEFAULT_HISTORY,
  DEFAULT_REPORT,
  appendHistoryRecord,
  buildBenchmarkReport,
  buildBenchmarkScenarios,
  compareWithPrevious,
  fileChecksum,
  findPreviousComparableRun,
  getRunMetadata,
  loadPerformanceManifest,
  readEnvFlag,
  readHistoryRecords,
  resolvePluginPath,
  summarizeScenarioSamples,
  formatBenchmarkReportMarkdown,
  writeBenchmarkReport
} = require('../helpers/performance-benchmark');
const {
  collectPageSpeedMetrics,
  createPageSpeedSession,
  readEnvNumber,
  warmPageSpeedRoute,
  waitForVisibleActionable
} = require('../helpers/page-speed');

test.skip(process.env.LL_E2E_PERF_ENABLED !== '1', 'Set LL_E2E_PERF_ENABLED=1 or use tests/bin/run-performance-benchmark.sh.');

const RUNS_PER_SCENARIO = readEnvNumber('LL_E2E_PERF_RUNS', 3);
const MAX_DOMCONTENTLOADED_MS = readEnvNumber('LL_E2E_PERF_MAX_DOMCONTENTLOADED_MS', 30000);
const MAX_ACTIONABLE_MS = readEnvNumber('LL_E2E_PERF_MAX_ACTIONABLE_MS', 30000);
const MAX_LOAD_MS = readEnvNumber('LL_E2E_PERF_MAX_LOAD_MS', 45000);
const MAX_INTERACTION_MS = readEnvNumber('LL_E2E_PERF_MAX_INTERACTION_MS', 20000);
const WARMUP_ATTEMPTS = readEnvNumber('LL_E2E_PERF_WARMUP_ATTEMPTS', 2);
const WARMUP_RETRY_DELAY_MS = readEnvNumber('LL_E2E_PERF_WARMUP_RETRY_DELAY_MS', 1000);
const MAX_REGRESSION_RATIO = readEnvNumber('LL_E2E_PERF_MAX_REGRESSION_RATIO', 0.2);
const MAX_REGRESSION_MS = readEnvNumber('LL_E2E_PERF_MAX_REGRESSION_MS', 500);

async function countVisible(page, selector) {
  return page.locator(selector).evaluateAll((elements) => elements.filter((element) => {
    if (!element) return false;
    const style = window.getComputedStyle(element);
    return style.display !== 'none'
      && style.visibility !== 'hidden'
      && style.opacity !== '0'
      && element.getClientRects().length > 0
      && !element.hidden;
  }).length);
}

async function runScenarioAction(page, scenario) {
  if (scenario.action === 'wordset-search') {
    const input = page.locator('[data-ll-wordset-page-search]').first();
    await expect(input).toBeVisible({ timeout: MAX_ACTIONABLE_MS });
    const startedAt = await page.evaluate(() => performance.now());
    await input.fill(scenario.query || 'LLPerf large 01 01');
    await page.waitForFunction(() => {
      const loading = document.querySelector('[data-ll-wordset-page-search-loading]');
      if (loading && !loading.hidden) {
        return false;
      }
      const cards = Array.from(document.querySelectorAll('.ll-wordset-card[data-cat-id]')).filter((card) => {
        const style = window.getComputedStyle(card);
        return !card.hidden
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && card.getClientRects().length > 0;
      });
      return cards.length > 0 && cards.length < 4;
    }, null, { timeout: MAX_INTERACTION_MS });
    return page.evaluate((start) => Math.round(performance.now() - start), startedAt);
  }

  if (scenario.action === 'progress-words-tab') {
    const tab = page.locator('[data-ll-wordset-progress-tab="words"]').first();
    await expect(tab).toBeVisible({ timeout: MAX_ACTIONABLE_MS });
    const startedAt = await page.evaluate(() => performance.now());
    await tab.click();
    await page.waitForFunction(() => {
      return document.querySelectorAll('[data-ll-wordset-progress-words-body] tr').length > 0;
    }, null, { timeout: MAX_INTERACTION_MS });
    return page.evaluate((start) => Math.round(performance.now() - start), startedAt);
  }

  if (scenario.action === 'quiz-popup') {
    const trigger = page.locator('.ll-quiz-page-trigger').first();
    await expect(trigger).toBeVisible({ timeout: MAX_ACTIONABLE_MS });
    const startedAt = await page.evaluate(() => performance.now());
    await trigger.click({ force: true });
    await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: MAX_INTERACTION_MS });
    await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeVisible({ timeout: MAX_INTERACTION_MS });
    return page.evaluate((start) => Math.round(performance.now() - start), startedAt);
  }

  return 0;
}

async function measureScenarioRun(page, request, scenario) {
  const navigationTimeoutMs = Math.max(MAX_DOMCONTENTLOADED_MS, MAX_ACTIONABLE_MS, MAX_LOAD_MS) + 10000;
  await warmPageSpeedRoute(request, scenario.path, {
    attempts: WARMUP_ATTEMPTS,
    retryDelayMs: WARMUP_RETRY_DELAY_MS,
    timeoutMs: navigationTimeoutMs
  });

  const session = await createPageSpeedSession(page);
  try {
    await page.goto(scenario.path, {
      waitUntil: 'domcontentloaded',
      timeout: navigationTimeoutMs
    });

    const firstActionableMs = await waitForVisibleActionable(page, scenario.selector, MAX_ACTIONABLE_MS);
    await expect(page.locator(scenario.selector).first()).toBeVisible({ timeout: MAX_ACTIONABLE_MS });
    await page.waitForLoadState('load', { timeout: MAX_LOAD_MS });

    const metrics = await collectPageSpeedMetrics(page, scenario.selector, firstActionableMs);
    metrics.visibleActionableCount = await countVisible(page, scenario.selector);

    if (scenario.kind === 'interaction') {
      metrics.interactionMs = await runScenarioAction(page, scenario);
    }

    return {
      domContentLoadedMs: metrics.domContentLoadedMs,
      firstActionableMs: metrics.firstActionableMs,
      loadEventMs: metrics.loadEventMs,
      responseStartMs: metrics.responseStartMs,
      responseEndMs: metrics.responseEndMs,
      interactionMs: metrics.interactionMs || 0,
      actionableCount: metrics.actionableCount,
      visibleActionableCount: metrics.visibleActionableCount,
      resourceCount: metrics.resourceCount,
      totalResourceTransferBytes: metrics.totalResourceTransferBytes,
      slowestResources: metrics.slowestResources
    };
  } finally {
    await session.dispose();
  }
}

test('seeded LL Tools benchmark scenarios stay within the historical performance envelope', async ({ page, request }, testInfo) => {
  test.slow();

  const { manifest, manifestPath } = loadPerformanceManifest();
  const scenarios = buildBenchmarkScenarios(manifest);
  test.skip(
    scenarios.some((scenario) => scenario.requiresAuth) && !hasAdminCredentials(),
    'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for authenticated performance scenarios.'
  );
  const scenarioSummaries = [];
  let throttleProfile = null;
  let authenticated = false;

  for (const scenario of scenarios) {
    if (scenario.requiresAuth && !authenticated) {
      await ensureLoggedIntoAdmin(page, '/wp-admin/');
      authenticated = true;
    }

    const samples = [];
    for (let runIndex = 0; runIndex < RUNS_PER_SCENARIO; runIndex += 1) {
      const sample = await measureScenarioRun(page, request, scenario);
      samples.push(sample);
    }

    const summary = summarizeScenarioSamples(scenario, samples);
    scenarioSummaries.push(summary);

    const lastSession = await createPageSpeedSession(page);
    throttleProfile = lastSession.profile;
    await lastSession.dispose();

    expect(summary.median.domContentLoadedMs, `${scenario.name} domContentLoadedMs`).toBeLessThanOrEqual(MAX_DOMCONTENTLOADED_MS);
    expect(summary.median.firstActionableMs, `${scenario.name} firstActionableMs`).toBeLessThanOrEqual(MAX_ACTIONABLE_MS);
    expect(summary.median.loadEventMs, `${scenario.name} loadEventMs`).toBeLessThanOrEqual(MAX_LOAD_MS);
    expect(summary.median[scenario.primaryMetric], `${scenario.name} ${scenario.primaryMetric}`).toBeGreaterThan(0);
    expect(summary.median[scenario.primaryMetric], `${scenario.name} ${scenario.primaryMetric}`).toBeLessThanOrEqual(
      scenario.primaryMetric === 'interactionMs' ? MAX_INTERACTION_MS : MAX_ACTIONABLE_MS
    );
    expect(
      Math.max(...samples.map((sample) => sample.visibleActionableCount || 0)),
      `${scenario.name} visible actionable count`
    ).toBeGreaterThanOrEqual(scenario.minActionableCount || 1);
  }

  const historyFile = resolvePluginPath(process.env.LL_E2E_PERF_HISTORY_FILE, DEFAULT_HISTORY);
  const runMetadata = getRunMetadata();
  const record = {
    schemaVersion: 1,
    recordedAt: new Date().toISOString(),
    fixtureVersion: String(manifest.fixtureVersion || ''),
    fixtureManifest: {
      path: manifestPath,
      sha256: fileChecksum(manifestPath)
    },
    baseURL: process.env.LL_E2E_BASE_URL || '',
    runsPerScenario: RUNS_PER_SCENARIO,
    throttleProfile,
    pluginVersion: runMetadata.pluginVersion,
    git: runMetadata.git,
    scenarios: scenarioSummaries
  };

  const previousRecord = readEnvFlag('LL_E2E_PERF_COMPARE_HISTORY', true)
    ? findPreviousComparableRun(readHistoryRecords(historyFile), record)
    : null;
  const comparison = previousRecord
    ? compareWithPrevious(record, previousRecord, {
      maxRegressionRatio: MAX_REGRESSION_RATIO,
      maxRegressionMs: MAX_REGRESSION_MS
    })
    : [];
  record.comparison = comparison;
  const reportFile = resolvePluginPath(process.env.LL_E2E_PERF_REPORT_FILE, DEFAULT_REPORT);
  const report = buildBenchmarkReport(record, previousRecord, historyFile);
  const reportFiles = writeBenchmarkReport(reportFile, report);
  console.log(formatBenchmarkReportMarkdown(report));

  await testInfo.attach('performance-benchmark-summary', {
    body: JSON.stringify({
      reportFiles,
      report
    }, null, 2),
    contentType: 'application/json'
  });

  const failures = comparison.filter((row) => row.failed);
  expect(failures, JSON.stringify(failures, null, 2)).toEqual([]);

  if (readEnvFlag('LL_E2E_PERF_WRITE_HISTORY', false)) {
    appendHistoryRecord(historyFile, record);
  }
});
