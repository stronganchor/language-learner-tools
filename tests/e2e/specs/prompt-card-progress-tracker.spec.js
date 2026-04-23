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
