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

  await page.evaluate(() => {
    const targetWord = {
      id: 101,
      title: 'der Tisch',
      label: 'der Tisch',
      image: 'table.jpg',
      grammatical_gender: 'masculine',
      part_of_speech: ['noun'],
      all_categories: ['Cat A']
    };

    window.__llGenderTargetWord = targetWord;
    window.llToolsStudyPrefs = {};
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      genderEnabled: true,
      genderOptions: ['masculine', 'feminine'],
      genderVisualConfig: { options: [] },
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

  await page.evaluate(() => {
    window.LLFlashcards.Selection.fillQuizOptions(window.__llGenderTargetWord);
  });

  await page.waitForFunction(() => {
    const cards = Array.from(document.querySelectorAll('#ll-tools-flashcard .ll-gender-option'));
    if (cards.length !== 3) {
      return false;
    }
    return cards.every((card) => window.getComputedStyle(card).display !== 'none');
  });

  const cards = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .ll-gender-option')).map((card) => {
      const style = window.getComputedStyle(card);
      const rect = card.getBoundingClientRect();
      return {
        display: style.display,
        visibility: style.visibility,
        width: rect.width,
        height: rect.height
      };
    });
  });

  expect(cards).toHaveLength(3);
  cards.forEach((card) => {
    expect(card.display).not.toBe('none');
    expect(card.visibility).toBe('visible');
    expect(card.width).toBeGreaterThan(0);
    expect(card.height).toBeGreaterThan(0);
  });
});
