const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const utilSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/util.js'),
  'utf8'
);
const selectionSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/selection.js'),
  'utf8'
);

function buildMarkup() {
  return `
    <div id="ll-tools-flashcard-content">
      <div id="ll-tools-prompt"></div>
    </div>
  `;
}

async function mountPromptHarness(page, categoryConfig) {
  await page.goto('about:blank');
  await page.setContent(buildMarkup());
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((cfg) => {
    window.LLFlashcards = {
      State: {
        DEFAULT_DISPLAY_MODE: 'image',
        currentCategoryName: 'Combo Category'
      }
    };
    window.llToolsFlashcardsData = {
      categories: [{
        name: 'Combo Category',
        prompt_type: String(cfg.prompt_type || 'audio'),
        option_type: String(cfg.option_type || 'image'),
        learning_supported: true
      }]
    };
  }, categoryConfig);
  await page.addScriptTag({ content: utilSource });
  await page.addScriptTag({ content: selectionSource });
}

test('audio + text prompt renders prompt text for combo prompt categories', async ({ page }) => {
  await mountPromptHarness(page, {
    prompt_type: 'audio_text_translation',
    option_type: 'text_title'
  });

  const rendered = await page.evaluate(() => {
    window.LLFlashcards.Selection.renderPrompt({
      id: 11,
      title: 'Casa',
      label: 'Casa',
      prompt_label: 'House',
      audio: 'https://example.com/casa.mp3'
    }, {
      prompt_type: 'audio_text_translation',
      option_type: 'text_title'
    });

    return {
      text: document.querySelector('#ll-tools-prompt .ll-prompt-text')?.textContent?.trim() || '',
      imageCount: document.querySelectorAll('#ll-tools-prompt img').length
    };
  });

  expect(rendered.text).toBe('House');
  expect(rendered.imageCount).toBe(0);
});

test('image + text prompt stacks image and text together', async ({ page }) => {
  await mountPromptHarness(page, {
    prompt_type: 'image_text_title',
    option_type: 'text_translation'
  });

  const rendered = await page.evaluate(() => {
    window.LLFlashcards.Selection.renderPrompt({
      id: 22,
      title: 'Kitap',
      label: 'Book',
      prompt_label: 'Kitap',
      image: 'https://example.com/kitap.jpg'
    }, {
      prompt_type: 'image_text_title',
      option_type: 'text_translation'
    });

    return {
      text: document.querySelector('#ll-tools-prompt .ll-prompt-text')?.textContent?.trim() || '',
      imageCount: document.querySelectorAll('#ll-tools-prompt img').length
    };
  });

  expect(rendered.text).toBe('Kitap');
  expect(rendered.imageCount).toBe(1);
});
