<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY')) {
    define('LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY', 'll_wordset_translation_language');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY')) {
    define('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY', 'll_wordset_enable_category_translation');
}
if (!defined('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY')) {
    define('LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY', 'll_wordset_category_translation_source');
}
if (!defined('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY')) {
    define('LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY', 'll_wordset_word_title_language_role');
}
if (!defined('LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY')) {
    define('LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY', 'll_wordset_recording_transcription_mode');
}
if (!defined('LL_TOOLS_WORDSET_TRANSCRIPTION_PROVIDER_META_KEY')) {
    define('LL_TOOLS_WORDSET_TRANSCRIPTION_PROVIDER_META_KEY', 'll_wordset_transcription_provider');
}
if (!defined('LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY')) {
    define('LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY', 'll_wordset_local_transcription_endpoint');
}
if (!defined('LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY')) {
    define('LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY', 'll_wordset_local_transcription_target');
}
if (!defined('LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY')) {
    define('LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY', 'll_wordset_offline_stt_bundle_path');
}
if (!defined('LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY')) {
    define('LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY', 'll_wordset_speaking_game_enabled');
}
if (!defined('LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY')) {
    define('LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY', 'll_wordset_speaking_game_provider');
}
if (!defined('LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY')) {
    define('LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY', 'll_wordset_speaking_game_target');
}
if (!defined('LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION')) {
    define('LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION', 'll_tools_wordset_language_settings_migrated_version');
}

function ll_tools_sanitize_wordset_language_setting($value): string {
    return sanitize_text_field((string) $value);
}

function ll_tools_sanitize_wordset_category_translation_source($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['target', 'translation'], true) ? $value : 'target';
}

function ll_tools_sanitize_wordset_title_language_role($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['target', 'translation'], true) ? $value : 'target';
}

function ll_tools_sanitize_wordset_recording_transcription_mode($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['ipa', 'transliteration', 'transcription'], true) ? $value : 'ipa';
}

function ll_tools_sanitize_wordset_transcription_provider($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['assemblyai', 'local_browser'], true) ? $value : '';
}

function ll_tools_sanitize_wordset_local_transcription_target($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['recording_text', 'recording_ipa'], true) ? $value : 'recording_ipa';
}

function ll_tools_sanitize_wordset_speaking_game_target($value): string {
    $value = sanitize_key((string) $value);
    return in_array($value, ['word_title', 'recording_ipa'], true) ? $value : 'word_title';
}

function ll_tools_get_default_local_transcription_endpoint(): string {
    $default = (string) apply_filters(
        'll_tools_default_local_transcription_endpoint',
        'http://127.0.0.1:8765/transcribe'
    );

    $default = trim($default);
    if ($default === '') {
        return '';
    }

    return esc_url_raw($default, ['http', 'https']);
}

function ll_tools_sanitize_wordset_local_transcription_endpoint($value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    return esc_url_raw($value, ['http', 'https']);
}

function ll_tools_sanitize_wordset_offline_stt_bundle_path($value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $quote_chars = ["'", '"'];
    $first_char = substr($value, 0, 1);
    $last_char = substr($value, -1);
    if ($first_char !== '' && $first_char === $last_char && in_array($first_char, $quote_chars, true)) {
        $value = substr($value, 1, -1);
    }

    $value = preg_replace('/[\r\n\t]+/', '', $value);
    $value = str_replace('\\', '/', (string) $value);

    return trim((string) $value);
}

function ll_tools_normalize_wordset_boolean_setting($value): int {
    return absint($value) === 1 ? 1 : 0;
}

/**
 * Normalize a mixed wordset context into unique positive term IDs.
 *
 * @param mixed $wordset_ids
 * @return int[]
 */
function ll_tools_normalize_wordset_setting_ids($wordset_ids): array {
    if ($wordset_ids instanceof WP_Term) {
        $wordset_ids = [$wordset_ids->term_id];
    } elseif (is_numeric($wordset_ids)) {
        $wordset_ids = [(int) $wordset_ids];
    } elseif (is_string($wordset_ids)) {
        $resolved = 0;
        if (function_exists('ll_tools_resolve_wordset_term_id')) {
            $resolved = (int) ll_tools_resolve_wordset_term_id($wordset_ids);
        }
        $wordset_ids = $resolved > 0 ? [$resolved] : [];
    } elseif (!is_array($wordset_ids)) {
        $wordset_ids = [];
    }

    $normalized = [];
    foreach ((array) $wordset_ids as $wordset_id) {
        if ($wordset_id instanceof WP_Term) {
            $wordset_id = (int) $wordset_id->term_id;
        }
        $wordset_id = (int) $wordset_id;
        if ($wordset_id > 0) {
            $normalized[$wordset_id] = true;
        }
    }

    return array_map('intval', array_keys($normalized));
}

/**
 * Get wordset term IDs assigned to a post.
 *
 * @param int $post_id
 * @return int[]
 */
function ll_tools_get_post_wordset_ids(int $post_id): array {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return [];
    }

    $term_ids = wp_get_post_terms($post_id, 'wordset', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || !is_array($term_ids)) {
        return [];
    }

    return ll_tools_normalize_wordset_setting_ids($term_ids);
}

function ll_tools_get_legacy_target_language_setting(): string {
    return ll_tools_sanitize_wordset_language_setting(get_option('ll_target_language', ''));
}

function ll_tools_get_legacy_translation_language_setting(): string {
    return ll_tools_sanitize_wordset_language_setting(get_option('ll_translation_language', ''));
}

function ll_tools_get_legacy_category_translation_enabled_setting(): bool {
    return ll_tools_normalize_wordset_boolean_setting(get_option('ll_enable_category_translation', 0)) === 1;
}

function ll_tools_get_legacy_category_translation_source_setting(): string {
    return ll_tools_sanitize_wordset_category_translation_source(get_option('ll_category_translation_source', 'target'));
}

function ll_tools_get_legacy_word_title_language_role_setting(): string {
    return ll_tools_sanitize_wordset_title_language_role(get_option('ll_word_title_language_role', 'target'));
}

function ll_tools_get_wordset_recording_transcription_mode($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_recording_transcription_mode(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_RECORDING_TRANSCRIPTION_MODE_META_KEY, true)
        );
    }

    return $fallback_to_default ? 'ipa' : '';
}

function ll_tools_get_wordset_transcription_provider($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_TRANSCRIPTION_PROVIDER_META_KEY)) {
            continue;
        }

        return ll_tools_sanitize_wordset_transcription_provider(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSCRIPTION_PROVIDER_META_KEY, true)
        );
    }

    if ($fallback_to_default && function_exists('ll_get_assemblyai_api_key') && ll_get_assemblyai_api_key() !== '') {
        return 'assemblyai';
    }

    return '';
}

function ll_tools_get_wordset_local_transcription_endpoint($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY)) {
            continue;
        }

        return ll_tools_sanitize_wordset_local_transcription_endpoint(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_ENDPOINT_META_KEY, true)
        );
    }

    return $fallback_to_default ? ll_tools_get_default_local_transcription_endpoint() : '';
}

function ll_tools_get_wordset_local_transcription_target($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY)) {
            continue;
        }

        return ll_tools_sanitize_wordset_local_transcription_target(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_LOCAL_TRANSCRIPTION_TARGET_META_KEY, true)
        );
    }

    return $fallback_to_default ? 'recording_ipa' : '';
}

function ll_tools_get_wordset_offline_stt_bundle_path($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY)) {
            continue;
        }

        return ll_tools_sanitize_wordset_offline_stt_bundle_path(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_OFFLINE_STT_BUNDLE_PATH_META_KEY, true)
        );
    }

    return $fallback_to_default ? '' : '';
}

function ll_tools_get_wordset_speaking_game_enabled($wordset_ids = [], bool $fallback_to_default = true): bool {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY)) {
            continue;
        }

        return ll_tools_normalize_wordset_boolean_setting(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_ENABLED_META_KEY, true)
        ) === 1;
    }

    return $fallback_to_default ? false : false;
}

function ll_tools_get_wordset_speaking_game_provider($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY)) {
            continue;
        }

        return ll_tools_sanitize_wordset_transcription_provider(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_PROVIDER_META_KEY, true)
        );
    }

    return ll_tools_get_wordset_transcription_provider($wordset_ids, $fallback_to_default);
}

function ll_tools_get_wordset_speaking_game_target($wordset_ids = [], bool $fallback_to_default = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY)) {
            continue;
        }

        return ll_tools_sanitize_wordset_speaking_game_target(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_SPEAKING_GAME_TARGET_META_KEY, true)
        );
    }

    return $fallback_to_default ? 'word_title' : '';
}

function ll_tools_get_wordset_offline_stt_bundle_config($wordset_ids = [], bool $fallback_to_default = true): array {
    $path = ll_tools_get_wordset_offline_stt_bundle_path($wordset_ids, $fallback_to_default);
    $resolved_path = $path;
    if ($path !== '' && function_exists('ll_tools_offline_app_resolve_source_path')) {
        $resolved_path = ll_tools_offline_app_resolve_source_path($path);
    }
    $exists = ($resolved_path !== '' && (is_file($resolved_path) || is_dir($resolved_path)));
    $type = '';
    if ($exists) {
        $type = is_dir($resolved_path) ? 'directory' : 'file';
    }

    return [
        'path' => $path,
        'configured' => ($path !== ''),
        'exists' => $exists,
        'type' => $type,
        'label' => $exists ? wp_basename($resolved_path) : ($path !== '' ? wp_basename($path) : ''),
    ];
}

function ll_tools_get_wordset_speaking_game_target_label(string $target, $wordset_ids = []): string {
    $target = ll_tools_sanitize_wordset_speaking_game_target($target);

    if ($target === 'recording_ipa') {
        return __('IPA', 'll-tools-text-domain');
    }

    return __('Word title', 'll-tools-text-domain');
}

function ll_tools_get_wordset_speaking_game_target_options(): array {
    return [
        'word_title' => [
            'label' => __('Word title', 'll-tools-text-domain'),
        ],
        'recording_ipa' => [
            'label' => __('IPA', 'll-tools-text-domain'),
        ],
    ];
}

function ll_tools_get_wordset_speaking_game_config($wordset_ids = [], bool $fallback_to_default = true): array {
    $provider = ll_tools_get_wordset_speaking_game_provider($wordset_ids, $fallback_to_default);
    $enabled_flag = ll_tools_get_wordset_speaking_game_enabled($wordset_ids, $fallback_to_default);
    $target = ll_tools_get_wordset_speaking_game_target($wordset_ids, $fallback_to_default);
    $local_endpoint = $provider === 'local_browser'
        ? ll_tools_get_wordset_local_transcription_endpoint($wordset_ids, $fallback_to_default)
        : '';
    $local_result_field = $provider === 'local_browser'
        ? ll_tools_get_wordset_local_transcription_target($wordset_ids, $fallback_to_default)
        : 'recording_text';
    $service_enabled = false;
    if ($provider === 'assemblyai') {
        $service_enabled = function_exists('ll_get_assemblyai_api_key') && ll_get_assemblyai_api_key() !== '';
    } elseif ($provider === 'local_browser') {
        $service_enabled = $local_endpoint !== '';
    }
    $enabled = $enabled_flag && $service_enabled && $target !== '';
    $compatible = true;
    $compatibility_message = '';
    if ($provider === 'assemblyai' && $target === 'recording_ipa') {
        $compatible = false;
        $compatibility_message = __('AssemblyAI returns normal text for this game, so the target must use word title text.', 'll-tools-text-domain');
    } elseif ($provider === 'local_browser') {
        if ($local_result_field === 'recording_ipa' && $target !== 'recording_ipa') {
            $compatible = false;
            $compatibility_message = __('The local STT model is configured to return IPA, so the speaking game target must use the IPA field.', 'll-tools-text-domain');
        } elseif ($local_result_field !== 'recording_ipa' && $target === 'recording_ipa') {
            $compatible = false;
            $compatibility_message = __('The local STT model is configured to return normal text, so the speaking game target must use word title text.', 'll-tools-text-domain');
        }
    }
    $enabled = $enabled && $compatible;

    return [
        'enabled_flag' => $enabled_flag,
        'enabled' => $enabled,
        'provider' => $provider,
        'provider_label' => ll_tools_get_wordset_transcription_provider_label($provider),
        'uses_local_browser' => ($provider === 'local_browser'),
        'local_endpoint' => $local_endpoint,
        'local_result_field' => $provider === 'local_browser' ? $local_result_field : 'recording_text',
        'service_enabled' => $service_enabled,
        'compatible' => $compatible,
        'compatibility_message' => $compatibility_message,
        'target' => $target,
        'target_label' => ll_tools_get_wordset_speaking_game_target_label($target, $wordset_ids),
        'target_options' => ll_tools_get_wordset_speaking_game_target_options(),
    ];
}

function ll_tools_is_wordset_speaking_game_available($wordset_ids = [], bool $fallback_to_default = true): bool {
    $config = ll_tools_get_wordset_speaking_game_config($wordset_ids, $fallback_to_default);
    return !empty($config['enabled']);
}

function ll_tools_get_wordset_speaking_game_target_value(int $word_id, string $target = 'word_title', array $word_data = []): string {
    $word_id = (int) $word_id;
    $target = ll_tools_sanitize_wordset_speaking_game_target($target);

    if ($target === 'recording_ipa') {
        $raw = trim((string) ($word_data['recording_ipa'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        if ($word_id <= 0 || !function_exists('ll_get_prioritized_audio')) {
            return '';
        }

        $audio_posts = get_posts([
            'post_type' => 'word_audio',
            'post_parent' => $word_id,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
            'no_found_rows' => true,
        ]);
        if (empty($audio_posts)) {
            return '';
        }

        $preferred_speaker = function_exists('ll_tools_get_preferred_speaker_from_audio_posts')
            ? ll_tools_get_preferred_speaker_from_audio_posts($audio_posts)
            : 0;
        $prioritized = ll_get_prioritized_audio($audio_posts, $preferred_speaker);
        $candidate_posts = [];
        if ($prioritized instanceof WP_Post) {
            $candidate_posts[] = $prioritized;
        }
        foreach ($audio_posts as $audio_post) {
            if (!($audio_post instanceof WP_Post)) {
                continue;
            }
            if ($prioritized instanceof WP_Post && (int) $audio_post->ID === (int) $prioritized->ID) {
                continue;
            }
            $candidate_posts[] = $audio_post;
        }
        foreach ($candidate_posts as $audio_post) {
            $ipa = trim((string) get_post_meta($audio_post->ID, 'recording_ipa', true));
            if ($ipa !== '') {
                return $ipa;
            }
        }

        return '';
    }

    $title = trim((string) ($word_data['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    if ($word_id > 0) {
        $title = html_entity_decode((string) get_the_title($word_id), ENT_QUOTES, 'UTF-8');
    }

    return trim((string) $title);
}

function ll_tools_get_wordset_transcription_provider_label(string $provider): string {
    $provider = ll_tools_sanitize_wordset_transcription_provider($provider);

    if ($provider === 'local_browser') {
        return __('Local browser model', 'll-tools-text-domain');
    }
    if ($provider === 'assemblyai') {
        return __('AssemblyAI', 'll-tools-text-domain');
    }

    return __('Disabled', 'll-tools-text-domain');
}

function ll_tools_get_wordset_local_transcription_target_label(string $target, $wordset_ids = []): string {
    $target = ll_tools_sanitize_wordset_local_transcription_target($target);

    if ($target === 'recording_ipa') {
        $secondary_label = __('Secondary transcription field', 'll-tools-text-domain');
        if (function_exists('ll_tools_get_wordset_recording_transcription_config')) {
            $config = ll_tools_get_wordset_recording_transcription_config($wordset_ids, true);
            $mode_label = trim((string) ($config['label'] ?? ''));
            if ($mode_label !== '') {
                return sprintf(
                    /* translators: %s: transcription mode label, e.g. IPA */
                    __('Secondary transcription field (%s)', 'll-tools-text-domain'),
                    $mode_label
                );
            }
        }

        return $secondary_label;
    }

    return __('Recording text', 'll-tools-text-domain');
}

function ll_tools_get_wordset_transcription_service_config($wordset_ids = [], bool $fallback_to_default = true): array {
    $provider = ll_tools_get_wordset_transcription_provider($wordset_ids, $fallback_to_default);
    $uses_local_browser = ($provider === 'local_browser');
    $target_field = $uses_local_browser
        ? ll_tools_get_wordset_local_transcription_target($wordset_ids, $fallback_to_default)
        : 'recording_text';
    $local_endpoint = $uses_local_browser
        ? ll_tools_get_wordset_local_transcription_endpoint($wordset_ids, $fallback_to_default)
        : '';

    $enabled = false;
    if ($provider === 'assemblyai') {
        $enabled = function_exists('ll_get_assemblyai_api_key') && ll_get_assemblyai_api_key() !== '';
    } elseif ($uses_local_browser) {
        $enabled = ($local_endpoint !== '');
    }

    return [
        'provider' => $provider,
        'provider_label' => ll_tools_get_wordset_transcription_provider_label($provider),
        'uses_local_browser' => $uses_local_browser,
        'target_field' => $target_field,
        'target_meta_key' => $target_field,
        'target_label' => ll_tools_get_wordset_local_transcription_target_label($target_field, $wordset_ids),
        'local_endpoint' => $local_endpoint,
        'enabled' => $enabled,
    ];
}

function ll_tools_is_wordset_recording_transcription_ipa($wordset_ids = [], bool $fallback_to_default = true): bool {
    return ll_tools_get_wordset_recording_transcription_mode($wordset_ids, $fallback_to_default) === 'ipa';
}

function ll_tools_get_wordset_recording_transcription_config($wordset_ids = [], bool $fallback_to_default = true): array {
    $mode = ll_tools_get_wordset_recording_transcription_mode($wordset_ids, $fallback_to_default);

    $configs = [
        'ipa' => [
            'mode' => 'ipa',
            'label' => __('IPA', 'll-tools-text-domain'),
            'label_with_colon' => __('IPA:', 'll-tools-text-domain'),
            'display_format' => 'brackets',
            'uses_ipa_font' => true,
            'supports_superscript' => true,
            'common_chars' => ['t͡ʃ', 'd͡ʒ', 'ʃ', 'ˈ'],
            'common_chars_label' => __('Common IPA symbols', 'll-tools-text-domain'),
            'wordset_chars_label' => __('Wordset IPA symbols', 'll-tools-text-domain'),
            'special_chars_heading' => __('IPA Special Characters', 'll-tools-text-domain'),
            'special_chars_empty' => __('No IPA symbols found for this word set.', 'll-tools-text-domain'),
            'special_chars_add_label' => __('Add symbols', 'll-tools-text-domain'),
            'special_chars_add_placeholder' => __('e.g. IPA symbols', 'll-tools-text-domain'),
            'special_chars_description' => __('Symbols used in this word set. Update recordings or add new symbols to the keyboard.', 'll-tools-text-domain'),
            'symbols_column_label' => __('IPA', 'll-tools-text-domain'),
            'suggestions_aria_label' => __('IPA suggestions', 'll-tools-text-domain'),
            'keyboard_aria_label' => __('IPA symbols', 'll-tools-text-domain'),
            'map_heading' => __('Letter to IPA Map', 'll-tools-text-domain'),
            'map_description' => __('Mappings inferred from transcriptions. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain'),
            'map_sample_value_label' => __('IPA:', 'll-tools-text-domain'),
            'map_add_symbols_label' => __('IPA symbols', 'll-tools-text-domain'),
            'map_add_symbols_placeholder' => __('IPA symbols (e.g. r)', 'll-tools-text-domain'),
            'map_add_missing' => __('Enter letters and IPA symbols to add.', 'll-tools-text-domain'),
        ],
        'transliteration' => [
            'mode' => 'transliteration',
            'label' => __('Transliteration', 'll-tools-text-domain'),
            'label_with_colon' => __('Transliteration:', 'll-tools-text-domain'),
            'display_format' => 'italic',
            'uses_ipa_font' => false,
            'supports_superscript' => false,
            'common_chars' => ['ʾ', 'ʿ', 'š', 'ś', 'ḥ', 'ṭ', 'ṣ', 'ā', 'ē', 'ī', 'ō', 'ū'],
            'common_chars_label' => __('Common transliteration characters', 'll-tools-text-domain'),
            'wordset_chars_label' => __('Wordset transliteration characters', 'll-tools-text-domain'),
            'special_chars_heading' => __('Transliteration Characters', 'll-tools-text-domain'),
            'special_chars_empty' => __('No transliteration characters found for this word set.', 'll-tools-text-domain'),
            'special_chars_add_label' => __('Add characters', 'll-tools-text-domain'),
            'special_chars_add_placeholder' => __('e.g. ḥ š ʾ ā', 'll-tools-text-domain'),
            'special_chars_description' => __('Characters used in this word set’s transliterations. Update recordings or add new characters to the keyboard.', 'll-tools-text-domain'),
            'symbols_column_label' => __('Transliteration', 'll-tools-text-domain'),
            'suggestions_aria_label' => __('Transliteration suggestions', 'll-tools-text-domain'),
            'keyboard_aria_label' => __('Transliteration characters', 'll-tools-text-domain'),
            'map_heading' => __('Letter to Transliteration Map', 'll-tools-text-domain'),
            'map_description' => __('Mappings inferred from this word set’s transliterations. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain'),
            'map_sample_value_label' => __('Transliteration:', 'll-tools-text-domain'),
            'map_add_symbols_label' => __('Transliteration characters', 'll-tools-text-domain'),
            'map_add_symbols_placeholder' => __('Characters (e.g. ḥ š)', 'll-tools-text-domain'),
            'map_add_missing' => __('Enter letters and transliteration characters to add.', 'll-tools-text-domain'),
        ],
        'transcription' => [
            'mode' => 'transcription',
            'label' => __('Transcription', 'll-tools-text-domain'),
            'label_with_colon' => __('Transcription:', 'll-tools-text-domain'),
            'display_format' => 'plain',
            'uses_ipa_font' => false,
            'supports_superscript' => false,
            'common_chars' => [],
            'common_chars_label' => __('Common transcription characters', 'll-tools-text-domain'),
            'wordset_chars_label' => __('Wordset transcription characters', 'll-tools-text-domain'),
            'special_chars_heading' => __('Transcription Characters', 'll-tools-text-domain'),
            'special_chars_empty' => __('No transcription characters found for this word set.', 'll-tools-text-domain'),
            'special_chars_add_label' => __('Add characters', 'll-tools-text-domain'),
            'special_chars_add_placeholder' => __('e.g. special characters', 'll-tools-text-domain'),
            'special_chars_description' => __('Characters used in this word set’s transcription field. Update recordings or add new characters to the keyboard.', 'll-tools-text-domain'),
            'symbols_column_label' => __('Transcription', 'll-tools-text-domain'),
            'suggestions_aria_label' => __('Transcription suggestions', 'll-tools-text-domain'),
            'keyboard_aria_label' => __('Transcription characters', 'll-tools-text-domain'),
            'map_heading' => __('Letter to Transcription Map', 'll-tools-text-domain'),
            'map_description' => __('Mappings inferred from this word set’s transcription field. Add manual overrides to fix suggestion mistakes.', 'll-tools-text-domain'),
            'map_sample_value_label' => __('Transcription:', 'll-tools-text-domain'),
            'map_add_symbols_label' => __('Transcription characters', 'll-tools-text-domain'),
            'map_add_symbols_placeholder' => __('Characters (e.g. special characters)', 'll-tools-text-domain'),
            'map_add_missing' => __('Enter letters and transcription characters to add.', 'll-tools-text-domain'),
        ],
    ];

    if (!isset($configs[$mode])) {
        $mode = 'ipa';
    }

    return $configs[$mode];
}

/**
 * Resolve the target language label/code for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_target_language($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        $value = ll_tools_sanitize_wordset_language_setting(get_term_meta($wordset_id, 'll_language', true));
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_target_language_setting() : '';
}

/**
 * Resolve the translation/helper language label/code for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_translation_language($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_language_setting(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true)
        );
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_translation_language_setting() : '';
}

/**
 * Resolve the configured word-title language role for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_title_language_role($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_title_language_role(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, true)
        );
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_word_title_language_role_setting() : 'target';
}

/**
 * Resolve the category translation source direction for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return string
 */
function ll_tools_get_wordset_category_translation_source($wordset_ids = [], bool $fallback_to_legacy = true): string {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY)) {
            continue;
        }
        return ll_tools_sanitize_wordset_category_translation_source(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, true)
        );
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_category_translation_source_setting() : 'target';
}

/**
 * Resolve whether category name translations are enabled for a wordset.
 *
 * @param mixed $wordset_ids
 * @param bool  $fallback_to_legacy
 * @return bool
 */
function ll_tools_is_wordset_category_translation_enabled($wordset_ids = [], bool $fallback_to_legacy = true): bool {
    $ids = ll_tools_normalize_wordset_setting_ids($wordset_ids);
    foreach ($ids as $wordset_id) {
        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY)) {
            continue;
        }
        return ll_tools_normalize_wordset_boolean_setting(
            get_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, true)
        ) === 1;
    }

    return $fallback_to_legacy ? ll_tools_get_legacy_category_translation_enabled_setting() : false;
}

/**
 * Determine whether the current locale should display translated category names.
 *
 * @param mixed       $wordset_ids
 * @param string|null $site_language
 * @return bool
 */
function ll_tools_should_use_category_translations($wordset_ids = [], ?string $site_language = null): bool {
    $target_language = strtolower((string) ll_tools_get_wordset_translation_language($wordset_ids));
    $site_language = strtolower((string) ($site_language ?? get_locale()));

    return ll_tools_is_wordset_category_translation_enabled($wordset_ids)
        && $target_language !== ''
        && strpos($site_language, $target_language) === 0;
}

/**
 * Resolve the language used in word titles for a wordset.
 *
 * @param mixed $wordset_ids
 * @return string
 */
function ll_tools_get_wordset_title_language_label($wordset_ids = []): string {
    $title_role = ll_tools_get_wordset_title_language_role($wordset_ids);
    if ($title_role === 'translation') {
        return ll_tools_get_wordset_translation_language($wordset_ids);
    }

    return ll_tools_get_wordset_target_language($wordset_ids);
}

function ll_tools_should_show_category_translation_ui(): bool {
    if (ll_tools_get_legacy_category_translation_enabled_setting()) {
        return true;
    }

    $enabled_wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'fields'     => 'ids',
        'number'     => 1,
        'meta_query' => [
            [
                'key'     => LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY,
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ]);

    return !is_wp_error($enabled_wordsets) && !empty($enabled_wordsets);
}

function ll_tools_translate_missing_category_names(string $source_language, string $target_language): array {
    $source_language = ll_tools_sanitize_wordset_language_setting($source_language);
    $target_language = ll_tools_sanitize_wordset_language_setting($target_language);

    $results = [
        'success' => [],
        'errors' => [],
        'source_language' => $source_language,
        'target_language' => $target_language,
    ];

    if ($source_language === '' || $target_language === '' || !function_exists('translate_with_deepl')) {
        return $results;
    }

    $categories = get_terms([
        'taxonomy' => 'word-category',
        'hide_empty' => false,
    ]);
    if (is_wp_error($categories) || empty($categories)) {
        return $results;
    }

    foreach ($categories as $category) {
        if (!($category instanceof WP_Term)) {
            continue;
        }

        $translation = get_term_meta($category->term_id, 'term_translation', true);
        if (!empty($translation)) {
            continue;
        }

        $translated_name = translate_with_deepl($category->name, $target_language, $source_language);
        if ($translated_name) {
            update_term_meta($category->term_id, 'term_translation', $translated_name);
            $results['success'][] = [
                'original' => $category->name,
                'translated' => $translated_name,
            ];
            continue;
        }

        $results['errors'][] = [
            'original' => $category->name,
            'error' => sprintf(
                /* translators: 1: category ID, 2: category name */
                __('Translation failed for category ID %1$d (%2$s).', 'll-tools-text-domain'),
                (int) $category->term_id,
                (string) $category->name
            ),
        ];
    }

    return $results;
}

function ll_tools_store_category_translation_results_notice(array $results): void {
    set_transient('ll_translation_results', $results, 60);
}

function ll_tools_auto_translate_categories_for_wordset(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0 || !ll_tools_is_wordset_category_translation_enabled([$wordset_id])) {
        return [
            'success' => [],
            'errors' => [],
            'source_language' => '',
            'target_language' => '',
        ];
    }

    $source_language = ll_tools_get_wordset_target_language([$wordset_id]);
    $target_language = ll_tools_get_wordset_translation_language([$wordset_id]);
    if (ll_tools_get_wordset_category_translation_source([$wordset_id]) === 'translation') {
        $source_language = ll_tools_get_wordset_translation_language([$wordset_id]);
        $target_language = ll_tools_get_wordset_target_language([$wordset_id]);
    }

    $results = ll_tools_translate_missing_category_names($source_language, $target_language);
    ll_tools_store_category_translation_results_notice($results);

    return $results;
}

function ll_tools_migrate_legacy_language_settings_to_wordsets(): array {
    $wordset_ids = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'fields' => 'ids',
    ]);
    if (is_wp_error($wordset_ids) || !is_array($wordset_ids)) {
        return [
            'wordsets' => 0,
            'migrated' => [],
        ];
    }

    $legacy = [
        'target_language' => ll_tools_get_legacy_target_language_setting(),
        'translation_language' => ll_tools_get_legacy_translation_language_setting(),
        'category_translation_enabled' => ll_tools_get_legacy_category_translation_enabled_setting() ? '1' : '0',
        'category_translation_source' => ll_tools_get_legacy_category_translation_source_setting(),
        'title_language_role' => ll_tools_get_legacy_word_title_language_role_setting(),
    ];

    $migrated = [
        'target_language' => 0,
        'translation_language' => 0,
        'category_translation_enabled' => 0,
        'category_translation_source' => 0,
        'title_language_role' => 0,
    ];

    foreach ($wordset_ids as $wordset_id) {
        $wordset_id = (int) $wordset_id;
        if ($wordset_id <= 0) {
            continue;
        }

        if ($legacy['target_language'] !== '' && ll_tools_sanitize_wordset_language_setting(get_term_meta($wordset_id, 'll_language', true)) === '') {
            update_term_meta($wordset_id, 'll_language', $legacy['target_language']);
            $migrated['target_language']++;
        }

        if ($legacy['translation_language'] !== '' && ll_tools_sanitize_wordset_language_setting(get_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, true)) === '') {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_TRANSLATION_LANGUAGE_META_KEY, $legacy['translation_language']);
            $migrated['translation_language']++;
        }

        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY)) {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_ENABLED_META_KEY, $legacy['category_translation_enabled']);
            $migrated['category_translation_enabled']++;
        }

        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY)) {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_CATEGORY_TRANSLATION_SOURCE_META_KEY, $legacy['category_translation_source']);
            $migrated['category_translation_source']++;
        }

        if (!metadata_exists('term', $wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY)) {
            update_term_meta($wordset_id, LL_TOOLS_WORDSET_WORD_TITLE_LANGUAGE_ROLE_META_KEY, $legacy['title_language_role']);
            $migrated['title_language_role']++;
        }
    }

    return [
        'wordsets' => count($wordset_ids),
        'migrated' => $migrated,
    ];
}

function ll_tools_maybe_migrate_legacy_language_settings_to_wordsets(): void {
    if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    if (!(current_user_can('view_ll_tools') || current_user_can('manage_options'))) {
        return;
    }

    $current_version = defined('LL_TOOLS_VERSION') ? (string) LL_TOOLS_VERSION : '';
    if ($current_version === '') {
        return;
    }

    $completed_version = (string) get_option(LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION, '');
    if ($completed_version !== '' && version_compare($completed_version, $current_version, '>=')) {
        return;
    }

    ll_tools_migrate_legacy_language_settings_to_wordsets();
    update_option(LL_TOOLS_WORDSET_LANGUAGE_SETTINGS_MIGRATION_OPTION, $current_version, false);
}
add_action('admin_init', 'll_tools_maybe_migrate_legacy_language_settings_to_wordsets', 11);
