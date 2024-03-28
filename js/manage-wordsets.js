jQuery(document).ready(function($) {
    $("#wordset-language").autocomplete({
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
        select: function(event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        },
        focus: function(event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        }
    });
    
});