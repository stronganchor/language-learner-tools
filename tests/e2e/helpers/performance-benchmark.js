const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const PLUGIN_ROOT = path.resolve(__dirname, '..', '..', '..');
const DEFAULT_MANIFEST = path.join(PLUGIN_ROOT, 'tests', 'performance', 'fixtures', 'performance-wordsets.json');
const DEFAULT_HISTORY = path.join(PLUGIN_ROOT, 'tests', 'performance', 'history', 'performance-history.jsonl');
const DEFAULT_REPORT = path.join(PLUGIN_ROOT, 'tests', 'performance', 'reports', 'performance-latest.json');
const DEFAULT_WORDSET_INITIAL_CARD_COUNT = 18;
const DEFAULT_RECORDER_QUEUE_INITIAL_CARD_COUNT = 3;
const DEFAULT_RECORDER_QUEUE_BATCH_SIZE = 6;
const MANIFEST_CHECKSUM_FORMAT = 'canonical-json-v1';
const SCENARIO_COMPARISON_KEY_FORMAT = 'scenario-contract-v1';
const MIN_COMPARABLE_RUNS = 3;
const DEFAULT_BENCHMARK_TIMEOUT_FIXED_OVERHEAD_MS = 30000;
const DEFAULT_BENCHMARK_TIMEOUT_PER_SCENARIO_OVERHEAD_MS = 5000;
const DEFAULT_BENCHMARK_TIMEOUT_PER_RUN_OVERHEAD_MS = 5000;

function readEnvFlag(name, fallback = false) {
  const rawValue = process.env[name];
  if (typeof rawValue === 'undefined' || rawValue === null || String(rawValue).trim() === '') {
    return fallback;
  }

  return /^(1|true|yes|on)$/i.test(String(rawValue).trim());
}

function resolvePluginPath(rawPath, fallback) {
  const selected = rawPath && String(rawPath).trim() ? String(rawPath).trim() : fallback;
  return path.isAbsolute(selected) ? selected : path.join(PLUGIN_ROOT, selected);
}

function loadPerformanceManifest() {
  const manifestPath = resolvePluginPath(process.env.LL_E2E_PERF_FIXTURE_MANIFEST, DEFAULT_MANIFEST);
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const expectedChecksum = String(process.env.LL_E2E_PERF_MANIFEST_SHA256 || '').trim();
  if (expectedChecksum !== '') {
    const actualChecksum = manifestChecksum(manifestPath);
    if (actualChecksum !== expectedChecksum) {
      throw new Error(
        `Performance fixture manifest checksum changed after runner verification: expected ${expectedChecksum}, found ${actualChecksum}.`
      );
    }
  }
  return {
    manifest,
    manifestPath
  };
}

function canonicalizeJsonValue(value) {
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) {
      throw new Error('Performance fixture manifests may contain only JSON integers within JavaScript\'s safe integer range.');
    }
    return Object.is(value, -0) ? 0 : value;
  }
  if (Array.isArray(value)) {
    return value.map((item) => canonicalizeJsonValue(item));
  }
  if (value && typeof value === 'object') {
    return Object.keys(value)
      .sort()
      .reduce((canonical, key) => {
        canonical[key] = canonicalizeJsonValue(value[key]);
        return canonical;
      }, {});
  }
  return value;
}

function canonicalManifestJson(manifest) {
  return JSON.stringify(canonicalizeJsonValue(manifest));
}

function manifestChecksum(filePath) {
  const manifest = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  return crypto.createHash('sha256').update(canonicalManifestJson(manifest)).digest('hex');
}

function safeGit(args) {
  try {
    return execFileSync('git', args, {
      cwd: PLUGIN_ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore']
    }).trim();
  } catch (_) {
    return '';
  }
}

function readPluginVersion() {
  const mainFile = path.join(PLUGIN_ROOT, 'language-learner-tools.php');
  const source = fs.readFileSync(mainFile, 'utf8');
  const constantMatch = source.match(/define\(\s*['"]LL_TOOLS_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\)/);
  if (constantMatch) {
    return constantMatch[1];
  }
  const headerMatch = source.match(/^\s*\*\s*Version:\s*([^\r\n]+)/m) || source.match(/^Version:\s*([^\r\n]+)/m);
  return headerMatch ? headerMatch[1].trim() : '';
}

function getRunMetadata() {
  const status = safeGit(['status', '--short']);
  return {
    pluginVersion: readPluginVersion(),
    git: {
      commit: safeGit(['rev-parse', '--short', 'HEAD']),
      describe: safeGit(['describe', '--tags', '--always', '--dirty']),
      dirty: status !== '',
      statusLineCount: status === '' ? 0 : status.split(/\r?\n/).length
    }
  };
}

function median(values) {
  const sorted = values.filter((value) => Number.isFinite(value)).slice().sort((left, right) => left - right);
  if (!sorted.length) {
    return 0;
  }
  const middle = Math.floor(sorted.length / 2);
  if (sorted.length % 2) {
    return sorted[middle];
  }
  return Math.round((sorted[middle - 1] + sorted[middle]) / 2);
}

function percentile(values, percentileValue) {
  const sorted = values.filter((value) => Number.isFinite(value)).slice().sort((left, right) => left - right);
  if (!sorted.length) {
    return 0;
  }
  const index = Math.min(sorted.length - 1, Math.ceil((percentileValue / 100) * sorted.length) - 1);
  return sorted[Math.max(0, index)];
}

function buildScenarioComparisonContract(scenario) {
  const semantics = String(scenario && scenario.comparisonSemantics ? scenario.comparisonSemantics : '').trim();
  if (semantics === '') {
    throw new Error(`Performance scenario ${scenario && scenario.name ? scenario.name : '(unnamed)'} is missing comparisonSemantics.`);
  }

  return {
    format: SCENARIO_COMPARISON_KEY_FORMAT,
    comparisonVersion: Math.max(1, Number(scenario.comparisonVersion || 1)),
    name: String(scenario.name || ''),
    semantics,
    kind: String(scenario.kind || ''),
    requiresAuth: !!scenario.requiresAuth,
    path: String(scenario.path || ''),
    selector: String(scenario.selector || ''),
    primaryMetric: String(scenario.primaryMetric || 'firstActionableMs'),
    minActionableCount: Math.max(1, Number(scenario.minActionableCount || 1)),
    action: String(scenario.action || ''),
    query: String(scenario.query || ''),
    expectedCategoryCount: Math.max(0, Number(scenario.expectedCategoryCount || 0)),
    initialCategoryCount: Math.max(0, Number(scenario.initialCategoryCount || 0)),
    batchSize: Math.max(0, Number(scenario.batchSize || 0)),
    maxBatchRequestCount: Math.max(0, Number(scenario.maxBatchRequestCount || 0)),
    maxConcurrentBatchRequestCount: Math.max(0, Number(scenario.maxConcurrentBatchRequestCount || 0))
  };
}

function scenarioComparisonKey(scenario) {
  const contract = buildScenarioComparisonContract(scenario);
  return crypto.createHash('sha256').update(canonicalManifestJson(contract)).digest('hex');
}

function legacyScenarioComparisonCore(scenario) {
  return {
    name: String(scenario && scenario.name ? scenario.name : ''),
    kind: String(scenario && scenario.kind ? scenario.kind : ''),
    requiresAuth: !!(scenario && scenario.requiresAuth),
    path: String(scenario && scenario.path ? scenario.path : ''),
    selector: String(scenario && scenario.selector ? scenario.selector : ''),
    primaryMetric: String(
      scenario && scenario.primaryMetric ? scenario.primaryMetric : 'firstActionableMs'
    ),
    minActionableCount: Math.max(1, Number(
      scenario && scenario.minActionableCount ? scenario.minActionableCount : 1
    ))
  };
}

function scenarioComparisonContractsMatch(currentScenario, previousScenario) {
  if (!currentScenario || !previousScenario) {
    return false;
  }

  const currentKey = String(currentScenario.comparisonKey || '');
  const previousKey = String(previousScenario.comparisonKey || '');
  if (
    currentKey !== ''
    && previousKey !== ''
    && String(currentScenario.comparisonKeyFormat || '') === SCENARIO_COMPARISON_KEY_FORMAT
    && String(previousScenario.comparisonKeyFormat || '') === SCENARIO_COMPARISON_KEY_FORMAT
  ) {
    return currentKey === previousKey;
  }

  // History written before scenario fingerprints is compatible only with an
  // unchanged v1 contract whose persisted core fields still match exactly.
  return previousKey === ''
    && Math.max(1, Number(currentScenario.comparisonVersion || 1)) === 1
    && canonicalManifestJson(legacyScenarioComparisonCore(currentScenario))
      === canonicalManifestJson(legacyScenarioComparisonCore(previousScenario));
}

function summarizeScenarioSamples(scenario, samples) {
  const metricNames = ['domContentLoadedMs', 'firstActionableMs', 'loadEventMs', 'responseStartMs', 'responseEndMs'];
  if (scenario.primaryMetric === 'interactionMs') {
    metricNames.push('interactionMs');
  }

  const medians = {};
  const p95 = {};
  metricNames.forEach((metricName) => {
    const values = samples.map((sample) => Number(sample[metricName] || 0));
    medians[metricName] = median(values);
    p95[metricName] = percentile(values, 95);
  });

  return {
    name: scenario.name,
    kind: scenario.kind,
    requiresAuth: !!scenario.requiresAuth,
    path: scenario.path,
    selector: scenario.selector,
    primaryMetric: scenario.primaryMetric,
    minActionableCount: scenario.minActionableCount || 1,
    comparisonVersion: Math.max(1, Number(scenario.comparisonVersion || 1)),
    comparisonKeyFormat: SCENARIO_COMPARISON_KEY_FORMAT,
    comparisonKey: scenarioComparisonKey(scenario),
    median: medians,
    p95,
    samples
  };
}

function pathForSlug(slug, suffix = '') {
  const normalizedSlug = String(slug || '').replace(/^\/+|\/+$/g, '');
  const normalizedSuffix = String(suffix || '').replace(/^\/+|\/+$/g, '');
  return `/${[normalizedSlug, normalizedSuffix].filter(Boolean).join('/')}/`;
}

function resolveBenchmarkWordsets(manifest) {
  return Array.isArray(manifest.wordsets) ? manifest.wordsets : [];
}

function resolveBenchmarkTargetWordset(manifest, wordsets) {
  const targetSize = String(manifest.benchmarkTargetSize || 'large');
  return wordsets.find((wordset) => String(wordset.size || '') === targetSize)
    || wordsets.find((wordset) => String(wordset.size || '') === 'large')
    || wordsets[wordsets.length - 1]
    || {};
}

function buildBenchmarkScenarios(manifest) {
  const wordsets = resolveBenchmarkWordsets(manifest);
  const targetWordset = resolveBenchmarkTargetWordset(manifest, wordsets);
  const targetSize = String(targetWordset.size || manifest.benchmarkTargetSize || 'large');
  const learnSlug = manifest.learnPage && manifest.learnPage.slug ? manifest.learnPage.slug : 'll-perf-learn';
  const scenarios = [
    {
      name: `learn-grid-${targetSize}-load`,
      kind: 'navigation',
      path: pathForSlug(learnSlug),
      selector: '.ll-quiz-page-trigger',
      minActionableCount: Number(targetWordset.categoryCount || 1),
      primaryMetric: 'firstActionableMs',
      comparisonVersion: 1,
      comparisonSemantics: 'visible-actionable-navigation-v1'
    }
  ];

  wordsets.forEach((wordset) => {
    const categoryCount = Number(wordset.categoryCount || 1);
    scenarios.push({
      name: `wordset-${wordset.size}-main-load`,
      kind: 'navigation',
      path: pathForSlug(wordset.slug),
      selector: '.ll-wordset-card[data-cat-id]:not(.ll-wordset-card--lazy-placeholder):not([data-ll-wordset-inline-placeholder])',
      minActionableCount: Math.min(categoryCount, DEFAULT_WORDSET_INITIAL_CARD_COUNT),
      primaryMetric: 'firstActionableMs',
      comparisonVersion: 1,
      comparisonSemantics: 'visible-actionable-navigation-v1'
    });
  });

  if (targetWordset.slug) {
    const settingsPath = pathForSlug(targetWordset.slug, 'settings');
    scenarios.push(
      {
        name: `wordset-${targetSize}-search-filter`,
        kind: 'interaction',
        path: pathForSlug(targetWordset.slug),
        selector: '[data-ll-wordset-page-search]',
        minActionableCount: 1,
        primaryMetric: 'interactionMs',
        action: 'wordset-search',
        query: `LLPerf ${targetSize} 01 01`,
        comparisonVersion: 2,
        comparisonSemantics: 'steady-category-search-results-v2'
      },
      {
        name: `wordset-${targetSize}-games-load`,
        kind: 'navigation',
        path: pathForSlug(targetWordset.slug, 'games'),
        selector: '[data-ll-wordset-games-root]',
        minActionableCount: 1,
        primaryMetric: 'firstActionableMs',
        comparisonVersion: 1,
        comparisonSemantics: 'visible-actionable-navigation-v1'
      },
      {
        name: `learn-grid-${targetSize}-quiz-popup`,
        kind: 'interaction',
        path: pathForSlug(learnSlug),
        selector: '.ll-quiz-page-trigger',
        minActionableCount: Number(targetWordset.categoryCount || 1),
        primaryMetric: 'interactionMs',
        action: 'quiz-popup',
        comparisonVersion: 2,
        comparisonSemantics: 'quiz-popup-mode-ready-v2'
      },
      {
        name: `wordset-${targetSize}-progress-load`,
        kind: 'navigation',
        path: pathForSlug(targetWordset.slug, 'progress'),
        selector: '[data-ll-wordset-progress-root]',
        minActionableCount: 1,
        primaryMetric: 'firstActionableMs',
        requiresAuth: true,
        comparisonVersion: 1,
        comparisonSemantics: 'visible-actionable-navigation-v1'
      },
      {
        name: `wordset-${targetSize}-progress-words-tab`,
        kind: 'interaction',
        path: pathForSlug(targetWordset.slug, 'progress'),
        selector: '[data-ll-wordset-progress-root]',
        minActionableCount: 1,
        primaryMetric: 'interactionMs',
        action: 'progress-words-tab',
        requiresAuth: true,
        comparisonVersion: 1,
        comparisonSemantics: 'progress-words-table-ready-v1'
      },
      {
        name: `wordset-${targetSize}-settings-hub-load`,
        kind: 'navigation',
        path: settingsPath,
        selector: '.ll-wordset-settings-page--hub[data-ll-wordset-settings-page]',
        minActionableCount: 1,
        primaryMetric: 'firstActionableMs',
        requiresAuth: true,
        comparisonVersion: 1,
        comparisonSemantics: 'visible-actionable-navigation-v1'
      }
    );

    const recorderQueueConfig = manifest.recorderQueue && typeof manifest.recorderQueue === 'object'
      ? manifest.recorderQueue
      : {};
    if (recorderQueueConfig.enabled) {
      const recorderQueuePath = `${settingsPath}?ll_wordset_tool=recorder-queues`;
      const expectedCategoryCount = Math.max(1, Number(
        recorderQueueConfig.expectedCategoryCount || targetWordset.categoryCount || 1
      ));
      const initialCategoryCount = Math.min(
        expectedCategoryCount,
        Math.max(1, Number(
          recorderQueueConfig.initialCategoryCount || DEFAULT_RECORDER_QUEUE_INITIAL_CARD_COUNT
        ))
      );
      const batchSize = Math.max(1, Number(
        recorderQueueConfig.batchSize || DEFAULT_RECORDER_QUEUE_BATCH_SIZE
      ));
      const maxBatchRequestCount = 1 + Math.ceil(
        Math.max(0, expectedCategoryCount - initialCategoryCount) / batchSize
      );
      const maxConcurrentBatchRequestCount = 1;
      scenarios.push(
        {
          name: `wordset-${targetSize}-recorder-queues-initial-load`,
          kind: 'navigation',
          path: recorderQueuePath,
          selector: '[data-ll-recorder-queue-summary-root] .ll-wordset-recorder-queue-category-card:not([data-ll-recorder-queue-summary-placeholder])',
          minActionableCount: initialCategoryCount,
          primaryMetric: 'firstActionableMs',
          requiresAuth: true,
          expectedCategoryCount,
          initialCategoryCount,
          batchSize,
          maxBatchRequestCount,
          maxConcurrentBatchRequestCount,
          comparisonVersion: 2,
          comparisonSemantics: 'recorder-queue-initial-stream-v2'
        },
        {
          name: `wordset-${targetSize}-recorder-queues-lazy-completion`,
          kind: 'interaction',
          path: recorderQueuePath,
          selector: '[data-ll-recorder-queue-summary-root]',
          minActionableCount: 1,
          primaryMetric: 'interactionMs',
          action: 'recorder-queue-lazy-completion',
          expectedCategoryCount,
          initialCategoryCount,
          batchSize,
          maxBatchRequestCount,
          maxConcurrentBatchRequestCount,
          requiresAuth: true,
          comparisonVersion: 2,
          comparisonSemantics: 'recorder-queue-auto-serial-completion-v2'
        }
      );
    }
  }

  return scenarios;
}

function benchmarkRequiresAuthentication(manifest) {
  if (manifest && manifest.requiresAuthentication === true) {
    return true;
  }

  const recorderQueue = manifest && manifest.recorderQueue;
  return !!(recorderQueue && typeof recorderQueue === 'object' && recorderQueue.enabled);
}

function validateRecorderQueueCompletion(
  scenario,
  finalLoadedCategoryCount,
  batchRequestCount = 0,
  maxConcurrentBatchRequestCount = 0
) {
  const expectedCategoryCount = Math.max(1, Number(
    scenario && scenario.expectedCategoryCount ? scenario.expectedCategoryCount : 1
  ));
  const actualCategoryCount = Math.max(0, Number(finalLoadedCategoryCount) || 0);
  if (actualCategoryCount !== expectedCategoryCount) {
    throw new Error(
      `Recorder queue lazy completion loaded ${actualCategoryCount} categories; expected ${expectedCategoryCount}.`
    );
  }

  const actualBatchRequestCount = Math.max(0, Number(batchRequestCount) || 0);
  const maxBatchRequestCount = Math.max(0, Number(
    scenario && scenario.maxBatchRequestCount ? scenario.maxBatchRequestCount : 0
  ));
  if (maxBatchRequestCount > 0 && actualBatchRequestCount > maxBatchRequestCount) {
    throw new Error(
      `Recorder queue lazy completion issued ${actualBatchRequestCount} summary requests; expected at most ${maxBatchRequestCount}.`
    );
  }

  const actualMaxConcurrentBatchRequestCount = Math.max(
    0,
    Number(maxConcurrentBatchRequestCount) || 0
  );
  const allowedMaxConcurrentBatchRequestCount = Math.max(0, Number(
    scenario && scenario.maxConcurrentBatchRequestCount
      ? scenario.maxConcurrentBatchRequestCount
      : 0
  ));
  if (
    allowedMaxConcurrentBatchRequestCount > 0
    && actualMaxConcurrentBatchRequestCount > allowedMaxConcurrentBatchRequestCount
  ) {
    throw new Error(
      `Recorder queue lazy completion reached ${actualMaxConcurrentBatchRequestCount} concurrent summary requests; expected at most ${allowedMaxConcurrentBatchRequestCount}.`
    );
  }

  return actualCategoryCount;
}

function calculateBenchmarkTestTimeout(scenarios, options = {}) {
  // The benchmark is intentionally one serial test so it can emit one report.
  // Reserve the sum of every operation's own timeout; otherwise Playwright's
  // test-wide clock can interrupt a late scenario before that operation gets
  // the timeout promised by its local assertion or request wait.
  const runnableScenarios = Array.isArray(scenarios) ? scenarios : [];
  const runsPerScenario = Math.max(1, Math.ceil(Number(options.runsPerScenario) || 1));
  const warmupAttempts = Math.max(1, Math.round(Number(options.warmupAttempts) || 1));
  const warmupRetryDelayMs = Math.max(0, Number(options.warmupRetryDelayMs) || 0);
  const maxDomContentLoadedMs = Math.max(1, Number(options.maxDomContentLoadedMs) || 1);
  const maxActionableMs = Math.max(1, Number(options.maxActionableMs) || 1);
  const maxLoadMs = Math.max(1, Number(options.maxLoadMs) || 1);
  const maxInteractionMs = Math.max(1, Number(options.maxInteractionMs) || 1);
  const maxRecorderQueueCompletionMs = Math.max(
    1,
    Number(options.maxRecorderQueueCompletionMs) || maxInteractionMs
  );
  const navigationGraceMs = Math.max(0, Number(options.navigationGraceMs) || 0);
  const fixedOverheadMs = Math.max(
    0,
    Number.isFinite(Number(options.fixedOverheadMs))
      ? Number(options.fixedOverheadMs)
      : DEFAULT_BENCHMARK_TIMEOUT_FIXED_OVERHEAD_MS
  );
  const perScenarioOverheadMs = Math.max(
    0,
    Number.isFinite(Number(options.perScenarioOverheadMs))
      ? Number(options.perScenarioOverheadMs)
      : DEFAULT_BENCHMARK_TIMEOUT_PER_SCENARIO_OVERHEAD_MS
  );
  const perRunOverheadMs = Math.max(
    0,
    Number.isFinite(Number(options.perRunOverheadMs))
      ? Number(options.perRunOverheadMs)
      : DEFAULT_BENCHMARK_TIMEOUT_PER_RUN_OVERHEAD_MS
  );
  const navigationTimeoutMs = Math.max(
    maxDomContentLoadedMs,
    maxActionableMs,
    maxLoadMs
  ) + navigationGraceMs;
  const warmupBudgetMs = (warmupAttempts * navigationTimeoutMs)
    + (Math.max(0, warmupAttempts - 1) * warmupRetryDelayMs);
  const measuredNavigationBudgetMs = navigationTimeoutMs
    + (2 * maxActionableMs)
    + maxLoadMs;

  const scenarioBudgetsMs = runnableScenarios.map((scenario) => {
    let actionBudgetMs = 0;
    if (scenario && scenario.kind === 'interaction') {
      const scenarioInteractionMs = scenario.action === 'recorder-queue-lazy-completion'
        ? maxRecorderQueueCompletionMs
        : maxInteractionMs;
      actionBudgetMs = maxActionableMs + scenarioInteractionMs;
      if (scenario.action === 'quiz-popup') {
        actionBudgetMs += maxInteractionMs;
      }
    }

    const runBudgetMs = warmupBudgetMs
      + measuredNavigationBudgetMs
      + actionBudgetMs
      + perRunOverheadMs;
    return perScenarioOverheadMs + (runsPerScenario * runBudgetMs);
  });

  return Math.ceil(fixedOverheadMs + scenarioBudgetsMs.reduce((total, budget) => total + budget, 0));
}

function readHistoryRecords(historyFile) {
  if (!historyFile || !fs.existsSync(historyFile)) {
    return [];
  }

  return fs.readFileSync(historyFile, 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      try {
        return JSON.parse(line);
      } catch (_) {
        return null;
      }
    })
    .filter(Boolean);
}

function sameThrottleProfile(left, right) {
  const leftProfile = left || {};
  const rightProfile = right || {};
  return Number(leftProfile.latencyMs || 0) === Number(rightProfile.latencyMs || 0)
    && Number(leftProfile.downloadKbps || 0) === Number(rightProfile.downloadKbps || 0)
    && Number(leftProfile.uploadKbps || 0) === Number(rightProfile.uploadKbps || 0)
    && Number(leftProfile.cpuSlowdownRate || 1) === Number(rightProfile.cpuSlowdownRate || 1);
}

function findPreviousComparableRun(records, currentRecord) {
  if (Number(currentRecord && currentRecord.runsPerScenario ? currentRecord.runsPerScenario : 0) < MIN_COMPARABLE_RUNS) {
    return null;
  }

  const currentManifestSha = currentRecord
    && currentRecord.fixtureManifest
    && currentRecord.fixtureManifest.sha256
    ? String(currentRecord.fixtureManifest.sha256)
    : '';
  const currentManifestChecksumFormat = currentRecord
    && currentRecord.fixtureManifest
    && currentRecord.fixtureManifest.checksumFormat
    ? String(currentRecord.fixtureManifest.checksumFormat)
    : '';
  const manifestScopeCandidates = [];
  const candidates = [];
  for (let index = records.length - 1; index >= 0; index -= 1) {
    const candidate = records[index];
    if (!candidate || candidate.fixtureVersion !== currentRecord.fixtureVersion) {
      continue;
    }
    if (!sameThrottleProfile(candidate.throttleProfile, currentRecord.throttleProfile)) {
      continue;
    }
    manifestScopeCandidates.push(candidate);
    if (
      !candidate.git
      || candidate.git.dirty !== false
      || Number(candidate.git.statusLineCount || 0) !== 0
      || Number(candidate.runsPerScenario || 0) < MIN_COMPARABLE_RUNS
    ) {
      continue;
    }
    if (Number(candidate.runsPerScenario || 0) !== Number(currentRecord.runsPerScenario || 0)) {
      continue;
    }
    candidates.push(candidate);
  }

  if (currentManifestChecksumFormat === MANIFEST_CHECKSUM_FORMAT) {
    const canonicalHistoryExists = manifestScopeCandidates.some((candidate) => (
      candidate.fixtureManifest
      && String(candidate.fixtureManifest.checksumFormat || '') === MANIFEST_CHECKSUM_FORMAT
    ));
    if (canonicalHistoryExists) {
      return candidates.find((candidate) => (
        candidate.fixtureManifest
        && String(candidate.fixtureManifest.checksumFormat || '') === MANIFEST_CHECKSUM_FORMAT
        && currentManifestSha !== ''
        && String(candidate.fixtureManifest.sha256 || '') === currentManifestSha
      )) || null;
    }

    // The history predates canonical-json-v1. Fixture version plus throttle
    // form the one-time migration gate because raw hashes varied by CRLF/LF.
    return candidates[0] || null;
  }

  if (currentManifestSha !== '') {
    return candidates.find((candidate) => (
      candidate.fixtureManifest
      && String(candidate.fixtureManifest.sha256 || '') === currentManifestSha
    )) || null;
  }

  return candidates[0] || null;
}

function compareWithPrevious(currentRecord, previousRecord, options = {}) {
  const maxRegressionRatio = Number(options.maxRegressionRatio || 0.2);
  const maxRegressionMs = Number(options.maxRegressionMs || 500);
  const previousByName = {};
  (previousRecord && Array.isArray(previousRecord.scenarios) ? previousRecord.scenarios : []).forEach((scenario) => {
    previousByName[scenario.name] = scenario;
  });

  return (currentRecord.scenarios || []).map((currentScenario) => {
    const previousScenario = previousByName[currentScenario.name] || null;
    const metricName = currentScenario.primaryMetric || 'firstActionableMs';
    const currentValue = Number(currentScenario.median && currentScenario.median[metricName] ? currentScenario.median[metricName] : 0);
    const scenarioContractMatches = scenarioComparisonContractsMatch(currentScenario, previousScenario);
    const previousValue = Number(
      scenarioContractMatches
      && previousScenario.median
      && previousScenario.median[metricName]
        ? previousScenario.median[metricName]
        : 0
    );
    const deltaMs = previousValue > 0 ? currentValue - previousValue : 0;
    const ratio = previousValue > 0 ? deltaMs / previousValue : 0;
    const failed = previousValue > 0 && deltaMs > maxRegressionMs && ratio > maxRegressionRatio;

    return {
      name: currentScenario.name,
      metric: metricName,
      currentMs: currentValue,
      previousMs: previousValue,
      comparable: scenarioContractMatches && previousValue > 0,
      skipReason: scenarioContractMatches ? '' : 'scenario-contract-changed',
      deltaMs,
      regressionRatio: Number(ratio.toFixed(4)),
      failed
    };
  });
}

function appendHistoryRecord(historyFile, record) {
  fs.mkdirSync(path.dirname(historyFile), { recursive: true });
  fs.appendFileSync(historyFile, `${JSON.stringify(record)}\n`, 'utf8');
}

function buildBenchmarkReport(record, previousRecord, historyFile) {
  const comparisonByName = {};
  (record.comparison || []).forEach((row) => {
    comparisonByName[row.name] = row;
  });

  return {
    schemaVersion: 2,
    generatedAt: new Date().toISOString(),
    historyFile,
    previousRecord: previousRecord
      ? {
        recordedAt: previousRecord.recordedAt,
        pluginVersion: previousRecord.pluginVersion,
        git: previousRecord.git
      }
      : null,
    current: record,
    summary: (record.scenarios || []).map((scenario) => {
      const metricName = scenario.primaryMetric || 'firstActionableMs';
      const comparison = comparisonByName[scenario.name] || {};
      const medianMs = Number(scenario.median && scenario.median[metricName] ? scenario.median[metricName] : 0);
      const p95Ms = Number(scenario.p95 && scenario.p95[metricName] ? scenario.p95[metricName] : 0);
      return {
        name: scenario.name,
        metric: metricName,
        medianMs,
        p95Ms,
        previousMs: Number(comparison.previousMs || 0),
        comparable: !!comparison.comparable,
        skipReason: String(comparison.skipReason || ''),
        deltaMs: Number(comparison.deltaMs || 0),
        regressionRatio: Number(comparison.regressionRatio || 0),
        failed: !!comparison.failed
      };
    })
  };
}

function formatBenchmarkReportMarkdown(report) {
  const lines = [];
  lines.push('# LL Tools Performance Benchmark');
  lines.push('');
  lines.push(`- Generated: ${report.generatedAt}`);
  lines.push(`- Fixture version: ${report.current.fixtureVersion || ''}`);
  lines.push(`- Plugin version: ${report.current.pluginVersion || ''}`);
  lines.push(`- Git: ${(report.current.git && report.current.git.describe) || ''}`);
  lines.push(`- Runs per scenario: ${report.current.runsPerScenario || 0}`);
  lines.push(`- History file: ${report.historyFile}`);
  lines.push(`- Previous run: ${report.previousRecord ? report.previousRecord.recordedAt : 'none'}`);
  lines.push('');
  lines.push('| Scenario | Metric | Median | P95 | Previous | Delta | Result |');
  lines.push('| --- | --- | ---: | ---: | ---: | ---: | --- |');
  (report.summary || []).forEach((row) => {
    const previous = row.previousMs > 0 ? `${row.previousMs} ms` : '';
    const delta = row.previousMs > 0 ? `${row.deltaMs >= 0 ? '+' : ''}${row.deltaMs} ms` : '';
    const result = row.comparable ? (row.failed ? 'FAIL' : 'pass') : 'NEW BASELINE';
    lines.push(`| ${row.name} | ${row.metric} | ${row.medianMs} ms | ${row.p95Ms} ms | ${previous} | ${delta} | ${result} |`);
  });
  lines.push('');
  return `${lines.join('\n')}\n`;
}

function markdownReportPath(reportFile) {
  if (/\.md$/i.test(reportFile)) {
    return reportFile;
  }
  if (/\.json$/i.test(reportFile)) {
    return reportFile.replace(/\.json$/i, '.md');
  }
  return `${reportFile}.md`;
}

function jsonReportPath(reportFile) {
  if (/\.json$/i.test(reportFile)) {
    return reportFile;
  }
  if (/\.md$/i.test(reportFile)) {
    return reportFile.replace(/\.md$/i, '.json');
  }
  return `${reportFile}.json`;
}

function writeBenchmarkReport(reportFile, report) {
  const jsonFile = jsonReportPath(reportFile);
  const markdownFile = markdownReportPath(reportFile);
  fs.mkdirSync(path.dirname(jsonFile), { recursive: true });
  fs.mkdirSync(path.dirname(markdownFile), { recursive: true });
  fs.writeFileSync(jsonFile, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  fs.writeFileSync(markdownFile, formatBenchmarkReportMarkdown(report), 'utf8');
  return {
    json: jsonFile,
    markdown: markdownFile
  };
}

module.exports = {
  DEFAULT_HISTORY,
  DEFAULT_REPORT,
  MANIFEST_CHECKSUM_FORMAT,
  MIN_COMPARABLE_RUNS,
  SCENARIO_COMPARISON_KEY_FORMAT,
  benchmarkRequiresAuthentication,
  buildBenchmarkReport,
  buildBenchmarkScenarios,
  buildScenarioComparisonContract,
  calculateBenchmarkTestTimeout,
  compareWithPrevious,
  canonicalManifestJson,
  manifestChecksum,
  findPreviousComparableRun,
  getRunMetadata,
  loadPerformanceManifest,
  legacyScenarioComparisonCore,
  readEnvFlag,
  readHistoryRecords,
  resolvePluginPath,
  resolveBenchmarkWordsets,
  resolveBenchmarkTargetWordset,
  scenarioComparisonKey,
  scenarioComparisonContractsMatch,
  summarizeScenarioSamples,
  validateRecorderQueueCompletion,
  appendHistoryRecord,
  formatBenchmarkReportMarkdown,
  writeBenchmarkReport
};
