const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const languageSwitcherSource = fs.readFileSync(
  path.resolve(__dirname, '../../../js/language-switcher.js'),
  'utf8'
);

test('language switcher exposes POST locale actions and focuses the first secondary action', async ({ page }) => {
  await page.setContent(`
    <!doctype html>
    <html>
      <body>
        <nav class="ll-lang-switcher ll-lang-switcher--has-secondary" data-ll-language-switcher>
          <ul class="ll-lang-switcher__list">
            <li class="ll-lang-switcher__more-item">
              <button type="button" data-ll-language-switcher-more aria-expanded="false">More</button>
            </li>
            <li class="ll-lang-switcher__secondary-locale">
              <form method="post" class="ll-lang-switcher__locale-form">
                <input type="hidden" name="ll_locale" value="tr_TR">
                <input type="hidden" name="ll_locale_nonce" value="signed-nonce">
                <button type="submit" class="ll-lang-link">Türkçe</button>
              </form>
            </li>
          </ul>
        </nav>
      </body>
    </html>
  `);
  await page.addScriptTag({ content: languageSwitcherSource });

  await page.locator('[data-ll-language-switcher-more]').click();

  const localeButton = page.locator('.ll-lang-switcher__secondary-locale .ll-lang-link');
  await expect(localeButton).toBeFocused();
  await expect(page.locator('a[href*="ll_locale"]')).toHaveCount(0);

  await page.evaluate(() => {
    document.querySelector('.ll-lang-switcher__locale-form').addEventListener('submit', (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      window.__llLocaleSubmit = {
        method: form.method.toLowerCase(),
        locale: new FormData(form).get('ll_locale'),
        nonce: new FormData(form).get('ll_locale_nonce')
      };
    });
  });

  await localeButton.click();
  await expect.poll(() => page.evaluate(() => window.__llLocaleSubmit || null)).toEqual({
    method: 'post',
    locale: 'tr_TR',
    nonce: 'signed-nonce'
  });
});
