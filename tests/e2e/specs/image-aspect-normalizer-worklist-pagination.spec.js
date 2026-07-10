const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const normalizerSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/image-aspect-normalizer-admin.js'),
  'utf8'
);

test('image aspect normalizer advances its worklist in explicit bounded pages', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <div data-ll-aspect-normalizer-root>
      <div data-ll-aspect-worklist></div>
      <button data-ll-aspect-worklist-more hidden>Load more</button>
      <div data-ll-aspect-status></div>
      <div data-ll-aspect-errors></div>
      <h2 data-ll-aspect-category-title></h2>
      <p data-ll-aspect-category-summary></p>
      <div data-ll-aspect-controls hidden></div>
      <select data-ll-aspect-ratio-select></select>
      <div data-ll-aspect-ratio-custom-wrap hidden><input data-ll-aspect-ratio-custom></div>
      <button data-ll-aspect-preview></button>
      <button data-ll-aspect-apply></button>
      <div data-ll-aspect-offenders></div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.__llAspectOffsets = [];
    window.llAspectNormalizerData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      preselectedCategoryId: 0,
      actions: {
        worklist: 'll_tools_aspect_normalizer_worklist',
        category: 'll_tools_aspect_normalizer_category',
        apply: 'll_tools_aspect_normalizer_apply'
      },
      i18n: {
        loading: 'Loading categories...',
        worklistLoadMore: 'Load more',
        worklistLoadingMore: 'Loading more...',
        emptyWorklist: 'No categories currently need image aspect normalization.',
        chooseCategory: 'Choose category'
      }
    };
    window.jQuery.post = function (_url, body) {
      const deferred = window.jQuery.Deferred();
      if (body.action !== 'll_tools_aspect_normalizer_worklist') {
        deferred.reject();
        return deferred.promise();
      }
      const offset = parseInt(body.offset || '0', 10) || 0;
      window.__llAspectOffsets.push(offset);
      setTimeout(() => {
        deferred.resolve({
          success: true,
          data: {
            categories: [],
            has_more: offset === 0,
            next_offset: offset === 0 ? 2 : 4,
            page_size: 2
          }
        });
      }, 0);
      return deferred.promise();
    };
  });
  await page.addScriptTag({ content: normalizerSource });

  await expect(page.locator('[data-ll-aspect-worklist-more]')).toBeVisible();
  await page.locator('[data-ll-aspect-worklist-more]').click();
  await expect(page.locator('[data-ll-aspect-worklist-more]')).toBeHidden();
  expect(await page.evaluate(() => window.__llAspectOffsets.slice())).toEqual([0, 2]);
});
