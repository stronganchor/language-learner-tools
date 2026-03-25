const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const utilSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/util.js'),
  'utf8'
);
const domSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/dom.js'),
  'utf8'
);
const selectionSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);

async function mountDomHarness(page, stateOverrides = {}) {
  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-quiz-popup"></div>
    <div id="ll-tools-flashcard-content"></div>
    <div id="ll-tools-category-stack">
      <button id="ll-tools-repeat-flashcard" type="button"></button>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((bootstrap) => {
    window.LLFlashcards = {
      State: Object.assign({
        isListeningMode: false,
        currentPromptType: 'audio',
        currentOptionType: 'image'
      }, bootstrap)
    };
  }, stateOverrides);

  await page.addScriptTag({ content: utilSource });
  await page.addScriptTag({ content: domSource });
}

async function mountSelectionHarness(page, options = {}) {
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
      categories: [
        {
          id: 1,
          name: 'Cat A',
          prompt_type: bootstrap.promptType,
          option_type: bootstrap.optionType
        }
      ],
      genderEnabled: true,
      genderOptions: ['m', 'f']
    };

    window.LLFlashcards = {
      State: {
        DEFAULT_DISPLAY_MODE: 'image',
        currentCategoryName: 'Cat A',
        currentPromptType: bootstrap.promptType,
        currentOptionType: bootstrap.optionType,
        isGenderMode: true,
        wordsByCategory: {}
      },
      Cards: {},
      Dom: {}
    };
  }, {
    promptType: String(options.promptType || 'text_translation'),
    optionType: String(options.optionType || 'text_title')
  });

  await page.addScriptTag({ content: utilSource });
  await page.addScriptTag({ content: selectionSource });
}

test('repeat button stays hidden for text-to-text quiz presentation', async ({ page }) => {
  await mountDomHarness(page, {
    currentPromptType: 'text_translation',
    currentOptionType: 'text_title'
  });

  const result = await page.evaluate(() => {
    window.LLFlashcards.Dom.enableRepeatButton();
    const button = document.getElementById('ll-tools-repeat-flashcard');
    return {
      display: window.getComputedStyle(button).display,
      disabled: button.disabled,
      stackHasNoRepeatClass: document.getElementById('ll-tools-category-stack').classList.contains('ll-no-repeat-btn')
    };
  });

  expect(result.display).toBe('none');
  expect(result.disabled).toBe(false);
  expect(result.stackHasNoRepeatClass).toBe(true);
});

test('repeat button stays hidden when audio-text prompts are shown with text options', async ({ page }) => {
  await mountDomHarness(page, {
    currentPromptType: 'audio_text_translation',
    currentOptionType: 'text_title'
  });

  const result = await page.evaluate(() => {
    window.LLFlashcards.Dom.enableRepeatButton();
    const button = document.getElementById('ll-tools-repeat-flashcard');
    return {
      display: window.getComputedStyle(button).display,
      stackHasNoRepeatClass: document.getElementById('ll-tools-category-stack').classList.contains('ll-no-repeat-btn')
    };
  });

  expect(result.display).toBe('none');
  expect(result.stackHasNoRepeatClass).toBe(true);
});

test('repeat button still shows for audio-to-text quiz presentation', async ({ page }) => {
  await mountDomHarness(page, {
    currentPromptType: 'audio',
    currentOptionType: 'text_title'
  });

  const result = await page.evaluate(() => {
    window.LLFlashcards.Dom.enableRepeatButton();
    const button = document.getElementById('ll-tools-repeat-flashcard');
    return {
      display: window.getComputedStyle(button).display,
      disabled: button.disabled,
      stackHasNoRepeatClass: document.getElementById('ll-tools-category-stack').classList.contains('ll-no-repeat-btn')
    };
  });

  expect(result.display).not.toBe('none');
  expect(result.disabled).toBe(false);
  expect(result.stackHasNoRepeatClass).toBe(false);
});

test('gender prompt rendering skips inline audio controls for text-to-text quizzes', async ({ page }) => {
  await mountSelectionHarness(page, {
    promptType: 'text_translation',
    optionType: 'text_title'
  });

  const hasPromptAudioButton = await page.evaluate(() => {
    window.LLFlashcards.Selection.renderPrompt({
      id: 55,
      title: 'Shalom',
      label: 'Peace',
      prompt_label: 'Peace',
      audio: '/audio/shalom.mp3',
      has_audio: true
    }, {
      prompt_type: 'text_translation',
      option_type: 'text_title'
    });

    return !!document.querySelector('#ll-tools-prompt .ll-prompt-audio-button');
  });

  expect(hasPromptAudioButton).toBe(false);
});
