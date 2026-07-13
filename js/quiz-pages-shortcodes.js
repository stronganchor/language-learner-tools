(function () {
    'use strict';

    function navigateFromSelect(select) {
        if (!select || !select.value) {
            return;
        }

        window.location.href = select.value;
    }

    function warmCatalog(status) {
        if (!status || status.dataset.llQuizCatalogStarted === '1') {
            return;
        }

        var ajaxUrl = status.dataset.ajaxUrl || '';
        var action = status.dataset.action || '';
        var nonce = status.dataset.nonce || '';
        var scopeId = status.dataset.scopeId || '';
        var refreshUrl = status.dataset.refreshUrl || window.location.href;
        var retryMs = Math.max(250, Math.min(5000, Number(status.dataset.retryMs) || 1200));
        var maxAttempts = Math.max(1, Math.min(600, Number(status.dataset.maxAttempts) || 120));
        var attempts = 0;

        if (!ajaxUrl || !action || !nonce || !scopeId || typeof window.fetch !== 'function') {
            return;
        }

        status.dataset.llQuizCatalogStarted = '1';

        function schedule() {
            if (attempts >= maxAttempts) {
                return;
            }
            window.setTimeout(requestStatus, retryMs);
        }

        function requestStatus() {
            attempts += 1;
            var body = new window.URLSearchParams();
            body.set('action', action);
            body.set('nonce', nonce);
            body.set('scope_id', scopeId);

            window.fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('quiz_catalog_warmup_failed');
                }
                return response.json();
            }).then(function (payload) {
                var data = payload && payload.success && payload.data ? payload.data : {};
                if (data.ready) {
                    window.location.href = refreshUrl;
                    return;
                }
                if (Number(data.retry_after_ms) > 0) {
                    retryMs = Math.max(250, Math.min(5000, Number(data.retry_after_ms)));
                }
                schedule();
            }).catch(schedule);
        }

        requestStatus();
    }

    function initializeCatalogWarmups() {
        document.querySelectorAll('[data-ll-quiz-catalog-status="1"]').forEach(warmCatalog);
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCatalogWarmups, {once: true});
    } else {
        initializeCatalogWarmups();
    }
}());
