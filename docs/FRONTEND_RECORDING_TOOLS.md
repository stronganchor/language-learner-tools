# Frontend recording tools

These tools use existing wordset manager and recorder access. Open the wordset's
**Word Set Tools** menu for editor/review work, or the **Recording Interface**
for a contributor's own playback history.

## Copy or split a word

Open **Editor**, find the source word, and choose **Copy / Split**. The dialog
starts with the same title and retains all recordings on the original. The copy
shares the existing image attachment and copies eligible word metadata. A source
with no recordings can also be copied.

To split recordings between meanings, select exactly the recordings to move
before creating the copy. Recording previews are paginated, and one operation
accepts at most 50 moves. The normal category audio requirement still controls
whether either word can be published. A successful operation updates the source
row and provides a link to the new word without refreshing the page.

If the response is lost, use the dialog's status check. Its saved request receipt
prevents a retry from creating another word. An incomplete copy remains available
for review; it is not automatically repeated or deleted. If creation stopped
before the new word received its wordset, the dialog shows its draft ID for an
administrator to review. Keep that ID and do not create another copy.

## Review transcription and IPA

Open **Transcription Review** in Word Set Tools. Search recordings within the
selected wordset, optionally filter to recordings needing review, and listen
beside their text. Recording text, IPA/secondary transcription, and review notes
autosave with inline status. The symbol keyboard uses the wordset configuration.

Finish pending edits before changing pages. A conflicting or unverifiable save
retains the local text and requires an explicit reload before further saves.
Copy any text you want to keep before reloading the recording. Wait for autosave,
then choose **Mark reviewed** to clear the recording's review flags and associated
review note.

The separate **Transcription** tool configures the transcription provider;
opening the review tool does not invoke a provider or transcribe audio.

## My recordings

Expand **My recordings** inside `[audio_recording_interface]`. History loads
only when opened and contains the current speaker's recordings in the selected
assigned or managed wordset. It includes word recordings and the current audio
attachment of a prompt card. Speaker attribution takes precedence over the user
who uploaded the file; legacy recordings fall back to the post author.

Use playback, **Newer**, **Older**, or **Refresh** to review recording type,
date, and processing/publication status. Own pending uploads can appear while
their word is still a staff-created draft. Word links are shown only for
published readable words. History does not add deletion or rerecording rights.

## Source owners and regression guards

| Surface | Source / request boundary | Canonical tests |
| --- | --- | --- |
| Copy/Split | `includes/lib/word-copy.php`, `includes/pages/wordset-editor.php`, `js/word-copy-dialog.js`; `ll_tools_word_copy_preview`, `ll_tools_word_copy_apply`, `ll_tools_word_copy_status` | `WordCopySplitTest.php`, `word-copy-dialog.spec.js`, existing `SplitWordReturnFlowTest.php` |
| Transcription review | `includes/pages/wordset-transcription-review.php`, `js/wordset-transcription-review.js`; `ll_tools_get_wordset_transcription_review`, `ll_tools_save_wordset_transcription_review`; settings tool `transcription-review` | `WordsetTranscriptionReviewTest.php`, `wordset-transcription-review.spec.js`, existing `IpaKeyboardAdminAjaxTest.php` |
| Private history | `includes/lib/recording-history.php`, `includes/shortcodes/audio-recording-shortcode.php`, `js/recording-history.js`; authenticated `ll_tools_recording_history` | `RecordingHistoryTest.php`, `recording-history.spec.js`, existing recorder/upload attribution tests |

Each feature has matching dedicated CSS and timestamped assets. AJAX endpoints
check nonces and current object/wordset access before exposing media or writing.
`includes/lib/recording-metadata.php` serializes recording text and review writes
across these tools and existing editor, admin, import and sync paths. The shared
lock, conditional metadata writes and fresh readback work with both InnoDB and
MyISAM. A failed multi-field change can retain earlier writes; the tool reports an
unverified save and requires a reload instead of silently replaying it.
`RecordingMetadataWriteTest.php` guards the shared writer boundary; the review
suite also exercises a real isolated MyISAM metadata table.
History cursors bind the viewer and wordset, expire, and never accept a caller's
recorder identity. Candidate pages and media hydration remain bounded; incomplete
reads must not become authoritative empty results.

`frontend-recording-tools-wp.spec.js` uses the marked disposable fixture in
`tests/e2e/fixtures/seed-frontend-recording-tools.php` to exercise all three real
WordPress routes together: manager autosave, copy with one recording move, and
attributed recorder playback history. It reads saved data back through WP-CLI and
removes its fixture in a `finally` block. Run it serially with other Local-backed
tests.
