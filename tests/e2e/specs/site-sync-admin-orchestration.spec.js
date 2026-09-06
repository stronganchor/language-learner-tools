const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const siteSyncSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/site-sync-admin.js'),
  'utf8'
);

async function loadSiteSyncHarness(page, responses) {
  await page.goto('about:blank');
  await page.setContent(`
    <section data-ll-site-sync-local-overview></section>
    <form data-ll-site-sync-apply-form>
      <input name="ll_site_sync_remote_password" value="test-password">
      <button type="submit" name="ll_site_sync_action" value="apply_push" data-ll-site-sync-apply-button>Apply</button>
      <div class="ll-site-sync-apply-progress" data-ll-site-sync-apply-progress hidden>
        <span class="spinner"></span>
        <span data-ll-site-sync-apply-status></span>
        <meter data-ll-site-sync-apply-meter min="0" max="1" value="0"></meter>
      </div>
    </form>
  `);
  await page.evaluate((responseQueues) => {
    window.__siteSyncRequests = [];
    window.__siteSyncResponses = responseQueues;
    window.__siteSyncPending = null;
    const nativeSetTimeout = window.setTimeout.bind(window);
    window.setTimeout = function (callback, delay) {
      return nativeSetTimeout(callback, Math.min(Number(delay) || 0, 5));
    };
    window.llToolsSiteSyncAdmin = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      localOverviewNonce: 'overview-nonce',
      applyPushNonce: 'apply-nonce',
      strings: {
        overviewLoading: 'Loading overview',
        overviewFailed: 'Overview failed',
        retry: 'Retry overview',
        applyFailed: 'Apply failed',
        applyDone: 'Apply complete',
        applyRunning: 'Apply running',
        applyStarting: 'Starting apply',
        applyPasswordRequired: 'Password required'
      }
    };
    window.fetch = function (_url, options) {
      const params = new URLSearchParams(options.body || '');
      const action = params.get('action') || '';
      const key = action === 'll_tools_site_sync_local_overview' ? 'overview' : 'apply';
      const request = Object.fromEntries(params.entries());
      window.__siteSyncRequests.push({ key, request });
      const response = (window.__siteSyncResponses[key] || []).shift();

      if (response && response.pending) {
        return new Promise((resolve, reject) => {
          window.__siteSyncPending = {
            resolve(payload) {
              resolve({ json: async () => payload });
            },
            reject(message) {
              reject(new Error(message || 'Network failure'));
            }
          };
        });
      }
      if (response && response.reject) {
        return Promise.reject(new Error(response.message || 'Network failure'));
      }
      return Promise.resolve({
        json: async () => (response ? response.payload : null)
      });
    };
  }, responses);
  await page.addScriptTag({ content: siteSyncSource });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true })));
}

test('Site Sync retries its overview and completes sequential apply batches', async ({ page }) => {
  await loadSiteSyncHarness(page, {
    overview: [
      { reject: true, message: 'Overview temporarily unavailable' },
      {
        payload: {
          success: true,
          data: { html: '<section id="overview-loaded" data-ll-site-sync-local-overview>Overview ready</section>' }
        }
      }
    ],
    apply: [
      {
        payload: {
          success: true,
          data: {
            message: 'First batch complete',
            progress: {
              sent_remote_updates: 2,
              sent_conflict_review_updates: 0,
              next_remote_updates: 1,
              next_conflict_review_updates: 0,
              done: false
            }
          }
        }
      },
      {
        payload: {
          success: true,
          data: {
            message: 'All batches complete',
            progress: {
              sent_remote_updates: 1,
              sent_conflict_review_updates: 0,
              next_remote_updates: 0,
              next_conflict_review_updates: 0,
              done: true
            }
          }
        }
      }
    ]
  });

  await expect(page.locator('.ll-site-sync-retry-overview')).toBeVisible();
  await expect(page.locator('[data-ll-site-sync-overview-state]')).toHaveText('Overview temporarily unavailable');
  await page.locator('.ll-site-sync-retry-overview').click();
  await expect(page.locator('#overview-loaded')).toHaveText('Overview ready');

  await page.locator('[data-ll-site-sync-apply-button]').click();
  await expect(page.locator('[data-ll-site-sync-apply-status]')).toHaveText('All batches complete');
  await expect(page.locator('[data-ll-site-sync-apply-meter]')).toHaveJSProperty('value', 100);
  await expect(page.locator('[data-ll-site-sync-apply-button]')).toBeDisabled();

  const applyRequests = await page.evaluate(() => (
    window.__siteSyncRequests.filter((entry) => entry.key === 'apply')
  ));
  expect(applyRequests).toHaveLength(2);
  for (const entry of applyRequests) {
    expect(entry.request).toMatchObject({
      action: 'll_tools_site_sync_apply_push_batch',
      nonce: 'apply-nonce',
      ll_site_sync_action: 'apply_push',
      ll_site_sync_remote_password: 'test-password'
    });
  }
});

test('Site Sync keeps a stalled apply single-flight and restores retry after failure settlement', async ({ page }) => {
  await loadSiteSyncHarness(page, {
    overview: [{
      payload: {
        success: true,
        data: { html: '<section data-ll-site-sync-local-overview>Overview ready</section>' }
      }
    }],
    apply: [{ pending: true }]
  });

  await page.locator('[data-ll-site-sync-apply-button]').click();
  await expect(page.locator('[data-ll-site-sync-apply-button]')).toBeDisabled();
  await expect(page.locator('[data-ll-site-sync-apply-status]')).toHaveText('Starting apply');
  await page.waitForFunction(() => window.__siteSyncPending !== null);

  await page.evaluate(() => {
    const form = document.querySelector('[data-ll-site-sync-apply-form]');
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  expect(await page.evaluate(() => window.__siteSyncRequests.filter((entry) => entry.key === 'apply').length)).toBe(1);

  await page.evaluate(() => {
    window.__siteSyncPending.resolve({
      success: false,
      data: { message: 'Remote batch rejected' }
    });
  });
  await expect(page.locator('[data-ll-site-sync-apply-status]')).toHaveText('Remote batch rejected');
  await expect(page.locator('[data-ll-site-sync-apply-button]')).toBeEnabled();
  await expect(page.locator('.spinner')).not.toHaveClass(/is-active/);
});
