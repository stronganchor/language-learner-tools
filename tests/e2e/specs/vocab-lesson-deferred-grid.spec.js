const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);
const vocabLessonScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-page.js'),
  'utf8'
);

function buildDeferredLessonMarkup() {
  return `
    <div class="ll-vocab-lesson-page">
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
          <div id="word-grid" class="word-grid ll-word-grid" data-ll-word-grid>
            <article class="word-item ll-vocab-lesson-skeleton-card" aria-hidden="true"></article>
            <article class="word-item ll-vocab-lesson-skeleton-card" aria-hidden="true"></article>
          </div>
          <div class="ll-vocab-lesson-grid-feedback" data-ll-vocab-lesson-grid-feedback hidden></div>
        </div>
      </div>
    </div>
  `;
}

test('deferred vocab lesson shell hydrates word-grid markup', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildDeferredLessonMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.llToolsWordGridData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: '',
      isLoggedIn: false,
      canEdit: false,
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
        $.post = function (url, payload) {
          const deferred = $.Deferred();
          window.__llLessonRequest = { url: url, payload: payload };
          window.setTimeout(function () {
            deferred.resolve({
              success: true,
              data: {
                html: '<div id="word-grid" class="word-grid ll-word-grid" data-ll-word-grid><div class="word-item" data-word-id="11"><div class="ll-word-title-row"><h3 class="word-title"><span class="ll-word-text" data-ll-word-text>Merhaba</span><span class="ll-word-translation" data-ll-word-translation>Hello</span></h3></div></div></div>'
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

  await expect(page.locator('.word-item[data-word-id="11"]')).toHaveCount(1);
  await expect(page.locator('.ll-vocab-lesson-skeleton-card')).toHaveCount(0);
  await expect(page.locator('[data-ll-vocab-lesson-grid-feedback]')).toHaveAttribute('hidden', 'hidden');
});
