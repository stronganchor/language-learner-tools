See `CODEBASE_ARCHITECTURE.md` for the canonical map of entry points, flows, and invariants for this plugin.

Codebase-specific guidelines:
- When adding buttons or using emojis, ensure the styling remains consistent across WordPress themes and devices by applying explicit classes and theme-resistant CSS (avoid relying on theme defaults).
- For user-facing pages, prefer icons and language-agnostic visual cues; keep text minimal and only when needed. Admin pages can be more verbose.
- Make all admin and user-facing text translation-ready so tools like Loco Translate can detect it (use WordPress i18n functions with `ll-tools-text-domain`; localize JS strings instead of hardcoding UI copy in scripts).
- Use `ll_enqueue_asset_by_timestamp()` for plugin CSS/JS so `filemtime` versioning stays consistent.
- Prefer `LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`, and `LL_TOOLS_MAIN_FILE` instead of hardcoded paths/URLs.
- Admin pages and AJAX handlers should check `current_user_can('view_ll_tools')` (or stricter) and verify nonces.
- The audio recording shortcode is `[audio_recording_interface]` (the old `[audio_recording]` is not registered).
- Publishing a `words` post may be blocked without published `word_audio` based on category config; follow `ll_tools_get_category_quiz_config()` and `ll_tools_quiz_requires_audio()`.
- Template overrides must respect the loader order in `includes/template-loader.php`.
