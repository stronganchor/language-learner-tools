(function ($) {
    'use strict';

    function getData() {
        return window.llContentLessonAdminData && typeof window.llContentLessonAdminData === 'object'
            ? window.llContentLessonAdminData
            : {};
    }

    function getMap(name) {
        var data = getData();
        return data[name] && typeof data[name] === 'object' ? data[name] : {};
    }

    function getPageState(kind, wordsetId) {
        var stateByKind = getMap('pageStateByKind');
        var kindState = stateByKind[kind] && typeof stateByKind[kind] === 'object' ? stateByKind[kind] : {};
        var state = kindState[String(wordsetId || '0')];
        return state && typeof state === 'object' ? state : null;
    }

    function setPageState(kind, wordsetId, state) {
        var data = getData();
        var key = String(wordsetId || '0');
        data.pageStateByKind = data.pageStateByKind && typeof data.pageStateByKind === 'object'
            ? data.pageStateByKind
            : {};
        data.pageStateByKind[kind] = data.pageStateByKind[kind] && typeof data.pageStateByKind[kind] === 'object'
            ? data.pageStateByKind[kind]
            : {};
        data.pageStateByKind[kind][key] = state;
    }

    function getRowsForWordset(wordsetId, mapName) {
        var rowsByWordset = getMap(mapName);
        var key = String(wordsetId || '0');
        return Object.prototype.hasOwnProperty.call(rowsByWordset, key) && Array.isArray(rowsByWordset[key])
            ? rowsByWordset[key]
            : null;
    }

    function setRowsForWordset(wordsetId, mapName, rows) {
        var data = getData();
        var key = String(wordsetId || '0');
        data[mapName] = data[mapName] && typeof data[mapName] === 'object' ? data[mapName] : {};
        data[mapName][key] = Array.isArray(rows) ? rows : [];
    }

    function getSelectedState($select, preserveSourceIds) {
        var selectedIds = {};
        var selectedSourceIds = {};
        var selectedRows = [];

        $select.find('option:selected').each(function () {
            var value = String(this.value || '');
            var sourceId = String(this.getAttribute('data-ll-category-source-id') || value);
            if (value === '') {
                return;
            }
            selectedIds[value] = true;
            if (preserveSourceIds && sourceId !== '') {
                selectedSourceIds[sourceId] = true;
            }
            selectedRows.push({
                id: value,
                label: String(this.textContent || ''),
                source_id: sourceId
            });
        });

        return {
            ids: selectedIds,
            sourceIds: selectedSourceIds,
            rows: selectedRows
        };
    }

    function mergeRows(rows, selectedRows) {
        var merged = {};
        var ordered = [];

        (selectedRows || []).concat(rows || []).forEach(function (row) {
            var id = String((row && row.id) || '');
            if (id === '' || merged[id]) {
                return;
            }
            merged[id] = true;
            ordered.push(row);
        });
        return ordered;
    }

    function mergeRowsWithSelections(rows, selectedRows, preserveSourceIds) {
        var loadedIds = {};
        var loadedSourceIds = {};
        var retainedSelections = [];

        (rows || []).forEach(function (row) {
            var id = String((row && row.id) || '');
            var sourceId = String((row && (row.source_id || row.id)) || '');
            if (id !== '') {
                loadedIds[id] = true;
            }
            if (preserveSourceIds && sourceId !== '') {
                loadedSourceIds[sourceId] = true;
            }
        });
        (selectedRows || []).forEach(function (row) {
            var id = String((row && row.id) || '');
            var sourceId = String((row && (row.source_id || row.id)) || '');
            if (id === '' || loadedIds[id] || (preserveSourceIds && sourceId !== '' && loadedSourceIds[sourceId])) {
                return;
            }
            retainedSelections.push(row);
        });

        return mergeRows(rows, retainedSelections);
    }

    function replaceOptions($select, rows, selectedState, preserveSourceIds) {
        var fragment = document.createDocumentFragment();

        mergeRowsWithSelections(rows, selectedState.rows, preserveSourceIds).forEach(function (row) {
            var id = String((row && row.id) || '');
            var label = String((row && row.label) || '');
            var sourceId = String((row && (row.source_id || row.id)) || '');
            var option;
            if (id === '' || label === '') {
                return;
            }

            option = document.createElement('option');
            option.value = id;
            option.textContent = label;
            if (preserveSourceIds) {
                option.setAttribute('data-ll-category-source-id', sourceId);
            }
            if (selectedState.ids[id] || (preserveSourceIds && sourceId !== '' && selectedState.sourceIds[sourceId])) {
                option.selected = true;
            }
            fragment.appendChild(option);
        });

        $select.empty().append(fragment);
    }

    function getString(name, fallback) {
        var strings = getMap('strings');
        return String(strings[name] || fallback);
    }

    $(function () {
        var data = getData();
        var $wordset = $('#ll-content-lesson-wordset');
        var $lessonKind = $('#ll-content-lesson-kind');
        var $mediaSettings = $('[data-ll-content-lesson-media-setting]');
        var configs = [
            { kind: 'categories', map: 'rowsByWordset', select: $('#ll-content-lesson-categories'), preserveSourceIds: true },
            { kind: 'prereq_categories', map: 'prereqRowsByWordset', select: $('#ll-content-lesson-prereq-categories'), preserveSourceIds: true },
            { kind: 'prereq_lessons', map: 'prereqLessonRowsByWordset', select: $('#ll-content-lesson-prereq-lessons'), preserveSourceIds: false }
        ];
        var requestSequence = 0;
        var currentLessonId = String(data.currentLessonId || '0');

        function updateLessonKindSettings() {
            var isArticle = String($lessonKind.val() || 'standard') === 'article';
            $mediaSettings
                .toggle(!isArticle)
                .attr('aria-hidden', isArticle ? 'true' : 'false')
                .find('input, select, textarea')
                .prop('disabled', isArticle);
        }

        if ($lessonKind.length > 0) {
            $lessonKind.on('change', updateLessonKindSettings);
            updateLessonKindSettings();
        }

        if ($wordset.length < 1 || configs[0].select.length < 1) {
            return;
        }

        function updateControls(config, state, loading, errorMessage) {
            config.controls.status.text(errorMessage || (loading ? getString('loading', 'Loading...') : ''));
            config.controls.search.prop('disabled', loading);
            config.controls.searchButton.prop('disabled', loading);
            config.controls.more
                .prop('disabled', loading)
                .toggle(!loading && !!(state && state.has_more));
        }

        function loadOptions(config, options) {
            var wordsetId = String(options.wordsetId || '0');
            var selectedState = getSelectedState(config.select, config.preserveSourceIds);
            var sequence = ++requestSequence;
            var offset = options.append ? Number((getPageState(config.kind, wordsetId) || {}).next_offset || 0) : 0;

            config.requestSequence = sequence;
            updateControls(config, getPageState(config.kind, wordsetId), true, '');
            return $.ajax({
                url: String(data.ajaxUrl || window.ajaxurl || ''),
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_tools_content_lesson_options',
                    nonce: String(data.nonce || ''),
                    kind: config.kind,
                    wordset_id: wordsetId,
                    search: String(options.search || ''),
                    offset: offset,
                    selected_ids: Object.keys(selectedState.ids),
                    exclude_lesson_id: currentLessonId
                }
            }).done(function (response) {
                var page;
                var rows;
                if (config.requestSequence !== sequence || String($wordset.val() || '0') !== wordsetId) {
                    return;
                }
                if (!response || response.success !== true || !response.data) {
                    updateControls(config, null, false, getString('loadFailed', 'Options could not be loaded.'));
                    return;
                }

                page = response.data;
                rows = Array.isArray(page.rows) ? page.rows : [];
                if (options.append) {
                    rows = mergeRows(rows, getRowsForWordset(wordsetId, config.map) || []);
                }
                setRowsForWordset(wordsetId, config.map, rows);
                setPageState(config.kind, wordsetId, page);
                replaceOptions(config.select, rows, selectedState, config.preserveSourceIds);
                updateControls(config, page, false, '');
            }).fail(function () {
                if (config.requestSequence === sequence) {
                    updateControls(config, getPageState(config.kind, wordsetId), false, getString('loadFailed', 'Options could not be loaded.'));
                }
            });
        }

        configs.forEach(function (config) {
            var $controls;
            if (config.select.length < 1) {
                return;
            }
            $controls = $('<div class="ll-content-lesson-option-controls"></div>');
            config.controls = {
                root: $controls,
                search: $('<input type="search" class="regular-text" />').attr('aria-label', getString('search', 'Search')),
                searchButton: $('<button type="button" class="button"></button>').text(getString('search', 'Search')),
                more: $('<button type="button" class="button"></button>').text(getString('loadMore', 'Load more')),
                status: $('<span class="description" aria-live="polite"></span>')
            };
            $controls.append(config.controls.search, config.controls.searchButton, config.controls.more, config.controls.status);
            config.select.after($controls);
            updateControls(config, getPageState(config.kind, String($wordset.val() || '0')), false, '');

            config.controls.searchButton.on('click', function () {
                loadOptions(config, {
                    wordsetId: String($wordset.val() || '0'),
                    search: config.controls.search.val(),
                    append: false
                });
            });
            config.controls.search.on('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    config.controls.searchButton.trigger('click');
                }
            });
            config.controls.more.on('click', function () {
                loadOptions(config, {
                    wordsetId: String($wordset.val() || '0'),
                    search: config.controls.search.val(),
                    append: true
                });
            });
        });

        $wordset.on('change', function () {
            var wordsetId = String($wordset.val() || '0');
            configs.forEach(function (config) {
                var selectedState;
                var cachedRows;
                if (config.select.length < 1) {
                    return;
                }
                selectedState = getSelectedState(config.select, config.preserveSourceIds);
                cachedRows = getRowsForWordset(wordsetId, config.map);
                config.controls.search.val('');
                if (cachedRows !== null) {
                    replaceOptions(config.select, cachedRows, selectedState, config.preserveSourceIds);
                    updateControls(config, getPageState(config.kind, wordsetId), false, '');
                    return;
                }
                loadOptions(config, { wordsetId: wordsetId, search: '', append: false });
            });
        });
    });
})(jQuery);
