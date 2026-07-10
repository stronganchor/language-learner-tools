const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const adminSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/content-lesson-admin.js'),
  'utf8'
);

test('content lesson admin searches and pages options without losing selections', async ({ page }) => {
  const calls = [];

  await page.route('https://example.test/**', async (route) => {
    const request = route.request();
    if (!request.url().includes('/wp-admin/admin-ajax.php')) {
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><body></body></html>' });
      return;
    }

    const params = new URLSearchParams(request.postData() || '');
    const kind = params.get('kind') || '';
    const wordsetId = params.get('wordset_id') || '';
    const search = params.get('search') || '';
    const offset = Number(params.get('offset') || '0');
    calls.push({ kind, wordsetId, search, offset });

    let rows = [];
    let hasMore = false;
    let nextOffset = offset;
    if (kind === 'categories') {
      if (search === 'needle') {
        rows = [{ id: 20, label: 'Mapped selected', source_id: 10 }, { id: 23, label: 'Needle result', source_id: 23 }];
        nextOffset = 1;
      } else if (offset === 0) {
        rows = [{ id: 20, label: 'Mapped selected', source_id: 10 }, { id: 21, label: 'Second category', source_id: 21 }];
        hasMore = true;
        nextOffset = 2;
      } else {
        rows = [{ id: 22, label: 'Third category', source_id: 22 }];
        nextOffset = 3;
      }
    } else if (kind === 'prereq_categories') {
      rows = [{ id: 31, label: 'Prerequisite category', source_id: 31 }];
      nextOffset = 1;
    } else if (kind === 'prereq_lessons') {
      rows = [{ id: 41, label: 'Earlier content lesson' }];
      nextOffset = 1;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { rows, has_more: hasMore, next_offset: nextOffset, limit: 40 } })
    });
  });

  await page.goto('https://example.test/');
  await page.setContent(`
    <select id="ll-content-lesson-wordset">
      <option value="1" selected>First wordset</option>
      <option value="2">Second wordset</option>
    </select>
    <select id="ll-content-lesson-categories" multiple>
      <option value="10" data-ll-category-source-id="10" selected>Original selected</option>
      <option value="11">Original second</option>
    </select>
    <select id="ll-content-lesson-prereq-categories" multiple></select>
    <select id="ll-content-lesson-prereq-lessons" multiple></select>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llContentLessonAdminData = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      currentLessonId: 99,
      rowsByWordset: { 1: [{ id: 10, label: 'Original selected', source_id: 10 }, { id: 11, label: 'Original second', source_id: 11 }] },
      prereqRowsByWordset: { 1: [] },
      prereqLessonRowsByWordset: { 1: [] },
      pageStateByKind: {
        categories: { 1: { has_more: false, next_offset: 2, limit: 40 } },
        prereq_categories: { 1: { has_more: false, next_offset: 0, limit: 40 } },
        prereq_lessons: { 1: { has_more: false, next_offset: 0, limit: 40 } }
      },
      strings: { search: 'Search', loadMore: 'Load more', loading: 'Loading...', loadFailed: 'Failed' }
    };
  });
  await page.addScriptTag({ content: adminSource });

  await expect(page.locator('.ll-content-lesson-option-controls')).toHaveCount(3);
  await page.locator('#ll-content-lesson-wordset').selectOption('2');
  await expect.poll(() => calls.filter((call) => call.wordsetId === '2').length).toBe(3);
  await expect(page.locator('#ll-content-lesson-categories option')).toHaveCount(2);
  await expect(page.locator('#ll-content-lesson-categories')).toHaveValues(['20']);

  const categoryControls = page.locator('#ll-content-lesson-categories + .ll-content-lesson-option-controls');
  await expect(categoryControls.getByRole('button', { name: 'Load more' })).toBeVisible();
  await categoryControls.getByRole('button', { name: 'Load more' }).click();
  await expect(page.locator('#ll-content-lesson-categories option')).toHaveCount(3);
  await expect(page.locator('#ll-content-lesson-categories')).toHaveValues(['20']);
  expect(calls.some((call) => call.kind === 'categories' && call.offset === 2)).toBe(true);

  await categoryControls.getByRole('searchbox').fill('needle');
  await categoryControls.getByRole('button', { name: 'Search' }).click();
  await expect(page.locator('#ll-content-lesson-categories option')).toHaveCount(2);
  await expect(page.locator('#ll-content-lesson-categories')).toHaveValues(['20']);
  await expect(page.locator('#ll-content-lesson-categories option[value="23"]')).toHaveText('Needle result');
  expect(calls.some((call) => call.kind === 'categories' && call.search === 'needle' && call.offset === 0)).toBe(true);
});
