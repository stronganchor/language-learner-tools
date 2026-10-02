const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 240000, mode: 'serial' });
const fixturePath = path.resolve(__dirname, '..', 'fixtures', 'seed-lms-assignment-player.php');
function fixture(command) { return runWpCliJson(['eval-file', fixturePath, command], { timeoutMs: 120000 }); }

test('real player resumes an ambiguous start and saves first answers and vocabulary progress once', async ({ page }) => {
  let seeded = false;
  try {
    seeded = true;
    const seed = fixture('seed');
    await page.goto('/wp-login.php?reauth=1');
    await page.locator('#user_login').fill(seed.login);
    await page.locator('#user_pass').fill(seed.password);
    await Promise.all([page.waitForURL((url) => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
    let privateManifest = null;
    page.on('response', async (response) => {
      if (response.url().includes('/player-state') && response.ok()) { privateManifest = await response.json(); }
    });
    await page.goto(seed.url);
    await expect(page.getByRole('heading', { name: 'Player Hebrew assignment' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Start assignment', exact: true })).toBeVisible();
    await expect.poll(() => privateManifest !== null).toBeTruthy();
    for (const item of privateManifest.manifest.items) {
      expect(item).not.toHaveProperty('vocabulary');
      for (const option of item.options) { expect(option).not.toHaveProperty('correct'); }
    }
    await page.getByRole('button', { name: 'Start assignment', exact: true }).click();
    await expect(page.locator('.ll-assignment-player__content')).toContainText('Question 1 of 5');
    await page.reload();
    await expect(page.locator('.ll-assignment-player__content')).toContainText('Question 1 of 5');
    expect(fixture('snapshot').attempts).toBe(1);
    // Let the real server commit one answer, then lose its response. Retry must
    // reuse the same answer UUID and produce exactly one exposure/outcome pair.
    let failedOnce = false;
    const answerPayloads = [];
    await page.route('**/lms/attempts/*/answers', async (route) => {
      answerPayloads.push(route.request().postDataJSON());
      if (!failedOnce) { failedOnce = true; await route.fetch(); await route.abort('failed'); }
      else { await route.continue(); }
    });
    const firstPrompt = await page.locator('.ll-assignment-player__prompt').innerText();
    const firstNumber = firstPrompt.match(/Hebrew prompt (\d+)/)[1];
    await page.locator('.ll-assignment-player__choice').filter({ hasText: `Meaning ${firstNumber}` }).locator('input').check();
    await page.getByRole('button', { name: 'Save answer', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
    expect(fixture('snapshot').ledger).toBe(2);
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.locator('.ll-assignment-player__content')).toContainText('Question 2 of 5');
    expect(answerPayloads).toHaveLength(2);
    expect(answerPayloads[0]).toEqual(answerPayloads[1]);
    for (let index = 2; index <= 5; index += 1) {
      const prompt = await page.locator('.ll-assignment-player__prompt').innerText();
      const number = prompt.match(/Hebrew prompt (\d+)/)[1];
      await page.locator('.ll-assignment-player__choice').filter({ hasText: `Meaning ${number}` }).locator('input').check();
      await page.getByRole('button', { name: 'Save answer', exact: true }).click();
      if (index < 5) { await expect(page.locator('.ll-assignment-player__content')).toContainText(`Question ${index + 1} of 5`); }
    }
    await expect(page.getByRole('button', { name: 'Finish assignment', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Finish assignment', exact: true }).click();
    await expect(page.locator('.ll-assignment-player__result')).toContainText('Attempt score: 5 / 5');
    await expect(page.locator('.ll-assignment-player__result')).toContainText('Selected grade: 10 / 10');
    const snapshot = fixture('snapshot');
    expect(snapshot.attempts).toBe(1);
    expect(snapshot.ledger).toBe(10);
    expect(snapshot.coverage).toBe(5);
    expect(snapshot.grade.score_given).toBe(5);
  } finally { if (seeded) { fixture('cleanup'); } }
});
