const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const quizPagesScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/quiz-pages.js'),
  'utf8'
);

test('quiz trigger opens iframe fallback modal when flashcard launcher is absent', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <button
      type="button"
      class="ll-quiz-page-trigger"
      data-category="Demo Category"
      data-url="https://example.com/embed/demo-category?mode=practice"
    >
      Open quiz
    </button>
  `);

  await page.evaluate(() => {
    window.llQuizPages = {
      labels: {
        defaultTitle: 'Quiz',
        closeLabel: 'Kapat',
        iframeTitle: 'Quiz Content'
      }
    };
    try {
      delete window.llOpenFlashcardForCategory;
    } catch (_) {
      window.llOpenFlashcardForCategory = undefined;
    }
  });

  await page.addScriptTag({ content: quizPagesScriptSource });
  await page.click('.ll-quiz-page-trigger');

  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(1);
  await expect(page.locator('.ll-quiz-modal')).toBeVisible();
  await expect(page.locator('.ll-quiz-modal')).toHaveAttribute('role', 'dialog');
  await expect(page.locator('.ll-quiz-modal')).toHaveAttribute('aria-modal', 'true');
  await expect(page.locator('.ll-quiz-modal')).toHaveAttribute('aria-labelledby', 'll-quiz-modal-title');
  const closeButton = page.getByRole('button', { name: 'Kapat' });
  await expect(closeButton).toHaveAccessibleName('Kapat');
  await expect(closeButton).toBeFocused();
  await expect(page.locator('.ll-quiz-page-trigger')).toHaveAttribute('inert', '');
  await expect(page.locator('.ll-quiz-page-trigger')).toHaveAttribute('aria-hidden', 'true');
  const closeButtonText = await closeButton.evaluate((node) => node.textContent || '');
  expect(closeButtonText).toContain('×');
  expect(closeButtonText).toContain('Kapat');
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute(
    'src',
    'https://example.com/embed/demo-category?mode=practice'
  );

  const cancelDialogPromise = page.waitForEvent('dialog');
  await page.evaluate(() => {
    window.setTimeout(() => {
      document.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'Backspace',
        bubbles: true,
        cancelable: true
      }));
    }, 0);
  });
  const cancelDialog = await cancelDialogPromise;
  expect(cancelDialog.message()).toContain('Close this quiz?');
  await cancelDialog.dismiss();

  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(1);

  const acceptDialogPromise = page.waitForEvent('dialog');
  await page.evaluate(() => {
    window.setTimeout(() => window.history.back(), 0);
  });
  const acceptDialog = await acceptDialogPromise;
  expect(acceptDialog.message()).toContain('Close this quiz?');
  await acceptDialog.accept();

  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(0);
  await expect(page.locator('.ll-quiz-page-trigger')).not.toHaveAttribute('inert', '');
  await expect(page.locator('.ll-quiz-page-trigger')).not.toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('.ll-quiz-page-trigger')).toBeFocused();

  await page.click('.ll-quiz-page-trigger');
  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(1);

  await page.keyboard.press('Escape');
  await expect(page.locator('.ll-quiz-overlay')).toHaveCount(0);
  await expect(page.locator('.ll-quiz-page-trigger')).toBeFocused();
});

test('same-origin fallback iframe times out, retries, and waits for the embed-ready signal', async ({ page }) => {
  let embedAttempts = 0;
  await page.route('https://ll-tools.test/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.pathname === '/embed/demo-category') {
      embedAttempts += 1;
      const readyScript = embedAttempts > 1
        ? `<script>window.setTimeout(() => parent.postMessage({ type: 'll-embed-ready' }, location.origin), 40);</script>`
        : '';
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!doctype html><title>Embedded quiz</title>${readyScript}`
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html><button type="button" class="ll-quiz-page-trigger" data-category="Demo" data-url="/embed/demo-category">Open quiz</button>`
    });
  });

  await page.goto('https://ll-tools.test/host');
  await page.evaluate(() => {
    window.llQuizPages = {
      iframeTimeoutMs: 80,
      labels: {
        loadingLabel: 'Quiz loading',
        readyLabel: 'Quiz ready',
        loadErrorLabel: 'Quiz failed',
        loadTimeoutLabel: 'Quiz timed out',
        retryLabel: 'Try again',
        openDirectLabel: 'Open directly'
      }
    };
  });
  await page.addScriptTag({ content: quizPagesScriptSource });
  await page.click('.ll-quiz-page-trigger');

  const modal = page.locator('.ll-quiz-modal');
  const state = page.locator('.ll-quiz-frame-state');
  await expect(modal).toHaveAttribute('aria-busy', 'true');
  await expect(state).toContainText('Quiz loading');

  // Native iframe load is not authoritative for the same-origin embed.
  await expect(state).toHaveClass(/ll-quiz-frame-state--timeout/, { timeout: 2000 });
  await expect(modal).toHaveAttribute('aria-busy', 'false');
  await expect(state).toContainText('Quiz timed out');
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open directly' })).toHaveJSProperty(
    'href',
    'https://ll-tools.test/embed/demo-category'
  );
  await expect(page.getByRole('link', { name: 'Open directly' })).toHaveAttribute('target', '_blank');

  await page.getByRole('button', { name: 'Try again' }).click();
  await expect(modal).toHaveAttribute('aria-busy', 'true');
  await expect(state).toHaveClass(/ll-quiz-frame-state--ready/, { timeout: 2000 });
  await expect(state).toContainText('Quiz ready');
  await expect(modal).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.ll-quiz-iframe')).not.toHaveAttribute('tabindex');
  await expect(page.locator('.ll-quiz-iframe')).not.toHaveAttribute('aria-hidden');
  expect(embedAttempts).toBe(2);
});

test('fallback iframe exposes a translated error with retry and direct-open recovery', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <button type="button" class="ll-quiz-page-trigger" data-category="Demo" data-url="https://example.com/embed/demo">Open quiz</button>
  `);
  await page.evaluate(() => {
    window.llQuizPages = {
      iframeTimeoutMs: 2000,
      labels: {
        loadErrorLabel: 'Could not open this quiz',
        retryLabel: 'Try once more',
        openDirectLabel: 'Open the quiz'
      }
    };
  });
  await page.addScriptTag({ content: quizPagesScriptSource });
  await page.click('.ll-quiz-page-trigger');
  await page.locator('.ll-quiz-iframe').evaluate((iframe) => iframe.dispatchEvent(new Event('error')));

  const state = page.locator('.ll-quiz-frame-state');
  await expect(state).toHaveClass(/ll-quiz-frame-state--error/);
  await expect(state).toContainText('Could not open this quiz');
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.ll-quiz-iframe')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.getByRole('button', { name: 'Try once more' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open the quiz' })).toHaveAttribute(
    'href',
    'https://example.com/embed/demo'
  );
});

test('quiz trigger passes ordered listening ids to custom flashcard launcher', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <button
      type="button"
      class="ll-quiz-page-trigger ll-vocab-lesson-mode-button"
      data-category="Numbers"
      data-url="https://example.com/embed/numbers?mode=listening"
      data-mode="listening"
      data-wordset-id="42"
      data-display-mode="text_translation"
      data-prompt-type="audio"
      data-option-type="text_translation"
      data-ordered-word-ids="[3,1,2]"
      data-preserve-word-order="1"
    >
      Listen
    </button>
  `);

  await page.evaluate(() => {
    window.llQuizPages = {
      labels: {
        defaultTitle: 'Quiz'
      }
    };
    window.llOpenFlashcardForCategory = function (categoryName, opts) {
      window.__llLaunch = {
        categoryName,
        opts: Object.assign({}, opts)
      };
    };
  });

  await page.addScriptTag({ content: quizPagesScriptSource });
  await page.click('.ll-quiz-page-trigger');

  const launch = await page.evaluate(() => window.__llLaunch);
  expect(launch.categoryName).toBe('Numbers');
  expect(launch.opts.mode).toBe('listening');
  expect(launch.opts.wordsetId).toBe('42');
  expect(launch.opts.launchContext).toBe('vocab_lesson');
  expect(launch.opts.displayMode).toBe('text_translation');
  expect(launch.opts.display_mode).toBe('text_translation');
  expect(launch.opts.promptType).toBe('audio');
  expect(launch.opts.prompt_type).toBe('audio');
  expect(launch.opts.optionType).toBe('text_translation');
  expect(launch.opts.option_type).toBe('text_translation');
  expect(launch.opts.orderedWordIds).toEqual([3, 1, 2]);
  expect(launch.opts.sessionWordIds).toEqual([3, 1, 2]);
  expect(launch.opts.preserveWordOrder).toBe(true);
  expect(launch.opts.preserveCategoryOrder).toBe(true);
});
