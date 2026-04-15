<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY', 'll_dictionary_entry_senses');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY', 'll_dictionary_entry_lookup_title');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY', 'll_dictionary_entry_lookup_translation');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY', 'll_dictionary_entry_search_index');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY', 'll_dictionary_entry_gender_number');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY', 'll_dictionary_entry_type');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY', 'll_dictionary_entry_parent');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY', 'll_dictionary_entry_needs_review');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY', 'll_dictionary_entry_page_number');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY', 'll_dictionary_entry_entry_lang');
}
if (!defined('LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY')) {
    define('LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY', 'll_dictionary_entry_def_lang');
}
if (!defined('LL_TOOLS_DICTIONARY_BROWSER_CACHE_VERSION_OPTION')) {
    define('LL_TOOLS_DICTIONARY_BROWSER_CACHE_VERSION_OPTION', 'll_tools_dictionary_browser_cache_version');
}
if (!defined('LL_TOOLS_DICTIONARY_BROWSER_CACHE_GROUP')) {
    define('LL_TOOLS_DICTIONARY_BROWSER_CACHE_GROUP', 'll_tools');
}

/**
 * Resolve the current version token for dictionary browser caches.
 */
function ll_tools_get_dictionary_browser_cache_version(): int {
    if (isset($GLOBALS['ll_tools_dictionary_browser_cache_version'])) {
        return max(1, (int) $GLOBALS['ll_tools_dictionary_browser_cache_version']);
    }

    $version = (int) get_option(LL_TOOLS_DICTIONARY_BROWSER_CACHE_VERSION_OPTION, 1);
    if ($version < 1) {
        $version = 1;
        update_option(LL_TOOLS_DICTIONARY_BROWSER_CACHE_VERSION_OPTION, $version, false);
    }

    $GLOBALS['ll_tools_dictionary_browser_cache_version'] = $version;

    return $version;
}

/**
 * Invalidate all persistent dictionary browser caches.
 */
function ll_tools_bump_dictionary_browser_cache_version(): int {
    $next_version = ll_tools_get_dictionary_browser_cache_version() + 1;
    update_option(LL_TOOLS_DICTIONARY_BROWSER_CACHE_VERSION_OPTION, $next_version, false);
    $GLOBALS['ll_tools_dictionary_browser_cache_version'] = $next_version;

    return $next_version;
}

/**
 * Build one stable persistent cache key for dictionary browser payloads.
 *
 * @param string               $namespace Cache namespace.
 * @param array<string,mixed>  $args      Key arguments.
 */
function ll_tools_dictionary_browser_build_cache_key(string $namespace, array $args = []): string {
    return 'll_dict_' . sanitize_key($namespace) . '_' . md5((string) wp_json_encode([
        'version' => ll_tools_get_dictionary_browser_cache_version(),
        'args' => $args,
    ]));
}

/**
 * Read one dictionary browser payload from request/object/transient cache.
 *
 * @param string              $namespace     Cache namespace.
 * @param array<string,mixed> $args          Key arguments.
 * @param array<string,mixed> $request_cache Request-scope cache bucket.
 * @return mixed|null
 */
function ll_tools_dictionary_browser_get_cached_payload(string $namespace, array $args, array &$request_cache) {
    $request_key = $namespace . ':' . md5((string) wp_json_encode([
        'version' => ll_tools_get_dictionary_browser_cache_version(),
        'args' => $args,
    ]));
    if (array_key_exists($request_key, $request_cache)) {
        return $request_cache[$request_key];
    }

    $persistent_key = ll_tools_dictionary_browser_build_cache_key($namespace, $args);
    $cached = wp_cache_get($persistent_key, LL_TOOLS_DICTIONARY_BROWSER_CACHE_GROUP);
    if (false === $cached) {
        $cached = get_transient($persistent_key);
    }
    if (false === $cached) {
        return null;
    }

    $request_cache[$request_key] = $cached;

    return $cached;
}

/**
 * Store one dictionary browser payload in request/object/transient cache.
 *
 * @param string              $namespace     Cache namespace.
 * @param array<string,mixed> $args          Key arguments.
 * @param mixed               $payload       Cache value.
 * @param int                 $ttl_seconds   Cache TTL.
 * @param array<string,mixed> $request_cache Request-scope cache bucket.
 * @return mixed
 */
function ll_tools_dictionary_browser_store_cached_payload(string $namespace, array $args, $payload, int $ttl_seconds, array &$request_cache) {
    $request_key = $namespace . ':' . md5((string) wp_json_encode([
        'version' => ll_tools_get_dictionary_browser_cache_version(),
        'args' => $args,
    ]));
    $persistent_key = ll_tools_dictionary_browser_build_cache_key($namespace, $args);

    $request_cache[$request_key] = $payload;
    wp_cache_set($persistent_key, $payload, LL_TOOLS_DICTIONARY_BROWSER_CACHE_GROUP, $ttl_seconds);
    set_transient($persistent_key, $payload, $ttl_seconds);

    return $payload;
}

/**
 * Normalize text for accent-insensitive dictionary search.
 */
function ll_tools_dictionary_normalize_search_text(string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = strtr($value, [
        'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        'Â' => 'a', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Û' => 'u',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e',
        'É' => 'e', 'È' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i',
        'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'õ' => 'o',
        'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
        'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u',
        'ş' => 's', 'Ş' => 's', 'ç' => 'c', 'Ç' => 'c',
        'ğ' => 'g', 'Ğ' => 'g', 'İ' => 'i', 'ı' => 'i',
    ]);

    if (function_exists('remove_accents')) {
        $value = remove_accents($value);
    }

    $value = function_exists('ll_tools_lowercase_for_language')
        ? ll_tools_lowercase_for_language($value, 'tr')
        : (function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $value = preg_replace('/[^\p{L}\p{N}\s\'"-]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string) $value);

    return trim((string) $value);
}

/**
 * Normalize a language identifier to a stable key when possible.
 */
function ll_tools_dictionary_normalize_language_key(string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('ll_tools_resolve_language_code_from_label')) {
        $resolved = (string) ll_tools_resolve_language_code_from_label($value, 'lower');
        if ($resolved !== '') {
            return $resolved;
        }
    }

    $value = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $value) ?? '');
    return trim($value);
}

/**
 * Normalize one browse-letter value for the current dictionary title language.
 */
function ll_tools_dictionary_normalize_browse_letter(string $value, string $language = ''): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, 1, 'UTF-8');
    } else {
        $value = substr($value, 0, 1);
    }

    if ($value === '' || preg_match('/[\p{L}\p{N}]/u', $value) !== 1) {
        return '';
    }

    return function_exists('ll_tools_uppercase_first_char_for_language')
        ? ll_tools_uppercase_first_char_for_language($value, $language)
        : (function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value));
}

/**
 * Resolve the active title language code for a dictionary wordset.
 */
function ll_tools_dictionary_get_wordset_title_language_code(int $wordset_id = 0): string {
    if ($wordset_id <= 0 || !function_exists('ll_tools_get_wordset_title_language_label')) {
        return '';
    }

    return ll_tools_dictionary_normalize_language_key((string) ll_tools_get_wordset_title_language_label([$wordset_id]));
}

/**
 * Return a preferred browse alphabet for languages with non-ASCII core letters.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_language_browse_alphabet(string $language = ''): array {
    $language = ll_tools_dictionary_normalize_language_key($language);
    if ($language === '') {
        return [];
    }

    $alphabets = [
        'tr' => ['A', 'B', 'C', 'Ç', 'D', 'E', 'F', 'G', 'Ğ', 'H', 'I', 'İ', 'J', 'K', 'L', 'M', 'N', 'O', 'Ö', 'P', 'R', 'S', 'Ş', 'T', 'U', 'Ü', 'V', 'Y', 'Z'],
        'tur' => ['A', 'B', 'C', 'Ç', 'D', 'E', 'F', 'G', 'Ğ', 'H', 'I', 'İ', 'J', 'K', 'L', 'M', 'N', 'O', 'Ö', 'P', 'R', 'S', 'Ş', 'T', 'U', 'Ü', 'V', 'Y', 'Z'],
        'zza' => ['A', 'B', 'C', 'Ç', 'D', 'E', 'Ê', 'F', 'G', 'Ğ', 'H', 'I', 'İ', 'Î', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'Ş', 'T', 'U', 'Û', 'V', 'W', 'X', 'Y', 'Z'],
        'diq' => ['A', 'B', 'C', 'Ç', 'D', 'E', 'Ê', 'F', 'G', 'Ğ', 'H', 'I', 'İ', 'Î', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'Ş', 'T', 'U', 'Û', 'V', 'W', 'X', 'Y', 'Z'],
        'kiu' => ['A', 'B', 'C', 'Ç', 'D', 'E', 'Ê', 'F', 'G', 'Ğ', 'H', 'I', 'İ', 'Î', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'Ş', 'T', 'U', 'Û', 'V', 'W', 'X', 'Y', 'Z'],
    ];

    return array_values(array_map(static function (string $letter) use ($language): string {
        return ll_tools_dictionary_normalize_browse_letter($letter, $language);
    }, (array) ($alphabets[$language] ?? [])));
}

/**
 * Determine whether one dictionary entry title belongs to a browse-letter bucket.
 */
function ll_tools_dictionary_entry_matches_browse_letter(int $entry_id, string $letter, string $language = ''): bool {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0) {
        return false;
    }

    $letter = ll_tools_dictionary_normalize_browse_letter($letter, $language);
    if ($letter === '') {
        return false;
    }

    $title = trim((string) get_the_title($entry_id));
    if ($title === '') {
        return false;
    }

    $first_letter = ll_tools_dictionary_normalize_browse_letter($title, $language);
    return $first_letter !== '' && $first_letter === $letter;
}

/**
 * Sanitize a translations map keyed by language code.
 *
 * @param mixed $translations Raw translations payload.
 * @return array<string,string>
 */
function ll_tools_dictionary_sanitize_translations_map($translations): array {
    if (!is_array($translations)) {
        return [];
    }

    $clean = [];
    foreach ($translations as $lang => $text) {
        $lang_key = ll_tools_dictionary_normalize_language_key((string) $lang);
        $text = trim(sanitize_text_field((string) $text));
        if ($lang_key === '' || $text === '') {
            continue;
        }
        $clean[$lang_key] = $text;
    }
    return $clean;
}

/**
 * Sanitize one imported dictionary sense row.
 *
 * @param array<string,mixed> $sense Raw sense payload.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_sanitize_sense(array $sense): array {
    $clean = [
        'definition' => '',
        'gender_number' => '',
        'entry_type' => '',
        'parent' => '',
        'needs_review' => '',
        'page_number' => '',
        'entry_lang' => '',
        'def_lang' => '',
        'search_terms' => '',
        'source_id' => '',
        'source_dictionary' => '',
        'source_row_idx' => '',
        'raw_headword' => '',
        'title_keys' => '',
        'dialects' => [],
        'translations' => [],
    ];

    foreach ($clean as $key => $unused) {
        if ($key === 'translations' || $key === 'dialects') {
            continue;
        }
        $clean[$key] = trim(sanitize_text_field((string) ($sense[$key] ?? '')));
    }

    $clean['source_id'] = ll_tools_dictionary_resolve_source_id((string) $clean['source_id'], (string) $clean['source_dictionary']);
    $clean['source_dictionary'] = ll_tools_dictionary_resolve_source_label((string) $clean['source_id'], (string) $clean['source_dictionary']);
    $clean['dialects'] = ll_tools_dictionary_sanitize_dialect_list($sense['dialects'] ?? []);
    $clean['translations'] = ll_tools_dictionary_sanitize_translations_map($sense['translations'] ?? []);

    if ($clean['definition'] !== '') {
        $def_lang_key = ll_tools_dictionary_normalize_language_key((string) $clean['def_lang']);
        if ($def_lang_key !== '' && empty($clean['translations'][$def_lang_key])) {
            $clean['translations'][$def_lang_key] = $clean['definition'];
        }
    }

    return $clean;
}

/**
 * Return normalized source data for one sense.
 *
 * @param array<string,mixed> $sense Sense payload.
 * @return array{id:string,label:string,attribution_text:string,attribution_url:string,default_dialects:string[]}
 */
function ll_tools_dictionary_get_sense_source(array $sense): array {
    return ll_tools_dictionary_build_source_payload(
        (string) ($sense['source_id'] ?? ''),
        (string) ($sense['source_dictionary'] ?? '')
    );
}

/**
 * Return all normalized dialect labels attached to one sense.
 *
 * @param array<string,mixed> $sense Sense payload.
 * @return string[]
 */
function ll_tools_dictionary_get_sense_dialects(array $sense): array {
    return ll_tools_dictionary_sanitize_dialect_list($sense['dialects'] ?? []);
}

/**
 * Create a stable sense hash for de-duplication.
 *
 * @param array<string,mixed> $sense Sanitized sense.
 */
function ll_tools_dictionary_sense_hash(array $sense): string {
    $parts = [];
    foreach (['definition', 'gender_number', 'entry_type', 'parent', 'needs_review', 'page_number', 'entry_lang', 'def_lang', 'search_terms', 'source_id', 'source_dictionary', 'source_row_idx', 'raw_headword', 'title_keys'] as $key) {
        $parts[] = ll_tools_dictionary_normalize_search_text((string) ($sense[$key] ?? ''));
    }
    foreach (ll_tools_dictionary_get_sense_dialects($sense) as $dialect) {
        $parts[] = 'dialect:' . ll_tools_dictionary_normalize_dialect_key($dialect);
    }
    $translations = ll_tools_dictionary_sanitize_translations_map($sense['translations'] ?? []);
    ksort($translations);
    foreach ($translations as $lang => $text) {
        $parts[] = $lang . ':' . ll_tools_dictionary_normalize_search_text($text);
    }

    return md5(implode('|', $parts));
}

/**
 * Return all translations attached to a sense, including legacy definition/def_lang pairs.
 *
 * @param array<string,mixed> $sense Sense payload.
 * @return array<string,string>
 */
function ll_tools_dictionary_get_sense_translations(array $sense): array {
    return ll_tools_dictionary_sanitize_translations_map($sense['translations'] ?? []);
}

/**
 * Resolve the preferred visible text for a sense.
 *
 * @param array<string,mixed> $sense Sense payload.
 * @param array<int,string>   $preferred_languages Preferred gloss languages.
 */
function ll_tools_dictionary_get_preferred_translation_text(array $sense, array $preferred_languages = [], bool $allow_fallback = true): string {
    $translations = ll_tools_dictionary_get_sense_translations($sense);
    foreach ($preferred_languages as $language) {
        $language = ll_tools_dictionary_normalize_language_key((string) $language);
        if ($language !== '' && !empty($translations[$language])) {
            return (string) $translations[$language];
        }
    }

    $def_lang = ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''));
    if ($def_lang !== '' && !empty($translations[$def_lang])) {
        return (string) $translations[$def_lang];
    }

    $definition = trim((string) ($sense['definition'] ?? ''));
    if ($definition !== '') {
        return $definition;
    }

    if ($allow_fallback && !empty($translations)) {
        $first = reset($translations);
        return is_string($first) ? trim($first) : '';
    }

    return '';
}

/**
 * Render a compact label for one glossary language.
 */
function ll_tools_dictionary_get_language_label(string $language): string {
    $language = ll_tools_dictionary_normalize_language_key($language);
    if ($language === '') {
        return '';
    }

    $labels = [
        'tr' => __('TR', 'll-tools-text-domain'),
        'de' => __('DE', 'll-tools-text-domain'),
        'en' => __('EN', 'll-tools-text-domain'),
        'zza' => __('ZZA', 'll-tools-text-domain'),
        'diq' => __('DIQ', 'll-tools-text-domain'),
        'kiu' => __('KIU', 'll-tools-text-domain'),
    ];

    if (isset($labels[$language])) {
        return (string) $labels[$language];
    }

    return strtoupper($language);
}

/**
 * Return sanitized structured senses for a dictionary entry.
 *
 * @return array<int,array<string,mixed>>
 */
function ll_tools_get_dictionary_entry_senses($entry_id): array {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry') {
        return [];
    }

    $raw = get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, true);
    $senses = [];

    if (is_array($raw)) {
        foreach ($raw as $sense) {
            if (!is_array($sense)) {
                continue;
            }
            $clean = ll_tools_dictionary_sanitize_sense($sense);
            $has_content = false;
            foreach ($clean as $key => $value) {
                if ($key === 'translations' || $key === 'dialects') {
                    if (!empty($value) && is_array($value)) {
                        $has_content = true;
                        break;
                    }
                    continue;
                }
                if ($value !== '') {
                    $has_content = true;
                    break;
                }
            }
            if ($has_content) {
                $senses[] = $clean;
            }
        }
    }

    if (!empty($senses)) {
        return $senses;
    }

    $fallback_definition = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));
    if ($fallback_definition === '') {
        $fallback_definition = trim(wp_strip_all_tags((string) get_post_field('post_content', $entry_id)));
    }

    $fallback = ll_tools_dictionary_sanitize_sense([
        'definition' => $fallback_definition,
        'gender_number' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY, true),
        'entry_type' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY, true),
        'parent' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY, true),
        'needs_review' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY, true),
        'page_number' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY, true),
        'entry_lang' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY, true),
        'def_lang' => (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY, true),
    ]);

    foreach ($fallback as $key => $value) {
        if ($key === 'translations') {
            if (!empty($value)) {
                return [$fallback];
            }
            continue;
        }
        if ($value !== '') {
            return [$fallback];
        }
    }

    return [];
}

/**
 * Collect unique source payloads used by a senses list.
 *
 * @param array<int,array<string,mixed>> $senses Senses list.
 * @return array<int,array{id:string,label:string,attribution_text:string,attribution_url:string,default_dialects:string[]}>
 */
function ll_tools_dictionary_collect_sources(array $senses): array {
    $sources = [];
    foreach ($senses as $sense) {
        if (!is_array($sense)) {
            continue;
        }

        $source = ll_tools_dictionary_get_sense_source($sense);
        $label = trim((string) ($source['label'] ?? ''));
        $key = trim((string) ($source['id'] ?? ''));
        if ($label === '' && $key === '') {
            continue;
        }

        $lookup = $key !== '' ? $key : ll_tools_dictionary_normalize_dialect_key($label);
        if ($lookup === '' || isset($sources[$lookup])) {
            continue;
        }
        $sources[$lookup] = $source;
    }

    uasort($sources, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');

        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    return array_values($sources);
}

/**
 * Collect unique dialect labels used by a senses list.
 *
 * @param array<int,array<string,mixed>> $senses Senses list.
 * @return string[]
 */
function ll_tools_dictionary_collect_dialects(array $senses): array {
    $dialects = [];
    foreach ($senses as $sense) {
        if (!is_array($sense)) {
            continue;
        }

        foreach (ll_tools_dictionary_get_sense_dialects($sense) as $dialect) {
            $key = ll_tools_dictionary_normalize_dialect_key($dialect);
            if ($key === '' || isset($dialects[$key])) {
                continue;
            }
            $dialects[$key] = $dialect;
        }
    }

    $labels = array_values($dialects);
    usort($labels, static function (string $left, string $right): int {
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left, $right)
            : strnatcasecmp($left, $right);
    });

    return $labels;
}

/**
 * Determine whether one wordset's display label matches a dialect list.
 *
 * @param int      $wordset_id Wordset ID.
 * @param string[] $dialects   Dialect labels.
 */
function ll_tools_dictionary_wordset_matches_dialect_labels(int $wordset_id, array $dialects): bool {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0 || empty($dialects)) {
        return false;
    }

    $term = get_term($wordset_id, 'wordset');
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return false;
    }

    $lookup_keys = [];
    foreach ([(string) $term->name, (string) $term->slug] as $candidate) {
        $candidate_key = ll_tools_dictionary_normalize_dialect_key(str_replace('-', ' ', trim($candidate)));
        if ($candidate_key !== '') {
            $lookup_keys[$candidate_key] = true;
        }
    }

    if (empty($lookup_keys)) {
        return false;
    }

    foreach ($dialects as $dialect) {
        $dialect_key = ll_tools_dictionary_normalize_dialect_key($dialect);
        if ($dialect_key !== '' && isset($lookup_keys[$dialect_key])) {
            return true;
        }
    }

    return false;
}

/**
 * Determine whether one dictionary entry should appear in a wordset-scoped dictionary view.
 */
function ll_tools_dictionary_entry_matches_wordset_context(int $entry_id, int $wordset_id): bool {
    $entry_id = (int) $entry_id;
    $wordset_id = (int) $wordset_id;
    if ($entry_id <= 0 || $wordset_id <= 0) {
        return true;
    }

    $scope_index = defined('LL_TOOLS_DICTIONARY_ENTRY_WORDSET_SCOPE_INDEX_META_KEY')
        ? (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_SCOPE_INDEX_META_KEY, true)
        : '';
    if ($scope_index !== '' && strpos($scope_index, '|' . $wordset_id . '|') !== false) {
        return true;
    }

    if ($scope_index === '' && in_array($wordset_id, ll_tools_get_dictionary_entry_scope_wordset_ids($entry_id), true)) {
        return true;
    }

    return ll_tools_dictionary_wordset_matches_dialect_labels(
        $wordset_id,
        ll_tools_dictionary_collect_dialects(ll_tools_get_dictionary_entry_senses($entry_id))
    );
}

/**
 * Determine whether one dictionary entry contains a matching source filter.
 */
function ll_tools_dictionary_entry_matches_source_filter(int $entry_id, string $source_filter): bool {
    $entry_id = (int) $entry_id;
    $source_filter_id = ll_tools_dictionary_normalize_source_id($source_filter);
    $source_filter_label = ll_tools_dictionary_normalize_dialect_key($source_filter);
    if ($entry_id <= 0 || ($source_filter_id === '' && $source_filter_label === '')) {
        return true;
    }

    foreach (ll_tools_dictionary_collect_sources(ll_tools_get_dictionary_entry_senses($entry_id)) as $source) {
        $source_id = ll_tools_dictionary_normalize_source_id((string) ($source['id'] ?? ''));
        $source_label_key = ll_tools_dictionary_normalize_dialect_key((string) ($source['label'] ?? ''));
        if (($source_filter_id !== '' && $source_id === $source_filter_id) || ($source_filter_label !== '' && $source_label_key === $source_filter_label)) {
            return true;
        }
    }

    return false;
}

/**
 * Determine whether one dictionary entry contains a matching dialect filter.
 */
function ll_tools_dictionary_entry_matches_dialect_filter(int $entry_id, string $dialect_filter): bool {
    $entry_id = (int) $entry_id;
    $dialect_filter = ll_tools_dictionary_normalize_dialect_key($dialect_filter);
    if ($entry_id <= 0 || $dialect_filter === '') {
        return true;
    }

    foreach (ll_tools_dictionary_collect_dialects(ll_tools_get_dictionary_entry_senses($entry_id)) as $dialect) {
        if (ll_tools_dictionary_normalize_dialect_key($dialect) === $dialect_filter) {
            return true;
        }
    }

    return false;
}

/**
 * Merge structured senses without duplicating identical rows.
 *
 * @param array<int,array<string,mixed>> $existing Existing senses.
 * @param array<int,array<string,mixed>> $incoming Incoming senses.
 * @return array<int,array<string,mixed>>
 */
function ll_tools_dictionary_merge_senses(array $existing, array $incoming): array {
    $merged = [];
    $seen = [];

    foreach (array_merge($existing, $incoming) as $sense) {
        if (!is_array($sense)) {
            continue;
        }
        $clean = ll_tools_dictionary_sanitize_sense($sense);
        $hash = ll_tools_dictionary_sense_hash($clean);
        if (isset($seen[$hash])) {
            continue;
        }
        $seen[$hash] = true;
        $merged[] = $clean;
    }

    return $merged;
}

/**
 * Build a short translation summary from senses.
 *
 * @param array<int,array<string,mixed>> $senses Senses list.
 */
function ll_tools_dictionary_build_translation_summary(array $senses, string $fallback = '', array $preferred_languages = []): string {
    $definitions = [];
    $seen = [];

    foreach ($senses as $sense) {
        $definition = ll_tools_dictionary_get_preferred_translation_text((array) $sense, $preferred_languages, true);
        if ($definition === '') {
            continue;
        }
        $key = ll_tools_dictionary_entry_normalize_lookup_value($definition);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $definitions[] = $definition;
        if (count($definitions) >= 3) {
            break;
        }
    }

    if (!empty($definitions)) {
        return implode('; ', $definitions);
    }

    return trim((string) $fallback);
}

/**
 * Build plain post content from structured senses.
 *
 * @param array<int,array<string,mixed>> $senses Senses list.
 */
function ll_tools_dictionary_build_post_content_from_senses(array $senses, array $preferred_languages = []): string {
    $lines = [];
    $seen = [];

    foreach ($senses as $sense) {
        $definition = ll_tools_dictionary_get_preferred_translation_text((array) $sense, $preferred_languages, true);
        if ($definition === '') {
            continue;
        }

        $extras = [];
        $entry_type = trim((string) ($sense['entry_type'] ?? ''));
        $gender = trim((string) ($sense['gender_number'] ?? ''));
        $parent = trim((string) ($sense['parent'] ?? ''));
        $page_number = trim((string) ($sense['page_number'] ?? ''));

        if ($entry_type !== '') {
            $extras[] = $entry_type;
        }
        if ($gender !== '') {
            $extras[] = $gender;
        }
        if ($parent !== '') {
            /* translators: %s: parent dictionary headword. */
            $extras[] = sprintf(__('Parent: %s', 'll-tools-text-domain'), $parent);
        }
        if ($page_number !== '') {
            /* translators: %s: source page number. */
            $extras[] = sprintf(__('Page %s', 'll-tools-text-domain'), $page_number);
        }

        $line = $definition;
        if (!empty($extras)) {
            $line .= ' (' . implode(', ', $extras) . ')';
        }

        $key = ll_tools_dictionary_entry_normalize_lookup_value($line);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $lines[] = $line;
    }

    return implode("\n\n", $lines);
}

/**
 * Build a normalized full-text search index string.
 *
 * @param array<int,array<string,mixed>> $senses Senses list.
 */
function ll_tools_dictionary_build_search_index(string $title, string $translation, string $content, array $senses): string {
    $parts = [$title, $translation, $content];

    foreach ($senses as $sense) {
        foreach (['definition', 'gender_number', 'entry_type', 'parent', 'page_number', 'entry_lang', 'def_lang', 'search_terms', 'source_id', 'source_dictionary', 'source_row_idx', 'raw_headword', 'title_keys'] as $key) {
            $value = trim((string) ($sense[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        foreach (ll_tools_dictionary_get_sense_dialects((array) $sense) as $dialect) {
            if ($dialect !== '') {
                $parts[] = $dialect;
            }
        }
        foreach (ll_tools_dictionary_get_sense_translations((array) $sense) as $lang => $text) {
            if ($text === '') {
                continue;
            }
            $parts[] = $lang;
            $parts[] = $text;
        }
    }

    return ll_tools_dictionary_normalize_search_text(implode(' ', $parts));
}

/**
 * Keep dictionary search meta in sync after save/import.
 */
function ll_tools_dictionary_refresh_entry_search_meta($post_id, $post = null, $update = false): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return;
    }
    if ($post instanceof WP_Post && $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!($post instanceof WP_Post) && get_post_type($post_id) !== 'll_dictionary_entry') {
        return;
    }
    if (wp_is_post_revision($post_id)) {
        return;
    }

    $title = trim((string) get_the_title($post_id));
    $content = trim(wp_strip_all_tags((string) get_post_field('post_content', $post_id)));
    $senses = ll_tools_get_dictionary_entry_senses($post_id);
    $stored_translation = trim((string) get_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true));
    $resolved_translation = ll_tools_dictionary_build_translation_summary($senses, $stored_translation);

    if ($resolved_translation !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $resolved_translation);
    } elseif ($stored_translation !== '') {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }

    if (!empty($senses)) {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, $senses);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY);
    }

    $lookup_title = ll_tools_dictionary_entry_normalize_lookup_value($title);
    $lookup_translation = ll_tools_dictionary_entry_normalize_lookup_value($resolved_translation);
    $search_index = ll_tools_dictionary_build_search_index($title, $resolved_translation, $content, $senses);

    if ($lookup_title !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, $lookup_title);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY);
    }

    if ($lookup_translation !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY, $lookup_translation);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY);
    }

    if ($search_index !== '') {
        update_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY, $search_index);
    } else {
        delete_post_meta($post_id, LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY);
    }
}
add_action('save_post_ll_dictionary_entry', 'll_tools_dictionary_refresh_entry_search_meta', 50, 3);

/**
 * Determine whether one entry matches the exact-title lookup scope rules.
 */
function ll_tools_dictionary_entry_matches_exact_title_scope(int $entry_id, int $wordset_id): bool {
    $entry_id = (int) $entry_id;
    if ($entry_id <= 0) {
        return false;
    }

    $wordset_id = max(0, (int) $wordset_id);
    $explicit_wordset_id = function_exists('ll_tools_get_dictionary_entry_explicit_wordset_id')
        ? (int) ll_tools_get_dictionary_entry_explicit_wordset_id($entry_id)
        : (int) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, true);

    if ($wordset_id > 0) {
        return $explicit_wordset_id === $wordset_id;
    }

    return $explicit_wordset_id <= 0;
}

/**
 * Find candidate dictionary entry IDs by normalized lookup title.
 *
 * @param string[] $statuses Allowed post statuses.
 * @return int[]
 */
function ll_tools_dictionary_find_entry_ids_by_lookup_title(string $lookup, array $statuses): array {
    global $wpdb;

    $lookup = trim((string) $lookup);
    if ($lookup === '' || empty($statuses)) {
        return [];
    }

    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $params = array_merge([
        LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY,
        $lookup,
    ], $statuses);

    $sql = "
        SELECT DISTINCT pm.post_id
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p
                ON p.ID = pm.post_id
        WHERE pm.meta_key = %s
          AND pm.meta_value = %s
          AND p.post_type = 'll_dictionary_entry'
          AND p.post_status IN ({$status_placeholders})
    ";

    $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
    sort($ids, SORT_NUMERIC);

    return array_values(array_unique($ids));
}

/**
 * Fallback exact-title lookup for legacy entries missing lookup meta.
 *
 * @param string[] $statuses Allowed post statuses.
 * @return int[]
 */
function ll_tools_dictionary_find_entry_ids_by_exact_post_title(string $title, array $statuses): array {
    global $wpdb;

    $title = trim((string) $title);
    if ($title === '' || empty($statuses)) {
        return [];
    }

    $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    $params = array_merge($statuses, [$title]);
    $sql = "
        SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = 'll_dictionary_entry'
          AND post_status IN ({$status_placeholders})
          AND post_title = %s
        LIMIT 50
    ";

    $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
    sort($ids, SORT_NUMERIC);

    return array_values(array_unique($ids));
}

/**
 * Backfill lookup meta for legacy exact-title matches so future lookups stay on indexed meta.
 *
 * @param int[] $entry_ids Candidate entry IDs.
 */
function ll_tools_dictionary_backfill_lookup_title_meta(array $entry_ids): void {
    $entry_ids = array_values(array_unique(array_filter(array_map('intval', $entry_ids), static function (int $entry_id): bool {
        return $entry_id > 0;
    })));
    if (empty($entry_ids)) {
        return;
    }

    update_postmeta_cache($entry_ids);

    foreach ($entry_ids as $entry_id) {
        if (get_post_type($entry_id) !== 'll_dictionary_entry') {
            continue;
        }

        $stored_lookup = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, true));
        if ($stored_lookup !== '') {
            continue;
        }

        ll_tools_dictionary_refresh_entry_search_meta($entry_id);
    }
}

/**
 * Choose the best exact-title lookup match from candidate IDs.
 *
 * @param int[]  $candidate_ids Candidate entry IDs.
 * @param string $lookup        Normalized title lookup value.
 */
function ll_tools_dictionary_select_entry_id_for_exact_title(array $candidate_ids, string $lookup, int $wordset_id = 0): int {
    $candidate_ids = array_values(array_unique(array_filter(array_map('intval', $candidate_ids), static function (int $entry_id): bool {
        return $entry_id > 0;
    })));
    if (empty($candidate_ids)) {
        return 0;
    }

    sort($candidate_ids, SORT_NUMERIC);
    update_postmeta_cache($candidate_ids);

    foreach ($candidate_ids as $entry_id) {
        if (get_post_type($entry_id) !== 'll_dictionary_entry') {
            continue;
        }

        $candidate_lookup = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY, true));
        if ($candidate_lookup === '') {
            $candidate_lookup = ll_tools_dictionary_entry_normalize_lookup_value((string) get_the_title($entry_id));
        }

        if ($candidate_lookup !== $lookup) {
            continue;
        }

        if (!ll_tools_dictionary_entry_matches_exact_title_scope($entry_id, $wordset_id)) {
            continue;
        }

        return $entry_id;
    }

    return 0;
}

/**
 * Resolve a part-of-speech slug from imported entry-type text.
 */
function ll_tools_dictionary_resolve_pos_slug_from_entry_type(string $entry_type): string {
    $entry_type = trim((string) $entry_type);
    if ($entry_type === '') {
        return '';
    }

    $normalized = sanitize_title($entry_type);
    $map = [
        'n' => 'noun',
        'noun' => 'noun',
        'v' => 'verb',
        'verb' => 'verb',
        'adj' => 'adjective',
        'adjective' => 'adjective',
        'adv' => 'adverb',
        'adverb' => 'adverb',
        'pron' => 'pronoun',
        'pronoun' => 'pronoun',
        'interj' => 'interjection',
        'interjection' => 'interjection',
        'prep' => 'preposition',
        'preposition' => 'preposition',
        'postp' => 'postposition',
        'postposition' => 'postposition',
        'conj' => 'conjunction',
        'conjunction' => 'conjunction',
    ];

    if (isset($map[$normalized])) {
        $normalized = $map[$normalized];
    }

    $term = get_term_by('slug', $normalized, 'part_of_speech');
    if ($term && !is_wp_error($term)) {
        return (string) $term->slug;
    }

    $term = get_term_by('name', $entry_type, 'part_of_speech');
    if ($term && !is_wp_error($term)) {
        return (string) $term->slug;
    }

    return '';
}

/**
 * Collect any per-language translation columns from an import row.
 *
 * @param array<string|int,mixed> $row Raw import row.
 * @return array<string,string>
 */
function ll_tools_dictionary_collect_row_translations(array $row): array {
    $translations = [];

    if (isset($row['translations']) && is_array($row['translations'])) {
        foreach ($row['translations'] as $language => $text) {
            $language_key = ll_tools_dictionary_normalize_language_key((string) $language);
            $text = trim(sanitize_text_field((string) $text));
            if ($language_key === '' || $text === '' || isset($translations[$language_key])) {
                continue;
            }
            $translations[$language_key] = $text;
        }
    }

    foreach ($row as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $column = strtolower(trim($key));
        $language = '';
        if (strpos($column, 'definition_full_') === 0) {
            $language = substr($column, strlen('definition_full_'));
        } elseif (strpos($column, 'translation_') === 0) {
            $language = substr($column, strlen('translation_'));
        }

        $language_key = ll_tools_dictionary_normalize_language_key($language);
        $text = trim(sanitize_text_field((string) $value));
        if ($language_key === '' || $text === '' || isset($translations[$language_key])) {
            continue;
        }

        $translations[$language_key] = $text;
    }

    return ll_tools_dictionary_sanitize_translations_map($translations);
}

/**
 * Collect any dialect columns from an import row.
 *
 * @param array<string|int,mixed> $row Raw import row.
 * @return string[]
 */
function ll_tools_dictionary_collect_row_dialects(array $row): array {
    $dialects = [];

    if (isset($row['dialects'])) {
        $dialects = ll_tools_dictionary_sanitize_dialect_list($row['dialects']);
    }

    if (empty($dialects) && isset($row['dialect'])) {
        $dialects = ll_tools_dictionary_sanitize_dialect_list($row['dialect']);
    }

    return $dialects;
}

/**
 * Normalize one import row.
 *
 * @param array<string|int,mixed> $row Raw import row.
 * @param array<string,mixed>     $defaults Default values.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_prepare_import_row(array $row, array $defaults = []): array {
    $prepared = [
        'entry' => '',
        'definition' => '',
        'gender_number' => '',
        'entry_type' => '',
        'parent' => '',
        'needs_review' => '',
        'page_number' => '',
        'entry_lang' => '',
        'def_lang' => '',
        'search_terms' => '',
        'source_id' => '',
        'source_dictionary' => '',
        'source_row_idx' => '',
        'raw_headword' => '',
        'title_keys' => '',
        'dialects' => [],
        'translations' => [],
    ];

    $raw_definition = trim(sanitize_text_field((string) ($row['definition'] ?? $row[1] ?? '')));

    $prepared['entry'] = trim(sanitize_text_field((string) ($row['entry'] ?? $row[0] ?? '')));
    $prepared['definition'] = $raw_definition;
    $prepared['gender_number'] = trim(sanitize_text_field((string) ($row['gender_number'] ?? $row[2] ?? '')));
    $prepared['entry_type'] = trim(sanitize_text_field((string) ($row['entry_type'] ?? $row[3] ?? '')));
    $prepared['parent'] = trim(sanitize_text_field((string) ($row['parent'] ?? $row[4] ?? '')));
    $prepared['needs_review'] = trim(sanitize_text_field((string) ($row['needs_review'] ?? $row[5] ?? '')));
    $prepared['page_number'] = trim(sanitize_text_field((string) ($row['page_number'] ?? $row[6] ?? '')));
    $prepared['entry_lang'] = trim(sanitize_text_field((string) ($row['entry_lang'] ?? $defaults['entry_lang'] ?? '')));
    $prepared['def_lang'] = trim(sanitize_text_field((string) ($row['def_lang'] ?? $defaults['def_lang'] ?? '')));
    $prepared['search_terms'] = trim(sanitize_text_field((string) ($row['search_terms'] ?? '')));
    $prepared['source_id'] = trim(sanitize_text_field((string) ($row['source_id'] ?? '')));
    $prepared['source_dictionary'] = trim(sanitize_text_field((string) ($row['source_dictionary'] ?? '')));
    $prepared['source_row_idx'] = trim(sanitize_text_field((string) ($row['source_row_idx'] ?? '')));
    $prepared['raw_headword'] = trim(sanitize_text_field((string) ($row['raw_headword'] ?? '')));
    $prepared['title_keys'] = trim(sanitize_text_field((string) ($row['title_keys'] ?? '')));
    $prepared['dialects'] = ll_tools_dictionary_collect_row_dialects($row);

    $prepared['source_id'] = ll_tools_dictionary_resolve_source_id($prepared['source_id'], $prepared['source_dictionary']);
    $prepared['source_dictionary'] = ll_tools_dictionary_resolve_source_label($prepared['source_id'], $prepared['source_dictionary']);
    if (empty($prepared['dialects'])) {
        $prepared['dialects'] = ll_tools_dictionary_get_source_default_dialects($prepared['source_id'], $prepared['source_dictionary']);
    }

    if ($raw_definition !== '') {
        if ($prepared['search_terms'] === '') {
            $prepared['search_terms'] = $raw_definition;
        } elseif (ll_tools_dictionary_normalize_search_text($prepared['search_terms']) !== ll_tools_dictionary_normalize_search_text($raw_definition)) {
            $prepared['search_terms'] .= ' | ' . $raw_definition;
        }
    }

    $translations = ll_tools_dictionary_collect_row_translations($row);
    $def_lang_key = ll_tools_dictionary_normalize_language_key($prepared['def_lang']);
    if ($raw_definition !== '' && $def_lang_key !== '' && empty($translations[$def_lang_key])) {
        $translations[$def_lang_key] = $raw_definition;
    }

    $prepared['translations'] = ll_tools_dictionary_sanitize_translations_map($translations);
    if (!empty($prepared['translations'])) {
        $prepared['definition'] = ll_tools_dictionary_get_preferred_translation_text([
            'definition' => $raw_definition,
            'def_lang' => $prepared['def_lang'],
            'translations' => $prepared['translations'],
        ], $def_lang_key !== '' ? [$def_lang_key] : [], true);

        if ($prepared['def_lang'] === '') {
            $first_language = array_key_first($prepared['translations']);
            if (is_string($first_language) && $first_language !== '') {
                $prepared['def_lang'] = $first_language;
            }
        }
    }

    return $prepared;
}

/**
 * Decide whether an import row should be skipped because of review flags.
 *
 * @param array<string,string> $row Prepared import row.
 */
function ll_tools_dictionary_should_skip_row_for_review(array $row, bool $skip_flagged = true): bool {
    if (!$skip_flagged) {
        return false;
    }

    $flag = trim((string) ($row['needs_review'] ?? ''));
    if ($flag === '' || $flag === '0' || $flag === '1') {
        return false;
    }

    return true;
}

/**
 * Find one dictionary entry by exact title within an optional word set.
 */
function ll_tools_dictionary_find_entry_by_title(string $title, int $wordset_id = 0): int {
    static $request_cache = [];

    $title = trim((string) $title);
    if ($title === '') {
        return 0;
    }

    $lookup = ll_tools_dictionary_entry_normalize_lookup_value($title);
    if ($lookup === '') {
        return 0;
    }

    $statuses = ['publish', 'draft', 'pending', 'private', 'future'];
    $cache_args = [
        'title' => $lookup,
        'wordset_id' => max(0, $wordset_id),
        'statuses' => $statuses,
    ];
    $cached = ll_tools_dictionary_browser_get_cached_payload('find_entry_by_title', $cache_args, $request_cache);
    if (is_int($cached) || is_numeric($cached)) {
        return (int) $cached;
    }

    $candidate_ids = ll_tools_dictionary_find_entry_ids_by_lookup_title($lookup, $statuses);
    if (empty($candidate_ids)) {
        $candidate_ids = ll_tools_dictionary_find_entry_ids_by_exact_post_title($title, $statuses);
        if (!empty($candidate_ids)) {
            ll_tools_dictionary_backfill_lookup_title_meta($candidate_ids);
        }
    }

    return (int) ll_tools_dictionary_browser_store_cached_payload(
        'find_entry_by_title',
        $cache_args,
        ll_tools_dictionary_select_entry_id_for_exact_title($candidate_ids, $lookup, $wordset_id),
        10 * MINUTE_IN_SECONDS,
        $request_cache
    );
}

/**
 * Return the first non-empty value from a senses list.
 *
 * @param array<int,array<string,string>> $senses Senses list.
 */
function ll_tools_dictionary_get_primary_sense_value(array $senses, string $field): string {
    foreach ($senses as $sense) {
        $value = trim((string) ($sense[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Create or update one dictionary entry from grouped import rows.
 *
 * @param array<int,array<string,mixed>>  $rows Grouped prepared rows.
 * @param array<string,mixed>             $options Import options.
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_upsert_entry_from_rows(array $rows, array $options = []) {
    $title = trim((string) (($rows[0]['entry'] ?? '') ?: ''));
    if ($title === '') {
        return new WP_Error('ll_tools_dictionary_missing_title', __('Dictionary entry title cannot be empty.', 'll-tools-text-domain'));
    }

    $wordset_id = max(0, (int) ($options['wordset_id'] ?? 0));
    $replace_existing_senses = !empty($options['replace_existing_senses']);
    $entry_id = max(0, (int) ($options['entry_id'] ?? 0));
    if ($entry_id <= 0) {
        $entry_id = ll_tools_dictionary_find_entry_by_title($title, $wordset_id);
    }

    $existing_senses = [];
    if ($entry_id > 0 && !$replace_existing_senses) {
        $existing_senses = ll_tools_get_dictionary_entry_senses($entry_id);
    }

    $preferred_languages = [];
    foreach ((array) ($options['preferred_languages'] ?? []) as $language) {
        $language_key = ll_tools_dictionary_normalize_language_key((string) $language);
        if ($language_key === '' || in_array($language_key, $preferred_languages, true)) {
            continue;
        }
        $preferred_languages[] = $language_key;
    }

    $incoming_senses = [];
    foreach ($rows as $row) {
        $sense = ll_tools_dictionary_sanitize_sense([
            'definition' => (string) ($row['definition'] ?? ''),
            'gender_number' => (string) ($row['gender_number'] ?? ''),
            'entry_type' => (string) ($row['entry_type'] ?? ''),
            'parent' => (string) ($row['parent'] ?? ''),
            'needs_review' => (string) ($row['needs_review'] ?? ''),
            'page_number' => (string) ($row['page_number'] ?? ''),
            'entry_lang' => (string) ($row['entry_lang'] ?? ''),
            'def_lang' => (string) ($row['def_lang'] ?? ''),
            'search_terms' => (string) ($row['search_terms'] ?? ''),
            'source_id' => (string) ($row['source_id'] ?? ''),
            'source_dictionary' => (string) ($row['source_dictionary'] ?? ''),
            'source_row_idx' => (string) ($row['source_row_idx'] ?? ''),
            'raw_headword' => (string) ($row['raw_headword'] ?? ''),
            'title_keys' => (string) ($row['title_keys'] ?? ''),
            'dialects' => $row['dialects'] ?? [],
            'translations' => $row['translations'] ?? [],
        ]);
        $incoming_senses[] = $sense;
    }

    $merged_senses = ll_tools_dictionary_merge_senses($existing_senses, $incoming_senses);
    $content = ll_tools_dictionary_build_post_content_from_senses($merged_senses, $preferred_languages);
    $translation = ll_tools_dictionary_build_translation_summary(
        $merged_senses,
        ($entry_id > 0 ? (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, true) : ''),
        $preferred_languages
    );

    $postarr = [
        'post_type' => 'll_dictionary_entry',
        'post_title' => $title,
        'post_status' => 'publish',
        'post_content' => $content,
    ];
    if ($entry_id > 0) {
        $postarr['ID'] = $entry_id;
    }

    $saved_entry_id = wp_insert_post($postarr, true);
    if (is_wp_error($saved_entry_id) || (int) $saved_entry_id <= 0) {
        return new WP_Error(
            'll_tools_dictionary_entry_save_failed',
            is_wp_error($saved_entry_id)
                ? $saved_entry_id->get_error_message()
                : __('Unable to save dictionary entry.', 'll-tools-text-domain')
        );
    }

    $saved_entry_id = (int) $saved_entry_id;
    if (function_exists('ll_tools_get_dictionary_entry_import_key')) {
        ll_tools_get_dictionary_entry_import_key($saved_entry_id, true);
    }
    update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_SENSES_META_KEY, $merged_senses);

    if ($translation !== '') {
        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY, $translation);
    } else {
        delete_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY);
    }

    if ($wordset_id > 0) {
        update_post_meta($saved_entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, $wordset_id);
    }

    $primary_entry_type = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'entry_type');
    $primary_page_number = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'page_number');
    $primary_parent = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'parent');
    $primary_gender = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'gender_number');
    $primary_entry_lang = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'entry_lang');
    $primary_def_lang = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'def_lang');
    $primary_review = ll_tools_dictionary_get_primary_sense_value($merged_senses, 'needs_review');
    $pos_slug = ll_tools_dictionary_resolve_pos_slug_from_entry_type($primary_entry_type);

    $meta_updates = [
        LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY => $primary_entry_type,
        LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY => $primary_page_number,
        LL_TOOLS_DICTIONARY_ENTRY_PARENT_META_KEY => $primary_parent,
        LL_TOOLS_DICTIONARY_ENTRY_GENDER_META_KEY => $primary_gender,
        LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY => $primary_entry_lang,
        LL_TOOLS_DICTIONARY_ENTRY_DEF_LANG_META_KEY => $primary_def_lang,
        LL_TOOLS_DICTIONARY_ENTRY_REVIEW_META_KEY => $primary_review,
        LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY => $pos_slug,
    ];

    foreach ($meta_updates as $meta_key => $value) {
        if ($value !== '') {
            update_post_meta($saved_entry_id, $meta_key, $value);
        } elseif ($replace_existing_senses) {
            delete_post_meta($saved_entry_id, $meta_key);
        }
    }

    ll_tools_dictionary_refresh_entry_search_meta($saved_entry_id);
    if (function_exists('ll_tools_refresh_dictionary_entry_wordset_scope_meta')) {
        ll_tools_refresh_dictionary_entry_wordset_scope_meta($saved_entry_id);
    }

    return [
        'entry_id' => $saved_entry_id,
        'entry_title' => $title,
        'created' => ($entry_id <= 0),
        'updated' => ($entry_id > 0),
        'sense_count' => count($merged_senses),
    ];
}

/**
 * Group raw dictionary import rows into prepared headword buckets.
 *
 * @param array<int,array<string|int,mixed>> $rows Raw rows.
 * @param array<string,mixed>                $options Import options.
 * @return array{grouped_rows:array<int,array<int,array<string,mixed>>>,summary:array<string,mixed>}
 */
function ll_tools_dictionary_group_import_rows(array $rows, array $options = []): array {
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $skip_flagged = !empty($options['skip_review_rows']);
    $defaults = [
        'entry_lang' => trim(sanitize_text_field((string) ($options['entry_lang'] ?? ''))),
        'def_lang' => trim(sanitize_text_field((string) ($options['def_lang'] ?? ''))),
    ];

    $summary = [
        'rows_total' => count($rows),
        'rows_grouped' => 0,
        'rows_skipped_empty' => 0,
        'rows_skipped_review' => 0,
        'entries_created' => 0,
        'entries_updated' => 0,
        'entry_ids' => [],
        'errors' => [],
    ];

    $grouped_rows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            $summary['rows_skipped_empty']++;
            continue;
        }

        $prepared = ll_tools_dictionary_prepare_import_row($row, $defaults);
        if ($prepared['entry'] === '') {
            $summary['rows_skipped_empty']++;
            continue;
        }
        if (ll_tools_dictionary_should_skip_row_for_review($prepared, $skip_flagged)) {
            $summary['rows_skipped_review']++;
            continue;
        }

        $group_key = max(0, (int) ($options['wordset_id'] ?? 0))
            . '|'
            . ll_tools_dictionary_entry_normalize_lookup_value($prepared['entry']);
        if (!isset($grouped_rows[$group_key])) {
            $grouped_rows[$group_key] = [];
        }
        $grouped_rows[$group_key][] = $prepared;
    }

    $summary['rows_grouped'] = count($grouped_rows);

    return [
        'grouped_rows' => array_values($grouped_rows),
        'summary' => $summary,
    ];
}

/**
 * Import prepared headword groups into ll_dictionary_entry posts.
 *
 * @param array<int,array<int,array<string,mixed>>> $grouped_rows Grouped prepared rows.
 * @param array<string,mixed>                       $options Import options.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_apply_grouped_import_rows(array $grouped_rows, array $options = []): array {
    $summary = [
        'entries_created' => 0,
        'entries_updated' => 0,
        'entry_ids' => [],
        'errors' => [],
    ];

    foreach ($grouped_rows as $group_rows) {
        if (!is_array($group_rows) || empty($group_rows)) {
            continue;
        }

        $result = ll_tools_dictionary_upsert_entry_from_rows($group_rows, $options);
        if (is_wp_error($result)) {
            $summary['errors'][] = $result->get_error_message();
            continue;
        }

        $entry_id = (int) ($result['entry_id'] ?? 0);
        if ($entry_id > 0) {
            $summary['entry_ids'][] = $entry_id;
        }
        if (!empty($result['created'])) {
            $summary['entries_created']++;
        } else {
            $summary['entries_updated']++;
        }
    }

    $summary['entry_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $summary['entry_ids']))));
    $summary['error_count'] = count((array) $summary['errors']);

    return $summary;
}

/**
 * Import prepared dictionary rows into ll_dictionary_entry posts.
 *
 * @param array<int,array<string|int,mixed>> $rows Raw rows.
 * @param array<string,mixed>                $options Import options.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_import_rows(array $rows, array $options = []): array {
    $grouped = ll_tools_dictionary_group_import_rows($rows, $options);
    $summary = isset($grouped['summary']) && is_array($grouped['summary']) ? $grouped['summary'] : [];
    $import_summary = ll_tools_dictionary_apply_grouped_import_rows(
        isset($grouped['grouped_rows']) && is_array($grouped['grouped_rows']) ? $grouped['grouped_rows'] : [],
        $options
    );

    $summary['entries_created'] = (int) ($import_summary['entries_created'] ?? 0);
    $summary['entries_updated'] = (int) ($import_summary['entries_updated'] ?? 0);
    $summary['entry_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) ($import_summary['entry_ids'] ?? [])))));
    $summary['errors'] = array_values(array_filter(array_map('strval', (array) ($import_summary['errors'] ?? []))));
    $summary['error_count'] = count($summary['errors']);

    return $summary;
}

/**
 * Name of the legacy one-off dictionary importer table.
 */
function ll_tools_dictionary_get_legacy_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'dictionary_entries';
}

/**
 * Check whether the legacy raw dictionary table exists.
 */
function ll_tools_dictionary_legacy_table_exists(): bool {
    global $wpdb;
    $table = ll_tools_dictionary_get_legacy_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return $exists === $table;
}

/**
 * Import the legacy raw dictionary table in batches.
 *
 * @param array<string,mixed> $options Import options.
 * @return array<string,mixed>|WP_Error
 */
function ll_tools_dictionary_import_legacy_table(array $options = []) {
    global $wpdb;

    if (!ll_tools_dictionary_legacy_table_exists()) {
        return new WP_Error('ll_tools_dictionary_legacy_table_missing', __('Legacy dictionary table not found.', 'll-tools-text-domain'));
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $table = ll_tools_dictionary_get_legacy_table_name();
    $batch_size = max(50, min(1000, (int) ($options['batch_size'] ?? 500)));
    $offset = 0;
    $aggregate = [
        'rows_total' => 0,
        'rows_grouped' => 0,
        'rows_skipped_empty' => 0,
        'rows_skipped_review' => 0,
        'entries_created' => 0,
        'entries_updated' => 0,
        'entry_ids' => [],
        'errors' => [],
        'legacy_batches' => 0,
    ];

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entry, definition, gender_number, entry_type, parent, needs_review, page_number, entry_lang, def_lang
                 FROM {$table}
                 ORDER BY entry ASC, id ASC
                 LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            break;
        }

        $aggregate['legacy_batches']++;
        $batch_summary = ll_tools_dictionary_import_rows($rows, $options);
        $aggregate['rows_total'] += (int) ($batch_summary['rows_total'] ?? 0);
        $aggregate['rows_grouped'] += (int) ($batch_summary['rows_grouped'] ?? 0);
        $aggregate['rows_skipped_empty'] += (int) ($batch_summary['rows_skipped_empty'] ?? 0);
        $aggregate['rows_skipped_review'] += (int) ($batch_summary['rows_skipped_review'] ?? 0);
        $aggregate['entries_created'] += (int) ($batch_summary['entries_created'] ?? 0);
        $aggregate['entries_updated'] += (int) ($batch_summary['entries_updated'] ?? 0);
        $aggregate['entry_ids'] = array_merge($aggregate['entry_ids'], (array) ($batch_summary['entry_ids'] ?? []));
        $aggregate['errors'] = array_merge($aggregate['errors'], (array) ($batch_summary['errors'] ?? []));

        $offset += $batch_size;
    } while (!empty($rows));

    $aggregate['entry_ids'] = array_values(array_unique(array_filter(array_map('intval', $aggregate['entry_ids']))));
    $aggregate['error_count'] = count($aggregate['errors']);

    return $aggregate;
}

/**
 * Build front-end preview data for words linked to a dictionary entry.
 *
 * @return array<int,array{word_text:string,translation_text:string,wordset_id:int,wordset_name:string}>
 */
function ll_tools_dictionary_get_linked_word_previews(int $entry_id, int $limit = 4): array {
    $entry_id = (int) $entry_id;
    $limit = (int) $limit;
    if ($entry_id <= 0 || $limit <= 0 || !function_exists('ll_tools_get_dictionary_entry_word_ids')) {
        return [];
    }

    $word_ids = ll_tools_get_dictionary_entry_word_ids($entry_id, $limit);
    $items = [];

    foreach ($word_ids as $word_id) {
        $display = function_exists('ll_tools_get_word_display_with_translation')
            ? ll_tools_get_word_display_with_translation((int) $word_id)
            : ['word_text' => trim((string) get_the_title((int) $word_id)), 'translation_text' => ''];
        $word_text = trim((string) ($display['word_text'] ?? ''));
        $translation_text = trim((string) ($display['translation_text'] ?? ''));
        if ($word_text === '') {
            continue;
        }
        $wordset_id = function_exists('ll_tools_get_word_primary_wordset_id')
            ? (int) ll_tools_get_word_primary_wordset_id((int) $word_id)
            : 0;
        $wordset_name = '';
        if ($wordset_id > 0) {
            $wordset = get_term($wordset_id, 'wordset');
            if ($wordset && !is_wp_error($wordset)) {
                $wordset_name = (string) $wordset->name;
            }
        }
        $items[] = [
            'word_text' => $word_text,
            'translation_text' => $translation_text,
            'wordset_id' => $wordset_id,
            'wordset_name' => $wordset_name,
        ];
    }

    return $items;
}

/**
 * Build a structured display payload for one dictionary entry.
 *
 * @return array<string,mixed>
 */
function ll_tools_dictionary_get_entry_data(int $entry_id, int $sense_limit = 3, int $linked_word_limit = 4, array $preferred_languages = []): array {
    static $request_cache = [];

    $entry_id = (int) $entry_id;
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry') {
        return [];
    }

    $normalized_preferred_languages = [];
    foreach ($preferred_languages as $language) {
        $language_key = ll_tools_dictionary_normalize_language_key((string) $language);
        if ($language_key === '' || in_array($language_key, $normalized_preferred_languages, true)) {
            continue;
        }
        $normalized_preferred_languages[] = $language_key;
    }

    $cache_args = [
        'entry_id' => $entry_id,
        'sense_limit' => max(1, $sense_limit),
        'linked_word_limit' => max(0, $linked_word_limit),
        'preferred_languages' => $normalized_preferred_languages,
    ];
    $cached = ll_tools_dictionary_browser_get_cached_payload('entry_data', $cache_args, $request_cache);
    if (is_array($cached)) {
        return $cached;
    }

    $title = trim((string) get_the_title($entry_id));
    if ($title === '') {
        $title = __('(no title)', 'll-tools-text-domain');
    }

    $senses = ll_tools_get_dictionary_entry_senses($entry_id);

    $translation = '';
    if (empty($normalized_preferred_languages) && function_exists('ll_tools_get_dictionary_entry_translation')) {
        $translation = ll_tools_get_dictionary_entry_translation($entry_id);
    }
    if ($translation === '') {
        $translation = ll_tools_dictionary_build_translation_summary(
            $senses,
            trim(wp_strip_all_tags((string) get_post_field('post_content', $entry_id))),
            $normalized_preferred_languages
        );
    }

    $wordset_id = function_exists('ll_tools_get_dictionary_entry_wordset_id')
        ? (int) ll_tools_get_dictionary_entry_wordset_id($entry_id)
        : 0;
    $wordset_name = '';
    if ($wordset_id > 0) {
        $wordset = get_term($wordset_id, 'wordset');
        if ($wordset && !is_wp_error($wordset)) {
            $wordset_name = (string) $wordset->name;
        }
    }
    $linked_wordsets = function_exists('ll_tools_get_dictionary_entry_scope_wordsets')
        ? ll_tools_get_dictionary_entry_scope_wordsets($entry_id)
        : [];
    $wordset_names = array_values(array_filter(array_map(static function (array $item): string {
        return trim((string) ($item['name'] ?? ''));
    }, $linked_wordsets)));

    $pos_slug = function_exists('ll_tools_get_dictionary_entry_primary_pos_slug')
        ? ll_tools_get_dictionary_entry_primary_pos_slug($entry_id)
        : '';
    $pos_label = '';
    if ($pos_slug !== '') {
        $term = get_term_by('slug', $pos_slug, 'part_of_speech');
        if ($term && !is_wp_error($term)) {
            $pos_label = (string) $term->name;
        }
    }

    $entry_type = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_TYPE_META_KEY, true));
    if ($entry_type === '') {
        $entry_type = ll_tools_dictionary_get_primary_sense_value($senses, 'entry_type');
    }

    $page_number = trim((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_PAGE_META_KEY, true));
    if ($page_number === '') {
        $page_number = ll_tools_dictionary_get_primary_sense_value($senses, 'page_number');
    }

    $linked_word_count = function_exists('ll_tools_count_dictionary_entry_words')
        ? (int) ll_tools_count_dictionary_entry_words($entry_id)
        : 0;
    $sources = ll_tools_dictionary_collect_sources($senses);
    $dialects = ll_tools_dictionary_collect_dialects($senses);

    return ll_tools_dictionary_browser_store_cached_payload(
        'entry_data',
        $cache_args,
        [
        'id' => $entry_id,
        'title' => $title,
        'translation' => $translation,
        'entry_type' => $entry_type,
        'page_number' => $page_number,
        'wordset_id' => $wordset_id,
        'wordset_name' => $wordset_name,
        'wordset_names' => $wordset_names,
        'linked_wordsets' => $linked_wordsets,
        'pos_slug' => $pos_slug,
        'pos_label' => $pos_label,
        'linked_word_count' => $linked_word_count,
        'linked_words' => ll_tools_dictionary_get_linked_word_previews($entry_id, $linked_word_limit),
        'preferred_languages' => $normalized_preferred_languages,
        'sources' => $sources,
        'dialects' => $dialects,
        'senses' => array_slice($senses, 0, max(1, $sense_limit)),
        'sense_count' => count($senses),
        ],
        10 * MINUTE_IN_SECONDS,
        $request_cache
    );
}

/**
 * Query dictionary entries with ranked search and pagination.
 *
 * @param array<string,mixed> $args Query arguments.
 * @return array<string,mixed>
 */
function ll_tools_dictionary_query_entries(array $args = []): array {
    static $request_cache = [];
    global $wpdb;

    $search = trim((string) ($args['search'] ?? ''));
    $page = max(1, (int) ($args['page'] ?? 1));
    $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
    $wordset_id = max(0, (int) ($args['wordset_id'] ?? 0));
    $title_language = ll_tools_dictionary_get_wordset_title_language_code($wordset_id);
    $letter = ll_tools_dictionary_normalize_browse_letter((string) ($args['letter'] ?? ''), $title_language);
    $pos_slug = sanitize_title((string) ($args['pos_slug'] ?? ''));
    $source_id = ll_tools_dictionary_normalize_source_id((string) ($args['source_id'] ?? ''));
    $dialect = trim(sanitize_text_field((string) ($args['dialect'] ?? '')));
    $sense_limit = max(1, min(8, (int) ($args['sense_limit'] ?? 3)));
    $linked_word_limit = max(0, min(8, (int) ($args['linked_word_limit'] ?? 4)));
    $preferred_languages = [];
    foreach ((array) ($args['preferred_languages'] ?? []) as $language) {
        $language_key = ll_tools_dictionary_normalize_language_key((string) $language);
        if ($language_key === '' || in_array($language_key, $preferred_languages, true)) {
            continue;
        }
        $preferred_languages[] = $language_key;
    }
    $statuses = array_values(array_filter(array_map('sanitize_key', (array) ($args['post_status'] ?? ['publish']))));
    if (empty($statuses)) {
        $statuses = ['publish'];
    }
    sort($statuses);

    $cache_args = [
        'search' => $search,
        'page' => $page,
        'per_page' => $per_page,
        'wordset_id' => $wordset_id,
        'letter' => $letter,
        'pos_slug' => $pos_slug,
        'source_id' => $source_id,
        'dialect' => $dialect,
        'sense_limit' => $sense_limit,
        'linked_word_limit' => $linked_word_limit,
        'preferred_languages' => $preferred_languages,
        'statuses' => $statuses,
    ];
    $cached = ll_tools_dictionary_browser_get_cached_payload('query_entries', $cache_args, $request_cache);
    if (is_array($cached)) {
        return $cached;
    }

    $candidate_ids = [];
    $used_published_scope_cache = false;
    if ($search === '' && $statuses === ['publish']) {
        $used_published_scope_cache = true;
        $candidate_ids = ll_tools_dictionary_get_published_entry_ids_for_scope($wordset_id);
        $needs_meta_cache = ($pos_slug !== '' || $source_id !== '' || $dialect !== '');
        if ($needs_meta_cache && !empty($candidate_ids)) {
            update_postmeta_cache($candidate_ids);
        }
    } else {
        $joins = [];
        if ($pos_slug !== '') {
            $joins[] = "
                LEFT JOIN {$wpdb->postmeta} pos_meta
                       ON pos_meta.post_id = p.ID
                      AND pos_meta.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY) . "'
            ";
        }

        $where = ["p.post_type = 'll_dictionary_entry'"];
        $params = [];

        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $where[] = "p.post_status IN ({$status_placeholders})";
        $params = array_merge($params, $statuses);

        if ($pos_slug !== '') {
            $where[] = "pos_meta.meta_value = %s";
            $params[] = $pos_slug;
        }

        $order_sql = 'p.post_title ASC';
        $order_params = [];

        if ($search !== '') {
            $joins[] = "
                LEFT JOIN {$wpdb->postmeta} translation
                       ON translation.post_id = p.ID
                      AND translation.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_TRANSLATION_META_KEY) . "'
                LEFT JOIN {$wpdb->postmeta} lookup_title
                       ON lookup_title.post_id = p.ID
                      AND lookup_title.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TITLE_META_KEY) . "'
                LEFT JOIN {$wpdb->postmeta} lookup_translation
                       ON lookup_translation.post_id = p.ID
                      AND lookup_translation.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_LOOKUP_TRANSLATION_META_KEY) . "'
                LEFT JOIN {$wpdb->postmeta} search_index
                       ON search_index.post_id = p.ID
                      AND search_index.meta_key = '" . esc_sql(LL_TOOLS_DICTIONARY_ENTRY_SEARCH_INDEX_META_KEY) . "'
            ";

            $lookup = ll_tools_dictionary_entry_normalize_lookup_value($search);
            $search_norm = ll_tools_dictionary_normalize_search_text($search);
            $contains_raw = '%' . $wpdb->esc_like($search) . '%';
            $contains_lookup = '%' . $wpdb->esc_like($lookup) . '%';
            $contains_norm = '%' . $wpdb->esc_like($search_norm) . '%';

            $where[] = '(
                p.post_title LIKE %s
                OR translation.meta_value LIKE %s
                OR p.post_content LIKE %s
                OR lookup_title.meta_value LIKE %s
                OR lookup_translation.meta_value LIKE %s
                OR search_index.meta_value LIKE %s
            )';
            $params = array_merge($params, [
                $contains_raw,
                $contains_raw,
                $contains_raw,
                $contains_lookup,
                $contains_lookup,
                $contains_norm,
            ]);

            $prefix_lookup = $wpdb->esc_like($lookup) . '%';
            $prefix_raw = $wpdb->esc_like($search) . '%';
            $order_sql = "
                CASE
                    WHEN lookup_title.meta_value = %s THEN 0
                    WHEN lookup_translation.meta_value = %s THEN 1
                    WHEN lookup_title.meta_value LIKE %s THEN 2
                    WHEN lookup_translation.meta_value LIKE %s THEN 3
                    WHEN p.post_title LIKE %s THEN 4
                    WHEN translation.meta_value LIKE %s THEN 5
                    WHEN search_index.meta_value LIKE %s THEN 6
                    ELSE 7
                END,
                p.post_title ASC
            ";
            $order_params = [
                $lookup,
                $lookup,
                $prefix_lookup,
                $prefix_lookup,
                $prefix_raw,
                $prefix_raw,
                $contains_norm,
            ];
        }

        $where_sql = implode(' AND ', $where);
        $query_sql = "
            SELECT " . (!empty($joins) ? 'DISTINCT ' : '') . "p.ID
            FROM {$wpdb->posts} p
            " . implode("\n", $joins) . "
            WHERE {$where_sql}
            ORDER BY {$order_sql}
        ";
        $query_params = array_merge($params, $order_params);
        $candidate_ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($query_sql, $query_params)))));
        if (!empty($candidate_ids)) {
            update_postmeta_cache($candidate_ids);
        }
    }

    $filtered_ids = [];
    foreach ($candidate_ids as $entry_id) {
        if ($pos_slug !== '') {
            $entry_pos_slug = sanitize_title((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, true));
            if ($entry_pos_slug !== $pos_slug) {
                continue;
            }
        }
        if (
            $wordset_id > 0
            && !$used_published_scope_cache
            && !ll_tools_dictionary_entry_matches_wordset_context($entry_id, $wordset_id)
        ) {
            continue;
        }
        if ($source_id !== '' && !ll_tools_dictionary_entry_matches_source_filter($entry_id, $source_id)) {
            continue;
        }
        if ($dialect !== '' && !ll_tools_dictionary_entry_matches_dialect_filter($entry_id, $dialect)) {
            continue;
        }
        if ($letter !== '' && !ll_tools_dictionary_entry_matches_browse_letter($entry_id, $letter, $title_language)) {
            continue;
        }

        $filtered_ids[] = $entry_id;
    }

    if ($search === '' && !empty($filtered_ids)) {
        usort($filtered_ids, static function (int $left_id, int $right_id) use ($title_language): int {
            $left_title = (string) get_the_title($left_id);
            $right_title = (string) get_the_title($right_id);
            $compared = function_exists('ll_tools_locale_compare_strings')
                ? ll_tools_locale_compare_strings($left_title, $right_title, $title_language)
                : strnatcasecmp($left_title, $right_title);
            if ($compared !== 0) {
                return $compared;
            }

            return $left_id <=> $right_id;
        });
    }

    $total = count($filtered_ids);
    $total_pages = max(1, (int) ceil($total / $per_page));
    if ($total === 0) {
        return ll_tools_dictionary_browser_store_cached_payload(
            'query_entries',
            $cache_args,
            [
            'items' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => $per_page,
            'total_pages' => 1,
            'search' => $search,
            'letter' => $letter,
            'wordset_id' => $wordset_id,
            'source_id' => $source_id,
            'dialect' => $dialect,
            'pos_slug' => $pos_slug,
            'preferred_languages' => $preferred_languages,
            ],
            10 * MINUTE_IN_SECONDS,
            $request_cache
        );
    }

    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    $ids = array_slice($filtered_ids, $offset, $per_page);

    if (!empty($ids)) {
        update_postmeta_cache($ids);
    }

    $items = [];
    foreach ($ids as $entry_id) {
        $item = ll_tools_dictionary_get_entry_data($entry_id, $sense_limit, $linked_word_limit, $preferred_languages);
        if (!empty($item)) {
            $items[] = $item;
        }
    }

    return ll_tools_dictionary_browser_store_cached_payload(
        'query_entries',
        $cache_args,
        [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'search' => $search,
        'letter' => $letter,
        'wordset_id' => $wordset_id,
        'source_id' => $source_id,
        'dialect' => $dialect,
        'pos_slug' => $pos_slug,
        'preferred_languages' => $preferred_languages,
        ],
        10 * MINUTE_IN_SECONDS,
        $request_cache
    );
}

/**
 * Return published dictionary entry IDs for scope-sensitive filter builders.
 *
 * @return int[]
 */
function ll_tools_dictionary_get_published_entry_ids_for_scope(int $wordset_id = 0): array {
    static $request_cache = [];
    global $wpdb;

    $cached = ll_tools_dictionary_browser_get_cached_payload('published_entry_ids', [
        'wordset_id' => $wordset_id,
    ], $request_cache);
    if (is_array($cached)) {
        return $cached;
    }

    $ids = array_values(array_filter(array_map('intval', (array) $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'll_dictionary_entry' AND post_status = 'publish'"
    ))));
    if ($wordset_id <= 0) {
        return ll_tools_dictionary_browser_store_cached_payload(
            'published_entry_ids',
            ['wordset_id' => $wordset_id],
            $ids,
            10 * MINUTE_IN_SECONDS,
            $request_cache
        );
    }

    $ids = array_values(array_filter($ids, static function (int $entry_id) use ($wordset_id): bool {
        return ll_tools_dictionary_entry_matches_wordset_context($entry_id, $wordset_id);
    }));

    return ll_tools_dictionary_browser_store_cached_payload(
        'published_entry_ids',
        ['wordset_id' => $wordset_id],
        $ids,
        10 * MINUTE_IN_SECONDS,
        $request_cache
    );
}

/**
 * Build the cached front-end filter index for one dictionary scope.
 *
 * @return array{letters:string[],pos_options:array<int,array{slug:string,label:string}>,source_options:array<int,array{id:string,label:string}>,dialect_options:string[]}
 */
function ll_tools_dictionary_get_scope_filter_index(int $wordset_id = 0): array {
    static $request_cache = [];

    $cache_args = ['wordset_id' => $wordset_id];
    $cached = ll_tools_dictionary_browser_get_cached_payload('scope_filter_index', $cache_args, $request_cache);
    if (is_array($cached)) {
        return $cached;
    }

    $language = ll_tools_dictionary_get_wordset_title_language_code($wordset_id);
    $letters = [];
    $pos_slugs = [];
    $source_options = [];
    $dialects = [];
    $entry_ids = ll_tools_dictionary_get_published_entry_ids_for_scope($wordset_id);

    if (!empty($entry_ids)) {
        update_postmeta_cache($entry_ids);
    }

    foreach ($entry_ids as $entry_id) {
        $letter = ll_tools_dictionary_normalize_browse_letter((string) get_the_title($entry_id), $language);
        if ($letter !== '') {
            $letters[$letter] = true;
        }

        $slug = sanitize_title((string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_POS_META_KEY, true));
        if ($slug !== '') {
            $pos_slugs[$slug] = true;
        }

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        foreach (ll_tools_dictionary_collect_sources($senses) as $source) {
            $source_id = ll_tools_dictionary_normalize_source_id((string) ($source['id'] ?? ''));
            $label = trim((string) ($source['label'] ?? ''));
            if ($source_id === '' || $label === '') {
                continue;
            }
            $source_options[$source_id] = [
                'id' => $source_id,
                'label' => $label,
            ];
        }
        foreach (ll_tools_dictionary_collect_dialects($senses) as $dialect) {
            $dialect_key = ll_tools_dictionary_normalize_dialect_key($dialect);
            if ($dialect_key === '' || isset($dialects[$dialect_key])) {
                continue;
            }
            $dialects[$dialect_key] = $dialect;
        }
    }

    $alphabet = ll_tools_dictionary_get_language_browse_alphabet($language);
    $ordered = [];

    foreach ($alphabet as $letter) {
        if ($letter === '' || isset($ordered[$letter])) {
            continue;
        }
        $ordered[$letter] = true;
    }

    $extras = array_values(array_diff(array_keys($letters), array_keys($ordered)));
    usort($extras, static function (string $left, string $right) use ($language): int {
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left, $right, $language)
            : strnatcasecmp($left, $right);
    });

    foreach ($extras as $letter) {
        $ordered[$letter] = true;
    }

    if (empty($ordered)) {
        $ordered_letters = [];
    } else {
        $ordered_letters = array_keys($ordered);
    }

    $options = [];
    foreach (array_keys($pos_slugs) as $slug) {
        $term = get_term_by('slug', (string) $slug, 'part_of_speech');
        $label = ($term && !is_wp_error($term))
            ? (string) $term->name
            : (string) $slug;
        if ($label === '') {
            continue;
        }
        $options[$slug] = [
            'slug' => (string) $slug,
            'label' => $label,
        ];
    }

    $options = array_values($options);
    usort($options, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    $source_items = array_values($source_options);
    usort($source_items, static function (array $left, array $right): int {
        $left_label = (string) ($left['label'] ?? '');
        $right_label = (string) ($right['label'] ?? '');
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left_label, $right_label)
            : strnatcasecmp($left_label, $right_label);
    });

    $dialect_labels = array_values($dialects);
    usort($dialect_labels, static function (string $left, string $right): int {
        return function_exists('ll_tools_locale_compare_strings')
            ? ll_tools_locale_compare_strings($left, $right)
            : strnatcasecmp($left, $right);
    });

    return ll_tools_dictionary_browser_store_cached_payload(
        'scope_filter_index',
        $cache_args,
        [
            'letters' => $ordered_letters,
            'pos_options' => array_values($options),
            'source_options' => $source_items,
            'dialect_options' => $dialect_labels,
        ],
        HOUR_IN_SECONDS,
        $request_cache
    );
}

/**
 * Collect available browse letters for the current dictionary scope.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_available_letters(int $wordset_id = 0): array {
    $index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
    return array_values(array_filter(array_map('strval', (array) ($index['letters'] ?? []))));
}

/**
 * Collect available part-of-speech filter options for the current scope.
 *
 * @return array<int,array{slug:string,label:string}>
 */
function ll_tools_dictionary_get_pos_filter_options(int $wordset_id = 0): array {
    $index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
    return array_values(array_filter((array) ($index['pos_options'] ?? []), 'is_array'));
}

/**
 * Collect available source filter options for the current dictionary scope.
 *
 * @return array<int,array{id:string,label:string}>
 */
function ll_tools_dictionary_get_source_filter_options(int $wordset_id = 0): array {
    $index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
    return array_values(array_filter((array) ($index['source_options'] ?? []), 'is_array'));
}

/**
 * Collect available dialect filter options for the current dictionary scope.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_dialect_filter_options(int $wordset_id = 0): array {
    $index = ll_tools_dictionary_get_scope_filter_index($wordset_id);
    return array_values(array_filter(array_map('strval', (array) ($index['dialect_options'] ?? []))));
}

/**
 * Compare stored/requested language values loosely by label or code.
 */
function ll_tools_dictionary_language_matches(string $stored, string $requested): bool {
    $requested = trim((string) $requested);
    if ($requested === '' || strtolower($requested) === 'auto') {
        return true;
    }

    $stored = trim((string) $stored);
    if ($stored === '') {
        return true;
    }

    $normalize = static function (string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('ll_tools_resolve_language_code_from_label')) {
            $resolved = (string) ll_tools_resolve_language_code_from_label($value, 'lower');
            if ($resolved !== '') {
                return $resolved;
            }
        }
        return strtolower($value);
    };

    return $normalize($stored) === $normalize($requested);
}

/**
 * Compute a small lookup score for one text candidate.
 */
function ll_tools_dictionary_lookup_text_score(string $candidate, string $term): int {
    $candidate_lookup = ll_tools_dictionary_entry_normalize_lookup_value($candidate);
    $term_lookup = ll_tools_dictionary_entry_normalize_lookup_value($term);
    $candidate_norm = ll_tools_dictionary_normalize_search_text($candidate);
    $term_norm = ll_tools_dictionary_normalize_search_text($term);

    if ($candidate_lookup !== '' && $candidate_lookup === $term_lookup) {
        return 0;
    }
    if ($candidate_norm !== '' && $candidate_norm === $term_norm) {
        return 1;
    }
    if ($term_lookup !== '' && strpos($candidate_lookup, $term_lookup) === 0) {
        return 2;
    }
    if ($term_norm !== '' && strpos($candidate_norm, $term_norm) === 0) {
        return 3;
    }
    if ($term_lookup !== '' && strpos($candidate_lookup, $term_lookup) !== false) {
        return 4;
    }
    if ($term_norm !== '' && strpos($candidate_norm, $term_norm) !== false) {
        return 5;
    }

    return 99;
}

/**
 * Collect lookup candidates from one sense for a requested language.
 *
 * @return string[]
 */
function ll_tools_dictionary_get_sense_lookup_candidates(array $sense, string $requested_language = '', bool $allow_fallback = true): array {
    $requested_language = trim((string) $requested_language);
    $requested_language_key = ll_tools_dictionary_normalize_language_key($requested_language);
    $translations = ll_tools_dictionary_get_sense_translations($sense);
    $candidates = [];

    if ($requested_language === '' || strtolower($requested_language) === 'auto') {
        foreach ($translations as $text) {
            $text = trim((string) $text);
            if ($text !== '') {
                $candidates[] = $text;
            }
        }
    } elseif ($requested_language_key !== '' && !empty($translations[$requested_language_key])) {
        $candidates[] = (string) $translations[$requested_language_key];
    }

    $definition = trim((string) ($sense['definition'] ?? ''));
    $def_lang_key = ll_tools_dictionary_normalize_language_key((string) ($sense['def_lang'] ?? ''));
    if ($definition !== '' && (
        empty($translations)
        || $requested_language === ''
        || strtolower($requested_language) === 'auto'
        || $requested_language_key === ''
        || $def_lang_key === ''
        || $def_lang_key === $requested_language_key
    )) {
        $candidates[] = $definition;
    }

    if ($allow_fallback && empty($candidates)) {
        $fallback = ll_tools_dictionary_get_preferred_translation_text(
            $sense,
            $requested_language_key !== '' ? [$requested_language_key] : [],
            true
        );
        if ($fallback !== '') {
            $candidates[] = $fallback;
        }
    }

    $unique = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        $lookup = ll_tools_dictionary_entry_normalize_lookup_value($candidate);
        if ($candidate === '' || $lookup === '' || isset($seen[$lookup])) {
            continue;
        }
        $seen[$lookup] = true;
        $unique[] = $candidate;
    }

    return $unique;
}

/**
 * Build a short translation summary for a requested language, if available.
 *
 * @param array<int,array<string,mixed>> $senses Sense list.
 */
function ll_tools_dictionary_build_requested_translation_summary(array $senses, string $language = ''): string {
    $language = trim((string) $language);
    $values = [];
    $seen = [];

    foreach ($senses as $sense) {
        foreach (ll_tools_dictionary_get_sense_lookup_candidates((array) $sense, $language, false) as $candidate) {
            $lookup = ll_tools_dictionary_entry_normalize_lookup_value($candidate);
            if ($lookup === '' || isset($seen[$lookup])) {
                continue;
            }
            $seen[$lookup] = true;
            $values[] = $candidate;
            if (count($values) >= 3) {
                break 2;
            }
        }
    }

    if (!empty($values)) {
        return implode('; ', $values);
    }

    if ($language === '' || strtolower($language) === 'auto') {
        return ll_tools_dictionary_build_translation_summary($senses);
    }

    return '';
}

/**
 * Lookup the best dictionary translation suggestion from ll_dictionary_entry posts.
 */
function ll_tools_dictionary_lookup_best($word, $source_lang, $target_lang, $reverse = false): ?string {
    $term = trim((string) $word);
    if ($term === '' || !function_exists('ll_tools_dictionary_query_entries')) {
        return null;
    }

    $results = ll_tools_dictionary_query_entries([
        'search' => $term,
        'per_page' => 40,
        'page' => 1,
        'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
        'sense_limit' => 8,
        'linked_word_limit' => 0,
        'preferred_languages' => !$reverse ? [(string) $target_lang] : [(string) $source_lang],
    ]);

    $items = (array) ($results['items'] ?? []);
    if (empty($items)) {
        return null;
    }

    $best_score = 999;
    $best_value = null;

    foreach ($items as $item) {
        $entry_id = (int) ($item['id'] ?? 0);
        if ($entry_id <= 0) {
            continue;
        }

        $entry_lang = (string) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_ENTRY_LANG_META_KEY, true);
        if ($reverse) {
            if (!ll_tools_dictionary_language_matches($entry_lang, (string) $target_lang)) {
                continue;
            }
        } else {
            if (!ll_tools_dictionary_language_matches($entry_lang, (string) $source_lang)) {
                continue;
            }
        }

        $senses = ll_tools_get_dictionary_entry_senses($entry_id);
        $score = 99;
        $candidate_value = '';

        if ($reverse) {
            foreach ($senses as $sense) {
                if (!ll_tools_dictionary_language_matches((string) ($sense['entry_lang'] ?? $entry_lang), (string) $target_lang)) {
                    continue;
                }

                foreach (ll_tools_dictionary_get_sense_lookup_candidates((array) $sense, (string) $source_lang, false) as $definition) {
                    $def_score = ll_tools_dictionary_lookup_text_score($definition, $term);
                    if ($def_score < $score) {
                        $score = $def_score;
                    }
                }
            }

            if ($score < 99) {
                $candidate_value = trim((string) ($item['title'] ?? ''));
            }
        } else {
            $score = ll_tools_dictionary_lookup_text_score((string) ($item['title'] ?? ''), $term);
            $candidate_value = ll_tools_dictionary_build_requested_translation_summary($senses, (string) $target_lang);
            if ($candidate_value === '' && (trim((string) $target_lang) === '' || strtolower(trim((string) $target_lang)) === 'auto')) {
                $candidate_value = (string) ($item['translation'] ?? '');
            }
            if ($candidate_value === '' && (trim((string) $target_lang) === '' || strtolower(trim((string) $target_lang)) === 'auto')) {
                $candidate_value = ll_tools_dictionary_build_translation_summary($senses);
            }
        }

        if ($candidate_value === '' || $score >= 99) {
            continue;
        }

        if ($score < $best_score) {
            $best_score = $score;
            $best_value = $candidate_value;
        }
    }

    return ($best_value !== null && trim((string) $best_value) !== '') ? trim((string) $best_value) : null;
}

/**
 * Show imported senses on the dictionary entry edit screen.
 */
function ll_tools_dictionary_entry_add_imported_senses_metabox(): void {
    add_meta_box(
        'll-tools-dictionary-entry-imported-senses',
        __('Imported Senses', 'll-tools-text-domain'),
        'll_tools_dictionary_entry_render_imported_senses_metabox',
        'll_dictionary_entry',
        'normal',
        'default'
    );
}
add_action('add_meta_boxes_ll_dictionary_entry', 'll_tools_dictionary_entry_add_imported_senses_metabox');

/**
 * Render imported senses metabox content.
 */
function ll_tools_dictionary_entry_render_imported_senses_metabox($post): void {
    if (!($post instanceof WP_Post) || $post->post_type !== 'll_dictionary_entry') {
        return;
    }
    if (!current_user_can('view_ll_tools')) {
        return;
    }

    $senses = ll_tools_get_dictionary_entry_senses((int) $post->ID);
    if (empty($senses)) {
        echo '<p>' . esc_html__('No structured senses have been imported for this entry yet.', 'll-tools-text-domain') . '</p>';
        return;
    }

    echo '<div class="widefat striped" style="border:1px solid #dcdcde;border-radius:6px;overflow:hidden;">';
    echo '<table class="widefat striped" style="border:0;margin:0;">';
    echo '<thead><tr>';
    echo '<th>' . esc_html__('Glosses', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Type', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Gender/Number', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Parent', 'll-tools-text-domain') . '</th>';
    echo '<th>' . esc_html__('Page', 'll-tools-text-domain') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($senses as $sense) {
        $gloss_lines = [];
        foreach (ll_tools_dictionary_get_sense_translations((array) $sense) as $language => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }

            $label = ll_tools_dictionary_get_language_label((string) $language);
            $line = $label !== ''
                ? '<strong>' . esc_html($label) . ':</strong> ' . esc_html($text)
                : esc_html($text);
            $gloss_lines[] = $line;
        }

        if (empty($gloss_lines)) {
            $definition = trim((string) ($sense['definition'] ?? ''));
            if ($definition !== '') {
                $gloss_lines[] = esc_html($definition);
            }
        }

        echo '<tr>';
        echo '<td>' . wp_kses_post(implode('<br>', $gloss_lines)) . '</td>';
        echo '<td>' . esc_html((string) ($sense['entry_type'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['gender_number'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['parent'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($sense['page_number'] ?? '')) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
