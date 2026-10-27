# Lesson activities

## Passage with draggable words

Create a **Content Lesson**, select **Fill in the blanks**, and assign its word set. Enter the passage in the dedicated passage field, putting `[[double brackets]]` around each missing word or phrase. For example:

```text
The [[bird]] is on the [[branch]].
```

Keep punctuation outside the brackets when it should remain visible. The page opens straight into the passage and shuffled word bank. Learners can drag words into blanks, or select a word and then a blank using touch, mouse, or keyboard. Identical repeated words are interchangeable. Checking an answer marks the attempt without displaying a separate answer key. Completion is recorded only after the server verifies every blank.

This is a content lesson rather than a vocabulary category: it has no Learn, Listen, Practice, or Self-check launch buttons, and it does not need words, pictures, or recordings. Source text is private lesson metadata; do not put an answer key in the lesson body or excerpt. Private word-set and password access still apply. Passage authors should use the existing content-lesson word-set permissions.

The authoring format is deliberately bounded: at most 20 KB of source, 60 blanks, and 300 bytes per answer. Hebrew vowel marks, right-to-left text, line breaks, and punctuation are preserved. A source change invalidates old answer checks; learners reload before checking the changed exercise.

## Vocabulary lesson launch settings

Vocabulary lessons retain their normal activity buttons and study cards by default. Their staff settings can select the available activities and optionally embed one activity directly on the lesson page. An embedded activity replaces the study-card grid, so answers are not shown before learners start. The server renders a single centered loading animation and masks the iframe from its first paint until the child signals that a usable question is ready. A slow or failed load exposes Retry and a direct activity link; a late valid readiness signal can still reveal the activity without reloading.

Available modes are always intersected with what the lesson can actually support. Listen requires a published, playable recording in the current scope. A category expecting recordings is not sufficient by itself. A lesson's restrictions also apply to its embedded quiz controls and results-screen mode choices.

The settings form submits activity controls along with category presentation edits. The server compares the submitted activities with a fresh, error-detecting read while holding the category settings lock. Unchanged effective activities are preserved without writing post metadata; a category-only edit therefore does not require transactional post metadata. Actual activity changes still require InnoDB post metadata and commit with category settings in the same transaction. An incomplete comparison read refuses the entire save.

For a text-to-text transformation exercise, Practice can be the only enabled activity and the embedded default. The random-question setting remains independent: a 73-item bank can still use a fresh sample of 15 targets for each Practice attempt.

The shared `LLToolsQuizIframe.attach(wrapper, { url })` controller in `js/quiz-pages.js` owns loading, origin/source validation, timeout, retry, and cleanup for standalone quizzes and vocabulary lessons. It observes the initial iframe without navigating again and returns its canonical `sourceUrl`, `iframe`, and `destroy()` method. The lesson destroys the old controller before removing an iframe or replacing it for a changed activity URL, so stale messages cannot activate the next mode. A successful Retry does not restart when the same activity is selected or unrelated settings are saved.

## Regression checks

Use the focused lesson activity, cloze, and audio-availability integration tests plus their browser fixtures; see `tests/AI_TESTING_PLAYBOOK.md` for the shared Local runtime rules. Check normal lessons as well as activity-first pages, private/password access, empty audio URLs, repeated Hebrew answers, incorrect placements, retry, touch/keyboard input, and narrow viewports. `vocab-lesson-activities.spec.js` checks the server loading mask before JavaScript, actual child readiness, scoped timeout recovery, stale mode messages, and cleanup; `quiz-iframe-recovery.spec.js` preserves the standalone quiz and dialog contracts.
