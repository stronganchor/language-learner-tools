<?php
/* 
 * Embed Flashcard Page Template
 * Renders a minimal, embeddable flashcard quiz for a specific word category.
 * This page is not indexed by search engines.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Embed Flashcard</title>
    <?php wp_head(); ?>
    <style>
        body { margin: 0; padding: 0; }
    </style>
</head>
<body>
<?php
$embed_category = get_query_var('embed_category');
echo do_shortcode('[flashcard_widget category="' . esc_attr($embed_category) . '" mode="random"]');
wp_footer();
?>
</body>
</html>
