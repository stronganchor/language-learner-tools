# Moodle / Wordboat pilot

This implements manual LTI 1.3 registration, persistent learner account linking,
ordinary practice, fixed vocabulary assignments and score-only Assignment and
Grade Services (AGS). It does not depend on the unfinished Google Classroom
connector. Local verification is separate from an actual Moodle acceptance test.

## Wordboat operator preparation

Deploy a tested `dev` build through the normal plugin updater. Configure an
RSA private key of at least 2048 bits, its stable public key ID, and an LMS
encryption key in protected server configuration:

Before activation, verify that the WordPress `users`, `usermeta`, `posts`,
`postmeta` and `options` tables use InnoDB, as well as the LL Tools progress and
LMS tables. MyISAM cannot support the required grouped rollback. LL Tools fails
closed; agents must not automatically convert core WordPress tables. On
2026-10-02 Wordboat's read-only preflight found `posts`, `postmeta` and `options`
on MyISAM. A separately approved, backed-up storage migration is required before
live learner enrollment; no live core table conversion occurred in this change.

```php
define('LL_TOOLS_LTI_PRIVATE_KEY', file_get_contents('/protected/path/lti-private.pem'));
define('LL_TOOLS_LTI_KEY_ID', 'wordboat-lti-20261002');
define('LL_TOOLS_LMS_CREDENTIAL_KEY', 'base64:EXACTLY_32_RANDOM_BYTES_BASE64_ENCODED');
```

These are server secrets, never Moodle form fields. Keep the private file outside
the document root with owner-only permissions. Preserve an existing LMS key:
changing it would make stored connections unreadable. This pilot does not yet
provide overlapping signing-key rotation or encryption-key migration. Back up
protected keys with the database and retain them during plugin updates.
Moodle's public keyset is cached for five minutes; a Moodle signing-key change
can pause verified launches until that cache refreshes.

Open **LL Tools > Moodle / LTI** (`admin.php?page=ll-tools-lti`). The screen lists
the exact URLs for this WordPress installation. On Wordboat these are:

| Moodle field | Value |
| --- | --- |
| Tool URL / target link URI | `https://wordboat.com/?ll_lti_resource=1` |
| Initiate login URL | `https://wordboat.com/wp-json/ll-tools/v1/lti/login` |
| Redirection URI | `https://wordboat.com/wp-json/ll-tools/v1/lti/launch` |
| Public keyset URL | `https://wordboat.com/wp-json/ll-tools/v1/lti/jwks` |

An administrator registers the Moodle **issuer/platform ID, client ID, deployment
ID, authentication URL, public keyset URL and access-token URL**. Copy these from
Moodle's configured tool details. Do not guess IDs or reuse a different site's
registration. All platform endpoints and signed grade lineitems must use HTTPS
on the exact registered Moodle origin; redirects and private-address endpoints
are rejected. The tool supports ten platform/deployment registrations.

The operator creates a normal LL Tools teacher account/class for Michael and
assigns the intended Hebrew wordset. That class fixes the wordset permission
boundary. No Moodle role becomes a WordPress administrator or content manager.

New learner signup follows the normal Wordboat registration policy: both the
LL Tools `ll_allow_learner_self_registration` setting and WordPress
`users_can_register` must be enabled. Otherwise, pre-create ordinary LL Tools
learner accounts and have students sign in to connect them on the first launch.
The Moodle connection does not bypass a site's registration settings.

## Michael's Moodle setup

1. Log in with permission to configure external tools. In current Moodle this
   is normally **Site administration > Plugins > Activity modules > External
   tool > Manage tools**, or the corresponding course-level tool configuration.
2. Add a manually configured **LTI 1.3** tool called Wordboat. Enter the four tool
   values above; use **Keyset URL** for public-key type. Set the launch container
   to **New window**. Enable accepting grades from the tool for graded activities.
   Name and email sharing are unnecessary for account matching.
3. Give the Wordboat operator Moodle's platform details from the created tool:
   issuer, client ID, deployment ID and the three platform endpoint URLs. These
   identifiers/URLs are not account passwords.
4. Create one **External tool** activity in a test course, using the registered
   Wordboat tool. The Wordboat activity configuration produces one custom
   parameter, `ll_activity=<opaque activity ID>`; copy that exact parameter into
   this activity's custom parameters. Use a separate parameter for each activity.
5. For graded activities, enable grade return and a maximum grade; the initial
   pilot uses 100. Set Moodle completion conditions deliberately. Practice saves
   Wordboat progress but does not return a Moodle grade or automatic completion.

## Wordboat activity setup

The class owner opens **LL Tools > Moodle / LTI**, selects the native class,
registered platform, exact Moodle course context ID and one lesson category.
Choose **Practice** or **Graded assignment**. A graded activity can create and
publish a fixed assignment snapshot with attempt limit and first/latest/best
grade policy. It currently supports ordinary vocabulary categories with 5–15
usable words and the category's configured text/image/audio prompt and choices.
Prompt cards, free response and speech grading are outside this first pilot.

Moodle 5.1's ordinary course launch uses the course ID as `context.id` (verified
in [Moodle's LTI source](https://github.com/moodle/moodle/blob/MOODLE_501_STABLE/public/mod/lti/locallib.php#L766)).
Michael's inspected Level 1 URL has course ID 2 and Level 2 has course ID 4;
confirm the signed launch details on his actual Moodle version before enrollment.
The Moodle resource-link ID may initially be blank in Wordboat; the first admitted
verified launch pins it, preventing reuse of this configuration in another activity.

First learner launch offers ordinary sign-in/registration and explicit account
connection. Subsequent verified launches reuse that Wordboat account. Email is
never used to merge identities. A different currently signed-in account blocks
the launch rather than silently switching users. Staff accounts cannot be linked
as student accounts. Learners can disconnect through `/?ll_lti_connections=1`;
an administrator must review reconnection. Removing a learner from the native
Wordboat class blocks later automatic readmission to that class.

Class activities update the same per-word progress as independent practice.
The existing Wordboat class report can therefore show personal practice within
that class's wordset. AGS receives only the selected fixed-assignment grade.
Account deletion/privacy erasure removes local mappings and progress without
issuing remote deletion or grade changes in Moodle.

## Acceptance before student rollout

Use a separate real Moodle student test account and one ready Hebrew lesson:

- Launch in a new window; create/link a Wordboat learner account; return from
  Moodle again and confirm the same identity and progress.
- Finish practice and check Wordboat progress; personal practice outside Moodle
  should update that same account.
- Finish a fixed assignment and verify its score in Moodle's actual gradebook.
  Repeat/reload an allowed attempt and confirm the configured grade policy.
- Check Moodle completion rules, class access and a removed student's blocked
  launch. Check the chosen learner browser on desktop/mobile.
- Confirm a scheduled WordPress cron mechanism drains grade deliveries. A
  network failure must leave a retryable delivery, not a lost grade. Use existing
  provider-neutral delivery diagnostics without logging JWTs or access tokens.

Automatic tool registration, Deep Linking, Moodle roster synchronization (NRPS),
iframe cookie/storage recovery and LTI certification are not included. Keep
Michael's current course content; replace only a small pilot set of quiz links
after successful acceptance. The Bibleling quizzes do not become Wordboat
activities automatically; matching Wordboat lesson content must exist first.

## Code and verification map

`includes/lms/lti.php` owns signed launch/service transport and encrypted provider
maps; `lti-accounts.php` owns account confirmation and course access;
`assignment-experience.php` owns fixed category snapshots, player presentation
and atomic per-word answer projection. `includes/admin/lti-integration.php` owns
manual setup. JWT provenance/rebuild instructions are in
`dependencies/lti/README.md`. Focused PHP tests are `LtiFoundationTest`,
`LtiAccountsTest`, `LmsAssignmentExperienceTest`, plus existing LMS/privacy suites.
Real WordPress browser tests cover the player and account continuation. Set
`LL_E2E_WP_ROOT` for an isolated WordPress site; do not point fixture writes at a
public site or an unrelated developer checkout.
