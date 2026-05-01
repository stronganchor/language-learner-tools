const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

test.describe.configure({ timeout: 240000 });

function uniqueSuffix() {
  return `${Date.now()}-${Math.floor(Math.random() * 100000)}`;
}

async function adminRest(page, path, { method = 'GET', body = null } = {}) {
  const performRequest = async () => page.evaluate(async ({ requestPath, requestMethod, requestBody }) => {
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

  let result = await performRequest();
  if (result && result.error === 'missing-rest-nonce') {
    await page.context().clearCookies().catch(() => {});
    await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');
    result = await performRequest();
  }

  if (!result || result.error) {
    throw new Error(`REST ${method} ${path} failed: ${result && result.error ? result.error : 'unknown error'}`);
  }
  if (!result.ok) {
    throw new Error(`REST ${method} ${path} failed: HTTP ${result.status} ${JSON.stringify(result.data)}`);
  }

  return result.data;
}

async function installClipboardFallbackProbe(page) {
  await page.evaluate(() => {
    const blockedClipboard = {
      writeText: async (text) => {
        window.__llClipboardWriteTextAttempted = true;
        window.__llClipboardWriteTextValue = String(text || '');
        throw new Error('clipboard-write-blocked');
      }
    };

    try {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: blockedClipboard
      });
    } catch (_) {
      Object.defineProperty(Navigator.prototype, 'clipboard', {
        configurable: true,
        get: () => blockedClipboard
      });
    }

    const originalExecCommand = typeof document.execCommand === 'function'
      ? document.execCommand.bind(document)
      : null;
    document.execCommand = (command) => {
      if (String(command).toLowerCase() === 'copy') {
        const active = document.activeElement;
        window.__llExecCommandCopyText = active && typeof active.value === 'string'
          ? active.value
          : '';
        return true;
      }

      return originalExecCommand ? originalExecCommand(command) : false;
    };
  });

  await expect.poll(() => page.evaluate(() => (
    !!navigator.clipboard &&
    typeof navigator.clipboard.writeText === 'function' &&
    typeof document.execCommand === 'function'
  ))).toBe(true);
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

async function createTeacherClassProgressFixtures(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');

  const suffix = uniqueSuffix();
  const fixtures = {
    categoryId: 0,
    existingLearnerEmail: '',
    existingLearnerPassword: `LearnerPass!${suffix}`,
    existingLearnerUserId: 0,
    existingLearnerUsername: `e2e_progress_existing_${suffix.replace(/-/g, '_')}`,
    registeredLearnerEmail: '',
    registeredLearnerPassword: `LearnerPass!${suffix}`,
    registeredLearnerUsername: `e2e_progress_signup_${suffix.replace(/-/g, '_')}`,
    teacherPassword: `TeacherPass!${suffix}`,
    teacherUserId: 0,
    teacherUsername: `e2e_progress_teacher_${suffix.replace(/-/g, '_')}`,
    wordIds: [],
    wordsetId: 0,
    wordsetSlug: `e2e-teacher-progress-${suffix}`
  };
  fixtures.existingLearnerEmail = `${fixtures.existingLearnerUsername}@example.test`;
  fixtures.registeredLearnerEmail = `${fixtures.registeredLearnerUsername}@example.test`;

  try {
    const wordset = await adminRest(page, '/wp-json/wp/v2/wordsets', {
      method: 'POST',
      body: {
        name: `E2E Teacher Progress ${suffix}`,
        slug: fixtures.wordsetSlug
      }
    });
    fixtures.wordsetId = Number(wordset && wordset.id) || 0;

    const category = await adminRest(page, '/wp-json/wp/v2/word-category', {
      method: 'POST',
      body: {
        name: `E2E Progress Category ${suffix}`,
        slug: `e2e-progress-category-${suffix}`
      }
    });
    fixtures.categoryId = Number(category && category.id) || 0;

    for (let index = 1; index <= 3; index += 1) {
      const word = await adminRest(page, '/wp-json/wp/v2/words', {
        method: 'POST',
        body: {
          title: `E2E Progress Word ${index} ${suffix}`,
          status: 'publish',
          wordsets: [fixtures.wordsetId],
          'word-category': [fixtures.categoryId]
        }
      });
      fixtures.wordIds.push(Number(word && word.id) || 0);
    }
    fixtures.wordIds = fixtures.wordIds.filter(Boolean);

    const teacher = await adminRest(page, '/wp-json/wp/v2/users', {
      method: 'POST',
      body: {
        username: fixtures.teacherUsername,
        email: `${fixtures.teacherUsername}@example.test`,
        password: fixtures.teacherPassword,
        roles: ['ll_tools_teacher']
      }
    });
    fixtures.teacherUserId = Number(teacher && teacher.id) || 0;

    const existingLearner = await adminRest(page, '/wp-json/wp/v2/users', {
      method: 'POST',
      body: {
        username: fixtures.existingLearnerUsername,
        email: fixtures.existingLearnerEmail,
        password: fixtures.existingLearnerPassword,
        roles: ['ll_tools_learner']
      }
    });
    fixtures.existingLearnerUserId = Number(existingLearner && existingLearner.id) || 0;

    if (
      fixtures.wordsetId <= 0 ||
      fixtures.categoryId <= 0 ||
      fixtures.wordIds.length < 3 ||
      fixtures.teacherUserId <= 0 ||
      fixtures.existingLearnerUserId <= 0
    ) {
      throw new Error('Failed to create teacher-class progress fixtures.');
    }
  } catch (error) {
    await deleteTeacherFixtures(page, fixtures).catch(() => {});
    throw error;
  }

  return fixtures;
}

async function deleteTeacherFixtures(page, fixtures) {
  if (!fixtures) {
    return;
  }

  await page.context().clearCookies().catch(() => {});
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');

  if (fixtures.registeredLearnerUsername && !fixtures.registeredLearnerUserId) {
    const users = await adminRest(
      page,
      `/wp-json/wp/v2/users?context=edit&search=${encodeURIComponent(fixtures.registeredLearnerUsername)}`
    ).catch(() => []);
    const registeredLearner = Array.isArray(users)
      ? users.find((user) => user && user.username === fixtures.registeredLearnerUsername)
      : null;
    if (registeredLearner && registeredLearner.id) {
      fixtures.registeredLearnerUserId = registeredLearner.id;
    }
  }

  const userIds = Array.from(new Set([
    fixtures.userId,
    fixtures.teacherUserId,
    fixtures.learnerUserId,
    fixtures.existingLearnerUserId,
    fixtures.registeredLearnerUserId
  ].filter(Boolean)));

  for (const userId of userIds) {
    await adminRest(page, `/wp-json/wp/v2/users/${userId}?force=true&reassign=1`, {
      method: 'DELETE'
    }).catch(() => {});
  }

  for (const wordId of Array.from(new Set((fixtures.wordIds || []).filter(Boolean)))) {
    await adminRest(page, `/wp-json/wp/v2/words/${wordId}?force=true`, {
      method: 'DELETE'
    }).catch(() => {});
  }

  if (fixtures.categoryId) {
    await adminRest(page, `/wp-json/wp/v2/word-category/${fixtures.categoryId}?force=true`, {
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

async function registerLearnerFromSignupLink(page, signupUrl, learner) {
  await page.context().clearCookies();
  await page.goto(signupUrl, { waitUntil: 'domcontentloaded' });

  const registerForm = page.locator('form:has(input[name="action"][value="ll_tools_register_learner"])');
  await expect(registerForm).toBeVisible({ timeout: 60000 });
  await registerForm.locator('input[name="user_email"]').fill(learner.email);
  await registerForm.locator('input[name="user_login"]').fill(learner.username);
  await registerForm.locator('input[name="user_pass"]').fill(learner.password);

  const left = Number(await registerForm.locator('input[name="ll_tools_register_math_left"]').inputValue());
  const right = Number(await registerForm.locator('input[name="ll_tools_register_math_right"]').inputValue());
  await registerForm.locator('input[name="ll_tools_register_math_answer"]').fill(String(left + right));
  await page.waitForTimeout(3200);

  await Promise.all([
    page.waitForURL((url) => url.searchParams.has('ll_tools_class_notice'), { timeout: 60000 }),
    registerForm.locator('button[type="submit"]').click()
  ]);

  await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText('You joined', { timeout: 60000 });
}

async function rememberRegisteredLearnerId(page, fixtures) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');
  const users = await adminRest(
    page,
    `/wp-json/wp/v2/users?context=edit&search=${encodeURIComponent(fixtures.registeredLearnerUsername)}`
  );
  const registeredLearner = Array.isArray(users)
    ? users.find((user) => user && user.username === fixtures.registeredLearnerUsername)
    : null;

  if (!registeredLearner || !registeredLearner.id) {
    throw new Error(`Unable to find registered learner ${fixtures.registeredLearnerUsername}.`);
  }

  fixtures.registeredLearnerUserId = registeredLearner.id;
}

async function recordLearnerProgress(page, fixtures, learner, wordIds) {
  const wordsetPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}`;
  await loginAsUser(page, learner.username, learner.password, wordsetPath);

  const result = await page.evaluate(async ({ categoryId, eventWordIds, wordsetId }) => {
    const config = window.llWordsetPageData || {};
    if (!config.ajaxUrl || !config.nonce) {
      return { ok: false, message: 'missing-progress-config' };
    }

    const createdAt = new Date().toISOString();
    const events = eventWordIds.map((wordId, index) => ({
      event_uuid: `e2e-${Date.now()}-${Math.floor(Math.random() * 100000)}-${index}`,
      event_type: 'word_exposure',
      mode: 'practice',
      word_id: wordId,
      category_id: categoryId,
      wordset_id: wordsetId,
      client_created_at: createdAt
    }));

    const params = new URLSearchParams();
    params.set('action', 'll_user_study_progress_batch');
    params.set('nonce', config.nonce);
    params.set('wordset_id', String(wordsetId));
    params.append('category_ids[]', String(categoryId));
    params.set('events', JSON.stringify(events));

    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: params.toString()
    });
    const data = await response.json().catch(() => null);

    return {
      ok: response.ok && data && data.success === true,
      status: response.status,
      data
    };
  }, {
    categoryId: fixtures.categoryId,
    eventWordIds: wordIds,
    wordsetId: fixtures.wordsetId
  });

  if (!result.ok) {
    throw new Error(`Failed to seed learner progress: HTTP ${result.status || 'unknown'} ${JSON.stringify(result.data || result.message)}`);
  }

  expect(result.data.data.stats.processed).toBe(wordIds.length);
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

    const signupInput = root.locator('.ll-teacher-classes__input--code');
    const signupUrl = await signupInput.inputValue();
    expect(signupUrl).toContain('ll_tools_class_invite');
    await installClipboardFallbackProbe(page);
    const copyButton = root.locator('[data-ll-copy-target]').first();
    await copyButton.click();
    await expect(copyButton).toHaveText('Copied');
    await expect.poll(() => page.evaluate(() => window.__llClipboardWriteTextAttempted === true)).toBe(true);
    await expect.poll(() => page.evaluate(() => window.__llExecCommandCopyText || '')).toBe(signupUrl);

    await deleteSelectedClass(page, className);
    await expect(root.getByText('Create a class to start inviting learners.')).toBeVisible({ timeout: 60000 });
  } finally {
    await deleteTeacherFixtures(page, fixtures);
  }
});

test('signup invite feeds class progress sorting and learner removal', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for teacher class E2E tests.');

  let fixtures = null;
  let classId = '';
  const className = `E2E Progress Signup Class ${uniqueSuffix()}`;

  try {
    fixtures = await createTeacherClassProgressFixtures(page);
    const classesPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}&ll_wordset_view=classes`;
    const selectedClassPath = () => `${classesPath}&class_id=${encodeURIComponent(classId)}`;

    await loginAsUser(page, fixtures.teacherUsername, fixtures.teacherPassword, classesPath);

    const root = page.locator('[data-ll-teacher-classes]');
    await expect(root).toBeVisible({ timeout: 60000 });

    const createForm = root.locator('form:has(input[name="action"][value="ll_tools_teacher_create_class"])');
    await expect(createForm).toHaveCount(1);
    await createForm.locator('input[name="ll_tools_teacher_class_name"]').fill(className);

    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('class_id'), { timeout: 60000 }),
      createForm.getByRole('button', { name: 'Create class' }).click()
    ]);

    classId = new URL(page.url()).searchParams.get('class_id') || '';
    expect(classId).not.toBe('');
    await expect(root.locator('.ll-teacher-classes__detail').getByRole('heading', { name: className, exact: true })).toBeVisible();

    const signupUrl = await root.locator('.ll-teacher-classes__input--code').inputValue();
    expect(signupUrl).toContain('ll_tools_class_invite');
    await registerLearnerFromSignupLink(page, signupUrl, {
      email: fixtures.registeredLearnerEmail,
      password: fixtures.registeredLearnerPassword,
      username: fixtures.registeredLearnerUsername
    });
    await rememberRegisteredLearnerId(page, fixtures);

    await ensureLoggedIntoAdmin(page, '/wp-admin/post-new.php?post_type=page');
    await page.goto(selectedClassPath(), { waitUntil: 'domcontentloaded' });
    await expect(root).toBeVisible({ timeout: 60000 });

    const assignForm = root.locator('form:has(input[name="action"][value="ll_tools_teacher_assign_class_student"])');
    await expect(assignForm).toHaveCount(1);
    await assignForm.locator('select[name="ll_tools_teacher_assign_user_id"]').selectOption(String(fixtures.existingLearnerUserId));

    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('ll_tools_class_notice') && url.searchParams.get('class_id') === classId, { timeout: 60000 }),
      assignForm.getByRole('button', { name: 'Add learner' }).click()
    ]);
    await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText('Added');

    await recordLearnerProgress(page, fixtures, {
      password: fixtures.registeredLearnerPassword,
      username: fixtures.registeredLearnerUsername
    }, fixtures.wordIds);
    await recordLearnerProgress(page, fixtures, {
      password: fixtures.existingLearnerPassword,
      username: fixtures.existingLearnerUsername
    }, fixtures.wordIds.slice(0, 1));

    await loginAsUser(page, fixtures.teacherUsername, fixtures.teacherPassword, selectedClassPath());
    await expect(root).toBeVisible({ timeout: 60000 });
    const detail = root.locator('.ll-teacher-classes__detail');
    await expect(detail).toContainText(fixtures.registeredLearnerEmail);
    await expect(detail).toContainText(fixtures.existingLearnerEmail);
    await expect(root.locator('.ll-teacher-classes__list-card.is-selected')).toContainText('2 students');

    const table = root.locator('[data-ll-teacher-classes-progress-table]');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr')).toHaveCount(2);
    await table.locator('[data-ll-teacher-classes-sort="rounds_30d"]').click();
    await expect(table).toHaveAttribute('data-sort-key', 'rounds_30d');
    await expect(table).toHaveAttribute('data-sort-direction', 'desc');
    await expect(table.locator('tbody tr').first()).toContainText(fixtures.registeredLearnerEmail);
    await expect(table.locator('tbody tr').first().locator('td').nth(2)).toHaveText('3');
    await expect(table.locator('tbody tr').nth(1).locator('td').nth(2)).toHaveText('1');

    const existingLearnerRow = table.locator('tbody tr').filter({
      has: page.getByText(fixtures.existingLearnerEmail, { exact: true })
    });
    const removeForm = existingLearnerRow.locator('form:has(input[name="action"][value="ll_tools_teacher_remove_class_student"])');
    await expect(removeForm).toHaveCount(1);

    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('ll_tools_class_notice') && url.searchParams.get('class_id') === classId, { timeout: 60000 }),
      removeForm.evaluate((form) => HTMLFormElement.prototype.submit.call(form))
    ]);

    await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText('Removed');
    await expect(detail).toContainText(fixtures.registeredLearnerEmail);
    await expect(detail).not.toContainText(fixtures.existingLearnerEmail);
    await expect(root.locator('.ll-teacher-classes__list-card.is-selected')).toContainText('1 student');
    await expect(table.locator('tbody tr')).toHaveCount(1);

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
