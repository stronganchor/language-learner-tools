<?php
/**
 * [editor_hub] - Editor-facing interface to review and complete word metadata.
 */

if (!defined('WPINC')) { die; }

function ll_tools_editor_hub_user_can_access(): bool {
    if (!is_user_logged_in()) {
        return false;
    }

    if (current_user_can('manage_options')) {
        return true;
    }

    if (function_exists('ll_tools_user_can_edit_vocab_words')) {
        return ll_tools_user_can_edit_vocab_words();
    }

    return current_user_can('view_ll_tools');
}

function ll_tools_editor_hub_find_first_wordset_id(bool $hide_empty): int {
    $term_ids = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => $hide_empty,
        'fields' => 'ids',
        'orderby' => 'term_id',
        'order' => 'ASC',
        'number' => 1,
    ]);

    if (is_wp_error($term_ids) || empty($term_ids)) {
        return 0;
    }

    return (int) $term_ids[0];
}

function ll_tools_editor_hub_resolve_wordset_id($wordset_spec = ''): int {
    $wordset_spec = is_string($wordset_spec) ? sanitize_text_field($wordset_spec) : '';

    if (function_exists('ll_flashcards_resolve_wordset_ids')) {
        $resolved = ll_flashcards_resolve_wordset_ids($wordset_spec, true);
        $resolved = array_values(array_filter(array_map('intval', (array) $resolved), static function ($id) {
            return $id > 0;
        }));
        if (!empty($resolved)) {
            return (int) $resolved[0];
        }
    }

    if (function_exists('ll_tools_get_active_wordset_id')) {
        $active_wordset_id = (int) ll_tools_get_active_wordset_id();
        if ($active_wordset_id > 0) {
            return $active_wordset_id;
        }
    }

    $first_with_content = ll_tools_editor_hub_find_first_wordset_id(true);
    if ($first_with_content > 0) {
        return $first_with_content;
    }

    return ll_tools_editor_hub_find_first_wordset_id(false);
}

function ll_tools_editor_hub_get_wordset_name(int $wordset_id): string {
    if ($wordset_id <= 0) {
        return '';
    }

    $term = get_term($wordset_id, 'wordset');
    if (!$term || is_wp_error($term)) {
        return '';
    }

    return (string) $term->name;
}

function ll_tools_editor_hub_get_word_ids_for_wordset(int $wordset_id): array {
    if ($wordset_id <= 0) {
        return [];
    }

    $query = new WP_Query([
        'post_type'      => 'words',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => [
            [
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ],
        ],
    ]);

    return array_values(array_filter(array_map('intval', (array) $query->posts), static function ($id) {
        return $id > 0;
    }));
}

function ll_tools_editor_hub_get_primary_category_for_word(int $word_id): array {
    $fallback = [
        'id' => 0,
        'slug' => 'uncategorized',
        'name' => __('Uncategorized', 'll-tools-text-domain'),
    ];

    if ($word_id <= 0) {
        return $fallback;
    }

    $terms = [];
    if (function_exists('ll_get_deepest_categories')) {
        $deepest_terms = ll_get_deepest_categories($word_id);
        if (is_array($deepest_terms) && !empty($deepest_terms)) {
            $terms = $deepest_terms;
        }
    }

    if (empty($terms)) {
        $raw_terms = wp_get_post_terms($word_id, 'word-category', [
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (!is_wp_error($raw_terms) && !empty($raw_terms)) {
            $terms = $raw_terms;
        }
    }

    if (empty($terms)) {
        return $fallback;
    }

    usort($terms, static function ($left, $right) {
        return strnatcasecmp((string) ($left->name ?? ''), (string) ($right->name ?? ''));
    });

    $term = $terms[0];
    if (!($term instanceof WP_Term)) {
        return $fallback;
    }

    return [
        'id' => (int) $term->term_id,
        'slug' => (string) $term->slug,
        'name' => function_exists('ll_tools_get_category_display_name')
            ? (string) ll_tools_get_category_display_name($term)
            : (string) $term->name,
    ];
}

function ll_tools_editor_hub_get_part_of_speech_for_word(int $word_id, array $map = []): array {
    if ($word_id <= 0) {
        return ['slug' => '', 'label' => ''];
    }

    if (isset($map[$word_id]) && is_array($map[$word_id])) {
        return [
            'slug' => (string) ($map[$word_id]['slug'] ?? ''),
            'label' => (string) ($map[$word_id]['label'] ?? ''),
        ];
    }

    $terms = wp_get_post_terms($word_id, 'part_of_speech', [
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (is_wp_error($terms) || empty($terms)) {
        return ['slug' => '', 'label' => ''];
    }

    $term = $terms[0];
    if (!($term instanceof WP_Term)) {
        return ['slug' => '', 'label' => ''];
    }

    return [
        'slug' => (string) $term->slug,
        'label' => (string) $term->name,
    ];
}

function ll_tools_editor_hub_category_has_text_only_answer_options(int $category_id): bool {
    if ($category_id <= 0 || !function_exists('ll_tools_get_category_quiz_config')) {
        return false;
    }

    $term = get_term($category_id, 'word-category');
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return false;
    }

    $config = ll_tools_get_category_quiz_config($term);
    $option_type = (string) ($config['option_type'] ?? '');
    return in_array($option_type, ['text', 'text_translation', 'text_title'], true);
}

function ll_tools_editor_hub_get_recording_type_labels(): array {
    $defaults = [
        'question' => __('Question', 'll-tools-text-domain'),
        'isolation' => __('Isolation', 'll-tools-text-domain'),
        'introduction' => __('Introduction', 'll-tools-text-domain'),
    ];

    foreach ($defaults as $slug => $default_label) {
        if (function_exists('ll_get_recording_type_name')) {
            $translated = ll_get_recording_type_name($slug, $default_label);
            if (is_string($translated) && $translated !== '') {
                $defaults[$slug] = $translated;
            }
        }
    }

    return $defaults;
}

function ll_tools_editor_hub_collect_recordings_for_word(int $word_id, array $audio_by_word): array {
    if ($word_id <= 0) {
        return [];
    }

    $audio_files = isset($audio_by_word[$word_id]) && is_array($audio_by_word[$word_id])
        ? $audio_by_word[$word_id]
        : [];
    if (empty($audio_files)) {
        return [];
    }

    $main_types = function_exists('ll_tools_get_main_recording_types')
        ? (array) ll_tools_get_main_recording_types()
        : ['isolation', 'question', 'introduction'];
    $preferred_speaker = function_exists('ll_tools_word_grid_get_preferred_speaker')
        ? (int) ll_tools_word_grid_get_preferred_speaker($audio_files, $main_types)
        : 0;
    $type_order = ['question', 'isolation', 'introduction'];
    $type_labels = ll_tools_editor_hub_get_recording_type_labels();

    $rows = [];
    foreach ($type_order as $type) {
        if (function_exists('ll_tools_word_grid_select_audio_entry')) {
            $entry = ll_tools_word_grid_select_audio_entry($audio_files, $type, $preferred_speaker);
        } else {
            $entry = [];
            foreach ($audio_files as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }
                $candidate_type = (string) ($candidate['recording_type'] ?? '');
                if ($candidate_type !== $type) {
                    continue;
                }
                $entry = $candidate;
                break;
            }
        }

        if (!is_array($entry) || empty($entry['id']) || empty($entry['url'])) {
            continue;
        }

        $text = trim((string) ($entry['recording_text'] ?? ''));
        $translation = trim((string) ($entry['recording_translation'] ?? ''));
        $ipa = function_exists('ll_tools_word_grid_normalize_ipa_output')
            ? ll_tools_word_grid_normalize_ipa_output((string) ($entry['recording_ipa'] ?? ''))
            : trim((string) ($entry['recording_ipa'] ?? ''));

        $missing = [
            'text' => ($text === ''),
            'translation' => ($translation === ''),
            'ipa' => ($ipa === ''),
        ];

        $rows[] = [
            'id' => (int) $entry['id'],
            'type' => $type,
            'label' => (string) ($type_labels[$type] ?? ucfirst($type)),
            'audio_url' => (string) $entry['url'],
            'text' => $text,
            'translation' => $translation,
            'ipa' => $ipa,
            'missing' => $missing,
        ];
    }

    return $rows;
}

function ll_tools_editor_hub_build_ui_options(int $wordset_id): array {
    $wordset_id = max(0, $wordset_id);

    $part_of_speech_options = [];
    $pos_terms = get_terms([
        'taxonomy' => 'part_of_speech',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
    ]);
    if (!is_wp_error($pos_terms)) {
        foreach ((array) $pos_terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $slug = (string) $term->slug;
            if ($slug === '') {
                continue;
            }
            $part_of_speech_options[] = [
                'value' => $slug,
                'label' => (string) $term->name,
            ];
        }
    }

    $has_gender = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? (bool) ll_tools_wordset_has_grammatical_gender($wordset_id)
        : false;
    $has_plurality = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_plurality'))
        ? (bool) ll_tools_wordset_has_plurality($wordset_id)
        : false;
    $has_verb_tense = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_tense'))
        ? (bool) ll_tools_wordset_has_verb_tense($wordset_id)
        : false;
    $has_verb_mood = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_verb_mood'))
        ? (bool) ll_tools_wordset_has_verb_mood($wordset_id)
        : false;

    $gender_options = [];
    if ($has_gender && function_exists('ll_tools_wordset_get_gender_options')) {
        foreach ((array) ll_tools_wordset_get_gender_options($wordset_id) as $value) {
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $label = function_exists('ll_tools_wordset_format_gender_display_label')
                ? ll_tools_wordset_format_gender_display_label($value)
                : $value;
            $gender_options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }
    }

    $plurality_options = [];
    if ($has_plurality && function_exists('ll_tools_wordset_get_plurality_options')) {
        foreach ((array) ll_tools_wordset_get_plurality_options($wordset_id) as $value) {
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $plurality_options[] = [
                'value' => $value,
                'label' => $value,
            ];
        }
    }

    $verb_tense_options = [];
    if ($has_verb_tense && function_exists('ll_tools_wordset_get_verb_tense_options')) {
        foreach ((array) ll_tools_wordset_get_verb_tense_options($wordset_id) as $value) {
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $verb_tense_options[] = [
                'value' => $value,
                'label' => $value,
            ];
        }
    }

    $verb_mood_options = [];
    if ($has_verb_mood && function_exists('ll_tools_wordset_get_verb_mood_options')) {
        foreach ((array) ll_tools_wordset_get_verb_mood_options($wordset_id) as $value) {
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $verb_mood_options[] = [
                'value' => $value,
                'label' => $value,
            ];
        }
    }

    return [
        'part_of_speech' => $part_of_speech_options,
        'gender' => $gender_options,
        'plurality' => $plurality_options,
        'verb_tense' => $verb_tense_options,
        'verb_mood' => $verb_mood_options,
        'flags' => [
            'gender' => $has_gender,
            'plurality' => $has_plurality,
            'verb_tense' => $has_verb_tense,
            'verb_mood' => $has_verb_mood,
        ],
    ];
}

function ll_tools_editor_hub_missing_field_label_map(): array {
    return [
        'word_text' => __('Word', 'll-tools-text-domain'),
        'word_translation' => __('Translation', 'll-tools-text-domain'),
        'word_note' => __('Note', 'll-tools-text-domain'),
        'dictionary_entry' => __('Dictionary entry', 'll-tools-text-domain'),
        'part_of_speech' => __('Part of speech', 'll-tools-text-domain'),
        'grammatical_gender' => __('Gender', 'll-tools-text-domain'),
        'grammatical_plurality' => __('Plurality', 'll-tools-text-domain'),
        'verb_tense' => __('Verb tense', 'll-tools-text-domain'),
        'verb_mood' => __('Verb mood', 'll-tools-text-domain'),
    ];
}

function ll_tools_editor_hub_get_word_image_data(int $word_id): array {
    $fallback = [
        'id' => 0,
        'url' => '',
        'alt' => '',
        'width' => 0,
        'height' => 0,
    ];

    if ($word_id <= 0) {
        return $fallback;
    }

    $attachment_id = (int) get_post_thumbnail_id($word_id);
    if ($attachment_id <= 0) {
        return $fallback;
    }

    $url = function_exists('ll_tools_get_masked_image_url')
        ? (string) ll_tools_get_masked_image_url($attachment_id, 'large')
        : '';
    if ($url === '') {
        $url = (string) (wp_get_attachment_image_url($attachment_id, 'large') ?: '');
    }

    $size_data = wp_get_attachment_image_src($attachment_id, 'large');
    $width = 0;
    $height = 0;
    if (is_array($size_data) && isset($size_data[1], $size_data[2])) {
        $width = (int) $size_data[1];
        $height = (int) $size_data[2];
    }

    $alt = trim((string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true));
    if ($alt === '') {
        $alt = trim((string) get_the_title($word_id));
    }

    return [
        'id' => $attachment_id,
        'url' => $url,
        'alt' => $alt,
        'width' => $width,
        'height' => $height,
    ];
}

function ll_tools_editor_hub_build_item(int $word_id, int $wordset_id, array $ui_options, array $pos_by_word, array $audio_by_word): array {
    $word_post = get_post($word_id);
    if (!$word_post || $word_post->post_type !== 'words') {
        return [];
    }

    $display_values = function_exists('ll_tools_word_grid_resolve_display_text')
        ? ll_tools_word_grid_resolve_display_text($word_id)
        : [
            'word_text' => get_the_title($word_id),
            'translation_text' => (string) get_post_meta($word_id, 'word_translation', true),
            'store_in_title' => true,
        ];

    $word_text = trim((string) ($display_values['word_text'] ?? ''));
    $translation_text = trim((string) ($display_values['translation_text'] ?? ''));
    $word_note = trim((string) get_post_meta($word_id, 'll_word_usage_note', true));

    $dictionary_entry_id = function_exists('ll_tools_get_word_dictionary_entry_id')
        ? (int) ll_tools_get_word_dictionary_entry_id($word_id)
        : 0;
    $dictionary_entry_title = $dictionary_entry_id > 0 ? trim((string) get_the_title($dictionary_entry_id)) : '';

    $part_of_speech = ll_tools_editor_hub_get_part_of_speech_for_word($word_id, $pos_by_word);
    $pos_slug = (string) ($part_of_speech['slug'] ?? '');
    $is_noun = ($pos_slug === 'noun');
    $is_verb = ($pos_slug === 'verb');

    $gender_value = trim((string) get_post_meta($word_id, 'll_grammatical_gender', true));
    $gender_label = '';
    if ($gender_value !== '' && function_exists('ll_tools_wordset_get_gender_label')) {
        $gender_label = (string) ll_tools_wordset_get_gender_label($wordset_id, $gender_value);
    }
    if ($gender_label === '') {
        $gender_label = $gender_value;
    }

    $plurality_value = trim((string) get_post_meta($word_id, 'll_grammatical_plurality', true));
    $plurality_label = '';
    if ($plurality_value !== '' && function_exists('ll_tools_wordset_get_plurality_label')) {
        $plurality_label = (string) ll_tools_wordset_get_plurality_label($wordset_id, $plurality_value);
    }
    if ($plurality_label === '') {
        $plurality_label = $plurality_value;
    }

    $verb_tense_value = trim((string) get_post_meta($word_id, 'll_verb_tense', true));
    $verb_tense_label = '';
    if ($verb_tense_value !== '' && function_exists('ll_tools_wordset_get_verb_tense_label')) {
        $verb_tense_label = (string) ll_tools_wordset_get_verb_tense_label($wordset_id, $verb_tense_value);
    }
    if ($verb_tense_label === '') {
        $verb_tense_label = $verb_tense_value;
    }

    $verb_mood_value = trim((string) get_post_meta($word_id, 'll_verb_mood', true));
    $verb_mood_label = '';
    if ($verb_mood_value !== '' && function_exists('ll_tools_wordset_get_verb_mood_label')) {
        $verb_mood_label = (string) ll_tools_wordset_get_verb_mood_label($wordset_id, $verb_mood_value);
    }
    if ($verb_mood_label === '') {
        $verb_mood_label = $verb_mood_value;
    }

    $recordings = ll_tools_editor_hub_collect_recordings_for_word($word_id, $audio_by_word);

    $flags = [
        'word_text' => ($word_text === ''),
        'word_translation' => ($translation_text === ''),
        'word_note' => ($word_note === ''),
        'dictionary_entry' => ($dictionary_entry_id <= 0 && $dictionary_entry_title === ''),
        'part_of_speech' => ($pos_slug === ''),
        'grammatical_gender' => !empty($ui_options['flags']['gender']) && $is_noun && $gender_value === '',
        'grammatical_plurality' => !empty($ui_options['flags']['plurality']) && $is_noun && $plurality_value === '',
        'verb_tense' => !empty($ui_options['flags']['verb_tense']) && $is_verb && $verb_tense_value === '',
        'verb_mood' => !empty($ui_options['flags']['verb_mood']) && $is_verb && $verb_mood_value === '',
    ];

    $has_missing = false;
    foreach ($flags as $is_missing) {
        if ($is_missing) {
            $has_missing = true;
            break;
        }
    }

    foreach ($recordings as $recording) {
        $rec_missing = (array) ($recording['missing'] ?? []);
        if (!empty($rec_missing['text']) || !empty($rec_missing['translation']) || !empty($rec_missing['ipa'])) {
            $has_missing = true;
            break;
        }
    }

    $missing_labels = [];
    $label_map = ll_tools_editor_hub_missing_field_label_map();
    foreach ($flags as $field => $is_missing) {
        if ($is_missing && isset($label_map[$field])) {
            $missing_labels[] = (string) $label_map[$field];
        }
    }

    foreach ($recordings as $recording) {
        $recording_label = (string) ($recording['label'] ?? __('Recording', 'll-tools-text-domain'));
        $rec_missing = (array) ($recording['missing'] ?? []);
        if (!empty($rec_missing['text'])) {
            $missing_labels[] = sprintf(
                /* translators: 1: recording type label, 2: field label */
                __('%1$s recording: %2$s', 'll-tools-text-domain'),
                $recording_label,
                __('Text', 'll-tools-text-domain')
            );
        }
        if (!empty($rec_missing['translation'])) {
            $missing_labels[] = sprintf(
                /* translators: 1: recording type label, 2: field label */
                __('%1$s recording: %2$s', 'll-tools-text-domain'),
                $recording_label,
                __('Translation', 'll-tools-text-domain')
            );
        }
        if (!empty($rec_missing['ipa'])) {
            $missing_labels[] = sprintf(
                /* translators: 1: recording type label, 2: field label */
                __('%1$s recording: %2$s', 'll-tools-text-domain'),
                $recording_label,
                __('IPA', 'll-tools-text-domain')
            );
        }
    }

    $missing_labels = array_values(array_unique(array_filter(array_map('strval', $missing_labels))));

    $category = ll_tools_editor_hub_get_primary_category_for_word($word_id);
    $category_id = (int) ($category['id'] ?? 0);
    $specific_wrong_answer_texts = function_exists('ll_tools_get_word_specific_wrong_answer_texts')
        ? ll_tools_get_word_specific_wrong_answer_texts($word_id)
        : [];

    return [
        'word_id' => $word_id,
        'title' => (string) get_the_title($word_id),
        'wordset_id' => $wordset_id,
        'category' => $category,
        'word_text' => $word_text,
        'word_translation' => $translation_text,
        'word_note' => $word_note,
        'specific_wrong_answer_texts' => array_values(array_map('strval', (array) $specific_wrong_answer_texts)),
        'answer_options_text_only' => ll_tools_editor_hub_category_has_text_only_answer_options($category_id),
        'image' => ll_tools_editor_hub_get_word_image_data($word_id),
        'dictionary_entry' => [
            'id' => $dictionary_entry_id,
            'title' => $dictionary_entry_title,
        ],
        'part_of_speech' => [
            'slug' => $pos_slug,
            'label' => (string) ($part_of_speech['label'] ?? ''),
        ],
        'grammatical_gender' => [
            'value' => $gender_value,
            'label' => $gender_label,
        ],
        'grammatical_plurality' => [
            'value' => $plurality_value,
            'label' => $plurality_label,
        ],
        'verb_tense' => [
            'value' => $verb_tense_value,
            'label' => $verb_tense_label,
        ],
        'verb_mood' => [
            'value' => $verb_mood_value,
            'label' => $verb_mood_label,
        ],
        'recordings' => $recordings,
        'missing_flags' => $flags,
        'missing_labels' => $missing_labels,
        'missing_count' => count($missing_labels),
        'has_missing' => $has_missing,
    ];
}

function ll_tools_editor_hub_sort_categories(array $categories): array {
    if (empty($categories)) {
        return $categories;
    }

    uasort($categories, static function ($left, $right) {
        $left_slug = (string) ($left['slug'] ?? '');
        $right_slug = (string) ($right['slug'] ?? '');
        if ($left_slug === 'uncategorized' && $right_slug !== 'uncategorized') {
            return -1;
        }
        if ($right_slug === 'uncategorized' && $left_slug !== 'uncategorized') {
            return 1;
        }

        return strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
    });

    return array_values($categories);
}

function ll_tools_editor_hub_get_dataset(int $wordset_id, string $selected_category = ''): array {
    $wordset_id = max(0, $wordset_id);
    $selected_category = sanitize_title($selected_category);

    $ui_options = ll_tools_editor_hub_build_ui_options($wordset_id);
    $dataset = [
        'wordset_id' => $wordset_id,
        'wordset_name' => ll_tools_editor_hub_get_wordset_name($wordset_id),
        'ui_options' => $ui_options,
        'categories' => [],
        'selected_category' => '',
        'items' => [],
        'total_missing' => 0,
    ];

    if ($wordset_id <= 0) {
        return $dataset;
    }

    $word_ids = ll_tools_editor_hub_get_word_ids_for_wordset($wordset_id);
    if (empty($word_ids)) {
        return $dataset;
    }

    update_meta_cache('post', $word_ids);

    $pos_by_word = function_exists('ll_tools_word_grid_collect_part_of_speech_terms')
        ? ll_tools_word_grid_collect_part_of_speech_terms($word_ids)
        : [];
    $audio_by_word = function_exists('ll_tools_word_grid_collect_audio_files')
        ? ll_tools_word_grid_collect_audio_files($word_ids, true)
        : [];

    $all_items = [];
    $categories = [];
    foreach ($word_ids as $word_id) {
        $item = ll_tools_editor_hub_build_item((int) $word_id, $wordset_id, $ui_options, (array) $pos_by_word, (array) $audio_by_word);
        if (empty($item) || empty($item['has_missing'])) {
            continue;
        }

        $category = (array) ($item['category'] ?? []);
        $cat_slug = sanitize_title((string) ($category['slug'] ?? 'uncategorized'));
        if ($cat_slug === '') {
            $cat_slug = 'uncategorized';
        }
        $cat_name = (string) ($category['name'] ?? __('Uncategorized', 'll-tools-text-domain'));
        $cat_id = (int) ($category['id'] ?? 0);

        $item['category'] = [
            'id' => $cat_id,
            'slug' => $cat_slug,
            'name' => $cat_name,
        ];

        if (!isset($categories[$cat_slug])) {
            $categories[$cat_slug] = [
                'id' => $cat_id,
                'slug' => $cat_slug,
                'name' => $cat_name,
                'count' => 0,
            ];
        }
        $categories[$cat_slug]['count']++;

        $all_items[] = $item;
    }

    if (empty($categories) || empty($all_items)) {
        return $dataset;
    }

    $dataset['total_missing'] = count($all_items);
    $dataset['categories'] = ll_tools_editor_hub_sort_categories($categories);

    $category_slugs = array_map(static function ($row) {
        return (string) ($row['slug'] ?? '');
    }, $dataset['categories']);

    if ($selected_category === '' || !in_array($selected_category, $category_slugs, true)) {
        $selected_category = (string) ($dataset['categories'][0]['slug'] ?? '');
    }

    $dataset['selected_category'] = $selected_category;

    $items = array_values(array_filter($all_items, static function ($item) use ($selected_category) {
        $category = (array) ($item['category'] ?? []);
        return (string) ($category['slug'] ?? '') === $selected_category;
    }));

    usort($items, static function ($left, $right) {
        $left_word = trim((string) ($left['word_text'] ?? ''));
        $right_word = trim((string) ($right['word_text'] ?? ''));

        if ($left_word === '') {
            $left_word = trim((string) ($left['title'] ?? ''));
        }
        if ($right_word === '') {
            $right_word = trim((string) ($right['title'] ?? ''));
        }

        if ($left_word === '') {
            $left_word = trim((string) ($left['word_translation'] ?? ''));
        }
        if ($right_word === '') {
            $right_word = trim((string) ($right['word_translation'] ?? ''));
        }

        return strnatcasecmp($left_word, $right_word);
    });

    $dataset['items'] = array_values($items);

    return $dataset;
}

function ll_get_editor_hub_items_handler() {
    check_ajax_referer('ll_word_grid_edit', 'nonce');

    if (!ll_tools_editor_hub_user_can_access()) {
        wp_send_json_error(__('Forbidden', 'll-tools-text-domain'), 403);
    }

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    if ($wordset_id <= 0) {
        $wordset_spec = isset($_POST['wordset']) ? sanitize_text_field(wp_unslash((string) $_POST['wordset'])) : '';
        $wordset_id = ll_tools_editor_hub_resolve_wordset_id($wordset_spec);
    }

    if ($wordset_id <= 0) {
        wp_send_json_error(__('Missing word set', 'll-tools-text-domain'), 400);
    }

    $category_slug = isset($_POST['category']) ? sanitize_text_field(wp_unslash((string) $_POST['category'])) : '';

    $dataset = ll_tools_editor_hub_get_dataset($wordset_id, $category_slug);

    wp_send_json_success($dataset);
}
add_action('wp_ajax_ll_get_editor_hub_items', 'll_get_editor_hub_items_handler');

function ll_editor_hub_shortcode($atts) {
    $utility_nav_base = function_exists('ll_tools_render_frontend_user_utility_menu')
        ? ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'editor_hub',
        ])
        : '';

    if (!is_user_logged_in()) {
        return $utility_nav_base . ll_tools_render_login_window([
            'container_class' => 'll-editor-hub ll-login-required',
            'title' => __('Sign in to access Editor Hub', 'll-tools-text-domain'),
            'message' => __('Use an account with editor access to continue.', 'll-tools-text-domain'),
            'submit_label' => __('Continue', 'll-tools-text-domain'),
            'redirect_to' => ll_tools_get_current_request_url(),
        ]);
    }

    if (!ll_tools_editor_hub_user_can_access()) {
        return $utility_nav_base . '<div class="ll-editor-hub"><p>'
            . esc_html__('You do not have permission to access Editor Hub.', 'll-tools-text-domain')
            . '</p></div>';
    }

    $atts = shortcode_atts([
        'wordset' => '',
        'category' => '',
    ], $atts);

    $wordset_id = ll_tools_editor_hub_resolve_wordset_id((string) $atts['wordset']);
    if ($wordset_id <= 0) {
        return $utility_nav_base . '<div class="ll-editor-hub"><p>'
            . esc_html__('No word set is available for Editor Hub.', 'll-tools-text-domain')
            . '</p></div>';
    }

    $dataset = ll_tools_editor_hub_get_dataset($wordset_id, (string) $atts['category']);

    ll_enqueue_asset_by_timestamp('/css/editor-hub.css', 'll-editor-hub-css');
    if (function_exists('ll_tools_enqueue_jquery_ui_autocomplete_assets')) {
        ll_tools_enqueue_jquery_ui_autocomplete_assets();
    }
    ll_enqueue_asset_by_timestamp('/js/editor-hub.js', 'll-editor-hub', ['jquery', 'jquery-ui-autocomplete'], true);

    $current_user = wp_get_current_user();

    wp_localize_script('ll-editor-hub', 'll_editor_hub_data', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ll_word_grid_edit'),
        'wordset_id' => $wordset_id,
        'wordset_name' => (string) ($dataset['wordset_name'] ?? ''),
        'categories' => (array) ($dataset['categories'] ?? []),
        'selected_category' => (string) ($dataset['selected_category'] ?? ''),
        'items' => (array) ($dataset['items'] ?? []),
        'ui_options' => (array) ($dataset['ui_options'] ?? []),
        'i18n' => [
            'word' => __('Word', 'll-tools-text-domain'),
            'translation' => __('Translation', 'll-tools-text-domain'),
            'note' => __('Note', 'll-tools-text-domain'),
            'wrong_answer_options' => __('Wrong answer options (one per line)', 'll-tools-text-domain'),
            'dictionary_entry' => __('Dictionary entry', 'll-tools-text-domain'),
            'dictionary_placeholder' => __('Type to select or create dictionary entry', 'll-tools-text-domain'),
            'part_of_speech' => __('Part of speech', 'll-tools-text-domain'),
            'gender' => __('Gender', 'll-tools-text-domain'),
            'plurality' => __('Plurality', 'll-tools-text-domain'),
            'verb_tense' => __('Verb tense', 'll-tools-text-domain'),
            'verb_mood' => __('Verb mood', 'll-tools-text-domain'),
            'recordings' => __('Recordings', 'll-tools-text-domain'),
            'missing' => __('Missing', 'll-tools-text-domain'),
            'none' => __('None', 'll-tools-text-domain'),
            'loading' => __('Loading…', 'll-tools-text-domain'),
            'saving' => __('Saving…', 'll-tools-text-domain'),
            'saved' => __('Saved.', 'll-tools-text-domain'),
            'save_error' => __('Unable to save changes.', 'll-tools-text-domain'),
            'load_error' => __('Unable to load missing items.', 'll-tools-text-domain'),
            'no_items' => __('No missing items in this category.', 'll-tools-text-domain'),
            'all_done' => __('All missing items are complete.', 'll-tools-text-domain'),
            'skip' => __('Skip', 'll-tools-text-domain'),
            'save_next' => __('Save & Next', 'll-tools-text-domain'),
            'reload' => __('Refresh', 'll-tools-text-domain'),
            'set_label' => __('Set:', 'll-tools-text-domain'),
            'category' => __('Category', 'll-tools-text-domain'),
            'missing_fields' => __('Missing fields', 'll-tools-text-domain'),
            'recording_text' => __('Text', 'll-tools-text-domain'),
            'recording_translation' => __('Translation', 'll-tools-text-domain'),
            'recording_ipa' => __('IPA', 'll-tools-text-domain'),
            'audio' => __('Audio', 'll-tools-text-domain'),
            'image' => __('Image', 'll-tools-text-domain'),
            'no_image' => __('No image available', 'll-tools-text-domain'),
        ],
    ]);

    $categories = (array) ($dataset['categories'] ?? []);
    $selected_category = (string) ($dataset['selected_category'] ?? '');
    $utility_nav = function_exists('ll_tools_render_frontend_user_utility_menu')
        ? ll_tools_render_frontend_user_utility_menu([
            'current_area' => 'editor_hub',
            'wordset' => $wordset_id,
        ])
        : '';

    ob_start();
    ?>
    <?php echo $utility_nav; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    <div class="ll-editor-hub" data-ll-editor-hub>
        <div class="ll-editor-hub-header">
            <div class="ll-editor-hub-progress" aria-label="<?php echo esc_attr__('Progress', 'll-tools-text-domain'); ?>">
                <span data-ll-current-num><?php echo !empty($dataset['items']) ? 1 : 0; ?></span>
                /
                <span data-ll-total-num><?php echo (int) count((array) ($dataset['items'] ?? [])); ?></span>
            </div>

            <div class="ll-editor-hub-category-selector">
                <label class="screen-reader-text" for="ll-editor-hub-category-select"><?php esc_html_e('Category', 'll-tools-text-domain'); ?></label>
                <select id="ll-editor-hub-category-select" data-ll-category-select>
                    <?php foreach ($categories as $category_row): ?>
                        <?php
                        $slug = sanitize_title((string) ($category_row['slug'] ?? ''));
                        if ($slug === '') {
                            continue;
                        }
                        $name = (string) ($category_row['name'] ?? $slug);
                        $count = (int) ($category_row['count'] ?? 0);
                        ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_category, $slug); ?>>
                            <?php echo esc_html($name . ' (' . $count . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($dataset['wordset_name'])): ?>
                <div class="ll-editor-hub-wordset">
                    <span><?php esc_html_e('Set:', 'll-tools-text-domain'); ?></span>
                    <strong><?php echo esc_html((string) $dataset['wordset_name']); ?></strong>
                </div>
            <?php endif; ?>

            <div class="ll-editor-hub-user"><?php echo esc_html((string) $current_user->display_name); ?></div>
        </div>

        <div class="ll-editor-hub-status" data-ll-status aria-live="polite"></div>

        <div class="ll-editor-hub-card" data-ll-card></div>

        <div class="ll-editor-hub-actions">
            <button type="button" class="ll-editor-hub-btn ll-editor-hub-btn--ghost" data-ll-reload>
                <?php esc_html_e('Refresh', 'll-tools-text-domain'); ?>
            </button>
            <button type="button" class="ll-editor-hub-btn ll-editor-hub-btn--secondary" data-ll-skip>
                <?php esc_html_e('Skip', 'll-tools-text-domain'); ?>
            </button>
            <button type="button" class="ll-editor-hub-btn ll-editor-hub-btn--primary" data-ll-save>
                <?php esc_html_e('Save & Next', 'll-tools-text-domain'); ?>
            </button>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('editor_hub', 'll_editor_hub_shortcode');
