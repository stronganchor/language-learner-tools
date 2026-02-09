const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const dashboardScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/user-study-dashboard.js'),
  'utf8'
);

function buildStudyPanelMarkup() {
  return `
    <div class="ll-user-study-dashboard" data-ll-study-root>
      <div class="ll-study-actions">
        <button type="button" class="ll-study-btn primary" data-ll-study-start data-mode="practice">Practice</button>
        <button type="button" class="ll-study-btn" data-ll-study-start data-mode="learning">Learn</button>
        <button type="button" class="ll-study-btn ll-study-btn--gender ll-study-btn--hidden" data-ll-study-start data-ll-study-gender data-mode="gender" aria-hidden="true">Gender</button>
        <button type="button" class="ll-study-btn" data-ll-study-start data-mode="listening">Listen</button>
        <button type="button" class="ll-study-btn" data-ll-study-check-start>Check</button>
      </div>

      <select data-ll-study-wordset></select>
      <div data-ll-study-categories></div>
      <div data-ll-study-words></div>
      <p data-ll-cat-empty></p>
      <p data-ll-words-empty></p>
      <span data-ll-star-count></span>

      <div data-ll-star-mode>
        <button type="button" class="ll-study-btn" data-mode="normal">Normal</button>
        <button type="button" class="ll-study-btn" data-mode="weighted">Weighted</button>
        <button type="button" class="ll-study-btn" data-mode="only">Only</button>
      </div>
      <div data-ll-transition-speed>
        <button type="button" class="ll-study-btn" data-speed="slow">Slow</button>
        <button type="button" class="ll-study-btn" data-speed="fast">Fast</button>
      </div>

      <div data-ll-study-check aria-hidden="true">
        <div class="ll-study-check-shell">
          <button type="button" data-ll-study-check-exit>Close</button>
          <div data-ll-study-check-category></div>
          <div data-ll-study-check-progress></div>
          <div data-ll-study-check-card>
            <div data-ll-study-check-card-inner>
              <div data-ll-study-check-prompt></div>
              <div data-ll-study-check-answer></div>
            </div>
          </div>
          <button type="button" data-ll-study-check-flip>Flip</button>
          <div data-ll-study-check-actions>
            <button type="button" data-ll-study-check-know>Know</button>
            <button type="button" data-ll-study-check-unknown>Unknown</button>
          </div>
          <div data-ll-study-check-complete style="display:none;">
            <p data-ll-study-check-summary></p>
            <button type="button" data-ll-study-check-apply>Set stars</button>
            <button type="button" data-ll-study-check-restart>Restart</button>
          </div>
        </div>
      </div>
    </div>
  `;
}

function buildPayload(overrides = {}) {
  const base = {
    wordsets: [
      { id: 19, name: 'Test Set', slug: 'test-set' }
    ],
    categories: [
      {
        id: 11,
        name: 'Category A',
        slug: 'category-a',
        translation: 'Category A',
        option_type: 'image',
        prompt_type: 'audio',
        word_count: 1,
        gender_supported: false
      },
      {
        id: 22,
        name: 'Category B',
        slug: 'category-b',
        translation: 'Category B',
        option_type: 'image',
        prompt_type: 'audio',
        word_count: 1,
        gender_supported: true
      }
    ],
    gender: {
      enabled: true,
      options: ['masculine', 'feminine'],
      min_count: 2
    },
    state: {
      wordset_id: 19,
      category_ids: [11, 22],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    words_by_category: {
      11: [
        {
          id: 101,
          title: 'Word A',
          translation: 'Word A',
          label: 'Word A',
          image: '',
          audio: '',
          audio_files: []
        }
      ],
      22: [
        {
          id: 202,
          title: 'Word B',
          translation: 'Word B',
          label: 'Word B',
          image: '',
          audio: '',
          audio_files: []
        }
      ]
    }
  };

  return Object.assign({}, base, overrides);
}

async function mountStudyPanel(page, payload) {
  await page.goto('about:blank');
  await page.setContent(buildStudyPanelMarkup());
  await page.addScriptTag({ content: jquerySource });

  const bootstrap = {
    ajaxUrl: '/fake-admin-ajax.php',
    nonce: 'test-nonce',
    payload,
    i18n: {
      noWords: 'No words',
      noCategories: 'No categories',
      recordingQuestion: 'Question',
      recordingIsolation: 'Isolation',
      recordingIntroduction: 'Introduction'
    }
  };

  await page.evaluate((data) => {
    window.llToolsStudyData = data;
    window.alert = function () {};
    window.__llSaveRequests = [];

    const $ = window.jQuery;
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_user_study_bootstrap') {
        deferred.resolve({ success: true, data: data.payload });
        return deferred.promise();
      }
      if (action === 'll_user_study_fetch_words') {
        deferred.resolve({
          success: true,
          data: { words_by_category: data.payload.words_by_category || {} }
        });
        return deferred.promise();
      }
      if (action === 'll_user_study_save') {
        window.__llSaveRequests.push(request || {});
        deferred.resolve({ success: true, data: { state: request || {} } });
        return deferred.promise();
      }

      deferred.resolve({ success: true, data: {} });
      return deferred.promise();
    };
  }, bootstrap);

  await page.addScriptTag({ content: dashboardScriptSource });
}

test('study panel mode options keep base modes and show gender when any selected category supports it', async ({ page }) => {
  await mountStudyPanel(page, buildPayload());

  await expect(page.locator('[data-ll-study-start][data-mode="practice"]')).toHaveCount(1);
  await expect(page.locator('[data-ll-study-start][data-mode="learning"]')).toHaveCount(1);
  await expect(page.locator('[data-ll-study-start][data-mode="listening"]')).toHaveCount(1);

  const genderButton = page.locator('[data-ll-study-gender]');
  await expect(genderButton).toHaveCount(1);
  await expect(genderButton).toHaveAttribute('aria-hidden', 'false');
  await expect.poll(async () => {
    return await genderButton.evaluate((el) => el.classList.contains('ll-study-btn--hidden'));
  }).toBe(false);

  await page.locator('[data-ll-study-categories] input[type="checkbox"][value="22"]').uncheck();

  await expect(genderButton).toHaveAttribute('aria-hidden', 'true');
  await expect.poll(async () => {
    return await genderButton.evaluate((el) => el.classList.contains('ll-study-btn--hidden'));
  }).toBe(true);
});

test('self-check apply only updates stars inside the checked category scope', async ({ page }) => {
  const payload = buildPayload({
    state: {
      wordset_id: 19,
      category_ids: [11, 22],
      starred_word_ids: [202],
      star_mode: 'normal',
      fast_transitions: false
    }
  });

  await mountStudyPanel(page, payload);

  await page.locator('[data-ll-study-categories] input[type="checkbox"][value="22"]').uncheck();
  await page.locator('[data-ll-study-check-start]').click();

  await expect.poll(async () => {
    return page.evaluate(() => ({
      bodyClass: document.body.classList.contains('ll-study-check-open'),
      htmlClass: document.documentElement.classList.contains('ll-study-check-open'),
      bodyOverflow: document.body.style.overflow,
      htmlOverflow: document.documentElement.style.overflow
    }));
  }).toEqual({
    bodyClass: true,
    htmlClass: true,
    bodyOverflow: 'hidden',
    htmlOverflow: 'hidden'
  });

  await page.locator('[data-ll-study-check-unknown]').click();
  await expect(page.locator('[data-ll-study-check-complete]')).toBeVisible();
  await page.locator('[data-ll-study-check-apply]').click();
  await page.waitForTimeout(500);

  await expect.poll(async () => {
    return page.evaluate(() => ({
      bodyClass: document.body.classList.contains('ll-study-check-open'),
      htmlClass: document.documentElement.classList.contains('ll-study-check-open')
    }));
  }).toEqual({
    bodyClass: false,
    htmlClass: false
  });

  const saveRequest = await page.evaluate(() => {
    const list = Array.isArray(window.__llSaveRequests) ? window.__llSaveRequests : [];
    return list.length ? list[list.length - 1] : null;
  });

  expect(saveRequest).not.toBeNull();
  const starred = Array.isArray(saveRequest.starred_word_ids) ? saveRequest.starred_word_ids.map((id) => Number(id)).sort((a, b) => a - b) : [];
  expect(starred).toEqual([101, 202]);
});
