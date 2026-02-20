/**
 * manage-wordsets.js
 *
 * Handles the autocomplete functionality for the Word Set language selection input.
 */
jQuery(document).ready(function ($) {
    var sortLocale = String(document.documentElement.lang || '').trim().replace('_', '-');
    var sortLocales = [];
    if (sortLocale) {
        sortLocales.push(sortLocale);
        var primaryLocale = sortLocale.split('-')[0];
        if (primaryLocale && sortLocales.indexOf(primaryLocale) === -1) {
            sortLocales.push(primaryLocale);
        }
        if (primaryLocale && primaryLocale.toLowerCase() === 'tr' && sortLocales.indexOf('tr-TR') === -1) {
            sortLocales.push('tr-TR');
        }
    }
    sortLocales.push('en-US');
    var turkishSortLocales = (function (baseLocales) {
        var combined = [];
        var pushLocale = function (value) {
            var normalized = String(value || '').trim();
            if (!normalized || combined.indexOf(normalized) !== -1) { return; }
            combined.push(normalized);
        };
        pushLocale('tr-TR');
        pushLocale('tr');
        (Array.isArray(baseLocales) ? baseLocales : []).forEach(pushLocale);
        return combined;
    })(sortLocales);

    function textHasTurkishCharacters(value) {
        return /[çğıöşüÇĞİÖŞÜıİ]/.test(String(value || ''));
    }

    function localeTextCompare(left, right) {
        var a = String(left || '');
        var b = String(right || '');
        if (a === b) { return 0; }
        var opts = { numeric: true, sensitivity: 'base' };
        var locales = (textHasTurkishCharacters(a) || textHasTurkishCharacters(b))
            ? turkishSortLocales
            : sortLocales;
        try {
            return a.localeCompare(b, locales, opts);
        } catch (_) {
            try {
                return a.localeCompare(b, undefined, opts);
            } catch (_) {
                return a < b ? -1 : (a > b ? 1 : 0);
            }
        }
    }

    /**
     * Initializes the autocomplete feature on the #wordset-language input field.
     */
    $("#wordset-language").autocomplete({
        /**
         * Source callback for autocomplete suggestions.
         *
         * @param {Object} request - Contains the term entered by the user.
         * @param {Function} response - Callback to pass the matched suggestions.
         */
        source: function (request, response) {
            var matcher = new RegExp($.ui.autocomplete.escapeRegex(request.term), "i");
            var sortedArray = manageWordSetData.availableLanguages.sort(function (a, b) {
                var startsWithA = a.label.toUpperCase().startsWith(request.term.toUpperCase());
                var startsWithB = b.label.toUpperCase().startsWith(request.term.toUpperCase());
                if (startsWithA && !startsWithB) {
                    return -1;
                } else if (!startsWithA && startsWithB) {
                    return 1;
                } else {
                    return localeTextCompare(a.label, b.label);
                }
            });
            response($.grep(sortedArray, function (item) {
                return matcher.test(item.label);
            }));
        },
        minLength: 1,
        /**
         * Handler for selecting an autocomplete suggestion.
         *
         * @param {Event} event - The event object.
         * @param {Object} ui - Contains the selected item's information.
         * @returns {boolean} False to prevent the default behavior.
         */
        select: function (event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        },
        /**
         * Handler for focusing on an autocomplete suggestion.
         *
         * @param {Event} event - The event object.
         * @param {Object} ui - Contains the focused item's information.
         * @returns {boolean} False to prevent the default behavior.
         */
        focus: function (event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        }
    });
});
