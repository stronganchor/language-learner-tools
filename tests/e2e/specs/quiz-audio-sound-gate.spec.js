const { test, expect } = require('@playwright/test');

const LEARN_PATH = process.env.LL_E2E_LEARN_PATH || '/learn/';

async function openModeMenu(page) {
  const switcher = page.locator('#ll-tools-mode-switcher');
  const menu = page.locator('#ll-tools-mode-menu');

  await expect(switcher).toBeVisible();
  if ((await menu.getAttribute('aria-hidden')) !== 'false') {
    await switcher.click();
  }
  await expect(menu).toHaveAttribute('aria-hidden', 'false');
}

async function switchToListening(page) {
  const option = page.locator('#ll-tools-mode-menu .ll-tools-mode-option.listening');
  await openModeMenu(page);
  await expect(option).toBeVisible();
  await option.click();
  await expect(option).toHaveClass(/active/);
}

test('audio-required quiz rounds pause behind the speaker gate when quiz audio is muted', async ({ page }) => {
  await page.goto(LEARN_PATH, { waitUntil: 'domcontentloaded' });

  const quizTriggers = page.locator('.ll-quiz-page-trigger');
  await expect(quizTriggers.first()).toBeVisible({ timeout: 60000 });
  await quizTriggers.first().click({ force: true });

  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toBeVisible({ timeout: 60000 });
  await switchToListening(page);

  await page.waitForFunction(() => {
    const audioApi = window.FlashcardAudio;
    const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
      ? audioApi.getCurrentTargetAudio()
      : null;
    return !!audio;
  }, { timeout: 60000 });

  const mutedApplied = await page.evaluate(() => {
    const audioApi = window.FlashcardAudio;
    const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
      ? audioApi.getCurrentTargetAudio()
      : null;
    if (!audio) {
      return false;
    }
    audio.volume = 0;
    audio.dispatchEvent(new Event('volumechange'));
    return true;
  });
  expect(mutedApplied).toBe(true);

  const overlay = page.locator('#ll-tools-autoplay-overlay');
  await expect(overlay).toBeVisible({ timeout: 10000 });
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).toHaveClass(/ll-sound-gate-active/);

  await overlay.locator('.ll-tools-autoplay-button').click({ force: true });

  await expect(overlay).toBeHidden({ timeout: 10000 });
  await expect(page.locator('#ll-tools-flashcard-quiz-popup')).not.toHaveClass(/ll-sound-gate-active/);

  const restored = await page.evaluate(() => {
    const audioApi = window.FlashcardAudio;
    const audio = audioApi && typeof audioApi.getCurrentTargetAudio === 'function'
      ? audioApi.getCurrentTargetAudio()
      : null;
    return !!audio && !audio.muted && Number(audio.volume || 0) > 0;
  });
  expect(restored).toBe(true);
});
