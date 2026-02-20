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

if (!function_exists('ll_tools_turkish_lowercase')) {
    function ll_tools_turkish_lowercase(string $value): string {
        $value = strtr($value, [
            'I' => 'ı',
            'İ' => 'i',
        ]);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
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
