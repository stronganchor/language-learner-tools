<?php

/***************************
 * [word_audio] Shortcode
 * 
 * This shortcode displays a word with an interactive audio player icon.
 **************************/
function ll_word_audio_shortcode($atts = [], $content = null) {
    // Ensure content is not empty
    if (empty($content)) {
        return '';
    }
	
	$attributes = shortcode_atts(array(
        'translate' => 'yes', // Default is to provide a translation in parentheses
		'id' => null, // If set, it will look up the word post by ID
    ), $atts);

    // Store the unmodified content for later
    $original_content = $content;

    // Strip nested shortcodes temporarily
    $stripped_content = preg_replace('/\[.*?\]/', '', $content);
	
	$parentheses_regex = '/\(([^)]+)\)/'; 

	// Use preg_match to capture the English meaning inside parentheses
	$has_parenthesis = preg_match($parentheses_regex, $stripped_content, $matches);

	// Now remove the matched pattern from the content
	$without_parentheses = preg_replace($parentheses_regex, '', $stripped_content);
	
    $normalized_content = ll_normalize_case($without_parentheses);
	
	if (!empty($attributes['id'])) {
    	$post_id = intval($attributes['id']); // Ensure the ID is an integer
    	$post = get_post($post_id); // Retrieve the post by ID
	} else {
		$post = ll_find_post_by_exact_title($normalized_content, 'words');
	}
	
    // If no posts found, return the original content processed for nested shortcodes
    if (empty($post)) {
        return do_shortcode($original_content);
    }
	
	$english_meaning = '';
	
    // Retrieve the English meaning if needed
    if (!$has_parenthesis && $attributes['translate'] !== 'no') {
    	$english_meaning = get_post_meta($post->ID, 'word_english_meaning', true);
	}
	
	// Retrieve the audio file for this word
    $audio_file = get_post_meta($post->ID, 'word_audio_file', true);

    // Generate unique ID for the audio element
    $audio_id = uniqid('audio_');

	$play_icon = '<img src="/wp-content/uploads/2024/02/play-symbol.svg" width="10" height="10" alt="Play" data-no-lazy="1"/>';
	
    // Construct the output with an interactive audio player icon
    $output = '<span class="ll-word-audio">';
    if (!empty($audio_file)) {
        $output .= "<div id='{$audio_id}_icon' class='ll-audio-icon' style='width: 20px; display: inline-flex; cursor: pointer;' onclick='ll_toggleAudio(\"{$audio_id}\")'>". $play_icon . "</div>";
        $output .= "<audio id='{$audio_id}' onplay='ll_audioPlaying(\"{$audio_id}\")' onended='ll_audioEnded(\"{$audio_id}\")' style='display:none;'><source src='" . esc_url($audio_file) . "' type='audio/mpeg'></audio>";
    }
    $output .= do_shortcode($original_content);
    if (!empty($english_meaning)) {
        $output .= ' (' . esc_html($english_meaning) . ')';
    }
    $output .= '</span>';

    // Include JavaScript for toggling play/stop
    $output .= "
    <script>
	var play_icon = '<img src=\"/wp-content/uploads/2024/02/play-symbol.svg\" width=\"10\" height=\"10\" alt=\"Play\" data-no-lazy=\"1\">';
	var stop_icon = '<img src=\"/wp-content/uploads/2024/02/stop-symbol.svg\" width=\"9\" height=\"9\" alt=\"Stop\" data-no-lazy=\"1\">';
	
    function ll_toggleAudio(audioId) {
        var audio = document.getElementById(audioId);
        var icon = document.getElementById(audioId + '_icon');
        if (!audio.paused) {
            audio.pause();
            audio.currentTime = 0; // Stop the audio
			icon.innerHTML = play_icon;
        } else {
            audio.play();
        }
    }

    function ll_audioPlaying(audioId) {
        var icon = document.getElementById(audioId + '_icon');
        icon.innerHTML = stop_icon;
    }

    function ll_audioEnded(audioId) {
        var icon = document.getElementById(audioId + '_icon');
        icon.innerHTML = play_icon;
    }
    </script>
    ";

    return $output;
}
add_shortcode('word_audio', 'll_word_audio_shortcode');

// Look up word post by the exact title, being sensitive of special characters
function ll_find_post_by_exact_title($title, $post_type = 'words') {
    global $wpdb;

    // Sanitize the title to prevent SQL injection
    $title = sanitize_text_field($title);

	// Normalize the case of the content
    $title = ll_normalize_case($title);
	
    // Prepare the SQL query using prepared statements for security
    $query = $wpdb->prepare(
        "SELECT * FROM $wpdb->posts WHERE post_title = BINARY %s AND post_type = %s AND post_status = 'publish' LIMIT 1",
        $title,
        $post_type
    );

    // Execute the query
    $post = $wpdb->get_row($query);

    // Return the post if found
    return $post;
}

// Set a Turkish text to only have the first character capitalized.
function ll_normalize_case($text) {
    $target_language = get_option('ll_target_language');
	if ($target_language === 'TR' && function_exists('mb_strtolower') && function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        // Normalize the encoding to UTF-8 if not already
        $text = mb_convert_encoding($text, 'UTF-8', mb_detect_encoding($text));
		 
		// Special handling for Turkish dotless I and dotted İ
        $firstChar = mb_substr($text, 0, 1, 'UTF-8');
        if ($firstChar === 'i' || $firstChar === "\xC4\xB0" || $firstChar == 'İ') {
			return 'İ' . mb_substr($text, 1, null, 'UTF-8');
        } elseif ($firstChar === 'ı' || $firstChar === 'I') {
            return 'I' . mb_substr($text, 1, null, 'UTF-8');
        } else {
            $firstChar = mb_strtoupper($firstChar, 'UTF-8');
			$text = mb_strtolower($text, 'UTF-8');
       		return $firstChar . mb_substr($text, 1, null, 'UTF-8');
        }
    }

    // Just return the original text if Multibyte String functions aren't available
	return $text;
}
?>