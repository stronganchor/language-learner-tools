# Starter English Live Test Todo

Created: 2026-06-29

Use this checklist for bounded live testing of `https://starterenglish.com/`.
Keep the run serial and read-heavy. Back off instead of retrying loops when the
server-health report is hot.

## Load Gate

- [ ] Run the protected server-health check before touching the live site:
  `C:\Users\messy\OneDrive\Documents\GitHub\server-health-report\tools\check-local-report.ps1 -RawJson`
- [ ] Record `status.level`, `status.reasons`, `php_fpm.total_workers`, the top
  PHP-FPM pools, and the `starterenglish_com` pool.
- [ ] Stop if the report is `CRITICAL`, any core service is down, or
  `starterenglish_com` is near saturation.
- [ ] If the report is `WARN` only because of recent historical
  `max_children` lines, proceed only when current `starterenglish_com` pressure
  is low and checks stay single-pass.
- [ ] Re-run the health check after the public smoke pass and again before any
  authenticated/admin browsing.
- [ ] Re-run the health check at closeout and include the before/after worker
  counts in the handoff.

## Public Smoke

- [ ] Send one header probe with a short timeout:
  `curl.exe -I -L --max-time 20 https://starterenglish.com/`
- [ ] Run the existing live smoke suite against only the Starter English entry.
  Verify the runner prints the intended one-site config and `Running 1 test`.
- [ ] If the runner prints `sites.local.json` or starts all sites, abort the run
  instead of letting a broad suite continue.
- [ ] Confirm document status is `200`, title includes `StarterEnglish.com`, and
  H1 is `Lessons`.
- [ ] Confirm the wordset page renders the utility bar, search control, category
  cards, and category mode buttons.
- [ ] Exercise wordset search with a normal probe and a no-match probe; the
  no-match state should hide cards, and clearing the search should restore the
  original card count.
- [ ] Open one public quiz popup from a category mode button, verify the mode
  switcher appears, then close it.
- [ ] Verify `ll-tools-flashcard-open` and `ll-tools-quiz-guard-active` are
  removed from `body` after closing the popup.
- [ ] Review console errors, page errors, same-origin `5xx`, and unexpected
  same-origin non-GET requests in the Playwright summary attachment.

## Authenticated Front-End Checks

- [ ] Use Chrome saved login through
  `https://starterenglish.com/?ll_tools_auth=login#ll-tools-auth-window`.
- [ ] Do not inspect saved passwords, cookies, local storage, or browser profile
  data.
- [ ] Confirm the user menu changes from `Guest` to the expected signed-in
  state.
- [ ] Open `/lessons/progress/?ll_wordset_back=https://starterenglish.com/` and
  confirm the Progress page renders KPI buttons, tabs, filters, and word rows
  without visible loading shells stuck on screen.
- [ ] Open `/lessons/games/?ll_wordset_back=https://starterenglish.com/` and
  confirm the Games page renders game cards and launch buttons. Do not launch a
  game unless the test explicitly includes progress-write behavior.
- [ ] Open `/lessons/settings/?ll_wordset_back=https://starterenglish.com/` and
  confirm the expected account/role-specific tool cards render. Do not submit
  forms.
- [ ] Check browser console errors after the authenticated pass.

## Admin Dashboard Checks

- [ ] Try only the known dashboard/login route. Do not brute-force hidden admin
  URLs.
- [ ] If `/wp-admin/` or `/wp-login.php` routes to the public site or 404, stop
  the admin portion and record the access blocker.
- [ ] If an admin dashboard is available, inspect read-only pages only:
  Dashboard, Plugins, Updates, and the LL Tools dashboard/status surface.
- [ ] Confirm the live LL Tools plugin version and compare it to the local
  checkout version.
- [ ] Do not update plugins, purge caches, upload files, import/export bundles,
  record audio, or submit admin forms as part of this smoke pass.

## Evidence To Save

- [ ] Server-health summaries before, after public smoke, before admin/auth, and
  at closeout.
- [ ] Live smoke command output.
- [ ] Starter English Playwright summary attachment from
  `tests/e2e/test-results/live-smoke/`.
- [ ] Any exact request/action names that caused live-smoke failures.
- [ ] A short issue list separating plugin/tooling defects from live-site/server
  configuration items.
