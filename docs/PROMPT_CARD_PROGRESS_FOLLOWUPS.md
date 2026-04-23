## Prompt Card Follow-Ups

Current behavior:

- Prompt cards track their own study state in `ll_user_study_prompt_card_progress`.
- Prompt cards can optionally also count correct answers toward the correct answer word mastery.
- Category progress bars include prompt-card totals so prompt-card-only lesson categories still show useful progress.
- Prompt cards are currently excluded from `self-check` and `gender` mode so they do not enter flows that still assume normal word-centric audio/image pairings.

Suggested later improvements:

- Add a first-class grammar-concept progress layer keyed by a concept slug or lesson concept term.
  This would let multiple prompt cards roll up into progress for concepts like `or`, `yes-no-questions`, or `plural-choice`.
- Add prompt-card templates and reuse tools in the authoring UI.
  The main gap is fast creation of repeated prompts such as `Is this a {word}?` across many prompt images.
- Add dedicated `self-check` support for prompt cards.
  That mode currently groups by image identity and replays answer-word recordings, so it needs prompt-card-aware grouping and playback before it should include them.
- Revisit weighting and recommendations for prompt cards versus normal word rows.
  A future pass could give prompt cards their own recommendation lane instead of relying only on category-level progress totals.
