(function () {
    'use strict';

    function isModifiedClick(event) {
        return !!(
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey ||
            event.button !== 0
        );
    }

    document.addEventListener('click', function (event) {
        if (!event || !event.target || !event.target.closest) {
            return;
        }

        var link = event.target.closest('a[data-ll-force-hard-nav="1"]');
        if (!link) {
            return;
        }

        if (isModifiedClick(event)) {
            return;
        }

        var href = (link.getAttribute('href') || '').trim();
        if (!href) {
            return;
        }

        var target = (link.getAttribute('target') || '').trim().toLowerCase();
        if (target && target !== '_self' && target !== '_top') {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        window.location.assign(href);
    }, true);
})();
