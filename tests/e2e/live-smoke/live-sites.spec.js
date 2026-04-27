const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const DEFAULT_SITES_FILE = path.resolve(__dirname, 'sites.local.json');
const EXAMPLE_SITES_FILE = path.resolve(__dirname, 'sites.example.json');
const PAUSE_MS = Math.max(0, parseInt(process.env.LL_LIVE_SMOKE_PAUSE_MS || '', 10) || 2000);

function resolveSitesFilePath(rawPath) {
  if (!rawPath) {
    return DEFAULT_SITES_FILE;
  }
  if (path.isAbsolute(rawPath)) {
    return rawPath;
  }
  return path.resolve(process.cwd(), rawPath);
}

function normalizeComparableText(value) {
  return String(value || '')
    .normalize('NFKC')
    .replace(/[\u2010-\u2015\u2212]/g, '-')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

function expectTextIncludes(actual, expected, label) {
  const expectedText = normalizeComparableText(expected);
  expect(expectedText, label + ' expected text should not be empty.').not.toBe('');
  expect(normalizeComparableText(actual), label).toContain(expectedText);
}

function normalizeList(value) {
  return Array.isArray(value) ? value.filter(Boolean).map((item) => String(item)) : [];
}

function parseRequestDetails(request) {
  const url = request.url();
  const method = String(request.method() || '').toUpperCase();

  let parsedUrl = null;
  try {
    parsedUrl = new URL(url);
  } catch (_) {
    parsedUrl = null;
  }

  const details = {
    method,
    url,
    pathname: parsedUrl ? parsedUrl.pathname : '',
    adminAjaxAction: '',
    postData: ''
  };

  if (method !== 'POST') {
    return details;
  }

  const postData = String(request.postData() || '');
  details.postData = postData;
  if (!postData) {
    return details;
  }

  const params = new URLSearchParams(postData);
  details.adminAjaxAction = String(params.get('action') || '').trim();
  return details;
}

function getAllowedAdminAjaxActions(networkConfig) {
  const configured = normalizeList(networkConfig.allowedAdminAjaxActions);
  const allowed = new Set(['ll_get_words_by_category']);
  configured.forEach((action) => allowed.add(action));
  return allowed;
}

function isAllowedSameOriginNonGetRequest(details, networkConfig) {
  if (!details || details.method === 'GET') {
    return true;
  }

  if (details.method === 'POST' && /\/wp-admin\/admin-ajax\.php$/i.test(details.pathname || '')) {
    return getAllowedAdminAjaxActions(networkConfig).has(details.adminAjaxAction);
  }

  return false;
}

function loadSites() {
  const sitesFile = resolveSitesFilePath(process.env.LL_LIVE_SITES_FILE || '');
  if (!fs.existsSync(sitesFile)) {
    throw new Error(
      'Live smoke sites file was not found: ' + sitesFile +
      '. Copy ' + EXAMPLE_SITES_FILE + ' to ' + DEFAULT_SITES_FILE +
      ' or set LL_LIVE_SITES_FILE to a local JSON file.'
    );
  }

  const raw = fs.readFileSync(sitesFile, 'utf8');
  const parsed = JSON.parse(raw);
  if (!Array.isArray(parsed) || parsed.length === 0) {
    throw new Error('Live smoke sites file must contain a non-empty JSON array: ' + sitesFile);
  }

  parsed.forEach((site, index) => {
    if (!site || typeof site !== 'object') {
      throw new Error('Live smoke site entry #' + (index + 1) + ' must be an object.');
    }
    if (!site.name || !site.url) {
      throw new Error('Live smoke site entry #' + (index + 1) + ' must include "name" and "url".');
    }
    // Validate URL early so Playwright failures are easier to diagnose.
    // eslint-disable-next-line no-new
    new URL(site.url);
  });

  return {
    file: sitesFile,
    sites: parsed
  };
}

let loadedSites;
let loadSitesError = null;

try {
  loadedSites = loadSites();
} catch (error) {
  loadSitesError = error;
  loadedSites = { file: resolveSitesFilePath(process.env.LL_LIVE_SITES_FILE || ''), sites: [] };
}

async function attachJson(testInfo, name, payload) {
  await testInfo.attach(name, {
    body: Buffer.from(JSON.stringify(payload, null, 2), 'utf8'),
    contentType: 'application/json'
  });
}

async function collectSnapshot(page) {
  return page.evaluate(() => {
    const getFirst = (selector) => document.querySelector(selector);
    const getAll = (selector) => Array.from(document.querySelectorAll(selector));
    const isVisible = (element) => {
      if (!element) {
        return false;
      }
      const style = window.getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
    };
    const visibleText = (selector) => {
      const element = getFirst(selector);
      if (!isVisible(element)) {
        return '';
      }
      return String(element.textContent || '').trim().replace(/\s+/g, ' ');
    };
    const visibleCount = (selector) => getAll(selector).filter((element) => isVisible(element)).length;

    return {
      title: document.title || '',
      h1: visibleText('h1'),
      hasWordsetPage: !!getFirst('[data-ll-wordset-page], .ll-wordset-page'),
      hasDictionary: !!getFirst('.ll-dictionary'),
      utilityBarVisible: isVisible(getFirst('.ll-wordset-utility-bar')),
      wordsetSearchVisible: isVisible(getFirst('#ll-wordset-page-search-input')),
      dictionarySearchVisible: isVisible(getFirst('#ll-dictionary-search')),
      wordsetCardCount: getAll('.ll-wordset-card').length,
      visibleWordsetCardCount: visibleCount('.ll-wordset-card'),
      wordsetModeButtonCount: getAll('[data-ll-wordset-category-mode]').length,
      visibleWordsetModeButtonCount: visibleCount('[data-ll-wordset-category-mode]'),
      quizTriggerCount: getAll('.ll-quiz-page-trigger').length,
      visibleQuizTriggerCount: visibleCount('.ll-quiz-page-trigger'),
      emptyTexts: [
        visibleText('.ll-wordset-empty'),
        visibleText('.ll-vocab-lessons-empty'),
        visibleText('.ll-wordset-page__empty'),
        visibleText('.ll-wordset-empty--search')
      ].filter(Boolean),
      headings: getAll('h1, h2')
        .map((element) => String(element.textContent || '').trim().replace(/\s+/g, ' '))
        .filter(Boolean)
        .slice(0, 12)
    };
  });
}

async function countVisible(page, selector) {
  return page.evaluate((targetSelector) => {
    return Array.from(document.querySelectorAll(targetSelector)).filter((element) => {
      const style = window.getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
    }).length;
  }, selector);
}

async function isSelectorVisible(page, selector) {
  return page.evaluate((targetSelector) => {
    const element = document.querySelector(targetSelector);
    if (!element) {
      return false;
    }
    const style = window.getComputedStyle(element);
    return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
  }, selector);
}

async function findFirstVisibleLocator(page, selector) {
  const locator = page.locator(selector);
  const count = await locator.count();
  for (let index = 0; index < count; index += 1) {
    const candidate = locator.nth(index);
    if (await candidate.isVisible().catch(() => false)) {
      return candidate;
    }
  }
  return null;
}

function parseLessonCount(text) {
  const match = String(text || '').match(/(\d+)/);
  return match ? parseInt(match[1], 10) || 0 : 0;
}

function pickSearchProbe(snapshot, configuredProbe) {
  if (configuredProbe) {
    return String(configuredProbe).trim();
  }

  const headings = Array.isArray(snapshot.headings) ? snapshot.headings : [];
  for (const heading of headings) {
    const trimmed = String(heading || '').trim();
    if (!trimmed || /^quiz results$/i.test(trimmed)) {
      continue;
    }
    const tokens = trimmed.split(/\s+/).filter(Boolean);
    const token = tokens.find((item) => item.length >= 3) || trimmed;
    const probe = token.slice(0, Math.min(5, token.length)).trim();
    if (probe) {
      return probe;
    }
  }

  return 'test';
}

async function navigateViaMostLessonsWordsetButton(page, navigationConfig) {
  const buttonSelector = navigationConfig.buttonSelector || '.ll-wordset-buttons-shortcode__button';
  const countSelector = navigationConfig.countSelector || '.ll-wordset-buttons-shortcode__count';
  const navigationTimeoutMs = navigationConfig.navigationTimeoutMs || 30000;
  const settleMs = navigationConfig.settleMs || 1500;

  const candidates = await page.locator(buttonSelector).evaluateAll((nodes, selectors) => {
    const { countSelectorValue } = selectors;

    const isVisible = (element) => {
      if (!element) {
        return false;
      }
      const style = window.getComputedStyle(element);
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0;
    };

    return nodes.map((node, index) => {
      const countNode = countSelectorValue ? node.querySelector(countSelectorValue) : null;
      return {
        index,
        visible: isVisible(node),
        href: node instanceof HTMLAnchorElement ? node.href : '',
        label: String((node.textContent || '').trim()).replace(/\s+/g, ' '),
        countText: String(countNode ? (countNode.textContent || '') : '').trim()
      };
    });
  }, {
    countSelectorValue: countSelector
  });

  const visibleCandidates = candidates
    .map((candidate) => Object.assign({}, candidate, {
      lessonCount: parseLessonCount(candidate.countText)
    }))
    .filter((candidate) => candidate.visible && candidate.href);

  expect(
    visibleCandidates.length,
    'No visible wordset buttons were found for the configured live-smoke navigation.'
  ).toBeGreaterThan(0);

  visibleCandidates.sort((left, right) => {
    if (left.lessonCount !== right.lessonCount) {
      return right.lessonCount - left.lessonCount;
    }
    return String(left.label || '').localeCompare(String(right.label || ''));
  });

  const selected = visibleCandidates[0];
  const currentUrl = page.url();
  const target = page.locator(buttonSelector).nth(selected.index);
  let clickNavigationResolved = false;

  await Promise.all([
    page.waitForNavigation({
      timeout: Math.min(navigationTimeoutMs, 8000),
      waitUntil: 'domcontentloaded'
    }).then(() => {
      clickNavigationResolved = true;
    }).catch(() => null),
    target.click({ force: true, timeout: navigationTimeoutMs })
  ]);

  if (!clickNavigationResolved && page.url() === currentUrl) {
    await page.goto(selected.href, {
      timeout: navigationTimeoutMs,
      waitUntil: 'domcontentloaded'
    });
  } else {
    await page.waitForLoadState('domcontentloaded', { timeout: navigationTimeoutMs });
  }

  if (settleMs > 0) {
    await page.waitForTimeout(settleMs);
  }

  return {
    type: 'wordsetButtonMostLessons',
    selectedLabel: selected.label,
    selectedLessonCount: selected.lessonCount,
    selectedHref: selected.href,
    finalUrl: page.url()
  };
}

async function maybePerformNavigation(page, navigationConfig) {
  if (!navigationConfig || typeof navigationConfig !== 'object') {
    return null;
  }
  if (!navigationConfig.type) {
    return null;
  }

  if (navigationConfig.type === 'wordsetButtonMostLessons') {
    return navigateViaMostLessonsWordsetButton(page, navigationConfig);
  }

  throw new Error('Unsupported live-smoke navigation type: ' + String(navigationConfig.type || ''));
}

async function exerciseWordsetSearch(page, snapshot, exerciseConfig) {
  const searchInput = page.locator('#ll-wordset-page-search-input');
  await expect(searchInput).toBeVisible();

  const initialVisibleCardCount = await countVisible(page, '.ll-wordset-card');
  expect(initialVisibleCardCount).toBeGreaterThan(0);

  const probe = pickSearchProbe(snapshot, exerciseConfig.wordsetSearchProbe);
  await searchInput.fill(probe);
  await page.waitForTimeout(350);

  const filteredVisibleCardCount = await countVisible(page, '.ll-wordset-card');
  expect(filteredVisibleCardCount).toBeGreaterThanOrEqual(1);

  await searchInput.fill('__codex_live_smoke_no_match__');
  await page.waitForTimeout(350);

  const noMatchVisibleCardCount = await countVisible(page, '.ll-wordset-card');
  const emptyVisible = await isSelectorVisible(page, '[data-ll-wordset-page-search-empty], .ll-wordset-empty--search');
  expect(noMatchVisibleCardCount).toBe(0);
  expect(emptyVisible).toBe(true);

  await searchInput.fill('');
  await page.waitForTimeout(350);

  const restoredVisibleCardCount = await countVisible(page, '.ll-wordset-card');
  expect(restoredVisibleCardCount).toBeGreaterThanOrEqual(initialVisibleCardCount);

  return {
    probe,
    initialVisibleCardCount,
    filteredVisibleCardCount,
    noMatchVisibleCardCount,
    restoredVisibleCardCount
  };
}

async function exercisePopupOpenClose(page, interactionConfig) {
  const popupSelector = interactionConfig.popupSelector || '#ll-tools-flashcard-quiz-popup';
  const closeSelector = interactionConfig.closeSelector || '#ll-tools-close-flashcard';
  const popup = page.locator(popupSelector);
  const trigger = await findFirstVisibleLocator(page, interactionConfig.openSelector);

  expect(trigger, 'No visible trigger was found for selector "' + interactionConfig.openSelector + '".').not.toBeNull();

  const triggerText = String((await trigger.textContent().catch(() => '')) || '').trim().replace(/\s+/g, ' ');
  await trigger.click({ timeout: interactionConfig.openTimeoutMs || 15000, force: true });

  await expect(popup).toBeVisible({ timeout: interactionConfig.popupVisibleTimeoutMs || 30000 });

  if (interactionConfig.expectModeSwitcher !== false) {
    await expect(page.locator('#ll-tools-mode-switcher-wrap')).toBeVisible({ timeout: 30000 });
  }

  const bodyClassWhileOpen = await page.locator('body').getAttribute('class');
  for (const expectedClass of normalizeList(interactionConfig.expectBodyClassIncludes)) {
    expect(String(bodyClassWhileOpen || '')).toContain(expectedClass);
  }

  const closeButton = page.locator(closeSelector);
  await expect(closeButton).toBeVisible({ timeout: interactionConfig.closeVisibleTimeoutMs || 30000 });
  await closeButton.click({ timeout: interactionConfig.closeTimeoutMs || 15000, force: true });
  await expect(popup).toBeHidden({ timeout: interactionConfig.popupHiddenTimeoutMs || 30000 });

  const bodyClassAfterClose = await page.locator('body').getAttribute('class');
  for (const removedClass of normalizeList(interactionConfig.expectBodyClassRemoved)) {
    expect(String(bodyClassAfterClose || '')).not.toContain(removedClass);
  }

  return {
    triggerText,
    bodyClassWhileOpen: bodyClassWhileOpen || '',
    bodyClassAfterClose: bodyClassAfterClose || ''
  };
}

if (loadSitesError) {
  test('live smoke configuration is available', async () => {
    throw loadSitesError;
  });
} else {
  for (const site of loadedSites.sites) {
    test(site.name + ' public smoke check', async ({ page }, testInfo) => {
      const siteUrl = new URL(site.url);
      const expected = site.expected && typeof site.expected === 'object' ? site.expected : {};
      const exercise = site.exercise && typeof site.exercise === 'object' ? site.exercise : {};
      const interaction = site.interaction && typeof site.interaction === 'object' ? site.interaction : {};
      const navigation = site.navigation && typeof site.navigation === 'object' ? site.navigation : {};
      const network = site.network && typeof site.network === 'object' ? site.network : {};

      const summary = {
        name: site.name,
        url: site.url,
        sitesFile: loadedSites.file,
        consoleErrors: [],
        pageErrors: [],
        sameOriginNonGetRequests: [],
        allowedSameOriginNonGetRequests: [],
        unexpectedSameOriginNonGetRequests: [],
        sameOriginRequestFailures: [],
        sameOriginServerErrors: []
      };

      page.on('console', (message) => {
        if (message.type() === 'error') {
          summary.consoleErrors.push(message.text());
        }
      });

      page.on('pageerror', (error) => {
        summary.pageErrors.push(String(error));
      });

      page.on('request', (request) => {
        try {
          if (new URL(request.url()).origin !== siteUrl.origin) {
            return;
          }
        } catch (_) {
          return;
        }
        if (request.method() !== 'GET') {
          const requestDetails = parseRequestDetails(request);
          summary.sameOriginNonGetRequests.push(requestDetails);
          if (isAllowedSameOriginNonGetRequest(requestDetails, network)) {
            summary.allowedSameOriginNonGetRequests.push(requestDetails);
          } else {
            summary.unexpectedSameOriginNonGetRequests.push(requestDetails);
          }
        }
      });

      page.on('requestfailed', (request) => {
        try {
          if (new URL(request.url()).origin !== siteUrl.origin) {
            return;
          }
        } catch (_) {
          return;
        }
        summary.sameOriginRequestFailures.push({
          method: request.method(),
          url: request.url(),
          error: request.failure() ? request.failure().errorText : 'request_failed'
        });
      });

      page.on('response', (response) => {
        try {
          if (new URL(response.url()).origin !== siteUrl.origin) {
            return;
          }
        } catch (_) {
          return;
        }
        if (response.status() >= 500) {
          summary.sameOriginServerErrors.push({
            status: response.status(),
            url: response.url()
          });
        }
      });

      const response = await page.goto(site.url, {
        waitUntil: 'domcontentloaded',
        timeout: site.gotoTimeoutMs || 45000
      });

      summary.status = response ? response.status() : null;
      expect(summary.status, 'Expected a successful document response.').not.toBeNull();
      expect(summary.status).toBeLessThan(400);

      await page.waitForTimeout(site.settleMs || 1500);

      summary.navigation = await maybePerformNavigation(page, navigation);
      if (summary.navigation) {
        summary.entryPhase = {
          sameOriginNonGetRequests: summary.sameOriginNonGetRequests.slice(),
          allowedSameOriginNonGetRequests: summary.allowedSameOriginNonGetRequests.slice(),
          unexpectedSameOriginNonGetRequests: summary.unexpectedSameOriginNonGetRequests.slice(),
          sameOriginRequestFailures: summary.sameOriginRequestFailures.slice(),
          sameOriginServerErrors: summary.sameOriginServerErrors.slice()
        };
        summary.sameOriginNonGetRequests = [];
        summary.allowedSameOriginNonGetRequests = [];
        summary.unexpectedSameOriginNonGetRequests = [];
        summary.sameOriginRequestFailures = [];
        summary.sameOriginServerErrors = [];
      }
      summary.finalUrl = page.url();

      summary.snapshot = await collectSnapshot(page);
      summary.title = summary.snapshot.title;
      summary.h1 = summary.snapshot.h1;

      if (expected.titleIncludes) {
        expectTextIncludes(summary.title, expected.titleIncludes, 'Expected document title to include configured text.');
      }

      if (expected.h1Includes) {
        expectTextIncludes(summary.h1, expected.h1Includes, 'Expected primary heading to include configured text.');
      }

      if (typeof expected.hasWordsetPage === 'boolean') {
        expect(summary.snapshot.hasWordsetPage).toBe(expected.hasWordsetPage);
      }

      if (typeof expected.hasDictionary === 'boolean') {
        expect(summary.snapshot.hasDictionary).toBe(expected.hasDictionary);
      }

      if (typeof expected.expectUtilityBar === 'boolean') {
        expect(summary.snapshot.utilityBarVisible).toBe(expected.expectUtilityBar);
      }

      if (typeof expected.expectWordsetSearch === 'boolean') {
        expect(summary.snapshot.wordsetSearchVisible).toBe(expected.expectWordsetSearch);
      }

      if (typeof expected.expectDictionarySearch === 'boolean') {
        expect(summary.snapshot.dictionarySearchVisible).toBe(expected.expectDictionarySearch);
      }

      if (typeof expected.minWordsetCards === 'number') {
        expect(summary.snapshot.wordsetCardCount).toBeGreaterThanOrEqual(expected.minWordsetCards);
      }

      if (typeof expected.minWordsetModeButtons === 'number') {
        expect(summary.snapshot.wordsetModeButtonCount).toBeGreaterThanOrEqual(expected.minWordsetModeButtons);
      }

      if (typeof expected.minQuizTriggers === 'number') {
        expect(summary.snapshot.quizTriggerCount).toBeGreaterThanOrEqual(expected.minQuizTriggers);
      }

      if (expected.emptyTextIncludes) {
        expectTextIncludes(
          summary.snapshot.emptyTexts.join(' '),
          expected.emptyTextIncludes,
          'Expected empty-state text to include configured text.'
        );
      }

      if (exercise.wordsetSearch) {
        summary.wordsetSearchExercise = await exerciseWordsetSearch(page, summary.snapshot, exercise);
      }

      if (interaction.openSelector) {
        summary.popupExercise = await exercisePopupOpenClose(page, interaction);
      }

      await attachJson(testInfo, 'summary', summary);

      expect(summary.pageErrors, 'Unhandled page errors were raised.').toEqual([]);
      expect(summary.sameOriginRequestFailures, 'Same-origin requests failed.').toEqual([]);
      expect(summary.sameOriginServerErrors, 'Same-origin responses returned 5xx.').toEqual([]);

      const maxUnexpectedSameOriginNonGetRequests = typeof network.maxUnexpectedSameOriginNonGetRequests === 'number'
        ? network.maxUnexpectedSameOriginNonGetRequests
        : (typeof network.maxSameOriginNonGetRequests === 'number'
          ? network.maxSameOriginNonGetRequests
          : 0);
      expect(
        summary.unexpectedSameOriginNonGetRequests.length,
        'Too many unexpected same-origin non-GET requests were made during the smoke test.'
      ).toBeLessThanOrEqual(maxUnexpectedSameOriginNonGetRequests);

      if (typeof network.maxTotalSameOriginNonGetRequests === 'number') {
        expect(
          summary.sameOriginNonGetRequests.length,
          'Too many total same-origin non-GET requests were made during the smoke test.'
        ).toBeLessThanOrEqual(network.maxTotalSameOriginNonGetRequests);
      }

      if (PAUSE_MS > 0) {
        await page.waitForTimeout(PAUSE_MS);
      }
    });
  }
}
