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
    const buttons = Array.from(document.querySelectorAll('#quiz-mode-buttons .quiz-mode-button'));
    if (!results || !popup || !buttons.length) {
      return null;
    }

    const resultsRect = results.getBoundingClientRect();
    const popupRect = popup.getBoundingClientRect();
    const lastButtonRect = buttons[buttons.length - 1].getBoundingClientRect();

    return {
      topOffset: Math.round(resultsRect.top - popupRect.top),
      lastButtonBottom: Math.round(lastButtonRect.bottom - popupRect.top),
      viewportHeight: window.innerHeight
    };
  });

  expect(metrics).not.toBeNull();
  expect(metrics.topOffset).toBeGreaterThanOrEqual(40);
  expect(metrics.topOffset).toBeLessThan(180);
  expect(metrics.lastButtonBottom).toBeLessThanOrEqual(metrics.viewportHeight - 24);
});
