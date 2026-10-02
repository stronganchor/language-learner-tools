const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');
const fixtureFile = path.resolve(__dirname, '..', 'fixtures', 'lti-accounts.php');

test('Moodle first launch links an existing learner, repeats automatically, and rejects a different signed-in account', async ({ page, context, baseURL }) => {
  const suffix = `lti-${Date.now()}`;
  const binding = 'a'.repeat(64);
  const fixtureCall = (...args) => runWpCliJson(['eval-file', fixtureFile, ...args]);
  try {
    const fixture = fixtureCall('seed', suffix);
    await context.addCookies([{ name: 'll_tools_lti_account_binding', value: binding, url: baseURL, httpOnly: true, sameSite: 'Lax' }]);
    const first = fixtureCall('ticket', suffix, binding, 'account');
    await page.goto(first.url);
    await expect(page.getByRole('heading', { name: 'Connect your learning account' })).toBeVisible();
    expect(fixtureCall('state', suffix).linked).toBe(false);
    const loginForm = page.locator('[data-ll-auth-section="login"] form');
    await loginForm.locator('input[name="log"]').fill(fixture.username);
    await loginForm.locator('input[name="pwd"]').fill(fixture.password);
    await loginForm.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Connect account and open activity' })).toBeVisible();
    expect(fixtureCall('state', suffix).linked).toBe(false);
    await page.getByRole('button', { name: 'Connect account and open activity' }).click();
    await expect(page).toHaveURL(/\/embed\//);
    expect(fixtureCall('state', suffix)).toMatchObject({ linked: true, learner_id: fixture.learnerId, member: true });
    // Launch tickets are generated from an already verified local fixture; OIDC verification has separate protocol tests.
    await context.clearCookies();
    await context.addCookies([{ name: '__Host-ll-tools-lti', value: binding, url: baseURL, secure: true, httpOnly: true, sameSite: 'None' }]);
    const repeat = fixtureCall('ticket', suffix, binding, 'launch');
    await page.goto(repeat.url);
    await expect(page).toHaveURL(/\/embed\//);
    await page.goto('/?ll_lti_connections=1');
    await expect(page.getByRole('button', { name: 'Disconnect Moodle' })).toBeVisible();
    await context.clearCookies();
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(fixture.otherUsername);
    await page.locator('#user_pass').fill(fixture.password);
    await page.locator('#wp-submit').click();
    await context.addCookies([{ name: '__Host-ll-tools-lti', value: binding, url: baseURL, secure: true, httpOnly: true, sameSite: 'None' }]);
    const mismatch = fixtureCall('ticket', suffix, binding, 'launch');
    await page.goto(mismatch.url);
    await expect(page.getByRole('alert')).toContainText('different learning account');
    expect(fixtureCall('state', suffix).learner_id).toBe(fixture.learnerId);
  } finally {
    fixtureCall('cleanup', suffix);
  }
});
