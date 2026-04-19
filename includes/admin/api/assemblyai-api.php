<?php
/**
 * AssemblyAI API Interface
 *
 * Handles storing the API key and basic transcription helpers.
 */

if (!defined('WPINC')) { die; }

if (!function_exists('ll_tools_api_settings_capability')) {
    function ll_tools_api_settings_capability() {
        return (string) apply_filters('ll_tools_api_settings_capability', 'manage_options');
    }
}

// Add an admin page under Tools for entering the AssemblyAI API key.
function ll_add_assemblyai_api_key_page() {
    add_management_page(
        __('AssemblyAI API Key', 'll-tools-text-domain'),
        __('AssemblyAI API Key', 'll-tools-text-domain'),
        ll_tools_api_settings_capability(),
        'assemblyai-api-key',
        'll_assemblyai_api_key_page_content'
    );
}
add_action('admin_menu', 'll_add_assemblyai_api_key_page');

/**
 * Renders the AssemblyAI API Key admin page content.
 */
function ll_assemblyai_api_key_page_content() {
    if (!current_user_can(ll_tools_api_settings_capability())) {
        wp_die(__('You do not have permission to view this page.', 'll-tools-text-domain'));
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Enter Your AssemblyAI API Key', 'll-tools-text-domain'); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('ll-assemblyai-api-key-group');
            do_settings_sections('ll-assemblyai-api-key-group');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e('AssemblyAI API Key', 'll-tools-text-domain'); ?></th>
                    <td>
                        <input type="password" name="ll_assemblyai_api_key" value="<?php echo esc_attr(get_option('ll_assemblyai_api_key')); ?>" autocomplete="off" />
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Registers the AssemblyAI API key setting.
 */
function ll_register_assemblyai_api_key_setting() {
    register_setting('ll-assemblyai-api-key-group', 'll_assemblyai_api_key', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
}
add_action('admin_init', 'll_register_assemblyai_api_key_setting');

/**
 * Retrieve the configured AssemblyAI API key.
 *
 * @return string
 */
function ll_get_assemblyai_api_key() {
    return trim((string) get_option('ll_assemblyai_api_key', ''));
}

/**
 * Upload an audio file to AssemblyAI and return its upload URL.
 *
 * @param string $file_path
 * @return string|WP_Error
 */
function ll_tools_assemblyai_upload_audio($file_path) {
    $api_key = ll_get_assemblyai_api_key();
    if ($api_key === '') {
        return new WP_Error('missing_key', __('AssemblyAI API key not configured.', 'll-tools-text-domain'));
    }

    if (!is_readable($file_path)) {
        return new WP_Error('file_missing', __('Audio file is missing or unreadable.', 'll-tools-text-domain'));
    }

    $audio_data = file_get_contents($file_path);
    if ($audio_data === false) {
        return new WP_Error('file_read', __('Failed to read audio data.', 'll-tools-text-domain'));
    }

    $response = wp_remote_post('https://api.assemblyai.com/v2/upload', [
        'headers' => [
            'authorization' => $api_key,
            'content-type'  => 'application/octet-stream',
        ],
        'body'    => $audio_data,
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['upload_url'])) {
        return new WP_Error('upload_failed', __('AssemblyAI upload failed.', 'll-tools-text-domain'));
    }

    return $body['upload_url'];
}

/**
 * Create a transcript request in AssemblyAI.
 *
 * @param string $upload_url
 * @param string $language_code
 * @param array  $options
 * @return string|WP_Error
 */
function ll_tools_assemblyai_request_transcript($upload_url, $language_code = '', array $options = []) {
    $api_key = ll_get_assemblyai_api_key();
    if ($api_key === '') {
        return new WP_Error('missing_key', __('AssemblyAI API key not configured.', 'll-tools-text-domain'));
    }

    $language_code = function_exists('ll_tools_normalize_language_code')
        ? (string) ll_tools_normalize_language_code($language_code, 'lower')
        : strtolower(trim((string) $language_code));
    if ($language_code === 'auto') {
        $language_code = '';
    }

    $payload = [
        'audio_url'   => $upload_url,
        'punctuate'   => true,
        'format_text' => true,
    ];

    if ($language_code !== '') {
        $payload['language_code'] = $language_code;
    }

    $speech_models = [];
    foreach ((array) ($options['speech_models'] ?? []) as $speech_model) {
        $speech_model = sanitize_key((string) $speech_model);
        if ($speech_model !== '') {
            $speech_models[$speech_model] = $speech_model;
        }
    }
    $speech_models = array_values($speech_models);
    if (!empty($speech_models)) {
        $payload['speech_models'] = $speech_models;
    }

    if (!empty($options['language_detection']) && $language_code === '') {
        $payload['language_detection'] = true;
    }

    if (!empty($payload['language_detection']) && !empty($options['language_detection_options']) && is_array($options['language_detection_options'])) {
        $payload['language_detection_options'] = $options['language_detection_options'];
    }

    $response = wp_remote_post('https://api.assemblyai.com/v2/transcript', [
        'headers' => [
            'authorization' => $api_key,
            'content-type'  => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['id'])) {
        return new WP_Error('transcript_failed', __('AssemblyAI transcript request failed.', 'll-tools-text-domain'));
    }

    return (string) $body['id'];
}

/**
 * Poll AssemblyAI for transcript completion.
 *
 * @param string $transcript_id
 * @return array|WP_Error
 */
function ll_tools_assemblyai_get_transcript($transcript_id) {
    $api_key = ll_get_assemblyai_api_key();
    if ($api_key === '') {
        return new WP_Error('missing_key', __('AssemblyAI API key not configured.', 'll-tools-text-domain'));
    }

    $response = wp_remote_get('https://api.assemblyai.com/v2/transcript/' . rawurlencode($transcript_id), [
        'headers' => [
            'authorization' => $api_key,
        ],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code < 200 || $code >= 300 || !is_array($body)) {
        return new WP_Error('transcript_status_failed', __('AssemblyAI transcript status check failed.', 'll-tools-text-domain'));
    }

    return $body;
}

/**
 * Upload an audio file and create a transcript request in AssemblyAI.
 *
 * @param string $file_path
 * @param string $language_code
 * @param array  $options
 * @return string|WP_Error Transcript ID
 */
function ll_tools_assemblyai_start_transcription($file_path, $language_code = '', array $options = []) {
    $upload_url = ll_tools_assemblyai_upload_audio($file_path);
    if (is_wp_error($upload_url)) {
        return $upload_url;
    }

    return ll_tools_assemblyai_request_transcript($upload_url, $language_code, $options);
}

/**
 * Upload and transcribe an audio file with AssemblyAI (blocking helper).
 *
 * @param string $file_path
 * @param string $language_code
 * @param array  $options
 * @return array|WP_Error
 */
function ll_tools_assemblyai_transcribe_audio_file($file_path, $language_code = '', array $options = []) {
    $transcript_id = ll_tools_assemblyai_start_transcription($file_path, $language_code, $options);
    if (is_wp_error($transcript_id)) {
        return $transcript_id;
    }

    $max_attempts = (int) apply_filters('ll_tools_assemblyai_poll_attempts', 15);
    $sleep_seconds = (int) apply_filters('ll_tools_assemblyai_poll_sleep', 1);
    if ($max_attempts < 1) $max_attempts = 1;
    if ($sleep_seconds < 0) $sleep_seconds = 0;

    for ($i = 0; $i < $max_attempts; $i++) {
        $status = ll_tools_assemblyai_get_transcript($transcript_id);
        if (is_wp_error($status)) {
            return $status;
        }

        $state = isset($status['status']) ? $status['status'] : '';
        if ($state === 'completed') {
            return [
                'id' => $transcript_id,
                'status' => 'completed',
                'text' => isset($status['text']) ? (string) $status['text'] : '',
                'language_code' => isset($status['language_code']) ? (string) $status['language_code'] : (string) $language_code,
                'speech_model_used' => isset($status['speech_model_used']) ? (string) $status['speech_model_used'] : '',
            ];
        }

        if ($state === 'error') {
            $msg = isset($status['error']) ? $status['error'] : __('AssemblyAI transcription failed.', 'll-tools-text-domain');
            return new WP_Error('transcript_error', $msg);
        }

        if ($sleep_seconds > 0) {
            sleep($sleep_seconds);
        }
    }

    return new WP_Error('transcript_timeout', __('AssemblyAI transcription is still processing.', 'll-tools-text-domain'));
}
