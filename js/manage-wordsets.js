/**
 * manage-wordsets.js
 *
 * Handles the autocomplete functionality for the Word Set language selection input.
 */
jQuery(document).ready(function($) {
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
        source: function(request, response) {
            var matcher = new RegExp($.ui.autocomplete.escapeRegex(request.term), "i");
            var sortedArray = manageWordSetData.availableLanguages.sort(function(a, b) {
                var startsWithA = a.label.toUpperCase().startsWith(request.term.toUpperCase());
                var startsWithB = b.label.toUpperCase().startsWith(request.term.toUpperCase());
                if (startsWithA && !startsWithB) {
                    return -1;
                } else if (!startsWithA && startsWithB) {
                    return 1;
                } else {
                    return a.label.localeCompare(b.label);
                }
            });
            response($.grep(sortedArray, function(item) {
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
        select: function(event, ui) {
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
        focus: function(event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        }
    });
});
