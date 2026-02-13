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
        <button type="button" class="ll-study-btn primary" data-ll-study-start-next disabled>Start Next</button>
        <button type="button" class="ll-study-btn primary" data-ll-study-start data-mode="practice">Practice</button>
        <button type="button" class="ll-study-btn" data-ll-study-start data-mode="learning">Learn</button>
        <button type="button" class="ll-study-btn ll-study-btn--gender ll-study-btn--hidden" data-ll-study-start data-ll-study-gender data-mode="gender" aria-hidden="true">Gender</button>
        <button type="button" class="ll-study-btn" data-ll-study-start data-mode="listening">Listen</button>
        <button type="button" class="ll-study-btn" data-ll-study-check-start>Check</button>
      </div>
      <div data-ll-study-next-text></div>

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
          <div data-ll-study-check-actions></div>
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
    window.__llGoalSaveRequests = [];

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
      if (action === 'll_user_study_save_goals') {
        window.__llGoalSaveRequests.push(request || {});
        let parsedGoals = {};
        try {
          parsedGoals = typeof request.goals === 'string' ? JSON.parse(request.goals || '{}') : (request.goals || {});
        } catch (_e) {
          parsedGoals = {};
        }
        deferred.resolve({ success: true, data: { goals: parsedGoals } });
        return deferred.promise();
      }
      if (action === 'll_user_study_recommendation') {
        deferred.resolve({ success: true, data: { next_activity: null } });
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

test('study panel normalizes gender launch mode before initializing flashcards', async ({ page }) => {
  await mountStudyPanel(page, buildPayload());

  await page.evaluate(() => {
    window.__llInitCalls = [];
    window.initFlashcardWidget = function (catNames, mode) {
      window.__llInitCalls.push({
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        mode: String(mode || '')
      });
      return Promise.resolve();
    };

    const button = document.querySelector('[data-ll-study-gender]');
    if (!button) {
      return;
    }

    button.setAttribute('data-mode', ' Gender ');
    if (window.jQuery) {
      window.jQuery(button).removeData('mode');
    }
  });

  await page.locator('[data-ll-study-gender]').click();
  await page.waitForTimeout(100);

  const launch = await page.evaluate(() => {
    const calls = Array.isArray(window.__llInitCalls) ? window.__llInitCalls : [];
    return calls.length ? calls[calls.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('gender');
});

test('study panel keeps gender mode hidden when wordset gender is disabled', async ({ page }) => {
  await mountStudyPanel(page, buildPayload({
    gender: {
      enabled: false,
      options: ['masculine', 'feminine'],
      min_count: 2
    }
  }));

  const genderButton = page.locator('[data-ll-study-gender]');
  await expect(genderButton).toHaveCount(1);
  await expect(genderButton).toHaveAttribute('aria-hidden', 'true');
  await expect.poll(async () => {
    return await genderButton.evaluate((el) => el.classList.contains('ll-study-btn--hidden'));
  }).toBe(true);

  await page.locator('[data-ll-study-categories] input[type="checkbox"][value="11"]').uncheck();
  await expect(genderButton).toHaveAttribute('aria-hidden', 'true');
  await expect.poll(async () => {
    return await genderButton.evaluate((el) => el.classList.contains('ll-study-btn--hidden'));
  }).toBe(true);
});

test('wordset switch keeps gender launch aligned with checked UI categories even if save responses arrive out of order', async ({ page }) => {
  const payload = buildPayload({
    wordsets: [
      { id: 19, name: 'English', slug: 'english' },
      { id: 20, name: 'Hebrew', slug: 'hebrew' }
    ],
    state: {
      wordset_id: 19,
      category_ids: [11],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
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
      }
    ],
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
      ]
    },
    gender: {
      enabled: false,
      options: [],
      min_count: 2
    }
  });

  await mountStudyPanel(page, payload);

  await page.evaluate(() => {
    window.__llInitCalls = [];
    window.initFlashcardWidget = function (catNames, mode) {
      window.__llInitCalls.push({
        mode: String(mode || ''),
        catNames: Array.isArray(catNames) ? catNames.slice() : []
      });
      return Promise.resolve();
    };

    const delayedBootstrapData = {
      wordsets: [
        { id: 19, name: 'English', slug: 'english' },
        { id: 20, name: 'Hebrew', slug: 'hebrew' }
      ],
      categories: [
        {
          id: 201,
          name: 'Hebrew Gender',
          slug: 'hebrew-gender',
          translation: 'Hebrew Gender',
          option_type: 'image',
          prompt_type: 'audio',
          word_count: 2,
          gender_supported: true
        }
      ],
      gender: {
        enabled: true,
        options: ['masculine', 'feminine'],
        min_count: 2
      },
      state: {
        wordset_id: 20,
        category_ids: [201],
        starred_word_ids: [],
        star_mode: 'normal',
        fast_transitions: false
      },
      words_by_category: {
        201: [
          { id: 2011, title: 'Hebrew 1', translation: 'Hebrew 1', label: 'Hebrew 1', image: '', audio: '', audio_files: [] },
          { id: 2012, title: 'Hebrew 2', translation: 'Hebrew 2', label: 'Hebrew 2', image: '', audio: '', audio_files: [] }
        ]
      },
      goals: {
        enabled_modes: ['learning', 'practice', 'listening', 'gender', 'self-check'],
        ignored_category_ids: [],
        preferred_wordset_ids: [19, 20],
        placement_known_category_ids: [],
        daily_new_word_target: 2
      },
      category_progress: {},
      next_activity: null
    };

    const $ = window.jQuery;
    const originalPost = $.post;
    $.post = function (_url, request) {
      const deferred = $.Deferred();
      const action = request && request.action ? String(request.action) : '';

      if (action === 'll_user_study_bootstrap' && String(request.wordset_id || '') === '20') {
        setTimeout(() => {
          deferred.resolve({ success: true, data: delayedBootstrapData });
        }, 20);
        return deferred.promise();
      }

      // Simulate a stale save response arriving after bootstrap and carrying empty categories.
      if (action === 'll_user_study_save') {
        const staleState = Object.assign({}, request || {}, { category_ids: [] });
        setTimeout(() => {
          deferred.resolve({ success: true, data: { state: staleState } });
        }, 80);
        return deferred.promise();
      }

      return originalPost.apply(this, arguments);
    };
  });

  await page.selectOption('[data-ll-study-wordset]', '20');
  await page.waitForTimeout(250);

  const genderButton = page.locator('[data-ll-study-gender]');
  await expect(genderButton).toHaveAttribute('aria-hidden', 'false');
  await page.locator('[data-ll-study-start][data-mode="gender"]').click();
  await page.waitForTimeout(120);

  const launch = await page.evaluate(() => {
    const calls = Array.isArray(window.__llInitCalls) ? window.__llInitCalls : [];
    return calls.length ? calls[calls.length - 1] : null;
  });

  expect(launch).not.toBeNull();
  expect(launch.mode).toBe('gender');
  expect(launch.catNames).toContain('Hebrew Gender');
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

  await page.locator('[data-ll-check-choice="idk"]').click();
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

test('self-check confident correct answers mark category known and persist goals', async ({ page }) => {
  const payload = buildPayload({
    state: {
      wordset_id: 19,
      category_ids: [11],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    words_by_category: {
      11: [
        { id: 111, title: 'Word 1', translation: 'Word 1', label: 'Word 1', image: '', audio: '', audio_files: [] },
        { id: 112, title: 'Word 2', translation: 'Word 2', label: 'Word 2', image: '', audio: '', audio_files: [] }
      ],
      22: [
        { id: 221, title: 'Word 4', translation: 'Word 4', label: 'Word 4', image: '', audio: '', audio_files: [] }
      ]
    }
  });

  await mountStudyPanel(page, payload);
  await page.locator('[data-ll-study-check-start]').click();

  await page.locator('[data-ll-check-choice="know"]').click();
  await page.locator('[data-ll-check-choice="right"]').click();
  await page.locator('[data-ll-check-choice="think"]').click();
  await page.locator('[data-ll-check-choice="right"]').click();

  await expect(page.locator('[data-ll-study-check-complete]')).toBeVisible();
  await page.locator('[data-ll-study-check-apply]').click();
  await page.waitForTimeout(400);

  const goalSaveRequest = await page.evaluate(() => {
    const list = Array.isArray(window.__llGoalSaveRequests) ? window.__llGoalSaveRequests : [];
    return list.length ? list[list.length - 1] : null;
  });
  expect(goalSaveRequest).not.toBeNull();
  expect(goalSaveRequest.action).toBe('ll_user_study_save_goals');

  const savedGoals = goalSaveRequest && goalSaveRequest.goals ? JSON.parse(goalSaveRequest.goals) : {};
  const knownCategoryIds = Array.isArray(savedGoals.placement_known_category_ids)
    ? savedGoals.placement_known_category_ids.map((id) => Number(id)).sort((a, b) => a - b)
    : [];
  expect(knownCategoryIds).toEqual([11]);
});

test('self-check switches to result choices immediately and keeps them disabled until isolation audio ends', async ({ page }) => {
  const payload = buildPayload({
    state: {
      wordset_id: 19,
      category_ids: [11],
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
          audio_files: [
            { recording_type: 'isolation', url: 'https://example.test/isolation-a.mp3' }
          ]
        }
      ],
      22: []
    }
  });

  await mountStudyPanel(page, payload);

  await page.evaluate(() => {
    window.__llAudioInstances = [];
    window.__llEmitAudioEnded = function (index = 0) {
      const list = Array.isArray(window.__llAudioInstances) ? window.__llAudioInstances : [];
      const audio = list[index] || null;
      if (!audio) {
        return false;
      }
      audio.paused = true;
      const listeners = Array.isArray(audio.__listeners && audio.__listeners.ended) ? audio.__listeners.ended.slice() : [];
      listeners.forEach((fn) => {
        try {
          fn();
        } catch (_err) {}
      });
      return true;
    };

    window.Audio = function (url) {
      this.url = url;
      this.paused = true;
      this.__listeners = {};
      this.__playCalls = 0;
      window.__llAudioInstances.push(this);
    };

    window.Audio.prototype.addEventListener = function (type, handler) {
      const key = String(type || '');
      if (!this.__listeners[key]) {
        this.__listeners[key] = [];
      }
      this.__listeners[key].push(handler);
    };

    window.Audio.prototype.removeEventListener = function (type, handler) {
      const key = String(type || '');
      const list = Array.isArray(this.__listeners[key]) ? this.__listeners[key] : [];
      this.__listeners[key] = list.filter((fn) => fn !== handler);
    };

    window.Audio.prototype.play = function () {
      this.paused = false;
      this.__playCalls += 1;
      return Promise.resolve();
    };

    window.Audio.prototype.pause = function () {
      this.paused = true;
    };
  });

  await page.locator('[data-ll-study-check-start]').click();
  await page.locator('[data-ll-check-choice="know"]').click();

  await expect(page.locator('[data-ll-check-choice="wrong"]')).toHaveCount(1);
  await expect(page.locator('[data-ll-check-choice="close"]')).toHaveCount(1);
  await expect(page.locator('[data-ll-check-choice="right"]')).toHaveCount(1);

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.from(document.querySelectorAll('[data-ll-study-check-actions] button')).map((btn) => btn.disabled);
    });
  }).toEqual([true, true, true]);

  const audioSnapshot = await page.evaluate(() => {
    const list = Array.isArray(window.__llAudioInstances) ? window.__llAudioInstances : [];
    const first = list[0] || null;
    return {
      count: list.length,
      playCalls: first ? Number(first.__playCalls || 0) : 0
    };
  });
  expect(audioSnapshot.count).toBeGreaterThanOrEqual(1);
  expect(audioSnapshot.playCalls).toBe(1);

  await page.evaluate(() => {
    window.__llEmitAudioEnded(0);
  });

  await expect.poll(async () => {
    return page.evaluate(() => {
      return Array.from(document.querySelectorAll('[data-ll-study-check-actions] button')).map((btn) => btn.disabled);
    });
  }).toEqual([false, false, false]);

  await page.locator('[data-ll-check-choice="right"]').click();
  await expect(page.locator('[data-ll-study-check-complete]')).toBeVisible();
});

test('start next chunk temporarily overrides starred-only to keep session words available', async ({ page }) => {
  const payload = buildPayload({
    state: {
      wordset_id: 19,
      category_ids: [11, 22],
      starred_word_ids: [101],
      star_mode: 'only',
      fast_transitions: false
    },
    next_activity: {
      type: 'review_chunk',
      reason_code: 'review_chunk_balanced',
      mode: 'practice',
      category_ids: [11, 22],
      session_word_ids: [202],
      details: { chunk_size: 1 }
    }
  });

  await mountStudyPanel(page, payload);

  await page.evaluate(() => {
    window.__llInitCalls = [];
    window.initFlashcardWidget = function (catNames, mode) {
      const data = window.llToolsFlashcardsData || {};
      window.__llInitCalls.push({
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        mode,
        sessionStarModeOverride: data.sessionStarModeOverride || data.session_star_mode_override || null,
        firstCategoryDataIds: Array.isArray(data.firstCategoryData) ? data.firstCategoryData.map((w) => Number(w && w.id)) : [],
        sessionWordIds: Array.isArray(data.sessionWordIds) ? data.sessionWordIds.map((id) => Number(id)) : []
      });
      return Promise.resolve();
    };
  });

  await page.locator('[data-ll-study-start-next]').click();
  await page.waitForTimeout(100);

  const call = await page.evaluate(() => {
    const list = Array.isArray(window.__llInitCalls) ? window.__llInitCalls : [];
    return list.length ? list[list.length - 1] : null;
  });

  expect(call).not.toBeNull();
  expect(call.mode).toBe('practice');
  expect(call.sessionStarModeOverride).toBe('normal');
  expect(call.sessionWordIds).toEqual([202]);
  expect(call.firstCategoryDataIds).toEqual([202]);
});

test('flashcard close event restores dashboard scroll lock when check panel closed while popup is active', async ({ page }) => {
  await mountStudyPanel(page, buildPayload());

  await page.locator('[data-ll-study-categories] input[type="checkbox"][value="22"]').uncheck();
  await page.locator('[data-ll-study-check-start]').click();

  await expect.poll(async () => {
    return page.evaluate(() => ({
      bodyOverflow: document.body.style.overflow,
      htmlOverflow: document.documentElement.style.overflow
    }));
  }).toEqual({
    bodyOverflow: 'hidden',
    htmlOverflow: 'hidden'
  });

  await page.evaluate(() => {
    let popup = document.getElementById('ll-tools-flashcard-popup');
    if (!popup) {
      popup = document.createElement('div');
      popup.id = 'll-tools-flashcard-popup';
      document.body.appendChild(popup);
    }
    popup.style.display = 'block';
    document.body.classList.add('ll-tools-flashcard-open');
    window.jQuery(document).trigger('lltools:flashcard-opened');
  });

  await expect.poll(async () => {
    return page.evaluate(() => ({
      panelActive: document.querySelector('[data-ll-study-check]').classList.contains('is-active'),
      bodyOverflow: document.body.style.overflow,
      htmlOverflow: document.documentElement.style.overflow
    }));
  }).toEqual({
    panelActive: false,
    bodyOverflow: 'hidden',
    htmlOverflow: 'hidden'
  });

  await page.evaluate(() => {
    const popup = document.getElementById('ll-tools-flashcard-popup');
    if (popup) {
      popup.style.display = 'none';
    }
    document.body.classList.remove('ll-tools-flashcard-open');
    window.jQuery(document).trigger('lltools:flashcard-closed');
  });

  await expect.poll(async () => {
    return page.evaluate(() => ({
      bodyOverflow: document.body.style.overflow,
      htmlOverflow: document.documentElement.style.overflow,
      bodyClass: document.body.classList.contains('ll-study-check-open'),
      htmlClass: document.documentElement.classList.contains('ll-study-check-open')
    }));
  }).toEqual({
    bodyOverflow: '',
    htmlOverflow: '',
    bodyClass: false,
    htmlClass: false
  });
});
