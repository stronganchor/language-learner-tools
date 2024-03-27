<?php
function ll_manage_word_sets_page_template() {
    $page_slug = 'manage-word-sets';
    $existing_page = get_page_by_path($page_slug, OBJECT, 'page');
	
    // Correct the path for the file whose version we're checking.
    $latest_page_version = filemtime(__FILE__); 

    if (!$existing_page) {
        // Page doesn't exist, so create it.
        $page_id = wp_insert_post(
            array(
                'post_title'    => 'Manage Word Sets',
                'post_name'     => $page_slug,
                'post_content'  => ll_manage_word_sets_page_content(), 
                'post_status'   => 'publish',
                'post_type'     => 'page',
                'post_author'   => get_current_user_id(),
                'ping_status'   => 'closed'
            )
        );
        // Set the initial version of the page.
        if ($page_id != 0) {
            update_post_meta($page_id, '_page_version', $latest_page_version);
            flush_rewrite_rules();
        }
    } else {
        // Page exists, check if the version needs updating.
        $existing_page_version = get_post_meta($existing_page->ID, '_page_version', true);
        if (intval($existing_page_version) < $latest_page_version) {
            // Update the content and the version meta.
            wp_update_post(
                array(
                    'ID'           => $existing_page->ID,
                    'post_content' => ll_manage_word_sets_page_content(),
                )
            );
            update_post_meta($existing_page->ID, '_page_version', $latest_page_version);
        }
    }
}
add_action('init', 'll_manage_word_sets_page_template');

// Function to return the content for the Manage Word Sets page
function ll_manage_word_sets_page_content() {
    ob_start();
    ?>
    <h2>Create a New Word Set</h2>
    <form id="create-word-set-form" method="post">
        <div>
            <label for="word-set-name">Word Set Name:</label>
            <input type="text" id="word-set-name" name="word_set_name" required>
        </div>
        <div>
            <label for="word-set-language">Language:</label>
            <input type="text" id="word-set-language" name="word_set_language" required>
            <input type="hidden" id="word-set-language-id" name="word_set_language_id">
        </div>
        <div>
            <button type="submit">Create Word Set</button>
        </div>
    </form>

    <script>
    // <![CDATA[
    jQuery(document).ready(function($) {
        var availableLanguages = [
            <?php
            $languages = get_terms([
                'taxonomy' => 'language',
                'hide_empty' => false,
            ]);
            $language_data = [];
            foreach ($languages as $language) {
                $language_data[] = '{label: "' . esc_js($language->name) . '", value: "' . esc_js($language->term_id) . '"}';
            }
            echo implode(',', $language_data);
            ?>
        ];

        $("#word-set-language").autocomplete({
            source: availableLanguages,
            minLength: 1,
            select: function(event, ui) {
                $("#word-set-language").val(ui.item.label);
                $("#word-set-language-id").val(ui.item.value);
                return false;
            },
            focus: function(event, ui) {
                $("#word-set-language").val(ui.item.label);
                return false;
            },
            change: function(event, ui) {
                if (!ui.item) {
                    $("#word-set-language").val("");
                    $("#word-set-language-id").val("");
                }
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
    // ]]>
    </script>
    <?php
    $content = ob_get_clean();
    return wpautop($content, false);
}
