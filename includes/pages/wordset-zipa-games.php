<?php
defined('ABSPATH') || exit;

function ll_tools_zipa_can_play(int $wordset_id, array $settings): bool {
    if (empty($settings['enabled']) || !ll_tools_user_can_view_wordset($wordset_id)) {
        return false;
    }
    switch ($settings['audience'] ?? 'learners') {
        case 'everyone':
            return true;
        case 'managers':
            return ll_tools_zipa_current_user_can_study()
                && ll_tools_user_can_manage_wordset_content($wordset_id);
        default:
            return ll_tools_zipa_current_user_can_study();
    }
}

function ll_tools_zipa_current_user_can_study(): bool {
    return is_user_logged_in() && ll_tools_user_study_can_access();
}

function ll_tools_zipa_hosted_service(int $wordset_id): array {
    return (array) apply_filters('ll_tools_zipa_hosted_service', [
        'endpoint' => ll_tools_get_wordset_local_transcription_endpoint([$wordset_id], false),
        'token' => ll_tools_get_wordset_transcription_api_token([$wordset_id], false),
    ], $wordset_id);
}

function ll_tools_zipa_can_use_hosted(int $wordset_id, array $settings): bool {
    return !empty($settings['enabled']) && !empty($settings['hostedEnabled'])
        && ll_tools_zipa_current_user_can_study() && ll_tools_user_can_view_wordset($wordset_id)
        && ll_tools_user_can_manage_wordset_content($wordset_id)
        && !empty(ll_tools_zipa_hosted_service($wordset_id)['endpoint']);
}

function ll_tools_zipa_enqueue_games(int $wordset_id): void {
    if (!wp_script_is('ll-wordset-pages-js', 'enqueued')) {
        return;
    }
    $settings = ll_tools_zipa_get_settings($wordset_id);
    $can_host = ll_tools_zipa_can_use_hosted($wordset_id, $settings);
    $preview = ($_GET['ll_zipa_hosted'] ?? '') === '1' || ($_GET['ll_zipa_pilot'] ?? '') === '1';
    if ((!$preview || !$can_host) && !ll_tools_zipa_can_play($wordset_id, $settings)) {
        return;
    }
    $hosted = $preview && $can_host;
    if ($hosted) {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }
    $handle = 'll-zipa-games';
    ll_enqueue_asset_by_timestamp('/js/zipa/zipa-games.js', $handle, ['ll-wordset-games-js'], true);
    $scripts = wp_scripts();
    $scripts->registered['ll-wordset-pages-js']->deps[] = $handle;
    wp_add_inline_script($handle, 'window.llToolsZipaGames = ' . wp_json_encode([
        'assetBase' => LL_TOOLS_BASE_URL . 'js/zipa/',
        'version' => LL_TOOLS_VERSION,
        'wordsetId' => $wordset_id,
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => $hosted ? wp_create_nonce('ll_user_study') : '',
        'hosted' => $hosted,
        'text' => [
            'loadingCards' => __('Loading the game cards…', 'll-tools-text-domain'),
            'missingCards' => __('The game cards could not load.', 'll-tools-text-domain'),
            'unsupported' => __('This browser does not support ZIPA speech recognition.', 'll-tools-text-domain'),
            /* translators: %s: download size. */
            'downloadLabel' => __('Download the %s speech model', 'll-tools-text-domain'),
            /* translators: %s: download size in megabytes. */
            'downloadSize' => __('%s MB', 'll-tools-text-domain'),
            'download' => __('Downloading the speech model…', 'll-tools-text-domain'),
            'initialize' => __('Preparing the speech model…', 'll-tools-text-domain'),
            'listening' => __('Processing audio…', 'll-tools-text-domain'),
            'loadFailed' => __('The speech model could not load.', 'll-tools-text-domain'),
            'noSpeech' => __('No speech was detected. Try again.', 'll-tools-text-domain'),
            'decodeFailed' => __('The recording could not be read. Try again.', 'll-tools-text-domain'),
            'inferenceFailed' => __('The recording could not be processed. Try again.', 'll-tools-text-domain'),
            'retry' => __('Try again', 'll-tools-text-domain'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
}

/**
 * Find only a bounded pool of visible word IDs with isolation IPA. The optional
 * curated list is exact and stays bounded independently of wordset size.
 */
function ll_tools_zipa_candidate_ids(int $wordset_id, array $category_ids, array $curated_ids): array {
    if (!$category_ids) {
        return [];
    }
    if ($curated_ids) {
        return ll_tools_wordset_games_filter_visible_candidate_word_ids($curated_ids, $wordset_id, $category_ids);
    }

    global $wpdb;
    $category_placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
    $isolation = get_term_by('slug', 'isolation', 'recording_type');
    $isolation_id = $isolation instanceof WP_Term ? (int) $isolation->term_taxonomy_id : 0;
    $sql = "
        SELECT DISTINCT word.ID
        FROM {$wpdb->term_taxonomy} wordset
        INNER JOIN {$wpdb->term_relationships} membership ON membership.term_taxonomy_id = wordset.term_taxonomy_id
        INNER JOIN {$wpdb->posts} word ON word.ID = membership.object_id
        INNER JOIN {$wpdb->term_relationships} category ON category.object_id = word.ID
        INNER JOIN {$wpdb->term_taxonomy} category_term ON category_term.term_taxonomy_id = category.term_taxonomy_id
        WHERE wordset.taxonomy = 'wordset' AND wordset.term_id = %d
          AND category_term.taxonomy = 'word-category' AND category_term.term_id IN ({$category_placeholders})
          AND word.post_status = 'publish' AND word.post_type = 'words' AND word.post_password = ''
          AND EXISTS (
              SELECT 1 FROM {$wpdb->posts} clip
              INNER JOIN {$wpdb->postmeta} ipa ON ipa.post_id = clip.ID AND ipa.meta_key = 'recording_ipa'
              INNER JOIN {$wpdb->postmeta} audio ON audio.post_id = clip.ID AND audio.meta_key = 'audio_file_path'
              LEFT JOIN {$wpdb->term_relationships} kind ON kind.object_id = clip.ID AND kind.term_taxonomy_id = %d
              LEFT JOIN {$wpdb->postmeta} legacy_kind ON legacy_kind.post_id = clip.ID AND legacy_kind.meta_key = 'recording_type'
              WHERE clip.post_parent = word.ID AND clip.post_type = 'word_audio'
                AND clip.post_status = 'publish' AND clip.post_password = ''
                AND TRIM(ipa.meta_value) <> '' AND audio.meta_value <> ''
                AND (kind.object_id IS NOT NULL OR LOWER(TRIM(legacy_kind.meta_value)) = 'isolation')
          )
        ORDER BY word.ID DESC LIMIT 180
    ";
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        $sql,
        array_merge([$wordset_id], $category_ids, [$isolation_id])
    )));
}

function ll_tools_zipa_build_cards(int $wordset_id, array $settings, string $slug): array {
    $uid = get_current_user_id();
    $curated = $settings['cardIds'] ?? [];
    $category_scope = $curated ? '' : $slug;
    $category_ids = ll_tools_wordset_games_visible_category_ids($wordset_id, $uid, $category_scope);
    $ids = ll_tools_zipa_candidate_ids($wordset_id, $category_ids, $curated);
    if (!$ids) {
        return [];
    }
    $categories_by_word = ll_tools_get_object_term_ids_map($ids, 'word-category');
    $categories = array_fill_keys($category_ids, true);
    $candidates = [];
    foreach ($ids as $id) {
        $candidates[] = ['id' => $id, 'category_ids' => array_values(array_filter(
            $categories_by_word[$id] ?? [],
            static fn($category) => isset($categories[$category])
        )), 'progress_status' => 'new'];
    }
    $words = ll_tools_wordset_games_collect_visible_speaking_words_for_candidate_pool(
        $wordset_id, $uid, $category_scope, $candidates
    )['words'];
    $cards = [];
    $target_label = ll_tools_get_wordset_speaking_game_target_label('recording_ipa', [$wordset_id]);
    foreach ($words as $word) {
        $id = (int) $word['id'];
        $post = get_post($id);
        if (!($post instanceof WP_Post) || $post->post_password !== '') {
            continue;
        }
        $configured = $settings['cardReferences'][$id] ?? [];
        if (!empty($configured['title'])) {
            $word['title'] = $configured['title'];
        }
        $recording_id = (int) ($configured['recordingId'] ?? 0);
        if ($recording_id) {
            $recording = get_post($recording_id);
            if (!($recording instanceof WP_Post) || $recording->post_type !== 'word_audio' || $recording->post_status !== 'publish'
                || $recording->post_password !== '' || (int) $recording->post_parent !== $id
                || ll_tools_wordset_games_get_audio_recording_type($recording) !== 'isolation') {
                continue;
            }
            $path = (string) get_post_meta($recording_id, 'audio_file_path', true);
            $url = ll_tools_resolve_audio_file_url($path);
            $ipa = trim((string) ($configured['ipa'] ?? get_post_meta($recording_id, 'recording_ipa', true)));
            $recording_text = (string) get_post_meta($recording_id, 'recording_text', true);
            if ($path === '' || $url === '' || $ipa === ''
                || !ll_tools_wordset_games_is_speaking_suitable_isolation_audio($recording_id, $path, $recording_text, 'recording_ipa')) {
                continue;
            }
            $word['recording_ipa'] = $ipa;
            $word['recording_text'] = $recording_text;
            $word['speaking_best_correct_audio_url'] = $url;
        } else {
            $audio = ll_tools_wordset_games_get_audio_details($id, 'isolation', [
                'speaking_short_only' => true,
                'target_field' => 'recording_ipa',
                'public_only' => true,
            ]);
            if (empty($audio['recording_ipa']) || empty($audio['url'])) {
                continue;
            }
            $word['recording_ipa'] = $audio['recording_ipa'];
            $word['recording_text'] = $audio['recording_text'];
            $word['speaking_best_correct_audio_url'] = $audio['url'];
        }
        if (!empty($configured['imageUrl'])) {
            $word['image'] = $configured['imageUrl'];
            $word['image_is_animated_webp'] = false;
        }
        if ($slug === 'speaking-stack' && !ll_tools_wordset_games_word_has_supported_image($word)) {
            continue;
        }
        $word['speaking_target_field'] = 'recording_ipa';
        $word['speaking_target_label'] = $target_label;
        $word['speaking_target_text'] = $word['recording_ipa'];
        $word['speaking_prompt_text'] = ll_tools_wordset_games_get_speaking_prompt_text($word);
        $word['speaking_prompt_type'] = !empty($word['image']) ? 'image' : 'text';
        $word['speaking_display_texts'] = ll_tools_wordset_games_get_speaking_display_texts($id, 'recording_ipa', $word);
        $word['ipaVariants'] = $settings['ipaAliases'][$id] ?? [];
        $cards[$id] = array_intersect_key($word, array_fill_keys([
            'id', 'title', 'translation', 'label', 'image', 'category_id', 'category_ids', 'category_name', 'category_names',
            'progress_status', 'recording_ipa', 'speaking_target_field', 'speaking_target_label', 'speaking_target_text',
            'speaking_prompt_text', 'speaking_prompt_type', 'speaking_display_texts', 'speaking_best_correct_audio_url', 'ipaVariants',
        ], true));
        $cards[$id]['audio'] = $word['speaking_best_correct_audio_url'];
        if (count($cards) >= LL_TOOLS_ZIPA_CARD_LIMIT) {
            break;
        }
    }
    return $cards;
}

function ll_tools_zipa_build_catalog(int $wordset_id, array $settings, bool $hosted): array {
    $games = [];
    foreach (['speaking-practice', 'speaking-stack'] as $slug) {
        $cards = array_values(ll_tools_zipa_build_cards($wordset_id, $settings, $slug));
        $minimum = ll_tools_wordset_games_min_word_count();
        if (count($cards) < $minimum) {
            continue;
        }
        $stack = $slug === 'speaking-stack';
        $games[$slug] = [
            'slug' => $slug,
            'title' => $stack ? __('Word Stack', 'll-tools-text-domain') : __('Speaking Practice', 'll-tools-text-domain'),
            'description' => $stack ? __('Say the picture before the stack reaches the top.', 'll-tools-text-domain')
                : __('Say the word aloud. Compare what you said to the target text.', 'll-tools-text-domain'),
            'provider' => 'embedded_model',
            'provider_label' => $hosted ? __('Hosted ZIPA', 'll-tools-text-domain') : __('On-device ZIPA', 'll-tools-text-domain'),
            'minimum_word_count' => $minimum,
            'available_word_count' => count($cards),
            'launch_word_count' => count($cards),
            'launch_word_cap' => count($cards),
            'launchable' => true,
            'payload_deferred' => false,
            'category_ids' => array_values(array_unique(array_column($cards, 'category_id'))),
            'target_field' => 'recording_ipa',
            'target_label' => ll_tools_get_wordset_speaking_game_target_label('recording_ipa', [$wordset_id]),
            'words' => $cards,
        ];
    }
    return $games;
}

function ll_tools_zipa_catalog_ajax(): void {
    $id = (int) ($_GET['wordset_id'] ?? 0);
    $hosted = ($_GET['hosted'] ?? '') === '1';
    $settings = ll_tools_zipa_get_settings($id);
    if ($hosted ? !ll_tools_zipa_can_use_hosted($id, $settings) : !ll_tools_zipa_can_play($id, $settings)) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    if ($hosted) {
        check_ajax_referer('ll_user_study', 'nonce');
    }
    $games = ll_tools_zipa_build_catalog($id, $settings, $hosted);
    wp_send_json_success([
        'games' => $games,
        'matcher' => $settings['matcher'] ?? [],
        'browserModelManifestUrl' => $settings['browserModelManifestUrl'] ?? '',
        'stackCapture' => $settings['stackCapture'] ?? [],
    ]);
}
add_action('wp_ajax_ll_wordset_zipa_catalog', 'll_tools_zipa_catalog_ajax');
add_action('wp_ajax_nopriv_ll_wordset_zipa_catalog', 'll_tools_zipa_catalog_ajax');

function ll_tools_zipa_require_hosted(): array {
    ll_tools_wordset_games_require_speaking_permissions();
    $id = (int) ($_POST['wordset_id'] ?? 0);
    $settings = ll_tools_zipa_get_settings($id);
    if (!ll_tools_zipa_can_use_hosted($id, $settings)) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    return [$id, $settings];
}

function ll_tools_zipa_transcribe_ajax(): void {
    [$wordset_id] = ll_tools_zipa_require_hosted();
    $status = ll_tools_wordset_games_get_speaking_transcription_rate_limit_status(get_current_user_id());
    if (!empty($status['limited'])) {
        ll_tools_wordset_games_send_speaking_rate_limit_error(new WP_Error(
            'rate_limited',
            $status['message'] ?? ll_tools_wordset_games_speaking_transcription_rate_limit_message(),
            $status
        ));
    }
    $file = $_FILES['audio'] ?? [];
    if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        wp_send_json_error(['message' => __('Missing audio file.', 'll-tools-text-domain')], 400);
    }
    $validation = ll_tools_validate_recording_upload_file($file);
    if (empty($validation['valid'])) {
        wp_send_json_error(['message' => $validation['error'] ?? __('Invalid audio upload.', 'll-tools-text-domain')], (int) ($validation['status'] ?? 400));
    }
    ll_tools_wordset_games_record_speaking_transcription_attempt(get_current_user_id());
    ll_tools_wordset_games_record_stt_api_call(get_current_user_id(), $wordset_id, 'hosted_api', ['target_field' => 'recording_ipa']);
    $service = ll_tools_zipa_hosted_service($wordset_id);
    $result = ll_tools_remote_stt_transcribe_audio_file((string) $service['endpoint'], $file['tmp_name'], [
        'token' => (string) ($service['token'] ?? ''),
        'filename' => sanitize_file_name((string) ($file['name'] ?? 'speaking-attempt.webm')),
        'fields' => [
            'wordset_id' => (string) $wordset_id,
            'recording_type' => 'speaking_attempt',
            'target_field' => 'recording_ipa',
        ],
    ]);
    if (is_wp_error($result)) {
        wp_send_json_error(['message' => __('The recording could not be processed. Try again.', 'll-tools-text-domain')], 502);
    }
    wp_send_json_success(['transcript' => trim((string) ($result['transcript'] ?? ''))]);
}
add_action('wp_ajax_ll_wordset_zipa_transcribe', 'll_tools_zipa_transcribe_ajax');

function ll_tools_zipa_match_transcript(
    LL_Tools_Zipa_Matcher $matcher,
    Closure $display,
    string $transcript,
    array $references,
    array $spellings,
    bool $allow_close
): array {
    $heard = $display($transcript);
    return [$matcher->score($transcript, $references, $allow_close, $heard, $spellings), $heard];
}

function ll_tools_zipa_score(
    int $wordset_id,
    string $transcript,
    array $cards,
    array $active_ids,
    int $asked_id,
    array $profile
): array {
    $matcher = new LL_Tools_Zipa_Matcher($profile);
    $display = $matcher->create_display($cards);
    $references = array_map(static fn($card) => [$card['recording_ipa'], ...$card['ipaVariants']], $cards);
    $spellings = array_column($cards, 'title', 'id');
    $stack = $asked_id === 0;
    // Resolve the whole utterance before trying to clear separate cards.
    [$result, $heard] = ll_tools_zipa_match_transcript($matcher, $display, $transcript, $references, $spellings, $stack);
    if ($stack && $result['reason'] === 'no_match') {
        $items = [];
        $parts = $matcher->split_burst($transcript, $references);
        foreach ($parts ?? [] as $part) {
            [$part_result, $part_heard] = ll_tools_zipa_match_transcript($matcher, $display, $part, $references, $spellings, false);
            $items[] = ll_tools_zipa_result_payload($wordset_id, $part_result, $part_heard, $cards, $active_ids, 0);
        }
        if ($parts === [] && !str_contains($result['normalized_transcript_text'], ' ')) {
            foreach ($matcher->split_spelling_burst($heard, $spellings, $transcript, $references) as $part) {
                $id = $part['word_id'];
                $part_ipa = $part['ipa'] ?? '';
                if ($id === null) {
                    $items[] = ['matched' => false, 'display_transcript_text' => $part['display'] ?? $display($part_ipa)];
                    continue;
                }
                $repaired = $part['repaired'];
                $part_result = [
                    'matched' => true,
                    'word_id' => $id,
                    'reason' => $repaired ? 'close_match' : ($part_ipa !== '' ? 'phonemic_match' : 'orthographic_match'),
                    'score' => $repaired ? 85.0 : 100.0,
                    'bucket' => $repaired ? 'close' : 'right',
                    'normalized_target_text' => $part_ipa !== '' ? $part_ipa : $matcher->normalize($references[$id][0]),
                    'normalized_transcript_text' => $part_ipa,
                ];
                $items[] = ll_tools_zipa_result_payload($wordset_id, $part_result, $spellings[$id], $cards, $active_ids, 0);
            }
        }
        $matches = [];
        foreach ($items as $item) {
            if ($item['matched']) {
                $matches[$item['word_id']] ??= $item;
            }
        }
        if ($matches) {
            $payload = reset($matches);
            $payload['matches'] = array_values($matches);
            $payload['display_transcript_text'] = implode(' ', array_filter(array_column($items, 'display_transcript_text')));
            $payload['normalized_transcript_text'] = $result['normalized_transcript_text'];
            return $payload;
        }
    }
    return ll_tools_zipa_result_payload($wordset_id, $result, $heard, $cards, $active_ids, $asked_id);
}

function ll_tools_zipa_result_payload(int $wordset_id, array $result, string $heard, array $cards, array $active_ids, int $asked_id): array {
    $stack = $asked_id === 0;
    $winner = (int) ($result['word_id'] ?? 0);
    $matched = !empty($result['matched']) && in_array($winner, $active_ids, true);
    $display_id = $stack ? ($matched ? $winner : 0) : $asked_id;
    $display = $cards[$display_id] ?? [];
    $target = (string) ($display['speaking_target_text'] ?? '');
    if ($matched && in_array($result['reason'], ['duplicate_consonant_match', 'duplicate_vowel_match'], true)) {
        $heard = (string) ($display['speaking_display_texts']['title'] ?? $heard);
    }
    $reason = !empty($result['matched']) && !$matched ? ($stack ? 'inactive_card' : 'different_card')
        : (string) ($result['reason'] ?? '');
    return [
        'wordset_id' => $wordset_id,
        'word_id' => $display_id ?: null,
        'matched' => $matched,
        'score' => $matched ? $result['score'] : 0,
        'bucket' => $matched ? $result['bucket'] : 'wrong',
        'reason' => $reason,
        'target_field' => 'recording_ipa',
        'target_label' => (string) ($display['speaking_target_label'] ?? ''),
        'target_text' => $target,
        'normalized_target_text' => $matched ? (string) ($result['normalized_target_text'] ?? '') : '',
        'normalized_transcript_text' => (string) ($result['normalized_transcript_text'] ?? ''),
        'display_target_text' => (string) ($display['speaking_display_texts']['title'] ?? ''),
        'display_transcript_text' => $heard,
        'display_texts' => $display['speaking_display_texts'] ?? [],
        'best_correct_audio_url' => (string) ($display['speaking_best_correct_audio_url'] ?? ''),
        'provider' => 'hosted_api',
        'comparison_mode' => 'recording_ipa',
        'candidate_word_ids' => $active_ids,
        'candidate_count' => count($active_ids),
    ];
}

function ll_tools_zipa_score_ajax(): void {
    [$wordset_id, $settings] = ll_tools_zipa_require_hosted();
    $transcript = $_POST['transcript'] ?? null;
    $transcript = is_string($transcript) && strlen($transcript) <= 512 ? trim(wp_unslash($transcript)) : '';
    $asked_id = (int) ($_POST['word_id'] ?? 0);
    $raw_ids = $_POST['word_ids'] ?? null;
    $active_ids = $asked_id > 0 ? [$asked_id] : [];
    if (is_string($raw_ids) && strlen($raw_ids) <= 160 && preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,7}$/D', $raw_ids)) {
        $active_ids = array_map('intval', explode(',', $raw_ids));
    }
    if (!$active_ids || count(array_unique($active_ids)) !== count($active_ids) || ($asked_id > 0 && $raw_ids !== null)
        || $transcript === '' || strlen($transcript) > 256 || !preg_match('//u', $transcript)) {
        wp_send_json_error(['message' => __('Invalid speaking attempt.', 'll-tools-text-domain')], 400);
    }
    $slug = $asked_id > 0 ? 'speaking-practice' : 'speaking-stack';
    $cards = ll_tools_zipa_build_cards($wordset_id, $settings, $slug);
    if (array_diff($active_ids, array_keys($cards))) {
        wp_send_json_error(['message' => __('A speaking card is unavailable.', 'll-tools-text-domain')], 403);
    }

    wp_send_json_success(ll_tools_zipa_score($wordset_id, $transcript, $cards, $active_ids, $asked_id, $settings['matcher']));
}
add_action('wp_ajax_ll_wordset_zipa_score', 'll_tools_zipa_score_ajax');
