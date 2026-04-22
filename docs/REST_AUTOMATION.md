# LL Tools REST Automation

Language Learner Tools now exposes a small REST surface for the same automation
workflows that were recently added to WP-CLI.

This is the better fit when you want to keep the current workflow of giving
Codex a temporary WordPress admin or manager account instead of SSH access to
the server.

## Recommended workflow

For a normal Codex session against a live LL Tools site:

1. Create a temporary WordPress user.
2. Prefer `administrator` when Codex needs to create wordsets, change sitewide
   settings, or work across multiple wordsets.
3. Use `wordset_manager` when Codex only needs to work inside one assigned
   wordset.
4. Assign the manager to the correct wordset before starting the session.
5. Give Codex the site URL plus the temporary username and password.
6. First call `GET /automation/status` to confirm authentication and capability
   scope.
7. Then call `GET /wordsets/{wordset}/report` to confirm the exact target
   wordset and current coverage.
8. Use `GET /wordsets/{wordset}/missing-meta` to discover the current backlog.
9. Use `POST /wordsets/{wordset}/bulk-update` with `dry_run=true` before any
   write operation.
10. Re-run the same request without `dry_run` to apply changes.
11. Delete or downgrade the temporary user when the session is complete.

This sequence keeps the workflow close to how Codex already operates in
wp-admin, but removes nonce scraping and form replay.

## Authentication

The LL Tools automation routes support three auth paths:

- Logged-in WordPress browser session plus REST nonce.
- WordPress Application Passwords.
- LL Tools password-based Basic auth for the `ll-tools/v1` namespace.

The password-based Basic auth path is designed for temporary WordPress users.
It uses the site's normal WordPress username and password directly, so Codex
can authenticate without creating an application password first.

Security notes:

- Use HTTPS on live sites.
- The plugin blocks password-based Basic auth on non-HTTPS requests unless the
  request is clearly local development.
- Prefer temporary users and remove them when the automation session is done.

## Local WSL helper

On this machine, the Local site listener can be reachable from Windows but not
from WSL's Linux networking stack. When Codex is running in WSL, use the helper
wrapper instead of Linux `curl`:

```bash
bash bin/ll-rest-local.sh /wp-json/ll-tools/v1/automation/status -u codex-temp:YOUR_PASSWORD
```

The wrapper resolves the Local site URL with `wp option get home` and sends the
request through Windows `curl.exe`, which can reach the Local listener reliably.

Example:

```bash
bash bin/ll-rest-local.sh /wp-json/ll-tools/v1/wordsets/english/report \
  -u codex-temp:YOUR_PASSWORD
```

## Endpoints

Base namespace:

```text
/wp-json/ll-tools/v1
```

Routes:

- `GET /automation/status`
- `POST /wordsets`
- `GET /wordsets/{wordset}/missing-meta`
- `POST /wordsets/{wordset}/bulk-update`
- `GET /wordsets/{wordset}/report`

`{wordset}` can be a stable wordset slug such as `spanish` or `genc-palu`.

## Basic auth examples

Check auth and route availability:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  https://example.com/wp-json/ll-tools/v1/automation/status
```

Dump a live report:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/report
```

List words that are still missing metadata:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  "https://example.com/wp-json/ll-tools/v1/wordsets/spanish/missing-meta?fields=part_of_speech,grammatical_gender"
```

Dry-run a bulk update:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/bulk-update \
  -d '{
    "category": "household-items",
    "where_missing": ["grammatical_gender"],
    "set": { "field": "grammatical_gender", "value": "feminine" },
    "dry_run": true
  }'
```

Apply the same update for real:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/bulk-update \
  -d '{
    "category": "household-items",
    "where_missing": ["grammatical_gender"],
    "set": { "field": "grammatical_gender", "value": "feminine" }
  }'
```

Create a wordset from a template:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets \
  -d '{
    "name": "Spanish Travel",
    "slug": "spanish-travel",
    "template": "travel-template",
    "manager": "codex-temp"
  }'
```

Dry-run a part-of-speech backfill for only two words:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/bulk-update \
  -d '{
    "set": { "field": "part_of_speech", "value": "noun" },
    "where_missing": ["part_of_speech"],
    "dry_run": true,
    "limit": 2
  }'
```

## Route behavior

### `GET /automation/status`

Returns the authenticated user, auth mode, LL Tools capability checks, and the
available automation routes. Use this as the first smoke test on a live site.

### `POST /wordsets`

Creates a blank wordset or clones one from a template.

Body fields:

- `name` required
- `slug` optional
- `template` optional wordset slug, name, or ID
- `manager` optional user login, email, or ID

Use this route when Codex needs to create a fresh working copy from a reusable
template without logging into wp-admin taxonomy screens first.

### `GET /wordsets/{wordset}/missing-meta`

Returns the machine-readable missing-metadata report.

Query params:

- `category` optional category slug or name
- `fields` optional comma-separated list or repeated array

Use this route before a metadata-editing session so Codex can see the exact
remaining rows instead of inferring gaps from the UI.

### `POST /wordsets/{wordset}/bulk-update`

Applies a partial metadata update without resending whole word rows.

Body fields:

- `set` required
  - Either `"field=value"` or `{ "field": "...", "value": "..." }`
- `category` optional
- `word` optional
- `where_missing` optional list or comma-separated string
- `where_pos` optional part-of-speech slug
- `limit` optional
- `offset` optional
- `dry_run` optional
- `resume_state` optional object returned by a prior run

Supported update fields:

- `word_translation`
- `word_note`
- `dictionary_entry_title`
- `part_of_speech`
- `grammatical_gender`
- `grammatical_plurality`
- `verb_tense`
- `verb_mood`

`resume_state` is the REST equivalent of the CLI `--resume-file` feature. Send
back the response's `resume_state` object on the next request to skip words that
were already processed successfully.

Typical uses:

- backfilling `part_of_speech`
- backfilling `grammatical_gender`
- attaching `dictionary_entry_title`
- updating `word_note` without resending the entire word row

### `GET /wordsets/{wordset}/report`

Returns the full machine-readable wordset report with settings, coverage, and
per-category counts.

Use this route at the start and end of a session to confirm the target scope and
to capture a before/after snapshot for later auditing.

## Permissions

The routes still respect LL Tools permissions:

- Any automation caller must pass the `view_ll_tools` gate.
- Wordset-scoped routes also require access to manage that specific wordset.
- Wordset creation requires `edit_wordsets`.

That means a `wordset_manager` can use these routes for their assigned wordset
but not for unrelated wordsets.

## Quick decision guide

Use REST automation when:

- Codex only has WordPress credentials, not SSH
- you want to preserve the existing temp-user workflow
- the task is wordset creation, metadata cleanup, or reporting

Use WP-CLI instead when:

- Codex has shell access to the server
- the task needs local filesystem operations or direct command chaining
- you are running large maintenance flows entirely inside a trusted server shell

## Common failure cases

- `401` on `/automation/status`
  - The username/password are wrong, or the request is not sending Basic auth.
- `403` with a wordset manager account
  - The user is authenticated but is not assigned to that specific wordset.
- `403` for password-based auth on a live site
  - The request is probably plain HTTP instead of HTTPS.
- Local WSL `curl` connection failures
  - Use `bash bin/ll-rest-local.sh ...` instead of Linux `curl`.
