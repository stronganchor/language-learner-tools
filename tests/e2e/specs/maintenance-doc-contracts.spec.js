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
    ...walkFiles(path.join(repoRoot, 'js'), (file) => file.endsWith('.js'))
  ].filter((file) => fs.existsSync(file));
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

test('high-confidence user-facing strings are translation-ready', async () => {
  const findings = collectHardcodedUiTextFindings();
  const formatted = findings.map((finding) => (
    `${finding.rule} ${finding.file}:${finding.line} ${JSON.stringify(finding.text)}`
  ));

  expect(formatted, `Hardcoded UI strings need WordPress i18n wrappers:\n${formatted.join('\n')}`).toEqual([]);
});
