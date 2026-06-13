<?php
/**
 * WP-CLI eval-file helper for profiling wordset category search.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

$wordset_slug = getenv('LL_PERF_PROFILE_WORDSET_SLUG');
if (!is_string($wordset_slug) || trim($wordset_slug) === '') {
    $wordset_slug = 'll-perf-stress-2x';
}
$query = getenv('LL_PERF_PROFILE_SEARCH_QUERY');
if (!is_string($query) || trim($query) === '') {
    $query = 'LLPerf stress2x 01 01';
}

$wordset = get_term_by('slug', sanitize_title($wordset_slug), 'wordset');
if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
    fwrite(STDERR, "Missing wordset: {$wordset_slug}\n");
    exit(1);
}

$category_ids = get_terms([
    'taxonomy' => 'word-category',
    'hide_empty' => false,
    'fields' => 'ids',
    'meta_key' => defined('LL_TOOLS_PERF_FIXTURE_META_KEY') ? LL_TOOLS_PERF_FIXTURE_META_KEY : '_ll_tools_performance_fixture',
    'meta_value' => defined('LL_TOOLS_PERF_FIXTURE_KEY') ? LL_TOOLS_PERF_FIXTURE_KEY : 'll-tools-performance-benchmark',
]);
if (is_wp_error($category_ids)) {
    fwrite(STDERR, $category_ids->get_error_message() . "\n");
    exit(1);
}

$category_ids = array_values(array_filter(array_map('intval', (array) $category_ids), static function (int $id): bool {
    return $id > 0;
}));
sort($category_ids, SORT_NUMERIC);

$started = microtime(true);
$index = function_exists('ll_tools_get_wordset_page_category_search_index')
    ? ll_tools_get_wordset_page_category_search_index((int) $wordset->term_id, $category_ids)
    : [];
$index_ms = (int) round((microtime(true) - $started) * 1000);

$started = microtime(true);
$matches = function_exists('ll_tools_wordset_page_get_category_search_matches')
    ? ll_tools_wordset_page_get_category_search_matches((int) $wordset->term_id, $category_ids, (string) $query, 1)
    : [];
$match_ms = (int) round((microtime(true) - $started) * 1000);

echo wp_json_encode([
    'wordset_id' => (int) $wordset->term_id,
    'wordset_slug' => (string) $wordset->slug,
    'query' => (string) $query,
    'categories' => count($category_ids),
    'index_categories' => is_array($index) ? count($index) : 0,
    'matches' => is_array($matches) ? count($matches) : 0,
    'index_ms' => $index_ms,
    'match_ms' => $match_ms,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
