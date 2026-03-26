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

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('[data-ll-vocab-lesson-print-root]');
        if (!root) {
            return;
        }

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
            showTranslations: root.getAttribute('data-show-translations') === '1'
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

        function findItemIndex(wordId) {
            for (var index = 0; index < items.length; index += 1) {
                if (items[index].wordId === wordId) {
                    return index;
                }
            }
            return -1;
        }

        function moveVisibleItem(wordId, direction) {
            var visibleItems = items.filter(function (item) {
                return !item.removed;
            });
            var visibleIds = visibleItems.map(function (item) {
                return item.wordId;
            });
            var currentVisibleIndex = visibleIds.indexOf(wordId);
            if (currentVisibleIndex === -1) {
                return;
            }

            var nextVisibleIndex = currentVisibleIndex + direction;
            if (nextVisibleIndex < 0 || nextVisibleIndex >= visibleIds.length) {
                return;
            }

            var currentIndex = findItemIndex(wordId);
            var swapIndex = findItemIndex(visibleIds[nextVisibleIndex]);
            if (currentIndex === -1 || swapIndex === -1) {
                return;
            }

            var currentItem = items[currentIndex];
            items[currentIndex] = items[swapIndex];
            items[swapIndex] = currentItem;
        }

        function buildCard(item, visibleIndex, visibleCount) {
            var article = document.createElement('article');
            article.className = 'll-vocab-lesson-print-card ll-vocab-lesson-print-card--interactive';
            article.setAttribute('data-word-id', String(item.wordId));

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
            root.setAttribute('data-show-text', state.showText ? '1' : '0');
            root.setAttribute('data-show-translations', state.showTranslations ? '1' : '0');

            var visibleItems = items.filter(function (item) {
                return !item.removed;
            });
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
                window.print();
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
    });
})();
