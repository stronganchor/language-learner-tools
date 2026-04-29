const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);

function buildOpenEditorWithAutocompleteMenu() {
  return `
    <div class="word-grid ll-word-grid" data-ll-word-grid data-ll-wordset-id="7">
      <div class="word-item ll-word-edit-open" data-word-id="101">
        <button type="button" data-ll-word-edit-toggle aria-expanded="true">Edit</button>
        <div class="ll-word-edit-backdrop" data-ll-word-edit-backdrop aria-hidden="false"></div>
        <div class="ll-word-edit-panel" data-ll-word-edit-panel aria-hidden="false">
          <div class="ll-word-edit-body" data-ll-word-edit-body>
            <div class="ll-word-edit-image" data-ll-word-image-panel>
              <label class="ll-word-edit-label" for="ll-word-edit-existing-image-101">Existing image</label>
              <input
                type="text"
                class="ll-word-edit-input ll-word-edit-image-search"
                id="ll-word-edit-existing-image-101"
                data-ll-word-image-existing-search
                autocomplete="off"
                value="market"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
    <ul class="ui-autocomplete ll-word-image-autocomplete" data-test-image-autocomplete>
      <li>
        <div class="ll-word-image-autocomplete-item">
          <span class="ll-word-image-autocomplete-label">Market #42</span>
        </div>
      </li>
    </ul>
  `;
}

test('word image autocomplete menu renders above the open word editor modal', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 700 });
  await page.goto('about:blank');
  await page.setContent(buildOpenEditorWithAutocompleteMenu());
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({
    content: `
      .ui-autocomplete[data-test-image-autocomplete] {
        display: block;
        position: fixed;
        top: 140px;
        left: 64px;
        width: 260px;
        margin: 0;
        padding: 4px;
      }
    `
  });

  const layerState = await page.locator('[data-test-image-autocomplete]').evaluate((menu) => {
    const panel = document.querySelector('[data-ll-word-edit-panel]');
    const backdrop = document.querySelector('[data-ll-word-edit-backdrop]');
    const rect = menu.getBoundingClientRect();
    const topElement = document.elementFromPoint(rect.left + 12, rect.top + 12);

    return {
      menuZIndex: Number.parseInt(window.getComputedStyle(menu).zIndex, 10) || 0,
      panelZIndex: Number.parseInt(window.getComputedStyle(panel).zIndex, 10) || 0,
      backdropZIndex: Number.parseInt(window.getComputedStyle(backdrop).zIndex, 10) || 0,
      topElementIsMenu: !!(topElement && (topElement === menu || menu.contains(topElement)))
    };
  });

  expect(layerState.menuZIndex).toBeGreaterThan(layerState.panelZIndex);
  expect(layerState.menuZIndex).toBeGreaterThan(layerState.backdropZIndex);
  expect(layerState.topElementIsMenu).toBe(true);
});
