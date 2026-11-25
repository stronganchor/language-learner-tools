# Disposable real Moodle interoperability check

This harness installs the official Moodle 5.1.7 release, WordPress 6.8.3 and the
committed LL Tools release candidate (`HEAD`). It uses PHP 8.3, MariaDB 10.11 and Alpine Chromium
through the repository's Playwright dependency. No Moodle JWT, token endpoint,
score endpoint or WordPress account session is mocked.

Moodle runs at `https://moodle.lti-interop.example.org`; Wordboat runs at
`https://wordboat.lti-interop.example.net`. These are different sites, with
project-local Docker DNS on the reserved documentation subnet 198.51.100.0/24.
HTTPS uses port 443 and a disposable local CA trusted by the isolated PHP
clients and browser NSS store. TLS verification, browser certificate validation,
launch browser binding and plugin endpoint restrictions stay enabled. Nothing
is added to the host's hosts file, routing table or certificate store. No host
ports are published. Production and Local site databases are not used. Runtime
source and Playwright dependencies are copied to native container storage;
Windows bind-mounted dependency trees made startup unnecessarily slow. The
plugin snapshot is the complete production `git archive`, including required
vendor assets. A partial snapshot omitted the update checker and caused an
admin-post bootstrap failure; the complete verified package fixed that fixture.

## Reproduce

Install/start Docker Desktop, have Git and Node/npm available, and install the
repository's `tests/e2e` dependencies with `npm ci` in that directory. PHP and
the database run entirely inside this harness's containers.
From the plugin checkout on Windows:

```powershell
./tests/interop/moodle/run.ps1 -Reset
```

`-Reset` explicitly removes only this harness's fixed Docker Compose project,
`ll-tools-real-moodle-20261125`, including its generated database/private/data
volumes. Before removal it archives every existing top-level evidence file to
`_codex_temp/moodle-real-20261125/archive-<timestamp>/`, then clears the current
outcomes. A failed new run therefore cannot inherit an old successful grade
report. Without `-Reset`, a previous fixture is rejected. A fresh run is required
because answering an assignment intentionally consumes its attempts.

The script builds the two images, waits for prepared configuration/database
access, installs Moodle/WordPress, seeds dummy users and five text vocabulary
items, and registers the platform using JSON emitted by Moodle's own
`serialise_tool_type()` through the Wordboat platform-details parser. Only the
six public platform fields are retained; administrator edit URLs/session keys
are excluded.

The browser logs a local teacher into both sites and configures two activities
from their verified instructor launches. Those activities contain no custom
parameter. The teacher chooses a category and practice/graded behavior through
the actual setup form. A separate student browser session then enters Moodle's
normal activity `view.php` page, follows its popup/new-window launch, explicitly
connects an ordinary Wordboat account, repeats the launch and answers the fixed
assignment. The local delivery worker calls real Moodle token/score services.
Finally, native Moodle grade records and the learner's gradebook page are read.

Generated account passwords and tool secrets exist only in the private Docker
volume and disposable server configuration. Do not inspect/dump that volume,
capture full HTTP bodies/headers, save cookies, or publish raw access logs.
Evidence contains only paths/statuses, dummy local IDs and redacted outcomes;
launch query strings and credentials are not recorded. Screenshots and runtime
files stay under ignored `_codex_temp/`, outside source and release packages.

## Observations recorded on 2026-11-25

Final existing-account/activity smoke passed on **LL Tools 6.7.89**, tested
commit `ac176c4d2dd29042c76b4b407b05079ee9b26d08`. The verified production ZIP
SHA256 was `44f25c2bba4a4923eaf590df19bf2ba92b1de3b605d0acf51c49ee8f4014636a`.
Moodle was **5.1.7 (Build: 20260914)** and WordPress **6.8.3**. Three real Moodle
popup launches, the same permanent student account, existing selected grade,
native gradebook record and learner gradebook page all passed after upgrading
the isolated plugin. One identity, five word-progress rows and twelve events
remained; the selected grade was 5/5 questions, scaled to 100/100, with a
successful HTTP 200 grade delivery. The earlier full authoring/answer flow and
the final upgrade smoke are separate checks.

The initial manual-registration run against actual Moodle passed first signed
launch, explicit learner linking, repeat practice launch and a five-answer
assignment. It produced one durable Moodle identity, class membership, five
word-progress rows and ten progress events. The worker delivered one selected
grade successfully; Moodle stored raw/final grade 100 of 100. That initial run
used same-site subdomains and is archived separately; it does not establish
cross-site cookie behavior.

The cross-site guided run passed teacher configuration of practice and graded
activities without custom parameters, actual Moodle `view.php` popup launches,
explicit first learner linking, automatic repeat launches and completion of all
five graded answers. Moodle stored 100/100 through its real AGS token/score
endpoints, and the student gradebook rendered the result. One ordinary practice
answer and the normal progress-queue flush increased progress events from 10 to
12, while retaining the same five word rows and permanent account. The five
published fixture recordings are generated non-speech WAV tones; text questions
are used, and no pronunciation claim is made.

Real admin-post authoring exposed a product regression: manager-only admin term
filters hid a teacher's otherwise valid wordset relationships during assignment
creation and validation. The integration now uses bounded, exact, verified
term-taxonomy relationships for those operations; the managed-wordset listing
remains scoped. After repair both guided activity saves and grade return passed.

Moodle's actual gradebook service probe supplies both an individual line item
and score scope to an instructor and to a student for the graded activity.
The cross-site guided run and its final outcomes are recorded separately in the
current ignored evidence directory. A fresh full run writes `browser-checks.json`;
the final candidate reuse check writes `final-smoke-check.json`. Also check
`wordpress-verification.json`, `moodle-gradebook.json` and
`gradebook-browser-check.json`; all required checks must be successful before
claiming acceptance. `practice-progress-before.json` and
`practice-progress-after.json` prove the separate practice update. `verify.php`
exits nonzero if the durable link, membership, grade, progress or successful
delivery is missing. Opening practice alone does not prove an answered prompt.
`verified-runtime-sha256.json` and `source-provenance.json` identify the installed
runtime. The scoped Docker project was removed with its generated private/data
volumes after verification; redacted evidence remains in the ignored directory.

This is representative local interoperability, not acceptance on Michael's
Moodle, a mobile-browser matrix, iframe support or LTI certification. His exact
Moodle version, HTTPS/cache behavior, registration configuration, ready Hebrew
content and student browsers still require a small real-site pilot.

## Stop and cleanup

To pause the local servers while retaining this disposable state:

```powershell
docker compose -f tests/interop/moodle/compose.yml stop
```

To remove this harness's containers, generated accounts, databases and private
keys after preserving the redacted evidence:

```powershell
docker compose -f tests/interop/moodle/compose.yml down --volumes --remove-orphans
```

Do not use broad Docker prune commands. The CA expires after seven days; the boot
script regenerates an expired/mismatched certificate, while a full `-Reset`
always creates a fresh CA and fixture. After changing web-image configuration,
use a fresh run rather than reconnecting an old database to new encryption keys.

Official source references: [Moodle 5.1.7 source](https://github.com/moodle/moodle/tree/v5.1.7),
[Moodle's Docker environment](https://github.com/moodlehq/moodle-docker), and
[Moodle 5.1 server requirements](https://moodledev.io/general/releases/5.1).
