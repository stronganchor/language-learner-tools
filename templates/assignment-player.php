<?php
if (!defined('WPINC')) { die; }
get_header();
?>
<main class="ll-assignment-player" id="ll-assignment-player" aria-busy="true">
    <h1 class="ll-assignment-player__title"><?php esc_html_e('Vocabulary assignment', 'll-tools-text-domain'); ?></h1>
    <p class="ll-assignment-player__status" role="status" aria-live="polite"><?php esc_html_e('Loading assignment…', 'll-tools-text-domain'); ?></p>
    <div class="ll-assignment-player__content"></div>
    <noscript><p><?php esc_html_e('Enable JavaScript to answer this assignment.', 'll-tools-text-domain'); ?></p></noscript>
</main>
<?php get_footer(); ?>
