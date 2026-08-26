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
        var requestTimeoutMs = Math.max(25, Math.min(120000, Number(status.dataset.requestTimeoutMs) || 15000));
        var message = status.querySelector('[data-ll-quiz-catalog-message]');
        var retryButton = status.querySelector('[data-ll-quiz-catalog-retry]');
        var attempts = 0;
        var generation = 0;
        var latestRequest = 0;
        var scheduledTimer = 0;
        var activeController = null;

        if (!ajaxUrl || !action || !nonce || !scopeId || typeof window.fetch !== 'function') {
            return;
        }

        status.dataset.llQuizCatalogStarted = '1';

        function setMessage(state, text) {
            status.dataset.state = state;
            if (message) {
                message.textContent = text;
            }
        }

        function showExhausted() {
            setMessage('exhausted', status.dataset.exhaustedMessage || 'Quiz loading is taking longer than expected.');
            if (retryButton) {
                retryButton.hidden = false;
            }
        }

        function schedule(currentGeneration) {
            if (currentGeneration !== generation) {
                return;
            }
            if (attempts >= maxAttempts) {
                showExhausted();
                return;
            }
            scheduledTimer = window.setTimeout(function () {
                requestStatus(currentGeneration);
            }, retryMs);
        }

        function requestStatus(currentGeneration) {
            if (currentGeneration !== generation) {
                return;
            }
            attempts += 1;
            latestRequest += 1;
            var requestId = latestRequest;
            var timedOut = false;
            var timeoutId = 0;
            var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
            var body = new window.URLSearchParams();
            body.set('action', action);
            body.set('nonce', nonce);
            body.set('scope_id', scopeId);

            if (retryButton) {
                retryButton.hidden = true;
            }
            setMessage('loading', status.dataset.loadingMessage || 'Loading quiz...');
            activeController = controller;

            var request = window.fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString(),
                signal: controller ? controller.signal : undefined
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('quiz_catalog_warmup_failed');
                }
                return response.json();
            });
            var deadline = new Promise(function (resolve, reject) {
                timeoutId = window.setTimeout(function () {
                    timedOut = true;
                    if (controller) {
                        controller.abort();
                    }
                    reject(new Error('quiz_catalog_warmup_timeout'));
                }, requestTimeoutMs);
            });

            Promise.race([request, deadline]).then(function (payload) {
                if (currentGeneration !== generation || requestId !== latestRequest) {
                    return;
                }
                var data = payload && payload.success && payload.data ? payload.data : {};
                if (data.ready) {
                    window.location.href = refreshUrl;
                    return;
                }
                if (Number(data.retry_after_ms) > 0) {
                    retryMs = Math.max(250, Math.min(5000, Number(data.retry_after_ms)));
                }
                schedule(currentGeneration);
            }).catch(function () {
                if (currentGeneration !== generation || requestId !== latestRequest) {
                    return;
                }
                setMessage(
                    timedOut ? 'timeout' : 'error',
                    timedOut
                        ? (status.dataset.timeoutMessage || 'The quiz loading request timed out.')
                        : (status.dataset.errorMessage || 'The quiz could not be loaded yet.')
                );
                schedule(currentGeneration);
            }).finally(function () {
                window.clearTimeout(timeoutId);
                if (currentGeneration === generation && requestId === latestRequest && activeController === controller) {
                    activeController = null;
                }
            });
        }

        function restart() {
            generation += 1;
            latestRequest += 1;
            attempts = 0;
            window.clearTimeout(scheduledTimer);
            if (activeController) {
                activeController.abort();
                activeController = null;
            }
            requestStatus(generation);
        }

        if (retryButton) {
            retryButton.addEventListener('click', restart);
        }
        restart();
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
