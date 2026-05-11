(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        const button = event.target && event.target.closest
            ? event.target.closest('[data-ll-text-document-print]')
            : null;
        if (!button) {
            return;
        }

        event.preventDefault();
        if (typeof window.print === 'function') {
            window.print();
        }
    });
}());
