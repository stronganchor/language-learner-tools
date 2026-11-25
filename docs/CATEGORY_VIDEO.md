# Category videos

Open **LL Tools Utilities → Category Video** as an administrator. Choose a word
set, exact category, and optionally a speaker, then **Load words**. Select up to
30 words and use the arrows to reorder them. **Load more words** reads the next
batch without rebuilding earlier rows or their controls. The page reports when
the full category has been checked.

Choose portrait (1080×1920), landscape (1920×1080), or square (1080×1080), one to
three repetitions, and a pause after each recording. Optional thinking time
shows the picture before the written answer and audio. The title is editable;
word labels come from the source data. Prepare the preview, play it, then create
the video. Settings or selection changes require a fresh preview. The last
completed download remains available until a replacement succeeds.

Creation runs in real time in the visible browser tab. The download uses MP4
when the browser can encode H.264/AAC, otherwise WebM. The result includes the
original recordings at their original speed and pitch. The tool does not use
the microphone, synthesize speech, upload the finished video, or publish it.
Review the downloaded video before publishing it elsewhere.

## Source and rendering boundaries

- The page and AJAX require both `manage_options` and `view_ll_tools`, plus
  nonce `ll_tools_category_video`. Sources are scoped by current wordset and
  category visibility and exact category ownership/membership.
- `ll_tools_category_video_sources` pages wordsets, categories, then speakers
  with a 100-row keyset. `ll_tools_category_video_items` reads 25 candidate words
  at a time and returns an explicit continuation cursor and skipped reasons.
  An incomplete source read returns a retryable error so the same cursor can be
  retried with repaired metadata caches.
- Only published, non-password-protected words and isolation recordings are
  considered. Speaker choices, filtering, and attribution all use the first
  valid positive speaker metadata row, with the post author as the fallback.
  The newest suitable recording is selected deterministically, examining at
  most 20 matching recordings per word.
- Flagged recording text is excluded. Unflagged nonempty `recording_text` is
  kept literally; an empty recording label uses the canonical target-language
  word text. This does not assert that every unflagged legacy record was manually
  reviewed. IPA-only review flags do not change the spoken clip/orthographic label.
- Existing image links/copies and thumbnails are read without creating isolated
  image copies, regenerating attachments, or changing metadata. Missing media
  is reported explicitly. Cross-origin audio needs CORS permission; media
  failures stop preparation rather than silently removing words.
- Browser preparation is capped at 30 words, 30 seconds per recording, 15 MiB
  per fetched file and ten minutes per output, with aggregate download/audio
  memory limits. Three workers fetch and prepare selected media. The browser
  verifies audio duration from the downloaded file before Web Audio decoding.
  Only one full audio decode can run at a time, even across Stop and retry.
  Decoded duration and retained bytes are checked again before use.
- Preview and export use the same timeline and draw function. WebAudio owns
  recording timing; Canvas draws the complete image without cropping. Export
  combines canvas and audio-destination tracks, never device microphone tracks.
- Stop, hidden-tab interruption, decode errors, recording errors and page exit
  release their operation's resources. A stopped render cannot publish a result
  into a subsequent render. Unsupported recording environments fail explicitly.

## Code owners and tests

- `includes/admin/category-video-export.php` owns the page, localized UI
  configuration, and page-only asset loading.
- `includes/lib/category-video-data.php` selects sources and guards AJAX reads.
- `js/category-video-export.js` handles source selection, ordering, pacing,
  preparation, preview, cancellation, and downloads.
- `js/category-video-renderer.js` owns browser rendering and can run without
  WordPress.
- `css/category-video-export.css` provides the scoped, responsive admin layout.
- `tests/Integration/CategoryVideoDataTest.php` covers source scope, speaker
  metadata, read failures, and the registered AJAX handlers.
- `tests/e2e/specs/category-video-export.spec.js` exercises the native browser
  encoder with generated media.
- `tests/e2e/specs/category-video-controller.spec.js` exercises the controller
  with the rendered PHP page and a standalone browser fixture.
- `tests/e2e/specs/category-video-admin.spec.js` exercises the authenticated admin
  screen with controlled media and source responses.

Run PHP tests through `tests/bin/run-tests.sh`; run the admin browser test through
`tests/bin/run-e2e.sh`. The standalone renderer test can run directly through the
installed Playwright CLI without WordPress. Fixture recordings do not substitute
for listening to the chosen vocabulary recordings before publication.
