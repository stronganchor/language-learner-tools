const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { isExpectedCloudflareRumAbort } = require('../live-smoke/network-policy');

const repoRoot = path.resolve(__dirname, '..', '..', '..');

test('live smoke ignores only aborted Cloudflare RUM beacons', async () => {
  expect(isExpectedCloudflareRumAbort(
    { method: 'POST', pathname: '/cdn-cgi/rum' },
    'net::ERR_ABORTED'
  )).toBe(true);

  const rejectedCases = [
    [{ method: 'GET', pathname: '/cdn-cgi/rum' }, 'net::ERR_ABORTED'],
    [{ method: 'PUT', pathname: '/cdn-cgi/rum' }, 'net::ERR_ABORTED'],
    [{ method: 'POST', pathname: '/cdn-cgi/rum/' }, 'net::ERR_ABORTED'],
    [{ method: 'POST', pathname: '/wp-admin/admin-ajax.php' }, 'net::ERR_ABORTED'],
    [{ method: 'POST', pathname: '/cdn-cgi/rum' }, 'net::ERR_FAILED'],
    [{ method: 'POST', pathname: '/cdn-cgi/rum' }, 'net::ERR_CONNECTION_RESET'],
    [null, 'net::ERR_ABORTED'],
    [{ method: 'POST', pathname: '/cdn-cgi/rum' }, '']
  ];

  for (const [details, errorText] of rejectedCases) {
    expect(isExpectedCloudflareRumAbort(details, errorText)).toBe(false);
  }
});

test('live smoke keeps unexpected same-origin request failures fatal', async () => {
  const source = fs.readFileSync(
    path.join(repoRoot, 'tests', 'e2e', 'live-smoke', 'live-sites.spec.js'),
    'utf8'
  );
  const requestFailureStart = source.indexOf("page.on('requestfailed'");
  const requestFailureEnd = source.indexOf("page.on('response'", requestFailureStart);

  expect(requestFailureStart).toBeGreaterThanOrEqual(0);
  expect(requestFailureEnd).toBeGreaterThan(requestFailureStart);

  const requestFailureBlock = source.slice(requestFailureStart, requestFailureEnd);
  expect(requestFailureBlock).toContain('isExpectedCloudflareRumAbort(requestDetails, errorText)');
  expect(requestFailureBlock).toContain('summary.expectedSameOriginRequestAborts.push(failureDetails)');
  expect(requestFailureBlock).toContain('summary.sameOriginRequestFailures.push(failureDetails)');
  expect(source).toContain("expect(summary.sameOriginRequestFailures, 'Same-origin requests failed.').toEqual([])");
});
