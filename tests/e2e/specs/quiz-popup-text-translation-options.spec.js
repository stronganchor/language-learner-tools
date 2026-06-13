const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000 });

function seedFixture() {
  const scriptPath = path.resolve(__dirname, '..', 'fixtures', 'seed-quiz-popup-text-translation-options.php');
  return runWpCliJson(['eval-file', scriptPath], { timeoutMs: 120000 });
}

function writeAscii(buffer, offset, value) {
  buffer.write(value, offset, value.length, 'ascii');
}

function silentWavBuffer() {
  const sampleRate = 8000;
  const channels = 1;
  const bitsPerSample = 16;
  const sampleCount = 960;
  const dataSize = sampleCount * channels * (bitsPerSample / 8);
  const buffer = Buffer.alloc(44 + dataSize);
  const byteRate = sampleRate * channels * (bitsPerSample / 8);
  const blockAlign = channels * (bitsPerSample / 8);

  writeAscii(buffer, 0, 'RIFF');
  buffer.writeUInt32LE(36 + dataSize, 4);
  writeAscii(buffer, 8, 'WAVE');
  writeAscii(buffer, 12, 'fmt ');
  buffer.writeUInt32LE(16, 16);
  buffer.writeUInt16LE(1, 20);
  buffer.writeUInt16LE(channels, 22);
  buffer.writeUInt32LE(sampleRate, 24);
  buffer.writeUInt32LE(byteRate, 28);
  buffer.writeUInt16LE(blockAlign, 32);
  buffer.writeUInt16LE(bitsPerSample, 34);
  writeAscii(buffer, 36, 'data');
  buffer.writeUInt32LE(dataSize, 40);

  return buffer;
}

test('quiz popup keeps text translation answer options from the launch card', async ({ page }) => {
  let fixture;
  try {
    fixture = seedFixture();
  } catch (error) {
    if (error && error.isWpCliUnavailable) {
      test.skip(true, `Unable to seed WordPress quiz popup translation fixture through WP-CLI: ${error.message}`);
      return;
    }
    throw error;
  }

  const fixtureAudio = silentWavBuffer();
  await page.route('**/wp-content/uploads/ll-tools-e2e/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'audio/wav',
      body: fixtureAudio
    });
  });

  await page.goto(fixture.pagePath, { waitUntil: 'domcontentloaded' });

  const trigger = page.locator('.ll-quiz-page-trigger').filter({
    hasText: fixture.categoryName
  }).first();
  await expect(trigger).toBeVisible({ timeout: 60000 });

  const triggerData = await trigger.evaluate((el) => ({
    mode: el.getAttribute('data-mode') || '',
    displayMode: el.getAttribute('data-display-mode') || '',
    promptType: el.getAttribute('data-prompt-type') || '',
    optionType: el.getAttribute('data-option-type') || '',
    wordsetId: el.getAttribute('data-wordset-id') || ''
  }));
  expect(triggerData).toMatchObject({
    mode: 'practice',
    displayMode: 'text_translation',
    promptType: 'audio',
    optionType: 'text_translation',
    wordsetId: String(fixture.wordsetId)
  });

  await page.evaluate((categoryName) => {
    const data = window.llToolsFlashcardsData || {};
    const categories = Array.isArray(data.categories) ? data.categories : [];
    const target = String(categoryName || '').trim().toLowerCase();
    const category = categories.find((entry) => String(entry && entry.name || '').trim().toLowerCase() === target);
    if (!category) {
      throw new Error(`Unable to find bootstrapped fixture category: ${categoryName}`);
    }

    category.mode = 'image';
    category.display_mode = 'image';
    category.option_type = 'image';
    category.prompt_type = 'audio';
  }, fixture.categoryName);

  const wordsResponsePromise = page.waitForResponse((response) => {
    if (!response.url().includes('/wp-admin/admin-ajax.php')) {
      return false;
    }
    const postData = response.request().postData() || '';
    return postData.includes('action=ll_get_words_by_category')
      && postData.includes(`wordset=${fixture.wordsetId}`)
      && postData.includes('prompt_type=audio')
      && postData.includes('option_type=text_translation');
  }, { timeout: 90000 });

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

  const rowsByTitle = new Map(wordsPayload.data.map((word) => [String(word.title || '').trim(), word]));
  for (const word of fixture.words) {
    const row = rowsByTitle.get(String(word.title || '').trim());
    expect(row, `AJAX row for ${word.title}`).toBeTruthy();
    expect(String(row.translation || '').trim()).toBe(word.translation);
    expect(String(row.label || '').trim()).toBe(word.translation);
    expect(String(row.label || '').trim()).not.toBe(word.title);
  }

  const answerLabels = popup.locator('#ll-tools-flashcard .flashcard-container.text-based .quiz-text');
  await expect(answerLabels.first()).toBeVisible({ timeout: 90000 });
  await expect.poll(async () => answerLabels.count(), { timeout: 90000 }).toBeGreaterThanOrEqual(2);

  const renderedLabels = await answerLabels.evaluateAll((nodes) => nodes.map((node) => String(node.textContent || '').trim()).filter(Boolean));
  const translationSet = new Set(fixture.words.map((word) => word.translation));
  const titleSet = new Set(fixture.words.map((word) => word.title));

  expect(renderedLabels.length).toBeGreaterThanOrEqual(2);
  for (const label of renderedLabels) {
    expect(translationSet.has(label), `Expected rendered answer "${label}" to be a translation`).toBe(true);
    expect(titleSet.has(label), `Expected rendered answer "${label}" not to be the Zazaki title`).toBe(false);
  }

  await expect(popup.locator('#ll-tools-flashcard .flashcard-container.ll-answer-option-image-card')).toHaveCount(0);

  await page.locator('#ll-tools-close-flashcard').click({ force: true });
  await expect(popup).toBeHidden({ timeout: 30000 });
  await expect(page.locator('body')).not.toHaveClass(/ll-qpg-popup-active/);
});
