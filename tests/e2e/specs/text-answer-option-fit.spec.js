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

async function mountTextCardHarness(page, imageSize = 'small') {
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
  await page.evaluate((size) => {
    window.llToolsFlashcardsData.imageSize = String(size || 'small');
  }, imageSize);
  await page.addScriptTag({ content: cardsSource });
}

test('text answer cards shrink long single words instead of splitting them', async ({ page }) => {
  await mountTextCardHarness(page, 'small');
  const metrics = await page.evaluate(() => {
    const card = window.LLFlashcards.Cards.appendWordToContainer(
      {
        id: 101,
        title: 'anneanneanneanne',
        label: 'anneanneanneanne'
      },
      'text_translation',
      'image',
      true
    );
    if (card && typeof card.css === 'function') {
      card.css('display', '');
    }
    if (window.LLFlashcards.Cards && typeof window.LLFlashcards.Cards.refitTextAnswerOptionCards === 'function') {
      window.LLFlashcards.Cards.refitTextAnswerOptionCards();
    }

    const label = document.querySelector('#ll-tools-flashcard .ll-answer-option-text-card .quiz-text');
    if (!label) {
      return null;
    }

    const style = window.getComputedStyle(label);
    return {
      whiteSpace: style.whiteSpace,
      justifyContent: style.justifyContent,
      alignItems: style.alignItems,
      fontSize: parseFloat(style.fontSize || '0'),
      clientWidth: Math.ceil(label.clientWidth || 0),
      scrollWidth: Math.ceil(label.scrollWidth || 0)
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.whiteSpace).toBe('nowrap');
  expect(metrics.justifyContent).toBe('center');
  expect(metrics.alignItems).toBe('center');
  expect(metrics.fontSize).toBeLessThan(48);
  expect(metrics.fontSize).toBeGreaterThan(12);
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1);
});

test('text answer cards keep phrase-based labels centered and shrink to fit', async ({ page }) => {
  await mountTextCardHarness(page, 'medium');
  const metrics = await page.evaluate(() => {
    const card = window.LLFlashcards.Cards.appendWordToContainer(
      {
        id: 102,
        title: 'Teyze oğlu teyze',
        label: 'Teyze oğlu teyze'
      },
      'text_translation',
      'image',
      true
    );
    if (card && typeof card.css === 'function') {
      card.css('display', '');
    }
    if (window.LLFlashcards.Cards && typeof window.LLFlashcards.Cards.refitTextAnswerOptionCards === 'function') {
      window.LLFlashcards.Cards.refitTextAnswerOptionCards();
    }

    const label = document.querySelector('#ll-tools-flashcard .ll-answer-option-text-card .quiz-text');
    if (!label) {
      return null;
    }

    const style = window.getComputedStyle(label);
    return {
      whiteSpace: style.whiteSpace,
      justifyContent: style.justifyContent,
      alignItems: style.alignItems,
      fontSize: parseFloat(style.fontSize || '0'),
      clientWidth: Math.ceil(label.clientWidth || 0),
      scrollWidth: Math.ceil(label.scrollWidth || 0)
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.whiteSpace).toBe('nowrap');
  expect(metrics.justifyContent).toBe('center');
  expect(metrics.alignItems).toBe('center');
  expect(metrics.fontSize).toBeLessThan(48);
  expect(metrics.fontSize).toBeGreaterThan(20);
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1);
});
