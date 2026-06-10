<?php
if (!defined('WPINC')) { die; }

function ll_tools_flashcard_shell_default_mode_icons(): array {
    return [
        'learning' => html_entity_decode('&#127891;', ENT_QUOTES, 'UTF-8'),
        'practice' => html_entity_decode('&#10067;', ENT_QUOTES, 'UTF-8'),
        'self-check' => html_entity_decode('&#10004;&#10006;', ENT_QUOTES, 'UTF-8'),
        'gender' => html_entity_decode('&#9893;', ENT_QUOTES, 'UTF-8'),
        'listening' => html_entity_decode('&#127911;', ENT_QUOTES, 'UTF-8'),
        'switch' => html_entity_decode('&#8644;', ENT_QUOTES, 'UTF-8'),
    ];
}

function ll_tools_flashcard_shell_mode_icon_html(array $cfg, string $fallback, string $class = 'mode-icon'): string {
    if (!empty($cfg['svg'])) {
        return '<span class="' . esc_attr($class) . '" aria-hidden="true">' . $cfg['svg'] . '</span>';
    }

    $icon = !empty($cfg['icon']) ? (string) $cfg['icon'] : $fallback;
    return '<span class="' . esc_attr($class) . '" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
}

function ll_tools_flashcard_shell_echo_display_text(string $text): void {
    if (function_exists('ll_tools_esc_html_display')) {
        echo ll_tools_esc_html_display($text);
        return;
    }

    echo esc_html($text);
}

function ll_tools_render_flashcard_overlay_shell(array $args = []): void {
    $mode_ui = isset($args['mode_ui']) && is_array($args['mode_ui']) ? $args['mode_ui'] : [];
    $practice_mode_ui = $mode_ui['practice'] ?? [];
    $learning_mode_ui = $mode_ui['learning'] ?? [];
    $self_check_mode_ui = $mode_ui['self-check'] ?? [];
    $listening_mode_ui = $mode_ui['listening'] ?? [];
    $gender_mode_ui = $mode_ui['gender'] ?? [];
    $fallback_icons = ll_tools_flashcard_shell_default_mode_icons();

    $include_category_selection = array_key_exists('include_category_selection', $args)
        ? !empty($args['include_category_selection'])
        : true;
    $include_loading_status = !empty($args['include_loading_status']);
    $show_category_display = array_key_exists('show_category_display', $args)
        ? !empty($args['show_category_display'])
        : true;
    $category_label_text = isset($args['category_label_text']) ? (string) $args['category_label_text'] : '';
    $gender_mode_visible = !empty($args['gender_mode_visible']);
    $mode_order = isset($args['mode_order']) && is_array($args['mode_order'])
        ? array_values(array_filter(array_map('strval', $args['mode_order'])))
        : ['learning', 'practice', 'self-check', 'gender', 'listening'];
    $listening_results_fallback = $args['listening_results_fallback'] ?? __('Listen', 'll-tools-text-domain');

    $mode_configs = [
        'learning' => [
            'class' => 'learning',
            'label' => $learning_mode_ui['switchLabel'] ?? __('Learn', 'll-tools-text-domain'),
            'ui' => $learning_mode_ui,
            'fallback' => $fallback_icons['learning'],
        ],
        'practice' => [
            'class' => 'practice',
            'label' => $practice_mode_ui['switchLabel'] ?? __('Practice', 'll-tools-text-domain'),
            'ui' => $practice_mode_ui,
            'fallback' => $fallback_icons['practice'],
        ],
        'self-check' => [
            'class' => 'self-check',
            'label' => $self_check_mode_ui['switchLabel'] ?? __('Self check', 'll-tools-text-domain'),
            'ui' => $self_check_mode_ui,
            'fallback' => $fallback_icons['self-check'],
        ],
        'gender' => [
            'class' => 'gender' . ($gender_mode_visible ? '' : ' hidden'),
            'label' => $gender_mode_ui['switchLabel'] ?? __('Gender', 'll-tools-text-domain'),
            'ui' => $gender_mode_ui,
            'fallback' => $fallback_icons['gender'],
            'hidden' => !$gender_mode_visible,
        ],
        'listening' => [
            'class' => 'listening',
            'label' => $listening_mode_ui['switchLabel'] ?? __('Listening', 'll-tools-text-domain'),
            'ui' => $listening_mode_ui,
            'fallback' => $fallback_icons['listening'],
        ],
    ];

    $practice_results_label = $practice_mode_ui['resultsButtonText'] ?? __('Practice', 'll-tools-text-domain');
    $learning_results_label = $learning_mode_ui['resultsButtonText'] ?? __('Learn', 'll-tools-text-domain');
    $self_check_results_label = $self_check_mode_ui['resultsButtonText'] ?? __('Self check', 'll-tools-text-domain');
    $listening_results_label = $listening_mode_ui['resultsButtonText'] ?? $listening_results_fallback;
    $gender_results_label = $gender_mode_ui['resultsButtonText'] ?? __('Gender', 'll-tools-text-domain');
    ?>
    <div id="ll-tools-flashcard-popup" style="display:none;">
      <?php if ($include_category_selection) : ?>
        <div id="ll-tools-category-selection-popup" style="display:none;">
          <h3 class="ll-tools-category-selection-title"><?php echo esc_html__('Categories', 'll-tools-text-domain'); ?></h3>
          <div class="ll-tools-category-selection-buttons">
            <button id="ll-tools-uncheck-all" type="button"><?php echo esc_html__('Deselect all', 'll-tools-text-domain'); ?></button>
            <button id="ll-tools-check-all" type="button"><?php echo esc_html__('Select all', 'll-tools-text-domain'); ?></button>
          </div>
          <div id="ll-tools-category-checkboxes-container">
            <div id="ll-tools-category-checkboxes"></div>
          </div>
          <button id="ll-tools-start-selected-quiz" type="button"><?php echo esc_html__('Start', 'll-tools-text-domain'); ?></button>
          <button id="ll-tools-close-category-selection" type="button" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>
        </div>
      <?php endif; ?>

      <div id="ll-tools-flashcard-quiz-popup" style="display:none;">
        <button id="ll-tools-close-flashcard" type="button" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>
        <div id="ll-tools-flashcard-header" style="display:none;">
          <div id="ll-tools-learning-progress" style="display:none;"></div>

          <div id="ll-tools-category-stack" class="ll-tools-category-stack">
            <?php if ($show_category_display) : ?>
              <span id="ll-tools-category-display" class="ll-tools-category-display">
                <?php ll_tools_flashcard_shell_echo_display_text($category_label_text); ?>
              </span>
            <?php endif; ?>
            <button id="ll-tools-repeat-flashcard" class="play-mode" type="button" aria-label="<?php echo esc_attr__('Play', 'll-tools-text-domain'); ?>"></button>
          </div>
        </div>

        <div id="ll-tools-loading-animation" class="ll-tools-loading-animation" aria-hidden="true"></div>
        <?php if ($include_loading_status) : ?>
          <div id="ll-tools-loading-status" class="screen-reader-text" role="status" aria-live="polite" hidden>
            <?php echo esc_html__('Loading quiz...', 'll-tools-text-domain'); ?>
          </div>
        <?php endif; ?>

        <div id="ll-tools-flashcard-content">
          <div id="ll-tools-prompt" class="ll-tools-prompt" style="display:none;"></div>
          <div id="ll-tools-flashcard"></div>
          <audio controls class="hidden"></audio>
        </div>

        <div id="ll-tools-mode-switcher-wrap" class="ll-tools-mode-switcher-wrap" style="display:none;" aria-expanded="false">
          <div id="ll-tools-mode-menu" class="ll-tools-mode-menu" role="menu" aria-hidden="true">
            <?php foreach ($mode_order as $mode_slug) :
                if (!isset($mode_configs[$mode_slug])) {
                    continue;
                }
                $mode_config = $mode_configs[$mode_slug];
                ?>
                <button class="ll-tools-mode-option <?php echo esc_attr($mode_config['class']); ?>" role="menuitemradio" aria-label="<?php echo esc_attr($mode_config['label']); ?>" data-mode="<?php echo esc_attr($mode_slug); ?>" type="button"<?php echo !empty($mode_config['hidden']) ? ' aria-hidden="true"' : ''; ?>>
                  <?php echo ll_tools_flashcard_shell_mode_icon_html($mode_config['ui'], $mode_config['fallback'], 'mode-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </button>
            <?php endforeach; ?>
          </div>
          <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" type="button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>">
            <span class="mode-icon" aria-hidden="true"><?php echo esc_html($fallback_icons['switch']); ?></span>
          </button>
        </div>

        <div id="quiz-results" style="display:none;">
          <h2 id="quiz-results-title"><?php echo esc_html__('Quiz Results', 'll-tools-text-domain'); ?></h2>
          <p id="quiz-results-message" style="display:none;"></p>
          <p class="ll-quiz-results-score">
            <strong><?php echo esc_html__('Correct', 'll-tools-text-domain'); ?>:</strong>
            <span id="correct-count">0</span> / <span id="total-questions">0</span>
          </p>
          <p id="quiz-results-categories" style="margin-top:10px;display:none;"></p>
          <div id="ll-gender-results-progress" style="display:none; margin-top: 14px;"></div>
          <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
            <button id="restart-practice-mode" class="quiz-button quiz-mode-button" type="button">
              <?php echo ll_tools_flashcard_shell_mode_icon_html($practice_mode_ui, $fallback_icons['practice'], 'button-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <?php echo esc_html($practice_results_label); ?>
            </button>
            <button id="restart-learning-mode" class="quiz-button quiz-mode-button" type="button">
              <?php echo ll_tools_flashcard_shell_mode_icon_html($learning_mode_ui, $fallback_icons['learning'], 'button-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <span class="ll-learning-results-label"><?php echo esc_html($learning_results_label); ?></span>
            </button>
            <button id="restart-self-check-mode" class="quiz-button quiz-mode-button" type="button">
              <?php echo ll_tools_flashcard_shell_mode_icon_html($self_check_mode_ui, $fallback_icons['self-check'], 'button-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <?php echo esc_html($self_check_results_label); ?>
            </button>
            <button id="restart-gender-mode" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo ll_tools_flashcard_shell_mode_icon_html($gender_mode_ui, $fallback_icons['gender'], 'button-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <span class="ll-gender-results-label"><?php echo esc_html($gender_results_label); ?></span>
            </button>
            <button id="restart-listening-mode" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo ll_tools_flashcard_shell_mode_icon_html($listening_mode_ui, $fallback_icons['listening'], 'button-icon'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
              <?php echo esc_html($listening_results_label); ?>
            </button>
          </div>
          <div id="ll-gender-results-actions" style="display:none; margin-top: 12px;">
            <button id="ll-gender-next-activity" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo esc_html__('Next', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-gender-next-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo esc_html__('Next Set', 'll-tools-text-domain'); ?>
            </button>
          </div>
          <div id="ll-study-results-actions" style="display:none; margin-top: 12px;">
            <p id="ll-study-results-suggestion" style="display:none; margin: 0 0 8px 0;"></p>
            <button id="ll-study-results-same-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo esc_html__('Repeat', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-study-results-different-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo esc_html__('Categories', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-study-results-next-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
              <?php echo esc_html__('Recommended', 'll-tools-text-domain'); ?>
            </button>
          </div>
          <button id="restart-quiz" class="quiz-button" type="button" style="display:none;"><?php echo esc_html__('Replay', 'll-tools-text-domain'); ?></button>
        </div>
      </div>
    </div>
    <?php
}

function ll_tools_render_flashcard_repeat_button_init_script(): void {
    ?>
    <script>
    (function() {
      if (window.__LL_FLASHCARD_REPEAT_ICON_INIT_BOUND) {
        return;
      }
      window.__LL_FLASHCARD_REPEAT_ICON_INIT_BOUND = true;
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
    <?php
}
