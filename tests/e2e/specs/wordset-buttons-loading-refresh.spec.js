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
const retainedCardImageSrc = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+yZ5kAAAAASUVORK5CYII=';

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

function navigationMarkup() {
  return `
    <div
      class="ll-wordset-buttons-refresh"
      data-ll-wordset-buttons-refresh
      data-shortcode-tag="ll_wordset_buttons"
      data-shortcode-class="homepage-wordsets"
      data-hide-empty="0"
      data-ajax-url="https://example.test/wp-admin/admin-ajax.php"
      data-ajax-action="ll_tools_wordset_buttons_refresh"
      data-nonce="recorder-refresh-nonce"
      data-error-message="Something went wrong"
      data-retry-label="Try again"
      aria-busy="true">
      <div
        class="ll-wordset-buttons-shortcode ll-wordset-buttons-shortcode--loading ll-wordset-buttons-shortcode--navigation"
        data-ll-wordset-buttons-navigation
        aria-busy="true">
        <ul class="ll-wordset-buttons-shortcode__list">
          <li class="ll-wordset-buttons-shortcode__item">
            <a class="ll-wordset-buttons-shortcode__button" href="https://example.test/genc-palu/">Genç</a>
          </li>
          <li class="ll-wordset-buttons-shortcode__item">
            <a class="ll-wordset-buttons-shortcode__button" href="https://example.test/aksaray/">Aksaray</a>
          </li>
        </ul>
      </div>
    </div>
  `;
}

function identifiedNavigationMarkup() {
  return `
    <div
      class="ll-wordset-buttons-refresh"
      data-ll-wordset-buttons-refresh
      data-shortcode-tag="ll_wordset_buttons"
      data-shortcode-class="homepage-wordsets"
      data-hide-empty="1"
      data-ajax-url="https://example.test/wp-admin/admin-ajax.php"
      data-ajax-action="ll_tools_wordset_buttons_refresh"
      data-nonce="recorder-refresh-nonce"
      aria-busy="true">
      <div
        class="ll-wordset-page ll-wordset-page--shortcode ll-wordset-buttons-shortcode ll-wordset-buttons-shortcode--loading ll-wordset-buttons-shortcode--navigation"
        data-ll-wordset-buttons-navigation
        aria-busy="true">
        <span class="screen-reader-text">Loading categories...</span>
        <ul class="ll-wordset-buttons-shortcode__list">
          <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="101">
            <a
              class="ll-wordset-buttons-shortcode__button ll-wordset-buttons-shortcode__button--has-image ll-wordset-buttons-shortcode__button--navigation ll-wordset-buttons-shortcode__button--hydrating"
              href="https://example.test/alpha/"
              aria-label="Alpha"
              data-ll-wordset-id="101"
              data-ll-wordset-card-state="hydrating"
              aria-busy="true">
              <span class="ll-wordset-buttons-shortcode__media" aria-hidden="true"><img class="ll-wordset-buttons-shortcode__image" src="${retainedCardImageSrc}" alt="" loading="lazy" decoding="async" /></span>
              <span class="ll-wordset-buttons-shortcode__label-wrap"><span class="ll-wordset-buttons-shortcode__label">Alpha</span></span>
              <span class="ll-wordset-buttons-shortcode__count ll-wordset-buttons-shortcode__count--loading" aria-hidden="true"></span>
            </a>
          </li>
          <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="202">
            <a
              class="ll-wordset-buttons-shortcode__button ll-wordset-buttons-shortcode__button--navigation ll-wordset-buttons-shortcode__button--hydrating"
              href="https://example.test/beta/"
              aria-label="Beta"
              data-ll-wordset-id="202"
              data-ll-wordset-card-state="hydrating"
              aria-busy="true">
              <span class="ll-wordset-buttons-shortcode__label-wrap"><span class="ll-wordset-buttons-shortcode__label">Beta</span></span>
              <span class="ll-wordset-buttons-shortcode__count ll-wordset-buttons-shortcode__count--loading" aria-hidden="true"></span>
            </a>
          </li>
          <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="303">
            <a
              class="ll-wordset-buttons-shortcode__button ll-wordset-buttons-shortcode__button--navigation ll-wordset-buttons-shortcode__button--hydrating"
              href="https://example.test/ineligible/"
              aria-label="Ineligible"
              data-ll-wordset-id="303"
              data-ll-wordset-card-state="hydrating"
              aria-busy="true">
              <span class="ll-wordset-buttons-shortcode__label-wrap"><span class="ll-wordset-buttons-shortcode__label">Ineligible</span></span>
              <span class="ll-wordset-buttons-shortcode__count ll-wordset-buttons-shortcode__count--loading" aria-hidden="true"></span>
            </a>
          </li>
        </ul>
      </div>
    </div>
  `;
}

function identifiedCompleteMarkup() {
  return `
    <div class="ll-wordset-page ll-wordset-page--shortcode ll-wordset-buttons-shortcode">
      <ul class="ll-wordset-buttons-shortcode__list">
        <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="404">
          <a class="ll-wordset-buttons-shortcode__button" href="https://example.test/delta/" aria-label="Delta, 3 lessons" data-ll-wordset-id="404" data-ll-wordset-card-state="ready">
            <span class="ll-wordset-buttons-shortcode__label-wrap"><span class="ll-wordset-buttons-shortcode__label">Delta</span></span>
            <span class="ll-wordset-buttons-shortcode__count">3 lessons</span>
          </a>
        </li>
        <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="202">
          <a class="ll-wordset-buttons-shortcode__button" href="https://example.test/beta/" aria-label="Beta, 2 lessons" data-ll-wordset-id="202" data-ll-wordset-card-state="ready">
            <span class="ll-wordset-buttons-shortcode__label-wrap"><span class="ll-wordset-buttons-shortcode__label">Beta</span></span>
            <span class="ll-wordset-buttons-shortcode__count">2 lessons</span>
          </a>
        </li>
        <li class="ll-wordset-buttons-shortcode__item" data-ll-wordset-id="101">
          <a class="ll-wordset-buttons-shortcode__button ll-wordset-buttons-shortcode__button--has-image" href="https://example.test/alpha/" aria-label="Alpha, 4 lessons" data-ll-wordset-id="101" data-ll-wordset-card-state="ready">
            <span class="ll-wordset-buttons-shortcode__media" aria-hidden="true"><img class="ll-wordset-buttons-shortcode__image" src="${retainedCardImageSrc}" alt="" loading="lazy" decoding="async" /></span>
            <span class="ll-wordset-buttons-shortcode__label-wrap"><span class="ll-wordset-buttons-shortcode__label">Alpha</span></span>
            <span class="ll-wordset-buttons-shortcode__count">4 lessons</span>
          </a>
        </li>
      </ul>
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

test('identified completion reconciles cards in place without moving retained navigation', async ({ page }) => {
  let requestCount = 0;
  let releaseCompletion;
  const completionGate = new Promise((resolve) => {
    releaseCompletion = resolve;
  });

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    await completionGate;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: identifiedCompleteMarkup(),
          retryAfterMs: 0
        }
      })
    });
  });

  await page.setContent(identifiedNavigationMarkup(), { waitUntil: 'domcontentloaded' });
  await page.addStyleTag({ content: refreshStyleSource });
  await expect.poll(() => page.locator('a[data-ll-wordset-id="101"] img').evaluate(
    (image) => image.complete && image.naturalWidth > 0
  )).toBe(true);
  await page.evaluate(() => {
    const alpha = document.querySelector('a[data-ll-wordset-id="101"]');
    const beta = document.querySelector('a[data-ll-wordset-id="202"]');
    const alphaBox = alpha.getBoundingClientRect();
    window.__llWordsetButtonsInitialAnchors = {
      alpha,
      beta,
      alphaImage: alpha.querySelector('.ll-wordset-buttons-shortcode__image'),
      alphaBox: { width: alphaBox.width, height: alphaBox.height }
    };
    beta.focus();
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 2,
      maxWaitMs: 1000
    };
  });

  const initialCards = page.locator('.ll-wordset-buttons-shortcode__item[data-ll-wordset-id]');
  await expect(initialCards).toHaveCount(3);
  expect(await initialCards.evaluateAll((items) => items.map((item) => item.dataset.llWordsetId))).toEqual([
    '101',
    '202',
    '303'
  ]);

  const beta = page.locator('a[data-ll-wordset-id="202"]');
  await expect(beta).toBeFocused();
  await expect(beta).toHaveAttribute('data-ll-wordset-card-state', 'hydrating');
  await expect(beta).toHaveAttribute('aria-busy', 'true');
  await expect(beta.locator('.ll-wordset-buttons-shortcode__count')).toHaveAttribute('aria-hidden', 'true');

  await page.addScriptTag({ content: refreshScriptSource });
  await expect.poll(() => requestCount).toBe(1);
  releaseCompletion();

  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveCount(0);
  await expect(page.locator('[data-ll-wordset-buttons-navigation]')).toHaveCount(0);
  await expect(page.locator('.ll-wordset-buttons-shortcode')).toHaveCount(1);
  await expect(page.locator('.screen-reader-text', { hasText: 'Loading categories...' })).toHaveCount(0);

  const completedCards = page.locator('.ll-wordset-buttons-shortcode__item[data-ll-wordset-id]');
  await expect(completedCards).toHaveCount(3);
  const completedIds = await completedCards.evaluateAll((items) => items.map((item) => item.dataset.llWordsetId));
  expect(completedIds).toEqual(['101', '202', '404']);
  expect(new Set(completedIds).size).toBe(completedIds.length);
  await expect(page.locator('.ll-wordset-buttons-shortcode__item[data-ll-wordset-id="303"]')).toHaveCount(0);
  await expect(page.locator('.ll-wordset-buttons-shortcode__item[data-ll-wordset-id="404"]')).toHaveCount(1);

  const retainedIdentity = await page.evaluate(() => {
    const alpha = document.querySelector('a[data-ll-wordset-id="101"]');
    const betaLink = document.querySelector('a[data-ll-wordset-id="202"]');
    const alphaBox = alpha.getBoundingClientRect();
    return {
      alpha: alpha === window.__llWordsetButtonsInitialAnchors.alpha,
      beta: betaLink === window.__llWordsetButtonsInitialAnchors.beta,
      alphaImage: alpha.querySelector('.ll-wordset-buttons-shortcode__image') === window.__llWordsetButtonsInitialAnchors.alphaImage,
      alphaWidthDelta: Math.abs(alphaBox.width - window.__llWordsetButtonsInitialAnchors.alphaBox.width),
      alphaHeightDelta: Math.abs(alphaBox.height - window.__llWordsetButtonsInitialAnchors.alphaBox.height),
      betaFocused: document.activeElement === betaLink
    };
  });
  expect(retainedIdentity).toEqual({
    alpha: true,
    beta: true,
    alphaImage: true,
    alphaWidthDelta: 0,
    alphaHeightDelta: 0,
    betaFocused: true
  });

  const alpha = page.locator('a[data-ll-wordset-id="101"]');
  await expect(alpha).toHaveAttribute('data-ll-wordset-card-state', 'ready');
  await expect(alpha).toHaveAttribute('aria-label', 'Alpha, 4 lessons');
  await expect(alpha.locator('.ll-wordset-buttons-shortcode__count')).toHaveText('4 lessons');
  expect(await alpha.getAttribute('aria-busy')).toBeNull();
  expect(await alpha.locator('.ll-wordset-buttons-shortcode__count').getAttribute('aria-hidden')).toBeNull();

  await expect(beta).toHaveAttribute('data-ll-wordset-card-state', 'ready');
  await expect(beta).toHaveAttribute('aria-label', 'Beta, 2 lessons');
  await expect(beta.locator('.ll-wordset-buttons-shortcode__count')).toHaveText('2 lessons');
  expect(await beta.getAttribute('aria-busy')).toBeNull();
  expect(requestCount).toBe(1);
});

test('identified completion moves focus to the nearest surviving card when the active card is removed', async ({ page }) => {
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: identifiedCompleteMarkup(),
          retryAfterMs: 0
        }
      })
    });
  });

  await page.setContent(identifiedNavigationMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    document.querySelector('a[data-ll-wordset-id="303"]').focus();
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 2,
      maxWaitMs: 1000
    };
  });
  await expect(page.locator('a[data-ll-wordset-id="303"]')).toBeFocused();

  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.locator('a[data-ll-wordset-id="303"]')).toHaveCount(0);
  await expect(page.locator('a[data-ll-wordset-id="404"]')).toBeFocused();
  await expect(page.locator('.screen-reader-text', { hasText: 'Loading categories...' })).toHaveCount(0);
});

test('authoritative-empty completion moves focus to the following external control', async ({ page }) => {
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { complete: true, html: '', retryAfterMs: 0 }
      })
    });
  });

  await page.setContent(`
    <button id="before-wordsets" type="button">Before wordsets</button>
    ${identifiedNavigationMarkup()}
    <button id="after-wordsets" type="button">After wordsets</button>
  `, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    document.querySelector('a[data-ll-wordset-id="202"]').focus();
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 1000,
      maxFailures: 2,
      maxWaitMs: 1000
    };
  });
  await expect(page.locator('a[data-ll-wordset-id="202"]')).toBeFocused();

  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'After wordsets' })).toBeFocused();
  await expect(page.getByRole('button', { name: 'Before wordsets' })).not.toBeFocused();
});

test('terminal Retry marks identified navigation as stalled and non-busy', async ({ page }) => {
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    await route.fulfill({ status: 503, body: 'temporary failure' });
  });

  await page.setContent(identifiedNavigationMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 500,
      maxFailures: 1,
      maxWaitMs: 1000,
      errorMessage: 'Something went wrong',
      retryLabel: 'Try again'
    };
  });
  await page.addStyleTag({ content: refreshStyleSource });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('[data-ll-wordset-buttons-navigation]')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.screen-reader-text', { hasText: 'Loading categories...' })).toHaveCount(0);

  const cards = page.locator('.ll-wordset-buttons-shortcode__button[data-ll-wordset-id]');
  await expect(cards).toHaveCount(3);
  await expect(page.locator(
    '.ll-wordset-buttons-shortcode__button[data-ll-wordset-card-state="stalled"][aria-busy="false"]'
  )).toHaveCount(3);
  await expect(page.locator('.ll-wordset-buttons-shortcode__button--stalled')).toHaveCount(3);
  await expect(page.locator('.ll-wordset-buttons-shortcode__button--hydrating')).toHaveCount(0);

  const counts = page.locator('.ll-wordset-buttons-shortcode__count--loading');
  await expect(counts).toHaveCount(3);
  await expect(page.locator('.ll-wordset-buttons-shortcode__count--stalled')).toHaveCount(3);
  expect(await counts.first().evaluate((count) => getComputedStyle(count, '::after').animationName)).toBe('none');
});

test('navigation links stay usable before counts load and after count refresh fails', async ({ page }) => {
  let requestCount = 0;
  let releaseFirstRequest;
  let releaseRecoveryRequest;
  const firstRequestGate = new Promise((resolve) => {
    releaseFirstRequest = resolve;
  });
  const recoveryRequestGate = new Promise((resolve) => {
    releaseRecoveryRequest = resolve;
  });

  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    requestCount += 1;
    if (requestCount === 1) {
      await firstRequestGate;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: {
            complete: false,
            html: `
              <div class="ll-wordset-buttons-shortcode ll-wordset-buttons-shortcode--navigation" data-ll-wordset-buttons-navigation aria-busy="true">
                <a class="ll-wordset-buttons-shortcode__button" href="https://example.test/genc-palu/">Genç</a>
                <a class="ll-wordset-buttons-shortcode__button" href="https://example.test/aksaray/">Aksaray</a>
              </div>
            `,
            retryAfterMs: 10
          }
        })
      });
      return;
    }
    if (requestCount === 2) {
      await route.fulfill({ status: 503, body: 'temporary failure' });
      return;
    }

    await recoveryRequestGate;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          complete: true,
          html: '<div class="ll-wordset-buttons-shortcode"><a class="ll-wordset-buttons-shortcode__button" href="https://example.test/genc-palu/">Genç</a><span class="ll-wordset-buttons-shortcode__count">209 lessons</span></div>',
          retryAfterMs: 0
        }
      })
    });
  });

  await page.setContent(navigationMarkup(), { waitUntil: 'domcontentloaded' });
  const wordsetLink = page.getByRole('link', { name: 'Genç' });
  await expect(wordsetLink).toBeVisible();
  await expect(wordsetLink).toHaveAttribute('href', 'https://example.test/genc-palu/');

  await page.evaluate(() => {
    window.llToolsWordsetButtonsRefresh = {
      retryMs: 10,
      requestTimeoutMs: 500,
      maxFailures: 1,
      maxWaitMs: 1000,
      errorMessage: 'Something went wrong',
      retryLabel: 'Try again'
    };
  });
  await page.addScriptTag({ content: refreshScriptSource });

  await expect.poll(() => requestCount).toBe(1);
  await expect(wordsetLink).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-navigation]')).toHaveAttribute('aria-busy', 'true');
  releaseFirstRequest();

  const incompleteReplacementLink = page.getByRole('link', { name: 'Aksaray' });
  await expect(incompleteReplacementLink).toBeVisible();
  await expect(incompleteReplacementLink).toHaveAttribute('href', 'https://example.test/aksaray/');

  const retry = page.getByRole('button', { name: 'Try again' });
  await expect(retry).toBeVisible();
  await expect(wordsetLink).toBeVisible();
  await expect(incompleteReplacementLink).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-navigation]')).toHaveAttribute('aria-busy', 'false');

  await retry.click();
  await expect.poll(() => requestCount).toBe(3);
  await expect(wordsetLink).toBeVisible();
  await expect(incompleteReplacementLink).toBeVisible();
  releaseRecoveryRequest();
  await expect(page.getByText('209 lessons')).toBeVisible();
  await expect(page.locator('[data-ll-wordset-buttons-refresh]')).toHaveCount(0);
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
