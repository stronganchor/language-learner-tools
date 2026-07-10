const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);

async function mountTranscriptionControls(page) {
  await page.goto('about:blank');
  await page.setContent(`
    <div class="word-grid" data-ll-word-grid data-ll-wordset-id="1" data-ll-category-id="2">
      <div data-ll-transcribe-wrapper>
        <button type="button" data-ll-transcribe-recordings data-lesson-id="9">Transcribe</button>
        <button type="button" data-ll-transcribe-replace data-lesson-id="9">Replace</button>
        <button type="button" data-ll-transcribe-clear data-lesson-id="9">Clear</button>
        <button type="button" data-ll-transcribe-cancel>Cancel</button>
        <span data-ll-transcribe-status></span>
      </div>
    </div>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate(() => {
    window.llToolsWordGridData = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      editNonce: 'transcription-test-nonce',
      canEdit: true,
      isLoggedIn: true,
      state: {
        wordset_id: 1,
        category_ids: [2]
      },
      transcribeI18n: {
        done: 'Transcription complete.',
        cleared: 'Transcription cleared.',
        none: 'Nothing to process.',
        error: 'Request failed.'
      }
    };
    window.llTranscriptionPostCalls = [];
    jQuery.post = function (_url, data) {
      const payload = JSON.parse(JSON.stringify(data || {}));
      window.llTranscriptionPostCalls.push(payload);
      const deferred = jQuery.Deferred();
      const afterId = parseInt(payload.after_id, 10) || 0;

      if (payload.action === 'll_tools_get_lesson_transcribe_queue') {
        const responses = {
          0: { queue: [{ recording_id: 1 }, { recording_id: 2 }], next_after_id: 2, has_more: true },
          2: { queue: [], next_after_id: 4, has_more: true },
          4: { queue: [{ recording_id: 5 }], next_after_id: 5, has_more: false }
        };
        deferred.resolve({ success: true, data: responses[afterId] });
      } else if (payload.action === 'll_tools_transcribe_recording_by_id') {
        deferred.resolve({ success: true, data: {} });
      } else if (payload.action === 'll_tools_clear_lesson_transcriptions') {
        const responses = {
          0: { cleared: [1, 2], next_after_id: 2, has_more: true },
          2: { cleared: [], next_after_id: 4, has_more: true },
          4: { cleared: [5], next_after_id: 5, has_more: false }
        };
        deferred.resolve({ success: true, data: responses[afterId] });
      } else {
        deferred.resolve({ success: true, data: {} });
      }
      return deferred.promise();
    };
  });
  await page.addScriptTag({ content: wordGridScriptSource });
}

test('lesson transcription follows recording cursor pages including empty pages', async ({ page }) => {
  await mountTranscriptionControls(page);

  await page.locator('[data-ll-transcribe-recordings]').click();
  await expect(page.locator('[data-ll-transcribe-status]')).toHaveText('Transcription complete.');

  const calls = await page.evaluate(() => window.llTranscriptionPostCalls);
  const queueCalls = calls.filter((call) => call.action === 'll_tools_get_lesson_transcribe_queue');
  const transcriptionCalls = calls.filter((call) => call.action === 'll_tools_transcribe_recording_by_id');
  expect(queueCalls.map((call) => parseInt(call.after_id, 10) || 0)).toEqual([0, 2, 4]);
  expect(transcriptionCalls.map((call) => parseInt(call.recording_id, 10))).toEqual([1, 2, 5]);
});

test('lesson transcription clearing follows all recording cursor pages', async ({ page }) => {
  await mountTranscriptionControls(page);

  await page.locator('[data-ll-transcribe-clear]').click();
  await expect(page.locator('[data-ll-transcribe-status]')).toHaveText('Transcription cleared.');

  const clearCalls = await page.evaluate(() => window.llTranscriptionPostCalls.filter(
    (call) => call.action === 'll_tools_clear_lesson_transcriptions'
  ));
  expect(clearCalls.map((call) => parseInt(call.after_id, 10) || 0)).toEqual([0, 2, 4]);
});
