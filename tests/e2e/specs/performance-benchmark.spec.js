const path = require('path');
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
const RECORDER_MEDIA_BLOCKED_DIAGNOSTIC = readEnvFlag('LL_E2E_PERF_RECORDER_BLOCK_MEDIA', false);
const RECORDER_ONLY_DIAGNOSTIC = readEnvFlag('LL_E2E_PERF_RECORDER_ONLY', false);
const RECORDER_DIAGNOSTIC_PROFILE = RECORDER_MEDIA_BLOCKED_DIAGNOSTIC
  ? 'recorder-media-blocked-v1'
  : (RECORDER_ONLY_DIAGNOSTIC ? 'recorder-only-v1' : '');

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

function summarizeTimings(values) {
  const sorted = values
    .map((value) => Math.max(0, Math.round(Number(value) || 0)))
    .filter((value) => value > 0)
    .sort((left, right) => left - right);
  if (!sorted.length) {
    return { count: 0, medianMs: 0, maxMs: 0, totalMs: 0 };
  }
  const middle = Math.floor(sorted.length / 2);
  const medianMs = sorted.length % 2
    ? sorted[middle]
    : Math.round((sorted[middle - 1] + sorted[middle]) / 2);
  return {
    count: sorted.length,
    medianMs,
    maxMs: sorted[sorted.length - 1],
    totalMs: sorted.reduce((sum, value) => sum + value, 0)
  };
}

async function collectRecorderQueueResourceProfile(page, interactionStartedAt) {
  return page.evaluate((interactionStart) => {
    const mediaTypes = new Set(['img', 'image', 'audio', 'video']);
    const mediaExtension = /\.(?:avif|gif|jpe?g|png|svg|webp|mp3|m4a|ogg|wav|mp4|webm)(?:$|[?#])/i;
    const mediaEntries = performance.getEntriesByType('resource').filter((entry) => {
      return mediaTypes.has(String(entry.initiatorType || '').toLowerCase())
        || mediaExtension.test(String(entry.name || ''));
    });
    const interactionMediaEntries = mediaEntries.filter(
      (entry) => Number(entry.startTime || 0) >= Number(interactionStart || 0)
    );
    const bytesFor = (entry) => Number(entry.transferSize || entry.encodedBodySize || 0);
    return {
      mediaResourceCount: mediaEntries.length,
      mediaTransferBytes: mediaEntries.reduce((sum, entry) => sum + bytesFor(entry), 0),
      mediaEncodedBodyBytes: mediaEntries.reduce(
        (sum, entry) => sum + Number(entry.encodedBodySize || 0),
        0
      ),
      mediaDurationTotalMs: Math.round(mediaEntries.reduce(
        (sum, entry) => sum + Number(entry.duration || 0),
        0
      )),
      interactionMediaResourceCount: interactionMediaEntries.length,
      interactionMediaTransferBytes: interactionMediaEntries.reduce(
        (sum, entry) => sum + bytesFor(entry),
        0
      ),
      interactionMediaEncodedBodyBytes: interactionMediaEntries.reduce(
        (sum, entry) => sum + Number(entry.encodedBodySize || 0),
        0
      ),
      slowestMedia: mediaEntries
        .map((entry) => ({
          name: String(entry.name || ''),
          durationMs: Math.round(Number(entry.duration || 0)),
          transferSizeBytes: bytesFor(entry)
        }))
        .sort((left, right) => right.durationMs - left.durationMs)
        .slice(0, 8)
    };
  }, interactionStartedAt);
}

function diagnosticReportPath(reportFile, diagnosticProfile) {
  const extension = path.extname(reportFile);
  const stem = extension === '' ? reportFile : reportFile.slice(0, -extension.length);
  const suffix = diagnosticProfile === 'recorder-media-blocked-v1'
    ? 'recorder-media-blocked'
    : 'recorder-only';
  return `${stem}-${suffix}${extension || '.json'}`;
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
    await expect(page.locator('#ll-tools-flashcard .flashcard-container:visible').first()).toBeVisible({ timeout: MAX_INTERACTION_MS });
    return page.evaluate((start) => Math.round(performance.now() - start), startedAt);
  }

  if (scenario.action === 'recorder-queue-lazy-completion') {
    const interactionStartedAtEpochMs = Date.now();
    const interactionStartedAt = await page.evaluate(() => performance.now());
    const root = page.locator('[data-ll-recorder-queue-summary-root]').first();
    const placeholders = root.locator('[data-ll-recorder-queue-summary-placeholder]');
    const loadedCards = root.locator('.ll-wordset-recorder-queue-category-card:not([data-ll-recorder-queue-summary-placeholder])');
    const sentinel = root.locator('[data-ll-recorder-queue-summary-sentinel]').first();
    let initialPlaceholderCount = 0;
    let initialLoadedCategoryCount = 0;
    const buildRecorderDetails = async () => {
      const requestTimings = typeof actionContext.getRecorderQueueBatchRequestTimings === 'function'
        ? actionContext.getRecorderQueueBatchRequestTimings()
        : [];
      const interactionRequestTimings = requestTimings.filter(
        (timing) => Number(timing.startedAtEpochMs || 0) >= interactionStartedAtEpochMs
      );
      const safeCount = async (locator) => {
        try {
          return await locator.count();
        } catch (error) {
          return -1;
        }
      };
      let resourceProfile = {};
      try {
        resourceProfile = await collectRecorderQueueResourceProfile(page, interactionStartedAt);
      } catch (error) {
        resourceProfile = { resourceProfileUnavailable: true };
      }
      return {
        initialPlaceholderCount,
        initialLoadedCategoryCount,
        finalPlaceholderCount: await safeCount(placeholders),
        finalLoadedCategoryCount: await safeCount(loadedCards),
        batchRequestCount: typeof actionContext.getRecorderQueueBatchRequestCount === 'function'
          ? actionContext.getRecorderQueueBatchRequestCount()
          : 0,
        maxConcurrentBatchRequestCount: typeof actionContext.getRecorderQueueMaxConcurrentBatchRequestCount === 'function'
          ? actionContext.getRecorderQueueMaxConcurrentBatchRequestCount()
          : 0,
        summaryRequestDuration: summarizeTimings(requestTimings.map((timing) => timing.durationMs)),
        summaryRequestResponseStart: summarizeTimings(requestTimings.map((timing) => timing.responseStartMs)),
        summaryRequestTimings: requestTimings,
        interactionSummaryRequestDuration: summarizeTimings(
          interactionRequestTimings.map((timing) => timing.durationMs)
        ),
        interactionSummaryRequestResponseStart: summarizeTimings(
          interactionRequestTimings.map((timing) => timing.responseStartMs)
        ),
        interactionSummaryRequestTimings: interactionRequestTimings,
        recorderMediaBlockedDiagnostic: RECORDER_MEDIA_BLOCKED_DIAGNOSTIC,
        ...resourceProfile
      };
    };

    try {
      await expect(root).toBeVisible({ timeout: MAX_ACTIONABLE_MS });
      await expect(sentinel).toBeAttached({ timeout: MAX_ACTIONABLE_MS });

      initialPlaceholderCount = await placeholders.count();
      initialLoadedCategoryCount = await loadedCards.count();
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
      const details = await buildRecorderDetails();
      const finalLoadedCategoryCount = details.finalLoadedCategoryCount;
      const batchRequestCount = details.batchRequestCount;
      const maxConcurrentBatchRequestCount = details.maxConcurrentBatchRequestCount;
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
        details
      };
    } catch (error) {
      if (error && typeof error === 'object') {
        try {
          error.recorderQueueDiagnostic = {
            completed: false,
            failure: error instanceof Error ? error.message : String(error),
            ...(await buildRecorderDetails())
          };
        } catch (diagnosticError) {
          error.recorderQueueDiagnostic = {
            completed: false,
            failure: error instanceof Error ? error.message : String(error),
            diagnosticFailure: diagnosticError instanceof Error
              ? diagnosticError.message
              : String(diagnosticError)
          };
        }
      }
      throw error;
    }
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
  const recorderQueueRequestStartedAt = new Map();
  const recorderQueueBatchRequestTimings = [];
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
    recorderQueueRequestStartedAt.set(browserRequest, Date.now());
    recorderQueueMaxConcurrentBatchRequestCount = Math.max(
      recorderQueueMaxConcurrentBatchRequestCount,
      recorderQueueActiveBatchRequests.size
    );
  };
  const recorderQueueRequestSettledListener = (browserRequest) => {
    if (recorderQueueRequestStartedAt.has(browserRequest)) {
      const timing = browserRequest.timing();
      const responseEnd = Number(timing.responseEnd);
      const responseStart = Number(timing.responseStart);
      recorderQueueBatchRequestTimings.push({
        startedAtEpochMs: recorderQueueRequestStartedAt.get(browserRequest),
        durationMs: responseEnd >= 0
          ? Math.round(responseEnd)
          : Math.max(0, Date.now() - recorderQueueRequestStartedAt.get(browserRequest)),
        responseStartMs: responseStart >= 0 ? Math.round(responseStart) : 0,
        failed: browserRequest.failure() !== null
      });
      recorderQueueRequestStartedAt.delete(browserRequest);
    }
    recorderQueueActiveBatchRequests.delete(browserRequest);
  };
  const getRecorderQueueBatchRequestTimings = () => [
    ...recorderQueueBatchRequestTimings,
    ...Array.from(recorderQueueRequestStartedAt.values()).map((startedAtEpochMs) => ({
      startedAtEpochMs,
      durationMs: Math.max(0, Date.now() - startedAtEpochMs),
      responseStartMs: 0,
      failed: false,
      inFlight: true
    }))
  ];
  page.on('request', recorderQueueRequestListener);
  page.on('requestfinished', recorderQueueRequestSettledListener);
  page.on('requestfailed', recorderQueueRequestSettledListener);

  const blockRecorderMedia = RECORDER_MEDIA_BLOCKED_DIAGNOSTIC
    && scenario.action === 'recorder-queue-lazy-completion';
  const recorderMediaRoute = async (route) => {
    const resourceType = route.request().resourceType();
    if (resourceType === 'image' || resourceType === 'media') {
      await route.abort('blockedbyclient');
      return;
    }
    await route.continue();
  };
  if (blockRecorderMedia) {
    await page.route('**/*', recorderMediaRoute);
  }

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
        getRecorderQueueMaxConcurrentBatchRequestCount: () => recorderQueueMaxConcurrentBatchRequestCount,
        getRecorderQueueBatchRequestTimings
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
    if (blockRecorderMedia) {
      await page.unroute('**/*', recorderMediaRoute);
    }
    await session.dispose();
  }
}

test('seeded LL Tools benchmark scenarios stay within the historical performance envelope', async ({ page, request }, testInfo) => {
  const { manifest, manifestPath } = loadPerformanceManifest();
  const configuredScenarios = buildBenchmarkScenarios(manifest);
  const allScenarios = RECORDER_ONLY_DIAGNOSTIC
    ? configuredScenarios.filter((scenario) => scenario.action === 'recorder-queue-lazy-completion')
    : configuredScenarios;
  if (RECORDER_ONLY_DIAGNOSTIC && !allScenarios.length) {
    throw new Error('Recorder-only diagnostic requested, but the fixture has no recorder completion scenario.');
  }
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
          completedScenarios: scenarioSummaries,
          currentScenario: scenario.name,
          currentRun: runIndex + 1,
          completedSamplesForCurrentScenario: samples.length,
          currentScenarioSamples: samples,
          error: error instanceof Error ? error.message : String(error),
          recorderQueueDiagnostic: error && typeof error === 'object'
            ? error.recorderQueueDiagnostic || null
            : null
        };
        console.error(`[LL Tools performance] Scenario failed: ${JSON.stringify(diagnostic)}`);
        await testInfo.attach('performance-benchmark-partial-diagnostic', {
          body: JSON.stringify(diagnostic, null, 2),
          contentType: 'application/json'
        });
        if (diagnostic.recorderQueueDiagnostic) {
          await testInfo.attach('performance-benchmark-recorder-failure-diagnostic', {
            body: JSON.stringify({
              diagnosticProfile: RECORDER_DIAGNOSTIC_PROFILE,
              scenario: scenario.name,
              run: runIndex + 1,
              recorderQueueDiagnostic: diagnostic.recorderQueueDiagnostic
            }, null, 2),
            contentType: 'application/json'
          });
        }
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
    schemaVersion: 2,
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
    diagnosticProfile: RECORDER_DIAGNOSTIC_PROFILE,
    scenarios: scenarioSummaries
  };

  const previousRecord = RECORDER_DIAGNOSTIC_PROFILE === ''
    && readEnvFlag('LL_E2E_PERF_COMPARE_HISTORY', true)
    ? findPreviousComparableRun(readHistoryRecords(historyFile), record)
    : null;
  const comparison = previousRecord
    ? compareWithPrevious(record, previousRecord, {
      maxRegressionRatio: MAX_REGRESSION_RATIO,
      maxRegressionMs: MAX_REGRESSION_MS
    })
    : [];
  record.comparison = comparison;
  const configuredReportFile = resolvePluginPath(process.env.LL_E2E_PERF_REPORT_FILE, DEFAULT_REPORT);
  const reportFile = RECORDER_DIAGNOSTIC_PROFILE !== ''
    ? diagnosticReportPath(configuredReportFile, RECORDER_DIAGNOSTIC_PROFILE)
    : configuredReportFile;
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

  if (RECORDER_DIAGNOSTIC_PROFILE !== '') {
    console.warn('[LL Tools performance] Isolated recorder diagnostic is excluded from comparison and history.');
  } else if (readEnvFlag('LL_E2E_PERF_WRITE_HISTORY', false)) {
    if (record.git && record.git.dirty) {
      console.warn('[LL Tools performance] Skipping history write because the Git worktree is dirty.');
    } else {
      appendHistoryRecord(historyFile, record);
    }
  }
});
