(function ($) {
    'use strict';

    const cfg = window.llIpaKeyboardAdmin || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const internalNotesCfg = cfg.internalNotes && typeof cfg.internalNotes === 'object' ? cfg.internalNotes : {};
    const internalNotesI18n = internalNotesCfg.i18n && typeof internalNotesCfg.i18n === 'object' ? internalNotesCfg.i18n : {};
    const internalNotesEnabled = !!internalNotesCfg.enabled;
    const internalNoteSaveDelayMs = Math.max(500, parseInt(internalNotesCfg.saveDelayMs, 10) || 3000);
    const wordsetStorageKey = 'llTranscriptionManagerLastWordsetId';
    const tabStorageKey = 'llTranscriptionManagerLastTab';
    const searchPageSize = Math.max(1, Math.min(500, parseInt(cfg.searchInitialPerPage, 10) || 20));

    const $admin = $('.ll-ipa-admin').first();
    if (!$admin.length) {
        return;
    }

    const $wordset = $('#ll-ipa-wordset');
    const $status = $('#ll-ipa-admin-status');
    const $tabButtons = $('[data-ll-tab-trigger]');
    const $tabPanels = $('[data-ll-tab-panel]');

    const $symbols = $('#ll-ipa-symbols');
    const $letterMap = $('#ll-ipa-letter-map');
    const $addInput = $('#ll-ipa-add-input');
    const $addBtn = $('#ll-ipa-add-btn');
    const $addLabel = $('#ll-ipa-add-label');
    const $symbolsHeading = $('#ll-ipa-symbols-heading');
    const $symbolsDescription = $('#ll-ipa-symbols-description');
    const $letterMapHeading = $('#ll-ipa-letter-map-heading');
    const $letterMapDescription = $('#ll-ipa-letter-map-description');

    const $searchQuery = $('#ll-ipa-search-query');
    const $searchScope = $('#ll-ipa-search-scope');
    const $searchIssuesOnly = $('#ll-ipa-search-issues-only');
    const $searchReviewOnly = $('#ll-ipa-search-review-only');
    const $searchExactTranscription = $('#ll-ipa-search-exact-transcription');
    const $searchBtn = $('#ll-ipa-search-btn');
    const $searchSummary = $('#ll-ipa-search-summary');
    const $searchResults = $('#ll-ipa-search-results');
    const $searchRules = $('#ll-ipa-search-rules');
    const $orthographySummary = $('#ll-ipa-orthography-summary');
    const $orthographyRules = $('#ll-ipa-orthography-rules');
    const $orthographyIssues = $('#ll-ipa-orthography-issues');
    const $orthographyConvert = $('#ll-ipa-orthography-convert');

    let currentWordsetId = 0;
    let currentTab = 'map';
    let currentTranscription = null;
    let currentAudio = null;
    let currentAudioButton = null;
    let currentCanEdit = false;
    let currentSearchPage = 1;
    let currentSearchPayload = null;
    let tabDirty = {
        map: true,
        symbols: true,
        search: true,
        orthography: true
    };
    let searchRulesExpanded = false;
    let letterMapRefreshTimer = null;
    let letterMapRefreshRequestId = 0;
    let pendingSearchReviewState = {};
    let pendingSearchEditorOpen = {};
    const searchReviewFields = ['recording_text', 'recording_ipa'];
    const maxInlineMismatchMarks = 4;
    const maxInlineMismatchCoverage = 0.35;
    let currentKeyboardSymbols = [];
    let activeIpaKeyboardInput = null;
    let $activeIpaKeyboard = $();
    let activeIpaKeyboardGroups = [];
    let ipaOptionalGroupsExpanded = false;
    let ipaKeyboardMouseInput = null;
    let ipaKeyboardMouseSelecting = false;
    let ipaKeyboardShowTimer = null;
    let $activeIpaSymbolMenu = $();
    let suppressSearchBlurSave = false;
    let suppressSearchWordReviewNoteBlurSave = false;
    let searchWordEditorRefreshTimer = null;

    function t(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(i18n, key) && typeof i18n[key] === 'string' && i18n[key] !== '') {
            return i18n[key];
        }
        return fallback;
    }

    function formatText(template, values) {
        let output = String(template || '');
        const list = Array.isArray(values) ? values : [];
        let nextIndex = 0;

        output = output.replace(/%(\d+)\$d/g, function (match, index) {
            const mappedIndex = parseInt(index, 10) - 1;
            if (!Number.isInteger(mappedIndex) || mappedIndex < 0 || typeof list[mappedIndex] === 'undefined') {
                return '';
            }
            return String(list[mappedIndex]);
        });

        output = output.replace(/%(\d+)\$s/g, function (match, index) {
            const mappedIndex = parseInt(index, 10) - 1;
            if (!Number.isInteger(mappedIndex) || mappedIndex < 0 || typeof list[mappedIndex] === 'undefined') {
                return '';
            }
            return String(list[mappedIndex]);
        });

        output = output.replace(/%1\$d/g, function () {
            if (typeof list[0] === 'undefined') {
                return '';
            }
            return String(list[0]);
        });

        output = output.replace(/%s/g, function () {
            if (typeof list[nextIndex] === 'undefined') {
                nextIndex += 1;
                return '';
            }
            const value = list[nextIndex];
            nextIndex += 1;
            return String(value);
        });

        return output;
    }

    function formatCount(count, singularKey, pluralKey, singularFallback, pluralFallback) {
        const template = count === 1 ? t(singularKey, singularFallback) : t(pluralKey, pluralFallback);
        return formatText(template, [count]);
    }

    function uniqueStringList(values) {
        const list = [];
        const seen = {};
        (Array.isArray(values) ? values : []).forEach(function (value) {
            const text = (value == null ? '' : String(value)).trim();
            if (!text || Object.prototype.hasOwnProperty.call(seen, text)) {
                return;
            }
            seen[text] = true;
            list.push(text);
        });
        return list;
    }

    function normalizeSearchPage(page) {
        const parsed = parseInt(page, 10) || 0;
        return parsed > 0 ? parsed : 1;
    }

    function buildDefaultTranscription() {
        return {
            mode: 'transcription',
            uses_ipa_font: false,
            supports_superscript: false,
            common_chars: [],
            common_chars_label: '',
            modifier_chars: [],
            modifier_chars_label: '',
            wordset_chars_label: '',
            keyboard_symbols: [],
            keyboard_groups: [],
            symbol_details: {},
            illegal_symbols: [],
            keyboard_aria_label: '',
            special_chars_heading: ($symbolsHeading.text() || '').toString().trim(),
            special_chars_description: ($symbolsDescription.text() || '').toString().trim(),
            special_chars_add_label: ($addLabel.text() || '').toString().trim(),
            special_chars_add_placeholder: ($addInput.attr('placeholder') || '').toString(),
            special_chars_empty: t('empty', 'No special characters found for this word set.'),
            symbols_column_label: t('pronunciationLabel', 'Pronunciation'),
            map_heading: ($letterMapHeading.text() || '').toString().trim(),
            map_description: ($letterMapDescription.text() || '').toString().trim(),
            map_sample_value_label: (t('pronunciationLabel', 'Pronunciation') + ':'),
            map_add_symbols_label: t('pronunciationLabel', 'Pronunciation'),
            map_add_symbols_placeholder: ($addInput.attr('placeholder') || '').toString(),
            map_add_missing: t('mapAddMissing', 'Enter letters and characters to add.')
        };
    }

    function getTranscription() {
        if (!currentTranscription) {
            currentTranscription = buildDefaultTranscription();
        }
        return currentTranscription;
    }

    function getCompactModifierChars() {
        const transcription = getTranscription();
        if (String(transcription.mode || '') !== 'ipa') {
            return [];
        }
        let modifiers = uniqueStringList(transcription.modifier_chars);
        if (!modifiers.length) {
            modifiers = ['ʰ', 'ʲ', 'ʷ', 'ː', '\u0325', '\u032A', '\u0306', '\u0361'];
        }
        return modifiers;
    }

    function getCompactingModifierChars(modifiers) {
        return (Array.isArray(modifiers) ? modifiers : []).filter(function (modifier) {
            return modifier !== '\u0361';
        });
    }

    function symbolUsesCompactModifier(symbol, modifiers) {
        const text = (symbol == null ? '' : String(symbol)).trim();
        if (!text) {
            return false;
        }
        if (/[\u035C\u0361]/u.test(text)) {
            return false;
        }
        return (Array.isArray(modifiers) ? modifiers : []).some(function (modifier) {
            return !!modifier && text !== modifier && text.indexOf(modifier) !== -1;
        });
    }

    function compactKeyboardSymbols(symbols, modifiers) {
        const compact = [];
        uniqueStringList(symbols).forEach(function (symbol) {
            if (symbolUsesCompactModifier(symbol, modifiers)) {
                return;
            }
            compact.push(symbol);
        });
        return compact;
    }

    function normalizeKeyboardGroups(groups) {
        const list = [];
        (Array.isArray(groups) ? groups : []).forEach(function (group) {
            const symbols = uniqueStringList(group && group.symbols ? group.symbols : []);
            if (!symbols.length) {
                return;
            }
            list.push({
                key: (group && group.key ? String(group.key) : '').trim(),
                label: (group && group.label ? String(group.label) : '').trim(),
                symbols: symbols
            });
        });
        return list;
    }

    function getSymbolDetail(symbol) {
        const text = (symbol == null ? '' : String(symbol)).trim();
        const transcription = getTranscription();
        const details = transcription && transcription.symbol_details && typeof transcription.symbol_details === 'object'
            ? transcription.symbol_details
            : {};
        const detail = text && Object.prototype.hasOwnProperty.call(details, text) && details[text] && typeof details[text] === 'object'
            ? details[text]
            : {};

        return {
            display: (detail.display == null || String(detail.display) === '') ? text : String(detail.display),
            label: (detail.label == null || String(detail.label) === '') ? text : String(detail.label)
        };
    }

    function getSymbolMenuLabel(symbol) {
        const detail = getSymbolDetail(symbol);
        return detail.display || symbol;
    }

    function canShowIpaKeyboard() {
        const transcription = getTranscription();
        return !!currentCanEdit
            && !!currentWordsetId
            && !!transcription
            && String(transcription.mode || '') === 'ipa';
    }

    function hideIpaKeyboard() {
        hideIpaSymbolContextMenu();
        if (ipaKeyboardShowTimer) {
            window.clearTimeout(ipaKeyboardShowTimer);
            ipaKeyboardShowTimer = null;
        }
        if ($activeIpaKeyboard.length) {
            $activeIpaKeyboard.remove();
        }
        $activeIpaKeyboard = $();
        activeIpaKeyboardGroups = [];
        ipaOptionalGroupsExpanded = false;
        activeIpaKeyboardInput = null;
        suppressSearchBlurSave = false;
        document.body.style.removeProperty('--ll-ipa-keyboard-bottom-padding');
        $(document.body).removeClass('ll-ipa-keyboard-open');
    }

    function cleanupDetachedIpaKeyboard() {
        if (!activeIpaKeyboardInput) {
            return;
        }

        if (document.body.contains(activeIpaKeyboardInput)) {
            return;
        }

        hideIpaKeyboard();
    }

    function applyKeyboardSymbols(symbols) {
        currentKeyboardSymbols = uniqueStringList(symbols);
        cleanupDetachedIpaKeyboard();

        if (!canShowIpaKeyboard()) {
            hideIpaKeyboard();
            return;
        }

        if (activeIpaKeyboardInput && document.body.contains(activeIpaKeyboardInput)) {
            showIpaKeyboardForInput($(activeIpaKeyboardInput));
        }
    }

    function getIpaKeyboardGroups() {
        const transcription = getTranscription();
        const configuredGroups = normalizeKeyboardGroups(transcription.keyboard_groups);
        if (configuredGroups.length) {
            return configuredGroups;
        }

        const modifiers = getCompactModifierChars();
        const compactingModifiers = getCompactingModifierChars(modifiers);
        const common = compactKeyboardSymbols(transcription.common_chars, compactingModifiers).filter(function (symbol) {
            return modifiers.indexOf(symbol) === -1;
        });
        const wordset = compactKeyboardSymbols(currentKeyboardSymbols, compactingModifiers).filter(function (symbol) {
            return modifiers.indexOf(symbol) === -1 && common.indexOf(symbol) === -1;
        });
        const groups = [];

        if (modifiers.length) {
            groups.push({
                label: (transcription.modifier_chars_label || '').toString(),
                symbols: modifiers
            });
        }

        if (common.length) {
            groups.push({
                label: (transcription.common_chars_label || '').toString(),
                symbols: common
            });
        }

        if (wordset.length) {
            groups.push({
                label: (transcription.wordset_chars_label || '').toString(),
                symbols: wordset
            });
        }

        return groups;
    }

    function ipaKeyboardGroupIsOptionalOnTightScreens(group) {
        const key = (group && group.key ? String(group.key) : '').trim();
        return key === 'rare' || key === 'other';
    }

    function getPrimaryIpaKeyboardGroups(groups) {
        return (Array.isArray(groups) ? groups : []).filter(function (group) {
            return !ipaKeyboardGroupIsOptionalOnTightScreens(group);
        });
    }

    function getOptionalIpaKeyboardGroups(groups) {
        return (Array.isArray(groups) ? groups : []).filter(ipaKeyboardGroupIsOptionalOnTightScreens);
    }

    function getIpaKeyboardOptionalGroupsLabel(groups) {
        const labels = [];
        getOptionalIpaKeyboardGroups(groups).forEach(function (group) {
            const label = (group && group.label ? String(group.label) : '').trim();
            if (label && labels.indexOf(label) === -1) {
                labels.push(label);
            }
        });
        return labels.join(' / ');
    }

    function buildIpaKeyboardOptionalToggle(groups) {
        const label = getIpaKeyboardOptionalGroupsLabel(groups);
        if (!label) {
            return $();
        }
        const buttonText = ipaOptionalGroupsExpanded
            ? formatText(t('keyboardOptionalGroupsHide', 'Hide %s'), [label])
            : formatText(t('keyboardOptionalGroupsShow', 'Show %s'), [label]);
        return $('<div>', {
            class: 'll-ipa-inline-keyboard-optional-toggle-wrap',
            'data-ll-ipa-keyboard-optional-toggle-wrap': '1'
        }).append($('<button>', {
            type: 'button',
            class: 'll-ipa-inline-keyboard-optional-toggle',
            'data-ll-ipa-keyboard-optional-toggle': '1',
            'aria-expanded': ipaOptionalGroupsExpanded ? 'true' : 'false',
            text: buttonText
        }));
    }

    function buildIpaKeyboardPanel(groups) {
        const transcription = getTranscription();
        const $panel = $('<div>', {
            class: 'll-ipa-inline-keyboard',
            'data-ll-ipa-inline-keyboard': '1',
            'aria-label': transcription.keyboard_aria_label || transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation')
        });

        groups.forEach(function (group) {
            const $group = $('<div>', {
                class: 'll-ipa-inline-keyboard-group',
                'data-ll-ipa-keyboard-group': (group.key || '').toString()
            });
            if (group.label) {
                $group.append($('<div>', {
                    class: 'll-ipa-inline-keyboard-label',
                    text: group.label
                }));
            }

            const $row = $('<div>', { class: 'll-ipa-inline-keyboard-row' });
            group.symbols.forEach(function (symbol) {
                $row.append(buildIpaKeyboardKey(symbol));
            });
            $group.append($row);
            $panel.append($group);
        });

        const $optionalToggle = buildIpaKeyboardOptionalToggle(activeIpaKeyboardGroups);
        if ($optionalToggle.length) {
            $panel.append($optionalToggle);
        }

        return $panel;
    }

    function renderIpaKeyboardGroups($wrap, groups, compact) {
        const primaryGroups = getPrimaryIpaKeyboardGroups(groups);
        const optionalGroups = ipaOptionalGroupsExpanded ? getOptionalIpaKeyboardGroups(groups) : [];
        const visibleGroups = primaryGroups.concat(optionalGroups);
        $wrap
            .attr('data-ll-ipa-keyboard-compact', compact ? '1' : '0')
            .empty()
            .append(buildIpaKeyboardPanel(visibleGroups));
    }

    function getIpaViewportMetrics() {
        const layoutHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const visualViewport = window.visualViewport || null;
        const visualHeight = visualViewport && typeof visualViewport.height === 'number' && visualViewport.height > 0
            ? visualViewport.height
            : layoutHeight;
        const visualOffsetTop = visualViewport && typeof visualViewport.offsetTop === 'number'
            ? Math.max(0, visualViewport.offsetTop)
            : 0;
        const visualBottom = visualHeight ? visualOffsetTop + visualHeight : layoutHeight;
        const hiddenBottom = layoutHeight && visualBottom
            ? Math.max(0, layoutHeight - visualBottom)
            : 0;

        return {
            height: visualHeight || layoutHeight,
            top: visualOffsetTop,
            bottom: visualBottom || layoutHeight,
            hiddenBottom: hiddenBottom
        };
    }

    function getIpaKeyboardViewportMaxHeight() {
        const viewportHeight = getIpaViewportMetrics().height;
        if (!viewportHeight) {
            return 360;
        }
        return Math.max(170, viewportHeight - 84);
    }

    function getScrollableIpaFieldContainer(input) {
        const $parents = $(input).parents();
        for (let index = 0; index < $parents.length; index += 1) {
            const element = $parents.get(index);
            if (!element || element === document.body || element === document.documentElement) {
                continue;
            }
            const overflowY = (window.getComputedStyle(element).overflowY || '').toString();
            if (/(auto|scroll)/.test(overflowY) && element.scrollHeight > element.clientHeight) {
                return element;
            }
        }
        return null;
    }

    function ensureActiveIpaFieldVisible() {
        const input = activeIpaKeyboardInput;
        if (!input || !$activeIpaKeyboard.length || !document.body.contains(input)) {
            return;
        }

        const keyboardHeight = $activeIpaKeyboard.outerHeight() || 0;
        const viewport = getIpaViewportMetrics();
        if (!viewport.height) {
            return;
        }

        const margin = 14;
        const rect = input.getBoundingClientRect();
        const keyboardTop = viewport.bottom - keyboardHeight - margin;
        const viewportTop = viewport.top + margin;
        let delta = 0;
        if (rect.bottom > keyboardTop) {
            delta = rect.bottom - keyboardTop;
        } else if (rect.top < viewportTop) {
            delta = rect.top - viewportTop;
        }

        if (Math.abs(delta) < 1) {
            return;
        }

        const scrollContainer = getScrollableIpaFieldContainer(input);
        if (scrollContainer) {
            scrollContainer.scrollTop += delta;
            return;
        }

        window.scrollBy(0, delta);
    }

    function fitIpaKeyboardToViewport() {
        if (!$activeIpaKeyboard.length || !activeIpaKeyboardInput || !document.body.contains(activeIpaKeyboardInput)) {
            return;
        }

        const maxHeight = getIpaKeyboardViewportMaxHeight();
        $activeIpaKeyboard.css('--ll-ipa-keyboard-max-height', maxHeight + 'px');
        renderIpaKeyboardGroups($activeIpaKeyboard, activeIpaKeyboardGroups, false);

        const hasOptionalGroups = activeIpaKeyboardGroups.some(ipaKeyboardGroupIsOptionalOnTightScreens);
        const viewport = getIpaViewportMetrics();
        const viewportHeight = viewport.height;
        const tightViewport = viewportHeight > 0 && viewportHeight < 520;
        if (hasOptionalGroups && (tightViewport || ($activeIpaKeyboard.outerHeight() || 0) > maxHeight)) {
            renderIpaKeyboardGroups($activeIpaKeyboard, activeIpaKeyboardGroups, true);
        }

        const keyboardHeight = Math.min($activeIpaKeyboard.outerHeight() || 0, maxHeight);
        $activeIpaKeyboard.css('--ll-ipa-native-keyboard-bottom', viewport.hiddenBottom + 'px');
        document.body.style.setProperty('--ll-ipa-keyboard-bottom-padding', (keyboardHeight + viewport.hiddenBottom + 18) + 'px');
        $(document.body).addClass('ll-ipa-keyboard-open');
        ensureActiveIpaFieldVisible();
    }

    function captureIpaInputSelection(input) {
        if (!input || typeof input.selectionStart !== 'number' || typeof input.selectionEnd !== 'number') {
            return null;
        }
        return {
            value: (input.value || '').toString(),
            start: input.selectionStart,
            end: input.selectionEnd,
            direction: input.selectionDirection || 'none'
        };
    }

    function restoreIpaInputSelection(input, selection) {
        if (!input || !selection || document.activeElement !== input || typeof input.setSelectionRange !== 'function') {
            return;
        }
        const value = (input.value || '').toString();
        if (value !== selection.value) {
            return;
        }
        const start = Math.min(selection.start, value.length);
        const end = Math.min(selection.end, value.length);
        input.setSelectionRange(start, end, selection.direction || 'none');
    }

    function buildIpaKeyboardKey(symbol) {
        const detail = getSymbolDetail(symbol);
        const display = detail.display || symbol;
        const label = detail.label || symbol;
        const ariaLabel = label && label !== display ? (display + ': ' + label) : display;
        return $('<button>', {
            type: 'button',
            class: 'll-ipa-inline-key',
            text: display,
            'data-ipa-char': symbol,
            'aria-label': ariaLabel,
            title: label
        });
    }

    function showIpaKeyboardForInput($input) {
        const $field = $input && $input.length ? $input.first() : $();
        if (!$field.length) {
            hideIpaKeyboard();
            return;
        }

        cleanupDetachedIpaKeyboard();
        if (!canShowIpaKeyboard()) {
            hideIpaKeyboard();
            return;
        }

        const groups = getIpaKeyboardGroups();
        if (!groups.length) {
            hideIpaKeyboard();
            return;
        }

        const input = $field.get(0);
        const selection = captureIpaInputSelection(input);
        if (activeIpaKeyboardInput === input && $activeIpaKeyboard.length) {
            activeIpaKeyboardGroups = groups;
            fitIpaKeyboardToViewport();
            restoreIpaInputSelection(input, selection);
            return;
        }

        hideIpaKeyboard();

        $activeIpaKeyboard = $('<div>', {
            class: 'll-ipa-inline-keyboard-wrap',
            'data-ll-ipa-inline-keyboard-wrap': '1'
        });
        activeIpaKeyboardGroups = groups;
        activeIpaKeyboardInput = input;
        $admin.append($activeIpaKeyboard);
        fitIpaKeyboardToViewport();
        restoreIpaInputSelection(input, selection);
    }

    function scheduleIpaKeyboardForInput(input, delay) {
        if (ipaKeyboardShowTimer) {
            window.clearTimeout(ipaKeyboardShowTimer);
        }
        ipaKeyboardShowTimer = window.setTimeout(function () {
            ipaKeyboardShowTimer = null;
            if (!input || !document.body.contains(input) || document.activeElement !== input) {
                return;
            }
            showIpaKeyboardForInput($(input));
        }, Math.max(0, delay || 0));
    }

    function handleIpaFieldMouseDown() {
        ipaKeyboardMouseInput = this;
        ipaKeyboardMouseSelecting = true;
        if (ipaKeyboardShowTimer) {
            window.clearTimeout(ipaKeyboardShowTimer);
            ipaKeyboardShowTimer = null;
        }
    }

    function handleIpaFieldFocus() {
        if (ipaKeyboardMouseSelecting && ipaKeyboardMouseInput === this) {
            return;
        }
        scheduleIpaKeyboardForInput(this, 0);
    }

    function insertIpaKeyboardChar(input, symbol) {
        if (!input || !symbol) {
            return;
        }

        const value = (input.value || '').toString();
        const start = typeof input.selectionStart === 'number' ? input.selectionStart : value.length;
        const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : value.length;
        const nextValue = value.slice(0, start) + symbol + value.slice(end);
        const nextCursor = start + symbol.length;

        input.value = nextValue;
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(nextCursor, nextCursor);
        }

        $(input).trigger('input');
    }

    function normalizeTypedIpaValue(value) {
        const text = (value == null ? '' : String(value));
        const transcription = getTranscription();
        if (!transcription || String(transcription.mode || '') !== 'ipa') {
            return text;
        }

        return text.replace(/\u0131/g, '\u026A');
    }

    function normalizeIpaInputElement(input) {
        if (!input) {
            return false;
        }

        const raw = (input.value || '').toString();
        const normalized = normalizeTypedIpaValue(raw);
        if (raw === normalized) {
            return false;
        }

        const start = typeof input.selectionStart === 'number' ? input.selectionStart : normalized.length;
        const end = typeof input.selectionEnd === 'number' ? input.selectionEnd : normalized.length;
        const nextStart = normalizeTypedIpaValue(raw.slice(0, start)).length;
        const nextEnd = normalizeTypedIpaValue(raw.slice(0, end)).length;
        input.value = normalized;
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(nextStart, nextEnd);
        }

        return true;
    }

    function hideIpaSymbolContextMenu() {
        if ($activeIpaSymbolMenu.length) {
            $activeIpaSymbolMenu.remove();
        }
        $activeIpaSymbolMenu = $();
    }

    function showIpaSymbolContextMenu($key, event) {
        const symbol = ($key.attr('data-ipa-char') || '').toString();
        if (!symbol || !currentCanEdit || !currentWordsetId) {
            return;
        }

        hideIpaSymbolContextMenu();
        const label = getSymbolMenuLabel(symbol);
        const $button = $('<button>', {
            type: 'button',
            class: 'll-ipa-symbol-menu-action',
            text: t('keyboardFlagIllegal', 'Flag as illegal symbol'),
            'data-ipa-char': symbol,
            role: 'menuitem'
        });
        $activeIpaSymbolMenu = $('<div>', {
            class: 'll-ipa-symbol-menu',
            role: 'menu',
            'aria-label': label
        }).append($button);
        $('body').append($activeIpaSymbolMenu);

        const menu = $activeIpaSymbolMenu.get(0);
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft || 0;
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
        const width = menu ? menu.offsetWidth : 180;
        const height = menu ? menu.offsetHeight : 44;
        const keyOffset = $key.offset() || { left: scrollLeft, top: scrollTop };
        let left = event && typeof event.pageX === 'number' ? event.pageX : (keyOffset.left || scrollLeft);
        let top = event && typeof event.pageY === 'number' ? event.pageY : ((keyOffset.top || scrollTop) + $key.outerHeight());

        if (viewportWidth && left + width > scrollLeft + viewportWidth - 8) {
            left = Math.max(scrollLeft + 8, scrollLeft + viewportWidth - width - 8);
        }
        if (viewportHeight && top + height > scrollTop + viewportHeight - 8) {
            top = Math.max(scrollTop + 8, scrollTop + viewportHeight - height - 8);
        }

        $activeIpaSymbolMenu.css({ left: left + 'px', top: top + 'px' });
        $button.trigger('focus');
    }

    function flagIpaKeyboardSymbolIllegal(symbol) {
        symbol = (symbol == null ? '' : String(symbol)).trim();
        if (!symbol || !currentWordsetId || !currentCanEdit) {
            return;
        }

        const label = getSymbolMenuLabel(symbol);
        if (!window.confirm(formatText(t('keyboardFlagIllegalConfirm', 'Mark %s as illegal for this word set?'), [label]))) {
            return;
        }

        setStatus(t('keyboardFlagIllegalSaving', 'Flagging symbol and rescanning this word set...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_flag_ipa_keyboard_illegal_symbol',
            nonce: nonce,
            wordset_id: currentWordsetId,
            symbol: symbol
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            const data = response.data || {};
            if (data.transcription) {
                applyTranscriptionConfig(data.transcription);
            }
            markTabsDirty(['symbols', 'search', 'orthography']);
            loadSearch(currentWordsetId, true, {
                quietStatus: true,
                showLoading: false,
                successStatus: t('keyboardFlagIllegalSaved', 'Symbol marked illegal and checks rescanned.')
            });
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function applyTranscriptionConfig(config) {
        currentTranscription = $.extend({}, buildDefaultTranscription(), config || {});
        currentTranscription.common_chars = uniqueStringList(currentTranscription.common_chars);
        currentTranscription.modifier_chars = uniqueStringList(currentTranscription.modifier_chars);
        currentTranscription.keyboard_symbols = uniqueStringList(currentTranscription.keyboard_symbols);
        currentTranscription.keyboard_groups = normalizeKeyboardGroups(currentTranscription.keyboard_groups);
        currentTranscription.symbol_details = currentTranscription.symbol_details && typeof currentTranscription.symbol_details === 'object'
            ? currentTranscription.symbol_details
            : {};
        currentTranscription.illegal_symbols = uniqueStringList(currentTranscription.illegal_symbols);
        currentTranscription.supports_superscript = !!currentTranscription.supports_superscript
            && String(currentTranscription.mode || '') === 'ipa';
        applyKeyboardSymbols(currentTranscription.keyboard_symbols);
        $admin.attr('data-ll-secondary-text-mode', currentTranscription.mode || 'transcription');
        $addLabel.text(currentTranscription.special_chars_add_label || 'Add characters');
        $addInput.attr('placeholder', currentTranscription.special_chars_add_placeholder || 'e.g. IPA symbols');
        $symbolsHeading.text(currentTranscription.special_chars_heading || 'IPA Special Characters');
        $symbolsDescription.text(currentTranscription.special_chars_description || '');
        $letterMapHeading.text(currentTranscription.map_heading || 'Letter to IPA Map');
        $letterMapDescription.text(currentTranscription.map_description || '');
    }

    function setStatus(message, isError) {
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
        if (!message) {
            $status.removeClass('is-error');
        }
    }

    function setSearchSummary(message) {
        $searchSummary.text(message || '');
    }

    function rememberWordset(wordsetId) {
        if (!window.localStorage) {
            return;
        }

        try {
            if (wordsetId) {
                window.localStorage.setItem(wordsetStorageKey, String(wordsetId));
            } else {
                window.localStorage.removeItem(wordsetStorageKey);
            }
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function getStoredWordset() {
        if (!$wordset.length || !window.localStorage) {
            return 0;
        }

        try {
            const stored = window.localStorage.getItem(wordsetStorageKey) || '';
            if (!stored) {
                return 0;
            }
            const hasOption = Array.prototype.some.call($wordset[0].options || [], function (option) {
                return option && option.value === stored;
            });
            return hasOption ? (parseInt(stored, 10) || 0) : 0;
        } catch (err) {
            return 0;
        }
    }

    function normalizeTabName(tabName) {
        const safeTab = (tabName || '').toString();
        return ['map', 'symbols', 'search', 'orthography'].indexOf(safeTab) >= 0 ? safeTab : 'map';
    }

    function getRequestedTabFromUrl() {
        if (!window.URLSearchParams) {
            return '';
        }

        const params = new window.URLSearchParams(window.location.search || '');
        if (!params.has('tab')) {
            return '';
        }

        return normalizeTabName(params.get('tab'));
    }

    function rememberTab(tabName) {
        if (!window.localStorage) {
            return;
        }

        try {
            window.localStorage.setItem(tabStorageKey, normalizeTabName(tabName));
        } catch (err) {
            // Ignore storage failures.
        }
    }

    function getStoredTab() {
        if (!window.localStorage) {
            return '';
        }

        try {
            const stored = window.localStorage.getItem(tabStorageKey) || '';
            if (!stored) {
                return '';
            }
            return normalizeTabName(stored);
        } catch (err) {
            return '';
        }
    }

    function markTabsDirty(tabs) {
        const list = Array.isArray(tabs) ? tabs : [tabs];
        list.forEach(function (tab) {
            if (tabDirty.hasOwnProperty(tab)) {
                tabDirty[tab] = true;
            }
        });
    }

    function stopCurrentAudio() {
        if (currentAudioButton) {
            $(currentAudioButton).removeClass('is-playing');
        }
        currentAudioButton = null;

        if (!currentAudio) {
            return;
        }

        currentAudio.pause();
        currentAudio.currentTime = 0;
        currentAudio = null;
    }

    function playAudio(button) {
        const url = (button.getAttribute('data-audio-url') || '').trim();
        if (!url || button.disabled) {
            return;
        }

        if (currentAudio && currentAudioButton === button && !currentAudio.paused) {
            stopCurrentAudio();
            return;
        }

        stopCurrentAudio();
        currentAudio = new Audio(url);
        currentAudioButton = button;

        currentAudio.addEventListener('play', function () {
            if (currentAudioButton === button) {
                $(button).addClass('is-playing');
            }
        });
        currentAudio.addEventListener('pause', function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
        });
        currentAudio.addEventListener('ended', function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
            currentAudio = null;
            currentAudioButton = null;
        });
        currentAudio.addEventListener('error', function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
            currentAudio = null;
            currentAudioButton = null;
        });

        currentAudio.play().catch(function () {
            if (currentAudioButton === button) {
                $(button).removeClass('is-playing');
            }
            currentAudio = null;
            currentAudioButton = null;
        });
    }

    function createAudioButton(rec, extraClass, options) {
        const settings = $.extend({ showDownload: false }, options || {});
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const recordingType = rec && rec.recording_type ? rec.recording_type : '';
        const recordingTypeSlug = rec && rec.recording_type_slug ? rec.recording_type_slug : '';
        const recordingIconType = rec && rec.recording_icon_type ? rec.recording_icon_type : 'isolation';
        const audioUrl = rec && rec.audio_url ? rec.audio_url : '';
        const audioLabel = rec && rec.audio_label ? rec.audio_label : t('playRecording', 'Play recording');
        const downloadLabel = t('downloadRecording', 'Download recording');
        if (!audioUrl) {
            return $('<span>', { class: 'll-ipa-search-audio-empty', text: '—' });
        }

        const $audioButton = $('<button>', {
            type: 'button',
            class: 'll-study-recording-btn ll-ipa-recording-audio-btn ll-study-recording-btn--' + recordingIconType + (extraClass ? (' ' + extraClass) : ''),
            'data-audio-url': audioUrl,
            'data-recording-id': recordingId,
            'data-recording-type': recordingTypeSlug,
            'aria-label': audioLabel,
            title: audioLabel
        });
        $audioButton.append($('<span>', { class: 'll-study-recording-icon', 'aria-hidden': 'true' }));
        const $visualizer = $('<span>', { class: 'll-study-recording-visualizer', 'aria-hidden': 'true' });
        for (let index = 0; index < 4; index += 1) {
            $visualizer.append($('<span>', { class: 'bar' }));
        }
        $audioButton.append($visualizer);

        const $buttons = $('<div>', { class: 'll-ipa-recording-buttons' }).append($audioButton);
        if (settings.showDownload) {
            $buttons.append(
                $('<a>', {
                    class: 'll-ipa-recording-download-btn',
                    href: audioUrl,
                    download: '',
                    target: '_blank',
                    rel: 'noopener',
                    'aria-label': downloadLabel,
                    title: downloadLabel
                }).append($('<span>', { class: 'll-ipa-recording-download-icon', 'aria-hidden': 'true' }))
            );
        }

        const $wrap = $('<div>', { class: 'll-ipa-recording-cell' });
        $wrap.append($buttons);
        if (recordingType) {
            $wrap.append($('<span>', { class: 'll-ipa-recording-type-label', text: recordingType }));
        }

        return $wrap;
    }

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function setTab(tabName) {
        hideIpaKeyboard();
        const nextTab = normalizeTabName(tabName);
        currentTab = nextTab;
        rememberTab(nextTab);

        $tabButtons.each(function () {
            const $button = $(this);
            const isActive = ($button.attr('data-ll-tab-trigger') || '') === nextTab;
            $button.toggleClass('is-active', isActive);
            $button.attr('aria-selected', isActive ? 'true' : 'false');
        });

        $tabPanels.each(function () {
            const $panel = $(this);
            const isActive = ($panel.attr('data-ll-tab-panel') || '') === nextTab;
            $panel.toggleClass('is-active', isActive);
            if (isActive) {
                $panel.removeAttr('hidden');
            } else {
                $panel.attr('hidden', 'hidden');
            }
        });

        loadActiveTab();
    }

    function setCanEdit(canEdit) {
        currentCanEdit = !!canEdit;
        if (!currentCanEdit) {
            hideIpaKeyboard();
        }
    }

    function handleWordsetResponse(response) {
        const data = response && response.data ? response.data : {};
        setCanEdit(!!data.can_edit);
        applyTranscriptionConfig(data.transcription || null);
    }

    function getSelectedWordsetId() {
        return parseInt($wordset.val(), 10) || 0;
    }

    function clearCurrentTab() {
        hideIpaKeyboard();
        if (currentTab === 'map') {
            $letterMap.empty();
        } else if (currentTab === 'symbols') {
            $symbols.empty();
        } else if (currentTab === 'search') {
            $searchRules.empty();
            $searchResults.empty();
            setSearchSummary('');
            currentSearchPayload = null;
        } else if (currentTab === 'orthography') {
            $orthographySummary.empty();
            $orthographyRules.empty();
            $orthographyIssues.empty();
            $orthographyConvert.empty();
        }
    }

    function loadActiveTab(force) {
        const wordsetId = currentWordsetId;
        clearCurrentTab();

        if (!wordsetId) {
            setStatus(t('noWordsets', 'No word sets are available for this page.'), true);
            return;
        }

        if (currentTab === 'map') {
            loadLetterMap(wordsetId, !!force || tabDirty.map);
            return;
        }

        if (currentTab === 'symbols') {
            loadSymbols(wordsetId, !!force || tabDirty.symbols);
            return;
        }

        if (currentTab === 'orthography') {
            loadOrthography(wordsetId, !!force || tabDirty.orthography);
            return;
        }

        loadSearch(wordsetId, !!force || tabDirty.search);
    }

    function selectWordset(wordsetId, options) {
        const settings = $.extend({ forceLoad: false }, options || {});
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        hideIpaKeyboard();
        currentWordsetId = safeWordsetId;
        currentSearchPage = 1;
        currentSearchPayload = null;
        if ($wordset.val() !== String(safeWordsetId)) {
            $wordset.val(String(safeWordsetId));
        }
        rememberWordset(safeWordsetId);
        markTabsDirty(['map', 'symbols', 'search', 'orthography']);
        loadActiveTab(settings.forceLoad);
    }

    function loadLetterMap(wordsetId, shouldLoad) {
        if (!shouldLoad) {
            return;
        }

        setStatus(t('loading', 'Loading transcription data...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_letter_map',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            renderLetterMap(response.data || {});
            tabDirty.map = false;
            setStatus('');
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function loadSymbols(wordsetId, shouldLoad) {
        if (!shouldLoad) {
            return;
        }

        setStatus(t('loading', 'Loading transcription data...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_symbols',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            renderSymbols(response.data || {});
            tabDirty.symbols = false;
            setStatus('');
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function getSearchState() {
        return {
            query: ($searchQuery.val() || '').toString(),
            scope: ($searchScope.val() || 'both').toString(),
            issuesOnly: !!$searchIssuesOnly.prop('checked'),
            reviewOnly: !!$searchReviewOnly.prop('checked'),
            exactTranscription: !!$searchExactTranscription.prop('checked')
        };
    }

    function loadSearch(wordsetId, shouldLoad, options) {
        const settings = $.extend({ quietStatus: false, showLoading: null, successStatus: null, append: false }, options || {});
        if (!shouldLoad) {
            return;
        }

        hideIpaKeyboard();
        const searchState = getSearchState();
        const requestedPage = normalizeSearchPage(settings.page || currentSearchPage);
        currentSearchPage = requestedPage;
        if (settings.showLoading === null) {
            settings.showLoading = !settings.quietStatus && !settings.append;
        }
        if (settings.showLoading) {
            renderSearchLoading();
        } else if (settings.append) {
            setSearchLoadMoreState(true);
        }
        if (!settings.quietStatus) {
            setStatus(settings.append ? t('searchLoadingMore', 'Loading more...') : t('searchLoading', 'Searching recordings...'), false);
        }
        return $.post(ajaxUrl, {
            action: 'll_tools_search_ipa_keyboard_recordings',
            nonce: nonce,
            wordset_id: wordsetId,
            query: searchState.query,
            scope: searchState.scope,
            issues_only: searchState.issuesOnly ? 1 : 0,
            review_only: searchState.reviewOnly ? 1 : 0,
            exact_transcription: searchState.exactTranscription ? 1 : 0,
            search_page: requestedPage,
            per_page: searchPageSize
        }).done(function (response) {
            if (!response || response.success !== true) {
                if (settings.append) {
                    setSearchLoadMoreState(false);
                }
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            currentSearchPage = normalizeSearchPage(response.data && response.data.current_page ? response.data.current_page : requestedPage);
            if (settings.append) {
                appendSearch(response.data || {});
            } else {
                renderSearch(response.data || {});
            }
            tabDirty.search = false;
            if (typeof settings.successStatus === 'string' && settings.successStatus !== '') {
                setStatus(settings.successStatus, false);
            } else if (!settings.quietStatus) {
                setStatus('');
            }
        }).fail(function () {
            if (settings.showLoading) {
                $searchResults.empty();
                setSearchSummary('');
                currentSearchPayload = null;
            } else if (settings.append) {
                setSearchLoadMoreState(false);
            }
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function renderSearchLoading() {
        setSearchSummary('');
        $searchResults.empty().append(
            $('<div>', { class: 'll-ipa-search-loading', 'aria-live': 'polite' })
                .append($('<div>', { class: 'll-ipa-search-loading-bar', 'aria-hidden': 'true' }).append($('<span>')))
                .append($('<div>', { class: 'll-ipa-search-loading-title', text: t('searchLoading', 'Searching recordings...') }))
                .append($('<div>', { class: 'll-ipa-search-loading-hint', text: t('searchLoadingHint', 'This can take a moment for larger word sets.') }))
        );
    }

    function buildSearchSummaryText(totalMatches, pageStart, pageEnd, issuesOnly, reviewOnly) {
        if (totalMatches <= 0) {
            return '';
        }

        if (totalMatches > 1 && pageStart > 0 && pageEnd >= pageStart) {
            const rangeTemplate = issuesOnly && !reviewOnly
                ? t('searchFilteredSummaryRange', 'Showing %1$d-%2$d of %3$d flagged recordings')
                : (reviewOnly && !issuesOnly
                    ? t('searchReviewSummaryRange', 'Showing %1$d-%2$d of %3$d transcriptions needing review')
                    : t('searchSummaryRange', 'Showing %1$d-%2$d of %3$d results'));
            return formatText(rangeTemplate, [pageStart, pageEnd, totalMatches]);
        }

        return issuesOnly && !reviewOnly
            ? formatCount(totalMatches, 'searchFilteredSummary', 'searchFilteredSummaryPlural', 'Showing %1$d flagged recording', 'Showing %1$d flagged recordings')
            : (reviewOnly && !issuesOnly
                ? formatCount(totalMatches, 'searchReviewSummary', 'searchReviewSummaryPlural', 'Showing %1$d transcription needing review', 'Showing %1$d transcriptions needing review')
                : formatCount(totalMatches, 'searchSummary', 'searchSummaryPlural', '%1$d result', '%1$d results'));
    }

    function getSearchPaginationItems(currentPage, totalPages) {
        const pages = [1, totalPages, currentPage - 1, currentPage, currentPage + 1];

        if (currentPage <= 4) {
            pages.push(2, 3, 4);
        }
        if (currentPage >= (totalPages - 3)) {
            pages.push(totalPages - 1, totalPages - 2, totalPages - 3);
        }

        const numbers = pages
            .filter(function (page) {
                return page >= 1 && page <= totalPages;
            })
            .sort(function (left, right) {
                return left - right;
            })
            .filter(function (page, index, list) {
                return index === 0 || list[index - 1] !== page;
            });

        const items = [];
        numbers.forEach(function (page, index) {
            if (index > 0 && (page - numbers[index - 1]) > 1) {
                items.push('ellipsis');
            }
            items.push(page);
        });

        return items;
    }

    function buildSearchPagination(payload) {
        const totalPages = normalizeSearchPage(payload && payload.total_pages ? payload.total_pages : 1);
        const currentPage = normalizeSearchPage(payload && payload.current_page ? payload.current_page : currentSearchPage);
        if (totalPages <= 1) {
            return null;
        }

        const $nav = $('<nav>', {
            class: 'll-ipa-search-pagination',
            'aria-label': t('searchPaginationLabel', 'Search result pages')
        });
        $nav.append($('<div>', {
            class: 'll-ipa-search-pagination-status',
            text: formatText(t('searchPaginationPage', 'Page %1$d of %2$d'), [currentPage, totalPages])
        }));

        const $controls = $('<div>', { class: 'll-ipa-search-pagination-controls' });
        $controls.append($('<button>', {
            type: 'button',
            class: 'button ll-ipa-search-page-button ll-ipa-search-page-button--nav',
            text: t('searchPaginationPrevious', 'Previous'),
            disabled: currentPage <= 1,
            'data-page': currentPage > 1 ? String(currentPage - 1) : '',
            'aria-label': t('searchPaginationPrevious', 'Previous')
        }));

        getSearchPaginationItems(currentPage, totalPages).forEach(function (item) {
            if (item === 'ellipsis') {
                $controls.append($('<span>', {
                    class: 'll-ipa-search-page-gap',
                    text: '...'
                }));
                return;
            }

            if (item === currentPage) {
                $controls.append($('<span>', {
                    class: 'll-ipa-search-page-current',
                    text: String(item),
                    'aria-current': 'page',
                    'aria-label': formatText(t('searchPaginationCurrentPage', 'Current page %1$d'), [item])
                }));
                return;
            }

            $controls.append($('<button>', {
                type: 'button',
                class: 'button ll-ipa-search-page-button',
                text: String(item),
                'data-page': String(item),
                'aria-label': formatText(t('searchPaginationGoToPage', 'Go to page %1$d'), [item])
            }));
        });

        $controls.append($('<button>', {
            type: 'button',
            class: 'button ll-ipa-search-page-button ll-ipa-search-page-button--nav',
            text: t('searchPaginationNext', 'Next'),
            disabled: currentPage >= totalPages,
            'data-page': currentPage < totalPages ? String(currentPage + 1) : '',
            'aria-label': t('searchPaginationNext', 'Next')
        }));

        $nav.append($controls);
        return $nav;
    }

    function buildSearchLazyControl(payload, loading) {
        if (!payload || !payload.has_more) {
            return null;
        }

        const currentPage = normalizeSearchPage(payload.current_page || currentSearchPage);
        const $control = $('<div>', { class: 'll-ipa-search-lazy-control' });
        if (loading) {
            $control.addClass('is-loading');
        }
        $control.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-search-load-more',
            text: loading ? t('searchLoadingMore', 'Loading more...') : t('searchLoadMore', 'Load more'),
            disabled: !!loading,
            'data-page': String(currentPage + 1)
        }));

        return $control;
    }

    function replaceSearchLazyControl(payload, loading) {
        $searchResults.children('.ll-ipa-search-lazy-control').remove();
        const $control = buildSearchLazyControl(payload, loading);
        if ($control) {
            $searchResults.append($control);
        }
    }

    function setSearchLoadMoreState(loading) {
        if (!currentSearchPayload) {
            return;
        }

        replaceSearchLazyControl(currentSearchPayload, !!loading);
    }

    function getSymbolSummaryText(recordingCount, occurrenceCount) {
        const countLabel = formatCount(
            recordingCount,
            'recordingCountSingular',
            'recordingCountPlural',
            '%1$d recording',
            '%1$d recordings'
        );
        const totalLabel = occurrenceCount
            ? (' - ' + formatCount(
                occurrenceCount,
                'occurrenceCountSingular',
                'occurrenceCountPlural',
                '%1$d occurrence',
                '%1$d occurrences'
            ))
            : '';
        return countLabel + totalLabel;
    }

    function setSymbolSummaryCounts($details, recordingCount, occurrenceCount) {
        const safeRecordingCount = Math.max(0, parseInt(recordingCount, 10) || 0);
        const safeOccurrenceCount = Math.max(0, parseInt(occurrenceCount, 10) || 0);
        let $count = $details.children('summary').find('.ll-ipa-symbol-count').first();

        if (!$count.length) {
            $count = $('<span>', { class: 'll-ipa-symbol-count' });
            $details.children('summary').append($count);
        }

        $details.attr('data-recording-count', safeRecordingCount);
        $details.attr('data-occurrence-count', safeOccurrenceCount);
        $count.text(getSymbolSummaryText(safeRecordingCount, safeOccurrenceCount));
    }

    function buildRecordingRow(rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const wordText = rec && rec.word_text ? rec.word_text : '';
        const wordTranslation = rec && rec.word_translation ? rec.word_translation : '';
        const recordingText = rec && rec.recording_text ? rec.recording_text : '';
        const recordingTranslation = rec && rec.recording_translation ? rec.recording_translation : '';
        const recordingIpa = rec && rec.recording_ipa ? rec.recording_ipa : '';
        const editLink = rec && rec.word_edit_link ? rec.word_edit_link : '';

        const $wordCell = $('<td>');
        if (editLink) {
            $wordCell.append(
                $('<a>', { href: editLink, text: wordText || t('untitled', '(Untitled)'), target: '_blank' })
            );
        } else {
            $wordCell.text(wordText || t('untitled', '(Untitled)'));
        }
        if (wordTranslation) {
            $wordCell.append(
                $('<span>', { class: 'll-ipa-translation', text: ' (' + wordTranslation + ')' })
            );
        }

        const $textCell = $('<td>');
        if (recordingText) {
            $textCell.text(recordingText);
            if (recordingTranslation) {
                $textCell.append(
                    $('<span>', { class: 'll-ipa-translation', text: ' (' + recordingTranslation + ')' })
                );
            }
        } else {
            $textCell.text('-');
        }

        const $ipaInput = $('<input>', {
            type: 'text',
            class: 'll-ipa-input',
            value: recordingIpa,
            disabled: !currentCanEdit
        });

        const $saveBtn = $('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-save',
            text: t('save', 'Save'),
            'data-recording-id': recordingId,
            disabled: !currentCanEdit
        });

        return $('<tr>', { 'data-recording-id': recordingId })
            .append($wordCell)
            .append($('<td>').append(createAudioButton(rec)))
            .append($textCell)
            .append($('<td>').append($ipaInput))
            .append($('<td>').append($saveBtn));
    }

    function buildRecordingsTable() {
        const transcription = getTranscription();
        const $table = $('<table>', { class: 'widefat striped ll-ipa-recordings' });
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('wordColumnLabel', 'Word') }))
                .append($('<th>', { text: t('recordingColumnLabel', 'Recording') }))
                .append($('<th>', { text: t('textColumnLabel', 'Text') }))
                .append($('<th>', { text: transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: '' }))
        );

        return $table.append($thead, $('<tbody>'));
    }

    function createSymbolDetails(symbol) {
        const $details = $('<details>', {
            class: 'll-ipa-symbol',
            'data-symbol': symbol,
            'data-recording-count': 0,
            'data-occurrence-count': 0
        });
        const $summary = $('<summary>');
        $summary.append($('<span>', { class: 'll-ipa-symbol-text', text: symbol }));
        $details.append($summary, $('<div>', { class: 'll-ipa-symbol-body' }));
        setSymbolSummaryCounts($details, 0, 0);
        return $details;
    }

    function buildSymbolsTableBody($details) {
        const $body = $details.children('.ll-ipa-symbol-body').first();
        let $table = $body.children('.ll-ipa-recordings').first();

        $body.children('.ll-ipa-empty').remove();
        if (!$table.length) {
            $table = buildRecordingsTable();
            $body.append($table);
        }

        return $table.children('tbody').first();
    }

    function findSymbolDetails(symbol) {
        return $symbols.children('.ll-ipa-symbol').filter(function () {
            return ($(this).attr('data-symbol') || '') === symbol;
        }).first();
    }

    function ensureSymbolEmptyState($details) {
        const $body = $details.children('.ll-ipa-symbol-body').first();
        const hasRows = $body.find('.ll-ipa-recordings tbody tr').length > 0;

        $body.children('.ll-ipa-empty').remove();
        if (hasRows) {
            return;
        }

        $body.children('.ll-ipa-recordings').remove();
        $body.append($('<div>', {
            class: 'll-ipa-empty',
            text: t('noRecordings', 'No recordings use this character yet.')
        }));
    }

    function renderSymbols(payload) {
        const list = Array.isArray(payload.symbols) ? payload.symbols : [];
        $symbols.empty();
        $addInput.prop('disabled', !currentCanEdit);
        $addBtn.prop('disabled', !currentCanEdit);

        if (!list.length) {
            $symbols.append(
                $('<div>', {
                    class: 'll-ipa-empty',
                    text: getTranscription().special_chars_empty || t('empty', 'No special characters found for this word set.')
                })
            );
            return;
        }

        list.forEach(function (entry) {
            const symbol = entry && entry.symbol ? entry.symbol : '';
            const count = entry && typeof entry.count === 'number' ? entry.count : 0;
            const recordings = entry && Array.isArray(entry.recordings) ? entry.recordings : [];
            const recordingCount = entry && typeof entry.recording_count === 'number'
                ? entry.recording_count
                : recordings.length;

            if (!symbol) {
                return;
            }

            const $details = createSymbolDetails(symbol);
            setSymbolSummaryCounts($details, recordingCount, count);

            if (recordings.length) {
                const $tbody = buildSymbolsTableBody($details);
                recordings.forEach(function (rec) {
                    $tbody.append(buildRecordingRow(rec));
                });
            } else {
                ensureSymbolEmptyState($details);
            }

            $symbols.append($details);
        });
    }

    function cancelScheduledLetterMapRefresh() {
        if (letterMapRefreshTimer) {
            window.clearTimeout(letterMapRefreshTimer);
            letterMapRefreshTimer = null;
        }
        letterMapRefreshRequestId += 1;
    }

    function refreshLetterMap(wordsetId) {
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        const requestId = ++letterMapRefreshRequestId;
        if (!safeWordsetId) {
            return;
        }

        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_letter_map',
            nonce: nonce,
            wordset_id: safeWordsetId
        }).done(function (response) {
            if (requestId !== letterMapRefreshRequestId || safeWordsetId !== currentWordsetId) {
                return;
            }
            if (!response || response.success !== true) {
                return;
            }
            handleWordsetResponse(response);
            renderLetterMap(response.data || {});
            tabDirty.map = false;
        });
    }

    function scheduleLetterMapRefresh(wordsetId) {
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        if (!safeWordsetId) {
            return;
        }

        if (letterMapRefreshTimer) {
            window.clearTimeout(letterMapRefreshTimer);
        }

        letterMapRefreshTimer = window.setTimeout(function () {
            letterMapRefreshTimer = null;
            refreshLetterMap(safeWordsetId);
        }, 700);
    }

    function renderLetterMap(payload) {
        const list = Array.isArray(payload.letter_map) ? payload.letter_map : [];
        const transcription = getTranscription();
        $letterMap.empty();

        const $add = $('<div>', { class: 'll-ipa-map-add' });
        $add.append($('<div>', { class: 'll-ipa-map-add-title', text: t('mapAddLabel', 'Add manual mapping') }));
        const $addFields = $('<div>', { class: 'll-ipa-map-add-fields' });
        const $addLetter = $('<input>', {
            type: 'text',
            class: 'll-ipa-map-add-letter',
            placeholder: t('mapAddLettersPlaceholder', 'Letters (e.g. ll)'),
            'aria-label': t('mapAddLettersLabel', 'Letters'),
            disabled: !currentCanEdit
        });
        const $addSymbols = $('<input>', {
            type: 'text',
            class: 'll-ipa-map-add-symbols',
            placeholder: transcription.map_add_symbols_placeholder || 'IPA symbols (e.g. r)',
            'aria-label': transcription.map_add_symbols_label || t('pronunciationLabel', 'Pronunciation'),
            disabled: !currentCanEdit
        });
        const $addMappingBtn = $('<button>', {
            type: 'button',
            class: 'll-ipa-map-button ll-ipa-map-add-btn',
            text: t('mapAdd', 'Add mapping'),
            disabled: !currentCanEdit
        });
        $addFields.append($addLetter, $addSymbols, $addMappingBtn);
        $add.append($addFields);
        $add.append($('<div>', { class: 'll-ipa-map-add-hint', text: t('mapAddHint', 'Use multiple letters to map digraphs like ll.') }));
        $letterMap.append($add);

        if (!list.length) {
            $letterMap.append(
                $('<div>', { class: 'll-ipa-empty', text: t('mapEmpty', 'No letter mappings found for this word set.') })
            );
            return;
        }

        const $table = $('<table>', { class: 'widefat striped ll-ipa-letter-table' });
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('mapLetterLabel', 'Letter(s)') }))
                .append($('<th>', { text: t('mapAutoLabel', 'Auto map') }))
                .append($('<th>', { text: t('mapManualLabel', 'Manual override') }))
        );
        $table.append($thead);

        const $tbody = $('<tbody>');
        list.forEach(function (entry) {
            const letter = entry && entry.letter ? entry.letter : '';
            const autoList = entry && Array.isArray(entry.auto) ? entry.auto : [];
            const manualList = entry && Array.isArray(entry.manual) ? entry.manual : [];

            const $autoCell = $('<td>');
            if (autoList.length) {
                const $autoWrap = $('<div>', { class: 'll-ipa-map-auto' });
                autoList.forEach(function (autoEntry) {
                    const symbol = autoEntry && autoEntry.symbol ? autoEntry.symbol : '';
                    const count = autoEntry && typeof autoEntry.count === 'number' ? autoEntry.count : 0;
                    const samples = autoEntry && Array.isArray(autoEntry.samples) ? autoEntry.samples : [];
                    if (!symbol) {
                        return;
                    }
                    const $token = $('<span>', { class: 'll-ipa-map-token' });
                    $token.append($('<span>', { class: 'll-ipa-map-token-symbol', text: symbol }));
                    if (count) {
                        $token.append($('<span>', { class: 'll-ipa-map-token-count', text: '(' + count + ')' }));
                    }
                    const $tokenWrap = $('<div>', { class: 'll-ipa-map-token-wrap' });
                    const $tokenRow = $('<div>', { class: 'll-ipa-map-token-row' });
                    const $blockBtn = $('<button>', {
                        type: 'button',
                        class: 'll-ipa-map-block',
                        text: 'x',
                        'data-letter': letter,
                        'data-symbol': symbol,
                        'aria-label': t('mapBlockLabel', 'Block mapping'),
                        title: t('mapBlockLabel', 'Block mapping'),
                        disabled: !currentCanEdit
                    });
                    $tokenRow.append($token, $blockBtn);
                    $tokenWrap.append($tokenRow);

                    if (samples.length) {
                        const $details = $('<details>', { class: 'll-ipa-map-samples' });
                        $details.append($('<summary>', { text: t('mapSamplesLabel', 'Examples') + ' (' + samples.length + ')' }));
                        const $list = $('<div>', { class: 'll-ipa-map-samples-list' });
                        samples.forEach(function (sample) {
                            const $item = $('<div>', { class: 'll-ipa-map-sample' });
                            const $title = $('<div>', { class: 'll-ipa-map-sample-title' });
                            if (sample.word_edit_link) {
                                $title.append(
                                    $('<a>', {
                                        href: sample.word_edit_link,
                                        text: sample.word_text || t('untitled', '(Untitled)'),
                                        target: '_blank',
                                        class: 'll-ipa-map-sample-link'
                                    })
                                );
                            } else {
                                $title.text(sample.word_text || t('untitled', '(Untitled)'));
                            }
                            if (sample.word_translation) {
                                $title.append($('<span>', { class: 'll-ipa-translation', text: ' (' + sample.word_translation + ')' }));
                            }
                            $item.append($title);

                            if (sample.recording_text || sample.recording_translation) {
                                const $textRow = $('<div>', { class: 'll-ipa-map-sample-row' });
                                $textRow.append($('<span>', { class: 'll-ipa-map-sample-label', text: t('mapSampleTextLabel', 'Text:') }));
                                $textRow.append($('<span>', { class: 'll-ipa-map-sample-value', text: sample.recording_text || '-' }));
                                if (sample.recording_translation) {
                                    $textRow.append($('<span>', { class: 'll-ipa-translation', text: ' (' + sample.recording_translation + ')' }));
                                }
                                $item.append($textRow);
                            }
                            if (sample.recording_ipa) {
                                const $ipaRow = $('<div>', { class: 'll-ipa-map-sample-row' });
                                $ipaRow.append($('<span>', { class: 'll-ipa-map-sample-label', text: transcription.map_sample_value_label || 'IPA:' }));
                                $ipaRow.append($('<span>', { class: 'll-ipa-map-sample-value ll-ipa-map-sample-ipa', text: sample.recording_ipa }));
                                $item.append($ipaRow);
                            }

                            $list.append($item);
                        });
                        $details.append($list);
                        $tokenWrap.append($details);
                    }

                    $autoWrap.append($tokenWrap);
                });

                if ($autoWrap.children().length) {
                    $autoCell.append($autoWrap);
                } else {
                    $autoCell.append($('<span>', { class: 'll-ipa-map-empty', text: t('mapAutoEmpty', 'No mappings yet.') }));
                }
            } else {
                $autoCell.append($('<span>', { class: 'll-ipa-map-empty', text: t('mapAutoEmpty', 'No mappings yet.') }));
            }

            const manualValue = manualList.filter(Boolean).join(' ');
            const $input = $('<input>', {
                type: 'text',
                class: 'll-ipa-map-input',
                value: manualValue,
                placeholder: transcription.map_add_symbols_placeholder || t('mapPlaceholder', 'e.g. r'),
                disabled: !currentCanEdit
            });
            const $saveBtn = $('<button>', {
                type: 'button',
                class: 'll-ipa-map-button ll-ipa-map-save',
                text: t('save', 'Save'),
                'data-letter': letter,
                disabled: !currentCanEdit
            });
            const $clearBtn = $('<button>', {
                type: 'button',
                class: 'll-ipa-map-button ll-ipa-map-clear',
                text: t('mapClear', 'Clear'),
                'data-letter': letter,
                disabled: !currentCanEdit
            });

            const $manualCell = $('<td>');
            const $controls = $('<div>', { class: 'll-ipa-map-controls' });
            $controls.append($input, $saveBtn, $clearBtn);
            $manualCell.append($controls);

            const $row = $('<tr>', { 'data-letter': letter });
            $row.append($('<td>', { class: 'll-ipa-map-letter', text: letter }));
            $row.append($autoCell);
            $row.append($manualCell);
            $tbody.append($row);
        });

        $table.append($tbody);
        $letterMap.append($table);

        const blockedItems = [];
        list.forEach(function (entry) {
            const letter = entry && entry.letter ? entry.letter : '';
            const blockedList = entry && Array.isArray(entry.blocked) ? entry.blocked : [];
            if (!letter || !blockedList.length) {
                return;
            }
            blockedList.forEach(function (blockedEntry) {
                const symbol = blockedEntry && blockedEntry.symbol ? blockedEntry.symbol : '';
                if (!symbol) {
                    return;
                }
                blockedItems.push({
                    letter: letter,
                    symbol: symbol,
                    count: blockedEntry && typeof blockedEntry.count === 'number' ? blockedEntry.count : 0
                });
            });
        });

        if (blockedItems.length) {
            const $blockedWrap = $('<div>', { class: 'll-ipa-map-blocked' });
            $blockedWrap.append($('<div>', { class: 'll-ipa-map-blocked-title', text: t('mapBlockedTitle', 'Blocked mappings') }));
            const $blockedList = $('<div>', { class: 'll-ipa-map-blocked-list' });
            blockedItems.forEach(function (item) {
                const $row = $('<div>', { class: 'll-ipa-map-blocked-item' });
                const $label = $('<div>', { class: 'll-ipa-map-blocked-label' });
                $label.append($('<span>', { class: 'll-ipa-map-blocked-letter', text: item.letter }));
                $label.append($('<span>', { class: 'll-ipa-map-blocked-arrow', text: '->' }));
                $label.append($('<span>', { class: 'll-ipa-map-blocked-symbol', text: item.symbol }));
                if (item.count) {
                    $label.append($('<span>', { class: 'll-ipa-map-blocked-count', text: '(' + item.count + ')' }));
                }
                const $undo = $('<button>', {
                    type: 'button',
                    class: 'll-ipa-map-unblock',
                    text: t('mapUnblockLabel', 'Undo'),
                    'data-letter': item.letter,
                    'data-symbol': item.symbol,
                    disabled: !currentCanEdit
                });
                $row.append($label, $undo);
                $blockedList.append($row);
            });
            $blockedWrap.append($blockedList);
            $letterMap.append($blockedWrap);
        }
    }

    function normalizeHighlightSpans(spans, text) {
        const chars = Array.from(text || '');
        return (Array.isArray(spans) ? spans : []).map(function (span) {
            const start = Math.max(0, parseInt(span && span.start, 10) || 0);
            const length = Math.max(0, parseInt(span && span.length, 10) || 0);
            return {
                start: Math.min(start, chars.length),
                end: Math.min(start + length, chars.length)
            };
        }).filter(function (span) {
            return span.end > span.start;
        }).sort(function (left, right) {
            return left.start - right.start || right.end - left.end;
        });
    }

    function shouldRenderInlineMismatchHighlight(spans, text) {
        const value = (text || '').toString();
        const chars = Array.from(value);
        const normalized = normalizeHighlightSpans(spans, value);
        if (!normalized.length) {
            return false;
        }
        if (normalized.length > maxInlineMismatchMarks) {
            return false;
        }

        let covered = 0;
        let offset = 0;
        normalized.forEach(function (span) {
            const start = Math.max(offset, span.start);
            if (span.end > start) {
                covered += span.end - start;
                offset = span.end;
            }
        });

        return !chars.length || (covered / chars.length) <= maxInlineMismatchCoverage;
    }

    function buildHighlightedText(text, spans, className) {
        const value = (text || '').toString();
        const chars = Array.from(value);
        const $wrap = $('<span>', { class: className || 'll-ipa-highlighted-text' });
        let offset = 0;
        normalizeHighlightSpans(spans, value).forEach(function (span) {
            if (span.start > offset) {
                $wrap.append(document.createTextNode(chars.slice(offset, span.start).join('')));
            }
            $wrap.append($('<mark>', {
                class: 'll-ipa-mismatch-mark',
                text: chars.slice(span.start, span.end).join('')
            }));
            offset = Math.max(offset, span.end);
        });
        if (offset < chars.length) {
            $wrap.append(document.createTextNode(chars.slice(offset).join('')));
        }
        if (!chars.length) {
            $wrap.text('');
        }
        return $wrap;
    }

    function buildSingleDiffSpan(before, after) {
        const beforeChars = Array.from((before || '').toString());
        const afterChars = Array.from((after || '').toString());
        let prefix = 0;
        const maxPrefix = Math.min(beforeChars.length, afterChars.length);
        while (prefix < maxPrefix && beforeChars[prefix] === afterChars[prefix]) {
            prefix++;
        }

        let suffix = 0;
        while (
            suffix < beforeChars.length - prefix
            && suffix < afterChars.length - prefix
            && beforeChars[beforeChars.length - 1 - suffix] === afterChars[afterChars.length - 1 - suffix]
        ) {
            suffix++;
        }

        const length = Math.max(0, afterChars.length - prefix - suffix);
        return length > 0 ? [{ start: prefix, length: length }] : [];
    }

    function buildSuggestionChipLabel(labelTemplate, value, spans) {
        const template = (labelTemplate || 'Change to: %s').toString();
        const token = '%s';
        const tokenIndex = template.indexOf(token);
        const before = tokenIndex >= 0 ? template.slice(0, tokenIndex) : '';
        const after = tokenIndex >= 0 ? template.slice(tokenIndex + token.length) : '';
        const $label = $('<span>', { class: 'll-ipa-search-suggestion-chip-label' });
        if (before) {
            $label.append(document.createTextNode(before));
        }
        $label.append(buildHighlightedText(value, spans || [], 'll-ipa-search-suggestion-preview'));
        if (after) {
            $label.append(document.createTextNode(after));
        }
        if (tokenIndex < 0) {
            $label.prepend(document.createTextNode(template + ' '));
        }
        return $label;
    }

    function getOrthographyMismatchDetail(issue) {
        const detail = issue && issue.orthography_mismatch ? issue.orthography_mismatch : null;
        return detail && typeof detail === 'object' ? detail : null;
    }

    function findOrthographyMismatchIssue(issues) {
        const list = Array.isArray(issues) ? issues : [];
        for (let index = 0; index < list.length; index++) {
            const issue = list[index] || {};
            const code = (issue.code || '').toString();
            const ruleKey = (issue.rule_key || '').toString();
            if ((code === 'orthography_mismatch' || ruleKey === 'builtin:orthography_mismatch') && getOrthographyMismatchDetail(issue)) {
                return issue;
            }
        }
        return null;
    }

    function getOrthographyMismatchDetailFromIssues(issues) {
        return getOrthographyMismatchDetail(findOrthographyMismatchIssue(issues));
    }

    function buildOrthographyMismatchPreview(issue) {
        const detail = getOrthographyMismatchDetail(issue);
        if (!detail) {
            return null;
        }

        const $preview = $('<div>', { class: 'll-ipa-search-orthography-mismatch' });
        const rows = [
            {
                label: t('orthographyIssueActual', 'Saved text'),
                text: detail.actual_text || '',
                spans: detail.actual_spans || []
            },
            {
                label: t('orthographyIssuePredicted', 'Suggested text'),
                text: detail.suggested_text || '',
                spans: detail.suggested_spans || []
            },
            {
                label: t('searchReviewIpaLabel', 'Pronunciation'),
                text: detail.ipa_text || '',
                spans: detail.ipa_spans || []
            }
        ];

        rows.forEach(function (row) {
            if (!row.text) {
                return;
            }
            const $row = $('<div>', { class: 'll-ipa-search-mismatch-row' });
            $row.append($('<span>', { class: 'll-ipa-search-mismatch-label', text: row.label }));
            $row.append(buildHighlightedText(row.text, row.spans, 'll-ipa-search-mismatch-text'));
            $preview.append($row);
        });

        return $preview.children().length ? $preview : null;
    }

    function buildSearchIssueItem(issue, ignored) {
        const $item = $('<div>', {
            class: 'll-ipa-search-issue' + (ignored ? ' is-ignored' : ''),
            'data-rule-key': issue.rule_key || ''
        });
        const mismatchDetail = getOrthographyMismatchDetail(issue);
        const $header = $('<div>', { class: 'll-ipa-search-issue-header' });
        $header.append($('<span>', { class: 'll-ipa-search-issue-title', text: issue.label || issue.message || t('searchReviewIssues', 'Review warnings') }));
        if (issue.count && parseInt(issue.count, 10) > 1) {
            $header.append($('<span>', { class: 'll-ipa-search-issue-count', text: 'x' + String(issue.count) }));
        }
        $item.append($header);
        if (issue.message) {
            $item.append($('<div>', { class: 'll-ipa-search-issue-message', text: issue.message }));
        }
        if (!mismatchDetail && Array.isArray(issue.samples) && issue.samples.length) {
            $item.append($('<div>', {
                class: 'll-ipa-search-issue-samples',
                html: issue.samples.map(function (sample) {
                    return '<code>' + escapeHtml(sample) + '</code>';
                }).join(' ')
            }));
        }
        if (currentCanEdit) {
            const $actions = $('<div>', { class: 'll-ipa-search-issue-actions' });
            const approvals = Array.isArray(issue.approval_options) ? issue.approval_options : [];
            approvals.forEach(function (option) {
                const symbol = option && option.symbol ? String(option.symbol) : '';
                const output = option && option.output ? String(option.output) : '';
                if (!symbol || !output) {
                    return;
                }
                $actions.append($('<button>', {
                    type: 'button',
                    class: 'button-link ll-ipa-symbol-approval',
                    text: formatText(t('searchApproveSymbolMapping', 'Approve %1$s symbol and map it to %2$s in orthography'), [symbol, output]),
                    'data-symbol': symbol,
                    'data-output': output
                }));
            });
            if (!mismatchDetail && issue.rule_key) {
                $actions.append($('<button>', {
                    type: 'button',
                    class: 'button-link ll-ipa-issue-toggle',
                    text: ignored ? t('searchExceptionRestore', 'Undo exception') : t('searchExceptionIgnore', 'Ignore for this transcription'),
                    'data-rule-key': issue.rule_key,
                    'data-enabled': ignored ? '0' : '1'
                }));
            }
            if ($actions.children().length) {
                $item.append($actions);
            }
        }
        return $item;
    }

    function normalizeReviewFields(value) {
        const fields = {
            recording_text: false,
            recording_ipa: false
        };
        if (!value) { return fields; }
        if (Array.isArray(value)) {
            value.forEach(function (field) {
                if (field === 'recording_text' || field === 'text' || field === 'orthography') {
                    fields.recording_text = true;
                } else if (field === 'recording_ipa' || field === 'ipa' || field === 'transcription') {
                    fields.recording_ipa = true;
                }
            });
            return fields;
        }
        if (typeof value === 'object') {
            fields.recording_text = !!(value.recording_text || value.text || value.orthography);
            fields.recording_ipa = !!(value.recording_ipa || value.ipa || value.transcription);
        }
        return fields;
    }

    function getReviewFieldLabel(field) {
        return field === 'recording_text'
            ? t('searchReviewTextLabel', 'Text')
            : t('searchReviewIpaLabel', 'Pronunciation');
    }

    function buildSearchFieldReviewNote(reviewNote) {
        if (!reviewNote) {
            return null;
        }

        return $('<div>', {
            class: 'll-ipa-search-field-review-note',
            text: reviewNote
        });
    }

    function searchReviewNoteAppliesToField(reviewFields, field) {
        const fields = normalizeReviewFields(reviewFields);
        return field === 'recording_text'
            ? !!fields.recording_text
            : !!fields.recording_ipa;
    }

    function buildSearchReviewStatus(needsReview) {
        const statusLabel = needsReview
            ? t('searchReviewNeedsReviewTag', 'Needs review')
            : t('searchReviewReviewedTag', 'Reviewed');
        const statusClass = needsReview ? 'is-needs-review' : 'is-reviewed';
        const icon = needsReview ? '\u00d7' : '\u2713';

        return $('<span>', {
            class: 'll-ipa-search-review-status ' + statusClass,
            'aria-label': statusLabel
        })
            .append($('<span>', {
                class: 'll-ipa-search-review-status-icon',
                'aria-hidden': 'true',
                text: icon
            }))
            .append($('<span>', {
                class: 'll-ipa-search-review-status-label',
                text: statusLabel
            }));
    }

    function buildSearchReviewSavingStatus() {
        const savingLabel = t('saving', 'Saving...');

        return $('<span>', {
            class: 'll-ipa-search-review-status is-saving',
            'aria-label': savingLabel,
            'aria-busy': 'true'
        })
            .append($('<span>', {
                class: 'll-ipa-search-review-status-icon',
                'aria-hidden': 'true'
            }))
            .append($('<span>', {
                class: 'll-ipa-search-review-status-label',
                text: savingLabel
            }));
    }

    function getSearchReviewFieldSelector(field) {
        return field === 'recording_text'
            ? '.ll-ipa-search-text-cell'
            : '.ll-ipa-search-ipa-cell';
    }

    function getSearchReviewAction($row, field) {
        if (!$row || !$row.length) {
            return $();
        }

        return $row.find(getSearchReviewFieldSelector(field) + ' .ll-ipa-search-field-review-action').first();
    }

    function normalizeSearchReviewField(field) {
        return field === 'recording_text' ? 'recording_text' : 'recording_ipa';
    }

    function getSearchReviewSavingFields($row) {
        const raw = $row && $row.length ? $row.data('llSearchReviewSavingFields') : null;
        const fields = {};
        if (raw && typeof raw === 'object') {
            searchReviewFields.forEach(function (field) {
                if (raw[field]) {
                    fields[field] = true;
                }
            });
        }
        return fields;
    }

    function setSearchReviewSavingFields($row, fields) {
        if (!$row || !$row.length) {
            return;
        }

        const activeFields = searchReviewFields.filter(function (field) {
            return fields && fields[field];
        });
        if (activeFields.length) {
            const nextFields = {};
            activeFields.forEach(function (field) {
                nextFields[field] = true;
            });
            $row
                .data('llSearchReviewSavingFields', nextFields)
                .addClass('is-review-saving')
                .attr('data-review-saving-fields', activeFields.join(' '));
            return;
        }

        $row
            .removeData('llSearchReviewSavingFields')
            .removeClass('is-review-saving')
            .removeAttr('data-review-saving-fields');
    }

    function searchReviewFieldIsSaving($row, field) {
        const savingFields = getSearchReviewSavingFields($row);
        return !!savingFields[normalizeSearchReviewField(field)];
    }

    function searchReviewStateIsSaving($row) {
        return searchReviewFields.some(function (field) {
            return searchReviewFieldIsSaving($row, field);
        });
    }

    function getReviewToggleButtonText(nextNeedsReview) {
        return nextNeedsReview
            ? t('searchReviewMarkAsNeedsReview', 'Mark as needing review')
            : t('searchReviewMarkAsReviewed', 'Mark as reviewed');
    }

    function getReviewToggleButtonAriaLabel(field, nextNeedsReview) {
        const fieldLabel = getReviewFieldLabel(field);
        return nextNeedsReview
            ? formatText(t('searchReviewMarkFieldAsNeedsReview', '%s: mark as needing review'), [fieldLabel])
            : formatText(t('searchReviewMarkFieldAsReviewed', '%s: mark as reviewed'), [fieldLabel]);
    }

    function restoreReviewToggleButton($toggle) {
        if (!$toggle || !$toggle.length) {
            return;
        }

        const field = ($toggle.attr('data-review-field') || 'recording_ipa').toString() === 'recording_text'
            ? 'recording_text'
            : 'recording_ipa';
        const nextNeedsReview = ($toggle.attr('data-next-review-state') || '0') === '1';
        const currentNeedsReview = ($toggle.attr('data-current-review-state') || '0') === '1';

        $toggle
            .removeClass('is-saving')
            .prop('disabled', false)
            .removeAttr('aria-busy aria-disabled')
            .attr({
                'aria-label': getReviewToggleButtonAriaLabel(field, nextNeedsReview),
                'aria-pressed': currentNeedsReview ? 'true' : 'false'
            })
            .text(getReviewToggleButtonText(nextNeedsReview));
    }

    function setSearchReviewSavingState($row, reviewField, saving) {
        if (!$row || !$row.length) {
            return;
        }

        const field = normalizeSearchReviewField(reviewField);
        const savingFields = getSearchReviewSavingFields($row);
        if (saving) {
            const $action = getSearchReviewAction($row, field);
            savingFields[field] = true;
            setSearchReviewSavingFields($row, savingFields);

            if ($action.length) {
                const $status = $action.find('.ll-ipa-search-review-status').first();
                const $toggle = $action.find('.ll-ipa-review-toggle').first();
                $action.addClass('is-saving');
                if ($status.length) {
                    $status.replaceWith(buildSearchReviewSavingStatus());
                } else {
                    $action.prepend(buildSearchReviewSavingStatus());
                }
                if ($toggle.length) {
                    $toggle
                        .addClass('is-saving')
                        .prop('disabled', true)
                        .attr('aria-disabled', 'true')
                        .attr('aria-busy', 'true');
                }
            }
            return;
        }

        delete savingFields[field];
        const currentNeedsReview = field === 'recording_text'
            ? ($row.attr('data-review-text') || '0') === '1'
            : ($row.attr('data-review-ipa') || '0') === '1';
        const $restoreAction = getSearchReviewAction($row, field);

        setSearchReviewSavingFields($row, savingFields);

        if ($restoreAction.length) {
            const $status = $restoreAction.find('.ll-ipa-search-review-status').first();
            const $toggle = $restoreAction.find('.ll-ipa-review-toggle').first();
            $restoreAction.removeClass('is-saving');
            if ($status.length) {
                $status.replaceWith(buildSearchReviewStatus(currentNeedsReview));
            } else {
                $restoreAction.prepend(buildSearchReviewStatus(currentNeedsReview));
            }
            restoreReviewToggleButton($toggle);
        }
    }

    function buildSearchFieldReviewAction(field, reviewFields) {
        const fields = normalizeReviewFields(reviewFields);
        const needsReview = !!fields[field];
        const $action = $('<div>', { class: 'll-ipa-search-field-review-action' });
        const $toggle = buildReviewToggleButton(needsReview, 'll-ipa-search-field-review-toggle', field);
        $action.append(buildSearchReviewStatus(needsReview));
        if (!$toggle) {
            return $action;
        }

        return $action.append(
            $('<div>', { class: 'll-ipa-search-field-review-link' }).append($toggle)
        );
    }

    function buildEditIconSvg() {
        return '<span class="ll-word-edit-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
    }

    function buildSearchWordEditControl(rec) {
        const wordId = parseInt(rec && rec.word_id, 10) || 0;
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        if (!wordId) {
            return null;
        }

        const editLabel = t('searchEditWord', 'Edit word');
        const $button = $('<button>', {
            type: 'button',
            class: 'll-ipa-search-word-edit-toggle',
            'aria-label': editLabel,
            title: editLabel,
            'data-word-id': wordId,
            'data-recording-id': recordingId,
            html: buildEditIconSvg()
        });
        if (rec && rec.word_edit_link) {
            $button.attr('data-word-edit-link', rec.word_edit_link);
        }

        return $button;
    }

    function getInternalNoteMessage(key, fallback) {
        const value = internalNotesI18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function readInternalNoteResponseMessage(response, fallback) {
        if (!response || typeof response !== 'object') {
            return fallback;
        }
        if (typeof response.data === 'string' && response.data) {
            return response.data;
        }
        if (response.data && typeof response.data.message === 'string' && response.data.message) {
            return response.data.message;
        }
        if (typeof response.message === 'string' && response.message) {
            return response.message;
        }
        return fallback;
    }

    function readInternalNoteAjaxMessage(jqXHR, fallback) {
        const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
        return readInternalNoteResponseMessage(response, fallback);
    }

    function getRecordInternalReviewNote(rec) {
        if (!rec || typeof rec !== 'object' || !Object.prototype.hasOwnProperty.call(rec, 'internal_review_note')) {
            return null;
        }
        return (rec.internal_review_note == null ? '' : String(rec.internal_review_note));
    }

    function updateSearchWordReviewNoteSummary($wrap, note) {
        const hasNote = (note || '').toString() !== '';
        const label = hasNote
            ? getInternalNoteMessage('label', 'Internal review note')
            : getInternalNoteMessage('emptyLabel', 'Add internal review note');
        $wrap
            .toggleClass('has-internal-review-note', hasNote)
            .find('.ll-internal-review-note__summary-label')
            .first()
            .text(label);
    }

    function buildSearchWordReviewNote(rec) {
        if (!internalNotesEnabled || !(rec && rec.can_manage_internal_review_note === true)) {
            return null;
        }

        const wordId = parseInt(rec && rec.word_id, 10) || 0;
        if (!wordId) {
            return null;
        }

        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const note = getRecordInternalReviewNote(rec) || '';
        const hasNote = note !== '';
        const fieldId = 'll-ipa-word-review-note-' + wordId + '-' + (recordingId || 'row');
        const statusId = fieldId + '-status';
        const descriptionId = fieldId + '-description';

        const $details = $('<details>', {
            class: 'll-ipa-search-word-review-note ll-internal-review-note' + (hasNote ? ' has-internal-review-note' : ''),
            'data-ll-search-word-review-note': '1',
            'data-ll-internal-review-note': '1',
            'data-object-type': 'word',
            'data-object-id': wordId,
            'data-wordset-id': currentWordsetId
        });
        if (hasNote) {
            $details.attr('open', 'open');
        }

        const $summary = $('<summary>', {
            class: 'll-internal-review-note__summary',
            'data-ll-internal-review-note-summary': '1'
        }).append($('<span>', {
            class: 'll-internal-review-note__summary-label',
            text: hasNote
                ? getInternalNoteMessage('label', 'Internal review note')
                : getInternalNoteMessage('emptyLabel', 'Add internal review note')
        }));

        const $input = $('<textarea>', {
            class: 'll-internal-review-note__input',
            id: fieldId,
            rows: 3,
            'data-ll-internal-review-note-input': '1',
            'aria-describedby': descriptionId + ' ' + statusId
        }).val(note);
        setSearchWordReviewNoteOriginalValue($input, note, false);

        $details
            .append($summary)
            .append($('<label>', {
                class: 'll-internal-review-note__label',
                for: fieldId,
                text: getInternalNoteMessage('label', 'Internal review note')
            }))
            .append($('<p>', {
                class: 'll-internal-review-note__description',
                id: descriptionId,
                text: getInternalNoteMessage('description', 'For staff-only review instructions, such as image fixes, split requests, or cleanup notes. This is not shown to learners.')
            }))
            .append($input)
            .append($('<div>', {
                class: 'll-internal-review-note__status',
                id: statusId,
                'data-ll-internal-review-note-status': '1',
                'aria-live': 'polite'
            }));

        return $details;
    }

    function setSearchWordReviewNoteStatus($wrap, message, state) {
        const $status = $wrap.find('[data-ll-internal-review-note-status]').first();
        if (!$status.length) {
            return;
        }

        const normalizedState = (state || '').toString();
        $status
            .text(message || '')
            .removeClass('is-saving is-success is-error')
            .toggleClass('is-saving', normalizedState === 'saving')
            .toggleClass('is-success', normalizedState === 'success')
            .toggleClass('is-error', normalizedState === 'error');
    }

    function getSearchWordReviewNoteOriginalValue($input) {
        if (!$input.length) {
            return '';
        }
        if (!$input.attr('data-ll-internal-review-note-original')) {
            const current = ($input.val() || '').toString();
            $input.attr('data-ll-internal-review-note-original', current);
            const inputEl = $input.get(0);
            if (inputEl) {
                inputEl.defaultValue = current;
            }
        }
        return ($input.attr('data-ll-internal-review-note-original') || '').toString();
    }

    function setSearchWordReviewNoteOriginalValue($input, value, updateValue) {
        const clean = (value || '').toString();
        if (updateValue !== false) {
            $input.val(clean);
        }
        $input.attr('data-ll-internal-review-note-original', clean);
        const inputEl = $input.get(0);
        if (inputEl) {
            inputEl.defaultValue = clean;
        }
    }

    function clearSearchWordReviewNoteTimer($wrap) {
        const timer = parseInt($wrap.data('llSearchWordReviewNoteTimer'), 10) || 0;
        if (timer > 0) {
            window.clearTimeout(timer);
        }
        $wrap.removeData('llSearchWordReviewNoteTimer');
    }

    function captureSearchWordReviewNoteStates($row) {
        const states = {};
        if (!internalNotesEnabled || !$row || !$row.length) {
            return states;
        }

        const activeElement = document.activeElement;
        $row.find('[data-ll-search-word-review-note]').each(function () {
            const $wrap = $(this);
            const objectId = parseInt($wrap.attr('data-object-id'), 10) || 0;
            const $input = $wrap.find('[data-ll-internal-review-note-input]').first();
            if (!objectId || !$input.length) {
                return;
            }

            const input = $input.get(0);
            const timer = parseInt($wrap.data('llSearchWordReviewNoteTimer'), 10) || 0;
            const current = ($input.val() || '').toString();
            const original = getSearchWordReviewNoteOriginalValue($input);
            const focused = !!(input && activeElement === input);
            const $status = $wrap.find('[data-ll-internal-review-note-status]').first();
            let statusState = '';
            if ($status.hasClass('is-saving')) {
                statusState = 'saving';
            } else if ($status.hasClass('is-success')) {
                statusState = 'success';
            } else if ($status.hasClass('is-error')) {
                statusState = 'error';
            }

            if (timer > 0) {
                window.clearTimeout(timer);
                $wrap.removeData('llSearchWordReviewNoteTimer');
            }

            states[objectId] = {
                open: $wrap.prop('open'),
                value: current,
                original: original,
                dirty: !!$wrap.data('llSearchWordReviewNoteDirty') || current !== original,
                hadTimer: timer > 0,
                saving: $wrap.hasClass('is-saving'),
                focused: focused,
                selectionStart: focused && input && typeof input.selectionStart === 'number' ? input.selectionStart : null,
                selectionEnd: focused && input && typeof input.selectionEnd === 'number' ? input.selectionEnd : null,
                statusMessage: ($status.text() || '').toString(),
                statusState: statusState
            };
        });

        return states;
    }

    function restoreSearchWordReviewNoteStates($row, states) {
        if (!internalNotesEnabled || !$row || !$row.length || !states || typeof states !== 'object') {
            return;
        }

        $row.find('[data-ll-search-word-review-note]').each(function () {
            const $wrap = $(this);
            const objectId = parseInt($wrap.attr('data-object-id'), 10) || 0;
            const state = objectId ? states[objectId] : null;
            const $input = $wrap.find('[data-ll-internal-review-note-input]').first();
            if (!state || !$input.length) {
                return;
            }

            if (state.open) {
                $wrap.attr('open', 'open');
            } else {
                $wrap.removeAttr('open');
            }

            if (state.dirty || state.hadTimer || state.focused || state.saving) {
                setSearchWordReviewNoteOriginalValue($input, state.original, false);
                $input.val(state.value);
                updateSearchWordReviewNoteSummary($wrap, state.value);
            }

            if (state.saving) {
                $wrap.addClass('is-saving');
            }
            if (state.statusMessage || state.statusState) {
                setSearchWordReviewNoteStatus($wrap, state.statusMessage, state.statusState);
            }
            if (state.dirty || state.hadTimer) {
                scheduleSearchWordReviewNoteSave($wrap);
            }
            if (state.focused) {
                const input = $input.get(0);
                if (input && !input.disabled) {
                    input.focus();
                    if (typeof input.setSelectionRange === 'function'
                        && typeof state.selectionStart === 'number'
                        && typeof state.selectionEnd === 'number') {
                        input.setSelectionRange(state.selectionStart, state.selectionEnd);
                    }
                }
            }
        });
    }

    function getSearchWordReviewNoteWrapsForWord(wordId) {
        const safeWordId = parseInt(wordId, 10) || 0;
        if (!safeWordId) {
            return $();
        }
        return $searchResults.find('[data-ll-search-word-review-note][data-object-id="' + safeWordId + '"]');
    }

    function updateCurrentSearchPayloadWordReviewNote(wordId, note) {
        const safeWordId = parseInt(wordId, 10) || 0;
        if (!safeWordId || !currentSearchPayload || !Array.isArray(currentSearchPayload.results)) {
            return;
        }

        const clean = (note || '').toString();
        let changed = false;
        const results = currentSearchPayload.results.map(function (entry) {
            const entryWordId = parseInt(entry && entry.word_id, 10) || 0;
            if (entryWordId !== safeWordId) {
                return entry;
            }
            changed = true;
            return $.extend({}, entry, { internal_review_note: clean });
        });

        if (changed) {
            currentSearchPayload = $.extend({}, currentSearchPayload, { results: results });
        }
    }

    function syncVisibleSearchWordReviewNotes(wordId, note, $sourceWrap, options) {
        const safeWordId = parseInt(wordId, 10) || 0;
        if (!safeWordId) {
            return;
        }

        const clean = (note || '').toString();
        const sourceElement = $sourceWrap && $sourceWrap.length ? $sourceWrap.get(0) : null;
        const skipSourceValue = !!(options && options.skipSourceValue);
        updateCurrentSearchPayloadWordReviewNote(safeWordId, clean);

        getSearchWordReviewNoteWrapsForWord(safeWordId).each(function () {
            const $wrap = $(this);
            const $input = $wrap.find('[data-ll-internal-review-note-input]').first();
            if (!$input.length) {
                return;
            }

            const isSource = sourceElement && sourceElement === $wrap.get(0);
            updateSearchWordReviewNoteSummary($wrap, clean);
            if (isSource && skipSourceValue) {
                return;
            }

            const current = ($input.val() || '').toString();
            const original = getSearchWordReviewNoteOriginalValue($input);
            const isDirty = !!$wrap.data('llSearchWordReviewNoteDirty') || current !== original;
            if (!isSource && isDirty) {
                return;
            }

            setSearchWordReviewNoteOriginalValue($input, clean, true);
            $wrap.removeData('llSearchWordReviewNoteDirty');
        });
    }

    function syncSearchWordReviewNoteFromRecord(rec) {
        const wordId = parseInt(rec && rec.word_id, 10) || 0;
        const note = getRecordInternalReviewNote(rec);
        if (!wordId || note === null) {
            return;
        }

        syncVisibleSearchWordReviewNotes(wordId, note);
    }

    function saveSearchWordReviewNote($wrap) {
        if (!internalNotesEnabled || !$wrap || !$wrap.length) {
            return;
        }

        const objectId = parseInt($wrap.attr('data-object-id'), 10) || 0;
        if (!$wrap.closest('html').length) {
            const $visibleWrap = objectId ? getSearchWordReviewNoteWrapsForWord(objectId).first() : $();
            if ($visibleWrap.length && $visibleWrap.get(0) !== $wrap.get(0)) {
                saveSearchWordReviewNote($visibleWrap);
            }
            return;
        }

        const $input = $wrap.find('[data-ll-internal-review-note-input]').first();
        if (!$input.length) {
            return;
        }

        clearSearchWordReviewNoteTimer($wrap);

        const note = ($input.val() || '').toString();
        const original = getSearchWordReviewNoteOriginalValue($input);
        if (note === original && !$wrap.data('llSearchWordReviewNoteDirty')) {
            return;
        }

        const objectType = ($wrap.attr('data-object-type') || '').toString();
        const wordsetId = parseInt($wrap.attr('data-wordset-id'), 10) || 0;
        if (!objectId || !objectType || !wordsetId) {
            return;
        }

        $wrap.removeData('llSearchWordReviewNoteDirty');
        $wrap.addClass('is-saving');
        setSearchWordReviewNoteStatus($wrap, getInternalNoteMessage('saving', 'Saving review note...'), 'saving');

        $.post(ajaxUrl, {
            action: internalNotesCfg.action || 'll_tools_save_internal_review_note',
            nonce: internalNotesCfg.nonce || '',
            object_id: objectId,
            object_type: objectType,
            wordset_id: wordsetId,
            note: note
        }).done(function (response) {
            if (!response || response.success !== true) {
                $wrap.data('llSearchWordReviewNoteDirty', true);
                setSearchWordReviewNoteStatus($wrap, readInternalNoteResponseMessage(response, getInternalNoteMessage('error', 'Unable to save the review note.')), 'error');
                return;
            }

            const data = response.data || {};
            const savedNote = (typeof data.note === 'string') ? data.note : note;
            const currentNote = ($input.val() || '').toString();
            const hasTypedAhead = currentNote !== note;
            setSearchWordReviewNoteOriginalValue($input, savedNote, !hasTypedAhead);
            syncVisibleSearchWordReviewNotes(objectId, savedNote, $wrap, { skipSourceValue: hasTypedAhead });
            setSearchWordReviewNoteStatus($wrap, getInternalNoteMessage('saved', 'Review note saved.'), 'success');
            if (hasTypedAhead) {
                $wrap.data('llSearchWordReviewNoteDirty', true);
                scheduleSearchWordReviewNoteSave($wrap);
            }
            window.setTimeout(function () {
                if (!$wrap.hasClass('is-saving')) {
                    setSearchWordReviewNoteStatus($wrap, '', '');
                }
            }, 1800);
        }).fail(function (jqXHR) {
            $wrap.data('llSearchWordReviewNoteDirty', true);
            setSearchWordReviewNoteStatus($wrap, readInternalNoteAjaxMessage(jqXHR, getInternalNoteMessage('error', 'Unable to save the review note.')), 'error');
        }).always(function () {
            $wrap.removeClass('is-saving');
        });
    }

    function scheduleSearchWordReviewNoteSave($wrap) {
        if (!internalNotesEnabled || !$wrap || !$wrap.length) {
            return;
        }

        clearSearchWordReviewNoteTimer($wrap);
        $wrap.data('llSearchWordReviewNoteDirty', true);
        const timer = window.setTimeout(function () {
            saveSearchWordReviewNote($wrap);
        }, internalNoteSaveDelayMs);
        $wrap.data('llSearchWordReviewNoteTimer', timer);
    }

    function buildSearchInputHighlight($input, mismatchDetail, field) {
        const detail = mismatchDetail && typeof mismatchDetail === 'object' ? mismatchDetail : null;
        if (!detail || detail.matches) {
            return null;
        }

        const value = ($input.val() || '').toString();
        const referenceText = field === 'recording_text'
            ? (detail.actual_text || '')
            : (detail.ipa_text || '');
        const spans = field === 'recording_text'
            ? (detail.actual_spans || [])
            : (detail.ipa_spans || []);

        if (!value || referenceText !== value || !shouldRenderInlineMismatchHighlight(spans, value)) {
            return null;
        }

        return $('<div>', {
            class: 'll-ipa-search-input-highlight',
            'aria-hidden': 'true'
        }).append(buildHighlightedText(value, spans, 'll-ipa-search-input-highlight-text'));
    }

    function buildSearchFieldInputWrap($input, mismatchDetail, field) {
        const $wrap = $('<div>', { class: 'll-ipa-search-field-wrap' });
        const $highlight = buildSearchInputHighlight($input, mismatchDetail, field);
        if ($highlight) {
            $wrap.addClass('ll-ipa-search-field-wrap--highlighted').append($highlight);
        }
        return $wrap.append($input);
    }

    function buildSearchFieldSuggestions(mismatchDetail, field) {
        const detail = mismatchDetail && typeof mismatchDetail === 'object' ? mismatchDetail : null;
        if (!detail || detail.matches || !currentCanEdit) {
            return null;
        }

        const $suggestions = $('<div>', { class: 'll-ipa-search-field-suggestions' });
        const labelTemplate = t('orthographyIssueInlineChangeTo', 'Change to: %s');

        if (field === 'recording_text' && detail.suggested_text && detail.suggested_text !== detail.actual_text) {
            const suggestedSpans = Array.isArray(detail.suggested_spans) && detail.suggested_spans.length
                ? detail.suggested_spans
                : buildSingleDiffSpan(detail.actual_text || '', detail.suggested_text || '');
            $suggestions.append($('<button>', {
                type: 'button',
                class: 'll-ipa-search-suggestion-chip ll-ipa-search-suggestion-chip--orthography ll-ipa-search-orthography-apply',
                'data-suggestion-field': 'recording_text',
                'data-suggestion-value': detail.suggested_text
            }).append(buildSuggestionChipLabel(
                labelTemplate,
                detail.suggested_text,
                suggestedSpans
            )));
        }

        if (field === 'recording_ipa' && Array.isArray(detail.ipa_suggestions)) {
            detail.ipa_suggestions.forEach(function (suggestion) {
                const ipa = suggestion && suggestion.ipa ? String(suggestion.ipa) : '';
                if (!ipa) {
                    return;
                }
                $suggestions.append($('<button>', {
                    type: 'button',
                    class: 'll-ipa-search-suggestion-chip ll-ipa-search-suggestion-chip--ipa ll-ipa-search-ipa-suggestion-apply',
                    'data-ipa': ipa,
                    'data-suggestion-field': 'recording_ipa',
                    'data-suggestion-value': ipa
                }).append(buildSuggestionChipLabel(
                    labelTemplate,
                    suggestion.label || ipa,
                    Array.isArray(suggestion.spans) ? suggestion.spans : buildSingleDiffSpan(detail.ipa_text || '', suggestion.label || ipa)
                )));
            });
        }

        return $suggestions.children().length ? $suggestions : null;
    }

    function buildSearchFieldBlock(cellClass, $input, field, reviewFields, reviewNote, label, mismatchDetail) {
        const $block = $('<div>', {
            class: 'll-ipa-search-field-block ' + cellClass,
            'data-field-label': label || ''
        });
        const $editor = $('<div>', { class: 'll-ipa-search-field-editor' });
        $editor.append($('<div>', {
            class: 'll-ipa-search-field-label',
            text: label || ''
        }));
        const $wrap = buildSearchFieldInputWrap($input, mismatchDetail, field);
        $editor.append($wrap);
        const $review = $('<div>', { class: 'll-ipa-search-field-review-panel' });
        if (reviewNote && searchReviewNoteAppliesToField(reviewFields, field)) {
            $review.append(buildSearchFieldReviewNote(reviewNote));
        }
        const $reviewAction = buildSearchFieldReviewAction(field, reviewFields);
        if ($reviewAction) {
            $review.append($reviewAction);
        }
        if ($review.children().length) {
            $editor.append($review);
        }
        const $suggestions = buildSearchFieldSuggestions(mismatchDetail, field);
        if ($suggestions) {
            $editor.append($suggestions);
        }
        $block.append($editor);
        return $block;
    }

    function buildSearchTranscriptionCell($textInput, $ipaInput, reviewFields, reviewNote, mismatchDetail) {
        const transcription = getTranscription();
        const textLabel = t('searchReviewTextLabel', 'Orthography');
        const ipaLabel = transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation');
        const $cell = $('<td>', {
            class: 'll-ipa-search-transcription-cell',
            'data-label': t('searchTranscriptionsLabel', 'Transcriptions')
        });
        return $cell.append(
            $('<div>', { class: 'll-ipa-search-transcription-stack' })
                .append(buildSearchFieldBlock('ll-ipa-search-text-cell', $textInput, 'recording_text', reviewFields, reviewNote, textLabel, mismatchDetail))
                .append(buildSearchFieldBlock('ll-ipa-search-ipa-cell', $ipaInput, 'recording_ipa', reviewFields, reviewNote, ipaLabel, mismatchDetail))
        );
    }

    function buildReviewToggleButton(needsReview, extraClass, reviewField) {
        if (!currentCanEdit) {
            return null;
        }

        const field = reviewField === 'recording_text' ? 'recording_text' : 'recording_ipa';
        const classes = ['button-link', 'll-ipa-review-toggle'];
        if (extraClass) {
            classes.push(extraClass);
        }
        const nextNeedsReview = !needsReview;

        return $('<button>', {
            type: 'button',
            class: classes.join(' '),
            text: getReviewToggleButtonText(nextNeedsReview),
            'aria-label': getReviewToggleButtonAriaLabel(field, nextNeedsReview),
            'data-next-review-state': nextNeedsReview ? '1' : '0',
            'data-current-review-state': needsReview ? '1' : '0',
            'data-review-field': field,
            'aria-pressed': needsReview ? 'true' : 'false'
        });
    }

    function buildIssuesCellData(activeIssues, ignoredIssues) {
        const active = Array.isArray(activeIssues) ? activeIssues : [];
        const ignored = Array.isArray(ignoredIssues) ? ignoredIssues : [];
        return {
            html: function () {
                const $wrap = $('<div>', { class: 'll-ipa-search-issues-wrap' });
                if (!active.length && !ignored.length) {
                    $wrap.append($('<span>', { class: 'll-ipa-search-issues-empty', text: t('searchNoIssues', 'No warnings') }));
                    return $wrap;
                }
                active.forEach(function (issue) {
                    $wrap.append(buildSearchIssueItem(issue, false));
                });
                if (ignored.length) {
                    const $ignoredSection = $('<div>', { class: 'll-ipa-search-ignored' });
                    $ignoredSection.append($('<div>', { class: 'll-ipa-search-ignored-title', text: t('searchIgnoredLabel', 'Ignored') }));
                    ignored.forEach(function (issue) {
                        $ignoredSection.append(buildSearchIssueItem(issue, true));
                    });
                    $wrap.append($ignoredSection);
                }
                return $wrap;
            }
        };
    }

    function buildCategoriesCell(categories) {
        const list = Array.isArray(categories) ? categories : [];
        if (!list.length) {
            return $('<span>', { class: 'll-ipa-search-empty', text: t('searchNoCategories', 'No categories') });
        }
        const $wrap = $('<div>', { class: 'll-ipa-search-categories' });
        list.forEach(function (category) {
            const name = category && category.name ? category.name : t('searchUnknownCategory', 'Unknown category');
            const url = category && (category.url || category.edit_url) ? (category.url || category.edit_url) : '';
            if (url) {
                $wrap.append($('<a>', {
                    class: 'll-ipa-search-category-link',
                    href: url,
                    target: '_blank',
                    text: name,
                    title: t('searchOpenCategory', 'Open category')
                }));
            } else {
                $wrap.append($('<span>', { class: 'll-ipa-search-category-link', text: name }));
            }
        });
        return $wrap;
    }

    function buildMetaCategoriesCell(categories) {
        const list = Array.isArray(categories) ? categories : [];
        if (!list.length) {
            return null;
        }

        return buildCategoriesCell(list).addClass('ll-ipa-search-meta-categories');
    }

    function buildSearchRow(rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
        const wordId = parseInt(rec && rec.word_id, 10) || 0;
        const image = rec && rec.image ? rec.image : {};
        const categories = rec && rec.categories ? rec.categories : [];
        const issues = rec && rec.issues ? rec.issues : [];
        const ignoredIssues = rec && rec.ignored_issues ? rec.ignored_issues : [];
        const reviewFields = normalizeReviewFields(rec && rec.review_fields ? rec.review_fields : (rec && rec.needs_review ? { recording_ipa: true } : null));
        const needsReview = !!(reviewFields.recording_text || reviewFields.recording_ipa);
        const reviewNote = rec && rec.review_note ? rec.review_note : '';
        const transcription = getTranscription();
        const textValue = rec && rec.recording_text ? rec.recording_text : '';
        const ipaValue = rec && rec.recording_ipa ? rec.recording_ipa : '';
        const mismatchDetail = getOrthographyMismatchDetailFromIssues(issues);

        const $metaCell = $('<td>', { class: 'll-ipa-search-meta-cell' });
        const $metaWrap = $('<div>', { class: 'll-ipa-search-meta' });
        const $wordWrap = $('<div>', { class: 'll-ipa-search-meta-word' });
        const $wordTitleRow = $('<div>', { class: 'll-ipa-search-word-title-row' });
        if (rec && rec.word_edit_link) {
            $wordTitleRow.append($('<a>', {
                class: 'll-ipa-search-word-link',
                href: rec.word_edit_link,
                target: '_blank',
                text: rec.word_text || t('untitled', '(Untitled)')
            }));
        } else {
            $wordTitleRow.append($('<span>', {
                class: 'll-ipa-search-word-link',
                text: rec.word_text || t('untitled', '(Untitled)')
            }));
        }
        if (currentCanEdit) {
            const $wordEditControl = buildSearchWordEditControl(rec);
            if ($wordEditControl) {
                $wordTitleRow.append($wordEditControl);
            }
        }
        $wordWrap.append($wordTitleRow);
        if (rec && rec.word_translation) {
            $wordWrap.append($('<span>', { class: 'll-ipa-translation', text: rec.word_translation }));
        }

        const $mediaWrap = $('<div>', { class: 'll-ipa-search-meta-media' });
        if (image && image.url) {
            $mediaWrap.append($('<img>', {
                class: 'll-ipa-search-thumb',
                src: image.url,
                alt: image.alt || ''
            }));
        } else {
            $mediaWrap.append($('<span>', { class: 'll-ipa-search-empty', text: t('searchNoImage', 'No image') }));
        }
        $mediaWrap.append($('<div>', {
            class: 'll-ipa-search-meta-recording'
        }).append(createAudioButton(rec, 'll-ipa-search-audio-btn', { showDownload: true })));

        $metaWrap.append($wordWrap, $mediaWrap);
        const $metaCategories = buildMetaCategoriesCell(categories);
        if ($metaCategories) {
            $metaWrap.append($metaCategories);
        }
        const $wordReviewNote = buildSearchWordReviewNote(rec);
        if ($wordReviewNote) {
            $metaWrap.append($wordReviewNote);
        }
        $metaCell.append($metaWrap);

        const $textInput = $('<textarea>', {
            class: 'll-ipa-search-input-field ll-ipa-search-text-input',
            rows: 2,
            disabled: !currentCanEdit,
            'aria-label': t('searchReviewTextLabel', 'Orthography')
        }).val(textValue).attr('data-saved-value', textValue);
        const $ipaInput = $('<textarea>', {
            class: 'll-ipa-search-input-field ll-ipa-search-ipa-input',
            rows: 2,
            disabled: !currentCanEdit,
            'aria-label': transcription.symbols_column_label || t('pronunciationLabel', 'Pronunciation')
        }).val(ipaValue).attr('data-saved-value', ipaValue);
        const $saveState = $('<div>', {
            class: 'll-ipa-search-save-state is-idle',
            'aria-live': 'polite'
        })
            .append($('<span>', { class: 'll-ipa-search-save-indicator', 'aria-hidden': 'true' }))
            .append($('<span>', { class: 'll-ipa-search-save-label' }));
        const $actionCell = $('<td>', { class: 'll-ipa-search-action-cell' });
        const $actionWrap = $('<div>', { class: 'll-ipa-search-actions' }).append($saveState);
        $actionCell.append($actionWrap);

        const $issueCell = $('<td>', {
            class: 'll-ipa-search-issues-cell',
            'data-label': t('searchIssuesLabel', 'Checks')
        }).append(
            buildIssuesCellData(issues, ignoredIssues).html()
        );

        return $('<tr>', {
            'data-recording-id': recordingId,
            'data-word-id': wordId,
            'data-needs-review': needsReview ? '1' : '0',
            'data-review-text': reviewFields.recording_text ? '1' : '0',
            'data-review-ipa': reviewFields.recording_ipa ? '1' : '0'
        })
            .append($metaCell)
            .append(buildSearchTranscriptionCell($textInput, $ipaInput, reviewFields, reviewNote, mismatchDetail))
            .append($issueCell)
            .append($actionCell);
    }

    function collectCustomRules() {
        const rules = [];
        $searchRules.find('.ll-ipa-rule-row').each(function () {
            const $row = $(this);
            const target = ($row.find('.ll-ipa-rule-target').val() || '').toString().trim();
            const label = ($row.find('.ll-ipa-rule-label').val() || '').toString().trim();
            const previous = ($row.find('.ll-ipa-rule-previous').val() || '').toString().trim();
            const next = ($row.find('.ll-ipa-rule-next').val() || '').toString().trim();
            const id = ($row.attr('data-rule-id') || '').toString();

            if (!target) {
                return;
            }

            rules.push({
                id: id,
                label: label,
                target: target,
                previous: previous,
                next: next
            });
        });
        return rules;
    }

    function renderValidationRules(payload) {
        const validationConfig = payload && payload.validation_config ? payload.validation_config : {};
        const builtinRules = Array.isArray(validationConfig.builtin_rules) ? validationConfig.builtin_rules : [];
        const customRules = Array.isArray(validationConfig.custom_rules) ? validationConfig.custom_rules : [];
        const supportsRules = !!validationConfig.supports_rules;

        $searchRules.empty();

        const $details = $('<details>', { class: 'll-ipa-rules-disclosure' });
        if (searchRulesExpanded) {
            $details.prop('open', true);
        }

        const $summary = $('<summary>', { class: 'll-ipa-rules-summary' });
        $summary.append($('<span>', {
            class: 'll-ipa-rules-summary-title',
            text: t('searchRulesSummary', 'IPA checks and typo rules')
        }));
        $summary.append($('<span>', {
            class: 'll-ipa-rules-summary-hint',
            text: t('searchRulesSummaryHint', 'Expand to review built-in checks and wordset-specific IPA rules.')
        }));
        $details.append($summary);

        const $panel = $('<div>', { class: 'll-ipa-rules-panel' });
        $panel.append($('<h3>', { text: t('searchRulesTitle', 'Wordset-specific IPA checks') }));
        $panel.append($('<p>', { class: 'description', text: t('searchRulesDescription', 'Add sounds that should never appear in this word set, or ban sounds in specific immediate environments.') }));

        if (!supportsRules) {
            $panel.append($('<div>', { class: 'll-ipa-empty', text: t('searchRulesUnavailable', 'Custom IPA checks are only available when this word set uses IPA transcription mode.') }));
            $details.append($panel);
            $searchRules.append($details);
            return;
        }

        const $builtinWrap = $('<div>', { class: 'll-ipa-rules-builtins' });
        $builtinWrap.append($('<div>', { class: 'll-ipa-rules-subtitle', text: t('searchBuiltinsTitle', 'Standard IPA checks') }));
        builtinRules.forEach(function (rule) {
            const $row = $('<label>', { class: 'll-ipa-builtin-rule' });
            const $checkbox = $('<input>', {
                type: 'checkbox',
                class: 'll-ipa-builtin-checkbox',
                'data-rule-code': rule.code || '',
                checked: !!rule.enabled,
                disabled: !currentCanEdit
            });
            const $copy = $('<span>', { class: 'll-ipa-builtin-copy' });
            $copy.append($('<span>', { class: 'll-ipa-builtin-title', text: rule.label || '' }));
            if (rule.description) {
                $copy.append($('<span>', { class: 'll-ipa-builtin-description', text: rule.description }));
            }
            $row.append($checkbox, $copy);
            $builtinWrap.append($row);
        });
        $panel.append($builtinWrap);

        const $customWrap = $('<div>', { class: 'll-ipa-rules-custom' });
        $customWrap.append($('<div>', { class: 'll-ipa-rules-subtitle', text: t('searchRulesTitle', 'Wordset-specific IPA checks') }));
        const $list = $('<div>', { class: 'll-ipa-rule-list' });

        function appendRuleRow(rule) {
            const $row = $('<div>', {
                class: 'll-ipa-rule-row',
                'data-rule-id': (rule && rule.id) ? rule.id : ''
            });
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-label',
                value: rule && rule.label ? rule.label : '',
                placeholder: t('searchRuleLabelPlaceholder', 'Optional note'),
                disabled: !currentCanEdit
            }));
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-target',
                value: rule && rule.target ? rule.target : '',
                placeholder: t('searchRuleTargetPlaceholder', 'e.g. t'),
                disabled: !currentCanEdit
            }));
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-previous',
                value: rule && rule.previous ? rule.previous : '',
                placeholder: t('searchRulePreviousPlaceholder', 'Previous sound(s)'),
                disabled: !currentCanEdit
            }));
            $row.append($('<input>', {
                type: 'text',
                class: 'll-ipa-rule-input ll-ipa-rule-next',
                value: rule && rule.next ? rule.next : '',
                placeholder: t('searchRuleNextPlaceholder', 'Next sound(s)'),
                disabled: !currentCanEdit
            }));
            $row.append($('<button>', {
                type: 'button',
                class: 'button button-secondary ll-ipa-rule-remove',
                text: t('searchRuleRemove', 'Remove'),
                disabled: !currentCanEdit
            }));
            $list.append($row);
        }

        if (customRules.length) {
            customRules.forEach(appendRuleRow);
        } else {
            appendRuleRow(null);
        }

        $customWrap.append($list);
        $customWrap.append($('<div>', { class: 'll-ipa-rule-hint', text: t('searchRuleHint', 'Leave Previous and Next empty to ban a sound everywhere. Separate multiple sounds with spaces.') }));

        const $actions = $('<div>', { class: 'll-ipa-rule-actions' });
        $actions.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-rule-add',
            text: t('searchRuleAdd', 'Add check'),
            disabled: !currentCanEdit
        }));
        $actions.append($('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-rule-save',
            text: t('searchRuleSave', 'Save checks'),
            disabled: !currentCanEdit
        }));
        $customWrap.append($actions);
        $panel.append($customWrap);

        $details.append($panel);
        $searchRules.append($details);
    }

    function renderSearch(payload) {
        const results = Array.isArray(payload.results) ? payload.results : [];
        const totalMatches = parseInt(payload.total_matches, 10) || 0;
        const pageStart = parseInt(payload.page_start, 10) || 0;
        const pageEnd = parseInt(payload.page_end, 10) || 0;
        const issuesOnly = !!payload.issues_only;
        const reviewOnly = !!payload.review_only;

        currentSearchPayload = $.extend({}, payload, {
            results: results.slice()
        });
        renderValidationRules(payload);
        $searchResults.empty();

        if (!results.length) {
            setSearchSummary('');
            $searchResults.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('searchResultsEmpty', 'No recordings matched this search.')
            }));
            return;
        }

        setSearchSummary(buildSearchSummaryText(totalMatches, pageStart, pageEnd, issuesOnly, reviewOnly));

        const $table = $('<table>', { class: 'widefat striped ll-ipa-search-table' });
        const $colgroup = $('<colgroup>')
            .append($('<col>', { class: 'll-ipa-search-col-meta' }))
            .append($('<col>', { class: 'll-ipa-search-col-transcriptions' }))
            .append($('<col>', { class: 'll-ipa-search-col-checks' }))
            .append($('<col>', { class: 'll-ipa-search-col-actions' }));
        const $thead = $('<thead>').append(
            $('<tr>')
                .append($('<th>', { class: 'll-ipa-search-meta-heading', text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { class: 'll-ipa-search-transcription-heading', text: t('searchTranscriptionsLabel', 'Transcriptions') }))
                .append($('<th>', { class: 'll-ipa-search-checks-heading', text: t('searchIssuesLabel', 'Checks') }))
                .append($('<th>', { class: 'll-ipa-search-actions-heading', text: '' }))
        );
        const $tbody = $('<tbody>');
        results.forEach(function (rec) {
            $tbody.append(buildSearchRow(rec));
        });
        $table.append($colgroup, $thead, $tbody);
        $searchResults.append($table);

        replaceSearchLazyControl(currentSearchPayload, false);
    }

    function appendSearch(payload) {
        const results = Array.isArray(payload.results) ? payload.results : [];
        const $table = $searchResults.children('.ll-ipa-search-table').first();
        const $tbody = $table.children('tbody').first();
        if (!$table.length || !$tbody.length || !currentSearchPayload) {
            renderSearch(payload);
            return;
        }

        const appended = [];
        const existingIds = {};
        $tbody.children('tr[data-recording-id]').each(function () {
            const recordingId = parseInt($(this).attr('data-recording-id'), 10) || 0;
            if (recordingId > 0) {
                existingIds[recordingId] = true;
            }
        });

        results.forEach(function (rec) {
            const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
            if (recordingId > 0 && existingIds[recordingId]) {
                return;
            }
            $tbody.append(buildSearchRow(rec));
            if (recordingId > 0) {
                existingIds[recordingId] = true;
            }
            appended.push(rec);
        });

        const previousResults = Array.isArray(currentSearchPayload.results) ? currentSearchPayload.results : [];
        const pageStart = parseInt(currentSearchPayload.page_start, 10) || parseInt(payload.page_start, 10) || 0;
        const pageEnd = parseInt(payload.page_end, 10) || pageStart + $tbody.children('tr').length - 1;
        currentSearchPayload = $.extend({}, currentSearchPayload, payload, {
            results: previousResults.concat(appended),
            shown_count: $tbody.children('tr').length,
            page_start: pageStart,
            page_end: pageEnd
        });

        setSearchSummary(buildSearchSummaryText(
            parseInt(currentSearchPayload.total_matches, 10) || 0,
            parseInt(currentSearchPayload.page_start, 10) || 0,
            parseInt(currentSearchPayload.page_end, 10) || 0,
            !!currentSearchPayload.issues_only,
            !!currentSearchPayload.review_only
        ));
        replaceSearchLazyControl(currentSearchPayload, false);
    }

    function loadOrthography(wordsetId, shouldLoad) {
        if (!shouldLoad) {
            return;
        }

        setStatus(t('orthographyLoading', 'Loading orthography conversion data...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_orthography',
            nonce: nonce,
            wordset_id: wordsetId
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            handleWordsetResponse(response);
            renderOrthography(response.data || {});
            tabDirty.orthography = false;
            setStatus('');
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    function buildOrthographyWordMeta(item) {
        const $wrap = $('<div>', { class: 'll-ipa-orthography-word-meta' });
        const title = item && item.word_text ? item.word_text : t('untitled', '(Untitled)');
        if (item && item.word_edit_link) {
            $wrap.append($('<a>', {
                class: 'll-ipa-orthography-word-link',
                href: item.word_edit_link,
                target: '_blank',
                text: title
            }));
        } else {
            $wrap.append($('<span>', {
                class: 'll-ipa-orthography-word-link',
                text: title
            }));
        }
        if (item && item.word_translation) {
            $wrap.append($('<span>', {
                class: 'll-ipa-translation',
                text: item.word_translation
            }));
        }
        return $wrap;
    }

    function buildOrthographyExamples(samples) {
        const list = Array.isArray(samples) ? samples : [];
        if (!list.length) {
            return null;
        }

        const $details = $('<details>', { class: 'll-ipa-orthography-examples' });
        $details.append($('<summary>', { text: t('orthographyRuleExamples', 'Examples') + ' (' + list.length + ')' }));
        const $list = $('<div>', { class: 'll-ipa-orthography-examples-list' });
        list.forEach(function (sample) {
            const $item = $('<div>', { class: 'll-ipa-orthography-example' });
            const title = sample && sample.word_text ? sample.word_text : t('untitled', '(Untitled)');
            if (sample && sample.word_edit_link) {
                $item.append($('<a>', {
                    class: 'll-ipa-orthography-example-link',
                    href: sample.word_edit_link,
                    target: '_blank',
                    text: title
                }));
            } else {
                $item.append($('<span>', {
                    class: 'll-ipa-orthography-example-link',
                    text: title
                }));
            }
            if (sample && sample.word_translation) {
                $item.append($('<span>', { class: 'll-ipa-translation', text: sample.word_translation }));
            }
            if (sample && sample.recording_text) {
                $item.append($('<div>', {
                    class: 'll-ipa-orthography-example-line',
                    text: t('orthographyIssueActual', 'Saved text') + ': ' + sample.recording_text
                }));
            }
            if (sample && sample.recording_ipa) {
                $item.append($('<div>', {
                    class: 'll-ipa-orthography-example-line ll-ipa-orthography-example-ipa',
                    text: sample.recording_ipa
                }));
            }
            $list.append($item);
        });
        $details.append($list);
        return $details;
    }

    function renderOrthographySummary(orthography) {
        const stats = orthography && orthography.stats ? orthography.stats : {};
        const profile = orthography && orthography.conversion_profile ? orthography.conversion_profile : {};
        const $grid = $('<div>', { class: 'll-ipa-orthography-stat-grid' });
        [
            {
                label: t('orthographySummaryRules', 'Rules'),
                value: parseInt(stats.rule_count, 10) || 0
            },
            {
                label: t('orthographySummaryIssues', 'Contradictions'),
                value: parseInt(stats.active_contradiction_count, 10) || 0
            },
            {
                label: t('orthographySummaryQueue', 'Missing text'),
                value: parseInt(stats.candidate_count, 10) || 0
            },
            {
                label: t('orthographySummaryProfile', 'Profile'),
                value: profile && profile.short_label ? profile.short_label : t('orthographySummaryProfileNone', 'Generic')
            }
        ].forEach(function (card) {
            const $item = $('<div>', { class: 'll-ipa-orthography-stat' });
            $item.append($('<span>', { class: 'll-ipa-orthography-stat-label', text: card.label }));
            $item.append($('<strong>', { class: 'll-ipa-orthography-stat-value', text: String(card.value) }));
            $grid.append($item);
        });

        $orthographySummary.empty().append($grid);
    }

    function buildOrthographyContextOptions(selectedValue) {
        const selected = (selectedValue || 'any').toString();
        return [
            { value: 'any', label: t('orthographyRuleAny', 'Anywhere') },
            { value: 'final', label: t('orthographyRuleFinal', 'Word-final') },
            { value: 'nonfinal', label: t('orthographyRuleNonfinal', 'Elsewhere') }
        ].map(function (item) {
            return $('<option>', {
                value: item.value,
                text: item.label,
                selected: item.value === selected
            });
        });
    }

    function renderOrthographyRules(orthography) {
        const rows = orthography && Array.isArray(orthography.rules) ? orthography.rules : [];
        $orthographyRules.empty();

        const $section = $('<div>', { class: 'll-ipa-orthography-section' });
        $section.append($('<h3>', { text: t('orthographyRulesTitle', 'Detected conversion rules') }));
        $section.append($('<p>', {
            class: 'description',
            text: t('orthographyRulesDescription', 'Review the inferred IPA-to-orthography rules, block bad guesses, and add manual overrides.')
        }));

        const $add = $('<div>', { class: 'll-ipa-orthography-add' });
        $add.append($('<div>', { class: 'll-ipa-orthography-add-title', text: t('orthographyRuleAddTitle', 'Add manual rule') }));
        const $addFields = $('<div>', { class: 'll-ipa-orthography-add-fields' });
        $addFields.append($('<input>', {
            type: 'text',
            class: 'll-ipa-orthography-add-segment',
            placeholder: t('orthographyRuleAddSegmentPlaceholder', 'e.g. ʃ or t͡ʃ'),
            'aria-label': t('orthographyRuleAddSegment', 'IPA segment'),
            disabled: !currentCanEdit
        }));
        const $context = $('<select>', {
            class: 'll-ipa-orthography-add-context',
            'aria-label': t('orthographyRuleAddContext', 'Position'),
            disabled: !currentCanEdit
        });
        buildOrthographyContextOptions('any').forEach(function ($option) {
            $context.append($option);
        });
        $addFields.append($context);
        $addFields.append($('<input>', {
            type: 'text',
            class: 'll-ipa-orthography-add-output',
            placeholder: t('orthographyRuleAddOutputPlaceholder', 'e.g. sh'),
            'aria-label': t('orthographyRuleAddOutput', 'Orthography'),
            disabled: !currentCanEdit
        }));
        $addFields.append($('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-orthography-add-btn',
            text: t('orthographyRuleAddButton', 'Add rule'),
            disabled: !currentCanEdit
        }));
        $add.append($addFields);
        $section.append($add);

        if (!rows.length) {
            $section.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyRulesEmpty', 'No usable IPA/text pairings were found yet for this word set.')
            }));
            $orthographyRules.append($section);
            return;
        }

        const $table = $('<table>', { class: 'widefat striped ll-ipa-orthography-rules-table' });
        $table.append($('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('orthographyRuleSegment', 'IPA segment') }))
                .append($('<th>', { text: t('orthographyRuleAuto', 'Auto rules') }))
                .append($('<th>', { text: t('orthographyRuleManual', 'Manual overrides') }))
        ));
        const $tbody = $('<tbody>');

        rows.forEach(function (row) {
            const segment = row && row.segment ? row.segment : '';
            const autoRules = row && Array.isArray(row.auto) ? row.auto : [];
            const manual = row && row.manual ? row.manual : {};
            const blocked = row && Array.isArray(row.blocked) ? row.blocked : [];
            const $tr = $('<tr>', { 'data-segment': segment });
            $tr.append($('<td>', { class: 'll-ipa-orthography-segment', text: segment }));

            const $autoCell = $('<td>');
            if (autoRules.length) {
                const $autoWrap = $('<div>', { class: 'll-ipa-orthography-auto-list' });
                autoRules.forEach(function (rule) {
                    const $rule = $('<div>', { class: 'll-ipa-orthography-auto-rule' });
                    const contextLabel = rule && rule.context && rule.context !== 'any'
                        ? (t('orthographyRule' + rule.context.charAt(0).toUpperCase() + rule.context.slice(1), '') || '')
                        : '';
                    const prefix = contextLabel ? (contextLabel + ': ') : '';
                    $rule.append($('<div>', {
                        class: 'll-ipa-orthography-auto-rule-main',
                        text: prefix + (rule && rule.output ? rule.output : '')
                    }));
                    if (rule && parseInt(rule.count, 10)) {
                        $rule.append($('<div>', {
                            class: 'll-ipa-orthography-auto-rule-count',
                            text: '(' + String(parseInt(rule.count, 10) || 0) + ')'
                        }));
                    }
                    if (currentCanEdit) {
                        $rule.append($('<button>', {
                            type: 'button',
                            class: 'button-link ll-ipa-orthography-block',
                            text: t('orthographyRuleBlock', 'Hide auto rule'),
                            'data-segment': segment,
                            'data-context': rule && rule.context ? rule.context : 'any',
                            'data-output': rule && rule.output ? rule.output : ''
                        }));
                    }
                    const $examples = buildOrthographyExamples(rule && rule.samples ? rule.samples : []);
                    if ($examples) {
                        $rule.append($examples);
                    }
                    $autoWrap.append($rule);
                });
                $autoCell.append($autoWrap);
            } else {
                $autoCell.append($('<span>', {
                    class: 'll-ipa-map-empty',
                    text: t('mapAutoEmpty', 'No mappings yet.')
                }));
            }

            if (blocked.length) {
                const $blocked = $('<div>', { class: 'll-ipa-orthography-blocked' });
                $blocked.append($('<div>', {
                    class: 'll-ipa-orthography-blocked-title',
                    text: t('orthographyRuleBlockedTitle', 'Hidden auto rules')
                }));
                blocked.forEach(function (rule) {
                    const contextLabel = rule && rule.context && rule.context !== 'any'
                        ? (rule.context === 'final' ? t('orthographyRuleFinal', 'Word-final') : t('orthographyRuleNonfinal', 'Elsewhere')) + ': '
                        : '';
                    const $item = $('<div>', { class: 'll-ipa-orthography-blocked-item' });
                    $item.append($('<span>', {
                        class: 'll-ipa-orthography-blocked-value',
                        text: contextLabel + (rule && rule.output ? rule.output : '')
                    }));
                    if (currentCanEdit) {
                        $item.append($('<button>', {
                            type: 'button',
                            class: 'button-link ll-ipa-orthography-unblock',
                            text: t('orthographyRuleUnblock', 'Restore'),
                            'data-segment': segment,
                            'data-context': rule && rule.context ? rule.context : 'any',
                            'data-output': rule && rule.output ? rule.output : ''
                        }));
                    }
                    $blocked.append($item);
                });
                $autoCell.append($blocked);
            }

            $tr.append($autoCell);

            const $manualCell = $('<td>');
            ['any', 'final', 'nonfinal'].forEach(function (contextKey) {
                const label = contextKey === 'final'
                    ? t('orthographyRuleFinal', 'Word-final')
                    : (contextKey === 'nonfinal' ? t('orthographyRuleNonfinal', 'Elsewhere') : t('orthographyRuleAny', 'Anywhere'));
                const $rowWrap = $('<div>', {
                    class: 'll-ipa-orthography-manual-row',
                    'data-context': contextKey
                });
                $rowWrap.append($('<label>', {
                    class: 'll-ipa-orthography-manual-label',
                    text: label
                }));
                $rowWrap.append($('<input>', {
                    type: 'text',
                    class: 'll-ipa-orthography-manual-input',
                    value: manual && manual[contextKey] ? manual[contextKey] : '',
                    disabled: !currentCanEdit
                }));
                $rowWrap.append($('<button>', {
                    type: 'button',
                    class: 'button button-secondary ll-ipa-orthography-manual-save',
                    text: t('orthographyRuleSave', 'Save rules'),
                    'data-segment': segment,
                    'data-context': contextKey,
                    disabled: !currentCanEdit
                }));
                $rowWrap.append($('<button>', {
                    type: 'button',
                    class: 'button-link ll-ipa-orthography-manual-clear',
                    text: t('orthographyRuleClear', 'Clear'),
                    'data-segment': segment,
                    'data-context': contextKey,
                    disabled: !currentCanEdit
                }));
                $manualCell.append($rowWrap);
            });
            $tr.append($manualCell);
            $tbody.append($tr);
        });

        $table.append($tbody);
        $section.append($table);
        $orthographyRules.append($section);
    }

    function renderOrthographyIssues(orthography) {
        const rows = orthography && Array.isArray(orthography.contradictions) ? orthography.contradictions : [];
        const stats = orthography && orthography.stats ? orthography.stats : {};
        $orthographyIssues.empty();

        const $section = $('<div>', { class: 'll-ipa-orthography-section' });
        $section.append($('<h3>', { text: t('orthographyIssuesTitle', 'Contradicting words') }));
        $section.append($('<p>', {
            class: 'description',
            text: t('orthographyIssuesDescription', 'These saved IPA/text pairings do not match the current rules. You can approve a word as an exception or keep adjusting the rules.')
        }));

        if (!rows.length) {
            $section.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyIssuesEmpty', 'No contradictions found with the current rules.')
            }));
            $orthographyIssues.append($section);
            return;
        }

        const activeCount = parseInt(stats.active_contradiction_count, 10) || 0;
        $section.append($('<div>', {
            class: 'll-ipa-orthography-section-summary',
            text: formatCount(
                activeCount,
                'orthographyIssuesSummary',
                'orthographyIssuesSummaryPlural',
                '%1$d contradicting word',
                '%1$d contradicting words'
            )
        }));

        const $table = $('<table>', { class: 'widefat striped ll-ipa-orthography-issues-table' });
        $table.append($('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { text: t('recordingColumnLabel', 'Recording') }))
                .append($('<th>', { text: getTranscription().symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: t('orthographyIssueActual', 'Saved text') }))
                .append($('<th>', { text: t('orthographyIssuePredicted', 'Predicted text') }))
                .append($('<th>', { text: '' }))
        ));
        const $tbody = $('<tbody>');
        rows.forEach(function (row) {
            const approved = !!(row && row.approved_exception);
            const $tr = $('<tr>', {
                'data-word-id': row && row.word_id ? row.word_id : 0,
                class: approved ? 'is-approved' : ''
            });
            $tr.append($('<td>').append(buildOrthographyWordMeta(row)));
            $tr.append($('<td>').append(createAudioButton(row, 'll-ipa-search-audio-btn', { showDownload: true })));
            const mismatch = row && row.orthography_mismatch ? row.orthography_mismatch : null;
            $tr.append($('<td>', {
                class: 'll-ipa-orthography-ipa-cell'
            }).append(buildHighlightedText(
                row && row.recording_ipa ? row.recording_ipa : '',
                mismatch && Array.isArray(mismatch.ipa_spans) ? mismatch.ipa_spans : [],
                'll-ipa-orthography-mismatch-text'
            )));
            $tr.append($('<td>').append(row && row.recording_text
                ? buildHighlightedText(
                    row.recording_text,
                    mismatch && Array.isArray(mismatch.actual_spans) ? mismatch.actual_spans : [],
                    'll-ipa-orthography-mismatch-text'
                )
                : document.createTextNode('—')
            ));
            const $predictedCell = $('<td>');
            const predictedText = row && row.predicted_text ? row.predicted_text : '';
            $predictedCell.append($('<div>').append(predictedText
                ? buildHighlightedText(
                    predictedText,
                    mismatch && Array.isArray(mismatch.suggested_spans) ? mismatch.suggested_spans : [],
                    'll-ipa-orthography-mismatch-text'
                )
                : document.createTextNode(t('orthographyConvertCannot', 'Needs more rules'))
            ));
            if (row && row.prediction_source_label) {
                $predictedCell.append($('<div>', {
                    class: 'll-ipa-orthography-prediction-source',
                    text: row.prediction_source_label
                }));
            }
            $tr.append($predictedCell);
            const $actions = $('<td>', { class: 'll-ipa-orthography-issue-actions' });
            if (approved) {
                $actions.append($('<span>', {
                    class: 'll-ipa-orthography-approved-label',
                    text: t('orthographyIssueApproved', 'Approved exception')
                }));
            }
            if (currentCanEdit) {
                if (row && row.can_apply_suggestion && row.recording_id) {
                    $actions.append($('<button>', {
                        type: 'button',
                        class: 'button button-primary ll-ipa-orthography-suggestion-apply',
                        text: t('orthographyIssueApplySuggestion', 'Use suggestion'),
                        'data-recording-id': row.recording_id
                    }));
                }
                $actions.append($('<button>', {
                    type: 'button',
                    class: 'button button-secondary ll-ipa-orthography-exception-toggle',
                    text: approved ? t('orthographyIssueRestore', 'Undo exception') : t('orthographyIssueApprove', 'Approve exception'),
                    'data-word-id': row && row.word_id ? row.word_id : 0,
                    'data-enabled': approved ? '0' : '1'
                }));
            }
            $tr.append($actions);
            $tbody.append($tr);
        });
        $table.append($tbody);
        $section.append($table);
        $orthographyIssues.append($section);
    }

    function renderOrthographyConvert(orthography) {
        const rows = orthography && Array.isArray(orthography.conversion_candidates) ? orthography.conversion_candidates : [];
        const stats = orthography && orthography.stats ? orthography.stats : {};
        $orthographyConvert.empty();

        const $section = $('<div>', { class: 'll-ipa-orthography-section' });
        $section.append($('<h3>', { text: t('orthographyConvertTitle', 'Words missing written text') }));
        $section.append($('<p>', {
            class: 'description',
            text: t('orthographyConvertDescription', 'Apply the current rules to words that have IPA saved but still need written text.')
        }));

        if (!rows.length) {
            $section.append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyConvertEmpty', 'No words are waiting for IPA-to-orthography conversion.')
            }));
            $orthographyConvert.append($section);
            return;
        }

        $section.append($('<div>', {
            class: 'll-ipa-orthography-section-summary',
            text: formatCount(
                parseInt(stats.candidate_count, 10) || rows.length,
                'orthographyConvertSummary',
                'orthographyConvertSummaryPlural',
                '%1$d word ready to convert',
                '%1$d words ready to convert'
            )
        }));

        const $controls = $('<div>', { class: 'll-ipa-orthography-convert-controls' });
        $controls.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-orthography-select-all',
            text: t('orthographyConvertSelectAll', 'Select all'),
            disabled: !currentCanEdit
        }));
        $controls.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-orthography-clear-selection',
            text: t('orthographyConvertClearSelection', 'Clear selection'),
            disabled: !currentCanEdit
        }));
        $controls.append($('<button>', {
            type: 'button',
            class: 'button button-primary ll-ipa-orthography-convert-selected',
            text: t('orthographyConvertSelected', 'Convert selected'),
            disabled: !currentCanEdit
        }));
        $section.append($controls);

        const $table = $('<table>', { class: 'widefat striped ll-ipa-orthography-convert-table' });
        $table.append($('<thead>').append(
            $('<tr>')
                .append($('<th>', { text: '' }))
                .append($('<th>', { text: t('searchWordLabel', 'Word') }))
                .append($('<th>', { text: t('recordingColumnLabel', 'Recording') }))
                .append($('<th>', { text: getTranscription().symbols_column_label || t('pronunciationLabel', 'Pronunciation') }))
                .append($('<th>', { text: t('orthographyConvertPreview', 'Predicted text') }))
                .append($('<th>', { text: t('orthographyConvertReason', 'Status') }))
                .append($('<th>', { text: '' }))
        ));
        const $tbody = $('<tbody>');
        rows.forEach(function (row) {
            const canConvert = !!(row && row.can_convert);
            const wordId = row && row.word_id ? row.word_id : 0;
            const $tr = $('<tr>', {
                'data-word-id': wordId,
                'data-can-convert': canConvert ? '1' : '0'
            });
            $tr.append($('<td>').append($('<input>', {
                type: 'checkbox',
                class: 'll-ipa-orthography-select',
                value: wordId,
                disabled: !currentCanEdit || !canConvert
            })));
            $tr.append($('<td>').append(buildOrthographyWordMeta(row)));
            $tr.append($('<td>').append(createAudioButton(row, 'll-ipa-search-audio-btn', { showDownload: true })));
            $tr.append($('<td>', {
                class: 'll-ipa-orthography-ipa-cell',
                text: row && row.recording_ipa ? row.recording_ipa : ''
            }));
            $tr.append($('<td>', {
                text: row && row.predicted_text ? row.predicted_text : '—'
            }));
            $tr.append($('<td>', {
                text: canConvert ? '' : t('orthographyConvertCannot', 'Needs more rules')
            }));
            $tr.append($('<td>').append($('<button>', {
                type: 'button',
                class: 'button button-primary ll-ipa-orthography-convert-one',
                text: t('orthographyConvertOne', 'Convert'),
                'data-word-id': wordId,
                disabled: !currentCanEdit || !canConvert
            })));
            $tbody.append($tr);
        });
        $table.append($tbody);
        $section.append($table);
        $orthographyConvert.append($section);
    }

    function renderOrthography(payload) {
        const orthography = payload && payload.orthography ? payload.orthography : {};
        renderOrthographySummary(orthography);
        if (!orthography || orthography.supported === false) {
            $orthographyRules.empty().append($('<div>', {
                class: 'll-ipa-empty',
                text: t('orthographyUnsupported', 'IPA-to-orthography conversion is only available when this word set uses IPA transcription mode.')
            }));
            $orthographyIssues.empty();
            $orthographyConvert.empty();
            return;
        }

        renderOrthographyRules(orthography);
        renderOrthographyIssues(orthography);
        renderOrthographyConvert(orthography);
    }

    function syncSavedRecording(data) {
        const recording = data && data.recording ? data.recording : {};
        const recordingId = parseInt(recording.recording_id || data.recording_id, 10) || 0;
        const previousCounts = data && data.previous_symbol_counts ? data.previous_symbol_counts : {};
        const nextCounts = data && data.symbol_counts ? data.symbol_counts : {};

        if (!recordingId) {
            return;
        }

        if (currentAudioButton && (parseInt($(currentAudioButton).attr('data-recording-id'), 10) || 0) === recordingId) {
            stopCurrentAudio();
        }

        Object.keys($.extend({}, previousCounts, nextCounts)).forEach(function (symbol) {
            const previousCount = parseInt(previousCounts[symbol], 10) || 0;
            const nextCount = parseInt(nextCounts[symbol], 10) || 0;
            let $details = findSymbolDetails(symbol);
            if (nextCount > 0 && !$details.length) {
                $details = createSymbolDetails(symbol);
                $symbols.append($details);
            }
            if (!$details.length) {
                return;
            }

            if (nextCount > 0) {
                const $tbody = buildSymbolsTableBody($details);
                const $existing = $tbody.children('tr').filter(function () {
                    return (parseInt($(this).attr('data-recording-id'), 10) || 0) === recordingId;
                }).first();
                const $row = buildRecordingRow(recording);
                if ($existing.length) {
                    $existing.replaceWith($row);
                } else {
                    $tbody.append($row);
                }
            } else {
                $details.find('tr[data-recording-id="' + recordingId + '"]').remove();
                ensureSymbolEmptyState($details);
            }

            const currentRecordingCount = parseInt($details.attr('data-recording-count'), 10) || 0;
            const currentOccurrenceCount = parseInt($details.attr('data-occurrence-count'), 10) || 0;
            const recordingDelta = (nextCount > 0 ? 1 : 0) - (previousCount > 0 ? 1 : 0);
            const occurrenceDelta = nextCount - previousCount;
            setSymbolSummaryCounts($details, currentRecordingCount + recordingDelta, currentOccurrenceCount + occurrenceDelta);
        });

        if (data && data.transcription) {
            applyTranscriptionConfig(data.transcription);
        } else {
            applyKeyboardSymbols(data && data.keyboard_symbols ? data.keyboard_symbols : currentKeyboardSymbols);
        }
    }

    function refreshSearchRowInlineMismatch($row, activeIssues) {
        const mismatchDetail = getOrthographyMismatchDetailFromIssues(activeIssues);
        [
            { field: 'recording_text', selector: '.ll-ipa-search-text-cell' },
            { field: 'recording_ipa', selector: '.ll-ipa-search-ipa-cell' }
        ].forEach(function (entry) {
            const $block = $row.find(entry.selector).first();
            const $input = getSearchRowInputForField($row, entry.field);
            if (!$block.length || !$input.length) {
                return;
            }

            const $oldWrap = $input.closest('.ll-ipa-search-field-wrap');
            const $newWrap = buildSearchFieldInputWrap($input.detach(), mismatchDetail, entry.field);
            if ($oldWrap.length) {
                $oldWrap.replaceWith($newWrap);
            } else {
                $block.find('.ll-ipa-search-field-editor').first().append($newWrap);
            }
            $block.find('.ll-ipa-search-field-suggestions').remove();
            const $suggestions = buildSearchFieldSuggestions(mismatchDetail, entry.field);
            if ($suggestions) {
                $newWrap.after($suggestions);
            }
            syncSearchInputHighlight($input.get(0));
        });
        setSearchRowDirtyState($row, searchRowHasUnsavedChanges($row));
    }

    function updateSearchRowValidation($row, validation) {
        const active = validation && Array.isArray(validation.active) ? validation.active : [];
        const ignored = validation && Array.isArray(validation.ignored) ? validation.ignored : [];
        const $cell = $row.find('.ll-ipa-search-issues-cell').first();
        if ($cell.length) {
            $cell.empty().append(buildIssuesCellData(active, ignored).html());
        }
        refreshSearchRowInlineMismatch($row, active);
    }

    function syncSearchInputHighlight(input) {
        const $input = $(input);
        const $highlight = $input.closest('.ll-ipa-search-field-wrap').find('.ll-ipa-search-input-highlight').first();
        if (!$highlight.length) {
            return;
        }
        $highlight.scrollTop(input.scrollTop || 0);
        $highlight.scrollLeft(input.scrollLeft || 0);
    }

    function replaceSearchRow($row, rec) {
        const layoutLocks = getSearchRowLayoutLocks($row);
        const reviewNoteStates = captureSearchWordReviewNoteStates($row);
        const $newRow = buildSearchRow(rec);
        suppressSearchWordReviewNoteBlurSave = true;
        $row.replaceWith($newRow);
        window.setTimeout(function () {
            suppressSearchWordReviewNoteBlurSave = false;
        }, 0);
        applySearchRowLayoutLocks($newRow, layoutLocks);
        syncSearchWordReviewNoteFromRecord(rec);
        restoreSearchWordReviewNoteStates($newRow, reviewNoteStates);
        cleanupDetachedIpaKeyboard();
        return $newRow;
    }

    function getSearchRowByRecordingId(recordingId) {
        const safeRecordingId = parseInt(recordingId, 10) || 0;
        if (!safeRecordingId) {
            return $();
        }

        return $searchResults.find('tr[data-recording-id="' + safeRecordingId + '"]').first();
    }

    function setSearchReviewSavingStateByRecordingId(recordingId, reviewField, saving) {
        const $row = getSearchRowByRecordingId(recordingId);
        if (!$row.length) {
            return;
        }

        setSearchReviewSavingState($row, reviewField, saving);
    }

    function replaceSearchRowByRecordingId(recordingId, rec) {
        const safeRecordingId = parseInt(recordingId, 10) || 0;
        if (!safeRecordingId) {
            return null;
        }

        const $row = getSearchRowByRecordingId(safeRecordingId);
        if (!$row.length) {
            return null;
        }

        const $newRow = replaceSearchRow($row, rec);
        replaceCurrentSearchPayloadRecording(rec);
        return $newRow;
    }

    function uniquePositiveIntegerList(values) {
        const source = Array.isArray(values) ? values : [values];
        const seen = {};
        const ids = [];
        source.forEach(function (value) {
            const id = parseInt(value, 10) || 0;
            if (id > 0 && !seen[id]) {
                seen[id] = true;
                ids.push(id);
            }
        });

        return ids;
    }

    function replaceCurrentSearchPayloadRecording(rec) {
        const recordingId = parseInt(rec && rec.recording_id, 10) || parseInt(rec && rec.id, 10) || 0;
        if (!recordingId || !currentSearchPayload || !Array.isArray(currentSearchPayload.results)) {
            return;
        }

        let replaced = false;
        const results = currentSearchPayload.results.map(function (entry) {
            const entryRecordingId = parseInt(entry && entry.recording_id, 10) || 0;
            if (entryRecordingId === recordingId) {
                replaced = true;
                return rec;
            }
            return entry;
        });

        if (replaced) {
            currentSearchPayload = $.extend({}, currentSearchPayload, { results: results });
        }
    }

    function removeCurrentSearchPayloadRecordings(recordingIds) {
        const ids = uniquePositiveIntegerList(recordingIds);
        if (!ids.length || !currentSearchPayload || !Array.isArray(currentSearchPayload.results)) {
            return;
        }

        const idLookup = {};
        ids.forEach(function (recordingId) {
            idLookup[recordingId] = true;
        });

        const previousResults = currentSearchPayload.results;
        const results = previousResults.filter(function (entry) {
            const recordingId = parseInt(entry && entry.recording_id, 10) || 0;
            return !recordingId || !idLookup[recordingId];
        });
        const removedCount = previousResults.length - results.length;
        if (removedCount <= 0) {
            return;
        }

        const shownCount = Math.max(0, (parseInt(currentSearchPayload.shown_count, 10) || previousResults.length) - removedCount);
        const totalMatches = Math.max(shownCount, (parseInt(currentSearchPayload.total_matches, 10) || previousResults.length) - removedCount);
        const pageStart = shownCount ? (parseInt(currentSearchPayload.page_start, 10) || 1) : 0;
        const pageEnd = shownCount ? pageStart + shownCount - 1 : 0;

        currentSearchPayload = $.extend({}, currentSearchPayload, {
            results: results,
            total_matches: totalMatches,
            shown_count: shownCount,
            has_more: !!currentSearchPayload.has_more && totalMatches > pageEnd,
            page_start: pageStart,
            page_end: pageEnd
        });
    }

    function syncSearchResultsAfterRowRemoval() {
        cleanupDetachedIpaKeyboard();
        const rowCount = $searchResults.find('tr[data-recording-id]').length;
        if (rowCount > 0) {
            updateSearchSummaryFromCurrentPayload();
            return;
        }

        if (currentSearchPayload) {
            currentSearchPayload = $.extend({}, currentSearchPayload, {
                results: [],
                shown_count: 0,
                has_more: false,
                page_start: 0,
                page_end: 0
            });
        }
        setSearchSummary('');
        $searchResults.empty().append($('<div>', {
            class: 'll-ipa-empty',
            text: t('searchResultsEmpty', 'No recordings matched this search.')
        }));
    }

    function updateSearchSummaryFromCurrentPayload() {
        if (!currentSearchPayload) {
            return;
        }

        const rowCount = $searchResults.find('tr[data-recording-id]').length;
        if (!rowCount) {
            setSearchSummary('');
            return;
        }

        setSearchSummary(buildSearchSummaryText(
            parseInt(currentSearchPayload.total_matches, 10) || rowCount,
            parseInt(currentSearchPayload.page_start, 10) || 1,
            parseInt(currentSearchPayload.page_end, 10) || rowCount,
            !!currentSearchPayload.issues_only,
            !!currentSearchPayload.review_only
        ));
        replaceSearchLazyControl(currentSearchPayload, false);
    }

    function removeSearchRowsByRecordingIds(recordingIds) {
        const ids = uniquePositiveIntegerList(recordingIds);
        let removed = false;
        ids.forEach(function (recordingId) {
            const $row = getSearchRowByRecordingId(recordingId);
            if ($row.length) {
                $row.remove();
                removed = true;
            }
        });

        if (removed) {
            removeCurrentSearchPayloadRecordings(ids);
            syncSearchResultsAfterRowRemoval();
        }

        return removed;
    }

    function setSearchWordEditorOpeningState($row, opening) {
        if (!$row || !$row.length) {
            return;
        }

        const isOpening = !!opening;
        $row.toggleClass('is-search-row-opening-editor', isOpening);
        $row.find('.ll-ipa-search-word-edit-toggle')
            .prop('disabled', isOpening)
            .attr('aria-disabled', isOpening ? 'true' : 'false');
        if (isOpening) {
            $row.attr('aria-busy', 'true');
        } else {
            $row.removeAttr('aria-busy');
            $row.find('.ll-ipa-search-word-edit-toggle').removeAttr('aria-disabled');
        }
    }

    function queuePendingSearchEditorOpen(recordingId, wordId, wordsetId) {
        const key = String(recordingId || '');
        const safeWordId = parseInt(wordId, 10) || 0;
        const safeWordsetId = parseInt(wordsetId, 10) || 0;
        if (!key || !safeWordId || !safeWordsetId) {
            return;
        }

        pendingSearchEditorOpen[key] = {
            recordingId: parseInt(recordingId, 10) || 0,
            wordId: safeWordId,
            wordsetId: safeWordsetId
        };
    }

    function clearPendingSearchEditorOpen(recordingId) {
        const key = String(recordingId || '');
        if (key && pendingSearchEditorOpen[key]) {
            delete pendingSearchEditorOpen[key];
        }
    }

    function flushPendingSearchEditorOpen(recordingId, $preferredRow) {
        const key = String(recordingId || '');
        const pending = key ? pendingSearchEditorOpen[key] : null;
        if (!pending) {
            return false;
        }

        let $row = ($preferredRow && $preferredRow.length && $preferredRow.closest('html').length)
            ? $preferredRow
            : getSearchRowByRecordingId(recordingId);
        if (!$row.length) {
            clearPendingSearchEditorOpen(recordingId);
            return false;
        }
        if ($row.data('llSearchRowSaving') || searchReviewStateIsSaving($row) || searchRowHasUnsavedChanges($row)) {
            return false;
        }

        clearPendingSearchEditorOpen(recordingId);
        openSearchWordEditor($row, { fromPendingSave: true });
        return true;
    }

    function openSearchWordEditor($row, options) {
        if (!$row || !$row.length || !currentCanEdit || !currentWordsetId) {
            return;
        }

        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;
        const wordId = parseInt($row.attr('data-word-id'), 10) || 0;
        if (!wordId || $row.hasClass('is-search-row-opening-editor')) {
            return;
        }

        if ($row.data('llSearchRowSaving') || searchReviewStateIsSaving($row) || searchRowHasUnsavedChanges($row)) {
            if (!(options && options.fromPendingSave)) {
                queuePendingSearchEditorOpen(recordingId, wordId, currentWordsetId);
            }
            if (searchRowHasUnsavedChanges($row)) {
                autosaveSearchRow($row, { preserveScroll: true });
            }
            setStatus(t('searchEditSavingBeforeOpen', 'Saving changes before opening the word editor...'), false);
            return;
        }

        const opener = window.LLToolsWordEditModal && window.LLToolsWordEditModal.open;
        if (typeof opener !== 'function') {
            const fallbackUrl = ($row.find('.ll-ipa-search-word-edit-toggle').first().attr('data-word-edit-link') || '').toString();
            if (fallbackUrl) {
                window.open(fallbackUrl, '_blank', 'noopener');
                return;
            }
            setStatus(t('searchWordEditorError', 'Unable to open the word editor.'), true);
            return;
        }

        setSearchWordEditorOpeningState($row, true);
        setStatus(t('searchOpeningWordEditor', 'Opening word editor...'), false);

        Promise.resolve(opener({
            wordId: wordId,
            wordsetId: currentWordsetId,
            recordingId: recordingId
        })).then(function () {
            setStatus(t('searchWordEditorOpened', 'Word editor opened.'), false);
        }).catch(function () {
            setStatus(t('searchWordEditorError', 'Unable to open the word editor.'), true);
        }).finally(function () {
            setSearchWordEditorOpeningState($row, false);
        });
    }

    function parseEditorEventDetail(detail) {
        return (detail && typeof detail === 'object') ? detail : {};
    }

    function editorEventWordsetId(detail) {
        const info = parseEditorEventDetail(detail);
        const data = info.data && typeof info.data === 'object' ? info.data : {};
        return parseInt(info.wordsetId || info.wordset_id || data.wordsetId || data.wordset_id, 10) || 0;
    }

    function editorEventMatchesVisibleSearchRows(detail) {
        const info = parseEditorEventDetail(detail);
        const recordingId = parseInt(info.recordingId || info.recording_id, 10) || 0;
        if (recordingId && getSearchRowByRecordingId(recordingId).length) {
            return true;
        }

        const wordIds = [
            parseInt(info.wordId || info.word_id, 10) || 0,
            parseInt(info.sourceWordId || info.source_word_id, 10) || 0,
            parseInt(info.targetWordId || info.target_word_id, 10) || 0
        ].filter(function (wordId, index, list) {
            return wordId > 0 && list.indexOf(wordId) === index;
        });

        return wordIds.some(function (wordId) {
            return $searchResults.find('tr[data-word-id="' + wordId + '"]').length > 0;
        });
    }

    function collectRecordingIdsFromEditorEvent(detail) {
        const info = parseEditorEventDetail(detail);
        const data = info.data && typeof info.data === 'object' ? info.data : {};
        const ids = [
            info.recordingId,
            info.recording_id,
            data.recordingId,
            data.recording_id
        ];

        if (data.recording && typeof data.recording === 'object') {
            ids.push(data.recording.recording_id, data.recording.id);
        }
        if (Array.isArray(data.recordings)) {
            data.recordings.forEach(function (recording) {
                if (recording && typeof recording === 'object') {
                    ids.push(recording.recording_id, recording.id);
                }
            });
        }

        return uniquePositiveIntegerList(ids);
    }

    function collectWordIdsFromEditorEvent(detail) {
        const info = parseEditorEventDetail(detail);
        const data = info.data && typeof info.data === 'object' ? info.data : {};
        return uniquePositiveIntegerList([
            info.wordId,
            info.word_id,
            data.wordId,
            data.word_id,
            info.sourceWordId,
            info.source_word_id,
            data.sourceWordId,
            data.source_word_id,
            info.targetWordId,
            info.target_word_id,
            data.targetWordId,
            data.target_word_id
        ]);
    }

    function collectVisibleRecordingIdsForEditorEvent(detail) {
        const ids = collectRecordingIdsFromEditorEvent(detail);
        collectWordIdsFromEditorEvent(detail).forEach(function (wordId) {
            $searchResults.find('tr[data-word-id="' + wordId + '"]').each(function () {
                ids.push(parseInt($(this).attr('data-recording-id'), 10) || 0);
            });
        });

        return uniquePositiveIntegerList(ids).filter(function (recordingId) {
            return getSearchRowByRecordingId(recordingId).length > 0;
        });
    }

    function removeSearchRowsForEditorEvent(detail) {
        return removeSearchRowsByRecordingIds(collectVisibleRecordingIdsForEditorEvent(detail));
    }

    function requestSearchRowsSync(recordingIds) {
        const ids = uniquePositiveIntegerList(recordingIds).filter(function (recordingId) {
            return getSearchRowByRecordingId(recordingId).length > 0;
        });
        if (!ids.length || !currentWordsetId) {
            return;
        }

        const scrollState = getWindowScrollState();
        $.post(ajaxUrl, {
            action: 'll_tools_get_ipa_keyboard_recordings',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_ids: ids
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            const data = response.data || {};
            if (data.transcription) {
                applyTranscriptionConfig(data.transcription);
            }
            if (typeof data.can_edit !== 'undefined') {
                setCanEdit(!!data.can_edit);
            }

            const returnedIds = {};
            const recordings = Array.isArray(data.recordings) ? data.recordings : [];
            recordings.forEach(function (rec) {
                const recordingId = parseInt(rec && rec.recording_id, 10) || 0;
                const $row = getSearchRowByRecordingId(recordingId);
                if (!recordingId || !$row.length) {
                    return;
                }
                returnedIds[recordingId] = true;
                if ($row.data('llSearchRowSaving') || searchReviewStateIsSaving($row) || searchRowHasUnsavedChanges($row)) {
                    return;
                }
                replaceSearchRowByRecordingId(recordingId, rec);
            });

            const missingSource = Array.isArray(data.missing_recording_ids) ? data.missing_recording_ids : [];
            const missingIds = uniquePositiveIntegerList(missingSource.concat(ids.filter(function (recordingId) {
                return !returnedIds[recordingId];
            })));
            if (missingIds.length) {
                removeSearchRowsByRecordingIds(missingIds);
            } else {
                updateSearchSummaryFromCurrentPayload();
                cleanupDetachedIpaKeyboard();
            }

            markTabsDirty(['map', 'symbols', 'orthography']);
            setStatus(t('searchRowsSynced', 'Transcription rows updated.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            restoreWindowScrollState(scrollState);
        });
    }

    function refreshSearchAfterWordEditorEvent(detail, eventType) {
        if (currentTab !== 'search' || !currentWordsetId) {
            return;
        }

        const wordsetId = editorEventWordsetId(detail);
        if (wordsetId && wordsetId !== currentWordsetId) {
            return;
        }
        if (!editorEventMatchesVisibleSearchRows(detail)) {
            return;
        }

        if (searchWordEditorRefreshTimer) {
            window.clearTimeout(searchWordEditorRefreshTimer);
        }
        searchWordEditorRefreshTimer = window.setTimeout(function () {
            searchWordEditorRefreshTimer = null;
            markTabsDirty(['map', 'symbols', 'search', 'orthography']);
            const type = (eventType || '').toString();
            if (type === 'lltools:word-grid-word-deleted' || type === 'lltools:word-grid-recording-deleted') {
                const scrollState = getWindowScrollState();
                if (removeSearchRowsForEditorEvent(detail)) {
                    setStatus(t('searchRowsSynced', 'Transcription rows updated.'), false);
                    restoreWindowScrollState(scrollState);
                }
                return;
            }

            requestSearchRowsSync(collectVisibleRecordingIdsForEditorEvent(detail));
        }, 120);
    }

    function getSearchRowValues($row) {
        const $textInput = $row.find('.ll-ipa-search-text-input').first();
        const $ipaInput = $row.find('.ll-ipa-search-ipa-input').first();
        return {
            recordingId: parseInt($row.attr('data-recording-id'), 10) || 0,
            recordingText: ($textInput.val() || '').toString(),
            recordingIpa: ($ipaInput.val() || '').toString(),
            savedText: ($textInput.attr('data-saved-value') || '').toString(),
            savedIpa: ($ipaInput.attr('data-saved-value') || '').toString()
        };
    }

    function searchRowHasUnsavedChanges($row) {
        const values = getSearchRowValues($row);
        return values.recordingText !== values.savedText || values.recordingIpa !== values.savedIpa;
    }

    function getWindowScrollState() {
        return {
            x: window.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft || 0,
            y: window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0
        };
    }

    function restoreWindowScrollState(scrollState) {
        if (!scrollState) {
            return;
        }

        window.requestAnimationFrame(function () {
            window.scrollTo(scrollState.x, scrollState.y);
        });
    }

    function setSearchRowDirtyState($row, dirty) {
        $row.toggleClass('is-search-row-dirty', !!dirty);
    }

    function getSearchRowLayoutLockTargets($row) {
        return [
            {
                key: 'recording_text',
                selector: '.ll-ipa-search-text-cell'
            },
            {
                key: 'recording_ipa',
                selector: '.ll-ipa-search-ipa-cell'
            },
            {
                key: 'issues',
                selector: '.ll-ipa-search-issues-wrap'
            }
        ].map(function (target) {
            return {
                key: target.key,
                $target: $row.find(target.selector).first()
            };
        });
    }

    function getSearchRowLayoutLocks($row) {
        const locks = $row && $row.length ? $row.data('llSearchRowLayoutLocks') : null;
        return locks && typeof locks === 'object' ? locks : {};
    }

    function applySearchRowLayoutLocks($row, locks) {
        if (!$row || !$row.length || !locks || typeof locks !== 'object') {
            return;
        }

        const appliedLocks = {};
        getSearchRowLayoutLockTargets($row).forEach(function (target) {
            const height = Math.max(0, Math.ceil(parseFloat(locks[target.key]) || 0));
            if (!target.$target.length || !height) {
                return;
            }

            target.$target
                .addClass('is-search-layout-locked')
                .css('min-height', height + 'px');
            appliedLocks[target.key] = height;
        });

        if (Object.keys(appliedLocks).length) {
            $row
                .data('llSearchRowLayoutLocks', appliedLocks)
                .addClass('is-search-row-layout-locked');
        }
    }

    function lockSearchRowLayout($row) {
        if (!$row || !$row.length) {
            return;
        }

        const locks = {};
        getSearchRowLayoutLockTargets($row).forEach(function (target) {
            const element = target.$target.get(0);
            if (!element) {
                return;
            }

            const rect = element.getBoundingClientRect();
            const height = Math.ceil(rect.height || element.offsetHeight || 0);
            if (height > 0) {
                locks[target.key] = height;
            }
        });
        applySearchRowLayoutLocks($row, locks);
    }

    function updateSearchRowSavedValues($row) {
        $row.find('.ll-ipa-search-text-input').first().attr('data-saved-value', ($row.find('.ll-ipa-search-text-input').first().val() || '').toString());
        $row.find('.ll-ipa-search-ipa-input').first().attr('data-saved-value', ($row.find('.ll-ipa-search-ipa-input').first().val() || '').toString());
        setSearchRowDirtyState($row, false);
    }

    function setSearchRowSaveState($row, state, label) {
        const $state = $row.find('.ll-ipa-search-save-state').first();
        if (!$state.length) {
            return;
        }

        $state.removeClass('is-idle is-saving is-saved is-error');
        $state.addClass('is-' + state);
        $state.find('.ll-ipa-search-save-label').text(label || '');
    }

    function getSearchRowInputForField($row, field) {
        return field === 'recording_text'
            ? $row.find('.ll-ipa-search-text-input').first()
            : $row.find('.ll-ipa-search-ipa-input').first();
    }

    function captureSearchRowFocusState($row, field) {
        const focusField = field === 'recording_text'
            ? 'recording_text'
            : (field === 'recording_ipa' ? 'recording_ipa' : '');
        if (!focusField) {
            return null;
        }

        const $input = getSearchRowInputForField($row, focusField);
        const input = $input.get(0);
        if (!input) {
            return null;
        }

        return {
            field: focusField,
            start: typeof input.selectionStart === 'number' ? input.selectionStart : null,
            end: typeof input.selectionEnd === 'number' ? input.selectionEnd : null
        };
    }

    function restoreSearchRowFocusState($row, focusState) {
        if (!focusState || !focusState.field) {
            return;
        }

        const $input = getSearchRowInputForField($row, focusState.field);
        const input = $input.get(0);
        if (!input || input.disabled) {
            return;
        }

        input.focus();
        if (typeof input.setSelectionRange === 'function'
            && typeof focusState.start === 'number'
            && typeof focusState.end === 'number') {
            input.setSelectionRange(focusState.start, focusState.end);
        }
        syncSearchInputHighlight(input);
    }

    function applyInlineSearchSuggestion($row, field, value) {
        if (!$row.length || !currentCanEdit) {
            return false;
        }

        const normalizedField = normalizeSearchReviewField(field);
        const nextValue = (value || '').toString();
        if (nextValue === '') {
            return false;
        }

        const $input = getSearchRowInputForField($row, normalizedField);
        const input = $input.get(0);
        if (!input) {
            return false;
        }

        lockSearchRowLayout($row);
        $input.val(nextValue);
        const $fieldBlock = $row.find(getSearchReviewFieldSelector(normalizedField)).first();
        $fieldBlock.find('.ll-ipa-search-field-suggestions').remove();

        const $wrap = $input.closest('.ll-ipa-search-field-wrap');
        $wrap.removeClass('ll-ipa-search-field-wrap--highlighted');
        $wrap.find('.ll-ipa-search-input-highlight').remove();
        syncSearchInputHighlight(input);

        setSearchRowDirtyState($row, searchRowHasUnsavedChanges($row));
        autosaveSearchRow($row, { preserveScroll: true });
        return true;
    }

    function getPendingSearchReviewStates(recordingId) {
        const key = String(recordingId || '');
        if (!key || !pendingSearchReviewState[key] || typeof pendingSearchReviewState[key] !== 'object') {
            return {};
        }
        return pendingSearchReviewState[key];
    }

    function hasPendingSearchReviewState(recordingId) {
        const states = getPendingSearchReviewStates(recordingId);
        return searchReviewFields.some(function (field) {
            return !!states[field];
        });
    }

    function queuePendingSearchReviewState(recordingId, needsReview, reviewField) {
        if (!recordingId) {
            return;
        }

        const key = String(recordingId);
        const field = normalizeSearchReviewField(reviewField);
        if (!pendingSearchReviewState[key] || typeof pendingSearchReviewState[key] !== 'object') {
            pendingSearchReviewState[key] = {};
        }
        pendingSearchReviewState[key][field] = {
            needsReview: !!needsReview,
            reviewField: field
        };
        setSearchReviewSavingStateByRecordingId(recordingId, field, true);
    }

    function clearPendingSearchReviewState(recordingId, reviewField) {
        if (!recordingId) {
            return;
        }

        const key = String(recordingId);
        const states = getPendingSearchReviewStates(recordingId);
        const fields = reviewField
            ? [normalizeSearchReviewField(reviewField)]
            : searchReviewFields.slice();
        fields.forEach(function (field) {
            if (states[field]) {
                setSearchReviewSavingStateByRecordingId(recordingId, field, false);
                delete states[field];
            }
        });
        if (!searchReviewFields.some(function (field) {
            return !!states[field];
        })) {
            delete pendingSearchReviewState[key];
        }
    }

    function flushPendingSearchReviewState(recordingId) {
        const key = String(recordingId || '');
        if (!key || !hasPendingSearchReviewState(recordingId)) {
            return;
        }

        const $row = getSearchRowByRecordingId(recordingId);
        if ($row.length && searchReviewStateIsSaving($row)) {
            return;
        }

        const states = getPendingSearchReviewStates(recordingId);
        const field = searchReviewFields.find(function (fieldKey) {
            return !!states[fieldKey];
        });
        if (!field) {
            delete pendingSearchReviewState[key];
            return;
        }

        const state = states[field] || {};
        delete states[field];
        if (!hasPendingSearchReviewState(recordingId)) {
            delete pendingSearchReviewState[key];
        }
        submitSearchReviewState(recordingId, !!state.needsReview, state.reviewField || field);
    }

    function submitSearchReviewState(recordingId, needsReview, reviewField) {
        if (!recordingId || !currentWordsetId || !currentCanEdit) {
            return $.Deferred().reject().promise();
        }
        const field = normalizeSearchReviewField(reviewField);
        let requestSucceeded = false;

        lockSearchRowLayout(getSearchRowByRecordingId(recordingId));
        setSearchReviewSavingStateByRecordingId(recordingId, field, true);
        setStatus(t('saving', 'Saving...'), false);
        return $.post(ajaxUrl, {
            action: 'll_tools_set_ipa_keyboard_transcription_review_state',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            review_field: field,
            needs_review: needsReview ? 1 : 0
        }).done(function (response) {
            if (!response || response.success !== true) {
                clearPendingSearchReviewState(recordingId);
                clearPendingSearchEditorOpen(recordingId);
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            requestSucceeded = true;
            const data = response.data || {};
            if (data.transcription) {
                applyTranscriptionConfig(data.transcription);
            }
            if (data.recording) {
                replaceSearchRowByRecordingId(recordingId, data.recording);
            }

            setStatus(
                needsReview
                    ? t('searchMarkedForReview', 'Marked for review.')
                    : t('searchReviewed', 'Reviewed.'),
                false
            );
        }).fail(function () {
            clearPendingSearchReviewState(recordingId);
            clearPendingSearchEditorOpen(recordingId);
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            setSearchReviewSavingStateByRecordingId(recordingId, field, false);
            if (requestSucceeded) {
                flushPendingSearchReviewState(recordingId);
                if (!hasPendingSearchReviewState(recordingId)) {
                    flushPendingSearchEditorOpen(recordingId);
                }
            }
        });
    }

    function autosaveSearchRow($row, options) {
        if (!$row.length || !$row.closest('html').length || !currentCanEdit) {
            return;
        }

        if ($row.data('llSearchRowSaving')) {
            $row.data('llSearchRowPending', true);
            return;
        }

        if (!searchRowHasUnsavedChanges($row)) {
            setSearchRowDirtyState($row, false);
            return;
        }

        const values = getSearchRowValues($row);
        if (!values.recordingId) {
            return;
        }

        const preserveScroll = !!(options && options.preserveScroll);
        const focusState = captureSearchRowFocusState($row, options && options.restoreFocusField);
        $row.data('llSearchRowSaving', true);
        $row.data('llSearchRowPending', false);
        setSearchRowSaveState($row, 'saving', t('saving', 'Saving...'));

        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_recording',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: values.recordingId,
            recording_text: values.recordingText,
            recording_ipa: values.recordingIpa
        }).done(function (response) {
            if (!response || response.success !== true) {
                clearPendingSearchReviewState(values.recordingId);
                clearPendingSearchEditorOpen(values.recordingId);
                setSearchRowSaveState($row, 'error', t('searchSaveFailed', 'Save failed'));
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            const data = response.data || {};
            const latestValues = getSearchRowValues($row);
            const rowChangedAfterSubmit = latestValues.recordingText !== values.recordingText
                || latestValues.recordingIpa !== values.recordingIpa;
            markTabsDirty(['map', 'symbols', 'orthography']);

            if (rowChangedAfterSubmit) {
                $row.data('llSearchRowPending', true);
                setSearchRowDirtyState($row, true);
                setSearchRowSaveState($row, 'saving', t('saving', 'Saving...'));
                return;
            }

            let $savedRow = $row;
            const scrollState = preserveScroll ? getWindowScrollState() : null;
            if (data.recording) {
                const $newRow = replaceSearchRow($row, data.recording);
                $savedRow = $newRow;
                setSearchRowSaveState($newRow, 'saved', t('saved', 'Saved.'));
                restoreSearchRowFocusState($newRow, focusState);
            } else {
                updateSearchRowSavedValues($row);
                updateSearchRowValidation($row, data.validation || null);
                setSearchRowSaveState($row, 'saved', t('saved', 'Saved.'));
                restoreSearchRowFocusState($row, focusState);
            }
            if (scrollState) {
                window.requestAnimationFrame(function () {
                    window.scrollTo(scrollState.x, scrollState.y);
                });
            }
            applyKeyboardSymbols(data.keyboard_symbols || currentKeyboardSymbols);

            if (hasPendingSearchReviewState(values.recordingId)) {
                flushPendingSearchReviewState(values.recordingId);
                return;
            }

            if (flushPendingSearchEditorOpen(values.recordingId, $savedRow)) {
                return;
            }

            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            clearPendingSearchReviewState(values.recordingId);
            clearPendingSearchEditorOpen(values.recordingId);
            setSearchRowSaveState($row, 'error', t('searchSaveFailed', 'Save failed'));
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $row.data('llSearchRowSaving', false);
            if ($row.closest('html').length && $row.data('llSearchRowPending')) {
                $row.data('llSearchRowPending', false);
                autosaveSearchRow($row);
            }
        });
    }

    function collectDisabledBuiltinRules() {
        const disabled = [];
        $searchRules.find('.ll-ipa-builtin-checkbox').each(function () {
            const $checkbox = $(this);
            if (!$checkbox.prop('checked')) {
                const ruleCode = ($checkbox.attr('data-rule-code') || '').toString();
                if (ruleCode) {
                    disabled.push(ruleCode);
                }
            }
        });
        return disabled;
    }

    $tabButtons.on('click', function () {
        const tab = ($(this).attr('data-ll-tab-trigger') || '').toString();
        setTab(tab);
    });

    $wordset.on('change', function () {
        const wordsetId = getSelectedWordsetId();
        selectWordset(wordsetId, { forceLoad: true });
    });

    $addBtn.on('click', function () {
        if (!currentWordsetId) {
            setStatus(t('selectWordset', 'Select a word set first.'), true);
            return;
        }
        const symbols = ($addInput.val() || '').toString();
        if (!symbols.trim()) {
            setStatus(t('enterSymbols', 'Enter one or more characters to add.'), true);
            return;
        }
        $addBtn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_add_wordset_ipa_symbols',
            nonce: nonce,
            wordset_id: currentWordsetId,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            $addInput.val('');
            setStatus(t('addSuccess', 'Characters added.'), false);
            markTabsDirty('symbols');
            loadSymbols(currentWordsetId, true);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $addBtn.prop('disabled', !currentCanEdit);
        });
    });

    $symbols.on('click', '.ll-ipa-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($btn.attr('data-recording-id'), 10) || 0;
        const ipaValue = ($row.find('.ll-ipa-input').first().val() || '').toString();
        const originalText = $btn.text();

        $btn.prop('disabled', true).text(t('saving', 'Saving...'));
        $.post(ajaxUrl, {
            action: 'll_tools_update_recording_ipa',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            recording_ipa: ipaValue
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            syncSavedRecording(response.data || {});
            markTabsDirty(['search', 'orthography']);
            if (response.data && response.data.letter_map_refresh_required) {
                scheduleLetterMapRefresh(currentWordsetId);
            }
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit).text(originalText);
        });
    });

    $admin.on('click', '.ll-study-recording-btn', function (event) {
        event.preventDefault();
        event.stopPropagation();
        playAudio(event.currentTarget);
    });

    $letterMap.on('click', '.ll-ipa-map-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const letter = ($btn.attr('data-letter') || $row.attr('data-letter') || '').toString();
        const symbols = ($row.find('.ll-ipa-map-input').first().val() || '').toString();
        const $clearBtn = $row.find('.ll-ipa-map-clear').first();

        $btn.prop('disabled', true);
        $clearBtn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
            $clearBtn.prop('disabled', !currentCanEdit);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-clear', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const letter = ($btn.attr('data-letter') || $row.attr('data-letter') || '').toString();
        const $saveBtn = $row.find('.ll-ipa-map-save').first();

        $btn.prop('disabled', true);
        $saveBtn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            clear: 1
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
            $saveBtn.prop('disabled', !currentCanEdit);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-add-btn', function () {
        const $wrap = $(this).closest('.ll-ipa-map-add');
        const letter = ($wrap.find('.ll-ipa-map-add-letter').first().val() || '').toString();
        const symbols = ($wrap.find('.ll-ipa-map-add-symbols').first().val() || '').toString();

        if (!letter.trim() || !symbols.trim()) {
            setStatus(getTranscription().map_add_missing || t('mapAddMissing', 'Enter letters and characters to add.'), true);
            return;
        }

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_wordset_ipa_letter_map',
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbols: symbols
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $letterMap.on('click', '.ll-ipa-map-block, .ll-ipa-map-unblock', function () {
        const $btn = $(this);
        const isUndo = $btn.hasClass('ll-ipa-map-unblock');
        const letter = ($btn.attr('data-letter') || '').toString();
        const symbol = ($btn.attr('data-symbol') || '').toString();
        const action = isUndo ? 'll_tools_unblock_wordset_ipa_letter_mapping' : 'll_tools_block_wordset_ipa_letter_mapping';

        $btn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: action,
            nonce: nonce,
            wordset_id: currentWordsetId,
            letter: letter,
            symbol: symbol
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty('map');
            loadLetterMap(currentWordsetId, true);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
        });
    });

    $searchBtn.on('click', function () {
        currentSearchPage = 1;
        markTabsDirty('search');
        loadSearch(currentWordsetId, true, { page: 1 });
    });

    function handleOrthographyRefreshResponse(response, successMessage) {
        if (!response || response.success !== true) {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
            return false;
        }

        handleWordsetResponse(response);
        renderOrthography(response.data || {});
        tabDirty.orthography = false;
        if (successMessage) {
            setStatus(successMessage, false);
        } else {
            setStatus('');
        }
        return true;
    }

    $orthographyRules.on('click', '.ll-ipa-orthography-add-btn', function () {
        const $wrap = $(this).closest('.ll-ipa-orthography-add');
        const segment = ($wrap.find('.ll-ipa-orthography-add-segment').first().val() || '').toString().trim();
        const context = ($wrap.find('.ll-ipa-orthography-add-context').first().val() || 'any').toString();
        const output = ($wrap.find('.ll-ipa-orthography-add-output').first().val() || '').toString().trim();

        if (!segment || !output) {
            setStatus(t('orthographyRuleAddMissing', 'Enter both an IPA segment and an orthography output.'), true);
            return;
        }

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: segment,
            context: context,
            output: output
        }).done(function (response) {
            if (handleOrthographyRefreshResponse(response, t('saved', 'Saved.'))) {
                $wrap.find('.ll-ipa-orthography-add-segment').first().val('');
                $wrap.find('.ll-ipa-orthography-add-output').first().val('');
            }
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyRules.on('click', '.ll-ipa-orthography-manual-save', function () {
        const $btn = $(this);
        const $row = $btn.closest('.ll-ipa-orthography-manual-row');
        const segment = ($btn.attr('data-segment') || '').toString();
        const context = ($btn.attr('data-context') || 'any').toString();
        const output = ($row.find('.ll-ipa-orthography-manual-input').first().val() || '').toString();

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: segment,
            context: context,
            output: output
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyRules.on('click', '.ll-ipa-orthography-manual-clear', function () {
        const $btn = $(this);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_update_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: ($btn.attr('data-segment') || '').toString(),
            context: ($btn.attr('data-context') || 'any').toString(),
            clear: 1
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyRules.on('click', '.ll-ipa-orthography-block, .ll-ipa-orthography-unblock', function () {
        const $btn = $(this);
        const isUnblock = $btn.hasClass('ll-ipa-orthography-unblock');
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: isUnblock ? 'll_tools_unblock_ipa_keyboard_orthography_rule' : 'll_tools_block_ipa_keyboard_orthography_rule',
            nonce: nonce,
            wordset_id: currentWordsetId,
            segment: ($btn.attr('data-segment') || '').toString(),
            context: ($btn.attr('data-context') || 'any').toString(),
            output: ($btn.attr('data-output') || '').toString()
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyIssues.on('click', '.ll-ipa-orthography-exception-toggle', function () {
        const $btn = $(this);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_toggle_ipa_keyboard_orthography_exception',
            nonce: nonce,
            wordset_id: currentWordsetId,
            word_id: ($btn.attr('data-word-id') || '').toString(),
            enabled: ($btn.attr('data-enabled') || '1') === '1' ? 1 : 0
        }).done(function (response) {
            handleOrthographyRefreshResponse(response, t('saved', 'Saved.'));
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    });

    $orthographyIssues.on('click', '.ll-ipa-orthography-suggestion-apply', function () {
        const $btn = $(this);
        const recordingId = parseInt($btn.attr('data-recording-id'), 10) || 0;
        if (!recordingId) {
            return;
        }

        $btn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_apply_ipa_keyboard_orthography_suggestion',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId
        }).done(function (response) {
            if (!handleOrthographyRefreshResponse(response, t('orthographyIssueSuggestionApplied', 'Suggestion saved.'))) {
                return;
            }
            const data = response.data || {};
            if (data.applied_suggestion && data.applied_suggestion.recording) {
                replaceSearchRowByRecordingId(recordingId, data.applied_suggestion.recording);
            }
            markTabsDirty(['map', 'symbols', 'search']);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
        });
    });

    $orthographyConvert.on('click', '.ll-ipa-orthography-select-all', function () {
        $orthographyConvert.find('.ll-ipa-orthography-select').prop('checked', true);
    });

    $orthographyConvert.on('click', '.ll-ipa-orthography-clear-selection', function () {
        $orthographyConvert.find('.ll-ipa-orthography-select').prop('checked', false);
    });

    function submitOrthographyConversion(wordIds) {
        const ids = Array.isArray(wordIds) ? wordIds : [];
        if (!ids.length) {
            setStatus(t('orthographyConvertNoSelection', 'Select at least one word to convert.'), true);
            return;
        }

        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_convert_ipa_keyboard_orthography_words',
            nonce: nonce,
            wordset_id: currentWordsetId,
            word_ids: ids
        }).done(function (response) {
            if (!handleOrthographyRefreshResponse(response, t('orthographyConvertSaved', 'Conversion saved.'))) {
                return;
            }
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        });
    }

    $orthographyConvert.on('click', '.ll-ipa-orthography-convert-selected', function () {
        const ids = $orthographyConvert.find('.ll-ipa-orthography-select:checked').map(function () {
            return parseInt($(this).val(), 10) || 0;
        }).get().filter(Boolean);
        submitOrthographyConversion(ids);
    });

    $orthographyConvert.on('click', '.ll-ipa-orthography-convert-one', function () {
        const wordId = parseInt($(this).attr('data-word-id'), 10) || 0;
        if (!wordId) {
            return;
        }
        submitOrthographyConversion([wordId]);
    });

    $searchQuery.on('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $searchBtn.trigger('click');
        }
    });

    $symbols.on('mousedown', '.ll-ipa-input', handleIpaFieldMouseDown);

    $symbols.on('focus', '.ll-ipa-input', handleIpaFieldFocus);

    $symbols.on('input', '.ll-ipa-input', function () {
        normalizeIpaInputElement(this);
    });

    $searchResults.on('mousedown', '.ll-ipa-search-ipa-input', handleIpaFieldMouseDown);

    $searchResults.on('focus', '.ll-ipa-search-ipa-input', handleIpaFieldFocus);

    $(document).on('mouseup.llIpaKeyboardAdmin', function () {
        const input = ipaKeyboardMouseInput;
        const shouldOpen = ipaKeyboardMouseSelecting && input && document.body.contains(input);
        ipaKeyboardMouseInput = null;
        ipaKeyboardMouseSelecting = false;
        if (!shouldOpen) {
            return;
        }
        scheduleIpaKeyboardForInput(input, 0);
    });

    $admin.on('mousedown', '.ll-ipa-inline-key, .ll-ipa-inline-keyboard-optional-toggle', function (event) {
        suppressSearchBlurSave = true;
        event.preventDefault();
    });

    $admin.on('click', '.ll-ipa-inline-keyboard-optional-toggle', function (event) {
        const input = activeIpaKeyboardInput;
        event.preventDefault();
        event.stopPropagation();
        ipaOptionalGroupsExpanded = !ipaOptionalGroupsExpanded;
        hideIpaSymbolContextMenu();
        fitIpaKeyboardToViewport();
        if (input && document.body.contains(input)) {
            input.focus();
        }
        suppressSearchBlurSave = false;
    });

    $admin.on('click', '.ll-ipa-inline-key', function (event) {
        const input = activeIpaKeyboardInput;
        const symbol = ($(this).attr('data-ipa-char') || '').toString();
        event.preventDefault();
        event.stopPropagation();
        hideIpaSymbolContextMenu();

        if (!input || !symbol) {
            suppressSearchBlurSave = false;
            return;
        }

        insertIpaKeyboardChar(input, symbol);
        input.focus();
        suppressSearchBlurSave = false;
    });

    $admin.on('contextmenu', '.ll-ipa-inline-key', function (event) {
        event.preventDefault();
        event.stopPropagation();
        showIpaSymbolContextMenu($(this), event);
    });

    $(document).on('click.llIpaKeyboardAdmin', '.ll-ipa-symbol-menu-action', function (event) {
        const symbol = ($(this).attr('data-ipa-char') || '').toString();
        event.preventDefault();
        event.stopPropagation();
        hideIpaSymbolContextMenu();
        flagIpaKeyboardSymbolIllegal(symbol);
    });

    $searchResults.on('click', '.ll-ipa-search-page-button', function () {
        const $btn = $(this);
        const targetPage = normalizeSearchPage($btn.attr('data-page') || 0);
        if ($btn.prop('disabled') || !targetPage || targetPage === currentSearchPage) {
            return;
        }

        markTabsDirty('search');
        loadSearch(currentWordsetId, true, { page: targetPage });
    });

    $searchResults.on('click', '.ll-ipa-search-load-more', function () {
        const $btn = $(this);
        const fallbackPage = normalizeSearchPage(currentSearchPayload && currentSearchPayload.current_page ? currentSearchPayload.current_page : currentSearchPage) + 1;
        const targetPage = normalizeSearchPage($btn.attr('data-page') || fallbackPage);
        if ($btn.prop('disabled') || !currentWordsetId || !targetPage) {
            return;
        }

        loadSearch(currentWordsetId, true, {
            page: targetPage,
            append: true,
            quietStatus: true,
            showLoading: false
        });
    });

    $searchResults.on('input', '.ll-ipa-search-text-input, .ll-ipa-search-ipa-input', function () {
        if ($(this).hasClass('ll-ipa-search-ipa-input')) {
            normalizeIpaInputElement(this);
        }
        syncSearchInputHighlight(this);

        const $row = $(this).closest('tr');
        if (!$row.length) {
            return;
        }

        const hasUnsavedChanges = searchRowHasUnsavedChanges($row);
        setSearchRowDirtyState($row, hasUnsavedChanges);
        if ($row.data('llSearchRowSaving')) {
            $row.data('llSearchRowPending', hasUnsavedChanges);
            return;
        }
        if (hasUnsavedChanges) {
            setSearchRowSaveState($row, 'idle', '');
        }
    });

    $searchResults.on('scroll', '.ll-ipa-search-text-input, .ll-ipa-search-ipa-input', function () {
        syncSearchInputHighlight(this);
    });

    $searchResults.on('focusout', '.ll-ipa-search-text-input, .ll-ipa-search-ipa-input', function () {
        const $row = $(this).closest('tr');
        if (!$row.length) {
            return;
        }

        if (suppressSearchBlurSave) {
            suppressSearchBlurSave = false;
            return;
        }

        window.setTimeout(function () {
            const activeElement = document.activeElement;
            const activeIsSearchInput = activeElement
                && $row.has(activeElement).length
                && $(activeElement).is('.ll-ipa-search-text-input, .ll-ipa-search-ipa-input');
            if (activeElement && $row.has(activeElement).length && !activeIsSearchInput) {
                return;
            }
            autosaveSearchRow($row, {
                restoreFocusField: activeIsSearchInput && $(activeElement).hasClass('ll-ipa-search-text-input')
                    ? 'recording_text'
                    : (activeIsSearchInput ? 'recording_ipa' : '')
            });
        }, 0);
    });

    $searchResults.on('click', '.ll-ipa-search-word-edit-toggle', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        if (!$row.length || $btn.prop('disabled')) {
            return;
        }

        openSearchWordEditor($row);
    });

    $searchResults.on('click', '[data-ll-search-word-review-note] [data-ll-internal-review-note-summary]', function () {
        const $wrap = $(this).closest('[data-ll-search-word-review-note]');
        window.setTimeout(function () {
            if ($wrap.prop('open')) {
                $wrap.find('[data-ll-internal-review-note-input]').first().trigger('focus');
            }
        }, 0);
    });

    $searchResults.on('input', '[data-ll-search-word-review-note] [data-ll-internal-review-note-input]', function () {
        const $wrap = $(this).closest('[data-ll-search-word-review-note]');
        updateSearchWordReviewNoteSummary($wrap, ($(this).val() || '').toString());
        scheduleSearchWordReviewNoteSave($wrap);
    });

    $searchResults.on('blur change', '[data-ll-search-word-review-note] [data-ll-internal-review-note-input]', function (event) {
        if (suppressSearchWordReviewNoteBlurSave && (event.type === 'blur' || event.type === 'focusout')) {
            return;
        }
        saveSearchWordReviewNote($(this).closest('[data-ll-search-word-review-note]'));
    });

    $(document).on(
        'lltools:word-grid-word-updated.llIpaKeyboardAdmin lltools:word-grid-word-deleted.llIpaKeyboardAdmin lltools:word-grid-recording-deleted.llIpaKeyboardAdmin lltools:word-grid-recording-moved.llIpaKeyboardAdmin',
        function (event, detail) {
            refreshSearchAfterWordEditorEvent(detail, event.type);
        }
    );

    $searchResults.on('click', '.ll-ipa-review-toggle', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;
        const nextNeedsReview = ($btn.attr('data-next-review-state') || '0') === '1';
        const reviewField = ($btn.attr('data-review-field') || 'recording_ipa').toString() === 'recording_text'
            ? 'recording_text'
            : 'recording_ipa';

        if (!recordingId || !currentWordsetId || searchReviewFieldIsSaving($row, reviewField)) {
            return;
        }

        if ($row.data('llSearchRowSaving')) {
            queuePendingSearchReviewState(recordingId, nextNeedsReview, reviewField);
            return;
        }

        if (searchRowHasUnsavedChanges($row)) {
            queuePendingSearchReviewState(recordingId, nextNeedsReview, reviewField);
            autosaveSearchRow($row);
            return;
        }

        if (searchReviewStateIsSaving($row)) {
            queuePendingSearchReviewState(recordingId, nextNeedsReview, reviewField);
            return;
        }

        submitSearchReviewState(recordingId, nextNeedsReview, reviewField);
    });

    $searchResults.on('click', '.ll-ipa-symbol-approval', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;
        const symbol = ($btn.attr('data-symbol') || '').toString();
        const output = ($btn.attr('data-output') || '').toString();

        if (!recordingId || !currentWordsetId || !symbol || !output) {
            return;
        }

        $btn.prop('disabled', true);
        setStatus(t('saving', 'Saving...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_approve_ipa_keyboard_symbol_mapping',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            symbol: symbol,
            output: output
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }

            markTabsDirty(['symbols', 'orthography']);
            loadSearch(currentWordsetId, true, {
                quietStatus: true,
                showLoading: false,
                successStatus: t('searchApprovedSymbolMapping', 'Approved symbol mapping.')
            });
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $searchResults.on('click', '.ll-ipa-search-suggestion-chip', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;
        const suggestionField = ($btn.attr('data-suggestion-field') || '').toString();
        const fallbackValue = $btn.hasClass('ll-ipa-search-ipa-suggestion-apply')
            ? ($btn.attr('data-ipa') || '').toString()
            : '';
        const suggestionValue = ($btn.attr('data-suggestion-value') || fallbackValue).toString();

        if (!recordingId || !currentWordsetId || !applyInlineSearchSuggestion($row, suggestionField, suggestionValue)) {
            return;
        }
    });

    $searchResults.on('click', '.ll-ipa-issue-toggle', function () {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const recordingId = parseInt($row.attr('data-recording-id'), 10) || 0;
        const ruleKey = ($btn.attr('data-rule-key') || '').toString();
        const enabled = ($btn.attr('data-enabled') || '1') === '1';

        $btn.prop('disabled', true);
        $.post(ajaxUrl, {
            action: 'll_tools_toggle_ipa_keyboard_validation_exception',
            nonce: nonce,
            wordset_id: currentWordsetId,
            recording_id: recordingId,
            rule_key: ruleKey,
            enabled: enabled ? 1 : 0
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            updateSearchRowValidation($row, response.data ? response.data.validation : null);
            setStatus(t('saved', 'Saved.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    $searchRules.on('click', '.ll-ipa-rule-add', function () {
        const $list = $searchRules.find('.ll-ipa-rule-list').first();
        const $row = $('<div>', { class: 'll-ipa-rule-row', 'data-rule-id': '' });
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-label',
            placeholder: t('searchRuleLabelPlaceholder', 'Optional note')
        }));
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-target',
            placeholder: t('searchRuleTargetPlaceholder', 'e.g. t')
        }));
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-previous',
            placeholder: t('searchRulePreviousPlaceholder', 'Previous sound(s)')
        }));
        $row.append($('<input>', {
            type: 'text',
            class: 'll-ipa-rule-input ll-ipa-rule-next',
            placeholder: t('searchRuleNextPlaceholder', 'Next sound(s)')
        }));
        $row.append($('<button>', {
            type: 'button',
            class: 'button button-secondary ll-ipa-rule-remove',
            text: t('searchRuleRemove', 'Remove')
        }));
        $list.append($row);
    });

    $searchRules.on('click', '.ll-ipa-rule-remove', function () {
        const $row = $(this).closest('.ll-ipa-rule-row');
        const $list = $searchRules.find('.ll-ipa-rule-list').first();
        $row.remove();
        if (!$list.children().length) {
            $searchRules.find('.ll-ipa-rule-add').trigger('click');
        }
    });

    $searchRules.on('click', '.ll-ipa-rule-save', function () {
        const customRules = collectCustomRules();
        const hasInvalidRule = $searchRules.find('.ll-ipa-rule-row').toArray().some(function (row) {
            const $row = $(row);
            const anyValue = ($row.find('.ll-ipa-rule-label').val() || '').toString().trim()
                || ($row.find('.ll-ipa-rule-previous').val() || '').toString().trim()
                || ($row.find('.ll-ipa-rule-next').val() || '').toString().trim();
            const target = ($row.find('.ll-ipa-rule-target').val() || '').toString().trim();
            return anyValue && !target;
        });

        if (hasInvalidRule) {
            setStatus(t('searchRuleMissingTarget', 'Enter a sound to check.'), true);
            return;
        }

        const disabledBuiltinRules = collectDisabledBuiltinRules();
        const $btn = $(this);
        $btn.prop('disabled', true);
        setStatus(t('searchRuleRescanning', 'Saving checks and rescanning this word set...'), false);
        $.post(ajaxUrl, {
            action: 'll_tools_save_ipa_keyboard_validation_config',
            nonce: nonce,
            wordset_id: currentWordsetId,
            disabled_builtin_rules: disabledBuiltinRules,
            custom_rules: customRules
        }).done(function (response) {
            if (!response || response.success !== true) {
                setStatus(t('error', 'Something went wrong. Please try again.'), true);
                return;
            }
            markTabsDirty(['map', 'symbols', 'search']);
            loadSearch(currentWordsetId, true);
            setStatus(t('searchRuleSaved', 'Checks saved and rescanned.'), false);
        }).fail(function () {
            setStatus(t('error', 'Something went wrong. Please try again.'), true);
        }).always(function () {
            $btn.prop('disabled', !currentCanEdit);
        });
    });

    $searchRules.on('toggle', '.ll-ipa-rules-disclosure', function () {
        searchRulesExpanded = !!this.open;
    });

    $(document).on('mousedown.llIpaKeyboardAdmin', function (event) {
        if (!activeIpaKeyboardInput) {
            return;
        }

        if ($(event.target).closest('[data-ll-ipa-inline-keyboard-wrap], .ll-ipa-symbol-menu, .ll-ipa-search-ipa-input, .ll-ipa-input').length) {
            return;
        }

        hideIpaSymbolContextMenu();
        hideIpaKeyboard();
    });

    $(document).on('keydown.llIpaKeyboardAdmin', function (event) {
        if (!activeIpaKeyboardInput || !event || event.key !== 'Escape') {
            return;
        }

        hideIpaSymbolContextMenu();
        hideIpaKeyboard();
    });

    function handleIpaViewportChange() {
        window.setTimeout(fitIpaKeyboardToViewport, 0);
    }

    $(window).on('resize.llIpaKeyboardAdmin', handleIpaViewportChange);

    if (window.visualViewport && typeof window.visualViewport.addEventListener === 'function') {
        window.visualViewport.addEventListener('resize', handleIpaViewportChange);
        window.visualViewport.addEventListener('scroll', handleIpaViewportChange);
    }

    function init() {
        if (!$wordset.length || !$wordset.find('option').length) {
            setStatus(t('noWordsets', 'No word sets are available for this page.'), true);
            return;
        }

        const requestedTab = getRequestedTabFromUrl();
        const storedTab = getStoredTab();
        currentTab = requestedTab || storedTab || normalizeTabName(cfg.initialTab || $admin.attr('data-ll-initial-tab') || 'map');

        const serverWordsetId = parseInt(cfg.selectedWordsetId, 10) || getSelectedWordsetId();
        const storedWordsetId = getStoredWordset();
        const initialWordsetId = serverWordsetId || storedWordsetId;
        const initialSearch = cfg.initialSearch || {};

        if (typeof initialSearch.query === 'string') {
            $searchQuery.val(initialSearch.query);
        }
        if (typeof initialSearch.scope === 'string' && initialSearch.scope) {
            $searchScope.val(initialSearch.scope);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'issues_only')) {
            $searchIssuesOnly.prop('checked', !!initialSearch.issues_only);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'review_only')) {
            $searchReviewOnly.prop('checked', !!initialSearch.review_only);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'exact_transcription')) {
            $searchExactTranscription.prop('checked', !!initialSearch.exact_transcription);
        }
        if (Object.prototype.hasOwnProperty.call(initialSearch, 'page')) {
            currentSearchPage = normalizeSearchPage(initialSearch.page);
        }

        if (initialWordsetId) {
            $wordset.val(String(initialWordsetId));
        }
        currentWordsetId = getSelectedWordsetId();
        rememberWordset(currentWordsetId);
        setTab(currentTab);
    }

    init();
})(jQuery);
