const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const visualizerScriptPath = path.resolve(__dirname, '../../../js/flashcard-widget/audio-visualizer.js');
const baseCssPath = path.resolve(__dirname, '../../../css/flashcard/base.css');

test('listening visualizer resumes on play events and re-shows bars after countdown hide', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-listening-visualizer" class="ll-tools-loading-animation ll-tools-loading-animation--visualizer"></div>');

  await page.evaluate(() => {
    const rafQueue = [];
    window.requestAnimationFrame = function (cb) {
      rafQueue.push(cb);
      return rafQueue.length;
    };
    window.cancelAnimationFrame = function () {};
    window.__llFlushRaf = function (limit) {
      const max = Math.max(1, Number(limit) || 1);
      for (let i = 0; i < max; i++) {
        const cb = rafQueue.shift();
        if (typeof cb !== 'function') {
          break;
        }
        cb();
      }
    };

    function FakeAnalyser() {
      this.fftSize = 256;
      this.smoothingTimeConstant = 0.65;
      this.frequencyBinCount = 64;
    }
    FakeAnalyser.prototype.connect = function () {};
    FakeAnalyser.prototype.getByteFrequencyData = function (arr) {
      for (let i = 0; i < arr.length; i++) {
        // Low-but-realistic signal should still stay JS-driven (not fallback animation).
        arr[i] = 22;
      }
    };
    FakeAnalyser.prototype.getByteTimeDomainData = function (arr) {
      for (let i = 0; i < arr.length; i++) {
        arr[i] = (i % 2 === 0) ? 132 : 124;
      }
    };

    function FakeAudioContext() {
      this.state = 'running';
      this.destination = {};
    }
    FakeAudioContext.prototype.createAnalyser = function () {
      return new FakeAnalyser();
    };
    FakeAudioContext.prototype.createMediaElementSource = function () {
      return {
        connect: function () {},
        disconnect: function () {}
      };
    };
    FakeAudioContext.prototype.resume = function () {
      this.state = 'running';
      return Promise.resolve();
    };

    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
  });

  const visualizerSource = fs.readFileSync(visualizerScriptPath, 'utf8');
  await page.addScriptTag({ content: visualizerSource });

  const result = await page.evaluate(() => {
    const api = window.LLFlashcards && window.LLFlashcards.AudioVisualizer;
    if (!api || typeof api.prepareForListening !== 'function' || typeof api.followAudio !== 'function') {
      return { error: 'missing visualizer api' };
    }

    api.prepareForListening();

    const el = document.getElementById('ll-tools-listening-visualizer');
    const bars = Array.from(el.querySelectorAll('.ll-tools-visualizer-bar'));
    if (!bars.length) {
      return { error: 'bars not initialized' };
    }

    bars.forEach((bar) => {
      bar.style.display = 'none';
      bar.style.opacity = '0';
    });

    const audio = document.createElement('div');
    audio.paused = true;
    audio.ended = false;
    audio.duration = 2;
    audio.currentTime = 0;

    api.followAudio(audio);

    audio.paused = false;
    audio.dispatchEvent(new Event('play'));

    // Flush enough frames to catch regressions that flip into fallback after sustained low energy.
    window.__llFlushRaf(130);

    const firstLevel = parseFloat((bars[0].style.getPropertyValue('--level') || '0').trim()) || 0;

    return {
      hasActiveClass: el.classList.contains('ll-tools-loading-animation--active'),
      hasJsClass: el.classList.contains('ll-tools-loading-animation--js'),
      hasFallbackClass: el.classList.contains('ll-tools-loading-animation--fallback'),
      firstBarDisplay: bars[0].style.display || '',
      firstBarOpacity: bars[0].style.opacity || '',
      firstLevel: firstLevel
    };
  });

  expect(result.error).toBeUndefined();
  expect(result.hasActiveClass).toBe(true);
  expect(result.hasJsClass).toBe(true);
  expect(result.hasFallbackClass).toBe(false);
  expect(result.firstBarDisplay).toBe('');
  expect(result.firstBarOpacity).toBe('');
  expect(result.firstLevel).toBeGreaterThan(0.01);
});

test('listening visualizer uses centered fallback pulse after pause', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-listening-visualizer" class="ll-tools-loading-animation ll-tools-loading-animation--visualizer"></div>');

  await page.evaluate(() => {
    const rafQueue = [];
    window.requestAnimationFrame = function (cb) {
      rafQueue.push(cb);
      return rafQueue.length;
    };
    window.cancelAnimationFrame = function () {};
    window.__llFlushRaf = function (limit) {
      const max = Math.max(1, Number(limit) || 1);
      for (let i = 0; i < max; i++) {
        const cb = rafQueue.shift();
        if (typeof cb !== 'function') {
          break;
        }
        cb();
      }
    };

    function FakeAnalyser() {
      this.fftSize = 256;
      this.smoothingTimeConstant = 0.65;
      this.frequencyBinCount = 64;
    }
    FakeAnalyser.prototype.connect = function () {};
    FakeAnalyser.prototype.getByteFrequencyData = function (arr) {
      for (let i = 0; i < arr.length; i++) {
        arr[i] = 40;
      }
    };
    FakeAnalyser.prototype.getByteTimeDomainData = function (arr) {
      for (let i = 0; i < arr.length; i++) {
        arr[i] = (i % 2 === 0) ? 136 : 120;
      }
    };

    function FakeAudioContext() {
      this.state = 'running';
      this.destination = {};
    }
    FakeAudioContext.prototype.createAnalyser = function () {
      return new FakeAnalyser();
    };
    FakeAudioContext.prototype.createMediaElementSource = function () {
      return {
        connect: function () {},
        disconnect: function () {}
      };
    };
    FakeAudioContext.prototype.resume = function () {
      this.state = 'running';
      return Promise.resolve();
    };

    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
  });

  const visualizerSource = fs.readFileSync(visualizerScriptPath, 'utf8');
  const baseCssSource = fs.readFileSync(baseCssPath, 'utf8');
  await page.addStyleTag({ content: baseCssSource });
  await page.addScriptTag({ content: visualizerSource });

  const result = await page.evaluate(() => {
    const api = window.LLFlashcards && window.LLFlashcards.AudioVisualizer;
    if (!api || typeof api.prepareForListening !== 'function' || typeof api.followAudio !== 'function') {
      return { error: 'missing visualizer api' };
    }

    api.prepareForListening();

    const el = document.getElementById('ll-tools-listening-visualizer');
    const audio = document.createElement('div');
    audio.paused = true;
    audio.ended = false;
    audio.duration = 2;
    audio.currentTime = 0;

    api.followAudio(audio);

    audio.paused = false;
    audio.dispatchEvent(new Event('play'));
    window.__llFlushRaf(8);

    audio.paused = true;
    audio.dispatchEvent(new Event('pause'));

    const bar = el.querySelector('.ll-tools-visualizer-bar');
    if (!bar) {
      return { error: 'missing visualizer bar' };
    }

    const style = window.getComputedStyle(bar);
    const originParts = style.transformOrigin.split(' ');
    const originY = parseFloat(originParts[1]) || 0;
    const height = parseFloat(style.height) || 0;

    return {
      hasPausedClass: el.classList.contains('ll-tools-loading-animation--paused'),
      hasFallbackClass: el.classList.contains('ll-tools-loading-animation--fallback'),
      height: height,
      centerOffset: Math.abs(originY - (height / 2)),
      bottomOffset: Math.abs(originY - height)
    };
  });

  expect(result.error).toBeUndefined();
  expect(result.hasPausedClass).toBe(true);
  expect(result.hasFallbackClass).toBe(true);
  expect(result.height).toBeGreaterThan(0);
  expect(result.centerOffset).toBeLessThan(0.75);
  expect(result.bottomOffset).toBeGreaterThan(1);
});

test('listening visualizer warmup keeps analyser-driven bars when autoplay resume would be blocked', async ({ page }) => {
  await page.goto('about:blank');
  await page.setContent('<div id="ll-tools-listening-visualizer" class="ll-tools-loading-animation ll-tools-loading-animation--visualizer"></div>');

  await page.evaluate(() => {
    const rafQueue = [];
    window.requestAnimationFrame = function (cb) {
      rafQueue.push(cb);
      return rafQueue.length;
    };
    window.cancelAnimationFrame = function () {};
    window.__llFlushRaf = function (limit) {
      const max = Math.max(1, Number(limit) || 1);
      for (let i = 0; i < max; i++) {
        const cb = rafQueue.shift();
        if (typeof cb !== 'function') {
          break;
        }
        cb();
      }
    };

    window.__allowResume = false;

    function FakeAnalyser() {
      this.fftSize = 256;
      this.smoothingTimeConstant = 0.65;
      this.frequencyBinCount = 64;
    }
    FakeAnalyser.prototype.connect = function () {};
    FakeAnalyser.prototype.getByteFrequencyData = function (arr) {
      for (let i = 0; i < arr.length; i++) {
        arr[i] = 40;
      }
    };
    FakeAnalyser.prototype.getByteTimeDomainData = function (arr) {
      for (let i = 0; i < arr.length; i++) {
        arr[i] = (i % 2 === 0) ? 136 : 120;
      }
    };

    function FakeAudioContext() {
      this.state = 'suspended';
      this.destination = {};
    }
    FakeAudioContext.prototype.createAnalyser = function () {
      return new FakeAnalyser();
    };
    FakeAudioContext.prototype.createMediaElementSource = function () {
      return {
        connect: function () {},
        disconnect: function () {}
      };
    };
    FakeAudioContext.prototype.resume = function () {
      if (!window.__allowResume) {
        return Promise.reject(new Error('blocked'));
      }
      this.state = 'running';
      return Promise.resolve();
    };

    window.AudioContext = FakeAudioContext;
    window.webkitAudioContext = FakeAudioContext;
  });

  const visualizerSource = fs.readFileSync(visualizerScriptPath, 'utf8');
  await page.addScriptTag({ content: visualizerSource });

  const result = await page.evaluate(async () => {
    const api = window.LLFlashcards && window.LLFlashcards.AudioVisualizer;
    if (!api || typeof api.prepareForListening !== 'function' || typeof api.followAudio !== 'function' || typeof api.warmup !== 'function') {
      return { error: 'missing visualizer api' };
    }

    api.prepareForListening();

    // Simulate user gesture warmup before autoplay.
    window.__allowResume = true;
    const warmed = await api.warmup();
    window.__allowResume = false;

    const el = document.getElementById('ll-tools-listening-visualizer');
    const bars = Array.from(el.querySelectorAll('.ll-tools-visualizer-bar'));
    if (!bars.length) {
      return { error: 'bars not initialized' };
    }

    const audio = document.createElement('div');
    audio.paused = true;
    audio.ended = false;
    audio.duration = 2;
    audio.currentTime = 0;

    api.followAudio(audio);
    audio.paused = false;
    audio.dispatchEvent(new Event('play'));

    window.__llFlushRaf(12);

    const firstLevel = parseFloat((bars[0].style.getPropertyValue('--level') || '0').trim()) || 0;
    return {
      warmed: !!warmed,
      hasJsClass: el.classList.contains('ll-tools-loading-animation--js'),
      hasFallbackClass: el.classList.contains('ll-tools-loading-animation--fallback'),
      firstLevel: firstLevel
    };
  });

  expect(result.error).toBeUndefined();
  expect(result.warmed).toBe(true);
  expect(result.hasJsClass).toBe(true);
  expect(result.hasFallbackClass).toBe(false);
  expect(result.firstLevel).toBeGreaterThan(0.01);
});
