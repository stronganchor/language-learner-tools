const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const jquery = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');
const lessonJs = fs.readFileSync(path.resolve(__dirname, '../../../js/vocab-lesson-page.js'), 'utf8');
const lessonCss = fs.readFileSync(path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'), 'utf8');

async function mount(page, embedded = false) {
  const activityRequests = [];
  await page.route('https://lesson.test/**', route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/embed/')) {
      activityRequests.push(url.href);
      if (url.pathname === '/embed/exercise') {
        url.pathname += '/';
        return route.fulfill({ status: 301, headers: { location: url.href }, body: '' });
      }
      return route.fulfill({ contentType: 'text/html', body: `<html><body><main data-test-activity>Activity ${url.searchParams.get('mode')}</main></body></html>` });
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
    <div class="ll-vocab-lesson-modes"><button class="ll-vocab-lesson-mode-button ll-quiz-page-trigger" data-mode="practice" data-url="https://lesson.test/embed/exercise/?wordset=hebrew&lesson_id=91&mode=practice">Practice</button></div>
    <div class="ll-vocab-lesson-embedded" data-ll-lesson-activity-host ${embedded ? '' : 'hidden'}>${embedded ? '<iframe class="ll-vocab-lesson-activity-frame" data-ll-lesson-activity-frame src="https://lesson.test/embed/exercise/?wordset=hebrew&lesson_id=91&mode=practice" title="Practice"></iframe>' : ''}</div>
    <div data-ll-lesson-study-content ${embedded ? 'hidden' : ''}>${embedded ? '' : '<div class="word-grid">ANSWER KEY</div>'}</div>
  </main>`);
  await page.addStyleTag({ content: lessonCss });
  await page.addScriptTag({ content: jquery });
  await page.evaluate(() => {
    window.llToolsVocabLessonData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      activity: { lessonId: 91, gridNonce: 'grid-nonce', title: 'Lesson activity', modeLabels: { practice: 'Practice', learning: 'Learn' }, embedBase: 'https://lesson.test/embed/exercise/?wordset=hebrew&lesson_id=91' },
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
            embedded_url: mode ? 'https://lesson.test/embed/exercise/?wordset=hebrew&lesson_id=91&mode=' + mode : ''
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
  await page.addScriptTag({ content: lessonJs });
  return activityRequests;
}

test('initial embedded lesson loads its child activity directly at the canonical URL', async ({ page }) => {
  const activityRequests = await mount(page, true);
  await expect(page.frameLocator('[data-ll-lesson-activity-frame]').locator('[data-test-activity]')).toHaveText('Activity practice');
  expect(activityRequests).toHaveLength(1);
  const url = new URL(activityRequests[0]);
  expect(url.pathname).toBe('/embed/exercise/');
  expect(url.searchParams.get('lesson_id')).toBe('91');
  expect(url.searchParams.get('wordset')).toBe('hebrew');
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
