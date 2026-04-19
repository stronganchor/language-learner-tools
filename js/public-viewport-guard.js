(function (root) {
    'use strict';

    if (!root || !root.document || root.__LLToolsViewportGuardLoaded) {
        return;
    }
    root.__LLToolsViewportGuardLoaded = true;

    var doc = root.document;
    var LOCKED_VIEWPORT_CONTENT = 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover';
    var REFRESH_VIEWPORT_CONTENT = 'width=device-width, initial-scale=1';
    var ZOOM_THRESHOLD = 1.01;
    var PINCH_GROWTH_TOLERANCE = 4;
    var refreshTimerIds = [];
    var touchState = {
        lastDistance: 0
    };

    function getViewportMetaTags() {
        var tags = [];
        if (doc.querySelectorAll) {
            tags = Array.prototype.slice.call(doc.querySelectorAll('meta[name="viewport"]'));
        }

        if (tags.length > 0) {
            return tags;
        }

        var head = doc.head || doc.getElementsByTagName('head')[0] || doc.documentElement;
        if (!head || !doc.createElement) {
            return [];
        }

        var meta = doc.createElement('meta');
        meta.setAttribute('name', 'viewport');
        head.appendChild(meta);
        return [meta];
    }

    function applyViewportMeta(forceRefresh) {
        getViewportMetaTags().forEach(function (tag) {
            if (!tag || typeof tag.setAttribute !== 'function') {
                return;
            }

            var current = String(tag.getAttribute('content') || '').trim();
            if (forceRefresh && current === LOCKED_VIEWPORT_CONTENT) {
                tag.setAttribute('content', REFRESH_VIEWPORT_CONTENT);
            }

            if (current !== LOCKED_VIEWPORT_CONTENT || forceRefresh) {
                tag.setAttribute('content', LOCKED_VIEWPORT_CONTENT);
            }
        });
    }

    function clearViewportRefreshTimers() {
        refreshTimerIds.forEach(function (timerId) {
            root.clearTimeout(timerId);
        });
        refreshTimerIds = [];
    }

    function scheduleViewportRefresh(forceRefresh) {
        clearViewportRefreshTimers();

        [0, 120, 360].forEach(function (delay) {
            var timerId = root.setTimeout(function () {
                applyViewportMeta(forceRefresh);
            }, delay);
            refreshTimerIds.push(timerId);
        });
    }

    function getViewportScale() {
        var visualViewport = root.visualViewport;
        if (visualViewport && typeof visualViewport.scale === 'number' && isFinite(visualViewport.scale) && visualViewport.scale > 0) {
            return visualViewport.scale;
        }

        return 1;
    }

    function isZoomed() {
        return getViewportScale() > ZOOM_THRESHOLD;
    }

    function syncZoomState(forceRefresh) {
        var zoomed = isZoomed();
        var method = zoomed ? 'add' : 'remove';

        if (doc.documentElement && doc.documentElement.classList) {
            doc.documentElement.classList[method]('ll-tools-viewport-zoomed');
            doc.documentElement.setAttribute('data-ll-viewport-zoomed', zoomed ? '1' : '0');
        }

        if (doc.body && doc.body.classList) {
            doc.body.classList[method]('ll-tools-viewport-zoomed');
        }

        if (zoomed && forceRefresh) {
            scheduleViewportRefresh(true);
        }

        return zoomed;
    }

    function getTouchDistance(touches) {
        if (!touches || touches.length < 2) {
            return 0;
        }

        var first = touches[0];
        var second = touches[1];
        var dx = (Number(second.clientX) || 0) - (Number(first.clientX) || 0);
        var dy = (Number(second.clientY) || 0) - (Number(first.clientY) || 0);

        return Math.sqrt((dx * dx) + (dy * dy));
    }

    function resetTouchTracking() {
        touchState.lastDistance = 0;
    }

    function onTouchStart(event) {
        if (!event || !event.touches || event.touches.length < 2) {
            resetTouchTracking();
            return;
        }

        touchState.lastDistance = getTouchDistance(event.touches);
        if (!syncZoomState(false)) {
            event.preventDefault();
        }
    }

    function onTouchMove(event) {
        if (!event || !event.touches || event.touches.length < 2) {
            resetTouchTracking();
            return;
        }

        var currentDistance = getTouchDistance(event.touches);
        var previousDistance = touchState.lastDistance;
        touchState.lastDistance = currentDistance;

        if (!syncZoomState(false)) {
            event.preventDefault();
            return;
        }

        if (previousDistance > 0 && currentDistance > (previousDistance + PINCH_GROWTH_TOLERANCE)) {
            event.preventDefault();
        }
    }

    function onTouchEnd(event) {
        if (!event || !event.touches || event.touches.length < 2) {
            resetTouchTracking();
            syncZoomState(false);
            return;
        }

        touchState.lastDistance = getTouchDistance(event.touches);
    }

    function onGesture(event) {
        if (!syncZoomState(false)) {
            event.preventDefault();
            return;
        }

        if (event.type === 'gesturechange' && typeof event.scale === 'number' && isFinite(event.scale) && event.scale >= 1) {
            event.preventDefault();
        }
    }

    function onVisibilityChange() {
        if (doc.visibilityState === 'visible') {
            scheduleViewportRefresh(false);
            syncZoomState(true);
        }
    }

    applyViewportMeta(false);
    syncZoomState(false);

    doc.addEventListener('touchstart', onTouchStart, { passive: false, capture: true });
    doc.addEventListener('touchmove', onTouchMove, { passive: false, capture: true });
    doc.addEventListener('touchend', onTouchEnd, true);
    doc.addEventListener('touchcancel', onTouchEnd, true);
    doc.addEventListener('gesturestart', onGesture, true);
    doc.addEventListener('gesturechange', onGesture, true);
    doc.addEventListener('gestureend', onGesture, true);
    doc.addEventListener('visibilitychange', onVisibilityChange, true);
    root.addEventListener('pageshow', function () {
        scheduleViewportRefresh(false);
        syncZoomState(true);
    }, true);
    root.addEventListener('orientationchange', function () {
        scheduleViewportRefresh(false);
        syncZoomState(false);
    }, true);
    root.addEventListener('resize', function () {
        syncZoomState(true);
    }, true);

    if (root.visualViewport && typeof root.visualViewport.addEventListener === 'function') {
        root.visualViewport.addEventListener('resize', function () {
            syncZoomState(true);
        });
    }

    root.LLToolsViewportGuard = {
        getScale: getViewportScale,
        isZoomed: isZoomed,
        refresh: function () {
            scheduleViewportRefresh(true);
            return syncZoomState(true);
        }
    };
})(window);
