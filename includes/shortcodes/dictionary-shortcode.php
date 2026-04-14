<?php
if (!defined('WPINC')) { die; }

function ll_tools_dictionary_shortcode_query_keys(): array {
    return [
        'll_dictionary_q',
        'll_dictionary_page',
        'll_dictionary_letter',
        'll_dictionary_pos',
    ];
}

function ll_tools_dictionary_enqueue_assets(): void {
    if (function_exists('ll_tools_enqueue_public_assets')) {
        ll_tools_enqueue_public_assets();
    }
    ll_enqueue_asset_by_timestamp('/css/dictionary-shortcode.css', 'll-tools-dictionary-shortcode', ['ll-tools-style']);
}

function ll_tools_dictionary_shortcode_maybe_enqueue_assets(): void {
    if (is_admin() || !is_singular()) {
        return;
    }

    $post = get_queried_object();
    if (!$post instanceof WP_Post || empty($post->post_content)) {
        return;
    }

    $content = (string) $post->post_content;
    $has_shortcode = has_shortcode($content, 'll_dictionary')
        || has_shortcode($content, 'dictionary_search')
        || has_shortcode($content, 'dictionary_browser');
    if (!$has_shortcode) {
        return;
    }

    ll_tools_dictionary_enqueue_assets();
}
add_action('wp_enqueue_scripts', 'll_tools_dictionary_shortcode_maybe_enqueue_assets', 120);

function ll_tools_dictionary_shortcode_resolve_wordset_id($raw_wordset = ''): int {
    $raw_wordset = is_string($raw_wordset) ? trim($raw_wordset) : '';
    if ($raw_wordset !== '' && function_exists('ll_tools_resolve_wordset_term_id')) {
        $resolved = (int) ll_tools_resolve_wordset_term_id($raw_wordset);
        if ($resolved > 0) {
            return $resolved;
        }
    }

    if ($raw_wordset !== '' && is_numeric($raw_wordset)) {
        return (int) $raw_wordset;
    }

    if (function_exists('ll_tools_get_wordset_page_term')) {
        $wordset_term = ll_tools_get_wordset_page_term();
        if ($wordset_term && !is_wp_error($wordset_term)) {
            return (int) $wordset_term->term_id;
        }
    }

    if (function_exists('ll_tools_get_active_wordset_id')) {
        return (int) ll_tools_get_active_wordset_id();
    }

    return 0;
}

function ll_tools_dictionary_get_current_base_url(): string {
    return (string) remove_query_arg(ll_tools_dictionary_shortcode_query_keys(), get_pagenum_link(1, false));
}

function ll_tools_dictionary_preserve_non_dictionary_query_inputs(): string {
    $exclude = array_flip(ll_tools_dictionary_shortcode_query_keys());
    $html = '';

    foreach ($_GET as $key => $value) {
        if (!is_string($key) || isset($exclude[$key])) {
            continue;
        }
        if (is_array($value)) {
            continue;
        }
        $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr(wp_unslash((string) $value)) . '">';
    }

    return $html;
}

function ll_tools_dictionary_build_url(string $base_url, array $args = []): string {
    $query_args = [];
    foreach ($args as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '' || ($key === 'll_dictionary_page' && (int) $value <= 1)) {
            continue;
        }
        $query_args[$key] = $value;
    }

    return (string) add_query_arg($query_args, $base_url);
}

/**
 * Resolve which gloss languages should be preferred in dictionary summaries.
 *
 * @return string[]
 */
function ll_tools_dictionary_shortcode_resolve_preferred_languages(int $wordset_id = 0, string $raw_gloss_langs = ''): array {
    $languages = [];

    $parts = preg_split('/[\s,|]+/', trim($raw_gloss_langs), -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
                ? ll_tools_dictionary_normalize_language_key((string) $part)
                : strtolower(trim((string) $part));
            if ($language_key === '' || in_array($language_key, $languages, true)) {
                continue;
            }
            $languages[] = $language_key;
        }
    }

    if (empty($languages) && $wordset_id > 0 && function_exists('ll_tools_get_wordset_translation_language')) {
        $wordset_language = (string) ll_tools_get_wordset_translation_language([$wordset_id]);
        $language_key = function_exists('ll_tools_dictionary_normalize_language_key')
            ? ll_tools_dictionary_normalize_language_key($wordset_language)
            : strtolower(trim($wordset_language));
        if ($language_key !== '') {
            $languages[] = $language_key;
        }
    }

    return $languages;
}

function ll_tools_dictionary_render_badge(string $text, string $modifier = ''): string {
    $modifier = sanitize_html_class($modifier);
    $classes = 'll-dictionary__badge';
    if ($modifier !== '') {
        $classes .= ' ll-dictionary__badge--' . $modifier;
    }

    return '<span class="' . esc_attr($classes) . '">' . esc_html($text) . '</span>';
}

/**
 * @param array<string,mixed> $item
 */
function ll_tools_dictionary_render_result_card(array $item): string {
    $title = trim((string) ($item['title'] ?? ''));
    $translation = trim((string) ($item['translation'] ?? ''));
    $pos_label = trim((string) ($item['pos_label'] ?? ''));
    $entry_type = trim((string) ($item['entry_type'] ?? ''));
    $wordset_name = trim((string) ($item['wordset_name'] ?? ''));
    $page_number = trim((string) ($item['page_number'] ?? ''));
    $sense_count = max(0, (int) ($item['sense_count'] ?? 0));
    $linked_word_count = max(0, (int) ($item['linked_word_count'] ?? 0));
    $senses = (array) ($item['senses'] ?? []);
    $linked_words = (array) ($item['linked_words'] ?? []);
    $preferred_languages = array_values(array_filter(array_map('strval', (array) ($item['preferred_languages'] ?? []))));

    $html = '<article class="ll-dictionary__card">';
    $html .= '<div class="ll-dictionary__card-head">';
    $html .= '<div class="ll-dictionary__title-wrap">';
    $html .= '<h3 class="ll-dictionary__title">' . esc_html($title) . '</h3>';
    if ($translation !== '') {
        $html .= '<p class="ll-dictionary__summary">' . esc_html($translation) . '</p>';
    }
    $html .= '</div>';
    $html .= '<div class="ll-dictionary__badges">';
    if ($pos_label !== '') {
        $html .= ll_tools_dictionary_render_badge($pos_label, 'pos');
    }
    if ($entry_type !== '' && $entry_type !== $pos_label) {
        $html .= ll_tools_dictionary_render_badge($entry_type, 'type');
    }
    if ($wordset_name !== '') {
        $html .= ll_tools_dictionary_render_badge($wordset_name, 'wordset');
    }
    if ($page_number !== '') {
        $html .= ll_tools_dictionary_render_badge(
            sprintf(
                /* translators: %s: source page number */
                __('p. %s', 'll-tools-text-domain'),
                $page_number
            ),
            'page'
        );
    }
    if ($linked_word_count > 0) {
        $html .= ll_tools_dictionary_render_badge(
            sprintf(
                /* translators: %d: linked word count */
                _n('%d linked word', '%d linked words', $linked_word_count, 'll-tools-text-domain'),
                $linked_word_count
            ),
            'linked'
        );
    }
    $html .= '</div></div>';

    if (!empty($senses)) {
        $html .= '<ol class="ll-dictionary__sense-list">';
        foreach ($senses as $sense) {
            if (!is_array($sense)) {
                continue;
            }

            $definition = function_exists('ll_tools_dictionary_get_preferred_translation_text')
                ? ll_tools_dictionary_get_preferred_translation_text($sense, $preferred_languages, true)
                : trim((string) ($sense['definition'] ?? ''));
            $sense_type = trim((string) ($sense['entry_type'] ?? ''));
            $gender = trim((string) ($sense['gender_number'] ?? ''));
            $parent = trim((string) ($sense['parent'] ?? ''));
            $sense_page = trim((string) ($sense['page_number'] ?? ''));
            if ($definition === '') {
                continue;
            }

            $meta_parts = [];
            if ($sense_type !== '') {
                $meta_parts[] = $sense_type;
            }
            if ($gender !== '') {
                $meta_parts[] = $gender;
            }
            if ($parent !== '') {
                $meta_parts[] = sprintf(
                    /* translators: %s: parent headword */
                    __('Parent: %s', 'll-tools-text-domain'),
                    $parent
                );
            }
            if ($sense_page !== '') {
                $meta_parts[] = sprintf(
                    /* translators: %s: source page number */
                    __('Page %s', 'll-tools-text-domain'),
                    $sense_page
                );
            }

            $translation_rows = [];
            $visible_lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
                ? ll_tools_dictionary_entry_normalize_lookup_value($definition)
                : strtolower($definition);
            $translations = function_exists('ll_tools_dictionary_get_sense_translations')
                ? ll_tools_dictionary_get_sense_translations($sense)
                : [];
            foreach ($translations as $language => $text) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }

                $text_lookup = function_exists('ll_tools_dictionary_entry_normalize_lookup_value')
                    ? ll_tools_dictionary_entry_normalize_lookup_value($text)
                    : strtolower($text);
                if ($text_lookup !== '' && $text_lookup === $visible_lookup) {
                    continue;
                }

                $label = function_exists('ll_tools_dictionary_get_language_label')
                    ? ll_tools_dictionary_get_language_label((string) $language)
                    : strtoupper((string) $language);
                $translation_rows[] = '<span class="ll-dictionary__sense-translation">'
                    . '<span class="ll-dictionary__sense-lang">' . esc_html($label) . '</span>'
                    . '<span class="ll-dictionary__sense-value">' . esc_html($text) . '</span>'
                    . '</span>';
            }

            $html .= '<li class="ll-dictionary__sense-item">';
            $html .= '<span class="ll-dictionary__sense-text">' . esc_html($definition) . '</span>';
            if (!empty($translation_rows)) {
                $html .= '<span class="ll-dictionary__sense-translations">' . implode('', $translation_rows) . '</span>';
            }
            if (!empty($meta_parts)) {
                $html .= '<span class="ll-dictionary__sense-meta">' . esc_html(implode(' • ', $meta_parts)) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ol>';
        if ($sense_count > count($senses)) {
            $html .= '<p class="ll-dictionary__more">';
            $html .= esc_html(sprintf(
                /* translators: %d: number of hidden senses */
                _n('+ %d more sense', '+ %d more senses', $sense_count - count($senses), 'll-tools-text-domain'),
                $sense_count - count($senses)
            ));
            $html .= '</p>';
        }
    }

    if (!empty($linked_words)) {
        $html .= '<div class="ll-dictionary__linked">';
        foreach ($linked_words as $word) {
            if (!is_array($word)) {
                continue;
            }
            $word_text = trim((string) ($word['word_text'] ?? ''));
            $translation_text = trim((string) ($word['translation_text'] ?? ''));
            if ($word_text === '') {
                continue;
            }
            $html .= '<span class="ll-dictionary__chip">';
            $html .= '<span class="ll-dictionary__chip-word">' . esc_html($word_text) . '</span>';
            if ($translation_text !== '') {
                $html .= '<span class="ll-dictionary__chip-translation">' . esc_html($translation_text) . '</span>';
            }
            $html .= '</span>';
        }
        $html .= '</div>';
    }

    $html .= '</article>';

    return $html;
}

/**
 * @param array<string,mixed> $query
 */
function ll_tools_dictionary_render_pagination(array $query, string $base_url, string $search, string $letter, string $pos_slug): string {
    $page = max(1, (int) ($query['page'] ?? 1));
    $total_pages = max(1, (int) ($query['total_pages'] ?? 1));
    if ($total_pages <= 1) {
        return '';
    }

    $html = '<nav class="ll-dictionary__pagination" aria-label="' . esc_attr__('Dictionary pagination', 'll-tools-text-domain') . '">';

    $prev_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
        'll_dictionary_letter' => $letter,
        'll_dictionary_pos' => $pos_slug,
        'll_dictionary_page' => (string) max(1, $page - 1),
    ]);
    $next_url = ll_tools_dictionary_build_url($base_url, [
        'll_dictionary_q' => $search,
        'll_dictionary_letter' => $letter,
        'll_dictionary_pos' => $pos_slug,
        'll_dictionary_page' => (string) min($total_pages, $page + 1),
    ]);

    $html .= '<a class="ll-dictionary__page-button' . ($page <= 1 ? ' is-disabled' : '') . '" href="' . esc_url($page <= 1 ? '#' : $prev_url) . '"' . ($page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '') . '>' . esc_html__('Previous', 'll-tools-text-domain') . '</a>';

    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);
    if ($start > 1) {
        $start = max(1, min($start, $total_pages - 4));
        $end = min($total_pages, max($end, $start + 4));
    }

    for ($current = $start; $current <= $end; $current++) {
        $url = ll_tools_dictionary_build_url($base_url, [
            'll_dictionary_q' => $search,
            'll_dictionary_letter' => $letter,
            'll_dictionary_pos' => $pos_slug,
            'll_dictionary_page' => (string) $current,
        ]);
        $active = ($current === $page) ? ' is-active' : '';
        $html .= '<a class="ll-dictionary__page-number' . $active . '" href="' . esc_url($url) . '">' . esc_html((string) $current) . '</a>';
    }

    $html .= '<a class="ll-dictionary__page-button' . ($page >= $total_pages ? ' is-disabled' : '') . '" href="' . esc_url($page >= $total_pages ? '#' : $next_url) . '"' . ($page >= $total_pages ? ' tabindex="-1" aria-disabled="true"' : '') . '>' . esc_html__('Next', 'll-tools-text-domain') . '</a>';
    $html .= '</nav>';

    return $html;
}

function ll_tools_dictionary_shortcode($atts = [], $content = null, $tag = ''): string {
    $atts = shortcode_atts([
        'wordset' => '',
        'show_title' => '1',
        'per_page' => '20',
        'sense_limit' => '3',
        'linked_word_limit' => '4',
        'title' => '',
        'gloss_lang' => '',
    ], $atts, $tag ?: 'll_dictionary');

    ll_tools_dictionary_enqueue_assets();

    $wordset_id = ll_tools_dictionary_shortcode_resolve_wordset_id((string) $atts['wordset']);
    $preferred_languages = ll_tools_dictionary_shortcode_resolve_preferred_languages($wordset_id, (string) $atts['gloss_lang']);
    $search = isset($_GET['ll_dictionary_q']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_q']))) : '';
    $letter = isset($_GET['ll_dictionary_letter'])
        ? trim(sanitize_text_field(wp_unslash((string) $_GET['ll_dictionary_letter'])))
        : (isset($_GET['letter']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['letter']))) : '');
    $page = isset($_GET['ll_dictionary_page']) ? max(1, (int) wp_unslash((string) $_GET['ll_dictionary_page'])) : 1;
    $pos_slug = isset($_GET['ll_dictionary_pos']) ? sanitize_title((string) wp_unslash((string) $_GET['ll_dictionary_pos'])) : '';
    if ($search !== '') {
        $letter = '';
    }

    $query = function_exists('ll_tools_dictionary_query_entries')
        ? ll_tools_dictionary_query_entries([
            'search' => $search,
            'letter' => $letter,
            'page' => $page,
            'per_page' => max(1, (int) $atts['per_page']),
            'wordset_id' => $wordset_id,
            'pos_slug' => $pos_slug,
            'sense_limit' => max(1, (int) $atts['sense_limit']),
            'linked_word_limit' => max(0, (int) $atts['linked_word_limit']),
            'preferred_languages' => $preferred_languages,
            'post_status' => ['publish'],
        ])
        : ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];

    $items = (array) ($query['items'] ?? []);
    $total = max(0, (int) ($query['total'] ?? 0));
    $current_page = max(1, (int) ($query['page'] ?? 1));
    $per_page = max(1, (int) ($query['per_page'] ?? max(1, (int) $atts['per_page'])));
    $start_index = $total > 0 ? (($current_page - 1) * $per_page) + 1 : 0;
    $end_index = $total > 0 ? min($total, $start_index + count($items) - 1) : 0;

    $wordset_name = '';
    if ($wordset_id > 0) {
        $wordset_term = get_term($wordset_id, 'wordset');
        if ($wordset_term && !is_wp_error($wordset_term)) {
            $wordset_name = (string) $wordset_term->name;
        }
    }

    $custom_title = trim((string) $atts['title']);
    $show_title_raw = strtolower(trim((string) $atts['show_title']));
    $show_title = !in_array($show_title_raw, ['0', 'false', 'no', 'off'], true);
    $heading = $custom_title !== ''
        ? $custom_title
        : ($wordset_name !== '' ? $wordset_name : __('Dictionary', 'll-tools-text-domain'));

    $base_url = ll_tools_dictionary_get_current_base_url();
    $letters = function_exists('ll_tools_dictionary_get_available_letters')
        ? ll_tools_dictionary_get_available_letters($wordset_id)
        : [];
    $pos_options = function_exists('ll_tools_dictionary_get_pos_filter_options')
        ? ll_tools_dictionary_get_pos_filter_options($wordset_id)
        : [];
    $reset_url = ll_tools_dictionary_build_url($base_url);

    ob_start();
    ?>
    <section class="ll-dictionary">
        <?php if ($show_title) : ?>
            <header class="ll-dictionary__header">
                <h2 class="ll-dictionary__heading"><?php echo esc_html($heading); ?></h2>
                <?php if ($wordset_name !== '' && $custom_title !== '' && $custom_title !== $wordset_name) : ?>
                    <p class="ll-dictionary__scope"><?php echo esc_html($wordset_name); ?></p>
                <?php endif; ?>
            </header>
        <?php endif; ?>

        <div class="ll-dictionary__toolbar">
            <form class="ll-dictionary__form" method="get" action="<?php echo esc_url($base_url); ?>">
                <?php echo ll_tools_dictionary_preserve_non_dictionary_query_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div class="ll-dictionary__field ll-dictionary__field--search">
                    <label class="screen-reader-text" for="ll-dictionary-search"><?php esc_html_e('Search dictionary', 'll-tools-text-domain'); ?></label>
                    <input
                        type="search"
                        id="ll-dictionary-search"
                        class="ll-dictionary__input"
                        name="ll_dictionary_q"
                        value="<?php echo esc_attr($search); ?>"
                        placeholder="<?php echo esc_attr__('Search', 'll-tools-text-domain'); ?>"
                    >
                </div>
                <?php if (!empty($pos_options)) : ?>
                    <div class="ll-dictionary__field ll-dictionary__field--select">
                        <label class="screen-reader-text" for="ll-dictionary-pos"><?php esc_html_e('Filter by part of speech', 'll-tools-text-domain'); ?></label>
                        <select id="ll-dictionary-pos" class="ll-dictionary__select" name="ll_dictionary_pos">
                            <option value=""><?php esc_html_e('All types', 'll-tools-text-domain'); ?></option>
                            <?php foreach ($pos_options as $option) : ?>
                                <option value="<?php echo esc_attr((string) $option['slug']); ?>" <?php selected($pos_slug, (string) $option['slug']); ?>>
                                    <?php echo esc_html((string) $option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="ll-dictionary__actions">
                    <button class="ll-dictionary__button" type="submit"><?php esc_html_e('Search', 'll-tools-text-domain'); ?></button>
                    <?php if ($search !== '' || $letter !== '' || $pos_slug !== '') : ?>
                        <a class="ll-dictionary__button ll-dictionary__button--ghost" href="<?php echo esc_url($reset_url); ?>"><?php esc_html_e('Reset', 'll-tools-text-domain'); ?></a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (!empty($letters)) : ?>
                <nav class="ll-dictionary__letters" aria-label="<?php echo esc_attr__('Browse dictionary by letter', 'll-tools-text-domain'); ?>">
                    <a class="ll-dictionary__letter<?php echo $letter === '' ? ' is-active' : ''; ?>" href="<?php echo esc_url(ll_tools_dictionary_build_url($base_url, [
                        'll_dictionary_pos' => $pos_slug,
                    ])); ?>">
                        <?php esc_html_e('All', 'll-tools-text-domain'); ?>
                    </a>
                    <?php foreach ($letters as $browse_letter) : ?>
                        <?php
                        $browse_url = ll_tools_dictionary_build_url($base_url, [
                            'll_dictionary_letter' => (string) $browse_letter,
                            'll_dictionary_pos' => $pos_slug,
                        ]);
                        ?>
                        <a class="ll-dictionary__letter<?php echo $browse_letter === $letter ? ' is-active' : ''; ?>" href="<?php echo esc_url($browse_url); ?>">
                            <?php echo esc_html((string) $browse_letter); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>

        <div class="ll-dictionary__meta">
            <?php if ($total > 0) : ?>
                <p class="ll-dictionary__count">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: 1: first visible result number, 2: last visible result number, 3: total result count */
                        __('Showing %1$d-%2$d of %3$d', 'll-tools-text-domain'),
                        $start_index,
                        $end_index,
                        $total
                    ));
                    ?>
                </p>
            <?php else : ?>
                <p class="ll-dictionary__count"><?php esc_html_e('No entries found.', 'll-tools-text-domain'); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($items)) : ?>
            <div class="ll-dictionary__results">
                <?php foreach ($items as $item) : ?>
                    <?php echo ll_tools_dictionary_render_result_card((array) $item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endforeach; ?>
            </div>
            <?php echo ll_tools_dictionary_render_pagination($query, $base_url, $search, $letter, $pos_slug); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php else : ?>
            <div class="ll-dictionary__empty">
                <?php if ($search !== '') : ?>
                    <p><?php esc_html_e('Try a shorter query, another spelling, or switch to letter browsing.', 'll-tools-text-domain'); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('Import a dictionary or migrate the legacy table to populate this view.', 'll-tools-text-domain'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

add_shortcode('ll_dictionary', 'll_tools_dictionary_shortcode');
add_shortcode('dictionary_search', 'll_tools_dictionary_shortcode');
add_shortcode('dictionary_browser', 'll_tools_dictionary_shortcode');
