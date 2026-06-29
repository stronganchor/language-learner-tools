# Starter English Live Issues Todo

Created from the 2026-06-29 first live-test pass.

## Plugin Or Test Tooling

- [ ] Add a safer one-site live-smoke path, such as a `--site
  starterenglish-home` option or a runner guard that fails fast when a requested
  local site list is not the one being used. In this pass, a PowerShell
  `LL_LIVE_SITES_FILE` override did not isolate the run, so all five local live
  entries ran once.
- [ ] Update the live-smoke same-origin non-GET allowlist or per-site config for
  the read-style wordset search request:
  `ll_tools_wordset_page_category_search`.
- [ ] Decide how live smoke should treat Cloudflare browser RUM POSTs to
  `/cdn-cgi/rum`. They are same-origin non-GET requests and currently fail a
  strict zero-unexpected-POST policy even though they are not LL Tools writes.
- [ ] Consider adding a lightweight public smoke mode that records unexpected
  non-GET requests as warnings first, then fails only for unknown WordPress
  write actions or same-origin server errors.
- [ ] Add a current Starter English-only runbook example with a command that
  works reliably from PowerShell/Windows and prints the resolved site list before
  Playwright starts.

## Live Site Or Server Configuration

- [ ] Investigate Cloudflare RUM on `starterenglish.com`: the trace showed two
  `/cdn-cgi/rum` POSTs, one `204` and one `404`, and the `404` payload contained
  `location: "https:://starterenglish.com/"`.
- [ ] Confirm the intended production admin access route. Both `/wp-admin/` and
  `/wp-login.php` resolved to the public site/404 in this pass, even after the
  Chrome saved-login flow signed in as `mike`.
- [ ] Provide or document an admin-capable saved Chrome login if future live
  tests are expected to verify Dashboard, Plugins, Updates, or live LL Tools
  plugin version through wp-admin.
- [ ] Confirm whether the REST automation status route should be usable from
  the browser session. `GET /wp-json/ll-tools/v1/automation/status` returned
  `401` after the front-end saved login, so plugin version/deploy status could
  not be verified that way.
- [ ] Keep an eye on the shared server-health report before repeating live
  tests. This pass stayed `WARN` because of recent PHP-FPM `max_children` log
  entries, with current `starterenglish_com` pressure low at closeout.

## Current Pass Results To Preserve

- [ ] Public homepage responded `200`; title was `StarterEnglish.com`; H1 was
  `Lessons`.
- [ ] Public wordset shell rendered 21 category cards and 72 category mode
  buttons.
- [ ] Public wordset search no-match probe reached 0 visible cards and clearing
  restored 21 visible cards.
- [ ] Public quiz popup opened and closed; `ll-tools-flashcard-open` and
  `ll-tools-quiz-guard-active` were removed from `body` after close.
- [ ] Logged-in front-end Progress page rendered KPI counts, tabs, filters, and
  word rows with no stuck visible loading shells.
- [ ] Logged-in front-end Games page rendered Space Shooter, Bubble Pop,
  Unscramble, Speaking Practice, and Word Stack launch cards.
- [ ] Logged-in front-end Settings page rendered the learner Study tool card.
- [ ] Chrome-captured console errors were empty during the logged-in front-end
  pass.
