const { test, expect } = require('@playwright/test');

async function gotoPageWithWidgetStartButton(page) {
  const candidates = ['/', '/home/'];
  for (const path of candidates) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    if ((await page.locator('#ll-tools-start-flashcard').count()) > 0) {
      return;
    }
  }
  throw new Error('Could not find #ll-tools-start-flashcard on / or /home/');
}

test('standalone flashcard widget start flow reaches quiz popup', async ({ page }) => {
  await gotoPageWithWidgetStartButton(page);

  const startButton = page.locator('#ll-tools-start-flashcard');
  await expect(startButton).toBeVisible({ timeout: 60000 });
  await startButton.click();

  await page.waitForFunction(() => {
    const isVisible = (id) => {
      const el = document.getElementById(id);
      if (!el) return false;
      const style = window.getComputedStyle(el);
      return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0;
    };
    return isVisible('ll-tools-category-selection-popup') || isVisible('ll-tools-flashcard-quiz-popup');
  }, { timeout: 30000 });

  const categoryPopup = page.locator('#ll-tools-category-selection-popup');
  const quizPopup = page.locator('#ll-tools-flashcard-quiz-popup');

  if (await categoryPopup.isVisible()) {
    await expect(page.locator('#ll-tools-category-checkboxes input[type="checkbox"]').first()).toBeVisible();
    await page.locator('#ll-tools-check-all').click();
    await page.locator('#ll-tools-start-selected-quiz').click();
  }

  await expect(quizPopup).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeVisible({ timeout: 60000 });

  await page.locator('#ll-tools-close-flashcard').click({ force: true });
  await expect(quizPopup).toBeHidden({ timeout: 30000 });
});
