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
            '̥' => '33devoice',
            '̪' => '34dental',
            '̆' => '35short',
            '͡' => '36tie',
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

if (!function_exists('ll_tools_get_secondary_text_keyboard_modifier_symbols')) {
    function ll_tools_get_secondary_text_keyboard_modifier_symbols(string $mode = 'ipa'): array {
        return $mode === 'ipa' ? ['ʰ', 'ʲ', 'ʷ', 'ː', "\u{0325}", "\u{032A}", "\u{0306}", "\u{0361}"] : [];
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_compacting_modifier_symbols')) {
    function ll_tools_get_secondary_text_keyboard_compacting_modifier_symbols(string $mode = 'ipa'): array {
        return $mode === 'ipa' ? ['ʰ', 'ʲ', 'ʷ', 'ː', "\u{0325}", "\u{032A}", "\u{0306}"] : [];
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_laryngeal_symbols')) {
    function ll_tools_get_secondary_text_keyboard_laryngeal_symbols(string $mode = 'ipa'): array {
        return $mode === 'ipa' ? ['ʔ', 'ʕ', 'ʡ'] : [];
    }
}

if (!function_exists('ll_tools_secondary_text_keyboard_symbol_uses_compact_modifier')) {
    function ll_tools_secondary_text_keyboard_symbol_uses_compact_modifier(string $symbol, string $mode = 'ipa'): bool {
        if ($mode !== 'ipa') {
            return false;
        }

        $symbol = trim($symbol);
        if ($symbol === '') {
            return false;
        }

        foreach (ll_tools_get_secondary_text_keyboard_compacting_modifier_symbols($mode) as $modifier) {
            if ($symbol !== $modifier && strpos($symbol, $modifier) !== false) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('ll_tools_compact_secondary_text_keyboard_symbols')) {
    function ll_tools_compact_secondary_text_keyboard_symbols(array $symbols, string $mode = 'ipa', bool $sort = true): array {
        $compact = [];
        foreach ($symbols as $symbol) {
            $value = trim((string) $symbol);
            if ($value === '' || in_array($value, $compact, true)) {
                continue;
            }
            if (ll_tools_secondary_text_keyboard_symbol_uses_compact_modifier($value, $mode)) {
                continue;
            }
            $compact[] = $value;
        }

        return $sort ? ll_tools_sort_secondary_text_symbols($compact, $mode) : $compact;
    }
}

if (!function_exists('ll_tools_secondary_text_keyboard_symbol_contains_tie_bar')) {
    function ll_tools_secondary_text_keyboard_symbol_contains_tie_bar(string $symbol, string $mode = 'ipa'): bool {
        return $mode === 'ipa' && preg_match('/[\x{035C}\x{0361}]/u', $symbol) === 1;
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_vowel_descriptions')) {
    function ll_tools_get_secondary_text_keyboard_vowel_descriptions(): array {
        return [
            'i' => __('close front unrounded vowel', 'll-tools-text-domain'),
            'y' => __('close front rounded vowel', 'll-tools-text-domain'),
            'ɨ' => __('close central unrounded vowel', 'll-tools-text-domain'),
            'ʉ' => __('close central rounded vowel', 'll-tools-text-domain'),
            'ɯ' => __('close back unrounded vowel', 'll-tools-text-domain'),
            'u' => __('close back rounded vowel', 'll-tools-text-domain'),
            'ɪ' => __('near-close near-front unrounded vowel', 'll-tools-text-domain'),
            'ʏ' => __('near-close near-front rounded vowel', 'll-tools-text-domain'),
            'ʊ' => __('near-close near-back rounded vowel', 'll-tools-text-domain'),
            'e' => __('close-mid front unrounded vowel', 'll-tools-text-domain'),
            'ø' => __('close-mid front rounded vowel', 'll-tools-text-domain'),
            'ɘ' => __('close-mid central unrounded vowel', 'll-tools-text-domain'),
            'ɵ' => __('close-mid central rounded vowel', 'll-tools-text-domain'),
            'ɤ' => __('close-mid back unrounded vowel', 'll-tools-text-domain'),
            'o' => __('close-mid back rounded vowel', 'll-tools-text-domain'),
            'ə' => __('mid central vowel', 'll-tools-text-domain'),
            'ɛ' => __('open-mid front unrounded vowel', 'll-tools-text-domain'),
            'œ' => __('open-mid front rounded vowel', 'll-tools-text-domain'),
            'ɜ' => __('open-mid central unrounded vowel', 'll-tools-text-domain'),
            'ɞ' => __('open-mid central rounded vowel', 'll-tools-text-domain'),
            'ʌ' => __('open-mid back unrounded vowel', 'll-tools-text-domain'),
            'ɔ' => __('open-mid back rounded vowel', 'll-tools-text-domain'),
            'æ' => __('near-open front unrounded vowel', 'll-tools-text-domain'),
            'ɐ' => __('near-open central vowel', 'll-tools-text-domain'),
            'a' => __('open front unrounded vowel', 'll-tools-text-domain'),
            'ɶ' => __('open front rounded vowel', 'll-tools-text-domain'),
            'ɑ' => __('open back unrounded vowel', 'll-tools-text-domain'),
            'ɒ' => __('open back rounded vowel', 'll-tools-text-domain'),
            'ɚ' => __('rhotacized mid central vowel', 'll-tools-text-domain'),
            'ɝ' => __('rhotacized open-mid central vowel', 'll-tools-text-domain'),
        ];
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_consonant_descriptions')) {
    function ll_tools_get_secondary_text_keyboard_consonant_descriptions(): array {
        return [
            'p' => __('voiceless bilabial stop', 'll-tools-text-domain'),
            'b' => __('voiced bilabial stop', 'll-tools-text-domain'),
            't' => __('voiceless alveolar stop', 'll-tools-text-domain'),
            'd' => __('voiced alveolar stop', 'll-tools-text-domain'),
            'ʈ' => __('voiceless retroflex stop', 'll-tools-text-domain'),
            'ɖ' => __('voiced retroflex stop', 'll-tools-text-domain'),
            'c' => __('voiceless palatal stop', 'll-tools-text-domain'),
            'ɟ' => __('voiced palatal stop', 'll-tools-text-domain'),
            'k' => __('voiceless velar stop', 'll-tools-text-domain'),
            'g' => __('voiced velar stop', 'll-tools-text-domain'),
            'ɡ' => __('voiced velar stop', 'll-tools-text-domain'),
            'q' => __('voiceless uvular stop', 'll-tools-text-domain'),
            'ɢ' => __('voiced uvular stop', 'll-tools-text-domain'),
            'ʔ' => __('glottal stop', 'll-tools-text-domain'),
            'ʡ' => __('epiglottal stop', 'll-tools-text-domain'),
            'm' => __('voiced bilabial nasal', 'll-tools-text-domain'),
            'ɱ' => __('voiced labiodental nasal', 'll-tools-text-domain'),
            'n' => __('voiced alveolar nasal', 'll-tools-text-domain'),
            'ɳ' => __('voiced retroflex nasal', 'll-tools-text-domain'),
            'ɲ' => __('voiced palatal nasal', 'll-tools-text-domain'),
            'ŋ' => __('voiced velar nasal', 'll-tools-text-domain'),
            'ɴ' => __('voiced uvular nasal', 'll-tools-text-domain'),
            'ʙ' => __('voiced bilabial trill', 'll-tools-text-domain'),
            'r' => __('voiced alveolar trill', 'll-tools-text-domain'),
            'ʀ' => __('voiced uvular trill', 'll-tools-text-domain'),
            'ⱱ' => __('voiced labiodental flap', 'll-tools-text-domain'),
            'ɾ' => __('voiced alveolar tap', 'll-tools-text-domain'),
            'ɽ' => __('voiced retroflex flap', 'll-tools-text-domain'),
            'ɸ' => __('voiceless bilabial fricative', 'll-tools-text-domain'),
            'β' => __('voiced bilabial fricative', 'll-tools-text-domain'),
            'f' => __('voiceless labiodental fricative', 'll-tools-text-domain'),
            'v' => __('voiced labiodental fricative', 'll-tools-text-domain'),
            'θ' => __('voiceless dental fricative', 'll-tools-text-domain'),
            'ð' => __('voiced dental fricative', 'll-tools-text-domain'),
            's' => __('voiceless alveolar fricative', 'll-tools-text-domain'),
            'z' => __('voiced alveolar fricative', 'll-tools-text-domain'),
            'ʃ' => __('voiceless postalveolar fricative', 'll-tools-text-domain'),
            'ʒ' => __('voiced postalveolar fricative', 'll-tools-text-domain'),
            'ʂ' => __('voiceless retroflex fricative', 'll-tools-text-domain'),
            'ʐ' => __('voiced retroflex fricative', 'll-tools-text-domain'),
            'ɕ' => __('voiceless alveolo-palatal fricative', 'll-tools-text-domain'),
            'ʑ' => __('voiced alveolo-palatal fricative', 'll-tools-text-domain'),
            'ç' => __('voiceless palatal fricative', 'll-tools-text-domain'),
            'ʝ' => __('voiced palatal fricative', 'll-tools-text-domain'),
            'x' => __('voiceless velar fricative', 'll-tools-text-domain'),
            'ɣ' => __('voiced velar fricative', 'll-tools-text-domain'),
            'χ' => __('voiceless uvular fricative', 'll-tools-text-domain'),
            'ʁ' => __('voiced uvular fricative', 'll-tools-text-domain'),
            'ħ' => __('voiceless pharyngeal fricative', 'll-tools-text-domain'),
            'ʕ' => __('voiced pharyngeal fricative', 'll-tools-text-domain'),
            'h' => __('voiceless glottal fricative', 'll-tools-text-domain'),
            'ɦ' => __('voiced glottal fricative', 'll-tools-text-domain'),
            'ɬ' => __('voiceless alveolar lateral fricative', 'll-tools-text-domain'),
            'ɮ' => __('voiced alveolar lateral fricative', 'll-tools-text-domain'),
            'ʋ' => __('voiced labiodental approximant', 'll-tools-text-domain'),
            'ɹ' => __('voiced alveolar approximant', 'll-tools-text-domain'),
            'ɻ' => __('voiced retroflex approximant', 'll-tools-text-domain'),
            'j' => __('voiced palatal approximant', 'll-tools-text-domain'),
            'ɰ' => __('voiced velar approximant', 'll-tools-text-domain'),
            'l' => __('voiced alveolar lateral approximant', 'll-tools-text-domain'),
            'ɭ' => __('voiced retroflex lateral approximant', 'll-tools-text-domain'),
            'ʎ' => __('voiced palatal lateral approximant', 'll-tools-text-domain'),
            'ʟ' => __('voiced velar lateral approximant', 'll-tools-text-domain'),
            'ɫ' => __('velarized alveolar lateral approximant', 'll-tools-text-domain'),
            'w' => __('voiced labial-velar approximant', 'll-tools-text-domain'),
            'ɥ' => __('voiced labial-palatal approximant', 'll-tools-text-domain'),
            'ʍ' => __('voiceless labial-velar fricative', 'll-tools-text-domain'),
            'ʜ' => __('voiceless epiglottal fricative', 'll-tools-text-domain'),
            'ʢ' => __('voiced epiglottal fricative', 'll-tools-text-domain'),
            'ɧ' => __('voiceless palatal-velar fricative', 'll-tools-text-domain'),
        ];
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_affricate_descriptions')) {
    function ll_tools_get_secondary_text_keyboard_affricate_descriptions(): array {
        return [
            "t\u{0361}s" => __('voiceless alveolar affricate', 'll-tools-text-domain'),
            "d\u{0361}z" => __('voiced alveolar affricate', 'll-tools-text-domain'),
            "t\u{0361}ʃ" => __('voiceless postalveolar affricate', 'll-tools-text-domain'),
            "d\u{0361}ʒ" => __('voiced postalveolar affricate', 'll-tools-text-domain'),
            "t\u{0361}ɕ" => __('voiceless alveolo-palatal affricate', 'll-tools-text-domain'),
            "d\u{0361}ʑ" => __('voiced alveolo-palatal affricate', 'll-tools-text-domain'),
            "ʈ\u{0361}ʂ" => __('voiceless retroflex affricate', 'll-tools-text-domain'),
            "ɖ\u{0361}ʐ" => __('voiced retroflex affricate', 'll-tools-text-domain'),
            "p\u{0361}f" => __('voiceless labiodental affricate', 'll-tools-text-domain'),
            "k\u{0361}x" => __('voiceless velar affricate', 'll-tools-text-domain'),
            "q\u{0361}χ" => __('voiceless uvular affricate', 'll-tools-text-domain'),
            "c\u{0361}ç" => __('voiceless palatal affricate', 'll-tools-text-domain'),
            "ɟ\u{0361}ʝ" => __('voiced palatal affricate', 'll-tools-text-domain'),
            "t\u{032A}\u{0361}ʙ\u{0325}" => __('voiceless dental stop with voiceless bilabial trill release', 'll-tools-text-domain'),
            "t\u{032A}\u{0361}\u{10784}" => __('voiceless dental stop with bilabial trill release', 'll-tools-text-domain'),
            "d\u{032A}\u{0361}ʙ" => __('voiced dental stop with bilabial trill release', 'll-tools-text-domain'),
            "t\u{0361}ʙ\u{0325}" => __('voiceless alveolar stop with voiceless bilabial trill release', 'll-tools-text-domain'),
        ];
    }
}

if (!function_exists('ll_tools_secondary_text_keyboard_symbol_is_vowel')) {
    function ll_tools_secondary_text_keyboard_symbol_is_vowel(string $symbol, string $mode = 'ipa'): bool {
        return $mode === 'ipa' && array_key_exists($symbol, ll_tools_get_secondary_text_keyboard_vowel_descriptions());
    }
}

if (!function_exists('ll_tools_secondary_text_keyboard_symbol_is_consonant')) {
    function ll_tools_secondary_text_keyboard_symbol_is_consonant(string $symbol, string $mode = 'ipa'): bool {
        return $mode === 'ipa' && array_key_exists($symbol, ll_tools_get_secondary_text_keyboard_consonant_descriptions());
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_symbol_display')) {
    function ll_tools_get_secondary_text_keyboard_symbol_display(string $symbol, string $mode = 'ipa'): string {
        if ($mode !== 'ipa') {
            return $symbol;
        }

        $display = [
            "\u{0325}" => "\u{25CC}\u{0325}",
            "\u{032A}" => "\u{25CC}\u{032A}",
            "\u{0306}" => "\u{25CC}\u{0306}",
            "\u{0361}" => "\u{25CC}\u{0361}\u{25CC}",
        ];

        return (string) ($display[$symbol] ?? $symbol);
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_symbol_description')) {
    function ll_tools_get_secondary_text_keyboard_symbol_description(string $symbol, string $mode = 'ipa'): string {
        if ($symbol === '') {
            return '';
        }
        if ($mode !== 'ipa') {
            return __('transcription symbol', 'll-tools-text-domain');
        }

        $modifiers = [
            'ʰ' => __('aspiration modifier', 'll-tools-text-domain'),
            'ʲ' => __('palatalization modifier', 'll-tools-text-domain'),
            'ʷ' => __('labialization modifier', 'll-tools-text-domain'),
            'ː' => __('long sound marker', 'll-tools-text-domain'),
            "\u{0325}" => __('devoicing diacritic', 'll-tools-text-domain'),
            "\u{032A}" => __('dental diacritic', 'll-tools-text-domain'),
            "\u{0306}" => __('extra-short diacritic', 'll-tools-text-domain'),
            "\u{0361}" => __('tie bar', 'll-tools-text-domain'),
            'ˈ' => __('primary stress marker', 'll-tools-text-domain'),
            'ˌ' => __('secondary stress marker', 'll-tools-text-domain'),
        ];
        if (isset($modifiers[$symbol])) {
            return $modifiers[$symbol];
        }

        $affricates = ll_tools_get_secondary_text_keyboard_affricate_descriptions();
        if (isset($affricates[$symbol])) {
            return (string) $affricates[$symbol];
        }

        $vowels = ll_tools_get_secondary_text_keyboard_vowel_descriptions();
        if (isset($vowels[$symbol])) {
            return (string) $vowels[$symbol];
        }

        $consonants = ll_tools_get_secondary_text_keyboard_consonant_descriptions();
        if (isset($consonants[$symbol])) {
            return (string) $consonants[$symbol];
        }

        if (ll_tools_secondary_text_keyboard_symbol_contains_tie_bar($symbol, $mode)) {
            return __('tie-bar IPA sequence', 'll-tools-text-domain');
        }

        return __('IPA symbol', 'll-tools-text-domain');
    }
}

if (!function_exists('ll_tools_get_secondary_text_keyboard_symbol_details')) {
    function ll_tools_get_secondary_text_keyboard_symbol_details(array $symbols, string $mode = 'ipa'): array {
        $details = [];
        foreach ($symbols as $symbol) {
            $symbol = trim((string) $symbol);
            if ($symbol === '' || isset($details[$symbol])) {
                continue;
            }
            $details[$symbol] = [
                'display' => ll_tools_get_secondary_text_keyboard_symbol_display($symbol, $mode),
                'label' => ll_tools_get_secondary_text_keyboard_symbol_description($symbol, $mode),
            ];
        }
        return $details;
    }
}

if (!function_exists('ll_tools_secondary_text_illegal_symbols_meta_key')) {
    function ll_tools_secondary_text_illegal_symbols_meta_key(): string {
        return 'll_wordset_illegal_ipa_symbols';
    }
}

if (!function_exists('ll_tools_sanitize_secondary_text_keyboard_symbol')) {
    function ll_tools_sanitize_secondary_text_keyboard_symbol(string $symbol, string $mode = 'ipa'): string {
        $symbol = trim($symbol);
        if ($symbol === '') {
            return '';
        }

        if (function_exists('ll_tools_word_grid_normalize_ipa_output')) {
            $symbol = ll_tools_word_grid_normalize_ipa_output($symbol, $mode);
        }
        if (function_exists('ll_tools_word_grid_strip_ipa_stress_markers')) {
            $symbol = ll_tools_word_grid_strip_ipa_stress_markers($symbol, $mode);
        }
        $symbol = trim((string) $symbol);
        if ($symbol === '') {
            return '';
        }

        if ($mode === 'ipa' && preg_match('/^[\x{0300}-\x{036F}]$/u', $symbol) === 1) {
            return $symbol;
        }

        if (function_exists('ll_tools_word_grid_tokenize_ipa')) {
            $tokens = ll_tools_word_grid_tokenize_ipa($symbol, $mode);
            if (!empty($tokens)) {
                return trim((string) $tokens[0]);
            }
        }

        return preg_match('/\s/u', $symbol) ? '' : $symbol;
    }
}

if (!function_exists('ll_tools_sanitize_secondary_text_illegal_symbols')) {
    function ll_tools_sanitize_secondary_text_illegal_symbols($raw, string $mode = 'ipa'): array {
        $values = is_string($raw) ? preg_split('/[\s,;|]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) : (array) $raw;
        $symbols = [];

        foreach ($values as $value) {
            $symbol = ll_tools_sanitize_secondary_text_keyboard_symbol((string) $value, $mode);
            if ($symbol === '' || in_array($symbol, $symbols, true)) {
                continue;
            }
            $symbols[] = $symbol;
        }

        usort($symbols, 'll_tools_locale_compare_strings');
        return $symbols;
    }
}

if (!function_exists('ll_tools_get_wordset_secondary_text_illegal_symbols')) {
    function ll_tools_get_wordset_secondary_text_illegal_symbols(int $wordset_id, string $mode = 'ipa'): array {
        if ($wordset_id <= 0) {
            return [];
        }

        $raw = get_term_meta($wordset_id, ll_tools_secondary_text_illegal_symbols_meta_key(), true);
        $symbols = ll_tools_sanitize_secondary_text_illegal_symbols($raw, $mode);
        if ($symbols !== $raw) {
            if (empty($symbols)) {
                delete_term_meta($wordset_id, ll_tools_secondary_text_illegal_symbols_meta_key());
            } else {
                update_term_meta($wordset_id, ll_tools_secondary_text_illegal_symbols_meta_key(), $symbols);
            }
        }

        return $symbols;
    }
}

if (!function_exists('ll_tools_update_wordset_secondary_text_illegal_symbols')) {
    function ll_tools_update_wordset_secondary_text_illegal_symbols(int $wordset_id, array $symbols, string $mode = 'ipa'): array {
        if ($wordset_id <= 0) {
            return [];
        }

        $symbols = ll_tools_sanitize_secondary_text_illegal_symbols($symbols, $mode);
        if (empty($symbols)) {
            delete_term_meta($wordset_id, ll_tools_secondary_text_illegal_symbols_meta_key());
        } else {
            update_term_meta($wordset_id, ll_tools_secondary_text_illegal_symbols_meta_key(), $symbols);
        }

        return $symbols;
    }
}

if (!function_exists('ll_tools_add_wordset_secondary_text_illegal_symbol')) {
    function ll_tools_add_wordset_secondary_text_illegal_symbol(int $wordset_id, string $symbol, string $mode = 'ipa'): array {
        $symbols = ll_tools_get_wordset_secondary_text_illegal_symbols($wordset_id, $mode);
        $symbol = ll_tools_sanitize_secondary_text_keyboard_symbol($symbol, $mode);
        if ($symbol !== '' && !in_array($symbol, $symbols, true)) {
            $symbols[] = $symbol;
        }

        return ll_tools_update_wordset_secondary_text_illegal_symbols($wordset_id, $symbols, $mode);
    }
}

if (!function_exists('ll_tools_secondary_text_token_has_illegal_symbol')) {
    function ll_tools_secondary_text_token_has_illegal_symbol(string $token, array $illegal_symbols): string {
        if ($token === '' || empty($illegal_symbols)) {
            return '';
        }

        $illegal_symbols = array_values(array_filter(array_map('strval', $illegal_symbols), static function (string $symbol): bool {
            return $symbol !== '';
        }));
        usort($illegal_symbols, static function (string $left, string $right): int {
            return strlen($right) <=> strlen($left);
        });

        foreach ($illegal_symbols as $symbol) {
            if (strpos($token, $symbol) !== false) {
                return $symbol;
            }
        }

        return '';
    }
}

if (!function_exists('ll_tools_build_secondary_text_keyboard_groups')) {
    function ll_tools_build_secondary_text_keyboard_groups(array $symbols, string $mode = 'ipa', array $recording_counts = [], array $options = []): array {
        if ($mode !== 'ipa') {
            return [
                [
                    'key' => 'symbols',
                    'label' => __('Symbols', 'll-tools-text-domain'),
                    'symbols' => ll_tools_sort_secondary_text_symbols($symbols, $mode),
                ],
            ];
        }

        $rare_threshold = max(2, (int) ($options['rare_threshold'] ?? 2));
        $illegal_symbols = array_values(array_map('strval', (array) ($options['illegal_symbols'] ?? [])));
        $normalized_symbols = [];
        foreach ($symbols as $symbol) {
            $symbol = trim((string) $symbol);
            if ($symbol === '' || in_array($symbol, $normalized_symbols, true)) {
                continue;
            }
            if (ll_tools_secondary_text_token_has_illegal_symbol($symbol, $illegal_symbols) !== '') {
                continue;
            }
            $normalized_symbols[] = $symbol;
        }

        $symbol_set = array_fill_keys($normalized_symbols, true);
        $groups = [];
        $signs = ll_tools_get_secondary_text_keyboard_modifier_symbols($mode);
        foreach (ll_tools_get_secondary_text_keyboard_laryngeal_symbols($mode) as $symbol) {
            if (isset($symbol_set[$symbol]) && !in_array($symbol, $signs, true)) {
                $signs[] = $symbol;
            }
        }

        if (!empty($signs)) {
            $groups[] = [
                'key' => 'signs',
                'label' => __('Diacritics and signs', 'll-tools-text-domain'),
                'symbols' => array_values($signs),
            ];
        }

        $affricates = [];
        $vowels = [];
        $consonants = [];
        $rare = [];
        $other = [];
        $top_symbols = array_fill_keys($signs, true);

        foreach ($normalized_symbols as $symbol) {
            if (isset($top_symbols[$symbol])) {
                continue;
            }
            $recording_count = array_key_exists($symbol, $recording_counts)
                ? max(0, (int) $recording_counts[$symbol])
                : $rare_threshold;

            if (ll_tools_secondary_text_keyboard_symbol_contains_tie_bar($symbol, $mode)) {
                $affricates[] = $symbol;
                continue;
            }

            if (ll_tools_secondary_text_keyboard_symbol_uses_compact_modifier($symbol, $mode)) {
                continue;
            }

            if ($recording_count > 0 && $recording_count < $rare_threshold) {
                $rare[] = $symbol;
                continue;
            }

            if (ll_tools_secondary_text_keyboard_symbol_is_vowel($symbol, $mode)) {
                $vowels[] = $symbol;
                continue;
            }

            if (ll_tools_secondary_text_keyboard_symbol_is_consonant($symbol, $mode)) {
                $consonants[] = $symbol;
                continue;
            }

            $other[] = $symbol;
        }

        $append_group = static function (array &$groups, string $key, string $label, array $values) use ($mode): void {
            $values = ll_tools_sort_secondary_text_symbols($values, $mode);
            if (empty($values)) {
                return;
            }
            $groups[] = [
                'key' => $key,
                'label' => $label,
                'symbols' => array_values($values),
            ];
        };

        $append_group($groups, 'affricates', __('Affricates and tie bars', 'll-tools-text-domain'), $affricates);
        $append_group($groups, 'vowels', __('Vowels', 'll-tools-text-domain'), $vowels);
        $append_group($groups, 'consonants', __('Consonants', 'll-tools-text-domain'), $consonants);
        $append_group($groups, 'rare', __('Rare symbols', 'll-tools-text-domain'), $rare);
        $append_group($groups, 'other', __('Other symbols', 'll-tools-text-domain'), $other);

        return $groups;
    }
}

if (!function_exists('ll_tools_flatten_secondary_text_keyboard_groups')) {
    function ll_tools_flatten_secondary_text_keyboard_groups(array $groups): array {
        $symbols = [];
        foreach ($groups as $group) {
            foreach ((array) ($group['symbols'] ?? []) as $symbol) {
                $symbol = trim((string) $symbol);
                if ($symbol === '' || in_array($symbol, $symbols, true)) {
                    continue;
                }
                $symbols[] = $symbol;
            }
        }
        return $symbols;
    }
}
