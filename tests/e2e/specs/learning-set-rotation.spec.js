const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const stateSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/state.js'), 'utf8');
const learningSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/modes/learning.js'), 'utf8');

test.use({ timezoneId: 'Pacific/Honolulu' });

async function mountLearning(page, wordsByCategory, categoryNames = Object.keys(wordsByCategory)) {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({ content: stateSource });
  await page.evaluate(({ words, names }) => {
    window.LLFlashcards.State.categoryNames = names;
    window.LLFlashcards.State.wordsByCategory = words;
    window.LLFlashcards.Selection = {
      isWordBlockedFromPromptRounds(word) { return !!word.blocked; },
      getCategoryConfig() { return { prompt_type: 'text_title', option_type: 'text_translation' }; }
    };
  }, { words: wordsByCategory, names: categoryNames });
  await page.addScriptTag({ content: learningSource });
}

function words(count, lastSeenAt = '') {
  return Array.from({ length: count }, (_, index) => ({
    id: index + 1,
    progress_last_seen_at: lastSeenAt
  }));
}

test('a fresh Learning launch rotates an answered set behind the remaining cached words', async ({ page }) => {
  await mountLearning(page, { A: words(36, '2026-08-01 00:00:00') });
  await page.clock.install({ time: new Date('2026-11-09T12:00:00Z') });

  const result = await page.evaluate(() => {
    const state = window.LLFlashcards.State;
    const learning = window.LLFlashcards.Modes.Learning;
    learning.initialize();
    const first = state.wordsToIntroduce.slice();
    state.introducedWordIDs = first.slice();
    first.forEach(id => {
      for (let answer = 0; answer < state.MIN_CORRECT_COUNT; answer += 1) {
        learning.recordAnswerResult(id, true);
      }
    });
    const completed = first.every(id => state.wordCorrectCounts[id] >= state.MIN_CORRECT_COUNT);
    const lastSeen = state.wordsByCategory.A.filter(word => first.includes(word.id)).map(word => word.progress_last_seen_at);
    state.reset();
    learning.initialize();
    return {
      first,
      second: state.wordsToIntroduce.slice(),
      allSets: state.learningWordSets.map(set => set.slice()),
      completed,
      lastSeen
    };
  });

  expect(result.completed).toBe(true);
  expect(result.first).toEqual(Array.from({ length: 12 }, (_, index) => index + 1));
  expect(result.second).toEqual(Array.from({ length: 12 }, (_, index) => index + 13));
  expect(result.second.filter(id => result.first.includes(id))).toEqual([]);
  expect(result.lastSeen).toEqual(Array(12).fill('2026-11-09 12:00:00'));
  expect(result.allSets.flat().sort((a, b) => a - b)).toEqual(Array.from({ length: 36 }, (_, index) => index + 1));
});

test('fresh sets prefer oldest study timestamps and parse MySQL timestamps as UTC', async ({ page }) => {
  const rows = words(24, '2026-11-09T11:00:00Z');
  rows.slice(12).forEach(word => { word.progress_last_seen_at = '2026-11-09 10:00:00'; });
  await mountLearning(page, { A: rows });

  const first = await page.evaluate(() => {
    window.LLFlashcards.Modes.Learning.initialize();
    return window.LLFlashcards.State.wordsToIntroduce;
  });

  expect(first).toEqual(Array.from({ length: 12 }, (_, index) => index + 13));
});

test('duplicate category copies share their newest recency while missing dates keep stable order', async ({ page }) => {
  const rows = words(18);
  rows[1].progress_last_seen_at = 'invalid date';
  rows[2].progress_last_seen_at = '0000-00-00 00:00:00';
  rows.push({ id: 19, blocked: true });
  await mountLearning(page, {
    A: rows,
    B: [{ id: '1', progress_last_seen_at: '2026-11-09 12:00:00' }]
  });

  const result = await page.evaluate(() => {
    window.LLFlashcards.Modes.Learning.initialize();
    return {
      first: window.LLFlashcards.State.wordsToIntroduce,
      allSets: window.LLFlashcards.State.learningWordSets
    };
  });

  expect(result.first).toEqual([2, 3, 4, 5, 6, 7, 8, 9, 10]);
  expect(result.allSets.flat()).toEqual([2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 1]);
});

test('study updates keep the current set, progress and results Next ordering stable', async ({ page }) => {
  await mountLearning(page, { A: words(24) });

  const result = await page.evaluate(() => {
    const state = window.LLFlashcards.State;
    const learning = window.LLFlashcards.Modes.Learning;
    learning.initialize();
    const first = state.wordsToIntroduce.slice();
    const expectedNext = state.learningWordSets[1].slice();
    state.introducedWordIDs = first.slice();
    first.forEach(id => learning.recordAnswerResult(id, true));
    learning.initialize();
    const current = state.wordsToIntroduce.slice();
    const counts = Object.assign({}, state.wordCorrectCounts);
    const introduced = state.introducedWordIDs.slice();
    const startedNext = learning.startNextSet();
    return { first, current, counts, introduced, expectedNext, startedNext, next: state.wordsToIntroduce.slice() };
  });

  expect(result.current).toEqual(result.first);
  expect(result.introduced).toEqual(result.first);
  expect(Object.values(result.counts)).toEqual(Array(12).fill(1));
  expect(result.startedNext).toBe(true);
  expect(result.next).toEqual(result.expectedNext);
});

test('ordering uses only selected categories and keeps a bounded chunk intact', async ({ page }) => {
  await mountLearning(page, {
    A: words(15),
    Unselected: [{ id: 1, progress_last_seen_at: '2026-11-09 12:00:00' }, { id: 100 }]
  }, ['A']);

  const result = await page.evaluate(() => {
    window.LLFlashcards.Modes.Learning.initialize();
    return {
      sets: window.LLFlashcards.State.learningWordSets,
      total: window.LLFlashcards.State.totalWordCount
    };
  });

  expect(result.sets).toEqual([Array.from({ length: 15 }, (_, index) => index + 1)]);
  expect(result.total).toBe(15);
});
