<?php
if (!defined('WPINC')) { die; }

if (!defined('LL_TOOLS_INTERNAL_REVIEW_NOTE_META_KEY')) {
    define('LL_TOOLS_INTERNAL_REVIEW_NOTE_META_KEY', '_ll_tools_internal_review_note');
}

function ll_tools_internal_review_note_meta_key(): string {
    return LL_TOOLS_INTERNAL_REVIEW_NOTE_META_KEY;
}

function ll_tools_sanitize_internal_review_note($value): string {
    return trim(sanitize_textarea_field((string) $value));
}

function ll_tools_get_internal_review_note(int $object_id): string {
    if ($object_id <= 0) {
        return '';
    }

    return trim((string) get_post_meta($object_id, ll_tools_internal_review_note_meta_key(), true));
}

function ll_tools_set_internal_review_note(int $object_id, string $note): string {
    $note = ll_tools_sanitize_internal_review_note($note);
    if ($object_id <= 0) {
        return '';
    }

    if ($note === '') {
        delete_post_meta($object_id, ll_tools_internal_review_note_meta_key());
        return '';
    }

    update_post_meta($object_id, ll_tools_internal_review_note_meta_key(), $note);
    return $note;
}

function ll_tools_internal_review_note_object_type(int $object_id): string {
    $post = get_post($object_id);
    if (!($post instanceof WP_Post)) {
        return '';
    }

    if ($post->post_type === 'words') {
        return 'word';
    }

    $prompt_card_post_type = defined('LL_TOOLS_PROMPT_CARD_POST_TYPE') ? LL_TOOLS_PROMPT_CARD_POST_TYPE : 'll_prompt_card';
    if ($post->post_type === $prompt_card_post_type) {
        return 'prompt_card';
    }

    return '';
}

function ll_tools_internal_review_note_normalize_object_type(string $type): string {
    $type = sanitize_key(str_replace('-', '_', $type));
    if (in_array($type, ['word', 'words'], true)) {
        return 'word';
    }
    if (in_array($type, ['prompt_card', 'prompt_cards'], true)) {
        return 'prompt_card';
    }
    return '';
}

function ll_tools_internal_review_note_object_belongs_to_wordset(int $object_id, int $wordset_id): bool {
    if ($object_id <= 0 || $wordset_id <= 0) {
        return false;
    }

    return has_term($wordset_id, 'wordset', $object_id);
}

function ll_tools_current_user_can_manage_internal_review_notes(int $wordset_id = 0): bool {
    if (!is_user_logged_in()) {
        return false;
    }

    if (!current_user_can('view_ll_tools') && !current_user_can('manage_options')) {
        return false;
    }

    if ($wordset_id <= 0) {
        return true;
    }

    if (!function_exists('ll_tools_user_can_view_wordset')) {
        return true;
    }

    return ll_tools_user_can_view_wordset($wordset_id, (int) get_current_user_id());
}

function ll_tools_build_internal_review_note_row(int $object_id, int $wordset_id = 0): array {
    $object_type = ll_tools_internal_review_note_object_type($object_id);
    if ($object_type === '') {
        return [];
    }

    $note = ll_tools_get_internal_review_note($object_id);
    $category_terms = wp_get_post_terms($object_id, 'word-category', [
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    $categories = [];
    if (!is_wp_error($category_terms)) {
        foreach ((array) $category_terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $categories[] = [
                'id' => (int) $term->term_id,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
            ];
        }
    }

    $row = [
        'object_type' => $object_type,
        'object_id' => $object_id,
        'note' => $note,
        'title' => trim((string) get_the_title($object_id)),
        'categories' => $categories,
    ];

    if ($wordset_id > 0) {
        $row['wordset_id'] = $wordset_id;
    }

    if ($object_type === 'word' && function_exists('ll_tools_word_grid_resolve_display_text')) {
        $display_values = ll_tools_word_grid_resolve_display_text($object_id);
        $row['word'] = (string) ($display_values['word_text'] ?? '');
        $row['translation'] = (string) ($display_values['translation_text'] ?? '');
    } elseif ($object_type === 'prompt_card' && function_exists('ll_tools_get_prompt_card_data')) {
        $card = ll_tools_get_prompt_card_data($object_id);
        $row['prompt_text'] = (string) ($card['prompt_text'] ?? '');
        $row['correct_answer_word_id'] = (int) ($card['correct_answer_word_id'] ?? 0);
        $row['prompt_image_word_id'] = (int) ($card['prompt_image_word_id'] ?? 0);
    }

    return $row;
}

function ll_tools_render_internal_review_note_field(int $object_id, string $object_type, int $wordset_id = 0): string {
    $object_type = ll_tools_internal_review_note_normalize_object_type($object_type);
    if ($object_id <= 0 || $object_type === '') {
        return '';
    }

    $note = ll_tools_get_internal_review_note($object_id);
    $field_id = 'll-internal-review-note-' . $object_type . '-' . $object_id;
    $status_id = $field_id . '-status';
    $has_note = $note !== '';
    $label = __('Internal review note', 'll-tools-text-domain');
    $empty_label = __('Add internal review note', 'll-tools-text-domain');
    $description = __('For staff-only review instructions, such as image fixes, split requests, or cleanup notes. This is not shown to learners.', 'll-tools-text-domain');

    ob_start();
    ?>
    <details
        class="ll-internal-review-note"
        data-ll-internal-review-note
        data-object-type="<?php echo esc_attr($object_type); ?>"
        data-object-id="<?php echo esc_attr((string) $object_id); ?>"
        data-wordset-id="<?php echo esc_attr((string) max(0, $wordset_id)); ?>"
        <?php if ($has_note) : ?>open<?php endif; ?>
    >
        <summary class="ll-internal-review-note__summary" data-ll-internal-review-note-summary>
            <span class="ll-internal-review-note__summary-label ll-internal-review-note__summary-label--empty"><?php echo esc_html($empty_label); ?></span>
        </summary>
        <label class="ll-internal-review-note__label" for="<?php echo esc_attr($field_id); ?>">
            <?php echo esc_html($label); ?>
        </label>
        <p class="ll-internal-review-note__description" id="<?php echo esc_attr($field_id); ?>-description">
            <?php echo esc_html($description); ?>
        </p>
        <textarea
            class="ll-internal-review-note__input"
            id="<?php echo esc_attr($field_id); ?>"
            rows="3"
            data-ll-internal-review-note-input
            aria-describedby="<?php echo esc_attr($field_id); ?>-description <?php echo esc_attr($status_id); ?>"
        ><?php echo esc_textarea($note); ?></textarea>
        <div class="ll-internal-review-note__status" id="<?php echo esc_attr($status_id); ?>" data-ll-internal-review-note-status aria-live="polite"></div>
    </details>
    <?php

    return trim((string) ob_get_clean());
}

function ll_tools_get_internal_review_note_rows_for_wordset(int $wordset_id, string $category_spec = '', bool $include_empty = false): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $post_types = ['words'];
    if (defined('LL_TOOLS_PROMPT_CARD_POST_TYPE')) {
        $post_types[] = LL_TOOLS_PROMPT_CARD_POST_TYPE;
    } else {
        $post_types[] = 'll_prompt_card';
    }

    $tax_query = [
        [
            'taxonomy' => 'wordset',
            'field' => 'term_id',
            'terms' => [$wordset_id],
        ],
    ];

    $category_spec = trim($category_spec);
    if ($category_spec !== '') {
        $category_field = ctype_digit($category_spec) ? 'term_id' : 'slug';
        $tax_query[] = [
            'taxonomy' => 'word-category',
            'field' => $category_field,
            'terms' => ctype_digit($category_spec) ? [(int) $category_spec] : [sanitize_title($category_spec)],
        ];
        $tax_query['relation'] = 'AND';
    }

    $args = [
        'post_type' => $post_types,
        'post_status' => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        'tax_query' => $tax_query,
    ];

    if (!$include_empty) {
        $args['meta_query'] = [
            [
                'key' => ll_tools_internal_review_note_meta_key(),
                'value' => '',
                'compare' => '!=',
            ],
        ];
    }

    $object_ids = get_posts($args);
    $rows = [];
    foreach ((array) $object_ids as $object_id) {
        $object_id = (int) $object_id;
        if ($object_id <= 0) {
            continue;
        }
        $row = ll_tools_build_internal_review_note_row($object_id, $wordset_id);
        if (empty($row)) {
            continue;
        }
        if (!$include_empty && trim((string) ($row['note'] ?? '')) === '') {
            continue;
        }
        $rows[] = $row;
    }

    return $rows;
}

add_action('wp_ajax_ll_tools_save_internal_review_note', 'll_tools_save_internal_review_note_ajax_handler');
function ll_tools_save_internal_review_note_ajax_handler(): void {
    check_ajax_referer('ll_internal_review_note', 'nonce');

    $object_id = isset($_POST['object_id']) ? absint(wp_unslash((string) $_POST['object_id'])) : 0;
    $submitted_type = isset($_POST['object_type'])
        ? ll_tools_internal_review_note_normalize_object_type((string) wp_unslash($_POST['object_type']))
        : '';
    $wordset_id = isset($_POST['wordset_id']) ? absint(wp_unslash((string) $_POST['wordset_id'])) : 0;
    $note = isset($_POST['note']) ? (string) wp_unslash($_POST['note']) : '';

    if ($object_id <= 0) {
        wp_send_json_error([
            'message' => __('Missing review-note object.', 'll-tools-text-domain'),
        ], 400);
    }

    $object_type = ll_tools_internal_review_note_object_type($object_id);
    if ($object_type === '' || ($submitted_type !== '' && $submitted_type !== $object_type)) {
        wp_send_json_error([
            'message' => __('This item cannot store an internal review note.', 'll-tools-text-domain'),
        ], 400);
    }

    if (!ll_tools_current_user_can_manage_internal_review_notes($wordset_id)) {
        wp_send_json_error([
            'message' => __('You cannot edit internal review notes for this lesson.', 'll-tools-text-domain'),
        ], 403);
    }

    if ($wordset_id > 0 && !ll_tools_internal_review_note_object_belongs_to_wordset($object_id, $wordset_id)) {
        wp_send_json_error([
            'message' => __('This item does not belong to the selected word set.', 'll-tools-text-domain'),
        ], 400);
    }

    $saved_note = ll_tools_set_internal_review_note($object_id, $note);
    wp_send_json_success([
        'object_type' => $object_type,
        'object_id' => $object_id,
        'wordset_id' => $wordset_id,
        'note' => $saved_note,
        'row' => ll_tools_build_internal_review_note_row($object_id, $wordset_id),
    ]);
}
