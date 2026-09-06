const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const bulkCategorySource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/bulk-category-edit.js'),
  'utf8'
);

async function loadBulkCategoryHarness(page) {
  await page.goto('about:blank');
  await page.setContent(`
    <table>
      <tbody>
        <tr><th class="check-column"><input type="checkbox" value="11" checked></th></tr>
        <tr><th class="check-column"><input type="checkbox" value="12" checked></th></tr>
        <tr><th class="check-column"><input type="checkbox" value="13"></th></tr>
        <tr id="bulk-edit" class="inline-editor" style="display:table-row">
          <td>
            <ul class="categorychecklist">
              <li><label><input type="checkbox" value="3"> First category</label></li>
              <li><label><input type="checkbox" value="4"> Second category</label></li>
            </ul>
            <button type="button" class="button save">Update</button>
          </td>
        </tr>
      </tbody>
    </table>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.__bulkCategoryRequests = [];
    window.__bulkCategoryPending = [];
    window.__bulkCategoryAbortCount = 0;
    window.inlineEditPost = {
      setBulk() {},
      edit() {}
    };
    window.llBulkEditData = {
      ajaxurl: '/wp-admin/admin-ajax.php',
      nonce: 'category-nonce',
      postType: 'words',
      actionName: 'll_words_get_common_categories',
      i18n: {
        categoryStateLoading: 'Loading categories…',
        categoryStateError: 'Categories could not be loaded. Category changes are unavailable.'
      }
    };
    window.jQuery.ajax = function (options) {
      const deferred = window.jQuery.Deferred();
      let settled = false;
      const request = deferred.promise({
        abort() {
          window.__bulkCategoryAbortCount += 1;
          // Keep the old request externally settleable to prove generation fencing.
        }
      });
      window.__bulkCategoryRequests.push(options.data);
      window.__bulkCategoryPending.push({
        resolve(payload) {
          if (settled) {
            return;
          }
          settled = true;
          deferred.resolve(payload);
        },
        reject() {
          if (settled) {
            return;
          }
          settled = true;
          deferred.reject({ status: 500 }, 'error');
        }
      });
      return request;
    };
  });
  await page.addScriptTag({ content: bulkCategorySource });
  await page.waitForFunction(() => window.inlineEditPost.setBulk.toString().includes('handleBulkEditShown'));
}

test('bulk category edit coalesces duplicate opens and ignores an older selection response', async ({ page }) => {
  await loadBulkCategoryHarness(page);

  await page.evaluate(() => {
    window.inlineEditPost.setBulk();
    document.querySelector('#bulk-edit').classList.add('is-observer-visible');
  });
  await page.waitForFunction(() => window.__bulkCategoryPending.length === 1);
  expect(await page.evaluate(() => window.__bulkCategoryRequests.length)).toBe(1);

  await page.evaluate(() => {
    document.querySelector('input[type="checkbox"][value="11"]').checked = false;
    document.querySelector('input[type="checkbox"][value="12"]').checked = false;
    document.querySelector('input[type="checkbox"][value="13"]').checked = true;
    window.inlineEditPost.setBulk();
  });
  await page.waitForFunction(() => window.__bulkCategoryPending.length === 2);

  await page.evaluate(() => {
    window.__bulkCategoryPending[1].resolve({
      success: true,
      data: { common: [4] }
    });
  });
  await expect(page.locator('.categorychecklist input[value="4"]')).toBeChecked();
  await expect(page.locator('.categorychecklist input[value="3"]')).not.toBeChecked();

  await page.evaluate(() => {
    window.__bulkCategoryPending[0].resolve({
      success: true,
      data: { common: [3] }
    });
  });
  await expect(page.locator('.categorychecklist input[value="4"]')).toBeChecked();
  await expect(page.locator('.categorychecklist input[value="3"]')).not.toBeChecked();

  await page.locator('.categorychecklist input[value="4"]').uncheck();
  await expect(page.locator('input[name="ll_bulk_categories_to_remove[]"]')).toHaveValue('4');

  const state = await page.evaluate(() => ({
    requests: window.__bulkCategoryRequests.map((request) => request.post_ids),
    abortCount: window.__bulkCategoryAbortCount
  }));
  expect(state.requests).toEqual([[11, 12], [13]]);
  expect(state.abortCount).toBe(1);
});

test('bulk category edit clears a prior selection and keeps category changes neutral when the next lookup fails', async ({ page }) => {
  await loadBulkCategoryHarness(page);

  await page.evaluate(() => window.inlineEditPost.setBulk());
  await page.waitForFunction(() => window.__bulkCategoryPending.length === 1);
  await expect(page.locator('.categorychecklist')).toHaveAttribute('aria-busy', 'true');
  await expect(page.locator('.categorychecklist input[value="3"]')).toBeDisabled();

  await page.evaluate(() => {
    window.__bulkCategoryPending[0].resolve({
      success: true,
      data: { common: [3] }
    });
  });
  await expect(page.locator('.categorychecklist input[value="3"]')).toBeChecked();
  await expect(page.locator('.categorychecklist input[value="3"]')).toBeEnabled();
  await page.locator('.categorychecklist input[value="3"]').uncheck();
  await expect(page.locator('input[name="ll_bulk_categories_to_remove[]"]')).toHaveValue('3');

  await page.evaluate(() => {
    document.querySelector('input[type="checkbox"][value="11"]').checked = false;
    document.querySelector('input[type="checkbox"][value="12"]').checked = false;
    document.querySelector('input[type="checkbox"][value="13"]').checked = true;
    window.inlineEditPost.setBulk();
  });
  await page.waitForFunction(() => window.__bulkCategoryPending.length === 2);

  await expect(page.locator('.categorychecklist input[value="3"]')).not.toBeChecked();
  await expect(page.locator('.categorychecklist input[value="3"]')).toBeDisabled();
  await expect(page.locator('input[name="ll_bulk_categories_to_remove[]"]')).toHaveCount(0);

  await page.evaluate(() => window.__bulkCategoryPending[1].reject());
  await expect(page.locator('.categorychecklist')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.categorychecklist input[value="3"]')).not.toBeChecked();
  await expect(page.locator('.categorychecklist input[value="3"]')).toBeDisabled();
  await expect(page.locator('.ll-bulk-category-state-notice')).toContainText(
    'Categories could not be loaded. Category changes are unavailable.'
  );

  await page.locator('#bulk-edit .button.save').click();
  await expect(page.locator('input[name="ll_bulk_categories_to_remove[]"]')).toHaveCount(0);
});
