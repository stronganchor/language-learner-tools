const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const ipaKeyboardAdminSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/ipa-keyboard-admin.js'),
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
            url: ''
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
    window.__llTranscriptionManagerMock.holdReviewStateRequests = true;
  });

  const reviewTextToggle = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-review-toggle');
  const reviewTextStatus = rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-review-status');
  const reviewIpaToggle = rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-review-toggle');
  const reviewIpaStatus = rows.nth(0).locator('.ll-ipa-search-ipa-cell .ll-ipa-search-review-status');
  await reviewTextToggle.click();

  await expect(reviewTextToggle).toBeDisabled();
  await expect(reviewTextToggle).toHaveClass(/is-saving/);
  await expect(reviewTextToggle).toHaveAttribute('aria-busy', 'true');
  await expect(reviewTextToggle).toBeHidden();
  await expect(reviewTextStatus).toHaveClass(/is-saving/);
  await expect(reviewTextStatus).toHaveAttribute('aria-busy', 'true');
  await expect(reviewTextStatus.locator('.ll-ipa-search-review-status-label')).toHaveText('Saving...');
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
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-note')).toHaveCount(0);
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

test('recording rows open the detached word editor and refresh after modal changes', async ({ page }) => {
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
    'll_tools_search_ipa_keyboard_recordings'
  ]);
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

  await row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip').click();
  await expect(textInput).toHaveValue('alpha');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-mismatch-mark')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saving...');
  await expect.poll(async () => page.evaluate(() => {
    return window.__llOrthographySuggestionMock.pendingUpdateRequests.length;
  })).toBe(1);

  await page.evaluate(() => {
    const pending = window.__llOrthographySuggestionMock.pendingUpdateRequests.shift();
    if (pending) {
      pending.finish();
    }
  });

  await expect(textInput).toHaveValue('alpha');
  await expect(row.locator('.ll-ipa-search-suggestion-chip')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-save-state')).toHaveText('Saved.');

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

  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-mismatch-mark')).toHaveText("'");
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-mismatch-mark')).toHaveText('i');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-search-suggestion-chip')).toHaveText('Change to: Ez nızûnû');
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-search-suggestion-chip')).toHaveText([
    'Change to: ʔɛz nɨzunu',
    'Change to: ʔɛz nɪzunu'
  ]);

  await row.locator('.ll-ipa-search-text-input').fill('Ez nizûnû');
  await page.locator('#ll-ipa-search-btn').focus();

  await expect.poll(async () => page.evaluate(() => {
    return window.__llOrthographyInlineMock.postCalls
      .filter(call => call.data.action === 'll_tools_update_ipa_keyboard_recording').length;
  })).toBe(1);
  await expect(row.locator('.ll-ipa-search-text-input')).toHaveValue('Ez nizûnû');
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-mismatch-mark')).toHaveText('i');
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
  await expect(row.locator('.ll-ipa-search-text-cell .ll-ipa-mismatch-mark')).toHaveCount(0);
  await expect(row.locator('.ll-ipa-search-ipa-cell .ll-ipa-mismatch-mark')).toHaveCount(0);
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
