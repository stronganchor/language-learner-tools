(function ($) {
    'use strict';

    function getRowsByWordset() {
        if (!window.llContentLessonAdminData || typeof window.llContentLessonAdminData !== 'object') {
            return {};
        }

        if (!window.llContentLessonAdminData.rowsByWordset || typeof window.llContentLessonAdminData.rowsByWordset !== 'object') {
            return {};
        }

        return window.llContentLessonAdminData.rowsByWordset;
    }

    function getPrereqRowsByWordset() {
        if (!window.llContentLessonAdminData || typeof window.llContentLessonAdminData !== 'object') {
            return {};
        }

        if (!window.llContentLessonAdminData.prereqRowsByWordset || typeof window.llContentLessonAdminData.prereqRowsByWordset !== 'object') {
            return {};
        }

        return window.llContentLessonAdminData.prereqRowsByWordset;
    }

    function getPrereqLessonRowsByWordset() {
        if (!window.llContentLessonAdminData || typeof window.llContentLessonAdminData !== 'object') {
            return {};
        }

        if (!window.llContentLessonAdminData.prereqLessonRowsByWordset || typeof window.llContentLessonAdminData.prereqLessonRowsByWordset !== 'object') {
            return {};
        }

        return window.llContentLessonAdminData.prereqLessonRowsByWordset;
    }

    function getCurrentLessonId() {
        if (!window.llContentLessonAdminData || typeof window.llContentLessonAdminData !== 'object') {
            return '0';
        }

        return String(window.llContentLessonAdminData.currentLessonId || '0');
    }

    function getRowsForWordset(wordsetId, rowsByWordset) {
        var key = String(wordsetId || '0');

        if (Object.prototype.hasOwnProperty.call(rowsByWordset, key) && Array.isArray(rowsByWordset[key])) {
            return rowsByWordset[key];
        }

        if (Object.prototype.hasOwnProperty.call(rowsByWordset, '0') && Array.isArray(rowsByWordset['0'])) {
            return rowsByWordset['0'];
        }

        return [];
    }

    function getSelectedState($select, preserveSourceIds) {
        var selectedIds = {};
        var selectedSourceIds = {};

        $select.find('option:selected').each(function () {
            var option = this;
            var value = String(option.value || '');
            var sourceId = String(option.getAttribute('data-ll-category-source-id') || '');

            if (value !== '') {
                selectedIds[value] = true;
            }
            if (preserveSourceIds && sourceId !== '') {
                selectedSourceIds[sourceId] = true;
            }
        });

        return {
            ids: selectedIds,
            sourceIds: selectedSourceIds
        };
    }

    function filterRowsByExcludedId(rows, excludedId) {
        return rows.filter(function (row) {
            return String((row && row.id) || '') !== excludedId;
        });
    }

    function replaceOptions($select, rows, selectedState, preserveSourceIds) {
        var fragment = document.createDocumentFragment();

        rows.forEach(function (row) {
            if (!row || typeof row !== 'object') {
                return;
            }

            var id = String(row.id || '');
            var label = String(row.label || '');
            var sourceId = String(row.source_id || row.id || '');
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

    $(function () {
        var $wordset = $('#ll-content-lesson-wordset');
        var $categories = $('#ll-content-lesson-categories');
        var $prereqs = $('#ll-content-lesson-prereq-categories');
        var $prereqLessons = $('#ll-content-lesson-prereq-lessons');
        var currentWordsetKey;
        var currentLessonId = getCurrentLessonId();

        if ($wordset.length < 1 || $categories.length < 1) {
            return;
        }

        currentWordsetKey = String($wordset.val() || '0');

        $wordset.on('change', function () {
            var nextWordsetKey = String($wordset.val() || '0');
            var categorySelectedState = getSelectedState($categories, true);
            var categoryRows = getRowsForWordset(nextWordsetKey || currentWordsetKey, getRowsByWordset());
            var prereqSelectedState;
            var prereqRows;
            var prereqLessonSelectedState;
            var prereqLessonRows;

            replaceOptions($categories, categoryRows, categorySelectedState, true);

            if ($prereqs.length) {
                prereqSelectedState = getSelectedState($prereqs, false);
                prereqRows = getRowsForWordset(nextWordsetKey || currentWordsetKey, getPrereqRowsByWordset());
                replaceOptions($prereqs, prereqRows, prereqSelectedState, false);
            }

            if ($prereqLessons.length) {
                prereqLessonSelectedState = getSelectedState($prereqLessons, false);
                prereqLessonRows = getRowsForWordset(nextWordsetKey || currentWordsetKey, getPrereqLessonRowsByWordset());
                prereqLessonRows = filterRowsByExcludedId(prereqLessonRows, currentLessonId);
                replaceOptions($prereqLessons, prereqLessonRows, prereqLessonSelectedState, false);
            }

            currentWordsetKey = nextWordsetKey;
        });
    });
})(jQuery);
