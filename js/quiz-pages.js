/* LL Tools – Quiz Pages (robust popup)
   - Delegated click (no inline JS)
   - Uses data-url if present; otherwise falls back to /quiz?category=...
   - Provides its own modal/iframe if no global llOpenFlashcardForCategory exists
*/
(function () {
    'use strict';

    var quizCfg = (window.llQuizPages && typeof window.llQuizPages === 'object') ? window.llQuizPages : {};
    var labelCfg = (quizCfg.labels && typeof quizCfg.labels === 'object') ? quizCfg.labels : {};
    var defaultQuizTitle = labelCfg.defaultTitle || 'Quiz';
    var closeLabel = labelCfg.closeLabel || 'Close';
    var closeConfirm = labelCfg.closeConfirm || 'Close this quiz? Your current progress in this popup will be lost.';
    var iframeTitle = labelCfg.iframeTitle || 'Quiz Content';
    var loadingLabel = labelCfg.loadingLabel || 'Loading quiz...';
    var readyLabel = labelCfg.readyLabel || 'Quiz ready.';
    var loadErrorLabel = labelCfg.loadErrorLabel || 'The quiz could not be loaded.';
    var loadTimeoutLabel = labelCfg.loadTimeoutLabel || 'The quiz is taking too long to load.';
    var retryLabel = labelCfg.retryLabel || 'Retry';
    var openDirectLabel = labelCfg.openDirectLabel || 'Open quiz in a new tab';
    var configuredIframeTimeout = parseInt(quizCfg.iframeTimeoutMs, 10);
    var iframeTimeoutMs = Number.isFinite(configuredIframeTimeout) && configuredIframeTimeout > 0
        ? configuredIframeTimeout
        : 12000;
    var modalHistoryActive = false;
    var suppressNextPopstate = false;
    var suppressResetTimer = null;

    // -------------------------
    // Minimal modal infrastructure
    // -------------------------
    var overlayEl, modalEl, iframeEl, lastFocus;
    var modalCloseButton, modalStatePanel, modalSpinner, modalStatus, modalRecovery, modalRetryButton, modalOpenDirect;
    var modalAttemptCleanup = null;
    var modalCurrentUrl = '';
    var modalAttemptNumber = 0;
    var modalBackgroundState = [];

    function clearModalPopstateSuppression() {
        if (suppressResetTimer) {
            clearTimeout(suppressResetTimer);
            suppressResetTimer = null;
        }
        suppressNextPopstate = false;
    }

    function scheduleModalPopstateSuppressionReset() {
        if (suppressResetTimer) {
            clearTimeout(suppressResetTimer);
        }
        suppressResetTimer = setTimeout(function () {
            suppressResetTimer = null;
            suppressNextPopstate = false;
        }, 600);
    }

    function isModalOpen() {
        return !!(overlayEl && overlayEl.parentNode);
    }

    function isVisibleFocusable(element) {
        if (!element || typeof element.focus !== 'function' || element.hidden || element.hasAttribute('disabled')) {
            return false;
        }
        if (element.getAttribute('aria-hidden') === 'true') {
            return false;
        }
        if (typeof element.getClientRects === 'function' && element.getClientRects().length === 0) {
            return false;
        }
        try {
            var style = window.getComputedStyle(element);
            if (style.display === 'none' || style.visibility === 'hidden') {
                return false;
            }
        } catch (_) { /* no-op */ }
        return !element.closest('[inert]');
    }

    function getModalFocusables() {
        if (!modalEl) return [];
        var selector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled]):not([type="hidden"])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            'iframe',
            '[tabindex]:not([tabindex="-1"])'
        ].join(',');
        return Array.prototype.slice.call(modalEl.querySelectorAll(selector)).filter(isVisibleFocusable);
    }

    function focusModalStart() {
        var target = isVisibleFocusable(modalCloseButton) ? modalCloseButton : modalEl;
        if (!target) return;
        try {
            target.focus({ preventScroll: true });
        } catch (_) {
            try { target.focus(); } catch (_ignore) { /* no-op */ }
        }
    }

    function trapModalTab(event) {
        if (!isModalOpen() || event.defaultPrevented || event.key !== 'Tab') return;
        var focusables = getModalFocusables();
        if (!focusables.length) {
            event.preventDefault();
            focusModalStart();
            return;
        }
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        var active = document.activeElement;
        if (event.shiftKey && (active === first || !modalEl.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !modalEl.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }

    function isolateModalBackground() {
        restoreModalBackground();
        if (!overlayEl || !overlayEl.parentNode || !document.body) return;
        Array.prototype.slice.call(document.body.children).forEach(function (element) {
            if (element === overlayEl) return;
            var tagName = String(element.tagName || '').toLowerCase();
            if (tagName === 'script' || tagName === 'style' || tagName === 'link' || tagName === 'template') return;
            modalBackgroundState.push({
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

    function restoreModalBackground() {
        modalBackgroundState.forEach(function (record) {
            var element = record.element;
            if (!element) return;
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
        modalBackgroundState = [];
    }

    function normalizeEmbedMessageType(data) {
        var rawType = null;
        if (typeof data === 'string') {
            rawType = data;
        } else if (data && typeof data === 'object') {
            rawType = data.type || data.action || null;
        }
        return rawType ? String(rawType).replace(/_/g, '-').toLowerCase() : '';
    }

    function expectedOriginForUrl(url) {
        try {
            return new URL(url, window.location.href).origin;
        } catch (_) {
            return '';
        }
    }

    function buildRetryUrl(url) {
        try {
            var retryUrl = new URL(url, window.location.href);
            retryUrl.searchParams.set('_ll_quiz_retry', String(Date.now()));
            return retryUrl.href;
        } catch (_) {
            return url;
        }
    }

    function watchQuizIframe(options) {
        var opts = (options && typeof options === 'object') ? options : {};
        var iframe = opts.iframe;
        var url = String(opts.url || '');
        if (!iframe || !url) return function () {};

        var finished = false;
        var timeoutId = null;
        var expectedOrigin = expectedOriginForUrl(url);
        var waitsForEmbedSignal = !!expectedOrigin && expectedOrigin === window.location.origin;

        function cleanup() {
            if (timeoutId) {
                window.clearTimeout(timeoutId);
                timeoutId = null;
            }
            iframe.removeEventListener('load', onLoad);
            iframe.removeEventListener('error', onError);
            window.removeEventListener('message', onMessage);
        }

        function finish(callback) {
            if (finished) return;
            finished = true;
            cleanup();
            if (typeof callback === 'function') callback();
        }

        function readEmbeddedState() {
            if (!waitsForEmbedSignal) return '';
            try {
                return normalizeEmbedMessageType(iframe.contentWindow && iframe.contentWindow.__LL_EMBED_STATE);
            } catch (_) {
                return '';
            }
        }

        function onLoad() {
            if (!waitsForEmbedSignal) {
                finish(opts.onReady);
                return;
            }
            var embeddedState = readEmbeddedState();
            if (embeddedState === 'll-embed-ready') {
                finish(opts.onReady);
            } else if (embeddedState === 'll-embed-error') {
                finish(opts.onError);
            }
        }

        function onError() {
            finish(opts.onError);
        }

        function onMessage(event) {
            if (!event || event.source !== iframe.contentWindow) return;
            if (expectedOrigin && expectedOrigin !== 'null' && event.origin && event.origin !== expectedOrigin) return;
            var type = normalizeEmbedMessageType(event.data);
            if (type === 'll-embed-ready') {
                finish(opts.onReady);
            } else if (type === 'll-embed-error') {
                finish(opts.onError);
            }
        }

        iframe.addEventListener('load', onLoad);
        iframe.addEventListener('error', onError);
        window.addEventListener('message', onMessage);
        timeoutId = window.setTimeout(function () {
            timeoutId = null;
            if (!finished && typeof opts.onTimeout === 'function') {
                opts.onTimeout();
            }
        }, iframeTimeoutMs);

        if (typeof opts.onLoading === 'function') {
            opts.onLoading();
        }
        if (opts.navigate !== false) {
            iframe.src = url;
        } else if (waitsForEmbedSignal) {
            window.setTimeout(function () {
                var embeddedState = readEmbeddedState();
                if (embeddedState === 'll-embed-ready') {
                    finish(opts.onReady);
                } else if (embeddedState === 'll-embed-error') {
                    finish(opts.onError);
                }
            }, 0);
        }
        return cleanup;
    }

    function setQuizIframeInteractive(iframe, isReady) {
        if (!iframe) return;
        if (!iframe.__llQuizOriginalFocusState) {
            iframe.__llQuizOriginalFocusState = {
                hadTabindex: iframe.hasAttribute('tabindex'),
                tabindex: iframe.getAttribute('tabindex'),
                hadAriaHidden: iframe.hasAttribute('aria-hidden'),
                ariaHidden: iframe.getAttribute('aria-hidden')
            };
        }

        var original = iframe.__llQuizOriginalFocusState;
        if (!isReady) {
            iframe.setAttribute('tabindex', '-1');
            iframe.setAttribute('aria-hidden', 'true');
            return;
        }

        if (original.hadTabindex) {
            iframe.setAttribute('tabindex', original.tabindex || '');
        } else {
            iframe.removeAttribute('tabindex');
        }
        if (original.hadAriaHidden) {
            iframe.setAttribute('aria-hidden', original.ariaHidden || '');
        } else {
            iframe.removeAttribute('aria-hidden');
        }
    }

    function setModalIframeState(state, message) {
        if (!modalEl || !iframeEl || !modalStatePanel) return;
        var isLoading = state === 'loading';
        var isReady = state === 'ready';
        modalEl.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        iframeEl.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        setQuizIframeInteractive(iframeEl, isReady);
        modalStatePanel.className = 'll-quiz-frame-state ll-quiz-frame-state--' + state;
        modalStatePanel.style.cssText = isReady
            ? 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);clip-path:inset(50%);white-space:nowrap;border:0;'
            : 'position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:24px;text-align:center;background:rgba(8,15,28,.94);color:#f8fafc;';
        if (modalSpinner) modalSpinner.hidden = !isLoading;
        if (modalStatus) modalStatus.textContent = message;
        if (modalRecovery) {
            modalRecovery.hidden = isLoading || isReady;
            modalRecovery.style.display = (isLoading || isReady) ? 'none' : 'flex';
        }
    }

    function startModalIframeAttempt() {
        if (!iframeEl || !modalCurrentUrl) return;
        if (modalAttemptCleanup) {
            modalAttemptCleanup();
            modalAttemptCleanup = null;
        }
        if (modalOpenDirect) modalOpenDirect.href = modalCurrentUrl;
        modalAttemptNumber += 1;
        var attemptUrl = modalAttemptNumber > 1 ? buildRetryUrl(modalCurrentUrl) : modalCurrentUrl;
        modalAttemptCleanup = watchQuizIframe({
            iframe: iframeEl,
            url: attemptUrl,
            navigate: true,
            onLoading: function () { setModalIframeState('loading', loadingLabel); },
            onReady: function () { setModalIframeState('ready', readyLabel); },
            onError: function () { setModalIframeState('error', loadErrorLabel); },
            onTimeout: function () { setModalIframeState('timeout', loadTimeoutLabel); }
        });
    }

    function isEditableTarget(target) {
        if (!target || typeof target.closest !== 'function') return false;
        if (target.isContentEditable) return true;
        if (target.closest('[contenteditable=""], [contenteditable="true"], [contenteditable="plaintext-only"], textarea')) {
            return true;
        }
        var input = target.closest('input');
        if (!input || input.disabled || input.readOnly) return false;
        var type = String(input.type || 'text').toLowerCase();
        return ['button', 'submit', 'reset', 'checkbox', 'radio', 'range', 'color', 'file', 'image', 'hidden'].indexOf(type) === -1;
    }

    function pushModalHistoryState() {
        if (!window.history || typeof window.history.pushState !== 'function') return false;
        try {
            var currentState = (window.history.state && typeof window.history.state === 'object')
                ? Object.assign({}, window.history.state)
                : {};
            currentState.llQuizModalOpen = true;
            window.history.pushState(currentState, document.title, window.location.href);
            modalHistoryActive = true;
            return true;
        } catch (_) {
            return false;
        }
    }

    function consumeModalHistoryState() {
        if (!modalHistoryActive || !window.history || typeof window.history.back !== 'function') {
            modalHistoryActive = false;
            clearModalPopstateSuppression();
            return false;
        }
        modalHistoryActive = false;
        suppressNextPopstate = true;
        scheduleModalPopstateSuppressionReset();
        try {
            window.history.back();
            return true;
        } catch (_) {
            clearModalPopstateSuppression();
            return false;
        }
    }

    function armModalHistoryGuard() {
        if (!modalHistoryActive) {
            pushModalHistoryState();
        }
    }

    function disarmModalHistoryGuard(options) {
        var opts = (options && typeof options === 'object') ? options : {};
        if (opts.historyAlreadyHandled) {
            modalHistoryActive = false;
            clearModalPopstateSuppression();
            return;
        }
        consumeModalHistoryState();
    }

    function confirmModalClose(options) {
        var opts = (options && typeof options === 'object') ? options : {};
        var shouldClose = true;
        try {
            shouldClose = window.confirm(closeConfirm);
        } catch (_) {
            shouldClose = true;
        }
        if (shouldClose) {
            closeModal({ historyAlreadyHandled: !!opts.historyAlreadyHandled });
            return true;
        }
        if (opts.rearmHistory) {
            pushModalHistoryState();
        }
        return false;
    }

    function ensureModal() {
        if (overlayEl) return;

        overlayEl = document.createElement('div');
        overlayEl.className = 'll-quiz-overlay';
        overlayEl.setAttribute('aria-hidden', 'false');
        overlayEl.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:999999;';

        modalEl = document.createElement('div');
        modalEl.className = 'll-quiz-modal';
        modalEl.setAttribute('role', 'dialog');
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.setAttribute('aria-labelledby', 'll-quiz-modal-title');
        modalEl.setAttribute('aria-busy', 'true');
        modalEl.setAttribute('tabindex', '-1');
        modalEl.style.cssText = 'background:#111;color:#eee;width:min(1200px,95vw);height:min(800px,90vh);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;border:1px solid rgba(255,255,255,0.1);box-shadow:0 10px 40px rgba(0,0,0,.4);';

        var header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid rgba(255,255,255,.1);';
        var title = document.createElement('div');
        title.id = 'll-quiz-modal-title';
        title.textContent = defaultQuizTitle;
        title.style.cssText = 'font-weight:600';
        modalCloseButton = document.createElement('button');
        modalCloseButton.type = 'button';
        modalCloseButton.setAttribute('aria-label', closeLabel);
        modalCloseButton.setAttribute('title', closeLabel);
        modalCloseButton.style.cssText = 'border:0;background:transparent;color:#eee;font-size:18px;cursor:pointer;padding:6px 8px;display:inline-flex;align-items:center;justify-content:center;position:relative;line-height:1;';
        var closeIcon = document.createElement('span');
        closeIcon.setAttribute('aria-hidden', 'true');
        closeIcon.textContent = '×';
        var closeLabelText = document.createElement('span');
        closeLabelText.textContent = closeLabel;
        closeLabelText.style.cssText = 'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);clip-path:inset(50%);white-space:nowrap;border:0;';
        modalCloseButton.appendChild(closeIcon);
        modalCloseButton.appendChild(closeLabelText);
        modalCloseButton.addEventListener('click', closeModal);
        header.appendChild(title);
        header.appendChild(modalCloseButton);

        var frameWrap = document.createElement('div');
        frameWrap.className = 'll-quiz-modal-frame-wrap';
        frameWrap.style.cssText = 'position:relative;display:flex;flex:1;min-height:0;';

        iframeEl = document.createElement('iframe');
        iframeEl.className = 'll-quiz-iframe';
        iframeEl.setAttribute('loading', 'eager');
        iframeEl.setAttribute('title', iframeTitle);
        iframeEl.setAttribute('aria-describedby', 'll-quiz-modal-status');
        iframeEl.setAttribute('aria-busy', 'true');
        iframeEl.style.cssText = 'flex:1;width:100%;border:0;background:#000;';

        modalStatePanel = document.createElement('div');
        modalStatePanel.className = 'll-quiz-frame-state ll-quiz-frame-state--loading';
        modalStatePanel.style.cssText = 'position:absolute;inset:0;z-index:2;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:24px;text-align:center;background:rgba(8,15,28,.94);color:#f8fafc;';
        modalSpinner = document.createElement('div');
        modalSpinner.className = 'll-quiz-frame-spinner';
        modalSpinner.setAttribute('aria-hidden', 'true');
        modalSpinner.style.cssText = 'width:40px;height:40px;border:4px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;';
        modalStatus = document.createElement('div');
        modalStatus.id = 'll-quiz-modal-status';
        modalStatus.setAttribute('role', 'status');
        modalStatus.setAttribute('aria-live', 'polite');
        modalStatus.setAttribute('aria-atomic', 'true');
        modalStatus.textContent = loadingLabel;
        modalRecovery = document.createElement('div');
        modalRecovery.className = 'll-quiz-frame-recovery';
        modalRecovery.hidden = true;
        modalRecovery.style.cssText = 'display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:10px;';
        modalRetryButton = document.createElement('button');
        modalRetryButton.type = 'button';
        modalRetryButton.className = 'll-quiz-frame-retry';
        modalRetryButton.textContent = retryLabel;
        modalRetryButton.addEventListener('click', startModalIframeAttempt);
        modalOpenDirect = document.createElement('a');
        modalOpenDirect.className = 'll-quiz-frame-open-direct';
        modalOpenDirect.target = '_blank';
        modalOpenDirect.rel = 'noopener noreferrer';
        modalOpenDirect.textContent = openDirectLabel;
        modalRecovery.appendChild(modalRetryButton);
        modalRecovery.appendChild(modalOpenDirect);
        modalStatePanel.appendChild(modalSpinner);
        modalStatePanel.appendChild(modalStatus);
        modalStatePanel.appendChild(modalRecovery);
        frameWrap.appendChild(iframeEl);
        frameWrap.appendChild(modalStatePanel);

        modalEl.appendChild(header);
        modalEl.appendChild(frameWrap);
        overlayEl.appendChild(modalEl);

        overlayEl.addEventListener('click', function (e) {
            if (e.target === overlayEl) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (!isModalOpen()) return;
            if (e.key === 'Tab') {
                trapModalTab(e);
                return;
            }
            if (e.key === 'Escape') {
                closeModal();
                return;
            }
            if (e.key === 'Backspace' && !e.defaultPrevented && !isEditableTarget(e.target)) {
                e.preventDefault();
                confirmModalClose();
            }
        });
        document.addEventListener('focusin', function (e) {
            if (!isModalOpen() || !modalEl || modalEl.contains(e.target)) return;
            focusModalStart();
        });
        window.addEventListener('popstate', function () {
            if (suppressNextPopstate) {
                clearModalPopstateSuppression();
                return;
            }
            if (!isModalOpen()) {
                modalHistoryActive = false;
                return;
            }
            confirmModalClose({ historyAlreadyHandled: true, rearmHistory: true });
        });
    }

    function openModal(url, titleTxt) {
        ensureModal();
        lastFocus = document.activeElement;
        modalCurrentUrl = String(url || '');
        modalAttemptNumber = 0;
        var titleEl = modalEl.querySelector('#ll-quiz-modal-title');
        if (titleEl) titleEl.textContent = titleTxt || defaultQuizTitle;
        document.body.appendChild(overlayEl);
        isolateModalBackground();
        startModalIframeAttempt();
        focusModalStart();
        armModalHistoryGuard();
    }

    function closeModal(options) {
        if (!overlayEl) return;
        disarmModalHistoryGuard(options);
        if (modalAttemptCleanup) {
            modalAttemptCleanup();
            modalAttemptCleanup = null;
        }
        if (overlayEl.parentNode) overlayEl.parentNode.removeChild(overlayEl);
        if (iframeEl) iframeEl.src = 'about:blank';
        restoreModalBackground();
        if (lastFocus && lastFocus.isConnected && typeof lastFocus.focus === 'function') {
            try {
                lastFocus.focus({ preventScroll: true });
            } catch (_) {
                try { lastFocus.focus(); } catch (_ignore) { /* no-op */ }
            }
        }
        lastFocus = null;
    }

    // -------------------------
    // URL building fallback
    // -------------------------
    function buildFallbackUrl(catName) {
        var basePath = '/quiz';
        var sep = basePath.indexOf('?') === -1 ? '?' : '&';
        return basePath + sep + 'category=' + encodeURIComponent(catName || '');
    }

    // -------------------------
    // Public API (global) — if absent
    // -------------------------
    if (typeof window.llOpenFlashcardForCategory !== 'function') {
        window.llOpenFlashcardForCategory = function (catName, opts) {
            // If a URL is provided via opts, prefer it; otherwise construct a fallback.
            var url = (opts && opts.url) ? String(opts.url) : buildFallbackUrl(catName);
            openModal(url, catName || defaultQuizTitle);
        };
    }

    function parseBooleanAttr(raw, fallback) {
        if (raw === null || typeof raw === 'undefined') {
            return !!fallback;
        }
        var normalized = String(raw || '').trim().toLowerCase();
        if (normalized === '1' || normalized === 'true' || normalized === 'yes' || normalized === 'on') {
            return true;
        }
        if (normalized === '0' || normalized === 'false' || normalized === 'no' || normalized === 'off') {
            return false;
        }
        return !!fallback;
    }

    function parseWordIdList(raw) {
        var values = [];
        if (Array.isArray(raw)) {
            values = raw;
        } else if (typeof raw === 'string' && raw.trim() !== '') {
            var trimmed = raw.trim();
            if (trimmed.charAt(0) === '[') {
                try {
                    var parsed = JSON.parse(trimmed);
                    if (Array.isArray(parsed)) {
                        values = parsed;
                    }
                } catch (_) {
                    values = [];
                }
            }
            if (!values.length) {
                values = trimmed.split(/[\s,|]+/);
            }
        }

        var seen = {};
        return values.map(function (value) {
            return parseInt(value, 10) || 0;
        }).filter(function (id) {
            if (id <= 0 || seen[id]) {
                return false;
            }
            seen[id] = true;
            return true;
        });
    }

    // -------------------------
    // Delegated click handler
    // -------------------------
    document.addEventListener('click', function (ev) {
        var trigger = ev.target.closest('.ll-quiz-page-trigger,[data-ll-open-cat],[data-category]');
        if (!trigger) return;

        // Determine if this is meant to be a popup trigger:
        // - has class ll-quiz-page-trigger, or
        // - href is "#" / empty / javascript:
        var href = (trigger.getAttribute('href') || '').trim();
        var explicitPopup = trigger.classList.contains('ll-quiz-page-trigger');
        var looksPopup = explicitPopup || !href || href === '#' || href.toLowerCase().startsWith('javascript:');
        if (!looksPopup) return; // allow normal navigation for non-popup links

        ev.preventDefault();

        var cat = trigger.getAttribute('data-ll-open-cat') || trigger.getAttribute('data-category') || '';
        var url = trigger.getAttribute('data-url'); // NEW: prefer real permalink if provided
        var title = trigger.querySelector('.ll-quiz-page-name')?.textContent?.trim() || cat || defaultQuizTitle;

        try {
            // If some other script replaced the global with a custom one, still call it:
            var opts = { url: url, triggerEl: trigger };
            var isVocabLessonTrigger = !!(
                trigger.classList.contains('ll-vocab-lesson-mode-button') ||
                trigger.closest('[data-ll-vocab-lesson], .ll-vocab-lesson-page')
            );
            opts.launchContext = isVocabLessonTrigger ? 'vocab_lesson' : 'quiz_pages';
            var mode = trigger.getAttribute('data-mode') || '';
            var wordsetId = trigger.getAttribute('data-wordset-id') || '';
            var wordset = trigger.getAttribute('data-wordset') || '';
            var displayMode = trigger.getAttribute('data-display-mode') || '';
            var promptType = trigger.getAttribute('data-prompt-type') || '';
            var optionType = trigger.getAttribute('data-option-type') || '';
            var orderedWordIds = parseWordIdList(trigger.getAttribute('data-ordered-word-ids') || '');
            var preserveWordOrderAttr = trigger.getAttribute('data-preserve-word-order');
            if (mode) opts.mode = mode;
            if (wordsetId) opts.wordsetId = wordsetId;
            else if (wordset) opts.wordset = wordset;
            if (displayMode) {
                opts.displayMode = displayMode;
                opts.display_mode = displayMode;
            }
            if (promptType) {
                opts.promptType = promptType;
                opts.prompt_type = promptType;
            }
            if (optionType) {
                opts.optionType = optionType;
                opts.option_type = optionType;
            }
            if (orderedWordIds.length) {
                opts.orderedWordIds = orderedWordIds.slice();
                opts.ordered_word_ids = orderedWordIds.slice();
                opts.sessionWordIds = orderedWordIds.slice();
                opts.session_word_ids = orderedWordIds.slice();
            }
            if (preserveWordOrderAttr !== null || orderedWordIds.length) {
                opts.preserveWordOrder = parseBooleanAttr(preserveWordOrderAttr, orderedWordIds.length > 0 && mode === 'listening');
                opts.preserve_word_order = opts.preserveWordOrder;
                opts.preserveCategoryOrder = opts.preserveWordOrder;
                opts.preserve_category_order = opts.preserveWordOrder;
            }
            window.llOpenFlashcardForCategory(cat, opts);
        } catch (e) {
            // Ultimate fallback: navigate
            window.location.href = url || buildFallbackUrl(cat);
        }
    }, false);

    // Remove leaked inline JS (if a security filter stripped the <script> tag and left the text behind)
    function stripInlineQuizCodeLeak(wrapper) {
        var scope = (wrapper && wrapper.parentNode) ? wrapper.parentNode : document.body;
        if (!scope || !scope.ownerDocument || !scope.ownerDocument.createTreeWalker || !window.NodeFilter) return;

        var walker = scope.ownerDocument.createTreeWalker(scope, NodeFilter.SHOW_TEXT, null, false);
        var toRemove = [];
        while (walker.nextNode()) {
            var node = walker.currentNode;
            if (node && typeof node.nodeValue === 'string' && node.nodeValue.indexOf('ll-tools-quiz-iframe-wrapper') !== -1) {
                toRemove.push(node);
            }
        }

        toRemove.forEach(function (node) {
            var parent = node.parentNode;
            if (!parent) return;
            parent.removeChild(node);
            if (parent.childNodes.length === 0 && parent.parentNode) {
                parent.parentNode.removeChild(parent);
            }
        });
    }

    // --- Recoverable state control for quiz pages that render an <iframe> ---
    function wireQuizIframeSpinner() {
        var wrapper = document.querySelector('.ll-tools-quiz-iframe-wrapper');
        if (!wrapper) return; // Not on a quiz page
        if (wrapper.getAttribute('data-ll-iframe-wired') === '1') return;
        wrapper.setAttribute('data-ll-iframe-wired', '1');

        stripInlineQuizCodeLeak(wrapper);

        var iframe = wrapper.querySelector('.ll-tools-quiz-iframe');
        var spinner = wrapper.querySelector('.ll-tools-iframe-loading');
        var loadingStatus = wrapper.querySelector('.ll-tools-iframe-loading-status');
        if (!iframe || !spinner) return;
        var statePanel = wrapper.querySelector('.ll-tools-iframe-state');
        if (!statePanel) {
            statePanel = document.createElement('div');
            statePanel.className = 'll-tools-iframe-state';
            spinner.parentNode.insertBefore(statePanel, spinner);
            statePanel.appendChild(spinner);
            if (loadingStatus) statePanel.appendChild(loadingStatus);
        }
        if (!loadingStatus) {
            loadingStatus = document.createElement('div');
            loadingStatus.className = 'll-tools-iframe-loading-status';
            loadingStatus.setAttribute('role', 'status');
            loadingStatus.setAttribute('aria-live', 'polite');
            loadingStatus.setAttribute('aria-atomic', 'true');
            statePanel.appendChild(loadingStatus);
        }
        loadingStatus.classList.remove('screen-reader-text');

        var recovery = statePanel.querySelector('.ll-tools-iframe-recovery');
        if (!recovery) {
            recovery = document.createElement('div');
            recovery.className = 'll-tools-iframe-recovery';
            recovery.hidden = true;
            statePanel.appendChild(recovery);
        }
        var retryButton = recovery.querySelector('.ll-tools-iframe-retry');
        if (!retryButton) {
            retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.className = 'll-tools-iframe-retry';
            retryButton.textContent = retryLabel;
            recovery.appendChild(retryButton);
        }
        var openDirect = recovery.querySelector('.ll-tools-iframe-open-direct');
        if (!openDirect) {
            openDirect = document.createElement('a');
            openDirect.className = 'll-tools-iframe-open-direct';
            openDirect.target = '_blank';
            openDirect.rel = 'noopener noreferrer';
            openDirect.textContent = openDirectLabel;
            recovery.appendChild(openDirect);
        }

        var sourceUrl = String(wrapper.getAttribute('data-quiz-src') || iframe.getAttribute('src') || iframe.src || '');
        openDirect.href = sourceUrl;
        var attemptCleanup = null;
        var attemptNumber = 0;

        function setState(state, message) {
            var isLoading = state === 'loading';
            var isReady = state === 'ready';
            wrapper.setAttribute('data-iframe-state', state);
            wrapper.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            iframe.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            setQuizIframeInteractive(iframe, isReady);
            statePanel.className = 'll-tools-iframe-state ll-tools-iframe-state--' + state;
            spinner.hidden = !isLoading;
            loadingStatus.textContent = message;
            recovery.hidden = isLoading || isReady;
        }

        function startAttempt(navigate) {
            if (attemptCleanup) {
                attemptCleanup();
                attemptCleanup = null;
            }
            attemptNumber += 1;
            var attemptUrl = attemptNumber > 1 ? buildRetryUrl(sourceUrl) : sourceUrl;
            attemptCleanup = watchQuizIframe({
                iframe: iframe,
                url: attemptUrl,
                navigate: navigate,
                onLoading: function () { setState('loading', loadingLabel); },
                onReady: function () { setState('ready', readyLabel); },
                onError: function () { setState('error', loadErrorLabel); },
                onTimeout: function () { setState('timeout', loadTimeoutLabel); }
            });
        }

        retryButton.addEventListener('click', function () {
            startAttempt(true);
        });
        startAttempt(false);
    }

    // Call it on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wireQuizIframeSpinner);
    } else {
        wireQuizIframeSpinner();
    }

})();
