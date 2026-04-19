const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('vocab lesson print template includes both head and footer hooks', () => {
  const templateSource = fs.readFileSync(
    path.resolve(__dirname, '../../../templates/vocab-lesson-print-template.php'),
    'utf8'
  );

  expect(templateSource).toContain('<?php wp_head(); ?>');
  expect(templateSource).toContain('<?php wp_footer(); ?>');
});
