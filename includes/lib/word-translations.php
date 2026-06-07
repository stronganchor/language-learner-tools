<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_WORD_TRANSLATIONS_META_KEY')) {
    define('LL_TOOLS_WORD_TRANSLATIONS_META_KEY', 'll_word_translations');
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

    $legacy = trim((string) get_post_meta($word_id, 'word_translation', true));
    if ($legacy !== '') {
        return $legacy;
    }

    return trim((string) get_post_meta($word_id, 'word_english_meaning', true));
}

function ll_tools_get_effective_word_translation_map(int $word_id): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $translations = ll_tools_get_word_translation_map($word_id);
    $locale = ll_tools_get_default_translation_locale_for_word($word_id);
    if ($locale !== '' && !isset($translations[$locale])) {
        $legacy = '';
        if (ll_tools_word_translation_meta_stores_default_translation($word_id)) {
            $legacy = trim((string) get_post_meta($word_id, 'word_translation', true));
        }
        if ($legacy === '') {
            $legacy = trim((string) get_post_meta($word_id, 'word_english_meaning', true));
        }
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

function ll_tools_word_translation_meta_stores_default_translation(int $word_id): bool {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
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
