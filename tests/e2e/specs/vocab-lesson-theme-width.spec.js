const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const lessonCss = fs.readFileSync(path.resolve(__dirname, '../../../css/vocab-lesson-pages.css'), 'utf8');
const gridCss = fs.readFileSync(path.resolve(__dirname, '../../../css/language-learner-tools.css'), 'utf8');

// Astra's desktop content wrapper is flex. A lesson with width:auto can shrink
// to its guest toolbar; staff controls then change the number of grid columns.
function lessonMarkup(staff, flexTheme) {
  return `<style>
    body { margin: 0; }
    .theme-container { display: ${flexTheme ? 'flex' : 'block'}; max-width: 1240px; padding: 0 20px; margin: auto; box-sizing: border-box; }
    .fixture-toolbar { width: ${staff ? '1100' : '700'}px; max-width: 100%; height: 36px; }
  </style>
  <div class="theme-container">
    <main class="ll-vocab-lesson-page" data-ll-vocab-lesson>
      <header class="ll-vocab-lesson-hero">
        <h1 class="ll-vocab-lesson-title">Diğer</h1>
        <div class="fixture-toolbar">${staff ? 'Lesson management controls' : 'Lesson controls'}</div>
      </header>
      <div class="ll-vocab-lesson-content">
        <div class="ll-vocab-lesson-grid-shell is-loading" data-ll-vocab-lesson-grid-shell>
          <div class="word-grid ll-word-grid" data-ll-word-grid>
            ${Array.from({ length: 6 }, () => '<article class="word-item ll-vocab-lesson-skeleton-card"><div class="ll-vocab-lesson-skeleton-media"></div></article>').join('')}
          </div>
        </div>
      </div>
    </main>
  </div>`;
}

for (const flexTheme of [true, false]) {
  for (const viewport of [{ width: 1478, columns: 3 }, { width: 820, columns: 2 }, { width: 390, columns: 1 }]) {
    test(`lesson fills ${flexTheme ? 'flex' : 'block'} theme at ${viewport.width}px for guests and staff before and after hydration`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: 900 });
      await page.goto('about:blank');
      const widths = [];
      for (const staff of [false, true]) {
        await page.setContent(lessonMarkup(staff, flexTheme));
        await page.addStyleTag({ content: gridCss });
        await page.addStyleTag({ content: lessonCss });

        for (const hydrated of [false, true]) {
          if (hydrated) {
            await page.locator('[data-ll-vocab-lesson-grid-shell]').evaluate((shell) => {
              shell.classList.remove('is-loading');
              shell.querySelector('[data-ll-word-grid]').innerHTML = Array.from({ length: 6 }, () => '<article class="word-item"><div class="word-text">Dım</div><div class="word-translation">Sap</div></article>').join('');
            });
          }
          const metrics = await page.locator('[data-ll-word-grid]').evaluate((grid) => {
            const container = grid.closest('.theme-container');
            const main = grid.closest('main');
            const style = getComputedStyle(container);
            const rows = [...grid.children].map(card => card.getBoundingClientRect());
            return {
              available: container.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
              mainWidth: main.getBoundingClientRect().width,
              gridWidth: grid.getBoundingClientRect().width,
              firstRowCount: rows.filter(row => Math.abs(row.top - rows[0].top) < 1).length,
              overflow: document.documentElement.scrollWidth > innerWidth
            };
          });
          expect(metrics.mainWidth).toBeCloseTo(metrics.available, 0);
          expect(metrics.firstRowCount).toBe(viewport.columns);
          expect(metrics.overflow).toBe(false);
          widths.push(metrics.gridWidth);
        }
      }
      for (const width of widths) expect(width).toBeCloseTo(widths[0], 0);
    });
  }
}
