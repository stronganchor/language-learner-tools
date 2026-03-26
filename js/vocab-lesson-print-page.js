(function () {
    'use strict';

    var cfg = window.llToolsVocabLessonPrintData || {};
    var i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};

    function t(key, fallback) {
        var value = i18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function toInt(value) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function chunk(items, size) {
        var chunks = [];
        for (var index = 0; index < items.length; index += size) {
            chunks.push(items.slice(index, index + size));
        }
        return chunks;
    }

    function createButton(className, action, label, iconMarkup) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.setAttribute('data-ll-vocab-lesson-print-action', action);
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.innerHTML = iconMarkup;
        return button;
    }

    function init() {
        var root = document.querySelector('[data-ll-vocab-lesson-print-root]');
        if (!root || root.getAttribute('data-ll-vocab-lesson-print-ready') === '1') {
            return;
        }

        root.setAttribute('data-ll-vocab-lesson-print-ready', '1');

        var canvas = root.querySelector('[data-ll-vocab-lesson-print-canvas]');
        if (!canvas) {
            return;
        }

        var toolbar = root.querySelector('[data-ll-vocab-lesson-print-toolbar]');
        var removedWrap = root.querySelector('[data-ll-vocab-lesson-print-removed]');
        var removedList = root.querySelector('[data-ll-vocab-lesson-print-removed-list]');
        var restoreAllButton = root.querySelector('[data-ll-vocab-lesson-print-restore-all]');
        var printButton = root.querySelector('[data-ll-vocab-lesson-print-trigger]');
        var textToggle = root.querySelector('[data-ll-vocab-lesson-print-toggle="text"]');
        var translationToggle = root.querySelector('[data-ll-vocab-lesson-print-toggle="translations"]');
        var titleText = String(root.getAttribute('data-title') || '').trim() || 'Print Lesson';
        var itemsPerPage = Math.max(1, toInt(root.getAttribute('data-items-per-page')) || 12);

        var icons = {
            moveEarlier: '<span aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M10 4.5v11M5.5 9l4.5-4.5L14.5 9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>',
            moveLater: '<span aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M10 4.5v11M5.5 11l4.5 4.5 4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>',
            remove: '<span aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M5.5 5.5 14.5 14.5M14.5 5.5 5.5 14.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>',
            restore: '<span aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M10 4.5v11M4.5 10h11" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>'
        };

        var items = Array.prototype.slice.call(canvas.querySelectorAll('.ll-vocab-lesson-print-card')).map(function (card) {
            var media = card.querySelector('.ll-vocab-lesson-print-card__media');
            var wordText = String(card.getAttribute('data-word-text') || '').trim();
            var translationText = String(card.getAttribute('data-translation-text') || '').trim();
            var label = String(card.getAttribute('data-label') || wordText || translationText || '').trim();

            return {
                wordId: toInt(card.getAttribute('data-word-id')),
                label: label,
                wordText: wordText,
                translationText: translationText,
                mediaHtml: media ? media.innerHTML : card.innerHTML,
                removed: false
            };
        }).filter(function (item) {
            return item.wordId > 0 && item.mediaHtml;
        });

        if (!items.length) {
            return;
        }

        var state = {
            showText: root.getAttribute('data-show-text') === '1',
            showTranslations: root.getAttribute('data-show-translations') === '1',
            dragWordId: 0,
            dropTargetWordId: 0,
            dropAfter: false
        };

        function updateUrl() {
            if (!window.history || typeof window.history.replaceState !== 'function') {
                return;
            }

            var nextUrl = new URL(window.location.href);
            nextUrl.searchParams.set('ll_print', '1');
            nextUrl.searchParams.delete('ll_print_images');
            nextUrl.searchParams.delete('ll_print_nonce');

            if (state.showText) {
                nextUrl.searchParams.set('ll_print_text', '1');
            } else {
                nextUrl.searchParams.delete('ll_print_text');
            }

            if (state.showTranslations) {
                nextUrl.searchParams.set('ll_print_translations', '1');
            } else {
                nextUrl.searchParams.delete('ll_print_translations');
            }

            window.history.replaceState({}, '', nextUrl.toString());
        }

        function getVisibleItems() {
            return items.filter(function (item) {
                return !item.removed;
            });
        }

        function findItemIndex(wordId) {
            for (var index = 0; index < items.length; index += 1) {
                if (items[index].wordId === wordId) {
                    return index;
                }
            }
            return -1;
        }

        function findVisibleItemIndex(visibleItems, wordId) {
            for (var index = 0; index < visibleItems.length; index += 1) {
                if (visibleItems[index].wordId === wordId) {
                    return index;
                }
            }
            return -1;
        }

        function applyVisibleOrder(visibleItems) {
            var reordered = [];
            var visibleIndex = 0;

            items.forEach(function (item) {
                if (item.removed) {
                    reordered.push(item);
                    return;
                }

                reordered.push(visibleItems[visibleIndex] || item);
                visibleIndex += 1;
            });

            items.splice(0, items.length);
            Array.prototype.push.apply(items, reordered);
        }

        function reorderVisibleItems(draggedWordId, targetWordId, placeAfter) {
            if (draggedWordId <= 0 || targetWordId <= 0 || draggedWordId === targetWordId) {
                return;
            }

            var visibleItems = getVisibleItems();
            if (visibleItems.length < 2) {
                return;
            }

            var draggedIndex = findVisibleItemIndex(visibleItems, draggedWordId);
            var targetIndex = findVisibleItemIndex(visibleItems, targetWordId);
            if (draggedIndex === -1 || targetIndex === -1) {
                return;
            }

            var draggedItem = visibleItems.splice(draggedIndex, 1)[0];
            if (!draggedItem) {
                return;
            }

            targetIndex = findVisibleItemIndex(visibleItems, targetWordId);
            if (targetIndex === -1) {
                visibleItems.push(draggedItem);
            } else {
                visibleItems.splice(placeAfter ? targetIndex + 1 : targetIndex, 0, draggedItem);
            }

            applyVisibleOrder(visibleItems);
        }

        function moveVisibleItem(wordId, direction) {
            var visibleItems = getVisibleItems();
            var currentIndex = findVisibleItemIndex(visibleItems, wordId);
            if (currentIndex === -1) {
                return;
            }

            var nextIndex = currentIndex + direction;
            if (nextIndex < 0 || nextIndex >= visibleItems.length) {
                return;
            }

            reorderVisibleItems(wordId, visibleItems[nextIndex].wordId, direction > 0);
        }

        function clearDropIndicators() {
            Array.prototype.slice.call(
                canvas.querySelectorAll('.ll-vocab-lesson-print-card.is-drop-target-before, .ll-vocab-lesson-print-card.is-drop-target-after')
            ).forEach(function (card) {
                card.classList.remove('is-drop-target-before');
                card.classList.remove('is-drop-target-after');
            });
        }

        function clearDragState() {
            state.dragWordId = 0;
            state.dropTargetWordId = 0;
            state.dropAfter = false;
            root.classList.remove('ll-vocab-lesson-print-page--dragging');
            clearDropIndicators();

            Array.prototype.slice.call(canvas.querySelectorAll('.ll-vocab-lesson-print-card.is-dragging')).forEach(function (card) {
                card.classList.remove('is-dragging');
            });
        }

        function shouldInsertAfter(card, event) {
            if (!card || typeof card.getBoundingClientRect !== 'function') {
                return false;
            }

            var rect = card.getBoundingClientRect();
            if (!rect || rect.height <= 0) {
                return false;
            }

            var pointerY = (event && typeof event.clientY === 'number') ? event.clientY : rect.top;
            return pointerY >= (rect.top + (rect.height / 2));
        }

        function updateDropIndicator(card, event) {
            clearDropIndicators();

            var targetWordId = card ? toInt(card.getAttribute('data-word-id')) : 0;
            if (targetWordId <= 0 || targetWordId === state.dragWordId) {
                state.dropTargetWordId = 0;
                state.dropAfter = false;
                return;
            }

            state.dropTargetWordId = targetWordId;
            state.dropAfter = shouldInsertAfter(card, event);
            card.classList.add(state.dropAfter ? 'is-drop-target-after' : 'is-drop-target-before');
        }

        function buildCard(item, visibleIndex, visibleCount) {
            var article = document.createElement('article');
            article.className = 'll-vocab-lesson-print-card ll-vocab-lesson-print-card--interactive';
            article.setAttribute('data-word-id', String(item.wordId));
            article.setAttribute('draggable', visibleCount > 1 ? 'true' : 'false');

            var controls = document.createElement('div');
            controls.className = 'll-vocab-lesson-print-card__controls';

            var earlierLabel = t('moveEarlier', 'Move earlier') + ': ' + item.label;
            var laterLabel = t('moveLater', 'Move later') + ': ' + item.label;
            var removeLabel = t('removeWord', 'Remove from print') + ': ' + item.label;

            var earlierButton = createButton(
                'll-vocab-lesson-print-card__action ll-vocab-lesson-print-card__action--move-earlier',
                'move-earlier',
                earlierLabel,
                icons.moveEarlier
            );
            if (visibleIndex === 0) {
                earlierButton.disabled = true;
            }
            earlierButton.setAttribute('data-word-id', String(item.wordId));
            controls.appendChild(earlierButton);

            var laterButton = createButton(
                'll-vocab-lesson-print-card__action ll-vocab-lesson-print-card__action--move-later',
                'move-later',
                laterLabel,
                icons.moveLater
            );
            if (visibleIndex === visibleCount - 1) {
                laterButton.disabled = true;
            }
            laterButton.setAttribute('data-word-id', String(item.wordId));
            controls.appendChild(laterButton);

            var removeButton = createButton(
                'll-vocab-lesson-print-card__action ll-vocab-lesson-print-card__action--remove',
                'remove',
                removeLabel,
                icons.remove
            );
            removeButton.setAttribute('data-word-id', String(item.wordId));
            controls.appendChild(removeButton);

            var media = document.createElement('div');
            media.className = 'll-vocab-lesson-print-card__media';
            media.innerHTML = item.mediaHtml;
            Array.prototype.slice.call(media.querySelectorAll('img')).forEach(function (image) {
                image.setAttribute('draggable', 'false');
            });

            article.appendChild(controls);
            article.appendChild(media);

            var captions = document.createElement('div');
            captions.className = 'll-vocab-lesson-print-card__captions';
            var hasCaptions = false;

            if (state.showText && item.wordText) {
                hasCaptions = true;
                var wordText = document.createElement('div');
                wordText.className = 'll-vocab-lesson-print-card__text';
                wordText.setAttribute('dir', 'auto');
                wordText.textContent = item.wordText;
                captions.appendChild(wordText);
            }

            if (state.showTranslations && item.translationText) {
                hasCaptions = true;
                var translationText = document.createElement('div');
                translationText.className = 'll-vocab-lesson-print-card__translation';
                translationText.setAttribute('dir', 'auto');
                translationText.textContent = item.translationText;
                captions.appendChild(translationText);
            }

            if (hasCaptions) {
                article.appendChild(captions);
            }

            return article;
        }

        function renderRemovedItems() {
            if (!removedWrap || !removedList) {
                return;
            }

            removedList.innerHTML = '';
            var removedItems = items.filter(function (item) {
                return item.removed;
            });

            removedWrap.hidden = removedItems.length === 0;
            if (restoreAllButton) {
                restoreAllButton.hidden = removedItems.length === 0;
            }

            removedItems.forEach(function (item) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'll-vocab-lesson-print-removed__item';
                button.setAttribute('data-ll-vocab-lesson-print-action', 'restore');
                button.setAttribute('data-word-id', String(item.wordId));
                button.setAttribute('aria-label', t('restoreWord', 'Add back to print') + ': ' + item.label);
                button.innerHTML = icons.restore + '<span class="ll-vocab-lesson-print-removed__label"></span>';
                var label = button.querySelector('.ll-vocab-lesson-print-removed__label');
                if (label) {
                    label.textContent = item.label;
                }
                removedList.appendChild(button);
            });
        }

        function renderCanvas() {
            clearDragState();

            root.setAttribute('data-show-text', state.showText ? '1' : '0');
            root.setAttribute('data-show-translations', state.showTranslations ? '1' : '0');

            var visibleItems = getVisibleItems();
            canvas.innerHTML = '';

            if (!visibleItems.length) {
                var emptyState = document.createElement('section');
                emptyState.className = 'll-vocab-lesson-print-state ll-vocab-lesson-print-state--empty';

                var emptyTitle = document.createElement('h1');
                emptyTitle.className = 'll-vocab-lesson-print-state__title';
                emptyTitle.textContent = t('allRemovedTitle', 'All words removed.');

                var emptyMessage = document.createElement('p');
                emptyMessage.className = 'll-vocab-lesson-print-state__message';
                emptyMessage.textContent = t('allRemovedMessage', 'Restore one or more words to print this lesson.');

                emptyState.appendChild(emptyTitle);
                emptyState.appendChild(emptyMessage);
                canvas.appendChild(emptyState);
                renderRemovedItems();
                return;
            }

            chunk(visibleItems, itemsPerPage).forEach(function (pageItems, pageIndex) {
                var sheet = document.createElement('section');
                sheet.className = 'll-vocab-lesson-print-sheet';

                var title = document.createElement('h1');
                title.className = 'll-vocab-lesson-print-sheet__title';
                title.textContent = titleText;
                sheet.appendChild(title);

                var grid = document.createElement('div');
                grid.className = 'll-vocab-lesson-print-grid';
                grid.setAttribute('data-ll-vocab-lesson-print-grid', '');
                grid.setAttribute('data-page-index', String(pageIndex + 1));

                pageItems.forEach(function (item, visibleIndex) {
                    grid.appendChild(buildCard(item, (pageIndex * itemsPerPage) + visibleIndex, visibleItems.length));
                });

                sheet.appendChild(grid);
                canvas.appendChild(sheet);
            });

            renderRemovedItems();
        }

        root.addEventListener('click', function (event) {
            var actionTarget = event.target.closest('[data-ll-vocab-lesson-print-action]');
            if (!actionTarget) {
                return;
            }

            var action = String(actionTarget.getAttribute('data-ll-vocab-lesson-print-action') || '');
            var wordId = toInt(actionTarget.getAttribute('data-word-id'));

            if (action === 'print') {
                if (typeof window.print === 'function') {
                    window.print();
                }
                return;
            }

            if (action === 'restore-all') {
                items.forEach(function (item) {
                    item.removed = false;
                });
                renderCanvas();
                return;
            }

            if (wordId <= 0) {
                return;
            }

            var itemIndex = findItemIndex(wordId);
            if (itemIndex === -1) {
                return;
            }

            if (action === 'move-earlier') {
                moveVisibleItem(wordId, -1);
                renderCanvas();
                return;
            }

            if (action === 'move-later') {
                moveVisibleItem(wordId, 1);
                renderCanvas();
                return;
            }

            if (action === 'remove') {
                items[itemIndex].removed = true;
                renderCanvas();
                return;
            }

            if (action === 'restore') {
                items[itemIndex].removed = false;
                renderCanvas();
            }
        });

        canvas.addEventListener('dragstart', function (event) {
            var card = event.target.closest('.ll-vocab-lesson-print-card--interactive');
            if (!card) {
                return;
            }

            var wordId = toInt(card.getAttribute('data-word-id'));
            if (wordId <= 0) {
                return;
            }

            state.dragWordId = wordId;
            root.classList.add('ll-vocab-lesson-print-page--dragging');
            card.classList.add('is-dragging');

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                try {
                    event.dataTransfer.setData('text/plain', String(wordId));
                } catch (_) {}
            }
        });

        canvas.addEventListener('dragover', function (event) {
            if (state.dragWordId <= 0) {
                return;
            }

            var card = event.target.closest('.ll-vocab-lesson-print-card--interactive');
            if (!card) {
                return;
            }

            var targetWordId = toInt(card.getAttribute('data-word-id'));
            if (targetWordId <= 0 || targetWordId === state.dragWordId) {
                return;
            }

            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
            updateDropIndicator(card, event);
        });

        canvas.addEventListener('drop', function (event) {
            if (state.dragWordId <= 0) {
                return;
            }

            var card = event.target.closest('.ll-vocab-lesson-print-card--interactive');
            var targetWordId = card ? toInt(card.getAttribute('data-word-id')) : state.dropTargetWordId;
            if (targetWordId <= 0 || targetWordId === state.dragWordId) {
                clearDragState();
                return;
            }

            event.preventDefault();
            var placeAfter = card ? shouldInsertAfter(card, event) : state.dropAfter;
            reorderVisibleItems(state.dragWordId, targetWordId, placeAfter);
            renderCanvas();
        });

        canvas.addEventListener('dragend', function () {
            clearDragState();
        });

        if (textToggle) {
            textToggle.checked = state.showText;
            textToggle.addEventListener('change', function () {
                state.showText = !!textToggle.checked;
                updateUrl();
                renderCanvas();
            });
        }

        if (translationToggle) {
            translationToggle.checked = state.showTranslations;
            translationToggle.addEventListener('change', function () {
                state.showTranslations = !!translationToggle.checked;
                updateUrl();
                renderCanvas();
            });
        }

        if (restoreAllButton) {
            restoreAllButton.setAttribute('data-ll-vocab-lesson-print-action', 'restore-all');
        }

        if (printButton) {
            printButton.setAttribute('data-ll-vocab-lesson-print-action', 'print');
        }

        if (toolbar) {
            toolbar.hidden = false;
        }

        root.classList.add('ll-vocab-lesson-print-page--interactive');
        updateUrl();
        renderCanvas();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
