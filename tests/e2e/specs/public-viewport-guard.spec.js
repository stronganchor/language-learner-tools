const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const viewportGuardSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/public-viewport-guard.js'),
  'utf8'
);

test('shared viewport guard blocks new zoom but lets users pinch back out if zoom slips through', async ({ page }) => {
  await page.addInitScript(() => {
    let scale = 1;
    const listeners = {};
    const viewport = {
      width: 390,
      height: 844,
      get scale() {
        return scale;
      },
      addEventListener(type, handler) {
        if (!listeners[type]) {
          listeners[type] = [];
        }
        listeners[type].push(handler);
      },
      removeEventListener(type, handler) {
        if (!listeners[type]) {
          return;
        }
        listeners[type] = listeners[type].filter((candidate) => candidate !== handler);
      },
      setScale(nextScale) {
        scale = nextScale;
        (listeners.resize || []).slice().forEach((handler) => handler.call(viewport));
      }
    };

    try {
      Object.defineProperty(window, 'visualViewport', {
        configurable: true,
        enumerable: true,
        get() {
          return viewport;
        }
      });
    } catch (error) {
      window.__llViewportOverrideError = String(error && error.message ? error.message : error);
    }

    window.__llTestViewport = viewport;
  });

  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('about:blank');
  await page.setContent(`
    <!doctype html>
    <html>
      <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
      </head>
      <body>
        <main data-ll-page-root>Viewport guard test</main>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: viewportGuardSource });

  const results = await page.evaluate(() => {
    function buildTouch(point) {
      return {
        clientX: point[0],
        clientY: point[1]
      };
    }

    function dispatchTouch(type, points) {
      const event = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(event, 'touches', {
        configurable: true,
        value: points.map(buildTouch)
      });
      document.dispatchEvent(event);
      return event.defaultPrevented;
    }

    const pinchBlockedAtScaleOne = dispatchTouch('touchmove', [[0, 0], [100, 0]]);

    window.__llTestViewport.setScale(1.25);

    dispatchTouch('touchstart', [[0, 0], [100, 0]]);
    const pinchOutToRecoverBlocked = dispatchTouch('touchmove', [[0, 0], [80, 0]]);

    dispatchTouch('touchstart', [[0, 0], [100, 0]]);
    const pinchFurtherInBlocked = dispatchTouch('touchmove', [[0, 0], [132, 0]]);

    return {
      overrideError: window.__llViewportOverrideError || '',
      metaContent: document.querySelector('meta[name="viewport"]').getAttribute('content') || '',
      htmlZoomedState: document.documentElement.getAttribute('data-ll-viewport-zoomed') || '',
      pinchBlockedAtScaleOne,
      pinchOutToRecoverBlocked,
      pinchFurtherInBlocked,
      reportedScale: window.LLToolsViewportGuard ? window.LLToolsViewportGuard.getScale() : 0,
      isZoomed: !!(window.LLToolsViewportGuard && window.LLToolsViewportGuard.isZoomed())
    };
  });

  expect(results.overrideError).toBe('');
  expect(results.metaContent).toContain('maximum-scale=1');
  expect(results.metaContent).toContain('user-scalable=no');
  expect(results.htmlZoomedState).toBe('1');
  expect(results.reportedScale).toBeCloseTo(1.25, 2);
  expect(results.isZoomed).toBe(true);
  expect(results.pinchBlockedAtScaleOne).toBe(true);
  expect(results.pinchOutToRecoverBlocked).toBe(false);
  expect(results.pinchFurtherInBlocked).toBe(true);
});
