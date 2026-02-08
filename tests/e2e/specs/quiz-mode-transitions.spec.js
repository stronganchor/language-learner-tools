const { test, expect } = require('@playwright/test');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

async function openModeMenu(page) {
  const switcher = page.locator('#ll-tools-mode-switcher');
  const menu = page.locator('#ll-tools-mode-menu');

  await expect(switcher).toBeVisible();
  if ((await menu.getAttribute('aria-hidden')) !== 'false') {
    await switcher.click();
  }
  await expect(menu).toHaveAttribute('aria-hidden', 'false');
}

async function switchToMode(page, mode) {
  const option = page.locator(`#ll-tools-mode-menu .ll-tools-mode-option.${mode}`);
  await openModeMenu(page);
  await expect(option).toBeVisible();

  const isDisabled = await option.evaluate((el) => {
    return el.classList.contains('disabled') || el.hasAttribute('disabled');
  });
  if (isDisabled) {
    throw new Error(`Mode option "${mode}" is unexpectedly disabled`);
  }

  await option.click();
  await expect(option).toHaveClass(/active/);
}

test('quiz popup supports mode transitions in the primary learn flow', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const quizTriggers = page.locator('.ll-quiz-page-trigger');
  await expect(quizTriggers.first()).toBeVisible({ timeout: 60000 });

  await quizTriggers.first().click({ force: true });

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });
  await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeVisible({ timeout: 60000 });

  await switchToMode(page, 'listening');
  await switchToMode(page, 'practice');

  const learningOption = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.learning');
  await openModeMenu(page);
  const learningDisabled = await learningOption.evaluate((el) => {
    return el.classList.contains('disabled') || el.hasAttribute('disabled');
  });
  if (!learningDisabled) {
    await learningOption.click();
    await expect(learningOption).toHaveClass(/active/);
  }
});
