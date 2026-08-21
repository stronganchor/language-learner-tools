const { test, expect } = require('@playwright/test');
const path = require('path');
const { runWpCliJson } = require('../helpers/wp-cli');

test.describe.configure({ timeout: 360000 });

const adminPath = '/wp-admin/admin.php?page=ll-tools-google-classroom';
const fixtureScript = path.resolve(
  __dirname,
  '..',
  'fixtures',
  'seed-google-classroom-admin.php'
);

function runFixture(command) {
  return runWpCliJson(['eval-file', fixtureScript, command], { timeoutMs: 180000 });
}

function configurationRow(page, label) {
  return page.locator('.ll-tools-google-classroom table').first().locator('tr').filter({
    has: page.getByRole('rowheader', { name: label, exact: true })
  });
}

async function loginAsFixtureTeacher(page, teacher) {
  await page.context().clearCookies();
  await page.goto('/wp-login.php?reauth=1', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#loginform')).toBeVisible({ timeout: 30000 });
  await page.locator('#user_login').fill(teacher.login);
  await page.locator('#user_pass').fill(teacher.password);
  await Promise.all([
    page.waitForURL((url) => !/\/wp-login\.php(?:$|[?\/])/.test(url.toString()), {
      timeout: 60000
    }),
    page.locator('#wp-submit').click()
  ]);
  await page.goto(adminPath, { waitUntil: 'domcontentloaded' });
}

test('Classroom admin shows safe unconfigured and locally mocked connected states', async ({ page }) => {
  let cleanupRequired = false;
  const browserGoogleRequests = [];
  const providerHosts = new Set([
    'accounts.google.com',
    'oauth2.googleapis.com',
    'openidconnect.googleapis.com',
    'classroom.googleapis.com'
  ]);
  page.on('request', (request) => {
    const hostname = new URL(request.url()).hostname.toLowerCase();
    if (providerHosts.has(hostname)) {
      browserGoogleRequests.push(request.url());
    }
  });

  try {
    try {
      cleanupRequired = true;
      const unconfiguredFixture = runFixture('activate-unconfigured');
      await loginAsFixtureTeacher(page, unconfiguredFixture.teacher);
    } catch (error) {
      if (error && error.isWpCliUnavailable) {
        cleanupRequired = false;
        test.skip(true, `Unable to activate the local Classroom fixture through WP-CLI: ${error.message}`);
        return;
      }
      throw error;
    }

    await expect(page.locator('.ll-tools-google-classroom')).toBeVisible({ timeout: 60000 });
    await expect(page.getByRole('heading', { name: 'Google Classroom', level: 1 })).toBeVisible();
    await expect(configurationRow(page, 'Client ID')).toContainText('Not ready');
    await expect(configurationRow(page, 'Client secret')).toContainText('Not ready');
    await expect(configurationRow(page, 'Credential encryption')).toContainText('Not ready');
    await expect(page.getByText('No Google Classroom account is connected.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Connect Google Classroom', exact: true })).toHaveCount(0);
    await expect(page.locator('input[name*="client" i], input[name*="secret" i]')).toHaveCount(0);
    await expect(page.getByText(
      'OAuth credentials and the encryption key must be supplied by site configuration. They are never stored or entered on this page.',
      { exact: true }
    )).toBeVisible();

    runFixture('activate-configured');
    const connectionFixture = runFixture('seed');
    await page.reload({ waitUntil: 'domcontentloaded' });

    await expect(configurationRow(page, 'Client ID')).toContainText('Ready');
    await expect(configurationRow(page, 'Client secret')).toContainText('Ready');
    await expect(configurationRow(page, 'Credential encryption')).toContainText('Ready');
    await expect(configurationRow(page, 'Database schema')).toContainText('Ready');
    await expect(page.getByText(connectionFixture.accountEmail, { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Load active courses', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Disconnect', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Connect another Google account', exact: true })).toBeVisible();
    await expect(page.getByText(
      'CourseWork and grade writes are not available on this page. They remain disabled until server-verified assignments and attempts are ready for production.',
      { exact: true }
    )).toBeVisible();
    await expect(page.getByRole('button', { name: /grade|coursework/i })).toHaveCount(0);

    const formActions = await page.locator('.ll-tools-google-classroom form').evaluateAll((forms) =>
      forms.map((form) => new URL(form.action, window.location.href).origin)
    );
    expect(new Set(formActions)).toEqual(new Set([new URL(page.url()).origin]));

    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 90000 }),
      page.getByRole('button', { name: 'Load active courses', exact: true }).click()
    ]);
    await expect(page.getByRole('heading', { name: 'Active courses', level: 2 })).toBeVisible({ timeout: 60000 });
    for (const courseName of connectionFixture.courseNames) {
      await expect(page.getByRole('cell', { name: courseName, exact: true })).toBeVisible();
    }
    await expect(page.getByText('E2E Archived Course', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('cell', { name: 'e2e-course-hebrew', exact: true })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'e2e-course-greek', exact: true })).toBeVisible();

    await page.goto(`${adminPath}&ll_tools_gc_notice=authorization_failed`, {
      waitUntil: 'domcontentloaded'
    });
    await expect(page.locator('.notice.notice-error')).toContainText(
      'Google Classroom authorization could not be verified. Please try again.'
    );

    await page.goto(`${adminPath}&ll_tools_gc_notice=raw-provider-secret`, {
      waitUntil: 'domcontentloaded'
    });
    await expect(page.locator('.ll-tools-google-classroom')).not.toContainText('raw-provider-secret');
    expect(browserGoogleRequests).toEqual([]);
  } finally {
    if (cleanupRequired) {
      runFixture('cleanup');
    }
  }
});
