<?php
/**
 * Minimal meta box to change post_parent for word_audio.
 * Type a Word post ID (or 0 to detach) and save.
 *
 * Path: includes/admin/metabox-word-audio-parent.php
 */

if (!defined('ABSPATH')) { exit; }

class LL_Tools_Word_Audio_Parent_Simple_Metabox {
    const NONCE_FIELD  = 'll_tools_wa_parent_simple_nonce';
    const NONCE_ACTION = 'll_tools_wa_parent_simple_save';

    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('save_post_word_audio', [__CLASS__, 'save']);
    }

    public static function add_metabox() {
        add_meta_box(
            'll-tools-wa-parent-simple',
            __('Parent Word (ID)', 'll-tools-text-domain'),
            [__CLASS__, 'render'],
            'word_audio',
            'side',
            'high'
        );
    }

    public static function render($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $parent_id = (int) $post->post_parent;
        $label     = '<em>' . esc_html__('None', 'll-tools-text-domain') . '</em>';

        if ($parent_id) {
            $p = get_post($parent_id);
            if ($p && $p->post_type === 'words') {
                $title = get_the_title($p);
                $label = esc_html($title) . ' (ID ' . (int)$parent_id . ')';
            } else {
                // parent_id is set but not a valid 'words' post
                $label = '<strong style="color:#b32d2e;">' . esc_html__('Invalid parent reference', 'll-tools-text-domain') . '</strong> (ID ' . (int)$parent_id . ')';
            }
        }
        ?>
        <p class="description" style="margin-top:0;">
            <?php esc_html_e('Set the parent Word by numeric post ID. Use 0 to detach.', 'll-tools-text-domain'); ?>
        </p>

        <p style="margin-bottom:6px;">
            <strong><?php esc_html_e('Current:', 'll-tools-text-domain'); ?></strong>
            <span><?php echo $label; // already escaped above ?></span>
        </p>

        <label for="ll_parent_word_id" style="display:block; font-weight:600; margin-bottom:4px;">
            <?php esc_html_e('New Parent Word ID', 'll-tools-text-domain'); ?>
        </label>
        <input type="number"
               id="ll_parent_word_id"
               name="ll_parent_word_id"
               value="<?php echo esc_attr($parent_id); ?>"
               min="0"
               step="1"
               style="width:100%;" />

        <?php if ($parent_id) : ?>
            <p style="margin-top:6px;">
                <a href="<?php echo esc_url(get_edit_post_link($parent_id)); ?>">
                    <?php esc_html_e('Open current parent', 'll-tools-text-domain'); ?>
                </a>
            </p>
        <?php endif; ?>
        <?php
    }

    public static function save($post_id) {
        // Autosaves / revisions / perms / nonce
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (!isset($_POST['ll_parent_word_id'])) return;

        $new_parent = absint($_POST['ll_parent_word_id']); // 0 allowed to detach

        if ($new_parent === 0) {
            // Detach if needed
            $cur = get_post($post_id);
            if ($cur && (int)$cur->post_parent !== 0) {
                wp_update_post(['ID' => $post_id, 'post_parent' => 0]);
            }
            return;
        }

        // Validate the target is an existing 'words' post
        $parent = get_post($new_parent);
        if (!$parent || $parent->post_type !== 'words') {
            // Silently ignore invalid entries (keeps current parent)
            return;
        }

        // Update only if changed
        $cur = get_post($post_id);
        if ($cur && (int)$cur->post_parent !== (int)$new_parent) {
            wp_update_post(['ID' => $post_id, 'post_parent' => $new_parent]);
        }
    }
}

LL_Tools_Word_Audio_Parent_Simple_Metabox::init();
