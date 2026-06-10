const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const offlineAppSource = fs.readFileSync(
  path.resolve(__dirname, '../../../offline-app/offline-app.js'),
  'utf8'
);

function buildOfflineShellMarkup() {
  return `
    <main class="ll-offline-app">
      <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container">
        <section id="ll-offline-study-view" class="ll-offline-app-view" data-ll-offline-view="study">
          <section id="ll-offline-launcher" class="ll-offline-launcher" aria-label="Offline quiz launcher">
            <div class="ll-wordset-next-shell" data-ll-offline-next-shell>
              <button type="button" class="ll-wordset-next-card is-disabled" id="ll-offline-next-card" data-ll-offline-next aria-live="polite" aria-disabled="true" disabled>
                <span class="ll-wordset-next-card__main">
                  <span class="ll-wordset-next-card__icon" id="ll-offline-next-icon" data-ll-offline-next-icon aria-hidden="true"></span>
                  <span class="ll-wordset-next-card__preview" id="ll-offline-next-preview" data-ll-offline-next-preview aria-hidden="true"></span>
                  <span class="ll-wordset-next-card__text" id="ll-offline-next-text" data-ll-offline-next-text>Loading next recommendation...</span>
                </span>
              </button>
              <span class="ll-wordset-next-card__meta">
                <span class="ll-wordset-queue-item__count ll-wordset-next-card__count" id="ll-offline-next-count" data-ll-offline-next-count hidden></span>
              </span>
            </div>
            <div class="ll-wordset-grid-tools">
              <input id="ll-offline-category-search" type="search" data-ll-offline-category-search />
              <div class="ll-wordset-main-sort" data-ll-offline-sort-root>
                <button type="button" data-ll-offline-sort-toggle aria-expanded="false" aria-controls="ll-offline-sort-menu">Sort</button>
                <div id="ll-offline-sort-menu" data-ll-offline-sort-menu role="menu" hidden>
                  <button type="button" data-ll-offline-sort-option="default" role="menuitemradio" aria-checked="true">Default</button>
                  <button type="button" data-ll-offline-sort-option="alpha-asc" role="menuitemradio" aria-checked="false">A-Z</button>
                  <button type="button" data-ll-offline-sort-option="alpha-desc" role="menuitemradio" aria-checked="false">Z-A</button>
                  <button type="button" data-ll-offline-sort-option="progress-desc" role="menuitemradio" aria-checked="false">More learned</button>
                </div>
              </div>
              <button id="ll-offline-select-all" type="button">Select All</button>
            </div>
            <div id="ll-offline-category-grid" class="ll-wordset-grid" role="list" aria-live="polite"></div>
            <div id="ll-offline-selection-bar" class="ll-wordset-selection-bar" hidden>
              <span id="ll-offline-selection-text" class="ll-wordset-selection-bar__text">Select categories to study together</span>
              <button id="ll-offline-launch-learning-selected" data-ll-offline-launch-selected data-mode="learning" type="button" disabled>Learn</button>
              <button id="ll-offline-launch-practice-selected" data-ll-offline-launch-selected data-mode="practice" type="button" disabled>Practice</button>
              <button id="ll-offline-launch-listening-selected" data-ll-offline-launch-selected data-mode="listening" type="button" hidden disabled>Listen</button>
              <button id="ll-offline-launch-gender-selected" data-ll-offline-launch-selected data-mode="gender" type="button" hidden disabled>Gender</button>
              <button id="ll-offline-launch-self-check-selected" data-ll-offline-launch-selected data-mode="self-check" type="button" hidden disabled>Self check</button>
              <button id="ll-offline-selection-clear" type="button">Clear</button>
            </div>
            <div id="ll-offline-category-empty" hidden>No categories are available in this offline app.</div>
          </section>

          <section id="ll-offline-sync-panel" hidden>
            <div id="ll-offline-sync-status"></div>
            <div id="ll-offline-sync-meta"></div>
            <div id="ll-offline-sync-feedback" hidden></div>
            <button id="ll-offline-sync-connect" type="button"></button>
            <button id="ll-offline-sync-now" type="button" hidden></button>
            <button id="ll-offline-sync-disconnect" type="button" hidden></button>
            <div id="ll-offline-sync-sheet" hidden>
              <form id="ll-offline-sync-form">
                <h2 id="ll-offline-sync-sheet-title"></h2>
                <label for="ll-offline-sync-identifier">Username or email</label>
                <input id="ll-offline-sync-identifier" type="text" />
                <label for="ll-offline-sync-password">Password</label>
                <input id="ll-offline-sync-password" type="password" />
                <button id="ll-offline-sync-password-toggle" type="button"></button>
                <button id="ll-offline-sync-submit" type="submit"></button>
                <button id="ll-offline-sync-cancel" type="button"></button>
                <div id="ll-offline-sync-sheet-feedback" hidden></div>
              </form>
            </div>
          </section>
        </section>

        <div id="ll-tools-flashcard-popup" style="display:none;">
          <div id="ll-tools-flashcard-quiz-popup" style="display:none;">
            <button id="ll-tools-close-flashcard" type="button">Close</button>
            <div id="ll-tools-flashcard-header" style="display:none;">
              <div id="ll-tools-category-stack">
                <span id="ll-tools-category-display"></span>
                <button id="ll-tools-repeat-flashcard" class="play-mode" type="button"></button>
              </div>
            </div>
            <div id="ll-tools-flashcard-content">
              <div id="ll-tools-prompt" style="display:none;"></div>
              <div id="ll-tools-flashcard"></div>
              <audio controls class="hidden"></audio>
            </div>
            <div id="quiz-results" style="display:none;"></div>
          </div>
        </div>
      </div>
    </main>
  `;
}

async function mountOfflineLauncher(page, options = {}) {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const errors = [];
  page.on('pageerror', (error) => {
    errors.push(error.message);
  });
  await page.setContent(buildOfflineShellMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate((mountOptions) => {
    window.__offlineLaunches = [];
    window.__offlineSyncRequests = [];
    window.__offlineSyncCalls = [];
    window.initFlashcardWidget = function initFlashcardWidget(catNames, mode) {
      const flashData = window.llToolsFlashcardsData || {};
      window.__offlineLaunches.push({
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        mode,
        plan: flashData.lastLaunchPlan ? Object.assign({}, flashData.lastLaunchPlan) : null
      });
      return Promise.resolve();
    };

    const syncState = {
      connected: false,
      pending: 0,
      device_id: 'fixture-device',
      profile_id: 'fixture-profile',
      auth: {}
    };

    window.LLFlashcards = {
      ProgressTracker: {
        getSyncState() {
          return JSON.parse(JSON.stringify(syncState));
        },
        setOfflineSyncSession(session) {
          syncState.connected = true;
          syncState.auth = {
            token: session.auth_token || '',
            expires_at: session.expires_at || '',
            user: session.user || null
          };
        },
        clearOfflineSyncSession() {
          syncState.connected = false;
          syncState.pending = 0;
          syncState.auth = {};
        },
        syncFromServer(syncOptions = {}) {
          window.__offlineSyncCalls.push({
            wordIds: Array.isArray(syncOptions.wordIds) ? syncOptions.wordIds.slice() : []
          });
          if (mountOptions.syncFailure === 'failed-result') {
            syncState.pending = 2;
            syncState.auth.last_error = 'Server rejected one local progress event.';
            return Promise.resolve({
              failed: true,
              error: 'Server rejected one local progress event.',
              data: {}
            });
          }
          if (mountOptions.syncFailure === 'rejected-promise') {
            syncState.pending = 2;
            syncState.auth.last_error = 'Temporary server sync failure.';
            return Promise.reject(new Error('Temporary server sync failure.'));
          }
          syncState.pending = 0;
          delete syncState.auth.last_error;
          syncState.auth.last_sync_at = '2026-05-29T00:00:00Z';
          return Promise.resolve({
            failed: false,
            data: {
              state: {
                wordset_id: 777,
                category_ids: [11],
                starred_word_ids: [],
                star_mode: 'normal',
                fast_transitions: false
              }
            }
          });
        }
      }
    };

    window.fetch = async (_url, requestOptions = {}) => {
      const body = requestOptions.body;
      const action = body && typeof body.get === 'function' ? String(body.get('action') || '') : '';
      const payload = {};
      if (body && typeof body.forEach === 'function') {
        body.forEach((value, key) => {
          payload[key] = String(value);
        });
      }
      window.__offlineSyncRequests.push({ action, payload });

      if (action === 'fixture_login' && mountOptions.loginFailure) {
        return {
          ok: false,
          async text() {
            return JSON.stringify({
              success: false,
              data: {
                message: 'Invalid offline login.'
              }
            });
          }
        };
      }

      const data = action === 'fixture_login'
        ? {
            auth_token: 'fixture-token',
            expires_at: '2099-01-01T00:00:00Z',
            user: {
              id: 42,
              display_name: 'Offline Learner',
              login: 'offline-learner'
            }
          }
        : {};

      return {
        ok: true,
        async text() {
          return JSON.stringify({ success: true, data });
        }
      };
    };

    window.llToolsOfflineData = {
      messages: {
        offlineSelectionWords: '%d words',
        offlineModePractice: 'Practice',
        offlineModeLearning: 'Learn',
        offlineSelectCategory: 'Select category: %s',
        offlineModeCategoryLabel: '%1$s: %2$s'
      },
      app: {
        title: 'Offline Starter',
        sync: mountOptions.enableSync
          ? {
              enabled: true,
              ajaxUrl: '/wp-admin/admin-ajax.php',
              loginAction: 'fixture_login',
              logoutAction: 'fixture_logout',
              messages: {
                connectedAsLabel: 'Connected as %s',
                syncIdleLabel: 'All caught up',
                syncInProgressLabel: 'Syncing...',
                syncSignedOutLabel: 'Disconnected locally'
              }
            }
          : {},
        launcher: {
          categories: [
            {
              id: 11,
              name: 'Animals',
              translation: 'Animals',
              word_count: 5,
              learning_supported: true,
              preview: [{ type: 'text', label: 'cat' }, { type: 'text', label: 'dog' }]
            },
            {
              id: 22,
              name: 'Market',
              translation: 'Market',
              word_count: 5,
              learning_supported: true,
              preview: [{ type: 'text', label: 'bread' }, { type: 'text', label: 'apple' }]
            }
          ]
        }
      },
      flashcards: {
        wordset: 'offline-set',
        wordsetIds: [777],
        availableModes: ['learning', 'practice'],
        categories: [
          { id: 11, name: 'Animals', word_count: 5, learning_supported: true },
          { id: 22, name: 'Market', word_count: 5, learning_supported: true }
        ],
        userStudyState: {
          wordset_id: 777,
          category_ids: [],
          starred_word_ids: [],
          star_mode: 'normal',
          fast_transitions: false
        },
        offlineCategoryData: {
          Animals: [
            { id: 101, title: 'cat', label: 'cat', translation: 'kedi' },
            { id: 102, title: 'dog', label: 'dog', translation: 'kopek' },
            ...Array.from({ length: 3 }, (_, index) => ({
              id: 103 + index,
              title: `animal ${index + 3}`,
              label: `animal ${index + 3}`,
              translation: `animal translation ${index + 3}`
            }))
          ],
          Market: [
            { id: 201, title: 'bread', label: 'bread', translation: 'ekmek' },
            { id: 202, title: 'apple', label: 'apple', translation: 'elma' },
            ...Array.from({ length: 3 }, (_, index) => ({
              id: 203 + index,
              title: `market ${index + 3}`,
              label: `market ${index + 3}`,
              translation: `market translation ${index + 3}`
            }))
          ]
        }
      }
    };
  }, options);
  await page.addScriptTag({ content: offlineAppSource });
  const cardCount = await page.locator('#ll-offline-category-grid .ll-wordset-card').count();
  if (cardCount === 0 && errors.length) {
    throw new Error(`Offline launcher failed to render: ${errors.join(' | ')}`);
  }
}

test('offline app launcher filters, sorts, selects, and launches the real shell wiring', async ({ page }) => {
  await mountOfflineLauncher(page);

  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(2);
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Animals', 'Market']);

  await page.locator('[data-ll-offline-category-search]').fill('bread');
  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(1);
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Market']);

  await page.locator('[data-ll-offline-category-search]').fill('');
  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(2);
  await page.locator('[data-ll-offline-sort-toggle]').click();
  await page.locator('[data-ll-offline-sort-option="alpha-desc"]').click();
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Market', 'Animals']);

  await page.locator('#ll-offline-select-all').click();
  await expect(page.locator('#ll-offline-selection-bar')).toBeVisible();
  await expect(page.locator('#ll-offline-selection-text')).toHaveText('10 words');
  await expect(page.locator('#ll-offline-launch-practice-selected')).toBeEnabled();

  await page.locator('#ll-offline-launch-practice-selected').click();
  const launch = await page.evaluate(() => ({
    launches: window.__offlineLaunches,
    lastLaunchPlan: window.llToolsFlashcardsData && window.llToolsFlashcardsData.lastLaunchPlan,
    userStudyState: window.llToolsFlashcardsData && window.llToolsFlashcardsData.userStudyState
  }));

  expect(launch.launches).toHaveLength(1);
  expect(launch.launches[0].catNames).toEqual(['Animals', 'Market']);
  expect(launch.launches[0].mode).toBe('practice');
  expect(launch.lastLaunchPlan.category_ids).toEqual([11, 22]);
  expect(launch.lastLaunchPlan.session_word_ids).toEqual([]);
  expect(launch.userStudyState.wordset_id).toBe(777);
  expect(launch.userStudyState.category_ids).toEqual([11, 22]);
});

test('offline app sync panel signs in, syncs, and disconnects through the real shell wiring', async ({ page }) => {
  await mountOfflineLauncher(page, { enableSync: true });

  const status = page.locator('#ll-offline-sync-status');
  const meta = page.locator('#ll-offline-sync-meta');
  const feedback = page.locator('#ll-offline-sync-feedback');
  const sheet = page.locator('#ll-offline-sync-sheet');

  await expect(page.locator('#ll-offline-sync-panel')).toBeVisible();
  await expect(status).toHaveText('Local progress only');
  await expect(page.locator('#ll-offline-sync-connect')).toBeVisible();
  await expect(page.locator('#ll-offline-sync-now')).toBeHidden();

  await page.locator('#ll-offline-sync-connect').click();
  await expect(sheet).toBeVisible();
  await expect(page.locator('#ll-offline-sync-sheet-title')).toHaveText('Connect to Sync');

  await page.locator('#ll-offline-sync-identifier').fill('learner@example.com');
  await page.locator('#ll-offline-sync-password').fill('secret-password');
  await page.locator('#ll-offline-sync-password-toggle').click();
  await expect(page.locator('#ll-offline-sync-password')).toHaveAttribute('type', 'text');
  await page.locator('#ll-offline-sync-submit').click();

  await expect(sheet).toBeHidden();
  await expect(status).toHaveText('Connected as Offline Learner');
  await expect(meta).toHaveText('All caught up');
  await expect(feedback).toHaveText('All caught up');
  await expect(page.locator('#ll-offline-sync-now')).toBeVisible();
  await expect(page.locator('#ll-offline-sync-disconnect')).toBeVisible();

  await page.locator('#ll-offline-sync-now').click();
  await expect(feedback).toHaveText('All caught up');

  const synced = await page.evaluate(() => ({
    requests: window.__offlineSyncRequests,
    calls: window.__offlineSyncCalls,
    categoryIds: window.llToolsFlashcardsData.userStudyState.category_ids
  }));
  expect(synced.requests.map((request) => request.action)).toContain('fixture_login');
  expect(synced.requests.find((request) => request.action === 'fixture_login').payload).toMatchObject({
    identifier: 'learner@example.com',
    device_id: 'fixture-device',
    profile_id: 'fixture-profile'
  });
  expect(synced.calls.length).toBeGreaterThanOrEqual(2);
  expect(synced.calls[0].wordIds).toEqual([101, 102, 103, 104, 105, 201, 202, 203, 204, 205]);
  expect(synced.categoryIds).toEqual([11]);

  await page.locator('#ll-offline-sync-disconnect').click();
  await expect(status).toHaveText('Local progress only');
  await expect(feedback).toHaveText('Disconnected locally');

  const actions = await page.evaluate(() => window.__offlineSyncRequests.map((request) => request.action));
  expect(actions).toContain('fixture_logout');
});

test('offline app sync panel keeps login failures recoverable', async ({ page }) => {
  await mountOfflineLauncher(page, { enableSync: true, loginFailure: true });

  await page.locator('#ll-offline-sync-connect').click();
  await expect(page.locator('#ll-offline-sync-sheet')).toBeVisible();

  await page.locator('#ll-offline-sync-identifier').fill('learner@example.com');
  await page.locator('#ll-offline-sync-password').fill('wrong-password');
  await page.locator('#ll-offline-sync-submit').click();

  await expect(page.locator('#ll-offline-sync-sheet')).toBeVisible();
  await expect(page.locator('#ll-offline-sync-sheet-feedback')).toHaveText('Invalid offline login.');
  await expect(page.locator('#ll-offline-sync-submit')).toBeEnabled();
  await expect(page.locator('#ll-offline-sync-status')).toHaveText('Local progress only');
  await expect(page.locator('#ll-offline-sync-now')).toBeHidden();

  const state = await page.evaluate(() => ({
    requests: window.__offlineSyncRequests,
    calls: window.__offlineSyncCalls,
    syncState: window.LLFlashcards.ProgressTracker.getSyncState()
  }));
  expect(state.requests.map((request) => request.action)).toEqual(['fixture_login']);
  expect(state.calls).toHaveLength(0);
  expect(state.syncState.connected).toBe(false);
});

test('offline app sync panel surfaces failed server sync without dropping local pending progress', async ({ page }) => {
  await mountOfflineLauncher(page, { enableSync: true, syncFailure: 'failed-result' });

  await page.locator('#ll-offline-sync-connect').click();
  await page.locator('#ll-offline-sync-identifier').fill('learner@example.com');
  await page.locator('#ll-offline-sync-password').fill('secret-password');
  await page.locator('#ll-offline-sync-submit').click();

  await expect(page.locator('#ll-offline-sync-sheet')).toBeHidden();
  await expect(page.locator('#ll-offline-sync-status')).toHaveText('Connected as Offline Learner');
  await expect(page.locator('#ll-offline-sync-meta')).toHaveText('2 pending');
  await expect(page.locator('#ll-offline-sync-feedback')).toHaveText('Server rejected one local progress event.');
  await expect(page.locator('#ll-offline-sync-now')).toBeVisible();
  await expect(page.locator('#ll-offline-sync-disconnect')).toBeVisible();

  await page.locator('#ll-offline-sync-now').click();
  await expect(page.locator('#ll-offline-sync-feedback')).toHaveText('Server rejected one local progress event.');

  const state = await page.evaluate(() => ({
    calls: window.__offlineSyncCalls,
    syncState: window.LLFlashcards.ProgressTracker.getSyncState(),
    categoryIds: window.llToolsFlashcardsData.userStudyState.category_ids
  }));
  expect(state.calls.length).toBeGreaterThanOrEqual(2);
  expect(state.calls[0].wordIds).toEqual([101, 102, 103, 104, 105, 201, 202, 203, 204, 205]);
  expect(state.syncState.connected).toBe(true);
  expect(state.syncState.pending).toBe(2);
  expect(state.syncState.auth.last_error).toBe('Server rejected one local progress event.');
  expect(state.categoryIds).toEqual([]);
});

test('offline app applies remote sync snapshots to launcher state and next recommendation', async ({ page }) => {
  await mountOfflineLauncher(page, { enableSync: true });

  await page.evaluate(() => {
    window.jQuery(document).trigger('lltools:remote-sync-snapshot', [{
      state: {
        wordset_id: 777,
        category_ids: [22],
        starred_word_ids: [201],
        star_mode: 'starred',
        fast_transitions: true
      },
      progress_words: {
        101: {
          progress_status: 'studied',
          last_seen_at: '2026-05-29T08:00:00Z'
        },
        201: {
          progress_status: 'mastered',
          last_seen_at: '2026-06-09T10:00:00Z'
        },
        202: {
          progress_status: 'mastered',
          last_seen_at: '2026-06-09T10:01:00Z'
        },
        203: {
          progress_status: 'studied',
          last_seen_at: '2026-06-09T10:02:00Z'
        }
      },
      category_progress: {
        11: {
          exposure_total: 2,
          last_seen_at: '2026-05-29T08:00:00Z'
        },
        22: {
          exposure_total: 9,
          last_seen_at: '2026-06-09T10:02:00Z'
        }
      },
      next_activity: {
        mode: 'practice',
        category_ids: [22],
        session_word_ids: [201, 202]
      },
      recommendation_queue: [{
        mode: 'learning',
        category_ids: [11],
        session_word_ids: []
      }]
    }]);
  });

  await expect(page.locator('#ll-offline-selection-bar')).toBeVisible();
  await expect(page.locator('#ll-offline-selection-text')).toHaveText('5 words');
  await expect(page.locator('[data-ll-offline-category-select][data-cat-id="22"]')).toBeChecked();
  await expect(page.locator('[data-ll-offline-category-select][data-cat-id="11"]')).not.toBeChecked();

  await page.locator('[data-ll-offline-sort-toggle]').click();
  await page.locator('[data-ll-offline-sort-option="progress-desc"]').click();
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Market', 'Animals']);

  const progressWidths = await page.locator('#ll-offline-category-grid .ll-wordset-card').first().evaluate((card) => ({
    mastered: card.querySelector('.ll-wordset-card__progress-segment--mastered')?.style.width || '',
    studied: card.querySelector('.ll-wordset-card__progress-segment--studied')?.style.width || ''
  }));
  expect(progressWidths).toEqual({
    mastered: '40%',
    studied: '20%'
  });

  await expect(page.locator('#ll-offline-next-text .ll-wordset-next-card__line')).toHaveText(['Practice', 'Market']);
  await expect(page.locator('#ll-offline-next-count')).toHaveText('2');
  await expect(page.locator('#ll-offline-next-card')).toBeDisabled();

  await page.locator('#ll-offline-selection-clear').click();
  await expect(page.locator('#ll-offline-selection-bar')).toBeHidden();
  await expect(page.locator('#ll-offline-next-card')).toBeEnabled();
  await expect(page.locator('#ll-offline-next-card')).toHaveAttribute('aria-label', 'Recommended: Practice in Market (2 words).');

  await page.locator('#ll-offline-next-card').click();
  const launch = await page.evaluate(() => ({
    launches: window.__offlineLaunches,
    lastLaunchPlan: window.llToolsFlashcardsData && window.llToolsFlashcardsData.lastLaunchPlan,
    userStudyState: window.llToolsFlashcardsData && window.llToolsFlashcardsData.userStudyState
  }));

  expect(launch.launches).toHaveLength(1);
  expect(launch.launches[0].catNames).toEqual(['Market']);
  expect(launch.launches[0].mode).toBe('practice');
  expect(launch.lastLaunchPlan.category_ids).toEqual([22]);
  expect(launch.lastLaunchPlan.session_word_ids).toEqual([201, 202]);
  expect(launch.userStudyState.starred_word_ids).toEqual([201]);
  expect(launch.userStudyState.star_mode).toBe('starred');
  expect(launch.userStudyState.fast_transitions).toBe(true);
});
