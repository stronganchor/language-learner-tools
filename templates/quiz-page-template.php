<?php
if (!defined('WPINC')) { die; }

// templates/quiz-page-template.php
// Expected vars: $vh, $src, $display_name, $slug, $iframe_title, $loading_status
?>
<div class="ll-tools-quiz-wrapper">
  <h1 class="ll-quiz-page-title"><?php echo ll_tools_esc_html_display($display_name); ?></h1>
  <div class="ll-tools-quiz-iframe-wrapper" style="min-height: <?php echo (int)$vh; ?>vh" data-quiz-slug="<?php echo esc_attr($slug); ?>">
    <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
    <div class="ll-tools-iframe-loading-status screen-reader-text" role="status" aria-live="polite">
      <?php echo esc_html(isset($loading_status) ? (string) $loading_status : __('Loading quiz...', 'll-tools-text-domain')); ?>
    </div>
    <iframe class="ll-tools-quiz-iframe"
            src="<?php echo esc_url($src); ?>"
            title="<?php echo esc_attr(isset($iframe_title) ? (string) $iframe_title : __('Quiz Content', 'll-tools-text-domain')); ?>"
            style="height: <?php echo (int)$vh; ?>vh; min-height: <?php echo (int)$vh; ?>vh"
            loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>
  </div>
</div>
