<?php
// /includes/lib/ai-crawler-support.php
if (!defined('WPINC')) { die; }

/**
 * Return the generated AI-crawler export routes.
 *
 * @return array<string,array{key:string,content_type:string}>
 */
function ll_tools_ai_crawler_export_routes(): array {
    return [
        '/llms.txt' => [
            'key' => 'llms',
            'content_type' => 'text/plain; charset=utf-8',
        ],
        '/ll-tools/llms.txt' => [
            'key' => 'llms',
            'content_type' => 'text/plain; charset=utf-8',
        ],
        '/ll-tools/index.md' => [
            'key' => 'index',
            'content_type' => 'text/markdown; charset=utf-8',
        ],
        '/ll-tools/dictionary.md' => [
            'key' => 'dictionary',
            'content_type' => 'text/markdown; charset=utf-8',
        ],
        '/ll-tools/wordsets.md' => [
            'key' => 'wordsets',
            'content_type' => 'text/markdown; charset=utf-8',
        ],
        '/ll-tools/content-lessons.md' => [
            'key' => 'content-lessons',
            'content_type' => 'text/markdown; charset=utf-8',
        ],
        '/ll-tools/ai-crawler.md' => [
            'key' => 'notes',
            'content_type' => 'text/markdown; charset=utf-8',
        ],
    ];
}

function ll_tools_ai_crawler_current_request_path(): string {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';
    $path = (string) parse_url($request_uri, PHP_URL_PATH);
    $path = rawurldecode($path);

    $home_path = (string) parse_url(home_url('/'), PHP_URL_PATH);
    $home_path = '/' . trim($home_path, '/');
    if ($home_path !== '/' && $home_path !== '' && strpos($path, $home_path . '/') === 0) {
        $path = substr($path, strlen($home_path));
    } elseif ($home_path !== '/' && $path === $home_path) {
        $path = '/';
    }

    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : untrailingslashit($path);
}

function ll_tools_ai_crawler_maybe_serve_export(): void {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $routes = ll_tools_ai_crawler_export_routes();
    $path = ll_tools_ai_crawler_current_request_path();
    if (!isset($routes[$path])) {
        return;
    }

    $export = $routes[$path];
    $body = ll_tools_ai_crawler_build_export((string) $export['key']);
    if ($body === '') {
        return;
    }

    status_header(200);
    header('Content-Type: ' . $export['content_type']);
    header('X-Robots-Tag: index, follow');
    header('Cache-Control: public, max-age=' . (string) ll_tools_ai_crawler_response_cache_seconds());

    if ($method !== 'HEAD') {
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    exit;
}
add_action('template_redirect', 'll_tools_ai_crawler_maybe_serve_export', 0);

function ll_tools_ai_crawler_response_cache_seconds(): int {
    return max(60, min(DAY_IN_SECONDS, (int) apply_filters('ll_tools_ai_crawler_response_cache_seconds', 10 * MINUTE_IN_SECONDS)));
}

function ll_tools_ai_crawler_build_export(string $key): string {
    switch ($key) {
        case 'llms':
            return ll_tools_ai_crawler_build_llms_txt();
        case 'index':
            return ll_tools_ai_crawler_build_index_markdown();
        case 'dictionary':
            return ll_tools_ai_crawler_build_dictionary_markdown();
        case 'wordsets':
            return ll_tools_ai_crawler_build_wordsets_markdown();
        case 'content-lessons':
            return ll_tools_ai_crawler_build_content_lessons_markdown();
        case 'notes':
            return ll_tools_ai_crawler_build_notes_markdown();
    }

    return '';
}

function ll_tools_ai_crawler_markdown_document(array $lines): string {
    return rtrim(implode("\n", $lines)) . "\n";
}

function ll_tools_ai_crawler_markdown_text(string $text): string {
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim(is_string($text) ? $text : '');
}

function ll_tools_ai_crawler_markdown_inline(string $text): string {
    $text = ll_tools_ai_crawler_markdown_text($text);
    $text = str_replace(['\\', '[', ']', '`'], ['\\\\', '\\[', '\\]', '\\`'], $text);

    return $text;
}

function ll_tools_ai_crawler_markdown_excerpt(string $text, int $max_length = 480): string {
    $text = ll_tools_ai_crawler_markdown_text($text);
    $max_length = max(80, $max_length);
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    if ($length <= $max_length) {
        return $text;
    }

    $excerpt = function_exists('mb_substr')
        ? mb_substr($text, 0, $max_length - 3, 'UTF-8')
        : substr($text, 0, $max_length - 3);

    return rtrim((string) $excerpt, " \t\n\r\0\x0B,;:") . '...';
}

function ll_tools_ai_crawler_markdown_heading(string $text): string {
    $text = ll_tools_ai_crawler_markdown_inline($text);
    $text = ltrim($text, '# ');

    return $text !== '' ? $text : __('Untitled', 'll-tools-text-domain');
}

function ll_tools_ai_crawler_markdown_link(string $label, string $url, string $description = ''): string {
    $label = ll_tools_ai_crawler_markdown_inline($label);
    $url = esc_url_raw($url);
    if ($label === '') {
        $label = $url;
    }

    $line = '- [' . $label . '](' . $url . ')';
    $description = ll_tools_ai_crawler_markdown_excerpt($description, 280);
    if ($description !== '') {
        $line .= ': ' . $description;
    }

    return $line;
}

function ll_tools_ai_crawler_site_name(): string {
    $name = ll_tools_ai_crawler_markdown_text((string) get_bloginfo('name'));
    if ($name !== '') {
        return $name;
    }

    $host = (string) parse_url(home_url('/'), PHP_URL_HOST);
    return $host !== '' ? $host : __('LL Tools Site', 'll-tools-text-domain');
}

function ll_tools_ai_crawler_limit(array $args, string $key, string $filter_name, int $default, int $min, int $max): int {
    $value = array_key_exists($key, $args)
        ? (int) $args[$key]
        : (int) apply_filters($filter_name, $default);

    return max($min, min($max, $value));
}

function ll_tools_ai_crawler_dictionary_page_url(): string {
    if (function_exists('ll_tools_get_dictionary_page_url')) {
        $url = (string) ll_tools_get_dictionary_page_url();
        if ($url !== '') {
            return $url;
        }
    }

    return home_url('/');
}

function ll_tools_ai_crawler_dictionary_detail_url(int $entry_id): string {
    return add_query_arg(
        ['ll_dictionary_entry' => (string) max(0, $entry_id)],
        ll_tools_ai_crawler_dictionary_page_url()
    );
}

function ll_tools_ai_crawler_can_view_wordset($wordset): bool {
    $wordset_id = 0;
    if ($wordset instanceof WP_Term) {
        $wordset_id = (int) $wordset->term_id;
    } elseif (function_exists('ll_tools_resolve_wordset_term_id')) {
        $wordset_id = (int) ll_tools_resolve_wordset_term_id($wordset);
    } else {
        $wordset_id = (int) $wordset;
    }

    if ($wordset_id <= 0) {
        return false;
    }

    if (function_exists('ll_tools_user_can_view_wordset')) {
        return ll_tools_user_can_view_wordset($wordset_id, 0);
    }

    return !function_exists('ll_tools_is_wordset_private') || !ll_tools_is_wordset_private($wordset_id);
}

function ll_tools_ai_crawler_can_view_category($category): bool {
    $category_id = 0;
    if ($category instanceof WP_Term) {
        $category_id = (int) $category->term_id;
    } elseif (function_exists('ll_tools_resolve_word_category_term_id')) {
        $category_id = (int) ll_tools_resolve_word_category_term_id($category);
    } else {
        $category_id = (int) $category;
    }

    if ($category_id <= 0) {
        return false;
    }

    if (function_exists('ll_tools_user_can_view_category')) {
        return ll_tools_user_can_view_category($category_id, 0);
    }

    return !function_exists('ll_tools_is_category_private') || !ll_tools_is_category_private($category_id);
}

function ll_tools_ai_crawler_can_view_dictionary_entry(int $entry_id): bool {
    if ($entry_id <= 0 || get_post_type($entry_id) !== 'll_dictionary_entry' || get_post_status($entry_id) !== 'publish') {
        return false;
    }
    if (post_password_required($entry_id)) {
        return false;
    }

    $explicit_wordset_id = defined('LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY')
        ? (int) get_post_meta($entry_id, LL_TOOLS_DICTIONARY_ENTRY_WORDSET_META_KEY, true)
        : 0;
    if ($explicit_wordset_id > 0 && !ll_tools_ai_crawler_can_view_wordset($explicit_wordset_id)) {
        return false;
    }

    $scope_wordset_ids = function_exists('ll_tools_get_dictionary_entry_scope_wordset_ids')
        ? array_values(array_filter(array_map('intval', ll_tools_get_dictionary_entry_scope_wordset_ids($entry_id))))
        : [];
    if (!empty($scope_wordset_ids)) {
        foreach ($scope_wordset_ids as $wordset_id) {
            if (ll_tools_ai_crawler_can_view_wordset($wordset_id)) {
                return true;
            }
        }

        return false;
    }

    return true;
}

function ll_tools_ai_crawler_get_wordset_url(WP_Term $wordset): string {
    if (function_exists('ll_tools_get_wordset_page_view_url')) {
        return (string) ll_tools_get_wordset_page_view_url($wordset);
    }

    $url = get_term_link($wordset);
    return is_string($url) ? $url : home_url('/');
}

/**
 * @return WP_Term[]
 */
function ll_tools_ai_crawler_get_public_wordsets(int $limit): array {
    $limit = max(1, min(100, $limit));
    $terms = get_terms([
        'taxonomy' => 'wordset',
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
        'number' => min(200, max($limit, $limit * 3)),
    ]);
    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }

    $public_terms = [];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term) || !ll_tools_ai_crawler_can_view_wordset($term)) {
            continue;
        }
        $public_terms[] = $term;
        if (count($public_terms) >= $limit) {
            break;
        }
    }

    return $public_terms;
}

/**
 * @return array<int,array<string,mixed>>
 */
function ll_tools_ai_crawler_get_public_dictionary_entries(int $limit, int $sense_limit): array {
    $limit = max(1, min(100, $limit));
    $sense_limit = max(1, min(8, $sense_limit));

    $items = [];
    $offset = 0;
    $chunk_size = min(100, max(25, $limit));
    $scan_limit = min(500, max($chunk_size, $limit * 5));

    while (count($items) < $limit && $offset < $scan_limit) {
        $ids = array_values(array_filter(array_map('intval', (array) get_posts([
            'post_type' => 'll_dictionary_entry',
            'post_status' => 'publish',
            'posts_per_page' => min($chunk_size, $scan_limit - $offset),
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]))));

        if (empty($ids)) {
            break;
        }

        $offset += count($ids);
        update_postmeta_cache($ids);

        foreach ($ids as $entry_id) {
            if (!ll_tools_ai_crawler_can_view_dictionary_entry($entry_id)) {
                continue;
            }
            $item = function_exists('ll_tools_dictionary_get_entry_data')
                ? ll_tools_dictionary_get_entry_data($entry_id, $sense_limit, 0)
                : [];
            if (!is_array($item) || empty($item)) {
                $item = [
                    'id' => $entry_id,
                    'title' => (string) get_the_title($entry_id),
                    'translation' => wp_strip_all_tags((string) get_post_field('post_content', $entry_id)),
                    'senses' => [],
                    'sense_count' => 0,
                    'sources' => [],
                    'dialects' => [],
                    'wordset_names' => [],
                ];
            }

            $items[] = [
                'id' => (int) ($item['id'] ?? $entry_id),
                'title' => (string) ($item['title'] ?? get_the_title($entry_id)),
                'translation' => (string) ($item['translation'] ?? ''),
                'entry_type' => (string) ($item['entry_type'] ?? ''),
                'pos_label' => (string) ($item['pos_label'] ?? ''),
                'wordset_names' => is_array($item['wordset_names'] ?? null) ? $item['wordset_names'] : [],
                'sources' => is_array($item['sources'] ?? null) ? $item['sources'] : [],
                'dialects' => is_array($item['dialects'] ?? null) ? $item['dialects'] : [],
                'senses' => is_array($item['senses'] ?? null) ? $item['senses'] : [],
                'sense_count' => (int) ($item['sense_count'] ?? 0),
            ];
            if (count($items) >= $limit) {
                break 2;
            }
        }
    }

    return $items;
}

/**
 * @return array<int,WP_Post>
 */
function ll_tools_ai_crawler_get_public_vocab_lessons(int $limit): array {
    $limit = max(1, min(100, $limit));
    $posts = get_posts([
        'post_type' => 'll_vocab_lesson',
        'post_status' => 'publish',
        'posts_per_page' => min(200, max($limit, $limit * 3)),
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    $lessons = [];
    foreach ((array) $posts as $post) {
        if (!($post instanceof WP_Post) || post_password_required($post)) {
            continue;
        }

        $wordset_id = defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
            ? (int) get_post_meta((int) $post->ID, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true)
            : 0;
        $category_id = defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')
            ? (int) get_post_meta((int) $post->ID, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true)
            : 0;
        if ($wordset_id > 0 && !ll_tools_ai_crawler_can_view_wordset($wordset_id)) {
            continue;
        }
        if ($category_id > 0 && !ll_tools_ai_crawler_can_view_category($category_id)) {
            continue;
        }

        $lessons[] = $post;
        if (count($lessons) >= $limit) {
            break;
        }
    }

    return $lessons;
}

/**
 * @return array<int,WP_Post>
 */
function ll_tools_ai_crawler_get_public_content_lessons(int $limit): array {
    $limit = max(1, min(100, $limit));
    $posts = get_posts([
        'post_type' => 'll_content_lesson',
        'post_status' => 'publish',
        'posts_per_page' => min(200, max($limit, $limit * 3)),
        'orderby' => 'title',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);

    $lessons = [];
    foreach ((array) $posts as $post) {
        if (!($post instanceof WP_Post) || post_password_required($post)) {
            continue;
        }

        $wordset_id = function_exists('ll_tools_get_content_lesson_wordset_id')
            ? (int) ll_tools_get_content_lesson_wordset_id((int) $post->ID)
            : 0;
        if ($wordset_id > 0 && !ll_tools_ai_crawler_can_view_wordset($wordset_id)) {
            continue;
        }

        $lessons[] = $post;
        if (count($lessons) >= $limit) {
            break;
        }
    }

    return $lessons;
}

function ll_tools_ai_crawler_build_llms_txt(): string {
    $site_name = ll_tools_ai_crawler_site_name();
    $lines = [
        '# ' . ll_tools_ai_crawler_markdown_heading($site_name),
        '',
        '> ' . __('Public LL Tools language-learning content, optimized for AI agents and crawlers.', 'll-tools-text-domain'),
        '',
        __('This file lists anonymous, read-only public content. Use canonical HTML URLs for citation and the Markdown exports for compact context. Admin screens, editor workflows, recording tools, REST mutation endpoints, and private wordsets are not public crawl targets.', 'll-tools-text-domain'),
        '',
        '## Core',
        ll_tools_ai_crawler_markdown_link(__('LL Tools AI index', 'll-tools-text-domain'), home_url('/ll-tools/index.md'), __('Compact generated Markdown index for AI agents.', 'll-tools-text-domain')),
        ll_tools_ai_crawler_markdown_link(__('Dictionary', 'll-tools-text-domain'), ll_tools_ai_crawler_dictionary_page_url(), __('Public dictionary search and browse page.', 'll-tools-text-domain')),
        '',
        '## Markdown Exports',
        ll_tools_ai_crawler_markdown_link(__('Dictionary Markdown', 'll-tools-text-domain'), home_url('/ll-tools/dictionary.md'), __('Bounded public dictionary entries with definitions, sources, and canonical detail links.', 'll-tools-text-domain')),
        ll_tools_ai_crawler_markdown_link(__('Wordsets Markdown', 'll-tools-text-domain'), home_url('/ll-tools/wordsets.md'), __('Public wordset hubs and public vocab lessons.', 'll-tools-text-domain')),
        ll_tools_ai_crawler_markdown_link(__('Content Lessons Markdown', 'll-tools-text-domain'), home_url('/ll-tools/content-lessons.md'), __('Public content lessons with compact transcript samples.', 'll-tools-text-domain')),
        '',
        '## Optional',
        ll_tools_ai_crawler_markdown_link(__('AI crawler notes', 'll-tools-text-domain'), home_url('/ll-tools/ai-crawler.md'), __('Operational notes about how these generated files should be interpreted.', 'll-tools-text-domain')),
    ];

    return ll_tools_ai_crawler_markdown_document($lines);
}

function ll_tools_ai_crawler_build_index_markdown(): string {
    $site_name = ll_tools_ai_crawler_site_name();
    $lines = [
        '# ' . ll_tools_ai_crawler_markdown_heading(sprintf(
            /* translators: %s: Site name. */
            __('%s LL Tools AI Index', 'll-tools-text-domain'),
            $site_name
        )),
        '',
        __('This generated index points AI agents at compact public LL Tools content. It is intentionally read-only and bounded; the linked HTML pages remain the canonical source for citation.', 'll-tools-text-domain'),
        '',
        '## Discovery',
        ll_tools_ai_crawler_markdown_link(__('Root llms.txt', 'll-tools-text-domain'), home_url('/llms.txt'), __('Site-level AI discovery file.', 'll-tools-text-domain')),
        ll_tools_ai_crawler_markdown_link(__('LL Tools llms.txt', 'll-tools-text-domain'), home_url('/ll-tools/llms.txt'), __('Plugin-scoped copy of the discovery file.', 'll-tools-text-domain')),
        '',
        '## Public Content',
        ll_tools_ai_crawler_markdown_link(__('Dictionary Markdown', 'll-tools-text-domain'), home_url('/ll-tools/dictionary.md'), __('Headwords, summaries, sources, dialects, and dictionary detail links.', 'll-tools-text-domain')),
        ll_tools_ai_crawler_markdown_link(__('Wordsets Markdown', 'll-tools-text-domain'), home_url('/ll-tools/wordsets.md'), __('Public wordset landing pages and vocab lesson routes.', 'll-tools-text-domain')),
        ll_tools_ai_crawler_markdown_link(__('Content Lessons Markdown', 'll-tools-text-domain'), home_url('/ll-tools/content-lessons.md'), __('Content lessons and compact transcript cue samples.', 'll-tools-text-domain')),
        '',
        '## Notes',
        ll_tools_ai_crawler_markdown_link(__('AI crawler notes', 'll-tools-text-domain'), home_url('/ll-tools/ai-crawler.md'), __('Scope and interpretation notes for these generated exports.', 'll-tools-text-domain')),
    ];

    return ll_tools_ai_crawler_markdown_document($lines);
}

function ll_tools_ai_crawler_build_dictionary_markdown(array $args = []): string {
    $limit = ll_tools_ai_crawler_limit($args, 'limit', 'll_tools_ai_crawler_dictionary_limit', 50, 1, 100);
    $sense_limit = ll_tools_ai_crawler_limit($args, 'sense_limit', 'll_tools_ai_crawler_dictionary_sense_limit', 4, 1, 8);
    $entries = ll_tools_ai_crawler_get_public_dictionary_entries($limit, $sense_limit);

    $lines = [
        '# ' . ll_tools_ai_crawler_markdown_heading(__('Public Dictionary', 'll-tools-text-domain')),
        '',
        sprintf(
            /* translators: %d: Entry limit. */
            __('This bounded export includes up to %d anonymous public dictionary entries. Use each canonical detail URL for citation and the public dictionary page for search.', 'll-tools-text-domain'),
            $limit
        ),
        '',
        ll_tools_ai_crawler_markdown_link(__('Public dictionary page', 'll-tools-text-domain'), ll_tools_ai_crawler_dictionary_page_url()),
    ];

    if (empty($entries)) {
        $lines[] = '';
        $lines[] = __('No public dictionary entries are currently available.', 'll-tools-text-domain');
        return ll_tools_ai_crawler_markdown_document($lines);
    }

    foreach ($entries as $entry) {
        $entry_id = (int) ($entry['id'] ?? 0);
        $title = ll_tools_ai_crawler_markdown_heading((string) ($entry['title'] ?? ''));
        $translation = ll_tools_ai_crawler_markdown_excerpt((string) ($entry['translation'] ?? ''), 560);
        $lines[] = '';
        $lines[] = '## ' . $title;
        $lines[] = '- ' . __('Canonical URL:', 'll-tools-text-domain') . ' ' . esc_url_raw(ll_tools_ai_crawler_dictionary_detail_url($entry_id));
        if ($translation !== '') {
            $lines[] = '- ' . __('Summary:', 'll-tools-text-domain') . ' ' . $translation;
        }

        $pos = ll_tools_ai_crawler_markdown_text((string) ($entry['pos_label'] ?? $entry['entry_type'] ?? ''));
        if ($pos !== '') {
            $lines[] = '- ' . __('Part of speech:', 'll-tools-text-domain') . ' ' . $pos;
        }

        $wordset_names = ll_tools_ai_crawler_markdown_list_values((array) ($entry['wordset_names'] ?? []));
        if (!empty($wordset_names)) {
            $lines[] = '- ' . __('Wordsets:', 'll-tools-text-domain') . ' ' . implode(', ', $wordset_names);
        }

        $source_labels = ll_tools_ai_crawler_dictionary_source_labels((array) ($entry['sources'] ?? []));
        if (!empty($source_labels)) {
            $lines[] = '- ' . __('Sources:', 'll-tools-text-domain') . ' ' . implode(', ', $source_labels);
        }

        $dialects = ll_tools_ai_crawler_markdown_list_values((array) ($entry['dialects'] ?? []));
        if (!empty($dialects)) {
            $lines[] = '- ' . __('Dialects:', 'll-tools-text-domain') . ' ' . implode(', ', $dialects);
        }

        $senses = (array) ($entry['senses'] ?? []);
        if (!empty($senses)) {
            $lines[] = '';
            $lines[] = '### ' . __('Senses', 'll-tools-text-domain');
            foreach ($senses as $sense) {
                if (!is_array($sense)) {
                    continue;
                }
                $line = ll_tools_ai_crawler_dictionary_sense_line($sense);
                if ($line !== '') {
                    $lines[] = '- ' . $line;
                }
            }
        }
    }

    return ll_tools_ai_crawler_markdown_document($lines);
}

/**
 * @return string[]
 */
function ll_tools_ai_crawler_markdown_list_values(array $values): array {
    $clean = [];
    foreach ($values as $value) {
        $value = ll_tools_ai_crawler_markdown_text(is_scalar($value) ? (string) $value : '');
        if ($value !== '' && !in_array($value, $clean, true)) {
            $clean[] = $value;
        }
    }

    return $clean;
}

/**
 * @return string[]
 */
function ll_tools_ai_crawler_dictionary_source_labels(array $sources): array {
    $labels = [];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        $label = ll_tools_ai_crawler_markdown_text((string) ($source['label'] ?? $source['id'] ?? ''));
        if ($label !== '' && !in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }

    return $labels;
}

function ll_tools_ai_crawler_dictionary_sense_line(array $sense): string {
    $definition = ll_tools_ai_crawler_markdown_excerpt((string) ($sense['definition'] ?? ''), 420);
    if ($definition === '' && isset($sense['translations']) && is_array($sense['translations'])) {
        foreach ($sense['translations'] as $translation) {
            $definition = ll_tools_ai_crawler_markdown_excerpt(is_scalar($translation) ? (string) $translation : '', 420);
            if ($definition !== '') {
                break;
            }
        }
    }
    if ($definition === '') {
        return '';
    }

    $meta = [];
    $entry_type = ll_tools_ai_crawler_markdown_text((string) ($sense['entry_type'] ?? ''));
    if ($entry_type !== '') {
        $meta[] = $entry_type;
    }
    if (function_exists('ll_tools_dictionary_get_sense_source')) {
        $source = ll_tools_dictionary_get_sense_source($sense);
        $source_label = ll_tools_ai_crawler_markdown_text((string) ($source['label'] ?? ''));
        if ($source_label !== '') {
            $meta[] = $source_label;
        }
    } else {
        $source_label = ll_tools_ai_crawler_markdown_text((string) ($sense['source_dictionary'] ?? $sense['source_id'] ?? ''));
        if ($source_label !== '') {
            $meta[] = $source_label;
        }
    }

    $dialects = [];
    if (isset($sense['dialects']) && is_array($sense['dialects'])) {
        $dialects = ll_tools_ai_crawler_markdown_list_values($sense['dialects']);
    } elseif (isset($sense['dialects']) && is_scalar($sense['dialects'])) {
        $dialects = ll_tools_ai_crawler_markdown_list_values(preg_split('/\s*[,;]\s*/', (string) $sense['dialects']) ?: []);
    }
    if (!empty($dialects)) {
        $meta[] = implode(', ', $dialects);
    }

    return $definition . (!empty($meta) ? ' (' . implode('; ', array_values(array_unique($meta))) . ')' : '');
}

function ll_tools_ai_crawler_build_wordsets_markdown(array $args = []): string {
    $wordset_limit = ll_tools_ai_crawler_limit($args, 'wordset_limit', 'll_tools_ai_crawler_wordset_limit', 50, 1, 100);
    $lesson_limit = ll_tools_ai_crawler_limit($args, 'lesson_limit', 'll_tools_ai_crawler_vocab_lesson_limit', 50, 1, 100);
    $wordsets = ll_tools_ai_crawler_get_public_wordsets($wordset_limit);
    $lessons = ll_tools_ai_crawler_get_public_vocab_lessons($lesson_limit);

    $lines = [
        '# ' . ll_tools_ai_crawler_markdown_heading(__('Public Wordsets and Vocab Lessons', 'll-tools-text-domain')),
        '',
        __('This export lists anonymous public wordset hubs and vocab lessons. Private wordsets and private categories are excluded.', 'll-tools-text-domain'),
        '',
        '## ' . __('Wordsets', 'll-tools-text-domain'),
    ];

    if (empty($wordsets)) {
        $lines[] = __('No public wordsets are currently available.', 'll-tools-text-domain');
    } else {
        foreach ($wordsets as $wordset) {
            $lines[] = ll_tools_ai_crawler_markdown_link(
                (string) $wordset->name,
                ll_tools_ai_crawler_get_wordset_url($wordset),
                (string) $wordset->description
            );
        }
    }

    $lines[] = '';
    $lines[] = '## ' . __('Vocab Lessons', 'll-tools-text-domain');
    if (empty($lessons)) {
        $lines[] = __('No public vocab lessons are currently available.', 'll-tools-text-domain');
    } else {
        foreach ($lessons as $lesson) {
            $description = ll_tools_ai_crawler_vocab_lesson_description($lesson);
            $lines[] = ll_tools_ai_crawler_markdown_link((string) get_the_title($lesson), (string) get_permalink($lesson), $description);
        }
    }

    return ll_tools_ai_crawler_markdown_document($lines);
}

function ll_tools_ai_crawler_vocab_lesson_description(WP_Post $lesson): string {
    $parts = [];
    $wordset_id = defined('LL_TOOLS_VOCAB_LESSON_WORDSET_META')
        ? (int) get_post_meta((int) $lesson->ID, LL_TOOLS_VOCAB_LESSON_WORDSET_META, true)
        : 0;
    if ($wordset_id > 0) {
        $wordset = get_term($wordset_id, 'wordset');
        if ($wordset instanceof WP_Term && !is_wp_error($wordset)) {
            $parts[] = sprintf(
                /* translators: %s: Wordset name. */
                __('Wordset: %s', 'll-tools-text-domain'),
                (string) $wordset->name
            );
        }
    }

    $category_id = defined('LL_TOOLS_VOCAB_LESSON_CATEGORY_META')
        ? (int) get_post_meta((int) $lesson->ID, LL_TOOLS_VOCAB_LESSON_CATEGORY_META, true)
        : 0;
    if ($category_id > 0 && ll_tools_ai_crawler_can_view_category($category_id)) {
        $category = get_term($category_id, 'word-category');
        if ($category instanceof WP_Term && !is_wp_error($category)) {
            $label = function_exists('ll_tools_get_category_display_name')
                ? (string) ll_tools_get_category_display_name($category, ['wordset_ids' => [$wordset_id]])
                : (string) $category->name;
            if ($label !== '') {
                $parts[] = sprintf(
                    /* translators: %s: Category name. */
                    __('Category: %s', 'll-tools-text-domain'),
                    $label
                );
            }
        }
    }

    return implode('; ', array_map('ll_tools_ai_crawler_markdown_text', $parts));
}

function ll_tools_ai_crawler_build_content_lessons_markdown(array $args = []): string {
    $lesson_limit = ll_tools_ai_crawler_limit($args, 'lesson_limit', 'll_tools_ai_crawler_content_lesson_limit', 50, 1, 100);
    $cue_limit = ll_tools_ai_crawler_limit($args, 'cue_limit', 'll_tools_ai_crawler_content_lesson_cue_limit', 8, 0, 20);
    $lessons = ll_tools_ai_crawler_get_public_content_lessons($lesson_limit);

    $lines = [
        '# ' . ll_tools_ai_crawler_markdown_heading(__('Public Content Lessons', 'll-tools-text-domain')),
        '',
        sprintf(
            /* translators: %d: Lesson limit. */
            __('This bounded export includes up to %d public content lessons. Transcript cues are compact samples; use the canonical lesson URL for the full page.', 'll-tools-text-domain'),
            $lesson_limit
        ),
    ];

    if (empty($lessons)) {
        $lines[] = '';
        $lines[] = __('No public content lessons are currently available.', 'll-tools-text-domain');
        return ll_tools_ai_crawler_markdown_document($lines);
    }

    foreach ($lessons as $lesson) {
        $lines[] = '';
        $lines[] = '## ' . ll_tools_ai_crawler_markdown_heading((string) get_the_title($lesson));
        $lines[] = '- ' . __('Canonical URL:', 'll-tools-text-domain') . ' ' . esc_url_raw((string) get_permalink($lesson));

        $card = function_exists('ll_tools_get_content_lesson_card_data')
            ? ll_tools_get_content_lesson_card_data($lesson)
            : [];
        $excerpt = ll_tools_ai_crawler_markdown_excerpt((string) ($card['excerpt'] ?? $lesson->post_excerpt ?? ''), 360);
        if ($excerpt !== '') {
            $lines[] = '- ' . __('Summary:', 'll-tools-text-domain') . ' ' . $excerpt;
        }

        $media_label = ll_tools_ai_crawler_markdown_text((string) ($card['media_label'] ?? ''));
        if ($media_label !== '') {
            $lines[] = '- ' . __('Media:', 'll-tools-text-domain') . ' ' . $media_label;
        }

        $wordset_id = (int) ($card['wordset_id'] ?? 0);
        if ($wordset_id > 0) {
            $wordset = get_term($wordset_id, 'wordset');
            if ($wordset instanceof WP_Term && !is_wp_error($wordset)) {
                $lines[] = '- ' . __('Wordset:', 'll-tools-text-domain') . ' ' . ll_tools_ai_crawler_markdown_text((string) $wordset->name);
            }
        }

        $category_labels = ll_tools_ai_crawler_content_lesson_category_labels((array) ($card['category_ids'] ?? []), $wordset_id);
        if (!empty($category_labels)) {
            $lines[] = '- ' . __('Categories:', 'll-tools-text-domain') . ' ' . implode(', ', $category_labels);
        }

        $cue_lines = ll_tools_ai_crawler_content_lesson_cue_lines((int) $lesson->ID, $cue_limit);
        if (!empty($cue_lines)) {
            $lines[] = '';
            $lines[] = '### ' . __('Transcript Sample', 'll-tools-text-domain');
            foreach ($cue_lines as $cue_line) {
                $lines[] = '- ' . $cue_line;
            }
        }
    }

    return ll_tools_ai_crawler_markdown_document($lines);
}

/**
 * @return string[]
 */
function ll_tools_ai_crawler_content_lesson_category_labels(array $category_ids, int $wordset_id): array {
    $labels = [];
    foreach (array_values(array_unique(array_filter(array_map('intval', $category_ids)))) as $category_id) {
        if (!ll_tools_ai_crawler_can_view_category($category_id)) {
            continue;
        }
        $category = get_term($category_id, 'word-category');
        if (!($category instanceof WP_Term) || is_wp_error($category)) {
            continue;
        }
        $label = function_exists('ll_tools_get_category_display_name')
            ? (string) ll_tools_get_category_display_name($category, ['wordset_ids' => [$wordset_id]])
            : (string) $category->name;
        $label = ll_tools_ai_crawler_markdown_text($label);
        if ($label !== '' && !in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }

    return $labels;
}

/**
 * @return string[]
 */
function ll_tools_ai_crawler_content_lesson_cue_lines(int $lesson_id, int $limit): array {
    if ($limit <= 0 || !function_exists('ll_tools_get_content_lesson_cues')) {
        return [];
    }

    $lines = [];
    $cues = ll_tools_get_content_lesson_cues($lesson_id);
    foreach ((array) $cues as $cue) {
        if (!is_array($cue)) {
            continue;
        }
        $text = ll_tools_ai_crawler_markdown_excerpt((string) ($cue['text'] ?? ''), 360);
        if ($text === '') {
            continue;
        }
        $start_ms = max(0, (int) ($cue['start_ms'] ?? 0));
        $prefix = $start_ms > 0 ? '[' . ll_tools_ai_crawler_format_timecode($start_ms) . '] ' : '';
        $lines[] = $prefix . $text;
        if (count($lines) >= $limit) {
            break;
        }
    }

    return $lines;
}

function ll_tools_ai_crawler_format_timecode(int $ms): string {
    $seconds = intdiv(max(0, $ms), 1000);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $remaining = $seconds % 60;

    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $remaining);
    }

    return sprintf('%d:%02d', $minutes, $remaining);
}

function ll_tools_ai_crawler_build_notes_markdown(): string {
    $lines = [
        '# ' . ll_tools_ai_crawler_markdown_heading(__('LL Tools AI Crawler Notes', 'll-tools-text-domain')),
        '',
        __('These generated files are intended to help AI agents discover and summarize public LL Tools content efficiently.', 'll-tools-text-domain'),
        '',
        '## ' . __('Scope', 'll-tools-text-domain'),
        '- ' . __('Only anonymous public content is included.', 'll-tools-text-domain'),
        '- ' . __('Exports are bounded snapshots and may omit older or less relevant public records.', 'll-tools-text-domain'),
        '- ' . __('Canonical HTML pages remain the source of record for citation and full context.', 'll-tools-text-domain'),
        '- ' . __('These files do not grant access to private wordsets, admin pages, editor flows, recording tools, or mutation endpoints.', 'll-tools-text-domain'),
        '',
        '## ' . __('Crawler Interpretation', 'll-tools-text-domain'),
        '- ' . __('Prefer Markdown exports for compact context gathering.', 'll-tools-text-domain'),
        '- ' . __('Prefer canonical HTML URLs when citing or sending a user to the site.', 'll-tools-text-domain'),
        '- ' . __('Treat robots.txt and response headers as the authority for crawl and training policy where the site defines them.', 'll-tools-text-domain'),
    ];

    return ll_tools_ai_crawler_markdown_document($lines);
}
