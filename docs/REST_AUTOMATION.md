# LL Tools REST Automation

Language Learner Tools now exposes a small REST surface for the same automation
workflows that were recently added to WP-CLI.

This is the better fit when you want to keep the current workflow of giving
Codex a temporary WordPress admin or manager account instead of SSH access to
the server.

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

### `GET /wordsets/{wordset}/missing-meta`

Returns the machine-readable missing-metadata report.

Query params:

- `category` optional category slug or name
- `fields` optional comma-separated list or repeated array

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

### `GET /wordsets/{wordset}/report`

Returns the full machine-readable wordset report with settings, coverage, and
per-category counts.

## Permissions

The routes still respect LL Tools permissions:

- Any automation caller must pass the `view_ll_tools` gate.
- Wordset-scoped routes also require access to manage that specific wordset.
- Wordset creation requires `edit_wordsets`.

That means a `wordset_manager` can use these routes for their assigned wordset
but not for unrelated wordsets.
