const { test, expect } = require('@playwright/test');
const path = require('path');

const audioProcessorJsPath = path.resolve(__dirname, '../../../js/audio-processor.js');

function queuePanel(tab, active = false, page = 1) {
  return `
    <div class="ll-recordings-list ${active ? 'is-active' : ''}" data-tab="${tab}" data-page="${page}" data-loaded="false">
      <div class="ll-queue-status is-loading">
        <span class="ll-queue-status-text">Loading recordings...</span>
        <button type="button" class="ll-queue-retry" hidden>Retry</button>
      </div>
      <div class="ll-queue-items"></div>
      <div class="ll-queue-pagination" hidden>
        <button type="button" class="ll-queue-page-previous">Previous</button>
        <span class="ll-queue-page-label"></span>
        <button type="button" class="ll-queue-page-next">Next</button>
      </div>
    </div>
  `;
}

async function mountAudioProcessor(page, options = {}) {
  const initialPage = options.initialPage || 1;
  const pageUrl = options.url || 'http://audio-processor.test/wp-admin/tools.php?page=ll-audio-processor';
  await page.route('http://audio-processor.test/**', route => route.fulfill({ status: 200, body: '<!doctype html>' }));
  await page.goto(pageUrl);
  await page.setContent(`
    <div class="ll-audio-processor-tabs" data-initial-tab="queue" data-auto-select-work="true">
      <button type="button" class="ll-audio-processor-tab is-active" data-tab="queue"><span class="ll-tab-count" data-tab-count="queue">...</span></button>
      <button type="button" class="ll-audio-processor-tab" data-tab="duplicates"><span class="ll-tab-count" data-tab-count="duplicates">...</span></button>
      <button type="button" class="ll-audio-processor-tab" data-tab="reprocess"><span class="ll-tab-count" data-tab-count="reprocess">...</span></button>
    </div>
    <button id="ll-select-all" type="button">Select all</button>
    <button id="ll-deselect-all" type="button">Deselect all</button>
    <button id="ll-process-selected" type="button" disabled>Process <span id="ll-selected-count">0</span></button>
    <button id="ll-delete-selected" type="button" disabled>Delete <span id="ll-delete-selected-count">0</span></button>
    ${queuePanel('queue', true, initialPage)}
    ${queuePanel('duplicates')}
    ${queuePanel('reprocess')}
  `);

  await page.evaluate((fixtureOptions) => {
    window.AudioContext = class FakeAudioContext {};
    window.__queueFetchCalls = [];
    window.__reprocessAttempts = 0;
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'queue-nonce',
      recordings: [],
      recordingTypes: [],
      recordingTypeIcons: {},
      i18n: {
        queueLoading: 'Loading recordings...',
        queueLoadFailed: 'Could not load recordings. Please try again.',
        queueRetry: 'Retry',
        queueEmpty: 'Queue is empty.',
        duplicatesEmpty: 'No duplicates found.',
        reprocessEmpty: 'Nothing to reprocess.',
        queuePageTemplate: 'Page %d',
        queueCountMoreTemplate: '%d+'
      }
    };

    const responseFor = (data, ok = true) => ({
      ok,
      json: async () => ok ? ({ success: true, data }) : ({ success: false })
    });
    const recording = (id, title) => ({
      id,
      title,
      wordText: title,
      translationText: '',
      storeInTitle: true,
      parentWordId: id + 1000,
      audioUrl: `/audio-${id}.mp3`,
      categories: [],
      wordsets: [],
      recordingType: ''
    });
    const card = (id, title, includeSplitLink = false) => `
      <div class="ll-recording-item" data-id="${id}">
        <label><input type="checkbox" class="ll-recording-checkbox" value="${id}"> ${title}</label>
        ${includeSplitLink ? `<a
          class="ll-split-word-link"
          href="/wp-admin/post.php?post=${id + 1000}&action=ll_tools_split_word"
          data-split-word-url="/wp-admin/post.php?post=${id + 1000}&action=ll_tools_split_word"
          data-return-base-url="/wp-admin/tools.php?page=ll-audio-processor"
        >Split word</a>` : ''}
      </div>
    `;

    window.fetch = async (url, options) => {
      const params = new URLSearchParams(options.body);
      const tab = params.get('tab');
      const requestedPage = parseInt(params.get('page'), 10);
      window.__queueFetchCalls.push(`${tab}:${requestedPage}`);
      await new Promise(resolve => setTimeout(resolve, 80));

      if (tab === 'queue' && requestedPage === 1) {
        if (fixtureOptions.autoFallback) {
          return responseFor({ tab, page: 1, perPage: 40, hasMore: false, knownCount: 0, recordings: [], html: '' });
        }
        return responseFor({
          tab,
          page: 1,
          perPage: 40,
          hasMore: true,
          knownCount: 2,
          recordings: [recording(101, 'First'), recording(102, 'Second')],
          html: `${card(101, 'First')}${card(102, 'Second')}`
        });
      }
      if (tab === 'queue' && requestedPage === 2) {
        return responseFor({
          tab,
          page: 2,
          perPage: 40,
          hasMore: false,
          knownCount: 3,
          recordings: [recording(103, 'Third')],
          html: card(103, 'Third', true)
        });
      }
      if (tab === 'duplicates') {
        if (fixtureOptions.autoFallback) {
          return responseFor({
            tab,
            page: 1,
            perPage: 40,
            hasMore: false,
            knownCount: 1,
            recordings: [recording(201, 'Duplicate Work')],
            html: card(201, 'Duplicate Work')
          });
        }
        return responseFor({ tab, page: 1, perPage: 40, hasMore: false, knownCount: 0, recordings: [], html: '' });
      }
      if (tab === 'reprocess') {
        window.__reprocessAttempts += 1;
        if (window.__reprocessAttempts === 1) {
          return responseFor({}, false);
        }
        return responseFor({ tab, page: 1, perPage: 40, hasMore: false, knownCount: 0, recordings: [], html: '' });
      }

      return responseFor({}, false);
    };
  }, { autoFallback: !!options.autoFallback });

  await page.addScriptTag({ path: audioProcessorJsPath });
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true })));
}

test('audio processor lazily pages queues and keeps page-local selection behavior', async ({ page }) => {
  await mountAudioProcessor(page);

  const queue = page.locator('.ll-recordings-list[data-tab="queue"]');
  await expect(queue.locator('.ll-queue-status-text')).toHaveText('Loading recordings...');
  await expect(queue.locator('.ll-recording-item')).toHaveCount(2);
  expect(await page.evaluate(() => window.__queueFetchCalls)).toEqual(['queue:1']);

  const checkboxes = queue.locator('.ll-recording-checkbox');
  await checkboxes.nth(0).check();
  await checkboxes.nth(1).click({ modifiers: ['Shift'] });
  await expect(page.locator('#ll-selected-count')).toHaveText('2');
  await expect(page.locator('#ll-process-selected')).toBeEnabled();

  await queue.locator('.ll-queue-page-next').click();
  await expect(queue.locator('.ll-queue-page-label')).toHaveText('Page 2');
  await expect(queue.locator('.ll-recording-item')).toHaveCount(1);
  await expect(page.locator('#ll-selected-count')).toHaveText('0');
  await expect(queue.locator('.ll-queue-page-next')).toBeDisabled();
  await expect(queue.locator('.ll-queue-page-previous')).toBeEnabled();

  const splitHref = await queue.locator('.ll-split-word-link').evaluate(link => {
    link.addEventListener('click', event => event.preventDefault(), { once: true });
    link.click();
    return link.href;
  });
  const splitUrl = new URL(splitHref);
  const returnUrl = new URL(splitUrl.searchParams.get('ll_return_to'));
  expect(returnUrl.searchParams.get('ll_ap_tab')).toBe('queue');
  expect(returnUrl.searchParams.get('ll_ap_page')).toBe('2');
  expect(returnUrl.searchParams.get('ll_ap_focus_recording')).toBe('103');

  await page.locator('.ll-audio-processor-tab[data-tab="duplicates"]').click();
  const duplicates = page.locator('.ll-recordings-list[data-tab="duplicates"]');
  await expect(duplicates.locator('.ll-queue-status-text')).toHaveText('No duplicates found.');
  expect(await page.evaluate(() => window.__queueFetchCalls)).toEqual(['queue:1', 'queue:2', 'duplicates:1']);

  await page.locator('.ll-audio-processor-tab[data-tab="reprocess"]').click();
  const reprocess = page.locator('.ll-recordings-list[data-tab="reprocess"]');
  await expect(reprocess.locator('.ll-queue-status-text')).toHaveText('Could not load recordings. Please try again.');
  await expect(reprocess.locator('.ll-queue-retry')).toBeVisible();
  await reprocess.locator('.ll-queue-retry').click();
  await expect(reprocess.locator('.ll-queue-status-text')).toHaveText('Nothing to reprocess.');
});

test('audio processor restores and focuses a recording on a returned queue page', async ({ page }) => {
  await mountAudioProcessor(page, {
    initialPage: 2,
    url: 'http://audio-processor.test/wp-admin/tools.php?page=ll-audio-processor&ll_ap_tab=queue&ll_ap_page=2&ll_ap_focus_recording=103'
  });

  const returnedItem = page.locator('.ll-recording-item[data-id="103"]');
  await expect(returnedItem).toBeVisible();
  await expect(returnedItem).toHaveClass(/is-return-focus/);
  await expect(page).toHaveURL(/page=ll-audio-processor/);
  await expect(page).not.toHaveURL(/ll_ap_page=/);
  await expect(page).not.toHaveURL(/ll_ap_focus_recording=/);
  expect(await page.evaluate(() => window.__queueFetchCalls)).toEqual(['queue:2']);
});

test('audio processor opens the first non-empty work tab when the default queue is empty', async ({ page }) => {
  await mountAudioProcessor(page, { autoFallback: true });

  const duplicatesTab = page.locator('.ll-audio-processor-tab[data-tab="duplicates"]');
  const duplicates = page.locator('.ll-recordings-list[data-tab="duplicates"]');
  await expect(duplicatesTab).toHaveAttribute('aria-selected', 'true');
  await expect(duplicates).toHaveClass(/is-active/);
  await expect(duplicates.locator('.ll-recording-item[data-id="201"]')).toBeVisible();
  expect(await page.evaluate(() => window.__queueFetchCalls)).toEqual(['queue:1', 'duplicates:1']);
});
