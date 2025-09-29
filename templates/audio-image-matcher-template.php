<?php
// /templates/audio-image-matcher.php
if (!defined('WPINC')) { die; }
/**
 * Variables expected in scope:
 *   - $cats (array of WP_Term)
 *   - $pre_term_id (int)
 *   - $pre_rematch (bool)
 */
?>
<div class="wrap">
    <h1>Audio ↔ Image Matcher</h1>
    <p>
        Select a category, then click <em>Start Matching</em>.
        In <strong>Rematch mode</strong>, already-matched words are included and picking an image will replace the current featured image.
    </p>

    <div id="ll-aim-controls" style="margin:16px 0; display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
        <label for="ll-aim-category"><strong>Category:</strong></label>
        <select id="ll-aim-category">
            <option value="">— Select —</option>
            <?php foreach ($cats as $t): ?>
                <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($pre_term_id, $t->term_id); ?>>
                    <?php echo esc_html($t->name . ' ('.$t->slug.')'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label style="display:flex; align-items:center; gap:6px;">
            <input type="checkbox" id="ll-aim-rematch" <?php checked($pre_rematch, true); ?> />
            Rematch mode (include already-matched words)
        </label>

        <button class="button button-primary" id="ll-aim-start">Start Matching</button>
        <button class="button" id="ll-aim-skip" disabled>Skip</button>
    </div>

    <div id="ll-aim-stage" style="display:none;">
        <div id="ll-aim-current" style="margin-bottom:12px;">
            <h2 id="ll-aim-word-title" style="margin:8px 0;">&nbsp;</h2>
            <audio id="ll-aim-audio" controls preload="auto" style="max-width:520px; display:block;"></audio>
            <p id="ll-aim-extra" style="color:#666; margin:6px 0 10px;"></p>

            <div id="ll-aim-current-thumb" style="display:none; max-width:520px;">
                <img src="" alt="" style="width:100%; height:auto; border:1px solid #ddd; border-radius:8px;">
                <div class="ll-aim-cap" style="font-size:.9rem; color:#555; margin-top:6px;"></div>
            </div>
        </div>

        <div id="ll-aim-images" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(140px, 1fr)); gap:12px;"></div>

        <div id="ll-aim-status" style="margin-top:14px; color:#444;"></div>
    </div>
</div>
