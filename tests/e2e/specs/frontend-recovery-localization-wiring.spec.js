const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

function source(relativePath) {
  return fs.readFileSync(path.resolve(__dirname, '../../..', relativePath), 'utf8');
}

test('frontend recovery states are wired through WordPress localization', async () => {
  const contentPages = source('includes/pages/content-lesson-pages.php');
  const quizShortcodes = source('includes/shortcodes/quiz-pages-shortcodes.php');
  const audioProcessor = source('includes/admin/audio-processor-admin.php');

  expect(contentPages).toContain("'timeout' => __('Saving took too long. Please retry.'");
  expect(contentPages).toContain("'retry' => __('Retry'");

  expect(quizShortcodes).toContain("esc_attr__('The quiz loading request timed out.'");
  expect(quizShortcodes).toContain("esc_attr__('Quiz loading is taking longer than expected.'");
  expect(quizShortcodes).toContain("esc_html__('Retry'");

  expect(audioProcessor).toContain("'deleteProgressTemplate' => __('Deleting %1$d of %2$d...'");
  expect(audioProcessor).toContain("'deleteRetryTemplate' => __('Retry %d failed deletion(s)'");
  expect(audioProcessor).toContain("'deleteBeforeUnloadWarning' => __('Deletion is still in progress.");
});

test('audio runtime fallback keys all have a server-side gettext source', async () => {
  const contracts = [
    {
      jsPath: 'js/audio-processor.js',
      phpPath: 'includes/admin/audio-processor-admin.php',
      keyRegex: /\bt\(\s*['"]([A-Za-z0-9_]+)['"]/g
    },
    {
      jsPath: 'js/audio-recorder.js',
      phpPath: 'includes/shortcodes/audio-recording-shortcode.php',
      keyRegex: /\bi18n(?:\?\.)?\.([A-Za-z0-9_]+)/g
    }
  ];

  for (const contract of contracts) {
    const js = source(contract.jsPath);
    const php = source(contract.phpPath);
    const keys = [...js.matchAll(contract.keyRegex)].map((match) => match[1]);
    const missing = [...new Set(keys)].sort().filter((key) => {
      const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      return !new RegExp(
        `['"]${escaped}['"]\\s*=>\\s*(?:__|_x|_n|esc_html__|esc_attr__)\\(`
      ).test(php);
    });

    expect(
      missing,
      `${contract.jsPath} fallback keys need gettext-backed localization in ${contract.phpPath}`
    ).toEqual([]);
  }
});
