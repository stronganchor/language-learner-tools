(function () {
    'use strict';

    function cleanupPrintRoot() {
        const root = document.querySelector('[data-ll-text-document-print-root]');
        if (root && root.parentNode) {
            root.parentNode.removeChild(root);
        }

        if (document.body && document.body.classList) {
            document.body.classList.remove('ll-text-document-print-active');
        }
    }

    function preparePrintRoot(button) {
        const source = button.closest('[data-ll-content-lesson]') || button.closest('[data-ll-text-document]');
        if (!source || !document.body) {
            return;
        }

        cleanupPrintRoot();

        const root = document.createElement('div');
        root.className = 'll-text-document-print-root';
        root.setAttribute('data-ll-text-document-print-root', '');
        root.setAttribute('aria-hidden', 'true');
        root.appendChild(source.cloneNode(true));

        document.body.appendChild(root);
        document.body.classList.add('ll-text-document-print-active');
    }

    document.addEventListener('click', function (event) {
        const button = event.target && event.target.closest
            ? event.target.closest('[data-ll-text-document-print]')
            : null;
        if (!button) {
            return;
        }

        event.preventDefault();
        preparePrintRoot(button);
        if (typeof window.print === 'function') {
            window.print();
        }
    });

    window.addEventListener('afterprint', cleanupPrintRoot);
}());
