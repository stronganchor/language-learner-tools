<?php
/**
 * DeepL API Interface
 * 
 * This file contains functions for interacting with the DeepL API.
 * It includes functions for adding an admin page to enter the API key,
 * performing translation using the DeepL API, and handling API errors.
 */

// Add an admin page under Tools for entering the API key (https://www.deepl.com/pro-api)
    function ll_add_deepl_api_key_page() {
        add_management_page(
            'DeepL API Key',
            'DeepL API Key',
            'manage_options',
            'deepl-api-key',
            'll_deepl_api_key_page_content'
        );
    }
    add_action('admin_menu', 'll_add_deepl_api_key_page');
    
    // Add content to the DeepL API page
    function ll_deepl_api_key_page_content() {
        ?>
        <div class="wrap">
            <h1>Enter Your DeepL API Key</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ll-deepl-api-key-group');
                do_settings_sections('ll-deepl-api-key-group');
                ?>
                <table class="form-table">
                    <tr valign="top">
                    <th scope="row">DeepL API Key</th>
                    <td><input type="text" name="ll_deepl_api_key" value="<?php echo esc_attr(get_option('ll_deepl_api_key')); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    // Register the DeepL API key setting
    function ll_register_deepl_api_key_setting() {
        register_setting('ll-deepl-api-key-group', 'll_deepl_api_key');
    }
    add_action('admin_init', 'll_register_deepl_api_key_setting');
    
    // Perform translation with DeepL API
    function translate_with_deepl($text, $translate_to_lang = 'EN', $translate_from_lang = 'TR') {
        $api_key = get_option('ll_deepl_api_key'); // Retrieve the API key from WordPress options
        if (empty($api_key)) {
            return null;
        }
    
        $endpoint = 'https://api-free.deepl.com/v2/translate';
        $data = http_build_query([
            'auth_key' => $api_key,
            'text' => $text,
            'target_lang' => $translate_to_lang,
            'source_lang' => $translate_from_lang,
        ]);
    
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $data,
            ],
        ];
    
        $context = stream_context_create($options);
        $result = file_get_contents($endpoint, false, $context);
    
        if ($result === FALSE) {
            return null; // return null if translation failed
        }
    
        $json = json_decode($result, true);
        if (!is_array($json) || !isset($json['translations'][0]['text'])) {
            // Handle unexpected JSON structure or missing translation
            return null; // Return null to indicate an unexpected error occurred
        }
        return $json['translations'][0]['text'] ?? $text; // Return the translation or original text if something goes wrong
    }
    ?>