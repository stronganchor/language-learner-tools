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
        <option value="7" selected>IPA Wordset</option>
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

test('clicking an IPA transcription field opens the inline keyboard and inserts symbols without autosaving', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.setContent(buildMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate(() => {
    function clone(value) {
      return JSON.parse(JSON.stringify(value));
    }

    const baseRecording = {
      recording_id: 101,
      word_id: 55,
      word_text: 'Test word',
      word_translation: 'Test translation',
      word_edit_link: '',
      recording_text: 'test',
      recording_translation: '',
      recording_ipa: 'te',
      categories: [],
      issues: [],
      ignored_issues: [],
      issue_count: 0,
      ignored_issue_count: 0,
      needs_review: false,
      image: {},
      audio_url: '',
      audio_label: 'Play recording'
    };

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

    window.__llTranscriptionKeyboardMock = {
      postCalls: []
    };

    const $ = window.jQuery;
    $.post = function (url, data) {
      const deferred = $.Deferred();
      const requestData = Object.assign({}, data);
      window.__llTranscriptionKeyboardMock.postCalls.push({
        url: String(url || ''),
        action: String(requestData.action || '')
      });

      window.setTimeout(function () {
        if (requestData.action === 'll_tools_search_ipa_keyboard_recordings') {
          deferred.resolve({
            success: true,
            data: {
              wordset: {
                id: 7,
                name: 'IPA Wordset'
              },
              transcription: {
                mode: 'ipa',
                symbols_column_label: 'Pronunciation',
                common_chars: ['ʃ'],
                common_chars_label: 'Common IPA symbols',
                wordset_chars_label: 'Wordset IPA symbols',
                keyboard_symbols: ['ɬ'],
                keyboard_aria_label: 'IPA symbols'
              },
              results: [clone(baseRecording)],
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

        deferred.reject(new Error('Unexpected action: ' + String(requestData.action || '')));
      }, 0);

      return deferred.promise();
    };
  });

  await page.addScriptTag({ content: ipaKeyboardAdminSource });

  const ipaInput = page.locator('.ll-ipa-search-ipa-input').first();
  await expect(ipaInput).toHaveValue('te');

  await ipaInput.click();
  await expect(page.locator('[data-ll-ipa-inline-keyboard]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ɬ"]')).toHaveCount(1);

  await page.locator('.ll-ipa-inline-key[data-ipa-char="ɬ"]').click();
  await expect(ipaInput).toHaveValue('teɬ');

  const actions = await page.evaluate(() => {
    return window.__llTranscriptionKeyboardMock.postCalls.map(function (call) {
      return call.action;
    });
  });

  expect(actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings'
  ]);
});
