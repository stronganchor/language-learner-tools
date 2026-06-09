const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000 });

function seedFixture() {
  const scriptPath = path.resolve(__dirname, '..', 'fixtures', 'seed-text-to-text-learning-intro.php');
  return runWpCliJson(['eval-file', scriptPath], { timeoutMs: 120000 });
}

test('text-to-text learning popup renders prompt and answer introduction pair cards', async ({ page }) => {
  let fixture;
  try {
    fixture = seedFixture();
  } catch (error) {
    if (error && error.isWpCliUnavailable) {
      test.skip(true, `Unable to seed WordPress text-to-text fixture through WP-CLI: ${error.message}`);
      return;
    }
    throw error;
  }

  await page.goto(fixture.pagePath, { waitUntil: 'domcontentloaded' });

  const trigger = page.locator('.ll-quiz-page-trigger').filter({
    hasText: fixture.categoryName
  }).first();
  await expect(trigger).toBeVisible({ timeout: 60000 });

  const triggerData = await trigger.evaluate((el) => ({
    mode: el.getAttribute('data-mode') || '',
    promptType: el.getAttribute('data-prompt-type') || '',
    optionType: el.getAttribute('data-option-type') || '',
    wordsetId: el.getAttribute('data-wordset-id') || ''
  }));
  expect(triggerData).toMatchObject({
    mode: 'learning',
    promptType: 'text_translation',
    optionType: 'text_title',
    wordsetId: String(fixture.wordsetId)
  });

  const wordsResponsePromise = page.waitForResponse((response) => (
    response.url().includes('/wp-admin/admin-ajax.php')
    && (response.request().postData() || '').includes('action=ll_get_words_by_category')
    && (response.request().postData() || '').includes(`wordset=${fixture.wordsetId}`)
  ), { timeout: 90000 });

  await trigger.click({ force: true });

  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  await expect(popup).toBeVisible({ timeout: 60000 });
  await expect(page.locator('body')).toHaveClass(/ll-qpg-popup-active/);

  const wordsResponse = await wordsResponsePromise;
  expect(wordsResponse.ok()).toBe(true);
  const wordsPayload = await wordsResponse.json();
  expect(wordsPayload.success).toBe(true);
  expect(Array.isArray(wordsPayload.data)).toBe(true);
  expect(wordsPayload.data.length).toBeGreaterThanOrEqual(fixture.words.length);

  const pairCards = popup.locator('.ll-learning-intro-pair-card');
  await expect(pairCards.first()).toBeVisible({ timeout: 90000 });
  await expect.poll(async () => pairCards.count(), { timeout: 90000 }).toBeGreaterThanOrEqual(2);

  const allowedPairs = new Map(
    fixture.words.map((word) => [String(word.translation || '').trim(), String(word.title || '').trim()])
  );
  const renderedPairs = await pairCards.evaluateAll((cards) => cards.map((card) => ({
    prompt: card.querySelector('.ll-learning-intro-pair-prompt')?.textContent?.trim() || '',
    answer: card.querySelector('.ll-learning-intro-pair-answer')?.textContent?.trim() || '',
    dividerVisible: !!card.querySelector('.ll-learning-intro-pair-divider'),
    audioRequired: card.getAttribute('data-audio-required') || ''
  })));

  expect(renderedPairs.length).toBeGreaterThanOrEqual(2);
  for (const pair of renderedPairs) {
    expect(pair.prompt).not.toBe('');
    expect(pair.answer).not.toBe('');
    expect(pair.dividerVisible).toBe(true);
    expect(pair.audioRequired).toBe('0');
    expect(allowedPairs.get(pair.prompt)).toBe(pair.answer);
  }
  expect(renderedPairs.some((pair) => /[\u0590-\u05FF]/.test(pair.answer))).toBe(true);

  await page.locator('#ll-tools-close-flashcard').click({ force: true });
  await expect(popup).toBeHidden({ timeout: 30000 });
  await expect(page.locator('body')).not.toHaveClass(/ll-qpg-popup-active/);
});
