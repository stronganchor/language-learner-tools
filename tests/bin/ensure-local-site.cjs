#!/usr/bin/env node
'use strict';

// Local 9's loopback GraphQL contract is also used by its own Start site UI.
// Keep authentication in this process; never print connection-info or API errors.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const http = require('node:http');
const https = require('node:https');
const crypto = require('node:crypto');
const dns = require('node:dns').promises;
const { spawnSync } = require('node:child_process');

function identity(value) {
  let normalized = String(value || '').replace(/\\+/g, '/').replace(/\/+$/, '');
  if (process.platform === 'win32') normalized = normalized.replace(/^\/(?:mnt\/)?([a-z])\//i, '$1:/');
  try { normalized = fs.realpathSync(normalized).replace(/\\/g, '/'); } catch (_) { /* Validate metadata before startup. */ }
  return /^[a-z]:\//i.test(normalized) || process.platform === 'win32' ? normalized.toLowerCase() : normalized;
}

function readJson(file) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_) { return null; }
}

function matchingSite(sites, siteRoot) {
  const matches = Object.values(sites || {}).filter(site => site && typeof site.id === 'string'
    && /^[a-z0-9_-]+$/i.test(site.id) && typeof site.path === 'string'
    && identity(`${site.path}/app/public`) === identity(siteRoot));
  if (matches.length !== 1) throw new Error('Local must contain exactly one registered site matching this checkout\'s app/public directory. Open Local and check the site path.');
  return matches[0];
}

function runtimePorts(userData, site, siteRoot) {
  const configRoot = path.join(userData, 'run', site.id, 'conf');
  let nginx = '', mysql = '';
  try { nginx = fs.readFileSync(path.join(configRoot, 'nginx', 'site.conf'), 'utf8'); } catch (_) { return null; }
  const root = nginx.match(/^\s*root\s+["']?([^;"']+)["']?\s*;/mi);
  if (!root || identity(root[1].trim()) !== identity(siteRoot)) return null;
  try { mysql = fs.readFileSync(path.join(configRoot, 'mysql', 'my.cnf'), 'utf8'); } catch (_) { /* Not ready. */ }
  const db = mysql.match(/^\s*port\s*=\s*(\d+)\s*$/mi);
  const web = nginx.match(/^\s*listen\s+(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)\s*;/mi);
  const valid = match => match && Number(match[1]) > 0 && Number(match[1]) <= 65535 ? Number(match[1]) : 0;
  return { db: valid(db), http: valid(web) };
}

function targetMatches(site, mode, target, ports) {
  if (!target) return true;
  if (mode === 'db') {
    const match = target.match(/^(?:127\.0\.0\.1|localhost|\[::1\]):(\d+)$/i);
    return !!match && !!ports && Number(match[1]) === ports.db;
  }
  try {
    const url = new URL(target);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return false;
    if (url.hostname.toLowerCase() === String(site.domain).toLowerCase()) return !url.port;
    return ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname) && !!ports && Number(url.port) === ports.http;
  } catch (_) { return false; }
}

function mysqlReady(port, timeout) {
  if (!port) return Promise.resolve(false);
  return new Promise(resolve => {
    const socket = net.connect({ host: '127.0.0.1', port });
    let data = Buffer.alloc(0), settled = false;
    const finish = ready => { if (!settled) { settled = true; socket.destroy(); resolve(ready); } };
    socket.setTimeout(timeout, () => finish(false));
    socket.on('error', () => finish(false));
    socket.on('end', () => finish(false));
    socket.on('data', chunk => {
      data = Buffer.concat([data, chunk]);
      if (data.length >= 5) finish(data[3] === 0 && data[4] === 10);
    });
  });
}

function httpReady(port, domain, timeout, secure = false) {
  if (!port) return Promise.resolve(false);
  return new Promise(resolve => {
    const transport = secure ? https : http;
    const request = transport.request({ host: '127.0.0.1', port, path: '/wp-includes/css/dashicons.min.css', method: 'HEAD', headers: { Host: domain },
      ...(secure ? { servername: domain, rejectUnauthorized: false } : {}) }, response => {
      response.resume();
      resolve(response.statusCode >= 200 && response.statusCode < 400);
    });
    request.setTimeout(timeout, () => request.destroy());
    request.on('error', () => resolve(false));
    request.end();
  });
}

async function graphql(userData, query, id, timeout) {
  const info = readJson(path.join(userData, 'graphql-connection-info.json'));
  if (!info || typeof info.authToken !== 'string') throw new Error('Local API is unavailable.');
  let url;
  try { url = new URL(info.url); } catch (_) { throw new Error('Local API connection metadata is invalid.'); }
  if (url.protocol !== 'http:' || !['127.0.0.1', '[::1]'].includes(url.hostname)
    || url.pathname !== '/graphql' || !url.port || url.username || url.password || url.search || url.hash) {
    throw new Error('Local API must use its authenticated loopback /graphql endpoint.');
  }
  const response = await fetch(url, {
    method: 'POST', redirect: 'error', signal: AbortSignal.timeout(Math.max(1, timeout)),
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${info.authToken}` },
    body: JSON.stringify({ query, variables: { id } })
  });
  if (!response.ok) throw new Error('Local API request failed.');
  const result = await response.json();
  if (result.errors || !result.data) throw new Error('Local API did not accept the site operation. Check the installed Local version and site status.');
  return result.data;
}

function launchLocal(appPath, timeout) {
  if (process.platform !== 'win32') throw new Error('Automatic Local application launch currently requires Windows Node. Open Local manually, or use node.exe from Git Bash/WSL.');
  if (!fs.existsSync(appPath)) throw new Error('Local.exe was not found. Set LL_TOOLS_LOCAL_APP_PATH to its executable, or open Local manually.');
  const result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command',
    "if (-not (Get-Process -Name Local -ErrorAction SilentlyContinue)) { Start-Process -FilePath $env:LL_TOOLS_LOCAL_LAUNCH_PATH -WindowStyle Hidden -ErrorAction Stop }"],
  { env: { ...process.env, LL_TOOLS_LOCAL_LAUNCH_PATH: appPath }, windowsHide: true, encoding: 'utf8', timeout });
  if (result.error || result.status !== 0) throw new Error('Could not open Local. Open it manually and retry the same test command.');
}

// Dependency injection exercises stopped/starting/error states without stopping
// the developer's real Local app, restarting services, or touching a database.
async function ensureReady(options, io) {
  const deadline = io.now() + options.timeout;
  let launched = false, started = false, lastStatus = 'unavailable';
  while (io.now() < deadline) {
    const remaining = () => Math.max(1, deadline - io.now());
    if (await io.ready(Math.min(1000, remaining()))) return { launched, started };
    if (io.now() >= deadline) break;
    let site;
    try { site = await io.site(Math.min(3000, remaining())); } catch (_) {
      if (!launched) {
        io.log('Opening Local and waiting for its site API.');
        await io.launch(Math.min(10000, remaining()));
        launched = true;
      }
    }
    if (io.now() >= deadline) break;
    if (site) {
      if (site.id !== options.site.id || identity(`${site.path}/app/public`) !== identity(options.siteRoot)) {
        throw new Error('Local API site identity does not match this checkout; no site was started.');
      }
      lastStatus = site.status;
      if (site.status === 'halted' && !started) {
        // Local's startSite restarts an existing process group. Never call it
        // for running/starting/restarting sites, even if a probe is still cold.
        io.log(`Starting Local test site ${site.domain || site.id}.`);
        started = true;
        await io.start(remaining());
      } else if (!['halted', 'running', 'starting', 'restarting'].includes(site.status)) {
        throw new Error(`Local test site cannot be started automatically from status ${String(site.status).replace(/[^a-z_-]/gi, '')}. Open Local and resolve its site error.`);
      }
    }
    if (io.now() < deadline) await io.sleep(Math.min(1000, remaining()));
  }
  throw new Error(`Local test site did not become ready within ${options.timeout / 1000}s (status: ${lastStatus}). Open Local, check this site's services, then retry. LL_TOOLS_LOCAL_AUTOSTART=0 disables this preflight.`);
}

async function main(args = process.argv.slice(2), env = process.env) {
  const mode = args[0];
  if (!['db', 'http'].includes(mode)) throw new Error('Usage: node tests/bin/ensure-local-site.cjs db|http [explicit-target]');
  if (env.LL_TOOLS_LOCAL_AUTOSTART === '0') return;
  const timeoutSeconds = env.LL_TOOLS_LOCAL_START_TIMEOUT_SECONDS || '120';
  if (!/^[1-9]\d*$/.test(timeoutSeconds) || Number(timeoutSeconds) > 600) throw new Error('LL_TOOLS_LOCAL_START_TIMEOUT_SECONDS must be an integer from 1 to 600.');
  const siteRoot = env.LL_TOOLS_LOCAL_SITE_ROOT || path.resolve(__dirname, '../../../../..');
  const userData = env.LL_TOOLS_LOCAL_USER_DATA || (env.APPDATA ? path.join(env.APPDATA, 'Local')
    : path.join(os.homedir(), process.platform === 'darwin' ? 'Library/Application Support/Local' : '.config/Local'));
  const registered = readJson(path.join(userData, 'sites.json'));
  // A non-Local checkout retains its existing manually managed runtime.
  if (!registered || !Object.values(registered).some(site => site && identity(`${site.path}/app/public`) === identity(siteRoot))) return;
  const site = matchingSite(registered, siteRoot);
  if (!targetMatches(site, mode, args[1] || '', runtimePorts(userData, site, siteRoot))) return;
  const ready = async timeout => {
    const ports = runtimePorts(userData, site, siteRoot);
    if (!ports || !await mysqlReady(ports.db, timeout)) return false;
    if (mode === 'db') return true;
    if (!await httpReady(ports.http, site.domain, timeout)) return false;
    const target = new URL(args[1] || `https://${site.domain}`);
    if (target.hostname.toLowerCase() !== String(site.domain).toLowerCase()) return true;
    let timer;
    const addresses = await Promise.race([
      dns.lookup(site.domain, { all: true }).catch(() => []),
      new Promise(resolve => { timer = setTimeout(() => resolve([]), timeout); })
    ]);
    clearTimeout(timer);
    if (!addresses.length || addresses.some(({ address }) => !['127.0.0.1', '::1', '::ffff:127.0.0.1'].includes(address))) return false;
    // The canonical router can be down while this site's private listener is
    // healthy. Check its local HTTPS/HTTP endpoint too, without following a
    // redirect or resolving a public host outside this machine.
    const secure = target.protocol === 'https:';
    return httpReady(secure ? 443 : 80, site.domain, timeout, secure);
  };
  if (await ready(750)) return;
  const lockFile = path.join(os.tmpdir(), `ll-tools-local-start-${crypto.createHash('sha256').update(identity(siteRoot)).digest('hex').slice(0, 16)}.lock`);
  const waitDeadline = Date.now() + Number(timeoutSeconds) * 1000;
  let lock;
  while (lock === undefined) {
    try { lock = fs.openSync(lockFile, 'wx'); } catch (error) {
      if (error.code !== 'EEXIST') throw new Error('Could not create the Local startup coordination lock.');
      if (await ready(750)) return;
      if (Date.now() >= waitDeadline) throw new Error(`Another Local startup preflight still owns ${lockFile}. Wait for it, or remove that lock only after confirming its runner has ended.`);
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
  }
  try {
    fs.writeSync(lock, `${process.pid}\n`);
    const appPath = env.LL_TOOLS_LOCAL_APP_PATH || path.join(env.LOCALAPPDATA || '', 'Programs', 'Local', 'Local.exe');
    await ensureReady({ site, siteRoot, timeout: Math.max(1, waitDeadline - Date.now()) }, {
      ready, now: Date.now, sleep: ms => new Promise(resolve => setTimeout(resolve, ms)),
      log: message => process.stderr.write(`${message}\n`),
      launch: timeout => launchLocal(appPath, timeout),
      site: async timeout => (await graphql(userData, 'query($id:ID!){site(id:$id){id path domain status}}', site.id, timeout)).site,
      start: timeout => graphql(userData, 'mutation($id:ID!){startSite(id:$id){id}}', site.id, timeout)
    });
  } finally {
    fs.closeSync(lock);
    fs.unlinkSync(lockFile);
  }
}

module.exports = { identity, matchingSite, runtimePorts, targetMatches, httpReady, graphql, ensureReady, main };
if (require.main === module) main().catch(error => { process.stderr.write(`Local preflight: ${error.message}\n`); process.exitCode = 1; });
