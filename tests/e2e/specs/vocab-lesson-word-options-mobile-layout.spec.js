const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const modalCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/vocab-lesson-word-options-modal.css'),
  'utf8'
);
const adminCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/word-option-rules-admin.css'),
  'utf8'
);
const adminJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-option-rules-admin.js'),
  'utf8'
);

function buildGroupHeaderCells(labels) {
  return labels.map((label, index) => (
    `<th scope="col" data-group-id="g${index}"><span class="ll-tools-word-options-group-header">${label}</span></th>`
  )).join('');
}

function buildGroupRowCells(labels, checkedIndex) {
  return labels.map((label, index) => (
    `<td class="ll-tools-word-options-group-cell" data-group-id="g${index}" data-group-label="${label}">
      <label class="ll-tools-word-options-group-check">
        <span class="ll-tools-word-options-group-cell-label" data-group-cell-label>${label}</span>
        <input type="checkbox"${index === checkedIndex ? ' checked' : ''} aria-label="Assign to group ${index + 1}">
      </label>
    </td>`
  )).join('');
}

function buildWordRows(labels, rowCount) {
  return Array.from({ length: rowCount }, (_, index) => {
    const wordId = 11 + index;
    const label = index % 2 === 0 ? `Boarding pass ${index + 1}` : `Passport control ${index + 1}`;

    return `
      <tr data-word-id="${wordId}">
        <td class="ll-tools-word-options-media"><span class="ll-tools-word-options-thumb-placeholder">No image</span></td>
        <td class="ll-tools-word-options-media"><span class="ll-tools-word-options-audio-missing">No audio</span></td>
        <td class="ll-tools-word-options-word">
          <span class="ll-tools-word-options-word-title">${label}</span>
          <span class="ll-tools-word-options-word-id">#${wordId}</span>
        </td>
        ${buildGroupRowCells(labels, index % labels.length)}
      </tr>
    `;
  }).join('');
}

function buildIframeDoc({ rowCount = 2 } = {}) {
  const groupLabels = [
    '01 Core travel words',
    '02 Airport help',
    '03 Tickets',
    '04 Hotel',
    '05 Transit',
    '06 Problems',
    '07 Questions'
  ];

  return `<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      html, body, #wpwrap, #wpcontent, #wpbody, #wpbody-content {
        margin: 0;
        min-height: 100%;
        padding: 0;
      }

      *, *::before, *::after {
        box-sizing: border-box;
      }

      ${adminCssSource}
    </style>
  </head>
  <body class="ll-tools-word-options-iframe">
    <div id="wpwrap">
      <div id="wpcontent">
        <div id="wpbody">
          <div id="wpbody-content">
            <div class="wrap ll-tools-word-options ll-tools-word-options--iframe">
              <div class="ll-tools-word-options-hero">
                <div class="ll-tools-word-options-hero__eyebrow">Lesson word options</div>
                <h1>Travel Words</h1>
                <p class="description">Fine-tune which lesson words stay together as distractors and which pairs must stay apart.</p>
                <div class="ll-tools-word-options-context">
                  <span class="ll-tools-word-options-context-chip">Beginner Set</span>
                  <span class="ll-tools-word-options-context-chip">Travel</span>
                </div>
              </div>

              <form class="ll-tools-word-options-form">
                <h2>Groups of words that go together</h2>
                <p class="description">Use the same label for words that should be used together for wrong answers.</p>

                <div class="ll-tools-word-options-group-editor">
                  <h3>Group names</h3>
                  <div class="ll-tools-word-options-group-list" data-ll-group-list data-next-index="1">
                    <div class="ll-tools-word-options-group-row" data-group-id="g0">
                      <input type="text" class="ll-tools-word-options-group-input" value="01 Core travel words" data-group-name-input>
                      <button type="button" class="button button-secondary ll-tools-button" data-group-remove>Remove</button>
                    </div>
                  </div>
                  <button type="button" class="button button-secondary ll-tools-button ll-tools-word-options-add-group" data-group-add>Add group</button>
                </div>

                <div class="ll-tools-word-options-table-wrap ll-tools-word-options-table-wrap--groups">
                  <table class="widefat striped ll-tools-word-options-table" data-ll-group-table>
                    <thead>
                      <tr>
                        <th scope="col">Image</th>
                        <th scope="col">Audio</th>
                        <th scope="col">Word</th>
                        ${buildGroupHeaderCells(groupLabels)}
                      </tr>
                    </thead>
                    <tbody>${buildWordRows(groupLabels, rowCount)}</tbody>
                  </table>
                </div>

                <p class="ll-tools-word-options-actions">
                  <button type="submit" class="button button-primary ll-tools-button">Save lesson rules</button>
                </p>

                <h2>Blocked Pairs</h2>
                <p class="description">Blocked pairs will never appear as wrong answers for each other.</p>

                <div class="ll-tools-word-options-pair-add">
                  <div class="ll-tools-word-options-field">
                    <label for="ll-word-option-pair-a">Word A</label>
                    <select id="ll-word-option-pair-a" name="pair_a">
                      <option value="">Select a word</option>
                      <option value="11">Boarding pass</option>
                    </select>
                  </div>
                  <div class="ll-tools-word-options-field">
                    <label for="ll-word-option-pair-b">Word B</label>
                    <select id="ll-word-option-pair-b" name="pair_b">
                      <option value="">Select a word</option>
                      <option value="12">Passport control</option>
                    </select>
                  </div>
                  <button type="submit" class="button button-secondary ll-tools-button">Add pair</button>
                </div>

                <div class="ll-tools-word-options-table-wrap ll-tools-word-options-table-wrap--pairs">
                  <table class="widefat striped ll-tools-word-options-table ll-tools-word-options-pair-table">
                    <thead>
                      <tr>
                        <th scope="col">Remove</th>
                        <th scope="col">Pair</th>
                        <th scope="col">Reason</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td><span class="ll-tools-word-options-locked">Locked</span></td>
                        <td>Boarding pass / Passport control</td>
                        <td>
                          <div class="ll-tools-word-options-reasons">
                            <span class="ll-tools-word-options-reason">Same title</span>
                            <span class="ll-tools-word-options-reason">Same translation</span>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  <script>
    ${adminJsSource}
  </script>
</body>
</html>`;
}

test('word options popup keeps portrait mobile controls inside the viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`
    <!doctype html>
    <html lang="en">
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
          html, body {
            height: 100%;
            margin: 0;
            padding: 0;
          }

          *, *::before, *::after {
            box-sizing: border-box;
          }

          ${modalCssSource}
        </style>
      </head>
      <body>
        <div class="ll-vocab-lesson-word-options-modal">
          <button type="button" class="ll-vocab-lesson-word-options-modal__backdrop" aria-label="Close"></button>
          <div class="ll-vocab-lesson-word-options-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ll-vocab-lesson-word-options-title">
            <div class="ll-vocab-lesson-word-options-modal__header">
              <div class="ll-vocab-lesson-word-options-modal__title-wrap">
                <h2 class="ll-vocab-lesson-word-options-modal__title" id="ll-vocab-lesson-word-options-title">Word options</h2>
                <div class="ll-vocab-lesson-word-options-modal__meta">
                  <span class="ll-vocab-lesson-word-options-modal__meta-chip">Travel</span>
                  <span class="ll-vocab-lesson-word-options-modal__meta-chip">Beginner Set</span>
                </div>
              </div>
              <button type="button" class="ll-vocab-lesson-word-options-modal__close" aria-label="Close">x</button>
            </div>
            <div class="ll-vocab-lesson-word-options-modal__frame-shell">
              <iframe id="ll-mobile-word-options-frame" class="ll-vocab-lesson-word-options-modal__frame" title="Lesson word option rules"></iframe>
            </div>
          </div>
        </div>
      </body>
    </html>
  `);

  const iframe = page.locator('#ll-mobile-word-options-frame');
  await iframe.evaluate((node, srcdoc) => {
    node.setAttribute('srcdoc', srcdoc);
  }, buildIframeDoc({ rowCount: 2 }));

  await page.waitForFunction(() => {
    const frame = document.querySelector('#ll-mobile-word-options-frame');
    return !!(frame && frame.contentDocument && frame.contentDocument.querySelector('.ll-tools-word-options-form'));
  });

  const iframeHandle = await iframe.elementHandle();
  const frame = await iframeHandle.contentFrame();

  await expect(frame.locator('.ll-tools-word-options-form')).toBeVisible();

  const dialogMetrics = await page.locator('.ll-vocab-lesson-word-options-modal__dialog').evaluate((node) => {
    const rect = node.getBoundingClientRect();
    return {
      left: rect.left,
      right: rect.right,
      top: rect.top,
      bottom: rect.bottom,
      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight
    };
  });

  expect(dialogMetrics.left).toBeGreaterThanOrEqual(0);
  expect(dialogMetrics.top).toBeGreaterThanOrEqual(0);
  expect(dialogMetrics.right).toBeLessThanOrEqual(dialogMetrics.viewportWidth + 1);
  expect(dialogMetrics.bottom).toBeLessThanOrEqual(dialogMetrics.viewportHeight + 1);

  const frameMetrics = await frame.evaluate(() => {
    const root = document.documentElement;
    const body = document.body;
    const wrap = document.querySelector('.wrap.ll-tools-word-options--iframe');
    const groupsWrap = document.querySelector('.ll-tools-word-options-table-wrap--groups');
    const groupRow = document.querySelector('.ll-tools-word-options-group-row');
    const pairAdd = document.querySelector('.ll-tools-word-options-pair-add');
    const firstGroupCell = document.querySelector('.ll-tools-word-options-group-cell');
    const firstGroupCellLabel = firstGroupCell ? firstGroupCell.querySelector('[data-group-cell-label]') : null;
    const measuredElements = [
      '.ll-tools-word-options-group-input',
      '.ll-tools-word-options-add-group',
      '#ll-word-option-pair-a',
      '#ll-word-option-pair-b',
      '.ll-tools-word-options-pair-add .ll-tools-button',
      '.ll-tools-word-options-actions .ll-tools-button'
    ];
    const maxOffscreenRight = measuredElements.reduce((maxRight, selector) => {
      const node = document.querySelector(selector);
      if (!node) {
        return maxRight;
      }
      const rect = node.getBoundingClientRect();
      return Math.max(maxRight, rect.right - root.clientWidth);
    }, 0);

    return {
      rootClientWidth: root.clientWidth,
      rootScrollWidth: root.scrollWidth,
      bodyClientWidth: body.clientWidth,
      bodyScrollWidth: body.scrollWidth,
      wrapClientWidth: wrap ? wrap.clientWidth : 0,
      wrapScrollWidth: wrap ? wrap.scrollWidth : 0,
      groupsWrapClientWidth: groupsWrap ? groupsWrap.clientWidth : 0,
      groupsWrapScrollWidth: groupsWrap ? groupsWrap.scrollWidth : 0,
      groupRowDirection: groupRow ? window.getComputedStyle(groupRow).flexDirection : '',
      pairAddDirection: pairAdd ? window.getComputedStyle(pairAdd).flexDirection : '',
      firstGroupCellLabelDisplay: firstGroupCellLabel ? window.getComputedStyle(firstGroupCellLabel).display : '',
      firstGroupCellLabelText: firstGroupCellLabel ? firstGroupCellLabel.textContent.trim() : '',
      maxOffscreenRight
    };
  });

  expect(frameMetrics.rootScrollWidth).toBeLessThanOrEqual(frameMetrics.rootClientWidth + 1);
  expect(frameMetrics.bodyScrollWidth).toBeLessThanOrEqual(frameMetrics.bodyClientWidth + 1);
  expect(frameMetrics.wrapScrollWidth).toBeLessThanOrEqual(frameMetrics.wrapClientWidth + 1);
  expect(frameMetrics.groupsWrapScrollWidth).toBeLessThanOrEqual(frameMetrics.groupsWrapClientWidth + 1);
  expect(frameMetrics.groupRowDirection).toBe('column');
  expect(frameMetrics.pairAddDirection).toBe('column');
  expect(frameMetrics.firstGroupCellLabelDisplay).not.toBe('none');
  expect(frameMetrics.firstGroupCellLabelText).toBe('01 Core travel words');
  expect(frameMetrics.maxOffscreenRight).toBeLessThanOrEqual(1);
});

test('word options popup keeps table headers sticky in wide layouts', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.setContent(`
    <!doctype html>
    <html lang="en">
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
          html, body {
            height: 100%;
            margin: 0;
            padding: 0;
          }

          *, *::before, *::after {
            box-sizing: border-box;
          }

          ${modalCssSource}
        </style>
      </head>
      <body>
        <div class="ll-vocab-lesson-word-options-modal">
          <button type="button" class="ll-vocab-lesson-word-options-modal__backdrop" aria-label="Close"></button>
          <div class="ll-vocab-lesson-word-options-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ll-vocab-lesson-word-options-title">
            <div class="ll-vocab-lesson-word-options-modal__header">
              <div class="ll-vocab-lesson-word-options-modal__title-wrap">
                <h2 class="ll-vocab-lesson-word-options-modal__title" id="ll-vocab-lesson-word-options-title">Word options</h2>
                <div class="ll-vocab-lesson-word-options-modal__meta">
                  <span class="ll-vocab-lesson-word-options-modal__meta-chip">Travel</span>
                  <span class="ll-vocab-lesson-word-options-modal__meta-chip">Beginner Set</span>
                </div>
              </div>
              <button type="button" class="ll-vocab-lesson-word-options-modal__close" aria-label="Close">x</button>
            </div>
            <div class="ll-vocab-lesson-word-options-modal__frame-shell">
              <iframe id="ll-desktop-word-options-frame" class="ll-vocab-lesson-word-options-modal__frame" title="Lesson word option rules"></iframe>
            </div>
          </div>
        </div>
      </body>
    </html>
  `);

  const iframe = page.locator('#ll-desktop-word-options-frame');
  await iframe.evaluate((node, srcdoc) => {
    node.setAttribute('srcdoc', srcdoc);
  }, buildIframeDoc({ rowCount: 24 }));

  await page.waitForFunction(() => {
    const frame = document.querySelector('#ll-desktop-word-options-frame');
    return !!(frame && frame.contentDocument && frame.contentDocument.querySelector('.ll-tools-word-options-form'));
  });

  const iframeHandle = await iframe.elementHandle();
  const frame = await iframeHandle.contentFrame();
  await frame.waitForTimeout(500);

  const stickyMetrics = await frame.evaluate(async () => {
    const groupsWrap = document.querySelector('.ll-tools-word-options-table-wrap--groups');
    const stickyHeader = document.querySelector('.ll-tools-word-options-table-wrap--groups .ll-tools-word-options-table--cloned-head thead th[data-group-id="g0"]');

    if (!groupsWrap || !stickyHeader) {
      return null;
    }

    const wrapTop = groupsWrap.getBoundingClientRect().top;
    const initialTop = stickyHeader.getBoundingClientRect().top;
    const hasClonedHeader = groupsWrap.getAttribute('data-has-cloned-header');
    const initialScrollTop = groupsWrap.scrollTop;

    groupsWrap.scrollTop = 320;
    await new Promise((resolve) => window.requestAnimationFrame(() => resolve()));

    const stickyTop = stickyHeader.getBoundingClientRect().top;
    const stickyBottom = stickyHeader.getBoundingClientRect().bottom;
    const viewportHeight = window.innerHeight;

    return {
      hasClonedHeader,
      initialTop,
      wrapTop,
      initialScrollTop,
      wrapScrollTop: groupsWrap.scrollTop,
      stickyTop,
      stickyBottom,
      viewportHeight
    };
  });

  expect(stickyMetrics).not.toBeNull();
  expect(stickyMetrics.hasClonedHeader).toBe('1');
  expect(stickyMetrics.initialTop).toBeGreaterThan(150);
  expect(stickyMetrics.initialScrollTop).toBe(0);
  expect(stickyMetrics.wrapScrollTop).toBeGreaterThanOrEqual(300);
  expect(Math.abs(stickyMetrics.stickyTop - stickyMetrics.wrapTop)).toBeLessThanOrEqual(2);
  expect(stickyMetrics.stickyBottom).toBeLessThanOrEqual(stickyMetrics.viewportHeight);
});
