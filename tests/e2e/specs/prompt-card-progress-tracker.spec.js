const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const progressTrackerSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/progress-tracker.js'),
  'utf8'
);
const progressScopeA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const progressScopeB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const storageTestUrl = 'https://ll-progress-journal.test/';

async function openStorageBackedPage(page) {
  await page.route(`${storageTestUrl}**`, route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><body></body></html>'
  }));
  await page.goto(storageTestUrl);
}

test('progress tracker queues prompt-card-only events without a word progress id', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '',
      userStudyNonce: '',
      isUserLoggedIn: false
    };
    window.llToolsStudyData = {};
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(() => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.clearQueue();
    tracker.setContext({
      mode: 'practice',
      wordsetId: 77,
      categoryIds: [12]
    });

    const promptWord = {
      id: 912,
      prompt_card_id: 912,
      is_prompt_card: true,
      progress_word_id: 0,
      __categoryName: 'Prompt Cards'
    };

    const exposureId = tracker.trackWordExposure({
      mode: 'practice',
      word: promptWord,
      wordId: 0,
      categoryId: 12,
      wordsetId: 77,
      promptCardId: 912
    });
    const outcomeId = tracker.trackWordOutcome({
      mode: 'practice',
      word: promptWord,
      wordId: 0,
      categoryId: 12,
      wordsetId: 77,
      promptCardId: 912,
      isCorrect: true,
      hadWrongBefore: false
    });

    return {
      exposureId,
      outcomeId,
      queueSize: tracker.getQueueSize()
    };
  });

  expect(result.exposureId).toBeTruthy();
  expect(result.outcomeId).toBeTruthy();
  expect(result.queueSize).toBe(2);
});

test('progress tracker selectively retries UUID failures and fails closed for older responses', async ({ page }) => {
  await openStorageBackedPage(page);
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-test-nonce',
      isUserLoggedIn: true,
      progressStorageScope: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressPosts = [];
    window.__progressResponses = [];
    window.__resolveProgressPost = (response) => {
      const respond = window.__progressResponses.shift();
      if (!respond) {
        throw new Error('No pending progress request.');
      }
      respond(response);
    };
    window.jQuery = {
      post(_url, payload) {
        const callbacks = {
          done: null,
          fail: null,
          always: null
        };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          always(callback) {
            callbacks.always = callback;
            return request;
          }
        };
        window.__progressPosts.push(JSON.parse(payload.events));
        window.__progressResponses.push((response) => {
          if (callbacks.done) {
            callbacks.done(response);
          }
          if (callbacks.always) {
            callbacks.always();
          }
        });
        return request;
      }
    };
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async () => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.clearQueue();

    const processedId = tracker.trackCategoryStudy({ categoryId: 11, units: 1 });
    const duplicateId = tracker.trackCategoryStudy({ categoryId: 12, units: 1 });
    const failedId = tracker.trackCategoryStudy({ categoryId: 13, units: 1 });
    const journalBeforeFirstFlush = window.sessionStorage.getItem(
      'lltools_wp_progress_journal_v1::user:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::wordset:77'
    );
    const firstFlush = tracker.flush();

    // This event is queued after the first batch has already been removed.
    const newerId = tracker.trackCategoryStudy({ categoryId: 14, units: 1 });
    window.__resolveProgressPost({
      success: true,
      data: {
        stats: {
          received: 3,
          processed: 1,
          duplicates: 1,
          invalid: 0,
          failed: 1,
          failed_event_uuids: [failedId]
        }
      }
    });
    const firstResult = await firstFlush;
    const partialJournal = JSON.parse(window.sessionStorage.getItem(
      'lltools_wp_progress_journal_v1::user:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::wordset:77'
    ));

    const secondFlush = tracker.flush();
    const secondBatch = window.__progressPosts[1].map((event) => event.event_uuid);
    window.__resolveProgressPost({
      success: true,
      data: {
        stats: {
          received: 2,
          processed: 2,
          duplicates: 0,
          invalid: 0,
          failed: 0,
          failed_event_uuids: []
        }
      }
    });
    await secondFlush;

    const fallbackProcessedId = tracker.trackCategoryStudy({ categoryId: 21, units: 1 });
    const fallbackFailedId = tracker.trackCategoryStudy({ categoryId: 22, units: 1 });
    const fallbackFirstFlush = tracker.flush();
    const fallbackNewerId = tracker.trackCategoryStudy({ categoryId: 23, units: 1 });
    window.__resolveProgressPost({
      success: true,
      data: {
        stats: {
          received: 2,
          processed: 1,
          duplicates: 0,
          invalid: 0,
          failed: 1
        }
      }
    });
    const fallbackFirstResult = await fallbackFirstFlush;

    const fallbackRetryFlush = tracker.flush();
    const fallbackRetryBatch = window.__progressPosts[3].map((event) => event.event_uuid);
    window.__resolveProgressPost({
      success: true,
      data: {
        stats: {
          received: 3,
          processed: 1,
          duplicates: 2,
          invalid: 0,
          failed: 0,
          failed_event_uuids: []
        }
      }
    });
    await fallbackRetryFlush;

    const finalJournal = window.sessionStorage.getItem(
      'lltools_wp_progress_journal_v1::user:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::wordset:77'
    );

    return {
      processedId,
      duplicateId,
      failedId,
      newerId,
      journalBeforeFirstFlush,
      firstResult,
      partialJournalIds: partialJournal.events.map(event => event.event_uuid),
      secondBatch,
      fallbackProcessedId,
      fallbackFailedId,
      fallbackNewerId,
      fallbackFirstResult,
      fallbackRetryBatch,
      finalJournal,
      finalQueueSize: tracker.getQueueSize()
    };
  });

  expect(result.firstResult.partial_failure).toBe(true);
  expect(result.firstResult.failed_event_uuids).toEqual([result.failedId]);
  expect(result.journalBeforeFirstFlush).not.toContain('progress-test-nonce');
  expect(result.journalBeforeFirstFlush).not.toContain('/wp-admin/admin-ajax.php');
  expect(result.partialJournalIds).toEqual([result.failedId, result.newerId]);
  expect(result.secondBatch).toEqual([result.failedId, result.newerId]);
  expect(result.secondBatch).not.toContain(result.processedId);
  expect(result.secondBatch).not.toContain(result.duplicateId);
  expect(result.fallbackFirstResult.partial_failure).toBe(true);
  expect(result.fallbackFirstResult.failed_event_uuids).toEqual([
    result.fallbackProcessedId,
    result.fallbackFailedId
  ]);
  expect(result.fallbackRetryBatch).toEqual([
    result.fallbackProcessedId,
    result.fallbackFailedId,
    result.fallbackNewerId
  ]);
  expect(result.finalJournal).toBeNull();
  expect(result.finalQueueSize).toBe(0);
});

test('progress tracker times out a stalled sync and retries the same event cleanly', async ({ page }) => {
  await openStorageBackedPage(page);
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-test-nonce',
      isUserLoggedIn: true,
      progressStorageScope: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      progressSyncTimeoutMs: 50,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressTimeoutMock = {
      aborts: 0,
      batches: [],
      requests: []
    };
    window.jQuery = {
      post(_url, payload) {
        const callbacks = {
          done: null,
          fail: null
        };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          abort() {
            window.__progressTimeoutMock.aborts += 1;
            if (callbacks.fail) {
              callbacks.fail({}, 'timeout', 'timeout');
            }
          },
          resolve(response) {
            if (callbacks.done) {
              callbacks.done(response);
            }
          }
        };
        window.__progressTimeoutMock.batches.push(JSON.parse(payload.events));
        window.__progressTimeoutMock.requests.push(request);
        return request;
      }
    };
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async () => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.clearQueue();
    const eventId = tracker.trackCategoryStudy({ categoryId: 31, units: 1 });
    const timedOutPromise = tracker.flush();
    const newerId = tracker.trackCategoryStudy({ categoryId: 32, units: 1 });
    const journalDuringRequest = JSON.parse(window.sessionStorage.getItem(
      'lltools_wp_progress_journal_v1::user:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::wordset:77'
    ));
    const timedOut = await timedOutPromise;
    const journalAfterTimeout = JSON.parse(window.sessionStorage.getItem(
      'lltools_wp_progress_journal_v1::user:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::wordset:77'
    ));

    const retryPromise = tracker.flush();
    const retryBatch = window.__progressTimeoutMock.batches[1].map(event => event.event_uuid);
    window.__progressTimeoutMock.requests[1].resolve({
      success: true,
      data: {
        stats: {
          received: 2,
          processed: 2,
          duplicates: 0,
          invalid: 0,
          failed: 0,
          failed_event_uuids: []
        }
      }
    });
    const retried = await retryPromise;

    return {
      eventId,
      newerId,
      journalDuringRequestIds: journalDuringRequest.events.map(event => event.event_uuid),
      journalAfterTimeoutIds: journalAfterTimeout.events.map(event => event.event_uuid),
      timedOut,
      retryBatch,
      retried,
      aborts: window.__progressTimeoutMock.aborts,
      finalJournal: window.sessionStorage.getItem(
        'lltools_wp_progress_journal_v1::user:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa::wordset:77'
      ),
      finalQueueSize: tracker.getQueueSize()
    };
  });

  expect(result.timedOut).toMatchObject({
    failed: true,
    timed_out: true,
    error: 'request_timeout'
  });
  expect(result.journalDuringRequestIds).toEqual([result.eventId, result.newerId]);
  expect(result.journalAfterTimeoutIds).toEqual([result.eventId, result.newerId]);
  expect(result.retryBatch).toEqual([result.eventId, result.newerId]);
  expect(result.retried.partial_failure).toBe(false);
  expect(result.aborts).toBe(1);
  expect(result.finalJournal).toBeNull();
  expect(result.finalQueueSize).toBe(0);
});

test('mode completion queued during an active request survives its failure and flushes on the preserved timer', async ({ page }) => {
  await openStorageBackedPage(page);
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-test-nonce',
      isUserLoggedIn: true,
      progressStorageScope: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressOverlapMock = { batches: [], requests: [] };
    window.jQuery = {
      post(_url, payload) {
        const callbacks = { done: null, fail: null };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          resolve(response) {
            if (callbacks.done) callbacks.done(response);
          },
          reject(error) {
            if (callbacks.fail) callbacks.fail({}, 'error', error || 'request_failed');
          }
        };
        window.__progressOverlapMock.batches.push(JSON.parse(payload.events));
        window.__progressOverlapMock.requests.push(request);
        return request;
      }
    };
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async () => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const waitFor = (predicate, timeoutMs = 1500) => new Promise((resolve, reject) => {
      const startedAt = Date.now();
      const check = () => {
        if (predicate()) {
          resolve();
          return;
        }
        if (Date.now() - startedAt >= timeoutMs) {
          reject(new Error('Timed out waiting for progress retry.'));
          return;
        }
        window.setTimeout(check, 10);
      };
      check();
    });

    const originalId = tracker.trackCategoryStudy({ categoryId: 35, units: 1 });
    const originalFlush = tracker.flush();
    const completionId = tracker.trackModeSessionComplete({
      mode: 'practice',
      categoryIds: [35],
      flushDelay: 60
    });
    const immediateResult = await tracker.flush();

    // Let the completion event's own timer fire while the first request is
    // still active. It should record one follow-up request, not poll the server.
    await new Promise(resolve => window.setTimeout(resolve, 350));
    const requestsBeforeFailure = window.__progressOverlapMock.batches.length;
    window.__progressOverlapMock.requests[0].reject('first_request_failed');
    const originalResult = await originalFlush;
    await waitFor(() => window.__progressOverlapMock.batches.length === 2);

    const retryIds = window.__progressOverlapMock.batches[1].map(event => event.event_uuid);
    window.__progressOverlapMock.requests[1].resolve({
      success: true,
      data: {
        stats: {
          received: 2,
          processed: 2,
          duplicates: 0,
          invalid: 0,
          failed: 0,
          failed_event_uuids: []
        }
      }
    });
    await waitFor(() => tracker.getQueueSize() === 0);

    return {
      originalId,
      completionId,
      immediateResult,
      requestsBeforeFailure,
      originalResult,
      retryIds,
      finalQueueSize: tracker.getQueueSize()
    };
  });

  expect(result.immediateResult).toEqual({ queued: 1, in_flight: true });
  expect(result.requestsBeforeFailure).toBe(1);
  expect(result.originalResult).toMatchObject({ failed: true, error: 'first_request_failed' });
  expect(result.retryIds).toEqual([result.originalId, result.completionId]);
  expect(result.finalQueueSize).toBe(0);
});

test('partial success preserves the flush timer for failed UUIDs and newer activity', async ({ page }) => {
  await openStorageBackedPage(page);
  await page.evaluate(({ scope }) => {
    window.sessionStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-partial-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressPartialMock = { batches: [], requests: [] };
    window.jQuery = {
      post(_url, payload) {
        const callbacks = { done: null, fail: null };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          resolve(response) {
            if (callbacks.done) callbacks.done(response);
          }
        };
        window.__progressPartialMock.batches.push(JSON.parse(payload.events));
        window.__progressPartialMock.requests.push(request);
        return request;
      }
    };
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async () => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const waitFor = (predicate, timeoutMs = 1500) => new Promise((resolve, reject) => {
      const startedAt = Date.now();
      const check = () => {
        if (predicate()) {
          resolve();
          return;
        }
        if (Date.now() - startedAt >= timeoutMs) {
          reject(new Error('Timed out waiting for the partial progress retry.'));
          return;
        }
        window.setTimeout(check, 10);
      };
      check();
    });

    tracker.clearQueue();
    const failedId = tracker.trackCategoryStudy({ categoryId: 71, units: 1 });
    const firstFlush = tracker.flush();
    const newerId = tracker.trackCategoryStudy({ categoryId: 72, units: 1, flushDelay: 120 });

    window.__progressPartialMock.requests[0].resolve({
      success: true,
      data: {
        stats: {
          received: 1,
          processed: 0,
          duplicates: 0,
          invalid: 0,
          failed: 1,
          failed_event_uuids: [failedId]
        }
      }
    });
    const firstResult = await firstFlush;
    await waitFor(() => window.__progressPartialMock.batches.length === 2);

    const retryIds = window.__progressPartialMock.batches[1].map(event => event.event_uuid);
    window.__progressPartialMock.requests[1].resolve({
      success: true,
      data: {
        stats: {
          received: 2,
          processed: 2,
          duplicates: 0,
          invalid: 0,
          failed: 0,
          failed_event_uuids: []
        }
      }
    });
    await waitFor(() => tracker.getQueueSize() === 0);

    return {
      failedId,
      newerId,
      firstResult,
      retryIds,
      finalQueueSize: tracker.getQueueSize()
    };
  });

  expect(result.firstResult).toMatchObject({
    partial_failure: true,
    failed_event_uuids: [result.failedId]
  });
  expect(result.retryIds).toEqual([result.failedId, result.newerId]);
  expect(result.finalQueueSize).toBe(0);
});

test('non-retryable conflict keeps a human error and generic persisted failure state', async ({ page }) => {
  const progressStoreKey = 'lltools_offline_progress_v2::wordset:77';

  await openStorageBackedPage(page);
  await page.evaluate(() => {
    window.localStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'offline',
      wordsetIds: [77],
      offlineSync: {
        enabled: true,
        ajaxUrl: '/wp-admin/admin-ajax.php',
        syncAction: 'll_tools_offline_app_sync'
      }
    };
    window.llToolsStudyData = {};
    window.jQuery = {
      post() {
        const request = {
          done() {
            return request;
          },
          fail(callback) {
            window.setTimeout(() => callback({
              status: 409,
              responseJSON: {
                success: false,
                data: {
                  code: 'll_tools_e2e_offline_sync_conflict',
                  message: 'Offline sync conflict from the E2E fixture. Retry is available.'
                }
              },
              getResponseHeader() {
                return null;
              }
            }, 'error', 'Conflict'), 0);
            return request;
          }
        };
        return request;
      }
    };
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async (key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.setOfflineSyncSession({ auth_token: 'offline-conflict-token', user: { id: 23 } });
    const eventId = tracker.trackCategoryStudy({ categoryId: 73, units: 1 });
    const failed = await tracker.flush();
    return {
      eventId,
      failed,
      state: tracker.getSyncState(),
      snapshot: JSON.parse(window.localStorage.getItem(key))
    };
  }, progressStoreKey);

  expect(result.failed).toMatchObject({
    failed: true,
    error: 'Conflict',
    retryable: false,
    retry_after_ms: 0
  });
  expect(result.state).toMatchObject({
    queued: 1,
    backing_off: false,
    last_sync_error: 'request_failed'
  });
  expect(result.snapshot.last_sync_error).toBe('request_failed');
  expect(result.snapshot.queue.map(event => event.event_uuid)).toEqual([result.eventId]);
});

test('typed progress Retry-After cooldown journals new activity and resumes one exact batch automatically', async ({ page }) => {
  const journalKey = `lltools_wp_progress_journal_v1::user:${progressScopeA}::wordset:77`;

  await openStorageBackedPage(page);
  await page.evaluate(({ scope }) => {
    window.sessionStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-retry-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressCooldownMock = { batches: [], requestTimes: [], requests: [] };
    window.jQuery = {
      post(_url, payload) {
        const callbacks = { done: null, fail: null };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          resolve(response) {
            if (callbacks.done) callbacks.done(response);
          },
          rejectRetry() {
            if (!callbacks.fail) return;
            callbacks.fail({
              status: 503,
              responseJSON: {
                success: false,
                data: {
                  code: 'progress_transactional_engine_unavailable',
                  retryable: true,
                  retry_after: 0.08
                }
              },
              getResponseHeader(name) {
                return String(name).toLowerCase() === 'retry-after' ? '0.18' : null;
              }
            }, 'error', 'Service Unavailable');
          }
        };
        window.__progressCooldownMock.batches.push(JSON.parse(payload.events));
        window.__progressCooldownMock.requestTimes.push(Date.now());
        window.__progressCooldownMock.requests.push(request);
        if (window.__progressCooldownMock.batches.length === 2) {
          window.clearInterval(window.__progressRetryActivityInterval);
          window.__progressRetryActivityInterval = null;
          window.__progressCooldownMock.generatedIdsAtRetry = Array.isArray(window.__progressRetryGeneratedIds)
            ? window.__progressRetryGeneratedIds.slice()
            : [];
        }
        return request;
      }
    };
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async (key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const waitFor = (predicate, timeoutMs = 2000) => new Promise((resolve, reject) => {
      const startedAt = Date.now();
      const check = () => {
        if (predicate()) {
          resolve();
          return;
        }
        if (Date.now() - startedAt >= timeoutMs) {
          reject(new Error('Timed out waiting for the progress cooldown to resume.'));
          return;
        }
        window.setTimeout(check, 10);
      };
      check();
    });

    tracker.clearQueue();
    const firstId = tracker.trackCategoryStudy({ categoryId: 81, units: 1 });
    const firstFlush = tracker.flush();
    window.__progressCooldownMock.requests[0].rejectRetry();
    const firstResult = await firstFlush;
    const cooldownState = tracker.getSyncState();
    const deferredFlush = await tracker.flush();

    window.__progressRetryGeneratedIds = [firstId];
    let nextCategoryId = 82;
    window.__progressRetryActivityInterval = window.setInterval(() => {
      const eventId = tracker.trackCategoryStudy({ categoryId: nextCategoryId, units: 1 });
      nextCategoryId += 1;
      if (eventId) window.__progressRetryGeneratedIds.push(eventId);
    }, 40);
    await new Promise(resolve => window.setTimeout(resolve, 60));
    const postsDuringCooldown = window.__progressCooldownMock.batches.length;
    const journalDuringCooldown = JSON.parse(window.sessionStorage.getItem(key));

    await waitFor(() => window.__progressCooldownMock.batches.length === 2);
    const retryBatchIds = window.__progressCooldownMock.batches[1].map(event => event.event_uuid);
    const generatedIdsAtRetry = window.__progressCooldownMock.generatedIdsAtRetry.slice();
    const journalDuringRetry = JSON.parse(window.sessionStorage.getItem(key));
    const retryDelayMs = window.__progressCooldownMock.requestTimes[1]
      - window.__progressCooldownMock.requestTimes[0];

    window.__progressCooldownMock.requests[1].resolve({
      success: true,
      data: {
        stats: {
          received: generatedIdsAtRetry.length,
          processed: generatedIdsAtRetry.length,
          duplicates: 0,
          invalid: 0,
          failed: 0,
          failed_event_uuids: []
        }
      }
    });
    await waitFor(() => tracker.getQueueSize() === 0 && window.sessionStorage.getItem(key) === null);

    return {
      firstId,
      firstResult,
      cooldownState,
      postsDuringCooldown,
      deferredFlush,
      generatedIdsAtRetry,
      journalDuringCooldownIds: journalDuringCooldown.events.map(event => event.event_uuid),
      retryBatchIds,
      journalDuringRetryIds: journalDuringRetry.events.map(event => event.event_uuid),
      retryDelayMs,
      finalState: tracker.getSyncState(),
      finalJournal: window.sessionStorage.getItem(key),
      finalQueueSize: tracker.getQueueSize()
    };
  }, journalKey);

  const expectedIds = result.generatedIdsAtRetry;
  expect(result.firstResult).toMatchObject({
    failed: true,
    error: 'progress_transactional_engine_unavailable',
    retryable: true
  });
  expect(result.firstResult.retry_after_ms).toBeGreaterThanOrEqual(150);
  expect(result.cooldownState).toMatchObject({
    backing_off: true,
    retry_error: 'progress_transactional_engine_unavailable'
  });
  expect(result.postsDuringCooldown).toBe(1);
  expect(result.deferredFlush).toMatchObject({
    queued: 1,
    deferred: true,
    retryable: true,
    error: 'progress_transactional_engine_unavailable'
  });
  expect(result.journalDuringCooldownIds).toEqual(
    expectedIds.slice(0, result.journalDuringCooldownIds.length)
  );
  expect(result.retryBatchIds).toEqual(expectedIds);
  expect(result.journalDuringRetryIds).toEqual(expectedIds);
  expect(result.retryDelayMs).toBeGreaterThanOrEqual(150);
  expect(result.finalState.backing_off).toBe(false);
  expect(result.finalState.retry_after_ms).toBe(0);
  expect(result.finalJournal).toBeNull();
  expect(result.finalQueueSize).toBe(0);
});

test('authenticated Retry-After cooldown survives reload before restoring the exact journal batch', async ({ page }) => {
  const journalKey = `lltools_wp_progress_journal_v1::user:${progressScopeA}::wordset:77`;

  await openStorageBackedPage(page);
  await page.evaluate(({ scope }) => {
    window.sessionStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-reload-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressReloadRequests = [];
    window.jQuery = {
      post() {
        const callbacks = { done: null, fail: null };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          rejectRetry() {
            if (!callbacks.fail) return;
            callbacks.fail({
              status: 503,
              responseJSON: {
                success: false,
                data: {
                  code: 'progress_schema_unavailable',
                  retryable: true,
                  retry_after: 1.2
                }
              },
              getResponseHeader(name) {
                return String(name).toLowerCase() === 'retry-after' ? '1.2' : null;
              }
            }, 'error', 'Service Unavailable');
          }
        };
        window.__progressReloadRequests.push(request);
        return request;
      }
    };
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  const seeded = await page.evaluate(async (key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.clearQueue();
    const eventId = tracker.trackCategoryStudy({ categoryId: 91, units: 1 });
    const flushPromise = tracker.flush();
    window.__progressReloadRequests[0].rejectRetry();
    const failed = await flushPromise;
    return {
      eventId,
      failed,
      snapshot: JSON.parse(window.sessionStorage.getItem(key))
    };
  }, journalKey);

  expect(seeded.failed).toMatchObject({
    failed: true,
    error: 'progress_schema_unavailable',
    retryable: true
  });
  expect(seeded.failed.retry_after_ms).toBeGreaterThanOrEqual(1100);
  expect(seeded.snapshot.events.map(event => event.event_uuid)).toEqual([seeded.eventId]);
  expect(seeded.snapshot.sync_retry_not_before_ms).toBeGreaterThan(Date.now());
  expect(seeded.snapshot.sync_retry_failure_code).toBe('progress_schema_unavailable');

  await page.reload();
  await page.evaluate(({ scope }) => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-reload-fresh-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__progressReloadMock = { batches: [], requestTimes: [], scriptStartedAt: Date.now() };
    window.jQuery = {
      post(_url, payload) {
        const batch = JSON.parse(payload.events);
        window.__progressReloadMock.batches.push(batch);
        window.__progressReloadMock.requestTimes.push(Date.now());
        const request = {
          done(callback) {
            window.setTimeout(() => callback({
              success: true,
              data: {
                stats: {
                  received: batch.length,
                  processed: batch.length,
                  duplicates: 0,
                  invalid: 0,
                  failed: 0,
                  failed_event_uuids: []
                }
              }
            }), 0);
            return request;
          },
          fail() {
            return request;
          }
        };
        return request;
      }
    };
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  await page.waitForTimeout(150);
  expect(await page.evaluate(() => window.__progressReloadMock.batches.length)).toBe(0);
  await page.waitForFunction((key) => (
    window.__progressReloadMock.batches.length === 1
    && window.sessionStorage.getItem(key) === null
  ), journalKey, { timeout: 3000 });

  const restored = await page.evaluate(() => ({
    sentIds: window.__progressReloadMock.batches[0].map(event => event.event_uuid),
    requestDelayMs: window.__progressReloadMock.requestTimes[0] - window.__progressReloadMock.scriptStartedAt,
    state: window.LLFlashcards.ProgressTracker.getSyncState()
  }));
  expect(restored.sentIds).toEqual([seeded.eventId]);
  expect(restored.requestDelayMs).toBeGreaterThanOrEqual(500);
  expect(restored.state.backing_off).toBe(false);
  expect(restored.state.queued).toBe(0);
});

test('offline Retry-After cooldown survives reload in the scoped local progress store', async ({ page }) => {
  const progressStoreKey = 'lltools_offline_progress_v2::wordset:77';
  const offlineConfig = {
    runtimeMode: 'offline',
    wordsetIds: [77],
    offlineSync: {
      enabled: true,
      ajaxUrl: '/wp-admin/admin-ajax.php',
      syncAction: 'll_tools_offline_app_sync'
    }
  };

  await openStorageBackedPage(page);
  await page.evaluate((config) => {
    window.localStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = config;
    window.llToolsStudyData = {};
    window.__offlineReloadRequests = [];
    window.jQuery = {
      post() {
        const callbacks = { done: null, fail: null };
        const request = {
          done(callback) {
            callbacks.done = callback;
            return request;
          },
          fail(callback) {
            callbacks.fail = callback;
            return request;
          },
          rejectRetry() {
            if (!callbacks.fail) return;
            callbacks.fail({
              status: 503,
              responseJSON: {
                success: false,
                data: {
                  code: 'progress_transactional_engine_unavailable',
                  retryable: true,
                  retry_after: 1.1
                }
              },
              getResponseHeader(name) {
                return String(name).toLowerCase() === 'retry-after' ? '1.1' : null;
              }
            }, 'error', 'Service Unavailable');
          }
        };
        window.__offlineReloadRequests.push(request);
        return request;
      }
    };
  }, offlineConfig);
  await page.addScriptTag({ content: progressTrackerSource });

  const seeded = await page.evaluate(async (key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.setOfflineSyncSession({ auth_token: 'offline-reload-token', user: { id: 17 } });
    const eventId = tracker.trackCategoryStudy({ categoryId: 101, units: 1 });
    const flushPromise = tracker.flush();
    window.__offlineReloadRequests[0].rejectRetry();
    const failed = await flushPromise;
    return {
      eventId,
      failed,
      snapshot: JSON.parse(window.localStorage.getItem(key))
    };
  }, progressStoreKey);

  expect(seeded.failed).toMatchObject({
    failed: true,
    error: 'progress_transactional_engine_unavailable',
    retryable: true
  });
  expect(seeded.snapshot.queue.map(event => event.event_uuid)).toEqual([seeded.eventId]);
  expect(seeded.snapshot.sync_retry_not_before_ms).toBeGreaterThan(Date.now());
  expect(seeded.snapshot.sync_retry_failure_code).toBe('progress_transactional_engine_unavailable');

  await page.reload();
  await page.evaluate((config) => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = config;
    window.llToolsStudyData = {};
    window.__offlineReloadMock = { batches: [], requestTimes: [], scriptStartedAt: Date.now() };
    window.jQuery = {
      post(_url, payload) {
        const batch = JSON.parse(payload.events);
        window.__offlineReloadMock.batches.push(batch);
        window.__offlineReloadMock.requestTimes.push(Date.now());
        const request = {
          done(callback) {
            window.setTimeout(() => callback({
              success: true,
              data: {
                state: {},
                progress_words: {},
                stats: {
                  received: batch.length,
                  processed: batch.length,
                  duplicates: 0,
                  invalid: 0,
                  failed: 0,
                  failed_event_uuids: []
                }
              }
            }), 0);
            return request;
          },
          fail() {
            return request;
          }
        };
        return request;
      }
    };
  }, offlineConfig);
  await page.addScriptTag({ content: progressTrackerSource });

  await page.waitForTimeout(150);
  expect(await page.evaluate(() => window.__offlineReloadMock.batches.length)).toBe(0);
  await page.waitForFunction((key) => {
    const snapshot = JSON.parse(window.localStorage.getItem(key));
    return window.__offlineReloadMock.batches.length === 1
      && snapshot
      && Array.isArray(snapshot.queue)
      && snapshot.queue.length === 0;
  }, progressStoreKey, { timeout: 3000 });

  const restored = await page.evaluate((key) => ({
    sentIds: window.__offlineReloadMock.batches[0].map(event => event.event_uuid),
    requestDelayMs: window.__offlineReloadMock.requestTimes[0] - window.__offlineReloadMock.scriptStartedAt,
    state: window.LLFlashcards.ProgressTracker.getSyncState(),
    snapshot: JSON.parse(window.localStorage.getItem(key))
  }), progressStoreKey);
  expect(restored.sentIds).toEqual([seeded.eventId]);
  expect(restored.requestDelayMs).toBeGreaterThanOrEqual(400);
  expect(restored.state.backing_off).toBe(false);
  expect(restored.state.queued).toBe(0);
  expect(restored.snapshot.sync_retry_not_before_ms).toBe(0);
  expect(restored.snapshot.sync_retry_failure_code).toBe('');
});

test('authenticated progress journal automatically flushes exact restored UUIDs and clears them after acknowledgement', async ({ page }) => {
  const journalKey = `lltools_wp_progress_journal_v1::user:${progressScopeA}::wordset:77`;
  const nonce = 'journal-secret-nonce';

  await openStorageBackedPage(page);
  await page.evaluate(({ scope }) => {
    window.sessionStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'journal-secret-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  const seeded = await page.evaluate((key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const firstId = tracker.trackCategoryStudy({ categoryId: 41, units: 1 });
    const secondId = tracker.trackCategoryStudy({ categoryId: 42, units: 1 });
    return {
      firstId,
      secondId,
      raw: window.sessionStorage.getItem(key)
    };
  }, journalKey);

  expect(seeded.raw).not.toContain(nonce);
  expect(seeded.raw).not.toContain('/wp-admin/admin-ajax.php');
  expect(JSON.parse(seeded.raw).events.map(event => event.event_uuid)).toEqual([
    seeded.firstId,
    seeded.secondId
  ]);
  await page.evaluate((key) => {
    const snapshot = JSON.parse(window.sessionStorage.getItem(key));
    snapshot.events.splice(1, 0, snapshot.events[0]);
    window.sessionStorage.setItem(key, JSON.stringify(snapshot));
  }, journalKey);

  await page.reload();
  await page.evaluate(({ scope }) => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'fresh-request-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    window.__restoredProgressBatches = [];
    window.jQuery = {
      post(_url, payload) {
        const batch = JSON.parse(payload.events);
        window.__restoredProgressBatches.push(batch);
        const request = {
          done(callback) {
            window.setTimeout(() => callback({
              success: true,
              data: {
                stats: {
                  received: batch.length,
                  processed: batch.length,
                  duplicates: 0,
                  invalid: 0,
                  failed: 0,
                  failed_event_uuids: []
                }
              }
            }), 0);
            return request;
          },
          fail() {
            return request;
          }
        };
        return request;
      }
    };
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  await page.waitForFunction((key) => (
    Array.isArray(window.__restoredProgressBatches)
    && window.__restoredProgressBatches.length === 1
    && window.sessionStorage.getItem(key) === null
  ), journalKey);

  const restored = await page.evaluate((key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    return {
      sentIds: window.__restoredProgressBatches[0].map(event => event.event_uuid),
      journalAfterAck: window.sessionStorage.getItem(key),
      finalQueueSize: tracker.getQueueSize()
    };
  }, journalKey);

  expect(restored.sentIds).toEqual([seeded.firstId, seeded.secondId]);
  expect(restored.journalAfterAck).toBeNull();
  expect(restored.finalQueueSize).toBe(0);
});

test('authenticated progress journals isolate user scope and wordset across same-tab auth transitions', async ({ page }) => {
  await openStorageBackedPage(page);
  await page.evaluate(({ scope }) => {
    window.sessionStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'scope-a-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(({ scopeA, scopeB }) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const scopeAEventId = tracker.trackCategoryStudy({ categoryId: 51, units: 1 });

    tracker.setAuthContext({
      nonce: '',
      isUserLoggedIn: false,
      progressStorageScope: ''
    });
    const afterLogout = tracker.getQueueSize();

    tracker.setAuthContext({
      nonce: 'scope-b-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scopeB
    });
    const afterScopeBLogin = tracker.getQueueSize();
    const scopeBEventId = tracker.trackCategoryStudy({ categoryId: 52, units: 1 });

    tracker.setContext({ wordsetId: 78 });
    const afterWordsetChange = tracker.getQueueSize();
    const wordset78EventId = tracker.trackCategoryStudy({ categoryId: 53, units: 1 });

    tracker.setContext({ wordsetId: 77 });
    const restoredScopeBIds = tracker.getQueueSize();

    tracker.setAuthContext({
      nonce: '',
      isUserLoggedIn: false,
      progressStorageScope: ''
    });
    tracker.setAuthContext({
      nonce: 'scope-a-fresh-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scopeA
    });

    const keyA77 = `lltools_wp_progress_journal_v1::user:${scopeA}::wordset:77`;
    const keyB77 = `lltools_wp_progress_journal_v1::user:${scopeB}::wordset:77`;
    const keyB78 = `lltools_wp_progress_journal_v1::user:${scopeB}::wordset:78`;
    return {
      scopeAEventId,
      scopeBEventId,
      wordset78EventId,
      afterLogout,
      afterScopeBLogin,
      afterWordsetChange,
      restoredScopeBIds,
      restoredScopeAQueueSize: tracker.getQueueSize(),
      journalA77Ids: JSON.parse(window.sessionStorage.getItem(keyA77)).events.map(event => event.event_uuid),
      journalB77Ids: JSON.parse(window.sessionStorage.getItem(keyB77)).events.map(event => event.event_uuid),
      journalB78Ids: JSON.parse(window.sessionStorage.getItem(keyB78)).events.map(event => event.event_uuid)
    };
  }, { scopeA: progressScopeA, scopeB: progressScopeB });

  expect(result.afterLogout).toBe(0);
  expect(result.afterScopeBLogin).toBe(0);
  expect(result.afterWordsetChange).toBe(0);
  expect(result.restoredScopeBIds).toBe(1);
  expect(result.restoredScopeAQueueSize).toBe(1);
  expect(result.journalA77Ids).toEqual([result.scopeAEventId]);
  expect(result.journalB77Ids).toEqual([result.scopeBEventId]);
  expect(result.journalB78Ids).toEqual([result.wordset78EventId]);
});

test('progress storage failures degrade safely and missing sync credentials retain authenticated events in memory', async ({ page }) => {
  await openStorageBackedPage(page);
  await page.evaluate(({ scope }) => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'storage-failure-nonce',
      isUserLoggedIn: true,
      progressStorageScope: scope,
      wordsetIds: [77]
    };
    window.llToolsStudyData = {};
    const throwStorageError = () => {
      throw new Error('Storage disabled');
    };
    window.Storage.prototype.getItem = throwStorageError;
    window.Storage.prototype.setItem = throwStorageError;
    window.Storage.prototype.removeItem = throwStorageError;
    window.__storageFailurePosts = 0;
    window.jQuery = {
      post() {
        window.__storageFailurePosts += 1;
        const request = {
          done() {
            return request;
          },
          fail(callback) {
            window.setTimeout(() => callback({}, 'error', 'transport_failed'), 0);
            return request;
          }
        };
        return request;
      }
    };
  }, { scope: progressScopeA });
  await page.addScriptTag({ content: progressTrackerSource });

  const result = await page.evaluate(async () => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const eventId = tracker.trackCategoryStudy({ categoryId: 61, units: 1 });
    const failed = await tracker.flush();
    const afterFailure = tracker.getQueueSize();
    window.llToolsFlashcardsData.userStudyNonce = '';
    const deferred = await tracker.flush();
    return {
      eventId,
      failed,
      afterFailure,
      deferred,
      finalQueueSize: tracker.getQueueSize(),
      posts: window.__storageFailurePosts
    };
  });

  expect(result.eventId).toBeTruthy();
  expect(result.failed).toMatchObject({ failed: true, error: 'transport_failed' });
  expect(result.afterFailure).toBe(1);
  expect(result.deferred).toEqual({ queued: 1, deferred: true });
  expect(result.finalQueueSize).toBe(1);
  expect(result.posts).toBe(1);
});

test('offline local progress storage includes the active batch and newer queue for reload recovery', async ({ page }) => {
  const offlineProgressKey = 'lltools_offline_progress_v2::wordset:88';
  const offlineToken = 'offline-auth-secret';

  await openStorageBackedPage(page);
  await page.evaluate(() => {
    window.localStorage.clear();
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'offline',
      wordsetIds: [88],
      offlineSync: {
        enabled: true,
        ajaxUrl: '/offline-sync',
        syncAction: 'll_offline_progress_sync'
      }
    };
    window.llToolsStudyData = {};
    window.jQuery = {
      post(_url, payload) {
        window.__offlineFirstBatch = JSON.parse(payload.events);
        return {
          done() { return this; },
          fail() { return this; },
          abort() {}
        };
      }
    };
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const seeded = await page.evaluate(({ key, token }) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    tracker.setOfflineSyncSession({ auth_token: token });
    const inFlightId = tracker.trackCategoryStudy({ categoryId: 71, units: 1 });
    tracker.flush();
    const newerId = tracker.trackCategoryStudy({ categoryId: 72, units: 1 });
    const raw = window.localStorage.getItem(key);
    return {
      inFlightId,
      newerId,
      raw,
      storedIds: JSON.parse(raw).queue.map(event => event.event_uuid)
    };
  }, { key: offlineProgressKey, token: offlineToken });

  expect(seeded.storedIds).toEqual([seeded.inFlightId, seeded.newerId]);
  expect(seeded.raw).not.toContain(offlineToken);

  await page.reload();
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'offline',
      wordsetIds: [88],
      offlineSync: {
        enabled: true,
        ajaxUrl: '/offline-sync',
        syncAction: 'll_offline_progress_sync'
      }
    };
    window.llToolsStudyData = {};
    window.__offlineRestoredBatches = [];
    window.jQuery = {
      post(_url, payload) {
        const batch = JSON.parse(payload.events);
        window.__offlineRestoredBatches.push(batch);
        const request = {
          done(callback) {
            window.setTimeout(() => callback({
              success: true,
              data: {
                stats: {
                  received: batch.length,
                  processed: batch.length,
                  duplicates: 0,
                  invalid: 0,
                  failed: 0,
                  failed_event_uuids: []
                }
              }
            }), 0);
            return request;
          },
          fail() {
            return request;
          }
        };
        return request;
      }
    };
  });
  await page.addScriptTag({ content: progressTrackerSource });

  const restored = await page.evaluate(async (key) => {
    const tracker = window.LLFlashcards.ProgressTracker;
    const restoredQueueSize = tracker.getQueueSize();
    await tracker.flush();
    return {
      restoredQueueSize,
      sentIds: window.__offlineRestoredBatches[0].map(event => event.event_uuid),
      storedIdsAfterAck: JSON.parse(window.localStorage.getItem(key)).queue.map(event => event.event_uuid),
      finalQueueSize: tracker.getQueueSize()
    };
  }, offlineProgressKey);

  expect(restored.restoredQueueSize).toBe(2);
  expect(restored.sentIds).toEqual([seeded.inFlightId, seeded.newerId]);
  expect(restored.storedIdsAfterAck).toEqual([]);
  expect(restored.finalQueueSize).toBe(0);
});
