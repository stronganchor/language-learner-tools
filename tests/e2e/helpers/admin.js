const { expect } = require('@playwright/test');

const ADMIN_USER = process.env.LL_E2E_ADMIN_USER || '';
const ADMIN_PASS = process.env.LL_E2E_ADMIN_PASS || '';

function hasAdminCredentials() {
  return !!(ADMIN_USER && ADMIN_PASS);
}

function targetMatchesCurrentUrl(page, targetPath) {
  if (!targetPath) {
    return true;
  }

  try {
    const current = new URL(page.url());
    const target = new URL(targetPath, current.origin);
    const currentPath = current.pathname.replace(/\/index\.php$/, '/');
    const targetPathname = target.pathname.replace(/\/index\.php$/, '/');
    if (targetPathname === '/wp-admin/' && /^\/wp-admin(?:\/|$)/.test(currentPath)) {
      return true;
    }
    return currentPath === targetPathname && current.search === target.search;
  } catch (_) {
    return false;
  }
}

async function gotoAdminPath(page, targetPath) {
  await page.goto(targetPath, { waitUntil: 'commit', timeout: 60000 });
  await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => {});
  await page.waitForFunction(() => (
    /\/wp-login\.php/.test(window.location.href)
    || !!document.querySelector('#loginform, #wpwrap, #wpadminbar')
  ), null, { timeout: 60000 });
}

async function dismissAdminEmailVerification(page) {
  if (!/action=confirm_admin_email/.test(page.url())) {
    return;
  }

  const remindLaterLink = page.getByRole('link', { name: /Remind me later/i }).first();
  if ((await remindLaterLink.count()) > 0) {
    await remindLaterLink.click();
    await page.waitForURL((url) => !/action=confirm_admin_email/.test(url.toString()), {
      timeout: 60000
    }).catch(() => {});
    return;
  }

  const confirmButton = page.getByRole('button', { name: /The email is correct/i }).first();
  if ((await confirmButton.count()) > 0) {
    await confirmButton.click();
    await page.waitForURL((url) => !/action=confirm_admin_email/.test(url.toString()), {
      timeout: 60000
    }).catch(() => {});
  }
}

async function ensureLoggedIntoAdmin(page, targetPath = '/wp-admin/') {
  await gotoAdminPath(page, targetPath);

  const loginForm = page.locator('#loginform');
  if (/\/wp-login\.php/.test(page.url()) || (await loginForm.count()) > 0) {
    await expect(page.locator('#user_login')).toBeVisible({ timeout: 30000 });
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASS);
    await page.click('#wp-submit');
    await page.waitForURL((url) => !/wp-login\.php/.test(url.toString()), {
      timeout: 60000
    }).catch(() => {});
  }

  await dismissAdminEmailVerification(page);

  if (!targetMatchesCurrentUrl(page, targetPath)) {
    await gotoAdminPath(page, targetPath);
    await dismissAdminEmailVerification(page);
  }

  await expect.poll(() => page.url(), { timeout: 60000 }).toMatch(/\/wp-admin(?:\/|$)/);
}

async function adminRest(page, path, { method = 'GET', body = null, timeoutMs = 30000 } = {}) {
  const performRequest = async () => page.evaluate(async ({ requestPath, requestMethod, requestBody, requestTimeoutMs }) => {
    const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
    if (!nonce) {
      return { error: 'missing-rest-nonce' };
    }

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), requestTimeoutMs || 30000);
    let response;
    let data = null;
    try {
      response = await fetch(requestPath, {
        method: requestMethod,
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        body: requestBody ? JSON.stringify(requestBody) : undefined,
        signal: controller.signal
      });

      try {
        data = await response.json();
      } catch (_) {
        data = null;
      }
    } catch (error) {
      return { error: error && error.name === 'AbortError' ? 'rest-request-timeout' : 'rest-request-failed' };
    } finally {
      window.clearTimeout(timeout);
    }

    if (!response) {
      return { error: 'rest-request-failed' };
    }

    return {
      ok: response.ok,
      status: response.status,
      data
    };
  }, { requestPath: path, requestMethod: method, requestBody: body, requestTimeoutMs: timeoutMs });

  let result = await performRequest();
  if (result && result.error === 'missing-rest-nonce') {
    await page.context().clearCookies().catch(() => {});
    await ensureLoggedIntoAdmin(page);
    result = await performRequest();
  }

  if (!result || result.error) {
    throw new Error(`REST ${method} ${path} failed: ${result && result.error ? result.error : 'unknown error'}`);
  }
  if (!result.ok) {
    throw new Error(`REST ${method} ${path} failed: HTTP ${result.status} ${JSON.stringify(result.data)}`);
  }

  return result.data;
}

async function createWpPage(page, { title, content, status = 'publish', timeoutMs = 30000 }) {
  await ensureLoggedIntoAdmin(page);

  const data = await adminRest(page, '/wp-json/wp/v2/pages', {
    method: 'POST',
    body: { title, content, status },
    timeoutMs
  });
  if (!data || !data.id || !data.link) {
    throw new Error('Failed to create page: REST response missing id/link');
  }

  return {
    id: data.id,
    link: data.link
  };
}

async function deleteWpPage(page, pageId) {
  if (!pageId || page.isClosed()) {
    return;
  }

  try {
    await ensureLoggedIntoAdmin(page);
    await adminRest(page, `/wp-json/wp/v2/pages/${pageId}?force=true`, {
      method: 'DELETE',
      timeoutMs: 10000
    });
  } catch (_) {
    // Best-effort cleanup should not hide the behavior under test.
  }
}

module.exports = {
  adminRest,
  createWpPage,
  deleteWpPage,
  dismissAdminEmailVerification,
  ensureLoggedIntoAdmin,
  hasAdminCredentials
};
