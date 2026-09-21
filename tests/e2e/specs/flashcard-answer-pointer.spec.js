const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const cardsSource = fs.readFileSync(path.resolve(__dirname, '../../../js/flashcard-widget/cards.js'), 'utf8');

async function mountCards(page) {
  await page.goto('about:blank');
  await page.setContent(`
    <style>
      #ll-tools-flashcard { display: flex; justify-content: center; gap: 8px; width: 600px; }
      .flashcard-container { flex: 0 0 120px; height: 100px; }
    </style>
    <div id="ll-tools-flashcard-content"><div id="ll-tools-flashcard"></div></div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsFlashcardsData = {};
    window.answers = [];
    window.inputEvents = [];
    ['pointerup', 'click'].forEach(type => document.addEventListener(type, event => {
      window.inputEvents.push({ type, word: event.target.closest('.flashcard-container')?.dataset.wordId });
    }, true));
    window.LLFlashcards = {
      Util: {}, State: {}, Dom: {},
      Main: {
        onWrongAnswer(target, index, card) {
          window.answers.push({ correct: false, word: Number(card.attr('data-word-id')) });
          card.remove();
          // Practice removes the other distractors immediately on the second mistake.
          if (window.answers.length === 2) {
            window.jQuery('.flashcard-container').not('[data-word-id="104"]').remove();
          }
        },
        onCorrectAnswer(target) { window.answers.push({ correct: true, word: target.id }); }
      }
    };
  });
  await page.addScriptTag({ content: cardsSource });
  await page.evaluate(() => {
    [101, 102, 103, 104].forEach((id, index) => {
      const card = window.jQuery('<button>', { class: 'flashcard-container', 'data-word-id': id, text: id });
      window.jQuery('#ll-tools-flashcard').append(card);
      window.LLFlashcards.Cards.addClickEventToCard(card, index, { id: 104 }, 'image', 'audio');
    });
  });
}

test.describe('answer pointer ownership', () => {
  test.use({ hasTouch: true });

  test('one touch remains one wrong answer when removing distractors moves the correct card under the tap', async ({ page }) => {
    await mountCards(page);
    await page.locator('[data-word-id="101"]').tap();
    await page.locator('[data-word-id="103"]').tap();

    expect(await page.evaluate(() => window.answers)).toEqual([
      { correct: false, word: 101 },
      { correct: false, word: 103 }
    ]);
    // Native touch synthesized a click on the now-repositioned correct card.
    expect(await page.evaluate(() => window.inputEvents.slice(-2))).toEqual([
      { type: 'pointerup', word: '103' },
      { type: 'click', word: '104' }
    ]);
    await page.locator('[data-word-id="104"]').tap();
    expect(await page.evaluate(() => window.answers.at(-1))).toEqual({ correct: true, word: 104 });
    expect(await page.evaluate(() => window.answers.length)).toBe(3);
  });

  test('mouse answers remain single and keyboard or accessible clicks work immediately afterward', async ({ page }) => {
    await mountCards(page);
    await page.locator('[data-word-id="104"]').click();
    expect(await page.evaluate(() => window.answers.length)).toBe(1);
    await page.locator('[data-word-id="104"]').press('Enter');
    expect(await page.evaluate(() => window.answers.length)).toBe(2);
    await page.locator('[data-word-id="104"]').evaluate(el => el.click());
    expect(await page.evaluate(() => window.answers.length)).toBe(3);
  });
});
