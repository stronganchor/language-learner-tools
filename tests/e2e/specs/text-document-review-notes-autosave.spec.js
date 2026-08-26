const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const reviewNotesSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/text-document-review-notes.js'),
  'utf8'
);

async function mountReviewNote(page, requestTimeoutMs = 1000) {
  await page.setContent(`
    <details data-ll-text-document-review-note data-lesson-id="42" data-note-key="line:l01" open>
      <summary>
        Review note
        <span data-ll-text-document-review-note-status aria-live="polite"></span>
      </summary>
      <textarea data-ll-text-document-review-note-input>Saved base.</textarea>
    </details>
  `);
  await page.evaluate((timeoutMs) => {
    window.llToolsTextDocumentReviewNotes = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      action: 'll_tools_save_text_document_review_note',
      nonce: 'review-note-nonce',
      requestTimeoutMs: timeoutMs,
      i18n: {
        saving: 'Saving review note...',
        saved: 'Review note saved.',
        error: 'Unable to save the review note.',
        timeout: 'Saving the review note timed out. Try again.'
      }
    };
    window.__reviewNoteCalls = [];
    window.__reviewNotePending = [];
    window.fetch = (url, options = {}) => {
      const body = new URLSearchParams(options.body || '');
      window.__reviewNoteCalls.push({
        url,
        body: Object.fromEntries(body.entries()),
        aborted: false
      });
      const callIndex = window.__reviewNoteCalls.length - 1;
      if (options.signal) {
        options.signal.addEventListener('abort', () => {
          window.__reviewNoteCalls[callIndex].aborted = true;
        });
      }
      return new Promise((resolve) => {
        window.__reviewNotePending[callIndex] = (payload) => resolve({
          json: () => Promise.resolve(payload)
        });
      });
    };
    window.__resolveReviewNote = (index, payload) => {
      window.__reviewNotePending[index](payload);
    };
  }, requestTimeoutMs);
  await page.addScriptTag({ content: reviewNotesSource });
}

async function requestImmediateSave(input) {
  await input.dispatchEvent('change');
}

async function dispatchBeforeUnload(page) {
  return page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event.defaultPrevented;
  });
}

test('review-note autosave warns while an edit is pending or saving and clears after settlement', async ({ page }) => {
  await mountReviewNote(page);
  const input = page.locator('[data-ll-text-document-review-note-input]');

  expect(await dispatchBeforeUnload(page)).toBe(false);
  await input.fill('Pending edit.');
  expect(await dispatchBeforeUnload(page)).toBe(true);

  await requestImmediateSave(input);
  await expect.poll(() => page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);
  expect(await dispatchBeforeUnload(page)).toBe(true);

  await page.evaluate(() => window.__resolveReviewNote(0, {
    success: true,
    data: { note: 'Pending edit.' }
  }));
  await expect.poll(() => input.getAttribute('data-original-value')).toBe('Pending edit.');
  await expect.poll(() => dispatchBeforeUnload(page)).toBe(false);
});

test('review-note autosave serializes edits and sends the saved value as the next CAS base', async ({ page }) => {
  await mountReviewNote(page);
  const input = page.locator('[data-ll-text-document-review-note-input]');
  const status = page.locator('[data-ll-text-document-review-note-status]');

  await input.fill('First edit.');
  await requestImmediateSave(input);
  await expect.poll(() => page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);

  await input.fill('Newest edit.');
  await requestImmediateSave(input);
  expect(await page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);

  await page.evaluate(() => window.__resolveReviewNote(0, {
    success: true,
    data: { note: 'First edit.' }
  }));
  await expect.poll(() => page.evaluate(() => window.__reviewNoteCalls.length)).toBe(2);

  const calls = await page.evaluate(() => window.__reviewNoteCalls);
  expect(calls[0].body.note).toBe('First edit.');
  expect(calls[0].body.base_note).toBe('Saved base.');
  expect(calls[1].body.note).toBe('Newest edit.');
  expect(calls[1].body.base_note).toBe('First edit.');

  await page.evaluate(() => window.__resolveReviewNote(1, {
    success: true,
    data: { note: 'Newest edit.' }
  }));
  await expect(status).toHaveText('Review note saved.');
  await expect(input).toHaveValue('Newest edit.');
  expect(await input.getAttribute('data-original-value')).toBe('Newest edit.');
});

test('review-note autosave reconciles server normalization without resubmitting forever', async ({ page }) => {
  await mountReviewNote(page);
  const input = page.locator('[data-ll-text-document-review-note-input]');
  const status = page.locator('[data-ll-text-document-review-note-status]');

  await input.fill('  Normalized note.  ');
  await requestImmediateSave(input);
  await expect.poll(() => page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);
  await page.evaluate(() => window.__resolveReviewNote(0, {
    success: true,
    data: { note: 'Normalized note.' }
  }));

  await expect(status).toHaveText('Review note saved.');
  await expect(input).toHaveValue('Normalized note.');
  expect(await input.getAttribute('data-original-value')).toBe('Normalized note.');
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);
});

test('review-note autosave releases a timed-out request and ignores its late response', async ({ page }) => {
  await mountReviewNote(page, 50);
  const wrapper = page.locator('[data-ll-text-document-review-note]');
  const input = page.locator('[data-ll-text-document-review-note-input]');
  const status = page.locator('[data-ll-text-document-review-note-status]');

  await input.fill('Timed-out edit.');
  await requestImmediateSave(input);
  await expect(status).toHaveText('Saving the review note timed out. Try again.');
  await expect(wrapper).not.toHaveClass(/is-saving/);
  expect(await page.evaluate(() => window.__reviewNoteCalls[0].aborted)).toBe(true);

  await input.fill('Recovered edit.');
  await requestImmediateSave(input);
  await expect.poll(() => page.evaluate(() => window.__reviewNoteCalls.length)).toBe(2);
  await page.evaluate(() => window.__resolveReviewNote(1, {
    success: true,
    data: { note: 'Recovered edit.' }
  }));
  await expect(status).toHaveText('Review note saved.');

  await page.evaluate(() => window.__resolveReviewNote(0, {
    success: true,
    data: { note: 'Timed-out edit.' }
  }));
  await page.waitForTimeout(75);
  await expect(input).toHaveValue('Recovered edit.');
  expect(await input.getAttribute('data-original-value')).toBe('Recovered edit.');
  await expect(status).toHaveText('Review note saved.');
});

test('review-note autosave exposes a same-key conflict without automatically overwriting it', async ({ page }) => {
  await mountReviewNote(page);
  const input = page.locator('[data-ll-text-document-review-note-input]');
  const status = page.locator('[data-ll-text-document-review-note-status]');

  await input.fill('Local edit.');
  await requestImmediateSave(input);
  await expect.poll(() => page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);
  await page.evaluate(() => window.__resolveReviewNote(0, {
    success: false,
    data: {
      code: 'll_tools_text_document_review_note_conflict',
      message: 'This review note changed elsewhere. Reload to review it.',
      note: 'Other editor saved this.'
    }
  }));

  await expect(status).toHaveText('This review note changed elsewhere. Reload to review it.');
  await expect(input).toHaveValue('Local edit.');
  expect(await input.getAttribute('data-original-value')).toBe('Saved base.');
  await page.waitForTimeout(50);
  expect(await page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);

  await input.focus();
  await input.blur();
  await input.fill('Still-local edit.');
  await requestImmediateSave(input);
  await page.waitForTimeout(750);
  expect(await page.evaluate(() => window.__reviewNoteCalls.length)).toBe(1);
  await expect(status).toHaveText('This review note changed elsewhere. Reload to review it.');
});
