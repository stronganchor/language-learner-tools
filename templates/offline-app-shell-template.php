<?php
if (!defined('WPINC')) { die; }

$app_title = isset($app_title) ? (string) $app_title : __('Offline Quiz', 'll-tools-text-domain');
$wordset_name = isset($wordset_name) ? (string) $wordset_name : '';
$styles = isset($styles) && is_array($styles) ? $styles : [];
$scripts = isset($scripts) && is_array($scripts) ? $scripts : [];
$warnings = isset($warnings) && is_array($warnings) ? array_values(array_filter(array_map('strval', $warnings))) : [];
$startup_mode = isset($startup_mode) ? (string) $startup_mode : 'practice';
$mode_ui = isset($mode_ui) && is_array($mode_ui) ? $mode_ui : [];
$practice_mode_ui = $mode_ui['practice'] ?? [];
$learning_mode_ui = $mode_ui['learning'] ?? [];
$self_check_mode_ui = $mode_ui['self-check'] ?? [];
$listening_mode_ui = $mode_ui['listening'] ?? [];
$gender_mode_ui = $mode_ui['gender'] ?? [];
$ll_config = isset($ll_config) && is_array($ll_config) ? $ll_config : [];
$ll_config_json = wp_json_encode($ll_config);
$gender_options = isset($ll_config['genderOptions']) && is_array($ll_config['genderOptions'])
  ? array_values(array_filter(array_map('strval', $ll_config['genderOptions']), static function ($option): bool {
      return $option !== '';
  }))
  : [];
$gender_mode_visible = !empty($ll_config['genderEnabled']) && count($gender_options) >= 2;
$render_mode_icon = static function (array $cfg, string $fallback, string $class = 'mode-icon'): void {
  if (!empty($cfg['svg'])) {
    echo '<span class="' . esc_attr($class) . '" aria-hidden="true">' . $cfg['svg'] . '</span>';
    return;
  }

  $icon = !empty($cfg['icon']) ? $cfg['icon'] : $fallback;
  echo '<span class="' . esc_attr($class) . '" aria-hidden="true" data-emoji="' . esc_attr($icon) . '"></span>';
};
$escape_asset_url = static function ($asset): string {
  $asset = trim((string) $asset);
  if ($asset === '') {
    return '';
  }

  if (preg_match('#^(?:\./|\../|/)#', $asset)) {
    return esc_attr($asset);
  }

  $scheme = wp_parse_url($asset, PHP_URL_SCHEME);
  if (is_string($scheme) && $scheme !== '') {
    return esc_url($asset);
  }

  return esc_attr($asset);
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo esc_html($app_title); ?></title>
  <?php foreach ($styles as $href) : ?>
    <link rel="stylesheet" href="<?php echo $escape_asset_url($href); ?>">
  <?php endforeach; ?>
  <style>
    :root {
      --ll-offline-bg: linear-gradient(180deg, #f4efe7 0%, #efe7d8 100%);
      --ll-offline-ink: #17202b;
      --ll-offline-accent: #1f5f4a;
      --ll-offline-soft: rgba(255,255,255,0.74);
      --ll-offline-border: rgba(23,32,43,0.11);
    }
    html, body {
      min-height: 100%;
      margin: 0;
      background: var(--ll-offline-bg);
      color: var(--ll-offline-ink);
    }
    body {
      font-family: Georgia, "Times New Roman", serif;
    }
    .ll-wordset-page.ll-wordset-page--offline {
      --ll-wp-main-shell-max-width: 960px;
      min-height: 100vh;
      background: transparent;
    }
    .ll-wordset-page.ll-wordset-page--offline #ll-tools-flashcard-container {
      max-width: none;
      margin: 0;
    }
    .ll-wordset-page.ll-wordset-page--offline .ll-offline-app-header,
    .ll-wordset-page.ll-wordset-page--offline .ll-offline-warning-list,
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-grid-tools,
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-grid,
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-empty {
      max-width: var(--ll-wp-main-shell-max-width);
      margin-inline: auto;
    }
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-card__heading {
      pointer-events: none;
    }
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-card__lesson-link {
      cursor: default;
    }
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-card__hide-spacer {
      visibility: hidden;
    }
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-selection-bar {
      left: 50%;
      right: auto;
      width: min(calc(100vw - 32px), var(--ll-wp-main-shell-max-width));
      transform: translateX(-50%);
      flex-wrap: wrap;
    }
    .ll-wordset-page.ll-wordset-page--offline .ll-wordset-selection-bar__text {
      font-size: 0.92rem;
      font-weight: 700;
      color: var(--ll-wp-text);
    }
    .ll-offline-app-shell [hidden] {
      display: none !important;
    }
    .ll-offline-app-shell {
      min-height: 100vh;
      padding: 18px 14px 28px;
      box-sizing: border-box;
    }
    .ll-offline-app-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin: 0 auto 14px;
      max-width: 960px;
      padding: 12px 14px;
      background: var(--ll-offline-soft);
      border: 1px solid var(--ll-offline-border);
      border-radius: 16px;
      box-shadow: 0 10px 22px rgba(23,32,43,0.06);
    }
    .ll-offline-app-title {
      margin: 0;
      font-size: 1.15rem;
      line-height: 1.2;
      letter-spacing: 0.01em;
    }
    .ll-offline-app-wordset {
      margin: 0;
      font-size: 0.88rem;
      opacity: 0.78;
    }
    .ll-offline-warning-list {
      max-width: 960px;
      margin: 0 auto 12px;
      padding: 10px 14px;
      border-radius: 14px;
      background: rgba(173, 93, 40, 0.1);
      border: 1px solid rgba(173, 93, 40, 0.18);
      font-size: 0.92rem;
    }
    .ll-offline-warning-list ul {
      margin: 8px 0 0;
      padding-left: 18px;
    }
    .ll-offline-app-shell #ll-tools-flashcard-container {
      max-width: 960px;
      margin: 0 auto;
    }
    .ll-offline-launcher {
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-bottom: 18px;
    }
    .ll-offline-launcher__selection-bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px;
      border-radius: 16px;
      border: 1px solid var(--ll-offline-border);
      background: var(--ll-offline-soft);
      box-shadow: 0 10px 22px rgba(23,32,43,0.06);
    }
    .ll-offline-launcher__selection-copy {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }
    .ll-offline-launcher__selection-text {
      margin: 0;
      font-size: 0.95rem;
      font-weight: 600;
    }
    .ll-offline-launcher__selection-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 32px;
      min-height: 32px;
      padding: 0 8px;
      border-radius: 999px;
      background: rgba(31,95,74,0.12);
      color: var(--ll-offline-accent);
      font-size: 0.9rem;
      font-weight: 700;
    }
    .ll-offline-launcher__selection-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
    }
    .ll-offline-launcher__action-content {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .ll-offline-launcher__action-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.1em;
      height: 1.1em;
      line-height: 1;
      font-size: 1em;
    }
    .ll-offline-launcher__action-icon svg {
      width: 100%;
      height: 100%;
      display: block;
    }
    .ll-offline-launcher__action,
    .ll-offline-category-card__action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      border-radius: 999px;
      border: 1px solid rgba(23,32,43,0.12);
      background: #fff;
      color: var(--ll-offline-ink);
      font-family: inherit;
      font-size: 0.95rem;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
    }
    .ll-offline-launcher__action:hover,
    .ll-offline-launcher__action:focus-visible,
    .ll-offline-category-card__action:hover,
    .ll-offline-category-card__action:focus-visible {
      border-color: rgba(31,95,74,0.42);
      box-shadow: 0 10px 20px rgba(23,32,43,0.08);
      transform: translateY(-1px);
    }
    .ll-offline-launcher__action--learning,
    .ll-offline-category-card__action--learning {
      background: var(--ll-offline-accent);
      border-color: var(--ll-offline-accent);
      color: #fff;
    }
    .ll-offline-launcher__action--learning:hover,
    .ll-offline-launcher__action--learning:focus-visible,
    .ll-offline-category-card__action--learning:hover,
    .ll-offline-category-card__action--learning:focus-visible {
      background: #184c3c;
      border-color: #184c3c;
    }
    .ll-offline-launcher__action--select-all {
      background: transparent;
    }
    .ll-offline-launcher__action[disabled],
    .ll-offline-category-card__action[disabled] {
      opacity: 0.45;
      cursor: not-allowed;
      box-shadow: none;
      transform: none;
    }
    .ll-offline-category-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 12px;
    }
    .ll-offline-category-card {
      display: flex;
      flex-direction: column;
      gap: 14px;
      padding: 14px;
      border-radius: 16px;
      border: 1px solid var(--ll-offline-border);
      background: var(--ll-offline-soft);
      box-shadow: 0 10px 22px rgba(23,32,43,0.06);
    }
    .ll-offline-category-card__preview {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }
    .ll-offline-category-card__preview-item {
      position: relative;
      overflow: hidden;
      min-height: 88px;
      border-radius: 12px;
      background: rgba(23,32,43,0.08);
      border: 1px solid rgba(23,32,43,0.08);
    }
    .ll-offline-category-card__preview-item img {
      display: block;
      width: 100%;
      height: 100%;
      min-height: 88px;
      object-fit: cover;
    }
    .ll-offline-category-card__preview-item--text {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 10px;
      text-align: center;
      font-size: 0.9rem;
      font-weight: 600;
      line-height: 1.25;
    }
    .ll-offline-category-card__preview-item--empty {
      opacity: 0.5;
    }
    .ll-offline-category-card__header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }
    .ll-offline-category-card__toggle {
      flex: 0 0 auto;
      width: 22px;
      height: 22px;
      margin: 2px 0 0;
      accent-color: var(--ll-offline-accent);
      cursor: pointer;
    }
    .ll-offline-category-card__header-main {
      flex: 1 1 auto;
      min-width: 0;
    }
    .ll-offline-category-card__title {
      margin: 0;
      font-size: 1rem;
      line-height: 1.3;
    }
    .ll-offline-category-card__translation {
      margin: 4px 0 0;
      font-size: 0.9rem;
      opacity: 0.72;
    }
    .ll-offline-category-card__count {
      flex: 0 0 auto;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 40px;
      min-height: 32px;
      padding: 0 10px;
      border-radius: 999px;
      background: rgba(23,32,43,0.08);
      font-size: 0.88rem;
      font-weight: 700;
    }
    .ll-offline-category-card__actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
    }
    .ll-offline-category-card__action {
      width: 44px;
      min-width: 44px;
      padding: 0;
      border-radius: 999px;
    }
    .ll-offline-category-card__action-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 18px;
      height: 18px;
      line-height: 1;
      font-size: 1rem;
    }
    .ll-offline-category-card__action-icon svg {
      width: 100%;
      height: 100%;
      display: block;
    }
    .ll-offline-launcher__empty {
      margin: 0;
      padding: 18px 14px;
      border-radius: 16px;
      border: 1px solid var(--ll-offline-border);
      background: var(--ll-offline-soft);
      font-size: 0.95rem;
    }
    body.ll-tools-flashcard-open .ll-offline-launcher {
      opacity: 0.35;
      pointer-events: none;
    }
    .ll-offline-app-shell .ll-tools-category-display {
      font-weight: 700;
    }
    @media (max-width: 640px) {
      .ll-wordset-page.ll-wordset-page--offline .ll-wordset-selection-bar {
        left: 16px;
        right: 16px;
        width: auto;
        transform: none;
      }
      .ll-wordset-page.ll-wordset-page--offline .ll-wordset-selection-bar__text {
        width: 100%;
      }
      .ll-offline-launcher__selection-actions,
      .ll-offline-category-card__actions {
        width: 100%;
      }
      .ll-offline-launcher__action {
        width: 100%;
      }
      .ll-offline-category-card__actions {
        justify-content: stretch;
      }
      .ll-offline-category-card__action {
        flex: 1 1 0;
        width: auto;
      }
    }
  </style>
</head>
<body data-ll-offline-app="1" data-ll-startup-mode="<?php echo esc_attr($startup_mode); ?>">
  <main class="ll-offline-app-shell ll-wordset-page ll-wordset-page--offline">
    <header class="ll-offline-app-header">
      <div>
        <h1 class="ll-offline-app-title"><?php echo esc_html($app_title); ?></h1>
        <?php if ($wordset_name !== '') : ?>
          <p class="ll-offline-app-wordset"><?php echo esc_html($wordset_name); ?></p>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!empty($warnings)) : ?>
      <section class="ll-offline-warning-list" aria-label="<?php echo esc_attr__('Offline export warnings', 'll-tools-text-domain'); ?>">
        <strong><?php esc_html_e('Export warnings', 'll-tools-text-domain'); ?></strong>
        <ul>
          <?php foreach ($warnings as $warning) : ?>
            <li><?php echo esc_html($warning); ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <div id="ll-tools-flashcard-container"
         class="ll-tools-flashcard-container"
         data-wordset="<?php echo esc_attr((string) ($ll_config['wordset'] ?? '')); ?>"
         data-wordset-fallback="<?php echo !empty($ll_config['wordsetFallback']) ? '1' : '0'; ?>"
         data-ll-config="<?php echo esc_attr(is_string($ll_config_json) ? $ll_config_json : '{}'); ?>">
      <section id="ll-offline-launcher" class="ll-offline-launcher" aria-label="<?php echo esc_attr__('Offline quiz launcher', 'll-tools-text-domain'); ?>">
        <div class="ll-wordset-grid-tools">
          <button id="ll-offline-select-all" class="ll-wordset-select-all ll-wordset-progress-select-all" type="button">
            <?php esc_html_e('Select All', 'll-tools-text-domain'); ?>
          </button>
        </div>
        <div id="ll-offline-category-grid" class="ll-wordset-grid" role="list" aria-live="polite"></div>
        <div id="ll-offline-selection-bar" class="ll-wordset-selection-bar" hidden>
          <span id="ll-offline-selection-text" class="ll-wordset-selection-bar__text"><?php esc_html_e('Select categories to study together', 'll-tools-text-domain'); ?></span>
          <div class="ll-wordset-selection-bar__actions">
            <button id="ll-offline-launch-learning-selected" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-offline-launch-selected data-mode="learning" type="button" disabled>
              <?php esc_html_e('Learn', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-offline-launch-practice-selected" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-offline-launch-selected data-mode="practice" type="button" disabled>
              <?php esc_html_e('Practice', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-offline-launch-listening-selected" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-offline-launch-selected data-mode="listening" type="button" hidden disabled>
              <?php esc_html_e('Listen', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-offline-launch-gender-selected" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-offline-launch-selected data-mode="gender" type="button" hidden disabled>
              <?php esc_html_e('Gender', 'll-tools-text-domain'); ?>
            </button>
            <button id="ll-offline-launch-self-check-selected" class="ll-study-btn ll-vocab-lesson-mode-button ll-wordset-mode-button ll-wordset-mode-button--tiny" data-ll-offline-launch-selected data-mode="self-check" type="button" hidden disabled>
              <?php esc_html_e('Self check', 'll-tools-text-domain'); ?>
            </button>
          </div>
          <button id="ll-offline-selection-clear" type="button" class="ll-wordset-selection-bar__clear" aria-label="<?php echo esc_attr__('Clear selection', 'll-tools-text-domain'); ?>">
            <span class="ll-wordset-selection-bar__clear-icon" aria-hidden="true">x</span>
          </button>
        </div>
        <div id="ll-offline-category-empty" class="ll-wordset-empty" hidden><?php esc_html_e('No categories are available in this offline app.', 'll-tools-text-domain'); ?></div>
      </section>

      <div id="ll-tools-flashcard-popup" style="display:none;">
        <div id="ll-tools-category-selection-popup" style="display:none;">
          <h3><?php esc_html_e('Select Categories', 'll-tools-text-domain'); ?></h3>
          <div class="ll-tools-category-selection-buttons">
            <button id="ll-tools-uncheck-all" type="button"><?php esc_html_e('Uncheck All', 'll-tools-text-domain'); ?></button>
            <button id="ll-tools-check-all" type="button"><?php esc_html_e('Check All', 'll-tools-text-domain'); ?></button>
          </div>
          <div id="ll-tools-category-checkboxes-container">
            <div id="ll-tools-category-checkboxes"></div>
          </div>
          <button id="ll-tools-start-selected-quiz" type="button"><?php esc_html_e('Start Quiz', 'll-tools-text-domain'); ?></button>
          <button id="ll-tools-close-category-selection" type="button" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>
        </div>

        <div id="ll-tools-flashcard-quiz-popup" style="display:none;">
          <button id="ll-tools-close-flashcard" type="button" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>
          <div id="ll-tools-flashcard-header" style="display:none;">
            <div id="ll-tools-learning-progress" style="display:none;"></div>
            <div id="ll-tools-category-stack" class="ll-tools-category-stack">
              <span id="ll-tools-category-display" class="ll-tools-category-display"></span>
              <button id="ll-tools-repeat-flashcard" class="play-mode" type="button" aria-label="<?php echo esc_attr__('Play', 'll-tools-text-domain'); ?>"></button>
            </div>
          </div>

          <div id="ll-tools-loading-animation" class="ll-tools-loading-animation" aria-hidden="true"></div>

          <div id="ll-tools-flashcard-content">
            <div id="ll-tools-prompt" class="ll-tools-prompt" style="display:none;"></div>
            <div id="ll-tools-flashcard"></div>
            <audio controls class="hidden"></audio>
          </div>

          <?php
            $practice_switch_label = $practice_mode_ui['switchLabel'] ?? __('Switch to Practice Mode', 'll-tools-text-domain');
            $learning_switch_label = $learning_mode_ui['switchLabel'] ?? __('Switch to Learning Mode', 'll-tools-text-domain');
            $self_check_switch_label = $self_check_mode_ui['switchLabel'] ?? __('Open Self Check', 'll-tools-text-domain');
            $listening_switch_label = $listening_mode_ui['switchLabel'] ?? __('Switch to Listening Mode', 'll-tools-text-domain');
            $gender_switch_label = $gender_mode_ui['switchLabel'] ?? __('Switch to Gender Mode', 'll-tools-text-domain');
          ?>
          <div id="ll-tools-mode-switcher-wrap" class="ll-tools-mode-switcher-wrap" style="display:none;" aria-expanded="false">
            <div id="ll-tools-mode-menu" class="ll-tools-mode-menu" role="menu" aria-hidden="true">
              <button class="ll-tools-mode-option learning" role="menuitemradio" aria-label="<?php echo esc_attr($learning_switch_label); ?>" data-mode="learning" type="button">
                <?php $render_mode_icon($learning_mode_ui, '🎓', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option practice" role="menuitemradio" aria-label="<?php echo esc_attr($practice_switch_label); ?>" data-mode="practice" type="button">
                <?php $render_mode_icon($practice_mode_ui, '❓', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option self-check" role="menuitemradio" aria-label="<?php echo esc_attr($self_check_switch_label); ?>" data-mode="self-check" type="button">
                <?php $render_mode_icon($self_check_mode_ui, '✔✖', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option gender<?php echo $gender_mode_visible ? '' : ' hidden'; ?>" role="menuitemradio" aria-label="<?php echo esc_attr($gender_switch_label); ?>" data-mode="gender" type="button"<?php echo $gender_mode_visible ? '' : ' aria-hidden="true"'; ?>>
                <?php $render_mode_icon($gender_mode_ui, '⚥', 'mode-icon'); ?>
              </button>
              <button class="ll-tools-mode-option listening" role="menuitemradio" aria-label="<?php echo esc_attr($listening_switch_label); ?>" data-mode="listening" type="button">
                <?php $render_mode_icon($listening_mode_ui, '🎧', 'mode-icon'); ?>
              </button>
            </div>
            <button id="ll-tools-mode-switcher" class="ll-tools-mode-switcher" type="button" aria-haspopup="true" aria-expanded="false" aria-label="<?php echo esc_attr__('Switch Mode', 'll-tools-text-domain'); ?>">
              <span class="mode-icon" aria-hidden="true">⇄</span>
            </button>
          </div>

          <div id="quiz-results" style="display:none;">
            <h2 id="quiz-results-title"><?php esc_html_e('Quiz Results', 'll-tools-text-domain'); ?></h2>
            <p id="quiz-results-message" style="display:none;"></p>
            <p>
              <strong><?php esc_html_e('Correct:', 'll-tools-text-domain'); ?></strong>
              <span id="correct-count">0</span> / <span id="total-questions">0</span>
            </p>
            <p id="quiz-results-categories" style="margin-top:10px;display:none;"></p>
            <?php
              $practice_results_label = $practice_mode_ui['resultsButtonText'] ?? __('Practice Mode', 'll-tools-text-domain');
              $learning_results_label = $learning_mode_ui['resultsButtonText'] ?? __('Learning Mode', 'll-tools-text-domain');
              $self_check_results_label = $self_check_mode_ui['resultsButtonText'] ?? __('Self Check', 'll-tools-text-domain');
              $listening_results_label = $listening_mode_ui['resultsButtonText'] ?? __('Listen', 'll-tools-text-domain');
              $gender_results_label = $gender_mode_ui['resultsButtonText'] ?? __('Gender', 'll-tools-text-domain');
            ?>
            <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
              <button id="restart-practice-mode" class="quiz-button quiz-mode-button" type="button">
                <?php $render_mode_icon($practice_mode_ui, '❓', 'button-icon'); ?>
                <?php echo esc_html($practice_results_label); ?>
              </button>
              <button id="restart-learning-mode" class="quiz-button quiz-mode-button" type="button">
                <?php $render_mode_icon($learning_mode_ui, '🎓', 'button-icon'); ?>
                <span class="ll-learning-results-label"><?php echo esc_html($learning_results_label); ?></span>
              </button>
              <button id="restart-self-check-mode" class="quiz-button quiz-mode-button" type="button">
                <?php $render_mode_icon($self_check_mode_ui, '✔✖', 'button-icon'); ?>
                <?php echo esc_html($self_check_results_label); ?>
              </button>
              <button id="restart-gender-mode" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php $render_mode_icon($gender_mode_ui, '⚥', 'button-icon'); ?>
                <span class="ll-gender-results-label"><?php echo esc_html($gender_results_label); ?></span>
              </button>
              <button id="restart-listening-mode" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php $render_mode_icon($listening_mode_ui, '🎧', 'button-icon'); ?>
                <?php echo esc_html($listening_results_label); ?>
              </button>
            </div>
            <div id="ll-gender-results-actions" style="display:none; margin-top: 12px;">
              <button id="ll-gender-next-activity" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php esc_html_e('Next Gender Activity', 'll-tools-text-domain'); ?>
              </button>
              <button id="ll-gender-next-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php esc_html_e('Next Set', 'll-tools-text-domain'); ?>
              </button>
            </div>
            <div id="ll-study-results-actions" style="display:none; margin-top: 12px;">
              <p id="ll-study-results-suggestion" style="display:none; margin: 0 0 8px 0;"></p>
              <button id="ll-study-results-same-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php esc_html_e('Repeat', 'll-tools-text-domain'); ?>
              </button>
              <button id="ll-study-results-different-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php esc_html_e('Categories', 'll-tools-text-domain'); ?>
              </button>
              <button id="ll-study-results-next-chunk" class="quiz-button quiz-mode-button" type="button" style="display:none;">
                <?php esc_html_e('Recommended', 'll-tools-text-domain'); ?>
              </button>
            </div>
            <button id="restart-quiz" class="quiz-button" type="button" style="display:none;"><?php esc_html_e('Restart Quiz', 'll-tools-text-domain'); ?></button>
          </div>
        </div>
      </div>
    </div>
  </main>

  <?php foreach ($scripts as $src) : ?>
    <script src="<?php echo $escape_asset_url($src); ?>"></script>
  <?php endforeach; ?>
  <script>
  (function () {
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
</body>
</html>
