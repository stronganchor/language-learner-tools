<?php
// templates/quiz-page-template.php
// Expected vars: $vh, $src, $display_name, $slug
?>
<div class="ll-tools-quiz-wrapper">
  <h1 class="ll-quiz-page-title"><?php echo esc_html($display_name); ?></h1>
  <div class="ll-tools-quiz-iframe-wrapper" style="min-height: <?php echo (int)$vh; ?>vh" data-quiz-slug="<?php echo esc_attr($slug); ?>">
    <div class="ll-tools-iframe-loading" aria-hidden="true"></div>
    <iframe class="ll-tools-quiz-iframe"
            src="<?php echo esc_url($src); ?>"
            style="height: <?php echo (int)$vh; ?>vh; min-height: <?php echo (int)$vh; ?>vh"
            loading="lazy" allow="autoplay" referrerpolicy="no-referrer-when-downgrade"></iframe>
  </div>
</div>

<script>
(function(){
  var wrapper = document.querySelector('.ll-tools-quiz-iframe-wrapper[data-quiz-slug="<?php echo esc_js($slug); ?>"]');
  if (!wrapper) return;

  var spinner = wrapper.querySelector('.ll-tools-iframe-loading');
  var iframe  = wrapper.querySelector('.ll-tools-quiz-iframe');

  function hideSpinner(){
    if (spinner) spinner.style.display = 'none';
  }

  // Fallback: hide when iframe finishes loading the /embed/<slug> page
  if (iframe) {
    iframe.addEventListener('load', hideSpinner, { once: true });
  }

  // Preferred: hide when the embedded widget signals readiness
  window.addEventListener('message', function(ev){
    var data = ev && ev.data;
    if (!data) return;
    if (data.type === 'll-embed-ready' || data.type === 'LL_EMBED_READY') {
      hideSpinner();
    }
  });
})();
</script>
