const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const progressTrackerSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/progress-tracker.js'),
  'utf8'
);

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
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-test-nonce',
      isUserLoggedIn: true
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

    return {
      processedId,
      duplicateId,
      failedId,
      newerId,
      firstResult,
      secondBatch,
      fallbackProcessedId,
      fallbackFailedId,
      fallbackNewerId,
      fallbackFirstResult,
      fallbackRetryBatch,
      finalQueueSize: tracker.getQueueSize()
    };
  });

  expect(result.firstResult.partial_failure).toBe(true);
  expect(result.firstResult.failed_event_uuids).toEqual([result.failedId]);
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
  expect(result.finalQueueSize).toBe(0);
});

test('progress tracker times out a stalled sync and retries the same event cleanly', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.llToolsFlashcardsData = {
      runtimeMode: 'wp',
      ajaxurl: '/wp-admin/admin-ajax.php',
      userStudyNonce: 'progress-test-nonce',
      isUserLoggedIn: true,
      progressSyncTimeoutMs: 50
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
    const timedOut = await tracker.flush();

    const retryPromise = tracker.flush();
    const retryBatch = window.__progressTimeoutMock.batches[1].map(event => event.event_uuid);
    window.__progressTimeoutMock.requests[1].resolve({
      success: true,
      data: {
        stats: {
          received: 1,
          processed: 1,
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
      timedOut,
      retryBatch,
      retried,
      aborts: window.__progressTimeoutMock.aborts,
      finalQueueSize: tracker.getQueueSize()
    };
  });

  expect(result.timedOut).toMatchObject({
    failed: true,
    timed_out: true,
    error: 'request_timeout'
  });
  expect(result.retryBatch).toEqual([result.eventId]);
  expect(result.retried.partial_failure).toBe(false);
  expect(result.aborts).toBe(1);
  expect(result.finalQueueSize).toBe(0);
});
