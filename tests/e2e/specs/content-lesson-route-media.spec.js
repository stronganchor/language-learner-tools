const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000 });

function seedFixture() {
  const scriptPath = path.resolve(__dirname, '..', 'fixtures', 'seed-content-lesson-route.php');
  return runWpCliJson(['eval-file', scriptPath], { timeoutMs: 120000 });
}

async function fulfillFixtureAudio(route) {
  await route.fulfill({
    status: 200,
    contentType: 'audio/mpeg',
    body: Buffer.from('ID3\u0003\u0000\u0000\u0000\u0000\u0000\u0000', 'binary')
  });
}

test('content lesson route renders media, transcript cues, notes, and related vocab link', async ({ page }) => {
  let fixture;
  try {
    fixture = seedFixture();
  } catch (error) {
    if (error && error.isWpCliUnavailable) {
      test.skip(true, `Unable to seed WordPress content lesson fixture through WP-CLI: ${error.message}`);
      return;
    }
    throw error;
  }

  expect(fixture.lessonPath).toMatch(/\/lesson\/ll-e2e-content-lesson-route\/?$/);
  expect(fixture.cues).toHaveLength(2);

  await page.route(fixture.mediaUrl, fulfillFixtureAudio);
  await page.goto(fixture.lessonPath, { waitUntil: 'domcontentloaded' });

  const lesson = page.locator('[data-ll-content-lesson]');
  await expect(lesson).toBeVisible({ timeout: 60000 });
  await expect(page.locator('.ll-content-lesson-title')).toHaveText(fixture.lessonTitle);
  await expect(page.locator('.ll-content-lesson-summary')).toContainText(fixture.lessonExcerpt);
  await expect(page.locator('.ll-content-lesson-pill')).toHaveText('Audio lesson');

  const mediaSource = page.locator('[data-ll-content-lesson-media] source');
  await expect(mediaSource).toHaveAttribute('src', fixture.mediaUrl);
  await expect(page.locator('[data-ll-content-lesson-player]')).toBeVisible();

  const cueButtons = page.locator('[data-ll-content-lesson-cue]');
  await expect(cueButtons).toHaveCount(fixture.cues.length);
  await expect(cueButtons.nth(0)).toContainText(fixture.cues[0].text);
  await expect(cueButtons.nth(0)).toHaveAttribute('data-start-ms', String(fixture.cues[0].start_ms));
  await expect(cueButtons.nth(0)).toHaveAttribute('data-end-ms', String(fixture.cues[0].end_ms));
  await expect(cueButtons.nth(1)).toContainText(fixture.cues[1].text);
  await expect(page.locator('.ll-content-lesson-stage__count')).toHaveText(`${fixture.cues.length} cues`);

  const cuePayload = await page.locator('script[data-ll-content-lesson-cues]').evaluate((node) => JSON.parse(node.textContent || '[]'));
  expect(cuePayload.map((cue) => cue.text)).toEqual(fixture.cues.map((cue) => cue.text));

  const relatedLink = page.locator('.ll-content-lesson-related-link').first();
  await expect(relatedLink).toBeVisible();
  await expect(relatedLink).toContainText(fixture.categoryName);
  await expect(relatedLink).toHaveAttribute('href', /ll-e2e-content-lesson-vocab|ll-e2e-content-lesson/);

  await expect(page.locator('.ll-content-lesson-notes')).toContainText(fixture.notes);
  await expect.poll(() => page.evaluate(() => window.llToolsContentLessonPlayer && window.llToolsContentLessonPlayer.i18n)).toMatchObject({
    currentCue: expect.any(String),
    transcriptRegion: expect.any(String)
  });
});
