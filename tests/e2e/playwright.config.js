const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.LL_E2E_BASE_URL || 'http://127.0.0.1:10036';

module.exports = defineConfig({
  testDir: './specs',
  timeout: 120000,
  expect: {
    timeout: 20000
  },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }]
  ],
  use: {
    baseURL,
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
