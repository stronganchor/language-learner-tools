const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const filterSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/dictionary-admin-filters.js'),
  'utf8'
);

test('dictionary admin filter selects bounded search results and clears stale ids', async ({ page }) => {
  await page.route('https://example.test/**', async (route) => {
    if (!route.request().url().includes('/wp-admin/admin-ajax.php')) {
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><body></body></html>' });
      return;
    }
    const params = new URLSearchParams(route.request().postData() || '');
    expect(params.get('action')).toBe('ll_tools_dictionary_admin_search_entries');
    expect(params.get('q')).toBe('alpha');
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { entries: [{ id: 9, label: 'Alpha entry' }] } })
    });
  });

  await page.goto('https://example.test/');
  await page.setContent(`
    <span data-ll-dictionary-admin-entry-filter>
      <select data-ll-dictionary-filter-mode>
        <option value="all" selected>All</option>
        <option value="none">None</option>
        <option value="entry">Specific</option>
      </select>
      <input type="hidden" name="dictionary_entry_id" value="" data-ll-dictionary-filter-id>
      <input type="search" data-ll-dictionary-filter-search>
      <span data-ll-dictionary-filter-spinner></span>
    </span>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llDictionaryAdminFilters = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'test-nonce'
    };
    window.jQuery.fn.autocomplete = function (arg) {
      if (typeof arg === 'object') {
        this.data('llAutocompleteOptions', arg);
      }
      return this;
    };
  });
  await page.addScriptTag({ content: filterSource });

  const mode = page.locator('[data-ll-dictionary-filter-mode]');
  const id = page.locator('[data-ll-dictionary-filter-id]');
  const search = page.locator('[data-ll-dictionary-filter-search]');
  await expect(search).toBeDisabled();

  await mode.selectOption('none');
  await expect(id).toHaveValue('__none__');
  await mode.selectOption('entry');
  await expect(search).toBeEnabled();

  const results = await page.evaluate(async () => {
    const $input = window.jQuery('[data-ll-dictionary-filter-search]');
    const options = $input.data('llAutocompleteOptions');
    return await new Promise((resolve) => options.source({ term: 'alpha' }, resolve));
  });
  expect(results).toEqual([{ id: 9, label: 'Alpha entry', value: 'Alpha entry' }]);

  await page.evaluate((item) => {
    const $input = window.jQuery('[data-ll-dictionary-filter-search]');
    const options = $input.data('llAutocompleteOptions');
    options.select({ preventDefault() {} }, { item });
  }, results[0]);
  await expect(id).toHaveValue('9');
  await expect(search).toHaveValue('Alpha entry');

  await search.fill('Changed text');
  await page.evaluate(() => {
    const $input = window.jQuery('[data-ll-dictionary-filter-search]');
    const options = $input.data('llAutocompleteOptions');
    options.change({}, {});
  });
  await expect(id).toHaveValue('');
});
