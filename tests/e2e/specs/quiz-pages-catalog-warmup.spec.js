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
          data-max-attempts="3"
          data-request-timeout-ms="1000"
          data-loading-message="Loading quiz..."
          data-timeout-message="Timed out."
          data-error-message="Could not load."
          data-exhausted-message="Still not ready.">
          <span data-ll-quiz-catalog-message>Loading quiz...</span>
          <button type="button" data-ll-quiz-catalog-retry hidden>Retry</button>
        </p>
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

test('large quiz catalog warmup can continue beyond twelve batches', async ({ page }) => {
  let warmupRequests = 0;

  await page.route('https://large-catalog.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/wp-admin/admin-ajax.php') {
      warmupRequests += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            ready: warmupRequests >= 13,
            retry_after_ms: 250
          }
        })
      });
      return;
    }

    if (url.pathname === '/ready/') {
      await route.fulfill({status: 200, contentType: 'text/html', body: '<p id="ready">Ready</p>'});
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><html><body>
        <p data-ll-quiz-catalog-status="1"
          data-ajax-url="https://large-catalog.test/wp-admin/admin-ajax.php"
          data-action="ll_quiz_pages_catalog_warmup"
          data-nonce="test-nonce"
          data-scope-id="0123456789abcdef0123456789abcdef"
          data-refresh-url="https://large-catalog.test/ready/"
          data-retry-ms="250"
          data-max-attempts="20"
          data-request-timeout-ms="1000">
          <span data-ll-quiz-catalog-message>Loading quiz...</span>
          <button type="button" data-ll-quiz-catalog-retry hidden>Retry</button>
        </p>
      </body></html>`
    });
  });

  await page.goto('https://large-catalog.test/start/');
  await page.addScriptTag({
    path: path.resolve(__dirname, '../../../js/quiz-pages-shortcodes.js')
  });

  await page.waitForURL('https://large-catalog.test/ready/');
  await expect(page.locator('#ready')).toBeVisible();
  expect(warmupRequests).toBe(13);
});

test('quiz catalog timeout becomes recoverable and retry fences the stale request', async ({ page }) => {
  let warmupRequests = 0;
  await page.route('https://recoverable-catalog.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/wp-admin/admin-ajax.php') {
      warmupRequests += 1;
      if (warmupRequests === 1) {
        await new Promise((resolve) => setTimeout(resolve, 150));
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({success: true, data: {ready: warmupRequests >= 2, retry_after_ms: 250}})
      });
      return;
    }
    if (url.pathname === '/ready/') {
      await route.fulfill({status: 200, contentType: 'text/html', body: '<p id="ready">Ready</p>'});
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><p role="status"
        data-ll-quiz-catalog-status="1"
        data-ajax-url="https://recoverable-catalog.test/wp-admin/admin-ajax.php"
        data-action="ll_quiz_pages_catalog_warmup"
        data-nonce="test-nonce"
        data-scope-id="0123456789abcdef0123456789abcdef"
        data-refresh-url="https://recoverable-catalog.test/ready/"
        data-retry-ms="250"
        data-max-attempts="1"
        data-request-timeout-ms="40"
        data-loading-message="Loading quiz..."
        data-timeout-message="Timed out."
        data-error-message="Could not load."
        data-exhausted-message="Still not ready.">
        <span data-ll-quiz-catalog-message>Loading quiz...</span>
        <button type="button" data-ll-quiz-catalog-retry hidden>Retry</button>
      </p>`
    });
  });

  await page.goto('https://recoverable-catalog.test/start/');
  await page.addScriptTag({path: path.resolve(__dirname, '../../../js/quiz-pages-shortcodes.js')});

  const status = page.locator('[data-ll-quiz-catalog-status]');
  const retry = page.locator('[data-ll-quiz-catalog-retry]');
  await expect(status).toHaveAttribute('data-state', 'exhausted');
  await expect(status.locator('[data-ll-quiz-catalog-message]')).toHaveText('Still not ready.');
  await expect(retry).toBeVisible();

  await retry.click();
  await page.waitForURL('https://recoverable-catalog.test/ready/');
  await expect(page.locator('#ready')).toBeVisible();
  expect(warmupRequests).toBe(2);
});
