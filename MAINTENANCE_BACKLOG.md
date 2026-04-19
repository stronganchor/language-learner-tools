# Maintenance Backlog

Deferred maintenance work from the March 9, 2026 audit.

## Lower Priority

- Audio/Image Matcher scalability
  - Current categories are usually small, so pagination/lazy-loading for the matcher is deferred.
  - Relevant files: `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`.

## Larger Projects

- Break up large monolithic files into smaller modules
  - This remains desirable for long-term maintenance, but it is intentionally deferred because it is broader and riskier than the current targeted fixes.
  - High-impact candidates include:
    - `includes/admin/export-import.php`
    - `includes/shortcodes/word-grid-shortcode.php`
    - `includes/shortcodes/audio-recording-shortcode.php`
    - `includes/pages/wordset-pages.php`
    - `js/wordset-pages.js`
    - `js/word-grid.js`
