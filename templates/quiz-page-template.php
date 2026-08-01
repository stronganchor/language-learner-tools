<?php
if (!defined('WPINC')) { die; }

// templates/quiz-page-template.php
// Expected vars: $vh, $src, $display_name, $slug, $iframe_title, and iframe state/action labels.
$status_id = 'll-tools-quiz-iframe-status-' . sanitize_html_class((string) $slug);
?>
<div class="ll-tools-quiz-wrapper">
  <h1 class="ll-quiz-page-title"><?php echo ll_tools_esc_html_display($display_name); ?></h1>
  <div
    class="ll-tools-quiz-iframe-wrapper"
    style="min-height: <?php echo (int)$vh; ?>vh"
    data-quiz-slug="<?php echo esc_attr($slug); ?>"
    data-quiz-src="<?php echo esc_url($src); ?>"
    data-iframe-state="loading"
    aria-busy="true"
  >
    <div class="ll-tools-iframe-state">
      <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
      <div id="<?php echo esc_attr($status_id); ?>" class="ll-tools-iframe-loading-status" role="status" aria-live="polite" aria-atomic="true">
        <?php echo esc_html(isset($loading_status) ? (string) $loading_status : __('Loading quiz...', 'll-tools-text-domain')); ?>
      </div>
      <div class="ll-tools-iframe-recovery" hidden>
        <button type="button" class="ll-tools-iframe-retry">
          <?php echo esc_html(isset($retry_label) ? (string) $retry_label : __('Retry', 'll-tools-text-domain')); ?>
        </button>
        <a
          class="ll-tools-iframe-open-direct"
          href="<?php echo esc_url($src); ?>"
          target="_blank"
          rel="noopener noreferrer"
        >
          <?php echo esc_html(isset($open_direct_label) ? (string) $open_direct_label : __('Open quiz in a new tab', 'll-tools-text-domain')); ?>
        </a>
      </div>
    </div>
    <iframe class="ll-tools-quiz-iframe"
            src="<?php echo esc_url($src); ?>"
            title="<?php echo esc_attr(isset($iframe_title) ? (string) $iframe_title : __('Quiz Content', 'll-tools-text-domain')); ?>"
            aria-describedby="<?php echo esc_attr($status_id); ?>"
            aria-busy="true"
            style="height: <?php echo (int)$vh; ?>vh; min-height: <?php echo (int)$vh; ?>vh"
            loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>
  </div>
</div>
