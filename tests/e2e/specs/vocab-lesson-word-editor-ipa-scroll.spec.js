const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery'), 'utf8');
const wordGridScriptSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/word-grid.js'),
  'utf8'
);
const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);

const hostileThemeCss = `
  .word-grid.ll-word-grid button,
  .word-grid.ll-word-grid input,
  .word-grid.ll-word-grid textarea {
    min-height: 48px !important;
    font-size: 16px !important;
    line-height: 1.5 !important;
  }
`;

function buildWordGridConfig() {
  return {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: 'test-nonce',
    editNonce: 'test-edit-nonce',
    canEdit: true,
    isLoggedIn: true,
    secondaryTextMode: 'ipa',
    secondaryTextDisplayFormat: 'plain',
    secondaryTextUsesIpaFont: false,
    secondaryTextSupportsSuperscript: true,
    secondaryTextCommonChars: ['ə', 'ɑ', 'ʃ', 'l'],
    ipaSpecialChars: ['ə', 'ɑ', 'ʃ', 'l', 'm'],
    ipaLetterMap: {
      s: ['ʃ'],
      sh: ['ʃ'],
      a: ['ɑ'],
      l: ['l'],
      m: ['m']
    },
    state: {
      wordset_id: 7,
      category_ids: [11],
      starred_word_ids: [],
      star_mode: 'normal',
      fast_transitions: false
    },
    i18n: {},
    editI18n: {}
  };
}

function buildEditorMarkup() {
  const fillerFields = Array.from({ length: 12 }, (_, index) => {
    const fieldIndex = index + 1;
    return `
      <label class="ll-word-edit-label" for="ll-word-edit-extra-${fieldIndex}">Extra field ${fieldIndex}</label>
      <textarea
        class="ll-word-edit-input ll-word-edit-textarea"
        id="ll-word-edit-extra-${fieldIndex}"
        rows="3"
      >Extra content ${fieldIndex}</textarea>
    `;
  }).join('');

  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="7" data-ll-category-id="11">
      <div class="word-item ll-word-edit-open" data-word-id="101">
        <button type="button" data-ll-word-edit-toggle aria-expanded="true">Edit</button>
        <div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="false"></div>
        <div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="false">
          <div class="ll-word-edit-body" data-ll-word-edit-body>
            <div class="ll-word-edit-fields">
              <label class="ll-word-edit-label" for="ll-word-edit-word-101">Word</label>
              <input type="text" class="ll-word-edit-input" id="ll-word-edit-word-101" value="shalom" />
              <label class="ll-word-edit-label" for="ll-word-edit-translation-101">Translation</label>
              <input type="text" class="ll-word-edit-input" id="ll-word-edit-translation-101" value="peace" />
              ${fillerFields}
            </div>

            <button type="button" class="ll-word-edit-recordings-toggle" data-ll-word-recordings-toggle aria-expanded="true">
              <span class="ll-word-edit-recordings-label">Recordings</span>
            </button>

            <div class="ll-word-edit-recordings" data-ll-word-recordings-panel aria-hidden="false">
              <div class="ll-word-edit-recording" data-recording-id="501" data-recording-type="isolation">
                <div class="ll-word-edit-recording-header">
                  <div class="ll-word-edit-recording-title">
                    <span class="ll-word-edit-recording-name">Isolation</span>
                  </div>
                </div>
                <div class="ll-word-edit-recording-fields">
                  <label class="ll-word-edit-label" for="ll-word-edit-recording-text-501">Text</label>
                  <input type="text" class="ll-word-edit-input" id="ll-word-edit-recording-text-501" data-ll-recording-input="text" value="shalom" />
                  <label class="ll-word-edit-label" for="ll-word-edit-recording-translation-501">Translation</label>
                  <input type="text" class="ll-word-edit-input" id="ll-word-edit-recording-translation-501" data-ll-recording-input="translation" value="peace" />
                  <label class="ll-word-edit-label" for="ll-word-edit-recording-ipa-501">IPA</label>
                  <div class="ll-word-edit-ipa-audio" data-ll-ipa-audio aria-hidden="true">
                    <div class="ll-word-edit-ipa-waveform" data-ll-ipa-waveform aria-hidden="true">
                      <canvas class="ll-word-edit-ipa-waveform-canvas"></canvas>
                    </div>
                    <audio class="ll-word-edit-ipa-audio-player" controls preload="none" src=""></audio>
                  </div>
                  <div class="ll-word-edit-ipa-target" data-ll-ipa-target aria-hidden="true" aria-label="Transcription guide">
                    <button type="button" class="ll-word-edit-ipa-shift ll-word-edit-ipa-shift--prev" data-ll-ipa-shift="prev" aria-label="Previous letter">
                      <span aria-hidden="true">&lt;</span>
                    </button>
                    <div class="ll-word-edit-ipa-target-text" data-ll-ipa-target-text aria-live="polite"></div>
                    <button type="button" class="ll-word-edit-ipa-shift ll-word-edit-ipa-shift--next" data-ll-ipa-shift="next" aria-label="Next letter">
                      <span aria-hidden="true">&gt;</span>
                    </button>
                  </div>
                  <div class="ll-word-edit-input-wrap ll-word-edit-input-wrap--ipa">
                    <input type="text" class="ll-word-edit-input ll-word-edit-input--ipa" id="ll-word-edit-recording-ipa-501" data-ll-recording-input="ipa" value="ʃalom" />
                  </div>
                  <div class="ll-word-edit-ipa-suggestions" data-ll-ipa-suggestions aria-hidden="true" aria-label="IPA suggestions"></div>
                  <button type="button" class="ll-word-edit-ipa-superscript" data-ll-ipa-superscript aria-hidden="true" aria-label="Superscript">
                    <span aria-hidden="true">x&sup2;</span>
                  </button>
                  <div class="ll-word-edit-ipa-keyboard" data-ll-ipa-keyboard aria-hidden="true" aria-label="IPA symbols"></div>
                </div>
              </div>
            </div>
          </div>

          <div class="ll-word-edit-footer">
            <div class="ll-word-edit-actions">
              <button type="button" class="ll-word-edit-action ll-word-edit-save" data-ll-word-edit-save aria-label="Save" title="Save">
                <span aria-hidden="true">Save</span>
              </button>
              <button type="button" class="ll-word-edit-action ll-word-edit-cancel" data-ll-word-edit-cancel aria-label="Cancel" title="Cancel">
                <span aria-hidden="true">Cancel</span>
              </button>
            </div>
            <div class="ll-word-edit-status" data-ll-word-edit-status aria-live="polite">Ready</div>
          </div>
        </div>
      </div>
    </div>
  `;
}

test('focusing the IPA field recenters the modal body so the waveform and keyboard stay visible', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(buildEditorMarkup());
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: hostileThemeCss });
  await page.addScriptTag({ content: jquerySource });
  await page.evaluate((cfg) => {
    window.llToolsWordGridData = cfg;
  }, buildWordGridConfig());
  await page.addScriptTag({ content: wordGridScriptSource });
  await page.evaluate(() => {
    document.body.classList.add('ll-word-edit-modal-open');
    document.body.style.margin = '0';
  });

  const editorBody = page.locator('[data-ll-word-edit-body]');
  const ipaInput = page.locator('.ll-word-edit-input--ipa');

  const before = await editorBody.evaluate((body) => {
    const input = body.querySelector('.ll-word-edit-input--ipa');
    const bodyRect = body.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const desiredBottomGap = 24;
    const targetScrollTop = body.scrollTop
      + (inputRect.top - bodyRect.top)
      - (body.clientHeight - inputRect.height - desiredBottomGap);

    body.scrollTop = Math.max(0, targetScrollTop);

    const nextBodyRect = body.getBoundingClientRect();
    const nextInputRect = input.getBoundingClientRect();
    return {
      scrollTop: body.scrollTop,
      centerOffset: ((nextInputRect.top + (nextInputRect.height / 2)) - (nextBodyRect.top + (nextBodyRect.height / 2))),
      inputBottom: nextInputRect.bottom - nextBodyRect.top,
      bodyHeight: nextBodyRect.height
    };
  });

  expect(before.inputBottom).toBeGreaterThan(before.bodyHeight - 60);

  await ipaInput.click();
  await page.waitForTimeout(100);

  const after = await editorBody.evaluate((body) => {
    const input = body.querySelector('.ll-word-edit-input--ipa');
    const waveform = body.querySelector('[data-ll-ipa-waveform]');
    const keyboard = body.querySelector('[data-ll-ipa-keyboard]');
    const bodyRect = body.getBoundingClientRect();
    const inputRect = input.getBoundingClientRect();
    const waveformRect = waveform.getBoundingClientRect();
    const keyboardRect = keyboard.getBoundingClientRect();

    return {
      scrollTop: body.scrollTop,
      centerOffset: ((inputRect.top + (inputRect.height / 2)) - (bodyRect.top + (bodyRect.height / 2))),
      inputTop: inputRect.top - bodyRect.top,
      inputBottom: inputRect.bottom - bodyRect.top,
      bodyHeight: bodyRect.height,
      waveformHidden: waveform.getAttribute('aria-hidden'),
      waveformBottom: waveformRect.bottom - bodyRect.top,
      keyboardHidden: keyboard.getAttribute('aria-hidden'),
      keyboardTop: keyboardRect.top - bodyRect.top,
      windowScrollY: window.scrollY
    };
  });

  expect(after.waveformHidden).toBe('false');
  expect(after.keyboardHidden).toBe('false');
  expect(after.scrollTop).toBeGreaterThan(before.scrollTop);
  expect(Math.abs(after.centerOffset)).toBeLessThan(Math.abs(before.centerOffset));
  expect(Math.abs(after.centerOffset)).toBeLessThanOrEqual(after.bodyHeight * 0.35);
  expect(after.waveformBottom).toBeLessThan(after.inputTop);
  expect(after.keyboardTop).toBeGreaterThan(after.inputBottom);
  expect(after.windowScrollY).toBe(0);
});
