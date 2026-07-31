const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);
const vocabLessonCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'),
  'utf8'
);
const vocabLessonScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-page.js'),
  'utf8'
);
const shellPreviewImage = `data:image/svg+xml,${encodeURIComponent(
  '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150" viewBox="0 0 150 150"><rect width="150" height="150" fill="#38bdf8"/></svg>'
)}`;

function buildDeferredLessonMarkup() {
  const boundedBlankShells = Array.from({ length: 4 }, () => `
    <article class="word-item ll-vocab-lesson-skeleton-card" aria-hidden="true">
      <div class="ll-vocab-lesson-skeleton-media"></div>
    </article>
  `).join('');
  const countPlaceholders = Array.from({ length: 6 }, () => `
    <article class="word-item ll-vocab-lesson-skeleton-card ll-vocab-lesson-skeleton-card--count-placeholder" aria-hidden="true">
      <div class="ll-vocab-lesson-skeleton-media"></div>
    </article>
  `).join('');

  return `
    <div class="ll-vocab-lesson-page">
      <div class="ll-vocab-lesson-bulk" data-ll-word-grid-bulk>
        <select data-ll-bulk-pos>
          <option value="">Part of speech</option>
          <option value="noun">Noun</option>
          <option value="verb">Verb</option>
        </select>
        <select data-ll-bulk-gender>
          <option value="">Gender</option>
          <option value="feminine">Feminine</option>
          <option value="masculine">Masculine</option>
        </select>
      </div>
      <div class="ll-vocab-lesson-content">
        <div
          class="ll-vocab-lesson-grid-shell is-loading"
          data-ll-vocab-lesson-grid-shell
          data-lesson-id="42"
          data-nonce="test-nonce"
          aria-busy="true"
        >
          <div class="screen-reader-text" data-ll-vocab-lesson-grid-status role="status" aria-live="polite">
            Loading lesson words...
          </div>
          <div id="word-grid" class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="1" data-ll-category-id="99" data-ll-expected-card-count="12">
            <article class="word-item ll-vocab-lesson-skeleton-card ll-vocab-lesson-skeleton-card--audio-ready" data-ll-shell-word-id="11">
              <div class="ll-vocab-lesson-skeleton-media ll-vocab-lesson-skeleton-media--preview">
                <img class="ll-vocab-lesson-shell-preview-image" src="${shellPreviewImage}" alt="" aria-hidden="true" loading="eager" decoding="async" fetchpriority="low" width="150" height="150">
              </div>
              <div class="ll-vocab-lesson-shell-title">
                <span class="ll-vocab-lesson-shell-word-text" dir="auto">Merhaba</span>
                <span class="ll-vocab-lesson-shell-translation-text" dir="auto">Hello</span>
              </div>
              <div class="ll-vocab-lesson-skeleton-recordings">
                <button type="button" class="ll-study-recording-btn ll-word-grid-recording-btn ll-study-recording-btn--question ll-vocab-lesson-shell-recording-btn" data-recording-type="question" data-audio-url="/wp-content/uploads/merhaba-question.mp3" aria-label="Play question recording" title="Play question recording">
                  <span class="ll-study-recording-icon" aria-hidden="true"></span>
                  <span class="ll-study-recording-visualizer" aria-hidden="true"><span class="bar"></span><span class="bar"></span><span class="bar"></span><span class="bar"></span></span>
                </button>
              </div>
            </article>
            <article class="word-item ll-vocab-lesson-skeleton-card" aria-hidden="true"></article>
            ${boundedBlankShells}
            ${countPlaceholders}
          </div>
          <div class="ll-vocab-lesson-grid-feedback" data-ll-vocab-lesson-grid-feedback hidden></div>
        </div>
      </div>
    </div>
  `;
}

test('deferred vocab lesson shell exposes useful content before hydration', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildDeferredLessonMarkup());
  await page.addStyleTag({ content: vocabLessonCssSource });

  await expect(page.locator('[data-ll-shell-word-id="11"] .ll-vocab-lesson-shell-word-text')).toHaveText('Merhaba');
  await expect(page.locator('[data-ll-shell-word-id="11"] .ll-vocab-lesson-shell-translation-text')).toHaveText('Hello');
  await expect(page.locator('[data-ll-shell-word-id="11"] .ll-vocab-lesson-shell-preview-image')).toHaveAttribute('fetchpriority', 'low');
  await expect(page.locator('[data-ll-shell-word-id="11"] .ll-vocab-lesson-shell-recording-btn')).toBeEnabled();
  await expect(page.locator('[data-ll-shell-word-id="11"] .ll-vocab-lesson-shell-recording-btn')).toHaveAttribute('data-audio-url', /merhaba-question\.mp3$/);

  const shellMetrics = await page.locator('[data-ll-shell-word-id="11"]').evaluate((card) => {
    const media = card.querySelector('.ll-vocab-lesson-skeleton-media');
    const title = card.querySelector('.ll-vocab-lesson-shell-title');
    const button = card.querySelector('.ll-vocab-lesson-shell-recording-btn');

    return {
      mediaHeight: media.getBoundingClientRect().height,
      titleHeight: title.getBoundingClientRect().height,
      buttonTabIndex: button.getAttribute('tabindex'),
      buttonDisabled: button.disabled,
      cardPointerEvents: window.getComputedStyle(card).pointerEvents
    };
  });

  expect(shellMetrics.mediaHeight).toBeGreaterThan(40);
  expect(shellMetrics.titleHeight).toBeGreaterThan(20);
  expect(shellMetrics.buttonTabIndex).toBeNull();
  expect(shellMetrics.buttonDisabled).toBe(false);
  expect(shellMetrics.cardPointerEvents).toBe('auto');
});

test('deferred lesson exposes blank loading cards for the full expected lesson count', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildDeferredLessonMarkup());
  await page.addStyleTag({ content: vocabLessonCssSource });

  const grid = page.locator('[data-ll-expected-card-count="12"]');
  await expect(grid.locator('.ll-vocab-lesson-skeleton-card')).toHaveCount(12);
  await expect(grid.locator('.ll-vocab-lesson-skeleton-card--count-placeholder')).toHaveCount(6);

  const lastPlaceholder = grid.locator('.ll-vocab-lesson-skeleton-card--count-placeholder').last();
  await expect(lastPlaceholder).toBeVisible();
  expect(await lastPlaceholder.locator('.ll-vocab-lesson-skeleton-media').evaluate((node) => node.getBoundingClientRect().height)).toBeGreaterThan(40);
});

test('deferred vocab lesson shell hydrates word-grid markup', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildDeferredLessonMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.llToolsWordGridData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: '',
      editNonce: 'edit-nonce',
      isLoggedIn: true,
      canEdit: true,
      state: {
        wordset_id: 1,
        category_ids: [],
        starred_word_ids: [],
        star_mode: 'normal',
        fast_transitions: false
      }
    };
    window.llToolsVocabLessonData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      action: 'll_tools_get_vocab_lesson_grid',
      i18n: {
        loading: 'Loading lesson words...',
        loaded: 'Lesson words loaded.',
        error: 'Unable to load this lesson right now.',
        retry: 'Retry'
      }
    };
  });

  await page.addScriptTag({ content: wordGridScriptSource });
  await page.addScriptTag({
    content: `
      (function ($) {
        $.ajax = function (options) {
          const deferred = $.Deferred();
          window.__llLessonRequest = { url: options.url, payload: options.data, timeout: options.timeout };
          window.setTimeout(function () {
            deferred.resolve({
              success: true,
              data: {
                html: '<div id="word-grid" class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="1" data-ll-category-id="99"><div class="word-item" data-word-id="11"><div class="ll-word-title-row"><h3 class="word-title"><span class="ll-word-text" data-ll-word-text>Merhaba</span><span class="ll-word-translation" data-ll-word-translation>Hello</span></h3></div><select data-ll-word-input="part_of_speech"><option value="noun" selected>Noun</option></select><select data-ll-word-input="gender"><option value="feminine" selected>Feminine</option></select></div><div class="word-item" data-word-id="12"><div class="ll-word-title-row"><h3 class="word-title"><span class="ll-word-text" data-ll-word-text>Dunya</span><span class="ll-word-translation" data-ll-word-translation>World</span></h3></div><select data-ll-word-input="part_of_speech"><option value="noun" selected>Noun</option></select><select data-ll-word-input="gender"><option value="feminine" selected>Feminine</option></select></div></div>'
              }
            });
          }, 20);
          return deferred.promise();
        };
      })(jQuery);
    `
  });
  await page.addScriptTag({ content: vocabLessonScriptSource });

  await page.waitForFunction(() => {
    const shell = document.querySelector('[data-ll-vocab-lesson-grid-shell]');
    const item = document.querySelector('.word-item[data-word-id="11"]');
    return !!shell && !shell.classList.contains('is-loading') && !!item;
  });

  const request = await page.evaluate(() => window.__llLessonRequest);
  expect(request.payload.lesson_id).toBe(42);
  expect(request.payload.nonce).toBe('test-nonce');
  expect(request.timeout).toBe(20000);

  await expect(page.locator('.word-item[data-word-id="11"]')).toHaveCount(1);
  await expect(page.locator('.ll-vocab-lesson-skeleton-card')).toHaveCount(0);
  await expect(page.locator('[data-ll-vocab-lesson-grid-feedback]')).toHaveAttribute('hidden', 'hidden');
  await expect(page.locator('[data-ll-bulk-pos]')).toHaveValue('noun');
  await expect(page.locator('[data-ll-bulk-gender]')).toHaveValue('feminine');
});

test('staff lesson cards load the detached editor only after an edit action', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <div
      class="word-grid ll-word-grid"
      data-ll-word-grid
      data-ll-word-edit-deferred-grid="1"
      data-ll-wordset-id="17"
      data-ll-category-id="29"
    >
      <article class="word-item" data-word-id="41">
        <button type="button" data-ll-word-edit-deferred>Edit word</button>
        <div class="ll-word-recording-row ll-word-recording-row--editable" data-recording-id="73">
          <button type="button" data-ll-recording-edit-trigger data-recording-id="73">Edit recording</button>
        </div>
        <div data-ll-word-save-status></div>
      </article>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsWordGridData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: '',
      editNonce: 'edit-nonce',
      isLoggedIn: true,
      canEdit: true,
      state: {}
    };
    window.__llDetachedEditorCalls = [];
    window.LLToolsWordEditModal = {
      open(options) {
        window.__llDetachedEditorCalls.push(options);
        return Promise.resolve({ wordId: options.wordId });
      }
    };
  });
  await page.addScriptTag({ content: wordGridScriptSource });

  await page.locator('[data-ll-word-edit-deferred]').click();
  await expect.poll(() => page.evaluate(() => window.__llDetachedEditorCalls.length)).toBe(1);
  expect(await page.evaluate(() => window.__llDetachedEditorCalls[0])).toMatchObject({
    wordId: 41,
    wordsetId: 17,
    categoryId: 29,
    recordingId: 0
  });

  await page.locator('[data-ll-recording-edit-trigger]').click();
  await expect.poll(() => page.evaluate(() => window.__llDetachedEditorCalls.length)).toBe(2);
  expect(await page.evaluate(() => window.__llDetachedEditorCalls[1])).toMatchObject({
    wordId: 41,
    wordsetId: 17,
    categoryId: 29,
    recordingId: 73
  });
});

test('large deferred lesson prepares and appends bounded pages serially', async ({ page }) => {
  await page.goto('about:blank');
  const pagedMarkup = buildDeferredLessonMarkup()
    .replace(
      'data-ll-vocab-lesson-grid-shell',
      'data-ll-vocab-lesson-grid-shell data-ll-grid-paged="1"'
    )
    .replace(
      '<div id="word-grid"',
      '<div class="ll-vocab-lesson-grid-progress" data-ll-vocab-lesson-grid-progress role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-ll-vocab-lesson-grid-progress-bar></span></div><div id="word-grid"'
    );
  await page.setContent(pagedMarkup);
  await page.addStyleTag({ content: vocabLessonCssSource });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.llToolsWordGridData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: '',
      editNonce: '',
      isLoggedIn: false,
      canEdit: false,
      state: {}
    };
    window.llToolsVocabLessonData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      grid: {
        action: 'll_tools_get_vocab_lesson_grid',
        requestTimeoutMs: 9000,
        i18n: {
          loading: 'Loading lesson words...',
          loaded: 'Lesson words loaded.',
          error: 'Unable to load this lesson right now.',
          retry: 'Retry'
        }
      }
    };
  });

  await page.addScriptTag({ content: wordGridScriptSource });
  await page.addScriptTag({
    content: `
      (function ($) {
        const pages = [
          {
            paged: true,
            preparing: true,
            html: '',
            has_more: true,
            next_cursor: '',
            loaded: 0,
            total: 5,
            scanned: 3,
            retry_after_ms: 40
          },
          {
            paged: true,
            preparing: false,
            html: '<div class="word-grid ll-word-grid" data-ll-word-grid><article class="word-item" data-word-id="1">One</article><article class="word-item" data-word-id="2">Two</article></div>',
            has_more: true,
            next_cursor: '2.signature',
            loaded: 2,
            total: 5,
            scanned: 5
          },
          {
            paged: true,
            preparing: false,
            html: '<div class="word-grid ll-word-grid" data-ll-word-grid><article class="word-item" data-word-id="3">Three</article><article class="word-item" data-word-id="4">Four</article></div>',
            has_more: true,
            next_cursor: '4.signature',
            loaded: 4,
            total: 5,
            scanned: 5
          },
          {
            paged: true,
            preparing: false,
            html: '<div class="word-grid ll-word-grid" data-ll-word-grid><article class="word-item" data-word-id="5">Five</article></div>',
            has_more: false,
            next_cursor: '',
            loaded: 5,
            total: 5,
            scanned: 5
          }
        ];
        window.__llPagedRequests = [];
        window.__llPagedActive = 0;
        window.__llPagedMaxActive = 0;
        $.ajax = function (options) {
          const deferred = $.Deferred();
          const response = pages[window.__llPagedRequests.length];
          window.__llPagedRequests.push({
            cursor: options.data.cursor,
            timeout: options.timeout
          });
          window.__llPagedActive += 1;
          window.__llPagedMaxActive = Math.max(window.__llPagedMaxActive, window.__llPagedActive);
          window.setTimeout(function () {
            window.__llPagedActive -= 1;
            deferred.resolve({ success: true, data: response });
          }, 10);
          return deferred.promise();
        };
      })(jQuery);
    `
  });
  await page.addScriptTag({ content: vocabLessonScriptSource });

  await page.waitForFunction(() => {
    const shell = document.querySelector('[data-ll-vocab-lesson-grid-shell]');
    return shell
      && shell.getAttribute('aria-busy') === 'false'
      && document.querySelectorAll('.word-item[data-word-id]').length === 5;
  });

  await expect(page.locator('.word-item[data-word-id]')).toHaveCount(5);
  await expect(page.locator('.word-item[data-word-id="1"]')).toHaveText('One');
  await expect(page.locator('.word-item[data-word-id="5"]')).toHaveText('Five');
  await expect(page.locator('.ll-vocab-lesson-skeleton-card')).toHaveCount(0);
  await expect(page.locator('[data-ll-vocab-lesson-grid-progress]')).toBeHidden();
  await expect(page.locator('[data-ll-vocab-lesson-grid-feedback]')).toBeHidden();

  const requestState = await page.evaluate(() => ({
    requests: window.__llPagedRequests,
    maxActive: window.__llPagedMaxActive
  }));
  expect(requestState.maxActive).toBe(1);
  expect(requestState.requests.map((request) => request.cursor)).toEqual([
    '',
    '',
    '2.signature',
    '4.signature'
  ]);
  expect(requestState.requests.every((request) => request.timeout === 9000)).toBe(true);
});

test('hidden lesson feedback stays invisible when theme overrides hidden styling', async ({ page }) => {
  await page.goto('about:blank');
  await page.addStyleTag({ content: '[hidden] { display: block !important; }' });
  await page.addStyleTag({ content: vocabLessonCssSource });
  await page.setContent(buildDeferredLessonMarkup());

  const feedback = page.locator('[data-ll-vocab-lesson-grid-feedback]');

  await expect(feedback).toHaveJSProperty('hidden', true);
  await expect(feedback).toBeHidden();
  expect(await feedback.evaluate((node) => window.getComputedStyle(node).display)).toBe('none');
});

test('prompt-card loading shell renders full-row skeleton cards', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.goto('about:blank');
  await page.setContent(`
    <div class="ll-vocab-lesson-page">
      <div class="ll-vocab-lesson-content">
        <div class="ll-vocab-lesson-grid-shell is-loading ll-vocab-lesson-grid-shell--prompt-cards" data-ll-vocab-lesson-grid-shell>
          <div id="word-grid" class="word-grid ll-word-grid ll-vocab-prompt-card-grid ll-vocab-prompt-card-grid--skeleton" data-ll-word-grid data-ll-prompt-card-lesson-grid="1">
            <article class="word-item ll-vocab-lesson-skeleton-card ll-vocab-lesson-skeleton-card--prompt-card" aria-hidden="true">
              <div class="ll-vocab-lesson-skeleton-media"></div>
              <div class="ll-vocab-lesson-skeleton-prompt-card-body">
                <div class="ll-vocab-lesson-skeleton-prompt-box">
                  <span class="ll-vocab-lesson-skeleton-dot"></span>
                  <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--prompt"></span>
                  <span class="ll-vocab-lesson-skeleton-recording-button"></span>
                  <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--prompt-secondary"></span>
                </div>
                <div class="ll-vocab-lesson-skeleton-answer-list">
                  <div class="ll-vocab-lesson-skeleton-answer">
                    <span class="ll-vocab-lesson-skeleton-dot"></span>
                    <span class="ll-vocab-lesson-skeleton-recording-button"></span>
                    <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--answer"></span>
                    <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--answer-secondary"></span>
                  </div>
                </div>
              </div>
            </article>
            <article class="word-item ll-vocab-lesson-skeleton-card ll-vocab-lesson-skeleton-card--prompt-card" aria-hidden="true">
              <div class="ll-vocab-lesson-skeleton-media"></div>
              <div class="ll-vocab-lesson-skeleton-prompt-card-body">
                <div class="ll-vocab-lesson-skeleton-prompt-box">
                  <span class="ll-vocab-lesson-skeleton-dot"></span>
                  <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--prompt"></span>
                  <span class="ll-vocab-lesson-skeleton-recording-button"></span>
                  <span class="ll-vocab-lesson-skeleton-line ll-vocab-lesson-skeleton-line--prompt-secondary"></span>
                </div>
              </div>
            </article>
          </div>
        </div>
      </div>
    </div>
  `);
  await page.addStyleTag({ content: vocabLessonCssSource });

  const metrics = await page.evaluate(() => {
    const grid = document.querySelector('#word-grid');
    const cards = Array.from(document.querySelectorAll('.ll-vocab-lesson-skeleton-card--prompt-card'));
    const firstRect = cards[0].getBoundingClientRect();
    const secondRect = cards[1].getBoundingClientRect();
    const firstStyle = window.getComputedStyle(cards[0]);

    return {
      gridColumns: window.getComputedStyle(grid).gridTemplateColumns,
      firstCardColumns: firstStyle.gridTemplateColumns,
      firstWidth: firstRect.width,
      verticalGap: secondRect.top - firstRect.top
    };
  });

  expect(metrics.gridColumns.trim().split(/\s+/)).toHaveLength(1);
  expect(metrics.firstCardColumns.trim().split(/\s+/).length).toBeGreaterThan(1);
  expect(metrics.firstWidth).toBeGreaterThan(800);
  expect(metrics.verticalGap).toBeGreaterThan(80);
});

test('prompt-card lesson images keep their intrinsic aspect ratio', async ({ page }) => {
  const squareImage = `data:image/svg+xml,${encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320" viewBox="0 0 320 320"><rect width="320" height="320" fill="#2563eb"/></svg>'
  )}`;

  await page.setViewportSize({ width: 1280, height: 720 });
  await page.goto('about:blank');
  await page.setContent(`
    <div class="ll-vocab-lesson-page">
      <div class="ll-vocab-lesson-content">
        <div id="word-grid" class="word-grid ll-word-grid ll-vocab-prompt-card-grid" data-ll-word-grid data-ll-prompt-card-lesson-grid="1">
          <article class="ll-vocab-prompt-card">
            <div class="ll-vocab-prompt-card__image">
              <img src="${squareImage}" alt="Square prompt image" loading="lazy" decoding="async" />
            </div>
            <div class="ll-vocab-prompt-card__body">
              <div class="ll-vocab-prompt-card__prompt">Is this square?</div>
            </div>
          </article>
        </div>
      </div>
    </div>
  `);
  await page.addStyleTag({ content: vocabLessonCssSource });
  await page.locator('.ll-vocab-prompt-card__image img').evaluate((img) => {
    if (img.complete && img.naturalWidth > 0) {
      return;
    }

    return new Promise((resolve, reject) => {
      img.addEventListener('load', resolve, { once: true });
      img.addEventListener('error', reject, { once: true });
    });
  });

  const metrics = await page.evaluate(() => {
    const wrapper = document.querySelector('.ll-vocab-prompt-card__image');
    const image = wrapper.querySelector('img');
    const wrapperRect = wrapper.getBoundingClientRect();
    const imageRect = image.getBoundingClientRect();
    const imageStyle = window.getComputedStyle(image);

    return {
      wrapperRatio: wrapperRect.width / wrapperRect.height,
      imageRatio: imageRect.width / imageRect.height,
      imageWidth: imageRect.width,
      objectFit: imageStyle.objectFit
    };
  });

  expect(Math.abs(metrics.wrapperRatio - 1)).toBeLessThan(0.02);
  expect(Math.abs(metrics.imageRatio - 1)).toBeLessThan(0.02);
  expect(metrics.imageWidth).toBeGreaterThan(200);
  expect(metrics.objectFit).toBe('contain');
});
