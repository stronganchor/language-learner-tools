<?php
// /templates/wordset-page-template.php
if (!defined('WPINC')) { die; }

$wordset = function_exists('ll_tools_get_wordset_page_term') ? ll_tools_get_wordset_page_term() : null;
if (!$wordset || is_wp_error($wordset)) {
    status_header(404);
    nocache_headers();
    get_header();
    echo '<main class="ll-wordset-page ll-wordset-page--missing"><div class="ll-wordset-empty">';
    echo esc_html__('Word set not found.', 'll-tools-text-domain');
    echo '</div></main>';
    get_footer();
    return;
}

$wp_query = $GLOBALS['wp_query'] ?? null;
if ($wp_query) {
    $wp_query->is_404 = false;
}
status_header(200);

$categories = function_exists('ll_tools_get_wordset_page_categories')
    ? ll_tools_get_wordset_page_categories((int) $wordset->term_id)
    : [];

$page_title = $wordset->name ?: get_bloginfo('name');
add_filter('pre_get_document_title', function () use ($page_title) {
    return $page_title;
});

get_header();
?>
<main class="ll-wordset-page" data-ll-wordset-page>
    <header class="ll-wordset-hero">
        <div class="ll-wordset-hero__icon" aria-hidden="true">
            <span class="ll-wordset-hero__dot"></span>
            <span class="ll-wordset-hero__dot"></span>
            <span class="ll-wordset-hero__dot"></span>
            <span class="ll-wordset-hero__dot"></span>
        </div>
        <h1 class="ll-wordset-title"><?php echo esc_html($wordset->name); ?></h1>
    </header>

    <?php if (empty($categories)) : ?>
        <div class="ll-wordset-empty">
            <?php echo esc_html__('No lesson categories yet.', 'll-tools-text-domain'); ?>
        </div>
    <?php else : ?>
        <div class="ll-wordset-grid" role="list">
            <?php foreach ($categories as $cat) : ?>
                <a class="ll-wordset-card" href="<?php echo esc_url($cat['url']); ?>" role="listitem" aria-label="<?php echo esc_attr($cat['name']); ?>">
                    <div class="ll-wordset-card__preview <?php echo $cat['has_images'] ? 'has-images' : 'has-text'; ?>">
                        <?php if (!empty($cat['preview'])) : ?>
                            <?php
                            $preview_items = array_values((array) $cat['preview']);
                            $preview_count = count($preview_items);
                            $preview_limit = 2;
                            ?>
                            <?php foreach (array_slice($preview_items, 0, $preview_limit) as $preview) : ?>
                                <?php if (($preview['type'] ?? '') === 'image') : ?>
                                    <span class="ll-wordset-preview-item ll-wordset-preview-item--image">
                                        <img src="<?php echo esc_url($preview['url']); ?>" alt="<?php echo esc_attr($preview['alt'] ?? ''); ?>" loading="lazy" />
                                    </span>
                                <?php else : ?>
                                    <span class="ll-wordset-preview-item ll-wordset-preview-item--text">
                                        <span class="ll-wordset-preview-text"><?php echo esc_html($preview['label'] ?? ''); ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php for ($i = $preview_count; $i < $preview_limit; $i++) : ?>
                                <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                            <?php endfor; ?>
                        <?php else : ?>
                            <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                            <span class="ll-wordset-preview-item ll-wordset-preview-item--empty" aria-hidden="true"></span>
                        <?php endif; ?>
                    </div>
                    <div class="ll-wordset-card__meta">
                        <h2 class="ll-wordset-card__title"><?php echo esc_html($cat['name']); ?></h2>
                        <span class="ll-wordset-card__count" aria-label="<?php echo esc_attr(sprintf(__('Words: %d', 'll-tools-text-domain'), (int) ($cat['count'] ?? 0))); ?>">
                            <?php echo (int) ($cat['count'] ?? 0); ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<?php
get_footer();
