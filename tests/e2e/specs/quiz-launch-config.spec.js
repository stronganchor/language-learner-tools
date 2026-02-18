const { test, expect } = require('@playwright/test');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

test('quiz launch forwards selected category, mode, and wordset into widget state', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const firstTrigger = page.locator('.ll-quiz-page-trigger').first();
  await expect(firstTrigger).toBeVisible({ timeout: 60000 });

  const triggerData = await firstTrigger.evaluate((el) => ({
    category: el.getAttribute('data-category') || '',
    mode: el.getAttribute('data-mode') || 'practice',
    wordsetId: el.getAttribute('data-wordset-id') || '',
    wordsetSlug: el.getAttribute('data-wordset') || ''
  }));

  await firstTrigger.click({ force: true });
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });

  await page.waitForFunction(() => {
    return !!(window.llToolsFlashcardsData && Array.isArray(window.llToolsFlashcardsData.categories));
  });

  const widgetState = await page.evaluate(() => {
    return {
      quizMode: String(window.llToolsFlashcardsData.quiz_mode || ''),
      wordset: String(window.llToolsFlashcardsData.wordset || ''),
      categories: (window.llToolsFlashcardsData.categories || []).map((cat) => String(cat.name || ''))
    };
  });

  expect(widgetState.quizMode).toBe(triggerData.mode);

  if (triggerData.wordsetId) {
    expect(widgetState.wordset).toBe(String(triggerData.wordsetId));
  } else if (triggerData.wordsetSlug) {
    expect(widgetState.wordset).toBe(String(triggerData.wordsetSlug));
  }

  expect(widgetState.categories).toContain(triggerData.category);
});
