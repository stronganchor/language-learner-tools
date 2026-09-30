# Frontend recording tools

These tools use existing wordset manager and recorder access. Open the wordset's
**Word Set Tools** menu for editor/review work, or the **Recording Interface**
for a contributor's own playback history.

## Category privacy and prerequisites

Open **Category settings** on a vocabulary lesson. Administrators can choose
**Visibility → Private**; the change autosaves with the other category settings
and preserves existing allowed-user assignments. A wordset-owned category can
become private independently of its source and siblings. A private source keeps
its copies private until that source is made public.

Prerequisite choices use the same responsive columns and wrapping labels as the
Edit Word category picker. The language bar stays behind an open category or
bulk settings popup and returns to its normal layer when the popup closes.

## Split a category

Open **Split Category** from a lesson's category settings. Select the words in
step 1, then enter a new category name in step 2 and choose **Move selected
words**. The destination stays beside the table on wide screens. Existing
categories are an explicit alternative; only the active destination is submitted.
Published words remain published and draft words remain drafts. Category settings
are copied by default for a new destination, with an optional switch under
**Category settings**.

Selection is limited to checked words on the current page unless **Select all
matching words** is explicitly checked. Unchecking a word returns to page-local
selection. Optional filters retain the source category, and an empty selection
cannot create a destination. Successful moves show their count and destination,
open that destination's editor, and retain the existing action-history Undo.
Linked word-image category assignments move with their words.

The split screen has one selection form and one move action. General editor
status actions, saved views, recording tools, and word editing are omitted so
their forms cannot accidentally substitute for the split. Canonical coverage is
`WordsetEditorToolTest.php` and `wordset-category-split.spec.js`.

## Copy or split a word

Open a word's **Edit word** popup from a lesson or Wordset Editor and choose
**Copy / Split** beside the save/cancel controls. The same action is also available
directly in **Word Set Tools → Editor**. Save pending word edits before copying;
the popup preserves unsaved values and asks you to save them first. The dialog
starts with the same title and retains all recordings on the original. The copy
shares the existing image attachment and copies eligible word metadata. A source
with no recordings can also be copied.

To split recordings between meanings, select exactly the recordings to move
before creating the copy. Recording previews are paginated, and one operation
accepts at most 50 moves. The normal category audio requirement still controls
whether either word can be published. A successful operation closes the copy
dialog and word editor, inserts the new word in the matching category grid, and
scrolls to it without refreshing the page or showing a success message. Copies
without the required published audio appear gray. Manager table rows retain a
link to the copy.

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
| Copy/Split | `includes/lib/word-copy.php`, `includes/pages/wordset-editor.php`, `includes/shortcodes/word-grid-shortcode.php`, `js/word-copy-dialog.js`; `ll_tools_word_copy_preview`, `ll_tools_word_copy_apply`, `ll_tools_word_copy_status` | `WordCopySplitTest.php`, `word-copy-dialog.spec.js`, `word-copy-popup.spec.js`, existing `SplitWordReturnFlowTest.php` |
| Transcription review | `includes/pages/wordset-transcription-review.php`, `js/wordset-transcription-review.js`; `ll_tools_get_wordset_transcription_review`, `ll_tools_save_wordset_transcription_review`; settings tool `transcription-review` | `WordsetTranscriptionReviewTest.php`, `wordset-transcription-review.spec.js`, existing `IpaKeyboardAdminAjaxTest.php` |
| Private history | `includes/lib/recording-history.php`, `includes/shortcodes/audio-recording-shortcode.php`, `js/recording-history.js`; authenticated `ll_tools_recording_history` | `RecordingHistoryTest.php`, `recording-history.spec.js`, existing recorder/upload attribution tests |

Each feature has matching dedicated CSS and timestamped assets. AJAX endpoints
check nonces and current object/wordset access before exposing media or writing.
Popup copy triggers carry their own wordset and scoped nonce, including deferred
editors. The nested native dialog owns focus and Escape while open. Its result
event `ll-word-copy-source-updated` updates recording ownership in visible source cards
and invalidates cached detached editor markup without a page refresh, including
confirmed partial moves in an incomplete copy. Creation stays blocked while the
receipt needs review. Receipt updates and deletion compare the stored option
bytes captured by the same read as the decoded value, so a legacy connection
charset cannot make a non-ASCII title fail its exact checkpoint comparison.
Completed receipts return only one rendered card and its
scoped category IDs. Optional display context must be one of those categories so
the copied card keeps its category's text/image presentation. `ll-word-copy-completed`
inserts it only into matching wordset/category grids, preserves their
paging and lesson attributes, and moves focus after both dialogs release it.
`word-copy-popup.spec.js` guards insertion,
scrolling, status recovery, and the gray no-audio state.

Copy and transcription-review transport failures use the configured translated
messages for network, malformed-response, and timeout errors. Validated server
rejections retain their specific message. The localized error path preserves
the same saved-copy receipt or unsaved transcription draft; it never retries
an uncertain mutation automatically. `WordCopySplitTest` also warms another
source wordset's audio payload before moving a recording, then verifies that
the existing cross-wordset invalidation removes the moved audio from that view.
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
