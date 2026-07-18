const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const {
  MANIFEST_CHECKSUM_FORMAT,
  benchmarkRequiresAuthentication,
  buildBenchmarkScenarios,
  calculateBenchmarkTestTimeout,
  canonicalManifestJson,
  findPreviousComparableRun,
  loadPerformanceManifest,
  manifestChecksum,
  validateRecorderQueueCompletion
} = require('../helpers/performance-benchmark');

const pluginRoot = path.resolve(__dirname, '..', '..', '..');
const gencManifestPath = path.join(
  pluginRoot,
  'tests',
  'performance',
  'fixtures',
  'performance-wordsets-genc.json'
);
const fixtureSeederPath = path.join(
  pluginRoot,
  'tests',
  'performance',
  'seed-performance-fixtures.php'
);
const benchmarkRunnerPath = path.join(pluginRoot, 'tests', 'bin', 'run-performance-benchmark.sh');
const benchmarkSpecPath = path.join(pluginRoot, 'tests', 'e2e', 'specs', 'performance-benchmark.spec.js');
const e2eRunnerPath = path.join(pluginRoot, 'tests', 'bin', 'run-e2e.sh');
const manifestVerifierPath = path.join(
  pluginRoot,
  'tests',
  'performance',
  'verify-performance-manifest.php'
);

function phpBinary() {
  const configured = String(process.env.PHP_BIN || '').trim();
  if (configured) {
    return configured;
  }

  const localWindowsPhp = 'C:\\php\\8.4\\php.exe';
  return process.platform === 'win32' && fs.existsSync(localWindowsPhp)
    ? localWindowsPhp
    : 'php';
}

function phpFunctionBlock(source, name) {
  const start = source.indexOf(`function ${name}(`);
  expect(start, `Missing PHP function ${name}`).toBeGreaterThanOrEqual(0);
  const end = source.indexOf('\nfunction ', start + 1);
  return source.slice(start, end === -1 ? source.length : end);
}

function pathForBash(filePath) {
  if (process.platform !== 'win32' || !/^[A-Za-z]:[\\/]/.test(filePath)) {
    return filePath;
  }

  const drive = filePath[0].toLowerCase();
  const remainder = filePath.slice(2).replace(/\\/g, '/');
  let drivePrefix = `/${drive}`;
  try {
    execFileSync('bash', ['-lc', `test -d /mnt/${drive}`], { stdio: 'ignore' });
    drivePrefix = `/mnt/${drive}`;
  } catch (_) {
    // Git Bash uses /c/... while WSL uses /mnt/c/....
  }
  return `${drivePrefix}${remainder}`;
}

test('performance manifest checksum is canonical across formatting, key order, and line endings', async ({}, testInfo) => {
  const expectedChecksum = 'bcb6e8fe385c21e8f27036bfbf5e461d92fa018c6db176dc95b8e365882d406e';
  const leftPath = testInfo.outputPath('manifest-lf.json');
  const rightPath = testInfo.outputPath('manifest-crlf.json');
  fs.mkdirSync(path.dirname(leftPath), { recursive: true });
  fs.writeFileSync(leftPath, '{\n  "b": [3, {"z": "last", "a": "first"}],\n  "a": 1\n}\n', 'utf8');
  fs.writeFileSync(rightPath, '{\r\n  "a": 1,\r\n  "b": [3, {"a": "first", "z": "last"}]\r\n}\r\n', 'utf8');

  expect(canonicalManifestJson({ b: [3, { z: 'last', a: 'first' }], a: 1 }))
    .toBe('{"a":1,"b":[3,{"a":"first","z":"last"}]}');
  expect(canonicalManifestJson({ number: 1.0, empty: {} })).toBe('{"empty":{},"number":1}');
  expect(() => canonicalManifestJson({ fraction: 0.5 })).toThrow(/safe integer range/);
  expect(manifestChecksum(leftPath)).toBe(expectedChecksum);
  expect(manifestChecksum(rightPath)).toBe(expectedChecksum);
});

test('stored fixture verifier accepts explicit JSON when cross-runtime stdin is unusable', async ({}, testInfo) => {
  const manifestPath = testInfo.outputPath('argv-contract-manifest.json');
  const manifest = {
    fixtureVersion: 'argv-contract-v1',
    benchmarkTargetSize: 'small',
    wordsets: [{ size: 'small', slug: 'argv-contract', categoryCount: 1, wordsPerCategory: 1 }]
  };
  fs.mkdirSync(path.dirname(manifestPath), { recursive: true });
  fs.writeFileSync(manifestPath, `${JSON.stringify(manifest)}\n`, 'utf8');

  const storedFixture = JSON.stringify({
    fixture_version: manifest.fixtureVersion,
    manifest_sha256: manifestChecksum(manifestPath),
    manifest_checksum_format: MANIFEST_CHECKSUM_FORMAT
  });
  const output = execFileSync(
    phpBinary(),
    [manifestVerifierPath, '--verify-stored', manifestPath, storedFixture],
    {
      encoding: 'utf8',
      input: '{invalid redirected stdin'
    }
  );

  expect(output).toContain('Stored performance fixture verified: version argv-contract-v1');
});

test('benchmark runtime rejects a manifest changed after runner verification', async ({}, testInfo) => {
  const manifestPath = testInfo.outputPath('runtime-manifest.json');
  const manifest = {
    fixtureVersion: 'runtime-contract-v1',
    benchmarkTargetSize: 'small',
    wordsets: [{ size: 'small', slug: 'runtime-contract', categoryCount: 1, wordsPerCategory: 1 }]
  };
  fs.mkdirSync(path.dirname(manifestPath), { recursive: true });
  fs.writeFileSync(manifestPath, `${JSON.stringify(manifest)}\n`, 'utf8');

  const previousManifest = process.env.LL_E2E_PERF_FIXTURE_MANIFEST;
  const previousChecksum = process.env.LL_E2E_PERF_MANIFEST_SHA256;
  try {
    process.env.LL_E2E_PERF_FIXTURE_MANIFEST = manifestPath;
    process.env.LL_E2E_PERF_MANIFEST_SHA256 = manifestChecksum(manifestPath);
    expect(loadPerformanceManifest().manifest.fixtureVersion).toBe('runtime-contract-v1');

    process.env.LL_E2E_PERF_MANIFEST_SHA256 = '0'.repeat(64);
    expect(() => loadPerformanceManifest()).toThrow(/checksum changed after runner verification/);
  } finally {
    if (typeof previousManifest === 'undefined') {
      delete process.env.LL_E2E_PERF_FIXTURE_MANIFEST;
    } else {
      process.env.LL_E2E_PERF_FIXTURE_MANIFEST = previousManifest;
    }
    if (typeof previousChecksum === 'undefined') {
      delete process.env.LL_E2E_PERF_MANIFEST_SHA256;
    } else {
      process.env.LL_E2E_PERF_MANIFEST_SHA256 = previousChecksum;
    }
  }
});

test('profile runner locks one authoritative manifest history and report through child env loading', async ({}, testInfo) => {
  const runner = fs.readFileSync(benchmarkRunnerPath, 'utf8');
  const e2eRunner = fs.readFileSync(e2eRunnerPath, 'utf8');
  const configureProfile = runner.slice(
    runner.indexOf('configure_perf_profile()'),
    runner.indexOf('\ncanonical_perf_manifest_path()')
  );

  expect(configureProfile).toContain('export LL_TOOLS_PERF_FIXTURE_MANIFEST="$PERF_MANIFEST_PATH"');
  expect(configureProfile).toContain('export LL_E2E_PERF_FIXTURE_MANIFEST="$manifest_rel"');
  expect(configureProfile).toContain('export LL_E2E_PERF_HISTORY_FILE="$history_rel"');
  expect(configureProfile).toContain('export LL_E2E_PERF_REPORT_FILE="$report_rel"');
  expect(configureProfile).not.toContain('LL_TOOLS_PERF_FIXTURE_MANIFEST:-$');
  expect(runner).toContain('ll_tools_perf_caller_env_has_key "$key"');
  expect(runner).toContain('export LL_E2E_PERF_CONFIG_LOCKED=1');
  expect(runner).toContain('option get ll_tools_performance_fixture_manifest');
  expect(runner).toContain('--verify-stored "$PERF_MANIFEST_PATH"');
  expect(runner).toContain('"$stored_fixture_json"; then');
  expect(runner).not.toContain('declare -A');
  expect(e2eRunner).not.toContain('declare -A');

  const captureIndex = e2eRunner.indexOf('locked_perf_values+=("${!env_var}")');
  const envLoadIndex = e2eRunner.indexOf('load_env_file_literal "$TESTS_DIR/.env"');
  const restoreIndex = e2eRunner.indexOf('export "$env_var=${locked_perf_values[$locked_index]}"');
  expect(captureIndex).toBeGreaterThanOrEqual(0);
  expect(envLoadIndex).toBeGreaterThan(captureIndex);
  expect(restoreIndex).toBeGreaterThan(envLoadIndex);
  expect(e2eRunner).toContain('LL_E2E_PERF_FIXTURE_MANIFEST');
  expect(e2eRunner).toContain('LL_E2E_PERF_HISTORY_FILE');
  expect(e2eRunner).toContain('LL_E2E_PERF_REPORT_FILE');
  expect(e2eRunner).toContain('LL_E2E_PERF_MANIFEST_SHA256');
  expect(e2eRunner).toContain('[[ "$env_var" == LL_E2E_PERF_* ]]');
  expect(e2eRunner).toContain('LL_E2E_PERF_RUNS');
  expect(e2eRunner).toContain('LL_E2E_PERF_WRITE_HISTORY');
  expect(e2eRunner).toContain('LL_E2E_PERF_COMPARE_HISTORY');
  expect(e2eRunner).toContain('LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS');

  const tempRoot = testInfo.outputPath('locked-runner-env');
  const tempTestsDir = path.join(tempRoot, 'tests');
  const tempBinDir = path.join(tempTestsDir, 'bin');
  const tempRunnerPath = path.join(tempBinDir, 'run-e2e.sh');
  fs.mkdirSync(tempBinDir, { recursive: true });
  fs.copyFileSync(e2eRunnerPath, tempRunnerPath);

  const conflictingValues = {
    LL_E2E_PERF_FIXTURE_MANIFEST: 'env-file-manifest.json',
    LL_E2E_PERF_HISTORY_FILE: 'env-file-history.jsonl',
    LL_E2E_PERF_REPORT_FILE: 'env-file-report.json',
    LL_E2E_PERF_MANIFEST_SHA256: 'f'.repeat(64),
    LL_E2E_PERF_RUNS: '9',
    LL_E2E_PERF_WRITE_HISTORY: '1',
    LL_E2E_PERF_COMPARE_HISTORY: '1',
    LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS: '999999',
    LL_E2E_PERF_MAX_ACTIONABLE_MS: '888888'
  };
  const conflictingEnv = Object.entries(conflictingValues)
    .map(([key, value]) => `${key}=${value}`)
    .join('\n');
  fs.writeFileSync(path.join(tempTestsDir, '.env'), `${conflictingEnv}\n`, 'utf8');
  fs.writeFileSync(path.join(tempTestsDir, '.env.local'), `${conflictingEnv}\n`, 'utf8');

  const callerValues = {
    LL_E2E_PERF_CONFIG_LOCKED: '1',
    LL_E2E_PERF_CONFIG_CONTRACT_ONLY: '1',
    LL_E2E_PERF_FIXTURE_MANIFEST: 'caller-manifest.json',
    LL_E2E_PERF_HISTORY_FILE: 'caller-history.jsonl',
    LL_E2E_PERF_REPORT_FILE: 'caller-report.json',
    LL_E2E_PERF_MANIFEST_SHA256: 'a'.repeat(64),
    LL_E2E_PERF_RUNS: '1',
    LL_E2E_PERF_WRITE_HISTORY: '0',
    LL_E2E_PERF_COMPARE_HISTORY: '0',
    LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS: '120000',
    LL_E2E_PERF_MAX_ACTIONABLE_MS: '30000'
  };
  const wslenv = [
    process.env.WSLENV || '',
    ...Object.keys(callerValues)
  ].filter(Boolean).join(':');
  const output = execFileSync('bash', [pathForBash(tempRunnerPath)], {
    cwd: pluginRoot,
    encoding: 'utf8',
    env: {
      ...process.env,
      ...callerValues,
      WSLENV: wslenv
    }
  });
  const resolvedValues = Object.fromEntries(output.trim().split(/\r?\n/).map((line) => {
    const separator = line.indexOf('=');
    return [line.slice(0, separator), line.slice(separator + 1)];
  }));
  Object.entries(callerValues).forEach(([key, value]) => {
    if (key !== 'LL_E2E_PERF_CONFIG_LOCKED') {
      expect(resolvedValues[key]).toBe(value);
    }
  });
});

test('Git Bash runner preserves browser URL path environment values for Windows Node', async () => {
  const runner = fs.readFileSync(e2eRunnerPath, 'utf8');
  const guardIndex = runner.indexOf('append_msys2_env_conv_excl_var()');
  const playwrightIndex = runner.indexOf('exec npx playwright test');

  expect(guardIndex).toBeGreaterThanOrEqual(0);
  expect(playwrightIndex).toBeGreaterThan(guardIndex);
  expect(runner).toContain('MSYS2_ENV_CONV_EXCL');
  expect(runner).toContain('append_msys2_env_conv_excl_var "$env_var"');
  expect(runner).toContain('LL_E2E_LEARN_PATH \\');
  expect(runner).toContain('LL_E2E_STANDALONE_PATH \\');
  expect(runner).toContain('LL_E2E_PAGE_SPEED_PATH');
});

test('Genç profile matches production-scale dimensions and includes settings and recorder queue scenarios', async () => {
  const manifest = JSON.parse(fs.readFileSync(gencManifestPath, 'utf8'));
  const target = manifest.wordsets.find((wordset) => wordset.size === manifest.benchmarkTargetSize);
  expect(target).toMatchObject({
    size: 'genc',
    categoryCount: 209,
    wordsPerCategory: 13
  });
  expect(target.categoryCount * target.wordsPerCategory).toBe(2717);
  expect(manifest.media).toMatchObject({
    imagePerWord: true,
    audioPerWord: true,
    audioPerWordCount: 3
  });
  expect(target.categoryCount * target.wordsPerCategory * manifest.media.audioPerWordCount).toBe(8151);
  expect(manifest.recorderQueue).toMatchObject({
    enabled: true,
    wordsetSlug: 'll-perf-genc'
  });
  expect(manifest.recorderQueue.desiredRecordingTypes).toContain('question');
  expect(benchmarkRequiresAuthentication(manifest)).toBe(true);

  const scenarios = buildBenchmarkScenarios(manifest);
  const byName = Object.fromEntries(scenarios.map((scenario) => [scenario.name, scenario]));
  expect(byName['wordset-genc-settings-hub-load']).toMatchObject({
    path: '/ll-perf-genc/settings/',
    requiresAuth: true,
    primaryMetric: 'firstActionableMs'
  });
  expect(byName['wordset-genc-recorder-queues-initial-load']).toMatchObject({
    path: '/ll-perf-genc/settings/?ll_wordset_tool=recorder-queues',
    requiresAuth: true,
    primaryMetric: 'firstActionableMs',
    minActionableCount: 3
  });
  expect(byName['wordset-genc-recorder-queues-initial-load'].selector)
    .toContain(':not([data-ll-recorder-queue-summary-placeholder])');
  expect(byName['wordset-genc-recorder-queues-lazy-completion']).toMatchObject({
    path: '/ll-perf-genc/settings/?ll_wordset_tool=recorder-queues',
    requiresAuth: true,
    primaryMetric: 'interactionMs',
    action: 'recorder-queue-lazy-completion',
    expectedCategoryCount: 209,
    maxBatchRequestCount: 36,
    maxConcurrentBatchRequestCount: 1
  });
  expect(validateRecorderQueueCompletion(
    byName['wordset-genc-recorder-queues-lazy-completion'],
    209,
    36,
    1
  )).toBe(209);
  expect(() => validateRecorderQueueCompletion(
    byName['wordset-genc-recorder-queues-lazy-completion'],
    1
  )).toThrow(/loaded 1 categories; expected 209/);
  expect(() => validateRecorderQueueCompletion(
    byName['wordset-genc-recorder-queues-lazy-completion'],
    209,
    37
  )).toThrow(/issued 37 summary requests; expected at most 36/);
  expect(() => validateRecorderQueueCompletion(
    byName['wordset-genc-recorder-queues-lazy-completion'],
    209,
    36,
    2
  )).toThrow(/reached 2 concurrent summary requests; expected at most 1/);
});

test('recorder completion benchmark follows the viewport-driven serial loading contract', async () => {
  const benchmarkSpec = fs.readFileSync(benchmarkSpecPath, 'utf8');
  expect(benchmarkSpec).toContain('sentinel.scrollIntoViewIfNeeded');
  expect(benchmarkSpec).not.toContain('loadMore.click');
  expect(benchmarkSpec).toContain("classList.contains('has-load-error')");
  expect(benchmarkSpec).toContain("page.on('requestfinished', recorderQueueRequestSettledListener)");
  expect(benchmarkSpec).toContain('getRecorderQueueMaxConcurrentBatchRequestCount');
});

test('benchmark timeout budget scales with runnable scenarios, runs, warmups, and action limits', async () => {
  const scenarios = [
    { name: 'navigation', kind: 'navigation' },
    { name: 'quiz', kind: 'interaction', action: 'quiz-popup' },
    { name: 'recorder', kind: 'interaction', action: 'recorder-queue-lazy-completion' }
  ];
  const options = {
    runsPerScenario: 2,
    warmupAttempts: 3,
    warmupRetryDelayMs: 50,
    maxDomContentLoadedMs: 100,
    maxActionableMs: 200,
    maxLoadMs: 300,
    maxInteractionMs: 400,
    navigationGraceMs: 1000,
    fixedOverheadMs: 5000,
    perScenarioOverheadMs: 700,
    perRunOverheadMs: 900
  };

  // Navigation timeout: 1300. Warmups: 3 * 1300 + 2 * 50 = 4000.
  // Measured navigation: 1300 + 2 * 200 + 300 = 2000.
  // Each base run: 4000 + 2000 + 900 = 6900.
  // Quiz action: 200 + 2 * 400 = 1000; recorder action: 200 + 400 = 600.
  expect(calculateBenchmarkTestTimeout(scenarios, options)).toBe(
    5000
      + (700 + (2 * 6900))
      + (700 + (2 * 7900))
      + (700 + (2 * 7500))
  );
  expect(calculateBenchmarkTestTimeout(scenarios.slice(0, 1), options)).toBe(19500);
  expect(calculateBenchmarkTestTimeout(scenarios.slice(0, 1), {
    ...options,
    runsPerScenario: 4
  })).toBe(33300);
  expect(calculateBenchmarkTestTimeout(scenarios.slice(0, 1), {
    ...options,
    warmupAttempts: 4
  })).toBe(22200);
  expect(calculateBenchmarkTestTimeout(scenarios, {
    ...options,
    maxRecorderQueueCompletionMs: 1200
  })).toBe(calculateBenchmarkTestTimeout(scenarios, options) + 1600);
});

test('performance fixture seeding defers category maintenance and batches localized writes', async () => {
  const source = fs.readFileSync(fixtureSeederPath, 'utf8');
  const beginBulk = phpFunctionBlock(source, 'll_tools_perf_seed_begin_bulk_mode');
  const endMaintenance = phpFunctionBlock(source, 'll_tools_perf_seed_end_category_maintenance');
  const insertMeta = phpFunctionBlock(source, 'll_tools_perf_seed_insert_post_meta_rows');
  const insertRelationships = phpFunctionBlock(source, 'll_tools_perf_seed_insert_term_relationships');
  const fixtureQuizPages = phpFunctionBlock(source, 'll_tools_perf_seed_query_fixture_quiz_pages_for_category');
  const run = phpFunctionBlock(source, 'll_tools_perf_seed_run');

  expect(beginBulk).toContain("ll_tools_begin_deferred_category_maintenance('performance-fixture-seed')");
  expect(endMaintenance).toContain('ll_tools_end_deferred_category_maintenance(false)');
  expect(endMaintenance).toContain("$maintenance_state['queued_category_ids']");

  const restoreIndex = run.indexOf('ll_tools_perf_seed_end_category_maintenance($bulk_state);');
  const createPagesIndex = run.indexOf('ll_tools_perf_seed_create_pages($manifest, $seeded_wordsets);');
  expect(restoreIndex).toBeGreaterThanOrEqual(0);
  expect(createPagesIndex).toBeGreaterThan(restoreIndex);
  expect(run).toContain('ll_tools_perf_seed_end_bulk_mode($bulk_state);');

  expect(insertMeta).toContain('INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES');
  expect(insertMeta).toContain("implode(', ', $placeholders)");
  expect(insertMeta).not.toContain('$wpdb->insert(');
  expect((insertMeta.match(/\$wpdb->query\(/g) || [])).toHaveLength(1);

  expect(insertRelationships).toContain('INSERT IGNORE INTO {$wpdb->term_relationships}');
  expect(insertRelationships).toContain("implode(', ', $placeholders)");
  expect((insertRelationships.match(/\$wpdb->query\(/g) || [])).toHaveLength(1);

  expect(fixtureQuizPages).toContain("'relation' => 'AND'");
  expect(fixtureQuizPages).toContain("'key' => $quiz_page_category_meta");
  expect(fixtureQuizPages).toContain("'key' => LL_TOOLS_PERF_FIXTURE_META_KEY");
  expect(fixtureQuizPages).toContain("'value' => LL_TOOLS_PERF_FIXTURE_KEY");
});

test('performance fixture reuse requires the exact canonical manifest checksum', async () => {
  const source = fs.readFileSync(fixtureSeederPath, 'utf8');
  const currentFixture = phpFunctionBlock(source, 'll_tools_perf_seed_get_current_fixture_summary');
  const run = phpFunctionBlock(source, 'll_tools_perf_seed_run');
  const reuseBranch = run.slice(0, run.indexOf('$bulk_state = ll_tools_perf_seed_begin_bulk_mode();'));

  expect(currentFixture).toContain(
    'll_tools_perf_manifest_stored_checksum_is_current($stored, $manifest_checksum)'
  );
  expect(currentFixture).toContain('fixture manifest checksum missing, legacy, or changed');
  expect(reuseBranch).not.toContain("$stored_manifest['manifest_sha256']");
  expect(reuseBranch).not.toContain('update_option(LL_TOOLS_PERF_FIXTURE_MANIFEST_OPTION');
});

test('history comparison migrates once from raw hashes then enforces canonical manifest hashes', async () => {
  const throttleProfile = {
    latencyMs: 150,
    downloadKbps: 1600,
    uploadKbps: 750,
    cpuSlowdownRate: 1
  };
  const legacy = {
    fixtureVersion: 'fixture-1',
    fixtureManifest: { sha256: 'raw-platform-specific-hash' },
    throttleProfile
  };
  const current = {
    fixtureVersion: 'fixture-1',
    fixtureManifest: {
      sha256: 'canonical-hash',
      checksumFormat: MANIFEST_CHECKSUM_FORMAT
    },
    throttleProfile
  };
  expect(findPreviousComparableRun([legacy], current)).toBe(legacy);

  const differentCanonical = {
    fixtureVersion: 'fixture-1',
    fixtureManifest: {
      sha256: 'different-canonical-hash',
      checksumFormat: MANIFEST_CHECKSUM_FORMAT
    },
    throttleProfile
  };
  expect(findPreviousComparableRun([differentCanonical], current)).toBeNull();
  expect(findPreviousComparableRun([legacy, differentCanonical], current)).toBeNull();

  const matchingLegacy = {
    ...legacy,
    fixtureManifest: { sha256: 'raw-platform-specific-hash' }
  };
  const changedLegacy = {
    ...legacy,
    fixtureManifest: { sha256: 'different-raw-hash' }
  };
  expect(findPreviousComparableRun([matchingLegacy], legacy)).toBe(matchingLegacy);
  expect(findPreviousComparableRun([changedLegacy], legacy)).toBeNull();
});
