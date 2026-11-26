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
const domSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/dom.js'),
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
  const imageA = String(options.imageA || '#dbeafe');
  const imageB = String(options.imageB || '#dcfce7');
  const isEmbed = options.isEmbed !== false;
  const optionType = options.optionType || (options.useCaptions ? 'image_text_translation' : 'image');
  const useCaptions = optionType === 'image_text_translation';

  const count = Number(options.count || 2);
  const captions = Array.isArray(options.captions) ? options.captions : [];
  const portaled = !!options.portaled;

  let frame = page;
  await page.goto('about:blank');
  if (options.topLevel) {
    await page.setViewportSize({ width, height });
  } else {
    await page.setViewportSize({ width: Math.max(1000, width + 80), height: Math.max(900, height + 80) });
    await page.setContent(`<iframe id="quiz-frame" style="width:${width}px;height:${height}px;border:0;display:block;"></iframe>`);
    const frameHandle = await page.locator('#quiz-frame').elementHandle();
    frame = await frameHandle.contentFrame();
  }
  await frame.setContent(`
    <!doctype html>
    <html>
    <head>
      <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
      <style>
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { background: #fff; }
      </style>
    </head>
    <body class="ll-tools-flashcard-open">
      <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container">
        <div id="ll-tools-flashcard-popup" style="display:flex;">
          <div id="ll-tools-flashcard-quiz-popup" style="display:flex;">
            ${options.topLevel ? '<button id="ll-tools-close-flashcard">×</button>' : ''}
            <div id="ll-tools-flashcard-header" style="display:flex;">
              <div id="ll-tools-learning-progress" style="display:block;">
                <div class="learning-progress-bar simple-progress-bar">
                  <div class="learning-progress-fill simple-fill" style="width:18%"></div>
                </div>
              </div>
              <div id="ll-tools-category-stack" class="ll-tools-category-stack">
                ${options.topLevel ? '<span id="ll-tools-category-display" class="ll-tools-category-display">Diğer</span>' : ''}
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
          </div>
        </div>
      </div>
    </body>
    </html>
  `);

  if (portaled) {
    await frame.evaluate(() => {
      document.body.appendChild(document.getElementById('ll-tools-flashcard-popup'));
    });
  }

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
          return bootstrap.optionType;
        }
      }
    };
  }, { imageSize, isEmbed, optionType });

  await addFrameScript(frame, optionsSource);
  await addFrameScript(frame, utilSource);
  await addFrameScript(frame, cardsSource);

  await frame.evaluate((bootstrap) => {
    const words = Array.from({ length: bootstrap.count }, (_, index) => ({
      id: 101 + index,
      title: `Option ${index + 1}`,
      translation: bootstrap.captions[index] || (index === 0 ? 'First answer' : 'Second answer with a compact caption'),
      image: index % 2 ? bootstrap.imageB : bootstrap.imageA
    }));
    words.forEach((word) => {
      window.LLFlashcards.Cards.appendWordToContainer(word, bootstrap.optionType, 'audio', true);
    });
    if (bootstrap.useCaptions) {
      // The target may be last in the shuffled DOM, even though it was selected first.
      const grid = document.getElementById('ll-tools-flashcard');
      grid.appendChild(grid.firstElementChild);
      // In live audio rounds the prompt audio can be a hidden child before the choices.
      grid.prepend(document.createElement('audio'));
      window.LLFlashcards.Cards.prepareCaptionChoices(words);
    }
    document.querySelectorAll('#ll-tools-flashcard .flashcard-container').forEach((card) => {
      card.style.display = 'flex';
      card.style.visibility = 'visible';
    });
    window.LLFlashcards.Cards.fitImageAnswerOptionCardsForViewport();
  }, {
    optionType,
    useCaptions,
    captions,
    count,
    imageA: fixtureImage(imageA, 'A'),
    imageB: fixtureImage(imageB, 'B')
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
    const content = document.getElementById('ll-tools-flashcard-content');
    const contentStyle = window.getComputedStyle(content);
    const cards = Array.from(document.querySelectorAll('#ll-tools-flashcard .flashcard-container'));
    return {
      width: window.innerWidth,
      height: window.innerHeight,
      rootClass: root.className,
      portaled: !document.getElementById('ll-tools-flashcard-popup').closest('#ll-tools-flashcard-container'),
      fitSize: root.getAttribute('data-ll-image-fit-size') || '',
      content: rectFor(content),
      contentPaddingTop: parseFloat(contentStyle.paddingTop),
      contentPaddingBottom: parseFloat(contentStyle.paddingBottom),
      progress: rectFor(document.getElementById('ll-tools-learning-progress')),
      repeat: rectFor(document.getElementById('ll-tools-repeat-flashcard')),
      cards: cards.map((card) => {
        const media = card.querySelector('.ll-answer-option-image-caption-media');
        const image = card.querySelector('img');
        const caption = card.querySelector('.ll-answer-option-image-caption');
        const captionStyle = caption ? window.getComputedStyle(caption) : null;
        const captionHeight = caption ? caption.getBoundingClientRect().height : 0;
        return {
          rect: rectFor(card),
          imageRect: image ? rectFor(media || image) : null,
          className: card.className,
          boxShadow: window.getComputedStyle(card).boxShadow,
          captionRows: captionStyle
            ? (captionHeight - parseFloat(captionStyle.paddingTop) - parseFloat(captionStyle.paddingBottom)) / parseFloat(captionStyle.lineHeight)
            : 0
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

test('image + translation answer captions prefer the resolved answer label', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
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
          return 'image_text_translation';
        }
      }
    };
  });

  await page.addScriptTag({ content: utilSource });
  await page.addScriptTag({ content: cardsSource });

  const caption = await page.evaluate(() => {
    window.LLFlashcards.Cards.appendWordToContainer({
      id: 21,
      title: 'Helper title',
      label: 'Helper title',
      translation: 'Target-language text',
      image: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="300" height="300"%3E%3Crect width="300" height="300" fill="%23dbeafe"/%3E%3C/svg%3E'
    }, 'image_text_translation', 'audio', true);

    const captionEl = document.querySelector('.ll-answer-option-image-caption');
    return captionEl ? String(captionEl.textContent || '').trim() : '';
  });

  expect(caption).toBe('Helper title');
  expect(caption).not.toBe('Target-language text');
});

test('prompt-card image answer options render the answer image instead of the prompt image', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
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

  const src = await page.evaluate(() => {
    const promptImage = 'https://media.test/asl-tree-sign.webp';
    const answerImage = 'https://media.test/tree-concept.webp';
    const card = window.LLFlashcards.Cards.appendWordToContainer({
      id: 9001,
      title: 'Tree',
      is_prompt_card: true,
      prompt_card_id: 9001,
      image: promptImage,
      answer_image: answerImage,
      answer_image_attachment_id: 321
    }, 'image', 'image', true);
    card.css('display', 'flex');
    return document.querySelector('#ll-tools-flashcard .flashcard-container img')?.getAttribute('src') || '';
  });

  expect(src).toBe('https://media.test/tree-concept.webp');
});

test.describe('full-screen phone Practice', () => {
  test.use({ isMobile: true, hasTouch: true });
  const short = ['İzzet', 'Affediyor', 'Kabul etmiyor', 'Af diliyor', 'Özgür', 'Kumaş'];
  const double = 'Arkadaşından yardım istiyor';
  const mount = (page, captions, height = 685) => renderQuizFrame(page, {
    width: 360, height, imageSize: 'small', count: captions.length,
    useCaptions: true, isEmbed: false, portaled: true, topLevel: true, captions
  });
  function checkGrid(result, count) {
    expect(result.cards).toHaveLength(count);
    expect(new Set(result.cards.map(card => card.rect.left)).size).toBe(2);
    expect(new Set(result.cards.map(card => card.rect.top)).size).toBe(count / 2);
    for (const card of result.cards) {
      expect(card.imageRect.width).toBe(150);
      expect(card.imageRect.height).toBe(150);
      expect(card.rect.bottom).toBeLessThanOrEqual(result.height - 15);
    }
  }

  test('a compact header leaves room for three normal-sized rows and one double-line caption', async ({ page }) => {
    const initial = await mount(page, [double, ...short.slice(1)]);
    checkGrid(initial, 6);
    expect(initial.cards.filter(card => card.captionRows > 1.5)).toHaveLength(1);
    expect(initial.cards[0].rect.top).toBeLessThanOrEqual(110);
    expect(initial.repeat.height).toBeGreaterThanOrEqual(44);
    const close = await page.locator('#ll-tools-close-flashcard').boundingBox();
    expect(close.width).toBe(38);
    expect(close.height).toBe(38);
    const closeButton = page.locator('#ll-tools-close-flashcard');
    expect(await closeButton.evaluate(button => {
      const rect = button.getBoundingClientRect();
      const x = rect.left + rect.width / 2;
      return [rect.top - 2.5, rect.bottom + 2.5].every(y => document.elementFromPoint(x, y) === button);
    })).toBe(true);
    const title = page.locator('#ll-tools-category-display');
    await expect(title).toHaveCSS('font-size', '14px');
    const label = await title.boundingBox();
    const bar = await page.locator('#ll-tools-learning-progress .learning-progress-bar').boundingBox();
    expect(label.y - (bar.y + bar.height)).toBeCloseTo(6, 1);
    expect(initial.repeat.top - (label.y + label.height)).toBeCloseTo(6, 1);
    expect(initial.progress.right).toBeLessThan(close.x);

    const cards = page.locator('#ll-tools-flashcard .ll-answer-option-image-caption-card');
    await page.evaluate(() => {
      Object.defineProperty(visualViewport, 'height', {configurable: true, value: 610});
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect(cards).toHaveCount(4);
    const ids = await cards.evaluateAll(nodes => nodes.map(node => node.dataset.wordId));
    expect(ids.sort()).toEqual(['101','102','103','104']);
    for (const card of await cards.all()) {
      const rect = await card.boundingBox();
      expect(rect.width).toBe(150);
      expect(rect.y + rect.height).toBeLessThanOrEqual(610 - 15);
    }
    await page.evaluate(() => {
      delete visualViewport.height;
      visualViewport.dispatchEvent(new Event('resize'));
    });
    await expect(cards).toHaveCount(4);

    // If even two rows exceed the available height, every answer stays reachable.
    await page.evaluate(() => {
      Object.defineProperty(visualViewport, 'height', { configurable: true, value: 300 });
      visualViewport.dispatchEvent(new Event('resize'));
    });
    const grid = page.locator('#ll-tools-flashcard');
    await expect(grid).toHaveCSS('overflow-y', 'auto');
    await grid.evaluate(node => { node.scrollTop = node.scrollHeight; });
    const last = await cards.last().boundingBox();
    expect(last.width).toBe(150);
    expect(last.y + last.height).toBeLessThanOrEqual(285);
  });

  test('the last picture choice is centered when the final row has one card', async ({ page }) => {
    const result = await mount(page, short.slice(0, 5));
    expect(result.cards).toHaveLength(5);
    const cards = page.locator('#ll-tools-flashcard .ll-answer-option-image-caption-card');
    const checkCentered = async () => {
      const left = await cards.first().boundingBox();
      const right = await cards.nth(1).boundingBox();
      const last = await cards.last().boundingBox();
      expect(left.y).toBe(right.y);
      expect(last.y).toBeGreaterThan(left.y);
      expect(last.width).toBe(150);
      expect(last.x + last.width / 2).toBeCloseTo((left.x + right.x + right.width) / 2, 1);
    };

    await checkCentered();
    // Incorrect answers are removed from the grid without rebuilding the choices.
    await cards.nth(1).evaluate(card => card.remove());
    await expect(cards).toHaveCount(4);
    await cards.nth(1).evaluate(card => card.remove());
    await expect(cards).toHaveCount(3);
    await checkCentered();
  });

  test('the header stays still and incoming choices stay hidden until the round is ready', async ({ page }) => {
    await mount(page, short);
    await addFrameScript(page, domSource);
    const popup = page.locator('#ll-tools-flashcard-quiz-popup');
    const content = page.locator('#ll-tools-flashcard-content');
    const controls = popup.locator('#ll-tools-close-flashcard, .learning-progress-bar, #ll-tools-category-display, #ll-tools-repeat-flashcard');
    const boxes = () => controls.evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().toJSON()));
    const initial = await boxes();

    await page.evaluate(() => {
      const grid = document.getElementById('ll-tools-flashcard');
      window.incomingChoices = Array.from(grid.children);
      grid.replaceChildren();
      window.LLFlashcards.Dom.showLoading();
    });
    expect(await boxes()).toEqual(initial);
    await expect(content).toHaveCSS('opacity', '0');
    await expect(popup).toHaveAttribute('aria-busy', 'true');
    expect(await popup.evaluate(n => getComputedStyle(n, '::before').display)).toBe('none');
    await page.evaluate(() => document.getElementById('ll-tools-flashcard').append(...window.incomingChoices));
    await expect(content).toHaveCSS('opacity', '0');
    await page.evaluate(() => window.LLFlashcards.Dom.hideLoading());
    await expect(content).toHaveCSS('opacity', '1');
    expect(await boxes()).toEqual(initial);

    await page.evaluate(() => {
      window.LLFlashcards.Dom.showLoading();
      return window.LLFlashcards.Dom.hideLoadingImmediately();
    });
    await expect(content).toHaveCSS('opacity', '1');
  });

  for (const portaled of [true, false]) {
    test(`phone controls and padding match across answer types in a ${portaled ? 'portaled' : 'nested'} quiz`, async ({ page }) => {
      let expected;
      for (const optionType of ['image_text_translation', 'image', 'text_translation', 'audio', 'text_audio']) {
        await renderQuizFrame(page, {
          width: 360, height: 685, imageSize: 'small', count: 4,
          isEmbed: false, portaled, topLevel: true, optionType, captions: short
        });
        await addFrameScript(page, domSource);
        const geometry = () => page.evaluate(() => {
          const ids = ['ll-tools-close-flashcard', 'll-tools-category-display', 'll-tools-repeat-flashcard'];
          const boxes = ids.map(id => document.getElementById(id).getBoundingClientRect().toJSON());
          const bar = document.querySelector('.learning-progress-bar').getBoundingClientRect().toJSON();
          const content = getComputedStyle(document.getElementById('ll-tools-flashcard-content'));
          const gap = getComputedStyle(document.getElementById('ll-tools-flashcard')).gap;
          return { boxes, bar, padding: content.padding, gap };
        });
        const ready = await geometry();
        expect(ready.padding).toBe('9px 15px 15px');
        expect(ready.gap).toBe('10px 14px');
        if (!expected) expected = ready;
        expect(ready, optionType).toEqual(expected);
        await page.evaluate(() => {
          document.getElementById('ll-tools-flashcard').replaceChildren();
          window.LLFlashcards.Dom.showLoading();
        });
        expect(await geometry(), optionType + ' loading').toEqual(expected);
        await expect(page.locator('#ll-tools-flashcard-content')).toHaveCSS('opacity', '0');
        await page.evaluate(() => window.LLFlashcards.Dom.hideLoadingImmediately());
      }
    });
  }

  test('two double-line captions or one longer caption limit a tall phone to two rows', async ({ page }) => {
    for (const captions of [
      [double, double, ...short.slice(2)],
      [...short.slice(0, 4), double, double],
      [...short.slice(0, 5), 'Arkadaşından eve dönerken ona yardım etmesini istiyor']
    ]) {
      const result = await mount(page, captions, 846);
      checkGrid(result, 4);
      await expect(page.locator('#ll-tools-flashcard [data-word-id="101"]')).toBeVisible();
    }
  });
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

test('resizing during a Learn introduction pulse fits both pictures at their resting size', async ({ page }) => {
  await renderQuizFrame(page, {
    width: 390,
    height: 700,
    imageSize: 'large',
    isEmbed: false,
    topLevel: true,
    portaled: true
  });

  const grid = page.locator('#ll-tools-flashcard');
  const cards = grid.locator('.ll-answer-option-image-card');
  const restingWidths = () => cards.evaluateAll(nodes => nodes.map(card => card.offsetWidth));
  expect(await restingWidths()).toEqual([173, 173]);

  await cards.evaluateAll(nodes => {
    nodes.forEach(card => card.classList.add('introducing'));
    const activeCard = nodes[0];
    activeCard.classList.add('introducing-active');
    const pulse = activeCard.getAnimations().find(animation => animation.animationName === 'pulse-card');
    pulse.pause();
    pulse.currentTime = 500;
  });
  const pulseScale = () => cards.first().evaluate(card => new DOMMatrixReadOnly(getComputedStyle(card).transform).a);
  expect(await pulseScale()).toBeCloseTo(1.08);

  await page.setViewportSize({ width: 360, height: 700 });

  await expect.poll(restingWidths, { timeout: 3000 }).toEqual([158, 158]);
  await expect(grid).not.toHaveClass(/ll-image-options-scroll/);
  expect(await pulseScale()).toBeCloseTo(1.08);
});

test('white image answer options retain a visible tile shadow', async ({ page }) => {
  const rendered = await renderQuizFrame(page, {
    width: 390,
    height: 700,
    imageSize: 'small',
    imageA: '#fff',
    imageB: '#fff'
  });

  expect(rendered.cards).toHaveLength(2);
  for (const card of rendered.cards) {
    expect(card.className).toContain('ll-answer-option-image-card');
    expect(card.boxShadow).not.toBe('none');
    expect(card.boxShadow).toMatch(/rgba?\(/);
  }
});

test('white prompt images retain a visible shadow boundary', async ({ page }) => {
  for (const viewport of [{ width: 390, height: 700 }, { width: 900, height: 760 }]) {
    await page.setViewportSize(viewport);
    await page.goto('about:blank');
    await page.setContent(`
      <div class="ll-tools-prompt" style="display:block;padding:40px;background:#fff;text-align:center;">
        <span class="ll-prompt-image-wrap">
          <img alt="White" width="220" height="220" style="display:block;width:220px;height:220px;background:#fff;" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220'%3E%3Crect width='220' height='220' fill='white'/%3E%3C/svg%3E">
        </span>
      </div>
    `);
    await page.addStyleTag({ content: baseCssSource });

    const boundary = await page.locator('.ll-prompt-image-wrap').evaluate((node) => {
      const style = window.getComputedStyle(node);
      const rect = node.getBoundingClientRect();
      return {
        boxShadow: style.boxShadow,
        backgroundColor: style.backgroundColor,
        width: rect.width,
        height: rect.height
      };
    });

    expect(boundary.boxShadow).not.toBe('none');
    expect(boundary.boxShadow).toMatch(/rgba?\(/);
    expect(boundary.backgroundColor).toBe('rgb(255, 255, 255)');
    expect(boundary.width).toBeGreaterThan(100);
    expect(boundary.height).toBeGreaterThan(100);
  }
});
