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
    const loopAfterInitialize = !!State.listeningLoop;
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

    State.listeningHistory = [];
    State.listenIndex = weightedIds.length;
    const endTarget = Listening.selectTargetWord();

    return { loopAfterInitialize, normalIds, weightedIds, endTarget: endTarget || null };
  });

  expect(result.error).toBeUndefined();
  expect(result.loopAfterInitialize).toBe(false);

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
  expect(result.endTarget).toBeNull();
});

test('listening sequence keeps category words grouped in contiguous blocks', async ({ page }) => {
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
        { id: 101, title: 'a1', label: 'a1', audio: 'a1.mp3' },
        { id: 102, title: 'a2', label: 'a2', audio: 'a2.mp3' },
        { id: 103, title: 'a3', label: 'a3', audio: 'a3.mp3' }
      ],
      CatB: [
        { id: 201, title: 'b1', label: 'b1', audio: 'b1.mp3' },
        { id: 202, title: 'b2', label: 'b2', audio: 'b2.mp3' }
      ],
      CatC: [
        { id: 301, title: 'c1', label: 'c1', audio: 'c1.mp3' }
      ]
    };
    State.categoryNames = ['CatA', 'CatB', 'CatC'];
    State.starModeOverride = null;

    window.llToolsStudyPrefs = {
      starredWordIds: [],
      starMode: 'normal',
      star_mode: 'normal'
    };

    Listening.initialize();
    const linear = Array.isArray(State.wordsLinear) ? State.wordsLinear : [];
    const pairs = linear.map((word) => ({
      id: Number(word && word.id) || 0,
      cat: String((word && word.__categoryName) || '')
    })).filter((row) => row.id > 0 && row.cat !== '');

    const collapsedCats = [];
    pairs.forEach((row) => {
      if (!collapsedCats.length || collapsedCats[collapsedCats.length - 1] !== row.cat) {
        collapsedCats.push(row.cat);
      }
    });

    const positionsByCat = {};
    pairs.forEach((row, idx) => {
      if (!positionsByCat[row.cat]) {
        positionsByCat[row.cat] = [];
      }
      positionsByCat[row.cat].push(idx);
    });

    return {
      pairs,
      collapsedCats,
      positionsByCat
    };
  });

  expect(result.error).toBeUndefined();
  expect(result.pairs.length).toBe(6);

  const collapsed = Array.isArray(result.collapsedCats) ? result.collapsedCats : [];
  const uniqueCollapsed = Array.from(new Set(collapsed));
  expect(collapsed.length).toBe(uniqueCollapsed.length);
  expect(uniqueCollapsed.sort()).toEqual(['CatA', 'CatB', 'CatC'].sort());

  const positionsByCat = result.positionsByCat || {};
  Object.keys(positionsByCat).forEach((cat) => {
    const positions = Array.isArray(positionsByCat[cat]) ? positionsByCat[cat] : [];
    if (!positions.length) {
      return;
    }
    const min = Math.min(...positions);
    const max = Math.max(...positions);
    expect((max - min + 1)).toBe(positions.length);
  });
});
