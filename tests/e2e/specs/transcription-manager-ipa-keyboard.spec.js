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
      illegalSymbols: [],
      postCalls: []
    };

    function buildTranscription() {
      const illegal = window.__llTranscriptionKeyboardMock.illegalSymbols.slice();
      const filterSymbols = (symbols) => symbols.filter((symbol) => !illegal.some((entry) => symbol.includes(entry)));

      return {
        mode: 'ipa',
        symbols_column_label: 'Pronunciation',
        common_chars: [],
        common_chars_label: '',
        modifier_chars: ['ʰ', 'ʲ', 'ʷ', 'ː', '\u0325', '\u032A', '\u0306', '\u0361'],
        modifier_chars_label: 'Diacritics and signs',
        wordset_chars_label: 'Wordset symbols',
        keyboard_symbols: filterSymbols(['qʰ', 'dʲ', 'tʷ', 'aː', 't͡ʃ', 'ɛ', 'ʃ', 'ɬ', 'ʔ']),
        keyboard_groups: [
          {
            key: 'signs',
            label: 'Diacritics and signs',
            symbols: filterSymbols(['ʰ', 'ʲ', 'ʷ', 'ː', '\u0325', '\u032A', '\u0306', '\u0361', 'ʔ'])
          },
          {
            key: 'affricates',
            label: 'Affricates and tie bars',
            symbols: filterSymbols(['t͡ʃ'])
          },
          {
            key: 'vowels',
            label: 'Vowels',
            symbols: filterSymbols(['ɛ'])
          },
          {
            key: 'consonants',
            label: 'Consonants',
            symbols: filterSymbols(['ʃ'])
          },
          {
            key: 'rare',
            label: 'Rare symbols',
            symbols: filterSymbols(['ɬ'])
          }
        ],
        symbol_details: {
          'ʰ': { display: 'ʰ', label: 'aspiration modifier' },
          'ʲ': { display: 'ʲ', label: 'palatalization modifier' },
          'ʷ': { display: 'ʷ', label: 'labialization modifier' },
          'ː': { display: 'ː', label: 'long sound marker' },
          '\u0325': { display: '◌\u0325', label: 'devoicing diacritic' },
          '\u032A': { display: '◌\u032A', label: 'dental diacritic' },
          '\u0306': { display: '◌\u0306', label: 'extra-short diacritic' },
          '\u0361': { display: '◌\u0361◌', label: 'tie bar' },
          't͡ʃ': { display: 't͡ʃ', label: 'voiceless postalveolar affricate' },
          'ɛ': { display: 'ɛ', label: 'open-mid front unrounded vowel' },
          'ʃ': { display: 'ʃ', label: 'voiceless postalveolar fricative' },
          'ɬ': { display: 'ɬ', label: 'voiceless alveolar lateral fricative' },
          'ʔ': { display: 'ʔ', label: 'glottal stop' }
        },
        illegal_symbols: window.__llTranscriptionKeyboardMock.illegalSymbols.slice(),
        keyboard_aria_label: 'IPA symbols'
      };
    }

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
              transcription: buildTranscription(),
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

        if (requestData.action === 'll_tools_flag_ipa_keyboard_illegal_symbol') {
          const symbol = String(requestData.symbol || '');
          if (symbol && !window.__llTranscriptionKeyboardMock.illegalSymbols.includes(symbol)) {
            window.__llTranscriptionKeyboardMock.illegalSymbols.push(symbol);
          }
          deferred.resolve({
            success: true,
            data: {
              symbol,
              illegal_symbols: window.__llTranscriptionKeyboardMock.illegalSymbols.slice(),
              rescanned_count: 1,
              transcription: buildTranscription(),
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
  await expect(page.locator('.ll-ipa-inline-keyboard-label').first()).toHaveText('Diacritics and signs');
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ʰ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ʲ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ʷ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ː"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[title="devoicing diacritic"]')).toHaveText('◌\u0325');
  await expect(page.locator('.ll-ipa-inline-key[title="dental diacritic"]')).toHaveText('◌\u032A');
  await expect(page.locator('.ll-ipa-inline-key[title="extra-short diacritic"]')).toHaveText('◌\u0306');
  await expect(page.locator('.ll-ipa-inline-key[title="tie bar"]')).toHaveText('◌\u0361◌');
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ʔ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-keyboard-label', { hasText: 'Common IPA symbols' })).toHaveCount(0);
  await expect(page.locator('.ll-ipa-inline-keyboard-label', { hasText: 'Affricates and tie bars' })).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-keyboard-label', { hasText: 'Vowels' })).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-keyboard-label', { hasText: 'Consonants' })).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-keyboard-label', { hasText: 'Rare symbols' })).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="qʰ"]')).toHaveCount(0);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="dʲ"]')).toHaveCount(0);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="tʷ"]')).toHaveCount(0);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="aː"]')).toHaveCount(0);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ɬ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="t͡ʃ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ɛ"]')).toHaveCount(1);
  await expect(page.locator('.ll-ipa-inline-key[data-ipa-char="ʃ"]')).toHaveAttribute('title', 'voiceless postalveolar fricative');

  await ipaInput.type('ı');
  await expect(ipaInput).toHaveValue('teɪ');

  await page.locator('.ll-ipa-inline-key[data-ipa-char="ʰ"]').click();
  await expect(ipaInput).toHaveValue('teɪʰ');

  page.once('dialog', async (dialog) => {
    await dialog.accept();
  });
  await page.locator('.ll-ipa-inline-key[data-ipa-char="ʃ"]').click({ button: 'right' });
  await expect(page.locator('.ll-ipa-symbol-menu-action')).toHaveText('Flag as illegal symbol');
  await page.locator('.ll-ipa-symbol-menu-action').click();
  await expect(page.locator('#ll-ipa-admin-status')).toHaveText('Symbol marked illegal and checks rescanned.');

  const actions = await page.evaluate(() => {
    return window.__llTranscriptionKeyboardMock.postCalls.map(function (call) {
      return call.action;
    });
  });

  expect(actions).toEqual([
    'll_tools_search_ipa_keyboard_recordings',
    'll_tools_flag_ipa_keyboard_illegal_symbol',
    'll_tools_search_ipa_keyboard_recordings'
  ]);
});
