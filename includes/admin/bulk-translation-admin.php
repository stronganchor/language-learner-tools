<?php
if (!defined('ABSPATH')) exit;

/**
 * LL Tools — Bulk Translations admin page
 * - Lists "words" posts missing translations
 * - Fetch suggestions (DeepL → Dictionary fallback)
 * - Edit/delete and save to 'word_translation' meta
 * - Migrate legacy 'word_english_meaning' → 'word_translation'
 */

// Register translation language options in the DeepL settings group as well
add_action('admin_init', function () {
    $args = array(
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest' => false,
        'default' => ''
    );
    // Allow saving these from this page's header form
    register_setting('ll-deepl-api-key-group', 'll_translation_language', $args); // target
    register_setting('ll-deepl-api-key-group', 'll_target_language', $args);      // source
    register_setting('ll-deepl-api-key-group', 'll_word_title_language_role', array(
        'type' => 'string',
        'sanitize_callback' => function($v){ return in_array($v, array('target','translation'), true) ? $v : 'target'; },
        'default' => 'target'
    ));
});

// Add page under Tools
add_action('admin_menu', function () {
    add_management_page(
        'LL Bulk Translations',
        'LL Bulk Translations',
        'view_ll_tools',
        'll-bulk-translations',
        'll_render_bulk_translations_page'
    );
});

function ll_render_bulk_translations_page() {
    if (!current_user_can('view_ll_tools')) return;

    $per_page = max(1, min(200, intval($_GET['per_page'] ?? 50)));
    $paged    = max(1, intval($_GET['paged'] ?? 1));
    $offset   = ($paged - 1) * $per_page;

    $rows     = ll_bulk_translation_query_posts($per_page, $offset);
    $total    = ll_bulk_translation_count_posts_missing();

    $total_pages = $total > 0 ? ceil($total / $per_page) : 1;

    $deepl_key   = get_option('ll_deepl_api_key');
    $src_lang    = get_option('ll_target_language', 'auto');       // DeepL source (auto allowed)
    $tgt_lang    = get_option('ll_translation_language', 'EN');    // DeepL target

    $title_lang_role = get_option('ll_word_title_language_role', 'target');
    $nonce = wp_create_nonce('ll-bulk-translations');
    ?>
    <div class="wrap">
        <h1>LL Tools — Bulk Translations</h1>

        <!-- Settings strip -->
        <form method="post" action="options.php" class="ll-top-settings">
            <?php settings_fields('ll-deepl-api-key-group'); ?>
            <table class="form-table" style="margin-top:0">
                <tr>
                    <th scope="row">DeepL API Key</th>
                    <td><input type="text" name="ll_deepl_api_key" value="<?php echo esc_attr($deepl_key); ?>" size="60" /></td>
                </tr>
                <tr>
                    <th scope="row">Source language (DeepL <code>source_lang</code>)</th>
                    <td><input type="text" name="ll_target_language" value="<?php echo esc_attr($src_lang); ?>" placeholder="auto" /></td>
                </tr>
                <tr>
                    <th scope="row">Target language (DeepL <code>target_lang</code>)</th>
                    <td><input type="text" name="ll_translation_language" value="<?php echo esc_attr($tgt_lang); ?>" placeholder="EN" /></td>
                </tr>
                <tr>
                    <th scope="row">Word title language (affects dictionary)</th>
                    <td>
                        <select name="ll_word_title_language_role">
                            <option value="target" <?php selected($title_lang_role, 'target'); ?>>Target (language being learned)</option>
                            <option value="translation" <?php selected($title_lang_role, 'translation'); ?>>Translation (helper/known language)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save translation settings'); ?>
        </form>

        <!-- Migration button -->
        <form id="ll-migrate-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:10px 0 20px">
            <input type="hidden" name="action" value="ll_bulk_translations_migrate">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <?php submit_button('Migrate legacy meta (word_english_meaning → word_translation)', 'secondary', '', false); ?>
            <span class="description" style="margin-left:8px">Copies legacy values where the new meta is empty.</span>
        </form>

        <div class="ll-controls" style="margin:10px 0 12px">
            <button class="button" id="ll-select-all">Select All</button>
            <button class="button" id="ll-deselect-all">Deselect All</button>
            <button class="button button-primary" id="ll-fetch">Get translations for selected</button>
            <span class="spinner" id="ll-fetch-spinner" style="float:none;margin-left:6px"></span>
            <button class="button button-primary" id="ll-save">Save selected translations</button>
        </div>

        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <td style="width:30px"><input type="checkbox" id="ll-master" /></td>
                    <th style="width:80px">ID</th>
                    <th>Word</th>
                    <th style="width:30%">Current translation</th>
                    <th style="width:30%">Suggested / Edit</th>
                    <th style="width:12%">Status</th>
                </tr>
            </thead>
            <tbody id="ll-rows">
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="6">No posts without translations found on this page.</td></tr>
                <?php else : foreach ($rows as $post) :
                    $current = get_post_meta($post->ID, 'word_translation', true);
                    if ($current === '') {
                        // one-time display fallback for visibility
                        $current = get_post_meta($post->ID, 'word_english_meaning', true);
                    }
                ?>
                    <tr data-id="<?php echo esc_attr($post->ID); ?>">
                        <td><input type="checkbox" class="ll-row-check" /></td>
                        <td><?php echo (int)$post->ID; ?></td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></td>
                        <td class="ll-current">
                            <input type="text" class="regular-text" value="<?php echo esc_attr($current); ?>" readonly />
                        </td>
                        <td class="ll-suggest">
                            <input type="text" class="regular-text ll-suggestion" value="" placeholder="(click Get translations)" />
                            <a href="#" class="ll-save-one" title="Save">✓</a>
                            <a href="#" class="ll-clear" title="Clear">×</a>
                        </td>
                        <td class="ll-status"><span class="status-badge">—</span><span class="spinner" style="float:none;margin-left:6px"></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="tablenav" style="margin-top:10px">
            <div class="tablenav-pages">
                <?php
                if ($total_pages > 1) {
                    $base = remove_query_arg(['paged'], menu_page_url('ll-bulk-translations', false));
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%', $base),
                        'format'    => '',
                        'prev_text' => '«',
                        'next_text' => '»',
                        'total'     => $total_pages,
                        'current'   => $paged,
                        'add_args'  => ['per_page' => $per_page],
                    ]);
                } else {
                    echo '<span class="displaying-num">' . intval($total) . ' items</span>';
                }
                ?>
            </div>
        </div>
    </div>

    <style>
        .ll-top-settings .form-table th { width: 280px; }
        .status-badge { display:inline-block; background:#f3f4f5; padding:2px 6px; border-radius:3px; }
        .ll-suggest { display:flex; align-items:center; gap:6px; }
        .ll-suggest input.ll-suggestion { flex:1 1 auto; min-width:0; width:auto; }
        .ll-suggest .ll-clear { text-decoration:none; font-weight:bold; }
        .ll-suggest .ll-save-one { text-decoration:none; font-weight:bold; color:#008a20; }
    </style>

    <script>
    jQuery(function($){
        var nonce = <?php echo json_encode($nonce); ?>;

        function getSelectedIds(){
            var ids = [];
            $('#ll-rows tr').each(function(){
                if ($(this).find('.ll-row-check').prop('checked')) {
                    ids.push(parseInt($(this).attr('data-id'), 10));
                }
            });
            return ids;
        }

        function updateMaster(){
            var $checks = $('#ll-rows .ll-row-check');
            var all = $checks.length;
            var sel = $checks.filter(':checked').length;
            $('#ll-master').prop('checked', all > 0 && sel === all);
        }

        $('#ll-master').on('change', function(){
            $('.ll-row-check').prop('checked', $(this).prop('checked'));
        });
        $('#ll-select-all').on('click', function(e){ e.preventDefault(); $('.ll-row-check').prop('checked', true); updateMaster(); });
        $('#ll-deselect-all').on('click', function(e){ e.preventDefault(); $('.ll-row-check').prop('checked', false); updateMaster(); });

        // Shift-click range selection for row checkboxes
        (function(){
            var lastIndex = null;
            $('#ll-rows').on('click', '.ll-row-check', function(e){
                var $checks = $('#ll-rows .ll-row-check');
                var idx = $checks.index(this);
                if (e.shiftKey && lastIndex !== null && lastIndex !== -1) {
                    var start = Math.min(lastIndex, idx);
                    var end   = Math.max(lastIndex, idx);
                    var state = $(this).prop('checked');
                    $checks.slice(start, end + 1).prop('checked', state);
                }
                lastIndex = idx;
                updateMaster();
            });
        })();

        $('#ll-rows').on('click', '.ll-clear', function(e){ e.preventDefault(); $(this).closest('td').find('.ll-suggestion').val(''); });
        $('#ll-rows').on('click', '.ll-save-one', function(e){
            e.preventDefault();
            var $tr = $(this).closest('tr');
            var id = parseInt($tr.attr('data-id'), 10);
            var txt = $tr.find('.ll-suggestion').val().trim();
            if (!id || !txt) { alert('Nothing to save for this row.'); return; }
            var $spinner = $tr.find('.ll-status .spinner');
            var $st = $tr.find('.status-badge');
            $spinner.addClass('is-active');
            $st.text('saving…');
            $.post(ajaxurl, {
                action: 'll_bulk_translations_save',
                nonce: nonce,
                rows: [{ id: id, translation: txt }]
            }).done(function(resp){
                if (!resp || !resp.success) { alert('Save failed.'); return; }
                $tr.find('.ll-current input').val(txt);
                $st.text('saved');
            }).fail(function(){
                alert('Save request failed.');
                $st.text('error');
            }).always(function(){
                $spinner.removeClass('is-active');
            });
        });

        $('#ll-fetch').on('click', function(e){
            e.preventDefault();
            var ids = getSelectedIds();
            if (!ids.length) { alert('Select at least one row.'); return; }

            // Activate global spinner and disable button
            $('#ll-fetch-spinner').addClass('is-active');
            $('#ll-fetch').prop('disabled', true);

            // Activate row spinners for selected rows and set status text
            ids.forEach(function(id){
                var $tr = $('#ll-rows tr[data-id="'+id+'"]');
                $tr.find('.ll-status .status-badge').text('fetching…');
                $tr.find('.ll-status .spinner').addClass('is-active');
            });

            $.post(ajaxurl, {
                action: 'll_bulk_translations_fetch',
                nonce: nonce,
                ids: ids
            }).done(function(resp){
                if (!resp || !resp.success || !resp.data || !resp.data.rows) {
                    alert('No response or failed to fetch.');
                } else {
                    resp.data.rows.forEach(function(row){
                        var $tr = $('#ll-rows tr[data-id="'+row.id+'"]');
                        var $in = $tr.find('.ll-suggestion');
                        var $st = $tr.find('.status-badge');
                        if (row.suggestion && row.suggestion.length) {
                            $in.val(row.suggestion);
                            $st.text('suggested');
                        } else {
                            $st.text('no match');
                        }
                    });
                }
            }).fail(function(){
                alert('Request failed.');
            }).always(function(){
                // Turn off spinners for selected rows
                ids.forEach(function(id){
                    var $tr = $('#ll-rows tr[data-id="'+id+'"]');
                    $tr.find('.ll-status .spinner').removeClass('is-active');
                });
                // Deactivate global spinner and re-enable button
                $('#ll-fetch-spinner').removeClass('is-active');
                $('#ll-fetch').prop('disabled', false);
            });
        });

        $('#ll-save').on('click', function(e){
            e.preventDefault();
            var payload = [];
            $('#ll-rows tr').each(function(){
                if ($(this).find('.ll-row-check').prop('checked')) {
                    var id = parseInt($(this).attr('data-id'), 10);
                    var txt = $(this).find('.ll-suggestion').val().trim();
                    if (id && txt) payload.push({ id:id, translation:txt });
                }
            });
            if (!payload.length) { alert('Nothing to save.'); return; }

            $.post(ajaxurl, {
                action: 'll_bulk_translations_save',
                nonce: nonce,
                rows: payload
            }).done(function(resp){
                if (!resp || !resp.success) { alert('Save failed.'); return; }
                // Reflect saved values into "Current translation"
                payload.forEach(function(row){
                    var $tr = $('#ll-rows tr[data-id="'+row.id+'"]');
                    $tr.find('.ll-current input').val(row.translation);
                    $tr.find('.status-badge').text('saved');
                });
            }).fail(function(){
                alert('Save request failed.');
            });
        });
    });
    </script>
    <?php
}

/** Query helpers **/
function ll_bulk_translation_query_posts($limit, $offset) {
    $q = new WP_Query([
        'post_type'      => 'words',
        'posts_per_page' => $limit,
        'offset'         => $offset,
        'fields'         => 'all',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => 'word_translation',
                'compare' => 'NOT EXISTS'
            ],
            [
                'key'     => 'word_translation',
                'value'   => '',
                'compare' => '='
            ],
        ],
    ]);
    return $q->posts;
}

function ll_bulk_translation_count_posts_missing() {
    global $wpdb;
    $sql = $wpdb->prepare(
        "SELECT COUNT(1) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} m
           ON p.ID = m.post_id AND m.meta_key = %s
         WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','future','private')
           AND (m.meta_id IS NULL OR m.meta_value = '')",
        'word_translation', 'words'
    );
    return (int) $wpdb->get_var($sql);
}

/** AJAX: fetch suggestions (DeepL → Dictionary fallback) */
add_action('wp_ajax_ll_bulk_translations_fetch', 'll_ajax_bulk_translations_fetch');
function ll_ajax_bulk_translations_fetch() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error(['message' => 'forbidden'], 403);
    check_ajax_referer('ll-bulk-translations', 'nonce');

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    if (empty($ids)) wp_send_json_success(['rows' => []]);

    // Prefer existing DeepL wiring if present
    if (!function_exists('translate_with_deepl')) {
        $deepl_file = trailingslashit(defined('LL_TOOLS_BASE_PATH') ? LL_TOOLS_BASE_PATH : plugin_dir_path(__FILE__) . '../..') . 'includes/admin/api/deepl-api.php';
        if (file_exists($deepl_file)) require_once $deepl_file;
    }

    $src = get_option('ll_target_language', 'auto');        // source (target language code setting)
    $tgt = get_option('ll_translation_language', 'EN');     // target (translation language code setting)
    $has_deepl = (string) get_option('ll_deepl_api_key') !== '';
    $title_role = get_option('ll_word_title_language_role', 'target');
    $reverse_dict = ($title_role === 'translation');

    $out = [];
    foreach ($ids as $id) {
        $title = get_the_title($id);
        $suggestion = null;

        if ($has_deepl && function_exists('translate_with_deepl')) {
            // DeepL first; if it returns null (or error), fall through to dictionary
            $try = translate_with_deepl($title, $tgt, $src);
            if ($try !== null) $suggestion = $try;
        }
        if ($suggestion === null) {
            $suggestion = ll_dictionary_lookup_best($title, $src, $tgt, $reverse_dict);
        }
        $out[] = ['id' => $id, 'suggestion' => (string) $suggestion];
    }
    wp_send_json_success(['rows' => $out]);
}

/** Dictionary fallback (uses DIB DB if present) */
function ll_dictionary_lookup_best($word, $source_lang, $target_lang, $reverse = false) {
    global $wpdb;
    $table = $wpdb->prefix . 'dictionary_entries';

    // Verify table exists
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return null;

    $term = trim((string) $word);
    if ($term === '') return null;

    // Normalizer (mirrors DIB’s normalize function)
    $normalize = function($s) {
        $map = [
            'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u','Â'=>'a','Ê'=>'e','Î'=>'i','Ô'=>'o','Û'=>'u',
            'á'=>'a','à'=>'a','ä'=>'a','ã'=>'a','å'=>'a','Á'=>'a','À'=>'a','Ä'=>'a','Ã'=>'a','Å'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ë'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Ï'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','õ'=>'o','Ó'=>'o','Ò'=>'o','Ö'=>'o','Õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Ü'=>'u',
            'ş'=>'s','Ş'=>'s','ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g',
            'İ'=>'i','ı'=>'i'
        ];
        $s = strtr($s, $map);
        $s = preg_replace("~[^a-z0-9\\s\\-\\'\"]~i", '', $s);
        return mb_strtolower($s, 'UTF-8');
    };

    $term_norm = $normalize($term);

    // Direction: if reverse, look up by definition and return entry
    // Otherwise, look up by entry and return definition
    $lookup_col = $reverse ? 'definition' : 'entry';
    $return_col = $reverse ? 'entry'      : 'definition';

    // Language narrowing aligned to direction (with graceful fallback to no-lang filters)
    $filters = [];
    $params  = [];
    $entry_lang_val = $reverse ? $target_lang : $source_lang; // entries language
    $def_lang_val   = $reverse ? $source_lang : $target_lang; // definitions language
    $has_lang_filter = false;
    if (!empty($entry_lang_val) && strtolower($entry_lang_val) !== 'auto') {
        $filters[] = "entry_lang = %s";
        $params[]  = $entry_lang_val;
        $has_lang_filter = true;
    }
    if (!empty($def_lang_val) && strtolower($def_lang_val) !== 'auto') {
        $filters[] = "def_lang = %s";
        $params[]  = $def_lang_val;
        $has_lang_filter = true;
    }
    $where_lang = $filters ? (' AND ' . implode(' AND ', $filters)) : '';

    // Try with language filters first; if no result and we had filters, retry without
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $where = ($attempt === 0) ? $where_lang : '';
        $par   = ($attempt === 0) ? $params : [];
        if ($attempt === 1 && !$has_lang_filter) {
            // No reason to retry if we never applied filters
            break;
        }

        // Candidate ranking over substring matches
        $like_any = '%' . $wpdb->esc_like($term) . '%';
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT entry, definition FROM {$table} WHERE {$lookup_col} LIKE %s {$where} LIMIT 300",
            array_merge([$like_any], $par)
        ));
        if ($candidates) {
            // Split helpers
            $split_gloss_tokens = function($s){
                $arr = preg_split('/[;,\\/\\|]+/u', (string)$s);
                $out = [];
                foreach ($arr as $t) { $t = trim($t); if ($t !== '') $out[] = $t; }
                return $out;
            };
            $split_words = function($s){
                $arr = preg_split('/[\s,;:\\/\\|\(\)\[\]\{\}\.\-–—]+/u', (string)$s);
                $out = [];
                foreach ($arr as $t) { $t = trim($t); if ($t !== '') $out[] = $t; }
                return $out;
            };

            $tier_exact   = [];
            $tier_punct  = [];
            $tier_word    = [];

            foreach ($candidates as $r) {
                $lookup_text = $reverse ? $r->definition : $r->entry;
                $return_val  = $reverse ? $r->entry : $r->definition;
                if ($return_val === '') continue;

                // Ignore candidates where the term only appears inside parentheses
                $lookup_lc = mb_strtolower($lookup_text, 'UTF-8');
                $term_lc   = mb_strtolower($term, 'UTF-8');
                $len_t     = mb_strlen($term_lc, 'UTF-8');
                $len_s     = mb_strlen($lookup_lc, 'UTF-8');
                $depth     = 0;
                $outside   = false;
                for ($i = 0; $i <= $len_s - $len_t; $i++) {
                    $ch = mb_substr($lookup_lc, $i, 1, 'UTF-8');
                    if ($ch === '(') { $depth++; continue; }
                    if ($ch === ')') { if ($depth > 0) $depth--; continue; }
                    if ($depth === 0) {
                        if (mb_substr($lookup_lc, $i, $len_t, 'UTF-8') === $term_lc) { $outside = true; break; }
                    }
                }
                if (!$outside) { continue; }

                $matched_exact = false;
                $matched_prefix = false;

                // Token-level checks
                foreach ($split_gloss_tokens($lookup_text) as $tok) {
                    $tok_trim = trim($tok);
                    if (mb_strtolower($tok_trim, 'UTF-8') === mb_strtolower($term, 'UTF-8')) {
                        $tier_exact[] = [
                            'entry' => $r->entry,
                            'definition' => $r->definition,
                            'return' => $return_val,
                            'score_len' => mb_strlen($r->definition, 'UTF-8'),
                        ];
                        $matched_exact = true;
                        break;
                    }
                    // Begins with term followed by allowed punctuation (not a closing paren)
                    if (!$matched_prefix) {
                        $tok_lc  = mb_strtolower($tok_trim, 'UTF-8');
                        $term_lc = mb_strtolower($term, 'UTF-8');
                        if (strpos($tok_lc, $term_lc) === 0) {
                            $rest = ltrim(mb_substr($tok_lc, mb_strlen($term_lc, 'UTF-8')));
                            if ($rest !== '') {
                                $ch = mb_substr($rest, 0, 1, 'UTF-8');
                                $allowed = array('(', '[', '{', ',', ';', ':', '-', '–', '—', '.', "'", '"');
                                if (in_array($ch, $allowed, true)) {
                                    $tier_punct[] = [
                                        'entry' => $r->entry,
                                        'definition' => $r->definition,
                                        'return' => $return_val,
                                        'score_len' => mb_strlen($r->definition, 'UTF-8'),
                                    ];
                                    $matched_prefix = true;
                                }
                            }
                        }
                    }
                }

                if ($matched_exact || $matched_prefix) {
                    continue; // already captured in strict tiers
                }

                // Whole-word anywhere in definition/entry, but not if inside parentheses
                if (strpos($lookup_text, '(') === false && strpos($lookup_text, ')') === false) {
                    foreach ($split_words($lookup_text) as $w) {
                        if ($normalize($w) === $term_norm) {
                            $tier_word[] = [
                                'entry' => $r->entry,
                                'definition' => $r->definition,
                                'return' => $return_val,
                                'score_len' => mb_strlen($r->definition, 'UTF-8'),
                            ];
                            break;
                        }
                    }
                }
            }

            $pick_and_join = function(array $rows) {
                // Remove duplicates by return value, preserve first appearance
                $unique = [];
                $seen = [];
                foreach ($rows as $it) {
                    $key = (string)$it['return'];
                    if ($key === '') continue;
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $unique[] = $it;
                }
                if (count($unique) === 0) return null;
                if (count($unique) <= 2) {
                    return implode(', ', array_map(function($x){ return (string)$x['return']; }, $unique));
                }
                // More than 2 → pick 2 with shortest definition length
                usort($unique, function($a,$b){ return $a['score_len'] <=> $b['score_len']; });
                $top = array_slice($unique, 0, 2);
                return implode(', ', array_map(function($x){ return (string)$x['return']; }, $top));
            };

            if (!empty($tier_exact) || !empty($tier_punct)) {
                // Exact wins over punctuation-prefix; within each, shortest defs first if needed
                $combined = array_merge($tier_exact, $tier_punct);
                // Stable order: exact items first
                usort($combined, function($a,$b) {
                    // already merged exact first; break ties by shorter definition
                    return $a['score_len'] <=> $b['score_len'];
                });
                $res = $pick_and_join($combined);
                if ($res !== null) return $res;
            }
            if (!empty($tier_word)) {
                $res = $pick_and_join($tier_word);
                if ($res !== null) return $res;
            }

            // 3c) Fallback: closest by normalized Levenshtein on lookup text
            $best_val = null; $best_d = 1e9;
            foreach ($candidates as $r) {
                $candidate = $reverse ? $r->definition : $r->entry;
                $d = levenshtein($term_norm, $normalize($candidate));
                $ret = $reverse ? $r->entry : $r->definition;
                if ($ret === '') continue;
                if ($d < $best_d) { $best_d = $d; $best_val = $ret; }
            }
            if ($best_val !== null) return $best_val;
        }
    }

    return null;
}

/** AJAX: save selected translations */
add_action('wp_ajax_ll_bulk_translations_save', 'll_ajax_bulk_translations_save');
function ll_ajax_bulk_translations_save() {
    if (!current_user_can('view_ll_tools')) wp_send_json_error(['message' => 'forbidden'], 403);
    check_ajax_referer('ll-bulk-translations', 'nonce');

    $rows  = isset($_POST['rows']) && is_array($_POST['rows']) ? $_POST['rows'] : [];
    $saved = 0;

    foreach ($rows as $row) {
        $id  = intval($row['id'] ?? 0);
        $txt = isset($row['translation']) ? wp_kses_post($row['translation']) : '';
        if ($id && $txt !== '') {
            update_post_meta($id, 'word_translation', $txt);
            $saved++;
        }
    }

    wp_send_json_success(['saved' => $saved]);
}

/** Admin-post: migrate legacy meta */
add_action('admin_post_ll_bulk_translations_migrate', 'll_handle_bulk_translations_migrate');
function ll_handle_bulk_translations_migrate() {
    if (!current_user_can('view_ll_tools')) wp_die('Forbidden', 403);
    check_admin_referer('ll-bulk-translations');

    $ids = get_posts([
        'post_type'      => 'words',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => 'word_english_meaning',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'word_translation',
                'compare' => 'NOT EXISTS',
            ],
        ],
    ]);

    $migrated = 0;
    foreach ($ids as $id) {
        $val = get_post_meta($id, 'word_english_meaning', true);
        if ($val !== '') {
            update_post_meta($id, 'word_translation', $val);
            $migrated++;
        }
    }

    $redirect = add_query_arg([
        'page'     => 'll-bulk-translations',
        'migrated' => $migrated,
    ], admin_url('tools.php'));

    wp_safe_redirect($redirect);
    exit;
}

?>
