const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const offlineAppSource = fs.readFileSync(
  path.resolve(__dirname, '../../../offline-app/offline-app.js'),
  'utf8'
);

function buildOfflineShellMarkup() {
  return `
    <main class="ll-offline-app">
      <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container">
        <section id="ll-offline-study-view" class="ll-offline-app-view" data-ll-offline-view="study">
          <section id="ll-offline-launcher" class="ll-offline-launcher" aria-label="Offline quiz launcher">
            <div class="ll-wordset-next-shell" data-ll-offline-next-shell>
              <button type="button" class="ll-wordset-next-card is-disabled" id="ll-offline-next-card" data-ll-offline-next aria-live="polite" aria-disabled="true" disabled>
                <span class="ll-wordset-next-card__main">
                  <span class="ll-wordset-next-card__icon" id="ll-offline-next-icon" data-ll-offline-next-icon aria-hidden="true"></span>
                  <span class="ll-wordset-next-card__preview" id="ll-offline-next-preview" data-ll-offline-next-preview aria-hidden="true"></span>
                  <span class="ll-wordset-next-card__text" id="ll-offline-next-text" data-ll-offline-next-text>Loading next recommendation...</span>
                </span>
              </button>
              <span class="ll-wordset-next-card__meta">
                <span class="ll-wordset-queue-item__count ll-wordset-next-card__count" id="ll-offline-next-count" data-ll-offline-next-count hidden></span>
              </span>
            </div>
            <div class="ll-wordset-grid-tools">
              <input id="ll-offline-category-search" type="search" data-ll-offline-category-search />
              <div class="ll-wordset-main-sort" data-ll-offline-sort-root>
                <button type="button" data-ll-offline-sort-toggle aria-expanded="false" aria-controls="ll-offline-sort-menu">Sort</button>
                <div id="ll-offline-sort-menu" data-ll-offline-sort-menu role="menu" hidden>
                  <button type="button" data-ll-offline-sort-option="default" role="menuitemradio" aria-checked="true">Default</button>
                  <button type="button" data-ll-offline-sort-option="alpha-asc" role="menuitemradio" aria-checked="false">A-Z</button>
                  <button type="button" data-ll-offline-sort-option="alpha-desc" role="menuitemradio" aria-checked="false">Z-A</button>
                  <button type="button" data-ll-offline-sort-option="progress-desc" role="menuitemradio" aria-checked="false">More learned</button>
                </div>
              </div>
              <button id="ll-offline-select-all" type="button">Select All</button>
            </div>
            <div id="ll-offline-category-grid" class="ll-wordset-grid" role="list" aria-live="polite"></div>
            <div id="ll-offline-selection-bar" class="ll-wordset-selection-bar" hidden>
              <span id="ll-offline-selection-text" class="ll-wordset-selection-bar__text">Select categories to study together</span>
              <button id="ll-offline-launch-learning-selected" data-ll-offline-launch-selected data-mode="learning" type="button" disabled>Learn</button>
              <button id="ll-offline-launch-practice-selected" data-ll-offline-launch-selected data-mode="practice" type="button" disabled>Practice</button>
              <button id="ll-offline-launch-listening-selected" data-ll-offline-launch-selected data-mode="listening" type="button" hidden disabled>Listen</button>
              <button id="ll-offline-launch-gender-selected" data-ll-offline-launch-selected data-mode="gender" type="button" hidden disabled>Gender</button>
              <button id="ll-offline-launch-self-check-selected" data-ll-offline-launch-selected data-mode="self-check" type="button" hidden disabled>Self check</button>
              <button id="ll-offline-selection-clear" type="button">Clear</button>
            </div>
            <div id="ll-offline-category-empty" hidden>No categories are available in this offline app.</div>
          </section>
        </section>

        <div id="ll-tools-flashcard-popup" style="display:none;">
          <div id="ll-tools-flashcard-quiz-popup" style="display:none;">
            <button id="ll-tools-close-flashcard" type="button">Close</button>
            <div id="ll-tools-flashcard-header" style="display:none;">
              <div id="ll-tools-category-stack">
                <span id="ll-tools-category-display"></span>
                <button id="ll-tools-repeat-flashcard" class="play-mode" type="button"></button>
              </div>
            </div>
            <div id="ll-tools-flashcard-content">
              <div id="ll-tools-prompt" style="display:none;"></div>
              <div id="ll-tools-flashcard"></div>
              <audio controls class="hidden"></audio>
            </div>
            <div id="quiz-results" style="display:none;"></div>
          </div>
        </div>
      </div>
    </main>
  `;
}

async function mountOfflineLauncher(page) {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  const errors = [];
  page.on('pageerror', (error) => {
    errors.push(error.message);
  });
  await page.setContent(buildOfflineShellMarkup(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    window.__offlineLaunches = [];
    window.initFlashcardWidget = function initFlashcardWidget(catNames, mode) {
      const flashData = window.llToolsFlashcardsData || {};
      window.__offlineLaunches.push({
        catNames: Array.isArray(catNames) ? catNames.slice() : [],
        mode,
        plan: flashData.lastLaunchPlan ? Object.assign({}, flashData.lastLaunchPlan) : null
      });
      return Promise.resolve();
    };

    window.llToolsOfflineData = {
      messages: {
        offlineSelectionWords: '%d words',
        offlineModePractice: 'Practice',
        offlineModeLearning: 'Learn',
        offlineSelectCategory: 'Select category: %s',
        offlineModeCategoryLabel: '%1$s: %2$s'
      },
      app: {
        title: 'Offline Starter',
        launcher: {
          categories: [
            {
              id: 11,
              name: 'Animals',
              translation: 'Animals',
              word_count: 5,
              learning_supported: true,
              preview: [{ type: 'text', label: 'cat' }, { type: 'text', label: 'dog' }]
            },
            {
              id: 22,
              name: 'Market',
              translation: 'Market',
              word_count: 5,
              learning_supported: true,
              preview: [{ type: 'text', label: 'bread' }, { type: 'text', label: 'apple' }]
            }
          ]
        }
      },
      flashcards: {
        wordset: 'offline-set',
        wordsetIds: [777],
        availableModes: ['learning', 'practice'],
        categories: [
          { id: 11, name: 'Animals', word_count: 5, learning_supported: true },
          { id: 22, name: 'Market', word_count: 5, learning_supported: true }
        ],
        userStudyState: {
          wordset_id: 777,
          category_ids: [],
          starred_word_ids: [],
          star_mode: 'normal',
          fast_transitions: false
        },
        offlineCategoryData: {
          Animals: [
            { id: 101, title: 'cat', label: 'cat', translation: 'kedi' },
            { id: 102, title: 'dog', label: 'dog', translation: 'kopek' },
            ...Array.from({ length: 3 }, (_, index) => ({
              id: 103 + index,
              title: `animal ${index + 3}`,
              label: `animal ${index + 3}`,
              translation: `animal translation ${index + 3}`
            }))
          ],
          Market: [
            { id: 201, title: 'bread', label: 'bread', translation: 'ekmek' },
            { id: 202, title: 'apple', label: 'apple', translation: 'elma' },
            ...Array.from({ length: 3 }, (_, index) => ({
              id: 203 + index,
              title: `market ${index + 3}`,
              label: `market ${index + 3}`,
              translation: `market translation ${index + 3}`
            }))
          ]
        }
      }
    };
  });
  await page.addScriptTag({ content: offlineAppSource });
  const cardCount = await page.locator('#ll-offline-category-grid .ll-wordset-card').count();
  if (cardCount === 0 && errors.length) {
    throw new Error(`Offline launcher failed to render: ${errors.join(' | ')}`);
  }
}

test('offline app launcher filters, sorts, selects, and launches the real shell wiring', async ({ page }) => {
  await mountOfflineLauncher(page);

  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(2);
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Animals', 'Market']);

  await page.locator('[data-ll-offline-category-search]').fill('bread');
  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(1);
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Market']);

  await page.locator('[data-ll-offline-category-search]').fill('');
  await expect(page.locator('#ll-offline-category-grid .ll-wordset-card')).toHaveCount(2);
  await page.locator('[data-ll-offline-sort-toggle]').click();
  await page.locator('[data-ll-offline-sort-option="alpha-desc"]').click();
  await expect(page.locator('.ll-wordset-card__title')).toHaveText(['Market', 'Animals']);

  await page.locator('#ll-offline-select-all').click();
  await expect(page.locator('#ll-offline-selection-bar')).toBeVisible();
  await expect(page.locator('#ll-offline-selection-text')).toHaveText('10 words');
  await expect(page.locator('#ll-offline-launch-practice-selected')).toBeEnabled();

  await page.locator('#ll-offline-launch-practice-selected').click();
  const launch = await page.evaluate(() => ({
    launches: window.__offlineLaunches,
    lastLaunchPlan: window.llToolsFlashcardsData && window.llToolsFlashcardsData.lastLaunchPlan,
    userStudyState: window.llToolsFlashcardsData && window.llToolsFlashcardsData.userStudyState
  }));

  expect(launch.launches).toHaveLength(1);
  expect(launch.launches[0].catNames).toEqual(['Animals', 'Market']);
  expect(launch.launches[0].mode).toBe('practice');
  expect(launch.lastLaunchPlan.category_ids).toEqual([11, 22]);
  expect(launch.lastLaunchPlan.session_word_ids).toEqual([]);
  expect(launch.userStudyState.wordset_id).toBe(777);
  expect(launch.userStudyState.category_ids).toEqual([11, 22]);
});
