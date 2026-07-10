const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const matcherSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/audio-image-matcher.js'),
  'utf8'
);

test('audio image matcher loads candidate images in bounded pages', async ({ page }) => {
  await page.route('https://example.test/', async (route) => {
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><body></body></html>' });
  });
  await page.goto('https://example.test/');
  await page.setContent(`
    <select id="ll-aim-wordset"><option value="0" selected>Default</option></select>
    <select id="ll-aim-category"><option value="11" selected>Category</option></select>
    <input id="ll-aim-rematch" type="checkbox">
    <input id="ll-aim-hide-used" type="checkbox" checked>
    <button id="ll-aim-start" type="button">Start</button>
    <button id="ll-aim-skip" type="button" disabled>Skip</button>
    <div id="ll-aim-stage" style="display:none">
      <h2 id="ll-aim-word-title"></h2>
      <audio id="ll-aim-audio"></audio>
      <div id="ll-aim-extra"></div>
      <div id="ll-aim-current-thumb" style="display:none"><img><span class="ll-aim-cap"></span></div>
      <div id="ll-aim-images"></div>
      <button id="ll-aim-load-more-images" type="button" hidden>Load more</button>
      <span id="ll-aim-image-page-status"></span>
      <div id="ll-aim-status"></div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.__llImageOffsets = [];
    window.llAimData = {
      ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      initialWordsetId: 0,
      initialCategoryId: 11,
      initialCategoryRows: [{ id: 11, slug: 'category', label: 'Category' }],
      i18n: {
        loadMoreImages: 'Load more',
        loadingMoreImages: 'Loading more...',
        imageLoadError: 'Something went wrong'
      }
    };
    window.llToolsFlashcardsData = { imageSize: 'small' };
    window.fetch = async function (url) {
      const parsed = new URL(url);
      const action = parsed.searchParams.get('action');
      if (action === 'll_aim_get_images') {
        const offset = parseInt(parsed.searchParams.get('offset') || '0', 10) || 0;
        window.__llImageOffsets.push(offset);
        const images = offset === 0
          ? [
              { id: 1, title: 'Image One', thumb: '', used_count: 0 },
              { id: 2, title: 'Image Two', thumb: '', used_count: 0 }
            ]
          : [{ id: 3, title: 'Image Three', thumb: '', used_count: 0 }];
        return {
          async json() {
            return {
              success: true,
              data: {
                images,
                has_more: offset === 0,
                next_offset: offset === 0 ? 2 : 3,
                page_size: 2
              }
            };
          }
        };
      }
      if (action === 'll_aim_get_next') {
        return {
          async json() {
            return {
              success: true,
              data: { item: { id: 101, title: 'Word', translation: '', audio_url: '', current_thumb: '' } }
            };
          }
        };
      }
      throw new Error('Unexpected action: ' + action);
    };
  });
  await page.addScriptTag({ content: matcherSource });

  await page.locator('#ll-aim-category').selectOption('11');
  await page.locator('#ll-aim-start').click();
  await expect(page.locator('#ll-aim-images .ll-aim-card')).toHaveCount(2);
  await expect(page.locator('#ll-aim-load-more-images')).toBeVisible();

  await page.locator('#ll-aim-load-more-images').click();
  await expect(page.locator('#ll-aim-images .ll-aim-card')).toHaveCount(3);
  await expect(page.locator('#ll-aim-load-more-images')).toBeHidden();
  expect(await page.evaluate(() => window.__llImageOffsets.slice())).toEqual([0, 2]);
});
