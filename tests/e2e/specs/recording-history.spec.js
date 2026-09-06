const path = require('path');
const { test, expect } = require('@playwright/test');

const script = path.resolve(__dirname, '../../../js/recording-history.js');
const stylesheet = path.resolve(__dirname, '../../../css/recording-history.css');

function fixture() {
  return `<details class="ll-recording-history" data-ll-recording-history data-wordset-id="17">
    <summary class="ll-recording-history__toggle">My recordings</summary>
    <div class="ll-recording-history__body" data-history-body aria-busy="false">
      <p class="ll-recording-history__message" data-history-message role="status" aria-live="polite"></p>
      <button class="ll-recording-history__button" type="button" data-history-retry hidden>Retry</button>
      <ul class="ll-recording-history__list" data-history-list></ul>
      <nav class="ll-recording-history__nav" data-history-nav hidden aria-label="Recording history pages">
        <button class="ll-recording-history__button" type="button" data-history-previous disabled>Newer</button>
        <button class="ll-recording-history__button" type="button" data-history-next disabled>Older</button>
        <button class="ll-recording-history__button" type="button" data-history-refresh>Refresh</button>
      </nav>
    </div>
  </details>`;
}

function recording(id, extra = {}) {
  return { id, title: `Word ${id}`, word_url: `https://example.org/words/${id}/`,
    categories: [{ id: 3, name: 'Everyday words', slug: 'everyday' }], recording_type: 'Isolation',
    timestamp: 1788678000, date: 'September 6, 2026 10:00', status: 'processing',
    status_label: 'Awaiting processing', audio_url: `https://example.org/recording-${id}.mp3`, ...extra };
}

async function setup(page, pages = [], timeout = 15000) {
  await page.setContent(fixture());
  await page.addStyleTag({ path: stylesheet });
  await page.evaluate(({ pages, timeout }) => {
    window.llRecordingHistory = {
      ajaxUrl: 'https://example.org/wp-admin/admin-ajax.php', nonce: 'history-nonce', locale: 'en_US',
      recorderUrl: 'https://example.org/record/?ll_record_word=91', requestTimeoutMs: timeout,
      strings: { loading: 'Loading recordings…', empty: 'No recordings to show.',
        error: 'Recordings could not be loaded. Please try again.', playback: 'Play recording',
        playbackError: 'This recording could not be played.', unavailable: 'Audio unavailable' }
    };
    window.historyCalls = [];
    window.historyPages = pages;
    window.fetch = async (_url, options) => {
      window.historyCalls.push(Object.fromEntries(options.body.entries()));
      const data = window.historyPages.shift();
      if (!data || data.fail) { return new Response(JSON.stringify({ success: false }), { status: 403 }); }
      return new Response(JSON.stringify({ success: true, data }), { status: 200 });
    };
  }, { pages, timeout });
  await page.addScriptTag({ path: script });
}

test('history opens lazily and shows attributed recording metadata and bounded playback', async ({ page }) => {
  await setup(page, [{ items: [recording(9), recording(8)], has_more: false, next_cursor: '' }]);
  expect(await page.evaluate(() => window.historyCalls)).toEqual([]);
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.locator('[data-history-list] > li')).toHaveCount(2);
  await expect(page.getByRole('link', { name: 'Word 9', exact: true })).toHaveAttribute('href', 'https://example.org/words/9/');
  await expect(page.getByRole('link', { name: 'Everyday words' }).first()).toHaveAttribute('href', 'https://example.org/record/?ll_record_wordset=17&ll_record_category=everyday');
  await expect(page.getByText('Awaiting processing', { exact: true })).toHaveCount(2);
  await expect(page.locator('audio').first()).toHaveAttribute('preload', 'none');
  await expect(page.locator('audio').first()).toHaveAttribute('aria-label', 'Play recording: Word 9');
  await expect(page.getByRole('button', { name: 'Older', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Newer', exact: true })).toBeDisabled();
  expect(await page.evaluate(() => window.historyCalls)).toEqual([{
    action: 'll_tools_recording_history', nonce: 'history-nonce', wordset_id: '17', cursor: '', locale: 'en_US'
  }]);
  await page.evaluate(() => {
    window.pausedHistoryPlayers = [];
    document.querySelectorAll('audio').forEach((player, index) => {
      player.pause = () => { window.pausedHistoryPlayers.push(index); };
    });
    document.querySelectorAll('audio')[1].dispatchEvent(new Event('play'));
  });
  expect(await page.evaluate(() => window.pausedHistoryPlayers)).toEqual([0]);
});

test('older/newer cursors advance only after success and refresh returns to newest', async ({ page }) => {
  await setup(page, [
    { items: [recording(9)], has_more: true, next_cursor: 'signed-older' },
    { items: [recording(7)], has_more: false, next_cursor: '' },
    { items: [recording(9)], has_more: true, next_cursor: 'signed-older' },
    { items: [recording(10)], has_more: true, next_cursor: 'fresh-older' }
  ]);
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.getByRole('button', { name: 'Older', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Older', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Word 7', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Newer', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Word 9', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Word 10', exact: true })).toBeVisible();
  expect(await page.evaluate(() => window.historyCalls.map(call => call.cursor))).toEqual(['', 'signed-older', '', '']);
});

test('failure clears private rows and Retry repeats the failed page without losing navigation', async ({ page }) => {
  await setup(page, [
    { items: [recording(9)], has_more: true, next_cursor: 'signed-older' },
    { fail: true },
    { items: [recording(7)], has_more: false, next_cursor: '' }
  ]);
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.getByRole('button', { name: 'Older', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Older', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
  await expect(page.locator('audio')).toHaveCount(0);
  await expect(page.locator('[data-history-message]')).toHaveText('Recordings could not be loaded. Please try again.');
  await expect(page.locator('[data-history-body]')).toHaveAttribute('aria-busy', 'false');
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Word 7', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Newer', exact: true })).toBeEnabled();
  expect(await page.evaluate(() => window.historyCalls.map(call => call.cursor))).toEqual(['', 'signed-older', 'signed-older']);
});

test('a stalled response body times out and allows retry', async ({ page }) => {
  await setup(page, [], 100);
  await page.evaluate(() => {
    window.fetch = async () => ({ ok: true, json: () => new Promise(() => {}) });
  });
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
  await expect(page.locator('[data-history-body]')).toHaveAttribute('aria-busy', 'false');
  await page.evaluate(() => {
    window.fetch = async () => new Response(JSON.stringify({ success: true,
      data: { items: [], has_more: false, next_cursor: '' } }), { status: 200 });
  });
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(page.locator('[data-history-message]')).toHaveText('No recordings to show.');
});

test('closing aborts and clears media, and late responses cannot populate a reopened panel', async ({ page }) => {
  await setup(page);
  await page.evaluate(() => {
    window.historyResolvers = [];
    window.historySignals = [];
    window.fetch = (_url, options) => {
      window.historySignals.push(options.signal);
      return new Promise(resolve => { window.historyResolvers.push(resolve); });
    };
  });
  await page.getByText('My recordings', { exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.historyResolvers.length)).toBe(1);
  await page.getByText('My recordings', { exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.historySignals[0].aborted)).toBe(true);
  await page.getByText('My recordings', { exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.historyResolvers.length)).toBe(2);
  await page.evaluate(item => {
    window.historyResolvers[0](new Response(JSON.stringify({ success: true,
      data: { items: [item], has_more: false, next_cursor: '' } }), { status: 200 }));
  }, recording(9));
  await expect(page.locator('[data-history-list] > li')).toHaveCount(0);
  await expect(page.locator('[data-history-body]')).toHaveAttribute('aria-busy', 'true');
  await page.evaluate(item => {
    window.historyResolvers[1](new Response(JSON.stringify({ success: true,
      data: { items: [item], has_more: false, next_cursor: '' } }), { status: 200 }));
  }, recording(10));
  await expect(page.getByRole('link', { name: 'Word 10', exact: true })).toBeVisible();
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.locator('audio')).toHaveCount(0);
});

test('empty filtered page can continue, and unsafe URLs or labels do not become executable markup', async ({ page }) => {
  await setup(page, [
    { items: [], has_more: true, next_cursor: 'after-filtered' },
    { items: [recording(7, { title: '<img src=x onerror=alert(1)>', word_url: 'javascript:alert(1)',
      audio_url: 'javascript:alert(1)' })], has_more: false, next_cursor: '' }
  ]);
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.locator('[data-history-message]')).toHaveText('No recordings to show.');
  await expect(page.getByRole('button', { name: 'Older', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Older', exact: true }).click();
  await expect(page.locator('.ll-recording-history__title')).toHaveText('<img src=x onerror=alert(1)>');
  await expect(page.locator('img')).toHaveCount(0);
  await expect(page.locator('audio')).toHaveCount(0);
  await expect(page.getByText('Audio unavailable', { exact: true })).toBeVisible();
});

test('compact mobile history stays within the viewport under theme button styles', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 760 });
  await setup(page, [{ items: [recording(9, { title: 'LongRecordingWord'.repeat(12) })], has_more: false, next_cursor: '' }]);
  await page.addStyleTag({ content: 'button { padding: 35px; text-transform: uppercase; background: red; } ul { margin-left: 60px; }' });
  await page.getByText('My recordings', { exact: true }).click();
  await expect(page.locator('audio')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const refresh = page.getByRole('button', { name: 'Refresh', exact: true });
  expect((await refresh.boundingBox()).height).toBeGreaterThanOrEqual(44);
  expect(await refresh.evaluate(button => getComputedStyle(button).textTransform)).toBe('none');
});
