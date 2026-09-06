const path = require('path');
const { test, expect } = require('@playwright/test');

const jqueryPath = path.resolve(__dirname, '..', 'node_modules', 'jquery', 'dist', 'jquery.min.js');
const speakerSearchScriptPath = path.resolve(__dirname, '..', '..', '..', 'js', 'audio-upload-form-admin.js');
const imageUploadFormScriptPath = path.resolve(__dirname, '..', '..', '..', 'js', 'image-upload-form-admin.js');

const uploadFormControllers = [
  {
    name: 'audio',
    formAttribute: 'data-ll-audio-upload-form',
    scriptPath: speakerSearchScriptPath,
  },
  {
    name: 'image',
    formAttribute: 'data-ll-image-upload-form',
    scriptPath: imageUploadFormScriptPath,
  },
];

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

function uploadFormParityMarkup(formAttribute) {
  return `
    <form ${formAttribute}="1">
      <label><input type="radio" name="ll_wordset_scope_mode" value="single" checked> Single</label>
      <label><input type="radio" name="ll_wordset_scope_mode" value="multiple"> Multiple</label>
      <div data-ll-single-wordset-wrap>
        <select data-ll-single-wordset>
          <option value="10" selected>Alpha word set</option>
          <option value="11">Beta word set</option>
        </select>
      </div>
      <div data-ll-multi-wordset-wrap hidden>
        <label><input type="checkbox" data-ll-multi-wordset data-ll-wordset-label="Beta word set" value="11" checked> Beta word set</label>
      </div>

      <label><input type="radio" name="ll_category_mode" value="existing" checked> Existing</label>
      <label><input type="radio" name="ll_category_mode" value="new"> New</label>
      <div data-ll-category-existing-wrap>
        <select data-ll-existing-category>
          <option value="0">Select</option>
          <option value="100" data-ll-category-wordsets="10" selected>Alpha category</option>
          <option value="200" data-ll-category-wordsets="11">Beta category</option>
          <option value="300" data-ll-category-shared="1">Shared category</option>
        </select>
      </div>
      <div data-ll-new-category-wrap hidden>
        <input data-ll-new-category-title value="">
      </div>
      <div data-ll-new-category-advanced hidden>
        <select data-ll-new-category-prompt>
          <option value="audio" selected>Audio</option>
          <option value="image">Image</option>
          <option value="text_title">Text title</option>
        </select>
        <select data-ll-new-category-option>
          <option value="audio">Audio</option>
          <option value="image" selected>Image</option>
          <option value="text_title">Text title</option>
          <option value="text_translation">Text translation</option>
        </select>
      </div>
      <div data-ll-target-preview hidden>
        <span data-ll-target-preview-category></span>
        <span data-ll-target-preview-wordsets></span>
      </div>
      <div data-ll-autocreate-note hidden></div>
      <input type="file" data-ll-image-file-input>
      <div data-ll-image-size-warning hidden>
        <span data-ll-image-size-warning-message></span>
        <span data-ll-image-size-warning-files></span>
      </div>
    </form>
  `;
}

async function exerciseUploadFormController(page, controller) {
  await page.goto('about:blank');
  await page.setContent(uploadFormParityMarkup(controller.formAttribute));
  await page.addScriptTag({ path: jqueryPath });
  await page.addScriptTag({ path: controller.scriptPath });

  const form = page.locator(`[${controller.formAttribute}]`);
  const existingCategory = form.locator('[data-ll-existing-category]');
  await expect(form.locator('[data-ll-single-wordset-wrap]')).toBeVisible();
  await expect(form.locator('[data-ll-multi-wordset-wrap]')).toBeHidden();
  await expect(existingCategory.locator('option[value="100"]')).toBeEnabled();
  await expect(existingCategory.locator('option[value="200"]')).toBeDisabled();
  await expect(form.locator('[data-ll-target-preview-category]')).toHaveText('Alpha category');
  await expect(form.locator('[data-ll-target-preview-wordsets]')).toHaveText('Alpha word set');

  await form.locator('[data-ll-single-wordset]').selectOption('11');
  await expect(existingCategory).toHaveValue('0');
  await expect(existingCategory.locator('option[value="100"]')).toBeDisabled();
  await expect(existingCategory.locator('option[value="200"]')).toBeEnabled();
  await existingCategory.selectOption('200');
  await expect(form.locator('[data-ll-target-preview-category]')).toHaveText('Beta category');
  await expect(form.locator('[data-ll-target-preview-wordsets]')).toHaveText('Beta word set');

  await form.locator('input[name="ll_wordset_scope_mode"][value="multiple"]').check();
  await expect(form.locator('[data-ll-single-wordset-wrap]')).toBeHidden();
  await expect(form.locator('[data-ll-multi-wordset-wrap]')).toBeVisible();

  await form.locator('input[name="ll_category_mode"][value="new"]').check();
  await form.locator('[data-ll-new-category-title]').fill('New category');
  await expect(form.locator('[data-ll-category-existing-wrap]')).toBeHidden();
  await expect(form.locator('[data-ll-new-category-wrap]')).toBeVisible();
  await expect(form.locator('[data-ll-new-category-advanced]')).toBeVisible();
  await expect(form.locator('[data-ll-target-preview-category]')).toHaveText('New category');
  await expect(form.locator('[data-ll-target-preview-wordsets]')).toHaveText('Beta word set');

  const prompt = form.locator('[data-ll-new-category-prompt]');
  const option = form.locator('[data-ll-new-category-option]');
  await option.selectOption('image');
  await prompt.selectOption('image');
  await expect(option.locator('option[value="image"]')).toBeDisabled();

  await option.selectOption('audio');
  await prompt.selectOption('audio');
  await expect(option.locator('option[value="audio"]')).toBeDisabled();

  return form.evaluate((node) => ({
    singleHidden: node.querySelector('[data-ll-single-wordset-wrap]').hasAttribute('hidden'),
    multipleHidden: node.querySelector('[data-ll-multi-wordset-wrap]').hasAttribute('hidden'),
    existingHidden: node.querySelector('[data-ll-category-existing-wrap]').hasAttribute('hidden'),
    newHidden: node.querySelector('[data-ll-new-category-wrap]').hasAttribute('hidden'),
    advancedHidden: node.querySelector('[data-ll-new-category-advanced]').hasAttribute('hidden'),
    category: node.querySelector('[data-ll-target-preview-category]').textContent,
    wordsets: node.querySelector('[data-ll-target-preview-wordsets]').textContent,
    prompt: node.querySelector('[data-ll-new-category-prompt]').value,
    option: node.querySelector('[data-ll-new-category-option]').value,
    audioDisabled: node.querySelector('[data-ll-new-category-option] option[value="audio"]').disabled,
    imageDisabled: node.querySelector('[data-ll-new-category-option] option[value="image"]').disabled,
  }));
}

test('audio and image upload controllers keep their shared form behavior in parity', async ({ page }) => {
  const snapshots = {};
  for (const controller of uploadFormControllers) {
    snapshots[controller.name] = await exerciseUploadFormController(page, controller);
  }

  expect(snapshots.audio).toEqual(snapshots.image);
  expect(snapshots.audio).toEqual({
    singleHidden: true,
    multipleHidden: false,
    existingHidden: true,
    newHidden: false,
    advancedHidden: false,
    category: 'New category',
    wordsets: 'Beta word set',
    prompt: 'audio',
    option: 'text_translation',
    audioDisabled: true,
    imageDisabled: false,
  });
});
