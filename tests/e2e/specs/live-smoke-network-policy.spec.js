const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const {
  isExpectedCloudflareRumAbort,
  isExpectedCategorySearchWarmingResponse,
  isPotentialCategorySearchWarmingConsoleError
} = require('../live-smoke/network-policy');

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

test('live smoke recognizes only the exact retryable category-search preparation response', async () => {
  const expectedDetails = {
    method: 'POST',
    pathname: '/wp-admin/admin-ajax.php',
    adminAjaxAction: 'll_tools_wordset_page_category_search'
  };
  expect(isExpectedCategorySearchWarmingResponse(expectedDetails, 503)).toBe(true);

  const rejected = [
    [{ ...expectedDetails, method: 'GET' }, 503],
    [{ ...expectedDetails, pathname: '/wp-admin/admin-ajax.php/other' }, 503],
    [{ ...expectedDetails, adminAjaxAction: 'll_tools_wordset_page_lazy_cards' }, 503],
    [expectedDetails, 500],
    [expectedDetails, 200],
    [null, 503]
  ];
  for (const [details, status] of rejected) {
    expect(isExpectedCategorySearchWarmingResponse(details, status)).toBe(false);
  }
});

test('live smoke limits warming console classification to 503 resource errors from category-search AJAX', async () => {
  const message = 'Failed to load resource: the server responded with a status of 503 ()';
  expect(isPotentialCategorySearchWarmingConsoleError(
    message,
    'https://example.test/wp-admin/admin-ajax.php',
    'https://example.test'
  )).toBe(true);
  expect(isPotentialCategorySearchWarmingConsoleError(message, '', 'https://example.test')).toBe(true);
  expect(isPotentialCategorySearchWarmingConsoleError(
    message,
    'https://example.test/wp-content/plugin.js',
    'https://example.test'
  )).toBe(false);
  expect(isPotentialCategorySearchWarmingConsoleError(
    message,
    'https://other.test/wp-admin/admin-ajax.php',
    'https://example.test'
  )).toBe(false);
  expect(isPotentialCategorySearchWarmingConsoleError(
    'Failed to load resource: the server responded with a status of 500 ()',
    '',
    'https://example.test'
  )).toBe(false);
});
