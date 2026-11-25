const fs = require('fs');
const { chromium } = require('/node_modules/playwright');
const { expect } = require('/node_modules/@playwright/test');
const moodle = JSON.parse(fs.readFileSync('/evidence/moodle-state.json'));
const wp = JSON.parse(fs.readFileSync('/evidence/wordpress-state.json'));
const secrets = JSON.parse(fs.readFileSync('/private/credentials.json'));
const M = 'https://moodle.lti-interop.example.org';
const W = 'https://wordboat.lti-interop.example.net';
(async () => {
 const browser = await chromium.launch({headless: true, executablePath: '/usr/bin/chromium-browser'});
 const context = await browser.newContext({ignoreHTTPSErrors: false, viewport: {width: 1360, height: 900}});
 let page = await context.newPage();
 let activePage = page;
 page.setDefaultTimeout(45000);
 page.setDefaultNavigationTimeout(60000);
 const hops = [];
 const checks = {};
 const tracked = new WeakSet();
 const track = target => {
  if (tracked.has(target)) return;
  tracked.add(target);
  target.on('pageerror', error => {
   const message = String(error.message).replace(/https?:\/\/[^\s'"<>]+/g, value => { try { const url = new URL(value); return url.origin + url.pathname; } catch { return '[URL]'; } });
   checks.page_errors = [...(checks.page_errors || []),message].slice(-10);
  });
  target.on('response', response => {
  const url = new URL(response.url());
  if ([new URL(M).hostname, new URL(W).hostname].includes(url.hostname) && (/\/mod\/lti\//.test(url.pathname) || /\/lti\//.test(url.pathname))) {
   hops.push({host: url.hostname, path: url.pathname, method: response.request().method(), status: response.status()});
  }
  });
 };
 context.on('page', track);
 track(page);
 const loginMoodle = async (target, role) => {
  await target.goto(M + '/login/index.php');
  await target.waitForLoadState('networkidle');
  await target.locator('#username').fill('interop_' + role);
  await target.locator('#password').fill(secrets['moodle_' + role]);
  await Promise.all([target.waitForNavigation({waitUntil: 'domcontentloaded'}), target.locator('#loginbtn').click()]);
  await expect(target).not.toHaveURL(/\/login\/index.php/, {timeout: 15000});
 };
 try {
  if (process.env.INTEROP_PHASE === 'gradebook') {
   await loginMoodle(page, 'student');
   await page.goto(M + '/grade/report/user/index.php?id=' + moodle.course_id);
   await expect(page.locator('body')).toContainText('Wordboat guided_assignment');
   await expect(page.locator('body')).toContainText('100.00');
   await page.screenshot({path:'/evidence/moodle-gradebook-visible.png',fullPage:true});
   fs.writeFileSync('/evidence/gradebook-browser-check.json', JSON.stringify({native_gradebook_visible:true},null,2));
   console.log('Native Moodle learner gradebook rendered the guided assignment score.');
   return;
  }
  const guided = process.env.INTEROP_MANUAL !== '1';
  const practiceOnly = process.env.INTEROP_PRACTICE_ONLY === '1';
  if (guided) {
   const teacherContext = await browser.newContext({ignoreHTTPSErrors: false, viewport: {width:1360,height:900}});
   teacherContext.on('page', track);
   const teacher = await teacherContext.newPage();
   activePage = teacher;
   teacher.setDefaultTimeout(60000);
   track(teacher);
   await teacher.goto(W + '/wp-login.php');
   await teacher.waitForLoadState('networkidle');
   await teacher.locator('#user_login').fill('interop_teacher');
   await teacher.locator('#user_pass').fill(secrets.wordpress_teacher);
   await Promise.all([teacher.waitForNavigation({waitUntil:'domcontentloaded'}), teacher.locator('#wp-submit').click()]);
   await loginMoodle(teacher, 'teacher');
   for (const kind of practiceOnly ? ['practice'] : ['practice','assignment']) {
    await teacher.goto(M + '/mod/lti/launch.php?id=' + moodle.activities['guided_' + kind].cm_id);
    await expect(teacher.getByRole('heading', {name:'Moodle activity setup'})).toBeVisible({timeout:90000});
    if (await teacher.getByText('Configured activity', {exact:false}).isVisible()) {
     checks['guided_teacher_' + kind] = true;
     continue;
    }
    await teacher.getByRole('link', {name:'Open Moodle / LTI settings',exact:true}).click();
    await expect(teacher.locator('#ll-lti-category')).toBeVisible({timeout:90000});
    await expect(teacher.locator('#ll-lti-context')).toHaveCount(0);
    await expect(teacher.locator('#ll-lti-resource-link')).toHaveCount(0);
    await teacher.locator('#ll-lti-category').selectOption(String(wp.category_id));
    await teacher.locator('#ll-lti-kind').selectOption(kind);
    await teacher.locator('#ll-lti-name').fill('Guided Moodle ' + kind);
    await teacher.getByRole('button', {name:'Create Moodle activity',exact:true}).click();
    await expect(teacher.locator('.notice-success')).toContainText('Saved.');
    checks['guided_teacher_' + kind] = true;
   }
   await teacher.screenshot({path:'/evidence/guided-teacher-configured.png'});
   await teacherContext.close();
   activePage = page;
  }
  await loginMoodle(page, 'student');
  const openActivity = async kind => {
   const source = await context.newPage();
   activePage = source;
   const popup = source.waitForEvent('popup', {timeout:10000}).catch(() => null);
   await source.goto(M + '/mod/lti/view.php?id=' + moodle.activities[(guided ? 'guided_' : '') + kind].cm_id);
   page = await popup;
   if (!page) {
    [page] = await Promise.all([source.waitForEvent('popup', {timeout:30000}), source.locator('a[target="_blank"][href*="/mod/lti/launch.php"]').click()]);
   }
   page.setDefaultTimeout(60000);
   page.setDefaultNavigationTimeout(90000);
   activePage = page;
   track(page);
   checks.moodle_ui_popup_count = (checks.moodle_ui_popup_count || 0) + 1;
  };
  await openActivity('practice');
  await expect.poll(async () => /\/embed\//.test(page.url()) || await page.getByRole('heading', {name: 'Connect your learning account'}).isVisible(), {timeout:90000}).toBeTruthy();
  checks.real_moodle_signed_launch = true;
  if (!/\/embed\//.test(page.url())) {
   const login = page.locator('[data-ll-auth-section="login"] form');
   await login.locator('input[name="log"]').fill('interop_student');
   await login.locator('input[name="pwd"]').fill(secrets.wordpress_student);
   await login.getByRole('button', {name: 'Sign in', exact: true}).click();
   await expect(page.getByRole('button', {name: 'Connect account and open activity'})).toBeVisible();
   await page.getByRole('button', {name: 'Connect account and open activity'}).click();
   checks.first_student_account_link = true;
  } else { checks.permanent_student_account_reused = true; }
  await expect(page).toHaveURL(/\/embed\//, {timeout: 90000});
  checks.cross_site_top_level_launch = new URL(M).hostname.endsWith('.example.org') && new URL(W).hostname.endsWith('.example.net');
  if (checks.first_student_account_link) { fs.writeFileSync('/evidence/first-link-browser-check.json', JSON.stringify({checks,hops},null,2)); }
  await page.waitForLoadState('networkidle');
  if (process.env.INTEROP_ANSWER_PRACTICE === '1') {
   await expect(page.locator('.ll-answer-option-text-card').first()).toBeVisible({timeout:30000});
   const prompt = await page.locator('body').innerText();
   const number = prompt.match(/Hebrew prompt (\d+)/)[1];
   await page.locator('.ll-answer-option-text-card').filter({hasText:`Meaning ${number}`}).first().click();
   await expect.poll(async () => page.evaluate(async () => {
    await window.LLFlashcards.ProgressTracker.flush();
    return window.LLFlashcards.ProgressTracker.getQueueSize();
   }), {timeout:30000}).toBe(0);
   checks.practice_answered = true;
   checks.practice_queue_flushed = true;
  }
  fs.writeFileSync('/evidence/practice-diagnostics.json', JSON.stringify(await page.evaluate(() => ({container:!!document.querySelector('#ll-tools-flashcard-container'),cards:document.querySelectorAll('.flashcard-container').length,popups:['ll-tools-flashcard-popup','ll-tools-flashcard-quiz-popup','ll-tools-flashcard'].map(id=>({id,display:document.getElementById(id)?getComputedStyle(document.getElementById(id)).display:'absent'})),scripts:[...document.scripts].filter(script=>script.src).map(script=>new URL(script.src).pathname),globals:Object.keys(window).filter(key=>/llTools|LLTools|Flashcard/.test(key)).slice(0,50)})),null,2));
  await page.screenshot({path: '/evidence/practice-launched.png'});
  fs.writeFileSync('/evidence/practice-visible.html', await page.locator('body').innerText());
  await openActivity('practice');
  await expect(page).toHaveURL(/\/embed\//, {timeout: 90000});
  checks.repeat_student_launch = true;
  if (practiceOnly) {
   fs.writeFileSync('/evidence/practice-browser-check.json', JSON.stringify({checks,hops,browser_version:browser.version()},null,2));
   console.log(JSON.stringify(checks));
   return;
  }
  await openActivity('assignment');
  await expect(page.getByRole('heading', {name: guided ? 'Guided Moodle assignment' : 'Real Moodle vocabulary assignment'})).toBeVisible({timeout: 90000});
  checks.graded_activity_launch = true;
  if (process.env.INTEROP_PHASE === 'smoke') {
   await expect(page.locator('.ll-assignment-player__result')).toContainText('Selected grade: 100 / 100');
   checks.existing_selected_grade_visible = true;
   fs.writeFileSync('/evidence/final-smoke-check.json', JSON.stringify({checks,hops,browser_version:browser.version()},null,2));
   console.log(JSON.stringify(checks));
   return;
  }
  await page.getByRole('button', {name: 'Start assignment', exact: true}).click();
  for (let n = 1; n <= 5; n++) {
   await expect(page.locator('.ll-assignment-player__content')).toContainText(`Question ${n} of 5`);
   const prompt = await page.locator('.ll-assignment-player__prompt').innerText();
   const number = prompt.match(/Hebrew prompt (\d+)/)[1];
   await page.locator('.ll-assignment-player__choice').filter({hasText: `Meaning ${number}`}).locator('input').check();
   await page.getByRole('button', {name: 'Save answer', exact: true}).click();
  }
  await expect(page.getByRole('button', {name: 'Finish assignment', exact: true})).toBeVisible();
  await page.getByRole('button', {name: 'Finish assignment', exact: true}).click();
  await expect(page.locator('.ll-assignment-player__result')).toContainText('Selected grade: 100 / 100');
  checks.graded_assignment_completed = true;
  await page.screenshot({path: '/evidence/assignment-completed.png'});
  fs.writeFileSync('/evidence/browser-checks.json', JSON.stringify({checks, hops, browser_version:browser.version()}, null, 2));
  console.log(JSON.stringify(checks));
 } catch (error) {
  // Never persist launch tokens, cookies, private account credentials or full URLs.
  const current = new URL(activePage.url());
  await activePage.screenshot({path: '/evidence/browser-failure.png'}).catch(() => {});
  const failureFile = process.env.INTEROP_PHASE === 'gradebook' ? 'gradebook-browser-check.json'
   : process.env.INTEROP_PHASE === 'smoke' ? 'final-smoke-check.json'
   : process.env.INTEROP_PRACTICE_ONLY === '1' ? 'practice-browser-check.json' : 'browser-checks.json';
  fs.writeFileSync('/evidence/' + failureFile, JSON.stringify({checks, hops, failure: {name: error.name, location: current.origin + current.pathname}}, null, 2));
  console.error(error.name + ': interop failed at ' + current.origin + current.pathname);
  console.error((await activePage.locator('body').innerText()).slice(0, 3500));
  process.exitCode = 1;
 } finally { await browser.close(); }
})();
