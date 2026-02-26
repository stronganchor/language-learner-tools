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
     *
     * Guarded so missing jQuery UI autocomplete (or missing localized data) does not
     * break the rest of the script, which now includes save-critical form compaction.
     */
    (function initWordsetLanguageAutocomplete() {
        var $languageInput = $("#wordset-language");
        if (!$languageInput.length) {
            return;
        }
        if (!$.fn || typeof $.fn.autocomplete !== 'function' || !$.ui || !$.ui.autocomplete) {
            return;
        }

        var localized = (typeof window.manageWordSetData === 'object' && window.manageWordSetData)
            ? window.manageWordSetData
            : {};
        var availableLanguages = Array.isArray(localized.availableLanguages)
            ? localized.availableLanguages.slice()
            : [];

        $languageInput.autocomplete({
            /**
             * Source callback for autocomplete suggestions.
             *
             * @param {Object} request - Contains the term entered by the user.
             * @param {Function} response - Callback to pass the matched suggestions.
             */
            source: function (request, response) {
                var matcher = new RegExp($.ui.autocomplete.escapeRegex(request.term), "i");
                var sortedArray = availableLanguages.slice().sort(function (a, b) {
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
                $languageInput.val(ui.item.label);
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
                $languageInput.val(ui.item.label);
                return false;
            }
        });
    })();

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
        var $form = $root.closest('form').first();
        var $prereqCompactInput = $();
        var $prereqCompactModeInput = $();

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

        function ensurePrereqCompactFields() {
            if (!$form.length || !window.JSON || typeof window.JSON.stringify !== 'function') {
                return false;
            }

            if (!$prereqCompactInput.length) {
                $prereqCompactInput = $form.find('input[name="ll_wordset_category_prereqs_compact"]').first();
                if (!$prereqCompactInput.length) {
                    $prereqCompactInput = $('<input>', {
                        type: 'hidden',
                        name: 'll_wordset_category_prereqs_compact'
                    });
                    $form.append($prereqCompactInput);
                }
            }

            if (!$prereqCompactModeInput.length) {
                $prereqCompactModeInput = $form.find('input[name="ll_wordset_category_prereqs_compact_mode"]').first();
                if (!$prereqCompactModeInput.length) {
                    $prereqCompactModeInput = $('<input>', {
                        type: 'hidden',
                        name: 'll_wordset_category_prereqs_compact_mode'
                    });
                    $form.append($prereqCompactModeInput);
                }
            }

            return true;
        }

        function markAndDetachLegacyPrereqNames() {
            var $prereqSelects = $root.find('select[name^="ll_wordset_category_prereqs["], select[data-ll-wordset-prereq-select]');
            if (!$prereqSelects.length) {
                return;
            }
            if (!ensurePrereqCompactFields()) {
                return;
            }

            $prereqSelects.each(function () {
                var $select = $(this);
                var currentName = String($select.attr('name') || '');
                var categoryId = parseInt($select.attr('data-ll-wordset-prereq-category-id'), 10) || 0;

                if (categoryId <= 0 && currentName) {
                    var match = currentName.match(/^ll_wordset_category_prereqs\[(\d+)\]\[\]$/);
                    if (match && match[1]) {
                        categoryId = parseInt(match[1], 10) || 0;
                    }
                }

                if (categoryId <= 0) {
                    return;
                }

                $select.attr('data-ll-wordset-prereq-select', '1');
                $select.attr('data-ll-wordset-prereq-category-id', String(categoryId));

                if (currentName) {
                    $select.attr('data-ll-wordset-prereq-legacy-name', currentName);
                    $select.removeAttr('name');
                }
            });
        }

        function buildCompactPrereqMap() {
            var out = {};

            $root.find('select[data-ll-wordset-prereq-select]').each(function () {
                var $select = $(this);
                var categoryId = parseInt($select.attr('data-ll-wordset-prereq-category-id'), 10) || 0;
                if (categoryId <= 0) {
                    return;
                }

                var values = $select.val();
                if (!Array.isArray(values) || !values.length) {
                    return;
                }

                var deps = [];
                var seen = {};
                values.forEach(function (rawValue) {
                    var depId = parseInt(rawValue, 10) || 0;
                    if (depId <= 0 || depId === categoryId || seen[depId]) {
                        return;
                    }
                    seen[depId] = true;
                    deps.push(depId);
                });

                if (!deps.length) {
                    return;
                }

                out[String(categoryId)] = deps;
            });

            return out;
        }

        function syncCompactPrereqInput() {
            var prereqSelectExists = $root.find('select[data-ll-wordset-prereq-select], select[name^="ll_wordset_category_prereqs["]').length > 0;
            if (!prereqSelectExists) {
                return;
            }
            if (!ensurePrereqCompactFields()) {
                return;
            }
            if (String($mode.val() || 'none') !== 'prerequisite') {
                $prereqCompactInput.val('');
                $prereqCompactModeInput.val('');
                return;
            }
            $prereqCompactInput.val(window.JSON.stringify(buildCompactPrereqMap()));
            $prereqCompactModeInput.val('json-v1');
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

        markAndDetachLegacyPrereqNames();
        syncCompactPrereqInput();

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

        $root.on('change', 'select[data-ll-wordset-prereq-select]', syncCompactPrereqInput);
        if ($form.length) {
            $form.on('submit.llWordsetPrereqCompact', function () {
                syncCompactPrereqInput();
            });
        }
    })();

    (function initAnswerOptionTextPreview() {
        var $preview = $('[data-ll-answer-option-preview-root]').first();
        var $fontFamily = $('[name="ll_wordset_answer_option_text_font_family"]').first();
        var $fontWeight = $('[name="ll_wordset_answer_option_text_font_weight"]').first();
        var $fontSize = $('[name="ll_wordset_answer_option_text_font_size_px"]').first();

        if (!$preview.length || !$fontWeight.length || !$fontSize.length) {
            return;
        }

        function hasCombiningMarks(value) {
            return /[\u0300-\u036F\u0591-\u05C7\u0610-\u061A\u064B-\u065F\u0670\u06D6-\u06ED]/.test(String(value || ''));
        }

        function clampInt(value, min, max, fallback) {
            var parsed = parseInt(value, 10);
            if (!isFinite(parsed)) {
                return fallback;
            }
            if (parsed < min) { return min; }
            if (parsed > max) { return max; }
            return parsed;
        }

        function readPreviewConfig() {
            var weight = String($fontWeight.val() || '700').trim();
            if (!/^(400|500|600|700|800|900)$/.test(weight)) {
                weight = '700';
            }

            var fontFamily = $fontFamily.length ? String($fontFamily.val() || '').trim() : '';
            fontFamily = fontFamily.replace(/[\r\n{};]/g, ' ').trim();

            return {
                fontFamily: fontFamily,
                fontWeight: weight,
                fontSizePx: clampInt($fontSize.val(), 12, 72, 48),
                minFontSizePx: 12,
                lineHeightRatio: 1.22,
                lineHeightRatioWithDiacritics: 1.4
            };
        }

        function applyPreviewVars(cfg) {
            var rootEl = $preview.get(0);
            if (!rootEl || !rootEl.style) {
                return;
            }
            if (cfg.fontFamily) {
                rootEl.style.setProperty('--ll-ws-answer-preview-font-family', cfg.fontFamily);
            } else {
                rootEl.style.removeProperty('--ll-ws-answer-preview-font-family');
            }
            rootEl.style.setProperty('--ll-ws-answer-preview-font-weight', cfg.fontWeight);
            rootEl.style.setProperty('--ll-ws-answer-preview-font-size', String(cfg.fontSizePx) + 'px');
            rootEl.style.setProperty('--ll-ws-answer-preview-line-height', String(cfg.lineHeightRatio));
            rootEl.style.setProperty('--ll-ws-answer-preview-line-height-marked', String(cfg.lineHeightRatioWithDiacritics));
        }

        var measureCanvas = null;
        var measureCtx = null;
        function measureTextWidth(text, font) {
            if (!measureCanvas) {
                measureCanvas = document.createElement('canvas');
            }
            if (!measureCtx) {
                measureCtx = measureCanvas.getContext('2d');
            }
            if (!measureCtx) {
                return 0;
            }
            try {
                measureCtx.font = String(font || '');
                return measureCtx.measureText(String(text || '')).width || 0;
            } catch (_) {
                return 0;
            }
        }

        function fitPreviewText(cardEl, textEl, cfg) {
            if (!cardEl || !textEl) {
                return;
            }

            var textValue = String(textEl.textContent || '').trim();
            var ratio = hasCombiningMarks(textValue) ? cfg.lineHeightRatioWithDiacritics : cfg.lineHeightRatio;
            var boxH = Math.max(0, (cardEl.clientHeight || 0) - 15);
            var boxW = Math.max(0, (cardEl.clientWidth || 0) - 15);

            if (cfg.fontFamily) {
                textEl.style.fontFamily = cfg.fontFamily;
            } else {
                textEl.style.fontFamily = '';
            }
            textEl.style.fontWeight = cfg.fontWeight;
            textEl.style.position = 'relative';
            textEl.style.visibility = 'hidden';

            var minFontSize = clampInt(cfg.minFontSizePx, 10, 24, 12);
            var startFontSize = clampInt(cfg.fontSizePx, minFontSize, 72, 48);
            var fitted = false;

            for (var fs = startFontSize; fs >= minFontSize; fs--) {
                var lineHeightPx = Math.round(fs * ratio * 100) / 100;
                var measureFont = String(cfg.fontWeight) + ' ' + String(fs) + 'px ' + (cfg.fontFamily || 'sans-serif');
                var singleLineWidth = measureTextWidth(textValue, measureFont);
                if (singleLineWidth > boxW && boxW > 0) {
                    continue;
                }
                textEl.style.fontSize = String(fs) + 'px';
                textEl.style.lineHeight = String(lineHeightPx) + 'px';
                if (textEl.offsetHeight <= boxH + 1) {
                    fitted = true;
                    break;
                }
            }

            if (!fitted) {
                var fallbackLineHeightPx = Math.round(minFontSize * ratio * 100) / 100;
                textEl.style.fontSize = String(minFontSize) + 'px';
                textEl.style.lineHeight = String(fallbackLineHeightPx) + 'px';
            }

            textEl.style.visibility = 'visible';
        }

        function refreshPreview() {
            var cfg = readPreviewConfig();
            applyPreviewVars(cfg);

            $preview.find('[data-ll-answer-option-preview-card]').each(function () {
                var cardEl = this;
                var textEl = $(cardEl).find('[data-ll-answer-option-preview-text]').get(0);
                fitPreviewText(cardEl, textEl, cfg);
            });
        }

        var resizeTimer = null;
        $(window).on('resize.llWordsetAnswerOptionPreview', function () {
            if (resizeTimer) {
                clearTimeout(resizeTimer);
            }
            resizeTimer = setTimeout(refreshPreview, 50);
        });

        $fontWeight.add($fontSize).on('input change', refreshPreview);
        if ($fontFamily.length) {
            $fontFamily.on('input change', refreshPreview);
        }

        refreshPreview();
    })();
});
