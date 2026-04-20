const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const effectsSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/flashcard-widget/effects.js'),
  'utf8'
);

test('flashcard confetti wrapper reuses one instance and resets cleanly', async ({ page }) => {
  await page.setContent('<!doctype html><html><body></body></html>');

  await page.evaluate(() => {
    const harness = {
      createCalls: [],
      fireCalls: [],
      resetCalls: 0
    };

    function confetti() {}
    confetti.create = function (canvas, options) {
      harness.createCalls.push({
        canvasId: canvas.id,
        options: Object.assign({}, options)
      });

      const instance = function (payload) {
        harness.fireCalls.push(Object.assign({}, payload));
      };

      instance.reset = function () {
        harness.resetCalls += 1;
      };

      return instance;
    };

    window.__llConfettiHarness = harness;
    window.confetti = confetti;
  });

  await page.addScriptTag({ content: effectsSource });

  const summary = await page.evaluate(async () => {
    const effects = window.LLFlashcards && window.LLFlashcards.Effects;

    effects.startConfetti({ duration: 20, particleCount: 4 });
    await new Promise((resolve) => setTimeout(resolve, 40));

    effects.startConfetti({ duration: 20, particleCount: 4 });
    await new Promise((resolve) => setTimeout(resolve, 40));

    const canvasPresentBeforeReset = !!document.getElementById('confetti-canvas');

    effects.resetConfetti();
    await new Promise((resolve) => setTimeout(resolve, 20));

    const canvasPresentAfterReset = !!document.getElementById('confetti-canvas');

    effects.startConfetti({ duration: 20, particleCount: 4 });
    await new Promise((resolve) => setTimeout(resolve, 40));

    return {
      createCalls: window.__llConfettiHarness.createCalls,
      fireCalls: window.__llConfettiHarness.fireCalls.length,
      resetCalls: window.__llConfettiHarness.resetCalls,
      canvasPresentBeforeReset,
      canvasPresentAfterReset
    };
  });

  expect(summary.createCalls).toHaveLength(2);
  expect(summary.createCalls[0].canvasId).toBe('confetti-canvas');
  expect(summary.createCalls[0].options).toMatchObject({ resize: true, useWorker: false });
  expect(summary.resetCalls).toBe(1);
  expect(summary.fireCalls).toBeGreaterThan(0);
  expect(summary.canvasPresentBeforeReset).toBe(true);
  expect(summary.canvasPresentAfterReset).toBe(false);
});
