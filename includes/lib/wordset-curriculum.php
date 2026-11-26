<?php
if (!defined('WPINC')) { die; }

function ll_tools_wordset_curriculum_purge_public_pages($meta_id, int $wordset_id, string $meta_key): void {
    if ($wordset_id > 0 && in_array($meta_key, [
        'll_wordset_category_prerequisites', 'll_wordset_category_ordering_mode',
    ], true) && function_exists('ll_tools_purge_public_static_cache_once')) {
        ll_tools_purge_public_static_cache_once(['wordset_ids' => [$wordset_id]]);
    }
}
add_action('added_term_meta', 'll_tools_wordset_curriculum_purge_public_pages', 30, 3);
add_action('updated_term_meta', 'll_tools_wordset_curriculum_purge_public_pages', 30, 3);
add_action('deleted_term_meta', 'll_tools_wordset_curriculum_purge_public_pages', 30, 3);

function ll_tools_wordset_curriculum_read_error(): WP_Error {
    return new WP_Error('curriculum_read_incomplete', __('The curriculum could not be loaded. Please try again.', 'll-tools-text-domain'));
}

/**
 * Turkish Textbook publishes its ranked reference list as a playable category.
 * Keep the list in the ordinary catalog without counting it as a course lesson.
 *
 * @return bool|WP_Error
 */
function ll_tools_wordset_curriculum_is_turkish_reference(int $wordset_id, int $category_id) {
    if ($wordset_id !== 8062 || $category_id !== 8121) {
        return false;
    }
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    if (!in_array($host, ['www.turkishtextbook.com', 'turkishtextbook.com'], true)) {
        return false;
    }
    global $wpdb;
    $wpdb->last_error = '';
    $wordset = get_term($wordset_id, 'wordset');
    if ($wpdb->last_error !== '' || is_wp_error($wordset)) {
        return ll_tools_wordset_curriculum_read_error();
    }
    if (!($wordset instanceof WP_Term) || $wordset->slug !== 'vocab') {
        return false;
    }
    $category = get_term($category_id, 'word-category');
    if ($wpdb->last_error !== '' || is_wp_error($category)) {
        return ll_tools_wordset_curriculum_read_error();
    }
    return $category instanceof WP_Term && $category->slug === 'most-common-words-vocab';
}

/**
 * Compute depths on the saved wordset graph before projecting onto the caller's
 * authorized, active category IDs. No hidden category IDs, names or counts are
 * returned. Raw prerequisite depths in storage and existing APIs remain zero-based.
 *
 * @return array{enabled:bool,levels:array<int,int[]>}|WP_Error
 */
function ll_tools_wordset_curriculum_groups(int $wordset_id, array $visible_category_ids, ?array $assignments = null) {
    $disabled = ['enabled' => false, 'levels' => []];
    $visible_category_ids = ll_tools_wordset_normalize_category_id_list($visible_category_ids);
    if ($wordset_id <= 0 || !$visible_category_ids) {
        return $disabled;
    }
    $assignments = $assignments ?? ll_tools_wordset_curriculum_assignment_map($wordset_id);
    if (is_wp_error($assignments)) {
        return $assignments;
    }
    if (!$assignments) {
        return $disabled;
    }
    $groups = ['enabled' => true, 'levels' => []];
    foreach ($visible_category_ids as $category_id) {
        $reference = ll_tools_wordset_curriculum_is_turkish_reference($wordset_id, $category_id);
        if (is_wp_error($reference)) {
            return $reference;
        }
        if ($reference) {
            continue;
        }
        $level = $assignments[$category_id] ?? 1;
        $groups['levels'][$level][] = $category_id;
    }
    ksort($groups['levels'], SORT_NUMERIC);
    return $groups;
}

/** Unlisted categories belong to level one when the wordset has a prerequisite graph. */
function ll_tools_wordset_curriculum_assignment_map(int $wordset_id) {
    global $wpdb;
    $wpdb->last_error = '';
    $mode = ll_tools_wordset_get_category_ordering_mode($wordset_id);
    if ($wpdb->last_error !== '') {
        return ll_tools_wordset_curriculum_read_error();
    }
    if ($mode !== 'prerequisite') {
        return [];
    }

    $raw = get_term_meta($wordset_id, 'll_wordset_category_prerequisites', true);
    if ($wpdb->last_error !== '') {
        return ll_tools_wordset_curriculum_read_error();
    }
    $source_graph = ll_tools_wordset_normalize_category_prereq_map($raw);
    if (!$source_graph) {
        return [];
    }
    $graph = ll_tools_wordset_isolation_remap_prerequisite_map_for_wordset($source_graph, $wordset_id, false, $complete);
    if (!$complete || $wpdb->last_error !== '' || !$graph) {
        return ll_tools_wordset_curriculum_read_error();
    }
    $graph_nodes = [];
    foreach ($graph as $category_id => $dependencies) {
        $graph_nodes[$category_id] = true;
        foreach ($dependencies as $dependency_id) {
            $graph_nodes[$dependency_id] = true;
        }
    }
    $level_info = ll_tools_wordset_calculate_prereq_levels(array_keys($graph_nodes), $graph);
    if ($level_info['has_cycle']) {
        return [];
    }

    $assignments = [];
    foreach ($level_info['levels'] as $category_id => $depth) {
        $assignments[$category_id] = 1 + $depth;
    }
    return $assignments;
}
