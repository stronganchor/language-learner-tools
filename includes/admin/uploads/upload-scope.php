<?php
if (!defined('WPINC')) { die; }

/**
 * Render the shared word-set target-scope controls used by upload forms.
 *
 * @param array{
 *     wordsets:array,
 *     wordset_selection_locked:bool,
 *     default_single_wordset_id:int,
 *     preselected_wordset:mixed,
 *     lock_wordset:bool,
 *     id_prefix:string,
 *     automatic_description:string,
 *     multiple_description:string,
 *     empty_description:string
 * } $args Renderer configuration and form-specific copy.
 */
function ll_tools_render_upload_wordset_scope(array $args): void {
    $args = wp_parse_args($args, [
        'wordsets' => [],
        'wordset_selection_locked' => false,
        'default_single_wordset_id' => 0,
        'preselected_wordset' => null,
        'lock_wordset' => false,
        'id_prefix' => 'll-upload',
        'automatic_description' => '',
        'multiple_description' => '',
        'empty_description' => '',
    ]);

    $wordsets = is_array($args['wordsets']) ? $args['wordsets'] : [];
    $wordset_selection_locked = (bool) $args['wordset_selection_locked'];
    $default_single_wordset_id = (int) $args['default_single_wordset_id'];
    $preselected_wordset = $args['preselected_wordset'];
    $lock_wordset = (bool) $args['lock_wordset'];
    $single_wordset_select_id = (string) $args['id_prefix'] . '-single-wordset';
    ?>
    <div style="margin-top:10px;" data-ll-wordset-scope-root>
        <label><strong><?php esc_html_e('Target Scope', 'll-tools-text-domain'); ?></strong></label><br>
        <?php if ($wordset_selection_locked && $default_single_wordset_id > 0 && $preselected_wordset instanceof WP_Term) : ?>
            <input type="hidden" name="ll_wordset_scope_mode" value="single">
            <input type="hidden" name="ll_single_wordset_id" value="<?php echo esc_attr($default_single_wordset_id); ?>">
            <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #ccd0d4;border-radius:4px;background:#fff;" data-ll-wordset-scope-locked="1">
                <strong><?php echo esc_html((string) $preselected_wordset->name); ?></strong>
                <span class="description" style="margin:0;">
                    <?php
                    echo esc_html(
                        $lock_wordset
                            ? __('Locked to this word set', 'll-tools-text-domain')
                            : __('Only accessible word set', 'll-tools-text-domain')
                    );
                    ?>
                </span>
            </div>
            <p class="description"><?php echo esc_html((string) $args['automatic_description']); ?></p>
        <?php elseif (!empty($wordsets)) : ?>
            <fieldset style="margin:6px 0 0;">
                <label style="display:inline-block; margin-right:16px;">
                    <input type="radio" name="ll_wordset_scope_mode" value="single" checked data-ll-scope-mode>
                    <?php esc_html_e('One word set', 'll-tools-text-domain'); ?>
                </label>
                <label style="display:inline-block;">
                    <input type="radio" name="ll_wordset_scope_mode" value="multiple" data-ll-scope-mode>
                    <?php esc_html_e('Multiple word sets', 'll-tools-text-domain'); ?>
                </label>
            </fieldset>
            <p class="description"><?php esc_html_e('Choose where this upload should land before selecting a category.', 'll-tools-text-domain'); ?></p>

            <div style="margin-top:8px;" data-ll-single-wordset-wrap>
                <label for="<?php echo esc_attr($single_wordset_select_id); ?>"><?php esc_html_e('Word Set', 'll-tools-text-domain'); ?>:</label><br>
                <select id="<?php echo esc_attr($single_wordset_select_id); ?>" name="ll_single_wordset_id" class="regular-text" data-ll-single-wordset>
                    <option value="0"><?php esc_html_e('— Select —', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($wordsets as $ws) : ?>
                        <option value="<?php echo esc_attr((int) $ws->term_id); ?>" <?php selected($default_single_wordset_id, (int) $ws->term_id); ?>>
                            <?php echo esc_html((string) $ws->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-top:8px; display:none;" data-ll-multi-wordset-wrap>
                <label><?php esc_html_e('Word Sets', 'll-tools-text-domain'); ?>:</label><br>
                <div style="max-height:160px; overflow:auto; border:1px solid #ccd0d4; padding:6px;">
                    <?php foreach ($wordsets as $ws) : ?>
                        <label style="display:block; margin:2px 0;">
                            <input
                                type="checkbox"
                                name="ll_multi_wordset_ids[]"
                                value="<?php echo esc_attr((int) $ws->term_id); ?>"
                                data-ll-multi-wordset
                                data-ll-wordset-label="<?php echo esc_attr((string) $ws->name); ?>"
                            >
                            <?php echo esc_html((string) $ws->name); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="description"><?php echo esc_html((string) $args['multiple_description']); ?></p>
            </div>
        <?php else : ?>
            <p class="description"><?php echo esc_html((string) $args['empty_description']); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
