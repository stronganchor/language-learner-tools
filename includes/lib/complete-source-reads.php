<?php
if (!defined('WPINC')) { die; }

/** Refuse partial source snapshots and evict only caches touched by the read. */
function ll_tools_with_complete_source_reads(callable $read, callable $on_failure) {
    global $wpdb;
    $failed = false;
    $meta_ids = ['post' => [], 'term' => [], 'user' => []];
    $options = [];
    $relationships = [];
    $watch = static function (string $query) use (&$failed, $wpdb): string {
        // query runs before wpdb flushes the preceding error. The last query
        // is checked separately so success cannot mask an earlier failure.
        $failed = $failed || $wpdb->last_error !== '';
        return $query;
    };
    $post_meta = static function ($check, $ids) use (&$meta_ids) {
        foreach ((array) $ids as $id) { $meta_ids['post'][(int) $id] = true; }
        return $check;
    };
    $term_meta = static function ($check, $ids) use (&$meta_ids) {
        foreach ((array) $ids as $id) { $meta_ids['term'][(int) $id] = true; }
        return $check;
    };
    $user_meta = static function ($check, $ids) use (&$meta_ids) {
        foreach ((array) $ids as $id) { $meta_ids['user'][(int) $id] = true; }
        return $check;
    };
    $option_read = static function ($check, $option) use (&$options) {
        $options[(string) $option] = true;
        return $check;
    };
    $object_terms = static function ($args, $ids, $taxonomies) use (&$relationships) {
        foreach ((array) $taxonomies as $taxonomy) {
            foreach ((array) $ids as $id) { $relationships[(string) $taxonomy][(int) $id] = true; }
        }
        return $args;
    };
    $wpdb->last_error = '';
    add_filter('query', $watch, PHP_INT_MIN);
    add_filter('update_post_metadata_cache', $post_meta, PHP_INT_MIN, 2);
    add_filter('update_term_metadata_cache', $term_meta, PHP_INT_MIN, 2);
    add_filter('update_user_metadata_cache', $user_meta, PHP_INT_MIN, 2);
    add_filter('pre_option', $option_read, PHP_INT_MIN, 2);
    add_filter('wp_get_object_terms_args', $object_terms, PHP_INT_MIN, 3);
    $exception = null;
    $result = null;
    try {
        $result = $read();
    } catch (Throwable $error) {
        $exception = $error;
    } finally {
        $failed = $failed || $wpdb->last_error !== '';
        remove_filter('query', $watch, PHP_INT_MIN);
        remove_filter('update_post_metadata_cache', $post_meta, PHP_INT_MIN);
        remove_filter('update_term_metadata_cache', $term_meta, PHP_INT_MIN);
        remove_filter('update_user_metadata_cache', $user_meta, PHP_INT_MIN);
        remove_filter('pre_option', $option_read, PHP_INT_MIN);
        remove_filter('wp_get_object_terms_args', $object_terms, PHP_INT_MIN);
    }
    if ($failed || is_wp_error($result)) {
        // A source helper can report incompleteness after clearing last_error.
        // Its explicit error needs the same retry-cache repair as a SQL error.
        // Core can cache an empty query/meta/relationship result on SQL error.
        foreach ($meta_ids as $type => $ids) {
            foreach (array_keys($ids) as $id) { wp_cache_delete($id, $type . '_meta'); }
        }
        foreach ($relationships as $taxonomy => $ids) {
            foreach (array_keys($ids) as $id) { wp_cache_delete($id, $taxonomy . '_relationships'); }
        }
        if (!empty($options)) {
            foreach (array_keys($options) as $option) { wp_cache_delete($option, 'options'); }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        wp_cache_set_posts_last_changed();
        wp_cache_set_terms_last_changed();
        return $failed ? $on_failure() : $result;
    }
    if ($exception) { throw $exception; }
    return $result;
}
