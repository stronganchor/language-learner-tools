<?php
if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_normalize_sort_locale')) {
    function ll_tools_normalize_sort_locale($locale): string {
        $value = strtolower(str_replace('-', '_', trim((string) $locale)));
        if ($value === '') {
            return 'en_us';
        }
        return $value;
    }
}

if (!function_exists('ll_tools_get_sort_locale')) {
    function ll_tools_get_sort_locale(): string {
        $locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';
        $locale = apply_filters('ll_tools_sort_locale', $locale);
        return ll_tools_normalize_sort_locale($locale);
    }
}

if (!function_exists('ll_tools_is_turkish_locale')) {
    function ll_tools_is_turkish_locale($locale = ''): bool {
        $normalized = ll_tools_normalize_sort_locale($locale ?: ll_tools_get_sort_locale());
        return strpos($normalized, 'tr') === 0;
    }
}

if (!function_exists('ll_tools_language_uses_turkish_casing')) {
    function ll_tools_language_uses_turkish_casing($language = ''): bool {
        $raw = trim((string) $language);
        if ($raw === '') {
            return false;
        }

        $normalized = '';
        if (function_exists('ll_tools_resolve_language_code_from_label')) {
            $normalized = (string) ll_tools_resolve_language_code_from_label($raw, 'lower');
        }
        if ($normalized === '') {
            $normalized = strtolower($raw);
        }

        return in_array($normalized, ['tr', 'tur', 'zza', 'diq', 'kiu'], true);
    }
}

if (!function_exists('ll_tools_lowercase_for_language')) {
    function ll_tools_lowercase_for_language(string $value, $language = ''): string {
        if (ll_tools_language_uses_turkish_casing($language)) {
            $value = strtr($value, [
                'I' => 'ı',
                'İ' => 'i',
            ]);
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }
}

if (!function_exists('ll_tools_uppercase_first_char_for_language')) {
    function ll_tools_uppercase_first_char_for_language(string $value, $language = ''): string {
        if ($value === '') {
            return '';
        }

        if (!function_exists('mb_substr') || !function_exists('mb_strtoupper')) {
            return ucfirst($value);
        }

        $first = mb_substr($value, 0, 1, 'UTF-8');
        $rest = mb_substr($value, 1, null, 'UTF-8');

        if (ll_tools_language_uses_turkish_casing($language)) {
            if ($first === 'i') {
                $first = 'İ';
            } elseif ($first === 'ı') {
                $first = 'I';
            } else {
                $first = mb_strtoupper($first, 'UTF-8');
            }
        } else {
            $first = mb_strtoupper($first, 'UTF-8');
        }

        return $first . $rest;
    }
}

if (!function_exists('ll_tools_turkish_lowercase')) {
    function ll_tools_turkish_lowercase(string $value): string {
        return ll_tools_lowercase_for_language($value, 'tr');
    }
}

if (!function_exists('ll_tools_turkish_sort_key')) {
    function ll_tools_turkish_sort_key(string $value): string {
        $clean = trim((string) wp_strip_all_tags($value));
        if ($clean === '') {
            return '';
        }
        $clean = ll_tools_turkish_lowercase($clean);
        $clean = preg_replace('/\s+/u', ' ', $clean);

        // Encode Turkish alphabet order in ASCII so fallback sorting stays deterministic:
        // c < ç, g < ğ, h < ı < i, o < ö, s < ş, u < ü.
        return strtr((string) $clean, [
            'ç' => 'c{',
            'ğ' => 'g{',
            'ı' => 'h{',
            'ö' => 'o{',
            'ş' => 's{',
            'ü' => 'u{',
        ]);
    }
}

if (!function_exists('ll_tools_contains_turkish_characters')) {
    function ll_tools_contains_turkish_characters(string $value): bool {
        if ($value === '') {
            return false;
        }
        return preg_match('/[çğıöşüÇĞİÖŞÜıİ]/u', $value) === 1;
    }
}

if (!function_exists('ll_tools_locale_compare_strings')) {
    function ll_tools_locale_compare_strings($left, $right, $locale = ''): int {
        $left_text = html_entity_decode((string) $left, ENT_QUOTES, 'UTF-8');
        $right_text = html_entity_decode((string) $right, ENT_QUOTES, 'UTF-8');
        if ($left_text === $right_text) {
            return 0;
        }

        $normalized_locale = ll_tools_normalize_sort_locale($locale ?: ll_tools_get_sort_locale());
        $use_turkish_order = ll_tools_is_turkish_locale($normalized_locale)
            || ll_tools_contains_turkish_characters($left_text)
            || ll_tools_contains_turkish_characters($right_text);

        if (class_exists('Collator')) {
            static $collators = [];
            $collator_key = $use_turkish_order ? 'tr-TR' : str_replace('_', '-', $normalized_locale);
            if (!array_key_exists($collator_key, $collators)) {
                $collators[$collator_key] = null;
                try {
                    $collator = new Collator($collator_key);
                    if ($collator instanceof Collator) {
                        $collator->setStrength(Collator::SECONDARY);
                        $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
                        $collators[$collator_key] = $collator;
                    }
                } catch (Throwable $e) {
                    $collators[$collator_key] = null;
                }
            }

            if ($collators[$collator_key] instanceof Collator) {
                $compared = $collators[$collator_key]->compare($left_text, $right_text);
                if (is_int($compared)) {
                    return $compared;
                }
                if (is_float($compared)) {
                    if ($compared > 0) { return 1; }
                    if ($compared < 0) { return -1; }
                    return 0;
                }
            }
        }

        if ($use_turkish_order) {
            $left_key = ll_tools_turkish_sort_key($left_text);
            $right_key = ll_tools_turkish_sort_key($right_text);

            $compared = strnatcmp($left_key, $right_key);
            if ($compared !== 0) {
                return $compared;
            }

            // Keep uppercase/lowercase and original punctuation stable on exact ties.
            $raw = strcmp($left_text, $right_text);
            if ($raw !== 0) {
                return $raw;
            }
        }

        return strnatcasecmp($left_text, $right_text);
    }
}

if (!function_exists('ll_tools_get_secondary_text_symbol_sort_maps')) {
    function ll_tools_get_secondary_text_symbol_sort_maps(string $mode = 'ipa'): array {
        static $maps = [];

        $mode = ($mode === 'ipa') ? 'ipa' : 'transcription';
        if (isset($maps[$mode])) {
            return $maps[$mode];
        }

        $modifier_map = [
            'ˈ' => '00stress',
            'ˌ' => '01stress',
            'ʰ' => '10h',
            'ʱ' => '11h',
            'ʲ' => '12y',
            'ʷ' => '13w',
            'ʳ' => '14r',
            'ʴ' => '15r',
            'ʵ' => '16e',
            'ˠ' => '17v',
            'ˤ' => '18p',
            '˞' => '19r',
            'ː' => '20long',
            'ˑ' => '21half',
            'ˀ' => '22glot',
            'ʼ' => '23ej',
            'ⁿ' => '24n',
            'ˡ' => '25l',
            '̃' => '30nasal',
            '̩' => '31syll',
            '̯' => '32off',
        ];

        if ($mode === 'ipa') {
            $base_map = function_exists('ll_tools_word_grid_get_ipa_match_map')
                ? ll_tools_word_grid_get_ipa_match_map()
                : [
                    'ɾ' => 'r',
                    'ɹ' => 'r',
                    'ɻ' => 'r',
                    'ʀ' => 'r',
                    'ʁ' => 'r',
                    'ɽ' => 'r',
                    'ʜ' => 'h',
                    'ɦ' => 'h',
                    'ʃ' => 'sh',
                    'ʒ' => 'zh',
                    'θ' => 'th',
                    'ð' => 'th',
                    'ŋ' => 'ng',
                    'ɲ' => 'ny',
                    'ɐ' => 'a',
                    'ɑ' => 'a',
                    'ɒ' => 'o',
                    'æ' => 'a',
                    'ɛ' => 'e',
                    'ɜ' => 'e',
                    'ə' => 'e',
                    'ɪ' => 'i',
                    'ʊ' => 'u',
                    'ʌ' => 'u',
                    'ɔ' => 'o',
                    'ɯ' => 'u',
                    'ɨ' => 'i',
                    'ʉ' => 'u',
                    'ø' => 'o',
                    'œ' => 'oe',
                    'ɶ' => 'oe',
                    'ɡ' => 'g',
                    'ɣ' => 'g',
                    'ʋ' => 'v',
                ];

            $base_map = array_merge($base_map, [
                'β' => 'v',
                'ɱ' => 'm',
                'ɳ' => 'n',
                'ɴ' => 'n',
                'ɫ' => 'l',
                'ɭ' => 'l',
                'ʎ' => 'l',
                'ʟ' => 'l',
                'ɬ' => 'l',
                'ɮ' => 'l',
                'ɕ' => 'sh',
                'ʑ' => 'zh',
                'ʂ' => 'sh',
                'ʐ' => 'zh',
                'ɟ' => 'j',
                'ʝ' => 'j',
                'ç' => 'h',
                'χ' => 'x',
                'ħ' => 'h',
                'ʔ' => 'q',
                'ʕ' => 'h',
            ]);
        } else {
            $base_map = function_exists('ll_tools_word_grid_get_transcription_match_map')
                ? ll_tools_word_grid_get_transcription_match_map()
                : [];
        }

        $maps[$mode] = [
            'base' => $base_map,
            'modifier' => $modifier_map,
        ];

        return $maps[$mode];
    }
}

if (!function_exists('ll_tools_get_secondary_text_symbol_sort_meta')) {
    function ll_tools_get_secondary_text_symbol_sort_meta(string $symbol, string $mode = 'ipa'): array {
        static $cache = [];

        $normalized_mode = ($mode === 'ipa') ? 'ipa' : 'transcription';
        $cache_key = $normalized_mode . "\0" . $symbol;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        $raw = trim((string) $symbol);
        if ($raw === '') {
            $cache[$cache_key] = [
                'family' => '',
                'modifier' => '',
                'modifier_rank' => 0,
                'plain_rank' => 1,
                'raw' => '',
            ];
            return $cache[$cache_key];
        }

        if (function_exists('ll_tools_word_grid_normalize_ipa_output')) {
            $normalized = ll_tools_word_grid_normalize_ipa_output($raw, $normalized_mode);
            if ($normalized !== '') {
                $raw = $normalized;
            }
        }

        $maps = ll_tools_get_secondary_text_symbol_sort_maps($normalized_mode);
        $base_map = (array) ($maps['base'] ?? []);
        $modifier_map = (array) ($maps['modifier'] ?? []);

        $chars = preg_split('//u', ll_tools_lowercase_for_language($raw), -1, PREG_SPLIT_NO_EMPTY);
        if (!$chars) {
            $chars = [$raw];
        }

        $family_key = '';
        $modifier_key = '';
        foreach ($chars as $char) {
            if ($char === '' || preg_match('/[\s\.]+/u', $char)) {
                continue;
            }

            if ($normalized_mode === 'ipa') {
                $is_tie_bar = function_exists('ll_tools_word_grid_is_ipa_tie_bar')
                    ? ll_tools_word_grid_is_ipa_tie_bar($char, 'ipa')
                    : preg_match('/[\x{035C}\x{0361}]/u', $char) === 1;
                if ($is_tie_bar) {
                    continue;
                }

                $is_combining_mark = function_exists('ll_tools_word_grid_is_ipa_combining_mark')
                    ? ll_tools_word_grid_is_ipa_combining_mark($char)
                    : preg_match('/[\x{0300}-\x{036F}]/u', $char) === 1;
                $is_modifier = function_exists('ll_tools_word_grid_is_ipa_post_modifier')
                    ? ll_tools_word_grid_is_ipa_post_modifier($char, 'ipa')
                    : array_key_exists($char, $modifier_map);
                $is_stress = function_exists('ll_tools_word_grid_is_ipa_stress_marker')
                    ? ll_tools_word_grid_is_ipa_stress_marker($char, 'ipa')
                    : in_array($char, ['ˈ', 'ˌ'], true);

                if ($is_combining_mark || $is_modifier || $is_stress) {
                    $modifier_key .= $modifier_map[$char] ?? ('zz' . bin2hex($char));
                    continue;
                }
            } elseif (preg_match('/[\x{0300}-\x{036F}]/u', $char) === 1) {
                $modifier_key .= $modifier_map[$char] ?? ('zz' . bin2hex($char));
                continue;
            }

            $mapped = (string) ($base_map[$char] ?? '');
            if ($mapped === '' && preg_match('/^[a-z0-9]$/', $char) === 1) {
                $mapped = $char;
            }

            if ($mapped === '') {
                if (function_exists('transliterator_transliterate')) {
                    $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $char);
                    if (is_string($converted) && $converted !== '') {
                        $mapped = $converted;
                    }
                } elseif (function_exists('iconv')) {
                    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $char);
                    if (is_string($converted) && $converted !== '') {
                        $mapped = $converted;
                    }
                }
            }

            $mapped = ll_tools_lowercase_for_language($mapped);
            $mapped = preg_replace('/[^a-z0-9]+/', '', $mapped);
            if ($mapped === '') {
                $mapped = 'zz' . bin2hex($char);
            }

            $family_key .= $mapped;
        }

        if ($family_key === '') {
            $family_key = '0';
        }

        $cache[$cache_key] = [
            'family' => $family_key,
            'modifier' => $modifier_key,
            'modifier_rank' => ($modifier_key === '') ? 0 : 1,
            'plain_rank' => (preg_match('/^[a-z]+$/', ll_tools_lowercase_for_language($raw)) === 1) ? 0 : 1,
            'raw' => $raw,
        ];

        return $cache[$cache_key];
    }
}

if (!function_exists('ll_tools_compare_secondary_text_symbols')) {
    function ll_tools_compare_secondary_text_symbols(string $left, string $right, string $mode = 'ipa'): int {
        $left_meta = ll_tools_get_secondary_text_symbol_sort_meta($left, $mode);
        $right_meta = ll_tools_get_secondary_text_symbol_sort_meta($right, $mode);

        $family_compare = strcmp((string) $left_meta['family'], (string) $right_meta['family']);
        if ($family_compare !== 0) {
            return $family_compare;
        }

        $modifier_rank_compare = ((int) $left_meta['modifier_rank'] <=> (int) $right_meta['modifier_rank']);
        if ($modifier_rank_compare !== 0) {
            return $modifier_rank_compare;
        }

        $modifier_compare = strcmp((string) $left_meta['modifier'], (string) $right_meta['modifier']);
        if ($modifier_compare !== 0) {
            return $modifier_compare;
        }

        $plain_rank_compare = ((int) $left_meta['plain_rank'] <=> (int) $right_meta['plain_rank']);
        if ($plain_rank_compare !== 0) {
            return $plain_rank_compare;
        }

        return ll_tools_locale_compare_strings((string) $left_meta['raw'], (string) $right_meta['raw']);
    }
}

if (!function_exists('ll_tools_sort_secondary_text_symbols')) {
    function ll_tools_sort_secondary_text_symbols(array $symbols, string $mode = 'ipa'): array {
        $sorted = [];
        foreach ($symbols as $symbol) {
            $value = trim((string) $symbol);
            if ($value === '') {
                continue;
            }
            $sorted[] = $value;
        }

        usort($sorted, static function (string $left, string $right) use ($mode): int {
            return ll_tools_compare_secondary_text_symbols($left, $right, $mode);
        });

        return $sorted;
    }
}
