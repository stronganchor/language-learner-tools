<?php
// templates/quiz-page-template.php
// Expected vars: $vh, $src, $display_name, $slug
?>
<div class="ll-tools-quiz-wrapper">
  <h1 class="ll-quiz-page-title"><?php echo ll_tools_esc_html_display($display_name); ?></h1>
  <div class="ll-tools-quiz-iframe-wrapper" style="min-height: <?php echo (int)$vh; ?>vh" data-quiz-slug="<?php echo esc_attr($slug); ?>">
    <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
    <iframe class="ll-tools-quiz-iframe"
            src="<?php echo esc_url($src); ?>"
            style="height: <?php echo (int)$vh; ?>vh; min-height: <?php echo (int)$vh; ?>vh"
            loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>
  </div>
</div>
