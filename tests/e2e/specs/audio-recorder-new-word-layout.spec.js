const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const recordingInterfaceCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/recording-interface.css'),
  'utf8'
);
const audioProcessorCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/audio-processor.css'),
  'utf8'
);

function buildNewWordOverlayMarkup(options = {}) {
  const showCreateCategory = options.showCreateCategory !== false;
  const showReview = options.showReview !== false;
  const processingReviewMode = !!options.processingReviewMode;

  return `
    <div class="ll-recording-interface">
      <div class="ll-new-word-overlay" id="ll-new-word-overlay">
        <div class="ll-new-word-overlay-backdrop"></div>
        <div
          class="ll-new-word-panel${processingReviewMode ? ' ll-new-word-panel--processing-review' : ''}"
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
                <div class="ll-new-word-header-actions">
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
                  <button type="button" class="ll-btn ll-new-word-close" id="ll-new-word-back" aria-label="Close">&times;</button>
                </div>
              </div>
              <div id="ll-new-word-status" class="ll-new-word-status" hidden role="status" aria-live="polite" aria-atomic="true"></div>

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
                        <button type="button" class="ll-btn ll-btn-primary ll-new-word-submit-btn" id="ll-new-word-start" aria-label="Save and continue">Save</button>
                      </div>
                    </div>
                  </div>

                  <div id="ll-new-word-review-slot" class="ll-new-word-review-slot">
                    ${showReview ? `
                      <div class="ll-review-interface ll-recording-review${processingReviewMode ? ' ll-recording-review--new-word-panel' : ''}" style="display:block;">
                        <h2 id="ll-recording-review-title">Review Processed Audio</h2>
                        <div class="ll-review-file">
                          <div class="ll-review-header">
                            <div class="ll-review-title-section">
                              <div class="ll-review-title-info">
                                <h3 class="ll-review-title">New word</h3>
                                <div class="ll-review-metadata">
                                  <span class="ll-review-category"><strong>Category:</strong> Uncategorized</span>
                                </div>
                              </div>
                            </div>
                          </div>
                          <div class="ll-waveform-container">
                            <canvas class="ll-waveform-canvas"></canvas>
                          </div>
                          <div class="ll-playback-controls" style="display:flex;">
                            <button type="button" class="ll-btn ll-btn-secondary ll-review-play" aria-label="Play">Play</button>
                            <audio controls></audio>
                          </div>
                        </div>
                        <div class="ll-review-actions">
                          <button type="button" id="ll-review-redo" class="ll-btn ll-btn-secondary" aria-label="Record again">Redo</button>
                          <button type="button" id="ll-review-submit" class="ll-btn ll-btn-primary" aria-label="Save and continue">Save</button>
                        </div>
                      </div>
                    ` : ''}
                  </div>
                </div>
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
  await page.addStyleTag({ content: audioProcessorCssSource });
}

test('new word overlay fits a 1024x768 laptop viewport without internal scrolling in the default state', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 1024, height: 768 }, { showCreateCategory: true, showReview: false });

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

test('new word overlay keeps target and translation together on wide layouts', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 1120, height: 620 }, { showCreateCategory: false, showReview: false });

  const positions = await page.evaluate(() => {
    const toggle = document.querySelector('.ll-new-word-row--toggle');
    const target = document.querySelector('#ll-new-word-text-target');
    const translation = document.querySelector('#ll-new-word-text-translation');
    if (!toggle || !target || !translation) return null;

    const toggleRect = toggle.getBoundingClientRect();
    const targetRect = target.getBoundingClientRect();
    const translationRect = translation.getBoundingClientRect();

    return {
      toggleTop: toggleRect.top,
      targetTop: targetRect.top,
      translationTop: translationRect.top
    };
  });

  expect(positions).not.toBeNull();
  expect(Math.abs(positions.targetTop - positions.translationTop)).toBeLessThanOrEqual(4);
  expect(Math.abs(positions.targetTop - positions.toggleTop)).toBeGreaterThan(12);
});

test('new word overlay keeps the close button in the header and the submit button beside redo', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 1120, height: 620 }, { showCreateCategory: false, showReview: false });

  const placement = await page.evaluate(() => {
    const closeBtn = document.querySelector('#ll-new-word-back');
    const redoBtn = document.querySelector('#ll-new-word-redo-btn');
    const submitBtn = document.querySelector('#ll-new-word-start');
    const header = document.querySelector('.ll-new-word-header');
    if (!closeBtn || !redoBtn || !submitBtn || !header) return null;

    const closeRect = closeBtn.getBoundingClientRect();
    const redoRect = redoBtn.getBoundingClientRect();
    const submitRect = submitBtn.getBoundingClientRect();
    const headerRect = header.getBoundingClientRect();

    return {
      closeTopOffset: Math.abs(closeRect.top - headerRect.top),
      redoTop: redoRect.top,
      submitTop: submitRect.top,
      submitLeft: submitRect.left,
      redoLeft: redoRect.left
    };
  });

  expect(placement).not.toBeNull();
  expect(placement.closeTopOffset).toBeLessThanOrEqual(10);
  expect(Math.abs(placement.redoTop - placement.submitTop)).toBeLessThanOrEqual(4);
  expect(placement.submitLeft).toBeGreaterThan(placement.redoLeft);
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

test('new word overlay compacts the processing review on short landscape mobile screens', async ({ page }) => {
  await mountNewWordOverlay(page, { width: 844, height: 430 }, { showCreateCategory: false, showReview: true, processingReviewMode: true });

  const panel = page.locator('#ll-new-word-panel');
  await expect(panel).toBeVisible();

  const metrics = await panel.evaluate((node) => {
    const layout = node.querySelector('.ll-new-word-layout');
    const computed = layout ? window.getComputedStyle(layout).gridTemplateColumns : '';
    const recording = node.querySelector('.ll-new-word-recording');
    const reviewTitle = node.querySelector('#ll-recording-review-title');
    const reviewRedo = node.querySelector('#ll-review-redo');
    const reviewSubmit = node.querySelector('#ll-review-submit');
    const reviewRedoRect = reviewRedo ? reviewRedo.getBoundingClientRect() : null;
    const reviewSubmitRect = reviewSubmit ? reviewSubmit.getBoundingClientRect() : null;
    return {
      clientWidth: node.clientWidth,
      scrollWidth: node.scrollWidth,
      clientHeight: node.clientHeight,
      scrollHeight: node.scrollHeight,
      layoutColumns: computed.split(' ').filter(Boolean).length,
      recordDisplay: recording ? window.getComputedStyle(recording).display : '',
      titleSize: reviewTitle ? parseFloat(window.getComputedStyle(reviewTitle).fontSize || '0') : 0,
      reviewSubmitDisplay: reviewSubmit ? window.getComputedStyle(reviewSubmit).display : '',
      reviewActionsInline: !!(reviewRedoRect && reviewSubmitRect && Math.abs(reviewRedoRect.top - reviewSubmitRect.top) <= 4)
    };
  });

  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.clientWidth + 1);
  expect(metrics.scrollHeight).toBeLessThanOrEqual(metrics.clientHeight + 1);
  expect(metrics.layoutColumns).toBe(2);
  expect(metrics.recordDisplay).toBe('none');
  expect(metrics.titleSize).toBeLessThanOrEqual(18);
  expect(metrics.reviewSubmitDisplay).not.toBe('none');
  expect(metrics.reviewActionsInline).toBe(true);
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
