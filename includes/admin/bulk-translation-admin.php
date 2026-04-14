<?php
if (!defined('ABSPATH')) exit;

/**
 * LL Tools — Bulk Translations admin page
 * - Lists "words" posts missing translations
 * - Fetch suggestions (DeepL → Dictionary fallback)
 * - Edit/delete and save to 'word_translation' meta
 * - Migrate legacy 'word_english_meaning' → 'word_translation'
 */

// Add page under Tools
add_action('admin_menu', function () {
    add_management_page(
        __('LL Bulk Translations', 'll-tools-text-domain'),
        __('LL Bulk Translations', 'll-tools-text-domain'),
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

    $can_manage_settings = current_user_can('manage_options');
    $deepl_key   = $can_manage_settings ? get_option('ll_deepl_api_key') : '';
    $deepl_key_set = ((string) get_option('ll_deepl_api_key', '') !== '');
    $nonce = wp_create_nonce('ll-bulk-translations');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LL Tools - Bulk Translations', 'll-tools-text-domain'); ?></h1>

        <!-- Settings strip -->
        <?php if ($can_manage_settings) : ?>
            <form method="post" action="options.php" class="ll-top-settings">
                <?php settings_fields('ll-deepl-api-key-group'); ?>
                <table class="form-table" style="margin-top:0">
                    <tr>
                        <th scope="row"><?php esc_html_e('DeepL API Key', 'll-tools-text-domain'); ?></th>
                        <td><input type="password" name="ll_deepl_api_key" value="<?php echo esc_attr($deepl_key); ?>" size="60" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Language resolution', 'll-tools-text-domain'); ?></th>
                        <td>
                            <p class="description"><?php esc_html_e('Source language, target language, and word-title language are resolved from each item’s assigned word set. Legacy site settings are used only as a fallback.', 'll-tools-text-domain'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save API key', 'll-tools-text-domain')); ?>
            </form>
        <?php else : ?>
            <div class="notice notice-info inline">
                <p><?php esc_html_e('Translation settings and API keys can only be updated by administrators.', 'll-tools-text-domain'); ?></p>
            </div>
            <table class="form-table ll-top-settings" style="margin-top:0">
                <tr>
                    <th scope="row"><?php esc_html_e('DeepL API Key', 'll-tools-text-domain'); ?></th>
                    <td><?php echo $deepl_key_set ? esc_html__('Configured', 'll-tools-text-domain') : esc_html__('Not configured', 'll-tools-text-domain'); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Language resolution', 'll-tools-text-domain'); ?></th>
                    <td><?php esc_html_e('Resolved from each item’s word set settings.', 'll-tools-text-domain'); ?></td>
                </tr>
            </table>
        <?php endif; ?>

        <!-- Migration button -->
        <form id="ll-migrate-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:10px 0 20px">
            <input type="hidden" name="action" value="ll_bulk_translations_migrate">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
            <?php submit_button(__('Migrate legacy meta (word_english_meaning to word_translation)', 'll-tools-text-domain'), 'secondary', '', false); ?>
            <span class="description" style="margin-left:8px"><?php esc_html_e('Copies legacy values where the new meta is empty.', 'll-tools-text-domain'); ?></span>
        </form>

        <!-- Filters -->
        <?php
            $flt_type    = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'both';
            $flt_s       = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
            $flt_cat     = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
            $flt_wordset = isset($_GET['wordset']) ? sanitize_text_field($_GET['wordset']) : '';
            $flt_orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'ID';
            $flt_order   = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'ASC';

            $wordsets   = get_terms(['taxonomy'=>'wordset','hide_empty'=>false]);
            if (is_wp_error($wordsets)) {
                $wordsets = [];
            }

            $category_option_map = ['' => function_exists('ll_tools_get_word_category_selector_rows')
                ? ll_tools_get_word_category_selector_rows(0, [
                    'post_types' => ['words', 'word_images'],
                    'post_statuses' => ['publish', 'draft', 'pending', 'future', 'private'],
                ])
                : []];
            foreach ((array) $wordsets as $ws) {
                if (!($ws instanceof WP_Term)) {
                    continue;
                }

                $category_option_map[(string) $ws->slug] = function_exists('ll_tools_get_word_category_selector_rows')
                    ? ll_tools_get_word_category_selector_rows((int) $ws->term_id, [
                        'post_types' => ['words', 'word_images'],
                        'post_statuses' => ['publish', 'draft', 'pending', 'future', 'private'],
                    ])
                    : [];
            }
            $categories = $category_option_map[$flt_wordset] ?? $category_option_map[''];
        ?>
        <form method="get" class="ll-filters" style="margin:10px 0 12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="page" value="ll-bulk-translations" />
            <label><?php esc_html_e('Type', 'll-tools-text-domain'); ?>
                <select name="type">
                    <option value="both" <?php selected($flt_type,'both'); ?>><?php esc_html_e('Words + Images', 'll-tools-text-domain'); ?></option>
                    <option value="words" <?php selected($flt_type,'words'); ?>><?php esc_html_e('Words', 'll-tools-text-domain'); ?></option>
                    <option value="word_images" <?php selected($flt_type,'word_images'); ?>><?php esc_html_e('Word Images', 'll-tools-text-domain'); ?></option>
                </select>
            </label>
            <label><?php esc_html_e('Category', 'll-tools-text-domain'); ?>
                <select name="cat" id="ll-bulk-translations-category-filter">
                    <option value="0"><?php esc_html_e('All', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?php echo (int) ($c['id'] ?? 0); ?>" <?php selected($flt_cat, (int) ($c['id'] ?? 0)); ?>>
                            <?php echo esc_html((string) ($c['label'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Wordset', 'll-tools-text-domain'); ?>
                <select name="wordset" id="ll-bulk-translations-wordset-filter">
                    <option value=""><?php esc_html_e('All', 'll-tools-text-domain'); ?></option>
                    <?php foreach ($wordsets as $ws): ?>
                        <option value="<?php echo esc_attr($ws->slug); ?>" <?php selected($flt_wordset,$ws->slug); ?>><?php echo esc_html($ws->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e('Search', 'll-tools-text-domain'); ?>
                <input type="search" name="s" value="<?php echo esc_attr($flt_s); ?>" placeholder="<?php echo esc_attr__('Word title', 'll-tools-text-domain'); ?>" />
            </label>
            <label><?php esc_html_e('Order', 'll-tools-text-domain'); ?>
                <select name="orderby">
                    <option value="ID" <?php selected($flt_orderby,'ID'); ?>><?php esc_html_e('ID', 'll-tools-text-domain'); ?></option>
                    <option value="title" <?php selected($flt_orderby,'title'); ?>><?php esc_html_e('Title', 'll-tools-text-domain'); ?></option>
                </select>
                <select name="order">
                    <option value="ASC" <?php selected($flt_order,'ASC'); ?>>ASC</option>
                    <option value="DESC" <?php selected($flt_order,'DESC'); ?>>DESC</option>
                </select>
            </label>
            <button class="button"><?php esc_html_e('Filter', 'll-tools-text-domain'); ?></button>
        </form>

        <div class="ll-controls" style="margin:10px 0 12px">
            <button class="button" id="ll-select-all"><?php esc_html_e('Select All', 'll-tools-text-domain'); ?></button>
            <button class="button" id="ll-deselect-all"><?php esc_html_e('Deselect All', 'll-tools-text-domain'); ?></button>
            <button class="button button-primary" id="ll-fetch"><?php esc_html_e('Get translations for selected', 'll-tools-text-domain'); ?></button>
            <span class="spinner" id="ll-fetch-spinner" style="float:none;margin-left:6px"></span>
            <button class="button button-primary" id="ll-save"><?php esc_html_e('Save selected translations', 'll-tools-text-domain'); ?></button>
        </div>

        <div class="ll-table-wrap">
        <table class="widefat striped ll-bulk-table">
            <thead>
                <tr>
                    <th class="col-select"><input type="checkbox" id="ll-master" /></th>
                    <th class="col-id"><?php esc_html_e('ID', 'll-tools-text-domain'); ?></th>
                    <th class="col-word"><?php esc_html_e('Word', 'll-tools-text-domain'); ?></th>
                    <th class="col-type"><?php esc_html_e('Type', 'll-tools-text-domain'); ?></th>
                    <th class="col-wordset"><?php esc_html_e('Wordset', 'll-tools-text-domain'); ?></th>
                    <th class="col-current"><?php esc_html_e('Current translation', 'll-tools-text-domain'); ?></th>
                    <th class="col-suggest"><?php esc_html_e('Suggested / Edit', 'll-tools-text-domain'); ?></th>
                    <th class="col-status"><?php esc_html_e('Status', 'll-tools-text-domain'); ?></th>
                </tr>
            </thead>
            <tbody id="ll-rows">
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="8"><?php esc_html_e('No posts without translations found on this page.', 'll-tools-text-domain'); ?></td></tr>
                <?php else : foreach ($rows as $post) :
                    $current = get_post_meta($post->ID, 'word_translation', true);
                    if ($current === '') {
                        // one-time display fallback for visibility
                        $current = get_post_meta($post->ID, 'word_english_meaning', true);
                    }
                ?>
                    <tr data-id="<?php echo esc_attr($post->ID); ?>">
                        <td class="col-select"><input type="checkbox" class="ll-row-check" /></td>
                        <td class="col-id"><?php echo (int)$post->ID; ?></td>
                        <td class="col-word"><a href="<?php echo esc_url(get_edit_post_link($post->ID)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></td>
                        <td class="col-type"><?php echo esc_html($post->post_type === 'word_images' ? __('Image', 'll-tools-text-domain') : __('Word', 'll-tools-text-domain')); ?></td>
                        <td class="col-wordset">
                            <?php
                                $wst = get_the_terms($post->ID, 'wordset');
                                if ($wst && !is_wp_error($wst)) {
                                    $names = array_map(function($t){ return $t->name; }, $wst);
                                    echo esc_html(implode(', ', $names));
                                } else {
                                    echo '—';
                                }
                            ?>
                        </td>
                        <td class="ll-current col-current">
                            <input type="text" class="regular-text" value="<?php echo esc_attr($current); ?>" readonly />
                        </td>
                        <td class="ll-suggest col-suggest">
                            <input type="text" class="regular-text ll-suggestion" value="" placeholder="<?php echo esc_attr__('(click Get translations)', 'll-tools-text-domain'); ?>" />
                            <a href="#" class="ll-save-one" title="<?php echo esc_attr__('Save', 'll-tools-text-domain'); ?>">✓</a>
                            <a href="#" class="ll-clear" title="<?php echo esc_attr__('Clear', 'll-tools-text-domain'); ?>">×</a>
                        </td>
                        <td class="ll-status col-status"><span class="status-badge">—</span><span class="spinner" style="float:none;margin-left:6px"></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <!-- Pagination -->
        <div class="tablenav" style="margin-top:10px">
            <div class="tablenav-pages">
                <?php
                if ($total_pages > 1) {
                    $base = remove_query_arg(['paged'], menu_page_url('ll-bulk-translations', false));
                    $add = [
                        'per_page' => $per_page,
                        'type'     => $flt_type,
                        's'        => $flt_s,
                        'cat'      => $flt_cat,
                        'wordset'  => $flt_wordset,
                        'orderby'  => $flt_orderby,
                        'order'    => $flt_order,
                    ];
                    echo paginate_links([
                        'base'      => add_query_arg('paged', '%#%', $base),
                        'format'    => '',
                        'prev_text' => '«',
                        'next_text' => '»',
                        'total'     => $total_pages,
                        'current'   => $paged,
                        'add_args'  => $add,
                    ]);
                } else {
                    $total_int = (int) $total;
                    $items_label = sprintf(
                        /* translators: %s: Item count. */
                        _n('%s item', '%s items', $total_int, 'll-tools-text-domain'),
                        number_format_i18n($total_int)
                    );
                    echo '<span class="displaying-num">' . esc_html($items_label) . '</span>';
                }
                ?>
            </div>
        </div>
    </div>

    <style>
        .ll-top-settings .form-table th { width: 280px; }
        .status-badge { display:inline-block; background:#f3f4f5; padding:2px 6px; border-radius:3px; }
        .ll-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table.ll-bulk-table { width: auto; min-width: 100%; table-layout: auto; border-collapse: separate; border-spacing: 0; }
        .ll-bulk-table th, .ll-bulk-table td { vertical-align: middle; white-space: nowrap; }
        .ll-bulk-table .col-select { width: 34px; }
        .ll-bulk-table .col-id { width: 56px; }
        .ll-bulk-table .col-word { /* auto width by content */ }
        .ll-bulk-table .col-type { width: 90px; text-align:center; }
        .ll-bulk-table .col-wordset { /* auto by content */ }
        .ll-bulk-table .col-current { min-width: 360px; }
        .ll-bulk-table .col-suggest { min-width: 360px; }
        .ll-bulk-table .col-status { width: 120px; }
        .ll-suggest { display:flex; align-items:center; gap:6px; }
        .ll-suggest input.ll-suggestion { flex:1 1 auto; min-width: 240px; width: 100%; box-sizing:border-box; }
        .ll-current input { width:auto; min-width: 240px; max-width:100%; box-sizing:border-box; }
        .ll-suggest .ll-clear { text-decoration:none; font-weight:bold; }
        .ll-suggest .ll-save-one { text-decoration:none; font-weight:bold; color:#008a20; }
    </style>

    <?php
    $bulk_i18n = [
        'nothing_to_save_row' => __('Nothing to save for this row.', 'll-tools-text-domain'),
        'save_failed' => __('Save failed.', 'll-tools-text-domain'),
        'save_request_failed' => __('Save request failed.', 'll-tools-text-domain'),
        'select_at_least_one' => __('Select at least one row.', 'll-tools-text-domain'),
        'fetch_failed' => __('No response or failed to fetch.', 'll-tools-text-domain'),
        'request_failed' => __('Request failed.', 'll-tools-text-domain'),
        'nothing_to_save' => __('Nothing to save.', 'll-tools-text-domain'),
        'status_saving' => __('saving…', 'll-tools-text-domain'),
        'status_saved' => __('saved', 'll-tools-text-domain'),
        'status_error' => __('error', 'll-tools-text-domain'),
        'status_fetching' => __('fetching…', 'll-tools-text-domain'),
        'status_suggested' => __('suggested', 'll-tools-text-domain'),
        'status_no_match' => __('no match', 'll-tools-text-domain'),
        'all_categories' => __('All', 'll-tools-text-domain'),
    ];
    ?>
    <script>
    jQuery(function($){
        var nonce = <?php echo json_encode($nonce); ?>;
        var i18n = <?php echo wp_json_encode($bulk_i18n); ?>;
        var categoryOptionsByWordset = <?php echo wp_json_encode($category_option_map); ?> || {};
        var $categoryFilter = $('#ll-bulk-translations-category-filter');
        var $wordsetFilter = $('#ll-bulk-translations-wordset-filter');

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

        function renderCategoryFilterOptions() {
            if (!$categoryFilter.length) {
                return;
            }

            var selectedWordset = ($wordsetFilter.val() || '').toString();
            var currentValue = ($categoryFilter.val() || '0').toString();
            var rows = categoryOptionsByWordset[selectedWordset];
            if (!Array.isArray(rows)) {
                rows = categoryOptionsByWordset[''] || [];
            }

            var html = '<option value="0">' + $('<div/>').text(i18n.all_categories || 'All').html() + '</option>';
            rows.forEach(function(row) {
                var id = parseInt(row && row.id ? row.id : 0, 10) || 0;
                if (!id) {
                    return;
                }
                var label = (row && row.label ? row.label : '').toString();
                html += '<option value="' + id + '">' + $('<div/>').text(label).html() + '</option>';
            });

            $categoryFilter.html(html);
            if ($categoryFilter.find('option[value="' + currentValue + '"]').length) {
                $categoryFilter.val(currentValue);
            } else {
                $categoryFilter.val('0');
            }
        }

        renderCategoryFilterOptions();
        $wordsetFilter.on('change', renderCategoryFilterOptions);

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
            if (!id || !txt) { alert(i18n.nothing_to_save_row); return; }
            var $spinner = $tr.find('.ll-status .spinner');
            var $st = $tr.find('.status-badge');
            $spinner.addClass('is-active');
            $st.text(i18n.status_saving);
            $.post(ajaxurl, {
                action: 'll_bulk_translations_save',
                nonce: nonce,
                rows: [{ id: id, translation: txt }]
            }).done(function(resp){
                if (!resp || !resp.success) { alert(i18n.save_failed); return; }
                $tr.find('.ll-current input').val(txt);
                $st.text(i18n.status_saved);
            }).fail(function(){
                alert(i18n.save_request_failed);
                $st.text(i18n.status_error);
            }).always(function(){
                $spinner.removeClass('is-active');
            });
        });

        $('#ll-fetch').on('click', function(e){
            e.preventDefault();
            var ids = getSelectedIds();
            if (!ids.length) { alert(i18n.select_at_least_one); return; }

            // Activate global spinner and disable button
            $('#ll-fetch-spinner').addClass('is-active');
            $('#ll-fetch').prop('disabled', true);

            // Activate row spinners for selected rows and set status text
            ids.forEach(function(id){
                var $tr = $('#ll-rows tr[data-id="'+id+'"]');
                $tr.find('.ll-status .status-badge').text(i18n.status_fetching);
                $tr.find('.ll-status .spinner').addClass('is-active');
            });

            $.post(ajaxurl, {
                action: 'll_bulk_translations_fetch',
                nonce: nonce,
                ids: ids
            }).done(function(resp){
                if (!resp || !resp.success || !resp.data || !resp.data.rows) {
                    alert(i18n.fetch_failed);
                } else {
                    resp.data.rows.forEach(function(row){
                        var $tr = $('#ll-rows tr[data-id="'+row.id+'"]');
                        var $in = $tr.find('.ll-suggestion');
                        var $st = $tr.find('.status-badge');
                        if (row.suggestion && row.suggestion.length) {
                            $in.val(row.suggestion);
                            $st.text(i18n.status_suggested);
                        } else {
                            $st.text(i18n.status_no_match);
                        }
                    });
                }
            }).fail(function(){
                alert(i18n.request_failed);
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
            if (!payload.length) { alert(i18n.nothing_to_save); return; }

            $.post(ajaxurl, {
                action: 'll_bulk_translations_save',
                nonce: nonce,
                rows: payload
            }).done(function(resp){
                if (!resp || !resp.success) { alert(i18n.save_failed); return; }
                // Reflect saved values into "Current translation"
                payload.forEach(function(row){
                    var $tr = $('#ll-rows tr[data-id="'+row.id+'"]');
                    $tr.find('.ll-current input').val(row.translation);
                    $tr.find('.status-badge').text(i18n.status_saved);
                });
            }).fail(function(){
                alert(i18n.save_request_failed);
            });
        });
    });
    </script>
    <?php
}

/** Query helpers **/
function ll_bulk_translations_build_query_args($limit = null, $offset = null) {
    $flt_type    = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'both';
    $types = ($flt_type === 'words' || $flt_type === 'word_images') ? [$flt_type] : ['words','word_images'];
    $flt_s       = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $flt_cat     = isset($_GET['cat']) ? intval($_GET['cat']) : 0;
    $flt_wordset = isset($_GET['wordset']) ? sanitize_text_field($_GET['wordset']) : '';
    $flt_orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'ID';
    $flt_order   = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'ASC';

    $args = [
        'post_type'      => $types,
        'post_status'    => ['publish','draft','pending','future','private'],
        's'              => $flt_s,
        'orderby'        => in_array($flt_orderby, ['ID','title'], true) ? $flt_orderby : 'ID',
        'order'          => ($flt_order === 'DESC') ? 'DESC' : 'ASC',
        'fields'         => 'all',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'word_translation', 'compare' => 'NOT EXISTS' ],
            [ 'key' => 'word_translation', 'value' => '', 'compare' => '=' ],
        ],
    ];
    if ($limit !== null)  $args['posts_per_page'] = $limit;
    if ($offset !== null) $args['offset']         = $offset;

    $tax = [];
    if ($flt_cat) {
        if ($flt_wordset !== '') {
            $wordset_term = get_term_by('slug', $flt_wordset, 'wordset');
            if ($wordset_term instanceof WP_Term && !is_wp_error($wordset_term) && function_exists('ll_tools_get_effective_category_id_for_wordset')) {
                $effective_category_id = (int) ll_tools_get_effective_category_id_for_wordset($flt_cat, (int) $wordset_term->term_id, true);
                if ($effective_category_id > 0) {
                    $flt_cat = $effective_category_id;
                }
            }
        }
        $tax[] = [ 'taxonomy' => 'word-category', 'field' => 'term_id', 'terms' => $flt_cat ];
    }
    if ($flt_wordset !== '') {
        $tax[] = [ 'taxonomy' => 'wordset', 'field' => 'slug', 'terms' => $flt_wordset ];
    }
    if ($tax) $args['tax_query'] = $tax;

    return $args;
}

function ll_bulk_translation_query_posts($limit, $offset) {
    $args = ll_bulk_translations_build_query_args($limit, $offset);
    $q = new WP_Query($args);
    return $q->posts;
}

function ll_bulk_translation_count_posts_missing() {
    $args = ll_bulk_translations_build_query_args(1, 0);
    $args['paged'] = 1;
    $args['no_found_rows'] = false;
    $q = new WP_Query($args);
    return (int) $q->found_posts;
}

/**
 * Return editable translation-target post IDs from an incoming list.
 *
 * @param array $ids
 * @return int[]
 */
function ll_bulk_translations_filter_editable_post_ids(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id): bool {
        return $id > 0;
    })));
    if (empty($ids)) {
        return [];
    }

    $editable = [];
    foreach ($ids as $post_id) {
        $post = get_post($post_id);
        if (!($post instanceof WP_Post)) {
            continue;
        }
        if (!in_array((string) $post->post_type, ['words', 'word_images'], true)) {
            continue;
        }
        if (!current_user_can('edit_post', $post_id)) {
            continue;
        }
        $editable[] = (int) $post_id;
    }

    return $editable;
}

/** AJAX: fetch suggestions (DeepL → Dictionary fallback) */
add_action('wp_ajax_ll_bulk_translations_fetch', 'll_ajax_bulk_translations_fetch');
function ll_ajax_bulk_translations_fetch() {
    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll-bulk-translations', 'nonce');

    $requested_ids = isset($_POST['ids']) && is_array($_POST['ids']) ? (array) wp_unslash($_POST['ids']) : [];
    $ids = ll_bulk_translations_filter_editable_post_ids($requested_ids);
    if (empty($ids) && !empty($requested_ids)) {
        wp_send_json_error(['message' => __('You do not have permission to edit the selected items.', 'll-tools-text-domain')], 403);
    }
    if (empty($ids)) {
        wp_send_json_success(['rows' => []]);
    }

    // Prefer existing DeepL wiring if present
    if (!function_exists('translate_with_deepl')) {
        $deepl_file = trailingslashit(defined('LL_TOOLS_BASE_PATH') ? LL_TOOLS_BASE_PATH : plugin_dir_path(__FILE__) . '../..') . 'includes/admin/api/deepl-api.php';
        if (file_exists($deepl_file)) require_once $deepl_file;
    }

    $has_deepl = (string) get_option('ll_deepl_api_key') !== '';

    $out = [];
    foreach ($ids as $id) {
        $title = get_the_title($id);
        $suggestion = null;
        $wordset_ids = function_exists('ll_tools_get_post_wordset_ids')
            ? ll_tools_get_post_wordset_ids((int) $id)
            : [];
        $title_role = function_exists('ll_tools_get_wordset_title_language_role')
            ? ll_tools_get_wordset_title_language_role($wordset_ids)
            : 'target';
        $source_raw = ($title_role === 'translation')
            ? (function_exists('ll_tools_get_wordset_translation_language') ? ll_tools_get_wordset_translation_language($wordset_ids) : '')
            : (function_exists('ll_tools_get_wordset_target_language') ? ll_tools_get_wordset_target_language($wordset_ids) : '');
        $target_raw = ($title_role === 'translation')
            ? (function_exists('ll_tools_get_wordset_target_language') ? ll_tools_get_wordset_target_language($wordset_ids) : '')
            : (function_exists('ll_tools_get_wordset_translation_language') ? ll_tools_get_wordset_translation_language($wordset_ids) : '');
        $source_code = function_exists('ll_tools_resolve_language_code_from_label')
            ? (string) ll_tools_resolve_language_code_from_label((string) $source_raw, 'upper')
            : '';
        $target_code = function_exists('ll_tools_resolve_language_code_from_label')
            ? (string) ll_tools_resolve_language_code_from_label((string) $target_raw, 'upper')
            : '';
        $src = ($source_code === 'AUTO')
            ? 'auto'
            : (($source_code !== '') ? $source_code : (((string) $source_raw !== '') ? (string) $source_raw : 'auto'));
        $tgt = ($target_code !== '') ? $target_code : (string) $target_raw;
        $reverse_dict = ($title_role === 'translation');

        if ($has_deepl && $tgt !== '' && function_exists('translate_with_deepl')) {
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
    if (function_exists('ll_tools_dictionary_lookup_best')) {
        $entry_match = ll_tools_dictionary_lookup_best($word, $source_lang, $target_lang, $reverse);
        if ($entry_match !== null && trim((string) $entry_match) !== '') {
            return trim((string) $entry_match);
        }
    }

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
    if (!current_user_can('view_ll_tools')) {
        wp_send_json_error(['message' => __('You do not have permission.', 'll-tools-text-domain')], 403);
    }
    check_ajax_referer('ll-bulk-translations', 'nonce');

    $rows  = isset($_POST['rows']) && is_array($_POST['rows']) ? (array) wp_unslash($_POST['rows']) : [];
    $saved = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $id  = intval($row['id'] ?? 0);
        $txt = isset($row['translation']) ? wp_kses_post((string) $row['translation']) : '';
        if ($id <= 0 || $txt === '') {
            continue;
        }

        if (!in_array(get_post_type($id), ['words', 'word_images'], true) || !current_user_can('edit_post', $id)) {
            $skipped++;
            continue;
        }

        update_post_meta($id, 'word_translation', $txt);
        $saved++;
    }

    wp_send_json_success(['saved' => $saved, 'skipped' => $skipped]);
}

/** Admin-post: migrate legacy meta */
add_action('admin_post_ll_bulk_translations_migrate', 'll_handle_bulk_translations_migrate');
function ll_handle_bulk_translations_migrate() {
    if (!current_user_can('view_ll_tools')) {
        wp_die(__('You do not have permission.', 'll-tools-text-domain'), 403);
    }
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
        if (!current_user_can('edit_post', (int) $id)) {
            continue;
        }
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
