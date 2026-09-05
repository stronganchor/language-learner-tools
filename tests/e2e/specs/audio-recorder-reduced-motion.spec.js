const { test, expect } = require('@playwright/test');
const path = require('path');

const recorderCssPath = path.resolve(__dirname, '../../../css/recording-interface.css');

test('recorder removes repeated and interactive motion when reduced motion is requested', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setContent(`
    <main class="ll-recording-interface">
      <button class="ll-recorder-category-back">Back</button>
      <button class="ll-recorder-category-card">Category</button>
      <div class="ll-wordset-card--lazy-placeholder">Loading</div>
      <span class="ll-new-word-auto-spinner"></span>
      <button class="ll-btn-record recording"><svg></svg></button>
      <button class="ll-btn-record starting"><svg></svg></button>
      <div class="ll-recording-indicator is-starting"><span class="ll-recording-dot"></span></div>
      <span class="ll-recording-meter-bar"></span>
      <div class="ll-upload-feedback is-indeterminate"><span class="ll-upload-progress-fill"></span></div>
      <button class="ll-next-category-btn"><svg></svg></button>
      <div class="ll-recording-review"><button class="ll-review-play">Play</button></div>
    </main>
  `);
  await page.addStyleTag({ path: recorderCssPath });

  const motion = await page.evaluate(() => {
    const style = (selector, pseudo = null) => getComputedStyle(document.querySelector(selector), pseudo);
    return {
      skeleton: style('.ll-wordset-card--lazy-placeholder', '::after').animationName,
      spinner: style('.ll-new-word-auto-spinner').animationName,
      recording: style('.ll-btn-record.recording').animationName,
      starting: style('.ll-btn-record.starting svg').animationName,
      dot: style('.ll-recording-dot').animationName,
      upload: style('.ll-upload-progress-fill').animationName,
      meterTransitionSeconds: parseFloat(style('.ll-recording-meter-bar').transitionDuration) || 0
    };
  });

  expect(motion).toMatchObject({
    skeleton: 'none',
    spinner: 'none',
    recording: 'none',
    starting: 'none',
    dot: 'none',
    upload: 'none'
  });
  expect(motion.meterTransitionSeconds).toBeLessThanOrEqual(0.001);

  await page.locator('.ll-recorder-category-back').hover();
  await expect(page.locator('.ll-recorder-category-back')).toHaveCSS('transform', 'none');
  await page.locator('.ll-recorder-category-card').hover();
  await expect(page.locator('.ll-recorder-category-card')).toHaveCSS('transform', 'none');
  await page.locator('.ll-review-play').hover();
  await expect(page.locator('.ll-review-play')).toHaveCSS('transform', 'none');
});
