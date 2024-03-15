(function($) {
    $(document).ready(function() {
        // Populate the 'Quick Edit' fields with the current values
        $('#the-list').on('click', '.editinline', function() {
            var post_id = $(this).closest('tr').attr('id').replace('post-', '');
            var $row = $('#inline-edit');

            // Populate categories
            var categories = $row.find('select[name="word_categories[]"]');
            categories.val(null); // Clear previous selections
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_get_word_categories',
                    post_id: post_id
                },
                success: function(response) {
                    if (response.success) {
                        categories.val(response.data);
                    }
                }
            });
            
            // Populate audio file
            var audio_file = $row.find('input[name="word_audio_file"]');
            audio_file.val(''); // Clear the previous value
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_get_word_audio_file',
                    post_id: post_id
                },
                success: function(response) {
                    if (response.success) {
                        audio_file.val(response.data);
                    }
                }
            });

            // Populate translation
            var translation = $row.find('input[name="word_english_meaning"]');
            translation.val(''); // Clear the previous value
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_get_word_translation',
                    post_id: post_id
                },
                success: function(response) {
                    if (response.success) {
                        translation.val(response.data);
                    }
                }
            });
        });
    });
})(jQuery);