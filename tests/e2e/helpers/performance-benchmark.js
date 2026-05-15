const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const PLUGIN_ROOT = path.resolve(__dirname, '..', '..', '..');
const DEFAULT_MANIFEST = path.join(PLUGIN_ROOT, 'tests', 'performance', 'fixtures', 'performance-wordsets.json');
const DEFAULT_HISTORY = path.join(PLUGIN_ROOT, 'tests', 'performance', 'history', 'performance-history.jsonl');
const DEFAULT_WORDSET_INITIAL_CARD_COUNT = 18;

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
  return {
    manifest,
    manifestPath
  };
}

function fileChecksum(filePath) {
  const crypto = require('crypto');
  return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex');
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

function buildBenchmarkScenarios(manifest) {
  const wordsets = Array.isArray(manifest.wordsets) ? manifest.wordsets : [];
  const largeWordset = wordsets.find((wordset) => String(wordset.size || '') === 'large') || wordsets[wordsets.length - 1] || {};
  const learnSlug = manifest.learnPage && manifest.learnPage.slug ? manifest.learnPage.slug : 'll-perf-learn';
  const scenarios = [
    {
      name: 'learn-grid-large-load',
      kind: 'navigation',
      path: pathForSlug(learnSlug),
      selector: '.ll-quiz-page-trigger',
      minActionableCount: Number(largeWordset.categoryCount || 1),
      primaryMetric: 'firstActionableMs'
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
      primaryMetric: 'firstActionableMs'
    });
  });

  if (largeWordset.slug) {
    scenarios.push(
      {
        name: 'wordset-large-search-filter',
        kind: 'interaction',
        path: pathForSlug(largeWordset.slug),
        selector: '[data-ll-wordset-page-search]',
        minActionableCount: 1,
        primaryMetric: 'interactionMs',
        action: 'wordset-search',
        query: 'LLPerf large 01 01'
      },
      {
        name: 'wordset-large-games-load',
        kind: 'navigation',
        path: pathForSlug(largeWordset.slug, 'games'),
        selector: '[data-ll-wordset-games-root]',
        minActionableCount: 1,
        primaryMetric: 'firstActionableMs'
      },
      {
        name: 'learn-grid-large-quiz-popup',
        kind: 'interaction',
        path: pathForSlug(learnSlug),
        selector: '.ll-quiz-page-trigger',
        minActionableCount: Number(largeWordset.categoryCount || 1),
        primaryMetric: 'interactionMs',
        action: 'quiz-popup'
      },
      {
        name: 'wordset-large-progress-load',
        kind: 'navigation',
        path: pathForSlug(largeWordset.slug, 'progress'),
        selector: '[data-ll-wordset-progress-root]',
        minActionableCount: 1,
        primaryMetric: 'firstActionableMs',
        requiresAuth: true
      },
      {
        name: 'wordset-large-progress-words-tab',
        kind: 'interaction',
        path: pathForSlug(largeWordset.slug, 'progress'),
        selector: '[data-ll-wordset-progress-root]',
        minActionableCount: 1,
        primaryMetric: 'interactionMs',
        action: 'progress-words-tab',
        requiresAuth: true
      }
    );
  }

  return scenarios;
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
  for (let index = records.length - 1; index >= 0; index -= 1) {
    const candidate = records[index];
    if (!candidate || candidate.fixtureVersion !== currentRecord.fixtureVersion) {
      continue;
    }
    if (!sameThrottleProfile(candidate.throttleProfile, currentRecord.throttleProfile)) {
      continue;
    }
    return candidate;
  }

  return null;
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
    const previousValue = Number(previousScenario && previousScenario.median && previousScenario.median[metricName] ? previousScenario.median[metricName] : 0);
    const deltaMs = previousValue > 0 ? currentValue - previousValue : 0;
    const ratio = previousValue > 0 ? deltaMs / previousValue : 0;
    const failed = previousValue > 0 && deltaMs > maxRegressionMs && ratio > maxRegressionRatio;

    return {
      name: currentScenario.name,
      metric: metricName,
      currentMs: currentValue,
      previousMs: previousValue,
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

module.exports = {
  DEFAULT_HISTORY,
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
  appendHistoryRecord
};
