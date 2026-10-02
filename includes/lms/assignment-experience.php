<?php
/** Bounded vocabulary assignment authoring, player, and answer-to-progress bridge. */
if (!defined('WPINC')) { die; }

function ll_tools_lms_assignment_experience_error(string $code, string $message, int $status = 400): WP_Error {
    return new WP_Error($code, $message, ['status' => $status]);
}

/** Only inert text and explicit HTTP(S) media are stored/rendered. */
function ll_tools_lms_assignment_normalize_presentation($raw) {
    if (!is_array($raw)) {
        return ll_tools_lms_assignment_experience_error('invalid_assignment_presentation', __('The question presentation is invalid.', 'll-tools-text-domain'));
    }
    $result = ['text' => '', 'image' => '', 'audio' => ''];
    foreach ($result as $key => $unused) {
        if (!isset($raw[$key]) || !is_string($raw[$key]) || strlen($raw[$key]) > ($key === 'text' ? 4096 : 2048)) {
            return ll_tools_lms_assignment_experience_error('invalid_assignment_presentation', __('The question presentation is invalid.', 'll-tools-text-domain'));
        }
        if ($key === 'text') {
            $result[$key] = sanitize_text_field($raw[$key]);
        } elseif ($raw[$key] !== '') {
            $url = esc_url_raw($raw[$key], ['https', 'http']);
            $parts = wp_parse_url($url);
            if ($url === '' || !is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
                return ll_tools_lms_assignment_experience_error('invalid_assignment_media', __('The question media is invalid.', 'll-tools-text-domain'));
            }
            $result[$key] = $url;
        }
    }
    if (implode('', $result) === '') {
        return ll_tools_lms_assignment_experience_error('empty_assignment_presentation', __('A question or choice has no usable presentation.', 'll-tools-text-domain'));
    }
    return $result;
}

/** Schema 1 remains the headless scoring contract; schema 2 adds frozen vocabulary. */
function ll_tools_lms_assignment_normalize_vocabulary_manifest(array $manifest) {
    if (($manifest['kind'] ?? '') !== 'closed_response' || !isset($manifest['items']) || !is_array($manifest['items']) || count($manifest['items']) < 5 || count($manifest['items']) > 15) {
        return ll_tools_lms_assignment_experience_error('invalid_vocabulary_assignment', __('Vocabulary assignments require 5 to 15 questions.', 'll-tools-text-domain'));
    }
    $items = [];
    $keys = [];
    foreach ($manifest['items'] as $item) {
        if (!is_array($item) || !isset($item['options']) || !is_array($item['options']) || count($item['options']) < 2 || count($item['options']) > 4) {
            return ll_tools_lms_assignment_experience_error('invalid_vocabulary_assignment', __('Each vocabulary question needs 2 to 4 distinct choices.', 'll-tools-text-domain'));
        }
        $key = ll_tools_lms_assignment_normalize_key($item['key'] ?? null);
        if ($key === '' || isset($keys[$key])) {
            return ll_tools_lms_assignment_experience_error('invalid_assignment_item_key', __('Assignment question keys must be unique.', 'll-tools-text-domain'));
        }
        $keys[$key] = true;
        $prompt = ll_tools_lms_assignment_normalize_presentation($item['prompt'] ?? null);
        if (is_wp_error($prompt)) { return $prompt; }
        $raw_vocab = $item['vocabulary'] ?? null;
        if (!is_array($raw_vocab)) {
            return ll_tools_lms_assignment_experience_error('invalid_assignment_vocabulary', __('The vocabulary mapping is invalid.', 'll-tools-text-domain'));
        }
        $vocabulary = [];
        foreach (['word_id', 'category_id', 'wordset_id'] as $field) {
            if (!isset($raw_vocab[$field]) || !is_int($raw_vocab[$field]) || $raw_vocab[$field] <= 0) {
                return ll_tools_lms_assignment_experience_error('invalid_assignment_vocabulary', __('The vocabulary mapping is invalid.', 'll-tools-text-domain'));
            }
            $vocabulary[$field] = $raw_vocab[$field];
        }
        foreach (['recording_type', 'prompt_type', 'option_type'] as $field) {
            if (!isset($raw_vocab[$field]) || !is_string($raw_vocab[$field]) || strlen($raw_vocab[$field]) > 64) {
                return ll_tools_lms_assignment_experience_error('invalid_assignment_vocabulary', __('The vocabulary mapping is invalid.', 'll-tools-text-domain'));
            }
            $vocabulary[$field] = sanitize_key($raw_vocab[$field]);
        }
        $raw_types = $raw_vocab['available_recording_types'] ?? null;
        if (!is_array($raw_types) || count($raw_types) > 8 || count(array_filter($raw_types, 'is_string')) !== count($raw_types)) {
            return ll_tools_lms_assignment_experience_error('invalid_assignment_vocabulary', __('The vocabulary mapping is invalid.', 'll-tools-text-domain'));
        }
        $vocabulary['available_recording_types'] = ll_tools_sort_practice_recording_types(array_map('sanitize_key', $raw_types));
        if ($prompt['audio'] === '' && ($vocabulary['recording_type'] !== '' || $vocabulary['available_recording_types'] !== [])) {
            return ll_tools_lms_assignment_experience_error('invalid_assignment_vocabulary', __('Audio progress requires an audio prompt.', 'll-tools-text-domain'));
        }
        if ($vocabulary['recording_type'] !== '' && !in_array($vocabulary['recording_type'], $vocabulary['available_recording_types'], true)) {
            return ll_tools_lms_assignment_experience_error('invalid_assignment_vocabulary', __('The prompt recording type is invalid.', 'll-tools-text-domain'));
        }
        $options = [];
        $option_keys = [];
        $presentations = [];
        $correct_count = 0;
        foreach ($item['options'] as $option) {
            $option_key = is_array($option) ? ll_tools_lms_assignment_normalize_key($option['key'] ?? null) : '';
            if ($option_key === '' || isset($option_keys[$option_key]) || !is_bool($option['correct'] ?? null)) {
                return ll_tools_lms_assignment_experience_error('invalid_assignment_option_key', __('Assignment choice keys must be unique.', 'll-tools-text-domain'));
            }
            $presentation = ll_tools_lms_assignment_normalize_presentation($option['presentation'] ?? null);
            if (is_wp_error($presentation)) { return $presentation; }
            $identity = hash('sha256', wp_json_encode($presentation));
            if (isset($presentations[$identity])) {
                return ll_tools_lms_assignment_experience_error('duplicate_assignment_choice', __('Two answer choices look or sound identical.', 'll-tools-text-domain'));
            }
            $presentations[$identity] = true;
            $option_keys[$option_key] = true;
            $correct_count += $option['correct'] ? 1 : 0;
            $options[] = ['key' => $option_key, 'correct' => $option['correct'], 'presentation' => $presentation];
        }
        if ($correct_count !== 1) {
            return ll_tools_lms_assignment_experience_error('assignment_correct_option_count', __('Each question must have exactly one correct choice.', 'll-tools-text-domain'));
        }
        $items[] = ['key' => $key, 'prompt' => $prompt, 'vocabulary' => $vocabulary, 'options' => $options];
    }
    return ['schema' => 2, 'kind' => 'closed_response', 'items' => $items];
}

/** Never expose correctness, source word IDs, or the semantic answer mapping. */
function ll_tools_lms_assignment_public_vocabulary_manifest(array $manifest): array {
    $items = [];
    foreach ($manifest['items'] as $item) {
        $options = [];
        foreach ($item['options'] as $option) {
            $options[] = ['key' => $option['key'], 'presentation' => $option['presentation']];
        }
        $items[] = ['key' => $item['key'], 'prompt' => $item['prompt'], 'options' => $options];
    }
    return ['schema' => 2, 'kind' => 'closed_response', 'items' => $items];
}

function ll_tools_lms_assignment_vocabulary_access(array $manifest, int $user_id): bool {
    $seen = [];
    foreach ((array) ($manifest['items'] ?? []) as $item) {
        $vocab = $item['vocabulary'] ?? [];
        $wordset = (int) ($vocab['wordset_id'] ?? 0);
        $category = (int) ($vocab['category_id'] ?? 0);
        $key = $wordset . ':' . $category;
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $complete = true;
        if (!ll_tools_user_can_view_wordset($wordset, $user_id, $complete) || !$complete) { return false; }
        $complete = true;
        if (!ll_tools_user_can_view_category($category, $user_id, $complete) || !$complete) { return false; }
    }
    return $seen !== [];
}

/** Raw native authoring cannot smuggle a foreign word into a class snapshot. */
function ll_tools_lms_assignment_validate_vocabulary_scope(array $manifest, int $wordset_id, int $actor_user_id) {
    if (!ll_tools_lms_assignment_vocabulary_access($manifest, $actor_user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_content_forbidden', __('This assignment content is unavailable.', 'll-tools-text-domain'), 403);
    }
    $seen_words = [];
    foreach ($manifest['items'] as $item) {
        $vocab = $item['vocabulary'];
        $word = get_post($vocab['word_id']);
        $complete = true;
        $owner = ll_tools_get_category_wordset_owner_id($vocab['category_id'], $complete);
        $wordsets = wp_get_post_terms($vocab['word_id'], 'wordset', ['fields' => 'ids']);
        $categories = wp_get_post_terms($vocab['word_id'], 'word-category', ['fields' => 'ids']);
        if ($vocab['wordset_id'] !== $wordset_id || !$complete || ($owner > 0 && $owner !== $wordset_id) || !($word instanceof WP_Post) || $word->post_type !== 'words' || $word->post_status !== 'publish' || is_wp_error($wordsets) || is_wp_error($categories) || !in_array($wordset_id, array_map('intval', (array) $wordsets), true) || !in_array($vocab['category_id'], array_map('intval', (array) $categories), true) || isset($seen_words[$vocab['word_id']])) {
            return ll_tools_lms_assignment_experience_error('assignment_word_scope', __('Every assignment question must map to a distinct current word in this class wordset and category.', 'll-tools-text-domain'), 403);
        }
        $seen_words[$vocab['word_id']] = true;
    }
    return true;
}

/** Safe bounded media/text projection from existing canonical flashcard rows. */
function ll_tools_lms_assignment_word_presentation(array $word, string $type, bool $prompt, bool $use_titles = false): array {
    $text = '';
    $image = '';
    $audio = '';
    $text_type = $prompt ? ll_tools_get_quiz_prompt_text_type($type, $use_titles) : (in_array($type, ['text_title', 'text'], true) ? 'text_title' : (in_array($type, ['text_translation', 'image_text_translation', 'text_audio'], true) ? 'text_translation' : ''));
    if ($text_type !== '') {
        $text = $text_type === 'text_title' ? (string) ($word['title'] ?? '') : (string) ($word['translation'] ?? '');
    }
    if (($prompt && ll_tools_quiz_prompt_type_has_image($type)) || (!$prompt && ll_tools_quiz_option_type_has_image($type))) {
        $image = ll_tools_resolve_image_file_url((string) ($word['image'] ?? ''));
    }
    if (($prompt && ll_tools_quiz_prompt_type_has_audio($type)) || (!$prompt && in_array($type, ['audio', 'text_audio'], true))) {
        $audio = (string) ($word['audio'] ?? '');
    }
    return ['text' => sanitize_text_field($text), 'image' => $image, 'audio' => $audio];
}

/** Reject excluded/deduplicated distractors instead of degrading quiz semantics. */
function ll_tools_lms_assignment_distractor_allowed(array $target, array $other, string $recording_type): bool {
    $other_id = (int) $other['id'];
    $target_id = (int) $target['id'];
    if ($target_id === $other_id || !empty($other['is_specific_wrong_answer_only']) || !empty($other['is_prompt_card_support_only'])) { return false; }
    $specific = array_map('intval', (array) ($target['specific_wrong_answer_ids'] ?? []));
    if ($specific !== [] && !in_array($other_id, $specific, true)) { return false; }
    if (!empty($target['specific_wrong_answer_texts'])) { return false; }
    foreach ([[$target, $other_id], [$other, $target_id]] as $pair) {
        if (in_array($pair[1], array_map('intval', (array) ($pair[0]['option_blocked_ids'] ?? [])), true)) { return false; }
        if ($recording_type !== '' && in_array($pair[1], array_map('intval', (array) ($pair[0]['option_blocked_ids_by_recording_type'][$recording_type] ?? [])), true)) { return false; }
    }
    $groups = (array) ($target['option_groups'] ?? []);
    if ($groups !== [] && array_intersect($groups, (array) ($other['option_groups'] ?? [])) === []) { return false; }
    return true;
}

/** Create one draft from an exact category and at most fifteen selected words. */
function ll_tools_lms_assignment_create_from_category(int $class_id, int $category_id, array $options = [], int $actor_user_id = 0) {
    global $wpdb;
    $actor_user_id = $actor_user_id ?: get_current_user_id();
    if (!ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_forbidden', __('You cannot manage this class.', 'll-tools-text-domain'), 403);
    }
    $wordset_id = ll_tools_teacher_class_get_wordset_id($class_id);
    $category = get_term($category_id, 'word-category');
    $complete = true;
    if (!($category instanceof WP_Term) || !ll_tools_user_can_view_category($category, $actor_user_id, $complete) || !$complete) {
        return ll_tools_lms_assignment_experience_error('assignment_category_forbidden', __('This category is unavailable.', 'll-tools-text-domain'), 403);
    }
    $complete = true;
    $owner = ll_tools_get_category_wordset_owner_id($category, $complete);
    if (!$complete || ($owner > 0 && $owner !== $wordset_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_category_scope', __('Choose a category in this class wordset.', 'll-tools-text-domain'), 403);
    }
    $selected = $options['word_ids'] ?? [];
    if (!is_array($selected) || count($selected) > 15 || count(array_filter($selected, static function ($id) { return is_int($id) && $id > 0; })) !== count($selected) || count(array_unique($selected)) !== count($selected)) {
        return ll_tools_lms_assignment_experience_error('assignment_word_scope', __('Choose 5 to 15 distinct words.', 'll-tools-text-domain'));
    }
    $args = ['post_type' => 'words', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 16, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'tax_query' => ['relation' => 'AND', ['taxonomy' => 'word-category', 'field' => 'term_id', 'terms' => [$category_id], 'include_children' => false], ['taxonomy' => 'wordset', 'field' => 'term_id', 'terms' => [$wordset_id], 'include_children' => false]]];
    if ($selected !== []) { $args['post__in'] = $selected; }
    $wpdb->last_error = '';
    $query = new WP_Query($args);
    $ids = array_map('intval', (array) $query->posts);
    if ($wpdb->last_error !== '') { return ll_tools_lms_assignment_experience_error('assignment_source_unavailable', __('The vocabulary could not be read. Please try again.', 'll-tools-text-domain'), 503); }
    sort($ids);
    $selected_compare = $selected;
    sort($selected_compare);
    if (count($ids) < 5 || count($ids) > 15 || ($selected !== [] && $selected_compare !== $ids)) {
        return ll_tools_lms_assignment_experience_error('assignment_word_scope', __('The category must have 5 to 15 usable words, or select an exact 5 to 15 word subset.', 'll-tools-text-domain'));
    }
    $complete = true;
    $config = ll_tools_get_category_quiz_config($category, $complete);
    if (!$complete) { return ll_tools_lms_assignment_experience_error('assignment_source_unavailable', __('The quiz configuration could not be read.', 'll-tools-text-domain'), 503); }
    $config['__candidate_word_ids'] = $ids;
    // This vocabulary pilot excludes prompt cards. Supplying an explicit empty
    // reference set prevents the generic hydrator scanning a large category's
    // prompt-card catalog during this bounded authoring request.
    $config['__prompt_card_reference_rows'] = [];
    $config['__skip_image_similarity_pairs'] = false;
    $rows = ll_get_words_by_category($category, (string) $config['option_type'], [$wordset_id], $config, $complete);
    if (!$complete || count($rows) !== count($ids)) { return ll_tools_lms_assignment_experience_error('assignment_source_unavailable', __('Every selected word needs the category’s configured quiz media.', 'll-tools-text-domain'), 409); }
    $by_id = [];
    foreach ($rows as $word) {
        if (!empty($word['is_prompt_card']) || !empty($word['is_prompt_card_support_only']) || !empty($word['is_specific_wrong_answer_only'])) {
            return ll_tools_lms_assignment_experience_error('assignment_unsupported_word', __('This pilot supports ordinary vocabulary words only.', 'll-tools-text-domain'));
        }
        $by_id[(int) $word['id']] = $word;
    }
    if (array_diff($ids, array_keys($by_id)) !== []) { return ll_tools_lms_assignment_experience_error('assignment_word_scope', __('The selected words changed. Please retry.', 'll-tools-text-domain'), 409); }
    $items = [];
    foreach ($ids as $id) {
        $target = $by_id[$id];
        $prompt = ll_tools_lms_assignment_word_presentation($target, (string) $config['prompt_type'], true, !empty($config['use_titles']));
        $recording_type = '';
        if ($prompt['audio'] !== '') {
            foreach ((array) ($target['audio_files'] ?? []) as $audio) {
                if ((string) ($audio['url'] ?? '') === $prompt['audio']) { $recording_type = ll_tools_normalize_practice_recording_type_slug($audio['recording_type'] ?? ''); break; }
            }
        }
        $correct_presentation = ll_tools_lms_assignment_word_presentation($target, (string) $config['option_type'], false, !empty($config['use_titles']));
        $answer_options = [['key' => wp_generate_uuid4(), 'correct' => true, 'presentation' => $correct_presentation]];
        $identities = [hash('sha256', wp_json_encode($correct_presentation)) => true];
        foreach ($ids as $other_id) {
            $other = $by_id[$other_id];
            if (!ll_tools_lms_assignment_distractor_allowed($target, $other, $recording_type)) { continue; }
            if (ll_tools_quiz_option_type_has_image((string) $config['option_type'])) {
                $same_image = !empty($target['image_attachment_id']) && (int) $target['image_attachment_id'] === (int) ($other['image_attachment_id'] ?? 0);
                $hash_a = (string) ($target['option_image_hash'] ?? '');
                $hash_b = (string) ($other['option_image_hash'] ?? '');
                if ($same_image || ($hash_a !== '' && $hash_a === $hash_b)) { continue; }
            }
            $presentation = ll_tools_lms_assignment_word_presentation($other, (string) $config['option_type'], false, !empty($config['use_titles']));
            $identity = hash('sha256', wp_json_encode($presentation));
            if (isset($identities[$identity]) || is_wp_error(ll_tools_lms_assignment_normalize_presentation($presentation))) { continue; }
            $identities[$identity] = true;
            $answer_options[] = ['key' => wp_generate_uuid4(), 'correct' => false, 'presentation' => $presentation];
            if (count($answer_options) === 4) { break; }
        }
        if (count($answer_options) < 2) { return ll_tools_lms_assignment_experience_error('assignment_options_unavailable', __('A selected word has no distinct allowed distractor.', 'll-tools-text-domain')); }
        shuffle($answer_options);
        $items[] = ['key' => wp_generate_uuid4(), 'prompt' => $prompt, 'vocabulary' => ['word_id' => $id, 'category_id' => $category_id, 'wordset_id' => $wordset_id, 'recording_type' => $recording_type, 'prompt_type' => (string) $config['prompt_type'], 'option_type' => (string) $config['option_type'], 'available_recording_types' => $prompt['audio'] === '' ? [] : array_values((array) ($target['practice_recording_types'] ?? []))], 'options' => $answer_options];
    }
    shuffle($items);
    $input = array_intersect_key($options, array_flip(['title', 'points_maximum', 'attempt_limit', 'grade_policy', 'available_at', 'due_at']));
    $input['title'] = isset($input['title']) && is_string($input['title']) && trim($input['title']) !== '' ? $input['title'] : (string) $category->name;
    $input['manifest'] = ['schema' => 2, 'kind' => 'closed_response', 'items' => $items];
    $created = ll_tools_lms_assignment_create($class_id, $input, $actor_user_id);
    return is_wp_error($created) ? $created : ll_tools_lms_assignment_get((int) $created);
}

/** Class-owned category picker: ID-only SQL aggregate, at most 100 hydrated terms. */
function ll_tools_lms_assignment_category_choices(int $class_id, int $actor_user_id = 0) {
    global $wpdb;
    $actor_user_id = $actor_user_id ?: get_current_user_id();
    if (!ll_tools_lms_assignment_user_can_manage_class($class_id, $actor_user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_forbidden', __('You cannot manage this class.', 'll-tools-text-domain'), 403);
    }
    $wordset_id = ll_tools_teacher_class_get_wordset_id($class_id);
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT cat.term_id FROM {$wpdb->term_taxonomy} cat INNER JOIN {$wpdb->term_relationships} cr ON cr.term_taxonomy_id=cat.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID=cr.object_id INNER JOIN {$wpdb->term_relationships} wr ON wr.object_id=p.ID INNER JOIN {$wpdb->term_taxonomy} ws ON ws.term_taxonomy_id=wr.term_taxonomy_id WHERE cat.taxonomy='word-category' AND ws.taxonomy='wordset' AND ws.term_id=%d AND p.post_type='words' AND p.post_status='publish' ORDER BY cat.term_id ASC LIMIT 101", $wordset_id));
    if ($wpdb->last_error !== '') { return ll_tools_lms_assignment_experience_error('assignment_source_unavailable', __('Categories could not be read.', 'll-tools-text-domain'), 503); }
    $choices = [];
    foreach (array_slice((array) $ids, 0, 100) as $category_id) {
        $category = get_term((int) $category_id, 'word-category');
        $complete = true;
        if (!($category instanceof WP_Term) || !ll_tools_user_can_view_category($category, $actor_user_id, $complete) || !$complete) { continue; }
        $complete = true;
        $owner = ll_tools_get_category_wordset_owner_id($category, $complete);
        if (!$complete) { return ll_tools_lms_assignment_experience_error('assignment_source_unavailable', __('Category ownership could not be read.', 'll-tools-text-domain'), 503); }
        if ($owner > 0 && $owner !== $wordset_id) { continue; }
        $choices[] = ['id' => (int) $category->term_id, 'name' => (string) $category->name, 'slug' => (string) $category->slug];
    }
    return $choices;
}

/** Caller holds advisory lock and answer transaction; event savepoints are nested. */
function ll_tools_lms_assignment_project_answer_progress(int $user_id, array $attempt, array $item, bool $correct, string $answered_at) {
    $vocab = $item['vocabulary'];
    if (!ll_tools_lms_assignment_vocabulary_access(['items' => [$item]], $user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_content_forbidden', __('This assignment content is unavailable.', 'll-tools-text-domain'), 403);
    }
    $base = ['mode' => 'practice', 'word_id' => $vocab['word_id'], 'category_id' => $vocab['category_id'], 'wordset_id' => $vocab['wordset_id'], 'client_created_at' => $answered_at];
    $identity = (string) $attempt['attempt_uuid'] . ':' . (string) $item['key'];
    $payload = ['event_source' => 'lms_assignment', 'prompt_type' => $vocab['prompt_type']];
    if ($vocab['recording_type'] !== '') {
        $payload['recording_type'] = $vocab['recording_type'];
        $payload['available_recording_types'] = $vocab['available_recording_types'];
    }
    $events = [
        array_merge($base, ['event_uuid' => 'lms-e:' . substr(hash('sha256', $identity), 0, 58), 'event_type' => 'word_exposure', 'payload' => $payload]),
        array_merge($base, ['event_uuid' => 'lms-o:' . substr(hash('sha256', $identity), 0, 58), 'event_type' => 'word_outcome', 'is_correct' => $correct, 'had_wrong_before' => false, 'payload' => $payload]),
    ];
    $stats = ll_tools_process_progress_events_batch_locked($user_id, $events);
    if ((int) ($stats['failed'] ?? 0) !== 0 || (int) ($stats['invalid'] ?? 0) !== 0 || ((int) ($stats['processed'] ?? 0) + (int) ($stats['duplicates'] ?? 0)) !== 2) {
        return ll_tools_lms_assignment_experience_error('assignment_progress_failed', __('The answer and learning progress could not be saved. Please retry.', 'll-tools-text-domain'), 503);
    }
    return true;
}

function ll_tools_lms_assignment_player_url(string $assignment_uuid): string {
    $uuid = ll_tools_lms_assignment_normalize_uuid($assignment_uuid);
    return $uuid === '' ? '' : add_query_arg('ll_assignment', $uuid, home_url('/'));
}

/** Read state without starting an attempt: a reload resumes the server-owned one. */
function ll_tools_lms_assignment_player_state(string $assignment_uuid, int $user_id = 0) {
    global $wpdb;
    $user_id = $user_id ?: get_current_user_id();
    $assignment = ll_tools_lms_assignment_get($assignment_uuid);
    if (!is_array($assignment) || $assignment['status'] !== 'published' || !ll_tools_lms_assignment_user_is_current_member($assignment, $user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_membership_required', __('This assignment is unavailable for your account.', 'll-tools-text-domain'), 403);
    }
    $revision = ll_tools_lms_assignment_get_revision((int) $assignment['current_revision_id']);
    $manifest = is_array($revision) ? ll_tools_lms_assignment_revision_manifest($revision) : null;
    if (!is_array($manifest) || ($manifest['schema'] ?? 0) !== 2 || !ll_tools_lms_assignment_vocabulary_access($manifest, $user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_player_unavailable', __('This assignment player is unavailable.', 'll-tools-text-domain'), 403);
    }
    if (function_exists('ll_tools_offline_app_user_data_write_is_fenced') && ll_tools_offline_app_user_data_write_is_fenced($user_id)) {
        return ll_tools_lms_assignment_experience_error('assignment_player_unavailable', __('Your learning data is being erased. Please try again later.', 'll-tools-text-domain'), 409);
    }
    $tables = ll_tools_lms_assignment_table_names();
    $wpdb->last_error = '';
    $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['attempts']} WHERE assignment_id=%d AND revision_id=%d AND user_id=%d ORDER BY attempt_number DESC,id DESC LIMIT 1", (int) $assignment['id'], (int) $revision['id'], $user_id), ARRAY_A);
    if ($wpdb->last_error !== '') { return ll_tools_lms_assignment_experience_error('assignment_state_unavailable', __('The attempt could not be read. Please retry.', 'll-tools-text-domain'), 503); }
    $answers = [];
    if (is_array($attempt)) {
        $wpdb->last_error = '';
        $answers = $wpdb->get_results($wpdb->prepare("SELECT item_key,option_key FROM {$tables['answers']} WHERE attempt_id=%d ORDER BY id ASC LIMIT 15", (int) $attempt['id']), ARRAY_A);
        if ($wpdb->last_error !== '') { return ll_tools_lms_assignment_experience_error('assignment_state_unavailable', __('The answers could not be read. Please retry.', 'll-tools-text-domain'), 503); }
    }
    $grade = ll_tools_lms_assignment_get_grade((int) $assignment['id'], (int) $revision['id'], $user_id);
    $window = ll_tools_lms_assignment_revision_window_is_open($revision, time());
    return ['assignment' => ['assignment_uuid' => $assignment['assignment_uuid'], 'title' => $assignment['title'], 'grade_policy' => $revision['grade_policy'], 'attempt_limit' => (int) $revision['attempt_limit'], 'available_at' => $revision['available_at'], 'due_at' => $revision['due_at']], 'manifest' => ll_tools_lms_assignment_public_manifest($manifest), 'attempt' => is_array($attempt) ? ll_tools_lms_assignment_public_attempt($attempt) : null, 'answers' => (array) $answers, 'grade' => ll_tools_lms_assignment_public_grade($grade), 'can_start' => !is_wp_error($window) && (!is_array($attempt) || (int) $attempt['attempt_number'] < (int) $revision['attempt_limit']), 'window_message' => is_wp_error($window) ? $window->get_error_message() : ''];
}

function ll_tools_lms_assignment_rest_from_category(WP_REST_Request $request) {
    $params = ll_tools_lms_rest_json_params($request, ['class_id', 'category_id', 'word_ids', 'title', 'points_maximum', 'attempt_limit', 'grade_policy', 'available_at', 'due_at']);
    if (is_wp_error($params)) { return $params; }
    if (!is_int($params['class_id'] ?? null) || !is_int($params['category_id'] ?? null)) {
        return ll_tools_lms_assignment_experience_error('invalid_assignment_scope', __('Choose a class and category.', 'll-tools-text-domain'));
    }
    $result = ll_tools_lms_assignment_create_from_category($params['class_id'], $params['category_id'], $params, get_current_user_id());
    return is_wp_error($result) ? ll_tools_lms_rest_prepare_error($result) : new WP_REST_Response(ll_tools_lms_rest_assignment_summary($result), 201);
}

function ll_tools_lms_assignment_rest_player_state(WP_REST_Request $request) {
    $result = ll_tools_lms_assignment_player_state((string) $request['assignment_uuid']);
    return is_wp_error($result) ? $result : rest_ensure_response($result);
}

function ll_tools_lms_assignment_experience_register_routes(): void {
    register_rest_route('ll-tools/v1', '/lms/assignments/from-category', ['methods' => WP_REST_Server::CREATABLE, 'callback' => 'll_tools_lms_assignment_rest_from_category', 'permission_callback' => 'll_tools_lms_rest_teacher_permission']);
    register_rest_route('ll-tools/v1', '/lms/assignments/(?P<assignment_uuid>[0-9a-fA-F-]{36})/player-state', ['methods' => WP_REST_Server::READABLE, 'callback' => 'll_tools_lms_assignment_rest_player_state', 'permission_callback' => 'll_tools_lms_rest_logged_in_permission']);
}
add_action('rest_api_init', 'll_tools_lms_assignment_experience_register_routes');

function ll_tools_lms_assignment_experience_template(string $template): string {
    if (!isset($_GET['ll_assignment']) || !is_string($_GET['ll_assignment'])) { return $template; }
    $uuid = ll_tools_lms_assignment_normalize_uuid(wp_unslash($_GET['ll_assignment']));
    if ($uuid === '') { return $template; }
    nocache_headers();
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(ll_tools_lms_assignment_player_url($uuid)));
        exit;
    }
    $GLOBALS['ll_tools_assignment_player_uuid'] = $uuid;
    ll_enqueue_asset_by_timestamp('/js/assignment-player.js', 'll-tools-assignment-player', [], true);
    ll_enqueue_asset_by_timestamp('/css/assignment-player.css', 'll-tools-assignment-player-style');
    wp_localize_script('ll-tools-assignment-player', 'llToolsAssignmentPlayer', ['assignmentUuid' => $uuid, 'apiRoot' => esc_url_raw(rest_url('ll-tools/v1/lms/')), 'nonce' => wp_create_nonce('wp_rest'), 'messages' => ['loading' => __('Loading assignment…', 'll-tools-text-domain'), 'start' => __('Start assignment', 'll-tools-text-domain'), 'retake' => __('Try another attempt', 'll-tools-text-domain'), 'question' => __('Question', 'll-tools-text-domain'), 'of' => __('of', 'll-tools-text-domain'), 'submit' => __('Save answer', 'll-tools-text-domain'), 'next' => __('Next question', 'll-tools-text-domain'), 'finish' => __('Finish assignment', 'll-tools-text-domain'), 'saved' => __('Answer saved.', 'll-tools-text-domain'), 'saving' => __('Saving…', 'll-tools-text-domain'), 'failed' => __('The request could not be confirmed. Retry to read the saved state.', 'll-tools-text-domain'), 'retry' => __('Retry', 'll-tools-text-domain'), 'score' => __('Attempt score', 'll-tools-text-domain'), 'grade' => __('Selected grade', 'll-tools-text-domain'), 'expired' => __('This attempt has expired.', 'll-tools-text-domain'), 'choose' => __('Choose an answer first.', 'll-tools-text-domain'), 'audio' => __('Play audio', 'll-tools-text-domain')]]);
    return LL_TOOLS_BASE_PATH . 'templates/assignment-player.php';
}
add_filter('template_include', 'll_tools_lms_assignment_experience_template', 20);
