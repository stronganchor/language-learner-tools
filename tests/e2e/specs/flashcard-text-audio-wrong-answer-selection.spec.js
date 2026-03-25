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
      ROUNDS_PER_CATEGORY: 2,
      isFirstRound: false,
      isLearningMode: false,
      isListeningMode: false,
      isGenderMode: false,
      isSelfCheckMode: false,
      currentCategoryName: String(options.targetCategoryName || ''),
      currentCategory: [],
      categoryNames: [],
      initialCategoryNames: [],
      categoryRepetitionQueues: {},
      categoryRoundCount: {},
      completedCategories: {},
      quizResults: { correctOnFirstTry: 0, incorrect: [], wordAttempts: {} },
      wrongIndexes: [],
      currentCategoryRoundCount: 0,
      usedWordIDs: [],
      lastWordShownId: null
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
    State.wordsByCategory = bootstrap.wordsByCategory || {};
    State.categoryNames = Object.keys(bootstrap.wordsByCategory || {});
    State.currentPromptType = String((bootstrap.categories[0] && bootstrap.categories[0].prompt_type) || 'audio');
    State.currentOptionType = String((bootstrap.categories[0] && bootstrap.categories[0].option_type) || 'image');

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
            'data-word-id': String((word && word.id) || '')
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

test('text-audio mode prefers specific wrong-answer IDs over synthetic text-only options', async ({ page }) => {
  const category = 'Image audio text answers';
  const targetWord = {
    id: 901,
    title: 'Cat',
    label: 'Cat',
    image: 'https://example.com/cat.jpg',
    audio: 'https://example.com/cat.mp3',
    specific_wrong_answer_ids: [902, 903],
    specific_wrong_answer_texts: ['Dog', 'Bird']
  };
  const dogWord = {
    id: 902,
    title: 'Dog',
    label: 'Dog',
    audio: 'https://example.com/dog.mp3'
  };
  const birdWord = {
    id: 903,
    title: 'Bird',
    label: 'Bird',
    audio: 'https://example.com/bird.mp3',
    is_specific_wrong_answer_only: true
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'image', option_type: 'text_audio' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord, dogWord, birdWord]
    },
    optionWordsByCategory: {
      [category]: [targetWord, dogWord, birdWord]
    }
  });

  const picked = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Image audio text answers' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => String(el.getAttribute('data-word-id') || ''));
  }, targetWord);

  expect(picked).toEqual(['901', '902', '903']);
});
