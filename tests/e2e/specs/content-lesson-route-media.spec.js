const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000 });

function seedFixture() {
  const scriptPath = path.resolve(__dirname, '..', 'fixtures', 'seed-content-lesson-route.php');
  return runWpCliJson(['eval-file', scriptPath], { timeoutMs: 120000 });
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
  expect(fixture.mediaUrl).toMatch(/\/wp-content\/uploads\/ll-tools-e2e\/content-lesson-silence\.wav$/);

  const fixtureMediaPath = new URL(fixture.mediaUrl, 'https://fixture.invalid').pathname;
  const mediaStatuses = [];
  page.on('response', (response) => {
    if (new URL(response.url()).pathname === fixtureMediaPath) {
      mediaStatuses.push(response.status());
    }
  });
  await page.goto(fixture.lessonPath, { waitUntil: 'domcontentloaded' });

  const lesson = page.locator('[data-ll-content-lesson]');
  await expect(lesson).toBeVisible({ timeout: 60000 });
  await expect(page.locator('.ll-content-lesson-title')).toHaveText(fixture.lessonTitle);
  await expect(page.locator('.ll-content-lesson-summary')).toContainText(fixture.lessonExcerpt);
  await expect(page.locator('.ll-content-lesson-pill')).toHaveText('Audio lesson');

  const media = page.locator('[data-ll-content-lesson-media]');
  const mediaSource = media.locator('source');
  await expect(mediaSource).toHaveAttribute('src', fixture.mediaUrl);
  await expect(page.locator('[data-ll-content-lesson-player]')).toBeVisible();
  await media.evaluate((element) => new Promise((resolve, reject) => {
    const timeout = window.setTimeout(() => reject(new Error('Timed out waiting for uploaded WAV metadata.')), 30000);
    const finish = () => {
      if (element.readyState < 1 || !Number.isFinite(element.duration) || element.duration <= 0) {
        return;
      }
      window.clearTimeout(timeout);
      element.removeEventListener('loadedmetadata', finish);
      element.removeEventListener('error', fail);
      resolve();
    };
    const fail = () => {
      window.clearTimeout(timeout);
      reject(new Error('The uploaded WAV could not be loaded.'));
    };
    element.addEventListener('loadedmetadata', finish);
    element.addEventListener('error', fail);
    element.load();
    finish();
  }));
  await expect.poll(() => mediaStatuses.some((status) => status === 200 || status === 206)).toBe(true);
  const mediaDuration = await media.evaluate((element) => element.duration);
  expect(Number.isFinite(mediaDuration)).toBe(true);
  expect(mediaDuration).toBeGreaterThanOrEqual(fixture.mediaDurationSeconds - 0.1);

  const cueButtons = page.locator('[data-ll-content-lesson-cue]');
  await expect(cueButtons).toHaveCount(fixture.cues.length);
  await expect(cueButtons.nth(0)).toContainText(fixture.cues[0].text);
  await expect(cueButtons.nth(0)).toHaveAttribute('data-start-ms', String(fixture.cues[0].start_ms));
  await expect(cueButtons.nth(0)).toHaveAttribute('data-end-ms', String(fixture.cues[0].end_ms));
  await expect(cueButtons.nth(1)).toContainText(fixture.cues[1].text);
  await expect(page.locator('.ll-content-lesson-stage__count')).toHaveText(`${fixture.cues.length} cues`);

  await cueButtons.nth(0).click();
  await expect(cueButtons.nth(0)).toHaveAttribute('aria-pressed', 'true');
  const cueCurrentTime = await media.evaluate((element) => {
    element.pause();
    return element.currentTime;
  });
  expect(cueCurrentTime).toBeGreaterThanOrEqual(0.9);
  expect(cueCurrentTime).toBeLessThan(1.5);

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

test('corpus text route renders the reader, collection navigation, assets, and sources view', async ({ page }) => {
  let fixture;
  try {
    fixture = seedFixture();
  } catch (error) {
    if (error && error.isWpCliUnavailable) {
      test.skip(true, `Unable to seed WordPress corpus lesson fixture through WP-CLI: ${error.message}`);
      return;
    }
    throw error;
  }

  expect(fixture.corpusLessonPath).toMatch(/\/lesson\/ll-e2e-corpus-text-route\/?$/);
  expect(fixture.corpusCollectionPath).toMatch(/\/ll-e2e-corpus-texts\/?$/);

  await page.goto(fixture.corpusLessonPath, { waitUntil: 'domcontentloaded' });

  const lesson = page.locator('[data-ll-content-lesson]');
  await expect(lesson).toBeVisible({ timeout: 60000 });
  await expect(page.getByRole('heading', { name: fixture.corpusLessonTitle, exact: true })).toHaveCount(1);
  await expect(page.locator('.ll-content-lesson-title')).toHaveText(fixture.corpusLessonTitle);
  await expect(page.locator('.ll-content-lesson-summary')).toContainText(fixture.corpusLessonExcerpt);
  await expect(page.locator('.ll-text-reader')).toContainText(fixture.corpusReaderSource);
  await expect(page.locator('.ll-text-reader')).toContainText(fixture.corpusReaderTranslation);
  await expect(page.locator('.ll-text-document__title')).toHaveCount(0);

  const titleId = await page.locator('.ll-content-lesson-title').getAttribute('id');
  expect(titleId).toBeTruthy();
  await expect(page.locator('[data-ll-text-document]')).toHaveAttribute('aria-labelledby', titleId);

  const collectionLink = page.locator('.ll-content-lesson-back--corpus');
  await expect(collectionLink).toBeVisible();
  await expect(collectionLink).toContainText(fixture.corpusCollectionTitle);
  const collectionHref = await collectionLink.getAttribute('href');
  expect(new URL(collectionHref, page.url()).pathname).toBe(
    new URL(fixture.corpusCollectionPath, page.url()).pathname
  );

  await expect(page.locator('.ll-content-lesson-pill')).toHaveCount(0);
  await expect(page.locator('[data-ll-content-lesson-player]')).toHaveCount(0);
  await expect(page.locator('script[src*="/js/text-document.js"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/content-lesson-player.js"]')).toHaveCount(0);

  await page.goto(`${fixture.corpusLessonPath}?ll_text_view=sources`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(/ll_text_view=sources/);
  await expect(page.locator('.ll-text-sources')).toContainText(fixture.corpusSourceLabel);
  await expect(page.locator('.ll-text-sources')).toContainText(fixture.corpusSourceCitation);
  await expect(page.locator('[data-ll-text-document]')).toHaveAttribute('data-view', 'sources');
  await expect(page.locator('script[src*="/js/text-document.js"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/content-lesson-player.js"]')).toHaveCount(0);
});
