<?php
/*
 * OpenAI API Integration for WordPress
 */

/**
 * Adds the ChatGPT Interface admin page under the Tools menu.
 */
function chatgpt_admin_page() {
    add_submenu_page(
        'tools.php',
        'ChatGPT Interface',
        'ChatGPT Interface',
        'manage_options',
        'chatgpt-interface',
        'chatgpt_interface_callback'
    );
}
add_action('admin_menu', 'chatgpt_admin_page');

/**
 * Renders the ChatGPT Interface admin page content.
 */
function chatgpt_interface_callback() {
    ?>
    <div class="wrap">
        <h2>ChatGPT Interface</h2>
        <form method="post" action="options.php">
            <?php settings_fields('chatgpt-settings-group'); ?>
            <?php do_settings_sections('chatgpt-settings-group'); ?>
            <table class="form-table">
                <tr valign="top">
                <th scope="row">ChatGPT API Key</th>
                <td><input type="text" name="chatgpt_api_key" value="<?php echo esc_attr(get_option('chatgpt_api_key')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Registers the ChatGPT API key setting.
 */
function chatgpt_settings() {
    register_setting('chatgpt-settings-group', 'chatgpt_api_key');
}
add_action('admin_init', 'chatgpt_settings');

/**
 * Sends a message to the ChatGPT API and retrieves the response.
 *
 * @param string $message The message to send.
 * @param string $model The model to use (default: 'gpt-3.5-turbo').
 * @return string The response from ChatGPT or an error message.
 */
function chatgpt_send_message($message, $model = 'gpt-3.5-turbo') {
    $api_key = get_option('chatgpt_api_key'); // Retrieve API key from WordPress settings
    $url = 'https://api.openai.com/v1/chat/completions';

    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ];

    $body = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $message]],
        'temperature' => 0
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
	
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log("ChatGPT cURL Error: " . $error_msg);
        curl_close($ch);
        return "Error: cURL error - " . $error_msg;
    }

    curl_close($ch);

    $decoded_response = json_decode($response, true);
    if (isset($decoded_response['error'])) {
        error_log("ChatGPT API Error: " . $decoded_response['error']['message']);
        return "Error: " . $decoded_response['error']['message'] . "    Raw Response: " . $response;
    }

    if (!isset($decoded_response['choices'][0]['message']['content'])) {
        error_log("ChatGPT Error: Unexpected API response format.");
        return "Error: Unexpected API response format." . "    Raw Response: " . $response; 
    }

    return $decoded_response['choices'][0]['message']['content'];
}

/**
 * Shortcode callback to display the ChatGPT form and handle submissions.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML content for the ChatGPT form and response.
 */
function chatgpt_shortcode_callback($atts) {
    $output = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chatgpt_message'])) {
        $user_message = sanitize_text_field($_POST['chatgpt_message']);
        $response = chatgpt_send_message($user_message);
        $output .= '<div class="chatgpt-response"><strong>Zeki:</strong> ' . esc_html($response) . '</div>';
    }

    $output .= '
    <form method="post">
        <textarea name="chatgpt_message" required placeholder="Type your message..."></textarea>
        <input type="submit" value="Send" />
    </form>
    ';

    return $output;
}
add_shortcode('chatgpt_form', 'chatgpt_shortcode_callback');

/**
 * Generates a text-to-speech audio file using OpenAI's TTS API.
 *
 * @param string $input_text The text to convert to speech.
 * @param string $voice The voice to use (default: 'onyx').
 * @return string The URL to the generated audio file or an error message.
 */
function generate_text_to_speech($input_text, $voice = 'onyx') {
    $api_key = get_option('chatgpt_api_key'); // Retrieve API key from WordPress settings

    $url = 'https://api.openai.com/v1/audio/speech';

    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ];

    $body = json_encode([
        'model' => 'tts-1-hd',
        'voice' => $voice,
        'input' => $input_text
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return "Error: cURL error - " . $error_msg;
    }

    curl_close($ch);

    // Check if the response is binary data
    if (!preg_match('/^[[:print:]\r\n\t]*$/', $response)) {
        // Format and sanitize the filename
        $formatted_text = substr(strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $input_text)), 0, 100); // Replace non-alphanumeric characters with underscore and limit to 100 chars
        $audio_file_name = 'tts_' . $voice . '_' . $formatted_text . '.mp3'; // Create the file name
        $upload_dir = wp_upload_dir();
        $audio_file_path = trailingslashit($upload_dir['path']) . $audio_file_name;

        file_put_contents($audio_file_path, $response);

        // Return the URL to the saved audio file
        return trailingslashit($upload_dir['url']) . $audio_file_name;
    } else {
        return "Error: Unexpected API response format.";
    }
}

/**
 * Transcribes speech from an audio file using OpenAI's Speech-To-Text API.
 *
 * @param string $audio_url The URL to the audio file.
 * @param string $user_prompt An optional user prompt to assist transcription.
 * @return string The transcribed text or an error message.
 */
function transcribe_audio_to_text($audio_url, $user_prompt) {
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
        error_log("Transcription cURL Error: " . $error_msg);
        curl_close($ch);
        return "Error: cURL error - " . $error_msg;
    }

    curl_close($ch);

    if (!empty($response)) {
        $escaped_response = esc_html($response);
		return add_paragraph_breaks_to_text($escaped_response);
    } else {
        return 'Error: No text found in the response.';
    }
}

/**
 * Adds paragraph breaks to transcribed text based on sentence boundaries.
 *
 * @param string $text The transcribed text.
 * @return string The text with paragraph breaks.
 */
function add_paragraph_breaks_to_text($text) {
    $processed_text = '';
    $remainingText = $text;
    $error_messages = ''; // Initialize variable to accumulate error messages

    while (!empty($remainingText)) {
        // Extract the first 200 words for analysis
        $first200WordsArray = array_slice(explode(' ', $remainingText), 0, 200);
        $first200Words = implode(' ', $first200WordsArray);

        $prompt = "Given the following text, identify the number of sentences that should form the first paragraph. Provide a single number between 1 and 5 as your response, with no other commentary.\n\n" . $first200Words;
        
        $model = 'gpt-3.5-turbo'; // Using gpt-3.5-turbo model
        $response = chatgpt_send_message($prompt, $model);

        // Attempt to extract a single number from the response
        preg_match('/\b[1-5]\b/', $response, $matches);
        if (empty($matches)) {
            // If no valid number is found, log the error and proceed without breaking
            $error_messages .= '<strong>Error: Received unexpected response format from the API: </strong>' . htmlspecialchars($response) . ' <strong>For text: </strong>' . htmlspecialchars($first200Words) . '<br />';
            // Default to a conservative number of sentences if no valid response is received
            $length = 2;
        } else {
            $length = (int) $matches[0]; // Use the extracted number
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $remainingText, -1, PREG_SPLIT_NO_EMPTY);
        $paragraph = array_slice($sentences, 0, $length);
        $processed_text .= '<p>' . implode(' ', $paragraph) . '</p>';

        // Update remainingText for the next iteration
        $remainingText = implode(' ', array_slice($sentences, $length));
    }

    // Append any error messages to the bottom of the processed text
    if (!empty($error_messages)) {
        $processed_text .= "<div class='error-messages'>$error_messages</div>";
    }

    return $processed_text;
}

/**
 * Generates a variation of an image using OpenAI's DALL-E API.
 *
 * @param string $image_url The URL to the original image.
 * @return string|null The URL to the generated image variation or null on failure.
 */
function generate_image_variation($image_url) {
    $api_key = get_option('chatgpt_api_key'); // Retrieve API key from WordPress settings
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.openai.com/v1/images/variations',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => [
            'image' => new CURLFile($image_url),
            'model' => 'dall-e-2',
            'n' => 1, // Number of variations to generate
            'size' => '1024x1024'
        ],
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_key
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
	
	// Debugging: Display raw API response
    echo '<pre>API Response: ' . htmlspecialchars($response) . '</pre>';

    $decoded_response = json_decode($response, true);

    // Accessing the URL from the response
    if(isset($decoded_response['data'][0]['url'])) {
        return esc_url($decoded_response['data'][0]['url']);
    } else {
        return null; // Return null if the URL is not found
    }
}

/**
 * Shortcode callback to display the TTS form and handle submissions.
 *
 * @return string HTML content for the TTS form and audio player.
 */
function tts_shortcode_callback() {
    $output = '<form method="post" enctype="multipart/form-data">';
    $output .= '<textarea name="tts_input_text" required placeholder="Enter text to convert to speech..."></textarea><br>';

    // Voice selection dropdown
    $output .= '<label for="voice_selection">Choose a voice:</label>';
    $output .= '<select name="voice_selection" id="voice_selection">';
    $voices = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    foreach ($voices as $voice) {
        $selected = ($voice == 'shimmer') ? 'selected' : '';
        $output .= '<option value="' . esc_attr($voice) . '" ' . $selected . '>' . ucfirst($voice) . '</option>';
    }
    $output .= '</select><br>';

    $output .= '<input type="submit" value="Convert to Speech" />';
    $output .= '</form>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['tts_input_text'])) {
        $input_text = sanitize_text_field($_POST['tts_input_text']);
        $selected_voice = isset($_POST['voice_selection']) ? sanitize_text_field($_POST['voice_selection']) : 'shimmer';
        $audio_url = generate_text_to_speech($input_text, $selected_voice);

        if (filter_var($audio_url, FILTER_VALIDATE_URL)) {
            $output .= '<audio controls><source src="' . esc_url($audio_url) . '" type="audio/mpeg">Your browser does not support the audio element.</audio>';
        } else {
            $output .= '<div class="error-message">' . esc_html($audio_url) . '</div>'; // Display error message
        }
    }

    return $output;
}
add_shortcode('tts_form', 'tts_shortcode_callback');

/**
 * Shortcode callback to handle audio file uploads and transcribe them.
 *
 * @return string HTML content for the audio-to-text form and transcription results.
 */
function audio_to_text_shortcode_callback() {
    $output = '<form method="post" enctype="multipart/form-data">';
    $output .= '<label for="audio_file">Upload an audio file:</label><br>';
    $output .= '<input type="file" name="audio_file" id="audio_file" accept="audio/*"><br>';
    $output .= '<textarea name="user_prompt" placeholder="Enter a prompt to assist transcription (optional)"></textarea><br>';
    $output .= '<input type="submit" name="submit_audio_transcription" value="Transcribe Audio">';
    $output .= '</form>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_audio_transcription']) && isset($_FILES['audio_file'])) {
        if ($_FILES['audio_file']['size'] > 26214400) { // 25MB limit
            $output .= '<div class="error-message">Error: File size exceeds 26 MB limit.</div>';
        } else {
            $upload = wp_handle_upload($_FILES['audio_file'], array('test_form' => FALSE));
            if (isset($upload['error'])) {
                $output .= '<div class="error-message">Upload Error: ' . esc_html($upload['error']) . '</div>';
            } else {
                $audio_file_url = $upload['url'];
                $user_prompt = isset($_POST['user_prompt']) ? sanitize_text_field($_POST['user_prompt']) : '';
                $transcribed_text = transcribe_audio_to_text($audio_file_url, $user_prompt);
                $output .= '<div class="transcribed-text">' . $transcribed_text . '</div>';
            }
        }
    }

    return $output;
}
add_shortcode('audio_to_text_form', 'audio_to_text_shortcode_callback');

/**
 * Shortcode callback to generate image variations using OpenAI's DALL-E API.
 *
 * @return string HTML content for the image variation form and generated image.
 */
function image_variation_shortcode() {
    ob_start();
    ?>
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="uploaded_image" accept="image/*">
        <input type="submit" name="submit_image_variation" value="Generate Image Variation">
    </form>
    <?php
    if (isset($_POST['submit_image_variation']) && !empty($_FILES['uploaded_image']['tmp_name'])) {
        $file = $_FILES['uploaded_image']['tmp_name'];
        $result = generate_image_variation($file);

        if ($result) {
            // Assuming the result contains a URL to the generated image
            $output .= '<img src="' . esc_url($result) . '" alt="Generated Image Variation">';
        } else {
            $output .= '<div class="error-message">An error occurred while generating the image variation.</div>';
        }
    }
    return ob_get_clean();
}
add_shortcode('image_variation', 'image_variation_shortcode');
?>
