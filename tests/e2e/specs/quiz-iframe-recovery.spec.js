const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const quizPagesScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/quiz-pages.js'),
  'utf8'
);
const effectsScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/effects.js'),
  'utf8'
);
const flashcardMainScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/main.js'),
  'utf8'
);
const jqueryScriptPath = path.resolve(__dirname, '../node_modules/jquery/dist/jquery.min.js');
const quizPagesCssPath = path.resolve(__dirname, '../../../css/quiz-pages.css');
const flashcardBaseCssPath = path.resolve(__dirname, '../../../css/flashcard/base.css');

test('shared quiz dialog portals to body, traps focus, isolates the page, and restores its opener', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <main id="page-content">
      <button id="quiz-opener" type="button">Open quiz</button>
    </main>
    <div id="ll-tools-flashcard-container" style="--ll-answer-option-font-weight: 812">
      <button id="container-sibling" type="button">Container sibling</button>
      <div id="ll-tools-flashcard-popup">
        <div
          id="ll-tools-flashcard-quiz-popup"
          role="dialog"
          aria-modal="true"
          aria-labelledby="quiz-title"
          aria-hidden="true"
          tabindex="-1"
        >
          <h2 id="quiz-title">Quiz</h2>
          <button id="ll-tools-close-flashcard" type="button">Close</button>
          <button id="quiz-action" type="button">Answer</button>
        </div>
      </div>
    </div>
  `);
  await page.evaluate(() => {
    window.LLFlashcards = {
      Util: {},
      State: { STATES: {} },
      Dom: {},
      Effects: {},
      Selection: {},
      Cards: {},
      Results: {},
      StateMachine: {},
      ModeConfig: {}
    };
  });
  await page.addScriptTag({ path: jqueryScriptPath });
  await page.addScriptTag({ content: flashcardMainScriptSource });

  await page.locator('#quiz-opener').focus();
  await page.evaluate(() => {
    window.LLToolsQuizDialog.activate('#ll-tools-flashcard-quiz-popup');
  });

  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  await expect(popup).toHaveAttribute('aria-hidden', 'false');
  await expect(page.locator('#ll-tools-close-flashcard')).toBeFocused();
  await expect(page.locator('#page-content')).toHaveAttribute('inert', '');
  await expect(page.locator('#page-content')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('#ll-tools-flashcard-container')).toHaveAttribute('inert', '');
  await expect(page.locator('#ll-tools-flashcard-container')).toHaveAttribute('aria-hidden', 'true');
  expect(await page.locator('#ll-tools-flashcard-popup').evaluate((element) => (
    element.parentElement ? element.parentElement.tagName.toLowerCase() : ''
  ))).toBe('body');
  expect(await popup.evaluate((element) => (
    window.getComputedStyle(element).getPropertyValue('--ll-answer-option-font-weight').trim()
  ))).toBe('');

  await page.locator('#quiz-action').focus();
  await page.keyboard.press('Tab');
  await expect(page.locator('#ll-tools-close-flashcard')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(page.locator('#quiz-action')).toBeFocused();

  await page.evaluate(() => {
    const outside = document.createElement('button');
    outside.id = 'outside-focus-probe';
    outside.textContent = 'Outside';
    document.body.appendChild(outside);
    outside.focus();
  });
  await expect(page.locator('#ll-tools-close-flashcard')).toBeFocused();

  await page.evaluate(() => window.LLToolsQuizDialog.deactivate());
  await expect(popup).toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('#page-content')).not.toHaveAttribute('inert', '');
  await expect(page.locator('#page-content')).not.toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('#ll-tools-flashcard-container')).not.toHaveAttribute('inert', '');
  await expect(page.locator('#ll-tools-flashcard-container')).not.toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('#quiz-opener')).toBeFocused();
});

test('top-level mobile quiz keeps its established card, header, and audio sizing', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 780 });
  await page.goto('about:blank');
  await page.setContent(`
    <div
      id="ll-tools-flashcard-container"
      class="ll-compact-quiz-layout ll-image-options-scaled"
      style="--ll-answer-option-fit-size: 72px; --ll-repeat-icon-size: 32px; --ll-quiz-header-gap: 8px"
    >
      <div id="ll-tools-flashcard-popup">
        <div id="ll-tools-flashcard-quiz-popup" role="dialog" aria-modal="true" aria-hidden="true" tabindex="-1">
          <header id="ll-tools-flashcard-header">
            <button id="ll-tools-close-flashcard" type="button">Close</button>
            <button id="ll-tools-repeat-flashcard" type="button">
              <span class="ll-repeat-icon-wrap"><span class="ll-audio-play-icon">Play</span></span>
            </button>
          </header>
          <div class="flashcard-container ll-answer-option-image-card ll-answer-option-image-caption-card">
            <span class="ll-answer-option-image-caption-media"></span>
            <span class="ll-answer-option-image-caption">Answer</span>
          </div>
        </div>
      </div>
    </div>
  `);
  await page.addStyleTag({ path: flashcardBaseCssPath });
  await page.evaluate(() => {
    document.body.classList.add('ll-tools-flashcard-open');
    window.LLFlashcards = {
      Util: {},
      State: { STATES: {} },
      Dom: {},
      Effects: {},
      Selection: {},
      Cards: {},
      Results: {},
      StateMachine: {},
      ModeConfig: {}
    };
  });
  await page.addScriptTag({ path: jqueryScriptPath });
  await page.addScriptTag({ content: flashcardMainScriptSource });

  await page.evaluate(() => {
    window.LLToolsQuizDialog.activate('#ll-tools-flashcard-quiz-popup');
  });

  await expect(page.locator('.ll-answer-option-image-caption-card')).toHaveCSS('width', '150px');
  await expect(page.locator('#ll-tools-repeat-flashcard .ll-repeat-icon-wrap')).toHaveCSS('width', '42px');
  await expect(page.locator('#ll-tools-flashcard-header')).toHaveCSS('gap', '15px');
});

test('embedded quiz dialog stays in its container with compact layout variables in scope', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <main id="page-content">
      <button id="quiz-opener" type="button">Open quiz</button>
    </main>
    <div id="ll-tools-flashcard-container" style="--ll-answer-option-font-weight: 812">
      <div id="ll-tools-flashcard-popup">
        <div
          id="ll-tools-flashcard-quiz-popup"
          role="dialog"
          aria-modal="true"
          aria-labelledby="quiz-title"
          aria-hidden="true"
          tabindex="-1"
        >
          <h2 id="quiz-title">Quiz</h2>
          <button id="ll-tools-close-flashcard" type="button">Close</button>
        </div>
      </div>
    </div>
  `);
  await page.evaluate(() => {
    window.llToolsFlashcardsData = { isEmbed: true };
    window.LLFlashcards = {
      Util: {},
      State: { STATES: {} },
      Dom: {},
      Effects: {},
      Selection: {},
      Cards: {},
      Results: {},
      StateMachine: {},
      ModeConfig: {}
    };
  });
  await page.addScriptTag({ path: jqueryScriptPath });
  await page.addScriptTag({ content: flashcardMainScriptSource });

  await page.locator('#quiz-opener').focus();
  await page.evaluate(() => {
    window.LLToolsQuizDialog.activate('#ll-tools-flashcard-quiz-popup');
  });

  const popup = page.locator('#ll-tools-flashcard-quiz-popup');
  expect(await page.locator('#ll-tools-flashcard-popup').evaluate((element) => (
    element.parentElement ? element.parentElement.id : ''
  ))).toBe('ll-tools-flashcard-container');
  expect(await popup.evaluate((element) => (
    window.getComputedStyle(element).getPropertyValue('--ll-answer-option-font-weight').trim()
  ))).toBe('812');
  await expect(page.locator('#page-content')).toHaveAttribute('inert', '');
  await expect(page.locator('#ll-tools-close-flashcard')).toBeFocused();

  await page.evaluate(() => window.LLToolsQuizDialog.deactivate());
  await expect(page.locator('#page-content')).not.toHaveAttribute('inert', '');
  await expect(page.locator('#quiz-opener')).toBeFocused();
});

test('standalone iframe shows timeout recovery and becomes ready only after the embed signal', async ({ page }) => {
  let embedAttempts = 0;
  await page.route('https://ll-tools.test/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.pathname === '/embed/standalone') {
      embedAttempts += 1;
      const readyScript = embedAttempts > 1
        ? `<script>
            window.__LL_EMBED_STATE = 'll-embed-ready';
            window.setTimeout(() => parent.postMessage({ type: 'll-embed-ready' }, location.origin), 30);
          </script>`
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
      body: `<!doctype html>
        <div
          class="ll-tools-quiz-iframe-wrapper"
          data-quiz-src="/embed/standalone"
          data-iframe-state="loading"
          aria-busy="true"
        >
          <div class="ll-tools-iframe-state">
            <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
            <div id="quiz-status" class="ll-tools-iframe-loading-status" role="status" aria-live="polite">Loading</div>
            <div class="ll-tools-iframe-recovery" hidden>
              <button type="button" class="ll-tools-iframe-retry">Retry</button>
              <a class="ll-tools-iframe-open-direct" href="/embed/standalone" target="_blank" rel="noopener noreferrer">Open direct</a>
            </div>
          </div>
          <iframe
            class="ll-tools-quiz-iframe"
            src="/embed/standalone"
            title="Quiz content"
            aria-describedby="quiz-status"
            aria-busy="true"
          ></iframe>
        </div>`
    });
  });

  await page.goto('https://ll-tools.test/standalone-host');
  await page.evaluate(() => {
    window.llQuizPages = {
      iframeTimeoutMs: 70,
      labels: {
        loadingLabel: 'Preparing quiz',
        readyLabel: 'Quiz is ready',
        loadTimeoutLabel: 'Quiz took too long',
        retryLabel: 'Retry',
        openDirectLabel: 'Open direct'
      }
    };
  });
  await page.addStyleTag({ path: quizPagesCssPath });
  await page.addScriptTag({ content: quizPagesScriptSource });

  const wrapper = page.locator('.ll-tools-quiz-iframe-wrapper');
  await expect(wrapper).toHaveAttribute('data-iframe-state', 'timeout', { timeout: 2000 });
  await expect(wrapper).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.ll-tools-iframe-loading-status')).toHaveText('Quiz took too long');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open direct' })).toHaveJSProperty(
    'href',
    'https://ll-tools.test/embed/standalone'
  );

  await page.getByRole('button', { name: 'Retry' }).click();
  await expect(wrapper).toHaveAttribute('data-iframe-state', 'ready', { timeout: 2000 });
  await expect(page.locator('.ll-tools-iframe-loading-status')).toHaveText('Quiz is ready');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.ll-tools-quiz-iframe')).not.toHaveAttribute('tabindex');
  await expect(page.locator('.ll-tools-quiz-iframe')).not.toHaveAttribute('aria-hidden');
  expect(embedAttempts).toBe(2);
});

test('standalone iframe reports a load error instead of hiding its status', async ({ page }) => {
  await page.route('https://ll-tools.test/error-host', async (route) => {
    await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Error host</title>' });
  });
  await page.goto('https://ll-tools.test/error-host');
  await page.setContent(`
    <div class="ll-tools-quiz-iframe-wrapper" data-quiz-src="/embed/error" aria-busy="true">
      <div class="ll-tools-iframe-state">
        <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
        <div class="ll-tools-iframe-loading-status" role="status" aria-live="polite">Loading</div>
      </div>
      <iframe class="ll-tools-quiz-iframe" src="about:blank" title="Quiz content" aria-busy="true"></iframe>
    </div>
  `);
  await page.evaluate(() => {
    window.llQuizPages = {
      iframeTimeoutMs: 2000,
      labels: {
        loadErrorLabel: 'The embedded quiz failed',
        retryLabel: 'Try again',
        openDirectLabel: 'Open elsewhere'
      }
    };
  });
  await page.addStyleTag({ path: quizPagesCssPath });
  await page.addScriptTag({ content: quizPagesScriptSource });
  await page.locator('.ll-tools-quiz-iframe').evaluate((iframe) => iframe.dispatchEvent(new Event('error')));

  const wrapper = page.locator('.ll-tools-quiz-iframe-wrapper');
  await expect(wrapper).toHaveAttribute('data-iframe-state', 'error');
  await expect(wrapper).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.ll-tools-iframe-loading-status')).toHaveText('The embedded quiz failed');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('aria-hidden', 'true');
  await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Open elsewhere' })).toHaveJSProperty(
    'href',
    'https://ll-tools.test/embed/error'
  );
});

test('a late same-origin embed-ready signal clears the timeout state without a reload', async ({ page }) => {
  await page.route('https://ll-tools.test/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    if (requestUrl.pathname === '/embed/late-ready') {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!doctype html><script>
          window.setTimeout(() => parent.postMessage({ type: 'll-embed-ready' }, location.origin), 300);
        </script>`
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: `<!doctype html>
        <div class="ll-tools-quiz-iframe-wrapper" data-quiz-src="/embed/late-ready" aria-busy="true">
          <div class="ll-tools-iframe-state">
            <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
            <div class="ll-tools-iframe-loading-status" role="status" aria-live="polite">Loading</div>
          </div>
          <iframe class="ll-tools-quiz-iframe" src="/embed/late-ready" title="Quiz content" aria-busy="true"></iframe>
        </div>`
    });
  });
  await page.goto('https://ll-tools.test/late-ready-host');
  await page.evaluate(() => {
    window.llQuizPages = {
      iframeTimeoutMs: 50,
      labels: {
        readyLabel: 'Late quiz ready',
        loadTimeoutLabel: 'Still preparing'
      }
    };
  });
  await page.addScriptTag({ content: quizPagesScriptSource });

  const wrapper = page.locator('.ll-tools-quiz-iframe-wrapper');
  await expect(wrapper).toHaveAttribute('data-iframe-state', 'timeout', { timeout: 1000 });
  await expect(page.locator('.ll-tools-iframe-loading-status')).toHaveText('Still preparing');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('.ll-tools-quiz-iframe')).toHaveAttribute('aria-hidden', 'true');
  await expect(wrapper).toHaveAttribute('data-iframe-state', 'ready', { timeout: 2000 });
  await expect(page.locator('.ll-tools-iframe-loading-status')).toHaveText('Late quiz ready');
  await expect(page.locator('.ll-tools-quiz-iframe')).not.toHaveAttribute('tabindex');
  await expect(page.locator('.ll-tools-quiz-iframe')).not.toHaveAttribute('aria-hidden');
});

test('reduced motion keeps static loading cues and suppresses quiz celebration motion', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('about:blank');
  await page.setContent(`
    <div id="ll-tools-flashcard-popup">
      <div id="ll-tools-flashcard-quiz-popup">
        <div id="ll-tools-loading-animation" class="ll-tools-loading-animation ll-tools-loading-animation--visualizer">
          <span class="ll-tools-visualizer-bar" style="height: 52px"></span>
        </div>
        <div id="ll-tools-flashcard-content"><div id="ll-tools-flashcard"></div></div>
      </div>
    </div>
    <div class="ll-tools-quiz-iframe-wrapper">
      <div class="ll-tools-iframe-state">
        <div class="ll-tools-iframe-loading"></div>
      </div>
    </div>
  `);
  await page.addStyleTag({ path: flashcardBaseCssPath });
  await page.addStyleTag({ path: quizPagesCssPath });

  const motion = await page.evaluate(() => {
    const popup = document.getElementById('ll-tools-flashcard-content');
    const bar = document.querySelector('.ll-tools-visualizer-bar');
    const iframeSpinner = document.querySelector('.ll-tools-iframe-loading');
    return {
      transitionDuration: getComputedStyle(popup).transitionDuration,
      barHeight: getComputedStyle(bar).height,
      barIterations: getComputedStyle(bar).animationIterationCount,
      iframeSpinnerAnimation: getComputedStyle(iframeSpinner).animationName
    };
  });
  expect(parseFloat(motion.transitionDuration)).toBeLessThanOrEqual(0.001);
  expect(motion.barHeight).toBe('18px');
  expect(motion.barIterations).toBe('1');
  expect(motion.iframeSpinnerAnimation).toBe('none');

  await page.evaluate(() => {
    window.LLFlashcards = {};
    window.__confettiCalls = 0;
    window.confetti = function () {
      window.__confettiCalls += 1;
    };
  });
  await page.addScriptTag({ content: effectsScriptSource });
  await page.evaluate(() => window.LLFlashcards.Effects.startConfetti({ duration: 50 }));
  await page.waitForTimeout(80);
  expect(await page.evaluate(() => window.__confettiCalls)).toBe(0);
  await expect(page.locator('#confetti-canvas')).toHaveCount(0);
});
