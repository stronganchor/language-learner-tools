# Connecting Wordboat to Moodle

Use Moodle's built-in **External tool** activity (LTI 1.3). Michael can add
Wordboat alongside his existing readings, videos and quizzes. Each new activity
opens either Wordboat practice or a fixed, graded vocabulary assignment. Students
can use the same permanent Wordboat account in class and on their own.

## Before Michael starts (Wordboat operator)

1. Install the tested LL Tools build from the normal `dev` update channel.
   Configure the tool's RSA private signing key (at least 2048 bits), stable
   public key ID and 32-byte LMS encryption key in protected server configuration.
   For example, use an owner-readable file outside the document root:

   ```php
   define('LL_TOOLS_LTI_PRIVATE_KEY', file_get_contents('/protected/path/lti-private.pem'));
   define('LL_TOOLS_LTI_KEY_ID', 'wordboat-1');
   define('LL_TOOLS_LMS_CREDENTIAL_KEY', 'base64:REPLACE_WITH_32_RANDOM_BYTES_IN_BASE64');
   ```

   Preserve an existing LMS key and back up the keys with the database.
   Overlapping tool signing-key rotation and encryption-key migration require
   separate operational work. Only the public keyset URL is shared with Moodle.
2. Open **LL Tools > Moodle / LTI**. The core `users`, `usermeta`, `posts`,
   `postmeta` and `options` tables and the progress and LMS tables must support
   InnoDB transactions. The screen identifies core tables needing attention.
   The November 25, 2026 Wordboat preflight found `posts` and `options` still on
   MyISAM. Back up and obtain approval for the production conversion first.
   The plugin gates enrollment on storage readiness and leaves core table engines
   under the operator's control.
3. Give Michael a Wordboat teacher account and a class assigned to the intended
   Hebrew wordset. Check a ready lesson on that wordset with his teacher access.
   For student self-signup, enable both WordPress membership registration and
   LL Tools learner self-registration. Alternatively provide ordinary learner
   accounts before class. Confirm WordPress scheduled tasks run for grade delivery.
4. Copy the four URLs from **Tool details for the Moodle administrator**. Open
   the public keyset URL and verify it returns a nonempty RSA keyset with the
   intended key ID. The URLs for Wordboat are:

| Field in Moodle | Wordboat value |
| --- | --- |
| Tool URL | `https://wordboat.com/?ll_lti_resource=1` |
| Initiate login URL | `https://wordboat.com/wp-json/ll-tools/v1/lti/login` |
| Redirection URI(s) | `https://wordboat.com/wp-json/ll-tools/v1/lti/launch` |
| Public keyset URL | `https://wordboat.com/wp-json/ll-tools/v1/lti/jwks` |

An operator with WP-CLI can verify the installed build, key availability and
database readiness before handing the URLs to a school. Run in the WordPress
installation as the site owner. The output contains status flags and engine
names. The key material stays on the server.

```sh
wp eval 'echo wp_json_encode([
    "version" => LL_TOOLS_VERSION,
    "signing_key" => !is_wp_error(ll_tools_lti_signing_material()),
    "encryption_key" => ll_tools_lms_credential_store_is_available(),
    "core_storage" => ll_tools_lti_account_storage_status(),
    "progress" => ll_tools_user_progress_core_engine_status(true)["ready"],
    "assignments" => ll_tools_lms_assignment_schema_is_ready(),
    "grade_delivery" => ll_tools_grade_delivery_schema_ready(true)
], JSON_PRETTY_PRINT), PHP_EOL;'
```

## One-time registration (Michael or his Moodle administrator)

1. Open **Site administration > Plugins > Activity modules > External tool >
   Manage tools > Configure a tool manually**. Use the name **Wordboat**, LTI
   version **LTI 1.3**, public key type **Keyset URL** and the four URLs above.
   Make the tool available to Michael's course and set its launch container to
   **New window**. Leave custom parameters empty. Student names and email
   addresses can stay private.
2. For graded work, under **Services** enable **IMS LTI Assignment and Grade
   Services** with **Use this service for grade sync only**. Under **Privacy**
   set **Accept grades from the tool** to **Always**, or allow the instructor to
   select it and enable it on each graded activity. Save the tool.
3. From the saved tool's **View configuration details** copy the platform ID
   (issuer), client ID, deployment ID, public keyset URL, access-token URL and
   authentication request URL, and give them to the Wordboat operator.
4. **Wordboat operator:** open **LL Tools > Moodle / LTI > Paste Moodle
   configuration details**, paste the six English labeled lines or Moodle's
   platform-details JSON and save. The individual fields also support Moodle
   installations using other interface languages. Each deployment gets its own
   registration. Endpoints must use HTTPS on the registered Moodle origin.
   After this step Michael can set up activities himself.

Moodle documents the available launch containers, tool configuration and course
settings in [External tool settings](https://docs.moodle.org/501/en/External_tool_settings).

## Adding a lesson (Michael)

1. Sign in to the Wordboat teacher account in the browser used for Moodle. Turn
   course editing on and choose **Wordboat** in the activity chooser. On versions
   with a generic **External tool** activity, select Wordboat as the preconfigured
   tool. If Wordboat is hidden, open **Course > More > LTI External tools** and
   turn on **Show in activity chooser**. Give the activity a name and save.
   For a graded lesson select a numeric grade and maximum. For practice disable
   grading where Moodle allows it. Wordboat sends scores only for graded assignments.
2. Open the saved activity as its instructor. Follow **Open Moodle / LTI
   settings**, choose the Wordboat class and lesson category, and select
   **Practice** or **Graded assignment**. Moodle supplies the course and
   activity IDs in the verified launch. Save once. If the setup tab expires,
   open the Moodle activity again.
3. For a graded assignment, select the attempt limit and the first, latest or
   best completed score. Set the maximum to match the Moodle activity. Wordboat
   snapshots the vocabulary questions at publication. The graded player
   supports 5–15 usable words with the category's text, image or audio prompts
   and choices. A Moodle quiz, Bibleling link or unpublished Aleph with Beth
   draft needs a suitable Wordboat lesson before it can use this integration.
4. Choose Moodle's course completion rule. Graded lessons can use **Receive
   a grade**. Practice saves personal progress in Wordboat. Moodle's manual
   completion and require-view rules are available when that is the desired
   signal. A copied or restored Moodle activity with a new link ID needs a fresh
   instructor setup.

The advanced manual form still accepts an exact course ID and an optional link
ID and produces `ll_activity=<activity ID>` for that one Moodle activity.
Configurations created with the guided flow use the verified activity directly.

## What students see

Students open the activity from Moodle in a new window. On their first visit
they sign in to an existing Wordboat learner account or create one and confirm
the connection. Later launches reuse the linked account and enroll it in the
mapped class. Personal study and class activities update that account's per-word
history. Fixed assignments also send the selected score to Moodle's gradebook.
A different active Wordboat account prompts a conflict; students should use the
linked account. Teachers should test the learner experience with a separate
Moodle student and Wordboat learner account.

Learners can disconnect through `/?ll_lti_connections=1` and retain their personal
history. Reconnecting a revoked link requires administrator review. Removing a
member from the native Wordboat class blocks automatic readmission. Account
erasure removes the local connection and data. Moodle retains its own gradebook.

## Pilot acceptance

- Use a separate Moodle student and a ready Hebrew lesson. Confirm practice
  updates the same Wordboat account on two launches and through independent
  study. Check the browsers used by the class.
- Complete a graded assignment. Open the student's grade in Moodle, check the
  points and completion rule and confirm a second allowed attempt follows the
  configured grade policy. If a grade is delayed, check the grade-delivery queue
  and scheduled WordPress jobs. Keep signed tokens and secrets out of logs.
- Start with these pilot activities before changing the rest of the course.
  Registration is manual, activity mapping is instructor-initiated and course
  membership follows student launches. There is no bulk conversion of Moodle
  quizzes or textbook exercises. The current registration limits are ten platform
  deployments and 100 saved activity mappings per Wordboat installation.
  Login initiation allows 180 launches per registered deployment per UTC minute;
  excess launches receive HTTP 429 and can be retried in the next minute.

## Code and verification

`includes/lms/lti.php` owns launch validation, account-independent protocol
state and grade transport. `includes/lms/lti-accounts.php` owns the instructor
setup ticket, persistent student link and course admission.
`includes/lms/assignment-experience.php` owns the player and per-word progress.
`includes/admin/lti-integration.php` owns Wordboat configuration. JWT
provenance is documented in [the dependency guide](../dependencies/lti/README.md).
The WordPress integration tests are `LtiFoundationTest`, `LtiAccountsTest`,
`LmsAssignmentExperienceTest` and the shared LMS/privacy suites.

On November 25, 2026 we tested Moodle 5.1.7 and WordPress 6.8.3 on different
HTTPS sites. A teacher used a public wordset managed by another person and
configured both activities from Moodle. A student linked a Wordboat account,
reopened practice in Moodle's new window, and answered a practice question.
Wordboat saved the practice events to the same account. The graded assignment
produced 100/100, which the student could see in Moodle's own gradebook after
the Wordboat delivery worker ran. The version-specific fixture and reproduction
steps are in [the Moodle interoperability test](../tests/interop/moodle/README.md).
