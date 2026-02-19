const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const selectionSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);

async function mountSelectionHarness(page, options = {}) {
  const maxCards = Number.isFinite(Number(options.maxCards))
    ? Math.max(1, Number(options.maxCards))
    : null;

  const data = {
    categories: Array.isArray(options.categories) ? options.categories : [],
    wordsByCategory: options.wordsByCategory || {},
    optionWordsByCategory: options.optionWordsByCategory || options.wordsByCategory || {},
    targetCategoryName: String(options.targetCategoryName || ''),
    desiredCount: Number(options.desiredCount || 4),
    maxCards,
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
        if (Number.isFinite(bootstrap.maxCards)) {
          const count = document.querySelectorAll('#ll-tools-flashcard .flashcard-container').length;
          return count < Number(bootstrap.maxCards);
        }
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

test('specific wrong-answer list overrides normal distractors', async ({ page }) => {
  const category = 'Override category';
  const targetWord = {
    id: 401,
    title: 'Owner',
    label: 'Owner',
    image: 'https://img.test/owner.jpg',
    audio: 'https://audio.test/owner.mp3',
    specific_wrong_answer_ids: [402, 403]
  };
  const specifiedA = { id: 402, title: 'Specified A', label: 'Specified A', image: 'https://img.test/spec-a.jpg', audio: 'https://audio.test/spec-a.mp3' };
  const specifiedB = { id: 403, title: 'Specified B', label: 'Specified B', image: 'https://img.test/spec-b.jpg', audio: 'https://audio.test/spec-b.mp3' };
  const normalDistractor = { id: 404, title: 'Normal distractor', label: 'Normal distractor', image: 'https://img.test/normal.jpg', audio: 'https://audio.test/normal.mp3' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    desiredCount: 6,
    wordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, normalDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, normalDistractor, specifiedA, specifiedB]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Override category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([401, 402, 403]);
  expect(pickedIds.includes(404)).toBe(false);
});

test('specific wrong-answer list respects card-cap and shows as many as fit', async ({ page }) => {
  const category = 'Cap category';
  const targetWord = {
    id: 451,
    title: 'Owner',
    label: 'Owner',
    image: 'https://img.test/owner-2.jpg',
    audio: 'https://audio.test/owner-2.mp3',
    specific_wrong_answer_ids: [452, 453, 454]
  };
  const specifiedA = { id: 452, title: 'Specified A', label: 'Specified A', image: 'https://img.test/spec2-a.jpg', audio: 'https://audio.test/spec2-a.mp3' };
  const specifiedB = { id: 453, title: 'Specified B', label: 'Specified B', image: 'https://img.test/spec2-b.jpg', audio: 'https://audio.test/spec2-b.mp3' };
  const specifiedC = { id: 454, title: 'Specified C', label: 'Specified C', image: 'https://img.test/spec2-c.jpg', audio: 'https://audio.test/spec2-c.mp3' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    desiredCount: 8,
    maxCards: 3,
    wordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, specifiedC]
    },
    optionWordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, specifiedC]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Cap category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([451, 452, 453]);
  expect(pickedIds.includes(454)).toBe(false);
});

test('reserved wrong-answer words are not used as distractors for other targets', async ({ page }) => {
  const category = 'Owner scope category';
  const targetWord = { id: 501, title: 'Target', label: 'Target', image: 'https://img.test/tgt.jpg', audio: 'https://audio.test/tgt.mp3' };
  const allowedDistractor = { id: 502, title: 'Allowed distractor', label: 'Allowed distractor', image: 'https://img.test/allowed.jpg', audio: 'https://audio.test/allowed.mp3' };
  const reservedForOther = {
    id: 503,
    title: 'Reserved for other',
    label: 'Reserved for other',
    image: 'https://img.test/reserved.jpg',
    audio: 'https://audio.test/reserved.mp3',
    specific_wrong_answer_owner_ids: [999]
  };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'audio', option_type: 'image' }],
    targetCategoryName: category,
    desiredCount: 4,
    wordsByCategory: {
      [category]: [targetWord, allowedDistractor, reservedForOther]
    },
    optionWordsByCategory: {
      [category]: [targetWord, reservedForOther, allowedDistractor]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Owner scope category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([501, 502]);
  expect(pickedIds.includes(503)).toBe(false);
});

test('text-to-text mode pulls wrong answers from other text options', async ({ page }) => {
  const category = 'Text only category';
  const targetWord = { id: 601, title: 'Casa', label: 'House' };
  const distractorA = { id: 602, title: 'Perro', label: 'Dog' };
  const distractorB = { id: 603, title: 'Gato', label: 'Cat' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'text_translation', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 3,
    wordsByCategory: {
      [category]: [targetWord, distractorA, distractorB]
    },
    optionWordsByCategory: {
      [category]: [targetWord, distractorA, distractorB]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Text only category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([601, 602, 603]);
});

test('text-to-text mode respects specific wrong-answer overrides', async ({ page }) => {
  const category = 'Text override category';
  const targetWord = {
    id: 651,
    title: 'Owner',
    label: 'Owner',
    specific_wrong_answer_ids: [652, 653]
  };
  const specifiedA = { id: 652, title: 'Specified A', label: 'Specified A' };
  const specifiedB = { id: 653, title: 'Specified B', label: 'Specified B' };
  const normalDistractor = { id: 654, title: 'Normal distractor', label: 'Normal distractor' };

  await mountSelectionHarness(page, {
    categories: [{ name: category, prompt_type: 'text_translation', option_type: 'text_translation' }],
    targetCategoryName: category,
    desiredCount: 6,
    wordsByCategory: {
      [category]: [targetWord, specifiedA, specifiedB, normalDistractor]
    },
    optionWordsByCategory: {
      [category]: [targetWord, normalDistractor, specifiedA, specifiedB]
    }
  });

  const pickedIds = await page.evaluate((word) => {
    const target = Object.assign({ __categoryName: 'Text override category' }, word);
    window.LLFlashcards.Selection.fillQuizOptions(target);
    return Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'))
      .map((el) => Number(el.getAttribute('data-word-id')) || 0)
      .filter((id) => id > 0);
  }, targetWord);

  expect(pickedIds).toEqual([651, 652, 653]);
  expect(pickedIds.includes(654)).toBe(false);
});
