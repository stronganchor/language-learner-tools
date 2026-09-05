const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const optimizerSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/image-webp-optimizer-admin.js'),
  'utf8'
);

function queuePayload(items, queuedCount = items.length) {
  return {
    success: true,
    data: {
      page: 1,
      total_pages: 1,
      total_items: items.length,
      items,
      summary: {
        queued_count: queuedCount,
        queued_bytes_label: '3 KB',
        non_webp_count: queuedCount,
        oversize_count: 0,
        supported_count: queuedCount,
        threshold_label: '300 KB'
      },
      focus_item: null,
      encoding_supported: true
    }
  };
}

function imageItem(id, title = `Image ${id}`) {
  return {
    word_image_id: id,
    title,
    status_key: 'needs',
    status_label: 'Needs optimization',
    problem_label: 'JPEG source',
    needs_conversion: true,
    can_convert: true,
    is_webp: false,
    reason_labels: ['JPEG'],
    file_size_label: '1 KB',
    format_label: 'JPEG'
  };
}

async function loadOptimizerHarness(page, responseQueues, batchSize = 2) {
  await page.goto('about:blank');
  await page.setContent(`
    <div data-ll-webp-optimizer-root>
      <div data-ll-webp-summary></div>
      <div data-ll-webp-status hidden></div>
      <div data-ll-webp-progress hidden>
        <div class="ll-webp-progress__bar" role="progressbar"><span data-ll-webp-progress-fill></span></div>
        <span data-ll-webp-progress-label></span>
      </div>
      <select data-ll-webp-filter-category><option value="0">All</option></select>
      <input data-ll-webp-filter-search>
      <button type="button" data-ll-webp-apply-filters>Apply filters</button>
      <button type="button" data-ll-webp-refresh>Refresh</button>
      <button type="button" data-ll-webp-convert-all>Optimize all</button>
      <div data-ll-webp-cards></div>
      <div data-ll-webp-pagination hidden></div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(({ queues, size }) => {
    window.__webpRequests = [];
    window.__webpResponses = queues;
    window.confirm = () => true;
    window.llWebpOptimizerData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'webp-nonce',
      batchSize: size,
      quality: 82,
      encodingSupported: true,
      actions: {
        queue: 'll_tools_webp_queue',
        convert: 'll_tools_webp_convert'
      },
      screen: { isToolPage: true, isWordImagesList: false },
      i18n: {
        loadingQueue: 'Loading queue',
        queueFailed: 'Queue failed',
        working: 'Optimizing',
        convertFailed: 'Conversion failed',
        convertOne: 'Optimize image',
        convertAllConfirm: 'Optimize all?',
        loadingIds: 'Loading IDs',
        progressLabel: 'Batch %1$d of %2$d',
        progressDone: 'Batch complete',
        resultSummary: 'Optimized %1$d image(s); %2$d failed; saved %3$s total.',
        resultSummaryWarnings: '%d warning(s).',
        emptyQueue: 'Queue empty'
      }
    };
    window.jQuery.post = function (_url, body) {
      const deferred = window.jQuery.Deferred();
      const key = body.action === 'll_tools_webp_convert' ? 'convert' : 'queue';
      window.__webpRequests.push({ key, body: { ...body } });
      const response = (window.__webpResponses[key] || []).shift();
      window.setTimeout(() => {
        if (response && response.reject) {
          deferred.reject(response.error || {
            responseJSON: { data: { message: 'Conversion transport failed' } }
          });
          return;
        }
        deferred.resolve(response ? response.payload : null);
      }, 0);
      return deferred.promise();
    };
  }, { queues: responseQueues, size: batchSize });
  await page.addScriptTag({ content: optimizerSource });
}

test('WebP optimizer restores a failed card for an explicit retry and settles success', async ({ page }) => {
  const queuedItem = imageItem(41);
  await loadOptimizerHarness(page, {
    queue: [
      { payload: queuePayload([queuedItem]) },
      { payload: queuePayload([queuedItem]) },
      { payload: queuePayload([], 0) }
    ],
    convert: [
      { reject: true },
      {
        payload: {
          success: true,
          data: {
            converted_count: 1,
            failed_count: 0,
            warning_count: 0,
            bytes_saved_total: 2048,
            results: [{
              word_image_id: 41,
              success: true,
              warning: false,
              message: 'Image optimized',
              item: {
                ...queuedItem,
                status_key: 'ok',
                status_label: 'Optimized',
                needs_conversion: false,
                can_convert: false,
                is_webp: true
              }
            }]
          }
        }
      }
    ]
  });

  const convertButton = page.locator('[data-ll-webp-convert-card][data-word-image-id="41"]');
  await expect(convertButton).toBeVisible();
  await convertButton.click();
  await expect(page.locator('[data-ll-webp-status]')).toHaveText('Optimized 0 image(s); 1 failed; saved 0 B total.');
  await expect(page.locator('[data-ll-webp-card-feedback]')).toHaveText('Conversion transport failed');
  await expect(convertButton).toBeEnabled();

  await convertButton.click();
  await expect(page.locator('[data-ll-webp-status]')).toHaveText('Optimized 1 image(s); 0 failed; saved 2 KB total.');
  await expect(page.locator('[data-ll-webp-optimizer-root]')).not.toHaveClass(/is-busy/);
  expect(await page.evaluate(() => window.__webpRequests.filter((entry) => entry.key === 'convert').length)).toBe(2);
});

test('WebP optimizer continues later chunks after a failed bulk batch and reports the partial result', async ({ page }) => {
  const items = [imageItem(51), imageItem(52), imageItem(53)];
  await loadOptimizerHarness(page, {
    queue: [
      { payload: queuePayload(items, 3) },
      { payload: { success: true, data: { ids: [51, 52, 53] } } },
      { payload: queuePayload(items, 3) }
    ],
    convert: [
      { reject: true },
      {
        payload: {
          success: true,
          data: {
            converted_count: 1,
            failed_count: 0,
            warning_count: 0,
            bytes_saved_total: 1024,
            results: [{
              word_image_id: 53,
              success: true,
              warning: false,
              message: 'Third image optimized',
              item: {
                ...items[2],
                status_key: 'ok',
                status_label: 'Optimized',
                needs_conversion: false,
                can_convert: false,
                is_webp: true
              }
            }]
          }
        }
      }
    ]
  }, 2);

  await expect(page.locator('[data-ll-webp-card]')).toHaveCount(3);
  await page.locator('[data-ll-webp-convert-all]').click();
  await expect(page.locator('[data-ll-webp-status]')).toHaveText('Optimized 1 image(s); 2 failed; saved 1 KB total.');
  await expect(page.locator('[data-ll-webp-optimizer-root]')).not.toHaveClass(/is-busy/);
  await expect(page.locator('[data-ll-webp-convert-all]')).toBeEnabled();

  const convertRequests = await page.evaluate(() => (
    window.__webpRequests
      .filter((entry) => entry.key === 'convert')
      .map((entry) => entry.body.word_image_ids)
  ));
  expect(convertRequests).toEqual([[51, 52], [53]]);
});
