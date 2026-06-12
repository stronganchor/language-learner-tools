#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const {
  DEFAULT_HISTORY,
  compareWithPrevious,
  findPreviousComparableRun,
  readHistoryRecords,
  resolvePluginPath
} = require('../tests/e2e/helpers/performance-benchmark');

function printUsage() {
  console.log(`Usage:
  node scripts/summarize-performance-history.js [--history <path>] [--limit <n>] [--scenario <text>] [--format markdown|json] [--output <path|->]

Options:
  --history <path>   JSONL history file. Defaults to tests/performance/history/performance-history.jsonl.
  --limit <n>        Number of recent runs to show in the run table. Defaults to 5.
  --scenario <text>  Only show latest scenario rows whose name contains this text.
  --format <type>    markdown or json. Defaults to markdown.
  --output <path|->  Write to a file or stdout. Defaults to stdout.
`);
}

function parseArgs(argv) {
  const options = {
    history: DEFAULT_HISTORY,
    limit: 5,
    scenario: '',
    format: 'markdown',
    output: '-'
  };

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    const next = () => {
      index += 1;
      if (index >= argv.length) {
        throw new Error(`Missing value for ${arg}`);
      }
      return argv[index];
    };

    if (arg === '--help' || arg === '-h') {
      options.help = true;
    } else if (arg === '--history') {
      options.history = next();
    } else if (arg.startsWith('--history=')) {
      options.history = arg.slice('--history='.length);
    } else if (arg === '--limit') {
      options.limit = Number(next());
    } else if (arg.startsWith('--limit=')) {
      options.limit = Number(arg.slice('--limit='.length));
    } else if (arg === '--scenario') {
      options.scenario = next();
    } else if (arg.startsWith('--scenario=')) {
      options.scenario = arg.slice('--scenario='.length);
    } else if (arg === '--format') {
      options.format = next();
    } else if (arg.startsWith('--format=')) {
      options.format = arg.slice('--format='.length);
    } else if (arg === '--output') {
      options.output = next();
    } else if (arg.startsWith('--output=')) {
      options.output = arg.slice('--output='.length);
    } else {
      throw new Error(`Unknown argument: ${arg}`);
    }
  }

  options.limit = Number.isFinite(options.limit) && options.limit > 0 ? Math.floor(options.limit) : 5;
  options.format = String(options.format || 'markdown').toLowerCase();
  if (!['markdown', 'json'].includes(options.format)) {
    throw new Error('--format must be markdown or json');
  }

  return options;
}

function metricValue(container, metricName) {
  return Number(container && Number.isFinite(Number(container[metricName])) ? container[metricName] : 0);
}

function scenarioRows(record, comparison, scenarioFilter) {
  const comparisonByName = {};
  (comparison || []).forEach((row) => {
    comparisonByName[row.name] = row;
  });

  return (record.scenarios || [])
    .filter((scenario) => {
      return !scenarioFilter || String(scenario.name || '').includes(scenarioFilter);
    })
    .map((scenario) => {
      const metric = scenario.primaryMetric || 'firstActionableMs';
      const comparisonRow = comparisonByName[scenario.name] || {};
      return {
        name: scenario.name,
        metric,
        medianMs: metricValue(scenario.median, metric),
        p95Ms: metricValue(scenario.p95, metric),
        previousMs: Number(comparisonRow.previousMs || 0),
        deltaMs: Number(comparisonRow.deltaMs || 0),
        regressionRatio: Number(comparisonRow.regressionRatio || 0),
        failed: !!comparisonRow.failed
      };
    });
}

function countFailures(record) {
  return (record.comparison || []).filter((row) => row && row.failed).length;
}

function gitLabel(record) {
  return (record.git && (record.git.describe || record.git.commit)) || '';
}

function buildSummary(records, options, historyFile) {
  const totalRecords = records.length;
  if (!totalRecords) {
    return {
      historyFile,
      totalRecords,
      selectedRecords: [],
      latest: null,
      previousComparable: null,
      scenarios: []
    };
  }

  const latest = records[records.length - 1];
  const previousComparable = findPreviousComparableRun(records.slice(0, -1), latest);
  const comparison = Array.isArray(latest.comparison) && latest.comparison.length
    ? latest.comparison
    : (previousComparable ? compareWithPrevious(latest, previousComparable) : []);
  const recent = records.slice(-options.limit).map((record) => {
    return {
      recordedAt: record.recordedAt || '',
      fixtureVersion: record.fixtureVersion || '',
      manifestSha256: record.fixtureManifest && record.fixtureManifest.sha256 ? record.fixtureManifest.sha256 : '',
      git: gitLabel(record),
      runsPerScenario: Number(record.runsPerScenario || 0),
      scenarioCount: Array.isArray(record.scenarios) ? record.scenarios.length : 0,
      failures: countFailures(record)
    };
  });

  return {
    historyFile,
    totalRecords,
    selectedRecords: recent,
    latest: {
      recordedAt: latest.recordedAt || '',
      fixtureVersion: latest.fixtureVersion || '',
      pluginVersion: latest.pluginVersion || '',
      git: gitLabel(latest),
      runsPerScenario: Number(latest.runsPerScenario || 0),
      manifestSha256: latest.fixtureManifest && latest.fixtureManifest.sha256 ? latest.fixtureManifest.sha256 : ''
    },
    previousComparable: previousComparable
      ? {
        recordedAt: previousComparable.recordedAt || '',
        fixtureVersion: previousComparable.fixtureVersion || '',
        pluginVersion: previousComparable.pluginVersion || '',
        git: gitLabel(previousComparable)
      }
      : null,
    scenarios: scenarioRows(latest, comparison, options.scenario)
  };
}

function tableCell(value) {
  return String(value === null || typeof value === 'undefined' ? '' : value).replace(/\|/g, '\\|');
}

function ms(value) {
  return Number(value || 0) > 0 ? `${Number(value)} ms` : '';
}

function signedMs(value) {
  const numeric = Number(value || 0);
  if (!numeric) {
    return '';
  }
  return `${numeric > 0 ? '+' : ''}${numeric} ms`;
}

function renderMarkdown(summary, options) {
  const lines = [];
  lines.push('# LL Tools Performance History');
  lines.push('');
  lines.push(`- History file: ${summary.historyFile}`);
  lines.push(`- Total records: ${summary.totalRecords}`);
  if (options.scenario) {
    lines.push(`- Scenario filter: ${options.scenario}`);
  }

  if (!summary.latest) {
    lines.push('');
    lines.push('No benchmark history records were found.');
    lines.push('');
    return lines.join('\n');
  }

  lines.push(`- Latest run: ${summary.latest.recordedAt}`);
  lines.push(`- Latest git: ${summary.latest.git}`);
  lines.push(`- Previous comparable run: ${summary.previousComparable ? summary.previousComparable.recordedAt : 'none'}`);
  lines.push('');
  lines.push('## Recent Runs');
  lines.push('');
  lines.push('| Recorded | Fixture | Git | Runs | Scenarios | Failures |');
  lines.push('| --- | --- | --- | ---: | ---: | ---: |');
  summary.selectedRecords.forEach((record) => {
    lines.push(`| ${tableCell(record.recordedAt)} | ${tableCell(record.fixtureVersion)} | ${tableCell(record.git)} | ${record.runsPerScenario} | ${record.scenarioCount} | ${record.failures} |`);
  });
  lines.push('');
  lines.push('## Latest Scenario Summary');
  lines.push('');
  if (!summary.scenarios.length) {
    lines.push('No scenarios matched the selected filter.');
    lines.push('');
    return lines.join('\n');
  }

  lines.push('| Scenario | Metric | Median | P95 | Previous | Delta | Result |');
  lines.push('| --- | --- | ---: | ---: | ---: | ---: | --- |');
  summary.scenarios.forEach((row) => {
    lines.push(`| ${tableCell(row.name)} | ${tableCell(row.metric)} | ${ms(row.medianMs)} | ${ms(row.p95Ms)} | ${ms(row.previousMs)} | ${signedMs(row.deltaMs)} | ${row.failed ? 'FAIL' : 'pass'} |`);
  });
  lines.push('');
  return lines.join('\n');
}

function writeOutput(outputPath, content) {
  if (!outputPath || outputPath === '-') {
    process.stdout.write(content);
    return;
  }

  const resolved = resolvePluginPath(outputPath, outputPath);
  fs.mkdirSync(path.dirname(resolved), { recursive: true });
  fs.writeFileSync(resolved, content, 'utf8');
  console.log(`Wrote ${resolved}`);
}

function main() {
  const options = parseArgs(process.argv.slice(2));
  if (options.help) {
    printUsage();
    return;
  }

  const historyFile = resolvePluginPath(options.history, DEFAULT_HISTORY);
  const records = readHistoryRecords(historyFile);
  const summary = buildSummary(records, options, historyFile);
  const content = options.format === 'json'
    ? `${JSON.stringify(summary, null, 2)}\n`
    : renderMarkdown(summary, options);
  writeOutput(options.output, content);
}

try {
  main();
} catch (error) {
  console.error(error && error.message ? error.message : error);
  process.exit(1);
}
