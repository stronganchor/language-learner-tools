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
    // Capture and cache the hosting post ID once per request to avoid pollution from nested queries or prior shortcodes
    $host_post_id = ll_word_audio_get_host_post_id();

    // Ensure content is not empty
    if (empty($content)) {
        return '';
    }

    $context = ll_word_audio_extract_context($atts, $content);
    $attributes = $context['attributes'];
    $original_content = $context['original_content'];
    $has_parenthesis = $context['has_parenthesis'];
    $normalized_content = $context['normalized_content'];
    $word_post = $context['word_post'];
    $audio_file = $context['audio_file'];

    $is_missing_audio = empty($word_post) || empty($audio_file);

    // Keep track of instances of word_audio with no matching audio file
    if (current_user_can('administrator')) {
        if ($is_missing_audio) {
            // Cache the missing audio instance
            ll_cache_missing_audio_instance($normalized_content, $host_post_id);
        } else {
            // Remove the word from the missing audio cache if it exists
            ll_remove_missing_audio_instance($normalized_content);
        }
    }
	    
    // If no posts found, return the original content processed for nested shortcodes
    if (empty($word_post)) {
        return do_shortcode($original_content);
    }

	$english_meaning = '';
	
    // Retrieve the English meaning if needed
    if (!$has_parenthesis && $attributes['translate'] !== 'no') {
    	$english_meaning = get_post_meta($word_post->ID, 'word_english_meaning', true);
	}
	
    // Generate unique ID for the audio element
    $audio_id = uniqid('audio_');

    $play_icon = '<img src="' . LL_TOOLS_BASE_URL . 'media/play-symbol.svg" width="10" height="10" alt="Play" data-no-lazy="1"/>';

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
 * Extract normalized content and related data for the [word_audio] shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @param string $content Raw shortcode content.
 * @return array {
 *     @type array       $attributes        Parsed shortcode attributes with defaults applied.
 *     @type string      $original_content  Unmodified shortcode content.
 *     @type bool|int    $has_parenthesis   Whether the content already contains a translation in parentheses.
 *     @type string      $normalized_content Content normalized for lookups.
 *     @type WP_Post|false|null $word_post  Matched word post (if any).
 *     @type string      $audio_file        Audio file meta value (if any).
 * }
 */
function ll_word_audio_extract_context($atts, $content) {
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
        $word_post = get_post($post_id); // Retrieve the post by ID
    } else {
        $word_post = ll_find_post_by_exact_title($normalized_content, 'words');
    }

    // Retrieve the audio file for this word (if a matching word post exists)
    $audio_file = '';
    if (!empty($word_post)) {
        $audio_file = get_post_meta($word_post->ID, 'word_audio_file', true);
    }

    return array(
        'attributes' => $attributes,
        'original_content' => $original_content,
        'has_parenthesis' => $has_parenthesis,
        'normalized_content' => $normalized_content,
        'word_post' => $word_post,
        'audio_file' => $audio_file,
    );
}

/**
 * Resolve the post ID hosting this shortcode, cached per request to avoid cross-shortcode contamination.
 *
 * @return int
 */
function ll_word_audio_get_host_post_id() {
    static $cached_host_id = null;

    if ($cached_host_id !== null) {
        return $cached_host_id;
    }

    // Prefer the main query's object; this stays stable even when other shortcodes run nested queries.
    if (isset($GLOBALS['wp_the_query']) && $GLOBALS['wp_the_query'] instanceof WP_Query) {
        $cached_host_id = (int) $GLOBALS['wp_the_query']->get_queried_object_id();
    }

    if (!$cached_host_id) {
        $cached_host_id = (int) get_queried_object_id();
    }

    if (!$cached_host_id && isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
        $cached_host_id = (int) $GLOBALS['post']->ID;
    }

    if (!$cached_host_id) {
        $cached_host_id = (int) get_the_ID();
    }

    return $cached_host_id;
}

/**
 * Enqueues the JavaScript necessary for the word_audio shortcode.
 */
function ll_enqueue_word_audio_js() {
    ll_enqueue_asset_by_timestamp('js/word-audio.js', 'll-word-audio', array(), true);

    // Pass the plugin ROOT url to JS so it can find /media/*.svg reliably
    wp_localize_script('ll-word-audio', 'll_word_audio_data', array(
        'plugin_dir_url' => LL_TOOLS_BASE_URL,
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
    $post_id = intval($post_id);
    if (function_exists('ll_sanitize_word_title_text')) {
        $word = ll_sanitize_word_title_text($word);
    } else {
        // Basic fallback sanitization: strip shortcodes/tags, parentheses, and trim.
        $word = strip_shortcodes($word);
        $word = wp_strip_all_tags($word);
        $word = preg_replace('/\s*\([^)]*\)/u', '', $word);
        $word = preg_replace('/\s+/u', ' ', $word);
        $word = trim($word);
    }

    $missing_audio_instances = get_option('ll_missing_audio_instances', array());
    $existing_post_id = isset($missing_audio_instances[$word]) ? intval($missing_audio_instances[$word]) : null;

    if ($post_id <= 0) {
        if ($existing_post_id === null) {
            $missing_audio_instances[$word] = 0;
            update_option('ll_missing_audio_instances', $missing_audio_instances);
        }
        return;
    }

    if ($existing_post_id !== $post_id) {
        $missing_audio_instances[$word] = $post_id;
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
        // Include drafts/pending so freshly created words from recordings are detected
        'post_status' => array('publish', 'draft', 'pending', 'private'),
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

/**
 * Remove a word from the missing-audio cache when a recording exists.
 */
function ll_tools_clear_missing_audio_for_word($word_id, $word_title = '') {
    if (!function_exists('ll_remove_missing_audio_instance') || !$word_id) {
        return;
    }

    // Prefer the provided title, otherwise pull from the word post.
    if ($word_title === '') {
        $word_post = get_post($word_id);
        if ($word_post && $word_post->post_type === 'words') {
            $word_title = $word_post->post_title;
        }
    }

    // Match the same normalization pipeline used when populating the cache.
    $canonicalize_apostrophes = function ($text) {
        return str_replace(
            array("\u{2019}", "\u{2018}", "\u{201B}", "\u{02BC}", "\u{FF07}"),
            "'",
            (string) $text
        );
    };

    $candidates = [];

    $normalized = $word_title;
    if (function_exists('ll_normalize_case')) {
        $normalized = ll_normalize_case($normalized);
    }
    if (function_exists('ll_missing_audio_sanitize_word_text')) {
        $normalized = ll_missing_audio_sanitize_word_text($normalized);
    }
    if ($normalized !== '') {
        $candidates[] = $normalized;
        $candidates[] = $canonicalize_apostrophes($normalized);
        $candidates[] = preg_replace("/['’ʼ`´]/u", '', $normalized);
    }
    if ($word_title !== '') {
        $candidates[] = $word_title;
        $candidates[] = $canonicalize_apostrophes($word_title);
        $candidates[] = preg_replace("/['’ʼ`´]/u", '', $word_title);
        if (function_exists('ll_normalize_case')) {
            $norm = ll_normalize_case($word_title);
            $candidates[] = $norm;
            $candidates[] = $canonicalize_apostrophes($norm);
            $candidates[] = preg_replace("/['’ʼ`´]/u", '', $norm);
        }
    }

    foreach (array_unique(array_filter($candidates, function ($v) { return is_string($v) && $v !== ''; })) as $cand) {
        ll_remove_missing_audio_instance($cand);
    }
}

/**
 * When saving a word_audio child, automatically clear any missing-audio cache entries for its parent word.
 */
function ll_tools_clear_missing_audio_on_word_audio_save($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
        return;
    }
    if (!$post || $post->post_type !== 'word_audio') {
        return;
    }
    $parent_id = (int) $post->post_parent;
    if ($parent_id > 0) {
        ll_tools_clear_missing_audio_for_word($parent_id);
    }
}
add_action('save_post_word_audio', 'll_tools_clear_missing_audio_on_word_audio_save', 20, 3);

?>
