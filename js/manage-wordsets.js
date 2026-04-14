/**
 * manage-wordsets.js
 *
 * Handles the autocomplete functionality for the Word Set language selection input.
 */
jQuery(document).ready(function ($) {
    function fallbackLocaleTextCompare(left, right) {
        var a = String(left || '');
        var b = String(right || '');
        if (a === b) { return 0; }
        try {
            return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
        } catch (_) {
            return a < b ? -1 : (a > b ? 1 : 0);
        }
    }
    var localeSort = (window.LLToolsLocaleSort && typeof window.LLToolsLocaleSort.createTextComparer === 'function')
        ? window.LLToolsLocaleSort
        : null;
    var localeTextCompare = localeSort
        ? localeSort.createTextComparer(document.documentElement.lang || '')
        : fallbackLocaleTextCompare;

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

    (function initCategoryLineupUI() {
        var $root = $('[data-ll-category-lineup-ordering]');
        if (!$root.length) {
            return;
        }

        var $list = $root.find('[data-ll-category-lineup-list]');
        var $orderInput = $root.find('[data-ll-category-lineup-order-input]');
        var $form = $root.closest('form').first();

        function syncOrderInput() {
            if (!$list.length || !$orderInput.length) {
                return;
            }

            var ids = [];
            $list.children('[data-ll-category-lineup-item]').each(function () {
                var id = parseInt($(this).attr('data-word-id'), 10);
                if (id > 0 && ids.indexOf(id) === -1) {
                    ids.push(id);
                }
            });

            $orderInput.val(ids.join(','));
        }

        function moveItem($item, direction) {
            if (!$item || !$item.length) {
                return;
            }
            if (direction === 'up') {
                var $prev = $item.prev('[data-ll-category-lineup-item]');
                if ($prev.length) {
                    $item.insertBefore($prev);
                }
            } else if (direction === 'down') {
                var $next = $item.next('[data-ll-category-lineup-item]');
                if ($next.length) {
                    $item.insertAfter($next);
                }
            }
            syncOrderInput();
        }

        if ($list.length && $.fn.sortable) {
            $list.sortable({
                axis: 'y',
                handle: '[data-ll-category-lineup-handle]',
                tolerance: 'pointer',
                update: syncOrderInput
            });
        }

        $list.on('click', '[data-ll-category-lineup-move]', function (event) {
            event.preventDefault();
            var dir = String($(this).attr('data-ll-category-lineup-move') || '');
            moveItem($(this).closest('[data-ll-category-lineup-item]'), dir);
        });

        if ($form.length) {
            $form.on('submit.llCategoryLineupOrder', function () {
                syncOrderInput();
            });
        }

        syncOrderInput();
    })();

    (function initAnswerOptionTextPreview() {
        var $preview = $('[data-ll-answer-option-preview-root]').first();
        var $fontFamily = $('[name="ll_wordset_answer_option_text_font_family"]').first();
        var $fontWeight = $('[name="ll_wordset_answer_option_text_font_weight"]').first();
        var $fontSize = $('[name="ll_wordset_answer_option_text_font_size_px"]').first();
        var $nextPairButton = $preview.find('[data-ll-answer-option-preview-next]').first();
        var $poolJsonScript = $preview.find('[data-ll-answer-option-preview-pool-json]').first();

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

        function shuffleArray(list) {
            var arr = Array.isArray(list) ? list.slice() : [];
            for (var i = arr.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var tmp = arr[i];
                arr[i] = arr[j];
                arr[j] = tmp;
            }
            return arr;
        }

        function parsePreviewPool() {
            if (!$poolJsonScript.length) {
                return [];
            }
            var raw = String($poolJsonScript.text() || '').trim();
            if (!raw) {
                return [];
            }

            try {
                var decoded = JSON.parse(raw);
                if (!Array.isArray(decoded)) {
                    return [];
                }
                var out = [];
                decoded.forEach(function (item) {
                    if (!item || typeof item !== 'object') {
                        return;
                    }
                    var text = String(item.text || '').trim();
                    if (!text) {
                        return;
                    }
                    var length = parseInt(item.length, 10);
                    if (!isFinite(length) || length < 0) {
                        length = text.length;
                    }
                    out.push({
                        text: text,
                        length: length
                    });
                });
                return out;
            } catch (_) {
                return [];
            }
        }

        function applyPreviewPair(pair) {
            if (!Array.isArray(pair) || !pair.length) {
                return;
            }

            var $cards = $preview.find('[data-ll-answer-option-preview-card]');
            $cards.each(function (index) {
                var sample = pair[index] || pair[pair.length - 1] || null;
                if (!sample) {
                    return;
                }
                var textValue = String(sample.text || '');
                var lengthValue = parseInt(sample.length, 10);
                if (!isFinite(lengthValue) || lengthValue < 0) {
                    lengthValue = textValue.length;
                }

                var $card = $(this);
                var textEl = $card.find('[data-ll-answer-option-preview-text]').get(0);
                if (textEl) {
                    textEl.textContent = textValue;
                }

                var $metaLength = $card.parent().find('[data-ll-answer-option-preview-meta-length]').first();
                if ($metaLength.length) {
                    $metaLength.text(String(lengthValue));
                }
            });
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
                minFontSizePx: 10,
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

        function applyPreviewTextSize(textEl, fontSizePx, lineHeightRatio, noWrap) {
            if (!textEl) {
                return;
            }
            var lineHeightPx = Math.round(fontSizePx * lineHeightRatio * 100) / 100;
            textEl.style.fontSize = String(fontSizePx) + 'px';
            textEl.style.lineHeight = String(lineHeightPx) + 'px';
            textEl.style.whiteSpace = noWrap ? 'nowrap' : 'normal';
        }

        function previewTextFits(textEl, maxHeight) {
            if (!textEl) {
                return false;
            }
            var availableWidth = Math.max(0, Math.floor(textEl.clientWidth || 0));
            var computed = window.getComputedStyle ? window.getComputedStyle(textEl) : null;
            var paddingY = computed
                ? (parseFloat(computed.paddingTop || '0') + parseFloat(computed.paddingBottom || '0'))
                : 0;
            var lineHeight = computed ? parseFloat(computed.lineHeight || '0') : 0;
            var measuredHeight = Math.max(0, Math.ceil(lineHeight + paddingY));
            var widthFits = availableWidth > 0
                ? Math.ceil(textEl.scrollWidth || 0) <= availableWidth + 1
                : true;

            return widthFits && measuredHeight <= Math.ceil(maxHeight) + 1;
        }

        function fitPreviewText(cardEl, textEl, cfg) {
            if (!cardEl || !textEl) {
                return;
            }

            var textValue = String(textEl.textContent || '').trim();
            var ratio = hasCombiningMarks(textValue) ? cfg.lineHeightRatioWithDiacritics : cfg.lineHeightRatio;
            var boxH = Math.max(0, (cardEl.clientHeight || 0) - 15);

            if (cfg.fontFamily) {
                textEl.style.fontFamily = cfg.fontFamily;
            } else {
                textEl.style.fontFamily = '';
            }
            textEl.style.fontWeight = cfg.fontWeight;
            textEl.style.position = 'relative';
            textEl.style.visibility = 'hidden';

            var minFontSize = clampInt(cfg.minFontSizePx, 10, 24, 10);
            var startFontSize = clampInt(cfg.fontSizePx, minFontSize, 72, 48);

            for (var fs = startFontSize; fs >= minFontSize; fs -= 0.5) {
                var normalizedSize = Math.round(fs * 100) / 100;
                applyPreviewTextSize(textEl, normalizedSize, ratio, true);
                if (previewTextFits(textEl, boxH)) {
                    textEl.style.visibility = 'visible';
                    return;
                }
            }

            applyPreviewTextSize(textEl, minFontSize, ratio, true);
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

        var previewPool = parsePreviewPool();
        var previewOrder = previewPool.length > 1 ? previewPool.slice() : [];
        var nextPairIndex = 2;
        if (previewOrder.length > 2) {
            previewOrder = shuffleArray(previewOrder);
            applyPreviewPair(previewOrder.slice(0, 2));
        }

        function showNextPreviewPair() {
            if (previewOrder.length <= 2) {
                return;
            }
            if (nextPairIndex >= previewOrder.length) {
                previewOrder = shuffleArray(previewPool);
                nextPairIndex = 0;
            }
            var nextPair = previewOrder.slice(nextPairIndex, nextPairIndex + 2);
            if (nextPair.length < 2 && previewPool.length > 1) {
                previewOrder = shuffleArray(previewPool);
                nextPairIndex = 0;
                nextPair = previewOrder.slice(0, 2);
            }
            if (!nextPair.length) {
                return;
            }
            applyPreviewPair(nextPair);
            nextPairIndex += 2;
            refreshPreview();
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
        if ($nextPairButton.length) {
            $nextPairButton.on('click', function (evt) {
                evt.preventDefault();
                showNextPreviewPair();
            });
        }

        refreshPreview();
    })();
});
