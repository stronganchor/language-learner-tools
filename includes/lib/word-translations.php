<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_WORD_TRANSLATIONS_META_KEY')) {
    define('LL_TOOLS_WORD_TRANSLATIONS_META_KEY', 'll_word_translations');
}
if (!defined('LL_TOOLS_WORD_TARGET_TEXT_META_KEY')) {
    define('LL_TOOLS_WORD_TARGET_TEXT_META_KEY', 'll_word_target_text');
}

function ll_tools_decode_word_text_value($value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('ll_tools_decode_display_entities')) {
        return trim(ll_tools_decode_display_entities($value));
    }

    return trim(html_entity_decode($value, ENT_QUOTES, 'UTF-8'));
}

function ll_tools_sanitize_word_target_text($value): string {
    $value = function_exists('ll_tools_strip_display_word_joiners')
        ? ll_tools_strip_display_word_joiners((string) $value)
        : (string) $value;

    return function_exists('ll_sanitize_word_title_text')
        ? ll_sanitize_word_title_text($value)
        : trim(sanitize_text_field($value));
}

function ll_tools_normalize_translation_locale($locale): string {
    $locale = trim((string) $locale);
    if ($locale === '') {
        return '';
    }

    $lower = strtolower(str_replace('_', '-', $locale));
    $name_map = [
        'english' => 'en',
        'eng' => 'en',
        'turkish' => 'tr',
        'turkce' => 'tr',
        'german' => 'de',
        'deutsch' => 'de',
        'almanca' => 'de',
        'french' => 'fr',
        'francais' => 'fr',
        'spanish' => 'es',
        'espanol' => 'es',
    ];
    if (isset($name_map[$lower])) {
        return $name_map[$lower];
    }

    $code = sanitize_key($lower);
    $code = str_replace('_', '-', $code);
    if ($code === '') {
        return '';
    }

    return substr($code, 0, 32);
}

function ll_tools_sanitize_word_translation_text($value): string {
    return trim(sanitize_text_field((string) $value));
}

function ll_tools_word_text_values_match(string $left, string $right): bool {
    $left = ll_tools_decode_word_text_value($left);
    $right = ll_tools_decode_word_text_value($right);
    if ($left === '' || $right === '') {
        return $left === $right;
    }

    return $left === $right;
}

function ll_tools_get_wordset_title_role_for_word(int $word_id, ?array $wordset_ids = null): string {
    $word_id = (int) $word_id;
    if ($wordset_ids === null) {
        $wordset_terms = $word_id > 0 ? wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']) : [];
        $wordset_ids = is_wp_error($wordset_terms) ? [] : array_values(array_map('intval', (array) $wordset_terms));
    }

    return function_exists('ll_tools_get_wordset_title_language_role')
        ? ll_tools_get_wordset_title_language_role($wordset_ids, true)
        : sanitize_key((string) get_option('ll_word_title_language_role', 'target'));
}

function ll_tools_get_legacy_word_text_parts_from_values(string $raw_post_title, string $raw_word_translation, string $raw_legacy_translation, string $title_role): array {
    $raw_post_title = ll_tools_decode_word_text_value($raw_post_title);
    $raw_word_translation = ll_tools_decode_word_text_value($raw_word_translation);
    $raw_legacy_translation = ll_tools_decode_word_text_value($raw_legacy_translation);
    $title_role = sanitize_key($title_role) === 'translation' ? 'translation' : 'target';

    $translation_meta_matches_legacy = (
        $raw_word_translation !== ''
        && $raw_legacy_translation !== ''
        && ll_tools_word_text_values_match($raw_word_translation, $raw_legacy_translation)
    );
    $title_matches_legacy = (
        $raw_post_title !== ''
        && $raw_legacy_translation !== ''
        && ll_tools_word_text_values_match($raw_post_title, $raw_legacy_translation)
    );

    if ($title_role === 'translation') {
        if ($raw_word_translation !== '' && !$translation_meta_matches_legacy) {
            $word_text = $raw_word_translation;
            $translation_text = $raw_legacy_translation !== '' ? $raw_legacy_translation : $raw_post_title;
        } else {
            $word_text = $raw_word_translation !== '' ? $raw_post_title : '';
            $translation_text = $raw_word_translation !== '' ? $raw_word_translation : $raw_legacy_translation;
        }
    } else {
        $word_text = $raw_post_title;
        $translation_text = $raw_word_translation !== '' ? $raw_word_translation : $raw_legacy_translation;
    }

    return [
        'raw_title' => $raw_post_title,
        'raw_word_translation' => $raw_word_translation,
        'raw_legacy_translation' => $raw_legacy_translation,
        'word_text' => $word_text,
        'translation_text' => $translation_text,
        'title_role' => $title_role,
    ];
}

function ll_tools_get_legacy_word_text_parts(int $word_id, ?array $wordset_ids = null): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return [
            'raw_title' => '',
            'raw_word_translation' => '',
            'raw_legacy_translation' => '',
            'word_text' => '',
            'translation_text' => '',
            'title_role' => 'target',
        ];
    }

    return ll_tools_get_legacy_word_text_parts_from_values(
        (string) get_post_field('post_title', $word_id),
        (string) get_post_meta($word_id, 'word_translation', true),
        (string) get_post_meta($word_id, 'word_english_meaning', true),
        ll_tools_get_wordset_title_role_for_word($word_id, $wordset_ids)
    );
}

function ll_tools_get_word_target_text(int $word_id, bool $fallback_to_legacy = true, ?array $wordset_ids = null): string {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return '';
    }

    $target_text = ll_tools_decode_word_text_value(get_post_meta($word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY, true));
    if ($target_text !== '' || !$fallback_to_legacy) {
        return $target_text;
    }

    $legacy = ll_tools_get_legacy_word_text_parts($word_id, $wordset_ids);
    return trim((string) ($legacy['word_text'] ?? ''));
}

function ll_tools_get_legacy_default_translation_text(int $word_id, string $canonical_target_text = '', ?array $wordset_ids = null): string {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return '';
    }

    $canonical_target_text = ll_tools_decode_word_text_value($canonical_target_text);
    if ($canonical_target_text !== '') {
        $legacy_word_translation = ll_tools_decode_word_text_value(get_post_meta($word_id, 'word_translation', true));
        $legacy_translation = ll_tools_decode_word_text_value(get_post_meta($word_id, 'word_english_meaning', true));
        if (ll_tools_word_translation_meta_stores_default_translation($word_id)) {
            if ($legacy_word_translation !== '' && !ll_tools_word_text_values_match($legacy_word_translation, $canonical_target_text)) {
                return $legacy_word_translation;
            }
            return $legacy_translation;
        }

        if ($legacy_translation !== '') {
            return $legacy_translation;
        }

        if ($legacy_word_translation !== '' && !ll_tools_word_text_values_match($legacy_word_translation, $canonical_target_text)) {
            return $legacy_word_translation;
        }

        return '';
    }

    $legacy = ll_tools_get_legacy_word_text_parts($word_id, $wordset_ids);
    return trim((string) ($legacy['translation_text'] ?? ''));
}

function ll_tools_update_word_target_text(int $word_id, string $target_text, bool $mirror_post_title = true) {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return new WP_Error('ll_tools_invalid_word_target', __('Invalid word ID.', 'll-tools-text-domain'));
    }

    $target_text = ll_tools_sanitize_word_target_text($target_text);
    if ($target_text === '') {
        delete_post_meta($word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY);
    } else {
        update_post_meta($word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY, $target_text);
    }

    if ($mirror_post_title && $target_text !== '') {
        return wp_update_post([
            'ID' => $word_id,
            'post_title' => $target_text,
        ], true);
    }

    return true;
}

function ll_tools_normalize_word_translation_map($translations): array {
    if (is_string($translations)) {
        $decoded = json_decode($translations, true);
        $translations = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($translations)) {
        return [];
    }

    $normalized = [];
    foreach ($translations as $locale => $text) {
        $locale = ll_tools_normalize_translation_locale($locale);
        if ($locale === '') {
            continue;
        }

        if (is_array($text)) {
            $text = $text['text'] ?? '';
        }

        $text = ll_tools_sanitize_word_translation_text($text);
        if ($text === '') {
            continue;
        }

        $normalized[$locale] = $text;
    }

    ksort($normalized, SORT_NATURAL);
    return $normalized;
}

function ll_tools_get_word_translation_map(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    return ll_tools_normalize_word_translation_map(
        get_post_meta($word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY, true)
    );
}

function ll_tools_update_word_translation_map(int $word_id, array $translations): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $translations = ll_tools_normalize_word_translation_map($translations);
    if (empty($translations)) {
        delete_post_meta($word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY);
        return [];
    }

    update_post_meta($word_id, LL_TOOLS_WORD_TRANSLATIONS_META_KEY, $translations);
    ll_tools_maybe_mirror_default_translation_to_legacy_meta($word_id, $translations);
    return $translations;
}

function ll_tools_update_word_translation_for_locale(int $word_id, string $locale, string $text): array {
    $word_id = (int) $word_id;
    $locale = ll_tools_normalize_translation_locale($locale);
    if ($word_id <= 0 || $locale === '') {
        return ll_tools_get_word_translation_map($word_id);
    }

    $translations = ll_tools_get_word_translation_map($word_id);
    $text = ll_tools_sanitize_word_translation_text($text);
    if ($text === '') {
        unset($translations[$locale]);
    } else {
        $translations[$locale] = $text;
    }

    return ll_tools_update_word_translation_map($word_id, $translations);
}

function ll_tools_get_word_translation_for_locale(int $word_id, string $locale, bool $fallback_to_legacy = true): string {
    $locale = ll_tools_normalize_translation_locale($locale);
    if ($locale !== '') {
        $translations = $fallback_to_legacy
            ? ll_tools_get_effective_word_translation_map($word_id)
            : ll_tools_get_word_translation_map($word_id);
        if (array_key_exists($locale, $translations)) {
            return (string) $translations[$locale];
        }
    }

    if (!$fallback_to_legacy) {
        return '';
    }

    return ll_tools_get_legacy_default_translation_text($word_id, ll_tools_get_word_target_text($word_id, false));
}

function ll_tools_get_effective_word_translation_map(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $translations = ll_tools_get_word_translation_map($word_id);
    $locale = ll_tools_get_default_translation_locale_for_word($word_id);
    if ($locale !== '' && !isset($translations[$locale])) {
        $legacy = ll_tools_get_legacy_default_translation_text($word_id, ll_tools_get_word_target_text($word_id, false));
        if ($legacy !== '') {
            $translations[$locale] = $legacy;
        }
    }

    ksort($translations, SORT_NATURAL);
    return $translations;
}

function ll_tools_get_default_translation_locale_for_word(int $word_id): string {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return '';
    }

    $wordset_terms = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    $wordset_ids = is_wp_error($wordset_terms) ? [] : array_values(array_map('intval', (array) $wordset_terms));
    $language = function_exists('ll_tools_get_wordset_translation_language')
        ? (string) ll_tools_get_wordset_translation_language($wordset_ids, true)
        : '';

    return ll_tools_normalize_translation_locale($language);
}

function ll_tools_update_word_default_translation_locale(int $word_id, string $text): array {
    $locale = ll_tools_get_default_translation_locale_for_word($word_id);
    if ($locale === '') {
        return ll_tools_get_word_translation_map($word_id);
    }

    return ll_tools_update_word_translation_for_locale($word_id, $locale, $text);
}

function ll_tools_default_translation_legacy_mirror_guard(?bool $set = true): bool {
    static $guard = false;
    $previous = $guard;
    if ($set === null) {
        return $previous;
    }

    $guard = $set;
    return $previous;
}

function ll_tools_maybe_mirror_default_translation_to_legacy_meta(int $word_id, array $translations): void {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || !ll_tools_word_translation_meta_stores_default_translation($word_id)) {
        return;
    }

    $locale = ll_tools_get_default_translation_locale_for_word($word_id);
    if ($locale === '' || !array_key_exists($locale, $translations)) {
        return;
    }

    $text = ll_tools_sanitize_word_translation_text((string) $translations[$locale]);
    $previous = ll_tools_default_translation_legacy_mirror_guard(true);
    try {
        if ($text === '') {
            delete_post_meta($word_id, 'word_translation');
        } else {
            update_post_meta($word_id, 'word_translation', $text);
        }
    } finally {
        ll_tools_default_translation_legacy_mirror_guard($previous);
    }
}

function ll_tools_update_word_default_translation_text(int $word_id, string $text, bool $mirror_legacy = true): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $text = ll_tools_sanitize_word_translation_text($text);
    $translations = ll_tools_update_word_default_translation_locale($word_id, $text);
    if (!$mirror_legacy || !ll_tools_word_translation_meta_stores_default_translation($word_id)) {
        return $translations;
    }

    $previous = ll_tools_default_translation_legacy_mirror_guard(true);
    try {
        if ($text === '') {
            delete_post_meta($word_id, 'word_translation');
        } else {
            update_post_meta($word_id, 'word_translation', $text);
        }
    } finally {
        ll_tools_default_translation_legacy_mirror_guard($previous);
    }

    return $translations;
}

function ll_tools_get_word_text_parts(int $word_id, ?string $translation_locale = null, bool $fallback_to_legacy = true, ?array $wordset_ids = null): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0 || get_post_type($word_id) !== 'words') {
        return [
            'raw_title' => '',
            'word_text' => '',
            'target_text' => '',
            'translation_text' => '',
            'translations' => [],
            'default_translation_locale' => '',
            'translation_locale' => '',
            'store_in_title' => true,
        ];
    }

    $legacy = $fallback_to_legacy ? ll_tools_get_legacy_word_text_parts($word_id, $wordset_ids) : [
        'raw_title' => ll_tools_decode_word_text_value(get_post_field('post_title', $word_id)),
        'word_text' => '',
        'translation_text' => '',
        'title_role' => ll_tools_get_wordset_title_role_for_word($word_id, $wordset_ids),
    ];
    $target_text = ll_tools_get_word_target_text($word_id, $fallback_to_legacy, $wordset_ids);
    $translations = $fallback_to_legacy
        ? ll_tools_get_effective_word_translation_map($word_id)
        : ll_tools_get_word_translation_map($word_id);

    $default_locale = ll_tools_get_default_translation_locale_for_word($word_id);
    $locale = ll_tools_normalize_translation_locale((string) ($translation_locale ?? ''));
    if ($locale === '') {
        $locale = $default_locale;
    }

    $translation_text = '';
    if ($locale !== '' && array_key_exists($locale, $translations)) {
        $translation_text = (string) $translations[$locale];
    }
    if ($translation_text === '' && $fallback_to_legacy) {
        $translation_text = ll_tools_get_legacy_default_translation_text($word_id, $target_text, $wordset_ids);
    }
    $has_canonical_target = metadata_exists('post', $word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY);

    return [
        'raw_title' => (string) ($legacy['raw_title'] ?? ll_tools_decode_word_text_value(get_post_field('post_title', $word_id))),
        'word_text' => $target_text,
        'target_text' => $target_text,
        'translation_text' => $translation_text,
        'translations' => $translations,
        'default_translation_locale' => $default_locale,
        'translation_locale' => $locale,
        'store_in_title' => $has_canonical_target || (sanitize_key((string) ($legacy['title_role'] ?? 'target')) !== 'translation'),
    ];
}

function ll_tools_word_translation_meta_stores_default_translation(int $word_id): bool {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return true;
    }

    if (metadata_exists('post', $word_id, LL_TOOLS_WORD_TARGET_TEXT_META_KEY)) {
        return true;
    }

    $wordset_terms = wp_get_post_terms($word_id, 'wordset', ['fields' => 'ids']);
    $wordset_ids = is_wp_error($wordset_terms) ? [] : array_values(array_map('intval', (array) $wordset_terms));
    $title_role = function_exists('ll_tools_get_wordset_title_language_role')
        ? ll_tools_get_wordset_title_language_role($wordset_ids, true)
        : 'target';

    return $title_role === 'target';
}

function ll_tools_sync_default_translation_locale_from_legacy_meta($meta_id, int $object_id, string $meta_key, $_meta_value): void {
    if ($meta_key !== 'word_translation') {
        return;
    }

    if (ll_tools_default_translation_legacy_mirror_guard(null)) {
        return;
    }

    $post = get_post($object_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'words') {
        return;
    }

    if (!ll_tools_word_translation_meta_stores_default_translation($object_id)) {
        return;
    }

    ll_tools_update_word_default_translation_locale($object_id, (string) $_meta_value);
}
add_action('added_post_meta', 'll_tools_sync_default_translation_locale_from_legacy_meta', 10, 4);
add_action('updated_post_meta', 'll_tools_sync_default_translation_locale_from_legacy_meta', 10, 4);

function ll_tools_remove_default_translation_locale_after_legacy_meta_delete($meta_ids, int $object_id, string $meta_key, $_meta_value): void {
    if ($meta_key !== 'word_translation') {
        return;
    }

    if (ll_tools_default_translation_legacy_mirror_guard(null)) {
        return;
    }

    $post = get_post($object_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'words') {
        return;
    }

    if (!ll_tools_word_translation_meta_stores_default_translation($object_id)) {
        return;
    }

    $locale = ll_tools_get_default_translation_locale_for_word($object_id);
    if ($locale === '') {
        return;
    }

    ll_tools_update_word_translation_for_locale($object_id, $locale, '');
}
add_action('deleted_post_meta', 'll_tools_remove_default_translation_locale_after_legacy_meta_delete', 10, 4);
