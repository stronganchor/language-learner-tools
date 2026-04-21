const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const dictionaryScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/dictionary-shortcode.js'),
  'utf8'
);

function buildMarkup() {
  return `
    <section
      class="ll-dictionary"
      data-ll-dictionary-root
      data-wordset-id="55"
      data-per-page="20"
      data-sense-limit="3"
      data-linked-word-limit="4"
      data-gloss-lang=""
      data-base-url="https://example.com/dictionary/"
      data-ll-dictionary-toolbar-deferred="1">
      <div class="ll-dictionary__toolbar is-collapsed">
        <form class="ll-dictionary__form" method="get" action="https://example.com/dictionary/" data-ll-dictionary-form>
          <input type="hidden" name="ll_dictionary_letter" value="">
          <div class="ll-dictionary__search-row">
            <div class="ll-dictionary__field ll-dictionary__field--search">
              <label class="screen-reader-text" for="ll-dictionary-search">Search dictionary</label>
              <input
                type="search"
                id="ll-dictionary-search"
                class="ll-dictionary__input"
                name="ll_dictionary_q"
                value=""
                placeholder="Search dictionary">
            </div>
            <div class="ll-dictionary__actions ll-dictionary__actions--primary">
              <button class="ll-dictionary__button" type="submit">Search</button>
              <a class="ll-dictionary__button ll-dictionary__button--ghost" data-ll-dictionary-reset href="https://example.com/dictionary/" hidden>Reset</a>
            </div>
          </div>
          <fieldset class="ll-dictionary__scope-group" aria-labelledby="ll-dictionary-scope-group">
            <legend id="ll-dictionary-scope-group" class="screen-reader-text">Search scope</legend>
            <div class="ll-dictionary__scope-options">
              <label class="ll-dictionary__scope-option" for="ll-dictionary-scope-headword">
                <input type="checkbox" id="ll-dictionary-scope-headword" class="ll-dictionary__scope-checkbox" name="ll_dictionary_scope[]" value="headword" checked>
                <span class="ll-dictionary__scope-option-label">Zazaki</span>
              </label>
              <label class="ll-dictionary__scope-option" for="ll-dictionary-scope-tr">
                <input type="checkbox" id="ll-dictionary-scope-tr" class="ll-dictionary__scope-checkbox" name="ll_dictionary_scope[]" value="tr" checked>
                <span class="ll-dictionary__scope-option-label">Türkçe</span>
              </label>
            </div>
          </fieldset>
          <div class="ll-dictionary__toolbar-panel ll-dictionary__toolbar-panel--deferred" data-ll-dictionary-toolbar-panel></div>
        </form>
      </div>
      <div class="ll-dictionary__browse-results" data-ll-dictionary-results></div>
    </section>
  `;
}

async function mountDictionaryHarness(page, options = {}) {
  await page.setContent(buildMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate((config) => {
    const store = {};
    if (config && typeof config.storedScope === 'string' && config.storedScope) {
      store['llDictionaryScopePrefs:55'] = config.storedScope;
    }

    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      value: {
        getItem(key) {
          return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null;
        },
        setItem(key, value) {
          store[key] = String(value);
        },
        removeItem(key) {
          delete store[key];
        }
      }
    });

    window.llToolsDictionary = {
      ajaxUrl: '/fake-admin-ajax.php',
      nonce: 'test-nonce',
      minChars: 2,
      debounceMs: 80,
      loadingCards: 2,
      cacheSize: 0,
      loadingLabel: 'Loading dictionary results...',
      toolbarLoadingLabel: 'Loading dictionary filters...'
    };

    window.__dictionaryFetchCalls = [];
    window.fetch = async (_url, init = {}) => {
      const requestData = {};
      const body = init && init.body;
      if (body && typeof body.forEach === 'function') {
        body.forEach((value, key) => {
          requestData[key] = String(value);
        });
      }

      window.__dictionaryFetchCalls.push(requestData);

      if (requestData.action === 'll_tools_dictionary_toolbar_bootstrap') {
        return {
          ok: true,
          json: async () => ({
            success: true,
            data: {
              html: `
                <div class="ll-dictionary__toolbar-panel" data-ll-dictionary-toolbar-panel>
                  <div class="ll-dictionary__filters">
                    <div class="ll-dictionary__field ll-dictionary__field--select">
                      <label class="screen-reader-text" for="ll-dictionary-pos">Filter by part of speech</label>
                      <select id="ll-dictionary-pos" class="ll-dictionary__select" name="ll_dictionary_pos">
                        <option value="">All types</option>
                        <option value="noun">Noun</option>
                        <option value="verb">Verb</option>
                      </select>
                    </div>
                  </div>
                  <p class="ll-dictionary__hint">Type to search, or open the alphabet below.</p>
                  <nav class="ll-dictionary__letters" aria-label="Browse dictionary by letter">
                    <a class="ll-dictionary__letter" href="https://example.com/dictionary/?ll_dictionary_letter=A">A</a>
                    <a class="ll-dictionary__letter" href="https://example.com/dictionary/?ll_dictionary_letter=B">B</a>
                  </nav>
                </div>
              `
            }
          })
        };
      }

      if (requestData.action === 'll_tools_dictionary_live_search') {
        return {
          ok: true,
          json: async () => ({
            success: true,
            data: {
              html: `<article class="ll-dictionary__entry">Query:${requestData.ll_dictionary_q || ''}; Scope:${requestData.ll_dictionary_scope || 'all'}; POS:${requestData.ll_dictionary_pos || 'all'}</article>`,
              has_active_query: true,
              url: `https://example.com/dictionary/?ll_dictionary_q=${encodeURIComponent(requestData.ll_dictionary_q || '')}`
            }
          })
        };
      }

      return {
        ok: false,
        json: async () => ({ success: false })
      };
    };
  }, options);

  await page.addScriptTag({ content: dictionaryScriptSource });
}

test('loads deferred dictionary filters on first interaction only once', async ({ page }) => {
  await mountDictionaryHarness(page);

  await expect(page.locator('select[name="ll_dictionary_pos"]')).toHaveCount(0);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('select[name="ll_dictionary_pos"]')).toHaveCount(1);

  await page.locator('#ll-dictionary-search').fill('ap');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:ap; Scope:all; POS:all');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const bootstrapCalls = fetchCalls.filter((call) => call.action === 'll_tools_dictionary_toolbar_bootstrap');

  expect(bootstrapCalls).toHaveLength(1);
});

test('live search still reacts to deferred filter controls after bootstrap', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('select[name="ll_dictionary_pos"]')).toHaveCount(1);

  await page.locator('#ll-dictionary-search').fill('apa');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; POS:all');

  await page.locator('select[name="ll_dictionary_pos"]').selectOption('verb');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; POS:verb');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const lastSearchCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_live_search');

  expect(lastSearchCall).toMatchObject({
    ll_dictionary_q: 'apa',
    ll_dictionary_scope: 'all',
    ll_dictionary_pos: 'verb'
  });
});

test('live search sends a narrowed checkbox scope when one scope is unchecked', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('select[name="ll_dictionary_pos"]')).toHaveCount(1);

  await page.locator('#ll-dictionary-search').fill('apa');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; POS:all');

  await page.locator('#ll-dictionary-scope-tr').uncheck();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:headword; POS:all');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const lastSearchCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_live_search');

  expect(lastSearchCall).toMatchObject({
    ll_dictionary_q: 'apa',
    ll_dictionary_scope: 'headword'
  });
});

test('restores saved checkbox scope preferences on load when the URL has no explicit scope', async ({ page }) => {
  await mountDictionaryHarness(page, { storedScope: 'headword' });

  await expect(page.locator('#ll-dictionary-scope-headword')).toBeChecked();
  await expect(page.locator('#ll-dictionary-scope-tr')).not.toBeChecked();
});
