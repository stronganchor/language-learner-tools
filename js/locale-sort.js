(function (root) {
    'use strict';

    if (!root || root.LLToolsLocaleSort) {
        return;
    }

    function pushUniqueLocale(locales, value) {
        const normalized = String(value || '').trim();
        if (!normalized || locales.indexOf(normalized) !== -1) {
            return;
        }
        locales.push(normalized);
    }

    function buildSortLocales(rawLocale) {
        const value = String(rawLocale || '').trim().replace(/_/g, '-');
        const locales = [];

        if (value) {
            pushUniqueLocale(locales, value);
            const primary = value.split('-')[0];
            if (primary) {
                pushUniqueLocale(locales, primary);
                if (primary.toLowerCase() === 'tr') {
                    pushUniqueLocale(locales, 'tr-TR');
                }
            }
        }

        pushUniqueLocale(locales, 'en-US');
        return locales;
    }

    function withTurkishSortLocales(baseLocales) {
        const combined = [];
        pushUniqueLocale(combined, 'tr-TR');
        pushUniqueLocale(combined, 'tr');
        (Array.isArray(baseLocales) ? baseLocales : []).forEach(function (value) {
            pushUniqueLocale(combined, value);
        });
        return combined;
    }

    function textHasTurkishCharacters(value) {
        return /[çğıöşüÇĞİÖŞÜıİ]/.test(String(value || ''));
    }

    function normalizeCompareOptions(options) {
        return Object.assign({
            numeric: true,
            sensitivity: 'base'
        }, (options && typeof options === 'object') ? options : {});
    }

    function compareWithLocales(left, right, sortLocales, turkishSortLocales, options) {
        const a = String(left || '');
        const b = String(right || '');
        if (a === b) {
            return 0;
        }

        const locales = (textHasTurkishCharacters(a) || textHasTurkishCharacters(b))
            ? turkishSortLocales
            : sortLocales;
        const compareOptions = normalizeCompareOptions(options);

        try {
            return a.localeCompare(b, locales, compareOptions);
        } catch (_) {
            try {
                return a.localeCompare(b, undefined, compareOptions);
            } catch (_) {
                if (a < b) {
                    return -1;
                }
                if (a > b) {
                    return 1;
                }
                return 0;
            }
        }
    }

    function compareText(left, right, rawLocale, options) {
        const sortLocales = buildSortLocales(rawLocale);
        return compareWithLocales(
            left,
            right,
            sortLocales,
            withTurkishSortLocales(sortLocales),
            options
        );
    }

    function createTextComparer(rawLocale, defaultOptions) {
        const sortLocales = buildSortLocales(rawLocale);
        const turkishSortLocales = withTurkishSortLocales(sortLocales);
        const baseOptions = normalizeCompareOptions(defaultOptions);

        return function (left, right, options) {
            return compareWithLocales(
                left,
                right,
                sortLocales,
                turkishSortLocales,
                Object.assign({}, baseOptions, (options && typeof options === 'object') ? options : {})
            );
        };
    }

    root.LLToolsLocaleSort = {
        buildSortLocales: buildSortLocales,
        withTurkishSortLocales: withTurkishSortLocales,
        textHasTurkishCharacters: textHasTurkishCharacters,
        compareText: compareText,
        createTextComparer: createTextComparer
    };
})(window);
