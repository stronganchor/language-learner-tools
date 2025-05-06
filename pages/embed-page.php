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
    <style>
        /* Transparent background for embedded iframe quiz */
        html, body, #ll-tools-flashcard-popup, #ll-tools-flashcard-quiz-popup {
            background: transparent !important;
        }
    </style>
</head>
<body <?php body_class(); ?> style="background: transparent;">
    <div class="entry-content" style="display: flex; justify-content: center; align-items: center; min-height: 100vh;">
        <?php
        $embed_category = get_query_var('embed_category');
        echo do_shortcode('[flashcard_widget category="' . esc_attr($embed_category) . '"]');
        ?>
    </div>
    <?php wp_footer(); ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Remove default start/close controls
        $('#ll-tools-start-flashcard, #ll-tools-close-flashcard').remove();
        // Display quiz popup immediately
        $('#ll-tools-flashcard-popup, #ll-tools-flashcard-quiz-popup').show();
        $('body').addClass('ll-tools-flashcard-open');
        // Launch directly into quiz
        var preselectedCategories = llToolsFlashcardsData.categories.map(function(cat) { return cat.name; });
        initFlashcardWidget(preselectedCategories);
    });
    </script>
</body>
</html>
