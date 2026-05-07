const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const audioProcessorCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/audio-processor.css'),
  'utf8'
);
const audioProcessorJsPath = path.resolve(__dirname, '../../../js/audio-processor.js');

function buildAudioProcessorMarkup() {
  return `
    <div class="wrap ll-audio-processor-wrap">
      <div class="ll-processing-options">
        <label><input type="checkbox" id="ll-enable-trim" checked> Trim</label>
        <label><input type="checkbox" id="ll-enable-noise" checked> Noise</label>
        <label><input type="checkbox" id="ll-enable-loudness" checked> Loudness</label>
      </div>
      <div class="ll-processor-controls">
        <button id="ll-select-all" type="button">Select All</button>
        <button id="ll-deselect-all" type="button">Deselect All</button>
        <button id="ll-process-selected" type="button" disabled>Process Selected (<span id="ll-selected-count">0</span>)</button>
        <button id="ll-delete-selected" type="button" disabled>Delete Selected (<span id="ll-delete-selected-count">0</span>)</button>
      </div>
      <div id="ll-processor-status" class="ll-processor-status" style="display:none;">
        <div class="ll-progress-bar"><div class="ll-progress-fill" style="width:0%"></div></div>
        <p class="ll-status-text">Processing...</p>
      </div>
      <div class="ll-audio-processor-tabs" role="tablist" data-initial-tab="queue">
        <button type="button" class="ll-audio-processor-tab is-active" data-tab="queue">Queue</button>
      </div>
      <div class="ll-recordings-list is-active" data-tab="queue" role="tabpanel">
        <label class="ll-recording-item" data-id="101">
          <input class="ll-recording-checkbox" type="checkbox" value="101">
          Recording 101
        </label>
      </div>
      <div id="ll-review-interface" class="ll-review-interface" style="display:none;">
        <div id="ll-review-files-container"></div>
      </div>
    </div>
  `;
}

async function installAudioProcessorFakes(page) {
  await page.evaluate(() => {
    class FakeAudioBuffer {
      constructor(length = 10000, sampleRate = 1000, withSignal = false) {
        this.length = length;
        this.sampleRate = sampleRate;
        this.numberOfChannels = 1;
        this._data = new Float32Array(length);

        if (withSignal) {
          for (let i = 2000; i < 8000; i += 1) {
            this._data[i] = i % 2 === 0 ? 0.45 : -0.45;
          }
        }
      }

      getChannelData() {
        return this._data;
      }
    }

    class FakeAudioContext {
      decodeAudioData() {
        return Promise.resolve(new FakeAudioBuffer(10000, 1000, true));
      }

      createBuffer(channels, length, sampleRate) {
        return new FakeAudioBuffer(length, sampleRate, false);
      }
    }

    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
    window.fetch = async () => ({
      arrayBuffer: async () => new ArrayBuffer(16),
    });
    window.llAudioProcessor = {
      ajaxUrl: '/wp-admin/admin-ajax.php',
      nonce: 'test-nonce',
      recordingTypes: [],
      recordingTypeIcons: {},
      recordings: [
        {
          id: 101,
          title: 'Resize target',
          wordText: 'Resize target',
          translationText: '',
          storeInTitle: true,
          parentWordId: 501,
          audioUrl: '/fake-audio.wav',
          categories: ['Test'],
          wordsets: [],
          recordingType: '',
        },
      ],
      i18n: {},
    };
  });
}

async function mountProcessedReview(page) {
  await page.setViewportSize({ width: 900, height: 720 });
  await page.goto('about:blank');
  await page.setContent(buildAudioProcessorMarkup());
  await page.addStyleTag({
    content: `
      html,
      body {
        margin: 0;
        padding: 0;
      }

      body {
        min-height: 100vh;
      }
    `,
  });
  await page.addStyleTag({ content: audioProcessorCssSource });
  await installAudioProcessorFakes(page);
  await page.addScriptTag({ path: audioProcessorJsPath });
  await page.evaluate(() => {
    document.dispatchEvent(new Event('DOMContentLoaded', { bubbles: true }));
  });

  await page.locator('#ll-enable-noise').setChecked(false);
  await page.locator('#ll-enable-loudness').setChecked(false);
  await page.locator('.ll-recording-checkbox').check();
  await page.locator('#ll-process-selected').click();
  await expect(page.locator('.ll-review-file')).toBeVisible({ timeout: 5000 });
  await expect(page.locator('.ll-trim-boundary.ll-start')).toBeVisible();
  await expect(page.locator('.ll-trim-boundary.ll-end')).toBeVisible();
}

async function getWaveformMetrics(page) {
  return page.locator('.ll-waveform-container').evaluate((container) => {
    const canvas = container.querySelector('.ll-waveform-canvas');
    const start = container.querySelector('.ll-trim-boundary.ll-start');
    const end = container.querySelector('.ll-trim-boundary.ll-end');
    const containerRect = container.getBoundingClientRect();
    const canvasRect = canvas.getBoundingClientRect();
    const startRect = start.getBoundingClientRect();
    const endRect = end.getBoundingClientRect();

    return {
      containerWidth: containerRect.width,
      canvasWidth: canvasRect.width,
      startOffset: startRect.left - containerRect.left,
      endOffset: endRect.left - containerRect.left,
      expectedStartOffset: (parseFloat(start.style.left) / 100) * canvasRect.width,
      expectedEndOffset: (parseFloat(end.style.left) / 100) * canvasRect.width,
    };
  });
}

test('audio processor waveform redraws to the resized review width without drifting from trim boundaries', async ({ page }) => {
  await mountProcessedReview(page);

  const initial = await getWaveformMetrics(page);
  expect(initial.containerWidth).toBeGreaterThan(700);
  expect(Math.abs(initial.canvasWidth - initial.containerWidth)).toBeLessThanOrEqual(1);

  await page.setViewportSize({ width: 520, height: 720 });

  await expect
    .poll(async () => {
      const metrics = await getWaveformMetrics(page);
      return Math.abs(metrics.canvasWidth - metrics.containerWidth);
    })
    .toBeLessThanOrEqual(1);

  const resized = await getWaveformMetrics(page);
  expect(resized.containerWidth).toBeLessThan(initial.containerWidth);
  expect(Math.abs(resized.startOffset - resized.expectedStartOffset)).toBeLessThanOrEqual(1.5);
  expect(Math.abs(resized.endOffset - resized.expectedEndOffset)).toBeLessThanOrEqual(1.5);
});
