<?php
/**
 * WP-CLI eval-file script for the offline sync error E2E fixture.
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "This script must run inside WordPress, usually through WP-CLI eval-file.\n");
    exit(1);
}

const LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY = 'll-tools-e2e-offline-sync-error';
const LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY = '_ll_tools_e2e_fixture';
const LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_VERSION_META_KEY = '_ll_tools_e2e_fixture_version';
const LL_TOOLS_OFFLINE_SYNC_ERROR_BUNDLE_PATHS_OPTION = 'll_tools_e2e_offline_sync_error_bundle_paths';

function ll_tools_offline_sync_error_fixture_fail(string $message): void {
    if (class_exists('WP_CLI')) {
        WP_CLI::error($message);
    }

    throw new RuntimeException($message);
}

function ll_tools_offline_sync_error_fixture_marker_matches($object_id, string $kind): bool {
    if ($kind === 'term') {
        return (string) get_term_meta((int) $object_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY, true) === LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY;
    }
    if ($kind === 'user') {
        return (string) get_user_meta((int) $object_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY, true) === LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY;
    }

    return (string) get_post_meta((int) $object_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY, true) === LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY;
}

function ll_tools_offline_sync_error_fixture_tag_post(int $post_id, string $fixture_version): void {
    update_post_meta($post_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY);
    update_post_meta($post_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_offline_sync_error_fixture_tag_term(int $term_id, string $fixture_version): void {
    update_term_meta($term_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY);
    update_term_meta($term_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_VERSION_META_KEY, $fixture_version);
}

function ll_tools_offline_sync_error_fixture_assert_post_slug_available(string $slug, string $post_type): void {
    $existing = get_page_by_path($slug, OBJECT, $post_type);
    if ($existing instanceof WP_Post && !ll_tools_offline_sync_error_fixture_marker_matches((int) $existing->ID, 'post')) {
        ll_tools_offline_sync_error_fixture_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s post with slug %s.',
            $post_type,
            $slug
        ));
    }
}

function ll_tools_offline_sync_error_fixture_assert_term_slug_available(string $slug, string $taxonomy): void {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term && !is_wp_error($existing) && !ll_tools_offline_sync_error_fixture_marker_matches((int) $existing->term_id, 'term')) {
        ll_tools_offline_sync_error_fixture_fail(sprintf(
            'Refusing to overwrite existing non-fixture %s term with slug %s.',
            $taxonomy,
            $slug
        ));
    }
}

function ll_tools_offline_sync_error_fixture_delete_posts(): int {
    $deleted = 0;
    $ids = get_posts([
        'post_type' => ['words'],
        'post_status' => 'any',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [[
            'key' => LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY,
            'value' => LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY,
        ]],
    ]);

    foreach ((array) $ids as $post_id) {
        $post_id = (int) $post_id;
        if ($post_id > 0 && wp_delete_post($post_id, true)) {
            $deleted++;
        }
    }

    return $deleted;
}

function ll_tools_offline_sync_error_fixture_delete_terms(): int {
    $deleted = 0;
    foreach (['word-category', 'wordset'] as $taxonomy) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY,
                'value' => LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY,
            ]],
        ]);

        if (is_wp_error($terms)) {
            continue;
        }

        foreach ((array) $terms as $term_id) {
            $term_id = (int) $term_id;
            if ($term_id > 0 && !is_wp_error(wp_delete_term($term_id, $taxonomy))) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

function ll_tools_offline_sync_error_fixture_rrmdir(string $path, string $allowed_base): void {
    $path = wp_normalize_path($path);
    $allowed_base = trailingslashit(wp_normalize_path($allowed_base));
    if ($path === '' || strpos(trailingslashit($path), $allowed_base) !== 0 || !is_dir($path)) {
        return;
    }

    if (function_exists('ll_tools_rrmdir')) {
        ll_tools_rrmdir($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }
    @rmdir($path);
}

function ll_tools_offline_sync_error_fixture_delete_prior_bundles(): void {
    $upload_dir = wp_upload_dir();
    $base_dir = wp_normalize_path((string) ($upload_dir['basedir'] ?? ''));
    if ($base_dir === '') {
        return;
    }

    $paths = get_option(LL_TOOLS_OFFLINE_SYNC_ERROR_BUNDLE_PATHS_OPTION, []);
    foreach ((array) $paths as $entry) {
        $staging_dir = wp_normalize_path((string) ($entry['staging_dir'] ?? ''));
        $zip_path = wp_normalize_path((string) ($entry['zip_path'] ?? ''));
        if ($staging_dir !== '') {
            ll_tools_offline_sync_error_fixture_rrmdir($staging_dir, $base_dir);
        }
        if ($zip_path !== '' && strpos($zip_path, trailingslashit($base_dir)) === 0 && is_file($zip_path)) {
            @unlink($zip_path);
        }
    }
    delete_option(LL_TOOLS_OFFLINE_SYNC_ERROR_BUNDLE_PATHS_OPTION);
}

function ll_tools_offline_sync_error_fixture_insert_term(string $taxonomy, string $name, string $slug, string $fixture_version): int {
    $insert = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($insert)) {
        ll_tools_offline_sync_error_fixture_fail(sprintf('Unable to create %s term %s: %s', $taxonomy, $slug, $insert->get_error_message()));
    }

    $term_id = (int) ($insert['term_id'] ?? 0);
    if ($term_id <= 0) {
        ll_tools_offline_sync_error_fixture_fail(sprintf('Unable to create %s term %s.', $taxonomy, $slug));
    }

    ll_tools_offline_sync_error_fixture_tag_term($term_id, $fixture_version);
    return $term_id;
}

function ll_tools_offline_sync_error_fixture_create_word(array $word, int $wordset_id, int $category_id, string $fixture_version): int {
    $title = sanitize_text_field((string) ($word['title'] ?? ''));
    $slug = sanitize_title((string) ($word['slug'] ?? ''));
    $translation = sanitize_text_field((string) ($word['translation'] ?? ''));
    if ($title === '' || $slug === '' || $translation === '') {
        ll_tools_offline_sync_error_fixture_fail('Fixture words require title, slug, and translation.');
    }

    $post_id = wp_insert_post([
        'post_type' => 'words',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => '',
    ], true);
    if (is_wp_error($post_id) || (int) $post_id <= 0) {
        $message = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown error';
        ll_tools_offline_sync_error_fixture_fail(sprintf('Unable to create fixture word %s: %s', $slug, $message));
    }

    $post_id = (int) $post_id;
    wp_set_object_terms($post_id, [$wordset_id], 'wordset', false);
    wp_set_object_terms($post_id, [$category_id], 'word-category', false);
    update_post_meta($post_id, 'word_translation', $translation);
    update_post_meta($post_id, 'word_note', 'Offline sync error Playwright fixture.');
    ll_tools_offline_sync_error_fixture_tag_post($post_id, $fixture_version);

    return $post_id;
}

function ll_tools_offline_sync_error_fixture_ensure_user(string $fixture_version): array {
    $login = 'll-e2e-offline-sync-learner';
    $password = 'LL-E2E-offline-sync-2026!';
    $email = 'll-e2e-offline-sync-learner@example.invalid';
    $existing = get_user_by('login', $login);

    if ($existing instanceof WP_User) {
        if (!ll_tools_offline_sync_error_fixture_marker_matches((int) $existing->ID, 'user')) {
            ll_tools_offline_sync_error_fixture_fail('Refusing to overwrite an existing non-fixture offline sync learner user.');
        }
        $user_id = (int) $existing->ID;
        wp_set_password($password, $user_id);
    } else {
        $created_user_id = wp_create_user($login, $password, $email);
        if (is_wp_error($created_user_id)) {
            ll_tools_offline_sync_error_fixture_fail('Unable to create offline sync learner user: ' . $created_user_id->get_error_message());
        }
        $user_id = (int) $created_user_id;
        if ($user_id <= 0) {
            ll_tools_offline_sync_error_fixture_fail('Unable to create offline sync learner user.');
        }
    }

    wp_update_user([
        'ID' => $user_id,
        'display_name' => 'Offline Sync Learner',
        'nickname' => 'Offline Sync Learner',
        'role' => 'subscriber',
    ]);
    $user = get_user_by('id', $user_id);
    if ($user instanceof WP_User) {
        $user->add_cap('view_ll_tools');
    }
    update_user_meta($user_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_META_KEY, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_KEY);
    update_user_meta($user_id, LL_TOOLS_OFFLINE_SYNC_ERROR_FIXTURE_VERSION_META_KEY, $fixture_version);

    return [
        'id' => $user_id,
        'login' => $login,
        'password' => $password,
        'displayName' => 'Offline Sync Learner',
    ];
}

function ll_tools_offline_sync_error_fixture_set_current_admin(): void {
    $admins = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]);
    $admin_id = (int) ((array) $admins)[0];
    if ($admin_id <= 0) {
        ll_tools_offline_sync_error_fixture_fail('An administrator user is required to build the offline app fixture.');
    }

    wp_set_current_user($admin_id);
}

function ll_tools_offline_sync_error_fixture_ensure_mu_plugin(): void {
    $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
    if (!wp_mkdir_p($mu_dir)) {
        ll_tools_offline_sync_error_fixture_fail('Unable to create mu-plugins directory for the offline sync error fixture.');
    }

    $plugin_path = trailingslashit($mu_dir) . 'll-tools-e2e-offline-sync-error.php';
    $plugin_source = <<<'PHP'
<?php
/**
 * LL Tools E2E offline sync error fixture.
 */
if (!defined('ABSPATH')) {
    return;
}

function ll_tools_e2e_offline_sync_error_fixture(): void {
    $mode = isset($_POST['ll_e2e_offline_sync_failure'])
        ? sanitize_key((string) wp_unslash($_POST['ll_e2e_offline_sync_failure']))
        : '';
    if ($mode === '') {
        return;
    }

    if ($mode === 'conflict') {
        wp_send_json_error([
            'code' => 'll_tools_e2e_offline_sync_conflict',
            'message' => 'Offline sync conflict from the E2E fixture. Retry is available.',
        ], 409);
    }

    if ($mode === 'server_error') {
        wp_send_json_error([
            'code' => 'll_tools_e2e_offline_sync_server_error',
            'message' => 'Offline sync server error from the E2E fixture. Retry is available.',
        ], 500);
    }
}
add_action('wp_ajax_nopriv_ll_tools_offline_app_sync', 'll_tools_e2e_offline_sync_error_fixture', 0);
add_action('wp_ajax_ll_tools_offline_app_sync', 'll_tools_e2e_offline_sync_error_fixture', 0);
PHP;

    if (file_put_contents($plugin_path, $plugin_source) === false) {
        ll_tools_offline_sync_error_fixture_fail('Unable to write offline sync error mu-plugin fixture.');
    }
}

function ll_tools_offline_sync_error_fixture_rewrite_sync_url(string $staging_dir): void {
    $data_path = trailingslashit($staging_dir) . 'www/data/offline-data.js';
    if (!is_file($data_path)) {
        ll_tools_offline_sync_error_fixture_fail('Offline app data file was not staged.');
    }

    $source = (string) file_get_contents($data_path);
    $source = preg_replace(
        '/"ajaxUrl":"[^"]*\/wp-admin\/admin-ajax\.php"/',
        '"ajaxUrl":"/wp-admin/admin-ajax.php"',
        $source
    );
    if (!is_string($source) || $source === '') {
        ll_tools_offline_sync_error_fixture_fail('Unable to rewrite offline app sync URL.');
    }
    file_put_contents($data_path, $source);
}

function ll_tools_offline_sync_error_fixture_run(): array {
    $fixture_version = '2026-06-12.1';
    $wordset_slug = 'll-e2e-offline-sync-wordset';
    $category_slug = 'll-e2e-offline-sync-category';
    $words = [
        ['title' => 'atlas', 'slug' => 'll-e2e-offline-sync-atlas', 'translation' => 'map book'],
        ['title' => 'brisk', 'slug' => 'll-e2e-offline-sync-brisk', 'translation' => 'quick'],
        ['title' => 'copper', 'slug' => 'll-e2e-offline-sync-copper', 'translation' => 'metal'],
        ['title' => 'dawn', 'slug' => 'll-e2e-offline-sync-dawn', 'translation' => 'sunrise'],
        ['title' => 'ember', 'slug' => 'll-e2e-offline-sync-ember', 'translation' => 'glowing coal'],
    ];

    ll_tools_offline_sync_error_fixture_ensure_mu_plugin();
    $learner = ll_tools_offline_sync_error_fixture_ensure_user($fixture_version);
    ll_tools_offline_sync_error_fixture_assert_term_slug_available($wordset_slug, 'wordset');
    ll_tools_offline_sync_error_fixture_assert_term_slug_available($category_slug, 'word-category');
    foreach ($words as $word) {
        ll_tools_offline_sync_error_fixture_assert_post_slug_available((string) $word['slug'], 'words');
    }

    ll_tools_offline_sync_error_fixture_delete_prior_bundles();
    $deleted_posts = ll_tools_offline_sync_error_fixture_delete_posts();
    $deleted_terms = ll_tools_offline_sync_error_fixture_delete_terms();

    $wordset_id = ll_tools_offline_sync_error_fixture_insert_term('wordset', 'LL E2E Offline Sync', $wordset_slug, $fixture_version);
    update_term_meta($wordset_id, 'll_language', 'English');

    $category_id = ll_tools_offline_sync_error_fixture_insert_term('word-category', 'Offline Sync Retry', $category_slug, $fixture_version);
    update_term_meta($category_id, 'll_quiz_prompt_type', 'text_translation');
    update_term_meta($category_id, 'll_quiz_option_type', 'text_title');
    update_term_meta($category_id, 'll_category_visibility', 'public');
    update_term_meta($category_id, 'll_category_enabled_games', []);
    update_term_meta($category_id, defined('LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY') ? LL_TOOLS_CATEGORY_ACCESS_USER_IDS_META_KEY : 'll_category_access_user_ids', [(int) $learner['id']]);
    if (function_exists('ll_tools_set_category_wordset_owner')) {
        ll_tools_set_category_wordset_owner($category_id, $wordset_id, $category_id);
    }

    $word_ids = [];
    foreach ($words as $word) {
        $word_ids[] = ll_tools_offline_sync_error_fixture_create_word($word, $wordset_id, $category_id, $fixture_version);
    }

    if (function_exists('ll_tools_bump_category_cache_version')) {
        ll_tools_bump_category_cache_version([$category_id]);
    }
    clean_term_cache([$wordset_id], 'wordset');
    clean_term_cache([$category_id], 'word-category');
    foreach ($word_ids as $word_id) {
        clean_post_cache((int) $word_id);
    }

    ll_tools_offline_sync_error_fixture_set_current_admin();
    if (!function_exists('ll_tools_build_offline_app_bundle')) {
        ll_tools_offline_sync_error_fixture_fail('Offline app bundle builder is unavailable.');
    }

    $bundle = ll_tools_build_offline_app_bundle([
        'wordset_id' => $wordset_id,
        'category_ids' => [$category_id],
        'app_name' => 'LL E2E Offline Sync',
        'version_name' => 'e2e-' . $fixture_version,
        'version_code' => 1,
        'app_id_suffix' => 'e2e.offline.sync',
        'skip_zip' => true,
    ]);
    if (is_wp_error($bundle)) {
        ll_tools_offline_sync_error_fixture_fail($bundle->get_error_message());
    }

    $staging_dir = wp_normalize_path((string) ($bundle['staging_dir'] ?? ''));
    $zip_path = wp_normalize_path((string) ($bundle['zip_path'] ?? ''));
    if ($staging_dir === '' || !is_file(trailingslashit($staging_dir) . 'www/index.html')) {
        ll_tools_offline_sync_error_fixture_fail('Offline app bundle index was not staged.');
    }
    ll_tools_offline_sync_error_fixture_rewrite_sync_url($staging_dir);
    update_option(LL_TOOLS_OFFLINE_SYNC_ERROR_BUNDLE_PATHS_OPTION, [[
        'staging_dir' => $staging_dir,
        'zip_path' => $zip_path,
    ]], false);

    $upload_dir = wp_upload_dir();
    $uploads_path = '/' . trim((string) wp_parse_url((string) ($upload_dir['baseurl'] ?? ''), PHP_URL_PATH), '/');
    $offline_path = trailingslashit($uploads_path) . rawurlencode(basename($staging_dir)) . '/www/index.html';

    return [
        'ok' => true,
        'fixtureVersion' => $fixture_version,
        'deletedPosts' => $deleted_posts,
        'deletedTerms' => $deleted_terms,
        'offlinePath' => $offline_path,
        'wordsetId' => $wordset_id,
        'wordsetSlug' => $wordset_slug,
        'categoryId' => $category_id,
        'categoryName' => 'Offline Sync Retry',
        'categorySlug' => $category_slug,
        'wordIds' => array_values(array_map('intval', $word_ids)),
        'learner' => $learner,
    ];
}

$summary = ll_tools_offline_sync_error_fixture_run();
echo wp_json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
