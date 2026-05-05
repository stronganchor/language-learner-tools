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
const optionsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/options.js'),
  'utf8'
);
const baseCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);
const modeLearningCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/mode-learning.css'),
  'utf8'
);

async function addFrameStyle(frame, source) {
  await frame.evaluate((css) => {
    const style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);
  }, source);
}

async function addFrameScript(frame, source) {
  await frame.evaluate((js) => {
    const script = document.createElement('script');
    script.textContent = js;
    document.head.appendChild(script);
  }, source);
}

function fixtureImage(fill, label) {
  return `data:image/svg+xml,${encodeURIComponent(`
    <svg xmlns="http://www.w3.org/2000/svg" width="640" height="420" viewBox="0 0 640 420">
      <rect width="640" height="420" fill="${fill}"/>
      <rect x="32" y="32" width="576" height="356" rx="20" fill="#ffffff" opacity="0.88"/>
      <text x="320" y="225" text-anchor="middle" font-family="Arial, sans-serif" font-size="54" font-weight="700" fill="#1d4d99">${label}</text>
    </svg>
  `)}`;
}

async function renderQuizFrame(page, options = {}) {
  const width = Number(options.width || 390);
  const height = Number(options.height || 600);
  const imageSize = String(options.imageSize || 'large');
  const isEmbed = options.isEmbed !== false;
  const useCaptions = !!options.useCaptions;

  await page.setViewportSize({
    width: Math.max(1000, width + 80),
    height: Math.max(900, height + 80)
  });
  await page.goto('about:blank');
  await page.setContent(`
    <iframe id="quiz-frame" name="quiz-frame" style="width:${width}px;height:${height}px;border:0;display:block;"></iframe>
  `);

  const frameHandle = await page.locator('#quiz-frame').elementHandle();
  const frame = await frameHandle.contentFrame();
  await frame.setContent(`
    <!doctype html>
    <html>
    <head>
      <style>
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { background: #fff; }
      </style>
    </head>
    <body class="ll-tools-flashcard-open">
      <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container">
        <div id="ll-tools-flashcard-popup" style="display:flex;">
          <div id="ll-tools-flashcard-quiz-popup" style="display:flex;">
            <div id="ll-tools-flashcard-header" style="display:flex;">
              <div id="ll-tools-learning-progress" style="display:block;">
                <div class="learning-progress-bar simple-progress-bar">
                  <div class="learning-progress-fill simple-fill" style="width:18%"></div>
                </div>
              </div>
              <div id="ll-tools-category-stack" class="ll-tools-category-stack">
                <button id="ll-tools-repeat-flashcard" class="play-mode" type="button" aria-label="Play">
                  <span class="ll-repeat-audio-ui">
                    <span class="ll-repeat-icon-wrap" aria-hidden="true">
                      <span class="ll-audio-play-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="7 6 11 12" focusable="false" aria-hidden="true"><path d="M9.2 6.5c-.8-.5-1.8.1-1.8 1v9c0 .9 1 1.5 1.8 1l8.3-4.5c.8-.4.8-1.6 0-2L9.2 6.5z" fill="currentColor"/></svg>
                      </span>
                    </span>
                    <span class="ll-audio-mini-visualizer" aria-hidden="true">
                      <span class="bar"></span><span class="bar"></span><span class="bar"></span>
                      <span class="bar"></span><span class="bar"></span><span class="bar"></span>
                    </span>
                  </span>
                </button>
              </div>
            </div>
            <div id="ll-tools-flashcard-content">
              <div id="ll-tools-prompt" class="ll-tools-prompt" style="display:none;"></div>
              <div id="ll-tools-flashcard"></div>
            </div>
            <div id="ll-tools-mode-switcher-wrap" class="ll-tools-mode-switcher-wrap" style="display:flex;">
              <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" type="button" aria-label="Switch Mode">
                <span class="mode-icon" aria-hidden="true">&harr;</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </body>
    </html>
  `);

  await addFrameScript(frame, jquerySource);
  await addFrameStyle(frame, baseCssSource);
  await addFrameStyle(frame, modeLearningCssSource);
  await frame.evaluate((bootstrap) => {
    window.llToolsFlashcardsData = {
      imageSize: bootstrap.imageSize,
      isEmbed: bootstrap.isEmbed,
      maxOptionsOverride: 9,
      categories: [],
      answerOptionTextStyle: {
        fontSizePx: 42,
        minFontSizePx: 10
      }
    };
    window.wordsByCategory = {};
    window.optionWordsByCategory = {};
    window.LLFlashcards = {
      State: {},
      Dom: {},
      Selection: {
        getCurrentDisplayMode: function () {
          return bootstrap.useCaptions ? 'image_text_translation' : 'image';
        }
      }
    };
  }, { imageSize, isEmbed, useCaptions });

  await addFrameScript(frame, optionsSource);
  await addFrameScript(frame, utilSource);
  await addFrameScript(frame, cardsSource);

  await frame.evaluate((bootstrap) => {
    const words = [
      {
        id: 101,
        title: 'Option A',
        translation: bootstrap.useCaptions ? 'First answer' : '',
        image: bootstrap.imageA
      },
      {
        id: 102,
        title: 'Option B',
        translation: bootstrap.useCaptions ? 'Second answer with a compact caption' : '',
        image: bootstrap.imageB
      }
    ];
    const optionType = bootstrap.useCaptions ? 'image_text_translation' : 'image';
    words.forEach((word) => {
      window.LLFlashcards.Cards.appendWordToContainer(word, optionType, 'audio', true);
    });
    document.querySelectorAll('#ll-tools-flashcard .flashcard-container').forEach((card) => {
      card.style.display = 'flex';
      card.style.visibility = 'visible';
    });
    window.LLFlashcards.Cards.fitImageAnswerOptionCardsForViewport();
  }, {
    useCaptions,
    imageA: fixtureImage('#dbeafe', 'A'),
    imageB: fixtureImage('#dcfce7', 'B')
  });

  await frame.waitForTimeout(80);

  return frame.evaluate(() => {
    const rectFor = (el) => {
      const rect = el.getBoundingClientRect();
      return {
        left: rect.left,
        top: rect.top,
        right: rect.right,
        bottom: rect.bottom,
        width: rect.width,
        height: rect.height
      };
    };
    const root = document.getElementById('ll-tools-flashcard-container');
    const cards = Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'));
    return {
      width: window.innerWidth,
      height: window.innerHeight,
      rootClass: root.className,
      fitSize: root.getAttribute('data-ll-image-fit-size') || '',
      progress: rectFor(document.getElementById('ll-tools-learning-progress')),
      repeat: rectFor(document.getElementById('ll-tools-repeat-flashcard')),
      cards: cards.map((card) => {
        const media = card.querySelector('.ll-answer-option-image-caption-media');
        const image = card.querySelector('img');
        return {
          rect: rectFor(card),
          imageRect: rectFor(media || image),
          className: card.className
        };
      })
    };
  });
}

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

test('embedded image quiz options stay fully inside small iframe viewports', async ({ page }) => {
  const sizes = [
    { width: 320, height: 420 },
    { width: 320, height: 500 },
    { width: 360, height: 540 },
    { width: 390, height: 600 },
    { width: 393, height: 720 },
    { width: 430, height: 580 },
    { width: 480, height: 480 },
    { width: 600, height: 420 },
    { width: 720, height: 500 },
    { width: 800, height: 600 }
  ];

  for (const size of sizes) {
    for (const useCaptions of [false, true]) {
      const label = `${size.width}x${size.height}${useCaptions ? ' captioned' : ''}`;
      const measured = await renderQuizFrame(page, {
        width: size.width,
        height: size.height,
        imageSize: 'large',
        isEmbed: true,
        useCaptions
      });

      expect(measured.cards, `${label} card count`).toHaveLength(2);
      expect(measured.rootClass, `${label} compact class`).toContain('ll-compact-quiz-layout');
      expect(measured.repeat.height, `${label} compact repeat button`).toBeLessThanOrEqual(48);

      for (const [index, card] of measured.cards.entries()) {
        expect(card.rect.left, `${label} card ${index} left`).toBeGreaterThanOrEqual(-1);
        expect(card.rect.top, `${label} card ${index} top`).toBeGreaterThanOrEqual(-1);
        expect(card.rect.right, `${label} card ${index} right`).toBeLessThanOrEqual(measured.width + 1);
        expect(card.rect.bottom, `${label} card ${index} bottom`).toBeLessThanOrEqual(measured.height + 1);
        expect(card.imageRect.left, `${label} image ${index} left`).toBeGreaterThanOrEqual(-1);
        expect(card.imageRect.right, `${label} image ${index} right`).toBeLessThanOrEqual(measured.width + 1);
        expect(card.imageRect.bottom, `${label} image ${index} bottom`).toBeLessThanOrEqual(measured.height + 1);
        expect(card.imageRect.width, `${label} image ${index} remains visible`).toBeGreaterThan(50);
      }
    }
  }
});

test('large embedded image quizzes keep the same image size as large standalone quizzes', async ({ page }) => {
  const embedded = await renderQuizFrame(page, {
    width: 900,
    height: 760,
    imageSize: 'large',
    isEmbed: true
  });
  const standalone = await renderQuizFrame(page, {
    width: 900,
    height: 760,
    imageSize: 'large',
    isEmbed: false
  });

  expect(embedded.fitSize).toBe('');
  expect(standalone.fitSize).toBe('');
  expect(embedded.rootClass).not.toContain('ll-compact-quiz-layout');
  expect(standalone.rootClass).not.toContain('ll-compact-quiz-layout');
  expect(embedded.cards[0].imageRect.width).toBeCloseTo(standalone.cards[0].imageRect.width, 0);
  expect(embedded.cards[0].imageRect.width).toBeCloseTo(250, 0);
});
