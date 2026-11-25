const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

// Load the real authenticated page/controller/renderer. Only source discovery and
// media fixtures are intercepted, so this test never seeds or changes site data.
// For standalone visual QA, preserve artifacts with --output=test-results-category-video-admin.

function toneWav() {
  const rate = 24000;
  const samples = Math.round(rate * 0.35);
  const bytes = Buffer.alloc(44 + samples * 2);
  bytes.write('RIFF', 0);
  bytes.writeUInt32LE(36 + samples * 2, 4);
  bytes.write('WAVEfmt ', 8);
  bytes.writeUInt32LE(16, 16);
  bytes.writeUInt16LE(1, 20);
  bytes.writeUInt16LE(1, 22);
  bytes.writeUInt32LE(rate, 24);
  bytes.writeUInt32LE(rate * 2, 28);
  bytes.writeUInt16LE(2, 32);
  bytes.writeUInt16LE(16, 34);
  bytes.write('data', 36);
  bytes.writeUInt32LE(samples * 2, 40);
  for (let i = 0; i < samples; i += 1) bytes.writeInt16LE(Math.round(Math.sin(i * 440 * Math.PI * 2 / rate) * 12000), 44 + i * 2);
  return bytes;
}

test('category video admin loads category batches, exports real media, and invalidates changed settings', async ({ page }, testInfo) => {
  test.setTimeout(180000);
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for admin E2E tests.');
  const browserErrors = [];
  page.on('pageerror', error => browserErrors.push(error.message));
  await page.route('**/ll-video-test-fixture/*.svg', route => route.fulfill({
    status: 200,
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="240"><rect width="300" height="240" fill="white"/><circle cx="150" cy="120" r="80" fill="#d93737"/></svg>'
  }));
  await page.route('**/ll-video-test-fixture/*.wav', route => route.fulfill({ status: 200, contentType: 'audio/wav', body: toneWav() }));
  const itemRequests = [];
  await page.route('**/wp-admin/admin-ajax.php', async route => {
    const fields = new URLSearchParams(route.request().postData() || '');
    const action = fields.get('action');
    if (!['ll_tools_category_video_sources', 'll_tools_category_video_items'].includes(action)) return route.continue();
    expect(fields.get('nonce')).toBeTruthy();
    let data;
    if (action === 'll_tools_category_video_sources') {
      if (fields.get('category_id')) {
        data = { speakers: [{ id: 11003, name: 'Video fixture speaker', slug: 'video-fixture-speaker' }], has_more: false, next_cursor: 0 };
      } else if (fields.get('wordset_id')) {
        data = { categories: [{ id: 11002, name: 'Fruit', slug: 'video-fixture-fruit' }], has_more: false, next_cursor: 0 };
      } else {
        data = { wordsets: [{ id: 11001, name: 'Video fixture word set', slug: 'video-fixture-wordset' }], has_more: false, next_cursor: 0 };
      }
    } else {
      expect(fields.get('wordset_id')).toBe('11001');
      expect(fields.get('category_id')).toBe('11002');
      expect(fields.get('speaker_id')).toBe('11003');
      const cursor = Number(fields.get('cursor'));
      itemRequests.push(cursor);
      const origin = new URL(route.request().url()).origin;
      data = {
        items: [{
          id: cursor ? 102 : 101,
          text: cursor ? 'Muz' : 'Sêv — ı ş ê',
          translation: cursor ? 'banana' : 'apple',
          image_url: `${origin}/ll-video-test-fixture/image.svg`,
          audio_url: `${origin}/ll-video-test-fixture/tone.wav`,
          recording_id: cursor ? 202 : 201,
          speaker_id: 11003
        }],
        skipped: cursor ? [] : [{ id: 103, text: 'Unreviewed fixture', reason: 'text_needs_review' }],
        has_more: cursor === 0,
        next_cursor: cursor ? 102 : 101
      };
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data }) });
  });

  await ensureLoggedIntoAdmin(page, '/wp-admin/tools.php?page=ll-category-video');
  await expect(page.locator('#ll-category-video')).toBeVisible();
  await expect(page.locator('#ll-video-wordset')).toBeEnabled();
  await page.selectOption('#ll-video-wordset', '11001');
  await expect(page.locator('#ll-video-category')).toBeEnabled();
  await page.selectOption('#ll-video-category', '11002');
  await expect(page.locator('#ll-video-speaker')).toHaveValue('11003');
  await page.click('#ll-video-load');
  await expect(page.locator('#ll-video-words li')).toHaveCount(1);
  await expect(page.locator('#ll-video-skipped')).toBeVisible();
  await page.locator('#ll-video-skipped summary').click();
  await expect(page.locator('#ll-video-skipped li')).toHaveText('Unreviewed fixture — Recording text needs review');
  await expect(page.locator('#ll-video-skipped li')).toBeVisible();
  await page.click('#ll-video-more');
  await expect(page.locator('#ll-video-words li')).toHaveCount(2);
  await expect(page.locator('#ll-video-more')).toBeHidden();
  expect(itemRequests).toEqual([0, 101]);
  await page.locator('#ll-video-words li').first().locator('button').nth(1).click();
  await expect(page.locator('#ll-video-words li strong').first()).toHaveText('Muz');
  await page.selectOption('#ll-video-repeats', '1');
  await page.fill('#ll-video-gap', '0.5');
  await page.click('#ll-video-prepare');
  await expect(page.locator('#ll-video-record')).toBeEnabled();
  await page.click('#ll-video-record');
  await expect(page.locator('#ll-video-stop')).toBeVisible();
  await page.click('#ll-video-stop');
  await expect(page.locator('#ll-video-record')).toBeEnabled();
  await expect(page.locator('#ll-video-result')).toBeHidden();
  await page.click('#ll-video-record');
  await expect(page.locator('#ll-video-result')).toBeVisible();
  await expect(page.locator('#ll-video-download')).toHaveAttribute('download', /^category-11002-video\.(mp4|webm)$/);
  const output = await page.locator('#ll-video-result video').evaluate(async video => {
    if (video.readyState < 1) await new Promise((resolve, reject) => {
      video.addEventListener('loadedmetadata', resolve, { once: true });
      video.addEventListener('error', () => reject(new Error('Export did not decode')), { once: true });
    });
    const blob = await (await fetch(video.src)).blob();
    const context = new AudioContext();
    const audio = await context.decodeAudioData(await blob.arrayBuffer());
    let peak = 0;
    for (const sample of audio.getChannelData(0)) peak = Math.max(peak, Math.abs(sample));
    await context.close();
    return { width: video.videoWidth, height: video.videoHeight, bytes: blob.size, peak };
  });
  expect(output.width).toBe(1080);
  expect(output.height).toBe(1920);
  expect(output.bytes).toBeGreaterThan(5000);
  expect(output.peak).toBeGreaterThan(0.1);
  const downloadPromise = page.waitForEvent('download');
  await page.click('#ll-video-download');
  const download = await downloadPromise;
  const videoPath = testInfo.outputPath(download.suggestedFilename());
  await download.saveAs(videoPath);
  await testInfo.attach('category-video-admin-output', { path: videoPath, contentType: download.suggestedFilename().endsWith('.mp4') ? 'video/mp4' : 'video/webm' });
  const screenshotPath = testInfo.outputPath('category-video-admin.png');
  await page.screenshot({ path: screenshotPath, fullPage: true });
  await testInfo.attach('category-video-admin', { path: screenshotPath, contentType: 'image/png' });
  const completedUrl = await page.locator('#ll-video-download').getAttribute('href');
  await page.fill('#ll-video-title', 'Changed lesson');
  await expect(page.locator('#ll-video-result')).toBeVisible();
  await expect(page.locator('#ll-video-download')).toHaveAttribute('href', completedUrl);
  await expect(page.locator('#ll-video-record')).toBeDisabled();
  await expect(page.locator('#ll-video-play')).toBeDisabled();
  await expect(page.locator('#ll-video-prepare')).toBeEnabled();
  expect(browserErrors).toEqual([]);
});
