const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const wordsetCss = fs.readFileSync(
  path.resolve(__dirname, '../../../css/wordset-pages.css'),
  'utf8'
);

test('teacher sortable headers retain a visible keyboard focus indicator', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head><style>${wordsetCss}</style></head><body>
    <table class="ll-teacher-classes__table">
      <thead><tr><th class="ll-teacher-classes__table-head--sortable">
        <button type="button" class="ll-teacher-classes__sort-button">Learner <span aria-hidden="true">↕</span></button>
      </th></tr></thead>
    </table>
  </body></html>`);

  await page.keyboard.press('Tab');
  const sortButton = page.locator('.ll-teacher-classes__sort-button');
  await expect(sortButton).toBeFocused();

  const focusStyle = await sortButton.evaluate((button) => {
    const style = getComputedStyle(button);
    return {
      outlineStyle: style.outlineStyle,
      outlineWidth: parseFloat(style.outlineWidth),
      outlineOffset: parseFloat(style.outlineOffset)
    };
  });
  expect(focusStyle.outlineStyle).toBe('solid');
  expect(focusStyle.outlineWidth).toBeGreaterThanOrEqual(3);
  expect(focusStyle.outlineOffset).toBeGreaterThanOrEqual(3);
});
