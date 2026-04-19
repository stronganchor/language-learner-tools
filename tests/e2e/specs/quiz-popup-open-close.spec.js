const { test, expect } = require('@playwright/test');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

test('quiz card opens popup and close button restores page state', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const firstTrigger = page.locator('.ll-quiz-page-trigger').first();
  await expect(firstTrigger).toBeVisible({ timeout: 60000 });

  await firstTrigger.click({ force: true });

  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  const loader = page.locator('#ll-tools-loading-animation');
  await expect(popup).toBeVisible({ timeout: 60000 });
  await expect(page.locator('body')).toHaveClass(/ll-qpg-popup-active/);

  await page.evaluate(() => {
    const el = document.getElementById('ll-tools-loading-animation');
    if (el) {
      el.style.display = 'block';
    }
  });
  await expect(loader).toBeVisible();

  await page.locator('#ll-tools-close-flashcard').click({ force: true });

  await expect(popup).toBeHidden({ timeout: 30000 });
  await expect(loader).toBeHidden({ timeout: 30000 });
  await expect(page.locator('body')).not.toHaveClass(/ll-qpg-popup-active/);

  await page.evaluate(() => {
    if (window.LLFlashcards && window.LLFlashcards.Dom && typeof window.LLFlashcards.Dom.showLoading === 'function') {
      window.LLFlashcards.Dom.showLoading();
    }
  });
  await page.waitForTimeout(250);
  await expect(loader).toBeHidden();
});

test('quiz popup blocks selection and confirms before backspace or browser-back close', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const firstTrigger = page.locator('.ll-quiz-page-trigger').first();
  await expect(firstTrigger).toBeVisible({ timeout: 60000 });

  await firstTrigger.click({ force: true });

  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  await expect(popup).toBeVisible({ timeout: 60000 });

  const userSelect = await popup.evaluate((el) => window.getComputedStyle(el).userSelect);
  expect(userSelect).toBe('none');

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

  await expect(popup).toBeVisible();
  await expect(page.locator('body')).toHaveClass(/ll-qpg-popup-active/);

  const acceptDialogPromise = page.waitForEvent('dialog');
  await page.evaluate(() => {
    window.setTimeout(() => window.history.back(), 0);
  });
  const acceptDialog = await acceptDialogPromise;
  expect(acceptDialog.message()).toContain('Close this quiz?');
  await acceptDialog.accept();

  await expect(popup).toBeHidden({ timeout: 30000 });
  await expect(page.locator('body')).not.toHaveClass(/ll-qpg-popup-active/);
});
