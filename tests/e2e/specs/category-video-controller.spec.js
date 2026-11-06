const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const plugin = path.resolve(__dirname, '../../..');
const origin = 'https://category-video.test';

// Render the real page and localization config with small WordPress stubs.
// The registered endpoint has a separate WordPress integration test.
function pageFixture() {
  const render = [
    'define("WPINC", "wp-includes"); define("ABSPATH", __DIR__);',
    'define("LL_TOOLS_BASE_PATH", $argv[1] . "/");',
    'function add_action(...$args) {} function current_user_can($cap) { return true; }',
    'function __($text, $domain) { return $text; }',
    'function esc_html_e($text, $domain) { echo htmlspecialchars($text, ENT_QUOTES, "UTF-8"); }',
    'function esc_attr_e($text, $domain) { esc_html_e($text, $domain); }',
    'function ll_enqueue_asset_by_timestamp(...$args) {}',
    'function admin_url($path) { return "/ajax"; } function wp_create_nonce($action) { return "fixture-nonce"; }',
    'function wp_localize_script($handle, $name, $config) { $GLOBALS["config"] = $config; }',
    'require LL_TOOLS_BASE_PATH . "includes/admin/category-video-export.php";',
    'ob_start(); ll_tools_render_category_video_page(); $html = ob_get_clean();',
    'll_tools_enqueue_category_video_assets("tools_page_ll-category-video");',
    'echo json_encode(["html" => $html, "config" => $GLOBALS["config"]], JSON_THROW_ON_ERROR);'
  ].join(' ');
  try {
    const php = process.env.LL_E2E_PHP_BIN || 'php';
    return JSON.parse(execFileSync(php, ['-r', render, plugin], { encoding: 'utf8' }));
  } catch (error) {
    test.skip(error.code === 'ENOENT', 'PHP CLI is required to render the standalone Category Video controller fixture.');
    throw error;
  }
}

async function mountController(page, routeSource) {
  const fixture = pageFixture();
  fixture.config.strings.requestError = 'Kategori yüklenemedi. Tekrar dene.';
  await page.route(origin + '/', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixture.html }));
  await page.route(origin + '/ajax', async route => {
    const fields = new URLSearchParams(route.request().postData() || '');
    expect(fields.get('nonce')).toBe('fixture-nonce');
    const response = routeSource && routeSource(fields);
    if (response === 'offline') return route.abort('failed');
    if (response) return route.fulfill(response);
    let data;
    if (fields.get('action') === 'll_tools_category_video_items') {
      const cursor = Number(fields.get('cursor'));
      const id = cursor ? 2 : 1;
      data = {
        items: [{ id, text: cursor ? 'Muz' : 'Sêv', image_url: 'data:,image' }],
        skipped: cursor ? [] : [{ id: 3, text: 'Skipped fixture', reason: 'missing_image' }],
        next_cursor: id,
        has_more: !cursor
      };
    } else if (fields.get('category_id')) {
      data = { speakers: [{ id: 3, name: 'Speaker fixture' }] };
    } else if (fields.get('wordset_id')) {
      data = { categories: [{ id: 2, name: 'Fruit fixture' }] };
    } else {
      data = { wordsets: [{ id: 1, name: 'Wordset fixture' }] };
    }
    return route.fulfill({ json: { success: true, data } });
  });
  await page.goto(origin + '/');
  await page.evaluate(config => {
    window.llCategoryVideo = config;
    window.videoPlayMode = 'success';
    window.LLToolsCategoryVideoRenderer = {
      create: () => ({
        async prepare(items) {
          return { itemCount: items.length, duration: 1 };
        },
        async play({ signal }) {
          if (window.videoPlayMode === 'failed') throw Object.assign(new Error(), { code: 'recording_failed' });
          if (window.videoPlayMode === 'stopped') {
            await new Promise((resolve, reject) => {
              signal.addEventListener('abort', () => reject(new DOMException('Stopped', 'AbortError')), { once: true });
            });
          }
          return { blob: new Blob(['fixture video bytes'], { type: 'video/webm' }), extension: 'webm' };
        },
        cancel() {},
        dispose() {}
      })
    };
  }, fixture.config);
  await page.addScriptTag({ path: path.join(plugin, 'js/category-video-export.js') });
}

async function chooseWords(page) {
  await page.selectOption('#ll-video-wordset', '1');
  await page.selectOption('#ll-video-category', '2');
  await expect(page.locator('#ll-video-speaker')).toHaveValue('3');
  await page.click('#ll-video-load');
  await expect(page.locator('#ll-video-words li')).toHaveCount(1);
}

test('category video controller preserves the completed download across failed or stopped replacements', async ({ page }) => {
  await mountController(page);
  await chooseWords(page);
  const record = page.locator('#ll-video-record');
  const result = page.locator('#ll-video-result');
  const download = page.locator('#ll-video-download');
  await page.click('#ll-video-prepare');
  await record.click();
  await expect(result).toBeVisible();
  const completedUrl = await download.getAttribute('href');
  expect(completedUrl).toMatch(/^blob:/);

  await page.fill('#ll-video-title', 'Next video');
  await expect(record).toBeDisabled();
  await expect(result).toBeVisible();
  await page.click('#ll-video-prepare');

  await page.evaluate(() => { window.videoPlayMode = 'failed'; });
  await record.click();
  await expect(page.locator('#ll-video-status')).toContainText('Video creation failed');
  await expect(download).toHaveAttribute('href', completedUrl);

  await page.evaluate(() => { window.videoPlayMode = 'stopped'; });
  await record.click();
  await page.click('#ll-video-stop');
  await expect(page.locator('#ll-video-status')).toContainText('Stopped.');
  await expect(download).toHaveAttribute('href', completedUrl);
  expect(await page.evaluate(url => fetch(url).then(response => response.text()), completedUrl)).toBe('fixture video bytes');

  await page.evaluate(() => { window.videoPlayMode = 'success'; });
  await record.click();
  await expect(download).not.toHaveAttribute('href', completedUrl);
  expect(await page.evaluate(url => fetch(url).then(() => true, () => false), completedUrl)).toBe(false);
});

test('category video controller localizes transport and parser errors while keeping structured server messages', async ({ page }) => {
  const failures = [
    'offline',
    { status: 502, contentType: 'text/html', body: '<h1>Proxy error</h1>' },
    { status: 403, json: { success: false, data: { message: 'Kaynak için iznin yok.' } } }
  ];
  await mountController(page, () => failures.shift());
  const status = page.locator('#ll-video-status');
  const retry = page.locator('#ll-video-retry-sources');
  await expect(status).toHaveText('Kategori yüklenemedi. Tekrar dene.');
  await retry.click();
  await expect(status).toHaveText('Kategori yüklenemedi. Tekrar dene.');
  await retry.click();
  await expect(status).toHaveText('Kaynak için iznin yok.');
  await retry.click();
  await expect(page.locator('#ll-video-wordset option')).toHaveCount(2);
  await expect(retry).toBeHidden();
});

test('category video controller appends pages and reorders existing rows in place', async ({ page }) => {
  await mountController(page);
  await chooseWords(page);
  const rows = page.locator('#ll-video-words li');
  const first = await rows.first().elementHandle();
  const skipped = await page.locator('#ll-video-skipped li').elementHandle();
  await page.click('#ll-video-more');
  await expect(rows).toHaveCount(2);
  expect(await first.evaluate(node => node === document.querySelector('#ll-video-words li'))).toBe(true);
  expect(await skipped.evaluate(node => node === document.querySelector('#ll-video-skipped li'))).toBe(true);
  const move = rows.first().locator('button').last();
  await move.click();
  await expect(rows.first()).toContainText('Muz');
  expect(await first.evaluate(node => node === document.querySelector('#ll-video-words li:last-child'))).toBe(true);
  await expect(rows.last().locator('button').last()).toBeFocused();
  await page.click('#ll-video-select-none');
  await expect(rows.locator('input:checked')).toHaveCount(0);
  await page.click('#ll-video-select-all');
  await expect(rows.locator('input:checked')).toHaveCount(2);
});
