const { test, expect } = require('@playwright/test');
const { adminRest, ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

test.describe.configure({ timeout: 240000 });

function uniqueSuffix() {
  return `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
}

async function deleteRestResource(page, path) {
  try {
    await adminRest(page, path, { method: 'DELETE' });
  } catch (_) {
    // Best-effort cleanup should not hide the behavior under test.
  }
}

test('recorder shortcode localizes real prompt-card queue items from WordPress', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for admin E2E tests.');

  await ensureLoggedIntoAdmin(page);

  const suffix = uniqueSuffix();
  const fixtures = {
    categoryId: 0,
    pageId: 0,
    promptCardId: 0,
    wordsetId: 0
  };

  const wordsetSlug = `e2e-prompt-recorder-${suffix}`;
  const categorySlug = `e2e-prompt-category-${suffix}`;
  const promptTitle = `E2E prompt card ${suffix}`;

  try {
    const wordset = await adminRest(page, '/wp-json/wp/v2/wordsets', {
      method: 'POST',
      body: {
        name: `E2E Prompt Recorder ${suffix}`,
        slug: wordsetSlug
      }
    });
    fixtures.wordsetId = wordset.id;

    const category = await adminRest(page, '/wp-json/wp/v2/word-category', {
      method: 'POST',
      body: {
        name: `E2E Prompt Category ${suffix}`,
        slug: categorySlug
      }
    });
    fixtures.categoryId = category.id;

    const promptCard = await adminRest(page, '/wp-json/wp/v2/ll_prompt_card', {
      method: 'POST',
      body: {
        title: promptTitle,
        status: 'publish',
        wordsets: [fixtures.wordsetId],
        'word-category': [fixtures.categoryId]
      }
    });
    fixtures.promptCardId = promptCard.id;

    const recorderPage = await adminRest(page, '/wp-json/wp/v2/pages', {
      method: 'POST',
      body: {
        title: `E2E prompt recorder page ${suffix}`,
        content: `[audio_recording_interface wordset="${wordsetSlug}" include_recording_types="prompt"]`,
        status: 'publish'
      }
    });
    fixtures.pageId = recorderPage.id;

    await page.goto(recorderPage.link, { waitUntil: 'domcontentloaded' });
    await expect.poll(() => page.evaluate(() => Array.isArray(window.ll_recorder_data && window.ll_recorder_data.images))).toBe(true);

    const recorderData = await page.evaluate(() => window.ll_recorder_data);
    const promptItem = recorderData.images.find((item) => Number(item.prompt_card_id || 0) === fixtures.promptCardId);

    expect(recorderData.wordset).toBe(wordsetSlug);
    expect((recorderData.wordset_ids || []).map(Number)).toContain(fixtures.wordsetId);
    expect(promptItem).toMatchObject({
      id: 0,
      prompt_card_id: fixtures.promptCardId,
      title: promptTitle,
      category_slug: categorySlug,
      word_id: 0,
      word_title: promptTitle,
      word_translation: '',
      use_word_display: true,
      missing_types: ['prompt'],
      existing_types: [],
      prompt_types: ['prompt'],
      my_existing_types: [],
      is_text_only: true,
      is_prompt_audio: true,
      hide_key: `prompt_card:${fixtures.promptCardId}`
    });

    await expect(page.locator('#ll-image-title')).toHaveText(promptTitle);
    await expect(page.locator('#ll-recording-type')).toHaveValue('prompt');
    await expect(page.locator('.ll-recording-type-choice[data-recording-type-value="prompt"]')).toHaveClass(/is-needed/);
  } finally {
    if (fixtures.pageId) {
      await deleteRestResource(page, `/wp-json/wp/v2/pages/${fixtures.pageId}?force=true`);
    }
    if (fixtures.promptCardId) {
      await deleteRestResource(page, `/wp-json/wp/v2/ll_prompt_card/${fixtures.promptCardId}?force=true`);
    }
    if (fixtures.categoryId) {
      await deleteRestResource(page, `/wp-json/wp/v2/word-category/${fixtures.categoryId}?force=true`);
    }
    if (fixtures.wordsetId) {
      await deleteRestResource(page, `/wp-json/wp/v2/wordsets/${fixtures.wordsetId}?force=true`);
    }
  }
});
