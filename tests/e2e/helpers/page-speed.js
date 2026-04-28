function readEnvNumber(name, fallback) {
  const rawValue = process.env[name];
  if (typeof rawValue === 'undefined' || rawValue === null || String(rawValue).trim() === '') {
    return fallback;
  }

  const parsed = Number(rawValue);
  return Number.isFinite(parsed) ? parsed : fallback;
}

async function warmPageSpeedRoute(request, path, options = {}) {
  const timeoutMs = Math.max(1, Number(options.timeoutMs) || 25000);
  const attempts = Math.max(1, Math.round(Number(options.attempts) || 1));
  const retryDelayMs = Math.max(0, Number(options.retryDelayMs) || 0);
  let lastError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      const response = await request.get(path, { timeout: timeoutMs });
      if (response.ok()) {
        return response;
      }

      lastError = new Error(`Warmup request returned HTTP ${response.status()} ${response.statusText()}`);
    } catch (error) {
      lastError = error;
    }

    if (attempt < attempts && retryDelayMs > 0) {
      await new Promise((resolve) => setTimeout(resolve, retryDelayMs));
    }
  }

  throw lastError || new Error('Warmup request failed.');
}

function kbpsToBytesPerSecond(kbps) {
  const normalized = Math.max(0, Number(kbps) || 0);
  return Math.round((normalized * 1000) / 8);
}

function buildPageSpeedProfile() {
  return {
    latencyMs: readEnvNumber('LL_E2E_PAGE_SPEED_LATENCY_MS', 150),
    downloadKbps: readEnvNumber('LL_E2E_PAGE_SPEED_DOWNLOAD_KBPS', 1600),
    uploadKbps: readEnvNumber('LL_E2E_PAGE_SPEED_UPLOAD_KBPS', 750),
    cpuSlowdownRate: Math.max(1, readEnvNumber('LL_E2E_PAGE_SPEED_CPU_SLOWDOWN_RATE', 1)),
    connectionType: 'cellular4g'
  };
}

async function createPageSpeedSession(page, overrides = {}) {
  const profile = Object.assign(buildPageSpeedProfile(), overrides || {});
  const client = await page.context().newCDPSession(page);

  await client.send('Network.enable');
  await client.send('Network.clearBrowserCache');
  await client.send('Network.setCacheDisabled', { cacheDisabled: true });
  await client.send('Network.emulateNetworkConditions', {
    offline: false,
    latency: profile.latencyMs,
    downloadThroughput: kbpsToBytesPerSecond(profile.downloadKbps),
    uploadThroughput: kbpsToBytesPerSecond(profile.uploadKbps),
    connectionType: profile.connectionType
  });

  if (profile.cpuSlowdownRate > 1) {
    await client.send('Emulation.setCPUThrottlingRate', { rate: profile.cpuSlowdownRate });
  }

  return {
    client,
    profile,
    async dispose() {
      try {
        await client.send('Network.emulateNetworkConditions', {
          offline: false,
          latency: 0,
          downloadThroughput: -1,
          uploadThroughput: -1,
          connectionType: 'none'
        });
      } catch (error) {
        // Ignore cleanup failures when the page or browser has already closed.
      }

      try {
        await client.send('Network.setCacheDisabled', { cacheDisabled: false });
      } catch (error) {
        // Ignore cleanup failures when the page or browser has already closed.
      }

      if (profile.cpuSlowdownRate > 1) {
        try {
          await client.send('Emulation.setCPUThrottlingRate', { rate: 1 });
        } catch (error) {
          // Ignore cleanup failures when the page or browser has already closed.
        }
      }

      try {
        await client.detach();
      } catch (error) {
        // Ignore cleanup failures when the page or browser has already closed.
      }
    }
  };
}

async function waitForVisibleActionable(page, selector, timeoutMs) {
  const handle = await page.waitForFunction((actionableSelector) => {
    const isVisible = (element) => {
      if (!element) {
        return false;
      }

      const styles = window.getComputedStyle(element);
      if (styles.display === 'none' || styles.visibility === 'hidden' || styles.opacity === '0') {
        return false;
      }

      return element.getClientRects().length > 0;
    };

    const match = Array.from(document.querySelectorAll(actionableSelector)).find(isVisible);
    return match ? Math.round(performance.now()) : false;
  }, selector, { timeout: timeoutMs });

  try {
    return await handle.jsonValue();
  } finally {
    await handle.dispose();
  }
}

async function collectPageSpeedMetrics(page, actionableSelector, firstActionableMs) {
  return page.evaluate(({ selector, actionableAt }) => {
    const resourceEntries = performance.getEntriesByType('resource')
      .map((entry) => ({
        name: String(entry.name || ''),
        initiatorType: String(entry.initiatorType || ''),
        durationMs: Math.round(entry.duration || 0),
        transferSizeBytes: Number(entry.transferSize || 0),
        encodedBodySizeBytes: Number(entry.encodedBodySize || 0),
        decodedBodySizeBytes: Number(entry.decodedBodySize || 0)
      }));

    const navigationEntry = performance.getEntriesByType('navigation')[0] || null;
    const actionableElements = Array.from(document.querySelectorAll(selector));

    return {
      url: String(window.location.href || ''),
      actionableSelector: selector,
      actionableCount: actionableElements.length,
      firstActionableMs: Math.round(Number(actionableAt) || 0),
      domInteractiveMs: navigationEntry ? Math.round(navigationEntry.domInteractive || 0) : 0,
      domContentLoadedMs: navigationEntry ? Math.round(navigationEntry.domContentLoadedEventEnd || 0) : 0,
      loadEventMs: navigationEntry ? Math.round(navigationEntry.loadEventEnd || 0) : 0,
      responseStartMs: navigationEntry ? Math.round(navigationEntry.responseStart || 0) : 0,
      responseEndMs: navigationEntry ? Math.round(navigationEntry.responseEnd || 0) : 0,
      navigationTransferSizeBytes: navigationEntry ? Number(navigationEntry.transferSize || 0) : 0,
      navigationEncodedBodySizeBytes: navigationEntry ? Number(navigationEntry.encodedBodySize || 0) : 0,
      resourceCount: resourceEntries.length,
      totalResourceTransferBytes: resourceEntries.reduce((sum, entry) => {
        return sum + (entry.transferSizeBytes || entry.encodedBodySizeBytes || 0);
      }, 0),
      slowestResources: resourceEntries
        .slice()
        .sort((left, right) => right.durationMs - left.durationMs)
        .slice(0, 8)
    };
  }, {
    selector: actionableSelector,
    actionableAt: firstActionableMs
  });
}

module.exports = {
  buildPageSpeedProfile,
  collectPageSpeedMetrics,
  createPageSpeedSession,
  readEnvNumber,
  warmPageSpeedRoute,
  waitForVisibleActionable
};
