<?php
/* 
 * Embed Flashcard Page Template
 * Renders a minimal, embeddable flashcard quiz for a specific word category.
 * This page is not indexed by search engines.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php wp_title(''); ?></title>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
    <div class="entry-content" style="display: flex; justify-content: center; align-items: center; min-height: 100vh;">
        <?php
        $embed_category = get_query_var('embed_category');
        echo do_shortcode('[flashcard_widget category="' . esc_attr($embed_category) . '"]');
        ?>
    </div>
    <?php wp_footer(); ?>
</body>
</html>
