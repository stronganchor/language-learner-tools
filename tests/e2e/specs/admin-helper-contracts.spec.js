const { test, expect } = require('@playwright/test');
const { gotoAdminPath } = require('../helpers/admin');

function fakeAdminPage(statuses) {
  const queue = statuses.slice();
  const calls = {
    goto: 0,
    waits: [],
    loadState: 0,
    readyCheck: 0
  };

  return {
    calls,
    async goto() {
      calls.goto += 1;
      const status = queue.length ? queue.shift() : 200;
      return { status: () => status };
    },
    async waitForTimeout(timeoutMs) {
      calls.waits.push(timeoutMs);
    },
    async waitForLoadState() {
      calls.loadState += 1;
    },
    async waitForFunction() {
      calls.readyCheck += 1;
    }
  };
}

test('admin helper retries a transient Local gateway response before inspecting the DOM', async () => {
  const page = fakeAdminPage([502, 200]);

  await gotoAdminPath(page, '/wp-admin/');

  expect(page.calls).toEqual({
    goto: 2,
    waits: [750],
    loadState: 1,
    readyCheck: 1
  });
});

test('admin helper fails clearly after a bounded run of gateway responses', async () => {
  const page = fakeAdminPage([502, 503, 504]);

  await expect(gotoAdminPath(page, '/wp-admin/')).rejects.toThrow(
    'Admin navigation failed with retryable HTTP 504 after 3 attempts.'
  );
  expect(page.calls).toEqual({
    goto: 3,
    waits: [750, 1500],
    loadState: 0,
    readyCheck: 0
  });
});

test('admin helper preserves a navigation exception without retrying it', async () => {
  const navigationError = new Error('navigation cancelled');
  let gotoCalls = 0;
  const page = {
    async goto() {
      gotoCalls += 1;
      throw navigationError;
    }
  };

  await expect(gotoAdminPath(page, '/wp-admin/')).rejects.toBe(navigationError);
  expect(gotoCalls).toBe(1);
});
