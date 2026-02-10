const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const listeningScriptPath = path.resolve(__dirname, '../../../js/flashcard-widget/modes/listening.js');

test('listening sequence de-duplicates shared words unless weighted mode allows a second starred play', async ({ page }) => {
  await page.goto('about:blank');

  const listeningSource = fs.readFileSync(listeningScriptPath, 'utf8');

  await page.evaluate(() => {
    window.llToolsFlashcardsData = {};
    window.llToolsStudyPrefs = { starredWordIds: [], starMode: 'normal', star_mode: 'normal' };
    window.LLFlashcards = {
      State: {
        STATES: {},
        categoryNames: [],
        wordsByCategory: {},
        wordsLinear: [],
        listeningHistory: [],
        listeningLoop: false,
        starModeOverride: null
      },
      Dom: {},
      Cards: {},
      Results: {},
      Util: {},
      Modes: {}
    };
  });

  await page.addScriptTag({ content: listeningSource });

  const result = await page.evaluate(() => {
    const State = window.LLFlashcards.State;
    const Listening = window.LLFlashcards.Modes.Listening;

    if (!Listening || typeof Listening.initialize !== 'function') {
      return { error: 'missing listening module' };
    }

    State.wordsByCategory = {
      CatA: [
        { id: 101, title: 'alpha', label: 'alpha', audio: 'alpha.mp3', __categoryName: 'CatA' },
        { id: 202, title: 'beta', label: 'beta', audio: 'beta.mp3', __categoryName: 'CatA' }
      ],
      CatB: [
        { id: 101, title: 'alpha', label: 'alpha', audio: 'alpha.mp3', __categoryName: 'CatB' }
      ]
    };
    State.categoryNames = ['CatA', 'CatB'];
    State.starModeOverride = null;

    window.llToolsStudyPrefs = {
      starredWordIds: [101],
      starMode: 'normal',
      star_mode: 'normal'
    };
    Listening.initialize();
    const normalIds = (Array.isArray(State.wordsLinear) ? State.wordsLinear : [])
      .map((w) => Number(w && w.id) || 0)
      .filter((id) => id > 0);

    window.llToolsStudyPrefs = {
      starredWordIds: [101],
      starMode: 'weighted',
      star_mode: 'weighted'
    };
    Listening.initialize();
    const weightedIds = (Array.isArray(State.wordsLinear) ? State.wordsLinear : [])
      .map((w) => Number(w && w.id) || 0)
      .filter((id) => id > 0);

    return { normalIds, weightedIds };
  });

  expect(result.error).toBeUndefined();

  const normalCount101 = result.normalIds.filter((id) => id === 101).length;
  const normalCount202 = result.normalIds.filter((id) => id === 202).length;
  expect(normalCount101).toBe(1);
  expect(normalCount202).toBe(1);
  expect(result.normalIds.length).toBe(2);

  const weightedCount101 = result.weightedIds.filter((id) => id === 101).length;
  const weightedCount202 = result.weightedIds.filter((id) => id === 202).length;
  expect(weightedCount101).toBe(2);
  expect(weightedCount202).toBe(1);
  expect(result.weightedIds.length).toBe(3);
});
