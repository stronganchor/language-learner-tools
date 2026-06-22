const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..', '..', '..');
const excludedWalkDirs = new Set([
  '.git',
  'languages',
  'node_modules',
  'offline-app-builder',
  'tests',
  'vendor'
]);

function walkFiles(dir, predicate) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (excludedWalkDirs.has(entry.name)) {
        continue;
      }
      files.push(...walkFiles(fullPath, predicate));
    } else if (predicate(fullPath)) {
      files.push(fullPath);
    }
  }

  return files;
}

function pluginSourceFiles() {
  return [
    path.join(repoRoot, 'language-learner-tools.php'),
    ...walkFiles(path.join(repoRoot, 'includes'), (file) => file.endsWith('.php')),
    ...walkFiles(path.join(repoRoot, 'templates'), (file) => file.endsWith('.php')),
    ...walkFiles(path.join(repoRoot, 'js'), (file) => file.endsWith('.js')),
    ...walkFiles(path.join(repoRoot, 'offline-app'), (file) => file.endsWith('.js'))
  ].filter((file) => fs.existsSync(file));
}

function collectContextPackNames() {
  const source = fs.readFileSync(path.join(repoRoot, 'scripts', 'build-ai-context-pack.php'), 'utf8');
  const start = source.indexOf('function ll_tools_context_pack_definitions()');
  const end = source.indexOf('function ll_tools_context_pack_print_usage()');

  expect(start, 'build-ai-context-pack.php is missing ll_tools_context_pack_definitions().').toBeGreaterThanOrEqual(0);
  expect(end, 'build-ai-context-pack.php is missing ll_tools_context_pack_print_usage().').toBeGreaterThan(start);

  const definitionBlock = source.slice(start, end);
  return [...definitionBlock.matchAll(/^\s{8}'([a-z0-9-]+)'\s*=>\s*\[/gm)]
    .map((match) => match[1])
    .sort();
}

function contextPackDefinitionBlock(packName) {
  const source = fs.readFileSync(path.join(repoRoot, 'scripts', 'build-ai-context-pack.php'), 'utf8');
  const marker = `        '${packName}' => [`;
  const start = source.indexOf(marker);

  expect(start, `build-ai-context-pack.php is missing pack ${packName}.`).toBeGreaterThanOrEqual(0);

  const next = source.indexOf("\n        '", start + marker.length);
  const end = next === -1 ? source.indexOf('    ];', start) : next;

  expect(end, `Could not find end of pack block for ${packName}.`).toBeGreaterThan(start);

  return source.slice(start, end);
}

function isLikelyUiText(value) {
  const normalized = value
    .replace(/\\[nrt]/g, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/%\d?\$?[sd]/g, '')
    .trim();

  return /[A-Za-z][A-Za-z ]{2,}/.test(normalized)
    && /\s/.test(normalized)
    && !/^[a-z0-9_ -]+$/.test(normalized);
}

function collectHardcodedUiTextFindings() {
  const quotedString = String.raw`(['"])((?:\\.|(?!\1).){3,})\1`;
  const rules = [
    {
      name: 'php-escaped-literal',
      ext: '.php',
      regex: new RegExp(String.raw`\b(?:esc_html|esc_attr|wp_kses_post)\s*\(\s*${quotedString}`, 'g'),
      textGroup: 2
    },
    {
      name: 'php-message-array-literal',
      ext: '.php',
      regex: new RegExp(String.raw`['"]message['"]\s*=>\s*${quotedString}`, 'g'),
      textGroup: 2
    },
    {
      name: 'php-wp-error-literal',
      ext: '.php',
      regex: new RegExp(String.raw`new\s+WP_Error\s*\(\s*${quotedString}\s*,\s*${quotedString}`, 'g'),
      textGroup: 4
    },
    {
      name: 'js-text-literal',
      ext: '.js',
      regex: new RegExp(String.raw`\.(?:textContent|innerText|innerHTML)\s*=\s*${quotedString}`, 'g'),
      textGroup: 2
    },
    {
      name: 'js-dialog-literal',
      ext: '.js',
      regex: new RegExp(String.raw`\b(?:alert|confirm)\s*\(\s*${quotedString}`, 'g'),
      textGroup: 2
    },
    {
      name: 'js-title-placeholder-literal',
      ext: '.js',
      regex: new RegExp(String.raw`\.(?:title|placeholder)\s*=\s*${quotedString}`, 'g'),
      textGroup: 2
    },
    {
      name: 'js-attribute-literal',
      ext: '.js',
      regex: new RegExp(String.raw`setAttribute\s*\(\s*['"](?:aria-label|title|placeholder)['"]\s*,\s*${quotedString}`, 'g'),
      textGroup: 2
    }
  ];
  const findings = [];

  for (const file of pluginSourceFiles()) {
    const source = fs.readFileSync(file, 'utf8');
    const ext = path.extname(file);

    for (const rule of rules.filter((candidate) => candidate.ext === ext)) {
      let match;
      rule.regex.lastIndex = 0;
      while ((match = rule.regex.exec(source)) !== null) {
        const text = match[rule.textGroup] || '';
        if (!isLikelyUiText(text)) {
          continue;
        }

        findings.push({
          rule: rule.name,
          file: path.relative(repoRoot, file).replace(/\\/g, '/'),
          line: source.slice(0, match.index).split(/\r?\n/).length,
          text
        });
      }
    }
  }

  return findings;
}

function normalizeRestRoutePath(routePath) {
  return routePath.replace(/\(\?P<([^>]+)>[^)]+\)/g, '{$1}');
}

function restMethodsFromDefinition(definition) {
  const methods = new Set();

  if (definition.includes('WP_REST_Server::READABLE')) {
    methods.add('GET');
  }
  if (definition.includes('WP_REST_Server::CREATABLE')) {
    methods.add('POST');
  }
  if (definition.includes('WP_REST_Server::EDITABLE')) {
    methods.add('POST');
    methods.add('PUT');
    methods.add('PATCH');
  }
  if (definition.includes('WP_REST_Server::DELETABLE')) {
    methods.add('DELETE');
  }

  return ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].filter((method) => methods.has(method));
}

function collectRegisteredLlToolsRestRoutes() {
  const sourceFiles = [
    path.join(repoRoot, 'includes', 'api', 'automation-rest.php'),
    path.join(repoRoot, 'includes', 'lib', 'site-sync.php')
  ];
  const routeRegex = /register_rest_route\(\s*['"]ll-tools\/v1['"]\s*,\s*['"]([^'"]+)['"]\s*,\s*\[([\s\S]*?)\]\s*\);/g;
  const routes = [];

  for (const file of sourceFiles) {
    const source = fs.readFileSync(file, 'utf8');
    let match;
    routeRegex.lastIndex = 0;

    while ((match = routeRegex.exec(source)) !== null) {
      const routePath = normalizeRestRoutePath(match[1]);
      const methods = restMethodsFromDefinition(match[2]);

      for (const method of methods) {
        routes.push(`${method} ${routePath}`);
      }
    }
  }

  return [...new Set(routes)].sort();
}

function collectAutomationStatusRoutePaths() {
  const source = fs.readFileSync(path.join(repoRoot, 'includes', 'api', 'automation-rest.php'), 'utf8');
  const routesBlockMatch = source.match(/['"]routes['"]\s*=>\s*\[([\s\S]*?)\],\s*['"]resource_guard['"]/);

  if (!routesBlockMatch) {
    return null;
  }

  return [...routesBlockMatch[1].matchAll(/['"][a-z_]+['"]\s*=>\s*['"]\/ll-tools\/v1([^'"]+)['"]/g)]
    .map((match) => match[1])
    .sort();
}

function collectBootstrapIncludePaths() {
  const source = fs.readFileSync(path.join(repoRoot, 'includes', 'bootstrap.php'), 'utf8');
  const requireRegex = /require_once\s*\(?\s*(?:__DIR__\s*\.\s*['"]([^'"]+\.php)['"]|LL_TOOLS_BASE_PATH\s*\.\s*['"]([^'"]+\.php)['"])/g;
  const paths = [];
  let match;

  while ((match = requireRegex.exec(source)) !== null) {
    const rawPath = (match[1] || match[2]).replace(/\\/g, '/').replace(/^\//, '');
    const normalized = rawPath.startsWith('includes/') || rawPath.startsWith('vendor/')
      ? rawPath
      : `includes/${rawPath}`;

    if (normalized.startsWith('vendor/')) {
      continue;
    }

    paths.push(normalized);
  }

  return [...new Set(paths)];
}

test('README documents registered public shortcodes', async () => {
  const readme = fs.readFileSync(path.join(repoRoot, 'README.md'), 'utf8');
  const phpFiles = pluginSourceFiles().filter((file) => file.endsWith('.php'));
  const shortcodeTags = new Set();
  const shortcodeRegex = /add_shortcode\(\s*['"]([^'"]+)['"]/g;

  for (const file of phpFiles) {
    const source = fs.readFileSync(file, 'utf8');
    let match;
    while ((match = shortcodeRegex.exec(source)) !== null) {
      const tag = match[1];
      if (tag.startsWith('test_')) {
        continue;
      }
      shortcodeTags.add(tag);
    }
  }

  const missing = [...shortcodeTags]
    .sort()
    .filter((tag) => !readme.includes(`[${tag}]`));

  expect(missing, `README.md is missing shortcode docs for: ${missing.join(', ')}`).toEqual([]);
});

test('CODEBASE_ARCHITECTURE bootstrap include index matches loaded plugin modules', async () => {
  const docs = fs.readFileSync(path.join(repoRoot, 'CODEBASE_ARCHITECTURE.md'), 'utf8');
  const indexMatch = docs.match(/<!-- bootstrap-include-index:start -->([\s\S]*?)<!-- bootstrap-include-index:end -->/);

  expect(indexMatch, 'CODEBASE_ARCHITECTURE.md is missing the bootstrap include index block.').not.toBeNull();

  const documented = [...indexMatch[1].matchAll(/^\s*-\s+(includes\/[^\s`]+\.php)\s*$/gm)]
    .map((match) => match[1]);

  expect(documented).toEqual(collectBootstrapIncludePaths());
});

test('AI context router and workflow docs cover configured context packs', async () => {
  const packNames = collectContextPackNames();
  const contextReadme = fs.readFileSync(path.join(repoRoot, 'docs', 'ai-context', 'README.md'), 'utf8');
  const router = fs.readFileSync(path.join(repoRoot, 'docs', 'ai-context', 'task-router.md'), 'utf8');
  const workflow = fs.readFileSync(path.join(repoRoot, 'docs', 'ai-context', 'AGENT_WORKFLOW.md'), 'utf8');
  const ignorePolicy = fs.readFileSync(path.join(repoRoot, 'docs', 'ai-context', 'AI_IGNORE.md'), 'utf8');

  for (const requiredDoc of ['task-router.md', 'AI_IGNORE.md', 'AGENT_WORKFLOW.md']) {
    expect(contextReadme, `docs/ai-context/README.md should link ${requiredDoc}.`).toContain(requiredDoc);
  }
  for (const command of ['--suggest-pack', '--activity-report']) {
    expect(contextReadme, `docs/ai-context/README.md should document ${command}.`).toContain(command);
    expect(router, `docs/ai-context/task-router.md should document ${command}.`).toContain(command);
    expect(workflow, `docs/ai-context/AGENT_WORKFLOW.md should document ${command}.`).toContain(command);
  }

  const missingFromRouter = packNames.filter((packName) => !router.includes(`\`${packName}\``));
  expect(
    missingFromRouter,
    `docs/ai-context/task-router.md is missing configured packs: ${missingFromRouter.join(', ')}`
  ).toEqual([]);

  for (const packName of packNames) {
    const block = contextPackDefinitionBlock(packName);
    expect(block, `Context pack ${packName} should define task-routing signals.`).toContain("'signals' => [");
  }

  expect(workflow).toContain('Feedback Loop');
  expect(ignorePolicy).toContain('Usually Skip On First Pass');
  expect(ignorePolicy).toContain('--activity-report');
});

test('high-confidence user-facing strings are translation-ready', async () => {
  const findings = collectHardcodedUiTextFindings();
  const formatted = findings.map((finding) => (
    `${finding.rule} ${finding.file}:${finding.line} ${JSON.stringify(finding.text)}`
  ));

  expect(formatted, `Hardcoded UI strings need WordPress i18n wrappers:\n${formatted.join('\n')}`).toEqual([]);
});

test('wordset games does not duplicate English i18n fallback strings in JS', async () => {
  const file = path.join(repoRoot, 'js', 'wordset-games.js');
  const source = fs.readFileSync(file, 'utf8');
  const fallbackRegexes = [
    /\b(?:ctx|cfg)\.i18n\.[A-Za-z0-9_]+\s*\|\|\s*(['"])(?=[A-Z])[\s\S]*?\1/g,
    /\b(?:ctx|cfg)\s*&&\s*(?:ctx|cfg)\.i18n\s*&&\s*(?:ctx|cfg)\.i18n\.[A-Za-z0-9_]+\s*\|\|\s*(['"])(?=[A-Z])[\s\S]*?\1/g,
    /\(\s*(?:ctx|cfg)\.i18n\s*&&\s*(?:ctx|cfg)\.i18n\.[A-Za-z0-9_]+\s*\)\s*\|\|\s*(['"])(?=[A-Z])[\s\S]*?\1/g
  ];
  const findings = [];

  for (const regex of fallbackRegexes) {
    let match;
    regex.lastIndex = 0;
    while ((match = regex.exec(source)) !== null) {
      const line = source.slice(0, match.index).split(/\r?\n/).length;
      findings.push(`js/wordset-games.js:${line} ${JSON.stringify(match[0])}`);
    }
  }

  expect(
    findings,
    `Move localized game UI copy to ll_tools_get_wordset_games_i18n_messages():\n${findings.join('\n')}`
  ).toEqual([]);
});

test('Turkish translation avoids high-risk tone and glossary regressions', async () => {
  const file = path.join(repoRoot, 'languages', 'll-tools-text-domain-tr_TR.po');
  const source = fs.readFileSync(file, 'utf8');
  const translationLines = [];
  let inTranslation = false;

  for (const line of source.split(/\r?\n/)) {
    if (/^msgstr(?:\[\d+\])?\s+/.test(line)) {
      inTranslation = true;
      translationLines.push(line);
    } else if (inTranslation && /^"/.test(line)) {
      translationLines.push(line);
    } else if (/^(msgid|msgctxt|#|$)/.test(line)) {
      inTranslation = false;
    }
  }

  const checks = [
    {
      name: 'formal second-person tone',
      regex: /(?:hesab\u0131n\u0131z|\u015fifreniz|izniniz|yap\u0131n|misiniz|musunuz|unuz|\u00fcn\u00fcz)/iu
    },
    {
      name: 'word set glossary',
      regex: /s\u00f6zc\u00fck\s+k\u00fcmes/iu
    },
    {
      name: 'part of speech glossary',
      regex: /s\u00f6zc\u00fck\s+t\u00fcr/iu
    },
    {
      name: 'word image glossary',
      regex: /kelime\s+g\u00f6r\u00fcnt/iu
    },
    {
      name: 'quiz glossary',
      regex: /\b[Ss]\u0131nav\b/u
    },
    {
      name: 'English entity fallback',
      regex: /msgstr\s+"(?:Word Audio|Flashcard G\u00f6r\u00fcnt\u00fc)"/u
    },
    {
      name: 'manager glossary',
      regex: /M\u00fcd\u00fcr/u
    }
  ];
  const findings = [];

  translationLines.forEach((line, index) => {
    for (const check of checks) {
      if (check.regex.test(line)) {
        findings.push(`${check.name} near translation segment ${index + 1}: ${line}`);
      }
    }
  });

  expect(
    findings,
    `Review languages/TURKISH_TRANSLATION_GUIDELINES.md before changing Turkish PO glossary/tone terms:\n${findings.join('\n')}`
  ).toEqual([]);
});

test('PHP include and template files block direct web access', async () => {
  const phpFiles = pluginSourceFiles().filter((file) => file.endsWith('.php'));
  const missingGuards = phpFiles
    .filter((file) => file !== path.join(repoRoot, 'language-learner-tools.php'))
    .filter((file) => {
      const sourcePrefix = fs.readFileSync(file, 'utf8').slice(0, 500);
      return !/defined\s*\(\s*['"](WPINC|ABSPATH)['"]/.test(sourcePrefix);
    })
    .map((file) => path.relative(repoRoot, file).replace(/\\/g, '/'))
    .sort();

  expect(
    missingGuards,
    `PHP include/template files need direct-access guards:\n${missingGuards.join('\n')}`
  ).toEqual([]);
});

test('live smoke default admin-ajax allowlist is documented', async () => {
  const readme = fs.readFileSync(path.join(repoRoot, 'tests', 'README.md'), 'utf8');
  const liveSmokeSpec = fs.readFileSync(
    path.join(repoRoot, 'tests', 'e2e', 'live-smoke', 'live-sites.spec.js'),
    'utf8'
  );
  const allowlistMatch = liveSmokeSpec.match(/const allowed = new Set\(\[([\s\S]*?)\]\);/);

  expect(allowlistMatch, 'Could not find the live-smoke default admin-ajax allowlist.').not.toBeNull();

  const documentedMissing = [...allowlistMatch[1].matchAll(/['"]([^'"]+)['"]/g)]
    .map((match) => match[1])
    .filter((action) => !readme.includes(`action=${action}`));

  expect(
    documentedMissing,
    `tests/README.md is missing live-smoke allowed admin-ajax action docs for: ${documentedMissing.join(', ')}`
  ).toEqual([]);
});

test('REST automation docs cover corpus text routes exposed by status', async () => {
  const docs = fs.readFileSync(path.join(repoRoot, 'docs', 'REST_AUTOMATION.md'), 'utf8');
  const source = fs.readFileSync(path.join(repoRoot, 'includes', 'api', 'automation-rest.php'), 'utf8');
  const routePairs = [
    ['corpus_text_asset', 'POST /corpus-texts/asset'],
    ['corpus_text_import', 'POST /corpus-texts/import'],
    ['corpus_text', 'GET /corpus-texts/{slug}']
  ];

  for (const [statusKey, docRoute] of routePairs) {
    expect(source).toContain(`'${statusKey}'`);
    expect(docs).toContain(docRoute);
  }
});

test('REST automation docs cover every registered ll-tools route', async () => {
  const docs = fs.readFileSync(path.join(repoRoot, 'docs', 'REST_AUTOMATION.md'), 'utf8');
  const registeredRoutes = collectRegisteredLlToolsRestRoutes();
  const missing = registeredRoutes.filter((route) => !docs.includes(`\`${route}\``));

  expect(
    missing,
    `docs/REST_AUTOMATION.md is missing registered REST routes:\n${missing.join('\n')}`
  ).toEqual([]);
});

test('automation status route map matches the registered ll-tools REST paths', async () => {
  const registeredPaths = [...new Set(collectRegisteredLlToolsRestRoutes().map((route) => route.replace(/^[A-Z]+ /, '')))].sort();
  const statusPaths = collectAutomationStatusRoutePaths();

  expect(statusPaths, 'Could not find the automation status routes map.').not.toBeNull();
  expect(statusPaths).toEqual(registeredPaths);
});
