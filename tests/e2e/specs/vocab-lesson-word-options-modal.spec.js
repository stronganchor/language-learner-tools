const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const modalSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-word-options-modal.js'),
  'utf8'
);
const modalCss = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-word-options-modal.css'),
  'utf8'
);
const localizationSource = fs.readFileSync(
  path.resolve(__dirname, '../../../includes/pages/vocab-lesson-pages.php'),
  'utf8'
);
const editorSource = fs.readFileSync(
  path.resolve(__dirname, '../../../includes/admin/word-option-rules-admin.php'),
  'utf8'
);

test('word options modal times out recoverably and isolates keyboard focus', async ({ page }) => {
  let iframeRequests = 0;
  await page.route('https://word-options-modal.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/options/') {
      iframeRequests += 1;
      if (iframeRequests === 1) {
        await new Promise((resolve) => setTimeout(resolve, 500));
      }
      try {
        await route.fulfill({
          status: 200,
          contentType: 'text/html',
          body: '<!doctype html><div data-ll-word-options-ready="1"><button id="frame-control">Loaded word options</button></div>'
        });
      } catch (_) {
        // The first request is intentionally aborted when its deadline expires.
      }
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><html><body>
        <main id="lesson-content" class="ll-vocab-lesson-page">
          <button id="outside-control">Outside</button>
          <div class="ll-vocab-lesson-star-controls"></div>
        </main>
      </body></html>`
    });
  });

  await page.goto('https://word-options-modal.test/lesson/');
  await page.evaluate(() => {
    window.llToolsVocabLessonWordOptions = {
      iframeUrl: 'https://word-options-modal.test/options/',
      categoryName: 'Travel',
      wordsetName: 'Beginner',
      loadTimeoutMs: 250,
      i18n: {
        buttonLabel: 'Options',
        buttonTitle: 'Edit word option rules',
        dialogTitle: 'Word options',
        closeLabel: 'Close',
        loading: 'Opening word options...',
        iframeTitle: 'Lesson word option rules',
        loadError: 'Could not open word options.',
        loadTimeout: 'Word options timed out.',
        retryLabel: 'Try again',
        directOpenLabel: 'Open separately'
      }
    };
  });
  await page.addStyleTag({content: modalCss});
  await page.addScriptTag({content: modalSource});

  const trigger = page.locator('[data-ll-word-options-launcher]');
  const modal = page.locator('.ll-vocab-lesson-word-options-modal');
  const close = modal.locator('.ll-vocab-lesson-word-options-modal__close');
  const retry = modal.locator('.ll-vocab-lesson-word-options-modal__retry');
  const background = page.locator('#lesson-content');

  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await expect(trigger).toBeFocused();
  const triggerFocus = await trigger.evaluate((button) => {
    const style = getComputedStyle(button);
    return {width: parseFloat(style.outlineWidth), style: style.outlineStyle};
  });
  expect(triggerFocus.style).toBe('solid');
  expect(triggerFocus.width).toBeGreaterThanOrEqual(3);

  await trigger.click();
  await expect(modal).toBeVisible();
  await expect(close).toBeFocused();
  await expect(background).toHaveAttribute('inert', '');
  await expect(background).toHaveAttribute('aria-hidden', 'true');

  await expect(modal.locator('.ll-vocab-lesson-word-options-modal__error-message')).toHaveText('Word options timed out.');
  await expect(retry).toBeVisible();
  await expect(retry).toBeFocused();
  await expect(modal.locator('.ll-vocab-lesson-word-options-modal__direct-open'))
    .toHaveAttribute('href', 'https://word-options-modal.test/options/');

  await retry.click();
  await expect(page.frameLocator('.ll-vocab-lesson-word-options-modal__frame').locator('#frame-control')).toBeVisible();
  expect(iframeRequests).toBe(2);

  await close.focus();
  await page.keyboard.press('Shift+Tab');
  await expect.poll(() => page.evaluate(() => document.activeElement && document.activeElement.tagName)).toBe('IFRAME');

  const frameControl = page.frameLocator('.ll-vocab-lesson-word-options-modal__frame').locator('#frame-control');
  await frameControl.focus();
  await expect(frameControl).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(modal).toBeHidden();
  await expect(background).not.toHaveAttribute('inert', '');
  await expect(background).not.toHaveAttribute('aria-hidden', 'true');
  await expect(trigger).toBeFocused();
});

test('word options PHP localization wires recovery copy and deadline', async () => {
  expect(localizationSource).toContain("'loadTimeoutMs' => 15000");
  expect(localizationSource).toContain("'loadTimeout' => __('Word options are taking too long to open.'");
  expect(localizationSource).toContain("'retryLabel' => __('Retry'");
  expect(localizationSource).toContain("'directOpenLabel' => __('Open in a new tab'");
  expect(editorSource).toContain('data-ll-word-options-ready="1"');
});

test('word options modal stops decorative motion when reduced motion is requested', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setContent(`
    <style>${modalCss}</style>
    <button class="ll-vocab-lesson-word-options-modal__close" type="button">Close</button>
    <span class="ll-vocab-lesson-word-options-modal__loading-dot"></span>
  `);

  const motion = await page.evaluate(() => {
    const close = getComputedStyle(document.querySelector('.ll-vocab-lesson-word-options-modal__close'));
    const dot = getComputedStyle(document.querySelector('.ll-vocab-lesson-word-options-modal__loading-dot'));
    return {
      closeTransitionDuration: close.transitionDuration,
      dotAnimationName: dot.animationName
    };
  });

  expect(motion).toEqual({
    closeTransitionDuration: '0s',
    dotAnimationName: 'none'
  });
});

test('word options modal rejects a fast error document without the editor readiness marker', async ({page}) => {
  await page.route('https://word-options-error.test/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/options/') {
      await route.fulfill({
        status: 503,
        contentType: 'text/html',
        body: '<!doctype html><h1>Temporarily unavailable</h1>'
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<!doctype html><div class="ll-vocab-lesson-page"><div class="ll-vocab-lesson-star-controls"></div></div>'
    });
  });

  await page.goto('https://word-options-error.test/lesson/');
  await page.evaluate(() => {
    window.llToolsVocabLessonWordOptions = {
      iframeUrl: 'https://word-options-error.test/options/',
      loadTimeoutMs: 1000,
      i18n: {
        buttonLabel: 'Options',
        buttonTitle: 'Edit word option rules',
        dialogTitle: 'Word options',
        closeLabel: 'Close',
        loading: 'Opening...',
        iframeTitle: 'Word options',
        loadError: 'Could not open word options.',
        loadTimeout: 'Timed out.',
        retryLabel: 'Retry',
        directOpenLabel: 'Open separately'
      }
    };
  });
  await page.addScriptTag({content: modalSource});
  await page.locator('[data-ll-word-options-launcher]').click();

  const modal = page.locator('.ll-vocab-lesson-word-options-modal');
  await expect(modal.locator('.ll-vocab-lesson-word-options-modal__error-message'))
    .toHaveText('Could not open word options.');
  await expect(modal.locator('.ll-vocab-lesson-word-options-modal__retry')).toBeVisible();
  await expect(modal.locator('.ll-vocab-lesson-word-options-modal__frame')).toHaveCount(0);
});
