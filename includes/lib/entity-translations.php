<?php
if (!defined('WPINC')) {
    die;
}

if (!defined('LL_TOOLS_ENTITY_TRANSLATIONS_META_KEY')) {
    define('LL_TOOLS_ENTITY_TRANSLATIONS_META_KEY', 'll_tools_entity_translations');
}

function ll_tools_normalize_entity_translation_locale($locale): string {
    $locale = trim((string) $locale);
    if ($locale === '') {
        return '';
    }

    $locale = str_replace('-', '_', $locale);
    $locale = preg_replace('/[^A-Za-z0-9_]/', '', $locale);
    if (!is_string($locale) || $locale === '') {
        return '';
    }

    $parts = array_values(array_filter(explode('_', $locale), 'strlen'));
    if (empty($parts)) {
        return '';
    }

    $language = strtolower((string) array_shift($parts));
    if (!preg_match('/^[a-z]{2,3}$/', $language)) {
        return '';
    }

    $normalized = [$language];
    foreach ($parts as $part) {
        $part = (string) $part;
        if (preg_match('/^[A-Za-z]{2}$/', $part)) {
            $normalized[] = strtoupper($part);
            continue;
        }
        if (preg_match('/^[A-Za-z]{4}$/', $part)) {
            $normalized[] = ucfirst(strtolower($part));
            continue;
        }
        if (preg_match('/^[A-Za-z0-9]{3,16}$/', $part)) {
            $normalized[] = strtolower($part);
        }
    }

    return implode('_', $normalized);
}

function ll_tools_current_entity_translation_locale(): string {
    $locale = function_exists('determine_locale') ? (string) determine_locale() : '';
    if ($locale === '' && function_exists('get_locale')) {
        $locale = (string) get_locale();
    }

    return ll_tools_normalize_entity_translation_locale($locale);
}

/**
 * @param string|string[] $extra_locales
 * @return string[]
 */
function ll_tools_entity_translation_locale_candidates($extra_locales = []): array {
    $raw_locales = [];
    foreach ((array) $extra_locales as $locale) {
        if (is_scalar($locale)) {
            $raw_locales[] = (string) $locale;
        }
    }
    $raw_locales[] = ll_tools_current_entity_translation_locale();

    $candidates = [];
    foreach ($raw_locales as $raw_locale) {
        $locale = ll_tools_normalize_entity_translation_locale($raw_locale);
        if ($locale === '') {
            continue;
        }

        $candidates[$locale] = true;
        $language = strtok($locale, '_');
        if (is_string($language) && $language !== '') {
            $candidates[$language] = true;
        }
    }

    return array_keys($candidates);
}

function ll_tools_normalize_entity_translation_object_type($object_type): string {
    $object_type = sanitize_key((string) $object_type);
    return in_array($object_type, ['post', 'term'], true) ? $object_type : '';
}

function ll_tools_normalize_entity_translation_field($field): string {
    $field = sanitize_key((string) $field);
    $aliases = [
        'blurb' => 'profile_blurb',
        'intro_blurb' => 'profile_blurb',
        'label' => 'name',
        'lesson_name' => 'title',
        'lesson_title' => 'title',
    ];
    if (isset($aliases[$field])) {
        $field = $aliases[$field];
    }

    return in_array($field, ['name', 'title', 'profile_blurb', 'excerpt'], true) ? $field : '';
}

function ll_tools_sanitize_entity_translation_text($field, $value): string {
    $field = ll_tools_normalize_entity_translation_field($field);
    if ($field === '') {
        return '';
    }

    if ($field === 'profile_blurb' && function_exists('ll_tools_sanitize_wordset_profile_blurb')) {
        return ll_tools_sanitize_wordset_profile_blurb((string) $value);
    }

    if (in_array($field, ['profile_blurb', 'excerpt'], true)) {
        $text = sanitize_textarea_field((string) $value);
        $text = preg_replace("/[ \t]+\r?\n/", "\n", $text);
        $text = preg_replace("/\r\n?/", "\n", (string) $text);
        $text = trim((string) $text);
        $limit = $field === 'profile_blurb' ? 2400 : 1200;

        return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    }

    $text = trim(sanitize_text_field((string) $value));
    return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
}

/**
 * @param mixed $raw
 * @return array<string,array<string,string>>
 */
function ll_tools_normalize_entity_translations($raw): array {
    if (!is_array($raw)) {
        return [];
    }

    $translations = [];
    foreach ($raw as $locale => $fields) {
        $locale = ll_tools_normalize_entity_translation_locale($locale);
        if ($locale === '' || !is_array($fields)) {
            continue;
        }

        foreach ($fields as $field => $value) {
            $field = ll_tools_normalize_entity_translation_field($field);
            if ($field === '' || !is_scalar($value)) {
                continue;
            }

            $text = ll_tools_sanitize_entity_translation_text($field, $value);
            if ($text !== '') {
                $translations[$locale][$field] = $text;
            }
        }

        if (empty($translations[$locale])) {
            unset($translations[$locale]);
        }
    }

    ksort($translations);
    foreach ($translations as &$fields) {
        ksort($fields);
    }
    unset($fields);

    return $translations;
}

/**
 * @return array<string,array<string,string>>
 */
function ll_tools_get_entity_translations(string $object_type, int $object_id): array {
    $object_type = ll_tools_normalize_entity_translation_object_type($object_type);
    $object_id = (int) $object_id;
    if ($object_type === '' || $object_id <= 0) {
        return [];
    }

    return ll_tools_normalize_entity_translations(
        get_metadata($object_type, $object_id, LL_TOOLS_ENTITY_TRANSLATIONS_META_KEY, true)
    );
}

function ll_tools_save_entity_translations(string $object_type, int $object_id, array $translations): bool {
    $object_type = ll_tools_normalize_entity_translation_object_type($object_type);
    $object_id = (int) $object_id;
    if ($object_type === '' || $object_id <= 0) {
        return false;
    }

    $translations = ll_tools_normalize_entity_translations($translations);
    if (empty($translations)) {
        return delete_metadata($object_type, $object_id, LL_TOOLS_ENTITY_TRANSLATIONS_META_KEY);
    }

    return update_metadata($object_type, $object_id, LL_TOOLS_ENTITY_TRANSLATIONS_META_KEY, $translations);
}

/**
 * @param array<string,array<string,string>> $current
 * @param array<string,array<string,mixed>>  $updates
 * @return array<string,array<string,string>>
 */
function ll_tools_apply_entity_translation_updates(array $current, array $updates): array {
    $next = ll_tools_normalize_entity_translations($current);

    foreach ($updates as $locale => $fields) {
        $locale = ll_tools_normalize_entity_translation_locale($locale);
        if ($locale === '' || !is_array($fields)) {
            continue;
        }

        foreach ($fields as $field => $value) {
            $field = ll_tools_normalize_entity_translation_field($field);
            if ($field === '' || !is_scalar($value)) {
                continue;
            }

            $text = ll_tools_sanitize_entity_translation_text($field, $value);
            if ($text === '') {
                unset($next[$locale][$field]);
                continue;
            }

            $next[$locale][$field] = $text;
        }

        if (empty($next[$locale])) {
            unset($next[$locale]);
        }
    }

    return ll_tools_normalize_entity_translations($next);
}

function ll_tools_update_entity_translations(string $object_type, int $object_id, array $updates, bool $dry_run = false): array {
    $before = ll_tools_get_entity_translations($object_type, $object_id);
    $after = ll_tools_apply_entity_translation_updates($before, $updates);
    $changed = $before !== $after;

    if ($changed && !$dry_run) {
        ll_tools_save_entity_translations($object_type, $object_id, $after);
    }

    return [
        'changed' => $changed,
        'before' => $before,
        'after' => $after,
    ];
}

function ll_tools_get_entity_translation(string $object_type, int $object_id, string $field, array $args = []): string {
    $field = ll_tools_normalize_entity_translation_field($field);
    if ($field === '') {
        return '';
    }

    $locale = isset($args['locale']) ? (string) $args['locale'] : (isset($args['site_language']) ? (string) $args['site_language'] : '');
    $extra_locales = isset($args['locales']) && is_array($args['locales']) ? $args['locales'] : [];
    if ($locale !== '') {
        array_unshift($extra_locales, $locale);
    }

    $translations = ll_tools_get_entity_translations($object_type, $object_id);
    foreach (ll_tools_entity_translation_locale_candidates($extra_locales) as $candidate) {
        if (!empty($translations[$candidate][$field])) {
            return (string) $translations[$candidate][$field];
        }
    }

    return '';
}

function ll_tools_get_wordset_display_name($wordset, array $args = []): string {
    $term = null;
    if ($wordset instanceof WP_Term && $wordset->taxonomy === 'wordset') {
        $term = $wordset;
    } elseif (function_exists('ll_tools_resolve_wordset_term')) {
        $term = ll_tools_resolve_wordset_term($wordset);
    } elseif (is_numeric($wordset)) {
        $term = get_term((int) $wordset, 'wordset');
    }

    if (!$term instanceof WP_Term || is_wp_error($term)) {
        return '';
    }

    $translated = ll_tools_get_entity_translation('term', (int) $term->term_id, 'name', $args);
    return $translated !== '' ? $translated : (string) $term->name;
}

function ll_tools_get_lesson_display_title($lesson, array $args = []): string {
    $post = $lesson instanceof WP_Post ? $lesson : get_post((int) $lesson);
    if (!$post instanceof WP_Post) {
        return '';
    }

    $fallback = isset($args['fallback']) ? (string) $args['fallback'] : (string) get_the_title($post);
    $translated = ll_tools_get_entity_translation('post', (int) $post->ID, 'title', $args);
    if ($translated !== '') {
        return $translated;
    }

    if (
        $post->post_type === 'll_content_lesson'
        && function_exists('ll_tools_get_content_lesson_kind')
        && ll_tools_get_content_lesson_kind((int) $post->ID) === 'corpus_text'
        && function_exists('ll_tools_get_content_lesson_localized_title')
    ) {
        return ll_tools_get_content_lesson_localized_title((int) $post->ID, $fallback);
    }

    return $fallback;
}

function ll_tools_get_lesson_display_excerpt($lesson, string $fallback = '', array $args = []): string {
    $post = $lesson instanceof WP_Post ? $lesson : get_post((int) $lesson);
    if (!$post instanceof WP_Post) {
        return $fallback;
    }

    $translated = ll_tools_get_entity_translation('post', (int) $post->ID, 'excerpt', $args);
    if ($translated !== '') {
        return $translated;
    }

    if (
        $post->post_type === 'll_content_lesson'
        && function_exists('ll_tools_get_content_lesson_kind')
        && ll_tools_get_content_lesson_kind((int) $post->ID) === 'corpus_text'
        && function_exists('ll_tools_get_content_lesson_localized_excerpt')
    ) {
        return ll_tools_get_content_lesson_localized_excerpt((int) $post->ID, $fallback);
    }

    return $fallback;
}
