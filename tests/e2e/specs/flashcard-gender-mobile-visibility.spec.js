const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const cardsScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/cards.js'),
  'utf8'
);
const selectionScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);
const baseCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);
const genderCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/mode-gender.css'),
  'utf8'
);

test('gender mode reveals answer options on a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <div id="ll-tools-flashcard-content">
          <div id="ll-tools-prompt" class="ll-tools-prompt" style="display:none;"></div>
          <div id="ll-tools-flashcard"></div>
        </div>
      </body>
    </html>
  `);

  await page.addScriptTag({ content: jquerySource });
  await page.addStyleTag({
    content: `${baseCssSource}\n${genderCssSource}\n.screen-reader-text{position:absolute !important;width:1px !important;height:1px !important;padding:0 !important;margin:-1px !important;overflow:hidden !important;clip:rect(0,0,0,0) !important;white-space:nowrap !important;border:0 !important;}`
  });

  await page.evaluate(() => {
    const masculineSvg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="30" y="18" width="16" height="64" rx="6" fill="currentColor"/><circle cx="38" cy="12" r="8" fill="currentColor"/></svg>';
    const feminineSvg = '<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="50" cy="28" r="16" fill="currentColor"/><rect x="44" y="44" width="12" height="34" rx="5" fill="currentColor"/><rect x="28" y="78" width="44" height="10" rx="4" fill="currentColor"/></svg>';
    const targetWord = {
      id: 101,
      title: 'kase',
      label: 'kase',
      image: 'table.jpg',
      grammatical_gender: 'eril',
      part_of_speech: ['noun'],
      all_categories: ['Cat A']
    };

    window.__llGenderTargetWord = targetWord;
    window.llToolsStudyPrefs = {};
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      genderEnabled: true,
      genderOptions: ['eril', 'dişil'],
      genderVisualConfig: {
        options: [
          {
            value: 'eril',
            normalized: 'eril',
            label: 'Eril',
            role: 'masculine',
            color: '#1D4D99',
            style: '--ll-gender-accent:#1D4D99;--ll-gender-bg:rgba(29,77,153,0.14);--ll-gender-border:rgba(29,77,153,0.38);',
            symbol: { type: 'svg', value: masculineSvg }
          },
          {
            value: 'dişil',
            normalized: 'dişil',
            label: 'Dişil',
            role: 'feminine',
            color: '#EC4899',
            style: '--ll-gender-accent:#EC4899;--ll-gender-bg:rgba(236,72,153,0.14);--ll-gender-border:rgba(236,72,153,0.38);',
            symbol: { type: 'svg', value: feminineSvg }
          }
        ]
      },
      categories: [
        {
          name: 'Cat A',
          prompt_type: 'text_title',
          option_type: 'image',
          gender_supported: true
        }
      ]
    };

    window.LLFlashcards = {
      Util: {
        randomlySort: (arr) => (Array.isArray(arr) ? arr.slice() : [])
      },
      State: {
        DEFAULT_DISPLAY_MODE: 'image',
        categoryNames: ['Cat A'],
        wordsByCategory: { 'Cat A': [targetWord] },
        currentCategoryName: 'Cat A',
        currentCategory: [targetWord],
        usedWordIDs: [],
        completedCategories: {},
        starPlayCounts: {},
        wrongIndexes: [],
        isLearningMode: false,
        isListeningMode: false,
        isGenderMode: true,
        isSelfCheckMode: false
      },
      Dom: {},
      Main: {
        onGenderAnswer: function () {}
      },
      Modes: {
        Gender: {
          isAnswerTapGuardActive: function () {
            return false;
          }
        }
      }
    };

    window.FlashcardAudio = {
      getTargetAudioHasPlayed: function () {
        return true;
      },
      pauseAllAudio: function () {},
      createAudio: function (url) {
        return {
          src: String(url || ''),
          addEventListener: function () {},
          removeEventListener: function () {}
        };
      },
      playAudio: function () {
        return Promise.resolve();
      }
    };
  });

  await page.addScriptTag({ content: cardsScriptSource });
  await page.addScriptTag({ content: selectionScriptSource });

  await page.evaluate(async () => {
    await Promise.resolve(window.LLFlashcards.Selection.fillQuizOptions(window.__llGenderTargetWord));
  });

  await page.waitForFunction(() => {
    const cards = Array.from(document.querySelectorAll('#ll-tools-flashcard .ll-gender-option'));
    if (cards.length !== 3) {
      return false;
    }
    return cards.every((card) => {
      const style = window.getComputedStyle(card);
      return style.display !== 'none' && style.visibility === 'visible';
    });
  });

  const cards = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .ll-gender-option')).map((card) => {
      const style = window.getComputedStyle(card);
      const rect = card.getBoundingClientRect();
      const textEl = card.querySelector('.quiz-text');
      const innerEl = card.querySelector('.ll-gender-option-inner');
      const symbolEl = card.querySelector('.ll-gender-symbol');
      const labelEl = card.querySelector('.ll-gender-option-label');
      const textStyle = textEl ? window.getComputedStyle(textEl) : null;
      const innerRect = innerEl ? innerEl.getBoundingClientRect() : null;
      const symbolRect = symbolEl ? symbolEl.getBoundingClientRect() : null;
      const labelRect = labelEl ? labelEl.getBoundingClientRect() : null;
      return {
        role: card.getAttribute('data-ll-gender-role') || '',
        display: style.display,
        visibility: style.visibility,
        width: rect.width,
        height: rect.height,
        fontSize: textStyle ? parseFloat(textStyle.fontSize || '0') : 0,
        remainingX: textEl && innerRect ? (textEl.clientWidth - innerRect.width) : 0,
        symbolWidth: symbolRect ? symbolRect.width : 0,
        symbolHeight: symbolRect ? symbolRect.height : 0,
        symbolCenterY: symbolRect ? (symbolRect.top + (symbolRect.height / 2)) : 0,
        labelCenterY: labelRect ? (labelRect.top + (labelRect.height / 2)) : 0
      };
    });
  });

  expect(cards).toHaveLength(3);
  cards.forEach((card) => {
    expect(card.display).not.toBe('none');
    expect(card.visibility).toBe('visible');
    expect(card.width).toBeGreaterThan(0);
    expect(card.height).toBeGreaterThan(0);
    expect(card.fontSize).toBeGreaterThan(18);
  });

  const genderCards = cards.filter((card) => card.role === 'masculine' || card.role === 'feminine');
  expect(genderCards).toHaveLength(2);
  expect(Math.abs(cards[0].fontSize - cards[1].fontSize)).toBeLessThan(0.5);
  expect(Math.abs(cards[0].fontSize - cards[2].fontSize)).toBeLessThan(0.5);
  expect(cards[2].remainingX).toBeGreaterThan(10);
  expect(Math.abs(genderCards[0].symbolWidth - genderCards[1].symbolWidth)).toBeLessThan(0.75);
  expect(Math.abs(genderCards[0].symbolHeight - genderCards[1].symbolHeight)).toBeLessThan(0.75);
  genderCards.forEach((card) => {
    expect(Math.abs(card.symbolCenterY - card.labelCenterY)).toBeLessThan(2);
  });
});
