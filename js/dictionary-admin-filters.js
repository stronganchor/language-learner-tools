(function ($) {
    'use strict';

    $(function () {
        var data = window.llDictionaryAdminFilters || {};
        $('[data-ll-dictionary-admin-entry-filter]').each(function () {
            var $root = $(this);
            var $mode = $root.find('[data-ll-dictionary-filter-mode]');
            var $id = $root.find('[data-ll-dictionary-filter-id]');
            var $search = $root.find('[data-ll-dictionary-filter-search]');
            var $spinner = $root.find('[data-ll-dictionary-filter-spinner]');
            var selectedLabel = String($search.val() || '');
            var request = null;

            function syncMode() {
                var mode = String($mode.val() || 'all');
                if (mode === 'all') {
                    $id.val('');
                } else if (mode === 'none') {
                    $id.val('__none__');
                } else if (!/^\d+$/.test(String($id.val() || ''))) {
                    $id.val('');
                }
                $search.prop('disabled', mode !== 'entry');
            }

            $mode.on('change', syncMode);
            syncMode();

            if (typeof $search.autocomplete !== 'function') {
                return;
            }
            $search.autocomplete({
                minLength: 0,
                delay: 150,
                classes: {
                    'ui-autocomplete': 'll-dictionary-admin-autocomplete'
                },
                source: function (requestData, respond) {
                    if (request && typeof request.abort === 'function') {
                        request.abort();
                    }
                    $spinner.addClass('is-active');
                    request = $.post(String(data.ajaxUrl || window.ajaxurl || ''), {
                        action: 'll_tools_dictionary_admin_search_entries',
                        nonce: String(data.nonce || ''),
                        q: String(requestData.term || '')
                    }).done(function (response) {
                        var entries = response && response.success === true && response.data && Array.isArray(response.data.entries)
                            ? response.data.entries
                            : [];
                        respond(entries.map(function (entry) {
                            return {
                                id: parseInt(entry.id, 10) || 0,
                                label: String(entry.label || ''),
                                value: String(entry.label || '')
                            };
                        }));
                    }).fail(function (_xhr, status) {
                        if (status !== 'abort') {
                            respond([]);
                        }
                    }).always(function () {
                        $spinner.removeClass('is-active');
                    });
                },
                select: function (event, ui) {
                    event.preventDefault();
                    selectedLabel = String((ui.item && ui.item.label) || '');
                    $search.val(selectedLabel);
                    $id.val(String(parseInt(ui.item && ui.item.id, 10) || ''));
                    $mode.val('entry');
                    syncMode();
                },
                change: function (_event, ui) {
                    if (ui && ui.item) {
                        return;
                    }
                    if (String($search.val() || '') !== selectedLabel) {
                        $id.val('');
                    }
                }
            });
            $search.on('focus', function () {
                if (String($search.val() || '') === '') {
                    $search.autocomplete('search', '');
                }
            });
        });
    });
})(jQuery);
