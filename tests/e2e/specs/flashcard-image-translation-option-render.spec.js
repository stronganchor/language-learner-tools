const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const utilSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/util.js'),
  'utf8'
);
const cardsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/cards.js'),
  'utf8'
);
const baseCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);

test('image + translation answer options render a smaller image area with an optional caption', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-flashcard"></div>');
  await page.addScriptTag({ content: jquerySource });
  await page.addStyleTag({ content: baseCssSource });

  await page.evaluate(() => {
    window.llToolsFlashcardsData = {
      imageSize: 'small',
      answerOptionTextStyle: {
        fontSizePx: 42,
        minFontSizePx: 10
      }
    };
    window.LLFlashcards = {
      State: {},
      Dom: {},
      Selection: {
        getCurrentDisplayMode: function () {
          return 'image';
        }
      }
    };
  });

  await page.addScriptTag({ content: utilSource });
  await page.addScriptTag({ content: cardsSource });

  const rendered = await page.evaluate(() => {
    const words = [
      {
        id: 11,
        title: 'Masa',
        translation: 'Table',
        image: 'https://example.com/table.jpg'
      },
      {
        id: 12,
        title: 'Kalem',
        translation: '',
        image: 'https://example.com/pencil.jpg'
      }
    ];

    words.forEach((word) => {
      window.LLFlashcards.Cards.appendWordToContainer(word, 'image_text_translation', 'audio', true);
    });

    const cards = Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'));
    cards.forEach((card) => {
      card.style.display = 'flex';
    });

    return cards.map((card) => {
      const media = card.querySelector('.ll-answer-option-image-caption-media');
      const caption = card.querySelector('.ll-answer-option-image-caption');
      const cardRect = card.getBoundingClientRect();
      const mediaRect = media ? media.getBoundingClientRect() : null;
      return {
        classes: card.className,
        captionText: caption ? String(caption.textContent || '').trim() : '',
        captionHidden: caption ? caption.getAttribute('aria-hidden') === 'true' : false,
        cardHeight: cardRect.height,
        mediaHeight: mediaRect ? mediaRect.height : 0
      };
    });
  });

  expect(rendered).toHaveLength(2);
  expect(rendered[0].classes).toContain('ll-answer-option-image-caption-card');
  expect(rendered[0].captionText).toBe('Table');
  expect(rendered[0].captionHidden).toBe(false);
  expect(rendered[0].cardHeight).toBeGreaterThan(rendered[0].mediaHeight);
  expect(rendered[1].captionText).toBe('');
  expect(rendered[1].captionHidden).toBe(true);
});
