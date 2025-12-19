<?php
// Vars: $embed (bool), $category_label_text (string), $quiz_font (string), $mode_ui (array)
$mode_ui = (isset($mode_ui) && is_array($mode_ui)) ? $mode_ui : [];
$practice_mode_ui = $mode_ui['practice'] ?? [];
$learning_mode_ui = $mode_ui['learning'] ?? [];
$listening_mode_ui = $mode_ui['listening'] ?? [];
?>
<?php if (!empty($quiz_font)): ?>
<style>
  #ll-tools-flashcard .text-based { font-family: "<?php echo esc_attr($quiz_font); ?>", sans-serif; }
</style>
<?php endif; ?>

<?php
$tmpl_wordset = isset($wordset) ? (string) $wordset : '';
$tmpl_wordset_fallback = !empty($wordset_fallback);
$tmpl_ll_config = isset($ll_config) && is_array($ll_config) ? $ll_config : [];
$tmpl_ll_config_json = wp_json_encode($tmpl_ll_config);
?>
<div id="ll-tools-flashcard-container"
     class="ll-tools-flashcard-container"
     data-wordset="<?php echo esc_attr($tmpl_wordset); ?>"
     data-wordset-fallback="<?php echo $tmpl_wordset_fallback ? '1' : '0'; ?>"
     data-ll-config="<?php echo esc_attr($tmpl_ll_config_json); ?>">
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
        <div id="ll-tools-prompt" class="ll-tools-prompt" style="display:none;"></div>
        <div id="ll-tools-flashcard"></div>
        <audio controls class="hidden"></audio>
      </div>

      <!-- Mode switcher: single toggle that expands to 3 fixed-order options -->
      <?php
        $practice_label = $practice_mode_ui['switchLabel'] ?? __('Switch to Practice Mode', 'll-tools-text-domain');
        $learning_label = $learning_mode_ui['switchLabel'] ?? __('Switch to Learning Mode', 'll-tools-text-domain');
        $listening_label = $listening_mode_ui['switchLabel'] ?? __('Switch to Listening Mode', 'll-tools-text-domain');
        $settings_label = __('Study Settings', 'll-tools-text-domain');
      ?>
      <div id="ll-tools-mode-switcher-wrap" class="ll-tools-mode-switcher-wrap" style="display:none;" aria-expanded="false">
        <?php if (is_user_logged_in()): ?>
          <div id="ll-tools-settings-control" class="ll-tools-settings-control">
            <button id="ll-tools-settings-button" class="ll-tools-settings-button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr($settings_label); ?>">
              <span class="mode-icon" aria-hidden="true">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M19.4 12.98c.04-.32.06-.65.06-.98 0-.33-.02-.66-.06-.98l1.73-1.35a.5.5 0 0 0 .12-.64l-1.64-2.84a.5.5 0 0 0-.6-.22l-2.04.82a7.1 7.1 0 0 0-1.7-.98l-.26-2.17A.5.5 0 0 0 14.5 3h-5a.5.5 0 0 0-.5.43l-.26 2.17c-.6.24-1.17.55-1.7.93l-2.04-.82a.5.5 0 0 0-.6.22L2.76 8.58a.5.5 0 0 0 .12.64L4.6 10.57c-.04.32-.06.65-.06.98 0 .33.02.66.06.98l-1.73 1.35a.5.5 0 0 0-.12.64l1.64 2.84a.5.5 0 0 0 .6.22l2.04-.82c.53.38 1.1.69 1.7.93l.26 2.17a.5.5 0 0 0 .5.43h5a.5.5 0 0 0 .5-.43l.26-2.17c.6-.24 1.17-.55 1.7-.93l2.04.82a.5.5 0 0 0 .6-.22l1.64-2.84a.5.5 0 0 0-.12-.64l-1.73-1.35Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </span>
            </button>
            <div id="ll-tools-settings-panel" class="ll-tools-settings-panel" role="dialog" aria-label="<?php echo esc_attr($settings_label); ?>" aria-hidden="true">
              <div class="ll-tools-settings-section">
                <div class="ll-tools-settings-heading"><?php echo esc_html__('Star preference', 'll-tools-text-domain'); ?></div>
                <div class="ll-tools-settings-options" role="group" aria-label="<?php echo esc_attr__('Star preference', 'll-tools-text-domain'); ?>">
                  <button type="button" class="ll-tools-settings-option" data-star-mode="normal"><?php echo esc_html__('Normal mix', 'll-tools-text-domain'); ?></button>
                  <button type="button" class="ll-tools-settings-option" data-star-mode="weighted"><?php echo esc_html__('Favor starred', 'll-tools-text-domain'); ?></button>
                  <button type="button" class="ll-tools-settings-option" data-star-mode="only"><?php echo esc_html__('Starred only', 'll-tools-text-domain'); ?></button>
                </div>
              </div>
              <div class="ll-tools-settings-section">
                <div class="ll-tools-settings-heading"><?php echo esc_html__('Transition speed', 'll-tools-text-domain'); ?></div>
                <div class="ll-tools-settings-options" role="group" aria-label="<?php echo esc_attr__('Transition speed', 'll-tools-text-domain'); ?>">
                  <button type="button" class="ll-tools-settings-option" data-speed="normal"><?php echo esc_html__('Standard pace', 'll-tools-text-domain'); ?></button>
                  <button type="button" class="ll-tools-settings-option" data-speed="fast"><?php echo esc_html__('Faster transitions', 'll-tools-text-domain'); ?></button>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
        <div id="ll-tools-mode-menu" class="ll-tools-mode-menu" role="menu" aria-hidden="true">
          <!-- Fixed order: learning (top), practice (middle), listening (bottom) -->
          <button class="ll-tools-mode-option learning" role="menuitemradio" aria-label="<?php echo esc_attr($learning_label); ?>" data-mode="learning">
            <?php if (!empty($learning_mode_ui['icon'])): ?>
              <span class="mode-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($learning_mode_ui['icon']); ?>"></span>
            <?php else: ?>
              <span class="mode-icon" aria-hidden="true" data-emoji="🎓"></span>
            <?php endif; ?>
          </button>
          <button class="ll-tools-mode-option practice" role="menuitemradio" aria-label="<?php echo esc_attr($practice_label); ?>" data-mode="practice">
            <?php if (!empty($practice_mode_ui['icon'])): ?>
              <span class="mode-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($practice_mode_ui['icon']); ?>"></span>
            <?php else: ?>
              <span class="mode-icon" aria-hidden="true" data-emoji="❓"></span>
            <?php endif; ?>
          </button>
          <button class="ll-tools-mode-option listening" role="menuitemradio" aria-label="<?php echo esc_attr($listening_label); ?>" data-mode="listening">
            <?php if (!empty($listening_mode_ui['icon'])): ?>
              <span class="mode-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($listening_mode_ui['icon']); ?>"></span>
            <?php else: ?>
              <span class="mode-icon" aria-hidden="true" data-emoji="🎧"></span>
            <?php endif; ?>
          </button>
        </div>
        <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>">
          <span class="mode-icon" aria-hidden="true">⇄</span>
        </button>
      </div>

      <div id="quiz-results" style="display:none;">
        <h2 id="quiz-results-title"><?php echo esc_html__('Quiz Results', 'll-tools-text-domain'); ?></h2>
        <p id="quiz-results-message" style="display:none;"></p>
        <p>
          <strong><?php echo esc_html__('Correct:', 'll-tools-text-domain'); ?></strong>
          <span id="correct-count">0</span> / <span id="total-questions">0</span>
        </p>
        <p id="quiz-results-categories" style="margin-top:10px;display:none;"></p>
        <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
          <?php
            $practice_label = $practice_mode_ui['resultsButtonText'] ?? __('Practice Mode', 'll-tools-text-domain');
            $learning_label = $learning_mode_ui['resultsButtonText'] ?? __('Learning Mode', 'll-tools-text-domain');
            $listening_label = $listening_mode_ui['resultsButtonText'] ?? __('Replay Listening', 'll-tools-text-domain');
          ?>
          <button id="restart-practice-mode" class="quiz-button quiz-mode-button">
            <?php if (!empty($practice_mode_ui['icon'])): ?>
              <span class="button-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($practice_mode_ui['icon']); ?>"></span>
            <?php endif; ?>
            <?php echo esc_html($practice_label); ?>
          </button>
          <button id="restart-learning-mode" class="quiz-button quiz-mode-button">
            <?php if (!empty($learning_mode_ui['icon'])): ?>
              <span class="button-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($learning_mode_ui['icon']); ?>"></span>
            <?php endif; ?>
            <?php echo esc_html($learning_label); ?>
          </button>
          <button id="restart-listening-mode" class="quiz-button quiz-mode-button" style="display:none;">
            <?php if (!empty($listening_mode_ui['icon'])): ?>
              <span class="button-icon" aria-hidden="true" data-emoji="<?php echo esc_attr($listening_mode_ui['icon']); ?>"></span>
            <?php endif; ?>
            <?php echo esc_html($listening_label); ?>
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
    if (window.LLFlashcards && window.LLFlashcards.Dom && typeof window.LLFlashcards.Dom.setRepeatButton === 'function') {
      window.LLFlashcards.Dom.setRepeatButton('play');
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
