# Overnight Automated Code Review TODO

Started: 2026-07-06 23:38 Europe/Istanbul
Target branch: `dev`
Review base HEAD: `1ecfbeb3688dde36fac9917990189fed6b1f3406`
Cadence: once every 20 minutes until 2026-07-07 09:00 Europe/Istanbul

The chunk manifest and machine-local run state live at:

```text
C:\Users\messy\.codex\automation-state\ll-tools-overnight-code-review
```

## Operating Rules

- Review the next pending chunk from the manifest on each heartbeat.
- Treat this as a code-review pass: prioritize correctness, security, performance, large-wordset behavior, i18n readiness, capability/nonce checks, missing tests, and stale docs.
- Append follow-up items under `Findings TODO` with chunk id, severity, evidence, suggested fix, and suggested verification.
- Safe minor fixes may be made immediately, especially documentation, source-contract, typo, or narrow test-maintenance updates.
- Do not do broad refactors, live-site writes, release/version bumps, UI redesigns, or product behavior changes as part of the heartbeat unless a chunk reveals a small clear bug fix with focused verification.
- Commit completed repo changes after each heartbeat pass, following `AGENTS.md`.
- Stop instead of mutating if the branch or current allowed HEAD in the machine-local state no longer matches the checkout.

## Coverage Plan

The run is sized for 28 review chunks, which fits the remaining 20-minute slots before the 2026-07-07 09:00 Europe/Istanbul cutoff. Chunks cover tracked first-party source, docs, scripts, tests, styles, templates, locale sources, and first-party offline-app builder code. Vendor, generated artifacts, binary media, test reports, built release output, and the embedded third-party Whisper/GGML source tree are excluded from normal overnight coverage.

## Findings TODO

No findings have been recorded yet.

## Completed Chunks

No chunks have been marked complete yet.
