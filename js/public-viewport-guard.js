(function (root) {
    'use strict';

    if (!root || !root.document || root.__LLToolsViewportGuardLoaded) {
        return;
    }
    root.__LLToolsViewportGuardLoaded = true;

    var doc = root.document;
    var VIEWPORT_CONTENT = 'width=device-width, initial-scale=1, viewport-fit=cover';
    var ZOOM_THRESHOLD = 1.01;

    function getViewportMetaTags() {
        var tags = doc.querySelectorAll
            ? Array.prototype.slice.call(doc.querySelectorAll('meta[name="viewport"]'))
            : [];
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

    function applyViewportMeta() {
        getViewportMetaTags().forEach(function (tag) {
            if (tag && typeof tag.setAttribute === 'function') {
                tag.setAttribute('content', VIEWPORT_CONTENT);
            }
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

    function syncZoomState() {
        var zoomed = isZoomed();
        var method = zoomed ? 'add' : 'remove';
        if (doc.documentElement && doc.documentElement.classList) {
            doc.documentElement.classList[method]('ll-tools-viewport-zoomed');
            doc.documentElement.setAttribute('data-ll-viewport-zoomed', zoomed ? '1' : '0');
        }
        if (doc.body && doc.body.classList) {
            doc.body.classList[method]('ll-tools-viewport-zoomed');
        }
        return zoomed;
    }

    applyViewportMeta();
    syncZoomState();
    root.addEventListener('pageshow', syncZoomState, true);
    root.addEventListener('orientationchange', syncZoomState, true);
    root.addEventListener('resize', syncZoomState, true);
    if (root.visualViewport && typeof root.visualViewport.addEventListener === 'function') {
        root.visualViewport.addEventListener('resize', syncZoomState);
    }

    root.LLToolsViewportGuard = {
        getScale: getViewportScale,
        isZoomed: isZoomed,
        refresh: function () {
            applyViewportMeta();
            return syncZoomState();
        }
    };
})(window);
