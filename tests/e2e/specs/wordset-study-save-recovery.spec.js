const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const jquery = fs.readFileSync(require.resolve('jquery'), 'utf8');
const source = fs.readFileSync(path.resolve(__dirname, '../../../js/wordset-pages.js'), 'utf8');
const stylesheet = fs.readFileSync(path.resolve(__dirname, '../../../css/wordset-pages.css'), 'utf8');

async function mount(page, options = {}) {
  await page.setContent(`<div class="ll-wordset-page" data-ll-wordset-page>
    <button data-ll-wordset-goal-mode="learning">Learn</button>
    <button data-ll-wordset-goal-mode="listening">Listen</button>
    <button data-ll-wordset-transition="fast">Fast</button>
    <button data-ll-wordset-transition="slow">Slow</button>
  </div>`);
  await page.addStyleTag({ content: stylesheet });
  await page.addScriptTag({ content: jquery });
  await page.evaluate((options) => {
    window.llWordsetPageData = {
      view: 'settings', ajaxUrl: '/save', nonce: 'test', isLoggedIn: true, wordsetId: 77,
      categories: [], visibleCategoryIds: [],
      goals: { enabled_modes: ['learning', 'practice', 'listening'] },
      state: { fast_transitions: false, category_ids: options.categoryIds || [], starred_word_ids: options.starredWordIds || [] },
      i18n: { saveError: 'Unable to save right now.', saveSaving: 'Saving...', saveSaved: 'Saved', saveRetry: 'Retry' }
    };
    window.requests = [];
    window.alerts = [];
    window.alert = (message) => window.alerts.push(message);
    window.jQuery.ajax = (options) => {
      const deferred = window.jQuery.Deferred();
      window.requests.push({ data: options.data, deferred });
      return deferred.promise();
    };
    window.succeed = (index) => {
      const request = window.requests[index];
      request.deferred.resolve({ success: true, data: request.data.goals
        ? { goals: JSON.parse(request.data.goals) }
        : { state: { fast_transitions: !!request.data.fast_transitions, category_ids: request.data.category_ids, starred_word_ids: request.data.starred_word_ids } } });
    };
    window.fail = (index, code = 'user_data_mutation_lock_unavailable', status = 503, retryAfter = 1) => {
      window.requests[index].deferred.reject({ status, responseJSON: {
        success: false, data: { code, retryable: true, retry_after: retryAfter }
      } }, 'error');
    };
  }, options);
  await page.addScriptTag({ content: source });
}

test('a brief progress lock retries the same settings snapshot without a popup', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Learn', exact: true }).click();
  await page.evaluate(() => window.fail(0));
  await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(2);
  expect(await page.evaluate(() => window.requests[0].data)).toEqual(await page.evaluate(() => window.requests[1].data));
  await page.evaluate(() => window.succeed(1));
  expect(await page.evaluate(() => window.alerts)).toEqual([]);
  await expect(page.locator('[data-ll-wordset-goal-mode="learning"]')).toHaveAttribute('aria-pressed', 'false');
});

test('rapid goal and transition changes save in order and keep the latest choices', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Learn', exact: true }).click();
  await page.getByRole('button', { name: 'Listen', exact: true }).click();
  await page.getByRole('button', { name: 'Fast', exact: true }).click();
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.requests.length)).toBe(1);
  await page.evaluate(() => window.succeed(0));
  expect(await page.evaluate(() => JSON.parse(window.requests[1].data.goals).enabled_modes)).toEqual(['practice']);
  await page.evaluate(() => window.succeed(1));
  expect(await page.evaluate(() => window.requests[2].data.action)).toBe('ll_user_study_save');
  await page.evaluate(() => window.succeed(2));
  await expect(page.locator('[data-ll-wordset-goal-mode="learning"]')).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-ll-wordset-goal-mode="listening"]')).toHaveAttribute('aria-pressed', 'false');
  expect(await page.evaluate(() => window.alerts)).toEqual([]);
});

for (const [label, code, status, delay] of [
  ['expired authentication', 'invalid_nonce', 403, 1],
  ['ambiguous connection loss', '', 0, 1],
  ['privacy erasure', 'user_data_privacy_erasure_in_progress', 503, 60],
  ['long server backoff', 'user_data_mutation_lock_unavailable', 503, 60]
]) {
  test(`${label} is reported without replaying the mutation`, async ({ page }) => {
    await mount(page);
    await page.getByRole('button', { name: 'Learn', exact: true }).click();
    await page.evaluate(([c, s, d]) => window.fail(0, c, s, d), [code, status, delay]);
    expect(await page.evaluate(() => window.alerts)).toEqual(['Unable to save right now.']);
    await page.waitForTimeout(1100);
    expect(await page.evaluate(() => window.requests.length)).toBe(1);
  });
}

test('persistent lock contention stops after four attempts and reports failure', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Learn', exact: true }).click();
  for (let index = 0; index < 4; index++) {
    await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(index + 1);
    await page.evaluate((i) => window.fail(i), index);
  }
  expect(await page.evaluate(() => window.alerts)).toEqual(['Unable to save right now.']);
  await page.waitForTimeout(1100);
  expect(await page.evaluate(() => window.requests.length)).toBe(4);
});

test('an HTTP 200 error is not mistaken for a saved setting', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Learn', exact: true }).click();
  await page.evaluate(() => window.requests[0].deferred.resolve({ success: false }));
  expect(await page.evaluate(() => window.alerts)).toEqual(['Unable to save right now.']);
});

for (const [label, code, status] of [
  ['ambiguous connection loss', '', 0],
  ['expired authentication', 'invalid_nonce', 403]
]) {
  test(`transition autosave retains its draft after ${label} until explicit Retry`, async ({ page }) => {
    await mount(page, { categoryIds: [11, 12], starredWordIds: [101] });
    await page.getByRole('button', { name: 'Fast', exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
    await page.evaluate(([code, status]) => window.fail(0, code, status), [code, status]);
    await expect(page.locator('[data-ll-wordset-transition="fast"]')).toHaveAttribute('aria-pressed', 'true');
    const feedback = page.locator('[data-ll-wordset-state-save-status]');
    await expect(feedback).toContainText('Unable to save right now.');
    await expect(page.locator('[data-ll-wordset-state-save-retry]')).toBeVisible();
    expect(await page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    })).toBe(true);
    await page.waitForTimeout(1100);
    expect(await page.evaluate(() => window.requests.length)).toBe(1);
    await page.locator('[data-ll-wordset-state-save-retry]').click();
    await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(2);
    expect(await page.evaluate(() => window.requests[1].data)).toMatchObject({
      category_ids: [11, 12], starred_word_ids: [101], fast_transitions: 1
    });
    await page.evaluate(() => window.succeed(1));
    await expect(feedback).toContainText('Saved');
    await expect(page.locator('[data-ll-wordset-state-save-retry]')).toBeHidden();
    expect(await page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    })).toBe(false);
    expect(await page.evaluate(() => window.alerts)).toEqual([]);
  });
}

test('a successful older snapshot cannot overwrite a newer edit during its debounce', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Fast', exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
  await page.evaluate(() => {
    // Acknowledge the old snapshot before the new edit's 250 ms debounce.
    document.querySelector('[data-ll-wordset-transition="slow"]').click();
    window.succeed(0);
  });
  await expect(page.locator('[data-ll-wordset-transition="slow"]')).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(2);
  expect(await page.evaluate(() => window.requests[1].data.fast_transitions)).toBe(0);
  await page.evaluate(() => window.succeed(1));
  await expect(page.locator('[data-ll-wordset-state-save-status]')).toContainText('Saved');
  await page.waitForTimeout(300);
  expect(await page.evaluate(() => window.requests.length)).toBe(2);
  await expect(page.locator('[data-ll-wordset-transition="slow"]')).toHaveAttribute('aria-pressed', 'true');
});

test('an HTTP 200 state-save failure keeps optimistic choices and exposes Retry', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'Fast', exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
  await page.evaluate(() => window.requests[0].deferred.resolve({ success: false }));
  await expect(page.locator('[data-ll-wordset-transition="fast"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-ll-wordset-state-save-retry]')).toBeVisible();
});

test('autosave feedback keeps hidden Retry theme-resistant and visible failures in a scrolled page', async ({ page }) => {
  await mount(page);
  await page.addStyleTag({ content: '[hidden] { display: block !important; }' });
  await page.evaluate(() => {
    document.querySelector('[data-ll-wordset-page]').style.minHeight = '3000px';
  });
  await page.getByRole('button', { name: 'Fast', exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.requests.length)).toBe(1);
  await expect(page.locator('[data-ll-wordset-state-save-retry]')).toBeHidden();
  await page.evaluate(() => {
    window.scrollTo(0, 1500);
    window.fail(0, '', 0);
  });
  const feedback = page.locator('[data-ll-wordset-state-save-status]');
  await expect(feedback).toBeInViewport();
  await expect(page.locator('[data-ll-wordset-state-save-retry]')).toBeVisible();
});
