<?php
if (!defined('ABSPATH')) { exit; }
/** Learner-specific level summaries. Callers supply the complete, access-checked public lesson map. */

function ll_tools_wordset_level_progress_error(): WP_Error {
    return new WP_Error('ll_level_progress_unavailable', __('Could not load level progress.', 'll-tools-text-domain'), ['status' => 503]);
}

function ll_tools_wordset_level_preparing(): WP_Error {
    return new WP_Error('ll_level_progress_preparing', __('Preparing level progress…', 'll-tools-text-domain'), ['status' => 503, 'retry_after' => 2]);
}

/** Local availability history is generated independently for each destination. */
function ll_tools_wordset_level_is_availability_meta(string $key): bool {
    return strpos($key, '_ll_level_') === 0;
}

// WordPress has no relationship creation date. Record future additions separately
// from edits so moving an old word into a lesson counts as an addition.
function ll_tools_wordset_level_note_assignment($object_id, $tt_id, $taxonomy): void {
    if (!in_array($taxonomy, ['wordset', 'word-category'], true)
        || !in_array(get_post_type($object_id), ['words', 'll_prompt_card'], true)) {
        return;
    }
    $term = get_term_by('term_taxonomy_id', (int) $tt_id, $taxonomy);
    if ($term instanceof WP_Term) {
        update_post_meta((int) $object_id, '_ll_level_added_' . $taxonomy . '_' . $term->term_id, gmdate('Y-m-d H:i:s'));
    }
}
add_action('added_term_relationship', 'll_tools_wordset_level_note_assignment', 10, 3);

function ll_tools_wordset_level_note_publication($new_status, $old_status, $post): void {
    if ($post instanceof WP_Post && in_array($post->post_type, ['words', 'll_prompt_card', 'll_vocab_lesson'], true)
        && $new_status === 'publish' && $old_status !== 'publish') {
        update_post_meta($post->ID, '_ll_level_published_at', gmdate('Y-m-d H:i:s'));
    }
}
add_action('transition_post_status', 'll_tools_wordset_level_note_publication', 10, 3);

function ll_tools_wordset_level_note_lesson_release($meta_ids, $post_id, $key, $value): void {
    static $was_preview = [];
    if (!defined('LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META') || $key !== LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META
        || get_post_type($post_id) !== 'll_vocab_lesson' || get_post_status($post_id) !== 'publish') {
        return;
    }
    if (in_array(current_filter(), ['update_post_meta', 'delete_post_meta'], true)) {
        $was_preview[$post_id] = ll_tools_vocab_lesson_is_preview_only((int) $post_id);
        return;
    }
    if (!empty($was_preview[$post_id]) && !ll_tools_vocab_lesson_is_preview_only((int) $post_id)) {
        update_post_meta((int) $post_id, '_ll_level_lesson_released_at', gmdate('Y-m-d H:i:s'));
    }
    unset($was_preview[$post_id]);
}
add_action('update_post_meta', 'll_tools_wordset_level_note_lesson_release', 10, 4);
add_action('delete_post_meta', 'll_tools_wordset_level_note_lesson_release', 10, 4);
add_action('updated_post_meta', 'll_tools_wordset_level_note_lesson_release', 10, 4);
add_action('deleted_post_meta', 'll_tools_wordset_level_note_lesson_release', 10, 4);

// The settings editor saves several metadata keys and may roll them back.
// Compare the first snapshot to the final graph, regardless of the entry point.
function ll_tools_wordset_level_before_graph_change($wordset_id, $key): void {
    if (!in_array($key, ['ll_wordset_category_prerequisites', 'll_wordset_category_ordering_mode'], true)
        || $wordset_id <= 0 || isset($GLOBALS['ll_tools_level_graph_snapshots'][$wordset_id])) {
        return;
    }
    $GLOBALS['ll_tools_level_graph_snapshots'][$wordset_id] = ll_tools_wordset_curriculum_assignment_map((int) $wordset_id);
}
add_action('add_term_meta', 'll_tools_wordset_level_before_graph_change', 10, 2);
function ll_tools_wordset_level_before_existing_graph_change($meta_ids, $wordset_id, $key): void {
    ll_tools_wordset_level_before_graph_change($wordset_id, $key);
}
add_action('update_term_meta', 'll_tools_wordset_level_before_existing_graph_change', 10, 3);
add_action('delete_term_meta', 'll_tools_wordset_level_before_existing_graph_change', 10, 3);

function ll_tools_wordset_level_record_graph_changes(): void {
    $snapshots = $GLOBALS['ll_tools_level_graph_snapshots'] ?? [];
    unset($GLOBALS['ll_tools_level_graph_snapshots']);
    foreach ($snapshots as $wordset_id => $before) {
        if (!is_array($before) || !$before) { continue; }
        $after = ll_tools_wordset_curriculum_assignment_map($wordset_id);
        if (!is_array($after) || !$after) { continue; }
        $date = gmdate('Y-m-d H:i:s');
        foreach (array_keys($before + $after) as $category_id) {
            $level = $after[$category_id] ?? 1;
            if ($level > 0 && $level !== ($before[$category_id] ?? 1)) {
                update_term_meta($category_id, '_ll_level_entry_' . $wordset_id, $level . '|' . $date);
            }
        }
    }
}
add_action('shutdown', 'll_tools_wordset_level_record_graph_changes', 40);

/** Read the last actual entry, scoped to its wordset and current level. */
function ll_tools_wordset_level_entry_dates(int $wordset_id, array $category_ids) {
    global $wpdb;
    $entries = [];
    foreach (array_chunk($category_ids, 200) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '%d'));
        $sql = "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key=%s AND term_id IN ($slots)";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge(['_ll_level_entry_' . $wordset_id], $chunk)), ARRAY_A);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
        foreach ($rows as $row) {
            $parts = explode('|', $row['meta_value'], 2);
            if (count($parts) === 2) {
                $entries[(int) $parts[0]][(int) $row['term_id']] = $parts[1];
            }
        }
    }
    return $entries;
}

/** A dated public vocabulary lesson can release existing words onto the level. */
function ll_tools_wordset_level_lesson_dates(int $wordset_id, array $category_ids) {
    global $wpdb;
    $catalog = ll_tools_wordset_page_get_published_vocab_lesson_category_map($wordset_id);
    if (empty($catalog['complete']) || !empty($catalog['stale'])) { return ll_tools_wordset_level_preparing(); }
    $lesson_map = array_intersect_key($catalog['map'], array_fill_keys($category_ids, true));
    $dates = [];
    foreach (array_chunk(array_unique(array_values($lesson_map)), 200) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.ID, GREATEST(p.post_date_gmt,
                COALESCE(MAX(m.meta_value),'0000-00-00 00:00:00')) AS released_at
            FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
                AND m.meta_key IN ('_ll_level_published_at','_ll_level_lesson_released_at')
            WHERE p.ID IN ($slots) AND p.post_type='ll_vocab_lesson' AND p.post_status='publish'
            GROUP BY p.ID,p.post_date_gmt", $chunk), ARRAY_A);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
        foreach ($rows as $row) {
            // MySQL may add six fractional digits to a mixed GREATEST()
            // result. Learner event and relationship timestamps use seconds.
            $dates[(int) $row['ID']] = substr($row['released_at'], 0, 19);
        }
    }
    $by_category = [];
    foreach ($lesson_map as $cid => $lesson_id) {
        if (isset($dates[$lesson_id])) { $by_category[$cid] = $dates[$lesson_id]; }
    }
    return $by_category;
}

/**
 * A published target may be a word or a prompt with answer-word tracking.
 * Multiple prompt forms of the same word count once; keep the earliest date when
 * any of its forms was available in this lesson. Standalone prompts are exercises.
 */
function ll_tools_wordset_level_project_targets(array $ids, int $wordset_id, int $category_id, array &$members, bool $materialized_targets = false) {
    global $wpdb;
    if (!$ids) { return true; }
    $slots = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "SELECT p.ID, p.post_type, GREATEST(p.post_date_gmt,
            COALESCE(MAX(m.meta_value), '0000-00-00 00:00:00')) AS available_at
        FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
            AND m.meta_key IN (%s,%s,%s)
        WHERE p.ID IN ($slots) AND p.post_status='publish' AND p.post_type IN ('words','ll_prompt_card')
        GROUP BY p.ID,p.post_type,p.post_date_gmt";
    $records = $wpdb->get_results($wpdb->prepare($sql, array_merge([
        '_ll_level_published_at', '_ll_level_added_wordset_' . $wordset_id, '_ll_level_added_word-category_' . $category_id,
    ], $ids)), ARRAY_A);
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    $words = [];
    $prompts = [];
    foreach ($records as $record) {
        if ($record['post_type'] === 'words') { $words[] = (int) $record['ID']; }
        else { $prompts[] = (int) $record['ID']; }
    }
    $complete = true;
    // The public flashcard worker already removes all distractors from target
    // sort groups. The ordinary renderable-items resolver can include them.
    $wrong = $materialized_targets ? [] : ll_tools_get_specific_wrong_answer_only_word_lookup($words, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $answers = ll_tools_user_progress_prompt_card_answer_word_id_map($prompts, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    foreach ($records as $record) {
        $id = (int) $record['ID'];
        if (isset($wrong[$id])) { continue; }
        $word = $record['post_type'] === 'words' ? $id : (int) ($answers[$id] ?? 0);
        if (!$word) { continue; }
        $date = substr((string) $record['available_at'], 0, 19);
        if (!isset($members[$word]) || $date < $members[$word]) { $members[$word] = $date; }
    }
    return true;
}

function ll_tools_wordset_level_member_key(int $wordset_id, int $category_id): string {
    return 'll_lvl_m4_' . md5(wp_json_encode([$wordset_id, $category_id, get_locale(),
        ll_tools_get_category_cache_versions([$category_id]), ll_tools_get_category_dependency_epoch(), ll_tools_get_wordset_cache_epoch(),
        ll_tools_get_category_versioned_content_epoch([$wordset_id], [$category_id]),
        (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ)]));
}

/**
 * Prime one bounded chunk of the authorized catalog. The single-category
 * reader still checks source and local visibility, the current viewer, and
 * expiration through WordPress. Cache keys and invalidation are identical.
 */
function ll_tools_wordset_level_prime_member_keys(int $wordset_id, array $category_ids, array &$object_cache) {
    global $wpdb;
    $wpdb->last_error = '';
    _prime_term_caches($category_ids, false);
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    update_meta_cache('term', $category_ids);
    if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    // The source of an isolated copy may impose stricter visibility.
    $sources = [];
    foreach ($category_ids as $category_id) {
        $source = (int) get_term_meta($category_id, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY, true);
        if ($source > 0 && $source !== $category_id) { $sources[$source] = $source; }
    }
    if ($sources) {
        update_meta_cache('term', $sources);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    }
    ll_tools_get_category_cache_versions($category_ids);
    $viewer = get_current_user_id();
    $keys = [];
    $transients = [];
    foreach ($category_ids as $category_id) {
        $key = ll_tools_wordset_level_member_key($wordset_id, $category_id);
        $keys[$category_id] = $key;
        $transients[] = $key;
        $transients[] = $key . '_u' . $viewer;
    }
    $object_cache = [];
    if (wp_using_ext_object_cache()) {
        $object_cache = wp_cache_get_multiple($transients, 'transient');
    } elseif (function_exists('wp_prime_option_caches')) {
        $options = [];
        foreach ($transients as $transient) {
            $options[] = '_transient_' . $transient;
            $options[] = '_transient_timeout_' . $transient;
        }
        wp_prime_option_caches($options);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
    }
    return $keys;
}

/**
 * Cache only curriculum membership and its availability dates, never a learner's
 * history. Small lessons use the standard renderable item resolver after proving
 * their raw input is bounded. Large lessons reuse the flashcard worker and read
 * compact IDs out of its completed generation without loading media or posts.
 */
function ll_tools_wordset_level_members(int $wordset_id, int $category_id, int &$cold_budget, ?string $key = null, array $object_cache = []) {
    global $wpdb;
    $complete = true;
    $category_private = ll_tools_is_category_private($category_id, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $wordset_private = ll_tools_is_wordset_private($wordset_id, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $public = !$category_private && !$wordset_private;
    $key = $key ?? ll_tools_wordset_level_member_key($wordset_id, $category_id);
    $personal_key = $key . '_u' . get_current_user_id();
    $cached = $public ? ($object_cache[$key] ?? get_transient($key)) : false;
    if (!is_array($cached)) { $cached = $object_cache[$personal_key] ?? get_transient($personal_key); }
    if (is_array($cached)) { return $cached; }
    if ($cold_budget <= 0) { return ll_tools_wordset_level_preparing(); }
    --$cold_budget;
    $complete = true;
    $raw_words = ll_tools_get_quiz_eligibility_raw_word_id_batch($category_id, [$wordset_id], 0, 49, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $raw_prompts = ll_tools_get_quiz_eligibility_prompt_card_id_batch($category_id, [$wordset_id], 0, 49, $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    $members = [];
    if (count($raw_words) + count($raw_prompts) <= 48) {
        $targets = ll_tools_user_study_renderable_word_ids_by_category([$category_id], $wordset_id, $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $primary_targets = array_intersect($targets[$category_id] ?? [], array_merge($raw_words, $raw_prompts));
        $ready = ll_tools_wordset_level_project_targets($primary_targets, $wordset_id, $category_id, $members);
        if (is_wp_error($ready)) { return $ready; }
        // Regular published words belonging to the public wordset and public
        // category share a scope. Small prompt lessons can reference words from
        // other scopes; keep that projection isolated for the viewing account.
        if (!$public || $raw_prompts) { $key = $personal_key; }
    } else {
        $term = get_term($category_id, 'word-category');
        if (!$term instanceof WP_Term) { return ll_tools_wordset_level_progress_error(); }
        $minimum = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
        $config = ll_tools_resolve_effective_category_quiz_config($term, $minimum, [$wordset_id], $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $scope = ll_tools_flashcard_payload_build_scope($term, [$wordset_id], $config);
        if (is_wp_error($scope)) { return $scope; }
        if (!$public || !empty($scope['viewer_user_id'])) { $key = $personal_key; }
        $ready = ll_tools_flashcard_payload_ensure_ready($scope);
        if (is_wp_error($ready)) { return $ready; }
        if (!$ready) { return ll_tools_wordset_level_preparing(); }
        $hash = ll_tools_flashcard_payload_scope_hash($scope);
        $state = ll_tools_get_flashcard_payload_state($hash);
        $generation = (string) ($state['published_generation'] ?? '');
        if ($generation === '' || $state['generation'] !== $generation) { return ll_tools_wordset_level_preparing(); }
        // Spend the remainder of the cold budget on this larger page.
        $cold_budget = 0;
        return ll_tools_wordset_level_project_generation($key, $hash, $generation, $wordset_id, $category_id);
    }
    set_transient($key, $members, DAY_IN_SECONDS);
    return $members;
}

/** Checkpoint one compact target page, retaining only complete published generations. */
function ll_tools_wordset_level_project_generation(string $key, string $hash, string $generation, int $wordset_id, int $category_id) {
    global $wpdb;
    $checkpoint_key = $key . '_p_' . substr($generation, 0, 20);
    if (!ll_tools_wordset_page_acquire_cache_rebuild_lock($checkpoint_key, 60)) {
        return ll_tools_wordset_level_preparing();
    }
    try {
        $cached = get_transient($key);
        if (is_array($cached)) { return $cached; }
        $checkpoint = get_transient($checkpoint_key);
        $members = is_array($checkpoint) ? $checkpoint['members'] : [];
        $cursor = is_array($checkpoint) ? (int) $checkpoint['cursor'] : 0;
        $group = (int) ($checkpoint['sort_group'] ?? 0);
        $table = ll_tools_flashcard_payload_table_name();
        $page = $wpdb->get_results($wpdb->prepare("SELECT sort_group, row_id FROM {$table}
            WHERE scope_hash=%s AND generation=%s AND sort_group<=1
                AND (sort_group>%d OR (sort_group=%d AND row_id>%d))
            ORDER BY sort_group,row_id LIMIT 250", $hash, $generation, $group, $group, $cursor), ARRAY_A);
        if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
        $ids = array_column($page, 'row_id');
        $step = ll_tools_wordset_level_project_targets($ids, $wordset_id, $category_id, $members, true);
        if (is_wp_error($step)) { return $step; }
        $latest = ll_tools_get_flashcard_payload_state($hash);
        if ($latest['generation'] !== $generation || $latest['published_generation'] !== $generation
            || !ll_tools_wordset_page_renew_cache_rebuild_lock($checkpoint_key, 60)) {
            return ll_tools_wordset_level_preparing();
        }
        if (count($ids) === 250) {
            $last = end($page);
            set_transient($checkpoint_key, ['cursor' => (int) $last['row_id'], 'sort_group' => (int) $last['sort_group'],
                'members' => $members], HOUR_IN_SECONDS);
            return ll_tools_wordset_level_preparing();
        }
        set_transient($key, $members, DAY_IN_SECONDS);
        delete_transient($checkpoint_key);
        return $members;
    } finally {
        ll_tools_wordset_page_release_cache_rebuild_lock($checkpoint_key);
    }
}

/** Most recent actual study in these lessons. Merely viewing the level does not move the baseline. */
function ll_tools_wordset_level_history(int $uid, int $wordset_id, array $category_ids) {
    global $wpdb;
    if ($uid <= 0 || !$category_ids) { return []; }
    $tables = ll_tools_user_progress_table_names();
    $history = [];
    foreach (array_chunk($category_ids, 200) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '%d'));
        $params = array_merge([$uid, $wordset_id], $chunk);
        $sources = [
            "SELECT category_id, MAX(created_at) AS last_seen FROM {$tables['events']}
            WHERE user_id=%d AND wordset_id=%d AND category_id IN ($slots)
              AND event_type IN ('word_exposure','word_outcome','mode_session_complete') GROUP BY category_id",
            "SELECT category_id, MAX(last_seen_at) AS last_seen FROM {$tables['words']}
            WHERE user_id=%d AND wordset_id=%d AND category_id IN ($slots) GROUP BY category_id",
        ];
        foreach ($sources as $sql) {
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
            if ($wpdb->last_error !== '') { return ll_tools_wordset_level_progress_error(); }
            foreach ($rows as $row) {
                $cid = (int) $row['category_id'];
                $history[$cid] = max((string) ($history[$cid] ?? ''), (string) $row['last_seen']);
            }
        }
    }
    // Session batches can include additional categories which have no dedicated
    // event row. The legacy category store also predates the durable event stream.
    $legacy = ll_tools_get_user_category_progress($uid);
    if (ll_tools_user_progress_get_source_error() instanceof WP_Error) { return ll_tools_wordset_level_progress_error(); }
    foreach ($category_ids as $cid) {
        $row = $legacy[$cid] ?? [];
        if ((int) ($row['wordset_id'] ?? 0) === $wordset_id && !empty($row['last_seen_at'])) {
            $history[$cid] = max((string) ($history[$cid] ?? ''), (string) $row['last_seen_at']);
        }
    }
    return $history;
}

function ll_tools_wordset_level_activity(string $mode, array $categories, array $ids = []): array {
    return ['mode' => $mode, 'category_ids' => array_values(array_unique(array_map('intval', $categories))),
        'session_word_ids' => array_values(array_map('intval', $ids))];
}

// Candidates are keyed by canonical word. Carry the exact category selected for
// each word in the bounded activity, including words available through prompts.
function ll_tools_wordset_level_practice_activity(array $candidates): ?array {
    if (!$candidates) { return null; }
    uasort($candidates, static function (array $left, array $right): int {
        return strcmp($left['date'], $right['date']) ?: ($left['id'] <=> $right['id']);
    });
    $selected = array_slice($candidates, 0, 12, true);
    $ownership = array_column($selected, 'category_id', 'id');
    $activity = ll_tools_wordset_level_activity('practice', array_values($ownership), array_keys($ownership));
    $activity['word_category_ids'] = $ownership;
    return $activity;
}

/**
 * Assemble a learner view from the compact curriculum projections. Only progress
 * summary columns are read, in bounded IN queries. Never load flashcards, media,
 * word posts, or learner timelines to draw the progress bars.
 */
function ll_tools_wordset_level_dashboard(int $wordset_id, int $user_id, array $level_categories, array $review_days_by_level = [], ?int $now = null) {
    $now = $now ?? time();
    $levels = [];
    $category_ids = [];
    foreach ($level_categories as $level => $rows) {
        $levels[$level] = [];
        foreach ((array) $rows as $row) {
            $row = is_array($row) ? $row : ['id' => (int) $row];
            $cid = (int) ($row['id'] ?? 0);
            if ($cid <= 0 || (isset($row['is_public']) && !$row['is_public'])) { continue; }
            $levels[$level][$cid] = $row;
            $category_ids[$cid] = $cid;
        }
    }
    $cold_budget = 8;
    $members = [];
    $all_ids = [];
    $preparing = false;
    foreach (array_chunk($category_ids, 100) as $chunk) {
        $object_cache = [];
        $keys = ll_tools_wordset_level_prime_member_keys($wordset_id, $chunk, $object_cache);
        if (is_wp_error($keys)) { return $keys; }
        foreach ($keys as $cid => $key) {
            $items = ll_tools_wordset_level_members($wordset_id, $cid, $cold_budget, $key, $object_cache);
            if (is_wp_error($items) && $items->get_error_code() === 'll_level_progress_preparing') {
                $preparing = true;
                if ($cold_budget <= 0) { return $items; }
                continue;
            }
            if (is_wp_error($items)) { return $items; }
            $members[$cid] = $items;
            $all_ids += $items;
        }
    }
    if ($preparing) { return ll_tools_wordset_level_preparing(); }
    $history = ll_tools_wordset_level_history($user_id, $wordset_id, array_values($category_ids));
    if (is_wp_error($history)) { return $history; }
    $releases = $history ? ll_tools_wordset_level_lesson_dates($wordset_id, array_values($category_ids)) : [];
    if (is_wp_error($releases)) { return $releases; }
    $entries = $history ? ll_tools_wordset_level_entry_dates($wordset_id, array_values($category_ids)) : [];
    if (is_wp_error($entries)) { return $entries; }
    foreach ($levels as $level => $categories) {
        foreach (array_intersect_key($entries[$level] ?? [], $categories) as $cid => $entry) {
            $levels[$level][$cid]['entered_at'] = $entry;
        }
    }
    $placement = [];
    if ($user_id > 0) {
        $goals = ll_tools_get_user_study_goals($user_id);
        if (ll_tools_user_progress_get_source_error() instanceof WP_Error) { return ll_tools_wordset_level_progress_error(); }
        $placement = array_fill_keys((array) ($goals['placement_known_category_ids'] ?? []), true);
    }
    $complete = true;
    $progress = ll_tools_get_user_word_progress_summary_rows($user_id, array_keys($all_ids), $complete);
    if (!$complete) { return ll_tools_wordset_level_progress_error(); }
    return ll_tools_wordset_level_summarize($wordset_id, $user_id, $levels, $members, $history, $progress, $review_days_by_level, $now, $placement, $releases);
}

function ll_tools_wordset_level_summarize(int $wordset_id, int $user_id, array $levels, array $members, array $history, array $progress, array $review_days_by_level, int $now, array $placement = [], array $releases = []) {
    $result = [];
    $overall_due = [];
    $now_mysql = gmdate('Y-m-d H:i:s', $now);
    foreach ($levels as $level => $categories) {
        $days = max(1, min(365, (int) ($review_days_by_level[$level] ?? 30)));
        $available = [];
        $practice_category = [];
        $next = 0;
        $partial = 0;
        $last = '';
        foreach ($categories as $cid => $category) {
            $entry = (string) ($category['entered_at'] ?? '');
            $seen = (string) ($history[$cid] ?? '');
            if ($seen >= $entry) { $last = max($last, $seen); }
            $has_new = false;
            $has_studied = false;
            foreach ($members[$cid] as $wid => $available_at) {
                $wid = (int) $wid;
                $available_at = max($available_at, (string) ($releases[$cid] ?? ''), $entry);
                if (!isset($available[$wid]) || $available_at < $available[$wid]) {
                    $available[$wid] = $available_at;
                }
                if (!isset($practice_category[$wid]) && (!isset($category['practice_supported']) || $category['practice_supported'])) {
                    $practice_category[$wid] = $cid;
                }
                if (isset($progress[$wid]) && ll_tools_user_progress_word_is_studied($progress[$wid])) {
                    $has_studied = true;
                } else {
                    $has_new = true;
                }
            }
            $learn_supported = !isset($placement[$cid]) && (!isset($category['learning_supported']) || $category['learning_supported']);
            // Exercise-only lessons may have no trackable answer words. Their
            // own category history still distinguishes a first visit.
            if ($learn_supported && !$next && empty($history[$cid]) && (!$has_studied || $has_new)) {
                $next = $cid;
            }
            if ($learn_supported && !$partial && $has_new) { $partial = $cid; }
        }
        $rows = array_intersect_key($progress, $available);
        $reviewable = array_intersect_key($rows, $practice_category);
        $complete = true;
        ll_tools_context_review_mark_rows($user_id, $wordset_id, $reviewable, array_keys($practice_category), $days, $now, $complete);
        if (!$complete) { return ll_tools_wordset_level_progress_error(); }
        $studied = 0;
        $mastered = 0;
        $added = 0;
        $practice = [];
        $due = [];
        foreach ($available as $wid => $available_at) {
            if ($last !== '' && $available_at > $last && $available_at <= $now_mysql) { ++$added; }
            $row = $rows[$wid] ?? [];
            if (!ll_tools_user_progress_word_is_studied($row)) { continue; }
            ++$studied;
            if (ll_tools_user_progress_word_is_mastered($row)) { ++$mastered; }
            if (!isset($practice_category[$wid])) { continue; }
            $row = $reviewable[$wid];
            $candidate = ['id' => $wid, 'category_id' => $practice_category[$wid], 'date' => ll_tools_context_review_last_studied_at($row)];
            $practice[$wid] = $candidate;
            if (ll_tools_context_review_word_due($row, $days, $now)) {
                $due[$wid] = $candidate;
                if (!isset($overall_due[$wid])) { $overall_due[$wid] = $candidate; }
            }
        }
        $practice_activity = ll_tools_wordset_level_practice_activity($practice);
        $due_activity = ll_tools_wordset_level_practice_activity($due);
        $result[$level] = ['total' => count($available), 'studied' => $studied, 'mastered' => $mastered,
            'working' => $studied - $mastered, 'new' => count($available) - $studied, 'due' => count($due),
            'added' => $added, 'last_studied_at' => $last, 'next_category_id' => $next ?: $partial,
            'practice_category_id' => $practice_activity['category_ids'][0] ?? 0,
            'due_category_id' => $due_activity['category_ids'][0] ?? 0,
            'practice_activity' => $practice_activity, 'due_activity' => $due_activity];
    }
    return ['levels' => $result, 'overall' => ['due' => count($overall_due), 'activity' => ll_tools_wordset_level_practice_activity($overall_due)]];
}

function ll_tools_wordset_level_progress(int $wordset_id, int $user_id, array $level_categories, array $review_days_by_level = [], ?int $now = null) {
    $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, $level_categories, $review_days_by_level, $now);
    return is_wp_error($dashboard) ? $dashboard : $dashboard['levels'];
}

function ll_tools_wordset_level_overall_review(int $wordset_id, int $user_id, array $level_categories, array $review_days_by_level = [], ?int $now = null) {
    $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, $level_categories, $review_days_by_level, $now);
    return is_wp_error($dashboard) ? $dashboard : $dashboard['overall'];
}

function ll_tools_wordset_level_launch(int $wordset_id, int $user_id, array $categories, int $review_days, string $action, ?int $now = null) {
    $dashboard = ll_tools_wordset_level_dashboard($wordset_id, $user_id, [$categories], [$review_days], $now);
    if (is_wp_error($dashboard)) { return $dashboard; }
    $row = $dashboard['levels'][0];
    if ($action === 'learn' && $row['next_category_id']) {
        return ll_tools_wordset_level_activity('learning', [$row['next_category_id']]);
    }
    if ($action === 'due' || $action === 'practice') { return $row[$action . '_activity']; }
    return null;
}
