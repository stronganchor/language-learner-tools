(function () {
    'use strict';

    var audio = new Audio();
    audio.preload = 'none';
    var currentButton = null;

    function initGroupManager() {
        var groupList = document.querySelector('[data-ll-group-list]');
        var addBtn = document.querySelector('[data-group-add]');
        var table = document.querySelector('[data-ll-group-table]');
        if (!groupList || !addBtn || !table) {
            return;
        }

        var nextIndex = parseInt(groupList.getAttribute('data-next-index') || '0', 10);
        if (isNaN(nextIndex) || nextIndex < 0) {
            nextIndex = 0;
        }

        function getWordRows() {
            return Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-word-id]'));
        }

        function getHeaderRow() {
            return table.querySelector('thead tr');
        }

        function updateHeaderLabel(groupId, label) {
            var header = table.querySelector('[data-group-header="' + groupId + '"]');
            if (header) {
                header.textContent = label || '';
            }
        }

        function updateCheckboxLabels(groupId, label) {
            var cells = table.querySelectorAll('td[data-group-id="' + groupId + '"] input[type="checkbox"]');
            var ariaLabel = label ? 'Assign to group ' + label : 'Assign to group';
            cells.forEach(function (checkbox) {
                checkbox.setAttribute('aria-label', ariaLabel);
            });
        }

        function addGroupColumn(groupId, label) {
            var headerRow = getHeaderRow();
            if (!headerRow) {
                return;
            }
            var th = document.createElement('th');
            th.setAttribute('scope', 'col');
            th.setAttribute('data-group-id', groupId);
            var span = document.createElement('span');
            span.className = 'll-tools-word-options-group-header';
            span.setAttribute('data-group-header', groupId);
            span.textContent = label || '';
            th.appendChild(span);
            headerRow.appendChild(th);

            getWordRows().forEach(function (row) {
                var wordId = row.getAttribute('data-word-id') || '';
                var td = document.createElement('td');
                td.className = 'll-tools-word-options-group-cell';
                td.setAttribute('data-group-id', groupId);
                var labelEl = document.createElement('label');
                labelEl.className = 'll-tools-word-options-group-check';
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'group_members[' + groupId + '][]';
                checkbox.value = wordId;
                checkbox.setAttribute('aria-label', label ? 'Assign to group ' + label : 'Assign to group');
                labelEl.appendChild(checkbox);
                td.appendChild(labelEl);
                row.appendChild(td);
            });
        }

        function removeGroupColumn(groupId) {
            var header = table.querySelector('th[data-group-id="' + groupId + '"]');
            if (header) {
                header.remove();
            }
            var cells = table.querySelectorAll('td[data-group-id="' + groupId + '"]');
            cells.forEach(function (cell) {
                cell.remove();
            });
        }

        function addGroupRow(label) {
            var groupId = 'g' + nextIndex;
            nextIndex += 1;
            groupList.setAttribute('data-next-index', String(nextIndex));

            var row = document.createElement('div');
            row.className = 'll-tools-word-options-group-row';
            row.setAttribute('data-group-id', groupId);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'll-tools-word-options-group-input';
            input.name = 'group_names[' + groupId + ']';
            input.value = label || '';
            input.setAttribute('data-group-name-input', '');

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'button button-secondary ll-tools-button ll-tools-word-options-remove-group';
            removeBtn.setAttribute('data-group-remove', '');
            removeBtn.textContent = 'Remove';

            row.appendChild(input);
            row.appendChild(removeBtn);
            groupList.appendChild(row);

            addGroupColumn(groupId, label || '');
            input.addEventListener('input', function () {
                updateHeaderLabel(groupId, input.value);
                updateCheckboxLabels(groupId, input.value);
            });
        }

        addBtn.addEventListener('click', function (event) {
            event.preventDefault();
            addGroupRow('');
        });

        groupList.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-group-remove]');
            if (!btn) {
                return;
            }
            var row = btn.closest('[data-group-id]');
            if (!row) {
                return;
            }
            var groupId = row.getAttribute('data-group-id');
            row.remove();
            removeGroupColumn(groupId);
        });

        groupList.querySelectorAll('[data-group-name-input]').forEach(function (input) {
            var row = input.closest('[data-group-id]');
            if (!row) {
                return;
            }
            var groupId = row.getAttribute('data-group-id');
            updateHeaderLabel(groupId, input.value);
            updateCheckboxLabels(groupId, input.value);
            input.addEventListener('input', function () {
                updateHeaderLabel(groupId, input.value);
                updateCheckboxLabels(groupId, input.value);
            });
        });
    }

    function stopAudio() {
        if (currentButton) {
            currentButton.classList.remove('is-playing');
        }
        currentButton = null;
        audio.pause();
        audio.currentTime = 0;
    }

    audio.addEventListener('ended', stopAudio);
    audio.addEventListener('pause', function () {
        if (currentButton) {
            currentButton.classList.remove('is-playing');
        }
    });

    initGroupManager();

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.ll-study-recording-btn');
        if (!btn) {
            return;
        }
        event.preventDefault();
        if (btn.disabled) {
            return;
        }
        var url = btn.getAttribute('data-audio-url') || '';
        if (!url) {
            return;
        }
        if (currentButton && currentButton !== btn) {
            currentButton.classList.remove('is-playing');
        }
        if (currentButton === btn && !audio.paused) {
            stopAudio();
            return;
        }
        currentButton = btn;
        audio.src = url;
        audio.play().then(function () {
            btn.classList.add('is-playing');
        }).catch(function () {
            btn.classList.remove('is-playing');
        });
    });
})();
