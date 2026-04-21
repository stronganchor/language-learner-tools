const { defineConfig, devices } = require('@playwright/test');

const timeout = parseInt(process.env.LL_LIVE_SMOKE_TIMEOUT_MS || '', 10) || 120000;

module.exports = defineConfig({
  testDir: './live-smoke',
  timeout,
  expect: {
    timeout: 20000
  },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  outputDir: 'test-results/live-smoke',
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report/live-smoke', open: 'never' }]
  ],
  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
    viewport: { width: 1366, height: 900 }
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
});
