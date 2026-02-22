const { test, expect } = require('@playwright/test');

async function gotoPageWithWidgetStartButton(page) {
  const envStandalone = process.env.LL_E2E_STANDALONE_PATH || '';
  const envLearn = process.env.LL_E2E_LEARN_PATH || '/learn/';
  const candidates = [
    envStandalone,
    '/english/',
    '/biblical-hebrew/',
    '/learn/',
    envLearn,
    '/',
    '/home/'
  ].filter((value, index, list) => value && list.indexOf(value) === index);

  for (const path of candidates) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    const startButton = page.locator('#ll-tools-start-flashcard');
    if ((await startButton.count()) === 0) {
      continue;
    }
    try {
      await startButton.first().waitFor({ state: 'visible', timeout: 2500 });
      return path;
    } catch {
      // This route includes the widget markup but hides the standalone start UI.
    }
  }
  return null;
}

test('standalone flashcard widget start flow reaches quiz popup', async ({ page }) => {
  const selectedPath = await gotoPageWithWidgetStartButton(page);
  test.skip(!selectedPath, 'No standalone flashcard page found with a visible start button; set LL_E2E_STANDALONE_PATH for this environment.');

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
