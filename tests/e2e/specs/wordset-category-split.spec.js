const { test, expect } = require('@playwright/test');
const path = require('path');
const { randomUUID } = require('crypto');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 360000 });
const fixtureScript = path.resolve(__dirname, '../fixtures/seed-wordset-category-split.php');
const fixture = (command, runId) => runWpCliJson(['eval-file', fixtureScript, command, runId], { timeoutMs: 180000 });

async function withFixture(page, exercise) {
  const runId = randomUUID();
  let cleanup = false;
  try {
    let state;
    try {
      cleanup = true;
      state = fixture('seed', runId);
    } catch (error) {
      if (error && error.isWpCliUnavailable) {
        cleanup = false;
        test.skip(true, 'Local WP-CLI is unavailable.');
        return;
      }
      throw error;
    }
    await page.goto('/wp-login.php?reauth=1', { waitUntil: 'domcontentloaded' });
    await page.locator('#user_login').fill(state.manager.login);
    await page.locator('#user_pass').fill(state.manager.password);
    await Promise.all([
      page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/wp-login.php'), { timeout: 60000 }),
      page.locator('#wp-submit').click({ noWaitAfter: true })
    ]);
    await exercise(state, () => fixture('inspect', runId));
  } finally {
    if (cleanup) expect(fixture('cleanup', runId)).toEqual({ ok: true });
  }
}

const wordCheckbox = (page, id) => page.locator(`[data-ll-wordset-editor-word][value="${id}"]`);

async function selectWords(page, ids) {
  for (const id of ids) await wordCheckbox(page, id).check();
}

async function submitSplit(page, form, expectedCount) {
  const navigation = page.waitForURL(url => url.searchParams.get('ll_wordset_manager_editor_result') === 'split_category', { timeout: 120000 });
  await form.locator('[data-ll-wordset-editor-split-submit]').click({ noWaitAfter: true });
  await navigation;
  await page.waitForLoadState('domcontentloaded');
  const url = new URL(page.url());
  expect(url.searchParams.get('ll_wordset_manager_editor')).toBe('ok');
  expect(url.searchParams.get('ll_wordset_manager_editor_count')).toBe(String(expectedCount));
  expect(url.searchParams.get('ll_wordset_manager_editor_blocked')).toBe('0');
  expect(url.searchParams.has('ll_editor_split_category')).toBe(false);
  await expect(page.locator('.ll-wordset-editor-split-form')).toHaveCount(0);
}

function assertMovedState(state, persisted, targetId) {
  for (const id of state.selectedWordIds) {
    const word = persisted.words[id];
    expect(word.status).toBe(id === state.draftWordId ? 'draft' : 'publish');
    expect(word.wordsets).toEqual([state.wordsetId]);
    expect(word.categories).toContain(targetId);
    expect(word.categories).not.toContain(state.sourceId);
    expect(word.imageCategories).toContain(targetId);
    expect(word.imageCategories).not.toContain(state.sourceId);
  }
  for (const id of state.retainedWordIds) {
    expect(persisted.words[id].categories).toEqual([state.sourceId]);
    expect(persisted.words[id].imageCategories).toEqual([state.sourceId]);
    expect(persisted.words[id].status).toBe('publish');
  }
  expect(persisted.words[state.selectedWordIds[0]].categories).toContain(state.relatedId);
  expect(persisted.words[state.selectedWordIds[0]].imageCategories).toContain(state.relatedId);
  for (const id of state.existingWordIds) {
    expect(persisted.words[id].categories).toEqual([state.existingTargetId]);
    expect(persisted.words[id].status).toBe('publish');
  }
}

async function assertLearnerDestination(page, state, inspect, targetId) {
  // Reading the normal wordset route exercises the same catalog refresh that
  // previously made the user's split look as if nothing had happened.
  await page.goto(state.wordsetPath, { waitUntil: 'domcontentloaded' });
  const card = page.locator(`.ll-wordset-card[data-cat-id="${targetId}"]:not(.ll-wordset-card--lazy-placeholder)`);
  await expect(card).toBeVisible({ timeout: 60000 });
  const persisted = inspect();
  const lesson = persisted.lessons.find(row => row.categoryId === targetId);
  expect(lesson, 'The catalog must have a published destination lesson.').toBeTruthy();
  await page.context().clearCookies();
  const response = await page.goto(lesson.path, { waitUntil: 'domcontentloaded' });
  expect(response.status()).toBe(200);
  await expect(page.locator('.ll-vocab-lesson-page')).toBeVisible();
  for (const id of state.selectedWordIds.filter(id => id !== state.draftWordId)) {
    await expect(page.locator(`.word-grid [data-word-id="${id}"]`).first()).toBeVisible({ timeout: 60000 });
  }
  await expect(page.locator(`.word-grid [data-word-id="${state.draftWordId}"]`)).toHaveCount(0);
  for (const id of state.retainedWordIds) {
    await expect(page.locator(`.word-grid [data-word-id="${id}"]`)).toHaveCount(0);
  }
}

test('split has one clear action, validates destination, and moves only the checked words with their images', async ({ page }) => {
  await withFixture(page, async (state, inspect) => {
    const submissions = [];
    page.on('request', request => {
      if (request.method() !== 'POST') return;
      const params = new URLSearchParams(request.postData() || '');
      if (params.has('ll_wordset_manager_editor_action')) submissions.push(params);
    });
    await page.goto(state.splitPath, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.ll-wordset-editor-split-form');
    const submit = form.locator('[data-ll-wordset-editor-split-submit]');
    const name = form.locator('[name="ll_wordset_editor_new_category_name"]');
    await expect(form).toBeVisible();
    await expect(form).toHaveAttribute('id', `ll-wordset-editor-split-${state.wordsetId}`);
    await expect(page.locator('[data-ll-wordset-editor-bulk-action]')).toHaveCount(0);
    await expect(page.locator('select[name="ll_wordset_manager_editor_action"]')).toHaveCount(0);
    await expect(form.locator('[data-ll-wordset-editor-split-target-mode]')).toHaveValue('new');
    await expect(name).toBeEnabled();
    await expect(name).toHaveAttribute('required', '');
    await expect(form.locator('[name="ll_wordset_editor_target_category"]')).toBeDisabled();
    await expect(submit).toBeDisabled();
    await expect(form.locator('[data-ll-wordset-editor-selected-count]')).toHaveText('0 selected');
    await name.fill('   ');
    await selectWords(page, state.selectedWordIds);
    await expect(submit).toBeEnabled();
    await expect(form.locator('[data-ll-wordset-editor-selected-count]')).toHaveText('6 selected');
    await wordCheckbox(page, state.selectedWordIds[0]).uncheck();
    await expect(form.locator('[data-ll-wordset-editor-selected-count]')).toHaveText('5 selected');
    await wordCheckbox(page, state.selectedWordIds[0]).check();
    const formData = await form.evaluate(node => Array.from(new FormData(node).entries()));
    expect(formData.filter(([key]) => key === 'll_wordset_editor_word_ids[]').map(([, id]) => Number(id)).sort((a, b) => a - b))
      .toEqual([...state.selectedWordIds].sort((a, b) => a - b));
    expect(formData.some(([key, value]) => key === 'll_wordset_editor_all_filtered' && value === '1')).toBe(false);
    await submit.click();
    await expect(form.locator('[data-ll-wordset-editor-split-error]')).toBeVisible();
    expect(submissions).toHaveLength(0);
    expect(inspect().targetId).toBe(0);
    await name.fill(state.newCategoryName);
    const desktopViewport = page.viewportSize();
    await page.screenshot({ path: test.info().outputPath('split-category-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(submit).toBeVisible();
    await page.screenshot({ path: test.info().outputPath('split-category-mobile.png'), fullPage: true });
    const layout = await page.evaluate(() => ({
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      outsideViewport: Array.from(document.querySelectorAll('body *')).filter(node => {
        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.right > window.innerWidth + 1;
      }).slice(0, 20).map(node => {
        const style = getComputedStyle(node);
        const rect = node.getBoundingClientRect();
        return { tag: node.tagName, id: node.id, className: node.className,
          left: Math.round(rect.left), right: Math.round(rect.right), width: Math.round(rect.width),
          scrollWidth: node.scrollWidth, clientWidth: node.clientWidth,
          position: style.position, display: style.display, overflowX: style.overflowX, transform: style.transform };
      }),
      splitPanels: Array.from(document.querySelectorAll('.ll-wordset-editor-split-workspace > *, .ll-wordset-editor-split-form input, .ll-wordset-editor-split-form select, [data-ll-wordset-editor-split-submit]'))
        .filter(node => getComputedStyle(node).display !== 'none' && node.getBoundingClientRect().width > 0)
        .map(node => ({ left: node.getBoundingClientRect().left, right: node.getBoundingClientRect().right }))
    }));
    require('fs').writeFileSync(test.info().outputPath('split-mobile-layout.json'), JSON.stringify(layout, null, 2));
    await test.info().attach('split-mobile-layout', { body: JSON.stringify(layout, null, 2), contentType: 'application/json' });
    expect(layout.splitPanels.every(rect => rect.left >= 0 && rect.right <= layout.viewportWidth + 1)).toBe(true);
    await page.setViewportSize(desktopViewport);
    await submitSplit(page, form, state.selectedWordIds.length);
    expect(submissions).toHaveLength(1);
    expect(submissions[0].get('ll_wordset_manager_editor_action')).toBe('split_category');
    expect(submissions[0].getAll('ll_wordset_editor_word_ids[]').map(Number).sort((a, b) => a - b))
      .toEqual([...state.selectedWordIds].sort((a, b) => a - b));
    const persisted = inspect();
    expect(persisted.targetId).toBeGreaterThan(0);
    expect(persisted.targetConfig).toEqual({ prompt: 'text_translation', option: 'text_title' });
    assertMovedState(state, persisted, persisted.targetId);
    expect(new URL(page.url()).searchParams.get('ll_editor_category')).toBe(String(persisted.targetId));
    await assertLearnerDestination(page, state, inspect, persisted.targetId);
  });
});

test('existing destination replaces the inactive new name and preserves published and draft status', async ({ page }) => {
  await withFixture(page, async (state, inspect) => {
    await page.goto(state.splitPath, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.ll-wordset-editor-split-form');
    const name = form.locator('[name="ll_wordset_editor_new_category_name"]');
    await name.fill(state.newCategoryName);
    await form.locator('[data-ll-wordset-editor-split-target-mode]').selectOption('existing');
    await expect(name).toBeDisabled();
    await expect(name).not.toHaveAttribute('required', '');
    const target = form.locator('[name="ll_wordset_editor_target_category"]');
    await expect(target).toBeEnabled();
    await target.selectOption(String(state.existingTargetId));
    await selectWords(page, state.selectedWordIds);
    await submitSplit(page, form, state.selectedWordIds.length);
    const persisted = inspect();
    expect(persisted.targetId).toBe(0);
    assertMovedState(state, persisted, state.existingTargetId);
    expect(new URL(page.url()).searchParams.get('ll_editor_category')).toBe(String(state.existingTargetId));
    await assertLearnerDestination(page, state, inspect, state.existingTargetId);
  });
});

test('all filtered is an explicit choice and moves the narrowed scope without including unfiltered words', async ({ page }) => {
  await withFixture(page, async (state, inspect) => {
    await page.goto(`${state.splitPath}&ll_editor_q=Move`, { waitUntil: 'domcontentloaded' });
    const form = page.locator('.ll-wordset-editor-split-form');
    const allFiltered = page.locator('input[data-ll-wordset-editor-all-filtered]');
    await expect(page.locator('[data-ll-wordset-editor-word]')).toHaveCount(state.selectedWordIds.length);
    await expect(allFiltered).not.toBeChecked();
    await expect(form.locator('[data-ll-wordset-editor-split-submit]')).toBeDisabled();
    await allFiltered.check();
    await expect(form.locator('[data-ll-wordset-editor-selected-count]')).toContainText('6');
    await expect(form.locator('[data-ll-wordset-editor-split-submit]')).toBeEnabled();
    const formData = await form.evaluate(node => new FormData(node).get('ll_wordset_editor_all_filtered'));
    expect(formData).toBe('1');
    await form.locator('[name="ll_wordset_editor_new_category_name"]').fill(state.newCategoryName);
    await submitSplit(page, form, state.selectedWordIds.length);
    const persisted = inspect();
    expect(persisted.targetId).toBeGreaterThan(0);
    assertMovedState(state, persisted, persisted.targetId);
    const url = new URL(page.url());
    expect(url.searchParams.has('ll_editor_q')).toBe(false);
    await expect(page.locator('[data-ll-wordset-editor-word]')).toHaveCount(state.selectedWordIds.length);
  });
});
