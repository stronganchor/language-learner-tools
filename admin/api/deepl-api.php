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
        'view_ll_tools',
        'deepl-api-key',
        'll_deepl_api_key_page_content'
    );
}
add_action('admin_menu', 'll_add_deepl_api_key_page');

/**
 * Renders the DeepL API Key admin page content.
 */
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

/**
 * Registers the DeepL API key setting.
 */
function ll_register_deepl_api_key_setting() {
    register_setting('ll-deepl-api-key-group', 'll_deepl_api_key');
}
add_action('admin_init', 'll_register_deepl_api_key_setting');

/**
 * Translates text using the DeepL API.
 *
 * @param string $text The text to translate.
 * @param string $translate_to_lang The target language code (e.g., 'EN').
 * @param string $translate_from_lang The source language code (e.g., 'TR').
 * @return string|null The translated text or null on failure.
 */
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

/**
 * Retrieves the available language names from the DeepL API.
 *
 * @param bool $no_parentheses Whether to remove parentheses from language names.
 * @param string $type The type of languages to retrieve ('source' or 'target').
 * @return array|null An array of language names or null on failure.
 */
function get_deepl_language_names($no_parentheses = false, $type = 'target') {
    $json = get_deepl_language_json($type);

    if ($json === null) {
        return null;
    }

    // Remove parentheses and duplicate entries if no_parentheses is true
    if ($no_parentheses) {
        $json = array_map(function($lang) {
            return preg_replace('/\s*\(.*\)/', '', $lang['name']);
        }, $json);
        $json = array_unique($json);
    }

    // Map the response to just the names of the languages
    return array_map(function($lang) {
        return $lang['name'];
    }, $json);
}

/**
 * Retrieves an associative array of language codes and their names from the DeepL API.
 *
 * @return array|null An associative array with language codes as keys and names as values, or null on failure.
 */
function get_deepl_language_codes() {
    $json = get_deepl_language_json();
    if ($json === null) {
        return null;
    }

    return array_column($json, 'name', 'language');
}

/**
 * Retrieves the full language JSON from the DeepL API and caches it.
 *
 * @param string $type The type of languages to retrieve ('source' or 'target').
 * @return array|null The decoded JSON response or null on failure.
 */
function get_deepl_language_json($type = 'target') {
    $transient_key = 'deepl_language_json_' . $type;
    $cached_json = get_transient($transient_key);

    if ($cached_json !== false) {
        return $cached_json;
    }
    
    $api_key = get_option('ll_deepl_api_key');
    if (empty($api_key)) {
        return null;
    }

    $endpoint = 'https://api-free.deepl.com/v2/languages';
    $url = $endpoint . '?type=' . $type; // Add the type parameter to the query string

    $options = [
        'http' => [
            'header' => "Authorization: DeepL-Auth-Key $api_key\r\n",
            'method' => 'GET',
        ],
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);

    if ($result === FALSE) {
        return null;
    }

    $json = json_decode($result, true);
    if (!is_array($json) || empty($json)) {
        return null;
    }

    set_transient($transient_key, $json, DAY_IN_SECONDS); // Cache the result for 24 hours

    return $json;
}

/**
 * Shortcode to test the DeepL API functionality.
 *
 * @return string HTML content with test results.
 */
function test_deepl_api_shortcode() {
    $output = '';
    
    // Test the get_deepl_language_names function
    $languages = get_deepl_language_names();
    if ($languages === null) {
        $output .= 'Failed to retrieve language names.<br>';
    } else {
        $output .= 'Available languages: ' . implode(', ', $languages) . '<br>';
    }

    // Test the translate_with_deepl function
    $translated_text = translate_with_deepl('Merhaba Dünya!', 'EN', 'TR');
    if ($translated_text === null) {
        $output .= 'Translation failed. Please check your API key.<br>';
    }
    $output .= 'Translated text from Turkish: ' . esc_html($translated_text) . '<br>';
    return $output;
}
add_shortcode('test_deepl_api', 'test_deepl_api_shortcode');
?>
