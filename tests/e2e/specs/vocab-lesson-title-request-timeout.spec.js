const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const jquerySource = fs.readFileSync(require.resolve('jquery/dist/jquery.js'), 'utf8');
const vocabLessonJsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/vocab-lesson-page.js'),
  'utf8'
);

test('category title timeout restores the editor and requires an explicit retry', async ({ page }) => {
  await page.setContent(`
    <main class="ll-vocab-lesson-page" data-ll-vocab-lesson>
      <div
        data-ll-vocab-lesson-title-editor
        data-lesson-id="17"
        data-category-id="31"
        data-action="ll_tools_update_vocab_lesson_category_title"
        data-nonce="title-nonce">
        <button type="button" data-ll-vocab-lesson-title-trigger aria-expanded="false">Edit category title</button>
        <span data-ll-vocab-lesson-title-text>Old title</span>
        <form data-ll-vocab-lesson-title-form hidden>
          <input data-ll-vocab-lesson-title-input value="Old title">
          <button type="submit" data-ll-vocab-lesson-title-save>Save title</button>
          <button type="button" data-ll-vocab-lesson-title-cancel>Cancel editing</button>
          <span data-ll-vocab-lesson-title-status aria-live="polite" hidden></span>
        </form>
      </div>
    </main>
  `);
  await page.addScriptTag({ content: jquerySource });
  await page.addScriptTag({
    content: `
      window.jQuery = window.$ = jQuery;
      window.llToolsVocabLessonData = {
        ajaxUrl: '/wp-admin/admin-ajax.php',
        titleEditor: {
          requestTimeoutMs: 100,
          i18n: {
            empty: 'Enter a category title.',
            saving: 'Saving...',
            saved: 'Category title saved.',
            error: 'Unable to save this category title right now.',
            timeout: 'Saving took too long. Please retry.'
          }
        }
      };
      window.__llTitleRequests = [];
      window.__llTitleRequestMode = 'timeout';
      window.__llRejectTitleTimeout = null;
      jQuery.ajax = function (options) {
        window.__llTitleRequests.push({
          timeout: Number(options.timeout || 0),
          title: String((options.data && options.data.title) || '')
        });
        const deferred = jQuery.Deferred();
        if (window.__llTitleRequestMode === 'timeout') {
          window.__llRejectTitleTimeout = function () {
            deferred.reject({}, 'timeout');
          };
        } else {
          window.setTimeout(function () {
            deferred.resolve({
              success: true,
              data: {
                display_name: String(options.data.title || ''),
                edit_value: String(options.data.title || ''),
                category_name: String(options.data.title || ''),
                field: 'name'
              }
            });
          }, 0);
        }
        return deferred.promise();
      };
    `
  });
  await page.addScriptTag({ content: vocabLessonJsSource });

  const editor = page.locator('[data-ll-vocab-lesson-title-editor]');
  const input = page.locator('[data-ll-vocab-lesson-title-input]');
  const status = page.locator('[data-ll-vocab-lesson-title-status]');
  const save = page.getByRole('button', { name: 'Save title' });

  await page.getByRole('button', { name: 'Edit category title' }).click();
  await input.fill('Recovered title');
  await save.click();
  await expect(input).toBeDisabled();
  await page.evaluate(() => window.__llRejectTitleTimeout());
  await expect(status).toHaveText('Saving took too long. Please retry.');
  await expect(status).toHaveClass(/is-error/);
  await expect(input).toBeEnabled();
  await expect(editor).toHaveClass(/is-editing/);
  expect(await page.evaluate(() => window.__llTitleRequests)).toEqual([
    { timeout: 100, title: 'Recovered title' }
  ]);

  await page.evaluate(() => {
    window.__llTitleRequestMode = 'success';
  });
  await save.click();
  await expect(page.locator('[data-ll-vocab-lesson-title-text]')).toHaveText('Recovered title');
  await expect(status).toHaveText('Category title saved.');
  await expect(editor).not.toHaveClass(/is-editing/, { timeout: 2000 });
  expect(await page.evaluate(() => window.__llTitleRequests)).toEqual([
    { timeout: 100, title: 'Recovered title' },
    { timeout: 100, title: 'Recovered title' }
  ]);
});
