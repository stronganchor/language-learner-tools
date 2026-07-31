const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const languageLearnerToolsCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/language-learner-tools.css'),
  'utf8'
);
const rankedWordListCssSource = fs.readFileSync(
  path.resolve(__dirname, '../../../css/ranked-word-list.css'),
  'utf8'
);

const hostileThemeCss = `
  .elementor-kit-2165 button {
    display: flex;
    min-width: auto;
    min-height: auto;
    padding: 16px 40px;
    border: 2px solid #1a6c7a;
    border-radius: 0;
    background: transparent;
    box-shadow: inset 0 0 0 2px rgba(26, 108, 122, 0.2);
    color: #1a6c7a;
    font-size: 0.75rem;
    line-height: 1;
    letter-spacing: 2px;
    text-transform: uppercase;
  }

  .elementor-kit-2165 button:hover,
  .elementor-kit-2165 button:focus {
    border-color: #1a6c7a;
    background: #1a6c7a;
    color: #fff;
    transform: translateY(-2px);
  }
`;

async function readButtonPresentation(button) {
  return button.evaluate((element) => {
    const style = window.getComputedStyle(element);
    const bounds = element.getBoundingClientRect();

    return {
      width: bounds.width,
      height: bounds.height,
      paddingLeft: style.paddingLeft,
      paddingRight: style.paddingRight,
      borderTopWidth: style.borderTopWidth,
      backgroundColor: style.backgroundColor,
      boxShadow: style.boxShadow,
      letterSpacing: style.letterSpacing,
      textTransform: style.textTransform,
      transform: style.transform,
      outlineStyle: style.outlineStyle,
      outlineWidth: style.outlineWidth,
      outlineOffset: style.outlineOffset,
    };
  });
}

test('word audio control resists generic theme button styling', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <main class="elementor-kit-2165">
      <p>
        <span class="ll-word-audio">
          <button type="button" class="ll-word-audio__button" aria-label="Play audio">
            <span class="ll-word-audio__icon" aria-hidden="true">
              <svg class="ll-word-audio__icon-image" width="10" height="10" viewBox="0 0 10 10">
                <path d="M2 1L9 5L2 9Z"></path>
              </svg>
            </span>
          </button>
          Benim
        </span>
      </p>
    </main>
  `);
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: hostileThemeCss });

  const button = page.locator('.ll-word-audio__button');
  await expect(button).toHaveCount(1);

  const resting = await readButtonPresentation(button);
  expect(resting.width).toBe(24);
  expect(resting.height).toBe(24);
  expect(resting.paddingLeft).toBe('0px');
  expect(resting.paddingRight).toBe('0px');
  expect(resting.borderTopWidth).toBe('0px');
  expect(resting.backgroundColor).toBe('rgba(0, 0, 0, 0)');
  expect(resting.boxShadow).toBe('none');
  expect(resting.letterSpacing).toBe('normal');
  expect(resting.textTransform).toBe('none');
  expect(resting.transform).toBe('none');

  await button.hover();
  const hovered = await readButtonPresentation(button);
  expect(hovered.width).toBe(24);
  expect(hovered.height).toBe(24);
  expect(hovered.borderTopWidth).toBe('0px');
  expect(hovered.backgroundColor).toBe('rgba(0, 0, 0, 0)');
  expect(hovered.transform).toBe('none');

  await page.keyboard.press('Tab');
  await expect(button).toBeFocused();
  const focused = await readButtonPresentation(button);
  expect(focused.width).toBe(24);
  expect(focused.height).toBe(24);
  expect(focused.borderTopWidth).toBe('0px');
  expect(focused.backgroundColor).toBe('rgba(0, 0, 0, 0)');
  expect(focused.transform).toBe('none');
  expect(focused.outlineStyle).toBe('solid');
  expect(focused.outlineWidth).toBe('2px');
  expect(focused.outlineOffset).toBe('2px');
});

test('ranked word list retains its intentional larger audio control', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent(`
    <main class="elementor-kit-2165 ll-ranked-word-list">
      <span class="ll-word-audio ll-ranked-word-list__audio-control">
        <button type="button" class="ll-word-audio__button" aria-label="Play audio">
          <span class="ll-word-audio__icon" aria-hidden="true">
            <svg class="ll-word-audio__icon-image" width="10" height="10" viewBox="0 0 10 10">
              <path d="M2 1L9 5L2 9Z"></path>
            </svg>
          </span>
        </button>
      </span>
    </main>
  `);
  await page.addStyleTag({ content: languageLearnerToolsCssSource });
  await page.addStyleTag({ content: rankedWordListCssSource });
  await page.addStyleTag({ content: hostileThemeCss });

  const button = page.locator('.ll-ranked-word-list .ll-word-audio__button');
  await expect(button).toHaveCount(1);

  const presentation = await readButtonPresentation(button);
  expect(presentation.width).toBe(32);
  expect(presentation.height).toBe(32);
  expect(presentation.paddingLeft).toBe('0px');
  expect(presentation.paddingRight).toBe('0px');
  expect(presentation.borderTopWidth).toBe('1px');
  expect(presentation.backgroundColor).toBe('rgb(255, 255, 255)');
});
