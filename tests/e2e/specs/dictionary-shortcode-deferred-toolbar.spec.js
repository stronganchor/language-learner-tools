const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const dictionaryScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/dictionary-shortcode.js'),
  'utf8'
);
const dictionaryCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/dictionary-shortcode.css'),
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
      data-ll-dictionary-toolbar-deferred="1"
      data-ll-dictionary-has-explicit-scope="0">
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
          <div class="ll-dictionary__toolbar-panel ll-dictionary__toolbar-panel--deferred" data-ll-dictionary-toolbar-panel></div>
        </form>
      </div>
      <div class="ll-dictionary__browse-results" data-ll-dictionary-results></div>
    </section>
  `;
}

async function mountDictionaryHarness(page, options = {}) {
  await page.setContent(buildMarkup(), { waitUntil: 'domcontentloaded' });
  await page.addStyleTag({ content: dictionaryCssSource });
  await page.evaluate((config) => {
    if (config && config.trackScroll) {
      const initialScrollTop = Number(config.scrollY || 0);
      Object.defineProperty(window, 'scrollY', {
        configurable: true,
        writable: true,
        value: initialScrollTop
      });
      Object.defineProperty(window, 'pageYOffset', {
        configurable: true,
        writable: true,
        value: initialScrollTop
      });
      if (typeof config.viewportHeight === 'number') {
        Object.defineProperty(window, 'innerHeight', {
          configurable: true,
          writable: true,
          value: config.viewportHeight
        });
      }

      window.__scrollCalls = [];
      window.scrollTo = (value, legacyTop) => {
        const payload = typeof value === 'object' && value !== null
          ? value
          : { top: Number(legacyTop || 0), left: Number(value || 0) };
        window.__scrollCalls.push(payload);

        if (typeof payload.top === 'number') {
          window.scrollY = payload.top;
          window.pageYOffset = payload.top;
        }
      };
    }

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
      minChars: 1,
      debounceMs: 80,
      loadingCards: 2,
      cacheSize: 0,
      loadingLabel: 'Loading dictionary results...',
      detailLoadingLabel: 'Loading dictionary entry...',
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
                  <div class="ll-dictionary__filter-group-label">Search settings</div>
                  <div class="ll-dictionary__filters" aria-label="Search settings">
                    <details class="ll-dictionary__filter-menu" data-ll-dictionary-filter-menu>
                      <summary id="ll-dictionary-filter-scope-summary" class="ll-dictionary__filter-summary">
                        <span class="ll-dictionary__filter-heading">Search in languages</span>
                        <span
                          class="ll-dictionary__filter-current"
                          data-ll-dictionary-filter-summary
                          data-summary-all="All languages"
                          data-summary-selected="%d selected"
                        >All languages</span>
                      </summary>
                      <div class="ll-dictionary__filter-popover" role="group" aria-labelledby="ll-dictionary-filter-scope-summary">
                        <label class="ll-dictionary__filter-option" for="ll-dictionary-scope-headword">
                          <input type="checkbox" id="ll-dictionary-scope-headword" class="ll-dictionary__filter-checkbox" name="ll_dictionary_scope[]" value="headword" checked>
                          <span class="ll-dictionary__filter-option-text">
                            <span class="ll-dictionary__filter-option-label">Zazaki</span>
                          </span>
                        </label>
                        <label class="ll-dictionary__filter-option" for="ll-dictionary-scope-tr">
                          <input type="checkbox" id="ll-dictionary-scope-tr" class="ll-dictionary__filter-checkbox" name="ll_dictionary_scope[]" value="tr" checked>
                          <span class="ll-dictionary__filter-option-text">
                            <span class="ll-dictionary__filter-option-label">Turkish</span>
                          </span>
                        </label>
                      </div>
                    </details>
                    <details class="ll-dictionary__filter-menu" data-ll-dictionary-filter-menu>
                      <summary id="ll-dictionary-filter-source-summary" class="ll-dictionary__filter-summary">
                        <span class="ll-dictionary__filter-heading">Source dictionaries</span>
                        <span
                          class="ll-dictionary__filter-current"
                          data-ll-dictionary-filter-summary
                          data-summary-all="All sources"
                          data-summary-selected="%d selected"
                        >All sources</span>
                      </summary>
                      <div class="ll-dictionary__filter-popover" role="group" aria-labelledby="ll-dictionary-filter-source-summary">
                        <label class="ll-dictionary__filter-option" for="ll-dictionary-source-dezd">
                          <input type="checkbox" id="ll-dictionary-source-dezd" class="ll-dictionary__filter-checkbox" name="ll_dictionary_source[]" value="dezd" checked>
                          <span class="ll-dictionary__filter-option-text">
                            <span class="ll-dictionary__filter-option-label">DEZD</span>
                            <span class="ll-dictionary__filter-option-note">Dialect unspecified</span>
                          </span>
                        </label>
                        <label class="ll-dictionary__filter-option" for="ll-dictionary-source-harun">
                          <input type="checkbox" id="ll-dictionary-source-harun" class="ll-dictionary__filter-checkbox" name="ll_dictionary_source[]" value="harun-turgut" checked>
                          <span class="ll-dictionary__filter-option-text">
                            <span class="ll-dictionary__filter-option-label">Harun Turgut</span>
                            <span class="ll-dictionary__filter-option-note">Palu - Bingol</span>
                          </span>
                        </label>
                        <label class="ll-dictionary__filter-option" for="ll-dictionary-source-hayig">
                          <input type="checkbox" id="ll-dictionary-source-hayig" class="ll-dictionary__filter-checkbox" name="ll_dictionary_source[]" value="hayig-werner" checked>
                          <span class="ll-dictionary__filter-option-text">
                            <span class="ll-dictionary__filter-option-label">Hayıg/Werner</span>
                            <span class="ll-dictionary__filter-option-note">Çermik</span>
                          </span>
                        </label>
                      </div>
                    </details>
                  </div>
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
        if (config && Number(config.liveSearchDelayMs || 0) > 0) {
          await new Promise((resolve) => {
            setTimeout(resolve, Number(config.liveSearchDelayMs));
          });
        }

        const query = requestData.ll_dictionary_q || '';
        return {
          ok: true,
          json: async () => ({
            success: true,
            data: {
              html: `
                <article class="ll-dictionary__entry">
                  Query:${query}; Scope:${requestData.ll_dictionary_scope || 'all'}; Source:${requestData.ll_dictionary_source || 'all'}; Letter:${requestData.ll_dictionary_letter || ''}
                  <a
                    class="ll-dictionary__details-link"
                    href="https://example.com/dictionary/?ll_dictionary_entry=77"
                    data-ll-dictionary-detail-link
                    data-entry-id="77"
                  >View details</a>
                </article>
              `,
              has_active_query: true,
              url: `https://example.com/dictionary/?ll_dictionary_q=${encodeURIComponent(query)}`
            }
          })
        };
      }

      if (requestData.action === 'll_tools_dictionary_entry_detail') {
        return {
          ok: true,
          json: async () => ({
            success: true,
            data: {
              html: `
                <article class="ll-dictionary__detail" data-ll-dictionary-detail>
                  <div class="ll-dictionary__detail-top">
                    <a class="ll-dictionary__back" href="https://example.com/dictionary/?ll_dictionary_q=${encodeURIComponent(requestData.ll_dictionary_q || '')}" data-ll-dictionary-back>Back to dictionary</a>
                  </div>
                  <h3>Detail:${requestData.entry_id}</h3>
                </article>
              `,
              url: `https://example.com/dictionary/?ll_dictionary_entry=${encodeURIComponent(requestData.entry_id || '')}`,
              back_url: `https://example.com/dictionary/?ll_dictionary_q=${encodeURIComponent(requestData.ll_dictionary_q || '')}`,
              entry_id: Number(requestData.entry_id || 0)
            }
          })
        };
      }

      return {
        ok: false,
        json: async () => ({ success: false })
      };
    };

    if (config && typeof config.searchRectTop === 'number') {
      const searchInput = document.querySelector('#ll-dictionary-search');
      searchInput.getBoundingClientRect = () => ({
        x: 0,
        y: config.searchRectTop,
        width: 320,
        height: 48,
        top: config.searchRectTop,
        right: 320,
        bottom: config.searchRectTop + 48,
        left: 0,
        toJSON() {
          return this;
        }
      });
    }
  }, options);

  await page.addScriptTag({ content: dictionaryScriptSource });
}

test('loads deferred dictionary filters on first interaction only once', async ({ page }) => {
  await mountDictionaryHarness(page);

  await expect(page.locator('[data-ll-dictionary-filter-menu]')).toHaveCount(0);
  await expect(page.locator('.ll-dictionary__toolbar')).not.toHaveClass(/is-scope-visible/);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('.ll-dictionary__toolbar')).toHaveClass(/is-scope-visible/);
  await expect(page.locator('[data-ll-dictionary-filter-menu]')).toHaveCount(2);
  await expect(page.locator('input[name="ll_dictionary_pos[]"]')).toHaveCount(0);

  await page.locator('#ll-dictionary-search').fill('ap');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:ap; Scope:all; Source:all');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const bootstrapCalls = fetchCalls.filter((call) => call.action === 'll_tools_dictionary_toolbar_bootstrap');

  expect(bootstrapCalls).toHaveLength(1);
});

test('live search accepts one-character queries', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').fill('a');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:a; Scope:all; Source:all');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const lastSearchCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_live_search');

  expect(lastSearchCall).toMatchObject({
    ll_dictionary_q: 'a'
  });
});

test('shows the loading skeleton immediately while live search is pending', async ({ page }) => {
  await mountDictionaryHarness(page, { liveSearchDelayMs: 600 });

  await page.locator('#ll-dictionary-search').fill('ap');

  await expect(page.locator('.ll-dictionary__loading')).toBeVisible();
  await expect(page.locator('[data-ll-dictionary-results]')).toHaveAttribute('aria-busy', 'true');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:ap; Scope:all; Source:all');
  await expect(page.locator('[data-ll-dictionary-results]')).not.toHaveAttribute('aria-busy', 'true');
});

test('opens entry details in place and restores the live search results', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').fill('apa');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; Source:all');

  await page.locator('[data-ll-dictionary-detail-link]').click();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Detail:77');
  await expect(page.locator('.ll-dictionary__toolbar')).toBeHidden();

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const detailCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_entry_detail');

  expect(detailCall).toMatchObject({
    entry_id: '77',
    ll_dictionary_q: 'apa'
  });

  await page.locator('[data-ll-dictionary-back]').click();
  await expect(page.locator('.ll-dictionary__toolbar')).toBeVisible();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; Source:all');
});

test('live search still reacts to deferred filter controls after bootstrap', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('[data-ll-dictionary-filter-menu]')).toHaveCount(2);

  await page.locator('#ll-dictionary-search').fill('apa');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; Source:all');

  await page.locator('summary#ll-dictionary-filter-source-summary').click();
  await page.locator('#ll-dictionary-source-dezd').uncheck();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; Source:harun-turgut,hayig-werner');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const lastSearchCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_live_search');

  expect(lastSearchCall).toMatchObject({
    ll_dictionary_q: 'apa',
    ll_dictionary_scope: 'all',
    ll_dictionary_source: 'harun-turgut,hayig-werner'
  });
});

test('letter browse preserves selected source filters', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('[data-ll-dictionary-filter-menu]')).toHaveCount(2);

  await page.locator('#ll-dictionary-search').fill('apa');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; Source:all');

  await page.locator('summary#ll-dictionary-filter-source-summary').click();
  await page.locator('#ll-dictionary-source-dezd').uncheck();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Source:harun-turgut,hayig-werner');

  await page.locator('.ll-dictionary__letters a', { hasText: 'B' }).click();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:; Scope:all; Source:harun-turgut,hayig-werner; Letter:B');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const lastSearchCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_live_search');

  expect(lastSearchCall).toMatchObject({
    ll_dictionary_q: '',
    ll_dictionary_letter: 'B',
    ll_dictionary_scope: 'all',
    ll_dictionary_source: 'harun-turgut,hayig-werner'
  });
});

test('live search sends a narrowed checkbox scope when one scope is unchecked', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('[data-ll-dictionary-filter-menu]')).toHaveCount(2);

  await page.locator('#ll-dictionary-search').fill('apa');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:all; Source:all');

  await page.locator('summary#ll-dictionary-filter-scope-summary').click();
  await page.locator('#ll-dictionary-scope-tr').uncheck();
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:apa; Scope:headword; Source:all');

  const fetchCalls = await page.evaluate(() => window.__dictionaryFetchCalls);
  const lastSearchCall = [...fetchCalls].reverse().find((call) => call.action === 'll_tools_dictionary_live_search');

  expect(lastSearchCall).toMatchObject({
    ll_dictionary_q: 'apa',
    ll_dictionary_scope: 'headword'
  });
});

test('restores saved checkbox scope preferences on load when the URL has no explicit scope', async ({ page }) => {
  await mountDictionaryHarness(page, { storedScope: 'headword' });

  await page.locator('#ll-dictionary-search').focus();

  await expect(page.locator('#ll-dictionary-scope-headword')).toBeChecked();
  await expect(page.locator('#ll-dictionary-scope-tr')).not.toBeChecked();
});

test('filter menu checkbox changes keep the dropdown open', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await page.locator('summary#ll-dictionary-filter-scope-summary').click();

  await expect(page.locator('#ll-dictionary-scope-tr')).toBeChecked();
  await expect(page.locator('[data-ll-dictionary-filter-menu]').first()).toHaveAttribute('open', '');

  await page.locator('#ll-dictionary-scope-tr').uncheck();
  await expect(page.locator('#ll-dictionary-scope-tr')).not.toBeChecked();
  await expect(page.locator('[data-ll-dictionary-filter-menu]').first()).toHaveAttribute('open', '');
});

test('scope changes keep the alphabet panel open', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();
  await expect(page.locator('.ll-dictionary__letters')).toBeVisible();

  await page.locator('summary#ll-dictionary-filter-scope-summary').click();
  await page.locator('#ll-dictionary-scope-tr').uncheck();
  await page.locator('body').click({ position: { x: 4, y: 4 } });

  await expect(page.locator('.ll-dictionary__toolbar')).toHaveClass(/is-expanded/);
  await expect(page.locator('.ll-dictionary__letters')).toBeVisible();
  await expect(page.locator('[data-ll-dictionary-filter-menu]').first()).not.toHaveAttribute('open', '');
});

test('filter dropdowns close on outside click and Escape', async ({ page }) => {
  await mountDictionaryHarness(page);

  await page.locator('#ll-dictionary-search').focus();

  const languageMenu = page.locator('[data-ll-dictionary-filter-menu]').first();
  const sourceMenu = page.locator('[data-ll-dictionary-filter-menu]').last();

  await page.locator('summary#ll-dictionary-filter-scope-summary').click();
  await expect(languageMenu).toHaveAttribute('open', '');

  await page.locator('body').click({ position: { x: 4, y: 4 } });
  await expect(languageMenu).not.toHaveAttribute('open', '');
  await expect(page.locator('.ll-dictionary__toolbar')).toHaveClass(/is-expanded/);

  await page.locator('summary#ll-dictionary-filter-scope-summary').click();
  await expect(languageMenu).toHaveAttribute('open', '');

  await page.locator('summary#ll-dictionary-filter-source-summary').click();
  await expect(languageMenu).not.toHaveAttribute('open', '');
  await expect(sourceMenu).toHaveAttribute('open', '');

  await page.keyboard.press('Escape');
  await expect(sourceMenu).not.toHaveAttribute('open', '');
});

test('scrolls down once when live search starts so the search field stays visible', async ({ page }) => {
  await mountDictionaryHarness(page, {
    trackScroll: true,
    scrollY: 40,
    searchRectTop: 420,
    viewportHeight: 900
  });

  await page.locator('#ll-dictionary-search').fill('ap');
  await expect(page.locator('[data-ll-dictionary-results]')).toContainText('Query:ap; Scope:all; Source:all');
  await page.waitForFunction(() => Array.isArray(window.__scrollCalls) && window.__scrollCalls.length > 0);

  const scrollCalls = await page.evaluate(() => window.__scrollCalls);
  expect(scrollCalls).toHaveLength(1);
  expect(scrollCalls[0]).toMatchObject({
    top: 364,
    behavior: 'smooth'
  });
});
