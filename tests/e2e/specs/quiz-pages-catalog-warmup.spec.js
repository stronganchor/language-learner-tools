const path = require('path');
const { test, expect } = require('@playwright/test');

test('cold quiz catalog warms asynchronously and reloads when ready', async ({ page }) => {
  let warmupRequests = 0;
  const payloads = [];

  await page.route('https://example.test/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());

    if (url.pathname === '/wp-admin/admin-ajax.php') {
      warmupRequests += 1;
      payloads.push(Object.fromEntries(new URLSearchParams(request.postData() || '')));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            ready: warmupRequests >= 2,
            retry_after_ms: 10
          }
        })
      });
      return;
    }

    if (url.pathname === '/ready/') {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<p id="ready">Catalog ready</p>'
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><html><body>
        <p role="status"
          data-ll-quiz-catalog-status="1"
          data-ajax-url="https://example.test/wp-admin/admin-ajax.php"
          data-action="ll_quiz_pages_catalog_warmup"
          data-nonce="test-nonce"
          data-scope-id="0123456789abcdef0123456789abcdef"
          data-refresh-url="https://example.test/ready/"
          data-retry-ms="10"
          data-max-attempts="3">Loading quiz...</p>
      </body></html>`
    });
  });

  await page.goto('https://example.test/start/');
  await page.addScriptTag({
    path: path.resolve(__dirname, '../../../js/quiz-pages-shortcodes.js')
  });

  await page.waitForURL('https://example.test/ready/');
  await expect(page.locator('#ready')).toBeVisible();
  expect(warmupRequests).toBe(2);
  expect(payloads).toEqual([
    {
      action: 'll_quiz_pages_catalog_warmup',
      nonce: 'test-nonce',
      scope_id: '0123456789abcdef0123456789abcdef'
    },
    {
      action: 'll_quiz_pages_catalog_warmup',
      nonce: 'test-nonce',
      scope_id: '0123456789abcdef0123456789abcdef'
    }
  ]);
});
