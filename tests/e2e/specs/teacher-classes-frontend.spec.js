const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

test.describe.configure({ timeout: 240000 });

function uniqueSuffix() {
  return `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
}

async function adminRest(page, path, { method = 'GET', body = null } = {}) {
  const result = await page.evaluate(async ({ requestPath, requestMethod, requestBody }) => {
    const nonce = window.wpApiSettings && window.wpApiSettings.nonce;
    if (!nonce) {
      return { error: 'missing-rest-nonce' };
    }

    const response = await fetch(requestPath, {
      method: requestMethod,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: requestBody ? JSON.stringify(requestBody) : undefined
    });

    let data = null;
    try {
      data = await response.json();
    } catch (_) {
      data = null;
    }

    return {
      ok: response.ok,
      status: response.status,
      data
    };
  }, { requestPath: path, requestMethod: method, requestBody: body });

  if (!result || result.error) {
    throw new Error(`REST ${method} ${path} failed: ${result && result.error ? result.error : 'unknown error'}`);
  }
  if (!result.ok) {
    throw new Error(`REST ${method} ${path} failed: HTTP ${result.status} ${JSON.stringify(result.data)}`);
  }

  return result.data;
}

async function createTeacherFixtures(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');

  const suffix = uniqueSuffix();
  const wordsetSlug = `e2e-teacher-classes-${suffix}`;
  const wordset = await adminRest(page, '/wp-json/wp/v2/wordsets', {
    method: 'POST',
    body: {
      name: `E2E Teacher Classes ${suffix}`,
      slug: wordsetSlug
    }
  });

  const username = `e2e_teacher_${suffix.replace(/-/g, '_')}`;
  const password = `TeacherPass!${suffix}`;
  const user = await adminRest(page, '/wp-json/wp/v2/users', {
    method: 'POST',
    body: {
      username,
      email: `${username}@example.test`,
      password,
      roles: ['ll_tools_teacher']
    }
  });

  if (!wordset || !wordset.id || !user || !user.id) {
    throw new Error('Failed to create teacher-class fixtures.');
  }

  return {
    password,
    userId: user.id,
    username,
    wordsetId: wordset.id,
    wordsetSlug
  };
}

async function createAdminAssignmentFixtures(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');

  const suffix = uniqueSuffix();
  const wordsetSlug = `e2e-teacher-assignment-${suffix}`;
  const wordset = await adminRest(page, '/wp-json/wp/v2/wordsets', {
    method: 'POST',
    body: {
      name: `E2E Teacher Assignment ${suffix}`,
      slug: wordsetSlug
    }
  });

  const teacherUsername = `e2e_assign_teacher_${suffix.replace(/-/g, '_')}`;
  const teacher = await adminRest(page, '/wp-json/wp/v2/users', {
    method: 'POST',
    body: {
      username: teacherUsername,
      email: `${teacherUsername}@example.test`,
      password: `TeacherPass!${suffix}`,
      roles: ['ll_tools_teacher']
    }
  });

  const learnerUsername = `e2e_assign_learner_${suffix.replace(/-/g, '_')}`;
  const learnerEmail = `${learnerUsername}@example.test`;
  const learner = await adminRest(page, '/wp-json/wp/v2/users', {
    method: 'POST',
    body: {
      username: learnerUsername,
      email: learnerEmail,
      password: `LearnerPass!${suffix}`,
      roles: ['ll_tools_learner']
    }
  });

  if (!wordset || !wordset.id || !teacher || !teacher.id || !learner || !learner.id) {
    throw new Error('Failed to create admin assignment fixtures.');
  }

  return {
    learnerEmail,
    learnerUserId: learner.id,
    teacherUserId: teacher.id,
    wordsetId: wordset.id,
    wordsetSlug
  };
}

async function deleteTeacherFixtures(page, fixtures) {
  if (!fixtures) {
    return;
  }

  await page.context().clearCookies().catch(() => {});
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');

  const userIds = Array.from(new Set([
    fixtures.userId,
    fixtures.teacherUserId,
    fixtures.learnerUserId
  ].filter(Boolean)));

  for (const userId of userIds) {
    await adminRest(page, `/wp-json/wp/v2/users/${userId}?force=true&reassign=1`, {
      method: 'DELETE'
    }).catch(() => {});
  }

  if (fixtures.wordsetId) {
    await adminRest(page, `/wp-json/wp/v2/wordsets/${fixtures.wordsetId}?force=true`, {
      method: 'DELETE'
    }).catch(() => {});
  }
}

async function loginAsUser(page, username, password, targetPath) {
  await page.context().clearCookies();
  await page.goto(`/wp-login.php?redirect_to=${encodeURIComponent(targetPath)}`, {
    waitUntil: 'domcontentloaded'
  });

  await expect(page.locator('#loginform')).toBeVisible({ timeout: 30000 });
  await page.fill('#user_login', username);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !/wp-login\.php/.test(url.toString()), {
    timeout: 60000
  }).catch(() => {});

  if (!page.url().includes('ll_wordset_view=classes')) {
    await page.goto(targetPath, { waitUntil: 'domcontentloaded' });
  }
}

async function deleteSelectedClass(page, className) {
  const selectedClass = page.locator('.ll-teacher-classes__list-card.is-selected').filter({
    has: page.getByRole('heading', { name: className, exact: true })
  });
  await expect(selectedClass).toBeVisible();
  const deleteForm = selectedClass.locator('form:has(input[name="action"][value="ll_tools_teacher_delete_class"])');
  await expect(deleteForm).toHaveCount(1);
  await Promise.all([
    page.waitForURL((url) => !url.searchParams.has('class_id'), { timeout: 60000 }),
    deleteForm.evaluate((form) => HTMLFormElement.prototype.submit.call(form))
  ]);
}

test('teacher can create a frontend class and stays on the new class', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for teacher class E2E tests.');

  let fixtures = null;
  const className = `E2E Teacher Frontend Class ${uniqueSuffix()}`;

  try {
    fixtures = await createTeacherFixtures(page);
    const classesPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}&ll_wordset_view=classes`;

    await loginAsUser(page, fixtures.username, fixtures.password, classesPath);

    const root = page.locator('[data-ll-teacher-classes]');
    await expect(root).toBeVisible({ timeout: 60000 });
    await expect(root.getByRole('heading', { name: 'New class', exact: true })).toBeVisible();
    await expect(root.getByText('Create a class to start inviting learners.')).toBeVisible();

    const createForm = root.locator('form:has(input[name="action"][value="ll_tools_teacher_create_class"])');
    await expect(createForm).toHaveCount(1);
    await expect(createForm.locator('input[name="ll_tools_teacher_class_wordset_id"]')).toHaveValue(String(fixtures.wordsetId));
    await createForm.locator('input[name="ll_tools_teacher_class_name"]').fill(className);

    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('class_id'), { timeout: 60000 }),
      createForm.getByRole('button', { name: 'Create class' }).click()
    ]);

    await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText(`Created class: ${className}`);
    await expect(root.locator('.ll-teacher-classes__list-card.is-selected').getByRole('heading', { name: className, exact: true })).toBeVisible();
    await expect(root.locator('.ll-teacher-classes__detail').getByRole('heading', { name: className, exact: true })).toBeVisible();
    await expect(root.locator('.ll-teacher-classes__detail')).toContainText('No learners have joined this class yet.');

    await deleteSelectedClass(page, className);
    await expect(root.getByText('Create a class to start inviting learners.')).toBeVisible({ timeout: 60000 });
  } finally {
    await deleteTeacherFixtures(page, fixtures);
  }
});

test('admin can assign an existing learner from frontend classes', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for teacher class E2E tests.');

  let fixtures = null;
  let classId = '';
  const className = `E2E Direct Assignment Class ${uniqueSuffix()}`;

  try {
    fixtures = await createAdminAssignmentFixtures(page);
    const classesPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}&ll_wordset_view=classes`;

    await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');
    await page.goto(classesPath, { waitUntil: 'domcontentloaded' });

    const root = page.locator('[data-ll-teacher-classes]');
    await expect(root).toBeVisible({ timeout: 60000 });

    const createForm = root.locator('form:has(input[name="action"][value="ll_tools_teacher_create_class"])');
    await expect(createForm).toHaveCount(1);
    await expect(createForm.locator('input[name="ll_tools_teacher_class_wordset_id"]')).toHaveValue(String(fixtures.wordsetId));
    await createForm.locator('input[name="ll_tools_teacher_class_name"]').fill(className);

    const teacherSelect = createForm.locator('select[name="ll_tools_teacher_class_teacher_user_id"]');
    if ((await teacherSelect.count()) > 0) {
      await teacherSelect.selectOption(String(fixtures.teacherUserId));
    }

    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('class_id'), { timeout: 60000 }),
      createForm.getByRole('button', { name: 'Create class' }).click()
    ]);

    classId = new URL(page.url()).searchParams.get('class_id') || '';
    expect(classId).not.toBe('');
    await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText(`Created class: ${className}`);

    const detail = root.locator('.ll-teacher-classes__detail');
    await expect(detail.getByRole('heading', { name: className, exact: true })).toBeVisible();
    await expect(root.getByRole('heading', { name: 'Add existing learner', exact: true })).toBeVisible();

    const assignForm = root.locator('form:has(input[name="action"][value="ll_tools_teacher_assign_class_student"])');
    await expect(assignForm).toHaveCount(1);
    await expect(assignForm.locator('input[name="class_id"]')).toHaveValue(classId);
    await assignForm.locator('select[name="ll_tools_teacher_assign_user_id"]').selectOption(String(fixtures.learnerUserId));

    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('ll_tools_class_notice') && url.searchParams.get('class_id') === classId, { timeout: 60000 }),
      assignForm.getByRole('button', { name: 'Add learner' }).click()
    ]);

    await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText('Added');
    await expect(detail).toContainText(fixtures.learnerEmail);
    await expect(root.locator('.ll-teacher-classes__table')).toBeVisible();
    await expect(root.locator('.ll-teacher-classes__list-card.is-selected')).toContainText('1 student');
    const remainingAssignSelect = root.locator('select[name="ll_tools_teacher_assign_user_id"]');
    if ((await remainingAssignSelect.count()) > 0) {
      await expect(remainingAssignSelect).not.toContainText(fixtures.learnerEmail);
    } else {
      await expect(root.getByText('No eligible learner accounts are currently available to assign.')).toBeVisible();
    }

    await deleteSelectedClass(page, className);
    classId = '';
    await expect(root.getByText('Create a class to start inviting learners.')).toBeVisible({ timeout: 60000 });
  } finally {
    if (fixtures && classId) {
      const classesPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}&ll_wordset_view=classes&class_id=${encodeURIComponent(classId)}`;
      await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page').catch(() => {});
      await page.goto(classesPath, { waitUntil: 'domcontentloaded' }).catch(() => {});
      await deleteSelectedClass(page, className).catch(() => {});
    }
    await deleteTeacherFixtures(page, fixtures);
  }
});
