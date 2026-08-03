(function () {
    'use strict';

    var selector = '[data-ll-wordset-buttons-refresh]';
    var states = new WeakMap();

    function positiveInteger(value, fallback) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
    }

    function rootConfig(root) {
        var localized = window.llToolsWordsetButtonsRefresh || {};
        return {
            ajaxUrl: String(root.getAttribute('data-ajax-url') || localized.ajaxUrl || ''),
            action: String(root.getAttribute('data-ajax-action') || localized.action || 'll_tools_wordset_buttons_refresh'),
            nonce: String(root.getAttribute('data-nonce') || localized.nonce || ''),
            statusToken: String(root.getAttribute('data-status-token') || ''),
            retryMs: positiveInteger(localized.retryMs, 1500),
            requestTimeoutMs: positiveInteger(localized.requestTimeoutMs, 20000),
            maxFailures: positiveInteger(localized.maxFailures, 5),
            maxWaitMs: positiveInteger(localized.maxWaitMs, 10 * 60 * 1000),
            errorMessage: String(root.getAttribute('data-error-message') || localized.errorMessage || ''),
            retryLabel: String(root.getAttribute('data-retry-label') || localized.retryLabel || '')
        };
    }

    function clearState(state) {
        if (state.timer) {
            window.clearTimeout(state.timer);
            state.timer = 0;
        }
        if (state.visibilityHandler) {
            document.removeEventListener('visibilitychange', state.visibilityHandler);
            state.visibilityHandler = null;
        }
    }

    function replaceCompleteRoot(root, html, state) {
        clearState(state);
        var normalized = typeof html === 'string' ? html.trim() : '';
        if (normalized === '') {
            root.remove();
            return;
        }

        var template = document.createElement('template');
        template.innerHTML = normalized;
        root.replaceWith(template.content);
    }

    function showRetry(root, state) {
        if (!root.isConnected || state.showingRetry) {
            return;
        }

        clearState(state);
        state.showingRetry = true;
        root.setAttribute('aria-busy', 'false');

        var config = rootConfig(root);
        var panel = document.createElement('div');
        panel.className = 'll-wordset-buttons-refresh__error';
        panel.setAttribute('role', 'alert');

        var message = document.createElement('p');
        message.className = 'll-wordset-buttons-refresh__error-message';
        message.textContent = config.errorMessage || '⚠';

        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'll-study-btn ll-wordset-buttons-refresh__retry';
        retry.textContent = config.retryLabel || '↻';
        retry.setAttribute('aria-label', config.retryLabel || '↻');
        retry.addEventListener('click', function () {
            if (state.reloadOnRetry) {
                window.location.reload();
                return;
            }
            state.showingRetry = false;
            state.failures = 0;
            state.nonceRefreshes = 0;
            state.configWaits = 0;
            state.startedAt = Date.now();
            root.setAttribute('aria-busy', 'true');
            root.innerHTML = state.loadingHtml;
            requestNextBatch(root, state);
        });

        panel.appendChild(message);
        panel.appendChild(retry);
        root.textContent = '';
        root.appendChild(panel);
    }

    function schedule(root, state, delay) {
        clearState(state);
        if (!root.isConnected || state.showingRetry) {
            return;
        }

        var config = rootConfig(root);
        var waitMs = positiveInteger(delay, config.retryMs);
        if (Date.now() + waitMs - state.startedAt > config.maxWaitMs) {
            showRetry(root, state);
            return;
        }

        if (document.visibilityState === 'hidden') {
            state.visibilityHandler = function () {
                if (document.visibilityState !== 'hidden') {
                    clearState(state);
                    requestNextBatch(root, state);
                }
            };
            document.addEventListener('visibilitychange', state.visibilityHandler);
            return;
        }

        state.timer = window.setTimeout(function () {
            state.timer = 0;
            requestNextBatch(root, state);
        }, waitMs);
    }

    function transientFailureDelay(config, failures) {
        return Math.min(30000, config.retryMs * Math.pow(2, Math.max(0, failures - 1)));
    }

    function refreshExpiredNonce(root, state, response, payload, config) {
        var data = payload && payload.data ? payload.data : {};
        if (
            config.statusToken
            ||
            response.status !== 403
            || data.code !== 'invalid_nonce'
            || typeof data.nonce !== 'string'
            || data.nonce === ''
            || state.nonceRefreshes >= 2
        ) {
            return false;
        }

        root.setAttribute('data-nonce', data.nonce);
        state.nonceRefreshes += 1;
        state.failures = 0;
        schedule(root, state, 1);
        return true;
    }

    function requestNextBatch(root, state) {
        if (!root.isConnected || state.pending || state.showingRetry) {
            return;
        }
        if (document.visibilityState === 'hidden') {
            schedule(root, state, rootConfig(root).retryMs);
            return;
        }

        var config = rootConfig(root);
        if (!config.ajaxUrl || (!config.nonce && !config.statusToken) || typeof window.fetch !== 'function') {
            state.configWaits += 1;
            if (state.configWaits >= 20) {
                showRetry(root, state);
                return;
            }
            schedule(root, state, 250);
            return;
        }

        state.configWaits = 0;
        state.pending = true;

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timeout = controller
            ? window.setTimeout(function () { controller.abort(); }, config.requestTimeoutMs)
            : 0;
        var body = new URLSearchParams();
        body.set('action', config.action);
        if (config.statusToken) {
            body.set('token', config.statusToken);
        } else {
            body.set('nonce', config.nonce);
            body.set('tag', String(root.getAttribute('data-shortcode-tag') || 'll_wordset_buttons'));
            body.set('class', String(root.getAttribute('data-shortcode-class') || ''));
            body.set('hide_empty', root.getAttribute('data-hide-empty') === '1' ? '1' : '0');
        }

        window.fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString(),
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                return { response: response, payload: payload };
            });
        }).then(function (result) {
            if (!result.response.ok) {
                if (refreshExpiredNonce(root, state, result.response, result.payload, config)) {
                    return;
                }
                var errorData = result.payload && result.payload.data ? result.payload.data : {};
                if (result.response.status === 429 && config.statusToken) {
                    var retryHeader = parseInt(result.response.headers.get('Retry-After') || '', 10);
                    var retryAfterMs = positiveInteger(
                        errorData.retryAfterMs,
                        Number.isFinite(retryHeader) && retryHeader > 0 ? retryHeader * 1000 : config.retryMs
                    );
                    state.failures = 0;
                    schedule(root, state, retryAfterMs);
                    return;
                }
                if (
                    config.statusToken
                    && [403, 409, 410].indexOf(result.response.status) !== -1
                    && ['invalid_status_token', 'expired_status_token', 'stale_status_token'].indexOf(String(errorData.code || '')) !== -1
                ) {
                    state.reloadOnRetry = true;
                    showRetry(root, state);
                    return;
                }
                throw new Error('wordset-buttons-refresh-http');
            }

            var payload = result.payload;
            if (!payload || payload.success !== true || !payload.data) {
                throw new Error('wordset-buttons-refresh-response');
            }

            var data = payload.data;
            state.failures = 0;
            state.nonceRefreshes = 0;
            if (data.complete === true) {
                replaceCompleteRoot(root, data.html, state);
                return;
            }

            if (typeof data.html === 'string' && data.html.trim() !== '' && root.innerHTML !== data.html) {
                root.innerHTML = data.html;
            }
            schedule(root, state, data.retryAfterMs);
        }).catch(function () {
            state.failures += 1;
            var currentConfig = rootConfig(root);
            if (state.failures >= currentConfig.maxFailures) {
                showRetry(root, state);
                return;
            }
            schedule(root, state, transientFailureDelay(currentConfig, state.failures));
        }).finally(function () {
            if (timeout) {
                window.clearTimeout(timeout);
            }
            state.pending = false;
        });
    }

    function initializeRoot(root) {
        if (states.has(root)) {
            return;
        }

        var state = {
            failures: 0,
            nonceRefreshes: 0,
            configWaits: 0,
            pending: false,
            timer: 0,
            visibilityHandler: null,
            showingRetry: false,
            reloadOnRetry: false,
            startedAt: Date.now(),
            loadingHtml: root.innerHTML
        };
        states.set(root, state);
        requestNextBatch(root, state);
    }

    function initialize(scope) {
        var root = scope && scope.matches && scope.matches(selector) ? scope : null;
        if (root) {
            initializeRoot(root);
        }

        if (scope && typeof scope.querySelectorAll === 'function') {
            scope.querySelectorAll(selector).forEach(initializeRoot);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initialize(document); }, { once: true });
    } else {
        initialize(document);
    }

    window.addEventListener('pageshow', function () { initialize(document); });

    if (typeof MutationObserver === 'function' && document.documentElement) {
        var observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node && node.nodeType === 1) {
                        initialize(node);
                    }
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
}());
