const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const recordingInterfaceCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/recording-interface.css'),
  'utf8'
);

function buildNewWordOverlayMarkup(options = {}) {
  const showCreateCategory = options.showCreateCategory !== false;
  const showReview = options.showReview !== false;

  return `
    <div class="ll-recording-interface">
      <div class="ll-new-word-overlay" id="ll-new-word-overlay">
        <div class="ll-new-word-overlay-backdrop"></div>
        <div
          class="ll-new-word-panel"
          id="ll-new-word-panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="ll-new-word-title"
          style="display: block;"
        >
          <div class="ll-new-word-card">
            <div class="ll-new-word-shell">
              <div class="ll-new-word-header">
                <h3 id="ll-new-word-title">Record a New Word</h3>
                <div
                  class="ll-new-word-auto-status is-loading"
                  id="ll-new-word-auto-status"
                  style="display:inline-flex;"
                  role="status"
                  aria-live="polite"
                  aria-busy="true"
                >
                  <span class="ll-new-word-auto-icon" aria-hidden="true">A</span>
                  <span class="ll-new-word-auto-spinner" aria-hidden="true" style="display:block;"></span>
                  <button type="button" class="ll-btn ll-new-word-auto-cancel" id="ll-new-word-auto-cancel" aria-label="Cancel automatic transcription">x</button>
                </div>
              </div>

              <div class="ll-new-word-layout">
                <div class="ll-new-word-form">
                  <div class="ll-new-word-form-grid">
                    <div class="ll-new-word-row ll-new-word-row--category">
                      <label for="ll-new-word-category">Category</label>
                      <select id="ll-new-word-category">
                        <option value="uncategorized" selected>Uncategorized</option>
                        <option value="food">Food</option>
                        <option value="travel">Travel</option>
                      </select>
                    </div>

                    <div class="ll-new-word-row ll-new-word-row--toggle ll-new-word-checkbox">
                      <label>
                        <input type="checkbox" id="ll-new-word-create-category"${showCreateCategory ? ' checked' : ''} />
                        Create a new category for these words
                      </label>
                    </div>

                    <div class="ll-new-word-create-fields" style="display:${showCreateCategory ? 'block' : 'none'};">
                      <div class="ll-new-word-row">
                        <label for="ll-new-word-category-name">New Category Name</label>
                        <input type="text" id="ll-new-word-category-name" value="Food Words" />
                      </div>
                      <div class="ll-new-word-row">
                        <label>Desired Recording Types</label>
                        <div class="ll-new-word-types">
                          <label class="ll-recording-type-option" data-recording-type="isolation">
                            <input type="checkbox" value="isolation" checked />
                            <span class="ll-recording-type-option-icon" aria-hidden="true">🔍</span>
                            <span class="ll-recording-type-option-label">Isolation</span>
                          </label>
                          <label class="ll-recording-type-option" data-recording-type="question">
                            <input type="checkbox" value="question" checked />
                            <span class="ll-recording-type-option-icon" aria-hidden="true">❓</span>
                            <span class="ll-recording-type-option-label">Question</span>
                          </label>
                          <label class="ll-recording-type-option" data-recording-type="introduction">
                            <input type="checkbox" value="introduction" checked />
                            <span class="ll-recording-type-option-icon" aria-hidden="true">💬</span>
                            <span class="ll-recording-type-option-label">Introduction</span>
                          </label>
                          <label class="ll-recording-type-option" data-recording-type="sentence">
                            <input type="checkbox" value="sentence" />
                            <span class="ll-recording-type-option-icon" aria-hidden="true">📝</span>
                            <span class="ll-recording-type-option-label">In Sentence</span>
                          </label>
                        </div>
                      </div>
                    </div>

                    <div class="ll-new-word-row ll-new-word-row--target">
                      <label for="ll-new-word-text-target">Target Word (optional)</label>
                      <input type="text" id="ll-new-word-text-target" value="merhaba" />
                    </div>
                    <div class="ll-new-word-row ll-new-word-row--translation">
                      <label for="ll-new-word-text-translation">Translation (optional)</label>
                      <input type="text" id="ll-new-word-text-translation" value="hello" />
                    </div>
                  </div>
                </div>

                <div class="ll-new-word-sidebar">
                  <div class="ll-new-word-recording">
                    <div class="ll-new-word-recording-controls">
                      <button id="ll-new-word-record-btn" class="ll-btn ll-btn-record" title="Record"></button>
                      <div id="ll-new-word-recording-indicator" class="ll-recording-indicator" style="display:flex;">
                        <span class="ll-recording-dot"></span>
                        <span id="ll-new-word-recording-timer">0:12</span>
                      </div>
                    </div>
                    <div id="ll-new-word-recording-type" class="ll-new-word-recording-type" style="display:inline-flex;" role="status" aria-live="polite">
                      <span class="ll-new-word-recording-type-dot" aria-hidden="true"></span>
                      <span id="ll-new-word-recording-type-label" class="ll-new-word-recording-type-label">Question</span>
                    </div>
                    <div id="ll-new-word-playback-controls" class="ll-new-word-playback-controls" style="display:flex;">
                      <audio id="ll-new-word-playback-audio" controls></audio>
                      <div class="ll-new-word-playback-actions">
                        <button id="ll-new-word-redo-btn" class="ll-btn ll-btn-secondary" title="Record again">Redo</button>
                      </div>
                    </div>
                  </div>

                  <div id="ll-new-word-review-slot" class="ll-new-word-review-slot">
                    ${showReview ? `
                      <div class="ll-review-interface ll-recording-review" style="display:block;">
                        <div class="ll-playback-controls" style="display:flex;">
                          <audio controls></audio>
                          <div class="ll-playback-actions">
                            <button class="ll-btn ll-btn-secondary" type="button">Redo</button>
                            <button class="ll-btn ll-btn-primary" type="button">Save</button>
                          </div>
                        </div>
                      </div>
                    ` : ''}
                  </div>
                </div>
              </div>

              <div class="ll-new-word-actions">
                <button type="button" class="ll-btn ll-btn-primary" id="ll-new-word-start">Save and Continue</button>
                <button type="button" class="ll-btn ll-btn-secondary" id="ll-new-word-back">Back to Existing Words</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

async function mountNewWordOverlay(page, viewport, options = {}) {
  await page.setViewportSize(viewport);
  await page.goto('about:blank');
  await page.setContent(buildNewWordOverlayMarkup(options));
  await page.addStyleTag({
    content: `
      html, body {
        margin: 0;
        padding: 0;
      }

      body {
        min-height: 100vh;
      }

      audio {
        width: 100%;
        height: 40px;
      }
    `
  });
  await page.addStyleTag({ content: recordingInterfaceCssSource });
}

test('new word overlay fits a 1024x768 laptop viewport without internal scrolling', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 1024, height: 768 }, { showCreateCategory: true, showReview: true });

  const panel = page.locator('#ll-new-word-panel');
  await expect(panel).toBeVisible();

  const metrics = await panel.evaluate((node) => {
    const rect = node.getBoundingClientRect();
    return {
      clientHeight: node.clientHeight,
      scrollHeight: node.scrollHeight,
      top: rect.top,
      bottom: rect.bottom,
      width: rect.width
    };
  });

  expect(metrics.scrollHeight).toBeLessThanOrEqual(metrics.clientHeight + 1);
  expect(metrics.top).toBeGreaterThanOrEqual(0);
  expect(metrics.bottom).toBeLessThanOrEqual(768);
  expect(metrics.width).toBeLessThanOrEqual(1024);
});

test('new word overlay switches to compact full-screen mode on short laptop viewports', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 1024, height: 500 }, { showCreateCategory: false, showReview: false });

  const panel = page.locator('#ll-new-word-panel');
  await expect(panel).toBeVisible();

  const metrics = await panel.evaluate((node) => {
    const rect = node.getBoundingClientRect();
    const title = node.querySelector('#ll-new-word-title');
    return {
      clientHeight: node.clientHeight,
      scrollHeight: node.scrollHeight,
      top: rect.top,
      bottom: rect.bottom,
      borderRadius: window.getComputedStyle(node).borderTopLeftRadius,
      titleDisplay: title ? window.getComputedStyle(title).display : ''
    };
  });

  expect(metrics.scrollHeight).toBeLessThanOrEqual(metrics.clientHeight + 1);
  expect(metrics.top).toBe(0);
  expect(metrics.bottom).toBeLessThanOrEqual(500);
  expect(metrics.borderRadius).toBe('0px');
  expect(metrics.titleDisplay).toBe('none');
});

test('new word overlay keeps a compact two-column layout on short landscape mobile screens', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 844, height: 430 }, { showCreateCategory: false, showReview: false });

  const panel = page.locator('#ll-new-word-panel');
  await expect(panel).toBeVisible();

  const metrics = await panel.evaluate((node) => {
    const layout = node.querySelector('.ll-new-word-layout');
    const computed = layout ? window.getComputedStyle(layout).gridTemplateColumns : '';
    return {
      clientWidth: node.clientWidth,
      scrollWidth: node.scrollWidth,
      clientHeight: node.clientHeight,
      scrollHeight: node.scrollHeight,
      layoutColumns: computed.split(' ').filter(Boolean).length
    };
  });

  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1);
  expect(metrics.scrollHeight).toBeLessThanOrEqual(metrics.clientHeight + 1);
  expect(metrics.layoutColumns).toBe(2);
});

test('new word overlay keeps the mobile stack within the viewport width', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 390, height: 844 }, { showCreateCategory: true, showReview: false });

  const panel = page.locator('#ll-new-word-panel');
  await expect(panel).toBeVisible();

  const metrics = await panel.evaluate((node) => {
    const layout = node.querySelector('.ll-new-word-layout');
    const computed = layout ? window.getComputedStyle(layout).gridTemplateColumns : '';
    return {
      clientWidth: node.clientWidth,
      scrollWidth: node.scrollWidth,
      layoutColumns: computed.split(' ').filter(Boolean).length
    };
  });

  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1);
  expect(metrics.layoutColumns).toBe(1);
});
