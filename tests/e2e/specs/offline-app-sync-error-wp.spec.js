const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000 });

function seedFixture() {
  const scriptPath = path.resolve(__dirname, '..', 'fixtures', 'seed-offline-sync-error.php');
  return runWpCliJson(['eval-file', scriptPath], { timeoutMs: 180000 });
}

function isOfflineSyncRequest(response) {
  const request = response.request();
  const postData = request.postData() || '';
  return response.url().includes('/wp-admin/admin-ajax.php')
    && postData.includes('action=ll_tools_offline_app_sync');
}

async function readOfflineSyncSnapshot(page, wordId) {
  return page.evaluate((targetWordId) => {
    const tracker = window.LLFlashcards && window.LLFlashcards.ProgressTracker;
    const state = tracker ? tracker.getSyncState() : {};
    const storeKey = Object.keys(window.localStorage).find((key) => key.startsWith('lltools_offline_progress_v2::wordset:')) || '';
    const store = storeKey ? JSON.parse(window.localStorage.getItem(storeKey) || '{}') : {};
    return {
      state,
      storeKey,
      queueLength: Array.isArray(store.queue) ? store.queue.length : 0,
      studyState: store.study_state || {},
      lastSyncError: store.last_sync_error || '',
      wordProgress: store.words && store.words[String(targetWordId)] ? store.words[String(targetWordId)] : null
    };
  }, wordId);
}

async function queueLocalProgress(page, fixture) {
  return page.evaluate((seed) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const categories = window.llToolsFlashcardsData.offlineCategoryData || {};
    const words = categories[seed.categoryName] || Object.values(categories)[0] || [];
    const word = words.find((candidate) => Number(candidate.id) === Number(seed.wordId)) || words[0];
    if (!word) {
      throw new Error('Offline fixture did not expose a word to queue.');
    }

    tracker.saveStudyState({
      wordset_id: seed.wordsetId,
      category_ids: [seed.categoryId],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    });
    tracker.trackWordOutcome({
      word,
      wordId: Number(word.id),
      wordsetId: seed.wordsetId,
      categoryId: seed.categoryId,
      categoryName: seed.categoryName,
      mode: 'practice',
      isCorrect: true,
      hadWrongBefore: false,
      flushDelay: 600000,
      payload: {
        recording_type: 'isolation',
        available_recording_types: ['isolation']
      }
    });

    return tracker.getSyncState();
  }, {
    wordsetId: fixture.wordsetId,
    categoryId: fixture.categoryId,
    categoryName: fixture.categoryName,
    wordId: fixture.wordIds[0]
  });
}

test('offline app keeps local progress and exposes retry after a WordPress sync conflict', async ({ page }) => {
  let fixture;
  try {
    fixture = seedFixture();
  } catch (error) {
    if (error && error.isWpCliUnavailable) {
      test.skip(true, `Unable to seed WordPress offline sync fixture through WP-CLI: ${error.message}`);
      return;
    }
    throw error;
  }

  let forceConflict = true;
  let syncRequests = 0;
  await page.route('**/wp-admin/admin-ajax.php', async (route, request) => {
    const postData = request.postData() || '';
    if (!postData.includes('action=ll_tools_offline_app_sync')) {
      await route.continue();
      return;
    }

    syncRequests += 1;
    if (!forceConflict) {
      await route.continue();
      return;
    }

    forceConflict = false;
    const response = await route.fetch({
      postData: `${postData}&ll_e2e_offline_sync_failure=conflict`
    });
    await route.fulfill({ response });
  });

  await page.goto(fixture.offlinePath, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => window.localStorage.clear());
  await page.reload({ waitUntil: 'domcontentloaded' });

  await expect(page.locator('#ll-offline-sync-panel')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(1, { timeout: 60000 });
  await expect(page.locator('.ll-wordset-card__title')).toHaveText([fixture.categoryName]);
  await expect(page.locator('#ll-offline-sync-status')).toHaveText('Local progress only');

  const queuedState = await queueLocalProgress(page, fixture);
  expect(queuedState.connected).toBe(false);
  expect(queuedState.pending).toBe(1);

  const beforeLogin = await readOfflineSyncSnapshot(page, fixture.wordIds[0]);
  expect(beforeLogin.queueLength).toBe(1);
  expect(beforeLogin.studyState.category_ids).toEqual([fixture.categoryId]);
  expect(beforeLogin.wordProgress).toMatchObject({
    correct_clean: 1,
    current_correct_streak: 1,
    last_mode: 'practice'
  });

  await page.locator('#ll-offline-sync-connect').click();
  await expect(page.locator('#ll-offline-sync-sheet')).toBeVisible();
  await page.locator('#ll-offline-sync-identifier').fill(fixture.learner.login);
  await page.locator('#ll-offline-sync-password').fill(fixture.learner.password);

  const conflictResponsePromise = page.waitForResponse((response) => (
    isOfflineSyncRequest(response) && response.status() === 409
  ), { timeout: 90000 });
  await page.locator('#ll-offline-sync-submit').click();
  await conflictResponsePromise;

  await expect(page.locator('#ll-offline-sync-sheet')).toBeHidden({ timeout: 60000 });
  await expect(page.locator('#ll-offline-sync-status')).toHaveText(`Connected as ${fixture.learner.displayName}`);
  await expect(page.locator('#ll-offline-sync-meta')).toHaveText('1 pending');
  await expect(page.locator('#ll-offline-sync-feedback')).toContainText(/Conflict|request_failed/);
  await expect(page.locator('#ll-offline-sync-now')).toBeVisible();
  await expect(page.locator('#ll-offline-sync-now')).toBeEnabled();

  await page.waitForTimeout(350);
  expect(syncRequests).toBe(1);

  const afterConflict = await readOfflineSyncSnapshot(page, fixture.wordIds[0]);
  expect(afterConflict.state.connected).toBe(true);
  expect(afterConflict.state.pending).toBe(1);
  expect(afterConflict.queueLength).toBe(1);
  expect(afterConflict.studyState.category_ids).toEqual([fixture.categoryId]);
  expect(afterConflict.lastSyncError).toBe('request_failed');
  expect(afterConflict.wordProgress).toMatchObject({
    correct_clean: 1,
    current_correct_streak: 1,
    last_mode: 'practice'
  });

  const retryResponsePromise = page.waitForResponse((response) => (
    isOfflineSyncRequest(response) && response.status() === 200
  ), { timeout: 90000 });
  await page.locator('#ll-offline-sync-now').click();
  const retryResponse = await retryResponsePromise;
  const retryPayload = await retryResponse.json();
  expect(retryPayload.success).toBe(true);

  await expect(page.locator('#ll-offline-sync-status')).toHaveText(`Connected as ${fixture.learner.displayName}`);
  await expect(page.locator('#ll-offline-sync-meta')).toHaveText('All caught up', { timeout: 60000 });
  await expect(page.locator('#ll-offline-sync-feedback')).toHaveText('All caught up');

  const afterRetry = await readOfflineSyncSnapshot(page, fixture.wordIds[0]);
  expect(afterRetry.state.connected).toBe(true);
  expect(afterRetry.state.pending).toBe(0);
  expect(afterRetry.queueLength).toBe(0);
  expect(afterRetry.studyState.category_ids).toEqual([fixture.categoryId]);
  expect(afterRetry.lastSyncError).toBe('');
  expect(afterRetry.wordProgress).toMatchObject({
    correct_clean: 1,
    current_correct_streak: 1,
    last_mode: 'practice'
  });
});
