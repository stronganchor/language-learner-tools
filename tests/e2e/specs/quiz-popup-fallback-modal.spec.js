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
        closeLabel: 'Kapat',
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
  await expect(page.locator('.ll-quiz-modal button')).toHaveAccessibleName('Kapat');
  const closeButtonText = await page.locator('.ll-quiz-modal button').evaluate((node) => node.textContent || '');
  expect(closeButtonText).toContain('×');
  expect(closeButtonText).toContain('Kapat');
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute(
    'src',
    'https://example.com/embed/demo-category?mode=practice'
  );

  const cancelDialogPromise = page.waitForEvent('dialog');
  await page.evaluate(() => {
    window.setTimeout(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'Backspace',
        bubbles: true,
        cancelable: true
      }));
    }, 0);
  });
  const cancelDialog = await cancelDialogPromise;
  expect(cancelDialog.message()).toContain('Close this quiz?');
  await cancelDialog.dismiss();

  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(1);

  const acceptDialogPromise = page.waitForEvent('dialog');
  await page.evaluate(() => {
    window.setTimeout(() => window.history.back(), 0);
  });
  const acceptDialog = await acceptDialogPromise;
  expect(acceptDialog.message()).toContain('Close this quiz?');
  await acceptDialog.accept();

  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(0);

  await page.click('.ll-quiz-page-trigger');
  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(1);

  await page.keyboard.press('Escape');
  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(0);
});
