# Teacher Quiz Results and LMS Integration Plan

## Purpose and status

This document separates two related product goals:

1. **Native Wordboat reporting:** retain the score already shown at the end of a
   logged-in Practice session and show it to an authorized teacher in Wordboat.
2. **LMS interoperability:** launch a defined Wordboat assignment from an LMS
   and return a server-authoritative grade to that LMS's gradebook.

Phase 1 is a native reporting feature. It is deliberately not described as LMS
integration or as a secure assessment system. Phases 2 and later provide the
path to standards-based grade passback for major LMS platforms. Compatibility
with any particular LMS is not complete until that platform's current sandbox
and production setup have been tested.

### Implementation status (source checked 2026-09-06)

- Phase 1 is implemented: bounded formative Practice results appear in the
  existing teacher Classes report.
- A Phase 2 foundation is implemented behind versioned schema gates:
  immutable bounded closed-response revisions, current-class learner attempts,
  server-derived first answers and scores, `first`/`latest`/`best` selected
  grades, cookie-authenticated native REST routes, privacy export/erasure, and
  a provider-neutral ordered delivery outbox. A complete teacher assignment
  authoring/player UI and provider mappings are still required before this is
  a finished production assessment workflow.
- A Google Classroom connection foundation is implemented: configuration-only
  OAuth secrets, one-use state plus PKCE, authenticated encryption for refresh
  credentials, fixed Google origins, scope readback, a teacher connection
  screen, and a bounded active-course list. Database-verified helpers for DRAFT
  CourseWork and `draftGrade` exist but have no UI/adapter registration and are
  disabled unless the explicit production-write gate is enabled. They do not
  accept Phase 1 results.
- LTI 1.3/AGS is not implemented or claimed. The repository still has no
  audited production JOSE/OIDC dependency, signing-key custody/rotation model,
  registration/deployment store, or LMS sandbox proof. Those are one security
  boundary, not a small transport patch.

See `docs/GOOGLE_CLASSROOM_SETUP.md` for the connector's deployment boundary
and remaining acceptance gates.

### Source and focused-test map

| Surface | Owning source | Focused coverage |
| --- | --- | --- |
| Browser-reported Practice result producer and strict event contract | `js/flashcard-widget/progress-tracker.js`, `includes/user-progress.php` | `UserProgressPracticeResultTest`, `UserProgressEventPayloadGuardTest`, `UserProgressAtomicityTest` |
| Paged class report and Practice-result display | `includes/teacher-classes.php`, `includes/user-progress-report-data.php`, `includes/admin/teacher-classes-page.php` | `TeacherClassesTest`, `UserProgressPracticeResultTest`, `teacher-classes-frontend.spec.js` |
| Assignment schema, immutable revisions, scoring and grade selection | `includes/lms/assignments.php` | `LmsAssignmentFoundationTest` |
| Native request shape, permissions and route registration | `includes/api/lms-rest.php` | `LmsRestApiTest` |
| Provider-neutral mappings, outbox, leases and scheduling | `includes/lms/grade-delivery.php` | `LmsGradeDeliveryTest` |
| Google connection and credential envelope | `includes/lms/google-classroom.php`, `includes/lms/credential-store.php`, `includes/admin/google-classroom-integration.php` | `GoogleClassroomFoundationTest`, `google-classroom-admin-ui.spec.js` |
| Export/erasure and durable account-deletion cleanup | `includes/privacy.php` plus each LMS module's privacy helpers | `LmsPrivacyLifecycleTest`, `OfflineAppSyncTest`, `MultisiteRegistrationAndMaintenanceTest` |

PHP test classes are in `tests/Integration/`; browser specs are in
`tests/e2e/specs/`. Use the wrappers and environment rules in
`tests/AI_TESTING_PLAYBOOK.md` and `tests/README.md`. These local tests do not
establish external-platform compatibility.

Schema and transaction failures are part of the domain contract. Start with
the relevant module's schema-readiness helper and `includes/lib/schema-maintenance.php`
when these APIs return unavailable errors. Finalization persists the grade and
outbox rows in one transaction. For a caller-owned transaction, the returned
`delivery_schedule_required` flag means the caller must schedule delivery only
after its own commit; releasing the nested savepoint is insufficient.

The `ll_tools_lms_assignment_create_revision()` PHP helper has no corresponding
native REST route in the current inventory. Treat teacher authoring, learner
assignment navigation/player UI, and provider mapping controls as unfinished
product work; do not infer those interfaces from the domain helpers.

### Native LMS REST route inventory

The Phase 2 foundation registers these cookie-authenticated routes under
`/wp-json/ll-tools/v1`. They are native Wordboat assignment and attempt APIs;
they are not LTI endpoints and do not by themselves provide an external LMS
launch or grade-passback integration.

| Method and route | Authorized use |
| --- | --- |
| `POST /lms/assignments` | A teacher creates a bounded assignment draft for a class they may manage. |
| `GET /lms/classes/{class_id}/assignments` | A teacher lists assignments for a class they may manage. |
| `POST /lms/assignments/{assignment_uuid}/publish` | A teacher publishes an owned assignment revision. |
| `POST /lms/assignments/{assignment_uuid}/archive` | A teacher archives an owned assignment. |
| `POST /lms/assignments/{assignment_uuid}/attempts` | A current class learner starts an allowed attempt. |
| `POST /lms/attempts/{attempt_uuid}/answers` | The attempt owner submits one bounded closed response. |
| `POST /lms/attempts/{attempt_uuid}/finalize` | The attempt owner finalizes the attempt for server-side scoring. |
| `GET /lms/attempts/{attempt_uuid}` | The attempt owner reads the bounded attempt and selected grade. |

The intended native browser contract uses logged-in WordPress REST cookie
authentication with its REST nonce, and every route has a registered permission
callback. Teacher routes enforce class-management ownership; learner routes
require the current logged-in learner and assignment/attempt eligibility. These
routes must not be documented as anonymous, custom bearer-token, or
provider-ready interfaces.

## Existing foundation

The plugin already has useful building blocks:

- `ll_teacher_class` records associate a teacher, learners, and one wordset.
- The teacher role has separate class-management and class-progress
  capabilities, and class access is ownership-checked.
- Logged-in quiz activity is accepted through a nonce-protected endpoint and
  stored as idempotent events keyed by `event_uuid`.
- `mode_session_complete` marks the end of one logical mode session. A bounded
  Practice continuation remains part of that logical session, so the final
  result has the complete denominator rather than one transport chunk's score.
- The existing Classes view already provides bounded, wordset-scoped learner
  progress reporting.
- Detailed progress events participate in WordPress personal-data export and
  erasure and have configurable retention.

The original missing contract was the score. Phase 1 now stores the bounded
canonical Practice result in the idempotent completion event. It remains
learner-reported formative data, not a server-verified grade or permanent class
assignment record.

## Phase 1: native Practice results

### Canonical event contract

For a non-empty Practice session only, the `mode_session_complete` event adds
this exact nested object under `payload.result`:

```json
{
  "schema": 1,
  "kind": "practice_first_try",
  "score_given": 8,
  "score_maximum": 10,
  "score_basis": "first_try_distinct_words"
}
```

The complete relevant payload shape is therefore:

```json
{
  "category_ids": [123, 456],
  "result": {
    "schema": 1,
    "kind": "practice_first_try",
    "score_given": 8,
    "score_maximum": 10,
    "score_basis": "first_try_distinct_words"
  }
}
```

Contract rules:

- `result` is present only when the normalized mode is `practice` and at least
  one distinct word was scored.
- `schema` is the integer `1`.
- `kind` is exactly `practice_first_try`.
- `score_given` and `score_maximum` are integers.
- `score_maximum` is positive and must not exceed
  `ll_tools_user_progress_practice_result_score_limit()`. The default is
  100,000 and the filtered value is hard-clamped to 1-1,000,000 so large
  logical sessions remain representable without accepting an unbounded client
  value.
- `score_given` is between zero and `score_maximum`, inclusive.
- `score_basis` is exactly `first_try_distinct_words`.
- `score_given` is the number of distinct words answered correctly without a
  previous wrong answer in the logical session. `score_maximum` is the number
  of distinct words scored in that session.
- Learning, Listening, Gender, Self-check, and zero-question sessions omit
  `payload.result`; they must not synthesize a zero grade.
- The server reconstructs the exact allowlisted result shape, discards extra
  nested fields, and persists it only when all required fields pass the type,
  value, and relational checks. A malformed optional result is not a trusted
  score and must not be rendered or exported as one.
- The existing completion event's `event_uuid` is the native attempt identity.
  Retried progress sync remains idempotent and must not create a second result.

The nested, versioned object keeps the event schema extensible without turning
unrelated payload properties into an implicit grade contract. Producers and
readers must branch on both `schema` and `kind`; unknown versions or kinds are
not Practice results.

### Native teacher experience

The first reporting surface should extend the existing Classes view rather
than create a parallel teacher system. For each learner on the already-paged
class roster, the initial view shows the latest Practice score and recorded
time plus a 30-day attempt count, all scoped to the selected class wordset.

Queries must remain bounded and use the existing user/wordset/time indexes.
Only the already-paged learner roster may be queried. The default report reads
two learners per UNION query, fetches at most 500 accepted rows plus one
truncation sentinel per learner, and excludes event payloads above 16 KiB in
SQL. The batch automatically shrinks when the configured scan depth would
exceed the approximately 24 MiB aggregate-query budget. A database failure is
not zero attempts or no data: it propagates as `query_failed`, and both Practice
cells render translated `Unavailable` values with empty sort keys. Do not
hydrate the complete event history or every learner in a large class in one
request.

If CSV export is added, it needs the same class ownership or administrator
capability check, a nonce, bounded/keyset batches, and spreadsheet-formula
escaping for learner-controlled cells.

Email is not the primary store. If requested later, prefer an opt-in daily or
weekly teacher digest generated from a durable, deduplicated delivery queue.
Do not make quiz completion wait for mail delivery, and do not put sensitive
learner details or scores in subject lines.

### Phase 1 limitations

Phase 1 is suitable for formative reporting, not high-stakes grading:

- The browser calculates the result. An authenticated learner can manipulate
  browser state or a request payload, so server sanitization establishes a
  well-formed value but does not prove how the learner answered.
- A public or logged-out quiz has no durable learner identity and therefore no
  teacher result.
- A Practice launch is a user-selected study session, not a fixed assignment.
  It has no frozen question set, due date, attempt limit, or grading policy.
- The event records completion of a logical session, not an LMS context,
  resource link, line item, or submission.
- Detailed events follow the configured retention window. A report that must
  be retained as an official grade needs a separate retention and correction
  policy rather than depending on the activity log.
- The stored completion time may be a bounded client-created time accepted by
  the existing progress protocol; it is not a secure started/submitted
  timeline, especially after offline synchronization.

The UI and documentation must say `Practice score`, not `verified grade`,
`assignment grade`, or `LMS grade`.

### Phase 1 acceptance criteria

- One non-empty Practice logical session produces one completion event with
  the exact `payload.result` contract above.
- Multi-chunk Practice continuation reports the cumulative result once.
- Retry/reload of the same queued event remains idempotent.
- Other modes and empty Practice sessions store no result.
- Invalid types, values, relationships, versions, kinds, and over-limit maxima
  cannot become a reportable result.
- A teacher can see only results for learners in a class they own and only for
  that class's wordset; administrators retain their documented override.
- Report and export queries remain paged and resource-bounded.
- Personal-data export, erasure, and retention tests cover the nested result.

## Phase 2: assignments and server-verifiable attempts

LMS grade passback should not publish the Phase 1 client-reported score as an
authoritative grade. First introduce a transport-independent assignment and
attempt domain that native classes and LTI can both use.

### Assignment definition

A durable assignment should have an opaque ID and a frozen revision containing
at least:

- owning site/wordset and bounded category or card scope;
- quiz presentation and scoring rules;
- maximum points;
- availability/due window, if used;
- attempt limit;
- grade selection policy: for example first, latest, or best completed attempt;
- publication/archive state.

Changing quiz content must not silently rewrite the meaning of an already
submitted grade. An attempt references the immutable assignment revision it
started from. Materializing every word in a large wordset during launch remains
out of bounds; build or page a bounded frozen assessment manifest through a
background preparation flow where necessary.

### Attempt lifecycle

The server issues an opaque, expiring attempt identity bound to the authenticated
learner, assignment revision, and launch context. It records start, answer, and
finalization state. On finalization the server validates the submitted answer
identities against the frozen manifest and computes the score itself. A client
may render immediate feedback, but it does not choose the grade written to the
attempt record.

Finalization must be transactional and idempotent. A finalized attempt has a
monotonic grade revision so the selected course grade can be recomputed under
the assignment's first/latest/best policy without an older retry replacing a
newer result.

Phase 2 should work for native Wordboat assignments before any LMS protocol is
added. That boundary keeps assessment correctness testable independently of
OIDC, JWT, and LMS-specific behavior.

## Phase 3: LTI 1.3 launch and AGS grade passback

Implement Wordboat as an LTI Tool using [LTI Core 1.3][lti-core] and the
[1EdTech Security Framework][lti-security]. Use [LTI Assignment and Grade
Services 2.0][lti-ags] (AGS) for gradebook line items and score publication.
LTI Core replaces legacy shared-secret launch signing with OpenID Connect,
signed JWTs, OAuth 2.0 service authorization, and HTTPS.

### Platform registrations

Maintain an administrator-managed registration per LMS platform/deployment,
not one global collection of loosely trusted endpoints. A registration needs:

- platform issuer;
- OAuth client ID;
- deployment ID or allowed deployment IDs;
- OIDC authentication endpoint;
- OAuth token endpoint;
- platform JWKS URL;
- tool launch/login-initiation URLs and tool JWKS URL shown to the administrator;
- enabled services/scopes and registration status;
- key identifiers, creation/rotation metadata, and a disable/revoke control.

Use a vetted JWT/OIDC implementation rather than writing cryptography in plugin
helpers. Tool private signing keys must not be public, autoloaded, logged, sent
to the browser, or stored in source control. Publish only active public keys at
the tool JWKS endpoint and support an overlap period for safe key rotation.

### OIDC launch validation

The login-initiation and launch endpoints must implement the complete LTI flow:

- bind a short-lived, high-entropy, single-use `state` and `nonce` to the
  initiating request;
- match `iss`, `client_id`, deployment, and registered redirect/target values;
- retrieve and cache the platform JWKS with bounded timeouts and safe key
  rotation behavior;
- restrict signature algorithms to the supported asymmetric LTI set, reject
  `none` and symmetric/algorithm-confusion cases, and select the expected key
  by `kid`;
- validate signature, issuer, audience/authorized party, nonce, message type,
  LTI version, deployment ID, issued-at, and expiry claims;
- reject replayed launches and tokens;
- use HTTPS for every launch and service endpoint.

Do not place ID tokens, access tokens, private keys, full launch claims, or
learner PII in URLs or routine logs. Frame embedding, cookie policy, CSP
`frame-ancestors`, and first-party session establishment require explicit tests
because LMS browsers may restrict third-party cookies. A new-window fallback is
preferable to weakening authentication.

### Identity mapping

The stable external identity key is the compound value:

```text
(platform issuer, deployment ID, LTI subject)
```

Do not use email as the primary mapping key; platforms may omit PII, and the
same email does not establish the same LTI identity. Store context-scoped roles
separately from the WordPress account role. A learner launch may create or link
a local learner identity only under an explicit site policy, with collision and
account-recovery handling. An instructor launch does not grant global WordPress
administrator privileges.

### Resource, assignment, and line-item mapping

Persist explicit mappings among:

- registration/deployment;
- LTI context ID (normally the LMS course);
- LTI resource-link ID;
- Wordboat assignment and immutable revision;
- AGS line-item URL and its maximum score;
- LTI learner user ID used by AGS.

Never infer these mappings from titles, emails, category names, or mutable URLs.
Fence every lookup by registration and deployment so two LMS tenants with the
same context or subject value cannot collide.

The smallest pilot asks the instructor/platform to create an LTI resource link
with one associated line item. When a valid launch supplies an AGS `lineitem`
and the `score` scope, Wordboat can publish to that line item without requesting
line-item management permission. Request the broader `lineitem` scope only if
Wordboat actually creates or updates gradebook columns. Treat service URLs as
untrusted input until they arrive in a verified launch and match the registered
platform policy; outbound HTTP must prevent SSRF and use bounded timeouts.

### AGS score publication

After the server finalizes an attempt and selects the assignment grade,
Wordboat obtains an OAuth 2.0 access token with a signed client assertion and
the least required AGS scope. It then posts the current grade to
`{lineitem URL}/scores` using the AGS media type and fields such as:

```json
{
  "timestamp": "2026-08-13T12:34:56Z",
  "scoreGiven": 8,
  "scoreMaximum": 10,
  "activityProgress": "Completed",
  "gradingProgress": "FullyGraded",
  "userId": "the-lti-user-id"
}
```

The LMS-provided LTI user ID, not a WordPress user ID or email address, is the
AGS `userId`. Preserve the exact line-item maximum and grade policy used by the
assignment. If Wordboat later reads LMS results, remember that AGS distinguishes
the Tool's write-only Score service from the Platform-controlled Result value.

### Durable delivery and ordering

Do not call AGS synchronously from the learner's results screen. In the same
transaction that finalizes a server-authoritative grade, enqueue a durable
grade-delivery record containing a destination mapping, grade revision, and
deduplication key. A background worker should:

- acquire work in bounded batches with an exact-owner lease;
- obtain/cache short-lived access tokens without persisting them in logs;
- honor `Retry-After` and use capped exponential backoff with jitter for
  retryable token, network, rate-limit, and server failures;
- distinguish permanent registration/scope/mapping failures from retryable
  delivery failures;
- redact tokens and sensitive response bodies from diagnostics;
- retain an administrator-visible audit state and manual retry control;
- mark success only after the LMS response satisfies the AGS contract.

Before each send, compare the queued grade revision with the assignment's
current selected grade. Supersede stale jobs, and serialize/coalesce delivery
per platform, line item, and learner. Otherwise an older job that recovers late
could overwrite a newer grade in the LMS. Delivery retries must never create a
second local attempt or block the learner's completion UI.

## Phase 4: instructor content selection with Deep Linking

Add [LTI Deep Linking 2.0][lti-dl] after the fixed-resource pilot is stable.
An instructor launch opens a bounded Wordboat assignment picker and returns an
`ltiResourceLink` tied to the selected immutable assignment. Where the platform
advertises support, the response can declare the associated line item and
maximum score.

Deep Linking must use the same registration, signing, state, deployment, role,
and redirect controls as ordinary launches. The picker must page large
wordsets/categories rather than hydrating a complete production wordset.
Copy/course-restore behavior needs tests so restored LMS links map to the
correct assignment without silently duplicating or reusing another course's
line item.

## Phase 5: optional roster synchronization with NRPS

[Names and Role Provisioning Services 2.0][lti-nrps] (NRPS) can retrieve the
membership and roles for an LMS context. It is optional and is not needed for
AGS grade passback.

Adopt NRPS only if the product needs LMS roster import or reconciliation with
native Wordboat classes. Request its read-only scope only for an authorized
instructor workflow; handle pagination, role filtering, removals, and partial
PII. Do not automatically create or delete WordPress accounts merely because a
roster response changes. Define invitations, identity linking, suspended
enrollments, teacher overrides, and resynchronization policy first.

## Vendor-specific adapters outside LTI

LTI and AGS provide the reusable core for conforming LMS platforms, but they do
not make one connector universal. Treat a platform whose official integration
surface uses different APIs as a separate adapter over the same assignment,
attempt, identity-mapping, and delivery-outbox domain.

Google Classroom is the first explicit example. Google's current publisher
paths are Classroom Share, the CourseWork API, and Classroom add-ons. The
initial connector targets the CourseWork API: the same configured Cloud
project creates explicit DRAFT CourseWork, then an exact mapped student
submission receives `draftGrade` from the selected server-authoritative grade.
That project-ownership restriction is part of the mapping/readback contract;
the connector must not attach itself to an arbitrary pre-existing assignment.

An embedded Wordboat activity should later use an activity-type add-on
attachment with a positive `maxPoints`, then update the attachment submission's
`pointsEarned` through the Classroom API. The attachment must have been
created by the add-on, and automatic completion-time passback may require
securely retained teacher offline authorization. Marketplace listing, OAuth
verification, domain allowlisting, license availability, review, identity
mapping, assignment ownership, and retry behavior are therefore a separate
delivery workstream from both CourseWork and LTI registration/AGS. Do not mix
the CourseWork and add-on journeys inside one resource mapping.

Do not implement the Classroom adapter by translating Classroom objects into
pretend LTI claims. Reuse the protocol-neutral Phase 2 records and expose a
separate destination adapter with its own least-privilege scopes, mapping keys,
credential lifecycle, outbox sender, audit states, and compatibility tests.
The same rule applies to any other major platform that lacks the required LTI
services or exposes a materially different assignment/grade contract.

## Privacy and operational controls

LMS integration sends education records to another system and adds persistent
cross-system identifiers. Before production rollout:

- update the site privacy notice to describe teacher access, LMS launches,
  grade passback, recipients, purposes, and retention;
- minimize stored claims; `iss`/deployment/`sub`, context, resource-link, and
  line-item identifiers are normally sufficient for protocol mappings;
- accept launches without name/email where the LMS legitimately withholds PII;
- extend WordPress personal-data export and erasure to assignments, attempts,
  external identity mappings, and delivery audit records where applicable;
- define how local corrections, account erasure, class removal, and LMS grades
  interact. Erasing a local account must not silently issue a grade deletion to
  an LMS without an explicit policy and authorized action;
- use separate retention settings for official attempts/delivery audit and the
  existing short-lived detailed study-event log;
- restrict registration and delivery diagnostics to authorized administrators
  and class/grade views to the owning instructor;
- document backup/restore effects on deployments, signing keys, mappings, and
  unsent outbox work.

Applicable school, child-privacy, and data-protection obligations depend on the
deployment and contracts. They require a product/legal review rather than an
assumption that protocol conformance alone supplies compliance.

## Testing and compatibility gates

### Automated coverage

Current local automated coverage includes `UserProgressPracticeResultTest`, the
provider-neutral assignment/delivery/privacy suites,
`GoogleClassroomFoundationTest`, `teacher-classes-frontend.spec.js`, and
`google-classroom-admin-ui.spec.js`. The Google browser fixture exercises safe
unconfigured and locally mocked connected states without contacting Google; it
is not OAuth, Marketplace, CourseWork-write, or grade-passback proof.

Maintain focused tests for the implemented contracts:

- Phase 1 result creation, sanitization, omission, idempotency, access control,
  adaptive bounded report queries, explicit query-failure UI, export/erasure,
  and retention;
- assignment revision immutability, attempt authorization, server score
  calculation, finalization idempotency, and first/latest/best selection;
- OAuth state/PKCE one-time use, bounded connection/course reads, encrypted
  credential storage, disabled-write gates, and safe local admin states;
- outbox crash recovery, exact-owner leases, deduplication, stale-revision
  suppression, and an older retry never overwriting a newer grade.

Add the following only with the corresponding future protocol phase:

- OIDC state/nonce expiry and one-time use;
- valid and invalid JWT signatures, issuer/audience/deployment claims, clock
  boundaries, unknown/rotated `kid`, PII-free launches, and algorithm attacks;
- identity and resource mappings across two issuers/deployments with colliding
  subject, context, or resource-link values;
- AGS token acquisition, scope restriction, score body/media type, timeouts,
  `Retry-After`, redaction, and permanent failures;
- iframe and new-window launches, cookie restrictions, and accessible failure
  recovery;
- Deep Linking signatures, assignment/line-item declarations, and course-copy
  behavior when that phase is implemented;
- bounded NRPS pagination and reconciliation when NRPS is implemented.

Use recorded/mocked platform traffic for deterministic integration tests, but
never put real access tokens, private keys, learner records, or production
launch JWTs in fixtures.

### Platform matrix

For each targeted major LMS, maintain a versioned compatibility row covering:

- administrator registration steps and required URLs;
- learner and instructor launch, including PII-minimized payloads;
- iframe and new-window behavior;
- fixed resource link and AGS grade creation/update;
- retry and corrected-grade ordering;
- course copy/restore;
- Deep Linking and NRPS only when those capabilities are claimed.

Start with one LMS sandbox and one fixed assignment. Expand to other platforms
only after the common LTI/AGS path is stable, adding narrow adapters for proven
platform differences rather than branching the core protocol by vendor name.
Passing one LMS test does not establish compatibility with Canvas, Moodle,
Blackboard Learn, D2L Brightspace, or any other platform.

Maintain a separate matrix row for Google Classroom's add-on/CourseWork path;
an LTI conformance result does not cover its OAuth, Marketplace review,
attachment ownership, or grade-passback behavior.

Use the official [LTI Advantage Conformance Certification Guide][lti-cert] and
validator as a release gate when pursuing a public interoperability claim.
Certification and per-vendor tests are complementary: certification establishes
standards conformance, while current platform tests establish supported setup
and behavior.

## Why xAPI and SCORM are not the primary grade-passback path

[xAPI][xapi] is well suited to emitting rich learning-experience statements to
a Learning Record Store. It could later mirror Wordboat exposures, answers, or
completed assignments for analytics. xAPI alone does not create/update the
ordinary LMS gradebook line item requested here, so it is complementary to LTI
AGS rather than its replacement.

[SCORM 2004][scorm] packages Sharable Content Objects that communicate with an
LMS-provided browser runtime API and data model. Wordboat is a hosted, dynamic,
multi-wordset WordPress application with its own accounts, progress sync, and
continuously changing content. Turning it into a SCORM package would add
package/version distribution, iframe/API discovery, and duplicated state while
still giving a weaker external-tool integration model. SCORM export may be a
separate product someday, but it is not the primary architecture for hosted
Wordboat grade passback.

## Delivery sequence and decision gates

| Phase | Deliverable | Exit gate |
| --- | --- | --- |
| 1 | Native, client-reported Practice results in teacher Classes | Exact nested contract, bounded reporting, privacy coverage, formative labeling |
| 2A | Provider-neutral assignment, attempt, grade, and outbox foundation | Immutable revisions, server score, idempotent finalization, explicit grade policy, ordered delivery tests |
| 2B | Native teacher authoring and learner assignment player | Bounded frozen-content builder, accessible UI, correction/retention policy, end-to-end browser test |
| 2C | Google Classroom CourseWork pilot | OAuth verification, exact project-owned CourseWork/submission mappings, draft-grade ordering, sandbox tests |
| 3 | LTI Core launch plus AGS for one fixed assignment on one LMS | Audited JOSE dependency/key custody, security review, outbox ordering proof, sandbox and correction tests |
| 4 | Deep Linking assignment picker | Signed instructor flow, bounded picker, line-item and course-copy tests |
| 5 | Optional NRPS roster sync and additional LTI LMSs | Explicit roster policy and a passing versioned platform matrix |
| 6 | Google Classroom add-on and other vendor-specific adapters | Shared assignment/attempt semantics, platform review, OAuth and grade-passback tests |
| 7 | Public conformance claim | Applicable 1EdTech validator/certification and documented supported platforms |

Do not skip Phase 2 for a high-stakes or externally published grade. A Phase 3
prototype may technically transmit the Phase 1 score, but it must remain
clearly marked experimental/formative and disabled for production gradebooks
until the server-verifiable attempt gate is complete.

## Official specifications

- [Learning Tools Interoperability Core 1.3][lti-core]
- [1EdTech Security Framework 1.0][lti-security]
- [LTI Assignment and Grade Services 2.0][lti-ags]
- [LTI Deep Linking 2.0][lti-dl]
- [LTI Names and Role Provisioning Services 2.0][lti-nrps]
- [LTI Advantage Conformance Certification Guide][lti-cert]
- [ADL xAPI specification repository][xapi] (the repository points to the
  current IEEE xAPI standard)
- [ADL SCORM 2004 programmer guide][scorm]
- [Google Classroom integration paths][classroom-paths]
- [Google Classroom add-on attachment and grade-passback guide][classroom-grades]
- [Google Classroom CourseWork creation][classroom-coursework-create]
- [Google Classroom grade management][classroom-grade-management]

[lti-core]: https://www.imsglobal.org/spec/lti/v1p3/
[lti-security]: https://www.imsglobal.org/spec/security/v1p0/
[lti-ags]: https://www.imsglobal.org/spec/lti-ags/v2p0/
[lti-dl]: https://www.imsglobal.org/spec/lti-dl/v2p0/
[lti-nrps]: https://www.imsglobal.org/spec/lti-nrps/v2p0/
[lti-cert]: https://www.imsglobal.org/spec/lti/v1p3/cert/
[xapi]: https://github.com/adlnet/xAPI-Spec
[scorm]: https://adlnet.gov/assets/uploads/SCORM_Users_Guide_for_Programmers.pdf
[classroom-paths]: https://developers.google.com/workspace/classroom/guides/integration-paths/integration-paths
[classroom-grades]: https://developers.google.com/workspace/classroom/add-ons/developer-guides/attachment-interactions
[classroom-coursework-create]: https://developers.google.com/workspace/classroom/reference/rest/v1/courses.courseWork/create
[classroom-grade-management]: https://developers.google.com/workspace/classroom/guides/classroom-api/manage-grades
