<?php
/** Wordset configuration for ZIPA speaking games. */

defined('WPINC') || exit;

const LL_TOOLS_ZIPA_SETTINGS_META_KEY = '_ll_tools_zipa_settings';
const LL_TOOLS_ZIPA_CARD_LIMIT = 60;

function ll_tools_zipa_default_settings(): array {
    return [
        'enabled' => false,
        'audience' => 'learners',
        'browserModelManifestUrl' => '',
        'hostedEnabled' => false,
        'cardIds' => [],
        'cardReferences' => [],
        'ipaAliases' => [],
        'matcher' => [],
        'stackCapture' => [],
    ];
}

function ll_tools_zipa_get_settings(int $wordset_id): array {
    $stored = $wordset_id > 0 ? get_term_meta($wordset_id, LL_TOOLS_ZIPA_SETTINGS_META_KEY, true) : [];
    if (!is_array($stored)) {
        return ll_tools_zipa_default_settings();
    }

    try {
        return ll_tools_zipa_sanitize_settings($stored);
    } catch (InvalidArgumentException $error) {
        return ll_tools_zipa_default_settings();
    }
}

function ll_tools_zipa_setting_error(string $field): InvalidArgumentException {
    /* translators: %s: ZIPA setting name. */
    return new InvalidArgumentException(sprintf(__('Check the ZIPA setting “%s”.', 'll-tools-text-domain'), $field));
}

function ll_tools_zipa_config_text($raw, string $field, int $max = 256, bool $allow_empty = false): string {
    if (!is_string($raw) || (!$allow_empty && trim($raw) === '') || strlen($raw) > $max
        || preg_match('/\A[^\p{Cc}<>]*\z/u', $raw) !== 1) {
        throw ll_tools_zipa_setting_error($field);
    }
    return $raw;
}

function ll_tools_zipa_config_array($raw, string $field, int $max, bool $list = false): array {
    if (!is_array($raw) || count($raw) > $max || ($list && array_values($raw) !== $raw)) {
        throw ll_tools_zipa_setting_error($field);
    }
    return $raw;
}

function ll_tools_zipa_config_text_list($raw, string $field, int $max, int $max_bytes, bool $allow_empty = false): array {
    return array_values(array_unique(array_map(
        static fn($value): string => ll_tools_zipa_config_text($value, $field, $max_bytes, $allow_empty),
        ll_tools_zipa_config_array($raw, $field, $max, true)
    )));
}

function ll_tools_zipa_config_id($raw, string $field): int {
    if ((!is_string($raw) && !is_int($raw)) || !preg_match('/\A[1-9][0-9]{0,15}\z/', (string) $raw)
        || (float) $raw > 9007199254740991 || (float) $raw > PHP_INT_MAX) {
        throw ll_tools_zipa_setting_error($field);
    }
    return (int) $raw;
}

function ll_tools_zipa_config_text_map($raw, string $field, bool $allow_empty = false, bool $single_symbol_keys = false): array {
    $map = [];
    foreach (ll_tools_zipa_config_array($raw, $field, 256) as $from => $to) {
        ll_tools_zipa_config_text($from, $field, 64);
        if ($single_symbol_keys && preg_match('/\A.\z/u', $from) !== 1) {
            throw ll_tools_zipa_setting_error($field);
        }
        $map[$from] = ll_tools_zipa_config_text($to, $field, 64, $allow_empty);
    }
    return $map;
}

/** Settings may refer only to publicly reachable files on this installation's origin. */
function ll_tools_zipa_config_asset_url($raw, string $field): string {
    $url = trim(ll_tools_zipa_config_text($raw, $field, 2048, true));
    if ($url === '') {
        return '';
    }
    $origin = wp_parse_url(home_url('/'));
    $parts = wp_parse_url($url);
    if (!is_array($origin) || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment']) || !str_starts_with($parts['path'] ?? '', '/')
        || str_contains($url, '\\') || str_starts_with($url, '//')) {
        throw ll_tools_zipa_setting_error($field);
    }
    if (str_starts_with($url, '/')) {
        $url = $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '') . $url;
        $parts = wp_parse_url($url);
    }

    $local_http = ($origin['scheme'] ?? '') === 'http'
        && in_array($origin['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
    $scheme = $local_http ? 'http' : 'https';
    $port = $local_http ? 80 : 443;
    if (($parts['scheme'] ?? '') !== $scheme || ($origin['scheme'] ?? '') !== $scheme
        || strcasecmp($parts['host'] ?? '', $origin['host'] ?? '') !== 0
        || ($parts['port'] ?? $port) !== ($origin['port'] ?? $port)
        || esc_url_raw($url, [$scheme]) !== $url) {
        throw ll_tools_zipa_setting_error($field);
    }
    return $url;
}

function ll_tools_zipa_sanitize_matcher_profile($raw): array {
    $profile = [];
    foreach (ll_tools_zipa_config_array($raw, 'matcher', 20) as $field => $value) {
        $profile[$field] = match ($field) {
            'inputSymbols' => ll_tools_zipa_config_text_map($value, $field, true, true),
            'lowercaseMap', 'uppercaseMap' => ll_tools_zipa_config_text_map($value, $field, false, true),
            'confusableGraphemes' => ll_tools_zipa_config_text_map($value, $field),
            'tiedPairs', 'ignorableIpaMarks', 'roundedVowels', 'joinedConfusables', 'internalJoinedConfusables'
                => ll_tools_zipa_config_text_list($value, $field, 96, 24),
            'roundedVowelOnsets', 'repairableConsonants', 'repairableVowels'
                => ll_tools_zipa_config_text($value, $field, 256, true),
            'openVowelAlternatives' => ll_tools_zipa_sanitize_vowel_alternatives($value),
            'closeForms' => ll_tools_zipa_sanitize_close_forms($value),
            'displayRules' => ll_tools_zipa_sanitize_display_rules($value),
            'ipaReplacements' => ll_tools_zipa_sanitize_ipa_replacements($value),
            default => throw ll_tools_zipa_setting_error((string) $field),
        };
    }
    return $profile;
}

function ll_tools_zipa_sanitize_vowel_alternatives($raw): array {
    $alternatives = [];
    foreach (ll_tools_zipa_config_array($raw, 'openVowelAlternatives', 96) as $vowel => $forms) {
        ll_tools_zipa_config_text($vowel, 'openVowelAlternatives', 24);
        $alternatives[$vowel] = ll_tools_zipa_config_text_list($forms, 'openVowelAlternatives', 24, 24);
    }
    return $alternatives;
}

function ll_tools_zipa_sanitize_close_forms($raw): array {
    $forms = [];
    foreach (ll_tools_zipa_config_array($raw, 'closeForms', LL_TOOLS_ZIPA_CARD_LIMIT) as $word_id => $entry) {
        $entry = ll_tools_zipa_config_array($entry, 'closeForms', 2);
        $forms[ll_tools_zipa_config_id($word_id, 'closeForms')] = [
            'target' => ll_tools_zipa_config_text($entry['target'] ?? null, 'target'),
            'heard' => ll_tools_zipa_config_text_list($entry['heard'] ?? null, 'heard', 64, 256),
        ];
    }
    return $forms;
}

function ll_tools_zipa_sanitize_display_rules($raw): array {
    $rules = [];
    foreach (ll_tools_zipa_config_array($raw, 'displayRules', 4) as $field => $value) {
        $rules[$field] = match ($field) {
            'phones', 'finalPhones' => ll_tools_zipa_config_text_map($value, $field, true),
            'ignoredMarks' => ll_tools_zipa_config_text_list($value, $field, 96, 24),
            'clusters' => array_map(static function ($pair): array {
                $pair = ll_tools_zipa_config_array($pair, 'clusters', 2, true);
                return [ll_tools_zipa_config_text($pair[0] ?? null, 'clusters', 64),
                    ll_tools_zipa_config_text($pair[1] ?? null, 'clusters', 64, true)];
            }, ll_tools_zipa_config_array($value, 'clusters', 256, true)),
            default => throw ll_tools_zipa_setting_error((string) $field),
        };
    }
    return $rules;
}

function ll_tools_zipa_sanitize_ipa_replacements($raw): array {
    $rules = [];
    foreach (ll_tools_zipa_config_array($raw, 'ipaReplacements', 256, true) as $entry) {
        $rule = [];
        foreach (ll_tools_zipa_config_array($entry, 'ipaReplacements', 5) as $field => $value) {
            $rule[$field] = match ($field) {
                'from' => ll_tools_zipa_config_text($value, $field, 64),
                'to', 'ignorePreceding' => ll_tools_zipa_config_text($value, $field, 64, true),
                'precededBy', 'followedBy' => ll_tools_zipa_config_text_list($value, $field, 64, 64, true),
                default => throw ll_tools_zipa_setting_error((string) $field),
            };
        }
        if (!isset($rule['from'], $rule['to'])) {
            throw ll_tools_zipa_setting_error('ipaReplacements');
        }
        $rules[] = $rule;
    }
    return $rules;
}

function ll_tools_zipa_sanitize_card_references($raw): array {
    $cards = [];
    foreach (ll_tools_zipa_config_array($raw, 'cardReferences', LL_TOOLS_ZIPA_CARD_LIMIT) as $word_id => $entry) {
        $card = [];
        foreach (ll_tools_zipa_config_array($entry, 'cardReferences', 4) as $field => $value) {
            $card[$field] = match ($field) {
                'recordingId' => ll_tools_zipa_config_id($value, $field),
                'imageUrl' => ll_tools_zipa_config_asset_url($value, $field),
                'ipa', 'title' => trim(ll_tools_zipa_config_text($value, $field)),
                default => throw ll_tools_zipa_setting_error((string) $field),
            };
        }
        $cards[ll_tools_zipa_config_id($word_id, 'cardReferences')] = $card;
    }
    return $cards;
}

function ll_tools_zipa_sanitize_stack_capture($raw): array {
    $settings = [];
    foreach (ll_tools_zipa_config_array($raw, 'stackCapture', 3) as $field => $value) {
        if (!is_int($value) && !is_float($value)) {
            throw ll_tools_zipa_setting_error((string) $field);
        }
        $settings[$field] = match ($field) {
            'speechStartThreshold', 'silenceThreshold' => $value > 0 && $value <= 1 ? $value : throw ll_tools_zipa_setting_error($field),
            'feedbackHoldMs' => is_int($value) && $value >= 0 && $value <= 5000 ? $value : throw ll_tools_zipa_setting_error($field),
            default => throw ll_tools_zipa_setting_error((string) $field),
        };
    }
    if (isset($settings['speechStartThreshold'], $settings['silenceThreshold'])
        && $settings['silenceThreshold'] > $settings['speechStartThreshold']) {
        throw ll_tools_zipa_setting_error('silenceThreshold');
    }
    return $settings;
}

function ll_tools_zipa_sanitize_settings(array $raw): array {
    $defaults = ll_tools_zipa_default_settings();
    $raw += $defaults;
    if (!is_bool($raw['enabled']) || !is_bool($raw['hostedEnabled'])
        || !in_array($raw['audience'], ['everyone', 'learners', 'managers'], true)) {
        throw ll_tools_zipa_setting_error('access');
    }
    $settings = array_intersect_key($raw, $defaults);
    $settings['browserModelManifestUrl'] = ll_tools_zipa_config_asset_url($raw['browserModelManifestUrl'], 'browserModelManifestUrl');
    $settings['cardIds'] = array_values(array_unique(array_map(
        static fn($id): int => ll_tools_zipa_config_id($id, 'cardIds'),
        ll_tools_zipa_config_array($raw['cardIds'], 'cardIds', LL_TOOLS_ZIPA_CARD_LIMIT, true)
    )));
    $settings['cardReferences'] = ll_tools_zipa_sanitize_card_references($raw['cardReferences']);
    $settings['matcher'] = ll_tools_zipa_sanitize_matcher_profile($raw['matcher']);
    $settings['stackCapture'] = ll_tools_zipa_sanitize_stack_capture($raw['stackCapture']);
    $settings['ipaAliases'] = [];
    foreach (ll_tools_zipa_config_array($raw['ipaAliases'], 'ipaAliases', LL_TOOLS_ZIPA_CARD_LIMIT) as $word_id => $aliases) {
        $settings['ipaAliases'][ll_tools_zipa_config_id($word_id, 'ipaAliases')] = ll_tools_zipa_config_text_list($aliases, 'ipaAliases', 64, 256);
    }
    $encoded = wp_json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || strlen($encoded) > 98304) {
        throw ll_tools_zipa_setting_error('settings');
    }
    return array_replace($defaults, $settings);
}

/** Save one settings record. The caller must authorize the wordset and the action. */
function ll_tools_zipa_save_settings(int $wordset_id, array $raw) {
    $term = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    if (!($term instanceof WP_Term)) {
        return new WP_Error('ll_zipa_invalid_wordset', __('Choose a valid wordset.', 'll-tools-text-domain'));
    }

    try {
        $settings = ll_tools_zipa_sanitize_settings($raw);
    } catch (InvalidArgumentException $error) {
        return new WP_Error('ll_zipa_invalid_setting', $error->getMessage());
    }

    $word_ids = array_unique(array_merge($settings['cardIds'], array_keys($settings['cardReferences']),
        array_keys($settings['ipaAliases']), array_keys($settings['matcher']['closeForms'] ?? [])));
    foreach ($word_ids as $word_id) {
        if (get_post_type($word_id) !== 'words' || !has_term($wordset_id, 'wordset', $word_id)) {
            /* translators: %d: Word post ID. */
            return new WP_Error('ll_zipa_invalid_word', sprintf(__('Word %d must belong to this wordset.', 'll-tools-text-domain'), $word_id));
        }
    }
    foreach ($settings['cardReferences'] as $word_id => $reference) {
        if (isset($reference['recordingId']) && (get_post_type($reference['recordingId']) !== 'word_audio'
            || (int) wp_get_post_parent_id($reference['recordingId']) !== $word_id)) {
            /* translators: %d: Word post ID. */
            return new WP_Error('ll_zipa_invalid_recording', sprintf(__('Choose a recording that belongs to word %d.', 'll-tools-text-domain'), $word_id));
        }
    }

    $stored = get_term_meta($wordset_id, LL_TOOLS_ZIPA_SETTINGS_META_KEY, true);
    if ($stored === $settings) {
        return true;
    }
    $result = update_term_meta($wordset_id, LL_TOOLS_ZIPA_SETTINGS_META_KEY, wp_slash($settings));
    if (is_wp_error($result)) {
        return $result;
    }
    return $result || get_term_meta($wordset_id, LL_TOOLS_ZIPA_SETTINGS_META_KEY, true) === $settings
        ? true
        : new WP_Error('ll_zipa_save_failed', __('WordPress could not save the ZIPA settings.', 'll-tools-text-domain'));
}
