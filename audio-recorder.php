<?php

// [audio_recorder] shortcode
function custom_audio_recorder_shortcode() {
    // Unique identifier for multiple recorders on the same page
    $uid = uniqid('audio_recorder_');

    // Enqueue the JavaScript file
    wp_enqueue_script('custom-audio-recorder', plugin_dir_url(__FILE__) . 'js/audio-recorder.js', array(), null, true);

    // The button HTML, note the change in the onclick attribute
    $html = "<button id='{$uid}' onclick='toggleRecording(this, \"{$uid}\")'>Start Recording</button>
             <audio id='audio_playback_{$uid}' controls hidden></audio>";

    // Add a div to display the transcription
    $html .= "<div id='transcription_{$uid}'>Transcription will appear here</div>";

    return $html;
}
add_shortcode('audio_recorder', 'custom_audio_recorder_shortcode');


// Handle AJAX request for audio transcription
function handle_audio_transcription() {
    // Check for nonce security
    if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'audio_nonce_security')) {
        wp_send_json_error('Security check failed. Nonce check failed for nonce with the following content: ' . $_POST['security']);
        return; // Stop execution if the nonce is not valid
    }
    
    // Ensure there's a file and it's a POST request
    if (isset($_FILES['audioFile']) && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $audio_temp_path = $_FILES['audioFile']['tmp_name'];
        
        // Move the uploaded audio file to a permanent location
        $upload_dir = wp_upload_dir();
        $audio_file_name = uniqid() . '.mp3';
        $audio_file_path = $upload_dir['path'] . '/' . $audio_file_name;
        move_uploaded_file($audio_temp_path, $audio_file_path);

        $transcription = transcribe_audio_recording($audio_file_path, ''); // Assume $user_prompt not needed or manage accordingly

        // Send a JSON response back
        wp_send_json_success(['transcription' => $transcription]);
    } else {
        // Send error message if no audio file received
        wp_send_json_error('No audio file received or incorrect request method.');
    }
}
add_action('wp_ajax_process_audio_transcription', 'handle_audio_transcription');

function custom_enqueue_scripts() {
    $script_path = plugin_dir_path(__FILE__) . 'js/audio-recorder.js';
    $script_url = plugin_dir_url(__FILE__) . 'js/audio-recorder.js';
    $script_version = filemtime($script_path); // Get the file last modification time

    wp_enqueue_script('custom-audio-recorder', $script_url, array(), $script_version, true);

    wp_localize_script('custom-audio-recorder', 'my_ajax_object', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'audio_nonce' => wp_create_nonce('audio_nonce_security') // Adding nonce here
    ));
}
add_action('wp_enqueue_scripts', 'custom_enqueue_scripts');


function transcribe_audio_recording($audio_url, $user_prompt) {
    $api_key = get_option('chatgpt_api_key');
    $url = 'https://api.openai.com/v1/audio/transcriptions';

    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: multipart/form-data'
    ];

    $postfields = [
        'file' => new CURLFile($audio_url),
        'model' => 'whisper-1',
        'response_format' => 'text',
        'prompt' => $user_prompt // Add user prompt
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return "cURL Error: " . $error_msg;
    }

    curl_close($ch);

    if (!empty($response)) {
        return esc_html($response);
    } else {
        return 'Error: No text found in the response.';
    }
}
