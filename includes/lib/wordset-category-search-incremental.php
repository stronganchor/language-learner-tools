<?php
if (!defined('WPINC')) { die; }

/** Read source epochs again before publishing across a database write. */
function ll_tools_wordset_category_search_fresh_signature(int $wordset_id): string {
    global $wpdb;
    $names = [
        LL_TOOLS_WORDSET_CATEGORY_SEARCH_UNKNOWN_SOURCE_EPOCH_OPTION,
        LL_TOOLS_WORDSET_CATEGORY_SEARCH_FAILSAFE_SOURCE_EPOCH_OPTION,
        ll_tools_wordset_category_search_source_epoch_option($wordset_id),
    ];
    foreach ($names as $option_name) {
        ll_tools_wordset_category_search_reset_source_epoch_cache($option_name);
    }
    // A cached fallback is unsuitable as a publication fence. Read all three
    // epochs in one bounded statement and fail closed on a storage error.
    $wpdb->last_error = '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s)",
        ...$names
    ), ARRAY_A);
    if ($wpdb->last_error !== '') {
        return '';
    }
    $epochs = array_fill_keys($names, 1);
    foreach ((array) $rows as $row) {
        $epochs[$row['option_name']] = max(1, (int) $row['option_value']);
    }
    return hash('sha256', wp_json_encode([
        'schema' => LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION,
        'wordset_id' => max(0, $wordset_id),
        'source_epoch' => 'wcs1:f' . $epochs[$names[1]] . ':u' . $epochs[$names[0]] . ':w' . $epochs[$names[2]],
    ]));
}

/**
 * Invalidate cached results, then refresh only this word in a proven current index.
 *
 * The epoch advances before any row changes. Readers reject the old signature
 * and the held lease until all rows and the new signature have been published.
 * Cold/failed builds, incomplete scopes and concurrent changes retain the
 * existing bounded full-rebuild fallback. Hard deletion defers the row refresh
 * until deleted_post, after core has actually removed the source post.
 *
 * @return array<int,array{before:string,after:string,generation:string}>
 */
function ll_tools_wordset_category_search_record_word_change(
    int $word_id,
    array $scope,
    bool $defer = false
): array {
    $wordset_ids = array_values(array_unique(array_filter(array_map(
        'intval', (array) ($scope['wordset_ids'] ?? [])
    ), static function (int $id): bool { return $id > 0; })));
    $plans = [];
    if (!empty($scope['complete']) && count($wordset_ids) <= 10) {
        foreach ($wordset_ids as $wordset_id) {
            $before = ll_tools_wordset_category_search_fresh_signature($wordset_id);
            $state = ll_tools_get_wordset_category_search_state($wordset_id);
            if (!ll_tools_wordset_category_search_state_is_ready($wordset_id, $before, $state)) {
                continue;
            }
            $source = ll_tools_wordset_category_search_source_epoch_signature($wordset_id);
            if (!preg_match('/^wcs1:f([0-9]+):u([0-9]+):w([0-9]+)$/', $source, $parts)) {
                continue;
            }
            // Derive exactly one mutation's successor; never adopt an epoch
            // advanced by a concurrent edit whose rows we did not refresh.
            $next_epoch = (int) $parts[3] + 1;
            $plans[$wordset_id] = [
                'before' => $before,
                'after' => hash('sha256', wp_json_encode([
                    'schema' => LL_TOOLS_WORDSET_CATEGORY_SEARCH_TABLE_VERSION,
                    'wordset_id' => $wordset_id,
                    'source_epoch' => 'wcs1:f' . $parts[1] . ':u' . $parts[2] . ':w' . $next_epoch,
                ])),
                'generation' => (string) $state['generation'],
            ];
        }
    }
    ll_tools_wordset_category_search_invalidate_scope($scope);
    if (!$defer) {
        foreach ($plans as $wordset_id => $plan) {
            ll_tools_wordset_category_search_refresh_word($wordset_id, $word_id, $plan);
        }
    }
    return $plans;
}

/** Replace one word's bounded rows without exposing a partially changed index. */
function ll_tools_wordset_category_search_refresh_word(int $wordset_id, int $word_id, array $plan): bool {
    global $wpdb;
    if ($wordset_id <= 0 || $word_id <= 0
        || !hash_equals((string) $plan['after'], ll_tools_wordset_category_search_fresh_signature($wordset_id))) {
        return false;
    }
    $lock = ll_tools_acquire_wordset_category_search_lock($wordset_id);
    if (empty($lock['acquired'])) {
        return false;
    }
    try {
        wp_cache_delete(ll_tools_wordset_category_search_state_option($wordset_id), 'options');
        $state = ll_tools_get_wordset_category_search_state($wordset_id);
        $generation = (string) ($state['generation'] ?? '');
        if (!empty($lock['replaced']) || $state['status'] !== 'completed' || $generation === ''
            || !hash_equals($generation, (string) $plan['generation'])
            || !hash_equals($generation, (string) $state['published_generation'])
            || !hash_equals((string) $plan['before'], (string) $state['signature'])
            || !hash_equals((string) $plan['after'], ll_tools_wordset_category_search_fresh_signature($wordset_id))) {
            return false;
        }
        $complete = true;
        $words = ll_tools_wordset_category_search_get_word_batch($wordset_id, 0, 1, $complete, $word_id);
        if (!$complete) {
            return false;
        }
        $category_error = '';
        $category_map = ll_tools_wordset_category_search_get_category_map(
            empty($words) ? [] : [$word_id], $complete, $category_error
        );
        if (!$complete) {
            return false;
        }
        $rows = [];
        foreach ($words as $word) {
            $rows = ll_tools_wordset_category_search_build_word_rows(
                $wordset_id, $generation, $word, (array) ($category_map[$word_id] ?? []), $complete
            );
            if (!$complete) {
                return false;
            }
        }

        $table = ll_tools_wordset_category_search_table_name();
        $limit = ll_tools_wordset_category_search_categories_per_word_limit() + 1;
        $wpdb->last_error = '';
        $old_rows = $wpdb->get_col($wpdb->prepare(
            "SELECT category_id FROM {$table}
             WHERE wordset_id = %d AND generation = %s AND word_id = %d LIMIT %d",
            $wordset_id, $generation, $word_id, $limit
        ));
        if ($wpdb->last_error !== '' || count((array) $old_rows) >= $limit
            || !ll_tools_renew_wordset_category_search_lock($lock)
            || !hash_equals((string) $plan['after'], ll_tools_wordset_category_search_fresh_signature($wordset_id))) {
            return false;
        }
        $wpdb->last_error = '';
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE wordset_id = %d AND generation = %s AND word_id = %d LIMIT %d",
            $wordset_id, $generation, $word_id, $limit
        ));
        if ($deleted === false || $wpdb->last_error !== ''
            || !ll_tools_wordset_category_search_insert_rows($rows, $lock)
            || !hash_equals((string) $plan['after'], ll_tools_wordset_category_search_fresh_signature($wordset_id))) {
            // The old state signature remains invalid, including on MyISAM.
            // The already queued rebuild repairs any interrupted replacement.
            return false;
        }
        $state['signature'] = (string) $plan['after'];
        $state['incremental_updates'] = (int) ($state['incremental_updates'] ?? 0) + 1;
        $state['last_incremental_at'] = current_time('mysql', true);
        $updated = false;
        ll_tools_wordset_category_search_update_owned_state($wordset_id, $state, $generation, $lock, $updated);
        return $updated;
    } finally {
        ll_tools_release_wordset_category_search_lock($lock);
    }
}

/** Canonical normalization/projection shared by full and single-word updates. */
function ll_tools_wordset_category_search_build_word_rows(
    int $wordset_id,
    string $generation,
    array $word,
    array $category_ids,
    ?bool &$complete = null
): array {
    $category_ids = ll_tools_wordset_category_search_get_deepest_categories($category_ids, $complete);
    if (!$complete) {
        return [];
    }
    $title = ll_tools_wordset_category_search_cap_value((string) $word['title_value']);
    $translation = ll_tools_wordset_category_search_cap_value((string) $word['translation_value']);
    $title_normalized = ll_tools_wordset_category_search_normalize_value($title);
    $translation_normalized = ll_tools_wordset_category_search_normalize_value($translation);
    if ($title_normalized === '' && $translation_normalized === '') {
        return [];
    }
    $rows = [];
    foreach ($category_ids as $category_id) {
        $rows[] = [
            'wordset_id' => $wordset_id,
            'generation' => $generation,
            'category_id' => (int) $category_id,
            'word_id' => (int) $word['word_id'],
            'title_value' => $title,
            'translation_value' => $translation,
            'title_normalized' => $title_normalized,
            'translation_normalized' => $translation_normalized,
            'title_tokens' => ll_tools_wordset_category_search_token_value($title_normalized),
            'translation_tokens' => ll_tools_wordset_category_search_token_value($translation_normalized),
            'updated_at' => current_time('mysql', true),
        ];
    }
    return $rows;
}
