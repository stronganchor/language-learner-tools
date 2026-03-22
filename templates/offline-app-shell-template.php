<?php
if (!defined('WPINC')) { die; }

$app_title = isset($app_title) ? (string) $app_title : __('Offline Quiz', 'll-tools-text-domain');
$wordset_name = isset($wordset_name) ? (string) $wordset_name : '';
$styles = isset($styles) && is_array($styles) ? $styles : [];
$scripts = isset($scripts) && is_array($scripts) ? $scripts : [];
$warnings = isset($warnings) && is_array($warnings) ? array_values(array_filter(array_map('strval', $warnings))) : [];
$startup_mode = isset($startup_mode) ? (string) $startup_mode : 'practice';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo esc_html($app_title); ?></title>
  <?php foreach ($styles as $href) : ?>
    <link rel="stylesheet" href="<?php echo esc_url($href); ?>">
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
    .ll-offline-app-shell #ll-tools-start-flashcard {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 20px;
      border: 0;
      border-radius: 999px;
      background: var(--ll-offline-accent);
      color: #fff;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 14px 28px rgba(31,95,74,0.18);
    }
    .ll-offline-app-shell #ll-tools-start-flashcard:hover,
    .ll-offline-app-shell #ll-tools-start-flashcard:focus-visible {
      background: #184c3c;
    }
    .ll-offline-app-shell .ll-tools-category-display {
      font-weight: 700;
    }
  </style>
</head>
<body data-ll-offline-app="1" data-ll-startup-mode="<?php echo esc_attr($startup_mode); ?>">
  <main class="ll-offline-app-shell">
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

    <div id="ll-tools-flashcard-container" class="ll-tools-flashcard-container" data-wordset="" data-wordset-fallback="0">
      <button id="ll-tools-start-flashcard" type="button"><?php esc_html_e('Start', 'll-tools-text-domain'); ?></button>

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
          <div id="ll-tools-flashcard-header" style="display:none;">
            <button id="ll-tools-close-flashcard" type="button" aria-label="<?php echo esc_attr__('Close', 'll-tools-text-domain'); ?>">&times;</button>
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

          <div id="ll-tools-mode-switcher-wrap" class="ll-tools-mode-switcher-wrap" style="display:none;" aria-expanded="false">
            <div id="ll-tools-mode-menu" class="ll-tools-mode-menu" role="menu" aria-hidden="true">
              <button class="ll-tools-mode-option learning" role="menuitemradio" aria-label="<?php echo esc_attr__('Switch to Learning Mode', 'll-tools-text-domain'); ?>" data-mode="learning" type="button">
                <span class="mode-icon" aria-hidden="true" data-emoji="🎓"></span>
              </button>
              <button class="ll-tools-mode-option practice" role="menuitemradio" aria-label="<?php echo esc_attr__('Switch to Practice Mode', 'll-tools-text-domain'); ?>" data-mode="practice" type="button">
                <span class="mode-icon" aria-hidden="true" data-emoji="❓"></span>
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
            <div id="quiz-mode-buttons" style="display:none; margin-top: 20px;">
              <button id="restart-practice-mode" class="quiz-button quiz-mode-button" type="button">
                <span class="button-icon" aria-hidden="true" data-emoji="❓"></span>
                <?php esc_html_e('Practice Mode', 'll-tools-text-domain'); ?>
              </button>
              <button id="restart-learning-mode" class="quiz-button quiz-mode-button" type="button">
                <span class="button-icon" aria-hidden="true" data-emoji="🎓"></span>
                <span class="ll-learning-results-label"><?php esc_html_e('Learning Mode', 'll-tools-text-domain'); ?></span>
              </button>
            </div>
            <button id="restart-quiz" class="quiz-button" type="button" style="display:none;"><?php esc_html_e('Restart Quiz', 'll-tools-text-domain'); ?></button>
          </div>
        </div>
      </div>
    </div>
  </main>

  <?php foreach ($scripts as $src) : ?>
    <script src="<?php echo esc_url($src); ?>"></script>
  <?php endforeach; ?>
</body>
</html>
