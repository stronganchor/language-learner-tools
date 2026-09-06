const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const bulkEditSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-audio-bulk-recording-type-edit.js'),
  'utf8'
);

async function loadBulkEditHarness(page, responses) {
  await page.goto('about:blank');
  await page.setContent(`
    <table>
      <tbody>
        <tr><th class="check-column"><input type="checkbox" value="11" checked></th></tr>
        <tr><th class="check-column"><input type="checkbox" value="12" checked></th></tr>
        <tr><th class="check-column"><input type="checkbox" value="13"></th></tr>
      </tbody>
    </table>
    <div id="bulk-edit" class="inline-editor" style="display:block">
      <p data-ll-word-audio-bulk-recording-type-status></p>
      <label><input class="ll-word-audio-bulk-recording-type-option" type="checkbox" value="3"> Isolation</label>
      <label><input class="ll-word-audio-bulk-recording-type-option" type="checkbox" value="4"> Question</label>
      <button type="button" class="button save">Update</button>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((queuedResponses) => {
    window.__bulkEditRequests = [];
    window.__bulkEditResponses = queuedResponses;
    window.__bulkEditPending = [];
    window.__bulkEditAbortCount = 0;
    window.inlineEditPost = {
      setBulk() {
        window.__originalBulkEditCalls = (window.__originalBulkEditCalls || 0) + 1;
      }
    };
    window.llWordAudioBulkRecordingTypeEditData = {
      ajaxurl: '/wp-admin/admin-ajax.php',
      nonce: 'bulk-test-nonce',
      actionName: 'll_word_audio_get_bulk_recording_type_state',
      strings: {
        idle: 'Open bulk edit',
        loading: 'Loading shared types',
        ready: 'Shared types ready',
        notUniform: 'Types are not uniform',
        loadError: 'Types could not be loaded'
      }
    };
    window.jQuery.ajax = function (options) {
      const deferred = window.jQuery.Deferred();
      window.__bulkEditRequests.push(options.data);
      const response = window.__bulkEditResponses.shift();
      let settled = false;
      const request = deferred.promise({
        abort() {
          window.__bulkEditAbortCount += 1;
          if (settled || (response && response.ignoreAbort)) {
            return;
          }
          settled = true;
          deferred.reject({ statusText: 'abort' }, 'abort');
        }
      });
      if (response && response.pending) {
        window.__bulkEditPending.push({
          resolve(payload) {
            if (settled) {
              return;
            }
            settled = true;
            deferred.resolve(payload);
          },
          reject(error) {
            if (settled) {
              return;
            }
            settled = true;
            deferred.reject(error || { status: 500 }, 'error');
          }
        });
        return request;
      }
      window.setTimeout(() => {
        if (settled) {
          return;
        }
        settled = true;
        if (response && response.reject) {
          deferred.reject({ status: 500 }, 'error');
          return;
        }
        deferred.resolve(response ? response.payload : null);
      }, 0);
      return request;
    };
  }, responses);
  await page.addScriptTag({ content: bulkEditSource });
  await page.waitForFunction(() => window.inlineEditPost.setBulk.toString().includes('loadBulkRecordingTypeState'));
}

test('word audio bulk edit loads a uniform state and serializes an explicit replacement', async ({ page }) => {
  await loadBulkEditHarness(page, [{
    payload: {
      success: true,
      data: { allSame: true, common: [3] }
    }
  }]);

  await page.evaluate(() => window.inlineEditPost.setBulk());
  await expect(page.locator('[data-ll-word-audio-bulk-recording-type-status]')).toHaveText('Shared types ready');
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option[value="3"]')).toBeChecked();
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option[value="4"]')).toBeEnabled();
  await page.locator('.ll-word-audio-bulk-recording-type-option[value="4"]').check();

  const state = await page.evaluate(() => ({
    requests: window.__bulkEditRequests,
    originalCalls: window.__originalBulkEditCalls,
    replace: document.querySelector('input[name="ll_bulk_recording_types_replace"]')?.value || '',
    selected: Array.from(document.querySelectorAll('input[name="ll_bulk_recording_types_selected[]"]'))
      .map((input) => input.value)
  }));

  expect(state.originalCalls).toBe(1);
  expect(state.requests).toHaveLength(1);
  expect(state.requests[0]).toMatchObject({
    action: 'll_word_audio_get_bulk_recording_type_state',
    nonce: 'bulk-test-nonce',
    post_ids: [11, 12]
  });
  expect(state.replace).toBe('1');
  expect(state.selected).toEqual(['3', '4']);
});

test('word audio bulk edit disables ambiguous replacement and surfaces a later load failure', async ({ page }) => {
  await loadBulkEditHarness(page, [
    {
      payload: {
        success: true,
        data: { allSame: false, common: [3] }
      }
    },
    { reject: true }
  ]);

  await page.evaluate(() => window.inlineEditPost.setBulk());
  await expect(page.locator('[data-ll-word-audio-bulk-recording-type-status]')).toHaveText('Types are not uniform');
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option').first()).toBeDisabled();
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option').last()).toBeDisabled();
  await expect(page.locator('input[name="ll_bulk_recording_types_replace"]')).toHaveCount(0);

  await page.evaluate(() => window.inlineEditPost.setBulk());
  await expect(page.locator('[data-ll-word-audio-bulk-recording-type-status]')).toHaveText('Types could not be loaded');
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option').first()).toBeDisabled();
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option').last()).toBeDisabled();
  await expect(page.locator('input[name="ll_bulk_recording_types_replace"]')).toHaveCount(0);
});

test('word audio bulk edit coalesces duplicate opens and ignores an older selection response', async ({ page }) => {
  await loadBulkEditHarness(page, [
    { pending: true, ignoreAbort: true },
    { pending: true }
  ]);

  await page.evaluate(() => {
    window.inlineEditPost.setBulk();
    document.querySelector('#bulk-edit').classList.add('is-observer-visible');
  });
  await page.waitForFunction(() => window.__bulkEditPending.length === 1);
  expect(await page.evaluate(() => window.__bulkEditRequests.length)).toBe(1);

  await page.evaluate(() => {
    document.querySelector('input[type="checkbox"][value="11"]').checked = false;
    document.querySelector('input[type="checkbox"][value="12"]').checked = false;
    document.querySelector('input[type="checkbox"][value="13"]').checked = true;
    window.inlineEditPost.setBulk();
  });
  await page.waitForFunction(() => window.__bulkEditPending.length === 2);

  await page.evaluate(() => {
    window.__bulkEditPending[1].resolve({
      success: true,
      data: { allSame: true, common: [4] }
    });
  });
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option[value="4"]')).toBeChecked();
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option[value="3"]')).not.toBeChecked();

  await page.evaluate(() => {
    window.__bulkEditPending[0].resolve({
      success: true,
      data: { allSame: true, common: [3] }
    });
  });
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option[value="4"]')).toBeChecked();
  await expect(page.locator('.ll-word-audio-bulk-recording-type-option[value="3"]')).not.toBeChecked();

  const state = await page.evaluate(() => ({
    requests: window.__bulkEditRequests.map((request) => request.post_ids),
    abortCount: window.__bulkEditAbortCount
  }));
  expect(state.requests).toEqual([[11, 12], [13]]);
  expect(state.abortCount).toBe(1);
});
