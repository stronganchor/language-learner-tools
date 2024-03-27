jQuery(document).ready(function($) {
    $("#word-set-language").autocomplete({
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
        event.preventDefault(); // Prevent form submission

        // Clear previous error messages
        $('.error-message').hide();

        var isValid = true;

        // Validate Word Set Name
        var wordSetName = $('#word-set-name').val().trim();
        if (wordSetName === '') {
            $('#word-set-name-error').text('Word Set Name is required.').show();
            isValid = false;
        }

        // Validate Language Selection
        var languageName = $('#word-set-language').val().trim();
        var languageId = $('#word-set-language-id').val().trim();
        var languageIsValid = manageWordSetData.availableLanguages.some(function(language) {
            return language.label === languageName;
        });

        if (languageName === '' || !languageIsValid || languageId === '') {
            $('#word-set-language-error').text('Please select a valid language.').show();
            isValid = false;
        }

        // Only proceed if the form is valid
        if (isValid) {
            var form = event.target;
            var formData = new FormData(form);
    
            // Append action and nonce to FormData
            formData.append('action', 'create_word_set');
            formData.append('security', manageWordSetData.nonce);

            fetch(manageWordSetData.ajaxurl, {
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
            } );
        } else {
            // Handle form invalid scenario
            console.error('Form validation failed');
        }

        var userWordSets = manageWordSetData.userWordSets;
        if (userWordSets) {
            $('#user-word-sets').append(userWordSets);
        }
    });
});