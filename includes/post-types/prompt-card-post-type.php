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

function ll_tools_get_prompt_card_prompt_audio_url(int $post_id): string {
    $attachment_id = ll_tools_normalize_prompt_card_audio_attachment_id(
        get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_ATTACHMENT_ID_META_KEY, true)
    );
    if ($attachment_id > 0) {
        $attachment_url = wp_get_attachment_url($attachment_id);
        if (is_string($attachment_url) && $attachment_url !== '') {
            return $attachment_url;
        }
    }

    $url = trim((string) get_post_meta($post_id, LL_TOOLS_PROMPT_CARD_PROMPT_AUDIO_URL_META_KEY, true));
    return $url !== '' ? esc_url_raw($url) : '';
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

function ll_tools_get_prompt_card_posts_for_category_context(array $category_context, array $wordset_terms = []): array {
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

    $posts = get_posts($args);
    return array_values(array_filter($posts, static function ($post): bool {
        return $post instanceof WP_Post;
    }));
}

function ll_tools_get_prompt_card_ids_for_category_context(array $category_context, array $wordset_terms = []): array {
    return array_values(array_filter(array_map(static function ($post): int {
        return ($post instanceof WP_Post) ? (int) $post->ID : 0;
    }, ll_tools_get_prompt_card_posts_for_category_context($category_context, $wordset_terms)), static function (int $post_id): bool {
        return $post_id > 0;
    }));
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
