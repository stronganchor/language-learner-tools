<?php
if (!defined('WPINC')) { die; }

/** A separate publication/access generation keeps ordinary quiz edits off the homepage. */
function ll_tools_wordset_button_membership_epoch(bool $force = false): int {
    return ll_tools_read_option_epoch('ll_tools_wordset_button_membership_epoch', $force);
}

function ll_tools_wordset_button_membership_changed(): void {
    ll_tools_atomic_increment_option_epoch('ll_tools_wordset_button_membership_epoch');

    if (get_option('ll_tools_wordset_button_public_counts') && !has_action('shutdown', 'll_tools_wordset_button_schedule_public_snapshot')) {
        add_action('shutdown', 'll_tools_wordset_button_schedule_public_snapshot', 8);
    }
}

/** Include newly published wordsets and the final lesson metadata from this edit. */
function ll_tools_wordset_button_schedule_public_snapshot(): void {
    $user_id = get_current_user_id();
    try {
        wp_set_current_user(0);
        $terms = ll_tools_get_wordset_button_visible_terms(false, $complete);
        if ($complete) {
            ll_tools_schedule_wordset_button_count_refresh(wp_list_pluck($terms, 'term_id'), true);
        }
    } finally {
        wp_set_current_user($user_id);
    }
}

add_action('transition_post_status', static function ($new, $old, $post): void {
    if ($post->post_type === 'll_vocab_lesson' && $new !== $old && ($new === 'publish' || $old === 'publish')) {
        ll_tools_wordset_button_membership_changed();
    }
}, 30, 3);
add_action('deleted_post', static function ($id, $post): void {
    if ($post->post_type === 'll_vocab_lesson' && $post->post_status === 'publish') {
        ll_tools_wordset_button_membership_changed();
    }
}, 30, 2);

function ll_tools_wordset_button_lesson_meta_changed($meta_id, $post_id, $key): void {
    if (in_array($key, [LL_TOOLS_VOCAB_LESSON_WORDSET_META, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, LL_TOOLS_VOCAB_LESSON_PREVIEW_ONLY_META], true)
        && get_post_type($post_id) === 'll_vocab_lesson' && get_post_status($post_id) === 'publish') {
        ll_tools_wordset_button_membership_changed();
    }
}

function ll_tools_wordset_button_access_meta_changed($meta_id, $term_id, $key): void {
    if (in_array($key, [
        LL_TOOLS_CATEGORY_VISIBILITY_META_KEY, LL_TOOLS_CATEGORY_VISIBILITY_OVERRIDE_META_KEY,
        LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY, LL_TOOLS_CATEGORY_ISOLATION_SOURCE_META_KEY,
        LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, LL_TOOLS_WORDSET_VISIBILITY_META_KEY,
        LL_TOOLS_WORDSET_MANAGER_USER_IDS_META_KEY,
    ], true)) {
        ll_tools_wordset_button_membership_changed();
    }
}

foreach (['added', 'updated', 'deleted'] as $meta_action) {
    add_action($meta_action . '_post_meta', 'll_tools_wordset_button_lesson_meta_changed', 30, 3);
    add_action($meta_action . '_term_meta', 'll_tools_wordset_button_access_meta_changed', 30, 3);
}
add_action('delete_word-category', 'll_tools_wordset_button_membership_changed');
add_action('delete_wordset', 'll_tools_wordset_button_membership_changed');
add_action('update_option_ll_tools_wordset_isolation_enabled', 'll_tools_wordset_button_membership_changed');
add_action('add_option_ll_tools_wordset_isolation_enabled', 'll_tools_wordset_button_membership_changed');
add_action('delete_option_ll_tools_wordset_isolation_enabled', 'll_tools_wordset_button_membership_changed');

function ll_tools_wordset_button_snapshot_capabilities(): string {
    $caps = (array) wp_get_current_user()->allcaps;
    ksort($caps);
    return md5((string) wp_json_encode($caps));
}

/** Store only a complete, generation-fenced result. Use one slot per audience and site. */
function ll_tools_wordset_button_store_snapshot(array $ids, array $counts, int $minimum, string $generation): void {
    $epoch = ll_tools_wordset_button_membership_epoch(true);
    if ($generation !== ll_tools_wordset_button_counts_generation_key($ids, $minimum)) {
        return;
    }
    $user_id = get_current_user_id();
    $caps = ll_tools_wordset_button_snapshot_capabilities();
    $previous = $user_id ? get_user_option('ll_tools_wordset_button_counts', $user_id) : get_option('ll_tools_wordset_button_public_counts');
    // A worker for a subset must not evict the other wordsets from the homepage.
    if (is_array($previous) && ($previous['epoch'] ?? 0) === $epoch && ($previous['minimum'] ?? 0) === $minimum
        && ($previous['schema'] ?? 0) === ll_tools_wordset_button_count_builder_schema() && ($previous['caps'] ?? '') === $caps
        && is_array($previous['counts'] ?? null) && array_diff_key($previous['counts'], $counts)) {
        $counts += $previous['counts'];
        // The union must be checked as a whole before it can be published as a fresh generation.
        $generation = '';
    }
    $snapshot = [
        'epoch' => $epoch, 'minimum' => $minimum, 'schema' => ll_tools_wordset_button_count_builder_schema(),
        'caps' => $caps, 'generation' => $generation, 'counts' => $counts,
    ];
    if ($user_id) {
        update_user_option($user_id, 'll_tools_wordset_button_counts', $snapshot);
    } else {
        update_option('ll_tools_wordset_button_public_counts', $snapshot, false);
    }
}

/** Ordinary learners with no private category grants can reuse the public totals. */
function ll_tools_wordset_button_can_share_public_counts(array $terms): bool {
    global $wpdb;
    $uid = get_current_user_id();
    if (!$uid || current_user_can('manage_options')) {
        return !$uid;
    }
    foreach ($terms as $term) {
        if (ll_tools_is_wordset_private($term, $visibility_complete) || !$visibility_complete) {
            return false;
        }
    }
    // An installation with many custom grants keeps using its personal snapshots.
    $wpdb->last_error = '';
    $grants = $wpdb->get_col($wpdb->prepare(
        "SELECT LEFT(meta_value, 2049) FROM {$wpdb->termmeta} WHERE meta_key = %s LIMIT 33",
        LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY
    ));
    if (!is_array($grants) || $wpdb->last_error !== '' || count($grants) > 32) {
        return false;
    }
    foreach ($grants as $grant) {
        if (strlen($grant) > 2048 || in_array($uid, ll_tools_normalize_category_access_user_ids(maybe_unserialize($grant)), true)) {
            return false;
        }
    }
    return true;
}

/** Null means the bounded builder still has to establish a count for this scope. */
function ll_tools_wordset_button_snapshot_items(array $atts, bool &$current, int &$epoch): ?array {
    foreach (['ll_tools_category_visibility', 'll_tools_category_access_user_ids', 'll_tools_user_can_view_category',
        'll_tools_wordset_visibility', 'll_tools_user_can_view_wordset'] as $filter) {
        if (has_filter($filter)) {
            return null;
        }
    }
    $epoch = ll_tools_wordset_button_membership_epoch();
    $minimum = ll_tools_wordset_button_quiz_min_word_count();
    $schema = ll_tools_wordset_button_count_builder_schema();
    $uid = get_current_user_id();
    $snapshot = $uid ? get_user_option('ll_tools_wordset_button_counts', $uid) : false;
    $personal = is_array($snapshot) && ($snapshot['epoch'] ?? 0) === $epoch
        && ($snapshot['caps'] ?? '') === ll_tools_wordset_button_snapshot_capabilities();
    if (!$personal) {
        $snapshot = get_option('ll_tools_wordset_button_public_counts', null);
    }
    if (!is_array($snapshot) || ($snapshot['epoch'] ?? 0) !== $epoch || ($snapshot['minimum'] ?? 0) !== $minimum
        || ($snapshot['schema'] ?? 0) !== $schema || !is_string($snapshot['generation'] ?? null)
        || !is_array($snapshot['counts'] ?? null)) {
        return null;
    }
    $terms = ll_tools_get_wordset_button_visible_terms(ll_tools_wordset_buttons_shortcode_is_truthy($atts['hide_empty'] ?? '0'), $terms_complete);
    $ids = ll_tools_wordset_button_normalize_wordset_ids(wp_list_pluck($terms, 'term_id'));
    if (!$terms_complete || array_diff($ids, array_keys($snapshot['counts']))
        || (!$personal && $uid && !ll_tools_wordset_button_can_share_public_counts($terms))) {
        return null;
    }
    $generation = ll_tools_wordset_button_counts_generation_key(array_keys($snapshot['counts']), $minimum, $personal ? $uid : 0);
    $current = $generation === $snapshot['generation'];
    if (!$current) {
        // Keep the last confirmed count on screen while a quiz-content change is checked.
        ll_tools_schedule_wordset_button_count_refresh(array_keys($snapshot['counts']), true, 0, false, $personal ? $uid : 0);
    }
    return ll_tools_get_wordset_button_items_from_counts($terms, $snapshot['counts']);
}
