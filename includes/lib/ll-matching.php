<?php
// /includes/lib/ll-matching.php
if (!defined('WPINC')) { die; }

/**
 * Tweakable thresholds (filters let you override in theme or a small mu-plugin)
 */
if (!defined('LL_MATCH_AUTOMATCH_THRESHOLD')) {
    define('LL_MATCH_AUTOMATCH_THRESHOLD', 0.93); // conservative
}
if (!defined('LL_MATCH_STRICT_CONTAINS_BONUS')) {
    define('LL_MATCH_STRICT_CONTAINS_BONUS', 0.97); // near-certain when A contains B verbatim
}

/**
 * Normalize a label for fuzzy matching.
 */
function ll_match_norm($s) {
    $s = (string) $s;
    $s = strtolower($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = remove_accents($s);
    // Replace separators with space
    $s = preg_replace('~[._\-–—/:()]+~u', ' ', $s);
    // Collapse spaces
    $s = preg_replace('~\s+~u', ' ', trim($s));
    return $s;
}

/**
 * Jaro-Winkler similarity for UTF-8 (operates on bytes; good enough for latin scripts after remove_accents)
 * Returns 0..1
 */
function ll_match_jaro_winkler($s1, $s2) {
    $s1 = (string)$s1; $s2 = (string)$s2;
    $len1 = strlen($s1); $len2 = strlen($s2);
    if ($len1 === 0 && $len2 === 0) return 1.0;
    if ($len1 === 0 || $len2 === 0) return 0.0;
    $match_distance = (int) floor(max($len1, $len2) / 2) - 1;
    if ($match_distance < 0) $match_distance = 0;

    $s1_matches = array_fill(0, $len1, false);
    $s2_matches = array_fill(0, $len2, false);

    $matches = 0;
    for ($i = 0; $i < $len1; $i++) {
        $start = max(0, $i - $match_distance);
        $end   = min($i + $match_distance + 1, $len2);
        for ($j = $start; $j < $end; $j++) {
            if ($s2_matches[$j]) continue;
            if ($s1[$i] !== $s2[$j]) continue;
            $s1_matches[$i] = true;
            $s2_matches[$j] = true;
            $matches++;
            break;
        }
    }
    if ($matches === 0) return 0.0;

    $t = 0; $k = 0;
    for ($i = 0; $i < $len1; $i++) {
        if (!$s1_matches[$i]) continue;
        while (!$s2_matches[$k]) $k++;
        if ($s1[$i] !== $s2[$k]) $t++;
        $k++;
    }
    $t /= 2.0;

    $jaro = (($matches / $len1) + ($matches / $len2) + (($matches - $t) / $matches)) / 3.0;

    // Winkler bonus for common prefix up to 4
    $prefix = 0;
    for ($i = 0; $i < min(4, $len1, $len2); $i++) {
        if ($s1[$i] === $s2[$i]) $prefix++; else break;
    }
    $p = 0.1;
    return $jaro + ($prefix * $p * (1 - $jaro));
}

/**
 * Combined similarity 0..1 with conservative boosts for exact and contains.
 */
function ll_match_similarity($a, $b) {
    $A = ll_match_norm($a);
    $B = ll_match_norm($b);
    if ($A === '' || $B === '') return 0.0;

    // Exact normalized match
    if ($A === $B) return 1.0;

    // If one contains the other verbatim (token-aware-ish), treat as very strong
    if (strpos(' ' . $A . ' ', ' ' . $B . ' ') !== false || strpos(' ' . $B . ' ', ' ' . $A . ' ') !== false) {
        return LL_MATCH_STRICT_CONTAINS_BONUS;
    }

    // Core metrics
    $jw = ll_match_jaro_winkler($A, $B);

    $sim_txt = 0.0;
    if (function_exists('similar_text')) {
        similar_text($A, $B, $pct);
        $sim_txt = max(0.0, min(1.0, $pct / 100.0));
    }

    // Levenshtein ratio (bounded)
    $lev = null;
    if (function_exists('levenshtein')) {
        $maxlen = max(strlen($A), strlen($B));
        if ($maxlen > 0) {
            $lev = 1.0 - (levenshtein($A, $B) / $maxlen);
            $lev = max(0.0, min(1.0, $lev));
        }
    }

    // Take a robust max (conservative)
    $core = $jw;
    if ($lev !== null) $core = max($core, $lev);
    $core = max($core, $sim_txt);

    // Light bonus if token sets are near-identical
    $tokensA = array_values(array_filter(explode(' ', $A)));
    $tokensB = array_values(array_filter(explode(' ', $B)));
    if ($tokensA && $tokensB) {
        $setA = array_unique($tokensA);
        $setB = array_unique($tokensB);
        $inter = array_intersect($setA, $setB);
        $union = array_unique(array_merge($setA, $setB));
        $jaccard = count($union) ? count($inter)/count($union) : 0.0;
        if ($jaccard >= 0.8) $core = min(1.0, $core + 0.05);
    }

    return $core;
}

/**
 * Decide if we should auto-match based on a conservative threshold.
 */
function ll_match_is_confident($a, $b, $threshold = null) {
    $threshold = $threshold ?? apply_filters('ll_match_autothreshold', LL_MATCH_AUTOMATCH_THRESHOLD);
    return ll_match_similarity($a, $b) >= $threshold;
}

/**
 * Find the best word_images post in the term for a given words post.
 * Returns array [ 'image_post_id' => int|null, 'score' => float ]
 */
function ll_match_find_best_image_for_word($word_post_id, $term_id) {
    $title = get_the_title($word_post_id);
    $alt   = get_post_meta($word_post_id, 'word_english_meaning', true);

    $candidates = get_posts([
        'post_type'      => 'word_images',
        'posts_per_page' => -1,
        'tax_query'      => [[
            'taxonomy' => 'word-category',
            'field'    => 'term_id',
            'terms'    => [$term_id],
        ]],
        'orderby' => 'title',
        'order'   => 'ASC',
        'fields'  => 'ids',
    ]);

    $best_id = null;
    $best    = 0.0;
    foreach ($candidates as $img_id) {
        $img_title = get_the_title($img_id);
        // Score against word title and (lightly) against translation if present
        $s1 = ll_match_similarity($title, $img_title);
        $s2 = $alt ? (0.6 * ll_match_similarity($alt, $img_title)) : 0.0;
        $score = max($s1, $s2);
        if ($score > $best) { $best = $score; $best_id = $img_id; }
    }
    return ['image_post_id' => $best_id, 'score' => $best];
}

/**
 * Auto-assign only if confidence is high. Returns true if assigned.
 */
function ll_match_maybe_autoset_image($word_post_id, $term_id, $threshold = null) {
    $threshold = $threshold ?? apply_filters('ll_match_autothreshold', LL_MATCH_AUTOMATCH_THRESHOLD);

    // Skip if already has thumbnail
    if (has_post_thumbnail($word_post_id)) return false;

    $pair = ll_match_find_best_image_for_word($word_post_id, $term_id);
    if (!$pair['image_post_id']) return false;
    if ($pair['score'] < $threshold) return false;

    $attachment_id = get_post_thumbnail_id($pair['image_post_id']);
    if (!$attachment_id) return false;

    set_post_thumbnail($word_post_id, $attachment_id);
    return true;
}
