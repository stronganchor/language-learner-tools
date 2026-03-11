const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const cardsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/cards.js'),
  'utf8'
);
const flashcardCss = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);

test('text answer cards shrink long single words instead of splitting them', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <style>${flashcardCss}</style>
    <div id="ll-tools-flashcard-container">
      <div id="ll-tools-flashcard"></div>
    </div>
  `);

  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.jQuery.fx.off = true;
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      answerOptionTextStyle: {
        fontFamily: 'Arial',
        fontWeight: '700',
        fontSizePx: 48,
        minFontSizePx: 10,
        lineHeightRatio: 1.22,
        lineHeightRatioWithDiacritics: 1.4
      }
    };
    window.LLFlashcards = {
      State: {},
      Dom: {}
    };
  });
  await page.addScriptTag({ content: cardsSource });

  const metrics = await page.evaluate(() => {
    window.LLFlashcards.Cards.appendWordToContainer(
      {
        id: 101,
        title: 'anneanneanneanne',
        label: 'anneanneanneanne'
      },
      'text_translation',
      'image',
      true
    );

    const label = document.querySelector('#ll-tools-flashcard .ll-answer-option-text-card .quiz-text');
    if (!label) {
      return null;
    }

    const style = window.getComputedStyle(label);
    return {
      whiteSpace: style.whiteSpace,
      fontSize: parseFloat(style.fontSize || '0'),
      clientWidth: Math.ceil(label.clientWidth || 0),
      scrollWidth: Math.ceil(label.scrollWidth || 0)
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.whiteSpace).toBe('nowrap');
  expect(metrics.fontSize).toBeLessThan(48);
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1);
});
