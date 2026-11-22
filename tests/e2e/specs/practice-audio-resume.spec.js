const { test, expect } = require('@playwright/test');
const path = require('path');

const root = path.resolve(__dirname, '../../..');

async function openRound(page, captureSupported = true) {
  await page.route('https://quiz.test/**', route => {
    if (new URL(route.request().url()).pathname === '/word.mp3') {
      return route.fulfill({ path: path.join(root, 'media/right-answer.mp3'), contentType: 'audio/mpeg' });
    }
    return route.fulfill({
      contentType: 'text/html',
      body: '<audio src="/word.mp3" loop></audio><button>Play</button><span class="bar"></span>'
    });
  });
  await page.goto('https://quiz.test/');
  await page.addScriptTag({ path: path.join(root, 'js/flashcard-widget/audio-visualizer.js') });
  await page.evaluate(supported => {
    const audio = document.querySelector('audio');
    if (!supported) {
      audio.captureStream = audio.mozCaptureStream = undefined;
    } else {
      const capture = audio.captureStream.bind(audio);
      window.capturedTracks = [];
      audio.captureStream = () => {
        const stream = capture();
        window.capturedTracks.push(...stream.getAudioTracks());
        return stream;
      };
    }
    window.speakerConnections = 0;
    window.meters = [];
    if (window.AudioNode && window.AudioContext) {
      const nativeConnect = AudioNode.prototype.connect;
      AudioNode.prototype.connect = function (target, ...args) {
        if (target === this.context.destination) window.speakerConnections++;
        return nativeConnect.call(this, target, ...args);
      };
      const nativeCreateAnalyser = AudioContext.prototype.createAnalyser;
      AudioContext.prototype.createAnalyser = function () {
        window.meters.push(this);
        return nativeCreateAnalyser.call(this);
      };
    }
    window.mini = window.LLFlashcards.AudioVisualizer.createMiniVisualizer();
    window.mini.attach(audio, document.body);
    document.querySelector('button').onclick = () => audio.play();
  }, captureSupported);
  await page.getByRole('button', { name: 'Play' }).click();
  await page.waitForFunction(() => document.querySelector('audio').currentTime > 0);
}

test('practice audio survives freezing the page and suspending or detaching its visualizer', async ({ page, context }) => {
  await openRound(page);
  await page.waitForFunction(() => parseFloat(document.querySelector('.bar').style.getPropertyValue('--level')) > 0);
  expect(await page.evaluate(() => window.speakerConnections)).toBe(0);
  expect(await page.evaluate(() => window.meters[0].sinkId.type)).toBe('none');
  await page.evaluate(async () => {
    const audio = document.querySelector('audio');
    // Probe the actual decoded speaker signal on an independent context.
    // This also fails if the visualizer has taken exclusive ownership of the element.
    const output = new AudioContext();
    const source = output.createMediaElementSource(audio);
    const probe = output.createAnalyser();
    source.connect(probe);
    probe.connect(output.destination);
    await output.resume();
    window.hasFreshSignal = async () => {
      const samples = new Float32Array(probe.fftSize);
      probe.getFloatTimeDomainData(samples);
      const previous = samples.slice();
      await new Promise(resolve => setTimeout(resolve, 60));
      probe.getFloatTimeDomainData(samples);
      return samples.some((value, i) => Math.abs(value) > 0.02 && Math.abs(value - previous[i]) > 0.01);
    };
  });
  await page.waitForFunction(() => window.hasFreshSignal());
  // Headless Chromium does not hide a page when a physical screen locks.
  // Deliver that transition while the player is still active, then freeze the page.
  await page.evaluate(() => {
    Object.defineProperty(document, 'hidden', { configurable: true, value: true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  expect(await page.evaluate(() => window.capturedTracks[0].readyState)).toBe('ended');
  await page.evaluate(() => document.querySelector('audio').pause());
  const lifecycle = await context.newCDPSession(page);
  await lifecycle.send('Page.setWebLifecycleState', { state: 'frozen' });
  await lifecycle.send('Page.setWebLifecycleState', { state: 'active' });
  await lifecycle.detach();
  const pausedOnReturn = await page.evaluate(() => {
    delete document.hidden;
    document.dispatchEvent(new Event('visibilitychange'));
    return document.querySelector('audio').paused;
  });
  expect(pausedOnReturn).toBe(true);
  await page.getByRole('button', { name: 'Play' }).click();
  await page.waitForFunction(() => window.hasFreshSignal());
  expect(await page.evaluate(() => window.speakerConnections)).toBe(1); // Only the test probe.
  await page.evaluate(() => window.meters[0].suspend());
  await page.waitForFunction(() => window.hasFreshSignal());
  await page.evaluate(() => window.mini.stop());
  await page.waitForFunction(() => window.hasFreshSignal());
});

test('practice replays through the native player when audio analysis is unavailable', async ({ page }) => {
  await openRound(page, false);
  const replay = await page.evaluate(async () => {
    const audio = document.querySelector('audio');
    audio.pause();
    audio.loop = false;
    audio.currentTime = 0;
    const ended = new Promise(resolve => audio.addEventListener('ended', resolve, { once: true }));
    await audio.play();
    await ended;
    return { error: audio.error, duration: audio.duration, meters: window.meters.length };
  });
  expect(replay.error).toBeNull();
  expect(replay.duration).toBeGreaterThan(0);
  expect(replay.meters).toBe(0);
});
