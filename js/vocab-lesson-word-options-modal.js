(function () {
    'use strict';

    var cfg = (window.llToolsVocabLessonWordOptions && typeof window.llToolsVocabLessonWordOptions === 'object')
        ? window.llToolsVocabLessonWordOptions
        : {};
    var iframeUrl = (cfg.iframeUrl || '').toString();
    if (!iframeUrl) {
        return;
    }

    var i18n = (cfg.i18n && typeof cfg.i18n === 'object') ? cfg.i18n : {};
    var modalEl = null;
    var dialogEl = null;
    var iframeEl = null;
    var loadingEl = null;
    var closeBtn = null;
    var lastFocusedEl = null;

    function t(key, fallback) {
        var value = i18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function createIcon() {
        var wrapper = document.createElement('span');
        wrapper.className = 'll-vocab-lesson-word-options-trigger__icon';
        wrapper.setAttribute('aria-hidden', 'true');
        wrapper.innerHTML =
            '<svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">' +
                '<circle cx="5" cy="6" r="2.2"></circle>' +
                '<circle cx="15" cy="6" r="2.2"></circle>' +
                '<circle cx="10" cy="14" r="2.2"></circle>' +
                '<path d="M6.9 7.1 8.7 12"></path>' +
                '<path d="M13.1 7.1 11.3 12"></path>' +
            '</svg>';
        return wrapper;
    }

    function createMetaChip(text) {
        if (!text) {
            return null;
        }

        var chip = document.createElement('span');
        chip.className = 'll-vocab-lesson-word-options-modal__meta-chip';
        chip.textContent = text;
        return chip;
    }

    function buildModal() {
        if (modalEl) {
            return;
        }

        modalEl = document.createElement('div');
        modalEl.className = 'll-vocab-lesson-word-options-modal';
        modalEl.hidden = true;

        var backdropEl = document.createElement('button');
        backdropEl.type = 'button';
        backdropEl.className = 'll-vocab-lesson-word-options-modal__backdrop';
        backdropEl.setAttribute('aria-label', t('closeLabel', 'Close'));

        dialogEl = document.createElement('div');
        dialogEl.className = 'll-vocab-lesson-word-options-modal__dialog';
        dialogEl.setAttribute('role', 'dialog');
        dialogEl.setAttribute('aria-modal', 'true');
        dialogEl.setAttribute('aria-labelledby', 'll-vocab-lesson-word-options-title');

        var headerEl = document.createElement('div');
        headerEl.className = 'll-vocab-lesson-word-options-modal__header';

        var titleWrapEl = document.createElement('div');
        titleWrapEl.className = 'll-vocab-lesson-word-options-modal__title-wrap';

        var titleEl = document.createElement('h2');
        titleEl.className = 'll-vocab-lesson-word-options-modal__title';
        titleEl.id = 'll-vocab-lesson-word-options-title';
        titleEl.textContent = t('dialogTitle', 'Word options');
        titleWrapEl.appendChild(titleEl);

        var metaEl = document.createElement('div');
        metaEl.className = 'll-vocab-lesson-word-options-modal__meta';
        var categoryChip = createMetaChip((cfg.categoryName || '').toString());
        var wordsetChip = createMetaChip((cfg.wordsetName || '').toString());
        if (categoryChip) {
            metaEl.appendChild(categoryChip);
        }
        if (wordsetChip) {
            metaEl.appendChild(wordsetChip);
        }
        if (metaEl.childNodes.length) {
            titleWrapEl.appendChild(metaEl);
        }

        closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'll-vocab-lesson-word-options-modal__close';
        closeBtn.setAttribute('aria-label', t('closeLabel', 'Close'));
        closeBtn.innerHTML =
            '<span aria-hidden="true">' +
                '<svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">' +
                    '<path d="M5.5 5.5 14.5 14.5"></path>' +
                    '<path d="M14.5 5.5 5.5 14.5"></path>' +
                '</svg>' +
            '</span>';

        headerEl.appendChild(titleWrapEl);
        headerEl.appendChild(closeBtn);

        var frameShellEl = document.createElement('div');
        frameShellEl.className = 'll-vocab-lesson-word-options-modal__frame-shell';

        loadingEl = document.createElement('div');
        loadingEl.className = 'll-vocab-lesson-word-options-modal__loading';
        loadingEl.innerHTML =
            '<span class="ll-vocab-lesson-word-options-modal__loading-dot" aria-hidden="true"></span>' +
            '<span class="ll-vocab-lesson-word-options-modal__loading-text"></span>';
        loadingEl.querySelector('.ll-vocab-lesson-word-options-modal__loading-text').textContent = t('loading', 'Opening word options...');

        iframeEl = document.createElement('iframe');
        iframeEl.className = 'll-vocab-lesson-word-options-modal__frame';
        iframeEl.setAttribute('title', t('iframeTitle', 'Lesson word option rules'));
        iframeEl.setAttribute('loading', 'eager');
        iframeEl.addEventListener('load', function () {
            if (loadingEl) {
                loadingEl.hidden = true;
            }
        });

        frameShellEl.appendChild(loadingEl);
        frameShellEl.appendChild(iframeEl);

        dialogEl.appendChild(headerEl);
        dialogEl.appendChild(frameShellEl);

        modalEl.appendChild(backdropEl);
        modalEl.appendChild(dialogEl);
        document.body.appendChild(modalEl);

        backdropEl.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);
    }

    function openModal() {
        buildModal();
        if (!modalEl || !dialogEl || !iframeEl) {
            return;
        }

        lastFocusedEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modalEl.hidden = false;
        document.body.classList.add('ll-vocab-lesson-word-options-open');
        if (!iframeEl.getAttribute('src')) {
            loadingEl.hidden = false;
            iframeEl.setAttribute('src', iframeUrl);
        }

        window.setTimeout(function () {
            if (closeBtn) {
                closeBtn.focus({ preventScroll: true });
            }
        }, 0);
    }

    function closeModal() {
        if (!modalEl || modalEl.hidden) {
            return;
        }

        modalEl.hidden = true;
        document.body.classList.remove('ll-vocab-lesson-word-options-open');
        if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
            lastFocusedEl.focus({ preventScroll: true });
        }
    }

    function handleKeydown(event) {
        if (event.key !== 'Escape' || !modalEl || modalEl.hidden) {
            return;
        }

        event.preventDefault();
        closeModal();
    }

    function injectTrigger() {
        var controlsEl = document.querySelector('.ll-vocab-lesson-star-controls');
        if (!controlsEl || controlsEl.querySelector('[data-ll-word-options-launcher]')) {
            return;
        }

        var triggerEl = document.createElement('button');
        triggerEl.type = 'button';
        triggerEl.className = 'll-study-btn tiny ll-vocab-lesson-word-options-trigger';
        triggerEl.setAttribute('data-ll-word-options-launcher', '1');
        triggerEl.setAttribute('aria-haspopup', 'dialog');
        triggerEl.setAttribute('title', t('buttonTitle', 'Edit word option rules for this lesson'));
        triggerEl.setAttribute('aria-label', t('buttonTitle', 'Edit word option rules for this lesson'));
        triggerEl.appendChild(createIcon());

        var labelEl = document.createElement('span');
        labelEl.className = 'll-vocab-lesson-word-options-trigger__label';
        labelEl.textContent = t('buttonLabel', 'Options');
        triggerEl.appendChild(labelEl);

        triggerEl.addEventListener('click', function (event) {
            event.preventDefault();
            openModal();
        });

        controlsEl.appendChild(triggerEl);
        document.addEventListener('keydown', handleKeydown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectTrigger);
    } else {
        injectTrigger();
    }
})();
