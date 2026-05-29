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
    return current.pathname === target.pathname && current.search === target.search;
  } catch (_) {
    return false;
  }
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
  await page.goto(targetPath, { waitUntil: 'domcontentloaded', timeout: 60000 });

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
    await page.goto(targetPath, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await dismissAdminEmailVerification(page);
  }

  await expect.poll(() => page.url(), { timeout: 60000 }).toMatch(/\/wp-admin(?:\/|$)/);
}

async function adminRest(page, path, { method = 'GET', body = null } = {}) {
  const performRequest = async () => page.evaluate(async ({ requestPath, requestMethod, requestBody }) => {
    const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
    if (!nonce) {
      return { error: 'missing-rest-nonce' };
    }

    const response = await fetch(requestPath, {
      method: requestMethod,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: requestBody ? JSON.stringify(requestBody) : undefined
    });

    let data = null;
    try {
      data = await response.json();
    } catch (_) {
      data = null;
    }

    return {
      ok: response.ok,
      status: response.status,
      data
    };
  }, { requestPath: path, requestMethod: method, requestBody: body });

  let result = await performRequest();
  if (result && result.error === 'missing-rest-nonce') {
    await page.context().clearCookies().catch(() => {});
    await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');
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
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');

  const result = await page.evaluate(async (payload) => {
    const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
    if (!nonce) {
      return { error: 'missing-rest-nonce' };
    }

    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), payload.timeoutMs || 30000);
    let response;
    let data;
    try {
      response = await fetch('/wp-json/wp/v2/pages', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        body: JSON.stringify({
          title: payload.title,
          content: payload.content,
          status: payload.status
        }),
        signal: controller.signal
      });
      data = await response.json();
    } catch (error) {
      return { error: error && error.name === 'AbortError' ? 'rest-create-page-timeout' : 'rest-create-page-failed' };
    } finally {
      window.clearTimeout(timeout);
    }

    return {
      ok: response.ok,
      status: response.status,
      data
    };
  }, { title, content, status, timeoutMs });

  if (!result || result.error) {
    throw new Error(`Failed to create page: ${result && result.error ? result.error : 'unknown error'}`);
  }
  if (!result.ok || !result.data || !result.data.id || !result.data.link) {
    throw new Error(`Failed to create page: HTTP ${result ? result.status : 'unknown'}`);
  }

  return {
    id: result.data.id,
    link: result.data.link
  };
}

async function deleteWpPage(page, pageId) {
  if (!pageId || page.isClosed()) {
    return;
  }

  try {
    await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');
    await page.evaluate(async (id) => {
      const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
      if (!nonce || !id) return;

      const controller = new AbortController();
      const timeout = window.setTimeout(() => controller.abort(), 10000);
      try {
        await fetch(`/wp-json/wp/v2/pages/${id}?force=true`, {
          method: 'DELETE',
          headers: {
            'X-WP-Nonce': nonce
          },
          signal: controller.signal
        });
      } catch (_) {
        // Best-effort cleanup should not hide the behavior under test.
      } finally {
        window.clearTimeout(timeout);
      }
    }, pageId);
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
