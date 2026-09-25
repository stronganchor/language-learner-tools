#!/usr/bin/env node
'use strict';

// Read-only deployment check: never infer a live target or use authentication.
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const https = require('node:https');

const REQUEST_TIMEOUT_MS = 9000;
const TOTAL_TIMEOUT_MS = 75000;
const MAX_RESPONSE_BYTES = 128 * 1024;
const FEEDBACK_VERSION = '2';
const FILES = ['right-answer.mp3', 'wrong-answer.mp3'];
const ENCODINGS = ['identity', 'gzip, br'];

function usage() {
  return [
    'Usage: node tests/bin/check-feedback-media-ranges.cjs --base-url <plugin-directory-url>',
    '',
    'Checks both feedback MP3 files at ?ver=2 against the local media files.',
    'Makes at most 16 unauthenticated GET requests: full, first 512 bytes,',
    'bytes 696-1207, and final 512 bytes with identity and gzip/br accepted.',
    'Requires raw, exact media bytes, correct range metadata, and no-transform.',
    'HTTPS is required except for an explicitly supplied loopback HTTP fixture.',
    'No redirects, retries, live writes, or Local service changes are performed.',
    'Each request is limited to 9 seconds / 128 KiB; total deadline is 75 seconds.',
    'Use --help to show this message without any network access.'
  ].join('\n');
}

function readBaseUrl(args) {
  if (args.length !== 2 || args[0] !== '--base-url') {
    throw new Error('An explicit --base-url is required. Use --help for usage.');
  }
  let url;
  try { url = new URL(args[1]); } catch (_) { throw new Error('The base URL must be an absolute plugin-directory URL.'); }
  const loopbackHttp = url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
  if (url.protocol !== 'https:' && !loopbackHttp) throw new Error('Use HTTPS, or HTTP only for an explicit loopback fixture.');
  if (url.username || url.password || url.search || url.hash) {
    throw new Error('The base URL must not contain credentials, a query, or a fragment.');
  }
  url.pathname = url.pathname.replace(/\/+$/, '') + '/';
  return url;
}

function requestRaw(url, encoding, range, timeoutMs) {
  return new Promise((resolve, reject) => {
    const transport = url.protocol === 'https:' ? https : http;
    const headers = { 'Accept-Encoding': encoding, 'User-Agent': 'LL-Tools-Feedback-Media-Check/1' };
    if (range) headers.Range = range;
    const request = transport.get(url, { headers, agent: false }, response => {
      const chunks = [];
      let size = 0;
      response.on('data', chunk => {
        size += chunk.length;
        if (size > MAX_RESPONSE_BYTES) {
          request.destroy(new Error('Response exceeded the 128 KiB limit.'));
          return;
        }
        chunks.push(chunk);
      });
      response.on('error', reject);
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks) }));
    });
    const deadline = setTimeout(() => request.destroy(new Error('Request deadline exceeded.')), timeoutMs);
    request.on('error', reject);
    request.on('close', () => clearTimeout(deadline));
  });
}

function checkResponse(response, expected, totalBytes, bounds) {
  const expectedStatus = bounds ? 206 : 200;
  if (response.status !== expectedStatus) throw new Error(`Expected HTTP ${expectedStatus}, received ${response.status}.`);
  const encoding = String(response.headers['content-encoding'] || '').trim().toLowerCase();
  if (encoding && encoding !== 'identity') throw new Error(`Unexpected Content-Encoding: ${encoding}.`);
  const cacheControl = String(response.headers['cache-control'] || '').split(',').map(value => value.trim().toLowerCase());
  if (!cacheControl.includes('no-transform')) throw new Error('Cache-Control is missing no-transform.');
  if (!/^audio\/mpeg(?:\s*;|$)/i.test(String(response.headers['content-type'] || ''))) {
    throw new Error('Content-Type is not audio/mpeg.');
  }
  if (bounds) {
    const expectedRange = `bytes ${bounds[0]}-${bounds[1]}/${totalBytes}`;
    if (response.headers['content-range'] !== expectedRange) {
      throw new Error(`Expected Content-Range ${expectedRange}; received ${String(response.headers['content-range'] || '(missing)').slice(0, 80)}.`);
    }
  }
  const declaredLength = response.headers['content-length'];
  if (declaredLength !== undefined && (!/^\d+$/.test(declaredLength) || Number(declaredLength) !== expected.length)) {
    throw new Error(`Content-Length does not match the expected ${expected.length} bytes.`);
  }
  if (response.body.length !== expected.length) {
    throw new Error(`Expected ${expected.length} body bytes, received ${response.body.length}.`);
  }
  if (!response.body.equals(expected)) throw new Error('Response bytes differ from the corresponding local MP3 bytes.');
}

async function main() {
  const args = process.argv.slice(2);
  if (args.length === 1 && (args[0] === '--help' || args[0] === '-h')) {
    console.log(usage());
    return;
  }
  const baseUrl = readBaseUrl(args);
  // Validate every local reference before making the first request.
  const references = FILES.map(name => {
    const bytes = fs.readFileSync(path.resolve(__dirname, '../../media', name));
    if (bytes.length < 1208 || bytes.length > MAX_RESPONSE_BYTES) throw new Error(`Unexpected local feedback file size: ${name}.`);
    return { name, bytes };
  });
  const deadline = Date.now() + TOTAL_TIMEOUT_MS;
  const failures = [];
  let requests = 0;
  for (const { name, bytes } of references) {
    const url = new URL(`media/${name}`, baseUrl);
    url.searchParams.set('ver', FEEDBACK_VERSION);
    const cases = [
      { label: 'full', range: null, bounds: null },
      { label: 'first 512', range: 'bytes=0-511', bounds: [0, 511] },
      { label: 'middle 512', range: 'bytes=696-1207', bounds: [696, 1207] },
      { label: 'last 512', range: 'bytes=-512', bounds: [bytes.length - 512, bytes.length - 1] }
    ];
    for (const encoding of ENCODINGS) {
      let passed = 0;
      for (const testCase of cases) {
        const remainingMs = deadline - Date.now();
        if (remainingMs <= 0) throw new Error(`Total deadline exceeded after ${requests} requests; ${failures.length} checks failed.`);
        const label = `${name}, ${encoding}, ${testCase.label}`;
        try {
          requests++;
          const response = await requestRaw(url, encoding, testCase.range, Math.min(REQUEST_TIMEOUT_MS, remainingMs));
          const expected = testCase.bounds ? bytes.subarray(testCase.bounds[0], testCase.bounds[1] + 1) : bytes;
          checkResponse(response, expected, bytes.length, testCase.bounds);
          passed++;
        } catch (error) {
          const message = String(error.message || error).replace(/\s+/g, ' ').slice(0, 200);
          failures.push(`${label}: ${message}`);
        }
      }
      console.log(`${name} (${encoding}): ${passed}/${cases.length} checks passed.`);
    }
  }
  for (const failure of failures) console.error(`FAIL ${failure}`);
  if (failures.length) {
    process.exitCode = 1;
    console.error(`${failures.length}/${requests} feedback media checks failed.`);
  } else {
    console.log(`All ${requests} feedback media checks passed; both clips match local bytes.`);
  }
}

main().catch(error => {
  console.error(`Feedback media check: ${String(error.message || error).replace(/\s+/g, ' ').slice(0, 240)}`);
  process.exitCode = 1;
});
