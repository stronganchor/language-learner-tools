<?php
// /includes/pages/quiz-pages.php
if (!defined('WPINC')) { die; }

/**
 * This single module handles:
 * - Building quiz page HTML (via a template)
 * - Creating/updating/removing pages per word-category
 * - Daily + on-change resync
 * - Admin UI (manual cleanup button / forced cleanup)
 * - Targeted asset enqueue for quiz pages
 */

if (!defined('LL_TOOLS_QUIZ_PAGE_POST_TYPE')) {
    define('LL_TOOLS_QUIZ_PAGE_POST_TYPE', 'll_quiz_page');
}
if (!defined('LL_TOOLS_QUIZ_PAGE_CATEGORY_META')) {
    define('LL_TOOLS_QUIZ_PAGE_CATEGORY_META', '_ll_tools_word_category_id');
}
if (!defined('LL_TOOLS_QUIZ_PAGE_STORAGE_OPTION')) {
    define('LL_TOOLS_QUIZ_PAGE_STORAGE_OPTION', 'll_tools_quiz_page_storage_schema');
}
if (!defined('LL_TOOLS_QUIZ_PAGE_REWRITE_OPTION')) {
    define('LL_TOOLS_QUIZ_PAGE_REWRITE_OPTION', 'll_tools_quiz_page_rewrite_schema');
}
if (!defined('LL_TOOLS_QUIZ_PAGE_SCHEMA_VERSION')) {
    define('LL_TOOLS_QUIZ_PAGE_SCHEMA_VERSION', '2');
}

/**
 * Generated quiz pages live in a dedicated CPT so they do not clutter normal Pages.
 * Legacy Page records with LL_TOOLS_QUIZ_PAGE_CATEGORY_META are still read during migration.
 */
function ll_tools_register_quiz_page_post_type(): void {
    $labels = [
        'name'                  => esc_html__('Quiz Pages', 'll-tools-text-domain'),
        'singular_name'         => esc_html__('Quiz Page', 'll-tools-text-domain'),
        'menu_name'             => esc_html__('Quiz Pages', 'll-tools-text-domain'),
        'name_admin_bar'        => esc_html__('Quiz Page', 'll-tools-text-domain'),
        'edit_item'             => esc_html__('View Generated Quiz Page', 'll-tools-text-domain'),
        'view_item'             => esc_html__('View Quiz Page', 'll-tools-text-domain'),
        'search_items'          => esc_html__('Search Quiz Pages', 'll-tools-text-domain'),
        'not_found'             => esc_html__('No quiz pages found', 'll-tools-text-domain'),
        'not_found_in_trash'    => esc_html__('No quiz pages found in Trash', 'll-tools-text-domain'),
        'all_items'             => esc_html__('Quiz Pages', 'll-tools-text-domain'),
        'filter_items_list'     => esc_html__('Filter quiz pages list', 'll-tools-text-domain'),
        'items_list_navigation' => esc_html__('Quiz pages list navigation', 'll-tools-text-domain'),
        'items_list'            => esc_html__('Quiz pages list', 'll-tools-text-domain'),
    ];

    register_post_type(LL_TOOLS_QUIZ_PAGE_POST_TYPE, [
        'label'               => esc_html__('Quiz Pages', 'll-tools-text-domain'),
        'labels'              => $labels,
        'description'         => esc_html__('Program-managed public quiz pages generated from word categories.', 'll-tools-text-domain'),
        'public'              => true,
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_admin_bar'   => false,
        'show_in_nav_menus'   => false,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'show_in_rest'        => false,
        'rewrite'             => [
            'slug'       => sanitize_title(apply_filters('ll_tools_quiz_parent_slug', 'quiz')),
            'with_front' => false,
        ],
        'query_var'           => 'll_quiz_page',
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'capabilities'        => [
            'create_posts' => 'do_not_allow',
        ],
        'supports'            => ['title'],
    ]);
}
add_action('init', 'll_tools_register_quiz_page_post_type', 0);

/**
 * @return string[]
 */
function ll_tools_get_quiz_page_post_types(bool $include_legacy_pages = true): array {
    $post_types = [LL_TOOLS_QUIZ_PAGE_POST_TYPE];
    if ($include_legacy_pages) {
        $post_types[] = 'page';
    }

    return array_values(array_unique(array_filter($post_types)));
}

/**
 * @param string|string[] $post_status
 * @return int[]
 */
function ll_tools_get_quiz_page_ids_for_category(int $category_id, $post_status = ['publish', 'draft', 'pending', 'private'], bool $include_legacy_pages = true): array {
    if ($category_id <= 0) {
        return [];
    }

    $ids = [];
    foreach (ll_tools_get_quiz_page_post_types($include_legacy_pages) as $post_type) {
        $matches = get_posts([
            'post_type'      => $post_type,
            'post_status'    => $post_status,
            'meta_key'       => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
            'meta_value'     => (string) $category_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        foreach ((array) $matches as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                $ids[] = $post_id;
            }
        }
    }

    return array_values(array_unique($ids));
}

function ll_tools_mark_quiz_page_rewrite_flush_needed(): void {
    set_transient('ll_tools_quiz_page_flush_rewrite', 1, 10 * MINUTE_IN_SECONDS);
}

function ll_tools_maybe_schedule_quiz_page_rewrite_flush(): void {
    $stored_version = (string) get_option(LL_TOOLS_QUIZ_PAGE_REWRITE_OPTION, '');
    if ($stored_version === LL_TOOLS_QUIZ_PAGE_SCHEMA_VERSION) {
        return;
    }

    ll_tools_mark_quiz_page_rewrite_flush_needed();
    update_option(LL_TOOLS_QUIZ_PAGE_REWRITE_OPTION, LL_TOOLS_QUIZ_PAGE_SCHEMA_VERSION, false);
}
add_action('init', 'll_tools_maybe_schedule_quiz_page_rewrite_flush', 5);

function ll_tools_maybe_flush_quiz_page_rewrite_rules(): void {
    if (!get_transient('ll_tools_quiz_page_flush_rewrite')) {
        return;
    }

    flush_rewrite_rules(false);
    delete_transient('ll_tools_quiz_page_flush_rewrite');
}
add_action('admin_init', 'll_tools_maybe_flush_quiz_page_rewrite_rules', 20);

function ll_tools_prepare_quiz_page_post_update(int $post_id, string $slug, string $status = 'publish'): array {
    $slug = sanitize_title($slug);
    if ($slug === '') {
        $slug = 'quiz-page';
    }

    $post = $post_id > 0 ? get_post($post_id) : null;
    $post_status = $post instanceof WP_Post ? (string) $post->post_status : $status;

    return [
        'post_name'   => wp_unique_post_slug($slug, $post_id, $post_status, LL_TOOLS_QUIZ_PAGE_POST_TYPE, 0),
        'post_parent' => 0,
        'post_type'   => LL_TOOLS_QUIZ_PAGE_POST_TYPE,
    ];
}

function ll_tools_migrate_legacy_quiz_pages_to_post_type(): int {
    $legacy_pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending', 'private', 'trash'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    $migrated = 0;
    foreach ((array) $legacy_pages as $page_id) {
        $page_id = (int) $page_id;
        if ($page_id <= 0) {
            continue;
        }

        $page = get_post($page_id);
        if (!($page instanceof WP_Post) || $page->post_type !== 'page') {
            continue;
        }

        $update = ll_tools_prepare_quiz_page_post_update($page_id, (string) $page->post_name, (string) $page->post_status);
        $update['ID'] = $page_id;

        $result = wp_update_post($update, true);
        if (!is_wp_error($result) && (int) $result > 0) {
            $migrated++;
        }
    }

    if ($migrated > 0) {
        ll_tools_mark_quiz_page_rewrite_flush_needed();
    }

    return $migrated;
}

function ll_tools_maybe_migrate_legacy_quiz_pages(): void {
    $stored_version = (string) get_option(LL_TOOLS_QUIZ_PAGE_STORAGE_OPTION, '');
    if ($stored_version === LL_TOOLS_QUIZ_PAGE_SCHEMA_VERSION) {
        return;
    }

    ll_tools_migrate_legacy_quiz_pages_to_post_type();
    update_option(LL_TOOLS_QUIZ_PAGE_STORAGE_OPTION, LL_TOOLS_QUIZ_PAGE_SCHEMA_VERSION, false);
}
add_action('admin_init', 'll_tools_maybe_migrate_legacy_quiz_pages', 5);

function ll_tools_hide_legacy_quiz_pages_from_pages_admin(WP_Query $query): void {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return;
    }

    $post_type = $query->get('post_type');
    if ($post_type === '') {
        $post_type = 'post';
    }
    if ($post_type !== 'page') {
        return;
    }

    $existing_meta_query = $query->get('meta_query');
    $meta_query = [
        'relation' => 'AND',
        [
            'key'     => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
            'compare' => 'NOT EXISTS',
        ],
    ];
    if (!empty($existing_meta_query)) {
        $meta_query[] = $existing_meta_query;
    }

    $query->set('meta_query', $meta_query);
}
add_action('pre_get_posts', 'll_tools_hide_legacy_quiz_pages_from_pages_admin');

function ll_tools_quiz_page_admin_columns(array $columns): array {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;
        if ($key === 'title') {
            $new_columns['ll_quiz_category'] = __('Word Category', 'll-tools-text-domain');
            $new_columns['ll_quiz_public_url'] = __('Public Page', 'll-tools-text-domain');
        }
    }

    return $new_columns;
}
add_filter('manage_' . LL_TOOLS_QUIZ_PAGE_POST_TYPE . '_posts_columns', 'll_tools_quiz_page_admin_columns');

function ll_tools_quiz_page_admin_column_content(string $column, int $post_id): void {
    if ($column === 'll_quiz_category') {
        $term_id = (int) get_post_meta($post_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true);
        $term = $term_id > 0 ? get_term($term_id, 'word-category') : null;
        if ($term instanceof WP_Term && !is_wp_error($term)) {
            $edit_link = get_edit_term_link($term_id, 'word-category', 'words');
            if (is_string($edit_link) && $edit_link !== '') {
                echo '<a href="' . esc_url($edit_link) . '">' . esc_html($term->name) . '</a>';
            } else {
                echo esc_html($term->name);
            }
            echo '<br><span class="description">' . esc_html(sprintf(__('Category ID: %d', 'll-tools-text-domain'), $term_id)) . '</span>';
            return;
        }

        echo esc_html__('Missing category', 'll-tools-text-domain');
        return;
    }

    if ($column === 'll_quiz_public_url') {
        $permalink = get_permalink($post_id);
        if (is_string($permalink) && $permalink !== '') {
            echo '<a class="button button-small" href="' . esc_url($permalink) . '" target="_blank" rel="noopener">' . esc_html__('View', 'll-tools-text-domain') . '</a>';
        }
    }
}
add_action('manage_' . LL_TOOLS_QUIZ_PAGE_POST_TYPE . '_posts_custom_column', 'll_tools_quiz_page_admin_column_content', 10, 2);

function ll_tools_quiz_page_row_actions(array $actions, WP_Post $post): array {
    if ($post->post_type !== LL_TOOLS_QUIZ_PAGE_POST_TYPE) {
        return $actions;
    }

    foreach (['edit', 'inline hide', 'trash', 'delete'] as $key) {
        unset($actions[$key]);
    }

    return $actions;
}
add_filter('post_row_actions', 'll_tools_quiz_page_row_actions', 10, 2);

function ll_tools_quiz_page_admin_edit_notice(): void {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen instanceof WP_Screen || $screen->base !== 'post' || $screen->post_type !== LL_TOOLS_QUIZ_PAGE_POST_TYPE) {
        return;
    }

    echo '<div class="notice notice-info"><p>' . esc_html__('Quiz pages are generated from word categories. Edit the category or its words, then run quiz page sync if the public page needs to be regenerated.', 'll-tools-text-domain') . '</p></div>';
}
add_action('admin_notices', 'll_tools_quiz_page_admin_edit_notice');

/** Helpers */
function ll_qp_is_quiz_page_context() : bool {
    if (!is_singular(ll_tools_get_quiz_page_post_types(true))) return false;
    $post = get_post();
    if (!$post instanceof WP_Post) {
        return false;
    }

    if (!in_array($post->post_type, ll_tools_get_quiz_page_post_types(true), true)) {
        return false;
    }

    return (bool) get_post_meta($post->ID, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true);
}

if (!defined('LL_TOOLS_QUIZ_PAGE_SYNC_EVENT')) {
    define('LL_TOOLS_QUIZ_PAGE_SYNC_EVENT', 'll_tools_quiz_page_sync_event');
}

if (!function_exists('ll_tools_get_category_maintenance_runtime')) {
    /**
     * @return array<string,mixed>
     */
    function &ll_tools_get_category_maintenance_runtime(): array {
        static $state = [
            'defer_depth' => 0,
            'queued_category_ids' => [],
            'synced_quiz_category_ids' => [],
            'synced_vocab_category_ids' => [],
        ];

        return $state;
    }
}

function ll_tools_reset_category_maintenance_runtime(): void {
    $state = &ll_tools_get_category_maintenance_runtime();
    $state = [
        'defer_depth' => 0,
        'queued_category_ids' => [],
        'synced_quiz_category_ids' => [],
        'synced_vocab_category_ids' => [],
    ];
}

function ll_tools_category_maintenance_is_deferred(): bool {
    $state = &ll_tools_get_category_maintenance_runtime();
    return ((int) ($state['defer_depth'] ?? 0)) > 0;
}

/**
 * @param array<int|string> $category_ids
 * @return int[]
 */
function ll_tools_normalize_category_maintenance_ids(array $category_ids): array {
    $normalized = array_values(array_unique(array_filter(array_map('intval', $category_ids), static function (int $id): bool {
        return $id > 0;
    })));

    sort($normalized, SORT_NUMERIC);
    return $normalized;
}

/**
 * @param int|array<int|string> $category_ids
 */
function ll_tools_queue_deferred_category_maintenance($category_ids): void {
    $ids = is_array($category_ids) ? $category_ids : [$category_ids];
    $normalized = ll_tools_normalize_category_maintenance_ids($ids);
    if (empty($normalized)) {
        return;
    }

    $state = &ll_tools_get_category_maintenance_runtime();
    if (!isset($state['queued_category_ids']) || !is_array($state['queued_category_ids'])) {
        $state['queued_category_ids'] = [];
    }

    foreach ($normalized as $category_id) {
        $state['queued_category_ids'][$category_id] = true;
    }
}

function ll_tools_begin_deferred_category_maintenance(string $scope = ''): void {
    $state = &ll_tools_get_category_maintenance_runtime();
    $state['defer_depth'] = max(0, (int) ($state['defer_depth'] ?? 0)) + 1;

    if ($scope !== '' && defined('WP_DEBUG') && WP_DEBUG) {
        error_log('LL Tools: Deferring category maintenance for scope "' . $scope . '".');
    }
}

function ll_tools_end_deferred_category_maintenance(bool $flush = true): void {
    $state = &ll_tools_get_category_maintenance_runtime();
    $depth = max(0, (int) ($state['defer_depth'] ?? 0));
    if ($depth <= 0) {
        return;
    }

    $state['defer_depth'] = $depth - 1;
    if ((int) $state['defer_depth'] > 0) {
        return;
    }

    if ($flush) {
        ll_tools_flush_deferred_category_maintenance();
        return;
    }

    $state['queued_category_ids'] = [];
}

/**
 * @return int[]
 */
function ll_tools_flush_deferred_category_maintenance(): array {
    $state = &ll_tools_get_category_maintenance_runtime();
    $queued = isset($state['queued_category_ids']) && is_array($state['queued_category_ids'])
        ? array_keys($state['queued_category_ids'])
        : [];
    $state['queued_category_ids'] = [];

    $category_ids = ll_tools_normalize_category_maintenance_ids($queued);
    if (empty($category_ids)) {
        return [];
    }

    foreach ($category_ids as $category_id) {
        ll_tools_handle_category_sync_immediate($category_id, true);
    }

    foreach ($category_ids as $category_id) {
        if (function_exists('ll_tools_sync_vocab_lessons_for_category_immediate')) {
            ll_tools_sync_vocab_lessons_for_category_immediate($category_id, true);
        } elseif (function_exists('ll_tools_sync_vocab_lessons_for_category')) {
            ll_tools_sync_vocab_lessons_for_category($category_id);
        }
    }

    return $category_ids;
}

function ll_tools_schedule_quiz_page_full_sync(int $delay = 30): void {
    $delay = max(0, $delay);
    if (wp_next_scheduled(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT)) {
        return;
    }

    wp_schedule_single_event(time() + $delay, LL_TOOLS_QUIZ_PAGE_SYNC_EVENT);
}

function ll_tools_quiz_page_enforce_category_access(): void {
    if (!ll_qp_is_quiz_page_context()) {
        return;
    }

    $post = get_post();
    $term_id = $post ? (int) get_post_meta($post->ID, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true) : 0;
    if ($term_id <= 0) {
        return;
    }

    if (!function_exists('ll_tools_user_can_view_category') || ll_tools_user_can_view_category($term_id)) {
        return;
    }

    global $wp_query;
    if ($wp_query instanceof WP_Query) {
        $wp_query->set_404();
    }
    status_header(404);
    nocache_headers();
}
add_action('template_redirect', 'll_tools_quiz_page_enforce_category_access', 1);

/**
 * Format a human-friendly quiz title for a category.
 *
 * @param int|WP_Term $term
 * @param bool        $include_site_name  Append the site name (useful for share previews on embed pages).
 * @return string
 */
function ll_tools_get_quiz_title_for_term($term, bool $include_site_name = false) : string {
    if (!($term instanceof WP_Term)) {
        $term = get_term($term, 'word-category');
    }
    if (!($term instanceof WP_Term) || is_wp_error($term)) return '';

    $display_name = function_exists('ll_tools_get_category_display_name')
        ? ll_tools_get_category_display_name($term)
        : $term->name;

    $base_title = sprintf(__('Quiz: %s', 'll-tools-text-domain'), $display_name);
    $site_name  = trim(wp_strip_all_tags((string) get_bloginfo('name')));

    $title = ($include_site_name && $site_name !== '')
        ? sprintf(__('%1$s | %2$s', 'll-tools-text-domain'), $base_title, $site_name)
        : $base_title;

    /**
     * Filter the generated quiz page/embed title.
     *
     * @param string  $title
     * @param string  $display_name
     * @param WP_Term $term
     * @param bool    $include_site_name
     * @param string  $site_name
     */
    return (string) apply_filters('ll_tools_quiz_page_title', $title, $display_name, $term, $include_site_name, $site_name);
}

/**
 * Resolve a wordset-owned category whose current slug was derived from an old
 * embed slug after the original source category is no longer resolvable.
 *
 * @param string       $embed_category Legacy embed route slug.
 * @param WP_Term|null $wordset_term   Optional explicit wordset term.
 * @param int          $min_word_count Minimum words required to treat the match as usable.
 * @return array{term:WP_Term,wordset_term:WP_Term,wordset:string}|null
 */
function ll_tools_resolve_legacy_embed_isolated_category(string $embed_category, ?WP_Term $wordset_term, int $min_word_count): ?array {
    $legacy_slug = sanitize_title(trim($embed_category));
    if ($legacy_slug === '' || !defined('LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY')) {
        return null;
    }

    $owner_query = [
        'key'     => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
        'compare' => 'EXISTS',
    ];
    if ($wordset_term instanceof WP_Term) {
        $owner_query = [
            'key'     => LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY,
            'value'   => (int) $wordset_term->term_id,
            'compare' => '=',
            'type'    => 'NUMERIC',
        ];
    }

    $terms = get_terms([
        'taxonomy'   => 'word-category',
        'hide_empty' => false,
        'fields'     => 'all',
        'orderby'    => 'term_id',
        'order'      => 'ASC',
        'meta_query' => [$owner_query],
    ]);

    if (is_wp_error($terms) || empty($terms)) {
        return null;
    }

    $prefixes = [
        $legacy_slug . '--',
        $legacy_slug . '-',
    ];
    $best_context = null;
    $best_owner_wordset_id = 0;
    $best_term_id = 0;

    foreach ($terms as $term) {
        if (!($term instanceof WP_Term) || $term->taxonomy !== 'word-category') {
            continue;
        }

        $term_slug = sanitize_title((string) $term->slug);
        $matches_legacy_slug = false;
        foreach ($prefixes as $prefix) {
            if (strpos($term_slug, $prefix) === 0 && strlen($term_slug) > strlen($prefix)) {
                $matches_legacy_slug = true;
                break;
            }
        }
        if (!$matches_legacy_slug) {
            continue;
        }

        $owner_wordset_id = function_exists('ll_tools_get_category_wordset_owner_id')
            ? (int) ll_tools_get_category_wordset_owner_id($term)
            : max(0, (int) get_term_meta((int) $term->term_id, LL_TOOLS_CATEGORY_WORDSET_OWNER_META_KEY, true));
        if ($owner_wordset_id <= 0) {
            continue;
        }
        if ($wordset_term instanceof WP_Term && $owner_wordset_id !== (int) $wordset_term->term_id) {
            continue;
        }

        $candidate_wordset = $wordset_term instanceof WP_Term
            ? $wordset_term
            : get_term($owner_wordset_id, 'wordset');
        if (!($candidate_wordset instanceof WP_Term) || is_wp_error($candidate_wordset)) {
            continue;
        }

        if (
            function_exists('ll_can_category_generate_quiz')
            && !ll_can_category_generate_quiz($term, $min_word_count, [$owner_wordset_id])
        ) {
            continue;
        }

        $context = [
            'term' => $term,
            'wordset_term' => $candidate_wordset,
            'wordset' => (string) $candidate_wordset->slug,
        ];

        if ($wordset_term instanceof WP_Term) {
            return $context;
        }

        $term_id = (int) $term->term_id;
        if (
            $best_context === null
            || $owner_wordset_id < $best_owner_wordset_id
            || ($owner_wordset_id === $best_owner_wordset_id && $term_id < $best_term_id)
        ) {
            $best_context = $context;
            $best_owner_wordset_id = $owner_wordset_id;
            $best_term_id = $term_id;
        }
    }

    return $best_context;
}

/**
 * Resolve the effective category + wordset context for an embed request.
 *
 * Legacy embed URLs may still point at the source category slug even after the
 * real quiz content moved into a wordset-isolated copy. When no explicit
 * wordset is provided, discover the default wordset first, then re-resolve the
 * category within that wordset so the flashcard widget receives the right slug.
 *
 * @param string $embed_category Embed route category slug or term reference.
 * @param string $wordset_spec   Optional wordset slug|id from the query string.
 * @return array{term:?WP_Term,wordset_term:?WP_Term,wordset:string}
 */
function ll_tools_resolve_embed_quiz_context(string $embed_category, string $wordset_spec = ''): array {
    $embed_category = trim($embed_category);
    $wordset_spec = trim($wordset_spec);
    $min_word_count = (int) apply_filters('ll_tools_quiz_min_words', LL_TOOLS_MIN_WORDS_PER_QUIZ);
    if ($min_word_count < 1) {
        $min_word_count = 1;
    }

    $resolve_wordset_term = static function (string $raw_wordset): ?WP_Term {
        if ($raw_wordset === '') {
            return null;
        }

        $wordset_field = ctype_digit($raw_wordset) ? 'term_id' : 'slug';
        $wordset_value = ctype_digit($raw_wordset) ? (int) $raw_wordset : sanitize_title($raw_wordset);
        $wordset_term = get_term_by($wordset_field, $wordset_value, 'wordset');

        return ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term))
            ? $wordset_term
            : null;
    };

    $resolve_category_term = static function (string $category_ref, ?WP_Term $wordset_term): ?WP_Term {
        if ($category_ref === '') {
            return null;
        }

        if (function_exists('ll_tools_resolve_word_category_term_for_wordsets')) {
            return ll_tools_resolve_word_category_term_for_wordsets(
                $category_ref,
                $wordset_term ? [(int) $wordset_term->term_id] : []
            );
        }

        $term = get_term_by('slug', $category_ref, 'word-category');
        return ($term instanceof WP_Term && !is_wp_error($term)) ? $term : null;
    };

    $wordset_term = $resolve_wordset_term($wordset_spec);
    if ($wordset_term instanceof WP_Term) {
        $wordset_spec = (string) $wordset_term->slug;
    }

    $term = $resolve_category_term($embed_category, $wordset_term);
    if (!($term instanceof WP_Term) && function_exists('ll_tools_resolve_legacy_embed_isolated_category')) {
        $legacy_context = ll_tools_resolve_legacy_embed_isolated_category($embed_category, $wordset_term, $min_word_count);
        if (is_array($legacy_context)) {
            return $legacy_context;
        }
    }

    if (
        $wordset_term === null
        && $term instanceof WP_Term
        && function_exists('ll_get_default_wordset_id_for_category')
    ) {
        $default_wordset_id = ll_get_default_wordset_id_for_category($term, $min_word_count);
        if ($default_wordset_id > 0) {
            $default_wordset_term = get_term($default_wordset_id, 'wordset');
            if ($default_wordset_term instanceof WP_Term && !is_wp_error($default_wordset_term)) {
                $wordset_term = $default_wordset_term;
                $wordset_spec = (string) $default_wordset_term->slug;

                $resolved_term = $resolve_category_term($embed_category, $wordset_term);
                if ($resolved_term instanceof WP_Term) {
                    $term = $resolved_term;
                }
            }
        }
    }

    if (
        function_exists('ll_tools_resolve_legacy_embed_isolated_category')
        && (
            !($term instanceof WP_Term)
            || $wordset_term === null
            || (
                $wordset_term instanceof WP_Term
                && function_exists('ll_can_category_generate_quiz')
                && !ll_can_category_generate_quiz($term, $min_word_count, [(int) $wordset_term->term_id])
            )
        )
    ) {
        $legacy_context = ll_tools_resolve_legacy_embed_isolated_category($embed_category, $wordset_term, $min_word_count);
        if (is_array($legacy_context)) {
            return $legacy_context;
        }
    }

    return [
        'term' => ($term instanceof WP_Term && !is_wp_error($term)) ? $term : null,
        'wordset_term' => ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term)) ? $wordset_term : null,
        'wordset' => $wordset_spec,
    ];
}

/**
 * Ensure the parent "/quiz" page exists and return its ID.
 * If a page with slug "quiz" is in the Trash, automatically restore it
 * (so we never end up with /quiz-2, /quiz-3 duplicates).
 */
function ll_tools_get_or_create_quiz_parent_page() {
    $parent_slug = sanitize_title( apply_filters( 'll_tools_quiz_parent_slug', 'quiz' ) );

    // 1) Any non-trashed match?
    $parent = get_page_by_path( $parent_slug );
    if ( $parent instanceof WP_Post && $parent->post_type === 'page' ) {
        return (int) $parent->ID;
    }

    // 2) Specifically look *inside* the trash.
    $trashed = get_posts( [
        'name'        => $parent_slug,
        'post_type'   => 'page',
        'post_status' => 'trash',
        'numberposts' => 1,
        'post_parent' => 0,
        'fields'      => 'all',
    ] );
    if ( $trashed ) {
        // Un-trash it and return the ID
        $trashed_id = (int) $trashed[0]->ID;
        wp_untrash_post( $trashed_id );
        wp_update_post( [
            'ID'         => $trashed_id,
            'post_name'  => $parent_slug, // ensure slug is correct
            'post_status'=> 'publish',
        ] );
        return $trashed_id;
    }

    // 3) Last resort – create a fresh one.
    $parent_id = wp_insert_post( [
        'post_title'   => ucfirst( $parent_slug ),
        'post_name'    => $parent_slug,
        'post_content' => '',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => 0,
    ], true );

    return is_wp_error( $parent_id ) ? 0 : (int) $parent_id;
}

/** Build HTML via template */
function ll_tools_build_quiz_page_content(WP_Term $term) : string {
    if (!function_exists('ll_tools_render_template')) {
        require_once LL_TOOLS_BASE_PATH . 'includes/template-loader.php';
    }

    $vh           = (int) apply_filters('ll_tools_quiz_iframe_vh', 95);
    $src          = home_url('/embed/' . $term->slug);

    if (function_exists('ll_get_default_wordset_id_for_category')) {
        $default_ws_id = ll_get_default_wordset_id_for_category($term, 5);
        if ($default_ws_id > 0) {
            $wordset_term = get_term($default_ws_id, 'wordset');
            if ($wordset_term && !is_wp_error($wordset_term)) {
                $src = add_query_arg('wordset', $wordset_term->slug, $src);
            }
        }
    }

    // Check for mode parameter in URL (support practice, learning, self-check, listening)
    if (isset($_GET['mode']) && in_array($_GET['mode'], ['practice', 'learning', 'self-check', 'listening'], true)) {
        $src = add_query_arg('mode', sanitize_text_field($_GET['mode']), $src);
    }

    $display_name = function_exists('ll_tools_get_category_display_name')
        ? ll_tools_get_category_display_name($term)
        : $term->name;

    ob_start();
    ll_tools_render_template('quiz-page-template.php', [
        'vh'           => $vh,
        'src'          => $src,
        'display_name' => $display_name,
        'slug'         => $term->slug,
    ]);
    return (string) ob_get_clean();
}

/** Create or update the page for a category */
function ll_tools_get_or_create_quiz_page_for_category($term_id) {
    $term = get_term($term_id, 'word-category');
    if (!$term || is_wp_error($term)) {
        return new WP_Error('invalid_term', __('Invalid category.', 'll-tools-text-domain'));
    }

    $child_slug = apply_filters('ll_tools_quiz_child_slug', sanitize_title($term->slug), $term);

    // Find active generated records, preferring the dedicated CPT over legacy Pages.
    $active_pages = ll_tools_get_quiz_page_ids_for_category(
        (int) $term->term_id,
        ['publish', 'draft', 'pending', 'private'],
        true
    );

    // Separately find trashed generated records.
    $trashed_pages = ll_tools_get_quiz_page_ids_for_category((int) $term->term_id, 'trash', true);

    $post_id = 0;
    $title   = ll_tools_get_quiz_title_for_term($term);
    $content = ll_tools_build_quiz_page_content($term);

    // If we have active (non-trashed) pages, use the first one
    if (!empty($active_pages)) {
        $post_id = (int) $active_pages[0];

        // Update it
        $existing_post = get_post($post_id);
        if ($existing_post) {
            $needs_post_type = ($existing_post->post_type !== LL_TOOLS_QUIZ_PAGE_POST_TYPE);
            $needs_parent = ((int) $existing_post->post_parent !== 0);
            $needs_slug   = ($existing_post->post_name !== $child_slug);

            $update = [
                'ID'           => $post_id,
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => LL_TOOLS_QUIZ_PAGE_POST_TYPE,
                'post_parent'  => 0,
            ];
            if ($needs_slug || $needs_parent || $needs_post_type) {
                $update = array_merge(
                    $update,
                    ll_tools_prepare_quiz_page_post_update($post_id, $child_slug, 'publish')
                );
            }
            wp_update_post($update);
            update_post_meta($post_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, (string) $term->term_id);

            if ($needs_post_type) {
                ll_tools_mark_quiz_page_rewrite_flush_needed();
            }
        }

        // Trash any duplicate active pages
        foreach (array_slice($active_pages, 1) as $duplicate_id) {
            wp_trash_post((int) $duplicate_id);
        }

        // Permanently delete any trashed duplicates
        foreach ($trashed_pages as $trash_id) {
            wp_delete_post((int) $trash_id, true);
        }

    } elseif (!empty($trashed_pages)) {
        // No active pages, but we have trashed ones - restore the first one
        $post_id = (int) $trashed_pages[0];
        wp_untrash_post($post_id);

        // Update it
        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ] + ll_tools_prepare_quiz_page_post_update($post_id, $child_slug, 'publish'));
        update_post_meta($post_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, (string) $term->term_id);
        ll_tools_mark_quiz_page_rewrite_flush_needed();

        // Permanently delete any other trashed duplicates
        foreach (array_slice($trashed_pages, 1) as $trash_id) {
            wp_delete_post((int) $trash_id, true);
        }

    } else {
        // No generated records exist - create a new dedicated quiz page record.
        $unique_slug = wp_unique_post_slug($child_slug, 0, 'publish', LL_TOOLS_QUIZ_PAGE_POST_TYPE, 0);
        $postarr = [
            'post_title'   => $title,
            'post_name'    => $unique_slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => LL_TOOLS_QUIZ_PAGE_POST_TYPE,
            'post_parent'  => 0,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];
        $post_id = wp_insert_post($postarr, true);
        if (is_wp_error($post_id)) return $post_id;
        update_post_meta($post_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, (string) $term->term_id);
    }

    return $post_id;
}

/** Create/update or remove a page when a category changes */
function ll_tools_handle_category_sync_immediate($term_id, bool $force = false) {
    $term = get_term($term_id);
    if (!$term || is_wp_error($term) || $term->taxonomy !== 'word-category') return;

    $category_id = (int) $term->term_id;
    $state = &ll_tools_get_category_maintenance_runtime();
    if (!$force && isset($state['synced_quiz_category_ids'][$category_id])) {
        return;
    }
    $state['synced_quiz_category_ids'][$category_id] = true;

    $ok = function_exists('ll_can_category_generate_quiz') ? ll_can_category_generate_quiz($term, LL_TOOLS_MIN_WORDS_PER_QUIZ) : true;
    if ($ok) {
        ll_tools_get_or_create_quiz_page_for_category($category_id);
    } else {
        $existing = ll_tools_get_quiz_page_ids_for_category($category_id, ['publish','draft','pending','private'], true);
        foreach ($existing as $post_id) {
            wp_trash_post((int) $post_id);
        }
    }
}

function ll_tools_handle_category_sync($term_id) {
    $category_id = (int) $term_id;
    if ($category_id <= 0) {
        return;
    }

    if (ll_tools_category_maintenance_is_deferred()) {
        ll_tools_queue_deferred_category_maintenance([$category_id]);
        return;
    }

    ll_tools_handle_category_sync_immediate($category_id);
}

function ll_tools_category_has_active_quiz_page_shell(int $category_id): bool {
    if ($category_id <= 0) {
        return false;
    }

    $existing = ll_tools_get_quiz_page_ids_for_category(
        $category_id,
        ['publish', 'draft', 'pending', 'private'],
        true
    );

    return !empty($existing);
}

function ll_tools_sync_category_shell_for_content_change(int $category_id): void {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return;
    }

    if (ll_tools_category_maintenance_is_deferred()) {
        ll_tools_queue_deferred_category_maintenance([$category_id]);
        return;
    }

    if (ll_tools_category_has_active_quiz_page_shell($category_id)) {
        return;
    }

    ll_tools_handle_category_sync_immediate($category_id);
}

function ll_tools_handle_category_delete($term_id) {
    $existing = ll_tools_get_quiz_page_ids_for_category((int) $term_id, ['publish','draft','pending','private'], true);
    foreach ($existing as $post_id) {
        wp_trash_post((int) $post_id);
    }
}

/** Remove pages for categories that can no longer generate valid quizzes. */
function ll_tools_cleanup_invalid_quiz_pages() : int {
    $removed = 0;
    $pages = get_posts([
        'post_type'      => ll_tools_get_quiz_page_post_types(true),
        'post_status'    => ['publish','draft','pending','private'],
        'meta_key'       => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
        'posts_per_page' => -1,
        'fields'         => 'all',
        'no_found_rows'  => true,
    ]);
    foreach ($pages as $p) {
        $term_id = get_post_meta($p->ID, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true);
        $term    = get_term($term_id, 'word-category');

        // Add safeguards: skip cleanup if term is invalid to avoid aggression during init/activation
        if (!$term || is_wp_error($term)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("LL Tools: Skipping cleanup for invalid term ID $term_id on page {$p->ID}");
            }
            continue;
        }

        if (function_exists('ll_can_category_generate_quiz')) {
            $ok = ll_can_category_generate_quiz($term, LL_TOOLS_MIN_WORDS_PER_QUIZ);
        } else {
            // Fallback if function not available (shouldn't happen)
            $ok = false;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("LL Tools: ll_can_category_generate_quiz not available, defaulting to false for term {$term->term_id}");
            }
        }

        if (!$ok) {
            wp_trash_post($p->ID);
            $removed++;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("LL Tools: Trashed page {$p->ID} for category '{$term->name}' due to insufficient words (< " . LL_TOOLS_MIN_WORDS_PER_QUIZ . ")");
            }
        }
    }
    return $removed;
}

/** Full backfill of all pages */
function ll_tools_sync_quiz_pages() {
    // Add transient check to prevent overly aggressive sync during early init (e.g., insufficient word seeding)
    if (get_transient('ll_tools_skip_sync_until_seeded')) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("LL Tools: Skipping quiz page sync due to 'skip until seeded' transient");
        }
        return;
    }

    $removed = ll_tools_cleanup_invalid_quiz_pages();
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("LL Tools: Cleaned up $removed invalid quiz pages");
    }

    $terms = get_terms(['taxonomy' => 'word-category', 'hide_empty' => false, 'fields' => 'all']);
    if (is_wp_error($terms)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("LL Tools: Failed to fetch word-category terms: " . $terms->get_error_message());
        }
        return;
    }

    $total_created = 0;
    foreach ($terms as $term) {
        if (function_exists('ll_can_category_generate_quiz')) {
            $ok = ll_can_category_generate_quiz($term, LL_TOOLS_MIN_WORDS_PER_QUIZ);
        } else {
            $ok = false;
        }

        if ($ok) {
            $result = ll_tools_get_or_create_quiz_page_for_category($term->term_id);
            if (!is_wp_error($result)) {
                $total_created++;
            } elseif (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("LL Tools: Failed to create/sync page for category '{$term->name}': " . $result->get_error_message());
            }
        } else {
            // Log decision for debugging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("LL Tools: Skipping page creation for category '{$term->name}' (ID {$term->term_id}): insufficient words");
            }
        }
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("LL Tools: Sync completed - $removed removed, $total_created created/updated");
    }

    update_option('ll_tools_quiz_page_sync_last', time(), false);
}

/** Wire term create/edit/delete to sync */
add_action('created_word-category', 'll_tools_handle_category_sync', 10, 1);
add_action('edited_word-category',  'll_tools_handle_category_sync', 10, 1);
add_action('delete_word-category',  'll_tools_handle_category_delete', 10, 1);

/** Daily safety net (admin only) */
add_action('admin_init', function () {
    // Remove transient after seeding completes to allow normal sync
    if (get_transient('ll_tools_seed_default_wordset')) {
        delete_transient('ll_tools_skip_sync_until_seeded');
    }

    $last = (int) get_option('ll_tools_quiz_page_sync_last', 0);
    if ($last < (time() - DAY_IN_SECONDS)) {
        ll_tools_schedule_quiz_page_full_sync();
    }
});

add_action(LL_TOOLS_QUIZ_PAGE_SYNC_EVENT, 'll_tools_sync_quiz_pages');

/** Keep pages in sync when content in those categories changes */
function ll_tools_sync_categories_for_post($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (!in_array($post->post_type, ['words','word_images'], true)) return;
    if ($post->post_status !== 'publish') return;

    $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) return;
    $term_ids = ll_tools_normalize_category_maintenance_ids($term_ids);
    foreach ($term_ids as $tid) ll_tools_sync_category_shell_for_content_change((int) $tid);
    ll_tools_bump_category_cache_version($term_ids);
}
add_action('save_post_words',       'll_tools_sync_categories_for_post', 10, 3);
add_action('save_post_word_images', 'll_tools_sync_categories_for_post', 10, 3);

function ll_tools_sync_categories_on_term_set($object_id, $terms, $tt_ids, $taxonomy) {
    if ($taxonomy === 'wordset' && get_transient('ll_tools_wordset_backfill_running')) {
        return;
    }
    $post = get_post($object_id);
    if (!$post || !in_array($post->post_type, ['words','word_images'], true)) return;

    $term_ids = [];
    if ($taxonomy === 'word-category') {
        $term_ids = array_map('intval', (array) $terms);
    } elseif ($taxonomy === 'wordset') {
        $term_ids = wp_get_post_terms($object_id, 'word-category', ['fields' => 'ids']);
        if (is_wp_error($term_ids)) {
            $term_ids = [];
        }
    } else {
        return;
    }

    $term_ids = ll_tools_normalize_category_maintenance_ids($term_ids);
    foreach ($term_ids as $tid) ll_tools_sync_category_shell_for_content_change((int) $tid);
    ll_tools_bump_category_cache_version($term_ids);
}
add_action('set_object_terms', 'll_tools_sync_categories_on_term_set', 10, 4);

function ll_tools_sync_categories_before_delete($post_id) {
    $post = get_post($post_id);
    if (!$post) return;

    if ($post->post_type === 'word_audio') {
        $parent_id = (int) $post->post_parent;
        if ($parent_id) {
            $term_ids = wp_get_post_terms($parent_id, 'word-category', ['fields' => 'ids']);
            if (!is_wp_error($term_ids) && !empty($term_ids)) {
                ll_tools_bump_category_cache_version($term_ids);
            }
        }
        return;
    }

    if (!in_array($post->post_type, ['words','word_images'], true)) return;
    $term_ids = wp_get_post_terms($post_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids)) return;
    $term_ids = ll_tools_normalize_category_maintenance_ids($term_ids);
    foreach ($term_ids as $tid) ll_tools_handle_category_sync((int) $tid);
    ll_tools_bump_category_cache_version($term_ids);
}
add_action('before_delete_post', 'll_tools_sync_categories_before_delete');

function ll_tools_sync_cache_on_word_audio_save($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (!$post || $post->post_type !== 'word_audio') return;

    $parent_id = (int) $post->post_parent;
    if ($parent_id <= 0) return;

    $term_ids = wp_get_post_terms($parent_id, 'word-category', ['fields' => 'ids']);
    if (is_wp_error($term_ids) || empty($term_ids)) return;

    ll_tools_bump_category_cache_version($term_ids);
}
add_action('save_post_word_audio', 'll_tools_sync_cache_on_word_audio_save', 10, 3);

/** Hide the title on these auto pages */
add_filter('the_title', function ($title, $post_id) {
    if (is_admin()) return $title;
    return (in_array(get_post_type($post_id), ll_tools_get_quiz_page_post_types(true), true) && get_post_meta($post_id, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true)) ? '' : $title;
}, 10, 2);

function ll_qp_enqueue_popup_assets(): void {
    static $enqueued = false;
    if ($enqueued || is_admin()) {
        return;
    }
    $enqueued = true;

    ll_enqueue_asset_by_timestamp('/js/quiz-pages.js', 'll-quiz-pages-js', [], true);
    wp_localize_script('ll-quiz-pages-js', 'llQuizPages', [
        'vh' => (int) apply_filters('ll_tools_quiz_iframe_vh', 95),
        'labels' => [
            'defaultTitle' => __('Quiz', 'll-tools-text-domain'),
            'closeLabel'   => __('Close', 'll-tools-text-domain'),
            'iframeTitle'  => __('Quiz Content', 'll-tools-text-domain'),
            'closeConfirm' => __('Close this quiz? Your current progress in this popup will be lost.', 'll-tools-text-domain'),
        ],
    ]);
}

/** Enqueue quiz page assets directly. Callers are responsible for context gating when needed. */
function ll_qp_enqueue_assets() {
    if (is_admin()) {
        return;
    }

    ll_qp_enqueue_popup_assets();
    ll_enqueue_asset_by_timestamp('/css/quiz-pages.css', 'll-quiz-pages-css');
}

/** Enqueue quiz page assets only when WordPress is rendering a quiz page context. */
function ll_qp_maybe_enqueue_assets(): void {
    if (is_admin()) {
        return;
    }

    if (!function_exists('ll_qp_is_quiz_page_context') || !ll_qp_is_quiz_page_context()) {
        return;
    }

    ll_qp_enqueue_assets();
}
add_action('wp_enqueue_scripts', 'll_qp_maybe_enqueue_assets');

/** Manual cleanup UI on the word-category admin screen */
function ll_tools_add_manual_cleanup_button() {
    if (!current_user_can('manage_options')) return;

    $cleanup_nonce = isset($_POST['ll_cleanup_nonce']) ? sanitize_text_field(wp_unslash($_POST['ll_cleanup_nonce'])) : '';
    if (isset($_POST['ll_cleanup_quiz_pages']) && wp_verify_nonce($cleanup_nonce, 'll_cleanup_quiz_pages')) {
        $removed = ll_tools_cleanup_invalid_quiz_pages();
        ll_tools_sync_quiz_pages();
        echo '<div class="notice notice-success is-dismissible"><p>';
        printf(
            esc_html__('Quiz page cleanup completed. %d invalid pages removed and valid pages synced.', 'll-tools-text-domain'),
            $removed
        );
        echo '</p></div>';
    }

    echo '<div class="wrap" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
          <h2>' . esc_html__('Quiz Page Management', 'll-tools-text-domain') . '</h2>
          <p>' . esc_html__('Clean up quiz pages for categories that can no longer generate valid quizzes and sync pages for valid categories.', 'll-tools-text-domain') . '</p>
          <form method="post">';
    wp_nonce_field('ll_cleanup_quiz_pages', 'll_cleanup_nonce');
    echo '<input type="submit" name="ll_cleanup_quiz_pages" class="button button-secondary" value="' . esc_attr__('Clean Up & Sync Quiz Pages', 'll-tools-text-domain') . '"></form></div>';
}
add_action('after-word-category-table', 'll_tools_add_manual_cleanup_button');

/** Force cleanup via query param (admin only) */
function ll_tools_force_quiz_cleanup() {
    if (!is_admin() || !isset($_GET['ll_force_quiz_cleanup']) || !current_user_can('manage_options')) return;

    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
    if (!wp_verify_nonce($nonce, 'll_force_quiz_cleanup')) {
        wp_die(esc_html__('Security check failed.', 'll-tools-text-domain'), 403);
    }

    $removed = ll_tools_cleanup_invalid_quiz_pages();
    ll_tools_sync_quiz_pages();

    $message = sprintf(
        esc_html__('Quiz page cleanup completed. %d invalid pages removed.', 'll-tools-text-domain'),
        $removed
    );
    $link = sprintf(
        ' <a href="%s">%s</a>',
        esc_url(admin_url('edit.php?post_type=' . LL_TOOLS_QUIZ_PAGE_POST_TYPE)),
        esc_html__('Go to Quiz Pages', 'll-tools-text-domain')
    );

    wp_die($message . $link);
}
add_action('admin_init', 'll_tools_force_quiz_cleanup', LL_TOOLS_MIN_WORDS_PER_QUIZ);

/** Resync when source files change (this file, template, JS, CSS) */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;

    $watch = [
        __FILE__,
        LL_TOOLS_BASE_PATH . 'templates/quiz-page-template.php',
        LL_TOOLS_BASE_PATH . 'js/quiz-pages.js',
        LL_TOOLS_BASE_PATH . 'css/quiz-pages.css',
    ];
    $current_mtime = 0;
    foreach ($watch as $f) { if (file_exists($f)) { $t = (int) @filemtime($f); if ($t > $current_mtime) $current_mtime = $t; } }
    if (!$current_mtime) return;

    $opt_key    = 'll_tools_autopage_source_mtime';
    $last_mtime = (int) get_option($opt_key, 0);
    $force = false;
    if (isset($_GET['lltools-resync'])) {
        $resync_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $force = (bool) wp_verify_nonce($resync_nonce, 'lltools-resync');
    }

    if (!$force && $current_mtime === $last_mtime) return;
    if (!$force) {
        ll_tools_schedule_quiz_page_full_sync();
        update_option($opt_key, $current_mtime, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LL Tools] Quiz page re-sync scheduled after source change (mtime=' . $current_mtime . ').');
        }
        return;
    }

    if (get_transient('ll_tools_autopage_resync_running')) return;

    set_transient('ll_tools_autopage_resync_running', 1, 5 * MINUTE_IN_SECONDS);

    // Run sync
    $terms = get_terms(['taxonomy' => 'word-category','hide_empty' => false]);
    if (!is_wp_error($terms)) foreach ($terms as $t) {
        if (function_exists('ll_can_category_generate_quiz')) {
            $ok = ll_can_category_generate_quiz($t, LL_TOOLS_MIN_WORDS_PER_QUIZ);
        } else {
            $ok = false;
        }
        if ($ok) ll_tools_handle_category_sync($t->term_id);
    }

    // Remove orphaned pages
    $orphan_pages = get_posts([
        'post_type'      => ll_tools_get_quiz_page_post_types(true),
        'post_status'    => ['publish','draft','pending','private'],
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => LL_TOOLS_QUIZ_PAGE_CATEGORY_META,
        'no_found_rows'  => true,
    ]);
    foreach ($orphan_pages as $pid) {
        $term_id = (int) get_post_meta($pid, LL_TOOLS_QUIZ_PAGE_CATEGORY_META, true);
        $term    = $term_id ? get_term($term_id, 'word-category') : null;
        if (!$term || is_wp_error($term)) wp_delete_post($pid, true);
    }

    update_option($opt_key, $current_mtime, true);
    delete_transient('ll_tools_autopage_resync_running');

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[LL Tools] Quiz pages re-synced after source change (mtime=' . $current_mtime . ').');
    }
});

/** Allow bootstrap to register an activation hook */
function ll_tools_register_autopage_activation($main_file) {
    if (function_exists('register_activation_hook')) {
        register_activation_hook($main_file, function() {
            // Set transient to skip aggressive sync until seeding completes
            set_transient('ll_tools_skip_sync_until_seeded', 1, 10 * MINUTE_IN_SECONDS);
            ll_tools_schedule_quiz_page_full_sync(60);
        });
    }
}

/** Manual cleanup UI on admin pages (expanded from just category edit) */
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    $screen = get_current_screen();
    $expected_screen_id = 'tools_page_ll-tools';
    if (function_exists('ll_tools_get_tools_hub_screen_id')) {
        $expected_screen_id = ll_tools_get_tools_hub_screen_id();
    } elseif (function_exists('ll_tools_get_tools_hub_page_slug')) {
        $expected_screen_id = 'toplevel_page_' . ll_tools_get_tools_hub_page_slug();
    }
    if (!$screen || $screen->id !== $expected_screen_id) return;

    $cleanup_nonce = isset($_POST['ll_cleanup_nonce']) ? sanitize_text_field(wp_unslash($_POST['ll_cleanup_nonce'])) : '';
    if (isset($_POST['ll_cleanup_quiz_pages']) && wp_verify_nonce($cleanup_nonce, 'll_cleanup_quiz_pages')) {
        $removed = ll_tools_cleanup_invalid_quiz_pages();
        ll_tools_sync_quiz_pages();
        echo '<div class="notice notice-success"><p>';
        printf(
            esc_html__('Quiz page cleanup completed. %d invalid pages removed and valid pages synced.', 'll-tools-text-domain'),
            $removed
        );
        echo '</p></div>';
    }

    echo '<div class="wrap"><h2>' . esc_html__('Quiz Page Management', 'll-tools-text-domain') . '</h2>
          <p>' . esc_html__('Clean up quiz pages for categories that can no longer generate valid quizzes and sync pages for valid categories.', 'll-tools-text-domain') . '</p>
          <form method="post">';
    wp_nonce_field('ll_cleanup_quiz_pages', 'll_cleanup_nonce');
    echo '<input type="submit" name="ll_cleanup_quiz_pages" class="button button-secondary" value="' . esc_attr__('Clean Up & Sync Quiz Pages', 'll-tools-text-domain') . '"></form></div>';
});
