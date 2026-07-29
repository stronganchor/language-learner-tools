const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const progressSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/content-lesson-progress.js'),
  'utf8'
);

async function mountProgressControl(page) {
  await page.goto('https://example.test/');
  await page.setContent(`
    <div class="ll-content-lesson-progress">
      <button
        type="button"
        data-ll-content-lesson-progress
        data-lesson-id="42"
        data-completed="0"
        aria-pressed="false">
        <span class="ll-content-lesson-progress-button__icon">○</span>
        <span data-ll-content-lesson-progress-label>Mark complete</span>
      </button>
      <span data-ll-content-lesson-progress-status data-state="idle"></span>
    </div>
  `);
  await page.evaluate(() => {
    window.llToolsContentLessonProgress = {
      ajaxUrl: 'https://example.test/wp-admin/admin-ajax.php',
      nonce: 'progress-nonce',
      action: 'll_tools_content_lesson_completion',
      i18n: {
        complete: 'Completed',
        incomplete: 'Mark complete',
        saving: 'Saving...',
        saved: 'Progress saved.',
        error: 'Lesson progress could not be saved.'
      }
    };
  });
  await page.addScriptTag({ content: progressSource });
}

test('content lesson completion autosaves and updates accessible state once', async ({ page }) => {
  const requests = [];
  await page.route('https://example.test/**', async (route) => {
    if (!route.request().url().includes('/wp-admin/admin-ajax.php')) {
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html>' });
      return;
    }
    requests.push(new URLSearchParams(route.request().postData() || ''));
    await new Promise((resolve) => setTimeout(resolve, 75));
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { completed: true } })
    });
  });

  await mountProgressControl(page);
  // Re-evaluating the bundle must not bind a second click handler.
  await page.addScriptTag({ content: progressSource });

  const button = page.locator('[data-ll-content-lesson-progress]');
  const status = page.locator('[data-ll-content-lesson-progress-status]');
  await button.click();
  await expect(status).toHaveAttribute('data-state', 'saving');
  await expect(button).toBeDisabled();
  await expect(status).toHaveText('Progress saved.');
  await expect(status).toHaveAttribute('data-state', 'saved');
  await expect(button).toHaveAttribute('aria-pressed', 'true');
  await expect(button).toHaveAttribute('data-completed', '1');
  await expect(button).toHaveClass(/is-completed/);
  await expect(button.locator('[data-ll-content-lesson-progress-label]')).toHaveText('Completed');
  await expect(button).toBeEnabled();

  expect(requests).toHaveLength(1);
  expect(requests[0].get('action')).toBe('ll_tools_content_lesson_completion');
  expect(requests[0].get('nonce')).toBe('progress-nonce');
  expect(requests[0].get('lesson_id')).toBe('42');
  expect(requests[0].get('completed')).toBe('1');
});

test('content lesson completion keeps its prior state when saving fails', async ({ page }) => {
  await page.route('https://example.test/**', async (route) => {
    if (!route.request().url().includes('/wp-admin/admin-ajax.php')) {
      await route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html>' });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: false, data: { message: 'Completion is blocked.' } })
    });
  });

  await mountProgressControl(page);
  const button = page.locator('[data-ll-content-lesson-progress]');
  const status = page.locator('[data-ll-content-lesson-progress-status]');
  await button.click();

  await expect(status).toHaveAttribute('data-state', 'error');
  await expect(status).toHaveText('Completion is blocked.');
  await expect(button).toHaveAttribute('aria-pressed', 'false');
  await expect(button).toHaveAttribute('data-completed', '0');
  await expect(button.locator('[data-ll-content-lesson-progress-label]')).toHaveText('Mark complete');
  await expect(button).toBeEnabled();
});
