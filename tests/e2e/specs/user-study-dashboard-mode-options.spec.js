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
