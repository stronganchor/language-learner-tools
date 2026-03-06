const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const quizPagesScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/quiz-pages.js'),
  'utf8'
);

test('quiz trigger opens iframe fallback modal when flashcard launcher is absent', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <button
      type="button"
      class="ll-quiz-page-trigger"
      data-category="Demo Category"
      data-url="https://example.com/embed/demo-category?mode=practice"
    >
      Open quiz
    </button>
  `);

  await page.evaluate(() => {
    window.llQuizPages = {
      labels: {
        defaultTitle: 'Quiz',
        closeLabel: 'Close',
        iframeTitle: 'Quiz Content'
      }
    };
    try {
      delete window.llOpenFlashcardForCategory;
    } catch (_) {
      window.llOpenFlashcardForCategory = undefined;
    }
  });

  await page.addScriptTag({ content: quizPagesScriptSource });
  await page.click('.ll-quiz-page-trigger');

  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(1);
  await expect(page.locator('.ll-quiz-modal')).toBeVisible();
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute(
    'src',
    'https://example.com/embed/demo-category?mode=practice'
  );

  await page.keyboard.press('Escape');
  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(0);
});
