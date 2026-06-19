const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const ipaKeyboardAdminSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/ipa-keyboard-admin.js'),
  'utf8'
);
const wordEditModalSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-edit-modal.js'),
  'utf8'
);
const ipaKeyboardAdminCss = fs.readFileSync(
  path.resolve(__dirname, '../../../css/ipa-keyboard-admin.css'),
  'utf8'
);

function buildMarkup() {
  return `
    <div class="ll-ipa-admin" data-ll-initial-tab="search">
      <select id="ll-ipa-wordset">
        <option value="7" selected>Regression Wordset</option>
      </select>
      <div id="ll-ipa-admin-status" aria-live="polite"></div>

      <button type="button" data-ll-tab-trigger="search" aria-selected="true">Search</button>

      <section data-ll-tab-panel="search">
        <label for="ll-ipa-search-query">Search</label>
        <input id="ll-ipa-search-query" type="search" value="" />

        <label for="ll-ipa-search-scope">Scope</label>
        <select id="ll-ipa-search-scope">
          <option value="both" selected>Both</option>
          <option value="written">Written</option>
          <option value="transcription">Transcription</option>
        </select>

        <label>
          <input id="ll-ipa-search-issues-only" type="checkbox" />
          Issues only
        </label>
        <label>
          <input id="ll-ipa-search-review-only" type="checkbox" />
          Review only
        </label>
        <label>
          <input id="ll-ipa-search-exact-transcription" type="checkbox" />
          Exact
        </label>

        <button type="button" id="ll-ipa-search-btn">Search</button>
        <div id="ll-ipa-search-summary"></div>
        <div id="ll-ipa-search-rules"></div>
        <div id="ll-ipa-search-results"></div>
      </section>
    </div>
  `;
}

async function getOrthographySuggestionLayout(page) {
  return page.evaluate(() => {
    function roundedRect(element) {
      if (!element) {
        return {
          top: 0,
          height: 0
        };
      }

      const rect = element.getBoundingClientRect();
      return {
        top: Math.round(rect.top),
        height: Math.round(rect.height)
      };
    }

    const row = document.querySelector('#ll-ipa-search-results tbody tr');
    const rowRect = roundedRect(row);
    const textBlock = row ? row.querySelector('.ll-ipa-search-text-cell') : null;
    const ipaBlock = row ? row.querySelector('.ll-ipa-search-ipa-cell') : null;
    const transcriptionCell = row ? row.querySelector('.ll-ipa-search-transcription-cell') : null;
    const issuesWrap = row ? row.querySelector('.ll-ipa-search-issues-wrap') : null;
    const textRect = roundedRect(textBlock);
    const ipaRect = roundedRect(ipaBlock);

    return {
      rowHeight: rowRect.height,
      textBlockHeight: textRect.height,
      ipaBlockHeight: ipaRect.height,
      ipaBlockTop: ipaRect.top - rowRect.top,
      transcriptionHeight: roundedRect(transcriptionCell).height,
      issuesHeight: roundedRect(issuesWrap).height,
      suggestionCount: row ? row.querySelectorAll('.ll-ipa-search-suggestion-chip').length : 0,
      textMinHeight: textBlock ? window.getComputedStyle(textBlock).minHeight : ''
    };
  });
}

test('reviewed rows stay visible until the transcription search is manually refreshed', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function buildRecording(recordingId, wordText, reviewFields, reviewNote) {
      const fields = Object.assign({ recording_text: false, recording_ipa: false }, reviewFields || {});
      const needsReview = !!(fields.recording_text || fields.recording_ipa);
      return {
        recording_id: recordingId,
        word_text: wordText,
        word_translation: '',
        word_edit_link: '',
        recording_text: wordText.toLowerCase(),
        recording_translation: '',
        recording_ipa: wordText.toLowerCase(),
        categories: [
          {
            name: 'Regression Category',
            url: '/quiz/regression-category/'
          }
        ],
        issues: [],
        ignored_issues: [],
        issue_count: 0,
        ignored_issue_count: 0,
        needs_review: needsReview,
        review_fields: fields,
        review_note: reviewNote || '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      };
    }

    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: false,
        review_only: true,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llTranscriptionManagerMock = {
      recordings: [
        buildRecording(101, 'Alpha', { recording_text: true }, 'Check the vowel length before clearing.'),
        buildRecording(202, 'Beta', { recording_ipa: true }, '')
      ],
      postCalls: [],
      holdReviewStateRequests: false,
      pendingReviewStateRequests: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llTranscriptionManagerMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          const reviewOnly = !!requestData.review_only;
          const results = window.__llTranscriptionManagerMock.recordings
            .filter(function (recording) {
              return !reviewOnly || !!recording.needs_review;
            })
            .map(clone);

          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Regression Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'Pronunciation'
              },
              results: results,
              total_matches: results.length,
              shown_count: results.length,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: results.length ? 1 : 0,
              page_end: results.length ? results.length : 0,
              issues_only: false,
              review_only: reviewOnly,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_set_ipa_keyboard_transcription_review_state') {
          const finishReviewStateRequest = function () {
            const recordingId = parseInt(requestData.recording_id, 10) || 0;
            const needsReview = !!requestData.needs_review;

            window.__llTranscriptionManagerMock.recordings = window.__llTranscriptionManagerMock.recordings.map(function (recording) {
              if (recording.recording_id !== recordingId) {
                return recording;
              }

              const reviewField = requestData.review_field === 'recording_text' ? 'recording_text' : 'recording_ipa';
              const reviewFields = Object.assign({ recording_text: false, recording_ipa: false }, recording.review_fields || {});
              reviewFields[reviewField] = needsReview;
              const stillNeedsReview = !!(reviewFields.recording_text || reviewFields.recording_ipa);

              return Object.assign({}, recording, {
                needs_review: stillNeedsReview,
                review_fields: reviewFields,
                review_note: stillNeedsReview ? (recording.review_note || '') : ''
              });
            });

            const updated = window.__llTranscriptionManagerMock.recordings.find(function (recording) {
              return recording.recording_id === recordingId;
            });

            deferred.resolve({
              success: true,
              data: {
                recording: clone(updated)
              }
            });
          };

          if (window.__llTranscriptionManagerMock.holdReviewStateRequests) {
            window.__llTranscriptionManagerMock.pendingReviewStateRequests.push({
              finish: finishReviewStateRequest
            });
            return;
          }

          finishReviewStateRequest();
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const rows = page.locator('#ll-ipa-search-results tbody tr');
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toHaveAttribute('data-recording-id', '101');
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '1');
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-note')).toHaveCount(1);
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-note')).toHaveText('Check the vowel length before clearing.');
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-note')).toHaveCount(1);
  await expect(rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-field-review-note')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-review-status')).toHaveText('×Needs review');
  await expect(rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-review-status')).toHaveText('✓Reviewed');
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-toggle')).toHaveText('Mark as reviewed');
  await expect(rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-field-review-toggle')).toHaveText('Mark as needing review');
  await expect(rows.nth(0).locator('.ll-ipa-search-review-note, .ll-ipa-search-review-note-compact')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-review .ll-ipa-search-field-review-note')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-review')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-action-toggle')).toHaveCount(0);

  const mobileLayout = await rows.nth(0).evaluate((row) => {
    const textCell = row.querySelector('.ll-ipa-search-text-cell').getBoundingClientRect();
    const ipaCell = row.querySelector('.ll-ipa-search-ipa-cell').getBoundingClientRect();
    const transcriptionCell = row.querySelector('.ll-ipa-search-transcription-cell');
    const textInput = row.querySelector('.ll-ipa-search-text-input');
    const results = document.querySelector('#ll-ipa-search-results');
    return {
      rowDisplay: window.getComputedStyle(row).display,
      textTop: textCell.top,
      ipaTop: ipaCell.top,
      transcriptionLabel: transcriptionCell ? transcriptionCell.getAttribute('data-label') : '',
      textInputTag: textInput ? textInput.tagName : '',
      scrollWidth: results.scrollWidth,
      clientWidth: results.clientWidth
    };
  });

  expect(mobileLayout.rowDisplay).toBe('grid');
  expect(mobileLayout.ipaTop).toBeGreaterThan(mobileLayout.textTop);
  expect(mobileLayout.transcriptionLabel).toBe('Transcriptions');
  expect(mobileLayout.textInputTag).toBe('TEXTAREA');
  expect(mobileLayout.scrollWidth).toBeLessThanOrEqual(mobileLayout.clientWidth + 1);

  await page.setViewportSize({ width: 1180, height: 800 });

  const laptopLayout = await rows.nth(0).evaluate((row) => {
    const table = row.closest('.ll-ipa-search-table').getBoundingClientRect();
    const transcriptionCell = row.querySelector('.ll-ipa-search-transcription-cell').getBoundingClientRect();
    const issuesCell = row.querySelector('.ll-ipa-search-issues-cell').getBoundingClientRect();
    const metaCategories = row.querySelector('.ll-ipa-search-meta-categories');
    return {
      categoryColumnHeadingCount: document.querySelectorAll('.ll-ipa-search-categories-heading').length,
      categoryColumnCellCount: document.querySelectorAll('.ll-ipa-search-categories-cell').length,
      metaCategoryDisplay: metaCategories ? window.getComputedStyle(metaCategories).display : '',
      metaCategoryText: metaCategories ? metaCategories.textContent.trim() : '',
      tableWidth: table.width,
      transcriptionWidth: transcriptionCell.width,
      issuesWidth: issuesCell.width
    };
  });

  expect(laptopLayout.categoryColumnHeadingCount).toBe(0);
  expect(laptopLayout.categoryColumnCellCount).toBe(0);
  expect(laptopLayout.metaCategoryDisplay).toBe('grid');
  expect(laptopLayout.metaCategoryText).toContain('Regression Category');
  expect(laptopLayout.transcriptionWidth / laptopLayout.tableWidth).toBeGreaterThan(0.45);
  expect(laptopLayout.transcriptionWidth).toBeGreaterThan(laptopLayout.issuesWidth * 1.75);

  await page.evaluate(() => {
    window.__llCategoryLinkClicks = 0;
    document.querySelectorAll('.ll-ipa-search-category-link').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        window.__llCategoryLinkClicks += 1;
      });
    });
  });

  const categoryEmptyTarget = await rows.nth(0).evaluate((row) => {
    const wrap = row.querySelector('.ll-ipa-search-meta-categories');
    const link = row.querySelector('.ll-ipa-search-category-link');
    const wrapRect = wrap.getBoundingClientRect();
    const linkRect = link.getBoundingClientRect();
    const emptyWidth = wrapRect.right - linkRect.right;
    const x = Math.round(Math.min(wrapRect.right - 2, linkRect.right + Math.max(6, emptyWidth / 2)));
    const y = Math.round(linkRect.top + (linkRect.height / 2));
    const hit = document.elementFromPoint(x, y);

    return {
      x,
      y,
      wrapperWidth: Math.round(wrapRect.width),
      linkWidth: Math.round(linkRect.width),
      hitClass: hit && hit.className ? String(hit.className) : ''
    };
  });

  expect(categoryEmptyTarget.wrapperWidth - categoryEmptyTarget.linkWidth).toBeGreaterThan(20);
  expect(categoryEmptyTarget.hitClass).not.toContain('ll-ipa-search-category-link');

  await page.mouse.click(categoryEmptyTarget.x, categoryEmptyTarget.y);
  await expect.poll(async () => page.evaluate(() => window.__llCategoryLinkClicks)).toBe(0);

  await rows.nth(0).locator('.ll-ipa-search-category-link').click();
  await expect.poll(async () => page.evaluate(() => window.__llCategoryLinkClicks)).toBe(1);

  await page.evaluate(() => {
    window.__llTranscriptionManagerMock.holdReviewStateRequests = true;
  });

  const getReviewActionLayout = async (actionLocator) => actionLocator.evaluate((action) => {
    const link = action.querySelector('.ll-ipa-search-field-review-link');
    const toggle = action.querySelector('.ll-ipa-review-toggle');
    const linkStyle = link ? window.getComputedStyle(link) : null;
    const toggleStyle = toggle ? window.getComputedStyle(toggle) : null;

    return {
      actionHeight: Math.round(action.getBoundingClientRect().height),
      linkHeight: link ? Math.round(link.getBoundingClientRect().height) : 0,
      linkDisplay: linkStyle ? linkStyle.display : '',
      toggleDisplay: toggleStyle ? toggleStyle.display : '',
      toggleVisibility: toggleStyle ? toggleStyle.visibility : ''
    };
  });

  const reviewTextAction = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-action');
  const reviewTextToggle = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-review-toggle');
  const reviewTextStatus = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-review-status');
  const reviewIpaToggle = rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-review-toggle');
  const reviewIpaStatus = rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-review-status');
  const initialReviewTextLayout = await getReviewActionLayout(reviewTextAction);
  const initialReviewRowLayout = await getOrthographySuggestionLayout(page);
  expect(initialReviewTextLayout.actionHeight).toBeGreaterThan(0);
  expect(initialReviewTextLayout.linkHeight).toBeGreaterThan(0);
  expect(initialReviewTextLayout.linkDisplay).not.toBe('none');
  expect(initialReviewRowLayout.textBlockHeight).toBeGreaterThan(0);
  await reviewTextToggle.click();

  await expect(reviewTextToggle).toBeDisabled();
  await expect(reviewTextToggle).toHaveClass(/is-saving/);
  await expect(reviewTextToggle).toHaveAttribute('aria-busy', 'true');
  await expect(reviewTextToggle).toBeHidden();
  await expect(reviewTextStatus).toHaveClass(/is-saving/);
  await expect(reviewTextStatus).toHaveAttribute('aria-busy', 'true');
  await expect(reviewTextStatus.locator('.ll-ipa-search-review-status-label')).toHaveText('Saving...');
  const savingReviewTextLayout = await getReviewActionLayout(reviewTextAction);
  expect(savingReviewTextLayout.toggleDisplay).not.toBe('none');
  expect(savingReviewTextLayout.toggleVisibility).toBe('hidden');
  expect(savingReviewTextLayout.linkHeight).toBe(initialReviewTextLayout.linkHeight);
  expect(Math.abs(savingReviewTextLayout.actionHeight - initialReviewTextLayout.actionHeight)).toBeLessThanOrEqual(1);
  await expect(reviewIpaToggle).toBeEnabled();
  await reviewIpaToggle.click();
  await expect(reviewIpaToggle).toBeDisabled();
  await expect(reviewIpaToggle).toHaveClass(/is-saving/);
  await expect(reviewIpaToggle).toBeHidden();
  await expect(reviewIpaStatus).toHaveClass(/is-saving/);
  await expect(reviewIpaStatus.locator('.ll-ipa-search-review-status-label')).toHaveText('Saving...');
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '1');

  await page.evaluate(() => {
    const mock = window.__llTranscriptionManagerMock;
    const pending = mock.pendingReviewStateRequests.shift();
    mock.holdReviewStateRequests = false;
    if (pending) {
      pending.finish();
    }
  });

  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toHaveAttribute('data-recording-id', '101');
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '1');
  await expect(rows.nth(0).locator('.ll-ipa-search-review')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-action-toggle')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-toggle')).toHaveCount(2);
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-review-status.is-reviewed')).toHaveCount(1);
  await expect(rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-review-status.is-needs-review')).toHaveCount(1);
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-toggle')).toHaveText('Mark as needing review');
  await expect(rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-field-review-toggle')).toHaveText('Mark as reviewed');
  const reviewedReviewTextLayout = await getReviewActionLayout(reviewTextAction);
  expect(reviewedReviewTextLayout.toggleVisibility).toBe('visible');
  expect(Math.abs(reviewedReviewTextLayout.actionHeight - savingReviewTextLayout.actionHeight)).toBeLessThanOrEqual(1);
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-note')).toHaveCount(0);
  const reviewedReviewRowLayout = await getOrthographySuggestionLayout(page);
  expect(reviewedReviewRowLayout.textBlockHeight).toBeGreaterThanOrEqual(initialReviewRowLayout.textBlockHeight);
  expect(reviewedReviewRowLayout.rowHeight).toBeGreaterThanOrEqual(initialReviewRowLayout.rowHeight);
  await expect(rows.nth(1)).toHaveAttribute('data-recording-id', '202');
  await expect(rows.nth(1)).toHaveAttribute('data-needs-review', '1');
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Marked for review.');

  await page.evaluate(() => {
    window.__llTranscriptionManagerMock.holdReviewStateRequests = true;
  });

  const flagTextToggle = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-review-toggle');
  const flagTextStatus = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-review-status');
  await expect(flagTextToggle).toHaveText('Mark as needing review');
  await flagTextToggle.click();

  await expect(flagTextToggle).toBeDisabled();
  await expect(flagTextToggle).toHaveClass(/is-saving/);
  await expect(flagTextToggle).toBeHidden();
  await expect(flagTextStatus).toHaveClass(/is-saving/);
  await expect(flagTextStatus.locator('.ll-ipa-search-review-status-label')).toHaveText('Saving...');
  await expect(rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-review-toggle')).toBeEnabled();
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '1');

  await page.evaluate(() => {
    const mock = window.__llTranscriptionManagerMock;
    const pending = mock.pendingReviewStateRequests.shift();
    mock.holdReviewStateRequests = false;
    if (pending) {
      pending.finish();
    }
  });

  await expect(rows.nth(0)).toHaveAttribute('data-recording-id', '101');
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '1');
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-review-status.is-needs-review .ll-ipa-search-review-status-label')).toHaveText('Needs review');
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-toggle')).toHaveText('Mark as reviewed');
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Marked for review.');

  const actions = await page.evaluate(() => {
    return window.__llTranscriptionManagerMock.postCalls.map(function (call) {
      return call.data.action;
    });
  });

  expect(actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings',
    'll_tools_set_ipa_keyboard_transcription_review_state',
    'll_tools_set_ipa_keyboard_transcription_review_state',
    'll_tools_set_ipa_keyboard_transcription_review_state'
  ]);
});

test('shared review notes render on every flagged transcription field after partial review', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 800 });
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    const reviewNote = 'Check both transcription fields before publishing.';
    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: false,
        review_only: true,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llSharedReviewNoteMock = {
      recording: {
        recording_id: 303,
        word_id: 55,
        word_text: 'Gamma',
        word_translation: '',
        word_edit_link: '',
        recording_text: 'gamma',
        recording_translation: '',
        recording_ipa: 'gamma',
        categories: [],
        issues: [],
        ignored_issues: [],
        issue_count: 0,
        ignored_issue_count: 0,
        needs_review: true,
        review_fields: {
          recording_text: true,
          recording_ipa: true
        },
        review_note: reviewNote,
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      },
      postCalls: [],
      pendingReviewStateRequests: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      const mock = window.__llSharedReviewNoteMock;
      mock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Shared Note Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'Pronunciation'
              },
              results: [clone(mock.recording)],
              total_matches: 1,
              shown_count: 1,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: 1,
              page_end: 1,
              issues_only: false,
              review_only: true,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_set_ipa_keyboard_transcription_review_state') {
          mock.pendingReviewStateRequests.push({
            finish() {
              const reviewField = requestData.review_field === 'recording_text'
                ? 'recording_text'
                : 'recording_ipa';
              const reviewFields = Object.assign({ recording_text: false, recording_ipa: false }, mock.recording.review_fields || {});
              reviewFields[reviewField] = !!requestData.needs_review;
              const stillNeedsReview = !!(reviewFields.recording_text || reviewFields.recording_ipa);
              mock.recording = Object.assign({}, mock.recording, {
                needs_review: stillNeedsReview,
                review_fields: reviewFields,
                review_note: stillNeedsReview ? reviewNote : ''
              });
              deferred.resolve({
                success: true,
                data: {
                  recording: clone(mock.recording)
                }
              });
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const row = page.locator('#ll-ipa-search-results tbody tr').first();
  await expect(row).toHaveAttribute('data-recording-id', '303');
  await expect(row.locator('.ll-ipa-search-field-review-note')).toHaveCount(2);
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-note')).toHaveText('Check both transcription fields before publishing.');
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-field-review-note')).toHaveText('Check both transcription fields before publishing.');
  const beforeReviewLayout = await getOrthographySuggestionLayout(page);

  await row.locator('.ll-ipa-search-text-cell .ll-ipa-review-toggle').click();
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-review-toggle')).toBeDisabled();
  await expect.poll(async () => page.evaluate(() => {
    return window.__llSharedReviewNoteMock.pendingReviewStateRequests.length;
  })).toBe(1);

  await page.evaluate(() => {
    const pending = window.__llSharedReviewNoteMock.pendingReviewStateRequests.shift();
    if (pending) {
      pending.finish();
    }
  });

  await expect(row).toHaveAttribute('data-needs-review', '1');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-note')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-field-review-note')).toHaveCount(1);
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-field-review-note')).toHaveText('Check both transcription fields before publishing.');
  const afterReviewLayout = await getOrthographySuggestionLayout(page);
  expect(afterReviewLayout.ipaBlockHeight).toBeGreaterThanOrEqual(beforeReviewLayout.ipaBlockHeight);
  expect(afterReviewLayout.rowHeight).toBeGreaterThanOrEqual(beforeReviewLayout.rowHeight);
});

test('search results render a small first chunk and append later rows on demand', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 800 });
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function buildRecording(recordingId, wordText) {
      return {
        recording_id: recordingId,
        word_text: wordText,
        word_translation: '',
        word_edit_link: '',
        recording_text: wordText.toLowerCase(),
        recording_translation: '',
        recording_ipa: wordText.toLowerCase(),
        categories: [],
        issues: [],
        ignored_issues: [],
        issue_count: 0,
        ignored_issue_count: 0,
        needs_review: false,
        review_fields: {
          recording_text: false,
          recording_ipa: false
        },
        review_note: '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      };
    }

    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: false,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      searchInitialPerPage: 2,
      i18n: {}
    };

    window.__llLazySearchMock = {
      recordings: [
        buildRecording(101, 'Alpha'),
        buildRecording(202, 'Beta'),
        buildRecording(303, 'Charlie'),
        buildRecording(404, 'Delta'),
        buildRecording(505, 'Echo')
      ],
      postCalls: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llLazySearchMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        if (requestData.action !== 'll_tools_search_ipa_keyboard_recordings') {
          deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
          return;
        }

        const perPage = Math.max(1, parseInt(requestData.per_page, 10) || 100);
        const requestedPage = Math.max(1, parseInt(requestData.search_page, 10) || 1);
        const totalMatches = window.__llLazySearchMock.recordings.length;
        const totalPages = Math.max(1, Math.ceil(totalMatches / perPage));
        const currentPage = Math.min(requestedPage, totalPages);
        const offset = (currentPage - 1) * perPage;
        const results = window.__llLazySearchMock.recordings.slice(offset, offset + perPage);

        deferred.resolve({
          success: true,
          data: {
            wordset: {
              id: 7,
              name: 'Regression Wordset'
            },
            transcription: {
              mode: 'ipa',
              symbols_column_label: 'Pronunciation'
            },
            results: results.map(clone),
            total_matches: totalMatches,
            shown_count: results.length,
            has_more: currentPage < totalPages,
            current_page: currentPage,
            total_pages: totalPages,
            per_page: perPage,
            page_start: results.length ? offset + 1 : 0,
            page_end: results.length ? offset + results.length : 0,
            issues_only: false,
            review_only: false,
            exact_transcription: false,
            can_edit: true,
            validation_config: {
              supports_rules: true,
              builtin_rules: [],
              custom_rules: []
            }
          }
        });
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  await expect(page.locator('.ll-ipa-search-table tbody tr')).toHaveCount(2);
  await expect(page.locator('#ll-ipa-search-summary')).toHaveText('Showing 1-2 of 5 results');
  await expect(page.locator('.ll-ipa-search-load-more')).toHaveText('Load more');

  await page.locator('.ll-ipa-search-load-more').click();

  await expect(page.locator('.ll-ipa-search-table tbody tr')).toHaveCount(4);
  await expect(page.locator('#ll-ipa-search-summary')).toHaveText('Showing 1-4 of 5 results');
  await expect(page.locator('.ll-ipa-search-word-link')).toHaveText(['Alpha', 'Beta', 'Charlie', 'Delta']);

  const searchCalls = await page.evaluate(() => {
    return window.__llLazySearchMock.postCalls
      .filter(call => call.data.action === 'll_tools_search_ipa_keyboard_recordings')
      .map(call => ({
        search_page: Number(call.data.search_page),
        per_page: Number(call.data.per_page)
      }));
  });

  expect(searchCalls).toEqual([
    { search_page: 1, per_page: 2 },
    { search_page: 2, per_page: 2 }
  ]);
});

test('recording rows open the detached word editor and sync affected rows without refreshing search', async ({ page }) => {
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    function buildRecording() {
      return {
        recording_id: 303,
        word_id: 77,
        word_text: 'Gamma',
        word_translation: '',
        word_edit_link: '',
        recording_text: 'gamma',
        recording_translation: '',
        recording_ipa: 'gamma',
        categories: [],
        issues: [],
        ignored_issues: [],
        issue_count: 0,
        ignored_issue_count: 0,
        needs_review: false,
        review_fields: {
          recording_text: false,
          recording_ipa: false
        },
        review_note: '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      };
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: false,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llTranscriptionEditMock = {
      recordings: [buildRecording()],
      postCalls: [],
      openCalls: []
    };
    window.LLToolsWordEditModal = {
      open(options) {
        window.__llTranscriptionEditMock.openCalls.push(Object.assign({}, options));
        return Promise.resolve({
          wordId: options.wordId,
          wordsetId: options.wordsetId,
          recordingId: options.recordingId
        });
      }
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llTranscriptionEditMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        const mock = window.__llTranscriptionEditMock;
        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          const results = mock.recordings.map(clone);
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Edit Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'Pronunciation'
              },
              results,
              total_matches: results.length,
              shown_count: results.length,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: results.length ? 1 : 0,
              page_end: results.length ? results.length : 0,
              issues_only: false,
              review_only: false,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_get_ipa_keyboard_recordings') {
          const requestedIds = Array.isArray(requestData.recording_ids)
            ? requestData.recording_ids.map(id => Number(id))
            : [];
          const results = mock.recordings
            .filter(recording => requestedIds.includes(Number(recording.recording_id)))
            .map(clone);
          deferred.resolve({
            success: true,
            data: {
              recordings: results,
              missing_recording_ids: requestedIds.filter(id => !results.some(recording => Number(recording.recording_id) === id)),
              can_edit: true
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const rows = page.locator('#ll-ipa-search-results tbody tr');
  await expect(rows).toHaveCount(1);
  const row = rows.first();
  await expect(row).toHaveAttribute('data-word-id', '77');
  const editToggle = row.locator('.ll-ipa-search-word-edit-toggle');
  await expect(editToggle).toHaveAttribute('aria-label', 'Edit word');
  await expect(row.locator('.ll-ipa-search-meta-cell .ll-ipa-search-word-edit-toggle')).toHaveCount(1);
  await expect(row.locator('.ll-ipa-search-action-cell .ll-ipa-search-word-edit-toggle')).toHaveCount(0);
  const editPlacement = await row.evaluate(rowEl => {
    const button = rowEl.querySelector('.ll-ipa-search-word-edit-toggle');
    return {
      inMetaCell: !!(button && button.closest('.ll-ipa-search-meta-cell')),
      inActionCell: !!(button && button.closest('.ll-ipa-search-action-cell')),
      firstPath: button && button.querySelector('path') ? button.querySelector('path').getAttribute('d') : ''
    };
  });
  expect(editPlacement).toEqual({
    inMetaCell: true,
    inActionCell: false,
    firstPath: 'M4 20.5h4l10-10-4-4-10 10v4z'
  });
  await expect(row.locator('.ll-ipa-search-recording-delete-toggle')).toHaveCount(0);

  await editToggle.click();
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Word editor opened.');

  const openCalls = await page.evaluate(() => {
    return window.__llTranscriptionEditMock.openCalls;
  });
  expect(openCalls).toEqual([
    {
      wordId: 77,
      wordsetId: 7,
      recordingId: 303
    }
  ]);

  await page.evaluate(() => {
    const mock = window.__llTranscriptionEditMock;
    mock.recordings = mock.recordings.map(recording => Object.assign({}, recording, {
      word_text: 'Gamma edited',
      recording_text: 'gamma edited',
      recording_ipa: 'gamma edited'
    }));
    window.jQuery(document).trigger('lltools:word-grid-word-updated', [{
      wordId: 77,
      data: {
        word_id: 77,
        recordings: [{ id: 303 }]
      }
    }]);
  });

  const updatedRow = page.locator('#ll-ipa-search-results tbody tr').first();
  await expect(updatedRow.locator('.ll-ipa-search-word-link')).toHaveText('Gamma edited');
  await expect(updatedRow.locator('.ll-ipa-search-text-input')).toHaveValue('gamma edited');
  await expect(updatedRow.locator('.ll-ipa-search-ipa-input')).toHaveValue('gamma edited');

  await page.evaluate(() => {
    const mock = window.__llTranscriptionEditMock;
    mock.recordings = [];
    window.jQuery(document).trigger('lltools:word-grid-recording-deleted', [{
      wordId: 77,
      wordsetId: 7,
      recordingId: 303
    }]);
  });

  await expect(page.locator('#ll-ipa-search-results tbody tr')).toHaveCount(0);
  await expect(page.locator('#ll-ipa-search-results .ll-ipa-empty')).toHaveText('No recordings matched this search.');
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Transcription rows updated.');

  const actions = await page.evaluate(() => {
    return window.__llTranscriptionEditMock.postCalls.map(function (call) {
      return call.data.action;
    });
  });

  expect(actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings',
    'll_tools_get_ipa_keyboard_recordings'
  ]);
});

test('dirty transcription rows save once and then open the detached word editor', async ({ page }) => {
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    function buildRecording() {
      return {
        recording_id: 404,
        word_id: 88,
        word_text: 'Delta',
        word_translation: '',
        word_edit_link: '',
        recording_text: 'delta',
        recording_translation: '',
        recording_ipa: 'delta ipa',
        categories: [],
        issues: [],
        ignored_issues: [],
        issue_count: 0,
        ignored_issue_count: 0,
        needs_review: false,
        review_fields: {
          recording_text: false,
          recording_ipa: false
        },
        review_note: '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      };
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: false,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llQueuedEditorMock = {
      recording: buildRecording(),
      postCalls: [],
      openCalls: []
    };
    window.LLToolsWordEditModal = {
      open(options) {
        window.__llQueuedEditorMock.openCalls.push(Object.assign({}, options));
        return Promise.resolve({
          wordId: options.wordId,
          wordsetId: options.wordsetId,
          recordingId: options.recordingId
        });
      }
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llQueuedEditorMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        const mock = window.__llQueuedEditorMock;
        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Queued Editor Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'Pronunciation'
              },
              results: [clone(mock.recording)],
              total_matches: 1,
              shown_count: 1,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: 1,
              page_end: 1,
              issues_only: false,
              review_only: false,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_update_ipa_keyboard_recording') {
          mock.recording = Object.assign({}, mock.recording, {
            recording_text: requestData.recording_text,
            recording_ipa: requestData.recording_ipa
          });
          deferred.resolve({
            success: true,
            data: {
              recording: clone(mock.recording),
              validation: null,
              keyboard_symbols: []
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, requestData.action === 'll_tools_update_ipa_keyboard_recording' ? 150 : 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const row = page.locator('#ll-ipa-search-results tbody tr').first();
  await expect(row).toHaveAttribute('data-word-id', '88');
  await row.locator('.ll-ipa-search-text-input').fill('delta changed');
  await row.locator('.ll-ipa-search-word-edit-toggle').click();

  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Saving changes before opening the word editor...');
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Word editor opened.');

  const result = await page.evaluate(() => {
    return {
      actions: window.__llQueuedEditorMock.postCalls.map(call => call.data.action),
      openCalls: window.__llQueuedEditorMock.openCalls,
      savedText: window.__llQueuedEditorMock.recording.recording_text
    };
  });

  expect(result.actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings',
    'll_tools_update_ipa_keyboard_recording'
  ]);
  expect(result.openCalls).toEqual([
    {
      wordId: 88,
      wordsetId: 7,
      recordingId: 404
    }
  ]);
  expect(result.savedText).toBe('delta changed');
});

test('detached word editor shows a loading shell immediately and reuses cached one-word markup', async ({ page }) => {
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(`
    <div class="ll-word-edit-modal-host" data-ll-word-edit-modal-host aria-live="polite">
      <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-word-edit-modal-grid="1"></div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    window.llToolsWordEditModalData = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'modal-nonce',
      i18n: {
        loading: 'Loading word editor...'
      }
    };
    window.LLToolsWordGrid = {
      configCalls: [],
      applyConfig(config) {
        this.configCalls.push(config || {});
      }
    };
    window.__llModalMock = {
      postCalls: [],
      deferreds: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      window.__llModalMock.postCalls.push({
        url: String(url || ''),
        data: Object.assign({}, data)
      });
      window.__llModalMock.deferreds.push(deferred);
      return deferred.promise();
    };
  });
  await page.addScriptTag({ content: wordEditModalSource });

  await page.evaluate(() => {
    window.__llModalMock.firstOpen = window.LLToolsWordEditModal.open({
      wordId: 91,
      wordsetId: 7,
      recordingId: 505
    });
  });

  await expect(page.locator('[data-ll-word-edit-modal-loading-shell]')).toBeVisible();
  await expect(page.locator('[data-ll-word-edit-modal-loading-shell]')).toHaveText('Loading word editor...');
  expect(await page.evaluate(() => document.body.classList.contains('ll-word-edit-modal-loading-open'))).toBe(true);

  await page.evaluate(() => {
    window.__llModalMock.deferreds[0].resolve({
      success: true,
      data: {
        html: `
          <div class="word-grid ll-word-grid" data-ll-word-grid>
            <article class="word-item" data-word-id="91">
              <button type="button" data-ll-word-edit-toggle aria-expanded="false">Edit</button>
              <div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="true" hidden></div>
              <div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="true">
                <div class="ll-word-edit-recording" data-recording-id="505">
                  <input data-ll-recording-input="text" value="cached" />
                </div>
              </div>
            </article>
          </div>
        `,
        config: {
          canEdit: true
        }
      }
    });
  });
  await page.evaluate(() => window.__llModalMock.firstOpen);

  await expect(page.locator('[data-ll-word-edit-modal-loading-shell]')).toBeHidden();
  expect(await page.evaluate(() => document.body.classList.contains('ll-word-edit-modal-loading-open'))).toBe(false);

  await page.evaluate(() => window.LLToolsWordEditModal.open({
    wordId: 91,
    wordsetId: 7,
    recordingId: 505
  }));

  const modalState = await page.evaluate(() => ({
    postCallCount: window.__llModalMock.postCalls.length,
    configCallCount: window.LLToolsWordGrid.configCalls.length,
    renderedWordId: document.querySelector('[data-ll-word-edit-modal-grid] .word-item')?.getAttribute('data-word-id') || ''
  }));

  expect(modalState).toEqual({
    postCallCount: 1,
    configCallCount: 2,
    renderedWordId: '91'
  });
});

test('transcription autosave keeps fields editable and preserves newer edits', async ({ page }) => {
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    function buildRecording(recording) {
      return Object.assign({}, recording, {
        word_text: 'Alpha',
        word_translation: '',
        word_edit_link: '',
        recording_translation: '',
        categories: [],
        issues: [],
        ignored_issues: [],
        issue_count: 0,
        ignored_issue_count: 0,
        needs_review: false,
        review_fields: {
          recording_text: false,
          recording_ipa: false
        },
        review_note: '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      });
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: false,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llAutosaveResponsivenessMock = {
      recording: {
        recording_id: 101,
        word_id: 55,
        recording_text: 'alpha',
        recording_ipa: 'alpha ipa'
      },
      postCalls: [],
      pendingUpdateRequests: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llAutosaveResponsivenessMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        const mock = window.__llAutosaveResponsivenessMock;

        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Autosave Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'IPA'
              },
              results: [clone(buildRecording(mock.recording))],
              total_matches: 1,
              shown_count: 1,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: 1,
              page_end: 1,
              issues_only: false,
              review_only: false,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_update_ipa_keyboard_recording') {
          mock.pendingUpdateRequests.push({
            finish() {
              mock.recording = Object.assign({}, mock.recording, {
                recording_text: String(requestData.recording_text || ''),
                recording_ipa: String(requestData.recording_ipa || '')
              });
              const row = buildRecording(mock.recording);
              deferred.resolve({
                success: true,
                data: {
                  recording: clone(row),
                  validation: {
                    active: [],
                    ignored: []
                  },
                  keyboard_symbols: [],
                  transcription: {
                    mode: 'ipa',
                    symbols_column_label: 'IPA'
                  }
                }
              });
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const row = page.locator('#ll-ipa-search-results tbody tr').first();
  const textInput = row.locator('.ll-ipa-search-text-input');
  const ipaInput = row.locator('.ll-ipa-search-ipa-input');
  await expect(textInput).toHaveValue('alpha');
  await expect(ipaInput).toHaveValue('alpha ipa');

  const getAutosaveLayout = async () => row.evaluate((rowElement) => {
    const saveState = rowElement.querySelector('.ll-ipa-search-save-state');
    const saveLabel = rowElement.querySelector('.ll-ipa-search-save-label');
    const saveIndicator = rowElement.querySelector('.ll-ipa-search-save-indicator');
    const actionCell = rowElement.querySelector('.ll-ipa-search-action-cell');
    const labelStyle = window.getComputedStyle(saveLabel);
    return {
      actionCellHeight: Math.round(actionCell.getBoundingClientRect().height),
      indicatorWidth: Math.round(saveIndicator.getBoundingClientRect().width),
      labelDisplay: labelStyle.display,
      labelVisibility: labelStyle.visibility,
      labelWidth: Math.round(saveLabel.getBoundingClientRect().width),
      rowHeight: Math.round(rowElement.getBoundingClientRect().height),
      saveStateHeight: Math.round(saveState.getBoundingClientRect().height),
      saveStateWidth: Math.round(saveState.getBoundingClientRect().width)
    };
  });
  const idleLayout = await getAutosaveLayout();
  expect(idleLayout.indicatorWidth).toBeGreaterThanOrEqual(16);
  expect(idleLayout.labelDisplay).not.toBe('none');
  expect(idleLayout.labelVisibility).toBe('hidden');
  expect(idleLayout.labelWidth).toBeGreaterThanOrEqual(60);
  expect(idleLayout.saveStateHeight).toBeGreaterThanOrEqual(32);

  await textInput.fill('alpha edited');
  await page.locator('#ll-ipa-search-btn').focus();
  await expect.poll(async () => page.evaluate(() => {
    return window.__llAutosaveResponsivenessMock.pendingUpdateRequests.length;
  })).toBe(1);

  await expect(textInput).toBeEnabled();
  await expect(ipaInput).toBeEnabled();
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saving...');
  const savingLayout = await getAutosaveLayout();
  expect(savingLayout.labelVisibility).toBe('visible');
  expect(Math.abs(savingLayout.rowHeight - idleLayout.rowHeight)).toBeLessThanOrEqual(1);
  expect(Math.abs(savingLayout.actionCellHeight - idleLayout.actionCellHeight)).toBeLessThanOrEqual(1);
  expect(savingLayout.saveStateHeight).toBe(idleLayout.saveStateHeight);
  expect(savingLayout.saveStateWidth).toBe(idleLayout.saveStateWidth);

  await ipaInput.fill('ipa edited');
  await page.evaluate(() => {
    const pending = window.__llAutosaveResponsivenessMock.pendingUpdateRequests.shift();
    if (pending) {
      pending.finish();
    }
  });

  await expect(ipaInput).toHaveValue('ipa edited');
  await expect.poll(async () => page.evaluate(() => {
    return window.__llAutosaveResponsivenessMock.pendingUpdateRequests.length;
  })).toBe(1);

  await page.evaluate(() => {
    const pending = window.__llAutosaveResponsivenessMock.pendingUpdateRequests.shift();
    if (pending) {
      pending.finish();
    }
  });

  await expect(textInput).toHaveValue('alpha edited');
  await expect(ipaInput).toHaveValue('ipa edited');
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saved.');
  const savedLayout = await getAutosaveLayout();
  expect(Math.abs(savedLayout.rowHeight - savingLayout.rowHeight)).toBeLessThanOrEqual(1);
  expect(Math.abs(savedLayout.actionCellHeight - savingLayout.actionCellHeight)).toBeLessThanOrEqual(1);
  expect(savedLayout.saveStateHeight).toBe(savingLayout.saveStateHeight);
  expect(savedLayout.saveStateWidth).toBe(savingLayout.saveStateWidth);

  const updatePayloads = await page.evaluate(() => {
    return window.__llAutosaveResponsivenessMock.postCalls
      .filter(call => call.data.action === 'll_tools_update_ipa_keyboard_recording')
      .map(call => ({
        recording_text: call.data.recording_text,
        recording_ipa: call.data.recording_ipa
      }));
  });
  expect(updatePayloads).toEqual([
    {
      recording_text: 'alpha edited',
      recording_ipa: 'alpha ipa'
    },
    {
      recording_text: 'alpha edited',
      recording_ipa: 'ipa edited'
    }
  ]);
});

test('orthography suggestion chips update the field and autosave inline', async ({ page }) => {
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    const spacer = document.createElement('div');
    spacer.style.height = '1400px';
    spacer.setAttribute('data-test-spacer', 'orthography-suggestion-scroll');
    document.body.insertBefore(spacer, document.querySelector('.ll-ipa-admin'));
    const trailingSpacer = document.createElement('div');
    trailingSpacer.style.height = '1400px';
    trailingSpacer.setAttribute('data-test-spacer', 'orthography-suggestion-scroll-after');
    document.body.appendChild(trailingSpacer);
  });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    function buildRecording(recording, includeIssue) {
      const issue = {
        rule_key: 'builtin:orthography_mismatch',
        code: 'orthography_mismatch',
        type: 'builtin',
        label: 'Orthography mismatch',
        message: 'Saved text does not match the conversion profile.',
        count: 1,
        samples: [],
        orthography_mismatch: {
          actual_text: String(recording.recording_text || ''),
          suggested_text: 'alpha',
          canonical_suggested_text: 'alpha',
          ipa_text: String(recording.recording_ipa || ''),
          matches: false,
          actual_spans: [{ start: 2, length: 1 }],
          suggested_spans: [{ start: 4, length: 1 }],
          ipa_spans: [{ start: 2, length: 1 }],
          ipa_suggestions: []
        }
      };
      const issues = includeIssue ? [issue] : [];
      return Object.assign({}, recording, {
        word_text: 'Alpha',
        word_translation: '',
        word_edit_link: '',
        recording_translation: '',
        categories: [],
        issues,
        ignored_issues: [],
        issue_count: issues.length,
        ignored_issue_count: 0,
        needs_review: false,
        review_fields: {
          recording_text: false,
          recording_ipa: false
        },
        review_note: '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      });
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: true,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llOrthographySuggestionMock = {
      recording: {
        recording_id: 101,
        word_id: 55,
        recording_text: 'alfa',
        recording_ipa: 'alfa'
      },
      includeIssue: true,
      postCalls: [],
      pendingUpdateRequests: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llOrthographySuggestionMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        const mock = window.__llOrthographySuggestionMock;

        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          const row = buildRecording(mock.recording, mock.includeIssue);
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Suggestion Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'IPA'
              },
              results: [clone(row)],
              total_matches: 1,
              shown_count: 1,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: 1,
              page_end: 1,
              issues_only: true,
              review_only: false,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_update_ipa_keyboard_recording') {
          mock.pendingUpdateRequests.push({
            finish() {
              mock.recording = Object.assign({}, mock.recording, {
                recording_text: String(requestData.recording_text || ''),
                recording_ipa: String(requestData.recording_ipa || '')
              });
              mock.includeIssue = false;
              const row = buildRecording(mock.recording, false);
              deferred.resolve({
                success: true,
                data: {
                  recording: clone(row),
                  validation: {
                    active: [],
                    ignored: []
                  },
                  keyboard_symbols: [],
                  transcription: {
                    mode: 'ipa',
                    symbols_column_label: 'IPA'
                  }
                }
              });
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const row = page.locator('#ll-ipa-search-results tbody tr').first();
  const textInput = row.locator('.ll-ipa-search-text-input');
  await expect(textInput).toHaveValue('alfa');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveText('Change to: alpha');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip .ll-ipa-mismatch-mark')).toHaveText('a');
  const beforeSuggestionLayout = await getOrthographySuggestionLayout(page);
  expect(beforeSuggestionLayout.suggestionCount).toBe(1);

  await row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip').click();
  await expect(textInput).toHaveValue('alpha');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-mismatch-mark')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saving...');
  const savingSuggestionLayout = await getOrthographySuggestionLayout(page);
  expect(savingSuggestionLayout.suggestionCount).toBe(0);
  expect(savingSuggestionLayout.textBlockHeight).toBeGreaterThanOrEqual(beforeSuggestionLayout.textBlockHeight);
  expect(savingSuggestionLayout.transcriptionHeight).toBeGreaterThanOrEqual(beforeSuggestionLayout.transcriptionHeight);
  expect(savingSuggestionLayout.rowHeight).toBeGreaterThanOrEqual(beforeSuggestionLayout.rowHeight);
  expect(Math.abs(savingSuggestionLayout.ipaBlockTop - beforeSuggestionLayout.ipaBlockTop)).toBeLessThanOrEqual(1);
  await expect.poll(async () => page.evaluate(() => {
    return window.__llOrthographySuggestionMock.pendingUpdateRequests.length;
  })).toBe(1);
  const scrollAtSuggestionClick = await page.evaluate(() => Math.round(window.scrollY));
  const scrollAfterUserMove = await page.evaluate(() => {
    window.scrollTo(0, window.scrollY + 360);
    return Math.round(window.scrollY);
  });
  expect(scrollAfterUserMove).toBeGreaterThan(scrollAtSuggestionClick + 100);

  await page.evaluate(() => {
    const pending = window.__llOrthographySuggestionMock.pendingUpdateRequests.shift();
    if (pending) {
      pending.finish();
    }
  });

  await expect(textInput).toHaveValue('alpha');
  await expect(row.locator('.ll-ipa-search-suggestion-chip')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saved.');
  const savedSuggestionLayout = await getOrthographySuggestionLayout(page);
  expect(savedSuggestionLayout.textBlockHeight).toBeGreaterThanOrEqual(beforeSuggestionLayout.textBlockHeight);
  expect(savedSuggestionLayout.rowHeight).toBeGreaterThanOrEqual(beforeSuggestionLayout.rowHeight);
  expect(Math.abs(savedSuggestionLayout.ipaBlockTop - beforeSuggestionLayout.ipaBlockTop)).toBeLessThanOrEqual(1);
  await page.evaluate(() => new Promise(resolve => window.requestAnimationFrame(resolve)));
  const scrollAfterSaveFinish = await page.evaluate(() => Math.round(window.scrollY));
  expect(Math.abs(scrollAfterSaveFinish - scrollAfterUserMove)).toBeLessThanOrEqual(1);

  const updatePayloads = await page.evaluate(() => {
    return window.__llOrthographySuggestionMock.postCalls
      .filter(call => call.data.action === 'll_tools_update_ipa_keyboard_recording')
      .map(call => ({
        recording_text: call.data.recording_text,
        recording_ipa: call.data.recording_ipa
      }));
  });
  expect(updatePayloads).toEqual([
    {
      recording_text: 'alpha',
      recording_ipa: 'alfa'
    }
  ]);
});

test('orthography mismatch warnings render inline field highlights and suggestions', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 800 });
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    function buildMismatchIssue(recording) {
      const text = String(recording.recording_text || '');
      const apostropheIndex = text.indexOf("'");
      const iIndex = text.indexOf('i');
      const actualSpan = apostropheIndex >= 0
        ? { start: apostropheIndex, length: 1 }
        : { start: Math.max(0, iIndex), length: 1 };
      const actualSpans = Array.isArray(recording.actual_spans) ? recording.actual_spans : [actualSpan];
      const suggestedSpans = Array.isArray(recording.suggested_spans) ? recording.suggested_spans : [{ start: 3, length: 1 }];
      const ipaSpans = Array.isArray(recording.ipa_spans) ? recording.ipa_spans : [{ start: 5, length: 1 }];

      return {
        rule_key: 'builtin:orthography_mismatch',
        code: 'orthography_mismatch',
        type: 'builtin',
        label: 'Orthography mismatch',
        message: 'Saved text does not match the conversion profile.',
        count: 1,
        samples: [],
        orthography_mismatch: {
          actual_text: text,
          suggested_text: 'Ez nızûnû',
          canonical_suggested_text: 'Ez nızûnû',
          ipa_text: String(recording.recording_ipa || ''),
          matches: false,
          actual_spans: actualSpans,
          suggested_spans: suggestedSpans,
          ipa_spans: ipaSpans,
          ipa_suggestions: [
            { ipa: 'ʔɛz nɨzunu', label: 'ʔɛz nɨzunu' },
            { ipa: 'ʔɛz nɪzunu', label: 'ʔɛz nɪzunu' }
          ]
        }
      };
    }

    function buildRecording(recording, includeIssue) {
      const issues = includeIssue ? [buildMismatchIssue(recording)] : [];
      return Object.assign({}, recording, {
        word_text: 'Bilimiyorum',
        word_translation: '',
        word_edit_link: '',
        recording_translation: '',
        categories: [],
        issues,
        ignored_issues: [],
        issue_count: issues.length,
        ignored_issue_count: 0,
        needs_review: false,
        review_fields: {
          recording_text: false,
          recording_ipa: false
        },
        review_note: '',
        image: {},
        audio_url: '',
        audio_label: 'Play recording'
      });
    }

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: true,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llOrthographyInlineMock = {
      recording: {
        recording_id: 101,
        word_id: 55,
        recording_text: "'Ez nızûnû",
        recording_ipa: 'ʔɛz nizunu'
      },
      includeIssue: true,
      postCalls: [],
      holdUpdateRequests: false,
      pendingUpdateRequests: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llOrthographyInlineMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        const mock = window.__llOrthographyInlineMock;

        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          const row = buildRecording(mock.recording, mock.includeIssue);
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Regression Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'IPA'
              },
              results: [clone(row)],
              total_matches: 1,
              shown_count: 1,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: 1,
              page_end: 1,
              issues_only: true,
              review_only: false,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_update_ipa_keyboard_recording') {
          const finishUpdateRequest = function () {
            const ipaChanged = String(requestData.recording_ipa || '') !== String(mock.recording.recording_ipa || '');
            mock.recording = Object.assign({}, mock.recording, {
              recording_text: String(requestData.recording_text || ''),
              recording_ipa: String(requestData.recording_ipa || '')
            });
            mock.includeIssue = !ipaChanged;
            const row = buildRecording(mock.recording, mock.includeIssue);
            deferred.resolve({
              success: true,
              data: {
                recording: clone(row),
                validation: {
                  active: clone(row.issues),
                  ignored: []
                },
                keyboard_symbols: [],
                transcription: {
                  mode: 'ipa',
                  symbols_column_label: 'IPA'
                }
              }
            });
          };

          if (mock.holdUpdateRequests) {
            mock.pendingUpdateRequests.push({
              finish: finishUpdateRequest
            });
            return;
          }

          finishUpdateRequest();
          return;
        }

        if (requestData.action === 'll_tools_update_recording_ipa') {
          mock.recording = Object.assign({}, mock.recording, {
            recording_ipa: String(requestData.recording_ipa || '')
          });
          mock.includeIssue = false;
          const row = buildRecording(mock.recording, false);
          deferred.resolve({
            success: true,
            data: {
              recording: clone(row),
              validation: {
                active: [],
                ignored: []
              },
              keyboard_symbols: [],
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'IPA'
              }
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const row = page.locator('#ll-ipa-search-results tbody tr').first();
  await expect(row.locator('.ll-ipa-search-issue-title')).toHaveText('Orthography mismatch');
  await expect(row.locator('.ll-ipa-search-issue-message')).toHaveText('Saved text does not match the conversion profile.');
  await expect(row.locator('.ll-ipa-search-issues-cell .ll-ipa-search-mismatch-row')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-issues-cell .ll-ipa-search-orthography-apply')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-issues-cell .ll-ipa-issue-toggle')).toHaveCount(0);

  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-input-highlight .ll-ipa-mismatch-mark')).toHaveText("'");
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-input-highlight .ll-ipa-mismatch-mark')).toHaveText('i');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveText('Change to: Ez nızûnû');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip .ll-ipa-mismatch-mark')).toHaveText('n');
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-suggestion-chip')).toHaveText([
    'Change to: ʔɛz nɨzunu',
    'Change to: ʔɛz nɪzunu'
  ]);
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-suggestion-chip').first().locator('.ll-ipa-mismatch-mark')).toHaveText('ɨ');

  await row.locator('.ll-ipa-search-text-input').fill('Ez nizûnû');
  await page.locator('#ll-ipa-search-btn').focus();

  await expect.poll(async () => page.evaluate(() => {
    return window.__llOrthographyInlineMock.postCalls
      .filter(call => call.data.action === 'll_tools_update_ipa_keyboard_recording').length;
  })).toBe(1);
  await expect(row.locator('.ll-ipa-search-text-input')).toHaveValue('Ez nizûnû');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-input-highlight .ll-ipa-mismatch-mark')).toHaveText('i');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveText('Change to: Ez nızûnû');

  await row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-suggestion-chip').nth(1).click();
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saved.');
  await expect(row.locator('.ll-ipa-search-suggestion-chip')).toHaveCount(0);

  const directIpaUpdateCalls = await page.evaluate(() => {
    return window.__llOrthographyInlineMock.postCalls
      .filter(call => call.data.action === 'll_tools_update_recording_ipa').length;
  });
  expect(directIpaUpdateCalls).toBe(0);
  await page.evaluate(() => {
    window.__llOrthographyInlineMock.recording = {
      recording_id: 101,
      word_id: 55,
      recording_text: 'cini xwe xwe vuna agmi ber biqafniten kerg nivecen teber',
      recording_ipa: 'dzini xwe xwe vuna agmi ber biqafniten kerg nivecen teber',
      actual_spans: [
        { start: 0, length: 1 },
        { start: 5, length: 3 },
        { start: 9, length: 3 },
        { start: 18, length: 4 },
        { start: 27, length: 10 }
      ],
      suggested_spans: [
        { start: 0, length: 1 },
        { start: 5, length: 3 },
        { start: 9, length: 3 },
        { start: 18, length: 4 },
        { start: 27, length: 10 }
      ],
      ipa_spans: [
        { start: 0, length: 5 },
        { start: 6, length: 3 },
        { start: 10, length: 3 },
        { start: 19, length: 4 },
        { start: 28, length: 10 }
      ]
    };
    window.__llOrthographyInlineMock.includeIssue = true;
  });
  await page.locator('#ll-ipa-search-btn').click();

  await expect(row.locator('.ll-ipa-search-issue-title')).toHaveText('Orthography mismatch');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-input-highlight .ll-ipa-mismatch-mark')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-input-highlight .ll-ipa-mismatch-mark')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveCount(1);
});

test('unapproved IPA symbol warnings offer a wordset approval mapping action', async ({ page }) => {
  await page.setViewportSize({ width: 760, height: 800 });
  await page.route('**/*', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head></head><body></body></html>'
  }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.setContent(buildMarkup());
  await page.addStyleTag({ content: ipaKeyboardAdminCss });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('llTranscriptionManagerLastWordsetId');
      window.localStorage.removeItem('llTranscriptionManagerLastTab');
    } catch (error) {
      // Ignore storage cleanup failures in the test harness.
    }
  });
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    const issue = {
      rule_key: 'builtin:unapproved_ipa_symbol',
      code: 'unapproved_ipa_symbol',
      type: 'builtin',
      label: 'Unapproved IPA symbol',
      message: 'This IPA token contains a symbol outside the approved inventory.',
      count: 1,
      samples: ['ø'],
      approval_options: [
        {
          symbol: 'ø',
          output: 'ö'
        }
      ]
    };

    window.llIpaKeyboardAdmin = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      selectedWordsetId: 7,
      initialTab: 'search',
      initialSearch: {
        query: '',
        scope: 'both',
        issues_only: true,
        review_only: false,
        exact_transcription: false,
        page: 1
      },
      i18n: {}
    };

    window.__llSymbolApprovalMock = {
      approved: false,
      postCalls: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llSymbolApprovalMock.postCalls.push({
        url: String(url || ''),
        data: requestData
      });

      window.setTimeout(function () {
        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          const hasIssue = !window.__llSymbolApprovalMock.approved;
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'Regression Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'Pronunciation'
              },
              results: [
                {
                  recording_id: 101,
                  word_text: 'Loan word',
                  word_translation: '',
                  word_edit_link: '',
                  recording_text: 'kör',
                  recording_translation: '',
                  recording_ipa: 'kør',
                  categories: [],
                  issues: hasIssue ? [clone(issue)] : [],
                  ignored_issues: [],
                  issue_count: hasIssue ? 1 : 0,
                  ignored_issue_count: 0,
                  needs_review: false,
                  review_fields: {
                    recording_text: false,
                    recording_ipa: false
                  },
                  review_note: '',
                  image: {},
                  audio_url: '',
                  audio_label: 'Play recording'
                }
              ],
              total_matches: 1,
              shown_count: 1,
              has_more: false,
              current_page: 1,
              total_pages: 1,
              per_page: 100,
              page_start: 1,
              page_end: 1,
              issues_only: true,
              review_only: false,
              exact_transcription: false,
              can_edit: true,
              validation_config: {
                supports_rules: true,
                builtin_rules: [],
                custom_rules: [],
                approved_ipa_symbols: window.__llSymbolApprovalMock.approved ? ['ø'] : []
              }
            }
          });
          return;
        }

        if (requestData.action === 'll_tools_approve_ipa_keyboard_symbol_mapping') {
          window.__llSymbolApprovalMock.approved = true;
          deferred.resolve({
            success: true,
            data: {
              approved_symbol: 'ø',
              orthography_output: 'ö',
              approved_ipa_symbols: ['ø'],
              rescanned_count: 1
            }
          });
          return;
        }

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const approveButton = page.locator('.ll-ipa-symbol-approval').first();
  await expect(approveButton).toHaveText('Approve ø symbol and map it to ö in orthography');
  await expect(page.locator('.ll-ipa-issue-toggle').first()).toHaveText('Ignore for this transcription');

  await approveButton.click();
  await expect(page.locator('.ll-ipa-symbol-approval')).toHaveCount(0);
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Approved symbol mapping.');

  const actions = await page.evaluate(() => {
    return window.__llSymbolApprovalMock.postCalls.map(function (call) {
      return call.data.action;
    });
  });

  expect(actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings',
    'll_tools_approve_ipa_keyboard_symbol_mapping',
    'll_tools_search_ipa_keyboard_recordings'
  ]);
});
