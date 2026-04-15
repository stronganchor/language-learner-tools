<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_SOURCES_OPTION')) {
    define('LL_TOOLS_DICTIONARY_SOURCES_OPTION', 'll_tools_dictionary_sources');
}

/**
 * Normalize one registry/source identifier to a stable slug-like key.
 */
function ll_tools_dictionary_normalize_source_id(string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $normalized = sanitize_title($value);
    if ($normalized !== '') {
        return $normalized;
    }

    $normalized = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value)) ?? '';
    $normalized = trim((string) $normalized, '-_ ');

    return $normalized;
}

/**
 * Normalize one dialect/filter label for case-insensitive matching.
 */
function ll_tools_dictionary_normalize_dialect_key(string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('ll_tools_dictionary_normalize_search_text')) {
        return ll_tools_dictionary_normalize_search_text($value);
    }

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/**
 * Sanitize one list of dialect labels.
 *
 * @param mixed $value Raw dialect payload.
 * @return string[]
 */
function ll_tools_dictionary_sanitize_dialect_list($value): array {
    $raw_values = [];

    if (is_array($value)) {
        $raw_values = $value;
    } elseif (is_string($value)) {
        $raw_values = preg_split('/\s*[|,;]+\s*/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    $dialects = [];
    $seen = [];
    foreach ($raw_values as $dialect) {
        $label = trim(sanitize_text_field((string) $dialect));
        $key = ll_tools_dictionary_normalize_dialect_key($label);
        if ($label === '' || $key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $dialects[] = $label;
    }

    return $dialects;
}

/**
 * Sanitize the stored dictionary source registry.
 *
 * @param mixed $raw Raw option payload.
 * @return array<string,array{id:string,label:string,attribution_text:string,attribution_url:string,default_dialects:string[]}>
 */
function ll_tools_dictionary_sanitize_source_registry($raw): array {
    if (!is_array($raw)) {
        return [];
    }

    $sources = [];
    foreach ($raw as $source_id => $source) {
        if (!is_array($source)) {
            continue;
        }

        $id = ll_tools_dictionary_normalize_source_id(
            is_string($source_id) && $source_id !== ''
                ? $source_id
                : (string) ($source['id'] ?? '')
        );
        $label = trim(sanitize_text_field((string) ($source['label'] ?? '')));
        if ($id === '' && $label !== '') {
            $id = ll_tools_dictionary_normalize_source_id($label);
        }
        if ($id === '' || $label === '') {
            continue;
        }

        $attribution_url = trim(esc_url_raw((string) ($source['attribution_url'] ?? '')));
        $sources[$id] = [
            'id' => $id,
            'label' => $label,
            'attribution_text' => trim(sanitize_textarea_field((string) ($source['attribution_text'] ?? ''))),
            'attribution_url' => $attribution_url,
            'default_dialects' => ll_tools_dictionary_sanitize_dialect_list($source['default_dialects'] ?? []),
        ];
    }

    uasort($sources, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');

        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    return $sources;
}

/**
 * Return the configured dictionary source registry.
 *
 * @return array<string,array{id:string,label:string,attribution_text:string,attribution_url:string,default_dialects:string[]}>
 */
function ll_tools_get_dictionary_source_registry(): array {
    $cache = $GLOBALS['ll_tools_dictionary_source_registry_cache'] ?? null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = ll_tools_dictionary_sanitize_source_registry(get_option(LL_TOOLS_DICTIONARY_SOURCES_OPTION, []));
    $GLOBALS['ll_tools_dictionary_source_registry_cache'] = $cache;

    return $cache;
}

/**
 * Update the configured dictionary source registry.
 *
 * @param mixed $raw Raw registry payload.
 */
function ll_tools_update_dictionary_source_registry($raw): array {
    $sources = ll_tools_dictionary_sanitize_source_registry($raw);
    update_option(LL_TOOLS_DICTIONARY_SOURCES_OPTION, $sources, false);
    $GLOBALS['ll_tools_dictionary_source_registry_cache'] = $sources;

    return $sources;
}

/**
 * Resolve one source registry item by ID or label.
 *
 * @return array{id:string,label:string,attribution_text:string,attribution_url:string,default_dialects:string[]}|null
 */
function ll_tools_dictionary_get_source_definition(string $source_id = '', string $source_label = ''): ?array {
    $registry = ll_tools_get_dictionary_source_registry();

    $source_id = ll_tools_dictionary_normalize_source_id($source_id);
    if ($source_id !== '' && isset($registry[$source_id])) {
        return $registry[$source_id];
    }

    $source_key = ll_tools_dictionary_normalize_dialect_key($source_label);
    if ($source_key === '') {
        return null;
    }

    foreach ($registry as $source) {
        $label = trim((string) ($source['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (ll_tools_dictionary_normalize_dialect_key($label) === $source_key) {
            return $source;
        }
    }

    return null;
}

/**
 * Resolve a registry-backed source ID when possible.
 */
function ll_tools_dictionary_resolve_source_id(string $source_id = '', string $source_label = ''): string {
    $definition = ll_tools_dictionary_get_source_definition($source_id, $source_label);
    if (is_array($definition) && !empty($definition['id'])) {
        return (string) $definition['id'];
    }

    if ($source_id !== '') {
        return ll_tools_dictionary_normalize_source_id($source_id);
    }

    return ll_tools_dictionary_normalize_source_id($source_label);
}

/**
 * Resolve a human-readable source label from the registry when possible.
 */
function ll_tools_dictionary_resolve_source_label(string $source_id = '', string $source_label = ''): string {
    $definition = ll_tools_dictionary_get_source_definition($source_id, $source_label);
    if (is_array($definition) && !empty($definition['label'])) {
        return (string) $definition['label'];
    }

    return trim(sanitize_text_field($source_label));
}

/**
 * Resolve the default dialects for one source when configured.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_source_default_dialects(string $source_id = '', string $source_label = ''): array {
    $definition = ll_tools_dictionary_get_source_definition($source_id, $source_label);
    if (!is_array($definition)) {
        return [];
    }

    return ll_tools_dictionary_sanitize_dialect_list($definition['default_dialects'] ?? []);
}

/**
 * Return one normalized source payload for UI rendering.
 *
 * @return array{id:string,label:string,attribution_text:string,attribution_url:string,default_dialects:string[]}
 */
function ll_tools_dictionary_build_source_payload(string $source_id = '', string $source_label = ''): array {
    $definition = ll_tools_dictionary_get_source_definition($source_id, $source_label);
    if (is_array($definition)) {
        return $definition;
    }

    $resolved_id = ll_tools_dictionary_resolve_source_id($source_id, $source_label);
    $resolved_label = ll_tools_dictionary_resolve_source_label($source_id, $source_label);

    return [
        'id' => $resolved_id,
        'label' => $resolved_label,
        'attribution_text' => '',
        'attribution_url' => '',
        'default_dialects' => [],
    ];
}
