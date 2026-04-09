const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const utilSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/util.js'),
  'utf8'
);

test('flashcard utility shuffle uses a deterministic Fisher-Yates pass for a fixed random sequence', async ({ page }) => {
  await page.goto('about:blank');

  await page.evaluate(() => {
    const values = [0.75, 0.25, 0.9];
    let index = 0;
    Math.random = function () {
      const fallback = values[values.length - 1];
      const next = index < values.length ? values[index] : fallback;
      index += 1;
      return next;
    };
  });

  await page.addScriptTag({ content: utilSource });

  const result = await page.evaluate(() => {
    const source = [1, 2, 3, 4];
    const shuffled = window.LLFlashcards.Util.randomlySort(source);
    return {
      source,
      shuffled
    };
  });

  expect(result.source).toEqual([1, 2, 3, 4]);
  expect(result.shuffled).toEqual([3, 2, 1, 4]);
});
