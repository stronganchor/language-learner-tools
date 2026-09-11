const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const childProcess = require('node:child_process');
const http = require('node:http');
const { ensureReady, matchingSite, runtimePorts, targetMatches, httpReady, graphql, main } = require('../../bin/ensure-local-site.cjs');

const siteRoot = path.join(os.tmpdir(), 'll-tools-start-fixture', 'app', 'public');
const site = { id: 'fixture-site', path: path.dirname(path.dirname(siteRoot)), domain: 'fixture.local' };
function fixture(overrides = {}) {
  let time = 0, ready = false;
  const calls = [];
  const io = {
    now: () => time,
    sleep: async ms => { time += ms; },
    log: message => calls.push(['log', message]),
    ready: async () => ready,
    site: async () => ({ ...site, status: 'halted' }),
    launch: async () => calls.push(['launch']),
    start: async () => { calls.push(['start']); ready = true; },
    ...overrides
  };
  return { io, calls, options: { site, siteRoot, timeout: 3000 } };
}

test('ready Local services are a fast no-op without API calls or launch', async () => {
  const { io, options, calls } = fixture({ ready: async () => true, site: async () => { throw new Error('must not read API'); } });
  await expect(ensureReady(options, io)).resolves.toEqual({ launched: false, started: false });
  expect(calls).toEqual([]);
});

test('a stopped matching site is started exactly once', async () => {
  const { io, options, calls } = fixture();
  await expect(ensureReady(options, io)).resolves.toEqual({ launched: false, started: true });
  expect(calls.filter(call => call[0] === 'start')).toHaveLength(1);
  expect(calls.filter(call => call[0] === 'launch')).toHaveLength(0);
});

test('Local is opened once when its API is unavailable before starting the matching site', async () => {
  let queries = 0;
  const { io, options, calls } = fixture({ site: async () => {
    if (++queries === 1) throw new Error('API not running');
    return { ...site, status: 'halted' };
  } });
  await expect(ensureReady(options, io)).resolves.toEqual({ launched: true, started: true });
  expect(calls.filter(call => call[0] === 'launch')).toHaveLength(1);
  expect(calls.filter(call => call[0] === 'start')).toHaveLength(1);
});

for (const status of ['running', 'starting', 'restarting']) {
  test(`${status} services are never restarted when readiness is still unavailable`, async () => {
    const { io, options, calls } = fixture({ site: async () => ({ ...site, status }) });
    await expect(ensureReady(options, io)).rejects.toThrow('did not become ready within 3s');
    expect(calls.filter(call => ['launch', 'start'].includes(call[0]))).toEqual([]);
  });
}

test('a stale site ID that now names another checkout cannot start it', async () => {
  const { io, options, calls } = fixture({ site: async () => ({ ...site, path: path.join(os.tmpdir(), 'unrelated'), status: 'halted' }) });
  await expect(ensureReady(options, io)).rejects.toThrow('identity does not match');
  expect(calls.filter(call => call[0] === 'start')).toEqual([]);
  expect(() => matchingSite({ site, duplicate: { ...site, id: 'duplicate' } }, siteRoot)).toThrow('exactly one');
});

test('explicit external URLs and unrelated database ports cannot start Local', () => {
  const ports = { db: 12345, http: 12346 };
  expect(targetMatches(site, 'http', 'https://example.org', ports)).toBe(false);
  expect(targetMatches(site, 'http', 'https://fixture.local:8443', ports)).toBe(false);
  expect(targetMatches(site, 'http', 'https://fixture.local', ports)).toBe(true);
  expect(targetMatches(site, 'http', 'http://127.0.0.1:12346', ports)).toBe(true);
  expect(targetMatches(site, 'db', '127.0.0.1:3306', ports)).toBe(false);
  expect(targetMatches(site, 'db', '127.0.0.1:12345', ports)).toBe(true);
});

test('opt-out does not read runtime metadata and invalid startup deadlines fail clearly', async () => {
  await expect(main(['http'], { LL_TOOLS_LOCAL_AUTOSTART: '0', LL_TOOLS_LOCAL_USER_DATA: 'unreadable' })).resolves.toBeUndefined();
  await expect(main(['http'], { LL_TOOLS_LOCAL_START_TIMEOUT_SECONDS: '601' })).rejects.toThrow('integer from 1 to 600');
});

test('Local API authentication cannot be sent to a non-loopback endpoint', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-local-api-'));
  try {
    fs.writeFileSync(path.join(directory, 'graphql-connection-info.json'), JSON.stringify({ url: 'https://example.org/graphql', authToken: 'fixture-token' }));
    await expect(graphql(directory, 'query{_empty}', site.id, 100)).rejects.toThrow('authenticated loopback');
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('HTTP readiness uses a cheap static HEAD with the site host rather than bootstrapping WordPress', async () => {
  const requests = [];
  const server = http.createServer((request, response) => {
    requests.push({ method: request.method, url: request.url, host: request.headers.host });
    response.writeHead(200, { 'Content-Type': 'text/css', Connection: 'close' });
    response.end();
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    await expect(httpReady(server.address().port, site.domain, 1000)).resolves.toBe(true);
    expect(requests).toEqual([{ method: 'HEAD', url: '/wp-includes/css/dashicons.min.css', host: 'fixture.local' }]);
  } finally { await new Promise(resolve => server.close(resolve)); }
});

test('stale runtime configuration cannot probe another site even when its ID matches', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'll-tools-local-config-'));
  const config = path.join(directory, 'run', site.id, 'conf');
  fs.mkdirSync(path.join(config, 'nginx'), { recursive: true });
  fs.mkdirSync(path.join(config, 'mysql'), { recursive: true });
  try {
    fs.writeFileSync(path.join(config, 'mysql', 'my.cnf'), '[mysqld]\nport=12345\n');
    fs.writeFileSync(path.join(config, 'nginx', 'site.conf'), `root "${siteRoot.replace(/\\/g, '/')}";\nlisten 127.0.0.1:12346;\n`);
    expect(runtimePorts(directory, site, siteRoot)).toEqual({ db: 12345, http: 12346 });
    fs.writeFileSync(path.join(config, 'nginx', 'site.conf'), 'root "/some/other/site";\nlisten 127.0.0.1:12346;\n');
    expect(runtimePorts(directory, site, siteRoot)).toBeNull();
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
});

test('wrapper discovery and filesystem-only selections bypass startup with split option values', () => {
  const root = path.resolve(__dirname, '../../..');
  const bash = process.platform === 'win32' ? path.join(process.env.ProgramFiles || 'C:\\Program Files', 'Git', 'bin', 'bash.exe') : 'bash';
  const result = childProcess.spawnSync(bash, ['-c', [
    'source tests/bin/local-test-runtime.sh',
    'if ll_tools_tests_need_local http --list; then exit 1; fi',
    'if ll_tools_tests_need_local db --help; then exit 2; fi',
    'if ll_tools_tests_need_local http specs/maintenance-doc-contracts.spec.js --reporter line --project chromium; then exit 3; fi',
    'if ll_tools_tests_need_local http specs/local-site-startup.spec.js specs/maintenance-doc-contracts.spec.js --workers 1; then exit 4; fi',
    'll_tools_tests_need_local http specs/page-speed-throttled-load.spec.js || exit 5',
    'll_tools_tests_need_local db Integration/DictionaryFeatureTest.php || exit 6'
  ].join('\n')], { cwd: root, encoding: 'utf8' });
  expect({ status: result.status, stderr: result.stderr }).toEqual({ status: 0, stderr: '' });
});
