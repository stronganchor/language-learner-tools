const { test, expect } = require('@playwright/test');
const path = require('path');
const { randomUUID } = require('crypto');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 360000 });
const fixtureScript = path.resolve(__dirname, '../fixtures/seed-frontend-recording-tools.php');
const fixture = (command, ...args) => runWpCliJson(['eval-file', fixtureScript, command, ...args], { timeoutMs: 180000 });

async function login(page, user) {
  await page.context().clearCookies();
  await page.goto('/wp-login.php?reauth=1', { waitUntil: 'domcontentloaded' });
  await page.locator('#user_login').fill(user.login);
  await page.locator('#user_pass').fill(user.password);
  const response = page.waitForResponse(res => res.request().method() === 'POST' && res.url().includes('/wp-login.php'), { timeout: 60000 });
  await page.locator('#wp-submit').click({ noWaitAfter: true });
  expect((await response).status()).toBeGreaterThanOrEqual(300);
}

test('scoped manager reviews and copies a word; attributed recorder sees private recording history', async ({ page }) => {
  let state;
  let cleanup = false;
  let copyRequestId = '';
  const fixtureRunId = randomUUID();
  const externalRequests = [];
  const origin = new URL(test.info().project.use.baseURL).origin;
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    if (['http:', 'https:'].includes(url.protocol) && url.origin !== origin) {
      externalRequests.push(url.hostname);
      return route.abort();
    }
    return route.continue();
  });
  page.on('request', request => {
    const params = new URLSearchParams(request.postData() || '');
    if (params.get('action') === 'll_tools_word_copy_apply') copyRequestId = params.get('request_id') || '';
  });
  try {
    try { cleanup = true; state = fixture('seed', fixtureRunId); }
    catch (error) {
      if (error && error.isWpCliUnavailable) { cleanup = false; test.skip(true, 'Local WP-CLI is unavailable.'); return; }
      throw error;
    }
    // A refused second run must never clean up this run's existing fixture.
    expect(() => fixture('cleanup', randomUUID())).toThrow(/different run/i);
    await login(page, state.manager);
    const reviewResponse = await page.goto(state.reviewPath, { waitUntil: 'domcontentloaded' });
    expect(reviewResponse.status()).toBe(200);
    const review = page.locator('[data-ll-transcription-review]');
    await expect(review).toBeVisible({ timeout: 60000 });
    const card = review.locator(`[data-recording-id="${state.recordingIds[0]}"]`);
    await expect(card).toBeVisible({ timeout: 60000 });
    await expect(card.locator('audio')).toHaveAttribute('preload', 'none');
    const audioResponse = await page.request.get(await card.locator('audio').getAttribute('src'));
    expect(audioResponse.status()).toBe(200);
    expect((await audioResponse.body()).subarray(0, 4).toString()).toBe('RIFF');
    await card.locator('[data-review-field="recording_text"]').fill('Frontend reviewed text');
    await expect(card.locator('.ll-transcription-review__save-status')).toHaveText('Saved', { timeout: 60000 });
    await card.locator('[data-review-field="recording_ipa"]').fill('ʃa');
    await expect(card.locator('.ll-transcription-review__save-status')).toHaveText('Saved', { timeout: 60000 });
    await card.locator('[data-review-field="review_note"]').fill('Reviewed through the frontend.');
    await expect(card.locator('.ll-transcription-review__save-status')).toHaveText('Saved', { timeout: 60000 });
    let persisted = fixture('inspect');
    expect(persisted).toMatchObject({ recording_text: 'Frontend reviewed text', recording_ipa: 'ʃa', review_note: 'Reviewed through the frontend.' });

    const editorResponse = await page.goto(state.editorPath, { waitUntil: 'domcontentloaded' });
    expect(editorResponse.status()).toBe(200);
    const editTrigger = page.locator(`[data-ll-wordset-editor-open-word-edit][data-word-id="${state.wordId}"]`);
    await expect(editTrigger).toBeVisible({ timeout: 60000 });
    await editTrigger.click();
    const popup = page.locator('[data-ll-word-edit-panel][aria-hidden="false"]');
    await expect(popup).toBeVisible({ timeout: 60000 });
    await expect(popup.locator('.ll-word-edit-recording')).toHaveCount(2);
    const trigger = popup.locator(`[data-ll-word-copy][data-word-id="${state.wordId}"]`);
    await expect(trigger).toBeVisible();
    await trigger.click();
    const dialog = page.locator('.ll-word-copy-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[type="checkbox"]')).toHaveCount(2, { timeout: 60000 });
    await dialog.locator('#ll-word-copy-title').fill(state.copyTitle);
    await dialog.locator(`input[type="checkbox"][value="${state.recordingIds[1]}"]`).check();
    await dialog.locator('.ll-word-copy-button--primary').click();
    await expect(dialog.locator('.ll-word-copy-message')).toHaveText('Word copied.', { timeout: 90000 });
    expect(copyRequestId).toMatch(/^[a-f0-9-]{36}$/);
    persisted = fixture('inspect');
    expect(persisted.copies).toHaveLength(1);
    expect(persisted.copies[0].title).toBe(state.copyTitle);
    expect(persisted.parents).toEqual([state.wordId, persisted.copies[0].id]);
    await dialog.locator('.ll-word-copy-actions').getByRole('button', { name: 'Close', exact: true }).first().click();
    await expect(trigger).toBeFocused();
    await expect(popup.locator(`.ll-word-edit-recording[data-recording-id="${state.recordingIds[1]}"]`)).toHaveCount(0);
    await popup.locator('[data-ll-word-edit-cancel]').click();
    await editTrigger.click();
    await expect(popup).toBeVisible({ timeout: 60000 });
    await expect(popup.locator('.ll-word-edit-recording')).toHaveCount(1);

    await login(page, state.recorder);
    await page.goto(state.recorderPath, { waitUntil: 'domcontentloaded' });
    const history = page.locator('[data-ll-recording-history]');
    await expect(history).toBeVisible({ timeout: 60000 });
    await history.locator('summary').click();
    await expect(history.locator('.ll-recording-history__item')).toHaveCount(2, { timeout: 60000 });
    await expect(history).toContainText(state.wordTitle);
    await expect(history).toContainText(state.copyTitle);
    await expect(history.locator('audio')).toHaveCount(2);
    const denied = await page.request.post('/wp-admin/admin-ajax.php', { form: {
      action: 'll_tools_get_wordset_transcription_review', wordset_id: String(state.wordsetId), nonce: 'invalid'
    } });
    expect(denied.status()).toBe(403);
    expect(externalRequests.filter(host => /openai|elevenlabs|deepgram|assemblyai/i.test(host))).toEqual([]);
  } finally {
    if (cleanup) fixture('cleanup', fixtureRunId, copyRequestId);
  }
});
