# Starter English Live Issues Todo

Created from the 2026-06-29 first live-test pass.
Expanded after the 2026-06-29 deeper live pass.

## High Priority

- [ ] Fix live category quiz launch for public wordset mode buttons. In the
  deeper pass, Animals 1 Practice and Learning opened the quiz shell but stayed
  at `Loading quiz...` with no prompt or answer buttons after 12 seconds.
  A real Chrome click on Animals 1 Practice also triggered a JavaScript alert
  and left the quiz popup closed. The direct
  `ll_get_words_by_category` backend probe for Animals 1 returned `200` with
  word records, so the first suspect is front-end runtime/rendering or stale
  deployed assets rather than missing category data.
- [ ] Deploy or reconcile the live LL Tools asset set, then purge cache and
  retest the quiz launch. Live
  `wp-content/plugins/language-learner-tools-main/js/wordset-pages.js?ver=1782676633`
  was `713670` bytes with a `Sun, 28 Jun 2026 19:57:13 GMT` last-modified
  header, while the current `dev` checkout has `js/wordset-pages.js` at
  `713146` bytes and a newer 2026-06-29 timestamp. Do not spend much time on
  live-only JavaScript debugging until this drift is resolved.
- [ ] Strengthen the live smoke assertion for category modes. The smoke should
  fail when the popup shell appears but never renders a prompt, answer buttons,
  or a deliberate empty-state message. A mode switcher alone is not enough.

## Plugin Or Test Tooling

- [ ] Add a safer one-site live-smoke path, such as a `--site
  starterenglish-home` option or a runner guard that fails fast when a requested
  local site list is not the one being used. In the first pass, a PowerShell
  `LL_LIVE_SITES_FILE` override did not isolate the run, so all five local live
  entries ran once.
- [ ] Update the live-smoke same-origin non-GET allowlist or per-site config for
  read-style wordset requests observed on Starter English:
  `ll_tools_wordset_page_category_search`,
  `ll_tools_wordset_page_lazy_cards`,
  `ll_get_words_by_category`, and `ll_tools_get_vocab_lesson_grid`.
- [ ] Decide how live smoke should treat Cloudflare browser RUM POSTs to
  `/cdn-cgi/rum`. They are same-origin non-GET requests and currently fail a
  strict zero-unexpected-POST policy even though they are not LL Tools writes.
- [ ] Consider adding a lightweight public smoke mode that records unexpected
  non-GET requests as warnings first, then fails only for unknown WordPress
  write actions or same-origin server errors.
- [ ] Add a current Starter English-only runbook example with a command that
  works reliably from PowerShell/Windows and prints the resolved site list before
  Playwright starts.
- [ ] Add a small live asset-drift check to the runbook or smoke tooling for
  `js/wordset-pages.js`, `js/flashcard-widget/main.js`, and
  `css/wordset-pages.css`.

## Live Site Or Server Configuration

- [ ] Investigate Cloudflare RUM on `starterenglish.com`. The first pass saw a
  `/cdn-cgi/rum` `404` with `location: "https:://starterenglish.com/"`; the
  deeper pass saw additional same-origin `/cdn-cgi/rum` `404` responses and
  aborted RUM requests. Either fix the Cloudflare/RUM setup or explicitly
  exclude these beacons from application write-safety checks.
- [ ] Confirm the intended production admin access route. Both `/wp-admin/` and
  `/wp-login.php` resolved to the public site/404 in this pass, even after the
  Chrome saved-login flow signed in as `mike`.
- [ ] Provide or document an admin-capable saved Chrome login if future live
  tests are expected to verify Dashboard, Plugins, Updates, or live LL Tools
  plugin version through wp-admin. The available saved-login session appears to
  be a learner/front-end account, not an admin session.
- [ ] Confirm whether the REST automation status route should be usable from
  the browser session. `GET /wp-json/ll-tools/v1/automation/status` returned
  `401` after the front-end saved login, so plugin version/deploy status could
  not be verified that way.
- [ ] Keep using a health gate before repeating live tests. The deeper pass
  initially hit `CRITICAL` because another PHP-FPM pool was saturated, then
  continued only after the report dropped to `WARN`; closeout was still `WARN`
  with `87` recent PHP-FPM `max_children` log entries, `15` total workers, and
  `starterenglish_com` at `1/10` workers.
- [ ] Monitor the public REST index size. `/wp-json/` returned `200` but was
  about `594 KB`; this is not a confirmed bug, but it is worth watching if
  Starter English keeps adding public REST routes.

## Current Pass Results To Preserve

- [ ] Public homepage responded `200`; title was `StarterEnglish.com`; H1 was
  `Lessons`; desktop showed 21 category cards and 72 category mode buttons.
- [ ] Public wordset search worked: `Animals` narrowed the visible card set,
  the no-match probe reached 0 visible cards with the expected no-match message,
  and clearing search restored the desktop card count.
- [ ] Representative public lesson pages returned `200` and rendered expected
  content: Animals 1, City 1, and Numbers 1-20. No same-origin `5xx` responses
  were observed during the deeper pass.
- [ ] Public crawler exports returned `200`: `/llms.txt`,
  `/ll-tools/index.md`, and `/ll-tools/index.jsonld`.
- [ ] Direct Animals 1 `ll_get_words_by_category` probe returned `200` with
  word records, supporting the conclusion that the stuck public quiz is not
  simply missing backend data.
- [ ] Logged-in front-end Progress page rendered KPI counts, tabs, filters, and
  word rows with no stuck visible loading shells. The status filter expanded and
  showed counts without a stuck busy state.
- [ ] Logged-in front-end Games page rendered Space Shooter, Bubble Pop,
  Unscramble, Speaking Practice, and Word Stack launch cards.
- [ ] Logged-in front-end Settings page rendered the learner Study tool card.
- [ ] Chrome-captured console errors were empty during the logged-in Progress,
  Games, and Settings pass.
