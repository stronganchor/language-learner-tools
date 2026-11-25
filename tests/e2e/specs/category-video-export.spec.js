const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs/promises');

const rendererPath = path.resolve(__dirname, '../../../js/category-video-renderer.js');

// These tests use the real canvas, AudioContext, MediaRecorder and media decoder.
// Fixture pictures and PCM audio are generated locally; WordPress is not needed.
async function mountRenderer(page) {
  await page.setContent('<button id="activate">Start video tools</button><canvas id="video"></canvas>');
  await page.click('#activate');
  await page.addScriptTag({ path: rendererPath });
  await page.evaluate(async () => {
    const urls = [];
    function blobUrl(blob) {
      const url = URL.createObjectURL(blob);
      urls.push(url);
      return url;
    }
    function audioUrl(frequency, seconds = 0.35, rate = 24000) {
      const samples = Math.round(rate * seconds);
      const bytes = new ArrayBuffer(44 + samples * 2);
      const view = new DataView(bytes);
      function text(offset, value) {
        for (let i = 0; i < value.length; i += 1) view.setUint8(offset + i, value.charCodeAt(i));
      }
      text(0, 'RIFF');
      view.setUint32(4, 36 + samples * 2, true);
      text(8, 'WAVE');
      text(12, 'fmt ');
      view.setUint32(16, 16, true);
      view.setUint16(20, 1, true);
      view.setUint16(22, 1, true);
      view.setUint32(24, rate, true);
      view.setUint32(28, rate * 2, true);
      view.setUint16(32, 2, true);
      view.setUint16(34, 16, true);
      text(36, 'data');
      view.setUint32(40, samples * 2, true);
      for (let i = 0; i < samples; i += 1) {
        const fade = Math.min(1, i / 240, (samples - i) / 240);
        view.setInt16(44 + i * 2, Math.sin(i * frequency * Math.PI * 2 / rate) * 12000 * fade, true);
      }
      return blobUrl(new Blob([bytes], { type: 'audio/wav' }));
    }
    async function imageUrl(color) {
      const picture = document.createElement('canvas');
      picture.width = 300;
      picture.height = 240;
      const ctx = picture.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, 300, 240);
      ctx.fillStyle = color;
      ctx.beginPath();
      ctx.arc(150, 120, 80, 0, Math.PI * 2);
      ctx.fill();
      return blobUrl(await new Promise(resolve => picture.toBlob(resolve, 'image/png')));
    }
    window.videoFixture = {
      urls,
      audioUrl,
      progress: [],
      items: [
        { id: 101, text: 'Sêv — ı ş ê', translation: 'apple', image_url: await imageUrl('#d93737'), audio_url: audioUrl(440) },
        { id: 102, text: 'Muz', translation: 'banana', image_url: await imageUrl('#f0c737'), audio_url: audioUrl(660) }
      ],
      options: { layout: 'portrait', repeats: 1, gap: 0.5, recall: 0, showText: true, title: 'Fruit' }
    };
    window.videoRenderer = window.LLToolsCategoryVideoRenderer.create({
      canvas: document.querySelector('#video'),
      onProgress: update => window.videoFixture.progress.push(update)
    });
    window.inspectVideoExport = async (result) => {
      const element = document.createElement('video');
      element.muted = true;
      element.playsInline = true;
      const url = blobUrl(result.blob);
      const metadata = new Promise((resolve, reject) => {
        element.onloadedmetadata = resolve;
        element.onerror = () => reject(new Error('The exported video cannot be decoded.'));
      });
      element.src = url;
      document.body.appendChild(element);
      await metadata;
      const stream = element.captureStream();
      await element.play();
      const deadline = performance.now() + 3000;
      while (stream.getTracks().length < 2 && performance.now() < deadline) {
        await new Promise(resolve => setTimeout(resolve, 20));
      }
      const decoder = new AudioContext();
      const decodedAudio = await decoder.decodeAudioData(await result.blob.arrayBuffer());
      const samples = decodedAudio.getChannelData(0);
      let peak = 0;
      let squares = 0;
      for (const value of samples) {
        peak = Math.max(peak, Math.abs(value));
        squares += value * value;
      }
      await decoder.close();
      const properties = {
        width: element.videoWidth,
        height: element.videoHeight,
        tracks: stream.getTracks().map(track => track.kind).sort(),
        mimeType: result.mimeType,
        extension: result.extension,
        duration: result.duration,
        bytes: result.blob.size,
        audioPeak: peak,
        audioRms: Math.sqrt(squares / samples.length),
        base64: await new Promise(resolve => {
          const reader = new FileReader();
          reader.onload = () => resolve(reader.result.slice(reader.result.indexOf(';base64,') + 8));
          reader.readAsDataURL(result.blob);
        })
      };
      element.pause();
      stream.getTracks().forEach(track => track.stop());
      element.removeAttribute('src');
      element.load();
      element.remove();
      return properties;
    };
  });
}

test.afterEach(async ({ page }) => {
  await page.evaluate(() => {
    if (window.videoRenderer) window.videoRenderer.dispose();
    if (window.videoFixture) window.videoFixture.urls.forEach(url => URL.revokeObjectURL(url));
  });
});

test('category video exports a playable portrait with real picture and audio tracks', async ({ page }, testInfo) => {
  await mountRenderer(page);
  const video = await page.evaluate(async () => {
    await window.videoRenderer.prepare(window.videoFixture.items, window.videoFixture.options);
    window.videoRenderer.draw(0);
    return window.inspectVideoExport(await window.videoRenderer.play({ record: true }));
  });
  expect(video.width).toBe(1080);
  expect(video.height).toBe(1920);
  expect(video.tracks).toEqual(['audio', 'video']);
  expect(video.audioPeak).toBeGreaterThan(0.1);
  expect(video.audioRms).toBeGreaterThan(0.01);
  expect(video.bytes).toBeGreaterThan(5000);
  expect(video.duration).toBeGreaterThanOrEqual(1.7);
  expect(video.duration).toBeLessThan(10);
  expect(video.mimeType).toMatch(/^video\/(webm|mp4)/);
  expect(video.extension).toMatch(/^(webm|mp4)$/);
  const outputPath = testInfo.outputPath(`category-video-fixture.${video.extension}`);
  await fs.writeFile(outputPath, Buffer.from(video.base64, 'base64'));
  await testInfo.attach('category-video-fixture', { path: outputPath, contentType: video.mimeType });
});

test('category video cancels recording and can create a fresh recording afterward', async ({ page }) => {
  await mountRenderer(page);
  await page.evaluate(async () => {
    await window.videoRenderer.prepare(window.videoFixture.items, {
      ...window.videoFixture.options, repeats: 3, gap: 1, recall: 2
    });
    window.cancelledVideo = window.videoRenderer.play({ record: true }).then(
      result => ({ completed: true, hasBlob: Boolean(result && result.blob) }),
      error => ({ completed: false, name: error.name, message: error.message })
    );
  });
  await expect.poll(() => page.evaluate(() => window.videoFixture.progress.length)).toBeGreaterThan(0);
  const cancellation = await page.evaluate(async () => {
    window.videoRenderer.cancel();
    return window.cancelledVideo;
  });
  expect(cancellation.completed).toBe(false);
  const video = await page.evaluate(async () => {
    await window.videoRenderer.prepare(window.videoFixture.items.slice(0, 1), {
      ...window.videoFixture.options, layout: 'square'
    });
    return window.inspectVideoExport(await window.videoRenderer.play({ record: true }));
  });
  expect(video.width).toBe(video.height);
  expect(video.tracks).toEqual(['audio', 'video']);
  expect(video.bytes).toBeGreaterThan(2000);
});

test('category video refuses an unreadable image or audio and recovers with valid media', async ({ page }) => {
  await mountRenderer(page);
  const results = await page.evaluate(async () => {
    const outcomes = [];
    for (const field of ['image_url', 'audio_url']) {
      const badUrl = URL.createObjectURL(new Blob(['invalid media bytes'], {
        type: field === 'image_url' ? 'image/png' : 'audio/wav'
      }));
      window.videoFixture.urls.push(badUrl);
      try {
        await window.videoRenderer.prepare([{ ...window.videoFixture.items[0], [field]: badUrl }], window.videoFixture.options);
        outcomes.push({ field, rejected: false });
      } catch (error) {
        outcomes.push({ field, rejected: true, message: error.message });
      }
    }
    await window.videoRenderer.prepare(window.videoFixture.items.slice(0, 1), {
      ...window.videoFixture.options, layout: 'landscape'
    });
    window.videoRenderer.draw(0);
    return { outcomes, width: document.querySelector('#video').width, height: document.querySelector('#video').height };
  });
  expect(results.outcomes).toHaveLength(2);
  for (const outcome of results.outcomes) {
    expect(outcome.rejected, outcome.field).toBe(true);
    expect(outcome.message).toBeTruthy();
  }
  expect(results.width).toBe(1920);
  expect(results.height).toBe(1080);
});

test('category video hides the answer during recall time and honors pictures-only playback', async ({ page }) => {
  await mountRenderer(page);
  const frames = await page.evaluate(async () => {
    const canvas = document.querySelector('#video');
    const context = canvas.getContext('2d');
    const fillRect = context.fillRect.bind(context);
    const fillText = context.fillText.bind(context);
    let text = [];
    let phase = 'recall';
    const observed = [];
    // Observe actual drawing calls without replacing any rendering or media APIs.
    context.fillRect = (...args) => {
      if (args[0] === 0 && args[1] === 0 && args[2] === canvas.width && args[3] === canvas.height) text = [];
      return fillRect(...args);
    };
    context.fillText = (...args) => { text.push(args[0]); return fillText(...args); };
    const updates = window.videoFixture.progress;
    updates.push = (update) => {
      if (update.phase === 'playing') observed.push({ phase, elapsed: update.elapsed, visible: text.includes(window.videoFixture.items[0].text) });
      return Array.prototype.push.call(updates, update);
    };
    await window.videoRenderer.prepare(window.videoFixture.items.slice(0, 1), {
      ...window.videoFixture.options, recall: 0.5
    });
    await window.videoRenderer.play();
    phase = 'pictures';
    await window.videoRenderer.prepare(window.videoFixture.items.slice(0, 1), {
      ...window.videoFixture.options, showText: false
    });
    await window.videoRenderer.play();
    return observed;
  });
  const beforeAnswer = frames.filter(frame => frame.phase === 'recall' && frame.elapsed < 0.5);
  const afterAnswer = frames.filter(frame => frame.phase === 'recall' && frame.elapsed >= 0.5);
  const picturesOnly = frames.filter(frame => frame.phase === 'pictures');
  expect(beforeAnswer.length).toBeGreaterThan(0);
  expect(afterAnswer.length).toBeGreaterThan(0);
  expect(picturesOnly.length).toBeGreaterThan(0);
  expect(beforeAnswer.every(frame => !frame.visible)).toBe(true);
  expect(afterAnswer.every(frame => frame.visible)).toBe(true);
  expect(picturesOnly.every(frame => !frame.visible)).toBe(true);
});


test('category video rejects a long source before Web Audio decodes it', async ({ page }) => {
  await mountRenderer(page);
  const result = await page.evaluate(async () => {
    const nativeDecode = AudioContext.prototype.decodeAudioData;
    let calls = 0;
    AudioContext.prototype.decodeAudioData = function (...args) {
      calls++;
      return nativeDecode.apply(this, args);
    };
    const longAudio = window.videoFixture.audioUrl(440, 32, 8000);
    const item = { ...window.videoFixture.items[0], audio_url: longAudio };
    let errorCode;
    try { await window.videoRenderer.prepare([item], window.videoFixture.options); }
    catch (error) { errorCode = error.code; }
    const callsBeforeRetry = calls;
    await window.videoRenderer.prepare(window.videoFixture.items.slice(0, 1), window.videoFixture.options);
    return { errorCode, callsBeforeRetry, calls };
  });
  expect(result).toEqual({ errorCode: 'audio_too_long', callsBeforeRetry: 0, calls: 1 });
});

test('category video keeps the decode slot across cancel and renderer replacement', async ({ page }) => {
  await mountRenderer(page);
  await page.evaluate(() => {
    const nativeDecode = AudioContext.prototype.decodeAudioData;
    window.videoDecodeCalls = 0;
    let releaseFirst;
    const gate = new Promise(resolve => { releaseFirst = resolve; });
    window.releaseVideoDecode = releaseFirst;
    AudioContext.prototype.decodeAudioData = function (...args) {
      window.videoDecodeCalls++;
      if (window.videoDecodeCalls === 1) return gate.then(() => nativeDecode.apply(this, args));
      return nativeDecode.apply(this, args);
    };
    const fixture = window.videoFixture;
    window.firstVideoPrepare = window.videoRenderer.prepare(fixture.items, fixture.options)
      .then(() => 'unexpected_success', error => error.code);
  });
  await expect.poll(() => page.evaluate(() => window.videoDecodeCalls)).toBe(1);
  const canceled = await page.evaluate(async () => {
    const previous = window.videoRenderer;
    previous.cancel();
    const reason = await window.firstVideoPrepare;
    window.videoRenderer = window.LLToolsCategoryVideoRenderer.create({ canvas: document.querySelector('#video') });
    const fixture = window.videoFixture;
    window.nextVideoPrepare = window.videoRenderer.prepare(fixture.items.slice(0, 1), fixture.options);
    window.disposePreviousVideo = () => previous.dispose();
    return reason;
  });
  expect(canceled).toBe('canceled');
  await page.waitForTimeout(200);
  expect(await page.evaluate(() => window.videoDecodeCalls)).toBe(1);
  const result = await page.evaluate(async () => {
    window.releaseVideoDecode();
    try { return await window.nextVideoPrepare; }
    finally { window.disposePreviousVideo(); }
  });
  expect(result.itemCount).toBe(1);
  expect(await page.evaluate(() => window.videoDecodeCalls)).toBe(2);
});

test('category video accepts a short browser recording without a declared duration', async ({ page }) => {
  await mountRenderer(page);
  const prepared = await page.evaluate(async () => {
    const context = new AudioContext();
    await context.resume();
    const output = context.createMediaStreamDestination();
    const oscillator = context.createOscillator();
    oscillator.connect(output);
    const recorder = new MediaRecorder(output.stream, { mimeType: 'audio/webm;codecs=opus' });
    const blob = await new Promise(resolve => {
      recorder.addEventListener('dataavailable', event => resolve(event.data), { once: true });
      recorder.start();
      oscillator.start();
      setTimeout(() => {
        oscillator.stop();
        recorder.stop();
      }, 350);
    });
    output.stream.getTracks().forEach(track => track.stop());
    await context.close();
    const fixture = window.videoFixture;
    const audioUrl = URL.createObjectURL(blob);
    fixture.urls.push(audioUrl);
    return await window.videoRenderer.prepare([{ ...fixture.items[0], audio_url: audioUrl }], fixture.options);
  });
  expect(prepared.itemCount).toBe(1);
  expect(prepared.duration).toBeLessThan(3);
});
