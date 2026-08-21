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
    var errorEl = null;
    var errorMessageEl = null;
    var closeBtn = null;
    var retryBtn = null;
    var lastFocusedEl = null;
    var frameShellEl = null;
    var frameReady = false;
    var loadAttempt = 0;
    var loadTimer = 0;
    var backgroundState = [];
    var loadTimeoutMs = Math.max(25, Math.min(120000, parseInt(cfg.loadTimeoutMs, 10) || 15000));

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
        backdropEl.tabIndex = -1;

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

        frameShellEl = document.createElement('div');
        frameShellEl.className = 'll-vocab-lesson-word-options-modal__frame-shell';

        loadingEl = document.createElement('div');
        loadingEl.className = 'll-vocab-lesson-word-options-modal__loading';
        loadingEl.setAttribute('role', 'status');
        loadingEl.setAttribute('aria-live', 'polite');
        loadingEl.innerHTML =
            '<span class="ll-vocab-lesson-word-options-modal__loading-dot" aria-hidden="true"></span>' +
            '<span class="ll-vocab-lesson-word-options-modal__loading-text"></span>';
        loadingEl.querySelector('.ll-vocab-lesson-word-options-modal__loading-text').textContent = t('loading', 'Opening word options...');

        errorEl = document.createElement('div');
        errorEl.className = 'll-vocab-lesson-word-options-modal__error';
        errorEl.setAttribute('role', 'alert');
        errorEl.hidden = true;

        errorMessageEl = document.createElement('p');
        errorMessageEl.className = 'll-vocab-lesson-word-options-modal__error-message';

        var errorActionsEl = document.createElement('div');
        errorActionsEl.className = 'll-vocab-lesson-word-options-modal__error-actions';

        retryBtn = document.createElement('button');
        retryBtn.type = 'button';
        retryBtn.className = 'll-vocab-lesson-word-options-modal__retry';
        retryBtn.textContent = t('retryLabel', 'Retry');

        var directOpenEl = document.createElement('a');
        directOpenEl.className = 'll-vocab-lesson-word-options-modal__direct-open';
        directOpenEl.href = iframeUrl;
        directOpenEl.target = '_blank';
        directOpenEl.rel = 'noopener noreferrer';
        directOpenEl.textContent = t('directOpenLabel', 'Open in a new tab');

        errorActionsEl.appendChild(retryBtn);
        errorActionsEl.appendChild(directOpenEl);
        errorEl.appendChild(errorMessageEl);
        errorEl.appendChild(errorActionsEl);

        frameShellEl.appendChild(loadingEl);
        frameShellEl.appendChild(errorEl);

        dialogEl.appendChild(headerEl);
        dialogEl.appendChild(frameShellEl);

        modalEl.appendChild(backdropEl);
        modalEl.appendChild(dialogEl);
        document.body.appendChild(modalEl);

        backdropEl.addEventListener('click', closeModal);
        closeBtn.addEventListener('click', closeModal);
        retryBtn.addEventListener('click', startFrameAttempt);
    }

    function isOpen() {
        return !!(modalEl && !modalEl.hidden);
    }

    function isVisibleFocusable(element) {
        if (!element || typeof element.focus !== 'function' || element.hidden || element.hasAttribute('disabled')) {
            return false;
        }
        if (element.getAttribute('aria-hidden') === 'true' || element.closest('[inert]')) {
            return false;
        }
        return typeof element.getClientRects !== 'function' || element.getClientRects().length > 0;
    }

    function getFocusables() {
        if (!dialogEl) {
            return [];
        }
        return Array.prototype.slice.call(dialogEl.querySelectorAll([
            'a[href]',
            'button:not([disabled])',
            'iframe',
            '[tabindex]:not([tabindex="-1"])'
        ].join(','))).filter(isVisibleFocusable);
    }

    function focusDialogStart() {
        var target = isVisibleFocusable(closeBtn) ? closeBtn : dialogEl;
        if (!target) {
            return;
        }
        try {
            target.focus({ preventScroll: true });
        } catch (_) {
            target.focus();
        }
    }

    function trapTab(event) {
        var focusables = getFocusables();
        if (!focusables.length) {
            event.preventDefault();
            focusDialogStart();
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        var active = document.activeElement;
        if (event.shiftKey && (active === first || !dialogEl.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !dialogEl.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }

    function restoreBackground() {
        backgroundState.forEach(function (record) {
            var element = record.element;
            if (!element) {
                return;
            }
            if (record.hadInert) {
                element.setAttribute('inert', record.inertValue);
            } else {
                element.removeAttribute('inert');
            }
            if (record.hadAriaHidden) {
                element.setAttribute('aria-hidden', record.ariaHiddenValue);
            } else {
                element.removeAttribute('aria-hidden');
            }
        });
        backgroundState = [];
    }

    function isolateBackground() {
        restoreBackground();
        Array.prototype.slice.call(document.body.children).forEach(function (element) {
            if (element === modalEl || /^(script|style|link|template)$/i.test(element.tagName || '')) {
                return;
            }
            backgroundState.push({
                element: element,
                hadInert: element.hasAttribute('inert'),
                inertValue: element.getAttribute('inert') || '',
                hadAriaHidden: element.hasAttribute('aria-hidden'),
                ariaHiddenValue: element.getAttribute('aria-hidden') || ''
            });
            element.setAttribute('inert', '');
            element.setAttribute('aria-hidden', 'true');
        });
    }

    function removePendingFrame() {
        window.clearTimeout(loadTimer);
        loadTimer = 0;
        if (iframeEl && !frameReady) {
            iframeEl.remove();
            iframeEl = null;
        }
    }

    function showFrameError(message) {
        loadAttempt += 1;
        frameReady = false;
        removePendingFrame();
        if (loadingEl) {
            loadingEl.hidden = true;
        }
        if (errorMessageEl) {
            errorMessageEl.textContent = message;
        }
        if (errorEl) {
            errorEl.hidden = false;
        }
        if (retryBtn) {
            retryBtn.focus({ preventScroll: true });
        }
    }

    function startFrameAttempt() {
        if (!isOpen() || !frameShellEl) {
            return;
        }
        loadAttempt += 1;
        var attempt = loadAttempt;
        frameReady = false;
        removePendingFrame();
        if (errorEl) {
            errorEl.hidden = true;
        }
        if (loadingEl) {
            loadingEl.hidden = false;
        }

        iframeEl = document.createElement('iframe');
        iframeEl.className = 'll-vocab-lesson-word-options-modal__frame';
        iframeEl.setAttribute('title', t('iframeTitle', 'Lesson word option rules'));
        iframeEl.setAttribute('loading', 'eager');
        iframeEl.hidden = true;
        iframeEl.addEventListener('load', function () {
            if (attempt !== loadAttempt || !isOpen()) {
                return;
            }
            var expectedEditor = null;
            try {
                expectedEditor = iframeEl.contentDocument
                    ? iframeEl.contentDocument.querySelector('[data-ll-word-options-ready="1"]')
                    : null;
            } catch (_) {
                expectedEditor = null;
            }
            if (!expectedEditor) {
                showFrameError(t('loadError', 'Word options could not be opened.'));
                return;
            }
            window.clearTimeout(loadTimer);
            loadTimer = 0;
            frameReady = true;
            iframeEl.hidden = false;
            loadingEl.hidden = true;
            errorEl.hidden = true;
        });
        iframeEl.addEventListener('error', function () {
            if (attempt === loadAttempt && isOpen()) {
                showFrameError(t('loadError', 'Word options could not be opened.'));
            }
        });
        iframeEl.src = iframeUrl;
        frameShellEl.appendChild(iframeEl);
        loadTimer = window.setTimeout(function () {
            if (attempt === loadAttempt && isOpen()) {
                showFrameError(t('loadTimeout', 'Word options are taking too long to open.'));
            }
        }, loadTimeoutMs);
    }

    function openModal() {
        buildModal();
        if (!modalEl || !dialogEl || !frameShellEl) {
            return;
        }

        lastFocusedEl = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        modalEl.hidden = false;
        modalEl.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ll-vocab-lesson-word-options-open');
        isolateBackground();
        if (!frameReady || !iframeEl || !iframeEl.isConnected) {
            startFrameAttempt();
        }

        window.setTimeout(function () {
            if (isOpen()) {
                focusDialogStart();
            }
        }, 0);
    }

    function closeModal() {
        if (!modalEl || modalEl.hidden) {
            return;
        }

        if (!frameReady) {
            loadAttempt += 1;
            removePendingFrame();
        }
        modalEl.hidden = true;
        modalEl.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ll-vocab-lesson-word-options-open');
        restoreBackground();
        if (lastFocusedEl && lastFocusedEl.isConnected && typeof lastFocusedEl.focus === 'function') {
            lastFocusedEl.focus({ preventScroll: true });
        }
    }

    function handleKeydown(event) {
        if (!isOpen()) {
            return;
        }
        if (event.key === 'Tab') {
            trapTab(event);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
        }
    }

    function handleFocusin(event) {
        if (isOpen() && dialogEl && !dialogEl.contains(event.target)) {
            focusDialogStart();
        }
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
        document.addEventListener('focusin', handleFocusin);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectTrigger);
    } else {
        injectTrigger();
    }
})();
