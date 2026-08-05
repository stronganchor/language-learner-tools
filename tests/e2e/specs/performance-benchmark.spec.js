const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');
const {
  DEFAULT_HISTORY,
  DEFAULT_REPORT,
  MANIFEST_CHECKSUM_FORMAT,
  appendHistoryRecord,
  benchmarkRequiresAuthentication,
  buildBenchmarkReport,
  buildBenchmarkScenarios,
  calculateBenchmarkTestTimeout,
  compareWithPrevious,
  findPreviousComparableRun,
  getRunMetadata,
  loadPerformanceManifest,
  manifestChecksum,
  readEnvFlag,
  readHistoryRecords,
  resolvePluginPath,
  summarizeScenarioSamples,
  validateRecorderQueueCompletion,
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
const MAX_RECORDER_QUEUE_COMPLETION_MS = readEnvNumber('LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS', 120000);
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

async function runScenarioAction(page, scenario, actionContext = {}) {
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
      const error = document.querySelector('[data-ll-wordset-page-search-error]');
      if (error && !error.hidden) {
        return true;
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
    const searchError = await page.locator('[data-ll-wordset-page-search-error]').evaluate((element) => ({
      visible: !element.hidden,
      message: String(element.textContent || '').replace(/\s+/g, ' ').trim()
    }));
    if (searchError.visible) {
      throw new Error(
        `Performance wordset search unexpectedly reached its Retry state after the category-index readiness preflight: ${searchError.message}`
      );
    }
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

  if (scenario.action === 'recorder-queue-lazy-completion') {
    const root = page.locator('[data-ll-recorder-queue-summary-root]').first();
    const placeholders = root.locator('[data-ll-recorder-queue-summary-placeholder]');
    const loadedCards = root.locator('.ll-wordset-recorder-queue-category-card:not([data-ll-recorder-queue-summary-placeholder])');
    const sentinel = root.locator('[data-ll-recorder-queue-summary-sentinel]').first();
    await expect(root).toBeVisible({ timeout: MAX_ACTIONABLE_MS });
    await expect(sentinel).toBeAttached({ timeout: MAX_ACTIONABLE_MS });

    const initialPlaceholderCount = await placeholders.count();
    const initialLoadedCategoryCount = await loadedCards.count();
    const deadline = Date.now() + MAX_RECORDER_QUEUE_COMPLETION_MS;
    const maximumBatchAttempts = Math.max(20, initialPlaceholderCount * 4);
    let noProgressCount = 0;

    const remainingTimeout = () => Math.max(1, deadline - Date.now());
    const readLoadError = () => root.evaluate((element) => {
      if (!element.classList.contains('has-load-error')) {
        return '';
      }
      const status = element.querySelector('[data-ll-recorder-queue-summary-status]');
      return String(status && status.textContent || '').trim()
        || 'Recorder queue summary loading entered an error state.';
    });
    const throwIfLoadFailed = async () => {
      const loadError = await readLoadError();
      if (loadError) {
        throw new Error(`Recorder queue lazy completion failed: ${loadError}`);
      }
    };
    const waitForQueueIdleOrError = async () => {
      await page.waitForFunction(() => {
        const queueRoot = document.querySelector('[data-ll-recorder-queue-summary-root]');
        return queueRoot && (
          queueRoot.classList.contains('has-load-error')
          || !queueRoot.classList.contains('is-loading')
        );
      }, null, { timeout: remainingTimeout() });
      await throwIfLoadFailed();
    };
    const waitForSummaryRequestAfter = async (previousRequestCount) => {
      const getRequestCount = typeof actionContext.getRecorderQueueBatchRequestCount === 'function'
        ? actionContext.getRecorderQueueBatchRequestCount
        : () => 0;
      while (Date.now() < deadline) {
        await throwIfLoadFailed();
        if (getRequestCount() > previousRequestCount) {
          return;
        }
        await page.waitForTimeout(Math.min(50, remainingTimeout()));
      }
      throw new Error('Recorder queue sentinel did not start another summary request before the completion deadline.');
    };

    for (let attempt = 0; attempt < maximumBatchAttempts; attempt += 1) {
      const pendingBeforeWait = await placeholders.count();
      if (pendingBeforeWait === 0) {
        break;
      }
      if (Date.now() >= deadline) {
        throw new Error(`Recorder queue lazy completion exceeded ${MAX_RECORDER_QUEUE_COMPLETION_MS} ms with ${pendingBeforeWait} placeholders remaining.`);
      }

      const requestCountBeforeViewportCheck = typeof actionContext.getRecorderQueueBatchRequestCount === 'function'
        ? actionContext.getRecorderQueueBatchRequestCount()
        : 0;
      const requestInFlight = await root.evaluate((element) => element.classList.contains('is-loading'));
      if (requestInFlight) {
        await waitForQueueIdleOrError();
        continue;
      }

      await throwIfLoadFailed();
      const pendingBeforeRequest = await placeholders.count();
      await sentinel.scrollIntoViewIfNeeded({ timeout: remainingTimeout() });
      await waitForSummaryRequestAfter(requestCountBeforeViewportCheck);
      await waitForQueueIdleOrError();

      const pendingAfterRequest = await placeholders.count();
      if (pendingAfterRequest < pendingBeforeRequest) {
        noProgressCount = 0;
      } else {
        noProgressCount += 1;
      }
      if (noProgressCount > 6) {
        throw new Error(`Recorder queue lazy completion made no progress across ${noProgressCount} bounded requests.`);
      }
    }

    await throwIfLoadFailed();
    const finalPlaceholderCount = await placeholders.count();
    if (finalPlaceholderCount > 0) {
      throw new Error(`Recorder queue lazy completion stopped with ${finalPlaceholderCount} placeholders remaining.`);
    }
    await expect(root).toHaveAttribute('aria-busy', 'false', { timeout: remainingTimeout() });
    const finalLoadedCategoryCount = await loadedCards.count();
    const batchRequestCount = typeof actionContext.getRecorderQueueBatchRequestCount === 'function'
      ? actionContext.getRecorderQueueBatchRequestCount()
      : 0;
    const maxConcurrentBatchRequestCount = typeof actionContext.getRecorderQueueMaxConcurrentBatchRequestCount === 'function'
      ? actionContext.getRecorderQueueMaxConcurrentBatchRequestCount()
      : 0;
    validateRecorderQueueCompletion(
      scenario,
      finalLoadedCategoryCount,
      batchRequestCount,
      maxConcurrentBatchRequestCount
    );
    // Recorder summary loading begins during the page's startup script, before
    // this action runs. performance.now() is navigation-relative, so this
    // measures the complete navigation-to-all-categories interval instead of
    // an unstable remainder that can collapse to zero on warm runs.
    const durationMs = await page.evaluate(() => Math.round(performance.now()));
    return {
      durationMs,
      details: {
        initialPlaceholderCount,
        initialLoadedCategoryCount,
        finalLoadedCategoryCount,
        batchRequestCount,
        maxConcurrentBatchRequestCount
      }
    };
  }

  return 0;
}

async function measureScenarioRun(page, request, scenario) {
  const navigationTimeoutMs = Math.max(MAX_DOMCONTENTLOADED_MS, MAX_ACTIONABLE_MS, MAX_LOAD_MS) + 10000;
  const warmupRequest = scenario.requiresAuth ? page.context().request : request;
  await warmPageSpeedRoute(warmupRequest, scenario.path, {
    attempts: WARMUP_ATTEMPTS,
    retryDelayMs: WARMUP_RETRY_DELAY_MS,
    timeoutMs: navigationTimeoutMs
  });

  let recorderQueueBatchRequestCount = 0;
  let recorderQueueMaxConcurrentBatchRequestCount = 0;
  const recorderQueueActiveBatchRequests = new Set();
  const isRecorderQueueBatchRequest = (browserRequest) => {
    if (scenario.action !== 'recorder-queue-lazy-completion') {
      return false;
    }
    const postData = browserRequest.postData() || '';
    return browserRequest.url().includes('/wp-admin/admin-ajax.php')
      && postData.includes('action=ll_tools_wordset_recorder_queue_summaries');
  };
  const recorderQueueRequestListener = (browserRequest) => {
    if (!isRecorderQueueBatchRequest(browserRequest)) {
      return;
    }
    recorderQueueBatchRequestCount += 1;
    recorderQueueActiveBatchRequests.add(browserRequest);
    recorderQueueMaxConcurrentBatchRequestCount = Math.max(
      recorderQueueMaxConcurrentBatchRequestCount,
      recorderQueueActiveBatchRequests.size
    );
  };
  const recorderQueueRequestSettledListener = (browserRequest) => {
    recorderQueueActiveBatchRequests.delete(browserRequest);
  };
  page.on('request', recorderQueueRequestListener);
  page.on('requestfinished', recorderQueueRequestSettledListener);
  page.on('requestfailed', recorderQueueRequestSettledListener);

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
      const actionResult = await runScenarioAction(page, scenario, {
        getRecorderQueueBatchRequestCount: () => recorderQueueBatchRequestCount,
        getRecorderQueueMaxConcurrentBatchRequestCount: () => recorderQueueMaxConcurrentBatchRequestCount
      });
      if (actionResult && typeof actionResult === 'object') {
        metrics.interactionMs = Number(actionResult.durationMs || 0);
        metrics.interactionDetails = actionResult.details || null;
      } else {
        metrics.interactionMs = Number(actionResult || 0);
      }
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
      slowestResources: metrics.slowestResources,
      interactionDetails: metrics.interactionDetails || null
    };
  } finally {
    page.off('request', recorderQueueRequestListener);
    page.off('requestfinished', recorderQueueRequestSettledListener);
    page.off('requestfailed', recorderQueueRequestSettledListener);
    await session.dispose();
  }
}

test('seeded LL Tools benchmark scenarios stay within the historical performance envelope', async ({ page, request }, testInfo) => {
  const { manifest, manifestPath } = loadPerformanceManifest();
  const allScenarios = buildBenchmarkScenarios(manifest);
  const credentialsAvailable = hasAdminCredentials();
  if (benchmarkRequiresAuthentication(manifest) && !credentialsAvailable) {
    throw new Error(
      'This performance fixture requires LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS so authenticated settings and recorder scenarios cannot be skipped.'
    );
  }
  const scenarios = credentialsAvailable
    ? allScenarios
    : allScenarios.filter((scenario) => !scenario.requiresAuth);
  if (!scenarios.length) {
    throw new Error('No performance scenarios are runnable with the available credentials.');
  }
  const testTimeoutMs = calculateBenchmarkTestTimeout(scenarios, {
    runsPerScenario: RUNS_PER_SCENARIO,
    warmupAttempts: WARMUP_ATTEMPTS,
    warmupRetryDelayMs: WARMUP_RETRY_DELAY_MS,
    maxDomContentLoadedMs: MAX_DOMCONTENTLOADED_MS,
    maxActionableMs: MAX_ACTIONABLE_MS,
    maxLoadMs: MAX_LOAD_MS,
    maxInteractionMs: MAX_INTERACTION_MS,
    maxRecorderQueueCompletionMs: MAX_RECORDER_QUEUE_COMPLETION_MS,
    navigationGraceMs: 10000
  });
  testInfo.setTimeout(testTimeoutMs);
  const benchmarkStartedAt = Date.now();
  console.log(
    `[LL Tools performance] ${scenarios.length} runnable scenarios x ${RUNS_PER_SCENARIO} run(s); `
      + `test timeout budget ${testTimeoutMs} ms.`
  );
  const scenarioSummaries = [];
  let throttleProfile = null;
  let authenticated = false;

  for (let scenarioIndex = 0; scenarioIndex < scenarios.length; scenarioIndex += 1) {
    const scenario = scenarios[scenarioIndex];
    if (scenario.requiresAuth && !authenticated) {
      await ensureLoggedIntoAdmin(page, '/wp-admin/');
      authenticated = true;
    }

    const samples = [];
    for (let runIndex = 0; runIndex < RUNS_PER_SCENARIO; runIndex += 1) {
      console.log(
        `[LL Tools performance] Starting scenario ${scenarioIndex + 1}/${scenarios.length} `
          + `${scenario.name}, run ${runIndex + 1}/${RUNS_PER_SCENARIO} `
          + `(${Date.now() - benchmarkStartedAt} ms elapsed).`
      );
      try {
        const sample = await measureScenarioRun(page, request, scenario);
        samples.push(sample);
        console.log(
          `[LL Tools performance] Finished ${scenario.name}, run ${runIndex + 1}/${RUNS_PER_SCENARIO}; `
            + `first actionable ${sample.firstActionableMs} ms, `
            + `${scenario.primaryMetric} ${sample[scenario.primaryMetric]} ms.`
        );
      } catch (error) {
        const diagnostic = {
          elapsedMs: Date.now() - benchmarkStartedAt,
          testTimeoutMs,
          runnableScenarioCount: scenarios.length,
          runsPerScenario: RUNS_PER_SCENARIO,
          completedScenarios: scenarioSummaries.map((summary) => summary.name),
          currentScenario: scenario.name,
          currentRun: runIndex + 1,
          completedSamplesForCurrentScenario: samples.length,
          error: error instanceof Error ? error.message : String(error)
        };
        console.error(`[LL Tools performance] Scenario failed: ${JSON.stringify(diagnostic)}`);
        await testInfo.attach('performance-benchmark-partial-diagnostic', {
          body: JSON.stringify(diagnostic, null, 2),
          contentType: 'application/json'
        });
        throw error;
      }
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
    const primaryMetricBudget = scenario.primaryMetric !== 'interactionMs'
      ? MAX_ACTIONABLE_MS
      : (scenario.action === 'recorder-queue-lazy-completion'
        ? MAX_RECORDER_QUEUE_COMPLETION_MS
        : MAX_INTERACTION_MS);
    expect(summary.median[scenario.primaryMetric], `${scenario.name} ${scenario.primaryMetric}`).toBeLessThanOrEqual(
      primaryMetricBudget
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
      sha256: manifestChecksum(manifestPath),
      checksumFormat: MANIFEST_CHECKSUM_FORMAT
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
