<?php
// Vars: $embed (bool), $category_label_text (string), $quiz_font (string)
?>
<?php if (!empty($quiz_font)): ?>
<style>
  #ll-tools-flashcard .text-based { font-family: "<?php echo esc_attr($quiz_font); ?>", sans-serif; }
</style>
<?php endif; ?>

<div id="ll-tools-flashcard-container">
  <?php if (!$embed): ?>
    <button id="ll-tools-start-flashcard"><?php echo esc_html__('Start', 'll-tools-text-domain'); ?></button>
  <?php endif; ?>

  <div id="ll-tools-flashcard-popup" style="display:none;">
    <div id="ll-tools-category-selection-popup" style="display:none;">
      <h3><?php echo esc_html__('Select Categories', 'll-tools-text-domain'); ?></h3>
      <div class="ll-tools-category-selection-buttons">
        <button id="ll-tools-uncheck-all"><?php echo esc_html__('Uncheck All', 'll-tools-text-domain'); ?></button>
        <button id="ll-tools-check-all"><?php echo esc_html__('Check All', 'll-tools-text-domain'); ?></button>
      </div>
      <div id="ll-tools-category-checkboxes-container">
        <div id="ll-tools-category-checkboxes"></div>
      </div>
      <button id="ll-tools-start-selected-quiz"><?php echo esc_html__('Start Quiz', 'll-tools-text-domain'); ?></button>
      <button id="ll-tools-close-category-selection" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>
    </div>

    <div id="ll-tools-flashcard-quiz-popup" style="display:none;">
      <div id="ll-tools-flashcard-header" style="display:none;">
        <button id="ll-tools-close-flashcard" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>

        <div id="ll-tools-learning-progress" style="display:none;"></div>

        <div id="ll-tools-category-stack" class="ll-tools-category-stack">
          <?php if (!$embed): ?>
          <span id="ll-tools-category-display" class="ll-tools-category-display">
            <?php echo esc_html($category_label_text); ?>
          </span>
          <?php endif; ?>
          <button id="ll-tools-repeat-flashcard" class="play-mode" aria-label="<?php echo esc_attr__('Play', 'll-tools-text-domain'); ?>">
          </button>
        </div>

        <div id="ll-tools-loading-animation" class="ll-tools-loading-animation" aria-hidden="true"></div>
      </div>

      <div id="ll-tools-flashcard-content">
        <div id="ll-tools-flashcard"></div>
        <audio controls class="hidden"></audio>
      </div>

      <!-- Mode switch buttons (stacked individually) -->
      <button id="ll-tools-mode-switcher-alt" class="ll-tools-mode-switcher" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>" style="display:none;">
        <span class="mode-icon"></span>
      </button>
      <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>" style="display:none;">
        <span class="mode-icon"></span>
      </button>

      <div id="quiz-results" style="display:none;">
        <h2 id="quiz-results-title"><?php echo esc_html__('Quiz Results', 'll-tools-text-domain'); ?></h2>
        <p id="quiz-results-message" style="display:none;"></p>
        <p>
          <strong><?php echo esc_html__('Correct:', 'll-tools-text-domain'); ?></strong>
          <span id="correct-count">0</span> / <span id="total-questions">0</span>
        </p>
        <p id="quiz-results-categories" style="margin-top:10px;display:none;"></p>
        <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
          <button id="restart-practice-mode" class="quiz-button quiz-mode-button">
            <span class="button-icon">❓</span>
            <?php echo esc_html__('Practice Mode', 'll-tools-text-domain'); ?>
          </button>
          <button id="restart-learning-mode" class="quiz-button quiz-mode-button">
            <span class="button-icon">🎓</span>
            <?php echo esc_html__('Learning Mode', 'll-tools-text-domain'); ?>
          </button>
          <button id="restart-listening-mode" class="quiz-button quiz-mode-button" style="display:none;">
            <?php echo esc_html__('Replay Listening', 'll-tools-text-domain'); ?>
          </button>
        </div>
        <button id="restart-quiz" class="quiz-button" style="display:none;"><?php echo esc_html__('Restart Quiz', 'll-tools-text-domain'); ?></button>
      </div>
    </div>
  </div>
<script>
(function() {
  // Initialize the play button icon once DOM module is loaded
  function initPlayIcon() {
    if (window.LLFlashcards && window.LLFlashcards.Dom) {
      var btn = document.getElementById('ll-tools-repeat-flashcard');
      if (btn && !btn.querySelector('.icon-container')) {
        btn.innerHTML = window.LLFlashcards.Dom.getPlayIconHTML();
      }
    } else {
      setTimeout(initPlayIcon, 50);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPlayIcon);
  } else {
    initPlayIcon();
  }
})();
</script>
</div>
