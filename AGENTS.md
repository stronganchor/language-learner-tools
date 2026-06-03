See `CODEBASE_ARCHITECTURE.md` for the canonical map of entry points, flows, and invariants for this plugin.

Codebase-specific guidelines:
- For testing workflows and conventions (PHPUnit + Playwright), see `tests/AI_TESTING_PLAYBOOK.md` and `tests/README.md`.
- When a request reveals that a larger structural change would make the solution cleaner or more maintainable, agents should use judgment: implement it when the broader change is safe and obvious; document it as a maintenance follow-up when it is useful but not needed immediately; or pause and ask whether to take the more elegant out-of-scope path or keep the smaller scoped fix.
- When adding buttons or using emojis, ensure the styling remains consistent across WordPress themes and devices by applying explicit classes and theme-resistant CSS (avoid relying on theme defaults).
- For user-facing pages, prefer icons and language-agnostic visual cues; keep text minimal and only when needed. Admin pages can be more verbose.
- For editing UIs, prefer autosaving changes as users work instead of requiring explicit Save clicks; show small inline Saving/Saved status messages and avoid page refreshes after successful saves whenever practical.
- Make all admin and user-facing text translation-ready so tools like Loco Translate can detect it (use WordPress i18n functions with `ll-tools-text-domain`; localize JS strings instead of hardcoding UI copy in scripts).
- Use `ll_enqueue_asset_by_timestamp()` for plugin CSS/JS so `filemtime` versioning stays consistent.
- Prefer `LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`, and `LL_TOOLS_MAIN_FILE` instead of hardcoded paths/URLs.
- Admin pages and AJAX handlers should check `current_user_can('view_ll_tools')` (or stricter) and verify nonces.
- The audio recording shortcode is `[audio_recording_interface]` (the old `[audio_recording]` is not registered).
- Publishing a `words` post may be blocked without published `word_audio` based on category config; follow `ll_tools_get_category_quiz_config()` and `ll_tools_quiz_requires_audio()`.
- Template overrides must respect the loader order in `includes/template-loader.php`.
- After completing a code change, commit it without waiting for a separate prompt; use a clear, scoped commit message that describes the change.
- If the change updates the plugin version, start the commit subject with the new version number (example: `6.0.1 - Fixing such and such...`).
