jQuery(document).ready(function($) {
    $("#word-set-language").autocomplete({
        source: function(request, response) {
            var matcher = new RegExp($.ui.autocomplete.escapeRegex(request.term), "i");
            var sortedArray = availableLanguages.sort(function(a, b) {
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
            $("#word-set-language").val(ui.item.label);
            $("#word-set-language-id").val(ui.item.value);
            return false;
        },
        focus: function(event, ui) {
            $("#word-set-language").val(ui.item.label);
            return false;
        }
    });

    $('#create-word-set-form').on('submit', function(event) {
        event.preventDefault();

        var form = event.target;
        var formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Word set created successfully!');
                form.reset();
            } else {
                alert('Error creating word set. Please try again.');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error creating word set. Please try again.');
        });
    });
});