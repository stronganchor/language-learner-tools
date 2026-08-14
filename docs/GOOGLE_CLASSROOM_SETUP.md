# Google Classroom connector setup

## Current scope

The initial connector provides a secure teacher OAuth connection and a
read-only list of the teacher's active Google Classroom courses. It is the
configuration and identity foundation for a CourseWork API connector.

It does **not** publish Wordboat Practice results or grades. The existing
Practice result is browser-reported formative data. Classroom writes remain
disabled until a Google course-work item and student submission are explicitly
mapped to a finalized, server-scored Wordboat assignment attempt.

No Google OAuth credential, refresh token, or encryption key belongs in this
repository or in a WordPress option.

## Google Cloud preparation

1. Create or select an organization-owned Google Cloud project.
2. Enable the Google Classroom API.
3. Configure the OAuth consent screen and its test users or publishing status.
4. Create a **Web application** OAuth client.
5. In WordPress, open **LL Tools > Google Classroom** and copy the exact
   callback URL shown there into the OAuth client's authorized redirect URIs.
   The scheme, host, path, query, and trailing slash must match exactly.
6. Keep the OAuth client ID and secret in deployment configuration. Do not
   paste them into a post, page, plugin setting, database migration, or support
   ticket.

The connector requests OpenID identity scopes plus:

- `https://www.googleapis.com/auth/classroom.courses.readonly`

The broader `classroom.coursework.students` permission is deliberately not
requested by the connection-only screen. It belongs in a later contextual
step-up flow only when an instructor explicitly maps a server-scored assignment
for grade delivery. Review Google's current OAuth verification and school
administrator requirements before moving an app beyond a controlled test
tenant.

Official references:

- [Google Classroom integration paths](https://developers.google.com/workspace/classroom/guides/integration-paths/integration-paths)
- [OAuth 2.0 for web-server applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- [OAuth security best practices](https://developers.google.com/identity/protocols/oauth2/resources/best-practices)
- [List courses](https://developers.google.com/workspace/classroom/reference/rest/v1/courses/list)
- [Create CourseWork](https://developers.google.com/workspace/classroom/reference/rest/v1/courses.courseWork/create)
- [Manage grades](https://developers.google.com/workspace/classroom/guides/classroom-api/manage-grades)

## WordPress deployment configuration

Define the OAuth client values and a dedicated 32-byte credential-encryption
key in deployment-controlled `wp-config.php` code or an environment-backed
configuration include:

```php
define('LL_TOOLS_GOOGLE_CLASSROOM_CLIENT_ID', 'YOUR_WEB_CLIENT_ID');
define('LL_TOOLS_GOOGLE_CLASSROOM_CLIENT_SECRET', 'YOUR_WEB_CLIENT_SECRET');
define('LL_TOOLS_LMS_CREDENTIAL_KEY', 'base64:YOUR_32_BYTE_BASE64_KEY');
```

Generate `LL_TOOLS_LMS_CREDENTIAL_KEY` with a cryptographically secure random
source. Store it in the same protected secret-management boundary as database
credentials. Do not use `AUTH_KEY`, another WordPress salt, the OAuth client
secret, or an ordinary password as this key.

The credential envelope supports either a `base64:` value containing exactly
32 decoded bytes, 64 hexadecimal characters, or exactly 32 raw bytes. Sodium
authenticated encryption is preferred when available; authenticated
AES-256-GCM is the supported fallback.

Key loss makes saved Google refresh credentials unreadable. Key rotation needs
an explicit decrypt-and-re-encrypt migration; changing the value in place is
not a rotation procedure.

Do not define this production-write gate during the connection-only phase:

```php
// Intentionally absent until authoritative mappings and sandbox tests pass.
// define('LL_TOOLS_GOOGLE_CLASSROOM_WRITES_READY', true);
```

The gate is an additional fail-closed assertion, not sufficient authorization
by itself. The runtime also requires a registered, explicitly certified Google
grade-delivery adapter, so setting the constant alone cannot activate writes.
A Classroom sender must also use a finalized server-authoritative grade, a
revision-pinned destination, an exact learner/submission mapping, and the
ordered delivery worker.

## Connection acceptance check

1. Sign in to WordPress as an administrator or teacher who can manage classes.
2. Open **LL Tools > Google Classroom**.
3. Confirm that the page reports the credential store and OAuth client as
   configured and shows the same HTTPS callback URL registered in Google Cloud.
4. Choose **Connect Google Classroom**, approve the requested scopes in the
   controlled test account, and return to WordPress.
5. Confirm the page shows a connected account without displaying a refresh or
   access token.
6. If the teacher connects more than one Google account, confirm each account
   appears separately and that course loading and disconnect actions remain
   bound to the selected owned connection. A teacher can retain at most 20
   connected accounts, and at most five live authorization attempts may be
   pending at once; both ceilings are enforced server-side.
7. Load active courses and confirm the bounded list contains only courses for
   the selected connected teacher.
8. Disconnect and verify that only that account's local encrypted credential is
   removed. The teacher should also revoke the application's grant in their
   Google Account when fully removing access.

WordPress privacy export includes the connected profile's email, display name,
Workspace hosted-domain value, verification state, scopes, and connection
timestamps, plus safe count/time metadata for short-lived authorization-state
records. It never exports an OAuth state, PKCE verifier, refresh token,
short-lived access token, or raw Google subject. Expired state envelopes are
removed in bounded hourly batches. Normal WordPress account deletion and
privacy erasure remove the local encrypted connection without sending a grade
or deletion to Google.

The automated tests mock all Google HTTP traffic. Never put a real client
secret, authorization code, token, Google subject, teacher email, course ID, or
student record in a test fixture.

## Remaining grade-passback gate

Before Classroom grade delivery can be enabled, complete and test all of the
following in a dedicated Google Workspace for Education sandbox:

- publish an immutable Wordboat assignment revision and finalize a
  server-scored learner attempt;
- create the Google CourseWork item from the same Cloud project that will
  update its grades;
- persist a pending/unknown creation operation and reconcile it after a timeout
  instead of blindly retrying a non-idempotent CourseWork POST;
- require a whole-number Classroom maximum and normalize outbound draft grades
  deterministically to Classroom's two-decimal contract while retaining the
  canonical local value;
- pin the Wordboat revision to the exact Google course and CourseWork IDs in a
  provider-owned mapping table;
- link a local learner to the exact Google subject and student submission,
  without using an email address as the durable identity key;
- verify the expected Google user and exact submission together immediately
  before every grade PATCH and again in its response;
- queue only the selected authoritative grade revision after the local
  transaction commits;
- send `draftGrade` by default, with bounded retry, redacted diagnostics,
  stale-grade suppression, and correction-order tests;
- verify consent, token refresh/revocation, class removal, local erasure, and
  backup/restore behavior.

An embedded Classroom add-on is a later product path. It additionally requires
the Google Workspace Marketplace SDK, iframe/SSO views, eligible Education
licenses, attachment ownership rules, and Google's review process. It should
reuse the same assignment, attempt, identity, and outbox core rather than mix
the CourseWork and add-on journeys.
