const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const selectionScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);

test('selection gender support accepts truthy/falsey gender_supported flags', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<!doctype html><html><body></body></html>');
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      genderEnabled: true,
      genderOptions: ['masculine', 'feminine'],
      genderMinCount: 2,
      categories: [
        { name: 'Cat A', gender_supported: '1' },
        { name: 'Cat B', gender_supported: 0 },
        { name: 'Cat C', gender_supported: 'true' }
      ]
    };

    window.llToolsStudyPrefs = {};
    window.LLFlashcards = {
      Util: {
        randomlySort: (arr) => (Array.isArray(arr) ? arr.slice() : [])
      },
      State: {
        DEFAULT_DISPLAY_MODE: 'image',
        categoryNames: ['Cat A', 'Cat B', 'Cat C'],
        wordsByCategory: {},
        usedWordIDs: [],
        completedCategories: {},
        starPlayCounts: {}
      }
    };
  });

  await page.addScriptTag({ content: selectionScriptSource });

  const support = await page.evaluate(() => {
    const Selection = window.LLFlashcards && window.LLFlashcards.Selection;
    return {
      catA: !!(Selection && Selection.isGenderSupportedForCategories(['Cat A'])),
      catB: !!(Selection && Selection.isGenderSupportedForCategories(['Cat B'])),
      catC: !!(Selection && Selection.isGenderSupportedForCategories(['Cat C'])),
      mixed: !!(Selection && Selection.isGenderSupportedForCategories(['Cat B', 'Cat C']))
    };
  });

  expect(support.catA).toBe(true);
  expect(support.catB).toBe(false);
  expect(support.catC).toBe(true);
  expect(support.mixed).toBe(true);
});
