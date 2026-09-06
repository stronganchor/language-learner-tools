const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const source = fs.readFileSync(path.resolve(__dirname, '../../../js/wordset-transcription-review.js'), 'utf8');
const css = fs.readFileSync(path.resolve(__dirname, '../../../css/wordset-transcription-review.css'), 'utf8');

function row(id = 11) {
  return { recording_id: id, word_text: `Word ${id}`, word_translation: 'Meaning', recording_type: 'Isolation',
    recording_text: 'Original text', recording_ipa: 'aba', review_note: '', needs_review: true,
    review_fields: { recording_ipa: true }, audio_url: '/audio.mp3',
    revisions: { recording_text: 'text-1', recording_ipa: 'ipa-1', review_note: 'note-1', review_fields: 'review-1' } };
}
function pageData(rows = [row()], next = 11, more = false) { return { recordings: rows, next_cursor: next, has_more: more }; }
const messages = { loading: 'Loading', saving: 'Saving', saved: 'Saved', empty: 'No recordings found on this page.', more: 'More recordings remain. Continue to the next page.',
  error: 'Could not load recordings.', saveError: 'Reload this recording before editing again.', pending: 'Finish saving or reload first.',
  recordingText: 'Recording text', note: 'Review note', review: 'Needs review', reviewed: 'Mark reviewed', reload: 'Reload', audio: 'Audio recording' };

async function setup(page, handler) {
  await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: '<html><body></body></html>' }));
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.unroute('**/*');
  await page.route('**/admin-ajax.php', async route => {
    const params = Object.fromEntries(new URLSearchParams(route.request().postData()));
    const result = await handler(params);
    await route.fulfill({ status: result.status || 200, contentType: 'application/json', body: JSON.stringify(result.body || { success: true, data: result }) });
  });
  await page.setContent(`<section class="ll-transcription-review" data-ll-transcription-review>
    <form data-review-search><label>Search recordings<input type="search" name="query"></label>
      <label><input type="checkbox" name="review_only">Needs review</label><button type="submit">Search</button></form>
    <p data-review-status role="status"></p><div data-review-rows></div>
    <nav><button data-review-previous type="button">Previous</button><button data-review-next type="button">Next</button></nav>
  </section>`);
  await page.addStyleTag({ content: css });
  await page.evaluate(data => { window.llWordsetTranscriptionReview = data; }, {
    ajaxUrl: '/admin-ajax.php', nonce: 'review-nonce', wordsetId: 7, ipaLabel: 'IPA', symbols: ['ʃ', 'ə'], saveDelayMs: 30, messages
  });
  await page.addScriptTag({ content: source });
  await expect(page.locator('[data-recording-id]')).toHaveCount(1);
}

test('listens and serializes autosave without losing typing during a pending save', async ({ page }) => {
  let current = row();
  const saves = [];
  let finishFirst;
  await setup(page, async params => {
    if (params.action.startsWith('ll_tools_get')) return pageData([current]);
    saves.push(params);
    if (saves.length === 1) await new Promise(resolve => { finishFirst = resolve; });
    current = { ...current, [params.field]: params.value, revisions: { ...current.revisions, [params.field]: `text-${saves.length + 1}` } };
    return { recording: current };
  });
  await expect(page.locator('audio')).toHaveAttribute('preload', 'none');
  const input = page.getByLabel('Recording text', { exact: true });
  await input.fill('First edit');
  await expect.poll(() => saves.length).toBe(1);
  await input.fill('Newer typing');
  finishFirst();
  await expect.poll(() => saves.length).toBe(2);
  await expect(input).toHaveValue('Newer typing');
  await expect(page.locator('.ll-transcription-review__save-status')).toHaveText('Saved');
  expect(saves[1].expected).toBe('text-2');
  expect(saves[1].wordset_id).toBe('7');
  expect(saves[1].nonce).toBe('review-nonce');
});

test('cursor paging and scoped search send bounded continuation context', async ({ page }) => {
  const requests = [];
  await setup(page, async params => {
    requests.push(params);
    if (params.query) return pageData([], 75, true);
    return params.cursor === '11' ? pageData([row(12)], 12, false) : pageData([row()], 11, true);
  });
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page.locator('[data-recording-id="12"]')).toBeVisible();
  await page.getByRole('button', { name: 'Previous', exact: true }).click();
  await expect(page.locator('[data-recording-id="11"]')).toBeVisible();
  await page.getByLabel('Search recordings').fill('ə');
  await page.getByLabel('Needs review', { exact: true }).check();
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('[data-review-status]')).toHaveText(messages.more);
  expect(requests.at(-1)).toMatchObject({ query: 'ə', cursor: '0', review_only: '1', wordset_id: '7' });
  await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeEnabled();
});

test('uncertain or conflicting saves retain local text and stop replay until a record reload', async ({ page }) => {
  let saves = 0;
  let reloads = 0;
  let current = row();
  await setup(page, async params => {
    if (params.action.startsWith('ll_tools_get')) {
      if (params.recording_id) { reloads++; return { recording: current }; }
      return pageData([current], 11, true);
    }
    saves++;
    if (saves === 1) return { status: 409, body: { success: false, data: { message: 'This recording changed. Reload it before editing again.' } } };
    current = { ...current, [params.field]: params.value };
    return { recording: current };
  });
  const input = page.getByLabel('Recording text', { exact: true });
  await input.fill('Keep this local draft');
  await expect(page.getByRole('button', { name: 'Reload', exact: true })).toBeVisible();
  await expect(input).toHaveValue('Keep this local draft');
  await input.fill('Still local');
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page.locator('[data-review-status]')).toHaveText(messages.pending);
  expect(saves).toBe(1);
  await page.getByRole('button', { name: 'Reload', exact: true }).click();
  await expect(input).toHaveValue('Original text');
  expect(reloads).toBe(1);
  await input.fill('Reviewed again');
  await expect.poll(() => saves).toBe(2);
  await expect(page.locator('.ll-transcription-review__save-status')).toHaveText('Saved');
});

test('search ignores an older response and keeps current cards disabled while loading', async ({ page }) => {
  let finishOld;
  await setup(page, async params => {
    if (params.query === 'old') { await new Promise(resolve => { finishOld = resolve; }); return pageData([row(90)]); }
    if (params.query === 'new') return pageData([row(91)]);
    return pageData();
  });
  await page.getByLabel('Search recordings').fill('old');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect.poll(() => typeof finishOld).toBe('function');
  await expect(page.getByLabel('Recording text', { exact: true })).toBeDisabled();
  await page.getByLabel('Search recordings').fill('new');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('[data-recording-id="91"]')).toBeVisible();
  finishOld();
  await expect(page.locator('[data-recording-id="90"]')).toHaveCount(0);
});

test('failed refresh removes previously visible recording media and can be retried', async ({ page }) => {
  let calls = 0;
  await setup(page, async () => {
    calls++;
    return calls === 2 ? { status: 403, body: { success: false, data: { message: 'Forbidden' } } } : pageData();
  });
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('[data-review-status]')).toHaveText('Forbidden');
  await expect(page.locator('[data-recording-id]')).toHaveCount(0);
  await expect(page.locator('audio')).toHaveCount(0);
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('[data-recording-id="11"]')).toBeVisible();
});

test('review notes and reviewed action follow server state without replacing other fields', async ({ page }) => {
  let current = row();
  const fields = [];
  await setup(page, async params => {
    if (params.action.startsWith('ll_tools_get')) return pageData([current]);
    fields.push(params.field);
    current = params.field === 'review_fields'
      ? { ...current, needs_review: false, review_fields: {}, review_note: '', revisions: { ...current.revisions, review_note: 'note-cleared', review_fields: 'review-cleared' } }
      : { ...current, [params.field]: params.value };
    return { recording: current };
  });
  await page.getByLabel('Review note', { exact: true }).fill('Check the first vowel.');
  await expect(page.locator('.ll-transcription-review__save-status')).toHaveText('Saved');
  await page.getByRole('button', { name: 'Mark reviewed', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Mark reviewed', exact: true })).toBeHidden();
  await expect(page.getByLabel('Review note', { exact: true })).toHaveValue('');
  await expect(page.getByLabel('Recording text', { exact: true })).toHaveValue('Original text');
  expect(fields).toEqual(['review_note', 'review_fields']);
});

test('phone layout and symbol controls remain usable under conflicting theme styles', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  let current = row();
  await setup(page, async params => {
    if (params.action.startsWith('ll_tools_get')) return pageData([current]);
    current = { ...current, [params.field]: params.value };
    return { recording: current };
  });
  await page.addStyleTag({ content: 'button { text-transform: uppercase; color: white; background: white; padding: 0; } textarea { width: 900px; }' });
  const ipa = page.getByLabel('IPA', { exact: true });
  await ipa.fill('');
  await page.getByRole('button', { name: 'ʃ', exact: true }).click();
  await expect(ipa).toHaveValue('ʃ');
  const styles = await page.getByRole('button', { name: 'ʃ', exact: true }).evaluate(node => {
    const style = getComputedStyle(node); return { color: style.color, height: node.getBoundingClientRect().height, transform: style.textTransform };
  });
  expect(styles.color).toBe('rgb(29, 78, 216)');
  expect(styles.height).toBeGreaterThanOrEqual(44);
  expect(styles.transform).toBe('none');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await expect(page.locator('.ll-transcription-review__save-status')).toHaveText('Saved');
});
