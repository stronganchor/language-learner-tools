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

    function getRowsForWordset(wordsetId) {
        var rowsByWordset = getRowsByWordset();
        var key = String(wordsetId || '0');

        if (Object.prototype.hasOwnProperty.call(rowsByWordset, key) && Array.isArray(rowsByWordset[key])) {
            return rowsByWordset[key];
        }

        if (Object.prototype.hasOwnProperty.call(rowsByWordset, '0') && Array.isArray(rowsByWordset['0'])) {
            return rowsByWordset['0'];
        }

        return [];
    }

    function getSelectedState($select) {
        var selectedIds = {};
        var selectedSourceIds = {};

        $select.find('option:selected').each(function () {
            var option = this;
            var value = String(option.value || '');
            var sourceId = String(option.getAttribute('data-ll-category-source-id') || '');

            if (value !== '') {
                selectedIds[value] = true;
            }
            if (sourceId !== '') {
                selectedSourceIds[sourceId] = true;
            }
        });

        return {
            ids: selectedIds,
            sourceIds: selectedSourceIds
        };
    }

    function replaceOptions($select, rows, selectedState) {
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
            option.setAttribute('data-ll-category-source-id', sourceId);

            if (selectedState.ids[id] || (sourceId !== '' && selectedState.sourceIds[sourceId])) {
                option.selected = true;
            }

            fragment.appendChild(option);
        });

        $select.empty().append(fragment);
    }

    $(function () {
        var $wordset = $('#ll-content-lesson-wordset');
        var $categories = $('#ll-content-lesson-categories');
        var currentWordsetKey;

        if ($wordset.length < 1 || $categories.length < 1) {
            return;
        }

        currentWordsetKey = String($wordset.val() || '0');

        $wordset.on('change', function () {
            var selectedState = getSelectedState($categories);
            var nextWordsetKey = String($wordset.val() || '0');
            var rows = getRowsForWordset(nextWordsetKey || currentWordsetKey);

            replaceOptions($categories, rows, selectedState);
            currentWordsetKey = nextWordsetKey;
        });
    });
})(jQuery);
