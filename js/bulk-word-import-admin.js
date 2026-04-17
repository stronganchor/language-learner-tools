(function () {
    'use strict';

    function getData() {
        if (window.llBulkWordImportData && typeof window.llBulkWordImportData === 'object') {
            return window.llBulkWordImportData;
        }

        return {
            categoryRowsByWordset: {},
            uncategorizedLabel: 'Leave uncategorized'
        };
    }

    function getRowsForWordset(data, wordsetId) {
        var map = data.categoryRowsByWordset || {};
        var key = String(wordsetId || '0');

        if (Object.prototype.hasOwnProperty.call(map, key) && Array.isArray(map[key])) {
            return map[key];
        }

        if (Object.prototype.hasOwnProperty.call(map, '0') && Array.isArray(map['0'])) {
            return map['0'];
        }

        return [];
    }

    function rebuildCategoryOptions(categorySelect, rows, selectedValue, defaultLabel) {
        var safeRows = Array.isArray(rows) ? rows : [];
        var targetValue = String(selectedValue || '0');

        categorySelect.innerHTML = '';

        var defaultOption = document.createElement('option');
        defaultOption.value = '0';
        defaultOption.textContent = String(defaultLabel || 'Leave uncategorized');
        categorySelect.appendChild(defaultOption);

        safeRows.forEach(function (row) {
            if (!row || typeof row !== 'object') {
                return;
            }

            var id = parseInt(row.id, 10);
            if (!Number.isInteger(id) || id <= 0) {
                return;
            }

            var option = document.createElement('option');
            option.value = String(id);
            option.textContent = String(row.label || '');
            categorySelect.appendChild(option);
        });

        categorySelect.value = targetValue;
        if (categorySelect.value !== targetValue) {
            categorySelect.value = '0';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var wordsetSelect = document.querySelector('#ll-bulk-word-import-wordset');
        var categorySelect = document.querySelector('#ll-bulk-word-import-category');

        if (!wordsetSelect || !categorySelect) {
            return;
        }

        var data = getData();

        function syncCategorySelect() {
            var selectedCategoryValue = categorySelect.value || '0';
            var rows = getRowsForWordset(data, wordsetSelect.value || '0');

            rebuildCategoryOptions(
                categorySelect,
                rows,
                selectedCategoryValue,
                data.uncategorizedLabel
            );
        }

        wordsetSelect.addEventListener('change', syncCategorySelect);
        syncCategorySelect();
    });
})();
