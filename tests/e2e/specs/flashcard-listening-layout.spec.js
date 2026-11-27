const { test, expect } = require('@playwright/test');
const path = require('path');
const root = path.resolve(__dirname, '../../..');

async function launchListen(frame, {ratio = 1, caption = ''} = {}) {
  await frame.setContent(`
    <div id="ll-tools-flashcard-container"><div id="ll-tools-flashcard-popup">
      <div id="ll-tools-flashcard-quiz-popup">
        <div id="ll-tools-flashcard-header">
          <div id="ll-tools-learning-progress"></div>
          <div id="ll-tools-category-stack" class="ll-tools-category-stack">
            <span id="ll-tools-category-display"></span>
          </div>
          <button id="ll-tools-close-flashcard">Close</button>
        </div>
        <div id="ll-tools-flashcard-content"><div id="ll-tools-flashcard"></div></div>
      </div>
    </div></div>
  `);
  for (const stylesheet of ['base', 'mode-listening']) {
    await frame.addStyleTag({ path: path.join(root, 'css/flashcard', stylesheet + '.css') });
  }
  await frame.addScriptTag({ path: require.resolve('jquery') });
  await frame.evaluate(({ ratio, caption }) => {
    const category = 'People and their families';
    const image = '<svg xmlns="http://www.w3.org/2000/svg" width="' + Math.round(200 * ratio) + '" height="200"><rect width="100%" height="100%" fill="gold"/></svg>';
    const prompt = caption ? 'image' : 'audio';
    window.llToolsFlashcardsData = {
      wordset: 'test', wordsetIds: [7], runtimeMode: 'offline',
      categories: [{id: 12, name: category, slug: 'family', listening_supported: true, prompt_type: prompt, option_type: caption ? 'text_translation' : 'image'}],
      offlineCategoryData: { [category]: [{
        id: 11, title: 'Word', label: caption || 'Family', translation: caption || 'Family',
        wordset_ids: [7], image: 'data:image/svg+xml,' + encodeURIComponent(image), audio: 'https://audio.test/test.mp3'
      }] }
    };
    window.llToolsFlashcardsMessages = {};
    window.llToolsStudyPrefs = {starMode: 'normal', starredWordIds: []};
    window.jQuery.fx.off = true;
  }, { ratio, caption });
  for (const module of ['audio', 'loader', 'options', 'util', 'state', 'dom', 'effects', 'cards',
    'selection', 'results', 'audio-visualizer', 'modes/listening', 'main']) {
    await frame.addScriptTag({ path: path.join(root, 'js/flashcard-widget', module + '.js') });
  }
  await frame.evaluate(() => {
    window.initFlashcardWidget(['People and their families'], 'listening');
  });
  await expect(frame.locator('.listening-stack .quiz-image')).toBeVisible();
}

async function measurePlayer(frame) {
  return frame.evaluate(() => {
    const rect = selector => document.querySelector(selector).getBoundingClientRect();
    const header = rect('#ll-tools-flashcard-header'), picture = rect('.listening-placeholder');
    const wave = rect('#ll-tools-listening-visualizer'), controls = rect('#ll-tools-listening-controls');
    return {
      pictureSize: [picture.width, picture.height],
      fits: picture.width > 0 && picture.height > 0 && header.bottom <= picture.top + 1
        && picture.bottom <= wave.top + 1 && wave.bottom <= controls.top + 1
        && controls.left >= 0 && controls.right <= innerWidth + 1 && controls.bottom <= innerHeight + 1
    };
  });
}

test.beforeEach(async ({ page }) => {
  await page.route('https://listen.test/**', route => route.fulfill({contentType: 'text/html', body: '<!doctype html>'}));
  await page.goto('https://listen.test/');
  await page.route('https://audio.test/**', route => route.fulfill({
    path: path.join(root, 'media/right-answer.mp3'), contentType: 'audio/mpeg',
    headers: { 'Access-Control-Allow-Origin': '*' }
  }));
});

test('Listen reserves room for the waveform and playback controls through phone rotation', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 });
  await launchListen(page.mainFrame());
  const pause = page.locator('#ll-listen-toggle');
  await pause.click();
  await expect(pause).toHaveAttribute('data-state', 'paused');
  for (const [width, height] of [[1280, 720], [1051, 846], [390, 844], [844, 390], [320, 568]]) {
    await page.setViewportSize({ width, height });
    await expect.poll(async () => (await measurePlayer(page)).fits).toBe(true);
    const [w, h] = (await measurePlayer(page)).pictureSize;
    expect(Math.abs(w - h)).toBeLessThan(2);
    expect(w).toBeGreaterThan(width === 844 ? 100 : 250);
  }
});

test('wide Listen pictures retain their proportions inside an embedded quiz', async ({ page }) => {
  await page.setContent('<iframe style="width:960px;height:650px"></iframe>');
  const frame = await page.locator('iframe').elementHandle().then(el => el.contentFrame());
  await launchListen(frame, { ratio: 2.5 });
  await expect.poll(async () => (await measurePlayer(frame)).fits).toBe(true);
  const [w, h] = (await measurePlayer(frame)).pictureSize;
  expect(w).toBeGreaterThanOrEqual(500);
  expect(w / h).toBeCloseTo(2.5, 1);
});

test('a tall Listen picture leaves room when its answer wraps in the waveform row', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 600 });
  const caption = 'The people who came to visit my family yesterday afternoon';
  await launchListen(page.mainFrame(), { ratio: 0.55, caption });
  await expect(page.locator('.listening-visualizer-text')).toHaveText(caption, {timeout: 12000});
  await expect.poll(async () => (await measurePlayer(page)).fits).toBe(true);
  const [w, h] = (await measurePlayer(page)).pictureSize;
  expect(w / h).toBeCloseTo(0.55, 1);
  expect(await page.locator('.listening-visualizer-text').evaluate(e => e.offsetHeight)).toBeGreaterThan(64);
});
