<?php
// /includes/user-study.php
if (!defined('WPINC')) { die; }

define('LL_TOOLS_USER_WORDSET_META', 'll_user_study_wordset');
define('LL_TOOLS_USER_CATEGORY_META', 'll_user_study_categories');
define('LL_TOOLS_USER_STARRED_META', 'll_user_study_starred');
define('LL_TOOLS_USER_FAST_TRANSITIONS_META', 'll_user_fast_transitions');

if (!function_exists('ll_tools_normalize_star_mode')) {
    function ll_tools_normalize_star_mode($mode): string {
        $mode = is_string($mode) ? $mode : '';
        $allowed = ['weighted', 'only', 'normal'];
        return in_array($mode, $allowed, true) ? $mode : 'normal';
    }
}

/**
 * Read the saved study state for a user.
 */
function ll_tools_get_user_study_state($user_id = 0): array {
    $uid = $user_id ?: get_current_user_id();
    $wordset_id = (int) get_user_meta($uid, LL_TOOLS_USER_WORDSET_META, true);
    $category_ids = (array) get_user_meta($uid, LL_TOOLS_USER_CATEGORY_META, true);
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) { return $id > 0; }));
    $starred_word_ids = (array) get_user_meta($uid, LL_TOOLS_USER_STARRED_META, true);
    $starred_word_ids = array_values(array_filter(array_map('intval', $starred_word_ids), function ($id) { return $id > 0; }));
    $star_mode_raw = get_user_meta($uid, 'll_user_star_mode', true) ?: 'normal';
    $star_mode = ll_tools_normalize_star_mode($star_mode_raw);
    $fast_raw = get_user_meta($uid, LL_TOOLS_USER_FAST_TRANSITIONS_META, true);
    $fast_transitions = filter_var($fast_raw, FILTER_VALIDATE_BOOLEAN);

    return [
        'wordset_id'       => $wordset_id,
        'category_ids'     => $category_ids,
        'starred_word_ids' => $starred_word_ids,
        'star_mode'        => $star_mode,
        'fast_transitions' => $fast_transitions,
    ];
}

/**
 * Save the study state for a user.
 */
function ll_tools_save_user_study_state(array $state, $user_id = 0): array {
    $uid = $user_id ?: get_current_user_id();
    $wordset_id   = isset($state['wordset_id']) ? (int) $state['wordset_id'] : 0;
    $category_ids = isset($state['category_ids']) ? (array) $state['category_ids'] : [];
    $starred_ids  = isset($state['starred_word_ids']) ? (array) $state['starred_word_ids'] : [];
    $star_mode    = ll_tools_normalize_star_mode(isset($state['star_mode']) ? (string) $state['star_mode'] : 'normal');
    $fast_raw     = isset($state['fast_transitions']) ? $state['fast_transitions'] : false;
    $fast_transitions = filter_var($fast_raw, FILTER_VALIDATE_BOOLEAN);

    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) { return $id > 0; }));
    $starred_ids  = array_values(array_filter(array_map('intval', $starred_ids), function ($id) { return $id > 0; }));

    update_user_meta($uid, LL_TOOLS_USER_WORDSET_META, $wordset_id);
    update_user_meta($uid, LL_TOOLS_USER_CATEGORY_META, $category_ids);
    update_user_meta($uid, LL_TOOLS_USER_STARRED_META, $starred_ids);
    update_user_meta($uid, 'll_user_star_mode', $star_mode);
    update_user_meta($uid, LL_TOOLS_USER_FAST_TRANSITIONS_META, $fast_transitions ? 1 : 0);

    return [
        'wordset_id'       => $wordset_id,
        'category_ids'     => $category_ids,
        'starred_word_ids' => $starred_ids,
        'star_mode'        => $star_mode,
        'fast_transitions' => $fast_transitions,
    ];
}

/**
 * List available wordsets for selection.
 */
function ll_tools_user_study_wordsets(): array {
    $terms = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms)) {
        return [];
    }

    $out = [];
    foreach ($terms as $term) {
        $out[] = [
            'id'   => (int) $term->term_id,
            'name' => html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'),
            'slug' => (string) $term->slug,
        ];
    }
    return $out;
}

/**
 * Build category data (mirrors flashcard widget structure) for a wordset scope.
 */
function ll_tools_user_study_categories_for_wordset($wordset_id): array {
    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $use_translations = function_exists('ll_flashcards_should_use_translations') ? ll_flashcards_should_use_translations() : false;
    if (!function_exists('ll_flashcards_build_categories')) {
        return [];
    }
    [$categories] = ll_flashcards_build_categories('', $use_translations, $wordset_ids);
    return array_map(function ($cat) {
        $cat['id']    = (int) $cat['id'];
        $cat['name']  = (string) $cat['name'];
        $cat['slug']  = (string) $cat['slug'];
        $cat['word_count'] = isset($cat['word_count']) ? (int) $cat['word_count'] : 0;
        $cat['gender_supported'] = !empty($cat['gender_supported']);
        return $cat;
    }, $categories);
}

/**
 * Fetch words for a set of category IDs, scoped to a wordset if provided.
 */
function ll_tools_user_study_words(array $category_ids, $wordset_id): array {
    $category_ids = array_values(array_filter(array_map('intval', $category_ids), function ($id) { return $id > 0; }));
    if (empty($category_ids)) {
        return [];
    }

    $wordset_ids = $wordset_id ? [(int) $wordset_id] : [];
    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }
    $by_id = [];
    foreach ($terms as $t) {
        $by_id[(int) $t->term_id] = $t;
    }

    $result = [];
    foreach ($category_ids as $cid) {
        if (!isset($by_id[$cid])) {
            continue;
        }
        $term = $by_id[$cid];
        $config = function_exists('ll_tools_get_category_quiz_config')
            ? ll_tools_get_category_quiz_config($term)
            : ['prompt_type' => 'audio', 'option_type' => 'image'];
        $option_type = isset($config['option_type']) ? $config['option_type'] : 'image';
        $prompt_type = isset($config['prompt_type']) ? $config['prompt_type'] : 'audio';
        $merged_config = array_merge($config, [
            'option_type' => $option_type,
            'prompt_type' => $prompt_type,
        ]);
        $words_raw = ll_get_words_by_category($term->name, $option_type, $wordset_ids, $merged_config);
        $result[$cid] = array_map(function ($w) use ($term) {
            $word_id = (int) ($w['id'] ?? 0);
            $title = isset($w['title']) ? (string) $w['title'] : '';
            $translation = '';
            if ($word_id > 0) {
                $translation = trim((string) get_post_meta($word_id, 'word_translation', true));
                if ($translation === '') {
                    $translation = trim((string) get_post_meta($word_id, 'word_english_meaning', true));
                }
            }
            return [
                'id'             => $word_id,
                'title'          => $title,
                'translation'    => html_entity_decode((string) $translation, ENT_QUOTES, 'UTF-8'),
                'label'          => isset($w['label']) ? (string) $w['label'] : '',
                'image'          => isset($w['image']) ? (string) $w['image'] : '',
                'audio'          => isset($w['audio']) ? (string) $w['audio'] : '',
                'audio_files'    => isset($w['audio_files']) ? (array) $w['audio_files'] : [],
                'preferred_speaker_user_id' => isset($w['preferred_speaker_user_id']) ? (int) $w['preferred_speaker_user_id'] : 0,
                'all_categories' => isset($w['all_categories']) ? (array) $w['all_categories'] : [$term->name],
                'wordset_ids'    => isset($w['wordset_ids']) ? (array) $w['wordset_ids'] : [],
            ];
        }, $words_raw);
    }

    return $result;
}

/**
 * Build a payload for bootstrapping the dashboard.
 */
function ll_tools_build_user_study_payload($user_id = 0, $requested_wordset_id = 0, $requested_categories = []) {
    $uid = $user_id ?: get_current_user_id();
    $state = ll_tools_get_user_study_state($uid);
    $wordset_id = $requested_wordset_id ? (int) $requested_wordset_id : (int) $state['wordset_id'];
    if ($wordset_id <= 0 && function_exists('ll_tools_get_active_wordset_id')) {
        $wordset_id = (int) ll_tools_get_active_wordset_id();
    }

    $wordsets = ll_tools_user_study_wordsets();
    $categories = ll_tools_user_study_categories_for_wordset($wordset_id);
    $category_lookup = [];
    foreach ($categories as $cat) {
        $category_lookup[(int) $cat['id']] = true;
    }

    $selected_category_ids = $requested_categories ? (array) $requested_categories : $state['category_ids'];
    $selected_category_ids = array_values(array_filter(array_map('intval', $selected_category_ids), function ($id) use ($category_lookup) {
        return $id > 0 && isset($category_lookup[$id]);
    }));
    if (empty($selected_category_ids) && !empty($categories)) {
        $selected_category_ids = array_slice(array_column($categories, 'id'), 0, 3);
    }

    $words_by_category = ll_tools_user_study_words($selected_category_ids, $wordset_id);

    $gender_enabled = false;
    $gender_options = [];
    if ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender')) {
        $gender_enabled = ll_tools_wordset_has_grammatical_gender($wordset_id);
    }
    if ($gender_enabled && function_exists('ll_tools_wordset_get_gender_options')) {
        $gender_options = ll_tools_wordset_get_gender_options($wordset_id);
    }
    $gender_options = array_values(array_filter(array_map('strval', (array) $gender_options), function ($val) {
        return $val !== '';
    }));
    $gender_min_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);

    return [
        'wordsets'          => $wordsets,
        'categories'        => $categories,
        'gender'            => [
            'enabled'   => (bool) $gender_enabled,
            'options'   => $gender_options,
            'min_count' => $gender_min_count,
        ],
        'state'             => [
            'wordset_id'       => $wordset_id,
            'category_ids'     => $selected_category_ids,
            'starred_word_ids' => $state['starred_word_ids'],
            'star_mode'        => ll_tools_normalize_star_mode($state['star_mode'] ?? 'normal'),
            'fast_transitions' => !empty($state['fast_transitions']),
        ],
        'words_by_category' => $words_by_category,
    ];
}

/**
 * AJAX: bootstrap data.
 */
function ll_tools_user_study_bootstrap_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $payload = ll_tools_build_user_study_payload(get_current_user_id(), $wordset_id, $category_ids);
    wp_send_json_success($payload);
}
add_action('wp_ajax_ll_user_study_bootstrap', 'll_tools_user_study_bootstrap_ajax');

/**
 * AJAX: fetch words for specific categories (used when user toggles selections).
 */
function ll_tools_user_study_fetch_words_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $words = ll_tools_user_study_words($category_ids, $wordset_id);
    wp_send_json_success(['words_by_category' => $words]);
}
add_action('wp_ajax_ll_user_study_fetch_words', 'll_tools_user_study_fetch_words_ajax');

/**
 * AJAX: save selections (wordset, categories, starred words).
 */
function ll_tools_user_study_save_ajax() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => __('Login required.', 'll-tools-text-domain')], 401);
    }
    check_ajax_referer('ll_user_study', 'nonce');

    $wordset_id   = isset($_POST['wordset_id']) ? (int) $_POST['wordset_id'] : 0;
    $category_ids = isset($_POST['category_ids']) ? (array) $_POST['category_ids'] : [];
    $starred_ids  = isset($_POST['starred_word_ids']) ? (array) $_POST['starred_word_ids'] : [];
    $star_mode    = ll_tools_normalize_star_mode(isset($_POST['star_mode']) ? sanitize_text_field($_POST['star_mode']) : 'normal');
    $fast_transitions = filter_var($_POST['fast_transitions'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $payload = ll_tools_save_user_study_state([
        'wordset_id'       => $wordset_id,
        'category_ids'     => $category_ids,
        'starred_word_ids' => $starred_ids,
        'star_mode'        => $star_mode,
        'fast_transitions' => $fast_transitions,
    ]);

    wp_send_json_success(['state' => $payload]);
}
add_action('wp_ajax_ll_user_study_save', 'll_tools_user_study_save_ajax');
