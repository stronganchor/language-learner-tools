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
      postCalls: []
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

  await rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-review-toggle').click();

  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toHaveAttribute('data-recording-id', '101');
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '0');
  await expect(rows.nth(0).locator('.ll-ipa-search-review')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-action-toggle')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-toggle')).toHaveCount(2);
  await expect(rows.nth(0).locator('.ll-ipa-search-review-status.is-needs-review')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-review-status.is-reviewed')).toHaveCount(2);
  await expect(rows.nth(0).locator('.ll-ipa-search-text-cell .ll-ipa-search-field-review-toggle')).toHaveText('Mark as needing review');
  await expect(rows.nth(0).locator('.ll-ipa-search-field-review-note')).toHaveCount(0);
  await expect(rows.nth(1)).toHaveAttribute('data-recording-id', '202');
  await expect(rows.nth(1)).toHaveAttribute('data-needs-review', '1');
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Reviewed.');

  const actions = await page.evaluate(() => {
    return window.__llTranscriptionManagerMock.postCalls.map(function (call) {
      return call.data.action;
    });
  });

  expect(actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings',
    'll_tools_set_ipa_keyboard_transcription_review_state'
  ]);
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
