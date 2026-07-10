<?php
if (!defined('ABSPATH')) {
    exit;
}

function ll_tools_get_offline_app_export_capability(): string {
    return (string) apply_filters('ll_tools_offline_app_export_capability', 'manage_options');
}

function ll_tools_current_user_can_offline_app_export(): bool {
    return current_user_can(ll_tools_get_offline_app_export_capability());
}

function ll_tools_get_offline_app_export_page_slug(): string {
    return 'll-offline-app-export';
}

function ll_tools_register_offline_app_export_page(): void {
    $hook = add_management_page(
        __('LL Offline App Export', 'll-tools-text-domain'),
        __('LL Offline App Export', 'll-tools-text-domain'),
        ll_tools_get_offline_app_export_capability(),
        ll_tools_get_offline_app_export_page_slug(),
        'll_tools_render_offline_app_export_page'
    );

    if (is_string($hook) && $hook !== '') {
        add_action('load-' . $hook, 'll_tools_prime_offline_app_export_admin_title');
    }
}
add_action('admin_menu', 'll_tools_register_offline_app_export_page');

function ll_tools_prime_offline_app_export_admin_title(): void {
    global $title;
    if (!is_string($title) || $title === '') {
        $title = __('LL Offline App Export', 'll-tools-text-domain');
    }
}

add_action('admin_post_ll_tools_export_offline_app', 'll_tools_handle_export_offline_app');
add_action('wp_ajax_ll_tools_offline_app_export_categories', 'll_tools_ajax_offline_app_export_categories');
add_action('wp_ajax_ll_tools_offline_app_export_start', 'll_tools_ajax_offline_app_export_start');
add_action('wp_ajax_ll_tools_offline_app_export_step', 'll_tools_ajax_offline_app_export_step');
add_action('admin_post_ll_tools_download_offline_app_export', 'll_tools_handle_offline_app_export_download');

function ll_tools_ajax_offline_app_export_categories(): void {
    if (!ll_tools_current_user_can_offline_app_export()) {
        wp_send_json_error(['message' => __('You do not have permission to export offline app bundles.', 'll-tools-text-domain')], 403);
    }

    check_ajax_referer('ll_tools_offline_app_export_categories', 'nonce');
    $wordset_id = isset($_POST['wordset_id']) ? (int) wp_unslash((string) $_POST['wordset_id']) : 0;
    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        wp_send_json_error(['message' => __('Select a valid word set.', 'll-tools-text-domain')], 400);
    }

    wp_send_json_success([
        'categories' => ll_tools_offline_app_get_wordset_category_options($wordset_id),
    ]);
}

function ll_tools_offline_app_filter_game_entry_words_by_category_ids(array $words, array $allowed_category_ids): array {
    $allowed_lookup = array_fill_keys(array_values(array_filter(array_map('intval', $allowed_category_ids), static function (int $id): bool {
        return $id > 0;
    })), true);
    if (empty($allowed_lookup)) {
        return [];
    }

    return array_values(array_filter($words, static function ($word) use ($allowed_lookup): bool {
        if (!is_array($word)) {
            return false;
        }

        $word_category_ids = isset($word['category_ids']) && is_array($word['category_ids'])
            ? array_values(array_filter(array_map('intval', $word['category_ids']), static function (int $id): bool {
                return $id > 0;
            }))
            : [];
        if (empty($word_category_ids) && !empty($word['category_id'])) {
            $word_category_ids = [(int) $word['category_id']];
        }

        foreach ($word_category_ids as $category_id) {
            if (!empty($allowed_lookup[$category_id])) {
                return true;
            }
        }

        return false;
    }));
}

function ll_tools_offline_app_filter_games_catalog_to_categories(array $catalog, array $allowed_category_ids): array {
    $filtered_catalog = [];
    foreach ($catalog as $slug => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $filtered_words = ll_tools_offline_app_filter_game_entry_words_by_category_ids(
            isset($entry['words']) && is_array($entry['words']) ? $entry['words'] : [],
            $allowed_category_ids
        );
        $minimum_word_count = max(1, (int) ($entry['minimum_word_count'] ?? 1));
        $launch_word_cap = max($minimum_word_count, (int) ($entry['launch_word_cap'] ?? count($filtered_words)));
        $available_word_count = count($filtered_words);

        $filtered_catalog[(string) $slug] = array_merge($entry, [
            'category_ids' => array_values(array_filter(array_map('intval', $allowed_category_ids), static function (int $id): bool {
                return $id > 0;
            })),
            'words' => array_values($filtered_words),
            'available_word_count' => $available_word_count,
            'launch_word_count' => min($available_word_count, $launch_word_cap),
            'launch_word_cap' => $launch_word_cap,
            'launchable' => !empty($entry['launchable']) && $available_word_count >= $minimum_word_count,
            'reason_code' => ($available_word_count >= $minimum_word_count)
                ? (string) ($entry['reason_code'] ?? '')
                : 'not_enough_words',
        ]);
    }

    return $filtered_catalog;
}

function ll_tools_offline_app_rewrite_game_asset_url($value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $plugin_base = defined('LL_TOOLS_BASE_URL') ? (string) LL_TOOLS_BASE_URL : '';
    if ($plugin_base !== '' && strpos($value, $plugin_base) === 0) {
        return './plugin/' . ltrim(substr($value, strlen($plugin_base)), '/');
    }

    return $value;
}

function ll_tools_offline_app_rewrite_games_frontend_config(array $config): array {
    foreach (['spaceShooter', 'bubblePop', 'speakingPractice'] as $game_key) {
        if (empty($config[$game_key]) || !is_array($config[$game_key])) {
            continue;
        }

        foreach (['correctHitAudioSources', 'wrongHitAudioSources'] as $audio_key) {
            if (!isset($config[$game_key][$audio_key])) {
                continue;
            }
            $config[$game_key][$audio_key] = array_values(array_filter(array_map(
                static function ($value): string {
                    return ll_tools_offline_app_rewrite_game_asset_url($value);
                },
                (array) $config[$game_key][$audio_key]
            ), static function (string $value): bool {
                return $value !== '';
            }));
        }
    }

    return $config;
}

function ll_tools_offline_app_build_games_word_lookup(array $offline_category_data): array {
    $lookup = [];
    foreach ($offline_category_data as $rows) {
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $word_id = (int) ($row['id'] ?? 0);
            if ($word_id <= 0) {
                continue;
            }
            $lookup[$word_id] = $row;
        }
    }

    return $lookup;
}

function ll_tools_offline_app_rewrite_games_entry_words(array $words, array $offline_word_lookup): array {
    return array_values(array_map(static function ($word) use ($offline_word_lookup) {
        if (!is_array($word)) {
            return $word;
        }

        $word_id = (int) ($word['id'] ?? 0);
        if ($word_id <= 0 || empty($offline_word_lookup[$word_id]) || !is_array($offline_word_lookup[$word_id])) {
            return $word;
        }

        $offline_word = $offline_word_lookup[$word_id];
        return array_merge($word, [
            'image' => (string) ($offline_word['image'] ?? ($word['image'] ?? '')),
            'audio' => (string) ($offline_word['audio'] ?? ($word['audio'] ?? '')),
            'audio_files' => isset($offline_word['audio_files']) && is_array($offline_word['audio_files'])
                ? $offline_word['audio_files']
                : (isset($word['audio_files']) && is_array($word['audio_files']) ? $word['audio_files'] : []),
        ]);
    }, $words));
}

function ll_tools_offline_app_read_json_file(string $path): array {
    $path = wp_normalize_path(trim((string) $path));
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ll_tools_offline_app_normalize_relative_bundle_path($value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = str_replace('\\', '/', $value);
    $segments = array_values(array_filter(explode('/', $value), static function ($segment): bool {
        return $segment !== '' && $segment !== '.' && $segment !== '..';
    }));

    return implode('/', $segments);
}

function ll_tools_offline_app_normalize_stt_engine($value): string {
    $engine = strtolower(trim((string) $value));
    $engine = str_replace([' ', '_'], ['.', '.'], $engine);

    if (in_array($engine, ['whisper.cpp', 'whispercpp', 'ggml', 'gguf'], true)) {
        return 'whisper.cpp';
    }

    if ($engine === 'onnx') {
        return 'onnx';
    }

    if ($engine === 'tflite') {
        return 'tflite';
    }

    return $engine;
}

function ll_tools_offline_app_guess_stt_model_file_from_directory(string $source_path): string {
    $source_path = wp_normalize_path(trim((string) $source_path));
    if ($source_path === '' || !is_dir($source_path)) {
        return '';
    }

    $candidates = [];
    foreach (new DirectoryIterator($source_path) as $file_info) {
        if (!$file_info->isFile()) {
            continue;
        }

        $extension = strtolower((string) $file_info->getExtension());
        if (!in_array($extension, ['bin', 'gguf'], true)) {
            continue;
        }

        $candidates[] = (string) $file_info->getFilename();
    }

    if (empty($candidates)) {
        return '';
    }

    sort($candidates, SORT_NATURAL | SORT_FLAG_CASE);
    return ll_tools_offline_app_normalize_relative_bundle_path($candidates[0]);
}

function ll_tools_offline_app_build_stt_runtime_manifest(string $source_path, bool $is_directory): array {
    $runtime = [
        'engine' => '',
        'task' => 'transcribe',
        'language' => 'auto',
        'model_path' => '',
        'manifest_path' => '',
        'manifest' => [],
        'android_supported' => false,
    ];

    $source_path = wp_normalize_path(trim((string) $source_path));
    if ($source_path === '') {
        return $runtime;
    }

    if ($is_directory) {
        $manifest_path = trailingslashit($source_path) . 'manifest.json';
        $manifest = ll_tools_offline_app_read_json_file($manifest_path);
        if (!empty($manifest)) {
            $runtime['manifest'] = $manifest;
            $runtime['manifest_path'] = 'manifest.json';
            $runtime['engine'] = ll_tools_offline_app_normalize_stt_engine(
                $manifest['engine']
                ?? $manifest['runtime']
                ?? $manifest['backend']
                ?? $manifest['format']
                ?? ''
            );
            $runtime['task'] = sanitize_key((string) ($manifest['task'] ?? $manifest['mode'] ?? 'transcribe'));
            if (!in_array($runtime['task'], ['transcribe', 'translate'], true)) {
                $runtime['task'] = 'transcribe';
            }

            $runtime['language'] = sanitize_text_field((string) ($manifest['language'] ?? 'auto'));
            if ($runtime['language'] === '') {
                $runtime['language'] = 'auto';
            }

            $runtime['model_path'] = ll_tools_offline_app_normalize_relative_bundle_path(
                $manifest['modelPath']
                ?? $manifest['model_path']
                ?? $manifest['model']
                ?? $manifest['modelFile']
                ?? $manifest['model_file']
                ?? $manifest['file']
                ?? ''
            );
        }

        if ($runtime['model_path'] === '') {
            $runtime['model_path'] = ll_tools_offline_app_guess_stt_model_file_from_directory($source_path);
        }
    } else {
        $runtime['model_path'] = ll_tools_offline_app_normalize_relative_bundle_path(wp_basename($source_path));
    }

    if ($runtime['engine'] === '' && $runtime['model_path'] !== '') {
        $extension = strtolower((string) pathinfo($runtime['model_path'], PATHINFO_EXTENSION));
        if (in_array($extension, ['bin', 'gguf'], true)) {
            $runtime['engine'] = 'whisper.cpp';
        }
    }

    $runtime['android_supported'] = ($runtime['engine'] === 'whisper.cpp' && $runtime['model_path'] !== '');

    return $runtime;
}

function ll_tools_offline_app_resolve_wordset_stt_bundle(int $wordset_id, WP_Term $wordset_term) {
    $source_path = function_exists('ll_tools_get_wordset_offline_stt_bundle_path')
        ? ll_tools_get_wordset_offline_stt_bundle_path([$wordset_id], true)
        : '';
    $source_path = ll_tools_sanitize_wordset_offline_stt_bundle_path($source_path);
    if ($source_path === '') {
        return [];
    }

    $normalized_source = ll_tools_offline_app_resolve_source_path($source_path);
    $source_exists = is_dir($normalized_source) || is_file($normalized_source);
    if (!$source_exists) {
        return new WP_Error(
            'll_tools_offline_app_missing_stt_bundle',
            sprintf(
                /* translators: %s: source path */
                __('The configured offline STT bundle path does not exist: %s', 'll-tools-text-domain'),
                $source_path
            )
        );
    }

    $bundle_slug = sanitize_title((string) $wordset_term->slug);
    if ($bundle_slug === '') {
        $bundle_slug = 'wordset-' . (int) $wordset_id;
    }
    $source_name = wp_basename($normalized_source);
    $relative_path = 'content/stt-models/' . $bundle_slug . '/' . $source_name;
    $runtime_manifest = ll_tools_offline_app_build_stt_runtime_manifest($normalized_source, is_dir($normalized_source));
    $android_asset_path = 'public/' . $relative_path;
    $manifest = [
        'wordsetId' => (int) $wordset_id,
        'wordsetSlug' => (string) $wordset_term->slug,
        'sourceName' => $source_name,
        'entryType' => is_dir($normalized_source) ? 'directory' : 'file',
        'bundlePath' => 'www/' . $relative_path,
        'webPath' => './' . $relative_path,
        'androidAssetPath' => $android_asset_path,
        'androidSupported' => !empty($runtime_manifest['android_supported']),
        'engine' => (string) ($runtime_manifest['engine'] ?? ''),
        'task' => (string) ($runtime_manifest['task'] ?? 'transcribe'),
        'language' => (string) ($runtime_manifest['language'] ?? 'auto'),
    ];

    if (!empty($runtime_manifest['manifest_path'])) {
        $manifest_relative_path = trim($relative_path, '/') . '/' . ltrim((string) $runtime_manifest['manifest_path'], '/');
        $manifest['manifestPath'] = 'www/' . $manifest_relative_path;
        $manifest['webManifestPath'] = './' . $manifest_relative_path;
        $manifest['androidAssetManifestPath'] = 'public/' . $manifest_relative_path;
    }

    if (!empty($runtime_manifest['model_path'])) {
        $model_relative_path = is_dir($normalized_source)
            ? trim($relative_path, '/') . '/' . ltrim((string) $runtime_manifest['model_path'], '/')
            : trim($relative_path, '/');
        $manifest['modelPath'] = (string) $runtime_manifest['model_path'];
        $manifest['modelBundlePath'] = 'www/' . $model_relative_path;
        $manifest['webModelPath'] = './' . $model_relative_path;
        $manifest['androidAssetModelPath'] = 'public/' . $model_relative_path;
    }

    return [
        'source_path' => $normalized_source,
        'relative_path' => $relative_path,
        'is_directory' => is_dir($normalized_source),
        'manifest' => $manifest,
    ];
}

function ll_tools_offline_app_build_games_payload(int $wordset_id, WP_Term $wordset_term, array $allowed_category_ids, array $offline_category_data = [], array &$warnings = []): array {
    $frontend_config = function_exists('ll_tools_get_wordset_games_frontend_config')
        ? ll_tools_get_wordset_games_frontend_config($wordset_id)
        : [];
    $frontend_config = ll_tools_offline_app_rewrite_games_frontend_config($frontend_config);
    $games_i18n = function_exists('ll_tools_get_wordset_games_i18n_messages')
        ? ll_tools_get_wordset_games_i18n_messages()
        : [];
    $catalog = function_exists('ll_tools_wordset_games_build_catalog')
        ? ll_tools_wordset_games_build_catalog($wordset_id, 0)
        : [];
    $catalog = ll_tools_offline_app_filter_games_catalog_to_categories($catalog, $allowed_category_ids);
    $offline_word_lookup = ll_tools_offline_app_build_games_word_lookup($offline_category_data);
    foreach ($catalog as $slug => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $catalog[$slug]['words'] = ll_tools_offline_app_rewrite_games_entry_words(
            isset($entry['words']) && is_array($entry['words']) ? $entry['words'] : [],
            $offline_word_lookup
        );
    }

    if (isset($catalog['speaking-practice']) && is_array($catalog['speaking-practice'])) {
        unset($catalog['speaking-practice']);
        $warnings[] = __('Speaking Practice is temporarily disabled in offline app exports.', 'll-tools-text-domain');
    }

    if (isset($catalog['speaking-stack']) && is_array($catalog['speaking-stack'])) {
        unset($catalog['speaking-stack']);
        $warnings[] = __('Word Stack speaking mode is temporarily disabled in offline app exports.', 'll-tools-text-domain');
    }

    return array_merge($frontend_config, [
        'enabled' => !empty($catalog),
        'runtimeMode' => 'offline',
        'catalog' => $catalog,
        'i18n' => $games_i18n,
        'offlineBridge' => [
            'androidInterface' => 'LLToolsOfflineAndroid',
            'usesEmbeddedModel' => false,
        ],
    ]);
}

function ll_tools_offline_app_get_site_icon_attachment_id(): int {
    $attachment_id = (int) get_option('site_icon');
    if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
        return 0;
    }

    $file_path = get_attached_file($attachment_id);
    if (!is_string($file_path) || $file_path === '' || !is_file($file_path)) {
        return 0;
    }

    return $attachment_id;
}

function ll_tools_offline_app_get_attachment_icon_payload(int $attachment_id): array {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
        return [];
    }

    $attachment = get_post($attachment_id);
    if (!($attachment instanceof WP_Post) || $attachment->post_type !== 'attachment') {
        return [];
    }

    $source_path = wp_normalize_path((string) get_attached_file($attachment_id));
    if ($source_path === '' || !is_file($source_path)) {
        return [];
    }

    $preview_url = wp_get_attachment_image_url($attachment_id, [128, 128]);
    if (!is_string($preview_url) || $preview_url === '') {
        $preview_url = wp_get_attachment_url($attachment_id);
    }

    $label = trim((string) get_the_title($attachment_id));
    if ($label === '') {
        $label = wp_basename($source_path);
    }

    return [
        'attachment_id' => $attachment_id,
        'label'         => $label,
        'filename'      => wp_basename($source_path),
        'mime_type'     => (string) get_post_mime_type($attachment_id),
        'preview_url'   => is_string($preview_url) ? $preview_url : '',
        'source_path'   => $source_path,
    ];
}

function ll_tools_offline_app_resolve_icon_payload(int $override_attachment_id = 0) {
    $override_attachment_id = (int) $override_attachment_id;
    if ($override_attachment_id > 0) {
        $override_payload = ll_tools_offline_app_get_attachment_icon_payload($override_attachment_id);
        if (empty($override_payload)) {
            return new WP_Error(
                'll_tools_offline_app_invalid_icon_override',
                __('Choose a valid image for the offline app icon override.', 'll-tools-text-domain')
            );
        }

        $override_payload['source'] = 'custom';
        return $override_payload;
    }

    $site_icon_attachment_id = ll_tools_offline_app_get_site_icon_attachment_id();
    if ($site_icon_attachment_id > 0) {
        $site_icon_payload = ll_tools_offline_app_get_attachment_icon_payload($site_icon_attachment_id);
        if (!empty($site_icon_payload)) {
            $site_icon_payload['source'] = 'site_icon';
            return $site_icon_payload;
        }
    }

    return [];
}

function ll_tools_offline_app_candidate_source_paths(string $path): array {
    $path = ll_tools_sanitize_wordset_offline_stt_bundle_path($path);
    if ($path === '') {
        return [];
    }

    $candidates = [$path];
    if (preg_match('/^([a-z]):[\\\\\\/](.+)$/i', $path, $matches)) {
        $drive = strtolower((string) $matches[1]);
        $tail = str_replace('\\', '/', (string) $matches[2]);
        $candidates[] = '/mnt/' . $drive . '/' . ltrim($tail, '/');
    } elseif (preg_match('#^/mnt/([a-z])/(.+)$#i', $path, $matches)) {
        $drive = strtoupper((string) $matches[1]);
        $tail = str_replace('/', '\\', (string) $matches[2]);
        $candidates[] = $drive . ':\\' . ltrim($tail, '\\');
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        $normalized[$candidate] = true;
    }

    return array_keys($normalized);
}

function ll_tools_offline_app_resolve_source_path(string $path): string {
    $candidates = ll_tools_offline_app_candidate_source_paths($path);
    if (empty($candidates)) {
        return '';
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate) || is_dir($candidate)) {
            return wp_normalize_path($candidate);
        }
    }

    return wp_normalize_path((string) $candidates[0]);
}

function ll_tools_render_offline_app_export_page(): void {
    if (!ll_tools_current_user_can_offline_app_export()) {
        wp_die(__('You do not have permission to export offline app bundles.', 'll-tools-text-domain'));
    }

    wp_enqueue_media();

    $wordsets = get_terms([
        'taxonomy'   => 'wordset',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($wordsets)) {
        $wordsets = [];
    }

    $plugin_version = ll_tools_get_plugin_version_string();
    $default_app_name = get_bloginfo('name');
    $site_icon_payload = ll_tools_offline_app_get_attachment_icon_payload(ll_tools_offline_app_get_site_icon_attachment_id());
    $current_job = null;
    $requested_job_token = isset($_GET['ll_offline_job'])
        ? sanitize_text_field(wp_unslash((string) $_GET['ll_offline_job']))
        : '';
    if ($requested_job_token !== '') {
        $loaded_job = ll_tools_offline_app_export_load_job($requested_job_token);
        if (is_array($loaded_job)) {
            $current_job = ll_tools_offline_app_export_build_response($loaded_job);
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Offline App Export', 'll-tools-text-domain'); ?></h1>
        <p><?php esc_html_e('Build a self-contained offline quiz bundle for one word set. The bundle contains a standalone web app plus exported content and can be turned into an Android APK on an admin machine or CI.', 'll-tools-text-domain'); ?></p>

        <?php if (empty($wordsets)) : ?>
            <div class="notice notice-warning"><p><?php esc_html_e('Create at least one word set before exporting an offline app.', 'll-tools-text-domain'); ?></p></div>
        <?php endif; ?>

        <style>
            .ll-offline-category-list {
                margin-top: 10px;
                padding: 12px;
                max-width: 420px;
                max-height: 260px;
                overflow-y: auto;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                background: #fff;
            }
            .ll-offline-category-choice {
                display: block;
                margin: 0 0 8px;
            }
            .ll-offline-category-choice:last-child {
                margin-bottom: 0;
            }
            .ll-offline-app-icon-picker {
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 560px;
            }
            .ll-offline-app-icon-picker__preview {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .ll-offline-app-icon-picker__thumb {
                width: 68px;
                height: 68px;
                border: 1px solid #ccd0d4;
                border-radius: 16px;
                background: #fff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                box-sizing: border-box;
                flex: 0 0 auto;
            }
            .ll-offline-app-icon-picker__thumb img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            .ll-offline-app-icon-picker__thumb.is-empty {
                background: #f6f7f7;
                color: #50575e;
                font-size: 0.75rem;
                font-weight: 600;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }
            .ll-offline-app-icon-picker__meta {
                min-width: 0;
            }
            .ll-offline-app-icon-picker__status {
                margin: 0 0 4px;
                font-weight: 600;
            }
            .ll-offline-app-icon-picker__name {
                margin: 0;
                color: #50575e;
                word-break: break-word;
            }
            .ll-offline-app-icon-picker__actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
            }
            .ll-offline-export-job {
                max-width: 720px;
                margin-top: 18px;
                padding: 14px 16px;
                border: 1px solid #c3c4c7;
                border-left: 4px solid #2271b1;
                border-radius: 4px;
                background: #fff;
            }
            .ll-offline-export-job.is-failed {
                border-left-color: #d63638;
            }
            .ll-offline-export-job.is-complete {
                border-left-color: #00a32a;
            }
            .ll-offline-export-job [hidden] {
                display: none !important;
            }
            .ll-offline-export-job__title,
            .ll-offline-export-job__status,
            .ll-offline-export-job__error {
                margin: 0 0 8px;
            }
            .ll-offline-export-job progress {
                display: block;
                width: 100%;
                max-width: 560px;
                height: 16px;
                margin: 8px 0 12px;
            }
            .ll-offline-export-job__error {
                color: #b32d2e;
            }
        </style>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ll-offline-export-form">
            <?php wp_nonce_field('ll_tools_export_offline_app'); ?>
            <input type="hidden" name="action" value="ll_tools_export_offline_app">
            <input type="hidden" name="ll_offline_category_scope" id="ll-offline-category-scope" value="all">

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="ll-offline-wordset-id"><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?></label>
                        </th>
                        <td>
                            <select name="ll_offline_wordset_id" id="ll-offline-wordset-id" required>
                                <option value=""><?php esc_html_e('Select a word set', 'll-tools-text-domain'); ?></option>
                                <?php foreach ($wordsets as $wordset) : ?>
                                    <option value="<?php echo esc_attr((string) $wordset->term_id); ?>">
                                        <?php echo esc_html($wordset->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Exactly one word set is exported per offline app bundle.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ll-offline-include-all-categories"><?php esc_html_e('Categories', 'll-tools-text-domain'); ?></label>
                        </th>
                        <td>
                            <fieldset id="ll-offline-category-fieldset" disabled>
                                <label for="ll-offline-include-all-categories">
                                    <input type="checkbox" id="ll-offline-include-all-categories" value="1" checked>
                                    <?php esc_html_e('Include all categories in this word set', 'll-tools-text-domain'); ?>
                                </label>

                                <p class="description" id="ll-offline-category-help">
                                    <?php esc_html_e('Select a word set first. Then you can keep all categories selected or choose specific ones.', 'll-tools-text-domain'); ?>
                                </p>

                                <p class="description" id="ll-offline-category-empty" hidden>
                                    <?php esc_html_e('No categories with published words were found for the selected word set.', 'll-tools-text-domain'); ?>
                                </p>

                                <div id="ll-offline-category-list-wrap" hidden>
                                    <div id="ll-offline-category-list" class="ll-offline-category-list" aria-live="polite"></div>
                                </div>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ll-offline-app-name"><?php esc_html_e('App Name', 'll-tools-text-domain'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" name="ll_offline_app_name" id="ll-offline-app-name" value="<?php echo esc_attr($default_app_name); ?>" required>
                            <p class="description"><?php esc_html_e('Used in the offline app shell and Android build metadata.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('App Icon', 'll-tools-text-domain'); ?></th>
                        <td>
                            <div class="ll-offline-app-icon-picker">
                                <div class="ll-offline-app-icon-picker__preview">
                                    <div class="ll-offline-app-icon-picker__thumb<?php echo empty($site_icon_payload) ? ' is-empty' : ''; ?>" id="ll-offline-app-icon-thumb">
                                        <img
                                            id="ll-offline-app-icon-preview"
                                            src="<?php echo !empty($site_icon_payload['preview_url']) ? esc_url((string) $site_icon_payload['preview_url']) : ''; ?>"
                                            alt=""
                                            <?php echo empty($site_icon_payload['preview_url']) ? 'hidden' : ''; ?>
                                        >
                                        <span id="ll-offline-app-icon-empty" <?php echo empty($site_icon_payload['preview_url']) ? '' : 'hidden'; ?>>
                                            <?php esc_html_e('No icon', 'll-tools-text-domain'); ?>
                                        </span>
                                    </div>
                                    <div class="ll-offline-app-icon-picker__meta">
                                        <p class="ll-offline-app-icon-picker__status" id="ll-offline-app-icon-status">
                                            <?php
                                            if (!empty($site_icon_payload)) {
                                                esc_html_e('Using the current site icon.', 'll-tools-text-domain');
                                            } else {
                                                esc_html_e('No app icon selected yet.', 'll-tools-text-domain');
                                            }
                                            ?>
                                        </p>
                                        <p class="ll-offline-app-icon-picker__name" id="ll-offline-app-icon-name">
                                            <?php
                                            if (!empty($site_icon_payload['label'])) {
                                                echo esc_html((string) $site_icon_payload['label']);
                                            } else {
                                                esc_html_e('The Android builder will keep its default launcher icon unless you choose one here.', 'll-tools-text-domain');
                                            }
                                            ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="ll-offline-app-icon-picker__actions">
                                    <input type="hidden" name="ll_offline_app_icon_attachment_id" id="ll-offline-app-icon-attachment-id" value="">
                                    <button type="button" class="button" id="ll-offline-app-icon-choose">
                                        <?php esc_html_e('Choose Custom Icon', 'll-tools-text-domain'); ?>
                                    </button>
                                    <button type="button" class="button-link" id="ll-offline-app-icon-reset" hidden>
                                        <?php esc_html_e('Use Site Icon', 'll-tools-text-domain'); ?>
                                    </button>
                                </div>
                                <p class="description">
                                    <?php
                                    if (!empty($site_icon_payload)) {
                                        esc_html_e('The bundle uses this site\'s icon by default. Choose another image to override it for this export only. Square images around 512x512 work best.', 'll-tools-text-domain');
                                    } else {
                                        esc_html_e('No site icon is set for this WordPress site. Choose a square image if you want the Android build to use a custom launcher icon.', 'll-tools-text-domain');
                                    }
                                    ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ll-offline-app-id-suffix"><?php esc_html_e('Android App ID Suffix', 'll-tools-text-domain'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" name="ll_offline_app_id_suffix" id="ll-offline-app-id-suffix" value="offline.quiz">
                            <p class="description"><?php esc_html_e('Used to build the Android application ID. Letters, numbers, underscores, and dots are allowed; the plugin will sanitize it.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ll-offline-version-name"><?php esc_html_e('Version Name', 'll-tools-text-domain'); ?></label>
                        </th>
                        <td>
                            <input type="text" class="regular-text" name="ll_offline_version_name" id="ll-offline-version-name" value="<?php echo esc_attr($plugin_version); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ll-offline-version-code"><?php esc_html_e('Version Code', 'll-tools-text-domain'); ?></label>
                        </th>
                        <td>
                            <input type="number" class="small-text" min="1" step="1" name="ll_offline_version_code" id="ll-offline-version-code" value="1" required>
                            <p class="description"><?php esc_html_e('Integer version for Android builds. Increase this when distributing an updated APK.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="description"><?php esc_html_e('The offline bundle exports the standalone APK shell plus bundled media for supported quiz modes. Learners can keep using the app locally, then optionally link a site account later to sync study state and progress when they reconnect.', 'll-tools-text-domain'); ?></p>
            <p>
                <button type="submit" class="button button-primary" id="ll-offline-export-submit" <?php disabled(empty($wordsets)); ?>>
                    <?php esc_html_e('Build Offline App Bundle', 'll-tools-text-domain'); ?>
                </button>
            </p>
        </form>
        <div class="ll-offline-export-job" id="ll-offline-export-job" aria-live="polite" hidden>
            <p class="ll-offline-export-job__title"><strong id="ll-offline-export-job-phase"></strong></p>
            <p class="ll-offline-export-job__status" id="ll-offline-export-job-status"></p>
            <progress id="ll-offline-export-job-progress" max="100" value="0"></progress>
            <p class="ll-offline-export-job__error" id="ll-offline-export-job-error" hidden></p>
            <p><a class="button button-primary" id="ll-offline-export-job-download" href="#" hidden><?php esc_html_e('Download Offline App Bundle (.zip)', 'll-tools-text-domain'); ?></a></p>
        </div>
    </div>
    <script>
        (function () {
            const categoriesByWordset = {};
            const categoryLoadErrors = {};
            const categoryLoads = {};
            const categoryEndpoint = <?php echo wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => 'll_tools_offline_app_export_categories',
                'nonce' => wp_create_nonce('ll_tools_offline_app_export_categories'),
            ]); ?>;
            const exportJobEndpoint = <?php echo wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'startAction' => 'll_tools_offline_app_export_start',
                'stepAction' => 'll_tools_offline_app_export_step',
                'nonce' => wp_create_nonce('ll_tools_offline_app_export_job'),
            ]); ?>;
            const currentJob = <?php echo wp_json_encode($current_job); ?>;
            const siteIcon = <?php echo wp_json_encode(!empty($site_icon_payload) ? [
                'attachmentId' => (int) ($site_icon_payload['attachment_id'] ?? 0),
                'label'        => (string) ($site_icon_payload['label'] ?? ''),
                'previewUrl'   => (string) ($site_icon_payload['preview_url'] ?? ''),
            ] : null); ?>;
            const strings = <?php echo wp_json_encode([
                'selectWordsetFirst' => __('Select a word set first. Then you can keep all categories selected or choose specific ones.', 'll-tools-text-domain'),
                'allCategories'      => __('Every category with published words in the selected word set will be included.', 'll-tools-text-domain'),
                'pickSpecific'       => __('Choose the specific categories to include from this word set.', 'll-tools-text-domain'),
                'noCategories'       => __('No categories with published words were found for the selected word set.', 'll-tools-text-domain'),
                'loadingCategories'  => __('Loading categories...', 'll-tools-text-domain'),
                'categoryLoadFailed' => __('Categories could not be loaded. Select the word set again to retry.', 'll-tools-text-domain'),
                'usingSiteIcon'      => __('Using the current site icon.', 'll-tools-text-domain'),
                'usingCustomIcon'    => __('Using a custom icon for this export only.', 'll-tools-text-domain'),
                'noIconSelected'     => __('No app icon selected yet.', 'll-tools-text-domain'),
                'noIconHelp'         => __('The Android builder will keep its default launcher icon unless you choose one here.', 'll-tools-text-domain'),
                'chooseIconTitle'    => __('Choose Offline App Icon', 'll-tools-text-domain'),
                'chooseIconButton'   => __('Use This Icon', 'll-tools-text-domain'),
                'useSiteIcon'        => __('Use Site Icon', 'll-tools-text-domain'),
                'clearCustomIcon'    => __('Clear Custom Icon', 'll-tools-text-domain'),
                'noIconLabel'        => __('No icon', 'll-tools-text-domain'),
                'requestFailed'      => __('The export request failed. You can retry the build without losing a completed bundle.', 'll-tools-text-domain'),
            ]); ?>;

            const wordsetField = document.getElementById('ll-offline-wordset-id');
            const categoryFieldset = document.getElementById('ll-offline-category-fieldset');
            const categoryScopeField = document.getElementById('ll-offline-category-scope');
            const includeAllField = document.getElementById('ll-offline-include-all-categories');
            const categoryHelp = document.getElementById('ll-offline-category-help');
            const categoryEmpty = document.getElementById('ll-offline-category-empty');
            const categoryListWrap = document.getElementById('ll-offline-category-list-wrap');
            const categoryList = document.getElementById('ll-offline-category-list');
            const submitButton = document.getElementById('ll-offline-export-submit');
            const exportForm = document.getElementById('ll-offline-export-form');
            const jobPanel = document.getElementById('ll-offline-export-job');
            const jobPhase = document.getElementById('ll-offline-export-job-phase');
            const jobStatus = document.getElementById('ll-offline-export-job-status');
            const jobProgress = document.getElementById('ll-offline-export-job-progress');
            const jobError = document.getElementById('ll-offline-export-job-error');
            const jobDownload = document.getElementById('ll-offline-export-job-download');
            const iconAttachmentField = document.getElementById('ll-offline-app-icon-attachment-id');
            const iconChooseButton = document.getElementById('ll-offline-app-icon-choose');
            const iconResetButton = document.getElementById('ll-offline-app-icon-reset');
            const iconThumb = document.getElementById('ll-offline-app-icon-thumb');
            const iconPreview = document.getElementById('ll-offline-app-icon-preview');
            const iconEmpty = document.getElementById('ll-offline-app-icon-empty');
            const iconStatus = document.getElementById('ll-offline-app-icon-status');
            const iconName = document.getElementById('ll-offline-app-icon-name');
            const selectedByWordset = {};
            let customIcon = null;
            let mediaFrame = null;
            let jobRunning = false;

            if (!wordsetField || !categoryFieldset || !categoryScopeField || !includeAllField || !categoryHelp || !categoryEmpty || !categoryListWrap || !categoryList || !submitButton) {
                return;
            }

            function getCurrentWordsetId() {
                return String(wordsetField.value || '').trim();
            }

            function getCurrentCategories() {
                const wordsetId = getCurrentWordsetId();
                if (!wordsetId || !Array.isArray(categoriesByWordset[wordsetId])) {
                    return [];
                }
                return categoriesByWordset[wordsetId];
            }

            function loadCategories(wordsetId) {
                wordsetId = String(wordsetId || '').trim();
                if (!wordsetId) {
                    updateCategoryUi();
                    return Promise.resolve([]);
                }
                if (Object.prototype.hasOwnProperty.call(categoriesByWordset, wordsetId)) {
                    updateCategoryUi();
                    return Promise.resolve(getCurrentCategories());
                }
                if (categoryLoads[wordsetId]) {
                    return categoryLoads[wordsetId];
                }
                if (!window.fetch || !window.FormData || !categoryEndpoint.ajaxUrl) {
                    categoryLoadErrors[wordsetId] = true;
                    updateCategoryUi();
                    return Promise.resolve([]);
                }

                delete categoryLoadErrors[wordsetId];
                const payload = new FormData();
                payload.set('action', categoryEndpoint.action);
                payload.set('nonce', categoryEndpoint.nonce);
                payload.set('wordset_id', wordsetId);

                categoryLoads[wordsetId] = fetch(categoryEndpoint.ajaxUrl, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin'
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('category_load_failed');
                    }
                    return response.json();
                }).then(function (response) {
                    if (!response || !response.success || !response.data || !Array.isArray(response.data.categories)) {
                        throw new Error('category_load_failed');
                    }
                    categoriesByWordset[wordsetId] = response.data.categories;
                    return categoriesByWordset[wordsetId];
                }).catch(function () {
                    categoryLoadErrors[wordsetId] = true;
                    return [];
                }).finally(function () {
                    delete categoryLoads[wordsetId];
                    if (getCurrentWordsetId() === wordsetId) {
                        updateCategoryUi();
                    }
                });
                updateCategoryUi();

                return categoryLoads[wordsetId];
            }

            function rememberSelections(wordsetId) {
                if (!wordsetId) {
                    return;
                }

                selectedByWordset[wordsetId] = Array.from(
                    categoryList.querySelectorAll('input[name="ll_offline_category_ids[]"]:checked')
                ).map(function (input) {
                    return String(input.value || '');
                });
            }

            function buildCategoryChoices(categories, wordsetId, disabled) {
                const selectedLookup = new Set(Array.isArray(selectedByWordset[wordsetId]) ? selectedByWordset[wordsetId] : []);
                categoryList.textContent = '';

                categories.forEach(function (category) {
                    const label = document.createElement('label');
                    label.className = 'll-offline-category-choice';

                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.name = 'll_offline_category_ids[]';
                    input.value = String(category.id || '');
                    input.checked = selectedLookup.has(String(category.id || ''));
                    input.disabled = disabled;
                    input.addEventListener('change', function () {
                        rememberSelections(wordsetId);
                    });

                    label.appendChild(input);
                    label.appendChild(document.createTextNode(' ' + String(category.name || '')));
                    categoryList.appendChild(label);
                });
            }

            function updateCategoryUi() {
                const wordsetId = getCurrentWordsetId();
                const categories = getCurrentCategories();
                const hasWordset = wordsetId !== '';
                const isLoading = hasWordset && !!categoryLoads[wordsetId];
                const loadFailed = hasWordset && !!categoryLoadErrors[wordsetId];
                const hasLoaded = hasWordset && Object.prototype.hasOwnProperty.call(categoriesByWordset, wordsetId);
                const hasCategories = categories.length > 0;
                const includeAll = includeAllField.checked;

                categoryFieldset.disabled = !hasWordset || isLoading || loadFailed;
                includeAllField.disabled = !hasWordset || !hasCategories || isLoading || loadFailed;
                categoryScopeField.value = includeAll ? 'all' : 'custom';

                categoryHelp.textContent = strings.selectWordsetFirst;
                if (isLoading || (hasWordset && !hasLoaded && !loadFailed)) {
                    categoryHelp.textContent = strings.loadingCategories;
                } else if (loadFailed) {
                    categoryHelp.textContent = strings.categoryLoadFailed;
                } else if (hasWordset && hasCategories) {
                    categoryHelp.textContent = includeAll ? strings.allCategories : strings.pickSpecific;
                }

                categoryEmpty.hidden = !hasWordset || !hasLoaded || hasCategories || isLoading || loadFailed;
                categoryEmpty.textContent = strings.noCategories;

                buildCategoryChoices(categories, wordsetId, !hasWordset || includeAll);

                categoryListWrap.hidden = !hasWordset || !hasCategories || includeAll;
                submitButton.disabled = jobRunning || <?php echo empty($wordsets) ? 'true' : 'false'; ?> || !hasWordset || !hasCategories || isLoading || loadFailed;
            }

            function getEffectiveIcon() {
                return customIcon || siteIcon || null;
            }

            function renderIconState() {
                if (!iconAttachmentField || !iconResetButton || !iconThumb || !iconPreview || !iconEmpty || !iconStatus || !iconName) {
                    return;
                }

                const effectiveIcon = getEffectiveIcon();
                const hasSiteIcon = !!(siteIcon && siteIcon.attachmentId);
                const isCustom = !!customIcon;

                iconAttachmentField.value = isCustom ? String(customIcon.attachmentId || '') : '';
                iconResetButton.hidden = !isCustom;
                iconResetButton.textContent = hasSiteIcon ? strings.useSiteIcon : strings.clearCustomIcon;

                if (effectiveIcon && effectiveIcon.previewUrl) {
                    iconPreview.src = String(effectiveIcon.previewUrl);
                    iconPreview.hidden = false;
                    iconEmpty.hidden = true;
                    iconThumb.classList.remove('is-empty');
                    iconStatus.textContent = isCustom ? strings.usingCustomIcon : strings.usingSiteIcon;
                    iconName.textContent = String(effectiveIcon.label || '');
                    return;
                }

                iconPreview.hidden = true;
                iconPreview.removeAttribute('src');
                iconEmpty.hidden = false;
                iconEmpty.textContent = strings.noIconLabel;
                iconThumb.classList.add('is-empty');
                iconStatus.textContent = strings.noIconSelected;
                iconName.textContent = strings.noIconHelp;
            }

            function getAttachmentPreviewUrl(attachment) {
                if (!attachment || typeof attachment !== 'object') {
                    return '';
                }

                if (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) {
                    return String(attachment.sizes.medium.url);
                }

                if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                    return String(attachment.sizes.thumbnail.url);
                }

                return attachment.url ? String(attachment.url) : '';
            }

            wordsetField.addEventListener('change', function () {
                const previousWordsetId = categoryList.getAttribute('data-wordset-id') || '';
                if (previousWordsetId !== '') {
                    rememberSelections(previousWordsetId);
                }

                categoryList.setAttribute('data-wordset-id', getCurrentWordsetId());
                loadCategories(getCurrentWordsetId());
            });

            includeAllField.addEventListener('change', function () {
                const wordsetId = getCurrentWordsetId();
                if (wordsetId !== '') {
                    rememberSelections(wordsetId);
                }
                updateCategoryUi();
            });

            if (iconChooseButton && iconAttachmentField && window.wp && wp.media) {
                iconChooseButton.addEventListener('click', function () {
                    if (!mediaFrame) {
                        mediaFrame = wp.media({
                            title: strings.chooseIconTitle,
                            button: { text: strings.chooseIconButton },
                            multiple: false,
                            library: { type: 'image' }
                        });

                        mediaFrame.on('select', function () {
                            const selection = mediaFrame.state().get('selection');
                            const attachment = selection && selection.first ? selection.first().toJSON() : null;
                            if (!attachment || !attachment.id) {
                                return;
                            }

                            customIcon = {
                                attachmentId: Number(attachment.id) || 0,
                                label: String(attachment.title || attachment.filename || ''),
                                previewUrl: getAttachmentPreviewUrl(attachment)
                            };
                            renderIconState();
                        });
                    }

                    mediaFrame.open();
                });
            }

            if (iconResetButton) {
                iconResetButton.addEventListener('click', function () {
                    customIcon = null;
                    renderIconState();
                });
            }

            function renderJob(job) {
                if (!jobPanel || !jobPhase || !jobStatus || !jobProgress || !jobError || !jobDownload) {
                    return;
                }

                const status = String((job && job.status) || 'failed');
                const progress = Math.max(0, Math.min(100, Number((job && job.progress) || 0)));
                jobPanel.hidden = false;
                jobPanel.classList.toggle('is-failed', status === 'failed');
                jobPanel.classList.toggle('is-complete', status === 'completed');
                jobPhase.textContent = String((job && job.phaseLabel) || '');
                jobStatus.textContent = String((job && job.statusText) || '');
                jobProgress.value = progress;
                jobError.hidden = status !== 'failed';
                jobError.textContent = status === 'failed' ? String((job && job.error) || strings.requestFailed) : '';
                jobDownload.hidden = status !== 'completed' || !(job && job.downloadUrl);
                if (!jobDownload.hidden) {
                    jobDownload.href = String(job.downloadUrl);
                }

                jobRunning = status === 'queued' || status === 'processing';
                updateCategoryUi();
            }

            function rememberJobToken(token) {
                token = String(token || '').trim();
                if (!token || !window.history || !window.history.replaceState) {
                    return;
                }
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('ll_offline_job', token);
                    window.history.replaceState({}, '', url.toString());
                } catch (error) {
                    // The export still runs; the current page simply cannot persist its resume URL.
                }
            }

            function parseJobResponse(response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload || !payload.success || !payload.data) {
                        const message = payload && payload.data && payload.data.message
                            ? String(payload.data.message)
                            : strings.requestFailed;
                        throw new Error(message);
                    }
                    return payload.data;
                });
            }

            function requestJobStep(token) {
                if (!jobRunning || !token) {
                    return;
                }
                const payload = new FormData();
                payload.set('action', exportJobEndpoint.stepAction);
                payload.set('nonce', exportJobEndpoint.nonce);
                payload.set('token', token);

                fetch(exportJobEndpoint.ajaxUrl, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin'
                }).then(parseJobResponse).then(function (job) {
                    renderJob(job);
                    if (jobRunning) {
                        window.setTimeout(function () {
                            requestJobStep(String(job.token || token));
                        }, 120);
                    }
                }).catch(function (error) {
                    renderJob({
                        status: 'failed',
                        phaseLabel: '',
                        statusText: '',
                        error: error && error.message ? error.message : strings.requestFailed,
                        progress: 0
                    });
                });
            }

            if (exportForm && window.fetch && window.FormData) {
                exportForm.addEventListener('submit', function (event) {
                    event.preventDefault();
                    if (jobRunning) {
                        return;
                    }

                    jobRunning = true;
                    updateCategoryUi();
                    const payload = new FormData(exportForm);
                    payload.set('action', exportJobEndpoint.startAction);
                    payload.set('nonce', exportJobEndpoint.nonce);

                    fetch(exportJobEndpoint.ajaxUrl, {
                        method: 'POST',
                        body: payload,
                        credentials: 'same-origin'
                    }).then(parseJobResponse).then(function (job) {
                        rememberJobToken(job.token);
                        renderJob(job);
                        if (jobRunning) {
                            requestJobStep(String(job.token || ''));
                        }
                    }).catch(function (error) {
                        renderJob({
                            status: 'failed',
                            phaseLabel: '',
                            statusText: '',
                            error: error && error.message ? error.message : strings.requestFailed,
                            progress: 0
                        });
                    });
                });
            }

            categoryList.setAttribute('data-wordset-id', getCurrentWordsetId());
            updateCategoryUi();
            renderIconState();
            if (currentJob && currentJob.token) {
                renderJob(currentJob);
                if (jobRunning) {
                    requestJobStep(String(currentJob.token));
                }
            }
        })();
    </script>
    <?php
}

function ll_tools_offline_app_get_wordset_category_options(int $wordset_id): array {
    static $request_cache = [];

    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    if (isset($request_cache[$wordset_id])) {
        return $request_cache[$wordset_id];
    }

    $category_ids = [];
    if (function_exists('ll_tools_word_option_rules_get_wordset_category_ids')) {
        $category_ids = ll_tools_word_option_rules_get_wordset_category_ids($wordset_id);
    } else {
        global $wpdb;

        $sql = $wpdb->prepare(
            "
            SELECT DISTINCT tt_cat.term_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
            INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
            WHERE p.post_type = %s
              AND p.post_status = %s
              AND tt_ws.taxonomy = %s
              AND tt_ws.term_id = %d
              AND tt_cat.taxonomy = %s
            ",
            'words',
            'publish',
            'wordset',
            $wordset_id,
            'word-category'
        );

        $category_ids = array_map('intval', (array) $wpdb->get_col($sql));
    }

    $category_ids = ll_tools_offline_app_normalize_id_list($category_ids);
    if (empty($category_ids)) {
        $request_cache[$wordset_id] = [];
        return [];
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'include'    => $category_ids,
    ]);
    if (is_wp_error($terms)) {
        $terms = [];
    }

    if (function_exists('ll_tools_filter_category_terms_for_user')) {
        $terms = ll_tools_filter_category_terms_for_user((array) $terms);
    }

    $terms_by_id = [];
    $category_name_map = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term) || $term->taxonomy !== 'word-category') {
            continue;
        }
        if ((string) $term->slug === 'uncategorized') {
            continue;
        }

        $term_id = (int) $term->term_id;
        if ($term_id <= 0) {
            continue;
        }

        $terms_by_id[$term_id] = $term;
        $category_name_map[$term_id] = (string) $term->name;
    }

    $ordered_ids = array_keys($terms_by_id);
    if (!empty($ordered_ids) && function_exists('ll_tools_wordset_sort_category_ids')) {
        $ordered_ids = ll_tools_wordset_sort_category_ids($ordered_ids, $wordset_id, [
            'category_name_map' => $category_name_map,
        ]);
    } else {
        usort($ordered_ids, static function (int $left, int $right) use ($category_name_map): int {
            $left_name = (string) ($category_name_map[$left] ?? '');
            $right_name = (string) ($category_name_map[$right] ?? '');
            if (function_exists('ll_tools_locale_compare_strings')) {
                return ll_tools_locale_compare_strings($left_name, $right_name);
            }
            return strnatcasecmp($left_name, $right_name);
        });
    }

    $options = [];
    foreach ($ordered_ids as $category_id) {
        if (!isset($terms_by_id[$category_id])) {
            continue;
        }

        $term = $terms_by_id[$category_id];
        $options[] = [
            'id'   => (int) $term->term_id,
            'slug' => (string) $term->slug,
            'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
        ];
    }

    $request_cache[$wordset_id] = $options;
    return $request_cache[$wordset_id];
}

function ll_tools_offline_app_parse_export_request(array $request) {
    $wordset_id = isset($request['ll_offline_wordset_id']) ? (int) wp_unslash((string) $request['ll_offline_wordset_id']) : 0;
    if ($wordset_id <= 0) {
        return new WP_Error('ll_tools_offline_app_missing_wordset', __('Select a word set to export.', 'll-tools-text-domain'));
    }

    $available_categories = ll_tools_offline_app_get_wordset_category_options($wordset_id);
    $available_category_ids = ll_tools_offline_app_normalize_id_list(wp_list_pluck($available_categories, 'id'));
    if (empty($available_category_ids)) {
        return new WP_Error(
            'll_tools_offline_app_no_available_categories',
            __('The selected word set has no categories with published words available for offline export.', 'll-tools-text-domain')
        );
    }

    $selected_category_ids = [];
    if (array_key_exists('ll_offline_category_ids', $request)) {
        $raw_selected_category_ids = $request['ll_offline_category_ids'];
        if (!is_array($raw_selected_category_ids)) {
            $raw_selected_category_ids = [$raw_selected_category_ids];
        }

        $selected_category_ids = ll_tools_offline_app_normalize_id_list((array) wp_unslash($raw_selected_category_ids));
    } else {
        $category_scope = isset($request['ll_offline_category_scope'])
            ? sanitize_key(wp_unslash((string) $request['ll_offline_category_scope']))
            : 'all';
        if ($category_scope === 'custom') {
            return new WP_Error(
                'll_tools_offline_app_missing_categories',
                __('Select at least one category to export.', 'll-tools-text-domain')
            );
        }

        $selected_category_ids = $available_category_ids;
    }

    if (empty($selected_category_ids)) {
        return new WP_Error(
            'll_tools_offline_app_missing_categories',
            __('Select at least one category to export.', 'll-tools-text-domain')
        );
    }

    $invalid_category_ids = array_values(array_diff($selected_category_ids, $available_category_ids));
    if (!empty($invalid_category_ids)) {
        return new WP_Error(
            'll_tools_offline_app_invalid_categories',
            __('One or more selected categories are not available in the selected word set.', 'll-tools-text-domain')
        );
    }

    $app_name = isset($request['ll_offline_app_name'])
        ? sanitize_text_field(wp_unslash((string) $request['ll_offline_app_name']))
        : '';
    if ($app_name === '') {
        $app_name = get_bloginfo('name');
    }

    $version_name = isset($request['ll_offline_version_name'])
        ? sanitize_text_field(wp_unslash((string) $request['ll_offline_version_name']))
        : ll_tools_get_plugin_version_string();
    if ($version_name === '') {
        $version_name = '1.0.0';
    }

    $version_code = isset($request['ll_offline_version_code']) ? (int) wp_unslash((string) $request['ll_offline_version_code']) : 1;
    if ($version_code < 1) {
        $version_code = 1;
    }

    $app_id_suffix = isset($request['ll_offline_app_id_suffix'])
        ? sanitize_text_field(wp_unslash((string) $request['ll_offline_app_id_suffix']))
        : '';
    $app_icon_attachment_id = isset($request['ll_offline_app_icon_attachment_id'])
        ? (int) wp_unslash((string) $request['ll_offline_app_icon_attachment_id'])
        : 0;

    return [
        'wordset_id'             => $wordset_id,
        'category_ids'           => $selected_category_ids,
        'app_name'               => $app_name,
        'version_name'           => $version_name,
        'version_code'           => $version_code,
        'app_id_suffix'          => $app_id_suffix,
        'app_icon_attachment_id' => $app_icon_attachment_id,
    ];
}

function ll_tools_offline_app_export_category_batch_size(): int {
    return max(1, min(25, (int) apply_filters('ll_tools_offline_app_export_category_batch_size', 5)));
}

function ll_tools_offline_app_export_word_batch_size(): int {
    return max(1, min(100, (int) apply_filters('ll_tools_offline_app_export_word_batch_size', 20)));
}

function ll_tools_offline_app_export_media_batch_size(): int {
    return max(1, min(100, (int) apply_filters('ll_tools_offline_app_export_media_batch_size', 25)));
}

function ll_tools_offline_app_export_data_batch_size(): int {
    return max(1, min(100, (int) apply_filters('ll_tools_offline_app_export_data_batch_size', 25)));
}

function ll_tools_offline_app_export_zip_batch_size(): int {
    return max(1, min(200, (int) apply_filters('ll_tools_offline_app_export_zip_batch_size', 50)));
}

function ll_tools_offline_app_export_job_ttl(): int {
    return max(HOUR_IN_SECONDS, min(7 * DAY_IN_SECONDS, (int) apply_filters('ll_tools_offline_app_export_job_ttl', DAY_IN_SECONDS)));
}

function ll_tools_offline_app_export_job_option_key(string $token): string {
    return 'll_tools_offline_export_job_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
}

function ll_tools_offline_app_export_job_lock_key(string $token): string {
    return 'll_tools_offline_export_lock_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
}

function ll_tools_offline_app_export_storage_dir() {
    $uploads = wp_upload_dir();
    $base_dir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
    if ($base_dir === '') {
        return new WP_Error('ll_tools_offline_app_job_uploads_missing', __('Could not access the uploads directory for the offline app export job.', 'll-tools-text-domain'));
    }

    $storage_dir = trailingslashit($base_dir) . 'll-tools-offline-app-jobs';
    if (!wp_mkdir_p($storage_dir)) {
        return new WP_Error('ll_tools_offline_app_job_storage_failed', __('Could not create storage for the offline app export job.', 'll-tools-text-domain'));
    }

    return wp_normalize_path($storage_dir);
}

function ll_tools_offline_app_export_job_dir_is_safe(string $job_dir): bool {
    $storage_dir = ll_tools_offline_app_export_storage_dir();
    if (is_wp_error($storage_dir)) {
        return false;
    }
    $resolved_storage = realpath((string) $storage_dir);
    $resolved_job = realpath($job_dir);
    if (!is_string($resolved_storage) || !is_string($resolved_job)) {
        return false;
    }
    $storage_prefix = strtolower(trailingslashit(wp_normalize_path($resolved_storage)));
    $job_prefix = strtolower(trailingslashit(wp_normalize_path($resolved_job)));
    return $job_prefix !== $storage_prefix && strpos($job_prefix, $storage_prefix) === 0;
}

function ll_tools_offline_app_export_write_json(string $path, array $data) {
    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        return new WP_Error('ll_tools_offline_app_job_json_failed', __('Could not encode offline app export job state.', 'll-tools-text-domain'));
    }
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_job_write_failed', __('Could not save offline app export job state.', 'll-tools-text-domain'));
    }
    return true;
}

function ll_tools_offline_app_export_save_job(array $job) {
    $manifest_path = wp_normalize_path((string) ($job['manifest_path'] ?? ''));
    if ($manifest_path === '') {
        return new WP_Error('ll_tools_offline_app_job_manifest_missing', __('Offline app export job storage is missing.', 'll-tools-text-domain'));
    }
    $job['updated_at'] = time();
    return ll_tools_offline_app_export_write_json($manifest_path, $job);
}

function ll_tools_offline_app_export_load_job(string $token, bool $enforce_owner = true) {
    $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
    if ($token === '') {
        return new WP_Error('ll_tools_offline_app_job_token_missing', __('Offline app export job token is missing.', 'll-tools-text-domain'));
    }

    $pointer = get_option(ll_tools_offline_app_export_job_option_key($token), null);
    if (!is_array($pointer)) {
        return new WP_Error('ll_tools_offline_app_job_not_found', __('The offline app export job could not be found. Start a new export.', 'll-tools-text-domain'));
    }
    if ($enforce_owner && (int) ($pointer['created_by'] ?? 0) !== get_current_user_id()) {
        return new WP_Error('ll_tools_offline_app_job_forbidden', __('You do not have permission to access this offline app export job.', 'll-tools-text-domain'));
    }
    if ((int) ($pointer['expires_at'] ?? 0) > 0 && (int) $pointer['expires_at'] < time()) {
        delete_option(ll_tools_offline_app_export_job_option_key($token));
        $expired_manifest = wp_normalize_path((string) ($pointer['manifest_path'] ?? ''));
        $expired_job_dir = $expired_manifest !== '' ? dirname($expired_manifest) : '';
        if ($expired_job_dir !== '' && ll_tools_offline_app_export_job_dir_is_safe($expired_job_dir) && is_dir($expired_job_dir)) {
            ll_tools_rrmdir($expired_job_dir);
        }
        return new WP_Error('ll_tools_offline_app_job_expired', __('This offline app export job expired. Start a new export.', 'll-tools-text-domain'));
    }

    $manifest_path = wp_normalize_path((string) ($pointer['manifest_path'] ?? ''));
    if ($manifest_path === '' || !is_file($manifest_path) || !is_readable($manifest_path)) {
        return new WP_Error('ll_tools_offline_app_job_manifest_missing', __('Offline app export job state is missing. Start a new export.', 'll-tools-text-domain'));
    }
    $raw = file_get_contents($manifest_path);
    $job = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($job) || (string) ($job['token'] ?? '') !== $token) {
        return new WP_Error('ll_tools_offline_app_job_manifest_invalid', __('Offline app export job state is invalid. Start a new export.', 'll-tools-text-domain'));
    }
    if ($enforce_owner && (int) ($job['created_by'] ?? 0) !== get_current_user_id()) {
        return new WP_Error('ll_tools_offline_app_job_forbidden', __('You do not have permission to access this offline app export job.', 'll-tools-text-domain'));
    }
    $job['manifest_path'] = $manifest_path;
    return $job;
}

function ll_tools_offline_app_export_prepare_job(array $request) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('ll_tools_offline_app_zip_unavailable', __('ZipArchive is not available on this server.', 'll-tools-text-domain'));
    }

    $wordset_id = isset($request['ll_offline_wordset_id']) ? (int) wp_unslash((string) $request['ll_offline_wordset_id']) : 0;
    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        return new WP_Error('ll_tools_offline_app_missing_wordset', __('Select a valid word set to export.', 'll-tools-text-domain'));
    }

    $category_scope = isset($request['ll_offline_category_scope'])
        ? sanitize_key(wp_unslash((string) $request['ll_offline_category_scope']))
        : 'all';
    $category_scope = ($category_scope === 'custom') ? 'custom' : 'all';
    $requested_category_ids = [];
    if (isset($request['ll_offline_category_ids'])) {
        $raw_category_ids = is_array($request['ll_offline_category_ids'])
            ? $request['ll_offline_category_ids']
            : [$request['ll_offline_category_ids']];
        $requested_category_ids = ll_tools_offline_app_normalize_id_list((array) wp_unslash($raw_category_ids));
    }
    if ($category_scope === 'custom' && empty($requested_category_ids)) {
        return new WP_Error('ll_tools_offline_app_missing_categories', __('Select at least one category to export.', 'll-tools-text-domain'));
    }

    $app_name = isset($request['ll_offline_app_name'])
        ? sanitize_text_field(wp_unslash((string) $request['ll_offline_app_name']))
        : '';
    if ($app_name === '') {
        $app_name = get_bloginfo('name');
    }
    $version_name = isset($request['ll_offline_version_name'])
        ? sanitize_text_field(wp_unslash((string) $request['ll_offline_version_name']))
        : ll_tools_get_plugin_version_string();
    if ($version_name === '') {
        $version_name = '1.0.0';
    }
    $version_code = max(1, isset($request['ll_offline_version_code']) ? (int) $request['ll_offline_version_code'] : 1);
    $app_id_suffix = ll_tools_offline_app_sanitize_app_id_suffix(
        isset($request['ll_offline_app_id_suffix']) ? wp_unslash((string) $request['ll_offline_app_id_suffix']) : '',
        $wordset
    );
    $app_icon_attachment_id = isset($request['ll_offline_app_icon_attachment_id'])
        ? (int) wp_unslash((string) $request['ll_offline_app_icon_attachment_id'])
        : 0;

    $storage_dir = ll_tools_offline_app_export_storage_dir();
    if (is_wp_error($storage_dir)) {
        return $storage_dir;
    }
    $token = wp_generate_password(24, false, false);
    $job_dir = trailingslashit((string) $storage_dir) . $token;
    $staging_dir = trailingslashit($job_dir) . 'staging';
    $www_dir = trailingslashit($staging_dir) . 'www';
    foreach ([$job_dir, $staging_dir, $www_dir, trailingslashit($job_dir) . 'categories', trailingslashit($job_dir) . 'row-markers', trailingslashit($job_dir) . 'word-lookup', trailingslashit($job_dir) . 'asset-markers'] as $dir) {
        if (!wp_mkdir_p($dir)) {
            if (is_dir($job_dir)) {
                ll_tools_rrmdir($job_dir);
            }
            return new WP_Error('ll_tools_offline_app_job_dir_failed', __('Could not create the offline app export job directory.', 'll-tools-text-domain'));
        }
    }

    $created_at = time();
    $filename = 'll-tools-offline-app-' . sanitize_title((string) $wordset->slug) . '-' . gmdate('Ymd-His') . '.zip';
    $job = [
        'format_version' => 1,
        'token' => $token,
        'created_by' => get_current_user_id(),
        'created_at' => $created_at,
        'updated_at' => $created_at,
        'expires_at' => $created_at + ll_tools_offline_app_export_job_ttl(),
        'status' => 'queued',
        'phase' => 'categories',
        'error' => '',
        'options' => [
            'wordset_id' => $wordset_id,
            'category_scope' => $category_scope,
            'category_ids' => $requested_category_ids,
            'app_name' => $app_name,
            'version_name' => $version_name,
            'version_code' => $version_code,
            'app_id_suffix' => $app_id_suffix,
            'app_icon_attachment_id' => $app_icon_attachment_id,
        ],
        'job_dir' => wp_normalize_path($job_dir),
        'staging_dir' => wp_normalize_path($staging_dir),
        'www_dir' => wp_normalize_path($www_dir),
        'manifest_path' => wp_normalize_path(trailingslashit($job_dir) . 'job.json'),
        'asset_manifest_path' => wp_normalize_path(trailingslashit($job_dir) . 'assets.ndjson'),
        'zip_manifest_path' => wp_normalize_path(trailingslashit($job_dir) . 'zip.ndjson'),
        'warnings_path' => wp_normalize_path(trailingslashit($job_dir) . 'warnings.ndjson'),
        'data_suffix_path' => wp_normalize_path(trailingslashit($job_dir) . 'offline-data-suffix.tmp'),
        'zip_path' => wp_normalize_path(trailingslashit($job_dir) . 'bundle.zip'),
        'filename' => $filename,
        'categories' => [],
        'category_position' => 0,
        'category_cursor' => 0,
        'category_has_more' => true,
        'word_category_index' => 0,
        'word_cursor' => 0,
        'media_offset' => 0,
        'data_stage' => 'init',
        'data_category_index' => 0,
        'data_offset' => 0,
        'data_category_started' => false,
        'data_category_has_rows' => false,
        'zip_offset' => 0,
        'zip_started' => false,
        'processed_categories' => 0,
        'processed_words' => 0,
        'usable_words' => 0,
        'total_assets' => 0,
        'processed_assets' => 0,
        'processed_data_rows' => 0,
        'total_zip_files' => 0,
        'processed_zip_files' => 0,
        'last_batch' => [],
    ];

    $saved = ll_tools_offline_app_export_save_job($job);
    if (is_wp_error($saved)) {
        ll_tools_rrmdir($job_dir);
        return $saved;
    }
    $pointer = [
        'manifest_path' => $job['manifest_path'],
        'created_by' => $job['created_by'],
        'expires_at' => $job['expires_at'],
    ];
    if (!add_option(ll_tools_offline_app_export_job_option_key($token), $pointer, '', false)) {
        ll_tools_rrmdir($job_dir);
        return new WP_Error('ll_tools_offline_app_job_pointer_failed', __('Could not register the offline app export job.', 'll-tools-text-domain'));
    }

    return ll_tools_offline_app_export_build_response($job);
}

function ll_tools_offline_app_export_delete_job(string $token): void {
    $job = ll_tools_offline_app_export_load_job($token, false);
    delete_option(ll_tools_offline_app_export_job_option_key($token));
    delete_option(ll_tools_offline_app_export_job_lock_key($token));
    if (is_array($job)
        && !empty($job['job_dir'])
        && ll_tools_offline_app_export_job_dir_is_safe((string) $job['job_dir'])
        && is_dir((string) $job['job_dir'])) {
        ll_tools_rrmdir((string) $job['job_dir']);
    }
}

function ll_tools_offline_app_export_phase_label(string $phase): string {
    $labels = [
        'categories' => __('Discovering categories', 'll-tools-text-domain'),
        'words' => __('Preparing words and media manifest', 'll-tools-text-domain'),
        'media' => __('Copying bundle assets', 'll-tools-text-domain'),
        'data' => __('Writing offline app data', 'll-tools-text-domain'),
        'zip' => __('Creating export zip', 'll-tools-text-domain'),
        'completed' => __('Offline app bundle ready', 'll-tools-text-domain'),
        'failed' => __('Offline app export failed', 'll-tools-text-domain'),
    ];
    return (string) ($labels[$phase] ?? $labels['failed']);
}

function ll_tools_offline_app_export_progress(array $job): int {
    $phase = (string) ($job['phase'] ?? 'categories');
    if ((string) ($job['status'] ?? '') === 'completed') {
        return 100;
    }
    if ($phase === 'categories') {
        $total = count((array) (($job['options'] ?? [])['category_ids'] ?? []));
        return $total > 0 ? min(10, (int) floor(10 * (int) ($job['processed_categories'] ?? 0) / $total)) : 3;
    }
    if ($phase === 'words') {
        $total = max(1, count((array) ($job['categories'] ?? [])));
        return 10 + min(45, (int) floor(45 * (int) ($job['word_category_index'] ?? 0) / $total));
    }
    if ($phase === 'media') {
        $total = max(1, (int) ($job['total_assets'] ?? 0));
        return 55 + min(20, (int) floor(20 * (int) ($job['processed_assets'] ?? 0) / $total));
    }
    if ($phase === 'data') {
        $total = max(1, (int) ($job['usable_words'] ?? 0));
        return 75 + min(15, (int) floor(15 * (int) ($job['processed_data_rows'] ?? 0) / $total));
    }
    if ($phase === 'zip') {
        $total = max(1, (int) ($job['total_zip_files'] ?? 0));
        return 90 + min(9, (int) floor(9 * (int) ($job['processed_zip_files'] ?? 0) / $total));
    }
    return 0;
}

function ll_tools_offline_app_export_build_response(array $job): array {
    $status = (string) ($job['status'] ?? 'queued');
    $phase = $status === 'completed' ? 'completed' : ($status === 'failed' ? 'failed' : (string) ($job['phase'] ?? 'categories'));
    $response = [
        'token' => (string) ($job['token'] ?? ''),
        'status' => $status,
        'phase' => $phase,
        'phaseLabel' => ll_tools_offline_app_export_phase_label($phase),
        'statusText' => sprintf(
            /* translators: 1: category count, 2: word count, 3: copied asset count */
            __('%1$d categories checked, %2$d words checked, %3$d assets copied.', 'll-tools-text-domain'),
            (int) ($job['processed_categories'] ?? 0),
            (int) ($job['processed_words'] ?? 0),
            (int) ($job['processed_assets'] ?? 0)
        ),
        'progress' => ll_tools_offline_app_export_progress($job),
        'error' => (string) ($job['error'] ?? ''),
        'batch' => is_array($job['last_batch'] ?? null) ? $job['last_batch'] : [],
    ];
    if ($status === 'completed' && !empty($job['zip_path']) && is_file((string) $job['zip_path'])) {
        $token = (string) $job['token'];
        $response['downloadUrl'] = wp_nonce_url(
            admin_url('admin-post.php?action=ll_tools_download_offline_app_export&token=' . rawurlencode($token)),
            'll_tools_download_offline_app_export_' . $token
        );
        $response['statusText'] = __('The offline app bundle is ready to download.', 'll-tools-text-domain');
    } elseif ($status === 'failed') {
        $response['statusText'] = __('The export stopped before a bundle was created.', 'll-tools-text-domain');
    }
    return $response;
}

function ll_tools_offline_app_export_fail_job(array $job, $error): array {
    $message = is_wp_error($error) ? $error->get_error_message() : (string) $error;
    $job['status'] = 'failed';
    $job['error'] = $message !== '' ? $message : __('The offline app export failed.', 'll-tools-text-domain');
    $job['last_batch'] = [];
    ll_tools_offline_app_export_save_job($job);
    return ll_tools_offline_app_export_build_response($job);
}

function ll_tools_offline_app_export_run_step(string $token) {
    $job = ll_tools_offline_app_export_load_job($token);
    if (is_wp_error($job)) {
        return $job;
    }
    if (in_array((string) ($job['status'] ?? ''), ['completed', 'failed'], true)) {
        return ll_tools_offline_app_export_build_response($job);
    }

    $lock_key = ll_tools_offline_app_export_job_lock_key($token);
    $lock = get_option($lock_key, null);
    if (is_array($lock) && (int) ($lock['created_at'] ?? 0) < time() - 300) {
        delete_option($lock_key);
    }
    if (!add_option($lock_key, ['created_at' => time()], '', false)) {
        return new WP_Error('ll_tools_offline_app_job_busy', __('This offline app export job is already processing another step.', 'll-tools-text-domain'));
    }

    try {
        $job['status'] = 'processing';
        $phase = (string) ($job['phase'] ?? 'categories');
        if ($phase === 'categories') {
            $result = ll_tools_offline_app_export_run_category_step($job);
        } elseif ($phase === 'words') {
            $result = ll_tools_offline_app_export_run_word_step($job);
        } elseif ($phase === 'media') {
            $result = ll_tools_offline_app_export_run_media_step($job);
        } elseif ($phase === 'data') {
            $result = ll_tools_offline_app_export_run_data_step($job);
        } elseif ($phase === 'zip') {
            $result = ll_tools_offline_app_export_run_zip_step($job);
        } else {
            $result = new WP_Error('ll_tools_offline_app_job_phase_invalid', __('Offline app export job phase is invalid.', 'll-tools-text-domain'));
        }

        if (is_wp_error($result)) {
            return ll_tools_offline_app_export_fail_job($job, $result);
        }
        $saved = ll_tools_offline_app_export_save_job($result);
        if (is_wp_error($saved)) {
            return ll_tools_offline_app_export_fail_job($result, $saved);
        }
        return ll_tools_offline_app_export_build_response($result);
    } finally {
        delete_option($lock_key);
    }
}

function ll_tools_ajax_offline_app_export_start(): void {
    if (!ll_tools_current_user_can_offline_app_export()) {
        wp_send_json_error(['message' => __('You do not have permission to export offline app bundles.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_tools_offline_app_export_job', 'nonce');
    $job = ll_tools_offline_app_export_prepare_job($_POST);
    if (is_wp_error($job)) {
        wp_send_json_error(['message' => $job->get_error_message()], 400);
    }
    wp_send_json_success($job);
}

function ll_tools_ajax_offline_app_export_step(): void {
    if (!ll_tools_current_user_can_offline_app_export()) {
        wp_send_json_error(['message' => __('You do not have permission to export offline app bundles.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll_tools_offline_app_export_job', 'nonce');
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
    $job = ll_tools_offline_app_export_run_step($token);
    if (is_wp_error($job)) {
        $status = $job->get_error_code() === 'll_tools_offline_app_job_busy' ? 409 : 400;
        wp_send_json_error(['message' => $job->get_error_message()], $status);
    }
    wp_send_json_success($job);
}

function ll_tools_handle_offline_app_export_download(): void {
    if (!ll_tools_current_user_can_offline_app_export()) {
        wp_die(__('You do not have permission to export offline app bundles.', 'll-tools-text-domain'));
    }
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';
    check_admin_referer('ll_tools_download_offline_app_export_' . $token);
    $job = ll_tools_offline_app_export_load_job($token);
    if (is_wp_error($job)) {
        wp_die($job->get_error_message());
    }
    $zip_path = (string) ($job['zip_path'] ?? '');
    if ((string) ($job['status'] ?? '') !== 'completed' || $zip_path === '' || !is_file($zip_path)) {
        wp_die(__('The offline app bundle is not ready to download.', 'll-tools-text-domain'));
    }
    ll_tools_stream_download_file($zip_path, (string) ($job['filename'] ?? 'll-tools-offline-app.zip'), 'application/zip');
}

function ll_tools_offline_app_export_category_has_word(int $wordset_id, int $category_id): bool {
    global $wpdb;
    $sql = $wpdb->prepare(
        "SELECT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type = %s
          AND p.post_status = %s
          AND tt_ws.taxonomy = %s
          AND tt_ws.term_id = %d
          AND tt_cat.taxonomy = %s
          AND tt_cat.term_id = %d
        LIMIT 1",
        'words',
        'publish',
        'wordset',
        $wordset_id,
        'word-category',
        $category_id
    );
    return (int) $wpdb->get_var($sql) > 0;
}

function ll_tools_offline_app_export_get_category_id_page(array $job, int $limit): array {
    $options = (array) ($job['options'] ?? []);
    if ((string) ($options['category_scope'] ?? 'all') === 'custom') {
        $ids = ll_tools_offline_app_normalize_id_list((array) ($options['category_ids'] ?? []));
        $position = max(0, (int) ($job['category_position'] ?? 0));
        return [
            'ids' => array_slice($ids, $position, $limit),
            'has_more' => ($position + $limit) < count($ids),
            'next_position' => min(count($ids), $position + $limit),
            'next_cursor' => (int) ($job['category_cursor'] ?? 0),
        ];
    }

    global $wpdb;
    $wordset_id = (int) ($options['wordset_id'] ?? 0);
    $cursor = max(0, (int) ($job['category_cursor'] ?? 0));
    $query_limit = $limit + 1;
    $sql = $wpdb->prepare(
        "SELECT DISTINCT tt_cat.term_id
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type = %s
          AND p.post_status = %s
          AND tt_ws.taxonomy = %s
          AND tt_ws.term_id = %d
          AND tt_cat.taxonomy = %s
          AND tt_cat.term_id > %d
        ORDER BY tt_cat.term_id ASC
        LIMIT %d",
        'words',
        'publish',
        'wordset',
        $wordset_id,
        'word-category',
        $cursor,
        $query_limit
    );
    $rows = ll_tools_offline_app_normalize_id_list(array_map('intval', (array) $wpdb->get_col($sql)));
    $has_more = count($rows) > $limit;
    $ids = array_slice($rows, 0, $limit);
    return [
        'ids' => $ids,
        'has_more' => $has_more,
        'next_position' => (int) ($job['category_position'] ?? 0),
        'next_cursor' => !empty($ids) ? (int) end($ids) : $cursor,
    ];
}

function ll_tools_offline_app_export_build_category_definition(int $wordset_id, WP_Term $term): array {
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $config = function_exists('ll_tools_resolve_effective_category_quiz_config')
        ? ll_tools_resolve_effective_category_quiz_config($term, $min_word_count, [$wordset_id])
        : ll_tools_get_category_quiz_config($term);
    $option_type = (string) ($config['option_type'] ?? 'image');
    $prompt_type = (string) ($config['prompt_type'] ?? 'audio');
    $requires_image = function_exists('ll_tools_quiz_requires_image')
        ? ll_tools_quiz_requires_image(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
        : ($prompt_type === 'image' || $option_type === 'image');
    $use_translations = ll_flashcards_should_use_translations([$wordset_id]);
    $translation = $use_translations
        ? (get_term_meta($term->term_id, 'term_translation', true) ?: $term->name)
        : $term->name;
    $aspect_bucket = function_exists('ll_tools_get_category_aspect_bucket_key')
        ? (string) ll_tools_get_category_aspect_bucket_key((int) $term->term_id)
        : 'no-image';

    return [
        'id' => (int) $term->term_id,
        'slug' => (string) $term->slug,
        'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
        'translation' => html_entity_decode((string) $translation, ENT_QUOTES, 'UTF-8'),
        'mode' => $option_type,
        'option_type' => $option_type,
        'prompt_type' => $prompt_type,
        'learning_prompt_type' => (string) ($config['learning_prompt_type'] ?? ''),
        'learning_option_type' => (string) ($config['learning_option_type'] ?? ''),
        'requires_images' => $requires_image,
        'learning_supported' => !array_key_exists('learning_supported', $config) || !empty($config['learning_supported']),
        'self_check_supported' => !array_key_exists('self_check_supported', $config) || !empty($config['self_check_supported']),
        'sign_language_mode' => !empty($config['sign_language_mode']),
        'use_titles' => !empty($config['use_titles']),
        'word_count' => 0,
        'gender_word_count' => 0,
        'gender_supported' => false,
        'aspect_bucket' => $aspect_bucket !== '' ? $aspect_bucket : 'no-image',
        'usable_count' => 0,
        'preview_words' => [],
        'omitted' => false,
    ];
}

function ll_tools_offline_app_export_run_category_step(array $job) {
    $batch_size = ll_tools_offline_app_export_category_batch_size();
    $page = ll_tools_offline_app_export_get_category_id_page($job, $batch_size);
    $wordset_id = (int) (($job['options'] ?? [])['wordset_id'] ?? 0);
    $processed = 0;
    foreach ((array) ($page['ids'] ?? []) as $category_id) {
        $processed++;
        $category_id = (int) $category_id;
        $term = $category_id > 0 ? get_term($category_id, 'word-category') : null;
        if (!($term instanceof WP_Term) || is_wp_error($term) || (string) $term->slug === 'uncategorized') {
            continue;
        }
        if (function_exists('ll_tools_user_can_view_category') && !ll_tools_user_can_view_category($term)) {
            continue;
        }
        if (!ll_tools_offline_app_export_category_has_word($wordset_id, $category_id)) {
            continue;
        }
        $job['categories'][] = ll_tools_offline_app_export_build_category_definition($wordset_id, $term);
    }
    $job['processed_categories'] = (int) ($job['processed_categories'] ?? 0) + $processed;
    $job['category_position'] = (int) ($page['next_position'] ?? $job['category_position']);
    $job['category_cursor'] = (int) ($page['next_cursor'] ?? $job['category_cursor']);
    $job['category_has_more'] = !empty($page['has_more']);
    $job['last_batch'] = ['phase' => 'categories', 'categories' => $processed, 'words' => 0, 'media' => 0, 'data' => 0, 'zip' => 0];

    if (!empty($page['has_more'])) {
        return $job;
    }
    if (empty($job['categories'])) {
        return new WP_Error('ll_tools_offline_app_no_categories', __('No quizzable categories were found for this word set and selection.', 'll-tools-text-domain'));
    }

    $categories_by_id = [];
    $category_ids = [];
    $category_name_map = [];
    foreach ((array) $job['categories'] as $category) {
        $category_id = (int) ($category['id'] ?? 0);
        if ($category_id <= 0) {
            continue;
        }
        $categories_by_id[$category_id] = $category;
        $category_ids[] = $category_id;
        $category_name_map[$category_id] = (string) ($category['name'] ?? '');
    }
    if (function_exists('ll_tools_wordset_sort_category_ids')) {
        $category_ids = ll_tools_wordset_sort_category_ids($category_ids, $wordset_id, ['category_name_map' => $category_name_map]);
    }
    $job['categories'] = array_values(array_filter(array_map(static function (int $category_id) use ($categories_by_id): array {
        return isset($categories_by_id[$category_id]) && is_array($categories_by_id[$category_id])
            ? $categories_by_id[$category_id]
            : [];
    }, $category_ids)));
    $job['phase'] = 'words';
    return $job;
}

function ll_tools_offline_app_export_get_word_id_page(int $wordset_id, int $category_id, int $after_id, int $limit): array {
    global $wpdb;
    $query_limit = $limit + 1;
    $sql = $wpdb->prepare(
        "SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_ws ON tr_ws.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_ws ON tt_ws.term_taxonomy_id = tr_ws.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = p.ID
        INNER JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
        WHERE p.post_type = %s
          AND p.post_status = %s
          AND p.ID > %d
          AND tt_ws.taxonomy = %s
          AND tt_ws.term_id = %d
          AND tt_cat.taxonomy = %s
          AND tt_cat.term_id = %d
        ORDER BY p.ID ASC
        LIMIT %d",
        'words',
        'publish',
        $after_id,
        'wordset',
        $wordset_id,
        'word-category',
        $category_id,
        $query_limit
    );
    $rows = ll_tools_offline_app_normalize_id_list(array_map('intval', (array) $wpdb->get_col($sql)));
    return [
        'ids' => array_slice($rows, 0, $limit),
        'has_more' => count($rows) > $limit,
    ];
}

function ll_tools_offline_app_export_append_line(string $path, array $row) {
    $encoded = wp_json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || @file_put_contents($path, $encoded . "\n", FILE_APPEND | LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_job_append_failed', __('Could not append offline app export job data.', 'll-tools-text-domain'));
    }
    return true;
}

function ll_tools_offline_app_export_add_warning(array $job, string $warning) {
    $warning = trim($warning);
    if ($warning === '') {
        return true;
    }
    return ll_tools_offline_app_export_append_line((string) $job['warnings_path'], ['message' => $warning]);
}

function ll_tools_offline_app_export_register_asset(array &$job, array $asset_entry) {
    $source_path = wp_normalize_path((string) ($asset_entry['source_path'] ?? ''));
    $relative_path = ll_tools_offline_app_normalize_relative_bundle_path((string) ($asset_entry['relative_path'] ?? ''));
    if ($source_path === '' || $relative_path === '') {
        return true;
    }
    $marker = trailingslashit((string) $job['job_dir']) . 'asset-markers/' . md5($relative_path) . '.done';
    if (is_file($marker)) {
        return true;
    }
    $written = ll_tools_offline_app_export_append_line((string) $job['asset_manifest_path'], [
        'source_path' => $source_path,
        'relative_path' => $relative_path,
    ]);
    if (is_wp_error($written)) {
        return $written;
    }
    if (@file_put_contents($marker, $relative_path, LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_job_asset_marker_failed', __('Could not save offline app media progress.', 'll-tools-text-domain'));
    }
    $job['total_assets'] = (int) ($job['total_assets'] ?? 0) + 1;
    return true;
}

function ll_tools_offline_app_export_register_word_row(array &$job, int $category_id, array $word) {
    $row_id = (int) ($word['id'] ?? 0);
    $row_key = $row_id > 0 ? (string) $row_id : md5((string) wp_json_encode($word));
    $marker_dir = trailingslashit((string) $job['job_dir']) . 'row-markers/' . $category_id;
    if (!wp_mkdir_p($marker_dir)) {
        return new WP_Error('ll_tools_offline_app_job_row_dir_failed', __('Could not create offline app word progress storage.', 'll-tools-text-domain'));
    }
    $marker = trailingslashit($marker_dir) . $row_key . '.done';
    if (is_file($marker)) {
        return false;
    }
    $category_path = trailingslashit((string) $job['job_dir']) . 'categories/' . $category_id . '.ndjson';
    $written = ll_tools_offline_app_export_append_line($category_path, $word);
    if (is_wp_error($written)) {
        return $written;
    }
    if (@file_put_contents($marker, $row_key, LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_job_row_marker_failed', __('Could not save offline app word progress.', 'll-tools-text-domain'));
    }
    if ($row_id > 0) {
        $lookup_path = trailingslashit((string) $job['job_dir']) . 'word-lookup/' . $row_id . '.json';
        $lookup_saved = ll_tools_offline_app_export_write_json($lookup_path, $word);
        if (is_wp_error($lookup_saved)) {
            return $lookup_saved;
        }
    }
    return true;
}

function ll_tools_offline_app_export_word_has_gender(array $word, array $gender_runtime): bool {
    if (empty($gender_runtime['enabled'])) {
        return false;
    }
    $part_of_speech = array_map('strtolower', array_map('strval', (array) ($word['part_of_speech'] ?? [])));
    if (!in_array('noun', $part_of_speech, true)) {
        return false;
    }
    $options = array_values((array) ($gender_runtime['options'] ?? []));
    $gender = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
        ? ll_tools_wordset_normalize_gender_value_for_options((string) ($word['grammatical_gender'] ?? ''), $options)
        : trim((string) ($word['grammatical_gender'] ?? ''));
    $normalized_options = array_map('strtolower', array_map('strval', $options));
    return $gender !== '' && (empty($normalized_options) || in_array(strtolower((string) $gender), $normalized_options, true));
}

function ll_tools_offline_app_export_register_static_assets(array &$job) {
    $plugin_files = [
        'css/language-learner-tools.css',
        'css/wordset-pages.css',
        'css/wordset-games.css',
        'css/ipa-fonts.css',
        'css/flashcard/base.css',
        'css/self-check-shared.css',
        'css/flashcard/mode-practice.css',
        'css/flashcard/mode-learning.css',
        'css/flashcard/mode-listening.css',
        'css/flashcard/mode-gender.css',
        'js/flashcard-widget/option-conflicts.js',
        'js/flashcard-widget/audio.js',
        'js/flashcard-widget/loader.js',
        'js/flashcard-widget/options.js',
        'js/flashcard-widget/util.js',
        'js/locale-sort.js',
        'js/self-check-shared.js',
        'js/wordset-games.js',
        'js/flashcard-widget/mode-config.js',
        'js/flashcard-widget/state.js',
        'js/flashcard-widget/progress-tracker.js',
        'js/flashcard-widget/dom.js',
        'js/flashcard-widget/audio-visualizer.js',
        'js/flashcard-widget/effects.js',
        'js/flashcard-widget/cards.js',
        'js/flashcard-widget/selection.js',
        'js/flashcard-widget/results.js',
        'js/flashcard-widget/modes/practice.js',
        'js/flashcard-widget/modes/learning.js',
        'js/flashcard-widget/modes/self-check.js',
        'js/flashcard-widget/modes/listening.js',
        'js/flashcard-widget/modes/gender.js',
        'js/flashcard-widget/main.js',
        'js/flashcard-widget/category-selection.js',
        'media/right-answer.mp3',
        'media/wrong-answer.mp3',
        'media/space-shooter-correct-hit.mp3',
        'media/space-shooter-correct-hit.ogg',
        'media/space-shooter-wrong-hit.mp3',
        'media/space-shooter-wrong-hit.ogg',
        'media/bubble-pop.mp3',
    ];
    foreach ($plugin_files as $relative_path) {
        $registered = ll_tools_offline_app_export_register_asset($job, [
            'source_path' => LL_TOOLS_BASE_PATH . $relative_path,
            'relative_path' => 'plugin/' . $relative_path,
        ]);
        if (is_wp_error($registered)) {
            return $registered;
        }
    }

    $font_root = wp_normalize_path(LL_TOOLS_BASE_PATH . 'fonts/ll-ipa');
    if (!is_dir($font_root)) {
        return new WP_Error('ll_tools_offline_app_missing_dir', __('The offline app IPA font directory is missing.', 'll-tools-text-domain'));
    }
    $font_iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($font_root, FilesystemIterator::SKIP_DOTS));
    foreach ($font_iterator as $font_file) {
        if (!$font_file->isFile()) {
            continue;
        }
        $source_path = wp_normalize_path($font_file->getPathname());
        $relative_path = ltrim(substr($source_path, strlen($font_root)), '/');
        $registered = ll_tools_offline_app_export_register_asset($job, [
            'source_path' => $source_path,
            'relative_path' => 'plugin/fonts/ll-ipa/' . $relative_path,
        ]);
        if (is_wp_error($registered)) {
            return $registered;
        }
    }

    $jquery_source = ll_tools_offline_app_find_jquery_source();
    $bootstrap_source = wp_normalize_path(LL_TOOLS_BASE_PATH . 'offline-app/offline-app.js');
    if ($jquery_source === '' || !is_file($jquery_source)) {
        return new WP_Error('ll_tools_offline_app_missing_jquery', __('Could not find the local WordPress jQuery runtime for the offline app shell.', 'll-tools-text-domain'));
    }
    if (!is_file($bootstrap_source)) {
        return new WP_Error('ll_tools_offline_app_bootstrap_missing', __('The offline app bootstrap script is missing from the plugin.', 'll-tools-text-domain'));
    }
    foreach ([
        ['source_path' => $jquery_source, 'relative_path' => 'vendor/jquery/jquery.min.js'],
        ['source_path' => $bootstrap_source, 'relative_path' => 'app/offline-app.js'],
    ] as $entry) {
        $registered = ll_tools_offline_app_export_register_asset($job, $entry);
        if (is_wp_error($registered)) {
            return $registered;
        }
    }

    $icon = ll_tools_offline_app_resolve_icon_payload((int) (($job['options'] ?? [])['app_icon_attachment_id'] ?? 0));
    if (is_wp_error($icon)) {
        return $icon;
    }
    $job['app_icon_manifest'] = [];
    if (!empty($icon)) {
        $extension = ll_tools_offline_app_get_image_extension((string) ($icon['filename'] ?? ''), (string) ($icon['mime_type'] ?? ''));
        $relative_path = 'app-assets/app-icon.' . $extension;
        $registered = ll_tools_offline_app_export_register_asset($job, [
            'source_path' => (string) ($icon['source_path'] ?? ''),
            'relative_path' => $relative_path,
        ]);
        if (is_wp_error($registered)) {
            return $registered;
        }
        $job['app_icon_manifest'] = [
            'attachmentId' => (int) ($icon['attachment_id'] ?? 0),
            'source' => (string) ($icon['source'] ?? ''),
            'mimeType' => (string) ($icon['mime_type'] ?? ''),
            'bundlePath' => 'www/' . $relative_path,
            'webPath' => './' . $relative_path,
            'label' => (string) ($icon['label'] ?? ''),
        ];
    }
    return true;
}

function ll_tools_offline_app_export_run_word_step(array $job) {
    $categories = array_values((array) ($job['categories'] ?? []));
    $category_index = max(0, (int) ($job['word_category_index'] ?? 0));
    if ($category_index >= count($categories)) {
        return new WP_Error('ll_tools_offline_app_job_word_cursor_invalid', __('Offline app export word progress is invalid.', 'll-tools-text-domain'));
    }
    $category = is_array($categories[$category_index] ?? null) ? $categories[$category_index] : [];
    $category_id = (int) ($category['id'] ?? 0);
    $wordset_id = (int) (($job['options'] ?? [])['wordset_id'] ?? 0);
    $batch_size = ll_tools_offline_app_export_word_batch_size();
    $page = ll_tools_offline_app_export_get_word_id_page($wordset_id, $category_id, (int) ($job['word_cursor'] ?? 0), $batch_size);
    $candidate_ids = array_values((array) ($page['ids'] ?? []));
    $term = $category_id > 0 ? get_term($category_id, 'word-category') : null;
    if (!($term instanceof WP_Term) || is_wp_error($term)) {
        return new WP_Error('ll_tools_offline_app_job_category_missing', __('A selected offline app category no longer exists.', 'll-tools-text-domain'));
    }

    $rows = [];
    if (!empty($candidate_ids)) {
        $config = $category;
        $config['__candidate_word_ids'] = array_map('intval', $candidate_ids);
        $config['__skip_quiz_config_merge'] = true;
        $rows = ll_get_words_by_category($term, (string) ($category['option_type'] ?? 'image'), [$wordset_id], $config);
        $rows = ll_tools_offline_app_filter_words_to_wordset((array) $rows, $wordset_id);
    }

    $asset_registry = ['images' => [], 'audio' => []];
    $asset_entries = [];
    $warnings = [];
    $rewritten_rows = ll_tools_offline_app_rewrite_category_words(
        (array) $rows,
        (string) ($category['name'] ?? ''),
        $category,
        $asset_registry,
        $asset_entries,
        $warnings
    );
    foreach ($asset_entries as $asset_entry) {
        $registered = ll_tools_offline_app_export_register_asset($job, (array) $asset_entry);
        if (is_wp_error($registered)) {
            return $registered;
        }
    }
    foreach ($warnings as $warning) {
        $saved_warning = ll_tools_offline_app_export_add_warning($job, (string) $warning);
        if (is_wp_error($saved_warning)) {
            return $saved_warning;
        }
    }

    $gender_runtime = ll_tools_offline_app_get_gender_runtime_config($wordset_id);
    foreach ($rewritten_rows as $word) {
        if (!is_array($word)) {
            continue;
        }
        $saved_row = ll_tools_offline_app_export_register_word_row($job, $category_id, $word);
        if (is_wp_error($saved_row)) {
            return $saved_row;
        }
        if ($saved_row !== true) {
            continue;
        }
        $category['usable_count'] = (int) ($category['usable_count'] ?? 0) + 1;
        $job['usable_words'] = (int) ($job['usable_words'] ?? 0) + 1;
        if (count((array) ($category['preview_words'] ?? [])) < 4) {
            $category['preview_words'][] = $word;
        }
        if (ll_tools_offline_app_export_word_has_gender($word, $gender_runtime)) {
            $category['gender_word_count'] = (int) ($category['gender_word_count'] ?? 0) + 1;
        }
    }

    $processed = count($candidate_ids);
    $job['processed_words'] = (int) ($job['processed_words'] ?? 0) + $processed;
    if (!empty($candidate_ids)) {
        $job['word_cursor'] = (int) end($candidate_ids);
    }
    $job['categories'][$category_index] = $category;
    $job['last_batch'] = ['phase' => 'words', 'categories' => 0, 'words' => $processed, 'media' => 0, 'data' => 0, 'zip' => 0];
    if (!empty($page['has_more'])) {
        return $job;
    }

    $minimum = max(1, (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ));
    $category['word_count'] = (int) ($category['usable_count'] ?? 0);
    $category['gender_supported'] = !empty($gender_runtime['enabled']) && (int) ($category['gender_word_count'] ?? 0) >= $minimum;
    if ($category['word_count'] < $minimum) {
        $category['omitted'] = true;
        $warning = sprintf(
            /* translators: 1: category name, 2: minimum word count */
            __('Category "%1$s" was omitted from the offline app because fewer than %2$d usable words remained after offline asset checks.', 'll-tools-text-domain'),
            (string) ($category['name'] ?? ''),
            $minimum
        );
        $saved_warning = ll_tools_offline_app_export_add_warning($job, $warning);
        if (is_wp_error($saved_warning)) {
            return $saved_warning;
        }
        $job['usable_words'] = max(0, (int) ($job['usable_words'] ?? 0) - $category['word_count']);
    }
    $job['categories'][$category_index] = $category;
    $job['word_category_index'] = $category_index + 1;
    $job['word_cursor'] = 0;

    if ((int) $job['word_category_index'] < count($categories)) {
        return $job;
    }
    $kept_categories = array_values(array_filter((array) $job['categories'], static function ($candidate): bool {
        return is_array($candidate) && empty($candidate['omitted']);
    }));
    if (empty($kept_categories)) {
        return new WP_Error('ll_tools_offline_app_empty_after_filter', __('Every selected category was removed because required offline assets could not be bundled.', 'll-tools-text-domain'));
    }
    $job['categories'] = $kept_categories;
    $static_assets = ll_tools_offline_app_export_register_static_assets($job);
    if (is_wp_error($static_assets)) {
        return $static_assets;
    }
    $job['phase'] = 'media';
    return $job;
}

function ll_tools_offline_app_export_append_zip_entry(array &$job, string $source_path, string $zip_path) {
    $source_path = wp_normalize_path($source_path);
    $zip_path = ll_tools_offline_app_normalize_relative_bundle_path($zip_path);
    if ($source_path === '' || $zip_path === '') {
        return true;
    }
    $marker_dir = trailingslashit((string) $job['job_dir']) . 'zip-markers';
    if (!wp_mkdir_p($marker_dir)) {
        return new WP_Error('ll_tools_offline_app_job_zip_marker_dir_failed', __('Could not create offline app zip progress storage.', 'll-tools-text-domain'));
    }
    $marker = trailingslashit($marker_dir) . md5($zip_path) . '.done';
    if (is_file($marker)) {
        return true;
    }
    $written = ll_tools_offline_app_export_append_line((string) $job['zip_manifest_path'], [
        'source_path' => $source_path,
        'zip_path' => $zip_path,
    ]);
    if (is_wp_error($written)) {
        return $written;
    }
    if (@file_put_contents($marker, $zip_path, LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_job_zip_marker_failed', __('Could not save offline app zip progress.', 'll-tools-text-domain'));
    }
    $job['total_zip_files'] = (int) ($job['total_zip_files'] ?? 0) + 1;
    return true;
}

function ll_tools_offline_app_export_run_media_step(array $job) {
    $manifest_path = (string) ($job['asset_manifest_path'] ?? '');
    if ($manifest_path === '' || !is_file($manifest_path)) {
        return new WP_Error('ll_tools_offline_app_job_asset_manifest_missing', __('Offline app media manifest is missing.', 'll-tools-text-domain'));
    }
    $handle = fopen($manifest_path, 'rb');
    if (!$handle) {
        return new WP_Error('ll_tools_offline_app_job_asset_manifest_open_failed', __('Could not read the offline app media manifest.', 'll-tools-text-domain'));
    }
    $offset = max(0, (int) ($job['media_offset'] ?? 0));
    if ($offset > 0 && fseek($handle, $offset) !== 0) {
        fclose($handle);
        return new WP_Error('ll_tools_offline_app_job_asset_cursor_failed', __('Could not resume offline app media progress.', 'll-tools-text-domain'));
    }

    $processed = 0;
    $batch_size = ll_tools_offline_app_export_media_batch_size();
    while ($processed < $batch_size && ($line = fgets($handle)) !== false) {
        $entry = json_decode(trim($line), true);
        if (!is_array($entry)) {
            fclose($handle);
            return new WP_Error('ll_tools_offline_app_job_asset_manifest_invalid', __('Offline app media manifest contains invalid data.', 'll-tools-text-domain'));
        }
        $source_path = wp_normalize_path((string) ($entry['source_path'] ?? ''));
        $relative_path = ll_tools_offline_app_normalize_relative_bundle_path((string) ($entry['relative_path'] ?? ''));
        if ($source_path === '' || $relative_path === '' || !is_file($source_path)) {
            fclose($handle);
            return new WP_Error('ll_tools_offline_app_job_asset_missing', __('A required offline app asset is missing. Restore it and resume with a new export.', 'll-tools-text-domain'));
        }
        $destination = trailingslashit((string) $job['www_dir']) . $relative_path;
        if (!wp_mkdir_p(dirname($destination)) || !copy($source_path, $destination)) {
            fclose($handle);
            return new WP_Error('ll_tools_offline_app_job_asset_copy_failed', __('Could not copy a required asset into the offline app bundle.', 'll-tools-text-domain'));
        }
        $zip_entry = ll_tools_offline_app_export_append_zip_entry($job, $destination, 'www/' . $relative_path);
        if (is_wp_error($zip_entry)) {
            fclose($handle);
            return $zip_entry;
        }
        $processed++;
    }
    $job['media_offset'] = max(0, (int) ftell($handle));
    $at_end = feof($handle);
    fclose($handle);
    $job['processed_assets'] = (int) ($job['processed_assets'] ?? 0) + $processed;
    $job['last_batch'] = ['phase' => 'media', 'categories' => 0, 'words' => 0, 'media' => $processed, 'data' => 0, 'zip' => 0];
    if ($at_end) {
        $job['phase'] = 'data';
        $job['data_stage'] = 'init';
    }
    return $job;
}

function ll_tools_offline_app_export_read_warnings(array $job): array {
    $path = (string) ($job['warnings_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        return [];
    }
    $warnings = [];
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }
    while (($line = fgets($handle)) !== false) {
        $row = json_decode(trim($line), true);
        $message = is_array($row) ? trim((string) ($row['message'] ?? '')) : '';
        if ($message !== '') {
            $warnings[$message] = $message;
        }
    }
    fclose($handle);
    return array_values($warnings);
}

function ll_tools_offline_app_export_public_categories(array $categories): array {
    return array_values(array_map(static function (array $category): array {
        unset($category['usable_count'], $category['preview_words'], $category['omitted']);
        return $category;
    }, $categories));
}

function ll_tools_offline_app_export_build_launcher_categories(array $categories): array {
    $launcher = [];
    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }
        $requires_images = !empty($category['requires_images']);
        $preview_limit = $requires_images ? 2 : 4;
        $preview = ll_tools_offline_app_build_launcher_preview((array) ($category['preview_words'] ?? []), $preview_limit, $requires_images);
        $has_images = false;
        $preview_aspect_ratio = '';
        foreach ($preview as $preview_item) {
            if (is_array($preview_item) && (string) ($preview_item['type'] ?? '') === 'image') {
                $has_images = true;
                $preview_aspect_ratio = (string) ($preview_item['ratio'] ?? '');
                break;
            }
        }
        $public = ll_tools_offline_app_export_public_categories([$category]);
        $row = $public[0] ?? [];
        $row['word_count'] = (int) ($category['usable_count'] ?? $category['word_count'] ?? 0);
        $row['preview'] = $preview;
        $row['preview_limit'] = $preview_limit;
        $row['has_images'] = $has_images;
        $row['preview_aspect_ratio'] = $preview_aspect_ratio;
        $launcher[] = $row;
    }
    return array_values($launcher);
}

function ll_tools_offline_app_export_rewrite_games_from_lookup(array $games, array $job): array {
    if (empty($games['catalog']) || !is_array($games['catalog'])) {
        return $games;
    }
    foreach ($games['catalog'] as $slug => $entry) {
        if (!is_array($entry) || empty($entry['words']) || !is_array($entry['words'])) {
            continue;
        }
        foreach ($entry['words'] as $index => $word) {
            if (!is_array($word)) {
                continue;
            }
            $word_id = (int) ($word['id'] ?? 0);
            $lookup_path = trailingslashit((string) $job['job_dir']) . 'word-lookup/' . $word_id . '.json';
            $offline_word = ll_tools_offline_app_read_json_file($lookup_path);
            if (empty($offline_word)) {
                continue;
            }
            $games['catalog'][$slug]['words'][$index] = array_merge($word, [
                'image' => (string) ($offline_word['image'] ?? ($word['image'] ?? '')),
                'audio' => (string) ($offline_word['audio'] ?? ($word['audio'] ?? '')),
                'audio_files' => isset($offline_word['audio_files']) && is_array($offline_word['audio_files'])
                    ? $offline_word['audio_files']
                    : (array) ($word['audio_files'] ?? []),
            ]);
        }
    }
    return $games;
}

function ll_tools_offline_app_export_build_payload(array $job) {
    $options = (array) ($job['options'] ?? []);
    $wordset_id = (int) ($options['wordset_id'] ?? 0);
    $wordset = $wordset_id > 0 ? get_term($wordset_id, 'wordset') : null;
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        return new WP_Error('ll_tools_offline_app_job_wordset_missing', __('The selected word set no longer exists.', 'll-tools-text-domain'));
    }
    $categories_with_state = array_values((array) ($job['categories'] ?? []));
    $categories = ll_tools_offline_app_export_public_categories($categories_with_state);
    $exported_category_ids = array_values(array_filter(array_map(static function (array $category): int {
        return (int) ($category['id'] ?? 0);
    }, $categories)));
    $warnings = ll_tools_offline_app_export_read_warnings($job);
    $games = ll_tools_offline_app_build_games_payload($wordset_id, $wordset, $exported_category_ids, [], $warnings);
    $games = ll_tools_offline_app_export_rewrite_games_from_lookup($games, $job);
    $app_name = (string) ($options['app_name'] ?? get_bloginfo('name'));
    $version_name = (string) ($options['version_name'] ?? ll_tools_get_plugin_version_string());
    $version_code = max(1, (int) ($options['version_code'] ?? 1));
    $app_id = 'com.lltools.offline.' . (string) ($options['app_id_suffix'] ?? 'offline.quiz');
    $app_icon_manifest = is_array($job['app_icon_manifest'] ?? null) ? $job['app_icon_manifest'] : [];
    $launcher_categories = ll_tools_offline_app_export_build_launcher_categories($categories_with_state);
    $first_category_name = (string) ($categories[0]['name'] ?? '');
    $mode_ui_config = function_exists('ll_flashcards_get_mode_ui_config') ? ll_flashcards_get_mode_ui_config() : [];
    $gender_runtime = ll_tools_offline_app_get_gender_runtime_config($wordset_id);
    $available_modes = ['learning', 'practice', 'listening', 'self-check'];
    foreach ($categories as $category) {
        if (!empty($gender_runtime['enabled']) && !empty($category['gender_supported'])) {
            $available_modes[] = 'gender';
            break;
        }
    }
    $minimum = max(1, (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ));
    $placeholder_token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($job['token'] ?? ''));
    $first_placeholder = '__LL_OFFLINE_FIRST_CATEGORY_DATA_' . $placeholder_token . '__';
    $categories_placeholder = '__LL_OFFLINE_CATEGORY_DATA_' . $placeholder_token . '__';

    $bundle_manifest = [
        'formatVersion' => 1,
        'bundleType' => 'll_tools_offline_app',
        'generatedAt' => gmdate('c'),
        'site' => home_url('/'),
        'app' => [
            'name' => $app_name,
            'versionName' => $version_name,
            'versionCode' => $version_code,
            'icon' => !empty($app_icon_manifest) ? $app_icon_manifest : null,
        ],
        'android' => ['appId' => $app_id],
        'wordset' => [
            'id' => (int) $wordset->term_id,
            'slug' => (string) $wordset->slug,
            'name' => (string) $wordset->name,
        ],
        'categories' => array_values(array_map(static function (array $category): array {
            return [
                'id' => (int) ($category['id'] ?? 0),
                'slug' => (string) ($category['slug'] ?? ''),
                'name' => (string) ($category['name'] ?? ''),
            ];
        }, $categories)),
        'warnings' => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
    ];

    $payload = [
        'formatVersion' => 1,
        'flashcards' => [
            'runtimeMode' => 'offline',
            'plugin_dir' => './plugin/',
            'mode' => 'random',
            'quiz_mode' => 'practice',
            'ajaxurl' => '',
            'ajaxNonce' => '',
            'isUserLoggedIn' => false,
            'wordset' => (string) $wordset->slug,
            'wordsetFallback' => false,
            'wordsetIds' => [(int) $wordset->term_id],
            'categories' => $categories,
            'categoriesPreselected' => false,
            'firstCategoryData' => $first_placeholder,
            'firstCategoryName' => $first_category_name,
            'imageSize' => get_option('ll_flashcard_image_size', 'small'),
            'maxOptionsOverride' => get_option('ll_max_options_override', 9),
            'modeUi' => $mode_ui_config,
            'userStudyState' => [
                'wordset_id' => (int) $wordset->term_id,
                'category_ids' => [],
                'starred_word_ids' => [],
                'star_mode' => 'normal',
                'fast_transitions' => false,
            ],
            'starredWordIds' => [],
            'starred_word_ids' => [],
            'starMode' => 'normal',
            'star_mode' => 'normal',
            'fastTransitions' => false,
            'fast_transitions' => false,
            'userStudyNonce' => '',
            'offlineSync' => [
                'enabled' => true,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'siteUrl' => home_url('/'),
                'loginAction' => 'll_tools_offline_app_login',
                'logoutAction' => 'll_tools_offline_app_logout',
                'syncAction' => 'll_tools_offline_app_sync',
            ],
            'availableModes' => $available_modes,
            'genderEnabled' => !empty($gender_runtime['enabled']),
            'genderWordsetId' => !empty($gender_runtime['enabled']) ? (int) $wordset->term_id : 0,
            'genderOptions' => array_values((array) ($gender_runtime['options'] ?? [])),
            'genderVisualConfig' => (array) ($gender_runtime['visual_config'] ?? []),
            'genderMinCount' => (int) ($gender_runtime['min_count'] ?? $minimum),
            'preloadTuning' => [
                'categoryAjaxConcurrency' => 1,
                'categoryAjaxSpacingMs' => 0,
                'categoryAjaxMaxRetriesOn429' => 0,
                'categoryAjaxRetryBaseMs' => 250,
                'categoryAjaxRetryMaxMs' => 1000,
                'categoryMediaChunkSize' => 8,
                'categoryMediaChunkDelayMs' => 40,
                'categoryMediaChunkConcurrency' => 2,
            ],
            'resultsCategoryPreviewLimit' => (int) apply_filters('ll_tools_results_category_preview_limit', 3),
            'sortLocale' => get_locale(),
            'offlineCategoryData' => $categories_placeholder,
        ],
        'messages' => ll_flashcards_get_messages(),
        'games' => $games,
        'app' => [
            'title' => $app_name,
            'versionName' => $version_name,
            'versionCode' => $version_code,
            'wordsetName' => (string) $wordset->name,
            'icon' => !empty($app_icon_manifest) ? [
                'url' => (string) ($app_icon_manifest['webPath'] ?? ''),
                'mimeType' => (string) ($app_icon_manifest['mimeType'] ?? ''),
            ] : null,
            'launcher' => [
                'categories' => $launcher_categories,
                'previewLimit' => 2,
            ],
            'views' => [
                'study' => ['enabled' => true],
                'games' => ['enabled' => !empty($games['catalog']) && is_array($games['catalog'])],
            ],
            'sync' => [
                'enabled' => true,
                'messages' => [
                    'localOnlyLabel' => __('Local progress only', 'll-tools-text-domain'),
                    'connectedAsLabel' => __('Connected as %s', 'll-tools-text-domain'),
                    'connectButton' => __('Connect account', 'll-tools-text-domain'),
                    'disconnectButton' => __('Disconnect', 'll-tools-text-domain'),
                    'syncNowButton' => __('Sync now', 'll-tools-text-domain'),
                    'syncPendingLabel' => __('%d pending', 'll-tools-text-domain'),
                    'syncIdleLabel' => __('All caught up', 'll-tools-text-domain'),
                    'syncFailedLabel' => __('Sync failed. Your local progress is still saved.', 'll-tools-text-domain'),
                    'syncFormTitle' => __('Connect to Sync', 'll-tools-text-domain'),
                    'syncIdentifierLabel' => __('Username or email', 'll-tools-text-domain'),
                    'syncPasswordLabel' => __('Password', 'll-tools-text-domain'),
                    'syncSubmitButton' => __('Sign in', 'll-tools-text-domain'),
                    'syncCancelButton' => __('Cancel', 'll-tools-text-domain'),
                    'syncSignedOutLabel' => __('Disconnected. The app will keep storing progress locally.', 'll-tools-text-domain'),
                    'syncInProgressLabel' => __('Syncing...', 'll-tools-text-domain'),
                    'showPasswordLabel' => __('Show password', 'll-tools-text-domain'),
                    'hidePasswordLabel' => __('Hide password', 'll-tools-text-domain'),
                ],
            ],
        ],
    ];

    return [
        'payload' => $payload,
        'manifest' => $bundle_manifest,
        'warnings' => $warnings,
        'wordset' => $wordset,
        'app_id' => $app_id,
        'first_placeholder' => $first_placeholder,
        'categories_placeholder' => $categories_placeholder,
    ];
}

function ll_tools_offline_app_export_write_shell(string $www_dir, array $payload, array $warnings, array $bundle_manifest) {
    $app_config = (array) ($payload['app'] ?? []);
    $flashcards = (array) ($payload['flashcards'] ?? []);
    $games_payload = (array) ($payload['games'] ?? []);
    $games_catalog = is_array($games_payload['catalog'] ?? null) ? (array) $games_payload['catalog'] : [];
    $games_shell_html = '';
    $wordset_id = (int) ($bundle_manifest['wordset']['id'] ?? 0);
    if ($wordset_id > 0 && !empty($games_catalog) && function_exists('ll_tools_render_wordset_games_shell')) {
        $wordset = get_term($wordset_id, 'wordset');
        if ($wordset instanceof WP_Term && !is_wp_error($wordset)) {
            $games_shell_html = ll_tools_render_wordset_games_shell([
                'wordset_term' => $wordset,
                'games_catalog' => $games_catalog,
                'is_study_user' => true,
                'back_url' => '#ll-offline-study-view',
                'as_modal' => false,
                'is_open' => true,
            ]);
        }
    }
    $html = ll_tools_capture_template('offline-app-shell-template.php', [
        'app_title' => (string) ($app_config['title'] ?? get_bloginfo('name')),
        'wordset_name' => (string) ($app_config['wordsetName'] ?? ''),
        'styles' => [
            './plugin/css/language-learner-tools.css',
            './plugin/css/wordset-pages.css',
            './plugin/css/wordset-games.css',
            './plugin/css/flashcard/base.css',
            './plugin/css/self-check-shared.css',
            './plugin/css/flashcard/mode-practice.css',
            './plugin/css/flashcard/mode-learning.css',
            './plugin/css/flashcard/mode-listening.css',
            './plugin/css/flashcard/mode-gender.css',
        ],
        'scripts' => [
            './vendor/jquery/jquery.min.js',
            './data/offline-data.js',
            './plugin/js/flashcard-widget/option-conflicts.js',
            './plugin/js/flashcard-widget/audio.js',
            './plugin/js/flashcard-widget/loader.js',
            './plugin/js/flashcard-widget/options.js',
            './plugin/js/flashcard-widget/util.js',
            './plugin/js/locale-sort.js',
            './plugin/js/self-check-shared.js',
            './plugin/js/flashcard-widget/mode-config.js',
            './plugin/js/flashcard-widget/state.js',
            './plugin/js/flashcard-widget/progress-tracker.js',
            './plugin/js/wordset-games.js',
            './plugin/js/flashcard-widget/dom.js',
            './plugin/js/flashcard-widget/audio-visualizer.js',
            './plugin/js/flashcard-widget/effects.js',
            './plugin/js/flashcard-widget/cards.js',
            './plugin/js/flashcard-widget/selection.js',
            './plugin/js/flashcard-widget/results.js',
            './plugin/js/flashcard-widget/modes/practice.js',
            './plugin/js/flashcard-widget/modes/learning.js',
            './plugin/js/flashcard-widget/modes/self-check.js',
            './plugin/js/flashcard-widget/modes/listening.js',
            './plugin/js/flashcard-widget/modes/gender.js',
            './plugin/js/flashcard-widget/main.js',
            './plugin/js/flashcard-widget/category-selection.js',
            './app/offline-app.js',
        ],
        'startup_mode' => (string) ($flashcards['quiz_mode'] ?? 'practice'),
        'app_icon_url' => is_array($app_config['icon'] ?? null) ? (string) ($app_config['icon']['url'] ?? '') : '',
        'app_icon_mime' => is_array($app_config['icon'] ?? null) ? (string) ($app_config['icon']['mimeType'] ?? '') : '',
        'warnings' => $warnings,
        'bundle_manifest' => $bundle_manifest,
        'mode_ui' => is_array($flashcards['modeUi'] ?? null) ? (array) $flashcards['modeUi'] : [],
        'games_enabled' => !empty($games_catalog),
        'games_shell_html' => $games_shell_html,
        'll_config' => [
            'wordset' => (string) ($flashcards['wordset'] ?? ''),
            'wordsetFallback' => !empty($flashcards['wordsetFallback']),
            'genderEnabled' => !empty($flashcards['genderEnabled']),
            'genderOptions' => array_values((array) ($flashcards['genderOptions'] ?? [])),
        ],
    ]);
    if ($html === '' || @file_put_contents(trailingslashit($www_dir) . 'index.html', $html, LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_template_failed', __('Could not render the offline app shell template.', 'll-tools-text-domain'));
    }
    return true;
}

function ll_tools_offline_app_export_write_readme(array $job, array $payload_data) {
    $options = (array) ($job['options'] ?? []);
    $wordset = $payload_data['wordset'] ?? null;
    if (!($wordset instanceof WP_Term)) {
        return new WP_Error('ll_tools_offline_app_job_wordset_missing', __('The selected word set no longer exists.', 'll-tools-text-domain'));
    }
    $icon_manifest = (array) ($job['app_icon_manifest'] ?? []);
    $lines = [
        __('LL Tools Offline App Bundle', 'll-tools-text-domain'),
        '===========================',
        '',
        sprintf(__('Word set: %s', 'll-tools-text-domain'), (string) $wordset->name),
        sprintf(__('App name: %s', 'll-tools-text-domain'), (string) ($options['app_name'] ?? '')),
        sprintf(__('Android app id: %s', 'll-tools-text-domain'), (string) ($payload_data['app_id'] ?? '')),
        !empty($icon_manifest)
            ? sprintf(__('App icon: %s', 'll-tools-text-domain'), (string) ($icon_manifest['source'] ?? '') === 'custom' ? __('Custom override image', 'll-tools-text-domain') : __('Current site icon', 'll-tools-text-domain'))
            : __('App icon: Builder default (no bundled icon)', 'll-tools-text-domain'),
        sprintf(__('Version: %1$s (%2$d)', 'll-tools-text-domain'), (string) ($options['version_name'] ?? ''), (int) ($options['version_code'] ?? 1)),
        '',
        __('To build an APK, extract this zip and run the scripts in offline-app-builder from this plugin repository against the bundle zip or extracted folder.', 'll-tools-text-domain'),
        __('This bundle includes the offline quiz shell, bundled media, and local-first quiz runtime data. Learners can keep studying fully offline, then optionally sign in later to sync saved progress back to the source site.', 'll-tools-text-domain'),
    ];
    $warnings = array_values((array) ($payload_data['warnings'] ?? []));
    if (!empty($warnings)) {
        $lines[] = '';
        $lines[] = __('Warnings:', 'll-tools-text-domain');
        foreach ($warnings as $warning) {
            $lines[] = '- ' . (string) $warning;
        }
    }
    $path = trailingslashit((string) $job['staging_dir']) . 'README.txt';
    if (@file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_readme_failed', __('Could not write the offline app bundle readme.', 'll-tools-text-domain'));
    }
    return $path;
}

function ll_tools_offline_app_export_initialize_data(array $job) {
    $payload_data = ll_tools_offline_app_export_build_payload($job);
    if (is_wp_error($payload_data)) {
        return $payload_data;
    }
    $payload = (array) ($payload_data['payload'] ?? []);
    $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $first_needle = wp_json_encode((string) ($payload_data['first_placeholder'] ?? ''));
    $categories_needle = wp_json_encode((string) ($payload_data['categories_placeholder'] ?? ''));
    if (!is_string($json) || !is_string($first_needle) || !is_string($categories_needle)) {
        return new WP_Error('ll_tools_offline_app_payload_failed', __('Could not encode offline app data.', 'll-tools-text-domain'));
    }
    $first_position = strpos($json, $first_needle);
    $categories_position = $first_position !== false
        ? strpos($json, $categories_needle, $first_position + strlen($first_needle))
        : false;
    if ($first_position === false || $categories_position === false) {
        return new WP_Error('ll_tools_offline_app_payload_placeholder_failed', __('Could not prepare streaming offline app data.', 'll-tools-text-domain'));
    }

    $data_dir = trailingslashit((string) $job['www_dir']) . 'data';
    if (!wp_mkdir_p($data_dir)) {
        return new WP_Error('ll_tools_offline_app_data_dir_failed', __('Could not create the offline app data directory.', 'll-tools-text-domain'));
    }
    $data_path = trailingslashit($data_dir) . 'offline-data.js';
    $middle_path = trailingslashit((string) $job['job_dir']) . 'offline-data-middle.tmp';
    $prefix = 'window.llToolsOfflineData = ' . substr($json, 0, $first_position) . '[';
    $middle = substr(
        $json,
        $first_position + strlen($first_needle),
        $categories_position - ($first_position + strlen($first_needle))
    );
    $suffix = substr($json, $categories_position + strlen($categories_needle));
    if (@file_put_contents($data_path, $prefix, LOCK_EX) === false
        || @file_put_contents($middle_path, $middle, LOCK_EX) === false
        || @file_put_contents((string) $job['data_suffix_path'], $suffix, LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_payload_write_failed', __('Could not stage streaming offline app data.', 'll-tools-text-domain'));
    }

    $shell = ll_tools_offline_app_export_write_shell(
        (string) $job['www_dir'],
        $payload,
        (array) ($payload_data['warnings'] ?? []),
        (array) ($payload_data['manifest'] ?? [])
    );
    if (is_wp_error($shell)) {
        return $shell;
    }
    $manifest_path = trailingslashit((string) $job['staging_dir']) . 'bundle-manifest.json';
    $manifest_saved = ll_tools_offline_app_export_write_json($manifest_path, (array) ($payload_data['manifest'] ?? []));
    if (is_wp_error($manifest_saved)) {
        return $manifest_saved;
    }
    $readme_path = ll_tools_offline_app_export_write_readme($job, $payload_data);
    if (is_wp_error($readme_path)) {
        return $readme_path;
    }
    foreach ([
        [$manifest_path, 'bundle-manifest.json'],
        [(string) $readme_path, 'README.txt'],
        [trailingslashit((string) $job['www_dir']) . 'index.html', 'www/index.html'],
    ] as $entry) {
        $registered = ll_tools_offline_app_export_append_zip_entry($job, (string) $entry[0], (string) $entry[1]);
        if (is_wp_error($registered)) {
            return $registered;
        }
    }

    $categories = array_values((array) ($job['categories'] ?? []));
    $first_category_count = (int) (($categories[0] ?? [])['usable_count'] ?? ($categories[0] ?? [])['word_count'] ?? 0);
    $job['data_path'] = wp_normalize_path($data_path);
    $job['data_middle_path'] = wp_normalize_path($middle_path);
    $job['data_stage'] = 'first_rows';
    $job['data_first_offset'] = 0;
    $job['data_first_has_rows'] = false;
    $job['data_category_index'] = 0;
    $job['data_offset'] = 0;
    $job['data_category_started'] = false;
    $job['data_category_has_rows'] = false;
    $job['data_total_rows'] = (int) ($job['usable_words'] ?? 0) + $first_category_count;
    $job['last_batch'] = ['phase' => 'data', 'categories' => 0, 'words' => 0, 'media' => 0, 'data' => 0, 'zip' => 0];
    return $job;
}

function ll_tools_offline_app_export_append_data_fragment(array $job, string $fragment) {
    $path = (string) ($job['data_path'] ?? '');
    if ($path === '' || @file_put_contents($path, $fragment, FILE_APPEND | LOCK_EX) === false) {
        return new WP_Error('ll_tools_offline_app_payload_write_failed', __('Could not continue writing offline app data.', 'll-tools-text-domain'));
    }
    return true;
}

function ll_tools_offline_app_export_stream_rows(array &$job, string $rows_path, string $offset_key, string $has_rows_key, int $limit) {
    if (!is_file($rows_path)) {
        return new WP_Error('ll_tools_offline_app_job_rows_missing', __('Prepared offline app word data is missing.', 'll-tools-text-domain'));
    }
    $handle = fopen($rows_path, 'rb');
    if (!$handle) {
        return new WP_Error('ll_tools_offline_app_job_rows_open_failed', __('Could not read prepared offline app word data.', 'll-tools-text-domain'));
    }
    $offset = max(0, (int) ($job[$offset_key] ?? 0));
    if ($offset > 0 && fseek($handle, $offset) !== 0) {
        fclose($handle);
        return new WP_Error('ll_tools_offline_app_job_rows_cursor_failed', __('Could not resume writing offline app data.', 'll-tools-text-domain'));
    }
    $processed = 0;
    $buffer = '';
    $has_rows = !empty($job[$has_rows_key]);
    while ($processed < $limit && ($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!is_array(json_decode($line, true))) {
            fclose($handle);
            return new WP_Error('ll_tools_offline_app_job_rows_invalid', __('Prepared offline app word data is invalid.', 'll-tools-text-domain'));
        }
        $buffer .= ($has_rows ? ',' : '') . $line;
        $has_rows = true;
        $processed++;
    }
    $job[$offset_key] = max(0, (int) ftell($handle));
    $job[$has_rows_key] = $has_rows;
    $at_end = feof($handle);
    fclose($handle);
    if ($buffer !== '') {
        $written = ll_tools_offline_app_export_append_data_fragment($job, $buffer);
        if (is_wp_error($written)) {
            return $written;
        }
    }
    return ['processed' => $processed, 'at_end' => $at_end];
}

function ll_tools_offline_app_export_run_data_step(array $job) {
    if ((string) ($job['data_stage'] ?? 'init') === 'init') {
        return ll_tools_offline_app_export_initialize_data($job);
    }
    $categories = array_values((array) ($job['categories'] ?? []));
    $batch_size = ll_tools_offline_app_export_data_batch_size();
    $processed = 0;

    while ($processed < $batch_size) {
        $stage = (string) ($job['data_stage'] ?? 'first_rows');
        if ($stage === 'first_rows') {
            $first_id = (int) (($categories[0] ?? [])['id'] ?? 0);
            $streamed = ll_tools_offline_app_export_stream_rows(
                $job,
                trailingslashit((string) $job['job_dir']) . 'categories/' . $first_id . '.ndjson',
                'data_first_offset',
                'data_first_has_rows',
                $batch_size - $processed
            );
            if (is_wp_error($streamed)) {
                return $streamed;
            }
            $processed += (int) ($streamed['processed'] ?? 0);
            if (empty($streamed['at_end'])) {
                break;
            }
            $middle = is_file((string) ($job['data_middle_path'] ?? ''))
                ? file_get_contents((string) $job['data_middle_path'])
                : false;
            if (!is_string($middle)) {
                return new WP_Error('ll_tools_offline_app_payload_middle_missing', __('Streaming offline app data state is missing.', 'll-tools-text-domain'));
            }
            $written = ll_tools_offline_app_export_append_data_fragment($job, ']' . $middle . '{');
            if (is_wp_error($written)) {
                return $written;
            }
            $job['data_stage'] = 'category_rows';
            continue;
        }

        if ($stage === 'category_rows') {
            $category_index = max(0, (int) ($job['data_category_index'] ?? 0));
            if ($category_index >= count($categories)) {
                $job['data_stage'] = 'finalize';
                continue;
            }
            $category = is_array($categories[$category_index] ?? null) ? $categories[$category_index] : [];
            if (empty($job['data_category_started'])) {
                $name_json = wp_json_encode((string) ($category['name'] ?? ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($name_json)) {
                    return new WP_Error('ll_tools_offline_app_payload_category_failed', __('Could not encode an offline app category.', 'll-tools-text-domain'));
                }
                $written = ll_tools_offline_app_export_append_data_fragment($job, ($category_index > 0 ? ',' : '') . $name_json . ':[');
                if (is_wp_error($written)) {
                    return $written;
                }
                $job['data_category_started'] = true;
                $job['data_category_has_rows'] = false;
                $job['data_offset'] = 0;
            }
            $streamed = ll_tools_offline_app_export_stream_rows(
                $job,
                trailingslashit((string) $job['job_dir']) . 'categories/' . (int) ($category['id'] ?? 0) . '.ndjson',
                'data_offset',
                'data_category_has_rows',
                $batch_size - $processed
            );
            if (is_wp_error($streamed)) {
                return $streamed;
            }
            $processed += (int) ($streamed['processed'] ?? 0);
            if (empty($streamed['at_end'])) {
                break;
            }
            $written = ll_tools_offline_app_export_append_data_fragment($job, ']');
            if (is_wp_error($written)) {
                return $written;
            }
            $job['data_category_index'] = $category_index + 1;
            $job['data_category_started'] = false;
            $job['data_category_has_rows'] = false;
            $job['data_offset'] = 0;
            continue;
        }

        if ($stage === 'finalize') {
            $suffix = is_file((string) ($job['data_suffix_path'] ?? ''))
                ? file_get_contents((string) $job['data_suffix_path'])
                : false;
            if (!is_string($suffix)) {
                return new WP_Error('ll_tools_offline_app_payload_suffix_missing', __('Streaming offline app data state is missing.', 'll-tools-text-domain'));
            }
            $written = ll_tools_offline_app_export_append_data_fragment($job, '}' . $suffix . ';' . "\n");
            if (is_wp_error($written)) {
                return $written;
            }
            $registered = ll_tools_offline_app_export_append_zip_entry($job, (string) $job['data_path'], 'www/data/offline-data.js');
            if (is_wp_error($registered)) {
                return $registered;
            }
            $job['data_stage'] = 'complete';
            $job['phase'] = 'zip';
            break;
        }
        break;
    }

    $job['processed_data_rows'] = (int) ($job['processed_data_rows'] ?? 0) + $processed;
    $job['last_batch'] = ['phase' => 'data', 'categories' => 0, 'words' => 0, 'media' => 0, 'data' => $processed, 'zip' => 0];
    return $job;
}

function ll_tools_offline_app_export_run_zip_step(array $job) {
    $manifest_path = (string) ($job['zip_manifest_path'] ?? '');
    if ($manifest_path === '' || !is_file($manifest_path)) {
        return new WP_Error('ll_tools_offline_app_job_zip_manifest_missing', __('Offline app zip manifest is missing.', 'll-tools-text-domain'));
    }
    $manifest = fopen($manifest_path, 'rb');
    if (!$manifest) {
        return new WP_Error('ll_tools_offline_app_job_zip_manifest_open_failed', __('Could not read the offline app zip manifest.', 'll-tools-text-domain'));
    }
    $offset = max(0, (int) ($job['zip_offset'] ?? 0));
    if ($offset > 0 && fseek($manifest, $offset) !== 0) {
        fclose($manifest);
        return new WP_Error('ll_tools_offline_app_job_zip_cursor_failed', __('Could not resume offline app zip progress.', 'll-tools-text-domain'));
    }
    $zip = new ZipArchive();
    $flags = ZipArchive::CREATE;
    if (empty($job['zip_started'])) {
        $flags |= ZipArchive::OVERWRITE;
    }
    if ($zip->open((string) $job['zip_path'], $flags) !== true) {
        fclose($manifest);
        return new WP_Error('ll_tools_offline_app_zip_open_failed', __('Could not create the offline app export zip.', 'll-tools-text-domain'));
    }

    $processed = 0;
    $batch_size = ll_tools_offline_app_export_zip_batch_size();
    while ($processed < $batch_size && ($line = fgets($manifest)) !== false) {
        $entry = json_decode(trim($line), true);
        $source_path = is_array($entry) ? wp_normalize_path((string) ($entry['source_path'] ?? '')) : '';
        $zip_path = is_array($entry) ? ll_tools_offline_app_normalize_relative_bundle_path((string) ($entry['zip_path'] ?? '')) : '';
        if ($source_path === '' || $zip_path === '' || !is_file($source_path) || !$zip->addFile($source_path, $zip_path)) {
            fclose($manifest);
            $zip->close();
            return new WP_Error('ll_tools_offline_app_zip_add_failed', __('Could not add files to the offline app export zip.', 'll-tools-text-domain'));
        }
        $processed++;
    }
    $job['zip_offset'] = max(0, (int) ftell($manifest));
    $at_end = feof($manifest);
    fclose($manifest);
    if (!$zip->close()) {
        return new WP_Error('ll_tools_offline_app_zip_close_failed', __('Could not finalize the offline app export zip.', 'll-tools-text-domain'));
    }
    $job['zip_started'] = true;
    $job['processed_zip_files'] = (int) ($job['processed_zip_files'] ?? 0) + $processed;
    $job['last_batch'] = ['phase' => 'zip', 'categories' => 0, 'words' => 0, 'media' => 0, 'data' => 0, 'zip' => $processed];
    if ($at_end) {
        $job['status'] = 'completed';
        $job['phase'] = 'completed';
    }
    return $job;
}

function ll_tools_handle_export_offline_app(): void {
    if (!ll_tools_current_user_can_offline_app_export()) {
        wp_die(__('You do not have permission to export offline app bundles.', 'll-tools-text-domain'));
    }

    check_admin_referer('ll_tools_export_offline_app');

    $job = ll_tools_offline_app_export_prepare_job($_POST);
    if (is_wp_error($job)) {
        wp_die($job->get_error_message());
    }

    $redirect_url = add_query_arg([
        'page' => ll_tools_get_offline_app_export_page_slug(),
        'll_offline_job' => (string) ($job['token'] ?? ''),
    ], admin_url('tools.php'));
    wp_safe_redirect($redirect_url);
    exit;
}

function ll_tools_build_offline_app_bundle(array $options = []) {
    do_action('ll_tools_offline_app_full_builder_started', $options);
    $wordset_id = isset($options['wordset_id']) ? (int) $options['wordset_id'] : 0;
    if ($wordset_id <= 0) {
        return new WP_Error('ll_tools_offline_app_missing_wordset', __('Offline app export requires a valid word set.', 'll-tools-text-domain'));
    }

    $wordset = get_term($wordset_id, 'wordset');
    if (!($wordset instanceof WP_Term) || is_wp_error($wordset)) {
        return new WP_Error('ll_tools_offline_app_invalid_wordset', __('The selected word set is invalid.', 'll-tools-text-domain'));
    }

    $category_ids = ll_tools_offline_app_normalize_id_list((array) ($options['category_ids'] ?? []));
    $app_name = trim((string) ($options['app_name'] ?? ''));
    if ($app_name === '') {
        $app_name = get_bloginfo('name');
    }
    $version_name = trim((string) ($options['version_name'] ?? ''));
    if ($version_name === '') {
        $version_name = ll_tools_get_plugin_version_string();
    }
    if ($version_name === '') {
        $version_name = '1.0.0';
    }
    $version_code = max(1, (int) ($options['version_code'] ?? 1));
    $app_id_suffix = ll_tools_offline_app_sanitize_app_id_suffix((string) ($options['app_id_suffix'] ?? ''), $wordset);
    $app_id = 'com.lltools.offline.' . $app_id_suffix;
    $app_icon_payload = ll_tools_offline_app_resolve_icon_payload((int) ($options['app_icon_attachment_id'] ?? 0));
    if (is_wp_error($app_icon_payload)) {
        return $app_icon_payload;
    }

    $use_translations = ll_flashcards_should_use_translations([$wordset_id]);
    $categories = ll_tools_offline_app_build_categories($wordset_id, $category_ids, $use_translations);

    if (empty($categories)) {
        return new WP_Error('ll_tools_offline_app_no_categories', __('No quizzable categories were found for this word set and selection.', 'll-tools-text-domain'));
    }

    $warnings = [];
    $asset_registry = [
        'images' => [],
        'audio'  => [],
    ];
    $asset_entries = [];
    $category_data = [];
    $kept_categories = [];
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);

    foreach ($categories as $category) {
        if (!is_array($category) || empty($category['name'])) {
            continue;
        }

        $option_type = (string) ($category['option_type'] ?? $category['mode'] ?? 'image');
        $words = ll_tools_offline_app_filter_words_to_wordset(
            ll_get_words_by_category((string) $category['name'], $option_type, [$wordset_id], $category),
            $wordset_id
        );
        $rewritten_words = ll_tools_offline_app_rewrite_category_words(
            (array) $words,
            (string) $category['name'],
            (array) $category,
            $asset_registry,
            $asset_entries,
            $warnings
        );

        if (count($rewritten_words) < max(1, $min_word_count)) {
            $warnings[] = sprintf(
                /* translators: 1: category name, 2: minimum words */
                __('Category "%1$s" was omitted from the offline app because only %2$d or fewer usable words remained after offline asset checks.', 'll-tools-text-domain'),
                (string) $category['name'],
                max(1, $min_word_count)
            );
            continue;
        }

        $category_data[(string) $category['name']] = $rewritten_words;
        $kept_categories[] = $category;
    }

    if (empty($kept_categories)) {
        return new WP_Error('ll_tools_offline_app_empty_after_filter', __('Every selected category was removed because required offline assets could not be bundled.', 'll-tools-text-domain'));
    }

    $categories = $kept_categories;
    $exported_category_ids = array_values(array_filter(array_map(static function ($category): int {
        return is_array($category) ? (int) ($category['id'] ?? 0) : 0;
    }, $categories), static function (int $id): bool {
        return $id > 0;
    }));
    $app_icon_manifest = [];
    if (!empty($app_icon_payload)) {
        $icon_extension = ll_tools_offline_app_get_image_extension(
            (string) ($app_icon_payload['filename'] ?? ''),
            (string) ($app_icon_payload['mime_type'] ?? '')
        );
        $icon_relative_path = 'app-assets/app-icon.' . $icon_extension;
        $asset_entries[] = [
            'source_path'   => (string) ($app_icon_payload['source_path'] ?? ''),
            'relative_path' => $icon_relative_path,
        ];
        $app_icon_manifest = [
            'attachmentId' => (int) ($app_icon_payload['attachment_id'] ?? 0),
            'source'       => (string) ($app_icon_payload['source'] ?? ''),
            'mimeType'     => (string) ($app_icon_payload['mime_type'] ?? ''),
            'bundlePath'   => 'www/' . $icon_relative_path,
            'webPath'      => './' . $icon_relative_path,
            'label'        => (string) ($app_icon_payload['label'] ?? ''),
        ];
    }
    $launcher_categories = ll_tools_offline_app_build_launcher_categories($categories, $category_data);
    $first_category_name = '';
    $first_category_data = [];
    foreach ($categories as $category) {
        $name = isset($category['name']) ? (string) $category['name'] : '';
        if ($name !== '' && !empty($category_data[$name])) {
            $first_category_name = $name;
            $first_category_data = $category_data[$name];
            break;
        }
    }
    $external_asset_entries = [];
    $offline_games_payload = ll_tools_offline_app_build_games_payload(
        $wordset_id,
        $wordset,
        $exported_category_ids,
        $category_data,
        $warnings
    );

    $bundle_manifest = [
        'formatVersion' => 1,
        'bundleType'    => 'll_tools_offline_app',
        'generatedAt'   => gmdate('c'),
        'site'          => home_url('/'),
        'app'           => [
            'name'         => $app_name,
            'versionName'  => $version_name,
            'versionCode'  => $version_code,
            'icon'         => !empty($app_icon_manifest) ? $app_icon_manifest : null,
        ],
        'android'       => [
            'appId' => $app_id,
        ],
        'wordset'       => [
            'id'   => (int) $wordset->term_id,
            'slug' => (string) $wordset->slug,
            'name' => (string) $wordset->name,
        ],
        'categories'    => array_values(array_map(static function (array $category): array {
            return [
                'id'   => (int) ($category['id'] ?? 0),
                'slug' => (string) ($category['slug'] ?? ''),
                'name' => (string) ($category['name'] ?? ''),
            ];
        }, $categories)),
        'warnings'      => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
    ];

    $mode_ui_config = function_exists('ll_flashcards_get_mode_ui_config')
        ? ll_flashcards_get_mode_ui_config()
        : [];
    $gender_runtime = ll_tools_offline_app_get_gender_runtime_config($wordset_id);
    $has_gender_supported_category = false;
    foreach ($categories as $category) {
        if (!empty($category['gender_supported'])) {
            $has_gender_supported_category = true;
            break;
        }
    }
    $available_modes = [
        'learning',
        'practice',
        'listening',
        'self-check',
    ];
    if (!empty($gender_runtime['enabled']) && $has_gender_supported_category) {
        $available_modes[] = 'gender';
    }

    $offline_payload = [
        'formatVersion' => 1,
        'flashcards'    => [
            'runtimeMode'         => 'offline',
            'plugin_dir'          => './plugin/',
            'mode'                => 'random',
            'quiz_mode'           => 'practice',
            'ajaxurl'             => '',
            'ajaxNonce'           => '',
            'isUserLoggedIn'      => false,
            'wordset'             => (string) $wordset->slug,
            'wordsetFallback'     => false,
            'wordsetIds'          => [(int) $wordset->term_id],
            'categories'          => array_values($categories),
            'categoriesPreselected' => false,
            'firstCategoryData'   => array_values($first_category_data),
            'firstCategoryName'   => $first_category_name,
            'imageSize'           => get_option('ll_flashcard_image_size', 'small'),
            'maxOptionsOverride'  => get_option('ll_max_options_override', 9),
            'modeUi'              => $mode_ui_config,
            'userStudyState'      => [
                'wordset_id'       => (int) $wordset->term_id,
                'category_ids'     => [],
                'starred_word_ids' => [],
                'star_mode'        => 'normal',
                'fast_transitions' => false,
            ],
            'starredWordIds'      => [],
            'starred_word_ids'    => [],
            'starMode'            => 'normal',
            'star_mode'           => 'normal',
            'fastTransitions'     => false,
            'fast_transitions'    => false,
            'userStudyNonce'      => '',
            'offlineSync'         => [
                'enabled'     => true,
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'siteUrl'     => home_url('/'),
                'loginAction' => 'll_tools_offline_app_login',
                'logoutAction' => 'll_tools_offline_app_logout',
                'syncAction'  => 'll_tools_offline_app_sync',
            ],
            'availableModes'      => $available_modes,
            'genderEnabled'       => !empty($gender_runtime['enabled']),
            'genderWordsetId'     => !empty($gender_runtime['enabled']) ? (int) $wordset->term_id : 0,
            'genderOptions'       => array_values((array) ($gender_runtime['options'] ?? [])),
            'genderVisualConfig'  => (array) ($gender_runtime['visual_config'] ?? []),
            'genderMinCount'      => (int) ($gender_runtime['min_count'] ?? $min_word_count),
            'preloadTuning'       => [
                'categoryAjaxConcurrency'       => 1,
                'categoryAjaxSpacingMs'         => 0,
                'categoryAjaxMaxRetriesOn429'   => 0,
                'categoryAjaxRetryBaseMs'       => 250,
                'categoryAjaxRetryMaxMs'        => 1000,
                'categoryMediaChunkSize'        => 8,
                'categoryMediaChunkDelayMs'     => 40,
                'categoryMediaChunkConcurrency' => 2,
            ],
            'resultsCategoryPreviewLimit' => (int) apply_filters('ll_tools_results_category_preview_limit', 3),
            'sortLocale'          => get_locale(),
            'offlineCategoryData' => $category_data,
        ],
        'messages'      => ll_flashcards_get_messages(),
        'games'         => $offline_games_payload,
        'app'           => [
            'title'        => $app_name,
            'versionName'  => $version_name,
            'versionCode'  => $version_code,
            'wordsetName'  => (string) $wordset->name,
            'icon'         => !empty($app_icon_manifest) ? [
                'url'      => (string) ($app_icon_manifest['webPath'] ?? ''),
                'mimeType' => (string) ($app_icon_manifest['mimeType'] ?? ''),
            ] : null,
            'launcher'     => [
                'categories'   => $launcher_categories,
                'previewLimit' => 2,
            ],
            'views'        => [
                'study' => [
                    'enabled' => true,
                ],
                'games' => [
                    'enabled' => !empty($offline_games_payload['catalog']) && is_array($offline_games_payload['catalog']),
                ],
            ],
            'sync'         => [
                'enabled' => true,
                'messages' => [
                    'localOnlyLabel' => __('Local progress only', 'll-tools-text-domain'),
                    'connectedAsLabel' => __('Connected as %s', 'll-tools-text-domain'),
                    'connectButton' => __('Connect account', 'll-tools-text-domain'),
                    'disconnectButton' => __('Disconnect', 'll-tools-text-domain'),
                    'syncNowButton' => __('Sync now', 'll-tools-text-domain'),
                    'syncPendingLabel' => __('%d pending', 'll-tools-text-domain'),
                    'syncIdleLabel' => __('All caught up', 'll-tools-text-domain'),
                    'syncFailedLabel' => __('Sync failed. Your local progress is still saved.', 'll-tools-text-domain'),
                    'syncFormTitle' => __('Connect to Sync', 'll-tools-text-domain'),
                    'syncIdentifierLabel' => __('Username or email', 'll-tools-text-domain'),
                    'syncPasswordLabel' => __('Password', 'll-tools-text-domain'),
                    'syncSubmitButton' => __('Sign in', 'll-tools-text-domain'),
                    'syncCancelButton' => __('Cancel', 'll-tools-text-domain'),
                    'syncSignedOutLabel' => __('Disconnected. The app will keep storing progress locally.', 'll-tools-text-domain'),
                    'syncInProgressLabel' => __('Syncing…', 'll-tools-text-domain'),
                    'showPasswordLabel' => __('Show password', 'll-tools-text-domain'),
                    'hidePasswordLabel' => __('Hide password', 'll-tools-text-domain'),
                ],
            ],
        ],
    ];

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit((string) $upload_dir['basedir']);
    if ($base_dir === '' || !wp_mkdir_p($base_dir)) {
        return new WP_Error('ll_tools_offline_app_uploads_missing', __('Could not access the uploads directory for offline app export.', 'll-tools-text-domain'));
    }

    $token = 'll-tools-offline-app-' . wp_generate_password(10, false, false);
    $staging_dir = $base_dir . $token;
    $www_dir = trailingslashit($staging_dir) . 'www';
    if (!wp_mkdir_p($www_dir)) {
        return new WP_Error('ll_tools_offline_app_staging_failed', __('Could not create the offline app staging directory.', 'll-tools-text-domain'));
    }

    $stage_result = ll_tools_offline_app_stage_web_bundle($www_dir, $offline_payload, $asset_entries, $external_asset_entries, $warnings, $bundle_manifest);
    if (is_wp_error($stage_result)) {
        ll_tools_rrmdir($staging_dir);
        return $stage_result;
    }

    $bundle_manifest_path = trailingslashit($staging_dir) . 'bundle-manifest.json';
    $manifest_json = wp_json_encode($bundle_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($manifest_json) || $manifest_json === '') {
        ll_tools_rrmdir($staging_dir);
        return new WP_Error('ll_tools_offline_app_manifest_failed', __('Could not encode the offline bundle manifest.', 'll-tools-text-domain'));
    }
    file_put_contents($bundle_manifest_path, $manifest_json);

    $readme_lines = [
        __('LL Tools Offline App Bundle', 'll-tools-text-domain'),
        '===========================',
        '',
        sprintf(
            /* translators: %s: word set name */
            __('Word set: %s', 'll-tools-text-domain'),
            (string) $wordset->name
        ),
        sprintf(
            /* translators: %s: app name */
            __('App name: %s', 'll-tools-text-domain'),
            $app_name
        ),
        sprintf(
            /* translators: %s: Android app id */
            __('Android app id: %s', 'll-tools-text-domain'),
            $app_id
        ),
        !empty($app_icon_manifest)
            ? sprintf(
                /* translators: %s: icon source */
                __('App icon: %s', 'll-tools-text-domain'),
                !empty($app_icon_payload['source']) && (string) $app_icon_payload['source'] === 'custom'
                    ? __('Custom override image', 'll-tools-text-domain')
                    : __('Current site icon', 'll-tools-text-domain')
            )
            : __('App icon: Builder default (no bundled icon)', 'll-tools-text-domain'),
        sprintf(
            /* translators: 1: version name, 2: version code */
            __('Version: %1$s (%2$d)', 'll-tools-text-domain'),
            $version_name,
            $version_code
        ),
        '',
        __('To build an APK, extract this zip and run the scripts in offline-app-builder from this plugin repository against the bundle zip or extracted folder.', 'll-tools-text-domain'),
        __('This bundle includes the offline quiz shell, bundled media, and local-first quiz runtime data. Learners can keep studying fully offline, then optionally sign in later to sync saved progress back to the source site.', 'll-tools-text-domain'),
    ];
    if (!empty($warnings)) {
        $readme_lines[] = '';
        $readme_lines[] = __('Warnings:', 'll-tools-text-domain');
        foreach (array_unique($warnings) as $warning) {
            $readme_lines[] = '- ' . $warning;
        }
    }
    file_put_contents(trailingslashit($staging_dir) . 'README.txt', implode("\n", $readme_lines) . "\n");

    $filename = 'll-tools-offline-app-' . sanitize_title((string) $wordset->slug) . '-' . gmdate('Ymd-His') . '.zip';
    if (!empty($options['skip_zip'])) {
        return [
            'zip_path'    => '',
            'staging_dir' => $staging_dir,
            'filename'    => $filename,
        ];
    }

    $zip_path = trailingslashit($base_dir) . $token . '.zip';
    $zip_result = ll_tools_offline_app_zip_directory($staging_dir, $zip_path);
    if (is_wp_error($zip_result)) {
        ll_tools_rrmdir($staging_dir);
        return $zip_result;
    }

    return [
        'zip_path'    => $zip_path,
        'staging_dir' => $staging_dir,
        'filename'    => $filename,
    ];
}

function ll_tools_offline_app_stage_web_bundle(string $www_dir, array $offline_payload, array $asset_entries, array $external_asset_entries, array $warnings, array $bundle_manifest) {
    $style_files = [
        'css/language-learner-tools.css',
        'css/wordset-pages.css',
        'css/wordset-games.css',
        'css/ipa-fonts.css',
        'css/flashcard/base.css',
        'css/self-check-shared.css',
        'css/flashcard/mode-practice.css',
        'css/flashcard/mode-learning.css',
        'css/flashcard/mode-listening.css',
        'css/flashcard/mode-gender.css',
    ];
    $script_files = [
        'js/flashcard-widget/option-conflicts.js',
        'js/flashcard-widget/audio.js',
        'js/flashcard-widget/loader.js',
        'js/flashcard-widget/options.js',
        'js/flashcard-widget/util.js',
        'js/locale-sort.js',
        'js/self-check-shared.js',
        'js/wordset-games.js',
        'js/flashcard-widget/mode-config.js',
        'js/flashcard-widget/state.js',
        'js/flashcard-widget/progress-tracker.js',
        'js/flashcard-widget/dom.js',
        'js/flashcard-widget/audio-visualizer.js',
        'js/flashcard-widget/effects.js',
        'js/flashcard-widget/cards.js',
        'js/flashcard-widget/selection.js',
        'js/flashcard-widget/results.js',
        'js/flashcard-widget/modes/practice.js',
        'js/flashcard-widget/modes/learning.js',
        'js/flashcard-widget/modes/self-check.js',
        'js/flashcard-widget/modes/listening.js',
        'js/flashcard-widget/modes/gender.js',
        'js/flashcard-widget/main.js',
        'js/flashcard-widget/category-selection.js',
    ];
    $media_files = [
        'media/right-answer.mp3',
        'media/wrong-answer.mp3',
        'media/space-shooter-correct-hit.mp3',
        'media/space-shooter-correct-hit.ogg',
        'media/space-shooter-wrong-hit.mp3',
        'media/space-shooter-wrong-hit.ogg',
        'media/bubble-pop.mp3',
    ];

    foreach ($style_files as $relative_path) {
        $copy = ll_tools_offline_app_copy_plugin_asset($relative_path, $www_dir);
        if (is_wp_error($copy)) {
            return $copy;
        }
    }

    foreach ($script_files as $relative_path) {
        $copy = ll_tools_offline_app_copy_plugin_asset($relative_path, $www_dir);
        if (is_wp_error($copy)) {
            return $copy;
        }
    }

    foreach ($media_files as $relative_path) {
        $copy = ll_tools_offline_app_copy_plugin_asset($relative_path, $www_dir);
        if (is_wp_error($copy)) {
            return $copy;
        }
    }

    $fonts_copy = ll_tools_offline_app_copy_plugin_directory('fonts/ll-ipa', $www_dir);
    if (is_wp_error($fonts_copy)) {
        return $fonts_copy;
    }

    $jquery_source = ll_tools_offline_app_find_jquery_source();
    if (!is_file($jquery_source)) {
        return new WP_Error('ll_tools_offline_app_missing_jquery', __('Could not find the local WordPress jQuery runtime for the offline app shell.', 'll-tools-text-domain'));
    }

    $vendor_dir = trailingslashit($www_dir) . 'vendor/jquery';
    if (!wp_mkdir_p($vendor_dir)) {
        return new WP_Error('ll_tools_offline_app_vendor_dir_failed', __('Could not create the offline app vendor directory.', 'll-tools-text-domain'));
    }
    if (!copy($jquery_source, trailingslashit($vendor_dir) . 'jquery.min.js')) {
        return new WP_Error('ll_tools_offline_app_copy_jquery_failed', __('Could not stage jQuery for the offline app shell.', 'll-tools-text-domain'));
    }

    $data_dir = trailingslashit($www_dir) . 'data';
    $app_dir = trailingslashit($www_dir) . 'app';
    if (!wp_mkdir_p($data_dir) || !wp_mkdir_p($app_dir)) {
        return new WP_Error('ll_tools_offline_app_data_dir_failed', __('Could not create the offline app data directory.', 'll-tools-text-domain'));
    }

    $payload_json = wp_json_encode($offline_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload_json) || $payload_json === '') {
        return new WP_Error('ll_tools_offline_app_payload_failed', __('Could not encode offline app data.', 'll-tools-text-domain'));
    }
    file_put_contents(trailingslashit($data_dir) . 'offline-data.js', 'window.llToolsOfflineData = ' . $payload_json . ';' . "\n");

    $bootstrap_source = LL_TOOLS_BASE_PATH . 'offline-app/offline-app.js';
    if (!is_file($bootstrap_source)) {
        return new WP_Error('ll_tools_offline_app_bootstrap_missing', __('The offline app bootstrap script is missing from the plugin.', 'll-tools-text-domain'));
    }
    if (!copy($bootstrap_source, trailingslashit($app_dir) . 'offline-app.js')) {
        return new WP_Error('ll_tools_offline_app_bootstrap_copy_failed', __('Could not stage the offline app bootstrap script.', 'll-tools-text-domain'));
    }

    foreach ($asset_entries as $asset_entry) {
        $source_path = wp_normalize_path((string) ($asset_entry['source_path'] ?? ''));
        $relative_path = ltrim((string) ($asset_entry['relative_path'] ?? ''), '/');
        if ($source_path === '' || $relative_path === '') {
            continue;
        }
        $destination = trailingslashit($www_dir) . $relative_path;
        $destination_dir = dirname($destination);
        if (!wp_mkdir_p($destination_dir)) {
            return new WP_Error('ll_tools_offline_app_content_dir_failed', __('Could not create the offline app content directory.', 'll-tools-text-domain'));
        }
        if (!copy($source_path, $destination)) {
            return new WP_Error('ll_tools_offline_app_content_copy_failed', __('Could not copy exported media into the offline app bundle.', 'll-tools-text-domain'));
        }
    }

    foreach ($external_asset_entries as $asset_entry) {
        $copy_external = ll_tools_offline_app_copy_external_source(
            (string) ($asset_entry['source_path'] ?? ''),
            (string) ($asset_entry['relative_path'] ?? ''),
            $www_dir
        );
        if (is_wp_error($copy_external)) {
            return $copy_external;
        }
    }

    $app_config = (array) ($offline_payload['app'] ?? []);
    $flashcards = (array) ($offline_payload['flashcards'] ?? []);
    $games_payload = (array) ($offline_payload['games'] ?? []);
    $games_catalog = is_array($games_payload['catalog'] ?? null) ? (array) $games_payload['catalog'] : [];
    $games_shell_html = '';
    $games_wordset_id = (int) ($bundle_manifest['wordset']['id'] ?? 0);
    if ($games_wordset_id > 0 && !empty($games_catalog) && function_exists('ll_tools_render_wordset_games_shell')) {
        $games_wordset_term = get_term($games_wordset_id, 'wordset');
        if ($games_wordset_term instanceof WP_Term && !is_wp_error($games_wordset_term)) {
            $games_shell_html = ll_tools_render_wordset_games_shell([
                'wordset_term' => $games_wordset_term,
                'games_catalog' => $games_catalog,
                'is_study_user' => true,
                'back_url' => '#ll-offline-study-view',
                'as_modal' => false,
                'is_open' => true,
            ]);
        }
    }
    $html = ll_tools_capture_template('offline-app-shell-template.php', [
        'app_title'        => (string) ($app_config['title'] ?? get_bloginfo('name')),
        'wordset_name'     => (string) ($app_config['wordsetName'] ?? ''),
        'styles'           => [
            './plugin/css/language-learner-tools.css',
            './plugin/css/wordset-pages.css',
            './plugin/css/wordset-games.css',
            './plugin/css/flashcard/base.css',
            './plugin/css/self-check-shared.css',
            './plugin/css/flashcard/mode-practice.css',
            './plugin/css/flashcard/mode-learning.css',
            './plugin/css/flashcard/mode-listening.css',
            './plugin/css/flashcard/mode-gender.css',
        ],
        'scripts'          => [
            './vendor/jquery/jquery.min.js',
            './data/offline-data.js',
            './plugin/js/flashcard-widget/option-conflicts.js',
            './plugin/js/flashcard-widget/audio.js',
            './plugin/js/flashcard-widget/loader.js',
            './plugin/js/flashcard-widget/options.js',
            './plugin/js/flashcard-widget/util.js',
            './plugin/js/locale-sort.js',
            './plugin/js/self-check-shared.js',
            './plugin/js/flashcard-widget/mode-config.js',
            './plugin/js/flashcard-widget/state.js',
            './plugin/js/flashcard-widget/progress-tracker.js',
            './plugin/js/wordset-games.js',
            './plugin/js/flashcard-widget/dom.js',
            './plugin/js/flashcard-widget/audio-visualizer.js',
            './plugin/js/flashcard-widget/effects.js',
            './plugin/js/flashcard-widget/cards.js',
            './plugin/js/flashcard-widget/selection.js',
            './plugin/js/flashcard-widget/results.js',
            './plugin/js/flashcard-widget/modes/practice.js',
            './plugin/js/flashcard-widget/modes/learning.js',
            './plugin/js/flashcard-widget/modes/self-check.js',
            './plugin/js/flashcard-widget/modes/listening.js',
            './plugin/js/flashcard-widget/modes/gender.js',
            './plugin/js/flashcard-widget/main.js',
            './plugin/js/flashcard-widget/category-selection.js',
            './app/offline-app.js',
        ],
        'startup_mode'     => (string) ($flashcards['quiz_mode'] ?? 'practice'),
        'app_icon_url'     => is_array($app_config['icon'] ?? null) ? (string) ($app_config['icon']['url'] ?? '') : '',
        'app_icon_mime'    => is_array($app_config['icon'] ?? null) ? (string) ($app_config['icon']['mimeType'] ?? '') : '',
        'warnings'         => $warnings,
        'bundle_manifest'  => $bundle_manifest,
        'mode_ui'          => is_array($flashcards['modeUi'] ?? null) ? (array) $flashcards['modeUi'] : [],
        'games_enabled'    => !empty($games_catalog),
        'games_shell_html' => $games_shell_html,
        'll_config'        => [
            'wordset'         => (string) ($flashcards['wordset'] ?? ''),
            'wordsetFallback' => !empty($flashcards['wordsetFallback']),
            'genderEnabled'   => !empty($flashcards['genderEnabled']),
            'genderOptions'   => array_values((array) ($flashcards['genderOptions'] ?? [])),
        ],
    ]);

    if ($html === '') {
        return new WP_Error('ll_tools_offline_app_template_failed', __('Could not render the offline app shell template.', 'll-tools-text-domain'));
    }
    file_put_contents(trailingslashit($www_dir) . 'index.html', $html);

    return true;
}

function ll_tools_offline_app_find_jquery_source(): string {
    $candidates = [
        wp_normalize_path(ABSPATH . WPINC . '/js/jquery/jquery.min.js'),
        wp_normalize_path(dirname(LL_TOOLS_BASE_PATH, 3) . '/wp-includes/js/jquery/jquery.min.js'),
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function ll_tools_offline_app_rewrite_category_words(array $words, string $category_name, array $category_config, array &$asset_registry, array &$asset_entries, array &$warnings): array {
    $rewritten = [];
    $needs_audio = ll_tools_quiz_requires_audio([
        'prompt_type' => (string) ($category_config['prompt_type'] ?? 'audio'),
        'option_type' => (string) ($category_config['option_type'] ?? 'image'),
    ], (string) ($category_config['option_type'] ?? 'image'));
    $needs_image = function_exists('ll_tools_quiz_requires_image')
        ? ll_tools_quiz_requires_image([
            'prompt_type' => (string) ($category_config['prompt_type'] ?? 'audio'),
            'option_type' => (string) ($category_config['option_type'] ?? 'image'),
        ], (string) ($category_config['option_type'] ?? 'image'))
        : (
            ((string) ($category_config['prompt_type'] ?? '') === 'image')
            || ((string) ($category_config['option_type'] ?? '') === 'image')
        );

    foreach ($words as $word) {
        if (!is_array($word)) {
            continue;
        }

        $word_id = isset($word['id']) ? (int) $word['id'] : 0;
        if ($word_id <= 0) {
            continue;
        }

        $word['audio'] = '';
        $word['has_audio'] = false;
        $word['has_image'] = false;

        $image_asset = ll_tools_offline_app_register_word_image_asset($word_id, $asset_registry, $asset_entries, $warnings);
        if (is_string($image_asset) && $image_asset !== '') {
            $word['image'] = $image_asset;
            $word['has_image'] = true;
        } else {
            $word['image'] = '';
        }

        $audio_url_map = ll_tools_offline_app_build_word_audio_url_map($word_id, $asset_registry, $asset_entries, $warnings);
        $audio_files = [];
        if (!empty($word['audio_files']) && is_array($word['audio_files'])) {
            foreach ($word['audio_files'] as $audio_entry) {
                if (!is_array($audio_entry)) {
                    continue;
                }
                $source_url = isset($audio_entry['url']) ? (string) $audio_entry['url'] : '';
                $rewritten_url = ($source_url !== '' && isset($audio_url_map[$source_url])) ? $audio_url_map[$source_url] : '';
                if ($rewritten_url === '') {
                    continue;
                }
                $audio_entry['url'] = $rewritten_url;
                $audio_files[] = $audio_entry;
            }
        }
        $word['audio_files'] = $audio_files;

        if (!empty($word['audio']) && is_string($word['audio']) && isset($audio_url_map[$word['audio']])) {
            $word['audio'] = $audio_url_map[$word['audio']];
        } elseif (!empty($audio_files[0]['url'])) {
            $word['audio'] = (string) $audio_files[0]['url'];
        } else {
            $word['audio'] = '';
        }
        $word['has_audio'] = ($word['audio'] !== '' || !empty($audio_files));

        if ($needs_audio && !$word['has_audio']) {
            $warnings[] = sprintf(
                /* translators: 1: word title, 2: category name */
                __('Word "%1$s" was omitted from offline category "%2$s" because no local audio file could be bundled.', 'll-tools-text-domain'),
                (string) ($word['title'] ?? $word_id),
                $category_name
            );
            continue;
        }

        if ($needs_image && !$word['has_image']) {
            $warnings[] = sprintf(
                /* translators: 1: word title, 2: category name */
                __('Word "%1$s" was omitted from offline category "%2$s" because no local image file could be bundled.', 'll-tools-text-domain'),
                (string) ($word['title'] ?? $word_id),
                $category_name
            );
            continue;
        }

        $rewritten[] = $word;
    }

    return array_values($rewritten);
}

function ll_tools_offline_app_build_word_audio_url_map(int $word_id, array &$asset_registry, array &$asset_entries, array &$warnings): array {
    $word_id = (int) $word_id;
    if ($word_id <= 0) {
        return [];
    }

    $audio_posts = get_posts([
        'post_type'      => 'word_audio',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'post_parent'    => $word_id,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $map = [];
    foreach ($audio_posts as $audio_post) {
        if (!($audio_post instanceof WP_Post)) {
            continue;
        }

        $audio_path = (string) get_post_meta($audio_post->ID, 'audio_file_path', true);
        if ($audio_path === '') {
            continue;
        }

        $source_url = function_exists('ll_tools_resolve_audio_file_url')
            ? (string) ll_tools_resolve_audio_file_url($audio_path)
            : '';
        $source_file = function_exists('ll_tools_export_resolve_audio_source_path')
            ? (string) ll_tools_export_resolve_audio_source_path($audio_path)
            : '';

        if ($source_file === '' || !is_file($source_file)) {
            $warnings[] = sprintf(
                /* translators: %d: audio post id */
                __('Audio post %d could not be bundled because its source file is missing.', 'll-tools-text-domain'),
                (int) $audio_post->ID
            );
            continue;
        }

        if (isset($asset_registry['audio'][$source_file])) {
            $relative_path = (string) $asset_registry['audio'][$source_file];
        } else {
            $relative_path = 'content/audio/' . (int) $audio_post->ID . '-' . basename($source_file);
            $asset_registry['audio'][$source_file] = $relative_path;
            $asset_entries[] = [
                'source_path'   => $source_file,
                'relative_path' => $relative_path,
            ];
        }

        if ($source_url !== '') {
            $map[$source_url] = './' . ltrim($relative_path, '/');
        }
    }

    return $map;
}

function ll_tools_offline_app_register_word_image_asset(int $word_id, array &$asset_registry, array &$asset_entries, array &$warnings): string {
    $attachment_id = function_exists('ll_tools_get_effective_word_image_attachment_id_for_word')
        ? (int) ll_tools_get_effective_word_image_attachment_id_for_word($word_id, true)
        : (int) get_post_thumbnail_id($word_id);
    if ($attachment_id <= 0) {
        return '';
    }

    $file_path = get_attached_file($attachment_id);
    $file_path = is_string($file_path) ? wp_normalize_path($file_path) : '';
    if ($file_path === '' || !is_file($file_path)) {
        $warnings[] = sprintf(
            /* translators: %d: word id */
            __('Word %d could not bundle its featured image because the source file is missing.', 'll-tools-text-domain'),
            $word_id
        );
        return '';
    }

    if (isset($asset_registry['images'][$file_path])) {
        $relative_path = (string) $asset_registry['images'][$file_path];
    } else {
        $relative_path = 'content/images/' . $attachment_id . '-' . basename($file_path);
        $asset_registry['images'][$file_path] = $relative_path;
        $asset_entries[] = [
            'source_path'   => $file_path,
            'relative_path' => $relative_path,
        ];
    }

    return './' . ltrim($relative_path, '/');
}

function ll_tools_offline_app_build_launcher_preview(array $words, int $limit = 2, bool $use_images = true): array {
    $limit = max(1, (int) $limit);
    $preview = [];
    $seen_image_urls = [];
    $seen_text_labels = [];

    if ($use_images) {
        foreach ($words as $word) {
            if (count($preview) >= $limit || !is_array($word)) {
                continue;
            }

            $image_url = isset($word['image']) ? trim((string) $word['image']) : '';
            if ($image_url === '' || isset($seen_image_urls[$image_url])) {
                continue;
            }

            $seen_image_urls[$image_url] = true;
            $dimensions = [
                'ratio' => '',
                'width' => 0,
                'height' => 0,
            ];
            $word_id = (int) ($word['id'] ?? 0);
            $attachment_id = function_exists('ll_tools_get_effective_word_image_attachment_id_for_word')
                ? (int) ll_tools_get_effective_word_image_attachment_id_for_word($word_id, true)
                : (int) get_post_thumbnail_id($word_id);
            if ($attachment_id > 0 && function_exists('ll_tools_get_image_dimensions_for_size')) {
                $dimensions = ll_tools_get_image_dimensions_for_size($attachment_id, 'medium');
            }

            $preview[] = [
                'type'   => 'image',
                'url'    => $image_url,
                'alt'    => (string) ($word['title'] ?? ''),
                'ratio'  => (string) ($dimensions['ratio'] ?? ''),
                'width'  => (int) ($dimensions['width'] ?? 0),
                'height' => (int) ($dimensions['height'] ?? 0),
            ];
        }
    }

    foreach ($words as $word) {
        if (count($preview) >= $limit || !is_array($word)) {
            continue;
        }

        $label = trim((string) ($word['title'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($word['translation'] ?? ''));
        }
        if ($label === '' || isset($seen_text_labels[$label])) {
            continue;
        }

        $seen_text_labels[$label] = true;
        $preview[] = [
            'type'  => 'text',
            'label' => $label,
        ];
    }

    return array_values(array_slice($preview, 0, $limit));
}

function ll_tools_offline_app_category_requires_images(array $category): bool {
    $prompt_type = isset($category['prompt_type']) ? (string) $category['prompt_type'] : 'audio';
    $option_type = isset($category['option_type']) ? (string) $category['option_type'] : (string) ($category['mode'] ?? 'image');

    if (function_exists('ll_tools_quiz_requires_image')) {
        return ll_tools_quiz_requires_image([
            'prompt_type' => $prompt_type,
            'option_type' => $option_type,
        ], $option_type);
    }

    return (($prompt_type === 'image') || ($option_type === 'image'));
}

function ll_tools_offline_app_build_launcher_categories(array $categories, array $category_data): array {
    $launcher_categories = [];

    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }

        $category_name = isset($category['name']) ? trim((string) $category['name']) : '';
        if ($category_name === '') {
            continue;
        }

        $words = isset($category_data[$category_name]) && is_array($category_data[$category_name])
            ? array_values($category_data[$category_name])
            : [];
        if (empty($words)) {
            continue;
        }

        $requires_images = array_key_exists('requires_images', $category)
            ? !empty($category['requires_images'])
            : ll_tools_offline_app_category_requires_images($category);
        $preview_limit = $requires_images ? 2 : 4;
        $preview = ll_tools_offline_app_build_launcher_preview($words, $preview_limit, $requires_images);
        $has_images = false;
        $preview_aspect_ratio = '';
        foreach ($preview as $preview_item) {
            if (is_array($preview_item) && (($preview_item['type'] ?? '') === 'image')) {
                $has_images = true;
                if ($preview_aspect_ratio === '') {
                    $preview_aspect_ratio = (string) ($preview_item['ratio'] ?? '');
                }
                break;
            }
        }

        $launcher_category = $category;
        $launcher_category['word_count'] = count($words);
        $launcher_category['preview'] = $preview;
        $launcher_category['preview_limit'] = $preview_limit;
        $launcher_category['has_images'] = $has_images;
        $launcher_category['preview_aspect_ratio'] = $preview_aspect_ratio;
        $launcher_categories[] = $launcher_category;
    }

    return array_values($launcher_categories);
}

function ll_tools_offline_app_copy_plugin_asset(string $relative_path, string $www_dir) {
    $source_path = wp_normalize_path(LL_TOOLS_BASE_PATH . ltrim($relative_path, '/'));
    if (!is_file($source_path)) {
        return new WP_Error(
            'll_tools_offline_app_missing_asset',
            sprintf(
                /* translators: %s: relative plugin path */
                __('The offline app bundle is missing required asset "%s".', 'll-tools-text-domain'),
                $relative_path
            )
        );
    }

    $destination = trailingslashit($www_dir) . 'plugin/' . ltrim($relative_path, '/');
    $destination_dir = dirname($destination);
    if (!wp_mkdir_p($destination_dir)) {
        return new WP_Error('ll_tools_offline_app_copy_dir_failed', __('Could not create the offline app asset directory.', 'll-tools-text-domain'));
    }

    if (!copy($source_path, $destination)) {
        return new WP_Error(
            'll_tools_offline_app_copy_failed',
            sprintf(
                /* translators: %s: relative plugin path */
                __('Could not copy required offline asset "%s".', 'll-tools-text-domain'),
                $relative_path
            )
        );
    }

    return true;
}

function ll_tools_offline_app_copy_external_source(string $source_path, string $relative_path, string $www_dir) {
    $source_path = wp_normalize_path(trim((string) $source_path));
    $relative_path = ltrim((string) $relative_path, '/');
    if ($source_path === '' || $relative_path === '') {
        return true;
    }

    $destination = trailingslashit($www_dir) . $relative_path;
    if (is_file($source_path)) {
        $destination_dir = dirname($destination);
        if (!wp_mkdir_p($destination_dir)) {
            return new WP_Error('ll_tools_offline_app_copy_dir_failed', __('Could not create the offline app model directory.', 'll-tools-text-domain'));
        }
        if (!copy($source_path, $destination)) {
            return new WP_Error('ll_tools_offline_app_copy_failed', __('Could not copy the offline STT model file into the app bundle.', 'll-tools-text-domain'));
        }
        return true;
    }

    if (!is_dir($source_path)) {
        return new WP_Error('ll_tools_offline_app_missing_external_source', __('The offline STT bundle source could not be found.', 'll-tools-text-domain'));
    }

    if (!wp_mkdir_p($destination)) {
        return new WP_Error('ll_tools_offline_app_copy_dir_failed', __('Could not create the offline STT bundle directory.', 'll-tools-text-domain'));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $src = wp_normalize_path($file->getPathname());
        $relative = ltrim(substr($src, strlen($source_path)), '/');
        $dest = trailingslashit($destination) . $relative;
        if ($file->isDir()) {
            if (!wp_mkdir_p($dest)) {
                return new WP_Error('ll_tools_offline_app_copy_dir_failed', __('Could not create a nested offline STT directory.', 'll-tools-text-domain'));
            }
            continue;
        }
        $dest_dir = dirname($dest);
        if (!wp_mkdir_p($dest_dir) || !copy($src, $dest)) {
            return new WP_Error('ll_tools_offline_app_copy_failed', __('Could not copy the offline STT bundle into the app.', 'll-tools-text-domain'));
        }
    }

    return true;
}

function ll_tools_offline_app_copy_plugin_directory(string $relative_dir, string $www_dir) {
    $source_dir = wp_normalize_path(LL_TOOLS_BASE_PATH . trim($relative_dir, '/'));
    if (!is_dir($source_dir)) {
        return new WP_Error(
            'll_tools_offline_app_missing_dir',
            sprintf(
                /* translators: %s: relative plugin directory */
                __('The offline app bundle is missing required directory "%s".', 'll-tools-text-domain'),
                $relative_dir
            )
        );
    }

    $destination_dir = trailingslashit($www_dir) . 'plugin/' . trim($relative_dir, '/');
    if (!wp_mkdir_p($destination_dir)) {
        return new WP_Error('ll_tools_offline_app_copy_dir_failed', __('Could not create the offline app directory.', 'll-tools-text-domain'));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $src = wp_normalize_path($file->getPathname());
        $relative = ltrim(substr($src, strlen($source_dir)), '/');
        $dest = trailingslashit($destination_dir) . $relative;
        if ($file->isDir()) {
            if (!wp_mkdir_p($dest)) {
                return new WP_Error('ll_tools_offline_app_copy_dir_failed', __('Could not create a nested offline app directory.', 'll-tools-text-domain'));
            }
            continue;
        }
        $dest_dir = dirname($dest);
        if (!wp_mkdir_p($dest_dir) || !copy($src, $dest)) {
            return new WP_Error(
                'll_tools_offline_app_copy_failed',
                sprintf(
                    /* translators: %s: relative plugin directory */
                    __('Could not copy files from "%s" into the offline app bundle.', 'll-tools-text-domain'),
                    $relative_dir
                )
            );
        }
    }

    return true;
}

function ll_tools_offline_app_zip_directory(string $source_dir, string $zip_path) {
    $source_dir = wp_normalize_path($source_dir);
    if (!is_dir($source_dir)) {
        return new WP_Error('ll_tools_offline_app_missing_stage', __('The offline app staging directory does not exist.', 'll-tools-text-domain'));
    }
    if (!class_exists('ZipArchive')) {
        return new WP_Error('ll_tools_offline_app_zip_unavailable', __('PHP ZipArchive is required to create the offline app export zip.', 'll-tools-text-domain'));
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('ll_tools_offline_app_zip_open_failed', __('Could not create the offline app export zip.', 'll-tools-text-domain'));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        $absolute_path = wp_normalize_path($file->getPathname());
        $relative_path = ltrim(substr($absolute_path, strlen($source_dir)), '/');
        if ($relative_path === '') {
            continue;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($relative_path);
            continue;
        }

        if (!$zip->addFile($absolute_path, $relative_path)) {
            $zip->close();
            @unlink($zip_path);
            return new WP_Error('ll_tools_offline_app_zip_add_failed', __('Could not add files to the offline app export zip.', 'll-tools-text-domain'));
        }
    }

    if (!$zip->close()) {
        @unlink($zip_path);
        return new WP_Error('ll_tools_offline_app_zip_close_failed', __('Could not finalize the offline app export zip.', 'll-tools-text-domain'));
    }

    return true;
}

function ll_tools_offline_app_normalize_id_list(array $values): array {
    $normalized = [];
    $seen = [];
    foreach ($values as $value) {
        $normalized_value = (int) $value;
        if ($normalized_value <= 0 || isset($seen[$normalized_value])) {
            continue;
        }
        $normalized[] = $normalized_value;
        $seen[$normalized_value] = true;
    }
    return $normalized;
}

function ll_tools_offline_app_sanitize_app_id_suffix(string $raw_suffix, WP_Term $wordset): string {
    $raw_suffix = strtolower(trim($raw_suffix));
    if ($raw_suffix === '') {
        $raw_suffix = (string) $wordset->slug;
    }

    $segments = preg_split('/[.\s]+/', $raw_suffix);
    $segments = is_array($segments) ? $segments : [];
    $sanitized = [];
    foreach ($segments as $segment) {
        $segment = strtolower((string) $segment);
        $segment = preg_replace('/[^a-z0-9_]+/', '', $segment);
        if ($segment === '') {
            continue;
        }
        if (!preg_match('/^[a-z_]/', $segment)) {
            $segment = 'app' . $segment;
        }
        $sanitized[] = $segment;
    }

    if (empty($sanitized)) {
        $sanitized = ['offline', 'quiz'];
    }

    return implode('.', $sanitized);
}

function ll_tools_get_plugin_version_string(): string {
    $data = get_file_data(LL_TOOLS_MAIN_FILE, [
        'Version' => 'Version',
    ]);
    $version = isset($data['Version']) ? trim((string) $data['Version']) : '';
    return $version !== '' ? $version : '1.0.0';
}

function ll_tools_offline_app_get_image_extension(string $filename, string $mime_type = ''): string {
    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^a-z0-9]+/', '', $extension);
    if (is_string($extension) && $extension !== '') {
        return $extension;
    }

    $mime_type = strtolower(trim($mime_type));
    $extension_map = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        'image/svg+xml' => 'svg',
    ];

    return $extension_map[$mime_type] ?? 'png';
}

function ll_tools_offline_app_get_gender_runtime_config(int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    $enabled = ($wordset_id > 0 && function_exists('ll_tools_wordset_has_grammatical_gender'))
        ? ll_tools_wordset_has_grammatical_gender($wordset_id)
        : false;
    $options = ($enabled && function_exists('ll_tools_wordset_get_gender_options'))
        ? ll_tools_wordset_get_gender_options($wordset_id)
        : [];
    $visual_config = ($enabled && function_exists('ll_tools_wordset_get_gender_visual_config'))
        ? ll_tools_wordset_get_gender_visual_config($wordset_id)
        : [];

    return [
        'enabled'       => (bool) $enabled,
        'options'       => array_values(array_filter(array_map('strval', (array) $options), static function (string $option): bool {
            return trim($option) !== '';
        })),
        'visual_config' => is_array($visual_config) ? $visual_config : [],
        'min_count'     => (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ),
    ];
}

function ll_tools_offline_app_filter_words_to_wordset(array $words, int $wordset_id): array {
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return array_values($words);
    }

    return array_values(array_filter($words, static function ($word) use ($wordset_id): bool {
        if (!is_array($word)) {
            return false;
        }
        $wordset_ids = array_values(array_filter(array_map('intval', (array) ($word['wordset_ids'] ?? [])), static function (int $id): bool {
            return $id > 0;
        }));
        return in_array($wordset_id, $wordset_ids, true);
    }));
}

function ll_tools_offline_app_build_categories(int $wordset_id, array $category_ids, bool $use_translations): array {
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    $wordset_id = (int) $wordset_id;
    if ($wordset_id <= 0) {
        return [];
    }

    if (!empty($category_ids)) {
        $all_terms = get_terms([
            'taxonomy'   => 'word-category',
            'hide_empty' => false,
            'include'    => array_map('intval', $category_ids),
        ]);
        if (is_wp_error($all_terms)) {
            $all_terms = [];
        }
    } else {
        $word_ids = get_posts([
            'post_type'      => 'words',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [[
                'taxonomy' => 'wordset',
                'field'    => 'term_id',
                'terms'    => [$wordset_id],
            ]],
        ]);
        $word_ids = array_values(array_unique(array_filter(array_map('intval', (array) $word_ids), static function (int $id): bool {
            return $id > 0;
        })));

        $category_term_ids = !empty($word_ids)
            ? wp_get_object_terms($word_ids, 'word-category', ['fields' => 'ids'])
            : [];
        if (is_wp_error($category_term_ids)) {
            $category_term_ids = [];
        }
        $category_term_ids = array_values(array_unique(array_filter(array_map('intval', (array) $category_term_ids), static function (int $id): bool {
            return $id > 0;
        })));

        $all_terms = !empty($category_term_ids)
            ? get_terms([
                'taxonomy'   => 'word-category',
                'hide_empty' => false,
                'include'    => $category_term_ids,
            ])
            : [];
        if (is_wp_error($all_terms)) {
            $all_terms = [];
        }
    }

    if (function_exists('ll_tools_filter_category_terms_for_user')) {
        $all_terms = ll_tools_filter_category_terms_for_user((array) $all_terms);
    }

    if (empty($all_terms)) {
        return [];
    }

    $gender_runtime = ll_tools_offline_app_get_gender_runtime_config($wordset_id);
    $gender_enabled = !empty($gender_runtime['enabled']);
    $gender_options = array_values((array) ($gender_runtime['options'] ?? []));
    $gender_lookup = [];
    foreach ($gender_options as $option) {
        $normalized = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
            ? ll_tools_wordset_normalize_gender_value_for_options((string) $option, $gender_options)
            : trim((string) $option);
        $key = strtolower(trim((string) $normalized));
        if ($key !== '') {
            $gender_lookup[$key] = true;
        }
    }

    $categories = [];
    foreach ($all_terms as $term) {
        if (!($term instanceof WP_Term)) {
            continue;
        }
        if ((string) ($term->slug ?? '') === 'uncategorized') {
            continue;
        }

        $config = function_exists('ll_tools_resolve_effective_category_quiz_config')
            ? ll_tools_resolve_effective_category_quiz_config($term, $min_word_count, [$wordset_id])
            : ll_tools_get_category_quiz_config($term);
        $option_type = (string) ($config['option_type'] ?? 'image');
        $words_in_mode = ll_tools_offline_app_filter_words_to_wordset(
            ll_get_words_by_category($term, $option_type, [], $config),
            $wordset_id
        );
        $word_count = count($words_in_mode);
        if ($word_count < $min_word_count) {
            continue;
        }

        $prompt_type = isset($config['prompt_type']) ? (string) $config['prompt_type'] : 'audio';
        $requires_audio = function_exists('ll_tools_quiz_requires_audio')
            ? ll_tools_quiz_requires_audio(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
            : ($prompt_type === 'audio' || in_array($option_type, ['audio', 'text_audio'], true));
        $requires_image = function_exists('ll_tools_quiz_requires_image')
            ? ll_tools_quiz_requires_image(['prompt_type' => $prompt_type, 'option_type' => $option_type], $option_type)
            : (($prompt_type === 'image') || ($option_type === 'image'));

        $translation = $use_translations
            ? (get_term_meta($term->term_id, 'term_translation', true) ?: $term->name)
            : $term->name;
        $aspect_bucket = function_exists('ll_tools_get_category_aspect_bucket_key')
            ? (string) ll_tools_get_category_aspect_bucket_key((int) $term->term_id)
            : 'no-image';
        if ($aspect_bucket === '') {
            $aspect_bucket = 'no-image';
        }

        $gender_word_count = 0;
        if ($gender_enabled && !empty($words_in_mode)) {
            foreach ($words_in_mode as $word) {
                if (!is_array($word)) {
                    continue;
                }
                $pos = $word['part_of_speech'] ?? [];
                $pos = is_array($pos) ? $pos : [$pos];
                $pos = array_map('strtolower', array_map('strval', $pos));
                if (!in_array('noun', $pos, true)) {
                    continue;
                }
                $gender_raw = (string) ($word['grammatical_gender'] ?? '');
                $gender_label = function_exists('ll_tools_wordset_normalize_gender_value_for_options')
                    ? ll_tools_wordset_normalize_gender_value_for_options($gender_raw, $gender_options)
                    : trim($gender_raw);
                $gender_key = strtolower(trim((string) $gender_label));
                if ($gender_key === '' || (empty($gender_lookup) || !isset($gender_lookup[$gender_key]))) {
                    continue;
                }
                if (($requires_image && empty($word['has_image'])) || ($requires_audio && empty($word['has_audio']))) {
                    continue;
                }
                $gender_word_count++;
            }
        }

        $categories[] = [
            'id'                 => (int) $term->term_id,
            'slug'               => (string) $term->slug,
            'name'               => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
            'translation'        => html_entity_decode((string) $translation, ENT_QUOTES, 'UTF-8'),
            'mode'               => $option_type,
            'option_type'        => $option_type,
            'prompt_type'        => $prompt_type,
            'learning_prompt_type' => (string) ($config['learning_prompt_type'] ?? ''),
            'learning_option_type' => (string) ($config['learning_option_type'] ?? ''),
            'requires_images'    => $requires_image,
            'learning_supported' => !array_key_exists('learning_supported', $config) || !empty($config['learning_supported']),
            'self_check_supported' => !array_key_exists('self_check_supported', $config) || !empty($config['self_check_supported']),
            'sign_language_mode' => !empty($config['sign_language_mode']),
            'use_titles'         => !empty($config['use_titles']),
            'word_count'         => $word_count,
            'gender_word_count'  => $gender_word_count,
            'gender_supported'   => ($gender_enabled && $gender_word_count >= $min_word_count),
            'aspect_bucket'      => $aspect_bucket,
        ];
    }

    if (count($categories) > 1 && function_exists('ll_tools_wordset_sort_category_ids')) {
        $categories_by_id = [];
        $category_ids_to_sort = [];
        foreach ($categories as $category) {
            $category_id = (int) ($category['id'] ?? 0);
            if ($category_id <= 0) {
                continue;
            }
            $categories_by_id[$category_id] = $category;
            $category_ids_to_sort[] = $category_id;
        }

        $ordered_ids = ll_tools_wordset_sort_category_ids($category_ids_to_sort, $wordset_id, [
            'categories_payload' => $categories,
        ]);
        $ordered_categories = [];
        foreach ($ordered_ids as $category_id) {
            if (isset($categories_by_id[$category_id])) {
                $ordered_categories[] = $categories_by_id[$category_id];
            }
        }
        if (!empty($ordered_categories)) {
            $categories = $ordered_categories;
        }
    }

    return array_values($categories);
}
