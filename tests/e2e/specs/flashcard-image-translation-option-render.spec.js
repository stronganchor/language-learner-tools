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

test('image + translation answer options keep image-sized tiles with adaptive captions', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(`
    <style>
      body { margin: 0; }
      #ll-tools-flashcard-content { width: 390px; }
    </style>
    <div id="ll-tools-flashcard-content">
      <div id="ll-tools-flashcard"></div>
    </div>
  `);
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
    const image = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300"%3E%3Crect width="300" height="300" fill="%23dbeafe"/%3E%3Ccircle cx="150" cy="150" r="92" fill="%231d4d99"/%3E%3C/svg%3E';
    const words = [
      {
        id: 10,
        title: 'Image only',
        translation: '',
        image
      },
      {
        id: 11,
        title: 'Masa',
        translation: 'Table',
        image
      },
      {
        id: 12,
        title: 'Uzun ifade',
        translation: 'This answer caption needs enough words to wrap across several compact rows without taking over the screen',
        image
      },
      {
        id: 13,
        title: 'Kalem',
        translation: '',
        image
      }
    ];

    window.LLFlashcards.Cards.appendWordToContainer(words[0], 'image', 'audio', true);
    words.slice(1).forEach((word) => {
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
      const captionRect = caption ? caption.getBoundingClientRect() : null;
      const captionStyle = caption ? window.getComputedStyle(caption) : null;
      const lineHeight = captionStyle ? parseFloat(captionStyle.lineHeight || '0') : 0;
      const paddingTop = captionStyle ? parseFloat(captionStyle.paddingTop || '0') : 0;
      const paddingBottom = captionStyle ? parseFloat(captionStyle.paddingBottom || '0') : 0;
      const captionContentHeight = captionRect ? Math.max(0, captionRect.height - paddingTop - paddingBottom) : 0;
      return {
        classes: card.className,
        captionText: caption ? String(caption.textContent || '').trim() : '',
        captionHidden: caption ? caption.getAttribute('aria-hidden') === 'true' : false,
        captionDisplay: captionStyle ? captionStyle.display : '',
        captionRows: lineHeight > 0 ? captionContentHeight / lineHeight : 0,
        width: cardRect.width,
        cardHeight: cardRect.height,
        mediaWidth: mediaRect ? mediaRect.width : 0,
        mediaHeight: mediaRect ? mediaRect.height : 0
      };
    });
  });

  expect(rendered).toHaveLength(4);

  const imageOnly = rendered[0];
  const oneLine = rendered[1];
  const wrapped = rendered[2];
  const empty = rendered[3];

  expect(oneLine.classes).toContain('ll-answer-option-image-caption-card');
  expect(oneLine.captionText).toBe('Table');
  expect(oneLine.captionHidden).toBe(false);
  expect(oneLine.mediaWidth).toBeCloseTo(imageOnly.width, 0);
  expect(oneLine.mediaHeight).toBeCloseTo(imageOnly.cardHeight, 0);
  expect(oneLine.cardHeight).toBeGreaterThan(imageOnly.cardHeight);
  expect(Math.round(oneLine.captionRows)).toBe(1);

  expect(wrapped.captionRows).toBeGreaterThan(1);
  expect(wrapped.captionRows).toBeLessThanOrEqual(4.2);
  expect(wrapped.cardHeight).toBeGreaterThan(oneLine.cardHeight);

  expect(empty.captionText).toBe('');
  expect(empty.captionHidden).toBe(true);
  expect(empty.captionDisplay).toBe('none');
  expect(empty.cardHeight).toBeCloseTo(imageOnly.cardHeight, 0);
});
