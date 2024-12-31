<?php

/***************************
 * [word_audio] Shortcode
 * 
 * This shortcode displays a word with an interactive audio player icon.
 **************************/

/**
 * Shortcode handler for [word_audio].
 *
 * @param array $atts Shortcode attributes.
 * @param string|null $content The content within the shortcode.
 * @return string The HTML output for the word audio.
 */
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
	    
	// Strip out parenthesis if it exists
	$parentheses_regex = '/\s*\(([^)]+)\)/'; 
	$has_parenthesis = preg_match($parentheses_regex, $stripped_content, $matches);
	$without_parentheses = preg_replace($parentheses_regex, '', $stripped_content);
	
    $normalized_content = ll_normalize_case($without_parentheses);
	
	if (!empty($attributes['id'])) {
    	$post_id = intval($attributes['id']); // Ensure the ID is an integer
    	$post = get_post($post_id); // Retrieve the post by ID
	} else {
		$post = ll_find_post_by_exact_title($normalized_content, 'words');
	}
	
    // Keep track of instances of word_audio with no matching audio file
    if (current_user_can('administrator')) {
        if (empty($post)) {
            // Cache the missing audio instance
            ll_cache_missing_audio_instance($normalized_content, get_the_ID());
        } else {
            // Remove the word from the missing audio cache if it exists
            ll_remove_missing_audio_instance($normalized_content);
        }
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

	$play_icon = '<img src="' . plugin_dir_url(__FILE__) . 'media/play-symbol.svg" width="10" height="10" alt="Play" data-no-lazy="1"/>';

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

    return $output;
}
add_shortcode('word_audio', 'll_word_audio_shortcode');

/**
 * Enqueues the JavaScript necessary for the word_audio shortcode.
 */
function ll_enqueue_word_audio_js() {    
    ll_enqueue_asset_by_timestamp('js/word-audio.js', 'll-word-audio', array(), true);

    // Pass the plugin's directory URL to the JavaScript code
    wp_localize_script('ll-word-audio', 'll_word_audio_data', array(
        'plugin_dir_url' => plugin_dir_url(__FILE__),
    ));
}
add_action('wp_enqueue_scripts', 'll_enqueue_word_audio_js');

/**
 * Caches instances of missing audio for administrator users.
 *
 * @param string $word The word with missing audio.
 * @param int    $post_id The post ID associated with the word.
 */
function ll_cache_missing_audio_instance($word, $post_id) {
    $missing_audio_instances = get_option('ll_missing_audio_instances', array());

    if (!isset($missing_audio_instances[$word])) {
        $missing_audio_instances[$word] = intval($post_id);
        update_option('ll_missing_audio_instances', $missing_audio_instances);
    }
}

/**
 * Removes a word from the missing audio cache.
 *
 * @param string $word The word to remove from the cache.
 */
function ll_remove_missing_audio_instance($word) {
    $missing_audio_instances = get_option('ll_missing_audio_instances', array());

    if (isset($missing_audio_instances[$word])) {
        unset($missing_audio_instances[$word]);
        update_option('ll_missing_audio_instances', $missing_audio_instances);
    }
}

/**
 * Compares two strings character by character.
 *
 * @param string $str1 The first string.
 * @param string $str2 The second string.
 * @return bool True if strings are identical, false otherwise.
 */
function ll_strcmp($str1, $str2) {
    $len1 = strlen($str1);
    $len2 = strlen($str2);

    if ($len1 !== $len2) {
        return false;
    }

    for ($i = 0; $i < $len1; $i++) {
        if ($str1[$i] !== $str2[$i]) {
            return false;
        }
    }

    return true;
}

/**
 * Finds a post by its exact title, sensitive to special characters.
 *
 * @param string $title The title to search for.
 * @param string $post_type The post type to search within.
 * @return WP_Post|null The matched post or null if not found.
 */
function ll_find_post_by_exact_title($title, $post_type = 'words') {
    $query_args = array(
        'post_type' => $post_type,
        'posts_per_page' => -1, // Retrieve all matching posts
        'post_status' => 'publish',
        'title' => sanitize_text_field($title),
        'exact' => true,
    );

    $query = new WP_Query($query_args);

    if ($query->have_posts()) {
        $exact_match = null;
        while ($query->have_posts()) {
            $query->the_post();
            $post = get_post();
            
            if (ll_strcmp($post->post_title, $title)) {
                $exact_match = $post;
                break;
            }
        }
        wp_reset_postdata();
        return $exact_match;
    }

    return null;
}

/**
 * Normalizes the case of a string based on target language settings.
 *
 * @param string $text The text to normalize.
 * @return string The normalized text.
 */
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
