(function ($) {
    'use strict';

    const cfg = window.llToolsWordGridData || {};
    const ajaxUrl = cfg.ajaxUrl || '';
    const nonce = cfg.nonce || '';
    const i18n = cfg.i18n || {};
    const editI18n = cfg.editI18n || {};
    const orderI18n = cfg.orderI18n || {};
    const bulkI18n = cfg.bulkI18n || {};
    const prereqI18n = cfg.prereqI18n || {};
    const transcribeI18n = cfg.transcribeI18n || {};
    const internalNotesCfg = (cfg.internalNotes && typeof cfg.internalNotes === 'object') ? cfg.internalNotes : {};
    const internalNotesI18n = (internalNotesCfg.i18n && typeof internalNotesCfg.i18n === 'object') ? internalNotesCfg.i18n : {};
    const internalNotesEnabled = !!internalNotesCfg.enabled && !!internalNotesCfg.nonce && !!ajaxUrl;
    const internalNoteSaveDelayMs = Math.max(1500, parseInt(internalNotesCfg.saveDelayMs, 10) || 3000);
    const transcribePollAttemptsRaw = parseInt(cfg.transcribePollAttempts, 10);
    const transcribePollIntervalRaw = parseInt(cfg.transcribePollIntervalMs, 10);
    const transcribePollAttempts = Number.isFinite(transcribePollAttemptsRaw) && transcribePollAttemptsRaw > 0
        ? transcribePollAttemptsRaw
        : 20;
    const transcribePollIntervalMs = Number.isFinite(transcribePollIntervalRaw) && transcribePollIntervalRaw >= 250
        ? transcribePollIntervalRaw
        : 1200;
    const transcribeProvider = String(cfg.transcribeProvider || '').trim();
    const transcribeTargetField = String(cfg.transcribeTargetField || 'recording_text').trim() === 'recording_ipa'
        ? 'recording_ipa'
        : 'recording_text';
    const transcribeLocalEndpoint = String(cfg.transcribeLocalEndpoint || '').trim();
    const transcribeUsesLocalBrowser = !!cfg.transcribeUsesLocalBrowser && transcribeProvider === 'local_browser' && !!transcribeLocalEndpoint;
    const secondaryTextMode = ['ipa', 'transliteration', 'transcription'].indexOf(String(cfg.secondaryTextMode || '').trim()) >= 0
        ? String(cfg.secondaryTextMode || '').trim()
        : 'ipa';
    const secondaryTextDisplayFormat = String(cfg.secondaryTextDisplayFormat || (secondaryTextMode === 'ipa' ? 'brackets' : 'plain')).trim();
    const secondaryTextCommonChars = Array.isArray(cfg.secondaryTextCommonChars) ? cfg.secondaryTextCommonChars.slice() : [];
    const secondaryTextModifierChars = secondaryTextMode === 'ipa'
        ? (Array.isArray(cfg.secondaryTextModifierChars) && cfg.secondaryTextModifierChars.length ? cfg.secondaryTextModifierChars.slice() : ['ʰ', 'ʲ', 'ʷ', 'ː'])
        : [];
    const secondaryTextCompactModifierChars = secondaryTextModifierChars.filter(function (ch) {
        return ch !== '\u0361';
    });
    const secondaryTextKeyboardGroups = Array.isArray(cfg.secondaryTextKeyboardGroups) ? cfg.secondaryTextKeyboardGroups.slice() : [];
    const secondaryTextSymbolDetails = (cfg.secondaryTextSymbolDetails && typeof cfg.secondaryTextSymbolDetails === 'object')
        ? cfg.secondaryTextSymbolDetails
        : {};
    const secondaryTextUsesIpaFont = !!cfg.secondaryTextUsesIpaFont;
    const secondaryTextSupportsSuperscript = !!cfg.secondaryTextSupportsSuperscript && secondaryTextMode === 'ipa';
    const ipaSpecialChars = Array.isArray(cfg.ipaSpecialChars) ? cfg.ipaSpecialChars.slice() : [];
    const ipaLetterMap = (cfg.ipaLetterMap && typeof cfg.ipaLetterMap === 'object') ? cfg.ipaLetterMap : {};
    const ipaTextLanguageCode = String(cfg.ipaTextLanguageCode || '').trim().toLowerCase();
    const isLoggedIn = !!cfg.isLoggedIn;
    const canEdit = !!cfg.canEdit;
    const editNonce = cfg.editNonce || '';
    const supportsIpaExtended = cfg.supportsIpaExtended !== false;
    const state = Object.assign({
        wordset_id: 0,
        category_ids: [],
        starred_word_ids: [],
        star_mode: 'normal',
        fast_transitions: false
    }, cfg.state || {});

    const $grids = $('[data-ll-word-grid]');
    if (!$grids.length) { return; }

    const WORD_GRID_IMAGE_SELECTOR = '.word-image-container img.word-image';
    const WORD_GRID_IMAGE_WRAPPER_SELECTOR = '.word-image-container';
    const ANIMATED_WEBP_IMAGE_SELECTOR = 'img[data-ll-animated-webp="1"]';
    const LESSON_ORDER_HANDLE_SELECTOR = '[data-ll-word-grid-order-handle]';
    const LESSON_ORDER_VIEWPORT_SCROLL_EDGE_PX = 96;
    const LESSON_ORDER_VIEWPORT_SCROLL_MIN_STEP_PX = 3;
    const LESSON_ORDER_VIEWPORT_SCROLL_MAX_STEP_PX = 11;
    const EDITABLE_INPUT_SELECTOR = 'input[data-ll-word-input], textarea[data-ll-word-input], select[data-ll-word-input], input[data-ll-word-category-input], input[data-ll-recording-input], select[data-ll-recording-input]';
    const wordGridImageObservers = [];

    function shouldRequireLessonOrderHandle() {
        const nav = (typeof window !== 'undefined' && window.navigator) ? window.navigator : null;
        if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
            try {
                if (window.matchMedia('(pointer: coarse)').matches) {
                    return true;
                }
                if (nav && Number(nav.maxTouchPoints || 0) > 0 && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
                    return true;
                }
            } catch (_error) {
                return !!(nav && Number(nav.maxTouchPoints || 0) > 0);
            }
        }

        return !!(nav && Number(nav.maxTouchPoints || 0) > 0);
    }

    function getLessonOrderTouchByIdentifier(touches, identifier) {
        if (!touches) { return null; }
        for (let i = 0; i < touches.length; i += 1) {
            if (touches[i] && touches[i].identifier === identifier) {
                return touches[i];
            }
        }
        return null;
    }

    function getLessonOrderPrimaryTouch(event, activeIdentifier) {
        if (!event) { return null; }
        if (typeof activeIdentifier !== 'undefined' && activeIdentifier !== null) {
            return getLessonOrderTouchByIdentifier(event.changedTouches, activeIdentifier)
                || getLessonOrderTouchByIdentifier(event.touches, activeIdentifier);
        }
        if (event.changedTouches && event.changedTouches.length) {
            return event.changedTouches[0];
        }
        if (event.touches && event.touches.length) {
            return event.touches[0];
        }
        return null;
    }

    function dispatchLessonOrderMouseEvent(type, touch, target) {
        if (!touch || !target || typeof target.dispatchEvent !== 'function') { return; }
        const event = new MouseEvent(type, {
            bubbles: true,
            cancelable: true,
            view: window,
            detail: 1,
            screenX: Number(touch.screenX) || 0,
            screenY: Number(touch.screenY) || 0,
            clientX: Number(touch.clientX) || 0,
            clientY: Number(touch.clientY) || 0,
            button: 0,
            buttons: type === 'mouseup' ? 0 : 1
        });
        target.dispatchEvent(event);
    }

    function clearLessonOrderTouchBridge($grid) {
        const grid = $grid && $grid.length ? $grid.get(0) : null;
        if (!grid || typeof grid.__llLessonOrderTouchBridgeCleanup !== 'function') { return; }
        grid.__llLessonOrderTouchBridgeCleanup();
        grid.__llLessonOrderTouchBridgeCleanup = null;
    }

    function bindLessonOrderTouchBridge($grid) {
        const grid = $grid && $grid.length ? $grid.get(0) : null;
        if (!grid || typeof grid.addEventListener !== 'function') { return; }
        clearLessonOrderTouchBridge($grid);

        let active = null;

        const finish = function (event) {
            if (!active) { return; }
            const touch = getLessonOrderPrimaryTouch(event, active.identifier);
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            dispatchLessonOrderMouseEvent('mouseup', touch || active.lastTouch, active.target);
            dispatchLessonOrderMouseEvent('mouseout', touch || active.lastTouch, active.target);
            active = null;
        };

        const onTouchStart = function (event) {
            if (!event || !event.target || (event.touches && event.touches.length > 1) || active) {
                return;
            }
            const handle = event.target.closest ? event.target.closest(LESSON_ORDER_HANDLE_SELECTOR) : null;
            if (!handle || !grid.contains(handle)) {
                return;
            }
            const touch = getLessonOrderPrimaryTouch(event, null);
            if (!touch) { return; }

            active = {
                identifier: touch.identifier,
                target: handle,
                lastTouch: touch
            };
            event.preventDefault();
            dispatchLessonOrderMouseEvent('mouseover', touch, handle);
            dispatchLessonOrderMouseEvent('mousemove', touch, handle);
            dispatchLessonOrderMouseEvent('mousedown', touch, handle);
        };

        const onTouchMove = function (event) {
            if (!active) { return; }
            const touch = getLessonOrderPrimaryTouch(event, active.identifier);
            if (!touch) { return; }
            active.lastTouch = touch;
            event.preventDefault();
            dispatchLessonOrderMouseEvent('mousemove', touch, active.target);
        };

        grid.addEventListener('touchstart', onTouchStart, { passive: false });
        grid.addEventListener('touchmove', onTouchMove, { passive: false });
        grid.addEventListener('touchend', finish, { passive: false });
        grid.addEventListener('touchcancel', finish, { passive: false });

        grid.__llLessonOrderTouchBridgeCleanup = function () {
            grid.removeEventListener('touchstart', onTouchStart);
            grid.removeEventListener('touchmove', onTouchMove);
            grid.removeEventListener('touchend', finish);
            grid.removeEventListener('touchcancel', finish);
            active = null;
        };
    }

    function readLessonOrderPointerClientY(event) {
        const source = event && event.originalEvent ? event.originalEvent : event;
        if (!source) { return null; }

        if (typeof source.clientY === 'number') {
            return source.clientY;
        }

        const touch = getLessonOrderPrimaryTouch(source, null);
        if (touch && typeof touch.clientY === 'number') {
            return touch.clientY;
        }

        return null;
    }

    function getLessonOrderViewportHeight() {
        return window.innerHeight || document.documentElement.clientHeight || 0;
    }

    function getLessonOrderScrollTop() {
        return window.pageYOffset
            || document.documentElement.scrollTop
            || (document.body ? document.body.scrollTop : 0)
            || 0;
    }

    function getLessonOrderMaxScrollTop() {
        const viewportHeight = getLessonOrderViewportHeight();
        const doc = document.documentElement;
        const body = document.body;
        const scrollHeight = Math.max(
            doc ? doc.scrollHeight : 0,
            body ? body.scrollHeight : 0
        );
        return Math.max(0, scrollHeight - viewportHeight);
    }

    function getLessonOrderViewportScrollStep(pointerClientY) {
        const viewportHeight = getLessonOrderViewportHeight();
        if (typeof pointerClientY !== 'number' || !viewportHeight) {
            return 0;
        }

        const edgeSize = Math.min(
            LESSON_ORDER_VIEWPORT_SCROLL_EDGE_PX,
            Math.max(48, Math.round(viewportHeight * 0.2))
        );
        let direction = 0;
        let pressure = 0;

        if (pointerClientY < edgeSize) {
            direction = -1;
            pressure = (edgeSize - pointerClientY) / edgeSize;
        } else if (pointerClientY > viewportHeight - edgeSize) {
            direction = 1;
            pressure = (pointerClientY - (viewportHeight - edgeSize)) / edgeSize;
        }

        if (!direction) {
            return 0;
        }

        const scrollTop = getLessonOrderScrollTop();
        if ((direction < 0 && scrollTop <= 0) || (direction > 0 && scrollTop >= getLessonOrderMaxScrollTop())) {
            return 0;
        }

        const boundedPressure = Math.max(0, Math.min(1, pressure));
        const step = LESSON_ORDER_VIEWPORT_SCROLL_MIN_STEP_PX
            + ((LESSON_ORDER_VIEWPORT_SCROLL_MAX_STEP_PX - LESSON_ORDER_VIEWPORT_SCROLL_MIN_STEP_PX) * boundedPressure);
        return direction * Math.round(step);
    }

    function getLessonOrderViewportAutoScrollState($grid) {
        const grid = $grid && $grid.length ? $grid.get(0) : null;
        if (!grid) { return null; }

        if (!grid.__llLessonOrderViewportAutoScrollState) {
            grid.__llLessonOrderViewportAutoScrollState = {
                active: false,
                pointerClientY: null,
                frameId: 0,
                frameIsTimeout: false
            };
        }

        return grid.__llLessonOrderViewportAutoScrollState;
    }

    function cancelLessonOrderViewportAutoScrollFrame(state) {
        if (!state || !state.frameId) { return; }

        if (state.frameIsTimeout) {
            window.clearTimeout(state.frameId);
        } else if (typeof window.cancelAnimationFrame === 'function') {
            window.cancelAnimationFrame(state.frameId);
        }

        state.frameId = 0;
        state.frameIsTimeout = false;
    }

    function queueLessonOrderViewportAutoScrollFrame(state, callback) {
        if (!state || state.frameId) { return; }

        if (typeof window.requestAnimationFrame === 'function') {
            state.frameIsTimeout = false;
            state.frameId = window.requestAnimationFrame(callback);
            return;
        }

        state.frameIsTimeout = true;
        state.frameId = window.setTimeout(callback, 16);
    }

    function refreshLessonOrderSortablePositions($grid) {
        if (!$grid || !$grid.length || typeof $grid.sortable !== 'function') {
            return;
        }

        try {
            $grid.sortable('refreshPositions');
        } catch (_error) {}
    }

    function scheduleLessonOrderViewportAutoScroll($grid) {
        const state = getLessonOrderViewportAutoScrollState($grid);
        if (!state || !state.active) { return; }

        queueLessonOrderViewportAutoScrollFrame(state, function () {
            state.frameId = 0;
            state.frameIsTimeout = false;

            if (!state.active) { return; }

            const step = getLessonOrderViewportScrollStep(state.pointerClientY);
            if (step) {
                const before = getLessonOrderScrollTop();
                window.scrollBy(0, step);
                if (Math.abs(getLessonOrderScrollTop() - before) > 0.5) {
                    refreshLessonOrderSortablePositions($grid);
                }
            }

            scheduleLessonOrderViewportAutoScroll($grid);
        });
    }

    function startLessonOrderViewportAutoScroll($grid, event) {
        const state = getLessonOrderViewportAutoScrollState($grid);
        if (!state) { return; }

        const pointerClientY = readLessonOrderPointerClientY(event);
        if (typeof pointerClientY === 'number') {
            state.pointerClientY = pointerClientY;
        }

        state.active = true;
        scheduleLessonOrderViewportAutoScroll($grid);
    }

    function updateLessonOrderViewportAutoScroll($grid, event) {
        const state = getLessonOrderViewportAutoScrollState($grid);
        if (!state || !state.active) { return; }

        const pointerClientY = readLessonOrderPointerClientY(event);
        if (typeof pointerClientY === 'number') {
            state.pointerClientY = pointerClientY;
        }

        scheduleLessonOrderViewportAutoScroll($grid);
    }

    function stopLessonOrderViewportAutoScroll($grid) {
        const state = getLessonOrderViewportAutoScrollState($grid);
        if (!state) { return; }

        state.active = false;
        state.pointerClientY = null;
        cancelLessonOrderViewportAutoScrollFrame(state);
    }

    function setWordGridImagePending(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        img.classList.remove('ll-image-loaded');
        img.classList.add('ll-image-load-pending');
        const container = img.closest(WORD_GRID_IMAGE_WRAPPER_SELECTOR);
        if (container) {
            container.classList.remove('ll-image-loaded');
            container.classList.add('ll-image-load-pending');
        }
    }

    function markWordGridImageLoaded(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        img.classList.remove('ll-image-load-pending');
        img.classList.add('ll-image-loaded');
        const container = img.closest(WORD_GRID_IMAGE_WRAPPER_SELECTOR);
        if (container) {
            container.classList.remove('ll-image-load-pending');
            container.classList.add('ll-image-loaded');
        }
    }

    function bindWordGridImageLoadState(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        if (img.dataset.llImgLoadBound === '1') {
            if (img.complete) {
                markWordGridImageLoaded(img);
            }
            return;
        }
        img.dataset.llImgLoadBound = '1';
        if (img.complete) {
            markWordGridImageLoaded(img);
            return;
        }
        setWordGridImagePending(img);
        const finish = function () {
            markWordGridImageLoaded(img);
        };
        img.addEventListener('load', finish, { once: true });
        img.addEventListener('error', finish, { once: true });
    }

    function bindAnimatedWebpControl(img) {
        if (!img || img.nodeType !== 1 || img.tagName !== 'IMG') { return; }
        if (img.dataset.llAnimatedWebpBound === '1') { return; }

        const state = {
            originalSrc: img.getAttribute('src') || '',
            originalSrcset: img.getAttribute('srcset') || '',
            originalSizes: img.getAttribute('sizes') || '',
            posterSrc: '',
            active: false,
            pinned: false,
            capturing: false
        };
        if (!state.originalSrc) { return; }

        img.dataset.llAnimatedWebpBound = '1';
        img.classList.add('ll-animated-webp-image');

        const restoreAnimatedSource = function () {
            if (img.getAttribute('src') !== state.originalSrc) {
                img.setAttribute('src', state.originalSrc);
            }
            if (state.originalSrcset) {
                img.setAttribute('srcset', state.originalSrcset);
            }
            if (state.originalSizes) {
                img.setAttribute('sizes', state.originalSizes);
            }
            img.classList.add('ll-animated-webp-active');
            img.classList.remove('ll-animated-webp-paused');
        };

        const showPosterSource = function () {
            if (!state.posterSrc) { return; }
            img.removeAttribute('srcset');
            img.removeAttribute('sizes');
            if (img.getAttribute('src') !== state.posterSrc) {
                img.setAttribute('src', state.posterSrc);
            }
            img.classList.add('ll-animated-webp-paused');
            img.classList.remove('ll-animated-webp-active');
        };

        const setAnimatedActive = function (active) {
            state.active = !!active;
            if (state.active) {
                restoreAnimatedSource();
            } else {
                showPosterSource();
            }
        };

        const capturePoster = function () {
            if (state.posterSrc || state.capturing || !img.complete || !img.naturalWidth || !img.naturalHeight) {
                return;
            }
            if ((img.getAttribute('src') || '') === state.posterSrc) {
                return;
            }

            state.capturing = true;
            try {
                const maxEdge = 720;
                const scale = Math.min(1, maxEdge / Math.max(img.naturalWidth, img.naturalHeight));
                const width = Math.max(1, Math.round(img.naturalWidth * scale));
                const height = Math.max(1, Math.round(img.naturalHeight * scale));
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                if (!ctx) { return; }
                ctx.drawImage(img, 0, 0, width, height);
                state.posterSrc = canvas.toDataURL('image/webp', 0.86);
                if (!state.active) {
                    showPosterSource();
                }
            } catch (_error) {
                state.posterSrc = '';
            } finally {
                state.capturing = false;
            }
        };

        const scheduleCapture = function () {
            if (state.posterSrc) { return; }
            window.setTimeout(capturePoster, 0);
        };

        if (img.complete) {
            scheduleCapture();
        } else {
            img.addEventListener('load', scheduleCapture, { once: true });
        }

        const activateOnPointer = function () {
            setAnimatedActive(true);
        };
        const pauseOnPointerLeave = function () {
            if (!state.pinned) {
                setAnimatedActive(false);
            }
        };
        ['mouseenter', 'pointerenter', 'mouseover'].forEach(function (eventName) {
            img.addEventListener(eventName, activateOnPointer);
        });
        ['mouseleave', 'pointerleave', 'mouseout'].forEach(function (eventName) {
            img.addEventListener(eventName, pauseOnPointerLeave);
        });
        img.addEventListener('focus', function () {
            setAnimatedActive(true);
        });
        img.addEventListener('blur', function () {
            if (!state.pinned) {
                setAnimatedActive(false);
            }
        });
        img.addEventListener('click', function () {
            state.pinned = !state.pinned;
            setAnimatedActive(state.pinned);
        });
    }

    function scanWordGridImages(rootNode) {
        if (!rootNode || rootNode.nodeType !== 1) { return; }
        if (rootNode.matches && rootNode.matches(WORD_GRID_IMAGE_SELECTOR)) {
            bindWordGridImageLoadState(rootNode);
        }
        if (rootNode.querySelectorAll) {
            rootNode.querySelectorAll(WORD_GRID_IMAGE_SELECTOR).forEach(bindWordGridImageLoadState);
        }
        if (rootNode.matches && rootNode.matches(ANIMATED_WEBP_IMAGE_SELECTOR)) {
            bindAnimatedWebpControl(rootNode);
        }
        if (rootNode.querySelectorAll) {
            rootNode.querySelectorAll(ANIMATED_WEBP_IMAGE_SELECTOR).forEach(bindAnimatedWebpControl);
        }
    }

    function initWordGridImageLoadingState() {
        $grids.each(function () {
            scanWordGridImages(this);
            if (!window.MutationObserver) { return; }
            const observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (!mutation || !mutation.addedNodes) { return; }
                    mutation.addedNodes.forEach(function (node) {
                        if (!node || node.nodeType !== 1) { return; }
                        scanWordGridImages(node);
                    });
                });
            });
            observer.observe(this, { childList: true, subtree: true });
            wordGridImageObservers.push(observer);
        });
    }

    initWordGridImageLoadingState();

    let currentAudio = null;
    let currentAudioButton = null;

    let vizContext = null;
    let vizAnalyser = null;
    let vizAnalyserData = null;
    let vizTimeData = null;
    let vizAnalyserConnected = false;
    let vizRafId = null;
    let vizBars = [];
    let vizBarLevels = [];
    let vizButton = null;
    let vizAudio = null;
    let vizSource = null;
    let wordRecordingLaunchPending = false;

    function syncEditModalBodyLock() {
        const hasOpenPanel = $grids.find('[data-ll-word-edit-panel][aria-hidden="false"]').length > 0;
        $('body').toggleClass('ll-word-edit-modal-open', hasOpenPanel);
    }

    function protectMaqafNoBreak(value) {
        const text = (value === null || value === undefined) ? '' : String(value);
        if (!text) { return ''; }
        if (text.indexOf('\u05BE') === -1 && text.indexOf('\u2060') === -1) { return text; }
        return text.replace(/\u2060*\u05BE\u2060*/gu, '\u2060\u05BE\u2060');
    }

    function canUseVisualizerForUrl(url) {
        if (!url) { return false; }
        const value = String(url);
        if (value.indexOf('blob:') === 0 || value.indexOf('data:') === 0) {
            return true;
        }
        try {
            const target = new URL(value, window.location.href);
            return target.origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    function isPlainNavigationClick(event) {
        if (!event) { return true; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return false;
        }
        if (typeof event.which === 'number' && event.which > 0 && event.which !== 1) {
            return false;
        }
        if (typeof event.button === 'number' && event.button > 0) {
            return false;
        }
        return true;
    }

    function navigateAfterPaint(url) {
        const navigate = function () {
            window.location.assign(url);
        };
        const schedule = function () {
            window.setTimeout(navigate, 50);
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(schedule);
            return;
        }
        schedule();
    }

    function closeWordRecordingLaunchOverlay() {
        wordRecordingLaunchPending = false;
        $('body').removeClass('ll-word-recording-launch-overlay-open');
        $('#ll-word-recording-launch-overlay')
            .removeClass('is-active')
            .attr('aria-hidden', 'true')
            .prop('hidden', true);
    }

    function openWordRecordingLaunchOverlay(label) {
        const statusLabel = String(label || '').trim();
        let $overlay = $('#ll-word-recording-launch-overlay');
        if (!$overlay.length) {
            $overlay = $('<div>', {
                id: 'll-word-recording-launch-overlay',
                class: 'll-word-recording-launch-overlay',
                role: 'status',
                'aria-live': 'polite',
                'aria-hidden': 'true',
                hidden: true
            });
            const $inner = $('<div>', {
                class: 'll-word-recording-launch-overlay__inner'
            });
            $('<div>', {
                class: 'll-word-recording-launch-overlay__spinner',
                'aria-hidden': 'true'
            }).appendTo($inner);
            $('<span>', {
                class: 'screen-reader-text ll-word-recording-launch-overlay__label'
            }).appendTo($inner);
            $inner.appendTo($overlay);
            $overlay.appendTo('body');
        }

        $overlay
            .find('.ll-word-recording-launch-overlay__label')
            .text(statusLabel);
        const overlayAttrs = {
            'aria-hidden': 'false'
        };
        if (statusLabel) {
            overlayAttrs['aria-label'] = statusLabel;
        } else {
            $overlay.removeAttr('aria-label');
        }
        $overlay
            .attr(overlayAttrs)
            .prop('hidden', false)
            .addClass('is-active');
        $('body').addClass('ll-word-recording-launch-overlay-open');
    }

    function ensureVisualizerContext() {
        if (vizContext) { return vizContext; }
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { return null; }
        try {
            vizContext = new Ctor();
        } catch (_) {
            vizContext = null;
        }
        return vizContext;
    }

    function ensureVisualizerAnalyser() {
        const ctx = ensureVisualizerContext();
        if (!ctx) { return null; }
        if (!vizAnalyser) {
            vizAnalyser = ctx.createAnalyser();
            vizAnalyser.fftSize = 256;
            vizAnalyser.smoothingTimeConstant = 0.65;
            vizAnalyserData = new Uint8Array(vizAnalyser.frequencyBinCount);
            vizTimeData = new Uint8Array(vizAnalyser.fftSize);
        }
        if (!vizAnalyserConnected) {
            try {
                vizAnalyser.connect(ctx.destination);
                vizAnalyserConnected = true;
            } catch (_) {
                return null;
            }
        }
        return vizAnalyser;
    }

    function setVisualizerBars(button) {
        if (!button) { return false; }
        const bars = button.querySelectorAll('.ll-study-recording-visualizer .bar');
        if (!bars.length) { return false; }
        vizBars = Array.from(bars);
        vizBarLevels = vizBars.map(() => 0);
        vizButton = button;
        return true;
    }

    function resetVisualizerBars() {
        if (!vizBars.length) { return; }
        vizBars.forEach(function (bar) {
            bar.style.setProperty('--level', '0');
        });
        vizBarLevels = vizBars.map(() => 0);
    }

    function stopVisualizer() {
        if (vizRafId) {
            cancelAnimationFrame(vizRafId);
            vizRafId = null;
        }
        if (vizSource) {
            try { vizSource.disconnect(); } catch (_) {}
            vizSource = null;
        }
        if (vizButton) {
            $(vizButton).removeClass('ll-study-recording-btn--js');
        }
        resetVisualizerBars();
        vizBars = [];
        vizBarLevels = [];
        vizButton = null;
        vizAudio = null;
    }

    function updateVisualizer() {
        if (!vizAnalyser || !vizBars.length || !vizAnalyserData || !vizTimeData) {
            vizRafId = null;
            return;
        }
        if (!vizAudio) {
            stopVisualizer();
            return;
        }
        if (vizAudio.paused) {
            if (vizAudio.currentTime === 0 && !vizAudio.ended) {
                vizRafId = requestAnimationFrame(updateVisualizer);
                return;
            }
            stopVisualizer();
            return;
        }
        if (!vizContext || vizContext.state !== 'running') {
            vizRafId = requestAnimationFrame(updateVisualizer);
            return;
        }

        vizAnalyser.getByteFrequencyData(vizAnalyserData);
        vizAnalyser.getByteTimeDomainData(vizTimeData);

        const slice = Math.max(1, Math.floor(vizAnalyserData.length / vizBars.length));
        let sumSquares = 0;
        for (let i = 0; i < vizTimeData.length; i++) {
            const deviation = vizTimeData[i] - 128;
            sumSquares += deviation * deviation;
        }
        const rms = Math.min(1, Math.sqrt(sumSquares / vizTimeData.length) / 64);

        for (let i = 0; i < vizBars.length; i++) {
            let sum = 0;
            for (let j = 0; j < slice; j++) {
                sum += vizAnalyserData[(i * slice) + j] || 0;
            }
            const avg = sum / slice;
            const normalized = Math.max(0, (avg - 40) / 215);
            const combined = Math.min(1, (normalized * 0.7) + (rms * 0.9));
            const eased = Math.pow(combined, 1.35);

            const previous = vizBarLevels[i] || 0;
            const level = previous + (eased - previous) * 0.35;
            vizBarLevels[i] = level;
            vizBars[i].style.setProperty('--level', level.toFixed(3));
        }

        vizRafId = requestAnimationFrame(updateVisualizer);
    }

    function startVisualizer(audio, button) {
        if (!audio || !button) { return; }
        stopVisualizer();
        const src = audio.currentSrc || audio.src || '';
        if (!canUseVisualizerForUrl(src)) { return; }
        const ctx = ensureVisualizerContext();
        if (!ctx) { return; }
        const resumePromise = (ctx.state === 'suspended') ? ctx.resume() : Promise.resolve();
        const targetAudio = audio;
        const targetButton = button;

        resumePromise.then(function () {
            if (targetAudio !== currentAudio || targetButton !== currentAudioButton) { return; }
            const analyser = ensureVisualizerAnalyser();
            if (!analyser) { return; }
            if (!setVisualizerBars(button)) { return; }

            let source = audio.__llWordGridVisualizerSource;
            if (!source) {
                try {
                    source = ctx.createMediaElementSource(audio);
                    audio.__llWordGridVisualizerSource = source;
                } catch (_) {
                    return;
                }
            }

            try { source.disconnect(); } catch (_) {}
            try {
                source.connect(analyser);
            } catch (_) {
                try { source.connect(ctx.destination); } catch (_) {}
                return;
            }

            vizSource = source;
            vizAudio = audio;
            vizButton = button;
            $(button).addClass('ll-study-recording-btn--js');

            if (vizRafId) {
                cancelAnimationFrame(vizRafId);
            }
            updateVisualizer();
        }).catch(function () {});
    }

    function stopCurrentAudio() {
        if (currentAudio) {
            currentAudio.pause();
            currentAudio.currentTime = 0;
        }
        if (currentAudioButton) {
            $(currentAudioButton).removeClass('is-playing');
        }
        stopVisualizer();
        currentAudio = null;
        currentAudioButton = null;
    }

    $grids.on('click', '.ll-word-recording-launch', function (e) {
        const link = this;
        const $link = $(link);
        const rawHref = $link.attr('href') || '';
        const href = link.href || rawHref;
        const target = String($link.attr('target') || '').toLowerCase();

        if (!rawHref || rawHref === '#' || (target && target !== '_self') || link.hasAttribute('download') || !isPlainNavigationClick(e)) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();

        if (wordRecordingLaunchPending) {
            return;
        }

        const loadingLabel = $link.attr('data-loading-label') || '';
        wordRecordingLaunchPending = true;
        openWordRecordingLaunchOverlay(loadingLabel);

        navigateAfterPaint(href);
    });

    $(window).on('pageshow', function () {
        closeWordRecordingLaunchOverlay();
    });

    $grids.on('click', '.ll-study-recording-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const url = $btn.attr('data-audio-url') || '';
        if (!url) { return; }

        if (currentAudio && currentAudioButton === this) {
            if (!currentAudio.paused) {
                currentAudio.pause();
                $btn.removeClass('is-playing');
                return;
            }
            startVisualizer(currentAudio, this);
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
            return;
        }

        stopCurrentAudio();
        currentAudio = new Audio(url);
        currentAudioButton = this;
        currentAudio.addEventListener('play', function () {
            if (currentAudio !== this) { return; }
            $btn.addClass('is-playing');
        });
        currentAudio.addEventListener('pause', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
        });
        currentAudio.addEventListener('ended', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
            currentAudio = null;
            currentAudioButton = null;
        });
        currentAudio.addEventListener('error', function () {
            if (currentAudio !== this) { return; }
            $btn.removeClass('is-playing');
            stopVisualizer();
        });
        startVisualizer(currentAudio, this);
        if (currentAudio.play) {
            currentAudio.play().catch(function () {
                stopVisualizer();
            });
        }
    });

    let titleWrapTimer = null;

    const measureCanvas = document.createElement('canvas');
    const measureCtx = measureCanvas.getContext('2d');

    function parseGapValue(value) {
        if (!value) { return 0; }
        const parts = value.toString().split(' ');
        const parsed = parseFloat(parts[0]);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function getFontString(el) {
        if (!el) { return ''; }
        const style = window.getComputedStyle(el);
        if (style.font && style.font !== '') {
            return style.font;
        }
        return [
            style.fontStyle || 'normal',
            style.fontVariant || 'normal',
            style.fontWeight || 'normal',
            style.fontSize || '16px',
            style.fontFamily || 'sans-serif'
        ].join(' ');
    }

    function measureTextWidth(text, el) {
        if (!measureCtx || !text) { return 0; }
        const font = getFontString(el);
        if (font) {
            measureCtx.font = font;
        }
        return measureCtx.measureText(text).width || 0;
    }

    function updateTitleWraps() {
        $grids.find('.word-item').each(function () {
            const $item = $(this);
            const item = $item.get(0);
            const rowEl = $item.find('.ll-word-title-row').get(0);
            const titleEl = $item.find('.word-title').get(0);
            const textEl = $item.find('[data-ll-word-text]').get(0);
            const translationEl = $item.find('[data-ll-word-translation]').get(0);
            if (!item || !rowEl || !titleEl || !textEl || !translationEl) {
                $item.removeClass('ll-word-title--stack');
                if (item) {
                    item.style.removeProperty('--ll-word-title-width');
                }
                return;
            }
            const translationText = (translationEl.textContent || '').trim();
            if (!translationText) {
                $item.removeClass('ll-word-title--stack');
                item.style.removeProperty('--ll-word-title-width');
                return;
            }

            $item.removeClass('ll-word-title--stack');
            item.style.removeProperty('--ll-word-title-width');

            const itemStyle = window.getComputedStyle(item);
            const itemWidth = item.getBoundingClientRect().width;
            const paddingX = parseGapValue(itemStyle.paddingLeft) + parseGapValue(itemStyle.paddingRight);
            const contentWidth = Math.max(0, itemWidth - paddingX);

            const rowStyle = window.getComputedStyle(rowEl);
            const rowGap = parseGapValue(rowStyle.columnGap || rowStyle.gap || '0');
            const starEl = rowEl.querySelector('.ll-word-star');
            const editEl = rowEl.querySelector('.ll-word-edit-toggle');
            const starWidth = starEl ? starEl.getBoundingClientRect().width : 0;
            const editWidth = editEl ? editEl.getBoundingClientRect().width : 0;
            let gapCount = 0;
            if (starEl && editEl) {
                gapCount = 2;
            } else if (starEl || editEl) {
                gapCount = 1;
            }
            const availableWidth = Math.max(0, contentWidth - starWidth - editWidth - (rowGap * gapCount));
            if (availableWidth <= 0) {
                return;
            }

            const titleStyle = window.getComputedStyle(titleEl);
            const titleGap = parseGapValue(titleStyle.columnGap || titleStyle.gap || '0');
            const rawText = (textEl.textContent || '').trim();
            const rawTranslation = translationText;
            const measuredTextWidth = measureTextWidth(rawText, textEl);
            const measuredTranslationWidth = measureTextWidth(rawTranslation ? ('(' + rawTranslation + ')') : '', translationEl);
            const combinedWidth = measuredTextWidth + measuredTranslationWidth + (rawTranslation ? titleGap : 0);
            const cushion = 2;

            if (combinedWidth <= availableWidth) {
                $item.removeClass('ll-word-title--stack');
                item.style.setProperty('--ll-word-title-width', (combinedWidth + cushion) + 'px');
            } else {
                $item.addClass('ll-word-title--stack');
                const widestLine = Math.max(measuredTextWidth, measuredTranslationWidth);
                const targetWidth = Math.min(availableWidth, widestLine);
                item.style.setProperty('--ll-word-title-width', (targetWidth + cushion) + 'px');
            }
        });
    }

    function updateRecordingRowWidths() {
        $grids.find('.word-item').each(function () {
            const item = this;
            if (!item) { return; }
            const itemRect = item.getBoundingClientRect();
            if (!itemRect.width) { return; }
            const itemStyle = window.getComputedStyle(item);
            const paddingX = parseGapValue(itemStyle.paddingLeft) + parseGapValue(itemStyle.paddingRight);
            const contentWidth = Math.max(0, itemRect.width - paddingX);

            $(item).find('.ll-word-recording-row').each(function () {
                const row = this;
                const textWrap = row.querySelector('.ll-word-recording-text');
                if (!textWrap) {
                    row.style.removeProperty('width');
                    return;
                }
                const mainEl = textWrap.querySelector('.ll-word-recording-text-main');
                const translationEl = textWrap.querySelector('.ll-word-recording-text-translation');
                const ipaEl = textWrap.querySelector('.ll-word-recording-ipa');
                const mainText = mainEl ? (mainEl.textContent || '').trim() : '';
                const translationText = translationEl ? (translationEl.textContent || '').trim() : '';
                const ipaText = ipaEl ? (ipaEl.textContent || '').trim() : '';

                if (!mainText && !translationText && !ipaText) {
                    row.style.removeProperty('width');
                    return;
                }

                const rowStyle = window.getComputedStyle(row);
                const rowGap = parseGapValue(rowStyle.columnGap || rowStyle.gap || '0');
                const rowPaddingX = parseGapValue(rowStyle.paddingLeft) + parseGapValue(rowStyle.paddingRight);
                const textStyle = window.getComputedStyle(textWrap);
                const textGap = parseGapValue(textStyle.columnGap || textStyle.gap || '0');
                const btnEl = row.querySelector('.ll-study-recording-btn');
                const btnWidth = btnEl ? btnEl.getBoundingClientRect().width : 0;
                const availableTextWidth = Math.max(0, contentWidth - btnWidth - rowGap - rowPaddingX);

                let mainWidth = 0;
                if (mainText) {
                    mainWidth = measureTextWidth(mainText, mainEl || textWrap);
                }
                let translationWidth = 0;
                if (translationText) {
                    translationWidth = measureTextWidth('(' + translationText + ')', translationEl || textWrap);
                }
                let ipaWidth = 0;
                if (ipaText) {
                    ipaWidth = measureTextWidth('[' + ipaText + ']', ipaEl || textWrap);
                }

                const hasBoth = mainWidth > 0 && translationWidth > 0;
                const combinedWidth = hasBoth ? (mainWidth + translationWidth + textGap) : Math.max(mainWidth, translationWidth);
                let lineWidth = combinedWidth;
                if (combinedWidth > availableTextWidth) {
                    lineWidth = Math.max(mainWidth, translationWidth);
                }
                if (ipaWidth > lineWidth) {
                    lineWidth = ipaWidth;
                }
                if (availableTextWidth > 0) {
                    lineWidth = Math.min(lineWidth, availableTextWidth);
                }

                let targetWidth = btnWidth + rowGap + lineWidth + rowPaddingX;
                if (contentWidth > 0) {
                    targetWidth = Math.min(contentWidth, targetWidth);
                }

                if (targetWidth > 0) {
                    row.style.width = targetWidth.toFixed(2) + 'px';
                } else {
                    row.style.removeProperty('width');
                }
            });
        });
    }

    function updateGridLayouts() {
        updateTitleWraps();
        updateRecordingRowWidths();
    }

    function scheduleTitleWrapUpdate() {
        clearTimeout(titleWrapTimer);
        titleWrapTimer = setTimeout(updateGridLayouts, 50);
    }

    updateGridLayouts();
    $(window).on('resize.llWordTitleWrap', scheduleTitleWrapUpdate);

    $(document).on('lltools:word-grid-rendered', function (_evt, detail) {
        const info = (detail && typeof detail === 'object') ? detail : {};
        const $scope = info.scope && info.scope.jquery
            ? info.scope
            : $(info.scope || []);
        if ($scope.length) {
            $scope.each(function () {
                if (this && this.nodeType === 1) {
                    scanWordGridImages(this);
                }
            });
        }
        updateGridLayouts();
    });

    if (!isLoggedIn) { return; }

    let starredIds = normalizeIds(state.starred_word_ids);
    let saveTimer = null;
    let internalStarChange = false;
    const $starToggles = $('[data-ll-word-grid-star-toggle]');
    const $starModeButtons = $('.ll-vocab-lesson-star-mode');
    const $lessonSettings = $('.ll-vocab-lesson-settings');
    const $bulkEditors = $('[data-ll-word-grid-bulk]');
    const $prereqEditors = $('[data-ll-prereq-editor]');
    let initRenderedGridItems = null;

    function getStudySettings() {
        return (window.LLFlashcards && window.LLFlashcards.StudySettings)
            ? window.LLFlashcards.StudySettings
            : null;
    }

    function normalizeIds(arr) {
        return (arr || []).map(function (v) { return parseInt(v, 10) || 0; })
            .filter(function (v) { return v > 0; })
            .filter(function (v, idx, list) { return list.indexOf(v) === idx; });
    }

    function normalizeStarMode(mode) {
        const studySettings = getStudySettings();
        if (studySettings && typeof studySettings.normalizeStarMode === 'function') {
            return studySettings.normalizeStarMode(mode);
        }
        const normalized = String(mode || '').toLowerCase();
        if (normalized === 'weighted' || normalized === 'only' || normalized === 'normal') {
            return normalized;
        }
        return 'normal';
    }

    function setStarModeLocal(mode) {
        const normalized = normalizeStarMode(mode);
        state.star_mode = normalized;
        state.starMode = normalized;
        return normalized;
    }

    let currentStarMode = normalizeStarMode(
        (getStudySettings() && typeof getStudySettings().getStarMode === 'function')
            ? getStudySettings().getStarMode()
            : (state.star_mode ||
                state.starMode ||
                (window.llToolsFlashcardsData && (window.llToolsFlashcardsData.starMode || window.llToolsFlashcardsData.star_mode)) ||
                'normal')
    );
    setStarModeLocal(currentStarMode);

    function isStarred(wordId) {
        return starredIds.indexOf(wordId) !== -1;
    }

    function setStarredIds(ids) {
        starredIds = normalizeIds(ids);
        state.starred_word_ids = starredIds.slice();
    }

    function setStarMode(mode) {
        const normalized = setStarModeLocal(mode);

        if (window.llToolsStudyPrefs) {
            window.llToolsStudyPrefs.starMode = normalized;
            window.llToolsStudyPrefs.star_mode = normalized;
        }

        if (window.llToolsFlashcardsData) {
            window.llToolsFlashcardsData.starMode = normalized;
            window.llToolsFlashcardsData.star_mode = normalized;
            if (window.llToolsFlashcardsData.userStudyState) {
                window.llToolsFlashcardsData.userStudyState.star_mode = normalized;
            }
        }

        return normalized;
    }

    function getGridWordIds($grid) {
        const ids = [];
        $grid.find('.ll-word-grid-star[data-word-id]').each(function () {
            const wordId = parseInt($(this).attr('data-word-id'), 10) || 0;
            if (wordId) {
                ids.push(wordId);
            }
        });
        return normalizeIds(ids);
    }

    function getGridForToggle($toggle) {
        let $grid = $();
        const $scope = $toggle.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page');
        if ($scope.length) {
            $grid = $scope.find('[data-ll-word-grid]').first();
        }
        if (!$grid.length) {
            $grid = $grids.first();
        }
        return $grid;
    }

    function updateStarToggle($toggle) {
        const $grid = getGridForToggle($toggle);
        const wordIds = $grid.length ? getGridWordIds($grid) : [];
        const hasWords = wordIds.length > 0;
        const allStarred = hasWords && wordIds.every(function (wordId) {
            return isStarred(wordId);
        });
        const label = allStarred ? (i18n.unstarAllLabel || '') : (i18n.starAllLabel || '');
        const iconChar = allStarred ? '\u2605' : '\u2606';
        const $icon = $toggle.find('.ll-vocab-lesson-star-icon');
        const $label = $toggle.find('.ll-vocab-lesson-star-label');
        if ($icon.length) {
            $icon.text(iconChar);
        }
        if (label) {
            if ($label.length) {
                $label.text(label);
            } else {
                $toggle.text(iconChar + ' ' + label);
            }
        }
        $toggle.toggleClass('active', allStarred);
        $toggle.attr('aria-pressed', allStarred ? 'true' : 'false');
        $toggle.prop('disabled', !hasWords);
    }

    function updateAllStarToggles() {
        if (!$starToggles.length) { return; }
        $starToggles.each(function () {
            updateStarToggle($(this));
        });
    }

    function canUseStarOnlyForGrid($grid) {
        const wordIds = $grid.length ? getGridWordIds($grid) : [];
        if (!wordIds.length) {
            return false;
        }
        return wordIds.some(function (wordId) {
            return isStarred(wordId);
        });
    }

    function updateStarModeButtons() {
        if (!$starModeButtons.length) { return; }
        const studySettings = getStudySettings();
        if (studySettings && typeof studySettings.syncStarModeButtons === 'function') {
            studySettings.syncStarModeButtons($starModeButtons, {
                canUseStarOnly: function (button) {
                    const $grid = getGridForToggle($(button));
                    return canUseStarOnlyForGrid($grid);
                }
            });
            if (typeof studySettings.getStarMode === 'function') {
                setStarModeLocal(studySettings.getStarMode());
            }
            return;
        }
        const currentMode = normalizeStarMode(state.star_mode || 'normal');

        $starModeButtons.each(function () {
            const $btn = $(this);
            const mode = normalizeStarMode($btn.data('star-mode') || '');
            if (!mode) { return; }
            const $grid = getGridForToggle($btn);
            const allowOnly = canUseStarOnlyForGrid($grid);
            const shouldDisable = (mode === 'only') && !allowOnly;
            const isActive = mode === currentMode;

            $btn.toggleClass('active', isActive).attr('aria-pressed', isActive ? 'true' : 'false');
            $btn.prop('disabled', shouldDisable).attr('aria-disabled', shouldDisable ? 'true' : 'false');
        });
    }

    $(document).on('lltools:word-grid-rendered', function (_evt, detail) {
        const info = (detail && typeof detail === 'object') ? detail : {};
        const $scope = info.scope && info.scope.jquery
            ? info.scope
            : $(info.scope || []);
        if (typeof initRenderedGridItems === 'function' && $scope.length) {
            try {
                initRenderedGridItems($scope);
            } catch (error) {
                console.error('LL Tools: failed to initialize rendered word grid items.', error);
            }
        }
        updateAllStarToggles();
        updateStarModeButtons();
    });

    function applyStarModeSelection(mode) {
        const normalized = normalizeStarMode(mode);
        if (!normalized) { return false; }
        const studySettings = getStudySettings();
        if (studySettings && typeof studySettings.applyStarMode === 'function') {
            studySettings.applyStarMode(normalized);
            if (typeof studySettings.getStarMode === 'function') {
                setStarModeLocal(studySettings.getStarMode());
            } else {
                setStarModeLocal(normalized);
            }
            return true;
        }
        setStarMode(normalized);
        saveStateDebounced();
        return false;
    }

    function resetSettingsPanelPosition($panel) {
        if (!$panel || !$panel.length) { return; }
        $panel.each(function () {
            if (!this || !this.style) { return; }
            this.style.removeProperty('left');
            this.style.removeProperty('right');
        });
    }

    function clampSettingsPanelToViewport($panel) {
        if (!$panel || !$panel.length) { return; }
        const panel = $panel.get(0);
        if (!panel || !panel.style || typeof panel.getBoundingClientRect !== 'function') { return; }

        panel.style.setProperty('left', 'auto', 'important');
        panel.style.setProperty('right', '0px', 'important');

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        if (viewportWidth <= 0) { return; }

        const safePadding = 8;
        const rect = panel.getBoundingClientRect();
        if (!rect || rect.width <= 0) { return; }

        const maxRight = viewportWidth - safePadding;
        let rightOffset = 0;

        if (rect.left < safePadding) {
            rightOffset -= (safePadding - rect.left);
        }
        if (rect.right > maxRight) {
            rightOffset += (rect.right - maxRight);
        }

        if (Math.abs(rightOffset) > 0.5) {
            panel.style.setProperty('right', String(rightOffset) + 'px', 'important');
        }
    }

    function queueSettingsPanelViewportClamp($panel) {
        if (!$panel || !$panel.length) { return; }
        const run = function () {
            clampSettingsPanelToViewport($panel);
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                run();
                window.requestAnimationFrame(run);
            });
            return;
        }
        setTimeout(run, 0);
    }

    function repositionOpenSettingsPanels() {
        $('.ll-vocab-lesson-settings-panel[aria-hidden="false"]').each(function () {
            clampSettingsPanelToViewport($(this));
        });
    }

    function closeCategorySettingsPanels() {
        $('.ll-vocab-lesson-category-settings').each(function () {
            const $wrap = $(this);
            $wrap.removeClass('is-open');
            $wrap.find('.ll-vocab-lesson-category-settings-panel').first().attr('aria-hidden', 'true');
            $wrap.find('.ll-vocab-lesson-category-settings-trigger').first().attr('aria-expanded', 'false');
        });
        $('body').removeClass('ll-vocab-lesson-category-settings-open');
    }

    function setLessonSettingsOpen($wrap, shouldOpen) {
        if (!$wrap || !$wrap.length) { return; }
        const $panel = $wrap.find('.ll-vocab-lesson-settings-panel');
        const $button = $wrap.find('.ll-vocab-lesson-settings-button');
        if (!$panel.length || !$button.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        $wrap.toggleClass('is-open', open);
        if (open) {
            queueSettingsPanelViewportClamp($panel);
            updateStarModeButtons();
        } else {
            resetSettingsPanelPosition($panel);
        }
    }

    function closeLessonSettings(except) {
        if (!$lessonSettings.length) { return; }
        $lessonSettings.each(function () {
            const $wrap = $(this);
            if (except && $wrap.is(except)) { return; }
            setLessonSettingsOpen($wrap, false);
        });
    }

    function setBulkEditorOpen($wrap, shouldOpen) {
        if (!$wrap || !$wrap.length) { return; }
        const $panel = $wrap.find('.ll-vocab-lesson-bulk-panel');
        const $button = $wrap.find('.ll-vocab-lesson-bulk-button');
        if (!$panel.length || !$button.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $button.attr('aria-expanded', open ? 'true' : 'false');
        $wrap.toggleClass('is-open', open);
        $('body').toggleClass('ll-vocab-lesson-bulk-open', $('.ll-vocab-lesson-bulk-panel[aria-hidden="false"]').length > 0);
        if (open) {
            closeLessonSettings();
            closeCategorySettingsPanels();
        } else {
            resetSettingsPanelPosition($panel);
        }
    }

    function closeBulkEditors(except) {
        if (!$bulkEditors.length) { return; }
        $bulkEditors.each(function () {
            const $wrap = $(this);
            if (except && $wrap.is(except)) { return; }
            setBulkEditorOpen($wrap, false);
        });
    }

    function updateStarButton($btn, shouldStar) {
        $btn.toggleClass('active', shouldStar);
        $btn.attr('aria-pressed', shouldStar ? 'true' : 'false');
        const label = shouldStar ? (i18n.unstarLabel || '') : (i18n.starLabel || '');
        if (label) {
            $btn.attr('aria-label', label);
            $btn.attr('title', label);
        }
    }

    function updateStarButtons(wordId, shouldStar) {
        $grids.find('.ll-word-grid-star[data-word-id="' + wordId + '"]').each(function () {
            updateStarButton($(this), shouldStar);
        });
    }

    function setStudyPrefsGlobal() {
        if (!window.llToolsFlashcardsData) { return; }
        const synced = starredIds.slice();
        window.llToolsFlashcardsData.starredWordIds = synced;
        window.llToolsFlashcardsData.starred_word_ids = synced;
        if (window.llToolsFlashcardsData.userStudyState) {
            window.llToolsFlashcardsData.userStudyState.starred_word_ids = synced;
        }
        if (window.llToolsStudyPrefs) {
            window.llToolsStudyPrefs.starredWordIds = synced;
            window.llToolsStudyPrefs.starred_word_ids = synced;
        }
        if (window.llToolsStudyData && window.llToolsStudyData.payload && window.llToolsStudyData.payload.state) {
            window.llToolsStudyData.payload.state.starred_word_ids = synced;
        }
    }

    function saveStateDebounced(options) {
        const opts = (options && typeof options === 'object') ? options : {};
        if (!ajaxUrl || !nonce) { return; }
        const saveNow = function () {
            $.post(ajaxUrl, {
                action: 'll_user_study_save',
                nonce: nonce,
                wordset_id: state.wordset_id,
                category_ids: state.category_ids,
                starred_word_ids: state.starred_word_ids,
                star_mode: state.star_mode,
                fast_transitions: state.fast_transitions ? 1 : 0
            });
        };
        clearTimeout(saveTimer);
        if (opts.immediate) {
            saveNow();
            return;
        }
        saveTimer = setTimeout(saveNow, 300);
    }

    if ($starToggles.length) {
        $starToggles.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $toggle = $(this);
            if ($toggle.prop('disabled')) { return; }
            const $grid = getGridForToggle($toggle);
            if (!$grid.length) { return; }
            const wordIds = getGridWordIds($grid);
            if (!wordIds.length) {
                updateStarToggle($toggle);
                return;
            }

            const current = new Set(starredIds);
            const allStarred = wordIds.every(function (wordId) {
                return current.has(wordId);
            });
            const shouldStar = !allStarred;
            const changed = [];

            wordIds.forEach(function (wordId) {
                const hasStar = current.has(wordId);
                if (shouldStar && !hasStar) {
                    current.add(wordId);
                    changed.push({ wordId: wordId, starred: true });
                } else if (!shouldStar && hasStar) {
                    current.delete(wordId);
                    changed.push({ wordId: wordId, starred: false });
                }
            });

            if (!changed.length) {
                updateStarToggle($toggle);
                return;
            }

            setStarredIds(Array.from(current));
            changed.forEach(function (entry) {
                updateStarButtons(entry.wordId, entry.starred);
            });
            setStudyPrefsGlobal();
            saveStateDebounced({ immediate: true });

            internalStarChange = true;
            try {
                changed.forEach(function (entry) {
                    $(document).trigger('lltools:star-changed', [entry]);
                });
            } finally {
                internalStarChange = false;
            }

            updateAllStarToggles();
            updateStarModeButtons();
        });
    }

    if ($lessonSettings.length) {
        $lessonSettings.on('click', '.ll-vocab-lesson-settings-button', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('.ll-vocab-lesson-settings');
            const $panel = $wrap.find('.ll-vocab-lesson-settings-panel');
            const isOpen = $panel.attr('aria-hidden') === 'false';
            closeLessonSettings($wrap);
            setLessonSettingsOpen($wrap, !isOpen);
        });

        $lessonSettings.on('click', '.ll-vocab-lesson-settings-panel', function (e) {
            e.stopPropagation();
        });

        $(document).on('pointerdown.llLessonSettings', function (e) {
            if ($(e.target).closest('.ll-vocab-lesson-settings').length) { return; }
            closeLessonSettings();
        });

        $(document).on('keydown.llLessonSettings', function (e) {
            if (e.key === 'Escape') {
                closeLessonSettings();
            }
        });
    }

    if ($bulkEditors.length) {
        $bulkEditors.on('click', '.ll-vocab-lesson-bulk-button', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('[data-ll-word-grid-bulk]');
            const $panel = $wrap.find('.ll-vocab-lesson-bulk-panel');
            const isOpen = $panel.attr('aria-hidden') === 'false';
            closeBulkEditors($wrap);
            setBulkEditorOpen($wrap, !isOpen);
        });

        $bulkEditors.on('click', '.ll-vocab-lesson-bulk-panel', function (e) {
            e.stopPropagation();
        });

        $bulkEditors.on('click', '[data-ll-bulk-modal-close]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeBulkEditors();
        });

        $bulkEditors.on('pointerdown', function (e) {
            if (e.target === this && $(this).hasClass('is-open')) {
                e.preventDefault();
                closeBulkEditors();
            }
        });

        $(document).on('pointerdown.llLessonBulk', function (e) {
            if ($(e.target).closest('[data-ll-word-grid-bulk]').length) { return; }
            closeBulkEditors();
        });

        $(document).on('keydown.llLessonBulk', function (e) {
            if (e.key === 'Escape') {
                closeBulkEditors();
            }
        });
    }

    $(window).on('resize.llLessonPanelBounds orientationchange.llLessonPanelBounds', function () {
        repositionOpenSettingsPanels();
    });

    let lessonEditProcessingResizeFrame = 0;
    $(window).on('resize.llLessonProcessingWaveform orientationchange.llLessonProcessingWaveform', function () {
        if (lessonEditProcessingResizeFrame) {
            window.cancelAnimationFrame(lessonEditProcessingResizeFrame);
        }
        lessonEditProcessingResizeFrame = window.requestAnimationFrame(function () {
            lessonEditProcessingResizeFrame = 0;
            $grids.find('.ll-word-edit-recording[data-recording-id]').each(function () {
                const $recording = $(this);
                const state = $recording.data('llProcessingState');
                if (state && state.originalBuffer && $recording.closest('[data-ll-word-edit-panel]').attr('aria-hidden') === 'false') {
                    renderLessonEditProcessingWaveform($recording, state);
                }
            });
        });
    });

    if ($starModeButtons.length) {
        $starModeButtons.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn = $(this);
            if ($btn.prop('disabled')) { return; }
            const mode = normalizeStarMode($btn.data('star-mode') || '');
            if (!mode) { return; }
            applyStarModeSelection(mode);
            updateStarModeButtons();
        });
    }

    $grids.on('click', '.ll-word-grid-star', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const wordId = parseInt($btn.attr('data-word-id'), 10) || 0;
        if (!wordId) { return; }

        const shouldStar = !isStarred(wordId);
        if (shouldStar) {
            starredIds.push(wordId);
        } else {
            starredIds = starredIds.filter(function (id) { return id !== wordId; });
        }
        setStarredIds(starredIds);
        updateStarButtons(wordId, shouldStar);
        setStudyPrefsGlobal();
        saveStateDebounced({ immediate: true });
        updateAllStarToggles();
        updateStarModeButtons();

        internalStarChange = true;
        try {
            $(document).trigger('lltools:star-changed', [{ wordId: wordId, starred: shouldStar }]);
        } finally {
            internalStarChange = false;
        }
    });

    $(document).on('lltools:star-changed', function (_evt, detail) {
        if (internalStarChange) { return; }
        const info = detail || {};
        const wordId = parseInt(info.wordId || info.word_id, 10) || 0;
        if (!wordId) { return; }
        const shouldStar = info.starred !== false;

        if (shouldStar && !isStarred(wordId)) {
            starredIds.push(wordId);
        } else if (!shouldStar && isStarred(wordId)) {
            starredIds = starredIds.filter(function (id) { return id !== wordId; });
        } else {
            return;
        }

        setStarredIds(starredIds);
        updateStarButtons(wordId, shouldStar);
        setStudyPrefsGlobal();
        saveStateDebounced({ immediate: true });
        updateAllStarToggles();
        updateStarModeButtons();
    });

    const editMessages = {
        saving: editI18n.saving || 'Saving...',
        savingBackground: editI18n.savingBackground || 'Saving in background...',
        saved: editI18n.saved || 'Saved.',
        error: editI18n.error || 'Unable to save changes.',
        processingAudio: editI18n.processingAudio || 'Processing audio...',
        processedAudio: editI18n.processedAudio || 'Audio processed.',
        processAudio: editI18n.processAudio || 'Process audio',
        reprocessAudio: editI18n.reprocessAudio || 'Reprocess audio',
        processAudioError: editI18n.processAudioError || 'Unable to process audio.',
        audioDecodeError: editI18n.audioDecodeError || 'Unable to read this audio file in the browser.',
        audioUnsupportedError: editI18n.audioUnsupportedError || 'This browser cannot process audio here.',
        sourceOriginal: editI18n.sourceOriginal || 'Using saved original audio',
        sourceCurrent: editI18n.sourceCurrent || 'Using current audio',
        playSelection: editI18n.playSelection || 'Play clip',
        pauseSelection: editI18n.pauseSelection || 'Pause clip',
        waveformLoading: editI18n.waveformLoading || 'Loading waveform...',
        waveformUnavailable: editI18n.waveformUnavailable || 'Waveform unavailable.',
        recordings: editI18n.recordings || 'Recordings',
        deletingWord: editI18n.deletingWord || 'Deleting word...',
        wordDeleted: editI18n.wordDeleted || 'Word moved to Trash.',
        deleteWordError: editI18n.deleteWordError || 'Unable to delete word.',
        addingWord: editI18n.addingWord || 'Adding word...',
        wordAdded: editI18n.wordAdded || 'Word added.',
        addWordError: editI18n.addWordError || 'Unable to add word.',
        lessonGridLoading: editI18n.lessonGridLoading || 'Lesson words are still loading.',
        deletingRecording: editI18n.deletingRecording || 'Deleting recording...',
        recordingDeleted: editI18n.recordingDeleted || 'Recording moved to Trash.',
        deleteRecordingError: editI18n.deleteRecordingError || 'Unable to delete recording.',
        movingRecording: editI18n.movingRecording || 'Moving recording...',
        recordingMoved: editI18n.recordingMoved || 'Recording moved.',
        moveRecordingError: editI18n.moveRecordingError || 'Unable to move recording.',
        selectMoveTarget: editI18n.selectMoveTarget || 'Choose a target word.',
        noMatchingWords: editI18n.noMatchingWords || 'No matching words.',
        ipaCommon: editI18n.ipaCommon || '',
        ipaModifiers: editI18n.ipaModifiers || 'Diacritics and signs',
        ipaWordset: editI18n.ipaWordset || 'Wordset symbols',
        secondaryTextCommon: editI18n.secondaryTextCommon || editI18n.ipaCommon || 'Common symbols',
        secondaryTextModifiers: editI18n.secondaryTextModifiers || editI18n.ipaModifiers || 'Diacritics and signs',
        secondaryTextWordset: editI18n.secondaryTextWordset || editI18n.ipaWordset || 'Wordset symbols'
    };
    const lessonEditTargetLufs = -18.0;
    let lessonEditAudioContext = null;

    function ensureLessonEditAudioContext() {
        if (lessonEditAudioContext) { return lessonEditAudioContext; }
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { return null; }
        try {
            lessonEditAudioContext = new Ctor();
        } catch (_) {
            lessonEditAudioContext = null;
        }
        return lessonEditAudioContext;
    }

    function clampLessonEditSample(value, min, max) {
        const numeric = Number.isFinite(value) ? value : min;
        return Math.max(min, Math.min(max, numeric));
    }

    function detectLessonEditSilenceBoundaries(audioBuffer) {
        const channelData = audioBuffer.getChannelData(0);
        const sampleRate = audioBuffer.sampleRate;
        const threshold = 0.02;
        const windowSize = Math.max(1, Math.floor(0.01 * sampleRate));

        let startIndex = 0;
        for (let i = 0; i < channelData.length - windowSize; i++) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            if ((sum / windowSize) > threshold) {
                startIndex = Math.max(0, i - Math.floor(0.1 * sampleRate));
                break;
            }
        }

        let endIndex = channelData.length;
        for (let i = channelData.length - windowSize; i >= 0; i--) {
            let sum = 0;
            for (let j = 0; j < windowSize; j++) {
                sum += Math.abs(channelData[i + j]);
            }
            if ((sum / windowSize) > threshold) {
                endIndex = Math.min(channelData.length, i + windowSize + Math.floor(0.3 * sampleRate));
                break;
            }
        }

        return { start: startIndex, end: endIndex };
    }

    function getLessonEditProcessingSourceUrl($recording) {
        if (!$recording || !$recording.length) { return ''; }
        return ($recording.attr('data-ll-processing-source-audio-url') || $recording.attr('data-ll-current-audio-url') || '').toString();
    }

    function getLessonEditProcessingMessage($recording, attrName, fallback) {
        const $waveform = $recording.find('[data-ll-processing-waveform]').first();
        const value = $waveform.length ? ($waveform.attr(attrName) || '').toString() : '';
        return value || fallback;
    }

    function setLessonEditProcessingWaveformMessage($recording, message, isError) {
        const $waveform = $recording.find('[data-ll-processing-waveform]').first();
        if (!$waveform.length) { return; }
        $waveform
            .removeClass('is-ready is-error')
            .toggleClass('is-error', !!isError);
        $waveform.find('[data-ll-processing-waveform-message]').first().text(message || '');
    }

    function isLessonEditAutoTrimEnabled($recording) {
        return $recording.find('[data-ll-processing-option="trim"]').first().prop('checked') !== false;
    }

    function resetLessonEditProcessingBounds($recording, state) {
        if (!state || !state.originalBuffer) { return; }
        if (isLessonEditAutoTrimEnabled($recording)) {
            const detected = detectLessonEditSilenceBoundaries(state.originalBuffer);
            state.trimStart = clampLessonEditSample(detected.start, 0, Math.max(0, state.originalBuffer.length - 1));
            state.trimEnd = clampLessonEditSample(detected.end, state.trimStart + 1, state.originalBuffer.length);
        } else {
            state.trimStart = 0;
            state.trimEnd = state.originalBuffer.length;
        }
        state.manualBoundaries = false;
    }

    function updateLessonEditProcessingBoundaryPositions($recording, state) {
        const $waveform = $recording.find('[data-ll-processing-waveform]').first();
        if (!$waveform.length || !state || !state.originalBuffer) { return; }
        const totalSamples = Math.max(1, state.originalBuffer.length || 1);
        const start = clampLessonEditSample(Math.floor(state.trimStart), 0, Math.max(0, totalSamples - 1));
        const end = clampLessonEditSample(Math.ceil(state.trimEnd), start + 1, totalSamples);
        state.trimStart = start;
        state.trimEnd = end;

        const startPercent = (start / totalSamples) * 100;
        const endPercent = (end / totalSamples) * 100;
        const widthPercent = Math.max(0, endPercent - startPercent);
        const startSeconds = state.sampleRate ? (start / state.sampleRate) : 0;
        const endSeconds = state.sampleRate ? (end / state.sampleRate) : 0;

        $waveform.find('[data-ll-processing-selected-region]').css({
            left: startPercent + '%',
            width: widthPercent + '%'
        });
        $waveform.find('[data-ll-processing-trimmed-region="before"]').css({
            left: '0%',
            width: startPercent + '%'
        });
        $waveform.find('[data-ll-processing-trimmed-region="after"]').css({
            left: endPercent + '%',
            width: Math.max(0, 100 - endPercent) + '%'
        });
        $waveform.find('[data-ll-processing-boundary="start"]').css('left', startPercent + '%').attr('aria-valuenow', startSeconds.toFixed(2));
        $waveform.find('[data-ll-processing-boundary="end"]').css('left', endPercent + '%').attr('aria-valuenow', endSeconds.toFixed(2));
    }

    function drawLessonEditProcessingWaveform(canvas, container, audioBuffer) {
        if (!canvas || !container || !audioBuffer) { return false; }
        const rect = container.getBoundingClientRect();
        const width = Math.floor(rect.width);
        const height = Math.floor(rect.height);
        if (!width || !height) { return false; }

        const dpr = window.devicePixelRatio || 1;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        const ctx = canvas.getContext('2d');
        if (!ctx) { return false; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const channelData = audioBuffer.getChannelData(0);
        if (!channelData || !channelData.length) { return false; }

        const gradient = ctx.createLinearGradient(0, 0, 0, height);
        gradient.addColorStop(0, '#93c5fd');
        gradient.addColorStop(0.5, '#60a5fa');
        gradient.addColorStop(1, '#2563eb');
        ctx.fillStyle = gradient;

        const samplesPerPixel = Math.max(1, Math.floor(channelData.length / width));
        const centerY = height / 2;

        for (let x = 0; x < width; x += 1) {
            const start = x * samplesPerPixel;
            const end = Math.min(start + samplesPerPixel, channelData.length);
            let min = 1;
            let max = -1;

            for (let i = start; i < end; i += 1) {
                const sample = channelData[i];
                if (sample < min) { min = sample; }
                if (sample > max) { max = sample; }
            }

            const yTop = centerY - (max * centerY * 0.86);
            const yBottom = centerY - (min * centerY * 0.86);
            ctx.fillRect(x, yTop, 1, Math.max(1, yBottom - yTop));
        }

        return true;
    }

    function bindLessonEditProcessingBoundaryDragging($recording, state, $startBoundary, $endBoundary) {
        const container = $recording.find('[data-ll-processing-waveform]').get(0);
        if (!container || !state || !state.originalBuffer) { return; }

        const clientXFromEvent = function (event) {
            const original = event.originalEvent || event;
            if (original.touches && original.touches.length) {
                return original.touches[0].clientX;
            }
            if (original.changedTouches && original.changedTouches.length) {
                return original.changedTouches[0].clientX;
            }
            return original.clientX;
        };

        const applyClientX = function (clientX, boundaryName) {
            const rect = container.getBoundingClientRect();
            const width = rect.width || 1;
            const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / width));
            const sample = Math.round(ratio * state.originalBuffer.length);
            if (boundaryName === 'start') {
                state.trimStart = clampLessonEditSample(sample, 0, Math.max(0, state.trimEnd - 1));
            } else {
                state.trimEnd = clampLessonEditSample(sample, Math.min(state.originalBuffer.length, state.trimStart + 1), state.originalBuffer.length);
            }
            state.manualBoundaries = true;
            stopLessonEditProcessingPlayback($recording);
            updateLessonEditProcessingBoundaryPositions($recording, state);
        };

        const startDrag = function (event, boundaryName) {
            event.preventDefault();
            event.stopPropagation();
            applyClientX(clientXFromEvent(event), boundaryName);
            const onMove = function (moveEvent) {
                moveEvent.preventDefault();
                applyClientX(clientXFromEvent(moveEvent), boundaryName);
            };
            const onEnd = function () {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onEnd);
                document.removeEventListener('touchmove', onMove);
                document.removeEventListener('touchend', onEnd);
                document.removeEventListener('touchcancel', onEnd);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onEnd);
            document.addEventListener('touchcancel', onEnd);
        };

        const keyAdjust = function (event, boundaryName) {
            const key = event.key || '';
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(key) === -1) { return; }
            event.preventDefault();
            event.stopPropagation();
            const smallStep = Math.max(1, Math.round((state.sampleRate || 44100) * 0.05));
            const largeStep = Math.max(smallStep, Math.round((state.sampleRate || 44100) * 0.25));
            const step = event.shiftKey ? largeStep : smallStep;
            if (boundaryName === 'start') {
                if (key === 'Home') {
                    state.trimStart = 0;
                } else if (key === 'End') {
                    state.trimStart = Math.max(0, state.trimEnd - 1);
                } else {
                    state.trimStart += key === 'ArrowLeft' ? -step : step;
                }
            } else if (key === 'Home') {
                state.trimEnd = Math.min(state.originalBuffer.length, state.trimStart + 1);
            } else if (key === 'End') {
                state.trimEnd = state.originalBuffer.length;
            } else {
                state.trimEnd += key === 'ArrowLeft' ? -step : step;
            }
            state.manualBoundaries = true;
            stopLessonEditProcessingPlayback($recording);
            updateLessonEditProcessingBoundaryPositions($recording, state);
        };

        $startBoundary.on('mousedown touchstart', function (event) { startDrag(event, 'start'); });
        $endBoundary.on('mousedown touchstart', function (event) { startDrag(event, 'end'); });
        $startBoundary.on('keydown', function (event) { keyAdjust(event, 'start'); });
        $endBoundary.on('keydown', function (event) { keyAdjust(event, 'end'); });
    }

    function renderLessonEditProcessingWaveform($recording, state) {
        const $waveform = $recording.find('[data-ll-processing-waveform]').first();
        const container = $waveform.get(0);
        const canvas = $waveform.find('[data-ll-processing-waveform-canvas]').get(0);
        if (!$waveform.length || !container || !canvas || !state || !state.originalBuffer) { return false; }
        if (!drawLessonEditProcessingWaveform(canvas, container, state.originalBuffer)) {
            return false;
        }

        $waveform.find('[data-ll-processing-selected-region], [data-ll-processing-trimmed-region], [data-ll-processing-boundary]').remove();

        $('<span class="ll-word-edit-processing-selected-region" data-ll-processing-selected-region aria-hidden="true"></span>').appendTo($waveform);
        $('<span class="ll-word-edit-processing-trimmed-region" data-ll-processing-trimmed-region="before" aria-hidden="true"></span>').appendTo($waveform);
        $('<span class="ll-word-edit-processing-trimmed-region" data-ll-processing-trimmed-region="after" aria-hidden="true"></span>').appendTo($waveform);

        const startLabel = ($waveform.attr('data-start-label') || 'Start boundary').toString();
        const endLabel = ($waveform.attr('data-end-label') || 'End boundary').toString();
        const duration = state.sampleRate ? (state.originalBuffer.length / state.sampleRate) : 0;
        const $startBoundary = $('<button type="button" class="ll-word-edit-processing-boundary ll-word-edit-processing-boundary--start" data-ll-processing-boundary="start"></button>')
            .attr({
                'aria-label': startLabel,
                'title': startLabel,
                'role': 'slider',
                'aria-valuemin': '0',
                'aria-valuemax': duration.toFixed(2)
            })
            .appendTo($waveform);
        const $endBoundary = $('<button type="button" class="ll-word-edit-processing-boundary ll-word-edit-processing-boundary--end" data-ll-processing-boundary="end"></button>')
            .attr({
                'aria-label': endLabel,
                'title': endLabel,
                'role': 'slider',
                'aria-valuemin': '0',
                'aria-valuemax': duration.toFixed(2)
            })
            .appendTo($waveform);

        updateLessonEditProcessingBoundaryPositions($recording, state);
        bindLessonEditProcessingBoundaryDragging($recording, state, $startBoundary, $endBoundary);
        $waveform.removeClass('is-error').addClass('is-ready');
        return true;
    }

    function stopLessonEditProcessingPlayback($scope) {
        const $recordings = $scope && $scope.hasClass && $scope.hasClass('ll-word-edit-recording')
            ? $scope
            : ($scope && $scope.length ? $scope.find('.ll-word-edit-recording') : $grids.find('.ll-word-edit-recording'));
        $recordings.each(function () {
            const $recording = $(this);
            const state = $recording.data('llProcessingState');
            if (!state || !state.selectionAudio) { return; }
            try { state.selectionAudio.pause(); } catch (_) {}
            if (state.selectionTimeHandler) {
                state.selectionAudio.removeEventListener('timeupdate', state.selectionTimeHandler);
            }
            if (state.selectionEndHandler) {
                state.selectionAudio.removeEventListener('ended', state.selectionEndHandler);
            }
            state.selectionTimeHandler = null;
            state.selectionEndHandler = null;
            $recording.find('[data-ll-processing-play-selection]').first().text(editMessages.playSelection).removeClass('is-playing');
        });
    }

    function clearLessonEditProcessingState($recording) {
        if (!$recording || !$recording.length) { return; }
        stopLessonEditProcessingPlayback($recording);
        $recording.removeData('llProcessingState');
        const $waveform = $recording.find('[data-ll-processing-waveform]').first();
        if ($waveform.length) {
            $waveform.removeClass('is-ready is-error');
            $waveform.find('[data-ll-processing-selected-region], [data-ll-processing-trimmed-region], [data-ll-processing-boundary]').remove();
            $waveform.find('[data-ll-processing-waveform-message]').first().text(getLessonEditProcessingMessage($recording, 'data-loading-label', editMessages.waveformLoading));
        }
    }

    async function ensureLessonEditProcessingWaveform($recording) {
        if (!$recording || !$recording.length) {
            throw new Error(editMessages.processAudioError);
        }
        const sourceUrl = getLessonEditProcessingSourceUrl($recording);
        if (!sourceUrl) {
            throw new Error(editMessages.processAudioError);
        }

        const existingState = $recording.data('llProcessingState');
        if (existingState && existingState.sourceUrl === sourceUrl && existingState.originalBuffer) {
            renderLessonEditProcessingWaveform($recording, existingState);
            return existingState;
        }

        clearLessonEditProcessingState($recording);
        setLessonEditProcessingWaveformMessage($recording, getLessonEditProcessingMessage($recording, 'data-loading-label', editMessages.waveformLoading), false);

        let buffer;
        try {
            buffer = await loadWaveformBuffer(sourceUrl);
        } catch (error) {
            setLessonEditProcessingWaveformMessage($recording, getLessonEditProcessingMessage($recording, 'data-unavailable-label', editMessages.waveformUnavailable), true);
            throw new Error(editMessages.audioDecodeError);
        }

        const state = {
            sourceUrl: sourceUrl,
            originalBuffer: buffer,
            sampleRate: buffer.sampleRate || 0,
            trimStart: 0,
            trimEnd: buffer.length,
            manualBoundaries: false,
            selectionAudio: null,
            selectionTimeHandler: null,
            selectionEndHandler: null
        };
        resetLessonEditProcessingBounds($recording, state);
        $recording.data('llProcessingState', state);

        if (!renderLessonEditProcessingWaveform($recording, state)) {
            window.requestAnimationFrame(function () {
                renderLessonEditProcessingWaveform($recording, state);
            });
        }

        return state;
    }

    function initLessonEditProcessingWaveforms($scope) {
        const $context = ($scope && $scope.length) ? $scope : $grids;
        $context.find('.ll-word-edit-recording[data-recording-id]').each(function () {
            const $recording = $(this);
            if (!$recording.find('[data-ll-processing-waveform]').length) { return; }
            ensureLessonEditProcessingWaveform($recording).catch(function () {});
        });
    }

    async function playLessonEditProcessingSelection($recording) {
        const existingState = $recording.data('llProcessingState');
        if (existingState && existingState.selectionAudio && !existingState.selectionAudio.paused) {
            stopLessonEditProcessingPlayback($recording);
            return;
        }

        const state = await ensureLessonEditProcessingWaveform($recording);
        stopLessonEditProcessingPlayback($recording);

        const audio = state.selectionAudio || new Audio(state.sourceUrl);
        state.selectionAudio = audio;
        audio.preload = 'auto';

        const startSeconds = state.sampleRate ? (state.trimStart / state.sampleRate) : 0;
        const endSeconds = state.sampleRate ? Math.max(startSeconds + 0.05, state.trimEnd / state.sampleRate) : Number.POSITIVE_INFINITY;
        const $button = $recording.find('[data-ll-processing-play-selection]').first();

        const cleanup = function () {
            if (state.selectionTimeHandler) {
                audio.removeEventListener('timeupdate', state.selectionTimeHandler);
            }
            if (state.selectionEndHandler) {
                audio.removeEventListener('ended', state.selectionEndHandler);
            }
            state.selectionTimeHandler = null;
            state.selectionEndHandler = null;
            $button.text(editMessages.playSelection).removeClass('is-playing');
        };

        state.selectionTimeHandler = function () {
            if (audio.currentTime >= endSeconds) {
                try { audio.pause(); } catch (_) {}
                cleanup();
            }
        };
        state.selectionEndHandler = cleanup;
        audio.addEventListener('timeupdate', state.selectionTimeHandler);
        audio.addEventListener('ended', state.selectionEndHandler);

        try {
            audio.currentTime = startSeconds;
        } catch (_) {}
        $button.text(editMessages.pauseSelection).addClass('is-playing');
        try {
            await audio.play();
        } catch (error) {
            cleanup();
            throw error;
        }
    }

    function trimLessonEditAudioBuffer(ctx, audioBuffer, startIndex, endIndex) {
        const start = clampLessonEditSample(Math.floor(startIndex), 0, audioBuffer.length);
        const end = clampLessonEditSample(Math.ceil(endIndex), start + 1, audioBuffer.length);
        if (end <= start || (start === 0 && end === audioBuffer.length)) {
            return audioBuffer;
        }

        const trimmedLength = end - start;
        const trimmedBuffer = ctx.createBuffer(audioBuffer.numberOfChannels, trimmedLength, audioBuffer.sampleRate);
        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = trimmedBuffer.getChannelData(channel);
            for (let i = 0; i < trimmedLength; i++) {
                targetData[i] = sourceData[start + i];
            }
        }
        return trimmedBuffer;
    }

    async function reduceLessonEditNoise(audioBuffer) {
        const OfflineCtor = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        if (!OfflineCtor) { return audioBuffer; }
        const offlineContext = new OfflineCtor(audioBuffer.numberOfChannels, audioBuffer.length, audioBuffer.sampleRate);
        const source = offlineContext.createBufferSource();
        source.buffer = audioBuffer;
        const highpass = offlineContext.createBiquadFilter();
        highpass.type = 'highpass';
        highpass.frequency.value = 80;
        const lowpass = offlineContext.createBiquadFilter();
        lowpass.type = 'lowpass';
        lowpass.frequency.value = 8000;
        source.connect(highpass);
        highpass.connect(lowpass);
        lowpass.connect(offlineContext.destination);
        source.start();
        return await offlineContext.startRendering();
    }

    function calculateLessonEditLufs(audioBuffer) {
        const sampleRate = audioBuffer.sampleRate;
        const channelData = audioBuffer.getChannelData(0);
        const blockSize = Math.max(1, Math.floor(0.4 * sampleRate));
        const hopSize = Math.max(1, Math.floor(0.1 * sampleRate));
        const gatingThreshold = -70;
        const relativeThreshold = -10;
        const blockLoudnesses = [];

        for (let i = 0; i + blockSize < channelData.length; i += hopSize) {
            let sumSquares = 0;
            for (let j = 0; j < blockSize; j++) {
                const sample = channelData[i + j];
                sumSquares += sample * sample;
            }
            const meanSquare = sumSquares / blockSize;
            if (meanSquare <= 0) { continue; }
            const loudness = -0.691 + 10 * Math.log10(meanSquare);
            if (loudness > gatingThreshold) {
                blockLoudnesses.push(loudness);
            }
        }

        if (!blockLoudnesses.length) { return -70; }
        const avgLoudness = blockLoudnesses.reduce(function (a, b) { return a + b; }, 0) / blockLoudnesses.length;
        const relThreshold = avgLoudness + relativeThreshold;
        const gated = blockLoudnesses.filter(function (loudness) { return loudness >= relThreshold; });
        if (!gated.length) { return avgLoudness; }
        return gated.reduce(function (a, b) { return a + b; }, 0) / gated.length;
    }

    function normalizeLessonEditLoudness(ctx, audioBuffer) {
        const currentLufs = calculateLessonEditLufs(audioBuffer);
        const targetGain = Math.pow(10, (lessonEditTargetLufs - currentLufs) / 20);
        const normalizedBuffer = ctx.createBuffer(audioBuffer.numberOfChannels, audioBuffer.length, audioBuffer.sampleRate);

        for (let channel = 0; channel < audioBuffer.numberOfChannels; channel++) {
            const sourceData = audioBuffer.getChannelData(channel);
            const targetData = normalizedBuffer.getChannelData(channel);
            for (let i = 0; i < sourceData.length; i++) {
                targetData[i] = Math.max(-1, Math.min(1, sourceData[i] * targetGain));
            }
        }

        return normalizedBuffer;
    }

    function lessonEditAudioBufferToWav(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const bitDepth = 16;
        const bytesPerSample = bitDepth / 8;
        const blockAlign = numChannels * bytesPerSample;
        const data = [];

        for (let i = 0; i < audioBuffer.length; i++) {
            for (let channel = 0; channel < numChannels; channel++) {
                const sample = Math.max(-1, Math.min(1, audioBuffer.getChannelData(channel)[i]));
                data.push(sample < 0 ? sample * 0x8000 : sample * 0x7FFF);
            }
        }

        const dataLength = data.length * bytesPerSample;
        const buffer = new ArrayBuffer(44 + dataLength);
        const view = new DataView(buffer);
        const writeString = function (offset, string) {
            for (let i = 0; i < string.length; i++) {
                view.setUint8(offset + i, string.charCodeAt(i));
            }
        };

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + dataLength, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * blockAlign, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, bitDepth, true);
        writeString(36, 'data');
        view.setUint32(40, dataLength, true);

        let offset = 44;
        for (let i = 0; i < data.length; i++) {
            view.setInt16(offset, data[i], true);
            offset += 2;
        }

        return new Blob([buffer], { type: 'audio/wav' });
    }
    const orderMessages = {
        saving: orderI18n.saving || 'Saving order...',
        saved: orderI18n.saved || 'Order saved.',
        error: orderI18n.error || 'Unable to save the lesson order.'
    };
    const bulkMessages = {
        saving: bulkI18n.saving || 'Updating...',
        saved: bulkI18n.saved || 'Saved.',
        undoLabel: bulkI18n.undoLabel || 'Undo last bulk change',
        undoSuccess: bulkI18n.undoSuccess || 'Bulk changes undone.',
        undoError: bulkI18n.undoError || 'Unable to undo bulk changes.',
        posSuccess: bulkI18n.posSuccess || 'Updated %d words.',
        genderSuccess: bulkI18n.genderSuccess || 'Updated %d nouns.',
        pluralitySuccess: bulkI18n.pluralitySuccess || 'Updated %d nouns.',
        verbTenseSuccess: bulkI18n.verbTenseSuccess || 'Updated %d verbs.',
        verbMoodSuccess: bulkI18n.verbMoodSuccess || 'Updated %d verbs.',
        posMissing: bulkI18n.posMissing || 'Choose a part of speech.',
        genderMissing: bulkI18n.genderMissing || 'Choose a gender.',
        pluralityMissing: bulkI18n.pluralityMissing || 'Choose a plurality option.',
        verbTenseMissing: bulkI18n.verbTenseMissing || 'Choose a verb tense option.',
        verbMoodMissing: bulkI18n.verbMoodMissing || 'Choose a verb mood option.',
        error: bulkI18n.error || 'Unable to update words.'
    };
    const prereqMessages = {
        saving: prereqI18n.saving || 'Saving prerequisites...',
        saved: prereqI18n.saved || 'Prerequisites saved.',
        error: prereqI18n.error || 'Unable to save prerequisites.',
        empty: prereqI18n.empty || 'No prerequisites selected.',
        remove: prereqI18n.remove || 'Remove %s',
        optionAdd: prereqI18n.optionAdd || 'Add %s',
        optionRemove: prereqI18n.optionRemove || prereqI18n.remove || 'Remove %s',
        optionBlocked: prereqI18n.optionBlocked || 'Cannot add %s because it would create a loop.',
        blockedHint: prereqI18n.blockedHint || 'Would create a prerequisite loop.',
        noMatches: prereqI18n.noMatches || 'No matching categories.',
        levelCycle: prereqI18n.levelCycle || 'Cycle',
        levelUnknown: prereqI18n.levelUnknown || '-'
    };
    const prereqSaveDelayMs = 0;
    const bulkStatusHideDelayMs = 1400;
    const prereqStatusHideDelayMs = 1400;
    const dictionaryEntryCache = {};
    const wordImageCache = {};
    const moveWordCache = {};

    function syncDictionaryEntrySelectionState($item) {
        const $lookup = $item.find('[data-ll-word-input="dictionary_entry_lookup"]').first();
        if (!$lookup.length) { return; }
        const id = parseInt($item.find('[data-ll-word-input="dictionary_entry_id"]').val(), 10) || 0;
        const label = ($lookup.val() || '').toString();
        $lookup.data('llEntrySelectedId', id);
        $lookup.data('llEntrySelectedLabel', label);
    }

    function applyDictionaryEntryData($item, data) {
        const payload = data || {};
        const id = parseInt(payload.id, 10) || 0;
        const title = (payload.title || '').toString();
        const $idInput = $item.find('[data-ll-word-input="dictionary_entry_id"]').first();
        const $lookup = $item.find('[data-ll-word-input="dictionary_entry_lookup"]').first();

        if ($idInput.length) {
            $idInput.val(id ? String(id) : '');
        }
        if ($lookup.length) {
            $lookup.val(title);
            $lookup.data('llEntrySelectedId', id);
            $lookup.data('llEntrySelectedLabel', title);
        }
    }

    function clearWordImagePickerSelection($item) {
        const $idInput = $item.find('[data-ll-word-image-existing-id]').first();
        const $lookup = $item.find('[data-ll-word-image-existing-search]').first();
        if ($idInput.length) {
            $idInput.val('');
        }
        if ($lookup.length) {
            $lookup.val('');
            $lookup.data('llWordImageSelectedId', 0);
            $lookup.data('llWordImageSelectedLabel', '');
        }
    }

    function restoreWordImagePickerSelection($item) {
        const state = $item.data('llWordImagePickerState') || {};
        const id = parseInt(state.id, 10) || 0;
        const label = (state.label || '').toString();
        const $idInput = $item.find('[data-ll-word-image-existing-id]').first();
        const $lookup = $item.find('[data-ll-word-image-existing-search]').first();
        if ($idInput.length) {
            $idInput.val(id ? String(id) : '');
        }
        if ($lookup.length) {
            $lookup.val(label);
            $lookup.data('llWordImageSelectedId', id);
            $lookup.data('llWordImageSelectedLabel', label);
        }
    }

    function fetchWordImages(term, wordsetId, wordId, done) {
        const query = (term || '').toString();
        const normalizedWordset = parseInt(wordsetId, 10) || 0;
        const normalizedWordId = parseInt(wordId, 10) || 0;
        const key = [normalizedWordset, normalizedWordId, query.toLowerCase()].join('|');
        if (Object.prototype.hasOwnProperty.call(wordImageCache, key)) {
            done((wordImageCache[key] || []).slice());
            return;
        }
        $.post(ajaxUrl, {
            action: 'll_tools_search_word_images',
            nonce: editNonce,
            q: query,
            wordset_id: normalizedWordset,
            word_id: normalizedWordId,
            limit: 20
        }).done(function (response) {
            const images = (response && response.success === true && response.data && Array.isArray(response.data.images))
                ? response.data.images
                : [];
            wordImageCache[key] = images;
            done(images.slice());
        }).fail(function () {
            done([]);
        });
    }

    function initWordImageAutocomplete($input) {
        if (!$input.length || typeof $input.autocomplete !== 'function') { return; }
        if ($input.data('llWordImageAutocompleteReady')) { return; }
        $input.data('llWordImageAutocompleteReady', true);
        const $item = $input.closest('.word-item');
        const $idInput = $item.find('[data-ll-word-image-existing-id]').first();
        const $grid = $item.closest('[data-ll-word-grid]');
        const wordId = parseInt($item.data('word-id'), 10) || 0;
        const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;

        $input.autocomplete({
            minLength: 0,
            delay: 150,
            classes: {
                'ui-autocomplete': 'll-word-image-autocomplete'
            },
            source: function (request, response) {
                fetchWordImages(request.term, wordsetId, wordId, function (images) {
                    response((images || []).map(function (image) {
                        const item = image || {};
                        const label = (item.label || item.alt || '').toString();
                        const wordImageId = parseInt(item.word_image_id || item.id, 10) || 0;
                        const attachmentId = parseInt(item.attachment_id, 10) || 0;
                        return {
                            label: label,
                            value: label,
                            id: wordImageId,
                            word_image_id: wordImageId,
                            attachment_id: attachmentId,
                            url: (item.url || '').toString(),
                            alt: (item.alt || label).toString(),
                            width: parseInt(item.width, 10) || 0,
                            height: parseInt(item.height, 10) || 0,
                            copyright_info: (item.copyright_info || '').toString()
                        };
                    }));
                });
            },
            focus: function (event, ui) {
                event.preventDefault();
                $input.val((ui.item && ui.item.label) ? ui.item.label : '');
            },
            select: function (event, ui) {
                event.preventDefault();
                const selectedId = parseInt(ui.item && ui.item.word_image_id, 10) || 0;
                const selectedLabel = (ui.item && ui.item.label) ? ui.item.label.toString() : '';
                const attachmentId = parseInt(ui.item && ui.item.attachment_id, 10) || 0;
                $input.val(selectedLabel);
                if ($idInput.length) {
                    $idInput.val(selectedId ? String(selectedId) : '');
                }
                $input.data('llWordImageSelectedId', selectedId);
                $input.data('llWordImageSelectedLabel', selectedLabel);
                revokePendingImagePreviewUrl($item);
                const $fileInput = $item.find('[data-ll-word-image-input]').first();
                if ($fileInput.length) {
                    $fileInput.val('');
                }
                setWordEditImagePreview($item, {
                    id: attachmentId,
                    attachment_id: attachmentId,
                    word_image_id: selectedId,
                    url: (ui.item && ui.item.url) ? ui.item.url.toString() : '',
                    alt: (ui.item && ui.item.alt) ? ui.item.alt.toString() : selectedLabel,
                    width: parseInt(ui.item && ui.item.width, 10) || 0,
                    height: parseInt(ui.item && ui.item.height, 10) || 0,
                    copyright_info: (ui.item && ui.item.copyright_info) ? ui.item.copyright_info.toString() : ''
                });
                $item.find('[data-ll-word-image-copyright]').first().val((ui.item && ui.item.copyright_info) ? ui.item.copyright_info.toString() : '');
                $item.find('[data-ll-word-image-selected]').first().text(selectedLabel);
            },
            change: function (_event, ui) {
                if (ui && ui.item) { return; }
                const typed = ($input.val() || '').toString();
                const selectedLabel = ($input.data('llWordImageSelectedLabel') || '').toString();
                if (typed.trim() === '' || typed !== selectedLabel) {
                    if ($idInput.length) {
                        $idInput.val('');
                    }
                    $input.data('llWordImageSelectedId', 0);
                    $input.data('llWordImageSelectedLabel', '');
                    if (typed.trim() === '') {
                        $item.find('[data-ll-word-image-selected]').first().text('');
                    }
                }
            }
        });

        const instance = $input.autocomplete('instance');
        if (instance) {
            instance._renderItem = function (ul, item) {
                const $row = $('<div class="ll-word-image-autocomplete-item"></div>');
                if (item.url) {
                    $('<img class="ll-word-image-autocomplete-thumb" loading="lazy" decoding="async" />')
                        .attr('src', item.url)
                        .attr('alt', item.alt || item.label || '')
                        .appendTo($row);
                }
                $('<span class="ll-word-image-autocomplete-label"></span>')
                    .text((item.label || '') + ' #' + (item.word_image_id || 0))
                    .appendTo($row);
                return $('<li>').append($row).appendTo(ul);
            };
        }
    }

    function fetchMoveWords(term, wordsetId, excludeWordId, done) {
        const query = (term || '').toString();
        const normalizedWordset = parseInt(wordsetId, 10) || 0;
        const normalizedExclude = parseInt(excludeWordId, 10) || 0;
        const key = [normalizedWordset, normalizedExclude, query.toLowerCase()].join('|');
        if (Object.prototype.hasOwnProperty.call(moveWordCache, key)) {
            done((moveWordCache[key] || []).slice());
            return;
        }
        $.post(ajaxUrl, {
            action: 'll_tools_word_grid_search_words',
            nonce: editNonce,
            q: query,
            wordset_id: normalizedWordset,
            exclude_word_id: normalizedExclude,
            limit: 20
        }).done(function (response) {
            const words = (response && response.success === true && response.data && Array.isArray(response.data.words))
                ? response.data.words
                : [];
            moveWordCache[key] = words;
            done(words.slice());
        }).fail(function () {
            done([]);
        });
    }

    function clearMoveWordCache() {
        Object.keys(moveWordCache).forEach(function (key) {
            delete moveWordCache[key];
        });
    }

    function setRecordingMoveTarget($panel, targetId, targetLabel) {
        const id = parseInt(targetId, 10) || 0;
        const label = (targetLabel || '').toString();
        const $input = $panel.find('[data-ll-recording-move-search]').first();
        const $target = $panel.find('[data-ll-recording-move-target]').first();
        if ($input.length && label) {
            $input.val(label);
        }
        if ($target.length) {
            $target.val(id ? String(id) : '');
        }
        $panel.find('[data-ll-recording-move-confirm]').prop('disabled', !id);
        $input.data('llMoveWordSelectedId', id);
        $input.data('llMoveWordSelectedLabel', label);
    }

    function initRecordingMoveAutocomplete($input) {
        if (!$input.length || typeof $input.autocomplete !== 'function') { return; }
        if ($input.data('llMoveWordAutocompleteReady')) { return; }
        $input.data('llMoveWordAutocompleteReady', true);

        $input.autocomplete({
            minLength: 0,
            delay: 150,
            classes: {
                'ui-autocomplete': 'll-word-move-autocomplete'
            },
            source: function (request, response) {
                const $recording = $input.closest('.ll-word-edit-recording');
                const $item = $recording.closest('.word-item');
                const $grid = $item.closest('[data-ll-word-grid]');
                const sourceWordId = parseInt($item.data('word-id'), 10) || 0;
                const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
                fetchMoveWords(request.term, wordsetId, sourceWordId, function (words) {
                    const items = (words || []).map(function (word) {
                        const item = word || {};
                        const label = (item.label || '').toString();
                        const id = parseInt(item.id, 10) || 0;
                        return {
                            label: label,
                            value: label,
                            id: id,
                            word_text: (item.word_text || '').toString(),
                            translation: (item.translation || '').toString(),
                            status: (item.status || '').toString(),
                            categories: Array.isArray(item.categories) ? item.categories : []
                        };
                    });
                    const $panel = $input.closest('[data-ll-recording-move-panel]');
                    const $status = $panel.find('[data-ll-recording-move-status]').first();
                    if ($status.length) {
                        $status
                            .removeClass('is-error is-success')
                            .text(items.length ? '' : editMessages.noMatchingWords);
                    }
                    response(items);
                });
            },
            focus: function (event, ui) {
                event.preventDefault();
                $input.val((ui.item && ui.item.label) ? ui.item.label : '');
            },
            select: function (event, ui) {
                event.preventDefault();
                setRecordingMoveTarget($input.closest('[data-ll-recording-move-panel]'), ui.item && ui.item.id, ui.item && ui.item.label);
            },
            change: function (_event, ui) {
                if (ui && ui.item) { return; }
                const typed = ($input.val() || '').toString();
                const selectedLabel = ($input.data('llMoveWordSelectedLabel') || '').toString();
                if (typed.trim() === '' || typed !== selectedLabel) {
                    setRecordingMoveTarget($input.closest('[data-ll-recording-move-panel]'), 0, '');
                }
            }
        });

        const instance = $input.autocomplete('instance');
        if (instance) {
            instance._renderItem = function (ul, item) {
                const $row = $('<div class="ll-word-move-autocomplete-item"></div>');
                $('<span class="ll-word-move-autocomplete-main"></span>')
                    .text(item.label || ('#' + (item.id || 0)))
                    .appendTo($row);
                if (item.categories && item.categories.length) {
                    $('<span class="ll-word-move-autocomplete-meta"></span>')
                        .text(item.categories.slice(0, 2).join(' / '))
                        .appendTo($row);
                }
                return $('<li>').append($row).appendTo(ul);
            };
        }
    }

    function fetchDictionaryEntries(term, wordsetId, wordId, done) {
        const query = (term || '').toString().trim();
        const normalizedWordset = parseInt(wordsetId, 10) || 0;
        const normalizedWordId = parseInt(wordId, 10) || 0;
        const key = normalizedWordset + '|' + query.toLowerCase();
        if (Object.prototype.hasOwnProperty.call(dictionaryEntryCache, key)) {
            done((dictionaryEntryCache[key] || []).slice());
            return;
        }
        $.post(ajaxUrl, {
            action: 'll_tools_search_dictionary_entries',
            nonce: editNonce,
            q: query,
            wordset_id: normalizedWordset,
            word_id: normalizedWordId,
            limit: 20
        }).done(function (response) {
            const entries = (response && response.success === true && response.data && Array.isArray(response.data.entries))
                ? response.data.entries
                : [];
            dictionaryEntryCache[key] = entries;
            done(entries.slice());
        }).fail(function () {
            done([]);
        });
    }

    function initDictionaryEntryAutocomplete($input) {
        if (!$input.length || typeof $input.autocomplete !== 'function') { return; }
        if ($input.data('llEntryAutocompleteReady')) { return; }
        $input.data('llEntryAutocompleteReady', true);
        const $item = $input.closest('.word-item');
        const $idInput = $item.find('[data-ll-word-input="dictionary_entry_id"]').first();
        const $grid = $item.closest('[data-ll-word-grid]');
        const wordId = parseInt($item.data('word-id'), 10) || 0;
        const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
        syncDictionaryEntrySelectionState($item);

        $input.autocomplete({
            minLength: 0,
            delay: 150,
            classes: {
                'ui-autocomplete': 'll-word-dictionary-entry-autocomplete'
            },
            source: function (request, response) {
                fetchDictionaryEntries(request.term, wordsetId, wordId, function (entries) {
                    response((entries || []).map(function (entry) {
                        const label = (entry && entry.label) ? entry.label.toString() : '';
                        const id = parseInt(entry && entry.id, 10) || 0;
                        return {
                            label: label,
                            value: label,
                            id: id
                        };
                    }));
                });
            },
            focus: function (event, ui) {
                event.preventDefault();
                $input.val((ui.item && ui.item.label) ? ui.item.label : '');
            },
            select: function (event, ui) {
                event.preventDefault();
                const selectedId = parseInt(ui.item && ui.item.id, 10) || 0;
                const selectedLabel = (ui.item && ui.item.label) ? ui.item.label.toString() : '';
                $input.val(selectedLabel);
                if ($idInput.length) {
                    $idInput.val(selectedId ? String(selectedId) : '');
                }
                $input.data('llEntrySelectedId', selectedId);
                $input.data('llEntrySelectedLabel', selectedLabel);
            },
            change: function (_event, ui) {
                if (ui && ui.item) { return; }
                const typed = ($input.val() || '').toString();
                if (typed.trim() === '') {
                    if ($idInput.length) {
                        $idInput.val('');
                    }
                    $input.data('llEntrySelectedId', 0);
                    $input.data('llEntrySelectedLabel', '');
                }
            }
        });

        const instance = $input.autocomplete('instance');
        if (instance) {
            instance._renderItem = function (ul, item) {
                return $('<li>')
                    .append($('<div>').text((item.label || '') + ' #' + (item.id || 0)))
                    .appendTo(ul);
            };
        }
    }

    function setEditPanelOpen($item, shouldOpen) {
        const $panel = $item.find('[data-ll-word-edit-panel]').first();
        const $toggle = $item.find('[data-ll-word-edit-toggle]').first();
        const $backdrop = $item.find('[data-ll-word-edit-backdrop]').first();
        if (!$panel.length || !$toggle.length) { return; }
        const open = !!shouldOpen;
        if (open) {
            $grids.find('[data-ll-inline-word-editor].is-editing').each(function () {
                const $inlineEditor = $(this);
                const fieldName = ($inlineEditor.attr('data-ll-inline-word-editor') || '').toString();
                closeInlineWordEditor($inlineEditor.closest('.word-item'), fieldName, true, false);
            });
            $grids.find('.word-item').not($item).each(function () {
                const $otherItem = $(this);
                const $otherPanel = $otherItem.find('[data-ll-word-edit-panel]').first();
                if ($otherPanel.length && $otherPanel.attr('aria-hidden') === 'false') {
                    setEditPanelOpen($otherItem, false);
                }
            });
            resetWordCategoryFields($item);
        }
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        if ($backdrop.length) {
            $backdrop.attr('aria-hidden', open ? 'false' : 'true').prop('hidden', !open);
        }
        $toggle.attr('aria-expanded', open ? 'true' : 'false');
        $item.toggleClass('ll-word-edit-open', open);
        if (open) {
            window.requestAnimationFrame(function () {
                initLessonEditProcessingWaveforms($item);
                const $firstFocusable = $panel
                    .find('input, textarea, select, button')
                    .filter(':enabled:visible')
                    .first();
                if ($firstFocusable.length) {
                    $firstFocusable.trigger('focus');
                }
            });
        } else {
            if ($panel.find(document.activeElement).length) {
                $toggle.trigger('focus');
            }
            hideIpaKeyboards();
            stopLessonEditProcessingPlayback($item);
            resetWordCategoryFields($item);
        }
        syncEditModalBodyLock();
    }

    function setRecordingsPanelOpen($item, shouldOpen) {
        const $panel = $item.find('[data-ll-word-recordings-panel]').first();
        const $toggle = $item.find('[data-ll-word-recordings-toggle]').first();
        if (!$panel.length || !$toggle.length) { return; }
        const open = !!shouldOpen;
        $panel.attr('aria-hidden', open ? 'false' : 'true');
        $toggle.attr('aria-expanded', open ? 'true' : 'false');
        $item.toggleClass('ll-word-recordings-open', open);
    }

    function cacheOriginalInputs($item) {
        $item.find(EDITABLE_INPUT_SELECTOR).each(function () {
            const $input = $(this);
            if ($input.is(':checkbox')) {
                $input.data('originalChecked', $input.prop('checked') ? '1' : '0');
            } else {
                $input.data('original', $input.val() || '');
            }
        });
        $item.find('[data-ll-recording-review-toggle]').each(function () {
            const $btn = $(this);
            $btn.data('originalPressed', ($btn.attr('aria-pressed') || 'false') === 'true' ? '1' : '0');
        });
        cacheOriginalImageState($item);
        syncDictionaryEntrySelectionState($item);
    }

    function restoreOriginalInputs($item) {
        $item.find(EDITABLE_INPUT_SELECTOR).each(function () {
            const $input = $(this);
            if ($input.is(':checkbox')) {
                const originalChecked = $input.data('originalChecked');
                if (typeof originalChecked === 'string') {
                    $input.prop('checked', originalChecked === '1');
                }
                return;
            }
            const original = $input.data('original');
            if (typeof original === 'string') {
                $input.val(original);
            }
        });
        $item.find('.ll-word-edit-recording[data-recording-id]').each(function () {
            const $recording = $(this);
            $recording.find('[data-ll-recording-review-toggle]').each(function () {
                const $btn = $(this);
                const field = ($btn.attr('data-review-field') || '').toString();
                const originalPressed = $btn.data('originalPressed');
                if ((field === 'recording_text' || field === 'recording_ipa') && typeof originalPressed === 'string') {
                    setRecordingReviewToggle($recording, field, originalPressed === '1');
                }
            });
        });
        restoreOriginalImageState($item);
        setMetaFieldState($item);
        syncDictionaryEntrySelectionState($item);
    }

    function normalizeWordImageData(imageData) {
        const data = (imageData && typeof imageData === 'object') ? imageData : {};
        const attachmentId = parseInt(data.attachment_id, 10) || parseInt(data.id, 10) || 0;
        return {
            id: attachmentId,
            attachment_id: attachmentId,
            url: (data.url || '').toString(),
            alt: (data.alt || '').toString(),
            width: parseInt(data.width, 10) || 0,
            height: parseInt(data.height, 10) || 0,
            word_image_id: parseInt(data.word_image_id, 10) || 0,
            label: (data.label || '').toString(),
            copyright_info: (data.copyright_info || '').toString()
        };
    }

    function revokePendingImagePreviewUrl($item) {
        const previewUrl = ($item.data('llWordImagePreviewUrl') || '').toString();
        if (previewUrl && window.URL && typeof window.URL.revokeObjectURL === 'function') {
            window.URL.revokeObjectURL(previewUrl);
        }
        $item.removeData('llWordImagePreviewUrl');
    }

    function getCurrentWordImageState($item) {
        const $preview = $item.find('[data-ll-word-image-preview]').first();
        if ($preview.length) {
            return normalizeWordImageData({
                id: ($preview.attr('data-ll-word-image-attachment-id') || '').toString(),
                attachment_id: ($preview.attr('data-ll-word-image-attachment-id') || '').toString(),
                word_image_id: ($preview.attr('data-ll-word-image-id') || '').toString(),
                url: ($preview.attr('src') || '').toString(),
                alt: ($preview.attr('alt') || '').toString()
            });
        }
        return normalizeWordImageData({});
    }

    function setWordEditImagePreview($item, imageData) {
        const data = normalizeWordImageData(imageData);
        const $frame = $item.find('[data-ll-word-image-frame]').first();
        if (!$frame.length) { return; }

        let $preview = $frame.find('[data-ll-word-image-preview]').first();
        let $empty = $frame.find('[data-ll-word-image-empty]').first();
        const emptyLabel = ($frame.attr('data-ll-empty-label') || '').toString();

        if (data.url) {
            if (!$preview.length) {
                $preview = $('<img class="ll-word-edit-image-preview" data-ll-word-image-preview loading="lazy" decoding="async" />');
                $frame.append($preview);
            }
            $preview.attr('src', data.url);
            $preview.attr('alt', data.alt || '');
            $preview.attr('data-ll-word-image-id', data.word_image_id ? String(data.word_image_id) : '');
            $preview.attr('data-ll-word-image-attachment-id', data.attachment_id ? String(data.attachment_id) : (data.id ? String(data.id) : ''));
            if ($empty.length) {
                $empty.remove();
            }
        } else {
            if ($preview.length) {
                $preview.remove();
            }
            if (!$empty.length) {
                $empty = $('<div class="ll-word-edit-image-empty" data-ll-word-image-empty></div>');
                $frame.append($empty);
            }
            $empty.text(emptyLabel);
        }
    }

    function cacheOriginalImageState($item) {
        $item.data('llWordImageState', getCurrentWordImageState($item));
        $item.data('llWordImagePickerState', {
            id: ($item.find('[data-ll-word-image-existing-id]').first().val() || '').toString(),
            label: ($item.find('[data-ll-word-image-existing-search]').first().val() || '').toString()
        });
    }

    function restoreOriginalImageState($item) {
        revokePendingImagePreviewUrl($item);
        const originalState = normalizeWordImageData($item.data('llWordImageState') || {});
        setWordEditImagePreview($item, originalState);
        const $fileInput = $item.find('[data-ll-word-image-input]').first();
        if ($fileInput.length) {
            $fileInput.val('');
        }
        restoreWordImagePickerSelection($item);
        $item.find('[data-ll-word-image-selected]').first().text('');
    }

    function setEditStatus($item, message, isError) {
        const $status = $item.find('[data-ll-word-edit-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function clearWordSaveStatusTimer($item) {
        const timer = parseInt($item.data('llWordSaveStatusTimer'), 10) || 0;
        if (timer > 0) {
            window.clearTimeout(timer);
        }
        $item.removeData('llWordSaveStatusTimer');
    }

    function setWordSaveStatus($item, message, state) {
        const $status = $item.find('[data-ll-word-save-status]').first();
        if (!$status.length) { return; }
        clearWordSaveStatusTimer($item);
        const normalizedState = (state || '').toString();
        $status
            .text(message || '')
            .removeClass('is-pending is-success is-error')
            .toggleClass('is-pending', normalizedState === 'pending')
            .toggleClass('is-success', normalizedState === 'success')
            .toggleClass('is-error', normalizedState === 'error');
    }

    function scheduleWordSaveStatusClear($item, delayMs) {
        const delay = Math.max(0, parseInt(delayMs, 10) || 0);
        if (delay <= 0) {
            setWordSaveStatus($item, '', '');
            return;
        }
        clearWordSaveStatusTimer($item);
        const timer = window.setTimeout(function () {
            setWordSaveStatus($item, '', '');
        }, delay);
        $item.data('llWordSaveStatusTimer', timer);
    }

    function setWordSaveBusy($item, isBusy) {
        const busy = !!isBusy;
        const $toggle = $item.find('[data-ll-word-edit-toggle]').first();
        const $inlineTriggers = $item.find('[data-ll-inline-word-trigger]');
        $item.toggleClass('ll-word-save-pending', busy);
        if (busy) {
            $item.attr('aria-busy', 'true');
        } else {
            $item.removeAttr('aria-busy');
        }
        if ($toggle.length) {
            $toggle.prop('disabled', busy);
        }
        if ($inlineTriggers.length) {
            $inlineTriggers.prop('disabled', busy);
        }
    }

    function getInlineWordEditor($item, fieldName) {
        if (!$item || !$item.length || !fieldName) { return $(); }
        return $item.find('[data-ll-inline-word-editor="' + fieldName + '"]').first();
    }

    function getInlineWordSavedValue($item, fieldName) {
        const $editor = getInlineWordEditor($item, fieldName);
        if ($editor.length) {
            const $input = $editor.find('[data-ll-inline-word-input]').first();
            if ($input.length) {
                return ($input.attr('value') || '').toString();
            }
        }

        const selector = fieldName === 'translation' ? '[data-ll-word-translation]' : '[data-ll-word-text]';
        const $display = $item.find(selector).first();
        return ($display.text() || '').toString();
    }

    function syncInlineWordEditorValue($item, fieldName, value) {
        const $editor = getInlineWordEditor($item, fieldName);
        if (!$editor.length) { return; }

        const safeValue = (value === null || value === undefined) ? '' : String(value);
        const hasValue = safeValue.trim() !== '';
        const displaySelector = fieldName === 'translation' ? '[data-ll-word-translation]' : '[data-ll-word-text]';
        const $display = $editor.find(displaySelector).first();
        const $placeholder = $editor.find('[data-ll-inline-word-placeholder]').first();
        const $input = $editor.find('[data-ll-inline-word-input]').first();

        if ($display.length) {
            $display
                .attr('dir', 'auto')
                .text(protectMaqafNoBreak(safeValue))
                .prop('hidden', !hasValue);
        }

        if ($placeholder.length) {
            $placeholder.prop('hidden', hasValue);
        }

        if ($input.length) {
            $input.val(safeValue);
            $input.attr('value', safeValue);
            if ($input.get(0)) {
                $input.get(0).defaultValue = safeValue;
            }
        }
    }

    function setInlineWordEditorSaving($item, fieldName, isSaving) {
        const $editor = getInlineWordEditor($item, fieldName);
        if (!$editor.length) { return; }

        const saving = !!isSaving;
        $editor.toggleClass('is-saving', saving);
        $editor
            .find('[data-ll-inline-word-input], [data-ll-inline-word-save], [data-ll-inline-word-cancel]')
            .prop('disabled', saving);
    }

    function closeInlineWordEditor($item, fieldName, restoreValue, shouldFocusTrigger) {
        const $editor = getInlineWordEditor($item, fieldName);
        if (!$editor.length) { return; }

        if (restoreValue) {
            const $input = $editor.find('[data-ll-inline-word-input]').first();
            if ($input.length) {
                $input.val(($input.attr('value') || '').toString());
            }
        }

        $editor.removeClass('is-editing is-saving');
        $editor.find('[data-ll-inline-word-form]').first().prop('hidden', true);
        $editor.find('[data-ll-inline-word-trigger]').first().prop('hidden', false).attr('aria-expanded', 'false');

        if (shouldFocusTrigger) {
            const $trigger = $editor.find('[data-ll-inline-word-trigger]').first();
            if ($trigger.length) {
                window.requestAnimationFrame(function () {
                    $trigger.trigger('focus');
                });
            }
        }
    }

    function openInlineWordEditor($item, fieldName) {
        const $editor = getInlineWordEditor($item, fieldName);
        if (!$editor.length || $item.hasClass('ll-word-save-pending') || $editor.hasClass('is-saving')) {
            return;
        }

        $grids.find('[data-ll-inline-word-editor].is-editing').each(function () {
            const $openEditor = $(this);
            if ($openEditor.is($editor)) { return; }
            const openField = ($openEditor.attr('data-ll-inline-word-editor') || '').toString();
            closeInlineWordEditor($openEditor.closest('.word-item'), openField, true, false);
        });

        setWordSaveStatus($item, '', '');
        $editor.addClass('is-editing');
        $editor.find('[data-ll-inline-word-trigger]').first().prop('hidden', true).attr('aria-expanded', 'true');
        $editor.find('[data-ll-inline-word-form]').first().prop('hidden', false);

        const $input = $editor.find('[data-ll-inline-word-input]').first();
        if ($input.length) {
            window.requestAnimationFrame(function () {
                $input.trigger('focus').trigger('select');
            });
        }
    }

    function formatBulkMessage(template, count) {
        const safe = template || '';
        return safe.replace('%1$d', String(count)).replace('%d', String(count));
    }

    function formatStringMessage(template, value) {
        const safe = (template || '').toString();
        return safe.replace('%s', (value || '').toString());
    }

    function setBulkStatus($wrap, message, isError) {
        const $status = $wrap.find('[data-ll-bulk-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function getWordBulkFieldValue($item, fieldName) {
        if (!$item || !$item.length || !fieldName) { return ''; }
        return ($item.find('[data-ll-word-input="' + fieldName + '"]').val() || '').toString();
    }

    function getWordBulkFieldLabel($item, fieldName, fallbackSelector) {
        if (!$item || !$item.length || !fieldName) { return ''; }
        const $input = $item.find('[data-ll-word-input="' + fieldName + '"]').first();
        const selectedLabel = $input.length
            ? (($input.find('option:selected').text() || '').toString().trim())
            : '';
        if (selectedLabel) {
            return selectedLabel;
        }
        if (!fallbackSelector) {
            return '';
        }
        const $fallback = $item.find(fallbackSelector).first();
        if (!$fallback.length) {
            return '';
        }
        return (($fallback.attr('aria-label') || $fallback.text() || '').toString().trim());
    }

    function captureCurrentBulkWordSnapshot($item) {
        const wordId = parseInt($item && $item.data('word-id'), 10) || 0;
        if (!wordId) { return null; }

        const posValue = getWordBulkFieldValue($item, 'part_of_speech');
        const genderValue = getWordBulkFieldValue($item, 'gender');
        const pluralityValue = getWordBulkFieldValue($item, 'plurality');
        const verbTenseValue = getWordBulkFieldValue($item, 'verb_tense');
        const verbMoodValue = getWordBulkFieldValue($item, 'verb_mood');
        const $genderMeta = $item.find('[data-ll-word-gender]').first();

        return {
            word_id: wordId,
            raw: {
                part_of_speech: posValue,
                grammatical_gender: genderValue,
                grammatical_plurality: pluralityValue,
                verb_tense: verbTenseValue,
                verb_mood: verbMoodValue
            },
            part_of_speech: {
                slug: posValue,
                label: getWordBulkFieldLabel($item, 'part_of_speech', '[data-ll-word-pos]')
            },
            grammatical_gender: {
                value: genderValue,
                label: getWordBulkFieldLabel($item, 'gender', '[data-ll-word-gender]'),
                role: ($genderMeta.attr('data-ll-gender-role') || '').toString(),
                style: ($genderMeta.attr('style') || '').toString(),
                html: genderValue ? ($genderMeta.html() || '').toString() : ''
            },
            grammatical_plurality: {
                value: pluralityValue,
                label: getWordBulkFieldLabel($item, 'plurality', '[data-ll-word-plurality]')
            },
            verb_tense: {
                value: verbTenseValue,
                label: getWordBulkFieldLabel($item, 'verb_tense', '[data-ll-word-verb-tense]')
            },
            verb_mood: {
                value: verbMoodValue,
                label: getWordBulkFieldLabel($item, 'verb_mood', '[data-ll-word-verb-mood]')
            }
        };
    }

    function parseJsonArrayAttr($el, attrName) {
        if (!$el || !$el.length) { return []; }
        const raw = ($el.attr(attrName) || '').toString();
        if (!raw) { return []; }
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }

    function normalizeIntegerIdList(ids) {
        const list = Array.isArray(ids) ? ids : [];
        const seen = {};
        const out = [];

        list.forEach(function (id) {
            const numericId = parseInt(id, 10) || 0;
            if (!numericId || seen[numericId]) {
                return;
            }
            seen[numericId] = true;
            out.push(numericId);
        });

        return out;
    }

    function normalizePrereqOptionRows(rows) {
        const list = Array.isArray(rows) ? rows : [];
        const out = [];
        const seen = {};

        list.forEach(function (row) {
            const item = (row && typeof row === 'object') ? row : {};
            const id = parseInt(item.id, 10) || 0;
            if (!id || seen[id]) { return; }

            const label = (typeof item.label === 'string' && item.label)
                ? item.label
                : String(id);
            const levelRaw = parseInt(item.level, 10);
            const normalized = {
                id: id,
                label: label
            };
            if (Number.isFinite(levelRaw)) {
                normalized.level = levelRaw;
            }

            out.push(normalized);
            seen[id] = true;
        });

        return out;
    }

    function readAjaxErrorMessage(jqXHR, fallbackMessage) {
        const fallback = (fallbackMessage || '').toString();
        const response = jqXHR && jqXHR.responseJSON ? jqXHR.responseJSON : null;
        if (!response) { return fallback; }

        if (typeof response.data === 'string' && response.data) {
            return response.data;
        }
        if (response.data && typeof response.data.message === 'string' && response.data.message) {
            return response.data.message;
        }
        if (typeof response.message === 'string' && response.message) {
            return response.message;
        }

        return fallback;
    }

    function readResponseErrorMessage(response, fallbackMessage) {
        const fallback = (fallbackMessage || '').toString();
        if (!response || typeof response !== 'object') {
            return fallback;
        }
        if (typeof response.data === 'string' && response.data) {
            return response.data;
        }
        if (response.data && typeof response.data.message === 'string' && response.data.message) {
            return response.data.message;
        }
        if (typeof response.message === 'string' && response.message) {
            return response.message;
        }
        return fallback;
    }

    function getInternalNoteMessage(key, fallback) {
        const value = internalNotesI18n[key];
        return (typeof value === 'string' && value) ? value : fallback;
    }

    function setInternalNoteStatus($wrap, message, state) {
        const $status = $wrap.find('[data-ll-internal-review-note-status]').first();
        if (!$status.length) { return; }
        const normalizedState = (state || '').toString();
        $status
            .text(message || '')
            .removeClass('is-saving is-success is-error')
            .toggleClass('is-saving', normalizedState === 'saving')
            .toggleClass('is-success', normalizedState === 'success')
            .toggleClass('is-error', normalizedState === 'error');
    }

    function getInternalNoteOriginalValue($input) {
        if (!$input.length) { return ''; }
        if (!$input.attr('data-ll-internal-review-note-original')) {
            const current = ($input.val() || '').toString();
            $input.attr('data-ll-internal-review-note-original', current);
            const inputEl = $input.get(0);
            if (inputEl) {
                inputEl.defaultValue = current;
            }
        }
        return ($input.attr('data-ll-internal-review-note-original') || '').toString();
    }

    function setInternalNoteOriginalValue($input, value, updateValue) {
        const clean = (value || '').toString();
        if (updateValue !== false) {
            $input.val(clean);
        }
        $input.attr('data-ll-internal-review-note-original', clean);
        const inputEl = $input.get(0);
        if (inputEl) {
            inputEl.defaultValue = clean;
        }
    }

    function clearInternalNoteTimer($wrap) {
        const timer = parseInt($wrap.data('llInternalReviewNoteTimer'), 10) || 0;
        if (timer > 0) {
            window.clearTimeout(timer);
        }
        $wrap.removeData('llInternalReviewNoteTimer');
    }

    function saveInternalReviewNote($wrap) {
        if (!internalNotesEnabled || !$wrap || !$wrap.length) { return; }

        const $input = $wrap.find('[data-ll-internal-review-note-input]').first();
        if (!$input.length) { return; }

        clearInternalNoteTimer($wrap);

        const note = ($input.val() || '').toString();
        const original = getInternalNoteOriginalValue($input);
        if (note === original && !$wrap.data('llInternalReviewNoteDirty')) {
            return;
        }

        const objectId = parseInt($wrap.attr('data-object-id'), 10) || 0;
        const objectType = ($wrap.attr('data-object-type') || '').toString();
        const wordsetId = parseInt($wrap.attr('data-wordset-id'), 10) || 0;
        if (!objectId || !objectType) { return; }

        $wrap.removeData('llInternalReviewNoteDirty');
        $wrap.addClass('is-saving');
        setInternalNoteStatus($wrap, getInternalNoteMessage('saving', 'Saving review note...'), 'saving');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: internalNotesCfg.action || 'll_tools_save_internal_review_note',
                nonce: internalNotesCfg.nonce,
                object_id: objectId,
                object_type: objectType,
                wordset_id: wordsetId,
                note: note
            }
        }).done(function (response) {
            if (!response || response.success !== true) {
                setInternalNoteStatus($wrap, readResponseErrorMessage(response, getInternalNoteMessage('error', 'Unable to save the review note.')), 'error');
                return;
            }
            const data = response.data || {};
            const savedNote = (typeof data.note === 'string') ? data.note : note;
            const currentNote = ($input.val() || '').toString();
            const hasTypedAhead = currentNote !== note;
            setInternalNoteOriginalValue($input, savedNote, !hasTypedAhead);
            setInternalNoteStatus($wrap, getInternalNoteMessage('saved', 'Review note saved.'), 'success');
            if (hasTypedAhead) {
                $wrap.data('llInternalReviewNoteDirty', true);
                scheduleInternalReviewNoteSave($wrap);
            }
            window.setTimeout(function () {
                if (!$wrap.hasClass('is-saving')) {
                    setInternalNoteStatus($wrap, '', '');
                }
            }, 1800);
        }).fail(function (jqXHR) {
            setInternalNoteStatus($wrap, readAjaxErrorMessage(jqXHR, getInternalNoteMessage('error', 'Unable to save the review note.')), 'error');
        }).always(function () {
            $wrap.removeClass('is-saving');
        });
    }

    function scheduleInternalReviewNoteSave($wrap) {
        if (!internalNotesEnabled || !$wrap || !$wrap.length) { return; }
        clearInternalNoteTimer($wrap);
        $wrap.data('llInternalReviewNoteDirty', true);
        const timer = window.setTimeout(function () {
            saveInternalReviewNote($wrap);
        }, internalNoteSaveDelayMs);
        $wrap.data('llInternalReviewNoteTimer', timer);
    }

    function formatPrereqLevelText(level, hasCycle) {
        if (hasCycle) {
            return prereqMessages.levelCycle;
        }
        const numeric = parseInt(level, 10);
        if (Number.isFinite(numeric) && numeric >= 0) {
            return 'L' + String(numeric);
        }
        return prereqMessages.levelUnknown;
    }

    function setWordMetaText($item, selector, value) {
        const $el = $item.find(selector).first();
        if (!$el.length) { return; }
        $el.text(value || '');
    }

    function clearGenderMetaRoleClasses($el) {
        if (!$el || !$el.length) { return; }
        $el.removeClass('ll-word-meta-tag--gender-masculine ll-word-meta-tag--gender-feminine ll-word-meta-tag--gender-other');
    }

    function setWordGenderMeta($item, data) {
        const $el = $item.find('[data-ll-word-gender]').first();
        if (!$el.length) { return; }

        const label = (data && Object.prototype.hasOwnProperty.call(data, 'label')) ? (data.label || '') : '';
        const html = (data && Object.prototype.hasOwnProperty.call(data, 'html')) ? (data.html || '') : '';
        const role = (data && Object.prototype.hasOwnProperty.call(data, 'role')) ? (data.role || '') : '';
        const style = (data && Object.prototype.hasOwnProperty.call(data, 'style')) ? (data.style || '') : '';

        if (html) {
            $el.html(html);
        } else {
            $el.text(label);
        }

        clearGenderMetaRoleClasses($el);
        if (role) {
            $el.addClass('ll-word-meta-tag--gender-' + role);
        }
        $el.attr('data-ll-gender-role', role || '');

        if (style) {
            $el.attr('style', style);
        } else {
            $el.removeAttr('style');
        }
        if (label) {
            $el.attr('aria-label', label).attr('title', label);
        } else {
            $el.removeAttr('aria-label').removeAttr('title');
        }
    }

    function setWordNote($item, value) {
        const $note = $item.find('[data-ll-word-note]').first();
        if (!$note.length) { return; }
        const clean = (value || '').toString().trim();
        $note.text(clean);
        $note.toggleClass('ll-word-note--empty', !clean);
    }

    function updateWordMetaRow($item) {
        const $row = $item.find('[data-ll-word-meta]').first();
        if (!$row.length) { return; }
        const posText = ($row.find('[data-ll-word-pos]').text() || '').trim();
        const genderText = ($row.find('[data-ll-word-gender]').text() || '').trim();
        const pluralityText = ($row.find('[data-ll-word-plurality]').text() || '').trim();
        const verbTenseText = ($row.find('[data-ll-word-verb-tense]').text() || '').trim();
        const verbMoodText = ($row.find('[data-ll-word-verb-mood]').text() || '').trim();
        $row.toggleClass('ll-word-meta-row--empty', !(posText || genderText || pluralityText || verbTenseText || verbMoodText));
    }

    function setMetaFieldState($item, posSlug) {
        const resolved = (posSlug || ($item.find('[data-ll-word-input="part_of_speech"]').val() || '')).toString();
        const isNoun = resolved === 'noun';
        const isVerb = resolved === 'verb';
        const $genderField = $item.find('[data-ll-word-gender-field]').first();
        if ($genderField.length) {
            $genderField.toggleClass('ll-word-edit-gender--hidden', !isNoun);
            $genderField.attr('aria-hidden', isNoun ? 'false' : 'true');
            const $select = $genderField.find('select[data-ll-word-input="gender"]').first();
            if ($select.length) {
                $select.prop('disabled', !isNoun);
            }
        }
        const $pluralityField = $item.find('[data-ll-word-plurality-field]').first();
        if ($pluralityField.length) {
            $pluralityField.toggleClass('ll-word-edit-plurality--hidden', !isNoun);
            $pluralityField.attr('aria-hidden', isNoun ? 'false' : 'true');
            const $select = $pluralityField.find('select[data-ll-word-input="plurality"]').first();
            if ($select.length) {
                $select.prop('disabled', !isNoun);
            }
        }
        const $verbTenseField = $item.find('[data-ll-word-verb-tense-field]').first();
        if ($verbTenseField.length) {
            $verbTenseField.toggleClass('ll-word-edit-verb-tense--hidden', !isVerb);
            $verbTenseField.attr('aria-hidden', isVerb ? 'false' : 'true');
            const $select = $verbTenseField.find('select[data-ll-word-input="verb_tense"]').first();
            if ($select.length) {
                $select.prop('disabled', !isVerb);
            }
        }
        const $verbMoodField = $item.find('[data-ll-word-verb-mood-field]').first();
        if ($verbMoodField.length) {
            $verbMoodField.toggleClass('ll-word-edit-verb-mood--hidden', !isVerb);
            $verbMoodField.attr('aria-hidden', isVerb ? 'false' : 'true');
            const $select = $verbMoodField.find('select[data-ll-word-input="verb_mood"]').first();
            if ($select.length) {
                $select.prop('disabled', !isVerb);
            }
        }
    }

    function applyPosMetaUpdate($item, posData, genderData, pluralityData, verbTenseData, verbMoodData) {
        if (posData && Object.prototype.hasOwnProperty.call(posData, 'slug')) {
            $item.find('[data-ll-word-input="part_of_speech"]').val(posData.slug || '');
        }
        if (posData && Object.prototype.hasOwnProperty.call(posData, 'label')) {
            setWordMetaText($item, '[data-ll-word-pos]', posData.label || '');
        }
        if (genderData && Object.prototype.hasOwnProperty.call(genderData, 'value')) {
            $item.find('[data-ll-word-input="gender"]').val(genderData.value || '');
        }
        if (genderData && Object.prototype.hasOwnProperty.call(genderData, 'label')) {
            setWordGenderMeta($item, genderData);
        }
        if (pluralityData && Object.prototype.hasOwnProperty.call(pluralityData, 'value')) {
            $item.find('[data-ll-word-input="plurality"]').val(pluralityData.value || '');
        }
        if (pluralityData && Object.prototype.hasOwnProperty.call(pluralityData, 'label')) {
            setWordMetaText($item, '[data-ll-word-plurality]', pluralityData.label || '');
        }
        if (verbTenseData && Object.prototype.hasOwnProperty.call(verbTenseData, 'value')) {
            $item.find('[data-ll-word-input="verb_tense"]').val(verbTenseData.value || '');
        }
        if (verbTenseData && Object.prototype.hasOwnProperty.call(verbTenseData, 'label')) {
            setWordMetaText($item, '[data-ll-word-verb-tense]', verbTenseData.label || '');
        }
        if (verbMoodData && Object.prototype.hasOwnProperty.call(verbMoodData, 'value')) {
            $item.find('[data-ll-word-input="verb_mood"]').val(verbMoodData.value || '');
        }
        if (verbMoodData && Object.prototype.hasOwnProperty.call(verbMoodData, 'label')) {
            setWordMetaText($item, '[data-ll-word-verb-mood]', verbMoodData.label || '');
        }
        updateWordMetaRow($item);
        if (posData && Object.prototype.hasOwnProperty.call(posData, 'slug')) {
            setMetaFieldState($item, posData.slug || '');
        } else {
            setMetaFieldState($item);
        }
    }

    function getSelectedCategoryIds($item) {
        const ids = [];
        const seen = {};
        $item.find('[data-ll-word-category-input]:checked').each(function () {
            const categoryId = parseInt($(this).val(), 10) || 0;
            if (!categoryId || seen[categoryId]) { return; }
            seen[categoryId] = true;
            ids.push(categoryId);
        });
        return ids;
    }

    function applyCategorySelection($item, categoryIds) {
        const selected = {};
        if (Array.isArray(categoryIds)) {
            categoryIds.forEach(function (categoryId) {
                const normalized = parseInt(categoryId, 10) || 0;
                if (normalized) {
                    selected[normalized] = true;
                }
            });
        }
        $item.find('[data-ll-word-category-input]').each(function () {
            const $input = $(this);
            const categoryId = parseInt($input.val(), 10) || 0;
            $input.prop('checked', !!(categoryId && selected[categoryId]));
        });
        $item.find('[data-ll-word-categories-field]').each(function () {
            updateWordCategoryField($(this));
        });
    }

    function normalizeCategorySearchText(value) {
        let text = (value || '').toString().trim().toLocaleLowerCase();
        if (typeof text.normalize === 'function') {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text;
    }

    function getCategoryOptionWordsetOrder(option) {
        return parseInt($(option).attr('data-ll-wordset-order'), 10) || 0;
    }

    function getCategoryOptionLabel(option) {
        const $option = $(option);
        return ($option.attr('data-ll-word-category-label') || $option.text() || '').toString();
    }

    function categoryOptionIsChecked(option) {
        return $(option).find('[data-ll-word-category-input]').first().prop('checked') ? 1 : 0;
    }

    function compareCategoryOptionsByLabel(left, right) {
        const leftLabel = getCategoryOptionLabel(left);
        const rightLabel = getCategoryOptionLabel(right);
        const cmp = leftLabel.localeCompare(rightLabel, undefined, {
            numeric: true,
            sensitivity: 'base'
        });
        if (cmp !== 0) {
            return cmp;
        }
        return getCategoryOptionWordsetOrder(left) - getCategoryOptionWordsetOrder(right);
    }

    function compareCategoryOptionsByWordsetOrder(left, right) {
        const cmp = getCategoryOptionWordsetOrder(left) - getCategoryOptionWordsetOrder(right);
        return cmp !== 0 ? cmp : compareCategoryOptionsByLabel(left, right);
    }

    function compareCategoryOptionsWithCheckedFirst(left, right, fallbackCompare) {
        const checkedCmp = categoryOptionIsChecked(right) - categoryOptionIsChecked(left);
        if (checkedCmp !== 0) {
            return checkedCmp;
        }
        return fallbackCompare(left, right);
    }

    function updateWordCategoryField($field) {
        if (!$field || !$field.length) { return; }
        const $list = $field.find('[data-ll-word-category-list]').first();
        if (!$list.length) { return; }

        const $search = $field.find('[data-ll-word-category-search]').first();
        const $sort = $field.find('[data-ll-word-category-sort]').first();
        const query = normalizeCategorySearchText($search.val() || '');
        const sortMode = ($sort.val() || '').toString() === 'alpha' ? 'alpha' : 'wordset';
        const options = $list.find('[data-ll-word-category-option]').get();

        const fallbackCompare = sortMode === 'alpha' ? compareCategoryOptionsByLabel : compareCategoryOptionsByWordsetOrder;
        options.sort(function (left, right) {
            return compareCategoryOptionsWithCheckedFirst(left, right, fallbackCompare);
        });
        options.forEach(function (option) {
            $list.append(option);
        });

        let visibleCount = 0;
        options.forEach(function (option) {
            const $option = $(option);
            const searchText = normalizeCategorySearchText(
                $option.attr('data-ll-word-category-search-text') || getCategoryOptionLabel(option)
            );
            const visible = query === '' || searchText.indexOf(query) !== -1;
            $option.prop('hidden', !visible);
            if (visible) {
                visibleCount++;
            }
        });

        $field.toggleClass('is-filtered', query !== '');
        $field.find('[data-ll-word-category-empty]').first().prop('hidden', visibleCount > 0);
    }

    function resetWordCategoryField($field) {
        if (!$field || !$field.length) { return; }
        $field.removeClass('is-expanded is-filtered');
        $field.find('[data-ll-word-category-search]').first().val('');
        $field.find('[data-ll-word-category-sort]').first().val('wordset');
        updateWordCategoryField($field);
        $field.removeClass('is-expanded');
    }

    function resetWordCategoryFields($item) {
        $item.find('[data-ll-word-categories-field]').each(function () {
            resetWordCategoryField($(this));
        });
    }

    function updateOriginalInputs($item) {
        $item.find(EDITABLE_INPUT_SELECTOR).each(function () {
            const $input = $(this);
            if ($input.is(':checkbox')) {
                $input.data('originalChecked', $input.prop('checked') ? '1' : '0');
            } else {
                $input.data('original', $input.val() || '');
            }
        });
        $item.find('[data-ll-recording-review-toggle]').each(function () {
            const $btn = $(this);
            $btn.data('originalPressed', ($btn.attr('aria-pressed') || 'false') === 'true' ? '1' : '0');
        });
        const $fileInput = $item.find('[data-ll-word-image-input]').first();
        if ($fileInput.length) {
            $fileInput.val('');
        }
        clearWordImagePickerSelection($item);
        revokePendingImagePreviewUrl($item);
        $item.find('[data-ll-word-image-selected]').first().text('');
        cacheOriginalImageState($item);
        syncDictionaryEntrySelectionState($item);
    }

    function applyWordImageData($item, imageData) {
        const data = normalizeWordImageData(imageData);
        const $grid = $item.closest('[data-ll-word-grid]');
        const isTextGrid = $grid.hasClass('ll-word-grid--text');
        const wordText = ($item.find('[data-ll-word-text]').text() || '').toString().trim();
        const altText = data.alt || wordText;

        setWordEditImagePreview($item, Object.assign({}, data, { alt: altText }));
        $item.find('[data-ll-word-image-copyright]').first().val(data.copyright_info || '');

        if (isTextGrid) {
            return;
        }

        let $container = $item.children('.word-image-container').first();
        if (!data.url) {
            if ($container.length) {
                $container.remove();
            }
            return;
        }

        if (!$container.length) {
            $container = $('<div class="word-image-container"></div>');
            $item.prepend($container);
        }

        let $img = $container.find('img.word-image').first();
        if (!$img.length) {
            $img = $('<img class="word-image" loading="lazy" decoding="async" fetchpriority="low" />');
            $container.empty().append($img);
        }

        $img.attr('src', data.url);
        $img.attr('alt', altText);
        $img.removeAttr('srcset');
        $img.removeAttr('sizes');
        const imgEl = $img.get(0);
        if (imgEl && imgEl.dataset) {
            delete imgEl.dataset.llImgLoadBound;
        }
        setWordGridImagePending(imgEl);
        bindWordGridImageLoadState(imgEl);
    }

    initRenderedGridItems = function ($scope) {
        const $root = ($scope && $scope.jquery) ? $scope : $($scope || []);
        if (!$root.length) { return; }

        $root.find('.word-item').each(function () {
            const $item = $(this);
            cacheOriginalInputs($item);
            setMetaFieldState($item);
            if ($item.find('[data-ll-word-recordings-panel]').length) {
                setRecordingsPanelOpen($item, true);
            }
        });
        $root.find('[data-ll-word-input="dictionary_entry_lookup"]').each(function () {
            initDictionaryEntryAutocomplete($(this));
        });
        $root.find('[data-ll-word-image-existing-search]').each(function () {
            initWordImageAutocomplete($(this));
        });
        $root.find('[data-ll-recording-move-search]').each(function () {
            initRecordingMoveAutocomplete($(this));
        });
        $root.find('[data-ll-word-categories-field]').each(function () {
            updateWordCategoryField($(this));
        });
        syncEditModalBodyLock();
    };

    function collectRecordingInputs($item) {
        const recordings = [];
        $item.find('.ll-word-edit-recording[data-recording-id]').each(function () {
            const $rec = $(this);
            const recId = parseInt($rec.attr('data-recording-id'), 10) || 0;
            if (!recId) { return; }
            const text = ($rec.find('[data-ll-recording-input="text"]').val() || '').toString();
            const translation = ($rec.find('[data-ll-recording-input="translation"]').val() || '').toString();
            const ipa = normalizeIpaForStorage(($rec.find('[data-ll-recording-input="ipa"]').val() || '').toString());
            recordings.push({
                id: recId,
                recording_text: text,
                recording_translation: translation,
                recording_ipa: ipa,
                review_fields: getRecordingReviewFields($rec)
            });
        });
        return recordings;
    }

    function collectLessonEditProcessingOptions($recording) {
        return {
            enableTrim: $recording.find('[data-ll-processing-option="trim"]').first().prop('checked') !== false,
            enableNoise: $recording.find('[data-ll-processing-option="noise"]').first().prop('checked') !== false,
            enableLoudness: $recording.find('[data-ll-processing-option="loudness"]').first().prop('checked') !== false
        };
    }

    function setLessonEditProcessingStatus($recording, message, tone) {
        const $status = $recording.find('[data-ll-processing-status]').first();
        if (!$status.length) { return; }
        $status
            .removeClass('is-error is-success')
            .toggleClass('is-error', tone === 'error')
            .toggleClass('is-success', tone === 'success')
            .text(message || '');
    }

    async function buildLessonEditProcessedAudio($recording) {
        const ctx = ensureLessonEditAudioContext();
        if (!ctx) {
            throw new Error(editMessages.audioUnsupportedError);
        }
        if (ctx.state === 'suspended' && typeof ctx.resume === 'function') {
            try { await ctx.resume(); } catch (_) {}
        }

        const state = await ensureLessonEditProcessingWaveform($recording);
        const originalBuffer = state.originalBuffer;
        const options = collectLessonEditProcessingOptions($recording);
        let processedBuffer = originalBuffer;
        let trimStart = 0;
        let trimEnd = originalBuffer.length;
        const hasManualTrim = !!state.manualBoundaries;

        if (options.enableTrim || hasManualTrim) {
            trimStart = clampLessonEditSample(Math.floor(state.trimStart), 0, Math.max(0, originalBuffer.length - 1));
            trimEnd = clampLessonEditSample(Math.ceil(state.trimEnd), trimStart + 1, originalBuffer.length);
            processedBuffer = trimLessonEditAudioBuffer(ctx, originalBuffer, trimStart, trimEnd);
        }

        if (options.enableNoise) {
            processedBuffer = await reduceLessonEditNoise(processedBuffer);
        }
        if (options.enableLoudness) {
            processedBuffer = normalizeLessonEditLoudness(ctx, processedBuffer);
        }

        return {
            audioBlob: lessonEditAudioBufferToWav(processedBuffer),
            trimStart: trimStart,
            trimEnd: trimEnd,
            sourceSamples: originalBuffer.length,
            sampleRate: originalBuffer.sampleRate,
            options: options,
            usedManualTrim: hasManualTrim
        };
    }

    function postLessonEditProcessedAudio(formData) {
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(resolve).fail(reject);
        });
    }

    function updateLessonEditRecordingAudio($item, recordingId, data) {
        const recId = parseInt(recordingId, 10) || 0;
        if (!recId || !data || typeof data !== 'object') { return; }

        const audioUrl = (data.audio_url || '').toString();
        const sourceUrl = (data.processing_source_audio_url || audioUrl).toString();
        const usesOriginal = !!data.uses_original_audio;
        const hasOriginal = !!data.has_original_audio;
        const selector = '[data-recording-id="' + recId + '"]';

        const $recording = $item.find('.ll-word-edit-recording' + selector).first();
        if ($recording.length) {
            $recording.attr({
                'data-ll-current-audio-url': audioUrl,
                'data-ll-processing-source-audio-url': sourceUrl,
                'data-ll-uses-original-audio': usesOriginal ? '1' : '0',
                'data-ll-has-original-audio': hasOriginal ? '1' : '0'
            });
            $recording.find('[data-ll-processing-source-label]').first().text(usesOriginal ? editMessages.sourceOriginal : editMessages.sourceCurrent);
            $recording.find('[data-ll-process-recording-audio]').first().text(hasOriginal ? editMessages.reprocessAudio : editMessages.processAudio);
            if (sourceUrl) {
                waveformCache.delete(sourceUrl);
                waveformPending.delete(sourceUrl);
            }
            if (audioUrl && audioUrl !== sourceUrl) {
                waveformCache.delete(audioUrl);
                waveformPending.delete(audioUrl);
            }
            const $player = $recording.find('.ll-word-edit-ipa-audio-player').first();
            if ($player.length && audioUrl) {
                const audioEl = $player.get(0);
                if (audioEl && typeof audioEl.pause === 'function') {
                    try { audioEl.pause(); } catch (_) {}
                }
                $player.attr('src', audioUrl);
                if (audioEl && typeof audioEl.load === 'function') {
                    try { audioEl.load(); } catch (_) {}
                }
            }
            const $download = $recording.find('[data-ll-processing-download-audio]').first();
            if ($download.length) {
                if (audioUrl) {
                    $download.attr('href', audioUrl);
                } else {
                    $download.removeAttr('href');
                }
            }
            clearLessonEditProcessingState($recording);
            ensureLessonEditProcessingWaveform($recording).catch(function () {});
        }

        if (audioUrl) {
            $item.find('.ll-word-grid-recording-btn' + selector).attr('data-audio-url', audioUrl);
            $item.find('.ll-word-grid-recording-btn[data-recording-id="' + recId + '"]').attr('data-audio-url', audioUrl);
        }

        if (currentAudioButton && parseInt($(currentAudioButton).attr('data-recording-id'), 10) === recId && currentAudio) {
            try { currentAudio.pause(); } catch (_) {}
            currentAudio = null;
            currentAudioButton = null;
        }
    }

    function stopRecordingPlayback(recordingId) {
        const recId = parseInt(recordingId, 10) || 0;
        if (!recId) { return; }
        if (currentAudioButton && parseInt($(currentAudioButton).attr('data-recording-id'), 10) === recId && currentAudio) {
            try { currentAudio.pause(); } catch (_) {}
            currentAudio = null;
            currentAudioButton = null;
        }
    }

    function closeRecordingPanels($recording) {
        if (!$recording || !$recording.length) { return; }
        $recording.find('[data-ll-recording-delete-confirm]').prop('hidden', true);
        const $movePanel = $recording.find('[data-ll-recording-move-panel]').first();
        $movePanel.prop('hidden', true);
        $movePanel.find('[data-ll-recording-move-status]').text('').removeClass('is-error is-success');
    }

    function setRecordingMoveStatus($recording, message, tone) {
        const $status = $recording.find('[data-ll-recording-move-status]').first();
        if (!$status.length) { return; }
        $status
            .removeClass('is-error is-success')
            .toggleClass('is-error', tone === 'error')
            .toggleClass('is-success', tone === 'success')
            .text(message || '');
    }

    function cleanupEmptyRecordingContainers($item) {
        if (!$item || !$item.length) { return; }
        const $recordingPanel = $item.find('[data-ll-word-recordings-panel]').first();
        if ($recordingPanel.length && !$recordingPanel.find('.ll-word-edit-recording[data-recording-id]').length) {
            $recordingPanel.remove();
            $item.find('[data-ll-word-recordings-toggle]').first().remove();
        }

        const $visibleWrap = $item.find('> .ll-word-recordings').first();
        if ($visibleWrap.length && !$visibleWrap.find('.ll-word-grid-recording-btn[data-recording-id]').length && !$visibleWrap.find('.ll-word-recording-launch').length) {
            $visibleWrap.remove();
        }
    }

    function ensureRecordingEditorPanel($item) {
        if (!$item || !$item.length) { return $(); }
        let $panel = $item.find('[data-ll-word-recordings-panel]').first();
        if ($panel.length) { return $panel; }

        const $body = $item.find('[data-ll-word-edit-body]').first();
        if (!$body.length) { return $(); }

        const $toggle = $('<button>', {
            type: 'button',
            class: 'll-word-edit-recordings-toggle',
            'aria-expanded': 'true'
        }).attr('data-ll-word-recordings-toggle', '');
        $('<span>', { class: 'll-word-edit-recordings-icon', 'aria-hidden': 'true' })
            .append('<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M4 10v4M9 6v12M14 8v8M19 11v2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>')
            .appendTo($toggle);
        $('<span>', { class: 'll-word-edit-recordings-label' })
            .text(editMessages.recordings)
            .appendTo($toggle);

        $panel = $('<div>', {
            class: 'll-word-edit-recordings',
            'aria-hidden': 'false'
        }).attr('data-ll-word-recordings-panel', '');

        const $danger = $body.find('[data-ll-word-edit-danger]').first();
        if ($danger.length) {
            $toggle.insertBefore($danger);
            $panel.insertBefore($danger);
        } else {
            $body.append($toggle, $panel);
        }
        $item.addClass('ll-word-recordings-open');

        return $panel;
    }

    function ensureVisibleRecordingWrap($item) {
        if (!$item || !$item.length) { return $(); }
        let $wrap = $item.find('> .ll-word-recordings').first();
        if ($wrap.length) { return $wrap; }

        $wrap = $('<div>', {
            class: 'll-word-recordings',
            'aria-label': editMessages.recordings
        });
        const $editPanel = $item.find('> [data-ll-word-edit-panel]').first();
        if ($editPanel.length) {
            $wrap.insertAfter($editPanel);
        } else {
            $item.append($wrap);
        }

        return $wrap;
    }

    function removeRecordingDom($item, recordingId) {
        const recId = parseInt(recordingId, 10) || 0;
        if (!recId || !$item || !$item.length) { return; }
        stopRecordingPlayback(recId);

        const selector = '[data-recording-id="' + recId + '"]';
        const $recording = $item.find('.ll-word-edit-recording' + selector).first();
        if ($recording.length) {
            clearLessonEditProcessingState($recording);
            $recording.remove();
        }

        $item.find('.ll-word-recording-row' + selector).remove();
        const $visibleButton = $item.find('> .ll-word-recordings .ll-word-grid-recording-btn' + selector).first();
        if ($visibleButton.length) {
            const $buttonRow = $visibleButton.closest('.ll-word-recording-row');
            if ($buttonRow.length) {
                $buttonRow.remove();
            } else {
                $visibleButton.remove();
            }
        }

        cleanupEmptyRecordingContainers($item);
        applyRecordingCaptions($item, collectRecordingInputs($item));
        updateOriginalInputs($item);
        updateGridLayouts();
    }

    function attachMovedRecordingDom($sourceItem, $targetItem, recordingId) {
        const recId = parseInt(recordingId, 10) || 0;
        if (!recId || !$sourceItem || !$sourceItem.length || !$targetItem || !$targetItem.length) {
            return false;
        }

        const selector = '[data-recording-id="' + recId + '"]';
        const $recording = $sourceItem.find('.ll-word-edit-recording' + selector).first();
        const $targetPanel = ensureRecordingEditorPanel($targetItem);
        let movedAny = false;

        if ($recording.length && $targetPanel.length) {
            closeRecordingPanels($recording);
            clearLessonEditProcessingState($recording);
            $recording.detach().appendTo($targetPanel);
            initRecordingMoveAutocomplete($recording.find('[data-ll-recording-move-search]').first());
            movedAny = true;
        } else if ($recording.length) {
            clearLessonEditProcessingState($recording);
            $recording.remove();
        }

        const $row = $sourceItem.find('.ll-word-recording-row' + selector).first();
        const $targetVisibleWrap = ensureVisibleRecordingWrap($targetItem);
        if ($row.length && $targetVisibleWrap.length) {
            $row.detach().appendTo($targetVisibleWrap);
            movedAny = true;
        } else if ($row.length) {
            $row.remove();
        } else {
            const $visibleButton = $sourceItem.find('> .ll-word-recordings .ll-word-grid-recording-btn' + selector).first();
            if ($visibleButton.length && $targetVisibleWrap.length) {
                $visibleButton.detach().appendTo($targetVisibleWrap);
                movedAny = true;
            } else if ($visibleButton.length) {
                $visibleButton.remove();
            }
        }

        if (movedAny) {
            cleanupEmptyRecordingContainers($sourceItem);
            applyRecordingCaptions($sourceItem, collectRecordingInputs($sourceItem));
            applyRecordingCaptions($targetItem, collectRecordingInputs($targetItem));
            updateOriginalInputs($sourceItem);
            updateOriginalInputs($targetItem);
            updateGridLayouts();
        }

        return movedAny;
    }

    function setTranscribeStatus($wrap, message, isError) {
        const $status = $wrap.find('[data-ll-transcribe-status]').first();
        if (!$status.length) { return; }
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
    }

    function formatTranscribeProgress(template, current, total) {
        return (template || '').replace('%1$d', String(current)).replace('%2$d', String(total));
    }

    function normalizeRecordingReviewFields(value) {
        const fields = {
            recording_text: false,
            recording_ipa: false
        };
        if (!value) { return fields; }
        if (Array.isArray(value)) {
            value.forEach(function (field) {
                if (field === 'recording_text' || field === 'text' || field === 'orthography') {
                    fields.recording_text = true;
                } else if (field === 'recording_ipa' || field === 'ipa' || field === 'transcription') {
                    fields.recording_ipa = true;
                }
            });
            return fields;
        }
        if (typeof value === 'object') {
            fields.recording_text = !!(value.recording_text || value.text || value.orthography);
            fields.recording_ipa = !!(value.recording_ipa || value.ipa || value.transcription);
        }
        return fields;
    }

    function getRecordingReviewFields($recording) {
        const fields = {};
        $recording.find('[data-ll-recording-review-toggle]').each(function () {
            const $btn = $(this);
            const field = ($btn.attr('data-review-field') || '').toString();
            if ((field === 'recording_text' || field === 'recording_ipa') && ($btn.attr('aria-pressed') || 'false') === 'true') {
                fields[field] = true;
            }
        });
        return fields;
    }

    function setRecordingReviewToggle($recording, field, active) {
        const $wrap = $recording.find('[data-ll-recording-review-field="' + field + '"]').first();
        const $btn = $recording.find('[data-ll-recording-review-toggle][data-review-field="' + field + '"]').first();
        $wrap.toggleClass('is-needs-review', !!active);
        if ($btn.length) {
            const label = active
                ? ($btn.attr('data-review-on-label') || $btn.attr('aria-label') || '')
                : ($btn.attr('data-review-off-label') || $btn.attr('aria-label') || '');
            $btn.attr({
                'aria-pressed': active ? 'true' : 'false',
                'aria-label': label,
                title: label
            });
        }
    }

    function applyRecordingReviewFields($recording, reviewFields, reviewNote) {
        const fields = normalizeRecordingReviewFields(reviewFields);
        setRecordingReviewToggle($recording, 'recording_text', fields.recording_text);
        setRecordingReviewToggle($recording, 'recording_ipa', fields.recording_ipa);
        const $note = $recording.find('[data-ll-recording-review-note]').first();
        if (!fields.recording_text && !fields.recording_ipa) {
            $note.remove();
        } else if (typeof reviewNote === 'string' && reviewNote.trim() !== '') {
            if ($note.length) {
                $note.text(reviewNote);
            } else {
                $('<div>', {
                    class: 'll-word-edit-review-note',
                    'data-ll-recording-review-note': '1',
                    text: reviewNote
                }).insertAfter($recording.find('.ll-word-edit-recording-header').first());
            }
        }
    }

    function getRecordingCaptionParts(text, translation, ipa, reviewFields) {
        const cleanText = (text || '').toString().trim();
        const cleanTranslation = (translation || '').toString().trim();
        const cleanIpa = normalizeIpaOutput(ipa);
        return {
            text: cleanText,
            translation: cleanTranslation,
            ipa: cleanIpa,
            reviewFields: normalizeRecordingReviewFields(reviewFields),
            hasCaption: !!(cleanText || cleanTranslation || cleanIpa)
        };
    }

    function normalizeIpaOutput(value) {
        const raw = (value || '').toString();
        if (secondaryTextMode !== 'ipa') {
            return raw.replace(/[\r\n\t\u00A0]+/g, ' ').replace(/\s+/g, ' ').trim();
        }
        return raw.replace(/\u1D2E/g, '\u{10784}').replace(/\u0131/g, '\u026A').trim();
    }

    function normalizeIpaForStorage(value) {
        const raw = sanitizeIpaValue(value);
        if (secondaryTextMode !== 'ipa') {
            return raw;
        }
        if (supportsIpaExtended) {
            return raw;
        }
        return raw.replace(/\u{10784}/gu, '\u1D2E');
    }

    function renderRecordingCaption($row, parts) {
        if (!$row || !parts) { return; }
        let $textWrap = $row.find('.ll-word-recording-text').first();

        if (!parts.hasCaption) {
            $textWrap.remove();
            return;
        }

        if (!$textWrap.length) {
            $textWrap = $('<span>', { class: 'll-word-recording-text' }).appendTo($row);
        }

        let $main = $textWrap.find('.ll-word-recording-text-main').first();
        if (parts.text) {
            if (!$main.length) {
                $main = $('<span>', { class: 'll-word-recording-text-main', dir: 'auto' }).appendTo($textWrap);
            }
            $main.attr('dir', 'auto');
            $main.toggleClass('ll-word-recording-text-main--needs-review', !!(parts.reviewFields && parts.reviewFields.recording_text));
            $main.text(protectMaqafNoBreak(parts.text));
        } else {
            $main.remove();
        }

        let $translation = $textWrap.find('.ll-word-recording-text-translation').first();
        if (parts.translation) {
            if (!$translation.length) {
                $translation = $('<span>', { class: 'll-word-recording-text-translation', dir: 'auto' }).appendTo($textWrap);
            }
            $translation.attr('dir', 'auto');
            $translation.text(protectMaqafNoBreak(parts.translation));
        } else {
            $translation.remove();
        }

        let $ipa = $textWrap.find('.ll-word-recording-ipa').first();
        if (parts.ipa) {
            if (!$ipa.length) {
                $ipa = $('<span>', { class: 'll-word-recording-ipa' }).appendTo($textWrap);
            }
            $ipa.toggleClass('ll-ipa', secondaryTextUsesIpaFont);
            $ipa.toggleClass('ll-word-recording-ipa--needs-review', !!(parts.reviewFields && parts.reviewFields.recording_ipa));
            $ipa.text(normalizeIpaOutput(parts.ipa));
        } else {
            $ipa.remove();
        }

        if (!$textWrap.children().length) {
            $textWrap.remove();
        }
    }

    const recordingEditTriggerIconMarkup = '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">'
        + '<path d="M4 20.5h4l10-10-4-4-10 10v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '<path d="M13.5 6.5l4 4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '</svg>';

    function syncRecordingRowEditTrigger($row) {
        if (!$row || !$row.length) { return; }

        const $button = $row.find('.ll-word-grid-recording-btn').first();
        const recId = parseInt($row.attr('data-recording-id'), 10)
            || parseInt($button.attr('data-recording-id'), 10)
            || 0;
        const editLabel = ($button.attr('data-ll-recording-edit-label') || '').toString();
        let $trigger = $row.find('[data-ll-recording-edit-trigger]').first();

        if (!recId || editLabel === '') {
            $row.removeClass('ll-word-recording-row--editable is-edit-trigger-visible');
            if ($trigger.length) {
                $trigger.remove();
            }
            return;
        }

        $row.attr('data-recording-id', String(recId));
        $row.addClass('ll-word-recording-row--editable');

        if (!$trigger.length) {
            $trigger = $('<button>', {
                type: 'button',
                class: 'll-word-recording-edit-trigger',
                'data-ll-recording-edit-trigger': '1'
            });
            $trigger.append(recordingEditTriggerIconMarkup);
            $row.append($trigger);
        }

        $trigger.attr({
            'data-recording-id': String(recId),
            'aria-label': editLabel,
            title: editLabel
        });
    }

    function clearVisibleRecordingRowEditTriggers($scope) {
        const $context = ($scope && $scope.length) ? $scope : $grids;
        $context.find('.ll-word-recording-row.is-edit-trigger-visible').removeClass('is-edit-trigger-visible');
    }

    function revealRecordingRowEditTrigger($row) {
        if (!$row || !$row.length || !$row.hasClass('ll-word-recording-row--editable')) {
            return;
        }

        clearVisibleRecordingRowEditTriggers($row.closest('.word-item'));
        $row.addClass('is-edit-trigger-visible');
    }

    function openRecordingEditor($item, recordingId) {
        if (!$item || !$item.length || $item.hasClass('ll-word-save-pending')) {
            return;
        }

        const recId = parseInt(recordingId, 10) || 0;
        if (!recId) {
            return;
        }

        const $recording = $item.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]').first();
        if (!$recording.length) {
            return;
        }

        setWordSaveStatus($item, '', '');
        setEditStatus($item, '');
        setEditPanelOpen($item, true);
        setRecordingsPanelOpen($item, true);
        clearVisibleRecordingRowEditTriggers($item);

        window.requestAnimationFrame(function () {
            const recordingEl = $recording.get(0);
            if (recordingEl && typeof recordingEl.scrollIntoView === 'function') {
                recordingEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }

            const $firstInput = $recording
                .find('[data-ll-recording-input="text"], [data-ll-recording-input="translation"], [data-ll-recording-input="ipa"], input, textarea, select')
                .filter(':enabled:visible')
                .first();

            if ($firstInput.length) {
                $firstInput.trigger('focus');
                const inputEl = $firstInput.get(0);
                if (inputEl && typeof inputEl.select === 'function' && ($firstInput.is('input[type="text"]') || $firstInput.is('textarea'))) {
                    inputEl.select();
                }
            }
        });
    }

    const ipaAllowedChar = /[a-z\u00C0-\u02FF\u0300-\u036F\u0370-\u03FF\u1D00-\u1DFF\u{10784}\. ]/u;
    const nonIpaAllowedChar = /[\p{L}\p{M}\p{N}\u02B0-\u02FF.\-'’\s]/u;
    const ipaCombiningMark = /[\u0300-\u036F]/u;
    const ipaPostModifier = /[\u02B0-\u02B8\u02D0\u02D1\u02E0-\u02E4\u1D2C-\u1D6A\u1D9B-\u1DBF\u2070-\u209F\u{10784}]/u;
    const ipaStressMarker = /[\u02C8\u02CC]/u;
    const ipaUppercaseMap = {
        'R': 'ʀ',
        'B': 'ʙ',
        'G': 'ɢ'
    };
    const ipaSuperscriptMap = {
        'a': 'ᵃ',
        'b': 'ᵇ',
        'c': 'ᶜ',
        'd': 'ᵈ',
        'e': 'ᵉ',
        'f': 'ᶠ',
        'g': 'ᵍ',
        'h': 'ʰ',
        'i': 'ᶦ',
        'j': 'ʲ',
        'k': 'ᵏ',
        'l': 'ˡ',
        'm': 'ᵐ',
        'n': 'ᶰ',
        'o': 'ᵒ',
        'p': 'ᵖ',
        'r': 'ʳ',
        's': 'ˢ',
        't': 'ᵗ',
        'u': 'ᵘ',
        'v': 'ᵛ',
        'w': 'ʷ',
        'x': 'ˣ',
        'y': 'ʸ',
        'z': 'ᶻ',
        'A': 'ᴬ',
        'B': '\u{10784}',
        'D': 'ᴰ',
        'E': 'ᴱ',
        'G': 'ᴳ',
        'H': 'ᴴ',
        'I': 'ᴵ',
        'J': 'ᴶ',
        'K': 'ᴷ',
        'L': 'ᴸ',
        'M': 'ᴹ',
        'N': 'ᴺ',
        'O': 'ᴼ',
        'P': 'ᴾ',
        'R': 'ᴿ',
        'T': 'ᵀ',
        'U': 'ᵁ',
        'W': 'ᵂ',
        'Y': 'ᵞ',
        'ʙ': '\u{10784}',
        'ɢ': 'ᴳ',
        'ʜ': 'ᴴ',
        'ɪ': 'ᴵ',
        'ʟ': 'ᴸ',
        'ɴ': 'ᴺ',
        'ʀ': 'ᴿ',
        'ʊ': 'ᵁ',
        'ʏ': 'ᵞ'
    };
    const ipaMatchMap = {
        '\u027e': 'r',
        '\u0279': 'r',
        '\u027b': 'r',
        '\u0280': 'r',
        '\u0281': 'r',
        '\u027d': 'r',
        '\u029c': 'h',
        '\u0266': 'h',
        '\u0283': 'sh',
        '\u0292': 'zh',
        '\u03b8': 'th',
        '\u00f0': 'th',
        '\u014b': 'ng',
        '\u0272': 'ny',
        '\u0250': 'a',
        '\u0251': 'a',
        '\u0252': 'o',
        '\u00e6': 'a',
        '\u025b': 'e',
        '\u025c': 'e',
        '\u0259': 'e',
        '\u026a': 'i',
        '\u028a': 'u',
        '\u028c': 'u',
        '\u0254': 'o',
        '\u026f': 'u',
        '\u0268': 'i',
        '\u0289': 'u',
        '\u00f8': 'o',
        '\u0153': 'oe',
        '\u0276': 'oe',
        '\u0261': 'g',
        '\u0263': 'g',
        '\u028b': 'v'
    };
    const transcriptionMatchMap = {
        'ā': 'a',
        'ă': 'a',
        'â': 'a',
        'á': 'a',
        'à': 'a',
        'ä': 'a',
        'ã': 'a',
        'ē': 'e',
        'ĕ': 'e',
        'ê': 'e',
        'é': 'e',
        'è': 'e',
        'ë': 'e',
        'ī': 'i',
        'ĭ': 'i',
        'î': 'i',
        'í': 'i',
        'ì': 'i',
        'ï': 'i',
        'ō': 'o',
        'ŏ': 'o',
        'ô': 'o',
        'ó': 'o',
        'ò': 'o',
        'ö': 'o',
        'õ': 'o',
        'ū': 'u',
        'ŭ': 'u',
        'û': 'u',
        'ú': 'u',
        'ù': 'u',
        'ü': 'u',
        'ḥ': 'h',
        'ḫ': 'h',
        'ṭ': 't',
        'ṣ': 's',
        'š': 'sh',
        'ś': 's',
        'ḏ': 'd',
        'ḇ': 'v',
        'ʾ': 'a',
        'ʿ': 'a',
        "'": 'a',
        '’': 'a',
        'ʻ': 'a',
        'א': 'a',
        'ע': 'a',
        'ב': 'b',
        'ג': 'g',
        'ד': 'd',
        'ה': 'h',
        'ו': 'w',
        'ז': 'z',
        'ח': 'h',
        'ט': 't',
        'י': 'y',
        'כ': 'k',
        'ך': 'k',
        'ל': 'l',
        'מ': 'm',
        'ם': 'm',
        'נ': 'n',
        'ן': 'n',
        'ס': 's',
        'פ': 'p',
        'ף': 'p',
        'צ': 's',
        'ץ': 's',
        'ק': 'q',
        'ר': 'r',
        'ש': 'sh',
        'ת': 't'
    };
    const secondaryTextSortBaseMap = secondaryTextMode === 'ipa'
        ? Object.assign({}, ipaMatchMap, {
            'β': 'v',
            'ɱ': 'm',
            'ɳ': 'n',
            'ɴ': 'n',
            'ɫ': 'l',
            'ɭ': 'l',
            'ʎ': 'l',
            'ʟ': 'l',
            'ɬ': 'l',
            'ɮ': 'l',
            'ɕ': 'sh',
            'ʑ': 'zh',
            'ʂ': 'sh',
            'ʐ': 'zh',
            'ɟ': 'j',
            'ʝ': 'j',
            'ç': 'h',
            'χ': 'x',
            'ħ': 'h',
            'ʔ': 'q',
            'ʕ': 'h'
        })
        : Object.assign({}, transcriptionMatchMap);
    const secondaryTextSortModifierMap = {
        'ˈ': '00stress',
        'ˌ': '01stress',
        'ʰ': '10h',
        'ʱ': '11h',
        'ʲ': '12y',
        'ʷ': '13w',
        'ʳ': '14r',
        'ʴ': '15r',
        'ʵ': '16e',
        'ˠ': '17v',
        'ˤ': '18p',
        '˞': '19r',
        'ː': '20long',
        'ˑ': '21half',
        'ˀ': '22glot',
        'ʼ': '23ej',
        'ⁿ': '24n',
        'ˡ': '25l',
        '̃': '30nasal',
        '̩': '31syll',
        '̯': '32off'
    };
    let activeIpaInput = null;
    let activeIpaSelection = null;
    let lastIpaEdit = { input: null, type: null, time: 0 };
    let waveformContext = null;
    const waveformCache = new Map();
    const waveformPending = new Map();

    function applyTranscriptionMatchMap(segment) {
        const value = (segment || '').toString();
        if (!value) { return ''; }
        let out = '';
        for (const ch of value) {
            out += transcriptionMatchMap[ch] || ch;
        }
        return out;
    }

    function getSecondaryTextSymbolSortMeta(symbol) {
        const raw = sanitizeIpaValue(symbol);
        if (!raw) {
            return { family: '', modifier: '', modifierRank: 0, plainRank: 1, raw: '' };
        }

        let family = '';
        let modifier = '';
        Array.from(raw.toLowerCase()).forEach(function (ch) {
            if (!ch || /[\s.]/u.test(ch)) { return; }

            if (secondaryTextMode === 'ipa') {
                if (isTieBar(ch)) { return; }
                if (isCombiningMark(ch) || isPostModifier(ch) || isIpaStressMarker(ch)) {
                    modifier += secondaryTextSortModifierMap[ch] || ('zz' + encodeURIComponent(ch).replace(/%/g, '').toLowerCase());
                    return;
                }
            } else if (isCombiningMark(ch)) {
                modifier += secondaryTextSortModifierMap[ch] || ('zz' + encodeURIComponent(ch).replace(/%/g, '').toLowerCase());
                return;
            }

            let mapped = secondaryTextSortBaseMap[ch] || '';
            if (!mapped && /^[a-z0-9]$/u.test(ch)) {
                mapped = ch;
            }
            if (!mapped) {
                mapped = applyTranscriptionMatchMap(ch).toLowerCase().replace(/[^a-z0-9]+/g, '');
            }
            if (!mapped) {
                mapped = 'zz' + encodeURIComponent(ch).replace(/%/g, '').toLowerCase();
            }
            family += mapped;
        });

        return {
            family: family || '0',
            modifier: modifier,
            modifierRank: modifier ? 1 : 0,
            plainRank: /^[a-z]+$/u.test(raw.toLowerCase()) ? 0 : 1,
            raw: raw
        };
    }

    function compareSecondaryTextSymbols(left, right) {
        const leftMeta = getSecondaryTextSymbolSortMeta(left);
        const rightMeta = getSecondaryTextSymbolSortMeta(right);

        if (leftMeta.family !== rightMeta.family) {
            return leftMeta.family < rightMeta.family ? -1 : 1;
        }
        if (leftMeta.modifierRank !== rightMeta.modifierRank) {
            return leftMeta.modifierRank - rightMeta.modifierRank;
        }
        if (leftMeta.modifier !== rightMeta.modifier) {
            return leftMeta.modifier < rightMeta.modifier ? -1 : 1;
        }
        if (leftMeta.plainRank !== rightMeta.plainRank) {
            return leftMeta.plainRank - rightMeta.plainRank;
        }
        return leftMeta.raw.localeCompare(rightMeta.raw);
    }

    function sortSecondaryTextSymbols(symbols) {
        if (!Array.isArray(symbols) || !symbols.length) { return []; }
        return symbols.slice().sort(compareSecondaryTextSymbols);
    }

    function normalizeIpaChar(ch) {
        if (secondaryTextMode !== 'ipa') {
            return ch;
        }
        if (ch === '\u1D2E') {
            return '\u{10784}';
        }
        if (ch === '\u0131') {
            return '\u026A';
        }
        if (ch === "'" || ch === '’') {
            return '\u02C8';
        }
        if (/[A-Z]/.test(ch)) {
            return ipaUppercaseMap[ch] || ch.toLowerCase();
        }
        return ch;
    }

    function sanitizeIpaValue(value) {
        const raw = (value || '').toString();
        const chars = Array.from(raw);
        let out = '';
        chars.forEach(function (ch) {
            const normalized = normalizeIpaChar(ch);
            const allowed = secondaryTextMode === 'ipa' ? ipaAllowedChar : nonIpaAllowedChar;
            if (allowed.test(normalized)) {
                out += normalized;
            }
        });
        const hadTrailingSpace = /\s$/.test(out);
        out = out.replace(/\s+/g, ' ').replace(/^\s+/, '');
        if (hadTrailingSpace) {
            out = out.replace(/\s+$/, ' ');
        } else {
            out = out.trim();
        }
        return out;
    }

    function updateIpaSelection(input) {
        if (!input || typeof input.selectionStart !== 'number' || typeof input.selectionEnd !== 'number') {
            activeIpaSelection = null;
            return;
        }
        activeIpaSelection = {
            start: input.selectionStart,
            end: input.selectionEnd
        };
        if (input === activeIpaInput) {
            refreshIpaKeyboardForInput(input);
        }
    }

    function setLastIpaEdit(input, type) {
        lastIpaEdit = {
            input: input || null,
            type: type || null,
            time: Date.now()
        };
    }

    function getLastIpaEditType(input) {
        if (!input || lastIpaEdit.input !== input) {
            return null;
        }
        if (!lastIpaEdit.time || (Date.now() - lastIpaEdit.time) > 1500) {
            return null;
        }
        return lastIpaEdit.type || null;
    }

    function updateLastIpaEditFromInputEvent(event, input) {
        const original = event && event.originalEvent ? event.originalEvent : event;
        const inputType = original && original.inputType ? original.inputType : '';
        if (!inputType) {
            return;
        }
        if (inputType.indexOf('delete') === 0) {
            setLastIpaEdit(input, 'delete');
        } else if (inputType.indexOf('insert') === 0) {
            setLastIpaEdit(input, 'insert');
        } else {
            setLastIpaEdit(input, null);
        }
    }

    function toIpaSuperscript(text) {
        const chars = Array.from((text || '').toString());
        if (!chars.length) { return ''; }

        const clusters = [];
        chars.forEach(function (ch) {
            if (ipaCombiningMark.test(ch)) {
                if (!clusters.length) {
                    clusters.push([ch]);
                } else {
                    clusters[clusters.length - 1].push(ch);
                }
                return;
            }
            clusters.push([ch]);
        });

        return clusters.map(function (cluster) {
            if (!cluster.length) { return ''; }
            const base = cluster[0];
            if (ipaCombiningMark.test(base)) {
                return cluster.join('');
            }
            let baseOut = ipaSuperscriptMap[base] || base;
            return baseOut + cluster.slice(1).join('');
        }).join('');
    }

    function getIpaSelectionBeforeCursor(input, cursor) {
        if (!input || typeof cursor !== 'number' || cursor <= 0) {
            return null;
        }
        const value = (input.value || '').toString();
        if (!value) {
            return null;
        }
        const codePoints = [];
        let index = 0;
        for (const ch of value) {
            const start = index;
            const end = index + ch.length;
            codePoints.push({ ch: ch, start: start, end: end });
            index = end;
        }
        if (!codePoints.length) {
            return null;
        }
        let lastIndex = -1;
        for (let i = 0; i < codePoints.length; i += 1) {
            if (codePoints[i].end <= cursor) {
                lastIndex = i;
            } else {
                break;
            }
        }
        if (lastIndex < 0) {
            return null;
        }
        let startIndex = lastIndex;
        if (isCombiningMark(codePoints[lastIndex].ch) || isPostModifier(codePoints[lastIndex].ch)) {
            while (startIndex >= 0
                && (isCombiningMark(codePoints[startIndex].ch) || isPostModifier(codePoints[startIndex].ch))) {
                startIndex -= 1;
            }
            if (startIndex < 0) {
                startIndex = lastIndex;
            }
        }
        return {
            start: codePoints[startIndex].start,
            end: codePoints[lastIndex].end
        };
    }

    function applySuperscriptToSelection(input) {
        if (!input) { return; }
        const hasNativeSelection = (typeof input.selectionStart === 'number' && typeof input.selectionEnd === 'number');
        let selection = hasNativeSelection ? { start: input.selectionStart, end: input.selectionEnd } : null;
        if (!selection || selection.end <= selection.start) {
            selection = hasNativeSelection
                ? getIpaSelectionBeforeCursor(input, input.selectionStart)
                : null;
        }
        if ((!selection || selection.end <= selection.start) && !hasNativeSelection && activeIpaSelection) {
            selection = activeIpaSelection;
        }
        if (!selection || selection.end <= selection.start) {
            return;
        }
        const value = (input.value || '').toString();
        const selected = value.slice(selection.start, selection.end);
        if (!selected) { return; }
        const transformed = toIpaSuperscript(selected);
        if (transformed === selected) { return; }
        input.value = value.slice(0, selection.start) + transformed + value.slice(selection.end);
        const newEnd = selection.start + transformed.length;
        if (input.setSelectionRange) {
            input.setSelectionRange(selection.start, newEnd);
        }
        setLastIpaEdit(input, 'insert');
        $(input).trigger('input');
        updateIpaSelection(input);
    }

    function extractIpaSpecialChars(value) {
        const tokens = tokenizeIpa(value);
        const found = [];
        const seen = new Set();
        tokens.forEach(function (token) {
            if (!isSpecialIpaToken(token)) { return; }
            if (seen.has(token)) { return; }
            seen.add(token);
            found.push(token);
        });
        return found;
    }

    function isIpaSeparator(ch) {
        if (secondaryTextMode !== 'ipa') {
            return ch === '.' || ch === '-' || /\s/.test(ch);
        }
        return ch === '.' || /\s/.test(ch);
    }

    function isCombiningMark(ch) {
        return ipaCombiningMark.test(ch);
    }

    function isPostModifier(ch) {
        if (secondaryTextMode !== 'ipa') {
            return false;
        }
        return ipaPostModifier.test(ch);
    }

    function isIpaStressMarker(ch) {
        if (secondaryTextMode !== 'ipa') {
            return false;
        }
        return ipaStressMarker.test(ch);
    }

    function isTieBar(ch) {
        if (secondaryTextMode !== 'ipa') {
            return false;
        }
        return ch === '\u0361' || ch === '\u035C';
    }

    function stripStressMarkersFromToken(token) {
        if (secondaryTextMode !== 'ipa' || !token) { return token || ''; }
        return token.replace(/\u02C8|\u02CC/g, '');
    }

    function filterIpaTokensForMapping(tokens) {
        if (!Array.isArray(tokens) || !tokens.length) { return []; }
        const cleaned = [];
        tokens.forEach(function (token) {
            if (!token) { return; }
            const stripped = stripStressMarkersFromToken(token);
            if (!stripped) { return; }
            if (isIpaStressMarker(stripped)) { return; }
            cleaned.push(stripped);
        });
        return cleaned;
    }

    function tokenizeIpa(value) {
        const sanitized = sanitizeIpaValue(value);
        if (!sanitized) { return []; }
        const chars = Array.from(sanitized);

        if (secondaryTextMode !== 'ipa') {
            const tokens = [];
            let buffer = '';

            chars.forEach(function (ch) {
                if (isIpaSeparator(ch)) {
                    if (buffer) {
                        tokens.push(buffer);
                        buffer = '';
                    }
                    return;
                }

                if (isCombiningMark(ch)) {
                    if (!buffer) {
                        buffer = ch;
                    } else {
                        buffer += ch;
                    }
                    return;
                }

                if (buffer) {
                    tokens.push(buffer);
                }
                buffer = ch;
            });

            if (buffer) {
                tokens.push(buffer);
            }

            return tokens;
        }

        const tokens = [];
        let buffer = '';
        let pending = '';
        let tiePending = false;

        chars.forEach(function (ch) {
            if (isIpaSeparator(ch)) {
                if (buffer) {
                    tokens.push(buffer);
                    buffer = '';
                }
                pending = '';
                tiePending = false;
                return;
            }

            if (isCombiningMark(ch)) {
                if (buffer) {
                    buffer += ch;
                } else {
                    pending += ch;
                }
                if (isTieBar(ch) && buffer) {
                    tiePending = true;
                }
                return;
            }

            if (isPostModifier(ch)) {
                if (buffer) {
                    buffer += ch;
                    return;
                }
                if (pending) {
                    buffer = pending + ch;
                    pending = '';
                    tiePending = false;
                    return;
                }
                buffer = ch;
                tiePending = false;
                return;
            }

            if (!buffer) {
                buffer = pending + ch;
                pending = '';
                tiePending = false;
                return;
            }

            if (tiePending) {
                buffer += ch;
                tiePending = false;
                return;
            }

            tokens.push(buffer);
            buffer = pending + ch;
            pending = '';
            tiePending = false;
        });

        if (buffer) {
            tokens.push(buffer);
        }

        return tokens;
    }

    function isSpecialIpaToken(token) {
        if (!token) { return false; }
        if (isIpaSeparator(token)) { return false; }
        if (ipaCombiningMark.test(token)) { return true; }
        if (/[^a-z]/u.test(token)) { return true; }
        return false;
    }

    function symbolUsesCompactModifier(symbol) {
        if (secondaryTextMode !== 'ipa') { return false; }
        const text = (symbol || '').toString().trim();
        if (!text) { return false; }
        if (/[\u035C\u0361]/u.test(text)) { return false; }
        return secondaryTextCompactModifierChars.some(function (modifier) {
            return modifier && text !== modifier && text.indexOf(modifier) !== -1;
        });
    }

    function compactIpaKeyboardChars(chars) {
        if (!Array.isArray(chars) || !chars.length) { return []; }
        const compact = [];
        const seen = new Set();
        chars.forEach(function (ch) {
            const text = (ch || '').toString().trim();
            if (!text || seen.has(text) || symbolUsesCompactModifier(text)) { return; }
            seen.add(text);
            compact.push(text);
        });
        return compact;
    }

    function normalizeSecondaryTextKeyboardGroups(groups, skipSet) {
        const list = [];
        (Array.isArray(groups) ? groups : []).forEach(function (group) {
            const seen = new Set();
            const symbols = [];
            (Array.isArray(group && group.symbols) ? group.symbols : []).forEach(function (ch) {
                const text = (ch || '').toString().trim();
                if (!text || seen.has(text) || (skipSet && skipSet.has(text))) { return; }
                seen.add(text);
                symbols.push(text);
            });
            if (!symbols.length) { return; }
            list.push({
                key: (group && group.key ? String(group.key) : '').trim(),
                label: (group && group.label ? String(group.label) : '').trim(),
                symbols: symbols
            });
        });
        return list;
    }

    function getSecondaryTextSymbolDetail(symbol) {
        const text = (symbol == null ? '' : String(symbol)).trim();
        const detail = text && Object.prototype.hasOwnProperty.call(secondaryTextSymbolDetails, text) && secondaryTextSymbolDetails[text] && typeof secondaryTextSymbolDetails[text] === 'object'
            ? secondaryTextSymbolDetails[text]
            : {};
        return {
            display: (detail.display == null || String(detail.display) === '') ? text : String(detail.display),
            label: (detail.label == null || String(detail.label) === '') ? text : String(detail.label)
        };
    }

    function mergeIpaSpecialChars(newChars) {
        if (!Array.isArray(newChars) || !newChars.length) { return false; }
        let updated = false;
        compactIpaKeyboardChars(newChars).forEach(function (ch) {
            if (ipaSpecialChars.indexOf(ch) === -1) {
                ipaSpecialChars.push(ch);
                updated = true;
            }
        });
        return updated;
    }

    function ensureWaveformContext() {
        if (waveformContext) { return waveformContext; }
        const Ctor = window.AudioContext || window.webkitAudioContext;
        if (!Ctor) { return null; }
        try {
            waveformContext = new Ctor();
        } catch (_) {
            waveformContext = null;
        }
        return waveformContext;
    }

    function fetchAudioArrayBuffer(url) {
        if (window.fetch) {
            return fetch(url, { credentials: 'same-origin' }).then(function (resp) {
                if (!resp.ok) {
                    throw new Error('Waveform fetch failed');
                }
                return resp.arrayBuffer();
            });
        }
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.responseType = 'arraybuffer';
            xhr.onload = function () {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.response);
                } else {
                    reject(new Error('Waveform fetch failed'));
                }
            };
            xhr.onerror = function () {
                reject(new Error('Waveform fetch failed'));
            };
            xhr.send();
        });
    }

    function decodeAudioBuffer(ctx, buffer) {
        return new Promise(function (resolve, reject) {
            if (!ctx) {
                reject(new Error('Missing AudioContext'));
                return;
            }
            const bufferCopy = buffer.slice(0);
            const done = function (decoded) { resolve(decoded); };
            const fail = function (err) { reject(err || new Error('Decode failed')); };
            const result = ctx.decodeAudioData(bufferCopy, done, fail);
            if (result && typeof result.then === 'function') {
                result.then(resolve).catch(fail);
            }
        });
    }

    function loadWaveformBuffer(url) {
        if (!url) { return Promise.reject(new Error('Missing URL')); }
        if (waveformCache.has(url)) {
            return Promise.resolve(waveformCache.get(url));
        }
        if (waveformPending.has(url)) {
            return waveformPending.get(url);
        }
        const ctx = ensureWaveformContext();
        if (!ctx) { return Promise.reject(new Error('No AudioContext')); }

        const promise = fetchAudioArrayBuffer(url)
            .then(function (buffer) { return decodeAudioBuffer(ctx, buffer); })
            .then(function (decoded) {
                waveformCache.set(url, decoded);
                waveformPending.delete(url);
                return decoded;
            })
            .catch(function (err) {
                waveformPending.delete(url);
                throw err;
            });

        waveformPending.set(url, promise);
        return promise;
    }

    function drawWaveform(canvas, container, audioBuffer) {
        if (!canvas || !container || !audioBuffer) { return; }
        const rect = container.getBoundingClientRect();
        const width = Math.floor(rect.width);
        const height = Math.floor(rect.height);
        if (!width || !height) { return; }

        const dpr = window.devicePixelRatio || 1;
        canvas.width = width * dpr;
        canvas.height = height * dpr;
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        const ctx = canvas.getContext('2d');
        if (!ctx) { return; }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const channelData = audioBuffer.getChannelData(0);
        if (!channelData || !channelData.length) { return; }

        const samplesPerPixel = Math.max(1, Math.floor(channelData.length / width));
        const centerY = height / 2;

        ctx.fillStyle = '#2ecc71';

        for (let x = 0; x < width; x++) {
            const start = x * samplesPerPixel;
            const end = Math.min(start + samplesPerPixel, channelData.length);
            let min = 1;
            let max = -1;

            for (let i = start; i < end; i += 1) {
                const sample = channelData[i];
                if (sample < min) { min = sample; }
                if (sample > max) { max = sample; }
            }

            const yTop = centerY - (max * centerY);
            const yBottom = centerY - (min * centerY);
            const height = yBottom - yTop;
            ctx.fillRect(x, yTop, 1, height);
        }
    }

    function renderIpaWaveform($recording) {
        if (!$recording || !$recording.length) { return; }
        const container = $recording.find('[data-ll-ipa-waveform]').get(0);
        const canvas = container ? container.querySelector('.ll-word-edit-ipa-waveform-canvas') : null;
        if (!container || !canvas) { return; }
        const audio = $recording.find('.ll-word-edit-ipa-audio-player').get(0);
        const url = audio ? (audio.currentSrc || audio.src || '') : '';
        if (!url) { return; }

        loadWaveformBuffer(url).then(function (buffer) {
            if (!document.body.contains(container)) { return; }
            drawWaveform(canvas, container, buffer);
        }).catch(function () {});
    }

    function languageUsesTurkishCasing(code) {
        return ['tr', 'tur', 'zza', 'diq', 'kiu'].indexOf(String(code || '').trim().toLowerCase()) !== -1;
    }

    function lowercaseIpaWordText(value) {
        let text = String(value || '');
        if (!text) { return ''; }
        if (languageUsesTurkishCasing(ipaTextLanguageCode)) {
            text = text.replace(/I/g, '\u0131').replace(/\u0130/g, 'i');
            try {
                return text.toLocaleLowerCase('tr');
            } catch (_) {
                return text.toLowerCase();
            }
        }
        try {
            return text.toLocaleLowerCase();
        } catch (_) {
            return text.toLowerCase();
        }
    }

    function normalizeTextSegment(segment) {
        if (!segment) { return ''; }
        let text = lowercaseIpaWordText(segment);
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        text = text.replace(/\u0131/g, 'i');
        text = applyTranscriptionMatchMap(text);
        text = text.replace(/[^a-z]/g, '');
        return text;
    }

    function normalizeIpaSegment(segment) {
        if (!segment) { return ''; }
        let text = segment.toString().toLocaleLowerCase().replace(/[\s\.]+/g, '');
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        if (secondaryTextMode !== 'ipa') {
            text = applyTranscriptionMatchMap(text);
            return text.replace(/[^a-z]/g, '');
        }
        let out = '';
        for (const ch of text) {
            if (ch >= 'a' && ch <= 'z') {
                out += ch;
                continue;
            }
            if (ipaMatchMap[ch]) {
                out += ipaMatchMap[ch];
            }
        }
        return out;
    }

    function normalizeIpaSegmentWithLength(segment) {
        if (!segment) { return ''; }
        let text = segment.toString().toLocaleLowerCase().replace(/[\s\.]+/g, '');
        if (text.normalize) {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        if (secondaryTextMode !== 'ipa') {
            text = applyTranscriptionMatchMap(text);
            return text.replace(/[^a-z]/g, '');
        }
        let out = '';
        let last = '';
        for (const ch of text) {
            if (ch === '\u02d0' || ch === '\u02d1') {
                if (last) {
                    out += last;
                }
                continue;
            }
            if (ch >= 'a' && ch <= 'z') {
                out += ch;
                last = ch;
                continue;
            }
            if (ipaMatchMap[ch]) {
                const mapped = ipaMatchMap[ch];
                out += mapped;
                last = mapped;
            }
        }
        return out;
    }

    function levenshteinDistance(a, b) {
        if (a === b) { return 0; }
        const alen = a.length;
        const blen = b.length;
        if (!alen) { return blen; }
        if (!blen) { return alen; }
        const row = new Array(blen + 1);
        for (let j = 0; j <= blen; j += 1) {
            row[j] = j;
        }
        for (let i = 1; i <= alen; i += 1) {
            let prev = i - 1;
            row[0] = i;
            for (let j = 1; j <= blen; j += 1) {
                const temp = row[j];
                const cost = a[i - 1] === b[j - 1] ? 0 : 1;
                row[j] = Math.min(
                    row[j] + 1,
                    row[j - 1] + 1,
                    prev + cost
                );
                prev = temp;
            }
        }
        return row[blen];
    }

    function similarityScore(textSegment, ipaSegment) {
        const textNorm = normalizeTextSegment(textSegment);
        if (!textNorm) { return 0; }
        const ipaNorm = normalizeIpaSegment(ipaSegment);
        const ipaExpanded = normalizeIpaSegmentWithLength(ipaSegment);
        if (!ipaNorm && !ipaExpanded) { return 0; }

        const scoreFor = function (norm) {
            if (!norm) { return 0; }
            if (textNorm === norm) { return 1; }
            const distance = levenshteinDistance(textNorm, norm);
            const maxLen = Math.max(textNorm.length, norm.length);
            if (!maxLen) { return 0; }
            const score = 1 - (distance / maxLen);
            return Math.max(0, Math.min(1, score));
        };

        let best = scoreFor(ipaNorm);
        if (ipaExpanded && ipaExpanded !== ipaNorm) {
            best = Math.max(best, scoreFor(ipaExpanded));
        }
        return best;
    }

    function alignTextToIpa(letters, tokens) {
        if (!letters.length || !tokens.length) { return null; }
        const matchThreshold = 0.55;
        const skipPenalty = 0.25;
        const multiPenalty = 0.05;
        const n = letters.length;
        const m = tokens.length;
        const tokenNorms = tokens.map(function (token) { return normalizeIpaSegment(token); });
        const comboNorms = [];
        for (let idx = 0; idx < (m - 1); idx += 1) {
            comboNorms[idx] = normalizeIpaSegment(tokens[idx] + tokens[idx + 1]);
        }
        const dp = Array.from({ length: n + 1 }, () => Array(m + 1).fill(null));
        dp[0][0] = { score: 0, prev: null };

        const update = function (i, j, score, prev) {
            const cell = dp[i][j];
            if (!cell || score > cell.score) {
                dp[i][j] = { score: score, prev: prev };
            }
        };

        for (let i = 0; i <= n; i += 1) {
            for (let j = 0; j <= m; j += 1) {
                const cell = dp[i][j];
                if (!cell) { continue; }

                if (i < n) {
                    update(i + 1, j, cell.score - skipPenalty, { type: 'skip-text', i: i, j: j });
                }
                if (j < m) {
                    update(i, j + 1, cell.score - skipPenalty, { type: 'skip-token', i: i, j: j });
                }

                if (i < n && j < m) {
                    const score = similarityScore(letters[i], tokens[j]);
                    if (score >= matchThreshold) {
                        update(i + 1, j + 1, cell.score + score, {
                            type: 'match',
                            i: i,
                            j: j,
                            text: letters[i],
                            ipa: tokens[j],
                            textLen: 1,
                            tokenLen: 1,
                            score: score
                        });
                    }
                }
                if (i < n && (j + 1) < m) {
                    const comboNorm = comboNorms[j] || '';
                    if (comboNorm) {
                        const normA = tokenNorms[j] || '';
                        const normB = tokenNorms[j + 1] || '';
                        if (comboNorm !== normA && comboNorm !== normB) {
                            const ipaSegment = tokens[j] + tokens[j + 1];
                            const score = similarityScore(letters[i], ipaSegment);
                            if (score >= matchThreshold) {
                                update(i + 1, j + 2, cell.score + score - multiPenalty, {
                                    type: 'match',
                                    i: i,
                                    j: j,
                                    text: letters[i],
                                    ipa: ipaSegment,
                                    textLen: 1,
                                    tokenLen: 2,
                                    score: score
                                });
                            }
                        }
                    }
                }
                if ((i + 1) < n && j < m) {
                    const textSegment = letters[i] + letters[i + 1];
                    const score = similarityScore(textSegment, tokens[j]);
                    if (score >= matchThreshold) {
                        update(i + 2, j + 1, cell.score + score - multiPenalty, {
                            type: 'match',
                            i: i,
                            j: j,
                            text: textSegment,
                            ipa: tokens[j],
                            textLen: 2,
                            tokenLen: 1,
                            score: score
                        });
                    }
                }
            }
        }

        const endCell = dp[n][m];
        if (!endCell) { return null; }

        let matches = [];
        let matchedLetters = 0;
        let matchedTokens = 0;
        let totalScore = 0;
        let i = n;
        let j = m;

        while (i > 0 || j > 0) {
            const cell = dp[i][j];
            if (!cell || !cell.prev) { break; }
            const prev = cell.prev;
            if (prev.type === 'match') {
                matches.push({
                    textIndex: prev.i,
                    tokenIndex: prev.j,
                    textLength: prev.textLen,
                    tokenLength: prev.tokenLen,
                    score: prev.score
                });
                matchedLetters += prev.textLen;
                matchedTokens += prev.tokenLen;
                totalScore += prev.score;
            }
            i = prev.i || 0;
            j = prev.j || 0;
        }

        if (!matches.length) { return null; }
        matches = matches.reverse();
        const avgScore = totalScore / matches.length;
        const letterCoverage = matchedLetters / n;
        const tokenCoverage = matchedTokens / m;
        if (avgScore < 0.55 || letterCoverage < 0.55 || tokenCoverage < 0.45) {
            return null;
        }

        return matches;
    }

    function getTextLetters(value) {
        const raw = (value || '').toString();
        if (!raw) { return []; }
        const letters = [];
        for (const ch of raw) {
            if (/\p{L}/u.test(ch)) {
                letters.push(lowercaseIpaWordText(ch));
            }
        }
        return letters;
    }

    function getLetterIndexForCursor(letters, tokens, cursorTokenCount, matches, cursorAtBoundary) {
        if (!letters.length) { return -1; }
        if (!tokens.length || cursorTokenCount <= 0) {
            return 0;
        }
        if (!matches || !matches.length) {
            let index = cursorTokenCount;
            if (!cursorAtBoundary && index > 0) {
                index -= 1;
            }
            return Math.min(letters.length - 1, index);
        }
        for (let i = 0; i < matches.length; i += 1) {
            const match = matches[i];
            const tokenStart = match.tokenIndex;
            const tokenEnd = match.tokenIndex + match.tokenLength;
            if (cursorTokenCount <= tokenStart) {
                return match.textIndex;
            }
            if (cursorTokenCount < tokenEnd) {
                return match.textIndex;
            }
            if (cursorTokenCount === tokenEnd) {
                if (cursorAtBoundary) {
                    const nextIndex = match.textIndex + match.textLength;
                    if (nextIndex < letters.length) {
                        return nextIndex;
                    }
                }
                return match.textIndex;
            }
        }
        const last = matches[matches.length - 1];
        const nextIndex = last.textIndex + last.textLength;
        if (nextIndex < letters.length) {
            return nextIndex;
        }
        return letters.length - 1;
    }

    function getIpaShiftOffset($recording) {
        if (!$recording || !$recording.length) { return 0; }
        const raw = $recording.attr('data-ll-ipa-shift');
        const parsed = parseInt(raw, 10);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function setIpaShiftOffset($recording, offset) {
        if (!$recording || !$recording.length) { return; }
        const parsed = parseInt(offset, 10);
        $recording.attr('data-ll-ipa-shift', Number.isFinite(parsed) ? parsed : 0);
    }

    function clampIpaShift(baseIndex, offset, totalLetters) {
        if (!totalLetters || baseIndex < 0) {
            return { offset: 0, minOffset: 0, maxOffset: 0 };
        }
        const minOffset = -baseIndex;
        const maxOffset = (totalLetters - 1) - baseIndex;
        let next = Number.isFinite(offset) ? offset : 0;
        if (next < minOffset) { next = minOffset; }
        if (next > maxOffset) { next = maxOffset; }
        return { offset: next, minOffset: minOffset, maxOffset: maxOffset };
    }

    function getIpaSuggestionsForLetter(letters, letterIndex) {
        if (!ipaLetterMap || !Object.keys(ipaLetterMap).length) { return []; }
        if (!letters.length || letterIndex < 0 || letterIndex >= letters.length) { return []; }

        const suggestions = [];
        const seen = new Set();
        const digraph = letters[letterIndex] + (letters[letterIndex + 1] || '');
        const candidates = [];
        if (digraph && ipaLetterMap[digraph]) {
            candidates.push(digraph);
        }
        const letterKey = letters[letterIndex];
        if (letterKey && ipaLetterMap[letterKey]) {
            candidates.push(letterKey);
        }
        candidates.forEach(function (key) {
            const entries = ipaLetterMap[key] || [];
            entries.forEach(function (entry) {
                if (!entry || seen.has(entry)) { return; }
                seen.add(entry);
                suggestions.push(entry);
            });
        });

        return suggestions;
    }

    function getIpaSuggestionState($input) {
        const state = {
            suggestions: [],
            letters: [],
            letterIndex: -1,
            baseIndex: -1,
            offset: 0,
            minOffset: 0,
            maxOffset: 0,
            highlightLength: 1,
            textValue: ''
        };
        if (!$input || !$input.length) { return state; }
        const $recording = $input.closest('.ll-word-edit-recording');
        if (!$recording.length) { return state; }
        const textValue = ($recording.find('[data-ll-recording-input="text"]').val() || '').toString();
        state.textValue = textValue;
        const letters = getTextLetters(textValue);
        state.letters = letters;
        if (!letters.length) { return state; }
        const input = $input.get(0);
        if (!input) { return state; }
        const value = (input.value || '').toString();
        const tokens = filterIpaTokensForMapping(tokenizeIpa(value));
        const cursor = (typeof input.selectionStart === 'number') ? input.selectionStart : value.length;
        const beforeTokens = filterIpaTokensForMapping(tokenizeIpa(value.slice(0, cursor)));
        const tokensBefore = beforeTokens.length;
        const prefixTokens = tokens.slice(0, tokensBefore);
        const cursorAtEnd = cursor >= value.length;
        const cursorAtBoundary = cursorAtEnd || beforeTokens.join('') === prefixTokens.join('');
        const matches = alignTextToIpa(letters, tokens);
        const lastEditType = getLastIpaEditType(input);
        const advanceAtBoundary = lastEditType !== 'delete';
        const baseIndex = getLetterIndexForCursor(
            letters,
            tokens,
            tokensBefore,
            matches,
            cursorAtBoundary && advanceAtBoundary
        );
        state.baseIndex = baseIndex;
        if (baseIndex < 0 || baseIndex >= letters.length) { return state; }

        const shift = clampIpaShift(baseIndex, getIpaShiftOffset($recording), letters.length);
        state.offset = shift.offset;
        state.minOffset = shift.minOffset;
        state.maxOffset = shift.maxOffset;
        if (shift.offset !== getIpaShiftOffset($recording)) {
            setIpaShiftOffset($recording, shift.offset);
        }
        const letterIndex = baseIndex + shift.offset;
        state.letterIndex = letterIndex;
        state.suggestions = getIpaSuggestionsForLetter(letters, letterIndex);
        const digraph = letters[letterIndex] + (letters[letterIndex + 1] || '');
        if (digraph && ipaLetterMap[digraph]) {
            state.highlightLength = 2;
        }
        return state;
    }

    function getIpaSuggestionsForInput($input) {
        return getIpaSuggestionState($input).suggestions;
    }

    function renderIpaTargetText($target, textValue, highlightIndex, highlightLength) {
        if (!$target || !$target.length) { return; }
        const el = $target.get(0);
        if (!el) { return; }
        while (el.firstChild) {
            el.removeChild(el.firstChild);
        }
        const text = (textValue || '').toString();
        if (!text) { return; }
        const spanLength = Math.max(1, parseInt(highlightLength || 1, 10));
        const highlightStart = typeof highlightIndex === 'number' ? highlightIndex : -1;
        const highlightEnd = highlightStart + spanLength - 1;
        const fragment = document.createDocumentFragment();
        let letterIndex = -1;
        for (const ch of text) {
            const span = document.createElement('span');
            span.textContent = ch;
            if (/\p{L}/u.test(ch)) {
                letterIndex += 1;
                if (letterIndex >= highlightStart && letterIndex <= highlightEnd) {
                    span.className = 'll-word-edit-ipa-target-letter';
                }
            }
            fragment.appendChild(span);
        }
        el.appendChild(fragment);
    }

    function updateIpaTargetRow($recording, state) {
        if (!$recording || !$recording.length) { return; }
        const $target = $recording.find('[data-ll-ipa-target]').first();
        if (!$target.length) { return; }
        const $text = $recording.find('[data-ll-ipa-target-text]').first();
        const $prev = $recording.find('[data-ll-ipa-shift="prev"]').first();
        const $next = $recording.find('[data-ll-ipa-shift="next"]').first();
        const letters = state && Array.isArray(state.letters) ? state.letters : [];
        if (!letters.length || !state || state.baseIndex < 0) {
            $target.attr('aria-hidden', 'true');
            if ($text.length) { $text.empty(); }
            if ($prev.length) { $prev.prop('disabled', true); }
            if ($next.length) { $next.prop('disabled', true); }
            return;
        }
        $target.attr('aria-hidden', 'false');
        renderIpaTargetText($text, state.textValue, state.letterIndex, state.highlightLength);
        const minOffset = Number.isFinite(state.minOffset) ? state.minOffset : 0;
        const maxOffset = Number.isFinite(state.maxOffset) ? state.maxOffset : 0;
        const offset = Number.isFinite(state.offset) ? state.offset : 0;
        if ($prev.length) { $prev.prop('disabled', offset <= minOffset); }
        if ($next.length) { $next.prop('disabled', offset >= maxOffset); }
    }

    function renderIpaSuggestionRow($keyboard, suggestions) {
        if (!$keyboard || !$keyboard.length) { return 0; }
        $keyboard.empty();

        if (!Array.isArray(suggestions) || !suggestions.length) {
            return 0;
        }

        const merged = [];
        const seen = new Set();
        suggestions.forEach(function (ch) {
            if (!ch || seen.has(ch)) { return; }
            seen.add(ch);
            merged.push(ch);
        });

        if (!merged.length) {
            return 0;
        }

        sortSecondaryTextSymbols(merged).forEach(function (ch) {
            $('<button>', {
                type: 'button',
                class: 'll-word-ipa-key',
                text: ch,
                'data-ipa-char': ch,
                'aria-label': ch
            }).appendTo($keyboard);
        });

        return merged.length;
    }

    function appendIpaKeyboardRow($keyboard, chars, label, extraClass) {
        const rowChars = Array.isArray(chars) ? chars : [];
        if (!rowChars.length) { return 0; }
        const $group = $('<div>', {
            class: 'll-word-edit-ipa-keyboard-group' + (extraClass ? ' ' + extraClass.replace(/row/g, 'group') : ''),
            'aria-label': label || ''
        });
        if (label) {
            $('<div>', {
                class: 'll-word-edit-ipa-keyboard-label',
                text: label
            }).appendTo($group);
        }
        const $row = $('<div>', {
            class: 'll-word-edit-ipa-keyboard-row' + (extraClass ? ' ' + extraClass : ''),
            'aria-label': label || ''
        });
        rowChars.forEach(function (ch) {
            const detail = getSecondaryTextSymbolDetail(ch);
            const display = detail.display || ch;
            const title = detail.label || ch;
            $('<button>', {
                type: 'button',
                class: 'll-word-ipa-key',
                text: display,
                'data-ipa-char': ch,
                'aria-label': title && title !== display ? (display + ': ' + title) : display,
                title: title
            }).appendTo($row);
        });
        $row.appendTo($group);
        $group.appendTo($keyboard);
        return rowChars.length;
    }

    function renderIpaKeyboard($keyboard, chars, skipChars) {
        if (!$keyboard || !$keyboard.length) { return 0; }
        $keyboard.empty();

        const skipSet = new Set(Array.isArray(skipChars) ? skipChars : []);
        const configuredGroups = normalizeSecondaryTextKeyboardGroups(secondaryTextKeyboardGroups, skipSet);
        if (configuredGroups.length) {
            let configuredTotal = 0;
            configuredGroups.forEach(function (group) {
                configuredTotal += appendIpaKeyboardRow(
                    $keyboard,
                    group.symbols,
                    group.label,
                    group.key ? ('ll-word-edit-ipa-keyboard-row--' + group.key) : ''
                );
            });
            if (configuredTotal) {
                return configuredTotal;
            }
        }

        const common = [];
        const wordset = [];
        const seen = new Set();
        const modifierSet = new Set(secondaryTextModifierChars);
        const pushUnique = function (target, ch) {
            const text = (ch || '').toString().trim();
            if (!text || seen.has(text) || skipSet.has(text) || modifierSet.has(text) || symbolUsesCompactModifier(text)) { return; }
            seen.add(text);
            target.push(text);
        };

        secondaryTextCommonChars.forEach(function (ch) {
            pushUnique(common, ch);
        });
        if (Array.isArray(chars)) {
            chars.forEach(function (ch) {
                pushUnique(wordset, ch);
            });
        }

        const modifierChars = secondaryTextModifierChars.filter(function (ch) {
            return !!ch && !skipSet.has(ch);
        });
        const sortedCommon = sortSecondaryTextSymbols(common);
        const sortedWordset = sortSecondaryTextSymbols(wordset);
        const total = modifierChars.length + sortedCommon.length + sortedWordset.length;
        if (!total) {
            return 0;
        }

        appendIpaKeyboardRow($keyboard, modifierChars, editMessages.secondaryTextModifiers || editMessages.ipaModifiers || 'Diacritics and signs', 'll-word-edit-ipa-keyboard-row--modifiers');
        appendIpaKeyboardRow($keyboard, sortedCommon, editMessages.secondaryTextCommon || editMessages.ipaCommon || 'Common', 'll-word-edit-ipa-keyboard-row--common');
        appendIpaKeyboardRow($keyboard, sortedWordset, editMessages.secondaryTextWordset || editMessages.ipaWordset || 'Wordset', 'll-word-edit-ipa-keyboard-row--wordset');

        return total;
    }

    function hideIpaAudio() {
        $('[data-ll-ipa-audio]').attr('aria-hidden', 'true').each(function () {
            const audio = $(this).find('audio').get(0);
            if (audio && !audio.paused) {
                audio.pause();
            }
        });
        $('[data-ll-ipa-waveform]').attr('aria-hidden', 'true');
    }

    function centerIpaInputInEditBody(input) {
        if (!input || !document.body.contains(input) || typeof input.closest !== 'function') { return; }
        const container = input.closest('[data-ll-word-edit-body]');
        if (!container) {
            if (typeof input.scrollIntoView === 'function') {
                input.scrollIntoView({ block: 'center', inline: 'nearest' });
            }
            return;
        }
        const maxScrollTop = Math.max(0, container.scrollHeight - container.clientHeight);
        if (maxScrollTop <= 0) { return; }

        const containerRect = container.getBoundingClientRect();
        const inputRect = input.getBoundingClientRect();
        const containerContentTop = containerRect.top + container.clientTop;
        const relativeTop = (inputRect.top - containerContentTop) + container.scrollTop;
        const desiredScrollTop = relativeTop - ((container.clientHeight - inputRect.height) / 2);
        const nextScrollTop = Math.max(0, Math.min(maxScrollTop, desiredScrollTop));

        if (Math.abs(nextScrollTop - container.scrollTop) <= 1) { return; }
        container.scrollTop = nextScrollTop;
    }

    function queueIpaInputCentering(input) {
        if (!input) { return; }
        window.requestAnimationFrame(function () {
            centerIpaInputInEditBody(input);
        });
    }

    function showIpaKeyboard($input, options) {
        const settings = (options && typeof options === 'object') ? options : {};
        if (!$input || !$input.length) { return; }
        const $recording = $input.closest('.ll-word-edit-recording');
        const $keyboard = $recording.find('[data-ll-ipa-keyboard]').first();
        const $suggestions = $recording.find('[data-ll-ipa-suggestions]').first();
        if (!$keyboard.length) { return; }
        const inputEl = $input.get(0);
        $('[data-ll-ipa-keyboard]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-suggestions]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-target]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-superscript]').attr('aria-hidden', 'true');
        const $audio = $recording.find('[data-ll-ipa-audio]').first();
        $('[data-ll-ipa-audio]').not($audio).attr('aria-hidden', 'true').each(function () {
            const audio = $(this).find('audio').get(0);
            if (audio && !audio.paused) {
                audio.pause();
            }
        });
        const state = getIpaSuggestionState($input);
        const suggestionCount = renderIpaSuggestionRow($suggestions, state.suggestions);
        const keyCount = renderIpaKeyboard($keyboard, ipaSpecialChars, state.suggestions);
        updateIpaTargetRow($recording, state);
        if (keyCount > 0) {
            $keyboard.attr('aria-hidden', 'false');
        } else {
            $keyboard.attr('aria-hidden', 'true');
        }
        if ($suggestions.length) {
            $suggestions.attr('aria-hidden', suggestionCount > 0 ? 'false' : 'true');
        }
        if (keyCount > 0 || suggestionCount > 0) {
            activeIpaInput = $input.get(0);
            if (secondaryTextSupportsSuperscript) {
                $recording.find('[data-ll-ipa-superscript]').attr('aria-hidden', 'false');
            }
        } else {
            activeIpaInput = null;
        }
        if ($audio.length) {
            $audio.attr('aria-hidden', 'false');
            $recording.find('[data-ll-ipa-waveform]').attr('aria-hidden', 'false');
            requestAnimationFrame(function () {
                renderIpaWaveform($recording);
            });
        }
        if (settings.centerInput && inputEl) {
            queueIpaInputCentering(inputEl);
        }
    }

    function hideIpaKeyboards() {
        $('[data-ll-ipa-keyboard]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-suggestions]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-target]').attr('aria-hidden', 'true');
        $('[data-ll-ipa-superscript]').attr('aria-hidden', 'true');
        hideIpaAudio();
        activeIpaInput = null;
    }

    function refreshIpaKeyboardForInput(input) {
        if (!input) { return; }
        const $input = $(input);
        const $recording = $input.closest('.ll-word-edit-recording');
        if (!$recording.length) { return; }
        const $keyboard = $recording.find('[data-ll-ipa-keyboard]').first();
        const $suggestions = $recording.find('[data-ll-ipa-suggestions]').first();
        if (!$keyboard.length || $keyboard.attr('aria-hidden') === 'true') { return; }
        const state = getIpaSuggestionState($input);
        const suggestionCount = renderIpaSuggestionRow($suggestions, state.suggestions);
        renderIpaKeyboard($keyboard, ipaSpecialChars, state.suggestions);
        if ($suggestions.length) {
            $suggestions.attr('aria-hidden', suggestionCount > 0 ? 'false' : 'true');
        }
        updateIpaTargetRow($recording, state);
    }

    function insertIpaChar(input, ch) {
        if (!input || !ch) { return; }
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        const value = input.value || '';
        const nextValue = value.slice(0, start) + ch + value.slice(end);
        input.value = nextValue;
        const cursor = start + ch.length;
        if (input.setSelectionRange) {
            input.setSelectionRange(cursor, cursor);
        }
        setLastIpaEdit(input, 'insert');
        $(input).trigger('input');
    }

    function applyRecordingCaptions($item, recordings) {
        if (!Array.isArray(recordings)) { return; }
        const $wrap = $item.find('.ll-word-recordings').first();
        if (!$wrap.length) { return; }
        const captionMap = {};
        let hasCaption = false;

        recordings.forEach(function (rec) {
            const recId = parseInt(rec.id, 10) || 0;
            if (!recId) { return; }
            const caption = getRecordingCaptionParts(rec.recording_text, rec.recording_translation, rec.recording_ipa, rec.review_fields);
            captionMap[recId] = caption;
            if (caption.hasCaption) {
                hasCaption = true;
            }
        });

        const $buttons = $wrap.find('.ll-word-grid-recording-btn');
        const keepRowLayout = $buttons.filter(function () {
            return (($(this).attr('data-ll-recording-edit-label') || '').toString() !== '');
        }).length > 0;

        if (hasCaption || keepRowLayout) {
            $wrap
                .toggleClass('ll-word-recordings--with-text', hasCaption)
                .empty();

            $buttons.each(function () {
                const $btn = $(this);
                const recId = parseInt($btn.attr('data-recording-id'), 10) || 0;
                const caption = recId ? (captionMap[recId] || null) : null;
                const $row = $('<div>', { class: 'll-word-recording-row' });
                const visibilityNote = ($btn.attr('data-ll-recording-visibility-note') || '').toString();
                const visibilityLabel = ($btn.attr('data-ll-recording-visibility-label') || 'Secondary').toString();
                if (recId) {
                    $row.attr('data-recording-id', recId);
                }
                if ($btn.hasClass('ll-word-grid-recording-btn--secondary') || visibilityNote) {
                    $row
                        .addClass('ll-word-recording-row--secondary')
                        .attr('data-ll-recording-secondary', '1');
                }
                $row.append($btn);
                if (visibilityNote) {
                    $('<span>', {
                        class: 'll-word-recording-visibility-badge',
                        title: visibilityNote,
                        text: visibilityLabel
                    }).appendTo($row);
                }
                renderRecordingCaption($row, caption);
                syncRecordingRowEditTrigger($row);
                $wrap.append($row);
            });
        } else if ($wrap.hasClass('ll-word-recordings--with-text')) {
            $wrap.removeClass('ll-word-recordings--with-text').empty();
            $buttons.each(function () {
                $wrap.append(this);
            });
        }
    }

    if (canEdit && ajaxUrl && editNonce) {
        syncEditModalBodyLock();
        $grids.find('.word-item').each(function () {
            cacheOriginalInputs($(this));
        });
        $grids.find('[data-ll-word-input="dictionary_entry_lookup"]').each(function () {
            initDictionaryEntryAutocomplete($(this));
        });
        $grids.find('[data-ll-word-categories-field]').each(function () {
            updateWordCategoryField($(this));
        });

        function getLessonAddWordWrap($button) {
            const $wrap = $button.closest('[data-ll-add-lesson-word-wrap]');
            return $wrap.length ? $wrap : $button.parent();
        }

        function clearLessonAddWordStatusTimer($wrap) {
            const timerId = parseInt($wrap.data('llAddWordStatusTimerId'), 10) || 0;
            if (timerId > 0) {
                window.clearTimeout(timerId);
            }
            $wrap.removeData('llAddWordStatusTimerId');
        }

        function setLessonAddWordStatus($wrap, message, stateName) {
            const $status = $wrap.find('[data-ll-add-lesson-word-status]').first();
            if (!$status.length) { return; }

            clearLessonAddWordStatusTimer($wrap);

            const stateValue = ['pending', 'success', 'error'].indexOf((stateName || '').toString()) >= 0
                ? stateName.toString()
                : '';
            const text = (message || '').toString();

            $status.removeClass('is-pending is-success is-error');
            if (!text) {
                $status.text('').attr('hidden', 'hidden').removeAttr('data-state');
                return;
            }

            $status
                .text(text)
                .removeAttr('hidden')
                .attr('data-state', stateValue)
                .toggleClass('is-pending', stateValue === 'pending')
                .toggleClass('is-success', stateValue === 'success')
                .toggleClass('is-error', stateValue === 'error');
        }

        function scheduleLessonAddWordStatusClear($wrap, delayMs) {
            clearLessonAddWordStatusTimer($wrap);
            const timerId = window.setTimeout(function () {
                setLessonAddWordStatus($wrap, '', '');
            }, Math.max(400, delayMs || 0));
            $wrap.data('llAddWordStatusTimerId', timerId);
        }

        function syncCreatedLessonGridAttributes(sourceGrid, targetGrid) {
            if (!sourceGrid || !targetGrid || !targetGrid.attributes) { return; }
            Array.from(targetGrid.attributes).forEach(function (attr) {
                targetGrid.removeAttribute(attr.name);
            });
            Array.from(sourceGrid.attributes).forEach(function (attr) {
                targetGrid.setAttribute(attr.name, attr.value);
            });
        }

        function insertCreatedLessonWord($grid, html, wordId) {
            const targetGrid = $grid.get(0);
            if (!targetGrid || typeof document === 'undefined') { return $(); }

            const wrapper = document.createElement('div');
            wrapper.innerHTML = (html || '').toString();
            const sourceGrid = wrapper.querySelector('[data-ll-word-grid]');
            if (sourceGrid) {
                syncCreatedLessonGridAttributes(sourceGrid, targetGrid);
            }

            const selector = wordId > 0 ? '.word-item[data-word-id="' + wordId + '"]' : '.word-item[data-word-id]';
            const sourceItem = sourceGrid
                ? sourceGrid.querySelector(selector)
                : wrapper.querySelector(selector);
            if (!sourceItem) { return $(); }

            const $sourceItem = $(sourceItem);
            const itemWordId = parseInt($sourceItem.attr('data-word-id'), 10) || wordId;
            const $existing = itemWordId > 0
                ? $grid.children('.word-item[data-word-id="' + itemWordId + '"]').first()
                : $();

            if (!$grid.children('.word-item[data-word-id]').length) {
                $grid.empty();
            }
            $grid.children('.ll-vocab-lesson-skeleton-card').remove();
            $grid.removeClass('ll-vocab-lesson-grid-empty');

            if ($existing.length) {
                $existing.replaceWith($sourceItem);
            } else {
                $grid.append($sourceItem);
            }

            return $sourceItem;
        }

        $('[data-ll-add-lesson-word]').on('click', function (event) {
            event.preventDefault();

            const $button = $(this);
            if ($button.prop('disabled')) { return; }

            const $wrap = getLessonAddWordWrap($button);
            const $scope = $button.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page');
            const $grid = ($scope.length ? $scope : $(document)).find('[data-ll-word-grid]').first();
            const lessonId = parseInt($button.attr('data-lesson-id'), 10)
                || parseInt($grid.attr('data-ll-lesson-id'), 10)
                || 0;
            const $shell = $grid.closest('[data-ll-vocab-lesson-grid-shell]');

            if (!$grid.length || !lessonId) {
                setLessonAddWordStatus($wrap, editMessages.addWordError, 'error');
                return;
            }
            if ($shell.length && ($shell.hasClass('is-loading') || $shell.data('llGridLoading'))) {
                setLessonAddWordStatus($wrap, editMessages.lessonGridLoading, 'error');
                return;
            }

            $button.prop('disabled', true).attr('aria-busy', 'true');
            setLessonAddWordStatus($wrap, editMessages.addingWord, 'pending');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_tools_word_grid_create_lesson_word',
                    nonce: editNonce,
                    lesson_id: String(lessonId)
                }
            }).done(function (response) {
                if (!response || response.success !== true) {
                    setLessonAddWordStatus($wrap, readResponseErrorMessage(response, editMessages.addWordError), 'error');
                    return;
                }

                const data = (response.data && typeof response.data === 'object') ? response.data : {};
                const wordId = parseInt(data.word_id, 10) || 0;
                const $item = insertCreatedLessonWord($grid, data.html || '', wordId);
                if (!$item.length) {
                    setLessonAddWordStatus($wrap, editMessages.addWordError, 'error');
                    return;
                }

                cacheOriginalInputs($item);
                updateOriginalInputs($item);
                updateGridLayouts();
                $(document).trigger('lltools:word-grid-rendered', [{ scope: $item }]);

                const $bulkWrap = $item.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page').find('[data-ll-word-grid-bulk]').first();
                if ($bulkWrap.length) {
                    clearAllBulkControlUndoSnapshots($bulkWrap);
                    syncBulkControlSelectDefaults($bulkWrap);
                }
                if ($grid.attr('data-ll-word-grid-reorderable') === '1') {
                    scheduleLessonOrderSave($grid, 300);
                }

                setEditPanelOpen($item, true);
                const $wordInput = $item.find('[data-ll-word-input="word"]').first();
                if ($wordInput.length) {
                    $wordInput.trigger('focus').trigger('select');
                }

                setLessonAddWordStatus($wrap, editMessages.wordAdded, 'success');
                scheduleLessonAddWordStatusClear($wrap, 1800);
            }).fail(function (jqXHR) {
                setLessonAddWordStatus($wrap, readAjaxErrorMessage(jqXHR, editMessages.addWordError), 'error');
            }).always(function () {
                $button.prop('disabled', false).removeAttr('aria-busy');
            });
        });

        function getLessonOrderStatusElement($grid) {
            if (!$grid || !$grid.length) { return $(); }
            return $grid.find('[data-ll-word-grid-order-status]').first();
        }

        function clearLessonOrderStatusTimer($grid) {
            if (!$grid || !$grid.length) { return; }
            const timerId = parseInt($grid.data('llLessonOrderStatusTimerId'), 10) || 0;
            if (timerId > 0) {
                window.clearTimeout(timerId);
            }
            $grid.removeData('llLessonOrderStatusTimerId');
        }

        function setLessonOrderStatus($grid, stateName, message) {
            const $status = getLessonOrderStatusElement($grid);
            if (!$status.length) { return; }

            clearLessonOrderStatusTimer($grid);

            const stateValue = ['saving', 'saved', 'error'].indexOf((stateName || '').toString()) >= 0
                ? stateName.toString()
                : '';
            const text = (message || '').toString();

            $status.removeClass('is-saving is-saved is-error');
            if (!stateValue || !text) {
                $status.text('').attr('hidden', 'hidden').removeAttr('data-state');
                return;
            }

            $status
                .text(text)
                .removeAttr('hidden')
                .attr('data-state', stateValue)
                .addClass('is-' + stateValue);
        }

        function scheduleLessonOrderStatusClear($grid, delayMs) {
            if (!$grid || !$grid.length) { return; }
            clearLessonOrderStatusTimer($grid);
            const timerId = window.setTimeout(function () {
                setLessonOrderStatus($grid, '', '');
            }, Math.max(400, delayMs || 0));
            $grid.data('llLessonOrderStatusTimerId', timerId);
        }

        function collectLessonOrderWordIds($grid) {
            if (!$grid || !$grid.length) { return []; }
            return normalizeIds($grid.children('.word-item[data-word-id]').map(function () {
                return parseInt($(this).attr('data-word-id'), 10) || 0;
            }).get());
        }

        function getLessonOrderState($grid) {
            let lessonState = $grid.data('llLessonOrderState');
            if (!lessonState || typeof lessonState !== 'object') {
                lessonState = {
                    saving: false,
                    queued: false,
                    pendingIds: [],
                    lastSavedKey: '',
                    timerId: 0
                };
                $grid.data('llLessonOrderState', lessonState);
            }
            return lessonState;
        }

        function flushLessonOrderSave($grid) {
            if (!$grid || !$grid.length) { return; }

            const lessonId = parseInt($grid.attr('data-ll-lesson-id'), 10) || 0;
            if (!lessonId) { return; }

            const lessonState = getLessonOrderState($grid);
            const orderIds = Array.isArray(lessonState.pendingIds) && lessonState.pendingIds.length
                ? lessonState.pendingIds.slice()
                : collectLessonOrderWordIds($grid);
            const orderKey = orderIds.join(',');

            if (!orderKey || orderKey === lessonState.lastSavedKey) {
                lessonState.pendingIds = [];
                lessonState.queued = false;
                setLessonOrderStatus($grid, '', '');
                return;
            }

            if (lessonState.saving) {
                lessonState.queued = true;
                return;
            }

            lessonState.pendingIds = orderIds.slice();
            lessonState.saving = true;
            $grid.attr('aria-busy', 'true');
            setLessonOrderStatus($grid, 'saving', orderMessages.saving);

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_tools_word_grid_save_lesson_order',
                    nonce: editNonce,
                    lesson_id: String(lessonId),
                    order: orderIds
                }
            }).done(function (response) {
                if (!response || response.success !== true) {
                    setLessonOrderStatus(
                        $grid,
                        'error',
                        readResponseErrorMessage(response, orderMessages.error)
                    );
                    return;
                }

                const data = (response.data && typeof response.data === 'object') ? response.data : {};
                const savedIds = Array.isArray(data.order) ? normalizeIds(data.order) : orderIds;
                lessonState.lastSavedKey = savedIds.join(',') || orderKey;
                lessonState.pendingIds = [];
                if (savedIds.length) {
                    const savedLookup = {};
                    savedIds.forEach(function (wordId) {
                        savedLookup[wordId] = true;
                    });
                    const currentIds = collectLessonOrderWordIds($grid);
                    if (currentIds.length && currentIds.every(function (wordId) { return !!savedLookup[wordId]; })) {
                        lessonState.lastSavedKey = currentIds.join(',') || lessonState.lastSavedKey;
                    }
                }
                setLessonOrderStatus($grid, 'saved', orderMessages.saved);
                scheduleLessonOrderStatusClear($grid, 1200);
            }).fail(function (jqXHR) {
                setLessonOrderStatus($grid, 'error', readAjaxErrorMessage(jqXHR, orderMessages.error));
            }).always(function () {
                lessonState.saving = false;
                $grid.removeAttr('aria-busy');
                if (lessonState.queued) {
                    lessonState.queued = false;
                    flushLessonOrderSave($grid);
                }
            });
        }

        function scheduleLessonOrderSave($grid, delayMs) {
            if (!$grid || !$grid.length) { return; }
            const lessonState = getLessonOrderState($grid);
            if (lessonState.timerId) {
                window.clearTimeout(lessonState.timerId);
            }
            lessonState.pendingIds = collectLessonOrderWordIds($grid);
            lessonState.timerId = window.setTimeout(function () {
                lessonState.timerId = 0;
                flushLessonOrderSave($grid);
            }, Math.max(80, delayMs || 0));
        }

        function initLessonWordReorder($scope) {
            const $targets = $scope && $scope.jquery ? $scope : $($scope || []);
            if (!$targets.length) { return; }

            $targets.each(function () {
                const $grid = $(this);
                const lessonId = parseInt($grid.attr('data-ll-lesson-id'), 10) || 0;
                const reorderable = $grid.attr('data-ll-word-grid-reorderable') === '1';
                const itemCount = $grid.children('.word-item[data-word-id]').length;
                const lessonState = getLessonOrderState($grid);

                if (lessonState.timerId) {
                    window.clearTimeout(lessonState.timerId);
                    lessonState.timerId = 0;
                }
                lessonState.pendingIds = [];
                lessonState.queued = false;
                lessonState.saving = false;
                lessonState.lastSavedKey = collectLessonOrderWordIds($grid).join(',');

                setLessonOrderStatus($grid, '', '');
                clearLessonOrderTouchBridge($grid);
                stopLessonOrderViewportAutoScroll($grid);
                $grid.removeClass('ll-word-grid--ordering ll-word-grid--order-handle-required');
                if (typeof $grid.sortable === 'function' && $grid.data('ui-sortable')) {
                    try {
                        $grid.sortable('destroy');
                    } catch (_error) {}
                }

                if (!reorderable || !lessonId || itemCount < 2 || typeof $grid.sortable !== 'function') {
                    return;
                }

                const requireHandle = shouldRequireLessonOrderHandle();
                const sortableOptions = {
                    items: '> .word-item[data-word-id]',
                    distance: 6,
                    tolerance: 'pointer',
                    placeholder: 'll-word-grid-order-placeholder',
                    scroll: false,
                    cancel: [
                        'a',
                        'button',
                        'input',
                        'textarea',
                        'select',
                        'option',
                        'label',
                        'audio',
                        'canvas',
                        '.ll-word-edit-open',
                        '.ll-word-edit-open *',
                        '[data-ll-word-edit-panel]',
                        '[data-ll-word-edit-backdrop]',
                        '[data-ll-word-edit-toggle]',
                        '[data-ll-recording-edit-trigger]'
                    ].join(','),
                    start: function (event, ui) {
                        clearLessonOrderStatusTimer($grid);
                        startLessonOrderViewportAutoScroll($grid, event);
                        $grid.addClass('ll-word-grid--ordering');
                        if (ui && ui.item) {
                            ui.item.addClass('is-dragging');
                        }
                        if (ui && ui.placeholder && ui.item) {
                            ui.placeholder.height(ui.item.outerHeight());
                        }
                    },
                    sort: function (event) {
                        updateLessonOrderViewportAutoScroll($grid, event);
                    },
                    stop: function (_event, ui) {
                        stopLessonOrderViewportAutoScroll($grid);
                        $grid.removeClass('ll-word-grid--ordering');
                        if (ui && ui.item) {
                            ui.item.removeClass('is-dragging');
                        }
                    },
                    update: function (_event, ui) {
                        if (ui && ui.item && ui.item.hasClass('ll-word-edit-open')) {
                            return;
                        }
                        scheduleLessonOrderSave($grid, 140);
                    }
                };

                if (requireHandle) {
                    sortableOptions.handle = LESSON_ORDER_HANDLE_SELECTOR;
                    $grid.addClass('ll-word-grid--order-handle-required');
                    bindLessonOrderTouchBridge($grid);
                }

                $grid.sortable(sortableOptions);
            });
        }

        initLessonWordReorder($grids);
        $(document).on('lltools:word-grid-rendered', function (_evt, detail) {
            const info = (detail && typeof detail === 'object') ? detail : {};
            const $scope = info.scope && info.scope.jquery
                ? info.scope
                : $(info.scope || []);
            if ($scope.length) {
                initLessonWordReorder($scope);
            }
        });

        function getBulkContext($wrap) {
            const $scope = $wrap.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page');
            const $grid = ($scope.length ? $scope : $(document)).find('[data-ll-word-grid]').first();
            if (!$grid.length) { return null; }
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            const categoryId = parseInt($grid.attr('data-ll-category-id'), 10) || 0;
            if (!wordsetId || !categoryId) { return null; }
            return { $grid: $grid, wordsetId: wordsetId, categoryId: categoryId };
        }

        function setBulkBusy($wrap, isBusy) {
            const busy = !!isBusy;
            $wrap.toggleClass('ll-vocab-lesson-bulk--busy', busy);
            $wrap.attr('aria-busy', busy ? 'true' : 'false');
            $wrap.find('[data-ll-bulk-control-undo]').prop('disabled', busy);
        }

        function getBulkControlStatusElement($wrap, controlKey) {
            if (!$wrap || !$wrap.length || !controlKey) { return $(); }
            return $wrap.find('[data-ll-bulk-control-status="' + controlKey + '"]').first();
        }

        function clearBulkControlStatusTimer($wrap, controlKey) {
            const $status = getBulkControlStatusElement($wrap, controlKey);
            if (!$status.length) { return; }
            const timerId = parseInt($status.data('llBulkStatusTimerId'), 10) || 0;
            if (timerId > 0) {
                window.clearTimeout(timerId);
            }
            $status.removeData('llBulkStatusTimerId');
        }

        function setBulkControlStatus($wrap, controlKey, statusState, message) {
            const $status = getBulkControlStatusElement($wrap, controlKey);
            if (!$status.length) { return; }

            clearBulkControlStatusTimer($wrap, controlKey);

            const nextState = ['saving', 'saved', 'error'].indexOf((statusState || '').toString()) !== -1
                ? statusState.toString()
                : 'idle';
            const text = (message || '').toString();
            const $message = $status.find('[data-ll-bulk-control-status-message]').first();

            $status.attr('data-state', nextState);
            if (nextState === 'idle') {
                $status.attr('hidden', 'hidden');
            } else {
                $status.removeAttr('hidden');
            }

            if (text) {
                $status.attr('aria-label', text);
                $status.attr('title', text);
            } else {
                $status.removeAttr('aria-label');
                $status.removeAttr('title');
            }

            if ($message.length) {
                $message.text('');
                $message.attr('hidden', 'hidden');
            }
        }

        function scheduleBulkControlStatusReset($wrap, controlKey, delayMs) {
            const $status = getBulkControlStatusElement($wrap, controlKey);
            if (!$status.length) { return; }

            clearBulkControlStatusTimer($wrap, controlKey);

            const delay = Math.max(0, parseInt(delayMs, 10) || 0);
            if (delay <= 0) {
                setBulkControlStatus($wrap, controlKey, 'idle', '');
                return;
            }

            const timerId = window.setTimeout(function () {
                $status.removeData('llBulkStatusTimerId');
                setBulkControlStatus($wrap, controlKey, 'idle', '');
            }, delay);
            $status.data('llBulkStatusTimerId', timerId);
        }

        function getBulkAutoState($wrap) {
            if (!$wrap || !$wrap.length) { return null; }

            let state = $wrap.data('llBulkAutoState');
            if (state && typeof state === 'object') {
                return state;
            }

            state = {
                activeKey: '',
                activeValue: '',
                queueOrder: [],
                queueValues: {},
                undoSnapshots: {}
            };
            $wrap.data('llBulkAutoState', state);
            return state;
        }

        function getBulkControlUndoButton($wrap, controlKey) {
            if (!$wrap || !$wrap.length || !controlKey) { return $(); }
            return $wrap.find('[data-ll-bulk-control-undo="' + controlKey + '"]').first();
        }

        function setBulkControlsDisabled($wrap, isDisabled) {
            const disabled = !!isDisabled;
            $wrap.find('[data-ll-bulk-pos], [data-ll-bulk-gender], [data-ll-bulk-plurality], [data-ll-bulk-verb-tense], [data-ll-bulk-verb-mood], [data-ll-bulk-control-undo]')
                .prop('disabled', disabled);
        }

        function setBulkControlUndoSnapshot($wrap, controlKey, snapshot) {
            const state = getBulkAutoState($wrap);
            const $button = getBulkControlUndoButton($wrap, controlKey);
            if (!state || !$button.length || !controlKey) { return; }

            if (!snapshot || !Array.isArray(snapshot.rows) || !snapshot.rows.length) {
                delete state.undoSnapshots[controlKey];
                $button.attr('hidden', 'hidden');
                return;
            }

            state.undoSnapshots[controlKey] = snapshot;
            $button.removeAttr('hidden');
        }

        function clearAllBulkControlUndoSnapshots($wrap, exceptKey) {
            const state = getBulkAutoState($wrap);
            if (!state) { return; }

            const keepKey = (exceptKey || '').toString();
            Object.keys(state.undoSnapshots || {}).forEach(function (controlKey) {
                if (keepKey && controlKey === keepKey) {
                    return;
                }
                delete state.undoSnapshots[controlKey];
                getBulkControlUndoButton($wrap, controlKey).attr('hidden', 'hidden');
                setBulkControlStatus($wrap, controlKey, 'idle', '');
            });
        }

        function getPrereqEditorState($editor) {
            if (!$editor || !$editor.length) { return null; }

            let state = $editor.data('llPrereqState');
            if (state && typeof state === 'object') {
                return state;
            }

            const options = normalizePrereqOptionRows(parseJsonArrayAttr($editor, 'data-ll-prereq-options'));
            const optionsById = {};
            options.forEach(function (option) {
                optionsById[option.id] = Object.assign({}, option);
            });

            const selectedRows = normalizePrereqOptionRows(parseJsonArrayAttr($editor, 'data-ll-prereq-selected'));
            const selectedIds = [];
            selectedRows.forEach(function (row) {
                if (!optionsById[row.id]) {
                    optionsById[row.id] = Object.assign({}, row);
                    options.push(optionsById[row.id]);
                } else {
                    optionsById[row.id].label = row.label || optionsById[row.id].label;
                    if (Object.prototype.hasOwnProperty.call(row, 'level')) {
                        optionsById[row.id].level = row.level;
                    }
                }
                if (selectedIds.indexOf(row.id) === -1) {
                    selectedIds.push(row.id);
                }
            });
            const blockedIds = normalizeIntegerIdList(parseJsonArrayAttr($editor, 'data-ll-prereq-blocked'));

            const currentLevelRaw = ($editor.attr('data-ll-prereq-current-level') || '').toString();
            const currentLevel = currentLevelRaw === '' ? null : (parseInt(currentLevelRaw, 10) || 0);
            const hasCycle = ($editor.attr('data-ll-prereq-has-cycle') || '') === '1';

            state = {
                options: options,
                optionsById: optionsById,
                selectedIds: selectedIds,
                blockedIds: blockedIds,
                currentLevel: currentLevel,
                hasCycle: hasCycle,
                isSaving: false,
                needsResave: false,
                saveTimerId: 0,
                statusTimerId: 0,
                lastSavedSelectionKey: '',
                lastRequestSelectionKey: '',
                lastSavedSelectedIds: selectedIds.slice(),
                lastSavedBlockedIds: blockedIds.slice(),
                lastSavedLevel: currentLevel,
                lastSavedHasCycle: hasCycle
            };
            state.lastSavedSelectionKey = serializePrereqSelectedIds(state.selectedIds);

            $editor.data('llPrereqState', state);
            return state;
        }

        function serializePrereqSelectedIds(ids) {
            return JSON.stringify(normalizeIntegerIdList(ids));
        }

        function setPrereqEditorBlockedIds($editor, blockedIds) {
            const state = getPrereqEditorState($editor);
            if (!state) { return; }

            state.blockedIds = normalizeIntegerIdList(blockedIds);
            $editor.attr('data-ll-prereq-blocked', JSON.stringify(state.blockedIds));
        }

        function applyPrereqEditorSavedState($editor, payload) {
            const state = getPrereqEditorState($editor);
            if (!state || !payload || typeof payload !== 'object') { return; }

            const selectedRows = normalizePrereqOptionRows(Array.isArray(payload.selected) ? payload.selected : []);
            const nextSelectedIds = normalizeIntegerIdList(
                Array.isArray(payload.selected_ids) && payload.selected_ids.length
                    ? payload.selected_ids
                    : selectedRows.map(function (row) { return row.id; })
            );

            selectedRows.forEach(function (row) {
                upsertPrereqOption(state, row);
            });

            state.selectedIds = sortPrereqSelectedIds(state, nextSelectedIds);
            setPrereqEditorBlockedIds($editor, payload.blocked_ids);
            setPrereqEditorLevel(
                $editor,
                Object.prototype.hasOwnProperty.call(payload, 'level') ? payload.level : null,
                payload.has_cycle === true
            );

            state.lastSavedSelectedIds = state.selectedIds.slice();
            state.lastSavedBlockedIds = state.blockedIds.slice();
            state.lastSavedLevel = state.currentLevel;
            state.lastSavedHasCycle = state.hasCycle;
            state.lastSavedSelectionKey = serializePrereqSelectedIds(state.selectedIds);

            renderPrereqEditorChips($editor);
            renderPrereqEditorOptions($editor);
        }

        function restoreLastSavedPrereqEditorState($editor) {
            const state = getPrereqEditorState($editor);
            if (!state) { return; }

            applyPrereqEditorSavedState($editor, {
                selected_ids: state.lastSavedSelectedIds,
                blocked_ids: state.lastSavedBlockedIds,
                level: state.lastSavedLevel,
                has_cycle: state.lastSavedHasCycle
            });
        }

        function isPrereqOptionBlocked(state, prereqId) {
            if (!state) { return false; }
            const numericId = parseInt(prereqId, 10) || 0;
            if (!numericId) { return false; }
            return normalizeIntegerIdList(state.blockedIds).indexOf(numericId) !== -1;
        }

        function clearPrereqEditorTimer(state, timerKey) {
            if (!state || !timerKey) { return; }
            const timerId = parseInt(state[timerKey], 10) || 0;
            if (!timerId) { return; }
            window.clearTimeout(timerId);
            state[timerKey] = 0;
        }

        function setPrereqEditorStatus($editor, statusState, message) {
            const $status = $editor.find('[data-ll-prereq-status]').first();
            if (!$status.length) { return; }

            const nextState = ['saving', 'saved', 'error'].indexOf((statusState || '').toString()) !== -1
                ? statusState.toString()
                : 'idle';
            const text = (message || '').toString();
            const $message = $status.find('[data-ll-prereq-status-message]').first();

            $status.attr('data-state', nextState);
            $status.attr('aria-label', text);

            if (nextState === 'idle') {
                $status.attr('hidden', 'hidden');
            } else {
                $status.removeAttr('hidden');
            }

            if ($message.length) {
                if (nextState === 'error' && text) {
                    $message.text(text);
                    $message.removeAttr('hidden');
                } else {
                    $message.text('');
                    $message.attr('hidden', 'hidden');
                }
            }
        }

        function setPrereqEditorBusy($editor, isBusy) {
            const busy = !!isBusy;
            $editor.attr('aria-busy', busy ? 'true' : 'false');
            $editor.find('[data-ll-prereq-input], [data-ll-prereq-search-clear], [data-ll-prereq-remove], [data-ll-prereq-option]')
                .prop('disabled', busy);
        }

        function schedulePrereqEditorStatusReset($editor, delayMs) {
            const state = getPrereqEditorState($editor);
            if (!state) { return; }
            clearPrereqEditorTimer(state, 'statusTimerId');

            const delay = parseInt(delayMs, 10) || 0;
            if (delay <= 0) {
                setPrereqEditorStatus($editor, 'idle', '');
                return;
            }

            state.statusTimerId = window.setTimeout(function () {
                state.statusTimerId = 0;
                setPrereqEditorStatus($editor, 'idle', '');
            }, delay);
        }

        function setPrereqEditorLevel($editor, level, hasCycle) {
            const $level = $editor.find('[data-ll-prereq-level]').first();
            if ($level.length) {
                $level.text(formatPrereqLevelText(level, hasCycle));
            }

            const $warning = $editor.find('[data-ll-prereq-cycle-warning]').first();
            if ($warning.length) {
                if (hasCycle) {
                    $warning.removeAttr('hidden');
                } else {
                    $warning.attr('hidden', 'hidden');
                }
            }

            $editor.attr('data-ll-prereq-current-level', (level === null || level === undefined) ? '' : String(level));
            $editor.attr('data-ll-prereq-has-cycle', hasCycle ? '1' : '0');

            const state = getPrereqEditorState($editor);
            if (state) {
                state.currentLevel = (level === null || level === undefined) ? null : (parseInt(level, 10) || 0);
                state.hasCycle = !!hasCycle;
            }
        }

        function sortPrereqSelectedIds(state, ids) {
            if (!state || !Array.isArray(ids)) { return []; }

            const selectedLookup = {};
            ids.forEach(function (id) {
                const numericId = parseInt(id, 10) || 0;
                if (numericId) {
                    selectedLookup[numericId] = true;
                }
            });

            const orderedIds = [];
            (Array.isArray(state.options) ? state.options : []).forEach(function (option) {
                const optionId = parseInt(option && option.id, 10) || 0;
                if (!optionId || !selectedLookup[optionId]) {
                    return;
                }
                orderedIds.push(optionId);
                delete selectedLookup[optionId];
            });

            Object.keys(selectedLookup).forEach(function (id) {
                const numericId = parseInt(id, 10) || 0;
                if (numericId) {
                    orderedIds.push(numericId);
                }
            });

            return orderedIds;
        }

        function renderPrereqEditorChips($editor) {
            const state = getPrereqEditorState($editor);
            const $chips = $editor.find('[data-ll-prereq-chips]').first();
            if (!state || !$chips.length) { return; }

            $chips.empty();

            if (!state.selectedIds.length) {
                $chips.attr('hidden', 'hidden');
                return;
            }

            $chips.removeAttr('hidden');

            state.selectedIds.forEach(function (id) {
                const numericId = parseInt(id, 10) || 0;
                if (!numericId) { return; }
                const option = state.optionsById[numericId] || { id: numericId, label: String(numericId) };
                const $chip = $('<span>', {
                    class: 'll-vocab-lesson-prereq-chip',
                    'data-ll-prereq-chip-id': numericId
                });

                $chip.append($('<span>', {
                    class: 'll-vocab-lesson-prereq-chip-label',
                    text: option.label || String(numericId)
                }));

                const levelRaw = parseInt(option.level, 10);
                if (Number.isFinite(levelRaw) && !state.hasCycle) {
                    $chip.append($('<span>', {
                        class: 'll-vocab-lesson-prereq-chip-level',
                        text: 'L' + String(levelRaw),
                        'aria-hidden': 'true'
                    }));
                }

                $chip.append($('<button>', {
                    type: 'button',
                    class: 'll-vocab-lesson-prereq-chip-remove',
                    'data-ll-prereq-remove': String(numericId),
                    'aria-label': formatStringMessage(prereqMessages.remove, option.label || String(numericId)),
                    text: 'x'
                }));

                $chips.append($chip);
            });
        }

        function renderPrereqEditorSearchControls($editor) {
            const $input = $editor.find('[data-ll-prereq-input]').first();
            const $clear = $editor.find('[data-ll-prereq-search-clear]').first();
            if (!$input.length || !$clear.length) { return; }

            if (($input.val() || '').toString().trim()) {
                $clear.removeAttr('hidden');
            } else {
                $clear.attr('hidden', 'hidden');
            }
        }

        function renderPrereqEditorOptions($editor) {
            const state = getPrereqEditorState($editor);
            const $list = $editor.find('[data-ll-prereq-options-list]').first();
            const $input = $editor.find('[data-ll-prereq-input]').first();
            if (!state || !$list.length) { return; }

            renderPrereqEditorSearchControls($editor);
            $list.empty();

            const term = $input.length
                ? ($input.val() || '').toString().trim().toLowerCase()
                : '';
            const selectedLookup = {};
            state.selectedIds.forEach(function (id) {
                const numericId = parseInt(id, 10) || 0;
                if (numericId) {
                    selectedLookup[numericId] = true;
                }
            });
            const blockedLookup = {};
            normalizeIntegerIdList(state.blockedIds).forEach(function (id) {
                blockedLookup[id] = true;
            });

            const selectedRows = [];
            const availableRows = [];
            const blockedRows = [];

            (Array.isArray(state.options) ? state.options : []).forEach(function (option) {
                const numericId = parseInt(option && option.id, 10) || 0;
                if (!numericId) { return; }

                const label = (option && option.label) ? option.label.toString() : String(numericId);
                const haystack = label.toLowerCase();
                const idText = String(numericId);
                if (term && haystack.indexOf(term) === -1 && idText.indexOf(term) === -1) {
                    return;
                }

                const isSelected = !!selectedLookup[numericId];
                const isBlocked = !!blockedLookup[numericId] && !isSelected;

                if (!term && isBlocked) {
                    return;
                }

                if (isSelected) {
                    selectedRows.push(option);
                } else if (isBlocked) {
                    blockedRows.push(option);
                } else {
                    availableRows.push(option);
                }
            });

            const rows = selectedRows.concat(availableRows, blockedRows);
            if (!rows.length) {
                $list.append($('<div>', {
                    class: 'll-vocab-lesson-prereq-options-empty',
                    text: prereqMessages.noMatches
                }));
                return;
            }

            rows.forEach(function (option) {
                const numericId = parseInt(option && option.id, 10) || 0;
                if (!numericId) { return; }

                const label = (option && option.label) ? option.label.toString() : String(numericId);
                const isSelected = !!selectedLookup[numericId];
                const isBlocked = !!blockedLookup[numericId] && !isSelected;
                const $button = $('<button>', {
                    type: 'button',
                    class: 'll-vocab-lesson-prereq-option'
                        + (isSelected ? ' is-selected' : '')
                        + (isBlocked ? ' is-blocked' : ''),
                    'data-ll-prereq-option': String(numericId),
                    'aria-pressed': isSelected ? 'true' : 'false',
                    'aria-label': formatStringMessage(
                        isBlocked
                            ? prereqMessages.optionBlocked
                            : (isSelected ? prereqMessages.optionRemove : prereqMessages.optionAdd),
                        label
                    ),
                    disabled: state.isSaving || isBlocked
                });
                if (isBlocked) {
                    $button.attr('aria-disabled', 'true');
                    $button.attr('title', prereqMessages.blockedHint);
                }
                const $main = $('<span>', { class: 'll-vocab-lesson-prereq-option-main' });
                $main.append($('<span>', {
                    class: 'll-vocab-lesson-prereq-option-toggle',
                    'aria-hidden': 'true'
                }));
                $main.append($('<span>', {
                    class: 'll-vocab-lesson-prereq-option-label',
                    text: label
                }));
                $button.append($main);

                const levelRaw = parseInt(option && option.level, 10);
                if (Number.isFinite(levelRaw) && !state.hasCycle) {
                    $button.append($('<span>', {
                        class: 'll-vocab-lesson-prereq-option-level',
                        text: 'L' + String(levelRaw),
                        'aria-hidden': 'true'
                    }));
                }

                $list.append($button);
            });
        }

        function upsertPrereqOption(state, optionRow) {
            if (!state || !optionRow || typeof optionRow !== 'object') { return null; }
            const id = parseInt(optionRow.id, 10) || 0;
            if (!id) { return null; }

            let option = state.optionsById[id];
            if (!option) {
                option = { id: id, label: (optionRow.label || String(id)).toString() };
                state.optionsById[id] = option;
                state.options.push(option);
            }

            if (typeof optionRow.label === 'string' && optionRow.label) {
                option.label = optionRow.label;
            }
            if (Object.prototype.hasOwnProperty.call(optionRow, 'level')) {
                const levelRaw = parseInt(optionRow.level, 10);
                if (Number.isFinite(levelRaw)) {
                    option.level = levelRaw;
                } else {
                    delete option.level;
                }
            }

            return option;
        }

        function addPrereqSelection($editor, optionRow) {
            const state = getPrereqEditorState($editor);
            if (!state) { return false; }
            const option = upsertPrereqOption(state, optionRow);
            if (!option) { return false; }
            if (state.selectedIds.indexOf(option.id) !== -1) {
                return false;
            }
            state.selectedIds = sortPrereqSelectedIds(state, state.selectedIds.concat([option.id]));
            renderPrereqEditorChips($editor);
            renderPrereqEditorOptions($editor);
            return true;
        }

        function removePrereqSelection($editor, prereqId) {
            const state = getPrereqEditorState($editor);
            const id = parseInt(prereqId, 10) || 0;
            if (!state || !id) { return false; }
            const nextIds = state.selectedIds.filter(function (currentId) {
                return (parseInt(currentId, 10) || 0) !== id;
            });
            if (nextIds.length === state.selectedIds.length) {
                return false;
            }
            state.selectedIds = sortPrereqSelectedIds(state, nextIds);
            renderPrereqEditorChips($editor);
            renderPrereqEditorOptions($editor);
            return true;
        }

        function togglePrereqSelection($editor, prereqId) {
            const state = getPrereqEditorState($editor);
            const numericId = parseInt(prereqId, 10) || 0;
            if (!state || !numericId) { return false; }
            if (isPrereqOptionBlocked(state, numericId)) {
                return false;
            }

            if (state.selectedIds.indexOf(numericId) !== -1) {
                return removePrereqSelection($editor, numericId);
            }

            const option = state.optionsById[numericId] || { id: numericId, label: String(numericId) };
            return addPrereqSelection($editor, option);
        }

        function persistPrereqEditorSelection($editor) {
            const state = getPrereqEditorState($editor);
            if (!state) { return; }

            clearPrereqEditorTimer(state, 'saveTimerId');

            const selectedKey = serializePrereqSelectedIds(state.selectedIds);
            if (state.isSaving) {
                state.needsResave = true;
                return;
            }

            if (selectedKey === state.lastSavedSelectionKey) {
                schedulePrereqEditorStatusReset($editor, 0);
                return;
            }

            const context = getBulkContext($editor);
            if (!context) {
                setPrereqEditorStatus($editor, 'error', prereqMessages.error);
                return;
            }

            state.isSaving = true;
            state.needsResave = false;
            state.lastRequestSelectionKey = selectedKey;
            let saveSucceeded = false;

            clearPrereqEditorTimer(state, 'statusTimerId');
            setPrereqEditorBusy($editor, true);
            setPrereqEditorStatus($editor, 'saving', prereqMessages.saving);

            $.post(ajaxUrl, {
                action: 'll_tools_word_grid_update_category_prereqs',
                nonce: editNonce,
                wordset_id: context.wordsetId,
                category_id: context.categoryId,
                prereq_ids: state.selectedIds.slice()
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const responseMessage = response && response.data && typeof response.data.message === 'string'
                        ? response.data.message
                        : prereqMessages.error;
                    if (serializePrereqSelectedIds(state.selectedIds) === state.lastRequestSelectionKey) {
                        restoreLastSavedPrereqEditorState($editor);
                    }
                    setPrereqEditorStatus($editor, 'error', responseMessage);
                    return;
                }

                const data = response.data || {};
                saveSucceeded = true;
                applyPrereqEditorSavedState($editor, data);

                if (serializePrereqSelectedIds(state.selectedIds) === state.lastRequestSelectionKey) {
                    setPrereqEditorStatus($editor, 'saved', (typeof data.message === 'string' && data.message) ? data.message : prereqMessages.saved);
                    schedulePrereqEditorStatusReset($editor, prereqStatusHideDelayMs);
                }
            }).fail(function (jqXHR) {
                const response = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && typeof jqXHR.responseJSON.data === 'object'
                    ? jqXHR.responseJSON.data
                    : null;
                if (serializePrereqSelectedIds(state.selectedIds) === state.lastRequestSelectionKey) {
                    if (response) {
                        applyPrereqEditorSavedState($editor, response);
                    } else {
                        restoreLastSavedPrereqEditorState($editor);
                    }
                } else if (response && Object.prototype.hasOwnProperty.call(response, 'blocked_ids')) {
                    setPrereqEditorBlockedIds($editor, response.blocked_ids);
                    renderPrereqEditorOptions($editor);
                }
                setPrereqEditorStatus($editor, 'error', readAjaxErrorMessage(jqXHR, prereqMessages.error));
            }).always(function () {
                state.isSaving = false;
                setPrereqEditorBusy($editor, false);

                if (state.needsResave || (saveSucceeded && serializePrereqSelectedIds(state.selectedIds) !== state.lastSavedSelectionKey)) {
                    schedulePrereqEditorSave($editor, 0);
                }
            });
        }

        function schedulePrereqEditorSave($editor, delayMs) {
            const state = getPrereqEditorState($editor);
            if (!state) { return; }

            clearPrereqEditorTimer(state, 'saveTimerId');
            clearPrereqEditorTimer(state, 'statusTimerId');

            if (!state.isSaving && serializePrereqSelectedIds(state.selectedIds) === state.lastSavedSelectionKey) {
                setPrereqEditorStatus($editor, 'idle', '');
                return;
            }

            const delay = Math.max(0, parseInt(delayMs, 10) || 0);
            if (delay <= 0) {
                persistPrereqEditorSelection($editor);
                return;
            }
            state.saveTimerId = window.setTimeout(function () {
                state.saveTimerId = 0;
                persistPrereqEditorSelection($editor);
            }, delay);
        }

        function initPrereqEditor($editor) {
            if (!$editor || !$editor.length) { return; }
            getPrereqEditorState($editor);
            renderPrereqEditorChips($editor);
            renderPrereqEditorOptions($editor);

            const state = getPrereqEditorState($editor);
            if (state) {
                setPrereqEditorLevel($editor, state.currentLevel, state.hasCycle);
            }
        }

        function forEachBulkWordItem(context, ids, applyUpdate) {
            const wordIds = Array.isArray(ids) ? ids : [];
            wordIds.forEach(function (id) {
                const wordId = parseInt(id, 10) || 0;
                if (!wordId) { return; }
                const $item = context.$grid.find('.word-item[data-word-id="' + wordId + '"]').first();
                if (!$item.length) { return; }
                applyUpdate($item, wordId);
                updateOriginalInputs($item);
            });
        }

        const bulkControlConfigs = {
            pos: {
                statusKey: 'pos',
                selector: '[data-ll-bulk-pos]',
                mode: 'pos',
                defaultField: 'part_of_speech',
                scope: 'all',
                requestField: 'part_of_speech',
                successTemplate: bulkMessages.posSuccess,
                applyResponse: function (context, data) {
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const posData = data.part_of_speech || {};
                    const clearGender = data.gender_cleared === true;
                    const clearPlurality = data.plurality_cleared === true;
                    const clearVerbTense = data.verb_tense_cleared === true;
                    const clearVerbMood = data.verb_mood_cleared === true;

                    forEachBulkWordItem(context, ids, function ($item) {
                        const genderData = clearGender ? { value: '', label: '' } : null;
                        const pluralityData = clearPlurality ? { value: '', label: '' } : null;
                        const verbTenseData = clearVerbTense ? { value: '', label: '' } : null;
                        const verbMoodData = clearVerbMood ? { value: '', label: '' } : null;
                        applyPosMetaUpdate($item, posData, genderData, pluralityData, verbTenseData, verbMoodData);
                    });

                    return ids.length;
                }
            },
            gender: {
                statusKey: 'gender',
                selector: '[data-ll-bulk-gender]',
                mode: 'gender',
                defaultField: 'grammatical_gender',
                scope: 'noun',
                requestField: 'grammatical_gender',
                successTemplate: bulkMessages.genderSuccess,
                applyResponse: function (context, data) {
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const genderData = data.grammatical_gender || {};

                    forEachBulkWordItem(context, ids, function ($item) {
                        applyPosMetaUpdate($item, null, genderData, null, null, null);
                    });

                    return ids.length;
                }
            },
            plurality: {
                statusKey: 'plurality',
                selector: '[data-ll-bulk-plurality]',
                mode: 'plurality',
                defaultField: 'grammatical_plurality',
                scope: 'noun',
                requestField: 'grammatical_plurality',
                successTemplate: bulkMessages.pluralitySuccess,
                applyResponse: function (context, data) {
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const pluralityData = data.grammatical_plurality || {};

                    forEachBulkWordItem(context, ids, function ($item) {
                        applyPosMetaUpdate($item, null, null, pluralityData, null, null);
                    });

                    return ids.length;
                }
            },
            'verb-tense': {
                statusKey: 'verb-tense',
                selector: '[data-ll-bulk-verb-tense]',
                mode: 'verb_tense',
                defaultField: 'verb_tense',
                scope: 'verb',
                requestField: 'verb_tense',
                successTemplate: bulkMessages.verbTenseSuccess,
                applyResponse: function (context, data) {
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const verbTenseData = data.verb_tense || {};

                    forEachBulkWordItem(context, ids, function ($item) {
                        applyPosMetaUpdate($item, null, null, null, verbTenseData, null);
                    });

                    return ids.length;
                }
            },
            'verb-mood': {
                statusKey: 'verb-mood',
                selector: '[data-ll-bulk-verb-mood]',
                mode: 'verb_mood',
                defaultField: 'verb_mood',
                scope: 'verb',
                requestField: 'verb_mood',
                successTemplate: bulkMessages.verbMoodSuccess,
                applyResponse: function (context, data) {
                    const ids = Array.isArray(data.word_ids) ? data.word_ids : [];
                    const verbMoodData = data.verb_mood || {};

                    forEachBulkWordItem(context, ids, function ($item) {
                        applyPosMetaUpdate($item, null, null, null, null, verbMoodData);
                    });

                    return ids.length;
                }
            }
        };

        function getBulkControlWordItems(context, controlKey) {
            const config = bulkControlConfigs[controlKey] || null;
            if (!context || !context.$grid || !config) { return $(); }

            const $items = context.$grid.find('.word-item');
            if (!$items.length) {
                return $items;
            }

            if (config.scope === 'noun') {
                return $items.filter(function () {
                    return getWordBulkFieldValue($(this), 'part_of_speech') === 'noun';
                });
            }
            if (config.scope === 'verb') {
                return $items.filter(function () {
                    return getWordBulkFieldValue($(this), 'part_of_speech') === 'verb';
                });
            }

            return $items;
        }

        function collectBulkUndoSnapshot(context, controlKey) {
            const $items = getBulkControlWordItems(context, controlKey);
            const rows = [];

            $items.each(function () {
                const snapshot = captureCurrentBulkWordSnapshot($(this));
                if (snapshot) {
                    rows.push(snapshot);
                }
            });

            return {
                controlKey: controlKey,
                rows: rows
            };
        }

        function summarizeBulkDefaultValues(values) {
            const list = Array.isArray(values) ? values : [];
            if (!list.length) {
                return '';
            }

            const first = (list[0] || '').toString().trim();
            if (!first) {
                return '';
            }

            for (let index = 1; index < list.length; index += 1) {
                if ((list[index] || '').toString().trim() !== first) {
                    return '';
                }
            }

            return first;
        }

        function computeBulkControlDefaultsFromGrid($wrap) {
            const defaults = {
                part_of_speech: '',
                grammatical_gender: '',
                grammatical_plurality: '',
                verb_tense: '',
                verb_mood: ''
            };
            const context = getBulkContext($wrap);
            if (!context || !context.$grid || !context.$grid.length) {
                return null;
            }

            const $items = context.$grid.find('.word-item');
            if (!$items.length) {
                return null;
            }

            const posValues = [];
            const genderValues = [];
            const pluralityValues = [];
            const verbTenseValues = [];
            const verbMoodValues = [];

            $items.each(function () {
                const $item = $(this);
                const posValue = getWordBulkFieldValue($item, 'part_of_speech');
                posValues.push(posValue);

                if (posValue === 'noun') {
                    genderValues.push(getWordBulkFieldValue($item, 'gender'));
                    pluralityValues.push(getWordBulkFieldValue($item, 'plurality'));
                }
                if (posValue === 'verb') {
                    verbTenseValues.push(getWordBulkFieldValue($item, 'verb_tense'));
                    verbMoodValues.push(getWordBulkFieldValue($item, 'verb_mood'));
                }
            });

            defaults.part_of_speech = summarizeBulkDefaultValues(posValues);
            defaults.grammatical_gender = summarizeBulkDefaultValues(genderValues);
            defaults.grammatical_plurality = summarizeBulkDefaultValues(pluralityValues);
            defaults.verb_tense = summarizeBulkDefaultValues(verbTenseValues);
            defaults.verb_mood = summarizeBulkDefaultValues(verbMoodValues);

            return defaults;
        }

        function syncBulkControlSelectDefaults($wrap, providedDefaults, options) {
            const syncOptions = (options && typeof options === 'object') ? options : {};
            const defaults = (providedDefaults && typeof providedDefaults === 'object')
                ? providedDefaults
                : computeBulkControlDefaultsFromGrid($wrap);
            if (!defaults || typeof defaults !== 'object') {
                return;
            }

            Object.keys(bulkControlConfigs).forEach(function (controlKey) {
                const config = bulkControlConfigs[controlKey];
                const $select = getBulkControlSelect($wrap, controlKey);
                if (!$select.length || !config || !config.defaultField) {
                    return;
                }

                const currentValue = (($select.val() || '') + '').trim();
                let nextValue = ((defaults[config.defaultField] || '') + '').trim();
                if (!nextValue && syncOptions.preserveExistingOnEmpty && currentValue) {
                    nextValue = currentValue;
                }
                $select.find('option[data-ll-bulk-temp-option]').remove();

                if (nextValue && !$select.find('option').filter(function () {
                    return (($(this).val() || '').toString() === nextValue);
                }).length) {
                    $select.append($('<option>', {
                        value: nextValue,
                        text: nextValue,
                        'data-ll-bulk-temp-option': '1'
                    }));
                }

                $select.val(nextValue);
            });
        }

        $(document).on('lltools:word-grid-rendered', function (_evt, detail) {
            const info = (detail && typeof detail === 'object') ? detail : {};
            const $scope = info.scope && info.scope.jquery
                ? info.scope
                : $(info.scope || []);
            if (!$scope.length || !$bulkEditors.length) {
                return;
            }

            try {
                $scope.each(function () {
                    const $bulkWrap = $(this)
                        .closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page')
                        .find('[data-ll-word-grid-bulk]')
                        .first();
                    if ($bulkWrap.length) {
                        syncBulkControlSelectDefaults($bulkWrap);
                    }
                });
            } catch (error) {
                console.error('LL Tools: failed to sync bulk defaults for rendered word grid.', error);
            }
        });

        function applyBulkUndoWords(context, words) {
            if (!context || !context.$grid || !Array.isArray(words)) { return; }

            words.forEach(function (word) {
                const wordId = parseInt(word && word.word_id, 10) || 0;
                if (!wordId) { return; }
                const $item = context.$grid.find('.word-item[data-word-id="' + wordId + '"]').first();
                if (!$item.length) { return; }

                applyPosMetaUpdate(
                    $item,
                    word.part_of_speech || {},
                    word.grammatical_gender || {},
                    word.grammatical_plurality || {},
                    word.verb_tense || {},
                    word.verb_mood || {}
                );
                updateOriginalInputs($item);
            });
        }

        function getBulkControlConfigForSelect($select) {
            if (!$select || !$select.length) { return null; }

            const keys = Object.keys(bulkControlConfigs);
            for (let index = 0; index < keys.length; index += 1) {
                const key = keys[index];
                const config = bulkControlConfigs[key];
                if (config && config.selector && $select.is(config.selector)) {
                    return config;
                }
            }

            return null;
        }

        function getBulkControlSelect($wrap, controlKey) {
            const config = bulkControlConfigs[controlKey] || null;
            if (!$wrap || !$wrap.length || !config || !config.selector) { return $(); }
            return $wrap.find(config.selector).first();
        }

        function queueBulkControlValue(state, controlKey, value) {
            if (!state || !controlKey) { return; }
            const normalizedValue = (value || '').toString();
            if (!normalizedValue) {
                delete state.queueValues[controlKey];
                state.queueOrder = state.queueOrder.filter(function (queuedKey) {
                    return queuedKey !== controlKey;
                });
                return;
            }
            state.queueValues[controlKey] = normalizedValue;
            if (state.queueOrder.indexOf(controlKey) === -1) {
                state.queueOrder.push(controlKey);
            }
        }

        function flushBulkControlQueue($wrap) {
            const state = getBulkAutoState($wrap);
            if (!state) { return; }
            if (state.activeKey) {
                setBulkBusy($wrap, true);
                return;
            }

            while (state.queueOrder.length) {
                const controlKey = state.queueOrder.shift();
                const nextValue = (state.queueValues[controlKey] || '').toString();
                delete state.queueValues[controlKey];
                if (!nextValue || !bulkControlConfigs[controlKey]) {
                    continue;
                }
                persistBulkControlUpdate($wrap, controlKey, nextValue);
                return;
            }

            setBulkBusy($wrap, false);
        }

        function persistBulkControlUpdate($wrap, controlKey, value) {
            const state = getBulkAutoState($wrap);
            const config = bulkControlConfigs[controlKey] || null;
            const context = getBulkContext($wrap);
            const requestValue = (value || '').toString();

            if (!state || !config || !requestValue) { return; }
            if (!context) {
                setBulkControlStatus($wrap, controlKey, 'error', bulkMessages.error);
                setBulkStatus($wrap, bulkMessages.error, true);
                flushBulkControlQueue($wrap);
                return;
            }

            const undoSnapshot = collectBulkUndoSnapshot(context, controlKey);
            state.activeKey = controlKey;
            state.activeValue = requestValue;
            setBulkBusy($wrap, true);
            setBulkStatus($wrap, '', false);
            setBulkControlStatus($wrap, controlKey, 'saving', bulkMessages.saving);

            const payload = {
                action: 'll_tools_word_grid_bulk_update',
                nonce: editNonce,
                mode: config.mode,
                wordset_id: context.wordsetId,
                category_id: context.categoryId
            };
            payload[config.requestField] = requestValue;

            $.post(ajaxUrl, payload).done(function (response) {
                if (!response || response.success !== true) {
                    const responseMessage = response && typeof response.data === 'string'
                        ? response.data
                        : (response && response.data && typeof response.data.message === 'string'
                            ? response.data.message
                            : bulkMessages.error);
                    setBulkControlStatus($wrap, controlKey, 'error', responseMessage);
                    setBulkStatus($wrap, responseMessage, true);
                    return;
                }

                const data = response.data || {};
                const updatedCount = typeof config.applyResponse === 'function'
                    ? (parseInt(config.applyResponse(context, data), 10) || 0)
                    : (Array.isArray(data.word_ids) ? data.word_ids.length : 0);
                const hasUndoSnapshot = !!(undoSnapshot && Array.isArray(undoSnapshot.rows) && undoSnapshot.rows.length && updatedCount > 0);

                updateGridLayouts();
                syncBulkControlSelectDefaults($wrap);
                clearAllBulkControlUndoSnapshots($wrap, controlKey);
                setBulkControlUndoSnapshot($wrap, controlKey, hasUndoSnapshot ? undoSnapshot : null);
                setBulkStatus($wrap, '', false);
                setBulkControlStatus(
                    $wrap,
                    controlKey,
                    'saved',
                    config.successTemplate ? formatBulkMessage(config.successTemplate, updatedCount) : bulkMessages.saved
                );
                if (!hasUndoSnapshot) {
                    scheduleBulkControlStatusReset($wrap, controlKey, bulkStatusHideDelayMs);
                }
            }).fail(function (jqXHR) {
                const errorMessage = readAjaxErrorMessage(jqXHR, bulkMessages.error);
                setBulkControlStatus($wrap, controlKey, 'error', errorMessage);
                setBulkStatus($wrap, errorMessage, true);
            }).always(function () {
                const currentState = getBulkAutoState($wrap);
                const $select = getBulkControlSelect($wrap, controlKey);
                const currentValue = $select.length ? ($select.val() || '').toString() : '';

                if (currentState) {
                    currentState.activeKey = '';
                    currentState.activeValue = '';
                    if (currentValue) {
                        if (currentValue !== requestValue) {
                            queueBulkControlValue(currentState, controlKey, currentValue);
                        }
                    } else {
                        setBulkControlStatus($wrap, controlKey, 'idle', '');
                    }
                }

                flushBulkControlQueue($wrap);
            });
        }

        function undoBulkControlUpdate($wrap, controlKey) {
            const state = getBulkAutoState($wrap);
            const config = bulkControlConfigs[controlKey] || null;
            const context = getBulkContext($wrap);
            const snapshot = state && state.undoSnapshots ? state.undoSnapshots[controlKey] : null;

            if (!state || !config || !context || !snapshot || !Array.isArray(snapshot.rows) || !snapshot.rows.length || state.activeKey) {
                return;
            }

            state.activeKey = controlKey;
            state.activeValue = '';
            setBulkControlsDisabled($wrap, true);
            setBulkBusy($wrap, true);
            setBulkStatus($wrap, '', false);
            setBulkControlStatus($wrap, controlKey, 'saving', bulkMessages.saving);

            const payloadRows = snapshot.rows.map(function (row) {
                const raw = row && row.raw && typeof row.raw === 'object' ? row.raw : {};
                return {
                    word_id: parseInt(row && row.word_id, 10) || 0,
                    part_of_speech: (raw.part_of_speech || '').toString(),
                    grammatical_gender: (raw.grammatical_gender || '').toString(),
                    grammatical_plurality: (raw.grammatical_plurality || '').toString(),
                    verb_tense: (raw.verb_tense || '').toString(),
                    verb_mood: (raw.verb_mood || '').toString()
                };
            }).filter(function (row) {
                return row.word_id > 0;
            });

            $.post(ajaxUrl, {
                action: 'll_tools_word_grid_bulk_undo',
                nonce: editNonce,
                mode: config.mode,
                wordset_id: context.wordsetId,
                category_id: context.categoryId,
                snapshot: JSON.stringify(payloadRows)
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const responseMessage = response && typeof response.data === 'string'
                        ? response.data
                        : (response && response.data && typeof response.data.message === 'string'
                            ? response.data.message
                            : bulkMessages.undoError);
                    setBulkControlStatus($wrap, controlKey, 'error', responseMessage);
                    setBulkStatus($wrap, responseMessage, true);
                    return;
                }

                const data = response.data || {};
                applyBulkUndoWords(context, Array.isArray(data.words) ? data.words : []);
                updateGridLayouts();
                syncBulkControlSelectDefaults($wrap);
                clearAllBulkControlUndoSnapshots($wrap);
                setBulkStatus($wrap, '', false);
                setBulkControlStatus($wrap, controlKey, 'saved', (typeof data.message === 'string' && data.message) ? data.message : bulkMessages.undoSuccess);
                scheduleBulkControlStatusReset($wrap, controlKey, bulkStatusHideDelayMs);
            }).fail(function (jqXHR) {
                const errorMessage = readAjaxErrorMessage(jqXHR, bulkMessages.undoError);
                setBulkControlStatus($wrap, controlKey, 'error', errorMessage);
                setBulkStatus($wrap, errorMessage, true);
            }).always(function () {
                const currentState = getBulkAutoState($wrap);
                if (currentState) {
                    currentState.activeKey = '';
                    currentState.activeValue = '';
                }
                setBulkControlsDisabled($wrap, false);
                setBulkBusy($wrap, false);
            });
        }

        if ($prereqEditors.length) {
            $prereqEditors.each(function () {
                initPrereqEditor($(this));
            });
        }

        if ($bulkEditors.length) {
            $bulkEditors.each(function () {
                syncBulkControlSelectDefaults($(this), null, { preserveExistingOnEmpty: true });
            });
        }

        if ($prereqEditors.length) {
            $prereqEditors.on('input', '[data-ll-prereq-input]', function () {
                const $editor = $(this).closest('[data-ll-prereq-editor]');
                if (!$editor.length) { return; }
                renderPrereqEditorOptions($editor);
                setPrereqEditorStatus($editor, 'idle', '');
            });

            $prereqEditors.on('keydown', '[data-ll-prereq-input]', function (e) {
                const $input = $(this);
                const $editor = $input.closest('[data-ll-prereq-editor]');
                if (!$editor.length) { return; }

                if (e.key === 'Enter') {
                    const $firstOption = $editor.find('[data-ll-prereq-option]').filter(function () {
                        return !$(this).prop('disabled');
                    }).first();
                    if ($firstOption.length) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (togglePrereqSelection($editor, $firstOption.attr('data-ll-prereq-option'))) {
                            setPrereqEditorStatus($editor, 'idle', '');
                            schedulePrereqEditorSave($editor, prereqSaveDelayMs);
                        }
                    }
                    return;
                }

                if (e.key === 'Escape' && ($input.val() || '').toString()) {
                    e.preventDefault();
                    e.stopPropagation();
                    $input.val('');
                    renderPrereqEditorOptions($editor);
                    setPrereqEditorStatus($editor, 'idle', '');
                }
            });

            $prereqEditors.on('click', '[data-ll-prereq-search-clear]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $btn = $(this);
                if ($btn.prop('disabled')) { return; }
                const $editor = $btn.closest('[data-ll-prereq-editor]');
                const $input = $editor.find('[data-ll-prereq-input]').first();
                if (!$editor.length || !$input.length) { return; }
                $input.val('');
                renderPrereqEditorOptions($editor);
                setPrereqEditorStatus($editor, 'idle', '');
                $input.trigger('focus');
            });

            $prereqEditors.on('click', '[data-ll-prereq-option]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $btn = $(this);
                if ($btn.prop('disabled')) { return; }
                const $editor = $btn.closest('[data-ll-prereq-editor]');
                if (!$editor.length) { return; }
                if (togglePrereqSelection($editor, $btn.attr('data-ll-prereq-option'))) {
                    setPrereqEditorStatus($editor, 'idle', '');
                    schedulePrereqEditorSave($editor, prereqSaveDelayMs);
                }
            });

            $prereqEditors.on('click', '[data-ll-prereq-remove]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $btn = $(this);
                if ($btn.prop('disabled')) { return; }
                const $editor = $btn.closest('[data-ll-prereq-editor]');
                if (!$editor.length) { return; }
                const prereqId = parseInt($btn.attr('data-ll-prereq-remove'), 10) || 0;
                if (!prereqId) { return; }
                if (removePrereqSelection($editor, prereqId)) {
                    setPrereqEditorStatus($editor, 'idle', '');
                    schedulePrereqEditorSave($editor, prereqSaveDelayMs);
                }
            });
        }

        if ($bulkEditors.length) {
            $bulkEditors.on('click', '[data-ll-bulk-control-undo]', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const $btn = $(this);
                if ($btn.prop('disabled')) { return; }
                const $wrap = $btn.closest('[data-ll-word-grid-bulk]');
                const controlKey = ($btn.attr('data-ll-bulk-control-undo') || '').toString();
                if (!$wrap.length || !controlKey) { return; }
                undoBulkControlUpdate($wrap, controlKey);
            });

            $bulkEditors.on('change', '[data-ll-bulk-pos], [data-ll-bulk-gender], [data-ll-bulk-plurality], [data-ll-bulk-verb-tense], [data-ll-bulk-verb-mood]', function () {
                const $select = $(this);
                const $wrap = $select.closest('[data-ll-word-grid-bulk]');
                const config = getBulkControlConfigForSelect($select);
                const state = getBulkAutoState($wrap);
                const selectedValue = ($select.val() || '').toString();

                if (!$wrap.length || !config || !state) {
                    return;
                }

                setBulkStatus($wrap, '', false);

                if (!selectedValue) {
                    queueBulkControlValue(state, config.statusKey, '');
                    if (state.activeKey !== config.statusKey) {
                        setBulkControlStatus($wrap, config.statusKey, 'idle', '');
                    }
                    return;
                }

                queueBulkControlValue(state, config.statusKey, selectedValue);
                flushBulkControlQueue($wrap);
            });
        }

        $grids.on('click', '[data-ll-word-edit-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const $panel = $item.find('[data-ll-word-edit-panel]').first();
            if (!$panel.length) { return; }
            const isOpen = $panel.attr('aria-hidden') === 'false';
            setEditPanelOpen($item, !isOpen);
            if (!isOpen) {
                setEditStatus($item, '');
            }
        });

        $grids.on('focusin click', '[data-ll-word-categories-field]', function () {
            $(this).addClass('is-expanded');
        });

        $grids.on('input', '[data-ll-word-category-search]', function () {
            const $field = $(this).closest('[data-ll-word-categories-field]');
            $field.addClass('is-expanded');
            updateWordCategoryField($field);
        });

        $grids.on('change', '[data-ll-word-category-sort]', function () {
            const $field = $(this).closest('[data-ll-word-categories-field]');
            $field.addClass('is-expanded');
            updateWordCategoryField($field);
        });

        $grids.on('change', '[data-ll-word-category-input]', function () {
            const $field = $(this).closest('[data-ll-word-categories-field]');
            updateWordCategoryField($field);
        });

        $grids.on('click', '[data-ll-word-edit-backdrop]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            restoreOriginalInputs($item);
            setEditStatus($item, '');
            setWordSaveStatus($item, '', '');
            setEditPanelOpen($item, false);
        });

        $grids.on('click', '[data-ll-word-recordings-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const $panel = $item.find('[data-ll-word-recordings-panel]').first();
            if (!$panel.length) { return; }
            const isOpen = $panel.attr('aria-hidden') === 'false';
            setRecordingsPanelOpen($item, !isOpen);
            if (isOpen === false) {
                window.requestAnimationFrame(function () {
                    initLessonEditProcessingWaveforms($item);
                });
            }
        });

        $grids.on('click', '[data-ll-word-delete-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            $item.find('[data-ll-word-delete-confirm]').first().prop('hidden', false);
            setEditStatus($item, '');
        });

        $grids.on('click', '[data-ll-word-delete-cancel]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            $item.find('[data-ll-word-delete-confirm]').first().prop('hidden', true);
            setEditStatus($item, '');
        });

        $grids.on('click', '[data-ll-word-delete-confirm-action]', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $button = $(this);
            if ($button.prop('disabled')) { return; }

            const $item = $button.closest('.word-item');
            const $grid = $item.closest('[data-ll-word-grid]');
            const wordId = parseInt($item.data('word-id'), 10) || 0;
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            if (!wordId || !ajaxUrl || !editNonce) {
                setEditStatus($item, editMessages.deleteWordError, true);
                return;
            }

            $button.prop('disabled', true);
            setWordSaveBusy($item, true);
            setEditStatus($item, editMessages.deletingWord, false);
            setWordSaveStatus($item, editMessages.deletingWord, 'pending');

            $.post(ajaxUrl, {
                action: 'll_tools_word_grid_delete_word',
                nonce: editNonce,
                word_id: String(wordId),
                wordset_id: String(wordsetId)
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const message = readResponseErrorMessage(response, editMessages.deleteWordError);
                    setEditStatus($item, message, true);
                    setWordSaveStatus($item, message, 'error');
                    return;
                }

                $(document).trigger('lltools:word-grid-word-deleted', [{
                    wordId: wordId,
                    wordsetId: wordsetId,
                    item: $item.get(0),
                    grid: $grid.get(0),
                    data: (response.data && typeof response.data === 'object') ? response.data : {}
                }]);
                setEditPanelOpen($item, false);
                clearMoveWordCache();
                $item.addClass('ll-word-item--deleted');
                window.setTimeout(function () {
                    $item.remove();
                    updateGridLayouts();
                }, 120);
            }).fail(function (jqXHR) {
                const message = readAjaxErrorMessage(jqXHR, editMessages.deleteWordError);
                setEditStatus($item, message, true);
                setWordSaveStatus($item, message, 'error');
            }).always(function () {
                $button.prop('disabled', false);
                setWordSaveBusy($item, false);
            });
        });

        $grids.on('click', '[data-ll-recording-delete-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $recording = $(this).closest('.ll-word-edit-recording');
            $recording.find('[data-ll-recording-move-panel]').first().prop('hidden', true);
            $recording.find('[data-ll-recording-delete-confirm]').first().prop('hidden', false);
            setRecordingMoveStatus($recording, '', '');
        });

        $grids.on('click', '[data-ll-recording-delete-cancel]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $recording = $(this).closest('.ll-word-edit-recording');
            $recording.find('[data-ll-recording-delete-confirm]').first().prop('hidden', true);
        });

        $grids.on('click', '[data-ll-recording-delete-confirm-action]', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $button = $(this);
            if ($button.prop('disabled')) { return; }
            const $recording = $button.closest('.ll-word-edit-recording');
            const $item = $recording.closest('.word-item');
            const $grid = $item.closest('[data-ll-word-grid]');
            const recordingId = parseInt($recording.attr('data-recording-id'), 10) || 0;
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            if (!recordingId || !ajaxUrl || !editNonce) {
                setEditStatus($item, editMessages.deleteRecordingError, true);
                return;
            }

            $button.prop('disabled', true);
            setEditStatus($item, editMessages.deletingRecording, false);
            setWordSaveStatus($item, editMessages.deletingRecording, 'pending');

            $.post(ajaxUrl, {
                action: 'll_tools_word_grid_delete_recording',
                nonce: editNonce,
                recording_id: String(recordingId),
                wordset_id: String(wordsetId)
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const message = readResponseErrorMessage(response, editMessages.deleteRecordingError);
                    setEditStatus($item, message, true);
                    setWordSaveStatus($item, message, 'error');
                    return;
                }

                const data = (response.data && typeof response.data === 'object') ? response.data : {};
                removeRecordingDom($item, recordingId);
                if (data.word_status) {
                    $item.attr('data-ll-word-status', data.word_status.toString());
                }
                $(document).trigger('lltools:word-grid-recording-deleted', [{
                    wordId: parseInt($item.data('word-id'), 10) || 0,
                    wordsetId: wordsetId,
                    recordingId: recordingId,
                    item: $item.get(0),
                    grid: $grid.get(0),
                    data: data
                }]);
                setEditStatus($item, editMessages.recordingDeleted, false);
                setWordSaveStatus($item, editMessages.recordingDeleted, 'success');
                scheduleWordSaveStatusClear($item, 1800);
            }).fail(function (jqXHR) {
                const message = readAjaxErrorMessage(jqXHR, editMessages.deleteRecordingError);
                setEditStatus($item, message, true);
                setWordSaveStatus($item, message, 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        });

        $grids.on('click', '[data-ll-recording-move-toggle]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $recording = $(this).closest('.ll-word-edit-recording');
            const $panel = $recording.find('[data-ll-recording-move-panel]').first();
            $recording.find('[data-ll-recording-delete-confirm]').first().prop('hidden', true);
            $panel.prop('hidden', false);
            setRecordingMoveStatus($recording, '', '');
            const $input = $panel.find('[data-ll-recording-move-search]').first();
            initRecordingMoveAutocomplete($input);
            window.setTimeout(function () {
                $input.trigger('focus');
                if (typeof $input.autocomplete === 'function') {
                    $input.autocomplete('search', ($input.val() || '').toString());
                }
            }, 0);
        });

        $grids.on('input', '[data-ll-recording-move-search]', function () {
            const $input = $(this);
            const typed = ($input.val() || '').toString();
            const selectedLabel = ($input.data('llMoveWordSelectedLabel') || '').toString();
            if (typed.trim() === '' || typed !== selectedLabel) {
                setRecordingMoveTarget($input.closest('[data-ll-recording-move-panel]'), 0, '');
            }
        });

        $grids.on('click', '[data-ll-recording-move-cancel]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $panel = $(this).closest('[data-ll-recording-move-panel]');
            $panel.prop('hidden', true);
            $panel.find('[data-ll-recording-move-search]').val('');
            setRecordingMoveTarget($panel, 0, '');
            setRecordingMoveStatus($panel.closest('.ll-word-edit-recording'), '', '');
        });

        $grids.on('click', '[data-ll-recording-move-confirm]', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $button = $(this);
            if ($button.prop('disabled')) { return; }
            const $recording = $button.closest('.ll-word-edit-recording');
            const $panel = $recording.find('[data-ll-recording-move-panel]').first();
            const $sourceItem = $recording.closest('.word-item');
            const $grid = $sourceItem.closest('[data-ll-word-grid]');
            const recordingId = parseInt($recording.attr('data-recording-id'), 10) || 0;
            const sourceWordId = parseInt($sourceItem.data('word-id'), 10) || 0;
            const targetWordId = parseInt($panel.find('[data-ll-recording-move-target]').val(), 10) || 0;
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            if (!recordingId || !targetWordId || targetWordId === sourceWordId) {
                setRecordingMoveStatus($recording, editMessages.selectMoveTarget, 'error');
                return;
            }

            $button.prop('disabled', true);
            setRecordingMoveStatus($recording, editMessages.movingRecording, '');
            setWordSaveStatus($sourceItem, editMessages.movingRecording, 'pending');

            $.post(ajaxUrl, {
                action: 'll_tools_word_grid_move_recording',
                nonce: editNonce,
                recording_id: String(recordingId),
                source_word_id: String(sourceWordId),
                target_word_id: String(targetWordId),
                wordset_id: String(wordsetId)
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const message = readResponseErrorMessage(response, editMessages.moveRecordingError);
                    setRecordingMoveStatus($recording, message, 'error');
                    setWordSaveStatus($sourceItem, message, 'error');
                    return;
                }

                const data = (response.data && typeof response.data === 'object') ? response.data : {};
                const movedTargetId = parseInt(data.target_word_id, 10) || targetWordId;
                const $targetItem = $grid.find('.word-item[data-word-id="' + movedTargetId + '"]').first();
                const movedInDom = $targetItem.length ? attachMovedRecordingDom($sourceItem, $targetItem, recordingId) : false;
                if (!movedInDom) {
                    removeRecordingDom($sourceItem, recordingId);
                }
                if (data.source_word_status) {
                    $sourceItem.attr('data-ll-word-status', data.source_word_status.toString());
                }
                clearMoveWordCache();
                $(document).trigger('lltools:word-grid-recording-moved', [{
                    sourceWordId: sourceWordId,
                    targetWordId: movedTargetId,
                    wordsetId: wordsetId,
                    recordingId: recordingId,
                    item: $sourceItem.get(0),
                    grid: $grid.get(0),
                    data: data
                }]);
                setWordSaveStatus($sourceItem, editMessages.recordingMoved, 'success');
                scheduleWordSaveStatusClear($sourceItem, 1800);
            }).fail(function (jqXHR) {
                const message = readAjaxErrorMessage(jqXHR, editMessages.moveRecordingError);
                setRecordingMoveStatus($recording, message, 'error');
                setWordSaveStatus($sourceItem, message, 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        });

        $grids.on('change', '[data-ll-processing-option="trim"]', function () {
            const $recording = $(this).closest('.ll-word-edit-recording');
            const state = $recording.data('llProcessingState');
            if (state && state.originalBuffer) {
                resetLessonEditProcessingBounds($recording, state);
                renderLessonEditProcessingWaveform($recording, state);
                stopLessonEditProcessingPlayback($recording);
                return;
            }
            ensureLessonEditProcessingWaveform($recording).catch(function () {});
        });

        $grids.on('click', '[data-ll-processing-play-selection]', async function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(this);
            if ($btn.prop('disabled')) { return; }

            const $recording = $btn.closest('.ll-word-edit-recording');
            $btn.prop('disabled', true);
            try {
                await playLessonEditProcessingSelection($recording);
            } catch (error) {
                setLessonEditProcessingStatus($recording, editMessages.processAudioError, 'error');
            } finally {
                $btn.prop('disabled', false);
            }
        });

        $grids.on('click', '[data-ll-process-recording-audio]', async function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $btn = $(this);
            if ($btn.prop('disabled')) { return; }

            const $recording = $btn.closest('.ll-word-edit-recording');
            const $item = $recording.closest('.word-item');
            const $grid = $item.closest('[data-ll-word-grid]');
            const recordingId = parseInt($recording.attr('data-recording-id'), 10) || 0;
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            const recordingType = ($recording.attr('data-recording-type') || '').toString();
            if (!recordingId || !ajaxUrl || !editNonce || !window.FormData) {
                setLessonEditProcessingStatus($recording, editMessages.processAudioError, 'error');
                return;
            }

            $btn.prop('disabled', true);
            setLessonEditProcessingStatus($recording, editMessages.processingAudio, '');

            try {
                const processed = await buildLessonEditProcessedAudio($recording);
                const formData = new window.FormData();
                formData.append('action', 'll_tools_word_grid_process_recording_audio');
                formData.append('nonce', editNonce);
                formData.append('recording_id', String(recordingId));
                formData.append('wordset_id', String(wordsetId));
                formData.append('recording_type', recordingType);
                formData.append('audio', processed.audioBlob, 'lesson-recording-' + recordingId + '.wav');
                formData.append('trim_start', String(Math.max(0, parseInt(processed.trimStart, 10) || 0)));
                formData.append('trim_end', String(Math.max(0, parseInt(processed.trimEnd, 10) || 0)));
                formData.append('source_samples', String(Math.max(0, parseInt(processed.sourceSamples, 10) || 0)));
                formData.append('sample_rate', String(Math.max(0, parseInt(processed.sampleRate, 10) || 0)));
                formData.append('enable_trim', (processed.options.enableTrim || processed.usedManualTrim) ? '1' : '0');
                formData.append('enable_noise', processed.options.enableNoise ? '1' : '0');
                formData.append('enable_loudness', processed.options.enableLoudness ? '1' : '0');
                formData.append('used_original_source', ($recording.attr('data-ll-uses-original-audio') || '') === '1' ? '1' : '0');

                const response = await postLessonEditProcessedAudio(formData);
                if (!response || response.success !== true) {
                    throw new Error(readResponseErrorMessage(response, editMessages.processAudioError));
                }

                const data = (response.data && typeof response.data === 'object') ? response.data : {};
                updateLessonEditRecordingAudio($item, recordingId, data);
                setLessonEditProcessingStatus($recording, editMessages.processedAudio, 'success');
            } catch (error) {
                const message = error && error.message ? error.message : editMessages.processAudioError;
                setLessonEditProcessingStatus($recording, message, 'error');
            } finally {
                $btn.prop('disabled', false);
            }
        });

        $grids.on('click', '.ll-word-recording-row', function (e) {
            const $row = $(this);
            if (!$row.hasClass('ll-word-recording-row--editable')) {
                return;
            }
            if ($(e.target).closest('[data-ll-recording-edit-trigger]').length) {
                return;
            }
            if ($(e.target).closest('.ll-study-recording-btn, .ll-word-recording-text').length) {
                revealRecordingRowEditTrigger($row);
            }
        });

        $grids.on('click', '[data-ll-recording-edit-trigger]', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $row = $(this).closest('.ll-word-recording-row');
            const $item = $row.closest('.word-item');
            const recId = parseInt($(this).attr('data-recording-id'), 10)
                || parseInt($row.attr('data-recording-id'), 10)
                || 0;

            openRecordingEditor($item, recId);
        });

        $(document).on('click.llWordGridRecordingEdit', function (event) {
            if ($(event.target).closest('.ll-word-recording-row--editable, .ll-word-edit-panel').length) {
                return;
            }
            clearVisibleRecordingRowEditTriggers();
        });

        $grids.on('click', '[data-ll-inline-word-trigger]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const fieldName = ($(this).attr('data-ll-inline-word-trigger') || '').toString();
            if (!fieldName) { return; }
            openInlineWordEditor($item, fieldName);
        });

        $grids.on('click', '[data-ll-inline-word-cancel]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $editor = $(this).closest('[data-ll-inline-word-editor]');
            const fieldName = ($editor.attr('data-ll-inline-word-editor') || '').toString();
            if (!fieldName) { return; }
            const $item = $editor.closest('.word-item');
            setWordSaveStatus($item, '', '');
            closeInlineWordEditor($item, fieldName, true, true);
        });

        $grids.on('keydown', '[data-ll-inline-word-input]', function (event) {
            const $editor = $(this).closest('[data-ll-inline-word-editor]');
            const fieldName = ($editor.attr('data-ll-inline-word-editor') || '').toString();
            if (!fieldName) { return; }
            const $item = $editor.closest('.word-item');

            if (event.key === 'Escape') {
                event.preventDefault();
                setWordSaveStatus($item, '', '');
                closeInlineWordEditor($item, fieldName, true, true);
                return;
            }

            if (event.key === 'Enter') {
                event.preventDefault();
                $editor.find('[data-ll-inline-word-save]').first().trigger('click');
            }
        });

        $grids.on('click', '[data-ll-internal-review-note-summary]', function () {
            const $wrap = $(this).closest('[data-ll-internal-review-note]');
            window.setTimeout(function () {
                if ($wrap.prop('open')) {
                    $wrap.find('[data-ll-internal-review-note-input]').first().trigger('focus');
                }
            }, 0);
        });

        $grids.on('input', '[data-ll-internal-review-note-input]', function () {
            const $input = $(this);
            getInternalNoteOriginalValue($input);
            scheduleInternalReviewNoteSave($input.closest('[data-ll-internal-review-note]'));
        });

        $grids.on('blur change', '[data-ll-internal-review-note-input]', function () {
            saveInternalReviewNote($(this).closest('[data-ll-internal-review-note]'));
        });

        $grids.on('click', '[data-ll-inline-word-save]', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $editor = $(this).closest('[data-ll-inline-word-editor]');
            const fieldName = ($editor.attr('data-ll-inline-word-editor') || '').toString();
            if (!fieldName || $editor.hasClass('is-saving')) { return; }

            const $item = $editor.closest('.word-item');
            const wordId = parseInt($item.data('word-id'), 10) || 0;
            const $grid = $item.closest('[data-ll-word-grid]');
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            if (!wordId || !ajaxUrl || !editNonce) {
                setWordSaveStatus($item, editMessages.error, 'error');
                return;
            }

            const editedValue = ($editor.find('[data-ll-inline-word-input]').first().val() || '').toString();
            let wordText = getInlineWordSavedValue($item, 'word');
            let wordTranslation = getInlineWordSavedValue($item, 'translation');

            if (fieldName === 'translation') {
                wordTranslation = editedValue;
            } else {
                wordText = editedValue;
            }

            setInlineWordEditorSaving($item, fieldName, true);
            setWordSaveBusy($item, true);
            setWordSaveStatus($item, editMessages.saving, 'pending');

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'll_tools_word_grid_update_word',
                    nonce: editNonce,
                    word_id: String(wordId),
                    word_text: wordText,
                    word_translation: wordTranslation,
                    wordset_id: String(wordsetId)
                }
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const message = readResponseErrorMessage(response, editMessages.error);
                    setWordSaveStatus($item, message, 'error');
                    return;
                }

                const data = (response.data && typeof response.data === 'object') ? response.data : {};
                if (typeof data.word_text === 'string') {
                    $item.find('[data-ll-word-text]').attr('dir', 'auto').text(protectMaqafNoBreak(data.word_text));
                    $item.find('[data-ll-word-input="word"]').val(data.word_text);
                    syncInlineWordEditorValue($item, 'word', data.word_text);
                }
                if (typeof data.word_translation === 'string') {
                    $item.find('[data-ll-word-translation]').attr('dir', 'auto').text(protectMaqafNoBreak(data.word_translation));
                    $item.find('[data-ll-word-input="translation"]').val(data.word_translation);
                    syncInlineWordEditorValue($item, 'translation', data.word_translation);
                }
                updateGridLayouts();
                updateOriginalInputs($item);
                closeInlineWordEditor($item, fieldName, false, true);
                setWordSaveStatus($item, editMessages.saved, 'success');
                scheduleWordSaveStatusClear($item, 1800);
            }).fail(function (jqXHR) {
                const message = readAjaxErrorMessage(jqXHR, editMessages.error);
                setWordSaveStatus($item, message, 'error');
            }).always(function () {
                setInlineWordEditorSaving($item, fieldName, false);
                setWordSaveBusy($item, false);
            });
        });

        $grids.on('change', '[data-ll-word-input="part_of_speech"]', function () {
            const $item = $(this).closest('.word-item');
            const posSlug = ($(this).val() || '').toString();
            setMetaFieldState($item, posSlug);
        });

        $grids.on('change', '[data-ll-word-image-input]', function () {
            const $input = $(this);
            const $item = $input.closest('.word-item');
            const inputEl = $input.get(0);
            const file = inputEl && inputEl.files && inputEl.files[0] ? inputEl.files[0] : null;

            revokePendingImagePreviewUrl($item);
            if (!file) {
                const originalState = normalizeWordImageData($item.data('llWordImageState') || {});
                setWordEditImagePreview($item, originalState);
                restoreWordImagePickerSelection($item);
                $item.find('[data-ll-word-image-selected]').first().text('');
                return;
            }

            clearWordImagePickerSelection($item);
            if (window.URL && typeof window.URL.createObjectURL === 'function') {
                const previewUrl = window.URL.createObjectURL(file);
                $item.data('llWordImagePreviewUrl', previewUrl);
                setWordEditImagePreview($item, {
                    url: previewUrl,
                    alt: (file.name || '').toString()
                });
            }

            $item.find('[data-ll-word-image-selected]').first().text((file.name || '').toString());
        });

        $grids.on('focus', '[data-ll-word-image-existing-search]', function () {
            const $input = $(this);
            initWordImageAutocomplete($input);
            if (typeof $input.autocomplete === 'function') {
                $input.autocomplete('search', ($input.val() || '').toString());
            }
        });

        $grids.on('input', '[data-ll-word-image-existing-search]', function () {
            const $input = $(this);
            const $item = $input.closest('.word-item');
            const typed = ($input.val() || '').toString();
            const selectedLabel = ($input.data('llWordImageSelectedLabel') || '').toString();
            if (typed.trim() === '') {
                $item.find('[data-ll-word-image-existing-id]').first().val('');
                $input.data('llWordImageSelectedId', 0);
                $input.data('llWordImageSelectedLabel', '');
                return;
            }
            if (selectedLabel && typed !== selectedLabel) {
                $item.find('[data-ll-word-image-existing-id]').first().val('');
            }
        });

        $grids.on('focus', '[data-ll-word-input="dictionary_entry_lookup"]', function () {
            const $input = $(this);
            initDictionaryEntryAutocomplete($input);
            if (typeof $input.autocomplete === 'function') {
                $input.autocomplete('search', ($input.val() || '').toString());
            }
        });

        $grids.on('input', '[data-ll-word-input="dictionary_entry_lookup"]', function () {
            const $input = $(this);
            const $item = $input.closest('.word-item');
            const typed = ($input.val() || '').toString();
            const selectedLabel = ($input.data('llEntrySelectedLabel') || '').toString();
            if (typed.trim() === '') {
                $item.find('[data-ll-word-input="dictionary_entry_id"]').val('');
                $input.data('llEntrySelectedId', 0);
                $input.data('llEntrySelectedLabel', '');
                return;
            }
            if (selectedLabel && typed !== selectedLabel) {
                $item.find('[data-ll-word-input="dictionary_entry_id"]').val('');
            }
        });

        $grids.on('focus', '.ll-word-edit-input--ipa', function () {
            showIpaKeyboard($(this), { centerInput: true });
            updateIpaSelection(this);
        });

        $grids.on('click keyup mouseup', '.ll-word-edit-input--ipa', function () {
            updateIpaSelection(this);
        });

        $grids.on('select touchend', '.ll-word-edit-input--ipa', function () {
            updateIpaSelection(this);
        });

        $grids.on('keydown', '.ll-word-edit-input--ipa', function (event) {
            if (!event || !event.key) { return; }
            if (event.key === 'Backspace' || event.key === 'Delete') {
                setLastIpaEdit(this, 'delete');
            } else if (event.key.length === 1) {
                setLastIpaEdit(this, 'insert');
            } else {
                setLastIpaEdit(this, null);
            }
        });

        $grids.on('input', '.ll-word-edit-input--ipa', function (event) {
            updateLastIpaEditFromInputEvent(event, this);
            const $input = $(this);
            const raw = ($input.val() || '').toString();
            const sanitized = sanitizeIpaValue(raw);
            if (raw !== sanitized) {
                $input.val(sanitized);
            }
            const newChars = extractIpaSpecialChars(sanitized);
            const updated = mergeIpaSpecialChars(newChars);
            if (updated || activeIpaInput === this) {
                showIpaKeyboard($input);
            }
            updateIpaSelection(this);
        });

        $grids.on('input', '[data-ll-recording-input="text"]', function () {
            const $recording = $(this).closest('.ll-word-edit-recording');
            const ipaInput = $recording.find('.ll-word-edit-input--ipa').get(0);
            if (ipaInput && ipaInput === activeIpaInput) {
                refreshIpaKeyboardForInput(ipaInput);
            }
        });

        $grids.on('click', '[data-ll-recording-review-toggle]', function () {
            const $btn = $(this);
            const field = ($btn.attr('data-review-field') || '').toString();
            const $recording = $btn.closest('.ll-word-edit-recording');
            if (!$recording.length || (field !== 'recording_text' && field !== 'recording_ipa')) {
                return;
            }
            const nextActive = ($btn.attr('aria-pressed') || 'false') !== 'true';
            setRecordingReviewToggle($recording, field, nextActive);
            const fields = getRecordingReviewFields($recording);
            if (!fields.recording_text && !fields.recording_ipa) {
                $recording.find('[data-ll-recording-review-note]').remove();
            }
        });

        document.addEventListener('selectionchange', function () {
            if (!activeIpaInput) { return; }
            if (document.activeElement !== activeIpaInput) { return; }
            updateIpaSelection(activeIpaInput);
        });

        $grids.on('mousedown', '[data-ll-ipa-superscript]', function (e) {
            e.preventDefault();
        });

        $grids.on('click', '[data-ll-ipa-superscript]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('.ll-word-edit-input-wrap--ipa');
            const input = $wrap.find('.ll-word-edit-input--ipa').get(0) || activeIpaInput;
            if (!input) { return; }
            applySuperscriptToSelection(input);
            input.focus();
        });

        $grids.on('click', '[data-ll-ipa-shift]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if ($(this).prop('disabled')) { return; }
            const $recording = $(this).closest('.ll-word-edit-recording');
            const input = $recording.find('.ll-word-edit-input--ipa').get(0) || activeIpaInput;
            if (!input) { return; }
            activeIpaInput = input;
            const $input = $(input);
            const state = getIpaSuggestionState($input);
            if (!state.letters.length || state.baseIndex < 0) { return; }
            let offset = Number.isFinite(state.offset) ? state.offset : 0;
            const direction = ($(this).attr('data-ll-ipa-shift') || '').toString();
            if (direction === 'prev') {
                offset -= 1;
            } else if (direction === 'next') {
                offset += 1;
            } else {
                return;
            }
            const shift = clampIpaShift(state.baseIndex, offset, state.letters.length);
            setIpaShiftOffset($recording, shift.offset);
            refreshIpaKeyboardForInput(input);
            input.focus();
        });

        $grids.on('click', '.ll-word-ipa-key', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const ch = $(this).attr('data-ipa-char') || $(this).text();
            if (!activeIpaInput || !ch) { return; }
            insertIpaChar(activeIpaInput, ch);
            activeIpaInput.focus();
        });

        $(document).on('click.llWordGridIpa', function (e) {
            if ($(e.target).closest('.ll-word-edit-input--ipa, .ll-word-edit-ipa-keyboard, .ll-word-edit-ipa-suggestions, .ll-word-edit-ipa-target, .ll-word-edit-ipa-superscript').length) {
                return;
            }
            if (activeIpaInput && $(document.activeElement).closest('.ll-word-edit-input--ipa').length) {
                return;
            }
            hideIpaKeyboards();
        });

        $(document).on('keydown.llWordEditModal', function (event) {
            if (!event || event.key !== 'Escape') { return; }
            if ($('.ui-autocomplete:visible').length) { return; }
            const $openPanel = $grids.find('[data-ll-word-edit-panel][aria-hidden="false"]').first();
            if (!$openPanel.length) { return; }
            event.preventDefault();
            const $item = $openPanel.closest('.word-item');
            restoreOriginalInputs($item);
            setEditStatus($item, '');
            setWordSaveStatus($item, '', '');
            setEditPanelOpen($item, false);
        });

        $grids.on('click', '[data-ll-word-edit-cancel]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            restoreOriginalInputs($item);
            setEditStatus($item, '');
            setWordSaveStatus($item, '', '');
            setEditPanelOpen($item, false);
        });

        $grids.on('click', '[data-ll-word-edit-save]', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.word-item');
            const wordId = parseInt($item.data('word-id'), 10) || 0;
            if (!wordId || $item.hasClass('ll-word-save-pending')) { return; }

            const wordText = ($item.find('[data-ll-word-input="word"]').val() || '').toString();
            const wordTranslation = ($item.find('[data-ll-word-input="translation"]').val() || '').toString();
            const wordNote = ($item.find('[data-ll-word-input="note"]').val() || '').toString();
            const partOfSpeech = ($item.find('[data-ll-word-input="part_of_speech"]').val() || '').toString();
            const partOfSpeechLabel = partOfSpeech
                ? (($item.find('[data-ll-word-input="part_of_speech"] option:selected').text() || '').toString().trim())
                : '';
            const gender = ($item.find('[data-ll-word-input="gender"]').val() || '').toString();
            const genderLabel = gender
                ? (($item.find('[data-ll-word-input="gender"] option:selected').text() || '').toString().trim())
                : '';
            const plurality = ($item.find('[data-ll-word-input="plurality"]').val() || '').toString();
            const pluralityLabel = plurality
                ? (($item.find('[data-ll-word-input="plurality"] option:selected').text() || '').toString().trim())
                : '';
            const verbTense = ($item.find('[data-ll-word-input="verb_tense"]').val() || '').toString();
            const verbTenseLabel = verbTense
                ? (($item.find('[data-ll-word-input="verb_tense"] option:selected').text() || '').toString().trim())
                : '';
            const verbMood = ($item.find('[data-ll-word-input="verb_mood"]').val() || '').toString();
            const verbMoodLabel = verbMood
                ? (($item.find('[data-ll-word-input="verb_mood"] option:selected').text() || '').toString().trim())
                : '';
            const dictionaryEntryId = parseInt($item.find('[data-ll-word-input="dictionary_entry_id"]').val(), 10) || 0;
            const dictionaryEntryTitle = ($item.find('[data-ll-word-input="dictionary_entry_lookup"]').val() || '').toString();
            const $wrongAnswerTextsInput = $item.find('[data-ll-word-input="specific_wrong_answer_texts"]').first();
            const $imageCopyrightInput = $item.find('[data-ll-word-image-copyright]').first();
            const imageCopyright = $imageCopyrightInput.length ? ($imageCopyrightInput.val() || '').toString() : '';
            const $grid = $item.closest('[data-ll-word-grid]');
            const wordsetId = parseInt($grid.attr('data-ll-wordset-id'), 10) || 0;
            const lessonCategoryId = parseInt($grid.attr('data-ll-category-id'), 10) || 0;
            const $categoryInputs = $item.find('[data-ll-word-category-input]');
            const categoryIds = $categoryInputs.length ? getSelectedCategoryIds($item) : [];
            const imageInputEl = $item.find('[data-ll-word-image-input]').get(0);
            const imageFile = imageInputEl && imageInputEl.files && imageInputEl.files[0] ? imageInputEl.files[0] : null;
            const selectedWordImageId = imageFile
                ? 0
                : (parseInt($item.find('[data-ll-word-image-existing-id]').first().val(), 10) || 0);
            const recordings = [];

            $item.find('.ll-word-edit-recording[data-recording-id]').each(function () {
                const $rec = $(this);
                const recId = parseInt($rec.attr('data-recording-id'), 10) || 0;
                if (!recId) { return; }
                const text = ($rec.find('[data-ll-recording-input="text"]').val() || '').toString();
                const translation = ($rec.find('[data-ll-recording-input="translation"]').val() || '').toString();
                const ipa = normalizeIpaForStorage(($rec.find('[data-ll-recording-input="ipa"]').val() || '').toString());
                recordings.push({ id: recId, text: text, translation: translation, ipa: ipa, review_fields: getRecordingReviewFields($rec) });
            });

            const $saveBtn = $(this);
            const $cancelBtn = $item.find('[data-ll-word-edit-cancel]');
            $saveBtn.prop('disabled', true);
            $cancelBtn.prop('disabled', true);
            setEditStatus($item, editMessages.saving, false);

            if (typeof wordText === 'string') {
                $item.find('[data-ll-word-text]').attr('dir', 'auto').text(protectMaqafNoBreak(wordText));
            }
            if (typeof wordTranslation === 'string') {
                $item.find('[data-ll-word-translation]').attr('dir', 'auto').text(protectMaqafNoBreak(wordTranslation));
            }
            setWordNote($item, wordNote);
            applyDictionaryEntryData($item, {
                id: dictionaryEntryId,
                title: dictionaryEntryTitle
            });
            applyPosMetaUpdate(
                $item,
                { slug: partOfSpeech, label: partOfSpeechLabel },
                { value: gender, label: genderLabel },
                { value: plurality, label: pluralityLabel },
                { value: verbTense, label: verbTenseLabel },
                { value: verbMood, label: verbMoodLabel }
            );
            if (recordings.length) {
                applyRecordingCaptions($item, recordings.map(function (entry) {
                    return {
                        id: entry.id,
                        recording_text: entry.text,
                        recording_translation: entry.translation,
                        recording_ipa: normalizeIpaOutput(entry.ipa),
                        review_fields: entry.review_fields
                    };
                }));
            }
            updateGridLayouts();
            setRecordingsPanelOpen($item, true);
            setEditPanelOpen($item, false);
            setEditStatus($item, '', false);
            setWordSaveBusy($item, true);
            setWordSaveStatus($item, editMessages.savingBackground, 'pending');

            const requestData = new window.FormData();
            requestData.append('action', 'll_tools_word_grid_update_word');
            requestData.append('nonce', editNonce);
            requestData.append('word_id', String(wordId));
            requestData.append('word_text', wordText);
            requestData.append('word_translation', wordTranslation);
            requestData.append('word_note', wordNote);
            requestData.append('part_of_speech', partOfSpeech);
            requestData.append('grammatical_gender', gender);
            requestData.append('grammatical_plurality', plurality);
            requestData.append('verb_tense', verbTense);
            requestData.append('verb_mood', verbMood);
            requestData.append('dictionary_entry_id', String(dictionaryEntryId));
            requestData.append('dictionary_entry_title', dictionaryEntryTitle);
            if ($imageCopyrightInput.length) {
                requestData.append('image_copyright', imageCopyright);
            }
            requestData.append('wordset_id', String(wordsetId));
            requestData.append('lesson_category_id', String(lessonCategoryId));
            requestData.append('recordings', JSON.stringify(recordings));
            if ($categoryInputs.length) {
                requestData.append('category_ids_submitted', '1');
                categoryIds.forEach(function (categoryId) {
                    requestData.append('category_ids[]', String(categoryId));
                });
            }
            if ($wrongAnswerTextsInput.length) {
                requestData.append('specific_wrong_answer_texts', ($wrongAnswerTextsInput.val() || '').toString());
            }
            if (imageFile) {
                requestData.append('word_image_file', imageFile, (imageFile.name || 'image').toString());
            } else if (selectedWordImageId > 0) {
                requestData.append('existing_word_image_id', String(selectedWordImageId));
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: requestData,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function (response) {
                if (!response || response.success !== true) {
                    const message = readResponseErrorMessage(response, editMessages.error);
                    setWordSaveStatus($item, message, 'error');
                    setEditStatus($item, message, true);
                    setEditPanelOpen($item, true);
                    return;
                }
                const data = response.data || {};
                if (typeof data.word_text === 'string') {
                    $item.find('[data-ll-word-text]').attr('dir', 'auto').text(protectMaqafNoBreak(data.word_text));
                    $item.find('[data-ll-word-input="word"]').val(data.word_text);
                    syncInlineWordEditorValue($item, 'word', data.word_text);
                }
                if (typeof data.word_translation === 'string') {
                    $item.find('[data-ll-word-translation]').attr('dir', 'auto').text(protectMaqafNoBreak(data.word_translation));
                    $item.find('[data-ll-word-input="translation"]').val(data.word_translation);
                    syncInlineWordEditorValue($item, 'translation', data.word_translation);
                }
                if (typeof data.word_note === 'string') {
                    $item.find('[data-ll-word-input="note"]').val(data.word_note);
                    setWordNote($item, data.word_note);
                }
                if (Array.isArray(data.specific_wrong_answer_texts) && $wrongAnswerTextsInput.length) {
                    $wrongAnswerTextsInput.val(data.specific_wrong_answer_texts.join('\n'));
                }
                if (data.image && typeof data.image === 'object') {
                    applyWordImageData($item, data.image);
                }
                if (data.dictionary_entry) {
                    applyDictionaryEntryData($item, data.dictionary_entry);
                }
                if (data.categories && Array.isArray(data.categories.ids)) {
                    applyCategorySelection($item, data.categories.ids);
                }
                if (Array.isArray(data.recordings)) {
                    data.recordings.forEach(function (rec) {
                        const recId = parseInt(rec.id, 10) || 0;
                        if (!recId) { return; }
                        const $rec = $item.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]');
                        if (!$rec.length) { return; }
                        if (typeof rec.recording_text === 'string') {
                            $rec.find('[data-ll-recording-input="text"]').val(rec.recording_text);
                        }
                        if (typeof rec.recording_translation === 'string') {
                            $rec.find('[data-ll-recording-input="translation"]').val(rec.recording_translation);
                        }
                        if (typeof rec.recording_ipa === 'string') {
                            $rec.find('[data-ll-recording-input="ipa"]').val(rec.recording_ipa);
                        }
                        if (rec.review_fields) {
                            applyRecordingReviewFields($rec, rec.review_fields, rec.review_note || '');
                        }
                    });
                    applyRecordingCaptions($item, data.recordings);
                }
                if (data.part_of_speech || data.grammatical_gender || data.grammatical_plurality || data.verb_tense || data.verb_mood) {
                    applyPosMetaUpdate($item, data.part_of_speech || {}, data.grammatical_gender || {}, data.grammatical_plurality || {}, data.verb_tense || {}, data.verb_mood || {});
                }
                $(document).trigger('lltools:word-grid-word-updated', [{
                    wordId: wordId,
                    data: data,
                    item: $item.get(0),
                    grid: $grid.get(0)
                }]);
                updateGridLayouts();
                if (data.lesson_visible === false) {
                    $item.remove();
                    updateGridLayouts();
                    return;
                }
                updateOriginalInputs($item);
                const $bulkWrap = $item.closest('[data-ll-vocab-lesson],.ll-vocab-lesson-page').find('[data-ll-word-grid-bulk]').first();
                if ($bulkWrap.length) {
                    clearAllBulkControlUndoSnapshots($bulkWrap);
                    syncBulkControlSelectDefaults($bulkWrap);
                }
                setEditStatus($item, '');
                setRecordingsPanelOpen($item, true);
                setEditPanelOpen($item, false);
                setWordSaveStatus($item, editMessages.saved, 'success');
                scheduleWordSaveStatusClear($item, 1800);
            }).fail(function (jqXHR) {
                const message = readAjaxErrorMessage(jqXHR, editMessages.error);
                setWordSaveStatus($item, message, 'error');
                setEditStatus($item, message, true);
                setEditPanelOpen($item, true);
            }).always(function () {
                $saveBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                setWordSaveBusy($item, false);
            });
        });
    }

    if (canEdit && ajaxUrl && editNonce) {
        const transcribeMessages = Object.assign({
            confirm: '',
            confirmReplace: '',
            confirmClear: '',
            working: 'Transcribing...',
            progress: 'Transcribing %1$d of %2$d...',
            done: 'Transcription complete.',
            none: 'No recordings need transcription.',
            clearing: 'Clearing transcription...',
            cleared: 'Transcription cleared.',
            cancelled: 'Transcription cancelled.',
            error: 'Unable to transcribe recordings.',
            localServiceError: 'Unable to reach the local transcription service.',
            localAudioError: 'Unable to fetch the recording audio in this browser.'
        }, transcribeI18n || {});

        function applyRecordingUpdate(rec) {
            if (!rec) { return; }
            const wordId = parseInt(rec.word_id, 10) || 0;
            const recId = parseInt(rec.id, 10) || 0;
            if (!wordId || !recId) { return; }
            const $item = $grids.find('.word-item[data-word-id="' + wordId + '"]').first();
            if (!$item.length) { return; }
            const $rec = $item.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]');
            if ($rec.length) {
                if (typeof rec.recording_text === 'string') {
                    $rec.find('[data-ll-recording-input="text"]').val(rec.recording_text);
                }
                if (typeof rec.recording_translation === 'string') {
                    $rec.find('[data-ll-recording-input="translation"]').val(rec.recording_translation);
                }
                if (typeof rec.recording_ipa === 'string') {
                    $rec.find('[data-ll-recording-input="ipa"]').val(rec.recording_ipa);
                }
                if (rec.review_fields) {
                    applyRecordingReviewFields($rec, rec.review_fields, rec.review_note || '');
                }
                const recordings = collectRecordingInputs($item);
                applyRecordingCaptions($item, recordings);
                updateOriginalInputs($item);
            } else {
                const $row = $item.find('.ll-word-recording-row[data-recording-id="' + recId + '"]');
                if ($row.length) {
                    const caption = getRecordingCaptionParts(rec.recording_text, rec.recording_translation, rec.recording_ipa, rec.review_fields);
                    renderRecordingCaption($row, caption);
                }
            }
            updateRecordingRowWidths();
        }

        function applyWordUpdate(word) {
            if (!word) { return; }
            const wordId = parseInt(word.id || word.word_id, 10) || 0;
            if (!wordId) { return; }
            const $item = $grids.find('.word-item[data-word-id="' + wordId + '"]').first();
            if (!$item.length) { return; }
            if (typeof word.word_text === 'string') {
                $item.find('[data-ll-word-text]').attr('dir', 'auto').text(protectMaqafNoBreak(word.word_text));
                $item.find('[data-ll-word-input="word"]').val(word.word_text);
                syncInlineWordEditorValue($item, 'word', word.word_text);
            }
            if (typeof word.word_translation === 'string') {
                $item.find('[data-ll-word-translation]').attr('dir', 'auto').text(protectMaqafNoBreak(word.word_translation));
                $item.find('[data-ll-word-input="translation"]').val(word.word_translation);
                syncInlineWordEditorValue($item, 'translation', word.word_translation);
            }
            if (word.dictionary_entry) {
                applyDictionaryEntryData($item, word.dictionary_entry);
            }
            if (word.part_of_speech || word.grammatical_gender || word.grammatical_plurality || word.verb_tense || word.verb_mood) {
                applyPosMetaUpdate($item, word.part_of_speech || {}, word.grammatical_gender || {}, word.grammatical_plurality || {}, word.verb_tense || {}, word.verb_mood || {});
            }
            updateGridLayouts();
            updateOriginalInputs($item);
        }

        function getTranscribeState($wrap) {
            let state = $wrap.data('llTranscribeState');
            if (!state) {
                state = {
                    active: false,
                    cancelled: false,
                    request: null,
                    pollTimer: null,
                    localAbortController: null,
                    queue: [],
                    total: 0,
                    completed: 0,
                    hadError: false,
                    force: false
                };
                $wrap.data('llTranscribeState', state);
            }
            return state;
        }

        function setTranscribeMenuOpen($wrap, shouldOpen) {
            if (!$wrap || !$wrap.length) { return; }
            const $menu = $wrap.find('[data-ll-transcribe-menu]').first();
            const $trigger = $wrap.find('[data-ll-transcribe-menu-trigger]').first();
            if (!$menu.length || !$trigger.length) { return; }

            const open = !!shouldOpen;
            $menu.attr('aria-hidden', open ? 'false' : 'true');
            $trigger.attr('aria-expanded', open ? 'true' : 'false');
            $wrap.toggleClass('is-open', open);
        }

        function closeTranscribeMenus(except) {
            $('[data-ll-transcribe-wrapper]').each(function () {
                const $wrap = $(this);
                if (except && $wrap.is(except)) { return; }
                setTranscribeMenuOpen($wrap, false);
            });
        }

        function setTranscribeControls($wrap, isActive) {
            $wrap.find('[data-ll-transcribe-recordings]').prop('disabled', isActive);
            $wrap.find('[data-ll-transcribe-replace]').prop('disabled', isActive);
            $wrap.find('[data-ll-transcribe-clear]').prop('disabled', isActive);
            $wrap.find('[data-ll-transcribe-cancel]').prop('disabled', !isActive);
        }

        function finishTranscribe($wrap, message, isError) {
            const state = getTranscribeState($wrap);
            state.active = false;
            state.cancelled = false;
            if (state.pollTimer) {
                window.clearTimeout(state.pollTimer);
                state.pollTimer = null;
            }
            if (state.localAbortController) {
                state.localAbortController.abort();
                state.localAbortController = null;
            }
            state.request = null;
            state.queue = [];
            state.total = 0;
            state.completed = 0;
            state.hadError = false;
            state.force = false;
            setTranscribeControls($wrap, false);
            $wrap.removeAttr('aria-busy');
            if (typeof message === 'string') {
                setTranscribeStatus($wrap, message, !!isError);
            }
        }

        function clearRecordingMetaById(recId) {
            const $rec = $grids.find('.ll-word-edit-recording[data-recording-id="' + recId + '"]');
            if ($rec.length) {
                const $item = $rec.closest('.word-item');
                if (transcribeTargetField === 'recording_ipa') {
                    $rec.find('[data-ll-recording-input="ipa"]').val('');
                } else {
                    $rec.find('[data-ll-recording-input="text"]').val('');
                    $rec.find('[data-ll-recording-input="translation"]').val('');
                }
                const recordings = collectRecordingInputs($item);
                applyRecordingCaptions($item, recordings);
                updateOriginalInputs($item);
                updateRecordingRowWidths();
                return;
            }
            const $row = $grids.find('.ll-word-recording-row[data-recording-id="' + recId + '"]');
            if ($row.length) {
                const text = $row.find('.ll-word-recording-text-main').text() || '';
                const translation = $row.find('.ll-word-recording-text-translation').text() || '';
                const ipa = $row.find('.ll-word-recording-ipa').text() || '';
                const reviewFields = {
                    recording_text: $row.find('.ll-word-recording-text-main--needs-review').length > 0,
                    recording_ipa: $row.find('.ll-word-recording-ipa--needs-review').length > 0
                };
                renderRecordingCaption(
                    $row,
                    getRecordingCaptionParts(
                        transcribeTargetField === 'recording_text' ? '' : text,
                        transcribeTargetField === 'recording_text' ? '' : translation,
                        transcribeTargetField === 'recording_ipa' ? '' : ipa,
                        reviewFields
                    )
                );
                updateRecordingRowWidths();
            }
        }

        function applyClearedRecordings(clearedIds) {
            if (!Array.isArray(clearedIds)) { return; }
            clearedIds.forEach(function (recId) {
                const id = parseInt(recId, 10) || 0;
                if (!id) { return; }
                clearRecordingMetaById(id);
            });
        }

        function extractLocalTranscriptValue(payload) {
            if (!payload || typeof payload !== 'object') {
                return '';
            }

            const targetKeys = transcribeTargetField === 'recording_ipa'
                ? ['predicted_ipa', 'recording_ipa', 'ipa', 'secondary_text', 'transcript', 'text']
                : ['transcript', 'text', 'recording_text', 'predicted_text', 'prediction'];

            for (let i = 0; i < targetKeys.length; i += 1) {
                const key = targetKeys[i];
                if (typeof payload[key] === 'string' && payload[key].trim()) {
                    return payload[key].trim();
                }
            }

            return '';
        }

        async function transcribeRecordingWithLocalService(queueItem, signal) {
            const audioUrl = typeof queueItem.audio_url === 'string' ? queueItem.audio_url : '';
            if (!audioUrl || !transcribeLocalEndpoint) {
                throw new Error('local_audio_missing');
            }

            let audioResponse;
            try {
                audioResponse = await window.fetch(audioUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal
                });
            } catch (err) {
                throw new Error('local_audio_fetch');
            }

            if (!audioResponse || !audioResponse.ok) {
                throw new Error('local_audio_fetch');
            }

            const audioBlob = await audioResponse.blob();
            const fileName = (typeof queueItem.audio_filename === 'string' && queueItem.audio_filename)
                ? queueItem.audio_filename
                : ('recording-' + (parseInt(queueItem.recording_id, 10) || 0) + '.wav');

            const formData = new window.FormData();
            formData.append('audio', audioBlob, fileName);
            formData.append('target_field', transcribeTargetField);
            if (queueItem.recording_id) {
                formData.append('recording_id', String(queueItem.recording_id));
            }
            if (queueItem.word_id) {
                formData.append('word_id', String(queueItem.word_id));
            }
            if (queueItem.word_title) {
                formData.append('word_title', String(queueItem.word_title));
            }
            if (queueItem.recording_type) {
                formData.append('recording_type', String(queueItem.recording_type));
            }

            let localResponse;
            try {
                localResponse = await window.fetch(transcribeLocalEndpoint, {
                    method: 'POST',
                    mode: 'cors',
                    body: formData,
                    signal
                });
            } catch (err) {
                throw new Error('local_service');
            }

            if (!localResponse || !localResponse.ok) {
                throw new Error('local_service');
            }

            const contentType = String(localResponse.headers.get('content-type') || '').toLowerCase();
            let payload;
            if (contentType.indexOf('application/json') >= 0) {
                payload = await localResponse.json();
            } else {
                payload = {
                    text: await localResponse.text()
                };
            }

            const transcript = extractLocalTranscriptValue(payload);
            if (!transcript) {
                throw new Error('local_empty');
            }

            return transcript;
        }

        function processLocalTranscription($wrap, lessonId, state, next, recordingId, processNext) {
            state.localAbortController = new window.AbortController();
            transcribeRecordingWithLocalService(next, state.localAbortController.signal)
                .then(function (localTranscript) {
                    if (state.cancelled) {
                        finishTranscribe($wrap, transcribeMessages.cancelled, false);
                        return;
                    }

                    state.request = $.post(ajaxUrl, {
                        action: 'll_tools_transcribe_recording_by_id',
                        nonce: editNonce,
                        lesson_id: lessonId,
                        recording_id: recordingId,
                        force: state.force ? 1 : 0,
                        local_transcript: localTranscript
                    }).done(function (res) {
                        if (state.cancelled) {
                            finishTranscribe($wrap, transcribeMessages.cancelled, false);
                            return;
                        }
                        if (!res || res.success !== true) {
                            state.hadError = true;
                            processNext();
                            return;
                        }

                        const data = res.data || {};
                        const rec = data.recording ? data.recording : null;
                        if (rec) {
                            applyRecordingUpdate(rec);
                        }
                        const word = data.word ? data.word : null;
                        if (word) {
                            applyWordUpdate(word);
                        }
                        processNext();
                    }).fail(function () {
                        if (state.cancelled) {
                            finishTranscribe($wrap, transcribeMessages.cancelled, false);
                            return;
                        }
                        state.hadError = true;
                        processNext();
                    }).always(function () {
                        state.request = null;
                    });
                })
                .catch(function (err) {
                    if (state.cancelled || (err && err.name === 'AbortError')) {
                        finishTranscribe($wrap, transcribeMessages.cancelled, false);
                        return;
                    }
                    state.hadError = true;
                    const message = (err && err.message === 'local_audio_fetch')
                        ? transcribeMessages.localAudioError
                        : transcribeMessages.localServiceError;
                    setTranscribeStatus($wrap, message, true);
                    processNext();
                })
                .finally(function () {
                    state.localAbortController = null;
                });
        }

        function runLessonTranscription($wrap, lessonId, options) {
            const state = getTranscribeState($wrap);
            if (state.active) { return; }
            const mode = options && options.mode ? options.mode : 'missing';
            const force = !!(options && options.force);
            const confirmMessage = options && options.confirmMessage ? options.confirmMessage : '';
            if (confirmMessage) {
                const confirmed = window.confirm(confirmMessage);
                if (!confirmed) { return; }
            }

            state.active = true;
            state.cancelled = false;
            state.hadError = false;
            state.force = force;
            if (state.pollTimer) {
                window.clearTimeout(state.pollTimer);
                state.pollTimer = null;
            }
            state.queue = [];
            state.total = 0;
            state.completed = 0;
            setTranscribeControls($wrap, true);
            $wrap.attr('aria-busy', 'true');
            setTranscribeStatus($wrap, transcribeMessages.working, false);

            state.request = $.post(ajaxUrl, {
                action: 'll_tools_get_lesson_transcribe_queue',
                nonce: editNonce,
                lesson_id: lessonId,
                mode: mode
            }).done(function (response) {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                if (!response || response.success !== true) {
                    finishTranscribe($wrap, transcribeMessages.error, true);
                    return;
                }
                const data = response.data || {};
                const queue = Array.isArray(data.queue) ? data.queue.slice() : [];
                const total = parseInt(data.total, 10) || queue.length;
                if (!queue.length) {
                    finishTranscribe($wrap, transcribeMessages.none, false);
                    return;
                }

                state.queue = queue;
                state.total = total;
                state.completed = 0;

                const processNext = function () {
                    if (state.cancelled) {
                        finishTranscribe($wrap, transcribeMessages.cancelled, false);
                        return;
                    }
                    if (!state.queue.length) {
                        const message = state.hadError ? transcribeMessages.error : transcribeMessages.done;
                        finishTranscribe($wrap, message, state.hadError);
                        return;
                    }

                    const next = state.queue.shift();
                    const recordingId = parseInt(next.recording_id, 10) || 0;
                    if (!recordingId) {
                        processNext();
                        return;
                    }

                    state.completed += 1;
                    setTranscribeStatus(
                        $wrap,
                        formatTranscribeProgress(transcribeMessages.progress, state.completed, state.total),
                        false
                    );

                    const requestTranscription = function (transcriptId, attempt) {
                        const payload = {
                            action: 'll_tools_transcribe_recording_by_id',
                            nonce: editNonce,
                            lesson_id: lessonId,
                            recording_id: recordingId,
                            force: state.force ? 1 : 0
                        };
                        if (transcriptId) {
                            payload.transcript_id = transcriptId;
                        }

                        state.request = $.post(ajaxUrl, payload).done(function (res) {
                            if (state.cancelled) {
                                finishTranscribe($wrap, transcribeMessages.cancelled, false);
                                return;
                            }
                            if (!res || res.success !== true) {
                                state.hadError = true;
                                processNext();
                                return;
                            }

                            const data = res.data || {};
                            if (data.pending) {
                                const nextTranscriptId = typeof data.transcript_id === 'string' ? data.transcript_id : '';
                                if (!nextTranscriptId || (attempt + 1) >= transcribePollAttempts) {
                                    state.hadError = true;
                                    processNext();
                                    return;
                                }
                                state.pollTimer = window.setTimeout(function () {
                                    state.pollTimer = null;
                                    requestTranscription(nextTranscriptId, attempt + 1);
                                }, transcribePollIntervalMs);
                                return;
                            }

                            const rec = data.recording ? data.recording : null;
                            if (rec) {
                                applyRecordingUpdate(rec);
                            }
                            const word = data.word ? data.word : null;
                            if (word) {
                                applyWordUpdate(word);
                            }
                            processNext();
                        }).fail(function () {
                            if (state.cancelled) {
                                finishTranscribe($wrap, transcribeMessages.cancelled, false);
                                return;
                            }
                            state.hadError = true;
                            processNext();
                        }).always(function () {
                            state.request = null;
                        });
                    };

                    if (transcribeUsesLocalBrowser) {
                        processLocalTranscription($wrap, lessonId, state, next, recordingId, processNext);
                        return;
                    }

                    requestTranscription('', 0);
                };

                processNext();
            }).fail(function () {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                finishTranscribe($wrap, transcribeMessages.error, true);
            });
        }

        function runClearCaptions($wrap, lessonId) {
            const state = getTranscribeState($wrap);
            if (state.active) { return; }
            if (transcribeMessages.confirmClear) {
                const confirmed = window.confirm(transcribeMessages.confirmClear);
                if (!confirmed) { return; }
            }

            state.active = true;
            state.cancelled = false;
            setTranscribeControls($wrap, true);
            $wrap.attr('aria-busy', 'true');
            setTranscribeStatus($wrap, transcribeMessages.clearing, false);

            state.request = $.post(ajaxUrl, {
                action: 'll_tools_clear_lesson_transcriptions',
                nonce: editNonce,
                lesson_id: lessonId
            }).done(function (response) {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                if (!response || response.success !== true) {
                    finishTranscribe($wrap, transcribeMessages.error, true);
                    return;
                }
                const data = response.data || {};
                const cleared = Array.isArray(data.cleared) ? data.cleared : [];
                if (cleared.length) {
                    applyClearedRecordings(cleared);
                    finishTranscribe($wrap, transcribeMessages.cleared, false);
                } else {
                    finishTranscribe($wrap, transcribeMessages.none, false);
                }
            }).fail(function () {
                if (state.cancelled) {
                    finishTranscribe($wrap, transcribeMessages.cancelled, false);
                    return;
                }
                finishTranscribe($wrap, transcribeMessages.error, true);
            });
        }

        function cancelLessonTranscription($wrap) {
            const state = getTranscribeState($wrap);
            if (!state.active) { return; }
            state.cancelled = true;
            if (state.localAbortController) {
                state.localAbortController.abort();
            }
            if (state.request && typeof state.request.abort === 'function') {
                state.request.abort();
            } else {
                finishTranscribe($wrap, transcribeMessages.cancelled, false);
            }
        }

        $('[data-ll-transcribe-menu-trigger]').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $wrap = $(this).closest('[data-ll-transcribe-wrapper]');
            const $menu = $wrap.find('[data-ll-transcribe-menu]').first();
            const isOpen = $menu.attr('aria-hidden') === 'false';
            closeTranscribeMenus($wrap);
            setTranscribeMenuOpen($wrap, !isOpen);
        });

        $('[data-ll-transcribe-menu]').on('click', function (e) {
            e.stopPropagation();
        });

        $(document).on('pointerdown.llLessonTranscribeMenu', function (e) {
            if ($(e.target).closest('[data-ll-transcribe-wrapper]').length) { return; }
            closeTranscribeMenus();
        });

        $(document).on('keydown.llLessonTranscribeMenu', function (e) {
            if (e.key === 'Escape') {
                closeTranscribeMenus();
            }
        });

        $('[data-ll-transcribe-recordings]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            const lessonId = parseInt($btn.attr('data-lesson-id'), 10) || 0;
            if (!lessonId) { return; }
            setTranscribeMenuOpen($wrap, false);
            runLessonTranscription($wrap, lessonId, {
                mode: 'missing',
                force: false,
                confirmMessage: transcribeMessages.confirm
            });
        });

        $('[data-ll-transcribe-replace]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            const lessonId = parseInt($btn.attr('data-lesson-id'), 10) || 0;
            if (!lessonId) { return; }
            setTranscribeMenuOpen($wrap, false);
            runLessonTranscription($wrap, lessonId, {
                mode: 'all',
                force: true,
                confirmMessage: transcribeMessages.confirmReplace
            });
        });

        $('[data-ll-transcribe-clear]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            const lessonId = parseInt($btn.attr('data-lesson-id'), 10) || 0;
            if (!lessonId) { return; }
            setTranscribeMenuOpen($wrap, false);
            runClearCaptions($wrap, lessonId);
        });

        $('[data-ll-transcribe-cancel]').on('click', function (e) {
            e.preventDefault();
            const $btn = $(this);
            const $wrap = $btn.closest('[data-ll-transcribe-wrapper]');
            if (!$wrap.length) { return; }
            setTranscribeMenuOpen($wrap, false);
            cancelLessonTranscription($wrap);
        });
    }

    updateAllStarToggles();
    updateStarModeButtons();
})(jQuery);
