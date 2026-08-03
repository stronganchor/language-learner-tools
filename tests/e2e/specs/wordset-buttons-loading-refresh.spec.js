const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const refreshScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/wordset-buttons-refresh.js'),
  'utf8'
);
const refreshStyleSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);

function loadingMarkup() {
  return `
    <div
      class="ll-wordset-buttons-refresh"
      data-ll-wordset-buttons-refresh
      data-shortcode-tag="ll_wordset_buttons"
      data-shortcode-class="homepage-wordsets recorder-home"
      data-hide-empty="0"
      data-ajax-url="https://example.test/wp-admin/admin-ajax.php"
      data-ajax-action="ll_tools_wordset_buttons_refresh"
      data-nonce="recorder-refresh-nonce"
      data-error-message="Something went wrong"
      data-retry-label="Try again"
      aria-busy="true">
      <div class="ll-wordset-buttons-shortcode ll-wordset-buttons-shortcode--loading" aria-busy="true">
        <span class="screen-reader-text">Loading categories...</span>
      </div>
    </div>
  `;
}

function anonymousStatusMarkup(token = 'signed-public-status-token') {
  return `
    <div
      class="ll-wordset-buttons-refresh"
      data-ll-wordset-buttons-refresh
      data-ajax-url="https://example.test/wp-admin/admin-ajax.php"
      data-ajax-action="ll_tools_wordset_buttons_status"
      data-status-token="${token}"
      data-error-message="Something went wrong"
      data-retry-label="Try again"
      aria-busy="true">
      <div class="ll-wordset-buttons-shortcode ll-wordset-buttons-shortcode--loading" aria-busy="true">
        <span class="screen-reader-text">Loading categories...</span>
      </div>
    </div>
  `;
}

test('logged-in loading shell advances bounded batches and replaces itself with exact cards', async ({ page }) => {
  const requests = [];
  const responses = [
    {
      complete: false,
      html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Public wordset</a></div>',
      retryAfterMs: 10
    },
    {
      complete: true,
      html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Public wordset</a><a class="ll-wordset-buttons-shortcode__button">Recorder wordset</a></div>',
      retryAfterMs: 10
    }
  ];

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const params = new URLSearchParams(route.request().postData() || '');
    requests.push(Object.fromEntries(params.entries()));
    const response = responses[Math.min(requests.length - 1, responses.length - 1)];
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: response })
    });
  });

  await page.setContent(loadingMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      action: 'll_tools_wordset_buttons_refresh',
      nonce: 'recorder-refresh-nonce',
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxAttempts: 5
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByText('Public wordset')).toBeVisible();
  await expect(page.getByText('Recorder wordset')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveCount(0);
  await expect(page.locator('.ll-wordset-buttons-shortcode__button')).toHaveCount(2);
  await expect.poll(() => requests.length).toBe(2);

  expect(requests[0]).toEqual({
    action: 'll_tools_wordset_buttons_refresh',
    nonce: 'recorder-refresh-nonce',
    tag: 'll_wordset_buttons',
    class: 'homepage-wordsets recorder-home',
    hide_empty: '0'
  });

  await page.waitForTimeout(50);
  expect(requests).toHaveLength(2);
});

test('anonymous shell polls read-only status with only its signed scope token', async ({ page }) => {
  const requests = [];
  let activeRequests = 0;
  let maxActiveRequests = 0;

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const params = new URLSearchParams(route.request().postData() || '');
    requests.push(Object.fromEntries(params.entries()));
    activeRequests += 1;
    maxActiveRequests = Math.max(maxActiveRequests, activeRequests);
    await new Promise((resolve) => setTimeout(resolve, 10));
    activeRequests -= 1;

    const data = requests.length === 1
      ? { complete: false, html: '', retryAfterMs: 10 }
      : {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Recovered public wordset</a></div>',
          retryAfterMs: 0
        };
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data })
    });
  });

  await page.setContent(anonymousStatusMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 3,
      maxWaitMs: 1000
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByText('Recovered public wordset')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveCount(0);
  await expect.poll(() => requests.length).toBe(2);
  expect(maxActiveRequests).toBe(1);
  expect(requests).toEqual([
    {
      action: 'll_tools_wordset_buttons_status',
      token: 'signed-public-status-token'
    },
    {
      action: 'll_tools_wordset_buttons_status',
      token: 'signed-public-status-token'
    }
  ]);

  await page.waitForTimeout(50);
  expect(requests).toHaveLength(2);
});

test('anonymous status polling honors a rate-limit delay without spending its failure budget', async ({ page }) => {
  let requestCount = 0;
  const requestTimes = [];

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    requestTimes.push(Date.now());
    if (requestCount === 1) {
      await route.fulfill({
        status: 429,
        headers: { 'Retry-After': '1' },
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          data: { code: 'rate_limited', retryAfterMs: 200 }
        })
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Rate-limit recovered</a></div>',
          retryAfterMs: 0
        }
      })
    });
  });

  await page.setContent(anonymousStatusMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 1,
      maxWaitMs: 1000
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByText('Rate-limit recovered')).toBeVisible();
  expect(requestCount).toBe(2);
  expect(requestTimes[1] - requestTimes[0]).toBeGreaterThanOrEqual(150);
});

test('invalid anonymous status scope stops polling at the retry control', async ({ page }) => {
  let requestCount = 0;
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    await route.fulfill({
      status: 409,
      contentType: 'application/json',
      body: JSON.stringify({
        success: false,
        data: { code: 'stale_status_token' }
      })
    });
  });

  await page.setContent(anonymousStatusMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 5,
      maxWaitMs: 1000,
      errorMessage: 'Something went wrong',
      retryLabel: 'Try again'
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveAttribute('aria-busy', 'false');
  await page.waitForTimeout(50);
  expect(requestCount).toBe(1);
});

test('transient request failure retries without overlapping requests', async ({ page }) => {
  let requestCount = 0;
  let activeRequests = 0;
  let maxActiveRequests = 0;

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    activeRequests += 1;
    maxActiveRequests = Math.max(maxActiveRequests, activeRequests);
    await new Promise((resolve) => setTimeout(resolve, 15));
    activeRequests -= 1;

    if (requestCount === 1) {
      await route.fulfill({ status: 503, body: 'temporary failure' });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Recovered wordset</a></div>',
          retryAfterMs: 10
        }
      })
    });
  });

  await page.setContent(loadingMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'recorder-refresh-nonce',
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 4,
      maxWaitMs: 1000,
      errorMessage: 'Something went wrong',
      retryLabel: 'Try again'
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByText('Recovered wordset')).toBeVisible();
  expect(requestCount).toBe(2);
  expect(maxActiveRequests).toBe(1);
});

test('expired nonce is refreshed once and the bounded loader resumes', async ({ page }) => {
  const nonces = [];

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const params = new URLSearchParams(route.request().postData() || '');
    nonces.push(params.get('nonce'));
    if (nonces.length === 1) {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          data: {
            code: 'invalid_nonce',
            nonce: 'fresh-refresh-nonce'
          }
        })
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Nonce recovered</a></div>',
          retryAfterMs: 10
        }
      })
    });
  });

  await page.setContent(loadingMarkup(), { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByText('Nonce recovered')).toBeVisible();
  expect(nonces).toEqual(['recorder-refresh-nonce', 'fresh-refresh-nonce']);
});

test('durable server backoff waits without consuming the transport failure budget', async ({ page }) => {
  let requestCount = 0;
  let activeRequests = 0;
  let maxActiveRequests = 0;

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    activeRequests += 1;
    maxActiveRequests = Math.max(maxActiveRequests, activeRequests);
    activeRequests -= 1;

    const data = requestCount === 1
      ? { complete: false, html: '', retryAfterMs: 120 }
      : {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Backoff recovered</a></div>',
          retryAfterMs: 10
        };
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data })
    });
  });

  await page.setContent(loadingMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      maxFailures: 1,
      maxWaitMs: 1000,
      retryMs: 10,
      requestTimeoutMs: 500,
      errorMessage: 'Something went wrong',
      retryLabel: 'Try again'
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByText('Backoff recovered')).toBeVisible();
  expect(requestCount).toBe(2);
  expect(maxActiveRequests).toBe(1);
});

test('terminal request failures replace the infinite skeleton with a retry control', async ({ page }) => {
  let requestCount = 0;

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    if (requestCount <= 2) {
      await route.fulfill({ status: 503, body: 'temporary failure' });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Manual retry recovered</a></div>',
          retryAfterMs: 10
        }
      })
    });
  });

  await page.setContent(loadingMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      maxFailures: 2,
      maxWaitMs: 1000,
      retryMs: 10,
      requestTimeoutMs: 500,
      errorMessage: 'Something went wrong',
      retryLabel: 'Try again'
    };
  });
  await page.addStyleTag({ content: refreshStyleSource });
  await page.addScriptTag({ content: refreshScriptSource });

  const retry = page.getByRole('button', { name: 'Try again' });
  await expect(retry).toBeVisible();
  await expect(retry).toHaveCSS('background-color', 'rgb(153, 27, 27)');
  await expect(retry).toHaveCSS('border-radius', '999px');
  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveAttribute('aria-busy', 'false');
  await retry.click();
  await expect(page.getByText('Manual retry recovered')).toBeVisible();
  expect(requestCount).toBe(3);
});

test('late page-builder shells are discovered after the runtime initializes', async ({ page }) => {
  let requestCount = 0;
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button">Late shell recovered</a></div>',
          retryAfterMs: 10
        }
      })
    });
  });

  await page.setContent('<main id="content"></main>', { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ content: refreshScriptSource });
  await page.evaluate((markup) => {
    document.getElementById('content').insertAdjacentHTML('beforeend', markup);
  }, loadingMarkup());

  await expect(page.getByText('Late shell recovered')).toBeVisible();
  expect(requestCount).toBe(1);
});
