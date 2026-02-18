const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const selectionSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);

async function mountSelectionHarness(page, options = {}) {
  const data = {
    categories: Array.isArray(options.categories) ? options.categories : [],
    wordsByCategory: options.wordsByCategory || {},
    optionWordsByCategory: options.optionWordsByCategory || options.wordsByCategory || {},
    targetCategoryName: String(options.targetCategoryName || ''),
    desiredCount: Number(options.desiredCount || 4),
    state: Object.assign({
      DEFAULT_DISPLAY_MODE: 'image',
      isLearningMode: false,
      isGenderMode: false,
      currentCategoryName: String(options.targetCategoryName || ''),
      currentCategory: [],
      categoryNames: [],
      categoryRepetitionQueues: {},
      completedCategories: {},
      wrongIndexes: [],
      currentCategoryRoundCount: 0
    }, options.state || {})
  };

  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-content">
      <div id="ll-tools-prompt"></div>
      <div id="ll-tools-flashcard"></div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrap) => {
    window.llToolsFlashcardsData = {
      categories: bootstrap.categories
    };
    window.wordsByCategory = bootstrap.wordsByCategory;
    window.optionWordsByCategory = bootstrap.optionWordsByCategory;

    const State = Object.assign({}, bootstrap.state);
    State.currentCategory = bootstrap.wordsByCategory[bootstrap.targetCategoryName] || [];
    State.categoryNames = Array.isArray(State.categoryNames) && State.categoryNames.length
      ? State.categoryNames.slice()
      : Object.keys(bootstrap.wordsByCategory || {});

    window.LLFlashcards = {
      State,
      Util: {
        randomlySort: function (items) {
          return Array.isArray(items) ? items.slice() : [];
        }
      },
      Cards: {
        appendWordToContainer: function (word) {
          const $card = window.jQuery('<div>', {
            class: 'flashcard-container',
            'data-word-id': String((word && word.id) || ''),
            'data-word-image': String((word && word.image) || '')
          });
          window.jQuery('#ll-tools-flashcard').append($card);
          return $card;
        },
        addClickEventToCard: function () {}
      },
      Dom: {
        updateCategoryNameDisplay: function () {}
      },
      LearningMode: {
        getChoiceCount: function () {
          return Number(bootstrap.desiredCount || 4);
        }
      }
    };

    window.FlashcardLoader = {
      loadResourcesForWord: function () {}
    };

    window.FlashcardOptions = {
      categoryOptionsCount: {
        [bootstrap.targetCategoryName]: Number(bootstrap.desiredCount || 4)
      },
      canAddMoreCards: function () {
        return true;
      }
    };
  }, data);

  await page.addScriptTag({ content: selectionSource });
}

test('practice options stay within the target category pool', async ({ page }) => {
  const targetCategory = 'Baby animals';
  const otherCategory = 'People';
  const targetWord = { id: 101, title: 'Lamb', label: 'Lamb', image: 'https://img.test/lamb.jpg', audio: 'https://audio.test/lamb.mp3' };
  const sameCategoryDistractor = { id: 102, title: 'Calf', label: 'Calf', image: 'https://img.test/calf.jpg', audio: 'https://audio.test/calf.mp3' };
  const crossCategoryDistractor = { id: 201, title: 'Man', label: 'Man', image: 'https://img.test/man.jpg', audio: 'https://audio.test/man.mp3' };

  await mountSelectionHarness(page, {
    categories: [
      { name: targetCategory, prompt_type: 'audio', option_type: 'image' },
      { name: otherCategory, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: targetCategory,
    desiredCount: 5,
    wordsByCategory: {
      [targetCategory]: [targetWord],
      [otherCategory]: [crossCategoryDistractor]
    },
    optionWordsByCategory: {
      [targetCategory]: [targetWord, sameCategoryDistractor],
      [otherCategory]: [crossCategoryDistractor]
    },
    state: {
      categoryNames: [targetCategory, otherCategory]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Baby animals' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([101, 102]);
});

test('practice options never include duplicate images', async ({ page }) => {
  const category = 'Baby animals';
  const targetWord = { id: 301, title: 'Bear cub', label: 'Bear cub', image: 'https://img.test/bear.jpg', audio: 'https://audio.test/bear.mp3' };
  const chickA = { id: 302, title: 'Chick one', label: 'Chick one', image: 'https://img.test/chick.jpg', audio: 'https://audio.test/chick-a.mp3' };
  const chickB = { id: 303, title: 'Chick two', label: 'Chick two', image: 'https://img.test/chick.jpg', audio: 'https://audio.test/chick-b.mp3' };
  const lamb = { id: 304, title: 'Lamb', label: 'Lamb', image: 'https://img.test/lamb.jpg', audio: 'https://audio.test/lamb.mp3' };

  await mountSelectionHarness(page, {
    categories: [
      { name: category, prompt_type: 'audio', option_type: 'image' }
    ],
    targetCategoryName: category,
    desiredCount: 5,
    wordsByCategory: {
      [category]: [targetWord]
    },
    optionWordsByCategory: {
      [category]: [targetWord, chickA, chickB, lamb]
    },
    state: {
      categoryNames: [category]
    }
  });

  const picked = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Baby animals' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container')).map((el) => ({
      id: Number(el.getAttribute('data-word-id')) || 0,
      image: String(el.getAttribute('data-word-image') || '')
    }));
  }, targetWord);

  const chickCount = picked.filter((row) => row.image === 'https://img.test/chick.jpg').length;
  expect(chickCount).toBe(1);
  expect(picked.some((row) => row.id === 302)).toBe(true);
  expect(picked.some((row) => row.id === 303)).toBe(false);
});
