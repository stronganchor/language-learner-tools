(function () {
    'use strict';

    var audio = new Audio();
    audio.preload = 'none';
    var currentButton = null;
    var i18n = (window.llWordOptionRulesI18n && typeof window.llWordOptionRulesI18n === 'object')
        ? window.llWordOptionRulesI18n
        : {};

    function t(key, fallback) {
        if (Object.prototype.hasOwnProperty.call(i18n, key) && typeof i18n[key] === 'string' && i18n[key] !== '') {
            return i18n[key];
        }
        return fallback;
    }

    function formatText(template, values) {
        var output = String(template || '');
        var list = Array.isArray(values) ? values : [];
        var nextIndex = 0;

        output = output.replace(/%(\d+)\$s/g, function (match, index) {
            var mappedIndex = parseInt(index, 10) - 1;
            if (!Number.isInteger(mappedIndex) || mappedIndex < 0 || typeof list[mappedIndex] === 'undefined') {
                return '';
            }
            return String(list[mappedIndex]);
        });

        output = output.replace(/%s/g, function () {
            if (typeof list[nextIndex] === 'undefined') {
                nextIndex += 1;
                return '';
            }
            var value = list[nextIndex];
            nextIndex += 1;
            return String(value);
        });

        return output;
    }

    function initWordOptionRulesAutosave() {
        var form = document.querySelector('.ll-tools-word-options-form');
        var status = form ? form.querySelector('[data-ll-word-options-save-status]') : null;
        var statusMessage = status ? status.querySelector('[data-ll-word-options-save-status-message]') : null;
        var ajaxUrl = (typeof i18n.ajaxUrl === 'string' && i18n.ajaxUrl !== '')
            ? i18n.ajaxUrl
            : ((typeof window.ajaxurl === 'string' && window.ajaxurl !== '') ? window.ajaxurl : '');
        var debounceTimer = 0;
        var resetTimer = 0;
        var inFlight = false;
        var queued = false;
        var queuedScopes = {};

        if (!form || !ajaxUrl || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
            return null;
        }

        function clearDebounceTimer() {
            if (!debounceTimer) {
                return;
            }

            window.clearTimeout(debounceTimer);
            debounceTimer = 0;
        }

        function clearResetTimer() {
            if (!resetTimer) {
                return;
            }

            window.clearTimeout(resetTimer);
            resetTimer = 0;
        }

        function setStatus(nextState, message) {
            var state = ['saving', 'saved', 'error'].indexOf((nextState || '').toString()) !== -1
                ? nextState.toString()
                : 'idle';
            var text = (message || '').toString();

            if (!status) {
                return;
            }

            status.setAttribute('data-state', state);
            status.setAttribute('aria-label', text);

            if (state === 'idle') {
                status.setAttribute('hidden', 'hidden');
            } else {
                status.removeAttribute('hidden');
            }

            if (!statusMessage) {
                return;
            }

            if (state === 'idle' || text === '') {
                statusMessage.textContent = '';
                statusMessage.setAttribute('hidden', 'hidden');
                return;
            }

            statusMessage.textContent = text;
            statusMessage.removeAttribute('hidden');
        }

        function scheduleStatusReset(delayMs) {
            clearResetTimer();
            resetTimer = window.setTimeout(function () {
                resetTimer = 0;
                setStatus('idle', '');
            }, delayMs);
        }

        function handleSaveFailure(message) {
            inFlight = false;
            setStatus('error', message || t('autosaveError', 'Unable to save word options.'));
        }

        function performSave() {
            var formData;
            var scopes;

            if (inFlight) {
                queued = true;
                return;
            }

            queued = false;
            scopes = Object.keys(queuedScopes);
            queuedScopes = {};
            inFlight = true;
            clearResetTimer();
            setStatus('saving', t('autosaveSaving', 'Saving word options...'));

            formData = new window.FormData(form);
            formData.set('action', 'll_tools_save_word_option_rules_async');
            formData.set('ll_scroll', String(Math.max(0, Math.round(getScrollTop()))));
            formData.set('ll_mutation_scope', scopes.length ? scopes.join(',') : 'all');

            window.fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            }).then(function (response) {
                return response.json().catch(function () {
                    return {
                        success: false,
                        data: {}
                    };
                }).then(function (payload) {
                    return {
                        ok: response.ok,
                        payload: payload
                    };
                });
            }).then(function (result) {
                var payload = result && result.payload ? result.payload : {};
                var data = payload && typeof payload.data === 'object' && payload.data ? payload.data : {};
                var message = (typeof data.message === 'string' && data.message !== '')
                    ? data.message
                    : t('autosaveSaved', 'Word options saved.');

                inFlight = false;

                if (!result.ok || !payload.success) {
                    handleSaveFailure((typeof data.message === 'string' && data.message !== '')
                        ? data.message
                        : t('autosaveError', 'Unable to save word options.'));
                    return;
                }

                setStatus('saved', message);
                scheduleStatusReset(1600);

                if (queued) {
                    performSave();
                }
            }).catch(function () {
                handleSaveFailure(t('autosaveError', 'Unable to save word options.'));
            });
        }

        function scheduleSave(delayMs, scope) {
            clearDebounceTimer();
            queued = true;
            if (scope === 'groups' || scope === 'pairs') {
                queuedScopes[scope] = true;
            }
            debounceTimer = window.setTimeout(function () {
                debounceTimer = 0;
                performSave();
            }, typeof delayMs === 'number' ? delayMs : 700);
        }

        function saveNow(scope) {
            clearDebounceTimer();
            queued = true;
            if (scope === 'groups' || scope === 'pairs') {
                queuedScopes[scope] = true;
            }
            performSave();
        }

        function isAutosaveField(target) {
            return !!(
                target
                && target.matches
                && (
                    target.matches('[data-group-name-input]')
                    || target.matches('.ll-tools-word-options-group-cell input[type="checkbox"]')
                    || target.matches('input[type="checkbox"][name^="pair_recording_types["]')
                )
            );
        }

        function getAutosaveScope(target) {
            if (target && target.matches && target.matches('input[type="checkbox"][name^="pair_recording_types["]')) {
                return 'pairs';
            }
            return 'groups';
        }

        form.addEventListener('input', function (event) {
            if (isAutosaveField(event.target)) {
                scheduleSave(undefined, getAutosaveScope(event.target));
            }
        });

        form.addEventListener('change', function (event) {
            if (isAutosaveField(event.target)) {
                scheduleSave(undefined, getAutosaveScope(event.target));
            }
        });

        form.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target && event.target.matches && event.target.matches('[data-group-name-input]')) {
                event.preventDefault();
                saveNow('groups');
            }
        });

        form.addEventListener('submit', function (event) {
            var submitter = event.submitter || null;
            var submitName = submitter && submitter.name ? submitter.name.toString() : '';

            clearDebounceTimer();

            if (submitName === 'add_pair' || submitName === 'remove_pair') {
                return;
            }

            event.preventDefault();
            saveNow();
        });

        return {
            schedule: scheduleSave,
            saveNow: saveNow
        };
    }

    function initWordsetMemory() {
        var select = document.querySelector('#ll-word-option-wordset');
        var storageKey = 'llWordOptionRulesLastWordsetId';
        if (!select || !window.localStorage) {
            return;
        }

        function hasOption(value) {
            return Array.prototype.some.call(select.options, function (option) {
                return option && option.value === value;
            });
        }

        try {
            var storedValue = window.localStorage.getItem(storageKey) || '';
            if (!select.value && storedValue && hasOption(storedValue)) {
                select.value = storedValue;
            }

            if (select.value) {
                window.localStorage.setItem(storageKey, select.value);
            }
        } catch (err) {
            return;
        }

        select.addEventListener('change', function () {
            try {
                if (select.value) {
                    window.localStorage.setItem(storageKey, select.value);
                } else {
                    window.localStorage.removeItem(storageKey);
                }
            } catch (err) {
                // Ignore storage failures and keep the admin screen usable.
            }
        });
    }

    function initGroupManager(autosaveController) {
        var groupList = document.querySelector('[data-ll-group-list]');
        var addBtn = document.querySelector('[data-group-add]');
        var table = document.querySelector('[data-ll-group-table]');
        var tableWrap = table ? table.closest('.ll-tools-word-options-table-wrap--groups') : null;
        if (!groupList || !addBtn || !table) {
            return;
        }

        var nextIndex = parseInt(groupList.getAttribute('data-next-index') || '0', 10);
        if (isNaN(nextIndex) || nextIndex < 0) {
            nextIndex = 0;
        }
        var stickyHeaderTable = null;
        var stickyHeaderSyncFrame = 0;
        var groupTableLayoutFrame = 0;
        var desktopMediaQuery = window.matchMedia ? window.matchMedia('(min-width: 783px)') : null;

        function isCondensedLayout() {
            return !!(tableWrap && tableWrap.classList.contains('is-condensed'));
        }

        function syncStickyHeaderOffset() {
            if (!stickyHeaderTable || !tableWrap || isCondensedLayout()) {
                return;
            }

            stickyHeaderTable.style.transform = 'translateY(' + tableWrap.scrollTop + 'px)';
        }

        function clearStickyHeader() {
            if (stickyHeaderSyncFrame) {
                window.cancelAnimationFrame(stickyHeaderSyncFrame);
                stickyHeaderSyncFrame = 0;
            }

            if (stickyHeaderTable) {
                stickyHeaderTable.remove();
                stickyHeaderTable = null;
            }

            if (tableWrap) {
                tableWrap.removeAttribute('data-has-cloned-header');
            }
        }

        function rebuildStickyHeader() {
            var head;
            var headRow;
            var originalCells;
            var clonedCells;

            stickyHeaderSyncFrame = 0;

            if (!tableWrap || isCondensedLayout() || (desktopMediaQuery && !desktopMediaQuery.matches)) {
                clearStickyHeader();
                return;
            }

            head = table.querySelector('thead');
            headRow = head ? head.querySelector('tr') : null;
            if (!head || !headRow || !headRow.children.length) {
                clearStickyHeader();
                return;
            }

            if (!stickyHeaderTable) {
                stickyHeaderTable = document.createElement('table');
                stickyHeaderTable.className = 'widefat ll-tools-word-options-table ll-tools-word-options-table--cloned-head';
                stickyHeaderTable.setAttribute('aria-hidden', 'true');
                tableWrap.insertBefore(stickyHeaderTable, table);
            }

            stickyHeaderTable.innerHTML = '';
            stickyHeaderTable.appendChild(head.cloneNode(true));
            tableWrap.setAttribute('data-has-cloned-header', '1');

            originalCells = Array.prototype.slice.call(headRow.children);
            clonedCells = Array.prototype.slice.call(stickyHeaderTable.querySelectorAll('thead th'));
            stickyHeaderTable.style.width = Math.max(table.scrollWidth, table.offsetWidth, tableWrap.clientWidth) + 'px';

            clonedCells.forEach(function (cell, index) {
                var originalCell = originalCells[index];
                var width = originalCell ? originalCell.getBoundingClientRect().width : 0;
                if (width > 0) {
                    cell.style.width = width + 'px';
                    cell.style.minWidth = width + 'px';
                    cell.style.maxWidth = width + 'px';
                }
            });

            syncStickyHeaderOffset();
        }

        function requestStickyHeaderSync() {
            if (stickyHeaderSyncFrame) {
                return;
            }

            stickyHeaderSyncFrame = window.requestAnimationFrame(rebuildStickyHeader);
        }

        function setCondensedLayout(shouldCondense) {
            if (!tableWrap) {
                return;
            }

            if (shouldCondense) {
                tableWrap.classList.add('is-condensed');
                tableWrap.setAttribute('data-layout', 'condensed');
                clearStickyHeader();
                return;
            }

            tableWrap.classList.remove('is-condensed');
            tableWrap.removeAttribute('data-layout');
            requestStickyHeaderSync();
        }

        function updateGroupTableLayout() {
            var wasCondensed;
            var shouldCondense;

            groupTableLayoutFrame = 0;

            if (!tableWrap) {
                return;
            }

            wasCondensed = isCondensedLayout();
            if (wasCondensed) {
                tableWrap.classList.remove('is-condensed');
            }

            shouldCondense = table.scrollWidth > tableWrap.clientWidth + 2;

            if (wasCondensed) {
                tableWrap.classList.add('is-condensed');
            }

            setCondensedLayout(shouldCondense);
        }

        function requestGroupTableLayoutSync() {
            if (groupTableLayoutFrame) {
                return;
            }

            groupTableLayoutFrame = window.requestAnimationFrame(updateGroupTableLayout);
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
            requestGroupTableLayoutSync();
        }

        function getGroupLabelText(label) {
            var normalizedLabel = typeof label === 'string' ? label.trim() : '';
            return normalizedLabel || t('groupLabelFallback', 'Group');
        }

        function updateGroupCellLabels(groupId, label) {
            var cells = table.querySelectorAll('td[data-group-id="' + groupId + '"]');
            var visibleLabel = getGroupLabelText(label);
            cells.forEach(function (cell) {
                cell.setAttribute('data-group-label', label || '');
                var cellLabel = cell.querySelector('[data-group-cell-label]');
                if (cellLabel) {
                    cellLabel.textContent = visibleLabel;
                }
            });
        }

        function updateCheckboxLabels(groupId, label) {
            var cells = table.querySelectorAll('td[data-group-id="' + groupId + '"] input[type="checkbox"]');
            var ariaLabel = label
                ? formatText(t('assignToGroupNamedTemplate', 'Assign to group %s'), [label])
                : t('assignToGroup', 'Assign to group');
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
                td.setAttribute('data-group-label', label || '');
                var labelEl = document.createElement('label');
                labelEl.className = 'll-tools-word-options-group-check';
                var groupLabel = document.createElement('span');
                groupLabel.className = 'll-tools-word-options-group-cell-label';
                groupLabel.setAttribute('data-group-cell-label', '');
                groupLabel.textContent = getGroupLabelText(label || '');
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'group_members[' + groupId + '][]';
                checkbox.value = wordId;
                checkbox.setAttribute(
                    'aria-label',
                    label
                        ? formatText(t('assignToGroupNamedTemplate', 'Assign to group %s'), [label])
                        : t('assignToGroup', 'Assign to group')
                );
                labelEl.appendChild(groupLabel);
                labelEl.appendChild(checkbox);
                td.appendChild(labelEl);
                row.appendChild(td);
            });
            requestGroupTableLayoutSync();
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
            requestGroupTableLayoutSync();
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
            removeBtn.textContent = t('remove', 'Remove');

            row.appendChild(input);
            row.appendChild(removeBtn);
            groupList.appendChild(row);

            addGroupColumn(groupId, label || '');
            input.addEventListener('input', function () {
                updateHeaderLabel(groupId, input.value);
                updateGroupCellLabels(groupId, input.value);
                updateCheckboxLabels(groupId, input.value);
            });

            if (autosaveController && typeof autosaveController.schedule === 'function') {
                autosaveController.schedule(150, 'groups');
            }
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
            if (autosaveController && typeof autosaveController.schedule === 'function') {
                autosaveController.schedule(150, 'groups');
            }
        });

        groupList.querySelectorAll('[data-group-name-input]').forEach(function (input) {
            var row = input.closest('[data-group-id]');
            if (!row) {
                return;
            }
            var groupId = row.getAttribute('data-group-id');
            updateHeaderLabel(groupId, input.value);
            updateGroupCellLabels(groupId, input.value);
            updateCheckboxLabels(groupId, input.value);
            input.addEventListener('input', function () {
                updateHeaderLabel(groupId, input.value);
                updateGroupCellLabels(groupId, input.value);
                updateCheckboxLabels(groupId, input.value);
            });
        });

        if (tableWrap) {
            tableWrap.addEventListener('scroll', syncStickyHeaderOffset, { passive: true });
        }

        window.addEventListener('resize', requestGroupTableLayoutSync);
        if (desktopMediaQuery) {
            if (typeof desktopMediaQuery.addEventListener === 'function') {
                desktopMediaQuery.addEventListener('change', requestGroupTableLayoutSync);
            } else if (typeof desktopMediaQuery.addListener === 'function') {
                desktopMediaQuery.addListener(requestGroupTableLayoutSync);
            }
        }

        requestGroupTableLayoutSync();
    }

    function initPairSelectExclusions() {
        var selectA = document.querySelector('#ll-word-option-pair-a');
        var selectB = document.querySelector('#ll-word-option-pair-b');

        if (!selectA || !selectB) {
            return;
        }

        function updateOptions(select, blockedValue) {
            Array.prototype.forEach.call(select.options, function (option) {
                if (!option || option.value === '') {
                    return;
                }

                var shouldHide = blockedValue !== '' && option.value === blockedValue;
                option.disabled = shouldHide;
                option.hidden = shouldHide;
            });
        }

        function normalizeSelections(changedSelect) {
            if (!selectA.value || !selectB.value || selectA.value !== selectB.value) {
                return;
            }

            if (changedSelect === selectA) {
                selectB.value = '';
                return;
            }

            selectA.value = '';
        }

        function syncPairSelects(changedSelect) {
            normalizeSelections(changedSelect);
            updateOptions(selectA, selectB.value);
            updateOptions(selectB, selectA.value);
        }

        selectA.addEventListener('change', function () {
            syncPairSelects(selectA);
        });

        selectB.addEventListener('change', function () {
            syncPairSelects(selectB);
        });

        syncPairSelects(null);
    }

    function initPairCandidateSearch() {
        var form = document.querySelector('.ll-tools-word-options-form');
        var fields = form ? form.querySelectorAll('[data-ll-pair-candidate-field]') : [];
        var ajaxUrl = (typeof i18n.ajaxUrl === 'string' && i18n.ajaxUrl !== '')
            ? i18n.ajaxUrl
            : ((typeof window.ajaxurl === 'string' && window.ajaxurl !== '') ? window.ajaxurl : '');
        var nonce = typeof i18n.candidateNonce === 'string' ? i18n.candidateNonce : '';
        var wordsetInput = form ? form.querySelector('input[name="wordset_id"]') : null;
        var categoryInput = form ? form.querySelector('input[name="category_id"]') : null;

        if (!form || !fields.length || !ajaxUrl || !nonce || !wordsetInput || !categoryInput || typeof window.fetch !== 'function') {
            return;
        }

        fields.forEach(function (field) {
            var input = field.querySelector('[data-ll-pair-candidate-search]');
            var targetId = input ? input.getAttribute('data-target') : '';
            var select = targetId ? document.getElementById(targetId) : null;
            var initialOptions = select ? select.innerHTML : '';
            var timer = 0;
            var controller = null;

            if (!input || !select) {
                return;
            }

            function setSearchState(isBusy, hasError) {
                input.setAttribute('aria-busy', isBusy ? 'true' : 'false');
                if (hasError) {
                    input.setAttribute('aria-invalid', 'true');
                    input.setAttribute('title', t('candidateSearchError', 'Unable to search words.'));
                } else {
                    input.removeAttribute('aria-invalid');
                    input.removeAttribute('title');
                }
            }

            function restoreInitialOptions() {
                var selectedValue = select.value;
                var selectedOption = selectedValue ? select.querySelector('option:checked') : null;
                var selectedLabel = selectedOption ? selectedOption.textContent : '';
                select.innerHTML = initialOptions;
                if (selectedValue && !select.querySelector('option[value="' + window.CSS.escape(selectedValue) + '"]')) {
                    var selected = document.createElement('option');
                    selected.value = selectedValue;
                    selected.textContent = selectedLabel || ('#' + selectedValue);
                    select.appendChild(selected);
                }
                if (selectedValue) {
                    select.value = selectedValue;
                }
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function replaceOptions(candidates) {
                var selectedValue = select.value;
                var selectedOption = selectedValue ? select.querySelector('option:checked') : null;
                var selectedLabel = selectedOption ? selectedOption.textContent : '';
                var placeholder = document.createElement('option');

                select.innerHTML = '';
                placeholder.value = '';
                placeholder.textContent = t('candidateSelectPlaceholder', 'Select a word');
                select.appendChild(placeholder);

                (Array.isArray(candidates) ? candidates : []).forEach(function (candidate) {
                    var id = candidate && Number.isInteger(Number(candidate.id)) ? String(candidate.id) : '';
                    var label = candidate && typeof candidate.label === 'string' ? candidate.label : '';
                    var option;
                    if (!id || !label || select.querySelector('option[value="' + window.CSS.escape(id) + '"]')) {
                        return;
                    }
                    option = document.createElement('option');
                    option.value = id;
                    option.textContent = label;
                    select.appendChild(option);
                });

                if (selectedValue && !select.querySelector('option[value="' + window.CSS.escape(selectedValue) + '"]')) {
                    var selected = document.createElement('option');
                    selected.value = selectedValue;
                    selected.textContent = selectedLabel || ('#' + selectedValue);
                    select.appendChild(selected);
                }
                select.value = selectedValue || '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function runSearch() {
                var search = input.value.trim();
                var formData;

                timer = 0;
                if (!search) {
                    if (controller) {
                        controller.abort();
                        controller = null;
                    }
                    setSearchState(false, false);
                    restoreInitialOptions();
                    return;
                }

                if (controller) {
                    controller.abort();
                }
                controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
                formData = new window.FormData();
                formData.set('action', 'll_tools_word_option_rule_candidates');
                formData.set('_ajax_nonce', nonce);
                formData.set('wordset_id', wordsetInput.value || '');
                formData.set('category_id', categoryInput.value || '');
                formData.set('search', search);
                setSearchState(true, false);

                window.fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                    signal: controller ? controller.signal : undefined
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok || !payload || !payload.success) {
                            throw new Error('candidate_search_failed');
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    var data = payload && typeof payload.data === 'object' && payload.data ? payload.data : {};
                    replaceOptions(data.candidates || []);
                    setSearchState(false, false);
                }).catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    setSearchState(false, true);
                });
            }

            input.addEventListener('input', function () {
                if (timer) {
                    window.clearTimeout(timer);
                }
                timer = window.setTimeout(runSearch, 250);
            });
        });
    }

    function getScrollTop() {
        return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
    }

    function initScrollPersistence() {
        var form = document.querySelector('.ll-tools-word-options-form');
        var scrollInput = form ? form.querySelector('[data-ll-scroll-input]') : null;
        var url;
        var scrollTop;
        var attempts = 0;
        var hasRestored = false;

        if (form && scrollInput) {
            form.addEventListener('submit', function () {
                scrollInput.value = String(Math.max(0, Math.round(getScrollTop())));
            });
        }

        try {
            url = new URL(window.location.href);
        } catch (err) {
            return;
        }

        scrollTop = parseInt(url.searchParams.get('ll_scroll') || '', 10);
        if (!Number.isInteger(scrollTop) || scrollTop <= 0) {
            return;
        }

        function cleanUrl() {
            if (!window.history || typeof window.history.replaceState !== 'function') {
                return;
            }

            url.searchParams.delete('ll_scroll');
            window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
        }

        function applyScroll() {
            if (hasRestored) {
                return;
            }

            window.scrollTo(0, scrollTop);
            attempts += 1;

            if (attempts < 6 && Math.abs(getScrollTop() - scrollTop) > 2) {
                window.requestAnimationFrame(applyScroll);
                return;
            }

            hasRestored = true;
            cleanUrl();
        }

        window.requestAnimationFrame(applyScroll);
        window.addEventListener('load', function () {
            window.requestAnimationFrame(applyScroll);
        }, { once: true });
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

    var autosaveController = initWordOptionRulesAutosave();

    initWordsetMemory();
    initGroupManager(autosaveController);
    initPairCandidateSearch();
    initPairSelectExclusions();
    initScrollPersistence();

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
