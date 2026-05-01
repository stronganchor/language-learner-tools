const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const repoRoot = path.resolve(__dirname, '..', '..', '..');

function walkFiles(dir, predicate) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (['node_modules', 'vendor', 'languages', 'offline-app-builder'].includes(entry.name)) {
        continue;
      }
      files.push(...walkFiles(fullPath, predicate));
    } else if (predicate(fullPath)) {
      files.push(fullPath);
    }
  }

  return files;
}

test('README documents registered public shortcodes', async () => {
  const readme = fs.readFileSync(path.join(repoRoot, 'README.md'), 'utf8');
  const phpFiles = [
    path.join(repoRoot, 'language-learner-tools.php'),
    ...walkFiles(path.join(repoRoot, 'includes'), (file) => file.endsWith('.php'))
  ];
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
