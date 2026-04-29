(function () {
    'use strict';

    function navigateFromSelect(select) {
        if (!select || !select.value) {
            return;
        }

        window.location.href = select.value;
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches || !target.matches('.ll-quiz-pages-select[data-ll-quiz-pages-auto-go="1"]')) {
            return;
        }

        navigateFromSelect(target);
    });

    document.addEventListener('click', function (event) {
        var button = event.target && event.target.closest ? event.target.closest('[data-ll-quiz-pages-go]') : null;
        if (!button) {
            return;
        }

        var container = button.closest('.ll-quiz-pages-dropdown');
        var select = container ? container.querySelector('.ll-quiz-pages-select') : null;
        navigateFromSelect(select);
    });
}());
