const path = require('path');
const { test, expect } = require('@playwright/test');

const jqueryPath = path.resolve(__dirname, '..', 'node_modules', 'jquery', 'dist', 'jquery.min.js');
const speakerSearchScriptPath = path.resolve(__dirname, '..', '..', '..', 'js', 'audio-upload-form-admin.js');

async function loadSpeakerSearchFixture(page) {
  await page.setContent(`
    <form data-ll-audio-upload-form="1">
      <select name="ll_speaker_assignment" data-ll-speaker-assignment>
        <option value="current">Current User</option>
        <option value="unassigned">Unassigned</option>
      </select>
      <div data-ll-speaker-search>
        <input type="search" data-ll-speaker-search-input>
        <p data-ll-speaker-search-status role="status"></p>
        <div data-ll-speaker-search-results hidden style="display:none"></div>
      </div>
    </form>
  `);
  await page.addScriptTag({ path: jqueryPath });
  await page.evaluate(() => {
    window.llAudioUploadFormData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      speakerSearchAction: 'll_audio_upload_search_speakers',
      speakerSearchNonce: 'speaker-search-nonce',
      speakerSearchMinChars: 2,
      speakerSearchDelay: 0,
      strings: {
        speakerSearchHint: 'Enter at least 2 characters.',
        speakerSearchLoading: 'Searching users...',
        speakerSearchNoResults: 'No matching speakers found.',
        speakerSearchError: 'Speakers could not be loaded.',
        speakerSearchMore: 'More users match.',
        speakerSelected: 'Selected speaker: %s',
      },
    };
    window.__speakerRequests = [];
    window.jQuery.ajax = (options) => {
      const deferred = window.jQuery.Deferred();
      const request = deferred.promise();
      request.abort = () => deferred.reject({}, 'abort');
      window.__speakerRequests.push({ options, deferred });
      return request;
    };
  });
  await page.addScriptTag({ path: speakerSearchScriptPath });
}

test('speaker search keeps wire values and selects a bounded AJAX result', async ({ page }) => {
  await loadSpeakerSearchFixture(page);

  const input = page.locator('[data-ll-speaker-search-input]');
  const status = page.locator('[data-ll-speaker-search-status]');
  const assignment = page.locator('[data-ll-speaker-assignment]');

  await input.fill('a');
  await expect(status).toHaveText('Enter at least 2 characters.');
  await expect.poll(() => page.evaluate(() => window.__speakerRequests.length)).toBe(0);

  await input.fill('al');
  await expect.poll(() => page.evaluate(() => window.__speakerRequests.length)).toBe(1);
  await expect(status).toHaveText('Searching users...');
  await expect.poll(() => page.evaluate(() => window.__speakerRequests[0].options.data)).toEqual({
    action: 'll_audio_upload_search_speakers',
    nonce: 'speaker-search-nonce',
    search: 'al',
  });

  await page.evaluate(() => {
    window.__speakerRequests[0].deferred.resolve({
      success: true,
      data: {
        results: [
          { id: 42, label: 'Alice Speaker' },
          { id: 77, label: 'Alina Speaker' },
        ],
        has_more: true,
      },
    });
  });

  await expect(page.locator('[data-ll-speaker-result]')).toHaveCount(2);
  await expect(status).toHaveText('More users match.');
  await page.getByRole('button', { name: 'Alice Speaker' }).click();

  await expect(assignment).toHaveValue('42');
  await expect(assignment.locator('option[value="current"]')).toHaveCount(1);
  await expect(assignment.locator('option[value="unassigned"]')).toHaveCount(1);
  await expect(assignment.locator('option[value="42"][data-ll-dynamic-speaker]')).toHaveText('Alice Speaker');
  await expect(status).toHaveText('Selected speaker: Alice Speaker');
  await expect(input).toHaveValue('');
});

test('speaker search exposes no-results and request-error states', async ({ page }) => {
  await loadSpeakerSearchFixture(page);

  const input = page.locator('[data-ll-speaker-search-input]');
  const status = page.locator('[data-ll-speaker-search-status]');

  await input.fill('zz');
  await expect.poll(() => page.evaluate(() => window.__speakerRequests.length)).toBe(1);
  await page.evaluate(() => {
    window.__speakerRequests[0].deferred.resolve({
      success: true,
      data: { results: [], has_more: false },
    });
  });
  await expect(status).toHaveText('No matching speakers found.');
  await expect(status).toHaveAttribute('data-ll-speaker-search-state', 'empty');

  await input.fill('er');
  await expect.poll(() => page.evaluate(() => window.__speakerRequests.length)).toBe(2);
  await page.evaluate(() => {
    window.__speakerRequests[1].deferred.reject({}, 'error');
  });
  await expect(status).toHaveText('Speakers could not be loaded.');
  await expect(status).toHaveAttribute('data-ll-speaker-search-state', 'error');
});
