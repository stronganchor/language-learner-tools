const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const listeningCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/flashcard/mode-listening.css'),
  'utf8'
);

async function renderListeningPlaceholder(page, viewport) {
  await page.setViewportSize(viewport);
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <div class="flashcard-container listening-placeholder" style="aspect-ratio: 2.5 / 1;"></div>
      </body>
    </html>
  `);
  await page.addStyleTag({ content: listeningCssSource });
  return page.locator('.listening-placeholder').boundingBox();
}

test('desktop listening placeholders keep wide images readable in embedded frames', async ({ page }) => {
  const box = await renderListeningPlaceholder(page, { width: 1280, height: 720 });

  expect(box).not.toBeNull();
  expect(box.width).toBeGreaterThanOrEqual(500);
  expect(box.height).toBeGreaterThanOrEqual(200);
});

test('mobile listening placeholders stay constrained by the viewport', async ({ page }) => {
  const box = await renderListeningPlaceholder(page, { width: 390, height: 844 });

  expect(box).not.toBeNull();
  expect(box.width).toBeGreaterThanOrEqual(300);
  expect(box.width).toBeLessThanOrEqual(340);
});
