/**
 * manage-wordsets.js
 *
 * Handles the autocomplete functionality for the Word Set language selection input.
 */
jQuery(document).ready(function ($) {
    var sortLocale = String(document.documentElement.lang || '').trim().replace('_', '-');
    var sortLocales = [];
    if (sortLocale) {
        sortLocales.push(sortLocale);
        var primaryLocale = sortLocale.split('-')[0];
        if (primaryLocale && sortLocales.indexOf(primaryLocale) === -1) {
            sortLocales.push(primaryLocale);
        }
        if (primaryLocale && primaryLocale.toLowerCase() === 'tr' && sortLocales.indexOf('tr-TR') === -1) {
            sortLocales.push('tr-TR');
        }
    }
    sortLocales.push('en-US');
    var turkishSortLocales = (function (baseLocales) {
        var combined = [];
        var pushLocale = function (value) {
            var normalized = String(value || '').trim();
            if (!normalized || combined.indexOf(normalized) !== -1) { return; }
            combined.push(normalized);
        };
        pushLocale('tr-TR');
        pushLocale('tr');
        (Array.isArray(baseLocales) ? baseLocales : []).forEach(pushLocale);
        return combined;
    })(sortLocales);

    function textHasTurkishCharacters(value) {
        return /[çğıöşüÇĞİÖŞÜıİ]/.test(String(value || ''));
    }

    function localeTextCompare(left, right) {
        var a = String(left || '');
        var b = String(right || '');
        if (a === b) { return 0; }
        var opts = { numeric: true, sensitivity: 'base' };
        var locales = (textHasTurkishCharacters(a) || textHasTurkishCharacters(b))
            ? turkishSortLocales
            : sortLocales;
        try {
            return a.localeCompare(b, locales, opts);
        } catch (_) {
            try {
                return a.localeCompare(b, undefined, opts);
            } catch (_) {
                return a < b ? -1 : (a > b ? 1 : 0);
            }
        }
    }

    /**
     * Initializes the autocomplete feature on the #wordset-language input field.
     */
    $("#wordset-language").autocomplete({
        /**
         * Source callback for autocomplete suggestions.
         *
         * @param {Object} request - Contains the term entered by the user.
         * @param {Function} response - Callback to pass the matched suggestions.
         */
        source: function (request, response) {
            var matcher = new RegExp($.ui.autocomplete.escapeRegex(request.term), "i");
            var sortedArray = manageWordSetData.availableLanguages.sort(function (a, b) {
                var startsWithA = a.label.toUpperCase().startsWith(request.term.toUpperCase());
                var startsWithB = b.label.toUpperCase().startsWith(request.term.toUpperCase());
                if (startsWithA && !startsWithB) {
                    return -1;
                } else if (!startsWithA && startsWithB) {
                    return 1;
                } else {
                    return localeTextCompare(a.label, b.label);
                }
            });
            response($.grep(sortedArray, function (item) {
                return matcher.test(item.label);
            }));
        },
        minLength: 1,
        /**
         * Handler for selecting an autocomplete suggestion.
         *
         * @param {Event} event - The event object.
         * @param {Object} ui - Contains the selected item's information.
         * @returns {boolean} False to prevent the default behavior.
         */
        select: function (event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        },
        /**
         * Handler for focusing on an autocomplete suggestion.
         *
         * @param {Event} event - The event object.
         * @param {Object} ui - Contains the focused item's information.
         * @returns {boolean} False to prevent the default behavior.
         */
        focus: function (event, ui) {
            $("#wordset-language").val(ui.item.label);
            return false;
        }
    });

    (function initCategoryOrderingUI() {
        var $root = $('[data-ll-wordset-category-ordering]');
        if (!$root.length) {
            return;
        }

        var $mode = $root.find('[data-ll-wordset-category-ordering-mode]');
        var $manualPanel = $root.find('[data-ll-wordset-category-ordering-panel="manual"]');
        var $prereqPanel = $root.find('[data-ll-wordset-category-ordering-panel="prerequisite"]');
        var $manualList = $root.find('[data-ll-wordset-manual-order-list]');
        var $manualInput = $root.find('[data-ll-wordset-manual-order-input]');
        var $manualSortField = $root.find('[data-ll-wordset-manual-sort-field]');
        var $manualSortDirection = $root.find('[data-ll-wordset-manual-sort-direction]');
        var $manualSortApply = $root.find('[data-ll-wordset-manual-sort-apply]');

        function syncPanels() {
            var mode = String($mode.val() || 'none');
            if ($manualPanel.length) {
                $manualPanel.prop('hidden', mode !== 'manual');
            }
            if ($prereqPanel.length) {
                $prereqPanel.prop('hidden', mode !== 'prerequisite');
            }
        }

        function syncManualOrderInput() {
            if (!$manualList.length || !$manualInput.length) {
                return;
            }
            var ids = [];
            $manualList.children('[data-category-id]').each(function () {
                var id = parseInt($(this).attr('data-category-id'), 10);
                if (id > 0 && ids.indexOf(id) === -1) {
                    ids.push(id);
                }
            });
            $manualInput.val(ids.join(','));
        }

        function moveListItem($item, direction) {
            if (!$item || !$item.length) {
                return;
            }
            if (direction === 'up') {
                var $prev = $item.prev('[data-category-id]');
                if ($prev.length) {
                    $item.insertBefore($prev);
                }
            } else if (direction === 'down') {
                var $next = $item.next('[data-category-id]');
                if ($next.length) {
                    $item.insertAfter($next);
                }
            }
            syncManualOrderInput();
        }

        function sortManualListByPreset() {
            if (!$manualList.length) {
                return;
            }

            var field = String($manualSortField.val() || 'age');
            var direction = String($manualSortDirection.val() || 'asc') === 'desc' ? -1 : 1;
            var rows = [];

            $manualList.children('[data-category-id]').each(function (index) {
                var $item = $(this);
                rows.push({
                    el: this,
                    index: index,
                    name: String($item.attr('data-category-label') || '').trim(),
                    ageRank: parseInt($item.attr('data-sort-age-rank'), 10) || 0
                });
            });

            rows.sort(function (left, right) {
                var cmp = 0;

                if (field === 'name') {
                    cmp = localeTextCompare(left.name, right.name);
                } else {
                    if (left.ageRank !== right.ageRank) {
                        cmp = (left.ageRank < right.ageRank) ? -1 : 1;
                    }
                }

                if (cmp === 0) {
                    cmp = localeTextCompare(left.name, right.name);
                }
                if (cmp === 0 && left.index !== right.index) {
                    cmp = (left.index < right.index) ? -1 : 1;
                }

                return cmp * direction;
            });

            rows.forEach(function (row) {
                $manualList.append(row.el);
            });
            syncManualOrderInput();
        }

        if ($mode.length) {
            $mode.on('change', syncPanels);
            syncPanels();
        }

        if ($manualList.length) {
            if ($.fn.sortable) {
                $manualList.sortable({
                    axis: 'y',
                    tolerance: 'pointer',
                    update: syncManualOrderInput
                });
            }

            $manualList.on('click', '[data-ll-wordset-manual-move]', function (event) {
                event.preventDefault();
                var dir = String($(this).attr('data-ll-wordset-manual-move') || '');
                moveListItem($(this).closest('[data-category-id]'), dir);
            });

            if ($manualSortApply.length) {
                $manualSortApply.on('click', function (event) {
                    event.preventDefault();
                    sortManualListByPreset();
                });
            }

            syncManualOrderInput();
        }
    })();
});
