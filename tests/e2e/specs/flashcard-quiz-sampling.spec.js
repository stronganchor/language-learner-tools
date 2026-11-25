const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const jquery = fs.readFileSync(require.resolve('jquery'), 'utf8');
const loader = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/loader.js'), 'utf8');

async function mount(page) {
  await page.goto('about:blank');
  await page.addScriptTag({ content: jquery });
  await page.evaluate(() => {
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.categoryRoundCount = {};
    window.categoryNames = ['Forms'];
    window.getCategoryDisplayMode = () => 'text_translation';
    window.llToolsFlashcardsData = {
      ajaxurl: '/fake', wordset: 'hebrew', wordsetIds: [1], wordsetFallback: false,
      categories: [{ id: 11, name: 'Forms', slug: 'forms', prompt_type: 'text_title', option_type: 'text_translation', question_limit: 15 }]
    };
    window.bank = Array.from({ length: 73 }, (_, i) => ({ id: i + 1, title: 'Qatal ' + i, translation: 'Vayyiqtol ' + i, wordset_ids: [1] }));
    window.requests = [];
    window.jQuery.ajax = (opts) => {
      window.requests.push({ ...opts.data });
      const start = parseInt(opts.data.cursor || 0, 10);
      const total = window.llToolsFlashcardsData.categories[0].question_limit;
      const targets = window.bank.slice(start, Math.min(start + 30, total));
      const data = opts.data.sample_seed ? {
        rows: targets.concat(window.bank.slice(65)), sample_target_ids: targets.map(w => w.id),
        sample_total: total, next_cursor: start + 30 < total ? String(start + 30) : ''
      } : { rows: window.bank, next_cursor: '' };
      setTimeout(() => opts.success({ success: true, data }), 0);
      return { abort() {} };
    };
  });
  await page.addScriptTag({ content: loader });
}

test('15 exact targets retain out-of-sample distractors; retry keeps seed, repeat changes it', async ({ page }) => {
  await mount(page);
  const result = await page.evaluate(async () => {
    const l = window.FlashcardLoader;
    const run = () => l.loadResourcesForCategory('Forms', null, { skipCategoryPreload: true });
    l.beginQuizAttempt('practice'); await run();
    const firstSeed = requests[0].sample_seed;
    const targetIds = wordsByCategory.Forms.map(w => w.id).sort((a,b) => a-b);
    const optionIds = optionWordsByCategory.Forms.map(w => w.id);
    l.resetCacheForNewWordset(); l.beginQuizAttempt('practice', { preserveSample: true }); await run();
    const retrySeed = requests[1].sample_seed;
    l.beginQuizAttempt('practice'); await run();
    return { firstSeed, retrySeed, repeatSeed: requests[2].sample_seed, targetIds, optionIds };
  });
  expect(result.targetIds).toEqual(Array.from({length:15}, (_,i) => i+1));
  expect(result.optionIds).toContain(73);
  expect(result.firstSeed).toMatch(/^[a-f0-9]{32}$/);
  expect(result.retrySeed).toBe(result.firstSeed);
  expect(result.repeatSeed).not.toBe(result.firstSeed);
});

test('large custom count pages and deduplicates option rows', async ({ page }) => {
  await mount(page);
  const result = await page.evaluate(async () => {
    llToolsFlashcardsData.categories[0].question_limit = 65;
    FlashcardLoader.beginQuizAttempt('practice');
    await FlashcardLoader.loadResourcesForCategory('Forms', null, { skipCategoryPreload: true });
    return { targets: wordsByCategory.Forms.map(w=>w.id), options: optionWordsByCategory.Forms.map(w=>w.id), calls: requests.length };
  });
  expect(result.targets).toHaveLength(65);
  expect(new Set(result.targets).size).toBe(65);
  expect(new Set(result.options).size).toBe(result.options.length);
  expect(result.calls).toBe(3);
});

test('other modes and explicit selections bypass sampling', async ({ page }) => {
  await mount(page);
  const result = await page.evaluate(async () => {
    const limits = [];
    ['learning', 'listening', 'self-check', 'gender'].forEach(mode => {
      FlashcardLoader.beginQuizAttempt(mode); limits.push(FlashcardLoader.getQuestionLimit('Forms'));
    });
    FlashcardLoader.beginQuizAttempt('practice');
    llToolsFlashcardsData.sessionWordIds = [1,2,3,4,5];
    limits.push(FlashcardLoader.getQuestionLimit('Forms'));
    return limits;
  });
  expect(result).toEqual([0,0,0,0,0]);
});

test('sample membership uses prompt item IDs even when prompts share an answer', async ({ page }) => {
  await mount(page);
  const ids = await page.evaluate(() => {
    FlashcardLoader.processFetchedWordData([
      { id: 101, answer_word_id: 1, progress_word_id: 1, is_prompt_card: true, wordset_ids: [1] },
      { id: 102, answer_word_id: 1, progress_word_id: 1, is_prompt_card: true, wordset_ids: [1] },
      { id: 1, is_prompt_card_support_only: true, wordset_ids: [1] }
    ], 'Forms', { targetItemIds: [101] });
    return wordsByCategory.Forms.map(w=>w.id);
  });
  expect(ids).toEqual([101]);
});

test('offline attempts sample once, repeat changes selection, and retain the full answer pool', async ({ page }) => {
  await mount(page);
  const result = await page.evaluate(async () => {
    llToolsFlashcardsData.runtimeMode = 'offline';
    llToolsFlashcardsData.offlineCategoryData = { Forms: bank };
    FlashcardLoader.beginQuizAttempt('practice');
    await FlashcardLoader.loadResourcesForCategory('Forms', null, { skipCategoryPreload: true });
    const first = wordsByCategory.Forms.map(w=>w.id).sort((a,b)=>a-b);
    FlashcardLoader.beginQuizAttempt('practice');
    await FlashcardLoader.loadResourcesForCategory('Forms', null, { skipCategoryPreload: true });
    return { first, next: wordsByCategory.Forms.map(w=>w.id).sort((a,b)=>a-b), options: optionWordsByCategory.Forms.length, calls: requests.length };
  });
  expect(result.first).toHaveLength(15);
  expect(result.next).toHaveLength(15);
  expect(result.next).not.toEqual(result.first);
  expect(result.options).toBe(73);
  expect(result.calls).toBe(0);
});
