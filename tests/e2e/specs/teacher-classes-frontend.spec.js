const { test, expect } = require('@playwright/test');
const { ensureLoggedIntoAdmin, hasAdminCredentials } = require('../helpers/admin');

test.describe.configure({ timeout: 360000 });

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
    await ensureLoggedIntoAdmin(page, '/wp-admin/');
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

async function throwAfterFixtureRollback(page, fixtures, setupError) {
  try {
    await deleteTeacherFixtures(page, fixtures);
  } catch (cleanupError) {
    throw new AggregateError(
      [setupError, cleanupError],
      'Teacher fixture setup and rollback both failed.',
      { cause: setupError }
    );
  }
  throw setupError;
}

async function createTeacherFixtures(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/');

  const suffix = uniqueSuffix();
  const fixtures = {
    password: `TeacherPass!${suffix}`,
    userId: 0,
    username: `e2e_teacher_${suffix.replace(/-/g, '_')}`,
    wordsetId: 0,
    wordsetSlug: `e2e-teacher-classes-${suffix}`
  };

  try {
    const wordset = await adminRest(page, '/wp-json/wp/v2/wordsets', {
      method: 'POST',
      body: {
        name: `E2E Teacher Classes ${suffix}`,
        slug: fixtures.wordsetSlug
      }
    });
    fixtures.wordsetId = Number(wordset && wordset.id) || 0;

    const user = await adminRest(page, '/wp-json/wp/v2/users', {
      method: 'POST',
      body: {
        username: fixtures.username,
        email: `${fixtures.username}@example.test`,
        password: fixtures.password,
        roles: ['ll_tools_teacher']
      }
    });
    fixtures.userId = Number(user && user.id) || 0;

    if (fixtures.wordsetId <= 0 || fixtures.userId <= 0) {
      throw new Error('Failed to create teacher-class fixtures.');
    }
  } catch (error) {
    await throwAfterFixtureRollback(page, fixtures, error);
  }

  return fixtures;
}

async function createAdminAssignmentFixtures(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/');

  const suffix = uniqueSuffix();
  const teacherUsername = `e2e_assign_teacher_${suffix.replace(/-/g, '_')}`;
  const learnerUsername = `e2e_assign_learner_${suffix.replace(/-/g, '_')}`;
  const learnerEmail = `${learnerUsername}@example.test`;
  const fixtures = {
    learnerEmail,
    learnerUserId: 0,
    learnerUsername,
    teacherUserId: 0,
    teacherUsername,
    wordsetId: 0,
    wordsetSlug: `e2e-teacher-assignment-${suffix}`
  };

  try {
    const wordset = await adminRest(page, '/wp-json/wp/v2/wordsets', {
      method: 'POST',
      body: {
        name: `E2E Teacher Assignment ${suffix}`,
        slug: fixtures.wordsetSlug
      }
    });
    fixtures.wordsetId = Number(wordset && wordset.id) || 0;

    const teacher = await adminRest(page, '/wp-json/wp/v2/users', {
      method: 'POST',
      body: {
        username: teacherUsername,
        email: `${teacherUsername}@example.test`,
        password: `TeacherPass!${suffix}`,
        roles: ['ll_tools_teacher']
      }
    });
    fixtures.teacherUserId = Number(teacher && teacher.id) || 0;

    const learner = await adminRest(page, '/wp-json/wp/v2/users', {
      method: 'POST',
      body: {
        username: learnerUsername,
        email: learnerEmail,
        password: `LearnerPass!${suffix}`,
        roles: ['ll_tools_learner']
      }
    });
    fixtures.learnerUserId = Number(learner && learner.id) || 0;

    if (fixtures.wordsetId <= 0 || fixtures.teacherUserId <= 0 || fixtures.learnerUserId <= 0) {
      throw new Error('Failed to create admin assignment fixtures.');
    }
  } catch (error) {
    await throwAfterFixtureRollback(page, fixtures, error);
  }

  return fixtures;
}

async function createTeacherClassProgressFixtures(page) {
  await ensureLoggedIntoAdmin(page, '/wp-admin/');

  const suffix = uniqueSuffix();
  const categoryName = `E2E Progress Category ${suffix}`;
  const fixtures = {
    categoryId: 0,
    categoryName,
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
    wordTitles: Array.from({ length: 3 }, (_, index) => `E2E Progress Word ${index + 1} ${suffix}`),
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
        name: categoryName,
        slug: `e2e-progress-category-${suffix}`
      }
    });
    fixtures.categoryId = Number(category && category.id) || 0;

    for (let index = 1; index <= 3; index += 1) {
      const word = await adminRest(page, '/wp-json/wp/v2/words', {
        method: 'POST',
        body: {
          title: fixtures.wordTitles[index - 1],
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
    await throwAfterFixtureRollback(page, fixtures, error);
  }

  return fixtures;
}

async function deleteTeacherFixtures(page, fixtures) {
  if (!fixtures) {
    return;
  }

  const failures = [];
  const attempt = async (label, operation) => {
    try {
      return await operation();
    } catch (error) {
      failures.push(new Error(label, { cause: error }));
      return null;
    }
  };

  await page.context().clearCookies();
  await ensureLoggedIntoAdmin(page, '/wp-admin/');

  if (!fixtures.wordsetId && fixtures.wordsetSlug) {
    const wordsets = await attempt('wordset lookup', () => adminRest(
      page,
      `/wp-json/wp/v2/wordsets?context=edit&hide_empty=false&slug=${encodeURIComponent(fixtures.wordsetSlug)}`
    ));
    const wordset = Array.isArray(wordsets)
      ? wordsets.find((item) => item && item.id && item.slug === fixtures.wordsetSlug)
      : null;
    if (wordset) {
      fixtures.wordsetId = Number(wordset.id) || 0;
    }
  }

  const userIds = new Set([
    fixtures.userId,
    fixtures.teacherUserId,
    fixtures.learnerUserId,
    fixtures.existingLearnerUserId,
    fixtures.registeredLearnerUserId
  ].filter(Boolean).map(Number));
  const usernames = Array.from(new Set([
    fixtures.username,
    fixtures.teacherUsername,
    fixtures.learnerUsername,
    fixtures.existingLearnerUsername,
    fixtures.registeredLearnerUsername
  ].filter(Boolean)));
  for (const username of usernames) {
    const users = await attempt(`user lookup ${username}`, () => adminRest(
      page,
      `/wp-json/wp/v2/users?context=edit&search=${encodeURIComponent(username)}`
    ));
    const user = Array.isArray(users)
      ? users.find((item) => item && item.id && item.username === username)
      : null;
    if (user) {
      userIds.add(Number(user.id));
    }
  }

  for (const userId of Array.from(userIds).filter(Boolean)) {
    await attempt(`user ${userId}`, () => adminRest(page, `/wp-json/wp/v2/users/${userId}?force=true&reassign=1`, {
      method: 'DELETE'
    }));
  }

  const wordIds = new Set((fixtures.wordIds || []).filter(Boolean).map(Number));
  for (const wordTitle of Array.from(new Set((fixtures.wordTitles || []).filter(Boolean)))) {
    const words = await attempt(`word lookup ${wordTitle}`, () => adminRest(
      page,
      `/wp-json/wp/v2/words?context=edit&per_page=100&search=${encodeURIComponent(wordTitle)}&status[]=draft&status[]=publish&status[]=pending&status[]=private&status[]=future`
    ));
    for (const word of Array.isArray(words) ? words : []) {
      const renderedTitle = word && word.title && typeof word.title.rendered === 'string'
        ? word.title.rendered
        : '';
      if (word && word.id && renderedTitle === wordTitle) {
        wordIds.add(Number(word.id));
      }
    }
  }

  for (const wordId of Array.from(wordIds).filter(Boolean)) {
    await attempt(`word ${wordId}`, () => adminRest(page, `/wp-json/wp/v2/words/${wordId}?force=true`, {
      method: 'DELETE'
    }));
  }

  const categoryIds = new Set([fixtures.categoryId].filter(Boolean));
  if (fixtures.categoryName) {
    const matchingCategories = await attempt('category family lookup', () => adminRest(
      page,
      `/wp-json/wp/v2/word-category?context=edit&hide_empty=false&per_page=100&search=${encodeURIComponent(fixtures.categoryName)}`
    ));
    for (const category of Array.isArray(matchingCategories) ? matchingCategories : []) {
      if (category && category.id && category.name === fixtures.categoryName) {
        categoryIds.add(category.id);
      }
    }
  }

  for (const categoryId of categoryIds) {
    await attempt(`category ${categoryId}`, () => adminRest(page, `/wp-json/wp/v2/word-category/${categoryId}?force=true`, {
      method: 'DELETE'
    }));
  }

  if (fixtures.wordsetId) {
    await attempt(`wordset ${fixtures.wordsetId}`, () => adminRest(page, `/wp-json/wp/v2/wordsets/${fixtures.wordsetId}?force=true`, {
      method: 'DELETE'
    }));
  }

  if (failures.length > 0) {
    throw new AggregateError(failures, 'Teacher fixture cleanup failed.');
  }
}

function loginTargetMatches(currentUrl, targetPath) {
  try {
    const current = new URL(currentUrl);
    const target = new URL(targetPath, current.origin);
    if (current.pathname === target.pathname && current.search === target.search) {
      return true;
    }

    const wordsetSlug = target.searchParams.get('ll_wordset_page');
    if (!wordsetSlug) {
      return false;
    }

    const canonicalPath = `/${wordsetSlug}/${target.searchParams.get('ll_wordset_view') === 'classes' ? 'classes/' : ''}`;
    if (decodeURIComponent(current.pathname) !== canonicalPath) {
      return false;
    }

    return current.searchParams.get('class_id') === target.searchParams.get('class_id');
  } catch (_) {
    return false;
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
  await Promise.all([
    page.waitForURL((url) => loginTargetMatches(url.toString(), targetPath), {
      timeout: 60000
    }),
    page.click('#wp-submit')
  ]);
  await page.waitForLoadState('domcontentloaded', { timeout: 60000 });
  await expect.poll(() => loginTargetMatches(page.url(), targetPath), { timeout: 60000 }).toBe(true);
}

async function deleteSelectedClass(page, className) {
  const selectedClass = page.locator('.ll-teacher-classes__list-card.is-selected').filter({
    has: page.getByRole('heading', { name: className, exact: true })
  });
  await expect(selectedClass).toBeVisible();
  const deleteForm = selectedClass.locator('form:has(input[name="action"][value="ll_tools_teacher_delete_class"])');
  await expect(deleteForm).toHaveCount(1);
  await Promise.all([
    page.waitForURL((url) => !url.searchParams.has('class_id') && url.searchParams.has('ll_tools_class_notice'), { timeout: 60000 }),
    deleteForm.evaluate((form) => HTMLFormElement.prototype.submit.call(form))
  ]);
  await expect(page.locator('.ll-wordset-progress-reset-notice--success')).toContainText(`Deleted class: ${className}`);
}

async function deleteClassFromAdmin(page, classId, className) {
  let normalizedClassId = String(classId || '');
  const normalizedClassName = String(className || '');
  if (!normalizedClassId && !normalizedClassName) {
    return;
  }
  if (normalizedClassId && !/^\d+$/.test(normalizedClassId)) {
    throw new Error('Teacher fixture cleanup received an invalid class ID.');
  }

  await page.context().clearCookies();
  const cleanupParams = new URLSearchParams({
    page: 'll-tools-teacher-classes'
  });
  if (normalizedClassId) {
    cleanupParams.set('class_id', normalizedClassId);
  }
  if (normalizedClassName) {
    cleanupParams.set('ll_tools_class_search', normalizedClassName);
  }
  const cleanupPath = `/wp-admin/admin.php?${cleanupParams.toString()}`;
  await ensureLoggedIntoAdmin(page, cleanupPath);

  let deleteForm = normalizedClassId
    ? page.locator(`form:has(input[name="action"][value="ll_tools_teacher_delete_class"]):has(input[name="class_id"][value="${normalizedClassId}"])`)
    : page.locator('tr').filter({
      has: page.getByText(normalizedClassName, { exact: true })
    }).locator('form:has(input[name="action"][value="ll_tools_teacher_delete_class"])');

  if ((await deleteForm.count()) === 0) {
    if (normalizedClassId) {
      const selectedClassIdMarkers = page.locator(`input[name="class_id"][value="${normalizedClassId}"]`);
      if ((await selectedClassIdMarkers.count()) > 0) {
        throw new Error('Teacher fixture class still exists outside the bounded class search results.');
      }
    }
    await expect(page.getByText('No classes match your search.', { exact: true })).toBeVisible();
    return;
  }
  await expect(deleteForm).toHaveCount(1);
  if (!normalizedClassId) {
    normalizedClassId = await deleteForm.locator('input[name="class_id"]').inputValue();
    if (!/^\d+$/.test(normalizedClassId)) {
      throw new Error('Teacher fixture cleanup could not recover a valid class ID.');
    }
    deleteForm = page.locator(`form:has(input[name="action"][value="ll_tools_teacher_delete_class"]):has(input[name="class_id"][value="${normalizedClassId}"])`);
    await expect(deleteForm).toHaveCount(1);
  }

  await Promise.all([
    page.waitForURL((url) => (
      !url.searchParams.has('class_id')
      && url.searchParams.get('ll_tools_teacher_notice_type') === 'success'
    ), { timeout: 60000 }),
    deleteForm.evaluate((form) => HTMLFormElement.prototype.submit.call(form))
  ]);
  await expect(page.locator('.notice-success')).toContainText(`Deleted class: ${normalizedClassName}`);
}

async function cleanupTeacherTest(page, classId, className, fixtures, bodyError) {
  try {
    await deleteClassFromAdmin(page, classId, className);
    await deleteTeacherFixtures(page, fixtures);
  } catch (cleanupError) {
    if (bodyError) {
      throw new AggregateError(
        [bodyError, cleanupError],
        'Teacher class test and cleanup both failed.',
        { cause: bodyError }
      );
    }
    throw cleanupError;
  }
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
  await page.context().clearCookies().catch(() => {});
  await ensureLoggedIntoAdmin(page, '/wp-admin/');
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
  let classId = '';
  let bodyError = null;
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
    classId = new URL(page.url()).searchParams.get('class_id') || '';
    expect(classId).not.toBe('');

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
    classId = '';
  } catch (error) {
    bodyError = error;
    throw error;
  } finally {
    await cleanupTeacherTest(page, classId, className, fixtures, bodyError);
  }
});

test('legacy Classes admin preserves bounded search state across deletion', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for teacher class E2E tests.');

  let fixtures = null;
  let classId = '';
  let bodyError = null;
  const className = `E2E Legacy Admin Class ${uniqueSuffix()}`;

  try {
    fixtures = await createTeacherFixtures(page);
    const classesPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}&ll_wordset_view=classes`;
    await loginAsUser(page, fixtures.username, fixtures.password, classesPath);

    const frontendRoot = page.locator('[data-ll-teacher-classes]');
    const createForm = frontendRoot.locator('form:has(input[name="action"][value="ll_tools_teacher_create_class"])');
    await createForm.locator('input[name="ll_tools_teacher_class_name"]').fill(className);
    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('class_id'), { timeout: 60000 }),
      createForm.getByRole('button', { name: 'Create class' }).click()
    ]);
    classId = new URL(page.url()).searchParams.get('class_id') || '';
    expect(classId).not.toBe('');

    await page.context().clearCookies();
    const adminPath = `/wp-admin/admin.php?page=ll-tools-teacher-classes&class_id=${encodeURIComponent(classId)}`;
    await ensureLoggedIntoAdmin(page, adminPath);

    const classSearch = page.locator('form:has(input[name="ll_tools_class_search"])');
    await expect(classSearch).toHaveCount(1);
    await expect(page.locator('form:has(input[name="ll_tools_account_search"])')).toHaveCount(1);
    await classSearch.locator('input[name="ll_tools_class_search"]').fill(className);
    await Promise.all([
      page.waitForURL((url) => url.searchParams.get('ll_tools_class_search') === className, { timeout: 60000 }),
      classSearch.getByRole('button', { name: 'Search' }).click()
    ]);
    await expect(page.locator('table.widefat').filter({ hasText: className })).toBeVisible();

    const accountSearch = page.locator('form:has(input[name="ll_tools_account_search"])');
    await accountSearch.locator('input[name="ll_tools_account_search"]').fill(fixtures.username);
    await Promise.all([
      page.waitForURL((url) => (
        url.searchParams.get('ll_tools_class_search') === className
        && url.searchParams.get('ll_tools_account_search') === fixtures.username
      ), { timeout: 60000 }),
      accountSearch.getByRole('button', { name: 'Search' }).click()
    ]);

    const assignTeacherForm = page.locator('form:has(input[name="action"][value="ll_tools_teacher_assign_class_teacher"])');
    await expect(assignTeacherForm.locator(`option[value="${fixtures.userId}"]`)).toHaveCount(1);
    const deleteForm = page.locator(`form:has(input[name="action"][value="ll_tools_teacher_delete_class"]):has(input[name="class_id"][value="${classId}"])`);
    const redirectUrl = new URL(await deleteForm.locator('input[name="ll_tools_teacher_redirect_to"]').inputValue());
    expect(redirectUrl.searchParams.get('ll_tools_class_search')).toBe(className);
    expect(redirectUrl.searchParams.get('ll_tools_account_search')).toBe(fixtures.username);

    await Promise.all([
      page.waitForURL((url) => (
        !url.searchParams.has('class_id')
        && url.searchParams.get('ll_tools_teacher_notice_type') === 'success'
      ), { timeout: 60000 }),
      deleteForm.evaluate((form) => HTMLFormElement.prototype.submit.call(form))
    ]);
    await expect(page.locator('.notice-success')).toContainText(`Deleted class: ${className}`);
    await expect(page.locator('table.widefat').filter({ hasText: className })).toHaveCount(0);
    await expect(page.getByText('No classes match your search.', { exact: true })).toBeVisible();
    classId = '';
  } catch (error) {
    bodyError = error;
    throw error;
  } finally {
    await cleanupTeacherTest(page, classId, className, fixtures, bodyError);
  }
});

test('signup invite feeds class progress sorting and learner removal', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for teacher class E2E tests.');

  let fixtures = null;
  let classId = '';
  let bodyError = null;
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

    await ensureLoggedIntoAdmin(page, '/wp-admin/');
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
    await expect(root.getByText('Create a class to start inviting learners.')).toBeVisible({ timeout: 60000 });
    classId = '';
  } catch (error) {
    bodyError = error;
    throw error;
  } finally {
    await cleanupTeacherTest(page, classId, className, fixtures, bodyError);
  }
});

test('admin can assign an existing learner from frontend classes', async ({ page }) => {
  test.skip(!hasAdminCredentials(), 'LL_E2E_ADMIN_USER and LL_E2E_ADMIN_PASS are required for teacher class E2E tests.');

  let fixtures = null;
  let classId = '';
  let bodyError = null;
  const className = `E2E Direct Assignment Class ${uniqueSuffix()}`;

  try {
    fixtures = await createAdminAssignmentFixtures(page);
    const classesPath = `/?ll_wordset_page=${encodeURIComponent(fixtures.wordsetSlug)}&ll_wordset_view=classes`;

    await ensureLoggedIntoAdmin(page, '/wp-admin/');
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
    await expect(root.getByText('Create a class to start inviting learners.')).toBeVisible({ timeout: 60000 });
    classId = '';
  } catch (error) {
    bodyError = error;
    throw error;
  } finally {
    await cleanupTeacherTest(page, classId, className, fixtures, bodyError);
  }
});
