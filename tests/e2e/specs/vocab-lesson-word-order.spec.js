const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);

function buildWordOrderMarkup() {
  return `
    <div class="ll-vocab-lesson-page">
      <div
        id="word-grid"
        class="word-grid ll-word-grid ll-word-grid--reorderable"
        data-ll-word-grid
        data-ll-word-grid-reorderable="1"
        data-ll-lesson-id="42"
        data-ll-wordset-id="7"
        data-ll-category-id="9"
      >
        <div class="ll-word-grid-order-status" data-ll-word-grid-order-status hidden></div>
        <div class="word-item" data-word-id="101">
          <div class="ll-word-actions-row"><span class="ll-word-grid-order-handle" data-ll-word-grid-order-handle></span></div>
          <div class="word-title">One</div>
        </div>
        <div class="word-item" data-word-id="102">
          <div class="ll-word-actions-row"><span class="ll-word-grid-order-handle" data-ll-word-grid-order-handle></span></div>
          <div class="word-title">Two</div>
        </div>
        <div class="word-item" data-word-id="103">
          <div class="ll-word-actions-row"><span class="ll-word-grid-order-handle" data-ll-word-grid-order-handle></span></div>
          <div class="word-title">Three</div>
        </div>
      </div>
    </div>
  `;
}

function buildWordGridConfig() {
  return {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    editNonce: 'lesson-order-nonce',
    canEdit: true,
    isLoggedIn: true,
    state: {
      wordset_id: 7,
      category_ids: [9],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    orderI18n: {
      saving: 'Saving order...',
      saved: 'Order saved.',
      error: 'Unable to save the lesson order.'
    }
  };
}

test('lesson word reordering posts the dragged order through AJAX', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildWordOrderMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((cfg) => {
    window.llToolsWordGridData = cfg;
    window.__llLessonOrderCalls = [];

    jQuery.fn.sortable = function (arg) {
      if (typeof arg === 'string') {
        if (arg === 'destroy') {
          return this.each(function () {
            jQuery(this).removeData('ui-sortable');
            delete this.__llSortableOptions;
          });
        }
        return this;
      }

      return this.each(function () {
        jQuery(this).data('ui-sortable', { options: arg || {} });
        this.__llSortableOptions = arg || {};
      });
    };

    jQuery.ajax = function (options) {
      const payload = JSON.parse(JSON.stringify(options && options.data ? options.data : {}));
      window.__llLessonOrderCalls.push(payload);
      const deferred = jQuery.Deferred();
      window.setTimeout(() => {
        deferred.resolve({
          success: true,
          data: {
            order: payload.order || []
          }
        });
      }, 10);
      return deferred.promise();
    };
  }, buildWordGridConfig());

  await page.addScriptTag({ content: wordGridScriptSource });

  await page.waitForFunction(() => {
    const grid = document.querySelector('[data-ll-word-grid]');
    return !!(grid && grid.__llSortableOptions);
  });

  await page.evaluate(() => {
    const grid = document.querySelector('[data-ll-word-grid]');
    const third = grid.querySelector('.word-item[data-word-id="103"]');
    const first = grid.querySelector('.word-item[data-word-id="101"]');
    grid.insertBefore(third, first);

    const options = grid.__llSortableOptions || {};
    if (typeof options.update === 'function') {
      options.update.call(grid, null, {
        item: jQuery(third)
      });
    }
  });

  await page.waitForFunction(() => Array.isArray(window.__llLessonOrderCalls) && window.__llLessonOrderCalls.length === 1);

  const payload = await page.evaluate(() => window.__llLessonOrderCalls[0]);
  expect(payload.action).toBe('ll_tools_word_grid_save_lesson_order');
  expect(payload.lesson_id).toBe('42');
  expect(payload.nonce).toBe('lesson-order-nonce');
  expect(payload.order).toEqual([103, 101, 102]);

  await expect(page.locator('[data-ll-word-grid-order-status]')).toContainText('Order saved.');
});

test('mobile lesson word reordering uses a drag handle so card bodies can scroll', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildWordOrderMarkup());
  await page.addScriptTag({ content: jquerySource });

  await page.evaluate((cfg) => {
    window.llToolsWordGridData = cfg;
    window.matchMedia = (query) => ({
      matches: String(query || '').includes('(pointer: coarse)'),
      media: String(query || ''),
      onchange: null,
      addListener() {},
      removeListener() {},
      addEventListener() {},
      removeEventListener() {},
      dispatchEvent() {
        return false;
      }
    });

    jQuery.fn.sortable = function (arg) {
      if (typeof arg === 'string') {
        return this;
      }

      return this.each(function () {
        jQuery(this).data('ui-sortable', { options: arg || {} });
        this.__llSortableOptions = arg || {};
      });
    };
  }, buildWordGridConfig());

  await page.addScriptTag({ content: wordGridScriptSource });

  const config = await page.locator('[data-ll-word-grid]').evaluate((grid) => ({
    handle: grid.__llSortableOptions && grid.__llSortableOptions.handle,
    requiresHandle: grid.classList.contains('ll-word-grid--order-handle-required')
  }));

  expect(config.handle).toBe('[data-ll-word-grid-order-handle]');
  expect(config.requiresHandle).toBe(true);
});
