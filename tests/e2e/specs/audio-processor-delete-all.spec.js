const { test, expect } = require('@playwright/test');
const path = require('path');

const audioProcessorJsPath = path.resolve(__dirname, '../../../js/audio-processor.js');

function buildMarkup(recordingCount) {
  const cards = Array.from({length: recordingCount}, (_, index) => {
    const id = index + 1;
    return `<label class="ll-recording-item" data-id="${id}">
      <input class="ll-recording-checkbox" type="checkbox" value="${id}"> Recording ${id}
      <button class="ll-delete-recording" type="button" data-post-id="${id}">Delete</button>
    </label>`;
  }).join('');

  return `<div class="ll-audio-processor-wrap" aria-busy="false">
    <div class="ll-processing-options">
      <input type="checkbox" id="ll-enable-trim" checked>
      <input type="checkbox" id="ll-enable-noise" checked>
      <input type="checkbox" id="ll-enable-loudness" checked>
    </div>
    <div class="ll-processor-controls">
      <button id="ll-select-all" type="button">Select All</button>
      <button id="ll-deselect-all" type="button">Deselect All</button>
      <button id="ll-process-selected" type="button" disabled>Process <span id="ll-selected-count">0</span></button>
      <button id="ll-delete-selected" type="button" disabled><span class="ll-btn-label">Delete Selected</span> <span id="ll-delete-selected-count">0</span></button>
    </div>
    <div id="ll-processor-status" style="display:none">
      <div class="ll-progress-fill"></div><span class="ll-status-text"></span>
    </div>
    <p id="ll-delete-status" role="status" aria-live="polite" aria-atomic="true"></p>
    <div class="ll-audio-processor-tabs" data-initial-tab="queue">
      <button type="button" class="ll-audio-processor-tab is-active" data-tab="queue">Queue</button>
    </div>
    <div class="ll-recordings-list is-active" data-tab="queue">${cards}</div>
    <div id="ll-review-interface" style="display:none">
      <div id="ll-review-files-container"></div>
      <button id="ll-save-all" type="button">Save All Changes</button>
      <button id="ll-delete-all-review" type="button">Delete All</button>
      <button id="ll-cancel-review" type="button">Cancel</button>
    </div>
  </div>`;
}

test('audio review Delete All bounds concurrency and retries only failed recordings', async ({ page }) => {
  const recordingCount = 5;
  await page.goto('about:blank');
  await page.setContent(buildMarkup(recordingCount));
  await page.evaluate((count) => {
    class FakeAudioBuffer {
      constructor(length = 1000, sampleRate = 1000) {
        this.length = length;
        this.sampleRate = sampleRate;
        this.numberOfChannels = 1;
        this.data = new Float32Array(length);
      }
      getChannelData() { return this.data; }
    }
    class FakeAudioContext {
      decodeAudioData() { return Promise.resolve(new FakeAudioBuffer()); }
      createBuffer(channels, length, sampleRate) { return new FakeAudioBuffer(length, sampleRate); }
    }
    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
    window.__deleteCalls = [];
    window.__activeDeletes = 0;
    window.__maxActiveDeletes = 0;
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'delete-nonce',
      deleteConcurrency: 2,
      deleteRequestTimeoutMs: 45,
      recordingTypes: [],
      recordingTypeIcons: {},
      recordings: Array.from({length: count}, (_, index) => ({
        id: index + 1,
        title: `Recording ${index + 1}`,
        wordText: `Recording ${index + 1}`,
        translationText: '',
        storeInTitle: true,
        parentWordId: 100 + index,
        audioUrl: `/audio-${index + 1}.wav`,
        categories: [],
        wordsets: [],
        recordingType: ''
      })),
      i18n: {
        deleteSelectedConfirmTemplate: 'Delete %d recording(s)?',
        deleteProgressTemplate: 'Deleting %1$d of %2$d...',
        deletePartialTemplate: 'Deleted %1$d recording(s). Failed to delete %2$d.',
        deleteRetryTemplate: 'Retry %d failed deletion(s)'
      }
    };

    window.fetch = (url, options = {}) => {
      if (String(options.method || '').toUpperCase() !== 'POST') {
        return Promise.resolve({arrayBuffer: async () => new ArrayBuffer(16)});
      }
      const id = Number(new URLSearchParams(options.body).get('post_id'));
      window.__deleteCalls.push(id);
      window.__activeDeletes += 1;
      window.__maxActiveDeletes = Math.max(window.__maxActiveDeletes, window.__activeDeletes);
      const delay = id === count ? 100 : 20;

      return new Promise((resolve, reject) => {
        let settled = false;
        const finish = (callback) => {
          if (settled) return;
          settled = true;
          window.__activeDeletes -= 1;
          callback();
        };
        const timer = setTimeout(() => finish(() => resolve({json: async () => ({success: true})})), delay);
        if (options.signal) {
          options.signal.addEventListener('abort', () => {
            clearTimeout(timer);
            finish(() => reject(new DOMException('Aborted', 'AbortError')));
          }, {once: true});
        }
      });
    };
  }, recordingCount);

  page.on('dialog', (dialog) => dialog.accept());
  await page.addScriptTag({path: audioProcessorJsPath});
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', {bubbles: true})));
  await page.locator('#ll-enable-trim').setChecked(false);
  await page.locator('#ll-enable-noise').setChecked(false);
  await page.locator('#ll-enable-loudness').setChecked(false);
  await page.locator('#ll-select-all').click();
  await page.locator('#ll-process-selected').click();
  await expect(page.locator('.ll-review-file')).toHaveCount(recordingCount);

  const deleteAll = page.locator('#ll-delete-all-review');
  await deleteAll.click();
  await expect(page.locator('.ll-audio-processor-wrap')).toHaveAttribute('aria-busy', 'true');
  await expect(deleteAll).toContainText(/Deleting \d of 5/);
  await expect(page.locator('#ll-delete-status')).toContainText(/Deleting \d of 5/);
  await expect(deleteAll).toHaveText('Retry 1 failed deletion(s)');
  await expect(page.locator('.ll-review-file')).toHaveCount(1);
  await expect(page.locator('.ll-audio-processor-wrap')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('#ll-delete-status')).toHaveText(
    'Deletion finished: 4 deleted and 1 failed. 1 failed deletion(s) are ready to retry.'
  );

  const firstRun = await page.evaluate(() => ({
    calls: window.__deleteCalls.slice(),
    maxActive: window.__maxActiveDeletes
  }));
  expect(firstRun.calls.sort((a, b) => a - b)).toEqual([1, 2, 3, 4, 5]);
  expect(firstRun.maxActive).toBeLessThanOrEqual(2);

  await deleteAll.click();
  await expect(deleteAll).toHaveText('Retry 1 failed deletion(s)');
  const allCalls = await page.evaluate(() => window.__deleteCalls.slice());
  expect(allCalls.filter((id) => id === 5)).toHaveLength(2);
  expect(allCalls.filter((id) => id !== 5)).toHaveLength(4);
});

test('individual queue deletion moves focus to the next surviving recording action', async ({ page }) => {
  const recordingCount = 3;
  await page.goto('about:blank');
  await page.setContent(buildMarkup(recordingCount));
  await page.evaluate((count) => {
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'delete-nonce',
      recordingTypes: [],
      recordingTypeIcons: {},
      recordings: Array.from({length: count}, (_, index) => ({
        id: index + 1,
        title: `Recording ${index + 1}`,
        wordText: `Recording ${index + 1}`,
        translationText: '',
        storeInTitle: true,
        parentWordId: 150 + index,
        audioUrl: `/audio-${index + 1}.wav`,
        categories: [],
        wordsets: [],
        recordingType: ''
      })),
      i18n: {
        deleteSingleConfirmTemplate: 'Delete "%s"?',
        deleteProgressTemplate: 'Deleting %1$d of %2$d...',
        deleteSingleSuccess: 'Recording deleted.'
      }
    };
    window.fetch = () => Promise.resolve({json: async () => ({success: true})});
  }, recordingCount);

  page.on('dialog', (dialog) => dialog.accept());
  await page.addScriptTag({path: audioProcessorJsPath});
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', {bubbles: true})));

  await page.locator('.ll-delete-recording[data-post-id="1"]').click();
  await expect(page.locator('.ll-recording-item[data-id="1"]')).toHaveCount(0);
  await expect(page.locator('.ll-delete-recording[data-post-id="2"]')).toBeFocused();
});

test('removing a review card skips its slide-and-fade delay under reduced motion', async ({ page }) => {
  const recordingCount = 2;
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('about:blank');
  await page.setContent(buildMarkup(recordingCount));
  await page.evaluate((count) => {
    class FakeAudioBuffer {
      constructor(length = 1000, sampleRate = 1000) {
        this.length = length;
        this.sampleRate = sampleRate;
        this.numberOfChannels = 1;
        this.data = new Float32Array(length);
      }
      getChannelData() { return this.data; }
    }
    class FakeAudioContext {
      decodeAudioData() { return Promise.resolve(new FakeAudioBuffer()); }
      createBuffer(channels, length, sampleRate) { return new FakeAudioBuffer(length, sampleRate); }
    }
    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
    window.confirm = () => true;
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'delete-nonce',
      recordingTypes: [],
      recordingTypeIcons: {},
      recordings: Array.from({length: count}, (_, index) => ({
        id: index + 1,
        title: `Recording ${index + 1}`,
        wordText: `Recording ${index + 1}`,
        translationText: '',
        storeInTitle: true,
        parentWordId: 175 + index,
        audioUrl: `/audio-${index + 1}.wav`,
        categories: [],
        wordsets: [],
        recordingType: ''
      })),
      i18n: {
        removeFromBatchConfirmTemplate: 'Remove "%s"?'
      }
    };
    window.fetch = () => Promise.resolve({arrayBuffer: async () => new ArrayBuffer(16)});
  }, recordingCount);

  await page.addScriptTag({path: audioProcessorJsPath});
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', {bubbles: true})));
  await page.locator('#ll-enable-trim').setChecked(false);
  await page.locator('#ll-enable-noise').setChecked(false);
  await page.locator('#ll-enable-loudness').setChecked(false);
  await page.locator('#ll-select-all').click();
  await page.locator('#ll-process-selected').click();
  await expect(page.locator('.ll-review-file')).toHaveCount(recordingCount);

  const immediateState = await page.evaluate(() => {
    document.querySelector('.ll-remove-review-btn[data-post-id="1"]').click();
    const card = document.querySelector('.ll-review-file[data-post-id="1"]');
    return {
      transition: card.style.transition,
      opacity: card.style.opacity,
      transform: card.style.transform,
      ariaHidden: card.getAttribute('aria-hidden')
    };
  });

  expect(immediateState).toEqual({
    transition: 'none',
    opacity: '',
    transform: '',
    ariaHidden: 'true'
  });
  await expect(page.locator('.ll-review-file')).toHaveCount(1);
});

test('remove is synchronous and individual deletion holds the shared delete mutex', async ({ page }) => {
  const recordingCount = 4;
  await page.goto('about:blank');
  await page.setContent(buildMarkup(recordingCount));
  await page.evaluate((count) => {
    class FakeAudioBuffer {
      constructor(length = 1000, sampleRate = 1000) {
        this.length = length;
        this.sampleRate = sampleRate;
        this.numberOfChannels = 1;
        this.data = new Float32Array(length);
      }
      getChannelData() { return this.data; }
    }
    class FakeAudioContext {
      decodeAudioData() { return Promise.resolve(new FakeAudioBuffer()); }
      createBuffer(channels, length, sampleRate) { return new FakeAudioBuffer(length, sampleRate); }
    }
    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
    window.__deleteCalls = [];
    window.__activeDeletes = 0;
    window.__maxActiveDeletes = 0;
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'delete-nonce',
      deleteConcurrency: 2,
      deleteRequestTimeoutMs: 250,
      recordingTypes: [],
      recordingTypeIcons: {},
      recordings: Array.from({length: count}, (_, index) => ({
        id: index + 1,
        title: `Recording ${index + 1}`,
        wordText: `Recording ${index + 1}`,
        translationText: '',
        storeInTitle: true,
        parentWordId: 200 + index,
        audioUrl: `/audio-${index + 1}.wav`,
        categories: [],
        wordsets: [],
        recordingType: ''
      })),
      i18n: {
        deleteSelectedConfirmTemplate: 'Delete %d recording(s)?',
        deleteSingleConfirmTemplate: 'Delete "%s"?',
        removeFromBatchConfirmTemplate: 'Remove "%s"?',
        deleteProgressTemplate: 'Deleting %1$d of %2$d...',
        deletePartialTemplate: 'Deleted %1$d recording(s). Failed to delete %2$d.',
        deleteRetryTemplate: 'Retry %d failed deletion(s)',
        deleteSingleSuccess: 'Recording deleted.',
        deleteFailureRetryStatusTemplate: 'Deletion finished: %1$d deleted and %2$d failed. %3$d failed deletion(s) are ready to retry.'
      }
    };

    window.fetch = (url, options = {}) => {
      if (String(options.method || '').toUpperCase() !== 'POST') {
        return Promise.resolve({arrayBuffer: async () => new ArrayBuffer(16)});
      }
      const id = Number(new URLSearchParams(options.body).get('post_id'));
      window.__deleteCalls.push(id);
      window.__activeDeletes += 1;
      window.__maxActiveDeletes = Math.max(window.__maxActiveDeletes, window.__activeDeletes);
      const delay = id === 2 || id === 4 ? 100 : 20;

      return new Promise((resolve, reject) => {
        let settled = false;
        const finish = (callback) => {
          if (settled) return;
          settled = true;
          window.__activeDeletes -= 1;
          callback();
        };
        const timer = setTimeout(() => finish(() => resolve({
          json: async () => ({success: id !== 4})
        })), delay);
        if (options.signal) {
          options.signal.addEventListener('abort', () => {
            clearTimeout(timer);
            finish(() => reject(new DOMException('Aborted', 'AbortError')));
          }, {once: true});
        }
      });
    };
  }, recordingCount);

  page.on('dialog', (dialog) => dialog.accept());
  await page.addScriptTag({path: audioProcessorJsPath});
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', {bubbles: true})));
  await page.locator('#ll-enable-trim').setChecked(false);
  await page.locator('#ll-enable-noise').setChecked(false);
  await page.locator('#ll-enable-loudness').setChecked(false);
  await page.locator('#ll-select-all').click();
  await page.locator('#ll-process-selected').click();
  await expect(page.locator('.ll-review-file')).toHaveCount(recordingCount);

  await page.locator('.ll-remove-review-btn[data-post-id="1"]').click();
  const individualDelete = page.locator('.ll-delete-review-btn[data-post-id="2"]');
  await individualDelete.click();
  await expect(individualDelete).toHaveText('Deleting...');
  await expect(page.locator('.ll-audio-processor-wrap')).toHaveAttribute('aria-busy', 'true');

  await page.evaluate(() => {
    document.getElementById('ll-delete-all-review').dispatchEvent(new MouseEvent('click', {bubbles: true}));
  });
  await page.waitForTimeout(30);
  expect(await page.evaluate(() => window.__deleteCalls.slice())).toEqual([2]);

  await expect(page.locator('.ll-review-file[data-post-id="2"]')).toHaveCount(0);
  await expect(page.locator('.ll-review-file[data-post-id="1"]')).toHaveCount(0);
  await expect(page.locator('.ll-delete-review-btn[data-post-id="3"]')).toBeFocused();

  const failedIndividualDelete = page.locator('.ll-delete-review-btn[data-post-id="4"]');
  await failedIndividualDelete.click();
  await expect(failedIndividualDelete).toHaveText('Deleting...');
  await expect(failedIndividualDelete).toHaveText('Delete');
  await expect(failedIndividualDelete).toBeEnabled();

  await page.locator('#ll-delete-all-review').click();
  await expect(page.locator('#ll-delete-all-review')).toHaveText('Retry 1 failed deletion(s)');

  const result = await page.evaluate(() => ({
    calls: window.__deleteCalls.slice(),
    maxActive: window.__maxActiveDeletes
  }));
  expect(result.calls).toEqual([2, 4, 3, 4]);
  expect(result.maxActive).toBeLessThanOrEqual(2);
  await expect(page.locator('#ll-delete-status')).toHaveText(
    'Deletion finished: 1 deleted and 1 failed. 1 failed deletion(s) are ready to retry.'
  );
});

test('individual deletion stays fenced while audio processing is in flight', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(buildMarkup(1));
  await page.evaluate(() => {
    class FakeAudioBuffer {
      constructor(length = 1000, sampleRate = 1000) {
        this.length = length;
        this.sampleRate = sampleRate;
        this.numberOfChannels = 1;
        this.data = new Float32Array(length);
      }
      getChannelData() { return this.data; }
    }
    class FakeAudioContext {
      decodeAudioData() { return Promise.resolve(new FakeAudioBuffer()); }
      createBuffer(channels, length, sampleRate) { return new FakeAudioBuffer(length, sampleRate); }
    }
    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
    window.__deleteCalls = [];
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'delete-nonce',
      processRequestTimeoutMs: 40,
      recordingTypes: [],
      recordingTypeIcons: {},
      recordings: [{
        id: 1,
        title: 'Recording 1',
        wordText: 'Recording 1',
        translationText: '',
        storeInTitle: true,
        parentWordId: 101,
        audioUrl: '/audio-1.wav',
        categories: [],
        wordsets: [],
        recordingType: ''
      }],
      i18n: {
        deleteSingleConfirmTemplate: 'Delete "%s"?',
        deleteProgressTemplate: 'Deleting %1$d of %2$d...',
        deleteSingleSuccess: 'Recording deleted.',
        processingTimeoutTemplate: 'Processing %s took too long. It was skipped; select it and try again.',
        processingNoneCompleted: 'No recordings were processed. The skipped recordings remain selected; try again.'
      }
    };

    window.fetch = (url, options = {}) => {
      if (String(options.method || '').toUpperCase() === 'POST') {
        window.__deleteCalls.push(Number(new URLSearchParams(options.body).get('post_id')));
        return Promise.resolve({json: async () => ({success: true})});
      }
      window.__audioFetchAttempts = (window.__audioFetchAttempts || 0) + 1;
      if (window.__audioFetchAttempts === 1) {
        return new Promise(() => {});
      }
      return Promise.resolve({arrayBuffer: async () => new ArrayBuffer(16)});
    };
  });

  page.on('dialog', (dialog) => dialog.accept());
  await page.addScriptTag({path: audioProcessorJsPath});
  await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded', {bubbles: true})));
  await page.locator('#ll-enable-trim').setChecked(false);
  await page.locator('#ll-enable-noise').setChecked(false);
  await page.locator('#ll-enable-loudness').setChecked(false);
  await page.locator('#ll-select-all').click();
  await page.locator('#ll-process-selected').click();

  const rowDelete = page.locator('.ll-delete-recording[data-post-id="1"]');
  await expect(rowDelete).toBeDisabled();
  await page.evaluate(() => {
    document.querySelector('.ll-delete-recording[data-post-id="1"]')
      .dispatchEvent(new MouseEvent('click', {bubbles: true}));
  });
  await page.waitForTimeout(25);
  expect(await page.evaluate(() => window.__deleteCalls.slice())).toEqual([]);

  await expect(page.locator('.ll-status-text')).toHaveText(
    'No recordings were processed. The skipped recordings remain selected; try again.',
    {timeout: 5000}
  );
  await expect(page.locator('#ll-process-selected')).toBeEnabled();
  expect(await page.evaluate(() => window.__audioFetchAttempts)).toBe(1);

  await page.locator('#ll-process-selected').click();
  const reviewDelete = page.locator('.ll-delete-review-btn[data-post-id="1"]');
  await expect(reviewDelete).toBeVisible();
  await expect(reviewDelete).toBeEnabled();
  expect(await page.evaluate(() => window.__audioFetchAttempts)).toBe(2);
  await reviewDelete.click();
  await expect.poll(() => page.evaluate(() => window.__deleteCalls.slice())).toEqual([1]);
});
