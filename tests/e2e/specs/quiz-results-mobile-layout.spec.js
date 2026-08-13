const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const flashcardCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/base.css'),
  'utf8'
);

test('mobile quiz results stay near the top of the popup', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(`
    <body class="ll-tools-flashcard-open">
      <div id="ll-tools-flashcard-container">
        <div id="ll-tools-flashcard-popup" style="display:block;">
          <div id="ll-tools-flashcard-quiz-popup">
            <button id="ll-tools-close-flashcard" type="button" aria-label="Close">&times;</button>
            <div id="ll-tools-flashcard-header" style="display:none;"></div>
            <div id="ll-tools-flashcard-content">
              <div id="ll-tools-prompt" style="display:none;"></div>
              <div id="ll-tools-flashcard" style="display:none;"></div>
            </div>
            <div id="quiz-results" style="display:block;">
              <h2 id="quiz-results-title">Ev Esyasi: Mutfak 1</h2>
              <p><strong>Correct:</strong> <span id="correct-count">0</span> / <span id="total-questions">4</span></p>
              <div id="quiz-mode-buttons" style="display:flex; margin-top:20px;">
                <button id="restart-practice-mode" class="quiz-button quiz-mode-button" type="button">
                  <span class="button-icon" aria-hidden="true" data-emoji="↻"></span>
                  <span>Tekrarla</span>
                </button>
                <button id="restart-learning-mode" class="quiz-button quiz-mode-button" type="button">
                  <span class="button-icon" aria-hidden="true" data-emoji="🎓"></span>
                  <span>Ogren</span>
                </button>
                <button id="restart-self-check-mode" class="quiz-button quiz-mode-button" type="button">
                  <span class="button-icon" aria-hidden="true" data-emoji="✔✖"></span>
                  <span>Kontrol</span>
                </button>
                <button id="restart-gender-mode" class="quiz-button quiz-mode-button" type="button">
                  <span class="button-icon" aria-hidden="true" data-emoji="⚥"></span>
                  <span>Cinsiyet</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </body>
  `);
  await page.addStyleTag({ content: flashcardCssSource });

  const metrics = await page.evaluate(() => {
    const results = document.querySelector('#quiz-results');
    const popup = document.querySelector('#ll-tools-flashcard-quiz-popup');
    const close = document.querySelector('#ll-tools-close-flashcard');
    const buttons = Array.from(document.querySelectorAll('#quiz-mode-buttons .quiz-mode-button'));
    if (!results || !popup || !close || !buttons.length) {
      return null;
    }

    const resultsRect = results.getBoundingClientRect();
    const popupRect = popup.getBoundingClientRect();
    const closeRect = close.getBoundingClientRect();
    const lastButtonRect = buttons[buttons.length - 1].getBoundingClientRect();

    return {
      closeTop: Math.round(closeRect.top - popupRect.top),
      closeRightGap: Math.round(popupRect.right - closeRect.right),
      closeBottom: Math.round(closeRect.bottom - popupRect.top),
      topOffset: Math.round(resultsRect.top - popupRect.top),
      lastButtonBottom: Math.round(lastButtonRect.bottom - popupRect.top),
      viewportHeight: window.innerHeight
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.closeTop).toBeGreaterThanOrEqual(8);
  expect(metrics.closeTop).toBeLessThan(40);
  expect(metrics.closeRightGap).toBeGreaterThanOrEqual(8);
  expect(metrics.closeRightGap).toBeLessThan(40);
  expect(metrics.closeBottom).toBeLessThan(metrics.topOffset);
  expect(metrics.topOffset).toBeGreaterThanOrEqual(40);
  expect(metrics.topOffset).toBeLessThan(180);
  expect(metrics.lastButtonBottom).toBeLessThanOrEqual(metrics.viewportHeight - 24);
});

test('mobile continuation error resists hostile theme styles and hides in-round controls', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(`
    <body class="ll-tools-flashcard-open">
      <div id="ll-tools-flashcard-container">
        <div id="ll-tools-flashcard-popup" style="display:block;">
          <div id="ll-tools-flashcard-quiz-popup" class="ll-tools-error-state">
            <button id="ll-tools-close-flashcard" type="button" aria-label="Close">&times;</button>
            <div id="ll-tools-flashcard-header">
              <div id="ll-tools-learning-progress" style="display:block;"></div>
            </div>
            <div id="ll-tools-flashcard-content"></div>
            <div id="ll-tools-mode-switcher-wrap" style="display:block;">
              <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" type="button">Mode</button>
            </div>
            <div id="quiz-results" class="ll-tools-error-state" style="display:block;">
              <h2 id="quiz-results-title">Bir şeyler ters gitti</h2>
              <p id="quiz-results-message"><strong>Hata ayrıntıları:</strong> Lütfen tekrar deneyin.</p>
              <button id="restart-quiz" class="quiz-button" type="button">Yeniden Dene</button>
            </div>
          </div>
        </div>
      </div>
    </body>
  `);
  await page.addStyleTag({ content: flashcardCssSource });
  await page.addStyleTag({ content: `
    #ll-tools-flashcard-quiz-popup button {
      min-height: 20px !important;
      padding: 2px 44px !important;
      border: 8px solid #ff5d22 !important;
      border-radius: 0 !important;
      background: #ff763b !important;
      color: #ffffff !important;
      font: 700 24px/1 cursive !important;
      text-transform: uppercase !important;
      box-shadow: 0 0 0 20px #ff763b !important;
    }
    #ll-tools-flashcard-quiz-popup #quiz-results {
      padding: 0 !important;
      border: 8px solid #ff763b !important;
      border-radius: 0 !important;
      background: #111111 !important;
      color: #ffffff !important;
    }
  ` });

  await expect(page.locator('#ll-tools-learning-progress')).toBeHidden();
  await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeHidden();

  const metrics = await page.evaluate(() => {
    const results = document.querySelector('#quiz-results');
    const retry = document.querySelector('#restart-quiz');
    const resultStyle = window.getComputedStyle(results);
    const retryStyle = window.getComputedStyle(retry);
    const resultRect = results.getBoundingClientRect();
    const retryRect = retry.getBoundingClientRect();
    return {
      resultBackground: resultStyle.backgroundColor,
      resultBorderRadius: resultStyle.borderRadius,
      retryBackground: retryStyle.backgroundColor,
      retryColor: retryStyle.color,
      retryBorderRadius: retryStyle.borderRadius,
      retryTextTransform: retryStyle.textTransform,
      retryHeight: retryRect.height,
      left: resultRect.left,
      right: resultRect.right,
      viewportWidth: window.innerWidth,
      overflowWidth: document.documentElement.scrollWidth
    };
  });

  expect(metrics.resultBackground).toBe('rgb(255, 255, 255)');
  expect(metrics.resultBorderRadius).toBe('18px');
  expect(metrics.retryBackground).toBe('rgb(255, 255, 255)');
  expect(metrics.retryColor).toBe('rgb(31, 41, 55)');
  expect(metrics.retryBorderRadius).toBe('12px');
  expect(metrics.retryTextTransform).toBe('none');
  expect(metrics.retryHeight).toBeGreaterThanOrEqual(44);
  expect(metrics.left).toBeGreaterThanOrEqual(12);
  expect(metrics.right).toBeLessThanOrEqual(metrics.viewportWidth - 12);
  expect(metrics.overflowWidth).toBeLessThanOrEqual(metrics.viewportWidth);

  await page.locator('#restart-quiz').hover();
  await expect(page.locator('#restart-quiz')).toHaveCSS('background-color', 'rgb(248, 250, 252)');
  await page.locator('#restart-quiz').focus();
  await expect(page.locator('#restart-quiz')).toHaveCSS('outline-width', '3px');
});
