<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')) {
    define('LL_TOOLS_PROMPT_CARD_POST_TYPE', 'll_prompt_card');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY', '_ll_prompt_card_prompt_text');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY', '_ll_prompt_card_prompt_audio_attachment_id');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY', '_ll_prompt_card_prompt_audio_url');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_BY_META_KEY', '_ll_prompt_card_prompt_audio_recorded_by');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_AT_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_RECORDED_AT_META_KEY', '_ll_prompt_card_prompt_audio_recorded_at');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_UPLOAD_SHA1_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_UPLOAD_SHA1_META_KEY', '_ll_prompt_card_prompt_audio_upload_sha1');
}
if (!defined('LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY', '_ll_prompt_card_prompt_image_word_id');
}
if (!defined('LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY', '_ll_prompt_card_correct_answer_word_id');
}
if (!defined('LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY', '_ll_prompt_card_wrong_answer_word_ids');
}
if (!defined('LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY')) {
    define('LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY', '_ll_prompt_card_track_answer_word_progress');
}

function ll_tools_register_prompt_card_post_type(): void {
    $labels = [
        'name'                  => __('Prompt Cards', 'll-tools-text-domain'),
        'singular_name'         => __('Prompt Card', 'll-tools-text-domain'),
        'menu_name'             => __('Prompt Cards', 'll-tools-text-domain'),
        'name_admin_bar'        => __('Prompt Card', 'll-tools-text-domain'),
        'add_new'               => __('Add Prompt Card', 'll-tools-text-domain'),
        'add_new_item'          => __('Add New Prompt Card', 'll-tools-text-domain'),
        'edit_item'             => __('Edit Prompt Card', 'll-tools-text-domain'),
        'new_item'              => __('New Prompt Card', 'll-tools-text-domain'),
        'view_item'             => __('View Prompt Card', 'll-tools-text-domain'),
        'search_items'          => __('Search Prompt Cards', 'll-tools-text-domain'),
        'not_found'             => __('No prompt cards found.', 'll-tools-text-domain'),
        'not_found_in_trash'    => __('No prompt cards found in Trash.', 'll-tools-text-domain'),
        'all_items'             => __('Prompt Cards', 'll-tools-text-domain'),
        'item_published'        => __('Prompt card published.', 'll-tools-text-domain'),
        'item_updated'          => __('Prompt card updated.', 'll-tools-text-domain'),
    ];

    $args = [
        'labels'              => $labels,
        'public'              => false,
        'publicly_queryable'  => false,
        'show_ui'             => true,
        'show_in_menu'        => 'edit.php?post_type=words',
        'show_in_nav_menus'   => false,
        'show_in_rest'        => true,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => 'll_prompt_card',
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'supports'            => ['title'],
        'menu_position'       => null,
    ];

    register_post_type(LL_TOOLS_PROMPT_CARD_POST_TYPE, $args);
}
add_action('init', 'll_tools_register_prompt_card_post_type', 0);

function ll_tools_is_valid_prompt_card_word_id($word_id): bool {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return false;
    }

    $post = get_post($word_id);
    return $post instanceof WP_Post && $post->post_type === 'words';
}

function ll_tools_normalize_prompt_card_word_ids($raw_ids, array $exclude_ids = []): array {
    $exclude_lookup = [];
    foreach ($exclude_ids as $exclude_id) {
        $exclude_id = (int) $exclude_id;
        if ($exclude_id > 0) {
            $exclude_lookup[$exclude_id] = true;
        }
    }

    if (is_string($raw_ids)) {
        $raw_ids = preg_split('/[\s,]+/', $raw_ids);
    }

    $normalized = [];
    $seen = [];
    foreach ((array) $raw_ids as $value) {
        $word_id = (int) $value;
        if ($word_id <= 0 || isset($exclude_lookup[$word_id]) || isset($seen[$word_id])) {
            continue;
        }
        if (!ll_tools_is_valid_prompt_card_word_id($word_id)) {
            continue;
        }
        $seen[$word_id] = true;
        $normalized[] = $word_id;
    }

    return $normalized;
}

function ll_tools_normalize_prompt_card_audio_attachment_id($attachment_id): int {
    $attachment_id = absint($attachment_id);
    if ($attachment_id <= 0) {
        return 0;
    }

    $attachment = get_post($attachment_id);
    if (!($attachment instanceof WP_Post) || $attachment->post_type !== 'attachment') {
        return 0;
    }

    $mime_type = (string) $attachment->post_mime_type;
    if ($mime_type !== '' && strpos($mime_type, 'audio/') !== 0) {
        return 0;
    }

    return $attachment_id;
}

function ll_tools_prompt_card_resolve_prompt_audio_url_from_meta($attachment_id, $stored_url): string {
    $attachment_id = ll_tools_normalize_prompt_card_audio_attachment_id($attachment_id);
    if ($attachment_id > 0) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (is_string($attachment_url) && $attachment_url !== '') {
            return $attachment_url;
        }
    }

    $url = trim((string) $stored_url);
    return $url !== '' ? esc_url_raw($url) : '';
}

function ll_tools_get_prompt_card_prompt_audio_url(int $post_id): string {
    return ll_tools_prompt_card_resolve_prompt_audio_url_from_meta(
        get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, true),
        get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, true)
    );
}

function ll_tools_prompt_card_needs_prompt_audio(int $post_id): bool {
    $post = get_post($post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return false;
    }

    return ll_tools_get_prompt_card_prompt_audio_url($post_id) === '';
}

function ll_tools_prompt_card_tracks_answer_word_progress(int $post_id): bool {
    if (!metadata_exists('post', $post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY)) {
        return true;
    }

    $raw = get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, true);
    return !empty($raw);
}

function ll_tools_get_prompt_card_data(int $post_id): array {
    $post = get_post($post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return [];
    }

    $correct_answer_word_id = (int) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, true);
    if (!ll_tools_is_valid_prompt_card_word_id($correct_answer_word_id)) {
        $correct_answer_word_id = 0;
    }

    $prompt_image_word_id = (int) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, true);
    if (!ll_tools_is_valid_prompt_card_word_id($prompt_image_word_id)) {
        $prompt_image_word_id = $correct_answer_word_id;
    }

    $wrong_answer_word_ids = ll_tools_normalize_prompt_card_word_ids(
        get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, true),
        [$correct_answer_word_id]
    );

    return [
        'id' => $post_id,
        'title' => (string) $post->post_title,
        'prompt_text' => sanitize_textarea_field((string) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY, true)),
        'prompt_audio_attachment_id' => ll_tools_normalize_prompt_card_audio_attachment_id(
            get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, true)
        ),
        'prompt_audio_url' => ll_tools_get_prompt_card_prompt_audio_url($post_id),
        'prompt_image_word_id' => max(0, $prompt_image_word_id),
        'correct_answer_word_id' => max(0, $correct_answer_word_id),
        'wrong_answer_word_ids' => $wrong_answer_word_ids,
        'track_answer_word_progress' => ll_tools_prompt_card_tracks_answer_word_progress($post_id),
    ];
}

function ll_tools_get_prompt_card_query_args_for_category_context(array $category_context, array $wordset_terms = [], array $overrides = []): array {
    $args = [
        'post_type'              => LL_TOOLS_PROMPT_CARD_POST_TYPE,
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'orderby'                => 'menu_order title',
        'order'                  => 'ASC',
        'suppress_filters'       => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        'tax_query'              => [[
            'taxonomy' => 'word-category',
            'field'    => (string) ($category_context['query_field'] ?? 'name'),
            'terms'    => $category_context['query_terms'] ?? '',
        ]],
        'no_found_rows'          => true,
    ];

    $wordset_terms = array_values(array_filter(array_map('intval', $wordset_terms), static function (int $wordset_id): bool {
        return $wordset_id > 0;
    }));
    if (!empty($wordset_terms)) {
        $args['tax_query'][] = [
            'taxonomy' => 'wordset',
            'field'    => 'term_id',
            'terms'    => $wordset_terms,
        ];
        $args['tax_query']['relation'] = 'AND';
    }

    foreach ($overrides as $key => $value) {
        $args[$key] = $value;
    }

    return $args;
}

function ll_tools_get_prompt_card_posts_for_category_context(array $category_context, array $wordset_terms = []): array {
    $args = ll_tools_get_prompt_card_query_args_for_category_context($category_context, $wordset_terms);
    $posts = get_posts($args);
    return array_values(array_filter($posts, static function ($post): bool {
        return $post instanceof WP_Post;
    }));
}

function ll_tools_get_prompt_card_ids_for_category_context(array $category_context, array $wordset_terms = []): array {
    $args = ll_tools_get_prompt_card_query_args_for_category_context($category_context, $wordset_terms, [
        'fields'                 => 'ids',
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ]);

    return array_values(array_filter(array_map('intval', (array) get_posts($args)), static function (int $post_id): bool {
        return $post_id > 0;
    }));
}

function ll_tools_extract_prompt_card_word_ids($raw_ids): array {
    if (is_string($raw_ids)) {
        $raw_ids = preg_split('/[\s,]+/', $raw_ids);
    }

    $ids = [];
    $seen = [];
    foreach ((array) $raw_ids as $value) {
        $word_id = (int) $value;
        if ($word_id <= 0 || isset($seen[$word_id])) {
            continue;
        }

        $seen[$word_id] = true;
        $ids[] = $word_id;
    }

    return $ids;
}

function ll_tools_filter_prompt_card_word_ids_with_lookup($raw_ids, array $valid_word_lookup, array $exclude_ids = []): array {
    $exclude_lookup = [];
    foreach ($exclude_ids as $exclude_id) {
        $exclude_id = (int) $exclude_id;
        if ($exclude_id > 0) {
            $exclude_lookup[$exclude_id] = true;
        }
    }

    $ids = [];
    $seen = [];
    foreach (ll_tools_extract_prompt_card_word_ids($raw_ids) as $word_id) {
        if (isset($seen[$word_id]) || isset($exclude_lookup[$word_id]) || empty($valid_word_lookup[$word_id])) {
            continue;
        }

        $seen[$word_id] = true;
        $ids[] = $word_id;
    }

    return $ids;
}

function ll_tools_get_prompt_card_valid_word_lookup(array $word_ids): array {
    global $wpdb;

    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }

    $lookup = [];
    foreach (array_chunk($word_ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $rows = $wpdb->get_col($wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
              AND post_type = %s
            ",
            array_merge($chunk, ['words'])
        ));
        foreach ((array) $rows as $word_id) {
            $word_id = (int) $word_id;
            if ($word_id > 0) {
                $lookup[$word_id] = true;
            }
        }
    }

    return $lookup;
}

function ll_tools_get_prompt_card_meta_map(array $prompt_card_ids, array $meta_keys): array {
    global $wpdb;

    $prompt_card_ids = array_values(array_unique(array_filter(array_map('intval', $prompt_card_ids), static function (int $post_id): bool {
        return $post_id > 0;
    })));
    $meta_keys = array_values(array_unique(array_filter(array_map('strval', $meta_keys), static function (string $meta_key): bool {
        return $meta_key !== '';
    })));
    if (empty($prompt_card_ids) || empty($meta_keys)) {
        return [];
    }

    $map = [];
    foreach (array_chunk($prompt_card_ids, 500) as $chunk) {
        $id_placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "
            SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE post_id IN ({$id_placeholders})
              AND meta_key IN ({$key_placeholders})
            ",
            array_merge($chunk, $meta_keys)
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $post_id = isset($row['post_id']) ? (int) $row['post_id'] : 0;
            $meta_key = isset($row['meta_key']) ? (string) $row['meta_key'] : '';
            if ($post_id <= 0 || $meta_key === '') {
                continue;
            }
            if (!isset($map[$post_id])) {
                $map[$post_id] = [];
            }
            $map[$post_id][$meta_key] = maybe_unserialize($row['meta_value'] ?? '');
        }
    }

    return $map;
}

function ll_tools_get_prompt_card_data_for_ids(array $prompt_card_ids, bool $include_prompt_audio_url = true): array {
    global $wpdb;

    $prompt_card_ids = array_values(array_unique(array_filter(array_map('intval', $prompt_card_ids), static function (int $post_id): bool {
        return $post_id > 0;
    })));
    if (empty($prompt_card_ids)) {
        return [];
    }

    $titles_by_id = [];
    foreach (array_chunk($prompt_card_ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "
            SELECT ID, post_title
            FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
              AND post_type = %s
              AND post_status = %s
            ",
            array_merge($chunk, [LL_TOOLS_PROMPT_CARD_POST_TYPE, 'publish'])
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $post_id = isset($row['ID']) ? (int) $row['ID'] : 0;
            if ($post_id > 0) {
                $titles_by_id[$post_id] = (string) ($row['post_title'] ?? '');
            }
        }
    }

    $prompt_card_ids = array_values(array_filter($prompt_card_ids, static function (int $post_id) use ($titles_by_id): bool {
        return isset($titles_by_id[$post_id]);
    }));
    if (empty($prompt_card_ids)) {
        return [];
    }

    $meta_keys = [
        LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY,
        LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY,
        LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY,
        LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY,
        LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY,
    ];
    if ($include_prompt_audio_url) {
        $meta_keys[] = LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY;
        $meta_keys[] = LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY;
    }
    $meta_by_card = ll_tools_get_prompt_card_meta_map($prompt_card_ids, $meta_keys);

    $raw_by_card = [];
    $referenced_word_ids = [];
    foreach ($prompt_card_ids as $prompt_card_id) {
        $meta = isset($meta_by_card[$prompt_card_id]) && is_array($meta_by_card[$prompt_card_id])
            ? $meta_by_card[$prompt_card_id]
            : [];
        $correct_answer_word_id = (int) ($meta[LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY] ?? 0);
        $prompt_image_word_id = (int) ($meta[LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY] ?? 0);
        $wrong_answer_word_ids = ll_tools_extract_prompt_card_word_ids($meta[LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY] ?? []);

        $raw_by_card[$prompt_card_id] = [
            'correct_answer_word_id' => $correct_answer_word_id,
            'prompt_image_word_id' => $prompt_image_word_id,
            'wrong_answer_word_ids' => $wrong_answer_word_ids,
        ];
        if ($correct_answer_word_id > 0) {
            $referenced_word_ids[] = $correct_answer_word_id;
        }
        if ($prompt_image_word_id > 0) {
            $referenced_word_ids[] = $prompt_image_word_id;
        }
        foreach ($wrong_answer_word_ids as $wrong_answer_word_id) {
            $referenced_word_ids[] = (int) $wrong_answer_word_id;
        }
    }

    $valid_word_lookup = ll_tools_get_prompt_card_valid_word_lookup($referenced_word_ids);

    $cards = [];
    foreach ($prompt_card_ids as $prompt_card_id) {
        $raw = isset($raw_by_card[$prompt_card_id]) && is_array($raw_by_card[$prompt_card_id])
            ? $raw_by_card[$prompt_card_id]
            : [];
        $meta = isset($meta_by_card[$prompt_card_id]) && is_array($meta_by_card[$prompt_card_id])
            ? $meta_by_card[$prompt_card_id]
            : [];
        $correct_answer_word_id = isset($raw['correct_answer_word_id']) ? (int) $raw['correct_answer_word_id'] : 0;
        if ($correct_answer_word_id <= 0 || empty($valid_word_lookup[$correct_answer_word_id])) {
            $correct_answer_word_id = 0;
        }

        $prompt_image_word_id = isset($raw['prompt_image_word_id']) ? (int) $raw['prompt_image_word_id'] : 0;
        if ($prompt_image_word_id <= 0 || empty($valid_word_lookup[$prompt_image_word_id])) {
            $prompt_image_word_id = $correct_answer_word_id;
        }

        $cards[] = [
            'id' => $prompt_card_id,
            'title' => (string) ($titles_by_id[$prompt_card_id] ?? ''),
            'prompt_text' => sanitize_textarea_field((string) ($meta[LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY] ?? '')),
            'prompt_audio_attachment_id' => $include_prompt_audio_url
                ? ll_tools_normalize_prompt_card_audio_attachment_id($meta[LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY] ?? 0)
                : 0,
            'prompt_audio_url' => $include_prompt_audio_url
                ? ll_tools_prompt_card_resolve_prompt_audio_url_from_meta(
                    $meta[LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY] ?? 0,
                    $meta[LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY] ?? ''
                )
                : '',
            'prompt_image_word_id' => max(0, $prompt_image_word_id),
            'correct_answer_word_id' => max(0, $correct_answer_word_id),
            'wrong_answer_word_ids' => ll_tools_filter_prompt_card_word_ids_with_lookup(
                $raw['wrong_answer_word_ids'] ?? [],
                $valid_word_lookup,
                [$correct_answer_word_id]
            ),
            'track_answer_word_progress' => !array_key_exists(LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, $meta)
                ? true
                : !empty($meta[LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY]),
        ];
    }

    return $cards;
}

function ll_tools_get_prompt_card_reference_data_for_ids(array $prompt_card_ids, bool $include_prompt_audio_url = true): array {
    global $wpdb;

    $prompt_card_ids = array_values(array_unique(array_filter(array_map('intval', $prompt_card_ids), static function (int $post_id): bool {
        return $post_id > 0;
    })));
    if (empty($prompt_card_ids)) {
        return [];
    }

    $published_lookup = [];
    foreach (array_chunk($prompt_card_ids, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
        $rows = $wpdb->get_col($wpdb->prepare(
            "
            SELECT ID
            FROM {$wpdb->posts}
            WHERE ID IN ({$placeholders})
              AND post_type = %s
              AND post_status = %s
            ",
            array_merge($chunk, [LL_TOOLS_PROMPT_CARD_POST_TYPE, 'publish'])
        ));
        foreach ((array) $rows as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $published_lookup[$post_id] = true;
            }
        }
    }

    $prompt_card_ids = array_values(array_filter($prompt_card_ids, static function (int $post_id) use ($published_lookup): bool {
        return !empty($published_lookup[$post_id]);
    }));
    if (empty($prompt_card_ids)) {
        return [];
    }

    $meta_keys = [
        LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY,
        LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY,
        LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY,
    ];
    if ($include_prompt_audio_url) {
        $meta_keys[] = LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY;
        $meta_keys[] = LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY;
    }
    $meta_by_card = ll_tools_get_prompt_card_meta_map($prompt_card_ids, $meta_keys);

    $raw_by_card = [];
    $referenced_word_ids = [];
    foreach ($prompt_card_ids as $prompt_card_id) {
        $meta = isset($meta_by_card[$prompt_card_id]) && is_array($meta_by_card[$prompt_card_id])
            ? $meta_by_card[$prompt_card_id]
            : [];
        $correct_answer_word_id = (int) ($meta[LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY] ?? 0);
        $prompt_image_word_id = (int) ($meta[LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY] ?? 0);
        $wrong_answer_word_ids = ll_tools_extract_prompt_card_word_ids($meta[LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY] ?? []);

        $raw_by_card[$prompt_card_id] = [
            'correct_answer_word_id' => $correct_answer_word_id,
            'prompt_image_word_id' => $prompt_image_word_id,
            'wrong_answer_word_ids' => $wrong_answer_word_ids,
        ];
        if ($correct_answer_word_id > 0) {
            $referenced_word_ids[] = $correct_answer_word_id;
        }
        if ($prompt_image_word_id > 0) {
            $referenced_word_ids[] = $prompt_image_word_id;
        }
        foreach ($wrong_answer_word_ids as $wrong_answer_word_id) {
            $referenced_word_ids[] = (int) $wrong_answer_word_id;
        }
    }

    $valid_word_lookup = ll_tools_get_prompt_card_valid_word_lookup($referenced_word_ids);

    $cards = [];
    foreach ($prompt_card_ids as $prompt_card_id) {
        $raw = isset($raw_by_card[$prompt_card_id]) && is_array($raw_by_card[$prompt_card_id])
            ? $raw_by_card[$prompt_card_id]
            : [];
        $meta = isset($meta_by_card[$prompt_card_id]) && is_array($meta_by_card[$prompt_card_id])
            ? $meta_by_card[$prompt_card_id]
            : [];
        $correct_answer_word_id = isset($raw['correct_answer_word_id']) ? (int) $raw['correct_answer_word_id'] : 0;
        if ($correct_answer_word_id <= 0 || empty($valid_word_lookup[$correct_answer_word_id])) {
            $correct_answer_word_id = 0;
        }

        $prompt_image_word_id = isset($raw['prompt_image_word_id']) ? (int) $raw['prompt_image_word_id'] : 0;
        if ($prompt_image_word_id <= 0 || empty($valid_word_lookup[$prompt_image_word_id])) {
            $prompt_image_word_id = $correct_answer_word_id;
        }

        $card = [
            'id' => $prompt_card_id,
            'prompt_image_word_id' => max(0, $prompt_image_word_id),
            'correct_answer_word_id' => max(0, $correct_answer_word_id),
            'wrong_answer_word_ids' => ll_tools_filter_prompt_card_word_ids_with_lookup(
                $raw['wrong_answer_word_ids'] ?? [],
                $valid_word_lookup,
                [$correct_answer_word_id]
            ),
        ];
        if ($include_prompt_audio_url) {
            $card['prompt_audio_url'] = ll_tools_prompt_card_resolve_prompt_audio_url_from_meta(
                $meta[LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY] ?? 0,
                $meta[LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY] ?? ''
            );
        }

        $cards[] = $card;
    }

    return $cards;
}

function ll_tools_get_prompt_card_data_for_category_context(array $category_context, array $wordset_terms = [], bool $include_prompt_audio_url = true): array {
    return ll_tools_get_prompt_card_data_for_ids(
        ll_tools_get_prompt_card_ids_for_category_context($category_context, $wordset_terms),
        $include_prompt_audio_url
    );
}

function ll_tools_get_prompt_card_reference_data_for_category_context(array $category_context, array $wordset_terms = [], bool $include_prompt_audio_url = true): array {
    return ll_tools_get_prompt_card_reference_data_for_ids(
        ll_tools_get_prompt_card_ids_for_category_context($category_context, $wordset_terms),
        $include_prompt_audio_url
    );
}

function ll_tools_prompt_card_get_category_ids_for_word_references(array $word_ids): array {
    $word_ids = array_values(array_unique(array_filter(array_map('intval', $word_ids), static function (int $word_id): bool {
        return $word_id > 0;
    })));
    if (empty($word_ids)) {
        return [];
    }

    $meta_query = [
        'relation' => 'OR',
        [
            'key'     => LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY,
            'value'   => array_map('strval', $word_ids),
            'compare' => 'IN',
        ],
        [
            'key'     => LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY,
            'value'   => array_map('strval', $word_ids),
            'compare' => 'IN',
        ],
    ];

    foreach ($word_ids as $word_id) {
        $meta_query[] = [
            'key'     => LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY,
            'value'   => 'i:' . $word_id . ';',
            'compare' => 'LIKE',
        ];
        $meta_query[] = [
            'key'     => LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY,
            'value'   => '"' . $word_id . '"',
            'compare' => 'LIKE',
        ];
    }

    $prompt_card_ids = get_posts([
        'post_type'              => LL_TOOLS_PROMPT_CARD_POST_TYPE,
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'suppress_filters'       => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'meta_query'             => $meta_query,
    ]);
    $prompt_card_ids = array_values(array_filter(array_map('intval', (array) $prompt_card_ids), static function (int $post_id): bool {
        return $post_id > 0;
    }));
    if (empty($prompt_card_ids)) {
        return [];
    }

    $terms = wp_get_object_terms($prompt_card_ids, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($terms)) {
        return [];
    }

    $category_ids = array_values(array_unique(array_filter(array_map('intval', (array) $terms), static function (int $term_id): bool {
        return $term_id > 0;
    })));
    sort($category_ids, SORT_NUMERIC);
    return $category_ids;
}

function ll_tools_prompt_card_get_word_category_term_ids(int $post_id): array {
    $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', (array) $term_ids), static function (int $term_id): bool {
        return $term_id > 0;
    })));
}

function ll_tools_prompt_card_term_taxonomy_ids_to_term_ids(array $term_taxonomy_ids, string $taxonomy): array {
    global $wpdb;

    $term_taxonomy_ids = array_values(array_unique(array_filter(array_map('intval', $term_taxonomy_ids), static function (int $term_taxonomy_id): bool {
        return $term_taxonomy_id > 0;
    })));
    if (empty($term_taxonomy_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($term_taxonomy_ids), '%d'));
    $sql = $wpdb->prepare(
        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_taxonomy_id IN ({$placeholders})",
        array_merge([$taxonomy], $term_taxonomy_ids)
    );

    return array_values(array_unique(array_filter(array_map('intval', (array) $wpdb->get_col($sql)), static function (int $term_id): bool {
        return $term_id > 0;
    })));
}

function ll_tools_prompt_card_invalidate_category_caches(int $post_id, array $extra_category_ids = []): array {
    $category_ids = array_merge(ll_tools_prompt_card_get_word_category_term_ids($post_id), $extra_category_ids);
    $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids), static function (int $term_id): bool {
        return $term_id > 0;
    })));

    if (!empty($category_ids) && function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version($category_ids);
        return $category_ids;
    }

    if (function_exists('ll_tools_bump_category_cache_epoch')) {
        ll_tools_bump_category_cache_epoch();
    }

    return [];
}

function ll_tools_update_prompt_card_configuration(int $post_id, array $fields): array {
    $changed_keys = [];

    $set_scalar_meta = static function (string $field_key, string $meta_key, $value) use ($post_id, &$changed_keys): void {
        $value = is_scalar($value) ? (string) $value : '';
        $before = (string) get_post_meta($post_id, $meta_key, true);
        if ($value !== '') {
            update_post_meta($post_id, $meta_key, $value);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
        $after = (string) get_post_meta($post_id, $meta_key, true);
        if ($after !== $before) {
            $changed_keys[] = $field_key;
        }
    };

    $set_int_meta = static function (string $field_key, string $meta_key, int $value) use ($post_id, &$changed_keys): void {
        $before = (int) get_post_meta($post_id, $meta_key, true);
        if ($value > 0) {
            update_post_meta($post_id, $meta_key, $value);
        } else {
            delete_post_meta($post_id, $meta_key);
        }
        $after = (int) get_post_meta($post_id, $meta_key, true);
        if ($after !== $before) {
            $changed_keys[] = $field_key;
        }
    };

    if (array_key_exists('prompt_text', $fields)) {
        $set_scalar_meta(
            'prompt_text',
            LL_TOOLS_PROMPT_CARD_PROMPT_TEXT_META_KEY,
            sanitize_textarea_field(is_scalar($fields['prompt_text']) ? (string) $fields['prompt_text'] : '')
        );
    }

    if (array_key_exists('prompt_audio_attachment_id', $fields)) {
        $set_int_meta(
            'prompt_audio_attachment_id',
            LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY,
            ll_tools_normalize_prompt_card_audio_attachment_id($fields['prompt_audio_attachment_id'])
        );
    }

    if (array_key_exists('prompt_audio_url', $fields)) {
        $set_scalar_meta(
            'prompt_audio_url',
            LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY,
            esc_url_raw(is_scalar($fields['prompt_audio_url']) ? (string) $fields['prompt_audio_url'] : '')
        );
    }

    if (array_key_exists('prompt_image_word_id', $fields)) {
        $prompt_image_word_id = is_scalar($fields['prompt_image_word_id']) ? (int) $fields['prompt_image_word_id'] : 0;
        if (!ll_tools_is_valid_prompt_card_word_id($prompt_image_word_id)) {
            $prompt_image_word_id = 0;
        }
        $set_int_meta('prompt_image_word_id', LL_TOOLS_PROMPT_CARD_PROMPT_IMAGE_WORD_ID_META_KEY, $prompt_image_word_id);
    }

    $correct_answer_word_id = (int) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, true);
    $correct_answer_changed = false;
    if (array_key_exists('correct_answer_word_id', $fields)) {
        $submitted_correct_answer_id = is_scalar($fields['correct_answer_word_id']) ? (int) $fields['correct_answer_word_id'] : 0;
        if (!ll_tools_is_valid_prompt_card_word_id($submitted_correct_answer_id)) {
            $submitted_correct_answer_id = 0;
        }
        $before_correct_answer_id = $correct_answer_word_id;
        $set_int_meta('correct_answer_word_id', LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, $submitted_correct_answer_id);
        $correct_answer_word_id = $submitted_correct_answer_id;
        $correct_answer_changed = $submitted_correct_answer_id !== $before_correct_answer_id;
    }

    if (array_key_exists('wrong_answer_word_ids', $fields) || $correct_answer_changed) {
        $raw_wrong_answer_ids = array_key_exists('wrong_answer_word_ids', $fields)
            ? $fields['wrong_answer_word_ids']
            : get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, true);
        $wrong_answer_word_ids = ll_tools_normalize_prompt_card_word_ids($raw_wrong_answer_ids, [$correct_answer_word_id]);
        $before_wrong_answer_ids = ll_tools_normalize_prompt_card_word_ids(
            get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, true),
            [$correct_answer_word_id]
        );
        if (!empty($wrong_answer_word_ids)) {
            update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, array_values($wrong_answer_word_ids));
        } else {
            delete_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY);
        }
        $after_wrong_answer_ids = ll_tools_normalize_prompt_card_word_ids(
            get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_WRONG_ANSWER_WORD_IDS_META_KEY, true),
            [$correct_answer_word_id]
        );
        if ($after_wrong_answer_ids !== $before_wrong_answer_ids) {
            $changed_keys[] = 'wrong_answer_word_ids';
        }
    }

    if (array_key_exists('track_answer_word_progress', $fields)) {
        $track_answer_word_progress = !empty($fields['track_answer_word_progress']) ? 1 : 0;
        $before = (int) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, true);
        update_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, $track_answer_word_progress);
        $after = (int) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_TRACK_ANSWER_WORD_PROGRESS_META_KEY, true);
        if ($after !== $before) {
            $changed_keys[] = 'track_answer_word_progress';
        }
    }

    return array_values(array_unique($changed_keys));
}

function ll_tools_prompt_card_word_reference_label(int $word_id): string {
    if ($word_id <= 0) {
        return '';
    }

    $post = get_post($word_id);
    if (!($post instanceof WP_Post) || $post->post_type !== 'words') {
        return '';
    }

    return sprintf(
        '%1$s (#%2$d)',
        wp_strip_all_tags((string) $post->post_title),
        $word_id
    );
}

function ll_tools_prompt_card_reference_summary(array $word_ids): string {
    $labels = [];
    foreach ($word_ids as $word_id) {
        $label = ll_tools_prompt_card_word_reference_label((int) $word_id);
        if ($label !== '') {
            $labels[] = $label;
        }
    }

    return implode(', ', $labels);
}

function ll_tools_prompt_card_add_metaboxes(): void {
    add_meta_box(
        'll-tools-prompt-card-config',
        __('Prompt Card Setup', 'll-tools-text-domain'),
        'll_tools_prompt_card_render_setup_metabox',
        LL_TOOLS_PROMPT_CARD_POST_TYPE,
        'normal',
        'high'
    );
}
add_action('add_meta_boxes_' . LL_TOOLS_PROMPT_CARD_POST_TYPE, 'll_tools_prompt_card_add_metaboxes');

function ll_tools_prompt_card_render_setup_metabox(WP_Post $post): void {
    wp_nonce_field('ll_tools_prompt_card_save', 'll_tools_prompt_card_nonce');

    $card = ll_tools_get_prompt_card_data((int) $post->ID);
    $prompt_image_word_id = (int) ($card['prompt_image_word_id'] ?? 0);
    $correct_answer_word_id = (int) ($card['correct_answer_word_id'] ?? 0);
    $wrong_answer_word_ids = isset($card['wrong_answer_word_ids']) && is_array($card['wrong_answer_word_ids'])
        ? $card['wrong_answer_word_ids']
        : [];
    $prompt_audio_attachment_id = (int) ($card['prompt_audio_attachment_id'] ?? 0);
    $prompt_audio_url = (string) ($card['prompt_audio_url'] ?? '');
    $track_answer_word_progress = !array_key_exists('track_answer_word_progress', $card) || !empty($card['track_answer_word_progress']);

    echo '<p>';
    echo esc_html__('Assign this card to the lesson category and wordset using the taxonomy boxes in the sidebar. The prompt side lives here; the answer options still come from normal words and their recordings.', 'll-tools-text-domain');
    echo '</p>';

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr>';
    echo '<th scope="row"><label for="ll-prompt-card-prompt-text">' . esc_html__('Prompt text', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<textarea id="ll-prompt-card-prompt-text" name="ll_prompt_card_prompt_text" rows="4" class="widefat" dir="auto">' . esc_textarea((string) ($card['prompt_text'] ?? '')) . '</textarea>';
    echo '<p class="description">' . esc_html__('Optional visible text or transcript for the question prompt.', 'll-tools-text-domain') . '</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="ll-prompt-card-prompt-audio-attachment-id">' . esc_html__('Prompt audio', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<input type="number" min="0" step="1" id="ll-prompt-card-prompt-audio-attachment-id" name="ll_prompt_card_prompt_audio_attachment_id" value="' . esc_attr((string) $prompt_audio_attachment_id) . '" class="small-text" /> ';
    echo '<button type="button" class="button" id="ll-prompt-card-select-audio">' . esc_html__('Select audio', 'll-tools-text-domain') . '</button> ';
    echo '<button type="button" class="button button-link-delete" id="ll-prompt-card-clear-audio">' . esc_html__('Clear', 'll-tools-text-domain') . '</button>';
    echo '<p class="description">' . esc_html__('Use a media-library audio attachment when possible. The URL field below is a fallback.', 'll-tools-text-domain') . '</p>';
    echo '<input type="url" id="ll-prompt-card-prompt-audio-url" name="ll_prompt_card_prompt_audio_url" value="' . esc_attr($prompt_audio_url) . '" class="widefat" />';
    if ($prompt_audio_url !== '') {
        echo '<p class="description">' . esc_html($prompt_audio_url) . '</p>';
    }
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="ll-prompt-card-prompt-image-word-id">' . esc_html__('Prompt image word ID', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<input type="number" min="0" step="1" id="ll-prompt-card-prompt-image-word-id" name="ll_prompt_card_prompt_image_word_id" value="' . esc_attr((string) $prompt_image_word_id) . '" class="small-text" />';
    echo '<p class="description">' . esc_html__('Pick the existing word whose image should be shown for this card. Leave empty to fall back to the correct answer word image.', 'll-tools-text-domain') . '</p>';
    $prompt_image_label = ll_tools_prompt_card_word_reference_label($prompt_image_word_id);
    if ($prompt_image_label !== '') {
        echo '<p><strong>' . esc_html__('Current:', 'll-tools-text-domain') . '</strong> ' . esc_html($prompt_image_label) . '</p>';
    }
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="ll-prompt-card-correct-answer-word-id">' . esc_html__('Correct answer word ID', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<input type="number" min="0" step="1" id="ll-prompt-card-correct-answer-word-id" name="ll_prompt_card_correct_answer_word_id" value="' . esc_attr((string) $correct_answer_word_id) . '" class="small-text" />';
    echo '<p class="description">' . esc_html__('This word provides the correct answer label and answer audio.', 'll-tools-text-domain') . '</p>';
    $correct_answer_label = ll_tools_prompt_card_word_reference_label($correct_answer_word_id);
    if ($correct_answer_label !== '') {
        echo '<p><strong>' . esc_html__('Current:', 'll-tools-text-domain') . '</strong> ' . esc_html($correct_answer_label) . '</p>';
    }
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="ll-prompt-card-wrong-answer-word-ids">' . esc_html__('Wrong answer word IDs', 'll-tools-text-domain') . '</label></th>';
    echo '<td>';
    echo '<input type="text" id="ll-prompt-card-wrong-answer-word-ids" name="ll_prompt_card_wrong_answer_word_ids" value="' . esc_attr(implode(', ', array_map('intval', $wrong_answer_word_ids))) . '" class="widefat" />';
    echo '<p class="description">' . esc_html__('Comma-separated word IDs for the answer options that should be offered as distractors.', 'll-tools-text-domain') . '</p>';
    $wrong_answer_summary = ll_tools_prompt_card_reference_summary($wrong_answer_word_ids);
    if ($wrong_answer_summary !== '') {
        echo '<p><strong>' . esc_html__('Current:', 'll-tools-text-domain') . '</strong> ' . esc_html($wrong_answer_summary) . '</p>';
    }
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row">' . esc_html__('Word mastery tracking', 'll-tools-text-domain') . '</th>';
    echo '<td>';
    echo '<label><input type="checkbox" name="ll_prompt_card_track_answer_word_progress" value="1" ' . checked($track_answer_word_progress, true, false) . ' /> ';
    echo esc_html__('Also count correct answers toward the correct answer word mastery progress.', 'll-tools-text-domain');
    echo '</label>';
    echo '<p class="description">' . esc_html__('Leave this on for cards like “horse or cow?” where the answer is still a vocabulary word. Turn it off for yes/no grammar cards so only prompt-card progress is tracked.', 'll-tools-text-domain') . '</p>';
    echo '</td>';
    echo '</tr>';

    echo '</tbody></table>';
}

function ll_tools_prompt_card_save_post(int $post_id, WP_Post $post): void {
    if ($post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return;
    }

    if (!isset($_POST['ll_tools_prompt_card_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ll_tools_prompt_card_nonce'])), 'll_tools_prompt_card_save')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    ll_tools_update_prompt_card_configuration($post_id, [
        'prompt_text' => isset($_POST['ll_prompt_card_prompt_text']) ? wp_unslash($_POST['ll_prompt_card_prompt_text']) : '',
        'prompt_audio_attachment_id' => isset($_POST['ll_prompt_card_prompt_audio_attachment_id']) ? wp_unslash($_POST['ll_prompt_card_prompt_audio_attachment_id']) : 0,
        'prompt_audio_url' => isset($_POST['ll_prompt_card_prompt_audio_url']) ? wp_unslash($_POST['ll_prompt_card_prompt_audio_url']) : '',
        'prompt_image_word_id' => isset($_POST['ll_prompt_card_prompt_image_word_id']) ? wp_unslash($_POST['ll_prompt_card_prompt_image_word_id']) : 0,
        'correct_answer_word_id' => isset($_POST['ll_prompt_card_correct_answer_word_id']) ? wp_unslash($_POST['ll_prompt_card_correct_answer_word_id']) : 0,
        'wrong_answer_word_ids' => isset($_POST['ll_prompt_card_wrong_answer_word_ids']) ? wp_unslash($_POST['ll_prompt_card_wrong_answer_word_ids']) : [],
        'track_answer_word_progress' => !empty($_POST['ll_prompt_card_track_answer_word_progress']),
    ]);

    ll_tools_prompt_card_invalidate_category_caches($post_id);
}
add_action('save_post_' . LL_TOOLS_PROMPT_CARD_POST_TYPE, 'll_tools_prompt_card_save_post', 10, 2);

function ll_tools_prompt_card_handle_deleted_post(int $post_id): void {
    $post = get_post($post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return;
    }

    ll_tools_prompt_card_invalidate_category_caches($post_id);
}
add_action('before_delete_post', 'll_tools_prompt_card_handle_deleted_post');

function ll_tools_prompt_card_handle_status_change(string $new_status, string $old_status, $post): void {
    if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE || $new_status === $old_status) {
        return;
    }

    if ($new_status !== 'publish' && $old_status !== 'publish') {
        return;
    }

    ll_tools_prompt_card_invalidate_category_caches((int) $post->ID);
}
add_action('transition_post_status', 'll_tools_prompt_card_handle_status_change', 20, 3);

function ll_tools_prompt_card_handle_trash_change(int $post_id): void {
    $post = get_post($post_id);
    if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return;
    }

    ll_tools_prompt_card_invalidate_category_caches($post_id);
}
add_action('trashed_post', 'll_tools_prompt_card_handle_trash_change', 20, 1);
add_action('untrashed_post', 'll_tools_prompt_card_handle_trash_change', 20, 1);

function ll_tools_prompt_card_handle_terms_set(int $object_id, array $terms, array $term_taxonomy_ids, string $taxonomy, bool $append, array $old_term_taxonomy_ids): void {
    unset($terms, $append);

    $post = get_post($object_id);
    if (!($post instanceof WP_Post) || $post->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return;
    }

    if ($taxonomy === 'word-category') {
        $old_category_ids = ll_tools_prompt_card_term_taxonomy_ids_to_term_ids($old_term_taxonomy_ids, 'word-category');
        $new_category_ids = ll_tools_prompt_card_term_taxonomy_ids_to_term_ids($term_taxonomy_ids, 'word-category');
        ll_tools_prompt_card_invalidate_category_caches($object_id, array_merge($old_category_ids, $new_category_ids));
        return;
    }

    if ($taxonomy === 'wordset') {
        ll_tools_prompt_card_invalidate_category_caches($object_id);
    }
}
add_action('set_object_terms', 'll_tools_prompt_card_handle_terms_set', 20, 6);

function ll_tools_prompt_card_admin_enqueue(string $hook_suffix): void {
    if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
        return;
    }

    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== LL_TOOLS_PROMPT_CARD_POST_TYPE) {
        return;
    }

    wp_enqueue_media();
    wp_add_inline_script(
        'jquery-core',
        "(function($){'use strict';$(function(){var frame=null;var \$attachment=$('#ll-prompt-card-prompt-audio-attachment-id');var \$url=$('#ll-prompt-card-prompt-audio-url');$('#ll-prompt-card-select-audio').on('click',function(e){e.preventDefault();if(frame){frame.open();return;}frame=wp.media({title:'" . esc_js(__('Select prompt audio', 'll-tools-text-domain')) . "',button:{text:'" . esc_js(__('Use this audio', 'll-tools-text-domain')) . "'},library:{type:'audio'},multiple:false});frame.on('select',function(){var selection=frame.state().get('selection').first();if(!selection){return;}var json=selection.toJSON();if(json&&json.id){\$attachment.val(String(json.id));}if(json&&json.url){\$url.val(String(json.url));}});frame.open();});$('#ll-prompt-card-clear-audio').on('click',function(e){e.preventDefault();\$attachment.val('');\$url.val('');});});})(jQuery);",
        'after'
    );
}
add_action('admin_enqueue_scripts', 'll_tools_prompt_card_admin_enqueue');

add_filter('manage_' . LL_TOOLS_PROMPT_CARD_POST_TYPE . '_posts_columns', static function (array $columns): array {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        if ($key === 'title') {
            $new_columns['title'] = $label;
            $new_columns['prompt_card_category'] = __('Category', 'll-tools-text-domain');
            $new_columns['prompt_card_answer'] = __('Correct Answer', 'll-tools-text-domain');
            $new_columns['prompt_card_progress'] = __('Word Mastery', 'll-tools-text-domain');
            continue;
        }
        $new_columns[$key] = $label;
    }

    return $new_columns;
});

add_action('manage_' . LL_TOOLS_PROMPT_CARD_POST_TYPE . '_posts_custom_column', static function (string $column, int $post_id): void {
    if ($column === 'prompt_card_category') {
        $terms = get_the_terms($post_id, 'word-category');
        if ($terms && !is_wp_error($terms)) {
            echo esc_html(implode(', ', wp_list_pluck($terms, 'name')));
            return;
        }
        echo '&mdash;';
        return;
    }

    if ($column === 'prompt_card_answer') {
        $label = ll_tools_prompt_card_word_reference_label((int) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_CORRECT_ANSWER_WORD_ID_META_KEY, true));
        echo $label !== '' ? esc_html($label) : '&mdash;';
        return;
    }

    if ($column === 'prompt_card_progress') {
        echo ll_tools_prompt_card_tracks_answer_word_progress($post_id)
            ? esc_html__('Tracks answer word', 'll-tools-text-domain')
            : esc_html__('Card only', 'll-tools-text-domain');
    }
}, 10, 2);
