<?php
if (!defined('WPINC')) { die; }

// Vars: $embed (bool), $category_label_text (string), $quiz_font (string), $mode_ui (array)
$mode_ui = (isset($mode_ui) && is_array($mode_ui)) ? $mode_ui : [];
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
$tmpl_gender_options = isset($tmpl_ll_config['genderOptions']) && is_array($tmpl_ll_config['genderOptions'])
  ? array_values(array_filter(array_map('strval', $tmpl_ll_config['genderOptions']), function ($opt) { return $opt !== ''; }))
  : [];
$tmpl_gender_mode_visible = !empty($tmpl_ll_config['genderEnabled']) && count($tmpl_gender_options) >= 2;
?>
<div id="ll-tools-flashcard-container"
     class="ll-tools-flashcard-container"
     data-wordset="<?php echo esc_attr($tmpl_wordset); ?>"
     data-wordset-fallback="<?php echo $tmpl_wordset_fallback ? '1' : '0'; ?>"
     data-ll-config="<?php echo esc_attr($tmpl_ll_config_json); ?>">
  <?php if (!$embed): ?>
    <button id="ll-tools-start-flashcard" type="button"><?php echo esc_html__('Start', 'll-tools-text-domain'); ?></button>
  <?php endif; ?>

  <?php
  ll_tools_render_flashcard_overlay_shell([
    'include_category_selection' => true,
    'include_loading_status' => true,
    'show_category_display' => !$embed,
    'category_label_text' => isset($category_label_text) ? (string) $category_label_text : '',
    'mode_ui' => $mode_ui,
    'gender_mode_visible' => $tmpl_gender_mode_visible,
    'listening_results_fallback' => __('Replay Listening', 'll-tools-text-domain'),
  ]);
  ll_tools_render_flashcard_repeat_button_init_script();
  ?>
</div>
