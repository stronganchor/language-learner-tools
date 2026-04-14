const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const ipaKeyboardAdminSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/ipa-keyboard-admin.js'),
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
  await page.goto('/', { waitUntil: 'domcontentloaded' });
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
    function buildRecording(recordingId, wordText, needsReview) {
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
        buildRecording(101, 'Alpha', true),
        buildRecording(202, 'Beta', true)
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

            return Object.assign({}, recording, {
              needs_review: needsReview
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

  await rows.nth(0).locator('.ll-ipa-search-review .ll-ipa-review-toggle').click();

  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toHaveAttribute('data-recording-id', '101');
  await expect(rows.nth(0)).toHaveAttribute('data-needs-review', '0');
  await expect(rows.nth(0).locator('.ll-ipa-search-review')).toHaveCount(0);
  await expect(rows.nth(0).locator('.ll-ipa-search-action-toggle')).toHaveCount(1);
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
