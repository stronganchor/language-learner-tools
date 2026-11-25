const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const jquery = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');
const lessonJs = fs.readFileSync(path.resolve(__dirname, '../../../js/vocab-lesson-page.js'), 'utf8');
const lessonCss = fs.readFileSync(path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'), 'utf8');
const quizJs = fs.readFileSync(path.resolve(__dirname, '../../../js/quiz-pages.js'), 'utf8');
const quizCss = fs.readFileSync(path.resolve(__dirname, '../../../css/quiz-pages.css'), 'utf8');

async function mount(page, embedded = false, options = {}) {
  const activityRequests = [];
  await page.route('https://lesson.test/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/embed/')) {
      activityRequests.push(url.href);
      if (url.pathname === '/embed/exercise') {
        url.pathname += '/';
        return route.fulfill({ status: 301, headers: { location: url.href }, body: '' });
      }
      const ready = options.ready === false ? '' : `<script>window.__LL_EMBED_STATE='ll-embed-ready';parent.postMessage({type:'ll-embed-ready'},location.origin);</script>`;
      return route.fulfill({ contentType: 'text/html', body: `<html><body><main data-test-activity>Activity ${url.searchParams.get('mode')}</main>${ready}</body></html>` });
    }
    return route.fulfill({ contentType: 'text/html', body: '<html><body>Lesson</body></html>' });
  });
  await page.goto('https://lesson.test/lesson');
  await page.setContent(`<main class="ll-vocab-lesson-page" data-ll-vocab-lesson>
    <div class="ll-vocab-lesson-category-settings">
      <button class="ll-vocab-lesson-category-settings-trigger" aria-expanded="false">Category</button>
      <form class="ll-vocab-lesson-category-settings-panel" aria-hidden="false">
        <input name="ll_vocab_lesson_category_settings_revision" value="0" type="hidden">
        <input name="ll_vocab_lesson_category_settings_client_id" value="00000000-0000-4000-8000-000000000000" type="hidden">
        <input name="ll_vocab_lesson_category_settings_sequence" value="1" type="hidden">
        <input name="ll_vocab_lesson_category_settings_lesson_id" value="91" type="hidden">
        <input name="ll_vocab_lesson_activity_settings_present" value="1" type="hidden">
        <label><input name="ll_vocab_lesson_enabled_modes[]" type="checkbox" value="practice" checked>Practice</label>
        <label><input name="ll_vocab_lesson_enabled_modes[]" type="checkbox" value="learning">Learn</label>
        <label>Open on lesson<select name="ll_vocab_lesson_embedded_mode"><option value="">Overview</option><option value="practice" ${embedded ? 'selected' : ''}>Practice</option><option value="learning">Learn</option></select></label>
        <div data-ll-category-settings-status hidden><span data-ll-category-settings-status-message></span></div>
      </form>
    </div>
    <div class="ll-vocab-lesson-modes"><button class="ll-vocab-lesson-mode-button ll-quiz-page-trigger" data-mode="practice" data-url="https://lesson.test/embed/exercise/?wordset=hebrew&ll_context=vocab_lesson&lesson_id=91&mode=practice">Practice</button></div>
    <div class="ll-vocab-lesson-embedded ll-tools-quiz-iframe-wrapper" data-ll-lesson-activity-host data-iframe-state="loading" ${embedded ? '' : 'hidden'}>${embedded ? '<div class="ll-tools-iframe-state ll-tools-iframe-state--loading"><div class="ll-tools-iframe-loading" aria-hidden="true"></div><div class="ll-tools-iframe-loading-status screen-reader-text" role="status" aria-live="polite">Loading quiz...</div></div><iframe class="ll-vocab-lesson-activity-frame" data-ll-lesson-activity-frame src="https://lesson.test/embed/exercise/?wordset=hebrew&ll_context=vocab_lesson&lesson_id=91&mode=practice" title="Practice"></iframe>' : ''}</div>
    <div data-ll-lesson-study-content ${embedded ? 'hidden' : ''}>${embedded ? '' : '<div class="word-grid">ANSWER KEY</div>'}</div>
  </main>`);
  await page.addStyleTag({ content: quizCss });
  await page.addStyleTag({ content: lessonCss });
  await page.addScriptTag({ content: jquery });
  await page.evaluate(() => {
    window.llToolsVocabLessonData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      activity: { lessonId: 91, gridNonce: 'grid-nonce', title: 'Lesson activity', modeLabels: { practice: 'Practice', learning: 'Learn' }, embedBase: 'https://lesson.test/embed/exercise/?wordset=hebrew&ll_context=vocab_lesson&lesson_id=91' },
      categorySettings: { i18n: { saved: 'Saved', saving: 'Saving', error: 'Unable to save' } }
    };
    window.saves = [];
    window.saveRequests = 0;
    window.saveDelay = 20;
    window.gridLoads = 0;
    window.saveFailure = false;
    jQuery.ajax = options => {
      const d = jQuery.Deferred();
      const form = options.data;
      const isSave = form instanceof FormData;
      if (isSave) { window.saveRequests += 1; }
      const settle = () => {
        if (isSave) {
          const enabled = form.getAll('ll_vocab_lesson_enabled_modes[]');
          const mode = form.get('ll_vocab_lesson_embedded_mode');
          window.saves.push({ enabled, mode });
          if (window.saveFailure) { d.reject({ responseJSON: { data: { message: 'Unable to save' } } }, 'error'); return; }
          d.resolve({ success: true, data: {
            revision: window.saves.length,
            available_modes: enabled,
            activity_settings: { enabled_modes: enabled, embedded_mode: mode },
            embedded_mode: mode,
            embedded_url: mode ? 'https://lesson.test/embed/exercise/?wordset=hebrew&ll_context=vocab_lesson&lesson_id=91&mode=' + mode : ''
          } });
        } else {
          window.gridLoads += 1;
          d.resolve({ success: true, data: { html: '<div data-ll-word-grid><article class="word-item">Restored vocabulary</article></div>', paged: false } });
        }
      };
      if (isSave && window.holdFirstSave && window.saveRequests === 1) { window.settleFirstSave = settle; }
      else { window.setTimeout(settle, isSave ? window.saveDelay : 20); }
      const p = d.promise();
      p.abort = () => d.reject({}, 'abort');
      return p;
    };
  });
  await page.evaluate(timeout => {
    window.llQuizPages = { iframeTimeoutMs: timeout, labels: { loadingLabel: 'Loading quiz...', readyLabel: 'Quiz ready.', loadTimeoutLabel: 'The quiz is taking too long to load.', retryLabel: 'Retry', openDirectLabel: 'Open quiz in a new tab' } };
    const listeners = new Set();
    const add = window.addEventListener.bind(window);
    const remove = window.removeEventListener.bind(window);
    window.addEventListener = function (type, listener, options) {
      if (type === 'message') { listeners.add(listener); }
      return add(type, listener, options);
    };
    window.removeEventListener = function (type, listener, options) {
      if (type === 'message') { listeners.delete(listener); }
      return remove(type, listener, options);
    };
    window.activityMessageListenerCount = () => listeners.size;
  }, options.timeoutMs || 2000);
  if (!options.deferScripts) {
    await page.addScriptTag({ content: quizJs });
    await page.addScriptTag({ content: lessonJs });
  }
  return activityRequests;
}

test('initial embedded lesson loads its child activity directly at the canonical URL', async ({ page }) => {
  const activityRequests = await mount(page, true);
  await expect(page.frameLocator('[data-ll-lesson-activity-frame]').locator('[data-test-activity]')).toHaveText('Activity practice');
  await expect(page.locator('[data-ll-lesson-activity-host]')).toHaveAttribute('data-iframe-state', 'ready');
  expect(activityRequests).toHaveLength(1);
  const url = new URL(activityRequests[0]);
  expect(url.pathname).toBe('/embed/exercise/');
  expect(url.searchParams.get('lesson_id')).toBe('91');
  expect(url.searchParams.get('wordset')).toBe('hebrew');
});

test('server loading markup masks the child before JavaScript and reveals only a ready activity', async ({ page }) => {
  await mount(page, true, { ready: false, deferScripts: true });
  const frame = page.locator('[data-ll-lesson-activity-frame]');
  const host = page.locator('[data-ll-lesson-activity-host]');
  await expect(frame).toHaveCSS('visibility', 'hidden');
  await expect(page.locator('.ll-tools-iframe-loading')).toBeVisible();
  await expect(page.locator('.ll-tools-iframe-state')).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
  await page.addScriptTag({ content: quizJs });
  await page.addScriptTag({ content: lessonJs });
  await expect(host).toHaveAttribute('data-iframe-state', 'loading');
  await expect(frame).toHaveCSS('visibility', 'hidden');
  await page.frameLocator('[data-ll-lesson-activity-frame]').locator('body').evaluate(() => {
    requestAnimationFrame(() => {
      window.__LL_EMBED_STATE = 'll-embed-ready';
      parent.postMessage({ type: 'll-embed-ready' }, location.origin);
    });
  });
  await expect(host).toHaveAttribute('data-iframe-state', 'ready');
  await expect(frame).toHaveCSS('visibility', 'visible');
});

test('lesson timeout offers scoped retry and direct open without restarting a recovered same-mode attempt', async ({ page }) => {
  const options = { ready: false, timeoutMs: 80 };
  const requests = await mount(page, true, options);
  const host = page.locator('[data-ll-lesson-activity-host]');
  await expect(host).toHaveAttribute('data-iframe-state', 'timeout');
  await expect(host.locator('.ll-tools-iframe-loading-status')).toHaveText('The quiz is taking too long to load.');
  await expect(host.getByRole('button', { name: 'Retry' })).toBeVisible();
  const directUrl = new URL(await host.getByRole('link', { name: 'Open quiz in a new tab' }).getAttribute('href'));
  expect(Object.fromEntries(directUrl.searchParams)).toEqual({ wordset: 'hebrew', ll_context: 'vocab_lesson', lesson_id: '91', mode: 'practice' });
  options.ready = true;
  await host.getByRole('button', { name: 'Retry' }).click();
  await expect(host).toHaveAttribute('data-iframe-state', 'ready');
  expect(requests).toHaveLength(2);
  const retryUrl = new URL(requests[1]);
  expect(retryUrl.searchParams.has('_ll_quiz_retry')).toBe(true);
  for (const [key, value] of directUrl.searchParams) { expect(retryUrl.searchParams.get(key)).toBe(value); }
  await page.locator('[data-ll-lesson-activity-frame]').evaluate(frame => frame.setAttribute('data-test-preserved', 'yes'));
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'practice');
  await expect(page.locator('[data-ll-category-settings-status]')).toContainText('Saved');
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveAttribute('data-test-preserved', 'yes');
  expect(requests).toHaveLength(2);
});

test('late valid lesson readiness clears timeout while wrong-origin and wrong-source messages are ignored', async ({ page }) => {
  const requests = await mount(page, true, { ready: false, timeoutMs: 80 });
  const host = page.locator('[data-ll-lesson-activity-host]');
  await expect(host).toHaveAttribute('data-iframe-state', 'timeout');
  await page.evaluate(() => {
    const source = document.querySelector('[data-ll-lesson-activity-frame]').contentWindow;
    window.dispatchEvent(new MessageEvent('message', { source, origin: 'https://unrelated.test', data: { type: 'll-embed-ready' } }));
    window.dispatchEvent(new MessageEvent('message', { source: window, origin: location.origin, data: { type: 'll-embed-ready' } }));
  });
  await expect(host).toHaveAttribute('data-iframe-state', 'timeout');
  await page.frameLocator('[data-ll-lesson-activity-frame]').locator('body').evaluate(() => parent.postMessage({ type: 'll-embed-ready' }, location.origin));
  await expect(host).toHaveAttribute('data-iframe-state', 'ready');
  expect(requests).toHaveLength(1);
  expect(await page.evaluate(() => window.activityMessageListenerCount())).toBe(0);
});

test('mode changes replace the old frame and returning to overview removes its readiness listeners', async ({ page }) => {
  await mount(page, true, { ready: false, timeoutMs: 80 });
  await page.evaluate(() => { window.oldActivitySource = document.querySelector('[data-ll-lesson-activity-frame]').contentWindow; });
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'learning');
  const host = page.locator('[data-ll-lesson-activity-host]');
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveAttribute('src', /mode=learning/);
  await expect(host).toHaveAttribute('data-iframe-state', 'timeout');
  expect(await page.evaluate(() => window.oldActivitySource === document.querySelector('[data-ll-lesson-activity-frame]').contentWindow)).toBe(false);
  await page.evaluate(() => window.dispatchEvent(new MessageEvent('message', { source: window.oldActivitySource, origin: location.origin, data: { type: 'll-embed-ready' } })));
  await expect(host).toHaveAttribute('data-iframe-state', 'timeout');
  expect(await page.evaluate(() => window.activityMessageListenerCount())).toBe(1);
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', '');
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveCount(0);
  await expect(host).not.toHaveAttribute('data-ll-iframe-wired');
  expect(await page.evaluate(() => window.activityMessageListenerCount())).toBe(0);
});

test('autosave opens the embedded activity and hides the existing answer overview without navigating', async ({ page }) => {
  await mount(page);
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'practice');
  const frame = page.locator('[data-ll-lesson-activity-frame]');
  await expect(frame).toHaveAttribute('src', /lesson_id=91&mode=practice/);
  await expect(page.frameLocator('[data-ll-lesson-activity-frame]').locator('[data-test-activity]')).toHaveText('Activity practice');
  await expect(page.locator('[data-ll-lesson-study-content]')).toBeHidden();
  await expect(page.locator('.ll-vocab-lesson-modes')).toBeHidden();
  await expect(page.locator('[data-ll-category-settings-status]')).toContainText('Saved');
  expect(page.url()).toBe('https://lesson.test/lesson');
  expect(await page.evaluate(() => window.gridLoads)).toBe(0);
});

test('choosing a disabled primary activity enables it and switches modes inside the same lesson', async ({ page }) => {
  await mount(page);
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'learning');
  await expect(page.locator('[name="ll_vocab_lesson_enabled_modes[]"][value="learning"]')).toBeChecked();
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveAttribute('src', /mode=learning/);
  await page.locator('.ll-vocab-lesson-mode-button[data-mode="practice"]').click();
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveAttribute('src', /mode=practice/);
  await expect(page.locator('.ll-vocab-lesson-mode-button[data-mode="practice"]')).toHaveAttribute('aria-pressed', 'true');
  expect(page.url()).toBe('https://lesson.test/lesson');
});

test('returning from an initially embedded lesson loads the overview only after the setting saves', async ({ page }) => {
  await mount(page, true);
  expect(await page.evaluate(() => window.gridLoads)).toBe(0);
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', '');
  await expect(page.locator('[data-ll-lesson-activity-host]')).toBeHidden();
  await expect(page.locator('[data-ll-lesson-study-content]')).toContainText('Restored vocabulary');
  expect(await page.evaluate(() => window.gridLoads)).toBe(1);
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveCount(0);
});

test('an older successful save cannot apply activity settings during a newer draft debounce', async ({ page }) => {
  await mount(page);
  await page.evaluate(() => {
    window.holdFirstSave = true;
    window.llToolsFlashcardsData = { availableModes: ['practice'] };
    window.staleFrameLoaded = false;
    new MutationObserver(() => {
      const frame = document.querySelector('[data-ll-lesson-activity-frame]');
      if (frame && frame.src.includes('mode=learning')) { window.staleFrameLoaded = true; }
    }).observe(document.querySelector('[data-ll-lesson-activity-host]'), { childList: true, subtree: true, attributes: true });
  });
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'learning');
  await page.waitForFunction(() => window.saveRequests === 1);
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'practice');
  // Resolve the first request during the second draft's 500ms debounce.
  await page.evaluate(() => window.settleFirstSave());
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveCount(0);
  await expect(page.locator('[data-ll-category-settings-status]')).toHaveAttribute('data-state', 'saving');
  expect(await page.evaluate(() => window.llToolsFlashcardsData.availableModes)).toEqual(['practice']);
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveAttribute('src', /mode=practice/);
  await expect(page.locator('[data-ll-category-settings-status]')).toHaveAttribute('data-state', 'saved');
  expect(await page.evaluate(() => window.staleFrameLoaded)).toBe(false);
});

test('failed save leaves the existing lesson view unchanged', async ({ page }) => {
  await mount(page);
  await page.evaluate(() => { window.saveFailure = true; });
  await page.selectOption('[name="ll_vocab_lesson_embedded_mode"]', 'practice');
  await expect(page.locator('[data-ll-category-settings-status]')).toContainText('Unable to save');
  await expect(page.locator('[data-ll-lesson-activity-frame]')).toHaveCount(0);
  await expect(page.locator('[data-ll-lesson-study-content]')).toBeVisible();
});

for (const width of [390, 1280]) {
  test(`embedded frame stays within the lesson at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 850 });
    await mount(page, true);
    await page.addStyleTag({ content: 'iframe { width: 1800px; border: 10px solid red; } [hidden] { display: block; }' });
    const box = await page.locator('[data-ll-lesson-activity-frame]').boundingBox();
    expect(box.width).toBeLessThanOrEqual(width);
    expect(box.x + box.width).toBeLessThanOrEqual(width);
    expect(box.height).toBeGreaterThanOrEqual(480);
    await expect(page.locator('[data-ll-lesson-study-content]')).toBeHidden();
  });
}
